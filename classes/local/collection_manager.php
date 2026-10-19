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
 * Collections: an ordered, curated reading list of catalogue resources, owned
 * by one user and optionally curated by others.
 *
 * This class is the ONE gate for every collection permission question. That is
 * not a stylistic preference — resource_manager::user_can_edit_resource()
 * carries the scar: a second, differently-guarded copy of one authorisation
 * rule let a moderator's takedown be undone by the author. Collections have a
 * three-tier model (owner / named curator / any logged-in user when the owner
 * has opened curation) crossed with four visibility states, which is far more
 * surface than resources ever had, so a page that invents its own check WILL
 * eventually disagree with this file. Every caller asks one of the
 * user_can_*() methods; none of them re-derives the rule.
 *
 * The permission model, in full:
 *
 * | Action                  | Owner | Curator | Open curation | Moderator |
 * | Add item                | yes   | yes     | yes           | yes       |
 * | Remove item             | any   | any     | only own      | any       |
 * | Reorder / edit notes    | yes   | yes     | no            | no        |
 * | Metadata, cover, slug,  | yes   | no      | no            | takedown  |
 * | visibility, curators,   |       |         |               | only      |
 * | opencuration, delete    |       |         |               |           |
 *
 * "only own" means an item whose addedby is that user: somebody who was let
 * in by an open invitation may undo their own contribution and nobody else's.
 * A moderator's only write is set_modhidden() — a takedown. They cannot edit
 * someone's title, reorder their list or delete it, because a takedown is
 * reversible and an edit is not.
 *
 * Visibility, in full:
 *
 * - draft: the owner alone. Everybody else, moderators included, gets a 404.
 *   A collection that has never been published is not yet a thing the site
 *   has an opinion about, so there is nothing for a moderator to moderate.
 * - unlisted: viewable by anyone holding the link, never listed anywhere, and
 *   the page sends noindex. This is the "share with my department" state.
 * - public: listed in the collections browser and the blended catalogue.
 * - modhidden (orthogonal to the three above): the owner still sees it, with
 *   a notice; everybody else gets a 404; moderators see it and can restore
 *   it. Deliberately a separate column rather than a fourth visibility, for
 *   the same reason resources keep 'modhidden' out of the author's own
 *   hide switch: a takedown the owner could lift by pressing "publish" is
 *   not a takedown.
 * - status = deleted: a tombstone, shown to everyone. See delete().
 *
 * Nothing here escapes anything. Titles, summaries and notes are stored
 * exactly as typed and escaped at the sink by the caller (s(),
 * format_string(), format_text()), which is this plugin's established rule —
 * escaping on the way in would double-escape on the way out and corrupt the
 * stored data for every other consumer.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class collection_manager {
    /** @var string The collections table, without braces. */
    const TABLE = 'local_oerexchange_collections';

    /** @var string The collection-items table, without braces. */
    const ITEM_TABLE = 'local_oerexchange_collection_items';

    /** @var string Visible to its owner only; not published yet. */
    const VISIBILITY_DRAFT = 'draft';

    /** @var string Reachable by its URL, never listed, noindex. */
    const VISIBILITY_UNLISTED = 'unlisted';

    /** @var string Listed in the collections browser and the blended catalogue. */
    const VISIBILITY_PUBLIC = 'public';

    /**
     * Every visibility a collection may hold.
     *
     * Kept as a list so set_visibility() can refuse anything else outright. A
     * typo'd visibility stored in the column would silently read as
     * "not public and not draft", i.e. unlisted-ish, which is the worst of the
     * three to guess wrong.
     *
     * @var string[]
     */
    const VISIBILITIES = [self::VISIBILITY_DRAFT, self::VISIBILITY_UNLISTED, self::VISIBILITY_PUBLIC];

    /** @var string A live collection. */
    const STATUS_ACTIVE = 'active';

    /** @var string A tombstoned collection: the row survives so its URL still resolves. */
    const STATUS_DELETED = 'deleted';

    /** @var string public_collections() sort: most recently published first. */
    const SORT_RECENT = 'recent';

    /** @var string public_collections() sort: most starred first. */
    const SORT_STARRED = 'starred';

    /** @var string public_collections() sort: most resources first. */
    const SORT_ITEMS = 'items';

    /** @var string[] The sorts public_collections() accepts. */
    const SORTS = [self::SORT_RECENT, self::SORT_STARRED, self::SORT_ITEMS];

    /** @var int Longest title accepted, matching the char(255) column. */
    const MAX_TITLE_LENGTH = 255;

    /**
     * Create a collection, as a draft, owned by $userid.
     *
     * New collections are always drafts: publishing is a separate, deliberate
     * act (set_visibility()), so an empty collection with a placeholder title
     * never appears in the browser the moment somebody presses "New".
     *
     * The slug is generated from the title rather than asked for. An author
     * who wants a particular one sets it afterwards through
     * update_metadata(), which is also the only path that reports
     * error_slugtaken — an auto-generated slug must never fail that way,
     * because the person did not choose it and has nothing to correct.
     *
     * @param int $userid the owner
     * @param string $title as typed; stored raw, escaped at the sink
     * @param string $summary as typed
     * @param int $summaryformat FORMAT_* of $summary
     * @return \stdClass the new row
     * @throws \moodle_exception error_collectiontitlerequired
     * @throws \coding_exception for a falsy $userid
     */
    public static function create(
        int $userid,
        string $title,
        string $summary = '',
        int $summaryformat = FORMAT_HTML
    ): \stdClass {
        global $DB;

        if (!$userid) {
            throw new \coding_exception('collection_manager::create() needs a real owner');
        }

        $title = self::clean_title($title);
        $now = time();

        $record = (object) [
            'userid' => $userid,
            'title' => $title,
            'summary' => $summary,
            'summaryformat' => $summaryformat,
            'visibility' => self::VISIBILITY_DRAFT,
            'status' => self::STATUS_ACTIVE,
            'modhidden' => 0,
            'modhiddentime' => 0,
            'modhiddenby' => 0,
            'opencuration' => 0,
            'timepublished' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        // Three attempts, not one: slug_helper::generate_unique() is
        // TOCTOU-prone by design (its docblock says so), and two people
        // creating "Week 1" at the same moment would otherwise hand one of
        // them a database error for a slug they never typed. Each retry
        // re-generates, so the second attempt sees the row that beat it and
        // picks the next suffix.
        $lasterror = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $record->slug = slug_helper::generate_unique(
                self::TABLE,
                slug_helper::slugify($title),
                'id',
                0,
                'collection'
            );
            try {
                $id = $DB->insert_record(self::TABLE, $record);
                return $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
            } catch (\dml_write_exception $e) {
                $lasterror = $e;
            }
        }

        // Three losses in a row is not a race any more, so the DML error is
        // the honest thing to surface rather than a slug-taken message about
        // a slug the person never chose.
        throw $lasterror ?? new \moodle_exception('error_collectionnotfound', 'local_oerexchange');
    }

    /**
     * Fetch a collection by id.
     *
     * Tombstoned collections ARE returned: their page still has to render,
     * and callers decide what to do with status = deleted (see
     * is_tombstone()).
     *
     * @param int $id
     * @return \stdClass|null
     */
    public static function get(int $id): ?\stdClass {
        global $DB;

        if (!$id) {
            return null;
        }

        return $DB->get_record(self::TABLE, ['id' => $id]) ?: null;
    }

    /**
     * Fetch a collection by its URL slug.
     *
     * @param string $slug
     * @return \stdClass|null
     */
    public static function get_by_slug(string $slug): ?\stdClass {
        global $DB;

        if ($slug === '') {
            return null;
        }

        return $DB->get_record(self::TABLE, ['slug' => $slug]) ?: null;
    }

    /**
     * Whether a collection is a tombstone.
     *
     * Exists so page code has an obvious ordering hook: the tombstone is
     * rendered BEFORE any access check, exactly as resource.php does, because
     * a deleted collection is shown to everybody and
     * user_can_view_collection() deliberately answers false for it (see its
     * docblock).
     *
     * @param \stdClass $collection
     * @return bool
     */
    public static function is_tombstone(\stdClass $collection): bool {
        return (string) $collection->status === self::STATUS_DELETED;
    }

    /**
     * Update a collection's editable metadata.
     *
     * Only title, summary, summaryformat and slug may be set here. Visibility,
     * open curation and the takedown columns have their own methods because
     * each has a side effect this one must not silently perform (stamping
     * timepublished, recording who took a collection down). An unrecognised
     * key is a coding error rather than a silent no-op: a caller that thinks
     * it is setting visibility through this method would otherwise never find
     * out.
     *
     * @param int $id
     * @param array $fields any of title, summary, summaryformat, slug
     * @throws \moodle_exception error_invalidslug, error_slugtaken, error_collectiontitlerequired
     * @throws \coding_exception for an unknown field
     */
    public static function update_metadata(int $id, array $fields): void {
        global $DB;

        $collection = self::get($id);
        if (!$collection) {
            throw new \moodle_exception('error_collectionnotfound', 'local_oerexchange');
        }

        $allowed = ['title', 'summary', 'summaryformat', 'slug'];
        $unknown = array_diff(array_keys($fields), $allowed);
        if ($unknown) {
            throw new \coding_exception(
                'collection_manager::update_metadata() cannot set: ' . implode(', ', $unknown)
            );
        }

        $update = (object) ['id' => $collection->id, 'timemodified' => time()];

        if (array_key_exists('title', $fields)) {
            $update->title = self::clean_title((string) $fields['title']);
        }
        if (array_key_exists('summary', $fields)) {
            $update->summary = (string) $fields['summary'];
        }
        if (array_key_exists('summaryformat', $fields)) {
            $update->summaryformat = (int) $fields['summaryformat'];
        }
        if (array_key_exists('slug', $fields)) {
            $slug = trim((string) $fields['slug']);
            // Excluded by the collection's OWN id: re-saving the form without
            // touching the slug must not report the collection's own slug as
            // taken. Raises error_invalidslug or error_slugtaken.
            slug_helper::validate(self::TABLE, $slug, 'id', (int) $collection->id);
            $update->slug = $slug;
        }

        try {
            $DB->update_record(self::TABLE, $update);
        } catch (\dml_write_exception $e) {
            // The unique index on slug is the real arbiter; the check above is
            // only the courtesy that gives the common case a clear message.
            // Same race, same resolution, as profile_manager::save().
            throw slug_helper::taken_exception();
        }
    }

    /**
     * Set a collection's visibility.
     *
     * timepublished is stamped on the FIRST transition to public and never
     * moved again — it is the date the collection browser sorts "recent" by,
     * and a collection that was unpublished for a week and republished has
     * not become newer than one published yesterday. It also stays set when
     * the collection goes back to draft or unlisted: it records that this
     * collection has been public before, which is exactly what the next
     * transition to public needs to know.
     *
     * @param int $id
     * @param string $visibility one of self::VISIBILITIES
     * @throws \coding_exception for an unknown visibility
     * @throws \moodle_exception error_collectionnotfound
     */
    public static function set_visibility(int $id, string $visibility): void {
        global $DB;

        if (!in_array($visibility, self::VISIBILITIES, true)) {
            throw new \coding_exception('Unknown collection visibility: ' . $visibility);
        }

        $collection = self::get($id);
        if (!$collection) {
            throw new \moodle_exception('error_collectionnotfound', 'local_oerexchange');
        }
        if ((string) $collection->visibility === $visibility) {
            return;
        }

        $update = (object) [
            'id' => $collection->id,
            'visibility' => $visibility,
            'timemodified' => time(),
        ];
        if ($visibility === self::VISIBILITY_PUBLIC && !$collection->timepublished) {
            $update->timepublished = time();
        }

        $DB->update_record(self::TABLE, $update);
    }

    /**
     * Open or close curation — whether any logged-in user may add resources.
     *
     * Closing it does NOT remove what open curators already added. Their
     * contributions are the owner's collection now, and silently emptying a
     * list because its owner changed a switch would be a surprising amount of
     * destruction for one checkbox. The owner removes individual items if
     * they want to.
     *
     * @param int $id
     * @param bool $open
     */
    public static function set_open_curation(int $id, bool $open): void {
        global $DB;

        $collection = self::get($id);
        if (!$collection) {
            throw new \moodle_exception('error_collectionnotfound', 'local_oerexchange');
        }

        $DB->update_record(self::TABLE, (object) [
            'id' => $collection->id,
            'opencuration' => $open ? 1 : 0,
            'timemodified' => time(),
        ]);
    }

    /**
     * Take a collection down, or restore it, as a moderator.
     *
     * Deliberately separate from set_visibility(): the owner's own switch and
     * a moderator's takedown must not be the same column, or the owner could
     * lift a takedown by pressing "publish". This is the same split
     * resource_manager keeps between 'hidden' and 'modhidden', made for the
     * same reason.
     *
     * The actor is recorded so the moderation report can say who acted and
     * when. On restore both are cleared — 0 is this plugin's established
     * "no attributable user" sentinel, and a restored collection must not
     * keep naming the moderator who once held it.
     *
     * @param int $id
     * @param bool $hidden true to take down, false to restore
     * @param int $actorid the moderator acting; 0 for an automated removal
     */
    public static function set_modhidden(int $id, bool $hidden, int $actorid): void {
        global $DB;

        $collection = self::get($id);
        if (!$collection) {
            throw new \moodle_exception('error_collectionnotfound', 'local_oerexchange');
        }

        $DB->update_record(self::TABLE, (object) [
            'id' => $collection->id,
            'modhidden' => $hidden ? 1 : 0,
            'modhiddentime' => $hidden ? time() : 0,
            'modhiddenby' => $hidden ? $actorid : 0,
            'timemodified' => time(),
        ]);
    }

    /**
     * Delete a collection: a tombstone, not a DELETE.
     *
     * The row survives with status = deleted so that every link already handed
     * out — a bookmark, a syllabus PDF, a post in somebody's forum — resolves
     * to "this collection no longer exists" instead of a bare 404 that reads
     * as a broken site. This mirrors profile_manager::delete_creator_resource(),
     * which tombstones a resource for the identical reason.
     *
     * What goes, and why:
     * - The item rows. There is nothing left to curate, and leaving them would
     *   keep the unique (collectionid, resourceid) index holding resources
     *   hostage in a collection that no longer exists.
     * - The curator rows. They are personal data about people who are not the
     *   owner whose collection this was — the same judgement
     *   coauthor_manager::delete_for_resource() makes.
     * - Other people's stars, which live in core's {favourite} table and so
     *   are reached by nothing else here; they would dangle against a
     *   tombstone.
     * - The uploaded cover and the generated mosaic. Both are files about
     *   content that is gone.
     *
     * The title is deliberately KEPT. Deletion here is the owner's own act on
     * their own collection (user_can_manage() is owner-only, and a moderator
     * takes down rather than deletes), so the tombstone can honestly say which
     * collection this was. A GDPR erasure is a different path with a different
     * answer, and scrubs it.
     *
     * @param int $id
     */
    public static function delete(int $id): void {
        global $DB;

        $collection = self::get($id);
        if (!$collection) {
            return;
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->delete_records(self::ITEM_TABLE, ['collectionid' => $collection->id]);
            collection_curator_manager::delete_for_collection((int) $collection->id);
            star_manager::delete_for_collection((int) $collection->id);
            // Reports about it, as profile_manager::delete_creator_resource()
            // does for a resource: once the thing is a tombstone there is
            // nothing left for a moderator to act on, and a report left open
            // would sit in the queue against an empty row.
            $DB->delete_records('local_oerexchange_reports', ['collectionid' => $collection->id]);

            // Both fileareas by their published names, so there is one place
            // that knows where a collection's images live.
            get_file_storage()->delete_area_files(
                \context_system::instance()->id,
                'local_oerexchange',
                collection_cover::FILEAREA,
                $collection->id
            );
            collection_cover::invalidate_mosaic((int) $collection->id);

            $DB->update_record(self::TABLE, (object) [
                'id' => $collection->id,
                'status' => self::STATUS_DELETED,
                'visibility' => self::VISIBILITY_DRAFT,
                'opencuration' => 0,
                'timemodified' => time(),
            ]);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Add a resource to a collection, at the end.
     *
     * This authorises nothing — the caller has already asked
     * user_can_add_items() — but it does validate STATE, which is a different
     * question and one no caller can answer by itself: the collection must be
     * live, and the resource must be one the public can actually see. Without
     * that second check, anyone with an open-curation invitation could use a
     * collection as a side channel to surface a resource that is hidden,
     * pending, taken down or tombstoned.
     *
     * @param int $collectionid
     * @param int $resourceid
     * @param int $userid who is adding it, recorded as addedby — it is what
     *                    user_can_remove_item() lets an open curator undo
     * @param string|null $note the curator's note about why this resource is here
     * @return int the new item's id
     * @throws \moodle_exception error_collectionnotfound, error_collectiondeleted,
     *                           error_collectionitemunavailable, error_collectionitemexists
     */
    public static function add_item(int $collectionid, int $resourceid, int $userid, ?string $note = null): int {
        global $DB;

        $collection = self::get($collectionid);
        if (!$collection) {
            throw new \moodle_exception('error_collectionnotfound', 'local_oerexchange');
        }
        if (self::is_tombstone($collection)) {
            throw new \moodle_exception('error_collectiondeleted', 'local_oerexchange');
        }

        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $resourceid], 'id, status, title');
        if (!$resource || (string) $resource->status !== resource_manager::STATUS_PUBLISHED) {
            throw new \moodle_exception('error_collectionitemunavailable', 'local_oerexchange');
        }

        if ($DB->record_exists(self::ITEM_TABLE, ['collectionid' => $collectionid, 'resourceid' => $resourceid])) {
            throw new \moodle_exception('error_collectionitemexists', 'local_oerexchange');
        }

        $max = $DB->get_field_sql(
            'SELECT MAX(sortorder) FROM {' . self::ITEM_TABLE . '} WHERE collectionid = ?',
            [$collectionid]
        );

        try {
            $itemid = $DB->insert_record(self::ITEM_TABLE, (object) [
                'collectionid' => $collectionid,
                'resourceid' => $resourceid,
                'sortorder' => (int) $max + 1,
                'note' => self::clean_note($note),
                'addedby' => $userid,
                'timecreated' => time(),
            ]);
        } catch (\dml_write_exception $e) {
            // The record_exists() check above is TOCTOU-prone — a
            // double-submitted "add to collection" button is the everyday way
            // to lose that race — and the unique (collectionid, resourceid)
            // index is what actually prevents the duplicate. Report it as the
            // same documented condition rather than a raw DML error.
            throw new \moodle_exception('error_collectionitemexists', 'local_oerexchange');
        }

        // The mosaic is built from the first four covers, so any change to
        // membership can change it.
        collection_cover::invalidate_mosaic($collectionid);
        self::touch($collectionid);

        // Open curation lets strangers add to somebody's public list, so the
        // owner hears about every addition they did not make themselves. It
        // is one of the three guards the feature ships with, alongside the
        // owner's remove-anything right and the report button. 0 is an erased
        // owner, who has nobody to tell.
        $ownerid = (int) $collection->userid;
        if ($ownerid > 0 && $ownerid !== $userid) {
            self::notify_item_added($collection, $resource, $userid);
        }

        return (int) $itemid;
    }

    /**
     * Tell a collection's owner that someone else added a resource to it.
     *
     * Plain-text message, so titles are filtered then flattened exactly as
     * collection_curator_manager::notify_added() does it, for the same reason.
     *
     * @param \stdClass $collection the collection row
     * @param \stdClass $resource the added resource (id, title)
     * @param int $addedbyid who added it
     */
    protected static function notify_item_added(\stdClass $collection, \stdClass $resource, int $addedbyid): void {
        $owner = \core_user::get_user((int) $collection->userid);
        $addedby = \core_user::get_user($addedbyid);
        if (!$owner || !$addedby || !empty($owner->deleted)) {
            return;
        }

        $context = \context_system::instance();
        $title = content_to_text(format_string($collection->title, true, ['context' => $context]), FORMAT_HTML);
        $resourcetitle = content_to_text(format_string($resource->title, true, ['context' => $context]), FORMAT_HTML);
        $url = \moodle_url::routed_path('/local_oerexchange/c/' . $collection->slug)->out(false);

        $message = new \core\message\message();
        $message->component = 'local_oerexchange';
        $message->name = 'collectionitem';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $owner;
        $message->subject = get_string('notifycollectionitemsubject', 'local_oerexchange', $title);
        $message->fullmessage = get_string('notifycollectionitembody', 'local_oerexchange', (object) [
            'title' => $title,
            'resource' => $resourcetitle,
            'addedby' => fullname($addedby),
            'url' => $url,
        ]);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $message->subject;
        $message->notification = 1;
        $message->contexturl = $url;
        $message->contexturlname = $title;

        message_send($message);
    }

    /**
     * Remove a resource from a collection.
     *
     * Keyed by (collectionid, resourceid) rather than by item id because that
     * is what the page has: the remove control sits next to a resource. The
     * collection id is part of the key deliberately — it means a posted
     * resource id can only ever affect the collection the caller already
     * authorised against, never somebody else's.
     *
     * @param int $collectionid
     * @param int $resourceid
     */
    public static function remove_item(int $collectionid, int $resourceid): void {
        global $DB;

        $item = $DB->get_record(self::ITEM_TABLE, [
            'collectionid' => $collectionid,
            'resourceid' => $resourceid,
        ]);
        if (!$item) {
            return;
        }

        $DB->delete_records(self::ITEM_TABLE, ['id' => $item->id]);
        self::renumber($collectionid);
        collection_cover::invalidate_mosaic($collectionid);
        self::touch($collectionid);
    }

    /**
     * Set (or clear) the curator's note on one item.
     *
     * @param int $itemid
     * @param string|null $note null or an empty/whitespace string clears it
     */
    public static function set_item_note(int $itemid, ?string $note): void {
        global $DB;

        $item = $DB->get_record(self::ITEM_TABLE, ['id' => $itemid]);
        if (!$item) {
            return;
        }

        $DB->set_field(self::ITEM_TABLE, 'note', self::clean_note($note), ['id' => $item->id]);
        self::touch((int) $item->collectionid);
    }

    /**
     * Move an item one place up or down, renumbering the collection.
     *
     * Renumbering the whole collection on every move rather than swapping two
     * sortorder values keeps the column contiguous and 1-based, which is what
     * makes "is this the first item" and "is this the last item" answerable by
     * the page without a second query. The cost is one UPDATE per item, on a
     * list a human curated by hand.
     *
     * Moving the first item up (or the last one down) is a no-op, not an
     * error: the page draws both arrows on every row, and a disabled arrow
     * that still submits is a better failure than an exception.
     *
     * @param int $itemid
     * @param int $direction negative to move up (earlier), positive to move down
     * @throws \coding_exception for a zero direction, which asks for nothing
     */
    public static function move_item(int $itemid, int $direction): void {
        global $DB;

        if ($direction === 0) {
            throw new \coding_exception('collection_manager::move_item() needs a direction');
        }
        $step = $direction < 0 ? -1 : 1;

        $item = $DB->get_record(self::ITEM_TABLE, ['id' => $itemid]);
        if (!$item) {
            return;
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            // The id is the tie-break so two items sharing a sortorder (only
            // reachable through a legacy row or a half-applied upgrade) still
            // order the same way on every read, rather than at the database's
            // discretion.
            $ids = array_keys($DB->get_records(
                self::ITEM_TABLE,
                ['collectionid' => $item->collectionid],
                'sortorder ASC, id ASC',
                'id'
            ));

            $position = array_search((int) $item->id, array_map('intval', $ids), true);
            $target = $position === false ? -1 : $position + $step;
            if ($target >= 0 && $target < count($ids)) {
                [$ids[$position], $ids[$target]] = [$ids[$target], $ids[$position]];
                self::write_order($ids);
                collection_cover::invalidate_mosaic((int) $item->collectionid);
                self::touch((int) $item->collectionid);
            }

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * A collection's items, in curated order, each with its resource.
     *
     * Two queries, not a single join: the items table and the resources table
     * both have id, status and timecreated columns, so a `SELECT ci.*, r.*`
     * would quietly overwrite half of one row with the other. Aliasing thirty
     * resource columns by hand is worse — the next column added to resources
     * would simply be missing here. So the items come back whole, the
     * resources come back whole in ONE further query keyed by id, and each
     * item carries its resource as a property.
     *
     * Each returned row has:
     * - the item columns (id, collectionid, resourceid, sortorder, note,
     *   addedby, timecreated)
     * - ->resource: the resources row, or null if the row has vanished entirely
     * - ->available: whether the resource is publicly viewable right now
     *
     * A resource that was hidden, taken down or tombstoned AFTER being
     * collected is unavailable, not removed. The owner of the collection did
     * nothing wrong and should be told their item is no longer showing, which
     * is why $includeunavailable exists at all — public pages pass false, the
     * owner's management page passes true.
     *
     * @param int $collectionid
     * @param bool $includeunavailable true to include items whose resource is not publicly viewable
     * @return \stdClass[] keyed by item id, in curated order
     */
    public static function get_items(int $collectionid, bool $includeunavailable = false): array {
        global $DB;

        $items = $DB->get_records(
            self::ITEM_TABLE,
            ['collectionid' => $collectionid],
            'sortorder ASC, id ASC'
        );
        if (!$items) {
            return [];
        }

        $resourceids = array_values(array_unique(array_map(fn($i) => (int) $i->resourceid, $items)));
        [$insql, $inparams] = $DB->get_in_or_equal($resourceids, SQL_PARAMS_NAMED, 'rid');
        $resources = $DB->get_records_select('local_oerexchange_resources', "id $insql", $inparams);

        $rows = [];
        foreach ($items as $item) {
            $resource = $resources[(int) $item->resourceid] ?? null;
            $item->resource = $resource;
            $item->available = $resource
                && (string) $resource->status === resource_manager::STATUS_PUBLISHED;

            if (!$item->available && !$includeunavailable) {
                continue;
            }
            $rows[(int) $item->id] = $item;
        }

        return $rows;
    }

    /**
     * How many resources a collection shows.
     *
     * Counts only items a viewer can actually see, so the "12 resources"
     * caption on a card cannot promise more than the page then draws. The raw
     * total, including items whose resource has since been hidden, is
     * count(get_items($id, true)).
     *
     * @param int $collectionid
     * @return int
     */
    public static function item_count(int $collectionid): int {
        return self::counts_for([$collectionid])[$collectionid] ?? 0;
    }

    /**
     * Visible item counts for many collections at once, in ONE query.
     *
     * The collections browser draws a page of cards, each captioned with its
     * item count. One count query per card is how a browse page becomes
     * twenty queries — the same N+1 cover_image::urls_for() exists to avoid.
     *
     * @param int[] $collectionids
     * @return array<int, int> keyed by collection id; every id asked for is present, 0 if empty
     */
    public static function counts_for(array $collectionids): array {
        global $DB;

        $collectionids = array_values(array_unique(array_map('intval', $collectionids)));
        if (!$collectionids) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($collectionids, SQL_PARAMS_NAMED, 'cid');
        $rows = $DB->get_records_sql(
            "SELECT ci.collectionid, COUNT(1) AS itemcount
               FROM {" . self::ITEM_TABLE . "} ci
               JOIN {local_oerexchange_resources} r ON r.id = ci.resourceid
              WHERE ci.collectionid $insql AND r.status = :status
           GROUP BY ci.collectionid",
            $inparams + ['status' => resource_manager::STATUS_PUBLISHED]
        );

        $counts = array_fill_keys($collectionids, 0);
        foreach ($rows as $row) {
            $counts[(int) $row->collectionid] = (int) $row->itemcount;
        }

        return $counts;
    }

    /**
     * The collections a user owns, and optionally the ones they curate for
     * somebody else.
     *
     * Tombstones are left out: this is the "my collections" list, and a
     * deleted collection is not something to go back to. Drafts and taken-down
     * collections ARE included — this is the only page from which the owner
     * can publish a draft or see that one of theirs is being held.
     *
     * Each row carries ->isowner, because the page has to draw the two groups
     * differently (a curator sees no publish or delete control) and must not
     * work that out by re-deriving ownership itself.
     *
     * @param int $userid
     * @param bool $includecurated whether to include collections owned by others that
     *                             $userid is a named curator of
     * @return \stdClass[] keyed by collection id, most recently modified first
     */
    public static function collections_for_user(int $userid, bool $includecurated = true): array {
        global $DB;

        if (!$userid) {
            return [];
        }

        $owned = $DB->get_records_select(
            self::TABLE,
            'userid = :userid AND status <> :deleted',
            ['userid' => $userid, 'deleted' => self::STATUS_DELETED],
            'timemodified DESC, id DESC'
        );
        foreach ($owned as $collection) {
            $collection->isowner = true;
        }

        if (!$includecurated) {
            return $owned;
        }

        $curatedids = collection_curator_manager::curated_collection_ids($userid);
        // A curator row for one's own collection should not exist
        // (collection_curator_manager::add() refuses it), but filtering here
        // too means a legacy row cannot make a collection appear twice under
        // two different ->isowner values.
        $curatedids = array_diff($curatedids, array_keys($owned));
        if (!$curatedids) {
            return $owned;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($curatedids, SQL_PARAMS_NAMED, 'cid');
        $curated = $DB->get_records_select(
            self::TABLE,
            "id $insql AND status <> :deleted",
            $inparams + ['deleted' => self::STATUS_DELETED]
        );
        foreach ($curated as $collection) {
            $collection->isowner = false;
        }

        $all = $owned + $curated;
        uasort($all, fn($a, $b) => [$b->timemodified, $b->id] <=> [$a->timemodified, $a->id]);

        return $all;
    }

    /**
     * The public collections, for the browse page.
     *
     * Public, active and not taken down — the three conditions that make a
     * collection something this site is willing to show a stranger. Each row
     * carries ->itemcount and ->starcount, because the sorts need them anyway
     * and a page that has them cannot be tempted into a per-card count query.
     *
     * @param string $sort one of self::SORTS
     * @param int $limit 0 for no limit
     * @param int $offset
     * @return \stdClass[] keyed by collection id
     */
    public static function public_collections(string $sort = self::SORT_RECENT, int $limit = 0, int $offset = 0): array {
        global $DB;

        [$sql, $params] = self::public_sql('', [], $sort);

        return $DB->get_records_sql($sql, $params, $offset, $limit);
    }

    /**
     * How many public collections match a free-text query, for paging.
     *
     * @param string $query '' for all of them
     * @return int
     */
    public static function count_public_collections(string $query = ''): int {
        global $DB;

        [$where, $params] = self::search_where($query);

        return (int) $DB->count_records_select(
            self::TABLE,
            self::public_where() . $where,
            $params + self::public_params()
        );
    }

    /**
     * Search the public collections by title and summary.
     *
     * Used by the blended catalogue, which interleaves collections with
     * resources for one search term, so the matching rule is deliberately the
     * plain one the resource search already uses: a case-insensitive
     * substring of the title or the summary. Anything cleverer here (ranking,
     * stemming) would rank collections against resources on an incomparable
     * scale.
     *
     * @param string $query
     * @param int $limit 0 for no limit
     * @param int $offset
     * @return \stdClass[] keyed by collection id
     */
    public static function search_public_collections(string $query, int $limit, int $offset): array {
        global $DB;

        [$where, $params] = self::search_where($query);
        [$sql, $sqlparams] = self::public_sql($where, $params, self::SORT_RECENT);

        return $DB->get_records_sql($sql, $sqlparams, $offset, $limit);
    }

    /**
     * Whether $userid may see this collection at all.
     *
     * This is the 404 gate. Everything else on the page is drawn behind it.
     *
     * A tombstoned collection answers FALSE here even though its page is
     * shown to everybody: the tombstone is rendered before any access check
     * (is_tombstone(), and resource.php's identical ordering), and answering
     * true would also quietly admit a deleted collection to every listing
     * that filters on this method.
     *
     * A draft is the owner's alone — moderators included. There is nothing to
     * moderate about a collection that has never been published, and giving
     * moderators a window onto everybody's unpublished drafts is a privacy
     * cost with no moderation benefit. Named curators of a draft cannot see it
     * either, which is the one place this rule bites: a collection has to be
     * published, or at least unlisted, before the people invited to curate it
     * can do so.
     *
     * @param \stdClass $collection a collections row
     * @param int $userid 0 for a logged-out visitor
     * @return bool
     */
    public static function user_can_view_collection(\stdClass $collection, int $userid): bool {
        if (self::is_tombstone($collection)) {
            return false;
        }

        $isowner = self::is_owner($collection, $userid);

        if ((string) $collection->visibility === self::VISIBILITY_DRAFT) {
            return $isowner;
        }

        if (!empty($collection->modhidden)) {
            // The owner keeps seeing it, with the notice the page draws, so
            // they know what has happened and can reply. Everybody else gets
            // the 404; moderators get it back.
            return $isowner || self::is_moderator($userid);
        }

        // Unlisted and public are both viewable by whoever has the link. The
        // difference between them is whether anything links TO it, which is
        // enforced by the listing queries, not here.
        return true;
    }

    /**
     * Whether $userid may reorder a collection's items and edit their notes.
     *
     * The owner and the people they named, and nobody else. An open-curation
     * invitation is explicitly NOT enough: reordering is editorial control
     * over somebody else's published list, and a note is prose published under
     * the collection's name. "Anyone may add" was never meant to mean "anyone
     * may rewrite".
     *
     * Moderators are excluded too. A moderator's power over a collection is a
     * takedown, which is reversible; silently rewriting the order of a
     * stranger's list is not.
     *
     * @param \stdClass $collection
     * @param int $userid
     * @return bool
     */
    public static function user_can_curate(\stdClass $collection, int $userid): bool {
        if (!$userid || self::is_tombstone($collection)) {
            return false;
        }
        if (self::is_owner($collection, $userid)) {
            return true;
        }

        return collection_curator_manager::is_curator((int) $collection->id, $userid);
    }

    /**
     * Whether $userid may add resources to a collection.
     *
     * The widest of the tiers: the owner, a named curator, any logged-in
     * non-guest user when the owner has opened curation, and a moderator. A
     * guest session is refused explicitly — isloggedin() is true for the guest
     * user, so "logged in" alone would hand every anonymous visitor on a site
     * with guest access the ability to append to open collections.
     *
     * @param \stdClass $collection
     * @param int $userid
     * @return bool
     */
    public static function user_can_add_items(\stdClass $collection, int $userid): bool {
        if (!$userid || isguestuser($userid) || self::is_tombstone($collection)) {
            return false;
        }
        if (self::user_can_curate($collection, $userid)) {
            return true;
        }
        if (!empty($collection->opencuration)) {
            return true;
        }

        return self::is_moderator($userid);
    }

    /**
     * Whether $userid may remove one specific item.
     *
     * The one rule in this file that needs the item as well as the collection:
     * a user who may add under an open-curation invitation may remove what
     * THEY added and nothing else. Letting them remove anything would make
     * "anyone may add" mean "anyone may empty".
     *
     * $item is matched against the collection it was passed with. A caller
     * that loads an item by a posted id and a collection by another posted id
     * could otherwise authorise a removal against the wrong collection
     * entirely — the kind of mismatch "re-derive authorisation from the
     * database" exists to stop.
     *
     * @param \stdClass $c the collection row
     * @param \stdClass $item a collection-items row (needs ->collectionid and ->addedby)
     * @param int $userid
     * @return bool
     */
    public static function user_can_remove_item(\stdClass $c, \stdClass $item, int $userid): bool {
        if (!$userid || self::is_tombstone($c)) {
            return false;
        }
        if ((int) $item->collectionid !== (int) $c->id) {
            return false;
        }
        if (self::user_can_curate($c, $userid) || self::is_moderator($userid)) {
            return true;
        }

        // An open curator, undoing their own contribution. addedby 0 is the
        // "no attributable user" sentinel after a GDPR erasure and must never
        // match, whatever $userid is.
        return !empty($c->opencuration)
            && (int) $item->addedby !== 0
            && (int) $item->addedby === $userid;
    }

    /**
     * Whether $userid may manage the collection itself — its title, summary,
     * cover, slug, visibility, open-curation switch, curator list, and
     * deletion.
     *
     * Owner only. Not curators: they were invited to help choose resources,
     * not to rename or unpublish somebody's collection. Not moderators
     * either — their power is set_modhidden(), a reversible takedown, and
     * routing it through this method would let a moderator edit or delete a
     * collection they merely wanted to hold.
     *
     * @param \stdClass $collection
     * @param int $userid
     * @return bool
     */
    public static function user_can_manage(\stdClass $collection, int $userid): bool {
        if (self::is_tombstone($collection)) {
            return false;
        }

        return self::is_owner($collection, $userid);
    }

    /**
     * Whether $userid owns this collection.
     *
     * $userid must be truthy: a collection whose owner has been through GDPR
     * erasure can hold userid 0, and 0 must never read as "owner" for a
     * logged-out visitor. Same guard, same reason, as
     * resource_manager::user_can_edit_resource().
     *
     * @param \stdClass $collection
     * @param int $userid
     * @return bool
     */
    protected static function is_owner(\stdClass $collection, int $userid): bool {
        return $userid > 0 && (int) $collection->userid === $userid;
    }

    /**
     * Whether $userid holds the moderation capability.
     *
     * Short-circuits on a falsy userid rather than asking core: a logged-out
     * visitor is never a moderator, and has_capability() with a 0 userid is
     * an ambiguous question not worth asking.
     *
     * @param int $userid
     * @return bool
     */
    protected static function is_moderator(int $userid): bool {
        if (!$userid) {
            return false;
        }

        return has_capability('local/oerexchange:moderate', \context_system::instance(), $userid);
    }

    /**
     * The SELECT every public-collections listing shares.
     *
     * The two counts are correlated subqueries rather than joins because the
     * sorts need them in the ORDER BY and a GROUP BY over two different
     * one-to-many relations would multiply the rows. Both MySQL/MariaDB and
     * PostgreSQL order by a select-list alias, which is why the ORDER BY can
     * name itemcount and starcount directly.
     *
     * @param string $extrawhere additional SQL, starting with ' AND ', or ''
     * @param array $extraparams
     * @param string $sort one of self::SORTS
     * @return array [sql, params]
     * @throws \coding_exception for an unknown sort
     */
    protected static function public_sql(string $extrawhere, array $extraparams, string $sort): array {
        if (!in_array($sort, self::SORTS, true)) {
            throw new \coding_exception('Unknown collection sort: ' . $sort);
        }

        $order = match ($sort) {
            self::SORT_STARRED => 'starcount DESC, c.timepublished DESC, c.id DESC',
            self::SORT_ITEMS => 'itemcount DESC, c.timepublished DESC, c.id DESC',
            default => 'c.timepublished DESC, c.id DESC',
        };

        $sql = "SELECT c.*,
                       (SELECT COUNT(1)
                          FROM {" . self::ITEM_TABLE . "} ci
                          JOIN {local_oerexchange_resources} r ON r.id = ci.resourceid
                         WHERE ci.collectionid = c.id AND r.status = :itemstatus) AS itemcount,
                       (SELECT COUNT(1)
                          FROM {favourite} f
                         WHERE f.component = :favcomponent
                               AND f.itemtype = :favitemtype
                               AND f.contextid = :favcontextid
                               AND f.itemid = c.id) AS starcount
                  FROM {" . self::TABLE . "} c
                 WHERE " . self::public_where('c.') . $extrawhere . "
              ORDER BY $order";

        $params = $extraparams + self::public_params() + [
            'itemstatus' => resource_manager::STATUS_PUBLISHED,
            'favcomponent' => star_manager::COMPONENT,
            'favitemtype' => star_manager::ITEMTYPE_COLLECTION,
            'favcontextid' => \context_system::instance()->id,
        ];

        return [$sql, $params];
    }

    /**
     * Whether one collection is publicly listable: the PHP twin of
     * public_where(), for a row already in hand.
     *
     * Starring uses it. Every starred list is public-only (a profile's
     * starred list is shown to visitors, so an unlisted collection must not
     * appear there), so a star on an unlisted collection would be accepted and
     * then never shown anywhere. The star is offered only where it can appear.
     *
     * @param \stdClass $collection
     * @return bool
     */
    public static function is_publicly_listable(\stdClass $collection): bool {
        return (string) $collection->visibility === self::VISIBILITY_PUBLIC
            && (string) $collection->status === self::STATUS_ACTIVE
            && empty($collection->modhidden);
    }

    /**
     * The WHERE clause that defines "a collection a stranger may be shown in
     * a listing", in one place so no listing can drift from the others.
     * is_publicly_listable() is its PHP twin, for a row already in hand.
     *
     * Public rather than protected because star_manager's
     * starred_collections() is a listing too — a profile page's starred list
     * is world-readable, so a collection that has since been unpublished or
     * taken down must not be advertised there by somebody else's bookmark.
     * Having it ask for this clause is what stops that query holding a second,
     * independently maintained copy of the rule.
     *
     * The named parameters it uses are :visibility and :status, supplied by
     * public_params().
     *
     * @param string $prefix table alias plus dot, or '' for an unaliased query
     * @return string
     */
    public static function public_where(string $prefix = ''): string {
        return "{$prefix}visibility = :visibility AND {$prefix}status = :status AND {$prefix}modhidden = 0";
    }

    /**
     * The parameters public_where() needs.
     *
     * @return array
     */
    public static function public_params(): array {
        return [
            'visibility' => self::VISIBILITY_PUBLIC,
            'status' => self::STATUS_ACTIVE,
        ];
    }

    /**
     * The title/summary LIKE fragment for a free-text query.
     *
     * sql_like_escape() on the term: a query containing % or _ must match
     * those characters literally, not act as a wildcard somebody typed by
     * accident.
     *
     * @param string $query
     * @return array [sql fragment starting with ' AND ' or '', params]
     */
    protected static function search_where(string $query): array {
        global $DB;

        $query = trim($query);
        if ($query === '') {
            return ['', []];
        }

        $like = '%' . $DB->sql_like_escape($query) . '%';
        $titlelike = $DB->sql_like('title', ':qtitle', false);
        $summarylike = $DB->sql_like('summary', ':qsummary', false);

        return [" AND ($titlelike OR $summarylike)", ['qtitle' => $like, 'qsummary' => $like]];
    }

    /**
     * Normalise a title, and refuse an empty one.
     *
     * The length cap is enforced here rather than left to the database: MySQL
     * in non-strict mode truncates a too-long value silently, so the author
     * would be shown a title they did not write with no indication why.
     *
     * @param string $title
     * @return string
     * @throws \moodle_exception error_collectiontitlerequired, error_collectiontitletoolong
     */
    protected static function clean_title(string $title): string {
        $title = trim($title);
        if ($title === '') {
            throw new \moodle_exception('error_collectiontitlerequired', 'local_oerexchange');
        }
        if (\core_text::strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new \moodle_exception(
                'error_collectiontitletoolong',
                'local_oerexchange',
                '',
                self::MAX_TITLE_LENGTH
            );
        }

        return $title;
    }

    /**
     * Normalise an item note: whitespace-only becomes null.
     *
     * One representation for "no note", so the page's `if ($item->note)` and
     * the privacy export agree about which items have one.
     *
     * @param string|null $note
     * @return string|null
     */
    protected static function clean_note(?string $note): ?string {
        if ($note === null) {
            return null;
        }
        $note = trim($note);

        return $note === '' ? null : $note;
    }

    /**
     * Rewrite a collection's sortorder column as a contiguous 1..n sequence.
     *
     * @param int $collectionid
     */
    protected static function renumber(int $collectionid): void {
        global $DB;

        $ids = array_keys($DB->get_records(
            self::ITEM_TABLE,
            ['collectionid' => $collectionid],
            'sortorder ASC, id ASC',
            'id'
        ));

        self::write_order($ids);
    }

    /**
     * Write sortorder 1..n for an ordered list of item ids.
     *
     * Only rows whose sortorder actually changes are written: a remove_item()
     * from the end of a long list would otherwise issue one pointless UPDATE
     * per item.
     *
     * @param int[] $ids in the order wanted
     */
    protected static function write_order(array $ids): void {
        global $DB;

        $position = 0;
        foreach ($ids as $id) {
            $position++;
            $current = $DB->get_field(self::ITEM_TABLE, 'sortorder', ['id' => $id]);
            if ((int) $current !== $position) {
                $DB->set_field(self::ITEM_TABLE, 'sortorder', $position, ['id' => $id]);
            }
        }
    }

    /**
     * Mark a collection as modified.
     *
     * Membership, order and notes are the collection's content as much as its
     * title is, so they move timemodified — it is what "my collections" sorts
     * by and what a curator's change should surface. timepublished is
     * deliberately untouched: the browse page's "recent" must not be
     * reshuffled by somebody adding a note.
     *
     * @param int $collectionid
     */
    protected static function touch(int $collectionid): void {
        global $DB;

        $DB->set_field(self::TABLE, 'timemodified', time(), ['id' => $collectionid]);
    }
}
