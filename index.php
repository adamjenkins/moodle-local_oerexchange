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
 * Catalogue browse/search page. Anonymous browsing is allowed by design.
 *
 * The listing itself lives in \local_oerexchange\local\catalogue_view, so
 * that the public-landing hook can render exactly the same catalogue at
 * the site root without duplicating any of it.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php'); // phpcs:ignore moodle.Files.RequireLogin.Missing -- see docblock above.

$view = \local_oerexchange\local\catalogue_view::from_request();
$pageurl = new moodle_url('/local/oerexchange/index.php');

$PAGE->set_url($pageurl);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('catalogtitle', 'local_oerexchange'));
$PAGE->set_heading(get_string('catalogtitle', 'local_oerexchange'));

echo $OUTPUT->header();
echo $view->render($pageurl);
echo $OUTPUT->footer();
