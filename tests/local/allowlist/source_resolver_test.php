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

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/oerexchange/tests/fixtures/fake_http_fetcher.php');

/**
 * Tests for turning what an admin pasted into a downloaded plugin ZIP.
 *
 * All of it runs against a scripted fetcher: the interesting behaviour is
 * which URL gets asked for next given the last answer — especially the
 * GitHub path, where "this repository has no releases" is a 404 that must be
 * read as an instruction to fall back to the default branch rather than as a
 * failure.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(source_resolver::class)]
final class source_resolver_test extends \basic_testcase {
    /** A minimal but genuine ZIP: local file header magic + nothing useful. */
    private const ZIP_BYTES = "PK\x03\x04fake zip payload";

    public function test_a_direct_zip_url_is_downloaded_as_given(): void {
        $fetcher = new fake_http_fetcher();
        $fetcher->zips['https://example.org/mod_thing.zip'] = self::ZIP_BYTES;

        $resolved = (new source_resolver($fetcher))
            ->resolve('https://example.org/mod_thing.zip', make_request_directory());

        $this->assertSame('https://example.org/mod_thing.zip', $resolved->sourceurl);
        $this->assertStringEqualsFile($resolved->zipfilepath, self::ZIP_BYTES);
        // No metadata lookups: a direct URL needs no discovery.
        $this->assertSame([], $fetcher->getcalls);
    }

    public function test_a_github_repo_prefers_a_published_zip_asset(): void {
        // The common case for a Moodle plugin: the release carries a properly
        // packaged ZIP whose root directory is already the plugin's name,
        // which a zipball would not be.
        $fetcher = new fake_http_fetcher();
        $fetcher->json['https://api.github.com/repos/acme/moodle-mod_thing/releases/latest'] = [
            'tag_name' => 'v1.2.0',
            'zipball_url' => 'https://api.github.com/repos/acme/moodle-mod_thing/zipball/v1.2.0',
            'assets' => [
                ['name' => 'notes.txt', 'browser_download_url' => 'https://example.org/notes.txt'],
                ['name' => 'mod_thing.zip', 'browser_download_url' => 'https://example.org/mod_thing.zip'],
            ],
        ];
        $fetcher->zips['https://example.org/mod_thing.zip'] = self::ZIP_BYTES;

        $resolved = (new source_resolver($fetcher))
            ->resolve('https://github.com/acme/moodle-mod_thing', make_request_directory());

        $this->assertSame('https://example.org/mod_thing.zip', $resolved->sourceurl);
    }

    public function test_a_github_release_without_a_zip_asset_uses_the_zipball(): void {
        $fetcher = new fake_http_fetcher();
        $fetcher->json['https://api.github.com/repos/acme/moodle-mod_thing/releases/latest'] = [
            'tag_name' => 'v1.2.0',
            'zipball_url' => 'https://api.github.com/repos/acme/moodle-mod_thing/zipball/v1.2.0',
            'assets' => [],
        ];
        $fetcher->zips['https://api.github.com/repos/acme/moodle-mod_thing/zipball/v1.2.0'] = self::ZIP_BYTES;

        $resolved = (new source_resolver($fetcher))
            ->resolve('https://github.com/acme/moodle-mod_thing', make_request_directory());

        $this->assertSame(
            'https://api.github.com/repos/acme/moodle-mod_thing/zipball/v1.2.0',
            $resolved->sourceurl
        );
    }

    public function test_a_github_repo_with_no_releases_falls_back_to_the_default_branch(): void {
        // This is mod_quizquest's situation: a real plugin repository that has never
        // cut a GitHub release. Refusing here would defeat the whole feature.
        $fetcher = new fake_http_fetcher();
        $fetcher->statuses['https://api.github.com/repos/acme/moodle-mod_thing/releases/latest'] = 404;
        $fetcher->json['https://api.github.com/repos/acme/moodle-mod_thing'] = [
            'default_branch' => 'main',
        ];
        $fetcher->zips['https://codeload.github.com/acme/moodle-mod_thing/zip/refs/heads/main'] = self::ZIP_BYTES;

        $resolved = (new source_resolver($fetcher))
            ->resolve('https://github.com/acme/moodle-mod_thing', make_request_directory());

        $this->assertSame(
            'https://codeload.github.com/acme/moodle-mod_thing/zip/refs/heads/main',
            $resolved->sourceurl
        );
    }

    public function test_a_default_branch_that_is_not_master_or_main_is_honoured(): void {
        $fetcher = new fake_http_fetcher();
        $fetcher->statuses['https://api.github.com/repos/acme/moodle-mod_thing/releases/latest'] = 404;
        $fetcher->json['https://api.github.com/repos/acme/moodle-mod_thing'] = [
            'default_branch' => 'MOODLE_502_STABLE',
        ];
        $fetcher->zips['https://codeload.github.com/acme/moodle-mod_thing/zip/refs/heads/MOODLE_502_STABLE']
            = self::ZIP_BYTES;

        $resolved = (new source_resolver($fetcher))
            ->resolve('https://github.com/acme/moodle-mod_thing', make_request_directory());

        $this->assertStringContainsString('MOODLE_502_STABLE', $resolved->sourceurl);
    }

    public function test_extra_path_segments_and_a_git_suffix_still_identify_the_repo(): void {
        // People paste the URL of whatever page they were looking at.
        $fetcher = new fake_http_fetcher();
        $fetcher->json['https://api.github.com/repos/acme/moodle-mod_thing/releases/latest'] = [
            'zipball_url' => 'https://api.github.com/repos/acme/moodle-mod_thing/zipball/v1',
            'assets' => [],
        ];
        $fetcher->zips['https://api.github.com/repos/acme/moodle-mod_thing/zipball/v1'] = self::ZIP_BYTES;

        foreach (
            [
            'https://github.com/acme/moodle-mod_thing/tree/main/db',
            'https://github.com/acme/moodle-mod_thing.git',
            'https://www.github.com/acme/moodle-mod_thing/releases',
            'https://github.com/acme/moodle-mod_thing/',
            ] as $url
        ) {
            $resolved = (new source_resolver($fetcher))->resolve($url, make_request_directory());
            $this->assertSame(
                'https://api.github.com/repos/acme/moodle-mod_thing/zipball/v1',
                $resolved->sourceurl,
                $url
            );
        }
    }

    public function test_a_github_url_naming_no_repository_is_refused(): void {
        $this->expectException(resolution_exception::class);

        (new source_resolver(new fake_http_fetcher()))
            ->resolve('https://github.com/acme', make_request_directory());
    }

    public function test_a_repository_that_does_not_exist_is_refused(): void {
        $fetcher = new fake_http_fetcher();
        $fetcher->statuses['https://api.github.com/repos/acme/nope/releases/latest'] = 404;
        $fetcher->statuses['https://api.github.com/repos/acme/nope'] = 404;

        $this->expectException(resolution_exception::class);

        (new source_resolver($fetcher))->resolve('https://github.com/acme/nope', make_request_directory());
    }

    public function test_a_non_https_url_is_refused(): void {
        // The download happens server-side from a host the admin named; there
        // is no reason to accept a downgrade to plaintext for it.
        $this->expectException(resolution_exception::class);

        (new source_resolver(new fake_http_fetcher()))
            ->resolve('http://example.org/mod_thing.zip', make_request_directory());
    }

    public function test_a_response_that_is_not_a_zip_is_refused(): void {
        // A URL that 200s with an HTML error page is the everyday version of
        // this — without the magic-byte check it would be stored as a plugin.
        $fetcher = new fake_http_fetcher();
        $fetcher->zips['https://example.org/mod_thing.zip'] = '<!DOCTYPE html><title>Not found</title>';

        $this->expectException(resolution_exception::class);

        (new source_resolver($fetcher))
            ->resolve('https://example.org/mod_thing.zip', make_request_directory());
    }

    public function test_a_download_that_404s_is_refused(): void {
        $fetcher = new fake_http_fetcher();
        $fetcher->statuses['https://example.org/gone.zip'] = 404;

        $this->expectException(resolution_exception::class);

        (new source_resolver($fetcher))->resolve('https://example.org/gone.zip', make_request_directory());
    }

    public function test_an_uploaded_file_is_accepted_without_any_fetching(): void {
        $dir = make_request_directory();
        $path = $dir . '/uploaded.zip';
        file_put_contents($path, self::ZIP_BYTES);

        $fetcher = new fake_http_fetcher();
        $resolved = (new source_resolver($fetcher))->accept_local_file($path, 'uploaded.zip');

        $this->assertSame($path, $resolved->zipfilepath);
        $this->assertSame('uploaded.zip', $resolved->sourceurl);
        $this->assertSame([], $fetcher->getcalls);
        $this->assertSame([], $fetcher->downloadcalls);
    }

    public function test_an_uploaded_file_that_is_not_a_zip_is_refused(): void {
        $path = make_request_directory() . '/uploaded.zip';
        file_put_contents($path, 'just some text');

        $this->expectException(resolution_exception::class);

        (new source_resolver(new fake_http_fetcher()))->accept_local_file($path, 'uploaded.zip');
    }
}
