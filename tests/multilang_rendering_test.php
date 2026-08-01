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
 * Later additions widen this to the other sink SHAPES the same audit found,
 * each of which needs a different call because of where the value lands:
 * an html_writer ATTRIBUTE (format_string with 'escape' => false, since
 * html_writer escapes attributes itself), a PAGE HEADING/TITLE (raw value —
 * set_title()/set_heading() format_string() it themselves), a PLAIN-TEXT
 * share payload (filter, then flatten with content_to_text()), and a
 * PARAM_TEXT column (format_string, because PARAM_TEXT deliberately
 * preserves multilang markup). The heading and PARAM_TEXT tests drive real
 * core code (moodle_page, clean_param) rather than pinning an expression.
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

    /**
     * The ATTRIBUTE sink: resource.php's cover-image alt text (and the
     * matching one on the profile page's resource cards).
     *
     * html_writer escapes every attribute value with s() itself
     * (lib/classes/output/html_writer.php:113), so the value handed to it
     * must be filtered but NOT pre-escaped. format_string(..., ['escape' =>
     * false]) looks like the answer and is what core uses for group names,
     * but it is not enough: 'escape' governs format_string's OWN ampersand
     * escaping only (lib/classes/formatting.php), while clean_text() still
     * encodes a bare '&', and a value stored with a pre-encoded entity — as
     * the fixture below has — is untouched by the option in either
     * direction. html_entity_decode() after filtering handles both, and is
     * the idiom block_oerexchangequicklinks already uses for its aria-labels.
     *
     * Three things must hold at once: one language, no literal span markup,
     * and the ampersand escaped exactly once rather than twice.
     */
    public function test_attribute_sink_filters_without_double_escaping_the_ampersand(): void {
        $this->resetAfterTest();
        $this->enable_multilang();

        $title = '<span lang="en" class="multilang">Fish &amp; Chips</span>'
            . '<span lang="ja" class="multilang">魚とチップス</span>';

        $img = \html_writer::empty_tag('img', [
            'src' => 'https://example.com/cover.png',
            'alt' => html_entity_decode(
                format_string($title, true, ['context' => \core\context\system::instance()]),
                ENT_QUOTES,
                'UTF-8'
            ),
        ]);

        $this->assertStringContainsString('alt="Fish &amp; Chips"', $img);
        $this->assertStringNotContainsString('魚とチップス', $img);
        $this->assertStringNotContainsString('multilang', $img);
        $this->assertStringNotContainsString('&amp;amp;', $img);
    }

    /**
     * Decoding after filtering must not be mistaken for "unescaped".
     * clean_text() has already stripped dangerous markup by then, and
     * html_writer escapes the attribute value on the way out regardless, so
     * a <script> in a title can never break out of the alt attribute.
     */
    public function test_the_attribute_sink_still_cleans_dangerous_markup(): void {
        $this->resetAfterTest();
        $this->enable_multilang();

        $title = 'Chemistry<script>alert(1)</script>';

        $formatted = html_entity_decode(
            format_string($title, true, ['context' => \core\context\system::instance()]),
            ENT_QUOTES,
            'UTF-8'
        );
        $img = \html_writer::empty_tag('img', ['src' => 'https://example.com/c.png', 'alt' => $formatted]);

        $this->assertStringNotContainsString('<script>', $formatted);
        $this->assertStringNotContainsString('<script>', $img);
        $this->assertStringContainsString('Chemistry', $img);
    }

    /**
     * $PAGE->set_title()/set_heading() run format_string() over the whole
     * string themselves (lib/pagelib.php: set_title() at :1424,
     * set_heading() at :1455, whose $applyformatting parameter defaults to
     * true) — so share_upload_mbz.php and share_upload_data.php now pass the
     * RAW title into their get_string() heading rather than s()-escaping it
     * first, which is what defeated that filtering.
     *
     * Drives the real moodle_page methods, not a pinned expression.
     */
    public function test_page_heading_and_title_filter_a_raw_multilang_title(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->enable_multilang();

        $PAGE->set_context(\core\context\system::instance());
        $title = '<span lang="en" class="multilang">Chemistry</span>'
            . '<span lang="ja" class="multilang">化学</span>';

        $PAGE->set_heading($title);
        $PAGE->set_title($title);

        $this->assertSame('Chemistry', $PAGE->heading);
        $this->assertStringContainsString('Chemistry', $PAGE->title);
        $this->assertStringNotContainsString('化学', $PAGE->heading);
        $this->assertStringNotContainsString('化学', $PAGE->title);
        $this->assertStringNotContainsString('multilang', $PAGE->heading);
        $this->assertStringNotContainsString('multilang', $PAGE->title);
    }

    /**
     * The negative control for the sink above: the old s()-first form. Even
     * with the filter trio on, pre-escaping leaves the heading showing
     * literal, visible span markup — proving the defect was in our call and
     * not in filter configuration.
     */
    public function test_page_heading_cannot_filter_a_pre_escaped_title(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->enable_multilang();

        $PAGE->set_context(\core\context\system::instance());
        $title = '<span lang="en" class="multilang">Chemistry</span>'
            . '<span lang="ja" class="multilang">化学</span>';

        $PAGE->set_heading(s($title));

        $this->assertStringContainsString('multilang', $PAGE->heading);
        $this->assertStringContainsString('化学', $PAGE->heading);
    }

    /**
     * The PLAIN-TEXT sink: resource.php's share payload. The title ends up
     * in a tweet body, a mailto: subject, an sms: body and
     * navigator.share()'s title, none of which render HTML entities — so the
     * title is filtered (multilang collapses) and then flattened back to
     * plain text, leaving a real '&' rather than format_string()'s '&amp;'.
     */
    public function test_share_title_is_filtered_then_flattened_to_plain_text(): void {
        $this->resetAfterTest();
        $this->enable_multilang();

        $title = '<span lang="en" class="multilang">Fish &amp; Chips</span>'
            . '<span lang="ja" class="multilang">魚とチップス</span>';

        $sharetitle = content_to_text(
            format_string($title, true, ['context' => \core\context\system::instance()]),
            FORMAT_HTML
        );

        $this->assertSame('Fish & Chips', $sharetitle);
        $this->assertStringNotContainsString('&amp;', $sharetitle);
        $this->assertStringNotContainsString('multilang', $sharetitle);
    }

    /**
     * The PARAM_TEXT sink shared by the review fields (contexttext,
     * adaptationtext, outcometext), the author's Try-it-disabled reason and
     * the registered site name.
     *
     * PARAM_TEXT exists precisely to let multilang markup through — core's
     * own cleaner comments "Leave only tags needed for multilang"
     * (lib/classes/param.php, clean_param_value_text()), and PARAM_TEXT is
     * documented in moodlelib.php as "general plain text compatible with
     * multilang filter". So a PARAM_TEXT column rendered with s() is a
     * multilang bug by construction: the round trip below proves the markup
     * survives storage and must therefore be filtered at display time.
     */
    public function test_param_text_preserves_multilang_which_format_string_then_collapses(): void {
        $this->resetAfterTest();
        $this->enable_multilang();

        $submitted = '<span lang="en" class="multilang">Used in first-year chemistry</span>'
            . '<span lang="ja" class="multilang">一年生の化学で使用</span>';

        // What required_param('reviewcontext', PARAM_TEXT) stores.
        $stored = clean_param($submitted, PARAM_TEXT);
        $this->assertStringContainsString('class="multilang"', $stored);

        $rendered = format_string($stored, true, ['context' => \core\context\system::instance()]);

        $this->assertStringContainsString('Used in first-year chemistry', $rendered);
        $this->assertStringNotContainsString('一年生の化学で使用', $rendered);
        $this->assertStringNotContainsString('multilang', $rendered);
    }
}
