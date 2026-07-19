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

    public function test_delete_for_userid_fully_deletes_new_profile_and_badge_data(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        \local_oerexchange\local\profile_manager::get_or_create_for_user((int) $user->id);
        \local_oerexchange\local\profile_manager::save((int) $user->id, [
            'slug' => 'deleteme', 'bio' => 'bio', 'expertise' => [], 'orcidurl' => '',
            'linkedinurl' => '', 'researchmapurl' => '', 'visible' => true,
        ]);
        $DB->insert_record('local_oerexchange_badges', (object) [
            'userid' => $user->id, 'badgekey' => 'trusted_contributor', 'timeawarded' => time(),
        ]);

        \local_oerexchange\privacy\provider::delete_data_for_user(
            new approved_contextlist($user, 'local_oerexchange', [\context_system::instance()->id])
        );

        $this->assertFalse($DB->record_exists('local_oerexchange_profiles', ['userid' => $user->id]));
        $this->assertFalse($DB->record_exists('local_oerexchange_badges', ['userid' => $user->id]));
    }

    public function test_delete_for_userid_tombstones_the_creators_resources_not_just_anonymizes(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $reviewer = $this->getDataGenerator()->create_user();

        $siteid = $DB->insert_record('local_oerexchange_sites', (object) [
            'name' => 'S', 'url' => 'https://x', 'contact' => 'x@x.com', 'serviceuserid' => null,
            'status' => 'active', 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $resourceid = $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'Secret course', 'summary' => 'Secret summary',
            'language' => '', 'tags' => 'tag1,tag2', 'licenseshortname' => 'cc-4.0',
            'activitytype' => null, 'courseformat' => null, 'creatorid' => $user->id,
            'siteid' => $siteid, 'status' => 'published', 'downloadcount' => 3, 'importcount' => 1,
            'forkedfromid' => null, 'timeshared' => time(), 'timemodified' => time(),
        ]);
        $versionid = $DB->insert_record('local_oerexchange_versions', (object) [
            'resourceid' => $resourceid, 'versionnumber' => 1, 'itemid' => $resourceid,
            'filename' => 'x.mbz', 'filesize' => 10, 'moodleversion' => '5.2', 'backupversion' => '5.2',
            'structurejson' => '{}', 'requiredplugins' => '[]', 'status' => 'ready',
            'parseerror' => null, 'timecreated' => time(),
        ]);
        // A review from someone OTHER than the deleting user — the design's
        // explicit decision (2026-07-19) is that it's deleted too, since it
        // describes courseware that will no longer exist.
        $DB->insert_record('local_oerexchange_reviews', (object) [
            'resourceid' => $resourceid, 'userid' => $reviewer->id, 'contexttext' => 'ctx',
            'adaptationtext' => 'adapt', 'outcometext' => 'outcome', 'rating' => 5,
            'status' => 'visible', 'timecreated' => time(),
        ]);
        $importerid = $DB->insert_record('local_oerexchange_imports', (object) [
            'resourceid' => $resourceid, 'versionid' => $versionid, 'siteid' => $siteid,
            'userid' => null, 'timecreated' => time(),
        ]);

        \local_oerexchange\privacy\provider::delete_data_for_user(
            new approved_contextlist($user, 'local_oerexchange', [\context_system::instance()->id])
        );

        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $resourceid], '*', MUST_EXIST);
        $this->assertSame('deleted', $resource->status, 'row survives as a tombstone, not deleted outright');
        $this->assertSame(0, (int) $resource->creatorid);
        $this->assertSame('', (string) $resource->title, 'descriptive metadata is scrubbed');
        $this->assertSame('', (string) $resource->summary);
        $this->assertSame('', (string) $resource->tags);

        $this->assertFalse(
            $DB->record_exists('local_oerexchange_versions', ['resourceid' => $resourceid]),
            'version rows (structure/required-plugins metadata) are deleted'
        );
        $this->assertFalse(
            $DB->record_exists('local_oerexchange_reviews', ['resourceid' => $resourceid]),
            'reviews on the deleted resource are gone, even the reviewer\'s own (design decision 2026-07-19)'
        );
        $this->assertTrue(
            $DB->record_exists('local_oerexchange_imports', ['id' => $importerid]),
            'other sites\' import history for this resourceid is left untouched'
        );

        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_oerexchange',
            'resource',
            $versionid,
            'id',
            false
        );
        $this->assertEmpty($files, 'the resource\'s files are actually deleted, not just detached');
    }
}
