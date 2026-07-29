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
 * A moderator's takedown must outrank the author's own visibility switch.
 *
 * Before 'modhidden' existed, moderate.php wrote a takedown as 'hidden' —
 * the same value the author's own Hide button writes — and
 * resource_manager::set_hidden() happily flipped that straight back to
 * 'published'. An author could therefore silently undo a moderator's
 * decision.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(resource_manager::class)]
final class moderator_visibility_test extends \advanced_testcase {
    /**
     * Insert a catalogue row.
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
            'type' => 'course',
            'status' => $status,
            'licenseshortname' => 'cc-4.0',
            'timeshared' => time(),
            'timemodified' => time(),
        ]);

        return $DB->get_record('local_oerexchange_resources', ['id' => $id], '*', MUST_EXIST);
    }

    public function test_an_author_cannot_lift_a_moderator_hide(): void {
        global $DB;
        $this->resetAfterTest();

        $author = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource((int) $author->id, 'modhidden');

        $this->assertFalse(
            resource_manager::set_hidden($resource, false),
            'unhide must refuse a moderator takedown'
        );
        $this->assertSame(
            'modhidden',
            $DB->get_field('local_oerexchange_resources', 'status', ['id' => $resource->id])
        );
    }

    /**
     * The author's own switch keeps working exactly as before — the split is
     * only about who can undo whose decision.
     */
    public function test_an_author_can_still_lift_their_own_hide(): void {
        global $DB;
        $this->resetAfterTest();

        $author = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource((int) $author->id, 'hidden');

        $this->assertTrue(resource_manager::set_hidden($resource, false));
        $this->assertSame(
            'published',
            $DB->get_field('local_oerexchange_resources', 'status', ['id' => $resource->id])
        );
    }

    /**
     * An author who hides a resource a moderator later takes down must not be
     * able to "unhide" it back into the catalogue either.
     */
    public function test_hiding_then_moderator_takedown_still_locks_the_author_out(): void {
        global $DB;
        $this->resetAfterTest();

        $author = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource((int) $author->id, 'published');

        $this->assertTrue(resource_manager::set_hidden($resource, true));

        // A moderator now takes it down while it is author-hidden.
        $DB->set_field('local_oerexchange_resources', 'status', 'modhidden', ['id' => $resource->id]);
        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $resource->id], '*', MUST_EXIST);

        $this->assertFalse(resource_manager::set_hidden($resource, false));
        $this->assertSame(
            'modhidden',
            $DB->get_field('local_oerexchange_resources', 'status', ['id' => $resource->id])
        );
    }

    /**
     * The author must still be able to reach the page — it is where the
     * explanation lives. Hiding the takedown as a 404 would just look broken.
     */
    public function test_the_author_can_still_view_a_moderator_hidden_resource(): void {
        $this->resetAfterTest();

        $author = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource((int) $author->id, 'modhidden');

        $this->assertTrue(resource_manager::user_can_view_resource($resource, (int) $author->id));
        $this->assertFalse(resource_manager::user_can_view_resource($resource, (int) $stranger->id));
        $this->assertFalse(resource_manager::user_can_view_resource($resource, 0));
    }

    public function test_every_status_the_owner_card_can_show_has_a_lang_string(): void {
        $this->resetAfterTest();

        foreach (['published', 'pending', 'hidden', 'modhidden', 'removed'] as $status) {
            $this->assertTrue(
                get_string_manager()->string_exists('resourcestatus_' . $status, 'local_oerexchange'),
                "missing lang string resourcestatus_{$status}"
            );
        }
    }
}
