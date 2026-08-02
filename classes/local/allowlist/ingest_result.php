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
 * What committing an ingest plan actually did.
 *
 * Counted in rows rather than plugins because one plugin normally produces
 * several rows — one per Moodle branch it supports — and the admin who used
 * to create those rows by hand, one form submission at a time, is the person
 * reading this.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ingest_result {
    /**
     * Constructor.
     *
     * @param int $added rows created
     * @param int $refreshed rows updated in place
     * @param string[] $components the plugins involved, primary first
     */
    public function __construct(
        /** @var int rows created */
        public readonly int $added,
        /** @var int rows updated in place */
        public readonly int $refreshed,
        /** @var string[] the plugins involved, primary first */
        public readonly array $components,
    ) {
    }

    /**
     * Total rows written.
     *
     * @return int
     */
    public function rows(): int {
        return $this->added + $this->refreshed;
    }
}
