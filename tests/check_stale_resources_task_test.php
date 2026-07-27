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

use local_oerexchange\local\resource_manager;
use local_oerexchange\local\stale_manager;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the abandoned-courseware lifecycle: warn the author once a
 * resource has gone unmaintained past the threshold, then remove it after
 * the grace period unless it was updated or confirmed still fresh.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(stale_manager::class)]
#[CoversClass(\local_oerexchange\task\check_stale_resources_task::class)]
final class check_stale_resources_task_test extends \advanced_testcase {
    /**
     * Insert a catalogue row with a chosen freshness state.
     *
     * @param int $creatorid
     * @param string $status
     * @param int $timefresh
     * @param int $stalenotifiedtime
     * @return \stdClass
     */
    protected function make_resource(
        int $creatorid,
        string $status = 'published',
        int $timefresh = 0,
        int $stalenotifiedtime = 0
    ): \stdClass {
        global $DB;

        $now = time();
        $id = $DB->insert_record('local_oerexchange_resources', (object) [
            'siteid' => 0,
            'creatorid' => $creatorid,
            'title' => 'A resource',
            'type' => 'course',
            'status' => $status,
            'licenseshortname' => 'cc-4.0',
            'timeshared' => $now,
            'timemodified' => $now,
            'timefresh' => $timefresh ?: $now,
            'stalenotifiedtime' => $stalenotifiedtime,
        ]);

        return $DB->get_record('local_oerexchange_resources', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Switch the feature on with its default threshold and grace.
     */
    protected function enable(): void {
        set_config('staleenabled', 1, 'local_oerexchange');
    }

    /**
     * A timefresh value safely past the default threshold.
     *
     * @return int
     */
    protected function longago(): int {
        return time() - stale_manager::DEFAULT_THRESHOLD - DAYSECS;
    }

    public function test_the_feature_is_off_until_the_admin_enables_it(): void {
        global $DB;
        $this->resetAfterTest();

        $creator = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource((int) $creator->id, 'published', $this->longago());

        $sink = $this->redirectMessages();
        $summary = stale_manager::run_checks();
        $sink->close();

        $this->assertSame(['unflagged' => 0, 'removed' => 0, 'notified' => 0], $summary);
        $this->assertSame(0, $sink->count());
        $this->assertEquals(0, $DB->get_field('local_oerexchange_resources', 'stalenotifiedtime', ['id' => $resource->id]));
    }

    public function test_a_stale_published_resource_warns_its_author_and_starts_the_grace_clock(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable();

        $creator = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource((int) $creator->id, 'published', $this->longago());

        $sink = $this->redirectMessages();
        $summary = stale_manager::run_checks();
        $sink->close();

        $this->assertSame(1, $summary['notified']);
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame('stale', $messages[0]->eventtype);
        $this->assertEquals($creator->id, $messages[0]->useridto);

        $row = $DB->get_record('local_oerexchange_resources', ['id' => $resource->id], '*', MUST_EXIST);
        $this->assertSame('published', $row->status, 'a warning must not touch the status');
        $this->assertGreaterThan(0, (int) $row->stalenotifiedtime);
    }

    public function test_an_author_is_warned_once_not_every_night(): void {
        $this->resetAfterTest();
        $this->enable();

        $creator = $this->getDataGenerator()->create_user();
        $this->make_resource((int) $creator->id, 'published', $this->longago());

        $sink = $this->redirectMessages();
        stale_manager::run_checks();
        stale_manager::run_checks();
        $sink->close();

        $this->assertCount(1, $sink->get_messages());
    }

    public function test_fresh_nonpublished_and_authorless_resources_are_never_flagged(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable();

        $creator = $this->getDataGenerator()->create_user();
        $fresh = $this->make_resource((int) $creator->id, 'published', time() - DAYSECS);
        $hidden = $this->make_resource((int) $creator->id, 'hidden', $this->longago());
        $modhidden = $this->make_resource((int) $creator->id, 'modhidden', $this->longago());
        $orphan = $this->make_resource(0, 'published', $this->longago());

        $sink = $this->redirectMessages();
        $summary = stale_manager::run_checks();
        $sink->close();

        $this->assertSame(0, $summary['notified']);
        $this->assertSame(0, $sink->count());
        foreach ([$fresh, $hidden, $modhidden, $orphan] as $resource) {
            $this->assertEquals(
                0,
                $DB->get_field('local_oerexchange_resources', 'stalenotifiedtime', ['id' => $resource->id]),
                "resource {$resource->id} must not be flagged"
            );
        }
    }

    public function test_a_flagged_resource_is_removed_once_the_grace_period_expires(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable();

        $creator = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource(
            (int) $creator->id,
            'published',
            $this->longago(),
            time() - stale_manager::DEFAULT_GRACE - DAYSECS
        );

        $sink = $this->redirectMessages();
        $summary = stale_manager::run_checks();
        $sink->close();

        $this->assertSame(1, $summary['removed']);
        $row = $DB->get_record('local_oerexchange_resources', ['id' => $resource->id], '*', MUST_EXIST);
        // Status 'removed' — the same takedown a moderator writes — so the
        // moderation page's "Moderated resources" list offers Restore on it.
        $this->assertSame('removed', $row->status);

        // The author hears about the removal too.
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame('stale', $messages[0]->eventtype);
        $this->assertEquals($creator->id, $messages[0]->useridto);
    }

    public function test_a_flagged_resource_inside_the_grace_period_is_left_alone(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable();

        $resource = $this->make_resource(
            (int) $this->getDataGenerator()->create_user()->id,
            'published',
            $this->longago(),
            time() - DAYSECS
        );

        $sink = $this->redirectMessages();
        $summary = stale_manager::run_checks();
        $sink->close();

        $this->assertSame(0, $summary['removed']);
        $this->assertSame(0, $sink->count());
        $this->assertSame(
            'published',
            $DB->get_field('local_oerexchange_resources', 'status', ['id' => $resource->id])
        );
    }

    /**
     * An admin who raises the threshold after warnings went out must not
     * see those resources removed anyway: a flagged row that is no longer
     * past the threshold is quietly unflagged instead.
     */
    public function test_a_flagged_resource_no_longer_past_the_threshold_is_unflagged(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable();

        $resource = $this->make_resource(
            (int) $this->getDataGenerator()->create_user()->id,
            'published',
            time() - DAYSECS,
            time() - stale_manager::DEFAULT_GRACE - DAYSECS
        );

        $sink = $this->redirectMessages();
        $summary = stale_manager::run_checks();
        $sink->close();

        $this->assertSame(1, $summary['unflagged']);
        $this->assertSame(0, $summary['removed']);
        $this->assertSame(0, $sink->count());
        $row = $DB->get_record('local_oerexchange_resources', ['id' => $resource->id], '*', MUST_EXIST);
        $this->assertSame('published', $row->status);
        $this->assertEquals(0, $row->stalenotifiedtime);
    }

    public function test_still_fresh_resets_the_clock_and_cancels_the_pending_removal(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable();

        $creator = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource(
            (int) $creator->id,
            'published',
            $this->longago(),
            time() - stale_manager::DEFAULT_GRACE - DAYSECS
        );

        stale_manager::mark_fresh($resource);

        $row = $DB->get_record('local_oerexchange_resources', ['id' => $resource->id], '*', MUST_EXIST);
        $this->assertEquals(0, $row->stalenotifiedtime);
        $this->assertEqualsWithDelta(time(), (int) $row->timefresh, 5);

        // And the janitor now has nothing to do.
        $sink = $this->redirectMessages();
        $summary = stale_manager::run_checks();
        $sink->close();
        $this->assertSame(['unflagged' => 0, 'removed' => 0, 'notified' => 0], $summary);
        $this->assertSame(
            'published',
            $DB->get_field('local_oerexchange_resources', 'status', ['id' => $resource->id])
        );
    }

    public function test_publishing_a_new_version_counts_as_an_update_and_resets_the_clock(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable();

        $creator = $this->getDataGenerator()->create_user();
        $this->setUser($creator);
        $resource = $this->make_resource(
            (int) $creator->id,
            'published',
            $this->longago(),
            time() - DAYSECS
        );

        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance((int) $creator->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'update.mbz',
        ], str_repeat('x', 50));

        resource_manager::publish($draftitemid, (int) $creator->id, null, [
            'type' => 'course', 'title' => 't', 'summary' => '', 'language' => '',
            'tags' => '', 'licenseshortname' => 'cc-4.0', 'activitytype' => null,
        ], (int) $resource->id);

        $row = $DB->get_record('local_oerexchange_resources', ['id' => $resource->id], '*', MUST_EXIST);
        $this->assertEquals(0, $row->stalenotifiedtime, 'an update must cancel the pending removal');
        $this->assertEqualsWithDelta(time(), (int) $row->timefresh, 5);
    }

    public function test_a_brand_new_resource_starts_with_a_running_freshness_clock(): void {
        global $DB;
        $this->resetAfterTest();

        $creator = $this->getDataGenerator()->create_user();
        $this->setUser($creator);

        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance((int) $creator->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'new.mbz',
        ], str_repeat('x', 50));

        [$resourceid] = resource_manager::publish($draftitemid, (int) $creator->id, null, [
            'type' => 'course', 'title' => 't', 'summary' => '', 'language' => '',
            'tags' => '', 'licenseshortname' => 'cc-4.0', 'activitytype' => null,
        ]);

        $this->assertEqualsWithDelta(
            time(),
            (int) $DB->get_field('local_oerexchange_resources', 'timefresh', ['id' => $resourceid]),
            5
        );
    }

    public function test_the_scheduled_task_wraps_the_checks(): void {
        $this->resetAfterTest();
        $this->enable();

        $creator = $this->getDataGenerator()->create_user();
        $this->make_resource((int) $creator->id, 'published', $this->longago());

        $task = new \local_oerexchange\task\check_stale_resources_task();
        $this->assertNotSame('', $task->get_name());

        $sink = $this->redirectMessages();
        $this->expectOutputRegex('/./'); // The task mtraces a summary line.
        $task->execute();
        $sink->close();

        $this->assertCount(1, $sink->get_messages());
    }

    /**
     * The task must never remove a resource the author was warned about at
     * a moment the feature was later reconfigured to call fresh — the
     * removal pass re-checks the threshold, not just the grace clock; and a
     * removal only ever touches 'published' rows, so a takedown or author
     * hide that happened during the grace period is respected.
     */
    /**
     * Boundary 2 of the lifecycle holds at removal time, not just at
     * warning time: if the author's account is deleted during the grace
     * period, the resource becomes authorless and automatic removal backs
     * off — that call belongs to a human moderator.
     */
    public function test_a_resource_whose_author_vanished_mid_grace_is_not_auto_removed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable();

        $creator = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource(
            (int) $creator->id,
            'published',
            $this->longago(),
            time() - stale_manager::DEFAULT_GRACE - DAYSECS
        );
        $DB->set_field('user', 'deleted', 1, ['id' => $creator->id]);

        $sink = $this->redirectMessages();
        $summary = stale_manager::run_checks();
        $sink->close();

        $this->assertSame(0, $summary['removed']);
        $this->assertSame(0, $sink->count());
        $this->assertSame(
            'published',
            $DB->get_field('local_oerexchange_resources', 'status', ['id' => $resource->id])
        );
    }

    public function test_a_resource_hidden_during_the_grace_period_is_not_touched_by_the_removal_pass(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable();

        $resource = $this->make_resource(
            (int) $this->getDataGenerator()->create_user()->id,
            'hidden',
            $this->longago(),
            time() - stale_manager::DEFAULT_GRACE - DAYSECS
        );

        $sink = $this->redirectMessages();
        $summary = stale_manager::run_checks();
        $sink->close();

        $this->assertSame(0, $summary['removed']);
        $this->assertSame(
            'hidden',
            $DB->get_field('local_oerexchange_resources', 'status', ['id' => $resource->id])
        );
    }
}
