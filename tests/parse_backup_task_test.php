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
use local_oerexchange\task\parse_backup_task;

/**
 * Tests for parse_backup_task resource status transitions: 'pending' → 'published'
 * on successful parse, and 'pending' → stays 'pending' on failure.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(parse_backup_task::class)]
final class parse_backup_task_test extends \advanced_testcase {
    /**
     * Insert a resource + version row and stage the given fixture .mbz into
     * the version's 'resource' filearea, ready for parse_backup_task::execute().
     *
     * @param string $fixturefilename filename under tests/fixtures/
     * @param string $resourcestatus resource status ('pending'|'published')
     * @param string $type resource type ('course'|'activity')
     * @return array [resourceid, versionid]
     */
    protected function stage_version(
        string $fixturefilename,
        string $resourcestatus = 'pending',
        string $type = 'course'
    ): array {
        global $DB, $USER;

        $siteid = $DB->insert_record('local_oerexchange_sites', (object) [
            'name' => 'S', 'url' => 'https://x', 'contact' => 'x@x.com', 'serviceuserid' => null,
            'status' => 'active', 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $resourceid = (int) $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => $type, 'title' => 'A', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => $type === 'activity' ? 'forum' : null,
            'courseformat' => null, 'creatorid' => $USER->id, 'siteid' => $siteid,
            'status' => $resourcestatus, 'downloadcount' => 0, 'importcount' => 0,
            'forkedfromid' => null, 'timeshared' => time(), 'timemodified' => time(),
        ]);
        $versionid = (int) $DB->insert_record('local_oerexchange_versions', (object) [
            'resourceid' => $resourceid, 'versionnumber' => 1, 'itemid' => 0,
            'filename' => $fixturefilename, 'filesize' => 0, 'status' => 'parsing',
            'timecreated' => time(),
        ]);

        $fs = get_file_storage();
        $context = \context_system::instance();
        $fs->create_file_from_pathname([
            'contextid' => $context->id,
            'component' => 'local_oerexchange',
            'filearea' => 'resource',
            'itemid' => $versionid,
            'filepath' => '/',
            'filename' => $fixturefilename,
        ], __DIR__ . '/fixtures/' . $fixturefilename);
        $DB->set_field('local_oerexchange_versions', 'itemid', $versionid, ['id' => $versionid]);

        return [$resourceid, $versionid];
    }

    public function test_execute_flips_pending_resource_to_published_on_success(): void {
        global $DB;
        $this->resetAfterTest();

        // Seed a resource with status='pending' and a version with status='parsing'
        // pointing at a real small valid .mbz fixture.
        [$resourceid, $versionid] = $this->stage_version('course_no_userdata.mbz', 'pending', 'course');

        $task = new parse_backup_task();
        $task->set_custom_data(['versionid' => $versionid]);
        $task->execute();

        // Assert the resource's status is now 'published'.
        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $resourceid]);
        $this->assertSame('published', $resource->status);

        // Also verify the version is 'ready'.
        $version = $DB->get_record('local_oerexchange_versions', ['id' => $versionid]);
        $this->assertSame('ready', $version->status);
    }

    public function test_execute_leaves_pending_resource_pending_on_sanitycheck_failure(): void {
        global $DB;
        $this->resetAfterTest();

        // Create a corrupt fixture by truncating a valid backup file.
        $tmpdir = make_temp_directory('oerexchange_test_corrupt');
        $tmpfile = $tmpdir . '/corrupt.mbz';
        copy(__DIR__ . '/fixtures/course_no_userdata.mbz', $tmpfile);
        file_put_contents($tmpfile, substr(file_get_contents($tmpfile), 0, 10));

        // Seed a resource with status='pending' and a version pointing at the corrupt file.
        [$resourceid, $versionid] = $this->stage_version('course_no_userdata.mbz', 'pending', 'course');

        // Replace the staged fixture with the corrupt version.
        $fs = get_file_storage();
        $context = \context_system::instance();
        $files = $fs->get_area_files($context->id, 'local_oerexchange', 'resource', $versionid);
        foreach ($files as $file) {
            if (!$file->is_directory()) {
                $file->delete();
            }
        }
        $fs->create_file_from_pathname([
            'contextid' => $context->id,
            'component' => 'local_oerexchange',
            'filearea' => 'resource',
            'itemid' => $versionid,
            'filepath' => '/',
            'filename' => 'corrupt.mbz',
        ], $tmpfile);
        $DB->set_field('local_oerexchange_versions', 'filename', 'corrupt.mbz', ['id' => $versionid]);
        remove_dir($tmpdir);

        $task = new parse_backup_task();
        $task->set_custom_data(['versionid' => $versionid]);
        $task->execute();

        // Assert the resource's status is still 'pending' (not flipped, not any other value).
        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $resourceid]);
        $this->assertSame('pending', $resource->status);

        // Assert the version's status is 'failed'.
        $version = $DB->get_record('local_oerexchange_versions', ['id' => $versionid]);
        $this->assertSame('failed', $version->status);
    }

    public function test_execute_does_not_touch_already_published_resource_status(): void {
        global $DB;
        $this->resetAfterTest();

        // Seed a resource with status='published' (simulating a second version being added)
        // and a version pointing at a valid fixture.
        [$resourceid, $versionid] = $this->stage_version('course_no_userdata.mbz', 'published', 'course');

        $task = new parse_backup_task();
        $task->set_custom_data(['versionid' => $versionid]);
        $task->execute();

        // Assert the resource's status is still 'published' (the WHERE ... AND status = 'pending'
        // guard means this path is a no-op for it either way, but assert the outcome
        // explicitly since it's a Global Constraint).
        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $resourceid]);
        $this->assertSame('published', $resource->status);

        // Also verify the version is 'ready'.
        $version = $DB->get_record('local_oerexchange_versions', ['id' => $versionid]);
        $this->assertSame('ready', $version->status);
    }
}
