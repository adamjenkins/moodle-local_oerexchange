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

/**
 * Resource detail page: structure preview, license, plugin-dependency
 * disclosure, Try it / Download / Report, adaptation-story reviews.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Viewing is intentionally public; require_login() is only called below,
// inside the report/review submission branches.
require(__DIR__ . '/../../config.php'); // phpcs:ignore moodle.Files.RequireLogin.Missing
// Filelib.php's free functions (file_get_submitted_draft_itemid(),
// file_prepare_draft_area(), file_save_draft_area_files() below) are NOT
// pulled in by setup.php's default require chain in this Moodle version —
// confirmed live 2026-07-19: function_exists('file_save_draft_area_files')
// is false immediately after a bare config.php bootstrap, and calling it
// unguarded intermittently fatals with "Call to undefined function"
// depending on whether some unrelated code path on a given request happened
// to load filelib.php as a side effect (e.g. formslib.php does, if a
// moodleform is instantiated elsewhere first). Every core script that
// calls these functions directly (not via a moodleform) requires this file
// explicitly — e.g. repository/draftfiles_ajax.php, blog/locallib.php.
require_once($CFG->libdir . '/filelib.php');

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$resource = $DB->get_record('local_oerexchange_resources', ['id' => $id], '*', MUST_EXIST);

$PAGE->set_url('/local/oerexchange/resource.php', ['id' => $id]);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title($resource->title);
$PAGE->set_heading($resource->title);

if ($resource->status === 'deleted') {
    // A dedicated, friendly tombstone — NOT the generic error_notfound
    // exception below, and NOT gated behind the moderator capability (a
    // deleted resource's tombstone is meant to be visible to anyone who
    // follows an old link, per design: "sever the link, don't break it").
    // This is a deliberate improvement over how 'removed'/'hidden' render
    // today — those two are unchanged and still fall through to the
    // exception below for non-moderators.
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('resourcedeletedheading', 'local_oerexchange'));
    echo html_writer::tag('p', get_string('resourcedeletedbody', 'local_oerexchange'));
    echo $OUTPUT->footer();
    exit;
}

// Authors are included here, not just moderators: hiding your own resource
// must not lock you out of the only page that can unhide it. See
// resource_manager::user_can_view_resource().
if (!\local_oerexchange\local\resource_manager::user_can_view_resource($resource, (int) $USER->id)) {
    throw new moodle_exception('error_notfound', 'local_oerexchange');
}

// Handle report/review submission.
if ($action === 'report' && isloggedin() && !isguestuser()) {
    require_login();
    // Throw on a missing/expired sesskey rather than silently falling
    // through to page rendering and discarding the user's typed report.
    require_sesskey();
    $type = required_param('reporttype', PARAM_ALPHA);
    // Re-validate against the same set the <select> on this page actually
    // offers - PARAM_ALPHA alone accepts any alphabetic string, and an
    // unrecognised value would later break moderate.php (it feeds
    // get_string('reporttype_' . $rep->type, ...), which has no fallback
    // for a value outside {copyright,quality,spam,other}), denying admins
    // the moderation queue over a single bad report row.
    $allowedreporttypes = ['copyright', 'quality', 'spam', 'other'];
    if (!in_array($type, $allowedreporttypes, true)) {
        throw new moodle_exception('error_invalidreporttype', 'local_oerexchange');
    }
    $details = required_param('reportdetails', PARAM_TEXT);
    $DB->insert_record('local_oerexchange_reports', (object) [
        'resourceid' => $resource->id,
        'userid' => $USER->id,
        'type' => $type,
        'details' => $details,
        'status' => 'open',
        'resolvernote' => null,
        'timecreated' => time(),
        'timeresolved' => null,
    ]);
    \core\notification::success(get_string('reportsubmitted', 'local_oerexchange'));
    redirect(new moodle_url('/local/oerexchange/resource.php', ['id' => $id]));
} else if ($action === 'review' && isloggedin() && !isguestuser()) {
    require_login();
    // Same rationale as the report branch above.
    require_sesskey();
    $DB->insert_record('local_oerexchange_reviews', (object) [
        'resourceid' => $resource->id,
        'userid' => $USER->id,
        'contexttext' => required_param('reviewcontext', PARAM_TEXT),
        'adaptationtext' => required_param('reviewadaptation', PARAM_TEXT),
        'outcometext' => required_param('reviewoutcome', PARAM_TEXT),
        'rating' => optional_param('reviewrating', null, PARAM_INT),
        'status' => 'visible',
        'timecreated' => time(),
    ]);
    $creator = $DB->get_record('user', ['id' => $resource->creatorid, 'deleted' => 0]);
    if ($creator && $creator->id !== $USER->id) {
        $message = new \core\message\message();
        $message->component = 'local_oerexchange';
        $message->name = 'review';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $creator;
        $message->subject = get_string('notifyreviewsubject', 'local_oerexchange', $resource->title);
        $message->fullmessage = get_string('notifyreviewbody', 'local_oerexchange', $resource->title);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $message->subject;
        $message->notification = 1;
        $message->contexturl = (new moodle_url('/local/oerexchange/resource.php', ['id' => $resource->id]))->out(false);
        $message->contexturlname = $resource->title;
        message_send($message);
    }
    \core\notification::success(get_string('reviewsubmitted', 'local_oerexchange'));
    redirect(new moodle_url('/local/oerexchange/resource.php', ['id' => $id]));
} else if ($action === 'star' && isloggedin() && !isguestuser()) {
    // The no-JavaScript path for the star button. The AMD module intercepts
    // the click and calls the web service instead, so this only runs when
    // scripting is off — but it must exist, because a star is a plain link
    // and a plain link has to go somewhere.
    require_login();
    require_sesskey();
    // Starring is not editing: any logged-in user who may SEE a resource may
    // star it, which is the gate applied here (and again in the web service).
    if (!\local_oerexchange\local\resource_manager::user_can_view_resource($resource, (int) $USER->id)) {
        throw new moodle_exception('error_notfound', 'local_oerexchange');
    }
    $wanted = !\local_oerexchange\local\star_manager::is_starred((int) $resource->id, (int) $USER->id);
    \local_oerexchange\local\star_manager::set_starred((int) $resource->id, (int) $USER->id, $wanted);
    redirect(new moodle_url('/local/oerexchange/resource.php', ['id' => $id]));
} else if (
    in_array(
        $action,
        ['hide', 'unhide', 'deleteconfirm', 'settrydisabled', 'stillfresh', 'addcoauthor', 'removecoauthor'],
        true
    )
    && isloggedin() && !isguestuser()
) {
    require_login();
    require_sesskey();
    // Same ownership/moderator gate the thumbnail editor and the owner
    // controls below use — never a second, subtly different copy of it.
    if (!\local_oerexchange\local\resource_manager::user_can_edit_resource($resource, (int) $USER->id)) {
        throw new moodle_exception('error_notyourresource', 'local_oerexchange');
    }

    if ($action === 'deleteconfirm') {
        // Deleting is the one author-side action that is NOT available while a
        // moderator is holding the resource — it would take the open reports
        // and the takedown record with it. See
        // resource_manager::user_can_delete_resource().
        if (!\local_oerexchange\local\resource_manager::user_can_delete_resource($resource, (int) $USER->id)) {
            throw new moodle_exception('error_deleteundermoderation', 'local_oerexchange');
        }

        // Tombstone rather than a row delete: files, versions, reviews and
        // reports go, the row stays with status 'deleted' so old links (a
        // client site's "View on Exchange" button, a shared social link)
        // land on resource.php's friendly tombstone instead of breaking.
        // Reuses the exact routine the GDPR full-deletion path already uses
        // so there is only ever one definition of what deletion means here.
        \local_oerexchange\local\profile_manager::delete_creator_resource($resource);

        // That routine rolls its transaction back and swallows the exception
        // on failure, which is right for a bulk GDPR sweep but would leave an
        // author staring at a success message on a resource that is still
        // there. Confirm the outcome before claiming it happened.
        $after = $DB->get_record('local_oerexchange_resources', ['id' => $id], 'status', MUST_EXIST);
        if ($after->status !== 'deleted') {
            throw new moodle_exception('error_deletefailed', 'local_oerexchange');
        }
        \core\notification::success(get_string('resourcedeleted', 'local_oerexchange'));
        redirect(new moodle_url('/local/oerexchange/index.php'));
    }

    if ($action === 'stillfresh') {
        // The one-click answer to the abandoned-courseware warning: wind the
        // freshness clock forward and cancel the pending removal.
        \local_oerexchange\local\stale_manager::mark_fresh($resource);
        \core\notification::success(get_string('stillfreshsaved', 'local_oerexchange'));
        redirect(new moodle_url('/local/oerexchange/resource.php', ['id' => $id]));
    }

    if ($action === 'addcoauthor') {
        // PARAM_RAW_TRIMMED, not PARAM_TEXT: this is matched against
        // user.username / user.email in a parameterized query and is never
        // echoed back, so stripping characters out of it would only break
        // legitimate addresses. The success notice names the resolved user,
        // not the string that was typed.
        $identifier = required_param('coauthoridentifier', PARAM_RAW_TRIMMED);
        try {
            $added = \local_oerexchange\local\coauthor_manager::add($resource, $identifier, (int) $USER->id);
            \local_oerexchange\local\coauthor_manager::notify_added($resource, $added, $USER);
            \core\notification::success(get_string('coauthoradded', 'local_oerexchange', fullname($added)));
        } catch (moodle_exception $e) {
            // A typo in a username is an ordinary mistake, not an exceptional
            // one — send them back to the form with the reason rather than to
            // a full-page error they have to navigate away from.
            \core\notification::error($e->getMessage());
        }
        redirect(new moodle_url('/local/oerexchange/resource.php', ['id' => $id]));
    }

    if ($action === 'removecoauthor') {
        $coauthorid = required_param('coauthorid', PARAM_INT);
        if (\local_oerexchange\local\coauthor_manager::remove((int) $resource->id, $coauthorid)) {
            \core\notification::success(get_string('coauthorremoved', 'local_oerexchange'));
        } else {
            // Already gone — a double-click, or two authors removing the same
            // person at once. Nothing is wrong, so this is not an error.
            \core\notification::warning(get_string('coauthornotpresent', 'local_oerexchange'));
        }
        redirect(new moodle_url('/local/oerexchange/resource.php', ['id' => $id]));
    }

    if ($action === 'settrydisabled') {
        // An unchecked checkbox posts nothing at all, so this is the "off"
        // case as well as the "on" one.
        $trydisabled = optional_param('trydisabled', 0, PARAM_BOOL) ? 1 : 0;
        // PARAM_TEXT, not PARAM_RAW: this is rendered back into the page for
        // every visitor. PARAM_TEXT strips every tag except the ones the
        // multilang filter needs (lib/classes/param.php, clean_param_value_text()),
        // so the display side runs it through format_string() rather than s()
        // — s() would escape the multilang span an author deliberately typed
        // into literal markup.
        $reason = trim(optional_param('trydisabledreason', '', PARAM_TEXT));

        $DB->update_record('local_oerexchange_resources', (object) [
            'id' => $resource->id,
            'trydisabled' => $trydisabled,
            'trydisabledreason' => $reason !== '' ? \core_text::substr($reason, 0, 255) : null,
            'timemodified' => time(),
        ]);
        \core\notification::success(get_string('trydisabledsaved', 'local_oerexchange'));
        redirect(new moodle_url('/local/oerexchange/resource.php', ['id' => $id]));
    }

    $hide = ($action === 'hide');
    if (\local_oerexchange\local\resource_manager::set_hidden($resource, $hide)) {
        \core\notification::success(get_string($hide ? 'resourcehidden' : 'resourceunhidden', 'local_oerexchange'));
    } else {
        // For example "unhide" on a resource a moderator has 'removed', or
        // one still 'pending' its first structural validation.
        \core\notification::warning(get_string('error_statusnotflippable', 'local_oerexchange'));
    }
    redirect(new moodle_url('/local/oerexchange/resource.php', ['id' => $id]));
}

$version = null;
$latest = $DB->get_records(
    'local_oerexchange_versions',
    ['resourceid' => $resource->id, 'status' => 'ready'],
    'versionnumber DESC',
    '*',
    0,
    1
);
if ($latest) {
    $version = reset($latest);
}

$requiredplugins = $version && $version->requiredplugins ? json_decode($version->requiredplugins, true) : [];
$structure = $version && $version->structurejson ? json_decode($version->structurejson, true) : null;

$sandboxenabled = (bool) get_config('local_oerexchange', 'sandboxenabled') && get_config('local_oerexchange', 'sandboxbaseurl');

echo $OUTPUT->header();

// Deletion is irreversible for the author (the tombstone stays, but the
// files, versions, reviews and reports do not), so it gets a confirmation
// step of its own rather than a one-click link. The sesskey lives on the
// confirm button's URL, not this one — reaching this page does nothing.
if (
    $action === 'delete' && isloggedin() && !isguestuser()
    && \local_oerexchange\local\resource_manager::user_can_delete_resource($resource, (int) $USER->id)
) {
    echo $OUTPUT->confirm(
        // Core's confirm() puts the message through html_writer::tag('p', ...)
        // — content position, so it is NOT escaped for us
        // (lib/classes/output/core_renderer.php, confirm()). format_string()
        // escapes internally, so it is both the filter and the escape here.
        get_string(
            'resourcedeleteconfirm',
            'local_oerexchange',
            format_string($resource->title, true, ['context' => context_system::instance()])
        ),
        new moodle_url('/local/oerexchange/resource.php', [
            'id' => $id, 'action' => 'deleteconfirm', 'sesskey' => sesskey(),
        ]),
        new moodle_url('/local/oerexchange/resource.php', ['id' => $id])
    );
    echo $OUTPUT->footer();
    exit;
}

// Created-by attribution. $resource->creatorid is 0 for a
// tombstoned/anonymized resource, so the guard below correctly renders no
// line in that case: there's no attributable owner. The name itself always
// shows for any other creator (whether or not they've ever touched the
// profile system — get_by_userid() is a read-only lookup and deliberately
// never calls get_or_create_for_user(), so viewing this page must never
// silently create a profile row); it only becomes a link to their profile
// page when they have a profile row AND it is visible.
$creatoruser = $resource->creatorid
    ? $DB->get_record('user', ['id' => $resource->creatorid, 'deleted' => 0])
    : null;
if (!empty($creatoruser)) {
    $creatorprofile = \local_oerexchange\local\profile_manager::get_by_userid((int) $resource->creatorid);
    $creatorname = fullname($creatoruser);
    $creatorlabel = ($creatorprofile && $creatorprofile->visible)
        ? html_writer::link(\moodle_url::routed_path('/local_oerexchange/u/' . $creatorprofile->slug), s($creatorname))
        : s($creatorname);
    echo html_writer::tag('p', get_string('createdby', 'local_oerexchange', $creatorlabel));
}

// Co-authors, shown to everyone: they hold the same rights over the entry as
// the creator, so the public attribution should say so. Same profile-linking
// rule as the creator line above — a name always shows, and only becomes a
// link where that person has a visible profile.
$coauthorusers = \local_oerexchange\local\coauthor_manager::get_users((int) $resource->id);
if ($coauthorusers) {
    $coauthorlabels = [];
    foreach ($coauthorusers as $coauthoruser) {
        $coauthorprofile = \local_oerexchange\local\profile_manager::get_by_userid((int) $coauthoruser->id);
        $coauthorlabels[] = ($coauthorprofile && $coauthorprofile->visible)
            ? html_writer::link(
                \moodle_url::routed_path('/local_oerexchange/u/' . $coauthorprofile->slug),
                s(fullname($coauthoruser))
            )
            : s(fullname($coauthoruser));
    }
    echo html_writer::tag(
        'p',
        get_string('coauthorsline', 'local_oerexchange', implode(', ', $coauthorlabels))
    );
}

// Printed exactly as it was published. Showing it in capitals is presentation
// only — licence_display attaches a CSS class and styles.css does the
// upper-casing — so the text in the DOM stays the real identifier and an admin
// can switch the capitals off. The helper returns escaped HTML, which is what
// {$a} receives here.
echo html_writer::tag('p', get_string(
    'licenselabel',
    'local_oerexchange',
    \local_oerexchange\local\licence_display::html($resource->licenseshortname)
));
if ($resource->courseformat) {
    echo html_writer::tag(
        'p',
        get_string('courseformatlabel', 'local_oerexchange', s($resource->courseformat)),
        ['class' => 'small text-muted']
    );
}
if ($version && $version->moodleversion) {
    echo html_writer::tag(
        'p',
        get_string('moodleversionlabel', 'local_oerexchange', s($version->moodleversion)),
        ['class' => 'small text-muted']
    );
}
if ($version && $version->filesize) {
    // Shown to everyone, not just the author: how big the file is decides
    // whether downloading it is reasonable on this connection and whether a
    // sandbox trial will take minutes, and until now the only way to find out
    // was to start the download.
    echo html_writer::tag(
        'p',
        get_string(
            'filesizelabel',
            'local_oerexchange',
            \local_oerexchange\local\size_advice::format((int) $version->filesize)
        ),
        ['class' => 'small text-muted']
    );
}
if ($resource->forkedfromid) {
    $parent = $DB->get_record('local_oerexchange_resources', ['id' => $resource->forkedfromid]);
    if ($parent) {
        $purl = new moodle_url('/local/oerexchange/resource.php', ['id' => $parent->id]);
        echo html_writer::tag(
            'p',
            get_string(
                'attributionchain',
                'local_oerexchange',
                html_writer::link(
                    $purl,
                    format_string($parent->title, true, ['context' => context_system::instance()])
                )
            )
        );
    }
}

// The description carries its own format now that authors can write it in a
// rich-text editor. Existing rows were backfilled to FORMAT_HTML, which is
// what every sink assumed before the column existed.
echo html_writer::tag(
    'div',
    format_text(
        $resource->summary ?? '',
        (int) ($resource->summaryformat ?? FORMAT_HTML),
        ['context' => context_system::instance()]
    ),
    ['class' => 'mb-3']
);

// Cover-image thumbnail, extracted from a course backup's overviewfiles by
// parse_backup_task (Task 8) and stored under component=local_oerexchange,
// filearea=coverimage, itemid=resourceid, context_system::instance() —
// every activity-type resource, and a course backup with no course image,
// simply has no file here, so no thumbnail renders; that is a reasonable
// default, not an error state.
$fs = get_file_storage();
$coverfiles = $fs->get_area_files(
    \context_system::instance()->id,
    'local_oerexchange',
    'coverimage',
    $resource->id,
    'id',
    false
);
if ($coverfiles) {
    $coverfile = reset($coverfiles);
    $coverurl = \moodle_url::make_pluginfile_url(
        $coverfile->get_contextid(),
        'local_oerexchange',
        'coverimage',
        $resource->id,
        '/',
        $coverfile->get_filename()
    );
    echo html_writer::empty_tag('img', [
        'src' => $coverurl->out(false),
        // Html_writer escapes attribute values itself, so there is no s()
        // here — pre-escaping double-encoded any & or quotes in the title.
        // The title still has to go through the string filters, or a multilang
        // one shows both languages in the alt text. It must then be decoded
        // back to plain text before html_writer sees it: html_writer escapes
        // every attribute value with s() itself (html_writer::attribute()),
        // so handing it format_string()'s already-escaped output renders an
        // ampersand as the literal "&amp;". format_string(..., 'escape' =>
        // false) only half-fixes that — it suppresses format_string's OWN
        // ampersand escaping but not clean_text()/HTMLPurifier's, and it does
        // nothing at all for a value stored with pre-encoded entities. Proved
        // by the failing assertion in tests/multilang_rendering_test.php.
        // html_entity_decode() is correct for both, and is the idiom
        // block_oerexchangequicklinks already uses for its aria-labels.
        'alt' => get_string(
            'thumbnailalt',
            'local_oerexchange',
            html_entity_decode(
                format_string($resource->title, true, ['context' => context_system::instance()]),
                ENT_QUOTES,
                'UTF-8'
            )
        ),
        'class' => 'img-fluid mb-3', 'style' => 'max-height:200px;',
    ]);
}

// Thumbnail-replacement upload, shown only to the resource's creator or a
// moderator (same gate as the editthumbnail action handler above, now the
// exact same shared helper — final whole-branch review finding 5) — anyone
// else sees no form and posting the action directly is rejected there too,
// so this is a UI convenience, not the actual access-control boundary.
// Gated on isloggedin() && !isguestuser() first (matching the
// review/report forms elsewhere on this page): without it, an anonymous
// visitor's $USER->id (0) would spuriously equal a tombstoned resource's
// creatorid (also 0 - see the "Created by" comment above), showing this
// upload form to a guest.
$canedit = isloggedin() && !isguestuser()
    && \local_oerexchange\local\resource_manager::user_can_edit_resource($resource, (int) $USER->id);

// Owner controls: visibility and deletion. Same gate, same caveat — the real
// boundary is the action handler near the top of this file.
if ($canedit) {
    echo html_writer::start_tag('div', ['class' => 'card mb-3']);
    echo html_writer::start_tag('div', ['class' => 'card-body']);
    echo $OUTPUT->heading(get_string('ownercontrolsheading', 'local_oerexchange'), 5);

    // Say plainly what state the resource is in — an author who hides a
    // resource and comes back later should not have to guess why it is
    // missing from the catalogue.
    $statuskey = 'resourcestatus_' . $resource->status;
    echo html_writer::tag(
        'p',
        get_string_manager()->string_exists($statuskey, 'local_oerexchange')
            ? get_string($statuskey, 'local_oerexchange')
            : s($resource->status),
        ['class' => 'mb-2']
    );

    // Why an upload never appeared. Until now the parse failure was recorded
    // on the version row and rendered ONLY in moderate.php's failed-parses
    // list, so the person who actually made the mistake — the author or a
    // co-author — saw nothing but "Pending" and had no way to learn that
    // their backup had been refused, let alone why. That is the worst case
    // for the sanity check in particular: its message tells you exactly how
    // to fix the problem ("Re-export it with user data excluded"), and the
    // one person who could act on it was the one person who could not read
    // it.
    //
    // The newest version is used rather than only the pending case, so a
    // rejected "Replace the file" is reported too — there the resource stays
    // published on its previous file and the failure would otherwise be
    // completely silent.
    $newestversion = $DB->get_records(
        'local_oerexchange_versions',
        ['resourceid' => $resource->id],
        'versionnumber DESC, id DESC',
        '*',
        0,
        1
    );
    $newestversion = $newestversion ? reset($newestversion) : null;
    if ($newestversion && $newestversion->status === 'failed' && $newestversion->parseerror) {
        // Never the raw stored text: core exception messages carry absolute
        // server paths. See resource_manager::author_facing_parse_error().
        $authormessage = \local_oerexchange\local\resource_manager::author_facing_parse_error(
            $newestversion->parseerror
        );
        echo html_writer::tag(
            'div',
            html_writer::tag(
                'strong',
                get_string('uploadrejectedheading', 'local_oerexchange')
            )
                . html_writer::tag('div', s($authormessage), ['class' => 'mt-1']),
            ['class' => 'alert alert-danger py-2 px-3 mb-2']
        );
    }

    // While validation is still running, say so and keep saying so: a new
    // upload lands here 'pending' and only becomes a catalogue entry when
    // parse_backup_task runs on the next cron tick. Without this the author
    // saw a static "Pending" with no indication that anything was in flight,
    // no idea how long to wait, and no way to learn the outcome short of
    // reloading on a hunch. The poller replaces this region in place and
    // reloads once the answer is in.
    if ($newestversion && $newestversion->status === 'parsing') {
        echo html_writer::tag(
            'div',
            html_writer::tag('span', '', [
                'class' => 'spinner-border spinner-border-sm me-2',
                'aria-hidden' => 'true',
            ])
                . html_writer::tag('span', get_string('publishchecking', 'local_oerexchange')),
            [
                'class' => 'alert alert-info py-2 px-3 mb-2 d-flex align-items-center',
                // The status role and aria-live matter because the text is
                // replaced by JavaScript as the answer arrives: a screen-reader
                // user must hear the outcome without re-navigating here.
                'role' => 'status',
                'aria-live' => 'polite',
                'data-region' => 'oerexchange-publish-status',
            ]
        );
        $PAGE->requires->js_call_amd('local_oerexchange/publish_status', 'init', [(int) $resource->id]);
    }

    // The abandoned-courseware warning, mirrored from the notification so an
    // author who lands here (its link points at this page) sees the deadline
    // and the one-click way out side by side. Only while the flag is live —
    // resetting it (this button, an update) removes the banner too.
    if (
        (int) $resource->stalenotifiedtime > 0 && $resource->status === 'published'
            && \local_oerexchange\local\stale_manager::enabled()
    ) {
        echo html_writer::start_tag('div', ['class' => 'alert alert-warning']);
        echo html_writer::tag('p', get_string('stalebanner', 'local_oerexchange', (object) [
            'flagged' => userdate((int) $resource->stalenotifiedtime),
            'deadline' => userdate(\local_oerexchange\local\stale_manager::removal_deadline($resource)),
        ]));
        echo $OUTPUT->single_button(
            new moodle_url('/local/oerexchange/resource.php', [
                'id' => $id, 'action' => 'stillfresh', 'sesskey' => sesskey(),
            ]),
            get_string('stillfresh', 'local_oerexchange'),
            'post',
            ['type' => 'primary']
        );
        echo html_writer::end_tag('div');
    }

    if (in_array($resource->status, ['published', 'hidden'], true)) {
        $ishidden = ($resource->status === 'hidden');
        echo html_writer::link(
            new moodle_url('/local/oerexchange/resource.php', [
                'id' => $id,
                'action' => $ishidden ? 'unhide' : 'hide',
                'sesskey' => sesskey(),
            ]),
            get_string($ishidden ? 'resourceunhide' : 'resourcehide', 'local_oerexchange'),
            ['class' => 'btn btn-outline-secondary btn-sm me-2']
        );
    }

    // Edit the catalogue entry without touching the file — the counterpart of
    // "Replace the file" below. The thumbnail lives on that page too: it used
    // to be an inline multipart form here, which meant the one control that
    // changed how a resource looks sat apart from the ones that change what it
    // says.
    echo html_writer::link(
        new moodle_url('/local/oerexchange/edit_resource.php', ['id' => $id]),
        get_string('editresource', 'local_oerexchange'),
        ['class' => 'btn btn-outline-secondary btn-sm me-2']
    );

    // Replace the file without touching the catalogue entry. Reuses whichever
    // upload page already knows how to validate this resource's file type,
    // rather than adding another upload handler to this file.
    $replaceurl = new moodle_url(
        $resource->type === 'data'
            ? '/local/oerexchange/share_upload_data.php'
            : '/local/oerexchange/share_upload_mbz.php',
        ['resourceid' => $id]
    );
    echo html_writer::link(
        $replaceurl,
        get_string('replacefile', 'local_oerexchange'),
        ['class' => 'btn btn-outline-secondary btn-sm me-2']
    );

    // No Delete button while a moderator is holding this resource. Say why
    // rather than silently omitting it — a missing control reads as a broken
    // page, and the status line above already tells them a moderator acted.
    if (\local_oerexchange\local\resource_manager::user_can_delete_resource($resource, (int) $USER->id)) {
        echo html_writer::link(
            new moodle_url('/local/oerexchange/resource.php', ['id' => $id, 'action' => 'delete']),
            get_string('resourcedelete', 'local_oerexchange'),
            ['class' => 'btn btn-outline-danger btn-sm']
        );
    } else {
        echo html_writer::tag(
            'p',
            get_string('resourcedeleteundermoderation', 'local_oerexchange'),
            ['class' => 'small text-muted mt-2 mb-0']
        );
    }

    // Author opt-out of the sandbox. Only offered where a sandbox trial is
    // possible at all — a data resource can never be tried, so showing the
    // control there would imply a capability that doesn't exist.
    if ($resource->type !== 'data') {
        echo html_writer::start_tag('form', [
            'method' => 'post',
            'action' => new moodle_url('/local/oerexchange/resource.php', ['id' => $id]),
            'class' => 'mt-3',
        ]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'settrydisabled']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

        echo html_writer::start_tag('div', ['class' => 'form-check mb-2']);
        echo html_writer::empty_tag('input', array_merge([
            'type' => 'checkbox',
            'class' => 'form-check-input',
            'id' => 'oerexchange-trydisabled',
            'name' => 'trydisabled',
            'value' => '1',
        ], !empty($resource->trydisabled) ? ['checked' => 'checked'] : []));
        echo html_writer::tag('label', get_string('trydisabledlabel', 'local_oerexchange'), [
            'class' => 'form-check-label',
            'for' => 'oerexchange-trydisabled',
        ]);
        echo html_writer::end_tag('div');

        echo html_writer::tag('label', get_string('trydisabledreasonlabel', 'local_oerexchange'), [
            'class' => 'form-label small',
            'for' => 'oerexchange-trydisabledreason',
        ]);
        echo html_writer::empty_tag('input', [
            'type' => 'text',
            'class' => 'form-control form-control-sm mb-2',
            'id' => 'oerexchange-trydisabledreason',
            'name' => 'trydisabledreason',
            'maxlength' => 255,
            'value' => (string) $resource->trydisabledreason,
            'placeholder' => get_string('trydisabledreasonplaceholder', 'local_oerexchange'),
        ]);
        echo html_writer::empty_tag('input', [
            'type' => 'submit',
            'class' => 'btn btn-outline-secondary btn-sm',
            'value' => get_string('trydisabledsave', 'local_oerexchange'),
        ]);
        echo html_writer::end_tag('form');
    }

    // Co-authors. Everyone who can edit the resource can manage this list —
    // there is no separate, lesser tier (coauthor_manager's docblock explains
    // why), so a co-author can add another one. That grants nothing they do
    // not already hold: a co-author can already replace or delete the entry
    // outright, and the creator is not a row here, so they can never be
    // removed by someone they added.
    echo $OUTPUT->heading(get_string('coauthorsheading', 'local_oerexchange'), 6, 'mt-3');
    echo html_writer::tag('p', get_string('coauthorsintro', 'local_oerexchange'), ['class' => 'small text-muted']);

    if ($coauthorusers) {
        echo html_writer::start_tag('ul', ['class' => 'list-unstyled mb-2']);
        foreach ($coauthorusers as $coauthoruser) {
            $removeurl = new moodle_url('/local/oerexchange/resource.php', [
                'id' => $id,
                'action' => 'removecoauthor',
                'coauthorid' => $coauthoruser->id,
                'sesskey' => sesskey(),
            ]);
            echo html_writer::tag(
                'li',
                s(fullname($coauthoruser)) . ' '
                    . html_writer::tag('span', s($coauthoruser->email), ['class' => 'small text-muted'])
                    . ' '
                    . html_writer::link($removeurl, get_string('coauthorremove', 'local_oerexchange'), [
                        'class' => 'btn btn-outline-danger btn-sm ms-2',
                    ]),
                ['class' => 'mb-1']
            );
        }
        echo html_writer::end_tag('ul');
    } else {
        echo html_writer::tag('p', get_string('coauthorsnone', 'local_oerexchange'), ['class' => 'mb-2']);
    }

    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => new moodle_url('/local/oerexchange/resource.php', ['id' => $id]),
        'class' => 'd-flex gap-2 align-items-start flex-wrap',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'addcoauthor']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::tag('label', get_string('coauthoradd', 'local_oerexchange'), [
        'class' => 'visually-hidden',
        'for' => 'oerexchange-coauthoridentifier',
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'class' => 'form-control form-control-sm',
        'style' => 'max-width:22rem;',
        'id' => 'oerexchange-coauthoridentifier',
        'name' => 'coauthoridentifier',
        'maxlength' => 255,
        'required' => 'required',
        'placeholder' => get_string('coauthoridentifierplaceholder', 'local_oerexchange'),
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-outline-secondary btn-sm',
        'value' => get_string('coauthoradd', 'local_oerexchange'),
    ]);
    echo html_writer::end_tag('form');

    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}

// Work out each required plugin's real trial status up front (used by both
// the Try it warning below and the Required plugins list further down), in
// three states rather than a boolean "in trial" — found live, 2026-07-19:
// a plugin merely being on the allowlist only means the sandbox *attempts*
// to install it via a fragile runtime upgrade path that does not reliably
// complete (see playground::is_baked_in()'s docblock and
// dev-docs/oer-platform/discoveries/2026-07-19-sandbox-thirdparty-plugin-db-install-limitation.md).
// Only a plugin actually baked into the bundle at build time is reliable:
// - 'bakedin'    — baked into the branch's bundle; Try it will fully work.
// - 'attempted'  — on the allowlist but not baked in; Try it will install
// the plugin's files but may not finish registering it.
// - 'missing'    — not on the allowlist at all; skipped entirely.
$pluginstatuses = [];
$branch = $version && $version->moodleversion
    ? \local_oerexchange\local\sandbox\playground::map_branch($version->moodleversion)
    : null;
foreach ($requiredplugins as $plugin) {
    $status = 'missing';
    if ($branch !== null) {
        $onallowlist = (bool) $DB->record_exists('local_oerexchange_pluginallowlist', [
            'plugintype' => $plugin['type'], 'pluginname' => $plugin['name'],
            'moodlebranch' => $branch, 'status' => 'active',
        ]);
        if ($onallowlist) {
            $status = \local_oerexchange\local\sandbox\playground::is_baked_in($plugin['type'], $plugin['name'], $branch)
                ? 'bakedin'
                : 'attempted';
        }
    }
    $pluginstatuses[] = ['type' => $plugin['type'], 'name' => $plugin['name'], 'status' => $status];
}
$hasunreliableplugin = (bool) array_filter($pluginstatuses, fn($p) => $p['status'] === 'attempted');

// Action buttons.
echo html_writer::start_tag('div', ['class' => 'mb-3']);
if ($sandboxenabled && $version && $resource->type !== 'data' && !empty($resource->trydisabled)) {
    // The author has switched sandbox availability off. Say so, and show
    // their reason if they gave one, rather than silently omitting the
    // button — a missing button reads as a broken page.
    $reason = trim((string) $resource->trydisabledreason);
    echo html_writer::tag(
        'div',
        $reason !== ''
            ? get_string(
                'tryitdisabledwithreason',
                'local_oerexchange',
                format_string($reason, true, ['context' => context_system::instance()])
            )
            : get_string('tryitdisabledbyauthor', 'local_oerexchange'),
        ['class' => 'alert alert-info py-2 px-3 mb-2']
    );
} else if (
    $sandboxenabled && $version && $resource->type !== 'data'
        && \local_oerexchange\local\size_advice::is_trial_blocked((int) $version->filesize)
) {
    // Over the site's hard cap for trials. Say so and say how big it is —
    // a missing button reads as a broken page, which is the same reasoning
    // the author opt-out branch above applies. The Download button rendered
    // immediately below is the way through, so this text points at it.
    echo html_writer::tag(
        'div',
        get_string('tryittoolarge', 'local_oerexchange', (object) [
            'size' => \local_oerexchange\local\size_advice::format((int) $version->filesize),
            'max' => \local_oerexchange\local\size_advice::format(
                \local_oerexchange\local\size_advice::max_trial_bytes()
            ),
        ]),
        ['class' => 'alert alert-info py-2 px-3 mb-2']
    );
} else if ($sandboxenabled && $version && $resource->type !== 'data') {
    // A 'data' resource is not a Moodle backup — there is nothing to
    // restore, so "Try it" is never offered for it (see sandbox_launch.php's
    // matching defence-in-depth check on this same endpoint's direct URL).
    $tryurl = new moodle_url('/local/oerexchange/sandbox_launch.php', ['id' => $resource->id]);
    echo html_writer::link(
        $tryurl,
        get_string('tryit', 'local_oerexchange'),
        ['class' => 'btn btn-success me-2', 'target' => '_blank']
    );
    echo html_writer::tag('div', get_string('tryitloadinghint', 'local_oerexchange'), ['class' => 'small text-muted d-inline']);
    if (\local_oerexchange\local\size_advice::is_slow_trial((int) $version->filesize)) {
        // The whole .mbz has to reach the visitor's browser before the trial
        // can start, and past the sandbox engine's fast-path budget that
        // download reports no progress whatsoever. Saying so beats a button
        // that looks broken for several minutes — and the download link
        // beside it is the better route for a backup this size anyway.
        echo html_writer::tag(
            'div',
            get_string(
                'tryitslowwarning',
                'local_oerexchange',
                \local_oerexchange\local\size_advice::format((int) $version->filesize)
            ),
            ['class' => 'alert alert-info mt-2 mb-0 py-2 px-3 small']
        );
    }
    if ($hasunreliableplugin) {
        echo html_writer::tag(
            'div',
            get_string('tryitpluginwarning', 'local_oerexchange'),
            ['class' => 'alert alert-warning mt-2 mb-0 py-2 px-3 small']
        );
    }
}
if ($version) {
    $dlurl = new moodle_url('/local/oerexchange/download.php', ['id' => $version->id]);
    echo html_writer::link($dlurl, get_string('download', 'local_oerexchange'), ['class' => 'btn btn-outline-primary me-2']);
}
// Share this resource. Uses $PAGE->url rather than rebuilding the resource
// URL so the shared link is byte-identical to the canonical one the og:url
// tag advertises.
//
// share_targets wants PLAIN TEXT, not HTML: the title ends up in a tweet
// body, a mailto: subject, an sms: body and navigator.share()'s title, none
// of which render HTML entities. So filter it (a multilang title collapses to
// the viewer's language instead of being shared as literal <span> markup),
// then flatten the filtered HTML back to plain text so format_string()'s
// '&amp;' never reaches a share sheet as visible text. Same
// format_string/content_to_text ordering the catalogue card teaser in
// index.php uses — filter first, flatten second.
$sharetitle = content_to_text(
    format_string($resource->title, true, ['context' => context_system::instance()]),
    FORMAT_HTML
);
echo \local_oerexchange\local\share_targets::render(
    $PAGE->url->out(false),
    $sharetitle,
    get_string('shareresource', 'local_oerexchange')
);

// Star. Rendered as a real link to this page's own 'star' action so it works
// with scripting off; local_oerexchange/star intercepts the click and does it
// over AJAX instead. Shown to anyone signed in — starring is a reader's act,
// not an author's, so it is deliberately not behind the edit gate. Anonymous
// visitors see the count without a control they cannot use.
$starcount = \local_oerexchange\local\star_manager::star_count((int) $resource->id);
if (isloggedin() && !isguestuser()) {
    $isstarred = \local_oerexchange\local\star_manager::is_starred((int) $resource->id, (int) $USER->id);
    echo html_writer::link(
        new moodle_url(
            '/local/oerexchange/resource.php',
            ['id' => $id, 'action' => 'star', 'sesskey' => sesskey()]
        ),
        get_string($isstarred ? 'unstar' : 'star', 'local_oerexchange')
            . ' (' . get_string('starcount', 'local_oerexchange', $starcount) . ')',
        [
            'class' => 'btn btn-sm ms-2 ' . ($isstarred ? 'btn-secondary' : 'btn-outline-secondary'),
            'data-region' => 'oerexchange-star',
            'data-starred' => $isstarred ? '1' : '0',
            'role' => 'button',
            'aria-pressed' => $isstarred ? 'true' : 'false',
        ]
    );
    $PAGE->requires->js_call_amd('local_oerexchange/star', 'init', [(int) $resource->id]);
} else if ($starcount > 0) {
    echo html_writer::tag(
        'span',
        get_string('starcount', 'local_oerexchange', $starcount),
        ['class' => 'ms-2 text-muted small']
    );
}

echo html_writer::end_tag('div');

// Required plugins.
echo $OUTPUT->heading(get_string('requiredplugins', 'local_oerexchange'), 4);
if (empty($requiredplugins)) {
    echo html_writer::tag('p', get_string('requiredpluginsnone', 'local_oerexchange'));
} else {
    echo html_writer::start_tag('ul');
    foreach ($pluginstatuses as $plugin) {
        $label = $plugin['type'] . '_' . $plugin['name'];
        $badge = match ($plugin['status']) {
            'bakedin' => html_writer::tag(
                'span',
                get_string('includedintrial', 'local_oerexchange'),
                ['class' => 'badge bg-success ms-2']
            ),
            'attempted' => html_writer::tag(
                'span',
                get_string('attemptedintrial', 'local_oerexchange'),
                ['class' => 'badge bg-warning text-dark ms-2']
            ),
            default => html_writer::tag(
                'span',
                get_string('missingfromtrial', 'local_oerexchange'),
                ['class' => 'badge bg-secondary ms-2']
            ),
        };
        echo html_writer::tag('li', s($label) . $badge);
    }
    echo html_writer::end_tag('ul');
}

// Structure preview.
echo $OUTPUT->heading(get_string('structurepreview', 'local_oerexchange'), 4);
if ($structure && !empty($structure['sections'])) {
    echo html_writer::start_tag('ul');
    foreach ($structure['sections'] as $section) {
        echo html_writer::start_tag('li');
        $title = $section['title'] ?? '';
        // Unnamed topics/weekly sections store just the bare section number
        // in the backup XML (Moodle applies "Topic N"/"Week N" only at
        // display time in core, not in the backup) — show that number in a
        // readable label instead of leaving it as a bare digit.
        // No s() on the lang-string branch: get_string() output is
        // site-owned text that is already safe to emit, and the only value
        // interpolated into it is the ctype_digit()-guarded section number.
        // s() there would instead escape any & or quote in a translation of
        // the string itself. Matches local_oerclient's resource_preview.php.
        echo ctype_digit((string) $title)
            ? get_string('sectionnumber', 'local_oerexchange', $title)
            : format_string($title, true, ['context' => context_system::instance()]);
        if (!empty($section['activities'])) {
            echo html_writer::start_tag('ul');
            foreach ($section['activities'] as $activity) {
                echo html_writer::tag(
                    'li',
                    s($activity['modulename']) . ': '
                        . format_string($activity['title'], true, ['context' => context_system::instance()])
                );
            }
            echo html_writer::end_tag('ul');
        }
        echo html_writer::end_tag('li');
    }
    echo html_writer::end_tag('ul');
} else {
    echo html_writer::tag('p', get_string('nocatalogresources', 'local_oerexchange'), ['class' => 'text-muted']);
}

// Reviews. Collapsed by default; the heading is a click-to-expand toggle,
// per the design doc's "Reviews/report UX" decision — Boost's Bootstrap 5
// bundle is already loaded on every Moodle page, so plain
// data-bs-toggle/data-bs-target attributes need no new JS dependency.
echo html_writer::tag(
    'h4',
    html_writer::link(
        '#oerexchange-reviews-collapse',
        get_string('reviewsheading', 'local_oerexchange'),
        ['class' => 'text-decoration-none', 'data-bs-toggle' => 'collapse', 'role' => 'button',
            'aria-expanded' => 'false', 'aria-controls' => 'oerexchange-reviews-collapse']
    )
);
echo html_writer::start_tag('div', ['class' => 'collapse', 'id' => 'oerexchange-reviews-collapse']);
$reviews = $DB->get_records(
    'local_oerexchange_reviews',
    ['resourceid' => $resource->id, 'status' => 'visible'],
    'timecreated DESC'
);
// The three review fields are captured as PARAM_TEXT (see the 'review'
// action handler above), which strips every tag EXCEPT the ones the multilang
// filter needs — so they are plain text that may legitimately carry multilang
// markup, and format_string() is the right sink for them, not s() (which
// escaped that markup into literal <span> text) and not
// format_text(FORMAT_HTML) (these fields never hold HTML; only the
// PARAM_RAW summary column does).
$reviewcontext = context_system::instance();
foreach ($reviews as $rv) {
    echo html_writer::start_tag('div', ['class' => 'card mb-2']);
    echo html_writer::start_tag('div', ['class' => 'card-body']);
    if ($rv->contexttext) {
        echo html_writer::tag(
            'p',
            '<strong>' . get_string('reviewcontext', 'local_oerexchange') . '</strong> '
                . format_string($rv->contexttext, true, ['context' => $reviewcontext])
        );
    }
    if ($rv->adaptationtext) {
        echo html_writer::tag(
            'p',
            '<strong>' . get_string('reviewadaptation', 'local_oerexchange') . '</strong> '
                . format_string($rv->adaptationtext, true, ['context' => $reviewcontext])
        );
    }
    if ($rv->outcometext) {
        echo html_writer::tag(
            'p',
            '<strong>' . get_string('reviewoutcome', 'local_oerexchange') . '</strong> '
                . format_string($rv->outcometext, true, ['context' => $reviewcontext])
        );
    }
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}

if (isloggedin() && !isguestuser()) {
    echo $OUTPUT->heading(get_string('addreview', 'local_oerexchange'), 5);
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => new moodle_url('/local/oerexchange/resource.php', ['id' => $id]),
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'review']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::tag('label', get_string('reviewcontext', 'local_oerexchange'));
    echo html_writer::tag('textarea', '', ['name' => 'reviewcontext', 'class' => 'form-control mb-2', 'required' => 'required']);
    echo html_writer::tag('label', get_string('reviewadaptation', 'local_oerexchange'));
    echo html_writer::tag('textarea', '', ['name' => 'reviewadaptation', 'class' => 'form-control mb-2', 'required' => 'required']);
    echo html_writer::tag('label', get_string('reviewoutcome', 'local_oerexchange'));
    echo html_writer::tag('textarea', '', ['name' => 'reviewoutcome', 'class' => 'form-control mb-2']);
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('reviewsubmit', 'local_oerexchange'),
        'class' => 'btn btn-primary',
    ]);
    echo html_writer::end_tag('form');
}
echo html_writer::end_tag('div'); // End #oerexchange-reviews-collapse.

// Report. Same collapsed-by-default pattern, distinct target id.
if (isloggedin() && !isguestuser()) {
    echo html_writer::tag(
        'h5',
        html_writer::link(
            '#oerexchange-report-collapse',
            get_string('report', 'local_oerexchange'),
            ['class' => 'text-decoration-none', 'data-bs-toggle' => 'collapse', 'role' => 'button',
                'aria-expanded' => 'false', 'aria-controls' => 'oerexchange-report-collapse']
        )
    );
    echo html_writer::start_tag('div', ['class' => 'collapse', 'id' => 'oerexchange-report-collapse']);
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => new moodle_url('/local/oerexchange/resource.php', ['id' => $id]),
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'report']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::select([
        'copyright' => get_string('reporttype_copyright', 'local_oerexchange'),
        'quality' => get_string('reporttype_quality', 'local_oerexchange'),
        'spam' => get_string('reporttype_spam', 'local_oerexchange'),
        'other' => get_string('reporttype_other', 'local_oerexchange'),
    ], 'reporttype', '', false, ['class' => 'form-select mb-2']);
    echo html_writer::tag('textarea', '', ['name' => 'reportdetails', 'class' => 'form-control mb-2']);
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('reportsubmit', 'local_oerexchange'),
        'class' => 'btn btn-outline-danger',
    ]);
    echo html_writer::end_tag('form');
    echo html_writer::end_tag('div'); // End #oerexchange-report-collapse.
}

echo $OUTPUT->footer();
