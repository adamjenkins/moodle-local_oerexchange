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

use local_oerexchange\local\catalogue_view;
use local_oerexchange\local\profile_manager;

/**
 * Hook callback handlers for local_oerexchange.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Adds Open Graph <meta> tags to the page <head> for a public
     * educator-profile page (GET /u/{slug}), so link-preview crawlers that
     * require <head> placement (e.g. Facebook's) can build a rich preview.
     * profile_controller::view() used to emit these tags itself, but
     * $OUTPUT->header() has already closed <head> by the time a route
     * controller runs, so they landed in <body> — this hook is the genuine
     * <head>-injection mechanism
     * (lib/classes/hook/output/before_standard_head_html_generation.php).
     *
     * This hook fires on *every* page load site-wide, so
     * {@see self::get_profile_slug_from_current_url()} must stay a cheap
     * string/regex match with no DB access — it is the first and only gate
     * before any profile_manager lookup runs.
     *
     * @param \core\hook\output\before_standard_head_html_generation $hook
     */
    public static function before_standard_head_html_generation(
        \core\hook\output\before_standard_head_html_generation $hook,
    ): void {
        global $DB;

        $slug = self::get_profile_slug_from_current_url();
        if ($slug === null) {
            // Not a /u/{slug} request — the overwhelmingly common case for
            // every other page on the site. Bail before touching the DB.
            return;
        }

        $profile = profile_manager::get_by_slug($slug);
        if (!$profile || !$profile->visible) {
            // Slug doesn't resolve or the profile is hidden — the
            // controller's own 404 handles this case; no OG tags needed.
            return;
        }

        $user = $DB->get_record('user', ['id' => $profile->userid, 'deleted' => 0]);
        if (!$user) {
            return;
        }

        $hook->add_html(self::build_og_meta_html($profile, $user));
    }

    /**
     * Cheap, DB-free check for whether the current request is a public
     * educator-profile page. Returns the slug when it is, null otherwise.
     * Must stay a pure string/regex match against the request path — this
     * runs on every page load, not just profile pages.
     *
     * @return string|null
     */
    protected static function get_profile_slug_from_current_url(): ?string {
        global $PAGE;

        $path = $PAGE->url->get_path();
        // Deliberately NOT anchored at the start of the path: a Moodle
        // site installed in a subdirectory (a common, supported layout —
        // and also how PHPUnit's own fake wwwroot,
        // https://www.example.com/moodle, is configured — see
        // lib/phpunit/bootstrap.php) puts that subdirectory ahead of the
        // route path in $PAGE->url->get_path(), e.g. '/moodle/u/janedoe'
        // rather than '/u/janedoe'. Anchoring only at the end mirrors
        // local_langcrowd\hook_callbacks::should_annotate()'s equivalent
        // $PAGE->url->get_path() check. The slug charset matches
        // path_profileslug (param::ALPHANUMEXT) and
        // profile_manager::is_valid_slug().
        //
        // This same start-unanchored match is *also* why this gate needed
        // no change when profile_controller::view()'s $PAGE->set_url() was
        // corrected from the non-resolving '/u/{slug}' to the real request
        // path '/local_oerexchange/u/{slug}' (component prefix required —
        // see that controller's class docblock): by the time this hook
        // fires (during $OUTPUT->header(), after the controller's own
        // set_url() call has already run), $PAGE->url->get_path() ends in
        // '/u/{slug}' either way, so this regex matches both forms
        // identically. Verified via the covering tests in
        // hook_callbacks_test.php, which pass a real
        // '/local_oerexchange/u/{slug}' $PAGE->url.
        if (!preg_match('#/u/([A-Za-z0-9_-]{1,100})/?$#', $path, $matches)) {
            return null;
        }
        return $matches[1];
    }

    /**
     * Builds the Open Graph <meta> tag markup for a profile page. Kept as
     * a single shared helper so the tag set is defined in exactly one
     * place (previously duplicated between this listener and
     * profile_controller::view(); the controller's inline emission was
     * removed once this hook took over <head> placement).
     *
     * @param \stdClass $profile local_oerexchange_profiles record
     * @param \stdClass $user user record for $profile->userid
     * @return string
     */
    protected static function build_og_meta_html(\stdClass $profile, \stdClass $user): string {
        global $PAGE;

        $fullname = fullname($user);
        // Must match profile_controller::view()'s $profileurl construction,
        // including its use of moodle_url::routed_path() rather than a
        // plain moodle_url() — see that method's comment and
        // dev-docs/harness/discoveries/
        // 2026-07-19-routerconfigured-inconsistent-during-routed-requests.md
        // for why: routed_path() is the portable core factory that always
        // resolves (lib/classes/url.php:673), whereas a plain moodle_url()
        // only happens to work on this dev VM's specific nginx catch-all
        // and would silently 404 on a real production site with
        // routerconfigured genuinely false. The #[route] attribute's
        // declared path ('/u/{slug}') is component-relative, not the real,
        // resolvable URL (see that class's docblock); a shared/pasted
        // og:url must actually resolve.
        $profileurl = \moodle_url::routed_path('/local_oerexchange/u/' . $profile->slug);
        // A bare strip_tags() ran no text filters at all, so a multilang bio
        // advertised BOTH languages at once in every link preview, and its
        // HTML entities stayed encoded for html_writer to encode a second
        // time below. Filter first, flatten second — the same order
        // index.php's catalogue card teaser uses.
        //
        // FORMAT_MOODLE, not FORMAT_HTML, to stay consistent with how
        // profile_controller::view() renders this very same column on the
        // page itself: the bio comes from a plain <textarea> as
        // PARAM_RAW_TRIMMED, i.e. plain text with real newlines, which is
        // FORMAT_MOODLE's input contract. Cleaning stays on (no 'noclean').
        // content_to_text() then flattens the filtered HTML back to real
        // plain text and decodes the entities, so html_writer's attribute
        // escaping below is the only escaping applied.
        $ogdescription = $profile->bio !== ''
            ? shorten_text(
                content_to_text(
                    format_text($profile->bio, FORMAT_MOODLE, ['context' => \context_system::instance()]),
                    FORMAT_HTML
                ),
                200
            )
            : get_string('profilenobio', 'local_oerexchange');
        $userpicture = new \user_picture($user);
        $userpicture->size = 200;

        $html = '';
        $html .= \html_writer::empty_tag('meta', ['property' => 'og:title', 'content' => $fullname]);
        $html .= \html_writer::empty_tag('meta', ['property' => 'og:description', 'content' => $ogdescription]);
        $html .= \html_writer::empty_tag(
            'meta',
            ['property' => 'og:image', 'content' => $userpicture->get_url($PAGE)->out(false)]
        );
        $html .= \html_writer::empty_tag('meta', ['property' => 'og:url', 'content' => $profileurl->out(false)]);
        $html .= \html_writer::empty_tag('meta', ['property' => 'og:type', 'content' => 'profile']);
        return $html;
    }

    /**
     * Offers the OER catalogue as a site home page option in Site
     * administration > Appearance > Navigation > Default home page for
     * users (dispatched from admin/settings/appearance.php:178).
     *
     * This is core's own supported mechanism, and it covers logged-in
     * users only — get_home_page() is gated on isloggedin()
     * (lib/moodlelib.php:10028) and core index.php runs
     * require_course_login() before it ever consults the setting. Serving
     * anonymous visitors needs the separate, earlier after_config
     * interception in {@see self::after_config()}.
     *
     * Note the resulting behaviour differs by audience: core redirects a
     * logged-in user to /local/oerexchange/index.php, whereas after_config
     * renders the catalogue in place at '/' for a visitor. Each uses the
     * right mechanism for its audience; the admin manual documents the
     * difference so it does not read as a bug.
     *
     * @param \core_user\hook\extend_default_homepage $hook
     */
    public static function extend_default_homepage(\core_user\hook\extend_default_homepage $hook): void {
        $hook->add_option(
            new \core\url('/local/oerexchange/index.php'),
            get_string('settings_homepageoption', 'local_oerexchange')
        );
    }

    /**
     * Serves the OER catalogue in place at the site root for anonymous
     * visitors, when the publiclanding setting is on.
     *
     * Rendering rather than redirecting is the point: the visitor's
     * address bar stays at the site root. There is no redirect anywhere in
     * this path, which is also why the setting is a checkbox rather than a
     * URL field — there is no open-redirect surface to get wrong.
     *
     * This fires from lib/setup.php:1209, after the session starts
     * (lib/setup.php:905, so isloggedin() is valid) and before core
     * public/index.php:56 calls require_course_login($SITE) — the call
     * that would otherwise bounce an anonymous visitor to the login page
     * whenever forcelogin is on. That ordering is the entire reason this
     * feature can leave forcelogin on and still open the front door.
     *
     * $PAGE (lib/setup.php:1048) and $OUTPUT (:653) both exist and are
     * unconfigured by this point, so setting them up here is safe. Note
     * the if(false) block at lib/setup.php:1198 is an IDE autocompletion
     * hint, not where they are constructed.
     *
     * @param \core\hook\after_config $hook
     */
    public static function after_config(\core\hook\after_config $hook): void {
        global $PAGE, $OUTPUT;

        if (!self::should_serve_public_landing()) {
            return;
        }

        $baseurl = new \moodle_url('/');
        $PAGE->set_url($baseurl);
        $PAGE->set_context(\context_system::instance());
        // The frontpage layout, not the standard one, so a theme's own
        // front-page treatment still applies to what is now the front page.
        $PAGE->set_pagelayout('frontpage');
        $PAGE->set_title(get_string('catalogtitle', 'local_oerexchange'));
        $PAGE->set_heading(get_string('catalogtitle', 'local_oerexchange'));

        echo $OUTPUT->header();
        // An intro/hero block above the catalogue would go here — the
        // grid-only presentation was chosen deliberately for the first
        // release, with that revisit explicitly anticipated.
        echo catalogue_view::from_request()->render($baseurl);
        echo $OUTPUT->footer();
        exit;
    }

    /**
     * Whether this request should be served the public catalogue landing
     * page instead of Moodle's own front page.
     *
     * Split out from {@see self::after_config()} with no output and no
     * side effects precisely so every guard below is unit-testable — the
     * caller is the part that cannot be tested, because it exits.
     *
     * Guards are ordered cheapest-first, and this runs on EVERY request
     * site-wide, so the $SCRIPT comparison must stay the first real test:
     * it is a plain string compare that rejects every page but one before
     * anything touches the database. Same discipline as
     * {@see self::get_profile_slug_from_current_url()}.
     *
     * @return bool
     */
    public static function should_serve_public_landing(): bool {
        global $CFG, $SCRIPT;

        // The hot path, and deliberately the very first test. $SCRIPT is
        // set by initialise_fullme() (lib/setuplib.php:716) as a
        // wwwroot-relative path taken from SCRIPT_NAME, so this is correct
        // for a Moodle installed in a subdirectory too, and DirectoryIndex
        // means a bare '/' request arrives here as '/index.php' just like
        // an explicit one does. $SCRIPT is null for a request core cannot
        // place under wwwroot (lib/setuplib.php:719), which also fails
        // this test — the safe direction.
        //
        // This subsumes a CLI_SCRIPT check: a CLI script's $SCRIPT is its
        // own path (lib/setuplib.php:783, e.g. '/admin/cli/cron.php'),
        // never '/index.php', and core refuses to run a web script from
        // the CLI at all (lib/setup.php:344). An explicit CLI_SCRIPT guard
        // would also be untestable-by-construction, since CLI_SCRIPT is
        // always true under PHPUnit — it would silently make the
        // positive-path tests below vacuous. AJAX_SCRIPT is false under
        // PHPUnit, so that one can stay.
        if ($SCRIPT !== '/index.php' || AJAX_SCRIPT) {
            return false;
        }

        if (during_initial_install()) {
            return false;
        }

        // Logged-in users are core's business: an admin sets where they
        // land via Appearance > Navigation > Default home page for users,
        // which self::extend_default_homepage() adds the catalogue to.
        // Guests count as visitors, and do get the catalogue.
        if (isloggedin() && !isguestuser()) {
            return false;
        }

        // The escape hatch — core's own convention (public/index.php:37).
        // Without it, an admin who turns this on has no way back to the
        // real front page.
        if (optional_param('redirect', 1, PARAM_BOOL) === 0) {
            return false;
        }

        // Rendering in place bypasses core index.php's own maintenance
        // check, so without this the catalogue would stay public while the
        // site was supposedly closed. CLI maintenance mode needs no guard:
        // lib/setup.php:356-370 has already 503'd the request long before
        // this hook fires.
        if (!empty($CFG->maintenance_enabled)) {
            return false;
        }

        if (!get_config('local_oerexchange', 'publiclanding')) {
            return false;
        }

        // Last, because it is the most expensive check here.
        if (moodle_needs_upgrading()) {
            return false;
        }

        return true;
    }
}
