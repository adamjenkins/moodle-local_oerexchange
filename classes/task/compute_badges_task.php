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

use local_oerexchange\local\badge_manager;

/**
 * Nightly scheduled task: evaluate badge thresholds for every user who has
 * ever published a resource, per the design's "Badge computation" section.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class compute_badges_task extends \core\task\scheduled_task {
    #[\Override]
    public function get_name(): string {
        return get_string('task_computebadges', 'local_oerexchange');
    }

    #[\Override]
    public function execute() {
        global $DB;

        $creatorids = $DB->get_fieldset_select(
            'local_oerexchange_resources',
            'DISTINCT creatorid',
            "status = 'published' AND creatorid <> 0"
        );

        foreach ($creatorids as $userid) {
            $awarded = badge_manager::evaluate_and_award((int) $userid);
            if ($awarded) {
                mtrace("local_oerexchange: awarded " . implode(',', $awarded) . " to user {$userid}");
            }
        }
    }
}
