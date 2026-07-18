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

namespace local_oerexchange\external;

/**
 * Tests for local_oerexchange_get_config. Added on the fourth MDL Shield
 * audit pass (2026-07-19) — of the 5 external functions, only record_import
 * had any WS-layer PHPUnit coverage before this pass; closing that gap for
 * the read-only functions here.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oerexchange\external\get_config
 */
final class get_config_test extends \advanced_testcase {
    public function test_execute_returns_defaults(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = get_config::execute();

        $this->assertSame(500 * 1024 * 1024, $result['maxbackupbytes']);
        $this->assertFalse($result['sandboxenabled']);
        $this->assertIsString($result['acceptedlicenses']);
        $this->assertNotSame('', $result['acceptedlicenses'], 'core always ships at least the default licenses');
    }

    public function test_execute_reflects_configured_settings(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        set_config('maxbackupbytes', 12345, 'local_oerexchange');
        set_config('sandboxenabled', 1, 'local_oerexchange');

        $result = get_config::execute();

        $this->assertSame(12345, $result['maxbackupbytes']);
        $this->assertTrue($result['sandboxenabled']);
    }
}
