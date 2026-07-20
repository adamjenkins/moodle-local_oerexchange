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

use core\router\route_loader_interface;
use core\tests\router\route_testcase;
use local_oerexchange\local\profile_manager;
use local_oerexchange\route\controller\profile_controller;

/**
 * Tests for profile_controller (GET /u/{slug}).
 *
 * route_testcase has no handle() dispatch helper (verified by reading
 * lib/tests/classes/router/route_testcase.php in full) — the real dispatch
 * method used by working core route-controller tests (e.g.
 * course/tests/route/controller/restricted_section_test.php) is
 * process_request(method, path-without-leading-slash, grouppath).
 *
 * add_class_routes_to_route_loader()'s $grouppath is passed straight to
 * mocking_route_loader's Slim App::group() call as the literal group
 * pattern, NOT used the way route_loader_interface::ROUTE_GROUP_PAGE ('/')
 * is used elsewhere (as a process_request() URI *prefix*, or as an array
 * key in the real route_loader::configure_routes()). Passing the '/'
 * constant here double-slashes the compiled Slim pattern ('//u/{slug}',
 * verified via lib/classes/router/route.php's Slim group()/map()
 * concatenation), which then fails to match a real single-slash request
 * path — silently returning 404 instead of matching, indistinguishable at
 * the assertion level from a correct 404. The real, non-test
 * core\router\route_loader::configure_standard_routes() registers the PAGE
 * group with $app->group('', ...) (an *empty* string), confirmed by reading
 * lib/classes/router/route_loader.php:73-80 — passing '' here (which still
 * skips guess_group_path_from_classname(), since it's non-null) matches
 * that and is what actually routes correctly.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oerexchange\route\controller\profile_controller
 */
final class profile_controller_test extends route_testcase {
    public function test_visible_profile_renders_200_with_slug_and_bio(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Jane', 'lastname' => 'Doe']);
        profile_manager::get_or_create_for_user((int) $user->id);
        profile_manager::save((int) $user->id, ['slug' => 'janedoe', 'bio' => 'A biology teacher.',
            'expertise' => [], 'orcidurl' => '', 'linkedinurl' => '', 'researchmapurl' => '', 'visible' => true]);

        $this->add_class_routes_to_route_loader(
            profile_controller::class,
            ''
        );

        $response = $this->process_request('GET', 'u/janedoe', route_loader_interface::ROUTE_GROUP_PAGE);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('A biology teacher.', $body);
        $this->assertStringContainsString('Jane Doe', $body);
        // Open Graph tags are no longer emitted by the controller itself —
        // \local_oerexchange\hook_callbacks::before_standard_head_html_generation()
        // (covered by tests/hook_callbacks_test.php) now owns placing them
        // into the real <head> via the Hooks API. A second, <body>-placed
        // copy here would just conflict with the <head> one.
        $this->assertStringNotContainsString('property="og:title"', $body);

        // Page URL and the share button's data-share-url must both be the
        // real, resolvable URL — /u/{slug} alone 404s in production because
        // the #[route] attribute's path is component-relative (see this
        // controller's class docblock); only /local_oerexchange/u/{slug} is
        // the actual compiled route pattern
        // (abstract_route_loader.php:112).
        //
        // The controller builds both $PAGE->url and the share button's URL
        // from the exact same moodle_url::routed_path() call (see
        // profile_controller.php around the $profileurl assignment), which
        // prepends '/r.php/' unless $CFG->routerconfigured is truthy — a
        // flag whose value is environment- (and even routed-request-phase-)
        // dependent, not a constant this test can hardcode against (see
        // dev-docs/harness/discoveries/
        // 2026-07-19-routerconfigured-inconsistent-during-routed-requests.md,
        // which found this flag's behaviour unusually order-dependent even
        // in production). What actually matters here is that $PAGE->url and
        // the share button stay byte-for-byte consistent with each other
        // and with what routed_path() itself would produce for this slug in
        // whatever environment runs this test — so compute the expected
        // value the same way the controller does, rather than asserting one
        // hardcoded literal.
        global $PAGE;
        $expectedprofileurl = \moodle_url::routed_path('/local_oerexchange/u/janedoe');
        $this->assertSame($expectedprofileurl->get_path(), $PAGE->url->get_path());
        $this->assertStringContainsString(
            'data-share-url="' . $expectedprofileurl->out(false) . '"',
            $body
        );
        $this->assertStringNotContainsString('data-share-url="https://www.example.com/moodle/u/janedoe"', $body);
        $this->assertStringNotContainsString(
            'data-share-url="https://www.example.com/moodle/r.php/u/janedoe"',
            $body
        );
    }

    public function test_visible_profile_shows_metrics_badges_and_message_link(): void {
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user(['firstname' => 'Rich', 'lastname' => 'Resources']);
        profile_manager::get_or_create_for_user((int) $creator->id);
        profile_manager::save((int) $creator->id, ['slug' => 'richres', 'bio' => 'Prolific.',
            'expertise' => ['Biology', 'Chemistry'], 'orcidurl' => 'https://orcid.org/0000-0000-0000-0000',
            'linkedinurl' => '', 'researchmapurl' => '', 'visible' => true]);

        // Give the creator enough published resources + downloads + rating to earn the badge.
        set_config('badge_trustedcontributor_minresources', 1, 'local_oerexchange');
        set_config('badge_trustedcontributor_mindownloads', 1, 'local_oerexchange');
        set_config('badge_trustedcontributor_minrating', 1, 'local_oerexchange');

        global $DB;
        $siteid = $DB->insert_record('local_oerexchange_sites', (object) [
            'name' => 'S', 'url' => 'https://x', 'contact' => 'x@x.com', 'serviceuserid' => null,
            'status' => 'active', 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course',
            'title' => 'Cell Biology 101',
            'summary' => 'A course.',
            'language' => '',
            'tags' => '',
            'licenseshortname' => 'CC BY',
            'activitytype' => null,
            'courseformat' => null,
            'creatorid' => $creator->id,
            'siteid' => $siteid,
            'status' => 'published',
            'downloadcount' => 42,
            'importcount' => 0,
            'forkedfromid' => null,
            'timeshared' => time() - 3600,
            'timemodified' => time() - 3600,
        ]);
        \local_oerexchange\local\badge_manager::evaluate_and_award((int) $creator->id);

        $this->add_class_routes_to_route_loader(
            profile_controller::class,
            ''
        );

        $response = $this->process_request('GET', 'u/richres', route_loader_interface::ROUTE_GROUP_PAGE);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Cell Biology 101', $body);
        $this->assertStringContainsString('Trusted Contributor', $body);
        $this->assertStringContainsString('message/index.php', $body);
        $this->assertStringContainsString((string) $creator->id, $body);
        $this->assertStringContainsString('Biology', $body);
    }

    /**
     * FINDING 3 (final whole-branch review): before this fix, nothing in the
     * codebase linked to /u/{slug}/edit — a user would have to hand-type the
     * URL. The link must appear for the owner and be absent for everyone
     * else (a non-owner, and an anonymous visitor).
     */
    public function test_owner_sees_edit_link_but_others_do_not(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        profile_manager::get_or_create_for_user((int) $owner->id);
        profile_manager::save((int) $owner->id, ['slug' => 'ownerview', 'bio' => '', 'expertise' => [],
            'orcidurl' => '', 'linkedinurl' => '', 'researchmapurl' => '', 'visible' => true]);

        $this->add_class_routes_to_route_loader(profile_controller::class, '');

        // Owner, logged in: link must appear.
        $this->setUser($owner);
        $response = $this->process_request('GET', 'u/ownerview', route_loader_interface::ROUTE_GROUP_PAGE);
        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('/local_oerexchange/u/ownerview/edit', $body);
        $this->assertStringContainsString(get_string('profileeditlink', 'local_oerexchange'), $body);

        // A different logged-in user: link must be absent.
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($other);
        $response = $this->process_request('GET', 'u/ownerview', route_loader_interface::ROUTE_GROUP_PAGE);
        $body = (string) $response->getBody();
        $this->assertStringNotContainsString('/local_oerexchange/u/ownerview/edit', $body);

        // Anonymous visitor: link must be absent.
        $this->setUser(null);
        $response = $this->process_request('GET', 'u/ownerview', route_loader_interface::ROUTE_GROUP_PAGE);
        $body = (string) $response->getBody();
        $this->assertStringNotContainsString('/local_oerexchange/u/ownerview/edit', $body);
    }

    /**
     * FINDING 4 (final whole-branch review): the profile resource grid
     * omitted cover-image thumbnails despite the thumbnail subsystem already
     * existing and working on resource.php. A resource with a cover image
     * must show it on the grid; one without must render cleanly (no broken
     * markup) with no <img> tag for that card — resource.php itself has no
     * placeholder-icon fallback to reuse (verified by reading it in full),
     * so neither does this grid.
     */
    public function test_resource_grid_shows_cover_image_when_present_and_omits_it_when_absent(): void {
        global $DB;
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        profile_manager::get_or_create_for_user((int) $creator->id);
        profile_manager::save((int) $creator->id, ['slug' => 'thumbcreator', 'bio' => '', 'expertise' => [],
            'orcidurl' => '', 'linkedinurl' => '', 'researchmapurl' => '', 'visible' => true]);

        $siteid = $DB->insert_record('local_oerexchange_sites', (object) [
            'name' => 'S', 'url' => 'https://x', 'contact' => 'x@x.com', 'serviceuserid' => null,
            'status' => 'active', 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $withimageid = $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'Has A Cover', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'CC BY', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => $creator->id, 'siteid' => $siteid, 'status' => 'published',
            'downloadcount' => 0, 'importcount' => 0, 'forkedfromid' => null,
            'timeshared' => time() - 100, 'timemodified' => time() - 100,
        ]);
        $noimageid = $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'No Cover Here', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'CC BY', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => $creator->id, 'siteid' => $siteid, 'status' => 'published',
            'downloadcount' => 0, 'importcount' => 0, 'forkedfromid' => null,
            'timeshared' => time() - 200, 'timemodified' => time() - 200,
        ]);

        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_oerexchange',
            'filearea' => 'coverimage',
            'itemid' => $withimageid,
            'filepath' => '/',
            'filename' => 'cover.png',
        ], 'fake-png-bytes');

        $this->add_class_routes_to_route_loader(profile_controller::class, '');

        $response = $this->process_request('GET', 'u/thumbcreator', route_loader_interface::ROUTE_GROUP_PAGE);
        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();

        $expectedurl = \moodle_url::make_pluginfile_url(
            \context_system::instance()->id,
            'local_oerexchange',
            'coverimage',
            $withimageid,
            '/',
            'cover.png'
        )->out(false);
        $this->assertStringContainsString($expectedurl, $body);
        $this->assertStringContainsString('<img', $body);

        // The card without a cover image must not reference a coverimage URL
        // for its own resource id.
        $noimageurlfragment = 'coverimage/' . $noimageid . '/';
        $this->assertStringNotContainsString($noimageurlfragment, $body);
    }

    public function test_hidden_profile_renders_404(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        profile_manager::get_or_create_for_user((int) $user->id);
        profile_manager::save((int) $user->id, ['slug' => 'hiddenone', 'bio' => '', 'expertise' => [],
            'orcidurl' => '', 'linkedinurl' => '', 'researchmapurl' => '', 'visible' => false]);

        $this->add_class_routes_to_route_loader(
            profile_controller::class,
            ''
        );

        $response = $this->process_request('GET', 'u/hiddenone', route_loader_interface::ROUTE_GROUP_PAGE);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_nonexistent_slug_renders_404(): void {
        $this->resetAfterTest();

        $this->add_class_routes_to_route_loader(
            profile_controller::class,
            ''
        );

        $response = $this->process_request('GET', 'u/doesnotexist', route_loader_interface::ROUTE_GROUP_PAGE);

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * Design requirement: hidden-profile and nonexistent-slug 404s must be
     * indistinguishable — no distinction leaked to the client.
     */
    public function test_hidden_and_nonexistent_404_responses_are_indistinguishable(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        profile_manager::get_or_create_for_user((int) $user->id);
        profile_manager::save((int) $user->id, ['slug' => 'hiddentwo', 'bio' => '', 'expertise' => [],
            'orcidurl' => '', 'linkedinurl' => '', 'researchmapurl' => '', 'visible' => false]);

        $this->add_class_routes_to_route_loader(
            profile_controller::class,
            ''
        );

        // In this developer-mode test environment, an uncaught 404 renders
        // Slim's own debug error page, which embeds a full PHP stacktrace —
        // including the *calling* line inside this test method. Two
        // separate process_request() call statements on two different
        // source lines would therefore always produce two different bodies,
        // regardless of anything profile_controller.php does — an artifact
        // of this harness, not a real leak (production has debug details
        // off, and a real client's two HTTP requests wouldn't carry PHP
        // source-line provenance either way). Route both requests through
        // the exact same call site so the only remaining source of any
        // difference is application behaviour.
        [$hiddenresponse, $missingresponse] = array_map(
            fn (string $path) => $this->process_request('GET', $path, route_loader_interface::ROUTE_GROUP_PAGE),
            ['u/hiddentwo', 'u/nosuchslugatall'],
        );

        $this->assertSame($missingresponse->getStatusCode(), $hiddenresponse->getStatusCode());
        $this->assertSame((string) $missingresponse->getBody(), (string) $hiddenresponse->getBody());
    }
}
