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

namespace local_oerexchange\local\allowlist;

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/oerexchange/tests/fixtures/fake_http_fetcher.php');
require_once($CFG->dirroot . '/local/oerexchange/tests/fixtures/fake_component_locator.php');
require_once($CFG->dirroot . '/local/oerexchange/tests/fixtures/allowlist_zip_builder.php');

/**
 * Tests for writing a confirmed plan onto the allowlist.
 *
 * The row shape is unchanged from what the hand-filled form produced — every
 * existing consumer reads these rows — so these tests are mostly about the
 * things the form never had to get right: one row per supported branch, a
 * second add refreshing rather than duplicating, dependencies recording what
 * pulled them in, and the bake flag cascading.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(ingestor::class)]
final class ingestor_test extends \advanced_testcase {
    /** @var allowlist_zip_builder */
    private allowlist_zip_builder $builder;

    /** @var fake_http_fetcher */
    private fake_http_fetcher $fetcher;

    /** @var fake_component_locator */
    private fake_component_locator $locator;

    protected function setUp(): void {
        parent::setUp();

        $this->builder = new allowlist_zip_builder();
        $this->fetcher = new fake_http_fetcher();
        $this->locator = new fake_component_locator();
    }

    public function test_one_plugin_becomes_one_row_per_supported_branch(): void {
        // The headline saving: this used to be two passes through a
        // five-field form with the ZIP uploaded twice.
        $this->resetAfterTest();
        global $DB;

        $result = (new ingestor())->commit($this->plan('mod_thing', []));

        $rows = $DB->get_records('local_oerexchange_pluginallowlist', [], 'moodlebranch');
        $this->assertCount(2, $rows);
        $this->assertSame(['5.0', '5.2'], array_values(array_map(
            static fn ($r) => $r->moodlebranch,
            $rows
        )));
        $this->assertSame(2, $result->added);
        $this->assertSame(0, $result->refreshed);

        foreach ($rows as $row) {
            $this->assertSame('mod', $row->plugintype);
            $this->assertSame('thing', $row->pluginname);
            $this->assertSame('mod_thing', $row->component);
            $this->assertSame('active', $row->status);
            $this->assertSame('1.0.0', $row->pluginrelease);
            $this->assertSame('2026070100', $row->pluginversion);
            $this->assertNull($row->parentid);
        }
    }

    public function test_the_mirrored_zip_is_stored_and_matches_the_recorded_hash(): void {
        // The sha256 is what the sandbox build machine verifies before injecting
        // a plugin, so a stored hash that does not match the stored file
        // fails the bake with a checksum mismatch.
        $this->resetAfterTest();
        global $DB;

        (new ingestor())->commit($this->plan('mod_thing', []));

        $row = $DB->get_record('local_oerexchange_pluginallowlist', ['moodlebranch' => '5.2']);
        $this->assertSame((int) $row->id, (int) $row->itemid);

        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_oerexchange',
            'allowlist',
            $row->itemid,
            'id',
            false
        );
        $this->assertCount(1, $files);

        $file = reset($files);
        $this->assertSame('thing.zip', $file->get_filename());
        $this->assertSame($row->sha256, hash('sha256', $file->get_content()));
    }

    public function test_adding_the_same_plugin_again_refreshes_rather_than_duplicates(): void {
        // Before the unique index this silently produced a second set of
        // rows, and sandbox_launch.php's get_record() lookup would then throw
        // on the ambiguity.
        $this->resetAfterTest();
        global $DB;

        $ingestor = new ingestor();
        $ingestor->commit($this->plan('mod_thing', []));
        $result = $ingestor->commit($this->plan('mod_thing', [], version: 2026080500));

        $rows = $DB->get_records('local_oerexchange_pluginallowlist');
        $this->assertCount(2, $rows);
        $this->assertSame(0, $result->added);
        $this->assertSame(2, $result->refreshed);

        // Refreshed in place, with the newer package's metadata.
        foreach ($rows as $row) {
            $this->assertSame('2026080500', $row->pluginversion);
        }
    }

    public function test_a_refresh_replaces_the_stored_zip_rather_than_adding_a_second(): void {
        $this->resetAfterTest();
        global $DB;

        $ingestor = new ingestor();
        $ingestor->commit($this->plan('mod_thing', []));
        $ingestor->commit($this->plan('mod_thing', [], version: 2026080500));

        $row = $DB->get_record('local_oerexchange_pluginallowlist', ['moodlebranch' => '5.2']);
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_oerexchange',
            'allowlist',
            $row->itemid,
            'id',
            false
        );

        // Serving code picks the first file it finds, so a leftover
        // would mean serving the previous release.
        $this->assertCount(1, $files);
        $this->assertSame($row->sha256, hash('sha256', reset($files)->get_content()));
    }

    public function test_a_dependency_is_written_active_and_records_its_parent(): void {
        $this->resetAfterTest();
        global $DB;

        $this->publish('local_helper', []);

        (new ingestor())->commit($this->plan('mod_thing', ['local_helper' => 'any']));

        $this->assertCount(4, $DB->get_records('local_oerexchange_pluginallowlist'));

        $parent = $DB->get_record('local_oerexchange_pluginallowlist', [
            'component' => 'mod_thing', 'moodlebranch' => '5.2',
        ]);
        $child = $DB->get_record('local_oerexchange_pluginallowlist', [
            'component' => 'local_helper', 'moodlebranch' => '5.2',
        ]);

        $this->assertSame('active', $child->status);
        $this->assertSame((int) $parent->id, (int) $child->parentid);

        // Parentage is per branch, so the 5.0 child points at the 5.0 parent,
        // not at whichever row happened to be written first.
        $parent50 = $DB->get_record('local_oerexchange_pluginallowlist', [
            'component' => 'mod_thing', 'moodlebranch' => '5.0',
        ]);
        $child50 = $DB->get_record('local_oerexchange_pluginallowlist', [
            'component' => 'local_helper', 'moodlebranch' => '5.0',
        ]);
        $this->assertSame((int) $parent50->id, (int) $child50->parentid);
    }

    public function test_the_bake_flag_cascades_to_dependencies(): void {
        // A baked plugin whose dependency is only installed at trial boot is
        // a plugin that does not work in the bundle it was baked into.
        $this->resetAfterTest();
        global $DB;

        $this->publish('local_helper', []);

        (new ingestor())->commit($this->plan('mod_thing', ['local_helper' => 'any']), true);

        foreach ($DB->get_records('local_oerexchange_pluginallowlist') as $row) {
            $this->assertSame('1', $row->bake, $row->component . ' ' . $row->moodlebranch);
        }
    }

    public function test_a_caller_without_the_sandbox_capability_cannot_clear_bake(): void {
        // The bake checkbox renders disabled for such a user, so a refresh by
        // them must leave the flag alone rather than silently unbaking a
        // plugin from the bundle.
        $this->resetAfterTest();
        global $DB;

        $ingestor = new ingestor();
        $ingestor->commit($this->plan('mod_thing', []), true);
        $ingestor->commit($this->plan('mod_thing', [], version: 2026080500), null);

        foreach ($DB->get_records('local_oerexchange_pluginallowlist') as $row) {
            $this->assertSame('1', $row->bake);
        }
    }

    public function test_entries_that_cannot_be_written_write_nothing(): void {
        // A core dependency and an unpublished one must not leave stray rows.
        $this->resetAfterTest();
        global $DB;

        $plan = $this->plan('mod_thing', ['mod_quiz' => 'any', 'local_private' => 'any']);
        (new ingestor())->commit($plan);

        $components = $DB->get_fieldset_sql(
            'SELECT DISTINCT component FROM {local_oerexchange_pluginallowlist} ORDER BY component'
        );
        $this->assertSame(['mod_thing'], $components);
    }

    public function test_an_overridden_branch_is_marked_on_the_row(): void {
        // Months later, a row listing a plugin for a branch its own
        // version.php disowns looks like a bug unless the row says why.
        $this->resetAfterTest();
        global $DB;

        (new ingestor())->commit($this->plan('mod_thing', [], supported: '[400, 405]', ignoredeclared: true));

        $rows = $DB->get_records('local_oerexchange_pluginallowlist', [], 'moodlebranch');
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(ingestor::NOTE_OVERRIDDEN, $row->notes, $row->moodlebranch);
        }
    }

    public function test_a_branch_the_plugin_does_claim_is_not_marked_as_overridden(): void {
        // Only the branches actually added against the plugin's word carry
        // the marker, even when the override was switched on.
        $this->resetAfterTest();
        global $DB;

        (new ingestor())->commit($this->plan('mod_thing', [], supported: '[404, 500]', ignoredeclared: true));

        $this->assertSame('', $DB->get_field(
            'local_oerexchange_pluginallowlist',
            'notes',
            ['component' => 'mod_thing', 'moodlebranch' => '5.0']
        ));
        $this->assertSame(ingestor::NOTE_OVERRIDDEN, $DB->get_field(
            'local_oerexchange_pluginallowlist',
            'notes',
            ['component' => 'mod_thing', 'moodlebranch' => '5.2']
        ));
    }

    public function test_refreshing_without_the_override_clears_the_marker(): void {
        // The plugin bumped its supported range; the entry is no longer an
        // override and must stop claiming to be one.
        $this->resetAfterTest();
        global $DB;

        $ingestor = new ingestor();
        $ingestor->commit($this->plan('mod_thing', [], supported: '[400, 405]', ignoredeclared: true));
        $ingestor->commit($this->plan('mod_thing', [], supported: '[500, 502]'));

        foreach ($DB->get_records('local_oerexchange_pluginallowlist') as $row) {
            $this->assertSame('', $row->notes, $row->moodlebranch);
        }
    }

    public function test_preview_labels_a_plugin_already_on_the_list_as_a_refresh(): void {
        $this->resetAfterTest();

        $ingestor = new ingestor();
        $ingestor->commit($this->plan('mod_thing', []));

        $previewed = $ingestor->preview($this->plan('mod_thing', []));

        $this->assertSame(entry_plan::REFRESH, $previewed->primary()->action);
    }

    public function test_preview_leaves_a_new_plugin_as_an_add(): void {
        $this->resetAfterTest();

        $previewed = (new ingestor())->preview($this->plan('mod_thing', []));

        $this->assertSame(entry_plan::ADD, $previewed->primary()->action);
    }

    public function test_deleting_an_entry_removes_the_row_and_its_mirrored_zip(): void {
        // Deletion, unlike disabling, has to reclaim the stored ZIP — that is
        // the only part of an entry with any real size to it.
        $this->resetAfterTest();
        global $DB;

        (new ingestor())->commit($this->plan('mod_thing', []));
        $row = $DB->get_record('local_oerexchange_pluginallowlist', ['moodlebranch' => '5.2']);

        $fs = get_file_storage();
        $context = \context_system::instance();
        $this->assertCount(1, $fs->get_area_files(
            $context->id,
            'local_oerexchange',
            'allowlist',
            $row->itemid,
            'id',
            false
        ));

        $removed = (new ingestor())->delete((int) $row->id);

        $this->assertSame('mod_thing', $removed);
        $this->assertFalse($DB->record_exists('local_oerexchange_pluginallowlist', ['id' => $row->id]));
        $this->assertSame([], $fs->get_area_files(
            $context->id,
            'local_oerexchange',
            'allowlist',
            $row->itemid,
            'id',
            false
        ));

        // Only the one branch goes; the other is a separate entry.
        $this->assertTrue($DB->record_exists('local_oerexchange_pluginallowlist', ['moodlebranch' => '5.0']));
    }

    public function test_deleting_a_parent_keeps_its_dependencies_but_unlinks_them(): void {
        // A dependency can be shared, so deleting plugins the admin did not
        // name would be a worse surprise than leaving one behind. What must
        // not survive is the dangling parent reference.
        $this->resetAfterTest();
        global $DB;

        $this->publish('local_helper', []);
        (new ingestor())->commit($this->plan('mod_thing', ['local_helper' => 'any']));

        $parent = $DB->get_record(
            'local_oerexchange_pluginallowlist',
            ['component' => 'mod_thing', 'moodlebranch' => '5.2']
        );
        $child = $DB->get_record(
            'local_oerexchange_pluginallowlist',
            ['component' => 'local_helper', 'moodlebranch' => '5.2']
        );
        $this->assertSame((int) $parent->id, (int) $child->parentid);

        $ingestor = new ingestor();
        $this->assertSame(1, $ingestor->count_dependents((int) $parent->id));
        $ingestor->delete((int) $parent->id);

        $child = $DB->get_record('local_oerexchange_pluginallowlist', ['id' => $child->id]);
        $this->assertNotFalse($child, 'the dependency must survive');
        $this->assertNull($child->parentid, 'its parent link must not dangle');
        $this->assertSame('active', $child->status);
    }

    public function test_deleting_something_already_gone_is_harmless(): void {
        // Two admins with the page open, both pressing Delete.
        $this->resetAfterTest();

        $this->assertNull((new ingestor())->delete(999999));
    }

    public function test_a_deleted_plugin_can_simply_be_added_again(): void {
        // Deletion must not leave anything behind that blocks a re-add — the
        // unique index would reject a leftover row.
        $this->resetAfterTest();
        global $DB;

        $ingestor = new ingestor();
        $ingestor->commit($this->plan('mod_thing', []));
        foreach ($DB->get_records('local_oerexchange_pluginallowlist') as $row) {
            $ingestor->delete((int) $row->id);
        }
        $this->assertSame(0, $DB->count_records('local_oerexchange_pluginallowlist'));

        $result = $ingestor->commit($this->plan('mod_thing', []));

        $this->assertSame(2, $result->added);
        $this->assertSame(0, $result->refreshed);
    }

    /**
     * Build a real plan, the way the admin page and CLI both do.
     *
     * @param string $component
     * @param array $dependencies
     * @param int $version
     * @param string|null $supported array literal for $plugin->supported
     * @param bool $ignoredeclared disregard the declared supported range
     * @return ingest_plan
     */
    private function plan(
        string $component,
        array $dependencies,
        int $version = 2026070100,
        ?string $supported = null,
        bool $ignoredeclared = false,
    ): ingest_plan {
        $zip = $this->builder->build($component, $dependencies, $supported, $version);
        $meta = (new zip_inspector())->inspect(
            $zip,
            make_request_directory(),
            'https://example.org/' . $component . '.zip'
        );

        $walker = new dependency_walker(
            new source_resolver($this->fetcher),
            new zip_inspector(),
            $this->locator,
            ['5.0', '5.2'],
            $ignoredeclared,
        );

        return $walker->walk($meta, make_request_directory());
    }

    /**
     * Make a component downloadable by the walker.
     *
     * @param string $component
     * @param array $dependencies
     */
    private function publish(string $component, array $dependencies): void {
        $url = 'https://example.org/' . $component . '.zip';
        $this->locator->urls[$component] = $url;
        $this->fetcher->zips[$url] = file_get_contents($this->builder->build($component, $dependencies));
    }
}
