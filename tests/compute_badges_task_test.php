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

use local_oerexchange\local\badge_manager;
use local_oerexchange\task\compute_badges_task;

/**
 * Tests for compute_badges_task.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oerexchange\task\compute_badges_task
 */
final class compute_badges_task_test extends \advanced_testcase {
    public function test_execute_awards_badges_for_every_qualifying_creator(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('badge_trustedcontributor_minresources', 1, 'local_oerexchange');
        set_config('badge_trustedcontributor_mindownloads', 10, 'local_oerexchange');
        set_config('badge_trustedcontributor_minrating', 0, 'local_oerexchange');

        $qualifies = $this->getDataGenerator()->create_user();
        $doesnot = $this->getDataGenerator()->create_user();
        $siteid = $DB->insert_record('local_oerexchange_sites', (object) [
            'name' => 'S', 'url' => 'https://x', 'contact' => 'x@x.com', 'serviceuserid' => null,
            'status' => 'active', 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'A', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => $qualifies->id, 'siteid' => $siteid, 'status' => 'published',
            'downloadcount' => 500, 'importcount' => 0, 'forkedfromid' => null,
            'timeshared' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'B', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => $doesnot->id, 'siteid' => $siteid, 'status' => 'published',
            'downloadcount' => 1, 'importcount' => 0, 'forkedfromid' => null,
            'timeshared' => time(), 'timemodified' => time(),
        ]);

        $task = new compute_badges_task();
        $task->execute();

        $this->assertSame([badge_manager::BADGE_TRUSTED_CONTRIBUTOR], badge_manager::get_badges_for_user($qualifies->id));
        $this->assertSame([], badge_manager::get_badges_for_user($doesnot->id));
    }
}
