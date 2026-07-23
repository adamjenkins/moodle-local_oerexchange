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
            'orcidurl' => '', 'linkedinurl' => '', 'researchmapurl' => '', 'visible' => true,
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
            'orcidurl' => '', 'linkedinurl' => '', 'researchmapurl' => '', 'visible' => true,
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
            'orcidurl' => '', 'linkedinurl' => '', 'researchmapurl' => '', 'visible' => false,
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
            'orcidurl' => '', 'linkedinurl' => '', 'researchmapurl' => '', 'visible' => true,
        ]);

        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => 2]));

        $hook = $this->make_hook();
        hook_callbacks::before_standard_head_html_generation($hook);

        $this->assertSame('', $hook->get_output());
    }
}
