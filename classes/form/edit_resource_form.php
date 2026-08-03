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

namespace local_oerexchange\form;

use local_oerexchange\local\cover_image;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Edit the details of a resource that has already been shared.
 *
 * Everything here is metadata the author typed themselves at upload and may
 * legitimately want to correct afterwards. What the form deliberately omits is
 * as considered as what it offers: `type`, `activitytype` and `courseformat`
 * are read out of the uploaded package rather than typed by anyone, and the
 * licence is left out because changing it would alter the terms under which
 * people have already imported the material.
 *
 * Being a real moodleform is what makes the description a rich-text field —
 * the 'editor' element is Moodle's configured default editor — and what gives
 * the thumbnail a proper filepicker, retiring the hand-rolled $_FILES upload
 * that used to sit inline on the resource page.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class edit_resource_form extends \moodleform {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'title', get_string('titlelabel', 'local_oerexchange'), ['size' => 60]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', get_string('required'), 'required', null, 'client');

        // Deliberately maxfiles => 0. "Basic formatting" is what this needs to
        // offer; permitting embedded files would mean a new file area, an
        // @@PLUGINFILE@@ rewrite on every output site, and a new branch in
        // local_oerexchange_pluginfile(), which today serves the cover image
        // and nothing else. The thumbnail below is the image story.
        $mform->addElement(
            'editor',
            'summary_editor',
            get_string('summarylabel', 'local_oerexchange'),
            null,
            ['maxfiles' => 0, 'context' => \context_system::instance()]
        );
        $mform->setType('summary_editor', PARAM_RAW);

        $mform->addElement('text', 'tags', get_string('tagslabel', 'local_oerexchange'), ['size' => 60]);
        $mform->setType('tags', PARAM_TEXT);

        // The language column is deliberately absent even though it exists and
        // is editable in principle: the upload form never renders an input for
        // it either (share_upload_mbz.php reads it as an optional_param that
        // nothing on the page can set), so it is populated only over the web
        // service by a client site. Offering it here would be inventing a
        // field rather than letting an author correct one they filled in.

        // The accepted_types option is a convenience for the picker only — it is chosen
        // client-side and cannot be trusted. cover_image::save_from_draft()
        // re-checks the mimetype AND sniffs the actual bytes server-side.
        $mform->addElement(
            'filepicker',
            'thumbnail',
            get_string('thumbnailupload', 'local_oerexchange'),
            null,
            [
                'accepted_types' => ['.png', '.jpg', '.jpeg', '.gif', '.webp'],
                'maxbytes' => cover_image::MAX_BYTES,
            ]
        );

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // A title of nothing but whitespace passes the 'required' rule, and
        // the catalogue would then render an entry with no visible name.
        if (trim((string) ($data['title'] ?? '')) === '') {
            $errors['title'] = get_string('required');
        }

        return $errors;
    }
}
