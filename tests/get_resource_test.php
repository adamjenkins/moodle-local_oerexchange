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
 * Tests for local_oerexchange_get_resource. Added on the fourth MDL Shield
 * audit pass (2026-07-19) — no WS-layer coverage existed for this function
 * before this pass, including for the visibility gate (only status=published
 * is ever readable) that resource.php and download.php both also enforce.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oerexchange\external\get_resource
 */
final class get_resource_test extends \advanced_testcase {
    public function test_unpublished_resource_is_rejected(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $resourceid = $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'Hidden', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => 2, 'siteid' => 1, 'status' => 'hidden',
            'downloadcount' => 0, 'importcount' => 0, 'forkedfromid' => null,
            'timeshared' => time(), 'timemodified' => time(),
        ]);

        $this->expectException(\moodle_exception::class);
        get_resource::execute($resourceid);
    }

    public function test_published_resource_without_ready_version_has_no_downloadurl(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $resourceid = $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'Still parsing', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => 2, 'siteid' => 1, 'status' => 'published',
            'downloadcount' => 0, 'importcount' => 0, 'forkedfromid' => null,
            'timeshared' => time(), 'timemodified' => time(),
        ]);

        $result = get_resource::execute($resourceid);

        $this->assertSame('', $result['downloadurl']);
        $this->assertSame(-1, $result['versionid']);
        // No ready version was touched, so the download counter must not move.
        $this->assertSame(0, (int) $DB->get_field('local_oerexchange_resources', 'downloadcount', ['id' => $resourceid]));
    }

    public function test_published_resource_with_ready_version_returns_signed_url_and_increments_downloadcount(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $resourceid = $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'Ready one', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => 2, 'siteid' => 1, 'status' => 'published',
            'downloadcount' => 3, 'importcount' => 0, 'forkedfromid' => null,
            'timeshared' => time(), 'timemodified' => time(),
        ]);
        $versionid = $DB->insert_record('local_oerexchange_versions', (object) [
            'resourceid' => $resourceid, 'versionnumber' => 1, 'itemid' => 0,
            'filename' => 'a.mbz', 'filesize' => 1, 'status' => 'ready', 'timecreated' => time(),
        ]);

        $result = get_resource::execute($resourceid);

        $this->assertSame($versionid, $result['versionid']);
        $this->assertNotSame('', $result['downloadurl']);
        $this->assertStringContainsString('download.php', $result['downloadurl']);
        $this->assertSame(4, (int) $DB->get_field('local_oerexchange_resources', 'downloadcount', ['id' => $resourceid]));
    }

    public function test_visible_reviews_are_included_hidden_ones_are_not(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $resourceid = $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'Reviewed', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => 2, 'siteid' => 1, 'status' => 'published',
            'downloadcount' => 0, 'importcount' => 0, 'forkedfromid' => null,
            'timeshared' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_oerexchange_reviews', (object) [
            'resourceid' => $resourceid, 'userid' => 2, 'contexttext' => 'visible one',
            'adaptationtext' => '', 'outcometext' => '', 'rating' => 4, 'status' => 'visible',
            'timecreated' => time(),
        ]);
        $DB->insert_record('local_oerexchange_reviews', (object) [
            'resourceid' => $resourceid, 'userid' => 2, 'contexttext' => 'hidden one',
            'adaptationtext' => '', 'outcometext' => '', 'rating' => 1, 'status' => 'hidden',
            'timecreated' => time(),
        ]);

        $result = get_resource::execute($resourceid);

        $this->assertCount(1, $result['reviews']);
        $this->assertSame('visible one', $result['reviews'][0]['contexttext']);
    }

    public function test_creatorname_is_always_present_for_a_published_resource(): void {
        global $DB;
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user(['firstname' => 'Priya', 'lastname' => 'Nair']);
        $this->setUser($this->getDataGenerator()->create_user());

        $resourceid = $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'Has a creator', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => $creator->id, 'siteid' => 1, 'status' => 'published',
            'downloadcount' => 0, 'importcount' => 0, 'forkedfromid' => null,
            'timeshared' => time(), 'timemodified' => time(),
        ]);

        $result = get_resource::execute($resourceid);

        $this->assertSame('Priya Nair', $result['creatorname']);
    }

    public function test_creatorprofileurl_is_empty_when_creator_has_no_profile(): void {
        global $DB;
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $this->setUser($this->getDataGenerator()->create_user());

        $resourceid = $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'No profile yet', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => $creator->id, 'siteid' => 1, 'status' => 'published',
            'downloadcount' => 0, 'importcount' => 0, 'forkedfromid' => null,
            'timeshared' => time(), 'timemodified' => time(),
        ]);

        $result = get_resource::execute($resourceid);

        $this->assertSame('', $result['creatorprofileurl']);
    }

    public function test_creatorprofileurl_is_empty_when_creators_profile_is_hidden(): void {
        global $DB;
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        \local_oerexchange\local\profile_manager::get_or_create_for_user((int) $creator->id);
        \local_oerexchange\local\profile_manager::save((int) $creator->id, [
            'slug' => 'hiddenprofile', 'bio' => '', 'expertise' => [],
            'orcidurl' => '', 'linkedinurl' => '', 'researchmapurl' => '', 'visible' => false,
        ]);
        $this->setUser($this->getDataGenerator()->create_user());

        $resourceid = $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'Hidden profile creator', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => $creator->id, 'siteid' => 1, 'status' => 'published',
            'downloadcount' => 0, 'importcount' => 0, 'forkedfromid' => null,
            'timeshared' => time(), 'timemodified' => time(),
        ]);

        $result = get_resource::execute($resourceid);

        $this->assertSame('', $result['creatorprofileurl']);
    }

    public function test_creatorprofileurl_is_a_real_url_when_creators_profile_is_visible(): void {
        global $DB;
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        \local_oerexchange\local\profile_manager::get_or_create_for_user((int) $creator->id);
        \local_oerexchange\local\profile_manager::save((int) $creator->id, [
            'slug' => 'visibleprofile', 'bio' => '', 'expertise' => [],
            'orcidurl' => '', 'linkedinurl' => '', 'researchmapurl' => '', 'visible' => true,
        ]);
        $this->setUser($this->getDataGenerator()->create_user());

        $resourceid = $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'Visible profile creator', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => $creator->id, 'siteid' => 1, 'status' => 'published',
            'downloadcount' => 0, 'importcount' => 0, 'forkedfromid' => null,
            'timeshared' => time(), 'timemodified' => time(),
        ]);

        $result = get_resource::execute($resourceid);

        $this->assertNotSame('', $result['creatorprofileurl']);
        $this->assertStringContainsString('visibleprofile', $result['creatorprofileurl']);
    }
}
