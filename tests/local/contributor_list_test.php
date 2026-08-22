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
 * Tests for contributor_list: who counts as a contributor, and in what order.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(contributor_list::class)]
final class contributor_list_test extends \advanced_testcase {
    /**
     * Insert a resource row. Mirrors catalogue_view_test::seed_resource(),
     * with the creator and share time exposed because this class groups by
     * them.
     *
     * @param int $creatorid
     * @param string $status
     * @param string $type course|activity|data
     * @param int|null $timeshared
     * @return int the new resource id
     */
    protected function seed_resource(
        int $creatorid,
        string $status = 'published',
        string $type = 'activity',
        ?int $timeshared = null
    ): int {
        global $DB;

        $siteid = $DB->get_field('local_oerexchange_sites', 'id', []) ?: $DB->insert_record(
            'local_oerexchange_sites',
            (object) [
                'name' => 'S', 'url' => 'https://x', 'contact' => 'x@x.com', 'serviceuserid' => null,
                'status' => 'active', 'timecreated' => time(), 'timemodified' => time(),
            ]
        );
        $when = $timeshared ?? time();

        return $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => $type, 'title' => 'Test resource', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => $creatorid, 'siteid' => $siteid, 'status' => $status,
            'downloadcount' => 0, 'importcount' => 0, 'forkedfromid' => null,
            'timeshared' => $when, 'timemodified' => $when,
        ]);
    }

    /**
     * Give a user a profile row so they are eligible to appear in the listing.
     *
     * @param int $userid
     * @param int $visible 0 = the owner has hidden their profile
     * @return void
     */
    protected function seed_profile(int $userid, int $visible = 1): void {
        global $DB;

        $DB->insert_record('local_oerexchange_profiles', (object) [
            'userid' => $userid,
            'slug' => 'user' . $userid,
            'bio' => '',
            'expertise' => json_encode([]),
            'visible' => $visible,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Add a co-author row.
     *
     * @param int $resourceid
     * @param int $userid
     * @return void
     */
    protected function seed_coauthor(int $resourceid, int $userid): void {
        global $DB;

        $DB->insert_record('local_oerexchange_coauthors', (object) [
            'resourceid' => $resourceid,
            'userid' => $userid,
            'addedby' => $userid,
            'timecreated' => time(),
        ]);
    }

    public function test_creator_with_published_resource_is_listed(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->seed_profile((int) $user->id);
        $this->seed_resource((int) $user->id);

        $rows = contributor_list::get_contributors();

        $this->assertCount(1, $rows);
        $this->assertSame((int) $user->id, $rows[0]->userid);
        $this->assertSame(1, $rows[0]->resourcecount);
    }

    public function test_user_with_nothing_published_is_not_listed(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->seed_profile((int) $user->id);

        $this->assertSame([], contributor_list::get_contributors());
    }

    public function test_unpublished_statuses_are_excluded(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->seed_profile((int) $user->id);
        foreach (['pending', 'hidden', 'modhidden', 'removed', 'deleted'] as $status) {
            $this->seed_resource((int) $user->id, $status);
        }

        $this->assertSame([], contributor_list::get_contributors());
    }

    public function test_coauthor_is_counted_as_a_contributor(): void {
        $this->resetAfterTest();

        $creator = $this->getDataGenerator()->create_user();
        $coauthor = $this->getDataGenerator()->create_user();
        $this->seed_profile((int) $creator->id);
        $this->seed_profile((int) $coauthor->id);

        $resourceid = $this->seed_resource((int) $creator->id);
        $this->seed_coauthor($resourceid, (int) $coauthor->id);

        $byid = [];
        foreach (contributor_list::get_contributors() as $row) {
            $byid[$row->userid] = $row;
        }

        $this->assertArrayHasKey((int) $coauthor->id, $byid);
        $this->assertSame(1, $byid[(int) $coauthor->id]->resourcecount);
    }

    public function test_tombstoned_resource_creates_no_phantom_contributor(): void {
        $this->resetAfterTest();

        // GDPR erasure keeps the row with creatorid 0 and status 'deleted'.
        $this->seed_resource(0, 'deleted');

        $this->assertSame([], contributor_list::get_contributors());
    }

    public function test_hidden_profile_is_excluded(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->seed_profile((int) $user->id, 0);
        $this->seed_resource((int) $user->id);

        $this->assertSame([], contributor_list::get_contributors());
    }

    public function test_missing_profile_row_is_excluded(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->seed_resource((int) $user->id);

        $this->assertSame([], contributor_list::get_contributors());
    }

    public function test_deleted_and_suspended_users_are_excluded(): void {
        $this->resetAfterTest();

        $deleted = $this->getDataGenerator()->create_user(['deleted' => 1]);
        $suspended = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $this->seed_profile((int) $deleted->id);
        $this->seed_profile((int) $suspended->id);
        $this->seed_resource((int) $deleted->id);
        $this->seed_resource((int) $suspended->id);

        $this->assertSame([], contributor_list::get_contributors());
    }

    public function test_course_count_counts_only_course_type(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->seed_profile((int) $user->id);
        $this->seed_resource((int) $user->id, 'published', 'course');
        $this->seed_resource((int) $user->id, 'published', 'course');
        $this->seed_resource((int) $user->id, 'published', 'activity');
        $this->seed_resource((int) $user->id, 'published', 'data');

        $rows = contributor_list::get_contributors();

        $this->assertSame(4, $rows[0]->resourcecount);
        $this->assertSame(2, $rows[0]->coursecount);
    }

    public function test_sort_by_resources(): void {
        $this->resetAfterTest();

        $few = $this->getDataGenerator()->create_user();
        $many = $this->getDataGenerator()->create_user();
        $this->seed_profile((int) $few->id);
        $this->seed_profile((int) $many->id);
        $this->seed_resource((int) $few->id);
        for ($i = 0; $i < 3; $i++) {
            $this->seed_resource((int) $many->id);
        }

        $rows = contributor_list::get_contributors(contributor_list::SORT_RESOURCES);

        $this->assertSame((int) $many->id, $rows[0]->userid);
        $this->assertSame((int) $few->id, $rows[1]->userid);
    }

    public function test_sort_by_courses(): void {
        $this->resetAfterTest();

        $coursey = $this->getDataGenerator()->create_user();
        $activityy = $this->getDataGenerator()->create_user();
        $this->seed_profile((int) $coursey->id);
        $this->seed_profile((int) $activityy->id);
        $this->seed_resource((int) $coursey->id, 'published', 'course');
        for ($i = 0; $i < 5; $i++) {
            $this->seed_resource((int) $activityy->id, 'published', 'activity');
        }

        $rows = contributor_list::get_contributors(contributor_list::SORT_COURSES);

        $this->assertSame((int) $coursey->id, $rows[0]->userid);
    }

    public function test_sort_by_recent(): void {
        $this->resetAfterTest();

        $old = $this->getDataGenerator()->create_user();
        $new = $this->getDataGenerator()->create_user();
        $this->seed_profile((int) $old->id);
        $this->seed_profile((int) $new->id);
        $now = time();
        $this->seed_resource((int) $old->id, 'published', 'activity', $now - 10000);
        $this->seed_resource((int) $new->id, 'published', 'activity', $now);

        $rows = contributor_list::get_contributors(contributor_list::SORT_RECENT);

        $this->assertSame((int) $new->id, $rows[0]->userid);
    }

    public function test_unknown_sort_falls_back_to_resources(): void {
        $this->resetAfterTest();

        $this->assertSame(
            contributor_list::SORT_RESOURCES,
            contributor_list::normalise_sort('; DROP TABLE users')
        );
        $this->assertSame(
            contributor_list::SORT_COURSES,
            contributor_list::normalise_sort('courses')
        );
    }

    public function test_limit_and_offset(): void {
        $this->resetAfterTest();

        for ($i = 0; $i < 5; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $this->seed_profile((int) $user->id);
            // Distinct resource counts give a deterministic order.
            for ($j = 0; $j <= $i; $j++) {
                $this->seed_resource((int) $user->id);
            }
        }

        $page1 = contributor_list::get_contributors(contributor_list::SORT_RESOURCES, 2, 0);
        $page2 = contributor_list::get_contributors(contributor_list::SORT_RESOURCES, 2, 2);

        $this->assertCount(2, $page1);
        $this->assertCount(2, $page2);
        $this->assertSame(5, $page1[0]->resourcecount);
        $this->assertSame(3, $page2[0]->resourcecount);
        $this->assertSame(5, contributor_list::count_contributors());
    }

    public function test_get_cards_enriches_rows(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user(['firstname' => 'Aiko', 'lastname' => 'Tanaka']);
        $this->seed_profile((int) $user->id);
        $DB->set_field(
            'local_oerexchange_profiles',
            'expertise',
            json_encode(['Chemistry', 'JHS', 'Practicals', 'Dropped']),
            ['userid' => $user->id]
        );
        $DB->insert_record('local_oerexchange_badges', (object) [
            'userid' => (int) $user->id,
            'badgekey' => badge_manager::BADGE_TRUSTED_CONTRIBUTOR,
            'timeawarded' => time(),
        ]);
        $this->seed_resource((int) $user->id, 'published', 'course');

        $cards = contributor_list::get_cards(contributor_list::SORT_RESOURCES);

        $this->assertCount(1, $cards);
        $this->assertSame('Aiko Tanaka', $cards[0]->fullname);
        $this->assertSame(1, $cards[0]->coursecount);
        $this->assertSame([badge_manager::BADGE_TRUSTED_CONTRIBUTOR], $cards[0]->badges);
        $this->assertCount(3, $cards[0]->expertise, 'expertise is capped at 3 tags');
        $this->assertStringContainsString('user' . $user->id, $cards[0]->profileurl->out(false));
    }

    public function test_renderers_produce_one_link_per_card(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user(['firstname' => 'Aiko', 'lastname' => 'Tanaka']);
        $this->seed_profile((int) $user->id);
        $this->seed_resource((int) $user->id);

        $cards = contributor_list::get_cards(contributor_list::SORT_RESOURCES);

        foreach ([contributor_list::render_list($cards), contributor_list::render_grid($cards)] as $html) {
            $this->assertStringContainsString('Aiko Tanaka', $html);
            $this->assertStringContainsString('stretched-link', $html);
            $this->assertSame(
                1,
                substr_count($html, '<a '),
                'the whole card is one link: a second anchor would be announced twice'
            );
        }
    }

    public function test_empty_cards_render_an_empty_state(): void {
        $this->resetAfterTest();

        $this->assertStringContainsString(
            get_string('contributors_none', 'local_oerexchange'),
            contributor_list::render_list([])
        );
        $this->assertStringContainsString(
            get_string('contributors_none', 'local_oerexchange'),
            contributor_list::render_grid([])
        );
    }

    public function test_sort_form_offers_every_sort_and_keeps_other_params(): void {
        $this->resetAfterTest();

        $baseurl = new \moodle_url('/local/oerexchange/x.php', ['page' => 2]);
        $html = contributor_list::render_sort_form($baseurl, contributor_list::SORT_RECENT, 'region-1');

        foreach (contributor_list::sort_keys() as $key) {
            $this->assertStringContainsString(
                get_string('contributors_sort_' . $key, 'local_oerexchange'),
                $html
            );
        }
        $this->assertStringContainsString('data-target="region-1"', $html);
        $this->assertStringContainsString('name="page"', $html, 'other params must survive a sort');
        $this->assertStringContainsString('oerexchange-contributor-sortgo', $html, 'the no-JS submit must exist');
    }

    public function test_unknown_layout_falls_back_to_cards(): void {
        $this->resetAfterTest();

        $this->assertSame(
            contributor_list::LAYOUT_CARDS,
            contributor_list::normalise_layout('nonsense')
        );
        $this->assertSame(
            contributor_list::LAYOUT_LIST,
            contributor_list::normalise_layout('list')
        );
    }

    public function test_render_dispatches_on_layout(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user(['firstname' => 'Aiko', 'lastname' => 'Tanaka']);
        $this->seed_profile((int) $user->id);
        $this->seed_resource((int) $user->id);
        $cards = contributor_list::get_cards(contributor_list::SORT_RESOURCES);

        $ascards = contributor_list::render($cards, contributor_list::LAYOUT_CARDS);
        $aslist = contributor_list::render($cards, contributor_list::LAYOUT_LIST);

        $this->assertStringContainsString('row-cols-md-3', $ascards);
        $this->assertStringNotContainsString('row-cols-md-3', $aslist);
        $this->assertStringContainsString('oerexchange-contributors-list', $aslist);
        // Both views name the contributor and link the whole card exactly once.
        foreach ([$ascards, $aslist] as $html) {
            $this->assertStringContainsString('Aiko Tanaka', $html);
            $this->assertSame(1, substr_count($html, '<a '));
        }
    }

    public function test_sort_form_offers_both_views(): void {
        $this->resetAfterTest();

        $html = contributor_list::render_sort_form(
            new \moodle_url('/local/oerexchange/x.php'),
            contributor_list::SORT_RESOURCES,
            'region-1',
            contributor_list::LAYOUT_LIST
        );

        $this->assertStringContainsString(get_string('contributors_view_cards', 'local_oerexchange'), $html);
        $this->assertStringContainsString(get_string('contributors_view_list', 'local_oerexchange'), $html);
        $this->assertStringContainsString('data-region="oerexchange-contributor-view"', $html);
        $this->assertStringContainsString('selected="selected" value="list"', $html);
    }
}
