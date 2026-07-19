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
