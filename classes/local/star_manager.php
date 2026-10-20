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
 * Starred resources and starred collections, on top of Moodle's own
 * favourites subsystem.
 *
 * Nothing here stores anything of its own. A star is a row in core's
 * {favourite} table under component 'local_oerexchange', itemtype 'resource'
 * or 'collection', itemid = the resource or collection id, in the system
 * context — which is what this platform's content lives in.
 *
 * Using the subsystem rather than a twelfth plugin table is deliberate. It is
 * documented as holding "an arbitrary item (itemtype, itemid)" for a user, it
 * admits any installed component, it brings its own unique index (so a
 * double-click cannot create two stars), and — the part that matters most here
 * — it brings its own privacy provider, so the plugin declares a subsystem
 * link instead of writing another export/delete path of its own. A
 * declared-but-unserviced table is this plugin's most repeated defect.
 *
 * Every other file talks to stars through this class, never to the subsystem
 * directly, the same way coauthor_manager and badge_manager wrap their storage.
 *
 * Collections joined later, and did so by adding an OPTIONAL TRAILING
 * $itemtype to each method rather than by growing a parallel
 * collection_star_manager. Two wrappers around one subsystem would be two
 * places to remember the component string, the system context and the
 * read-back-don't-assume rule — and every existing resource call site keeps
 * working untouched, which is the other half of why the parameter goes last
 * and defaults to ITEMTYPE.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class star_manager {
    /** @var string The component favourites are recorded under. */
    const COMPONENT = 'local_oerexchange';

    /** @var string The favourites itemtype a starred resource uses. */
    const ITEMTYPE = 'resource';

    /** @var string The favourites itemtype a starred collection uses. */
    const ITEMTYPE_COLLECTION = 'collection';

    /** @var string[] Every itemtype this plugin stars. */
    const ITEMTYPES = [self::ITEMTYPE, self::ITEMTYPE_COLLECTION];

    /**
     * The favourites service for one user.
     *
     * @param int $userid
     * @return \core_favourites\local\service\user_favourite_service
     */
    protected static function service(int $userid) {
        return \core_favourites\service_factory::get_service_for_user_context(
            \context_user::instance($userid)
        );
    }

    /**
     * Whether a user has starred a resource (or, with $itemtype, a collection).
     *
     * @param int $resourceid the resource id, or the collection id when $itemtype says so
     * @param int $userid
     * @param string $itemtype one of self::ITEMTYPES
     * @return bool
     */
    public static function is_starred(int $resourceid, int $userid, string $itemtype = self::ITEMTYPE): bool {
        if (!$userid || isguestuser($userid)) {
            return false;
        }
        self::check_itemtype($itemtype);

        return self::service($userid)->favourite_exists(
            self::COMPONENT,
            $itemtype,
            $resourceid,
            \context_system::instance()
        );
    }

    /**
     * Star or unstar a resource, and report the state afterwards.
     *
     * The existence check before creating is not belt-and-braces: the
     * repository's add() inserts unconditionally, so a double-submitted star
     * would hit the unique index and raise a dml_write_exception rather than
     * being the no-op the user expects. Core's own course favourites guard the
     * same call the same way.
     *
     * A guest, or a zero user id, is a no-op returning false rather than an
     * exception — matching is_starred() and starred_resources(), which both
     * refuse the same way. Every caller gates on being signed in first, but an
     * entry point that throws where its siblings return is a trap for the next
     * one.
     *
     * @param int $resourceid the resource id, or the collection id when $itemtype says so
     * @param int $userid
     * @param bool $starred the state wanted
     * @param string $itemtype one of self::ITEMTYPES
     * @return bool the state after the call, read back rather than assumed
     */
    public static function set_starred(
        int $resourceid,
        int $userid,
        bool $starred,
        string $itemtype = self::ITEMTYPE
    ): bool {
        if (!$userid || isguestuser($userid)) {
            return false;
        }
        self::check_itemtype($itemtype);

        $service = self::service($userid);
        $context = \context_system::instance();
        $exists = $service->favourite_exists(self::COMPONENT, $itemtype, $resourceid, $context);

        if ($starred && !$exists) {
            $service->create_favourite(self::COMPONENT, $itemtype, $resourceid, $context);
        } else if (!$starred && $exists) {
            $service->delete_favourite(self::COMPONENT, $itemtype, $resourceid, $context);
        }

        // Read back rather than echoing the request. The web service hands
        // this value to the browser as the authoritative state, so it should
        // be what the store actually holds.
        return $service->favourite_exists(self::COMPONENT, $itemtype, $resourceid, $context);
    }

    /**
     * How many people have starred a resource.
     *
     * A direct query rather than the service layer: count_favourites_by_type()
     * always scopes itself to the calling user, so it cannot answer a public
     * "how many people" question at all.
     *
     * @param int $resourceid the resource id, or the collection id when $itemtype says so
     * @param string $itemtype one of self::ITEMTYPES
     * @return int
     */
    public static function star_count(int $resourceid, string $itemtype = self::ITEMTYPE): int {
        global $DB;

        self::check_itemtype($itemtype);

        return $DB->count_records('favourite', [
            'component' => self::COMPONENT,
            'itemtype' => $itemtype,
            'itemid' => $resourceid,
            'contextid' => \context_system::instance()->id,
        ]);
    }

    /**
     * Star counts for many items at once, in ONE query.
     *
     * The collections browser shows a star count on every card and can sort
     * by it; star_count() per card is how that page becomes twenty queries.
     * (public_collections() gets its own counts inside its single query — this
     * is for the pages that already have their rows.)
     *
     * @param int[] $itemids
     * @param string $itemtype one of self::ITEMTYPES
     * @return array<int, int> keyed by itemid; every id asked for is present, 0 if unstarred
     */
    public static function star_counts_for(array $itemids, string $itemtype = self::ITEMTYPE): array {
        global $DB;

        self::check_itemtype($itemtype);

        $itemids = array_values(array_unique(array_map('intval', $itemids)));
        if (!$itemids) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'itemid');
        $rows = $DB->get_records_sql(
            "SELECT f.itemid, COUNT(1) AS starcount
               FROM {favourite} f
              WHERE f.component = :component
                    AND f.itemtype = :itemtype
                    AND f.contextid = :contextid
                    AND f.itemid $insql
           GROUP BY f.itemid",
            $inparams + [
                'component' => self::COMPONENT,
                'itemtype' => $itemtype,
                'contextid' => \context_system::instance()->id,
            ]
        );

        $counts = array_fill_keys($itemids, 0);
        foreach ($rows as $row) {
            $counts[(int) $row->itemid] = (int) $row->starcount;
        }

        return $counts;
    }

    /**
     * The resources a user has starred, most recently starred first.
     *
     * Ordering is done here because the repository's finder passes an empty
     * sort to get_records_select(), so the subsystem returns rows in no
     * defined order.
     *
     * Only publicly visible rows are returned. The profile page that shows
     * this list is world-readable, so a starred resource that has since been
     * hidden, taken down or deleted — or a starred collection that has been
     * unpublished, taken down or deleted — must not be advertised there by
     * someone else's bookmark.
     *
     * With $itemtype = ITEMTYPE_COLLECTION this returns COLLECTION rows, not
     * resource rows; starred_collections() is the readable spelling of that
     * call and exists so no page has to pass the itemtype by hand.
     *
     * @param int $userid
     * @param int $limit 0 for no limit
     * @param string $itemtype one of self::ITEMTYPES
     * @return \stdClass[] rows of the table matching $itemtype, keyed by id, each with ->timestarred
     */
    public static function starred_resources(int $userid, int $limit = 0, string $itemtype = self::ITEMTYPE): array {
        global $DB;

        if (!$userid || isguestuser($userid)) {
            return [];
        }
        self::check_itemtype($itemtype);

        // The "may a stranger be shown this" rule belongs to whoever owns the
        // content, not to this class. resource_manager's is one status
        // comparison; collection_manager::public_where() is three conditions
        // and is asked for rather than copied, so an unpublished collection
        // cannot leak through here the day that rule changes.
        if ($itemtype === self::ITEMTYPE_COLLECTION) {
            $table = collection_manager::TABLE;
            $visible = collection_manager::public_where('i.');
            $visibleparams = collection_manager::public_params();
        } else {
            $table = 'local_oerexchange_resources';
            // A starred list is a standalone listing on a public profile, so
            // it follows the catalogue's collection-only rule too.
            [$standalone, $standaloneparams] = resource_manager::standalone_where('i');
            $visible = 'i.status = :status AND ' . $standalone;
            $visibleparams = ['status' => resource_manager::STATUS_PUBLISHED] + $standaloneparams;
        }

        $sql = "SELECT i.*, f.timecreated AS timestarred
                  FROM {favourite} f
                  JOIN {" . $table . "} i ON i.id = f.itemid
                 WHERE f.component = :component
                       AND f.itemtype = :itemtype
                       AND f.contextid = :contextid
                       AND f.userid = :userid
                       AND $visible
              ORDER BY f.timecreated DESC, i.id DESC";

        return $DB->get_records_sql($sql, $visibleparams + [
            'component' => self::COMPONENT,
            'itemtype' => $itemtype,
            'contextid' => \context_system::instance()->id,
            'userid' => $userid,
        ], 0, $limit);
    }

    /**
     * The public collections a user has starred, most recently starred first.
     *
     * @param int $userid
     * @param int $limit 0 for no limit
     * @return \stdClass[] collections rows, keyed by collection id, each with ->timestarred
     */
    public static function starred_collections(int $userid, int $limit = 0): array {
        return self::starred_resources($userid, $limit, self::ITEMTYPE_COLLECTION);
    }

    /**
     * Every resource id a user has starred, whatever its status.
     *
     * Unlike starred_resources(), this filters nothing. It exists for the
     * privacy export, where a star on a resource that has since been hidden or
     * taken down is still the user's own data and under-reporting it would be
     * the wrong answer to a subject access request.
     *
     * @param int $userid
     * @param string $itemtype one of self::ITEMTYPES
     * @return int[]
     */
    public static function all_starred_ids(int $userid, string $itemtype = self::ITEMTYPE): array {
        global $DB;

        if (!$userid || isguestuser($userid)) {
            return [];
        }
        self::check_itemtype($itemtype);

        $ids = $DB->get_fieldset_select(
            'favourite',
            'itemid',
            'component = :component AND itemtype = :itemtype AND contextid = :contextid AND userid = :userid',
            [
                'component' => self::COMPONENT,
                'itemtype' => $itemtype,
                'contextid' => \context_system::instance()->id,
                'userid' => $userid,
            ]
        );

        return array_map('intval', $ids);
    }

    /**
     * Drop every star on a resource.
     *
     * Called when a resource is deleted: the stars are other people's rows in
     * a core table, so nothing else in the plugin's own delete path would
     * reach them and they would dangle against an id that no longer resolves.
     *
     * @param int $resourceid
     * @return void
     */
    public static function delete_for_resource(int $resourceid): void {
        self::delete_for_item(self::ITEMTYPE, $resourceid);
    }

    /**
     * Drop every star on a collection.
     *
     * Called when a collection is tombstoned, for exactly the reason
     * delete_for_resource() exists: the stars belong to other people, live in
     * a core table, and nothing in collection_manager::delete() would
     * otherwise reach them.
     *
     * @param int $collectionid
     * @return void
     */
    public static function delete_for_collection(int $collectionid): void {
        self::delete_for_item(self::ITEMTYPE_COLLECTION, $collectionid);
    }

    /**
     * Drop every star on one item of one type.
     *
     * @param string $itemtype
     * @param int $itemid
     * @return void
     */
    protected static function delete_for_item(string $itemtype, int $itemid): void {
        \core_favourites\service_factory::get_service_for_component(self::COMPONENT)
            ->delete_favourites_by_type_and_item($itemtype, $itemid, \context_system::instance());
    }

    /**
     * Refuse an itemtype this plugin does not star.
     *
     * A typo'd itemtype is far worse than it looks: create_favourite() would
     * happily store it, favourite_exists() would then answer false for the
     * star the user just created, and the row would be invisible to every
     * count, listing and delete path in this class — an orphan in a core
     * table that nothing cleans up.
     *
     * @param string $itemtype
     * @throws \coding_exception
     */
    protected static function check_itemtype(string $itemtype): void {
        if (!in_array($itemtype, self::ITEMTYPES, true)) {
            throw new \coding_exception('Unknown local_oerexchange star itemtype: ' . $itemtype);
        }
    }
}
