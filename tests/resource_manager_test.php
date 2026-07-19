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

use local_oerexchange\local\profile_manager;
use local_oerexchange\local\resource_manager;

/**
 * Tests for resource_manager: the download visibility gate (MDL Shield audit
 * finding 1a, 2026-07-18) and the maxbackupbytes enforcement (finding 2).
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oerexchange\local\resource_manager
 */
final class resource_manager_test extends \advanced_testcase {
    public function test_can_download_unsigned_allows_published_ready(): void {
        $this->resetAfterTest();
        $resource = (object) ['status' => 'published'];
        $version = (object) ['status' => 'ready'];
        $this->assertTrue(resource_manager::can_download_unsigned($version, $resource));
    }

    public function test_can_download_unsigned_blocks_unpublished_for_ordinary_user(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        foreach (['hidden', 'removed'] as $status) {
            $resource = (object) ['status' => $status];
            $version = (object) ['status' => 'ready'];
            $this->assertFalse(resource_manager::can_download_unsigned($version, $resource), "status={$status}");
        }

        $resource = (object) ['status' => 'published'];
        $version = (object) ['status' => 'parsing'];
        $this->assertFalse(resource_manager::can_download_unsigned($version, $resource), 'version not ready');

        $version = (object) ['status' => 'failed'];
        $this->assertFalse(resource_manager::can_download_unsigned($version, $resource), 'version failed');
    }

    public function test_can_download_unsigned_allows_moderator_regardless_of_status(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'manager']);
        role_assign($roleid, $user->id, \context_system::instance()->id);
        $this->setUser($user);

        $resource = (object) ['status' => 'hidden'];
        $version = (object) ['status' => 'failed'];
        $this->assertTrue(resource_manager::can_download_unsigned($version, $resource));
    }

    public function test_publish_rejects_backup_over_configured_max_size(): void {
        global $DB, $USER;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        set_config('maxbackupbytes', 10, 'local_oerexchange');

        $draftitemid = $this->create_draft_file($user->id, str_repeat('x', 100));

        $this->expectException(\moodle_exception::class);
        resource_manager::publish($draftitemid, (int) $user->id, 1, [
            'type' => 'course', 'title' => 't', 'summary' => '', 'language' => '',
            'tags' => '', 'licenseshortname' => 'cc-4.0', 'activitytype' => null,
        ]);
    }

    public function test_publish_accepts_backup_within_configured_max_size(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        set_config('maxbackupbytes', 1000, 'local_oerexchange');

        $draftitemid = $this->create_draft_file($user->id, str_repeat('x', 100));

        [$resourceid, $versionid] = resource_manager::publish($draftitemid, (int) $user->id, 1, [
            'type' => 'course', 'title' => 't', 'summary' => '', 'language' => '',
            'tags' => '', 'licenseshortname' => 'cc-4.0', 'activitytype' => null,
        ]);

        $this->assertGreaterThan(0, $resourceid);
        $this->assertGreaterThan(0, $versionid);
    }

    public function test_publish_new_version_increments_number_without_disturbing_counts(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        set_config('maxbackupbytes', 1000, 'local_oerexchange');

        // First publish: a brand-new resource (version 1).
        [$resourceid, $v1] = resource_manager::publish(
            $this->create_draft_file($user->id, str_repeat('x', 50)),
            (int) $user->id,
            1,
            [
                'type' => 'course', 'title' => 't', 'summary' => '', 'language' => '',
                'tags' => '', 'licenseshortname' => 'cc-4.0', 'activitytype' => null,
            ]
        );

        // Simulate downloads/imports having accrued on the catalogue entry.
        $DB->set_field('local_oerexchange_resources', 'downloadcount', 7, ['id' => $resourceid]);
        $DB->set_field('local_oerexchange_resources', 'importcount', 3, ['id' => $resourceid]);
        $DB->set_field('local_oerexchange_resources', 'timemodified', 100, ['id' => $resourceid]);

        // Second publish: a new version of the SAME resource.
        [$resourceid2, $v2] = resource_manager::publish(
            $this->create_draft_file($user->id, str_repeat('y', 50)),
            (int) $user->id,
            1,
            [
                'type' => 'course', 'title' => 't', 'summary' => '', 'language' => '',
                'tags' => '', 'licenseshortname' => 'cc-4.0', 'activitytype' => null,
            ],
            $resourceid
        );

        $this->assertSame($resourceid, $resourceid2, 'new version stays on the same resource');
        $this->assertSame(1, (int) $DB->get_field('local_oerexchange_versions', 'versionnumber', ['id' => $v1]));
        $this->assertSame(2, (int) $DB->get_field('local_oerexchange_versions', 'versionnumber', ['id' => $v2]));

        // Adding a version must bump timemodified but leave the atomic counters
        // untouched — the timemodified update is a targeted set_field, not a
        // whole-row write-back that would re-persist (and clobber) the counts.
        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $resourceid], '*', MUST_EXIST);
        $this->assertSame(7, (int) $resource->downloadcount);
        $this->assertSame(3, (int) $resource->importcount);
        $this->assertGreaterThan(100, (int) $resource->timemodified);
    }

    public function test_publish_new_version_rejects_other_users_resource(): void {
        $this->resetAfterTest();
        set_config('maxbackupbytes', 1000, 'local_oerexchange');

        $owner = $this->getDataGenerator()->create_user();
        $this->setUser($owner);
        [$resourceid] = resource_manager::publish(
            $this->create_draft_file($owner->id, str_repeat('x', 50)),
            (int) $owner->id,
            1,
            [
                'type' => 'course', 'title' => 't', 'summary' => '', 'language' => '',
                'tags' => '', 'licenseshortname' => 'cc-4.0', 'activitytype' => null,
            ]
        );

        $intruder = $this->getDataGenerator()->create_user();
        $this->setUser($intruder);
        $this->expectException(\moodle_exception::class);
        resource_manager::publish(
            $this->create_draft_file($intruder->id, str_repeat('z', 50)),
            (int) $intruder->id,
            1,
            [
                'type' => 'course', 'title' => 't', 'summary' => '', 'language' => '',
                'tags' => '', 'licenseshortname' => 'cc-4.0', 'activitytype' => null,
            ],
            $resourceid
        );
    }

    /**
     * FINDING 1 (final whole-branch review): before this fix, no production
     * code path ever called profile_manager::get_or_create_for_user() —
     * profile_edit_controller::edit()'s GET branch only reads an existing
     * profile (get_by_slug()), it never creates one, so the edit page and
     * public profile page 404 forever unless a profile row already exists.
     * resource_manager::publish() is the one place that should create it,
     * on a creator's first-ever published resource.
     */
    public function test_publish_creates_a_profile_for_the_creator_on_first_publish(): void {
        $this->resetAfterTest();
        set_config('maxbackupbytes', 1000, 'local_oerexchange');

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assertNull(profile_manager::get_by_userid((int) $user->id), 'precondition: no profile yet');

        resource_manager::publish(
            $this->create_draft_file($user->id, str_repeat('x', 50)),
            (int) $user->id,
            1,
            [
                'type' => 'course', 'title' => 't', 'summary' => '', 'language' => '',
                'tags' => '', 'licenseshortname' => 'cc-4.0', 'activitytype' => null,
            ]
        );

        $profile = profile_manager::get_by_userid((int) $user->id);
        $this->assertNotNull($profile, 'publish() must create a profile row for a first-time creator');
        $this->assertSame((int) $user->id, (int) $profile->userid);
    }

    /**
     * Publishing a second version of an already-published resource must not
     * duplicate or crash on the (already-existing) profile row — this is a
     * "does nothing harmful" assertion, not a claim that publish() must skip
     * calling get_or_create_for_user() (it's naturally idempotent either way).
     */
    public function test_publish_new_version_does_not_duplicate_or_break_existing_profile(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('maxbackupbytes', 1000, 'local_oerexchange');

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        [$resourceid] = resource_manager::publish(
            $this->create_draft_file($user->id, str_repeat('x', 50)),
            (int) $user->id,
            1,
            [
                'type' => 'course', 'title' => 't', 'summary' => '', 'language' => '',
                'tags' => '', 'licenseshortname' => 'cc-4.0', 'activitytype' => null,
            ]
        );
        $firstprofile = profile_manager::get_by_userid((int) $user->id);
        $this->assertNotNull($firstprofile);

        resource_manager::publish(
            $this->create_draft_file($user->id, str_repeat('y', 50)),
            (int) $user->id,
            1,
            [
                'type' => 'course', 'title' => 't', 'summary' => '', 'language' => '',
                'tags' => '', 'licenseshortname' => 'cc-4.0', 'activitytype' => null,
            ],
            $resourceid
        );

        $count = $DB->count_records('local_oerexchange_profiles', ['userid' => $user->id]);
        $this->assertSame(1, $count, 'a second version must not create a duplicate profile row');
        $secondprofile = profile_manager::get_by_userid((int) $user->id);
        $this->assertSame($firstprofile->id, $secondprofile->id);
    }

    /**
     * Creates a draft-area file with the given contents, for use as a fake upload in tests.
     *
     * @param int $userid
     * @param string $contents
     * @return int the new draftitemid
     */
    protected function create_draft_file(int $userid, string $contents): int {
        $draftitemid = file_get_unused_draft_itemid();
        $fs = get_file_storage();
        $usercontext = \context_user::instance($userid);
        $fs->create_file_from_string([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'test.mbz',
        ], $contents);
        return $draftitemid;
    }
}
