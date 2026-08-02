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
 * Test fixture: builds real plugin ZIPs for allowlist ingest tests.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_oerexchange\local\allowlist;

/**
 * Builds genuine plugin ZIPs on disk.
 *
 * Real archives rather than mocks, because the code under test reads them
 * with core's own packer and validator — a mocked package would prove nothing
 * about whether a real one survives the round trip.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class allowlist_zip_builder {
    /**
     * Build a plugin ZIP.
     *
     * @param string $component frankenstyle name, e.g. 'local_helper'
     * @param array $dependencies $plugin->dependencies
     * @param string|null $supported array literal for $plugin->supported,
     *                               e.g. '[500, 502]'; omitted when null
     * @param int $version $plugin->version
     * @return string full path to the ZIP
     */
    public function build(
        string $component,
        array $dependencies = [],
        ?string $supported = null,
        int $version = 2026070100,
    ): string {
        [, $name] = \core_component::normalize_component($component);

        $lines = [
            '<?php',
            "\$plugin->component = '{$component}';",
            "\$plugin->version = {$version};",
            '$plugin->requires = 2025041400;',
            "\$plugin->release = '1.0.0';",
        ];

        if ($supported !== null) {
            $lines[] = "\$plugin->supported = {$supported};";
        }

        if ($dependencies !== []) {
            $pairs = [];
            foreach ($dependencies as $dependency => $required) {
                $value = is_int($required) ? (string) $required : "'" . $required . "'";
                $pairs[] = "    '{$dependency}' => {$value},";
            }
            $lines[] = '$plugin->dependencies = [';
            $lines = array_merge($lines, $pairs);
            $lines[] = '];';
        }

        $dir = make_request_directory();
        $staging = $dir . '/' . $name;
        mkdir($staging, 0777, true);
        file_put_contents($staging . '/version.php', implode("\n", $lines) . "\n");

        $zip = $dir . '/' . $name . '.zip';
        get_file_packer('application/zip')->archive_to_pathname([$name => $staging], $zip);

        return $zip;
    }
}
