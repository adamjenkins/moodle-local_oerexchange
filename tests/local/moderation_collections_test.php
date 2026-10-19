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
 * The moderation queue must not confuse the two kinds of report it now holds.
 *
 * {local_oerexchange_reports} became polymorphic when collections became
 * reportable: resourceid is nullable, collectionid was added nullable, and
 * exactly one of the two is set on a row. The failure mode that follows is not
 * a missing row but a misattributed one — the queue's resource query joins
 * every report to {local_oerexchange_resources} by resourceid, so a collection
 * report arriving there is rendered as a resource that has been deleted. This
 * pins the separation in both directions, and pins the two takedown
 * transitions re-deriving their target from the database rather than trusting
 * the id a moderator's browser sent.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(moderation_collections::class)]
final class moderation_collections_test extends \advanced_testcase {
    /**
     * Insert a collection row directly, so a test can start from a state
     * (held, tombstoned) that collection_manager::create() never produces.
     *
     * @param array $overrides field => value pairs replacing the defaults
     * @return \stdClass the stored row
     */
    protected function make_collection(array $overrides = []): \stdClass {
        global $DB;

        $now = time();
        static $n = 0;
        $n++;
        $record = (object) array_merge([
            'userid' => 0,
            'slug' => 'collection-' . $n,
            'title' => 'Reading list ' . $n,
            'summary' => '',
            'summaryformat' => FORMAT_HTML,
            'visibility' => collection_manager::VISIBILITY_PUBLIC,
            'status' => collection_manager::STATUS_ACTIVE,
            'modhidden' => 0,
            'modhiddentime' => 0,
            'modhiddenby' => 0,
            'opencuration' => 0,
            'timepublished' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
        ], $overrides);
        $id = $DB->insert_record(collection_manager::TABLE, $record);

        return $DB->get_record(collection_manager::TABLE, ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Insert a report row about a resource or about a collection.
     *
     * Both target columns are always passed, exactly one of them non-null:
     * the invariant this class exists to respect is worth stating in the
     * fixture rather than leaving to a column default.
     *
     * @param int|null $resourceid
     * @param int|null $collectionid
     * @param string $status open|resolved|dismissed
     * @param int $timecreated
     * @return int the new report id
     */
    protected function make_report(
        ?int $resourceid,
        ?int $collectionid,
        string $status = 'open',
        int $timecreated = 0,
    ): int {
        global $DB;

        return (int) $DB->insert_record('local_oerexchange_reports', (object) [
            'resourceid' => $resourceid,
            'collectionid' => $collectionid,
            'userid' => 0,
            'type' => 'spam',
            'details' => 'Reported for testing',
            'status' => $status,
            'resolvernote' => null,
            'timecreated' => $timecreated ?: time(),
            'timeresolved' => null,
        ]);
    }

    /**
     * Only open reports about a collection, and every one of them.
     *
     * Both halves matter: a resource report leaking in is the regression
     * this class exists to stop, and a resolved or dismissed collection
     * report leaking in would put closed work back in the queue.
     *
     * @return void
     */
    public function test_open_reports_returns_collection_reports_only(): void {
        $this->resetAfterTest();

        $collection = $this->make_collection();
        $wanted = $this->make_report(null, (int) $collection->id, 'open', 100);
        // None of these three may appear: a resource report, a resolved
        // collection report, a dismissed one.
        $this->make_report(12345, null, 'open', 101);
        $this->make_report(null, (int) $collection->id, 'resolved', 102);
        $this->make_report(null, (int) $collection->id, 'dismissed', 103);

        $reports = moderation_collections::open_reports();

        $this->assertSame([$wanted], array_keys($reports));
    }

    /**
     * The joined collection must not eat the report it is joined to.
     *
     * id, status and timecreated exist on both tables, so the collection's
     * columns are aliased rather than selected wholesale.
     *
     * @return void
     */
    public function test_open_reports_carries_the_collection_without_overwriting_the_report(): void {
        $this->resetAfterTest();

        $collection = $this->make_collection(['title' => 'Coastal ecology', 'slug' => 'coastal-ecology']);
        $reportid = $this->make_report(null, (int) $collection->id, 'open', 100);

        $reports = moderation_collections::open_reports();
        $report = $reports[$reportid];

        // The report's own id, status and timecreated survive the join: all
        // three column names exist on both tables, which is why the
        // collection's columns are aliased instead of selected as c.*.
        $this->assertSame($reportid, (int) $report->id);
        $this->assertSame('open', $report->status);
        $this->assertSame(100, (int) $report->timecreated);
        $this->assertSame((int) $collection->id, (int) $report->collectionid);
        $this->assertSame('Coastal ecology', $report->collectiontitle);
        $this->assertSame('coastal-ecology', $report->collectionslug);
        $this->assertSame(collection_manager::STATUS_ACTIVE, $report->collectionstatus);
        $this->assertEmpty($report->collectionmodhidden);
    }

    /**
     * A report outliving its collection still lists, with a null title.
     *
     * @return void
     */
    public function test_open_reports_still_lists_a_report_whose_collection_has_gone(): void {
        $this->resetAfterTest();

        // Reachable through a privacy erasure only — the owner's own delete
        // tombstones the row — but the queue must not lose the report.
        $reportid = $this->make_report(null, 999999, 'open', 100);

        $reports = moderation_collections::open_reports();

        $this->assertArrayHasKey($reportid, $reports);
        $this->assertNull($reports[$reportid]->collectiontitle);
        $this->assertNull($reports[$reportid]->collectionslug);
    }

    /**
     * The standing list of held collections: what is on it, in what order,
     * and who is named on each row.
     *
     * @return void
     */
    public function test_held_collections_lists_active_held_newest_first_with_the_actor(): void {
        $this->resetAfterTest();

        $moderator = $this->getDataGenerator()->create_user(['firstname' => 'Mod', 'lastname' => 'Erator']);
        $now = time();
        $older = $this->make_collection([
            'modhidden' => 1,
            'modhiddentime' => $now - 100,
            'modhiddenby' => (int) $moderator->id,
        ]);
        $newer = $this->make_collection(['modhidden' => 1, 'modhiddentime' => $now, 'modhiddenby' => 0]);
        // Neither of these belongs on the report: one was never held, the
        // other is a tombstone that happens to still carry the flag.
        $this->make_collection();
        $this->make_collection([
            'modhidden' => 1,
            'modhiddentime' => $now,
            'status' => collection_manager::STATUS_DELETED,
        ]);

        $held = moderation_collections::held_collections();

        $this->assertSame([(int) $newer->id, (int) $older->id], array_map('intval', array_keys($held)));
        // A modhiddenby of 0 is "no attributable moderator", not user 0.
        $this->assertNull($held[$newer->id]->modhiddenbyuser);
        $this->assertSame('Mod', $held[$older->id]->modhiddenbyuser->firstname);
    }

    /**
     * A takedown records who did it and when, once.
     *
     * @return void
     */
    public function test_take_down_records_the_actor_and_refuses_a_second_takedown(): void {
        global $DB;
        $this->resetAfterTest();

        $moderator = $this->getDataGenerator()->create_user();
        $collection = $this->make_collection();

        $this->assertTrue(moderation_collections::take_down((int) $collection->id, (int) $moderator->id));

        $after = $DB->get_record(collection_manager::TABLE, ['id' => $collection->id], '*', MUST_EXIST);
        $this->assertSame(1, (int) $after->modhidden);
        $this->assertSame((int) $moderator->id, (int) $after->modhiddenby);
        $this->assertGreaterThan(0, (int) $after->modhiddentime);

        // A replayed link must not restamp the date the takedown happened.
        $this->assertFalse(moderation_collections::take_down((int) $collection->id, (int) $moderator->id));
        $again = $DB->get_record(collection_manager::TABLE, ['id' => $collection->id], '*', MUST_EXIST);
        $this->assertSame((int) $after->modhiddentime, (int) $again->modhiddentime);
    }

    /**
     * Neither transition applies to a collection that no longer exists.
     *
     * @return void
     */
    public function test_take_down_and_restore_refuse_a_tombstone(): void {
        $this->resetAfterTest();

        $tombstone = $this->make_collection(['status' => collection_manager::STATUS_DELETED]);
        $this->assertFalse(moderation_collections::take_down((int) $tombstone->id, 2));

        $heldtombstone = $this->make_collection([
            'status' => collection_manager::STATUS_DELETED,
            'modhidden' => 1,
            'modhiddentime' => time(),
        ]);
        $this->assertFalse(moderation_collections::restore((int) $heldtombstone->id));
    }

    /**
     * A restore clears the whole takedown record, and only a real one.
     *
     * @return void
     */
    public function test_restore_clears_the_takedown_and_refuses_what_is_not_held(): void {
        global $DB;
        $this->resetAfterTest();

        $collection = $this->make_collection(['modhidden' => 1, 'modhiddentime' => time(), 'modhiddenby' => 2]);

        $this->assertTrue(moderation_collections::restore((int) $collection->id));

        $after = $DB->get_record(collection_manager::TABLE, ['id' => $collection->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $after->modhidden);
        $this->assertSame(0, (int) $after->modhiddentime);
        $this->assertSame(0, (int) $after->modhiddenby);

        // Nothing to lift the second time, and nothing to lift on a
        // collection no moderator ever touched.
        $this->assertFalse(moderation_collections::restore((int) $collection->id));
        $this->assertFalse(moderation_collections::restore(999999));
    }

    /**
     * The shared title cell: linked, filtered once, and badged by state.
     *
     * @return void
     */
    public function test_render_title_cell_links_filters_and_badges(): void {
        $this->resetAfterTest();

        $context = \context_system::instance();

        $live = moderation_collections::render_title_cell(
            'Rivers & Deltas',
            'rivers-and-deltas',
            collection_manager::STATUS_ACTIVE,
            false,
            $context
        );
        $this->assertStringContainsString('/c/rivers-and-deltas', $live);
        // Escaped exactly once by format_string(), and no state badge.
        $this->assertStringContainsString('Rivers &amp; Deltas', $live);
        $this->assertStringNotContainsString('&amp;amp;', $live);
        $this->assertStringNotContainsString('badge', $live);

        $held = moderation_collections::render_title_cell(
            'Held list',
            'held-list',
            collection_manager::STATUS_ACTIVE,
            true,
            $context
        );
        $this->assertStringContainsString(get_string('collectionmodhidden', 'local_oerexchange'), $held);

        $tombstoned = moderation_collections::render_title_cell(
            'Gone list',
            'gone-list',
            collection_manager::STATUS_DELETED,
            true,
            $context
        );
        // The tombstone badge wins over the takedown badge: it is the later
        // and more final of the two states.
        $this->assertStringContainsString(get_string('collectiontombstoneheading', 'local_oerexchange'), $tombstoned);
        $this->assertStringNotContainsString(get_string('collectionmodhidden', 'local_oerexchange'), $tombstoned);

        // No collection row left: the placeholder, and nothing to link to.
        $missing = moderation_collections::render_title_cell(null, null, '', false, $context);
        $this->assertSame(get_string('resourcedeletedplaceholder', 'local_oerexchange'), $missing);
    }
}
