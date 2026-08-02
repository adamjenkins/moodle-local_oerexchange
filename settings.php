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

// Own category, nested under Plugins, instead of everything landing in the
// generic "Local plugins" list indistinguishable from every other local
// plugin (found live, 2026-07-19: 4 pages were all flattened there with no
// grouping at all). Registered unconditionally (not inside `if
// ($hassiteconfig)` below) because the four admin_externalpage entries added
// at the bottom of this file are gated on THIS plugin's own capabilities
// (managesites/moderate/managesandbox), not moodle/site:config, and need
// this category to exist for a user who holds one of those but not
// site:config — see those entries' own comment for why they too must stay
// outside the $hassiteconfig guard.
$ADMIN->add('localplugins', new admin_category(
    'local_oerexchange_category',
    get_string('pluginname', 'local_oerexchange')
));

if ($hassiteconfig) {
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

    // Off by default: relaxing core's curl SSRF guard and TLS verification
    // is only ever safe for a sandbox base URL the admin fully controls and
    // knows to be self-hosted (private network and/or self-signed cert) -
    // see bundle_stamp::fetch()'s docblock for the full reasoning, modeled
    // on local_oerclient\exchange_client's own equivalent, equally opt-in
    // 'acceptinvalidcerts' setting.
    $settings->add(new admin_setting_configcheckbox(
        'local_oerexchange/sandboxbaseurlinsecure',
        get_string('settings_sandboxbaseurlinsecure', 'local_oerexchange'),
        get_string('settings_sandboxbaseurlinsecure_desc', 'local_oerexchange'),
        0
    ));

    // Above this size a trial stops reporting progress (the sandbox engine's
    // own fast-download budget is 50 MiB; over it the backup is fetched
    // inside PHP with no percentage shown at all), so this is where the
    // resource page starts warning visitors what they are in for rather than
    // leaving them on a button that appears to do nothing for minutes.
    // Nothing is ever refused on size here — see size_advice's docblock for
    // the 2026-08-02 measurement behind the default.
    $settings->add(new admin_setting_configtext(
        'local_oerexchange/sandboxwarnbytes',
        get_string('settings_sandboxwarnbytes', 'local_oerexchange'),
        get_string(
            'settings_sandboxwarnbytes_desc',
            'local_oerexchange',
            display_size(\local_oerexchange\local\size_advice::DEFAULT_WARN_BYTES)
        ),
        \local_oerexchange\local\size_advice::DEFAULT_WARN_BYTES,
        PARAM_INT
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

    // What an anonymous visitor gets at the site root. Catalogue browsing
    // has always been anonymous (index.php has never had a login gate);
    // the only thing between a visitor and it is the front page, which
    // sends them to the login form whenever forcelogin is on. This lets an
    // admin open that one door without opening the whole site.
    $settings->add(new admin_setting_heading(
        'local_oerexchange/publiclandingheading',
        get_string('settings_publiclandingheading', 'local_oerexchange'),
        get_string('settings_publiclandingheading_desc', 'local_oerexchange')
    ));

    // Off by default, like every other opt-in setting in this plugin:
    // turning it on changes what the whole world sees at the site root, so
    // it must never happen as a side effect of installing or upgrading.
    $settings->add(new admin_setting_configcheckbox(
        'local_oerexchange/publiclanding',
        get_string('settings_publiclanding', 'local_oerexchange'),
        get_string(
            'settings_publiclanding_desc',
            'local_oerexchange',
            (new moodle_url('/', ['redirect' => 0]))->out()
        ),
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

    // Which licences courseware may be shared under. Enforced server-side in
    // resource_manager::publish() (so the web-service publish path a client
    // site uses is covered too, not just this site's own upload forms) and
    // advertised to clients via get_config's acceptedlicenses. Choices are
    // the licences enabled in this site's own licence manager; until first
    // save the Creative Commons set applies (a multicheckbox renders
    // all-unticked until then regardless of this default — the runtime
    // fallback in allowed_licenses::shortnames() is what makes the default
    // real). Restricting the list only affects future shares: published
    // resources keep the licence they were shared under.
    $settings->add(new admin_setting_heading(
        'local_oerexchange/licensesheading',
        get_string('settings_licensesheading', 'local_oerexchange'),
        get_string(
            'settings_licensesheading_desc',
            'local_oerexchange',
            \tool_licensemanager\helper::get_licensemanager_url()->out(false)
        )
    ));

    require_once($CFG->libdir . '/licenselib.php');
    $settings->add(new admin_setting_configmulticheckbox(
        'local_oerexchange/allowedlicenses',
        get_string('settings_allowedlicenses', 'local_oerexchange'),
        get_string('settings_allowedlicenses_desc', 'local_oerexchange'),
        array_fill_keys(\local_oerexchange\local\allowed_licenses::CC_SHORTNAMES, 1),
        license_manager::get_active_licenses_as_array()
    ));

    // Display only — it switches a CSS class on, nothing more. The stored and
    // rendered shortname is unchanged either way, so turning this off cannot
    // affect what may be published, what the catalogue filter matches, or what
    // get_config advertises to client sites.
    $settings->add(new admin_setting_configcheckbox(
        'local_oerexchange/uppercaselicencenames',
        get_string('settings_uppercaselicencenames', 'local_oerexchange'),
        get_string('settings_uppercaselicencenames_desc', 'local_oerexchange'),
        1
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

    $settings->add(new admin_setting_heading(
        'local_oerexchange/staleheading',
        get_string('settings_staleheading', 'local_oerexchange'),
        get_string('settings_staleheading_desc', 'local_oerexchange')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_oerexchange/staleenabled',
        get_string('settings_staleenabled', 'local_oerexchange'),
        get_string('settings_staleenabled_desc', 'local_oerexchange'),
        0
    ));

    $settings->add(new admin_setting_configduration(
        'local_oerexchange/stalethreshold',
        get_string('settings_stalethreshold', 'local_oerexchange'),
        get_string('settings_stalethreshold_desc', 'local_oerexchange'),
        \local_oerexchange\local\stale_manager::DEFAULT_THRESHOLD,
        WEEKSECS
    ));

    $settings->add(new admin_setting_configduration(
        'local_oerexchange/stalegrace',
        get_string('settings_stalegrace', 'local_oerexchange'),
        get_string('settings_stalegrace_desc', 'local_oerexchange'),
        \local_oerexchange\local\stale_manager::DEFAULT_GRACE,
        DAYSECS
    ));
}

// Registered unconditionally (outside `if ($hassiteconfig)` above), NOT
// because these pages are exempt from any capability check, but because
// each is already gated on its OWN plugin capability
// (admin_externalpage's 4th constructor arg — managesites/moderate/
// managesandbox, never the default moodle/site:config) and
// admin_externalpage_setup() (called by sandbox_config.php) can only ever
// grant access to a page it can first locate in $ADMIN's tree for the
// CURRENT user. $hassiteconfig is `has_capability('moodle/site:config',
// ...)` for that same user — a manager role holds none of that capability
// by default — so nesting these adds inside the `if ($hassiteconfig)` block
// above meant a user with e.g. only local/oerexchange:managesandbox could
// never reach sandbox_config.php at all: admin_externalpage_setup()'s own
// $adminroot->locate() would find nothing, and since !$hassiteconfig is
// also true for that same user, it throws moodle_exception('accessdenied',
// 'admin') — confirmed live 2026-07-31 (SANDBOX-CONFIG-PLAN.md Task 12
// Defect 1) with a real managesandbox-only manager account: the page went
// from a hard fatal (missing adminlib.php require, fixed above) straight to
// "Access denied", never actually reaching the form. This exact
// unconditional-registration pattern is how core itself handles a custom
// non-site:config admin page capability — see e.g.
// report/backups/settings.php and report/log/settings.php, neither of
// which references $hassiteconfig at all.
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

$ADMIN->add('local_oerexchange_category', new admin_externalpage(
    'local_oerexchange_sandboxconfig',
    get_string('sandboxconfigtitle', 'local_oerexchange'),
    new moodle_url('/local/oerexchange/sandbox_config.php'),
    'local/oerexchange:managesandbox'
));
