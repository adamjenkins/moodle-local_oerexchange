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

/**
 * Version information for local_oerexchange.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_oerexchange';
// Bumped for the 1.0.9 release (2026081901, never released standalone,
// registered the rescan_required_plugins_task; this serial just records the
// release string on upgrade — no further schema/services change).
// 2026081903 adds the sandbox settings catalogue, whose upgrade step migrates
// the two retired multilang settings. The release string is deliberately NOT
// bumped with it: that is the owner's call (HARNESS.md §8).
// 2026082200 adds the contributor listing. Its upgrade step backfills
// profile rows for existing co-authors, who are contributors in their own
// right from this version on. The release string is deliberately NOT
// bumped with it: that is the owner's call (HARNESS.md section 8).
// 2026082201 registers the local_oerexchange_get_contributors AJAX
// function. A services.php change only reaches the external_functions
// table when the version serial moves, so adding the entry under the
// previous serial left the endpoint unregistered.
$plugin->version   = 2026082201;
// 2025041400 = the Moodle 5.0 branching version — matches $supported's floor.
// Was 2024100700 (Moodle 4.5), which let a site below the tested/supported
// range install the plugin; found on the fourth MDL Shield audit pass
// (2026-07-19). Eight sibling plugins in this workspace already carry the
// exact same (requires, supported) pair for this range — recounted
// precisely on the independent second pass, 2026-07-19, correcting an
// earlier off-by-one estimate of nine.
$plugin->requires  = 2025041400;
$plugin->supported = [500, 502];
$plugin->release   = '1.0.9';
$plugin->maturity  = MATURITY_STABLE;
