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

namespace local_oerexchange\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Validation for the sandbox plugin allowlist admin form. Split out mainly
 * for the moodlebranch check: PARAM_ALPHANUMEXT (letters, digits, _- only —
 * no dot) silently strips the dot out of a value like "5.2", corrupting it
 * to "52", which then never matches playground::DEPLOYED_BRANCHES ("5.0",
 * "5.2") anywhere the branch is compared — found on the third MDL Shield
 * audit pass (2026-07-18): manage_allowlist.php used PARAM_ALPHANUMEXT for
 * this field, including in its own placeholder text suggesting "5.2".
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class allowlist_manager {

    /**
     * A Moodle branch label as used throughout this plugin: "5.0", "5.2",
     * "5.10" — major.minor, digits and one dot, nothing else.
     *
     * @param string $branch
     * @return bool
     */
    public static function is_valid_branch(string $branch): bool {
        return (bool) preg_match('/^\d+\.\d+$/', $branch);
    }
}
