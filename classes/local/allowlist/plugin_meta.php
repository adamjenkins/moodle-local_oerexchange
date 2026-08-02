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

/**
 * Everything read out of a candidate plugin's ZIP.
 *
 * This is the whole point of the feature: every field here used to be typed
 * in by hand on the allowlist form, or (for dependencies) not captured at all.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class plugin_meta {
    /**
     * Constructor.
     *
     * @param string $component frankenstyle name, e.g. 'mod_quizquest'
     * @param string $plugintype e.g. 'mod'
     * @param string $pluginname e.g. 'quizquest'
     * @param int|null $version $plugin->version
     * @param string|null $release $plugin->release, for display
     * @param int|null $requires minimum core version
     * @param int[]|null $supported declared branch range
     * @param int|null $incompatible first incompatible branch
     * @param array $dependencies component => version
     * @param string $zipfilepath a ZIP whose single root directory is
     *                            $pluginname, ready to mirror
     * @param string[] $warnings human-readable validator complaints
     * @param string $sourceurl the URL the package was actually fetched from
     */
    public function __construct(
        /** @var string frankenstyle name */
        public readonly string $component,
        /** @var string plugin type, e.g. 'mod' */
        public readonly string $plugintype,
        /** @var string plugin name without its type prefix */
        public readonly string $pluginname,
        /** @var int|null $plugin->version */
        public readonly ?int $version,
        /** @var string|null $plugin->release */
        public readonly ?string $release,
        /** @var int|null minimum core version */
        public readonly ?int $requires,
        /** @var int[]|null declared branch range */
        public readonly ?array $supported,
        /** @var int|null first incompatible branch */
        public readonly ?int $incompatible,
        /** @var array<string, int|string> declared dependencies */
        public readonly array $dependencies,
        /** @var string path to a root-normalised ZIP */
        public readonly string $zipfilepath,
        /** @var string[] validator warnings worth showing the admin */
        public readonly array $warnings,
        /**
         * @var string where the package came from. Stored on the allowlist
         * entry and re-downloaded from by the sandbox build machine, so it
         * has to be the URL actually fetched rather than a friendlier
         * address the admin happened to paste.
         */
        public readonly string $sourceurl = '',
    ) {
    }

    /**
     * The branches this plugin should be allowlisted for.
     *
     * @param string[]|null $deployed defaults to the deployed sandbox set
     * @return branch_selection
     */
    public function branches(?array $deployed = null): branch_selection {
        return branch_mapper::branches_for($this->supported, $this->requires, $this->incompatible, $deployed);
    }
}
