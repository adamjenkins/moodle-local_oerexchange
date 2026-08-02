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

use local_oerexchange\local\allowlist\source_resolver;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * "Add a plugin to the sandbox allowlist" — a URL or a file, nothing else.
 *
 * What this form does NOT ask for is the point of it. It used to want the
 * plugin type, the plugin name, the Moodle branch and a source URL, all typed
 * by hand, and the whole form again for each Moodle branch. Every one of
 * those is now read from the package itself.
 *
 * Being a real moodleform also retires the hand-rolled multipart form the
 * page used to carry, which read $_FILES directly with no size or type
 * validation and outside the File API.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class allowlist_add_form extends \moodleform {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        $cansandbox = !empty($this->_customdata['cansandbox']);

        $mform->addElement('static', 'intro', '', get_string('allowlistaddintro', 'local_oerexchange'));

        $mform->addElement('text', 'url', get_string('allowlisturl', 'local_oerexchange'), ['size' => 60]);
        $mform->setType('url', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('url', 'allowlisturl', 'local_oerexchange');

        $mform->addElement(
            'filepicker',
            'zipfile',
            get_string('allowlistupload', 'local_oerexchange'),
            null,
            ['accepted_types' => ['.zip'], 'maxbytes' => source_resolver::MAX_ZIP_BYTES]
        );

        $mform->addElement(
            'advcheckbox',
            'ignoresupported',
            get_string('allowlistignoresupported', 'local_oerexchange')
        );
        $mform->addHelpButton('ignoresupported', 'allowlistignoresupported', 'local_oerexchange');

        $mform->addElement('advcheckbox', 'bake', get_string('allowlistbake', 'local_oerexchange'));
        $mform->addHelpButton('bake', 'allowlistbake', 'local_oerexchange');
        if (!$cansandbox) {
            // Rendered disabled rather than hidden, matching how the entries
            // table below has always shown this flag. The page ignores any
            // submitted value from such a user regardless — a disabled input
            // submits nothing, but the server-side check is what actually
            // enforces it against a forged request.
            $mform->hardFreeze('bake');
        }

        $this->add_action_buttons(false, get_string('allowlistresolve', 'local_oerexchange'));
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $hasurl = !empty($data['url']);

        // Deliberately NOT moodleform::get_new_filename() here. That method
        // starts with a !is_validated() guard, is_validated() runs
        // validation(), and validation() is this method — so calling it from
        // here recurses until the request runs out of memory. (Observed as a
        // 256 MB fatal in the DML layer, which is merely where the last
        // allocation happened to land.) Reading the draft area directly asks
        // the same question without re-entering validation.
        $draftid = (int) ($data['zipfile'] ?? 0);
        $hasfile = $draftid > 0 && !empty(file_get_drafarea_files($draftid)->list);

        if (!$hasurl && !$hasfile) {
            $errors['url'] = get_string('allowlistneedsource', 'local_oerexchange');
        } else if ($hasurl && $hasfile) {
            // Resolving both would silently ignore one of them.
            $errors['url'] = get_string('allowlistonesource', 'local_oerexchange');
        }

        return $errors;
    }
}
