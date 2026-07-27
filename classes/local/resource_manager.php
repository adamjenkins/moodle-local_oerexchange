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
     * The statuses only a moderator may put a resource into, and only a
     * moderator may take it out of.
     *
     * 'modhidden' is a takedown, 'removed' is a removal (by a moderator, or
     * by the automatic abandoned-courseware sweep). Both are deliberately
     * distinct from the author's own 'hidden' so that an author-facing
     * control can never undo a moderation decision — see
     * dev-docs/oer-platform/AUTHOR-CONTROL-COMPLETION-DESIGN.md §1.
     *
     * @var string[]
     */
    const MODERATOR_HELD_STATUSES = ['modhidden', 'removed'];

    /**
     * Publish a draft-area backup as a resource (new, or a new version of an
     * existing one when $resourceid is given).
     *
     * @param int $draftitemid draft area holding exactly one .mbz file
     * @param int $creatorid Exchange-local userid: the creator of a new resource, and in
     *                        every case the user whose draft area $draftitemid lives in
     * @param int|null $siteid the registered site the share came from, or null for a direct
     *                          upload with no client site involved
     * @param array $metadata title, summary, language, tags, licenseshortname, type, activitytype
     * @param int|null $resourceid null to create a new resource, or an existing resource's id
     *                              ($creatorid must be able to edit it — its creator, one of its
     *                              co-authors, or a moderator) to add a version to it
     * @return array [resourceid, versionid]
     */
    public static function publish(
        int $draftitemid,
        int $creatorid,
        ?int $siteid,
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

        if ($isnewresource) {
            // Server-side validation shared by every publish path (WS,
            // .mbz upload page, data upload page): a client-side 'required'
            // attribute is not a guarantee, and the license field is
            // rendered/filtered all over the catalogue, so only shortnames
            // core's license manager actually knows may enter it.
            if (trim((string) $metadata['title']) === '') {
                throw new \moodle_exception('error_notitle', 'local_oerexchange');
            }
            global $CFG;
            require_once($CFG->libdir . '/licenselib.php');
            if (!\license_manager::get_license_by_shortname($metadata['licenseshortname'])) {
                throw new \moodle_exception('error_invalidlicense', 'local_oerexchange');
            }
        }

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
                $isdataresource = ($metadata['type'] === 'data');
                $resourceid = (int) $DB->insert_record('local_oerexchange_resources', (object) [
                    'type' => $metadata['type'],
                    'title' => $metadata['title'],
                    'summary' => $metadata['summary'] ?? '',
                    'language' => $metadata['language'] ?? '',
                    'tags' => $metadata['tags'] ?? '',
                    'licenseshortname' => $metadata['licenseshortname'],
                    'activitytype' => $metadata['activitytype'] ?? null,
                    'dataresourcetype' => $metadata['dataresourcetype'] ?? null,
                    'courseformat' => null,
                    'creatorid' => $creatorid,
                    'siteid' => $siteid,
                    // Data resources have no async structural validation (they
                    // aren't Moodle backups) — nothing will ever flip them out of
                    // 'pending', so they publish immediately once the caller's own
                    // synchronous extension/MIME check (Task 5) has already passed.
                    // Course/activity resources start 'pending' and are flipped to
                    // 'published' by parse_backup_task only once sanitycheck and
                    // mbz_parser both succeed (Task 3) — closing the previous gap
                    // where a resource was publicly listed before any validation
                    // ran at all.
                    'status' => $isdataresource ? 'published' : 'pending',
                    'downloadcount' => 0,
                    'importcount' => 0,
                    'forkedfromid' => $metadata['forkedfromid'] ?? null,
                    'timeshared' => $now,
                    'timemodified' => $now,
                    // The abandoned-courseware clock starts at first publish.
                    'timefresh' => $now,
                ]);
                $versionnumber = 1;
            } else {
                $resource = $DB->get_record('local_oerexchange_resources', ['id' => $resourceid], '*', MUST_EXIST);
                // The same gate every other author-side action uses, rather
                // than a bare creatorid comparison: a co-author holds full
                // parity with the creator, so they may replace the file too.
                // (It also settles an inconsistency that predates them — the
                // two upload pages already let a moderator through their own
                // gate, only for this line to reject them here.)
                //
                // Note this does NOT change who the version is attributed to:
                // resources.creatorid is untouched by an update, so replacing
                // the file never transfers ownership of the entry.
                if (!self::user_can_edit_resource($resource, $creatorid)) {
                    throw new \moodle_exception('error_notyourresource', 'local_oerexchange');
                }
                $versionnumber = 1 + (int) $DB->get_field_sql(
                    'SELECT MAX(versionnumber) FROM {local_oerexchange_versions} WHERE resourceid = ?',
                    [$resourceid]
                );
                // Bump only the timestamps with a targeted UPDATE. Writing the whole
                // $resource object back (update_record) would re-persist the
                // downloadcount/importcount values read above, clobbering any atomic
                // "col = col + 1" increment committed concurrently by get_resource or
                // record_import — the exact lost-update the atomic increments exist
                // to prevent. A new version is an update in the abandoned-courseware
                // sense, so it also winds the freshness clock forward and cancels
                // any pending stale removal.
                $DB->execute(
                    'UPDATE {local_oerexchange_resources}
                        SET timemodified = ?, timefresh = ?, stalenotifiedtime = 0
                      WHERE id = ?',
                    [$now, $now, $resourceid]
                );
            }

            $isdataresource = ($metadata['type'] === 'data');
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
                // A data resource is not a Moodle backup — there is nothing for
                // parse_backup_task to parse, and can_download_unsigned()/
                // download.php gate on version status = 'ready', so this must be
                // 'ready' immediately rather than left at 'parsing' forever.
                'status' => $isdataresource ? 'ready' : 'parsing',
                'parseerror' => null,
                'timecreated' => $now,
            ]);

            // Move the file out of the draft area into permanent storage, itemid = versionid.
            file_save_draft_area_files($draftitemid, $context->id, 'local_oerexchange', 'resource', $versionid);
            $DB->set_field('local_oerexchange_versions', 'itemid', $versionid, ['id' => $versionid]);

            if (!$isdataresource) {
                $task = new parse_backup_task();
                $task->set_custom_data(['versionid' => $versionid]);
                \core\task\manager::queue_adhoc_task($task);
            } else {
                // A data resource is 'ready' the moment it is stored (there is
                // nothing to parse), so the version it replaces can be retired
                // right away. For course/activity resources this happens in
                // parse_backup_task instead, once validation has succeeded.
                self::supersede_old_versions($resourceid, $versionid);
            }

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
     * Whether $userid may edit a resource — the resource's creator, one of its
     * co-authors, or anyone holding local/oerexchange:moderate.
     *
     * This is the ONE gate for every author-side action in the plugin
     * (thumbnail, hide/unhide, delete, Try-it opt-out, freshness confirmation,
     * file replacement, and the co-author list itself). Keeping it single is
     * the point — final whole-branch review finding 5 was two copies of it
     * with subtly different guards — so a co-author gets full parity with the
     * creator by construction rather than by a list of features somebody has
     * to remember to extend.
     *
     * $userid must be truthy AND match $resource->creatorid for the owner
     * branch — a tombstoned/anonymized resource has creatorid = 0
     * (profile_manager::delete_creator_resource()), and 0 must never match
     * as "owner" no matter what $userid is passed. In practice this method
     * is only ever called with a real, logged-in, non-guest user's id (the
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
        // Null-coalesced rather than reading ->id directly: several callers
        // (and the existing unit tests) build a partial row holding only the
        // fields the check needs, and is_coauthor() already treats a 0
        // resourceid as "no".
        if ($userid && coauthor_manager::is_coauthor((int) ($resource->id ?? 0), $userid)) {
            return true;
        }
        return has_capability('local/oerexchange:moderate', \context_system::instance(), $userid);
    }

    /**
     * Whether $userid may delete a resource outright.
     *
     * Everyone who can edit it, EXCEPT while a moderator is holding it: an
     * author (or a co-author they added) must not be able to delete a
     * resource that has been taken down or removed. Deleting it runs
     * profile_manager::delete_creator_resource(), which deletes the
     * resource's report rows and flips it to 'deleted' — so the subject of a
     * complaint could destroy both the complaint and the record that a
     * takedown ever happened, and the entry would vanish from both of
     * moderate.php's lists (open reports, and status IN modhidden/removed).
     *
     * This is the delete-shaped half of the same rule set_hidden() already
     * enforces for visibility: the author's own control may not reverse a
     * moderator's. A moderator may still delete, and the GDPR erasure path is
     * deliberately NOT routed through here — a person's right to erasure is
     * not suspended by their content being under moderation, so
     * delete_creator_resource() itself stays unguarded and the privacy
     * provider keeps calling it directly.
     *
     * @param \stdClass $resource a row from local_oerexchange_resources
     * @param int $userid
     * @return bool
     */
    public static function user_can_delete_resource(\stdClass $resource, int $userid): bool {
        if (!self::user_can_edit_resource($resource, $userid)) {
            return false;
        }
        if (!in_array($resource->status, self::MODERATOR_HELD_STATUSES, true)) {
            return true;
        }
        return has_capability('local/oerexchange:moderate', \context_system::instance(), $userid);
    }

    /**
     * The one version of a resource that is currently served — the newest one
     * that finished validating.
     *
     * @param int $resourceid
     * @return \stdClass|null null while a first upload is still parsing, or if every parse failed
     */
    public static function get_current_version(int $resourceid): ?\stdClass {
        global $DB;

        $rows = $DB->get_records(
            'local_oerexchange_versions',
            ['resourceid' => $resourceid, 'status' => 'ready'],
            'versionnumber DESC',
            '*',
            0,
            1
        );

        return $rows ? reset($rows) : null;
    }

    /**
     * Retire every version of a resource except $keepversionid: delete the
     * stored file and mark the row 'superseded'.
     *
     * This is what makes "only ever one version on the Exchange" true. An
     * update uploads into a *new* version row and only calls this once that
     * row has actually validated, so there is never a window where the
     * resource has nothing downloadable, and a failed update leaves the
     * previous good version serving untouched.
     *
     * The rows themselves are kept on purpose. local_oerexchange_imports and
     * local_oerexchange_trials both record which versionid was taken, and
     * deleting the row would dangle those references; a 'superseded' row with
     * no file keeps the history readable while every consumer that looks for
     * status = 'ready' (resource.php, download.php via
     * can_download_unsigned(), sandbox_launch.php) skips it automatically.
     *
     * @param int $resourceid
     * @param int $keepversionid the version that must survive
     * @return int how many versions were superseded
     */
    public static function supersede_old_versions(int $resourceid, int $keepversionid): int {
        global $DB;

        $stale = $DB->get_records_select(
            'local_oerexchange_versions',
            'resourceid = ? AND id <> ? AND status <> ?',
            [$resourceid, $keepversionid, 'superseded'],
            '',
            'id'
        );
        if (empty($stale)) {
            return 0;
        }

        $fs = get_file_storage();
        $contextid = \context_system::instance()->id;
        foreach ($stale as $version) {
            $fs->delete_area_files($contextid, 'local_oerexchange', 'resource', $version->id);
            $DB->set_field('local_oerexchange_versions', 'status', 'superseded', ['id' => $version->id]);
        }

        return count($stale);
    }

    /**
     * One-off upgrade helper: apply the single-version rule to resources that
     * already accumulated several versions under the old behaviour.
     *
     * @return int how many versions were superseded across all resources
     */
    public static function supersede_all_stale_versions(): int {
        global $DB;

        $resourceids = $DB->get_fieldset_sql(
            'SELECT DISTINCT resourceid FROM {local_oerexchange_versions}'
        );

        $total = 0;
        foreach ($resourceids as $resourceid) {
            $current = self::get_current_version((int) $resourceid);
            if ($current) {
                $total += self::supersede_old_versions((int) $resourceid, (int) $current->id);
            }
        }

        return $total;
    }

    /**
     * Whether $userid may see a resource's page at all.
     *
     * Anything published is public (the catalogue is browsable without
     * logging in). Anything else — pending, hidden, removed — is visible only
     * to the people who can act on it.
     *
     * Authors are included deliberately: before this existed, resource.php
     * gated non-published resources on the moderator capability alone, so an
     * author who hid their own resource was locked out of the only page that
     * could unhide it. 'deleted' never reaches here — resource.php renders
     * its tombstone before any access check.
     *
     * @param \stdClass $resource a row from local_oerexchange_resources
     * @param int $userid 0 for a logged-out visitor
     * @return bool
     */
    public static function user_can_view_resource(\stdClass $resource, int $userid): bool {
        if ($resource->status === 'published') {
            return true;
        }
        return self::user_can_edit_resource($resource, $userid);
    }

    /**
     * Whether a sandbox trial of $resourceid by $userid should be recorded, or
     * folded into one this viewer already has from the last few minutes.
     *
     * sandbox_launch.php wrote a row on every hit. A trial that fails to boot
     * invites reloading, and when the admin allows anonymous downloads that
     * page is reachable with no session at all, so "every hit" was an
     * unbounded, unauthenticated insert. block_oerexchangequicklinks reads
     * only the newest trial per resource for the current user, so collapsing
     * a burst loses nothing that is displayed.
     *
     * @param int $resourceid
     * @param int|null $userid null for an anonymous viewer, whose rows are telemetry only
     * @param int $window seconds within which an existing trial counts as the same visit
     * @return bool
     */
    public static function should_record_trial(int $resourceid, ?int $userid, int $window = 300): bool {
        global $DB;

        $cutoff = time() - $window;

        $recent = $userid
            ? $DB->record_exists_select(
                'local_oerexchange_trials',
                'resourceid = ? AND userid = ? AND timecreated > ?',
                [$resourceid, $userid, $cutoff]
            )
            : $DB->record_exists_select(
                'local_oerexchange_trials',
                'resourceid = ? AND userid IS NULL AND timecreated > ?',
                [$resourceid, $cutoff]
            );

        return !$recent;
    }

    /**
     * Hide or unhide a resource, as its author or a moderator.
     *
     * Hiding flips status to 'hidden', which every consumer already honours —
     * index.php, the search and get_resource web services, sandbox_launch.php,
     * lib.php's pluginfile gate, the profile page's resource list and the
     * badge task all select on status = 'published'. Unhiding returns it to
     * 'published'.
     *
     * Only 'published' and 'hidden' are flipped between: a resource sitting
     * in 'pending' (awaiting its first structural validation) or 'removed'
     * (taken down by a moderator) must not be quietly published by an author
     * pressing "unhide", so those are left alone.
     *
     * @param \stdClass $resource a row from local_oerexchange_resources
     * @param bool $hidden true to hide, false to unhide
     * @return bool whether the status actually changed
     */
    public static function set_hidden(\stdClass $resource, bool $hidden): bool {
        global $DB;

        $from = $hidden ? 'published' : 'hidden';
        $to = $hidden ? 'hidden' : 'published';
        if ($resource->status !== $from) {
            return false;
        }

        $DB->update_record('local_oerexchange_resources', (object) [
            'id' => $resource->id,
            'status' => $to,
            'timemodified' => time(),
        ]);

        return true;
    }
}
