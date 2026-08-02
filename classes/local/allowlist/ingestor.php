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

/**
 * Writes a confirmed ingest plan to the allowlist.
 *
 * One row per plugin per branch, which is the shape every existing consumer
 * already reads (sandbox_launch.php's lookup, playground::is_baked_in(),
 * sandbox\config::render()'s BAKE_PLUGINS_<branch> grouping). Deriving the
 * branch set automatically changes how those rows come into existence, not
 * what they look like — so nothing downstream had to change.
 *
 * Writing is idempotent on (plugintype, pluginname, moodlebranch), which the
 * unique index added in 2026080300 now enforces: re-adding a plugin refreshes
 * its entry and replaces its mirrored ZIP rather than accumulating duplicates.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ingestor {
    /** The table every method here writes. */
    private const TABLE = 'local_oerexchange_pluginallowlist';

    /** The file area mirrored ZIPs live in — unchanged, allowlist_file.php serves from it. */
    private const FILEAREA = 'allowlist';

    /**
     * Re-label a plan against what is already on the allowlist.
     *
     * The walker cannot do this itself without a database, and keeping it
     * database-free is what makes it testable. So the ADD/REFRESH distinction
     * is settled here, immediately before the plan is shown for confirmation.
     *
     * @param ingest_plan $plan
     * @return ingest_plan
     */
    public function preview(ingest_plan $plan): ingest_plan {
        global $DB;

        $entries = [];
        foreach ($plan->entries as $entry) {
            if (!$entry->is_writable()) {
                $entries[] = $entry;
                continue;
            }

            $exists = $DB->record_exists_select(
                self::TABLE,
                'plugintype = :plugintype AND pluginname = :pluginname',
                ['plugintype' => $entry->meta->plugintype, 'pluginname' => $entry->meta->pluginname]
            );

            $entries[] = $exists ? $entry->with_action(entry_plan::REFRESH) : $entry;
        }

        return $plan->with_entries($entries);
    }

    /**
     * Write the plan.
     *
     * @param ingest_plan $plan
     * @param bool|null $bake whether these entries should be baked into the
     *                        sandbox bundle; null leaves the flag untouched,
     *                        which is what a caller without
     *                        local/oerexchange:managesandbox must pass — such
     *                        a user re-adding a plugin must not be able to
     *                        clear a bake flag they cannot even see
     * @return ingest_result
     */
    public function commit(ingest_plan $plan, ?bool $bake = null): ingest_result {
        global $DB;

        $context = \context_system::instance();
        $now = time();

        $added = 0;
        $refreshed = 0;
        $components = [];

        // Keyed component, then branch, then row id, so a dependency can point at
        // the row of the entry that pulled it in, on the same branch. The
        // plan is ordered parents-first, so a parent's ids are always
        // already here by the time its dependencies are written.
        $rowids = [];

        foreach ($plan->entries as $entry) {
            if (!$entry->is_writable()) {
                continue;
            }

            $meta = $entry->meta;
            $sha256 = hash_file('sha256', $meta->zipfilepath);
            $components[] = $meta->component;

            foreach ($entry->branches->branches as $branch) {
                $existing = $DB->get_record(self::TABLE, [
                    'plugintype' => $meta->plugintype,
                    'pluginname' => $meta->pluginname,
                    'moodlebranch' => $branch,
                ]);

                $record = (object) [
                    'plugintype' => $meta->plugintype,
                    'pluginname' => $meta->pluginname,
                    'component' => $meta->component,
                    'moodlebranch' => $branch,
                    'sourceurl' => $meta->sourceurl,
                    'sha256' => $sha256,
                    'status' => 'active',
                    'pluginversion' => $meta->version,
                    'pluginrelease' => $meta->release,
                    'parentid' => $entry->parent !== null ? ($rowids[$entry->parent][$branch] ?? null) : null,
                    'timemodified' => $now,
                ];

                // The bake flag cascades from the plugin the admin asked for
                // to everything it pulled in: a baked plugin whose dependency
                // is only installed at trial boot is a plugin that does not
                // work in the bundle it was baked into.
                if ($bake !== null) {
                    $record->bake = $bake ? 1 : 0;
                }

                if ($existing) {
                    $record->id = $existing->id;
                    $DB->update_record(self::TABLE, $record);
                    $id = (int) $existing->id;
                    $refreshed++;
                } else {
                    $record->notes = '';
                    $record->timecreated = $now;
                    $record->itemid = null;
                    $id = (int) $DB->insert_record(self::TABLE, $record);
                    $added++;
                }

                $this->store_zip($context, $id, $meta);
                $DB->set_field(self::TABLE, 'itemid', $id, ['id' => $id]);

                $rowids[$entry->component][$branch] = $id;
            }
        }

        return new ingest_result($added, $refreshed, array_values(array_unique($components)));
    }

    /**
     * Mirror the plugin ZIP into the entry's file area.
     *
     * Replaces whatever was there: an entry being refreshed must not end up
     * serving the previous release's ZIP alongside the new one, since
     * allowlist_file.php simply serves the first file it finds.
     *
     * @param \context $context
     * @param int $itemid the allowlist row's id, which is also its itemid
     * @param plugin_meta $meta
     */
    private function store_zip(\context $context, int $itemid, plugin_meta $meta): void {
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'local_oerexchange', self::FILEAREA, $itemid);

        $fs->create_file_from_pathname([
            'contextid' => $context->id,
            'component' => 'local_oerexchange',
            'filearea' => self::FILEAREA,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $meta->pluginname . '.zip',
        ], $meta->zipfilepath);
    }
}
