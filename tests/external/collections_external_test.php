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

use PHPUnit\Framework\Attributes\CoversClass;
use local_oerexchange\local\collection_list;
use local_oerexchange\local\collection_manager;
use local_oerexchange\local\star_manager;

/**
 * Tests for the two AJAX functions the collection surfaces call:
 * local_oerexchange_get_collections and local_oerexchange_set_collection_star.
 *
 * Both are session-authenticated browser endpoints deliberately kept out of
 * the token-facing "OER Exchange service", and they fail in opposite
 * directions, so they are tested for opposite things.
 *
 * get_collections() is READ-ONLY and anonymous by design — the /collections
 * page is public, and this returns only what that page already shows a
 * stranger. What matters is therefore that it cannot be used to pull more
 * than a page at a time (the browser's limit is clamped, not trusted) and
 * that it cannot be used to see a draft.
 *
 * set_collection_star() is a WRITE that takes a bare collection id, which
 * makes it the obvious place to probe for ids that exist. It must turn away a
 * guest, and it must give the SAME "not found" answer for a collection the
 * caller may not see as for one that does not exist — asserted on the
 * specific error code each time, because the function has several ways to
 * throw and a test that accepted any moodle_exception would pass on the wrong
 * one.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(get_collections::class)]
#[CoversClass(set_collection_star::class)]
final class collections_external_test extends \advanced_testcase {
    /**
     * This plugin's data generator.
     *
     * @return \component_generator_base
     */
    protected function generator(): \component_generator_base {
        return $this->getDataGenerator()->get_plugin_generator('local_oerexchange');
    }

    /**
     * A collection in a known state.
     *
     * @param int $userid the owner
     * @param string $title
     * @param array $state columns to overwrite after creation
     * @return \stdClass the stored row
     */
    protected function make_collection(int $userid, string $title, array $state = []): \stdClass {
        return $this->generator()->create_collection($state + [
            'userid' => $userid,
            'title' => $title,
            // UNSPECIFIED MEANS DRAFT, the opposite of the generator's own
            // default: half of these tests turn on a draft being refused.
            'visibility' => collection_manager::VISIBILITY_DRAFT,
            'timepublished' => 0,
        ]);
    }

    /**
     * A public collection, published $agoseconds ago.
     *
     * @param int $userid
     * @param string $title
     * @param int $agoseconds
     * @return \stdClass
     */
    protected function make_public_collection(int $userid, string $title, int $agoseconds = 0): \stdClass {
        return $this->make_collection($userid, $title, [
            'visibility' => collection_manager::VISIBILITY_PUBLIC,
            'timepublished' => time() - $agoseconds,
        ]);
    }

    /**
     * The markup get_collections() answers with, for one set of arguments.
     *
     * @param string $sort
     * @param int $limit
     * @param int $offset
     * @param string $layout
     * @return string
     */
    protected function fetch(
        string $sort = collection_list::SORT_RECENT,
        int $limit = collection_list::PERPAGE,
        int $offset = 0,
        string $layout = collection_list::LAYOUT_CARDS
    ): string {
        $result = get_collections::execute($sort, $limit, $offset, $layout);

        return $result['html'];
    }

    /**
     * An anonymous caller gets the public listing, which is what the public
     * /collections page shows them anyway.
     *
     * @return void
     */
    public function test_an_anonymous_caller_gets_the_public_listing(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $this->make_public_collection((int) $owner->id, 'Published list');

        $this->setUser(null);
        $html = $this->fetch();

        $this->assertStringContainsString('Published list', $html);
    }

    /**
     * Drafts, unlisted collections, held collections and tombstones are not
     * reachable through the web service either.
     *
     * @return void
     */
    public function test_unlistable_collections_are_not_returned(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $this->make_public_collection((int) $owner->id, 'Published list');
        $this->make_collection((int) $owner->id, 'Draft list');
        $this->make_collection((int) $owner->id, 'Unlisted list', [
            'visibility' => collection_manager::VISIBILITY_UNLISTED,
            'timepublished' => time(),
        ]);
        $held = $this->make_public_collection((int) $owner->id, 'Held list');
        collection_manager::set_modhidden((int) $held->id, true, (int) $owner->id);
        $deleted = $this->make_public_collection((int) $owner->id, 'Deleted list');
        collection_manager::delete((int) $deleted->id);

        // The owner themselves, who CAN see all of these on their own pages.
        $this->setUser($owner);
        $html = $this->fetch();

        $this->assertStringContainsString('Published list', $html);
        $this->assertStringNotContainsString('Draft list', $html);
        $this->assertStringNotContainsString('Unlisted list', $html);
        $this->assertStringNotContainsString('Held list', $html);
        $this->assertStringNotContainsString('Deleted list', $html);
    }

    /**
     * The browser's limit is clamped to one page, so nobody can pull every
     * collection on the site in one request.
     *
     * @return void
     */
    public function test_the_limit_is_clamped_to_one_page(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $total = collection_list::PERPAGE + 1;
        for ($i = 0; $i < $total; $i++) {
            // Oldest last, so the final one is the one that must fall off.
            $this->make_public_collection((int) $owner->id, 'List number ' . $i, $i * MINSECS);
        }

        $this->setUser(null);
        $html = $this->fetch(collection_list::SORT_RECENT, 500);

        // One card-title per card in the grid layout.
        $this->assertSame(collection_list::PERPAGE, substr_count($html, 'card-title'));
        $this->assertStringContainsString('List number 0', $html);
        $this->assertStringNotContainsString('List number ' . ($total - 1), $html);
    }

    /**
     * An offset reaches the next page, which is how the AJAX sort control
     * pages without a reload.
     *
     * @return void
     */
    public function test_an_offset_returns_the_following_page(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $this->make_public_collection((int) $owner->id, 'Newer list', 0);
        $this->make_public_collection((int) $owner->id, 'Older list', DAYSECS);

        $this->setUser(null);

        $first = $this->fetch(collection_list::SORT_RECENT, 1, 0);
        $this->assertStringContainsString('Newer list', $first);
        $this->assertStringNotContainsString('Older list', $first);

        $second = $this->fetch(collection_list::SORT_RECENT, 1, 1);
        $this->assertStringContainsString('Older list', $second);
        $this->assertStringNotContainsString('Newer list', $second);
    }

    /**
     * A negative offset is floored rather than handed to the database.
     *
     * @return void
     */
    public function test_a_negative_offset_is_floored(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $this->make_public_collection((int) $owner->id, 'Published list');

        $this->setUser(null);

        $this->assertStringContainsString(
            'Published list',
            $this->fetch(collection_list::SORT_RECENT, collection_list::PERPAGE, -5)
        );
    }

    /**
     * Each layout is rendered in its own container, and an unknown one is the
     * grid rather than an error.
     *
     * @return void
     */
    public function test_the_layout_argument_chooses_the_renderer(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $this->make_public_collection((int) $owner->id, 'Published list');

        $this->setUser(null);

        $cards = $this->fetch(collection_list::SORT_RECENT, collection_list::PERPAGE, 0, collection_list::LAYOUT_CARDS);
        $this->assertStringContainsString('oerexchange-collections row', $cards);

        $list = $this->fetch(collection_list::SORT_RECENT, collection_list::PERPAGE, 0, collection_list::LAYOUT_LIST);
        $this->assertStringContainsString('oerexchange-collections-list', $list);

        $unknown = $this->fetch(collection_list::SORT_RECENT, collection_list::PERPAGE, 0, 'carousel');
        $this->assertStringContainsString('oerexchange-collections row', $unknown);
    }

    /**
     * An unknown sort key is answered with the default order, not with an
     * exception: the caller's string never reaches an ORDER BY.
     *
     * @return void
     */
    public function test_an_unknown_sort_is_answered_with_the_default_order(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $this->make_public_collection((int) $owner->id, 'Newer list', 0);
        $this->make_public_collection((int) $owner->id, 'Older list', DAYSECS);

        $this->setUser(null);
        $html = $this->fetch('alphabetical');

        $this->assertLessThan(strpos($html, 'Older list'), strpos($html, 'Newer list'));
    }

    /**
     * Starring a public collection persists the star and reports the state
     * the store actually holds.
     *
     * @return void
     */
    public function test_starring_a_collection_persists_and_reports_the_count(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $reader = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Starrable list');

        $this->setUser($reader);
        $result = set_collection_star::execute((int) $collection->id, true);

        $this->assertTrue($result['starred']);
        $this->assertSame(1, $result['count']);
        $this->assertTrue(star_manager::is_starred(
            (int) $collection->id,
            (int) $reader->id,
            star_manager::ITEMTYPE_COLLECTION
        ));

        // The count is everybody's stars, not the caller's own.
        $this->setUser($other);
        $second = set_collection_star::execute((int) $collection->id, true);
        $this->assertSame(2, $second['count']);

        // Unstarring removes only this caller's.
        $removed = set_collection_star::execute((int) $collection->id, false);
        $this->assertFalse($removed['starred']);
        $this->assertSame(1, $removed['count']);
    }

    /**
     * Starring twice neither fails nor double-counts.
     *
     * @return void
     */
    public function test_starring_twice_neither_fails_nor_double_counts(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $reader = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Starrable list');

        $this->setUser($reader);
        set_collection_star::execute((int) $collection->id, true);
        $result = set_collection_star::execute((int) $collection->id, true);

        $this->assertTrue($result['starred']);
        $this->assertSame(1, $result['count']);
    }

    /**
     * A guest is refused, with the reason that names the missing sign-in.
     *
     * A guest has a real user id, so isloggedin() alone would let them write.
     *
     * @return void
     */
    public function test_a_guest_may_not_star_a_collection(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Starrable list');

        $this->setGuestUser();
        try {
            set_collection_star::execute((int) $collection->id, true);
            $this->fail('a guest must not be able to star a collection');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_notloggedin', $e->errorcode);
        }

        $this->assertSame(0, star_manager::star_count((int) $collection->id, star_manager::ITEMTYPE_COLLECTION));
    }

    /**
     * A caller with no session is refused one layer earlier, by
     * validate_context()'s require_login().
     *
     * Pinning which layer refuses records that the plugin's own guard is
     * really there for the guest case, so a later change that moves
     * validate_context() shows up as a failure rather than passing silently.
     *
     * @return void
     */
    public function test_a_logged_out_caller_is_refused_by_the_context_check(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Starrable list');

        $this->setUser(null);
        try {
            set_collection_star::execute((int) $collection->id, true);
            $this->fail('a logged-out caller must not be able to star a collection');
        } catch (\moodle_exception $e) {
            $this->assertInstanceOf(\require_login_exception::class, $e);
            $this->assertSame('requireloginerror', $e->errorcode);
        }

        $this->assertSame(0, star_manager::star_count((int) $collection->id, star_manager::ITEMTYPE_COLLECTION));
    }

    /**
     * Somebody else's draft is refused as "not found", and so is an id that
     * was never used — with the SAME error code, so the reply cannot be used
     * to confirm that a draft exists.
     *
     * @return void
     */
    public function test_a_draft_and_an_unknown_id_are_refused_identically(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $draft = $this->make_collection((int) $owner->id, 'Unpublished thoughts');
        $unknown = (int) $DB->get_field_sql('SELECT MAX(id) FROM {' . collection_manager::TABLE . '}') + 1000;

        $this->setUser($stranger);

        $codes = [];
        foreach ([(int) $draft->id, $unknown] as $id) {
            try {
                set_collection_star::execute($id, true);
                $this->fail('starring collection ' . $id . ' should have been refused');
            } catch (\moodle_exception $e) {
                $codes[] = $e->errorcode;
            }
        }

        $this->assertSame(['error_collectionnotfound', 'error_collectionnotfound'], $codes);
        $this->assertSame(0, star_manager::star_count((int) $draft->id, star_manager::ITEMTYPE_COLLECTION));
    }

    /**
     * Even the owner cannot star their own draft. They can see it, so it is
     * not an error, but every starred list is public-only and the star would
     * be shown nowhere, so the call answers with the unchanged state.
     *
     * @return void
     */
    public function test_the_owner_cannot_star_their_own_draft(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $draft = $this->make_collection((int) $owner->id, 'Unpublished thoughts');

        $this->setUser($owner);
        $result = set_collection_star::execute((int) $draft->id, true);

        $this->assertFalse($result['starred']);
        $this->assertSame(0, $result['count']);
    }

    /**
     * An unlisted collection is visible to anybody with its link but cannot
     * be starred: the star would appear in no starred list (they are
     * public-only), so it is refused as the unchanged state, not an error.
     * A star already held, from while it was public, can still be removed.
     *
     * @return void
     */
    public function test_an_unlisted_collection_is_not_starrable(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $collection = $this->make_collection((int) $owner->id, 'Shared by link', [
            'visibility' => collection_manager::VISIBILITY_UNLISTED,
            'timepublished' => time(),
        ]);

        $this->setUser($stranger);
        $result = set_collection_star::execute((int) $collection->id, true);
        $this->assertFalse($result['starred']);

        // Starred while public, then unlisted: removing it still works.
        collection_manager::set_visibility((int) $collection->id, collection_manager::VISIBILITY_PUBLIC);
        $this->assertTrue(set_collection_star::execute((int) $collection->id, true)['starred']);
        collection_manager::set_visibility((int) $collection->id, collection_manager::VISIBILITY_UNLISTED);
        $this->assertFalse(set_collection_star::execute((int) $collection->id, false)['starred']);
    }

    /**
     * A tombstoned collection cannot be starred: user_can_view_collection()
     * answers false for it, even though its page still renders.
     *
     * @return void
     */
    public function test_a_tombstoned_collection_cannot_be_starred(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $reader = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Deleted list');
        collection_manager::delete((int) $collection->id);

        $this->setUser($reader);
        try {
            set_collection_star::execute((int) $collection->id, true);
            $this->fail('a tombstoned collection must not be starrable');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_collectionnotfound', $e->errorcode);
        }

        $this->assertSame(0, star_manager::star_count((int) $collection->id, star_manager::ITEMTYPE_COLLECTION));
    }

    /**
     * A collection a moderator is holding is refused for everybody but its
     * owner — the same "not found" the draft gets.
     *
     * @return void
     */
    public function test_a_held_collection_is_refused_for_a_stranger(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Held list');
        collection_manager::set_modhidden((int) $collection->id, true, 2);

        $this->setUser($stranger);
        try {
            set_collection_star::execute((int) $collection->id, true);
            $this->fail('a held collection must not be starrable by a stranger');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_collectionnotfound', $e->errorcode);
        }

        // The owner may see it, so it is not an error for them, but a held
        // collection is not publicly listable, so the star is not added.
        $this->setUser($owner);
        $this->assertFalse(set_collection_star::execute((int) $collection->id, true)['starred']);
    }

    /**
     * Both functions describe themselves well enough for core's own
     * parameter and return validation, which is what the AJAX layer applies.
     *
     * @return void
     */
    public function test_both_functions_declare_validatable_structures(): void {
        $this->assertInstanceOf(
            \core_external\external_function_parameters::class,
            get_collections::execute_parameters()
        );
        $this->assertInstanceOf(
            \core_external\external_single_structure::class,
            get_collections::execute_returns()
        );
        $this->assertInstanceOf(
            \core_external\external_function_parameters::class,
            set_collection_star::execute_parameters()
        );
        $this->assertInstanceOf(
            \core_external\external_single_structure::class,
            set_collection_star::execute_returns()
        );
    }
}
