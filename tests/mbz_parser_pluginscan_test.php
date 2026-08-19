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

/**
 * Tests for mbz_parser's archive-level required-plugins scan (question
 * types, question behaviours, mod subplugins, grading methods, blocks).
 *
 * Each test plants NON-STANDARD plugin traces into a copy of the real
 * course_no_userdata.mbz fixture and asserts the parser reports exactly
 * them — planted names are findable only by the archive scan, so a scan
 * that silently stopped working fails these tests rather than passing
 * vacuously. Standard plugins planted alongside them must NOT be reported.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(mbz_parser::class)]
final class mbz_parser_pluginscan_test extends \advanced_testcase {
    /**
     * Build an augmented copy of the fixture backup with plugin traces of
     * every archive-scanned kind planted into it.
     *
     * @param bool $aszip repack as a zip-format .mbz instead of tgz
     * @param bool $legacyquestions use the pre-4.0 questions.xml shape
     * @return string absolute path to the augmented .mbz
     */
    protected function build_augmented_mbz(bool $aszip = false, bool $legacyquestions = false): string {
        $base = make_temp_directory('oerexchange_pluginscan_' . sha1(uniqid('', true)));
        $tree = $base . '/tree';
        check_dir_exists($tree);
        $packer = get_file_packer('application/vnd.moodle.backup');
        $packer->extract_to_pathname(__DIR__ . '/fixtures/course_no_userdata.mbz', $tree);
        // The tgz index is regenerated at repack time; a stale extracted copy
        // must not be archived as a member.
        @unlink($tree . '/.ARCHIVE_INDEX');

        // Question types: one standard (multichoice), one not (coderunner).
        if ($legacyquestions) {
            $questions = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<question_categories>
  <question_category id="1">
    <name>Default</name>
    <questions>
      <question id="2">
        <qtype>legacyfancy</qtype>
      </question>
      <question id="3">
        <qtype>truefalse</qtype>
      </question>
      <question id="4">
        <qtype>random</qtype>
      </question>
    </questions>
  </question_category>
</question_categories>
XML;
        } else {
            $questions = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<question_categories>
  <question_category id="1">
    <name>Default</name>
    <question_bank_entries>
      <question_bank_entry id="10">
        <question_version>
          <question_versions id="11">
            <version>1</version>
            <questions>
              <question id="12">
                <qtype>multichoice</qtype>
              </question>
              <question id="13">
                <qtype>coderunner</qtype>
              </question>
            </questions>
          </question_versions>
        </question_version>
      </question_bank_entry>
    </question_bank_entries>
  </question_category>
</question_categories>
XML;
        }
        file_put_contents($tree . '/questions.xml', $questions);

        // A quiz activity: non-standard preferred behaviour, one standard
        // and one non-standard access-rule subplugin.
        check_dir_exists($tree . '/activities/quiz_99');
        file_put_contents($tree . '/activities/quiz_99/quiz.xml', <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<activity id="99" moduleid="99" modulename="quiz" contextid="1">
  <quiz id="7">
    <name>Fixture quiz</name>
    <preferredbehaviour>certaintybased</preferredbehaviour>
    <subplugin_quizaccess_seb_quiz></subplugin_quizaccess_seb_quiz>
    <subplugin_quizaccess_wifiresilience_quiz></subplugin_quizaccess_wifiresilience_quiz>
  </quiz>
</activity>
XML);
        $manifestpath = $tree . '/moodle_backup.xml';
        $manifest = file_get_contents($manifestpath);
        $quizentry = <<<XML
        <activity>
          <moduleid>99</moduleid>
          <sectionid>39</sectionid>
          <modulename>quiz</modulename>
          <title>Fixture quiz</title>
          <directory>activities/quiz_99</directory>
          <insubsection></insubsection>
        </activity>
        <activity>
          <moduleid>77</moduleid>
          <sectionid>39</sectionid>
          <modulename>fancymod</modulename>
          <title>Third-party activity</title>
          <directory>activities/fancymod_77</directory>
          <insubsection></insubsection>
        </activity>
      </activities>
XML;
        $manifest = str_replace('      </activities>', $quizentry, $manifest);
        $this->assertStringContainsString('activities/quiz_99', $manifest);
        file_put_contents($manifestpath, $manifest);

        // A subplugin block in an existing forum's own XML (forum declares
        // the forumreport subplugin type; 'extended' is not a standard one).
        $forumpath = $tree . '/activities/forum_18/forum.xml';
        $forum = file_get_contents($forumpath);
        $this->assertStringContainsString('</forum>', $forum);
        $forum = str_replace(
            '</forum>',
            "<subplugin_forumreport_extended_forum></subplugin_forumreport_extended_forum>\n</forum>",
            $forum
        );
        file_put_contents($forumpath, $forum);

        // A NON-standard mod with its own grading.xml: the gradingform
        // dependency must be reported even though the host mod is not
        // scanned for subplugins (review finding, 2026-08-19).
        check_dir_exists($tree . '/activities/fancymod_77');
        file_put_contents($tree . '/activities/fancymod_77/grading.xml', <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<areas>
  <area id="9">
    <areaname>fancymod</areaname>
    <activemethod>checklist</activemethod>
    <definitions>
      <definition id="10">
        <method>checklist</method>
      </definition>
    </definitions>
  </area>
</areas>
XML);

        // A non-standard advanced-grading method on the first forum.
        file_put_contents($tree . '/activities/forum_17/grading.xml', <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<areas>
  <area id="1">
    <areaname>forum</areaname>
    <activemethod>btec</activemethod>
    <definitions>
      <definition id="2">
        <method>btec</method>
      </definition>
    </definitions>
  </area>
</areas>
XML);

        // Blocks: a non-standard course block, a standard course block, a
        // non-standard activity-level block, and an invalidly-named one
        // (must be dropped, not stored).
        foreach (
            [
            'course/blocks/sidebar_5' => 'sidebar',
            'course/blocks/html_6' => 'html',
            'activities/forum_18/blocks/fancynav_7' => 'fancynav',
            'course/blocks/EvilBlock_9' => 'EvilBlock',
            'course/blocks/participants_10' => 'participants',
            ] as $dir => $blockname
        ) {
            check_dir_exists($tree . '/' . $dir);
            file_put_contents($tree . '/' . $dir . '/block.xml', <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<block id="5" contextid="1" version="2024042200">
  <blockname>{$blockname}</blockname>
  <configdata></configdata>
</block>
XML);
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tree, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isFile()) {
                $relative = ltrim(substr($fileinfo->getPathname(), strlen($tree)), '/');
                $files[$relative] = $fileinfo->getPathname();
            }
        }
        $out = $base . '/augmented.mbz';
        $repacker = $aszip ? get_file_packer('application/zip') : get_file_packer('application/x-gzip');
        $this->assertNotEmpty($repacker->archive_to_pathname($files, $out));
        return $out;
    }

    /**
     * Flattens a parse result's required plugins to "type:name" strings.
     *
     * @param string $mbzpath path to the .mbz to parse
     * @return array list of "type:name"
     */
    protected function parsed_plugin_keys(string $mbzpath): array {
        $result = mbz_parser::parse($mbzpath);
        return array_map(
            static fn(array $plugin): string => $plugin['type'] . ':' . $plugin['name'],
            $result->requiredplugins
        );
    }

    public function test_archive_scan_flags_planted_nonstandard_plugins(): void {
        $this->resetAfterTest();
        $keys = $this->parsed_plugin_keys($this->build_augmented_mbz());

        // Every planted non-standard trace is reported...
        $this->assertContains('qtype:coderunner', $keys);
        $this->assertContains('qbehaviour:certaintybased', $keys);
        $this->assertContains('quizaccess:wifiresilience', $keys);
        $this->assertContains('forumreport:extended', $keys);
        $this->assertContains('gradingform:btec', $keys);
        $this->assertContains('gradingform:checklist', $keys);
        $this->assertContains('mod:fancymod', $keys);
        $this->assertContains('block:sidebar', $keys);
        $this->assertContains('block:fancynav', $keys);

        // ...and nothing standard or invalid is.
        $this->assertNotContains('qtype:multichoice', $keys);
        $this->assertNotContains('quizaccess:seb', $keys);
        $this->assertNotContains('block:html', $keys);
        $this->assertNotContains('block:EvilBlock', $keys);
        // A DELETED standard block (participants left core years ago) is
        // core-handled on restore, not a third-party dependency.
        $this->assertNotContains('block:participants', $keys);
        $this->assertNotContains('mod:quiz', $keys);
        $this->assertNotContains('mod:forum', $keys);
    }

    public function test_legacy_questions_shape_detected(): void {
        $this->resetAfterTest();
        $keys = $this->parsed_plugin_keys($this->build_augmented_mbz(false, true));
        $this->assertContains('qtype:legacyfancy', $keys);
        $this->assertNotContains('qtype:truefalse', $keys);
        // The random qtype was DELETED from core in 4.0: near-universal in
        // pre-4.0 quiz backups, handled by core's restore itself, and a name
        // no plugin may ever use — it must not be reported as a dependency.
        $this->assertNotContains('qtype:random', $keys);
    }

    public function test_zip_format_archive_scanned(): void {
        $this->resetAfterTest();
        $keys = $this->parsed_plugin_keys($this->build_augmented_mbz(true));
        $this->assertContains('qtype:coderunner', $keys);
        $this->assertContains('block:sidebar', $keys);
        $this->assertContains('quizaccess:wifiresilience', $keys);
    }

    public function test_unaugmented_fixture_still_reports_no_plugins(): void {
        $this->resetAfterTest();
        $keys = $this->parsed_plugin_keys(__DIR__ . '/fixtures/course_no_userdata.mbz');
        $this->assertSame([], $keys);
    }
}
