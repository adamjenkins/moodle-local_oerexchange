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
 * Named curators of a collection: people the owner invited to help choose and
 * arrange its resources.
 *
 * A curator row is NOT the co-author parity that coauthor_manager grants over
 * a resource. The shapes look alike — the same join table, the same
 * username-or-email lookup — but the rights differ on purpose: a curator may
 * add, remove, reorder and annotate items, and may not touch the collection
 * itself (title, summary, cover, slug, visibility, the curator list, or
 * deletion). The collection stays its owner's, published under their name,
 * and a curator cannot unpublish or rename it.
 *
 * As with co-authors, the owner is never a row here — they are
 * collections.userid, which is what makes them structurally unremovable by a
 * curator. Every rights question is answered by collection_manager; this
 * class only stores who the curators are.
 *
 * Unlike a co-author, being made a curator does NOT create an educator
 * profile. A co-author is an author of the resource and belongs on the public
 * contributors listing; a curator helped assemble somebody else's reading
 * list, which is a different and smaller claim, and creating a public profile
 * page for them off the back of it would be a surprise.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class collection_curator_manager {
    /** @var string The curators table, without braces. */
    const TABLE = 'local_oerexchange_collection_curators';

    /**
     * Add a curator to a collection.
     *
     * Authorises nothing — the caller has already asked
     * collection_manager::user_can_manage() — but it does refuse the three
     * people who cannot sensibly be curators: the guest account (a shared
     * session, so the grant would be to everybody), the owner (already holds
     * strictly more, and a row for them would survive an ownership change as
     * a phantom grant), and someone who already is one.
     *
     * @param int $collectionid
     * @param int $userid the new curator
     * @param int $addedby the owner doing the adding
     * @throws \moodle_exception error_collectionnotfound, error_curatornotfound,
     *                           error_curatorisguest, error_curatorisowner, error_curatorexists
     */
    public static function add(int $collectionid, int $userid, int $addedby): void {
        global $DB;

        $collection = collection_manager::get($collectionid);
        if (!$collection) {
            throw new \moodle_exception('error_collectionnotfound', 'local_oerexchange');
        }

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
        if (!$user) {
            throw new \moodle_exception('error_curatornotfound', 'local_oerexchange');
        }
        if (isguestuser($user)) {
            throw new \moodle_exception('error_curatorisguest', 'local_oerexchange');
        }
        if ((int) $user->id === (int) $collection->userid) {
            throw new \moodle_exception('error_curatorisowner', 'local_oerexchange');
        }
        if (self::is_curator($collectionid, (int) $user->id)) {
            throw new \moodle_exception('error_curatorexists', 'local_oerexchange');
        }

        try {
            $DB->insert_record(self::TABLE, (object) [
                'collectionid' => $collectionid,
                'userid' => (int) $user->id,
                'addedby' => $addedby,
                'timecreated' => time(),
            ]);
        } catch (\dml_write_exception $e) {
            // The is_curator() check above is TOCTOU-prone (a double-submitted
            // form is the everyday way to lose that race); the unique
            // (collectionid, userid) index is what actually prevents the
            // duplicate. Report the documented condition, not a DML error.
            throw new \moodle_exception('error_curatorexists', 'local_oerexchange');
        }
    }

    /**
     * Remove a curator from a collection.
     *
     * Items they added stay. They are the collection's content now, chosen on
     * the owner's invitation, and withdrawing the invitation is not a reason
     * to silently shorten somebody's published reading list.
     *
     * @param int $collectionid
     * @param int $userid
     */
    public static function remove(int $collectionid, int $userid): void {
        global $DB;

        $DB->delete_records(self::TABLE, ['collectionid' => $collectionid, 'userid' => $userid]);
    }

    /**
     * Whether $userid is a named curator of $collectionid.
     *
     * Guards against a falsy userid or collectionid explicitly: a logged-out
     * visitor's $USER->id is 0, both columns are NOT NULL, and a stray 0 row
     * must never read as "yes" for every anonymous visitor. Same guard, same
     * reason, as coauthor_manager::is_coauthor().
     *
     * @param int $collectionid
     * @param int $userid
     * @return bool
     */
    public static function is_curator(int $collectionid, int $userid): bool {
        global $DB;

        if (!$userid || !$collectionid) {
            return false;
        }

        return $DB->record_exists(self::TABLE, [
            'collectionid' => $collectionid,
            'userid' => $userid,
        ]);
    }

    /**
     * The curators of a collection as user records, for display.
     *
     * Deleted users are dropped rather than rendered: core scrambles their
     * name and email on deletion, so the row would credit the collection to a
     * meaningless string. The curator row itself survives core's user
     * deletion (only a privacy erasure request removes it), so this filter is
     * what keeps the page honest in between — exactly as
     * coauthor_manager::get_users() does.
     *
     * @param int $collectionid
     * @return \stdClass[] user records keyed by userid, in the order they were added
     */
    public static function get_curators(int $collectionid): array {
        global $DB;

        $userids = array_map('intval', $DB->get_fieldset_select(
            self::TABLE,
            'userid',
            'collectionid = ?',
            [$collectionid]
        ));
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
     * Every collection id $userid is a named curator of.
     *
     * @param int $userid
     * @return int[]
     */
    public static function curated_collection_ids(int $userid): array {
        global $DB;

        if (!$userid) {
            return [];
        }

        return array_map('intval', $DB->get_fieldset_select(
            self::TABLE,
            'collectionid',
            'userid = ?',
            [$userid]
        ));
    }

    /**
     * Drop every curator row for a collection.
     *
     * Called when a collection is tombstoned: there is nothing left to
     * curate, and the rows are personal data about people who are not its
     * owner.
     *
     * @param int $collectionid
     */
    public static function delete_for_collection(int $collectionid): void {
        global $DB;

        $DB->delete_records(self::TABLE, ['collectionid' => $collectionid]);
    }

    /**
     * Erase a user's curatorship data.
     *
     * Their own curator rows go entirely. Rows they merely ADDED stay — that
     * is somebody else's grant, and revoking it would silently strip a third
     * party's curating rights — but the addedby attribution is scrubbed to 0,
     * the same "no attributable user" sentinel the rest of this plugin uses.
     * Identical treatment, for identical reasons, to
     * coauthor_manager::delete_for_user().
     *
     * @param int $userid
     */
    public static function delete_for_user(int $userid): void {
        global $DB;

        $DB->delete_records(self::TABLE, ['userid' => $userid]);
        $DB->set_field(self::TABLE, 'addedby', 0, ['addedby' => $userid]);
    }

    /**
     * Tell a user they have been made a curator of a collection.
     *
     * Being handed the ability to add to and rearrange somebody else's
     * published reading list without being told would be worse than noisy, so
     * every add should send this. A failed send is not allowed to undo the
     * add: the rights are granted either way, and message_send() fails for
     * reasons that have nothing to do with this plugin (a disabled output, a
     * full queue).
     *
     * Not called from add(): coauthor_manager keeps the same split, so a
     * bulk or administrative grant can choose not to notify.
     *
     * @param \stdClass $collection a collections row
     * @param \stdClass $user the new curator
     * @param \stdClass $addedby the owner who added them
     */
    public static function notify_added(\stdClass $collection, \stdClass $user, \stdClass $addedby): void {
        // Filter the title, then flatten it back to plain text: this is a
        // FORMAT_PLAIN message, so an unfiltered title reaches the recipient
        // as literal `<span lang=... class="multilang">` markup, and a
        // format_string()-escaped one reaches them as `&amp;`.
        // format_string() resolves the multilang spans and content_to_text()
        // undoes the entity escaping it applies. Same ordering, same reason,
        // as coauthor_manager::notify_added().
        $title = content_to_text(
            format_string($collection->title, true, ['context' => \context_system::instance()]),
            FORMAT_HTML
        );

        $url = \moodle_url::routed_path('/local_oerexchange/c/' . $collection->slug)->out(false);

        $message = new \core\message\message();
        $message->component = 'local_oerexchange';
        $message->name = 'collectioncurator';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = get_string('notifycollectioncuratorsubject', 'local_oerexchange', $title);
        $message->fullmessage = get_string('notifycollectioncuratorbody', 'local_oerexchange', (object) [
            'title' => $title,
            'addedby' => fullname($addedby),
            'url' => $url,
        ]);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $message->subject;
        $message->notification = 1;
        $message->contexturl = $url;
        $message->contexturlname = $title;

        message_send($message);
    }
}
