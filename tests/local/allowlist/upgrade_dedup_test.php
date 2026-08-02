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

namespace local_oerexchange\local\allowlist;

use PHPUnit\Framework\Attributes\CoversFunction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/oerexchange/db/upgrade.php');

/**
 * Tests the 2026080300 upgrade step's de-duplication.
 *
 * This step is the one piece of 1.0.5 that can take a whole SITE down rather
 * than just this plugin: it deletes duplicate allowlist rows so that a UNIQUE
 * index can be added, and the index is added BEFORE
 * upgrade_plugin_savepoint(). If de-duplication misses a set, add_index()
 * throws, the savepoint is never written, and every retry of the site upgrade
 * re-enters this block and fails identically — recovery needs manual SQL.
 *
 * The bug this pins down: the query originally selected `plugintype` first and
 * read the result with get_records_sql(), which keys its array on the first
 * column. Two duplicate sets sharing a plugintype — 'mod' twice, entirely
 * ordinary — collapsed to one, and the second set survived to break the index.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('xmldb_local_oerexchange_upgrade')]
final class upgrade_dedup_test extends \advanced_testcase {
    /**
     * Put the table back into its pre-1.0.5 shape.
     *
     * The test database already has the 2026080300 schema, unique index and
     * all, so the duplicate rows this step exists to clean up cannot be
     * inserted until that index is gone. Dropping it here reproduces the
     * schema an upgrading site is actually coming FROM.
     */
    private function drop_unique_index(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_oerexchange_pluginallowlist');
        $unique = new \xmldb_index('typenamebranch', XMLDB_INDEX_UNIQUE, ['plugintype', 'pluginname', 'moodlebranch']);
        if ($dbman->index_exists($table, $unique)) {
            $dbman->drop_index($table, $unique);
        }
    }

    /**
     * Add the unique index, exactly as the upgrade step does.
     *
     * Throws if de-duplication left any set behind — which on a real site
     * aborts the whole upgrade before the savepoint is written.
     */
    private function add_unique_index(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_oerexchange_pluginallowlist');
        $unique = new \xmldb_index('typenamebranch', XMLDB_INDEX_UNIQUE, ['plugintype', 'pluginname', 'moodlebranch']);
        if (!$dbman->index_exists($table, $unique)) {
            $dbman->add_index($table, $unique);
        }
    }

    /**
     * Insert an allowlist row directly, bypassing the ingestor.
     *
     * The rows this step cleans up were written by 1.0.4's form, which had no
     * uniqueness check at all, so they cannot be created through any current
     * code path.
     *
     * @param string $plugintype
     * @param string $pluginname
     * @param string $branch
     * @return int the new row id
     */
    private function insert_row(string $plugintype, string $pluginname, string $branch): int {
        global $DB;

        $now = time();

        return (int) $DB->insert_record('local_oerexchange_pluginallowlist', (object) [
            'plugintype' => $plugintype,
            'pluginname' => $pluginname,
            'component' => $plugintype . '_' . $pluginname,
            'moodlebranch' => $branch,
            'sourceurl' => 'https://example.org/' . $pluginname . '.zip',
            'itemid' => null,
            'sha256' => str_repeat('a', 64),
            'status' => 'active',
            'bake' => 0,
            'notes' => '',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    public function test_two_duplicate_sets_sharing_a_plugintype_are_both_cleaned(): void {
        // The regression case. Both sets are 'mod', which is what made the
        // first-column keying collapse them into one.
        $this->resetAfterTest();
        global $DB;
        $this->drop_unique_index();

        $quizquestold = $this->insert_row('mod', 'quizquest', '5.2');
        $quizquestnew = $this->insert_row('mod', 'quizquest', '5.2');
        $thingold = $this->insert_row('mod', 'thing', '5.2');
        $thingnew = $this->insert_row('mod', 'thing', '5.2');

        $this->assertCount(4, $DB->get_records('local_oerexchange_pluginallowlist'));

        $this->run_dedup();

        $remaining = array_keys($DB->get_records('local_oerexchange_pluginallowlist'));
        sort($remaining);

        // One survivor per set — the most recently added of each.
        $this->assertSame([$quizquestnew, $thingnew], $remaining);
        $this->assertFalse($DB->record_exists('local_oerexchange_pluginallowlist', ['id' => $quizquestold]));
        $this->assertFalse($DB->record_exists('local_oerexchange_pluginallowlist', ['id' => $thingold]));
    }

    public function test_the_unique_index_can_actually_be_created_afterwards(): void {
        // The check that matters: de-duplication is only correct if the index
        // add that follows it succeeds. Three sets, two sharing a plugintype.
        $this->resetAfterTest();
        global $DB;
        $this->drop_unique_index();

        $this->insert_row('mod', 'quizquest', '5.2');
        $this->insert_row('mod', 'quizquest', '5.2');
        $this->insert_row('mod', 'thing', '5.2');
        $this->insert_row('mod', 'thing', '5.2');
        $this->insert_row('local', 'helper', '5.0');
        $this->insert_row('local', 'helper', '5.0');

        $this->run_dedup();

        $duplicatesleft = $DB->get_recordset_sql("
            SELECT MAX(id) AS keepid
              FROM {local_oerexchange_pluginallowlist}
          GROUP BY plugintype, pluginname, moodlebranch
            HAVING COUNT(*) > 1");
        $left = iterator_to_array($duplicatesleft);
        $duplicatesleft->close();

        // Any survivor here is a row the UNIQUE index would choke on.
        $this->assertSame([], $left);
        $this->assertCount(3, $DB->get_records('local_oerexchange_pluginallowlist'));

        // The real assertion: this is what the upgrade step does next, and
        // what threw before the fix. If it survives, the upgrade survives.
        $this->add_unique_index();
        $this->assertTrue($DB->get_manager()->index_exists(
            new \xmldb_table('local_oerexchange_pluginallowlist'),
            new \xmldb_index('typenamebranch', XMLDB_INDEX_UNIQUE, ['plugintype', 'pluginname', 'moodlebranch'])
        ));
    }

    public function test_distinct_branches_of_one_plugin_are_not_duplicates(): void {
        // One row per branch is the normal, correct shape — it must survive.
        $this->resetAfterTest();
        global $DB;
        $this->drop_unique_index();

        $this->insert_row('mod', 'quizquest', '5.0');
        $this->insert_row('mod', 'quizquest', '5.2');

        $this->run_dedup();

        $this->assertCount(2, $DB->get_records('local_oerexchange_pluginallowlist'));
    }

    public function test_a_doomed_rows_mirrored_zip_is_deleted_with_it(): void {
        // The itemid is the row's own id, so deleting the row alone strands the
        // file with nothing left able to reach it.
        $this->resetAfterTest();
        global $DB;
        $this->drop_unique_index();

        $old = $this->insert_row('mod', 'quizquest', '5.2');
        $new = $this->insert_row('mod', 'quizquest', '5.2');

        $fs = get_file_storage();
        $context = \context_system::instance();
        foreach ([$old, $new] as $id) {
            $DB->set_field('local_oerexchange_pluginallowlist', 'itemid', $id, ['id' => $id]);
            $fs->create_file_from_string([
                'contextid' => $context->id,
                'component' => 'local_oerexchange',
                'filearea' => 'allowlist',
                'itemid' => $id,
                'filepath' => '/',
                'filename' => 'quizquest.zip',
            ], 'PK' . "\x03\x04" . $id);
        }

        $this->run_dedup();

        $this->assertSame([], $fs->get_area_files($context->id, 'local_oerexchange', 'allowlist', $old, 'id', false));
        $this->assertCount(1, $fs->get_area_files($context->id, 'local_oerexchange', 'allowlist', $new, 'id', false));
    }

    /**
     * Run just the de-duplication half of the 2026080300 step.
     *
     * The real step also adds columns and the index, which are already applied
     * to the test database, so replaying it wholesale is not possible. This
     * mirrors the block at db/upgrade.php exactly; if that query changes, this
     * must change with it.
     */
    private function run_dedup(): void {
        global $DB;

        $rs = $DB->get_recordset_sql("
            SELECT MAX(id) AS keepid, plugintype, pluginname, moodlebranch
              FROM {local_oerexchange_pluginallowlist}
          GROUP BY plugintype, pluginname, moodlebranch
            HAVING COUNT(*) > 1");
        $sets = [];
        foreach ($rs as $duplicate) {
            $sets[] = (object) [
                'keepid' => $duplicate->keepid,
                'plugintype' => $duplicate->plugintype,
                'pluginname' => $duplicate->pluginname,
                'moodlebranch' => $duplicate->moodlebranch,
            ];
        }
        $rs->close();

        $fs = get_file_storage();
        $systemcontext = \context_system::instance();
        foreach ($sets as $duplicate) {
            $conditions = 'plugintype = :plugintype AND pluginname = :pluginname
                               AND moodlebranch = :moodlebranch AND id <> :keepid';
            $params = [
                'plugintype' => $duplicate->plugintype,
                'pluginname' => $duplicate->pluginname,
                'moodlebranch' => $duplicate->moodlebranch,
                'keepid' => $duplicate->keepid,
            ];
            foreach ($DB->get_fieldset_select('local_oerexchange_pluginallowlist', 'id', $conditions, $params) as $id) {
                $fs->delete_area_files($systemcontext->id, 'local_oerexchange', 'allowlist', $id);
            }
            $DB->delete_records_select('local_oerexchange_pluginallowlist', $conditions, $params);
        }
    }
}
