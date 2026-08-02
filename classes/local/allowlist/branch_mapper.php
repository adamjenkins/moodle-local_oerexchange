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

use local_oerexchange\local\sandbox\playground;

/**
 * Decides which sandbox branches a candidate plugin gets allowlisted for.
 *
 * This is what replaces filling the allowlist form in once per Moodle branch:
 * a plugin's own version.php already says which branches it runs on, so the
 * admin should never have to type them.
 *
 * Three declarations feed in, in order of how much they are worth:
 *
 * 1. `$plugin->supported` — a two-element RANGE of branch numbers, not a
 *    membership list. Core enforces exactly that shape, requiring two ints
 *    with [0] <= [1] and throwing a coding_exception otherwise
 *    (lib/classes/plugininfo/base.php:313-320). Reading it as a list would
 *    wrongly exclude every intermediate branch.
 * 2. `$plugin->requires` — the minimum core version. Weaker: it gives a floor
 *    but says nothing about an upper bound, so a result derived from it is
 *    flagged INFERRED.
 * 3. `$plugin->incompatible` — an open-ended upper bound applied on top of
 *    either of the above, since core reads branch >= incompatible as
 *    incompatible (base.php:456-462).
 *
 * A plugin declaring none of them is offered every deployed branch, flagged
 * UNVERIFIED, rather than refused — plenty of working plugins simply never
 * filled in the optional fields, and the admin can untick.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class branch_mapper {
    /** The plugin declares a supported range and we used it. */
    public const DECLARED = 'declared';

    /** Derived from $plugin->requires; there is no declared upper bound. */
    public const INFERRED = 'inferred';

    /** The plugin declares nothing usable; every deployed branch is offered. */
    public const UNVERIFIED = 'unverified';

    /**
     * The admin chose to disregard what the plugin declares.
     *
     * A narrow $plugin->supported is very often simply stale — the plugin
     * works on a newer Moodle and its maintainer never bumped the line — and
     * without a way to say so, such a plugin cannot be allowlisted at all.
     */
    public const OVERRIDDEN = 'overridden';

    /**
     * Branches this plugin should be allowlisted for.
     *
     * @param int[]|null $supported $plugin->supported as scanned, or null
     * @param int|null $requires $plugin->requires, or null
     * @param int|null $incompatible $plugin->incompatible, or null
     * @param string[]|null $deployed dotted branch labels to choose from;
     *                                defaults to the deployed sandbox set
     * @param bool $ignoredeclared disregard $plugin->supported and
     *                             $plugin->requires and offer every deployed
     *                             branch — see OVERRIDDEN
     * @return branch_selection
     */
    public static function branches_for(
        ?array $supported,
        ?int $requires,
        ?int $incompatible = null,
        ?array $deployed = null,
        bool $ignoredeclared = false,
    ): branch_selection {
        $deployed ??= playground::DEPLOYED_BRANCHES;

        if ($ignoredeclared) {
            // What the plugin would have got on its own, so the admin can be
            // told precisely which branches they are adding against its word
            // rather than just that something was overridden.
            $declared = self::branches_for($supported, $requires, $incompatible, $deployed);

            // Note that $plugin->incompatible is deliberately still applied. A stale
            // supported range is an omission; incompatible is the maintainer
            // positively asserting the plugin is broken from that branch on,
            // which is a different kind of statement and not what "the
            // maintainer forgot to bump it" describes.
            $branches = self::filter(
                $deployed,
                static fn (int $branch): bool => $incompatible === null || $branch < $incompatible
            );

            return new branch_selection(
                $branches,
                self::OVERRIDDEN,
                array_values(array_diff($branches, $declared->branches)),
            );
        }

        if (self::is_wellformed_range($supported)) {
            $confidence = self::DECLARED;
            [$low, $high] = [(int) $supported[0], (int) $supported[1]];
            $branches = self::filter($deployed, static function (int $branch) use ($low, $high): bool {
                return $branch >= $low && $branch <= $high;
            });
        } else if ($requires !== null) {
            $confidence = self::INFERRED;
            $branches = self::filter($deployed, static function (int $branch, string $label) use ($requires): bool {
                $coreversion = playground::BRANCH_CORE_VERSIONS[$label] ?? null;

                // No known core version for this branch means there is no
                // honest comparison to make, so it drops out rather than
                // being guessed in either direction.
                return $coreversion !== null && $coreversion >= $requires;
            });
        } else {
            $confidence = self::UNVERIFIED;
            $branches = self::filter($deployed, static fn (): bool => true);
        }

        if ($incompatible !== null) {
            $branches = self::filter($branches, static fn (int $branch): bool => $branch < $incompatible);
        }

        return new branch_selection($branches, $confidence);
    }

    /**
     * The core branch number a dotted label denotes: '5.2' => 502.
     *
     * Note '5.10' is 510, not 51 — the same dot-handling trap that
     * allowlist_manager::is_valid_branch() exists to guard against.
     *
     * @param string $label e.g. '5.2'
     * @return int|null null when the label is not major.minor
     */
    public static function branch_number(string $label): ?int {
        if (!preg_match('/^(\d+)\.(\d+)$/', $label, $matches)) {
            return null;
        }

        return ((int) $matches[1]) * 100 + ((int) $matches[2]);
    }

    /**
     * Whether $plugin->supported is shaped the way core insists on.
     *
     * Core throws a coding_exception on a malformed declaration; here the
     * declaration came out of a stranger's ZIP, so a malformed one has to
     * degrade to the next-best source instead of taking the page down.
     *
     * @param int[]|null $supported
     * @return bool
     */
    private static function is_wellformed_range(?array $supported): bool {
        return $supported !== null
            && count($supported) === 2
            && array_is_list($supported)
            && is_int($supported[0])
            && is_int($supported[1])
            && $supported[0] <= $supported[1];
    }

    /**
     * Keep the deployed branch labels whose branch number satisfies $keep.
     *
     * A label that is not major.minor is dropped: it can have no branch
     * number, so no test on it could be meaningful.
     *
     * @param string[] $labels
     * @param callable $keep fn(int $branchnumber, string $label): bool
     * @return string[] re-indexed
     */
    private static function filter(array $labels, callable $keep): array {
        $kept = [];

        foreach ($labels as $label) {
            $branch = self::branch_number($label);
            if ($branch !== null && $keep($branch, $label)) {
                $kept[] = $label;
            }
        }

        return $kept;
    }
}
