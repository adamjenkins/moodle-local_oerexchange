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
$plugin->version   = 2026071800;
// 2025041400 = the Moodle 5.0 branching version — matches $supported's floor.
// Was 2024100700 (Moodle 4.5), which let a site below the tested/supported
// range install the plugin; found on the fourth MDL Shield audit pass
// (2026-07-19). Nine sibling plugins in this workspace already carry the
// corrected value for the same supported=[500, 502] range (verified via
// `grep -rn "requires\s*=" */version.php`, 2026-07-19).
$plugin->requires  = 2025041400;
$plugin->supported = [500, 502];
$plugin->release   = '0.1.0';
$plugin->maturity  = MATURITY_ALPHA;
