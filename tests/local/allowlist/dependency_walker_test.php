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
 * Tests for following a plugin's dependencies onto the allowlist.
 *
 * The user-visible promise being tested: adding a plugin adds what it needs
 * too, without the admin hunting any of it down. The interesting cases are
 * the ones where that has to stop — a core dependency, a diamond, a cycle, a
 * plugin nobody publishes — because each of those, handled wrongly, either
 * loops forever or silently produces a broken trial.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(dependency_walker::class)]
final class dependency_walker_test extends \advanced_testcase {
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

    public function test_a_plugin_with_no_dependencies_plans_only_itself(): void {
        $this->resetAfterTest();

        $plan = $this->walk($this->meta('mod_thing', []));

        $this->assertCount(1, $plan->entries);
        $this->assertSame('mod_thing', $plan->primary()->component);
        $this->assertSame(entry_plan::ADD, $plan->primary()->action);
    }

    public function test_a_contrib_dependency_is_downloaded_and_planned(): void {
        // The headline behaviour: the admin pasted one URL and gets two rows.
        $this->resetAfterTest();

        $this->publish('local_helper', []);

        $plan = $this->walk($this->meta('mod_thing', ['local_helper' => 2026010100]));

        $this->assertCount(2, $plan->entries);
        $dependency = $plan->entries[1];
        $this->assertSame('local_helper', $dependency->component);
        $this->assertSame(entry_plan::DEPENDENCY, $dependency->role);
        $this->assertSame(entry_plan::ADD, $dependency->action);
        $this->assertSame('mod_thing', $dependency->parent);
        // It was really read, not just named.
        $this->assertSame('local', $dependency->meta->plugintype);
        $this->assertSame(['5.0', '5.2'], $dependency->branches->branches);
    }

    public function test_a_core_dependency_is_recorded_but_never_downloaded(): void {
        // The quiz module ships with Moodle, so the sandbox already has it.
        // Mirroring a copy of core into a trial would be pure waste.
        $this->resetAfterTest();

        $plan = $this->walk($this->meta('mod_thing', ['mod_quiz' => 'any']));

        $this->assertCount(2, $plan->entries);
        $this->assertSame('mod_quiz', $plan->entries[1]->component);
        $this->assertSame(entry_plan::CORE, $plan->entries[1]->action);
        $this->assertFalse($plan->entries[1]->is_writable());
        $this->assertSame([], $this->locator->requested);
    }

    public function test_dependencies_of_dependencies_are_followed(): void {
        $this->resetAfterTest();

        $this->publish('local_middle', ['local_deep' => 'any']);
        $this->publish('local_deep', []);

        $plan = $this->walk($this->meta('mod_thing', ['local_middle' => 'any']));

        $this->assertSame(
            ['mod_thing', 'local_middle', 'local_deep'],
            array_map(static fn (entry_plan $e) => $e->component, $plan->entries)
        );
        // Breadth first, parents before children — the order the ingestor
        // needs so a dependency row can point at its parent's row.
        $this->assertSame('local_middle', $plan->entries[2]->parent);
    }

    public function test_a_diamond_plans_the_shared_dependency_once(): void {
        $this->resetAfterTest();

        $this->publish('local_left', ['local_shared' => 'any']);
        $this->publish('local_right', ['local_shared' => 'any']);
        $this->publish('local_shared', []);

        $plan = $this->walk($this->meta('mod_thing', ['local_left' => 'any', 'local_right' => 'any']));

        $components = array_map(static fn (entry_plan $e) => $e->component, $plan->entries);
        $this->assertSame(['mod_thing', 'local_left', 'local_right', 'local_shared'], $components);
        $this->assertCount(1, array_keys($components, 'local_shared', true));
    }

    public function test_a_dependency_cycle_terminates(): void {
        // Two plugins declaring each other. Without the seen-set this walk
        // never returns.
        $this->resetAfterTest();

        $this->publish('local_ping', ['local_pong' => 'any']);
        $this->publish('local_pong', ['local_ping' => 'any']);

        $plan = $this->walk($this->meta('mod_thing', ['local_ping' => 'any']));

        $this->assertSame(
            ['mod_thing', 'local_ping', 'local_pong'],
            array_map(static fn (entry_plan $e) => $e->component, $plan->entries)
        );
    }

    public function test_a_dependency_nobody_publishes_is_surfaced_not_dropped(): void {
        // This is mod_quizquest's situation one level down: a real plugin that is
        // not in the moodle.org directory. Silently omitting it would produce
        // an allowlist that looks complete and a trial that breaks.
        $this->resetAfterTest();

        $plan = $this->walk($this->meta('mod_thing', ['local_private' => 'any']));

        $this->assertCount(1, $plan->unresolved());
        $unresolved = $plan->unresolved()[0];
        $this->assertSame('local_private', $unresolved->component);
        $this->assertSame('mod_thing', $unresolved->parent);
        $this->assertNotEmpty($unresolved->messages);
        // The rest of the plan is still worth committing.
        $this->assertFalse($plan->is_empty());
    }

    public function test_a_dependency_whose_download_is_broken_is_surfaced_not_fatal(): void {
        $this->resetAfterTest();

        // Published, but the URL serves something that is not a plugin ZIP.
        $this->locator->urls['local_broken'] = 'https://example.org/broken.zip';
        $this->fetcher->zips['https://example.org/broken.zip'] = '<html>nope</html>';

        $plan = $this->walk($this->meta('mod_thing', ['local_broken' => 'any']));

        $this->assertCount(1, $plan->unresolved());
        $this->assertSame(entry_plan::ADD, $plan->primary()->action);
    }

    public function test_the_total_entry_cap_stops_the_walk_and_says_so(): void {
        $this->resetAfterTest();

        // A chain far longer than the cap allows.
        $dependencies = [];
        for ($i = 0; $i < dependency_walker::MAX_ENTRIES + 5; $i++) {
            $this->publish('local_dep' . $i, []);
            $dependencies['local_dep' . $i] = 'any';
        }

        $plan = $this->walk($this->meta('mod_thing', $dependencies));

        $downloaded = array_filter($plan->entries, static fn (entry_plan $e) => $e->meta !== null);
        $this->assertLessThanOrEqual(dependency_walker::MAX_ENTRIES + 1, count($downloaded));
        // Silently truncating would read as "everything is covered".
        $this->assertNotEmpty($plan->messages);
    }

    public function test_a_dependency_supporting_no_deployed_branch_is_flagged(): void {
        $this->resetAfterTest();

        $this->publish('local_ancient', [], '[309, 401]');

        $plan = $this->walk($this->meta('mod_thing', ['local_ancient' => 'any']));

        $this->assertSame(entry_plan::NO_BRANCHES, $plan->entries[1]->action);
        $this->assertFalse($plan->entries[1]->is_writable());
    }

    /**
     * Run a walk with the fakes wired up.
     *
     * @param plugin_meta $root
     * @return ingest_plan
     */
    private function walk(plugin_meta $root): ingest_plan {
        $walker = new dependency_walker(
            new source_resolver($this->fetcher),
            new zip_inspector(),
            $this->locator,
            ['5.0', '5.2'],
        );

        return $walker->walk($root, make_request_directory());
    }

    /**
     * Make a component downloadable by the walker.
     *
     * @param string $component
     * @param array $dependencies
     * @param string|null $supported array literal for $plugin->supported
     */
    private function publish(string $component, array $dependencies, ?string $supported = null): void {
        $url = 'https://example.org/' . $component . '.zip';
        $this->locator->urls[$component] = $url;
        $this->fetcher->zips[$url] = file_get_contents(
            $this->builder->build($component, $dependencies, $supported)
        );
    }

    /**
     * Read a built ZIP into a plugin_meta, the way the admin's own upload
     * would have been read.
     *
     * @param string $component
     * @param array $dependencies
     * @return plugin_meta
     */
    private function meta(string $component, array $dependencies): plugin_meta {
        return (new zip_inspector())->inspect(
            $this->builder->build($component, $dependencies),
            make_request_directory()
        );
    }
}
