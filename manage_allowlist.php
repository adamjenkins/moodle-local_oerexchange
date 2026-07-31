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
 * Sandbox plugin allowlist admin: curated contrib plugins installable in
 * Moodle Playground trials, mirrored same-origin. See DESIGN.md §2/§4.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_oerexchange\local\allowlist_manager;

require(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/oerexchange:managesites', $context);
$cansandbox = has_capability('local/oerexchange:managesandbox', $context);

$PAGE->set_url('/local/oerexchange/manage_allowlist.php');
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('managepluginallowlisttitle', 'local_oerexchange'));
$PAGE->set_heading(get_string('managepluginallowlisttitle', 'local_oerexchange'));

$toggleid = optional_param('toggleid', 0, PARAM_INT);
if ($toggleid && confirm_sesskey()) {
    $entry = $DB->get_record('local_oerexchange_pluginallowlist', ['id' => $toggleid], '*', MUST_EXIST);
    $entry->status = $entry->status === 'active' ? 'disabled' : 'active';
    $entry->timemodified = time();
    $DB->update_record('local_oerexchange_pluginallowlist', $entry);
    redirect(new moodle_url('/local/oerexchange/manage_allowlist.php'));
}

if (optional_param('savebake', 0, PARAM_INT) && confirm_sesskey()) {
    // Gated on the capability rather than merely hidden in the UI: a request
    // forged by (or replayed for) a user without local/oerexchange:managesandbox
    // must not be able to flip what ships baked into every trial, even though
    // the checkbox itself is rendered disabled for them.
    if ($cansandbox) {
        $baked = optional_param_array('bake', [], PARAM_INT);
        $now = time();
        $entries = $DB->get_records('local_oerexchange_pluginallowlist', null, '', 'id, bake');
        foreach ($entries as $entry) {
            $newbake = !empty($baked[$entry->id]) ? 1 : 0;
            if ((int) $entry->bake !== $newbake) {
                $DB->set_field('local_oerexchange_pluginallowlist', 'bake', $newbake, ['id' => $entry->id]);
                $DB->set_field('local_oerexchange_pluginallowlist', 'timemodified', $now, ['id' => $entry->id]);
            }
        }
    }
    redirect(new moodle_url('/local/oerexchange/manage_allowlist.php'));
}

if (optional_param('doadd', 0, PARAM_INT) && confirm_sesskey()) {
    $plugintype = required_param('plugintype', PARAM_ALPHA);
    $pluginname = required_param('pluginname', PARAM_ALPHANUMEXT);
    $moodlebranch = required_param('moodlebranch', PARAM_TEXT);
    $sourceurl = required_param('sourceurl', PARAM_URL);

    if (!allowlist_manager::is_valid_branch($moodlebranch)) {
        throw new moodle_exception('error_invalidbranch', 'local_oerexchange');
    }

    $now = time();
    $id = $DB->insert_record('local_oerexchange_pluginallowlist', (object) [
        'plugintype' => $plugintype,
        'pluginname' => $pluginname,
        'moodlebranch' => $moodlebranch,
        'sourceurl' => $sourceurl,
        'itemid' => null,
        'sha256' => null,
        'status' => 'active',
        'notes' => '',
        'timecreated' => $now,
        'timemodified' => $now,
    ]);

    if (!empty($_FILES['zipfile']['tmp_name']) && is_uploaded_file($_FILES['zipfile']['tmp_name'])) {
        $contents = file_get_contents($_FILES['zipfile']['tmp_name']);
        $fs = get_file_storage();
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'local_oerexchange',
            'filearea' => 'allowlist',
            'itemid' => $id,
            'filepath' => '/',
            'filename' => clean_param($_FILES['zipfile']['name'], PARAM_FILE) ?: 'plugin.zip',
        ];
        $fs->create_file_from_string($filerecord, $contents);
        $DB->set_field('local_oerexchange_pluginallowlist', 'itemid', $id, ['id' => $id]);
        $DB->set_field('local_oerexchange_pluginallowlist', 'sha256', hash('sha256', $contents), ['id' => $id]);
    }

    redirect(new moodle_url('/local/oerexchange/manage_allowlist.php'));
}

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('allowlistadd', 'local_oerexchange'), 3);
echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => new moodle_url('/local/oerexchange/manage_allowlist.php'),
    'enctype' => 'multipart/form-data',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'doadd', 'value' => 1]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::tag('label', get_string('allowlistplugintype', 'local_oerexchange'));
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'plugintype', 'class' => 'form-control mb-2',
    'placeholder' => 'mod', 'required' => 'required',
]);
echo html_writer::tag('label', get_string('allowlistpluginname', 'local_oerexchange'));
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'pluginname', 'class' => 'form-control mb-2', 'required' => 'required',
]);
echo html_writer::tag('label', get_string('allowlistbranch', 'local_oerexchange'));
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'moodlebranch', 'class' => 'form-control mb-2',
    'placeholder' => '5.2', 'required' => 'required',
]);
echo html_writer::tag('label', get_string('allowlistsourceurl', 'local_oerexchange'));
echo html_writer::empty_tag('input', [
    'type' => 'url', 'name' => 'sourceurl', 'class' => 'form-control mb-2', 'required' => 'required',
]);
echo html_writer::tag('label', get_string('allowlistupload', 'local_oerexchange'));
echo html_writer::empty_tag('input', ['type' => 'file', 'name' => 'zipfile', 'class' => 'form-control mb-2', 'accept' => '.zip']);
echo html_writer::empty_tag('input', [
    'type' => 'submit', 'value' => get_string('allowlistadd', 'local_oerexchange'), 'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('form');

$entries = $DB->get_records('local_oerexchange_pluginallowlist', null, 'moodlebranch, plugintype, pluginname');
if (empty($entries)) {
    echo html_writer::tag('p', get_string('allowlistempty', 'local_oerexchange'), ['class' => 'mt-3']);
} else {
    $sesskey = sesskey();

    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => new moodle_url('/local/oerexchange/manage_allowlist.php'),
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'savebake', 'value' => 1]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => $sesskey]);

    $table = new html_table();
    $table->head = [
        get_string('allowlistplugintype', 'local_oerexchange'),
        get_string('allowlistpluginname', 'local_oerexchange'),
        get_string('allowlistbranch', 'local_oerexchange'),
        get_string('sitestatus', 'local_oerexchange'),
        get_string('allowlistbake', 'local_oerexchange') . ' ' . $OUTPUT->help_icon('allowlistbake', 'local_oerexchange'),
        '',
    ];
    foreach ($entries as $e) {
        $toggleurl = new moodle_url('/local/oerexchange/manage_allowlist.php', ['toggleid' => $e->id, 'sesskey' => $sesskey]);
        $label = $e->status === 'active'
            ? get_string('allowlistdisable', 'local_oerexchange')
            : get_string('allowlistenable', 'local_oerexchange');
        // Rendered disabled (not hidden) for a user without managesandbox, and any
        // submitted value from such a user is ignored server-side above — a
        // disabled checkbox never submits a value at all, but the server-side
        // check is what actually enforces the gate against a forged request.
        $bakeattrs = [
            'type' => 'checkbox',
            'name' => 'bake[' . $e->id . ']',
            'value' => 1,
        ] + ($e->bake ? ['checked' => 'checked'] : []) + ($cansandbox ? [] : ['disabled' => 'disabled']);
        $bakecheckbox = html_writer::empty_tag('input', $bakeattrs);
        $table->data[] = [
            s($e->plugintype),
            s($e->pluginname),
            s($e->moodlebranch),
            s($e->status),
            $bakecheckbox,
            html_writer::link($toggleurl, $label, ['class' => 'btn btn-sm btn-outline-secondary']),
        ];
    }
    echo html_writer::table($table);

    if ($cansandbox) {
        echo html_writer::empty_tag('input', [
            'type' => 'submit', 'value' => get_string('savebakesettings', 'local_oerexchange'), 'class' => 'btn btn-primary',
        ]);
    }
    echo html_writer::end_tag('form');
}

echo $OUTPUT->footer();
