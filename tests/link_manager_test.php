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
use local_oerexchange\local\link_manager;

/**
 * Tests for link_manager — the account-linking one-time-code handshake.
 * Previously entirely untested by automation; added on the second MDL
 * Shield audit pass (2026-07-18).
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(link_manager::class)]
final class link_manager_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        global $DB;
        if (!$DB->record_exists('external_services', ['shortname' => 'local_oerexchange'])) {
            $DB->insert_record('external_services', (object) [
                'name' => 'OER Exchange service', 'shortname' => 'local_oerexchange',
                'timecreated' => time(), 'timemodified' => time(), 'component' => 'local_oerexchange',
                'restrictedusers' => 0, 'enabled' => 1, 'downloadfiles' => 1, 'uploadfiles' => 1,
            ]);
        }
    }

    public function test_issue_then_consume_returns_a_working_token_for_the_right_user(): void {
        $user = $this->getDataGenerator()->create_user();

        $code = link_manager::issue_code(1, (int) $user->id);
        $result = link_manager::consume($code);

        $this->assertEquals($user->id, $result->userid);
        $this->assertNotEmpty($result->token);

        global $DB;
        $tokenrecord = $DB->get_record('external_tokens', ['token' => $result->token]);
        $this->assertEquals($user->id, $tokenrecord->userid);
    }

    public function test_consuming_twice_fails_on_the_second_attempt(): void {
        $user = $this->getDataGenerator()->create_user();
        $code = link_manager::issue_code(1, (int) $user->id);

        link_manager::consume($code);

        $this->expectException(\moodle_exception::class);
        link_manager::consume($code);
    }

    public function test_token_is_cleared_from_storage_after_consumption(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $code = link_manager::issue_code(1, (int) $user->id);

        link_manager::consume($code);

        $record = $DB->get_record('local_oerexchange_linkcodes', ['code' => $code]);
        $this->assertSame('used', $record->status);
        $this->assertSame('', $record->token, 'the live token must not linger in the DB once handed off');
    }

    public function test_unknown_code_is_rejected(): void {
        $this->expectException(\moodle_exception::class);
        link_manager::consume('this-code-was-never-issued');
    }

    public function test_expired_code_is_rejected(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $code = link_manager::issue_code(1, (int) $user->id);

        // Force it into the past.
        $DB->set_field('local_oerexchange_linkcodes', 'timeexpires', time() - 1, ['code' => $code]);

        $this->expectException(\moodle_exception::class);
        link_manager::consume($code);
    }

    public function test_expired_consumption_marks_the_code_expired_not_used(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $code = link_manager::issue_code(1, (int) $user->id);
        $DB->set_field('local_oerexchange_linkcodes', 'timeexpires', time() - 1, ['code' => $code]);

        try {
            link_manager::consume($code);
        } catch (\moodle_exception $e) {
            $this->assertInstanceOf(\moodle_exception::class, $e);
        }

        $record = $DB->get_record('local_oerexchange_linkcodes', ['code' => $code]);
        $this->assertSame('expired', $record->status);
    }

    /**
     * connect.php's open-redirect gate. Until 0.1.6 it compared only the
     * host, so an https-registered site could be handed an http:// callback
     * and the one-time link code would cross the network in the clear
     * (MDL Shield self-audit, class 1, 2026-07-27).
     */
    public function test_callback_matches_site_accepts_the_registered_origin(): void {
        $this->assertTrue(link_manager::callback_matches_site(
            'https://client.example.net',
            'https://client.example.net/local/oerclient/connect_callback.php?state=abc'
        ));
    }

    public function test_callback_matches_site_ignores_host_case(): void {
        $this->assertTrue(link_manager::callback_matches_site(
            'https://Client.Example.NET',
            'https://client.example.net/local/oerclient/connect_callback.php'
        ));
    }

    public function test_callback_matches_site_rejects_another_host(): void {
        $this->assertFalse(link_manager::callback_matches_site(
            'https://client.example.net',
            'https://attacker.example.com/collect.php'
        ));
    }

    public function test_callback_matches_site_rejects_a_downgraded_scheme(): void {
        $this->assertFalse(link_manager::callback_matches_site(
            'https://client.example.net',
            'http://client.example.net/local/oerclient/connect_callback.php'
        ));
    }

    public function test_callback_matches_site_rejects_a_schemeless_or_relative_callback(): void {
        $this->assertFalse(link_manager::callback_matches_site(
            'https://client.example.net',
            '//attacker.example.com/collect.php'
        ));
        $this->assertFalse(link_manager::callback_matches_site(
            'https://client.example.net',
            '/local/oerclient/connect_callback.php'
        ));
    }

    /**
     * Matching host and scheme alone let connect.php hand the one-time code
     * to any page on the registered host (security checklist audit F3,
     * 2026-10-28). Only the site's own local_oerclient callback is accepted.
     */
    public function test_callback_matches_site_rejects_another_path_on_the_registered_host(): void {
        $this->assertFalse(link_manager::callback_matches_site(
            'https://client.example.net',
            'https://client.example.net/mod/forum/discuss.php?d=1'
        ));
        $this->assertFalse(link_manager::callback_matches_site(
            'https://client.example.net',
            'https://client.example.net/local/oerclient/connect_callback.php/extra'
        ));
    }

    public function test_callback_matches_site_accepts_a_site_in_a_subdirectory(): void {
        $this->assertTrue(link_manager::callback_matches_site(
            'https://client.example.net/moodle/',
            'https://client.example.net/moodle/local/oerclient/connect_callback.php?state=abc'
        ));
        $this->assertFalse(link_manager::callback_matches_site(
            'https://client.example.net/moodle',
            'https://client.example.net/local/oerclient/connect_callback.php'
        ));
    }

    public function test_callback_matches_site_rejects_another_port(): void {
        $this->assertFalse(link_manager::callback_matches_site(
            'https://client.example.net',
            'https://client.example.net:8443/local/oerclient/connect_callback.php'
        ));
        $this->assertTrue(link_manager::callback_matches_site(
            'https://client.example.net',
            'https://client.example.net:443/local/oerclient/connect_callback.php'
        ));
    }

    /**
     * issue_code() minted a permanent token on every connect.php visit, so
     * every code that was never consumed left a live token behind (audit F3).
     * The token is now minted only when the code is consumed.
     */
    public function test_issue_code_mints_no_token_until_the_code_is_consumed(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();

        $code = link_manager::issue_code(1, (int) $user->id);

        $this->assertSame(0, $DB->count_records('external_tokens', ['userid' => $user->id]));
        $this->assertSame('', $DB->get_field('local_oerexchange_linkcodes', 'token', ['code' => $code]));

        $result = link_manager::consume($code);

        $tokens = $DB->get_records('external_tokens', ['userid' => $user->id]);
        $this->assertCount(1, $tokens);
        $token = reset($tokens);
        $this->assertSame($result->token, $token->token);
        $this->assertEquals($user->id, $token->creatorid);
    }

    public function test_an_expired_unconsumed_code_leaves_no_token(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $code = link_manager::issue_code(1, (int) $user->id);
        $DB->set_field('local_oerexchange_linkcodes', 'timeexpires', time() - 1, ['code' => $code]);

        try {
            link_manager::consume($code);
            $this->fail('an expired code must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_linkcodeexpired', $e->errorcode);
        }

        $this->assertSame(0, $DB->count_records('external_tokens', ['userid' => $user->id]));
    }

    /**
     * The minted token's id is recorded on the code, which is what lets a
     * site revoke delete it (site_manager::revoke()).
     */
    public function test_consume_records_the_tokens_id_on_the_code(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $code = link_manager::issue_code(1, (int) $user->id);

        $result = link_manager::consume($code);

        $tokenid = $DB->get_field('external_tokens', 'id', ['token' => $result->token], MUST_EXIST);
        $this->assertEquals($tokenid, $DB->get_field('local_oerexchange_linkcodes', 'tokenid', ['code' => $code]));
    }

    /**
     * Insert a code as issue_code() wrote it before tokens were minted on
     * consumption: pending, holding a live permanent token.
     *
     * @param int $userid
     * @param int $timeexpires
     * @return array [code, token]
     */
    protected function make_legacy_code(int $userid, int $timeexpires): array {
        global $DB;

        $serviceid = $DB->get_field('external_services', 'id', ['shortname' => 'local_oerexchange'], MUST_EXIST);
        $token = \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            (object) ['id' => $serviceid],
            $userid,
            \context_system::instance(),
            0,
            ''
        );
        $code = bin2hex(random_bytes(32));
        $DB->insert_record('local_oerexchange_linkcodes', (object) [
            'code' => $code,
            'siteid' => 1,
            'userid' => $userid,
            'token' => $token,
            'status' => 'pending',
            'timecreated' => $timeexpires - link_manager::TTL,
            'timeexpires' => $timeexpires,
        ]);

        return [$code, $token];
    }

    /**
     * A code issued before 7dd2a28 holds a live token from the moment it was
     * issued. Once it expires unused, cleanup deletes that token and the
     * code; a used code (the record that the teacher linked through the
     * site) and an unexpired one stay.
     */
    public function test_cleanup_deletes_expired_codes_and_the_tokens_they_still_hold(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        [$expiredcode, $expiredtoken] = $this->make_legacy_code((int) $user->id, time() - 1);
        [$livecode, $livetoken] = $this->make_legacy_code((int) $user->id, time() + 300);
        $usedcode = link_manager::issue_code(1, (int) $user->id);
        $used = link_manager::consume($usedcode);
        $DB->set_field('local_oerexchange_linkcodes', 'timeexpires', time() - 1, ['code' => $usedcode]);

        $this->assertSame(1, link_manager::cleanup_expired_codes());

        $this->assertFalse($DB->record_exists('external_tokens', ['token' => $expiredtoken]), 'nobody can collect it now');
        $this->assertFalse($DB->record_exists('local_oerexchange_linkcodes', ['code' => $expiredcode]));
        $this->assertTrue($DB->record_exists('external_tokens', ['token' => $livetoken]), 'still within its TTL');
        $this->assertTrue($DB->record_exists('local_oerexchange_linkcodes', ['code' => $livecode]));
        $this->assertTrue($DB->record_exists('local_oerexchange_linkcodes', ['code' => $usedcode]), 'linking history stays');
        $this->assertTrue($DB->record_exists('external_tokens', ['token' => $used->token]));
    }

    public function test_consuming_an_expired_legacy_code_deletes_its_token(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        [$code, $token] = $this->make_legacy_code((int) $user->id, time() - 1);

        try {
            link_manager::consume($code);
            $this->fail('an expired code must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_linkcodeexpired', $e->errorcode);
        }

        $this->assertFalse($DB->record_exists('external_tokens', ['token' => $token]));
        $this->assertSame('', $DB->get_field('local_oerexchange_linkcodes', 'token', ['code' => $code]));
    }

    public function test_revoke_site_tokens_deletes_a_token_a_pending_legacy_code_holds(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        [, $token] = $this->make_legacy_code((int) $user->id, time() + 300);

        link_manager::revoke_site_tokens(1);

        $this->assertFalse($DB->record_exists('external_tokens', ['token' => $token]));
    }
}
