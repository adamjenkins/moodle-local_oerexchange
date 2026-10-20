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
 * Whole-collection download zips.
 *
 * The property everything else rests on: a stored zip is only ever "ready"
 * while its signature matches what the collection holds now, so an edit,
 * a new version or a hidden resource can never be served from an old zip.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(collection_zip::class)]
#[CoversClass(\local_oerexchange\task\build_collection_zip::class)]
final class collection_zip_test extends \advanced_testcase {
    /**
     * This plugin's data generator.
     *
     * @return \local_oerexchange_generator
     */
    protected function generator(): \local_oerexchange_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_oerexchange');
    }

    /**
     * A public collection of two resources, each with a stored version.
     *
     * @return \stdClass the collection, re-read
     */
    protected function make_collection(): \stdClass {
        $owner = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Author']);
        $collection = $this->generator()->create_collection([
            'userid' => (int) $owner->id,
            'title' => 'Tide pools',
            'visibility' => collection_manager::VISIBILITY_PUBLIC,
        ]);
        foreach (['Rock pool survey', 'Shoreline quiz'] as $index => $title) {
            $resource = $this->generator()->create_resource([
                'title' => $title,
                'creatorid' => (int) $owner->id,
                'licenseshortname' => 'cc-4.0',
            ]);
            $this->generator()->create_version((int) $resource->id, str_repeat('x', 100 + $index), [
                'filename' => 'unit' . $index . '.mbz',
            ]);
            $itemid = collection_manager::add_item((int) $collection->id, (int) $resource->id, (int) $owner->id);
            if ($index === 0) {
                collection_manager::set_item_note($itemid, 'Do this one on site.');
            }
        }

        return collection_manager::get((int) $collection->id);
    }

    /**
     * The names inside a stored zip.
     *
     * @param \stored_file $file
     * @return string[]
     */
    protected function zip_names(\stored_file $file): array {
        $names = array_map(fn($f) => $f->pathname, $file->list_files(get_file_packer('application/zip')));
        sort($names);
        return $names;
    }

    /**
     * A collection with downloadable items is pending until built, then
     * ready, and the zip holds both files, in order, plus the manifest.
     *
     * @return void
     */
    public function test_build_makes_a_ready_zip_with_the_files_and_a_manifest(): void {
        $this->resetAfterTest();
        $collection = $this->make_collection();

        $this->assertSame(collection_zip::STATE_PENDING, collection_zip::state($collection)->state);
        $this->assertSame('built', collection_zip::build((int) $collection->id));

        $state = collection_zip::state($collection);
        $this->assertSame(collection_zip::STATE_READY, $state->state);
        $this->assertSame(201, $state->bytes);
        $this->assertSame(
            ['01 - Rock pool survey.mbz', '02 - Shoreline quiz.mbz', 'collection.txt'],
            $this->zip_names($state->file)
        );

        $manifest = collection_zip::manifest($collection, collection_zip::entries((int) $collection->id));
        $this->assertStringContainsString('Tide pools', $manifest);
        $this->assertStringContainsString('01. Rock pool survey', $manifest);
        $this->assertStringContainsString('Ana Author', $manifest);
        $this->assertStringContainsString('cc-4.0', $manifest);
        $this->assertStringContainsString('Do this one on site.', $manifest);
    }

    /**
     * Any change to what the zip holds makes it stale at once; building
     * again replaces it, and only one zip is ever kept.
     *
     * @return void
     */
    public function test_an_edit_makes_the_zip_stale_and_a_rebuild_replaces_it(): void {
        $this->resetAfterTest();
        $collection = $this->make_collection();
        collection_zip::build((int) $collection->id);

        $items = collection_manager::get_items((int) $collection->id);
        collection_manager::set_item_note((int) array_key_last($items), 'Now with a note.');
        $this->assertSame(collection_zip::STATE_PENDING, collection_zip::state($collection)->state);

        collection_zip::build((int) $collection->id);
        $this->assertSame(collection_zip::STATE_READY, collection_zip::state($collection)->state);
        $stored = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_oerexchange',
            collection_zip::FILEAREA,
            (int) $collection->id,
            'id',
            false
        );
        $this->assertCount(1, $stored, 'the outdated zip is removed once the new one exists');
    }

    /**
     * A hidden resource leaves the zip: the old one is stale, not served.
     *
     * @return void
     */
    public function test_hiding_a_resource_makes_the_zip_stale(): void {
        global $DB;
        $this->resetAfterTest();
        $collection = $this->make_collection();
        collection_zip::build((int) $collection->id);

        $first = collection_zip::entries((int) $collection->id)[0];
        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $first->resource->id]);
        resource_manager::set_hidden($resource, true);

        $this->assertSame(collection_zip::STATE_PENDING, collection_zip::state($collection)->state);
        collection_zip::build((int) $collection->id);
        $this->assertSame(
            ['01 - Shoreline quiz.mbz', 'collection.txt'],
            $this->zip_names(collection_zip::state($collection)->file)
        );
    }

    /**
     * Over the size limit there is no zip; at 0 the feature is off.
     *
     * @return void
     */
    public function test_the_size_limit_and_the_off_switch(): void {
        $this->resetAfterTest();
        $collection = $this->make_collection();

        set_config('collectionzipmaxmb', 0, 'local_oerexchange');
        $this->assertSame(collection_zip::STATE_DISABLED, collection_zip::state($collection)->state);

        set_config('collectionzipmaxmb', 1, 'local_oerexchange');
        $big = $this->generator()->create_resource(['title' => 'Huge course']);
        $this->generator()->create_version((int) $big->id, 'x', ['filesize' => 2 * 1024 * 1024]);
        collection_manager::add_item((int) $collection->id, (int) $big->id, (int) $collection->userid);

        $this->assertSame(collection_zip::STATE_TOOLARGE, collection_zip::state($collection)->state);
        $this->assertSame(collection_zip::STATE_TOOLARGE, collection_zip::build((int) $collection->id));
    }

    /**
     * A draft collection has no zip, and an empty one has nothing to zip.
     *
     * @return void
     */
    public function test_drafts_and_empty_collections_have_no_zip(): void {
        $this->resetAfterTest();
        $collection = $this->make_collection();
        collection_manager::set_visibility((int) $collection->id, collection_manager::VISIBILITY_DRAFT);
        $draft = collection_manager::get((int) $collection->id);
        $this->assertSame(collection_zip::STATE_UNAVAILABLE, collection_zip::state($draft)->state);

        $empty = $this->generator()->create_collection(['visibility' => collection_manager::VISIBILITY_PUBLIC,
            'userid' => (int) $collection->userid]);
        $this->assertSame(collection_zip::STATE_EMPTY, collection_zip::state($empty)->state);
    }

    /**
     * Edits queue ONE build, scheduled a little later, however many there are.
     *
     * @return void
     */
    public function test_edits_queue_one_debounced_build(): void {
        $this->resetAfterTest();
        $collection = $this->make_collection();

        $tasks = \core\task\manager::get_adhoc_tasks(\local_oerexchange\task\build_collection_zip::class);
        $this->assertCount(1, $tasks, 'two item adds and a note made one queued build');
        $task = reset($tasks);
        $this->assertSame((int) $collection->id, (int) $task->get_custom_data()->collectionid);
        $this->assertGreaterThan(time(), $task->get_next_run_time());
    }

    /**
     * On a busy site the task puts the build off, and builds once the
     * longest deferral has passed.
     *
     * @return void
     */
    public function test_a_busy_site_defers_the_build_but_not_for_ever(): void {
        global $DB;
        $this->resetAfterTest();
        $collection = $this->make_collection();
        set_config('collectionzipbusyusers', 2, 'local_oerexchange');
        foreach ([1, 2] as $n) {
            $user = $this->getDataGenerator()->create_user();
            $DB->insert_record('sessions', (object) [
                'state' => 0, 'sid' => 'busytest' . $n, 'userid' => (int) $user->id, 'sessdata' => null,
                'timecreated' => time(), 'timemodified' => time(), 'firstip' => '127.0.0.1', 'lastip' => '127.0.0.1',
            ]);
        }
        $this->assertTrue(collection_zip::is_busy());

        $task = new \local_oerexchange\task\build_collection_zip();
        $task->set_custom_data(['collectionid' => (int) $collection->id]);
        $this->expectOutputRegex('/site busy/');
        $task->execute();
        $this->assertSame(collection_zip::STATE_PENDING, collection_zip::state($collection)->state);

        $deferred = array_filter(
            \core\task\manager::get_adhoc_tasks(\local_oerexchange\task\build_collection_zip::class),
            fn($t) => !empty($t->get_custom_data()->deferredsince)
        );
        $this->assertCount(1, $deferred);

        // Past the longest deferral, it builds even though the site is busy.
        $late = new \local_oerexchange\task\build_collection_zip();
        $late->set_custom_data([
            'collectionid' => (int) $collection->id,
            'deferredsince' => time() - collection_zip::MAX_DEFER - 1,
        ]);
        $late->execute();
        $this->assertSame(collection_zip::STATE_READY, collection_zip::state($collection)->state);
    }

    /**
     * Deleting a collection deletes its zip.
     *
     * @return void
     */
    public function test_deleting_the_collection_deletes_its_zip(): void {
        $this->resetAfterTest();
        $collection = $this->make_collection();
        collection_zip::build((int) $collection->id);

        collection_manager::delete((int) $collection->id);

        $this->assertSame([], get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_oerexchange',
            collection_zip::FILEAREA,
            (int) $collection->id,
            'id',
            false
        ));
    }
}
