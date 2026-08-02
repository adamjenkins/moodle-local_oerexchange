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
// Bumped for the new AJAX external function (db/services.php registrations
// are only re-read on upgrade, so without this the publish-status poller
// gets "Access control exception" on an already-installed site), for the new
// AMD modules whose cached revision is keyed on this number, and for the
// sandboxmaxbytes/maxbackupbytes settings — admin_apply_default_settings()
// only writes a new setting's default during an upgrade, so without a bump
// the values exist in the tree and nowhere in config_plugins.
$plugin->version   = 2026080302;
// 2025041400 = the Moodle 5.0 branching version — matches $supported's floor.
// Was 2024100700 (Moodle 4.5), which let a site below the tested/supported
// range install the plugin; found on the fourth MDL Shield audit pass
// (2026-07-19). Eight sibling plugins in this workspace already carry the
// exact same (requires, supported) pair for this range — recounted
// precisely on the independent second pass, 2026-07-19, correcting an
// earlier off-by-one estimate of nine.
$plugin->requires  = 2025041400;
$plugin->supported = [500, 502];
$plugin->release   = '1.0.6';
$plugin->maturity  = MATURITY_STABLE;
