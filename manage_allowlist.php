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
use local_oerexchange\local\allowlist\branch_editor;
use local_oerexchange\local\allowlist\dependency_walker;
use local_oerexchange\local\allowlist\directory_component_locator;
use local_oerexchange\local\allowlist\entry_plan;
use local_oerexchange\local\allowlist\ingest_plan;
use local_oerexchange\local\allowlist\ingestor;
use local_oerexchange\local\allowlist\moodle_http_fetcher;
use local_oerexchange\local\allowlist\resolution_exception;
use local_oerexchange\local\allowlist\source_resolver;
use local_oerexchange\local\allowlist\zip_inspector;
use local_oerexchange\local\sandbox\playground;

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
$brancheditor = new branch_editor();

// Listing an already-allowlisted plugin for one more branch. Copies the
// release already pinned for it rather than resolving a new one: the admin
// reviewed that ZIP, and "also offer it on 5.0" must not quietly swap it.
$addbranchfor = optional_param('addbranchfor', '', PARAM_COMPONENT);
if ($addbranchfor !== '' && confirm_sesskey()) {
    $branch = optional_param('addbranch', '', PARAM_RAW_TRIMMED);
    // Passing $cansandbox rather than true: inheriting the bake flag would let
    // a user who cannot tick that box extend a baked plugin onto another branch, which
    // changes what the next bundle build ships — the thing the capability
    // exists to gate. They can still add the branch; it simply lands unbaked.
    $result = $brancheditor->add_branch($addbranchfor, $branch, $cansandbox);

    // Mapped explicitly rather than interpolating $result into the key: a
    // string built at runtime is invisible to grep and to the lang tooling,
    // which is how an untranslated key reaches a user.
    [$stringid, $notify] = match ($result) {
        branch_editor::ADDED => ['allowlistbranchadded', \core\output\notification::NOTIFY_SUCCESS],
        branch_editor::EXISTS => ['allowlistbranchexists', \core\output\notification::NOTIFY_INFO],
        default => ['allowlistbranchnosource', \core\output\notification::NOTIFY_ERROR],
    };
    redirect(
        $pageurl,
        get_string($stringid, 'local_oerexchange', (object) [
            'plugin' => s($addbranchfor),
            'branch' => s($branch),
        ]),
        null,
        $notify
    );
}

// Offering everything already on one branch on another one — the "5.3 just
// came out" operation. Unlike add_branch above, each plugin is re-resolved
// from the plugins directory for the target branch, then shown in the same
// preview-and-confirm as a manual add. Nothing is written here.
$rolloverfrom = optional_param('rolloverfrom', '', PARAM_RAW_TRIMMED);
$rolloverto = optional_param('rolloverto', '', PARAM_RAW_TRIMMED);
if ($rolloverfrom !== '' && $rolloverto !== '' && confirm_sesskey()) {
    if ($rolloverfrom === $rolloverto) {
        redirect(
            $pageurl,
            get_string('allowlistrolloversamebranch', 'local_oerexchange'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    local_oerexchange_allowlist_forget_plan();

    // Persistent, not a request directory: the downloaded packages have to
    // survive until the admin confirms on the next request — same contract as
    // the manual add above.
    $workdir = make_temp_directory('local_oerexchange/allowlist/' . random_string(20));
    $plan = $ingestor->preview($brancheditor->rollover_plan($rolloverfrom, $rolloverto, $workdir));

    if ($plan->is_empty() && empty($plan->entries)) {
        remove_dir($workdir);
        redirect(
            $pageurl,
            get_string('allowlistrollovernothing', 'local_oerexchange', (object) [
                'from' => s($rolloverfrom),
                'to' => s($rolloverto),
            ]),
            null,
            \core\output\notification::NOTIFY_INFO
        );
    }

    $SESSION->local_oerexchange_allowlistplan = $plan;
    $SESSION->local_oerexchange_allowlistdir = $workdir;
    // Bake is per branch and the admin has said nothing about it here, so the
    // rows land unbaked rather than inheriting a flag from another branch.
    $SESSION->local_oerexchange_allowlistbake = false;
    redirect($pageurl);
}

// Deleting an entry throws away its mirrored ZIP as well as the row, so it
// asks first. Disabling, the reversible option, stays a one-click toggle.
$deleteid = optional_param('deleteid', 0, PARAM_INT);
if ($deleteid) {
    $entry = $DB->get_record('local_oerexchange_pluginallowlist', ['id' => $deleteid], '*', MUST_EXIST);
    $name = $entry->component ?: ($entry->plugintype . '_' . $entry->pluginname);

    if (optional_param('confirmdelete', 0, PARAM_INT)) {
        // Deliberately require_sesskey(), not confirm_sesskey(): the latter RETURNS false
        // rather than throwing, so combining it into this condition would send
        // a stale or forged request quietly back to the confirmation screen —
        // indistinguishable, to whoever sent it, from never having asked. A
        // request that deletes data should fail loudly when it fails.
        require_sesskey();

        $removed = $ingestor->delete((int) $entry->id);
        redirect(
            $pageurl,
            get_string('allowlistdeleted', 'local_oerexchange', s((string) $removed)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    $message = get_string('allowlistdeleteconfirm', 'local_oerexchange', (object) [
        'plugin' => s($name),
        'branch' => s($entry->moodlebranch),
    ]);
    $dependents = $ingestor->count_dependents((int) $entry->id);
    if ($dependents > 0) {
        $message .= html_writer::empty_tag('br') . html_writer::empty_tag('br')
            . get_string('allowlistdeletedependents', 'local_oerexchange', $dependents);
    }

    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        $message,
        new moodle_url($pageurl, ['deleteid' => $entry->id, 'confirmdelete' => 1, 'sesskey' => sesskey()]),
        $pageurl
    );
    echo $OUTPUT->footer();
    die;
}

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

        $walker = new dependency_walker(
            $resolver,
            $inspector,
            new directory_component_locator(),
            null,
            !empty($data->ignoresupported)
        );
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
    // Escaped because this message is plain text that interpolates the URL
    // the admin just typed (error_allowlistdownloadfailed), and get_string()
    // does not escape its $a. Not load-bearing against XSS on current Moodle —
    // \core\output\notification::export_for_template() runs the message
    // through clean_text() before the template's {{{ }}} sees it
    // (lib/classes/output/notification.php:198), verified by rendering a
    // script-tag URL through this page with and without this call. It is here
    // because the template's own docblock asks for an already-cleaned string,
    // and clean_text() sanitises HTML rather than escaping text.
    echo $OUTPUT->notification(s($resolveerror), \core\output\notification::NOTIFY_ERROR);
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
echo local_oerexchange_allowlist_render_rollover();

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
        // Name the branches being added against the plugin's own word, rather
        // than only saying that something was overridden.
        if ($entry->branches !== null && $entry->branches->declined !== []) {
            $name .= html_writer::tag(
                'div',
                get_string(
                    'allowlistoverriddenbranches',
                    'local_oerexchange',
                    s(implode(', ', $entry->branches->declined))
                ),
                ['class' => 'small text-warning']
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
        // Same reasoning as the resolve error above: plain text, so escape it
        // rather than leaving it to clean_text()'s HTML sanitising.
        $html .= $output->notification(s($message), \core\output\notification::NOTIFY_WARNING);
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

    // Grouped by plugin, not by row: the table stores one row per plugin per
    // branch because each carries its own mirrored ZIP and its own bake flag,
    // but an admin reads the list as "this plugin, these branches". Status,
    // bake and the override warning stay per branch inside the cell, because
    // they genuinely differ per branch — config::render() emits
    // BAKE_PLUGINS_<branch>, so flattening the checkbox would silently change
    // what ships in a bundle.
    $grouped = [];
    foreach ($entries as $e) {
        $grouped[$e->component ?: $e->plugintype . '_' . $e->pluginname][] = $e;
    }

    $branchorder = array_flip(playground::DEPLOYED_BRANCHES);
    $brancheditor = new branch_editor();

    $html = $OUTPUT->heading(get_string('allowlistcurrent', 'local_oerexchange'), 3);
    $html .= html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl]);
    $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'savebake', 'value' => 1]);
    $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => $sesskey]);

    $table = new html_table();
    $table->head = [
        get_string('allowlistplugin', 'local_oerexchange'),
        get_string('allowlistbranches', 'local_oerexchange') . ' '
            . $OUTPUT->help_icon('allowlistbake', 'local_oerexchange'),
        get_string('allowlistaddbranch', 'local_oerexchange'),
    ];

    foreach ($grouped as $component => $rows) {
        usort($rows, static function ($a, $b) use ($branchorder): int {
            return ($branchorder[$a->moodlebranch] ?? PHP_INT_MAX) <=> ($branchorder[$b->moodlebranch] ?? PHP_INT_MAX);
        });

        $releases = array_values(array_unique(array_filter(array_map(
            static fn($r) => (string) $r->pluginrelease,
            $rows
        ))));

        $name = html_writer::tag('strong', s($component));
        // One release for every branch is the normal case and belongs beside
        // the name; differing releases are shown per branch below instead of
        // being averaged into something untrue.
        if (count($releases) === 1) {
            $name .= ' ' . s($releases[0]);
        }
        foreach ($rows as $e) {
            if (!empty($e->parentid) && isset($names[$e->parentid])) {
                $name .= html_writer::tag(
                    'div',
                    get_string('allowlistaddedasdependency', 'local_oerexchange', s($names[$e->parentid])),
                    ['class' => 'small text-muted']
                );
                break;
            }
        }

        $chips = '';
        foreach ($rows as $e) {
            $toggleurl = new moodle_url($pageurl, ['toggleid' => $e->id, 'sesskey' => $sesskey]);
            $togglelabel = $e->status === 'active'
                ? get_string('allowlistdisable', 'local_oerexchange')
                : get_string('allowlistenable', 'local_oerexchange');
            $deleteurl = new moodle_url($pageurl, ['deleteid' => $e->id, 'sesskey' => $sesskey]);

            // Rendered disabled (not hidden) for a user without managesandbox, and any
            // submitted value from such a user is ignored server-side above — a
            // disabled checkbox never submits a value at all, but the server-side
            // check is what actually enforces the gate against a forged request.
            $bakeid = 'bake-' . $e->id;
            $bakeattrs = [
                'type' => 'checkbox',
                'name' => 'bake[' . $e->id . ']',
                'id' => $bakeid,
                'value' => 1,
                'class' => 'me-1',
            ] + ($e->bake ? ['checked' => 'checked'] : []) + ($cansandbox ? [] : ['disabled' => 'disabled']);

            $chip = html_writer::tag('span', s($e->moodlebranch), [
                'class' => 'badge bg-light text-dark border me-2',
            ]);
            if (count($releases) > 1 && !empty($e->pluginrelease)) {
                $chip .= html_writer::tag('span', s($e->pluginrelease), ['class' => 'small text-muted me-2']);
            }
            if ($e->status !== 'active') {
                $chip .= html_writer::tag('span', s($e->status), ['class' => 'small text-muted me-2']);
            }
            $chip .= html_writer::empty_tag('input', $bakeattrs)
                . html_writer::tag('label', get_string('allowlistbake', 'local_oerexchange'), [
                    'for' => $bakeid,
                    'class' => 'small me-2',
                ]);
            $chip .= html_writer::link($toggleurl, $togglelabel, [
                'class' => 'btn btn-sm btn-outline-secondary me-1',
            ]);
            $chip .= html_writer::link($deleteurl, get_string('allowlistremovebranch', 'local_oerexchange'), [
                'class' => 'btn btn-sm btn-outline-danger',
                // The button says "Remove" for every branch, so the branch it
                // removes has to be in the accessible name.
                'aria-label' => get_string('allowlistremovebrancharia', 'local_oerexchange', (object) [
                    'plugin' => s($component),
                    'branch' => s($e->moodlebranch),
                ]),
            ]);

            // Without this, a row listing a plugin for a branch its own
            // version.php disowns reads as a bug rather than a decision.
            if ($e->notes === ingestor::NOTE_OVERRIDDEN) {
                $chip .= html_writer::tag(
                    'div',
                    get_string('allowlistoverriddennote', 'local_oerexchange'),
                    ['class' => 'small text-warning']
                );
            }

            $chips .= html_writer::tag('div', $chip, ['class' => 'd-flex align-items-center flex-wrap mb-1']);
        }

        // Plain links, not a select inside a button: this cell sits inside the
        // bake form, and a nested <form> is invalid HTML that browsers silently
        // drop — taking the bake checkboxes with it.
        $add = '';
        foreach ($brancheditor->addable_branches($component) as $branch) {
            $add .= html_writer::link(
                new moodle_url($pageurl, [
                    'addbranchfor' => $component,
                    'addbranch' => $branch,
                    'sesskey' => $sesskey,
                ]),
                '+ ' . s($branch),
                [
                    'class' => 'btn btn-sm btn-outline-primary me-1',
                    'aria-label' => get_string('allowlistaddbrancharia', 'local_oerexchange', (object) [
                        'plugin' => s($component),
                        'branch' => s($branch),
                    ]),
                ]
            );
        }
        if ($add === '') {
            $add = html_writer::tag('span', get_string('allowlistallbranches', 'local_oerexchange'), [
                'class' => 'small text-muted',
            ]);
        }

        $table->data[] = [$name, $chips, $add];
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

/**
 * The "offer everything from one branch on another" control.
 *
 * Separate from the entries table's form on purpose: that form posts the bake
 * checkboxes, and a nested <form> is invalid HTML browsers drop silently.
 *
 * @return string
 */
function local_oerexchange_allowlist_render_rollover(): string {
    global $DB, $OUTPUT;

    $branches = playground::DEPLOYED_BRANCHES;
    if (count($branches) < 2) {
        // One deployed branch: there is nowhere to roll anything over to, and
        // an empty pair of selects would only puzzle the reader.
        return '';
    }

    $counts = $DB->get_records_sql(
        'SELECT moodlebranch, COUNT(DISTINCT component) AS plugins
           FROM {local_oerexchange_pluginallowlist}
       GROUP BY moodlebranch'
    );

    $options = [];
    foreach ($branches as $branch) {
        $listed = isset($counts[$branch]) ? (int) $counts[$branch]->plugins : 0;
        $options[$branch] = get_string('allowlistrolloveroption', 'local_oerexchange', (object) [
            'branch' => $branch,
            'plugins' => $listed,
        ]);
    }

    // Defaults matching the common case: copy the branch carrying the most
    // plugins onto the newest deployed one, which is the branch that has just
    // appeared and is therefore empty.
    $newest = (string) end($branches);
    $fullest = $newest;
    $most = -1;
    foreach ($branches as $branch) {
        $listed = isset($counts[$branch]) ? (int) $counts[$branch]->plugins : 0;
        if ($branch !== $newest && $listed >= $most + 1) {
            $most = $listed;
            $fullest = $branch;
        }
    }

    $pageurl = new moodle_url('/local/oerexchange/manage_allowlist.php');

    $html = $OUTPUT->heading(get_string('allowlistrolloverheading', 'local_oerexchange'), 3);
    $html .= html_writer::tag('p', get_string('allowlistrolloverintro', 'local_oerexchange'), ['class' => 'text-muted']);
    $html .= html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl, 'class' => 'form-inline']);
    $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $html .= html_writer::label(
        get_string('allowlistrolloverfrom', 'local_oerexchange'),
        'rolloverfrom',
        true,
        ['class' => 'me-1']
    );
    $html .= html_writer::select($options, 'rolloverfrom', $fullest, false, ['id' => 'rolloverfrom', 'class' => 'me-2']);
    $html .= html_writer::label(
        get_string('allowlistrolloverto', 'local_oerexchange'),
        'rolloverto',
        true,
        ['class' => 'me-1']
    );
    $html .= html_writer::select($options, 'rolloverto', $newest, false, ['id' => 'rolloverto', 'class' => 'me-2']);
    $html .= html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('allowlistrolloversubmit', 'local_oerexchange'),
        'class' => 'btn btn-secondary',
    ]);
    $html .= html_writer::end_tag('form');

    return $html;
}
