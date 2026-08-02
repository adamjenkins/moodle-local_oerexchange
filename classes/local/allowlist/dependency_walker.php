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

/**
 * Walks a plugin's declared dependencies and plans an allowlist entry for
 * each one that needs it.
 *
 * Breadth first from the plugin the admin asked for, so the resulting plan is
 * already in the order the ingestor needs to write it (a dependency's row
 * records the entry that pulled it in, so parents must be written first).
 *
 * Two kinds of dependency are deliberately not pursued:
 *
 * - Anything Moodle ships. The sandbox boots a real Moodle, so a dependency
 *   on mod_quiz is already satisfied; allowlisting it would mirror a copy of
 *   core into a trial that already has it. core_plugin_manager's own list of
 *   standard plugins is the authority for this, not a hand-maintained one.
 * - Anything already seen this walk. Dependency graphs contain diamonds and,
 *   occasionally, cycles.
 *
 * Both caps below exist because this fans out to network fetches: each new
 * component is a metadata lookup plus a ZIP download. A plugin declaring an
 * unexpectedly deep tree should stop and say so rather than quietly spend
 * several minutes downloading.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dependency_walker {
    /** How far from the requested plugin to follow dependencies. */
    public const MAX_DEPTH = 5;

    /** How many plugins in total one ingest may pull in. */
    public const MAX_ENTRIES = 20;

    /**
     * Constructor.
     *
     * @param source_resolver $resolver downloads a dependency's ZIP
     * @param zip_inspector $inspector reads it
     * @param component_locator $locator turns a component name into a URL
     * @param string[]|null $deployed branches to plan for; defaults to the
     *                                deployed sandbox set
     */
    public function __construct(
        /** @var source_resolver */
        private readonly source_resolver $resolver,
        /** @var zip_inspector */
        private readonly zip_inspector $inspector,
        /** @var component_locator */
        private readonly component_locator $locator,
        /** @var string[]|null branches to plan for */
        private readonly ?array $deployed = null,
    ) {
    }

    /**
     * Plan the allowlist entries implied by one plugin.
     *
     * @param plugin_meta $root the plugin the admin asked for
     * @param string $workdir writable directory for dependency downloads
     * @return ingest_plan
     */
    public function walk(plugin_meta $root, string $workdir): ingest_plan {
        $entries = [$this->entry_for($root, entry_plan::PRIMARY, null)];
        $messages = [];
        $seen = [$root->component => true];

        // Holds meta and depth — the components whose own dependencies still need
        // reading. The root's dependencies are read at depth 1.
        $queue = [[$root, 0]];
        $downloaded = 0;

        while ($queue !== []) {
            [$parent, $depth] = array_shift($queue);

            if ($depth >= self::MAX_DEPTH) {
                $messages[] = get_string('allowlistdepthreached', 'local_oerexchange', self::MAX_DEPTH);
                continue;
            }

            foreach (array_keys($parent->dependencies) as $component) {
                if (isset($seen[$component])) {
                    continue;
                }
                $seen[$component] = true;

                if ($this->is_standard($component)) {
                    $entries[] = new entry_plan(
                        component: $component,
                        action: entry_plan::CORE,
                        role: entry_plan::DEPENDENCY,
                        parent: $parent->component,
                    );
                    continue;
                }

                if ($downloaded >= self::MAX_ENTRIES) {
                    // Stop fetching, but still record what was wanted, so the
                    // admin sees a truncated tree rather than a complete-
                    // looking one.
                    $messages[] = get_string('allowlistlimitreached', 'local_oerexchange', self::MAX_ENTRIES);
                    $entries[] = $this->unresolved($component, $parent->component, []);
                    continue;
                }

                $downloaded++;
                $meta = $this->fetch($component, $workdir . '/dep-' . $downloaded, $problem);

                if ($meta === null) {
                    $entries[] = $this->unresolved($component, $parent->component, $problem);
                    continue;
                }

                $entries[] = $this->entry_for($meta, entry_plan::DEPENDENCY, $parent->component);
                $queue[] = [$meta, $depth + 1];
            }
        }

        return new ingest_plan($entries, $messages);
    }

    /**
     * Download and read one dependency.
     *
     * @param string $component
     * @param string $workdir
     * @param string[]|null $problem set to the reason on failure
     * @return plugin_meta|null
     */
    private function fetch(string $component, string $workdir, ?array &$problem): ?plugin_meta {
        $problem = [];

        // Ask the directory for a release matching the newest branch this
        // sandbox runs. One ZIP is fetched per dependency rather than one per
        // branch: a plugin's own supported range then decides which branches
        // it is listed for, exactly as for the plugin the admin asked for.
        $branches = $this->deployed ?? \local_oerexchange\local\sandbox\playground::DEPLOYED_BRANCHES;
        $newest = $branches ? branch_mapper::branch_number((string) end($branches)) : null;

        $url = $this->locator->locate($component, $newest);
        if ($url === null) {
            $problem = [get_string('allowlistnotindirectory', 'local_oerexchange', $component)];
            return null;
        }

        if (!is_dir($workdir)) {
            mkdir($workdir, 0777, true);
        }

        try {
            $resolved = $this->resolver->resolve($url, $workdir);

            return $this->inspector->inspect($resolved->zipfilepath, $workdir, $resolved->sourceurl);
        } catch (resolution_exception $e) {
            $problem = [$e->getMessage()];

            return null;
        }
    }

    /**
     * A planned entry for a plugin that was successfully read.
     *
     * @param plugin_meta $meta
     * @param string $role
     * @param string|null $parent
     * @return entry_plan
     */
    private function entry_for(plugin_meta $meta, string $role, ?string $parent): entry_plan {
        $branches = $meta->branches($this->deployed);

        // ADD here is provisional. The ingestor re-labels an entry REFRESH
        // once it has looked for an existing row; the walker does no DB work
        // so that it stays testable without one.
        return new entry_plan(
            component: $meta->component,
            action: $branches->is_empty() ? entry_plan::NO_BRANCHES : entry_plan::ADD,
            role: $role,
            meta: $meta,
            branches: $branches,
            parent: $parent,
            messages: $meta->warnings,
        );
    }

    /**
     * A planned entry for a dependency that could not be obtained.
     *
     * @param string $component
     * @param string $parent
     * @param string[] $messages
     * @return entry_plan
     */
    private function unresolved(string $component, string $parent, array $messages): entry_plan {
        return new entry_plan(
            component: $component,
            action: entry_plan::UNRESOLVED,
            role: entry_plan::DEPENDENCY,
            parent: $parent,
            messages: $messages,
        );
    }

    /**
     * Whether Moodle ships this component itself.
     *
     * @param string $component
     * @return bool
     */
    private function is_standard(string $component): bool {
        return in_array($component, \core_plugin_manager::get_standard_plugins(), true);
    }
}
