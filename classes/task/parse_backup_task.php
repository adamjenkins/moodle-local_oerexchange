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

namespace local_oerexchange\task;

use local_oerexchange\local\parser\mbz_parser;
use local_oerexchange\local\resource_manager;
use local_oerexchange\local\sanitycheck;

defined('MOODLE_INTERNAL') || die();

/**
 * Adhoc task: parse an uploaded backup into a structure preview + required
 * plugins, and verify it contains no user data. Runs after publish_resource.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class parse_backup_task extends \core\task\adhoc_task {
    #[\Override]
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $versionid = (int) $data->versionid;

        $version = $DB->get_record('local_oerexchange_versions', ['id' => $versionid]);
        if (!$version) {
            mtrace("local_oerexchange: version {$versionid} no longer exists, skipping parse.");
            return;
        }

        $file = resource_manager::get_version_file($versionid);
        if (!$file) {
            $this->mark_failed($versionid, 'Backup file missing from storage.');
            return;
        }

        $tmpdir = make_temp_directory('oerexchange/parse_' . $versionid);
        $tmppath = $tmpdir . '/' . $file->get_filename();
        $file->copy_content_to($tmppath);

        try {
            if (!sanitycheck::passes($tmppath)) {
                $this->mark_failed($versionid, get_string('error_sanitycheckfailed', 'local_oerexchange'));
                return;
            }

            $parsed = mbz_parser::parse($tmppath);

            $DB->update_record('local_oerexchange_versions', (object) [
                'id' => $versionid,
                'moodleversion' => $parsed->moodleversion,
                'backupversion' => $parsed->backupversion,
                'structurejson' => $parsed->structurejson,
                'requiredplugins' => json_encode($parsed->requiredplugins),
                'status' => 'ready',
                'parseerror' => null,
            ]);

            if ($parsed->courseformat) {
                $DB->set_field(
                    'local_oerexchange_resources',
                    'courseformat',
                    $parsed->courseformat,
                    ['id' => $version->resourceid]
                );
            }
        } catch (\Throwable $e) {
            $this->mark_failed($versionid, $e->getMessage());
        } finally {
            remove_dir($tmpdir);
        }
    }

    /**
     * Mark a version as failed to parse (surfaces in the moderation queue).
     *
     * @param int $versionid
     * @param string $error
     */
    protected function mark_failed(int $versionid, string $error): void {
        global $DB;

        $DB->update_record('local_oerexchange_versions', (object) [
            'id' => $versionid,
            'status' => 'failed',
            'parseerror' => $error,
        ]);
    }
}
