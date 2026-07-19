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

use local_oerexchange\local\profile_manager;

/**
 * Tests for profile_manager.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oerexchange\local\profile_manager
 */
final class profile_manager_test extends \advanced_testcase {
    public function test_get_or_create_creates_a_profile_once(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['username' => 'janedoe']);

        $profile1 = profile_manager::get_or_create_for_user((int) $user->id);
        $profile2 = profile_manager::get_or_create_for_user((int) $user->id);

        $this->assertSame($profile1->id, $profile2->id);
        $this->assertSame(1, (int) $profile1->visible);
        $this->assertNotEmpty($profile1->slug);
    }

    public function test_get_by_slug_returns_null_when_missing(): void {
        $this->resetAfterTest();
        $this->assertNull(profile_manager::get_by_slug('nosuchslug'));
    }

    public function test_is_valid_slug_rejects_bad_input(): void {
        $this->resetAfterTest();
        $this->assertTrue(profile_manager::is_valid_slug('jane-doe_2'));
        $this->assertFalse(profile_manager::is_valid_slug(''));
        $this->assertFalse(profile_manager::is_valid_slug('has spaces'));
        $this->assertFalse(profile_manager::is_valid_slug('has/slash'));
    }

    public function test_save_rejects_slug_taken_by_another_user(): void {
        $this->resetAfterTest();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        profile_manager::get_or_create_for_user((int) $user1->id);
        profile_manager::save((int) $user1->id, ['slug' => 'takenslug', 'bio' => '', 'expertise' => [],
            'orcidurl' => '', 'linkedinurl' => '', 'researchmapurl' => '', 'visible' => true]);

        profile_manager::get_or_create_for_user((int) $user2->id);
        $this->expectException(\moodle_exception::class);
        profile_manager::save((int) $user2->id, ['slug' => 'takenslug', 'bio' => '', 'expertise' => [],
            'orcidurl' => '', 'linkedinurl' => '', 'researchmapurl' => '', 'visible' => true]);
    }

    public function test_save_allows_keeping_your_own_slug(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $profile = profile_manager::get_or_create_for_user((int) $user->id);

        profile_manager::save((int) $user->id, ['slug' => $profile->slug, 'bio' => 'Updated bio',
            'expertise' => ['biology'], 'orcidurl' => '', 'linkedinurl' => '', 'researchmapurl' => '',
            'visible' => true]);

        $updated = profile_manager::get_by_slug($profile->slug);
        $this->assertSame('Updated bio', $updated->bio);
        $this->assertSame(['biology'], json_decode($updated->expertise, true));
    }

    public function test_visible_false_still_returns_the_row_by_slug(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $profile = profile_manager::get_or_create_for_user((int) $user->id);
        profile_manager::save((int) $user->id, ['slug' => $profile->slug, 'bio' => '', 'expertise' => [],
            'orcidurl' => '', 'linkedinurl' => '', 'researchmapurl' => '', 'visible' => false]);

        $result = profile_manager::get_by_slug($profile->slug);
        $this->assertNotNull($result, 'get_by_slug does not itself enforce visibility — callers (route controllers) do');
        $this->assertSame(0, (int) $result->visible);
    }

    public function test_get_metrics_counts_published_resources_and_downloads(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $siteid = $DB->insert_record('local_oerexchange_sites', (object) [
            'name' => 'S', 'url' => 'https://x', 'contact' => 'x@x.com', 'serviceuserid' => null,
            'status' => 'active', 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $now = time();
        $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'A', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => $user->id, 'siteid' => $siteid, 'status' => 'published',
            'downloadcount' => 5, 'importcount' => 1, 'forkedfromid' => null,
            'timeshared' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'B', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => $user->id, 'siteid' => $siteid, 'status' => 'published',
            'downloadcount' => 10, 'importcount' => 2, 'forkedfromid' => null,
            'timeshared' => $now, 'timemodified' => $now,
        ]);

        $metrics = profile_manager::get_metrics((int) $user->id);
        $this->assertSame(2, $metrics['resourcecount']);
        $this->assertSame(15, $metrics['downloadtotal']);
        $this->assertNull($metrics['avgrating'], 'no reviews yet');
        $this->assertSame($now, $metrics['membersince']);
    }
}
