<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_oerexchange;

use PHPUnit\Framework\Attributes\CoversClass;
use local_oerexchange\local\site_manager;

/**
 * Tests for site_manager — the core of the site-registration identity model
 * (a real WS token minted against a dedicated, suspendable service account,
 * not a custom key scheme). Previously entirely untested by automation;
 * added on the second MDL Shield audit pass (2026-07-18).
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(site_manager::class)]
final class site_manager_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        // The custom service must exist for token minting — normally created
        // by db/services.php on install; recreate it explicitly for the test DB.
        global $DB;
        if (!$DB->record_exists('external_services', ['shortname' => 'local_oerexchange'])) {
            $DB->insert_record('external_services', (object) [
                'name' => 'OER Exchange service', 'shortname' => 'local_oerexchange',
                'timecreated' => time(), 'timemodified' => time(), 'component' => 'local_oerexchange',
                'restrictedusers' => 0, 'enabled' => 1, 'downloadfiles' => 1, 'uploadfiles' => 1,
            ]);
        }
    }

    public function test_register_creates_a_pending_site(): void {
        global $DB;

        $siteid = site_manager::register('Test site', 'https://example.com', 'a@example.com');
        $site = $DB->get_record('local_oerexchange_sites', ['id' => $siteid]);

        $this->assertSame('pending', $site->status);
        $this->assertNull($site->serviceuserid);
    }

    public function test_approve_creates_a_working_non_login_service_account(): void {
        global $DB;

        $siteid = site_manager::register('Test site', 'https://example.com', 'a@example.com');
        $rawtoken = site_manager::approve($siteid);

        $site = $DB->get_record('local_oerexchange_sites', ['id' => $siteid]);
        $this->assertSame('active', $site->status);
        $this->assertNotEmpty($site->serviceuserid);

        $serviceuser = $DB->get_record('user', ['id' => $site->serviceuserid]);
        $this->assertSame('manual', $serviceuser->auth, 'auth=nologin would be rejected by core WS auth');
        $this->assertEquals(0, $serviceuser->suspended);

        // The token really was minted against that account.
        $tokenrecord = $DB->get_record('external_tokens', ['token' => $rawtoken]);
        $this->assertEquals($site->serviceuserid, $tokenrecord->userid);
    }

    public function test_get_site_for_user_resolves_only_active_sites(): void {
        $siteid = site_manager::register('Test site', 'https://example.com', 'a@example.com');
        site_manager::approve($siteid);

        global $DB;
        $site = $DB->get_record('local_oerexchange_sites', ['id' => $siteid]);

        $found = site_manager::get_site_for_user((int) $site->serviceuserid);
        $this->assertNotFalse($found);
        $this->assertEquals($siteid, $found->id);
    }

    public function test_revoke_suspends_the_account_and_clears_tokens(): void {
        global $DB;

        $siteid = site_manager::register('Test site', 'https://example.com', 'a@example.com');
        site_manager::approve($siteid);
        $site = $DB->get_record('local_oerexchange_sites', ['id' => $siteid]);
        $serviceuserid = $site->serviceuserid;

        site_manager::revoke($siteid);

        $site = $DB->get_record('local_oerexchange_sites', ['id' => $siteid]);
        $this->assertSame('revoked', $site->status);

        $serviceuser = $DB->get_record('user', ['id' => $serviceuserid]);
        $this->assertEquals(1, $serviceuser->suspended);
        $this->assertEquals(0, $DB->count_records('external_tokens', ['userid' => $serviceuserid]));

        // Revoked sites must not resolve via get_site_for_user (status filter).
        $this->assertFalse(site_manager::get_site_for_user((int) $serviceuserid));
    }

    public function test_reapprove_after_revoke_reuses_the_account_and_reactivates(): void {
        global $DB;

        $siteid = site_manager::register('Test site', 'https://example.com', 'a@example.com');
        site_manager::approve($siteid);
        $firstserviceuserid = $DB->get_field('local_oerexchange_sites', 'serviceuserid', ['id' => $siteid]);
        site_manager::revoke($siteid);

        $newtoken = site_manager::approve($siteid);

        $site = $DB->get_record('local_oerexchange_sites', ['id' => $siteid]);
        $this->assertSame('active', $site->status);
        $this->assertEquals($firstserviceuserid, $site->serviceuserid, 'the same service account is reused, not recreated');

        $serviceuser = $DB->get_record('user', ['id' => $site->serviceuserid]);
        $this->assertEquals(0, $serviceuser->suspended);

        $tokenrecord = $DB->get_record('external_tokens', ['token' => $newtoken]);
        $this->assertEquals($site->serviceuserid, $tokenrecord->userid);
    }

    /**
     * register.php is unauthenticated by design — a site with no token yet
     * has nothing to authenticate with — so the only thing bounding the rows
     * it can insert is this pair of guards (MDL Shield self-audit, class 2,
     * 2026-07-27).
     */
    public function test_register_reuses_an_existing_pending_row_for_the_same_url(): void {
        global $DB;

        $first = site_manager::register('Their site', 'https://theirs.example.net', 'a@example.com');
        $second = site_manager::register('Their site again', 'https://theirs.example.net', 'b@example.com');

        $this->assertSame($first, $second, 'a repeated registration returns the pending row instead of adding one');
        $this->assertEquals(1, $DB->count_records('local_oerexchange_sites'));
        // The first registration's details are the ones an admin reviews —
        // a replay must not be able to rewrite them.
        $site = $DB->get_record('local_oerexchange_sites', ['id' => $first], '*', MUST_EXIST);
        $this->assertSame('Their site', $site->name);
        $this->assertSame('a@example.com', $site->contact);
    }

    public function test_register_matches_the_pending_url_case_insensitively(): void {
        global $DB;

        $first = site_manager::register('Their site', 'https://Theirs.Example.NET', 'a@example.com');
        $second = site_manager::register('Their site', 'https://theirs.example.net', 'a@example.com');

        $this->assertSame($first, $second);
        $this->assertEquals(1, $DB->count_records('local_oerexchange_sites'));
    }

    public function test_register_adds_a_row_for_a_url_whose_only_registration_was_revoked(): void {
        global $DB;

        $first = site_manager::register('Their site', 'https://theirs.example.net', 'a@example.com');
        $DB->set_field('local_oerexchange_sites', 'status', 'revoked', ['id' => $first]);

        $second = site_manager::register('Their site', 'https://theirs.example.net', 'a@example.com');

        $this->assertNotSame($first, $second, 'asking again after a revocation is a genuine new request');
        $this->assertEquals(2, $DB->count_records('local_oerexchange_sites'));
    }

    public function test_registration_rate_exceeded_trips_only_at_the_cap(): void {
        global $DB;

        $this->assertFalse(site_manager::registration_rate_exceeded());

        $now = time();
        for ($i = 0; $i < site_manager::REGISTRATION_MAX_PER_WINDOW - 1; $i++) {
            $DB->insert_record('local_oerexchange_sites', (object) [
                'name' => 'S' . $i, 'url' => 'https://s' . $i . '.example.net', 'contact' => 'c@example.com',
                'serviceuserid' => null, 'status' => 'pending', 'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        $this->assertFalse(site_manager::registration_rate_exceeded(), 'one below the cap still registers');

        $DB->insert_record('local_oerexchange_sites', (object) [
            'name' => 'S last', 'url' => 'https://last.example.net', 'contact' => 'c@example.com',
            'serviceuserid' => null, 'status' => 'pending', 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $this->assertTrue(site_manager::registration_rate_exceeded());
    }

    public function test_registration_rate_ignores_rows_older_than_the_window(): void {
        global $DB;

        $stale = time() - site_manager::REGISTRATION_WINDOW - 60;
        for ($i = 0; $i < site_manager::REGISTRATION_MAX_PER_WINDOW + 5; $i++) {
            $DB->insert_record('local_oerexchange_sites', (object) [
                'name' => 'S' . $i, 'url' => 'https://s' . $i . '.example.net', 'contact' => 'c@example.com',
                'serviceuserid' => null, 'status' => 'pending', 'timecreated' => $stale, 'timemodified' => $stale,
            ]);
        }

        $this->assertFalse(
            site_manager::registration_rate_exceeded(),
            'the window slides — yesterday\'s flood does not lock the door today'
        );
    }
}
