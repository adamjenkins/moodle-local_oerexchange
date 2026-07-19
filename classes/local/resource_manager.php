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

use local_oerexchange\task\parse_backup_task;

/**
 * Publish an uploaded backup (already landed in the teacher's Exchange-account
 * draft area via webservice/upload.php) as a new resource or a new version of
 * an existing one, then queue the parse task.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resource_manager {
    /**
     * Publish a draft-area backup as a resource (new, or a new version of an
     * existing one when $resourceid is given).
     *
     * @param int $draftitemid draft area holding exactly one .mbz file
     * @param int $creatorid Exchange-local userid
     * @param int $siteid the registered site the share came from
     * @param array $metadata title, summary, language, tags, licenseshortname, type, activitytype
     * @param int|null $resourceid null to create a new resource, or an existing resource's id
     *                              (must belong to $creatorid) to add a version to it
     * @return array [resourceid, versionid]
     */
    public static function publish(
        int $draftitemid,
        int $creatorid,
        int $siteid,
        array $metadata,
        ?int $resourceid = null
    ): array {
        global $DB;

        $context = \context_system::instance();
        $usercontext = \context_user::instance($creatorid);
        $fs = get_file_storage();
        $draftfiles = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false);
        if (empty($draftfiles)) {
            throw new \moodle_exception('error_nofile', 'local_oerexchange');
        }
        $draftfile = reset($draftfiles);

        $maxbytes = (int) get_config('local_oerexchange', 'maxbackupbytes') ?: (500 * 1024 * 1024);
        if ($draftfile->get_filesize() > $maxbytes) {
            throw new \moodle_exception('error_backuptoolarge', 'local_oerexchange');
        }

        $now = time();
        $isnewresource = ($resourceid === null);

        // Everything below — the resource/version rows, moving the file into
        // permanent storage, and queuing the parse task — must land atomically.
        // Without this boundary, a failure part-way through (file_save_draft_area_files()
        // throwing, or the request dying) left a permanently "published" resource
        // whose only version was stuck at 'parsing' with no file and no parse task
        // ever queued — a publicly-listed catalogue entry that could never recover.
        // Files are content-addressed, so a rollback leaves only harmless orphaned
        // content in filedir (its {files} row is rolled back with everything else);
        // the queued adhoc task's row is likewise invisible to cron until commit.
        $transaction = $DB->start_delegated_transaction();
        try {
            if ($resourceid === null) {
                $resourceid = (int) $DB->insert_record('local_oerexchange_resources', (object) [
                    'type' => $metadata['type'],
                    'title' => $metadata['title'],
                    'summary' => $metadata['summary'] ?? '',
                    'language' => $metadata['language'] ?? '',
                    'tags' => $metadata['tags'] ?? '',
                    'licenseshortname' => $metadata['licenseshortname'],
                    'activitytype' => $metadata['activitytype'] ?? null,
                    'courseformat' => null,
                    'creatorid' => $creatorid,
                    'siteid' => $siteid,
                    'status' => 'published',
                    'downloadcount' => 0,
                    'importcount' => 0,
                    'forkedfromid' => $metadata['forkedfromid'] ?? null,
                    'timeshared' => $now,
                    'timemodified' => $now,
                ]);
                $versionnumber = 1;
            } else {
                $resource = $DB->get_record('local_oerexchange_resources', ['id' => $resourceid], '*', MUST_EXIST);
                if ((int) $resource->creatorid !== $creatorid) {
                    throw new \moodle_exception('error_notyourresource', 'local_oerexchange');
                }
                $versionnumber = 1 + (int) $DB->get_field_sql(
                    'SELECT MAX(versionnumber) FROM {local_oerexchange_versions} WHERE resourceid = ?',
                    [$resourceid]
                );
                // Bump only timemodified with a targeted UPDATE. Writing the whole
                // $resource object back (update_record) would re-persist the
                // downloadcount/importcount values read above, clobbering any atomic
                // "col = col + 1" increment committed concurrently by get_resource or
                // record_import — the exact lost-update the atomic increments exist
                // to prevent.
                $DB->set_field('local_oerexchange_resources', 'timemodified', $now, ['id' => $resourceid]);
            }

            $versionid = (int) $DB->insert_record('local_oerexchange_versions', (object) [
                'resourceid' => $resourceid,
                'versionnumber' => $versionnumber,
                'itemid' => 0, // Filled in below once we know it (reused as the permanent-area itemid).
                'filename' => $draftfile->get_filename(),
                'filesize' => $draftfile->get_filesize(),
                'moodleversion' => null,
                'backupversion' => null,
                'structurejson' => null,
                'requiredplugins' => null,
                'status' => 'parsing',
                'parseerror' => null,
                'timecreated' => $now,
            ]);

            // Move the file out of the draft area into permanent storage, itemid = versionid.
            file_save_draft_area_files($draftitemid, $context->id, 'local_oerexchange', 'resource', $versionid);
            $DB->set_field('local_oerexchange_versions', 'itemid', $versionid, ['id' => $versionid]);

            $task = new parse_backup_task();
            $task->set_custom_data(['versionid' => $versionid]);
            \core\task\manager::queue_adhoc_task($task);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The rollback() call re-throws $e after unwinding the transaction, so
            // callers still see the original failure and no partial state is committed.
            $transaction->rollback($e);
        }

        if ($isnewresource) {
            // A creator's profile is lazily created on their first publish
            // (design: "auto-created once they publish") — this is the ONLY
            // production call site that ever creates one; profile_edit_controller
            // only reaches an existing profile (get_by_slug()), never creates
            // one. Deliberately outside the transaction above:
            // get_or_create_for_user() is its own idempotent, TOCTOU-safe
            // operation (see its docblock) that doesn't need the resource
            // insert's atomicity, and keeping it out keeps that transaction
            // focused on the resource/version/file invariant it documents.
            // Deliberately NOT called for the "new version of an existing
            // resource" branch above ($isnewresource false) — that creator's
            // profile, if any, already exists; publishing a second version
            // isn't a new "first publish" event.
            profile_manager::get_or_create_for_user($creatorid);
        }

        return [$resourceid, $versionid];
    }

    /**
     * Fetch the stored file for a version.
     *
     * @param int $versionid
     * @return \stored_file|null
     */
    public static function get_version_file(int $versionid): ?\stored_file {
        $context = \context_system::instance();
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'local_oerexchange', 'resource', $versionid, 'id', false);
        return $files ? reset($files) : null;
    }

    /**
     * Whether a version's file may be downloaded by a plain logged-in session
     * (i.e. without a valid signed URL). Signed downloads bypass this check
     * entirely (they carry their own short-lived authorization). This is the
     * same visibility rule resource.php applies: published + ready, or the
     * viewer can moderate.
     *
     * @param \stdClass $version
     * @param \stdClass $resource
     * @return bool
     */
    public static function can_download_unsigned(\stdClass $version, \stdClass $resource): bool {
        if ($resource->status === 'published' && $version->status === 'ready') {
            return true;
        }
        return has_capability('local/oerexchange:moderate', \context_system::instance());
    }

    /**
     * Whether $userid may edit a resource's own metadata (currently: its
     * cover-image thumbnail) — the resource's creator, or anyone holding
     * local/oerexchange:moderate. Shared by resource.php's editthumbnail
     * action handler and its display gate (final whole-branch review finding
     * 5: those two previously duplicated this check with subtly different
     * guards).
     *
     * $userid must be truthy AND match $resource->creatorid for the owner
     * branch — a tombstoned/anonymized resource has creatorid = 0
     * (profile_manager::delete_creator_resource()), and 0 must never match
     * as "owner" no matter what $userid is passed. In practice this method
     * is only ever called with a real, logged-in, non-guest user's id (both
     * call sites in resource.php gate on isloggedin() && !isguestuser()
     * before calling it), so $userid is never 0 itself — this guard exists
     * for defence in depth, not because a 0 caller is expected.
     *
     * @param \stdClass $resource a row from local_oerexchange_resources
     * @param int $userid
     * @return bool
     */
    public static function user_can_edit_resource(\stdClass $resource, int $userid): bool {
        if ($userid && (int) $resource->creatorid === $userid) {
            return true;
        }
        return has_capability('local/oerexchange:moderate', \context_system::instance(), $userid);
    }
}
