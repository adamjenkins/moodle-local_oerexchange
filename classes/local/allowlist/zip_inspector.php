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

namespace local_oerexchange\local\allowlist;

use local_oerexchange\local\sandbox\playground;

/**
 * Reads a plugin ZIP: what plugin is this, and what does it need?
 *
 * Almost all of the reading is core's. \core\update\validator is the same
 * class Moodle's own "Install plugins from ZIP" runs, so a package this
 * accepts is one Moodle would accept, and its complaints are the complaints
 * an admin would see there. Only $plugin->supported, $plugin->incompatible
 * and $plugin->dependencies come from version_php_scanner, because core's
 * parser does not read them.
 *
 * It also normalises the package. A GitHub archive's root directory is
 * something like "acme-moodle-mod_thing-9f2c1ab/", never the plugin's own
 * name, and the sandbox installs these ZIPs directly. Core's
 * code_manager::unzip_plugin_file() takes the target root directory as an
 * argument and renames on extraction, so re-packing from that gives a ZIP
 * whose root is "thing/" — which is exactly the hand-packaging step the admin
 * manual used to instruct admins to perform themselves.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class zip_inspector {
    /**
     * Read a plugin ZIP.
     *
     * @param string $zipfilepath the downloaded package
     * @param string $workdir a writable directory for extraction and repacking
     * @param string $sourceurl where it came from, recorded on the entry
     * @return plugin_meta
     * @throws resolution_exception when the ZIP is not a readable plugin
     */
    public function inspect(string $zipfilepath, string $workdir, string $sourceurl = ''): plugin_meta {
        $packer = get_file_packer('application/zip');

        // First pass: extract as-is, purely to find and read version.php.
        // The plugin's own name is not known yet, so the root directory
        // cannot be normalised on this pass.
        $rawdir = $this->subdirectory($workdir, 'raw');
        if (!$packer->extract_to_pathname($zipfilepath, $rawdir)) {
            throw new resolution_exception('error_allowlistnotazip');
        }

        [$rootdir, $versionphp] = $this->locate_version_php($rawdir);
        $source = file_get_contents($versionphp);

        $component = version_php_scanner::component($source);
        if ($component === null) {
            throw new resolution_exception('error_allowlistnoversionphp');
        }

        [$plugintype, $pluginname] = \core_component::normalize_component($component);
        if ($plugintype === 'core' || $pluginname === null) {
            throw new resolution_exception('error_allowlistnoversionphp');
        }

        // Repack under the plugin's own name. Whatever the archive called its
        // root — a GitHub commit-stamped directory, the plugin's name
        // already, or nothing at all because the files sat loose at the root
        // — the mirrored ZIP always contains exactly one directory, named
        // after the plugin. That is the layout Moodle's installer and the
        // sandbox both expect, and producing it here is what removes the
        // "package it by hand first" step from the admin's job.
        $normalisedzip = $workdir . '/' . $pluginname . '.zip';
        if (!$packer->archive_to_pathname([$pluginname => $rootdir], $normalisedzip)) {
            throw new resolution_exception('error_allowlistnotazip');
        }

        // Validate the normalised copy rather than the original: the layout
        // checks are meaningful only against the layout that will actually be
        // stored, and extract_to_pathname()'s return value is precisely the
        // file list \core\update\validator asks for.
        $validationdir = $this->subdirectory($workdir, 'validate');
        $files = $packer->extract_to_pathname($normalisedzip, $validationdir);
        if (!$files) {
            throw new resolution_exception('error_allowlistnotazip');
        }

        $warnings = $this->validate($validationdir, $files, $plugintype);

        return new plugin_meta(
            component: $component,
            plugintype: $plugintype,
            pluginname: $pluginname,
            version: version_php_scanner::plugin_version($source),
            release: version_php_scanner::release($source),
            requires: version_php_scanner::requires($source),
            supported: version_php_scanner::supported($source),
            incompatible: version_php_scanner::incompatible($source),
            dependencies: version_php_scanner::dependencies($source),
            zipfilepath: $normalisedzip,
            warnings: $warnings,
            sourceurl: $sourceurl,
        );
    }

    /**
     * Run core's plugin-ZIP validator and collect what it objects to.
     *
     * Its verdict is not treated as fatal here. This ZIP is not being
     * installed into the Exchange — it is being mirrored for a throwaway
     * in-browser trial on a different Moodle branch, so checks like "this
     * version is older than the one already installed" are noise in this
     * context. What the validator says is shown to the admin, who decides.
     *
     * @param string $plugindir directory holding the normalised plugin dir
     * @param array $files as returned by unzip_plugin_file()
     * @param string $plugintype
     * @return string[] human-readable warnings
     */
    private function validate(string $plugindir, array $files, string $plugintype): array {
        $validator = \core\update\validator::instance($plugindir, $files);
        $validator->assert_plugin_type($plugintype);

        // Assert against the NEWEST Moodle the sandbox runs, not $CFG->version.
        // The package is never installed into this Exchange — it is mirrored
        // for an in-browser trial on a deployed sandbox branch — so checking
        // it against the Exchange's own core version would answer a question
        // nobody asked. Using the newest deployed branch means the validator
        // objects only when the plugin genuinely requires a Moodle newer than
        // anything a trial could boot. (branch_mapper separately decides,
        // per branch, which ones it is actually listed for.)
        // execute() throws a coding_exception if this was never set.
        $validator->assert_moodle_version(max(playground::BRANCH_CORE_VERSIONS));

        $validator->execute();

        $warnings = [];
        foreach ($validator->get_messages() as $message) {
            if (
                $message->level !== \core\update\validator::ERROR
                    && $message->level !== \core\update\validator::WARNING
            ) {
                continue;
            }

            $text = $validator->message_code_name($message->msgcode);
            $info = $validator->message_code_info($message->msgcode, $message->addinfo);
            if ($info) {
                $text .= ' (' . $info . ')';
            } else if (is_string($message->addinfo)) {
                $text .= ' (' . $message->addinfo . ')';
            }

            $warnings[] = $text;
        }

        return $warnings;
    }

    /**
     * Find the version.php inside a raw extraction.
     *
     * Handles both shapes: a single wrapper directory (every GitHub archive,
     * and a correctly packaged plugin ZIP) and files sitting loose at the
     * archive root.
     *
     * @param string $rawdir
     * @return array{0: string, 1: string} [root directory, version.php path]
     * @throws resolution_exception when there is no version.php
     */
    private function locate_version_php(string $rawdir): array {
        if (is_readable($rawdir . '/version.php')) {
            return [$rawdir, $rawdir . '/version.php'];
        }

        foreach (glob($rawdir . '/*', GLOB_ONLYDIR) ?: [] as $candidate) {
            if (is_readable($candidate . '/version.php')) {
                return [$candidate, $candidate . '/version.php'];
            }
        }

        throw new resolution_exception('error_allowlistnoversionphp');
    }

    /**
     * Make and return a fresh subdirectory of $workdir.
     *
     * @param string $workdir
     * @param string $name
     * @return string
     */
    private function subdirectory(string $workdir, string $name): string {
        $path = rtrim($workdir, '/') . '/' . $name;
        remove_dir($path);
        mkdir($path, 0777, true);

        return $path;
    }
}
