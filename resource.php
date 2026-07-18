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

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$resource = $DB->get_record('local_oerexchange_resources', ['id' => $id], '*', MUST_EXIST);

$PAGE->set_url('/local/oerexchange/resource.php', ['id' => $id]);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title($resource->title);
$PAGE->set_heading($resource->title);

if ($resource->status !== 'published' && !has_capability('local/oerexchange:moderate', context_system::instance())) {
    throw new moodle_exception('error_notfound', 'local_oerexchange');
}

// Handle report/review submission.
if ($action === 'report' && confirm_sesskey() && isloggedin() && !isguestuser()) {
    require_login();
    $type = required_param('reporttype', PARAM_ALPHA);
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
}

$version = null;
$latest = $DB->get_records('local_oerexchange_versions', ['resourceid' => $resource->id, 'status' => 'ready'], 'versionnumber DESC', '*', 0, 1);
if ($latest) {
    $version = reset($latest);
}

$requiredplugins = $version && $version->requiredplugins ? json_decode($version->requiredplugins, true) : [];
$structure = $version && $version->structurejson ? json_decode($version->structurejson, true) : null;

$sandboxenabled = (bool) get_config('local_oerexchange', 'sandboxenabled') && get_config('local_oerexchange', 'sandboxbaseurl');

echo $OUTPUT->header();

echo html_writer::tag('p', get_string('licenselabel', 'local_oerexchange', s($resource->licenseshortname)));
if ($resource->courseformat) {
    echo html_writer::tag('p', get_string('courseformatlabel', 'local_oerexchange', s($resource->courseformat)), ['class' => 'small text-muted']);
}
if ($version && $version->moodleversion) {
    echo html_writer::tag('p', get_string('moodleversionlabel', 'local_oerexchange', s($version->moodleversion)), ['class' => 'small text-muted']);
}
if ($resource->forkedfromid) {
    $parent = $DB->get_record('local_oerexchange_resources', ['id' => $resource->forkedfromid]);
    if ($parent) {
        $purl = new moodle_url('/local/oerexchange/resource.php', ['id' => $parent->id]);
        echo html_writer::tag('p', get_string('attributionchain', 'local_oerexchange', html_writer::link($purl, s($parent->title))));
    }
}

echo html_writer::tag('div', format_text($resource->summary ?? '', FORMAT_PLAIN), ['class' => 'mb-3']);

// Action buttons.
echo html_writer::start_tag('div', ['class' => 'mb-3']);
if ($sandboxenabled && $version) {
    $tryurl = new moodle_url('/local/oerexchange/sandbox_launch.php', ['id' => $resource->id]);
    echo html_writer::link($tryurl, get_string('tryit', 'local_oerexchange'), ['class' => 'btn btn-success me-2', 'target' => '_blank']);
    echo html_writer::tag('div', get_string('tryitloadinghint', 'local_oerexchange'), ['class' => 'small text-muted d-inline']);
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
    $branch = $version && $version->moodleversion
        ? \local_oerexchange\local\sandbox\playground::map_branch($version->moodleversion)
        : null;
    echo html_writer::start_tag('ul');
    foreach ($requiredplugins as $plugin) {
        $intrial = false;
        if ($branch !== null) {
            $intrial = (bool) $DB->record_exists('local_oerexchange_pluginallowlist', [
                'plugintype' => $plugin['type'], 'pluginname' => $plugin['name'],
                'moodlebranch' => $branch, 'status' => 'active',
            ]);
        }
        $label = $plugin['type'] . '_' . $plugin['name'];
        $badge = $intrial
            ? html_writer::tag('span', get_string('includedintrial', 'local_oerexchange'), ['class' => 'badge bg-success ms-2'])
            : html_writer::tag('span', get_string('missingfromtrial', 'local_oerexchange'), ['class' => 'badge bg-secondary ms-2']);
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
        echo s($section['title'] ?: '');
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

// Reviews.
echo $OUTPUT->heading(get_string('reviewsheading', 'local_oerexchange'), 4);
$reviews = $DB->get_records('local_oerexchange_reviews', ['resourceid' => $resource->id, 'status' => 'visible'], 'timecreated DESC');
foreach ($reviews as $rv) {
    echo html_writer::start_tag('div', ['class' => 'card mb-2']);
    echo html_writer::start_tag('div', ['class' => 'card-body']);
    if ($rv->contexttext) {
        echo html_writer::tag('p', '<strong>' . get_string('reviewcontext', 'local_oerexchange') . '</strong> ' . s($rv->contexttext));
    }
    if ($rv->adaptationtext) {
        echo html_writer::tag('p', '<strong>' . get_string('reviewadaptation', 'local_oerexchange') . '</strong> ' . s($rv->adaptationtext));
    }
    if ($rv->outcometext) {
        echo html_writer::tag('p', '<strong>' . get_string('reviewoutcome', 'local_oerexchange') . '</strong> ' . s($rv->outcometext));
    }
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}

if (isloggedin() && !isguestuser()) {
    echo $OUTPUT->heading(get_string('addreview', 'local_oerexchange'), 5);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => new moodle_url('/local/oerexchange/resource.php', ['id' => $id])]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'review']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::tag('label', get_string('reviewcontext', 'local_oerexchange'));
    echo html_writer::tag('textarea', '', ['name' => 'reviewcontext', 'class' => 'form-control mb-2', 'required' => 'required']);
    echo html_writer::tag('label', get_string('reviewadaptation', 'local_oerexchange'));
    echo html_writer::tag('textarea', '', ['name' => 'reviewadaptation', 'class' => 'form-control mb-2', 'required' => 'required']);
    echo html_writer::tag('label', get_string('reviewoutcome', 'local_oerexchange'));
    echo html_writer::tag('textarea', '', ['name' => 'reviewoutcome', 'class' => 'form-control mb-2']);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('reviewsubmit', 'local_oerexchange'), 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');

    echo $OUTPUT->heading(get_string('report', 'local_oerexchange'), 5);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => new moodle_url('/local/oerexchange/resource.php', ['id' => $id])]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'report']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::select([
        'copyright' => get_string('reporttype_copyright', 'local_oerexchange'),
        'quality' => get_string('reporttype_quality', 'local_oerexchange'),
        'spam' => get_string('reporttype_spam', 'local_oerexchange'),
        'other' => get_string('reporttype_other', 'local_oerexchange'),
    ], 'reporttype', '', false, ['class' => 'form-select mb-2']);
    echo html_writer::tag('textarea', '', ['name' => 'reportdetails', 'class' => 'form-control mb-2']);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('reportsubmit', 'local_oerexchange'), 'class' => 'btn btn-outline-danger']);
    echo html_writer::end_tag('form');
}

echo $OUTPUT->footer();
