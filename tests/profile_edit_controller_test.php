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
use local_oerexchange\route\controller\profile_edit_controller;

/**
 * Tests for profile_edit_controller (GET+POST /u/{slug}/edit).
 *
 * Builds on the two route_testcase gotchas already documented in
 * profile_controller_test.php's class docblock (read that first):
 * add_class_routes_to_route_loader()'s $grouppath must be '' (not
 * route_loader_interface::ROUTE_GROUP_PAGE, which double-slashes the
 * compiled Slim pattern and silently 404s), and dispatch goes through
 * process_request(), not a nonexistent handle() method.
 *
 * Three more, specific to this controller's POST branch and its
 * non-owner exception, discovered while writing these tests and verified
 * empirically 2026-07-19 (disposable probe controllers/tests and a
 * temporary debug trace through the real request pipeline — r.php, every
 * lib/classes/router/middleware/*, the controller method itself — none of
 * it committed):
 *
 * - $request->getParsedBody() is unusable for a POST route in this
 *   codebase unless the #[route(...)] attribute declares a `requestbody:`
 *   schema (this one deliberately doesn't — see
 *   profile_edit_controller::save()'s docblock for the full trace).
 *   core\router\request_validator::validate_request_body()
 *   (lib/classes/router/request_validator.php:171-178) unconditionally
 *   replaces the request with `$request->withParsedBody([])` whenever
 *   get_request_body() is null, so by the time the controller runs,
 *   getParsedBody() is guaranteed empty regardless of what was posted.
 *   The controller instead reads the real $_POST superglobal via
 *   optional_param()/required_param() — unaffected by that PSR-7-only
 *   substitution — matching this plugin's existing convention for every
 *   other POST-handling script. These tests set $_POST directly for the
 *   same reason, rather than building a PSR-7 body stream that the
 *   controller under test no longer reads.
 *
 *   IMPORTANT: route_testcase::process_request() does NOT exercise this
 *   wiping at all — its mocking_route_loader mocks routes without
 *   attaching the real 'standard' page group's moodle_authentication_middleware
 *   /validation_middleware chain that production's router::configure_standard_route()
 *   attaches. A getParsedBody()-based controller therefore PASSES its
 *   PHPUnit tests while 404/silently-losing-data in real production — this
 *   is exactly why this task's brief mandates real end-to-end curl
 *   verification in addition to PHPUnit (see task-7-report.md's "Real
 *   end-to-end verification" section for the full incident).
 *
 * - An exception thrown inside a routed controller does NOT propagate out
 *   of process_request() to PHPUnit as a thrown exception: every route is
 *   wrapped in Slim\Middleware\ErrorMiddleware
 *   (\core\router::add_error_handler_middleware()), which catches every
 *   Throwable and converts it into a Response — assert on the response
 *   status code, not expectException(). A worry that this might instead
 *   route through Moodle's default_exception_handler() (which calls
 *   exit(1) unconditionally, lib/setuplib.php:205) and kill the PHPUnit
 *   process was checked empirically with a disposable probe controller:
 *   it does not happen for either a plain \moodle_exception (falls through
 *   to Slim's default handler, renders as a normal 500 response) or a
 *   \core\exception\access_denied_exception (routed via
 *   lib/classes/router/error_handler.php's determineStatusCode() override
 *   to a genuine 403) — both complete cleanly within the test process.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oerexchange\route\controller\profile_edit_controller
 */
final class profile_edit_controller_test extends route_testcase {
    #[\Override]
    protected function tearDown(): void {
        // These tests set $_POST directly (see class docblock) to reach the
        // controller's optional_param()/required_param() reads — clear it so
        // it can't leak into an unrelated later test.
        $_POST = [];
        parent::tearDown();
    }

    public function test_owner_can_view_edit_form_prefilled(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        profile_manager::get_or_create_for_user((int) $user->id);
        profile_manager::save((int) $user->id, [
            'slug' => 'ownerslug', 'bio' => 'My existing bio.', 'expertise' => ['biology'],
            'orcidurl' => '', 'linkedinurl' => '', 'researchmapurl' => '', 'visible' => true,
        ]);
        $this->setUser($user);

        $this->add_class_routes_to_route_loader(profile_edit_controller::class, '');

        $response = $this->process_request('GET', 'u/ownerslug/edit', route_loader_interface::ROUTE_GROUP_PAGE);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('<form', $body);
        $this->assertStringContainsString('My existing bio.', $body);
        $this->assertStringContainsString('value="ownerslug"', $body);
        $this->assertStringContainsString('biology', $body);
    }

    public function test_non_owner_is_denied_with_403(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        profile_manager::get_or_create_for_user((int) $owner->id);
        profile_manager::save((int) $owner->id, ['slug' => 'notyours', 'bio' => '', 'expertise' => [],
            'orcidurl' => '', 'linkedinurl' => '', 'researchmapurl' => '', 'visible' => true]);
        $intruder = $this->getDataGenerator()->create_user();
        $this->setUser($intruder);

        $this->add_class_routes_to_route_loader(profile_edit_controller::class, '');

        $response = $this->process_request('GET', 'u/notyours/edit', route_loader_interface::ROUTE_GROUP_PAGE);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_nonexistent_slug_renders_404(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->add_class_routes_to_route_loader(profile_edit_controller::class, '');

        $response = $this->process_request('GET', 'u/doesnotexist/edit', route_loader_interface::ROUTE_GROUP_PAGE);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_owner_can_save_changes_via_profile_manager(): void {
        // The controller delegates saving to profile_manager::save(), already
        // covered by profile_manager_test.php — this test just confirms the
        // controller wires POST data through to it and redirects afterward,
        // not re-testing save()'s own validation rules.
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $profile = profile_manager::get_or_create_for_user((int) $user->id);
        $this->setUser($user);

        $this->add_class_routes_to_route_loader(profile_edit_controller::class, '');

        $_POST = [
            'slug' => $profile->slug,
            'bio' => 'New bio',
            'expertise' => 'biology, chemistry',
            'orcidurl' => '',
            'linkedinurl' => '',
            'researchmapurl' => '',
            'visible' => '1',
            'sesskey' => sesskey(),
        ];
        $response = $this->process_request('POST', 'u/' . $profile->slug . '/edit', route_loader_interface::ROUTE_GROUP_PAGE);

        $this->assertContains($response->getStatusCode(), [302, 303]);
        $location = $response->getHeaderLine('Location');
        // Must be the real, component-prefixed, routed_path() URL (Task 6's
        // lesson — see profile_edit_controller's class docblock) rather than
        // a bare /u/{slug}, which 404s in production.
        $this->assertStringContainsString('/local_oerexchange/u/' . $profile->slug, $location);

        $updated = profile_manager::get_by_slug($profile->slug);
        $this->assertSame('New bio', $updated->bio);
        $this->assertSame(['biology', 'chemistry'], json_decode($updated->expertise, true));
        $this->assertSame('1', (string) $updated->visible);
    }

    public function test_owner_can_change_their_own_slug(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $profile = profile_manager::get_or_create_for_user((int) $user->id);
        $this->setUser($user);

        $this->add_class_routes_to_route_loader(profile_edit_controller::class, '');

        $_POST = [
            'slug' => 'brandnewslug',
            'bio' => '',
            'expertise' => '',
            'orcidurl' => '',
            'linkedinurl' => '',
            'researchmapurl' => '',
            'sesskey' => sesskey(),
        ];
        $response = $this->process_request('POST', 'u/' . $profile->slug . '/edit', route_loader_interface::ROUTE_GROUP_PAGE);

        $this->assertContains($response->getStatusCode(), [302, 303]);
        $this->assertStringContainsString('/local_oerexchange/u/brandnewslug', $response->getHeaderLine('Location'));
        $this->assertNull(profile_manager::get_by_slug($profile->slug));
        $this->assertNotNull(profile_manager::get_by_slug('brandnewslug'));
    }

    public function test_save_with_missing_sesskey_is_rejected(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $profile = profile_manager::get_or_create_for_user((int) $user->id);
        $this->setUser($user);

        $this->add_class_routes_to_route_loader(profile_edit_controller::class, '');

        $_POST = [
            'slug' => $profile->slug,
            'bio' => 'Should not be saved',
            'sesskey' => 'wrong',
        ];
        $response = $this->process_request('POST', 'u/' . $profile->slug . '/edit', route_loader_interface::ROUTE_GROUP_PAGE);

        // The require_sesskey() call throws a plain \moodle_exception('invalidsesskey')
        // *before* this controller's save()/profile_manager::save() try/catch
        // even starts (Task 7's fix only wraps the profile_manager::save()
        // call, deliberately not require_sesskey() — a bad sesskey is a CSRF
        // rejection, not a form-validation error to redisplay). That
        // exception is not a response_aware_exception, so it falls through
        // to Slim's default ErrorHandler::determineStatusCode(), which
        // returns 500 for any non-HttpException (vendor/slim/slim/Slim/
        // Handlers/ErrorHandler.php:148-159) — matching this file's own
        // class docblock ("a plain \moodle_exception ... renders as a
        // normal 500 response"). Verified empirically 2026-07-19 with a
        // disposable probe test asserting on the real status code (not
        // committed): 500, not 403 — confirming 500 here rather than the
        // 403 originally assumed for this assertion.
        $this->assertSame(500, $response->getStatusCode());
        $unchanged = profile_manager::get_by_slug($profile->slug);
        $this->assertSame('', $unchanged->bio);
    }

    public function test_invalid_slug_on_save_surfaces_error(): void {
        // Not re-testing profile_manager::save()'s validation logic itself
        // (profile_manager_test.php's job) — just confirming this
        // controller's own error path surfaces it rather than silently
        // swallowing or mis-saving it, and (Task 7 review finding) that the
        // form is redisplayed with the error and the user's just-submitted
        // values, not bounced to Slim's generic error page with everything
        // typed lost.
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $profile = profile_manager::get_or_create_for_user((int) $user->id);
        $this->setUser($user);

        $this->add_class_routes_to_route_loader(profile_edit_controller::class, '');

        $_POST = [
            'slug' => 'not a valid slug!',
            'bio' => 'This bio must survive the failed save.',
            'expertise' => 'biology, chemistry',
            'sesskey' => sesskey(),
        ];
        $response = $this->process_request('POST', 'u/' . $profile->slug . '/edit', route_loader_interface::ROUTE_GROUP_PAGE);

        // The fix's whole point: a validation failure re-renders the form
        // (200), not a redirect and not Slim's generic error page.
        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString(get_string('error_invalidslug', 'local_oerexchange'), $body);
        // The just-submitted values must be shown back, not the stale
        // pre-save $profile row (which has empty bio/expertise).
        $this->assertStringContainsString('This bio must survive the failed save.', $body);
        $this->assertStringContainsString('biology, chemistry', $body);
        $this->assertStringContainsString('value="not a valid slug!"', $body);

        $unchanged = profile_manager::get_by_slug($profile->slug);
        $this->assertNotNull($unchanged);
        $this->assertSame('', $unchanged->bio);
    }
}
