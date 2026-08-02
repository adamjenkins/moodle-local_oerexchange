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

/**
 * Test fixture: a plugins-directory lookup that answers from a script.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_oerexchange\local\allowlist;

/**
 * A component_locator backed by a fixed map.
 *
 * An unlisted component returns null, which is the real directory's answer
 * for a plugin published only on its author's own forge — the case the
 * walker has to surface to the admin rather than swallow.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_component_locator implements component_locator {
    /** @var array<string, string> component => download URL */
    public array $urls = [];

    /** @var string[] every component looked up, in order */
    public array $requested = [];

    #[\Override]
    public function locate(string $component, ?int $branch): ?string {
        $this->requested[] = $component;

        return $this->urls[$component] ?? null;
    }
}
