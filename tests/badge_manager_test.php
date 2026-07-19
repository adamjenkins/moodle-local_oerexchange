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

use local_oerexchange\local\badge_manager;

/**
 * Tests for badge_manager.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oerexchange\local\badge_manager
 */
final class badge_manager_test extends \advanced_testcase {
    /**
     * Insert a published resource for the given user with a given download count.
     *
     * @param int $userid
     * @param int $downloadcount
     */
    protected function seed_resource(int $userid, int $downloadcount): void {
        global $DB;
        $siteid = $DB->get_field('local_oerexchange_sites', 'id', []) ?: $DB->insert_record(
            'local_oerexchange_sites',
            (object) [
                'name' => 'S', 'url' => 'https://x', 'contact' => 'x@x.com', 'serviceuserid' => null,
                'status' => 'active', 'timecreated' => time(), 'timemodified' => time(),
            ]
        );
        $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'A', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => $userid, 'siteid' => $siteid, 'status' => 'published',
            'downloadcount' => $downloadcount, 'importcount' => 0, 'forkedfromid' => null,
            'timeshared' => time(), 'timemodified' => time(),
        ]);
    }

    public function test_below_threshold_awards_nothing(): void {
        $this->resetAfterTest();
        set_config('badge_trustedcontributor_minresources', 10, 'local_oerexchange');
        set_config('badge_trustedcontributor_mindownloads', 500, 'local_oerexchange');
        $user = $this->getDataGenerator()->create_user();
        $this->seed_resource((int) $user->id, 5);

        $awarded = badge_manager::evaluate_and_award((int) $user->id);
        $this->assertSame([], $awarded);
        $this->assertSame([], badge_manager::get_badges_for_user((int) $user->id));
    }

    public function test_meeting_threshold_awards_trusted_contributor(): void {
        $this->resetAfterTest();
        set_config('badge_trustedcontributor_minresources', 1, 'local_oerexchange');
        set_config('badge_trustedcontributor_mindownloads', 10, 'local_oerexchange');
        $user = $this->getDataGenerator()->create_user();
        $this->seed_resource((int) $user->id, 500);

        $awarded = badge_manager::evaluate_and_award((int) $user->id);
        $this->assertSame([badge_manager::BADGE_TRUSTED_CONTRIBUTOR], $awarded);
        $this->assertSame([badge_manager::BADGE_TRUSTED_CONTRIBUTOR], badge_manager::get_badges_for_user((int) $user->id));
    }

    public function test_award_is_idempotent(): void {
        $this->resetAfterTest();
        set_config('badge_trustedcontributor_minresources', 1, 'local_oerexchange');
        set_config('badge_trustedcontributor_mindownloads', 10, 'local_oerexchange');
        $user = $this->getDataGenerator()->create_user();
        $this->seed_resource((int) $user->id, 500);

        badge_manager::evaluate_and_award((int) $user->id);
        $second = badge_manager::evaluate_and_award((int) $user->id);

        $this->assertSame([], $second, 'a badge already held is never re-reported as newly awarded');
        $this->assertSame([badge_manager::BADGE_TRUSTED_CONTRIBUTOR], badge_manager::get_badges_for_user((int) $user->id));
    }

    public function test_badge_is_never_revoked_once_metrics_drop(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('badge_trustedcontributor_minresources', 1, 'local_oerexchange');
        set_config('badge_trustedcontributor_mindownloads', 10, 'local_oerexchange');
        $user = $this->getDataGenerator()->create_user();
        $this->seed_resource((int) $user->id, 500);
        badge_manager::evaluate_and_award((int) $user->id);

        $DB->set_field('local_oerexchange_resources', 'downloadcount', 0, ['creatorid' => $user->id]);
        badge_manager::evaluate_and_award((int) $user->id);

        $this->assertSame(
            [badge_manager::BADGE_TRUSTED_CONTRIBUTOR],
            badge_manager::get_badges_for_user((int) $user->id),
            'v1 never auto-revokes (design doc: a stale badge is a smaller UX problem than a visible demotion)'
        );
    }
}
