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

/**
 * Settings for local_oerexchange.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_oerexchange', get_string('pluginname', 'local_oerexchange'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_heading(
        'local_oerexchange/heading',
        get_string('settingsheading', 'local_oerexchange'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_oerexchange/sandboxenabled',
        get_string('settings_sandboxenabled', 'local_oerexchange'),
        get_string('settings_sandboxenabled_desc', 'local_oerexchange'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_oerexchange/sandboxbaseurl',
        get_string('settings_sandboxbaseurl', 'local_oerexchange'),
        get_string('settings_sandboxbaseurl_desc', 'local_oerexchange'),
        '',
        PARAM_URL
    ));

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_oerexchange_managesites',
        get_string('managesitestitle', 'local_oerexchange'),
        new moodle_url('/local/oerexchange/manage_sites.php'),
        'local/oerexchange:managesites'
    ));

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_oerexchange_manageallowlist',
        get_string('managepluginallowlisttitle', 'local_oerexchange'),
        new moodle_url('/local/oerexchange/manage_allowlist.php'),
        'local/oerexchange:managesites'
    ));

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_oerexchange_moderate',
        get_string('moderatetitle', 'local_oerexchange'),
        new moodle_url('/local/oerexchange/moderate.php'),
        'local/oerexchange:moderate'
    ));
}
