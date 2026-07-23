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
    // Own category, nested under Plugins, instead of everything landing in
    // the generic "Local plugins" list indistinguishable from every other
    // local plugin (found live, 2026-07-19: 4 pages were all flattened
    // there with no grouping at all).
    $ADMIN->add('localplugins', new admin_category(
        'local_oerexchange_category',
        get_string('pluginname', 'local_oerexchange')
    ));

    $settings = new admin_settingpage('local_oerexchange', get_string('generalsettings', 'local_oerexchange'));
    $ADMIN->add('local_oerexchange_category', $settings);

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

    // Anonymous access, corrected against what the code actually does
    // (verified live, 2026-07-19 - an earlier draft of this setting
    // wrongly assumed resource.php and "Try it" required login; neither
    // does: index.php has never gated browsing, resource.php only calls
    // require_login() inside the report/review submission branches (not
    // for viewing), and sandbox_launch.php has no login gate at all and
    // already handles the anonymous case explicitly (`isloggedin() &&
    // !isguestuser() ? $USER->id : null`). The one real gap: the visible
    // "Download" button on resource.php links to download.php with a
    // plain unsigned id, which does call require_login() when unsigned -
    // so an anonymous visitor can browse, view, and Try it, then hit a
    // login wall specifically on Download. Off by default so this ships
    // as a no-op until an admin deliberately opts in.
    $settings->add(new admin_setting_heading(
        'local_oerexchange/anonymousheading',
        get_string('settings_anonymousheading', 'local_oerexchange'),
        get_string('settings_anonymousheading_desc', 'local_oerexchange')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_oerexchange/anonymousdownload',
        get_string('settings_anonymousdownload', 'local_oerexchange'),
        get_string('settings_anonymousdownload_desc', 'local_oerexchange'),
        0
    ));

    // Which destinations the "Share this resource" / "Share my profile"
    // disclosures offer. Every network target is a plain link to that
    // network's own share endpoint - no third-party script or SDK is ever
    // loaded onto a catalogue page, so turning one on tells that network
    // nothing about visitors who don't click it.
    $settings->add(new admin_setting_heading(
        'local_oerexchange/shareheading',
        get_string('settings_shareheading', 'local_oerexchange'),
        get_string('settings_shareheading_desc', 'local_oerexchange')
    ));

    $sharechoices = [];
    foreach (\local_oerexchange\local\share_targets::ALL as $sharekey) {
        $sharechoices[$sharekey] = get_string('sharetarget_' . $sharekey, 'local_oerexchange');
    }
    $settings->add(new admin_setting_configmulticheckbox(
        'local_oerexchange/sharetargets',
        get_string('settings_sharetargets', 'local_oerexchange'),
        get_string('settings_sharetargets_desc', 'local_oerexchange'),
        array_fill_keys(\local_oerexchange\local\share_targets::ALL, 1),
        $sharechoices
    ));

    $settings->add(new admin_setting_heading(
        'local_oerexchange/badgesheading',
        get_string('settings_badgesheading', 'local_oerexchange'),
        get_string('settings_badgesheading_desc', 'local_oerexchange')
    ));

    $settings->add(new admin_setting_configtext(
        'local_oerexchange/badge_trustedcontributor_minresources',
        get_string('settings_badge_minresources', 'local_oerexchange'),
        get_string('settings_badge_minresources_desc', 'local_oerexchange'),
        \local_oerexchange\local\badge_manager::DEFAULT_MINRESOURCES,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_oerexchange/badge_trustedcontributor_mindownloads',
        get_string('settings_badge_mindownloads', 'local_oerexchange'),
        get_string('settings_badge_mindownloads_desc', 'local_oerexchange'),
        \local_oerexchange\local\badge_manager::DEFAULT_MINDOWNLOADS,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_oerexchange/badge_trustedcontributor_minrating',
        get_string('settings_badge_minrating', 'local_oerexchange'),
        get_string('settings_badge_minrating_desc', 'local_oerexchange'),
        \local_oerexchange\local\badge_manager::DEFAULT_MINRATING,
        PARAM_FLOAT
    ));

    $ADMIN->add('local_oerexchange_category', new admin_externalpage(
        'local_oerexchange_managesites',
        get_string('managesitestitle', 'local_oerexchange'),
        new moodle_url('/local/oerexchange/manage_sites.php'),
        'local/oerexchange:managesites'
    ));

    $ADMIN->add('local_oerexchange_category', new admin_externalpage(
        'local_oerexchange_manageallowlist',
        get_string('managepluginallowlisttitle', 'local_oerexchange'),
        new moodle_url('/local/oerexchange/manage_allowlist.php'),
        'local/oerexchange:managesites'
    ));

    $ADMIN->add('local_oerexchange_category', new admin_externalpage(
        'local_oerexchange_moderate',
        get_string('moderatetitle', 'local_oerexchange'),
        new moodle_url('/local/oerexchange/moderate.php'),
        'local/oerexchange:moderate'
    ));
}
