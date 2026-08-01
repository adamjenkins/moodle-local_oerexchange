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
        // Visitors see published resources only. The profile's owner also
        // sees their own hidden ones (flagged as such below) — otherwise
        // hiding a resource would remove the only listing that leads back to
        // the page which can unhide it.
        $isowner = isloggedin() && !isguestuser() && (int) $USER->id === (int) $profile->userid;
        $statuses = $isowner ? ['published', 'hidden'] : ['published'];
        [$insql, $inparams] = $DB->get_in_or_equal($statuses, SQL_PARAMS_NAMED, 'status');
        $resources = $DB->get_records_select(
            'local_oerexchange_resources',
            'creatorid = :creatorid AND status ' . $insql,
            array_merge(['creatorid' => $profile->userid], $inparams),
            'timeshared DESC'
        );

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
        // FORMAT_MOODLE, not the FORMAT_PLAIN this used to pass. FORMAT_PLAIN
        // runs no filters at all (it only escapes and nl2br's), so a URL in a
        // bio never became a link and a multilang span rendered as literal
        // markup. FORMAT_MOODLE is the format that actually matches how the
        // data is captured: the bio comes from a plain <textarea name="bio">
        // (profile_edit_controller::render_form()) read as
        // optional_param('bio', '', PARAM_RAW_TRIMMED)
        // (profile_edit_controller::save()) and written to the column
        // verbatim by profile_manager::save() — no editor, no HTML, just raw
        // text with real newlines. That is exactly FORMAT_MOODLE's input
        // contract (newlines converted to <br />, filters applied), whereas
        // FORMAT_HTML would leave the newlines as insignificant whitespace.
        // Cleaning stays on deliberately: no 'noclean' option is passed, so
        // format_text() still runs clean_text() over this
        // user-supplied-and-never-HTML-validated string.
        //
        // 'para' => false because this is already being placed inside a <p>:
        // format_text()'s default para=true wraps FORMAT_MOODLE output in a
        // <div class="text_to_html"> (weblib.php:482-483), which inside a <p>
        // is invalid nesting that browsers silently repair by closing the <p>
        // early. FORMAT_PLAIN never emitted that wrapper, so this only became
        // a problem with the format change. Newlines are still converted —
        // that is the 'newlines' option, left at its default. Core's own
        // profile_field_base::display_data() sets para = false for exactly
        // the same reason (user/profile/lib.php:134-135).
        $out .= \html_writer::tag('p', $profile->bio !== ''
            ? format_text($profile->bio, FORMAT_MOODLE, [
                'context' => \context_system::instance(),
                'para' => false,
            ])
            : get_string('profilenobio', 'local_oerexchange'));

        $expertise = json_decode($profile->expertise ?: '[]', true) ?: [];
        if ($expertise) {
            $out .= \html_writer::start_tag('div', ['class' => 'mb-2']);
            foreach ($expertise as $tag) {
                // Author-authored display text, so it is a filter sink like
                // the resource titles below: s() would render a multilang-
                // marked-up subject tag as visible literal <span> markup.
                // format_string() escapes internally — no s() as well.
                $out .= \html_writer::tag(
                    'span',
                    format_string($tag, true, ['context' => \context_system::instance()]),
                    ['class' => 'badge bg-secondary me-1']
                );
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

        // This position used to hold three hardcoded portfolio links
        // (ORCID/LinkedIn/ResearchMap), whose columns are now dropped from
        // the schema entirely (db/upgrade.php's 2026080101 step). It now
        // shows the user's own additional user profile fields — the site's
        // custom user profile fields (admin page /user/profile/index.php) —
        // which lets the admin decide what an educator can publish here
        // instead of this plugin hardcoding three particular networks.
        $customfields = self::get_public_custom_fields((int) $profile->userid);
        if ($customfields) {
            $out .= \html_writer::tag(
                'h3',
                get_string('profilefieldsheading', 'local_oerexchange'),
                ['class' => 'h5 mt-3']
            );
            $out .= \html_writer::start_tag('dl', ['class' => 'row mb-2']);
            foreach ($customfields as $customfield) {
                // Both halves arrive already-safe from core (see
                // get_public_custom_fields()'s docblock), so neither is put
                // through s() here — that would double-escape them.
                $out .= \html_writer::tag('dt', $customfield['name'], ['class' => 'col-sm-3']);
                $out .= \html_writer::tag('dd', $customfield['value'], ['class' => 'col-sm-9']);
            }
            $out .= \html_writer::end_tag('dl');
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
                        // Filter, then decode back to plain text, and NO s().
                        // The old s($r->title) was a double-escape: this value
                        // ends up as an attribute, and html_writer::attribute()
                        // already runs s() over every value it writes
                        // (lib/classes/output/html_writer.php:113), so a
                        // multilang title came out as visible, doubly-escaped
                        // "&amp;lt;span lang=..." markup.
                        //
                        // format_string(..., 'escape' => false) is the obvious
                        // attribute-context form and is what core uses for
                        // group names (weblib.php), but it is not sufficient
                        // here: it suppresses format_string's own ampersand
                        // escaping and nothing else, so clean_text()/
                        // HTMLPurifier still encodes a bare '&', and a title
                        // stored with pre-encoded entities is untouched by it
                        // either way. Both cases then get escaped a second
                        // time by html_writer and render as a literal
                        // "&amp;". html_entity_decode() after filtering is
                        // correct for both, and matches the idiom
                        // block_oerexchangequicklinks already uses for its
                        // aria-labels.
                        'alt' => get_string('thumbnailalt', 'local_oerexchange', html_entity_decode(
                            format_string($r->title, true, ['context' => \context_system::instance()]),
                            ENT_QUOTES,
                            'UTF-8'
                        )),
                        'class' => 'card-img-top',
                    ]);
                }

                $out .= \html_writer::start_tag('div', ['class' => 'card-body']);
                $typestring = $r->type === 'activity'
                    ? get_string('typeactivity', 'local_oerexchange')
                    : get_string('typecourse', 'local_oerexchange');
                $out .= \html_writer::tag('span', $typestring, ['class' => 'badge bg-secondary mb-1']);
                if ($r->status === 'hidden') {
                    // Only ever reachable by the profile's owner (see the
                    // status filter above), so this doubles as the marker
                    // telling them why a resource is missing from the
                    // public catalogue.
                    $out .= \html_writer::tag(
                        'span',
                        get_string('resourcestatus_hidden', 'local_oerexchange'),
                        ['class' => 'badge bg-warning text-dark mb-1 ms-1']
                    );
                }
                // Uses format_string(), not s(): a multilang span in a title must
                // collapse to one language rather than show as literal
                // markup, matching the title sink index.php:194 and
                // resource.php already use. It is not additionally wrapped in
                // s() — format_string() escapes bare ampersands and runs
                // clean_text() itself (lib/classes/formatting.php:105-128),
                // so an s() around it would double-escape.
                $title = format_string($r->title, true, ['context' => \context_system::instance()]);
                $out .= \html_writer::tag('h5', \html_writer::link($rurl, $title), ['class' => 'card-title']);
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

    /**
     * The user's custom user profile fields (user_info_field, admin page
     * /user/profile/index.php) that this deliberately-public page may show,
     * flattened to a name/value list in the admin's configured field order.
     *
     * Core API used, all verified by reading
     * /srv/lms/moodle/public/user/profile/lib.php on 2026-08-01:
     *
     * - profile_get_user_fields_with_data(int $userid): profile_field_base[]
     *   (lib.php:640) returns one field object per user_info_field row —
     *   every field, with this user's data attached, already ordered
     *   `uic.sortorder ASC, uif.sortorder ASC` (lib.php:652), i.e. the
     *   admin's configured order. It lives in a legacy lib, not an
     *   autoloaded class, hence the require_once below.
     * - profile_field_base::$field->visible (lib.php:69, a public stdClass)
     *   holds the raw visibility, compared against PROFILE_VISIBLE_ALL
     *   (lib.php:37, '2').
     * - profile_field_base::is_empty() (lib.php:534) is core's own
     *   definition of "no data": empty, except the string '0' which counts
     *   as data (so an explicitly-unchecked checkbox still renders "No",
     *   exactly as core's own profile page shows it).
     * - display_name() (lib.php:145) returns
     *   format_string($this->field->name, true, [context system, escape]) —
     *   i.e. filtered (a multilang span in a field name collapses to one
     *   language) and already HTML-safe. Must NOT be re-escaped.
     * - display_data() (lib.php:133) returns each field type's own rendering
     *   and is likewise already HTML — the base class returns
     *   format_text($this->data, FORMAT_MOODLE); text fields return
     *   format_string() plus, when param4 is set, a built <a href> using the
     *   configured link format (field/text/field.class.php:36-56); checkbox
     *   returns get_string('yes'/'no') (field/checkbox/field.class.php:72);
     *   datetime returns userdate() (field/datetime/field.class.php:102).
     *   Also must NOT be re-escaped.
     *
     * This is the same trio core itself uses to render custom fields for a
     * viewer, in user_get_user_details()
     * (/srv/lms/moodle/public/user/lib.php:417-430: show_field_content()
     * gate, then display_name() + display_data()).
     *
     * Two deliberate divergences from that core snippet:
     *
     * 1. The visibility test is an explicit `=== PROFILE_VISIBLE_ALL`, not
     *    core's show_field_content()/is_visible(). is_visible() (lib.php:450)
     *    also passes a PROFILE_VISIBLE_PRIVATE or _TEACHERS field when the
     *    *viewer* is the field's owner or holds moodle/user:viewalldetails —
     *    which on this page would mean the owner previewing their own public
     *    profile sees fields no member of the public can see, i.e. the page
     *    would actively mislead them about what they are publishing. Only
     *    "Visible to everyone" is genuinely publishable here.
     * 2. No isloggedin() gate and no $CFG->forceloginforprofiles check
     *    (core's /user/profile.php:49 consults the latter). This page is
     *    deliberately public — see the class docblock — and the project
     *    owner's decision is that "Visible to everyone" is to be read
     *    literally, anonymous visitors included. Note this needs no
     *    capability check to be safe: for PROFILE_VISIBLE_ALL, core's own
     *    is_visible() returns true unconditionally (lib.php:472-473).
     *
     * @param int $userid
     * @return array<int, array{name: string, value: string}> already-safe HTML, in admin-configured order
     */
    protected static function get_public_custom_fields(int $userid): array {
        global $CFG;

        require_once($CFG->dirroot . '/user/profile/lib.php');

        $fields = [];
        foreach (profile_get_user_fields_with_data($userid) as $field) {
            if ((int) $field->field->visible !== (int) PROFILE_VISIBLE_ALL) {
                continue;
            }
            if ($field->is_empty()) {
                continue;
            }
            $fields[] = [
                'name' => $field->display_name(),
                // Core's display_data() is safe for five of its six field
                // types, but NOT universally: profile_field_social's version
                // (user/profile/field/social/field.class.php) substitutes the
                // raw stored value into a template that for the 'url' network
                // is `<a href="%%PLAIN%%">%%PLAIN%%</a>`
                // (field/social/classes/helper.php) — no escaping at all. The
                // edit form's PARAM_URL is the only guard, and it is bypassed
                // by every non-form writer: core_user_create_users /
                // update_users declare customfields[].value as PARAM_RAW
                // (user/externallib.php) and hand it to profile_save_data(),
                // as do uploaduser and the LDAP/OAuth2 sync paths.
                //
                // Core lives with that because /user/profile.php honours
                // $CFG->forceloginforprofiles; this page is deliberately
                // public, so the same stored value would be published to the
                // anonymous internet. clean_text() closes it for every field
                // type, including third-party ones this plugin has never
                // seen, without dropping any: verified against the live
                // bootstrap that it strips a javascript: href and an injected
                // onmouseover while leaving a legitimate <a href="https://…">,
                // a checkbox's "Yes" and a formatted date untouched. It is
                // idempotent on the already-safe types.
                'value' => clean_text($field->display_data(), FORMAT_HTML),
            ];
        }

        return $fields;
    }
}
