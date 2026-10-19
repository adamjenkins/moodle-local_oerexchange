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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The collection permission table, cell by cell.
 *
 * Collections have three tiers of contributor — the owner, the people the
 * owner named as curators, and (when the owner has opened curation) any
 * logged-in user — crossed with a moderator who may hold a collection and
 * nothing else. That is four actors and four verbs, and the whole table lives
 * in collection_manager because this plugin already has the scar from the
 * alternative: resource_manager::user_can_edit_resource()'s docblock records a
 * second, differently-guarded copy of one authorisation rule that let an
 * author undo a moderator's takedown.
 *
 * The table, which is what this file asserts:
 *
 * | Action                 | Owner | Curator | Open curation | Moderator |
 * | Add item               | yes   | yes     | yes           | yes       |
 * | Remove item            | any   | any     | only own      | any       |
 * | Reorder / edit notes   | yes   | yes     | no            | no        |
 * | Metadata, visibility,  | yes   | no      | no            | takedown  |
 * | cover, slug, curators, |       |         |               | only      |
 * | opencuration, delete   |       |         |               |           |
 *
 * Four cells are the ones worth losing sleep over, and each has its own test:
 *
 * - a named curator may REORDER but not RENAME. A curator was invited to help
 *   choose resources, not to retitle or unpublish somebody's published list.
 * - an open-curation contributor may remove the item THEY added and no other.
 *   Otherwise "anyone may add" silently means "anyone may empty".
 * - a moderator is not an owner. Their power is set_modhidden(), which is
 *   reversible; editing or deleting a stranger's collection is not.
 * - every falsy or guest userid is refused everywhere. A tombstoned
 *   collection, or one whose owner has been through GDPR erasure, holds
 *   userid 0 — and a logged-out visitor is userid 0 too.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(collection_manager::class)]
final class collection_permissions_test extends \advanced_testcase {
    /**
     * This plugin's data generator.
     *
     * @return \local_oerexchange_generator
     */
    protected function generator(): \local_oerexchange_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_oerexchange');
    }

    /**
     * A user holding local/oerexchange:moderate in the system context.
     *
     * @return \stdClass the user record
     */
    protected function make_moderator(): \stdClass {
        $moderator = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(
            'local/oerexchange:moderate',
            CAP_ALLOW,
            $roleid,
            \context_system::instance()->id
        );
        role_assign($roleid, $moderator->id, \context_system::instance()->id);

        return $moderator;
    }

    /**
     * Add a resource to a collection and return the stored item row.
     *
     * The ROW is what user_can_remove_item() takes, and it has to be the row
     * the database holds rather than one a test assembled, because addedby is
     * the column the open-curation rule turns on.
     *
     * @param \stdClass $collection the collection to add to
     * @param int $userid who is adding it, recorded as addedby
     * @param string $title a title for the new resource, so failures name it
     * @return \stdClass the stored collection-item row
     */
    protected function add_item(\stdClass $collection, int $userid, string $title): \stdClass {
        global $DB;

        $resource = $this->generator()->create_resource(['title' => $title]);
        $itemid = collection_manager::add_item((int) $collection->id, (int) $resource->id, $userid);

        return $DB->get_record(collection_manager::ITEM_TABLE, ['id' => $itemid], '*', MUST_EXIST);
    }

    /**
     * Adding is the widest tier: the owner, a named curator, any logged-in
     * user while curation is open, and a moderator.
     *
     * @return void
     */
    public function test_add_item_is_allowed_for_all_four_actors_while_curation_is_open(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $curator = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $moderator = $this->make_moderator();
        $collection = $this->generator()->create_collection([
            'userid' => (int) $owner->id,
            'opencuration' => 1,
        ]);
        collection_curator_manager::add((int) $collection->id, (int) $curator->id, (int) $owner->id);
        $collection = collection_manager::get((int) $collection->id);

        $this->assertTrue(collection_manager::user_can_add_items($collection, (int) $owner->id));
        $this->assertTrue(collection_manager::user_can_add_items($collection, (int) $curator->id));
        $this->assertTrue(collection_manager::user_can_add_items($collection, (int) $stranger->id));
        $this->assertTrue(collection_manager::user_can_add_items($collection, (int) $moderator->id));
    }

    /**
     * With curation closed, a stranger may not add — and the other three
     * still may.
     *
     * Both halves in one test on purpose: a rule that refused everybody would
     * pass the first assertion alone.
     *
     * @return void
     */
    public function test_a_stranger_cannot_add_once_curation_is_closed(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $curator = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $moderator = $this->make_moderator();
        $collection = $this->generator()->create_collection([
            'userid' => (int) $owner->id,
            'opencuration' => 0,
        ]);
        collection_curator_manager::add((int) $collection->id, (int) $curator->id, (int) $owner->id);
        $collection = collection_manager::get((int) $collection->id);

        $this->assertFalse(collection_manager::user_can_add_items($collection, (int) $stranger->id));
        $this->assertTrue(collection_manager::user_can_add_items($collection, (int) $owner->id));
        $this->assertTrue(collection_manager::user_can_add_items($collection, (int) $curator->id));
        $this->assertTrue(collection_manager::user_can_add_items($collection, (int) $moderator->id));
    }

    /**
     * The guest account is refused even while curation is open, and so is a
     * logged-out visitor.
     *
     * isloggedin() is true for the guest user, so "logged in" alone would
     * hand every anonymous visitor on a guest-access site the ability to
     * append to open collections — and guest is a SHARED session, so the
     * addedby column could not even say who did it.
     *
     * @return void
     */
    public function test_guests_and_logged_out_visitors_can_never_add(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection([
            'userid' => (int) $owner->id,
            'opencuration' => 1,
        ]);
        $guestid = (int) guest_user()->id;

        $this->assertTrue(isguestuser($guestid), 'the fixture really is the guest account');
        $this->assertFalse(collection_manager::user_can_add_items($collection, $guestid));
        $this->assertFalse(collection_manager::user_can_add_items($collection, 0));
    }

    /**
     * An open-curation contributor may remove the item THEY added, and may
     * not touch anybody else's.
     *
     * This is the cell that makes open curation safe to switch on: without
     * it, "anyone may add" would mean "anyone may empty".
     *
     * @return void
     */
    public function test_an_open_contributor_may_remove_only_their_own_item(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $contributor = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection([
            'userid' => (int) $owner->id,
            'opencuration' => 1,
        ]);
        $mine = $this->add_item($collection, (int) $contributor->id, 'Added by the contributor');
        $theirs = $this->add_item($collection, (int) $other->id, 'Added by somebody else');
        $owners = $this->add_item($collection, (int) $owner->id, 'Added by the owner');

        $this->assertTrue(
            collection_manager::user_can_remove_item($collection, $mine, (int) $contributor->id)
        );
        $this->assertFalse(
            collection_manager::user_can_remove_item($collection, $theirs, (int) $contributor->id),
            'another contributor\'s item is not theirs to undo'
        );
        $this->assertFalse(
            collection_manager::user_can_remove_item($collection, $owners, (int) $contributor->id),
            'the owner\'s own item least of all'
        );
    }

    /**
     * The owner, a named curator and a moderator may remove ANY item,
     * whoever added it.
     *
     * @return void
     */
    public function test_the_owner_a_curator_and_a_moderator_may_remove_any_item(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $curator = $this->getDataGenerator()->create_user();
        $contributor = $this->getDataGenerator()->create_user();
        $moderator = $this->make_moderator();
        $collection = $this->generator()->create_collection([
            'userid' => (int) $owner->id,
            'opencuration' => 1,
        ]);
        collection_curator_manager::add((int) $collection->id, (int) $curator->id, (int) $owner->id);
        $collection = collection_manager::get((int) $collection->id);
        $item = $this->add_item($collection, (int) $contributor->id, 'Added by the contributor');

        $this->assertTrue(collection_manager::user_can_remove_item($collection, $item, (int) $owner->id));
        $this->assertTrue(collection_manager::user_can_remove_item($collection, $item, (int) $curator->id));
        $this->assertTrue(collection_manager::user_can_remove_item($collection, $item, (int) $moderator->id));
        $this->assertFalse(
            collection_manager::user_can_remove_item($collection, $item, 0),
            'a logged-out visitor removes nothing'
        );
    }

    /**
     * An item is authorised against the collection it was passed with, so a
     * caller that loaded a collection from one posted id and an item from
     * another cannot authorise a removal against the wrong list.
     *
     * The actor owns BOTH collections here, so the only thing that can
     * produce a refusal is the mismatch itself — and the correctly paired
     * call right after it proves the refusal was the mismatch and not a
     * blanket no.
     *
     * @return void
     */
    public function test_an_item_from_another_collection_is_refused_outright(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $first = $this->generator()->create_collection(['userid' => (int) $owner->id, 'title' => 'First']);
        $second = $this->generator()->create_collection(['userid' => (int) $owner->id, 'title' => 'Second']);
        $seconditem = $this->add_item($second, (int) $owner->id, 'In the second collection');

        $this->assertFalse(
            collection_manager::user_can_remove_item($first, $seconditem, (int) $owner->id),
            'the collection in hand and the item in hand must agree'
        );
        $this->assertTrue(
            collection_manager::user_can_remove_item($second, $seconditem, (int) $owner->id),
            'the same actor and item, correctly paired, is a different answer'
        );
    }

    /**
     * An item whose adder has been through GDPR erasure (addedby 0) is not
     * removable by an open contributor, whoever they are.
     *
     * 0 is this plugin's "no attributable user" sentinel. Without the
     * explicit guard, the day a falsy userid slipped past the entry checks it
     * would match every erased row at once.
     *
     * @return void
     */
    public function test_an_erased_adder_is_nobodys_own_item(): void {
        global $DB;
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $contributor = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection([
            'userid' => (int) $owner->id,
            'opencuration' => 1,
        ]);
        $item = $this->add_item($collection, (int) $contributor->id, 'Added then erased');
        $DB->set_field(collection_manager::ITEM_TABLE, 'addedby', 0, ['id' => $item->id]);
        $item = $DB->get_record(collection_manager::ITEM_TABLE, ['id' => $item->id], '*', MUST_EXIST);

        $this->assertSame(0, (int) $item->addedby);
        $this->assertFalse(
            collection_manager::user_can_remove_item($collection, $item, (int) $contributor->id)
        );
        $this->assertTrue(
            collection_manager::user_can_remove_item($collection, $item, (int) $owner->id),
            'the owner can still tidy it up'
        );
    }

    /**
     * Closing open curation withdraws the right to remove an item the
     * contributor added while it was open — and leaves the item itself alone.
     *
     * The contribution is the owner's collection now. Revoking the invitation
     * is not a reason to silently shorten somebody's published reading list.
     *
     * @return void
     */
    public function test_closing_curation_withdraws_the_right_but_not_the_item(): void {
        global $DB;
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $contributor = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection([
            'userid' => (int) $owner->id,
            'opencuration' => 1,
        ]);
        $item = $this->add_item($collection, (int) $contributor->id, 'Added while open');
        $this->assertTrue(
            collection_manager::user_can_remove_item($collection, $item, (int) $contributor->id)
        );

        collection_manager::set_open_curation((int) $collection->id, false);
        $collection = collection_manager::get((int) $collection->id);

        $this->assertFalse(
            collection_manager::user_can_remove_item($collection, $item, (int) $contributor->id)
        );
        $this->assertFalse(collection_manager::user_can_add_items($collection, (int) $contributor->id));
        $this->assertTrue(
            $DB->record_exists(collection_manager::ITEM_TABLE, ['id' => $item->id]),
            'the item they chose stays in the collection'
        );
    }

    /**
     * A named curator may reorder and annotate, and may not rename, retitle,
     * reslug, republish, delete, or change who else curates.
     *
     * The single most important cell in the table: the shapes of
     * coauthor_manager and collection_curator_manager are nearly identical,
     * and the rights they grant deliberately are not.
     *
     * @return void
     */
    public function test_a_named_curator_may_reorder_but_may_not_rename(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $curator = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id]);
        collection_curator_manager::add((int) $collection->id, (int) $curator->id, (int) $owner->id);
        $collection = collection_manager::get((int) $collection->id);

        $this->assertTrue(
            collection_manager::user_can_curate($collection, (int) $curator->id),
            'reordering and notes are a curator\'s job'
        );
        $this->assertFalse(
            collection_manager::user_can_manage($collection, (int) $curator->id),
            'the title, the slug, the visibility and the curator list are not'
        );
        $this->assertTrue(collection_manager::user_can_manage($collection, (int) $owner->id));
    }

    /**
     * An open-curation invitation is explicitly NOT a licence to reorder or
     * to write notes.
     *
     * Reordering is editorial control over somebody else's published list,
     * and a note is prose published under the collection's name. "Anyone may
     * add" was never meant to mean "anyone may rewrite".
     *
     * @return void
     */
    public function test_open_curation_does_not_grant_reordering_or_notes(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $contributor = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection([
            'userid' => (int) $owner->id,
            'opencuration' => 1,
        ]);

        $this->assertTrue(collection_manager::user_can_add_items($collection, (int) $contributor->id));
        $this->assertFalse(collection_manager::user_can_curate($collection, (int) $contributor->id));
        $this->assertFalse(collection_manager::user_can_manage($collection, (int) $contributor->id));
    }

    /**
     * A moderator may hold a collection and may not become its editor.
     *
     * A takedown is reversible; silently rewriting the order of a stranger's
     * list, or renaming it, is not. So set_modhidden() works for them while
     * user_can_curate() and user_can_manage() both refuse.
     *
     * @return void
     */
    public function test_a_moderator_can_take_down_but_is_not_an_owner(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $moderator = $this->make_moderator();
        // Published, because a moderator's view of a DRAFT is a 404 whatever
        // else is true of it — see collection_visibility_test.
        $collection = $this->generator()->create_collection([
            'userid' => (int) $owner->id,
            'visibility' => collection_manager::VISIBILITY_PUBLIC,
        ]);

        $this->assertFalse(collection_manager::user_can_curate($collection, (int) $moderator->id));
        $this->assertFalse(collection_manager::user_can_manage($collection, (int) $moderator->id));

        collection_manager::set_modhidden((int) $collection->id, true, (int) $moderator->id);
        $held = collection_manager::get((int) $collection->id);

        $this->assertSame(1, (int) $held->modhidden);
        $this->assertSame((int) $moderator->id, (int) $held->modhiddenby);
        $this->assertTrue(
            collection_manager::user_can_view_collection($held, (int) $moderator->id),
            'a moderator can see what they are holding'
        );
        $this->assertFalse(
            collection_manager::user_can_manage($held, (int) $moderator->id),
            'holding it still does not make it theirs to edit'
        );
    }

    /**
     * Revoking a curator revokes their rights immediately, and leaves the
     * items they chose in place.
     *
     * @return void
     */
    public function test_a_revoked_curator_loses_their_rights_and_keeps_nothing_of_the_collection(): void {
        global $DB;
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $curator = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id]);
        collection_curator_manager::add((int) $collection->id, (int) $curator->id, (int) $owner->id);
        $collection = collection_manager::get((int) $collection->id);
        $item = $this->add_item($collection, (int) $curator->id, 'Chosen by the curator');
        $this->assertTrue(collection_manager::user_can_curate($collection, (int) $curator->id));

        collection_curator_manager::remove((int) $collection->id, (int) $curator->id);

        $this->assertFalse(collection_curator_manager::is_curator((int) $collection->id, (int) $curator->id));
        $this->assertFalse(collection_manager::user_can_curate($collection, (int) $curator->id));
        $this->assertFalse(
            collection_manager::user_can_add_items($collection, (int) $curator->id),
            'curation is closed on this collection, so they are now a stranger'
        );
        $this->assertFalse(
            collection_manager::user_can_remove_item($collection, $item, (int) $curator->id),
            'not even the item they themselves chose'
        );
        $this->assertTrue(
            $DB->record_exists(collection_manager::ITEM_TABLE, ['id' => $item->id]),
            'their choices are the collection\'s content now'
        );
    }

    /**
     * Being a curator of one collection grants nothing over another.
     *
     * @return void
     */
    public function test_a_curator_of_one_collection_is_a_stranger_to_another(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $curator = $this->getDataGenerator()->create_user();
        $curated = $this->generator()->create_collection(['userid' => (int) $owner->id, 'title' => 'Curated']);
        $other = $this->generator()->create_collection(['userid' => (int) $owner->id, 'title' => 'Other']);
        collection_curator_manager::add((int) $curated->id, (int) $curator->id, (int) $owner->id);

        $this->assertTrue(collection_manager::user_can_curate($curated, (int) $curator->id));
        $this->assertFalse(collection_manager::user_can_curate($other, (int) $curator->id));
        $this->assertFalse(collection_manager::user_can_add_items($other, (int) $curator->id));
    }

    /**
     * Managing a collection is the owner's alone — and a logged-out visitor
     * is not the owner of a collection whose owner has been erased.
     *
     * A GDPR erasure leaves collections.userid at 0, which is also every
     * anonymous visitor's $USER->id. Without the truthiness guard on the
     * owner check, every erased collection would belong to everybody.
     *
     * @return void
     */
    public function test_an_erased_owner_is_not_every_logged_out_visitor(): void {
        $this->resetAfterTest();
        $erased = $this->generator()->create_collection(['userid' => 0, 'opencuration' => 1]);

        $this->assertFalse(collection_manager::user_can_manage($erased, 0));
        $this->assertFalse(collection_manager::user_can_curate($erased, 0));
        $this->assertFalse(collection_manager::user_can_add_items($erased, 0));
    }

    /**
     * A tombstone grants nothing to anybody, the owner included.
     *
     * Its page still renders — that is the point of a tombstone — but there
     * is nothing left to add to, remove from, reorder or rename.
     *
     * @return void
     */
    public function test_a_tombstone_grants_nothing_to_anybody(): void {
        global $DB;
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $curator = $this->getDataGenerator()->create_user();
        $moderator = $this->make_moderator();
        $collection = $this->generator()->create_collection([
            'userid' => (int) $owner->id,
            'opencuration' => 1,
        ]);
        collection_curator_manager::add((int) $collection->id, (int) $curator->id, (int) $owner->id);
        $item = $this->add_item($collection, (int) $owner->id, 'Collected before deletion');

        // Tombstone the row without going through delete(), which would also
        // drop the item and the curator row this test wants to ask about.
        $DB->set_field(
            collection_manager::TABLE,
            'status',
            collection_manager::STATUS_DELETED,
            ['id' => $collection->id]
        );
        $collection = collection_manager::get((int) $collection->id);

        foreach ([(int) $owner->id, (int) $curator->id, (int) $moderator->id] as $userid) {
            $this->assertFalse(collection_manager::user_can_add_items($collection, $userid), (string) $userid);
            $this->assertFalse(collection_manager::user_can_curate($collection, $userid), (string) $userid);
            $this->assertFalse(collection_manager::user_can_manage($collection, $userid), (string) $userid);
            $this->assertFalse(
                collection_manager::user_can_remove_item($collection, $item, $userid),
                (string) $userid
            );
        }
    }

    /**
     * The owner's rights do not depend on the collection's visibility: a
     * draft is as much theirs to edit as a published one.
     *
     * Worth stating because the view gate DOES depend on visibility, and the
     * two rules are answered by different methods that a page could easily
     * confuse.
     *
     * @return void
     */
    public function test_the_owners_rights_are_the_same_in_every_visibility(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();

        foreach (collection_manager::VISIBILITIES as $visibility) {
            $collection = $this->generator()->create_collection([
                'userid' => (int) $owner->id,
                'title' => 'Owned ' . $visibility,
                'visibility' => $visibility,
            ]);

            $this->assertTrue(collection_manager::user_can_manage($collection, (int) $owner->id), $visibility);
            $this->assertTrue(collection_manager::user_can_curate($collection, (int) $owner->id), $visibility);
            $this->assertTrue(collection_manager::user_can_add_items($collection, (int) $owner->id), $visibility);
        }
    }

    /**
     * A taken-down collection is still the owner's to edit.
     *
     * modhidden is a moderator's hold, not a freeze: the owner has to be able
     * to fix whatever was reported, which means the edit rights survive the
     * takedown even though the collection is invisible to the public.
     *
     * @return void
     */
    public function test_a_takedown_does_not_strip_the_owners_edit_rights(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection([
            'userid' => (int) $owner->id,
            'visibility' => collection_manager::VISIBILITY_PUBLIC,
            'modhidden' => 1,
            'modhiddentime' => time(),
        ]);

        $this->assertTrue(collection_manager::user_can_manage($collection, (int) $owner->id));
        $this->assertTrue(collection_manager::user_can_curate($collection, (int) $owner->id));
        $this->assertTrue(collection_manager::user_can_add_items($collection, (int) $owner->id));
    }
}
