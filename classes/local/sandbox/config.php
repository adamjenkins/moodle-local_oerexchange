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
 * The sandbox bundle configuration: what a trial's bundle should be built
 * with, and the stamp that identifies it.
 *
 * The stamp is the contract between this site and the build machine. It is a
 * hash of the effective config, written into the generated file and, by the
 * build, into the deployed bundle — so the two can be compared without the
 * Exchange having to trust an admin's checkbox.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config {
    /** @var int most advanced name=value pairs accepted */
    const ADVANCED_MAX = 50;

    /**
     * Hash of the effective config, insensitive to key order.
     *
     * @param array $config
     * @return string e.g. cfg-7f3a91b0c2d4
     */
    public static function stamp(array $config): string {
        return 'cfg-' . substr(hash('sha256', self::canonical($config)), 0, 12);
    }

    /**
     * Recursively key-sorted JSON, so a stamp depends on the config's content
     * and never on the order the form happened to submit it in.
     *
     * @param mixed $value
     * @return string
     */
    protected static function canonical($value): string {
        if (is_array($value)) {
            $sorted = $value;
            if (array_is_list($sorted)) {
                sort($sorted, SORT_STRING);
            } else {
                ksort($sorted, SORT_STRING);
            }
            foreach ($sorted as $k => $v) {
                $sorted[$k] = is_array($v) ? json_decode(self::canonical($v), true) : $v;
            }
            return json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Parse the Advanced textarea into name => value.
     *
     * Names are restricted to what a Moodle setting name can be and values to
     * a single line: the result is written into a file that is parsed line by
     * line as KEY=value, so a value containing a newline could otherwise forge
     * a further key. Rejecting is correct — silently stripping would store
     * something the admin did not type.
     *
     * @param string $raw
     * @return array name => value
     * @throws \moodle_exception on an unusable name or value
     */
    public static function parse_advanced(string $raw): array {
        $pairs = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                throw new \moodle_exception('error_advancedsyntax', 'local_oerexchange', '', $line);
            }
            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if ($name === '' || $name !== clean_param($name, PARAM_ALPHANUMEXT)) {
                throw new \moodle_exception('error_advancedname', 'local_oerexchange', '', $name);
            }
            // Reject both an actual embedded control character and the literal two-character
            // sequence backslash-n/backslash-r: a downstream shell step that unescapes
            // backslash sequences (echo -e, printf %b, unquoted read) could turn either into a
            // real newline and forge a further KEY=value line in the rendered file.
            $unsafevalue = $value !== clean_param($value, PARAM_TEXT) || preg_match('/[\r\n]/', $value)
                    || str_contains($value, '\\n') || str_contains($value, '\\r');
            if ($unsafevalue) {
                throw new \moodle_exception('error_advancedvalue', 'local_oerexchange', '', $name);
            }
            $pairs[$name] = $value;
            if (count($pairs) > self::ADVANCED_MAX) {
                throw new \moodle_exception('error_advancedtoomany', 'local_oerexchange');
            }
        }

        return $pairs;
    }

    /**
     * The effective configuration: stored settings plus the allowlist's baked
     * entries, grouped by the branch each entry names.
     *
     * @return array
     */
    public static function current(): array {
        global $DB;

        $advanced = self::parse_advanced((string) get_config('local_oerexchange', 'sandboxadvanced'));

        // The catalogue's choices and the Advanced box cannot name the same
        // setting: the form refuses to save that pair (settings_catalogue::
        // collisions()). Merging catalogue-last anyway means that if one ever
        // did slip through — a choice stored before that check existed, say —
        // what the page SHOWS is what gets baked, rather than a typed line
        // silently beating a visible control.
        [$filters, $catalogue] = settings_catalogue::split(settings_catalogue::stored_choices());
        $advanced = array_merge($advanced, $catalogue);

        $bakeplugins = [];
        $entries = $DB->get_records('local_oerexchange_pluginallowlist', ['bake' => 1, 'status' => 'active']);
        foreach ($entries as $entry) {
            $bakeplugins[$entry->moodlebranch][] = [
                'type' => $entry->plugintype,
                'name' => $entry->pluginname,
                'url' => (new \moodle_url('/local/oerexchange/allowlist_file.php', ['id' => $entry->id]))->out(false),
                'sha256' => (string) $entry->sha256,
            ];
        }

        $config = [
            'langpacks' => array_values(array_filter(explode(',', (string) get_config('local_oerexchange', 'sandboxlangpacks')))),
            'triallang' => (string) get_config('local_oerexchange', 'sandboxtriallang'),
            'bundles' => (string) get_config('local_oerexchange', 'sandboxbundles'),
            'settings' => $advanced,
            'filters' => $filters,
            'bakeplugins' => $bakeplugins,
        ];
        $config['stamp'] = self::stamp($config);

        return $config;
    }

    /**
     * The downloadable config file.
     *
     * Every line is either a comment or KEY=value, because the consuming
     * script parses line by line against a key whitelist rather than sourcing
     * the file — so nothing here is ever executed by a shell.
     *
     * @param array $config
     * @return string
     */
    public static function render(array $config): string {
        global $CFG;

        $lines = [
            '# oer-sandbox.conf - generated by ' . $CFG->wwwroot,
            '# config stamp: ' . $config['stamp'],
            '# Consumed by oer-sandbox/scripts/install.sh --config',
            'OER_CONFIG_STAMP=' . $config['stamp'],
        ];
        if (($config['bundles'] ?? '') !== '') {
            $lines[] = 'BUNDLES=' . $config['bundles'];
        }
        if ($config['langpacks']) {
            $lines[] = 'LANGPACKS=' . implode(' ', $config['langpacks']);
        }
        if ($config['triallang'] !== '') {
            $lines[] = 'TRIAL_DEFAULT_LANG=' . $config['triallang'];
        }
        foreach ($config['filters'] as $name => $state) {
            $lines[] = 'SITE_FILTER_' . $name . '=' . $state;
        }
        foreach ($config['settings'] as $name => $value) {
            $lines[] = 'SITE_SETTING_' . $name . '=' . $value;
        }
        foreach ($config['bakeplugins'] as $branch => $plugins) {
            $specs = [];
            $detail = [];
            foreach ($plugins as $plugin) {
                $specs[] = $plugin['type'] . ':' . $plugin['name'];
                $suffix = $plugin['type'] . '_' . $plugin['name'] . '_' . $branch;
                $detail[] = 'PLUGIN_ZIP_' . $suffix . '=' . $plugin['url'];
                $detail[] = 'PLUGIN_SHA256_' . $suffix . '=' . $plugin['sha256'];
            }
            $lines[] = 'BAKE_PLUGINS_' . $branch . '=' . implode(' ', $specs);
            $lines = array_merge($lines, $detail);
        }

        return implode("\n", $lines) . "\n";
    }
}
