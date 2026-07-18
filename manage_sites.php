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
 * Registered client sites: approve pending, revoke active.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_oerexchange\local\site_manager;

require(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/oerexchange:managesites', $context);

$PAGE->set_url('/local/oerexchange/manage_sites.php');
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('managesitestitle', 'local_oerexchange'));
$PAGE->set_heading(get_string('managesitestitle', 'local_oerexchange'));

$approveid = optional_param('approveid', 0, PARAM_INT);
$revokeid = optional_param('revokeid', 0, PARAM_INT);

if ($approveid && confirm_sesskey()) {
    $site = $DB->get_record('local_oerexchange_sites', ['id' => $approveid], '*', MUST_EXIST);
    site_manager::approve($approveid);
    \core\notification::success(get_string('approvesuccess', 'local_oerexchange', $site->contact));
    redirect(new moodle_url('/local/oerexchange/manage_sites.php'));
}
if ($revokeid && confirm_sesskey()) {
    site_manager::revoke($revokeid);
    \core\notification::success(get_string('revokesuccess', 'local_oerexchange'));
    redirect(new moodle_url('/local/oerexchange/manage_sites.php'));
}

echo $OUTPUT->header();

function oerexchange_render_site_table(array $sites, bool $showapprove, bool $showrevoke): void {
    global $OUTPUT;
    if (empty($sites)) {
        echo html_writer::tag('p', get_string('nositesyet', 'local_oerexchange'));
        return;
    }
    $table = new html_table();
    $table->head = [
        get_string('sitename', 'local_oerexchange'),
        get_string('siteurl', 'local_oerexchange'),
        get_string('sitecontact', 'local_oerexchange'),
        '',
    ];
    $sesskey = sesskey();
    foreach ($sites as $site) {
        $actions = '';
        if ($showapprove) {
            $url = new moodle_url('/local/oerexchange/manage_sites.php', ['approveid' => $site->id, 'sesskey' => $sesskey]);
            $actions .= html_writer::link($url, get_string('siteapprove', 'local_oerexchange'), ['class' => 'btn btn-sm btn-success']);
        }
        if ($showrevoke) {
            $url = new moodle_url('/local/oerexchange/manage_sites.php', ['revokeid' => $site->id, 'sesskey' => $sesskey]);
            $actions .= html_writer::link($url, get_string('siterevoke', 'local_oerexchange'), ['class' => 'btn btn-sm btn-outline-danger']);
        }
        $table->data[] = [s($site->name), s($site->url), s($site->contact), $actions];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->heading(get_string('pendingsites', 'local_oerexchange'), 3);
oerexchange_render_site_table(
    array_values($DB->get_records('local_oerexchange_sites', ['status' => 'pending'], 'timecreated ASC')),
    true,
    false
);

echo $OUTPUT->heading(get_string('activesites', 'local_oerexchange'), 3);
oerexchange_render_site_table(
    array_values($DB->get_records('local_oerexchange_sites', ['status' => 'active'], 'timemodified DESC')),
    false,
    true
);

echo $OUTPUT->heading(get_string('revokedsites', 'local_oerexchange'), 3);
oerexchange_render_site_table(
    array_values($DB->get_records('local_oerexchange_sites', ['status' => 'revoked'], 'timemodified DESC')),
    false,
    false
);

echo $OUTPUT->footer();
