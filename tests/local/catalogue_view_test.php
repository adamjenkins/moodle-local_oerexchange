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
 * Tests for catalogue_view, the shared catalogue query + markup used by
 * both /local/oerexchange/index.php and the public-landing hook that
 * serves the same catalogue at the site root.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(catalogue_view::class)]
final class catalogue_view_test extends \advanced_testcase {
    /**
     * Insert a published resource. Mirrors badge_manager_test::seed_resource().
     *
     * @param string $title
     * @param string $type
     * @param string $status
     * @return int
     */
    protected function seed_resource(string $title, string $type = 'course', string $status = 'published'): int {
        global $DB;
        $siteid = $DB->get_field('local_oerexchange_sites', 'id', []) ?: $DB->insert_record(
            'local_oerexchange_sites',
            (object) [
                'name' => 'S', 'url' => 'https://x', 'contact' => 'x@x.com', 'serviceuserid' => null,
                'status' => 'active', 'timecreated' => time(), 'timemodified' => time(),
            ]
        );
        return $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => $type, 'title' => $title, 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => 0, 'siteid' => $siteid, 'status' => $status,
            'downloadcount' => 0, 'importcount' => 0, 'forkedfromid' => null,
            'timeshared' => time(), 'timemodified' => time(),
        ]);
    }

    public function test_lists_published_resources(): void {
        $this->resetAfterTest();
        $this->seed_resource('Astronomy Basics');

        $view = new catalogue_view('', '', '', '', 0);

        $this->assertSame(1, $view->get_total());
        $this->assertStringContainsString('Astronomy Basics', $view->render(new \moodle_url('/')));
    }

    public function test_excludes_unpublished_resources(): void {
        $this->resetAfterTest();
        $this->seed_resource('Hidden Draft', 'course', 'pending');

        $view = new catalogue_view('', '', '', '', 0);

        $this->assertSame(0, $view->get_total());
        $this->assertStringNotContainsString('Hidden Draft', $view->render(new \moodle_url('/')));
    }

    public function test_filters_by_type(): void {
        $this->resetAfterTest();
        $this->seed_resource('A Course', 'course');
        $this->seed_resource('An Activity', 'activity');

        $view = new catalogue_view('', 'activity', '', '', 0);

        $this->assertSame(1, $view->get_total());
        $html = $view->render(new \moodle_url('/'));
        $this->assertStringContainsString('An Activity', $html);
        $this->assertStringNotContainsString('A Course', $html);
    }

    public function test_filters_by_search_query(): void {
        $this->resetAfterTest();
        $this->seed_resource('Astronomy Basics');
        $this->seed_resource('Botany Basics');

        $view = new catalogue_view('Astronomy', '', '', '', 0);

        $this->assertSame(1, $view->get_total());
        $this->assertStringNotContainsString('Botany Basics', $view->render(new \moodle_url('/')));
    }

    public function test_shows_empty_message_when_catalogue_is_empty(): void {
        $this->resetAfterTest();

        $view = new catalogue_view('', '', '', '', 0);

        $this->assertStringContainsString(
            get_string('catalogueempty', 'local_oerexchange'),
            $view->render(new \moodle_url('/'))
        );
    }

    /**
     * The whole point of the refactor: when the catalogue is served at the
     * site root, its search form must post back to the site root, not to
     * /local/oerexchange/index.php - otherwise the first search throws the
     * visitor off the landing page.
     */
    public function test_render_uses_the_supplied_base_url_for_the_form_action(): void {
        $this->resetAfterTest();
        $this->seed_resource('Astronomy Basics');

        $html = (new catalogue_view('', '', '', '', 0))->render(new \moodle_url('/'));

        $this->assertMatchesRegularExpression('#<form[^>]+action="[^"]*/"#', $html);
        $this->assertStringNotContainsString('/local/oerexchange/index.php', $html);
    }

    public function test_render_uses_the_plugin_url_when_that_is_the_base(): void {
        $this->resetAfterTest();
        $this->seed_resource('Astronomy Basics');

        $html = (new catalogue_view('', '', '', '', 0))
            ->render(new \moodle_url('/local/oerexchange/index.php'));

        $this->assertStringContainsString('/local/oerexchange/index.php', $html);
    }
}
