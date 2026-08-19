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
use local_oerexchange\task\rescan_required_plugins_task;

/**
 * Tests for rescan_required_plugins_task: it corrects the requiredplugins
 * column of current versions and touches NOTHING else — no statuses, no
 * files, no rows it cannot re-parse, no superseded/data-resource rows.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(rescan_required_plugins_task::class)]
final class rescan_required_plugins_task_test extends \advanced_testcase {
    /**
     * Insert a resource + version row, optionally staging the fixture .mbz
     * into the version's file area.
     *
     * @param string $versionstatus versions row status
     * @param string $type resource type ('course'|'data')
     * @param string $requiredplugins stored requiredplugins JSON
     * @param bool $withfile stage the fixture file
     * @return array [resourceid, versionid]
     */
    protected function stage_version(
        string $versionstatus = 'ready',
        string $type = 'course',
        string $requiredplugins = '[]',
        bool $withfile = true
    ): array {
        global $DB, $USER;

        $siteid = $DB->insert_record('local_oerexchange_sites', (object) [
            'name' => 'S', 'url' => 'https://x', 'contact' => 'x@x.com', 'serviceuserid' => null,
            'status' => 'active', 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $resourceid = (int) $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => $type, 'title' => 'A', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => null,
            'courseformat' => null, 'creatorid' => $USER->id, 'siteid' => $siteid,
            'status' => 'published', 'downloadcount' => 0, 'importcount' => 0,
            'forkedfromid' => null, 'timeshared' => time(), 'timemodified' => time(),
        ]);
        $versionid = (int) $DB->insert_record('local_oerexchange_versions', (object) [
            'resourceid' => $resourceid, 'versionnumber' => 1, 'itemid' => 0,
            'filename' => 'course_no_userdata.mbz', 'filesize' => 0, 'status' => $versionstatus,
            'requiredplugins' => $requiredplugins,
            'timecreated' => time(),
        ]);

        if ($withfile) {
            $fs = get_file_storage();
            $context = \context_system::instance();
            $fs->create_file_from_pathname([
                'contextid' => $context->id,
                'component' => 'local_oerexchange',
                'filearea' => 'resource',
                'itemid' => $versionid,
                'filepath' => '/',
                'filename' => 'course_no_userdata.mbz',
            ], __DIR__ . '/fixtures/course_no_userdata.mbz');
        }
        $DB->set_field('local_oerexchange_versions', 'itemid', $versionid, ['id' => $versionid]);

        return [$resourceid, $versionid];
    }

    /**
     * Runs the task, swallowing its mtrace output.
     *
     * @return void
     */
    protected function run_task(): void {
        $task = new rescan_required_plugins_task();
        ob_start();
        try {
            $task->execute();
        } finally {
            ob_end_clean();
        }
    }

    public function test_stale_wrong_list_is_corrected_and_nothing_else_changes(): void {
        global $DB;
        $this->resetAfterTest();

        // The fixture contains only standard plugins, so a stored list
        // claiming a ghost mod is stale-wrong; the rescan must correct it
        // to [] — an observable change only the rescan can make.
        [$resourceid, $versionid] = $this->stage_version(
            'ready',
            'course',
            '[{"type":"mod","name":"ghostmod"}]'
        );

        $this->run_task();

        $version = $DB->get_record('local_oerexchange_versions', ['id' => $versionid]);
        $this->assertSame([], json_decode($version->requiredplugins, true));
        $this->assertSame('ready', $version->status);
        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $resourceid]);
        $this->assertSame('published', $resource->status);
    }

    public function test_missing_file_is_skipped_untouched(): void {
        global $DB;
        $this->resetAfterTest();

        [, $versionid] = $this->stage_version(
            'ready',
            'course',
            '[{"type":"mod","name":"ghostmod"}]',
            false
        );

        $this->run_task();

        $version = $DB->get_record('local_oerexchange_versions', ['id' => $versionid]);
        $this->assertSame('[{"type":"mod","name":"ghostmod"}]', $version->requiredplugins);
        $this->assertSame('ready', $version->status);
    }

    public function test_corrupt_file_leaves_row_unchanged(): void {
        global $DB;
        $this->resetAfterTest();

        [, $versionid] = $this->stage_version(
            'ready',
            'course',
            '[{"type":"mod","name":"ghostmod"}]',
            false
        );
        // Stage garbage bytes under the .mbz filename.
        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_oerexchange',
            'filearea' => 'resource',
            'itemid' => $versionid,
            'filepath' => '/',
            'filename' => 'course_no_userdata.mbz',
        ], 'this is not a backup archive');

        $this->run_task();
        // Core's zip fallback flags the garbage bytes ("Not a zip archive")
        // via debugging() before the parse throws — expected here.
        $this->assertDebuggingCalledCount(1);

        $version = $DB->get_record('local_oerexchange_versions', ['id' => $versionid]);
        $this->assertSame('[{"type":"mod","name":"ghostmod"}]', $version->requiredplugins);
        $this->assertSame('ready', $version->status);
    }

    public function test_superseded_and_data_rows_are_not_rescanned(): void {
        global $DB;
        $this->resetAfterTest();

        [, $supersededid] = $this->stage_version(
            'superseded',
            'course',
            '[{"type":"mod","name":"ghostmod"}]'
        );
        [, $dataid] = $this->stage_version(
            'ready',
            'data',
            '[{"type":"mod","name":"ghostmod"}]'
        );

        $this->run_task();

        $this->assertSame(
            '[{"type":"mod","name":"ghostmod"}]',
            $DB->get_field('local_oerexchange_versions', 'requiredplugins', ['id' => $supersededid])
        );
        $this->assertSame(
            '[{"type":"mod","name":"ghostmod"}]',
            $DB->get_field('local_oerexchange_versions', 'requiredplugins', ['id' => $dataid])
        );
    }
}
