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

namespace local_oerexchange;

use PHPUnit\Framework\Attributes\CoversClass;
use local_oerexchange\local\parser\mbz_parser;
use local_oerexchange\local\sanitycheck;

/**
 * Tests for mbz_parser and sanitycheck against a real backup fixture
 * (course + forum + label, users=false).
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(sanitycheck::class)]
#[CoversClass(mbz_parser::class)]
final class mbz_parser_test extends \advanced_testcase {
    /**
     * Returns the path to this test's fixture .mbz file.
     *
     * @return string
     */
    protected function fixture_path(): string {
        return __DIR__ . '/fixtures/course_no_userdata.mbz';
    }

    public function test_parse_extracts_structure_and_no_required_plugins(): void {
        $this->resetAfterTest();

        $result = mbz_parser::parse($this->fixture_path());

        $this->assertSame('topics', $result->courseformat);
        $this->assertSame('course', $result->type);
        $this->assertNotEmpty($result->moodleversion);
        $this->assertSame([], $result->requiredplugins);

        $structure = json_decode($result->structurejson, true);
        $this->assertCount(3, $structure['sections']);

        $modnames = [];
        foreach ($structure['sections'] as $section) {
            foreach ($section['activities'] as $activity) {
                $modnames[] = $activity['modulename'];
            }
        }
        sort($modnames);
        $this->assertSame(['forum', 'forum', 'label'], $modnames);
    }

    public function test_sanitycheck_passes_on_users_false_backup(): void {
        $this->resetAfterTest();

        $this->assertTrue(sanitycheck::passes($this->fixture_path()));
    }

    /** @var string A 1x1 PNG, base64. */
    const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /**
     * Build a .mbz whose files.xml names one course overview image.
     *
     * @param string $dir a scratch directory
     * @param string $contenthash the manifest's contenthash for the image
     * @param string|null $content bytes to store at files/<hash[0..1]>/<hash>, or null for none
     * @return string path to the .mbz
     */
    protected function build_cover_mbz(string $dir, string $contenthash, ?string $content = null): string {
        $src = $dir . '/src';
        check_dir_exists($src);
        file_put_contents($src . '/files.xml', '<?xml version="1.0" encoding="UTF-8"?>
<files>
  <file id="1">
    <contenthash>' . s($contenthash) . '</contenthash>
    <contextid>1</contextid>
    <component>course</component>
    <filearea>overviewfiles</filearea>
    <itemid>0</itemid>
    <filepath>/</filepath>
    <filename>cover.png</filename>
  </file>
</files>');
        $files = ['files.xml' => $src . '/files.xml'];
        if ($content !== null) {
            file_put_contents($src . '/blob', $content);
            $files['files/' . substr($contenthash, 0, 2) . '/' . $contenthash] = $src . '/blob';
        }

        $mbz = $dir . '/backup.mbz';
        get_file_packer('application/vnd.moodle.backup')->archive_to_pathname($files, $mbz);
        return $mbz;
    }

    /**
     * The contenthash in files.xml comes from the uploaded backup. Before the
     * fix, '../victim.png' resolved outside the extraction directory and the
     * image found there was moved into the resource's public cover (security
     * audit 2026-10-28, F4).
     */
    public function test_extract_cover_image_refuses_a_contenthash_that_leaves_the_extraction_dir(): void {
        $this->resetAfterTest();
        $base = make_request_directory();
        $outputdir = $base . '/out';
        // An earlier, legitimate manifest entry would have created this; the
        // traversal needs it to exist to resolve.
        check_dir_exists($outputdir . '/coverimage_content/files');
        $victim = $outputdir . '/victim.png';
        file_put_contents($victim, base64_decode(self::PNG));

        $mbz = $this->build_cover_mbz($base, '../victim.png');

        $this->assertNull(mbz_parser::extract_cover_image($mbz, $outputdir));
        $this->assertFileExists($victim, 'a file outside the extraction directory is never taken');
    }

    public function test_extract_cover_image_copies_a_well_formed_entry(): void {
        $this->resetAfterTest();
        $base = make_request_directory();
        $outputdir = $base . '/out';
        check_dir_exists($outputdir);
        $png = base64_decode(self::PNG);
        $hash = sha1($png);

        $mbz = $this->build_cover_mbz($base, $hash, $png);
        $cover = mbz_parser::extract_cover_image($mbz, $outputdir);

        $this->assertNotNull($cover);
        $this->assertSame('cover.png', basename($cover));
        $this->assertSame($png, file_get_contents($cover));
        $this->assertFileExists(
            $outputdir . '/coverimage_content/files/' . substr($hash, 0, 2) . '/' . $hash,
            'the extracted file is copied, not moved'
        );
    }
}
