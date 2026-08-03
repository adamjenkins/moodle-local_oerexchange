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
 * Starred resources, on top of Moodle's own favourites subsystem.
 *
 * Nothing here stores anything of its own. A star is a row in core's
 * {favourite} table under component 'local_oerexchange', itemtype 'resource',
 * itemid = the resource id, in the system context — which is what this
 * platform's resources live in.
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
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class star_manager {
    /** @var string The component favourites are recorded under. */
    const COMPONENT = 'local_oerexchange';

    /** @var string The favourites itemtype a starred resource uses. */
    const ITEMTYPE = 'resource';

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
     * Whether a user has starred a resource.
     *
     * @param int $resourceid
     * @param int $userid
     * @return bool
     */
    public static function is_starred(int $resourceid, int $userid): bool {
        if (!$userid || isguestuser($userid)) {
            return false;
        }

        return self::service($userid)->favourite_exists(
            self::COMPONENT,
            self::ITEMTYPE,
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
     * @param int $resourceid
     * @param int $userid
     * @param bool $starred the state wanted
     * @return bool the state after the call, read back rather than assumed
     */
    public static function set_starred(int $resourceid, int $userid, bool $starred): bool {
        if (!$userid || isguestuser($userid)) {
            return false;
        }

        $service = self::service($userid);
        $context = \context_system::instance();
        $exists = $service->favourite_exists(self::COMPONENT, self::ITEMTYPE, $resourceid, $context);

        if ($starred && !$exists) {
            $service->create_favourite(self::COMPONENT, self::ITEMTYPE, $resourceid, $context);
        } else if (!$starred && $exists) {
            $service->delete_favourite(self::COMPONENT, self::ITEMTYPE, $resourceid, $context);
        }

        // Read back rather than echoing the request. The web service hands
        // this value to the browser as the authoritative state, so it should
        // be what the store actually holds.
        return $service->favourite_exists(self::COMPONENT, self::ITEMTYPE, $resourceid, $context);
    }

    /**
     * How many people have starred a resource.
     *
     * A direct query rather than the service layer: count_favourites_by_type()
     * always scopes itself to the calling user, so it cannot answer a public
     * "how many people" question at all.
     *
     * @param int $resourceid
     * @return int
     */
    public static function star_count(int $resourceid): int {
        global $DB;

        return $DB->count_records('favourite', [
            'component' => self::COMPONENT,
            'itemtype' => self::ITEMTYPE,
            'itemid' => $resourceid,
            'contextid' => \context_system::instance()->id,
        ]);
    }

    /**
     * The resources a user has starred, most recently starred first.
     *
     * Ordering is done here because the repository's finder passes an empty
     * sort to get_records_select(), so the subsystem returns rows in no
     * defined order.
     *
     * Only published resources are returned. The profile page that shows this
     * list is world-readable, so a starred resource that has since been hidden,
     * taken down or deleted must not be advertised there by someone else's
     * bookmark.
     *
     * @param int $userid
     * @param int $limit 0 for no limit
     * @return \stdClass[] resources rows, keyed by resource id
     */
    public static function starred_resources(int $userid, int $limit = 0): array {
        global $DB;

        if (!$userid || isguestuser($userid)) {
            return [];
        }

        $sql = "SELECT r.*, f.timecreated AS timestarred
                  FROM {favourite} f
                  JOIN {local_oerexchange_resources} r ON r.id = f.itemid
                 WHERE f.component = :component
                       AND f.itemtype = :itemtype
                       AND f.contextid = :contextid
                       AND f.userid = :userid
                       AND r.status = :status
              ORDER BY f.timecreated DESC, r.id DESC";

        return $DB->get_records_sql($sql, [
            'component' => self::COMPONENT,
            'itemtype' => self::ITEMTYPE,
            'contextid' => \context_system::instance()->id,
            'userid' => $userid,
            'status' => 'published',
        ], 0, $limit);
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
     * @return int[]
     */
    public static function all_starred_ids(int $userid): array {
        global $DB;

        if (!$userid || isguestuser($userid)) {
            return [];
        }

        $ids = $DB->get_fieldset_select(
            'favourite',
            'itemid',
            'component = :component AND itemtype = :itemtype AND contextid = :contextid AND userid = :userid',
            [
                'component' => self::COMPONENT,
                'itemtype' => self::ITEMTYPE,
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
        \core_favourites\service_factory::get_service_for_component(self::COMPONENT)
            ->delete_favourites_by_type_and_item(self::ITEMTYPE, $resourceid, \context_system::instance());
    }
}
