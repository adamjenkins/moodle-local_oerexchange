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
 * Invitations to co-author a resource or curate a collection.
 *
 * Adding somebody used to grant the rights at once: any author could type
 * any username or email address, read back the account's full name, attach
 * that person to content they had never seen, and (for a co-author) give
 * them a public profile page. Security audit 2026-10-28, finding F2. An add
 * is now an invitation: the person is notified, and the co-author or curator
 * row (and, for a co-author, the profile) is written only when they accept.
 *
 * What the inviter learns is deliberately nothing: whatever the lookup finds
 * (one account, none, several, the guest account, somebody already invited
 * or already holding the rights), invite() behaves the same way and the page
 * shows the same "if an account matches" message with the identifier as
 * typed. Every attempt leaves a row, matched or not, so the per-inviter rate
 * limit counts attempts rather than matches and cannot itself be used to
 * tell which identifiers belong to an account.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class invitation_manager {
    /** @var string The invitations table, without braces. */
    const TABLE = 'local_oerexchange_invitations';

    /** @var string An invitation to co-author a resource; itemid is a resource id. */
    const KIND_COAUTHOR = 'coauthor';

    /** @var string An invitation to curate a collection; itemid is a collection id. */
    const KIND_CURATOR = 'curator';

    /** @var string Waiting for the invitee. */
    const STATUS_PENDING = 'pending';

    /** @var string The invitee accepted; the rights row exists. */
    const STATUS_ACCEPTED = 'accepted';

    /** @var string The invitee declined. */
    const STATUS_DECLINED = 'declined';

    /** @var string An add attempt that invited nobody: no match, or a match that was suppressed. */
    const STATUS_VOID = 'void';

    /** @var int Most add attempts one user may make per RATE_WINDOW, across every resource and collection. */
    const MAX_PER_WINDOW = 10;

    /** @var int The window MAX_PER_WINDOW is counted over, seconds. */
    const RATE_WINDOW = HOURSECS;

    /**
     * @var int How long after inviting somebody to an item they may not be
     * invited to it again, seconds — whether they accepted, declined, were
     * removed or never answered. This is what stops add/remove being cycled
     * to send the same person a stream of notifications.
     */
    const REINVITE_COOLDOWN = DAYSECS;

    /** @var int How long a pending invitation can still be accepted, seconds. */
    const EXPIRY = 14 * DAYSECS;

    /**
     * @var int How long any invitation row is kept, seconds. Longer than
     * both EXPIRY and REINVITE_COOLDOWN, which read these rows.
     */
    const RETENTION = 30 * DAYSECS;

    /**
     * Whether $actorid may name somebody by email address, rather than only
     * by username.
     *
     * An email address is an identity field: core shows it to other users
     * only when it is listed in $CFG->showuseridentity or the viewer holds
     * moodle/site:viewuseridentity. Matching on it for anybody else would
     * let them test which addresses have accounts here.
     *
     * @param int $actorid
     * @return bool
     */
    public static function may_match_by_email(int $actorid): bool {
        global $CFG;

        $fields = array_map('trim', explode(',', (string) ($CFG->showuseridentity ?? '')));
        if (in_array('email', $fields, true)) {
            return true;
        }

        return has_capability('moodle/site:viewuseridentity', \context_system::instance(), $actorid);
    }

    /**
     * Invite somebody, named by username (or email, where the actor may
     * match by email), to co-author a resource or curate a collection.
     *
     * Callers must already have checked that $inviter may manage the item
     * (resource_manager::user_can_edit_resource() for a resource,
     * collection_manager::user_can_manage() for a collection). This method
     * authorises nothing.
     *
     * Returns normally whatever the lookup finds, and sends a notification
     * only when it found exactly one account that may be invited. The only
     * exception it throws is error_invitationratelimit, which depends on the
     * inviter's own attempts and says nothing about the identifier.
     *
     * @param string $kind self::KIND_COAUTHOR or self::KIND_CURATOR
     * @param \stdClass $item the resource or collection row
     * @param string $identifier the username or email address, as typed
     * @param \stdClass $inviter the user doing the inviting
     * @throws \moodle_exception error_invitationratelimit
     */
    public static function invite(string $kind, \stdClass $item, string $identifier, \stdClass $inviter): void {
        global $DB;

        self::check_kind($kind);
        $now = time();

        if (self::rate_exceeded((int) $inviter->id, $now)) {
            throw new \moodle_exception('error_invitationratelimit', 'local_oerexchange');
        }

        $user = null;
        try {
            $user = coauthor_manager::find_user($identifier, self::may_match_by_email((int) $inviter->id));
        } catch (\moodle_exception $e) {
            // Not found and ambiguous are the same outcome to the inviter.
            $user = null;
        }

        $invitable = $user && self::may_invite($kind, $item, $user, $now);

        $invitationid = (int) $DB->insert_record(self::TABLE, (object) [
            'kind' => $kind,
            'itemid' => (int) $item->id,
            'userid' => $invitable ? (int) $user->id : 0,
            'invitedby' => (int) $inviter->id,
            'status' => $invitable ? self::STATUS_PENDING : self::STATUS_VOID,
            'timecreated' => $now,
            'timeresponded' => 0,
        ]);

        if ($invitable) {
            self::notify($kind, $item, $user, $inviter, $invitationid);
        }
    }

    /**
     * Whether $inviterid has used up this window's add attempts.
     *
     * @param int $inviterid
     * @param int|null $now
     * @return bool
     */
    public static function rate_exceeded(int $inviterid, ?int $now = null): bool {
        global $DB;

        $now = $now ?? time();
        $recent = $DB->count_records_select(
            self::TABLE,
            'invitedby = ? AND timecreated > ?',
            [$inviterid, $now - self::RATE_WINDOW]
        );

        return $recent >= self::MAX_PER_WINDOW;
    }

    /**
     * Whether the resolved $user may be sent an invitation to $item now.
     *
     * Refuses the guest account, the item's own creator or owner, somebody
     * who already holds the rights, and somebody who was invited to this
     * item within REINVITE_COOLDOWN (which also covers a pending invitation).
     *
     * @param string $kind
     * @param \stdClass $item
     * @param \stdClass $user
     * @param int $now
     * @return bool
     */
    protected static function may_invite(string $kind, \stdClass $item, \stdClass $user, int $now): bool {
        global $DB;

        $userid = (int) $user->id;
        if (isguestuser($user) || !empty($user->suspended)) {
            return false;
        }
        if ($kind === self::KIND_COAUTHOR) {
            if ($userid === (int) $item->creatorid || coauthor_manager::is_coauthor((int) $item->id, $userid)) {
                return false;
            }
        } else {
            if ($userid === (int) $item->userid || collection_curator_manager::is_curator((int) $item->id, $userid)) {
                return false;
            }
        }

        return !$DB->record_exists_select(
            self::TABLE,
            'kind = ? AND itemid = ? AND userid = ? AND timecreated > ?',
            [$kind, (int) $item->id, $userid, $now - self::REINVITE_COOLDOWN]
        );
    }

    /**
     * An invitation addressed to $userid that can still be answered, or null.
     *
     * @param int $invitationid
     * @param int $userid
     * @return \stdClass|null
     */
    public static function get_open_for_user(int $invitationid, int $userid): ?\stdClass {
        global $DB;

        if (!$invitationid || !$userid) {
            return null;
        }
        $invitation = $DB->get_record(self::TABLE, [
            'id' => $invitationid,
            'userid' => $userid,
            'status' => self::STATUS_PENDING,
        ]);
        if (!$invitation || (int) $invitation->timecreated <= time() - self::EXPIRY) {
            return null;
        }

        return $invitation;
    }

    /**
     * The resource or collection an invitation is about, if it can still be
     * joined: the item exists, is not a tombstone, and the inviter still has
     * the right to add people to it. An inviter who has since been removed
     * as a co-author must not be able to grant rights through an invitation
     * sent before that.
     *
     * @param \stdClass $invitation
     * @return \stdClass|null
     */
    public static function get_item(\stdClass $invitation): ?\stdClass {
        global $DB;

        if ($invitation->kind === self::KIND_COAUTHOR) {
            $resource = $DB->get_record('local_oerexchange_resources', ['id' => $invitation->itemid]);
            if (
                !$resource || $resource->status === 'deleted'
                || !resource_manager::user_can_edit_resource($resource, (int) $invitation->invitedby)
            ) {
                return null;
            }
            return $resource;
        }

        $collection = collection_manager::get((int) $invitation->itemid);
        if (!$collection || !collection_manager::user_can_manage($collection, (int) $invitation->invitedby)) {
            return null;
        }
        return $collection;
    }

    /**
     * Accept an invitation: grant the rights it offers.
     *
     * For a co-author this is also where their educator profile is created
     * (profile_manager::get_or_create_for_user()), so nobody gets a public
     * profile page from somebody else's action alone.
     *
     * @param int $invitationid
     * @param int $userid the invitee, who must be the one answering
     * @return \stdClass the resource or collection joined
     * @throws \moodle_exception error_invitationnotfound when the invitation is
     *                           not this user's, not pending, expired, or its item can no longer be joined
     */
    public static function accept(int $invitationid, int $userid): \stdClass {
        global $DB;

        $invitation = self::get_open_for_user($invitationid, $userid);
        $item = $invitation ? self::get_item($invitation) : null;
        if (!$invitation || !$item) {
            throw new \moodle_exception('error_invitationnotfound', 'local_oerexchange');
        }

        $transaction = $DB->start_delegated_transaction();
        // Claim the invitation first: of two concurrent accepts, only the
        // one that moves it out of 'pending' goes on to grant.
        $DB->execute(
            'UPDATE {' . self::TABLE . '} SET status = ?, timeresponded = ? WHERE id = ? AND status = ?',
            [self::STATUS_ACCEPTED, time(), $invitation->id, self::STATUS_PENDING]
        );
        $claimed = $DB->get_record(self::TABLE, ['id' => $invitation->id], 'id, status', MUST_EXIST);
        if ($claimed->status !== self::STATUS_ACCEPTED) {
            $transaction->allow_commit();
            throw new \moodle_exception('error_invitationnotfound', 'local_oerexchange');
        }

        try {
            if ($invitation->kind === self::KIND_COAUTHOR) {
                coauthor_manager::grant($item, $userid, (int) $invitation->invitedby);
            } else {
                collection_curator_manager::add((int) $item->id, $userid, (int) $invitation->invitedby);
            }
        } catch (\moodle_exception $e) {
            // Already holding the rights (granted by another invitation) is
            // the outcome the invitee asked for, not a failure.
            if (!in_array($e->errorcode, ['error_coauthorexists', 'error_curatorexists'], true)) {
                $transaction->rollback($e);
            }
        }

        if ($invitation->kind === self::KIND_COAUTHOR) {
            // Being a co-author makes this person a contributor: contributor_list
            // joins on local_oerexchange_profiles, so a co-author who has
            // published nothing of their own needs the row to be listed. It
            // is created now, on their own acceptance, never by the add.
            profile_manager::get_or_create_for_user($userid);
        }

        $transaction->allow_commit();

        return $item;
    }

    /**
     * Decline an invitation. Nothing is granted, and the inviter cannot send
     * another to this person for this item until REINVITE_COOLDOWN has passed.
     *
     * @param int $invitationid
     * @param int $userid the invitee, who must be the one answering
     * @throws \moodle_exception error_invitationnotfound
     */
    public static function decline(int $invitationid, int $userid): void {
        global $DB;

        $invitation = self::get_open_for_user($invitationid, $userid);
        if (!$invitation) {
            throw new \moodle_exception('error_invitationnotfound', 'local_oerexchange');
        }

        $DB->execute(
            'UPDATE {' . self::TABLE . '} SET status = ?, timeresponded = ? WHERE id = ? AND status = ?',
            [self::STATUS_DECLINED, time(), $invitation->id, self::STATUS_PENDING]
        );
    }

    /**
     * Send the invitee the notification with the accept/decline link.
     *
     * Uses the existing coauthor and collectioncurator message providers,
     * which recipients can turn off in their notification preferences.
     *
     * @param string $kind
     * @param \stdClass $item the resource or collection
     * @param \stdClass $user the invitee
     * @param \stdClass $inviter
     * @param int $invitationid
     */
    protected static function notify(
        string $kind,
        \stdClass $item,
        \stdClass $user,
        \stdClass $inviter,
        int $invitationid
    ): void {
        // Filter the title, then flatten it back to plain text: this is a
        // FORMAT_PLAIN message, so an unfiltered title reaches the recipient
        // as literal multilang markup and a format_string()-escaped one as
        // `&amp;`. Same treatment as every other notification here.
        $title = content_to_text(
            format_string($item->title, true, ['context' => \context_system::instance()]),
            FORMAT_HTML
        );
        $url = (new \moodle_url('/local/oerexchange/invitation.php', ['id' => $invitationid]))->out(false);
        $a = (object) [
            'title' => $title,
            'invitedby' => fullname($inviter),
            'url' => $url,
            'days' => (int) (self::EXPIRY / DAYSECS),
        ];
        $prefix = $kind === self::KIND_COAUTHOR ? 'notifycoauthorinvite' : 'notifycuratorinvite';

        $message = new \core\message\message();
        $message->component = 'local_oerexchange';
        $message->name = $kind === self::KIND_COAUTHOR ? 'coauthor' : 'collectioncurator';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = get_string($prefix . 'subject', 'local_oerexchange', $title);
        $message->fullmessage = get_string($prefix . 'body', 'local_oerexchange', $a);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $message->subject;
        $message->notification = 1;
        $message->contexturl = $url;
        $message->contexturlname = get_string('invitationheading', 'local_oerexchange');

        message_send($message);
    }

    /**
     * Drop every invitation to one resource or collection, when it is
     * tombstoned: there is nothing left to join, and the rows name people.
     *
     * @param string $kind
     * @param int $itemid
     */
    public static function delete_for_item(string $kind, int $itemid): void {
        global $DB;

        $DB->delete_records(self::TABLE, ['kind' => $kind, 'itemid' => $itemid]);
    }

    /**
     * Erase a user's invitation data: the invitations they received and the
     * ones they sent. A pending invitation from an erased user could no
     * longer be accepted anyway (get_item() checks the inviter's rights).
     *
     * @param int $userid
     */
    public static function delete_for_user(int $userid): void {
        global $DB;

        if (!$userid) {
            return;
        }
        $DB->delete_records(self::TABLE, ['userid' => $userid]);
        $DB->delete_records(self::TABLE, ['invitedby' => $userid]);
    }

    /**
     * Delete invitation rows older than RETENTION. By then a pending one has
     * long expired and the cooldown it imposed has long passed.
     *
     * @param int|null $now
     * @return int how many rows were deleted
     */
    public static function cleanup(?int $now = null): int {
        global $DB;

        $cutoff = ($now ?? time()) - self::RETENTION;
        $count = $DB->count_records_select(self::TABLE, 'timecreated < ?', [$cutoff]);
        if ($count) {
            $DB->delete_records_select(self::TABLE, 'timecreated < ?', [$cutoff]);
        }

        return $count;
    }

    /**
     * Refuse an unknown kind, which would otherwise be stored and never answerable.
     *
     * @param string $kind
     */
    protected static function check_kind(string $kind): void {
        if ($kind !== self::KIND_COAUTHOR && $kind !== self::KIND_CURATOR) {
            throw new \coding_exception('Unknown invitation kind: ' . $kind);
        }
    }
}
