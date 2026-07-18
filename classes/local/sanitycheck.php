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

namespace local_oerexchange\local;

/**
 * Server-side verification that an uploaded backup contains no user data.
 *
 * Defense in depth: the client is expected to sanitize (users=false) before
 * upload, but the Exchange verifies independently rather than trusting the
 * client. Two checks: (1) the backup's own recorded root setting says users
 * were excluded, (2) the zip's users.xml (if present at all) contains no
 * <user> records.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sanitycheck {
    /**
     * Verify a backup file contains no user data.
     *
     * @param string $filepath absolute path to the .mbz file
     * @param \stdClass|null $rawinfo the object returned by mbz_parser::parse()'s underlying
     *                           backup_general_helper::get_backup_information_from_mbz() call
     *                           (callers that already have the raw info can pass it; otherwise
     *                           null and this re-parses it).
     * @return bool true if the backup is clean
     */
    public static function passes(string $filepath, ?\stdClass $rawinfo = null): bool {
        global $CFG;

        if ($rawinfo === null) {
            require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
            $rawinfo = \backup_general_helper::get_backup_information_from_mbz($filepath);
        }

        // Check 1: the backup's own root setting recorded users=0 at export time.
        $usersflag = $rawinfo->root_settings['users'] ?? null;
        if ($usersflag !== null && (string) $usersflag !== '0') {
            return false;
        }

        // Check 2: independently inspect users.xml in the archive, if present.
        $fp = get_file_packer('application/vnd.moodle.backup');
        $files = $fp->list_files($filepath);
        $hasusersxml = false;
        foreach ($files as $filedescriptor) {
            if ($filedescriptor->pathname === 'users.xml') {
                $hasusersxml = true;
                break;
            }
        }

        if (!$hasusersxml) {
            // No users.xml at all — nothing to check further, and check 1 already passed.
            return true;
        }

        $tmpname = 'oerexchange_sanitycheck_' . time() . '_' . random_string(4);
        $tmpdir = make_temp_directory('oerexchange/' . $tmpname);
        $extracted = $fp->extract_to_pathname($filepath, $tmpdir, ['users.xml']);
        if (!$extracted) {
            // Could not extract to verify — fail closed.
            remove_dir($tmpdir);
            return false;
        }

        $usersxmlpath = $tmpdir . '/users.xml';
        $clean = true;
        if (is_readable($usersxmlpath)) {
            $contents = file_get_contents($usersxmlpath);
            // A clean users.xml still exists as a skeleton (<users></users>) with no <user> records.
            if ($contents !== false && preg_match('/<user\\s/i', $contents)) {
                $clean = false;
            }
        }

        remove_dir($tmpdir);
        return $clean;
    }
}
