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
 * Raised when a URL or ZIP cannot be turned into a usable plugin package.
 *
 * Every one of these is something the admin can act on — a URL that is not a
 * plugin, a repository with nothing published, a file that is not a ZIP — so
 * the message is always a lang string fit to render straight into the
 * preview, not a developer-facing diagnostic.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resolution_exception extends \moodle_exception {
    /**
     * Constructor.
     *
     * @param string $errorcode lang string key in local_oerexchange
     * @param string|object|array|null $a value to fill the lang string with
     */
    public function __construct(string $errorcode, $a = null) {
        parent::__construct($errorcode, 'local_oerexchange', '', $a);
    }
}
