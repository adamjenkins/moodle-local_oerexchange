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

/**
 * Tests for reading a real plugin ZIP.
 *
 * These build genuine ZIP files rather than mocking the packer: the whole
 * value of this class is what it does to an archive's directory layout, and
 * a mocked packer would assert nothing about that.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(zip_inspector::class)]
final class zip_inspector_test extends \advanced_testcase {
    public function test_reads_every_field_the_admin_used_to_type_in(): void {
        $this->resetAfterTest();

        $zip = $this->build_zip('quizquest', [
            'quizquest/version.php' => $this->versionphp([
                'component' => 'mod_quizquest',
                'version' => 2026070100,
                'release' => '2.1.0',
                'requires' => 2025041400,
                'supported' => '[500, 502]',
                'dependencies' => "['mod_quiz' => ANY_VERSION, 'local_helper' => 2026010100]",
            ]),
            'quizquest/lib.php' => "<?php\n// Nothing.\n",
        ]);

        $meta = (new zip_inspector())->inspect($zip, make_request_directory());

        // Plugin type and name: previously two hand-typed form fields.
        $this->assertSame('mod_quizquest', $meta->component);
        $this->assertSame('mod', $meta->plugintype);
        $this->assertSame('quizquest', $meta->pluginname);

        $this->assertSame(2026070100, $meta->version);
        $this->assertSame('2.1.0', $meta->release);
        $this->assertSame(2025041400, $meta->requires);

        // Supported branches: previously one hand-typed field, filled in once
        // per branch.
        $this->assertSame([500, 502], $meta->supported);
        $this->assertSame(['5.0', '5.2'], $meta->branches(['5.0', '5.2'])->branches);
        $this->assertSame(branch_mapper::DECLARED, $meta->branches(['5.0', '5.2'])->confidence);

        // Dependencies: previously not captured at all.
        $this->assertSame([
            'mod_quiz' => 'any',
            'local_helper' => 2026010100,
        ], $meta->dependencies);
    }

    public function test_a_github_archive_is_repacked_under_the_plugins_own_name(): void {
        // The point of the whole normalisation step. A GitHub archive's root
        // is the repository and commit, never the plugin's directory name —
        // which is why the admin manual used to tell admins to repack by hand
        // before uploading.
        $zip = $this->build_zip('gh', [
            'acme-moodle-mod_quizquest-9f2c1ab/version.php' => $this->versionphp([
                'component' => 'mod_quizquest',
                'version' => 2026070100,
            ]),
            'acme-moodle-mod_quizquest-9f2c1ab/lib.php' => "<?php\n",
        ]);

        $meta = (new zip_inspector())->inspect($zip, make_request_directory());

        $this->assertSame('mod_quizquest', $meta->component);
        $this->assertSame(['quizquest/', 'quizquest/lib.php', 'quizquest/version.php'], $this->entries($meta->zipfilepath));
    }

    public function test_an_already_correct_package_stays_correct(): void {
        $zip = $this->build_zip('ok', [
            'quizquest/version.php' => $this->versionphp(['component' => 'mod_quizquest', 'version' => 2026070100]),
        ]);

        $meta = (new zip_inspector())->inspect($zip, make_request_directory());

        $this->assertSame(['quizquest/', 'quizquest/version.php'], $this->entries($meta->zipfilepath));
    }

    public function test_a_zip_with_version_php_loose_at_its_root_is_still_read(): void {
        $zip = $this->build_zip('loose', [
            'version.php' => $this->versionphp(['component' => 'block_thing', 'version' => 2026070100]),
        ]);

        $meta = (new zip_inspector())->inspect($zip, make_request_directory());

        $this->assertSame('block', $meta->plugintype);
        $this->assertSame('thing', $meta->pluginname);
        // And the repack gives it the wrapper directory it was missing —
        // this shape used to produce a silently unusable archive.
        $this->assertSame(['thing/', 'thing/version.php'], $this->entries($meta->zipfilepath));
    }

    public function test_a_zip_that_is_not_a_plugin_is_refused(): void {
        $zip = $this->build_zip('junk', ['readme.txt' => 'hello']);

        $this->expectException(resolution_exception::class);

        (new zip_inspector())->inspect($zip, make_request_directory());
    }

    public function test_a_version_php_with_no_component_is_refused(): void {
        // Nothing to derive a plugin type or name from, which is precisely
        // what this whole path exists to derive.
        $zip = $this->build_zip('nocomp', [
            'thing/version.php' => "<?php\n\$plugin->version = 2026070100;\n",
        ]);

        $this->expectException(resolution_exception::class);

        (new zip_inspector())->inspect($zip, make_request_directory());
    }

    public function test_a_plugin_declaring_nothing_optional_still_reads(): void {
        // Most contrib plugins declare neither supported nor dependencies.
        $zip = $this->build_zip('bare', [
            'thing/version.php' => $this->versionphp([
                'component' => 'local_thing',
                'version' => 2026070100,
                'requires' => 2025041400,
            ]),
        ]);

        $meta = (new zip_inspector())->inspect($zip, make_request_directory());

        $this->assertNull($meta->supported);
        $this->assertSame([], $meta->dependencies);
        // Falls back to the requires-based inference rather than refusing.
        $this->assertSame(branch_mapper::INFERRED, $meta->branches(['5.0', '5.2'])->confidence);
    }

    /**
     * Build a ZIP from a map of archive path => contents.
     *
     * @param string $name basename for the ZIP
     * @param array $files
     * @return string full path to the ZIP
     */
    private function build_zip(string $name, array $files): string {
        $dir = make_request_directory();
        $staging = $dir . '/staging';

        $archive = [];
        foreach ($files as $path => $contents) {
            $full = $staging . '/' . $path;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0777, true);
            }
            file_put_contents($full, $contents);
            $archive[$path] = $full;
        }

        $zip = $dir . '/' . $name . '.zip';
        get_file_packer('application/zip')->archive_to_pathname($archive, $zip);

        return $zip;
    }

    /**
     * A version.php declaring the given fields.
     *
     * @param array $fields
     * @return string
     */
    private function versionphp(array $fields): string {
        $lines = ["<?php"];
        foreach ($fields as $name => $value) {
            // Strings that are already PHP source (an array literal, or a
            // quoted release) are emitted as-is; bare words get quoted.
            $literal = is_int($value) || str_starts_with((string) $value, '[')
                ? $value
                : "'" . $value . "'";
            $lines[] = '$plugin->' . $name . ' = ' . $literal . ';';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Sorted list of entries inside a ZIP.
     *
     * @param string $zippath
     * @return string[]
     */
    private function entries(string $zippath): array {
        $names = [];
        foreach (get_file_packer('application/zip')->list_files($zippath) as $entry) {
            $names[] = $entry->pathname;
        }
        sort($names);

        return $names;
    }
}
