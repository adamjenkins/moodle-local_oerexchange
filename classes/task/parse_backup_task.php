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

            // Task 2's validation-gap fix: a brand-new resource starts 'pending'
            // and is only made publicly visible (search.php filters on
            // resource.status = 'published') once its first version's structural
            // validation has actually succeeded. A resource already 'published'
            // (a new version being added to it, or one that started 'data'-typed
            // and published immediately) is deliberately left untouched by this —
            // only a still-'pending' resource is eligible to flip here.
            $DB->set_field_select(
                'local_oerexchange_resources',
                'status',
                'published',
                'id = ? AND status = ?',
                [$version->resourceid, 'pending']
            );

            if ($parsed->courseformat) {
                $DB->set_field(
                    'local_oerexchange_resources',
                    'courseformat',
                    $parsed->courseformat,
                    ['id' => $version->resourceid]
                );
            }

            $this->extract_cover_image($tmppath, $tmpdir, $versionid, $version->resourceid);
        } catch (\Throwable $e) {
            $this->mark_failed($versionid, $e->getMessage());
        } finally {
            remove_dir($tmpdir);
        }
    }

    /**
     * Extract a course's cover image from its .mbz backup and store it via
     * the File API, for later display on resource.php / catalogue cards
     * (Tasks 9/10). Cosmetic only — never allowed to fail the parse itself,
     * so every failure mode here is caught and just mtrace()d.
     *
     * Only 'course' shares have a course cover image to extract; a
     * single-activity share's backup has no course-level overviewfiles entry
     * (see the design doc's "Thumbnails" decision row) so this is a no-op
     * for those, by design.
     *
     * @param string $tmppath absolute path to the copied .mbz file
     * @param string $tmpdir writable temp directory (still exists; removed
     *                       by the caller's finally block)
     * @param int $versionid
     * @param int $resourceid
     */
    protected function extract_cover_image(string $tmppath, string $tmpdir, int $versionid, int $resourceid): void {
        global $DB;

        try {
            $resourcetype = $DB->get_field('local_oerexchange_resources', 'type', ['id' => $resourceid]);
            if ($resourcetype !== 'course') {
                return;
            }

            $coverimagepath = mbz_parser::extract_cover_image($tmppath, $tmpdir);
            if ($coverimagepath && is_readable($coverimagepath)) {
                $fs = get_file_storage();
                $context = \context_system::instance();
                $fs->delete_area_files($context->id, 'local_oerexchange', 'coverimage', $resourceid);
                $fs->create_file_from_pathname([
                    'contextid' => $context->id,
                    'component' => 'local_oerexchange',
                    'filearea' => 'coverimage',
                    'itemid' => $resourceid,
                    'filepath' => '/',
                    'filename' => basename($coverimagepath),
                ], $coverimagepath);
            }
        } catch (\Throwable $e) {
            mtrace("local_oerexchange: cover image extraction failed for version {$versionid}: " . $e->getMessage());
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
