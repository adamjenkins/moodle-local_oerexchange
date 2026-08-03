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
 * The audit trail behind a moderator takedown must survive being read back.
 *
 * A takedown is an accusation with consequences, so the report has to be able
 * to say who made it, when, and against which version of the content — and it
 * has to be honest about the cases it cannot answer. This pins the three
 * decisions that are easy to regress by "tidying up": that hidden_count()
 * counts moderator takedowns only and never the stale-courseware janitor's
 * automatic 'removed' rows; that a resource taken down before the record
 * existed is reported as unknown rather than as changed; and that the shared
 * moderator note is one row that is updated and deleted in place, never a
 * growing pile of blank revisions.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(moderation_report::class)]
final class moderation_report_test extends \advanced_testcase {
    /**
     * Insert a catalogue row, since the plugin ships no generator.
     *
     * @param array $overrides field => value pairs replacing the defaults
     * @return \stdClass the stored row
     */
    protected function make_resource(array $overrides = []): \stdClass {
        global $DB;

        $now = time();
        $record = (object) array_merge([
            'type' => 'course', 'title' => 'Shared course', 'summary' => '', 'summaryformat' => FORMAT_HTML,
            'language' => 'en', 'licenseshortname' => 'cc-4.0',
            'creatorid' => 0, 'siteid' => null, 'status' => 'published',
            'timeshared' => $now, 'timemodified' => $now, 'timefresh' => $now,
            'modhiddentime' => 0, 'modhiddenby' => 0, 'modhiddenversionid' => null,
        ], $overrides);
        $id = $DB->insert_record('local_oerexchange_resources', $record);

        return $DB->get_record('local_oerexchange_resources', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Insert a version row for a resource.
     *
     * @param int $resourceid the resource the version belongs to
     * @param string $status one of parsing|ready|failed|superseded
     * @param int $versionnumber the version number, which orders 'ready' lookups
     * @return int the new version id
     */
    protected function make_version(int $resourceid, string $status = 'ready', int $versionnumber = 1): int {
        global $DB;

        return (int) $DB->insert_record('local_oerexchange_versions', (object) [
            'resourceid' => $resourceid,
            'versionnumber' => $versionnumber,
            'itemid' => 0,
            'filename' => 'backup.mbz',
            'filesize' => 0,
            'status' => $status,
            'timecreated' => time(),
        ]);
    }

    /**
     * A takedown records the acting moderator, the moment, and the served version.
     *
     * The superseded row is there on purpose: the anchor must be the version
     * the public could actually download, not merely the newest row.
     *
     * @return void
     */
    public function test_record_takedown_records_the_moderator_the_time_and_the_served_version(): void {
        global $DB;
        $this->resetAfterTest();

        $moderator = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource();
        $this->make_version((int) $resource->id, 'superseded', 1);
        $readyid = $this->make_version((int) $resource->id, 'ready', 2);

        $this->setUser($moderator);
        $before = time();
        moderation_report::record_takedown((int) $resource->id, 'modhidden');
        $after = time();

        $stored = $DB->get_record('local_oerexchange_resources', ['id' => $resource->id], '*', MUST_EXIST);
        $this->assertSame('modhidden', $stored->status);
        $this->assertSame((int) $moderator->id, (int) $stored->modhiddenby);
        $this->assertSame($readyid, (int) $stored->modhiddenversionid);
        $this->assertGreaterThanOrEqual($before, (int) $stored->modhiddentime);
        $this->assertLessThanOrEqual($after, (int) $stored->modhiddentime);
    }

    /**
     * With nothing downloadable there is no version to anchor to, and the
     * record must say so with null rather than invent an id.
     *
     * @return void
     */
    public function test_record_takedown_records_no_version_when_none_is_ready(): void {
        global $DB;
        $this->resetAfterTest();

        $moderator = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource();
        $this->make_version((int) $resource->id, 'parsing', 1);
        $this->make_version((int) $resource->id, 'failed', 2);

        $this->setUser($moderator);
        moderation_report::record_takedown((int) $resource->id, 'removed');

        $stored = $DB->get_record('local_oerexchange_resources', ['id' => $resource->id], '*', MUST_EXIST);
        $this->assertSame('removed', $stored->status);
        $this->assertNull($stored->modhiddenversionid);
        $this->assertSame((int) $moderator->id, (int) $stored->modhiddenby);
    }

    /**
     * A ready version belonging to some other resource must not be borrowed.
     *
     * @return void
     */
    public function test_record_takedown_ignores_another_resources_version(): void {
        global $DB;
        $this->resetAfterTest();

        $moderator = $this->getDataGenerator()->create_user();
        $other = $this->make_resource(['title' => 'Someone else']);
        $this->make_version((int) $other->id, 'ready', 1);
        $resource = $this->make_resource();

        $this->setUser($moderator);
        moderation_report::record_takedown((int) $resource->id, 'modhidden');

        $stored = $DB->get_record('local_oerexchange_resources', ['id' => $resource->id], '*', MUST_EXIST);
        $this->assertNull($stored->modhiddenversionid);
    }

    /**
     * The held-resource count is moderator takedowns only.
     *
     * 'removed' is excluded deliberately — the stale-courseware janitor writes
     * it automatically, so counting it would report resources no moderator
     * ever touched under a heading claiming a moderator hid them.
     *
     * @return void
     */
    public function test_hidden_count_counts_takedowns_and_ignores_janitor_removals(): void {
        $this->resetAfterTest();

        $this->assertSame(0, moderation_report::hidden_count());

        $this->make_resource(['status' => 'modhidden']);
        $this->make_resource(['status' => 'modhidden']);
        $this->make_resource(['status' => 'removed']);
        $this->make_resource(['status' => 'published']);
        $this->make_resource(['status' => 'hidden']);
        $this->make_resource(['status' => 'pending']);
        $this->make_resource(['status' => 'deleted']);

        $this->assertSame(2, moderation_report::hidden_count());
    }

    /**
     * A takedown from before the record existed has modhiddentime 0 and must
     * be reported as unknown, not as changed.
     *
     * @return void
     */
    public function test_a_takedown_with_no_recorded_time_reports_unknown_rather_than_changed(): void {
        $this->resetAfterTest();

        $resource = $this->make_resource([
            'status' => 'modhidden',
            'modhiddentime' => 0,
            'timemodified' => time() + 500,
        ]);
        $this->make_version((int) $resource->id, 'ready', 1);

        $this->assertNull(moderation_report::has_changed_since_hidden($resource));
    }

    /**
     * Nothing touched since the takedown is a definite "no change".
     *
     * @return void
     */
    public function test_an_untouched_held_resource_reports_no_change(): void {
        global $DB;
        $this->resetAfterTest();

        $moderator = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource();
        $this->make_version((int) $resource->id, 'ready', 1);

        $this->setUser($moderator);
        moderation_report::record_takedown((int) $resource->id, 'modhidden');
        $stored = $DB->get_record('local_oerexchange_resources', ['id' => $resource->id], '*', MUST_EXIST);

        $this->assertFalse(moderation_report::has_changed_since_hidden($stored));
    }

    /**
     * An edit after the takedown moves timemodified past modhiddentime, which
     * is the "they changed the details" signal.
     *
     * @return void
     */
    public function test_a_later_edit_counts_as_a_change(): void {
        $this->resetAfterTest();

        $hidden = time();
        $resource = $this->make_resource([
            'status' => 'modhidden',
            'modhiddentime' => $hidden,
            'timemodified' => $hidden + 60,
        ]);
        $versionid = $this->make_version((int) $resource->id, 'ready', 1);
        $resource->modhiddenversionid = $versionid;

        $this->assertTrue(moderation_report::has_changed_since_hidden($resource));
    }

    /**
     * Replacing the file changes the served version id while timemodified
     * stays put, so the version anchor is the only signal available.
     *
     * @return void
     */
    public function test_a_replaced_version_counts_as_a_change(): void {
        $this->resetAfterTest();

        $hidden = time();
        $resource = $this->make_resource([
            'status' => 'modhidden',
            'modhiddentime' => $hidden,
            'timemodified' => $hidden,
        ]);
        $oldid = $this->make_version((int) $resource->id, 'superseded', 1);
        $this->make_version((int) $resource->id, 'ready', 2);
        $resource->modhiddenversionid = $oldid;

        $this->assertTrue(moderation_report::has_changed_since_hidden($resource));
    }

    /**
     * A resource held with nothing downloadable, which then gains a ready
     * version, has changed just as much as one whose file was replaced.
     *
     * @return void
     */
    public function test_a_first_ready_version_after_a_takedown_counts_as_a_change(): void {
        $this->resetAfterTest();

        $hidden = time();
        $resource = $this->make_resource([
            'status' => 'modhidden',
            'modhiddentime' => $hidden,
            'timemodified' => $hidden,
            'modhiddenversionid' => null,
        ]);
        $this->make_version((int) $resource->id, 'ready', 1);

        $this->assertTrue(moderation_report::has_changed_since_hidden($resource));
    }

    /**
     * No note written yet must read as null, not as an empty row.
     *
     * @return void
     */
    public function test_get_note_is_null_until_something_is_written(): void {
        $this->resetAfterTest();

        $resource = $this->make_resource(['status' => 'modhidden']);

        $this->assertNull(moderation_report::get_note((int) $resource->id));
    }

    /**
     * The note is shared working state: a second save edits the same row and
     * records the moderator who touched it last.
     *
     * @return void
     */
    public function test_saving_a_note_twice_updates_the_one_row(): void {
        global $DB;
        $this->resetAfterTest();

        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource(['status' => 'modhidden']);

        $this->setUser($first);
        moderation_report::save_note((int) $resource->id, '  Emailed the author  ');
        $created = moderation_report::get_note((int) $resource->id);

        $this->assertNotNull($created);
        $this->assertSame('Emailed the author', $created->note);
        $this->assertSame((int) $first->id, (int) $created->usermodified);
        $this->assertSame((int) $resource->id, (int) $created->resourceid);

        $this->setUser($second);
        moderation_report::save_note((int) $resource->id, 'Author replied, waiting on a new file');
        $updated = moderation_report::get_note((int) $resource->id);

        $this->assertSame(
            1,
            $DB->count_records('local_oerexchange_modnotes', ['resourceid' => $resource->id]),
            'a second save must edit the note in place, not add another row'
        );
        $this->assertSame((int) $created->id, (int) $updated->id);
        $this->assertSame('Author replied, waiting on a new file', $updated->note);
        $this->assertSame((int) $second->id, (int) $updated->usermodified);
        $this->assertSame((int) $created->timecreated, (int) $updated->timecreated);
    }

    /**
     * Clearing a note deletes the row, so the report can still tell "nobody
     * wrote anything" from "somebody wrote something and cleared it".
     *
     * @return void
     */
    public function test_an_empty_note_deletes_the_row_rather_than_storing_a_blank(): void {
        global $DB;
        $this->resetAfterTest();

        $moderator = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource(['status' => 'modhidden']);

        $this->setUser($moderator);
        moderation_report::save_note((int) $resource->id, 'Held pending a licence check');
        $this->assertSame(1, $DB->count_records('local_oerexchange_modnotes', ['resourceid' => $resource->id]));

        moderation_report::save_note((int) $resource->id, "  \n\t ");

        $this->assertNull(moderation_report::get_note((int) $resource->id));
        $this->assertSame(0, $DB->count_records('local_oerexchange_modnotes', ['resourceid' => $resource->id]));
    }

    /**
     * Clearing a note that was never written must not create one.
     *
     * @return void
     */
    public function test_clearing_a_note_that_was_never_written_creates_nothing(): void {
        global $DB;
        $this->resetAfterTest();

        $moderator = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource(['status' => 'modhidden']);

        $this->setUser($moderator);
        moderation_report::save_note((int) $resource->id, '');

        $this->assertSame(0, $DB->count_records('local_oerexchange_modnotes', []));
    }

    /**
     * Notes belong to one resource each: saving one must not disturb another.
     *
     * @return void
     */
    public function test_notes_are_kept_per_resource(): void {
        $this->resetAfterTest();

        $moderator = $this->getDataGenerator()->create_user();
        $one = $this->make_resource(['status' => 'modhidden']);
        $two = $this->make_resource(['status' => 'modhidden']);

        $this->setUser($moderator);
        moderation_report::save_note((int) $one->id, 'First note');
        moderation_report::save_note((int) $two->id, 'Second note');
        moderation_report::save_note((int) $one->id, '');

        $this->assertNull(moderation_report::get_note((int) $one->id));
        $this->assertSame('Second note', moderation_report::get_note((int) $two->id)->note);
    }

    /**
     * The report lists takedowns newest first, each carrying its note and its
     * changed-since verdict, and lists nothing a moderator did not hide.
     *
     * @return void
     */
    public function test_hidden_resources_lists_takedowns_newest_first_with_their_notes(): void {
        $this->resetAfterTest();

        $this->assertSame([], moderation_report::hidden_resources());

        $moderator = $this->getDataGenerator()->create_user();
        $now = time();

        $oldest = $this->make_resource([
            'title' => 'Oldest takedown',
            'status' => 'modhidden',
            'modhiddentime' => $now - 3000,
            'timemodified' => $now - 3000,
        ]);
        $newest = $this->make_resource([
            'title' => 'Newest takedown',
            'status' => 'modhidden',
            'modhiddentime' => $now - 100,
            'timemodified' => $now,
        ]);
        $unknown = $this->make_resource([
            'title' => 'Held before the record existed',
            'status' => 'modhidden',
            'modhiddentime' => 0,
            'timemodified' => $now,
        ]);
        $this->make_resource(['title' => 'Janitor removal', 'status' => 'removed', 'modhiddentime' => $now]);
        $this->make_resource(['title' => 'Author hidden', 'status' => 'hidden', 'modhiddentime' => $now]);

        $this->setUser($moderator);
        moderation_report::save_note((int) $newest->id, 'Copyright claim received');

        $rows = array_values(moderation_report::hidden_resources());

        $this->assertCount(3, $rows);
        $this->assertSame(
            [(int) $newest->id, (int) $oldest->id, (int) $unknown->id],
            array_map(static fn($row) => (int) $row->id, $rows),
            'takedowns must be listed newest first, with the unrecorded one last'
        );

        $this->assertSame('Copyright claim received', $rows[0]->modnote);
        $this->assertSame((int) $moderator->id, (int) $rows[0]->modnoteby);
        $this->assertGreaterThan(0, (int) $rows[0]->modnotetime);
        $this->assertTrue($rows[0]->changedsincehidden, 'this one was edited after its takedown');

        $this->assertSame('', $rows[1]->modnote, 'a resource with no note must read as an empty note');
        $this->assertSame(0, (int) $rows[1]->modnoteby);
        $this->assertSame(0, (int) $rows[1]->modnotetime);
        $this->assertFalse($rows[1]->changedsincehidden);

        $this->assertNull($rows[2]->changedsincehidden, 'no takedown time means unknown, not changed');
    }

    /**
     * An author's own Hide is not a takedown and stays off the report.
     *
     * This is the distinction a moderator walked into on the live site: they
     * pressed Hide on the resource page — which moderators could see, because
     * the edit gate grants them every author control — and the resource never
     * appeared on the report, because no takedown had happened.
     *
     * @return void
     */
    public function test_an_author_hide_is_not_a_takedown(): void {
        $this->resetAfterTest();
        global $DB;

        $author = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource(['creatorid' => $author->id, 'status' => 'published']);

        $this->setUser($author);
        $this->assertTrue(resource_manager::set_hidden($resource, true));

        $after = $DB->get_record('local_oerexchange_resources', ['id' => $resource->id], '*', MUST_EXIST);
        $this->assertSame('hidden', $after->status);
        $this->assertSame(0, (int) $after->modhiddentime, 'an author hide must not write a takedown record');
        $this->assertSame(0, moderation_report::hidden_count());
    }

    /**
     * A moderator who is not an author cannot use the author's hide switch.
     *
     * The defect this pins is worse than a missing button: because the edit
     * gate admits moderators, a moderator hiding somebody else's resource
     * wrote the AUTHOR's 'hidden' status — which that author could then simply
     * switch back, and which no report listed. The takedown/author-hide split
     * exists precisely so an author cannot undo a moderator.
     *
     * @return void
     */
    public function test_a_moderator_who_is_not_an_author_is_not_an_author(): void {
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

        $resource = $this->make_resource(['creatorid' => $author->id, 'status' => 'published']);

        // The edit gate admits them — that is what made the button appear.
        $this->assertTrue(
            resource_manager::user_can_edit_resource($resource, (int) $moderator->id),
            'a moderator still holds the edit gate, which is what this bug rode in on'
        );
        // Authorship does not, which is what the hide/show switch now requires.
        $this->assertFalse(
            resource_manager::user_is_author($resource, (int) $moderator->id),
            'a moderator who is not creator or co-author must not count as an author'
        );
        $this->assertTrue(resource_manager::user_is_author($resource, (int) $author->id));
    }

    /**
     * A takedown of an author-hidden resource reaches the report, and sticks.
     *
     * @return void
     */
    public function test_a_takedown_reaches_the_report_and_the_author_cannot_lift_it(): void {
        $this->resetAfterTest();
        global $DB;

        $author = $this->getDataGenerator()->create_user();
        $moderator = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource(['creatorid' => $author->id, 'status' => 'hidden']);

        $this->setUser($moderator);
        moderation_report::record_takedown((int) $resource->id, 'modhidden');

        $after = $DB->get_record('local_oerexchange_resources', ['id' => $resource->id], '*', MUST_EXIST);
        $this->assertSame('modhidden', $after->status);
        $this->assertSame((int) $moderator->id, (int) $after->modhiddenby);
        $this->assertSame(1, moderation_report::hidden_count());

        // The set_hidden() helper only ever flips published <-> hidden, so it refuses a
        // moderator-held row: the author cannot undo the takedown.
        $this->setUser($author);
        $this->assertFalse(resource_manager::set_hidden($after, false));
        $this->assertSame(
            'modhidden',
            $DB->get_field('local_oerexchange_resources', 'status', ['id' => $resource->id])
        );
    }

    /**
     * restore() lifts a takedown and clears the record off the report.
     *
     * @return void
     */
    public function test_restore_lifts_a_takedown_and_clears_the_record(): void {
        $this->resetAfterTest();
        global $DB;

        $moderator = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource(['status' => 'published']);
        $this->make_version((int) $resource->id);

        $this->setUser($moderator);
        moderation_report::record_takedown((int) $resource->id, 'modhidden');
        $held = $DB->get_record('local_oerexchange_resources', ['id' => $resource->id], '*', MUST_EXIST);

        $this->assertTrue(moderation_report::restore($held));

        $after = $DB->get_record('local_oerexchange_resources', ['id' => $resource->id], '*', MUST_EXIST);
        $this->assertSame('published', $after->status);
        $this->assertSame(0, (int) $after->modhiddentime);
        $this->assertSame(0, (int) $after->modhiddenby);
        $this->assertNull($after->modhiddenversionid);
        $this->assertSame(0, moderation_report::hidden_count());
    }

    /**
     * restore() refuses a resource with nothing servable.
     *
     * @return void
     */
    public function test_restore_refuses_a_resource_with_no_ready_version(): void {
        $this->resetAfterTest();
        global $DB;

        $moderator = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource(['status' => 'published']);

        $this->setUser($moderator);
        moderation_report::record_takedown((int) $resource->id, 'modhidden');
        $held = $DB->get_record('local_oerexchange_resources', ['id' => $resource->id], '*', MUST_EXIST);

        $this->assertFalse(moderation_report::restore($held));
        $this->assertSame(
            'modhidden',
            $DB->get_field('local_oerexchange_resources', 'status', ['id' => $resource->id]),
            'a resource with nothing to serve must stay held rather than be published as a husk'
        );
    }
}
