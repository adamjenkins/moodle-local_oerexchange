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
use local_oerexchange\local\collection_cover;
use local_oerexchange\local\collection_manager;
use local_oerexchange\local\cover_image;
use local_oerexchange\local\featured_collections;
use local_oerexchange\local\profile_manager;
use local_oerexchange\local\resource_manager;

/**
 * Hook callback handlers for local_oerexchange.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Adds Open Graph <meta> tags to the page <head> for the three kinds of
     * shareable page this plugin publishes — an educator profile
     * (GET /u/{slug}), a collection (GET /c/{slug}) and a resource
     * (resource.php?id=N) — so link-preview crawlers that require <head>
     * placement (e.g. Facebook's) can build a rich preview.
     * profile_controller::view() used to emit these tags itself, but
     * $OUTPUT->header() has already closed <head> by the time a route
     * controller runs, so they landed in <body> — this hook is the genuine
     * <head>-injection mechanism
     * (lib/classes/hook/output/before_standard_head_html_generation.php).
     *
     * This hook fires on *every* page load site-wide, so every gate in
     * {@see self::social_preview_html()} is a cheap string/regex match on the
     * request path before anything touches the database.
     *
     * @param \core\hook\output\before_standard_head_html_generation $hook
     */
    public static function before_standard_head_html_generation(
        \core\hook\output\before_standard_head_html_generation $hook,
    ): void {
        $html = self::social_preview_html();
        if ($html !== '') {
            $hook->add_html($html);
        }
    }

    /**
     * The social-preview tags for whichever of this plugin's shareable pages
     * the current request is, or '' for every other page on the site.
     *
     * Split from the listener with no output and no side effects so the whole
     * dispatch is unit-testable, the same arrangement
     * {@see self::should_serve_public_landing()} has.
     *
     * The three path gates run first and in order of how cheap they are, and
     * none of them reads the database: the overwhelmingly common case is a
     * page that is none of the three, and it must cost a few preg_match()
     * calls and nothing more.
     *
     * @return string HTML, '' when this request has no preview
     */
    protected static function social_preview_html(): string {
        global $DB;

        $slug = self::get_profile_slug_from_current_url();
        if ($slug !== null) {
            $profile = profile_manager::get_by_slug($slug);
            if (!$profile || !$profile->visible) {
                // Slug doesn't resolve or the profile is hidden — the
                // controller's own 404 handles this case; no OG tags needed.
                return '';
            }

            $user = $DB->get_record('user', ['id' => $profile->userid, 'deleted' => 0]);
            if (!$user) {
                return '';
            }

            return self::build_og_meta_html($profile, $user);
        }

        $collectionslug = self::get_collection_slug_from_current_url();
        if ($collectionslug !== null) {
            return self::build_collection_meta_html($collectionslug);
        }

        $resourceid = self::get_resource_id_from_current_url();
        if ($resourceid !== null) {
            return self::build_resource_meta_html($resourceid);
        }

        return '';
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
     * Cheap, DB-free check for whether the current request is a public
     * collection page. Returns the slug when it is, null otherwise.
     *
     * The same shape, and the same start-unanchored matching, as
     * {@see self::get_profile_slug_from_current_url()} — see that method for
     * why the pattern must not be anchored at the start of the path (a Moodle
     * in a subdirectory, which is also PHPUnit's own fake wwwroot) and why the
     * charset is exactly this one (slug_helper::is_valid()).
     *
     * @return string|null
     */
    protected static function get_collection_slug_from_current_url(): ?string {
        global $PAGE;

        // Unlike the profile pattern this one includes the component segment:
        // "/c/" is short enough that another plugin's route could end the same
        // way, and a match here costs a query and emits a preview card.
        if (!preg_match('#/local_oerexchange/c/([A-Za-z0-9_-]{1,100})/?$#', $PAGE->url->get_path(), $matches)) {
            return null;
        }
        return $matches[1];
    }

    /**
     * Cheap, DB-free check for whether the current request is a resource page.
     * Returns the resource id when it is, null otherwise.
     *
     * resource.php is a plain script rather than a routed page, so the id is a
     * query parameter, read from $PAGE->url rather than from
     * optional_param(): by the time this hook fires the page has called
     * set_url() with the id it actually loaded, and that — not whatever is in
     * the query string — is the resource being displayed.
     *
     * @return int|null
     */
    protected static function get_resource_id_from_current_url(): ?int {
        global $PAGE;

        if (!str_ends_with($PAGE->url->get_path(), '/local/oerexchange/resource.php')) {
            return null;
        }

        $id = (int) $PAGE->url->get_param('id');

        return $id > 0 ? $id : null;
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
            ? self::teaser($profile->bio, FORMAT_MOODLE)
            : get_string('profilenobio', 'local_oerexchange');
        $userpicture = new \user_picture($user);
        $userpicture->size = 200;

        return self::meta_tags(
            $fullname,
            $ogdescription,
            $userpicture->get_url($PAGE)->out(false),
            $profileurl->out(false),
            'profile'
        );
    }

    /**
     * The preview tags for a collection page, or '' when this collection must
     * not be previewed at all.
     *
     * The three states that get nothing: a draft (it is the owner's alone —
     * collection_manager::user_can_view_collection()), a collection a
     * moderator has taken down, and a tombstone. In each case the page itself
     * shows the owner or the moderator something, but there is nothing here
     * for the world to see, and an og:title is world-readable by definition.
     *
     * An unlisted collection DOES get tags — its whole point is to be shared
     * by link, and a preview is what being shared by link looks like — plus a
     * robots noindex, so a crawler that follows the shared link does not then
     * put it in a search index the owner never asked to be in.
     *
     * @param string $slug from the path gate
     * @return string
     */
    protected static function build_collection_meta_html(string $slug): string {
        // The collections master switch (and the are-the-tables-there test),
        // read before the first query: with the feature off there is no
        // collection page for these tags to describe.
        if (!featured_collections::available()) {
            return '';
        }

        $collection = collection_manager::get_by_slug($slug);
        if (!$collection || collection_manager::is_tombstone($collection)) {
            return '';
        }
        if ((string) $collection->visibility === collection_manager::VISIBILITY_DRAFT) {
            return '';
        }
        if (!empty($collection->modhidden)) {
            return '';
        }

        $summary = (string) ($collection->summary ?? '');
        $description = trim($summary) !== ''
            ? self::teaser($summary, (int) ($collection->summaryformat ?? FORMAT_HTML))
            : get_string('collectionognosummary', 'local_oerexchange');

        // Using display_url() rather than the batch display_urls(): this is the
        // collection's own page, so building the mosaic now is the same work
        // the page below is about to do, and the file it writes serves every
        // later view and every later crawl.
        $image = collection_cover::display_url((int) $collection->id);

        return self::meta_tags(
            self::plain_text((string) $collection->title),
            $description,
            ($image ?? self::default_image_url())->out(false),
            catalogue_view::collection_url($collection)->out(false),
            'website',
            (string) $collection->visibility === collection_manager::VISIBILITY_UNLISTED
        );
    }

    /**
     * The preview tags for a resource page, or '' when the resource is not
     * published.
     *
     * Only 'published' earns tags. An author viewing their own hidden resource
     * sees the page, but these tags exist for strangers' link previews, and a
     * crawler is always a stranger — so the status test here is the published
     * one, not resource_manager::user_can_view_resource().
     *
     * This reads the row the page below is about to read again: the hook runs
     * inside $OUTPUT->header(), long after resource.php loaded its own copy,
     * and there is no supported way to be handed it. One extra query on
     * resource pages only, and none anywhere else on the site.
     *
     * @param int $resourceid from the path gate
     * @return string
     */
    protected static function build_resource_meta_html(int $resourceid): string {
        global $DB;

        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $resourceid]);
        if (!$resource || (string) $resource->status !== resource_manager::STATUS_PUBLISHED) {
            return '';
        }

        $summary = (string) ($resource->summary ?? '');
        $description = trim($summary) !== ''
            ? self::teaser($summary, (int) ($resource->summaryformat ?? FORMAT_HTML))
            : get_string('resourceognosummary', 'local_oerexchange');

        $image = cover_image::url_for((int) $resource->id);

        return self::meta_tags(
            self::plain_text((string) $resource->title),
            $description,
            ($image ?? self::default_image_url())->out(false),
            (new \moodle_url('/local/oerexchange/resource.php', ['id' => $resource->id]))->out(false),
            'article'
        );
    }

    /**
     * The Open Graph and Twitter tag set, in one place so the three page types
     * cannot drift apart.
     *
     * Every value is passed as plain text: html_writer escapes attribute
     * values, and it is the only escaping applied — which is why the callers
     * hand over text that has already been through
     * {@see self::teaser()}/{@see self::plain_text()} rather than markup.
     *
     * twitter:card is summary_large_image for all three: every one of these
     * pages has a real picture to show (a user picture, a cover, a mosaic, or
     * this plugin's default thumbnail), and the small card crops it to a
     * thumbnail beside the text.
     *
     * @param string $title
     * @param string $description
     * @param string $imageurl absolute URL
     * @param string $url absolute, resolvable canonical URL of the page
     * @param string $type og:type — 'profile', 'website' or 'article'
     * @param bool $noindex also emit <meta name="robots" content="noindex">
     * @return string
     */
    protected static function meta_tags(
        string $title,
        string $description,
        string $imageurl,
        string $url,
        string $type,
        bool $noindex = false,
    ): string {
        $tags = [
            ['property' => 'og:title', 'content' => $title],
            ['property' => 'og:description', 'content' => $description],
            ['property' => 'og:image', 'content' => $imageurl],
            ['property' => 'og:url', 'content' => $url],
            ['property' => 'og:type', 'content' => $type],
            ['name' => 'twitter:card', 'content' => 'summary_large_image'],
            ['name' => 'twitter:title', 'content' => $title],
            ['name' => 'twitter:description', 'content' => $description],
            ['name' => 'twitter:image', 'content' => $imageurl],
        ];
        if ($noindex) {
            $tags[] = ['name' => 'robots', 'content' => 'noindex'];
        }

        $html = '';
        foreach ($tags as $attributes) {
            $html .= \html_writer::empty_tag('meta', $attributes);
        }

        return $html;
    }

    /**
     * A preview description: filtered, flattened to real plain text, shortened.
     *
     * Filter first, flatten second. A bare strip_tags() runs no text filters
     * at all, so a multilang bio advertised BOTH languages at once in every
     * link preview and its HTML entities stayed encoded for html_writer to
     * encode a second time. content_to_text() then flattens the filtered HTML
     * back to real plain text and decodes the entities, so html_writer's
     * attribute escaping is the only escaping applied.
     *
     * @param string $text the stored text
     * @param int $format its FORMAT_* constant — FORMAT_MOODLE for a profile bio
     *      (a plain <textarea> with real newlines, which is that format's input
     *      contract, and what profile_controller::view() renders the same column
     *      with), FORMAT_HTML for an editor-authored summary
     * @param int $length
     * @return string plain text, unescaped
     */
    protected static function teaser(string $text, int $format, int $length = 200): string {
        return shorten_text(
            content_to_text(
                format_text($text, $format, ['context' => \context_system::instance()]),
                FORMAT_HTML
            ),
            $length
        );
    }

    /**
     * A title as plain text: filtered (so a multilang title collapses to one
     * language) and decoded, for html_writer to escape exactly once.
     *
     * @param string $text
     * @return string
     */
    protected static function plain_text(string $text): string {
        return content_to_text(
            format_string($text, true, ['context' => \context_system::instance()]),
            FORMAT_HTML
        );
    }

    /**
     * The plugin's default thumbnail, for a page whose own picture is missing.
     *
     * A preview with no og:image is rendered by most networks as a bare link,
     * so "no picture" is worth a generic one.
     *
     * @return \moodle_url
     */
    protected static function default_image_url(): \moodle_url {
        global $OUTPUT;

        return $OUTPUT->image_url('defaultthumbnail', 'local_oerexchange');
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
        // The hero/intro area this comment used to reserve is now rendered
        // INSIDE catalogue_view::render() — the admin-authored intro, then the
        // featured-collections strip — and deliberately not here. This method
        // is only one of the catalogue's three entry points, so anything
        // emitted here would be content the plugin's own index.php and the
        // default-home-page redirect both lack. One setting, one surface, one
        // place that draws it.
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
