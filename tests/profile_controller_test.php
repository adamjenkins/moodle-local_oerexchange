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
use core\router\route_loader_interface;
use core\tests\router\route_testcase;
use local_oerexchange\local\profile_manager;
use local_oerexchange\route\controller\profile_controller;

defined('MOODLE_INTERNAL') || die();

global $CFG;
// PROFILE_VISIBLE_ALL / PROFILE_VISIBLE_PRIVATE (user/profile/lib.php:37,43)
// live in a legacy lib rather than an autoloaded class, and are needed when
// the create_custom_profile_field() array literals below are evaluated —
// before that generator's own require_once would have run.
require_once($CFG->dirroot . '/user/profile/lib.php');

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
 */
#[CoversClass(profile_controller::class)]
final class profile_controller_test extends route_testcase {
    public function test_visible_profile_renders_200_with_slug_and_bio(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Jane', 'lastname' => 'Doe']);
        profile_manager::get_or_create_for_user((int) $user->id);
        profile_manager::save((int) $user->id, ['slug' => 'janedoe', 'bio' => 'A biology teacher.',
            'expertise' => [], 'visible' => true]);

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
            'expertise' => ['Biology', 'Chemistry'], 'visible' => true]);

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
        profile_manager::save((int) $owner->id, ['slug' => 'ownerview', 'bio' => '', 'expertise' => [], 'visible' => true]);

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
        profile_manager::save((int) $creator->id, ['slug' => 'thumbcreator', 'bio' => '', 'expertise' => [], 'visible' => true]);

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

    /**
     * Task 2: the three hardcoded portfolio links that used to sit here are
     * gone; their slot now holds the user's additional user profile fields
     * (admin page /user/profile/index.php) that are set to "Visible to
     * everyone" — and only those, and only when they hold a value.
     *
     * Deliberately asserted as an anonymous, not-logged-in visitor
     * (setUser(null)): this page is public by design (see the controller's
     * class docblock) and the decision is that "Visible to everyone" is read
     * literally, so there is no isloggedin() gate and no
     * $CFG->forceloginforprofiles consultation to satisfy.
     */
    public function test_public_custom_profile_fields_are_shown_to_an_anonymous_visitor(): void {
        $this->resetAfterTest();

        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'oerpublicfield',
            'name' => 'Research group',
            'visible' => PROFILE_VISIBLE_ALL,
        ]);
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'oerprivatefield',
            'name' => 'Home telephone',
            'visible' => PROFILE_VISIBLE_PRIVATE,
        ]);
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'oerblankfield',
            'name' => 'Office number',
            'visible' => PROFILE_VISIBLE_ALL,
        ]);

        $user = $this->getDataGenerator()->create_user([
            'profile_field_oerpublicfield' => 'Marine Ecology Lab',
            'profile_field_oerprivatefield' => 'Do not publish this',
            // Note: profile_field_oerblankfield is deliberately left unset.
        ]);
        profile_manager::get_or_create_for_user((int) $user->id);
        profile_manager::save((int) $user->id, ['slug' => 'fielduser', 'bio' => '', 'expertise' => [], 'visible' => true]);

        $this->add_class_routes_to_route_loader(profile_controller::class, '');
        $this->setUser(null);

        $response = $this->process_request('GET', 'u/fielduser', route_loader_interface::ROUTE_GROUP_PAGE);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();

        // Visible to everyone AND populated: heading, field name and value.
        $this->assertStringContainsString(get_string('profilefieldsheading', 'local_oerexchange'), $body);
        $this->assertStringContainsString('Research group', $body);
        $this->assertStringContainsString('Marine Ecology Lab', $body);

        // Not visible to everyone: absent despite holding a value. This is the
        // deliberate divergence from core's show_field_content(), which would
        // reveal it to a viewer holding moodle/user:viewalldetails.
        $this->assertStringNotContainsString('Home telephone', $body);
        $this->assertStringNotContainsString('Do not publish this', $body);

        // Visible to everyone but empty for this user: omitted entirely.
        $this->assertStringNotContainsString('Office number', $body);
    }

    /**
     * The owner viewing their OWN public profile still sees only the
     * "Visible to everyone" fields — this is the case the whole divergence
     * from core exists for, and the one the anonymous-visitor test above
     * cannot reach.
     *
     * Core's profile_field_base::is_visible() (user/profile/lib.php) returns
     * true for a PROFILE_VISIBLE_PRIVATE field when
     * $this->userid == $USER->id, so a show_field_content()-based
     * implementation would show the owner a field no visitor can see. On a
     * page whose entire purpose is "this is what the public sees", that would
     * actively mislead the person deciding what to publish.
     */
    public function test_a_private_field_stays_hidden_even_from_the_profiles_own_owner(): void {
        $this->resetAfterTest();

        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'oerpublicfield',
            'name' => 'Research group',
            'visible' => PROFILE_VISIBLE_ALL,
        ]);
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'oerprivatefield',
            'name' => 'Home telephone',
            'visible' => PROFILE_VISIBLE_PRIVATE,
        ]);

        $user = $this->getDataGenerator()->create_user([
            'profile_field_oerpublicfield' => 'Marine Ecology Lab',
            'profile_field_oerprivatefield' => 'Do not publish this',
        ]);
        profile_manager::get_or_create_for_user((int) $user->id);
        profile_manager::save((int) $user->id, ['slug' => 'owneruser', 'bio' => '', 'expertise' => [], 'visible' => true]);

        $this->add_class_routes_to_route_loader(profile_controller::class, '');
        $this->setUser($user);

        $response = $this->process_request('GET', 'u/owneruser', route_loader_interface::ROUTE_GROUP_PAGE);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();

        $this->assertStringContainsString('Research group', $body);
        $this->assertStringContainsString('Marine Ecology Lab', $body);
        $this->assertStringNotContainsString('Home telephone', $body);
        $this->assertStringNotContainsString('Do not publish this', $body);
    }

    /**
     * A 'social' profile field substitutes its RAW stored value into
     * `<a href="%%PLAIN%%">` with no escaping of its own
     * (user/profile/field/social/field.class.php + classes/helper.php), and
     * the edit form's PARAM_URL is bypassed by every non-form writer — the
     * web-service create/update_users functions declare customfields[].value
     * as PARAM_RAW, as do uploaduser and directory sync. Core tolerates that
     * because /user/profile.php honours $CFG->forceloginforprofiles; this
     * page is deliberately public, so it must neutralise the payload itself.
     */
    public function test_a_social_field_cannot_publish_a_javascript_url(): void {
        global $DB;

        $this->resetAfterTest();

        // A social field carries its network key in BOTH columns, and each is
        // read by different core code — get this wrong and the test passes
        // vacuously. `name` is the key that set_field() swaps for a localised
        // label via profilefield_social\helper::get_networks(); `param1` is
        // the key display_data() looks up in get_network_urls(), falling
        // through to the bare stored string when it does not match. 'url' is
        // the network whose template is `<a href="%%PLAIN%%">%%PLAIN%%</a>` —
        // the raw value straight into an href.
        $field = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'social',
            'shortname' => 'oersocialfield',
            'name' => 'url',
            'param1' => 'url',
            'visible' => PROFILE_VISIBLE_ALL,
        ]);

        $user = $this->getDataGenerator()->create_user();
        // Written straight to the column, exactly as a PARAM_RAW web-service
        // or directory-sync write would — the edit form is not involved.
        $DB->insert_record('user_info_data', (object) [
            'userid' => $user->id,
            'fieldid' => $field->id,
            'data' => 'javascript:alert(1)',
            'dataformat' => FORMAT_HTML,
        ]);

        profile_manager::get_or_create_for_user((int) $user->id);
        profile_manager::save((int) $user->id, ['slug' => 'socialuser', 'bio' => '', 'expertise' => [], 'visible' => true]);

        $this->add_class_routes_to_route_loader(profile_controller::class, '');
        $this->setUser(null);

        $response = $this->process_request('GET', 'u/socialuser', route_loader_interface::ROUTE_GROUP_PAGE);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();

        // The field itself still renders — the fix neutralises, it does not
        // silently drop whole field types.
        $this->assertStringContainsString(get_string('webpage', 'profilefield_social'), $body);
        $this->assertStringContainsString('javascript:alert(1)', $body);
        // But never as a live href: clean_text() strips the dangerous scheme
        // while leaving the (now inert) anchor and its text.
        $this->assertStringNotContainsString('href="javascript:', $body);
        $this->assertStringNotContainsString("href='javascript:", $body);
    }

    /**
     * The removed portfolio links must not reappear in any form: no ORCID/
     * LinkedIn/ResearchMap labels, and no attempt to read a column that no
     * longer exists (which would surface as a dml exception, not a 200).
     */
    public function test_removed_portfolio_links_are_gone_from_the_page(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        profile_manager::get_or_create_for_user((int) $user->id);
        profile_manager::save((int) $user->id, ['slug' => 'nolinks', 'bio' => '', 'expertise' => [], 'visible' => true]);

        $this->add_class_routes_to_route_loader(profile_controller::class, '');

        $response = $this->process_request('GET', 'u/nolinks', route_loader_interface::ROUTE_GROUP_PAGE);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringNotContainsString('ORCID', $body);
        $this->assertStringNotContainsString('ResearchMap', $body);
        // NB deliberately not asserting on 'LinkedIn': share_targets::ALL
        // includes a LinkedIn share button (share_targets.php:45), on by
        // default, which legitimately puts that word on this page.
        // No custom profile fields exist in this test, so the block's heading
        // must not be emitted either.
        $this->assertStringNotContainsString(get_string('profilefieldsheading', 'local_oerexchange'), $body);
    }

    /**
     * Task 3: a multilang span in a resource title must be filtered down to a
     * single language by format_string(), not escaped by s() into visible
     * literal `<span lang="en" class="multilang">` markup — in both the card
     * title and the cover image's alt attribute. The alt attribute is inside
     * html_writer::empty_tag(), which already runs s() over attribute values
     * (lib/classes/output/html_writer.php:113), so the old inner s() there was
     * a double-escape: it produced "&amp;lt;span ..." in the rendered alt.
     */
    public function test_multilang_resource_title_is_filtered_not_escaped(): void {
        global $DB;
        $this->resetAfterTest();

        // Enable the exact filter trio this sink depends on, rather than
        // relying on site configuration (matching multilang_rendering_test).
        filter_set_global_state('multilang', TEXTFILTER_ON);
        set_config('filterall', 1);
        set_config('stringfilters', 'multilang');

        $creator = $this->getDataGenerator()->create_user();
        profile_manager::get_or_create_for_user((int) $creator->id);
        profile_manager::save((int) $creator->id, ['slug' => 'mluser', 'bio' => '', 'expertise' => [], 'visible' => true]);

        $siteid = $DB->insert_record('local_oerexchange_sites', (object) [
            'name' => 'S', 'url' => 'https://x', 'contact' => 'x@x.com', 'serviceuserid' => null,
            'status' => 'active', 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $title = '<span lang="en" class="multilang">Marine Biology</span>'
            . '<span lang="ja" class="multilang">海洋生物学</span>';
        $resourceid = $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => $title, 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'CC BY', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => $creator->id, 'siteid' => $siteid, 'status' => 'published',
            'downloadcount' => 0, 'importcount' => 0, 'forkedfromid' => null,
            'timeshared' => time() - 100, 'timemodified' => time() - 100,
        ]);

        // A cover image, so the alt-attribute sink is actually exercised.
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_oerexchange',
            'filearea' => 'coverimage',
            'itemid' => $resourceid,
            'filepath' => '/',
            'filename' => 'cover.png',
        ], 'fake-png-bytes');

        $this->add_class_routes_to_route_loader(profile_controller::class, '');

        $response = $this->process_request('GET', 'u/mluser', route_loader_interface::ROUTE_GROUP_PAGE);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();

        // Filtered down to exactly one language...
        $this->assertStringContainsString('Marine Biology', $body);
        $this->assertStringNotContainsString('海洋生物学', $body);
        // ...with no literal multilang markup left anywhere on the page, in
        // either the raw form (unfiltered) or the escaped form (s()'d).
        $this->assertStringNotContainsString('class="multilang"', $body);
        $this->assertStringNotContainsString('class=&quot;multilang&quot;', $body);
        // The alt attribute specifically: no double-escaped markup, and the
        // filtered title present inside it.
        $this->assertStringNotContainsString('&amp;lt;span', $body);
        $this->assertStringContainsString(
            'alt="' . s(get_string('thumbnailalt', 'local_oerexchange', 'Marine Biology')) . '"',
            $body
        );
    }

    /**
     * Task 3: the bio is rendered with FORMAT_MOODLE, not FORMAT_PLAIN, so
     * text filters actually run over it (FORMAT_PLAIN runs none). Cleaning
     * stays on — no 'noclean' is passed — so a script tag in a bio must not
     * survive into the page.
     */
    public function test_bio_is_filtered_and_still_cleaned(): void {
        $this->resetAfterTest();
        filter_set_global_state('multilang', TEXTFILTER_ON);
        set_config('filterall', 1);
        set_config('stringfilters', 'multilang');

        $user = $this->getDataGenerator()->create_user();
        profile_manager::get_or_create_for_user((int) $user->id);
        profile_manager::save((int) $user->id, [
            'slug' => 'biouser',
            'bio' => '<span lang="en" class="multilang">A biology teacher.</span>'
                . '<span lang="ja" class="multilang">生物の教員です。</span>'
                . "\n<script>alert(1)</script>",
            'expertise' => [],
            'visible' => true,
        ]);

        $this->add_class_routes_to_route_loader(profile_controller::class, '');

        $response = $this->process_request('GET', 'u/biouser', route_loader_interface::ROUTE_GROUP_PAGE);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();

        // Filters ran: one language survives, no literal multilang markup.
        $this->assertStringContainsString('A biology teacher.', $body);
        $this->assertStringNotContainsString('生物の教員です。', $body);
        $this->assertStringNotContainsString('class="multilang"', $body);
        // Cleaning stayed on.
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
    }

    public function test_hidden_profile_renders_404(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        profile_manager::get_or_create_for_user((int) $user->id);
        profile_manager::save((int) $user->id, ['slug' => 'hiddenone', 'bio' => '', 'expertise' => [], 'visible' => false]);

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
        profile_manager::save((int) $user->id, ['slug' => 'hiddentwo', 'bio' => '', 'expertise' => [], 'visible' => false]);

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

    /**
     * A data resource's badge must say "Data resource", not "Course".
     *
     * The regression this pins: the type badge was a two-way ternary over a
     * three-value column —
     *   $r->type === 'activity' ? typeactivity : typecourse
     * — so every resource that was not an activity was labelled a Course,
     * including data resources, while the catalogue one click away rendered
     * the correct three-way label. Reverting resource_type::label() to that
     * ternary must make this test fail.
     *
     * @return void
     */
    public function test_data_resource_badge_is_not_labelled_course(): void {
        $this->resetAfterTest();
        global $DB;

        $creator = $this->getDataGenerator()->create_user(['firstname' => 'Dana', 'lastname' => 'Types']);
        profile_manager::get_or_create_for_user((int) $creator->id);
        profile_manager::save((int) $creator->id, ['slug' => 'danatypes', 'bio' => '',
            'expertise' => [], 'visible' => true]);

        $this->make_profile_resource($creator, [
            'type' => 'data',
            'title' => 'Question bank export',
            'dataresourcetype' => 'questionbank',
        ]);

        $this->add_class_routes_to_route_loader(profile_controller::class, '');
        $response = $this->process_request('GET', 'u/danatypes', route_loader_interface::ROUTE_GROUP_PAGE);
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(get_string('typedata', 'local_oerexchange'), $body);
        // The specific failure being guarded, stated as its own assertion so
        // a failure names the actual defect rather than "string not found".
        $this->assertStringNotContainsString(
            '>' . get_string('typecourse', 'local_oerexchange') . '<',
            $body,
            'A data resource was labelled with the Course type badge.'
        );
    }

    /**
     * An activity resource's badge names the activity, not "Course" either.
     *
     * @return void
     */
    public function test_activity_resource_badge_names_the_activity(): void {
        $this->resetAfterTest();

        $creator = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Act']);
        profile_manager::get_or_create_for_user((int) $creator->id);
        profile_manager::save((int) $creator->id, ['slug' => 'adaact', 'bio' => '',
            'expertise' => [], 'visible' => true]);

        $this->make_profile_resource($creator, [
            'type' => 'activity',
            'title' => 'A quiz',
            'activitytype' => 'quiz',
        ]);

        $this->add_class_routes_to_route_loader(profile_controller::class, '');
        $response = $this->process_request('GET', 'u/adaact', route_loader_interface::ROUTE_GROUP_PAGE);
        $body = (string) $response->getBody();

        $this->assertStringContainsString(get_string('typeactivity', 'local_oerexchange'), $body);
        $this->assertStringContainsString('quiz', $body);
    }

    /**
     * A URL in a public custom profile field is auto-linked.
     *
     * The field values used to be passed through a bare clean_text(), which
     * escapes but runs NO text filter, so a URL a user typed into a profile
     * field stayed dead text while the same URL in their bio was linked.
     * format_text() supplies the 'originalformat' option filter_urltolink
     * requires before it will act.
     *
     * @return void
     */
    public function test_custom_profile_field_url_is_filtered(): void {
        $this->resetAfterTest();
        global $DB;

        // The filter has to be on for this to be a meaningful assertion —
        // otherwise the test would pass against a site where nothing is
        // filtered and prove nothing.
        filter_set_global_state('urltolink', TEXTFILTER_ON);

        $field = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'homepage',
            'name' => 'Homepage',
            'visible' => PROFILE_VISIBLE_ALL,
        ]);

        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Ursula', 'lastname' => 'Link',
            'profile_field_homepage' => 'https://example.org/teaching',
        ]);
        profile_manager::get_or_create_for_user((int) $user->id);
        profile_manager::save((int) $user->id, ['slug' => 'ursulalink', 'bio' => '',
            'expertise' => [], 'visible' => true]);

        $this->add_class_routes_to_route_loader(profile_controller::class, '');
        $response = $this->process_request('GET', 'u/ursulalink', route_loader_interface::ROUTE_GROUP_PAGE);
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('https://example.org/teaching', $body);
        $this->assertStringContainsString(
            '<a href="https://example.org/teaching"',
            $body,
            'A URL in a public custom profile field was not turned into a link.'
        );
    }

    /**
     * A javascript: URL in a profile field is still neutralised.
     *
     * Guards the swap from clean_text() to format_text(): format_text() only
     * cleans while the text is untrusted, so this asserts the existing XSS
     * protection survived the change rather than assuming it did.
     *
     * @return void
     */
    public function test_custom_profile_field_javascript_url_is_cleaned(): void {
        $this->resetAfterTest();

        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'evil',
            'name' => 'Evil',
            'visible' => PROFILE_VISIBLE_ALL,
        ]);

        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Eve', 'lastname' => 'Ill',
            'profile_field_evil' => '<a href="javascript:alert(1)">click</a>',
        ]);
        profile_manager::get_or_create_for_user((int) $user->id);
        profile_manager::save((int) $user->id, ['slug' => 'eveill', 'bio' => '',
            'expertise' => [], 'visible' => true]);

        $this->add_class_routes_to_route_loader(profile_controller::class, '');
        $response = $this->process_request('GET', 'u/eveill', route_loader_interface::ROUTE_GROUP_PAGE);
        $body = (string) $response->getBody();

        $this->assertStringNotContainsString('javascript:', $body);
    }

    /**
     * Starred resources appear under "Liked resources" on the profile.
     *
     * @return void
     */
    public function test_liked_resources_section_lists_starred_resources(): void {
        $this->resetAfterTest();

        $creator = $this->getDataGenerator()->create_user(['firstname' => 'Star', 'lastname' => 'Rer']);
        profile_manager::get_or_create_for_user((int) $creator->id);
        profile_manager::save((int) $creator->id, ['slug' => 'starrer', 'bio' => '',
            'expertise' => [], 'visible' => true]);

        // The section only shows on a profile that has shares of its own.
        $this->make_profile_resource($creator, ['title' => 'Their own share']);

        $someoneelse = $this->getDataGenerator()->create_user();
        $liked = $this->make_profile_resource($someoneelse, ['title' => 'Somebody elses course']);
        \local_oerexchange\local\star_manager::set_starred((int) $liked, (int) $creator->id, true);

        $this->add_class_routes_to_route_loader(profile_controller::class, '');
        $response = $this->process_request('GET', 'u/starrer', route_loader_interface::ROUTE_GROUP_PAGE);
        $body = (string) $response->getBody();

        $this->assertStringContainsString(get_string('profilelikedheading', 'local_oerexchange'), $body);
        $this->assertStringContainsString('Somebody elses course', $body);
    }

    /**
     * A starred resource that is no longer published is not advertised.
     *
     * The profile is world-readable, so somebody else's bookmark must not
     * become a way to see that a hidden resource exists.
     *
     * @return void
     */
    public function test_liked_resources_omits_unpublished_resources(): void {
        $this->resetAfterTest();

        $creator = $this->getDataGenerator()->create_user(['firstname' => 'Hid', 'lastname' => 'Den']);
        profile_manager::get_or_create_for_user((int) $creator->id);
        profile_manager::save((int) $creator->id, ['slug' => 'hidden-liker', 'bio' => '',
            'expertise' => [], 'visible' => true]);

        $this->make_profile_resource($creator, ['title' => 'Their own share']);

        $someoneelse = $this->getDataGenerator()->create_user();
        $liked = $this->make_profile_resource($someoneelse, [
            'title' => 'Taken down elsewhere',
            'status' => 'modhidden',
        ]);
        \local_oerexchange\local\star_manager::set_starred((int) $liked, (int) $creator->id, true);

        $this->add_class_routes_to_route_loader(profile_controller::class, '');
        $response = $this->process_request('GET', 'u/hidden-liker', route_loader_interface::ROUTE_GROUP_PAGE);
        $body = (string) $response->getBody();

        $this->assertStringNotContainsString('Taken down elsewhere', $body);
    }

    /**
     * Insert one catalogue row attributed to a user.
     *
     * Direct insert rather than resource_manager::publish(), which needs a
     * real .mbz in a draft area that none of these rendering tests are about.
     *
     * @param \stdClass $creator the user the resource is attributed to
     * @param array $overrides column values to override on the row
     * @return int the new resource's id
     */
    protected function make_profile_resource(\stdClass $creator, array $overrides = []): int {
        global $DB;

        $now = time();

        return (int) $DB->insert_record('local_oerexchange_resources', (object) array_merge([
            'type' => 'course',
            'title' => 'A shared course',
            'summary' => '',
            'summaryformat' => FORMAT_HTML,
            'language' => '',
            'tags' => '',
            'licenseshortname' => 'cc-4.0',
            'activitytype' => null,
            'dataresourcetype' => null,
            'courseformat' => null,
            'creatorid' => $creator->id,
            'siteid' => null,
            'status' => 'published',
            'downloadcount' => 0,
            'importcount' => 0,
            'forkedfromid' => null,
            'trydisabled' => 0,
            'trydisabledreason' => null,
            'timeshared' => $now,
            'timemodified' => $now,
            'timefresh' => $now,
            'stalenotifiedtime' => 0,
            'modhiddentime' => 0,
            'modhiddenby' => 0,
            'modhiddenversionid' => null,
        ], $overrides));
    }
}
