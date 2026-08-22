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
 * The contributor listing: who has published to this Exchange, how much, and
 * in what order.
 *
 * Owns both the query and the rendering so the Dashboard block and the
 * /contributors page cannot drift apart — the same arrangement catalogue_view
 * uses for index.php and the public-landing hook.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class contributor_list {
    /** @var int contributors per page on the full listing page */
    public const PERPAGE = 24;

    /** @var string sort by number of resources shared */
    public const SORT_RESOURCES = 'resources';

    /** @var string sort by number of courses shared */
    public const SORT_COURSES = 'courses';

    /** @var string sort by most recent share */
    public const SORT_RECENT = 'recent';

    /**
     * Every accepted sort key, in menu order.
     *
     * @return string[]
     */
    public static function sort_keys(): array {
        return [self::SORT_RESOURCES, self::SORT_COURSES, self::SORT_RECENT];
    }

    /**
     * Map any caller-supplied sort to a known key. The result picks a
     * hardcoded ORDER BY clause — a caller's string is never interpolated
     * into SQL.
     *
     * @param string $sort
     * @return string one of the SORT_* constants
     */
    public static function normalise_sort(string $sort): string {
        return in_array($sort, self::sort_keys(), true) ? $sort : self::SORT_RESOURCES;
    }

    /**
     * The inner UNION that defines contribution: a resource counts for its
     * creator and for every co-author, because a co-author holds exactly the
     * same rights over it (db/install.xml, local_oerexchange_coauthors
     * COMMENT; resource_manager::user_is_author()).
     *
     * UNION rather than UNION ALL: the schema says a creator is never also a
     * co-author row, but UNION makes double-counting impossible even if that
     * invariant is ever broken.
     *
     * @return string
     */
    protected static function contribution_union(): string {
        return "SELECT r.creatorid AS userid, r.id AS resourceid, r.type, r.timeshared
                  FROM {local_oerexchange_resources} r
                 WHERE r.status = :status1 AND r.creatorid <> 0
                 UNION
                SELECT ca.userid, r.id, r.type, r.timeshared
                  FROM {local_oerexchange_coauthors} ca
                  JOIN {local_oerexchange_resources} r ON r.id = ca.resourceid
                 WHERE r.status = :status2";
    }

    /**
     * The joins that decide who is eligible to be shown at all.
     *
     * A deleted or suspended account is not held up for recognition; and the
     * profiles join IS the visibility rule — the contributor's own "show my
     * profile publicly" switch — which simultaneously guarantees every card
     * has somewhere to link.
     *
     * @return string
     */
    protected static function eligibility_joins(): string {
        return "JOIN {user} u ON u.id = c.userid AND u.deleted = 0 AND u.suspended = 0
                JOIN {local_oerexchange_profiles} p ON p.userid = c.userid AND p.visible = 1";
    }

    /**
     * The status parameters both halves of the UNION need.
     *
     * @return array
     */
    protected static function status_params(): array {
        return [
            'status1' => resource_manager::STATUS_PUBLISHED,
            'status2' => resource_manager::STATUS_PUBLISHED,
        ];
    }

    /**
     * Fetch contributors with their contribution totals.
     *
     * @param string $sort one of the SORT_* constants; anything else falls back to SORT_RESOURCES
     * @param int $limit 0 for no limit
     * @param int $offset
     * @return \stdClass[] list of rows: userid, resourcecount, coursecount, latestshared, membersince
     */
    public static function get_contributors(
        string $sort = self::SORT_RESOURCES,
        int $limit = 0,
        int $offset = 0
    ): array {
        global $DB;

        $orderbys = [
            self::SORT_RESOURCES => 'resourcecount DESC, latestshared DESC, c.userid ASC',
            self::SORT_COURSES => 'coursecount DESC, resourcecount DESC, c.userid ASC',
            self::SORT_RECENT => 'latestshared DESC, c.userid ASC',
        ];
        $orderby = $orderbys[self::normalise_sort($sort)];

        $sql = "SELECT c.userid,
                       COUNT(DISTINCT c.resourceid) AS resourcecount,
                       COUNT(DISTINCT CASE WHEN c.type = 'course' THEN c.resourceid END) AS coursecount,
                       MAX(c.timeshared) AS latestshared,
                       MIN(c.timeshared) AS membersince
                  FROM (" . self::contribution_union() . ") c
                  " . self::eligibility_joins() . "
              GROUP BY c.userid
              ORDER BY {$orderby}";

        $records = $DB->get_records_sql($sql, self::status_params(), $offset, $limit);

        return array_values(array_map(fn($r) => (object) [
            'userid' => (int) $r->userid,
            'resourcecount' => (int) $r->resourcecount,
            'coursecount' => (int) $r->coursecount,
            'latestshared' => (int) $r->latestshared,
            'membersince' => (int) $r->membersince,
        ], $records));
    }

    /**
     * How many contributors are eligible in total, for the paging bar.
     *
     * @return int
     */
    public static function count_contributors(): int {
        global $DB;

        $sql = "SELECT COUNT(DISTINCT c.userid)
                  FROM (" . self::contribution_union() . ") c
                  " . self::eligibility_joins();

        return (int) $DB->count_records_sql($sql, self::status_params());
    }
}
