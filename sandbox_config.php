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
use local_oerexchange\local\sandbox\settings_catalogue;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->libdir . '/adminlib.php');

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
    const DEFAULT_BUNDLES = 'MOODLE_502_STABLE MOODLE_500_STABLE';

    /**
     * Prefix for the generated catalogue fields.
     *
     * Keeps a setting called, say, "theme" from colliding with a form element
     * of the same name, and makes the catalogue's values easy to pick back out
     * of the submitted data.
     *
     * @var string
     */
    const FIELD_PREFIX = 'sandboxset_';

    /**
     * The select options for a filter or boolean field: the unset state first.
     *
     * @param array $field one entry from settings_catalogue::fields()
     * @return array value => label
     */
    private static function choice_options(array $field): array {
        $options = [settings_catalogue::UNSET => get_string('settings_sandboxleavedefault', 'local_oerexchange')];
        foreach ($field['choices'] as $choice) {
            $options[$choice] = get_string('settings_sandboxchoice_' . $choice, 'local_oerexchange');
        }

        return $options;
    }

    /**
     * The catalogue values in a submitted form, keyed by setting name.
     *
     * @param array|object $data submitted form data
     * @return array key => value, including the unset ones so validation can see them
     */
    public static function submitted_choices($data): array {
        $data = (array) $data;
        $choices = [];
        foreach (array_keys(settings_catalogue::fields()) as $key) {
            $name = self::FIELD_PREFIX . $key;
            if (array_key_exists($name, $data)) {
                $choices[$key] = trim((string) $data[$name]);
            }
        }

        return $choices;
    }

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

        // Every catalogue field, grouped, each with a "leave Moodle's own
        // default alone" state that is the default and is never emitted. The
        // form is generated from the catalogue so that adding a setting is one
        // line there rather than an edit here as well.
        $fields = settings_catalogue::fields();
        foreach (settings_catalogue::groups() as $group => $headingid) {
            $ingroup = array_filter($fields, fn($field) => $field['group'] === $group);
            if (!$ingroup) {
                continue;
            }
            $mform->addElement('header', 'sandboxgroup_' . $group, get_string($headingid, 'local_oerexchange'));
            $mform->setExpanded('sandboxgroup_' . $group, false);

            foreach ($ingroup as $key => $field) {
                $name = self::FIELD_PREFIX . $key;
                if ($field['type'] === settings_catalogue::TYPE_TEXT) {
                    $mform->addElement('text', $name, settings_catalogue::label($field), ['size' => 30]);
                    $mform->setType($name, PARAM_TEXT);
                } else if ($field['type'] === settings_catalogue::TYPE_INT) {
                    $mform->addElement('text', $name, settings_catalogue::label($field), ['size' => 8]);
                    $mform->setType($name, PARAM_RAW_TRIMMED);
                } else {
                    $mform->addElement(
                        'select',
                        $name,
                        settings_catalogue::label($field),
                        self::choice_options($field)
                    );
                    $mform->setType($name, PARAM_RAW_TRIMMED);
                }
                $mform->setDefault($name, settings_catalogue::UNSET);
            }
        }

        $mform->addElement('header', 'sandboxgroup_advanced', get_string('settings_sandboxgroupadvanced', 'local_oerexchange'));
        $mform->setExpanded('sandboxgroup_advanced', false);

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

        $advanced = [];
        try {
            $advanced = config::parse_advanced((string) $data['sandboxadvanced']);
        } catch (\moodle_exception $e) {
            $errors['sandboxadvanced'] = $e->getMessage();
        }

        $choices = self::submitted_choices($data);
        foreach ($choices as $key => $value) {
            if (!settings_catalogue::validate($key, $value)) {
                $errors[self::FIELD_PREFIX . $key] = get_string('error_sandboxsetvalue', 'local_oerexchange');
            }
        }

        // A name set both here and in the Advanced box is refused rather than
        // resolved: silently picking a winner would store something the admin
        // did not ask for, which is the same reasoning parse_advanced() uses
        // when it rejects an unusable pair instead of stripping it.
        $collisions = settings_catalogue::collisions($advanced, $choices);
        if ($collisions) {
            $errors['sandboxadvanced'] = get_string(
                'error_sandboxcollision',
                'local_oerexchange',
                implode(', ', $collisions)
            );
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
    settings_catalogue::store_choices(local_oerexchange_sandbox_config_form::submitted_choices($data));
    set_config('sandboxadvanced', (string) $data->sandboxadvanced, 'local_oerexchange');
    set_config('sandboxbundled', !empty($data->sandboxbundled) ? 1 : 0, 'local_oerexchange');

    \core\notification::success(get_string('sandboxconfigsaved', 'local_oerexchange'));
    redirect(new moodle_url('/local/oerexchange/sandbox_config.php'));
}

if (!$form->is_submitted()) {
    $formdata = [
        'sandboxlangpacks' => implode(' ', $currentlangpacks),
        'sandboxtriallang' => (string) get_config('local_oerexchange', 'sandboxtriallang') ?: 'en',
        'sandboxbundles' => (string) get_config('local_oerexchange', 'sandboxbundles')
            ?: local_oerexchange_sandbox_config_form::DEFAULT_BUNDLES,
        'sandboxadvanced' => (string) get_config('local_oerexchange', 'sandboxadvanced'),
        'sandboxbundled' => (int) get_config('local_oerexchange', 'sandboxbundled'),
    ];
    // Only stored (non-default) choices are set; every other field keeps the
    // form's own default, which is the "leave Moodle's default alone" state.
    foreach (settings_catalogue::stored_choices() as $key => $value) {
        $formdata[local_oerexchange_sandbox_config_form::FIELD_PREFIX . $key] = $value;
    }
    $form->set_data($formdata);
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
