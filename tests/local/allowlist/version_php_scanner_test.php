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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the two version.php declarations core's own parser does not read.
 *
 * Core's \core\update\validator::parse_version_php() matches only
 * version|maturity|release|requires|component (lib/classes/update/validator.php
 * :537-540), and core_plugin_manager carries a standing
 * "TODO Check for missing dependencies during validation"
 * (lib/classes/plugin_manager.php:1382). So $plugin->supported (which decides
 * which sandbox branches an allowlist entry is created for) and
 * $plugin->dependencies (which decides what else gets pulled onto the
 * allowlist) have to be read here.
 *
 * The scanner tokenises; it never includes or evals. A version.php arrives
 * from an admin-supplied URL or upload, and the Exchange must never be its
 * execution host — test_scanning_never_executes_the_file is the regression
 * test for that and must not be deleted.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(version_php_scanner::class)]
final class version_php_scanner_test extends \basic_testcase {
    public function test_reads_a_modern_supported_declaration(): void {
        $source = <<<'PHP'
        <?php
        $plugin->component = 'mod_example';
        $plugin->version = 2026010100;
        $plugin->supported = [500, 502];
        PHP;

        $this->assertSame([500, 502], version_php_scanner::supported($source));
    }

    public function test_reads_the_long_array_syntax_and_the_module_prefix(): void {
        // Older plugins still ship both spellings; core's own regex accepts
        // $module-> for the fields it reads, so this scanner has to as well.
        $source = <<<'PHP'
        <?php
        $module->supported = array(405, 500);
        PHP;

        $this->assertSame([405, 500], version_php_scanner::supported($source));
    }

    public function test_supported_survives_comments_and_odd_whitespace(): void {
        $source = <<<'PHP'
        <?php
        $plugin->supported   =   [
            // Oldest branch we still test against.
            405,
            /* and the newest */ 502,
        ];
        PHP;

        $this->assertSame([405, 502], version_php_scanner::supported($source));
    }

    public function test_an_undeclared_supported_is_null_not_empty(): void {
        // The distinction drives branch_mapper: null means "fall back to
        // $plugin->requires and flag the result as inferred", whereas an
        // empty array would mean "the author says: no branch at all".
        $source = <<<'PHP'
        <?php
        $plugin->component = 'mod_example';
        $plugin->requires = 2025041400;
        PHP;

        $this->assertNull(version_php_scanner::supported($source));
    }

    public function test_an_explicitly_empty_supported_is_an_empty_array(): void {
        $source = <<<'PHP'
        <?php
        $plugin->supported = [];
        PHP;

        $this->assertSame([], version_php_scanner::supported($source));
    }

    public function test_reads_dependencies_with_versions_and_any_version(): void {
        // Both spellings occur in core today: report/competency/version.php uses
        // the ANY_VERSION constant, lib/editor/tiny/plugins/aiplacement uses ints.
        $source = <<<'PHP'
        <?php
        $plugin->dependencies = [
            'tool_lp' => ANY_VERSION,
            'aiplacement_editor' => 2026041000,
        ];
        PHP;

        $this->assertSame([
            'tool_lp' => 'any',
            'aiplacement_editor' => 2026041000,
        ], version_php_scanner::dependencies($source));
    }

    public function test_dependencies_survive_trailing_commas_quotes_and_trailing_comments(): void {
        // Core's own enrol/lti version file carries a trailing line comment
        // inside the array literal.
        $source = <<<'PHP'
        <?php
        $plugin->dependencies = array(
            "auth_lti" => 2026041000, // Must be present.
            'mod_quiz'   =>   ANY_VERSION,
        );
        PHP;

        $this->assertSame([
            'auth_lti' => 2026041000,
            'mod_quiz' => 'any',
        ], version_php_scanner::dependencies($source));
    }

    public function test_undeclared_dependencies_are_an_empty_array(): void {
        $source = <<<'PHP'
        <?php
        $plugin->component = 'mod_example';
        PHP;

        $this->assertSame([], version_php_scanner::dependencies($source));
    }

    public function test_a_declaration_mentioned_only_in_a_comment_or_string_is_ignored(): void {
        // A naive regex over the raw file matches all three of these.
        $source = <<<'PHP'
        <?php
        // Set $plugin->supported = [401, 405]; when we drop 5.0.
        $notice = 'remember $plugin->dependencies = ["mod_fake" => 1];';
        /* $plugin->supported = [100]; */
        $plugin->component = 'mod_example';
        PHP;

        $this->assertNull(version_php_scanner::supported($source));
        $this->assertSame([], version_php_scanner::dependencies($source));
    }

    public function test_a_computed_declaration_is_reported_as_undeclared_rather_than_guessed(): void {
        // Nothing in the tokens says what $branches holds. Returning null (and
        // for dependencies, dropping the unreadable entry) sends branch_mapper
        // down its "inferred, flag it" path instead of inventing a value.
        $source = <<<'PHP'
        <?php
        $branches = [500, 502];
        $plugin->supported = $branches;
        $plugin->dependencies = ['local_real' => 2026010100, 'local_computed' => $someversion];
        PHP;

        $this->assertNull(version_php_scanner::supported($source));
        $this->assertSame(['local_real' => 2026010100], version_php_scanner::dependencies($source));
    }

    public function test_scanning_never_executes_the_file(): void {
        // The whole reason this class exists rather than an include(). The
        // source below is what a hostile "plugin" zip would carry.
        $canary = make_request_directory() . '/canary.txt';
        $source = <<<PHP
        <?php
        \$plugin->supported = [502];
        file_put_contents('{$canary}', 'executed');
        PHP;

        $this->assertSame([502], version_php_scanner::supported($source));
        $this->assertFileDoesNotExist($canary);
    }

    public function test_reads_the_component_name(): void {
        $source = <<<'PHP'
        <?php
        $plugin->component = 'mod_quizquest';
        PHP;

        $this->assertSame('mod_quizquest', version_php_scanner::component($source));
    }

    public function test_a_missing_or_malformed_component_is_null(): void {
        $this->assertNull(version_php_scanner::component('<?php $plugin->version = 1;'));
        // No underscore: not a frankenstyle name, so there is no plugin type
        // to place it under.
        $this->assertNull(version_php_scanner::component('<?php $plugin->component = "quizquest";'));
        $this->assertNull(version_php_scanner::component('<?php $plugin->component = $name;'));
    }

    public function test_reads_an_incompatible_declaration(): void {
        // Core reads this as an open-ended upper bound: branch >= 600 is out
        // (lib/classes/plugininfo/base.php:456-462).
        $source = <<<'PHP'
        <?php
        $plugin->incompatible = 600;
        PHP;

        $this->assertSame(600, version_php_scanner::incompatible($source));
    }

    public function test_an_undeclared_or_computed_incompatible_is_null(): void {
        $this->assertNull(version_php_scanner::incompatible('<?php $plugin->version = 1;'));
        $this->assertNull(version_php_scanner::incompatible('<?php $plugin->incompatible = $limit;'));
        // An expression rather than a bare literal is not evaluated.
        $this->assertNull(version_php_scanner::incompatible('<?php $plugin->incompatible = 500 + 2;'));
    }

    public function test_a_file_that_is_not_php_at_all_yields_nothing(): void {
        $this->assertNull(version_php_scanner::supported('not php at all'));
        $this->assertSame([], version_php_scanner::dependencies(''));
        $this->assertNull(version_php_scanner::incompatible('not php at all'));
    }
}
