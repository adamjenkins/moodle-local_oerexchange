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
 * "Show this resource only as part of its collections".
 *
 * The rule pinned here is the one that makes the switch safe: a resource is
 * withheld from standalone listings only while it is switched on AND the
 * resource is in at least one public, live collection. Switched on with no
 * such collection, it stays in the catalogue, so an author can never make a
 * resource reachable from nowhere.
 *
 * Each standalone listing is checked through its own entry point (the
 * catalogue, its filter options, the client sites' search service, starred
 * lists), because each builds its own query around
 * resource_manager::standalone_where() and could leave it out.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(resource_manager::class)]
final class collection_only_test extends \advanced_testcase {
    /**
     * This plugin's data generator.
     *
     * @return \local_oerexchange_generator
     */
    protected function generator(): \local_oerexchange_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_oerexchange');
    }

    /**
     * Ids of the resources the catalogue lists for a query.
     *
     * @param string $query
     * @return int[]
     */
    protected function catalogue_ids(string $query = ''): array {
        return array_map('intval', array_keys((new catalogue_view($query, 'course'))->get_resources()));
    }

    /**
     * A collection-only resource in a public collection, and an ordinary one.
     *
     * @param string $visibility the collection's visibility
     * @return \stdClass[] [the collection-only resource, the ordinary one, the collection]
     */
    protected function make_pair(string $visibility = collection_manager::VISIBILITY_PUBLIC): array {
        $owner = $this->getDataGenerator()->create_user();
        $only = $this->generator()->create_resource(['title' => 'Kelp forest unit', 'collectiononly' => 1,
            'licenseshortname' => 'cc-by-nc-4.0']);
        $plain = $this->generator()->create_resource(['title' => 'Kelp quiz']);
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id, 'visibility' => $visibility]);
        collection_manager::add_item((int) $collection->id, (int) $only->id, (int) $owner->id);

        return [$only, $plain, $collection];
    }

    /**
     * In a public collection, it leaves the catalogue; the other resource stays.
     *
     * @return void
     */
    public function test_it_leaves_the_catalogue_while_in_a_public_collection(): void {
        $this->resetAfterTest();
        [$only, $plain] = $this->make_pair();

        $ids = $this->catalogue_ids('Kelp');

        $this->assertNotContains((int) $only->id, $ids);
        $this->assertContains((int) $plain->id, $ids);
        $this->assertSame(1, (new catalogue_view('Kelp', 'course'))->get_total(), 'the count must agree with the list');
    }

    /**
     * In a draft or unlisted collection only, the switch has no effect: the
     * resource would otherwise be reachable from nowhere public.
     *
     * @return void
     */
    public function test_it_stays_in_the_catalogue_when_no_public_collection_holds_it(): void {
        $this->resetAfterTest();
        [$draftonly] = $this->make_pair(collection_manager::VISIBILITY_DRAFT);
        [$unlistedonly] = $this->make_pair(collection_manager::VISIBILITY_UNLISTED);

        $ids = $this->catalogue_ids('Kelp');

        $this->assertContains((int) $draftonly->id, $ids);
        $this->assertContains((int) $unlistedonly->id, $ids);
    }

    /**
     * Taking the collection down, or deleting it, brings the resource back.
     *
     * @return void
     */
    public function test_it_comes_back_when_its_collection_is_taken_down_or_deleted(): void {
        $this->resetAfterTest();
        [$only, , $collection] = $this->make_pair();
        $this->assertNotContains((int) $only->id, $this->catalogue_ids('Kelp'));

        collection_manager::set_modhidden((int) $collection->id, true, 2);
        $this->assertContains((int) $only->id, $this->catalogue_ids('Kelp'));

        collection_manager::set_modhidden((int) $collection->id, false, 2);
        collection_manager::delete((int) $collection->id);
        $this->assertContains((int) $only->id, $this->catalogue_ids('Kelp'));
    }

    /**
     * Switched off, an item in a public collection is in the catalogue as usual.
     *
     * @return void
     */
    public function test_switching_it_off_restores_the_catalogue_entry(): void {
        $this->resetAfterTest();
        [$only] = $this->make_pair();

        resource_manager::set_collection_only((int) $only->id, false);

        $this->assertContains((int) $only->id, $this->catalogue_ids('Kelp'));
    }

    /**
     * The licence filter does not offer a value only collection-only
     * resources carry: choosing it would list nothing.
     *
     * @return void
     */
    public function test_filter_options_follow_the_same_rule(): void {
        $this->resetAfterTest();
        $this->make_pair();

        $html = (new catalogue_view())->render(new \moodle_url('/local/oerexchange/index.php'));

        $this->assertStringNotContainsString('value="cc-by-nc-4.0"', $html);
    }

    /**
     * Client sites search the catalogue through the search service, which
     * follows the same rule.
     *
     * @return void
     */
    public function test_the_search_service_leaves_it_out(): void {
        $this->resetAfterTest();
        [$only, $plain] = $this->make_pair();
        $this->setAdminUser();

        $result = \local_oerexchange\external\search::execute('Kelp');
        $ids = array_map(fn($r) => (int) $r['id'], $result['results']);

        $this->assertNotContains((int) $only->id, $ids);
        $this->assertContains((int) $plain->id, $ids);
        $this->assertSame(1, $result['total']);
    }

    /**
     * A starred list is a standalone listing on a public profile.
     *
     * @return void
     */
    public function test_starred_lists_leave_it_out(): void {
        $this->resetAfterTest();
        [$only, $plain] = $this->make_pair();
        $reader = $this->getDataGenerator()->create_user();
        star_manager::set_starred((int) $only->id, (int) $reader->id, true);
        star_manager::set_starred((int) $plain->id, (int) $reader->id, true);

        $ids = array_map('intval', array_keys(star_manager::starred_resources((int) $reader->id)));

        $this->assertSame([(int) $plain->id], $ids);
    }

    /**
     * The helpers the resource page uses to offer the switch and to say
     * where the resource belongs.
     *
     * @return void
     */
    public function test_collection_membership_helpers(): void {
        $this->resetAfterTest();
        [$only, $plain, $collection] = $this->make_pair();
        $owner = $this->getDataGenerator()->create_user();
        $draft = $this->generator()->create_collection(['userid' => (int) $owner->id]);
        collection_manager::add_item((int) $draft->id, (int) $plain->id, (int) $owner->id);

        $this->assertTrue(resource_manager::is_in_any_collection((int) $only->id));
        $this->assertTrue(resource_manager::is_in_any_collection((int) $plain->id), 'a draft collection counts as collected');
        $this->assertSame([(int) $collection->id], array_keys(resource_manager::public_collections_containing((int) $only->id)));
        $this->assertSame([], resource_manager::public_collections_containing((int) $plain->id));
    }
}
