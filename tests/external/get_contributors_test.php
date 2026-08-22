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

use local_oerexchange\local\contributor_list;

/**
 * Tests for the get_contributors AJAX endpoint: it renders through the one
 * server-side renderer, clamps what the browser asks for, and works for an
 * anonymous visitor because the pages that call it are public.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(get_contributors::class)]
final class get_contributors_test extends \advanced_testcase {
    /**
     * Seed one visible contributor with a single published course.
     *
     * @param string $firstname
     * @param string $lastname
     * @return \stdClass the user record
     */
    protected function seed_contributor(string $firstname, string $lastname): \stdClass {
        global $DB;

        $user = $this->getDataGenerator()->create_user([
            'firstname' => $firstname,
            'lastname' => $lastname,
        ]);
        $DB->insert_record('local_oerexchange_profiles', (object) [
            'userid' => (int) $user->id,
            'slug' => strtolower($firstname),
            'bio' => '',
            'expertise' => json_encode([]),
            'visible' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $siteid = $DB->get_field('local_oerexchange_sites', 'id', []) ?: $DB->insert_record(
            'local_oerexchange_sites',
            (object) [
                'name' => 'S', 'url' => 'https://x', 'contact' => 'x@x.com', 'serviceuserid' => null,
                'status' => 'active', 'timecreated' => time(), 'timemodified' => time(),
            ]
        );
        $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'T', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => (int) $user->id, 'siteid' => $siteid, 'status' => 'published',
            'downloadcount' => 0, 'importcount' => 0, 'forkedfromid' => null,
            'timeshared' => time(), 'timemodified' => time(),
        ]);

        return $user;
    }

    public function test_returns_rendered_html_for_a_sort(): void {
        $this->resetAfterTest();
        $this->seed_contributor('Aiko', 'Tanaka');

        $result = get_contributors::execute(contributor_list::SORT_RESOURCES, 10, 0, 'grid');

        $this->assertStringContainsString('Aiko Tanaka', $result['html']);
        $this->assertStringContainsString('row-cols-md-3', $result['html'], 'grid layout requested');
    }

    public function test_list_layout_differs_from_grid(): void {
        $this->resetAfterTest();
        $this->seed_contributor('Aiko', 'Tanaka');

        $list = get_contributors::execute(contributor_list::SORT_RESOURCES, 10, 0, 'list')['html'];

        $this->assertStringContainsString('oerexchange-contributors-list', $list);
        $this->assertStringNotContainsString('row-cols-md-3', $list);
    }

    public function test_bad_sort_is_normalised_not_fatal(): void {
        $this->resetAfterTest();
        $this->seed_contributor('Aiko', 'Tanaka');

        $result = get_contributors::execute('nonsense', 10, 0, 'list');

        $this->assertStringContainsString('Aiko Tanaka', $result['html']);
    }

    public function test_limit_is_clamped_to_perpage(): void {
        $this->resetAfterTest();
        for ($i = 0; $i < 3; $i++) {
            $this->seed_contributor('User' . $i, 'Test');
        }

        // An unbounded limit from the browser must not pull the whole table.
        $result = get_contributors::execute(contributor_list::SORT_RESOURCES, 99999, 0, 'grid');

        $this->assertIsString($result['html']);
        $this->assertLessThanOrEqual(
            contributor_list::PERPAGE,
            substr_count($result['html'], 'stretched-link')
        );
    }

    public function test_works_for_a_logged_out_visitor(): void {
        $this->resetAfterTest();
        $this->seed_contributor('Aiko', 'Tanaka');
        $this->setUser(null);

        $result = get_contributors::execute(contributor_list::SORT_RESOURCES, 10, 0, 'grid');

        $this->assertStringContainsString('Aiko Tanaka', $result['html']);
    }
}
