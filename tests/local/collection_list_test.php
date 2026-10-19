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
 * Tests for collection_list, the /collections browse surface.
 *
 * Two properties are pinned here, and they are the two a listing page can get
 * wrong without anybody noticing.
 *
 * The first is WHICH collections reach a stranger's eyes. collection_list
 * never writes its own WHERE clause — every row it draws comes through
 * collection_manager::public_where() — so the exclusion tests below are a
 * guard on that arrangement rather than on a clause in this class: a draft,
 * an unlisted collection, one a moderator is holding and a tombstone must each
 * be absent from the cards, from the rendered markup and from the count the
 * paging bar is built from. A listing that leaks a draft leaks somebody's
 * unpublished work, and a count that disagrees with the cards produces a
 * paging bar with an empty last page.
 *
 * The second is the ORDER. Each of the three sorts is asserted on a fixture
 * built so that the three orders are all different from each other: a test
 * where "recent" and "most starred" happen to agree would pass with the sort
 * parameter ignored entirely.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(collection_list::class)]
final class collection_list_test extends \advanced_testcase {
    /**
     * This plugin's data generator.
     *
     * Wrapped in one method per test file so the generator's call shape is
     * spelled once: create_collection()/create_resource() are the two entry
     * points these tests use, and both take a record array.
     *
     * @return \component_generator_base
     */
    protected function generator(): \component_generator_base {
        return $this->getDataGenerator()->get_plugin_generator('local_oerexchange');
    }

    /**
     * A collection in a known state.
     *
     * UNSPECIFIED MEANS DRAFT here, which is the opposite of the generator's
     * own default (create_collection() makes a public collection when it is
     * told nothing). These tests turn on which collections are listable, so
     * the unpublished state has to be explicit rather than inherited — and
     * $state is merged so it wins over both defaults.
     *
     * @param int $userid the owner
     * @param string $title
     * @param array $state further columns, overriding the defaults
     * @return \stdClass the stored row
     */
    protected function make_collection(int $userid, string $title, array $state = []): \stdClass {
        return $this->generator()->create_collection($state + [
            'userid' => $userid,
            'title' => $title,
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
     * A published resource, so that an item added to a collection counts.
     *
     * @param string $title
     * @return \stdClass
     */
    protected function make_resource(string $title): \stdClass {
        return $this->generator()->create_resource([
            'title' => $title,
            'status' => resource_manager::STATUS_PUBLISHED,
        ]);
    }

    /**
     * The card titles, in the order the listing returned them.
     *
     * @param \stdClass[] $cards from get_cards()/build_cards()
     * @return string[]
     */
    protected function titles(array $cards): array {
        return array_map(fn($card) => $card->title, $cards);
    }

    /**
     * Only public, active, not-held collections are listed — in the cards, in
     * the markup and in the count.
     *
     * @return void
     */
    public function test_only_public_active_unheld_collections_are_listed(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $this->make_public_collection((int) $owner->id, 'Listed reading list');
        $this->make_collection((int) $owner->id, 'Still a draft');
        $this->make_collection((int) $owner->id, 'Shared by link only', [
            'visibility' => collection_manager::VISIBILITY_UNLISTED,
            'timepublished' => time(),
        ]);
        $this->make_collection((int) $owner->id, 'Held by a moderator', [
            'visibility' => collection_manager::VISIBILITY_PUBLIC,
            'timepublished' => time(),
            'modhidden' => 1,
            'modhiddentime' => time(),
            'modhiddenby' => (int) $owner->id,
        ]);
        $this->make_collection((int) $owner->id, 'Deleted reading list', [
            'visibility' => collection_manager::VISIBILITY_PUBLIC,
            'timepublished' => time(),
            'status' => collection_manager::STATUS_DELETED,
        ]);

        $cards = collection_list::get_cards(collection_list::SORT_RECENT);

        $this->assertSame(['Listed reading list'], $this->titles($cards));
        // The count the paging bar is built from has to agree with the cards,
        // or the listing grows an empty final page.
        $this->assertSame(1, collection_list::count_collections());

        $html = collection_list::render($cards, collection_list::LAYOUT_CARDS);
        $this->assertStringContainsString('Listed reading list', $html);
        $this->assertStringNotContainsString('Still a draft', $html);
        $this->assertStringNotContainsString('Shared by link only', $html);
        $this->assertStringNotContainsString('Held by a moderator', $html);
        $this->assertStringNotContainsString('Deleted reading list', $html);
    }

    /**
     * 'recent' is most-recently-published first.
     *
     * @return void
     */
    public function test_sort_recent_orders_by_publication_date(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $this->make_public_collection((int) $owner->id, 'Oldest', 3 * DAYSECS);
        $this->make_public_collection((int) $owner->id, 'Middle', 2 * DAYSECS);
        $this->make_public_collection((int) $owner->id, 'Newest', DAYSECS);

        $cards = collection_list::get_cards(collection_list::SORT_RECENT);

        $this->assertSame(['Newest', 'Middle', 'Oldest'], $this->titles($cards));
    }

    /**
     * 'starred' is most-starred first, and deliberately disagrees with
     * 'recent' on this fixture.
     *
     * @return void
     */
    public function test_sort_starred_orders_by_star_count(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $reader1 = $this->getDataGenerator()->create_user();
        $reader2 = $this->getDataGenerator()->create_user();

        $twostars = $this->make_public_collection((int) $owner->id, 'Two stars', 3 * DAYSECS);
        $onestar = $this->make_public_collection((int) $owner->id, 'One star', DAYSECS);
        $this->make_public_collection((int) $owner->id, 'No stars', 2 * DAYSECS);

        foreach ([$reader1, $reader2] as $reader) {
            star_manager::set_starred(
                (int) $twostars->id,
                (int) $reader->id,
                true,
                star_manager::ITEMTYPE_COLLECTION
            );
        }
        star_manager::set_starred(
            (int) $onestar->id,
            (int) $reader1->id,
            true,
            star_manager::ITEMTYPE_COLLECTION
        );

        $cards = collection_list::get_cards(collection_list::SORT_STARRED);

        $this->assertSame(['Two stars', 'One star', 'No stars'], $this->titles($cards));
        // The count on the card is everybody's stars, not the viewer's own.
        $this->assertSame(2, $cards[0]->starcount);
        $this->assertSame(0, $cards[2]->starcount);
    }

    /**
     * 'items' is most-resources first, counting only the resources a visitor
     * can actually see.
     *
     * @return void
     */
    public function test_sort_items_orders_by_visible_item_count(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $twoitems = $this->make_public_collection((int) $owner->id, 'Two resources', 3 * DAYSECS);
        $oneitem = $this->make_public_collection((int) $owner->id, 'One resource', DAYSECS);
        $this->make_public_collection((int) $owner->id, 'No resources', 2 * DAYSECS);

        $first = $this->make_resource('Cell biology');
        $second = $this->make_resource('Organic chemistry');

        collection_manager::add_item((int) $twoitems->id, (int) $first->id, (int) $owner->id);
        collection_manager::add_item((int) $twoitems->id, (int) $second->id, (int) $owner->id);
        collection_manager::add_item((int) $oneitem->id, (int) $first->id, (int) $owner->id);

        $cards = collection_list::get_cards(collection_list::SORT_ITEMS);

        $this->assertSame(['Two resources', 'One resource', 'No resources'], $this->titles($cards));
        $this->assertSame(2, $cards[0]->itemcount);
        $this->assertSame(1, $cards[1]->itemcount);
        $this->assertSame(0, $cards[2]->itemcount);
    }

    /**
     * An item whose resource has since been hidden stops being counted.
     *
     * The caption on a card must not promise more than the page then draws,
     * which is the whole reason counts_for() joins the resources table.
     *
     * @return void
     */
    public function test_an_unavailable_resource_leaves_the_card_count(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Mixed availability');
        $live = $this->make_resource('Still published');
        $pulled = $this->make_resource('Taken down later');
        collection_manager::add_item((int) $collection->id, (int) $live->id, (int) $owner->id);
        collection_manager::add_item((int) $collection->id, (int) $pulled->id, (int) $owner->id);

        $DB->set_field('local_oerexchange_resources', 'status', 'modhidden', ['id' => $pulled->id]);

        $cards = collection_list::get_cards(collection_list::SORT_RECENT);

        $this->assertSame(1, $cards[0]->itemcount);
    }

    /**
     * An unknown sort key falls back to 'recent' rather than reaching
     * collection_manager, which raises a coding_exception for one.
     *
     * @return void
     */
    public function test_an_unknown_sort_falls_back_to_recent(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $this->make_public_collection((int) $owner->id, 'Older', 2 * DAYSECS);
        $this->make_public_collection((int) $owner->id, 'Newer', DAYSECS);

        $this->assertSame(collection_list::SORT_RECENT, collection_list::normalise_sort('alphabetical'));
        $this->assertSame(
            ['Newer', 'Older'],
            $this->titles(collection_list::get_cards(collection_list::normalise_sort('alphabetical')))
        );
    }

    /**
     * Both layouts draw the same collections in their own container.
     *
     * The markers asserted are the two containers' classes, because that is
     * what the AJAX re-render swaps and what the stylesheet hangs off: a
     * render() that silently answered with the grid for every layout would
     * otherwise pass a test that only looked for the title.
     *
     * @return void
     */
    public function test_both_layouts_render_their_own_container(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $this->make_public_collection((int) $owner->id, 'Shown in both views');
        $cards = collection_list::get_cards(collection_list::SORT_RECENT);

        $grid = collection_list::render($cards, collection_list::LAYOUT_CARDS);
        $this->assertStringContainsString('oerexchange-collections row', $grid);
        $this->assertStringNotContainsString('oerexchange-collections-list', $grid);
        $this->assertStringContainsString('Shown in both views', $grid);

        $list = collection_list::render($cards, collection_list::LAYOUT_LIST);
        $this->assertStringContainsString('oerexchange-collections-list', $list);
        $this->assertStringNotContainsString('oerexchange-collections row', $list);
        $this->assertStringContainsString('Shown in both views', $list);

        // An unrecognised layout is the grid, not an exception and not a
        // blank region.
        $this->assertSame($grid, collection_list::render($cards, 'carousel'));
    }

    /**
     * An empty listing is a sentence, never a blank region.
     *
     * @return void
     */
    public function test_an_empty_listing_renders_the_empty_state(): void {
        $this->resetAfterTest();

        $empty = get_string('collectionsempty', 'local_oerexchange');
        $this->assertStringContainsString($empty, collection_list::render([], collection_list::LAYOUT_CARDS));
        $this->assertStringContainsString($empty, collection_list::render([], collection_list::LAYOUT_LIST));
    }

    /**
     * The owner's name is on the card either way; it is a LINK only when
     * their profile is published.
     *
     * An invisible profile 404s, so linking to one would be a dead end on
     * every card that user owns.
     *
     * @return void
     */
    public function test_the_byline_links_only_to_a_visible_profile(): void {
        $this->resetAfterTest();

        $shown = $this->getDataGenerator()->create_user(['firstname' => 'Vera', 'lastname' => 'Visible']);
        $hidden = $this->getDataGenerator()->create_user(['firstname' => 'Hilda', 'lastname' => 'Hidden']);
        profile_manager::get_or_create_for_user((int) $shown->id);
        profile_manager::save((int) $shown->id, [
            'slug' => 'veravisible', 'bio' => '', 'expertise' => [], 'visible' => true,
        ]);
        profile_manager::get_or_create_for_user((int) $hidden->id);
        profile_manager::save((int) $hidden->id, [
            'slug' => 'hildahidden', 'bio' => '', 'expertise' => [], 'visible' => false,
        ]);

        $this->make_public_collection((int) $shown->id, 'By a published author', DAYSECS);
        $this->make_public_collection((int) $hidden->id, 'By an unpublished author', 2 * DAYSECS);

        $cards = collection_list::get_cards(collection_list::SORT_RECENT);
        $bytitle = [];
        foreach ($cards as $card) {
            $bytitle[$card->title] = $card;
        }

        $this->assertSame('Vera Visible', $bytitle['By a published author']->ownername);
        $this->assertNotNull($bytitle['By a published author']->ownerurl);
        $this->assertStringContainsString(
            'veravisible',
            $bytitle['By a published author']->ownerurl->out(false)
        );

        $this->assertSame('Hilda Hidden', $bytitle['By an unpublished author']->ownername);
        $this->assertNull($bytitle['By an unpublished author']->ownerurl);

        // Both names are printed; only the published one is wrapped in a link
        // to its profile.
        $html = collection_list::render($cards, collection_list::LAYOUT_CARDS);
        $this->assertStringContainsString('Hilda Hidden', $html);
        $this->assertStringContainsString('Vera Visible', $html);
        $this->assertStringContainsString('/u/veravisible', $html);
        $this->assertStringNotContainsString('/u/hildahidden', $html);
    }

    /**
     * A card's URL is the real routed collection path, never a bare /c/{slug}
     * (which does not resolve — see collection_controller's class docblock).
     *
     * routed_path() prepends '/r.php' or not depending on $CFG->routerconfigured,
     * so the expectation is computed the same way rather than hardcoded.
     *
     * @return void
     */
    public function test_a_card_links_to_the_real_routed_collection_url(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Linked collection');

        $cards = collection_list::get_cards(collection_list::SORT_RECENT);
        $expected = \moodle_url::routed_path('/local_oerexchange/c/' . $collection->slug);

        $this->assertSame($expected->out(false), $cards[0]->url->out(false));
        $this->assertStringContainsString(
            '/local_oerexchange/c/' . $collection->slug,
            collection_list::render($cards, collection_list::LAYOUT_CARDS)
        );
    }

    /**
     * build_cards() renders rows a caller fetched itself — the property that
     * lets a block or a landing row reuse this renderer — and counts them in
     * one batch when the rows did not carry the counts.
     *
     * @return void
     */
    public function test_build_cards_counts_rows_that_arrive_without_counts(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Fetched elsewhere');
        $resource = $this->make_resource('A resource');
        collection_manager::add_item((int) $collection->id, (int) $resource->id, (int) $owner->id);

        // Rows from collections_for_user() carry no itemcount/starcount, which is
        // the branch under test.
        $rows = collection_manager::collections_for_user((int) $owner->id);
        $this->assertObjectNotHasProperty('itemcount', reset($rows));

        $cards = collection_list::build_cards($rows);

        $this->assertSame(['Fetched elsewhere'], $this->titles($cards));
        $this->assertSame(1, $cards[0]->itemcount);
        $this->assertSame(0, $cards[0]->starcount);
    }

    /**
     * The teaser is filtered, then flattened, then shortened — in that order.
     *
     * A multilang description must collapse to one language rather than
     * advertising both, and the markup must not reach the card as literal
     * tags or as double-escaped entities.
     *
     * @return void
     */
    public function test_the_teaser_collapses_multilang_and_strips_markup(): void {
        global $DB;
        $this->resetAfterTest();
        // The same trio multilang_rendering_test::enable_multilang() sets,
        // rather than relying on how this deployment happens to be
        // configured.
        filter_set_global_state('multilang', TEXTFILTER_ON);
        set_config('filterall', 1);
        set_config('stringfilters', 'multilang');

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Bilingual list');
        $DB->set_field(
            collection_manager::TABLE,
            'summary',
            '<p><span lang="en" class="multilang">English half</span>'
                . '<span lang="ja" class="multilang">Japanese half</span></p>',
            ['id' => $collection->id]
        );

        $cards = collection_list::get_cards(collection_list::SORT_RECENT);

        $this->assertStringContainsString('English half', $cards[0]->teaser);
        $this->assertStringNotContainsString('Japanese half', $cards[0]->teaser);
        $this->assertStringNotContainsString('<p>', $cards[0]->teaser);
        $this->assertStringNotContainsString('multilang', $cards[0]->teaser);
    }

    /**
     * The sort form carries the rest of the query string, so changing the
     * sort does not silently drop the page's other parameters.
     *
     * @return void
     */
    public function test_the_sort_form_keeps_the_other_query_parameters(): void {
        $this->resetAfterTest();

        $baseurl = new \moodle_url('/local_oerexchange/collections', [
            collection_list::PARAM_SORT => collection_list::SORT_STARRED,
            collection_list::PARAM_LAYOUT => collection_list::LAYOUT_LIST,
            'page' => 2,
        ]);

        $form = collection_list::render_sort_form(
            $baseurl,
            collection_list::SORT_STARRED,
            'oerexchange-collections-page',
            collection_list::LAYOUT_LIST
        );

        $this->assertStringContainsString('name="page"', $form);
        $this->assertStringContainsString('value="2"', $form);
        // The two controls own their parameters; a hidden copy would fight
        // the select on submit.
        $this->assertStringNotContainsString('type="hidden" name="' . collection_list::PARAM_SORT . '"', $form);
        $this->assertStringNotContainsString('type="hidden" name="' . collection_list::PARAM_LAYOUT . '"', $form);
        $this->assertStringContainsString('data-target="oerexchange-collections-page"', $form);
    }
}
