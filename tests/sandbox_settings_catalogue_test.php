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

use local_oerexchange\local\sandbox\config;
use local_oerexchange\local\sandbox\settings_catalogue;

/**
 * The sandbox settings catalogue: what it stores, what it refuses, and what
 * reaches the generated configuration file.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oerexchange\local\sandbox\settings_catalogue
 */
final class sandbox_settings_catalogue_test extends \advanced_testcase {
    /**
     * A field left at "Moodle's default" is not stored and never reaches the file.
     *
     * This is the property the whole three-state design rests on: without it
     * the generated config would carry 40 lines restating Moodle's own
     * defaults, and its stamp would change whenever the catalogue grew.
     */
    public function test_default_state_is_not_stored_and_not_emitted(): void {
        $this->resetAfterTest();

        settings_catalogue::store_choices([
            'displayh5p' => settings_catalogue::UNSET,
            'enablebadges' => settings_catalogue::UNSET,
        ]);

        $this->assertSame([], settings_catalogue::stored_choices());

        $rendered = config::render(config::current());
        $this->assertStringNotContainsString('SITE_FILTER_displayh5p', $rendered);
        $this->assertStringNotContainsString('SITE_SETTING_enablebadges', $rendered);
    }

    /**
     * A chosen filter reaches the file as SITE_FILTER_<name>=<state> — the
     * line that was previously unreachable from the UI.
     */
    public function test_a_chosen_filter_is_emitted_as_a_filter_line(): void {
        $this->resetAfterTest();

        settings_catalogue::store_choices(['displayh5p' => 'off']);

        $rendered = config::render(config::current());
        $this->assertStringContainsString("SITE_FILTER_displayh5p=off\n", $rendered);
        // A filter must not also be emitted as a plain setting: the two go to
        // different tables in the built snapshot (mdl_filter_active, mdl_config).
        $this->assertStringNotContainsString('SITE_SETTING_displayh5p', $rendered);
    }

    /**
     * A chosen setting reaches the file as SITE_SETTING_<name>=<value>.
     */
    public function test_a_chosen_setting_is_emitted_as_a_setting_line(): void {
        $this->resetAfterTest();

        settings_catalogue::store_choices(['enablebadges' => '0', 'navcourselimit' => '5']);

        $rendered = config::render(config::current());
        $this->assertStringContainsString("SITE_SETTING_enablebadges=0\n", $rendered);
        $this->assertStringContainsString("SITE_SETTING_navcourselimit=5\n", $rendered);
    }

    /**
     * The headings control expands into the filterall/stringfilters pair, and
     * names whichever filters are actually on.
     */
    public function test_headings_control_expands_to_filterall_and_stringfilters(): void {
        $this->resetAfterTest();

        settings_catalogue::store_choices([
            'multilang' => 'on',
            'displayh5p' => 'on',
            'tex' => 'off',
            settings_catalogue::KEY_HEADINGS => '1',
        ]);

        $rendered = config::render(config::current());
        $this->assertStringContainsString("SITE_SETTING_filterall=1\n", $rendered);
        $this->assertStringContainsString("SITE_SETTING_stringfilters=multilang,displayh5p\n", $rendered);
        // The composite key itself is not a Moodle setting and must not leak.
        $this->assertStringNotContainsString('applytoheadings', $rendered);
        // A filter switched OFF is not something to apply to headings.
        $this->assertStringNotContainsString('tex', substr(
            $rendered,
            strpos($rendered, 'SITE_SETTING_stringfilters')
        ));
    }

    /**
     * The greying control writes its stylesheet into additionalhtmlhead, which
     * core_renderer prints into every page head.
     */
    public function test_greying_control_emits_the_stylesheet(): void {
        $this->resetAfterTest();

        settings_catalogue::store_choices([settings_catalogue::KEY_GREYOUTH5P => '1']);

        $rendered = config::render(config::current());
        $this->assertStringContainsString('SITE_SETTING_additionalhtmlhead=<style>', $rendered);
        $this->assertStringContainsString('.modtype_h5pactivity', $rendered);
        // The composite key is not a Moodle setting and must not leak.
        $this->assertStringNotContainsString('SITE_SETTING_greyouth5p', $rendered);
    }

    /**
     * Switching it off clears the value, which is how a bundle built with the
     * stylesheet gets it removed. Leaving the field alone emits nothing at all.
     */
    public function test_greying_off_clears_the_value_and_default_emits_nothing(): void {
        $this->resetAfterTest();

        settings_catalogue::store_choices([settings_catalogue::KEY_GREYOUTH5P => '0']);
        $this->assertStringContainsString("SITE_SETTING_additionalhtmlhead=\n", config::render(config::current()));

        settings_catalogue::store_choices([]);
        $this->assertStringNotContainsString('additionalhtmlhead', config::render(config::current()));
    }

    /**
     * The stylesheet has to survive a file that is parsed line by line as
     * KEY=value by a shell script, so it must carry no newline; and it must not
     * depend on double quotes, which that parsing would have to re-quote.
     *
     * This is the property that makes shipping raw markup through the config
     * safe at all, so it is asserted on the constant itself rather than on one
     * rendering of it.
     */
    public function test_the_stylesheet_is_safe_for_a_key_equals_value_line(): void {
        $css = settings_catalogue::GREYOUT_H5P_CSS;

        $this->assertDoesNotMatchRegularExpression('/[\r\n]/', $css);
        $this->assertStringNotContainsString('\\n', $css);
        $this->assertStringNotContainsString('"', $css);
        // A value may contain "=" — the reader splits on the FIRST one only
        // (oer-sandbox/scripts/common.sh: key="${line%%=*}", value="${line#*=}").
        $this->assertStringContainsString('=', $css);
    }

    /**
     * Values are checked against the field's declared type.
     */
    public function test_validate_rejects_values_the_field_cannot_take(): void {
        $this->assertTrue(settings_catalogue::validate('displayh5p', 'off'));
        $this->assertFalse(settings_catalogue::validate('displayh5p', 'maybe'));

        $this->assertTrue(settings_catalogue::validate('enablebadges', '0'));
        $this->assertFalse(settings_catalogue::validate('enablebadges', '2'));

        $this->assertTrue(settings_catalogue::validate('navcourselimit', '20'));
        $this->assertFalse(settings_catalogue::validate('navcourselimit', 'lots'));
        $this->assertFalse(settings_catalogue::validate('navcourselimit', '-1'));

        $this->assertTrue(settings_catalogue::validate('defaulthomepage', '3'));
        $this->assertFalse(settings_catalogue::validate('defaulthomepage', '2'));

        $this->assertFalse(settings_catalogue::validate('nosuchsetting', '1'));
    }

    /**
     * A text value that could forge a second KEY=value line in the generated
     * file is refused, exactly as parse_advanced() refuses one.
     */
    public function test_validate_refuses_a_text_value_that_could_forge_a_config_line(): void {
        $this->assertTrue(settings_catalogue::validate('theme', 'boost'));
        $this->assertFalse(settings_catalogue::validate('theme', "boost\nBUNDLES=evil"));
        $this->assertFalse(settings_catalogue::validate('theme', 'boost\nBUNDLES=evil'));
    }

    /**
     * A stored value that the catalogue no longer accepts is dropped, not
     * fatal: a configuration page that cannot render is the worse failure.
     */
    public function test_unusable_stored_values_are_dropped_rather_than_thrown(): void {
        $this->resetAfterTest();

        set_config(
            settings_catalogue::STORE,
            json_encode(['displayh5p' => 'off', 'retiredkey' => '1', 'enablebadges' => 'banana']),
            'local_oerexchange'
        );

        $this->assertSame(['displayh5p' => 'off'], settings_catalogue::stored_choices());
    }

    /**
     * A name set by both the catalogue and the Advanced box is reported, so the
     * form can refuse the save.
     */
    public function test_collisions_names_settings_set_in_both_places(): void {
        $advanced = ['enablebadges' => '1', 'somethingelse' => '2'];
        $choices = ['enablebadges' => '0', 'displayh5p' => 'off'];

        $this->assertSame(['enablebadges'], settings_catalogue::collisions($advanced, $choices));
    }

    /**
     * The headings control's expansions collide too, though the admin never
     * sees those two names on the form.
     */
    public function test_collisions_includes_the_names_the_headings_control_expands_into(): void {
        $advanced = ['filterall' => '0'];
        $choices = ['multilang' => 'on', settings_catalogue::KEY_HEADINGS => '1'];

        $this->assertSame(['filterall'], settings_catalogue::collisions($advanced, $choices));
    }

    /**
     * Every catalogue field must have a label that resolves. A missing string
     * would render as the bare key on the page, which is the kind of defect
     * that ships unnoticed because nothing errors.
     */
    public function test_every_field_has_a_resolving_label(): void {
        $unresolved = [];
        foreach (settings_catalogue::fields() as $key => $field) {
            if (!get_string_manager()->string_exists($field['labelid'], $field['labelcomponent'])) {
                $unresolved[] = $key . ' (' . $field['labelcomponent'] . '/' . $field['labelid'] . ')';
            }
        }

        $this->assertSame([], $unresolved, 'catalogue fields whose label string does not exist');
    }

    /**
     * No catalogue key may be plugin-scoped: the build writes each pair into
     * the snapshot's mdl_config and never config_plugins, so such a field
     * would be silently inert in the built bundle.
     */
    public function test_no_field_is_plugin_scoped(): void {
        foreach (array_keys(settings_catalogue::fields()) as $key) {
            $this->assertStringNotContainsString('/', $key);
            $this->assertStringNotContainsString('|', $key);
        }
    }
}
