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
 * Co-authors: additional users who hold exactly the same rights over a
 * resource as its creator.
 *
 * A co-author row grants full parity — everything the creator can do to the
 * resource (replace the file, edit the thumbnail, hide/unhide, confirm
 * freshness, opt out of Try it, delete it, and manage the co-author list
 * itself). That parity is deliberate rather than a lesser "editor" role: the
 * only gate in the plugin is resource_manager::user_can_edit_resource(), and
 * inventing a second, weaker tier would mean a second gate that the next
 * feature could forget to consult.
 *
 * The creator is never a row here — they are resources.creatorid, which is
 * what makes them structurally unremovable by a co-author.
 *
 * Nobody is made a co-author directly: an add is an invitation
 * (invitation_manager), and the row here is written by grant() only when
 * the invitee accepts.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coauthor_manager {
    /**
     * Whether $userid is a co-author of $resourceid.
     *
     * Guards against userid 0 explicitly: a logged-out visitor's $USER->id is
     * 0, and the column is NOT NULL, so a stray 0 row (impossible today, but
     * one bad insert away) must never read as "yes" for every anonymous
     * visitor. See resource_manager::user_can_edit_resource(), which makes the
     * same guard for creatorid.
     *
     * @param int $resourceid
     * @param int $userid
     * @return bool
     */
    public static function is_coauthor(int $resourceid, int $userid): bool {
        global $DB;

        if (!$userid || !$resourceid) {
            return false;
        }

        return $DB->record_exists('local_oerexchange_coauthors', [
            'resourceid' => $resourceid,
            'userid' => $userid,
        ]);
    }

    /**
     * The co-author user ids of a resource, in the order they were added.
     *
     * @param int $resourceid
     * @return int[]
     */
    public static function get_userids(int $resourceid): array {
        global $DB;

        $ids = $DB->get_fieldset_select(
            'local_oerexchange_coauthors',
            'userid',
            'resourceid = ?',
            [$resourceid]
        );

        return array_map('intval', $ids);
    }

    /**
     * The co-authors of a resource as user records, for display.
     *
     * Deleted users are dropped rather than rendered: core scrambles their
     * name and email on deletion, so the row would attribute the resource to
     * a meaningless string. The co-author row itself survives that (the
     * privacy provider removes it on an erasure request, not core's user
     * deletion), so this filter is what keeps the page honest in between.
     *
     * @param int $resourceid
     * @return \stdClass[] user records keyed by userid, in the order added
     */
    public static function get_users(int $resourceid): array {
        global $DB;

        $userids = self::get_userids($resourceid);
        if (!$userids) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids);
        $users = $DB->get_records_select('user', "id $insql AND deleted = 0", $inparams);

        // Preserve the added order, which get_records_select() does not.
        $ordered = [];
        foreach ($userids as $userid) {
            if (isset($users[$userid])) {
                $ordered[$userid] = $users[$userid];
            }
        }

        return $ordered;
    }

    /**
     * Resolve the username or email address an author typed into a user.
     *
     * Username is tried first and matched case-insensitively: it is unique per
     * MNet host, so it can never be ambiguous, and someone who types a string
     * that is both one person's username and another's email meant the
     * username. Email is only unique when $CFG->allowaccountssameemail is off,
     * so a multi-way email match is refused rather than resolved arbitrarily —
     * silently picking one of two accounts would hand editing rights to a
     * person the author did not name.
     *
     * Email is matched only when $allowemail is true: the caller decides
     * whether the actor may name people by an identity field
     * (invitation_manager::may_match_by_email()). The two exceptions are for
     * the caller's own use; invitation_manager deliberately does not pass
     * the difference on to the person typing.
     *
     * @param string $identifier username or email address, as typed
     * @param bool $allowemail whether an email address may match
     * @return \stdClass the user record
     * @throws \moodle_exception error_coauthornotfound, error_coauthorambiguous
     */
    public static function find_user(string $identifier, bool $allowemail = false): \stdClass {
        global $DB, $CFG;

        $identifier = trim($identifier);
        if ($identifier === '') {
            throw new \moodle_exception('error_coauthornotfound', 'local_oerexchange');
        }

        $byusername = $DB->get_record_select(
            'user',
            $DB->sql_equal('username', ':username', false) . ' AND deleted = 0 AND mnethostid = :mnethostid',
            ['username' => $identifier, 'mnethostid' => $CFG->mnet_localhost_id]
        );
        if ($byusername) {
            return $byusername;
        }
        if (!$allowemail) {
            throw new \moodle_exception('error_coauthornotfound', 'local_oerexchange');
        }

        $byemail = $DB->get_records_select(
            'user',
            $DB->sql_equal('email', ':email', false) . ' AND deleted = 0 AND mnethostid = :mnethostid',
            ['email' => $identifier, 'mnethostid' => $CFG->mnet_localhost_id]
        );
        if (count($byemail) > 1) {
            throw new \moodle_exception('error_coauthorambiguous', 'local_oerexchange');
        }
        if ($byemail) {
            return reset($byemail);
        }

        throw new \moodle_exception('error_coauthornotfound', 'local_oerexchange');
    }

    /**
     * Grant a user co-author rights over a resource.
     *
     * Called by invitation_manager::accept() once the person has accepted
     * an invitation; nobody becomes a co-author without that. This method
     * authorizes nothing, and creates no profile (accept() does that, on the
     * invitee's own action). It only refuses the people who cannot sensibly
     * be co-authors.
     *
     * @param \stdClass $resource a row from local_oerexchange_resources
     * @param int $userid the new co-author
     * @param int $addedby the user who invited them
     * @throws \moodle_exception error_coauthornotfound, error_coauthorisguest,
     *                           error_coauthoriscreator, error_coauthorexists
     */
    public static function grant(\stdClass $resource, int $userid, int $addedby): void {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
        if (!$user) {
            throw new \moodle_exception('error_coauthornotfound', 'local_oerexchange');
        }
        if (isguestuser($user)) {
            throw new \moodle_exception('error_coauthorisguest', 'local_oerexchange');
        }
        if ((int) $user->id === (int) $resource->creatorid) {
            throw new \moodle_exception('error_coauthoriscreator', 'local_oerexchange');
        }
        if (self::is_coauthor((int) $resource->id, (int) $user->id)) {
            throw new \moodle_exception('error_coauthorexists', 'local_oerexchange');
        }

        try {
            $DB->insert_record('local_oerexchange_coauthors', (object) [
                'resourceid' => (int) $resource->id,
                'userid' => (int) $user->id,
                'addedby' => $addedby,
                'timecreated' => time(),
            ]);
        } catch (\dml_write_exception $e) {
            // The unique (resourceid, userid) index settles the race the
            // is_coauthor() check above can lose.
            throw new \moodle_exception('error_coauthorexists', 'local_oerexchange');
        }
    }

    /**
     * Remove a co-author from a resource.
     *
     * @param int $resourceid
     * @param int $userid
     * @return bool whether a row was actually removed
     */
    public static function remove(int $resourceid, int $userid): bool {
        global $DB;

        if (!self::is_coauthor($resourceid, $userid)) {
            return false;
        }

        $DB->delete_records('local_oerexchange_coauthors', [
            'resourceid' => $resourceid,
            'userid' => $userid,
        ]);

        return true;
    }

    /**
     * Drop every co-author row for a resource.
     *
     * Called when a resource is tombstoned: the entry no longer has content to
     * edit, and the rows are personal data about people who are not its
     * creator.
     *
     * @param int $resourceid
     * @return int how many rows were removed
     */
    public static function delete_for_resource(int $resourceid): int {
        global $DB;

        $count = $DB->count_records('local_oerexchange_coauthors', ['resourceid' => $resourceid]);
        $DB->delete_records('local_oerexchange_coauthors', ['resourceid' => $resourceid]);
        // Invitations to it go too: there is nothing left to join.
        invitation_manager::delete_for_item(invitation_manager::KIND_COAUTHOR, $resourceid);

        return $count;
    }

    /**
     * Erase a user's co-authorship data.
     *
     * Their own co-author rows go entirely. Rows they merely *added* stay —
     * that is somebody else's grant, and revoking it would silently strip a
     * third party's editing rights — but the addedby attribution is scrubbed
     * to 0, the same "no attributable user" sentinel resources.creatorid uses.
     *
     * @param int $userid
     */
    public static function delete_for_user(int $userid): void {
        global $DB;

        $DB->delete_records('local_oerexchange_coauthors', ['userid' => $userid]);
        $DB->set_field('local_oerexchange_coauthors', 'addedby', 0, ['addedby' => $userid]);
    }
}
