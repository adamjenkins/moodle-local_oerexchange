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

namespace local_oerexchange\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_oerexchange\local\resource_manager;

/**
 * local_oerexchange_get_share_status external function — lets the client
 * site that published a resource read back its current state on the
 * Exchange: is it still visible, when was it first published, and when was
 * the copy the Exchange is serving last updated.
 *
 * Deliberately a separate function rather than extra fields on
 * get_resource(): get_resource is the public catalogue read and refuses
 * anything not 'published', which is exactly the case an author most needs
 * to see here (they hid it). This one answers only for the caller's OWN
 * resources, so it can safely report hidden ones.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_share_status extends external_api {
    /**
     * Describes the parameters this function accepts.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'resourceid' => new external_value(PARAM_INT, 'Exchange resource id'),
        ]);
    }

    /**
     * Returns the caller's own resource's current state on the Exchange.
     *
     * @param int $resourceid
     * @return array
     */
    public static function execute(int $resourceid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['resourceid' => $resourceid]);
        self::validate_context(\context_system::instance());

        $resource = $DB->get_record(
            'local_oerexchange_resources',
            ['id' => $params['resourceid']],
            '*',
            MUST_EXIST
        );

        // The token is personal (minted for one Exchange user by the
        // account-linking handshake), so $USER is the linked educator. Only
        // the creator may read this back — a registered site's token must not
        // become a way to enumerate other people's hidden resources.
        if ((int) $resource->creatorid !== (int) $USER->id) {
            throw new \moodle_exception('error_notyourresource', 'local_oerexchange');
        }

        $version = resource_manager::get_current_version((int) $resource->id);

        return [
            'resourceid' => (int) $resource->id,
            'title' => $resource->title,
            'status' => $resource->status,
            'visible' => ($resource->status === 'published'),
            'timeshared' => (int) $resource->timeshared,
            // The served version's timecreated is when the copy the Exchange
            // is actually handing out was uploaded — a truer "last updated"
            // than resource.timemodified, which also moves for metadata-only
            // edits like hiding.
            'timeupdated' => $version ? (int) $version->timecreated : (int) $resource->timeshared,
            'downloadcount' => (int) $resource->downloadcount,
            'importcount' => (int) $resource->importcount,
        ];
    }

    /**
     * Describes the structure of execute()'s return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'resourceid' => new external_value(PARAM_INT, 'Exchange resource id'),
            'title' => new external_value(PARAM_TEXT, 'Current title on the Exchange'),
            'status' => new external_value(PARAM_ALPHA, 'published|pending|hidden|removed|deleted'),
            'visible' => new external_value(PARAM_BOOL, 'Whether it currently appears in the catalogue'),
            'timeshared' => new external_value(PARAM_INT, 'When it was first published'),
            'timeupdated' => new external_value(PARAM_INT, 'When the served copy was last uploaded'),
            'downloadcount' => new external_value(PARAM_INT, 'Times downloaded'),
            'importcount' => new external_value(PARAM_INT, 'Times imported'),
        ]);
    }
}
