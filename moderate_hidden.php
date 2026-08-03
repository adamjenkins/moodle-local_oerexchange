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
 * Moderator-only report: every resource a moderator is currently holding.
 *
 * The moderation queue proper is about incoming work — open reports and failed
 * parses. This is the standing list of what has already been taken down, which
 * nothing showed before: a takedown recorded only a status, so there was no way
 * to see when it happened, who did it, or whether the author has changed the
 * resource since.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_login();

use local_oerexchange\local\moderation_report;
use local_oerexchange\local\resource_type;

$context = context_system::instance();
require_capability('local/oerexchange:moderate', $context);

$PAGE->set_url('/local/oerexchange/moderate_hidden.php');
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('hiddenreporttitle', 'local_oerexchange'));
$PAGE->set_heading(get_string('hiddenreporttitle', 'local_oerexchange'));

$noteid = optional_param('noteid', 0, PARAM_INT);

if ($noteid) {
    // Use require_sesskey(), not confirm_sesskey(): the latter returns
    // false rather than throwing, which would send a stale submission
    // silently back to the list as though it had saved.
    require_sesskey();
    $note = optional_param('note', '', PARAM_TEXT);
    // Prove the target is really a held resource before writing a note
    // against its id — the id arrives from the client like any other.
    $target = $DB->get_record('local_oerexchange_resources', ['id' => $noteid], 'id,status', MUST_EXIST);
    if ($target->status === 'modhidden') {
        moderation_report::save_note((int) $target->id, $note);
        \core\notification::success(get_string('modnotesaved', 'local_oerexchange'));
    }
    redirect(new moodle_url('/local/oerexchange/moderate_hidden.php'));
}

echo $OUTPUT->header();

$resources = moderation_report::hidden_resources();

if (!$resources) {
    echo html_writer::tag('p', get_string('nohiddenresources', 'local_oerexchange'));
    echo html_writer::link(
        new moodle_url('/local/oerexchange/moderate.php'),
        get_string('moderatetitle', 'local_oerexchange'),
        ['class' => 'btn btn-outline-secondary btn-sm']
    );
    echo $OUTPUT->footer();
    die;
}

echo html_writer::tag('p', get_string('hiddenreportintro', 'local_oerexchange'));

foreach ($resources as $r) {
    $resourceurl = new moodle_url('/local/oerexchange/resource.php', ['id' => $r->id]);

    echo html_writer::start_tag('div', ['class' => 'card mb-3']);
    echo html_writer::start_tag('div', ['class' => 'card-body']);

    // Filtered with format_string(), not s(): a multilang title must collapse to one
    // language here exactly as it does on the catalogue, rather than showing
    // literal <span> markup to the moderator.
    $title = format_string($r->title, true, ['context' => $context]);
    echo html_writer::tag('h5', html_writer::link($resourceurl, $title), ['class' => 'card-title']);

    echo html_writer::tag('span', resource_type::label($r), ['class' => 'badge bg-secondary me-1']);

    // Whether the author has touched it since the takedown — the question a
    // moderator revisiting a held resource actually has.
    $changed = $r->changedsincehidden;
    if ($changed === null) {
        echo html_writer::tag(
            'span',
            get_string('hiddenchangedunknown', 'local_oerexchange'),
            ['class' => 'badge bg-light text-dark me-1']
        );
    } else if ($changed) {
        echo html_writer::tag(
            'span',
            get_string('hiddenchangedyes', 'local_oerexchange'),
            ['class' => 'badge bg-warning text-dark me-1']
        );
    } else {
        echo html_writer::tag(
            'span',
            get_string('hiddenchangedno', 'local_oerexchange'),
            ['class' => 'badge bg-light text-dark me-1']
        );
    }

    // Who took it down and when. Both are unknown for a resource hidden
    // before this record existed, and the report says so rather than
    // rendering "1 January 1970".
    if (!empty($r->modhiddentime)) {
        $hiddenby = $r->modhiddenby
            ? $DB->get_record('user', ['id' => $r->modhiddenby], 'id, firstname, lastname')
            : null;
        echo html_writer::tag('p', get_string(
            'hiddenwhen',
            'local_oerexchange',
            (object) [
                'when' => userdate($r->modhiddentime),
                'who' => $hiddenby ? fullname($hiddenby) : get_string('hiddenbyunknown', 'local_oerexchange'),
            ]
        ), ['class' => 'small text-muted mb-2']);
    } else {
        echo html_writer::tag(
            'p',
            get_string('hiddenwhenunknown', 'local_oerexchange'),
            ['class' => 'small text-muted mb-2']
        );
    }

    // The note: a single shared scratchpad per resource that every moderator
    // sees, edited in place.
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => new moodle_url('/local/oerexchange/moderate_hidden.php'),
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'noteid', 'value' => $r->id]);
    echo html_writer::tag(
        'label',
        get_string('modnotelabel', 'local_oerexchange'),
        ['for' => 'oerexchange-modnote-' . $r->id, 'class' => 'form-label']
    );
    echo html_writer::tag('textarea', s($r->modnote), [
        'name' => 'note',
        'id' => 'oerexchange-modnote-' . $r->id,
        'rows' => 3,
        'class' => 'form-control mb-2',
    ]);
    if ($r->modnotetime) {
        $noteby = $r->modnoteby
            ? $DB->get_record('user', ['id' => $r->modnoteby], 'id, firstname, lastname')
            : null;
        echo html_writer::tag('p', get_string(
            'modnotelastsaved',
            'local_oerexchange',
            (object) [
                'when' => userdate($r->modnotetime),
                'who' => $noteby ? fullname($noteby) : get_string('hiddenbyunknown', 'local_oerexchange'),
            ]
        ), ['class' => 'small text-muted']);
    }
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('modnotesave', 'local_oerexchange'),
        'class' => 'btn btn-primary btn-sm me-2',
    ]);
    echo html_writer::end_tag('form');

    echo html_writer::link(
        new moodle_url('/local/oerexchange/moderate.php'),
        get_string('moderatetitle', 'local_oerexchange'),
        ['class' => 'btn btn-outline-secondary btn-sm mt-2']
    );

    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
}

echo $OUTPUT->footer();
