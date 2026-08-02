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
 * The network seam for allowlist ingest.
 *
 * Exists so source_resolver's branching — which URL form leads to which
 * fetch, and what happens when a repository has no releases — can be tested
 * exhaustively without a network. Those branches are the part that goes wrong;
 * the HTTP call itself is core's job (see moodle_http_fetcher).
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface http_fetcher {
    /**
     * Fetch a small response into memory — a metadata API call, never a ZIP.
     *
     * Implementations must not throw for an HTTP error status; a 404 from the
     * releases endpoint is an expected, meaningful answer ("this repository
     * publishes no releases"), not a failure.
     *
     * @param string $url absolute https URL
     * @return array{status: int, body: string} status 0 when the request
     *                                          could not be made at all
     */
    public function get(string $url): array;

    /**
     * Stream a URL to a local file.
     *
     * @param string $url absolute https URL
     * @param string $path full path to write to
     * @param int $maxbytes refuse to write more than this
     * @return int HTTP status, or 0 when the request could not be made
     * @throws resolution_exception when the response exceeds $maxbytes
     */
    public function download(string $url, string $path, int $maxbytes): int;
}
