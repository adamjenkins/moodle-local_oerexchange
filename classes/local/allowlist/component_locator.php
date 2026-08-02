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
 * Finds a download for a plugin known only by its frankenstyle name.
 *
 * Needed because a dependency declaration gives a name and nothing else:
 * `$plugin->dependencies = ['local_helper' => 2026010100]` says what is
 * required but not where to get it. An admin pasting a URL for the plugin
 * they wanted should not then have to hunt down URLs for its dependencies —
 * that is the tedium this whole feature exists to remove.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface component_locator {
    /**
     * Where a plugin can be downloaded from.
     *
     * @param string $component frankenstyle name, e.g. 'local_helper'
     * @param int|null $branch core branch to prefer a release for, e.g. 502
     * @return string|null null when nothing is published for it — an
     *                     ordinary outcome for a plugin that lives only on
     *                     its author's own forge
     */
    public function locate(string $component, ?int $branch): ?string;
}
