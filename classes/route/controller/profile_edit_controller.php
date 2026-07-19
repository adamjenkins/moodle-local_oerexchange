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

namespace local_oerexchange\route\controller;

use core\exception\access_denied_exception;
use core\router\require_login;
use core\router\route;
use local_oerexchange\local\profile_manager;
use local_oerexchange\router\parameters\path_profileslug;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Owner-only educator-profile edit page, GET+POST /u/{slug}/edit.
 *
 * The #[route(path: '/u/{slug}/edit')] attribute below is relative to this
 * plugin's component path, NOT the real, resolvable URL — same caveat as
 * profile_controller (see that class's docblock for the full explanation
 * and the abstract_route_loader.php:112 citation). The real, working
 * request path is /local_oerexchange/u/{slug}/edit. Anything this
 * controller generates for its own use ($PAGE->url, the form's action, the
 * post-save redirect) must use the real path built via
 * moodle_url::routed_path() — see edit().
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profile_edit_controller {
    use \core\router\route_controller;

    /**
     * Render (GET) or save (POST) the logged-in user's own profile.
     *
     * Only the profile's owner may reach this page (design: owner-only,
     * no moderator override — these are the owner's own editable fields,
     * not a moderation action, so there is deliberately no capability
     * check to bypass with). A non-owner is rejected with
     * access_denied_exception, which the router's error_handler
     * (lib/classes/router/error_handler.php) maps to a genuine HTTP 403 —
     * unlike profile_controller's public view, which deliberately makes a
     * hidden profile indistinguishable from a nonexistent one (design doc:
     * "no distinction leaked") to a stranger, this page is reached only by
     * an already-authenticated user attempting to act on an account that
     * isn't theirs; there is no need to hide whether the slug exists, and
     * a 403 is the standard, informative Moodle response for "you're
     * logged in, but not allowed to do that" (compare require_capability()
     * elsewhere in Moodle core).
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param string $slug
     * @return ResponseInterface
     */
    #[route(
        path: '/u/{slug}/edit',
        method: ['GET', 'POST'],
        pathtypes: [
            new path_profileslug(),
        ],
        requirelogin: new require_login(requirelogin: true),
    )]
    public function edit(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $slug,
    ): ResponseInterface {
        global $USER, $OUTPUT, $PAGE;

        $profile = profile_manager::get_by_slug($slug);
        if (!$profile) {
            return $this->page_not_found($request, $response);
        }
        if ((int) $profile->userid !== (int) $USER->id) {
            throw new access_denied_exception('error_notyourprofile', 'local_oerexchange');
        }

        if ($request->getMethod() === 'POST') {
            return $this->save($response, $profile);
        }

        return $this->render_form($response, $profile, $slug);
    }

    /**
     * Handle the POST branch: validate the session key, delegate persisting
     * the submitted fields to profile_manager::save() (Task 2's validation
     * and TOCTOU-safe slug handling is not re-implemented here), and
     * redirect to the (possibly new) slug's public profile page.
     *
     * Reads the submission via Moodle's standard optional_param()/
     * required_param() (i.e. the real $_POST superglobal), NOT
     * $request->getParsedBody(). This is deliberate, not an oversight:
     * this route declares no `requestbody:` schema on its #[route(...)]
     * attribute (form-urlencoded HTML posts don't fit that JSON-oriented
     * API, see core_user\route\api\preferences's the only other in-tree
     * example, all JSON), and
     * core\router\request_validator::validate_request_body()
     * (lib/classes/router/request_validator.php:171-178) unconditionally
     * replaces the request with `$request->withParsedBody([])` whenever
     * get_request_body() is null — i.e. getParsedBody() is guaranteed
     * empty here by the time this method runs, regardless of what was
     * actually posted. Verified empirically 2026-07-19 with a disposable
     * debug trace through the real request pipeline (r.php, every
     * lib/classes/router/middleware/*, this method — not committed): the
     * real $_POST superglobal carries the submitted data intact at every
     * one of those points (validate_request_body() only replaces the
     * PSR-7 request's parsedBody property, never touches $_POST itself),
     * while $request->getParsedBody() is already the wiped `[]` by the
     * time moodle_authentication_middleware/validation_middleware finish
     * and control reaches this controller. This also matches this
     * plugin's own established convention for every other POST-handling
     * script (resource.php, moderate.php, manage_sites.php,
     * manage_allowlist.php: optional_param()/required_param() +
     * confirm_sesskey()/require_sesskey()), so this method is not
     * introducing a new pattern, just extending the existing one into a
     * routed controller.
     *
     * @param ResponseInterface $response
     * @param \stdClass $profile the profile row being edited (pre-save)
     * @return ResponseInterface
     */
    protected function save(
        ResponseInterface $response,
        \stdClass $profile,
    ): ResponseInterface {
        global $USER;

        require_sesskey();

        // PARAM_RAW_TRIMMED, not PARAM_ALPHANUMEXT: an out-of-charset slug
        // must reach profile_manager::save()'s own is_valid_slug() check
        // and its error_invalidslug rejection unchanged, not get silently
        // stripped down to something that would then validate.
        $newslug = optional_param('slug', $profile->slug, PARAM_RAW_TRIMMED);
        $bio = optional_param('bio', '', PARAM_RAW_TRIMMED);
        $expertiseraw = optional_param('expertise', '', PARAM_RAW_TRIMMED);
        $expertise = array_values(array_filter(array_map(
            'trim',
            explode(',', $expertiseraw)
        ), fn (string $tag): bool => $tag !== ''));

        profile_manager::save((int) $USER->id, [
            'slug' => $newslug,
            'bio' => $bio,
            'expertise' => $expertise,
            'orcidurl' => optional_param('orcidurl', '', PARAM_URL),
            'linkedinurl' => optional_param('linkedinurl', '', PARAM_URL),
            'researchmapurl' => optional_param('researchmapurl', '', PARAM_URL),
            'visible' => (bool) optional_param('visible', 0, PARAM_BOOL),
        ]);

        // See this class's docblock: the real, resolvable URL needs the
        // component prefix and moodle_url::routed_path(), not a plain
        // moodle_url() — copied from profile_controller::view()'s
        // identical, previously-reviewed pattern.
        $profileurl = \moodle_url::routed_path('/local_oerexchange/u/' . $newslug);
        return static::redirect($response, $profileurl);
    }

    /**
     * Render the GET branch: the edit form pre-filled with the profile's
     * current values.
     *
     * @param ResponseInterface $response
     * @param \stdClass $profile
     * @param string $slug the slug the request arrived on (used for the
     *        form's own action URL; may differ from $profile->slug only in
     *        the impossible case where it wouldn't have resolved above)
     * @return ResponseInterface
     */
    protected function render_form(
        ResponseInterface $response,
        \stdClass $profile,
        string $slug,
    ): ResponseInterface {
        global $OUTPUT, $PAGE;

        // See this class's docblock and profile_controller::view()'s
        // identical, previously-reviewed comment for why routed_path() with
        // the component prefix is required here, not a plain moodle_url().
        $editurl = \moodle_url::routed_path('/local_oerexchange/u/' . $slug . '/edit');

        $PAGE->set_url($editurl);
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_pagelayout('standard');
        $PAGE->set_title(get_string('profileedittitle', 'local_oerexchange'));
        $PAGE->set_heading(get_string('profileedittitle', 'local_oerexchange'));

        $expertise = json_decode($profile->expertise ?: '[]', true) ?: [];

        $out = $OUTPUT->header();
        $out .= \html_writer::start_tag('form', [
            'method' => 'post',
            'action' => $editurl,
        ]);
        $out .= \html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey(),
        ]);

        $out .= \html_writer::tag('label', get_string('profileeditslug', 'local_oerexchange'), [
            'for' => 'oerexchange-profile-slug',
        ]);
        $out .= \html_writer::empty_tag('input', [
            'type' => 'text', 'name' => 'slug', 'id' => 'oerexchange-profile-slug',
            'value' => s($profile->slug), 'class' => 'form-control mb-2',
        ]);

        $out .= \html_writer::tag('label', get_string('profileeditbio', 'local_oerexchange'), [
            'for' => 'oerexchange-profile-bio',
        ]);
        $out .= \html_writer::tag('textarea', s($profile->bio), [
            'name' => 'bio', 'id' => 'oerexchange-profile-bio', 'class' => 'form-control mb-2',
        ]);

        $out .= \html_writer::tag('label', get_string('profileeditexpertise', 'local_oerexchange'), [
            'for' => 'oerexchange-profile-expertise',
        ]);
        $out .= \html_writer::empty_tag('input', [
            'type' => 'text', 'name' => 'expertise', 'id' => 'oerexchange-profile-expertise',
            'value' => s(implode(', ', $expertise)), 'class' => 'form-control mb-2',
        ]);

        $out .= \html_writer::tag('label', get_string('profileeditorcid', 'local_oerexchange'), [
            'for' => 'oerexchange-profile-orcid',
        ]);
        $out .= \html_writer::empty_tag('input', [
            'type' => 'url', 'name' => 'orcidurl', 'id' => 'oerexchange-profile-orcid',
            'value' => s($profile->orcidurl), 'class' => 'form-control mb-2',
        ]);

        $out .= \html_writer::tag('label', get_string('profileeditlinkedin', 'local_oerexchange'), [
            'for' => 'oerexchange-profile-linkedin',
        ]);
        $out .= \html_writer::empty_tag('input', [
            'type' => 'url', 'name' => 'linkedinurl', 'id' => 'oerexchange-profile-linkedin',
            'value' => s($profile->linkedinurl), 'class' => 'form-control mb-2',
        ]);

        $out .= \html_writer::tag('label', get_string('profileeditresearchmap', 'local_oerexchange'), [
            'for' => 'oerexchange-profile-researchmap',
        ]);
        $out .= \html_writer::empty_tag('input', [
            'type' => 'url', 'name' => 'researchmapurl', 'id' => 'oerexchange-profile-researchmap',
            'value' => s($profile->researchmapurl), 'class' => 'form-control mb-2',
        ]);

        $out .= \html_writer::start_tag('div', ['class' => 'form-check mb-3']);
        $out .= \html_writer::empty_tag('input', array_merge([
            'type' => 'checkbox', 'name' => 'visible', 'value' => '1', 'class' => 'form-check-input',
            'id' => 'oerexchange-profile-visible',
        ], $profile->visible ? ['checked' => 'checked'] : []));
        $out .= \html_writer::tag(
            'label',
            get_string('profileeditvisible', 'local_oerexchange'),
            ['for' => 'oerexchange-profile-visible', 'class' => 'form-check-label']
        );
        $out .= \html_writer::end_tag('div');

        $out .= \html_writer::empty_tag('input', [
            'type' => 'submit', 'value' => get_string('profileeditsave', 'local_oerexchange'),
            'class' => 'btn btn-primary',
        ]);
        $out .= \html_writer::end_tag('form');
        $out .= $OUTPUT->footer();

        $response->getBody()->write($out);
        return $response;
    }
}
