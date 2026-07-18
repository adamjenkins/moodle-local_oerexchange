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
 * Uninstall steps for local_oerexchange.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Purge stored files under this plugin's own component — core's DB-table
 * cleanup on uninstall does not know about our system-context filearea
 * files (resource backups, mirrored allowlist ZIPs).
 *
 * @return bool
 */
function xmldb_local_oerexchange_uninstall() {
    $fs = get_file_storage();
    $contextid = context_system::instance()->id;
    $fs->delete_area_files($contextid, 'local_oerexchange', 'resource');
    $fs->delete_area_files($contextid, 'local_oerexchange', 'allowlist');

    return true;
}
