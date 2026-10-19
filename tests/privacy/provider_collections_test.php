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

use PHPUnit\Framework\Attributes\CoversClass;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_oerexchange\local\collection_curator_manager;
use local_oerexchange\local\collection_manager;
use local_oerexchange\local\resource_manager;
use local_oerexchange\local\star_manager;

/**
 * Privacy coverage for the three collection tables and for starred
 * collections.
 *
 * Declaring a table in get_metadata() is the easy half; the defect that keeps
 * recurring in this plugin's own history is a table that is DECLARED and then
 * not serviced by discovery, export or erasure. So every test here works on a
 * fixture whose ONLY data is the one column under test, and the discovery
 * tests assert the user is found with nothing else to find them by.
 *
 * Collections name a person in five places across three tables — the owner,
 * the moderator who held one, whoever added each item, and both ends of a
 * curator invitation — and each of the five is a separate column with its own
 * clause in all three paths.
 *
 * The erasure rules being pinned are the owner's own decisions, and they are
 * asymmetric on purpose:
 *
 * - The user's own collections are tombstoned AND scrubbed: the structural
 *   half through collection_manager::delete(), then userid 0, an empty title,
 *   a null summary and a slug rewritten to 'deleted.<id>' so the erased words
 *   are not left readable in the URL bar.
 * - Items the user added to SOMEBODY ELSE's collection SURVIVE, with addedby
 *   cleared to 0. The owner's list is their curation and must not lose
 *   entries because a contributor left.
 * - A curator row the user held goes; one they merely granted stays, with the
 *   attribution scrubbed, because revoking a third party's rights is not part
 *   of this user's erasure.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(provider::class)]
final class provider_collections_test extends \core_privacy\tests\provider_testcase {
    /**
     * This plugin's data generator.
     *
     * @return \component_generator_base
     */
    protected function generator(): \component_generator_base {
        return $this->getDataGenerator()->get_plugin_generator('local_oerexchange');
    }

    /**
     * A collection in a known state.
     *
     * @param int $userid the owner
     * @param string $title
     * @param array $state columns to overwrite after creation
     * @return \stdClass the stored row
     */
    protected function make_collection(int $userid, string $title, array $state = []): \stdClass {
        return $this->generator()->create_collection($state + [
            'userid' => $userid,
            'title' => $title,
            // UNSPECIFIED MEANS DRAFT, the opposite of the generator's own
            // default, so a fixture's visibility is never accidental.
            'visibility' => collection_manager::VISIBILITY_DRAFT,
            'timepublished' => 0,
        ]);
    }

    /**
     * A public collection.
     *
     * @param int $userid
     * @param string $title
     * @param array $state further columns to overwrite
     * @return \stdClass
     */
    protected function make_public_collection(int $userid, string $title, array $state = []): \stdClass {
        return $this->make_collection($userid, $title, $state + [
            'visibility' => collection_manager::VISIBILITY_PUBLIC,
            'timepublished' => time(),
        ]);
    }

    /**
     * A published resource.
     *
     * @param string $title
     * @return \stdClass
     */
    protected function make_resource(string $title): \stdClass {
        return $this->generator()->create_resource([
            'title' => $title,
            'status' => resource_manager::STATUS_PUBLISHED,
        ]);
    }

    /**
     * The approved contextlist for one user at the system context.
     *
     * @param \stdClass $user
     * @return approved_contextlist
     */
    protected function approved_for(\stdClass $user): approved_contextlist {
        return new approved_contextlist(
            \core_user::get_user($user->id),
            'local_oerexchange',
            [\context_system::instance()->id]
        );
    }

    /**
     * The names get_metadata() declares.
     *
     * @return string[]
     */
    protected function declared_names(): array {
        $collection = new \core_privacy\local\metadata\collection('local_oerexchange');
        $collection = provider::get_metadata($collection);

        return array_map(
            fn($item) => method_exists($item, 'get_name') ? $item->get_name() : null,
            $collection->get_collection()
        );
    }

    /**
     * All three collection tables are declared.
     *
     * @return void
     */
    public function test_get_metadata_declares_the_three_collection_tables(): void {
        $names = $this->declared_names();

        $this->assertContains('local_oerexchange_collections', $names);
        $this->assertContains('local_oerexchange_collection_items', $names);
        $this->assertContains('local_oerexchange_collection_curators', $names);
        // Stars of both kinds are rows in core's favourites, which is
        // declared as a subsystem link rather than as a table of ours.
        $this->assertContains('core_favourites', $names);
    }

    /**
     * Each of the five person-bearing collection columns, on its own, is
     * enough to discover a user.
     *
     * Five users with one row each, so a clause missing for one column cannot
     * be masked by another user's data.
     *
     * @return void
     */
    public function test_each_collection_column_discovers_its_user(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $moderator = $this->getDataGenerator()->create_user();
        $adder = $this->getDataGenerator()->create_user();
        $curator = $this->getDataGenerator()->create_user();
        $granter = $this->getDataGenerator()->create_user();
        $nobody = $this->getDataGenerator()->create_user();

        $collection = $this->make_public_collection((int) $owner->id, 'Owned list');

        // The moderator's only row is the takedown attribution.
        $DB->update_record(collection_manager::TABLE, (object) [
            'id' => $collection->id,
            'modhidden' => 1,
            'modhiddentime' => time(),
            'modhiddenby' => (int) $moderator->id,
        ]);

        // The adder's only row is one item in somebody else's collection.
        $resource = $this->make_resource('A collected resource');
        collection_manager::add_item((int) $collection->id, (int) $resource->id, (int) $adder->id);

        // The curator pair's only rows are the two ends of one invitation.
        $DB->insert_record(collection_curator_manager::TABLE, (object) [
            'collectionid' => $collection->id,
            'userid' => (int) $curator->id,
            'addedby' => (int) $granter->id,
            'timecreated' => time(),
        ]);

        foreach ([$owner, $moderator, $adder, $curator, $granter] as $user) {
            $contextlist = provider::get_contexts_for_userid((int) $user->id);
            // Cast both sides: assertContains() is strict, and contextlist
            // returns the ids as the database's strings.
            $this->assertContains(
                (int) \context_system::instance()->id,
                array_map('intval', $contextlist->get_contextids()),
                "user {$user->id} was not discovered by their only collection row"
            );
        }

        $this->assertEmpty(
            provider::get_contexts_for_userid((int) $nobody->id)->get_contextids(),
            'a user with no data must not be discovered'
        );
    }

    /**
     * A star on a collection, with nothing else, discovers its user — and the
     * export reports it under the starred-collections subcontext rather than
     * among the starred resources.
     *
     * @return void
     */
    public function test_a_starred_collection_is_discovered_and_exported(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $reader = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Starred list');
        star_manager::set_starred(
            (int) $collection->id,
            (int) $reader->id,
            true,
            star_manager::ITEMTYPE_COLLECTION
        );

        $this->assertContains(
            (int) \context_system::instance()->id,
            array_map('intval', provider::get_contexts_for_userid((int) $reader->id)->get_contextids())
        );

        provider::export_user_data($this->approved_for($reader));
        $writer = writer::with_context(\context_system::instance());

        $this->assertNotEmpty($writer->get_data([
            get_string('pluginname', 'local_oerexchange'),
            get_string('privacy:starredcollectionspath', 'local_oerexchange'),
            (string) $collection->id,
        ]), 'the starred collection was not exported');
        // Not filed as a starred RESOURCE: the two id spaces are different,
        // and presenting a collection id as a resource id would be wrong.
        $this->assertEmpty((array) $writer->get_data([
            get_string('pluginname', 'local_oerexchange'),
            get_string('privacy:starredpath', 'local_oerexchange'),
            (string) $collection->id,
        ]));
    }

    /**
     * get_users_in_context() reports all five roles, and never the
     * "no attributable user" sentinel.
     *
     * @return void
     */
    public function test_get_users_in_context_covers_all_five_columns(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $moderator = $this->getDataGenerator()->create_user();
        $adder = $this->getDataGenerator()->create_user();
        $curator = $this->getDataGenerator()->create_user();
        $granter = $this->getDataGenerator()->create_user();

        $collection = $this->make_public_collection((int) $owner->id, 'Owned list');
        $DB->update_record(collection_manager::TABLE, (object) [
            'id' => $collection->id,
            'modhidden' => 1,
            'modhiddentime' => time(),
            'modhiddenby' => (int) $moderator->id,
        ]);
        $resource = $this->make_resource('A collected resource');
        collection_manager::add_item((int) $collection->id, (int) $resource->id, (int) $adder->id);
        $DB->insert_record(collection_curator_manager::TABLE, (object) [
            'collectionid' => $collection->id,
            'userid' => (int) $curator->id,
            'addedby' => (int) $granter->id,
            'timecreated' => time(),
        ]);

        // An erased contributor's surviving item, and an erased owner's
        // tombstone, both carry 0 — which is not a user id.
        // Created with a real owner (collection_manager::create() refuses a
        // falsy one), then scrubbed the way erase_collection() leaves it.
        $erasedowner = $this->make_collection((int) $owner->id, 'An erased owners tombstone', [
            'userid' => 0,
            'status' => collection_manager::STATUS_DELETED,
        ]);
        $second = $this->make_resource('Another collected resource');
        $DB->insert_record(collection_manager::ITEM_TABLE, (object) [
            'collectionid' => $erasedowner->id,
            'resourceid' => $second->id,
            'sortorder' => 1,
            'note' => null,
            'addedby' => 0,
            'timecreated' => time(),
        ]);

        $userlist = new userlist(\context_system::instance(), 'local_oerexchange');
        provider::get_users_in_context($userlist);
        $found = $userlist->get_userids();

        foreach ([$owner, $moderator, $adder, $curator, $granter] as $user) {
            $this->assertContains((int) $user->id, array_map('intval', $found), "user {$user->id} is missing");
        }
        $this->assertNotContains(0, array_map('intval', $found), '0 is a sentinel, not a user');
    }

    /**
     * The export gives a user's own collections their own subcontext, with
     * their items nested in curated order.
     *
     * @return void
     */
    public function test_export_includes_own_collections_with_their_items(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Week one reading');
        $DB->update_record(collection_manager::TABLE, (object) [
            'id' => $collection->id,
            'summary' => 'My own description.',
        ]);
        $first = $this->make_resource('First resource');
        $second = $this->make_resource('Second resource');
        collection_manager::add_item((int) $collection->id, (int) $first->id, (int) $owner->id, 'Read this first.');
        collection_manager::add_item((int) $collection->id, (int) $second->id, (int) $owner->id);

        provider::export_user_data($this->approved_for($owner));
        $data = writer::with_context(\context_system::instance())->get_data([
            get_string('pluginname', 'local_oerexchange'),
            get_string('privacy:collectionspath', 'local_oerexchange'),
        ]);

        $this->assertNotEmpty($data);
        $this->assertCount(1, $data->collections);
        $exported = $data->collections[0];
        $this->assertSame('Week one reading', $exported['title']);
        $this->assertSame('My own description.', $exported['summary']);
        $this->assertSame($collection->slug, $exported['slug']);
        $this->assertSame(collection_manager::VISIBILITY_PUBLIC, $exported['visibility']);
        $this->assertCount(2, $exported['items']);
        $this->assertSame(1, (int) $exported['items'][0]['sortorder']);
        $this->assertSame('Read this first.', $exported['items'][0]['note']);
        // The addedby column is deliberately absent: on an open collection it names
        // somebody else.
        $this->assertArrayNotHasKey('addedby', $exported['items'][0]);
    }

    /**
     * An item a user added to somebody else's collection is exported as their
     * own contribution, in its own subcontext, with the note they wrote.
     *
     * @return void
     */
    public function test_export_reports_items_added_to_other_peoples_collections(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $contributor = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Somebody elses list', ['opencuration' => 1]);
        $resource = $this->make_resource('A contributed resource');
        collection_manager::add_item(
            (int) $collection->id,
            (int) $resource->id,
            (int) $contributor->id,
            'Why I added this.'
        );

        provider::export_user_data($this->approved_for($contributor));
        $data = writer::with_context(\context_system::instance())->get_data([
            get_string('pluginname', 'local_oerexchange'),
            get_string('privacy:collectionitemspath', 'local_oerexchange'),
        ]);

        $this->assertNotEmpty($data);
        $this->assertCount(1, $data->items);
        $this->assertSame((int) $collection->id, (int) $data->items[0]['collectionid']);
        $this->assertSame((int) $resource->id, (int) $data->items[0]['resourceid']);
        $this->assertSame('Why I added this.', $data->items[0]['note']);

        // The contributor does not own a collection, so the owner's own
        // subcontext is not written for them.
        $this->assertEmpty((array) writer::with_context(\context_system::instance())->get_data([
            get_string('pluginname', 'local_oerexchange'),
            get_string('privacy:collectionspath', 'local_oerexchange'),
        ]));
    }

    /**
     * Curatorships held, invitations issued and takedowns performed all reach
     * the main export object.
     *
     * The invitation this user issued names only the collection, not the
     * person invited: that person's identity is their own personal data.
     *
     * @return void
     */
    public function test_export_includes_curatorships_grants_and_takedowns(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $curator = $this->getDataGenerator()->create_user();
        $moderator = $this->getDataGenerator()->create_user();

        $theirs = $this->make_public_collection((int) $owner->id, 'Co-curated list');
        collection_curator_manager::add((int) $theirs->id, (int) $curator->id, (int) $owner->id);

        $held = $this->make_public_collection((int) $owner->id, 'Held list');
        $DB->update_record(collection_manager::TABLE, (object) [
            'id' => $held->id,
            'modhidden' => 1,
            'modhiddentime' => time(),
            'modhiddenby' => (int) $moderator->id,
        ]);

        // The invited curator's view.
        provider::export_user_data($this->approved_for($curator));
        $curatordata = writer::with_context(\context_system::instance())->get_data([
            get_string('pluginname', 'local_oerexchange'),
        ]);
        $this->assertCount(1, $curatordata->collectionscurated);
        $this->assertSame((int) $theirs->id, (int) $curatordata->collectionscurated[0]['collectionid']);
        $this->assertSame([], $curatordata->collectioncuratorsadded);

        writer::reset();

        // The owner who issued the invitation.
        provider::export_user_data($this->approved_for($owner));
        $ownerdata = writer::with_context(\context_system::instance())->get_data([
            get_string('pluginname', 'local_oerexchange'),
        ]);
        $this->assertCount(1, $ownerdata->collectioncuratorsadded);
        $this->assertSame((int) $theirs->id, (int) $ownerdata->collectioncuratorsadded[0]['collectionid']);
        $this->assertArrayNotHasKey('userid', $ownerdata->collectioncuratorsadded[0]);
        $this->assertSame([], $ownerdata->collectionscurated);

        writer::reset();

        // The moderator who held one.
        provider::export_user_data($this->approved_for($moderator));
        $moddata = writer::with_context(\context_system::instance())->get_data([
            get_string('pluginname', 'local_oerexchange'),
        ]);
        $this->assertCount(1, $moddata->collectiontakedowns);
        $this->assertSame((int) $held->id, (int) $moddata->collectiontakedowns[0]['collectionid']);
    }

    /**
     * Erasing a user tombstones their own collections AND scrubs the words
     * and the address.
     *
     * The row survives so inbound links degrade to "this is gone" rather than
     * to a bare 404; the slug becomes 'deleted.<id>', a spelling
     * slug_helper::is_valid() rejects, so no live collection can ever collide
     * with it.
     *
     * @return void
     */
    public function test_erasure_tombstones_and_scrubs_the_users_own_collections(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $curator = $this->getDataGenerator()->create_user();
        $reader = $this->getDataGenerator()->create_user();

        $collection = $this->make_public_collection((int) $owner->id, 'My reading list');
        $DB->update_record(collection_manager::TABLE, (object) [
            'id' => $collection->id,
            'summary' => 'Words I wrote.',
        ]);
        $resource = $this->make_resource('A collected resource');
        collection_manager::add_item((int) $collection->id, (int) $resource->id, (int) $owner->id, 'My note.');
        collection_curator_manager::add((int) $collection->id, (int) $curator->id, (int) $owner->id);
        star_manager::set_starred(
            (int) $collection->id,
            (int) $reader->id,
            true,
            star_manager::ITEMTYPE_COLLECTION
        );

        provider::delete_data_for_user($this->approved_for($owner));

        $after = $DB->get_record(collection_manager::TABLE, ['id' => $collection->id], '*', MUST_EXIST);
        $this->assertSame(collection_manager::STATUS_DELETED, $after->status);
        $this->assertSame(0, (int) $after->userid);
        $this->assertSame('', $after->title);
        $this->assertNull($after->summary);
        $this->assertSame('deleted.' . $collection->id, $after->slug);
        $this->assertNotSame($collection->slug, $after->slug, 'the erased title must not survive in the URL');

        // The structural half: items, curator rows and everybody's stars.
        $this->assertSame(0, $DB->count_records(collection_manager::ITEM_TABLE, [
            'collectionid' => $collection->id,
        ]));
        $this->assertSame(0, $DB->count_records(collection_curator_manager::TABLE, [
            'collectionid' => $collection->id,
        ]));
        $this->assertSame(
            0,
            star_manager::star_count((int) $collection->id, star_manager::ITEMTYPE_COLLECTION)
        );
    }

    /**
     * THE OWNER'S RULE: an item the erased user added to somebody else's
     * collection survives, with addedby cleared.
     *
     * The collection's owner chose to keep that entry; a contributor leaving
     * the site must not silently shorten somebody else's published list. The
     * note stays with the item for the same reason — it is prose published on
     * another person's page — and once addedby is 0 nothing in the row names
     * its writer.
     *
     * @return void
     */
    public function test_erasure_keeps_items_added_elsewhere_and_clears_the_attribution(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $contributor = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Open list', ['opencuration' => 1]);
        $resource = $this->make_resource('A contributed resource');
        $itemid = collection_manager::add_item(
            (int) $collection->id,
            (int) $resource->id,
            (int) $contributor->id,
            'Why I added this.'
        );

        provider::delete_data_for_user($this->approved_for($contributor));

        $item = $DB->get_record(collection_manager::ITEM_TABLE, ['id' => $itemid]);
        $this->assertNotFalse($item, 'the owner keeps their curation when a contributor is erased');
        $this->assertSame(0, (int) $item->addedby);
        $this->assertSame('Why I added this.', $item->note);
        // And the collection itself is untouched: it is not the erased user's.
        $host = $DB->get_record(collection_manager::TABLE, ['id' => $collection->id], '*', MUST_EXIST);
        $this->assertSame(collection_manager::STATUS_ACTIVE, $host->status);
        $this->assertSame('Open list', $host->title);
        $this->assertSame((int) $owner->id, (int) $host->userid);
    }

    /**
     * Erasing a curator drops their own curator row and keeps the ones they
     * granted, with the attribution scrubbed.
     *
     * @return void
     */
    public function test_erasure_drops_own_curatorships_but_keeps_grants_made(): void {
        global $DB;
        $this->resetAfterTest();

        $alice = $this->getDataGenerator()->create_user();
        $bob = $this->getDataGenerator()->create_user();
        $carol = $this->getDataGenerator()->create_user();

        // Alice curates Bob's collection...
        $bobs = $this->make_public_collection((int) $bob->id, 'Bobs list');
        collection_curator_manager::add((int) $bobs->id, (int) $alice->id, (int) $bob->id);
        // ...and Alice invited Carol to curate another of Bob's collections.
        // That grant is Carol's rights over Bob's list, not Alice's data to
        // take with her.
        $others = $this->make_public_collection((int) $bob->id, 'Another of Bobs lists');
        collection_curator_manager::add((int) $others->id, (int) $carol->id, (int) $alice->id);

        provider::delete_data_for_user($this->approved_for($alice));

        $this->assertSame(0, $DB->count_records(collection_curator_manager::TABLE, [
            'userid' => $alice->id,
        ]));
        $kept = $DB->get_record(collection_curator_manager::TABLE, [
            'collectionid' => $others->id,
            'userid' => $carol->id,
        ]);
        $this->assertNotFalse($kept, "a third party's curating rights are not Alice's to revoke");
        $this->assertSame(0, (int) $kept->addedby);
    }

    /**
     * Erasing a moderator keeps the takedown but stops naming them.
     *
     * The decision to hold a collection is the site's, not the moderator's
     * personal data; the attribution is.
     *
     * @return void
     */
    public function test_erasure_keeps_a_takedown_but_drops_the_moderators_id(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $moderator = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Held list');
        $DB->update_record(collection_manager::TABLE, (object) [
            'id' => $collection->id,
            'modhidden' => 1,
            'modhiddentime' => time(),
            'modhiddenby' => (int) $moderator->id,
        ]);

        provider::delete_data_for_user($this->approved_for($moderator));

        $after = $DB->get_record(collection_manager::TABLE, ['id' => $collection->id], '*', MUST_EXIST);
        $this->assertSame(1, (int) $after->modhidden, 'the collection stays held');
        $this->assertSame(0, (int) $after->modhiddenby);
    }

    /**
     * Erasing a reader removes their collection stars and nobody else's.
     *
     * @return void
     */
    public function test_erasure_removes_the_users_collection_stars_only(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $leaver = $this->getDataGenerator()->create_user();
        $stayer = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Starred list');

        foreach ([$leaver, $stayer] as $reader) {
            star_manager::set_starred(
                (int) $collection->id,
                (int) $reader->id,
                true,
                star_manager::ITEMTYPE_COLLECTION
            );
        }
        $this->assertSame(2, star_manager::star_count((int) $collection->id, star_manager::ITEMTYPE_COLLECTION));

        provider::delete_data_for_user($this->approved_for($leaver));

        $this->assertSame(1, star_manager::star_count((int) $collection->id, star_manager::ITEMTYPE_COLLECTION));
        $this->assertTrue(star_manager::is_starred(
            (int) $collection->id,
            (int) $stayer->id,
            star_manager::ITEMTYPE_COLLECTION
        ));
    }

    /**
     * delete_data_for_users() applies the same rules to a batch, which is the
     * path an admin's bulk request takes.
     *
     * @return void
     */
    public function test_delete_data_for_users_erases_each_named_user(): void {
        global $DB;
        $this->resetAfterTest();

        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        $survivor = $this->getDataGenerator()->create_user();

        $firsts = $this->make_public_collection((int) $first->id, 'First users list');
        $seconds = $this->make_public_collection((int) $second->id, 'Second users list');
        $survivors = $this->make_public_collection((int) $survivor->id, 'Untouched list');

        provider::delete_data_for_users(new approved_userlist(
            \context_system::instance(),
            'local_oerexchange',
            [(int) $first->id, (int) $second->id]
        ));

        foreach ([$firsts, $seconds] as $erased) {
            $after = $DB->get_record(collection_manager::TABLE, ['id' => $erased->id], '*', MUST_EXIST);
            $this->assertSame(collection_manager::STATUS_DELETED, $after->status);
            $this->assertSame(0, (int) $after->userid);
            $this->assertSame('', $after->title);
        }

        $kept = $DB->get_record(collection_manager::TABLE, ['id' => $survivors->id], '*', MUST_EXIST);
        $this->assertSame(collection_manager::STATUS_ACTIVE, $kept->status);
        $this->assertSame('Untouched list', $kept->title);
    }

    /**
     * Erasing the whole context leaves no item rows, no curator rows, no
     * stars and no named owners.
     *
     * @return void
     */
    public function test_delete_data_for_all_users_empties_the_collection_tables(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $curator = $this->getDataGenerator()->create_user();
        $reader = $this->getDataGenerator()->create_user();

        $collection = $this->make_public_collection((int) $owner->id, 'Everybodys list');
        $resource = $this->make_resource('A collected resource');
        collection_manager::add_item((int) $collection->id, (int) $resource->id, (int) $owner->id);
        collection_curator_manager::add((int) $collection->id, (int) $curator->id, (int) $owner->id);
        star_manager::set_starred(
            (int) $collection->id,
            (int) $reader->id,
            true,
            star_manager::ITEMTYPE_COLLECTION
        );

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertSame(0, $DB->count_records(collection_manager::ITEM_TABLE));
        $this->assertSame(0, $DB->count_records(collection_curator_manager::TABLE));
        $this->assertSame(
            0,
            star_manager::star_count((int) $collection->id, star_manager::ITEMTYPE_COLLECTION)
        );

        // The skeleton survives as tombstones, with nothing of anybody's in
        // them.
        $tombstone = $DB->get_record(collection_manager::TABLE, ['id' => $collection->id], '*', MUST_EXIST);
        $this->assertSame(collection_manager::STATUS_DELETED, $tombstone->status);
        $this->assertSame(0, (int) $tombstone->userid);
        $this->assertSame('', $tombstone->title);
    }

    /**
     * A context other than the system one is left alone: this plugin's data
     * all lives at the system level.
     *
     * @return void
     */
    public function test_delete_data_for_all_users_ignores_another_context(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Untouched list');
        $course = $this->getDataGenerator()->create_course([
            'summary' => 'Fixture course for the collections privacy test: it only supplies a '
                . 'non-system context, and holds no content.',
        ]);

        provider::delete_data_for_all_users_in_context(\context_course::instance($course->id));

        $after = $DB->get_record(collection_manager::TABLE, ['id' => $collection->id], '*', MUST_EXIST);
        $this->assertSame(collection_manager::STATUS_ACTIVE, $after->status);
        $this->assertSame('Untouched list', $after->title);
    }
}
