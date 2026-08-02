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
 * A plugin ZIP on local disk, plus where it came from.
 *
 * The provenance URL is not decoration: it is what gets stored in the
 * allowlist entry's sourceurl column and what the sandbox build machine later
 * re-downloads from, so it must be the URL actually fetched (a release asset,
 * a codeload archive) rather than the friendlier page address the admin
 * happened to paste.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class resolved_source {
    /**
     * Constructor.
     *
     * @param string $zipfilepath full path to the downloaded ZIP
     * @param string $sourceurl the URL actually fetched, or the original
     *                          filename for an upload
     */
    public function __construct(
        /** @var string full path to the ZIP on local disk */
        public readonly string $zipfilepath,
        /** @var string the URL actually fetched, or an upload's filename */
        public readonly string $sourceurl,
    ) {
    }
}
