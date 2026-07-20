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

/**
 * Tests for profile_manager.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_oerexchange;

use local_oerexchange\local\profile_manager;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/oerexchange/tests/fixtures/racing_db_stub.php');

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

    public function test_get_by_userid_returns_null_when_missing(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->assertNull(profile_manager::get_by_userid((int) $user->id));
    }

    public function test_get_by_userid_returns_the_matching_profile(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $created = profile_manager::get_or_create_for_user((int) $user->id);

        $found = profile_manager::get_by_userid((int) $user->id);

        $this->assertNotNull($found);
        $this->assertSame($created->id, $found->id);
        $this->assertSame($created->slug, $found->slug);
    }

    public function test_get_by_userids_returns_a_map_keyed_by_userid_omitting_users_with_no_profile(): void {
        $this->resetAfterTest();
        $withprofile = $this->getDataGenerator()->create_user();
        $withoutprofile = $this->getDataGenerator()->create_user();
        profile_manager::get_or_create_for_user((int) $withprofile->id);

        $result = profile_manager::get_by_userids([(int) $withprofile->id, (int) $withoutprofile->id]);

        $this->assertArrayHasKey((int) $withprofile->id, $result);
        $this->assertArrayNotHasKey((int) $withoutprofile->id, $result);
    }

    public function test_get_by_userids_with_empty_array_returns_empty_array(): void {
        $this->resetAfterTest();
        $this->assertSame([], profile_manager::get_by_userids([]));
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

    public function test_save_converts_a_lost_slug_race_to_error_slugtaken(): void {
        global $DB;
        $this->resetAfterTest();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        profile_manager::get_or_create_for_user((int) $user1->id);
        profile_manager::get_or_create_for_user((int) $user2->id);

        // Simulate user1's save() winning a race for 'raceslug' in the window
        // between user2's slug_available() check and user2's update_record():
        // the conflicting row is inserted directly, via the real $DB, right
        // before user2's save() runs.
        $DB->set_field('local_oerexchange_profiles', 'slug', 'raceslug', ['userid' => $user1->id]);

        $realdb = $DB;
        $DB = new racing_db_stub($realdb, 'local_oerexchange_profiles', ['slug' => 'raceslug']);
        try {
            profile_manager::save((int) $user2->id, ['slug' => 'raceslug', 'bio' => '', 'expertise' => [],
                'orcidurl' => '', 'linkedinurl' => '', 'researchmapurl' => '', 'visible' => true]);
            $this->fail('Expected a moodle_exception (error_slugtaken) from the lost race.');
        } catch (\moodle_exception $e) {
            $this->assertSame(
                'error_slugtaken',
                $e->errorcode,
                'The race loser must see the same documented exception as the common-case slug-taken check.'
            );
        } finally {
            $DB = $realdb;
        }

        // No corruption: 'raceslug' still resolves only to user1 (the race
        // winner), and user2's own profile row is untouched.
        $winner = profile_manager::get_by_slug('raceslug');
        $this->assertSame((int) $user1->id, (int) $winner->userid);
        $user2profile = $DB->get_record('local_oerexchange_profiles', ['userid' => $user2->id]);
        $this->assertNotSame('raceslug', $user2profile->slug);
    }

    public function test_get_or_create_for_user_recovers_from_a_lost_userid_race(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        // Simulate a concurrent get_or_create_for_user() call for the SAME
        // user winning the race in the window between our existence check
        // and our own insert_record(): the row it would have created is
        // inserted directly, via the real $DB, right before we call the
        // method under test.
        $now = time();
        $winnerslug = 'racewinner';
        $winnerid = $DB->insert_record('local_oerexchange_profiles', (object) [
            'userid' => (int) $user->id,
            'slug' => $winnerslug,
            'bio' => '',
            'expertise' => json_encode([]),
            'orcidurl' => '',
            'linkedinurl' => '',
            'researchmapurl' => '',
            'visible' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $realdb = $DB;
        $DB = new racing_db_stub($realdb, 'local_oerexchange_profiles', ['userid' => (int) $user->id]);
        try {
            $profile = profile_manager::get_or_create_for_user((int) $user->id);
        } finally {
            $DB = $realdb;
        }

        // A get-OR-create must return what won the race, not throw.
        $this->assertSame($winnerid, (int) $profile->id);
        $this->assertSame($winnerslug, $profile->slug);
        $this->assertSame(
            1,
            $DB->count_records('local_oerexchange_profiles', ['userid' => (int) $user->id]),
            'Losing the race must not leave a duplicate/orphaned profile row.'
        );
    }
}
