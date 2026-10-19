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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The state half of collection_manager: creation, slugs, metadata, the
 * publish stamp, the tombstone, item membership and item order.
 *
 * The authorisation half is in collection_permissions_test.php and the
 * visibility matrix in collection_visibility_test.php, so each file can be
 * read as one argument. What is pinned here is the handful of behaviours that
 * are invisible until they are wrong:
 *
 * - timepublished is stamped ONCE. It is what the browse page's "recent" sort
 *   orders by, so a collection that was unpublished for a week and
 *   republished must not jump ahead of one published yesterday.
 * - delete() is a tombstone, not a DELETE. The row, and its title, survive;
 *   the items, curators, stars and images do not. A bookmark handed out
 *   months ago has to resolve to "this is gone" rather than to a 404 that
 *   reads as a broken site.
 * - sortorder stays a contiguous 1..n sequence through every add, remove and
 *   move, because the page decides whether to draw an "up" arrow by comparing
 *   positions rather than by running a second query.
 * - an item whose resource stops being publicly viewable DISAPPEARS from the
 *   public read and is never deleted. The owner collected something that was
 *   fine at the time; losing it silently would be the plugin editing somebody
 *   else's list.
 * - every refusal has its own errorcode. "Already in this collection", "that
 *   resource is not available" and "this collection has been deleted" are
 *   three different corrections for the person reading them, so each negative
 *   test here asserts WHICH one was raised, not merely that something was.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(collection_manager::class)]
final class collection_manager_test extends \advanced_testcase {
    /**
     * This plugin's data generator.
     *
     * @return \local_oerexchange_generator
     */
    protected function generator(): \local_oerexchange_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_oerexchange');
    }

    /**
     * The item rows of a collection read straight from the table, in stored
     * order.
     *
     * Deliberately not get_items(): a test of ordering must not take its
     * reading of the order from the method whose ordering it is checking.
     *
     * @param int $collectionid
     * @return \stdClass[] the raw item rows, ordered by sortorder then id
     */
    protected function stored_items(int $collectionid): array {
        global $DB;

        return array_values($DB->get_records(
            collection_manager::ITEM_TABLE,
            ['collectionid' => $collectionid],
            'sortorder ASC, id ASC'
        ));
    }

    /**
     * The resource ids of a collection's items, in stored order.
     *
     * @param int $collectionid
     * @return int[]
     */
    protected function stored_order(int $collectionid): array {
        return array_map(fn($item) => (int) $item->resourceid, $this->stored_items($collectionid));
    }

    /**
     * The sortorder values of a collection's items, in stored order.
     *
     * @param int $collectionid
     * @return int[]
     */
    protected function stored_sortorders(int $collectionid): array {
        return array_map(fn($item) => (int) $item->sortorder, $this->stored_items($collectionid));
    }

    /**
     * One field of a stored collection, read back from the database.
     *
     * @param int $collectionid
     * @param string $field the column wanted
     * @return mixed
     */
    protected function stored_field(int $collectionid, string $field) {
        global $DB;

        return $DB->get_field(collection_manager::TABLE, $field, ['id' => $collectionid], MUST_EXIST);
    }

    /**
     * Force a collection's timemodified, so a test can prove that a call did
     * or did not touch it.
     *
     * Everything inside one test otherwise lands in the same second, which
     * would let "timemodified moved" pass without anything having moved it.
     *
     * @param int $collectionid
     * @param int $time the value to force
     * @return void
     */
    protected function backdate(int $collectionid, int $time): void {
        global $DB;

        $DB->set_field(collection_manager::TABLE, 'timemodified', $time, ['id' => $collectionid]);
    }

    /**
     * A new collection is a draft, owned by its creator, never published, and
     * addressed by a slug derived from its title.
     *
     * The draft default is the load-bearing part: publishing is a separate,
     * deliberate act, so pressing "New collection" must not put an empty list
     * with a placeholder title straight into the public browser.
     *
     * @return void
     */
    public function test_create_makes_an_unpublished_draft_owned_by_its_creator(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();

        $collection = collection_manager::create((int) $owner->id, 'Week 1', 'Readings', FORMAT_MARKDOWN);

        $this->assertSame((int) $owner->id, (int) $collection->userid);
        $this->assertSame('Week 1', $collection->title);
        $this->assertSame('Readings', $collection->summary);
        $this->assertSame((int) FORMAT_MARKDOWN, (int) $collection->summaryformat);
        $this->assertSame(collection_manager::VISIBILITY_DRAFT, $collection->visibility);
        $this->assertSame(collection_manager::STATUS_ACTIVE, $collection->status);
        $this->assertSame(0, (int) $collection->modhidden);
        $this->assertSame(0, (int) $collection->opencuration);
        $this->assertSame(
            0,
            (int) $collection->timepublished,
            'a brand new collection has never been public'
        );
        $this->assertSame('week-1', $collection->slug);
        $this->assertGreaterThan(0, (int) $collection->timecreated);
        $this->assertGreaterThan(0, (int) $collection->timemodified);
    }

    /**
     * A title is stored exactly as typed.
     *
     * This plugin escapes at the sink (s(), format_string(), format_text()),
     * which only works if nothing escaped on the way in: a title stored as
     * '&amp;' would reach every reader as a literal ampersand-a-m-p and be
     * unrecoverable.
     *
     * @return void
     */
    public function test_create_stores_the_title_raw_for_the_sink_to_escape(): void {
        global $DB;
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $title = '<b>Week 1</b> & "friends"';

        $collection = collection_manager::create((int) $owner->id, $title);

        $this->assertSame($title, $collection->title);
        $this->assertSame(
            $title,
            $DB->get_field(collection_manager::TABLE, 'title', ['id' => $collection->id]),
            'the stored bytes are the typed bytes'
        );
    }

    /**
     * A title that is only whitespace is no title at all, and nothing is
     * written.
     *
     * @return void
     */
    public function test_create_refuses_a_blank_title_and_writes_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();

        try {
            collection_manager::create((int) $owner->id, "   \n\t ");
            $this->fail('a whitespace-only title must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_collectiontitlerequired', $e->errorcode);
            $this->assertSame('local_oerexchange', $e->module);
        }

        $this->assertSame(0, $DB->count_records(collection_manager::TABLE));
    }

    /**
     * A title longer than the char(255) column is refused with its own
     * errorcode, carrying the limit so the message can state it.
     *
     * Enforced in PHP rather than left to the database because MySQL in
     * non-strict mode truncates silently, which would show the author a title
     * they did not write with no indication why.
     *
     * @return void
     */
    public function test_create_refuses_a_title_longer_than_the_column(): void {
        global $DB;
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();

        try {
            collection_manager::create((int) $owner->id, str_repeat('a', collection_manager::MAX_TITLE_LENGTH + 1));
            $this->fail('an over-long title must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_collectiontitletoolong', $e->errorcode);
            $this->assertSame('local_oerexchange', $e->module);
            $this->assertSame(collection_manager::MAX_TITLE_LENGTH, (int) $e->a);
        }

        $this->assertSame(0, $DB->count_records(collection_manager::TABLE));
    }

    /**
     * A title exactly at the limit is accepted: the cap is a maximum, and an
     * off-by-one here refuses a legal title.
     *
     * @return void
     */
    public function test_create_accepts_a_title_exactly_at_the_limit(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $title = str_repeat('a', collection_manager::MAX_TITLE_LENGTH);

        $collection = collection_manager::create((int) $owner->id, $title);

        $this->assertSame($title, $collection->title);
    }

    /**
     * A collection with no real owner is a programming error, not a user
     * error: every right over a collection is derived from its userid, and a
     * row owned by 0 would be owned by every logged-out visitor if the owner
     * check ever lost its truthiness guard.
     *
     * @return void
     */
    public function test_create_refuses_a_falsy_owner(): void {
        $this->resetAfterTest();

        try {
            collection_manager::create(0, 'Week 1');
            $this->fail('a collection cannot be created without an owner');
        } catch (\coding_exception $e) {
            $this->assertSame('codingerror', $e->errorcode);
            $this->assertStringContainsString('needs a real owner', (string) $e->a);
        }
    }

    /**
     * Two collections with the same title get different slugs, suffixed in
     * the order they were created.
     *
     * The slug is the URL. An auto-generated one must never fail with
     * "already taken", because the person did not choose it and has nothing to
     * correct — so the generator suffixes instead of refusing.
     *
     * @return void
     */
    public function test_a_slug_collision_is_suffixed_rather_than_refused(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();

        $first = collection_manager::create((int) $owner->id, 'Week 1');
        $second = collection_manager::create((int) $owner->id, 'Week 1');
        $third = collection_manager::create((int) $owner->id, 'Week 1');

        $this->assertSame('week-1', $first->slug);
        $this->assertSame('week-1-2', $second->slug);
        $this->assertSame('week-1-3', $third->slug);
    }

    /**
     * A title keeps its word boundaries in the slug.
     *
     * 'Open Science: week 1' becomes 'open-science-week-1', not
     * 'opensciencweek1' — a collection title is a sentence, and this is the
     * one place that difference from a username seed is visible.
     *
     * @return void
     */
    public function test_a_title_keeps_its_word_boundaries_in_the_slug(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();

        $collection = collection_manager::create((int) $owner->id, 'Open Science: week 1');

        $this->assertSame('open-science-week-1', $collection->slug);
    }

    /**
     * A title in a non-Latin script is a perfectly reasonable title, and
     * still has to produce a usable URL.
     *
     * Transliteration is deliberately not attempted (it is locale-dependent
     * and differs between servers), so the fallback is a numbered slug — and
     * two such collections must not collide on it.
     *
     * @return void
     */
    public function test_a_title_with_no_slugifiable_characters_falls_back(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();

        $first = collection_manager::create((int) $owner->id, '日本語の教材');
        $second = collection_manager::create((int) $owner->id, '한국어 자료');

        $this->assertSame('collection', $first->slug);
        $this->assertSame('collection-2', $second->slug);
        $this->assertTrue(slug_helper::is_valid($first->slug));
        $this->assertTrue(slug_helper::is_valid($second->slug));
    }

    /**
     * get() and get_by_slug() find the same row, and both answer null rather
     * than throwing for something that is not there.
     *
     * Null matters: the collection page turns it into a 404, and an exception
     * would be a 500 for every mistyped URL.
     *
     * @return void
     */
    public function test_get_and_get_by_slug_find_the_same_row_and_answer_null_otherwise(): void {
        $this->resetAfterTest();
        $collection = $this->generator()->create_collection(['title' => 'Week 1']);

        $this->assertSame((int) $collection->id, (int) collection_manager::get((int) $collection->id)->id);
        $this->assertSame((int) $collection->id, (int) collection_manager::get_by_slug($collection->slug)->id);
        $this->assertNull(collection_manager::get(0));
        $this->assertNull(collection_manager::get((int) $collection->id + 1000));
        $this->assertNull(collection_manager::get_by_slug(''));
        $this->assertNull(collection_manager::get_by_slug('nothing-here'));
    }

    /**
     * A tombstoned collection is still returned by get(): its page has to
     * render the tombstone, so the row must be reachable.
     *
     * @return void
     */
    public function test_get_still_returns_a_tombstoned_collection(): void {
        $this->resetAfterTest();
        $collection = $this->generator()->create_collection([
            'status' => collection_manager::STATUS_DELETED,
        ]);

        $found = collection_manager::get((int) $collection->id);

        $this->assertNotNull($found);
        $this->assertTrue(collection_manager::is_tombstone($found));
        $this->assertFalse(collection_manager::is_tombstone($this->generator()->create_collection()));
    }

    /**
     * update_metadata() writes the four fields it owns and moves
     * timemodified.
     *
     * @return void
     */
    public function test_update_metadata_writes_its_four_fields(): void {
        $this->resetAfterTest();
        $collection = $this->generator()->create_collection(['title' => 'Week 1']);
        $this->backdate((int) $collection->id, 100);

        collection_manager::update_metadata((int) $collection->id, [
            'title' => '  Week one  ',
            'summary' => 'Now with a summary',
            'summaryformat' => FORMAT_MARKDOWN,
            'slug' => 'week-one',
        ]);

        $this->assertSame('Week one', $this->stored_field((int) $collection->id, 'title'));
        $this->assertSame('Now with a summary', $this->stored_field((int) $collection->id, 'summary'));
        $this->assertSame((int) FORMAT_MARKDOWN, (int) $this->stored_field((int) $collection->id, 'summaryformat'));
        $this->assertSame('week-one', $this->stored_field((int) $collection->id, 'slug'));
        $this->assertGreaterThan(100, (int) $this->stored_field((int) $collection->id, 'timemodified'));
    }

    /**
     * A field update_metadata() does not own is a coding error, not a silent
     * no-op.
     *
     * Visibility, open curation and the takedown columns each have their own
     * method because each has a side effect this one must not perform. A
     * caller that thinks it is publishing a collection through here would
     * otherwise never find out.
     *
     * @return void
     */
    public function test_update_metadata_refuses_a_field_it_does_not_own(): void {
        $this->resetAfterTest();
        $collection = $this->generator()->create_collection([
            'visibility' => collection_manager::VISIBILITY_DRAFT,
        ]);

        try {
            collection_manager::update_metadata((int) $collection->id, [
                'title' => 'Week one',
                'visibility' => collection_manager::VISIBILITY_PUBLIC,
            ]);
            $this->fail('update_metadata() must refuse an unknown field');
        } catch (\coding_exception $e) {
            $this->assertSame('codingerror', $e->errorcode);
            $this->assertStringContainsString('cannot set: visibility', (string) $e->a);
        }

        $this->assertSame(
            collection_manager::VISIBILITY_DRAFT,
            $this->stored_field((int) $collection->id, 'visibility')
        );
        $this->assertSame(
            'Reading list',
            $this->stored_field((int) $collection->id, 'title'),
            'a refused call writes none of its fields, not even the legal ones'
        );
    }

    /**
     * A slug with characters the router will not accept is refused with
     * error_invalidslug, and the stored slug is untouched.
     *
     * @return void
     */
    public function test_update_metadata_refuses_an_unusable_slug(): void {
        $this->resetAfterTest();
        $collection = $this->generator()->create_collection(['title' => 'Week 1']);

        try {
            collection_manager::update_metadata((int) $collection->id, ['slug' => 'week 1/../etc']);
            $this->fail('an unusable slug must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_invalidslug', $e->errorcode);
            $this->assertSame('local_oerexchange', $e->module);
        }

        $this->assertSame('week-1', $this->stored_field((int) $collection->id, 'slug'));
    }

    /**
     * A slug another collection already holds is refused with a DIFFERENT
     * errorcode from an unusable one: "those characters are not allowed" and
     * "somebody already has that" need different corrections.
     *
     * @return void
     */
    public function test_update_metadata_refuses_a_slug_another_collection_holds(): void {
        $this->resetAfterTest();
        $taken = $this->generator()->create_collection(['title' => 'Week 1']);
        $mine = $this->generator()->create_collection(['title' => 'Week 2']);

        try {
            collection_manager::update_metadata((int) $mine->id, ['slug' => $taken->slug]);
            $this->fail('a slug another collection holds must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_slugtaken', $e->errorcode);
            $this->assertSame('local_oerexchange', $e->module);
        }

        $this->assertSame('week-2', $this->stored_field((int) $mine->id, 'slug'));
    }

    /**
     * Re-saving the form without touching the slug must not report the
     * collection's own slug as taken.
     *
     * This is why the availability check excludes the collection's own id,
     * and it is the everyday path: the edit form posts the slug on every
     * save.
     *
     * @return void
     */
    public function test_update_metadata_accepts_the_collections_own_slug(): void {
        $this->resetAfterTest();
        $collection = $this->generator()->create_collection(['title' => 'Week 1']);

        collection_manager::update_metadata((int) $collection->id, [
            'title' => 'Week 1 revised',
            'slug' => $collection->slug,
        ]);

        $this->assertSame('week-1', $this->stored_field((int) $collection->id, 'slug'));
        $this->assertSame('Week 1 revised', $this->stored_field((int) $collection->id, 'title'));
    }

    /**
     * Metadata for a collection that does not exist is a user-facing
     * not-found, not a crash: the id came from a URL.
     *
     * @return void
     */
    public function test_update_metadata_on_a_missing_collection_reports_not_found(): void {
        $this->resetAfterTest();

        try {
            collection_manager::update_metadata(12345, ['title' => 'Week 1']);
            $this->fail('a missing collection must be reported');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_collectionnotfound', $e->errorcode);
            $this->assertSame('local_oerexchange', $e->module);
        }
    }

    /**
     * timepublished is stamped on the FIRST transition to public and never
     * moved again.
     *
     * It is what the browse page's "recent" sort orders by. A collection
     * unpublished for a week and republished has not become newer than one
     * published yesterday, and a collection returned to draft keeps the stamp
     * because that is exactly what the next transition needs to know.
     *
     * @return void
     */
    public function test_timepublished_is_stamped_once_and_never_moved(): void {
        global $DB;
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $collection = collection_manager::create((int) $owner->id, 'Week 1');

        collection_manager::set_visibility((int) $collection->id, collection_manager::VISIBILITY_PUBLIC);
        $firststamp = (int) $this->stored_field((int) $collection->id, 'timepublished');
        $this->assertGreaterThan(0, $firststamp);

        // Backdate the stamp so a second stamping would be unmistakable:
        // everything inside one test otherwise happens in the same second.
        $DB->set_field(collection_manager::TABLE, 'timepublished', 100, ['id' => $collection->id]);

        collection_manager::set_visibility((int) $collection->id, collection_manager::VISIBILITY_DRAFT);
        $this->assertSame(
            100,
            (int) $this->stored_field((int) $collection->id, 'timepublished'),
            'returning to draft records that this collection has been public before'
        );

        collection_manager::set_visibility((int) $collection->id, collection_manager::VISIBILITY_UNLISTED);
        $this->assertSame(100, (int) $this->stored_field((int) $collection->id, 'timepublished'));

        collection_manager::set_visibility((int) $collection->id, collection_manager::VISIBILITY_PUBLIC);
        $this->assertSame(
            100,
            (int) $this->stored_field((int) $collection->id, 'timepublished'),
            'republishing must not reshuffle the browse page'
        );
        $this->assertSame(
            collection_manager::VISIBILITY_PUBLIC,
            $this->stored_field((int) $collection->id, 'visibility')
        );
    }

    /**
     * A visibility outside the known three is refused, and nothing is
     * written.
     *
     * A typo'd value stored in the column would read as "not public and not
     * draft" — unlisted-ish — which is the worst of the three to guess
     * wrong.
     *
     * @return void
     */
    public function test_set_visibility_refuses_an_unknown_visibility(): void {
        $this->resetAfterTest();
        $collection = $this->generator()->create_collection([
            'visibility' => collection_manager::VISIBILITY_DRAFT,
        ]);

        try {
            collection_manager::set_visibility((int) $collection->id, 'publicish');
            $this->fail('an unknown visibility must be refused');
        } catch (\coding_exception $e) {
            $this->assertSame('codingerror', $e->errorcode);
            $this->assertStringContainsString('Unknown collection visibility: publicish', (string) $e->a);
        }

        $this->assertSame(
            collection_manager::VISIBILITY_DRAFT,
            $this->stored_field((int) $collection->id, 'visibility')
        );
    }

    /**
     * Publishing a collection that does not exist reports not-found.
     *
     * @return void
     */
    public function test_set_visibility_on_a_missing_collection_reports_not_found(): void {
        $this->resetAfterTest();

        try {
            collection_manager::set_visibility(12345, collection_manager::VISIBILITY_PUBLIC);
            $this->fail('a missing collection must be reported');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_collectionnotfound', $e->errorcode);
            $this->assertSame('local_oerexchange', $e->module);
        }
    }

    /**
     * The open-curation switch toggles, and turning it OFF leaves every item
     * an open contributor already added exactly where it is.
     *
     * Silently emptying a published list because its owner changed a checkbox
     * would be a surprising amount of destruction for one click.
     *
     * @return void
     */
    public function test_closing_open_curation_keeps_the_items_already_added(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $contributor = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection([
            'userid' => (int) $owner->id,
            'opencuration' => 1,
        ]);
        $resource = $this->generator()->create_resource();
        collection_manager::add_item((int) $collection->id, (int) $resource->id, (int) $contributor->id);

        collection_manager::set_open_curation((int) $collection->id, false);

        $this->assertSame(0, (int) $this->stored_field((int) $collection->id, 'opencuration'));
        $this->assertSame([(int) $resource->id], $this->stored_order((int) $collection->id));

        collection_manager::set_open_curation((int) $collection->id, true);
        $this->assertSame(1, (int) $this->stored_field((int) $collection->id, 'opencuration'));
    }

    /**
     * A takedown records who did it and when; a restore clears both.
     *
     * The actor is what lets the moderation report say who acted, and
     * clearing it on restore is what stops a restored collection going on
     * naming the moderator who once held it.
     *
     * @return void
     */
    public function test_set_modhidden_records_the_actor_and_a_restore_clears_it(): void {
        $this->resetAfterTest();
        $moderator = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection([
            'visibility' => collection_manager::VISIBILITY_PUBLIC,
        ]);

        collection_manager::set_modhidden((int) $collection->id, true, (int) $moderator->id);

        $this->assertSame(1, (int) $this->stored_field((int) $collection->id, 'modhidden'));
        $this->assertSame((int) $moderator->id, (int) $this->stored_field((int) $collection->id, 'modhiddenby'));
        $this->assertGreaterThan(0, (int) $this->stored_field((int) $collection->id, 'modhiddentime'));
        $this->assertSame(
            collection_manager::VISIBILITY_PUBLIC,
            $this->stored_field((int) $collection->id, 'visibility'),
            'a takedown is not the owner\'s own visibility switch'
        );

        collection_manager::set_modhidden((int) $collection->id, false, (int) $moderator->id);

        $this->assertSame(0, (int) $this->stored_field((int) $collection->id, 'modhidden'));
        $this->assertSame(0, (int) $this->stored_field((int) $collection->id, 'modhiddenby'));
        $this->assertSame(0, (int) $this->stored_field((int) $collection->id, 'modhiddentime'));
    }

    /**
     * delete() is a tombstone: the row and its title survive, and everything
     * that pointed at content survives nothing.
     *
     * The title is kept on purpose — deletion is the owner's own act on their
     * own collection, so the tombstone can honestly say which collection this
     * was. A GDPR erasure is a different path and scrubs it.
     *
     * @return void
     */
    public function test_delete_tombstones_the_row_and_clears_what_pointed_at_content(): void {
        global $DB;
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $curator = $this->getDataGenerator()->create_user();
        $starrer = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection([
            'userid' => (int) $owner->id,
            'title' => 'Week 1',
            'visibility' => collection_manager::VISIBILITY_PUBLIC,
            'opencuration' => 1,
        ]);
        $resource = $this->generator()->create_resource();
        collection_manager::add_item((int) $collection->id, (int) $resource->id, (int) $owner->id);
        collection_curator_manager::add((int) $collection->id, (int) $curator->id, (int) $owner->id);
        star_manager::set_starred(
            (int) $collection->id,
            (int) $starrer->id,
            true,
            star_manager::ITEMTYPE_COLLECTION
        );
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_oerexchange',
            'filearea' => collection_cover::FILEAREA,
            'itemid' => (int) $collection->id,
            'filepath' => '/',
            'filename' => 'cover.png',
        ], 'not really a png, but it is a file in the area');

        collection_manager::delete((int) $collection->id);

        $this->assertTrue(
            $DB->record_exists(collection_manager::TABLE, ['id' => $collection->id]),
            'a bookmark handed out months ago still has to resolve'
        );
        $this->assertSame(
            collection_manager::STATUS_DELETED,
            $this->stored_field((int) $collection->id, 'status')
        );
        $this->assertSame(
            collection_manager::VISIBILITY_DRAFT,
            $this->stored_field((int) $collection->id, 'visibility')
        );
        $this->assertSame(0, (int) $this->stored_field((int) $collection->id, 'opencuration'));
        $this->assertSame(
            'Week 1',
            $this->stored_field((int) $collection->id, 'title'),
            'the tombstone says which collection this was'
        );
        $this->assertSame(
            0,
            $DB->count_records(collection_manager::ITEM_TABLE, ['collectionid' => $collection->id])
        );
        $this->assertSame([], collection_curator_manager::get_curators((int) $collection->id));
        $this->assertSame(
            0,
            star_manager::star_count((int) $collection->id, star_manager::ITEMTYPE_COLLECTION),
            'other people\'s stars live in a core table and nothing else would reach them'
        );
        $this->assertSame(
            [],
            get_file_storage()->get_area_files(
                \context_system::instance()->id,
                'local_oerexchange',
                collection_cover::FILEAREA,
                (int) $collection->id,
                'id',
                false
            )
        );
        $this->assertTrue(
            $DB->record_exists('local_oerexchange_resources', ['id' => $resource->id]),
            'deleting a collection must not delete the resources it pointed at'
        );
    }

    /**
     * Deleting something that is not there is a no-op, not an exception: the
     * id came from a URL, and a double-submitted confirm form is the
     * everyday way to arrive here twice.
     *
     * @return void
     */
    public function test_delete_is_a_no_op_for_a_missing_collection(): void {
        $this->resetAfterTest();

        collection_manager::delete(12345);

        $this->assertTrue(true, 'deleting a missing collection raised nothing');
    }

    /**
     * Items are appended in the order they are added, numbered from 1, and
     * each records who added it.
     *
     * addedby is load-bearing rather than mere provenance: under open
     * curation it is what decides whose items a non-curator may remove.
     *
     * @return void
     */
    public function test_add_item_appends_in_order_and_records_who_added_it(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $contributor = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id]);
        $first = $this->generator()->create_resource(['title' => 'First']);
        $second = $this->generator()->create_resource(['title' => 'Second']);

        $firstitem = collection_manager::add_item(
            (int) $collection->id,
            (int) $first->id,
            (int) $owner->id,
            '  Start here  '
        );
        $seconditem = collection_manager::add_item(
            (int) $collection->id,
            (int) $second->id,
            (int) $contributor->id
        );

        $this->assertGreaterThan(0, $firstitem);
        $this->assertNotSame($firstitem, $seconditem);
        $this->assertSame([(int) $first->id, (int) $second->id], $this->stored_order((int) $collection->id));
        $this->assertSame([1, 2], $this->stored_sortorders((int) $collection->id));

        $items = $this->stored_items((int) $collection->id);
        $this->assertSame('Start here', $items[0]->note, 'a note is trimmed, not escaped');
        $this->assertSame((int) $owner->id, (int) $items[0]->addedby);
        $this->assertNull($items[1]->note);
        $this->assertSame((int) $contributor->id, (int) $items[1]->addedby);
    }

    /**
     * A note that is only whitespace becomes null, not ''.
     *
     * One representation for "no note", so the page's `if ($item->note)` and
     * the privacy export agree about which items have one.
     *
     * @return void
     */
    public function test_a_whitespace_only_note_is_stored_as_null(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id]);
        $resource = $this->generator()->create_resource();

        collection_manager::add_item((int) $collection->id, (int) $resource->id, (int) $owner->id, "  \n ");

        $this->assertNull($this->stored_items((int) $collection->id)[0]->note);
    }

    /**
     * The same resource cannot be added twice, and the refusal leaves exactly
     * one row.
     *
     * A double-submitted "add to collection" button is the everyday way to
     * get here, so the duplicate must be reported as its own documented
     * condition rather than as a raw DML error.
     *
     * @return void
     */
    public function test_add_item_refuses_a_duplicate_resource(): void {
        global $DB;
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id]);
        $resource = $this->generator()->create_resource();
        collection_manager::add_item((int) $collection->id, (int) $resource->id, (int) $owner->id);

        try {
            collection_manager::add_item((int) $collection->id, (int) $resource->id, (int) $owner->id);
            $this->fail('a resource appears at most once in a collection');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_collectionitemexists', $e->errorcode);
            $this->assertSame('local_oerexchange', $e->module);
        }

        $this->assertSame(
            1,
            $DB->count_records(collection_manager::ITEM_TABLE, [
                'collectionid' => $collection->id,
                'resourceid' => $resource->id,
            ])
        );
    }

    /**
     * The same resource CAN be in two different collections: the uniqueness
     * is per collection, not global.
     *
     * @return void
     */
    public function test_the_same_resource_may_be_in_two_collections(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $first = $this->generator()->create_collection(['userid' => (int) $owner->id, 'title' => 'Week 1']);
        $second = $this->generator()->create_collection(['userid' => (int) $owner->id, 'title' => 'Week 2']);
        $resource = $this->generator()->create_resource();

        collection_manager::add_item((int) $first->id, (int) $resource->id, (int) $owner->id);
        collection_manager::add_item((int) $second->id, (int) $resource->id, (int) $owner->id);

        $this->assertSame([(int) $resource->id], $this->stored_order((int) $first->id));
        $this->assertSame([(int) $resource->id], $this->stored_order((int) $second->id));
    }

    /**
     * A resource the public cannot see cannot be collected, whatever made it
     * invisible.
     *
     * Without this a collection would be a side channel: anyone holding an
     * open-curation invitation could surface a resource that is pending,
     * author-hidden, taken down, removed or tombstoned simply by adding it to
     * a public list.
     *
     * @return void
     */
    public function test_add_item_refuses_every_unavailable_resource_status(): void {
        global $DB;
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id]);

        foreach (['pending', 'hidden', 'removed', 'modhidden', 'deleted'] as $status) {
            $resource = $this->generator()->create_resource(['status' => $status]);

            try {
                collection_manager::add_item((int) $collection->id, (int) $resource->id, (int) $owner->id);
                $this->fail("a '$status' resource must not be collectable");
            } catch (\moodle_exception $e) {
                $this->assertSame('error_collectionitemunavailable', $e->errorcode, $status);
                $this->assertSame('local_oerexchange', $e->module, $status);
            }
        }

        $this->assertSame(
            0,
            $DB->count_records(collection_manager::ITEM_TABLE, ['collectionid' => $collection->id])
        );
    }

    /**
     * A resource id that matches nothing is reported as unavailable rather
     * than crashing on a null row.
     *
     * @return void
     */
    public function test_add_item_refuses_a_resource_that_does_not_exist(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id]);

        try {
            collection_manager::add_item((int) $collection->id, 999999, (int) $owner->id);
            $this->fail('a resource that does not exist must not be collectable');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_collectionitemunavailable', $e->errorcode);
            $this->assertSame('local_oerexchange', $e->module);
        }
    }

    /**
     * A tombstoned collection reports its own distinct condition — "this
     * collection has been deleted" is a different thing to tell somebody
     * than "that collection does not exist".
     *
     * @return void
     */
    public function test_add_item_distinguishes_a_tombstone_from_a_missing_collection(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $tombstone = $this->generator()->create_collection([
            'userid' => (int) $owner->id,
            'status' => collection_manager::STATUS_DELETED,
        ]);
        $resource = $this->generator()->create_resource();

        try {
            collection_manager::add_item((int) $tombstone->id, (int) $resource->id, (int) $owner->id);
            $this->fail('nothing can be added to a tombstone');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_collectiondeleted', $e->errorcode);
            $this->assertSame('local_oerexchange', $e->module);
        }

        try {
            collection_manager::add_item(999999, (int) $resource->id, (int) $owner->id);
            $this->fail('a missing collection must be reported');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_collectionnotfound', $e->errorcode);
            $this->assertSame('local_oerexchange', $e->module);
        }
    }

    /**
     * Removing an item renumbers the rest as a contiguous 1..n sequence,
     * keeping their relative order.
     *
     * Contiguity is what lets the page answer "is this the first item" by
     * comparing numbers instead of running a second query.
     *
     * @return void
     */
    public function test_remove_item_renumbers_the_remaining_items(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id]);
        $resources = [];
        foreach (['A', 'B', 'C', 'D'] as $label) {
            $resources[$label] = $this->generator()->create_resource(['title' => $label]);
            collection_manager::add_item((int) $collection->id, (int) $resources[$label]->id, (int) $owner->id);
        }

        collection_manager::remove_item((int) $collection->id, (int) $resources['B']->id);

        $this->assertSame(
            [(int) $resources['A']->id, (int) $resources['C']->id, (int) $resources['D']->id],
            $this->stored_order((int) $collection->id)
        );
        $this->assertSame([1, 2, 3], $this->stored_sortorders((int) $collection->id));
    }

    /**
     * remove_item() is keyed on the collection as well as the resource, so a
     * posted resource id can only ever affect the collection the caller
     * already authorised against.
     *
     * @return void
     */
    public function test_remove_item_cannot_reach_into_another_collection(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $mine = $this->generator()->create_collection(['userid' => (int) $owner->id, 'title' => 'Mine']);
        $theirs = $this->generator()->create_collection(['title' => 'Theirs']);
        $resource = $this->generator()->create_resource();
        collection_manager::add_item((int) $mine->id, (int) $resource->id, (int) $owner->id);
        collection_manager::add_item((int) $theirs->id, (int) $resource->id, (int) $owner->id);

        collection_manager::remove_item((int) $mine->id, (int) $resource->id);

        $this->assertSame([], $this->stored_order((int) $mine->id));
        $this->assertSame(
            [(int) $resource->id],
            $this->stored_order((int) $theirs->id),
            'the other collection keeps its copy'
        );
    }

    /**
     * Removing something that is not in the collection changes nothing and
     * raises nothing.
     *
     * @return void
     */
    public function test_remove_item_is_a_no_op_for_an_item_that_is_not_there(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id]);
        $kept = $this->generator()->create_resource(['title' => 'Kept']);
        $never = $this->generator()->create_resource(['title' => 'Never added']);
        collection_manager::add_item((int) $collection->id, (int) $kept->id, (int) $owner->id);
        $this->backdate((int) $collection->id, 100);

        collection_manager::remove_item((int) $collection->id, (int) $never->id);

        $this->assertSame([(int) $kept->id], $this->stored_order((int) $collection->id));
        $this->assertSame(
            100,
            (int) $this->stored_field((int) $collection->id, 'timemodified'),
            'a no-op must not report the collection as changed'
        );
    }

    /**
     * A note can be set, replaced and cleared, and setting one moves the
     * collection's timemodified — a note is the collection's content as much
     * as its title is.
     *
     * @return void
     */
    public function test_set_item_note_sets_replaces_and_clears(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id]);
        $resource = $this->generator()->create_resource();
        $itemid = collection_manager::add_item((int) $collection->id, (int) $resource->id, (int) $owner->id);
        $this->backdate((int) $collection->id, 100);

        collection_manager::set_item_note($itemid, '  Read this first  ');
        $this->assertSame('Read this first', $this->stored_items((int) $collection->id)[0]->note);
        $this->assertGreaterThan(100, (int) $this->stored_field((int) $collection->id, 'timemodified'));

        collection_manager::set_item_note($itemid, 'Read this second');
        $this->assertSame('Read this second', $this->stored_items((int) $collection->id)[0]->note);

        collection_manager::set_item_note($itemid, null);
        $this->assertNull($this->stored_items((int) $collection->id)[0]->note);

        collection_manager::set_item_note($itemid, '   ');
        $this->assertNull($this->stored_items((int) $collection->id)[0]->note);

        collection_manager::set_item_note(999999, 'nothing to set');
        $this->assertNull($this->stored_items((int) $collection->id)[0]->note);
    }

    /**
     * Moving an item up and down rewrites the order and keeps sortorder
     * contiguous.
     *
     * @return void
     */
    public function test_move_item_reorders_and_keeps_sortorder_contiguous(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id]);
        $items = [];
        $resources = [];
        foreach (['A', 'B', 'C'] as $label) {
            $resources[$label] = $this->generator()->create_resource(['title' => $label]);
            $items[$label] = collection_manager::add_item(
                (int) $collection->id,
                (int) $resources[$label]->id,
                (int) $owner->id
            );
        }

        collection_manager::move_item($items['C'], -1);

        $this->assertSame(
            [(int) $resources['A']->id, (int) $resources['C']->id, (int) $resources['B']->id],
            $this->stored_order((int) $collection->id)
        );
        $this->assertSame([1, 2, 3], $this->stored_sortorders((int) $collection->id));

        collection_manager::move_item($items['A'], 1);

        $this->assertSame(
            [(int) $resources['C']->id, (int) $resources['A']->id, (int) $resources['B']->id],
            $this->stored_order((int) $collection->id)
        );
        $this->assertSame([1, 2, 3], $this->stored_sortorders((int) $collection->id));
    }

    /**
     * Any negative direction is "up" and any positive one is "down": the page
     * posts -1 and +1, but a caller passing -5 asked to move up, not to move
     * five places.
     *
     * @return void
     */
    public function test_move_item_treats_the_direction_as_a_sign_not_a_distance(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id]);
        $items = [];
        $resources = [];
        foreach (['A', 'B', 'C'] as $label) {
            $resources[$label] = $this->generator()->create_resource(['title' => $label]);
            $items[$label] = collection_manager::add_item(
                (int) $collection->id,
                (int) $resources[$label]->id,
                (int) $owner->id
            );
        }

        collection_manager::move_item($items['C'], -5);

        $this->assertSame(
            [(int) $resources['A']->id, (int) $resources['C']->id, (int) $resources['B']->id],
            $this->stored_order((int) $collection->id)
        );
    }

    /**
     * Moving the first item up, or the last one down, is a no-op rather than
     * an error — and it does not report the collection as changed either.
     *
     * The page draws both arrows on every row, so a disabled arrow that still
     * submits is a better failure than an exception.
     *
     * @return void
     */
    public function test_moving_past_either_end_is_a_silent_no_op(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id]);
        $items = [];
        $resources = [];
        foreach (['A', 'B'] as $label) {
            $resources[$label] = $this->generator()->create_resource(['title' => $label]);
            $items[$label] = collection_manager::add_item(
                (int) $collection->id,
                (int) $resources[$label]->id,
                (int) $owner->id
            );
        }
        $this->backdate((int) $collection->id, 100);

        collection_manager::move_item($items['A'], -1);
        collection_manager::move_item($items['B'], 1);

        $this->assertSame(
            [(int) $resources['A']->id, (int) $resources['B']->id],
            $this->stored_order((int) $collection->id)
        );
        $this->assertSame(
            100,
            (int) $this->stored_field((int) $collection->id, 'timemodified'),
            'nothing moved, so nothing changed'
        );
    }

    /**
     * A zero direction asks for nothing, which is a caller bug rather than a
     * no-op: silently ignoring it would hide a page that forgot to send the
     * direction at all.
     *
     * @return void
     */
    public function test_move_item_refuses_a_zero_direction(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id]);
        $resource = $this->generator()->create_resource();
        $itemid = collection_manager::add_item((int) $collection->id, (int) $resource->id, (int) $owner->id);

        try {
            collection_manager::move_item($itemid, 0);
            $this->fail('a zero direction must be refused');
        } catch (\coding_exception $e) {
            $this->assertSame('codingerror', $e->errorcode);
            $this->assertStringContainsString('needs a direction', (string) $e->a);
        }
    }

    /**
     * Moving an item that is not there raises nothing.
     *
     * @return void
     */
    public function test_move_item_is_a_no_op_for_a_missing_item(): void {
        $this->resetAfterTest();

        collection_manager::move_item(999999, -1);

        $this->assertTrue(true, 'moving a missing item raised nothing');
    }

    /**
     * An item whose resource stops being publicly viewable vanishes from the
     * public read, stays in the owner's read with ->available false, and is
     * NEVER deleted.
     *
     * The owner collected something that was fine at the time. Dropping the
     * row would be the plugin editing somebody else's list; showing it to the
     * public would advertise a resource its author has hidden.
     *
     * @return void
     */
    public function test_a_dead_item_is_hidden_from_the_public_flagged_for_the_owner_and_never_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id]);
        $live = $this->generator()->create_resource(['title' => 'Still published']);
        $dying = $this->generator()->create_resource(['title' => 'Later hidden']);
        collection_manager::add_item((int) $collection->id, (int) $live->id, (int) $owner->id);
        $dyingitem = collection_manager::add_item((int) $collection->id, (int) $dying->id, (int) $owner->id);

        // The author hides their resource AFTER it was collected.
        $DB->set_field('local_oerexchange_resources', 'status', 'hidden', ['id' => $dying->id]);

        $public = collection_manager::get_items((int) $collection->id);
        // Keyed by item id, so compared as a list.
        $this->assertSame([(int) $live->id], array_values(array_map(fn($i) => (int) $i->resourceid, $public)));

        $full = collection_manager::get_items((int) $collection->id, true);
        $this->assertCount(2, $full);
        $this->assertArrayHasKey($dyingitem, $full);
        $this->assertFalse($full[$dyingitem]->available);
        $this->assertSame((int) $dying->id, (int) $full[$dyingitem]->resource->id);
        $this->assertTrue(reset($full)->available, 'the live item is still available');

        $this->assertTrue(
            $DB->record_exists(collection_manager::ITEM_TABLE, ['id' => $dyingitem]),
            'an item is never auto-deleted because its resource changed state'
        );
    }

    /**
     * An item whose resource row has vanished entirely is flagged, not
     * fatal: ->resource is null and ->available is false.
     *
     * @return void
     */
    public function test_an_item_whose_resource_row_vanished_is_flagged_not_fatal(): void {
        global $DB;
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id]);
        $resource = $this->generator()->create_resource();
        $itemid = collection_manager::add_item((int) $collection->id, (int) $resource->id, (int) $owner->id);

        $DB->delete_records('local_oerexchange_resources', ['id' => $resource->id]);

        $this->assertSame([], collection_manager::get_items((int) $collection->id));

        $full = collection_manager::get_items((int) $collection->id, true);
        $this->assertCount(1, $full);
        $this->assertNull($full[$itemid]->resource);
        $this->assertFalse($full[$itemid]->available);
    }

    /**
     * get_items() returns rows in curated order, keyed by item id, each
     * carrying its whole resource row.
     *
     * The two-query shape matters: items and resources both have id, status
     * and timecreated columns, so a single `SELECT ci.*, r.*` would silently
     * overwrite half of one row with the other. This asserts both rows are
     * intact.
     *
     * @return void
     */
    public function test_get_items_returns_whole_rows_in_curated_order(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id]);
        $first = $this->generator()->create_resource(['title' => 'First', 'type' => 'activity']);
        $second = $this->generator()->create_resource(['title' => 'Second']);
        $firstitem = collection_manager::add_item(
            (int) $collection->id,
            (int) $first->id,
            (int) $owner->id,
            'Why this one'
        );
        $seconditem = collection_manager::add_item((int) $collection->id, (int) $second->id, (int) $owner->id);
        collection_manager::move_item($seconditem, -1);

        $items = collection_manager::get_items((int) $collection->id);

        $this->assertSame([$seconditem, $firstitem], array_keys($items));
        $this->assertSame('Second', $items[$seconditem]->resource->title);
        $this->assertSame('First', $items[$firstitem]->resource->title);
        $this->assertSame('activity', $items[$firstitem]->resource->type);
        $this->assertSame('Why this one', $items[$firstitem]->note, 'the item row survived the join');
        $this->assertSame((int) $collection->id, (int) $items[$firstitem]->collectionid);
        $this->assertSame($firstitem, (int) $items[$firstitem]->id, 'the item id, not the resource id');
    }

    /**
     * An empty collection reads as an empty array, not as a query error.
     *
     * @return void
     */
    public function test_get_items_on_an_empty_collection_is_an_empty_array(): void {
        $this->resetAfterTest();
        $collection = $this->generator()->create_collection();

        $this->assertSame([], collection_manager::get_items((int) $collection->id));
        $this->assertSame([], collection_manager::get_items((int) $collection->id, true));
        $this->assertSame(0, collection_manager::item_count((int) $collection->id));
    }

    /**
     * item_count() counts only what a viewer can actually see, so the "12
     * resources" caption on a card cannot promise more than the page draws.
     *
     * @return void
     */
    public function test_item_count_counts_only_visible_items(): void {
        global $DB;
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id]);
        $live = $this->generator()->create_resource(['title' => 'Live']);
        $dying = $this->generator()->create_resource(['title' => 'Dying']);
        collection_manager::add_item((int) $collection->id, (int) $live->id, (int) $owner->id);
        collection_manager::add_item((int) $collection->id, (int) $dying->id, (int) $owner->id);
        $this->assertSame(2, collection_manager::item_count((int) $collection->id));

        $DB->set_field('local_oerexchange_resources', 'status', 'modhidden', ['id' => $dying->id]);

        $this->assertSame(1, collection_manager::item_count((int) $collection->id));
        $this->assertCount(
            2,
            collection_manager::get_items((int) $collection->id, true),
            'the raw total is still reachable'
        );
    }

    /**
     * counts_for() answers for every id asked about — zero included — and
     * does it in ONE query.
     *
     * The one-query property is the reason the method exists: a browse page
     * drawing twenty captioned cards would otherwise be twenty count
     * queries. The read count is calibrated against a known single query
     * first, because what one logical query costs in reads depends on the
     * driver.
     *
     * @return void
     */
    public function test_counts_for_answers_every_id_in_one_query(): void {
        global $DB;
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $full = $this->generator()->create_collection(['userid' => (int) $owner->id, 'title' => 'Full']);
        $empty = $this->generator()->create_collection(['userid' => (int) $owner->id, 'title' => 'Empty']);
        foreach (['A', 'B'] as $label) {
            $resource = $this->generator()->create_resource(['title' => $label]);
            collection_manager::add_item((int) $full->id, (int) $resource->id, (int) $owner->id);
        }
        $ids = [(int) $full->id, (int) $empty->id, 999999];

        $start = $DB->perf_get_reads();
        $DB->count_records(collection_manager::TABLE);
        $readsperquery = $DB->perf_get_reads() - $start;
        $this->assertGreaterThan(0, $readsperquery, 'the driver counts reads, so the calibration is usable');

        $start = $DB->perf_get_reads();
        $counts = collection_manager::counts_for($ids);
        $used = $DB->perf_get_reads() - $start;

        $this->assertSame([(int) $full->id => 2, (int) $empty->id => 0, 999999 => 0], $counts);
        $this->assertSame($readsperquery, $used, 'counts_for() is one query however many ids it is given');
        $this->assertSame([], collection_manager::counts_for([]));
    }

    /**
     * The "my collections" list holds what the user owns plus what they
     * curate, flags which is which, and leaves tombstones out.
     *
     * ->isowner is supplied rather than left to the page to re-derive: the
     * page draws the two groups differently (a curator sees no publish or
     * delete control) and must not work ownership out for itself.
     *
     * @return void
     */
    public function test_collections_for_user_separates_owned_from_curated(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $curator = $this->getDataGenerator()->create_user();
        $owned = $this->generator()->create_collection(['userid' => (int) $owner->id, 'title' => 'Mine']);
        $gone = $this->generator()->create_collection([
            'userid' => (int) $owner->id,
            'title' => 'Deleted',
            'status' => collection_manager::STATUS_DELETED,
        ]);
        $theirs = $this->generator()->create_collection(['userid' => (int) $owner->id, 'title' => 'Curated']);
        collection_curator_manager::add((int) $theirs->id, (int) $curator->id, (int) $owner->id);
        // Both were created in the same second, so the order would otherwise
        // fall to the id tie-break and say nothing about the sort.
        $this->backdate((int) $owned->id, 200);
        $this->backdate((int) $theirs->id, 100);

        $ownerlist = collection_manager::collections_for_user((int) $owner->id);
        $this->assertSame(
            [(int) $owned->id, (int) $theirs->id],
            array_values(array_map(fn($c) => (int) $c->id, $ownerlist)),
            'order is most recently modified first, and the tombstone is not in the list'
        );
        $this->assertArrayNotHasKey((int) $gone->id, $ownerlist);
        foreach ($ownerlist as $collection) {
            $this->assertTrue($collection->isowner);
        }

        $curatorlist = collection_manager::collections_for_user((int) $curator->id);
        $this->assertSame([(int) $theirs->id], array_keys($curatorlist));
        $this->assertFalse($curatorlist[(int) $theirs->id]->isowner);

        $this->assertSame(
            [],
            collection_manager::collections_for_user((int) $curator->id, false),
            'a curator owns nothing, so excluding curated collections empties the list'
        );
        $this->assertSame([], collection_manager::collections_for_user(0));
    }

    /**
     * Drafts and taken-down collections stay in the owner's own list: it is
     * the only page from which they can publish a draft, or see that one of
     * theirs is being held.
     *
     * @return void
     */
    public function test_collections_for_user_keeps_drafts_and_held_collections(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $draft = $this->generator()->create_collection([
            'userid' => (int) $owner->id,
            'title' => 'Draft',
            'visibility' => collection_manager::VISIBILITY_DRAFT,
        ]);
        $held = $this->generator()->create_collection([
            'userid' => (int) $owner->id,
            'title' => 'Held',
            'modhidden' => 1,
            'modhiddentime' => time(),
        ]);

        $list = collection_manager::collections_for_user((int) $owner->id);

        $this->assertArrayHasKey((int) $draft->id, $list);
        $this->assertArrayHasKey((int) $held->id, $list);
    }

    /**
     * The owner's list is ordered most recently modified first.
     *
     * @return void
     */
    public function test_collections_for_user_is_ordered_by_last_change(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $old = $this->generator()->create_collection(['userid' => (int) $owner->id, 'title' => 'Old']);
        $new = $this->generator()->create_collection(['userid' => (int) $owner->id, 'title' => 'New']);
        $this->backdate((int) $old->id, 100);
        $this->backdate((int) $new->id, 200);

        $this->assertSame(
            [(int) $new->id, (int) $old->id],
            array_keys(collection_manager::collections_for_user((int) $owner->id))
        );
    }

    /**
     * The browse page shows public, active, not-taken-down collections and
     * nothing else.
     *
     * All four exclusions are asserted together because the WHERE clause is
     * shared by every public listing, including the one behind somebody
     * else's starred list — a single missing condition leaks everywhere at
     * once.
     *
     * @return void
     */
    public function test_public_collections_lists_only_what_a_stranger_may_see(): void {
        $this->resetAfterTest();
        $listed = $this->generator()->create_collection([
            'title' => 'Listed',
            'visibility' => collection_manager::VISIBILITY_PUBLIC,
        ]);
        $this->generator()->create_collection([
            'title' => 'Draft',
            'visibility' => collection_manager::VISIBILITY_DRAFT,
        ]);
        $this->generator()->create_collection([
            'title' => 'Unlisted',
            'visibility' => collection_manager::VISIBILITY_UNLISTED,
        ]);
        // The next two are PUBLIC, so the only thing excluding them is the
        // condition under test rather than their visibility as well.
        $this->generator()->create_collection([
            'title' => 'Held',
            'visibility' => collection_manager::VISIBILITY_PUBLIC,
            'modhidden' => 1,
            'modhiddentime' => time(),
        ]);
        $this->generator()->create_collection([
            'title' => 'Deleted',
            'visibility' => collection_manager::VISIBILITY_PUBLIC,
            'status' => collection_manager::STATUS_DELETED,
        ]);

        $this->assertSame([(int) $listed->id], array_keys(collection_manager::public_collections()));
        $this->assertSame(1, collection_manager::count_public_collections());
    }

    /**
     * Every row the browse page gets carries its own item and star counts, so
     * the page cannot be tempted into a per-card count query.
     *
     * @return void
     */
    public function test_public_collections_rows_carry_their_counts(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $starrer = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection([
            'userid' => (int) $owner->id,
            'visibility' => collection_manager::VISIBILITY_PUBLIC,
        ]);
        $resource = $this->generator()->create_resource();
        collection_manager::add_item((int) $collection->id, (int) $resource->id, (int) $owner->id);
        star_manager::set_starred(
            (int) $collection->id,
            (int) $starrer->id,
            true,
            star_manager::ITEMTYPE_COLLECTION
        );

        $row = collection_manager::public_collections()[(int) $collection->id];

        $this->assertSame(1, (int) $row->itemcount);
        $this->assertSame(1, (int) $row->starcount);
    }

    /**
     * The three sorts order by what they say they order by.
     *
     * 'recent' is timepublished, NOT timecreated or timemodified — which is
     * the whole reason timepublished exists as a separate column.
     *
     * @return void
     */
    public function test_public_collections_honours_all_three_sorts(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $starrers = [
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
        ];

        $oldest = $this->generator()->create_collection([
            'userid' => (int) $owner->id,
            'title' => 'Oldest',
            'visibility' => collection_manager::VISIBILITY_PUBLIC,
            'timepublished' => 100,
        ]);
        $middle = $this->generator()->create_collection([
            'userid' => (int) $owner->id,
            'title' => 'Middle',
            'visibility' => collection_manager::VISIBILITY_PUBLIC,
            'timepublished' => 200,
        ]);
        $newest = $this->generator()->create_collection([
            'userid' => (int) $owner->id,
            'title' => 'Newest',
            'visibility' => collection_manager::VISIBILITY_PUBLIC,
            'timepublished' => 300,
        ]);

        // The oldest collection gets the most items, the middle one the most
        // stars, so each sort has to disagree with 'recent' to pass.
        foreach (['A', 'B'] as $label) {
            $resource = $this->generator()->create_resource(['title' => $label]);
            collection_manager::add_item((int) $oldest->id, (int) $resource->id, (int) $owner->id);
        }
        foreach ($starrers as $starrer) {
            star_manager::set_starred(
                (int) $middle->id,
                (int) $starrer->id,
                true,
                star_manager::ITEMTYPE_COLLECTION
            );
        }

        $this->assertSame(
            [(int) $newest->id, (int) $middle->id, (int) $oldest->id],
            array_keys(collection_manager::public_collections(collection_manager::SORT_RECENT))
        );
        $this->assertSame(
            (int) $oldest->id,
            (int) array_key_first(collection_manager::public_collections(collection_manager::SORT_ITEMS))
        );
        $this->assertSame(
            (int) $middle->id,
            (int) array_key_first(collection_manager::public_collections(collection_manager::SORT_STARRED))
        );
    }

    /**
     * Limit and offset page the browse list.
     *
     * @return void
     */
    public function test_public_collections_pages_with_limit_and_offset(): void {
        $this->resetAfterTest();
        $public = ['visibility' => collection_manager::VISIBILITY_PUBLIC];
        $first = $this->generator()->create_collection($public + ['title' => 'First', 'timepublished' => 300]);
        $second = $this->generator()->create_collection($public + ['title' => 'Second', 'timepublished' => 200]);
        $third = $this->generator()->create_collection($public + ['title' => 'Third', 'timepublished' => 100]);

        $this->assertSame(
            [(int) $first->id, (int) $second->id],
            array_keys(collection_manager::public_collections(collection_manager::SORT_RECENT, 2, 0))
        );
        $this->assertSame(
            [(int) $third->id],
            array_keys(collection_manager::public_collections(collection_manager::SORT_RECENT, 2, 2))
        );
    }

    /**
     * An unknown sort is a coding error rather than a silent fall-back to
     * 'recent': a page asking for a sort that does not exist has a bug, and
     * quietly answering a different question hides it.
     *
     * @return void
     */
    public function test_public_collections_refuses_an_unknown_sort(): void {
        $this->resetAfterTest();

        try {
            collection_manager::public_collections('popularity');
            $this->fail('an unknown sort must be refused');
        } catch (\coding_exception $e) {
            $this->assertSame('codingerror', $e->errorcode);
            $this->assertStringContainsString('Unknown collection sort: popularity', (string) $e->a);
        }
    }

    /**
     * Search matches a case-insensitive substring of the title or the
     * summary, and counts the same rows it returns.
     *
     * @return void
     */
    public function test_search_matches_title_and_summary_case_insensitively(): void {
        $this->resetAfterTest();
        $public = ['visibility' => collection_manager::VISIBILITY_PUBLIC];
        $bytitle = $this->generator()->create_collection($public + [
            'title' => 'Open Science readings',
            'timepublished' => 300,
        ]);
        $bysummary = $this->generator()->create_collection($public + [
            'title' => 'Week 3',
            'summary' => 'Everything about open science',
            'timepublished' => 200,
        ]);
        $this->generator()->create_collection($public + [
            'title' => 'Chemistry',
            'summary' => 'Nothing relevant',
        ]);

        $found = collection_manager::search_public_collections('OPEN sCiEnCe', 0, 0);

        $this->assertSame(
            [(int) $bytitle->id, (int) $bysummary->id],
            array_keys($found),
            'both halves of the LIKE match, newest first'
        );
        $this->assertSame(2, collection_manager::count_public_collections('OPEN sCiEnCe'));
        $this->assertSame(0, collection_manager::count_public_collections('thermodynamics'));
        $this->assertSame([], collection_manager::search_public_collections('thermodynamics', 0, 0));
    }

    /**
     * A query containing LIKE wildcards matches them literally.
     *
     * Without sql_like_escape() a visitor typing '%' into the search box
     * would be handed every collection on the site, and one typing '_' would
     * get near-misses they did not ask for.
     *
     * @return void
     */
    public function test_search_treats_like_wildcards_as_literal_characters(): void {
        $this->resetAfterTest();
        $public = ['visibility' => collection_manager::VISIBILITY_PUBLIC];
        $literal = $this->generator()->create_collection($public + ['title' => 'Grading: 100% open']);
        $underscore = $this->generator()->create_collection($public + ['title' => 'file_name conventions']);
        $this->generator()->create_collection($public + ['title' => 'Nothing special here']);

        $this->assertSame(
            [(int) $literal->id],
            array_keys(collection_manager::search_public_collections('100%', 0, 0))
        );
        $this->assertSame(1, collection_manager::count_public_collections('100%'));
        $this->assertSame(
            [(int) $underscore->id],
            array_keys(collection_manager::search_public_collections('file_name', 0, 0))
        );
        $this->assertSame(
            3,
            collection_manager::count_public_collections('  '),
            'a whitespace-only query is no query at all and matches everything'
        );
    }

    /**
     * Search obeys the same public rule as the browse list: a draft,
     * unlisted, held or tombstoned collection is not findable by searching
     * for its title.
     *
     * Searching is the obvious way to probe for one, so this is a disclosure
     * test rather than a completeness one.
     *
     * @return void
     */
    public function test_search_cannot_reach_a_collection_the_browse_list_hides(): void {
        $this->resetAfterTest();
        foreach (
            [
                ['visibility' => collection_manager::VISIBILITY_DRAFT],
                ['visibility' => collection_manager::VISIBILITY_UNLISTED],
                [
                    'visibility' => collection_manager::VISIBILITY_PUBLIC,
                    'modhidden' => 1,
                    'modhiddentime' => time(),
                ],
                [
                    'visibility' => collection_manager::VISIBILITY_PUBLIC,
                    'status' => collection_manager::STATUS_DELETED,
                ],
            ] as $index => $state
        ) {
            $this->generator()->create_collection($state + ['title' => 'Secret quantum notes ' . $index]);
        }

        $this->assertSame([], collection_manager::search_public_collections('Secret quantum', 0, 0));
        $this->assertSame(0, collection_manager::count_public_collections('Secret quantum'));
    }

    /**
     * The owner is told when somebody else adds to their collection, and is
     * not told about their own additions.
     *
     * @return void
     */
    public function test_an_addition_by_someone_else_notifies_the_owner(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user(['firstname' => 'Sam', 'lastname' => 'Stranger']);
        $collection = $this->generator()->create_collection([
            'userid' => (int) $owner->id,
            'title' => 'Open list',
            'opencuration' => 1,
        ]);
        $mine = $this->generator()->create_resource(['title' => 'Added by the owner']);
        $theirs = $this->generator()->create_resource(['title' => 'Added by a stranger']);

        $sink = $this->redirectMessages();
        collection_manager::add_item((int) $collection->id, (int) $mine->id, (int) $owner->id);
        $this->assertSame(0, $sink->count(), 'the owner did it, so there is nobody to tell');

        collection_manager::add_item((int) $collection->id, (int) $theirs->id, (int) $stranger->id);
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(1, $messages);
        $message = reset($messages);
        $this->assertSame('collectionitem', $message->eventtype);
        $this->assertEquals($owner->id, $message->useridto);
        $this->assertStringContainsString('Added by a stranger', $message->fullmessage);
        $this->assertStringContainsString('Sam Stranger', $message->fullmessage);
    }
}
