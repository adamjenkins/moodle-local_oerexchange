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
 * Tests for the featured-collections strip and its three placements.
 *
 * The strip is the one part of this feature an admin can point at the public
 * landing page, so the failure that matters most is not a missing card but a
 * BLANK PAGE: PLACEMENT_INSTEAD suppresses the search form, the resource grid
 * and the paging bar, and an admin who features a collection that is later
 * unpublished or taken down must get the catalogue back rather than an empty
 * document. That contract lives in two places — strip() returning '' when it
 * has nothing to draw, and catalogue_view::render() only suppressing the
 * catalogue when the strip actually drew something — so it is asserted here
 * through render(), end to end, not by unit-testing either half.
 *
 * The other property pinned here is that a featured collection which has
 * stopped being publicly listable disappears SILENTLY. The id is stored in
 * config, so it survives the collection being unpublished, held or deleted,
 * and collections() resolves it through collection_manager::public_where()
 * precisely so the landing page cannot keep advertising something no listing
 * would show.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(featured_collections::class)]
final class featured_collections_test extends \advanced_testcase {
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
     * UNSPECIFIED MEANS DRAFT, which is the opposite of the generator's own
     * default (create_collection() makes a public collection when told
     * nothing); $state wins over both defaults.
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
     * A published resource.
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
     * Choose which collections are featured, and where.
     *
     * @param int[] $ids collection ids, in the admin's own order
     * @param string|null $placement one of featured_collections::PLACEMENTS
     */
    protected function feature(array $ids, ?string $placement = null): void {
        set_config('featuredcollections', implode(',', $ids), 'local_oerexchange');
        if ($placement !== null) {
            set_config('featuredplacement', $placement, 'local_oerexchange');
        }
    }

    /**
     * The catalogue's own search button, which is present exactly when the
     * grid and its form have NOT been suppressed.
     *
     * @return string
     */
    protected function searchbutton(): string {
        return get_string('searchbutton', 'local_oerexchange');
    }

    /**
     * Nothing is featured by default, so no strip and no suppression.
     *
     * @return void
     */
    public function test_nothing_is_featured_until_an_admin_chooses(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $this->make_public_collection((int) $owner->id, 'Not featured');

        $this->assertSame([], featured_collections::selected_ids());
        $this->assertSame([], featured_collections::collections());
        $this->assertSame('', featured_collections::strip(new \moodle_url('/local/oerexchange/index.php')));
    }

    /**
     * The chosen collections are drawn in the ADMIN's order, not the
     * database's.
     *
     * @return void
     */
    public function test_the_strip_follows_the_admins_order(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $first = $this->make_public_collection((int) $owner->id, 'Alpha list', 3 * DAYSECS);
        $second = $this->make_public_collection((int) $owner->id, 'Beta list', DAYSECS);

        // Deliberately the reverse of 'recent', so a strip that quietly used
        // the query's own order would fail here.
        $this->feature([(int) $first->id, (int) $second->id]);

        $this->assertSame(
            [(int) $first->id, (int) $second->id],
            array_keys(featured_collections::collections())
        );

        $html = featured_collections::strip(new \moodle_url('/local/oerexchange/index.php'));
        $this->assertLessThan(strpos($html, 'Beta list'), strpos($html, 'Alpha list'));
        $this->assertStringContainsString(get_string('featuredcollectionsheading', 'local_oerexchange'), $html);
    }

    /**
     * A collection that has stopped being publicly listable is skipped
     * silently — no card, no error, no fatal.
     *
     * Each of the four states is checked, because they are four different
     * columns and the clause that excludes them is shared with every other
     * listing: an admin's stale choice must not be the thing that resurrects
     * one.
     *
     * @return void
     */
    public function test_a_collection_that_stopped_being_public_is_skipped_silently(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $kept = $this->make_public_collection((int) $owner->id, 'Still public');
        $unpublished = $this->make_public_collection((int) $owner->id, 'Back to draft');
        $held = $this->make_public_collection((int) $owner->id, 'Held by a moderator');
        $deleted = $this->make_public_collection((int) $owner->id, 'Deleted since');

        $this->feature([
            (int) $kept->id,
            (int) $unpublished->id,
            (int) $held->id,
            (int) $deleted->id,
            999999,
        ]);

        collection_manager::set_visibility((int) $unpublished->id, collection_manager::VISIBILITY_DRAFT);
        collection_manager::set_modhidden((int) $held->id, true, (int) $owner->id);
        collection_manager::delete((int) $deleted->id);

        $this->assertSame([(int) $kept->id], array_keys(featured_collections::collections()));

        $html = featured_collections::strip(new \moodle_url('/local/oerexchange/index.php'));
        $this->assertStringContainsString('Still public', $html);
        $this->assertStringNotContainsString('Back to draft', $html);
        $this->assertStringNotContainsString('Held by a moderator', $html);
        $this->assertStringNotContainsString('Deleted since', $html);
        // The id chosen in config is left alone: the admin's choice is not
        // rewritten behind their back by a page view, so republishing the
        // collection brings its card back.
        $this->assertContains((int) $unpublished->id, featured_collections::selected_ids());
        collection_manager::set_visibility((int) $unpublished->id, collection_manager::VISIBILITY_PUBLIC);
        $this->assertStringContainsString(
            'Back to draft',
            featured_collections::strip(new \moodle_url('/local/oerexchange/index.php'))
        );
    }

    /**
     * 'above' puts the strip before the search form; 'below' puts it after
     * the paging bar. Both keep the catalogue.
     *
     * @return void
     */
    public function test_placement_above_and_below_keep_the_catalogue(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Featured list');
        $this->generator()->create_resource([
            'title' => 'An ordinary resource',
            'status' => resource_manager::STATUS_PUBLISHED,
        ]);

        $baseurl = new \moodle_url('/local/oerexchange/index.php');

        $this->feature([(int) $collection->id], featured_collections::PLACEMENT_ABOVE);
        $above = (new catalogue_view('', '', '', '', 0))->render($baseurl);
        $this->assertStringContainsString('An ordinary resource', $above);
        $this->assertStringContainsString($this->searchbutton(), $above);
        $this->assertLessThan(strpos($above, $this->searchbutton()), strpos($above, 'Featured list'));

        $this->feature([(int) $collection->id], featured_collections::PLACEMENT_BELOW);
        $below = (new catalogue_view('', '', '', '', 0))->render($baseurl);
        $this->assertStringContainsString('An ordinary resource', $below);
        $this->assertStringContainsString($this->searchbutton(), $below);
        $this->assertGreaterThan(strpos($below, $this->searchbutton()), strpos($below, 'Featured list'));
    }

    /**
     * 'instead' suppresses the search form, the grid and the paging bar, and
     * ALWAYS renders a way into the full catalogue.
     *
     * The link carries catalogue_view::PARAM_FULL, because the placement
     * applies to every catalogue surface including the one the visitor is
     * already on: without it the link would land them on another copy of the
     * strip.
     *
     * @return void
     */
    public function test_placement_instead_suppresses_the_grid_but_still_links_to_it(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Featured list');
        $this->generator()->create_resource([
            'title' => 'An ordinary resource',
            'status' => resource_manager::STATUS_PUBLISHED,
        ]);

        $this->feature([(int) $collection->id], featured_collections::PLACEMENT_INSTEAD);
        $html = (new catalogue_view('', '', '', '', 0))->render(new \moodle_url('/local/oerexchange/index.php'));

        $this->assertStringContainsString('Featured list', $html);
        $this->assertStringNotContainsString('An ordinary resource', $html);
        $this->assertStringNotContainsString($this->searchbutton(), $html);
        $this->assertStringContainsString(get_string('featuredbrowseall', 'local_oerexchange'), $html);
        $this->assertStringContainsString(catalogue_view::PARAM_FULL . '=1', $html);
    }

    /**
     * The 'instead' escape hatch: ?full=1 brings the whole catalogue back on
     * the very surface the strip had replaced.
     *
     * @return void
     */
    public function test_the_full_parameter_overrides_the_instead_placement(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Featured list');
        $this->generator()->create_resource([
            'title' => 'An ordinary resource',
            'status' => resource_manager::STATUS_PUBLISHED,
        ]);

        $this->feature([(int) $collection->id], featured_collections::PLACEMENT_INSTEAD);
        $html = (new catalogue_view('', '', '', '', 0, true))
            ->render(new \moodle_url('/local/oerexchange/index.php'));

        $this->assertStringContainsString('An ordinary resource', $html);
        $this->assertStringContainsString($this->searchbutton(), $html);
        // The search form and the paging bar carry it onward, so the first
        // search does not bounce the visitor back to the strip.
        $this->assertStringContainsString('name="' . catalogue_view::PARAM_FULL . '"', $html);
    }

    /**
     * 'instead' with a chosen collection that is no longer public gives the
     * visitor the catalogue, NOT a blank page.
     *
     * This is the failure this arrangement exists to prevent, and it cannot
     * be seen from either half alone: strip() returns '' and render() has to
     * notice.
     *
     * @return void
     */
    public function test_instead_falls_back_to_the_catalogue_when_the_strip_is_empty(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Featured then held');
        $this->generator()->create_resource([
            'title' => 'An ordinary resource',
            'status' => resource_manager::STATUS_PUBLISHED,
        ]);

        $this->feature([(int) $collection->id], featured_collections::PLACEMENT_INSTEAD);
        collection_manager::set_modhidden((int) $collection->id, true, (int) $owner->id);

        $html = (new catalogue_view('', '', '', '', 0))->render(new \moodle_url('/local/oerexchange/index.php'));

        $this->assertStringNotContainsString('Featured then held', $html);
        $this->assertStringContainsString('An ordinary resource', $html);
        $this->assertStringContainsString($this->searchbutton(), $html);
    }

    /**
     * An unrecognised stored placement reads as 'above', never as 'instead'.
     *
     * A bad config value must not be the reason a visitor is left with no
     * resource grid.
     *
     * @return void
     */
    public function test_an_unknown_placement_falls_back_to_above(): void {
        $this->resetAfterTest();

        set_config('featuredplacement', 'sidebar', 'local_oerexchange');

        $this->assertSame(featured_collections::PLACEMENT_ABOVE, featured_collections::placement());
    }

    /**
     * The master switch, with its runtime default.
     *
     * An ABSENT config row reads as ON — the setting's declared default is 1
     * and a checkbox default only reaches the database when an admin saves
     * the page or admin_apply_default_settings() runs — while an explicit 0
     * is off.
     *
     * @return void
     */
    public function test_the_master_switch_defaults_to_on_and_an_explicit_zero_is_off(): void {
        $this->resetAfterTest();

        // Explicitly absent, which is the state a site is in before an admin
        // has ever saved this plugin's settings page.
        unset_config('collectionsenabled', 'local_oerexchange');
        $this->assertFalse(get_config('local_oerexchange', 'collectionsenabled'));
        $this->assertTrue(featured_collections::enabled());
        $this->assertTrue(featured_collections::available());

        set_config('collectionsenabled', 0, 'local_oerexchange');
        $this->assertFalse(featured_collections::enabled());
        $this->assertFalse(featured_collections::available());

        set_config('collectionsenabled', 1, 'local_oerexchange');
        $this->assertTrue(featured_collections::enabled());
    }

    /**
     * With collections switched off there is no strip and no collection
     * cards, whatever an admin chose earlier.
     *
     * @return void
     */
    public function test_switching_collections_off_empties_the_strip(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Featured list');
        $this->feature([(int) $collection->id], featured_collections::PLACEMENT_INSTEAD);

        set_config('collectionsenabled', 0, 'local_oerexchange');

        $this->assertSame([], featured_collections::collections());
        $this->assertSame('', featured_collections::strip(new \moodle_url('/local/oerexchange/index.php')));
        // And 'instead' cannot blank the catalogue through a switched-off
        // feature either.
        $html = (new catalogue_view('', '', '', '', 0))->render(new \moodle_url('/local/oerexchange/index.php'));
        $this->assertStringContainsString($this->searchbutton(), $html);
    }

    /**
     * Changing a featured collection's slug keeps it featured: the setting
     * stores ids precisely so an owner tidying their web address cannot
     * silently take it off the front page.
     *
     * @return void
     */
    public function test_a_renamed_slug_stays_featured(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Renamed later');
        $this->feature([(int) $collection->id]);

        collection_manager::update_metadata((int) $collection->id, ['slug' => 'a-tidier-address']);

        $this->assertSame([(int) $collection->id], array_keys(featured_collections::collections()));
    }

    /**
     * No more than MAX_FEATURED ids are ever drawn, and duplicates in the
     * stored list collapse.
     *
     * @return void
     */
    public function test_the_selection_is_deduplicated_and_capped(): void {
        $this->resetAfterTest();

        $slugs = [];
        for ($i = 0; $i < featured_collections::MAX_FEATURED + 3; $i++) {
            $slugs[] = 1000 + $i;
        }
        // One repeat and one blank entry, both of which a hand-edited config
        // row can hold.
        $this->feature(array_merge($slugs, [$slugs[0], '', 'not-an-id']));

        $selected = featured_collections::selected_ids();

        $this->assertCount(featured_collections::MAX_FEATURED, $selected);
        $this->assertSame(array_slice($slugs, 0, featured_collections::MAX_FEATURED), $selected);
    }

    /**
     * The admin's own heading wins; an empty one falls back to the default.
     *
     * @return void
     */
    public function test_the_heading_is_the_admins_wording_or_the_default(): void {
        $this->resetAfterTest();

        $this->assertSame(
            get_string('featuredcollectionsheading', 'local_oerexchange'),
            featured_collections::heading()
        );

        set_config('featuredheading', '   ', 'local_oerexchange');
        $this->assertSame(
            get_string('featuredcollectionsheading', 'local_oerexchange'),
            featured_collections::heading()
        );

        set_config('featuredheading', 'This term we recommend', 'local_oerexchange');
        $this->assertSame('This term we recommend', featured_collections::heading());
    }

    /**
     * A strip card shows the counts it was handed, with no per-card query and
     * no "Starred by 0".
     *
     * @return void
     */
    public function test_a_strip_card_shows_the_item_count(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'One entry');
        $resource = $this->make_resource('A resource');
        collection_manager::add_item((int) $collection->id, (int) $resource->id, (int) $owner->id);

        $this->feature([(int) $collection->id]);
        $html = featured_collections::strip(new \moodle_url('/local/oerexchange/index.php'));

        $this->assertStringContainsString(
            get_string('collectionitemcount_one', 'local_oerexchange'),
            $html
        );
        $this->assertStringNotContainsString(
            get_string('collectionstarcount', 'local_oerexchange', 0),
            $html
        );
    }

    /**
     * The admin chooser offers public collections only, labelled with their
     * curator.
     *
     * @return void
     */
    public function test_the_setting_chooser_offers_public_collections_only(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user(['firstname' => 'Cora', 'lastname' => 'Curator']);
        $public = $this->make_public_collection((int) $owner->id, 'Offered');
        $draft = $this->make_collection((int) $owner->id, 'Not offered');

        $choices = featured_collections::setting_choices();

        $this->assertArrayHasKey((int) $public->id, $choices);
        $this->assertArrayNotHasKey((int) $draft->id, $choices);
        $this->assertStringContainsString('Offered', $choices[(int) $public->id]);
        $this->assertStringContainsString('Cora Curator', $choices[(int) $public->id]);
    }
}
