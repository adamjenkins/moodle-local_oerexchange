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
 * Sandbox bundle configuration: what language packs, site settings and baked
 * plugins the "Try it" sandbox build should be built with, and the download
 * of the generated config file that drives oer-sandbox's build scripts. See
 * SANDBOX-CONFIG-DESIGN.md.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_oerexchange\local\sandbox\bundle_stamp;
use local_oerexchange\local\sandbox\config;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');

require_login();
$context = context_system::instance();
require_capability('local/oerexchange:managesandbox', $context);

// The download branch runs before any output — before admin_externalpage_setup()
// and $OUTPUT->header() — because it emits a file, not a page, and the two must
// never share a response. The capability check above already gates it; sesskey
// is required in addition because, unlike a plain view, this materialises the
// effective configuration into a downloadable artefact.
if (optional_param('download', 0, PARAM_INT)) {
    require_sesskey();
    $downloadconfig = config::current();
    send_file(
        config::render($downloadconfig),
        'oer-sandbox.conf',
        0,
        0,
        true,
        true,
        'text/plain'
    );
}

admin_externalpage_setup('local_oerexchange_sandboxconfig');

/**
 * The sandbox configuration form.
 *
 * Not namespaced/autoloaded: this is the only place it is used, matching this
 * page's single-file scope.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_oerexchange_sandbox_config_form extends moodleform {
    /** @var string the current common.sh default, offered as the field's own default */
    const DEFAULT_BUNDLES = 'MOODLE_502_STABLE:v5.2.0 MOODLE_500_STABLE';

    /**
     * Space-separated language pack codes into a validated list, or null on
     * the first invalid token.
     *
     * @param string $raw
     * @return string[]|null
     */
    public static function parse_langpacks(string $raw): ?array {
        $codes = [];
        foreach (preg_split('/\s+/', trim($raw)) as $code) {
            if ($code === '') {
                continue;
            }
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $code)) {
                return null;
            }
            $codes[] = $code;
        }

        return $codes;
    }

    #[\Override]
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement(
            'text',
            'sandboxlangpacks',
            get_string('settings_sandboxlangpacks', 'local_oerexchange'),
            ['size' => 40]
        );
        $mform->setType('sandboxlangpacks', PARAM_TEXT);
        $mform->addHelpButton('sandboxlangpacks', 'settings_sandboxlangpacks', 'local_oerexchange');

        $langoptions = ['en' => 'en'];
        foreach ($this->_customdata['langpacks'] ?? [] as $code) {
            $langoptions[$code] = $code;
        }
        $mform->addElement(
            'select',
            'sandboxtriallang',
            get_string('settings_sandboxtriallang', 'local_oerexchange'),
            $langoptions
        );
        $mform->setType('sandboxtriallang', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('sandboxtriallang', 'settings_sandboxtriallang', 'local_oerexchange');

        $mform->addElement(
            'text',
            'sandboxbundles',
            get_string('settings_sandboxbundles', 'local_oerexchange'),
            ['size' => 60]
        );
        $mform->setType('sandboxbundles', PARAM_TEXT);
        $mform->setDefault('sandboxbundles', self::DEFAULT_BUNDLES);
        $mform->addHelpButton('sandboxbundles', 'settings_sandboxbundles', 'local_oerexchange');

        $mform->addElement(
            'advcheckbox',
            'sandboxmultilang',
            get_string('settings_sandboxmultilang', 'local_oerexchange')
        );
        $mform->addHelpButton('sandboxmultilang', 'settings_sandboxmultilang', 'local_oerexchange');

        $mform->addElement(
            'advcheckbox',
            'sandboxmultilangheadings',
            get_string('settings_sandboxmultilangheadings', 'local_oerexchange')
        );
        $mform->addHelpButton('sandboxmultilangheadings', 'settings_sandboxmultilangheadings', 'local_oerexchange');

        $mform->addElement(
            'textarea',
            'sandboxadvanced',
            get_string('settings_sandboxadvanced', 'local_oerexchange'),
            ['rows' => 8, 'cols' => 60]
        );
        $mform->setType('sandboxadvanced', PARAM_RAW);
        $mform->addHelpButton('sandboxadvanced', 'settings_sandboxadvanced', 'local_oerexchange');

        $mform->addElement(
            'advcheckbox',
            'sandboxbundled',
            get_string('settings_sandboxbundled', 'local_oerexchange')
        );
        $mform->addHelpButton('sandboxbundled', 'settings_sandboxbundled', 'local_oerexchange');

        $this->add_action_buttons(true);
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (self::parse_langpacks((string) $data['sandboxlangpacks']) === null) {
            $errors['sandboxlangpacks'] = get_string('error_invalidlangpack', 'local_oerexchange');
        }

        try {
            config::parse_advanced((string) $data['sandboxadvanced']);
        } catch (\moodle_exception $e) {
            $errors['sandboxadvanced'] = $e->getMessage();
        }

        return $errors;
    }
}

$currentlangpacks = array_values(array_filter(
    explode(',', (string) get_config('local_oerexchange', 'sandboxlangpacks'))
));

$form = new local_oerexchange_sandbox_config_form(null, ['langpacks' => $currentlangpacks]);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/oerexchange/sandbox_config.php'));
} else if ($data = $form->get_data()) {
    $langpacks = local_oerexchange_sandbox_config_form::parse_langpacks((string) $data->sandboxlangpacks);
    set_config('sandboxlangpacks', implode(',', $langpacks), 'local_oerexchange');
    set_config('sandboxtriallang', $data->sandboxtriallang, 'local_oerexchange');
    set_config('sandboxbundles', trim((string) $data->sandboxbundles), 'local_oerexchange');
    set_config('sandboxmultilang', !empty($data->sandboxmultilang) ? 1 : 0, 'local_oerexchange');
    set_config('sandboxmultilangheadings', !empty($data->sandboxmultilangheadings) ? 1 : 0, 'local_oerexchange');
    set_config('sandboxadvanced', (string) $data->sandboxadvanced, 'local_oerexchange');
    set_config('sandboxbundled', !empty($data->sandboxbundled) ? 1 : 0, 'local_oerexchange');

    \core\notification::success(get_string('sandboxconfigsaved', 'local_oerexchange'));
    redirect(new moodle_url('/local/oerexchange/sandbox_config.php'));
}

if (!$form->is_submitted()) {
    $form->set_data([
        'sandboxlangpacks' => implode(' ', $currentlangpacks),
        'sandboxtriallang' => (string) get_config('local_oerexchange', 'sandboxtriallang') ?: 'en',
        'sandboxbundles' => (string) get_config('local_oerexchange', 'sandboxbundles')
            ?: local_oerexchange_sandbox_config_form::DEFAULT_BUNDLES,
        'sandboxmultilang' => (int) get_config('local_oerexchange', 'sandboxmultilang'),
        'sandboxmultilangheadings' => (int) get_config('local_oerexchange', 'sandboxmultilangheadings'),
        'sandboxadvanced' => (string) get_config('local_oerexchange', 'sandboxadvanced'),
        'sandboxbundled' => (int) get_config('local_oerexchange', 'sandboxbundled'),
    ]);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('sandboxconfigtitle', 'local_oerexchange'));

// The saved-vs-deployed comparison (SANDBOX-CONFIG-DESIGN.md D1): the switch
// above only records an admin's claim that the deployed bundle carries this
// configuration; this fetch is the Exchange checking that claim itself
// rather than trusting the checkbox. "Could not verify" is rendered
// distinctly from a mismatch — a fetch failure is not evidence the two
// disagree, and treating it as one would train an admin to ignore the real
// warning.
$currentconfig = config::current();
$deployedstamp = bundle_stamp::deployed();
if ($deployedstamp['error'] !== null) {
    echo $OUTPUT->notification(
        get_string('sandboxstampunverifiable', 'local_oerexchange', $deployedstamp['error']),
        \core\output\notification::NOTIFY_INFO
    );
} else {
    $built = $deployedstamp['built'] !== null
        ? userdate($deployedstamp['built'])
        : get_string('sandboxstampbuiltunknown', 'local_oerexchange');
    if ($deployedstamp['stamp'] === $currentconfig['stamp']) {
        echo $OUTPUT->notification(
            get_string('sandboxstampmatch', 'local_oerexchange', (object) [
                'stamp' => $currentconfig['stamp'],
                'built' => $built,
            ]),
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else {
        echo $OUTPUT->notification(
            get_string('sandboxstampmismatch', 'local_oerexchange', (object) [
                'saved' => $currentconfig['stamp'],
                'deployed' => (string) $deployedstamp['stamp'],
                'built' => $built,
            ]),
            \core\output\notification::NOTIFY_WARNING
        );
    }
}

$downloadurl = new moodle_url('/local/oerexchange/sandbox_config.php', ['download' => 1, 'sesskey' => sesskey()]);
echo html_writer::div(
    html_writer::link($downloadurl, get_string('sandboxconfigdownload', 'local_oerexchange'), ['class' => 'btn btn-secondary']),
    'mb-3'
);

$form->display();

echo $OUTPUT->footer();
