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
 * The real network, via core's HTTP client.
 *
 * Note what is deliberately absent: 'ignoresecurity'. bundle_stamp has to
 * pass it (behind its own opt-in setting) because the sandbox base URL may
 * legitimately be a private address on a self-hosted deployment. Nothing
 * comparable applies here — these URLs point at public code-hosting sites —
 * so core's outbound-request guard (curlsecurityblockedhosts, which blocks
 * private ranges by default) stays on, and TLS verification with it. An
 * admin-supplied URL is still a URL a request gets made to.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class moodle_http_fetcher implements http_fetcher {
    /** Seconds to wait for a connection. */
    private const CONNECT_TIMEOUT = 10;

    /** Seconds for a metadata call — small JSON, should be quick. */
    private const METADATA_TIMEOUT = 20;

    /** Seconds for a ZIP download, which may be tens of megabytes. */
    private const DOWNLOAD_TIMEOUT = 300;

    /** Bytes to pull off the wire at a time. */
    private const CHUNK = 65536;

    #[\Override]
    public function get(string $url): array {
        try {
            $client = new \core\http_client([
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'timeout' => self::METADATA_TIMEOUT,
                // A non-200 is information here, not an exception: a 404 from
                // the releases endpoint means "this repository publishes no
                // releases", which is a normal answer the resolver acts on.
                'http_errors' => false,
                'headers' => $this->headers(),
            ]);
            $response = $client->get($url);

            return [
                'status' => $response->getStatusCode(),
                'body' => (string) $response->getBody(),
            ];
        } catch (\Throwable $e) {
            // A blocked-by-guard request, a DNS failure and a timeout all
            // mean the same thing to the caller: no answer from this URL.
            return ['status' => 0, 'body' => ''];
        }
    }

    #[\Override]
    public function download(string $url, string $path, int $maxbytes): int {
        try {
            $client = new \core\http_client([
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'timeout' => self::DOWNLOAD_TIMEOUT,
                'http_errors' => false,
                'headers' => $this->headers(),
                // Streamed rather than buffered so the size cap below can be
                // enforced as bytes arrive. Reading the whole body first and
                // measuring it afterwards would mean already having taken the
                // hit the cap exists to prevent.
                'stream' => true,
            ]);
            $response = $client->get($url);
        } catch (\Throwable $e) {
            return 0;
        }

        $status = $response->getStatusCode();
        if ($status !== 200) {
            return $status;
        }

        $body = $response->getBody();
        $out = fopen($path, 'wb');
        if ($out === false) {
            return 0;
        }

        $written = 0;
        try {
            while (!$body->eof()) {
                $chunk = $body->read(self::CHUNK);
                if ($chunk === '') {
                    break;
                }

                $written += strlen($chunk);
                if ($written > $maxbytes) {
                    throw new resolution_exception('error_allowlistziptoobig');
                }

                fwrite($out, $chunk);
            }
        } finally {
            fclose($out);
            if ($written > $maxbytes) {
                @unlink($path);
            }
        }

        return 200;
    }

    /**
     * Request headers.
     *
     * GitHub's API rejects a request with no User-Agent outright, and the
     * Accept header pins the response schema this code was written against.
     *
     * @return array<string, string>
     */
    private function headers(): array {
        global $CFG;

        return [
            'User-Agent' => 'local_oerexchange/' . ($CFG->wwwroot ?? 'moodle'),
            'Accept' => 'application/vnd.github+json, application/json, */*',
        ];
    }
}
