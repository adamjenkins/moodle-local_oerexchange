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

namespace local_oerexchange\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for local_oerexchange. All data lives under the system
 * context — this plugin has no course/module context of its own.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    #[\Override]
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_oerexchange_reviews', [
            'userid' => 'privacy:metadata:local_oerexchange_reviews:userid',
            'contexttext' => 'privacy:metadata:local_oerexchange_reviews:contexttext',
            'adaptationtext' => 'privacy:metadata:local_oerexchange_reviews:adaptationtext',
            'outcometext' => 'privacy:metadata:local_oerexchange_reviews:outcometext',
            'rating' => 'privacy:metadata:local_oerexchange_reviews:rating',
            'timecreated' => 'privacy:metadata:local_oerexchange_reviews:timecreated',
        ], 'privacy:metadata:local_oerexchange_reviews');

        $collection->add_database_table('local_oerexchange_reports', [
            'userid' => 'privacy:metadata:local_oerexchange_reports:userid',
            'details' => 'privacy:metadata:local_oerexchange_reports:details',
            'timecreated' => 'privacy:metadata:local_oerexchange_reports:timecreated',
        ], 'privacy:metadata:local_oerexchange_reports');

        $collection->add_database_table('local_oerexchange_resources', [
            'creatorid' => 'privacy:metadata:local_oerexchange_resources:creatorid',
            'title' => 'privacy:metadata:local_oerexchange_resources:title',
            'timeshared' => 'privacy:metadata:local_oerexchange_resources:timeshared',
        ], 'privacy:metadata:local_oerexchange_resources');

        $collection->add_database_table('local_oerexchange_imports', [
            'userid' => 'privacy:metadata:local_oerexchange_imports:userid',
            'timecreated' => 'privacy:metadata:local_oerexchange_imports:timecreated',
        ], 'privacy:metadata:local_oerexchange_imports');

        $collection->add_database_table('local_oerexchange_trials', [
            'userid' => 'privacy:metadata:local_oerexchange_trials:userid',
            'timecreated' => 'privacy:metadata:local_oerexchange_trials:timecreated',
        ], 'privacy:metadata:local_oerexchange_trials');

        $collection->add_database_table('local_oerexchange_linkcodes', [
            'userid' => 'privacy:metadata:local_oerexchange_linkcodes:userid',
            'token' => 'privacy:metadata:local_oerexchange_linkcodes:token',
            'timecreated' => 'privacy:metadata:local_oerexchange_linkcodes:timecreated',
        ], 'privacy:metadata:local_oerexchange_linkcodes');

        $collection->add_database_table('local_oerexchange_profiles', [
            'userid' => 'privacy:metadata:local_oerexchange_profiles:userid',
            'slug' => 'privacy:metadata:local_oerexchange_profiles:slug',
            'bio' => 'privacy:metadata:local_oerexchange_profiles:bio',
            'expertise' => 'privacy:metadata:local_oerexchange_profiles:expertise',
            'orcidurl' => 'privacy:metadata:local_oerexchange_profiles:orcidurl',
            'linkedinurl' => 'privacy:metadata:local_oerexchange_profiles:linkedinurl',
            'researchmapurl' => 'privacy:metadata:local_oerexchange_profiles:researchmapurl',
            'visible' => 'privacy:metadata:local_oerexchange_profiles:visible',
            'timecreated' => 'privacy:metadata:local_oerexchange_profiles:timecreated',
            'timemodified' => 'privacy:metadata:local_oerexchange_profiles:timemodified',
        ], 'privacy:metadata:local_oerexchange_profiles');

        $collection->add_database_table('local_oerexchange_badges', [
            'userid' => 'privacy:metadata:local_oerexchange_badges:userid',
            'badgekey' => 'privacy:metadata:local_oerexchange_badges:badgekey',
            'timeawarded' => 'privacy:metadata:local_oerexchange_badges:timeawarded',
        ], 'privacy:metadata:local_oerexchange_badges');

        return $collection;
    }

    #[\Override]
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        global $DB;

        $hasdata = $DB->record_exists('local_oerexchange_reviews', ['userid' => $userid])
            || $DB->record_exists('local_oerexchange_reports', ['userid' => $userid])
            || $DB->record_exists('local_oerexchange_resources', ['creatorid' => $userid])
            || $DB->record_exists('local_oerexchange_imports', ['userid' => $userid])
            || $DB->record_exists('local_oerexchange_trials', ['userid' => $userid])
            || $DB->record_exists('local_oerexchange_linkcodes', ['userid' => $userid])
            || $DB->record_exists('local_oerexchange_profiles', ['userid' => $userid])
            || $DB->record_exists('local_oerexchange_badges', ['userid' => $userid]);

        if ($hasdata) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    #[\Override]
    public static function get_users_in_context(userlist $userlist): void {
        global $DB;

        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }

        $tables = [
            'local_oerexchange_reviews',
            'local_oerexchange_reports',
            'local_oerexchange_imports',
            'local_oerexchange_trials',
            'local_oerexchange_linkcodes',
            'local_oerexchange_profiles',
            'local_oerexchange_badges',
        ];
        foreach ($tables as $table) {
            $userids = $DB->get_fieldset_select($table, 'DISTINCT userid', 'userid IS NOT NULL');
            foreach ($userids as $uid) {
                $userlist->add_user($uid);
            }
        }
        $creators = $DB->get_fieldset_select('local_oerexchange_resources', 'DISTINCT creatorid', '1=1');
        foreach ($creators as $uid) {
            $userlist->add_user($uid);
        }
    }

    #[\Override]
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        if (!in_array(\context_system::instance()->id, array_map(fn($c) => $c->id, $contextlist->get_contexts()), true)) {
            return;
        }

        $reviews = $DB->get_records('local_oerexchange_reviews', ['userid' => $userid]);
        $reports = $DB->get_records('local_oerexchange_reports', ['userid' => $userid]);
        $resources = $DB->get_records('local_oerexchange_resources', ['creatorid' => $userid]);
        $imports = $DB->get_records('local_oerexchange_imports', ['userid' => $userid]);
        $trials = $DB->get_records('local_oerexchange_trials', ['userid' => $userid]);
        $linkcodes = $DB->get_records('local_oerexchange_linkcodes', ['userid' => $userid]);
        $profiles = $DB->get_records('local_oerexchange_profiles', ['userid' => $userid]);
        $badges = $DB->get_records('local_oerexchange_badges', ['userid' => $userid]);

        $data = (object) [
            'reviews' => array_values(array_map(fn($r) => [
                'contexttext' => $r->contexttext, 'adaptationtext' => $r->adaptationtext,
                'outcometext' => $r->outcometext, 'rating' => $r->rating,
                'timecreated' => \core_privacy\local\request\transform::datetime($r->timecreated),
            ], $reviews)),
            'reports' => array_values(array_map(fn($r) => [
                'type' => $r->type, 'details' => $r->details,
                'timecreated' => \core_privacy\local\request\transform::datetime($r->timecreated),
            ], $reports)),
            'sharedresources' => array_values(array_map(fn($r) => [
                'title' => $r->title,
                'timeshared' => \core_privacy\local\request\transform::datetime($r->timeshared),
            ], $resources)),
            'imports' => array_values(array_map(fn($r) => [
                'resourceid' => $r->resourceid,
                'timecreated' => \core_privacy\local\request\transform::datetime($r->timecreated),
            ], $imports)),
            'trials' => array_values(array_map(fn($r) => [
                'resourceid' => $r->resourceid,
                'timecreated' => \core_privacy\local\request\transform::datetime($r->timecreated),
            ], $trials)),
            // Deliberately excludes the raw 'token' field — it is a live WS
            // credential, not something to write into a downloadable export.
            'linkcodes' => array_values(array_map(fn($r) => [
                'status' => $r->status,
                'timecreated' => \core_privacy\local\request\transform::datetime($r->timecreated),
            ], $linkcodes)),
            'profile' => array_values(array_map(fn($r) => [
                'slug' => $r->slug, 'bio' => $r->bio, 'expertise' => $r->expertise,
                'orcidurl' => $r->orcidurl,
                'linkedinurl' => $r->linkedinurl, 'researchmapurl' => $r->researchmapurl,
                'visible' => \core_privacy\local\request\transform::yesno($r->visible),
                'timecreated' => \core_privacy\local\request\transform::datetime($r->timecreated),
                'timemodified' => \core_privacy\local\request\transform::datetime($r->timemodified),
            ], $profiles)),
            'badges' => array_values(array_map(fn($r) => [
                'badgekey' => $r->badgekey,
                'timeawarded' => \core_privacy\local\request\transform::datetime($r->timeawarded),
            ], $badges)),
        ];

        writer::with_context(\context_system::instance())->export_data(
            [get_string('pluginname', 'local_oerexchange')],
            $data
        );
    }

    #[\Override]
    public static function delete_data_for_all_users_in_context(\context $context): void {
        // Never wipe the whole catalogue as a side effect of one user's request —
        // per-user deletion below is the only supported path for this plugin.
        return;
    }

    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        self::delete_for_userid($userid);
    }

    #[\Override]
    public static function delete_data_for_users(approved_userlist $userlist): void {
        foreach ($userlist->get_userids() as $userid) {
            self::delete_for_userid($userid);
        }
    }

    /**
     * Deletes all of this plugin's data for a single user. Resources the user
     * created are tombstoned (files/versions/reviews/reports deleted,
     * descriptive metadata scrubbed, row kept as status = 'deleted' so
     * existing links to it don't break — see profile_manager::delete_creator_resource()).
     *
     * @param int $userid
     */
    protected static function delete_for_userid(int $userid): void {
        global $DB;

        $DB->delete_records('local_oerexchange_profiles', ['userid' => $userid]);
        $DB->delete_records('local_oerexchange_badges', ['userid' => $userid]);

        $ownresources = $DB->get_records('local_oerexchange_resources', ['creatorid' => $userid]);
        foreach ($ownresources as $resource) {
            \local_oerexchange\local\profile_manager::delete_creator_resource($resource);
        }

        // These four are unrelated to resource ownership — a departing user's
        // OWN activity elsewhere (reviews they wrote on other people's still-
        // live resources, reports they filed, imports/trials they made,
        // pending link codes) is deleted regardless of whose resource it
        // touches. This is unchanged from the plugin's original behavior.
        // No double-deletion risk with the loop above: delete_creator_resource()
        // filters by resourceid (the user's own resources), these filter by
        // userid (reviews/reports the user themself authored, possibly on
        // someone else's resource) — non-overlapping row sets.
        $DB->delete_records('local_oerexchange_reviews', ['userid' => $userid]);
        $DB->delete_records('local_oerexchange_reports', ['userid' => $userid]);
        $DB->delete_records('local_oerexchange_imports', ['userid' => $userid]);
        $DB->delete_records('local_oerexchange_trials', ['userid' => $userid]);
        $DB->delete_records('local_oerexchange_linkcodes', ['userid' => $userid]);
    }
}
