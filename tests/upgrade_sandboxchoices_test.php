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

use local_oerexchange\local\sandbox\settings_catalogue;

/**
 * The upgrade step that migrates the two retired multilang settings into the
 * settings catalogue.
 *
 * Exercises the real upgrade function rather than a copy of its logic: the
 * point of the step is what it does to a site that already has the old
 * settings stored, and a reimplementation in the test could agree with itself
 * while disagreeing with the shipped code.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_local_oerexchange_upgrade
 */
final class upgrade_sandboxchoices_test extends \advanced_testcase {
    /**
     * Run the upgrade from just before the catalogue step.
     */
    private function run_upgrade_step(): void {
        global $CFG;
        // Neither is in the PHPUnit bootstrap: upgrade.php is only ever loaded
        // by the upgrade process, which has already required upgradelib.php by
        // the time it does so.
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/oerexchange/db/upgrade.php');

        // The test database is already at the new version, so the step's
        // closing savepoint would refuse as a downgrade. Put the stored version
        // back to where a site about to run this step actually sits;
        // resetAfterTest() undoes it.
        set_config('version', 2026081902, 'local_oerexchange');

        xmldb_local_oerexchange_upgrade(2026081902);
    }

    /**
     * Both switches on: the site keeps emitting the multilang filter and the
     * headings behaviour, and the old settings are gone.
     */
    public function test_both_switches_on_migrate_to_the_catalogue(): void {
        $this->resetAfterTest();
        set_config('sandboxmultilang', 1, 'local_oerexchange');
        set_config('sandboxmultilangheadings', 1, 'local_oerexchange');

        $this->run_upgrade_step();

        $this->assertSame(
            ['multilang' => 'on', settings_catalogue::KEY_HEADINGS => '1'],
            settings_catalogue::stored_choices()
        );
        $this->assertFalse(get_config('local_oerexchange', 'sandboxmultilang'));
        $this->assertFalse(get_config('local_oerexchange', 'sandboxmultilangheadings'));
    }

    /**
     * The filter on but not the headings extension.
     */
    public function test_filter_on_without_headings(): void {
        $this->resetAfterTest();
        set_config('sandboxmultilang', 1, 'local_oerexchange');
        set_config('sandboxmultilangheadings', 0, 'local_oerexchange');

        $this->run_upgrade_step();

        $this->assertSame(
            ['multilang' => 'on', settings_catalogue::KEY_HEADINGS => '0'],
            settings_catalogue::stored_choices()
        );
    }

    /**
     * Explicitly off is carried across as an explicit "off", which is not the
     * same as never having been asked: it emits SITE_FILTER_multilang=off.
     */
    public function test_switch_explicitly_off_migrates_as_off(): void {
        $this->resetAfterTest();
        set_config('sandboxmultilang', 0, 'local_oerexchange');

        $this->run_upgrade_step();

        $this->assertSame(['multilang' => 'off'], settings_catalogue::stored_choices());
    }

    /**
     * A site that never touched the page stores nothing at all — it must not
     * acquire a choice it never made.
     */
    public function test_a_site_that_never_configured_it_stores_nothing(): void {
        $this->resetAfterTest();
        unset_config('sandboxmultilang', 'local_oerexchange');
        unset_config('sandboxmultilangheadings', 'local_oerexchange');

        $this->run_upgrade_step();

        $this->assertSame([], settings_catalogue::stored_choices());
        $this->assertFalse(get_config('local_oerexchange', settings_catalogue::STORE));
    }
}
