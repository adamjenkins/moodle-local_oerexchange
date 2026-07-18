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

namespace local_oerexchange\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider tests. Added for MDL Shield audit finding 6 (2026-07-18):
 * local_oerexchange_linkcodes carried a userid + WS token but was absent
 * from get_metadata()/export/delete entirely.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oerexchange\privacy\provider
 */
final class privacy_provider_test extends \core_privacy\tests\provider_testcase {
    public function test_get_metadata_declares_linkcodes(): void {
        $collection = new \core_privacy\local\metadata\collection('local_oerexchange');
        $collection = provider::get_metadata($collection);

        $tables = array_map(
            fn($item) => method_exists($item, 'get_name') ? $item->get_name() : null,
            $collection->get_collection()
        );
        $this->assertContains('local_oerexchange_linkcodes', $tables);
    }

    public function test_get_contexts_for_userid_finds_user_via_linkcodes_only(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $siteid = $DB->insert_record('local_oerexchange_sites', (object) [
            'name' => 'S', 'url' => 'https://x', 'contact' => 'x@x.com',
            'serviceuserid' => null, 'status' => 'active', 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_oerexchange_linkcodes', (object) [
            'code' => 'abc123', 'siteid' => $siteid, 'userid' => $user->id, 'token' => 'sometoken',
            'status' => 'pending', 'timecreated' => time(), 'timeexpires' => time() + 300,
        ]);

        $contextlist = provider::get_contexts_for_userid($user->id);
        $this->assertNotEmpty($contextlist->get_contextids());
    }

    public function test_export_includes_linkcodes_without_raw_token(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $siteid = $DB->insert_record('local_oerexchange_sites', (object) [
            'name' => 'S', 'url' => 'https://x', 'contact' => 'x@x.com',
            'serviceuserid' => null, 'status' => 'active', 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_oerexchange_linkcodes', (object) [
            'code' => 'abc123', 'siteid' => $siteid, 'userid' => $user->id, 'token' => 'sometoken-should-not-export',
            'status' => 'pending', 'timecreated' => time(), 'timeexpires' => time() + 300,
        ]);

        $this->setUser($user);
        writer::reset();
        $approvedlist = new approved_contextlist($user, 'local_oerexchange', [\context_system::instance()->id]);
        provider::export_user_data($approvedlist);

        $data = writer::with_context(\context_system::instance())->get_data([get_string('pluginname', 'local_oerexchange')]);
        $this->assertNotEmpty($data->linkcodes);
        $this->assertArrayNotHasKey('token', (array) $data->linkcodes[0]);
    }

    public function test_delete_data_for_user_removes_linkcodes(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $siteid = $DB->insert_record('local_oerexchange_sites', (object) [
            'name' => 'S', 'url' => 'https://x', 'contact' => 'x@x.com',
            'serviceuserid' => null, 'status' => 'active', 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_oerexchange_linkcodes', (object) [
            'code' => 'abc123', 'siteid' => $siteid, 'userid' => $user->id, 'token' => 't',
            'status' => 'pending', 'timecreated' => time(), 'timeexpires' => time() + 300,
        ]);
        $this->assertEquals(1, $DB->count_records('local_oerexchange_linkcodes', ['userid' => $user->id]));

        $approvedlist = new approved_contextlist($user, 'local_oerexchange', [\context_system::instance()->id]);
        provider::delete_data_for_user($approvedlist);

        $this->assertEquals(0, $DB->count_records('local_oerexchange_linkcodes', ['userid' => $user->id]));
    }
}
