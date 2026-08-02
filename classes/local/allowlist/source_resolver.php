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
 * Turns what an admin pasted (or uploaded) into a plugin ZIP on local disk.
 *
 * Three input forms, chosen because between them they cover every plugin this
 * platform actually needs: a direct ZIP URL, a GitHub repository URL, and an
 * uploaded file. Deliberately NOT a moodle.org plugin-directory URL — that
 * API is used elsewhere (dependency_walker) to resolve a bare component name
 * a dependency declaration gave us, but it is not something an admin has to
 * type here.
 *
 * The GitHub path is the one with real logic in it. A Moodle plugin
 * repository may or may not publish releases, and if it does the release may
 * or may not carry a properly packaged ZIP asset, so this walks:
 *
 *   latest release -> a *.zip asset          (best: already packaged as the
 *                                             plugin directory)
 *   latest release -> zipball_url            (source snapshot at that tag)
 *   no releases    -> default branch codeload archive
 *
 * A repository with no releases is the mod_quizquest case — a real, working
 * plugin that has simply never cut a GitHub release — so that fallback is
 * load-bearing, not a nicety. Whichever archive is chosen, its root directory
 * is normalised to the plugin's own name later, by zip_inspector, so the
 * "package it by hand" instruction the admin manual used to carry no longer
 * applies to any of these.
 *
 * Everything goes through http_fetcher so the branching above is testable
 * without a network.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_resolver {
    /**
     * Refuse anything larger. Moodle's own plugin ZIPs are single-digit
     * megabytes; 100 MiB is far above any real plugin while still bounding
     * what a mistyped URL can drag onto the server.
     */
    public const MAX_ZIP_BYTES = 104857600;

    /** The first four bytes of every ZIP's local file header. */
    private const ZIP_MAGIC = "PK\x03\x04";

    /**
     * Constructor.
     *
     * @param http_fetcher $fetcher the network seam
     */
    public function __construct(
        /** @var http_fetcher */
        private readonly http_fetcher $fetcher,
    ) {
    }

    /**
     * Download the plugin ZIP a URL denotes.
     *
     * @param string $url what the admin pasted
     * @param string $targetdir a writable directory to download into
     * @return resolved_source
     * @throws resolution_exception when the URL is unusable or yields no ZIP
     */
    public function resolve(string $url, string $targetdir): resolved_source {
        $url = trim($url);
        $parts = parse_url($url);

        // HTTPS only. The fetch happens server-side to a host the admin
        // named, and there is no reason to accept a plaintext downgrade for
        // code that is about to be mirrored and shipped into trials.
        if (empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https' || empty($parts['host'])) {
            throw new resolution_exception('error_allowlistnothttps');
        }

        $host = strtolower($parts['host']);
        $downloadurl = in_array($host, ['github.com', 'www.github.com'], true)
            ? $this->github_archive_url($parts['path'] ?? '')
            : $url;

        return $this->download($downloadurl, $targetdir);
    }

    /**
     * Accept a ZIP that is already on local disk, e.g. an admin's upload.
     *
     * @param string $path full path to the file
     * @param string $displayname what to record as its provenance
     * @return resolved_source
     * @throws resolution_exception when the file is not a ZIP
     */
    public function accept_local_file(string $path, string $displayname): resolved_source {
        $this->assert_is_zip($path);

        return new resolved_source($path, $displayname);
    }

    /**
     * The archive URL to fetch for a github.com repository URL.
     *
     * @param string $path the URL path, e.g. '/acme/moodle-mod_thing/tree/main'
     * @return string
     * @throws resolution_exception when no repository can be identified
     */
    private function github_archive_url(string $path): string {
        $segments = array_values(array_filter(explode('/', $path), static fn ($s) => $s !== ''));

        if (count($segments) < 2) {
            throw new resolution_exception('error_allowlistnotarepo');
        }

        // Anything past owner/repo is the page the admin happened to be on.
        $owner = $segments[0];
        $repo = preg_replace('/\.git$/', '', $segments[1]);

        if (!preg_match('/^[A-Za-z0-9._-]+$/', $owner) || !preg_match('/^[A-Za-z0-9._-]+$/', $repo)) {
            throw new resolution_exception('error_allowlistnotarepo');
        }

        $api = 'https://api.github.com/repos/' . $owner . '/' . $repo;

        $release = $this->fetch_json($api . '/releases/latest');
        if ($release !== null) {
            // A published .zip asset is a properly packaged plugin far more
            // often than a zipball is, so prefer it.
            foreach ($release['assets'] ?? [] as $asset) {
                $name = (string) ($asset['name'] ?? '');
                $href = (string) ($asset['browser_download_url'] ?? '');
                if ($href !== '' && strtolower(substr($name, -4)) === '.zip') {
                    return $href;
                }
            }
            if (!empty($release['zipball_url'])) {
                return (string) $release['zipball_url'];
            }
        }

        // No releases at all — fall back to a snapshot of the default branch.
        $repository = $this->fetch_json($api);
        if ($repository === null || empty($repository['default_branch'])) {
            throw new resolution_exception('error_allowlistreponotfound', $owner . '/' . $repo);
        }

        return 'https://codeload.github.com/' . $owner . '/' . $repo
            . '/zip/refs/heads/' . $repository['default_branch'];
    }

    /**
     * GET a JSON endpoint.
     *
     * @param string $url
     * @return array|null null on any non-200 or unparseable body — for the
     *                    releases endpoint a 404 is a meaningful answer
     *                    ("no releases"), not an error
     */
    private function fetch_json(string $url): ?array {
        $response = $this->fetcher->get($url);

        if ($response['status'] !== 200) {
            return null;
        }

        $decoded = json_decode($response['body'], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Fetch $url into $targetdir and confirm what arrived is a ZIP.
     *
     * @param string $url
     * @param string $targetdir
     * @return resolved_source
     * @throws resolution_exception
     */
    private function download(string $url, string $targetdir): resolved_source {
        $path = rtrim($targetdir, '/') . '/allowlist-source.zip';

        $status = $this->fetcher->download($url, $path, self::MAX_ZIP_BYTES);
        if ($status !== 200) {
            throw new resolution_exception('error_allowlistdownloadfailed', (object) [
                'url' => $url,
                'status' => (string) $status,
            ]);
        }

        $this->assert_is_zip($path);

        return new resolved_source($path, $url);
    }

    /**
     * Confirm a file really is a ZIP.
     *
     * A URL that answers 200 with an HTML error page is the everyday way this
     * goes wrong; without this check that page would be stored and served as
     * a plugin.
     *
     * @param string $path
     * @throws resolution_exception
     */
    private function assert_is_zip(string $path): void {
        $handle = @fopen($path, 'rb');
        $magic = $handle ? fread($handle, 4) : false;
        if ($handle) {
            fclose($handle);
        }

        if ($magic !== self::ZIP_MAGIC) {
            throw new resolution_exception('error_allowlistnotazip');
        }
    }
}
