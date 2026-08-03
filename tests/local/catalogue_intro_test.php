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
 * Pins the admin-authored content area that sits above the catalogue, as it
 * is actually delivered — through catalogue_view::render(), the one method
 * all three catalogue entry points converge on (plugin index.php, the
 * site-root takeover, and the home-page redirect).
 *
 * The behaviours pinned here are the ones an admin would notice breaking and
 * the code cannot express in its own signature: the checkbox must be able to
 * take the area down WITHOUT the admin having to delete what they wrote; an
 * editor left holding '<p><br></p>' must not push an empty grey box onto every
 * visitor's first screen; the content must land ABOVE the search form, because
 * "at the top of the page" is the requirement, not merely "somewhere on it";
 * it must survive render()'s early return for an empty catalogue, which is
 * exactly the site where a welcome message earns its keep; and the admin's
 * markup must be filtered rather than escaped, or a bolded welcome renders as
 * visible angle brackets.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(catalogue_view::class)]
final class catalogue_intro_test extends \advanced_testcase {
    /** @var string the wrapper class that marks the rendered content area */
    private const INTRO_CLASS = 'oerexchange-intro';

    /** @var string the class on the catalogue's search form */
    private const FORM_CLASS = 'oerexchange-searchform';

    /**
     * Insert one published catalogue row, so the catalogue is non-empty and
     * render() reaches its full grid rather than the empty-catalogue return.
     *
     * @param array $overrides field values to override on the default record
     * @return \stdClass the inserted record as stored
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
            'timeshared' => $now, 'timemodified' => $now,
        ], $overrides);
        $id = $DB->insert_record('local_oerexchange_resources', $record);
        return $DB->get_record('local_oerexchange_resources', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Render the catalogue the way index.php does, at the plugin's own URL.
     *
     * @return string the catalogue body HTML
     */
    protected function render_catalogue(): string {
        return (new catalogue_view('', '', '', '', 0))
            ->render(new \moodle_url('/local/oerexchange/index.php'));
    }

    /**
     * Turning the checkbox off must hide the area without discarding the
     * content — the whole reason the setting is a pair rather than "render
     * whenever the editor is non-empty".
     *
     * @return void
     */
    public function test_nothing_is_rendered_when_the_area_is_switched_off(): void {
        $this->resetAfterTest();
        $this->make_resource();
        set_config('catalogueintroenabled', 0, 'local_oerexchange');
        set_config('catalogueintro', '<p>Welcome to our repository</p>', 'local_oerexchange');

        $html = $this->render_catalogue();

        // The catalogue itself still rendered — otherwise the absence below
        // would prove nothing about the content area specifically.
        $this->assertStringContainsString(self::FORM_CLASS, $html);
        $this->assertStringNotContainsString(self::INTRO_CLASS, $html);
        $this->assertStringNotContainsString('Welcome to our repository', $html);
        // The stored content is untouched: it is the render that is suppressed.
        $this->assertSame(
            '<p>Welcome to our repository</p>',
            get_config('local_oerexchange', 'catalogueintro')
        );
    }

    /**
     * Enabled but never filled in is the state every site is in the moment
     * after the checkbox is ticked; it must not produce an empty wrapper.
     *
     * @return void
     */
    public function test_nothing_is_rendered_when_the_content_is_empty(): void {
        $this->resetAfterTest();
        $this->make_resource();
        set_config('catalogueintroenabled', 1, 'local_oerexchange');
        set_config('catalogueintro', '', 'local_oerexchange');

        $html = $this->render_catalogue();

        $this->assertStringContainsString(self::FORM_CLASS, $html);
        $this->assertStringNotContainsString(self::INTRO_CLASS, $html);
    }

    /**
     * Markup an HTML editor leaves behind when the admin clears the field by
     * hand — visually blank, textually not.
     *
     * @return array<string, array{0: string}> case name => [stored content]
     */
    public static function blank_markup_provider(): array {
        return [
            'empty paragraph' => ['<p></p>'],
            'paragraph holding a line break' => ['<p><br></p>'],
            'self-closed break' => ['<p><br /></p>'],
            'whitespace only' => ['   '],
            'non-breaking space in a paragraph' => ["<p>\n</p>"],
        ];
    }

    /**
     * Visually-blank markup must be treated as no content at all. The setting
     * normalises this on save, but a value stored before the setting existed —
     * or written by CLI/upgrade — arrives here unnormalised, which is what
     * html_is_blank() is guarding.
     *
     * @param string $content the stored content for this case
     * @return void
     */
    #[DataProvider('blank_markup_provider')]
    public function test_nothing_is_rendered_for_visually_blank_markup(string $content): void {
        $this->resetAfterTest();
        $this->make_resource();
        set_config('catalogueintroenabled', 1, 'local_oerexchange');
        set_config('catalogueintro', $content, 'local_oerexchange');

        $html = $this->render_catalogue();

        $this->assertStringContainsString(self::FORM_CLASS, $html);
        $this->assertStringNotContainsString(self::INTRO_CLASS, $html);
    }

    /**
     * The positive path: enabled plus real content puts the text on the page
     * inside the wrapper the theme styles.
     *
     * @return void
     */
    public function test_content_is_rendered_inside_the_intro_wrapper_when_enabled(): void {
        $this->resetAfterTest();
        $this->make_resource();
        set_config('catalogueintroenabled', 1, 'local_oerexchange');
        set_config('catalogueintro', '<p>Welcome to our repository</p>', 'local_oerexchange');

        $html = $this->render_catalogue();

        $this->assertStringContainsString('Welcome to our repository', $html);
        // Assert the text sits INSIDE an element carrying the class, not merely
        // that both strings appear somewhere in a page this long.
        $this->assertMatchesRegularExpression(
            '#<div[^>]*class="[^"]*' . self::INTRO_CLASS . '[^"]*"[^>]*>.*Welcome to our repository#s',
            $html
        );
    }

    /**
     * "At the top of the catalogue, above the search box" is the documented
     * requirement, so position is the assertion — content that rendered below
     * the grid would satisfy a mere contains-check while failing the feature.
     *
     * @return void
     */
    public function test_the_content_is_rendered_above_the_search_form(): void {
        $this->resetAfterTest();
        $this->make_resource();
        set_config('catalogueintroenabled', 1, 'local_oerexchange');
        set_config('catalogueintro', '<p>Welcome to our repository</p>', 'local_oerexchange');

        $html = $this->render_catalogue();

        $intropos = strpos($html, self::INTRO_CLASS);
        $formpos = strpos($html, self::FORM_CLASS);
        $this->assertIsInt($intropos, 'the content area did not render at all');
        $this->assertIsInt($formpos, 'the search form did not render at all');
        $this->assertLessThan($formpos, $intropos, 'the content area must precede the search form');
    }

    /**
     * render() returns early once it has printed the empty-catalogue notice,
     * so the content area has to be emitted before that branch — and an empty
     * catalogue is precisely the site whose visitors need the welcome most.
     *
     * @return void
     */
    public function test_the_content_is_rendered_on_an_empty_catalogue(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('catalogueintroenabled', 1, 'local_oerexchange');
        set_config('catalogueintro', '<p>Nothing shared yet — be the first</p>', 'local_oerexchange');

        // The state under test is a catalogue with no rows at all.
        $this->assertSame(0, $DB->count_records('local_oerexchange_resources'));
        $html = $this->render_catalogue();

        // This is the early-return branch, confirmed by its own notice...
        $this->assertStringContainsString(get_string('catalogueempty', 'local_oerexchange'), $html);
        // ...and the content area survived it, above the search form.
        $this->assertStringContainsString('Nothing shared yet', $html);
        $this->assertLessThan(strpos($html, self::FORM_CLASS), strpos($html, self::INTRO_CLASS));
    }

    /**
     * The setting stores PARAM_RAW so the admin's markup survives; it is made
     * safe at the sink with format_text(). Escaping instead of filtering would
     * print the tags to every visitor, which is the regression this pins.
     *
     * @return void
     */
    public function test_the_content_is_filtered_rather_than_escaped(): void {
        $this->resetAfterTest();
        $this->make_resource();
        set_config('catalogueintroenabled', 1, 'local_oerexchange');
        set_config('catalogueintro', '<strong>Hi</strong>', 'local_oerexchange');

        $html = $this->render_catalogue();

        $this->assertStringContainsString('<strong>Hi</strong>', $html);
        $this->assertStringNotContainsString('&lt;strong&gt;', $html);
    }

    /**
     * Cleaning is deliberately left on even though the author is an admin,
     * because the output is world-readable and an admin's paste can carry
     * whatever the page it came from carried.
     *
     * @return void
     */
    public function test_dangerous_markup_in_the_content_is_cleaned(): void {
        $this->resetAfterTest();
        $this->make_resource();
        set_config('catalogueintroenabled', 1, 'local_oerexchange');
        set_config('catalogueintro', '<p>Hello</p><script>alert(1)</script>', 'local_oerexchange');

        $html = $this->render_catalogue();

        $this->assertStringContainsString('Hello', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
    }
}
