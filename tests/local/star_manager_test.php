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
 * Pins the contract star_manager puts on top of core's favourites subsystem.
 *
 * The class stores nothing of its own, so what is worth testing is not "does a
 * row appear" but the four things the wrapper adds over the raw subsystem, each
 * of which has a way of going wrong that a caller would never see coming:
 *
 * - Idempotence. The repository's add() inserts unconditionally, so a
 *   double-submitted star hits {favourite}'s uniqueuserfavouriteitem index and
 *   raises a dml_write_exception instead of doing nothing; delete_favourite()
 *   throws outright when the favourite is not there. Both directions of
 *   set_starred() must be no-ops, and one test here deliberately provokes the
 *   raw failure so the guard cannot be proven by an empty runner.
 * - Status filtering. starred_resources() feeds a world-readable profile page,
 *   so a star on a resource that has since been hidden, taken down or deleted
 *   must not resurface it there — while all_starred_ids(), which feeds the
 *   privacy export, must report exactly those same stars, because
 *   under-reporting a subject access request is the opposite failure.
 * - Ordering. The subsystem's finder passes an empty sort, so "most recently
 *   starred first" is this class's promise, not core's.
 * - Identity. Stars are per user, and a guest or a logged-out visitor has none
 *   even if a row exists against that userid.
 *
 * Every favourite row here is keyed on the system context, which is where this
 * platform's resources live.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(star_manager::class)]
final class star_manager_test extends \advanced_testcase {
    /**
     * Insert a catalogue row directly.
     *
     * There is no generator for this plugin, and publish() would need a real
     * .mbz and a registered site, neither of which starring cares about.
     *
     * @param array $overrides field values to replace in the default row
     * @return \stdClass the inserted resource record
     */
    protected function make_resource(array $overrides = []): \stdClass {
        global $DB;

        $now = time();
        $record = (object) array_merge([
            'type' => 'course',
            'title' => 'Shared course',
            'summary' => '',
            'summaryformat' => FORMAT_HTML,
            'language' => 'en',
            'licenseshortname' => 'cc-4.0',
            'creatorid' => 0,
            'siteid' => null,
            'status' => 'published',
            'timeshared' => $now,
            'timemodified' => $now,
            'timefresh' => $now,
        ], $overrides);

        $id = $DB->insert_record('local_oerexchange_resources', $record);

        return $DB->get_record('local_oerexchange_resources', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Count the favourite rows this plugin owns for a resource.
     *
     * Read straight from the table rather than through star_count(), so tests
     * of other methods do not depend on the method they are checking.
     *
     * @param int $resourceid the resource the stars point at
     * @return int the number of rows found
     */
    protected function count_rows(int $resourceid): int {
        global $DB;

        return $DB->count_records('favourite', [
            'component' => star_manager::COMPONENT,
            'itemtype' => star_manager::ITEMTYPE,
            'itemid' => $resourceid,
            'contextid' => \context_system::instance()->id,
        ]);
    }

    /**
     * Backdate a star so ordering by star time is deterministic.
     *
     * Stars created within one test all land in the same second otherwise,
     * which would let a wrongly ordered result pass by luck.
     *
     * @param int $resourceid the starred resource
     * @param int $userid the user who starred it
     * @param int $time the timecreated value to force
     * @return void
     */
    protected function set_star_time(int $resourceid, int $userid, int $time): void {
        global $DB;

        $DB->set_field('favourite', 'timecreated', $time, [
            'component' => star_manager::COMPONENT,
            'itemtype' => star_manager::ITEMTYPE,
            'itemid' => $resourceid,
            'contextid' => \context_system::instance()->id,
            'userid' => $userid,
        ]);
    }

    /**
     * A resource is unstarred until the user stars it, and unstarred again after.
     *
     * @return void
     */
    public function test_is_starred_follows_the_star(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource();

        $this->assertFalse(star_manager::is_starred((int) $resource->id, (int) $user->id));

        $this->assertTrue(star_manager::set_starred((int) $resource->id, (int) $user->id, true));
        $this->assertTrue(star_manager::is_starred((int) $resource->id, (int) $user->id));
        $this->assertSame(1, $this->count_rows((int) $resource->id));

        $this->assertFalse(star_manager::set_starred((int) $resource->id, (int) $user->id, false));
        $this->assertFalse(star_manager::is_starred((int) $resource->id, (int) $user->id));
        $this->assertSame(0, $this->count_rows((int) $resource->id));
    }

    /**
     * One user's star says nothing about another user's.
     *
     * @return void
     */
    public function test_a_star_belongs_to_one_user_only(): void {
        $this->resetAfterTest();

        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource();

        star_manager::set_starred((int) $resource->id, (int) $usera->id, true);

        $this->assertTrue(star_manager::is_starred((int) $resource->id, (int) $usera->id));
        $this->assertFalse(star_manager::is_starred((int) $resource->id, (int) $userb->id));
        $this->assertSame([(int) $resource->id], star_manager::all_starred_ids((int) $usera->id));
        $this->assertSame([], star_manager::all_starred_ids((int) $userb->id));
    }

    /**
     * Starring twice must be a no-op, not a unique-index violation.
     *
     * This is the double-click case: the page posts the same star again before
     * the first response lands.
     *
     * @return void
     */
    public function test_starring_twice_is_a_no_op(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource();

        star_manager::set_starred((int) $resource->id, (int) $user->id, true);
        $this->assertTrue(star_manager::set_starred((int) $resource->id, (int) $user->id, true));

        $this->assertTrue(star_manager::is_starred((int) $resource->id, (int) $user->id));
        $this->assertSame(1, $this->count_rows((int) $resource->id), 'the second star must not add a row');
    }

    /**
     * The guard in set_starred() is load-bearing, not decorative.
     *
     * Without the existence check the same second star would reach the
     * repository's unconditional insert and blow up on
     * {favourite}'s uniqueuserfavouriteitem index. Provoking that here means
     * the idempotence test above cannot pass vacuously.
     *
     * @return void
     */
    public function test_the_raw_favourites_service_would_throw_on_the_second_star(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource();

        star_manager::set_starred((int) $resource->id, (int) $user->id, true);

        $service = \core_favourites\service_factory::get_service_for_user_context(
            \context_user::instance((int) $user->id)
        );

        $this->expectException(\dml_write_exception::class);
        $service->create_favourite(
            star_manager::COMPONENT,
            star_manager::ITEMTYPE,
            (int) $resource->id,
            \context_system::instance()
        );
    }

    /**
     * Unstarring something that was never starred must be a no-op.
     *
     * The subsystem's delete_favourite() throws a moodle_exception when the
     * favourite does not exist, so this direction needs its own guard.
     *
     * @return void
     */
    public function test_unstarring_something_never_starred_is_a_no_op(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource();

        $this->assertFalse(star_manager::set_starred((int) $resource->id, (int) $user->id, false));
        $this->assertFalse(star_manager::is_starred((int) $resource->id, (int) $user->id));
        $this->assertSame(0, $this->count_rows((int) $resource->id));

        // And again after a full star/unstar round trip, which is the real
        // double-click case for the unstar button.
        star_manager::set_starred((int) $resource->id, (int) $user->id, true);
        star_manager::set_starred((int) $resource->id, (int) $user->id, false);

        $this->assertFalse(star_manager::set_starred((int) $resource->id, (int) $user->id, false));
        $this->assertSame(0, $this->count_rows((int) $resource->id));
    }

    /**
     * star_count() is a public total across every user, not the caller's own.
     *
     * @return void
     */
    public function test_star_count_counts_every_user(): void {
        $this->resetAfterTest();

        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $userc = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource();

        $this->assertSame(0, star_manager::star_count((int) $resource->id));

        star_manager::set_starred((int) $resource->id, (int) $usera->id, true);
        star_manager::set_starred((int) $resource->id, (int) $userb->id, true);
        star_manager::set_starred((int) $resource->id, (int) $userc->id, true);

        // Counted from nobody's session in particular.
        $this->setUser($usera);
        $this->assertSame(3, star_manager::star_count((int) $resource->id));
        $this->setGuestUser();
        $this->assertSame(3, star_manager::star_count((int) $resource->id));

        star_manager::set_starred((int) $resource->id, (int) $userb->id, false);
        $this->assertSame(2, star_manager::star_count((int) $resource->id));
    }

    /**
     * star_count() must not pick up other resources or other components.
     *
     * itemid alone is not unique in {favourite}: every component numbers its
     * own items, so a course favourited by the same id would otherwise inflate
     * the figure shown next to a resource.
     *
     * @return void
     */
    public function test_star_count_is_scoped_to_this_component_and_resource(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource();
        $other = $this->make_resource();

        star_manager::set_starred((int) $resource->id, (int) $user->id, true);
        star_manager::set_starred((int) $other->id, (int) $user->id, true);

        // A core_course favourite that happens to share the resource's id.
        $DB->insert_record('favourite', (object) [
            'component' => 'core_course',
            'itemtype' => 'courses',
            'itemid' => $resource->id,
            'contextid' => \context_system::instance()->id,
            'userid' => $user->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $this->assertSame(1, star_manager::star_count((int) $resource->id));
        $this->assertSame(1, star_manager::star_count((int) $other->id));
    }

    /**
     * starred_resources() must only ever advertise published resources.
     *
     * The list is rendered on a world-readable profile page, so someone else's
     * bookmark must not keep a hidden, taken-down or deleted resource visible.
     *
     * @return void
     */
    public function test_starred_resources_returns_only_published_resources(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $published = $this->make_resource(['title' => 'Still published']);
        star_manager::set_starred((int) $published->id, (int) $user->id, true);

        foreach (['hidden', 'modhidden', 'deleted', 'removed', 'pending'] as $status) {
            $resource = $this->make_resource(['status' => $status, 'title' => "A {$status} resource"]);
            star_manager::set_starred((int) $resource->id, (int) $user->id, true);

            $ids = array_map('intval', array_keys(star_manager::starred_resources((int) $user->id)));

            $this->assertNotContains(
                (int) $resource->id,
                $ids,
                "a '{$status}' resource must not appear on the profile page"
            );
            // The star itself is still recorded — it is the listing that filters.
            $this->assertTrue(star_manager::is_starred((int) $resource->id, (int) $user->id));
        }

        $this->assertSame(
            [(int) $published->id],
            array_map('intval', array_keys(star_manager::starred_resources((int) $user->id)))
        );
    }

    /**
     * The listing is most-recently-starred first, keyed by resource id.
     *
     * The subsystem's own finder sorts by nothing at all, so this ordering is
     * entirely star_manager's doing.
     *
     * @return void
     */
    public function test_starred_resources_are_most_recently_starred_first(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $oldest = $this->make_resource(['title' => 'Starred first']);
        $middle = $this->make_resource(['title' => 'Starred second']);
        $newest = $this->make_resource(['title' => 'Starred last']);

        // Star in an order unrelated to both id order and the wanted output.
        $now = time();
        foreach ([$middle, $newest, $oldest] as $resource) {
            star_manager::set_starred((int) $resource->id, (int) $user->id, true);
        }
        $this->set_star_time((int) $oldest->id, (int) $user->id, $now - 300);
        $this->set_star_time((int) $middle->id, (int) $user->id, $now - 200);
        $this->set_star_time((int) $newest->id, (int) $user->id, $now - 100);

        $starred = star_manager::starred_resources((int) $user->id);

        $this->assertSame(
            [(int) $newest->id, (int) $middle->id, (int) $oldest->id],
            array_map('intval', array_keys($starred))
        );
        $this->assertSame($now - 100, (int) reset($starred)->timestarred);

        // Re-starring moves a resource back to the top of the list.
        star_manager::set_starred((int) $oldest->id, (int) $user->id, false);
        star_manager::set_starred((int) $oldest->id, (int) $user->id, true);
        $this->set_star_time((int) $oldest->id, (int) $user->id, $now);

        $this->assertSame(
            [(int) $oldest->id, (int) $newest->id, (int) $middle->id],
            array_map('intval', array_keys(star_manager::starred_resources((int) $user->id)))
        );
    }

    /**
     * A limit trims the newest end of the list; 0 means no limit.
     *
     * @return void
     */
    public function test_starred_resources_honours_a_limit(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $now = time();
        $resources = [];
        foreach ([0, 1, 2] as $index) {
            $resource = $this->make_resource(['title' => "Resource {$index}"]);
            star_manager::set_starred((int) $resource->id, (int) $user->id, true);
            $this->set_star_time((int) $resource->id, (int) $user->id, $now - 100 + $index);
            $resources[$index] = $resource;
        }

        $this->assertSame(
            [(int) $resources[2]->id, (int) $resources[1]->id],
            array_map('intval', array_keys(star_manager::starred_resources((int) $user->id, 2)))
        );
        $this->assertCount(3, star_manager::starred_resources((int) $user->id, 0));
    }

    /**
     * all_starred_ids() reports the user's stars whatever the resource status.
     *
     * It feeds the privacy export, where omitting a star on a resource that was
     * later hidden or taken down would under-report the subject's own data.
     *
     * @return void
     */
    public function test_all_starred_ids_ignores_resource_status(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();

        $expected = [];
        foreach (['published', 'hidden', 'modhidden', 'deleted'] as $status) {
            $resource = $this->make_resource(['status' => $status]);
            star_manager::set_starred((int) $resource->id, (int) $user->id, true);
            $expected[] = (int) $resource->id;
        }

        // A resource the user did not star, and one starred by somebody else.
        $unstarred = $this->make_resource();
        $theirs = $this->make_resource();
        star_manager::set_starred((int) $theirs->id, (int) $stranger->id, true);

        $actual = star_manager::all_starred_ids((int) $user->id);
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
        $this->assertNotContains((int) $unstarred->id, $actual);
        $this->assertNotContains((int) $theirs->id, $actual);
        // Only one of the four is fit to show on the profile page.
        $this->assertCount(1, star_manager::starred_resources((int) $user->id));
    }

    /**
     * Deleting a resource drops every user's star on it, and nobody else's.
     *
     * The stars are other people's rows in a core table, so nothing in the
     * plugin's own delete path would otherwise reach them.
     *
     * @return void
     */
    public function test_delete_for_resource_removes_every_users_star(): void {
        $this->resetAfterTest();

        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $doomed = $this->make_resource();
        $survivor = $this->make_resource();

        star_manager::set_starred((int) $doomed->id, (int) $usera->id, true);
        star_manager::set_starred((int) $doomed->id, (int) $userb->id, true);
        star_manager::set_starred((int) $survivor->id, (int) $usera->id, true);

        star_manager::delete_for_resource((int) $doomed->id);

        $this->assertSame(0, $this->count_rows((int) $doomed->id));
        $this->assertFalse(star_manager::is_starred((int) $doomed->id, (int) $usera->id));
        $this->assertFalse(star_manager::is_starred((int) $doomed->id, (int) $userb->id));
        $this->assertSame(0, star_manager::star_count((int) $doomed->id));

        $this->assertTrue(star_manager::is_starred((int) $survivor->id, (int) $usera->id));
        $this->assertSame([(int) $survivor->id], star_manager::all_starred_ids((int) $usera->id));
    }

    /**
     * Deleting stars for a resource nobody starred is a no-op, not an error.
     *
     * @return void
     */
    public function test_delete_for_resource_is_safe_when_there_are_no_stars(): void {
        $this->resetAfterTest();

        $resource = $this->make_resource();

        star_manager::delete_for_resource((int) $resource->id);

        $this->assertSame(0, $this->count_rows((int) $resource->id));
    }

    /**
     * Guests and logged-out visitors have no stars, even if a row says otherwise.
     *
     * userid 0 must never reach context_user::instance(), and a guest session is
     * shared, so a star recorded against it belongs to nobody in particular.
     *
     * @return void
     */
    public function test_a_guest_or_logged_out_visitor_has_no_stars(): void {
        global $DB;
        $this->resetAfterTest();

        $resource = $this->make_resource();
        $guestid = (int) guest_user()->id;

        // Plant rows the guards must ignore, so this cannot pass for want of data.
        foreach ([$guestid, 0] as $userid) {
            $DB->insert_record('favourite', (object) [
                'component' => star_manager::COMPONENT,
                'itemtype' => star_manager::ITEMTYPE,
                'itemid' => $resource->id,
                'contextid' => \context_system::instance()->id,
                'userid' => $userid,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        $this->assertFalse(star_manager::is_starred((int) $resource->id, $guestid));
        $this->assertFalse(star_manager::is_starred((int) $resource->id, 0));
        $this->assertSame([], star_manager::starred_resources($guestid));
        $this->assertSame([], star_manager::starred_resources(0));

        // The planted rows really are there — the guards are what rejects them.
        $this->assertSame(2, $this->count_rows((int) $resource->id));
    }
}
