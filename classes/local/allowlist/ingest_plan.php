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
 * The complete set of allowlist changes one URL or upload implies.
 *
 * Entries are in dependency order — the plugin the admin asked for first,
 * then whatever it pulled in, breadth first. The ingestor relies on that
 * order: a dependency's row records which entry pulled it in, so the parent's
 * row has to exist first.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ingest_plan {
    /**
     * Constructor.
     *
     * @param entry_plan[] $entries in dependency order, root first
     * @param string[] $messages plan-wide notes, e.g. a traversal limit hit
     */
    public function __construct(
        /** @var entry_plan[] in dependency order, root first */
        public readonly array $entries,
        /** @var string[] plan-wide notes for the admin */
        public readonly array $messages = [],
    ) {
    }

    /**
     * The entry the admin actually asked for.
     *
     * @return entry_plan|null
     */
    public function primary(): ?entry_plan {
        foreach ($this->entries as $entry) {
            if ($entry->role === entry_plan::PRIMARY) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Entries that will actually write rows.
     *
     * @return entry_plan[]
     */
    public function writable(): array {
        return array_values(array_filter($this->entries, static fn (entry_plan $e) => $e->is_writable()));
    }

    /**
     * Whether confirming this plan would do anything at all.
     *
     * @return bool
     */
    public function is_empty(): bool {
        return $this->writable() === [];
    }

    /**
     * Dependencies that were needed but could not be found.
     *
     * These are what the admin has to supply a URL for; the ingest is
     * deliberately still allowed to proceed without them, because a plugin
     * whose optional-in-practice dependency is missing is more useful on the
     * allowlist than absent from it — but they must be visible, never
     * silently dropped.
     *
     * @return entry_plan[]
     */
    public function unresolved(): array {
        return array_values(array_filter(
            $this->entries,
            static fn (entry_plan $e) => $e->action === entry_plan::UNRESOLVED
        ));
    }

    /**
     * A copy of this plan with $entries replaced.
     *
     * @param entry_plan[] $entries
     * @return self
     */
    public function with_entries(array $entries): self {
        return new self($entries, $this->messages);
    }
}
