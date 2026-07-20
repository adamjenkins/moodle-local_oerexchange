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
 * Chooser: how does the user want to share a new resource directly on the
 * Exchange (no client site involved)? Links to share_upload_mbz.php or
 * share_upload_data.php.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_login();

$PAGE->set_url('/local/oerexchange/share_new.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('sharenewheading', 'local_oerexchange'));
$PAGE->set_heading(get_string('sharenewheading', 'local_oerexchange'));

echo $OUTPUT->header();

echo html_writer::start_tag('div', ['class' => 'row row-cols-1 row-cols-md-2 g-3']);

echo html_writer::start_tag('div', ['class' => 'col']);
echo html_writer::start_tag('div', ['class' => 'card h-100']);
echo html_writer::start_tag('div', ['class' => 'card-body']);
echo html_writer::tag('h5', get_string('sharenewmbzoption', 'local_oerexchange'), ['class' => 'card-title']);
echo html_writer::tag('p', get_string('sharenewmbzdesc', 'local_oerexchange'), ['class' => 'card-text text-muted']);
echo html_writer::link(
    new moodle_url('/local/oerexchange/share_upload_mbz.php'),
    get_string('sharenewmbzoption', 'local_oerexchange'),
    ['class' => 'btn btn-primary']
);
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', ['class' => 'col']);
echo html_writer::start_tag('div', ['class' => 'card h-100']);
echo html_writer::start_tag('div', ['class' => 'card-body']);
echo html_writer::tag('h5', get_string('sharenewdataoption', 'local_oerexchange'), ['class' => 'card-title']);
echo html_writer::tag('p', get_string('sharenewdatadesc', 'local_oerexchange'), ['class' => 'card-text text-muted']);
echo html_writer::link(
    new moodle_url('/local/oerexchange/share_upload_data.php'),
    get_string('sharenewdataoption', 'local_oerexchange'),
    ['class' => 'btn btn-primary']
);
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

echo html_writer::end_tag('div');

echo $OUTPUT->footer();
