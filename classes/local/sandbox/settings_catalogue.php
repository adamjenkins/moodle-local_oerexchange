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

namespace local_oerexchange\local\sandbox;

/**
 * Which site settings and text filters the sandbox configuration page offers
 * as real fields, declared as data.
 *
 * The form, its validation and the generated configuration file all read this
 * one table, so adding a field is one line here rather than an edit in three
 * places. See dev-docs SANDBOX-SETTINGS-CATALOGUE-DESIGN.md.
 *
 * Two constraints decided the contents, both established by inspection rather
 * than assumption (2026-08-19):
 *
 * 1. Only CORE, unprefixed settings can appear. The build writes each pair
 *    into the install snapshot's `mdl_config` table and never `config_plugins`
 *    (oer-sandbox/scripts/bake-settings.php), so a plugin-scoped setting such
 *    as competencies (`core_competency | enabled`) is unreachable and is
 *    deliberately absent.
 * 2. The 13 filters are the same on both bundle branches (compared the
 *    `filter/` directories of the MOODLE_500_STABLE and MOODLE_502_STABLE
 *    checkouts), so nothing here varies by branch.
 *
 * Labels reuse core's own strings, which is why each entry carries the
 * component that actually defines its string: 11 of these settings are not in
 * `admin` at all, and three needed a different string id (checked with
 * `string_exists()` against a live 5.2 site, not guessed). The payoff is that
 * every field is already translated in each installed language pack.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings_catalogue {
    /** @var string the plugin setting holding the admin's non-default choices, as JSON */
    public const STORE = 'sandboxchoices';

    /** @var string text filters */
    public const GROUP_FILTER = 'filters';
    /** @var string subsystems a throwaway trial rarely needs */
    public const GROUP_TRIM = 'trim';
    /** @var string what a visitor lands on and sees */
    public const GROUP_LOOK = 'look';
    /** @var string how a visitor gets in */
    public const GROUP_ACCESS = 'access';

    /** @var string on/off, applied to mdl_filter_active rather than mdl_config */
    public const TYPE_FILTER = 'filter';
    /** @var string 0/1 */
    public const TYPE_BOOL = 'bool';
    /** @var string a whole number */
    public const TYPE_INT = 'int';
    /** @var string one of a fixed set of values */
    public const TYPE_CHOICE = 'choice';
    /** @var string free text, single line */
    public const TYPE_TEXT = 'text';

    /** @var string the value meaning "leave this at Moodle's own default" — never emitted */
    public const UNSET = '';

    /**
     * The composite control for "apply filters to headings and other strings".
     *
     * Not a Moodle setting name: it emits `filterall` plus `stringfilters`,
     * which is the pair the page's old multilang-headings checkbox wrote. Kept
     * as one control because setting either alone is a misconfiguration.
     *
     * @var string
     */
    public const KEY_HEADINGS = 'applytoheadings';

    /**
     * The composite control for greying out H5P activities.
     *
     * Not a Moodle setting name: it emits `additionalhtmlhead`, which
     * core_renderer prints into every page's head when it is non-empty
     * (lib/classes/output/core_renderer.php:280-281).
     *
     * This is deliberately cosmetic and was chosen over hiding the module for
     * real (`modules.visible = 0`), which the configuration file cannot reach:
     * the build writes core `mdl_config` rows, and module visibility is a row
     * in `mdl_modules`. A visitor who types /mod/h5pactivity/view.php?id=N
     * still gets the activity, and the activity chooser still lists H5P. The
     * help string says so rather than implying the activity is disabled.
     *
     * @var string
     */
    public const KEY_GREYOUTH5P = 'greyouth5p';

    /**
     * The CSS the greying control writes.
     *
     * Single-quoted inside the selector on purpose: this string travels
     * through a shell-parsed `KEY=value` line, and avoiding double quotes
     * keeps it from depending on how that line is re-quoted. It must also stay
     * on ONE line — a newline here would forge a second config key.
     *
     * `modtype_<modname>` is the class Moodle puts on each activity in a
     * section (course/format/templates/local/content/section/cmitem.mustache:42);
     * the href selector catches the same links in the course index and
     * anywhere else they appear. Opacity greys the whole item, while
     * pointer-events is limited to links so the row itself stays selectable.
     *
     * @var string
     */
    public const GREYOUT_H5P_CSS = '<style>.modtype_h5pactivity{opacity:.45;}'
        . ".modtype_h5pactivity a,a[href*='/mod/h5pactivity/']{pointer-events:none;}</style>";

    /** @var string[] the core text filters, identical on both bundle branches */
    private const FILTERS = [
        'activitynames', 'algebra', 'codehighlighter', 'data', 'displayh5p',
        'emailprotect', 'emoticon', 'glossary', 'mathjaxloader', 'mediaplugin',
        'multilang', 'tex', 'urltolink',
    ];

    /**
     * Every non-filter field: key => [group, type, label string id, label component, choices].
     *
     * A label id of null means "same as the key".
     *
     * @var array
     */
    private const SETTINGS = [
        // Subsystems a trial rarely needs, each costing boot time and bundle weight.
        'enablebadges' => [self::GROUP_TRIM, self::TYPE_BOOL, null, 'badges'],
        'enableanalytics' => [self::GROUP_TRIM, self::TYPE_BOOL, null, 'admin'],
        'messaging' => [self::GROUP_TRIM, self::TYPE_BOOL, null, 'admin'],
        'enableblogs' => [self::GROUP_TRIM, self::TYPE_BOOL, null, 'admin'],
        'usetags' => [self::GROUP_TRIM, self::TYPE_BOOL, null, 'admin'],
        'enablenotes' => [self::GROUP_TRIM, self::TYPE_BOOL, null, 'notes'],
        'enableportfolios' => [self::GROUP_TRIM, self::TYPE_BOOL, 'enabled', 'portfolio'],
        'enablewebservices' => [self::GROUP_TRIM, self::TYPE_BOOL, null, 'admin'],
        'enablemobilewebservice' => [self::GROUP_TRIM, self::TYPE_BOOL, null, 'admin'],
        'enableplagiarism' => [self::GROUP_TRIM, self::TYPE_BOOL, null, 'plagiarism'],
        'enablecompletion' => [self::GROUP_TRIM, self::TYPE_BOOL, null, 'completion'],
        'enableoutcomes' => [self::GROUP_TRIM, self::TYPE_BOOL, null, 'grades'],
        'usecomments' => [self::GROUP_TRIM, self::TYPE_BOOL, 'enablecomments', 'admin'],
        'enablerssfeeds' => [self::GROUP_TRIM, self::TYPE_BOOL, null, 'admin'],
        'enablecourserequests' => [self::GROUP_TRIM, self::TYPE_BOOL, null, 'admin'],

        // What a visitor lands on and sees.
        // defaulthomepage's values are core's own constants: 0 site, 1 dashboard,
        // 3 my courses (lib/moodlelib.php HOMEPAGE_*).
        'defaulthomepage' => [self::GROUP_LOOK, self::TYPE_CHOICE, null, 'admin', ['0', '1', '3']],
        'navcourselimit' => [self::GROUP_LOOK, self::TYPE_INT, null, 'admin'],
        'courselistshortnames' => [self::GROUP_LOOK, self::TYPE_BOOL, null, 'admin'],
        'navshowallcourses' => [self::GROUP_LOOK, self::TYPE_BOOL, null, 'admin'],
        'navshowmycoursecategories' => [self::GROUP_LOOK, self::TYPE_BOOL, null, 'admin'],
        'langmenu' => [self::GROUP_LOOK, self::TYPE_BOOL, null, 'admin'],
        // Text, not a choice: the valid set is whatever the BUNDLE ships, which
        // this site cannot enumerate.
        'theme' => [self::GROUP_LOOK, self::TYPE_TEXT, null, 'moodle'],

        // How a visitor gets in and what they may do.
        'guestloginbutton' => [self::GROUP_ACCESS, self::TYPE_BOOL, null, 'auth'],
        'autologinguests' => [self::GROUP_ACCESS, self::TYPE_BOOL, null, 'admin'],
        'forcelogin' => [self::GROUP_ACCESS, self::TYPE_BOOL, null, 'admin'],
        'forceloginforprofiles' => [self::GROUP_ACCESS, self::TYPE_BOOL, null, 'admin'],
        'authloginviaemail' => [self::GROUP_ACCESS, self::TYPE_BOOL, null, 'auth'],
    ];

    /**
     * Every field, keyed by its setting name.
     *
     * @return array key => ['key', 'group', 'type', 'labelid', 'labelcomponent', 'choices']
     */
    public static function fields(): array {
        $fields = [];

        foreach (self::FILTERS as $name) {
            $fields[$name] = [
                'key' => $name,
                'group' => self::GROUP_FILTER,
                'type' => self::TYPE_FILTER,
                'labelid' => 'filtername',
                'labelcomponent' => 'filter_' . $name,
                'choices' => ['on', 'off'],
            ];
        }

        $fields[self::KEY_HEADINGS] = [
            'key' => self::KEY_HEADINGS,
            'group' => self::GROUP_FILTER,
            'type' => self::TYPE_BOOL,
            'labelid' => 'settings_sandboxapplytoheadings',
            'labelcomponent' => 'local_oerexchange',
            'choices' => ['0', '1'],
        ];

        $fields[self::KEY_GREYOUTH5P] = [
            'key' => self::KEY_GREYOUTH5P,
            'group' => self::GROUP_LOOK,
            'type' => self::TYPE_BOOL,
            'labelid' => 'settings_sandboxgreyouth5p',
            'labelcomponent' => 'local_oerexchange',
            'choices' => ['0', '1'],
        ];

        foreach (self::SETTINGS as $key => $spec) {
            [$group, $type, $labelid, $labelcomponent] = $spec;
            $fields[$key] = [
                'key' => $key,
                'group' => $group,
                'type' => $type,
                'labelid' => $labelid ?? $key,
                'labelcomponent' => $labelcomponent,
                'choices' => $spec[4] ?? ($type === self::TYPE_BOOL ? ['0', '1'] : []),
            ];
        }

        return $fields;
    }

    /**
     * The groups, in the order the form should show them.
     *
     * @return string[] group => this plugin's lang string id for its heading
     */
    public static function groups(): array {
        return [
            self::GROUP_FILTER => 'settings_sandboxgroupfilters',
            self::GROUP_TRIM => 'settings_sandboxgrouptrim',
            self::GROUP_LOOK => 'settings_sandboxgrouplook',
            self::GROUP_ACCESS => 'settings_sandboxgroupaccess',
        ];
    }

    /**
     * A field's label, from whichever component actually defines it.
     *
     * Falls back to the bare key rather than rendering Moodle's [[missing]]
     * marker: a language pack without the string should leave the page usable.
     *
     * @param array $field one entry from fields()
     * @return string
     */
    public static function label(array $field): string {
        if (get_string_manager()->string_exists($field['labelid'], $field['labelcomponent'])) {
            return get_string($field['labelid'], $field['labelcomponent']);
        }

        return $field['key'];
    }

    /**
     * Is this key a text filter (so it belongs in the config's filters list)?
     *
     * @param string $key
     * @return bool
     */
    public static function is_filter(string $key): bool {
        return in_array($key, self::FILTERS, true);
    }

    /**
     * Is $value acceptable for $key?
     *
     * self::UNSET is always acceptable: it is how "leave Moodle's default
     * alone" is stored, and such a field is never emitted at all.
     *
     * @param string $key
     * @param string $value
     * @return bool false for an unknown key as well as an unusable value
     */
    public static function validate(string $key, string $value): bool {
        $fields = self::fields();
        if (!isset($fields[$key])) {
            return false;
        }
        if ($value === self::UNSET) {
            return true;
        }

        $field = $fields[$key];
        switch ($field['type']) {
            case self::TYPE_INT:
                return $value !== '' && $value === (string) (int) $value && (int) $value >= 0;
            case self::TYPE_TEXT:
                // The same restriction parse_advanced() applies, and for the same
                // reason: this value is written into a file parsed line by line
                // as KEY=value, so anything that could forge a further line is
                // refused rather than stripped.
                return $value === clean_param($value, PARAM_TEXT)
                    && !preg_match('/[\r\n]/', $value)
                    && !str_contains($value, '\\n')
                    && !str_contains($value, '\\r');
            default:
                return in_array($value, $field['choices'], true);
        }
    }

    /**
     * The admin's stored choices, with anything unusable dropped.
     *
     * Dropping rather than throwing is deliberate: a stored value can only
     * become invalid if this catalogue changed under it (a field retired, a
     * choice narrowed), and a configuration page that cannot render is a worse
     * answer to that than one that shows the field back at its default.
     *
     * @return array key => value, containing only genuinely chosen values
     */
    public static function stored_choices(): array {
        $raw = (string) get_config('local_oerexchange', self::STORE);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $choices = [];
        foreach ($decoded as $key => $value) {
            $value = (string) $value;
            if ($value !== self::UNSET && self::validate((string) $key, $value)) {
                $choices[(string) $key] = $value;
            }
        }

        return $choices;
    }

    /**
     * Store the choices, keeping only the ones that are not "leave the default".
     *
     * @param array $choices key => value
     */
    public static function store_choices(array $choices): void {
        $keep = [];
        foreach ($choices as $key => $value) {
            $value = (string) $value;
            if ($value !== self::UNSET && self::validate((string) $key, $value)) {
                $keep[(string) $key] = $value;
            }
        }

        set_config(self::STORE, $keep ? json_encode($keep) : '', 'local_oerexchange');
    }

    /**
     * Split stored choices into the two collections the config file needs.
     *
     * The headings control is expanded here rather than stored expanded, so
     * that turning a filter on later also brings it under `stringfilters`
     * without the admin having to revisit the checkbox: `filterall` says
     * "filter headings too" and `stringfilters` names which filters that
     * applies to, which can only be the ones being switched on.
     *
     * @param array $choices stored_choices() output
     * @return array [filters (name => on|off), settings (name => value)]
     */
    public static function split(array $choices): array {
        $filters = [];
        $settings = [];

        foreach ($choices as $key => $value) {
            if ($key === self::KEY_HEADINGS || $key === self::KEY_GREYOUTH5P) {
                continue;
            }
            if (self::is_filter($key)) {
                $filters[$key] = $value;
            } else {
                $settings[$key] = $value;
            }
        }

        if (isset($choices[self::KEY_HEADINGS])) {
            $on = array_keys(array_filter($filters, fn($state) => $state === 'on'));
            $settings['filterall'] = $choices[self::KEY_HEADINGS] === '1' ? '1' : '0';
            $settings['stringfilters'] = $choices[self::KEY_HEADINGS] === '1' ? implode(',', $on) : '';
        }

        // Switching the greying off writes an empty value rather than emitting
        // nothing: it is how an admin undoes the greying on a bundle that was
        // built with it, which leaving the field at its default cannot do.
        if (isset($choices[self::KEY_GREYOUTH5P])) {
            $settings['additionalhtmlhead'] = $choices[self::KEY_GREYOUTH5P] === '1'
                ? self::GREYOUT_H5P_CSS
                : '';
        }

        return [$filters, $settings];
    }

    /**
     * Setting names that the catalogue and the Advanced textarea both set.
     *
     * Includes the names the headings control expands into, since those are
     * what actually reach the configuration file — a collision the admin
     * cannot see on the form is exactly the one worth refusing.
     *
     * @param array $advanced parse_advanced() output
     * @param array $choices the catalogue choices about to be stored
     * @return string[] the colliding setting names, in the order they were typed
     */
    public static function collisions(array $advanced, array $choices): array {
        [, $settings] = self::split($choices);

        return array_values(array_intersect(array_keys($advanced), array_keys($settings)));
    }
}
