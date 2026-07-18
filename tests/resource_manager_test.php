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

use local_oerexchange\local\resource_manager;

defined('MOODLE_INTERNAL') || die();

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

    /**
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
