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

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for licence_display — capitalisation is a CSS class, never a change
 * to the text.
 *
 * The point of every test here is that the rendered TEXT is the stored
 * shortname whatever the setting says; only the class list moves. That is
 * what keeps the catalogue's licence filter — whose values are the stored
 * shortnames — comparing like with like.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(licence_display::class)]
final class licence_display_test extends \advanced_testcase {
    /**
     * The text a browser would show, recovered from the helper's markup.
     *
     * @param string $html
     * @return string
     */
    protected function rendered_text(string $html): string {
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function test_the_modifier_class_is_attached_when_the_setting_is_on(): void {
        $this->resetAfterTest();
        set_config('uppercaselicencenames', 1, 'local_oerexchange');

        $html = licence_display::html('cc-sa-4.0');

        $this->assertStringContainsString(licence_display::CLASS_BASE, $html);
        $this->assertStringContainsString(licence_display::CLASS_UPPER, $html);
    }

    public function test_only_the_base_class_is_attached_when_the_setting_is_off(): void {
        $this->resetAfterTest();
        set_config('uppercaselicencenames', 0, 'local_oerexchange');

        $html = licence_display::html('cc-sa-4.0');

        $this->assertStringContainsString(licence_display::CLASS_BASE, $html);
        $this->assertStringNotContainsString(licence_display::CLASS_UPPER, $html);
    }

    public function test_an_unsaved_setting_falls_back_to_capitals_rather_than_off(): void {
        $this->resetAfterTest();
        // The state under test is "no row stored at all", which a site reaches
        // between the plugin files landing and upgrade.php running, and which
        // any code path reading this setting must survive.
        //
        // It has to be FORCED, not assumed: admin_apply_default_settings()
        // writes every declared default at install time, so a freshly
        // installed site — which is what CI builds — already has '1' stored.
        // An earlier version of this test simply asserted get_config() was
        // false and passed locally purely because the setting had not been
        // created there yet; CI failed it immediately.
        unset_config('uppercaselicencenames', 'local_oerexchange');
        $this->assertFalse(get_config('local_oerexchange', 'uppercaselicencenames'));

        $this->assertTrue(licence_display::is_uppercased());
        $this->assertStringContainsString(licence_display::CLASS_UPPER, licence_display::html('cc-sa-4.0'));
    }

    /**
     * The whole point of the CSS approach: the text never changes.
     *
     * @param string $shortname
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('shortname_provider')]
    public function test_the_rendered_text_is_the_stored_shortname_whatever_the_setting(string $shortname): void {
        $this->resetAfterTest();

        set_config('uppercaselicencenames', 1, 'local_oerexchange');
        $this->assertSame($shortname, $this->rendered_text(licence_display::html($shortname)));

        set_config('uppercaselicencenames', 0, 'local_oerexchange');
        $this->assertSame($shortname, $this->rendered_text(licence_display::html($shortname)));
    }

    /**
     * Shortnames worth pinning, including one the catalogue really holds.
     *
     * @return array<string, array{string}>
     */
    public static function shortname_provider(): array {
        return [
            'core lowercase' => ['cc-sa-4.0'],
            'core non-cc, the one capitals flatter least' => ['allrightsreserved'],
            // A real stored value in this catalogue: mixed case, not a core
            // shortname. It must survive untouched rather than be normalised,
            // or it would stop matching the licence filter.
            'mixed case, not a core shortname' => ['CC-BY-4.0'],
            'empty' => [''],
        ];
    }

    public function test_html_special_characters_are_escaped_exactly_once(): void {
        $this->resetAfterTest();
        set_config('uppercaselicencenames', 1, 'local_oerexchange');

        $html = licence_display::html('a&b<script>');

        $this->assertStringContainsString('a&amp;b&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertSame('a&b<script>', $this->rendered_text($html));
    }
}
