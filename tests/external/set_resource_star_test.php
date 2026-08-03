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
use local_oerexchange\local\resource_manager;
use local_oerexchange\local\star_manager;

/**
 * Tests for local_oerexchange_set_resource_star, the only write path a visitor's
 * own browser has into the favourites-backed star store.
 *
 * Two things are pinned here. First, that the returned pair really describes the
 * world afterwards — the button's label comes from 'starred' and its badge from
 * 'count', so a reply that does not match what was persisted leaves the page
 * lying until it is reloaded, and 'count' has to be everybody's stars rather
 * than the caller's own.
 *
 * Second, and more important, the refusals. This is a write that takes a bare
 * resource id, so it is the obvious place to probe for ids that exist: it must
 * turn away a guest, and it must give the same "not found" answer for a resource
 * the caller may not view as for one that does not exist. Each refusal is
 * asserted on its specific error code, because the function has several ways to
 * throw and a test that accepts any moodle_exception would pass on the wrong
 * one. The owner of a hidden resource is checked too — the gate is "can you view
 * it", not "is it published", and collapsing those would silently stop authors
 * starring their own work.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(set_resource_star::class)]
final class set_resource_star_test extends \advanced_testcase {
    /**
     * Insert a resource row, with the fields this function cares about
     * overridable.
     *
     * There is no data generator in this plugin, so the row is built by hand.
     * Only status and creatorid drive any behaviour under test; the rest is
     * plausible filler that satisfies the NOT NULL columns.
     *
     * @param array $overrides column => value pairs replacing the defaults
     * @return \stdClass the inserted row, as read back from the database
     */
    protected function make_resource(array $overrides = []): \stdClass {
        global $DB;
        $now = time();
        $record = (object) array_merge([
            'type' => 'course', 'title' => 'Shared course', 'summary' => '', 'summaryformat' => FORMAT_HTML,
            'language' => 'en', 'tags' => '', 'licenseshortname' => 'cc-4.0',
            'activitytype' => null, 'dataresourcetype' => null, 'courseformat' => null,
            'creatorid' => 0, 'siteid' => null, 'status' => 'published',
            'downloadcount' => 0, 'importcount' => 0, 'forkedfromid' => null,
            'trydisabled' => 0, 'trydisabledreason' => null,
            'timeshared' => $now, 'timemodified' => $now, 'timefresh' => $now, 'stalenotifiedtime' => 0,
            'modhiddentime' => 0, 'modhiddenby' => 0, 'modhiddenversionid' => null,
        ], $overrides);
        $id = $DB->insert_record('local_oerexchange_resources', $record);
        return $DB->get_record('local_oerexchange_resources', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Star a published resource and check the reply describes what was stored.
     *
     * @return void
     */
    public function test_starring_a_published_resource_persists_the_star(): void {
        $this->resetAfterTest();

        $author = $this->getDataGenerator()->create_user();
        $reader = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource(['creatorid' => $author->id]);

        $this->setUser($reader);
        $result = set_resource_star::execute((int) $resource->id, true);

        $this->assertSame(['starred' => true, 'count' => 1], $result);
        // The reply is only worth anything if the star really landed.
        $this->assertTrue(star_manager::is_starred((int) $resource->id, (int) $reader->id));
    }

    /**
     * Unstarring removes the star and reports the emptied state.
     *
     * @return void
     */
    public function test_unstarring_removes_the_star(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource(['creatorid' => $user->id]);

        $this->setUser($user);
        set_resource_star::execute((int) $resource->id, true);
        $result = set_resource_star::execute((int) $resource->id, false);

        $this->assertSame(['starred' => false, 'count' => 0], $result);
        $this->assertFalse(star_manager::is_starred((int) $resource->id, (int) $user->id));
    }

    /**
     * Repeating the same request is a no-op rather than a second star.
     *
     * The favourites table has a unique index over (component, itemtype,
     * itemid, context, user), so an unguarded second insert would come back as
     * a database write error on a double-clicked button.
     *
     * @return void
     */
    public function test_starring_twice_neither_fails_nor_double_counts(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource(['creatorid' => $user->id]);

        $this->setUser($user);
        set_resource_star::execute((int) $resource->id, true);
        $result = set_resource_star::execute((int) $resource->id, true);

        $this->assertSame(['starred' => true, 'count' => 1], $result);
    }

    /**
     * The count is everybody's stars, not just the caller's own.
     *
     * @return void
     */
    public function test_the_count_includes_other_peoples_stars(): void {
        $this->resetAfterTest();

        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();
        $three = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource(['creatorid' => $one->id]);
        $other = $this->make_resource(['creatorid' => $one->id]);

        star_manager::set_starred((int) $resource->id, (int) $two->id, true);
        star_manager::set_starred((int) $resource->id, (int) $three->id, true);
        // A star on a different resource must not leak into this resource's count.
        star_manager::set_starred((int) $other->id, (int) $two->id, true);

        $this->setUser($one);
        $result = set_resource_star::execute((int) $resource->id, true);

        $this->assertTrue($result['starred']);
        $this->assertSame(3, $result['count']);

        // And unstarring only takes the caller's own star away.
        $result = set_resource_star::execute((int) $resource->id, false);

        $this->assertFalse($result['starred']);
        $this->assertSame(2, $result['count']);
    }

    /**
     * A guest is refused, with the reason that names the missing sign-in.
     *
     * A guest has a real user id, so isloggedin() alone would let them write.
     *
     * @return void
     */
    public function test_a_guest_may_not_star(): void {
        $this->resetAfterTest();

        $resource = $this->make_resource();

        $this->setGuestUser();
        try {
            set_resource_star::execute((int) $resource->id, true);
            $this->fail('a guest must not be able to star a resource');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_notloggedin', $e->errorcode);
        }

        $this->assertSame(0, star_manager::star_count((int) $resource->id));
    }

    /**
     * A caller with no session at all is refused before the function's own check.
     *
     * validate_context() ends in require_login(), so a logged-out caller never
     * reaches the isloggedin() guard and is turned away with core's
     * 'requireloginerror' instead. That is why the plugin's own guard is really
     * there for the guest case: pinning the code here records which layer
     * actually refuses, so a later change that moves validate_context() after
     * the guard shows up as a failure rather than passing silently.
     *
     * @return void
     */
    public function test_a_logged_out_caller_is_refused_by_the_context_check(): void {
        $this->resetAfterTest();

        $resource = $this->make_resource();

        $this->setUser(null);
        try {
            set_resource_star::execute((int) $resource->id, true);
            $this->fail('a logged-out caller must not be able to star a resource');
        } catch (\moodle_exception $e) {
            $this->assertInstanceOf(\require_login_exception::class, $e);
            $this->assertSame('requireloginerror', $e->errorcode);
        }

        $this->assertSame(0, star_manager::star_count((int) $resource->id));
    }

    /**
     * A resource the caller cannot view is refused as "not found".
     *
     * Someone else's hidden resource is the shape that matters: it exists, and
     * the answer must not reveal that. The visibility gate itself is asserted
     * first, so a failure here cannot be blamed on the fixture being viewable
     * after all.
     *
     * @return void
     */
    public function test_a_resource_the_caller_cannot_view_is_refused(): void {
        $this->resetAfterTest();

        $author = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource(['creatorid' => $author->id, 'status' => 'hidden']);

        $this->setUser($stranger);
        $this->assertFalse(
            resource_manager::user_can_view_resource($resource, (int) $stranger->id),
            'fixture precondition: a stranger must not be able to view a hidden resource'
        );

        try {
            set_resource_star::execute((int) $resource->id, true);
            $this->fail('a resource the caller cannot view must not be starrable');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_notfound', $e->errorcode);
        }

        $this->assertFalse(star_manager::is_starred((int) $resource->id, (int) $stranger->id));
    }

    /**
     * The author of a hidden resource may still star it.
     *
     * This is what distinguishes "you cannot see this" from "this is not
     * published": user_can_view_resource() falls through to the edit check, so
     * the owner keeps access to their own work while it is hidden.
     *
     * @return void
     */
    public function test_the_author_may_star_their_own_hidden_resource(): void {
        $this->resetAfterTest();

        $author = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource(['creatorid' => $author->id, 'status' => 'hidden']);

        $this->setUser($author);
        $this->assertTrue(resource_manager::user_can_view_resource($resource, (int) $author->id));

        $result = set_resource_star::execute((int) $resource->id, true);

        $this->assertSame(['starred' => true, 'count' => 1], $result);
        $this->assertTrue(star_manager::is_starred((int) $resource->id, (int) $author->id));
    }

    /**
     * An unknown id is refused exactly as an unviewable one is.
     *
     * The two must be indistinguishable, otherwise the refusal itself tells
     * somebody probing ids which resources exist — the very thing the
     * visibility check is there to deny. This originally raised
     * dml_missing_record_exception ('invalidrecord', with the table name in
     * the message) while a hidden resource raised 'error_notfound'; the
     * lookup now uses IGNORE_MISSING so both take the same path.
     *
     * @return void
     */
    public function test_an_unknown_resource_id_is_refused(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $unknown = ((int) $DB->get_field_sql('SELECT MAX(id) FROM {local_oerexchange_resources}')) + 1000;

        try {
            set_resource_star::execute($unknown, true);
            $this->fail('an unknown resource id must not be starrable');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_notfound', $e->errorcode);
            $this->assertStringNotContainsString(
                'local_oerexchange_resources',
                $e->getMessage(),
                'the refusal must not name the table it looked in'
            );
        }

        $this->assertSame(0, star_manager::star_count($unknown));
    }

    /**
     * An unknown id and an unviewable one are refused identically.
     *
     * @return void
     */
    public function test_unknown_and_unviewable_refusals_are_indistinguishable(): void {
        global $DB;
        $this->resetAfterTest();

        $stranger = $this->getDataGenerator()->create_user();
        $author = $this->getDataGenerator()->create_user();
        $hidden = $this->make_resource(['status' => 'hidden', 'creatorid' => $author->id]);
        $unknown = ((int) $DB->get_field_sql('SELECT MAX(id) FROM {local_oerexchange_resources}')) + 1000;

        $this->setUser($stranger);

        $codes = [];
        foreach ([(int) $hidden->id, $unknown] as $id) {
            try {
                set_resource_star::execute($id, true);
                $this->fail('neither id should be starrable by a stranger');
            } catch (\moodle_exception $e) {
                $codes[] = $e->errorcode;
            }
        }

        $this->assertSame($codes[0], $codes[1]);
    }
}
