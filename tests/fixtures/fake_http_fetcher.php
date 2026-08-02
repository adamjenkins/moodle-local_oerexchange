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
 * Test fixture: a scripted stand-in for the allowlist ingest's network.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_oerexchange\local\allowlist;

/**
 * An http_fetcher answering from a script instead of the network.
 *
 * Every answer is declared per-URL, so a test that forgets to script a URL
 * gets a 404 rather than a silent empty success — the resolver's fallback
 * chain is exactly the thing under test, and a permissive default would hide
 * a wrong turn in it. The call logs additionally let a test assert what was
 * NOT fetched, which is how "a direct ZIP URL performs no discovery" is
 * pinned down.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_http_fetcher implements http_fetcher {
    /** @var array<string, array> URL => data to be returned JSON-encoded with status 200 */
    public array $json = [];

    /** @var array<string, string> URL => raw bytes a download() of it yields */
    public array $zips = [];

    /** @var array<string, int> URL => HTTP status to answer with, overriding the above */
    public array $statuses = [];

    /** @var string[] every URL passed to get(), in order */
    public array $getcalls = [];

    /** @var string[] every URL passed to download(), in order */
    public array $downloadcalls = [];

    #[\Override]
    public function get(string $url): array {
        $this->getcalls[] = $url;

        if (isset($this->statuses[$url])) {
            return ['status' => $this->statuses[$url], 'body' => ''];
        }
        if (isset($this->json[$url])) {
            return ['status' => 200, 'body' => json_encode($this->json[$url])];
        }

        return ['status' => 404, 'body' => ''];
    }

    #[\Override]
    public function download(string $url, string $path, int $maxbytes): int {
        $this->downloadcalls[] = $url;

        if (isset($this->statuses[$url])) {
            return $this->statuses[$url];
        }
        if (!isset($this->zips[$url])) {
            return 404;
        }

        $body = $this->zips[$url];
        if (strlen($body) > $maxbytes) {
            throw new resolution_exception('error_allowlistziptoobig');
        }

        file_put_contents($path, $body);

        return 200;
    }
}
