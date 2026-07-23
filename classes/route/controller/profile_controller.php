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

use core\router\require_login;
use core\router\route;
use local_oerexchange\local\badge_manager;
use local_oerexchange\local\profile_manager;
use local_oerexchange\local\share_targets;
use local_oerexchange\router\parameters\path_profileslug;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Public educator-profile page. Viewing is intentionally public (no
 * requirelogin) — matches this plugin's existing pattern of public
 * catalogue browsing (resource.php:26-27, index.php docblock).
 *
 * The #[route(path: '/u/{slug}')] attribute below is relative to this
 * plugin's component path, NOT the real, resolvable URL. Moodle's router
 * only strips the component prefix for `core` components
 * (core\router\util::normalise_component_path()); for a `local_oerexchange`
 * route it is left in place, so the real, working request path is
 * /local_oerexchange/u/{slug} (confirmed against
 * lib/classes/router/abstract_route_loader.php:112, which compiles every
 * standard route's pattern as "/{$componentpath}{$path}"). A bare /u/{slug}
 * 404s. Anything this controller generates for external use (its own
 * $PAGE->url, the share-button link) must use the real path — see view().
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profile_controller {
    use \core\router\route_controller;

    /**
     * Render the public profile page for a slug, or a 404 when the slug
     * doesn't resolve or the profile is hidden. Those two cases are
     * deliberately indistinguishable (design doc: "no distinction leaked") —
     * both take the same page_not_found() path with no differing message.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param string $slug
     * @return ResponseInterface
     */
    #[route(
        path: '/u/{slug}',
        method: 'GET',
        pathtypes: [
            new path_profileslug(),
        ],
        requirelogin: new require_login(requirelogin: false),
    )]
    public function view(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $slug,
    ): ResponseInterface {
        global $DB, $OUTPUT, $PAGE, $USER;

        $profile = profile_manager::get_by_slug($slug);
        if (!$profile || !$profile->visible) {
            return $this->page_not_found($request, $response);
        }

        $user = $DB->get_record('user', ['id' => $profile->userid, 'deleted' => 0]);
        if (!$user) {
            // A profile row can outlive its user record only in the narrow
            // window of an in-flight account deletion; treat it the same as
            // "no such profile" rather than exposing that timing detail.
            return $this->page_not_found($request, $response);
        }

        $metrics = profile_manager::get_metrics($profile->userid);
        $badges = badge_manager::get_badges_for_user($profile->userid);
        $resources = $DB->get_records('local_oerexchange_resources', [
            'creatorid' => $profile->userid, 'status' => 'published',
        ], 'timeshared DESC');

        $fullname = fullname($user);
        // The #[route] attribute's path ('/u/{slug}') is component-relative,
        // not the real request path — see the class docblock. Build the
        // real, working URL directly with the component prefix so $PAGE->url,
        // the og:url tag (hook_callbacks::build_og_meta_html(), which must
        // stay consistent with this), and the share button all point
        // somewhere that actually resolves.
        //
        // Use moodle_url::routed_path() (lib/classes/url.php:673; also used
        // by admin/swaggerui.php), the documented core factory for a
        // self-referencing routed-controller URL — NOT a plain
        // `new moodle_url(...)`. routed_path() always produces a working
        // link: it prepends '/r.php/' whenever $CFG->routerconfigured isn't
        // confirmed true, and leaves the clean form otherwise. A plain
        // moodle_url() only happens to resolve on this dev VM because of a
        // site-specific nginx catch-all rule; on a real production site
        // where routerconfigured is genuinely false, it would silently
        // 404. On THIS VM specifically, a documented environment bug makes
        // routerconfigured read false during a routed request's controller
        // execution even though config.php sets it true (see
        // dev-docs/harness/discoveries/
        // 2026-07-19-routerconfigured-inconsistent-during-routed-requests.md)
        // — so this link may render with a '/r.php/' prefix here. That is
        // expected and cosmetic, not a plugin defect; do not "simplify"
        // this back to a plain moodle_url().
        $profileurl = \moodle_url::routed_path('/local_oerexchange/u/' . $slug);

        $PAGE->set_url($profileurl);
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_pagelayout('standard');
        $PAGE->set_title($fullname);
        $PAGE->set_heading($fullname);

        $out = $OUTPUT->header();

        // Open Graph tags for rich link previews (design doc, "Pages &
        // flows") are emitted into the real <head> by
        // \local_oerexchange\hook_callbacks::before_standard_head_html_generation()
        // (db/hooks.php), a listener on the genuine Moodle <head>-injection
        // surface, \core\hook\output\before_standard_head_html_generation
        // (lib/classes/hook/output/before_standard_head_html_generation.php).
        // $OUTPUT->header() has already rendered and closed <head> by the
        // time it returns to this controller, so this method must not (and
        // no longer does) emit its own copy here — a second, <body>-placed
        // set of og:* tags would just conflict with the <head> ones.
        $out .= \html_writer::tag('div', $OUTPUT->user_picture($user, ['size' => 100, 'link' => false]), ['class' => 'mb-2']);
        $out .= \html_writer::tag('p', get_string('profileheading', 'local_oerexchange'), ['class' => 'text-muted small mb-1']);
        $out .= \html_writer::tag('h2', s($fullname));
        $out .= \html_writer::tag('p', $profile->bio !== ''
            ? format_text($profile->bio, FORMAT_PLAIN)
            : get_string('profilenobio', 'local_oerexchange'));

        $expertise = json_decode($profile->expertise ?: '[]', true) ?: [];
        if ($expertise) {
            $out .= \html_writer::start_tag('div', ['class' => 'mb-2']);
            foreach ($expertise as $tag) {
                $out .= \html_writer::tag('span', s($tag), ['class' => 'badge bg-secondary me-1']);
            }
            $out .= \html_writer::end_tag('div');
        }

        if ($badges) {
            $out .= \html_writer::start_tag('div', ['class' => 'mb-2']);
            foreach ($badges as $badgekey) {
                $out .= \html_writer::tag('span', get_string('badge_' . $badgekey, 'local_oerexchange'), [
                    'class' => 'badge bg-success me-1',
                ]);
            }
            $out .= \html_writer::end_tag('div');
        }

        $links = [];
        foreach (['orcidurl' => 'ORCID', 'linkedinurl' => 'LinkedIn', 'researchmapurl' => 'ResearchMap'] as $field => $label) {
            // Saving a profile does not itself restrict these to a safe
            // scheme (Task 2's profile_manager::save()); re-validate here
            // rather than trust stored data for something rendered as a
            // clickable href — clean_param() returns '' for anything that
            // isn't a well-formed http(s)/etc URL, ruling out a stored
            // javascript: URI.
            $url = clean_param($profile->{$field} ?? '', PARAM_URL);
            if ($url !== '') {
                $links[] = \html_writer::link($url, $label, ['class' => 'me-2']);
            }
        }
        if ($links) {
            $out .= \html_writer::tag('div', implode(' ', $links), ['class' => 'mb-2']);
        }

        $out .= \html_writer::tag('div', implode(' · ', array_filter([
            $metrics['membersince']
                ? get_string(
                    'profilemembersince',
                    'local_oerexchange',
                    userdate($metrics['membersince'], get_string('strftimedatemonthabbr', 'langconfig'))
                )
                : null,
            get_string('profileresourcecount', 'local_oerexchange', $metrics['resourcecount']),
            get_string('profiledownloadtotal', 'local_oerexchange', $metrics['downloadtotal']),
            $metrics['avgrating'] !== null
                ? get_string('profileavgrating', 'local_oerexchange', round($metrics['avgrating'], 1))
                : null,
        ])), ['class' => 'small text-muted mb-3']);

        // Owner-only "Edit profile" affordance (final whole-branch review
        // finding 3): before this fix, nothing anywhere linked to
        // /u/{slug}/edit — a user had to hand-type the URL. Gated on
        // isloggedin() && !isguestuser() first, matching this plugin's
        // established convention elsewhere on this page and on resource.php,
        // so an anonymous visitor's $USER->id (0) can never spuriously equal
        // a real profile's userid.
        if (isloggedin() && !isguestuser() && (int) $USER->id === (int) $profile->userid) {
            $editurl = \moodle_url::routed_path('/local_oerexchange/u/' . $profile->slug . '/edit');
            $out .= \html_writer::link(
                $editurl,
                get_string('profileeditlink', 'local_oerexchange'),
                ['class' => 'btn btn-outline-primary me-2', 'id' => 'oerexchange-profile-editlink']
            );
        }

        $out .= \html_writer::link(
            new \moodle_url('/message/index.php', ['id' => $profile->userid]),
            get_string('profilemessage', 'local_oerexchange'),
            ['class' => 'btn btn-outline-secondary me-2']
        );
        // Share affordance. This was originally a single button running an
        // inline js_init_code handler: navigator.share, else
        // navigator.clipboard.writeText, else nothing — with no feedback on
        // any path and an empty catch swallowing the clipboard rejection.
        // Reproduced in Chromium 2026-07-23: navigator.share is undefined on
        // desktop Linux, and the clipboard write then either succeeds
        // silently or is denied silently, so the button was indistinguishable
        // from dead. share_targets::render() replaces it with a disclosure
        // that always contains a selectable URL, gives visible feedback, and
        // offers whichever networks the admin enabled.
        $out .= share_targets::render(
            $profileurl->out(false),
            $fullname,
            get_string('profileshare', 'local_oerexchange')
        );

        $out .= $OUTPUT->heading(get_string('profileresourcesheading', 'local_oerexchange'), 4);
        if (empty($resources)) {
            $out .= \html_writer::tag('p', get_string('profilenoresources', 'local_oerexchange'));
        } else {
            // Cover-image thumbnail per card, using the exact same File API
            // read + make_pluginfile_url() pattern resource.php's own
            // detail-page display already uses for the identical
            // component=local_oerexchange/filearea=coverimage/itemid=resourceid
            // file (see resource.php's "Cover-image thumbnail" block) —
            // established, already-reviewed, not reinvented here (final
            // whole-branch review finding 4).
            //
            // resource.php has NO placeholder-icon fallback of its own: when
            // a resource has no cover file, it simply renders no <img> tag
            // at all (verified by reading resource.php in full, 2026-07-19 —
            // there is no icon/placeholder asset or CSS anywhere in this
            // plugin). Per this task's explicit "check this" instruction,
            // that means there is no existing placeholder infrastructure to
            // reuse, and adding new placeholder-icon infrastructure is out
            // of scope — so a card with no cover image likewise renders no
            // <img> tag, matching resource.php's real behaviour exactly
            // rather than inventing something new.
            $fs = get_file_storage();
            $out .= \html_writer::start_tag('div', ['class' => 'row row-cols-1 row-cols-md-3 g-3']);
            foreach ($resources as $r) {
                $rurl = new \moodle_url('/local/oerexchange/resource.php', ['id' => $r->id]);
                $out .= \html_writer::start_tag('div', ['class' => 'col']);
                $out .= \html_writer::start_tag('div', ['class' => 'card h-100']);

                $coverfiles = $fs->get_area_files(
                    \context_system::instance()->id,
                    'local_oerexchange',
                    'coverimage',
                    $r->id,
                    'id',
                    false
                );
                if ($coverfiles) {
                    $coverfile = reset($coverfiles);
                    $coverurl = \moodle_url::make_pluginfile_url(
                        $coverfile->get_contextid(),
                        'local_oerexchange',
                        'coverimage',
                        $r->id,
                        '/',
                        $coverfile->get_filename()
                    );
                    $out .= \html_writer::empty_tag('img', [
                        'src' => $coverurl->out(false),
                        'alt' => get_string('thumbnailalt', 'local_oerexchange', s($r->title)),
                        'class' => 'card-img-top',
                    ]);
                }

                $out .= \html_writer::start_tag('div', ['class' => 'card-body']);
                $typestring = $r->type === 'activity'
                    ? get_string('typeactivity', 'local_oerexchange')
                    : get_string('typecourse', 'local_oerexchange');
                $out .= \html_writer::tag('span', $typestring, ['class' => 'badge bg-secondary mb-1']);
                $out .= \html_writer::tag('h5', \html_writer::link($rurl, s($r->title)), ['class' => 'card-title']);
                $out .= \html_writer::tag(
                    'div',
                    get_string('downloadcountlabel', 'local_oerexchange', $r->downloadcount),
                    ['class' => 'small text-muted']
                );
                $out .= \html_writer::end_tag('div');
                $out .= \html_writer::end_tag('div');
                $out .= \html_writer::end_tag('div');
            }
            $out .= \html_writer::end_tag('div');
        }

        $out .= $OUTPUT->footer();

        $response->getBody()->write($out);
        return $response;
    }
}
