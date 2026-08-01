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

namespace local_oerexchange\local\sandbox;

use local_oerexchange\local\allowlist_manager;

/**
 * Moodle Playground sandbox integration (DESIGN.md §4, option B): branch
 * mapper + blueprint builder + launch-URL builder. Pure functions — no HTTP
 * calls, no orchestrator, no server-side trial execution. Replaces the
 * Podman-fleet client.php from DESIGN.md §3.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class playground {
    /**
     * Moodle versions the oer-sandbox deployment kit has actually built and
     * deployed, oldest first. Keep in sync with the kit's build list
     * (dev-docs/oer-platform/SANDBOX-UPGRADES.md).
     *
     * @var string[]
     */
    const DEPLOYED_BRANCHES = ['5.0', '5.2'];

    /**
     * Signed-download link TTL, seconds.
     */
    const SIGNED_URL_TTL = 900;

    /**
     * The bundle string a Moodle branch name corresponds to.
     *
     * Two spellings reach this method, from two different places:
     * - The allowlist (local_oerexchange_pluginallowlist.moodlebranch, written by
     *   manage_allowlist.php and validated by allowlist_manager::is_valid_branch())
     *   stores the DOTTED form, e.g. "5.2" — that is already the bundle string, so
     *   this is the identity case. (db/install.xml's column COMMENT says
     *   "e.g. MOODLE_502_STABLE"; that comment is stale and wrong — the validator
     *   and the live data are the truth. Trusting the comment instead of checking
     *   is what broke this method the first time around: it made
     *   branch_to_bundle('5.2') return '', so is_baked_in() compared '' against
     *   the real bundle string and silently returned false for every allowlist
     *   row, forever.)
     * - BUNDLES (sandbox_config.php DEFAULT_BUNDLES, classes/local/sandbox/config.php
     *   render(), oer-sandbox's fetch-moodle-source.sh) use the git branch-tag
     *   spelling, MOODLE_502_STABLE, because that is the real upstream Moodle git
     *   branch name the build script checks out.
     *
     * Both are legitimate; this is the one place they meet. The dotted form is
     * returned as-is, MOODLE_502_STABLE-style strings are converted to it, and
     * anything else (e.g. "main") maps to '' — there is no bundle for it.
     *
     * @param string $moodlebranch e.g. "5.2" (allowlist) or "MOODLE_502_STABLE" (BUNDLES/fetch-moodle-source.sh)
     * @return string e.g. "5.2", or '' when the branch has no bundle string (e.g. "main")
     */
    public static function branch_to_bundle(string $moodlebranch): string {
        if (allowlist_manager::is_valid_branch($moodlebranch)) {
            return $moodlebranch;
        }

        if (!preg_match('/^MOODLE_(\d)(\d{2})_STABLE$/', $moodlebranch, $m)) {
            return '';
        }

        return $m[1] . '.' . (int) $m[2];
    }

    /**
     * Whether a plugin is already baked into a branch's deployed bundle, so
     * the runtime installMoodlePlugin step for it should be skipped entirely
     * (nothing to install - it's already there and fully registered).
     *
     * Baked-ness is data, not a hardcoded list: an allowlisted plugin's own
     * `bake` flag (local_oerexchange_pluginallowlist.bake), gated on the
     * 'sandboxbundled' admin switch (SANDBOX-CONFIG-DESIGN.md D1/D2). The
     * switch matters as much as the flag: a bake-flagged entry in a bundle
     * nobody has actually rebuilt is NOT present, and skipping its install
     * on the strength of the flag alone would produce a broken trial — so
     * the switch being off (the default) always means false here, regardless
     * of what any entry's flag says.
     *
     * The historical basis for baking mod_quizquest into 5.2 (three
     * independent live verifications, plus a caveat about single-activity
     * restores) is preserved in dev-docs/oer-platform/discoveries/
     * 2026-07-31-quizquest-bake-verification.md now that it's admin-managed
     * data rather than a hardcoded constant.
     *
     * @param string $type
     * @param string $name
     * @param string $branch bundle string, e.g. "5.2" — see branch_to_bundle()
     * @return bool
     */
    public static function is_baked_in(string $type, string $name, string $branch): bool {
        global $DB;

        if (!get_config('local_oerexchange', 'sandboxbundled')) {
            return false;
        }

        $entries = $DB->get_records('local_oerexchange_pluginallowlist', [
            'plugintype' => $type,
            'pluginname' => $name,
            'bake' => 1,
            'status' => 'active',
        ]);
        foreach ($entries as $entry) {
            if (self::branch_to_bundle($entry->moodlebranch) === $branch) {
                return true;
            }
        }

        return false;
    }

    /**
     * Map a resource's source Moodle version to the lowest deployed playground
     * branch that is >= it (restore is forward-compatible, never backward).
     * Falls back to the highest deployed branch if the source is newer than
     * everything we've deployed.
     *
     * @param string $sourceversion e.g. "4.5.2 (Build: 20250101)" or "5.2.1+"
     * @return string e.g. "5.2"
     */
    public static function map_branch(string $sourceversion): string {
        $deployed = self::DEPLOYED_BRANCHES;
        $newest = end($deployed);

        if (!preg_match('/(\d+)\.(\d+)/', $sourceversion, $matches)) {
            // Unparseable — assume it needs the newest deployed branch.
            return $newest;
        }
        $sourcenum = ((int) $matches[1]) * 100 + (int) $matches[2];

        foreach ($deployed as $branch) {
            [$maj, $min] = explode('.', $branch);
            $branchnum = ((int) $maj) * 100 + (int) $min;
            if ($branchnum >= $sourcenum) {
                return $branch;
            }
        }

        return $newest;
    }

    /**
     * Build the blueprint JSON for a trial: install, login, allowlisted
     * required plugins, restore the resource, land on the restored course.
     *
     * @param string $resourcetitle
     * @param string $signedmbzurl signed, short-lived, same-origin-reachable URL to the .mbz
     * @param array $allowedplugininstalls list of ['type'=>, 'name'=>, 'zipurl'=>] — already
     *                                     intersected with the pluginallowlist by the caller;
     *                                     this method does not consult resource metadata for
     *                                     what to install, only what it is given.
     * @param string $branch the deployed branch this trial will boot (e.g. "5.2") — used only
     *                        to skip a runtime install step for a plugin already baked into
     *                        that branch's bundle. Pass '' (default) to always emit runtime
     *                        install steps, e.g. from a caller that hasn't resolved a branch.
     * @param string $resourcetype 'course' or 'activity' (resources.type — a 'data' resource is
     *                              never Try-it-able and must not reach this method at all; see
     *                              its caller's guard). Defaults to 'course' so any caller not
     *                              yet updated keeps today's exact restoreCourse behavior.
     * @param string $language Moodle language code the trial should come up in — normally the
     *                          launching user's own current_language(). '' or 'en' needs no pack
     *                          of its own (core ships English; every other language is a
     *                          downloaded pack, or a locally-selected one — see below), and an
     *                          unrecognisable code is dropped. Note this decides only which
     *                          language the trial OPENS in: the packs it installs are this one
     *                          plus every pack the sandbox configuration names, so a trial can
     *                          offer a switcher. An English trial on a site configuring no packs
     *                          therefore still emits no language step at all.
     * @return array the blueprint structure (JSON-encode before use)
     */
    public static function build_blueprint(
        string $resourcetitle,
        string $signedmbzurl,
        array $allowedplugininstalls,
        string $branch = '',
        string $resourcetype = 'course',
        string $language = ''
    ): array {
        $steps = [];

        $steps[] = [
            'step' => 'installMoodle',
            'options' => ['siteName' => $resourcetitle],
        ];

        // The 'sandboxbundled' setting (SANDBOX-CONFIG-DESIGN.md D1, "What
        // the switch does and does not remove") records whether THIS deployment's
        // bundle was actually rebuilt with the admin's saved language/settings
        // configuration baked into its install snapshot — confirmed to work
        // end-to-end by the 2026-07-31 baking spike (dev-docs/oer-platform/
        // discoveries/2026-07-31-baking-settings-into-the-install-snapshot.md).
        // When it is on, both the download and the settings-application step
        // below are redundant work the snapshot already did; when it is off
        // (the default, and always true for a bundle nobody has rebuilt),
        // this method must still do that work itself at boot, exactly as
        // before this switch existed.
        $bundled = (bool) get_config('local_oerexchange', 'sandboxbundled');
        $current = config::current();

        // Every language pack the sandbox configuration names gets installed,
        // not only the one the trial opens in. Found live 2026-08-01, with
        // langpacks=[ja] and a trial default of English: the pack was baked
        // into the bundle correctly and then never installed, because the only
        // code that ever reached a language step was the launching user's own
        // - so an English-speaking visitor got a trial with exactly one
        // translation, and Moodle hides the language switcher below two
        // (lib/classes/output/language_menu.php show_language_menu()). An
        // admin who configures a pack list is asking for a trial a visitor can
        // switch languages in; the trial language still decides only which of
        // them it OPENS in.
        $packs = self::trial_language_packs($current['langpacks'], $language);

        if ($bundled) {
            if ($packs) {
                // Baked (oer-sandbox scripts/bake.sh, Task 10 of
                // SANDBOX-CONFIG-PLAN.md) means the pack already lives in the
                // bundle's webroot — nothing to download, only a local copy
                // into this trial's own (freshly-created-per-boot) dataroot.
                // See build_language_selection_php()'s own docblock for why
                // this step still has to run on every boot despite the pack
                // being baked.
                $steps[] = [
                    'step' => 'runPhpCode',
                    'code' => self::build_language_selection_php($packs, $language),
                ];
            }
        } else {
            // Settings step first (mirrors the ordering the now-superseded
            // MULTILANG-TRYIT-PLAN.md Task 1 specified: after installMoodle,
            // before installLanguagePack/login), and only when there is
            // something to apply — an admin who hasn't configured anything
            // here must get exactly today's blueprint, not an extra no-op
            // WASM PHP step (every byte here also risks the base64-encoded
            // launch URL's nginx header-size limit — see
            // build_activity_restore_php()'s docblock).
            if ($current['filters'] || $current['settings']) {
                $steps[] = [
                    'step' => 'runPhpCode',
                    'code' => self::build_boot_settings_php($current['filters'], $current['settings']),
                ];
            }

            foreach ($packs as $code) {
                // A trial boots with core's English strings only; every other
                // language lives in a pack that Moodle's own lang_installer
                // downloads from download.moodle.org/langpack. Upstream's step
                // handler treats a failed download as non-fatal (it catches and
                // continues in English), so this can never cost the user their
                // trial - see reference-clones/moodle-playground/src/blueprint/
                // steps/moodle-language.js.
                //
                // setDefault on the trial's own language is not optional in
                // practice: the bundle's baseline snapshot bakes admin.lang='en',
                // and a logged-in user's own lang overrides $CFG->lang, so
                // without it the auto-logged-in admin reads English out of a
                // pack that installed perfectly. Upstream's helper repoints
                // pre-existing accounts for exactly this reason (helpers.js
                // phpInstallLanguagePacks()). The other packs are installed
                // WITHOUT it - they exist so the switcher has somewhere to
                // switch to, and making each the default in turn would just
                // leave the trial in whichever happened to be applied last.
                //
                // Whether the user can then switch at all is a separate
                // question, answered by the langmenu site setting. That setting
                // is only honoured from oer-sandbox's patch-playground.mjs
                // onwards - upstream's generated config.php assigned
                // $CFG->langmenu = 0, and a value assigned in config.php is a
                // forced setting that overrides the database row, so a bundle
                // built before that patch ignores a configured langmenu=1 and
                // shows no switcher however many packs this installs.
                $steps[] = [
                    'step' => 'installLanguagePack',
                    'language' => $code,
                    'setDefault' => $code === $language,
                ];
            }
        }

        // Deliberately AFTER the language/settings step(s), in every branch
        // above. Found live 2026-07-23: with the login first, the pack
        // downloads and installs correctly and the trial still comes up
        // entirely in English, because Moodle serialises the whole $USER into
        // the session at login and setDefault's update to user.lang then
        // lands too late for that already-established session to ever read
        // it. Installing first makes the same blueprint render Japanese -
        // verified by booting both orderings of one blueprint. Every other
        // step below is content, so this is the only ordering constraint the
        // language/settings steps impose.
        $steps[] = ['step' => 'login', 'username' => 'admin'];

        foreach ($allowedplugininstalls as $plugin) {
            if ($branch !== '' && self::is_baked_in($plugin['type'], $plugin['name'], $branch)) {
                // Already present and fully registered in this branch's
                // bundle (see is_baked_in()'s docblock) — nothing to do,
                // and attempting the runtime install anyway would be
                // redundant at best and risk the exact fragile-upgrade
                // failure this exists to avoid at worst.
                continue;
            }
            // The pluginType/pluginName must be explicit: Moodle Playground only
            // auto-detects them from a GitHub-style archive URL matching
            // /<repo>/archive/... (moodle-{type}_{name} naming) - our
            // allowlist_file.php?id=N URLs never match that, so omitting
            // these silently threw "pluginType could not be detected from
            // URL" inside the sandbox on every install attempt (found live,
            // 2026-07-19, tracing why mod_quizquest never appeared in a
            // trial despite an active allowlist entry - see
            // reference-clones/moodle-playground/src/blueprint/steps/moodle-plugins.js
            // detectPluginTypeAndName()). We already know both values
            // exactly; there's no need to rely on auto-detection at all.
            $steps[] = [
                'step' => 'installMoodlePlugin',
                'url' => $plugin['zipurl'],
                'pluginType' => $plugin['type'],
                'pluginName' => $plugin['name'],
            ];
        }

        if ($resourcetype === 'activity') {
            // A single-activity backup is TYPE_1ACTIVITY, not TYPE_1COURSE —
            // the playground's own restoreCourse step handler hard-rejects
            // anything that isn't a full-course backup (verified against the
            // pinned upstream source, reference-clones/moodle-playground/src/
            // blueprint/php/helpers.js: `if ($backuptype !== backup::TYPE_1COURSE)
            // { fail(...); }`). Instead of a restoreCourse step, run a
            // self-contained runPhpCode step that creates a fresh
            // single-activity-format course and restores the activity backup
            // INTO it with TARGET_EXISTING_ADDING — modeled directly on the
            // upstream restoreCourse PHP generator's own pattern (same
            // download/extract/error-handling shape), so it behaves
            // consistently with every other blueprint step's error reporting.
            $steps[] = [
                'step' => 'runPhpCode',
                'code' => self::build_activity_restore_php($signedmbzurl),
            ];
        } else {
            $steps[] = [
                'step' => 'restoreCourse',
                'url' => $signedmbzurl,
                'category' => 'Trial',
            ];
        }

        return [
            'steps' => $steps,
            // Fresh snapshot install has only the site course (id 1) — the
            // restored course is expected to land as id 2. Verified by the
            // fidelity spike / kit smoke test; falls back gracefully to the
            // course index if wrong (restoreCourse failures are non-fatal).
            'landingPage' => '/course/view.php?id=2',
        ];
    }

    /**
     * Whether a language code is one this plugin is willing to put into a
     * blueprint: a real Moodle language code, and not English (which core
     * ships, so there is nothing to install).
     *
     * The code ends up interpolated into PHP generated inside the sandbox and
     * into a $CFG->dataroot path (reference-clones/moodle-playground/src/
     * blueprint/php/helpers.js phpInstallLanguagePacks()). The sandbox
     * validates it with this same pattern before running anything, but a
     * blueprint built here must not be the thing that carries an unvalidated
     * code to it in the first place — so anything that isn't recognisable is
     * dropped and the trial simply comes up in English.
     *
     * @param string $language
     * @return bool
     */
    private static function is_installable_language(string $language): bool {
        if ($language === '' || $language === 'en') {
            return false;
        }

        return (bool) preg_match('/^[a-z][a-z0-9_]*$/', $language);
    }

    /**
     * Build the runPhpCode step body for the switch-ON (`sandboxbundled`)
     * language selection: a local filesystem copy first, falling back to the
     * same network install the switch-OFF path uses if the copy can't
     * deliver a usable pack.
     *
     * This is NOT the same as "nothing to do" even though the switch means
     * the pack is already baked: oer-sandbox's bake.sh (SANDBOX-CONFIG-PLAN.md
     * Task 10) injects the pack at `dirroot/oer-baked-lang/<code>`, which
     * ships as part of the bundle's webroot and so is present on every boot —
     * but `dataroot` (where Moodle actually reads installed packs from,
     * `$CFG->dataroot/lang`) is recreated fresh on every trial boot, exactly
     * like the rest of the WASM instance's ephemeral filesystem. So this copy
     * genuinely has to run every time; the only thing a successful copy
     * removes is the network round-trip through the playground's CORS proxy
     * to download.moodle.org (MULTILANG-TRYIT-PLAN.md verified fact 5).
     *
     * Measured live 2026-07-31 (SANDBOX-CONFIG-PLAN.md Task 12 Defect 2),
     * booting a probe build of this step for branch 5.2
     * (MOODLE_502_STABLE, split docroot):
     *   - $CFG->dirroot   = /www/moodle/public
     *   - $CFG->dataroot  = /persist/moodledata
     *   - $CFG->langotherroot = /persist/moodledata/lang
     *   - the pack bake.sh injects for a split-docroot branch really does
     *     land at $CFG->dirroot . '/oer-baked-lang/<code>' (bake.sh's own
     *     WEBROOT="$MOODLE_DIR/public" for split branches matches Moodle's
     *     own dirroot = dirname(lib/setup.php's own dir) exactly) — so the
     *     path guess here was already correct; is_dir()/is_file() and the
     *     RecursiveDirectoryIterator copy all measured true/succeeded
     *     (1385/1385 files copied, langconfig.php present at the target
     *     afterward). The path was never the bug.
     *   - The actual defect: the admin-repoint call below,
     *     `user_update_user($admin, false)`, fatals with "Call to undefined
     *     function user_update_user()" — user/lib.php is not among the
     *     libraries lib/setup.php always loads (the same class of bug as
     *     dev-docs/oer-platform/discoveries/
     *     2026-07-23-core-libs-are-not-always-loaded.md), and CLI_SCRIPT +
     *     require(config.php) here never pulls it in either. The copy
     *     silently completes with the pack fully installed, but the admin's
     *     `lang` column is never updated, so the very next 'login' step logs
     *     in as an admin still on 'en' and the trial renders English despite
     *     the pack being correctly baked and copied — invisible before this
     *     measurement because runPhpCode's echo output is discarded by the
     *     upstream step handler (reference-clones/moodle-playground/src/
     *     blueprint/steps/request.js handleRunPhpCode(), which only
     *     console.warn()s `result.errors`, never surfaces `result.text`).
     *     Fixed here by dropping user_update_user() entirely in favour of
     *     the same direct $DB->set_field('user', 'lang', ...) the
     *     switch-OFF network path already uses successfully (see
     *     reference-clones/moodle-playground/src/blueprint/php/helpers.js
     *     phpInstallLanguagePacks()) — no extra require needed, and one
     *     fewer divergence between the two paths.
     *
     * The baked-path lookup itself now probes two candidates rather than
     * hardcoding one (dirroot's own value, then its parent), so a future
     * bundle layout change (e.g. a branch that stops splitting the docroot)
     * degrades to the network fallback below instead of silently no-oping.
     *
     * If neither the baked copy nor the network install can deliver the
     * pack, the trial simply boots in English, same as a failed
     * installLanguagePack step (SANDBOX-CONFIG-DESIGN.md's existing
     * behaviour) — this was already the standing fallback contract; it
     * previously just also (wrongly) covered the baked-and-present case.
     *
     * Modeled on build_activity_restore_php()'s CLI_SCRIPT preamble and
     * comment-free body (same base64-into-launch-URL size cost — see that
     * method's docblock). The recursive local copy mirrors the baked-copy
     * branch MULTILANG-TRYIT-PLAN.md Task 1 specified for its (never
     * implemented, now superseded) build_multilang_defaults_php(). The
     * network fallback mirrors phpInstallLanguagePacks() (same file) line
     * for line, since that generator is not reachable from this class.
     *
     * Every code in $codes is installed, so a trial can offer a language
     * switcher rather than only the one language it opens in; $default names
     * the one it opens in (see build_blueprint()'s own comment on why the two
     * are separate). A code that cannot be delivered by either route is
     * skipped, not fatal — the other packs still install.
     *
     * @param string[] $codes already validated by is_installable_language()
     * @param string $default which of $codes the trial opens in; '' for none
     * @return string PHP source (without the leading <?php)
     */
    private static function build_language_selection_php(array $codes, string $default): string {
        $codeslit = var_export(array_values($codes), true);
        $defaultlit = var_export(self::is_installable_language($default) ? $default : '', true);

        return <<<PHP
define('CLI_SCRIPT', true);
require('/www/moodle/config.php');
require_once(\$CFG->libdir . '/componentlib.class.php');
\$codes = $codeslit;
\$default = $defaultlit;
\$installed = [];
make_upload_directory('lang');
foreach (\$codes as \$code) {
    \$source = 'none';
    \$candidates = [
        \$CFG->dirroot . '/oer-baked-lang/' . \$code,
        dirname(\$CFG->dirroot) . '/oer-baked-lang/' . \$code,
    ];
    \$baked = null;
    foreach (\$candidates as \$candidate) {
        if (is_dir(\$candidate) && is_file(\$candidate . '/langconfig.php')) {
            \$baked = \$candidate;
            break;
        }
    }
    if (\$baked !== null) {
        \$target = \$CFG->dataroot . '/lang/' . \$code;
        \$it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(\$baked, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach (\$it as \$item) {
            \$dest = \$target . '/' . \$it->getSubPathname();
            if (\$item->isDir()) {
                @mkdir(\$dest, 0777, true);
            } else {
                @mkdir(dirname(\$dest), 0777, true);
                copy(\$item->getPathname(), \$dest);
            }
        }
        if (is_file(\$target . '/langconfig.php')) {
            \$source = 'baked';
        }
    }
    if (\$source === 'none') {
        try {
            \$installer = new lang_installer([\$code]);
            \$installer->run();
        } catch (\Throwable \$e) {
            // fall through to the filesystem check below
        }
        if (is_file(\$CFG->dataroot . '/lang/' . \$code . '/langconfig.php')) {
            \$source = 'network';
        }
    }
    \$installed[\$code] = \$source;
}
get_string_manager()->reset_caches();
if (\$default !== '' && (\$installed[\$default] ?? 'none') !== 'none') {
    \$admin = get_admin();
    if (\$admin) {
        \$DB->set_field('user', 'lang', \$default, ['id' => \$admin->id]);
    }
}
echo json_encode(['installed' => \$installed]);
PHP;
    }

    /**
     * Which language packs a trial should install: the one it opens in, plus
     * every pack the sandbox configuration names.
     *
     * The trial's own language comes first so it is the one installed even if
     * a later pack's download exhausts the boot's patience, and duplicates are
     * collapsed — a configuration naming the very language the visitor is
     * already browsing in must not produce the same step twice.
     *
     * @param string[] $configured config::current()['langpacks']
     * @param string $language the trial's own language ('' or 'en' for none)
     * @return string[] validated codes, trial language first
     */
    private static function trial_language_packs(array $configured, string $language): array {
        $packs = [];
        if (self::is_installable_language($language)) {
            $packs[] = $language;
        }
        foreach ($configured as $code) {
            if (self::is_installable_language($code)) {
                $packs[] = $code;
            }
        }

        return array_values(array_unique($packs));
    }

    /**
     * Build the runPhpCode step body for the switch-OFF settings step: apply
     * the admin's saved multilang-filter state and advanced name=value
     * settings directly to this trial's DB, because this deployment's bundle
     * was never (or not yet) rebuilt with them baked into its install
     * snapshot (SANDBOX-CONFIG-DESIGN.md "Settings baking: mechanism, risk,
     * and fallback" — confirmed working end-to-end by the 2026-07-31 spike,
     * dev-docs/oer-platform/discoveries/
     * 2026-07-31-baking-settings-into-the-install-snapshot.md, for the
     * switch-ON case; this method is the boot-time equivalent for when that
     * mechanism hasn't been used).
     *
     * $filters/$settings come from config::current() ('filters' is
     * filter-name => 'on'/'off', applied via filter_set_global_state()
     * rather than set_config() — a filter's active state is a separate table,
     * mdl_filter_active, not mdl_config; 'settings' is name => value, applied
     * via set_config()). Both are embedded as PHP array literals
     * (var_export()) rather than interpolated as code, so nothing here is
     * built from unescaped admin input — config::parse_advanced() already
     * restricted names/values to what is safe to write into the generated
     * .conf file, and that same restriction makes them safe here too.
     *
     * Modeled on build_activity_restore_php()'s CLI_SCRIPT preamble and
     * comment-free body. Unverified live — see SANDBOX-CONFIG-PLAN.md Task 12.
     *
     * @param array $filters filter name => 'on'|'off'
     * @param array $settings config name => value
     * @return string PHP source (without the leading <?php)
     */
    private static function build_boot_settings_php(array $filters, array $settings): string {
        $filterslit = var_export($filters, true);
        $settingslit = var_export($settings, true);

        return <<<PHP
define('CLI_SCRIPT', true);
require('/www/moodle/config.php');
require_once(\$CFG->libdir . '/filterlib.php');
\$filters = $filterslit;
\$settings = $settingslit;
foreach (\$filters as \$name => \$state) {
    filter_set_global_state(\$name, \$state === 'on' ? TEXTFILTER_ON : TEXTFILTER_DISABLED);
}
foreach (\$settings as \$name => \$value) {
    set_config(\$name, \$value);
}
echo json_encode(['ok' => true]);
PHP;
    }

    /**
     * Build the runPhpCode step body for a single-activity Try-it: create a
     * fresh format_singleactivity course, then restore the activity backup
     * into it with TARGET_EXISTING_ADDING. See build_blueprint()'s call site
     * for why this can't reuse the restoreCourse step type.
     *
     * The leading define(CLI_SCRIPT)/require(config.php) matters: verified
     * live 2026-07-20 that — unlike the restoreCourse step — runPhpCode's
     * execution environment does NOT auto-bootstrap Moodle; its handler
     * (reference-clones/moodle-playground/src/blueprint/steps/request.js
     * handleRunPhpCode()) only prepends a bare "<?php" if missing, nothing
     * more. Without this require, raise_memory_limit() below fatals with
     * "Call to undefined function" because no config.php has ever run in
     * this PHP process. Every OTHER upstream generator in helpers.js
     * (including phpRestoreCourse(), the pattern this method is modeled on)
     * is prefixed with exactly this CLI_HEADER boilerplate for the same
     * reason — runPhpCode's own code strings are the one case that must
     * supply it manually. MOODLE_ROOT is hardcoded '/www/moodle' in
     * helpers.js too: it is the in-browser WASM mount point, fixed across
     * every trial regardless of the real Moodle site's own path, not a
     * value to derive from this plugin's $CFG.
     *
     * The generated code is kept comment-free: it is base64-encoded whole
     * into the trial's launch URL (build_launch_url()), and a verbose
     * version measurably risked tripping nginx's fastcgi response-header
     * buffer limit on the sandbox_launch.php redirect (found live
     * 2026-07-20: intermittent "upstream sent too big header", full
     * Location: URL ~5KB) — every extra byte here is a real cost.
     *
     * download_file_content() is called with the $tofile (8th) parameter
     * streaming straight to a temp path, NOT with $fullresponse=true reading
     * into a variable — verified live 2026-07-20 that the $fullresponse=true
     * form (this method's first attempt) DOES fetch successfully inside the
     * WASM sandbox, but its ->results is the response BODY BYTES, not a file
     * path; passing those bytes straight to extract_to_pathname() (which
     * expects a path and calls fopen() on it) fatals with "fopen(): Argument
     * #1 ($filename) must not contain any null bytes" the instant the binary
     * .mbz content contains one, which it does. This is the exact working
     * pattern from the upstream restoreCourse generator in helpers.js
     * (phpRestoreCourse()'s sourceBlock), reused here unchanged.
     *
     * After a successful restore, the course's format_singleactivity
     * 'activitytype' option is set to the restored module's modname —
     * verified live 2026-07-20 that without this, format_singleactivity's
     * own page_set_course() (course/format/singleactivity/lib.php) can't
     * find a course-module matching its unset/default activitytype (it
     * defaults to the first registered activity type, e.g. 'forum', not
     * whatever was actually restored) and redirects to "add an activity"
     * instead of the restored activity, even though the restore itself
     * succeeded.
     *
     * @param string $signedmbzurl
     * @return string PHP source (without the leading <?php — the runPhpCode
     *                 step handler adds it if missing)
     */
    private static function build_activity_restore_php(string $signedmbzurl): string {
        $urlliteral = str_replace("'", "\\'", $signedmbzurl);

        return <<<PHP
define('CLI_SCRIPT', true);
require('/www/moodle/config.php');
raise_memory_limit(MEMORY_EXTRA);
@set_time_limit(0);
require_once(\$CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once(\$CFG->dirroot . '/course/lib.php');
global \$CFG, \$DB, \$USER;

function fail(\$msg) {
    while (ob_get_level()) { ob_end_clean(); }
    echo json_encode(['ok' => false, 'error' => \$msg]);
    exit(0);
}
set_exception_handler(function(\$e) { fail(\$e->getMessage()); });

\$admin = get_admin();
if (!\$admin) { fail('Administrator account not found.'); }
\$USER = \$admin;

\$mbz = make_request_directory() . '/source.mbz';
\$dlok = download_file_content('$urlliteral', null, null, false, 600, 30, false, \$mbz);
if (\$dlok === false || !is_file(\$mbz) || filesize(\$mbz) < 1000) {
    fail('Could not download the activity backup.');
}

\$backupdir = restore_controller::get_tempdir_name(SITEID, \$admin->id);
\$path = make_backup_temp_directory(\$backupdir);
\$packer = get_file_packer('application/vnd.moodle.backup');
if (!\$packer->extract_to_pathname(\$mbz, \$path)) {
    fulldelete(\$path);
    fail('Could not extract the activity backup (.mbz).');
}
if (!is_file(\$path . '/moodle_backup.xml')) {
    fulldelete(\$path);
    fail('The .mbz does not contain moodle_backup.xml (not a valid Moodle backup).');
}
\$tmprc = new restore_controller(
    \$backupdir, SITEID, backup::INTERACTIVE_NO, backup::MODE_GENERAL, \$admin->id, backup::TARGET_EXISTING_ADDING
);
\$backuptype = \$tmprc->get_type();
\$tmprc->destroy();
if (\$backuptype !== backup::TYPE_1ACTIVITY) {
    fulldelete(\$path);
    fail('The supplied .mbz is not a single-activity backup.');
}

\$newcourse = new stdClass();
\$newcourse->fullname = 'Trial activity';
\$newcourse->shortname = 'trial-activity-' . time();
\$newcourse->category = 1;
\$newcourse->format = 'singleactivity';
\$course = create_course(\$newcourse);

\$rc = new restore_controller(
    \$backupdir, \$course->id, backup::INTERACTIVE_NO, backup::MODE_GENERAL, \$admin->id, backup::TARGET_EXISTING_ADDING
);
try {
    \$rc->execute_precheck();
    \$rc->execute_plan();
    \$rc->destroy();
} catch (\\Throwable \$e) {
    \$rc->destroy();
    fulldelete(\$path);
    fail('Activity restore failed: ' . \$e->getMessage());
}
fulldelete(\$path);

rebuild_course_cache(\$course->id, true);
\$modinfo = get_fast_modinfo(\$course->id);
\$restoredcm = reset(\$modinfo->get_cms());
if (\$restoredcm) {
    course_get_format(\$course)->update_course_format_options(['activitytype' => \$restoredcm->modname]);
}

echo json_encode(['ok' => true, 'courseid' => \$course->id]);
PHP;
    }

    /**
     * Build the full launch URL for a trial.
     *
     * @param string $sandboxbaseurl e.g. "https://vagrant.wisecat.net/try/"
     * @param string $branch e.g. "5.2"
     * @param array $blueprint from build_blueprint()
     * @return \moodle_url
     */
    public static function build_launch_url(string $sandboxbaseurl, string $branch, array $blueprint): \moodle_url {
        $encoded = base64_encode(json_encode($blueprint));

        return new \moodle_url(rtrim($sandboxbaseurl, '/') . '/', [
            'moodle' => $branch,
            'blueprint' => $encoded,
        ]);
    }
}
