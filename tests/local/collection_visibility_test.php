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
 * The collection visibility matrix: who gets the page and who gets a 404, in
 * every state a collection can be in.
 *
 * user_can_view_collection() is the 404 gate. Everything else on a collection
 * page is drawn behind it, and every listing query filters on the same rule,
 * so one wrong cell here is a disclosure bug in several places at once. Five
 * states cross six kinds of visitor:
 *
 * - DRAFT is the owner's alone — moderators and named curators included.
 *   There is nothing to moderate about a collection that has never been
 *   published, and a window onto everybody's unpublished drafts is a privacy
 *   cost with no moderation benefit. That cell is the surprising one, so it
 *   gets its own test as well as its row in the matrix.
 * - UNLISTED is viewable by whoever has the link and appears in no listing.
 *   "Viewable" and "listed" are two different questions answered in two
 *   different places (this method, and the listing queries), which is exactly
 *   how they drift apart — so the listings are asserted here too.
 * - PUBLIC is viewable by everyone, logged out and guest included.
 * - MODHIDDEN is orthogonal: the owner still sees it (with the notice that
 *   tells them what happened), moderators see it so they can restore it,
 *   everybody else gets the 404. A held DRAFT is still draft-first — the
 *   takedown does not hand a moderator the unpublished version.
 * - DELETED answers FALSE for everybody, the owner included, even though its
 *   tombstone page is shown to everybody: the tombstone is rendered BEFORE
 *   the access check (is_tombstone(), and resource.php's identical ordering),
 *   and answering true would quietly admit a deleted collection to every
 *   listing that filters on this method.
 *
 * The last section covers the dead item — a collected resource whose author
 * later hid it. The public read drops it, a curator's read keeps it and flags
 * it, and nothing ever deletes it.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(collection_manager::class)]
final class collection_visibility_test extends \advanced_testcase {
    /** @var \stdClass|null The owner of every collection in the current test. */
    protected ?\stdClass $owner = null;

    /** @var \stdClass|null A named curator of every collection in the current test. */
    protected ?\stdClass $curator = null;

    /** @var \stdClass|null A logged-in user with no relationship to the collection. */
    protected ?\stdClass $stranger = null;

    /** @var \stdClass|null A user holding local/oerexchange:moderate. */
    protected ?\stdClass $moderator = null;

    /**
     * This plugin's data generator.
     *
     * @return \local_oerexchange_generator
     */
    protected function generator(): \local_oerexchange_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_oerexchange');
    }

    /**
     * Create the four named visitors the matrix is asserted against.
     *
     * Called by each test rather than from setUp(), because resetAfterTest()
     * has to be in the test itself for the users to be rolled back.
     *
     * @return void
     */
    protected function make_cast(): void {
        $this->owner = $this->getDataGenerator()->create_user();
        $this->curator = $this->getDataGenerator()->create_user();
        $this->stranger = $this->getDataGenerator()->create_user();

        $this->moderator = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(
            'local/oerexchange:moderate',
            CAP_ALLOW,
            $roleid,
            \context_system::instance()->id
        );
        role_assign($roleid, $this->moderator->id, \context_system::instance()->id);
    }

    /**
     * A collection owned by the cast's owner and curated by its curator.
     *
     * @param array $state the collection columns that make the state under test
     * @return \stdClass the collection row, re-read after the curator was added
     */
    protected function make_collection(array $state): \stdClass {
        $collection = $this->generator()->create_collection($state + [
            'userid' => (int) $this->owner->id,
            'title' => 'Reading list for ' . ($state['visibility'] ?? 'draft'),
        ]);
        collection_curator_manager::add(
            (int) $collection->id,
            (int) $this->curator->id,
            (int) $this->owner->id
        );

        return collection_manager::get((int) $collection->id);
    }

    /**
     * Who can see this collection, as a map a test can compare in one go.
     *
     * Returning the whole row at once means a failure names every actor that
     * disagreed, rather than stopping at the first.
     *
     * @param \stdClass $collection
     * @return array<string, bool> keyed by actor
     */
    protected function viewers(\stdClass $collection): array {
        return [
            'owner' => collection_manager::user_can_view_collection($collection, (int) $this->owner->id),
            'curator' => collection_manager::user_can_view_collection($collection, (int) $this->curator->id),
            'stranger' => collection_manager::user_can_view_collection($collection, (int) $this->stranger->id),
            'moderator' => collection_manager::user_can_view_collection($collection, (int) $this->moderator->id),
            'guest' => collection_manager::user_can_view_collection($collection, (int) guest_user()->id),
            'loggedout' => collection_manager::user_can_view_collection($collection, 0),
        ];
    }

    /**
     * A draft is the owner's alone. Everybody else, moderators and named
     * curators included, gets the 404.
     *
     * @return void
     */
    public function test_a_draft_is_visible_to_its_owner_and_to_nobody_else(): void {
        $this->resetAfterTest();
        $this->make_cast();
        $collection = $this->make_collection(['visibility' => collection_manager::VISIBILITY_DRAFT]);

        $this->assertSame([
            'owner' => true,
            'curator' => false,
            'stranger' => false,
            'moderator' => false,
            'guest' => false,
            'loggedout' => false,
        ], $this->viewers($collection));
    }

    /**
     * The draft/moderator cell, on its own, because it is the one a reader
     * will assume is a bug.
     *
     * A moderator can see every PUBLISHED collection, held or not. A draft is
     * the single thing on this platform they cannot see, and that is a
     * decision rather than an oversight: there is nothing to moderate about a
     * collection nobody has been shown.
     *
     * @return void
     */
    public function test_a_moderator_sees_every_published_collection_but_not_a_draft(): void {
        $this->resetAfterTest();
        $this->make_cast();
        $draft = $this->make_collection(['visibility' => collection_manager::VISIBILITY_DRAFT]);
        $unlisted = $this->make_collection(['visibility' => collection_manager::VISIBILITY_UNLISTED]);
        $public = $this->make_collection(['visibility' => collection_manager::VISIBILITY_PUBLIC]);
        $held = $this->make_collection([
            'visibility' => collection_manager::VISIBILITY_PUBLIC,
            'modhidden' => 1,
            'modhiddentime' => time(),
        ]);

        $moderatorid = (int) $this->moderator->id;
        $this->assertFalse(collection_manager::user_can_view_collection($draft, $moderatorid));
        $this->assertTrue(collection_manager::user_can_view_collection($unlisted, $moderatorid));
        $this->assertTrue(collection_manager::user_can_view_collection($public, $moderatorid));
        $this->assertTrue(collection_manager::user_can_view_collection($held, $moderatorid));
    }

    /**
     * An unlisted collection is viewable by anybody holding the link, and
     * appears in no listing at all.
     *
     * Both halves are the state: "share with my department" means the URL
     * works for whoever is sent it and the collection is not discoverable by
     * browsing, searching, or reading somebody else's starred list.
     *
     * @return void
     */
    public function test_an_unlisted_collection_is_viewable_by_link_and_listed_nowhere(): void {
        $this->resetAfterTest();
        $this->make_cast();
        $collection = $this->make_collection([
            'visibility' => collection_manager::VISIBILITY_UNLISTED,
            'title' => 'Departmental reading',
        ]);
        star_manager::set_starred(
            (int) $collection->id,
            (int) $this->stranger->id,
            true,
            star_manager::ITEMTYPE_COLLECTION
        );

        $this->assertSame([
            'owner' => true,
            'curator' => true,
            'stranger' => true,
            'moderator' => true,
            'guest' => true,
            'loggedout' => true,
        ], $this->viewers($collection));

        $this->assertSame([], collection_manager::public_collections());
        $this->assertSame(0, collection_manager::count_public_collections());
        $this->assertSame([], collection_manager::search_public_collections('Departmental', 0, 0));
        $this->assertSame(
            [],
            star_manager::starred_collections((int) $this->stranger->id),
            'somebody else\'s bookmark must not advertise an unlisted collection'
        );
    }

    /**
     * A public collection is viewable by everybody and listed.
     *
     * @return void
     */
    public function test_a_public_collection_is_viewable_by_everybody_and_listed(): void {
        $this->resetAfterTest();
        $this->make_cast();
        $collection = $this->make_collection(['visibility' => collection_manager::VISIBILITY_PUBLIC]);

        $this->assertSame([
            'owner' => true,
            'curator' => true,
            'stranger' => true,
            'moderator' => true,
            'guest' => true,
            'loggedout' => true,
        ], $this->viewers($collection));

        $this->assertSame([(int) $collection->id], array_keys(collection_manager::public_collections()));
    }

    /**
     * A taken-down collection stays visible to its owner and to moderators,
     * and disappears for everybody else and from every listing.
     *
     * The owner keeps the page because that is where the explanation lives
     * and where they reply; hiding it as a 404 from them too would just look
     * broken.
     *
     * @return void
     */
    public function test_a_taken_down_collection_is_visible_to_its_owner_and_to_moderators(): void {
        $this->resetAfterTest();
        $this->make_cast();
        $collection = $this->make_collection([
            'visibility' => collection_manager::VISIBILITY_PUBLIC,
            'modhidden' => 1,
            'modhiddentime' => time(),
            'modhiddenby' => (int) $this->moderator->id,
            'title' => 'Held reading list',
        ]);

        $this->assertSame([
            'owner' => true,
            'curator' => false,
            'stranger' => false,
            'moderator' => true,
            'guest' => false,
            'loggedout' => false,
        ], $this->viewers($collection));

        $this->assertSame([], collection_manager::public_collections());
        $this->assertSame([], collection_manager::search_public_collections('Held reading', 0, 0));
    }

    /**
     * A takedown applied to a draft does not promote the draft: the draft
     * rule is checked first, so the owner alone still sees it and the
     * moderator does not.
     *
     * Reaching this state needs a moderator to hold something they could not
     * see, which the UI does not offer — but the two columns are
     * independent, so the combination is reachable by a data fix or a future
     * automated takedown, and the order of the checks must not be an
     * accident.
     *
     * @return void
     */
    public function test_a_held_draft_is_still_draft_first(): void {
        $this->resetAfterTest();
        $this->make_cast();
        $collection = $this->make_collection([
            'visibility' => collection_manager::VISIBILITY_DRAFT,
            'modhidden' => 1,
            'modhiddentime' => time(),
        ]);

        $this->assertSame([
            'owner' => true,
            'curator' => false,
            'stranger' => false,
            'moderator' => false,
            'guest' => false,
            'loggedout' => false,
        ], $this->viewers($collection));
    }

    /**
     * A tombstone answers FALSE for everybody, the owner included — and
     * is_tombstone() answers TRUE for everybody, which is what the page
     * branches on before it ever asks the view question.
     *
     * The pair is the contract: a caller that asked only
     * user_can_view_collection() would 404 a deleted collection instead of
     * rendering its tombstone, and a caller that trusted the view check to
     * exclude it from a listing would be right to.
     *
     * @return void
     */
    public function test_a_tombstone_is_shown_by_is_tombstone_and_refused_by_the_view_gate(): void {
        $this->resetAfterTest();
        $this->make_cast();
        $collection = $this->make_collection([
            'visibility' => collection_manager::VISIBILITY_PUBLIC,
            'status' => collection_manager::STATUS_DELETED,
            'title' => 'Deleted reading list',
        ]);

        $this->assertTrue(collection_manager::is_tombstone($collection));
        $this->assertSame([
            'owner' => false,
            'curator' => false,
            'stranger' => false,
            'moderator' => false,
            'guest' => false,
            'loggedout' => false,
        ], $this->viewers($collection));

        $this->assertSame([], collection_manager::public_collections());
        $this->assertSame([], collection_manager::search_public_collections('Deleted reading', 0, 0));
    }

    /**
     * A collection whose owner has been through GDPR erasure (userid 0) is
     * not visible to every logged-out visitor while it is a draft.
     *
     * The owner test must be truthiness-guarded, because 0 is both "no
     * attributable user" and "not signed in".
     *
     * @return void
     */
    public function test_an_erased_owners_draft_is_not_visible_to_logged_out_visitors(): void {
        $this->resetAfterTest();
        $this->make_cast();
        $collection = $this->generator()->create_collection([
            'userid' => 0,
            'visibility' => collection_manager::VISIBILITY_DRAFT,
        ]);

        $this->assertFalse(collection_manager::user_can_view_collection($collection, 0));
        $this->assertFalse(
            collection_manager::user_can_view_collection($collection, (int) $this->moderator->id)
        );
    }

    /**
     * Every visibility the column accepts is covered by the matrix above.
     *
     * A fourth visibility added without a matrix row for it would otherwise
     * be silently untested, and the default branch of the view gate treats
     * anything that is not draft as viewable — the laxest of the three
     * answers.
     *
     * @return void
     */
    public function test_the_matrix_covers_every_visibility_the_column_accepts(): void {
        $this->resetAfterTest();

        $this->assertSame(
            [
                collection_manager::VISIBILITY_DRAFT,
                collection_manager::VISIBILITY_UNLISTED,
                collection_manager::VISIBILITY_PUBLIC,
            ],
            collection_manager::VISIBILITIES
        );
    }

    /**
     * A resource hidden after it was collected disappears for the public,
     * stays visible-and-flagged for whoever may curate, and is never
     * deleted.
     *
     * The includeunavailable flag is driven here from user_can_curate(),
     * exactly as a page must drive it, so the test states the whole rule
     * rather than just the mechanics of the parameter.
     *
     * @return void
     */
    public function test_a_dead_item_is_flagged_for_curators_and_invisible_to_everyone_else(): void {
        global $DB;
        $this->resetAfterTest();
        $this->make_cast();
        $collection = $this->make_collection(['visibility' => collection_manager::VISIBILITY_PUBLIC]);
        $live = $this->generator()->create_resource(['title' => 'Still published']);
        $dying = $this->generator()->create_resource(['title' => 'Hidden by its author']);
        collection_manager::add_item((int) $collection->id, (int) $live->id, (int) $this->owner->id);
        $dyingitem = collection_manager::add_item(
            (int) $collection->id,
            (int) $dying->id,
            (int) $this->owner->id
        );
        $DB->set_field('local_oerexchange_resources', 'status', 'hidden', ['id' => $dying->id]);

        foreach (['owner' => $this->owner, 'curator' => $this->curator] as $label => $actor) {
            $cancurate = collection_manager::user_can_curate($collection, (int) $actor->id);
            $this->assertTrue($cancurate, $label);
            $items = collection_manager::get_items((int) $collection->id, $cancurate);
            $this->assertCount(2, $items, $label);
            $this->assertFalse($items[$dyingitem]->available, $label);
        }

        foreach (['stranger' => $this->stranger, 'moderator' => $this->moderator] as $label => $actor) {
            $cancurate = collection_manager::user_can_curate($collection, (int) $actor->id);
            $this->assertFalse($cancurate, $label);
            $items = collection_manager::get_items((int) $collection->id, $cancurate);
            $this->assertCount(1, $items, $label);
            $this->assertArrayNotHasKey($dyingitem, $items, $label);
        }

        $this->assertTrue(
            $DB->record_exists(collection_manager::ITEM_TABLE, ['id' => $dyingitem]),
            'the row survives: the owner collected something that was fine at the time'
        );
        $this->assertSame(
            1,
            collection_manager::item_count((int) $collection->id),
            'the card caption promises only what the public page draws'
        );
    }

    /**
     * A dead item comes back by itself when its resource is published again.
     *
     * Because nothing was deleted, restoring the resource restores the item —
     * which is the whole point of flagging rather than removing.
     *
     * @return void
     */
    public function test_a_dead_item_returns_when_its_resource_is_published_again(): void {
        global $DB;
        $this->resetAfterTest();
        $this->make_cast();
        $collection = $this->make_collection(['visibility' => collection_manager::VISIBILITY_PUBLIC]);
        $resource = $this->generator()->create_resource();
        $itemid = collection_manager::add_item(
            (int) $collection->id,
            (int) $resource->id,
            (int) $this->owner->id
        );
        $DB->set_field('local_oerexchange_resources', 'status', 'modhidden', ['id' => $resource->id]);
        $this->assertSame([], collection_manager::get_items((int) $collection->id));

        $DB->set_field(
            'local_oerexchange_resources',
            'status',
            resource_manager::STATUS_PUBLISHED,
            ['id' => $resource->id]
        );

        $items = collection_manager::get_items((int) $collection->id);
        $this->assertSame([$itemid], array_keys($items));
        $this->assertTrue($items[$itemid]->available);
    }
}
