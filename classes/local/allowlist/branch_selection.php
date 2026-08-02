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
 * Which sandbox branches a candidate plugin should be allowlisted for, and
 * how much the answer is worth.
 *
 * The confidence travels with the branch list deliberately: an admin
 * confirming an ingest needs to see the difference between "the author says
 * this runs on 5.0 and 5.2" and "the author said nothing, so we are offering
 * you everything we deploy". Without it the preview would present a guess and
 * a fact identically.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class branch_selection {
    /**
     * Constructor.
     *
     * @param string[] $branches dotted branch labels, e.g. ['5.0', '5.2'],
     *                           in the order they are deployed
     * @param string $confidence one of branch_mapper's DECLARED, INFERRED,
     *                           UNVERIFIED or OVERRIDDEN
     * @param string[] $declined branches included only because the admin
     *                           overrode what the plugin declares
     */
    public function __construct(
        /** @var string[] dotted branch labels this plugin should be listed for */
        public readonly array $branches,
        /** @var string how the branch list was arrived at */
        public readonly string $confidence,
        /**
         * @var string[] the branches in $branches that the plugin's own
         * declarations would have excluded. Empty unless the admin overrode
         * them. Named separately from $branches because the admin is owed the
         * specifics — "you are adding 5.2, which this plugin does not claim to
         * support" is actionable in a way that "overridden" is not.
         */
        public readonly array $declined = [],
    ) {
    }

    /**
     * Whether the plugin supports nothing the sandbox actually runs.
     *
     * @return bool
     */
    public function is_empty(): bool {
        return $this->branches === [];
    }
}
