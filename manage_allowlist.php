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
 * Adding an entry is a paste-and-confirm: the admin supplies a URL or a ZIP,
 * the package is read for its type, name, release, supported Moodle versions
 * and dependencies, and the resulting plan is shown before anything is
 * written. See classes/local/allowlist/ for the pipeline.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_oerexchange\form\allowlist_add_form;
use local_oerexchange\local\allowlist\dependency_walker;
use local_oerexchange\local\allowlist\directory_component_locator;
use local_oerexchange\local\allowlist\entry_plan;
use local_oerexchange\local\allowlist\ingest_plan;
use local_oerexchange\local\allowlist\ingestor;
use local_oerexchange\local\allowlist\moodle_http_fetcher;
use local_oerexchange\local\allowlist\resolution_exception;
use local_oerexchange\local\allowlist\source_resolver;
use local_oerexchange\local\allowlist\zip_inspector;

require(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/oerexchange:managesites', $context);
$cansandbox = has_capability('local/oerexchange:managesandbox', $context);

$pageurl = new moodle_url('/local/oerexchange/manage_allowlist.php');
$PAGE->set_url($pageurl);
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
    redirect($pageurl);
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
    redirect($pageurl);
}

$ingestor = new ingestor();

// Stage 2: the admin has seen the plan and pressed Add.
//
// Read as PARAM_RAW, not PARAM_INT: a named submit button submits its own
// LABEL as its value ("Add these entries"), which PARAM_INT quietly cleans to
// 0 — so the branch never fires and the confirmation silently does nothing.
// Presence of the parameter is the signal; sesskey is what makes it safe.
if (optional_param('confirm', '', PARAM_RAW) !== '' && confirm_sesskey()) {
    $pending = local_oerexchange_allowlist_pending_plan();
    if ($pending === null) {
        redirect(
            $pageurl,
            get_string('allowlistplanexpired', 'local_oerexchange'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // A user without managesandbox passes null, meaning "leave the bake flag
    // as it is" — they must not be able to clear a flag they cannot set.
    $bake = $cansandbox ? (bool) ($SESSION->local_oerexchange_allowlistbake ?? false) : null;
    $result = $ingestor->commit($pending, $bake);

    local_oerexchange_allowlist_forget_plan();

    redirect(
        $pageurl,
        get_string('allowlistadded', 'local_oerexchange', (object) [
            'rows' => $result->rows(),
            'plugins' => count($result->components),
        ]),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if (optional_param('cancel', '', PARAM_RAW) !== '' && confirm_sesskey()) {
    local_oerexchange_allowlist_forget_plan();
    redirect($pageurl);
}

$form = new allowlist_add_form($pageurl, ['cansandbox' => $cansandbox]);
$plan = null;
$resolveerror = null;

// Stage 1: a URL or an upload arrived. Resolve it, read it, and work out
// everything that would be written — but write nothing yet.
if ($data = $form->get_data()) {
    local_oerexchange_allowlist_forget_plan();

    // Persistent rather than a request directory: the downloaded packages
    // have to survive until the admin confirms on the next request.
    $token = random_string(20);
    $workdir = make_temp_directory('local_oerexchange/allowlist/' . $token);

    $resolver = new source_resolver(new moodle_http_fetcher());
    $inspector = new zip_inspector();

    try {
        $upload = $form->get_new_filename('zipfile');
        if ($upload !== false) {
            $path = $workdir . '/' . clean_param($upload, PARAM_FILE);
            $form->save_file('zipfile', $path, true);
            $resolved = $resolver->accept_local_file($path, $upload);
        } else {
            $resolved = $resolver->resolve($data->url, $workdir);
        }

        $meta = $inspector->inspect($resolved->zipfilepath, $workdir, $resolved->sourceurl);

        $walker = new dependency_walker($resolver, $inspector, new directory_component_locator());
        $plan = $ingestor->preview($walker->walk($meta, $workdir));

        $SESSION->local_oerexchange_allowlistplan = $plan;
        $SESSION->local_oerexchange_allowlistdir = $workdir;
        $SESSION->local_oerexchange_allowlistbake = $cansandbox && !empty($data->bake);
    } catch (resolution_exception $e) {
        remove_dir($workdir);
        $resolveerror = $e->getMessage();
    }
} else {
    // Returning to the page with a plan already pending (e.g. a reload).
    $plan = local_oerexchange_allowlist_pending_plan();
}

echo $OUTPUT->header();

if ($resolveerror !== null) {
    echo $OUTPUT->notification($resolveerror, \core\output\notification::NOTIFY_ERROR);
}

if ($plan !== null) {
    echo $OUTPUT->heading(get_string('allowlistplanheading', 'local_oerexchange'), 3);
    echo local_oerexchange_allowlist_render_plan($plan, $OUTPUT);

    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    if (!$plan->is_empty()) {
        echo html_writer::empty_tag('input', [
            'type' => 'submit', 'name' => 'confirm', 'value' => get_string('allowlistconfirm', 'local_oerexchange'),
            'class' => 'btn btn-primary me-2',
        ]);
    }
    echo html_writer::empty_tag('input', [
        'type' => 'submit', 'name' => 'cancel', 'value' => get_string('cancel'),
        'class' => 'btn btn-secondary',
    ]);
    echo html_writer::end_tag('form');
} else {
    echo $OUTPUT->heading(get_string('allowlistadd', 'local_oerexchange'), 3);
    $form->display();
}

echo local_oerexchange_allowlist_render_entries($cansandbox);

echo $OUTPUT->footer();

/**
 * The plan awaiting confirmation, if its downloaded packages are still there.
 *
 * @return ingest_plan|null
 */
function local_oerexchange_allowlist_pending_plan(): ?ingest_plan {
    global $SESSION;

    $plan = $SESSION->local_oerexchange_allowlistplan ?? null;
    $dir = $SESSION->local_oerexchange_allowlistdir ?? null;

    // Moodle's temp directories are periodically swept, so a plan left
    // overnight can outlive the packages it refers to. Committing then would
    // write rows whose mirrored ZIP is missing.
    if (!$plan instanceof ingest_plan || !$dir || !is_dir($dir)) {
        return null;
    }

    return $plan;
}

/**
 * Discard any pending plan and the packages downloaded for it.
 */
function local_oerexchange_allowlist_forget_plan(): void {
    global $SESSION;

    $dir = $SESSION->local_oerexchange_allowlistdir ?? null;
    if ($dir && is_dir($dir)) {
        remove_dir($dir);
    }

    unset($SESSION->local_oerexchange_allowlistplan);
    unset($SESSION->local_oerexchange_allowlistdir);
    unset($SESSION->local_oerexchange_allowlistbake);
}

/**
 * Render what confirming would do.
 *
 * @param ingest_plan $plan
 * @param core_renderer $output
 * @return string HTML
 */
function local_oerexchange_allowlist_render_plan(ingest_plan $plan, core_renderer $output): string {
    $table = new html_table();
    $table->head = [
        get_string('allowlistplugin', 'local_oerexchange'),
        get_string('allowlistbranch', 'local_oerexchange'),
        get_string('allowlistplanwhat', 'local_oerexchange'),
    ];

    foreach ($plan->entries as $entry) {
        $name = html_writer::tag('strong', s($entry->component));
        if ($entry->meta !== null && $entry->meta->release !== null) {
            $name .= ' ' . s($entry->meta->release);
        }
        if ($entry->role === entry_plan::DEPENDENCY) {
            $name .= html_writer::tag(
                'div',
                get_string('allowlistaddedasdependency', 'local_oerexchange', s($entry->parent)),
                ['class' => 'small text-muted']
            );
        }
        foreach ($entry->messages as $message) {
            $name .= html_writer::tag('div', s($message), ['class' => 'small text-warning']);
        }

        $row = new html_table_row([
            $name,
            $entry->branches ? s(implode(', ', $entry->branches->branches)) : '',
            get_string('allowlistaction_' . $entry->action, 'local_oerexchange'),
        ]);
        if ($entry->action === entry_plan::UNRESOLVED || $entry->action === entry_plan::NO_BRANCHES) {
            $row->attributes['class'] = 'table-warning';
        }
        $table->data[] = $row;
    }

    $html = html_writer::table($table);

    // Where the branch list came from matters: "the author says so" and "we
    // guessed from the minimum Moodle version" deserve different confidence.
    $primary = $plan->primary();
    if ($primary !== null && $primary->branches !== null) {
        $html .= $output->notification(
            get_string('allowlistconfidence_' . $primary->branches->confidence, 'local_oerexchange'),
            \core\output\notification::NOTIFY_INFO
        );
    }

    foreach ($plan->messages as $message) {
        $html .= $output->notification($message, \core\output\notification::NOTIFY_WARNING);
    }

    if ($plan->is_empty()) {
        $html .= $output->notification(
            get_string('allowlistnothingtoadd', 'local_oerexchange'),
            \core\output\notification::NOTIFY_ERROR
        );
    }

    return $html;
}

/**
 * The existing allowlist, with its bake checkboxes.
 *
 * @param bool $cansandbox whether the viewer may change bake flags
 * @return string HTML
 */
function local_oerexchange_allowlist_render_entries(bool $cansandbox): string {
    global $DB, $OUTPUT;

    $entries = $DB->get_records('local_oerexchange_pluginallowlist', null, 'moodlebranch, plugintype, pluginname');
    if (empty($entries)) {
        return html_writer::tag('p', get_string('allowlistempty', 'local_oerexchange'), ['class' => 'mt-3']);
    }

    $sesskey = sesskey();
    $pageurl = new moodle_url('/local/oerexchange/manage_allowlist.php');

    // For the "added as a dependency of" note: parentid points at another
    // row, and the admin cares which plugin that is, not which row id.
    $names = [];
    foreach ($entries as $entry) {
        $names[$entry->id] = $entry->component ?: ($entry->plugintype . '_' . $entry->pluginname);
    }

    $html = $OUTPUT->heading(get_string('allowlistcurrent', 'local_oerexchange'), 3);
    $html .= html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl]);
    $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'savebake', 'value' => 1]);
    $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => $sesskey]);

    $table = new html_table();
    $table->head = [
        get_string('allowlistplugin', 'local_oerexchange'),
        get_string('allowlistbranch', 'local_oerexchange'),
        get_string('sitestatus', 'local_oerexchange'),
        get_string('allowlistbake', 'local_oerexchange') . ' ' . $OUTPUT->help_icon('allowlistbake', 'local_oerexchange'),
        '',
    ];

    foreach ($entries as $e) {
        $toggleurl = new moodle_url($pageurl, ['toggleid' => $e->id, 'sesskey' => $sesskey]);
        $label = $e->status === 'active'
            ? get_string('allowlistdisable', 'local_oerexchange')
            : get_string('allowlistenable', 'local_oerexchange');

        $name = html_writer::tag('strong', s($e->component ?: $e->plugintype . '_' . $e->pluginname));
        if (!empty($e->pluginrelease)) {
            $name .= ' ' . s($e->pluginrelease);
        }
        if (!empty($e->parentid) && isset($names[$e->parentid])) {
            $name .= html_writer::tag(
                'div',
                get_string('allowlistaddedasdependency', 'local_oerexchange', s($names[$e->parentid])),
                ['class' => 'small text-muted']
            );
        }

        // Rendered disabled (not hidden) for a user without managesandbox, and any
        // submitted value from such a user is ignored server-side above — a
        // disabled checkbox never submits a value at all, but the server-side
        // check is what actually enforces the gate against a forged request.
        $bakeattrs = [
            'type' => 'checkbox',
            'name' => 'bake[' . $e->id . ']',
            'value' => 1,
        ] + ($e->bake ? ['checked' => 'checked'] : []) + ($cansandbox ? [] : ['disabled' => 'disabled']);

        $table->data[] = [
            $name,
            s($e->moodlebranch),
            s($e->status),
            html_writer::empty_tag('input', $bakeattrs),
            html_writer::link($toggleurl, $label, ['class' => 'btn btn-sm btn-outline-secondary']),
        ];
    }

    $html .= html_writer::table($table);

    if ($cansandbox) {
        $html .= html_writer::empty_tag('input', [
            'type' => 'submit', 'value' => get_string('savebakesettings', 'local_oerexchange'),
            'class' => 'btn btn-primary',
        ]);
    }
    $html .= html_writer::end_tag('form');

    return $html;
}
