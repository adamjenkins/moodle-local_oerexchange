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

/**
 * Tests for the admin-configurable list of licences sharing may use.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(allowed_licenses::class)]
final class allowed_licenses_test extends \advanced_testcase {
    public function test_the_creative_commons_set_is_allowed_before_the_admin_saves_the_setting(): void {
        $this->resetAfterTest();

        $this->assertSame(allowed_licenses::CC_SHORTNAMES, allowed_licenses::shortnames());
        $this->assertFalse(allowed_licenses::is_allowed('allrightsreserved'));
        $this->assertFalse(allowed_licenses::is_allowed('unknown'));
        $this->assertTrue(allowed_licenses::is_allowed('cc-4.0'));
    }

    public function test_cc_by_sa_is_the_default_selection_out_of_the_box(): void {
        $this->resetAfterTest();

        // Stock Moodle's site default licence is 'unknown', which is not on
        // the CC list, so it cannot steer the preselection here.
        $this->assertSame('cc-sa-4.0', allowed_licenses::default_shortname());
    }

    public function test_the_site_default_licence_is_preselected_when_it_is_allowed(): void {
        $this->resetAfterTest();

        set_config('sitedefaultlicense', 'cc-nc-4.0');

        $this->assertSame('cc-nc-4.0', allowed_licenses::default_shortname());
    }

    public function test_a_site_default_licence_off_the_allowed_list_cannot_steer_the_preselection(): void {
        $this->resetAfterTest();

        set_config('sitedefaultlicense', 'allrightsreserved');

        $this->assertSame('cc-sa-4.0', allowed_licenses::default_shortname());
    }

    public function test_the_users_remembered_licence_beats_the_site_default_when_remembering_is_on(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        set_config('rememberuserlicensepref', 1);
        set_config('sitedefaultlicense', 'cc-nc-4.0');
        set_user_preference('filepicker_recentlicense', 'cc-nd-4.0');

        $this->assertSame('cc-nd-4.0', allowed_licenses::default_shortname());
    }

    public function test_the_remembered_licence_is_ignored_when_remembering_is_off(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        set_config('rememberuserlicensepref', 0);
        set_user_preference('filepicker_recentlicense', 'cc-nd-4.0');

        $this->assertSame('cc-sa-4.0', allowed_licenses::default_shortname());
    }

    public function test_a_remembered_licence_off_the_allowed_list_cannot_steer_the_preselection(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        set_config('rememberuserlicensepref', 1);
        set_user_preference('filepicker_recentlicense', 'allrightsreserved');

        $this->assertSame('cc-sa-4.0', allowed_licenses::default_shortname());
    }

    public function test_remember_stores_the_choice_only_when_remembering_is_on(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        set_config('rememberuserlicensepref', 0);
        allowed_licenses::remember('cc-nc-4.0');
        $this->assertSame('', get_user_preferences('filepicker_recentlicense', ''));

        set_config('rememberuserlicensepref', 1);
        allowed_licenses::remember('cc-nc-4.0');
        $this->assertSame('cc-nc-4.0', get_user_preferences('filepicker_recentlicense', ''));
    }

    public function test_a_configured_list_is_the_only_one_offered(): void {
        $this->resetAfterTest();

        set_config('allowedlicenses', 'public,cc-4.0', 'local_oerexchange');

        $this->assertSame(['public', 'cc-4.0'], allowed_licenses::shortnames());
        $this->assertTrue(allowed_licenses::is_allowed('public'));
        $this->assertFalse(allowed_licenses::is_allowed('cc-sa-4.0'));
    }

    public function test_the_default_selection_falls_back_to_the_first_allowed_licence(): void {
        $this->resetAfterTest();

        // CC BY-SA is not on this admin's list, so it cannot be preselected.
        set_config('allowedlicenses', 'public,cc-4.0', 'local_oerexchange');

        $this->assertSame('public', allowed_licenses::default_shortname());
    }

    /**
     * An admin who unticks every box stores an empty string. That must mean
     * "no licence may be chosen" (sharing is effectively closed), not be
     * mistaken for "setting never saved" and silently restore the CC set —
     * same rule as the sharetargets setting.
     */
    public function test_unticking_everything_closes_sharing_rather_than_restoring_defaults(): void {
        $this->resetAfterTest();

        set_config('allowedlicenses', '', 'local_oerexchange');

        $this->assertSame([], allowed_licenses::shortnames());
        $this->assertSame([], allowed_licenses::menu());
        $this->assertNull(allowed_licenses::default_shortname());
        $this->assertFalse(allowed_licenses::is_allowed('cc-4.0'));
    }

    public function test_shortnames_core_does_not_know_or_has_disabled_are_dropped(): void {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/licenselib.php');

        \license_manager::disable('public');
        set_config('allowedlicenses', 'public,notalicence,cc-4.0', 'local_oerexchange');

        $this->assertSame(['cc-4.0'], allowed_licenses::shortnames());
    }

    public function test_the_menu_offers_core_fullnames_keyed_by_shortname(): void {
        $this->resetAfterTest();

        $menu = allowed_licenses::menu();

        $this->assertSame(allowed_licenses::CC_SHORTNAMES, array_keys($menu));
        $this->assertStringContainsString('ShareAlike', $menu['cc-sa-4.0']);
    }
}
