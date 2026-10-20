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

use local_oerexchange\local\collection_zip;

/**
 * Scheduled sweep: queue a zip build for every collection whose download is
 * out of date.
 *
 * The known change points (collection edits, a resource's new version, title
 * or visibility) queue a build themselves. This catches the rest, such as a
 * moderator's takedown of a resource or a change made before this feature
 * existed, so a stale zip is never left waiting for the next edit.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class queue_collection_zips extends \core\task\scheduled_task {
    #[\Override]
    public function get_name(): string {
        return get_string('task_queuecollectionzips', 'local_oerexchange');
    }

    #[\Override]
    public function execute() {
        $ids = collection_zip::pending_collection_ids();
        foreach ($ids as $id) {
            collection_zip::queue($id);
        }
        mtrace('Collection zips queued: ' . count($ids));
    }
}
