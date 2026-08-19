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

namespace local_oerexchange;

use PHPUnit\Framework\Attributes\CoversClass;
use local_oerexchange\event\resource_shared;
use local_oerexchange\event\resource_updated;
use local_oerexchange\local\resource_manager;

/**
 * Tests for the resource_shared / resource_updated events: the right event
 * fires from each writer, with the right payload, and moderation writers
 * fire nothing.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(resource_shared::class)]
#[CoversClass(resource_updated::class)]
final class events_test extends \advanced_testcase {
    /**
     * Stages a draft file for publish(), mirroring resource_manager_test.
     *
     * @param int $userid owner of the draft area
     * @return int the new draftitemid
     */
    protected function create_draft_file(int $userid): int {
        $draftitemid = file_get_unused_draft_itemid();
        $fs = get_file_storage();
        $usercontext = \context_user::instance($userid);
        $fs->create_file_from_string([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'test.mbz',
        ], str_repeat('x', 100));
        return $draftitemid;
    }

    /**
     * Publishes a new resource as the given user and returns [resourceid, versionid].
     *
     * @param int $userid the sharer
     * @param int|null $resourceid existing resource to add a version to
     * @return array [resourceid, versionid]
     */
    protected function publish(int $userid, ?int $resourceid = null): array {
        return resource_manager::publish($this->create_draft_file($userid), $userid, null, [
            'type' => 'course', 'title' => 't', 'summary' => '', 'language' => '',
            'tags' => '', 'licenseshortname' => 'cc-4.0', 'activitytype' => null,
        ], $resourceid);
    }

    public function test_sharing_a_new_resource_fires_resource_shared(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $sink = $this->redirectEvents();
        [$resourceid] = $this->publish((int) $user->id);
        $events = array_filter($sink->get_events(), static fn($e) => $e instanceof resource_shared);
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertSame($resourceid, (int) $event->objectid);
        $this->assertSame((int) $user->id, (int) $event->userid);
        $this->assertSame('c', $event->crud);
        $this->assertStringContainsString('shared a new resource', $event->get_description());
        $this->assertStringContainsString('resource.php', $event->get_url()->out(false));
    }

    public function test_replacing_the_file_fires_resource_updated_not_shared(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        [$resourceid] = $this->publish((int) $user->id);

        $sink = $this->redirectEvents();
        $this->publish((int) $user->id, $resourceid);
        $shared = array_filter($sink->get_events(), static fn($e) => $e instanceof resource_shared);
        $updated = array_filter($sink->get_events(), static fn($e) => $e instanceof resource_updated);
        $sink->close();

        $this->assertCount(0, $shared);
        $this->assertCount(1, $updated);
        $event = reset($updated);
        $this->assertSame($resourceid, (int) $event->objectid);
        $this->assertSame('file', $event->other['updated']);
    }

    public function test_editing_details_fires_resource_updated(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        [$resourceid] = $this->publish((int) $user->id);

        $sink = $this->redirectEvents();
        resource_manager::update_metadata($resourceid, [
            'title' => 'New title', 'summary' => 's', 'summaryformat' => FORMAT_HTML, 'tags' => '',
        ]);
        $updated = array_filter($sink->get_events(), static fn($e) => $e instanceof resource_updated);
        $sink->close();

        $this->assertCount(1, $updated);
        $event = reset($updated);
        $this->assertSame($resourceid, (int) $event->objectid);
        $this->assertSame('details', $event->other['updated']);
        $this->assertSame((int) $user->id, (int) $event->userid);
    }

    public function test_moderation_hide_fires_no_resource_event(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        [$resourceid] = $this->publish((int) $user->id);
        $DB->set_field('local_oerexchange_resources', 'status', 'published', ['id' => $resourceid]);
        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $resourceid]);

        $sink = $this->redirectEvents();
        resource_manager::set_hidden($resource, true);
        $ours = array_filter(
            $sink->get_events(),
            static fn($e) => $e instanceof resource_shared || $e instanceof resource_updated
        );
        $sink->close();

        $this->assertCount(0, $ours);
    }
}
