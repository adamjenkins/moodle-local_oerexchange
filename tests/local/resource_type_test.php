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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for resource_type — the regression net for the mislabelled data resource.
 *
 * The Educator Profile page used to build its type badge from a two-way ternary
 * over a three-value `type` column, so every 'data' resource was announced as a
 * "Course" while the catalogue one click away named it correctly. These tests
 * pin all three branches and, just as importantly, the two fall-throughs that
 * the ternary got wrong: an unrecognised `type` must still produce a badge, and
 * an unrecognised `dataresourcetype` must degrade to the plain data label rather
 * than push a bad column value into get_string() and paint a "missing string"
 * placeholder onto a visitor's screen.
 *
 * Expectations are composed from get_string() rather than English literals, so
 * the tests pin the label's SHAPE — which string, which qualifier, in which
 * order — and survive a wording change in the lang file.
 *
 * label() reads only ->type, ->activitytype and ->dataresourcetype, so most
 * cases here are built as plain \stdClass objects: that keeps each case's
 * inputs visible in one line. The cases that specifically claim something about
 * stored data — a real 'data' row, and NULL qualifier columns — insert an
 * actual resources row instead, because their point is what the database gives
 * label() rather than what a test author remembered to set.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(resource_type::class)]
final class resource_type_test extends \advanced_testcase {
    /**
     * A resources row, defaulted so a caller sets only the columns it is about.
     *
     * @param array<string, mixed> $overrides Column values replacing the defaults.
     * @return \stdClass The inserted row, read back from the database.
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
     * A resource-shaped object carrying only the columns label() reads.
     *
     * @param string|null $type The `type` column value.
     * @param string|null $activitytype The `activitytype` column value.
     * @param string|null $dataresourcetype The `dataresourcetype` column value.
     * @return \stdClass
     */
    protected function resource_shaped(
        ?string $type,
        ?string $activitytype = null,
        ?string $dataresourcetype = null
    ): \stdClass {
        return (object) [
            'type' => $type,
            'activitytype' => $activitytype,
            'dataresourcetype' => $dataresourcetype,
        ];
    }

    /**
     * A course resource is labelled with the course string and nothing else.
     *
     * @return void
     */
    public function test_a_course_resource_is_labelled_course(): void {
        $this->resetAfterTest();

        $label = resource_type::label($this->resource_shaped('course'));

        $this->assertSame(get_string('typecourse', 'local_oerexchange'), $label);
    }

    /**
     * An activity resource names its module alongside the activity string.
     *
     * @return void
     */
    public function test_an_activity_resource_names_its_module(): void {
        $this->resetAfterTest();

        $label = resource_type::label($this->resource_shaped('activity', 'quiz'));

        $this->assertSame(get_string('typeactivity', 'local_oerexchange') . ' (quiz)', $label);
    }

    /**
     * With no module recorded the activity label stands alone — no empty brackets.
     *
     * @return void
     */
    public function test_an_activity_resource_without_a_module_is_labelled_activity_alone(): void {
        $this->resetAfterTest();

        $label = resource_type::label($this->resource_shaped('activity', ''));

        $this->assertSame(get_string('typeactivity', 'local_oerexchange'), $label);
        $this->assertStringNotContainsString('(', $label);
    }

    /**
     * The module name is a stored value, so it reaches the page HTML-escaped.
     *
     * @return void
     */
    public function test_the_module_name_is_escaped(): void {
        $this->resetAfterTest();

        $label = resource_type::label($this->resource_shaped('activity', 'a&b<script>'));

        $this->assertSame(
            get_string('typeactivity', 'local_oerexchange') . ' (a&amp;b&lt;script&gt;)',
            $label
        );
        $this->assertStringNotContainsString('<script>', $label);
    }

    /**
     * Each export kind the parser can produce qualifies the data label.
     *
     * @param string $kind The stored dataresourcetype value.
     * @return void
     */
    #[DataProvider('data_kind_provider')]
    public function test_a_data_resource_names_its_kind(string $kind): void {
        $this->resetAfterTest();

        $label = resource_type::label($this->resource_shaped('data', null, $kind));

        $this->assertSame(
            get_string('typedata', 'local_oerexchange')
                . ' (' . get_string('datatype_' . $kind, 'local_oerexchange') . ')',
            $label
        );
    }

    /**
     * The three dataresourcetype values the backup parser can write.
     *
     * @return array<string, array{string}>
     */
    public static function data_kind_provider(): array {
        return [
            'glossary' => ['glossary'],
            'questionbank' => ['questionbank'],
            'other' => ['other'],
        ];
    }

    /**
     * With no export kind recorded the data label stands alone.
     *
     * @return void
     */
    public function test_a_data_resource_without_a_kind_is_labelled_data_alone(): void {
        $this->resetAfterTest();

        $label = resource_type::label($this->resource_shaped('data', null, ''));

        $this->assertSame(get_string('typedata', 'local_oerexchange'), $label);
        $this->assertStringNotContainsString('(', $label);
    }

    /**
     * An unrecognised export kind degrades to the plain data label.
     *
     * The failure this guards against is specific: passing the stored value
     * to get_string() would render Moodle's "[[datatype_x]]" placeholder to a
     * visitor. Both halves are asserted — the exact label returned, and the
     * absence of any placeholder or raw column value inside it — so the test
     * cannot pass for the wrong reason.
     *
     * @return void
     */
    public function test_an_unrecognised_data_kind_falls_back_to_the_unqualified_data_label(): void {
        $this->resetAfterTest();
        $resource = $this->make_resource(['type' => 'data', 'dataresourcetype' => 'legacy-import']);

        $label = resource_type::label($resource);

        $this->assertSame(get_string('typedata', 'local_oerexchange'), $label);
        $this->assertStringNotContainsString('[[', $label);
        $this->assertStringNotContainsString('legacy-import', $label);
        $this->assertStringNotContainsString('(', $label);
    }

    /**
     * The bug itself: a stored data row must never be announced as a course.
     *
     * @return void
     */
    public function test_a_stored_data_row_is_not_labelled_course(): void {
        $this->resetAfterTest();
        $resource = $this->make_resource(['type' => 'data', 'dataresourcetype' => 'glossary']);

        $label = resource_type::label($resource);

        $this->assertSame(
            get_string('typedata', 'local_oerexchange')
                . ' (' . get_string('datatype_glossary', 'local_oerexchange') . ')',
            $label
        );
        $this->assertStringNotContainsString(get_string('typecourse', 'local_oerexchange'), $label);
    }

    /**
     * NULL qualifier columns — what the database really returns — are survivable.
     *
     * Both optional qualifier columns are nullable and are NULL for every row
     * that has never been parsed, so label() sees null rather than the empty
     * string a hand-built object would carry.
     *
     * @return void
     */
    public function test_null_qualifier_columns_from_the_database_are_labelled_without_them(): void {
        $this->resetAfterTest();
        $activity = $this->make_resource(['type' => 'activity']);
        $data = $this->make_resource(['type' => 'data']);

        $this->assertNull($activity->activitytype);
        $this->assertNull($data->dataresourcetype);
        $this->assertSame(get_string('typeactivity', 'local_oerexchange'), resource_type::label($activity));
        $this->assertSame(get_string('typedata', 'local_oerexchange'), resource_type::label($data));
    }

    /**
     * An unrecognised type still gets a badge, and it is the course label.
     *
     * This is a deliberate choice in the class, not an accident: a catalogue
     * entry came from somewhere, so a wrong-but-present badge beats a blank.
     *
     * @return void
     */
    public function test_an_unrecognised_type_falls_back_to_the_course_label(): void {
        $this->resetAfterTest();

        $label = resource_type::label($this->resource_shaped('question-set'));

        $this->assertSame(get_string('typecourse', 'local_oerexchange'), $label);
        $this->assertStringNotContainsString('question-set', $label);
    }

    /**
     * A resource object with no type property at all still yields a label.
     *
     * Partial objects reach label() from the search API and from templates
     * that select a column subset, so the missing-property path is real.
     *
     * @return void
     */
    public function test_an_object_without_a_type_property_falls_back_to_the_course_label(): void {
        $this->resetAfterTest();

        $label = resource_type::label((object) ['title' => 'Untyped']);

        $this->assertSame(get_string('typecourse', 'local_oerexchange'), $label);
    }
}
