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
 * Collections joined the star wrapper by adding an optional trailing
 * $itemtype to every method rather than by growing a second manager, and this
 * file is about what that choice can get wrong.
 *
 * The danger is ISOLATION. A star is a row in core's {favourite} table keyed
 * on (component, itemtype, itemid, contextid, userid), and resource ids and
 * collection ids come from two independent sequences — so resource 7 and
 * collection 7 both exist on any real site, in the same component and the same
 * system context, separated by the itemtype column alone. Every method that
 * forgets to pass or filter on that column will silently report one kind of
 * thing's stars as the other's: the count under a collection card, the list on
 * a profile page, and the rows a privacy export hands over. Several tests here
 * therefore star the SAME numeric id under both itemtypes and assert the two
 * never see each other.
 *
 * The second danger is the familiar one from the resource side, restated for
 * the new itemtype: a public read must not resurface content that has since
 * been unpublished, taken down or deleted — while the privacy read must report
 * exactly those same stars, because under-reporting a subject access request
 * is the opposite failure. starred_collections() asks
 * collection_manager::public_where() for that rule rather than keeping a copy,
 * and the tests below drive every state through it.
 *
 * The resource half of the class is covered by star_manager_test.php and is
 * not repeated; what IS asserted here is that adding the parameter left the
 * old no-argument call sites answering exactly as before.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(star_manager::class)]
final class star_manager_collection_test extends \advanced_testcase {
    /**
     * This plugin's data generator.
     *
     * @return \local_oerexchange_generator
     */
    protected function generator(): \local_oerexchange_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_oerexchange');
    }

    /**
     * Count favourite rows of one itemtype on one item, read straight from
     * the table.
     *
     * Deliberately not star_count(): a test of isolation must not take its
     * reading from the method whose isolation it is checking.
     *
     * @param string $itemtype star_manager::ITEMTYPE or ITEMTYPE_COLLECTION
     * @param int $itemid
     * @return int
     */
    protected function count_rows(string $itemtype, int $itemid): int {
        global $DB;

        return $DB->count_records('favourite', [
            'component' => star_manager::COMPONENT,
            'itemtype' => $itemtype,
            'itemid' => $itemid,
            'contextid' => \context_system::instance()->id,
        ]);
    }

    /**
     * Backdate a star so ordering by star time is deterministic.
     *
     * Stars created inside one test all land in the same second otherwise,
     * which would let a wrongly ordered result pass by luck.
     *
     * @param int $itemid the starred item
     * @param int $userid who starred it
     * @param int $time the timecreated value to force
     * @param string $itemtype star_manager::ITEMTYPE or ITEMTYPE_COLLECTION
     * @return void
     */
    protected function set_star_time(
        int $itemid,
        int $userid,
        int $time,
        string $itemtype = star_manager::ITEMTYPE_COLLECTION
    ): void {
        global $DB;

        $DB->set_field('favourite', 'timecreated', $time, [
            'component' => star_manager::COMPONENT,
            'itemtype' => $itemtype,
            'itemid' => $itemid,
            'contextid' => \context_system::instance()->id,
            'userid' => $userid,
        ]);
    }

    /**
     * Starring a collection stores a row of the collection itemtype and
     * reports the state read back from the store.
     *
     * @return void
     */
    public function test_starring_a_collection_stores_a_collection_star(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection();

        $after = star_manager::set_starred(
            (int) $collection->id,
            (int) $user->id,
            true,
            star_manager::ITEMTYPE_COLLECTION
        );

        $this->assertTrue($after);
        $this->assertSame(1, $this->count_rows(star_manager::ITEMTYPE_COLLECTION, (int) $collection->id));
        $this->assertTrue(star_manager::is_starred(
            (int) $collection->id,
            (int) $user->id,
            star_manager::ITEMTYPE_COLLECTION
        ));
        $this->assertSame(
            1,
            star_manager::star_count((int) $collection->id, star_manager::ITEMTYPE_COLLECTION)
        );
    }

    /**
     * A resource star and a collection star on the SAME numeric id are two
     * different stars.
     *
     * Resource ids and collection ids come from two independent sequences, so
     * this collision exists on every real site. The two are separated by the
     * itemtype column alone, and a method that forgets it reports one kind of
     * thing's stars as the other's.
     *
     * @return void
     */
    public function test_a_resource_star_and_a_collection_star_on_one_id_never_meet(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        // A bare id, starred under both itemtypes: the favourites subsystem
        // stores what it is given without checking the item exists, which is
        // what lets this state be built at all.
        $sharedid = 4242;

        star_manager::set_starred($sharedid, (int) $user->id, true);
        star_manager::set_starred($sharedid, (int) $user->id, true, star_manager::ITEMTYPE_COLLECTION);
        star_manager::set_starred($sharedid, (int) $other->id, true, star_manager::ITEMTYPE_COLLECTION);

        $this->assertSame(1, $this->count_rows(star_manager::ITEMTYPE, $sharedid));
        $this->assertSame(2, $this->count_rows(star_manager::ITEMTYPE_COLLECTION, $sharedid));
        $this->assertSame(1, star_manager::star_count($sharedid));
        $this->assertSame(2, star_manager::star_count($sharedid, star_manager::ITEMTYPE_COLLECTION));
        $this->assertSame([$sharedid => 1], star_manager::star_counts_for([$sharedid]));
        $this->assertSame(
            [$sharedid => 2],
            star_manager::star_counts_for([$sharedid], star_manager::ITEMTYPE_COLLECTION)
        );
        $this->assertFalse(star_manager::is_starred($sharedid, (int) $other->id));
        $this->assertTrue(
            star_manager::is_starred($sharedid, (int) $other->id, star_manager::ITEMTYPE_COLLECTION)
        );

        // Unstarring one must not disturb the other.
        star_manager::set_starred($sharedid, (int) $user->id, false, star_manager::ITEMTYPE_COLLECTION);

        $this->assertSame(1, $this->count_rows(star_manager::ITEMTYPE, $sharedid));
        $this->assertSame(1, $this->count_rows(star_manager::ITEMTYPE_COLLECTION, $sharedid));
        $this->assertTrue(star_manager::is_starred($sharedid, (int) $user->id));
    }

    /**
     * Starring twice, and unstarring twice, are both no-ops.
     *
     * Not belt-and-braces: the repository's add() inserts unconditionally, so
     * a double-submitted star would hit {favourite}'s uniqueuserfavouriteitem
     * index and raise a dml_write_exception rather than doing nothing, and
     * delete_favourite() throws outright when the favourite is not there. A
     * double-click on a star button is the everyday way to find out.
     *
     * @return void
     */
    public function test_starring_a_collection_is_idempotent_in_both_directions(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection();
        $id = (int) $collection->id;

        $this->assertTrue(star_manager::set_starred($id, (int) $user->id, true, star_manager::ITEMTYPE_COLLECTION));
        $this->assertTrue(star_manager::set_starred($id, (int) $user->id, true, star_manager::ITEMTYPE_COLLECTION));
        $this->assertSame(1, $this->count_rows(star_manager::ITEMTYPE_COLLECTION, $id));

        $this->assertFalse(star_manager::set_starred($id, (int) $user->id, false, star_manager::ITEMTYPE_COLLECTION));
        $this->assertFalse(star_manager::set_starred($id, (int) $user->id, false, star_manager::ITEMTYPE_COLLECTION));
        $this->assertSame(0, $this->count_rows(star_manager::ITEMTYPE_COLLECTION, $id));
    }

    /**
     * A guest, and a logged-out visitor, cannot star a collection and have
     * none — a no-op returning false rather than an exception, matching every
     * other entry point in the class.
     *
     * @return void
     */
    public function test_a_guest_and_a_logged_out_visitor_cannot_star_a_collection(): void {
        $this->resetAfterTest();
        $collection = $this->generator()->create_collection();
        $id = (int) $collection->id;
        $guestid = (int) guest_user()->id;

        $this->assertFalse(star_manager::set_starred($id, $guestid, true, star_manager::ITEMTYPE_COLLECTION));
        $this->assertFalse(star_manager::set_starred($id, 0, true, star_manager::ITEMTYPE_COLLECTION));
        $this->assertSame(0, $this->count_rows(star_manager::ITEMTYPE_COLLECTION, $id));
        $this->assertFalse(star_manager::is_starred($id, $guestid, star_manager::ITEMTYPE_COLLECTION));
        $this->assertFalse(star_manager::is_starred($id, 0, star_manager::ITEMTYPE_COLLECTION));
        $this->assertSame([], star_manager::starred_collections($guestid));
        $this->assertSame([], star_manager::starred_collections(0));
        $this->assertSame([], star_manager::all_starred_ids($guestid, star_manager::ITEMTYPE_COLLECTION));
        $this->assertSame([], star_manager::all_starred_ids(0, star_manager::ITEMTYPE_COLLECTION));
    }

    /**
     * star_count() counts people, and star_counts_for() answers for every id
     * it is given — zero included — in ONE query.
     *
     * The browse page puts a star count on every card and can sort by it;
     * star_count() per card is how that page becomes twenty queries. The read
     * count is calibrated against a known single query first, because what
     * one logical query costs in reads depends on the driver.
     *
     * @return void
     */
    public function test_star_counts_for_answers_every_id_in_one_query(): void {
        global $DB;
        $this->resetAfterTest();
        $starred = $this->generator()->create_collection(['title' => 'Starred twice']);
        $unstarred = $this->generator()->create_collection(['title' => 'Starred by nobody']);
        for ($starrer = 0; $starrer < 2; $starrer++) {
            $user = $this->getDataGenerator()->create_user();
            star_manager::set_starred(
                (int) $starred->id,
                (int) $user->id,
                true,
                star_manager::ITEMTYPE_COLLECTION
            );
        }
        $ids = [(int) $starred->id, (int) $unstarred->id, 999999];

        $start = $DB->perf_get_reads();
        $DB->count_records(collection_manager::TABLE);
        $readsperquery = $DB->perf_get_reads() - $start;
        $this->assertGreaterThan(0, $readsperquery, 'the driver counts reads, so the calibration is usable');

        $start = $DB->perf_get_reads();
        $counts = star_manager::star_counts_for($ids, star_manager::ITEMTYPE_COLLECTION);
        $used = $DB->perf_get_reads() - $start;

        $this->assertSame(
            [(int) $starred->id => 2, (int) $unstarred->id => 0, 999999 => 0],
            $counts
        );
        $this->assertSame($readsperquery, $used, 'one query however many ids are asked about');
        $this->assertSame([], star_manager::star_counts_for([], star_manager::ITEMTYPE_COLLECTION));
    }

    /**
     * A starred collection that has since become unpublishable does not
     * resurface on the world-readable page that lists it.
     *
     * Every state is driven through, because starred_collections() asks
     * collection_manager::public_where() for the rule rather than keeping a
     * copy — the point being that this list cannot drift from the browse
     * page the day that rule changes.
     *
     * @return void
     */
    public function test_starred_collections_shows_only_what_a_stranger_may_be_shown(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        // The held and deleted rows are PUBLIC, so the only thing hiding them
        // is the condition under test rather than their visibility as well.
        $states = [
            'public' => ['visibility' => collection_manager::VISIBILITY_PUBLIC],
            'draft' => ['visibility' => collection_manager::VISIBILITY_DRAFT],
            'unlisted' => ['visibility' => collection_manager::VISIBILITY_UNLISTED],
            'held' => [
                'visibility' => collection_manager::VISIBILITY_PUBLIC,
                'modhidden' => 1,
                'modhiddentime' => time(),
            ],
            'deleted' => [
                'visibility' => collection_manager::VISIBILITY_PUBLIC,
                'status' => collection_manager::STATUS_DELETED,
            ],
        ];

        $collections = [];
        foreach ($states as $label => $state) {
            $collections[$label] = $this->generator()->create_collection(
                $state + ['title' => 'Starred and ' . $label]
            );
            star_manager::set_starred(
                (int) $collections[$label]->id,
                (int) $user->id,
                true,
                star_manager::ITEMTYPE_COLLECTION
            );
        }

        $visible = star_manager::starred_collections((int) $user->id);

        $this->assertSame(
            [(int) $collections['public']->id],
            array_keys($visible),
            'only the public, active, not-taken-down collection is advertised'
        );
        $this->assertSame(
            5,
            count(star_manager::all_starred_ids((int) $user->id, star_manager::ITEMTYPE_COLLECTION)),
            'the privacy export reports every one of them: under-reporting a subject access '
                . 'request is the opposite failure'
        );
    }

    /**
     * starred_collections() returns collection rows — not resource rows —
     * most recently starred first, each carrying ->timestarred.
     *
     * The ordering is this class's promise rather than core's: the
     * repository's finder passes an empty sort, so the subsystem returns rows
     * in no defined order.
     *
     * @return void
     */
    public function test_starred_collections_are_collection_rows_newest_star_first(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $public = ['visibility' => collection_manager::VISIBILITY_PUBLIC];
        $first = $this->generator()->create_collection($public + ['title' => 'Starred first']);
        $second = $this->generator()->create_collection($public + ['title' => 'Starred second']);
        $third = $this->generator()->create_collection($public + ['title' => 'Starred third']);
        foreach ([$first, $second, $third] as $collection) {
            star_manager::set_starred(
                (int) $collection->id,
                (int) $user->id,
                true,
                star_manager::ITEMTYPE_COLLECTION
            );
        }
        $this->set_star_time((int) $first->id, (int) $user->id, 100);
        $this->set_star_time((int) $second->id, (int) $user->id, 200);
        $this->set_star_time((int) $third->id, (int) $user->id, 300);

        $starred = star_manager::starred_collections((int) $user->id);

        $this->assertSame(
            [(int) $third->id, (int) $second->id, (int) $first->id],
            array_keys($starred)
        );
        $this->assertSame('Starred third', $starred[(int) $third->id]->title);
        $this->assertSame(
            $first->slug,
            $starred[(int) $first->id]->slug,
            'the whole collection row is there, not just its id'
        );
        $this->assertSame(300, (int) $starred[(int) $third->id]->timestarred);
        $this->assertSame(
            [(int) $third->id, (int) $second->id],
            array_keys(star_manager::starred_collections((int) $user->id, 2)),
            'the limit takes the newest stars'
        );
    }

    /**
     * Stars are per user: one person's starred collections are not another's.
     *
     * @return void
     */
    public function test_starred_collections_are_per_user(): void {
        $this->resetAfterTest();
        $mine = $this->getDataGenerator()->create_user();
        $theirs = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection([
            'visibility' => collection_manager::VISIBILITY_PUBLIC,
        ]);
        star_manager::set_starred(
            (int) $collection->id,
            (int) $mine->id,
            true,
            star_manager::ITEMTYPE_COLLECTION
        );

        $this->assertSame(
            [(int) $collection->id],
            array_keys(star_manager::starred_collections((int) $mine->id))
        );
        $this->assertSame([], star_manager::starred_collections((int) $theirs->id));
        $this->assertSame(
            1,
            star_manager::star_count((int) $collection->id, star_manager::ITEMTYPE_COLLECTION),
            'the public count is everybody\'s, which is a different question'
        );
    }

    /**
     * A user's starred resources and starred collections are separate lists,
     * even though both are read through the same method.
     *
     * @return void
     */
    public function test_the_two_starred_lists_do_not_bleed_into_each_other(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $resource = $this->generator()->create_resource(['title' => 'A starred resource']);
        $collection = $this->generator()->create_collection([
            'title' => 'A starred collection',
            'visibility' => collection_manager::VISIBILITY_PUBLIC,
        ]);
        star_manager::set_starred((int) $resource->id, (int) $user->id, true);
        star_manager::set_starred(
            (int) $collection->id,
            (int) $user->id,
            true,
            star_manager::ITEMTYPE_COLLECTION
        );

        $resources = star_manager::starred_resources((int) $user->id);
        $collections = star_manager::starred_collections((int) $user->id);

        $this->assertSame([(int) $resource->id], array_keys($resources));
        $this->assertSame('A starred resource', $resources[(int) $resource->id]->title);
        $this->assertSame([(int) $collection->id], array_keys($collections));
        $this->assertSame('A starred collection', $collections[(int) $collection->id]->title);
        $this->assertSame(
            [(int) $resource->id],
            star_manager::all_starred_ids((int) $user->id)
        );
        $this->assertSame(
            [(int) $collection->id],
            star_manager::all_starred_ids((int) $user->id, star_manager::ITEMTYPE_COLLECTION)
        );
    }

    /**
     * Tombstoning a collection drops everybody's stars on it, and leaves
     * stars on resources alone.
     *
     * The stars are other people's rows in a core table, so nothing in the
     * plugin's own delete path would otherwise reach them and they would
     * dangle against an id that no longer resolves.
     *
     * @return void
     */
    public function test_delete_for_collection_drops_every_star_on_that_collection_only(): void {
        $this->resetAfterTest();
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        $doomed = $this->generator()->create_collection(['title' => 'Doomed']);
        $spared = $this->generator()->create_collection(['title' => 'Spared']);
        $resource = $this->generator()->create_resource();
        foreach ([$first, $second] as $user) {
            star_manager::set_starred(
                (int) $doomed->id,
                (int) $user->id,
                true,
                star_manager::ITEMTYPE_COLLECTION
            );
            star_manager::set_starred(
                (int) $spared->id,
                (int) $user->id,
                true,
                star_manager::ITEMTYPE_COLLECTION
            );
            star_manager::set_starred((int) $resource->id, (int) $user->id, true);
        }

        star_manager::delete_for_collection((int) $doomed->id);

        $this->assertSame(0, $this->count_rows(star_manager::ITEMTYPE_COLLECTION, (int) $doomed->id));
        $this->assertSame(2, $this->count_rows(star_manager::ITEMTYPE_COLLECTION, (int) $spared->id));
        $this->assertSame(
            2,
            $this->count_rows(star_manager::ITEMTYPE, (int) $resource->id),
            'a resource star is not a collection star, whatever the ids happen to be'
        );
    }

    /**
     * Deleting a resource's stars leaves collection stars alone, which is the
     * same isolation read from the other side.
     *
     * @return void
     */
    public function test_delete_for_resource_leaves_collection_stars_alone(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $sharedid = 4242;
        star_manager::set_starred($sharedid, (int) $user->id, true);
        star_manager::set_starred($sharedid, (int) $user->id, true, star_manager::ITEMTYPE_COLLECTION);

        star_manager::delete_for_resource($sharedid);

        $this->assertSame(0, $this->count_rows(star_manager::ITEMTYPE, $sharedid));
        $this->assertSame(1, $this->count_rows(star_manager::ITEMTYPE_COLLECTION, $sharedid));
    }

    /**
     * An itemtype this plugin does not star is refused by every method that
     * takes one.
     *
     * A typo'd itemtype is far worse than it looks: create_favourite() would
     * happily store it, favourite_exists() would then answer false for the
     * star the user just created, and the row would be invisible to every
     * count, listing and delete path in the class — an orphan in a core table
     * that nothing cleans up. Each call below passes a REAL userid, because
     * the falsy/guest guards return before the itemtype is ever checked.
     *
     * @return void
     */
    public function test_an_unknown_itemtype_is_refused_by_every_method(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $userid = (int) $user->id;

        $calls = [
            'is_starred' => fn() => star_manager::is_starred(1, $userid, 'bookmark'),
            'set_starred' => fn() => star_manager::set_starred(1, $userid, true, 'bookmark'),
            'star_count' => fn() => star_manager::star_count(1, 'bookmark'),
            'star_counts_for' => fn() => star_manager::star_counts_for([1], 'bookmark'),
            'starred_resources' => fn() => star_manager::starred_resources($userid, 0, 'bookmark'),
            'all_starred_ids' => fn() => star_manager::all_starred_ids($userid, 'bookmark'),
        ];

        foreach ($calls as $method => $call) {
            try {
                $call();
                $this->fail("star_manager::$method() must refuse an unknown itemtype");
            } catch (\coding_exception $e) {
                $this->assertSame('codingerror', $e->errorcode, $method);
                $this->assertStringContainsString(
                    'Unknown local_oerexchange star itemtype: bookmark',
                    (string) $e->a,
                    $method
                );
            }
        }

        $this->assertSame(
            0,
            $this->count_rows('bookmark', 1),
            'a refused itemtype must not have left an orphan row behind'
        );
    }

    /**
     * The declared itemtype set is exactly the two this plugin stars, and the
     * resource one is still the default.
     *
     * The default is what keeps every pre-collections call site — the
     * resource page, its web service, the privacy provider — working
     * untouched, so it is part of the contract rather than a convenience.
     *
     * @return void
     */
    public function test_the_itemtype_set_is_declared_and_resource_is_still_the_default(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $resource = $this->generator()->create_resource();

        $this->assertSame('resource', star_manager::ITEMTYPE);
        $this->assertSame('collection', star_manager::ITEMTYPE_COLLECTION);
        $this->assertSame(
            [star_manager::ITEMTYPE, star_manager::ITEMTYPE_COLLECTION],
            star_manager::ITEMTYPES
        );

        // The old three-argument spelling, with no itemtype anywhere.
        star_manager::set_starred((int) $resource->id, (int) $user->id, true);

        $this->assertSame(1, $this->count_rows(star_manager::ITEMTYPE, (int) $resource->id));
        $this->assertSame(0, $this->count_rows(star_manager::ITEMTYPE_COLLECTION, (int) $resource->id));
        $this->assertTrue(star_manager::is_starred((int) $resource->id, (int) $user->id));
        $this->assertSame(1, star_manager::star_count((int) $resource->id));
        $this->assertSame(
            [(int) $resource->id],
            array_keys(star_manager::starred_resources((int) $user->id))
        );
    }
}
