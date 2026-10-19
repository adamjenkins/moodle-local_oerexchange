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
use core\router\route_loader_interface;
use core\tests\router\route_testcase;
use local_oerexchange\local\collection_manager;
use local_oerexchange\local\collection_curator_manager;
use local_oerexchange\local\resource_manager;
use local_oerexchange\local\star_manager;
use local_oerexchange\route\controller\collection_controller;

/**
 * Tests for collection_controller (GET+POST /c/{slug}).
 *
 * THREE ROUTE_TESTCASE GOTCHAS APPLY HERE, all three already documented at
 * length in tests/profile_controller_test.php and
 * tests/profile_edit_controller_test.php — read those docblocks first. In
 * short:
 *
 * - add_class_routes_to_route_loader()'s $grouppath must be '' and not
 *   route_loader_interface::ROUTE_GROUP_PAGE, which double-slashes the
 *   compiled Slim pattern and then silently 404s — indistinguishable at the
 *   assertion level from a correct 404.
 * - Dispatch is process_request(method, path-without-leading-slash, group);
 *   route_testcase has no handle() helper.
 * - An exception thrown inside the controller does NOT reach PHPUnit: Slim's
 *   ErrorMiddleware turns every Throwable into a response, so a refusal is
 *   asserted on the status code (403 for access_denied_exception), never with
 *   expectException().
 *
 * And one specific to this controller: it reads POST values with
 * optional_param()/required_param() rather than $request->getParsedBody(),
 * which is guaranteed empty for a route declaring no requestbody schema
 * (request_validator::validate_request_body()). These tests therefore set
 * $_POST directly, exactly as profile_edit_controller_test does.
 *
 * What is pinned here is the visibility matrix as the WEB sees it — a draft, a
 * held collection and a tombstone each have a different right answer for each
 * kind of viewer — and the two guards on every state change: a sesskey, and an
 * authorisation re-derived from the database against the SLUG-resolved
 * collection, so that a posted item or resource id can never reach a
 * collection the caller was not authorised against.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(collection_controller::class)]
final class collection_controller_test extends route_testcase {
    #[\Override]
    protected function tearDown(): void {
        // These tests set $_POST directly (see the class docblock) to reach
        // the controller's optional_param()/required_param() reads — clear it
        // so it cannot leak into an unrelated later test.
        $_POST = [];
        parent::tearDown();
    }

    /**
     * This plugin's data generator.
     *
     * @return \component_generator_base
     */
    protected function generator(): \component_generator_base {
        return $this->getDataGenerator()->get_plugin_generator('local_oerexchange');
    }

    /**
     * Register this controller's route. Called by every test; $grouppath is
     * '' deliberately (see the class docblock).
     */
    protected function register_route(): void {
        $this->add_class_routes_to_route_loader(collection_controller::class, '');
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
            // default: the visibility matrix is what these tests are about,
            // so the unpublished state has to be explicit.
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
     * The messages of every notification queued during the request, clearing
     * the queue.
     *
     * Under PHPUnit the CLI renderer's header() prints only the page heading,
     * so a notification queued for the header never reaches the response
     * body; it stays in $SESSION, which is where a real page's header reads
     * it from.
     *
     * @return string[]
     */
    protected function queued_notifications(): array {
        return array_map(fn($notification) => $notification->get_message(), \core\notification::fetch());
    }

    /**
     * GET one collection's page.
     *
     * @param string $slug
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function get_page(string $slug): \Psr\Http\Message\ResponseInterface {
        return $this->process_request('GET', 'c/' . $slug, route_loader_interface::ROUTE_GROUP_PAGE);
    }

    /**
     * POST an action to one collection's page.
     *
     * @param string $slug
     * @param array $post the body, as the real $_POST superglobal
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function post_action(string $slug, array $post): \Psr\Http\Message\ResponseInterface {
        $_POST = $post;

        return $this->process_request('POST', 'c/' . $slug, route_loader_interface::ROUTE_GROUP_PAGE);
    }

    /**
     * A public collection renders for a logged-out visitor, with its items.
     *
     * @return void
     */
    public function test_a_public_collection_renders_for_anyone(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Week one reading');
        $resource = $this->make_resource('Cell biology basics');
        collection_manager::add_item((int) $collection->id, (int) $resource->id, (int) $owner->id);

        $this->register_route();
        $this->setUser(null);
        $response = $this->get_page($collection->slug);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Week one reading', $body);
        $this->assertStringContainsString('Cell biology basics', $body);
        // A public page is indexable; the header is only for the two states
        // that must not be.
        $this->assertSame('', $response->getHeaderLine('X-Robots-Tag'));
    }

    /**
     * A slug that resolves to nothing is a 404.
     *
     * @return void
     */
    public function test_an_unknown_slug_is_a_404(): void {
        $this->resetAfterTest();

        $this->register_route();
        $response = $this->get_page('no-such-collection');

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * A draft is the owner's alone: a stranger gets a 404, and so does a
     * moderator. The owner sees it, with the draft notice.
     *
     * @return void
     */
    public function test_a_draft_is_404_for_everyone_but_its_owner(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $collection = $this->make_collection((int) $owner->id, 'Unpublished thoughts');

        $this->register_route();

        $this->setUser(null);
        $this->assertSame(404, $this->get_page($collection->slug)->getStatusCode());

        $this->setUser($stranger);
        $this->assertSame(404, $this->get_page($collection->slug)->getStatusCode());

        // Moderators too: there is nothing to moderate about a collection
        // that has never been published.
        $this->setAdminUser();
        $this->assertSame(404, $this->get_page($collection->slug)->getStatusCode());

        $this->setUser($owner);
        $response = $this->get_page($collection->slug);
        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Unpublished thoughts', $body);
        $this->assertStringContainsString(
            get_string('collectionvisibilitydraftnotice', 'local_oerexchange'),
            $body
        );
        // Not indexable, and asked not to be.
        $this->assertStringContainsString('noindex', $response->getHeaderLine('X-Robots-Tag'));
    }

    /**
     * An unlisted collection renders for a stranger who has the link, and
     * carries noindex so following that link does not put it in a search
     * index its owner never asked to be in.
     *
     * @return void
     */
    public function test_an_unlisted_collection_renders_for_a_stranger_with_the_link(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_collection((int) $owner->id, 'Shared with my department', [
            'visibility' => collection_manager::VISIBILITY_UNLISTED,
            'timepublished' => time(),
        ]);

        $this->register_route();
        $this->setUser(null);
        $response = $this->get_page($collection->slug);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Shared with my department', (string) $response->getBody());
        $this->assertStringContainsString('noindex', $response->getHeaderLine('X-Robots-Tag'));
        // A stranger is not told it is unlisted: they have the link, which is
        // the whole of what unlisted means.
        $this->assertStringNotContainsString(
            get_string('collectionvisibilityunlistednotice', 'local_oerexchange'),
            (string) $response->getBody()
        );
    }

    /**
     * A held collection: the owner sees it with a notice, a moderator sees it
     * and can restore it, everybody else gets a 404.
     *
     * @return void
     */
    public function test_a_held_collection_is_404_for_strangers_only(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Held reading list', [
            'modhidden' => 1,
            'modhiddentime' => time(),
            'modhiddenby' => 2,
        ]);

        $this->register_route();

        $this->setUser(null);
        $this->assertSame(404, $this->get_page($collection->slug)->getStatusCode());

        $this->setUser($stranger);
        $this->assertSame(404, $this->get_page($collection->slug)->getStatusCode());

        $this->setUser($owner);
        $ownerresponse = $this->get_page($collection->slug);
        $this->assertSame(200, $ownerresponse->getStatusCode());
        $this->assertStringContainsString(
            get_string('collectionmodhiddenownernotice', 'local_oerexchange'),
            (string) $ownerresponse->getBody()
        );

        $this->setAdminUser();
        $modresponse = $this->get_page($collection->slug);
        $this->assertSame(200, $modresponse->getStatusCode());
        $this->assertStringContainsString('Held reading list', (string) $modresponse->getBody());
    }

    /**
     * A tombstone is shown to everyone, and carries none of the collection's
     * content.
     *
     * The ordering this pins matters: user_can_view_collection() answers false
     * for a tombstone by design, so a controller that asked it first would 404
     * every old link instead of explaining what happened.
     *
     * @return void
     */
    public function test_a_tombstone_renders_for_everyone(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Deleted reading list');
        $resource = $this->make_resource('A collected resource');
        collection_manager::add_item((int) $collection->id, (int) $resource->id, (int) $owner->id);
        collection_manager::delete((int) $collection->id);

        $this->register_route();
        $this->setUser(null);
        $response = $this->get_page($collection->slug);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString(
            get_string('collectiontombstone', 'local_oerexchange'),
            $body
        );
        // The heading still says which collection this was; the body carries
        // no items, no byline and no description.
        $this->assertStringNotContainsString('A collected resource', $body);
    }

    /**
     * With collections switched off the page 404s before the row is even
     * loaded.
     *
     * @return void
     */
    public function test_the_page_404s_when_collections_are_switched_off(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Switched off');
        set_config('collectionsenabled', 0, 'local_oerexchange');

        $this->register_route();
        $this->assertSame(404, $this->get_page($collection->slug)->getStatusCode());
    }

    /**
     * A POST with no sesskey changes nothing, and says why on the page the
     * user was looking at rather than becoming a 500.
     *
     * @return void
     */
    public function test_a_post_without_a_sesskey_is_refused_and_changes_nothing(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $reader = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Starrable list');

        $this->register_route();
        $this->setUser($reader);
        $response = $this->post_action($collection->slug, ['action' => 'star']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertContains(get_string('error_sesskeyexpired', 'local_oerexchange'), $this->queued_notifications());
        $this->assertSame(
            0,
            star_manager::star_count((int) $collection->id, star_manager::ITEMTYPE_COLLECTION),
            'a sesskey-less POST must not have persisted the star'
        );
    }

    /**
     * A wrong sesskey is refused the same way a missing one is.
     *
     * require_sesskey() rather than confirm_sesskey() is what makes those two
     * cases land in the same branch.
     *
     * @return void
     */
    public function test_a_wrong_sesskey_is_refused_the_same_way(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $reader = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Starrable list');

        $this->register_route();
        $this->setUser($reader);
        $response = $this->post_action($collection->slug, ['action' => 'star', 'sesskey' => 'notthetoken']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertContains(get_string('error_sesskeyexpired', 'local_oerexchange'), $this->queued_notifications());
        $this->assertSame(0, star_manager::star_count((int) $collection->id, star_manager::ITEMTYPE_COLLECTION));
    }

    /**
     * With a valid sesskey the star is persisted and the browser is
     * redirected back to the collection.
     *
     * @return void
     */
    public function test_a_valid_post_stars_the_collection_and_redirects(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $reader = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Starrable list');

        $this->register_route();
        $this->setUser($reader);
        $response = $this->post_action($collection->slug, [
            'action' => 'star',
            'sesskey' => sesskey(),
        ]);

        $this->assertContains($response->getStatusCode(), [302, 303]);
        $this->assertTrue(star_manager::is_starred(
            (int) $collection->id,
            (int) $reader->id,
            star_manager::ITEMTYPE_COLLECTION
        ));
        // The wanted state is derived from the stored one, so a replayed form
        // unstars rather than starring twice.
        $this->post_action($collection->slug, ['action' => 'star', 'sesskey' => sesskey()]);
        $this->assertSame(0, star_manager::star_count((int) $collection->id, star_manager::ITEMTYPE_COLLECTION));
    }

    /**
     * A stranger cannot add to somebody else's closed collection.
     *
     * access_denied_exception becomes a real 403 through
     * lib/classes/router/error_handler.php, which is why the refusal is
     * asserted on the status code.
     *
     * @return void
     */
    public function test_a_stranger_may_not_add_an_item(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Closed list');
        $resource = $this->make_resource('Someone elses resource');

        $this->register_route();
        $this->setUser($stranger);
        $response = $this->post_action($collection->slug, [
            'action' => 'additem',
            'resourceid' => (int) $resource->id,
            'sesskey' => sesskey(),
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, $DB->count_records(collection_manager::ITEM_TABLE, [
            'collectionid' => $collection->id,
        ]));
    }

    /**
     * Open curation lets any logged-in user add — and lets them remove what
     * THEY added and nothing else.
     *
     * The refusal is the point: "anyone may add" must not become "anyone may
     * empty".
     *
     * @return void
     */
    public function test_an_open_contributor_may_remove_only_their_own_item(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $contributor = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Open list', ['opencuration' => 1]);
        $ownersitem = $this->make_resource('Chosen by the owner');
        $theirs = $this->make_resource('Chosen by a contributor');
        collection_manager::add_item((int) $collection->id, (int) $ownersitem->id, (int) $owner->id);

        $this->register_route();
        $this->setUser($contributor);

        // Adding is allowed.
        $added = $this->post_action($collection->slug, [
            'action' => 'additem',
            'resourceid' => (int) $theirs->id,
            'sesskey' => sesskey(),
        ]);
        $this->assertContains($added->getStatusCode(), [302, 303]);
        $this->assertTrue($DB->record_exists(collection_manager::ITEM_TABLE, [
            'collectionid' => $collection->id,
            'resourceid' => $theirs->id,
            'addedby' => $contributor->id,
        ]));

        // Removing the owner's item is not.
        $refused = $this->post_action($collection->slug, [
            'action' => 'removeitem',
            'resourceid' => (int) $ownersitem->id,
            'sesskey' => sesskey(),
        ]);
        $this->assertSame(403, $refused->getStatusCode());
        $this->assertTrue($DB->record_exists(collection_manager::ITEM_TABLE, [
            'collectionid' => $collection->id,
            'resourceid' => $ownersitem->id,
        ]));

        // Removing their own is.
        $allowed = $this->post_action($collection->slug, [
            'action' => 'removeitem',
            'resourceid' => (int) $theirs->id,
            'sesskey' => sesskey(),
        ]);
        $this->assertContains($allowed->getStatusCode(), [302, 303]);
        $this->assertFalse($DB->record_exists(collection_manager::ITEM_TABLE, [
            'collectionid' => $collection->id,
            'resourceid' => $theirs->id,
        ]));
    }

    /**
     * Reordering and annotating are curation rights: an open contributor has
     * neither, even over the item they added themselves.
     *
     * @return void
     */
    public function test_an_open_contributor_may_not_reorder_or_annotate(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $contributor = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Open list', ['opencuration' => 1]);
        $first = $this->make_resource('First resource');
        $second = $this->make_resource('Second resource');
        collection_manager::add_item((int) $collection->id, (int) $first->id, (int) $owner->id);
        $theirsid = collection_manager::add_item((int) $collection->id, (int) $second->id, (int) $contributor->id);

        $this->register_route();
        $this->setUser($contributor);

        $moved = $this->post_action($collection->slug, [
            'action' => 'moveitem',
            'itemid' => $theirsid,
            'direction' => -1,
            'sesskey' => sesskey(),
        ]);
        $this->assertSame(403, $moved->getStatusCode());
        $this->assertSame(2, (int) $DB->get_field(collection_manager::ITEM_TABLE, 'sortorder', ['id' => $theirsid]));

        $annotated = $this->post_action($collection->slug, [
            'action' => 'setnote',
            'itemid' => $theirsid,
            'note' => 'My own reasoning',
            'sesskey' => sesskey(),
        ]);
        $this->assertSame(403, $annotated->getStatusCode());
        $this->assertNull($DB->get_field(collection_manager::ITEM_TABLE, 'note', ['id' => $theirsid]));
    }

    /**
     * A named curator may reorder and annotate, but may NOT manage the
     * collection: the edit link is not even offered to them.
     *
     * @return void
     */
    public function test_a_named_curator_may_reorder_but_not_manage(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $curator = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Co-curated list');
        collection_curator_manager::add((int) $collection->id, (int) $curator->id, (int) $owner->id);

        $first = $this->make_resource('First resource');
        $second = $this->make_resource('Second resource');
        collection_manager::add_item((int) $collection->id, (int) $first->id, (int) $owner->id);
        $secondid = collection_manager::add_item((int) $collection->id, (int) $second->id, (int) $owner->id);

        $this->register_route();
        $this->setUser($curator);

        $moved = $this->post_action($collection->slug, [
            'action' => 'moveitem',
            'itemid' => $secondid,
            'direction' => -1,
            'sesskey' => sesskey(),
        ]);
        $this->assertContains($moved->getStatusCode(), [302, 303]);
        $this->assertSame(1, (int) $DB->get_field(collection_manager::ITEM_TABLE, 'sortorder', ['id' => $secondid]));

        $page = (string) $this->get_page($collection->slug)->getBody();
        $this->assertStringNotContainsString('oerexchange-collection-editlink', $page);
    }

    /**
     * A posted item id belonging to ANOTHER collection cannot be reordered
     * from this page.
     *
     * This is the forged-id case: the caller is the owner of the collection
     * the slug resolved to, and genuinely may curate it, but the id they
     * posted is somebody else's. The item row is loaded keyed by this
     * collection as well as by the id, so nothing happens — and specifically,
     * the other collection's order is untouched.
     *
     * @return void
     */
    public function test_a_posted_item_id_from_another_collection_does_nothing(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $victim = $this->getDataGenerator()->create_user();

        $mine = $this->make_public_collection((int) $owner->id, 'My own list');
        $theirs = $this->make_public_collection((int) $victim->id, 'Somebody elses list');

        $first = $this->make_resource('Their first resource');
        $second = $this->make_resource('Their second resource');
        $theirfirstid = collection_manager::add_item((int) $theirs->id, (int) $first->id, (int) $victim->id);
        $theirsecondid = collection_manager::add_item((int) $theirs->id, (int) $second->id, (int) $victim->id);

        $this->register_route();
        $this->setUser($owner);

        $response = $this->post_action($mine->slug, [
            'action' => 'moveitem',
            'itemid' => $theirsecondid,
            'direction' => -1,
            'sesskey' => sesskey(),
        ]);

        // The request is answered (the caller may curate the collection they
        // addressed), but the foreign item is not touched.
        $this->assertContains($response->getStatusCode(), [302, 303]);
        $this->assertSame(
            1,
            (int) $DB->get_field(collection_manager::ITEM_TABLE, 'sortorder', ['id' => $theirfirstid])
        );
        $this->assertSame(
            2,
            (int) $DB->get_field(collection_manager::ITEM_TABLE, 'sortorder', ['id' => $theirsecondid])
        );
    }

    /**
     * A posted resource id that is in another collection cannot be removed
     * from this one.
     *
     * The remove is keyed by (this collection, posted resource id), so the
     * other collection keeps its item and this page reports nothing to
     * remove.
     *
     * @return void
     */
    public function test_a_posted_resource_id_cannot_remove_another_collections_item(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $victim = $this->getDataGenerator()->create_user();

        $mine = $this->make_public_collection((int) $owner->id, 'My own list');
        $theirs = $this->make_public_collection((int) $victim->id, 'Somebody elses list');
        $resource = $this->make_resource('Their resource');
        collection_manager::add_item((int) $theirs->id, (int) $resource->id, (int) $victim->id);

        $this->register_route();
        $this->setUser($owner);

        $response = $this->post_action($mine->slug, [
            'action' => 'removeitem',
            'resourceid' => (int) $resource->id,
            'sesskey' => sesskey(),
        ]);

        // The caller may curate the collection they addressed, so the request
        // is answered with the ordinary redirect — but there was nothing in
        // THIS collection to remove, and the other one keeps its item.
        $this->assertContains($response->getStatusCode(), [302, 303]);
        $this->assertTrue($DB->record_exists(collection_manager::ITEM_TABLE, [
            'collectionid' => $theirs->id,
            'resourceid' => $resource->id,
        ]));
        $this->assertSame(1, $DB->count_records(collection_manager::ITEM_TABLE, [
            'resourceid' => $resource->id,
        ]));
    }

    /**
     * A guest session cannot act, even though isloggedin() is true for it.
     *
     * @return void
     */
    public function test_a_guest_may_view_but_not_act(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Public list');

        $this->register_route();
        $this->setGuestUser();

        $this->assertSame(200, $this->get_page($collection->slug)->getStatusCode());

        $response = $this->post_action($collection->slug, ['action' => 'star', 'sesskey' => sesskey()]);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, star_manager::star_count((int) $collection->id, star_manager::ITEMTYPE_COLLECTION));
    }

    /**
     * Reporting a collection writes a report row with collectionid set and
     * resourceid null — the polymorphic invariant the moderation queue reads.
     *
     * @return void
     */
    public function test_reporting_a_collection_writes_a_collection_report(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $reporter = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Reportable list');

        $this->register_route();
        $this->setUser($reporter);
        $response = $this->post_action($collection->slug, [
            'action' => 'report',
            'reporttype' => 'copyright',
            'reportdetails' => 'This list points at pirated material.',
            'sesskey' => sesskey(),
        ]);

        $this->assertContains($response->getStatusCode(), [302, 303]);
        $report = $DB->get_record('local_oerexchange_reports', ['collectionid' => $collection->id]);
        $this->assertNotFalse($report);
        $this->assertNull($report->resourceid);
        $this->assertSame('copyright', $report->type);
        $this->assertSame('open', $report->status);
        $this->assertSame((int) $reporter->id, (int) $report->userid);
    }

    /**
     * A report reason the form never offered is refused, and no row is
     * written.
     *
     * An unrecognised value would later break moderate.php, which feeds it to
     * get_string('reporttype_' . $type) with no fallback — one bad row would
     * cost admins the whole queue. The refusal is a plain moodle_exception,
     * which Slim's error handler renders as a server error response rather
     * than letting it reach PHPUnit (see the class docblock), so what is
     * asserted is that the request failed and stored nothing.
     *
     * @return void
     */
    public function test_an_unoffered_report_reason_is_refused(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $reporter = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Reportable list');

        $this->register_route();
        $this->setUser($reporter);
        $response = $this->post_action($collection->slug, [
            'action' => 'report',
            'reporttype' => 'defamation',
            'reportdetails' => 'A reason the form does not offer.',
            'sesskey' => sesskey(),
        ]);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertSame(0, $DB->count_records('local_oerexchange_reports', [
            'collectionid' => $collection->id,
        ]));
    }

    /**
     * A takedown needs the moderation capability, not ownership; a restore
     * puts the collection back and stops naming the moderator.
     *
     * @return void
     */
    public function test_only_a_moderator_can_take_a_collection_down(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Takedown candidate');

        $this->register_route();

        // The owner holds every other right over their own collection and
        // still cannot hold it.
        $this->setUser($owner);
        $refused = $this->post_action($collection->slug, ['action' => 'modhide', 'sesskey' => sesskey()]);
        $this->assertGreaterThanOrEqual(400, $refused->getStatusCode());
        $this->assertSame(0, (int) $DB->get_field(collection_manager::TABLE, 'modhidden', ['id' => $collection->id]));

        $this->setAdminUser();
        $held = $this->post_action($collection->slug, ['action' => 'modhide', 'sesskey' => sesskey()]);
        $this->assertContains($held->getStatusCode(), [302, 303]);
        $after = $DB->get_record(collection_manager::TABLE, ['id' => $collection->id], '*', MUST_EXIST);
        $this->assertSame(1, (int) $after->modhidden);
        $this->assertNotEquals(0, (int) $after->modhiddentime);

        $restored = $this->post_action($collection->slug, ['action' => 'modrestore', 'sesskey' => sesskey()]);
        $this->assertContains($restored->getStatusCode(), [302, 303]);
        $back = $DB->get_record(collection_manager::TABLE, ['id' => $collection->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $back->modhidden);
        $this->assertSame(0, (int) $back->modhiddenby);
        $this->assertSame(0, (int) $back->modhiddentime);
        // A takedown is not an unpublish: the owner's own visibility is
        // untouched by either transition.
        $this->assertSame(collection_manager::VISIBILITY_PUBLIC, $back->visibility);
    }

    /**
     * A POST with no recognised action renders the page and does nothing.
     *
     * It must not reach require_sesskey() (nothing is being changed) and must
     * not become a 500.
     *
     * @return void
     */
    public function test_a_post_with_no_known_action_just_renders_the_page(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Public list');

        $this->register_route();
        $this->setUser($owner);
        $response = $this->post_action($collection->slug, ['action' => 'explode']);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Public list', $body);
        $this->assertStringNotContainsString(
            get_string('error_sesskeyexpired', 'local_oerexchange'),
            $body
        );
    }

    /**
     * The owner learns that an item's resource has since been pulled, but is
     * not told WHICH resource unless the resource page itself would show it
     * to them; everybody else sees the collection as the public sees it.
     *
     * The collection's owner is usually not the resource's author, and a
     * resource that is hidden, pending or taken down is withheld from them on
     * its own page, so naming it here would be a side channel around that.
     * Its own author, who may see it there, is shown its title here too.
     *
     * @return void
     */
    public function test_only_curators_see_an_unavailable_item(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $collection = $this->make_public_collection((int) $owner->id, 'Mixed list');
        $live = $this->make_resource('Still published');
        $pulled = $this->make_resource('Pulled since collecting');
        collection_manager::add_item((int) $collection->id, (int) $live->id, (int) $owner->id);
        collection_manager::add_item((int) $collection->id, (int) $pulled->id, (int) $owner->id);
        $DB->set_field('local_oerexchange_resources', 'status', 'modhidden', ['id' => $pulled->id]);

        $this->register_route();

        $this->setUser($stranger);
        $publicbody = (string) $this->get_page($collection->slug)->getBody();
        $this->assertStringContainsString('Still published', $publicbody);
        $this->assertStringNotContainsString('Pulled since collecting', $publicbody);

        $this->setUser($owner);
        $ownerbody = (string) $this->get_page($collection->slug)->getBody();
        $this->assertStringNotContainsString('Pulled since collecting', $ownerbody);
        $this->assertStringContainsString(get_string('collectionitemunavailable', 'local_oerexchange'), $ownerbody);

        // The resource's own author, curating their own collection, may see
        // their hidden resource on its page, so they see its title here.
        $author = $this->getDataGenerator()->create_user();
        $mine = $this->make_public_collection((int) $author->id, 'Author list');
        $ownpulled = $this->make_resource('My own pulled resource');
        collection_manager::add_item((int) $mine->id, (int) $ownpulled->id, (int) $author->id);
        $DB->set_field('local_oerexchange_resources', 'status', 'hidden', ['id' => $ownpulled->id]);
        $DB->set_field('local_oerexchange_resources', 'creatorid', (int) $author->id, ['id' => $ownpulled->id]);
        $this->setUser($author);
        $authorbody = (string) $this->get_page($mine->slug)->getBody();
        $this->assertStringContainsString('My own pulled resource', $authorbody);
    }
}
