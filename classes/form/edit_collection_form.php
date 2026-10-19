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

use local_oerexchange\local\collection_cover;
use local_oerexchange\local\collection_manager;
use local_oerexchange\local\slug_helper;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Create or edit a collection: its title, description, address, who may see
 * it, whether anyone may add to it, and its cover image.
 *
 * One form for both acts, because they are the same fields and a separate
 * create form would drift from this one. The only difference is the address:
 * a new collection's slug is minted from its title by
 * collection_manager::create() (the person never chose it, so there is nothing
 * for them to correct if it collides), and the field appears only once the
 * collection exists and has an address worth changing.
 *
 * What it deliberately does NOT offer: the curator list and deletion. Both
 * live on collection_edit.php below this form, as sesskey-guarded actions of
 * their own — a curator invitation sends a notification and a deletion is
 * irreversible, and neither belongs behind the same "Save" button as fixing a
 * typo in a title.
 *
 * Built from the same elements, in the same order, as this plugin's
 * edit_resource_form, including the editor's deliberate maxfiles => 0 and the
 * filepicker's accepted_types-is-a-convenience-only note.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class edit_collection_form extends \moodleform {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        $collectionid = (int) ($this->_customdata['collectionid'] ?? 0);

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        // Carried through the form so "create this collection and put that
        // resource in it" survives a validation failure. PARAM_INT, and
        // re-authorised server-side when it is finally used — the page never
        // trusts it as permission to add anything.
        $mform->addElement('hidden', 'addresourceid');
        $mform->setType('addresourceid', PARAM_INT);

        $mform->addElement('text', 'title', get_string('collectiontitle', 'local_oerexchange'), ['size' => 60]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', get_string('required'), 'required', null, 'client');
        $mform->addRule(
            'title',
            get_string('error_collectiontitletoolong', 'local_oerexchange', collection_manager::MAX_TITLE_LENGTH),
            'maxlength',
            collection_manager::MAX_TITLE_LENGTH,
            'client'
        );
        $mform->addHelpButton('title', 'collectiontitle', 'local_oerexchange');

        // Setting maxfiles => 0 for the same reason edit_resource_form gives: allowing
        // embedded files would mean a new file area, an @@PLUGINFILE@@ rewrite
        // on every output surface, and a new branch in
        // local_oerexchange_pluginfile(). The cover below is the image story.
        $mform->addElement(
            'editor',
            'summary_editor',
            get_string('collectionsummary', 'local_oerexchange'),
            null,
            ['maxfiles' => 0, 'context' => \context_system::instance()]
        );
        $mform->setType('summary_editor', PARAM_RAW);
        $mform->addHelpButton('summary_editor', 'collectionsummary', 'local_oerexchange');

        if ($collectionid) {
            // PARAM_RAW_TRIMMED, not PARAM_ALPHANUMEXT: an out-of-charset
            // address must reach validation() and be REJECTED with
            // error_invalidslug, not be silently stripped down to something
            // that then validates and quietly changes the collection's URL.
            $mform->addElement('text', 'slug', get_string('collectionslug', 'local_oerexchange'), [
                'size' => 40,
                'maxlength' => slug_helper::MAX_LENGTH,
            ]);
            $mform->setType('slug', PARAM_RAW_TRIMMED);
            $mform->addRule('slug', get_string('required'), 'required', null, 'client');
            $mform->addHelpButton('slug', 'collectionslug', 'local_oerexchange');
        }

        $mform->addElement(
            'select',
            'visibility',
            get_string('collectionvisibility', 'local_oerexchange'),
            [
                collection_manager::VISIBILITY_DRAFT =>
                    get_string('collectionvisibilitydraft', 'local_oerexchange'),
                collection_manager::VISIBILITY_UNLISTED =>
                    get_string('collectionvisibilityunlisted', 'local_oerexchange'),
                collection_manager::VISIBILITY_PUBLIC =>
                    get_string('collectionvisibilitypublic', 'local_oerexchange'),
            ]
        );
        $mform->setType('visibility', PARAM_ALPHA);
        $mform->setDefault('visibility', collection_manager::VISIBILITY_DRAFT);
        $mform->addHelpButton('visibility', 'collectionvisibility', 'local_oerexchange');

        // An advcheckbox, not checkbox: an unticked plain checkbox posts nothing
        // at all, so turning contributions back OFF would silently do nothing.
        $mform->addElement(
            'advcheckbox',
            'opencuration',
            get_string('collectionopencuration', 'local_oerexchange'),
            '',
            null,
            [0, 1]
        );
        $mform->setType('opencuration', PARAM_BOOL);
        $mform->addHelpButton('opencuration', 'collectionopencuration', 'local_oerexchange');

        // The accepted_types option is a convenience for the picker only — it is chosen
        // client-side and cannot be trusted. collection_cover::save_from_draft()
        // re-checks the mimetype AND sniffs the actual bytes server-side.
        $mform->addElement(
            'filepicker',
            'cover',
            get_string('collectioncover', 'local_oerexchange'),
            null,
            [
                'accepted_types' => ['.png', '.jpg', '.jpeg', '.gif', '.webp'],
                'maxbytes' => collection_cover::MAX_BYTES,
            ]
        );
        $mform->addHelpButton('cover', 'collectioncover', 'local_oerexchange');

        $this->add_action_buttons(
            true,
            get_string($collectionid ? 'collectionsave' : 'collectioncreate', 'local_oerexchange')
        );
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // A title of nothing but whitespace passes the 'required' rule, and the
        // collection would then render with no visible name — the same hole
        // edit_resource_form closes for resource titles.
        if (trim((string) ($data['title'] ?? '')) === '') {
            $errors['title'] = get_string('required');
        }

        if (array_key_exists('slug', $data)) {
            $collectionid = (int) ($this->_customdata['collectionid'] ?? 0);
            try {
                // Excluded by the collection's OWN id, so re-saving the form
                // without touching the address does not report the
                // collection's own slug as taken. Raises error_invalidslug or
                // error_slugtaken — the same single helper
                // collection_manager::update_metadata() will raise them from
                // if the check loses the race, which is why this is a
                // courtesy rather than the guarantee.
                slug_helper::validate(
                    collection_manager::TABLE,
                    trim((string) $data['slug']),
                    'id',
                    $collectionid
                );
            } catch (\moodle_exception $e) {
                $errors['slug'] = $e->getMessage();
            }
        }

        if (!in_array((string) ($data['visibility'] ?? ''), collection_manager::VISIBILITIES, true)) {
            $errors['visibility'] = get_string('required');
        }

        return $errors;
    }
}
