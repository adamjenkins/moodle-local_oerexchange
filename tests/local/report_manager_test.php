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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for report_manager: one open report per user per resource or
 * collection (security audit 2026-10-28, F7). resource.php's report branch
 * and collection_controller::report() both file through it; the controller
 * path has its own test in collection_controller_test.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(report_manager::class)]
#[CoversClass(collection_view::class)]
final class report_manager_test extends \advanced_testcase {
    public function test_a_second_open_report_on_a_resource_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $resource = $this->getDataGenerator()->get_plugin_generator('local_oerexchange')->create_resource();
        $reporter = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $this->assertTrue(report_manager::report_resource((int) $resource->id, (int) $reporter->id, 'spam', 'One.'));
        $this->assertFalse(report_manager::report_resource((int) $resource->id, (int) $reporter->id, 'other', 'Two.'));
        $this->assertTrue(
            report_manager::report_resource((int) $resource->id, (int) $other->id, 'spam', 'Another reporter.'),
            'somebody else may still report it'
        );

        $this->assertSame(2, $DB->count_records('local_oerexchange_reports', ['resourceid' => $resource->id]));
        $row = $DB->get_record('local_oerexchange_reports', ['userid' => $reporter->id], '*', MUST_EXIST);
        $this->assertSame('spam', $row->type);
        $this->assertNull($row->collectionid);
    }

    /**
     * Once a moderator has dealt with the report, the same user may report
     * the item again.
     */
    public function test_a_resolved_report_does_not_block_a_new_one(): void {
        global $DB;
        $this->resetAfterTest();
        $resource = $this->getDataGenerator()->get_plugin_generator('local_oerexchange')->create_resource();
        $reporter = $this->getDataGenerator()->create_user();

        report_manager::report_resource((int) $resource->id, (int) $reporter->id, 'spam', 'One.');
        $DB->set_field('local_oerexchange_reports', 'status', 'resolved', ['userid' => $reporter->id]);

        $this->assertTrue(report_manager::report_resource((int) $resource->id, (int) $reporter->id, 'spam', 'Again.'));
    }

    public function test_reports_on_a_collection_are_limited_separately(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_oerexchange');
        $collection = $generator->create_collection();
        $reporter = $this->getDataGenerator()->create_user();

        $this->assertTrue(report_manager::report_collection((int) $collection->id, (int) $reporter->id, 'spam', 'One.'));
        $this->assertFalse(report_manager::report_collection((int) $collection->id, (int) $reporter->id, 'spam', 'Two.'));

        $row = $DB->get_record('local_oerexchange_reports', ['collectionid' => $collection->id], '*', MUST_EXIST);
        $this->assertNull($row->resourceid);
    }

    /**
     * The collection delete confirmation is handed to $OUTPUT->confirm(),
     * which does not escape it. A title saved with entity-encoded markup used
     * to be decoded back into live HTML there (audit F8).
     */
    public function test_the_collection_delete_confirmation_keeps_the_title_escaped(): void {
        $this->resetAfterTest();
        $collection = $this->getDataGenerator()->get_plugin_generator('local_oerexchange')->create_collection([
            'title' => '&lt;img src=x onerror=alert(1)&gt;',
        ]);

        $message = collection_view::delete_confirmation($collection);

        $this->assertStringNotContainsString('<img', $message);
        $this->assertStringContainsString('&lt;img', $message);
    }
}
