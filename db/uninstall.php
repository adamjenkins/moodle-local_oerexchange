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

/**
 * Purge what core's own uninstall cleanup cannot see:
 *
 * 1. Stored files under this plugin's component — core drops our tables but
 *    knows nothing about our system-context fileareas (resource backups,
 *    mirrored allowlist ZIPs, cover images).
 * 2. The dedicated service accounts and web-service tokens minted per
 *    registered site (site_manager::create_service_account()). These live in
 *    core's own {user} / {external_tokens} tables, so dropping
 *    {local_oerexchange_sites} would strand one undeletable-looking account
 *    per site — with a live token — and lose the only record of which
 *    accounts were ours. Core runs this hook BEFORE drop_plugin_tables()
 *    (lib/adminlib.php: the uninstall lib is required at line ~180, tables go
 *    at ~240), which is what makes reading the sites table here valid.
 *
 * @return bool
 */
function xmldb_local_oerexchange_uninstall() {
    global $DB;

    $fs = get_file_storage();
    $contextid = context_system::instance()->id;
    $fs->delete_area_files($contextid, 'local_oerexchange', 'resource');
    $fs->delete_area_files($contextid, 'local_oerexchange', 'allowlist');
    $fs->delete_area_files($contextid, 'local_oerexchange', 'coverimage');

    // Stars are rows this plugin wrote into core's {favourite} table, so
    // drop_plugin_tables() cannot see them: it drops local_oerexchange_*
    // tables and nothing else, and these rows would survive the uninstall
    // pointing at resource ids that no longer exist. Same reasoning as the
    // external_tokens cleanup below.
    $DB->delete_records('favourite', ['component' => 'local_oerexchange']);

    if ($DB->get_manager()->table_exists('local_oerexchange_sites')) {
        $sites = $DB->get_records_select(
            'local_oerexchange_sites',
            'serviceuserid IS NOT NULL',
            null,
            '',
            'id, serviceuserid'
        );
        foreach ($sites as $site) {
            $DB->delete_records('external_tokens', ['userid' => $site->serviceuserid]);

            $serviceuser = \core_user::get_user((int) $site->serviceuserid);
            // Only ever delete an account that still looks like one we minted
            // (create_service_account() names them 'oersite_<siteid>'). If an
            // admin has since repurposed the row, or the id now points at a
            // real person, leaving the account alone is the safe failure —
            // an orphaned account is recoverable, a deleted person is not.
            if (
                $serviceuser
                && empty($serviceuser->deleted)
                && $serviceuser->username === 'oersite_' . $site->id
            ) {
                delete_user($serviceuser);
            }
        }
    }

    return true;
}
