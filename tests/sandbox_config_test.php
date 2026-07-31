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

use PHPUnit\Framework\Attributes\CoversClass;
use local_oerexchange\local\sandbox\config;

/**
 * Tests for the sandbox bundle configuration model: the stamp that is the
 * contract between the saved config and a deployed bundle, and the
 * advanced-settings parser that rejects rather than sanitises unsafe input.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(config::class)]
final class sandbox_config_test extends \advanced_testcase {
    public function test_the_stamp_is_stable_across_key_order(): void {
        $this->resetAfterTest();

        $a = ['langpacks' => ['ja'], 'settings' => ['filterall' => '1', 'country' => 'JP']];
        $b = ['settings' => ['country' => 'JP', 'filterall' => '1'], 'langpacks' => ['ja']];

        $this->assertSame(config::stamp($a), config::stamp($b));
    }

    public function test_the_stamp_changes_when_the_config_changes(): void {
        $this->resetAfterTest();

        $a = ['langpacks' => ['ja']];
        $b = ['langpacks' => ['ja', 'fr']];

        $this->assertNotSame(config::stamp($a), config::stamp($b));
    }

    public function test_the_stamp_is_prefixed_and_short(): void {
        $this->resetAfterTest();

        $stamp = config::stamp(['langpacks' => ['ja']]);

        $this->assertMatchesRegularExpression('/^cfg-[0-9a-f]{12}$/', $stamp);
    }

    public function test_advanced_settings_parse_into_pairs(): void {
        $this->resetAfterTest();

        $parsed = config::parse_advanced("country=JP\n timezone = Asia/Tokyo \n\n# comment\n");

        $this->assertSame(['country' => 'JP', 'timezone' => 'Asia/Tokyo'], $parsed);
    }

    public function test_advanced_settings_reject_an_unsafe_name(): void {
        $this->resetAfterTest();

        $this->expectException(\moodle_exception::class);
        config::parse_advanced("some name with spaces=1");
    }

    public function test_advanced_settings_reject_a_newline_bearing_value(): void {
        $this->resetAfterTest();

        // A value that could forge a second KEY=value line in the rendered file.
        $this->expectException(\moodle_exception::class);
        config::parse_advanced("country=JP\\nBAKE_PLUGINS_X=evil");
    }
}
