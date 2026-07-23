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
use local_oerexchange\local\parser\mbz_parser;
use local_oerexchange\task\parse_backup_task;

/**
 * Tests for cover-image extraction in parse_backup_task, against real backup
 * fixtures (course + forum + label, users=false — see mbz_parser_test.php for
 * the base fixture; course_with_image.mbz additionally carries a course
 * overviewfiles image).
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(mbz_parser::class)]
#[CoversClass(parse_backup_task::class)]
final class parse_backup_task_coverimage_test extends \advanced_testcase {
    /**
     * Insert a resource + version row and stage the given fixture .mbz into
     * the version's 'resource' filearea, ready for parse_backup_task::execute().
     *
     * @param string $fixturefilename filename under tests/fixtures/
     * @param string $type resource type ('course'|'activity')
     * @return array [resourceid, versionid]
     */
    protected function stage_version(string $fixturefilename, string $type = 'course'): array {
        global $DB, $USER;

        $siteid = $DB->insert_record('local_oerexchange_sites', (object) [
            'name' => 'S', 'url' => 'https://x', 'contact' => 'x@x.com', 'serviceuserid' => null,
            'status' => 'active', 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $resourceid = (int) $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => $type, 'title' => 'A', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => $type === 'activity' ? 'forum' : null,
            'courseformat' => null, 'creatorid' => $USER->id, 'siteid' => $siteid,
            'status' => 'published', 'downloadcount' => 0, 'importcount' => 0,
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

    /**
     * Fetch the resource's stored coverimage files, if any.
     *
     * @param int $resourceid
     * @return \stored_file[]
     */
    protected function coverimage_files(int $resourceid): array {
        $context = \context_system::instance();
        return get_file_storage()->get_area_files(
            $context->id,
            'local_oerexchange',
            'coverimage',
            $resourceid,
            'id',
            false
        );
    }

    public function test_extracts_cover_image_for_course_backup_with_image(): void {
        global $DB;
        $this->resetAfterTest();

        [$resourceid, $versionid] = $this->stage_version('course_with_image.mbz', 'course');

        $task = new parse_backup_task();
        $task->set_custom_data(['versionid' => $versionid]);
        $task->execute();

        $version = $DB->get_record('local_oerexchange_versions', ['id' => $versionid]);
        $this->assertSame('ready', $version->status);

        $files = $this->coverimage_files($resourceid);
        $this->assertNotEmpty($files);
        $file = reset($files);
        $this->assertSame('coverimage.png', $file->get_filename());
    }

    public function test_no_cover_image_for_course_backup_without_image(): void {
        global $DB;
        $this->resetAfterTest();

        [$resourceid, $versionid] = $this->stage_version('course_no_userdata.mbz', 'course');

        $task = new parse_backup_task();
        $task->set_custom_data(['versionid' => $versionid]);
        $task->execute();

        $version = $DB->get_record('local_oerexchange_versions', ['id' => $versionid]);
        $this->assertSame('ready', $version->status);

        $this->assertEmpty($this->coverimage_files($resourceid));
    }

    public function test_no_cover_image_extracted_for_non_raster_manifest_entry(): void {
        global $DB;
        $this->resetAfterTest();

        // Same fixture as test_extracts_cover_image_for_course_backup_with_image(),
        // except the overviewfiles manifest entry claims filename 'evil.svg' /
        // mimetype 'image/svg+xml' instead of 'coverimage.png' — a crafted
        // .mbz attempting to get a non-raster file stored via this ingestion
        // path. Must be skipped, same as the no-image-in-backup case.
        [$resourceid, $versionid] = $this->stage_version('course_with_evil_svg.mbz', 'course');

        $task = new parse_backup_task();
        $task->set_custom_data(['versionid' => $versionid]);
        $task->execute();

        $version = $DB->get_record('local_oerexchange_versions', ['id' => $versionid]);
        $this->assertSame('ready', $version->status);

        $this->assertEmpty($this->coverimage_files($resourceid));
    }

    public function test_no_cover_image_extracted_for_non_image_content(): void {
        global $DB;
        $this->resetAfterTest();

        // Same fixture as test_extracts_cover_image_for_course_backup_with_image(),
        // except the overviewfiles manifest entry's ARCHIVED CONTENT (not its
        // claimed filename/mimetype) has been replaced with plain text. The
        // manifest still claims filename 'coverimage.png' / mimetype
        // 'image/png', so this passes is_allowed_cover_image_type() — unlike
        // course_with_evil_svg.mbz above, which is rejected by that
        // extension/mimetype check before extraction ever happens. This
        // fixture instead exercises is_verified_raster_image()'s
        // getimagesize() content check, which must reject it once the actual
        // (non-image) bytes are extracted.
        [$resourceid, $versionid] = $this->stage_version('course_with_fake_raster.mbz', 'course');

        $task = new parse_backup_task();
        $task->set_custom_data(['versionid' => $versionid]);
        $task->execute();

        $version = $DB->get_record('local_oerexchange_versions', ['id' => $versionid]);
        $this->assertSame('ready', $version->status);

        $this->assertEmpty($this->coverimage_files($resourceid));
    }

    public function test_no_cover_image_extracted_for_activity_type_share(): void {
        global $DB;
        $this->resetAfterTest();

        // Even though this fixture happens to carry a course overview image,
        // an activity-type share must never get an auto-extracted cover image.
        [$resourceid, $versionid] = $this->stage_version('course_with_image.mbz', 'activity');

        $task = new parse_backup_task();
        $task->set_custom_data(['versionid' => $versionid]);
        $task->execute();

        $version = $DB->get_record('local_oerexchange_versions', ['id' => $versionid]);
        $this->assertSame('ready', $version->status);

        $this->assertEmpty($this->coverimage_files($resourceid));
    }
}
