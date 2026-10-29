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

namespace local_oerexchange\local;

/**
 * Filing moderation reports about a resource or a collection.
 *
 * resource.php and collection_controller::report() each inserted a row on
 * every POST, so one user could fill moderate.php with copies of the same
 * complaint (security audit 2026-10-28, F7). A user now has at most one open
 * report per resource or collection; once a moderator resolves or dismisses
 * it, they may report again.
 *
 * Callers validate the report type and hold the sesskey; this class only
 * decides whether a row is written.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_manager {
    /** @var string The reports table, without braces. */
    const TABLE = 'local_oerexchange_reports';

    /**
     * File a report about a resource.
     *
     * @param int $resourceid
     * @param int $userid the reporter
     * @param string $type already validated against the offered set
     * @param string $details
     * @return bool true when a report was filed, false when this user already has an open one
     */
    public static function report_resource(int $resourceid, int $userid, string $type, string $details): bool {
        return self::file('resourceid', $resourceid, $userid, $type, $details);
    }

    /**
     * File a report about a collection.
     *
     * @param int $collectionid
     * @param int $userid the reporter
     * @param string $type already validated against the offered set
     * @param string $details
     * @return bool true when a report was filed, false when this user already has an open one
     */
    public static function report_collection(int $collectionid, int $userid, string $type, string $details): bool {
        return self::file('collectionid', $collectionid, $userid, $type, $details);
    }

    /**
     * Whether $userid already has an open report about this item.
     *
     * @param string $column 'resourceid' or 'collectionid'
     * @param int $itemid
     * @param int $userid
     * @return bool
     */
    public static function has_open_report(string $column, int $itemid, int $userid): bool {
        global $DB;

        return $DB->record_exists(self::TABLE, [$column => $itemid, 'userid' => $userid, 'status' => 'open']);
    }

    /**
     * Insert the report row unless the reporter already has an open one.
     *
     * @param string $column 'resourceid' or 'collectionid'
     * @param int $itemid
     * @param int $userid
     * @param string $type
     * @param string $details
     * @return bool
     */
    protected static function file(string $column, int $itemid, int $userid, string $type, string $details): bool {
        global $DB;

        if (self::has_open_report($column, $itemid, $userid)) {
            return false;
        }

        $DB->insert_record(self::TABLE, (object) [
            'resourceid' => $column === 'resourceid' ? $itemid : null,
            'collectionid' => $column === 'collectionid' ? $itemid : null,
            'userid' => $userid,
            'type' => $type,
            'details' => $details,
            'status' => 'open',
            'resolvernote' => null,
            'timecreated' => time(),
            'timeresolved' => null,
        ]);

        return true;
    }
}
