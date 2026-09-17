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
 * Tests for editing which branches an allowlisted plugin is offered for.
 *
 * The two operations deliberately take their package from different places —
 * add_branch() reuses the release already mirrored, rollover_plan()
 * re-resolves the current one for the new branch — so each is pinned down
 * here, along with the rule that a branch the plugin disowns is still listed,
 * marked as an override.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(branch_editor::class)]
final class branch_editor_test extends \advanced_testcase {
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

    public function test_add_branch_copies_the_release_already_mirrored(): void {
        // The point of the feature: listing a plugin for another branch must
        // not silently re-pin it to whatever is current today.
        $this->resetAfterTest();
        global $DB;

        $this->listed('mod_thing', '[502, 502]');

        $editor = new branch_editor($this->locator, new source_resolver($this->fetcher), new zip_inspector());
        $this->assertSame(branch_editor::ADDED, $editor->add_branch('mod_thing', '5.0'));

        $added = $DB->get_record('local_oerexchange_pluginallowlist', [
            'component' => 'mod_thing',
            'moodlebranch' => '5.0',
        ]);
        $source = $DB->get_record('local_oerexchange_pluginallowlist', [
            'component' => 'mod_thing',
            'moodlebranch' => '5.2',
        ]);

        $this->assertSame($source->sha256, $added->sha256);
        $this->assertSame($source->pluginrelease, $added->pluginrelease);
        $this->assertSame($source->pluginversion, $added->pluginversion);
        $this->assertSame($source->sourceurl, $added->sourceurl);
        $this->assertSame($source->bake, $added->bake);
        // Never looked anything up: the locator is the network in this class.
        $this->assertSame([], $this->locator->requested);

        // Its own mirrored copy, keyed to its own row, because delete() of
        // either branch removes that row's file area.
        $this->assertSame((int) $added->id, (int) $added->itemid);
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_oerexchange',
            'allowlist',
            $added->itemid,
            'id',
            false
        );
        $this->assertCount(1, $files);
        $this->assertSame($source->sha256, hash('sha256', reset($files)->get_content()));
    }

    public function test_add_branch_marks_a_branch_the_plugin_disowns_as_overridden(): void {
        $this->resetAfterTest();
        global $DB;

        // Declares 5.2 only, so 5.0 is an override.
        $this->listed('mod_thing', '[502, 502]');

        $editor = new branch_editor($this->locator, new source_resolver($this->fetcher), new zip_inspector());
        $editor->add_branch('mod_thing', '5.0');

        $this->assertSame(
            ingestor::NOTE_OVERRIDDEN,
            $DB->get_field('local_oerexchange_pluginallowlist', 'notes', [
                'component' => 'mod_thing',
                'moodlebranch' => '5.0',
            ])
        );
    }

    public function test_add_branch_leaves_a_declared_branch_unmarked(): void {
        // The control for the test above: without it, a note written on every
        // row would pass that assertion just as well.
        $this->resetAfterTest();
        global $DB;

        $this->listed('mod_thing', '[500, 502]', ['5.2']);

        $editor = new branch_editor($this->locator, new source_resolver($this->fetcher), new zip_inspector());
        $editor->add_branch('mod_thing', '5.0');

        $this->assertSame(
            '',
            $DB->get_field('local_oerexchange_pluginallowlist', 'notes', [
                'component' => 'mod_thing',
                'moodlebranch' => '5.0',
            ])
        );
    }

    public function test_add_branch_does_not_spread_the_bake_flag_without_the_capability(): void {
        // Extending a baked plugin to another branch changes what the next
        // bundle build ships, so a caller who cannot tick the bake box must
        // not achieve it sideways.
        $this->resetAfterTest();
        global $DB;

        $this->listed('mod_thing', '[502, 502]');
        $DB->set_field('local_oerexchange_pluginallowlist', 'bake', 1, ['component' => 'mod_thing']);

        $editor = new branch_editor($this->locator, new source_resolver($this->fetcher), new zip_inspector());
        $editor->add_branch('mod_thing', '5.0', false);

        $this->assertSame('0', $DB->get_field('local_oerexchange_pluginallowlist', 'bake', [
            'component' => 'mod_thing',
            'moodlebranch' => '5.0',
        ]));
        // The source row is untouched either way.
        $this->assertSame('1', $DB->get_field('local_oerexchange_pluginallowlist', 'bake', [
            'component' => 'mod_thing',
            'moodlebranch' => '5.2',
        ]));
    }

    public function test_add_branch_inherits_the_bake_flag_with_the_capability(): void {
        // The control for the test above: a flag that never copied would pass
        // that assertion just as well.
        $this->resetAfterTest();
        global $DB;

        $this->listed('mod_thing', '[502, 502]');
        $DB->set_field('local_oerexchange_pluginallowlist', 'bake', 1, ['component' => 'mod_thing']);

        $editor = new branch_editor($this->locator, new source_resolver($this->fetcher), new zip_inspector());
        $editor->add_branch('mod_thing', '5.0', true);

        $this->assertSame('1', $DB->get_field('local_oerexchange_pluginallowlist', 'bake', [
            'component' => 'mod_thing',
            'moodlebranch' => '5.0',
        ]));
    }

    public function test_add_branch_is_idempotent(): void {
        $this->resetAfterTest();
        global $DB;

        $this->listed('mod_thing', '[500, 502]');

        $editor = new branch_editor($this->locator, new source_resolver($this->fetcher), new zip_inspector());
        $this->assertSame(branch_editor::EXISTS, $editor->add_branch('mod_thing', '5.2'));
        $this->assertSame(2, $DB->count_records('local_oerexchange_pluginallowlist', ['component' => 'mod_thing']));
    }

    public function test_add_branch_reports_a_plugin_it_has_never_seen(): void {
        $this->resetAfterTest();

        $editor = new branch_editor($this->locator, new source_resolver($this->fetcher), new zip_inspector());
        $this->assertSame(branch_editor::NO_SOURCE, $editor->add_branch('mod_absent', '5.0'));
    }

    public function test_branch_helpers_report_listed_and_addable_branches(): void {
        $this->resetAfterTest();

        $this->listed('mod_thing', '[502, 502]');

        $editor = new branch_editor($this->locator, new source_resolver($this->fetcher), new zip_inspector());
        $this->assertSame(['5.2'], $editor->branches_for('mod_thing'));
        $this->assertSame(['5.0'], $editor->addable_branches('mod_thing'));
    }

    public function test_rollover_plans_only_the_components_missing_from_the_target(): void {
        $this->resetAfterTest();

        $this->listed('mod_thing', '[502, 502]');
        $this->listed('local_helper', '[500, 502]');

        $editor = new branch_editor($this->locator, new source_resolver($this->fetcher), new zip_inspector());
        $this->assertSame(['mod_thing'], $editor->missing_components('5.2', '5.0'));

        // Re-resolved rather than copied: this is the operation that should
        // pick up a release published since the plugin was added.
        $url = 'https://directory.example.org/mod_thing-new.zip';
        $this->locator->urls['mod_thing'] = $url;
        $this->fetcher->zips[$url] = file_get_contents(
            $this->builder->build('mod_thing', [], '[500, 502]', 2026090100)
        );

        $plan = $editor->rollover_plan('5.2', '5.0', make_request_directory());

        $this->assertCount(1, $plan->entries);
        $entry = $plan->entries[0];
        $this->assertSame('mod_thing', $entry->component);
        $this->assertSame(entry_plan::ADD, $entry->action);
        // Only the target branch: a rollover must not re-pin the branches the
        // admin did not ask about.
        $this->assertSame(['5.0'], $entry->branches->branches);
        $this->assertSame(2026090100, $entry->meta->version);
        $this->assertSame(['mod_thing'], $this->locator->requested);
    }

    public function test_rollover_surfaces_a_component_the_directory_cannot_resolve(): void {
        // A plugin published only on its author's forge must appear as a
        // visible problem, not vanish from the rollover.
        $this->resetAfterTest();

        $this->listed('mod_thing', '[502, 502]');

        $editor = new branch_editor($this->locator, new source_resolver($this->fetcher), new zip_inspector());
        $plan = $editor->rollover_plan('5.2', '5.0', make_request_directory());

        $this->assertCount(1, $plan->entries);
        $this->assertSame(entry_plan::UNRESOLVED, $plan->entries[0]->action);
        $this->assertFalse($plan->entries[0]->is_writable());
        $this->assertNotEmpty($plan->entries[0]->messages);
    }

    public function test_rollover_lists_a_branch_the_plugin_disowns_as_an_override(): void {
        $this->resetAfterTest();

        $this->listed('mod_thing', '[502, 502]');

        $url = 'https://directory.example.org/mod_thing.zip';
        $this->locator->urls['mod_thing'] = $url;
        // Still declares 5.2 only, and the admin is rolling over to 5.0.
        $this->fetcher->zips[$url] = file_get_contents($this->builder->build('mod_thing', [], '[502, 502]'));

        $editor = new branch_editor($this->locator, new source_resolver($this->fetcher), new zip_inspector());
        $plan = $editor->rollover_plan('5.2', '5.0', make_request_directory());

        $entry = $plan->entries[0];
        $this->assertSame(['5.0'], $entry->branches->branches);
        $this->assertSame(['5.0'], $entry->branches->declined);
    }

    /**
     * Put a plugin on the allowlist the way a confirmed ingest would.
     *
     * @param string $component frankenstyle name
     * @param string|null $supported array literal for $plugin->supported
     * @param string[]|null $deployed branches the walker may choose from
     * @return void
     */
    private function listed(string $component, ?string $supported = null, ?array $deployed = null): void {
        $zip = $this->builder->build($component, [], $supported);
        $meta = (new zip_inspector())->inspect(
            $zip,
            make_request_directory(),
            'https://example.org/' . $component . '.zip'
        );

        $walker = new dependency_walker(
            new source_resolver($this->fetcher),
            new zip_inspector(),
            $this->locator,
            $deployed ?? ['5.0', '5.2'],
            false,
        );

        (new ingestor())->commit($walker->walk($meta, make_request_directory()));

        // The locator log belongs to the test that comes after this setup.
        $this->locator->requested = [];
    }
}
