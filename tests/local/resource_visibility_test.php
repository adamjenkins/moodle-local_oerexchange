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

/**
 * Tests for author-controlled resource visibility.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(resource_manager::class)]
final class resource_visibility_test extends \advanced_testcase {
    /**
     * Insert a catalogue row directly — publish() needs a real .mbz and a
     * registered site, neither of which these visibility rules care about.
     *
     * @param int $creatorid
     * @param string $status
     * @return \stdClass
     */
    protected function make_resource(int $creatorid, string $status = 'published'): \stdClass {
        global $DB;

        $id = $DB->insert_record('local_oerexchange_resources', (object) [
            'siteid' => 0,
            'creatorid' => $creatorid,
            'title' => 'A resource',
            'summary' => '',
            'type' => 'course',
            'status' => $status,
            'licenseshortname' => 'cc-4.0',
            'timeshared' => time(),
            'timemodified' => time(),
        ]);

        return $DB->get_record('local_oerexchange_resources', ['id' => $id], '*', MUST_EXIST);
    }

    public function test_anyone_can_view_a_published_resource_including_logged_out_visitors(): void {
        $this->resetAfterTest();

        $author = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource((int) $author->id);

        $this->assertTrue(resource_manager::user_can_view_resource($resource, 0));
        $this->assertTrue(resource_manager::user_can_view_resource($resource, (int) $author->id));
    }

    /**
     * The bug this method was added for: resource.php gated non-published
     * resources on the moderator capability alone, so an author who hid their
     * own resource could no longer reach the page that unhides it.
     */
    public function test_an_author_can_still_view_their_own_hidden_resource(): void {
        $this->resetAfterTest();

        $author = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource((int) $author->id, 'hidden');

        $this->assertTrue(resource_manager::user_can_view_resource($resource, (int) $author->id));
    }

    public function test_a_hidden_resource_is_invisible_to_everyone_else(): void {
        $this->resetAfterTest();

        $author = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource((int) $author->id, 'hidden');

        $this->assertFalse(resource_manager::user_can_view_resource($resource, (int) $stranger->id));
        $this->assertFalse(resource_manager::user_can_view_resource($resource, 0));
    }

    public function test_a_moderator_can_view_a_hidden_resource(): void {
        $this->resetAfterTest();

        $author = $this->getDataGenerator()->create_user();
        $moderator = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(
            'local/oerexchange:moderate',
            CAP_ALLOW,
            $roleid,
            \context_system::instance()->id
        );
        role_assign($roleid, $moderator->id, \context_system::instance()->id);

        $resource = $this->make_resource((int) $author->id, 'hidden');

        $this->assertTrue(resource_manager::user_can_view_resource($resource, (int) $moderator->id));
    }

    /**
     * A tombstoned resource has creatorid 0. A logged-out visitor also has
     * userid 0, and must not be treated as its author.
     */
    public function test_a_logged_out_visitor_is_not_the_author_of_a_tombstoned_resource(): void {
        $this->resetAfterTest();

        $resource = $this->make_resource(0, 'hidden');

        $this->assertFalse(resource_manager::user_can_view_resource($resource, 0));
        $this->assertFalse(resource_manager::user_can_edit_resource($resource, 0));
    }

    public function test_hiding_and_unhiding_round_trips(): void {
        global $DB;
        $this->resetAfterTest();

        $author = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource((int) $author->id);

        $this->assertTrue(resource_manager::set_hidden($resource, true));
        $this->assertSame('hidden', $DB->get_field('local_oerexchange_resources', 'status', ['id' => $resource->id]));

        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $resource->id], '*', MUST_EXIST);
        $this->assertTrue(resource_manager::set_hidden($resource, false));
        $this->assertSame('published', $DB->get_field('local_oerexchange_resources', 'status', ['id' => $resource->id]));
    }

    /**
     * "Unhide" must not become a way for an author to publish something a
     * moderator took down, or to skip the pending-validation gate.
     */
    public function test_unhiding_refuses_to_publish_a_removed_or_pending_resource(): void {
        global $DB;
        $this->resetAfterTest();

        $author = $this->getDataGenerator()->create_user();
        foreach (['removed', 'pending'] as $status) {
            $resource = $this->make_resource((int) $author->id, $status);

            $this->assertFalse(resource_manager::set_hidden($resource, false), "{$status} must not be unhideable");
            $this->assertSame(
                $status,
                $DB->get_field('local_oerexchange_resources', 'status', ['id' => $resource->id]),
                "{$status} must be left untouched"
            );
        }
    }

    public function test_hiding_something_already_hidden_is_a_no_op(): void {
        $this->resetAfterTest();

        $author = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource((int) $author->id, 'hidden');

        $this->assertFalse(resource_manager::set_hidden($resource, true));
    }
}
