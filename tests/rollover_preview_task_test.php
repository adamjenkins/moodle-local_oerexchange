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

use local_oerexchange\local\allowlist\branch_editor;
use local_oerexchange\local\allowlist\dependency_walker;
use local_oerexchange\local\allowlist\entry_plan;
use local_oerexchange\local\allowlist\fake_component_locator;
use local_oerexchange\local\allowlist\fake_http_fetcher;
use local_oerexchange\local\allowlist\allowlist_zip_builder;
use local_oerexchange\local\allowlist\ingestor;
use local_oerexchange\local\allowlist\source_resolver;
use local_oerexchange\local\allowlist\zip_inspector;
use local_oerexchange\task\rollover_preview;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/oerexchange/tests/fixtures/fake_http_fetcher.php');
require_once($CFG->dirroot . '/local/oerexchange/tests/fixtures/fake_component_locator.php');
require_once($CFG->dirroot . '/local/oerexchange/tests/fixtures/allowlist_zip_builder.php');

/**
 * Tests for the background rollover lookup behind manage_allowlist.php.
 *
 * The page hands the task a download directory and later reads the plan back
 * out of it, so these pin down that hand-over: the plan arrives whole, and a
 * cancelled rollover (directory gone) writes nothing.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(rollover_preview::class)]
final class rollover_preview_task_test extends \advanced_testcase {
    public function test_the_task_leaves_the_plan_for_the_page(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();

        $locator = new fake_component_locator();
        $fetcher = new fake_http_fetcher();
        $this->listed('mod_thing', $locator, $fetcher);
        $url = 'https://directory.example.org/mod_thing.zip';
        $locator->urls['mod_thing'] = $url;
        $fetcher->zips[$url] = file_get_contents((new allowlist_zip_builder())->build('mod_thing', [], '[500, 502]'));

        $dir = random_string(20);
        $workdir = make_temp_directory(rollover_preview::TEMPDIR . $dir);
        $task = $this->task($locator, $fetcher);
        $task->set_custom_data(['from' => '5.2', 'to' => '5.0', 'dir' => $dir]);
        ob_start();
        $task->execute();
        ob_end_clean();

        $plan = rollover_preview::read_plan($workdir);
        $this->assertNotNull($plan);
        $this->assertCount(1, $plan->entries);
        $this->assertSame('mod_thing', $plan->entries[0]->component);
        // Already listed for 5.2, so confirming refreshes it onto 5.0.
        $this->assertSame(entry_plan::REFRESH, $plan->entries[0]->action);
        $this->assertSame(['5.0'], $plan->entries[0]->branches->branches);
        // The ZIP the plan points at is the one confirming will mirror.
        $this->assertStringStartsWith($CFG->tempdir . '/' . rollover_preview::TEMPDIR . $dir, $plan->entries[0]->meta->zipfilepath);
        $this->assertFileExists($plan->entries[0]->meta->zipfilepath);
        $this->assertFileDoesNotExist($workdir . '/' . rollover_preview::PLANFILE . '.part');

        remove_dir($workdir);
    }

    public function test_a_cancelled_rollover_looks_nothing_up(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();

        $locator = new fake_component_locator();
        $fetcher = new fake_http_fetcher();
        $this->listed('mod_thing', $locator, $fetcher);

        // The page removes the directory on Cancel; it is never made here.
        $dir = random_string(20);
        $task = $this->task($locator, $fetcher);
        $task->set_custom_data(['from' => '5.2', 'to' => '5.0', 'dir' => $dir]);
        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertSame([], $locator->requested);
        $this->assertDirectoryDoesNotExist($CFG->tempdir . '/' . rollover_preview::TEMPDIR . $dir);
    }

    public function test_a_plan_file_holding_another_class_is_refused(): void {
        $workdir = make_request_directory();
        file_put_contents($workdir . '/' . rollover_preview::PLANFILE, serialize(new \ArrayObject([1])));

        $this->assertNull(rollover_preview::read_plan($workdir));
        $this->assertNull(rollover_preview::read_plan(make_request_directory()));
    }

    /**
     * The task, with its branch editor kept off the network.
     *
     * @param fake_component_locator $locator
     * @param fake_http_fetcher $fetcher
     * @return rollover_preview
     */
    private function task(fake_component_locator $locator, fake_http_fetcher $fetcher): rollover_preview {
        return new class ($locator, $fetcher) extends rollover_preview {
            /**
             * Constructor.
             *
             * @param fake_component_locator $locator
             * @param fake_http_fetcher $fetcher
             */
            public function __construct(
                /** @var fake_component_locator where download URLs come from */
                private fake_component_locator $locator,
                /** @var fake_http_fetcher serves the ZIPs */
                private fake_http_fetcher $fetcher,
            ) {
            }

            #[\Override]
            protected function editor(): branch_editor {
                return new branch_editor($this->locator, new source_resolver($this->fetcher), new zip_inspector());
            }
        };
    }

    /**
     * Put a plugin on the allowlist for 5.2 the way a confirmed ingest would.
     *
     * @param string $component frankenstyle name
     * @param fake_component_locator $locator
     * @param fake_http_fetcher $fetcher
     * @return void
     */
    private function listed(string $component, fake_component_locator $locator, fake_http_fetcher $fetcher): void {
        $zip = (new allowlist_zip_builder())->build($component, [], '[502, 502]');
        $meta = (new zip_inspector())->inspect($zip, make_request_directory(), 'https://example.org/' . $component . '.zip');
        $walker = new dependency_walker(new source_resolver($fetcher), new zip_inspector(), $locator, ['5.2'], false);
        (new ingestor())->commit($walker->walk($meta, make_request_directory()));
        $locator->requested = [];
    }
}
