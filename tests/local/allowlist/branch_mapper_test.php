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
 * Tests for turning a plugin's own version.php declarations into the set of
 * sandbox branches an allowlist entry should be created for.
 *
 * This is the step that removes the "fill the form in once per Moodle branch"
 * tedium, so it has to be right in both directions: a branch wrongly included
 * produces an allowlist entry whose ZIP does not actually run on that branch,
 * and a branch wrongly excluded silently leaves a trial without the plugin it
 * needed.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(branch_mapper::class)]
final class branch_mapper_test extends \basic_testcase {
    /**
     * The deployed set these tests reason about, stated explicitly rather
     * than read from playground::DEPLOYED_BRANCHES so that deploying a new
     * sandbox branch cannot silently change what these assertions mean.
     *
     * @var string[]
     */
    private const DEPLOYED = ['5.0', '5.2'];

    public function test_a_declared_range_is_read_as_a_range_not_a_list(): void {
        // The discriminating case. $plugin->supported is a two-element
        // RANGE — core enforces exactly that shape and rejects anything else
        // (lib/classes/plugininfo/base.php:313-320). Read as a membership
        // list, [404, 502] would match only 5.2; read correctly it spans
        // 4.4 through 5.2 and so covers both deployed branches.
        $result = branch_mapper::branches_for([404, 502], null, null, self::DEPLOYED);

        $this->assertSame(['5.0', '5.2'], $result->branches);
        $this->assertSame(branch_mapper::DECLARED, $result->confidence);
    }

    public function test_a_range_can_exclude_a_deployed_branch(): void {
        $result = branch_mapper::branches_for([502, 502], null, null, self::DEPLOYED);

        $this->assertSame(['5.2'], $result->branches);
        $this->assertSame(branch_mapper::DECLARED, $result->confidence);
    }

    public function test_a_range_ending_below_everything_deployed_yields_nothing(): void {
        // A plugin for 3.9-4.1 only. The caller has to surface this as "this
        // plugin does not support any branch the sandbox runs" rather than
        // quietly writing no rows.
        $result = branch_mapper::branches_for([309, 401], null, null, self::DEPLOYED);

        $this->assertSame([], $result->branches);
        $this->assertSame(branch_mapper::DECLARED, $result->confidence);
    }

    public function test_a_malformed_supported_declaration_falls_back_rather_than_throwing(): void {
        // Core would throw a coding_exception here. This runs against a
        // stranger's ZIP, so a malformed declaration must degrade to the
        // requires-based inference, not take the page down.
        $result = branch_mapper::branches_for([500], null, null, self::DEPLOYED);

        $this->assertSame(['5.0', '5.2'], $result->branches);
        $this->assertSame(branch_mapper::UNVERIFIED, $result->confidence);
    }

    public function test_without_supported_the_minimum_core_version_is_used(): void {
        // Here requires is the 5.2 branching version, so 5.0 is out.
        $result = branch_mapper::branches_for(null, 2026042000, null, self::DEPLOYED);

        $this->assertSame(['5.2'], $result->branches);
        $this->assertSame(branch_mapper::INFERRED, $result->confidence);
    }

    public function test_a_requires_below_every_deployed_branch_keeps_them_all(): void {
        $result = branch_mapper::branches_for(null, 2025041400, null, self::DEPLOYED);

        $this->assertSame(['5.0', '5.2'], $result->branches);
        $this->assertSame(branch_mapper::INFERRED, $result->confidence);
    }

    public function test_a_requires_above_every_deployed_branch_yields_nothing(): void {
        $result = branch_mapper::branches_for(null, 2099010100, null, self::DEPLOYED);

        $this->assertSame([], $result->branches);
        $this->assertSame(branch_mapper::INFERRED, $result->confidence);
    }

    public function test_with_no_declarations_at_all_every_deployed_branch_is_offered_unverified(): void {
        // Nothing to go on. Offering all of them with the confidence flag set
        // lets the admin decide, which is better than refusing a plugin whose
        // author simply never filled in the optional fields.
        $result = branch_mapper::branches_for(null, null, null, self::DEPLOYED);

        $this->assertSame(['5.0', '5.2'], $result->branches);
        $this->assertSame(branch_mapper::UNVERIFIED, $result->confidence);
    }

    public function test_incompatible_removes_a_branch_the_range_allowed(): void {
        // The supported range says 4.4-5.2; incompatible says 5.2 and up is broken.
        // Core's rule is branch >= incompatible (base.php:456-462), so 5.2 goes.
        $result = branch_mapper::branches_for([404, 502], null, 502, self::DEPLOYED);

        $this->assertSame(['5.0'], $result->branches);
        $this->assertSame(branch_mapper::DECLARED, $result->confidence);
    }

    public function test_incompatible_applies_to_the_inferred_path_too(): void {
        $result = branch_mapper::branches_for(null, 2025041400, 502, self::DEPLOYED);

        $this->assertSame(['5.0'], $result->branches);
        $this->assertSame(branch_mapper::INFERRED, $result->confidence);
    }

    public function test_branch_labels_convert_to_core_branch_numbers(): void {
        // The label 5.10 must be 510, not 51 — the same dot-handling trap that
        // allowlist_manager::is_valid_branch() exists to guard.
        $this->assertSame(500, branch_mapper::branch_number('5.0'));
        $this->assertSame(502, branch_mapper::branch_number('5.2'));
        $this->assertSame(404, branch_mapper::branch_number('4.4'));
        $this->assertSame(510, branch_mapper::branch_number('5.10'));
    }

    public function test_a_branch_with_no_known_core_version_is_skipped_on_the_inferred_path(): void {
        // Inference needs the branch's core version; there is no honest
        // answer for a branch not in the map, so it drops out rather than
        // being guessed either way.
        $result = branch_mapper::branches_for(null, 2025041400, null, ['5.0', '9.9']);

        $this->assertSame(['5.0'], $result->branches);
    }
}
