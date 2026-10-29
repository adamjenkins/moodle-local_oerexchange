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
use local_oerexchange\local\link_manager;
use local_oerexchange\local\site_manager;
use local_oerexchange\task\cleanup_task;

/**
 * Tests for the hourly cleanup task: it is registered, and one run removes
 * expired link codes (with any token they still hold) and stale pending
 * registrations. The individual rules are tested with their classes.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(cleanup_task::class)]
final class cleanup_task_test extends \advanced_testcase {
    public function test_the_task_is_registered(): void {
        $this->resetAfterTest();
        $this->assertNotNull(\core\task\manager::get_scheduled_task(cleanup_task::class));
    }

    public function test_a_run_removes_expired_codes_and_stale_registrations(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $code = link_manager::issue_code(1, (int) $user->id);
        $DB->set_field('local_oerexchange_linkcodes', 'timeexpires', time() - 1, ['code' => $code]);
        $siteid = site_manager::register('Stale', 'https://stale.example.net', 'c@example.com', '203.0.113.5');
        $DB->set_field(
            'local_oerexchange_sites',
            'timecreated',
            time() - site_manager::PENDING_REGISTRATION_TTL - 1,
            ['id' => $siteid]
        );

        ob_start();
        (new cleanup_task())->execute();
        ob_end_clean();

        $this->assertFalse($DB->record_exists('local_oerexchange_linkcodes', ['code' => $code]));
        $this->assertFalse($DB->record_exists('local_oerexchange_sites', ['id' => $siteid]));
    }
}
