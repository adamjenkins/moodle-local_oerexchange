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
 * Tests for the BLENDED catalogue: one page of cards drawn from two tables.
 *
 * The blend is done in PHP rather than in SQL (catalogue_view::load_page()
 * explains why — a cross-dialect UNION would pass here and fail on a user's
 * PostgreSQL site), and that choice moves three things out of the database's
 * hands and into ours, so each of them is pinned here:
 *
 * - THE MERGE ORDER. Two sort columns, timeshared and timepublished, read as
 *   one sequence. A card appearing in the wrong half of the page is the
 *   cheapest possible bug to introduce and the hardest to see.
 * - PAGING ACROSS THE BOUNDARY. Each side fetches (page + 1) * PERPAGE rows
 *   and the merge is then sliced, so the row that lands on page 2 is the one
 *   test nothing else can stand in for: a bound computed per side rather than
 *   for the merge would drop or duplicate exactly this row. The fixture below
 *   puts a COLLECTION there deliberately.
 * - THE TOTAL. The paging bar is built from get_total(), which has to sum both
 *   sides: a total counting only resources produces a page the visitor cannot
 *   reach, and one counting collections twice produces an empty final page.
 *
 * The filter rules are asserted too, including the one that is a decision
 * rather than a mechanism: setting a licence or a language excludes
 * collections entirely, because a collection has neither.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(catalogue_view::class)]
final class catalogue_view_blended_test extends \advanced_testcase {
    /** @var \moodle_url the URL the catalogue is served from in these tests */
    protected \moodle_url $baseurl;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->baseurl = new \moodle_url('/local/oerexchange/index.php');
    }

    /**
     * This plugin's data generator.
     *
     * @return \component_generator_base
     */
    protected function generator(): \component_generator_base {
        return $this->getDataGenerator()->get_plugin_generator('local_oerexchange');
    }

    /**
     * A published resource, shared at a controlled moment.
     *
     * timeshared is written afterwards rather than passed, so these tests do
     * not depend on which columns the generator chooses to accept.
     *
     * @param string $title
     * @param int $timeshared
     * @param array $fields further columns to overwrite (type, licenseshortname, language)
     * @return \stdClass the stored row
     */
    protected function make_resource(string $title, int $timeshared, array $fields = []): \stdClass {
        return $this->generator()->create_resource($fields + [
            'title' => $title,
            'status' => resource_manager::STATUS_PUBLISHED,
            'timeshared' => $timeshared,
        ]);
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
     * A public collection, published at a controlled moment.
     *
     * @param int $userid
     * @param string $title
     * @param int $timepublished
     * @param array $state further columns to overwrite
     * @return \stdClass
     */
    protected function make_public_collection(
        int $userid,
        string $title,
        int $timepublished,
        array $state = []
    ): \stdClass {
        return $this->make_collection($userid, $title, $state + [
            'visibility' => collection_manager::VISIBILITY_PUBLIC,
            'timepublished' => $timepublished,
        ]);
    }

    /**
     * Assert that each title appears in the markup, in this order.
     *
     * @param string[] $titles expected order
     * @param string $html
     */
    protected function assert_card_order(array $titles, string $html): void {
        $positions = [];
        foreach ($titles as $title) {
            $at = strpos($html, $title);
            $this->assertNotFalse($at, "'{$title}' is not on the page at all");
            $positions[$title] = $at;
        }
        $sorted = $positions;
        asort($sorted);
        $this->assertSame(
            $titles,
            array_keys($sorted),
            'the merged page is not in the expected order: ' . json_encode($positions)
        );
    }

    /**
     * Resources and collections interleave by date in one sequence.
     *
     * The fixture alternates the two kinds deliberately: a page that drew one
     * table and then the other would pass any assertion that only checked
     * membership.
     *
     * @return void
     */
    public function test_the_two_kinds_interleave_by_date(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $now = time();

        $this->make_resource('Newest resource', $now - 100);
        $this->make_public_collection((int) $owner->id, 'Second newest collection', $now - 200);
        $this->make_resource('Third resource', $now - 300);
        $this->make_public_collection((int) $owner->id, 'Oldest collection', $now - 400);

        $view = new catalogue_view('', '', '', '', 0);

        $this->assertSame(4, $view->get_total());
        $this->assert_card_order(
            ['Newest resource', 'Second newest collection', 'Third resource', 'Oldest collection'],
            $view->render($this->baseurl)
        );
    }

    /**
     * A collection can be the row that lands on page 2, and it is neither
     * dropped nor repeated.
     *
     * 21 rows over a 20-card page: the oldest of them is a collection, so the
     * slice has to be taken from the MERGE rather than from either side.
     *
     * @return void
     */
    public function test_paging_crosses_the_resource_collection_boundary(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $base = time() - 10000;

        // 19 resources, oldest first so the newest ends up highest.
        for ($i = 0; $i < 19; $i++) {
            $this->make_resource('Resource number ' . $i, $base + 100 + ($i * 10));
        }
        $this->make_public_collection((int) $owner->id, 'Newest collection', $base + 100 + (19 * 10));
        $this->make_public_collection((int) $owner->id, 'Oldest collection', $base);

        $first = new catalogue_view('', '', '', '', 0);
        $second = new catalogue_view('', '', '', '', 1);

        // Both pages agree about the total, which is what the paging bar is
        // drawn from.
        $this->assertSame(21, $first->get_total());
        $this->assertSame(21, $second->get_total());

        // Page 0 is exactly PERPAGE cards: the newest collection and all 19
        // resources. Counted separately rather than merged with '+', because
        // the two arrays are keyed by their own tables' ids and those
        // collide.
        $firsthtml = $first->render($this->baseurl);
        $this->assertSame(
            catalogue_view::PERPAGE,
            count($first->get_resources()) + count($first->get_collections())
        );
        $this->assertStringContainsString('Newest collection', $firsthtml);
        $this->assertStringNotContainsString('Oldest collection', $firsthtml);
        $this->assertStringContainsString('Resource number 0', $firsthtml);
        $this->assertStringContainsString('Resource number 18', $firsthtml);

        $secondhtml = $second->render($this->baseurl);
        $this->assertStringContainsString('Oldest collection', $secondhtml);
        $this->assertStringNotContainsString('Newest collection', $secondhtml);
        $this->assertSame([], $second->get_resources());
        $this->assertCount(1, $second->get_collections());
    }

    /**
     * type=collection lists collections only.
     *
     * @return void
     */
    public function test_the_collection_type_filter_excludes_resources(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $this->make_resource('An ordinary resource', time() - 100);
        $this->make_public_collection((int) $owner->id, 'A reading list', time() - 200);

        $view = new catalogue_view('', catalogue_view::TYPE_COLLECTION, '', '', 0);
        $html = $view->render($this->baseurl);

        $this->assertSame(1, $view->get_total());
        $this->assertSame([], $view->get_resources());
        $this->assertStringContainsString('A reading list', $html);
        $this->assertStringNotContainsString('An ordinary resource', $html);
    }

    /**
     * A resource type filter excludes collections.
     *
     * @return void
     */
    public function test_a_resource_type_filter_excludes_collections(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $this->make_resource('A whole course', time() - 100, ['type' => 'course']);
        $this->make_public_collection((int) $owner->id, 'A reading list', time() - 200);

        $view = new catalogue_view('', 'course', '', '', 0);
        $html = $view->render($this->baseurl);

        $this->assertSame(1, $view->get_total());
        $this->assertSame([], $view->get_collections());
        $this->assertStringContainsString('A whole course', $html);
        $this->assertStringNotContainsString('A reading list', $html);
    }

    /**
     * Setting a licence excludes collections, which have none.
     *
     * @return void
     */
    public function test_a_licence_filter_excludes_collections(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $this->make_resource('Openly licensed course', time() - 100, ['licenseshortname' => 'cc-4.0']);
        $this->make_public_collection((int) $owner->id, 'A reading list', time() - 200);

        $view = new catalogue_view('', '', 'cc-4.0', '', 0);
        $html = $view->render($this->baseurl);

        $this->assertSame(1, $view->get_total());
        $this->assertSame([], $view->get_collections());
        $this->assertStringContainsString('Openly licensed course', $html);
        $this->assertStringNotContainsString('A reading list', $html);
    }

    /**
     * Setting a language excludes collections too, for the same reason.
     *
     * @return void
     */
    public function test_a_language_filter_excludes_collections(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $this->make_resource('A course in English', time() - 100, ['language' => 'en']);
        $this->make_public_collection((int) $owner->id, 'A reading list', time() - 200);

        $view = new catalogue_view('', '', '', 'en', 0);
        $html = $view->render($this->baseurl);

        $this->assertSame(1, $view->get_total());
        $this->assertSame([], $view->get_collections());
        $this->assertStringContainsString('A course in English', $html);
        $this->assertStringNotContainsString('A reading list', $html);
    }

    /**
     * A free-text search matches collections by title and by description,
     * alongside the resources it matches.
     *
     * @return void
     */
    public function test_a_search_matches_both_kinds(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $this->make_resource('Photosynthesis explained', time() - 100);
        $this->make_public_collection((int) $owner->id, 'Photosynthesis reading list', time() - 200);
        $bysummary = $this->make_public_collection((int) $owner->id, 'Biology miscellany', time() - 300);
        $DB->set_field(
            collection_manager::TABLE,
            'summary',
            'Everything we have on photosynthesis.',
            ['id' => $bysummary->id]
        );
        $this->make_public_collection((int) $owner->id, 'Unrelated list', time() - 400);

        $view = new catalogue_view('photosynthesis', '', '', '', 0);
        $html = $view->render($this->baseurl);

        $this->assertSame(3, $view->get_total());
        $this->assertStringContainsString('Photosynthesis explained', $html);
        $this->assertStringContainsString('Photosynthesis reading list', $html);
        $this->assertStringContainsString('Biology miscellany', $html);
        $this->assertStringNotContainsString('Unrelated list', $html);
    }

    /**
     * Drafts, unlisted collections, held collections and tombstones never
     * reach the catalogue — not in the cards, and not in the total.
     *
     * Each of the four is a different column, and the catalogue inherits the
     * rule from collection_manager rather than restating it, so this is the
     * test that would catch a blended query that stopped going through
     * public_where().
     *
     * @return void
     */
    public function test_unlistable_collections_never_appear(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $now = time();

        $this->make_public_collection((int) $owner->id, 'Published list', $now - 100);
        $this->make_collection((int) $owner->id, 'Draft list');
        $this->make_collection((int) $owner->id, 'Unlisted list', [
            'visibility' => collection_manager::VISIBILITY_UNLISTED,
            'timepublished' => $now - 200,
        ]);
        $this->make_public_collection((int) $owner->id, 'Held list', $now - 300, [
            'modhidden' => 1,
            'modhiddentime' => $now,
            'modhiddenby' => (int) $owner->id,
        ]);
        $this->make_public_collection((int) $owner->id, 'Deleted list', $now - 400, [
            'status' => collection_manager::STATUS_DELETED,
        ]);

        $view = new catalogue_view('', '', '', '', 0);
        $html = $view->render($this->baseurl);

        $this->assertSame(1, $view->get_total());
        $this->assertStringContainsString('Published list', $html);
        $this->assertStringNotContainsString('Draft list', $html);
        $this->assertStringNotContainsString('Unlisted list', $html);
        $this->assertStringNotContainsString('Held list', $html);
        $this->assertStringNotContainsString('Deleted list', $html);

        // Not through the type filter either, which is the obvious way to ask
        // for collections specifically.
        $collectionsonly = new catalogue_view('', catalogue_view::TYPE_COLLECTION, '', '', 0);
        $this->assertSame(1, $collectionsonly->get_total());
        $this->assertSame(
            ['Published list'],
            array_values(array_map(fn($c) => $c->title, $collectionsonly->get_collections()))
        );
    }

    /**
     * With collections switched off the catalogue is resources only, and the
     * type filter stops offering a kind the site does not list.
     *
     * @return void
     */
    public function test_switching_collections_off_removes_them_and_their_filter(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $this->make_resource('An ordinary resource', time() - 100);
        $this->make_public_collection((int) $owner->id, 'A reading list', time() - 200);

        $on = new catalogue_view('', '', '', '', 0);
        $onhtml = $on->render($this->baseurl);
        $this->assertSame(2, $on->get_total());
        $this->assertStringContainsString(
            'value="' . catalogue_view::TYPE_COLLECTION . '"',
            $onhtml
        );

        set_config('collectionsenabled', 0, 'local_oerexchange');

        $off = new catalogue_view('', '', '', '', 0);
        $offhtml = $off->render($this->baseurl);
        $this->assertSame(1, $off->get_total());
        $this->assertStringContainsString('An ordinary resource', $offhtml);
        $this->assertStringNotContainsString('A reading list', $offhtml);
        $this->assertStringNotContainsString(
            'value="' . catalogue_view::TYPE_COLLECTION . '"',
            $offhtml
        );

        // And asking for them by name answers with nothing rather than with
        // every resource.
        $asked = new catalogue_view('', catalogue_view::TYPE_COLLECTION, '', '', 0);
        $this->assertSame(0, $asked->get_total());
        $this->assertStringNotContainsString('An ordinary resource', $asked->render($this->baseurl));
    }

    /**
     * A collection card says what it is, links to its routed page, and shows
     * the counts its row carried.
     *
     * The kind badge is not decoration: in a blended grid it is the only
     * thing distinguishing a reading list from a single resource.
     *
     * @return void
     */
    public function test_a_collection_card_is_labelled_and_counted(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user(['firstname' => 'Cora', 'lastname' => 'Curator']);
        $collection = $this->make_public_collection((int) $owner->id, 'One entry list', time() - 100);
        $resource = $this->generator()->create_resource([
            'title' => 'A collected resource',
            'status' => resource_manager::STATUS_PUBLISHED,
        ]);
        collection_manager::add_item((int) $collection->id, (int) $resource->id, (int) $owner->id);

        $html = (new catalogue_view('', catalogue_view::TYPE_COLLECTION, '', '', 0))->render($this->baseurl);

        $this->assertStringContainsString(get_string('collection', 'local_oerexchange'), $html);
        $this->assertStringContainsString('One entry list', $html);
        $this->assertStringContainsString('Cora Curator', $html);
        $this->assertStringContainsString(get_string('collectionitemcount_one', 'local_oerexchange'), $html);
        $this->assertStringContainsString('/local_oerexchange/c/' . $collection->slug, $html);
    }

    /**
     * An empty blended catalogue says so, rather than rendering a bare grid.
     *
     * @return void
     */
    public function test_an_empty_catalogue_says_so(): void {
        $this->resetAfterTest();

        $view = new catalogue_view('', '', '', '', 0);

        $this->assertSame(0, $view->get_total());
        $this->assertStringContainsString(
            get_string('catalogueempty', 'local_oerexchange'),
            $view->render($this->baseurl)
        );
    }

    /**
     * A search that matches nothing reports the filtered empty state, not the
     * "nothing has been shared yet" one.
     *
     * @return void
     */
    public function test_a_search_matching_nothing_reports_the_filtered_empty_state(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $this->make_public_collection((int) $owner->id, 'A reading list', time() - 100);

        $view = new catalogue_view('nothingmatchesthis', '', '', '', 0);

        $this->assertSame(0, $view->get_total());
        $this->assertStringContainsString(
            get_string('nocatalogresources', 'local_oerexchange'),
            $view->render($this->baseurl)
        );
    }
}
