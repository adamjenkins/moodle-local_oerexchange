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
use local_oerexchange\local\badge_manager;

/**
 * Tests for badge_manager.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(badge_manager::class)]
final class badge_manager_test extends \advanced_testcase {
    /**
     * Insert a published resource for the given user with a given download count.
     *
     * @param int $userid
     * @param int $downloadcount
     * @return int id of the inserted resource
     */
    protected function seed_resource(int $userid, int $downloadcount): int {
        global $DB;
        $siteid = $DB->get_field('local_oerexchange_sites', 'id', []) ?: $DB->insert_record(
            'local_oerexchange_sites',
            (object) [
                'name' => 'S', 'url' => 'https://x', 'contact' => 'x@x.com', 'serviceuserid' => null,
                'status' => 'active', 'timecreated' => time(), 'timemodified' => time(),
            ]
        );
        return $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'A', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => $userid, 'siteid' => $siteid, 'status' => 'published',
            'downloadcount' => $downloadcount, 'importcount' => 0, 'forkedfromid' => null,
            'timeshared' => time(), 'timemodified' => time(),
        ]);
    }

    /**
     * Insert a visible review with a rating on the given resource, so that
     * profile_manager::get_metrics() computes a non-null avgrating for its
     * creator.
     *
     * @param int $resourceid
     * @param int $userid reviewer, arbitrary (rating table has no FK on it)
     * @param int $rating
     */
    protected function seed_review(int $resourceid, int $userid, int $rating): void {
        global $DB;
        $DB->insert_record('local_oerexchange_reviews', (object) [
            'resourceid' => $resourceid, 'userid' => $userid,
            'contexttext' => '', 'adaptationtext' => '', 'outcometext' => '',
            'rating' => $rating, 'status' => 'visible', 'timecreated' => time(),
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

    public function test_exactly_at_threshold_awards_badge(): void {
        $this->resetAfterTest();
        set_config('badge_trustedcontributor_minresources', 2, 'local_oerexchange');
        set_config('badge_trustedcontributor_mindownloads', 100, 'local_oerexchange');
        $user = $this->getDataGenerator()->create_user();
        $this->seed_resource((int) $user->id, 50);
        $this->seed_resource((int) $user->id, 50);

        $awarded = badge_manager::evaluate_and_award((int) $user->id);
        $this->assertSame(
            [badge_manager::BADGE_TRUSTED_CONTRIBUTOR],
            $awarded,
            'a metric exactly equal to its minimum threshold qualifies (inclusive boundary)'
        );
    }

    public function test_one_download_below_threshold_does_not_award(): void {
        $this->resetAfterTest();
        set_config('badge_trustedcontributor_minresources', 2, 'local_oerexchange');
        set_config('badge_trustedcontributor_mindownloads', 100, 'local_oerexchange');
        $user = $this->getDataGenerator()->create_user();
        $this->seed_resource((int) $user->id, 50);
        $this->seed_resource((int) $user->id, 49);

        $awarded = badge_manager::evaluate_and_award((int) $user->id);
        $this->assertSame([], $awarded, 'one download below the minimum must not qualify');
    }

    public function test_unset_config_falls_back_to_class_constant_defaults(): void {
        $this->resetAfterTest();
        // Deliberately no set_config() calls for any of the three threshold
        // settings: this exercises the get_config() === false path (a fresh
        // upgrade with admin_apply_default_settings() never run) that the
        // config_int()/config_float() fallback constants exist to cover.
        //
        // resourcecount = 9 (one below DEFAULT_MINRESOURCES = 10) is
        // deliberately the *only failing* check here: downloadtotal is raised
        // to 500, clearing its own DEFAULT_MINDOWNLOADS = 500 threshold, so
        // qualifies_for_trusted_contributor() cannot pass this case for the
        // wrong reason — if DEFAULT_MINRESOURCES ever regressed to a value
        // <= 9, the resourcecount check alone would no longer block
        // qualification, and this test would fail because downloadtotal no
        // longer blocks it either. This mirrors
        // test_unset_mindownloads_fallback_blocks_qualification() and
        // test_unset_minrating_fallback_blocks_qualification() below, which
        // isolate DEFAULT_MINDOWNLOADS and DEFAULT_MINRATING respectively by
        // the same technique: clear every other threshold so only the one
        // under test can be the blocker.
        $user = $this->getDataGenerator()->create_user();
        for ($i = 0; $i < 8; $i++) {
            $this->seed_resource((int) $user->id, 0);
        }
        $this->seed_resource((int) $user->id, 500);

        $awarded = badge_manager::evaluate_and_award((int) $user->id);
        $this->assertSame(
            [],
            $awarded,
            'a user below the DEFAULT_MINRESOURCES fallback threshold must not qualify when config is entirely unset'
        );
        $this->assertSame([], badge_manager::get_badges_for_user((int) $user->id));
    }

    public function test_unset_mindownloads_fallback_blocks_qualification(): void {
        $this->resetAfterTest();
        // Isolates DEFAULT_MINDOWNLOADS: resourcecount clears its own
        // threshold (10 resources, meeting DEFAULT_MINRESOURCES = 10) so the
        // resourcecount check does not short-circuit before downloadtotal is
        // ever compared. downloadtotal = 499, one below
        // DEFAULT_MINDOWNLOADS = 500. No reviews are seeded, so avgrating is
        // null and the rating check is inapplicable (see
        // qualifies_for_trusted_contributor()) — the only reason this user
        // fails to qualify is the DEFAULT_MINDOWNLOADS fallback. No
        // set_config() calls: this is the entirely-unset-config path.
        $user = $this->getDataGenerator()->create_user();
        for ($i = 0; $i < 9; $i++) {
            $this->seed_resource((int) $user->id, 0);
        }
        $this->seed_resource((int) $user->id, 499);

        $awarded = badge_manager::evaluate_and_award((int) $user->id);
        $this->assertSame(
            [],
            $awarded,
            'a user at/above DEFAULT_MINRESOURCES but one download below the DEFAULT_MINDOWNLOADS ' .
            'fallback threshold must not qualify when config is entirely unset'
        );
        $this->assertSame([], badge_manager::get_badges_for_user((int) $user->id));
    }

    public function test_unset_minrating_fallback_blocks_qualification(): void {
        $this->resetAfterTest();
        // Isolates DEFAULT_MINRATING: resourcecount (10) and downloadtotal
        // (500) both clear DEFAULT_MINRESOURCES = 10 and
        // DEFAULT_MINDOWNLOADS = 500, so neither of the earlier checks
        // short-circuits before the rating comparison is reached. A visible
        // review with rating = 3 makes avgrating = 3.0, which is below
        // DEFAULT_MINRATING = 4.0 — a null/absent average rating is NOT used
        // here because qualifies_for_trusted_contributor() treats a null
        // avgrating as "not applicable" (skips the check entirely), which
        // would make this case pass regardless of DEFAULT_MINRATING and fail
        // to isolate it. No set_config() calls: this is the
        // entirely-unset-config path.
        $user = $this->getDataGenerator()->create_user();
        $lastid = 0;
        for ($i = 0; $i < 10; $i++) {
            $lastid = $this->seed_resource((int) $user->id, 50);
        }
        $this->seed_review($lastid, (int) $user->id, 3);

        $awarded = badge_manager::evaluate_and_award((int) $user->id);
        $this->assertSame(
            [],
            $awarded,
            'a user at/above DEFAULT_MINRESOURCES and DEFAULT_MINDOWNLOADS but below the DEFAULT_MINRATING ' .
            'fallback threshold must not qualify when config is entirely unset'
        );
        $this->assertSame([], badge_manager::get_badges_for_user((int) $user->id));
    }

    public function test_one_resource_below_threshold_does_not_award(): void {
        $this->resetAfterTest();
        set_config('badge_trustedcontributor_minresources', 2, 'local_oerexchange');
        set_config('badge_trustedcontributor_mindownloads', 10, 'local_oerexchange');
        $user = $this->getDataGenerator()->create_user();
        $this->seed_resource((int) $user->id, 500);

        $awarded = badge_manager::evaluate_and_award((int) $user->id);
        $this->assertSame([], $awarded, 'one published resource below the minimum must not qualify');
    }
}
