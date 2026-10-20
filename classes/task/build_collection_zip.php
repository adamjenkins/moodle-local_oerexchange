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
 * Adhoc task: build one collection's download zip, when the site is quiet.
 *
 * Custom data: collectionid, and deferredsince once a build has been put off
 * for being busy.
 *
 * "When the site is quiet" is collection_zip::is_busy(). A busy site puts the
 * build off by BUSY_RETRY seconds, but never for longer than MAX_DEFER in
 * total, so a site that is never quiet still gets its zips. The deferral is a
 * NEW task carrying deferredsince rather than a reschedule of this one: while
 * this task runs its own row is still queued, and rescheduling it would be
 * undone when the run finishes and the row is deleted.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class build_collection_zip extends \core\task\adhoc_task {
    #[\Override]
    public function get_name(): string {
        return get_string('task_buildcollectionzip', 'local_oerexchange');
    }

    #[\Override]
    public function execute() {
        $data = (object) $this->get_custom_data();
        $collectionid = (int) ($data->collectionid ?? 0);
        if (!$collectionid) {
            return;
        }
        $deferredsince = (int) ($data->deferredsince ?? 0);

        if (collection_zip::is_busy() && (!$deferredsince || time() - $deferredsince < collection_zip::MAX_DEFER)) {
            $later = new self();
            $later->set_custom_data([
                'collectionid' => $collectionid,
                'deferredsince' => $deferredsince ?: time(),
            ]);
            $later->set_component('local_oerexchange');
            $later->set_next_run_time(time() + collection_zip::BUSY_RETRY);
            \core\task\manager::reschedule_or_queue_adhoc_task($later);
            mtrace("Collection {$collectionid}: site busy, zip build put off.");
            return;
        }

        $result = collection_zip::build($collectionid);
        mtrace("Collection {$collectionid}: zip {$result}.");
    }
}
