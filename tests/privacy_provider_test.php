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

use PHPUnit\Framework\Attributes\CoversClass;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider tests. Added for MDL Shield audit finding 6 (2026-07-18):
 * local_oerexchange_linkcodes carried a userid + WS token but was absent
 * from get_metadata()/export/delete entirely.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(provider::class)]
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

    public function test_get_contexts_for_userid_finds_user_via_profile_only(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        \local_oerexchange\local\profile_manager::get_or_create_for_user((int) $user->id);

        $contextlist = provider::get_contexts_for_userid($user->id);
        $this->assertNotEmpty($contextlist->get_contextids());
    }

    public function test_get_contexts_for_userid_finds_user_via_badge_only(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_oerexchange_badges', (object) [
            'userid' => $user->id, 'badgekey' => 'trusted_contributor', 'timeawarded' => time(),
        ]);

        $contextlist = provider::get_contexts_for_userid($user->id);
        $this->assertNotEmpty($contextlist->get_contextids());
    }

    public function test_get_users_in_context_includes_profile_owner(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        \local_oerexchange\local\profile_manager::get_or_create_for_user((int) $user->id);

        $userlist = new userlist(\context_system::instance(), 'local_oerexchange');
        provider::get_users_in_context($userlist);

        $this->assertContains((int) $user->id, $userlist->get_userids());
    }

    /**
     * FINDING 6 (final whole-branch review): get_users_in_context()'s
     * creatorid lookup used '1=1', which pulls in creatorid = 0 —
     * profile_manager::delete_creator_resource()'s tombstone marker, not a
     * real user id — as a phantom entry in the returned userlist.
     */
    public function test_get_users_in_context_excludes_tombstoned_creatorid_zero(): void {
        global $DB;
        $this->resetAfterTest();

        $siteid = $DB->insert_record('local_oerexchange_sites', (object) [
            'name' => 'S', 'url' => 'https://x', 'contact' => 'x@x.com', 'serviceuserid' => null,
            'status' => 'active', 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => '', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => 0, 'siteid' => $siteid, 'status' => 'deleted',
            'downloadcount' => 0, 'importcount' => 0, 'forkedfromid' => null,
            'timeshared' => time(), 'timemodified' => time(),
        ]);

        $creator = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'Real', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => $creator->id, 'siteid' => $siteid, 'status' => 'published',
            'downloadcount' => 0, 'importcount' => 0, 'forkedfromid' => null,
            'timeshared' => time(), 'timemodified' => time(),
        ]);

        $userlist = new userlist(\context_system::instance(), 'local_oerexchange');
        provider::get_users_in_context($userlist);

        $userids = $userlist->get_userids();
        $this->assertNotContains(0, $userids, 'creatorid=0 (tombstone) must never appear in the userlist');
        $this->assertContains((int) $creator->id, $userids);
    }

    public function test_get_users_in_context_includes_badge_owner(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_oerexchange_badges', (object) [
            'userid' => $user->id, 'badgekey' => 'trusted_contributor', 'timeawarded' => time(),
        ]);

        $userlist = new userlist(\context_system::instance(), 'local_oerexchange');
        provider::get_users_in_context($userlist);

        $this->assertContains((int) $user->id, $userlist->get_userids());
    }

    public function test_export_includes_profile_data(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        \local_oerexchange\local\profile_manager::get_or_create_for_user((int) $user->id);
        \local_oerexchange\local\profile_manager::save((int) $user->id, [
            'slug' => 'exportme', 'bio' => 'my bio', 'expertise' => ['maths', 'physics'],
            'orcidurl' => 'https://orcid.org/0000', 'linkedinurl' => '', 'researchmapurl' => '',
            'visible' => true,
        ]);

        $this->setUser($user);
        writer::reset();
        $approvedlist = new approved_contextlist($user, 'local_oerexchange', [\context_system::instance()->id]);
        provider::export_user_data($approvedlist);

        $data = writer::with_context(\context_system::instance())->get_data([get_string('pluginname', 'local_oerexchange')]);
        $this->assertNotEmpty($data->profile);
        $this->assertSame('exportme', $data->profile[0]['slug']);
        $this->assertSame('my bio', $data->profile[0]['bio']);
        $this->assertStringContainsString('maths', $data->profile[0]['expertise']);
    }

    public function test_export_includes_badge_data(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_oerexchange_badges', (object) [
            'userid' => $user->id, 'badgekey' => 'trusted_contributor', 'timeawarded' => time(),
        ]);

        $this->setUser($user);
        writer::reset();
        $approvedlist = new approved_contextlist($user, 'local_oerexchange', [\context_system::instance()->id]);
        provider::export_user_data($approvedlist);

        $data = writer::with_context(\context_system::instance())->get_data([get_string('pluginname', 'local_oerexchange')]);
        $this->assertNotEmpty($data->badges);
        $this->assertSame('trusted_contributor', $data->badges[0]['badgekey']);
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
        $reportid = $DB->insert_record('local_oerexchange_reports', (object) [
            'resourceid' => $resourceid, 'userid' => $reviewer->id, 'type' => 'copyright',
            'details' => 'This is not mine', 'status' => 'open', 'resolvernote' => null,
            'timecreated' => time(), 'timeresolved' => null,
        ]);

        // Cover-image file, same File API convention as parse_backup_task.php
        // / resource.php: component=local_oerexchange, filearea=coverimage,
        // itemid=resourceid, context_system::instance().
        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_oerexchange',
            'filearea' => 'coverimage',
            'itemid' => $resourceid,
            'filepath' => '/',
            'filename' => 'cover.png',
        ], 'fake-image-bytes');

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
        $this->assertFalse(
            $DB->record_exists('local_oerexchange_reports', ['id' => $reportid]),
            'moderation reports on the deleted resource are gone'
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

        $coverfiles = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_oerexchange',
            'coverimage',
            $resourceid,
            'id',
            false
        );
        $this->assertEmpty($coverfiles, 'the resource\'s cover image is actually deleted, not just detached');
    }

    public function test_delete_for_userid_leaves_other_users_resources_untouched_and_tombstones_non_published_status(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $otheruser = $this->getDataGenerator()->create_user();

        $siteid = $DB->insert_record('local_oerexchange_sites', (object) [
            'name' => 'S', 'url' => 'https://x', 'contact' => 'x@x.com', 'serviceuserid' => null,
            'status' => 'active', 'timecreated' => time(), 'timemodified' => time(),
        ]);

        // Another user's resource — must be completely untouched by the
        // deleting user's request.
        $otherresourceid = $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'Other user\'s course', 'summary' => 'Other summary',
            'language' => '', 'tags' => 'tag', 'licenseshortname' => 'cc-4.0',
            'activitytype' => null, 'courseformat' => null, 'creatorid' => $otheruser->id,
            'siteid' => $siteid, 'status' => 'published', 'downloadcount' => 1, 'importcount' => 0,
            'forkedfromid' => null, 'timeshared' => time(), 'timemodified' => time(),
        ]);
        $otherversionid = $DB->insert_record('local_oerexchange_versions', (object) [
            'resourceid' => $otherresourceid, 'versionnumber' => 1, 'itemid' => $otherresourceid,
            'filename' => 'other.mbz', 'filesize' => 5, 'moodleversion' => '5.2', 'backupversion' => '5.2',
            'structurejson' => '{}', 'requiredplugins' => '[]', 'status' => 'ready',
            'parseerror' => null, 'timecreated' => time(),
        ]);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_oerexchange',
            'filearea' => 'resource',
            'itemid' => $otherversionid,
            'filepath' => '/',
            'filename' => 'other.mbz',
        ], 'other-file-bytes');

        // The deleting user's OWN resource, but not in 'published' status —
        // must still be caught and tombstoned; the code has no status filter,
        // this proves it.
        $hiddenresourceid = $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'Hidden course', 'summary' => 'Hidden summary',
            'language' => '', 'tags' => 'tag', 'licenseshortname' => 'cc-4.0',
            'activitytype' => null, 'courseformat' => null, 'creatorid' => $user->id,
            'siteid' => $siteid, 'status' => 'hidden', 'downloadcount' => 0, 'importcount' => 0,
            'forkedfromid' => null, 'timeshared' => time(), 'timemodified' => time(),
        ]);

        \local_oerexchange\privacy\provider::delete_data_for_user(
            new approved_contextlist($user, 'local_oerexchange', [\context_system::instance()->id])
        );

        $otherresource = $DB->get_record('local_oerexchange_resources', ['id' => $otherresourceid], '*', MUST_EXIST);
        $this->assertSame('published', $otherresource->status, 'another user\'s resource status is untouched');
        $this->assertSame((int) $otheruser->id, (int) $otherresource->creatorid, 'another user\'s resource creatorid is untouched');
        $this->assertSame('Other user\'s course', $otherresource->title, 'another user\'s resource metadata is untouched');
        $otherfiles = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_oerexchange',
            'resource',
            $otherversionid,
            'id',
            false
        );
        $this->assertNotEmpty($otherfiles, 'another user\'s resource file is untouched');

        $hiddenresource = $DB->get_record('local_oerexchange_resources', ['id' => $hiddenresourceid], '*', MUST_EXIST);
        $this->assertSame('deleted', $hiddenresource->status, 'a hidden-status resource of the deleting user is still tombstoned');
        $this->assertSame(0, (int) $hiddenresource->creatorid);
        $this->assertSame('', (string) $hiddenresource->title);
    }
}
