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
 * here; after they log in/sign up (normal Moodle auth), we mint a one-time
 * code and redirect back to the client's callback. See DESIGN.md §1.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_oerexchange\local\link_manager;

require(__DIR__ . '/../../config.php');

$siteid = required_param('siteid', PARAM_INT);
$callback = required_param('callback', PARAM_URL);

$PAGE->set_url('/local/oerexchange/connect.php', ['siteid' => $siteid, 'callback' => $callback]);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('login');
$PAGE->set_title(get_string('connecttitle', 'local_oerexchange'));

$site = $DB->get_record('local_oerexchange_sites', ['id' => $siteid, 'status' => 'active']);
if (!$site) {
    throw new moodle_exception('error_sitenotactive', 'local_oerexchange');
}

// Prevent an open redirect: the callback must point back at the registered site.
$sitehost = parse_url($site->url, PHP_URL_HOST);
$callbackhost = parse_url($callback, PHP_URL_HOST);
if (!$sitehost || !$callbackhost || strcasecmp($sitehost, $callbackhost) !== 0) {
    throw new moodle_exception('error_sitenotactive', 'local_oerexchange');
}

require_login(null, false);

if (isguestuser()) {
    // A guest session isn't a real account — send them through login again.
    redirect(get_login_url());
}

$code = link_manager::issue_code($siteid, (int) $USER->id);

$separator = str_contains($callback, '?') ? '&' : '?';
redirect(new moodle_url($callback . $separator . 'linkcode=' . $code));
