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
 * Edit the details of a resource that has already been shared.
 *
 * Title, description and thumbnail were frozen at upload until this page
 * existed: publish() applies its metadata only when creating a resource, so
 * the only way to fix a typo in a title was to share the whole thing again.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_oerexchange\form\edit_resource_form;
use local_oerexchange\local\cover_image;
use local_oerexchange\local\resource_manager;

$id = required_param('id', PARAM_INT);

require_login();

$resource = $DB->get_record('local_oerexchange_resources', ['id' => $id], '*', MUST_EXIST);

$PAGE->set_url('/local/oerexchange/edit_resource.php', ['id' => $id]);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('editresource', 'local_oerexchange'));
$PAGE->set_heading(get_string('editresource', 'local_oerexchange'));

// A tombstoned resource has had its title, summary and cover deliberately
// blanked by the delete path; there is nothing left to edit and re-populating
// those fields would partially resurrect it.
if ($resource->status === 'deleted') {
    throw new moodle_exception('error_notfound', 'local_oerexchange');
}

// The one ownership gate — creator, co-author, or a moderator. Never write a
// second differently-guarded copy of this check; that was a review finding
// once already (resource_manager::user_can_edit_resource() docblock).
if (!resource_manager::user_can_edit_resource($resource, (int) $USER->id)) {
    throw new moodle_exception('error_notyourresource', 'local_oerexchange');
}

$returnurl = new moodle_url('/local/oerexchange/resource.php', ['id' => $id]);

// Prefill the thumbnail picker with the current cover image so that saving
// the form without touching it leaves the existing image alone rather than
// clearing it.
$draftitemid = file_get_submitted_draft_itemid('thumbnail');
file_prepare_draft_area(
    $draftitemid,
    context_system::instance()->id,
    'local_oerexchange',
    'coverimage',
    $resource->id,
    ['subdirs' => 0, 'maxfiles' => 1, 'maxbytes' => cover_image::MAX_BYTES]
);

$form = new edit_resource_form($PAGE->url);
$form->set_data([
    'id' => $resource->id,
    'title' => $resource->title,
    'summary_editor' => [
        'text' => $resource->summary ?? '',
        'format' => (int) ($resource->summaryformat ?? FORMAT_HTML),
    ],
    'tags' => $resource->tags ?? '',
    'thumbnail' => $draftitemid,
]);

if ($form->is_cancelled()) {
    redirect($returnurl);
}

if ($data = $form->get_data()) {
    // Normalise the description to HTML before storing it.
    //
    // The stored summary has readers this page cannot reach: the catalogue
    // cards, block_oerexchangebrowse (which queries the table directly), and —
    // over the web service — every registered client site's browse and preview
    // pages. The service contract carries the text but no format field, and
    // adding one would be a protocol change requiring a matching
    // local_oerclient release, so every one of those readers treats the
    // summary as HTML.
    //
    // With Moodle's usual rich-text editor that is already what arrives. But
    // when the site's default editor is the plain text area, the editor
    // element offers a format menu, and an author choosing Markdown would
    // store markup that renders correctly on this page and wrongly in three
    // other places. Converting here keeps every reader right; the column is
    // still stored and honoured on output, so a later round can carry real
    // multi-format support without a migration.
    $summary = (string) $data->summary_editor['text'];
    $submittedformat = (int) $data->summary_editor['format'];
    if ($submittedformat !== FORMAT_HTML) {
        $summary = format_text($summary, $submittedformat, [
            'context' => context_system::instance(),
            'filter' => false,
        ]);
    }

    resource_manager::update_metadata((int) $resource->id, [
        'title' => $data->title,
        'summary' => $summary,
        'summaryformat' => FORMAT_HTML,
        'tags' => $data->tags,
    ]);

    // Validates the mimetype and sniffs the actual bytes; returns false, and
    // changes nothing, when the picker was left as it was.
    cover_image::save_from_draft((int) $resource->id, (int) $data->thumbnail);

    \core\notification::success(get_string('resourceupdated', 'local_oerexchange'));
    redirect($returnurl);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($resource->title, true, ['context' => context_system::instance()]), 3);
$form->display();
echo $OUTPUT->footer();
