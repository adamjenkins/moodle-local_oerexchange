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

/**
 * Re-derive the required-plugins list of every current, already-parsed version.
 *
 * Exists so that improvements to mbz_parser's detection (it originally saw
 * only activity modules and the course format; it now also reads question
 * types, mod subplugins, question behaviours, grading methods and blocks out
 * of the archive) can be applied retroactively to the catalogue: everything
 * that displays or acts on plugin requirements — resource.php's badge list
 * and "Try it" warning, sandbox_launch.php's install list, the get_resource
 * web service — reads the versions row's requiredplugins column live, so
 * updating that one column updates them all, trial availability included.
 *
 * Deliberately NARROW, by contrast with parse_backup_task: this task updates
 * requiredplugins and nothing else. It never re-runs the sanity check (whose
 * failure path purges the stored file), never touches version or resource
 * status, courseformat, supersession or the cover image (whose re-extraction
 * could overwrite an author-uploaded thumbnail). A version it cannot re-parse
 * is reported to mtrace and left exactly as it was.
 *
 * Registered disabled in db/tasks.php: it is an on-demand backfill (run it
 * from Server > Scheduled tasks > "Run now", or
 * admin/cli/scheduled_task.php --execute), not routine maintenance.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rescan_required_plugins_task extends \core\task\scheduled_task {
    /**
     * Returns the task name shown in the scheduled tasks admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_rescanrequiredplugins', 'local_oerexchange');
    }

    /**
     * Re-parse each current version's stored .mbz and update its
     * requiredplugins column where the derived list changed.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        // Current versions only: 'ready' rows of non-'data' resources.
        // Superseded rows have had their file deleted (kept only so old
        // references resolve), 'parsing'/'failed' rows belong to
        // parse_backup_task, and 'data' resources are not Moodle backups.
        $rs = $DB->get_recordset_sql("
            SELECT v.id, v.resourceid, v.requiredplugins
              FROM {local_oerexchange_versions} v
              JOIN {local_oerexchange_resources} r ON r.id = v.resourceid
             WHERE v.status = 'ready' AND r.type <> 'data'
          ORDER BY v.id ASC");

        $scanned = $updated = $skipped = 0;
        foreach ($rs as $version) {
            $file = resource_manager::get_version_file((int) $version->id);
            if (!$file) {
                mtrace("local_oerexchange rescan: version {$version->id} has no stored file, skipping.");
                $skipped++;
                continue;
            }

            $tmpdir = make_temp_directory('oerexchange/rescan_' . $version->id);
            try {
                $tmppath = $tmpdir . '/' . $file->get_filename();
                $file->copy_content_to($tmppath);

                $parsed = mbz_parser::parse($tmppath);
                $scanned++;

                $newjson = json_encode($parsed->requiredplugins);
                $oldlist = $version->requiredplugins ? json_decode($version->requiredplugins, true) : [];
                if (self::normalise($oldlist ?: []) === self::normalise($parsed->requiredplugins)) {
                    continue;
                }

                $DB->set_field('local_oerexchange_versions', 'requiredplugins', $newjson, ['id' => $version->id]);
                $updated++;
                mtrace("local_oerexchange rescan: version {$version->id} (resource {$version->resourceid}) "
                    . "requiredplugins updated to {$newjson}");
            } catch (\Throwable $e) {
                // Narrow task, narrow failure: report and move on. The row —
                // status, error, file — stays exactly as it was.
                mtrace("local_oerexchange rescan: version {$version->id} could not be re-parsed, "
                    . 'leaving it unchanged: ' . $e->getMessage());
                $skipped++;
            } finally {
                remove_dir($tmpdir);
            }
        }
        $rs->close();

        mtrace("local_oerexchange rescan: {$scanned} version(s) re-parsed, {$updated} updated, {$skipped} skipped.");
    }

    /**
     * Canonical comparable form of a required-plugins list, so reordered but
     * otherwise identical lists do not count as a change.
     *
     * @param array $plugins list of ['type' => string, 'name' => string]
     * @return array sorted list of "type:name" strings
     */
    protected static function normalise(array $plugins): array {
        $keys = array_map(
            static fn(array $plugin): string => $plugin['type'] . ':' . $plugin['name'],
            $plugins
        );
        sort($keys);
        return $keys;
    }
}
