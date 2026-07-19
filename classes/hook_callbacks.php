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
        $ogdescription = $profile->bio !== ''
            ? shorten_text(strip_tags($profile->bio), 200)
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
}
