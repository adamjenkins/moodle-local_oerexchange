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

use local_oerexchange\local\collection_manager;
use local_oerexchange\local\resource_manager;
use local_oerexchange\local\slug_helper;

/**
 * Data generator for local_oerexchange.
 *
 * This plugin went a long way without one, and the cost shows: roughly ten
 * test files each carry their own make_resource() helper, every one of them a
 * slightly different list of column defaults. When a NOT NULL column is added
 * to {local_oerexchange_resources} they all have to be found and edited, and
 * the ones that are missed fail on PostgreSQL (which refuses the insert) while
 * passing on MySQL (which supplies a zero). This class is the one place those
 * defaults live from now on.
 *
 * Both methods INSERT directly rather than going through the managers, and
 * that is the point of them:
 *
 * - resource_manager::publish() wants a real .mbz and a registered client
 *   site, neither of which anything about collections cares about;
 * - collection_manager::create() always produces a DRAFT, so a test that
 *   needs a published, an unlisted, a taken-down or a tombstoned collection
 *   could not start from it at all.
 *
 * So the tests of create() call create(), and every test that merely needs a
 * collection to exist in a given state asks for that state here. The one rule
 * this file does take from production code is the slug: an unspecified slug is
 * minted by slug_helper, which both guarantees uniqueness against the UNIQUE
 * index and means a generated collection is addressed exactly as a real one.
 *
 * create_collection() defaults to the same state create() produces — an
 * active DRAFT with timepublished 0 — so that the generator never quietly
 * publishes something a test did not ask to be published. Several test files
 * in this plugin mint a row here and then write the listability columns
 * directly, and a public default would make every one of their "this is not
 * listed" cases pass for the wrong reason. Any test that wants a published
 * collection says 'visibility' => VISIBILITY_PUBLIC in its own call, where a
 * reader can see it; timepublished then follows automatically.
 *
 * Existing test files are deliberately left on their own helpers: rewriting
 * ten passing files to funnel through a new one risks changing what they
 * assert, for no gain today.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_oerexchange_generator extends component_generator_base {
    /**
     * Insert a catalogue resource row.
     *
     * Defaults to a published course resource with no creator (creatorid 0,
     * this plugin's "no attributable user" sentinel), which is what a test
     * that only needs something collectable wants. Pass 'status' to make it
     * unavailable, 'creatorid' to give it an author.
     *
     * Every NOT NULL column is supplied explicitly rather than left to its
     * XMLDB default, so the insert behaves identically on MySQL/MariaDB and
     * PostgreSQL.
     *
     * @param array $record field => value pairs replacing the defaults
     * @return stdClass the stored resource record, read back from the database
     */
    public function create_resource(array $record = []): stdClass {
        global $DB;

        $now = time();
        $resource = (object) array_merge([
            'type' => 'course',
            'title' => 'Shared course',
            'summary' => '',
            'summaryformat' => FORMAT_HTML,
            'language' => 'en',
            'tags' => '',
            'licenseshortname' => 'cc-4.0',
            'activitytype' => null,
            'dataresourcetype' => null,
            'courseformat' => null,
            'creatorid' => 0,
            'siteid' => null,
            'status' => resource_manager::STATUS_PUBLISHED,
            'downloadcount' => 0,
            'importcount' => 0,
            'forkedfromid' => null,
            'trydisabled' => 0,
            'trydisabledreason' => null,
            'timeshared' => $now,
            'timemodified' => $now,
            'timefresh' => $now,
            'stalenotifiedtime' => 0,
            'modhiddentime' => 0,
            'modhiddenby' => 0,
            'modhiddenversionid' => null,
        ], $record);
        unset($resource->id);

        $id = $DB->insert_record('local_oerexchange_resources', $resource);

        return $DB->get_record('local_oerexchange_resources', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Insert a collection row in whatever state the test needs.
     *
     * An unspecified slug is minted with slug_helper::generate_unique()
     * against the collections table, so repeated calls with the same (or no)
     * title cannot collide on the UNIQUE slug index — 'Reading list' becomes
     * 'reading-list', then 'reading-list-2'.
     *
     * The state defaults to an active draft, exactly as
     * collection_manager::create() produces one.
     *
     * timepublished defaults to now when the visibility asked for is public,
     * and to 0 otherwise, matching what set_visibility() would have stamped: a
     * public collection with timepublished 0 is a state production code never
     * creates, and handing one to a "recent" sort would be testing a fiction.
     * An explicit timepublished in $record always wins.
     *
     * @param array $record field => value pairs replacing the defaults; userid,
     *      title, summary, summaryformat, slug, visibility, status, modhidden,
     *      modhiddentime, modhiddenby, opencuration, timepublished, timecreated
     *      and timemodified are all accepted
     * @return stdClass the stored collection record, read back from the database
     */
    public function create_collection(array $record = []): stdClass {
        global $DB;

        $now = time();
        $title = (string) ($record['title'] ?? 'Reading list');
        $visibility = (string) ($record['visibility'] ?? collection_manager::VISIBILITY_DRAFT);

        $collection = (object) array_merge([
            'userid' => 0,
            'slug' => slug_helper::generate_unique(
                collection_manager::TABLE,
                slug_helper::slugify($title),
                'id',
                0,
                'collection'
            ),
            'title' => $title,
            'summary' => '',
            'summaryformat' => FORMAT_HTML,
            'visibility' => $visibility,
            'status' => collection_manager::STATUS_ACTIVE,
            'modhidden' => 0,
            'modhiddentime' => 0,
            'modhiddenby' => 0,
            'opencuration' => 0,
            'timepublished' => $visibility === collection_manager::VISIBILITY_PUBLIC ? $now : 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ], $record);
        unset($collection->id);

        $id = $DB->insert_record(collection_manager::TABLE, $collection);

        return $DB->get_record(collection_manager::TABLE, ['id' => $id], '*', MUST_EXIST);
    }
}
