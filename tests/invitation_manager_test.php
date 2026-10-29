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

namespace local_oerexchange;

use PHPUnit\Framework\Attributes\CoversClass;
use local_oerexchange\local\coauthor_manager;
use local_oerexchange\local\collection_curator_manager;
use local_oerexchange\local\invitation_manager;
use local_oerexchange\local\resource_manager;

/**
 * Tests for invitation_manager: co-author and curator adds are invitations
 * (security audit 2026-10-28, F2). Nothing is granted, and no profile is
 * created, until the invitee accepts; the lookup tells the inviter nothing;
 * email matching follows core's identity-field rules; add/remove cannot be
 * cycled to spam the same person; and every inviter is rate-limited.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(invitation_manager::class)]
#[CoversClass(coauthor_manager::class)]
#[CoversClass(collection_curator_manager::class)]
final class invitation_manager_test extends \advanced_testcase {
    /**
     * The plugin's data generator.
     *
     * @return \local_oerexchange_generator
     */
    protected function generator(): \local_oerexchange_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_oerexchange');
    }

    /**
     * Invite through the real entry point and collect what was sent.
     *
     * @param string $kind
     * @param \stdClass $item
     * @param string $identifier
     * @param \stdClass $inviter
     * @return array the messages sent
     */
    protected function invite(string $kind, \stdClass $item, string $identifier, \stdClass $inviter): array {
        $sink = $this->redirectMessages();
        invitation_manager::invite($kind, $item, $identifier, $inviter);
        $messages = $sink->get_messages();
        $sink->close();
        return $messages;
    }

    /**
     * The single pending invitation to $userid, or null.
     *
     * @param int $userid
     * @return \stdClass|null
     */
    protected function pending_for(int $userid): ?\stdClass {
        global $DB;
        return $DB->get_record(invitation_manager::TABLE, [
            'userid' => $userid,
            'status' => invitation_manager::STATUS_PENDING,
        ]) ?: null;
    }

    public function test_a_coauthor_invite_grants_nothing_and_creates_no_profile_until_accepted(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $creator = $this->getDataGenerator()->create_user(['firstname' => 'Tomoko', 'lastname' => 'Teacher']);
        $invitee = $this->getDataGenerator()->create_user(['username' => 'hanako']);
        $resource = $this->generator()->create_resource([
            'creatorid' => (int) $creator->id,
            'title' => 'Coastal Ecology Field Course',
        ]);

        $messages = $this->invite(invitation_manager::KIND_COAUTHOR, $resource, 'hanako', $creator);

        $this->assertFalse(coauthor_manager::is_coauthor((int) $resource->id, (int) $invitee->id));
        $this->assertFalse(resource_manager::user_can_edit_resource($resource, (int) $invitee->id));
        $this->assertFalse(
            $DB->record_exists('local_oerexchange_profiles', ['userid' => $invitee->id]),
            'somebody else\'s add must not publish a profile for this person'
        );

        $invitation = $this->pending_for((int) $invitee->id);
        $this->assertNotNull($invitation);
        $this->assertCount(1, $messages);
        $this->assertSame((int) $invitee->id, (int) $messages[0]->useridto);
        $this->assertStringContainsString('Coastal Ecology Field Course', $messages[0]->subject);
        $this->assertStringContainsString('Tomoko Teacher', $messages[0]->fullmessage);
        $this->assertStringContainsString('/local/oerexchange/invitation.php?id=' . $invitation->id, $messages[0]->fullmessage);
        $this->assertStringContainsString('Show my profile publicly', $messages[0]->fullmessage);
        $this->assertStringNotContainsString('{$a', $messages[0]->fullmessage, 'unsubstituted placeholder');

        $joined = invitation_manager::accept((int) $invitation->id, (int) $invitee->id);

        $this->assertSame((int) $resource->id, (int) $joined->id);
        $this->assertTrue(coauthor_manager::is_coauthor((int) $resource->id, (int) $invitee->id));
        $this->assertTrue(resource_manager::user_can_edit_resource($resource, (int) $invitee->id));
        $this->assertTrue(
            $DB->record_exists('local_oerexchange_profiles', ['userid' => $invitee->id]),
            'accepting makes them a contributor, who needs a profile row to be listed'
        );
        $this->assertSame(
            (int) $creator->id,
            (int) $DB->get_field('local_oerexchange_coauthors', 'addedby', ['userid' => $invitee->id])
        );
        $this->assertSame(
            invitation_manager::STATUS_ACCEPTED,
            $DB->get_field(invitation_manager::TABLE, 'status', ['id' => $invitation->id])
        );
    }

    public function test_declining_grants_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $creator = $this->getDataGenerator()->create_user();
        $invitee = $this->getDataGenerator()->create_user(['username' => 'hanako']);
        $resource = $this->generator()->create_resource(['creatorid' => (int) $creator->id]);

        $this->invite(invitation_manager::KIND_COAUTHOR, $resource, 'hanako', $creator);
        $invitation = $this->pending_for((int) $invitee->id);
        invitation_manager::decline((int) $invitation->id, (int) $invitee->id);

        $this->assertFalse(coauthor_manager::is_coauthor((int) $resource->id, (int) $invitee->id));
        $this->assertFalse($DB->record_exists('local_oerexchange_profiles', ['userid' => $invitee->id]));
        $this->expectException(\moodle_exception::class);
        invitation_manager::accept((int) $invitation->id, (int) $invitee->id);
    }

    public function test_only_the_invitee_can_accept(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $creator = $this->getDataGenerator()->create_user();
        $invitee = $this->getDataGenerator()->create_user(['username' => 'hanako']);
        $intruder = $this->getDataGenerator()->create_user();
        $resource = $this->generator()->create_resource(['creatorid' => (int) $creator->id]);

        $this->invite(invitation_manager::KIND_COAUTHOR, $resource, 'hanako', $creator);
        $invitation = $this->pending_for((int) $invitee->id);

        try {
            invitation_manager::accept((int) $invitation->id, (int) $intruder->id);
            $this->fail('somebody else must not be able to accept');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_invitationnotfound', $e->errorcode);
        }
        $this->assertFalse(coauthor_manager::is_coauthor((int) $resource->id, (int) $intruder->id));
        $this->assertFalse(coauthor_manager::is_coauthor((int) $resource->id, (int) $invitee->id));
    }

    /**
     * The lookup tells the inviter nothing: no match, an ambiguous email and
     * a real match all return normally, and only the real match is sent
     * anything. The inviter's notice is built from the typed string alone
     * (resource.php, collection_edit.php), so there is nothing else to read.
     */
    public function test_an_unknown_or_ambiguous_identifier_is_indistinguishable_from_a_match(): void {
        global $DB, $CFG;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $CFG->allowaccountssameemail = 1;
        set_config('showuseridentity', 'email');
        $creator = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_user(['username' => 'one', 'email' => 'dup@example.com']);
        $this->getDataGenerator()->create_user(['username' => 'two', 'email' => 'dup@example.com']);
        $resource = $this->generator()->create_resource(['creatorid' => (int) $creator->id]);

        $this->assertSame([], $this->invite(invitation_manager::KIND_COAUTHOR, $resource, 'nobody', $creator));
        $this->assertSame([], $this->invite(invitation_manager::KIND_COAUTHOR, $resource, 'dup@example.com', $creator));

        $this->assertSame(2, $DB->count_records(invitation_manager::TABLE, [
            'invitedby' => $creator->id,
            'userid' => 0,
            'status' => invitation_manager::STATUS_VOID,
        ]), 'every attempt is recorded, matched or not, without naming anybody');
        $this->assertSame(0, $DB->count_records('local_oerexchange_coauthors'));
    }

    /**
     * Email is an identity field: matching on it is allowed only where core
     * would show it, i.e. it is listed in showuseridentity and the actor holds
     * moodle/site:viewuseridentity. The list is 'email' by default, so this
     * is the default site.
     */
    public function test_email_matches_only_where_the_actor_may_see_identity_fields(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        set_config('showuseridentity', 'email');
        $creator = $this->getDataGenerator()->create_user();
        $invitee = $this->getDataGenerator()->create_user(['email' => 'second.author@example.com']);
        $resource = $this->generator()->create_resource(['creatorid' => (int) $creator->id]);

        $this->assertFalse(invitation_manager::may_match_by_email((int) $creator->id));
        $messages = $this->invite(invitation_manager::KIND_COAUTHOR, $resource, 'second.author@example.com', $creator);
        $this->assertSame([], $messages, 'an ordinary author cannot name people by email address');
        $this->assertNull($this->pending_for((int) $invitee->id));

        // With the identity capability, the same address matches.
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/site:viewuseridentity', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $creator->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue(invitation_manager::may_match_by_email((int) $creator->id));
        // The first attempt matched nobody, so it imposes no cooldown.
        $messages = $this->invite(invitation_manager::KIND_COAUTHOR, $resource, 'second.author@example.com', $creator);
        $this->assertCount(1, $messages);
        $this->assertNotNull($this->pending_for((int) $invitee->id));
        $this->assertSame(1, $DB->count_records(invitation_manager::TABLE, ['userid' => $invitee->id]));
    }

    /**
     * The capability is not enough either when the site does not list email
     * as an identity field.
     */
    public function test_email_does_not_match_when_the_site_does_not_list_it(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        set_config('showuseridentity', 'idnumber');
        $creator = $this->getDataGenerator()->create_user();
        $invitee = $this->getDataGenerator()->create_user(['email' => 'second.author@example.com']);
        $resource = $this->generator()->create_resource(['creatorid' => (int) $creator->id]);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/site:viewuseridentity', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $creator->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertFalse(invitation_manager::may_match_by_email((int) $creator->id));
        $this->assertSame([], $this->invite(invitation_manager::KIND_COAUTHOR, $resource, 'second.author@example.com', $creator));
        $this->assertNull($this->pending_for((int) $invitee->id));
    }

    /**
     * add/remove cycling: once invited, the same person cannot be invited to
     * the same item again within the cooldown, whether they accepted and
     * were removed, declined, or never answered.
     */
    public function test_reinviting_the_same_person_within_the_cooldown_sends_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $creator = $this->getDataGenerator()->create_user();
        $invitee = $this->getDataGenerator()->create_user(['username' => 'hanako']);
        $resource = $this->generator()->create_resource(['creatorid' => (int) $creator->id]);

        $this->assertCount(1, $this->invite(invitation_manager::KIND_COAUTHOR, $resource, 'hanako', $creator));
        $this->assertSame([], $this->invite(invitation_manager::KIND_COAUTHOR, $resource, 'hanako', $creator), 'still pending');

        invitation_manager::accept((int) $this->pending_for((int) $invitee->id)->id, (int) $invitee->id);
        coauthor_manager::remove((int) $resource->id, (int) $invitee->id);
        $messages = $this->invite(invitation_manager::KIND_COAUTHOR, $resource, 'hanako', $creator);
        $this->assertSame([], $messages, 'removed, then re-added');
        $this->assertNull($this->pending_for((int) $invitee->id));

        // Once the cooldown has passed, they may be invited again.
        $DB->set_field(invitation_manager::TABLE, 'timecreated', time() - invitation_manager::REINVITE_COOLDOWN - 1, []);
        $this->assertCount(1, $this->invite(invitation_manager::KIND_COAUTHOR, $resource, 'hanako', $creator));
    }

    public function test_an_inviter_is_rate_limited_across_items(): void {
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $resource = $this->generator()->create_resource(['creatorid' => (int) $creator->id]);

        for ($i = 0; $i < invitation_manager::MAX_PER_WINDOW; $i++) {
            // Unmatched identifiers count too, so the cap reveals nothing about matches.
            invitation_manager::invite(invitation_manager::KIND_COAUTHOR, $resource, 'nobody' . $i, $creator);
        }

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/too many invitations/i');
        invitation_manager::invite(invitation_manager::KIND_COAUTHOR, $resource, 'anyone', $creator);
    }

    public function test_the_guest_account_and_the_creator_are_never_invited(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $creator = $this->getDataGenerator()->create_user(['username' => 'creator']);
        $resource = $this->generator()->create_resource(['creatorid' => (int) $creator->id]);

        $this->assertSame([], $this->invite(invitation_manager::KIND_COAUTHOR, $resource, 'guest', $creator));
        $this->assertSame([], $this->invite(invitation_manager::KIND_COAUTHOR, $resource, 'creator', $creator));
        $this->assertSame(0, $DB->count_records(invitation_manager::TABLE, ['status' => invitation_manager::STATUS_PENDING]));
    }

    public function test_a_curator_invite_grants_curation_only_on_acceptance(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $owner = $this->getDataGenerator()->create_user();
        $invitee = $this->getDataGenerator()->create_user(['username' => 'jiro']);
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id, 'title' => 'Reading list']);

        $messages = $this->invite(invitation_manager::KIND_CURATOR, $collection, 'jiro', $owner);

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Reading list', $messages[0]->subject);
        $this->assertFalse(collection_curator_manager::is_curator((int) $collection->id, (int) $invitee->id));

        invitation_manager::accept((int) $this->pending_for((int) $invitee->id)->id, (int) $invitee->id);

        $this->assertTrue(collection_curator_manager::is_curator((int) $collection->id, (int) $invitee->id));
    }

    public function test_an_expired_invitation_cannot_be_accepted(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $creator = $this->getDataGenerator()->create_user();
        $invitee = $this->getDataGenerator()->create_user(['username' => 'hanako']);
        $resource = $this->generator()->create_resource(['creatorid' => (int) $creator->id]);
        $this->invite(invitation_manager::KIND_COAUTHOR, $resource, 'hanako', $creator);
        $invitation = $this->pending_for((int) $invitee->id);
        $expired = time() - invitation_manager::EXPIRY - 1;
        $DB->set_field(invitation_manager::TABLE, 'timecreated', $expired, ['id' => $invitation->id]);

        $this->expectException(\moodle_exception::class);
        invitation_manager::accept((int) $invitation->id, (int) $invitee->id);
    }

    /**
     * An invitation is only as good as the inviter's own rights: a co-author
     * removed after sending one must not be able to grant through it.
     */
    public function test_an_invitation_lapses_when_the_inviter_loses_their_rights(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $creator = $this->getDataGenerator()->create_user();
        $coauthor = $this->getDataGenerator()->create_user();
        $invitee = $this->getDataGenerator()->create_user(['username' => 'hanako']);
        $resource = $this->generator()->create_resource(['creatorid' => (int) $creator->id]);
        coauthor_manager::grant($resource, (int) $coauthor->id, (int) $creator->id);

        $this->invite(invitation_manager::KIND_COAUTHOR, $resource, 'hanako', $coauthor);
        $invitation = $this->pending_for((int) $invitee->id);
        coauthor_manager::remove((int) $resource->id, (int) $coauthor->id);

        try {
            invitation_manager::accept((int) $invitation->id, (int) $invitee->id);
            $this->fail('the inviter no longer has the right to add co-authors');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_invitationnotfound', $e->errorcode);
        }
        $this->assertFalse(coauthor_manager::is_coauthor((int) $resource->id, (int) $invitee->id));
        $this->assertSame(0, $DB->count_records('local_oerexchange_coauthors', ['userid' => $invitee->id]));
    }

    public function test_tombstoning_a_resource_drops_its_invitations(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $creator = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_user(['username' => 'hanako']);
        $resource = $this->generator()->create_resource(['creatorid' => (int) $creator->id]);
        $this->invite(invitation_manager::KIND_COAUTHOR, $resource, 'hanako', $creator);

        coauthor_manager::delete_for_resource((int) $resource->id);

        $this->assertSame(0, $DB->count_records(invitation_manager::TABLE, ['itemid' => $resource->id]));
    }

    public function test_cleanup_drops_rows_past_retention_only(): void {
        global $DB;
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $resource = $this->generator()->create_resource(['creatorid' => (int) $creator->id]);
        invitation_manager::invite(invitation_manager::KIND_COAUTHOR, $resource, 'nobody1', $creator);
        invitation_manager::invite(invitation_manager::KIND_COAUTHOR, $resource, 'nobody2', $creator);
        $old = $DB->get_records(invitation_manager::TABLE, null, 'id ASC', 'id', 0, 1);
        $DB->set_field(invitation_manager::TABLE, 'timecreated', time() - invitation_manager::RETENTION - 1, ['id' => key($old)]);

        $this->assertSame(1, invitation_manager::cleanup());
        $this->assertSame(1, $DB->count_records(invitation_manager::TABLE));
    }

    public function test_privacy_erasure_removes_invitations_sent_and_received(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $creator = $this->getDataGenerator()->create_user();
        $invitee = $this->getDataGenerator()->create_user(['username' => 'hanako']);
        $resource = $this->generator()->create_resource(['creatorid' => (int) $creator->id]);
        $this->invite(invitation_manager::KIND_COAUTHOR, $resource, 'hanako', $creator);

        $contexts = \local_oerexchange\privacy\provider::get_contexts_for_userid((int) $invitee->id);
        $this->assertContains(\context_system::instance()->id, array_map('intval', $contexts->get_contextids()));

        \local_oerexchange\privacy\provider::delete_data_for_user(
            new \core_privacy\local\request\approved_contextlist(
                $invitee,
                'local_oerexchange',
                [\context_system::instance()->id]
            )
        );

        $this->assertSame(0, $DB->count_records(invitation_manager::TABLE, ['userid' => $invitee->id]));
    }
}
