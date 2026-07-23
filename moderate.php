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
 * Moderation queue: open reports, failed parses.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/oerexchange:moderate', $context);

$PAGE->set_url('/local/oerexchange/moderate.php');
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('moderatetitle', 'local_oerexchange'));
$PAGE->set_heading(get_string('moderatetitle', 'local_oerexchange'));

$reportid = optional_param('reportid', 0, PARAM_INT);
$reportaction = optional_param('reportaction', '', PARAM_ALPHA);
$hideid = optional_param('hideid', 0, PARAM_INT);
$removeid = optional_param('removeid', 0, PARAM_INT);
$restoreid = optional_param('restoreid', 0, PARAM_INT);

if ($reportid && $reportaction && confirm_sesskey()) {
    $status = $reportaction === 'resolve' ? 'resolved' : 'dismissed';
    $DB->set_field('local_oerexchange_reports', 'status', $status, ['id' => $reportid]);
    $DB->set_field('local_oerexchange_reports', 'timeresolved', time(), ['id' => $reportid]);
    redirect(new moodle_url('/local/oerexchange/moderate.php'));
}
if ($hideid && confirm_sesskey()) {
    // Deliberately 'modhidden', NOT 'hidden'. 'hidden' is the author's own switch, and
    // resource_manager::set_hidden() lets an author flip that straight back to
    // 'published' — so writing 'hidden' here let an author silently undo a
    // moderator's takedown. A moderator hide is a separate state only a
    // moderator can lift, via the Restore action below.
    $DB->set_field('local_oerexchange_resources', 'status', 'modhidden', ['id' => $hideid]);
    redirect(new moodle_url('/local/oerexchange/moderate.php'));
}
if ($removeid && confirm_sesskey()) {
    $DB->set_field('local_oerexchange_resources', 'status', 'removed', ['id' => $removeid]);
    redirect(new moodle_url('/local/oerexchange/moderate.php'));
}
if ($restoreid && confirm_sesskey()) {
    // Until this existed, a moderator hide/remove was a one-way door: nothing
    // in this page could reverse it, and the only thing that ever did was the
    // author-unhide defect the 'modhidden' split above closes.
    $target = $DB->get_record('local_oerexchange_resources', ['id' => $restoreid], 'id,status', MUST_EXIST);
    if (in_array($target->status, ['modhidden', 'removed'], true)) {
        $DB->update_record('local_oerexchange_resources', (object) [
            'id' => $target->id,
            'status' => 'published',
            'timemodified' => time(),
        ]);
        \core\notification::success(get_string('resourcerestored', 'local_oerexchange'));
    }
    redirect(new moodle_url('/local/oerexchange/moderate.php'));
}

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('openreports', 'local_oerexchange'), 3);
$reports = $DB->get_records('local_oerexchange_reports', ['status' => 'open'], 'timecreated ASC');
if (empty($reports)) {
    echo html_writer::tag('p', get_string('noopenreports', 'local_oerexchange'));
} else {
    $table = new html_table();
    $table->head = [
        get_string('resourcetitle', 'local_oerexchange'),
        get_string('reporttype', 'local_oerexchange'),
        get_string('reportdetails', 'local_oerexchange'),
        '',
    ];
    foreach ($reports as $rep) {
        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $rep->resourceid]);
        $resurl = new moodle_url('/local/oerexchange/resource.php', ['id' => $rep->resourceid]);
        $sesskey = sesskey();
        $resolveurl = new moodle_url('/local/oerexchange/moderate.php', [
            'reportid' => $rep->id, 'reportaction' => 'resolve', 'sesskey' => $sesskey,
        ]);
        $dismissurl = new moodle_url('/local/oerexchange/moderate.php', [
            'reportid' => $rep->id, 'reportaction' => 'dismiss', 'sesskey' => $sesskey,
        ]);
        $hideurl = new moodle_url('/local/oerexchange/moderate.php', [
            'hideid' => $rep->resourceid, 'sesskey' => $sesskey,
        ]);
        $actions = html_writer::link(
            $resolveurl,
            get_string('resolvereport', 'local_oerexchange'),
            ['class' => 'btn btn-sm btn-success me-1']
        )
            . html_writer::link(
                $dismissurl,
                get_string('dismissreport', 'local_oerexchange'),
                ['class' => 'btn btn-sm btn-secondary me-1']
            )
            . html_writer::link(
                $hideurl,
                get_string('hideresource', 'local_oerexchange'),
                ['class' => 'btn btn-sm btn-outline-danger']
            );
        $table->data[] = [
            $resource ? html_writer::link($resurl, s($resource->title)) : '(deleted)',
            get_string('reporttype_' . $rep->type, 'local_oerexchange'),
            s($rep->details),
            $actions,
        ];
    }
    echo html_writer::table($table);
}

// Everything a moderator has taken down, and the only way back. Without this
// section a hide/remove could not be reversed from anywhere in the UI.
echo $OUTPUT->heading(get_string('moderatedresources', 'local_oerexchange'), 3);
[$insql, $inparams] = $DB->get_in_or_equal(['modhidden', 'removed'], SQL_PARAMS_NAMED, 'st');
$moderated = $DB->get_records_select(
    'local_oerexchange_resources',
    'status ' . $insql,
    $inparams,
    'timemodified DESC'
);
if (empty($moderated)) {
    echo html_writer::tag('p', get_string('nomoderatedresources', 'local_oerexchange'));
} else {
    $table = new html_table();
    $table->head = [
        get_string('resourcetitle', 'local_oerexchange'),
        get_string('sitestatus', 'local_oerexchange'),
        '',
    ];
    foreach ($moderated as $mod) {
        $resurl = new moodle_url('/local/oerexchange/resource.php', ['id' => $mod->id]);
        $restoreurl = new moodle_url('/local/oerexchange/moderate.php', [
            'restoreid' => $mod->id, 'sesskey' => sesskey(),
        ]);
        $table->data[] = [
            html_writer::link($resurl, s($mod->title)),
            get_string('resourcestatus_' . $mod->status, 'local_oerexchange'),
            html_writer::link(
                $restoreurl,
                get_string('resourcerestore', 'local_oerexchange'),
                ['class' => 'btn btn-sm btn-outline-success']
            ),
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->heading(get_string('failedparses', 'local_oerexchange'), 3);
$failed = $DB->get_records('local_oerexchange_versions', ['status' => 'failed'], 'timecreated DESC');
if (empty($failed)) {
    echo html_writer::tag('p', get_string('nofailedparses', 'local_oerexchange'));
} else {
    $table = new html_table();
    $table->head = [get_string('resourcetitle', 'local_oerexchange'), 'Error'];
    foreach ($failed as $v) {
        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $v->resourceid]);
        $resurl = new moodle_url('/local/oerexchange/resource.php', ['id' => $v->resourceid]);
        $table->data[] = [
            $resource ? html_writer::link($resurl, s($resource->title)) : '(deleted)',
            s($v->parseerror),
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
