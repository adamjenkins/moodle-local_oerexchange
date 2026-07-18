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

use local_oerexchange\local\link_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for link_manager — the account-linking one-time-code handshake.
 * Previously entirely untested by automation; added on the second MDL
 * Shield audit pass (2026-07-18).
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oerexchange\local\link_manager
 */
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
            // Expected.
        }

        $record = $DB->get_record('local_oerexchange_linkcodes', ['code' => $code]);
        $this->assertSame('expired', $record->status);
    }
}
