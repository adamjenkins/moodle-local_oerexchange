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
 * One plugin's worth of a proposed allowlist change.
 *
 * Deliberately inert: building one performs no writes, so the preview an
 * admin confirms is exactly the set of rows that will be written, and the CLI
 * can render the same thing under --dry-run without a second code path.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class entry_plan {
    /** Will be added to the allowlist. */
    public const ADD = 'add';

    /** Already on the allowlist; the entry and its mirrored ZIP get refreshed. */
    public const REFRESH = 'refresh';

    /** Ships with Moodle, so the sandbox already has it — nothing to do. */
    public const CORE = 'core';

    /** Needed, but no downloadable package could be found for it. */
    public const UNRESOLVED = 'unresolved';

    /** Found and readable, but supports no branch the sandbox runs. */
    public const NO_BRANCHES = 'nobranches';

    /** The admin asked for this one by URL or upload. */
    public const PRIMARY = 'primary';

    /** Pulled in because something else declared it as a dependency. */
    public const DEPENDENCY = 'dependency';

    /**
     * Constructor.
     *
     * @param string $component frankenstyle name
     * @param string $action one of ADD, REFRESH, CORE, UNRESOLVED, NO_BRANCHES
     * @param string $role PRIMARY or DEPENDENCY
     * @param plugin_meta|null $meta null for CORE and UNRESOLVED entries
     * @param branch_selection|null $branches null when there is no package to
     *                                        derive branches from
     * @param string|null $parent component that declared this dependency
     * @param string[] $messages anything the admin should read before confirming
     */
    public function __construct(
        /** @var string frankenstyle name */
        public readonly string $component,
        /** @var string what will happen to it */
        public readonly string $action,
        /** @var string PRIMARY or DEPENDENCY */
        public readonly string $role,
        /** @var plugin_meta|null what was read from its ZIP */
        public readonly ?plugin_meta $meta = null,
        /** @var branch_selection|null branches it will be listed for */
        public readonly ?branch_selection $branches = null,
        /** @var string|null the component that pulled this one in */
        public readonly ?string $parent = null,
        /** @var string[] notes for the admin */
        public readonly array $messages = [],
    ) {
    }

    /**
     * Whether committing this entry writes anything.
     *
     * @return bool
     */
    public function is_writable(): bool {
        return ($this->action === self::ADD || $this->action === self::REFRESH)
            && $this->meta !== null
            && $this->branches !== null
            && !$this->branches->is_empty();
    }

    /**
     * The same entry with a different action — used once the DB has been
     * consulted about whether a row already exists.
     *
     * @param string $action
     * @return self
     */
    public function with_action(string $action): self {
        return new self(
            $this->component,
            $action,
            $this->role,
            $this->meta,
            $this->branches,
            $this->parent,
            $this->messages,
        );
    }
}
