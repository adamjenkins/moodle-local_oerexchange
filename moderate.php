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
$collectionhideid = optional_param('collectionhideid', 0, PARAM_INT);
$collectionrestoreid = optional_param('collectionrestoreid', 0, PARAM_INT);

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
    //
    // Only takedown-able states may be taken down: a 'deleted' tombstone
    // (scrubbed, files gone) or an already-moderated row must not be pulled
    // into the takedown/restore cycle, where Restore would "publish" a husk.
    $target = $DB->get_record('local_oerexchange_resources', ['id' => $hideid], 'id,status', MUST_EXIST);
    if (in_array($target->status, ['published', 'hidden', 'pending'], true)) {
        \local_oerexchange\local\moderation_report::record_takedown((int) $target->id, 'modhidden');
    }
    redirect(new moodle_url('/local/oerexchange/moderate.php'));
}
if ($removeid && confirm_sesskey()) {
    // Same status guard as the hide branch above.
    $target = $DB->get_record('local_oerexchange_resources', ['id' => $removeid], 'id,status', MUST_EXIST);
    if (in_array($target->status, ['published', 'hidden', 'pending'], true)) {
        \local_oerexchange\local\moderation_report::record_takedown((int) $target->id, 'removed');
    }
    redirect(new moodle_url('/local/oerexchange/moderate.php'));
}
if ($restoreid && confirm_sesskey()) {
    // Until this existed, a moderator hide/remove was a one-way door: nothing
    // in this page could reverse it, and the only thing that ever did was the
    // author-unhide defect the 'modhidden' split above closes.
    $target = $DB->get_record('local_oerexchange_resources', ['id' => $restoreid], '*', MUST_EXIST);
    if (in_array($target->status, \local_oerexchange\local\resource_manager::MODERATOR_HELD_STATUSES, true)) {
        if (\local_oerexchange\local\moderation_report::restore($target)) {
            \core\notification::success(get_string('resourcerestored', 'local_oerexchange'));
        } else {
            // Publishing a resource with nothing servable would list a husk in
            // the catalogue; say so instead of silently "restoring" it.
            \core\notification::warning(get_string('error_restorenoversion', 'local_oerexchange'));
        }
    }
    redirect(new moodle_url('/local/oerexchange/moderate.php'));
}
if ($collectionhideid && confirm_sesskey()) {
    // The collection equivalent of the hide branch above, and guarded the same
    // way: moderation_collections::take_down() re-reads the collection and
    // refuses a tombstone or an already-held collection, so a replayed link
    // cannot restamp the takedown date over the real one. A collection
    // takedown is a single flag rather than a status, so there is no
    // author-owned switch it can collide with — the owner's own draft/
    // unlisted/public setting is a separate column they keep control of, and
    // modhidden overrides it without being changed by it.
    if (\local_oerexchange\local\moderation_collections::take_down($collectionhideid, (int) $USER->id)) {
        \core\notification::success(get_string('collectionmodhidedone', 'local_oerexchange'));
    }
    redirect(new moodle_url('/local/oerexchange/moderate.php'));
}
if ($collectionrestoreid && confirm_sesskey()) {
    if (\local_oerexchange\local\moderation_collections::restore($collectionrestoreid)) {
        \core\notification::success(get_string('collectionmodrestoredone', 'local_oerexchange'));
    }
    redirect(new moodle_url('/local/oerexchange/moderate.php'));
}

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('openreports', 'local_oerexchange'), 3);
// Resource reports only, said explicitly. {local_oerexchange_reports} became
// polymorphic when collections became reportable (nullable resourceid, new
// nullable collectionid, exactly one of the two set), and the loop below joins
// every row to {local_oerexchange_resources} by resourceid — so without this
// filter a collection report would have listed here as a row whose resource
// "no longer exists", with a Hide button pointing at resource id nothing. The
// collection reports have their own section further down.
$reports = $DB->get_records_select(
    'local_oerexchange_reports',
    'status = :status AND resourceid IS NOT NULL',
    ['status' => 'open'],
    'timecreated ASC'
);
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
            $resource ? html_writer::link($resurl, format_string($resource->title, true, ['context' => $context]))
                : get_string('resourcedeletedplaceholder', 'local_oerexchange'),
            get_string('reporttype_' . $rep->type, 'local_oerexchange'),
            // Deliberately s(), NOT format_string(): a report's details are
            // moderation *evidence*, and a moderator must see exactly what the
            // reporter typed. Running the string filters here would let a
            // reporter hide text from the moderator inside a multilang span
            // for a language the moderator is not viewing in.
            s($rep->details),
            $actions,
        ];
    }
    echo html_writer::table($table);
}

// Collections. A separate section rather than extra rows in the table above:
// the two kinds of report lead to different actions (a resource takedown is a
// status with an author-owned switch beside it, a collection takedown is a
// moderator-only flag), and the resource table's columns and Hide link are all
// built from a resourceid these rows do not have.
//
// Deliberately NOT gated on the collectionsenabled setting, which the public
// pages do check. Turning collections off hides the feature; it does not
// resolve the reports already filed or release the collections a moderator is
// holding, and a queue that quietly stopped showing outstanding work would be
// the worst possible reading of an admin switch.
echo $OUTPUT->heading(get_string('moderatecollections', 'local_oerexchange'), 3);
$collectionreports = \local_oerexchange\local\moderation_collections::open_reports();
$heldcollections = \local_oerexchange\local\moderation_collections::held_collections();
if (!$collectionreports && !$heldcollections) {
    echo html_writer::tag('p', get_string('moderatecollectionsnone', 'local_oerexchange'));
}
if ($collectionreports) {
    echo $OUTPUT->heading(get_string('moderatecollectionsopen', 'local_oerexchange'), 4);
    $table = new html_table();
    $table->head = [
        get_string('collection', 'local_oerexchange'),
        get_string('reporttype', 'local_oerexchange'),
        get_string('reportdetails', 'local_oerexchange'),
        '',
    ];
    foreach ($collectionreports as $rep) {
        $sesskey = sesskey();
        $resolveurl = new moodle_url('/local/oerexchange/moderate.php', [
            'reportid' => $rep->id, 'reportaction' => 'resolve', 'sesskey' => $sesskey,
        ]);
        $dismissurl = new moodle_url('/local/oerexchange/moderate.php', [
            'reportid' => $rep->id, 'reportaction' => 'dismiss', 'sesskey' => $sesskey,
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
            );
        // Which takedown action is offered is decided from the collection's
        // own row, not from the report: a collection that is already held
        // needs Restore, and a tombstone needs neither. The handlers at the
        // top of this file re-derive the same thing, so a stale link from a
        // page left open does nothing rather than something wrong.
        $isactive = (string) $rep->collectionstatus === \local_oerexchange\local\collection_manager::STATUS_ACTIVE;
        if ($isactive && empty($rep->collectionmodhidden)) {
            $actions .= html_writer::link(
                new moodle_url('/local/oerexchange/moderate.php', [
                    'collectionhideid' => $rep->collectionid, 'sesskey' => $sesskey,
                ]),
                get_string('collectionmodhide', 'local_oerexchange'),
                ['class' => 'btn btn-sm btn-outline-danger']
            );
        } else if ($isactive) {
            $actions .= html_writer::link(
                new moodle_url('/local/oerexchange/moderate.php', [
                    'collectionrestoreid' => $rep->collectionid, 'sesskey' => $sesskey,
                ]),
                get_string('collectionmodrestore', 'local_oerexchange'),
                ['class' => 'btn btn-sm btn-outline-success']
            );
        }
        $table->data[] = [
            \local_oerexchange\local\moderation_collections::render_title_cell(
                $rep->collectiontitle,
                $rep->collectionslug,
                (string) $rep->collectionstatus,
                !empty($rep->collectionmodhidden),
                $context
            ),
            get_string('reporttype_' . $rep->type, 'local_oerexchange'),
            // Escaped with s(), not format_string(), for the same reason as the resource
            // table above: the reporter's own words are the evidence.
            s($rep->details),
            $actions,
        ];
    }
    echo html_writer::table($table);
}
if ($heldcollections) {
    echo $OUTPUT->heading(get_string('moderatecollectionsheld', 'local_oerexchange'), 4);
    $table = new html_table();
    $table->head = [
        get_string('collection', 'local_oerexchange'),
        get_string('moderatecollectionsheldwhen', 'local_oerexchange'),
        '',
    ];
    foreach ($heldcollections as $held) {
        $restoreurl = new moodle_url('/local/oerexchange/moderate.php', [
            'collectionrestoreid' => $held->id, 'sesskey' => sesskey(),
        ]);
        // The name from fullname() is unescaped and get_string() does
        // not escape its placeholders, so the name is escaped here — the same
        // three-functions-in-a-row trap moderate_hidden.php documents.
        $when = empty($held->modhiddentime)
            ? get_string('hiddenwhenunknown', 'local_oerexchange')
            : get_string('hiddenwhen', 'local_oerexchange', (object) [
                'when' => userdate($held->modhiddentime),
                'who' => $held->modhiddenbyuser
                    ? s(fullname($held->modhiddenbyuser))
                    : get_string('hiddenbyunknown', 'local_oerexchange'),
            ]);
        $table->data[] = [
            \local_oerexchange\local\moderation_collections::render_title_cell(
                $held->title,
                $held->slug,
                (string) $held->status,
                true,
                $context
            ),
            $when,
            html_writer::link(
                $restoreurl,
                get_string('collectionmodrestore', 'local_oerexchange'),
                ['class' => 'btn btn-sm btn-outline-success']
            ),
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
            html_writer::link($resurl, format_string($mod->title, true, ['context' => $context])),
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
    $table->head = [
        get_string('resourcetitle', 'local_oerexchange'),
        get_string('failedparseerror', 'local_oerexchange'),
    ];
    foreach ($failed as $v) {
        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $v->resourceid]);
        $resurl = new moodle_url('/local/oerexchange/resource.php', ['id' => $v->resourceid]);
        $table->data[] = [
            $resource ? html_writer::link($resurl, format_string($resource->title, true, ['context' => $context]))
                : get_string('resourcedeletedplaceholder', 'local_oerexchange'),
            // Moderators see the full stored text — only the internal
            // author-safe marker is stripped, since it is plumbing rather
            // than part of the message.
            s(\local_oerexchange\local\resource_manager::raw_parse_error($v->parseerror)),
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
