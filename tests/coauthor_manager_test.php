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
use local_oerexchange\local\resource_manager;

/**
 * Tests for coauthor_manager: resolving a username/email to the right
 * account, the ways an add is refused, and the parity a co-author row grants
 * through resource_manager::user_can_edit_resource() — including the one
 * action that parity deliberately does NOT extend to,
 * resource_manager::user_can_delete_resource() on a moderated resource.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(coauthor_manager::class)]
#[CoversClass(resource_manager::class)]
final class coauthor_manager_test extends \advanced_testcase {
    /**
     * Insert a catalogue row directly — publish() needs a real .mbz in a
     * draft area, which none of these tests are about.
     *
     * @param int $creatorid
     * @return \stdClass the resource row
     */
    protected function make_resource(int $creatorid): \stdClass {
        global $DB;

        $now = time();
        $id = $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course',
            'title' => 'Shared course',
            'summary' => '',
            'language' => 'en',
            'tags' => '',
            'licenseshortname' => 'cc-4.0',
            'activitytype' => null,
            'dataresourcetype' => null,
            'courseformat' => null,
            'creatorid' => $creatorid,
            'siteid' => null,
            'status' => 'published',
            'downloadcount' => 0,
            'importcount' => 0,
            'forkedfromid' => null,
            'trydisabled' => 0,
            'trydisabledreason' => null,
            'timeshared' => $now,
            'timemodified' => $now,
            'timefresh' => $now,
            'stalenotifiedtime' => 0,
        ]);

        return $DB->get_record('local_oerexchange_resources', ['id' => $id], '*', MUST_EXIST);
    }

    public function test_add_by_username_seats_the_named_user(): void {
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user(['username' => 'hanako']);
        $resource = $this->make_resource((int) $creator->id);

        $added = coauthor_manager::add($resource, 'hanako', (int) $creator->id);

        $this->assertSame((int) $second->id, (int) $added->id);
        $this->assertTrue(coauthor_manager::is_coauthor((int) $resource->id, (int) $second->id));
    }

    public function test_adding_a_coauthor_creates_their_profile(): void {
        global $DB;
        $this->resetAfterTest();

        $creator = $this->getDataGenerator()->create_user();
        $coauthor = $this->getDataGenerator()->create_user(['username' => 'newcoauthor']);
        $resource = $this->make_resource((int) $creator->id);

        $this->assertFalse(
            $DB->record_exists('local_oerexchange_profiles', ['userid' => $coauthor->id]),
            'precondition: a user who has published nothing has no profile row yet'
        );

        coauthor_manager::add($resource, 'newcoauthor', (int) $creator->id);

        $this->assertTrue(
            $DB->record_exists('local_oerexchange_profiles', ['userid' => $coauthor->id]),
            'a co-author who has published nothing of their own still needs a profile row, '
                . 'or contributor_list silently drops them from a listing they belong in'
        );
    }

    public function test_add_by_email_seats_the_named_user(): void {
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user(['email' => 'second.author@example.com']);
        $resource = $this->make_resource((int) $creator->id);

        $added = coauthor_manager::add($resource, 'second.author@example.com', (int) $creator->id);

        $this->assertSame((int) $second->id, (int) $added->id);
    }

    /**
     * Authors type what they remember, not what the database stores. Both
     * lookups use $DB->sql_equal(..., false), and a case-sensitive match
     * would reject a perfectly correct address on MySQL/MariaDB's default
     * collation only by accident of which column it hit.
     */
    public function test_lookup_is_case_insensitive_for_both_identifiers(): void {
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $byname = $this->getDataGenerator()->create_user(['username' => 'taro']);
        $bymail = $this->getDataGenerator()->create_user(['email' => 'mixed.case@example.com']);

        $this->assertSame((int) $byname->id, (int) coauthor_manager::find_user('TARO')->id);
        $this->assertSame((int) $bymail->id, (int) coauthor_manager::find_user('Mixed.Case@Example.COM')->id);
    }

    /**
     * A string that is one person's username and another's email means the
     * username — usernames are unique per MNet host, emails are not.
     */
    public function test_username_wins_over_a_different_users_email(): void {
        $this->resetAfterTest();
        $byname = $this->getDataGenerator()->create_user(['username' => 'shared@example.com']);
        $this->getDataGenerator()->create_user(['email' => 'shared@example.com']);

        $this->assertSame((int) $byname->id, (int) coauthor_manager::find_user('shared@example.com')->id);
    }

    /**
     * With $CFG->allowaccountssameemail on, an email can name two people.
     * Picking one of them would hand editing rights to somebody the author
     * did not name, so the add is refused and they are told to use a
     * username.
     */
    public function test_ambiguous_email_is_refused_rather_than_guessed(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->allowaccountssameemail = 1;
        $this->getDataGenerator()->create_user(['username' => 'one', 'email' => 'dup@example.com']);
        $this->getDataGenerator()->create_user(['username' => 'two', 'email' => 'dup@example.com']);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/more than one account/i');
        coauthor_manager::find_user('dup@example.com');
    }

    public function test_unknown_identifier_is_refused(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        coauthor_manager::find_user('nobody@example.com');
    }

    /**
     * A deleted user's username/email are scrambled by core, but the row is
     * still in {user} — the lookup must not resurrect them as a co-author.
     */
    public function test_deleted_users_are_not_findable(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['username' => 'departed', 'email' => 'departed@example.com']);
        delete_user($user);

        $this->expectException(\moodle_exception::class);
        coauthor_manager::find_user('departed');
    }

    public function test_the_creator_cannot_be_added_as_their_own_coauthor(): void {
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user(['username' => 'creator']);
        $resource = $this->make_resource((int) $creator->id);

        $this->expectException(\moodle_exception::class);
        coauthor_manager::add($resource, 'creator', (int) $creator->id);
    }

    public function test_adding_the_same_person_twice_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_user(['username' => 'hanako']);
        $resource = $this->make_resource((int) $creator->id);

        coauthor_manager::add($resource, 'hanako', (int) $creator->id);
        try {
            coauthor_manager::add($resource, 'hanako', (int) $creator->id);
            $this->fail('a duplicate add must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame(1, $DB->count_records('local_oerexchange_coauthors', ['resourceid' => $resource->id]));
        }
    }

    public function test_the_guest_account_cannot_be_a_coauthor(): void {
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource((int) $creator->id);

        $this->expectException(\moodle_exception::class);
        coauthor_manager::add($resource, 'guest', (int) $creator->id);
    }

    /**
     * The point of the whole feature: a co-author row is the only thing that
     * needs to change for every author-side action to admit them, because
     * they all consult this one gate.
     */
    public function test_a_coauthor_can_edit_the_resource(): void {
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user(['username' => 'hanako']);
        $resource = $this->make_resource((int) $creator->id);

        $this->assertFalse(
            resource_manager::user_can_edit_resource($resource, (int) $second->id),
            'not a co-author yet'
        );

        coauthor_manager::add($resource, 'hanako', (int) $creator->id);

        $this->assertTrue(resource_manager::user_can_edit_resource($resource, (int) $second->id));
    }

    /**
     * Co-authorship is per resource, not per creator: being added to one of
     * somebody's resources grants nothing over the rest of them.
     */
    public function test_coauthorship_does_not_leak_to_another_resource(): void {
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user(['username' => 'hanako']);
        $shared = $this->make_resource((int) $creator->id);
        $other = $this->make_resource((int) $creator->id);

        coauthor_manager::add($shared, 'hanako', (int) $creator->id);

        $this->assertTrue(resource_manager::user_can_edit_resource($shared, (int) $second->id));
        $this->assertFalse(resource_manager::user_can_edit_resource($other, (int) $second->id));
    }

    public function test_removing_a_coauthor_revokes_their_rights(): void {
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user(['username' => 'hanako']);
        $resource = $this->make_resource((int) $creator->id);
        coauthor_manager::add($resource, 'hanako', (int) $creator->id);

        $this->assertTrue(coauthor_manager::remove((int) $resource->id, (int) $second->id));
        $this->assertFalse(resource_manager::user_can_edit_resource($resource, (int) $second->id));
        $this->assertFalse(
            coauthor_manager::remove((int) $resource->id, (int) $second->id),
            'removing again reports that nothing was removed'
        );
    }

    /**
     * A co-author holds full parity, so they may seat another co-author. That
     * grants nothing they do not already have (they can replace or delete the
     * entry outright), and the creator is not a row here, so nobody they add
     * can ever remove them.
     */
    public function test_a_coauthor_may_add_another_coauthor(): void {
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user(['username' => 'hanako']);
        $third = $this->getDataGenerator()->create_user(['username' => 'jiro']);
        $resource = $this->make_resource((int) $creator->id);

        coauthor_manager::add($resource, 'hanako', (int) $creator->id);
        coauthor_manager::add($resource, 'jiro', (int) $second->id);

        $this->assertTrue(resource_manager::user_can_edit_resource($resource, (int) $third->id));
        $this->assertTrue(
            resource_manager::user_can_edit_resource($resource, (int) $creator->id),
            'the creator keeps their rights whatever the co-authors do'
        );
    }

    /**
     * userid 0 is a logged-out visitor. The column is NOT NULL, so a stray 0
     * row would otherwise read as "yes" for everybody who is not logged in.
     */
    public function test_anonymous_is_never_a_coauthor(): void {
        global $DB;
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource((int) $creator->id);
        $DB->insert_record('local_oerexchange_coauthors', (object) [
            'resourceid' => $resource->id, 'userid' => 0, 'addedby' => 0, 'timecreated' => time(),
        ]);

        $this->assertFalse(coauthor_manager::is_coauthor((int) $resource->id, 0));
        $this->assertFalse(resource_manager::user_can_edit_resource($resource, 0));
    }

    public function test_get_users_preserves_added_order_and_drops_deleted_accounts(): void {
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $first = $this->getDataGenerator()->create_user(['username' => 'zoe']);
        $secondadded = $this->getDataGenerator()->create_user(['username' => 'adam']);
        $departing = $this->getDataGenerator()->create_user(['username' => 'departing']);
        $resource = $this->make_resource((int) $creator->id);

        coauthor_manager::add($resource, 'zoe', (int) $creator->id);
        coauthor_manager::add($resource, 'adam', (int) $creator->id);
        coauthor_manager::add($resource, 'departing', (int) $creator->id);
        delete_user($departing);

        $users = coauthor_manager::get_users((int) $resource->id);

        $this->assertSame(
            [(int) $first->id, (int) $secondadded->id],
            array_map('intval', array_keys($users)),
            'added order, not alphabetical, and no deleted account'
        );
    }

    /**
     * The notification must actually name the resource. Caught live on
     * 2026-07-27: the subject string used the {$a->title} form while the
     * caller passes a plain title, so the message went out reading
     * literally `You are now a co-author of "{$a->title}"` — message_send()
     * succeeded, which is exactly why the exit code proved nothing.
     */
    public function test_notify_added_warns_that_a_public_profile_now_exists(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $creator = $this->getDataGenerator()->create_user(['firstname' => 'Tomoko', 'lastname' => 'Teacher']);
        $second = $this->getDataGenerator()->create_user(['username' => 'hanako']);
        $resource = $this->make_resource((int) $creator->id);

        // Go through add(), which is what creates the profile row, rather than
        // calling notify_added() against a user who has no profile yet.
        $sink = $this->redirectMessages();
        coauthor_manager::add($resource, 'hanako', (int) $creator->id);
        coauthor_manager::notify_added($resource, $second, $creator);
        $messages = $sink->get_messages();
        $sink->close();

        $body = end($messages)->fullmessage;

        $this->assertStringContainsString(
            'public profile',
            $body,
            'somebody else\'s action just published this person; the notification must say so'
        );
        $this->assertStringContainsString('Show my profile publicly', $body, 'and how to opt out');
        $this->assertStringNotContainsString('{$a', $body, 'unsubstituted placeholder');
    }

    public function test_notify_added_names_the_resource_and_the_person(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $creator = $this->getDataGenerator()->create_user(['firstname' => 'Tomoko', 'lastname' => 'Teacher']);
        $second = $this->getDataGenerator()->create_user(['username' => 'hanako']);
        $resource = $this->make_resource((int) $creator->id);
        $resource->title = 'Coastal Ecology Field Course';

        $sink = $this->redirectMessages();
        coauthor_manager::notify_added($resource, $second, $creator);
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Coastal Ecology Field Course', $messages[0]->subject);
        $this->assertStringNotContainsString('{$a', $messages[0]->subject, 'unsubstituted placeholder');
        $this->assertStringContainsString('Coastal Ecology Field Course', $messages[0]->fullmessage);
        $this->assertStringContainsString('Tomoko Teacher', $messages[0]->fullmessage);
        $this->assertStringNotContainsString('{$a', $messages[0]->fullmessage, 'unsubstituted placeholder');
        $this->assertSame((int) $second->id, (int) $messages[0]->useridto);
    }

    /**
     * MDL Shield self-audit finding 1 (2026-07-27). The delete action was
     * gated on user_can_edit_resource() alone, so an author — and, once
     * co-authors existed, anyone they added — could delete a resource a
     * moderator had taken down. delete_creator_resource() deletes the
     * resource's report rows and flips it to 'deleted', so the subject of a
     * complaint could destroy the complaint AND the takedown record, and the
     * entry vanished from both of moderate.php's lists.
     *
     * @param string $status a status only a moderator may set
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('moderator_held_status_provider')]
    public function test_a_coauthor_cannot_delete_a_resource_under_moderation(string $status): void {
        global $DB;
        $this->resetAfterTest();

        $creator = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user(['username' => 'hanako']);
        $resource = $this->make_resource((int) $creator->id);
        coauthor_manager::add($resource, 'hanako', (int) $creator->id);

        $DB->set_field('local_oerexchange_resources', 'status', $status, ['id' => $resource->id]);
        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $resource->id], '*', MUST_EXIST);

        // Both still hold every other author right — this is not a general
        // lockout, only the one action that destroys moderation evidence.
        $this->assertTrue(resource_manager::user_can_edit_resource($resource, (int) $creator->id));
        $this->assertTrue(resource_manager::user_can_edit_resource($resource, (int) $second->id));

        $this->assertFalse(
            resource_manager::user_can_delete_resource($resource, (int) $creator->id),
            "the creator must not delete a {$status} resource"
        );
        $this->assertFalse(
            resource_manager::user_can_delete_resource($resource, (int) $second->id),
            "a co-author must not delete a {$status} resource"
        );
    }

    /**
     * The statuses only a moderator may set.
     *
     * @return array<string, array{string}>
     */
    public static function moderator_held_status_provider(): array {
        return [
            'moderator takedown' => ['modhidden'],
            'moderator or stale removal' => ['removed'],
        ];
    }

    /**
     * The moderator holding the resource can still delete it — the gate
     * restricts the author side, it does not make moderated content
     * permanent.
     */
    public function test_a_moderator_can_still_delete_a_resource_under_moderation(): void {
        global $DB;
        $this->resetAfterTest();

        $creator = $this->getDataGenerator()->create_user();
        $moderator = $this->getDataGenerator()->create_user();
        role_assign(
            $DB->get_field('role', 'id', ['shortname' => 'manager']),
            $moderator->id,
            \context_system::instance()->id
        );
        $resource = $this->make_resource((int) $creator->id);
        $DB->set_field('local_oerexchange_resources', 'status', 'modhidden', ['id' => $resource->id]);
        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $resource->id], '*', MUST_EXIST);

        $this->assertTrue(resource_manager::user_can_delete_resource($resource, (int) $moderator->id));
    }

    /**
     * The ordinary case is untouched: nothing about co-authorship or the new
     * gate stops an author deleting a resource nobody has moderated.
     */
    public function test_delete_is_unaffected_for_a_resource_nobody_has_moderated(): void {
        global $DB;
        $this->resetAfterTest();

        $creator = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user(['username' => 'hanako']);
        $stranger = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource((int) $creator->id);
        coauthor_manager::add($resource, 'hanako', (int) $creator->id);

        foreach (['published', 'hidden', 'pending'] as $status) {
            $DB->set_field('local_oerexchange_resources', 'status', $status, ['id' => $resource->id]);
            $row = $DB->get_record('local_oerexchange_resources', ['id' => $resource->id], '*', MUST_EXIST);
            $this->assertTrue(
                resource_manager::user_can_delete_resource($row, (int) $creator->id),
                "creator, status={$status}"
            );
            $this->assertTrue(
                resource_manager::user_can_delete_resource($row, (int) $second->id),
                "co-author, status={$status}"
            );
            $this->assertFalse(
                resource_manager::user_can_delete_resource($row, (int) $stranger->id),
                "unrelated user, status={$status}"
            );
        }
    }

    /**
     * A person's right to erasure is not suspended by their content being
     * under moderation, so the GDPR path deliberately does NOT consult the
     * new gate — profile_manager::delete_creator_resource() is called
     * directly by the privacy provider and must still tombstone a moderated
     * resource.
     */
    public function test_gdpr_erasure_still_removes_a_moderated_resource(): void {
        global $DB;
        $this->resetAfterTest();

        $creator = $this->getDataGenerator()->create_user();
        $resource = $this->make_resource((int) $creator->id);
        $DB->set_field('local_oerexchange_resources', 'status', 'modhidden', ['id' => $resource->id]);

        \local_oerexchange\privacy\provider::delete_data_for_user(
            new \core_privacy\local\request\approved_contextlist(
                $creator,
                'local_oerexchange',
                [\context_system::instance()->id]
            )
        );

        $this->assertSame('deleted', $DB->get_field(
            'local_oerexchange_resources',
            'status',
            ['id' => $resource->id],
            MUST_EXIST
        ));
    }

    public function test_delete_for_resource_clears_every_row(): void {
        global $DB;
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_user(['username' => 'hanako']);
        $this->getDataGenerator()->create_user(['username' => 'jiro']);
        $resource = $this->make_resource((int) $creator->id);
        $survivor = $this->make_resource((int) $creator->id);
        coauthor_manager::add($resource, 'hanako', (int) $creator->id);
        coauthor_manager::add($resource, 'jiro', (int) $creator->id);
        coauthor_manager::add($survivor, 'hanako', (int) $creator->id);

        $this->assertSame(2, coauthor_manager::delete_for_resource((int) $resource->id));
        $this->assertSame(0, $DB->count_records('local_oerexchange_coauthors', ['resourceid' => $resource->id]));
        $this->assertSame(
            1,
            $DB->count_records('local_oerexchange_coauthors', ['resourceid' => $survivor->id]),
            'another resource is untouched'
        );
    }

    /**
     * A departing user loses their own co-authorships outright, but the
     * grants they made to other people survive with the attribution scrubbed
     * — revoking a third party's editing rights is not part of this user's
     * erasure.
     */
    public function test_delete_for_user_drops_own_rows_and_scrubs_grants_made(): void {
        global $DB;
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $departing = $this->getDataGenerator()->create_user(['username' => 'departing']);
        $stayer = $this->getDataGenerator()->create_user(['username' => 'stayer']);
        $resource = $this->make_resource((int) $creator->id);

        coauthor_manager::add($resource, 'departing', (int) $creator->id);
        coauthor_manager::add($resource, 'stayer', (int) $departing->id);

        coauthor_manager::delete_for_user((int) $departing->id);

        $this->assertFalse(coauthor_manager::is_coauthor((int) $resource->id, (int) $departing->id));
        $this->assertTrue(coauthor_manager::is_coauthor((int) $resource->id, (int) $stayer->id));
        $this->assertSame(
            0,
            (int) $DB->get_field(
                'local_oerexchange_coauthors',
                'addedby',
                ['resourceid' => $resource->id, 'userid' => $stayer->id]
            ),
            'the grant survives with no attributable granter'
        );
    }
}
