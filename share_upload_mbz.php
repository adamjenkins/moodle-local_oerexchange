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
 * Upload a course/activity .mbz directly on the Exchange — no client site
 * involved. Publishes via resource_manager::publish() with siteid=null.
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

// Optional "replace the file of an existing resource" mode, linked from
// resource.php's owner card. Sharing the page keeps the .mbz validation and
// draft-area handling in ONE place rather than growing a fourth upload
// handler elsewhere. Only the file changes in this mode: the catalogue
// entry's metadata, id, link and reviews all stay put.
$resourceid = optional_param('resourceid', 0, PARAM_INT);
$updating = null;
if ($resourceid) {
    $updating = $DB->get_record('local_oerexchange_resources', ['id' => $resourceid], '*', MUST_EXIST);
    if (!resource_manager::user_can_edit_resource($updating, (int) $USER->id)) {
        throw new moodle_exception('error_notyourresource', 'local_oerexchange');
    }
    if ($updating->type === 'data') {
        // Type-locked: a data resource is not a Moodle backup, and swapping
        // one for the other would leave the catalogue entry describing
        // something it no longer contains. share_upload_data.php refuses the
        // mirror image of this.
        throw new moodle_exception('error_wrongupdatetype', 'local_oerexchange');
    }
}

$pageparams = $resourceid ? ['resourceid' => $resourceid] : [];
$PAGE->set_url('/local/oerexchange/share_upload_mbz.php', $pageparams);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$heading = $updating
    ? get_string('replacefileheading', 'local_oerexchange', s($updating->title))
    : get_string('uploadmbzheading', 'local_oerexchange');
$PAGE->set_title($heading);
$PAGE->set_heading($heading);

$error = null;

if (data_submitted() && confirm_sesskey()) {
    $type = optional_param('type', '', PARAM_ALPHA);
    $title = optional_param('title', '', PARAM_TEXT);
    $summary = optional_param('summary', '', PARAM_RAW_TRIMMED);
    $language = optional_param('language', '', PARAM_TEXT);
    $tags = optional_param('tags', '', PARAM_TEXT);
    $licenseshortname = optional_param('licenseshortname', '', PARAM_TEXT);
    $activitytype = optional_param('activitytype', '', PARAM_TEXT);

    try {
        if ($updating) {
            // Metadata is not editable here — carry the stored values through
            // untouched so a replacement file cannot quietly relabel an entry
            // people have already reviewed and imported.
            $type = $updating->type;
            $title = $updating->title;
            $summary = (string) $updating->summary;
            $language = (string) $updating->language;
            $tags = (string) $updating->tags;
            $licenseshortname = $updating->licenseshortname;
            $activitytype = (string) $updating->activitytype;
        }
        if (!in_array($type, ['course', 'activity'], true)) {
            throw new moodle_exception('error_invalidresourcetype', 'local_oerexchange');
        }
        if (empty($_FILES['mbzfile']) || $_FILES['mbzfile']['error'] !== UPLOAD_ERR_OK) {
            throw new moodle_exception('error_nofile', 'local_oerexchange');
        }

        // Manually build a draft area from the uploaded temp file — this plugin
        // uses no moodleform, so there is no filepicker element to do this for
        // us. resource_manager::publish() expects exactly what a moodleform
        // filepicker would have produced: one file sitting in a fresh draft
        // area owned by the current user.
        $usercontext = context_user::instance($USER->id);
        $draftitemid = file_get_unused_draft_itemid();
        $fs = get_file_storage();
        $fs->create_file_from_pathname([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => clean_param($_FILES['mbzfile']['name'], PARAM_FILE),
        ], $_FILES['mbzfile']['tmp_name']);

        resource_manager::publish($draftitemid, (int) $USER->id, $updating ? $updating->siteid : null, [
            'type' => $type,
            'title' => $title,
            'summary' => $summary,
            'language' => $language,
            'tags' => $tags,
            'licenseshortname' => $licenseshortname,
            'activitytype' => $type === 'activity' ? $activitytype : null,
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
    'action' => new moodle_url('/local/oerexchange/share_upload_mbz.php', $pageparams),
    'enctype' => 'multipart/form-data',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

if ($updating) {
    // Update mode: the file is the only thing being changed, so the metadata
    // fields below are not rendered at all. Showing them read-only would
    // invite the question of why they can't be edited; saying plainly what
    // will and won't change answers it.
    echo html_writer::tag(
        'p',
        get_string('replacefileintro', 'local_oerexchange', s($updating->title)),
        ['class' => 'alert alert-info']
    );
} else {
    echo html_writer::tag('label', get_string('typelabel', 'local_oerexchange'), ['for' => 'oerexchange-upload-type']);
    echo html_writer::select(
        ['course' => get_string('typecourse', 'local_oerexchange'), 'activity' => get_string('typeactivity', 'local_oerexchange')],
        'type',
        '',
        false,
        ['id' => 'oerexchange-upload-type', 'class' => 'form-select mb-2']
    );

    echo html_writer::tag('label', get_string('titlelabel', 'local_oerexchange'), ['for' => 'oerexchange-upload-title']);
    echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'title', 'id' => 'oerexchange-upload-title', 'class' => 'form-control mb-2',
    'required' => 'required',
    ]);

    echo html_writer::tag('label', get_string('summarylabel', 'local_oerexchange'), ['for' => 'oerexchange-upload-summary']);
    echo html_writer::tag('textarea', '', [
        'name' => 'summary', 'id' => 'oerexchange-upload-summary', 'class' => 'form-control mb-2',
    ]);

    echo html_writer::tag('label', get_string('licenselabelform', 'local_oerexchange'), ['for' => 'oerexchange-upload-license']);
    echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'licenseshortname', 'id' => 'oerexchange-upload-license', 'class' => 'form-control mb-2',
    'required' => 'required',
    ]);

    echo html_writer::tag('label', get_string('tagslabel', 'local_oerexchange'), ['for' => 'oerexchange-upload-tags']);
    echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'tags', 'id' => 'oerexchange-upload-tags', 'class' => 'form-control mb-2',
    ]);

    echo html_writer::tag(
        'label',
        get_string('activitytypelabel', 'local_oerexchange'),
        ['for' => 'oerexchange-upload-activitytype']
    );
    echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'activitytype', 'id' => 'oerexchange-upload-activitytype', 'class' => 'form-control mb-2',
    'placeholder' => 'quiz, quizquest, glossary, ...',
    ]);
}

echo html_writer::tag('label', get_string('uploadmbzfile', 'local_oerexchange'), ['for' => 'oerexchange-upload-file']);
echo html_writer::empty_tag('input', [
    'type' => 'file', 'name' => 'mbzfile', 'id' => 'oerexchange-upload-file', 'class' => 'form-control mb-2',
    'accept' => '.mbz', 'required' => 'required',
]);

echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string($updating ? 'replacefilesubmit' : 'uploadsubmit', 'local_oerexchange'),
    'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
