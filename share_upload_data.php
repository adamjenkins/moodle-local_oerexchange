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
 * Upload a generic "data resource" (not a Moodle backup) directly on the
 * Exchange. Synchronous extension/MIME allowlist check, then publishes
 * immediately via resource_manager::publish() with type='data'.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_oerexchange\local\resource_manager;

require(__DIR__ . '/../../config.php');
// Filelib.php's free functions (file_get_unused_draft_itemid(), get_file_storage(),
// file_save_draft_area_files() inside resource_manager::publish()) are NOT pulled
// in by setup.php's default require chain in this Moodle version — see
// resource.php's identical require_once and its docblock comment for the full,
// verified explanation. This script builds a draft area manually (no
// moodleform), so it needs the same explicit require.
require_once($CFG->libdir . '/filelib.php');
require_login();

// Optional "replace the file of an existing resource" mode — the mirror of
// share_upload_mbz.php's, keeping the data-file validation (extension
// allowlist + finfo MIME sniff) in one place. See that file's comment.
$resourceid = optional_param('resourceid', 0, PARAM_INT);
$updating = null;
if ($resourceid) {
    $updating = $DB->get_record('local_oerexchange_resources', ['id' => $resourceid], '*', MUST_EXIST);
    if (!resource_manager::user_can_edit_resource($updating, (int) $USER->id)) {
        throw new moodle_exception('error_notyourresource', 'local_oerexchange');
    }
    if ($updating->type !== 'data') {
        // Type-locked: a course/activity backup cannot become a data resource.
        throw new moodle_exception('error_wrongupdatetype', 'local_oerexchange');
    }
}

$pageparams = $resourceid ? ['resourceid' => $resourceid] : [];
$PAGE->set_url('/local/oerexchange/share_upload_data.php', $pageparams);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$heading = $updating
    ? get_string('replacefileheading', 'local_oerexchange', s($updating->title))
    : get_string('uploaddataheading', 'local_oerexchange');
$PAGE->set_title($heading);
$PAGE->set_heading($heading);

// Broad allowlist per the design's "reject dangerous types, not a narrow
// per-subtype mapping" decision: extension AND sniffed MIME type must both be
// on this list. Sniffing via finfo (not the browser-supplied MIME type, which
// is client-controlled and untrustworthy) is what actually blocks a
// double-extension or mislabeled upload.

/** @var string[] Allowed file extensions for a data-resource upload. */
const ALLOWED_DATA_EXTENSIONS = ['xml', 'csv', 'json', 'zip', 'pdf', 'h5p'];

/** @var string[] Allowed sniffed MIME types for a data-resource upload. */
const ALLOWED_DATA_MIMETYPES = [
    'text/xml', 'application/xml',
    'text/csv',
    'application/json',
    'application/zip',
    'application/pdf',
    // H5P files are zip containers under the hood.
    'application/octet-stream',
];

$error = null;

if (data_submitted() && confirm_sesskey()) {
    $title = optional_param('title', '', PARAM_TEXT);
    $summary = optional_param('summary', '', PARAM_RAW_TRIMMED);
    $language = optional_param('language', '', PARAM_TEXT);
    $tags = optional_param('tags', '', PARAM_TEXT);
    $licenseshortname = optional_param('licenseshortname', '', PARAM_TEXT);
    $dataresourcetype = optional_param('dataresourcetype', '', PARAM_ALPHA);

    try {
        if ($updating) {
            // Metadata is carried through untouched in update mode — see
            // share_upload_mbz.php's matching comment.
            $title = $updating->title;
            $summary = (string) $updating->summary;
            $language = (string) $updating->language;
            $tags = (string) $updating->tags;
            $licenseshortname = $updating->licenseshortname;
            $dataresourcetype = $updating->dataresourcetype;
        }
        if (!in_array($dataresourcetype, ['glossary', 'questionbank', 'other'], true)) {
            throw new moodle_exception('error_invaliddataresourcetype', 'local_oerexchange');
        }
        if (empty($_FILES['datafile']) || $_FILES['datafile']['error'] !== UPLOAD_ERR_OK) {
            throw new moodle_exception('error_nofile', 'local_oerexchange');
        }

        $filename = clean_param($_FILES['datafile']['name'], PARAM_FILE);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $sniffedmime = $finfo->file($_FILES['datafile']['tmp_name']);

        if (!in_array($extension, ALLOWED_DATA_EXTENSIONS, true) || !in_array($sniffedmime, ALLOWED_DATA_MIMETYPES, true)) {
            throw new moodle_exception('error_invaliddatafiletype', 'local_oerexchange');
        }

        $usercontext = context_user::instance($USER->id);
        $draftitemid = file_get_unused_draft_itemid();
        $fs = get_file_storage();
        $fs->create_file_from_pathname([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $filename,
        ], $_FILES['datafile']['tmp_name']);

        resource_manager::publish($draftitemid, (int) $USER->id, $updating ? $updating->siteid : null, [
            'type' => 'data',
            'title' => $title,
            'summary' => $summary,
            'language' => $language,
            'tags' => $tags,
            'licenseshortname' => $licenseshortname,
            'dataresourcetype' => $dataresourcetype,
        ], $updating ? (int) $updating->id : null);

        if ($updating) {
            redirect(
                new moodle_url('/local/oerexchange/resource.php', ['id' => $updating->id]),
                get_string('replacefilequeued', 'local_oerexchange')
            );
        }
        redirect(new moodle_url('/local/oerexchange/index.php'), get_string('uploadsubmit', 'local_oerexchange'));
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

echo $OUTPUT->header();

if ($error !== null) {
    echo html_writer::tag('div', s($error), ['class' => 'alert alert-danger']);
}

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => new moodle_url('/local/oerexchange/share_upload_data.php', $pageparams),
    'enctype' => 'multipart/form-data',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

if ($updating) {
    echo html_writer::tag(
        'p',
        get_string('replacefileintro', 'local_oerexchange', s($updating->title)),
        ['class' => 'alert alert-info']
    );
} else {
    echo html_writer::tag('label', get_string('datatypelabel', 'local_oerexchange'), ['for' => 'oerexchange-data-type']);
    echo html_writer::select(
        [
        'glossary' => get_string('datatype_glossary', 'local_oerexchange'),
        'questionbank' => get_string('datatype_questionbank', 'local_oerexchange'),
        'other' => get_string('datatype_other', 'local_oerexchange'),
        ],
        'dataresourcetype',
        '',
        false,
        ['id' => 'oerexchange-data-type', 'class' => 'form-select mb-2']
    );

    echo html_writer::tag('label', get_string('titlelabel', 'local_oerexchange'), ['for' => 'oerexchange-data-title']);
    echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'title', 'id' => 'oerexchange-data-title', 'class' => 'form-control mb-2', 'required' => 'required',
    ]);

    echo html_writer::tag('label', get_string('summarylabel', 'local_oerexchange'), ['for' => 'oerexchange-data-summary']);
    echo html_writer::tag('textarea', '', [
        'name' => 'summary', 'id' => 'oerexchange-data-summary', 'class' => 'form-control mb-2',
    ]);

    echo html_writer::tag('label', get_string('licenselabelform', 'local_oerexchange'), ['for' => 'oerexchange-data-license']);
    echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'licenseshortname', 'id' => 'oerexchange-data-license', 'class' => 'form-control mb-2',
    'required' => 'required',
    ]);

    echo html_writer::tag('label', get_string('tagslabel', 'local_oerexchange'), ['for' => 'oerexchange-data-tags']);
    echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'tags', 'id' => 'oerexchange-data-tags', 'class' => 'form-control mb-2',
    ]);
}

echo html_writer::tag('label', get_string('uploaddatafile', 'local_oerexchange'), ['for' => 'oerexchange-data-file']);
echo html_writer::empty_tag('input', [
    'type' => 'file', 'name' => 'datafile', 'id' => 'oerexchange-data-file', 'class' => 'form-control mb-2',
    'required' => 'required',
]);

echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string($updating ? 'replacefilesubmit' : 'uploadsubmit', 'local_oerexchange'),
    'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
