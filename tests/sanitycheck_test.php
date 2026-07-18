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

namespace local_oerexchange\local;

/**
 * Negative-case tests for sanitycheck — mbz_parser_test.php already covers
 * the positive case (a real users=false backup passes); this covers the
 * defense-in-depth failure modes, which had no coverage at all before the
 * second MDL Shield audit pass (2026-07-18). Uses a hand-built zip + an
 * explicit $rawinfo to isolate the users.xml-scanning logic from
 * moodle_backup.xml parsing (already covered elsewhere).
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oerexchange\local\sanitycheck
 */
final class sanitycheck_test extends \advanced_testcase {
    /**
     * Builds a minimal fixture backup zip, with or without a users.xml entry.
     *
     * @param string|null $usersxmlcontent contents of users.xml inside the zip, or
     *                                null to omit the file entirely
     * @return string path to the built zip
     */
    protected function build_zip(?string $usersxmlcontent): string {
        $path = make_request_directory() . '/fixture_' . uniqid() . '.mbz';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        if ($usersxmlcontent !== null) {
            $zip->addFromString('users.xml', $usersxmlcontent);
        }
        $zip->addFromString('moodle_backup.xml', '<moodle_backup></moodle_backup>');
        $zip->close();
        return $path;
    }

    public function test_backup_with_root_setting_users_true_fails(): void {
        $this->resetAfterTest();
        $path = $this->build_zip(null);

        $rawinfo = (object) ['root_settings' => ['users' => '1']];
        $this->assertFalse(sanitycheck::passes($path, $rawinfo));
    }

    public function test_backup_with_populated_usersxml_fails_even_if_root_setting_says_clean(): void {
        $this->resetAfterTest();
        // A backup whose settings CLAIM users=false but whose users.xml
        // actually contains user records — the exact defense-in-depth
        // scenario this class exists for (don't just trust the client).
        $path = $this->build_zip('<users><user id="2"><username>evil</username></user></users>');

        $rawinfo = (object) ['root_settings' => ['users' => '0']];
        $this->assertFalse(sanitycheck::passes($path, $rawinfo));
    }

    public function test_backup_with_empty_usersxml_skeleton_passes(): void {
        $this->resetAfterTest();
        // A clean backup still ships a users.xml skeleton with no <user> records.
        $path = $this->build_zip('<users></users>');

        $rawinfo = (object) ['root_settings' => ['users' => '0']];
        $this->assertTrue(sanitycheck::passes($path, $rawinfo));
    }

    public function test_backup_with_no_usersxml_at_all_passes(): void {
        $this->resetAfterTest();
        $path = $this->build_zip(null);

        $rawinfo = (object) ['root_settings' => ['users' => '0']];
        $this->assertTrue(sanitycheck::passes($path, $rawinfo));
    }

    public function test_missing_root_setting_does_not_short_circuit_the_usersxml_check(): void {
        $this->resetAfterTest();
        // No 'users' key at all in root_settings (older/unusual backup) — must
        // still fall through to the independent users.xml inspection rather
        // than treating "unknown" as "clean".
        $path = $this->build_zip('<users><user id="3"><username>evil2</username></user></users>');

        $rawinfo = (object) ['root_settings' => []];
        $this->assertFalse(sanitycheck::passes($path, $rawinfo));
    }
}
