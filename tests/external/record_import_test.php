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

namespace local_oerexchange\external;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for local_oerexchange_record_import — the versionid-belongs-to-
 * resourceid cross-validation added for MDL Shield audit finding 1b
 * (2026-07-18), and the userid-linked-to-calling-site corroboration added
 * for MDL Shield round 2 audit finding 1 (2026-07-19).
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(record_import::class)]
final class record_import_test extends \advanced_testcase {
    /**
     * Create a site + its dedicated service account, and two resources each
     * with one version, for cross-validation testing.
     *
     * @return array [siteserviceuser, resourceid1, versionid1, resourceid2, versionid2]
     */
    protected function setup_fixtures(): array {
        global $DB;

        $creator = $this->getDataGenerator()->create_user();
        $siteuser = $this->getDataGenerator()->create_user(['auth' => 'manual']);

        $siteid = $DB->insert_record('local_oerexchange_sites', (object) [
            'name' => 'Test site', 'url' => 'https://example.com', 'contact' => 'x@example.com',
            'serviceuserid' => $siteuser->id, 'status' => 'active',
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        $resourceid1 = $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'R1', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => $creator->id, 'siteid' => $siteid, 'status' => 'published',
            'downloadcount' => 0, 'importcount' => 0, 'forkedfromid' => null,
            'timeshared' => time(), 'timemodified' => time(),
        ]);
        $versionid1 = $DB->insert_record('local_oerexchange_versions', (object) [
            'resourceid' => $resourceid1, 'versionnumber' => 1, 'itemid' => 0,
            'filename' => 'a.mbz', 'filesize' => 1, 'status' => 'ready', 'timecreated' => time(),
        ]);

        $resourceid2 = $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'R2', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => $creator->id, 'siteid' => $siteid, 'status' => 'published',
            'downloadcount' => 0, 'importcount' => 0, 'forkedfromid' => null,
            'timeshared' => time(), 'timemodified' => time(),
        ]);
        $versionid2 = $DB->insert_record('local_oerexchange_versions', (object) [
            'resourceid' => $resourceid2, 'versionnumber' => 1, 'itemid' => 0,
            'filename' => 'b.mbz', 'filesize' => 1, 'status' => 'ready', 'timecreated' => time(),
        ]);

        return [$siteuser, $resourceid1, $versionid1, $resourceid2, $versionid2];
    }

    public function test_matching_resource_and_version_succeeds(): void {
        global $DB;
        $this->resetAfterTest();

        [$siteuser, $resourceid1, $versionid1] = $this->setup_fixtures();
        $this->setUser($siteuser);

        $result = record_import::execute($resourceid1, $versionid1);
        $this->assertTrue($result['success']);
        $this->assertEquals(1, $DB->get_field('local_oerexchange_resources', 'importcount', ['id' => $resourceid1]));
    }

    public function test_mismatched_resource_and_version_is_rejected(): void {
        global $DB;
        $this->resetAfterTest();

        [$siteuser, $resourceid1, , $resourceid2, $versionid2] = $this->setup_fixtures();
        $this->setUser($siteuser);

        // Version 2 belongs to resource 2, not resource 1 — must be rejected.
        $this->expectException(\moodle_exception::class);
        record_import::execute($resourceid1, $versionid2);
    }

    public function test_mismatched_pair_does_not_increment_either_resources_importcount(): void {
        global $DB;
        $this->resetAfterTest();

        [$siteuser, $resourceid1, , $resourceid2, $versionid2] = $this->setup_fixtures();
        $this->setUser($siteuser);

        try {
            record_import::execute($resourceid1, $versionid2);
        } catch (\moodle_exception $e) {
            $this->assertInstanceOf(\moodle_exception::class, $e);
        }

        $this->assertEquals(0, $DB->get_field('local_oerexchange_resources', 'importcount', ['id' => $resourceid1]));
        $this->assertEquals(0, $DB->get_field('local_oerexchange_resources', 'importcount', ['id' => $resourceid2]));
        $this->assertEquals(0, $DB->count_records('local_oerexchange_imports'));
    }

    /**
     * MDL Shield round 2 audit finding 1: userid is client-supplied and was
     * previously trusted with no verification, letting a malicious/
     * compromised client site attribute an import to an arbitrary
     * Exchange-local userid. A userid actually linked to the calling site
     * (a 'used' local_oerexchange_linkcodes row for that userid+siteid) must
     * still be recorded as-is.
     */
    public function test_userid_linked_to_the_calling_site_is_recorded_as_submitted(): void {
        global $DB;
        $this->resetAfterTest();

        [$siteuser, $resourceid1, $versionid1] = $this->setup_fixtures();
        $siteid = $DB->get_field('local_oerexchange_sites', 'id', ['serviceuserid' => $siteuser->id], MUST_EXIST);
        $importer = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_oerexchange_linkcodes', (object) [
            'code' => 'testcode1', 'siteid' => $siteid, 'userid' => $importer->id,
            'token' => 'testtoken1', 'status' => 'used',
            'timecreated' => time(), 'timeexpires' => time() + DAYSECS,
        ]);
        $this->setUser($siteuser);

        record_import::execute($resourceid1, $versionid1, (int) $importer->id);

        $this->assertEquals(
            $importer->id,
            $DB->get_field('local_oerexchange_imports', 'userid', ['resourceid' => $resourceid1])
        );
    }

    /**
     * A userid NOT linked to the calling site (no matching 'used'
     * linkcodes row) is a forged/untrustworthy attribution — the call must
     * still succeed (an import genuinely happened) but record userid = 0
     * ("unlinked importer", already a supported documented value) rather
     * than trusting the client's claim.
     */
    public function test_unlinked_userid_is_recorded_as_zero_not_the_forged_value(): void {
        global $DB;
        $this->resetAfterTest();

        [$siteuser, $resourceid1, $versionid1] = $this->setup_fixtures();
        // A real Exchange user who exists, but was never linked to this
        // calling site — the forged-attribution case.
        $victim = $this->getDataGenerator()->create_user();
        $this->setUser($siteuser);

        $result = record_import::execute($resourceid1, $versionid1, (int) $victim->id);

        $this->assertTrue($result['success'], 'the call still succeeds — the import genuinely happened');
        $importrow = $DB->get_record('local_oerexchange_imports', ['resourceid' => $resourceid1], '*', MUST_EXIST);
        $this->assertNull($importrow->userid, 'falls back to unlinked (0/null), never the forged userid');
        $this->assertNotEquals($victim->id, $importrow->userid);
    }

    /**
     * A linkcode for the right userid but a DIFFERENT site (or not 'used')
     * must not corroborate the claim either — the check is siteid-specific,
     * not just "this userid was linked to something, somewhere".
     */
    public function test_userid_linked_to_a_different_site_is_recorded_as_zero(): void {
        global $DB;
        $this->resetAfterTest();

        [$siteuser, $resourceid1, $versionid1] = $this->setup_fixtures();
        $othersiteid = $DB->insert_record('local_oerexchange_sites', (object) [
            'name' => 'Other site', 'url' => 'https://other.example.com', 'contact' => 'y@example.com',
            'serviceuserid' => $this->getDataGenerator()->create_user()->id, 'status' => 'active',
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $importer = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_oerexchange_linkcodes', (object) [
            'code' => 'testcode2', 'siteid' => $othersiteid, 'userid' => $importer->id,
            'token' => 'testtoken2', 'status' => 'used',
            'timecreated' => time(), 'timeexpires' => time() + DAYSECS,
        ]);
        $this->setUser($siteuser);

        record_import::execute($resourceid1, $versionid1, (int) $importer->id);

        $importrow = $DB->get_record('local_oerexchange_imports', ['resourceid' => $resourceid1], '*', MUST_EXIST);
        $this->assertNull($importrow->userid);
    }
}
