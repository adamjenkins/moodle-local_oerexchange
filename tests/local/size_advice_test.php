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
 * Tests for the sandbox size advice: when a backup is big enough that the
 * in-browser trial stops reporting progress, and how the catalogue finds each
 * resource's size in one query.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(size_advice::class)]
final class size_advice_test extends \advanced_testcase {
    /**
     * Seed a resource with versions of the given (status => filesize) shape.
     *
     * @param array ...$versions each ['status' => string, 'filesize' => int], in version order
     * @return int resource id
     */
    protected function seed_resource(array ...$versions): int {
        global $DB;

        $resourceid = (int) $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'A course', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-sa-4.0', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => 0, 'siteid' => null, 'status' => 'published',
            'downloadcount' => 0, 'importcount' => 0, 'forkedfromid' => null,
            'timeshared' => time(), 'timemodified' => time(),
        ]);
        $versionnumber = 0;
        foreach ($versions as $version) {
            $versionnumber++;
            $DB->insert_record('local_oerexchange_versions', (object) [
                'resourceid' => $resourceid, 'versionnumber' => $versionnumber, 'itemid' => 0,
                'filename' => 'backup.mbz', 'filesize' => $version['filesize'],
                'status' => $version['status'], 'parseerror' => null, 'timecreated' => time(),
            ]);
        }

        return $resourceid;
    }

    public function test_the_default_threshold_is_the_sandbox_engines_own_budget(): void {
        $this->resetAfterTest();

        // 50 MiB is MAX_BROWSER_BACKUP_BYTES in the playground engine's
        // moodle-restore.js — the exact size above which a trial's download
        // stops reporting progress. Pinned here because the whole point of
        // the warning is that it fires at that boundary and not somewhere
        // arbitrary.
        $this->assertSame(50 * 1024 * 1024, size_advice::DEFAULT_WARN_BYTES);
        $this->assertSame(50 * 1024 * 1024, size_advice::warn_threshold());
    }

    public function test_a_backup_at_the_threshold_is_not_called_slow(): void {
        $this->resetAfterTest();

        $this->assertFalse(size_advice::is_slow_trial(50 * 1024 * 1024));
        $this->assertTrue(size_advice::is_slow_trial(50 * 1024 * 1024 + 1));
    }

    public function test_the_live_359mb_resource_is_called_slow(): void {
        $this->resetAfterTest();

        // The measured case that prompted this: 376,453,940 bytes took
        // 4 min 15 s to boot, nearly all of it a silent download.
        $this->assertTrue(size_advice::is_slow_trial(376453940));
    }

    public function test_a_zero_threshold_switches_the_warning_off(): void {
        $this->resetAfterTest();

        set_config('sandboxwarnbytes', 0, 'local_oerexchange');

        // Not the `?: default` idiom's answer — a configured 0 means "never
        // warn", and must not silently fall back to 50 MiB.
        $this->assertSame(0, size_advice::warn_threshold());
        $this->assertFalse(size_advice::is_slow_trial(376453940));
    }

    public function test_a_configured_threshold_is_honoured(): void {
        $this->resetAfterTest();

        set_config('sandboxwarnbytes', 1024, 'local_oerexchange');

        $this->assertTrue(size_advice::is_slow_trial(2048));
        $this->assertFalse(size_advice::is_slow_trial(512));
    }

    public function test_no_trial_cap_by_default(): void {
        $this->resetAfterTest();

        // An upgrading site must keep the button it already has: a large
        // trial is slow, not broken (359 MiB booted in 4 min 15 s).
        $this->assertSame(0, size_advice::max_trial_bytes());
        $this->assertFalse(size_advice::is_trial_blocked(376453940));
    }

    public function test_a_configured_trial_cap_blocks_above_it_only(): void {
        $this->resetAfterTest();

        set_config('sandboxmaxbytes', 200 * 1024 * 1024, 'local_oerexchange');

        $this->assertTrue(size_advice::is_trial_blocked(376453940));
        $this->assertFalse(size_advice::is_trial_blocked(200 * 1024 * 1024));
        $this->assertFalse(size_advice::is_trial_blocked(1024));
    }

    public function test_the_upload_limit_defaults_to_500mb(): void {
        $this->resetAfterTest();

        $this->assertSame(500 * 1024 * 1024, size_advice::DEFAULT_MAX_UPLOAD_BYTES);
        $this->assertSame(500 * 1024 * 1024, size_advice::max_upload_bytes());
    }

    public function test_a_zero_upload_limit_means_the_default_not_unlimited(): void {
        $this->resetAfterTest();

        // The `?:` this helper replaced has always behaved this way; a site
        // sitting on a stored 0 must not silently become unlimited.
        set_config('maxbackupbytes', 0, 'local_oerexchange');

        $this->assertSame(size_advice::DEFAULT_MAX_UPLOAD_BYTES, size_advice::max_upload_bytes());
    }

    public function test_the_upload_limit_is_the_one_publish_enforces(): void {
        $this->resetAfterTest();
        // The get_config function is called over a web-service token, i.e.
        // always as a real user; validate_context() refuses an anonymous one.
        $this->setUser($this->getDataGenerator()->create_user());

        set_config('maxbackupbytes', 1024, 'local_oerexchange');

        // Same source of truth as resource_manager::publish() and the
        // get_config web service — the three used to carry their own copy of
        // the expression, so a changed setting could be advertised and
        // enforced differently.
        $this->assertSame(1024, size_advice::max_upload_bytes());
        $this->assertSame(
            size_advice::max_upload_bytes(),
            \local_oerexchange\external\get_config::execute()['maxbackupbytes']
        );
    }

    public function test_sizes_for_reports_the_newest_ready_version(): void {
        $this->resetAfterTest();

        // The catalogue must show the size of the file it would actually
        // serve: the newest READY version, not the first one and not a
        // replacement that is still being validated.
        $resourceid = $this->seed_resource(
            ['status' => 'superseded', 'filesize' => 100],
            ['status' => 'ready', 'filesize' => 200],
            ['status' => 'parsing', 'filesize' => 999999],
        );

        $sizes = size_advice::sizes_for([$resourceid]);

        $this->assertSame(200, $sizes[$resourceid]);
    }

    public function test_sizes_for_skips_a_resource_with_nothing_ready(): void {
        $this->resetAfterTest();

        $pending = $this->seed_resource(['status' => 'parsing', 'filesize' => 4242]);

        $this->assertArrayNotHasKey($pending, size_advice::sizes_for([$pending]));
    }

    public function test_sizes_for_handles_an_empty_list(): void {
        $this->resetAfterTest();

        $this->assertSame([], size_advice::sizes_for([]));
    }
}
