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

use local_oerexchange\local\sandbox\playground;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the Moodle Playground sandbox integration (branch mapping,
 * blueprint building — pure functions, no HTTP/orchestrator).
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oerexchange\local\sandbox\playground
 */
final class playground_test extends \basic_testcase {
    public function test_map_branch_picks_lowest_deployed_at_or_above_source(): void {
        $this->assertSame('5.0', playground::map_branch('4.4.2 (Build: 20250101)'));
        $this->assertSame('5.0', playground::map_branch('5.0.1'));
        $this->assertSame('5.2', playground::map_branch('5.1.3 (Build: 20250601)'));
        $this->assertSame('5.2', playground::map_branch('5.2.1+ (Build: 20260714)'));
    }

    public function test_map_branch_falls_back_to_newest_when_source_is_newer_than_everything(): void {
        $this->assertSame('5.2', playground::map_branch('5.9.0'));
    }

    public function test_map_branch_falls_back_to_newest_when_unparseable(): void {
        $this->assertSame('5.2', playground::map_branch('not a version string'));
    }

    public function test_build_blueprint_orders_steps_and_intersects_only_given_plugins(): void {
        $blueprint = playground::build_blueprint(
            'My Course',
            'https://exchange.example/local/oerexchange/download.php?v=1&exp=2&sig=abc',
            [['type' => 'mod', 'name' => 'board', 'zipurl' => 'https://exchange.example/allowlist_file.php?id=1']]
        );

        $steps = array_column($blueprint['steps'], 'step');
        $this->assertSame(['installMoodle', 'login', 'installMoodlePlugin', 'restoreCourse'], $steps);
        $this->assertSame('/course/view.php?id=2', $blueprint['landingPage']);
    }

    public function test_build_launch_url_embeds_branch_and_base64_blueprint(): void {
        $blueprint = ['steps' => [['step' => 'installMoodle']], 'landingPage' => '/course/view.php?id=2'];
        $url = playground::build_launch_url('https://exchange.example/try/', '5.2', $blueprint);

        $this->assertStringStartsWith('https://exchange.example/try/?', $url->out(false));
        $params = $url->params();
        $this->assertSame('5.2', $params['moodle']);
        $decoded = json_decode(base64_decode($params['blueprint']), true);
        $this->assertSame($blueprint, $decoded);
    }
}
