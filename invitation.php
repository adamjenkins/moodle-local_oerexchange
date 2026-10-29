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
 * Accept or decline an invitation to co-author a resource or curate a
 * collection. The invitation notification links here.
 *
 * A GET only shows the invitation; accepting or declining is a POST with
 * sesskey, so a link or an image pointing here cannot answer for the user.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_oerexchange\local\invitation_manager;
use local_oerexchange\route\controller\collection_controller;

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$answer = optional_param('answer', '', PARAM_ALPHA);

$PAGE->set_url('/local/oerexchange/invitation.php', ['id' => $id]);
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('invitationheading', 'local_oerexchange'));
$PAGE->set_heading(get_string('invitationheading', 'local_oerexchange'));

require_login(null, false);
if (isguestuser()) {
    redirect(get_login_url());
}

$catalogueurl = new moodle_url('/local/oerexchange/index.php');

// Only the invitee can see or answer an invitation; anybody else, an
// answered or expired invitation, and one whose item can no longer be joined
// all get the same notice.
$invitation = invitation_manager::get_open_for_user($id, (int) $USER->id);
$item = $invitation ? invitation_manager::get_item($invitation) : null;
if (!$invitation || !$item) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('error_invitationnotfound', 'local_oerexchange'), 'info');
    echo $OUTPUT->continue_button($catalogueurl);
    echo $OUTPUT->footer();
    die();
}

if ($answer === 'accept' || $answer === 'decline') {
    require_sesskey();
    if ($answer === 'decline') {
        invitation_manager::decline($id, (int) $USER->id);
        \core\notification::info(get_string('invitationdeclined', 'local_oerexchange'));
        redirect($catalogueurl);
    }

    $joined = invitation_manager::accept($id, (int) $USER->id);
    if ($invitation->kind === invitation_manager::KIND_COAUTHOR) {
        \core\notification::success(get_string('invitationacceptedcoauthor', 'local_oerexchange'));
        redirect(new moodle_url('/local/oerexchange/resource.php', ['id' => $joined->id]));
    }
    \core\notification::success(get_string('invitationacceptedcurator', 'local_oerexchange'));
    redirect(collection_controller::collection_url($joined->slug));
}

$inviter = core_user::get_user((int) $invitation->invitedby);
$a = (object) [
    'invitedby' => $inviter ? s(fullname($inviter)) : '',
    'title' => format_string($item->title, true, ['context' => context_system::instance()]),
];
$message = $invitation->kind === invitation_manager::KIND_COAUTHOR
    ? get_string('invitationcoauthor', 'local_oerexchange', $a)
    : get_string('invitationcurator', 'local_oerexchange', $a);

$accept = new single_button(
    new moodle_url($PAGE->url, ['answer' => 'accept']),
    get_string('invitationaccept', 'local_oerexchange'),
    'post',
    single_button::BUTTON_PRIMARY
);
$decline = new single_button(
    new moodle_url($PAGE->url, ['answer' => 'decline']),
    get_string('invitationdecline', 'local_oerexchange'),
    'post'
);

echo $OUTPUT->header();
echo $OUTPUT->confirm($message, $accept, $decline);
echo $OUTPUT->footer();
