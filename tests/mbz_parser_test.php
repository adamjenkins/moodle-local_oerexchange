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
}
