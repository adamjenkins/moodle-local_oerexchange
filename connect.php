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
 * Account-linking handshake, Exchange side: the client redirects the teacher
 * here; after they log in/sign up (normal Moodle auth) and confirm the link,
 * we issue a one-time code and redirect back to the client's callback. See
 * DESIGN.md §1.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_oerexchange\local\link_manager;

require(__DIR__ . '/../../config.php');

$siteid = required_param('siteid', PARAM_INT);
$callback = required_param('callback', PARAM_URL);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$PAGE->set_url('/local/oerexchange/connect.php', ['siteid' => $siteid, 'callback' => $callback]);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('login');
$PAGE->set_title(get_string('connecttitle', 'local_oerexchange'));

$site = $DB->get_record('local_oerexchange_sites', ['id' => $siteid, 'status' => 'active']);
if (!$site) {
    throw new moodle_exception('error_sitenotactive', 'local_oerexchange');
}

// Prevent an open redirect: the callback must be the registered site's own
// local_oerclient callback, on the same scheme, host and port — see
// link_manager::callback_matches_site().
if (!link_manager::callback_matches_site($site->url, $callback)) {
    throw new moodle_exception('error_sitenotactive', 'local_oerexchange');
}

require_login(null, false);

if (isguestuser()) {
    // A guest session isn't a real account — send them through login again.
    redirect(get_login_url());
}

// Linking hands the site a permanent token that publishes as this user, so
// it needs the user's say-so: a plain GET only asks. Before this, any link,
// redirect or same-site <img> pointing here linked a logged-in user's
// account to the site silently.
if (!$confirm) {
    $continueurl = new moodle_url('/local/oerexchange/connect.php', [
        'siteid' => $siteid,
        'callback' => $callback,
        'confirm' => 1,
    ]);
    $continue = new single_button($continueurl, get_string('connectallow', 'local_oerexchange'), 'post');
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('connectconfirm', 'local_oerexchange', (object) [
            'name' => s($site->name),
            'url' => s($site->url),
        ]),
        $continue,
        new moodle_url('/')
    );
    echo $OUTPUT->footer();
    die();
}
require_sesskey();

$code = link_manager::issue_code($siteid, (int) $USER->id);

$separator = str_contains($callback, '?') ? '&' : '?';
redirect(new moodle_url($callback . $separator . 'linkcode=' . $code));
