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

/**
 * Tests for local_oerexchange_record_import — specifically the
 * versionid-belongs-to-resourceid cross-validation added for MDL Shield
 * audit finding 1b (2026-07-18).
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oerexchange\external\record_import
 */
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
}
