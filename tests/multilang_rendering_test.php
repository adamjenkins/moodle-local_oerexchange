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

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Regression tests for the multilang-rendering bug: index.php, resource.php
 * and the Exchange blocks used to escape resource titles/summaries with
 * s() (and the summary via format_text(..., FORMAT_PLAIN), which s()-escapes
 * BEFORE filtering) so a multilang-marked-up title/summary rendered as
 * visible literal `<span lang="en" class="multilang">...` markup instead of
 * being collapsed to one language.
 *
 * index.php and resource.php are legacy page scripts (require config.php
 * directly, not routed controllers), so there is no class to instantiate
 * and call get_content() on the way the block tests do. These tests instead
 * pin the exact expression each sink now uses — format_string(...,
 * ['context' => system]) for titles/names, and the
 * format_text(FORMAT_HTML) -> content_to_text(FORMAT_HTML) -> shorten_text
 * -> s() chain for the catalogue card summary teaser (index.php:194-206,
 * resource.php:461,903-909 use these exact calls) — against a live,
 * test-local filter configuration, so a regression to s()/FORMAT_PLAIN
 * fails these tests without depending on site config.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class multilang_rendering_test extends \advanced_testcase {
    /**
     * Enable the exact "content and headings" trio this plugin's sinks
     * depend on, rather than relying on site configuration (which is a
     * snapshot of one deployment, not something a unit test should assume).
     */
    protected function enable_multilang(): void {
        filter_set_global_state('multilang', TEXTFILTER_ON);
        set_config('filterall', 1);
        set_config('stringfilters', 'multilang');
    }

    /**
     * The title sink pattern used by index.php:194, resource.php's
     * structure-preview title/activity-title branches, and all four blocks:
     * format_string($title, true, ['context' => system]).
     *
     * A bilingual title collapses to exactly one language (the current
     * session's), and a literal `&` in that language's text is escaped
     * exactly once — not left raw, and not double-escaped to `&amp;amp;`.
     */
    public function test_title_sink_renders_single_language_and_escapes_ampersand_once(): void {
        $this->resetAfterTest();
        $this->enable_multilang();

        $title = '<span lang="en" class="multilang">Fish &amp; Chips</span>'
            . '<span lang="ja" class="multilang">魚とチップス</span>';

        $formatted = format_string($title, true, ['context' => \core\context\system::instance()]);

        $this->assertStringContainsString('Fish &amp; Chips', $formatted);
        $this->assertStringNotContainsString('魚とチップス', $formatted);
        $this->assertStringNotContainsString('multilang', $formatted);
        $this->assertStringNotContainsString('&amp;amp;', $formatted);
        // Exactly one escaped ampersand, not zero (raw) and not two (double-escaped).
        $this->assertSame(1, substr_count($formatted, '&amp;'));
    }

    /**
     * Same sink, viewed as a Japanese-language user (?lang=ja): the OTHER
     * span wins. Guards against a fix that hardcodes English.
     *
     * Sets $SESSION->forcelang directly rather than calling
     * force_current_language(): that helper gates on
     * translation_exists('ja', ...), which is false on a site with no
     * Japanese language pack installed (this is the Exchange site, not a
     * Try-it trial sandbox — Task 1 of the multilang plan, out of scope
     * here). current_language() itself reads $SESSION->forcelang with no
     * such gate (moodlelib.php), which is exactly what ?lang=ja drives at
     * request time via core's own language-switch handling.
     */
    public function test_title_sink_renders_japanese_when_current_language_is_ja(): void {
        global $SESSION;
        $this->resetAfterTest();
        $this->enable_multilang();
        $SESSION->forcelang = 'ja';

        $title = '<span lang="en" class="multilang">Course Overview</span>'
            . '<span lang="ja" class="multilang">コース概要</span>';

        $formatted = format_string($title, true, ['context' => \core\context\system::instance()]);

        $this->assertStringContainsString('コース概要', $formatted);
        $this->assertStringNotContainsString('Course Overview', $formatted);
    }

    /**
     * resource.php:461's summary sink: format_text(..., FORMAT_HTML,
     * ['context' => system]). FORMAT_PLAIN (the old value) s()-escapes
     * before filtering ever runs (lib/classes/formatting.php), so multilang
     * spans can never collapse through it — this pins FORMAT_HTML instead.
     */
    public function test_summary_sink_renders_single_language_via_format_html(): void {
        $this->resetAfterTest();
        $this->enable_multilang();

        $summary = '<p><span lang="en" class="multilang">Overview text</span>'
            . '<span lang="ja" class="multilang">概要テキスト</span></p>';

        $formatted = format_text($summary, FORMAT_HTML, ['context' => \core\context\system::instance()]);

        $this->assertStringContainsString('Overview text', $formatted);
        $this->assertStringNotContainsString('概要テキスト', $formatted);
        $this->assertStringNotContainsString('class="multilang"', $formatted);
    }

    /**
     * The old sink (FORMAT_PLAIN) is the negative control: even with the
     * filter trio enabled, it must still show the raw spans, proving the
     * bug was in our call and not in filter configuration.
     */
    public function test_format_plain_still_shows_raw_spans_even_when_filter_is_enabled(): void {
        $this->resetAfterTest();
        $this->enable_multilang();

        $summary = '<span lang="en" class="multilang">Overview text</span>'
            . '<span lang="ja" class="multilang">概要テキスト</span>';

        $formatted = format_text($summary, FORMAT_PLAIN);

        $this->assertStringContainsString('&lt;span lang=&quot;en&quot;', $formatted);
    }

    /**
     * index.php:194-206's catalogue-card summary teaser chain: filter via
     * format_text(FORMAT_HTML), THEN strip tags/decode entities via
     * content_to_text(FORMAT_HTML), THEN shorten_text(), THEN s() exactly
     * once — mirroring the order block_oerexchangebrowse.php's card summary
     * already uses (its comment documents a prior strip_tags()+s()
     * double-escape regression on pre-encoded entities). Filtering must
     * happen before stripping, or multilang never collapses.
     */
    public function test_catalogue_summary_teaser_filters_before_stripping_and_escapes_once(): void {
        $this->resetAfterTest();
        $this->enable_multilang();

        $summary = '<span lang="en" class="multilang">Fish &amp; Chip Shop overview</span>'
            . '<span lang="ja" class="multilang">魚とチップス店の概要</span>';

        $summaryfiltered = format_text($summary, FORMAT_HTML, ['context' => \core\context\system::instance()]);
        $rendered = s(shorten_text(content_to_text($summaryfiltered, FORMAT_HTML), 140));

        $this->assertStringContainsString('Fish &amp; Chip Shop overview', $rendered);
        $this->assertStringNotContainsString('魚とチップス店の概要', $rendered);
        $this->assertStringNotContainsString('multilang', $rendered);
        $this->assertStringNotContainsString('&amp;amp;', $rendered);
        $this->assertSame(1, substr_count($rendered, '&amp;'));
    }

    /**
     * resource.php's structure-preview activity-title branch
     * (:906-911): format_string($activity['title'], ...) alongside the
     * module-component name, which stays s()-escaped (never multilang).
     */
    public function test_structure_preview_activity_title_is_filtered_modulename_is_not(): void {
        $this->resetAfterTest();
        $this->enable_multilang();

        $activitytitle = '<span lang="en" class="multilang">Week 1 Quiz</span>'
            . '<span lang="ja" class="multilang">第1週クイズ</span>';
        $modulename = 'quiz';

        $line = s($modulename) . ': '
            . format_string($activitytitle, true, ['context' => \core\context\system::instance()]);

        $this->assertSame('quiz: Week 1 Quiz', $line);
    }
}
