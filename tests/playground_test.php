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

    /**
     * Moodle Playground's installMoodlePlugin step only auto-detects
     * pluginType/pluginName from a GitHub-style archive URL
     * (/<repo>/archive/... matching moodle-{type}_{name} naming) - our
     * allowlist_file.php?id=N URLs never match that pattern, so the step
     * MUST carry pluginType/pluginName explicitly or the install throws
     * inside the sandbox. Found live, 2026-07-19: this previously silently
     * omitted both fields.
     */
    public function test_build_blueprint_installs_plugin_step_carries_explicit_type_and_name(): void {
        $blueprint = playground::build_blueprint(
            'My Course',
            'https://exchange.example/local/oerexchange/download.php?v=1&exp=2&sig=abc',
            [['type' => 'mod', 'name' => 'quizquest', 'zipurl' => 'https://exchange.example/allowlist_file.php?id=1']]
        );

        $installstep = null;
        foreach ($blueprint['steps'] as $step) {
            if ($step['step'] === 'installMoodlePlugin') {
                $installstep = $step;
            }
        }
        $this->assertNotNull($installstep);
        $this->assertSame('mod', $installstep['pluginType']);
        $this->assertSame('quizquest', $installstep['pluginName']);
        $this->assertSame('https://exchange.example/allowlist_file.php?id=1', $installstep['url']);
    }

    /**
     * mod_quizquest is baked into the deployed 5.2 bundle (see
     * playground::BAKED_IN_PLUGINS) — a real fix for the sandbox's
     * third-party-plugin DB-install limitation, found live 2026-07-19
     * (dev-docs/oer-platform/discoveries/2026-07-19-sandbox-thirdparty-plugin-db-install-limitation.md).
     */
    public function test_is_baked_in_recognises_the_known_baked_in_plugin(): void {
        $this->assertTrue(playground::is_baked_in('mod', 'quizquest', '5.2'));
    }

    public function test_is_baked_in_is_false_for_a_plugin_not_baked_into_that_branch(): void {
        $this->assertFalse(playground::is_baked_in('mod', 'board', '5.2'));
    }

    public function test_is_baked_in_is_false_for_a_branch_with_no_baked_in_plugins(): void {
        $this->assertFalse(playground::is_baked_in('mod', 'quizquest', '5.0'));
    }

    public function test_is_baked_in_is_false_for_an_unknown_branch(): void {
        $this->assertFalse(playground::is_baked_in('mod', 'quizquest', '4.4'));
    }

    /**
     * When the caller resolves and passes a branch, a baked-in plugin's
     * runtime installMoodlePlugin step is skipped entirely — it's already
     * present and fully registered in that branch's bundle, so attempting
     * the runtime install would be redundant at best.
     */
    public function test_build_blueprint_skips_the_install_step_for_a_baked_in_plugin(): void {
        $blueprint = playground::build_blueprint(
            'My Course',
            'https://exchange.example/local/oerexchange/download.php?v=1&exp=2&sig=abc',
            [['type' => 'mod', 'name' => 'quizquest', 'zipurl' => 'https://exchange.example/allowlist_file.php?id=1']],
            '5.2'
        );

        $steps = array_column($blueprint['steps'], 'step');
        $this->assertSame(['installMoodle', 'login', 'restoreCourse'], $steps);
    }

    /**
     * A plugin that ISN'T baked in still gets the ordinary runtime install
     * step, even when a branch is passed — the skip is per-plugin, not
     * all-or-nothing for the branch.
     */
    public function test_build_blueprint_still_installs_a_non_baked_in_plugin_at_runtime(): void {
        $blueprint = playground::build_blueprint(
            'My Course',
            'https://exchange.example/local/oerexchange/download.php?v=1&exp=2&sig=abc',
            [['type' => 'mod', 'name' => 'board', 'zipurl' => 'https://exchange.example/allowlist_file.php?id=1']],
            '5.2'
        );

        $steps = array_column($blueprint['steps'], 'step');
        $this->assertSame(['installMoodle', 'login', 'installMoodlePlugin', 'restoreCourse'], $steps);
    }

    /**
     * Omitting the branch (the default, '') never skips a runtime install —
     * a caller that hasn't resolved a branch yet must not silently drop a
     * plugin install it didn't actually verify is baked in.
     */
    public function test_build_blueprint_without_a_branch_never_skips_the_install_step(): void {
        $blueprint = playground::build_blueprint(
            'My Course',
            'https://exchange.example/local/oerexchange/download.php?v=1&exp=2&sig=abc',
            [['type' => 'mod', 'name' => 'quizquest', 'zipurl' => 'https://exchange.example/allowlist_file.php?id=1']]
        );

        $steps = array_column($blueprint['steps'], 'step');
        $this->assertSame(['installMoodle', 'login', 'installMoodlePlugin', 'restoreCourse'], $steps);
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
