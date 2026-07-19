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

if ($resource->status !== 'published' && !has_capability('local/oerexchange:moderate', context_system::instance())) {
    throw new moodle_exception('error_notfound', 'local_oerexchange');
}

// Handle report/review submission.
if ($action === 'report' && confirm_sesskey() && isloggedin() && !isguestuser()) {
    require_login();
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
} else if ($action === 'review' && confirm_sesskey() && isloggedin() && !isguestuser()) {
    require_login();
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
} else if ($action === 'editthumbnail' && confirm_sesskey() && isloggedin() && !isguestuser()) {
    require_login();
    $isowner = (int) $resource->creatorid === (int) $USER->id;
    $ismoderator = has_capability('local/oerexchange:moderate', context_system::instance());
    if (!$isowner && !$ismoderator) {
        throw new moodle_exception('error_notyourresource', 'local_oerexchange');
    }
    $draftitemid = file_get_submitted_draft_itemid('thumbnail');
    $fs = get_file_storage();
    $usercontext = context_user::instance($USER->id);
    // The upload form below (deliberately, see its comment) uses a plain
    // <input type="file">, not Moodle's JS filepicker widget — the
    // filepicker is what normally AJAX-uploads into the draft area named by
    // file_get_submitted_draft_itemid() before this handler even runs.
    // Without it, the draft area is empty and file_save_draft_area_files()
    // below would silently no-op. Move the plain $_FILES upload into that
    // same draft item ourselves first, replicating what the filepicker's
    // AJAX endpoint (repository/draftfiles_ajax.php) does.
    if (
        !empty($_FILES['thumbnailfile']['tmp_name'])
        && $_FILES['thumbnailfile']['error'] === UPLOAD_ERR_OK
        && is_uploaded_file($_FILES['thumbnailfile']['tmp_name'])
    ) {
        // Discard whatever this draft item already held (e.g. a stale draft
        // left over from an earlier abandoned edit) before adding the file
        // just submitted, so the draft area holds exactly one file.
        $fs->delete_area_files($usercontext->id, 'user', 'draft', $draftitemid);
        $filename = clean_param($_FILES['thumbnailfile']['name'], PARAM_FILE);
        if ($filename === '') {
            $filename = 'thumbnail';
        }
        $fs->create_file_from_pathname([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $filename,
        ], $_FILES['thumbnailfile']['tmp_name']);
    }
    $draftfiles = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false);
    if (empty($draftfiles)) {
        throw new moodle_exception('error_thumbnailnofile', 'local_oerexchange');
    }
    $draftfile = reset($draftfiles);
    // A plain <input type="file"> (see the upload form below) offers no
    // client-side type filtering that can be trusted, and this filearea is
    // served inline via local_oerexchange_pluginfile() (lib.php, Task 9) —
    // an arbitrary uploaded file (e.g. .html/.svg) rendered inline from the
    // Moodle origin would be a stored-XSS vector. Reject anything that
    // isn't an image before it ever reaches the permanent 'coverimage'
    // filearea, rather than trusting the draft-area file's extension alone.
    if (strpos((string) $draftfile->get_mimetype(), 'image/') !== 0) {
        throw new moodle_exception('error_thumbnailnotanimage', 'local_oerexchange');
    }
    // No admin setting for this (out of this task's scope); mirrors
    // resource_manager::publish()'s maxbackupbytes pattern with a flat
    // default sized for a thumbnail rather than a course backup.
    $maxthumbnailbytes = 5 * 1024 * 1024;
    if ($draftfile->get_filesize() > $maxthumbnailbytes) {
        throw new moodle_exception('error_thumbnailtoolarge', 'local_oerexchange');
    }
    file_save_draft_area_files(
        $draftitemid,
        context_system::instance()->id,
        'local_oerexchange',
        'coverimage',
        $resource->id,
        ['subdirs' => 0, 'maxfiles' => 1, 'maxbytes' => $maxthumbnailbytes]
    );
    \core\notification::success(get_string('thumbnailuploaded', 'local_oerexchange'));
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

echo html_writer::tag('p', get_string('licenselabel', 'local_oerexchange', s($resource->licenseshortname)));
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
if ($resource->forkedfromid) {
    $parent = $DB->get_record('local_oerexchange_resources', ['id' => $resource->forkedfromid]);
    if ($parent) {
        $purl = new moodle_url('/local/oerexchange/resource.php', ['id' => $parent->id]);
        echo html_writer::tag(
            'p',
            get_string('attributionchain', 'local_oerexchange', html_writer::link($purl, s($parent->title)))
        );
    }
}

echo html_writer::tag('div', format_text($resource->summary ?? '', FORMAT_PLAIN), ['class' => 'mb-3']);

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
        'alt' => get_string('thumbnailalt', 'local_oerexchange', s($resource->title)),
        'class' => 'img-fluid mb-3', 'style' => 'max-height:200px;',
    ]);
}

// Thumbnail-replacement upload, shown only to the resource's creator or a
// moderator (same gate as the editthumbnail action handler above) — anyone
// else sees no form and posting the action directly is rejected there too,
// so this is a UI convenience, not the actual access-control boundary.
// Gated on isloggedin() && !isguestuser() first (matching the
// review/report forms elsewhere on this page): without it, an anonymous
// visitor's $USER->id (0) would spuriously equal a tombstoned resource's
// creatorid (also 0 - see the "Created by" comment above), showing this
// upload form to a guest.
$isowner = isloggedin() && !isguestuser()
    && $resource->creatorid
    && (int) $resource->creatorid === (int) $USER->id;
$ismoderator = isloggedin() && !isguestuser()
    && has_capability('local/oerexchange:moderate', context_system::instance());
if ($isowner || $ismoderator) {
    $draftitemid = file_get_submitted_draft_itemid('thumbnail');
    file_prepare_draft_area(
        $draftitemid,
        context_system::instance()->id,
        'local_oerexchange',
        'coverimage',
        $resource->id,
        ['subdirs' => 0, 'maxfiles' => 1]
    );
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => new moodle_url('/local/oerexchange/resource.php', ['id' => $id]),
        'enctype' => 'multipart/form-data',
        'class' => 'mb-3',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'editthumbnail']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'thumbnail', 'value' => $draftitemid]);
    echo html_writer::tag('label', get_string('thumbnailupload', 'local_oerexchange'));
    echo html_writer::empty_tag('input', [
        'type' => 'file', 'name' => 'thumbnailfile', 'class' => 'form-control mb-2', 'accept' => 'image/*',
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('editthumbnail', 'local_oerexchange'),
        'class' => 'btn btn-outline-secondary btn-sm',
    ]);
    echo html_writer::end_tag('form');
}

// Work out each required plugin's real trial status up front (used by both
// the Try it warning below and the Required plugins list further down), in
// three states rather than a boolean "in trial" — found live, 2026-07-19:
// a plugin merely being on the allowlist only means the sandbox *attempts*
// to install it via a fragile runtime upgrade path that does not reliably
// complete (see playground::BAKED_IN_PLUGINS docblock and
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
if ($sandboxenabled && $version) {
    $tryurl = new moodle_url('/local/oerexchange/sandbox_launch.php', ['id' => $resource->id]);
    echo html_writer::link(
        $tryurl,
        get_string('tryit', 'local_oerexchange'),
        ['class' => 'btn btn-success me-2', 'target' => '_blank']
    );
    echo html_writer::tag('div', get_string('tryitloadinghint', 'local_oerexchange'), ['class' => 'small text-muted d-inline']);
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
        echo ctype_digit((string) $title)
            ? s(get_string('sectionnumber', 'local_oerexchange', $title))
            : s($title);
        if (!empty($section['activities'])) {
            echo html_writer::start_tag('ul');
            foreach ($section['activities'] as $activity) {
                echo html_writer::tag('li', s($activity['modulename']) . ': ' . s($activity['title']));
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
foreach ($reviews as $rv) {
    echo html_writer::start_tag('div', ['class' => 'card mb-2']);
    echo html_writer::start_tag('div', ['class' => 'card-body']);
    if ($rv->contexttext) {
        echo html_writer::tag(
            'p',
            '<strong>' . get_string('reviewcontext', 'local_oerexchange') . '</strong> ' . s($rv->contexttext)
        );
    }
    if ($rv->adaptationtext) {
        echo html_writer::tag(
            'p',
            '<strong>' . get_string('reviewadaptation', 'local_oerexchange') . '</strong> ' . s($rv->adaptationtext)
        );
    }
    if ($rv->outcometext) {
        echo html_writer::tag(
            'p',
            '<strong>' . get_string('reviewoutcome', 'local_oerexchange') . '</strong> ' . s($rv->outcometext)
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
