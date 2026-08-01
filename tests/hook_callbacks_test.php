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

use PHPUnit\Framework\Attributes\CoversClass;
use local_oerexchange\local\profile_manager;

/**
 * Tests for hook_callbacks::before_standard_head_html_generation(), the
 * listener that puts Open Graph <meta> tags into the real <head> for a
 * public educator-profile page (GET /u/{slug}).
 *
 * The listener method is called directly against a manually constructed
 * hook instance (the pattern used by core's own hook-listener tests, e.g.
 * lib/tests/hook/before_course_viewed_test.php) rather than through the
 * full \core\hook\manager::dispatch() path — that keeps these tests
 * independent of which other plugins' listeners happen to be registered
 * for this same hook in this environment (e.g. admin/tool/mobile's), while
 * still exercising the exact callback db/hooks.php points at.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(hook_callbacks::class)]
final class hook_callbacks_test extends \advanced_testcase {
    /**
     * Build a fresh before_standard_head_html_generation hook instance.
     *
     * @return \core\hook\output\before_standard_head_html_generation
     */
    private function make_hook(): \core\hook\output\before_standard_head_html_generation {
        global $PAGE;
        // Global $OUTPUT is a core\output\bootstrap_renderer in the
        // PHPUnit CLI bootstrap, not a renderer_base — the hook
        // constructor's typed parameter rejects it.
        // $PAGE->get_renderer('core') is a real renderer_base, same as
        // what core_renderer::header() actually passes in production
        // (lib/classes/output/core_renderer.php:193).
        return new \core\hook\output\before_standard_head_html_generation($PAGE->get_renderer('core'));
    }

    public function test_visible_profile_page_adds_og_tags(): void {
        $this->resetAfterTest();
        global $PAGE;

        $user = $this->getDataGenerator()->create_user(['firstname' => 'Jane', 'lastname' => 'Doe']);
        profile_manager::get_or_create_for_user((int) $user->id);
        profile_manager::save((int) $user->id, [
            'slug' => 'janedoe', 'bio' => 'A biology teacher.', 'expertise' => [],
            'visible' => true,
        ]);

        // The real request path a client hits (component-prefixed — see
        // profile_controller's class docblock; a bare '/u/janedoe' 404s in
        // production) is what the router sets on $PAGE->url before the
        // controller runs, and what the controller's own corrected
        // $PAGE->set_url() call reproduces.
        $PAGE->set_url(new \moodle_url('/local_oerexchange/u/janedoe'));

        $hook = $this->make_hook();
        hook_callbacks::before_standard_head_html_generation($hook);

        $html = $hook->get_output();
        $this->assertStringContainsString('property="og:title"', $html);
        $this->assertStringContainsString('content="Jane Doe"', $html);
        $this->assertStringContainsString('property="og:description"', $html);
        $this->assertStringContainsString('A biology teacher.', $html);
        $this->assertStringContainsString('property="og:image"', $html);
        $this->assertStringContainsString('property="og:url"', $html);
        // The real, resolvable URL, not the bare '/u/{slug}' the #[route]
        // attribute declares (component-relative only — see
        // profile_controller's class docblock). build_og_meta_html() builds
        // this via moodle_url::routed_path(), which prepends an extra
        // '/r.php/' segment or not depending on $CFG->routerconfigured — a
        // flag whose value is environment- (and even routed-request-phase-)
        // dependent, not something this test should be coupled to (see
        // dev-docs/harness/discoveries/
        // 2026-07-19-routerconfigured-inconsistent-during-routed-requests.md).
        // What this test actually cares about is that the emitted og:url
        // carries the real, component-prefixed slug path — so match on
        // that suffix, tolerating an optional '/r.php' segment before it,
        // rather than asserting one hardcoded full form.
        $this->assertMatchesRegularExpression(
            '#property="og:url" content="https://www\.example\.com/moodle(?:/r\.php)?/local_oerexchange/u/janedoe"#',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '#property="og:url" content="https://www\.example\.com/moodle(?:/r\.php)?/u/janedoe"#',
            $html
        );
        $this->assertStringContainsString('property="og:type" content="profile"', $html);
    }

    /**
     * The path gate (get_profile_slug_from_current_url()) matches on the
     * *end* of the path, not the whole thing, specifically so it tolerates
     * both the real, component-prefixed production path and a bare
     * '/u/{slug}' — see that method's docblock. Confirm the bare form still
     * gates correctly (even though it does not correspond to a resolvable
     * production URL, some other code path or future site config could
     * still present it), so a future change to the regex's anchoring can't
     * silently break this tolerance.
     */
    public function test_visible_profile_page_adds_og_tags_for_bare_u_path_too(): void {
        $this->resetAfterTest();
        global $PAGE;

        $user = $this->getDataGenerator()->create_user(['firstname' => 'Jane', 'lastname' => 'Doe']);
        profile_manager::get_or_create_for_user((int) $user->id);
        profile_manager::save((int) $user->id, [
            'slug' => 'janedoe', 'bio' => 'A biology teacher.', 'expertise' => [],
            'visible' => true,
        ]);

        $PAGE->set_url(new \moodle_url('/u/janedoe'));

        $hook = $this->make_hook();
        hook_callbacks::before_standard_head_html_generation($hook);

        $html = $hook->get_output();
        $this->assertStringContainsString('property="og:title"', $html);
        // The gate matched (og:title present), but the *emitted* og:url is
        // always the real path built by build_og_meta_html(), never an echo
        // of whatever $PAGE->url happened to be. As above, match only the
        // component-prefixed slug suffix — an optional '/r.php' prefix
        // depends on $CFG->routerconfigured, which this test has no reason
        // to be coupled to (see the matching comment and discovery doc
        // referenced in test_visible_profile_page_adds_og_tags() above).
        $this->assertMatchesRegularExpression(
            '#property="og:url" content="https://www\.example\.com/moodle(?:/r\.php)?/local_oerexchange/u/janedoe"#',
            $html
        );
    }

    public function test_hidden_profile_page_adds_nothing(): void {
        $this->resetAfterTest();
        global $PAGE;

        $user = $this->getDataGenerator()->create_user();
        profile_manager::get_or_create_for_user((int) $user->id);
        profile_manager::save((int) $user->id, [
            'slug' => 'hiddenone', 'bio' => '', 'expertise' => [],
            'visible' => false,
        ]);

        $PAGE->set_url(new \moodle_url('/u/hiddenone'));

        $hook = $this->make_hook();
        hook_callbacks::before_standard_head_html_generation($hook);

        $this->assertSame('', $hook->get_output());
    }

    public function test_nonexistent_slug_adds_nothing(): void {
        $this->resetAfterTest();
        global $PAGE;

        $PAGE->set_url(new \moodle_url('/u/doesnotexist'));

        $hook = $this->make_hook();
        hook_callbacks::before_standard_head_html_generation($hook);

        $this->assertSame('', $hook->get_output());
    }

    /**
     * The overwhelmingly common case: this hook fires on every page load
     * site-wide. A non-profile page must add nothing, and must do so
     * without the path check ever reaching profile_manager (verified here
     * by using a slug ('janedoe') that IS a real visible profile, but
     * requesting a URL whose path does not match the /u/{slug} pattern —
     * if the path gate were missing or wrong, this would incorrectly find
     * and emit that profile's tags).
     */
    public function test_non_profile_page_adds_nothing_even_for_a_real_slug(): void {
        $this->resetAfterTest();
        global $PAGE;

        $user = $this->getDataGenerator()->create_user();
        profile_manager::get_or_create_for_user((int) $user->id);
        profile_manager::save((int) $user->id, [
            'slug' => 'janedoe', 'bio' => 'A biology teacher.', 'expertise' => [],
            'visible' => true,
        ]);

        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => 2]));

        $hook = $this->make_hook();
        hook_callbacks::before_standard_head_html_generation($hook);

        $this->assertSame('', $hook->get_output());
    }

    /**
     * Regression test for the og:description multilang bug: the listener used
     * to build the description with a bare strip_tags(), which runs no text
     * filters at all, so a bilingual bio was advertised to every link-preview
     * crawler in BOTH languages at once — and its HTML entities stayed
     * encoded for html_writer to encode a second time.
     *
     * This drives the real listener end to end (not a pinned expression), so
     * a regression to strip_tags() fails here.
     */
    public function test_og_description_collapses_a_multilang_bio_to_one_language(): void {
        $this->resetAfterTest();
        global $PAGE;

        // Enable the exact filter trio these sinks depend on rather than
        // trusting site configuration — same rationale as
        // multilang_rendering_test::enable_multilang().
        filter_set_global_state('multilang', TEXTFILTER_ON);
        set_config('filterall', 1);
        set_config('stringfilters', 'multilang');

        $user = $this->getDataGenerator()->create_user(['firstname' => 'Jane', 'lastname' => 'Doe']);
        profile_manager::get_or_create_for_user((int) $user->id);
        profile_manager::save((int) $user->id, [
            'slug' => 'janedoe',
            'bio' => '<span lang="en" class="multilang">Chemistry &amp; biology teacher</span>'
                . '<span lang="ja" class="multilang">化学と生物の教師</span>',
            'expertise' => [],
            'visible' => true,
        ]);

        $PAGE->set_url(new \moodle_url('/local_oerexchange/u/janedoe'));

        $hook = $this->make_hook();
        hook_callbacks::before_standard_head_html_generation($hook);

        $html = $hook->get_output();

        // One language only, and no leftover multilang scaffolding.
        $this->assertStringContainsString('Chemistry', $html);
        $this->assertStringNotContainsString('化学と生物の教師', $html);
        $this->assertStringNotContainsString('multilang', $html);
        // The literal markup the bug leaked into the preview. html_writer
        // escapes attribute values, so an unfiltered bio would surface here
        // as an escaped '&lt;span'.
        $this->assertStringNotContainsString('&lt;span', $html);
        // Escaped exactly once by html_writer, not twice: content_to_text()
        // decodes '&amp;' back to '&' before html_writer re-encodes it.
        $this->assertStringContainsString('&amp;', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /**
     * Put the request into the exact state where the public landing page
     * should be served, so each guard test below can break one thing and
     * assert that one guard catches it.
     */
    private function arrange_anonymous_front_page_request(): void {
        global $SCRIPT;
        $this->setUser(null);
        $SCRIPT = '/index.php';
        unset($_GET['redirect'], $_POST['redirect']);
        set_config('publiclanding', 1, 'local_oerexchange');
        set_config('maintenance_enabled', 0);
    }

    public function test_landing_served_for_anonymous_visitor_on_the_front_page(): void {
        $this->resetAfterTest();
        $this->arrange_anonymous_front_page_request();

        $this->assertTrue(hook_callbacks::should_serve_public_landing());
    }

    public function test_landing_not_served_when_setting_is_off(): void {
        $this->resetAfterTest();
        $this->arrange_anonymous_front_page_request();
        set_config('publiclanding', 0, 'local_oerexchange');

        $this->assertFalse(hook_callbacks::should_serve_public_landing());
    }

    public function test_landing_not_served_off_the_front_page(): void {
        $this->resetAfterTest();
        global $SCRIPT;
        $this->arrange_anonymous_front_page_request();
        $SCRIPT = '/local/oerexchange/resource.php';

        $this->assertFalse(hook_callbacks::should_serve_public_landing());
    }

    public function test_landing_not_served_for_a_logged_in_user(): void {
        $this->resetAfterTest();
        $this->arrange_anonymous_front_page_request();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertFalse(hook_callbacks::should_serve_public_landing());
    }

    /**
     * A guest is treated as a visitor and gets the catalogue - the
     * behaviour confirmed by the user when the design was approved.
     */
    public function test_landing_served_for_a_guest_user(): void {
        $this->resetAfterTest();
        $this->arrange_anonymous_front_page_request();
        $this->setGuestUser();

        $this->assertTrue(hook_callbacks::should_serve_public_landing());
    }

    /**
     * The escape hatch, mirroring core's own /?redirect=0 convention
     * (public/index.php:37) - without it an admin who turns this on can
     * never reach the real front page again.
     */
    public function test_landing_not_served_when_redirect_is_suppressed(): void {
        $this->resetAfterTest();
        $this->arrange_anonymous_front_page_request();
        $_GET['redirect'] = '0';

        $this->assertFalse(hook_callbacks::should_serve_public_landing());
    }

    /**
     * Rendering in place skips core index.php's own
     * print_maintenance_message() call, so this guard is the only thing
     * stopping the catalogue being served while the site is closed.
     */
    public function test_landing_not_served_during_maintenance(): void {
        $this->resetAfterTest();
        $this->arrange_anonymous_front_page_request();
        set_config('maintenance_enabled', 1);

        $this->assertFalse(hook_callbacks::should_serve_public_landing());
    }

    /**
     * The key is the local URL string, because that is exactly what
     * add_option() stores (out_as_local_url(),
     * user/classes/hook/extend_default_homepage.php:57) and what core
     * then writes into $CFG->defaulthomepage.
     */
    public function test_catalogue_is_offered_as_a_default_home_page(): void {
        $this->resetAfterTest();

        $hook = new \core_user\hook\extend_default_homepage();
        hook_callbacks::extend_default_homepage($hook);

        $this->assertArrayHasKey('/local/oerexchange/index.php', $hook->get_options());
    }
}
