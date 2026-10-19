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
 * The collection half of the moderation queue.
 *
 * {local_oerexchange_reports} became polymorphic when collections became
 * reportable: resourceid is nullable, collectionid was added nullable, and
 * exactly one of the two is set on any row. Every reader therefore has to say
 * which kind of report it wants, because the alternative is not an empty
 * result but a wrong one — the queue's resource query LEFT JOINs
 * {local_oerexchange_resources} on resourceid, so a collection report would
 * have come through it as a row with no resource and been labelled "(deleted
 * resource)". This class is the collection-shaped reader; moderate.php's own
 * resource query carries the matching `resourceid IS NOT NULL`.
 *
 * It also owns the two takedown transitions for collections, so that
 * moderate.php and moderate_hidden.php cannot drift in which states they
 * consider takedown-able — the same reason moderation_report::restore()
 * exists for resources.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moderation_collections {
    /**
     * Every open report that is about a collection, oldest first.
     *
     * Oldest-first matches moderate.php's resource queue: a report is work,
     * and the work that has been waiting longest is the work to do next.
     *
     * The collection is LEFT JOINed and its columns are aliased rather than
     * merged: reports and collections both have id, status and timecreated, so
     * a `c.*` would have silently overwritten half the report row with half of
     * the collection's (the collision Foundation's get_items() row shape
     * avoids for the same reason). A null collectiontitle means the collection
     * row is gone — only possible through a privacy erasure, since the owner's
     * own delete tombstones the row rather than removing it — and the page
     * renders the placeholder rather than a blank cell.
     *
     * @return \stdClass[] report rows, each also carrying collectiontitle,
     *      collectionslug, collectionstatus and collectionmodhidden (all null
     *      when the collection row no longer exists)
     */
    public static function open_reports(): array {
        global $DB;

        return $DB->get_records_sql(
            "SELECT r.*,
                    c.title AS collectiontitle,
                    c.slug AS collectionslug,
                    c.status AS collectionstatus,
                    c.modhidden AS collectionmodhidden
               FROM {local_oerexchange_reports} r
          LEFT JOIN {" . collection_manager::TABLE . "} c ON c.id = r.collectionid
              WHERE r.status = :status
                    AND r.collectionid IS NOT NULL
           ORDER BY r.timecreated ASC, r.id ASC",
            ['status' => 'open']
        );
    }

    /**
     * Every collection a moderator is currently holding, newest takedown first.
     *
     * Tombstoned collections are excluded even when the modhidden flag is
     * still set on them: the row is already gone as far as the public is
     * concerned, and offering Restore on it would put the takedown/restore
     * cycle around a husk — the same judgement moderate.php's status guard
     * makes for resources.
     *
     * The acting moderator is resolved in one extra query for the whole list
     * rather than one per row, and attached as ->modhiddenbyuser (null when
     * the column is 0, which is both "taken down before this was recorded"
     * and the sentinel a privacy erasure leaves behind).
     *
     * @return \stdClass[] collection rows, each carrying ->modhiddenbyuser
     */
    public static function held_collections(): array {
        global $DB;

        $rows = $DB->get_records_select(
            collection_manager::TABLE,
            'modhidden = 1 AND status = :status',
            ['status' => collection_manager::STATUS_ACTIVE],
            'modhiddentime DESC, id DESC'
        );
        if (!$rows) {
            return [];
        }

        $actorids = [];
        foreach ($rows as $row) {
            if (!empty($row->modhiddenby)) {
                $actorids[(int) $row->modhiddenby] = true;
            }
        }
        $actors = $actorids
            ? $DB->get_records_list('user', 'id', array_keys($actorids), '', 'id, firstname, lastname')
            : [];

        foreach ($rows as $row) {
            $row->modhiddenbyuser = $actors[(int) $row->modhiddenby] ?? null;
        }

        return $rows;
    }

    /**
     * Take a collection down, re-deriving what is takedown-able from the
     * database rather than trusting the id that arrived from the client.
     *
     * Refused for a tombstone (nothing to hide) and for a collection already
     * held (so a second click cannot restamp modhiddentime and lose the date
     * the takedown actually happened).
     *
     * @param int $collectionid
     * @param int $actorid the moderator acting
     * @return bool true if the collection was taken down
     */
    public static function take_down(int $collectionid, int $actorid): bool {
        $collection = collection_manager::get($collectionid);
        if (!$collection || collection_manager::is_tombstone($collection) || !empty($collection->modhidden)) {
            return false;
        }

        collection_manager::set_modhidden((int) $collection->id, true, $actorid);

        return true;
    }

    /**
     * Lift a takedown.
     *
     * Shared by moderate.php and moderate_hidden.php so the two cannot
     * disagree about which collections may be restored. Unlike a resource
     * restore there is no "has it anything servable" question to ask: a
     * collection with no items is a legitimate, if empty, collection, and its
     * own page says so.
     *
     * @param int $collectionid
     * @return bool true if the takedown was lifted
     */
    public static function restore(int $collectionid): bool {
        $collection = collection_manager::get($collectionid);
        if (!$collection || collection_manager::is_tombstone($collection) || empty($collection->modhidden)) {
            return false;
        }

        collection_manager::set_modhidden((int) $collection->id, false, 0);

        return true;
    }

    /**
     * One moderation-table cell naming a collection: its title, linked to its
     * page, plus a badge whenever the collection is not simply live.
     *
     * A static render*() method rather than a function in the page, because
     * both moderation pages draw this same cell and a second copy of the
     * escaping decisions is a second place to get them wrong.
     *
     * The title is filtered with format_string() (which escapes), not s(), so
     * a multilang title collapses to one language here exactly as it does on
     * the collection's own page — the regression the resource tables were
     * already fixed for. A null title means the collection row has gone, and
     * reuses the resource tables' '(deleted)' placeholder.
     *
     * The link is offered for a tombstone too: that page renders for
     * everybody and is the honest answer to "what happened to this?".
     *
     * @param string|null $title the collection's stored title
     * @param string|null $slug the collection's slug
     * @param string $status the collection's status column
     * @param bool $modhidden whether a moderator is holding it
     * @param \core\context $context the context to filter the title in
     * @return string HTML
     */
    public static function render_title_cell(
        ?string $title,
        ?string $slug,
        string $status,
        bool $modhidden,
        \core\context $context,
    ): string {
        // Null is a row that has gone; '' is a collection whose owner was
        // erased (privacy\provider::erase_collection() keeps the row so that
        // reports about it still resolve, but empties the title).
        $label = ($title === null || $title === '')
            ? get_string('resourcedeletedplaceholder', 'local_oerexchange')
            : format_string($title, true, ['context' => $context]);

        $url = self::url($slug);
        $cell = $url ? \html_writer::link($url, $label) : $label;

        if ($status === collection_manager::STATUS_DELETED) {
            $cell .= ' ' . \html_writer::tag(
                'span',
                get_string('collectiontombstoneheading', 'local_oerexchange'),
                ['class' => 'badge bg-secondary']
            );
        } else if ($modhidden) {
            $cell .= ' ' . \html_writer::tag(
                'span',
                get_string('collectionmodhidden', 'local_oerexchange'),
                ['class' => 'badge bg-warning text-dark']
            );
        }

        return $cell;
    }

    /**
     * The public URL of a collection, or null when there is no slug to link to.
     *
     * Delegated to collection_controller::collection_url(), which is public
     * static for exactly this reason: a slug-routed path is easy to get subtly
     * wrong (the #[route] attribute is component-relative, so the real request
     * path is '/local_oerexchange/c/{slug}', not '/c/{slug}', and it must be
     * built with moodle_url::routed_path() rather than new moodle_url()), and
     * one more hand-built copy of that rule is one more place to forget it.
     * The null handling is this wrapper's own: the moderation tables can be
     * looking at a report whose collection row has gone, which the controller's
     * non-nullable signature has no reason to model.
     *
     * @param string|null $slug the collection's slug
     * @return \moodle_url|null
     */
    public static function url(?string $slug): ?\moodle_url {
        // An erased owner's collection keeps a 'deleted.<id>' placeholder
        // slug that no route accepts, so it gets no link rather than a dead one.
        if ($slug === null || !slug_helper::is_valid($slug)) {
            return null;
        }

        return \local_oerexchange\route\controller\collection_controller::collection_url($slug);
    }
}
