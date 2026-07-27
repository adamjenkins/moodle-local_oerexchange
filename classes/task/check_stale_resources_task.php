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

namespace local_oerexchange\task;

use local_oerexchange\local\stale_manager;

/**
 * Nightly janitor for the abandoned-courseware lifecycle: warns authors of
 * resources unmaintained past the threshold, and removes flagged resources
 * whose grace period ran out with neither an update nor a "Still fresh"
 * confirmation. All decisions live in stale_manager; this class is only the
 * cron entry point.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_stale_resources_task extends \core\task\scheduled_task {
    #[\Override]
    public function get_name(): string {
        return get_string('task_checkstale', 'local_oerexchange');
    }

    #[\Override]
    public function execute() {
        $summary = stale_manager::run_checks();
        mtrace(sprintf(
            'local_oerexchange: stale check — %d unflagged, %d removed, %d notified',
            $summary['unflagged'],
            $summary['removed'],
            $summary['notified']
        ));
    }
}
