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

        // Site-registration rows carry a contact person's email (plus the
        // site name/url an admin typed), and the id of the dedicated service
        // account minted for the site at approval time. The contact often
        // maps to no Moodle account on this site, but personal data must be
        // declared regardless of whether it is user-linked — and both of the
        // links that DO exist (contact = a local user's email, serviceuserid
        // = a local user id) are serviced by the discovery, export and
        // deletion paths below. See site_registration_ids().
        $collection->add_database_table('local_oerexchange_sites', [
            'name' => 'privacy:metadata:local_oerexchange_sites:name',
            'url' => 'privacy:metadata:local_oerexchange_sites:url',
            'contact' => 'privacy:metadata:local_oerexchange_sites:contact',
            'serviceuserid' => 'privacy:metadata:local_oerexchange_sites:serviceuserid',
        ], 'privacy:metadata:local_oerexchange_sites');

        $collection->add_database_table('local_oerexchange_resources', [
            'creatorid' => 'privacy:metadata:local_oerexchange_resources:creatorid',
            'title' => 'privacy:metadata:local_oerexchange_resources:title',
            'timeshared' => 'privacy:metadata:local_oerexchange_resources:timeshared',
            // Author-written free text shown publicly in place of the Try it
            // button, so it is the author's own words and belongs here.
            'trydisabledreason' => 'privacy:metadata:local_oerexchange_resources:trydisabledreason',
        ], 'privacy:metadata:local_oerexchange_resources');

        // Who else holds editing rights over a resource, and who granted them.
        // Both columns are local user ids, so both are serviced below —
        // 'userid' as the co-author's own data, 'addedby' as an attribution
        // that is scrubbed rather than removed (see delete_for_userid()).
        $collection->add_database_table('local_oerexchange_coauthors', [
            'userid' => 'privacy:metadata:local_oerexchange_coauthors:userid',
            'addedby' => 'privacy:metadata:local_oerexchange_coauthors:addedby',
            'timecreated' => 'privacy:metadata:local_oerexchange_coauthors:timecreated',
        ], 'privacy:metadata:local_oerexchange_coauthors');

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
            || $DB->record_exists('local_oerexchange_badges', ['userid' => $userid])
            || $DB->record_exists('local_oerexchange_coauthors', ['userid' => $userid])
            || $DB->record_exists('local_oerexchange_coauthors', ['addedby' => $userid])
            || self::site_registration_ids($userid) !== [];

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
        // Creatorid = 0 marks a tombstoned/anonymized resource
        // (profile_manager::delete_creator_resource()) — it is not a real
        // user id and must not appear in the userlist (final whole-branch
        // review finding 6).
        $creators = $DB->get_fieldset_select('local_oerexchange_resources', 'DISTINCT creatorid', 'creatorid <> 0');
        foreach ($creators as $uid) {
            $userlist->add_user($uid);
        }

        // Co-authorship names two people per row. addedby is filtered the same
        // way creatorid is above: it carries the same 0 = "no attributable
        // user" sentinel once that user has been erased.
        $coauthors = $DB->get_fieldset_select('local_oerexchange_coauthors', 'DISTINCT userid', 'userid <> 0');
        foreach ($coauthors as $uid) {
            $userlist->add_user($uid);
        }
        $adders = $DB->get_fieldset_select('local_oerexchange_coauthors', 'DISTINCT addedby', 'addedby <> 0');
        foreach ($adders as $uid) {
            $userlist->add_user($uid);
        }

        // Site registrations link to a local account two ways: the dedicated
        // service account minted at approval, and a contact email that
        // happens to belong to a user on this site. Both were declared in
        // get_metadata() but neither was discoverable here until 0.1.6.
        $serviceuserids = $DB->get_fieldset_select(
            'local_oerexchange_sites',
            'DISTINCT serviceuserid',
            'serviceuserid IS NOT NULL'
        );
        foreach ($serviceuserids as $uid) {
            $userlist->add_user($uid);
        }

        $contacts = $DB->get_fieldset_select('local_oerexchange_sites', 'DISTINCT contact', "contact <> ''");
        if ($contacts) {
            [$insql, $inparams] = $DB->get_in_or_equal($contacts, SQL_PARAMS_NAMED, 'contact');
            $contactusers = $DB->get_fieldset_select(
                'user',
                'id',
                "deleted = 0 AND LOWER(email) $insql",
                array_map('core_text::strtolower', $inparams)
            );
            foreach ($contactusers as $uid) {
                $userlist->add_user($uid);
            }
        }
    }

    /**
     * The site-registration rows that count as $userid's personal data: those
     * naming them as the registration contact (by email, case-insensitively),
     * and those whose dedicated service account IS this user.
     *
     * A contact email that belongs to nobody on this site is real personal
     * data with no local subject — it is declared in get_metadata() and is
     * reachable only through the delete-all-users path, which scrubs every
     * contact regardless of whether it maps to an account here.
     *
     * @param int $userid
     * @return int[] site ids, empty when the user has no registration link
     */
    protected static function site_registration_ids(int $userid): array {
        global $DB;

        $ids = $DB->get_fieldset_select('local_oerexchange_sites', 'id', 'serviceuserid = ?', [$userid]);

        $user = \core_user::get_user($userid, 'id, email, deleted');
        // A user core has already deleted has had their email scrambled, so
        // the contact match can no longer be made — the service-account link
        // above still works, and the delete-all path still reaches the row.
        if ($user && empty($user->deleted) && !empty($user->email)) {
            $ids = array_merge($ids, $DB->get_fieldset_select(
                'local_oerexchange_sites',
                'id',
                $DB->sql_equal('contact', ':contact', false),
                ['contact' => $user->email]
            ));
        }

        return array_values(array_unique(array_map('intval', $ids)));
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
        $coauthored = $DB->get_records('local_oerexchange_coauthors', ['userid' => $userid]);
        $coauthorsadded = $DB->get_records('local_oerexchange_coauthors', ['addedby' => $userid]);
        $siteids = self::site_registration_ids($userid);
        $sites = $siteids ? $DB->get_records_list('local_oerexchange_sites', 'id', $siteids) : [];

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
            // Resources somebody else shared that this user was given equal
            // editing rights over...
            'coauthoredresources' => array_values(array_map(fn($r) => [
                'resourceid' => $r->resourceid,
                'timecreated' => \core_privacy\local\request\transform::datetime($r->timecreated),
            ], $coauthored)),
            // ...and the grants this user made to other people. Only the
            // resource is named, not who was added: that person's identity is
            // their own personal data, not this user's, and the requester can
            // read the current list on the resource page anyway.
            'coauthorsadded' => array_values(array_map(fn($r) => [
                'resourceid' => $r->resourceid,
                'timecreated' => \core_privacy\local\request\transform::datetime($r->timecreated),
            ], $coauthorsadded)),
            // Registrations this user is the named contact for, or whose
            // service account they are. Declared since 0.1.5 but exported
            // only from 0.1.6 — an unserviced declaration is the defect this
            // closes, not a new data category.
            'siteregistrations' => array_values(array_map(fn($r) => [
                'name' => $r->name,
                'url' => $r->url,
                'contact' => $r->contact,
                'status' => $r->status,
                'timecreated' => \core_privacy\local\request\transform::datetime($r->timecreated),
            ], $sites)),
        ];

        writer::with_context(\context_system::instance())->export_data(
            [get_string('pluginname', 'local_oerexchange')],
            $data
        );
    }

    #[\Override]
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof \context_system) {
            return;
        }

        // Note "all users in this context" is every user on the site here — so
        // this deletes every user-keyed row and tombstones every attributed
        // resource, exactly as the per-user path does, just for everyone at
        // once. What deliberately SURVIVES is the tombstoned catalogue
        // skeleton itself (status='deleted' rows with scrubbed metadata and
        // no files), so existing inbound links degrade gracefully instead of
        // 404ing — the same stance delete_for_userid() takes. Until 0.1.5
        // this method was a total no-op, which quietly retained every
        // profile, badge, review and report through an approved
        // delete-all-users request.
        $ownresources = $DB->get_records_select('local_oerexchange_resources', 'creatorid <> 0');
        foreach ($ownresources as $resource) {
            \local_oerexchange\local\profile_manager::delete_creator_resource($resource);
        }

        $DB->delete_records('local_oerexchange_profiles');
        $DB->delete_records('local_oerexchange_badges');
        // Nothing survives here the way a site registration's row does: a
        // co-author grant is purely internal to this site, so there is no
        // third party whose integration breaks by removing it. The loop above
        // has already dropped most of these via delete_creator_resource();
        // this catches rows on resources that were already tombstoned.
        $DB->delete_records('local_oerexchange_coauthors');
        $DB->delete_records('local_oerexchange_reviews');
        $DB->delete_records('local_oerexchange_reports');
        $DB->delete_records('local_oerexchange_imports');
        $DB->delete_records('local_oerexchange_trials');
        $DB->delete_records('local_oerexchange_linkcodes');

        // Every registration contact, including the ones belonging to people
        // with no account here — this path is "erase everyone", and a contact
        // email is personal data whether or not its subject is local. The
        // registration row itself survives with an empty contact: deleting it
        // would silently sever a live integration (its service account and
        // minted token stay valid), which is a third party's operational
        // state, not this site's personal data. An admin re-collects the
        // contact through manage_sites.php if they still need it.
        $DB->set_field_select('local_oerexchange_sites', 'contact', '', "contact <> ''");
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

        // Their own co-authorships go; grants they made to other people keep
        // the row (revoking a third party's editing rights is not part of this
        // user's erasure) but lose the attribution. See
        // coauthor_manager::delete_for_user().
        \local_oerexchange\local\coauthor_manager::delete_for_user($userid);

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

        // Registrations naming this user as the contact keep their row (see
        // delete_data_for_all_users_in_context() for why severing a live
        // integration is not ours to do) but lose the email. Rows where the
        // user IS the service account are deliberately untouched: that link
        // is to a machine account this plugin created, and nulling it would
        // orphan the site's still-valid token with no way to revoke it.
        $user = \core_user::get_user($userid, 'id, email, deleted');
        if ($user && empty($user->deleted) && !empty($user->email)) {
            $DB->set_field_select(
                'local_oerexchange_sites',
                'contact',
                '',
                $DB->sql_equal('contact', ':contact', false),
                ['contact' => $user->email]
            );
        }
    }
}
