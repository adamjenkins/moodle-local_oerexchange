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
}
