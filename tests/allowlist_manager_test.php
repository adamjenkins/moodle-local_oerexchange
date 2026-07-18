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

namespace local_oerexchange;

use local_oerexchange\local\allowlist_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for allowlist_manager::is_valid_branch() — found on the third MDL
 * Shield audit pass (2026-07-18): manage_allowlist.php used to read the
 * moodlebranch field with PARAM_ALPHANUMEXT, which silently strips dots,
 * corrupting "5.2" (the field's own placeholder-suggested value) to "52" —
 * a value that would then never match playground::DEPLOYED_BRANCHES ("5.0",
 * "5.2") anywhere it's compared, silently breaking the allowlist feature
 * for every entry an admin ever added via the form.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oerexchange\local\allowlist_manager
 */
final class allowlist_manager_test extends \basic_testcase {

    public function test_accepts_real_branch_labels(): void {
        foreach (['5.0', '5.2', '4.4', '4.5', '5.10', '10.0'] as $branch) {
            $this->assertTrue(allowlist_manager::is_valid_branch($branch), $branch);
        }
    }

    public function test_rejects_dotless_or_malformed_values(): void {
        // "52" is exactly what PARAM_ALPHANUMEXT used to silently turn "5.2"
        // into — the bug this class exists to prevent from ever reaching storage.
        foreach (['52', '5', '5.', '.2', '5.2.1', 'MOODLE_502_STABLE', '', '5.2x', '<script>'] as $branch) {
            $this->assertFalse(allowlist_manager::is_valid_branch($branch), $branch);
        }
    }
}
