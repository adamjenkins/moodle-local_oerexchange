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
 * Tests for the "only one version is ever served" rule.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(resource_manager::class)]
final class version_supersession_test extends \advanced_testcase {
    /**
     * Create a resource row.
     *
     * @param int $creatorid
     * @return int resource id
     */
    protected function make_resource(int $creatorid = 0): int {
        global $DB;

        return (int) $DB->insert_record('local_oerexchange_resources', (object) [
            'siteid' => 0,
            'creatorid' => $creatorid,
            'title' => 'A resource',
            'type' => 'course',
            'status' => 'published',
            'licenseshortname' => 'cc-4.0',
            'timeshared' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Create a version row with a real stored file behind it.
     *
     * @param int $resourceid
     * @param int $versionnumber
     * @param string $status
     * @return int version id
     */
    protected function make_version(int $resourceid, int $versionnumber, string $status = 'ready'): int {
        global $DB;

        $versionid = (int) $DB->insert_record('local_oerexchange_versions', (object) [
            'resourceid' => $resourceid,
            'versionnumber' => $versionnumber,
            'itemid' => 0,
            'filename' => 'backup.mbz',
            'filesize' => 4,
            'status' => $status,
            'timecreated' => time() + $versionnumber,
        ]);
        $DB->set_field('local_oerexchange_versions', 'itemid', $versionid, ['id' => $versionid]);

        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_oerexchange',
            'filearea' => 'resource',
            'itemid' => $versionid,
            'filepath' => '/',
            'filename' => 'backup.mbz',
        ], 'v' . $versionnumber);

        return $versionid;
    }

    /**
     * How many stored files a version currently has.
     *
     * @param int $versionid
     * @return int
     */
    protected function count_files(int $versionid): int {
        return count(get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_oerexchange',
            'resource',
            $versionid,
            'id',
            false
        ));
    }

    public function test_the_newest_ready_version_is_the_one_served(): void {
        $this->resetAfterTest();

        $resourceid = $this->make_resource();
        $this->make_version($resourceid, 1);
        $v2 = $this->make_version($resourceid, 2);

        $current = resource_manager::get_current_version($resourceid);
        $this->assertNotNull($current);
        $this->assertSame($v2, (int) $current->id);
    }

    /**
     * An update that is still parsing must not become "the current version" —
     * the previous good one keeps serving until the new one validates.
     */
    public function test_a_still_parsing_upload_does_not_displace_the_served_version(): void {
        $this->resetAfterTest();

        $resourceid = $this->make_resource();
        $v1 = $this->make_version($resourceid, 1, 'ready');
        $this->make_version($resourceid, 2, 'parsing');

        $current = resource_manager::get_current_version($resourceid);
        $this->assertSame($v1, (int) $current->id);
    }

    public function test_superseding_deletes_the_old_file_but_keeps_the_row(): void {
        global $DB;
        $this->resetAfterTest();

        $resourceid = $this->make_resource();
        $v1 = $this->make_version($resourceid, 1);
        $v2 = $this->make_version($resourceid, 2);

        $this->assertSame(1, resource_manager::supersede_old_versions($resourceid, $v2));

        // The row survives, so imports.versionid / trials.versionid still resolve.
        $this->assertTrue($DB->record_exists('local_oerexchange_versions', ['id' => $v1]));
        $this->assertSame('superseded', $DB->get_field('local_oerexchange_versions', 'status', ['id' => $v1]));
        $this->assertSame(0, $this->count_files($v1), 'the superseded file must be deleted');

        // The survivor is untouched.
        $this->assertSame('ready', $DB->get_field('local_oerexchange_versions', 'status', ['id' => $v2]));
        $this->assertSame(1, $this->count_files($v2));
    }

    /**
     * A superseded row has no file, so it must never be picked as the served
     * version — otherwise the resource page would offer a download that 404s.
     */
    public function test_a_superseded_version_is_never_served(): void {
        $this->resetAfterTest();

        $resourceid = $this->make_resource();
        $v1 = $this->make_version($resourceid, 1);
        $v2 = $this->make_version($resourceid, 2);
        resource_manager::supersede_old_versions($resourceid, $v2);

        $current = resource_manager::get_current_version($resourceid);
        $this->assertSame($v2, (int) $current->id);
        $this->assertNotSame($v1, (int) $current->id);
    }

    public function test_superseding_is_idempotent_and_never_touches_other_resources(): void {
        $this->resetAfterTest();

        $mine = $this->make_resource();
        $v1 = $this->make_version($mine, 1);
        $v2 = $this->make_version($mine, 2);

        $theirs = $this->make_resource();
        $other = $this->make_version($theirs, 1);

        $this->assertSame(1, resource_manager::supersede_old_versions($mine, $v2));
        $this->assertSame(0, resource_manager::supersede_old_versions($mine, $v2), 'second run must be a no-op');

        $this->assertSame(1, $this->count_files($other), 'another resource\'s version must be untouched');
        $this->assertSame(0, $this->count_files($v1));
    }

    /**
     * The upgrade step's entry point, for sites that already accumulated
     * multiple versions under the old add-a-version-every-time behaviour.
     */
    public function test_the_upgrade_sweep_leaves_exactly_one_served_version_per_resource(): void {
        $this->resetAfterTest();

        $a = $this->make_resource();
        $this->make_version($a, 1);
        $this->make_version($a, 2);
        $this->make_version($a, 3);

        $b = $this->make_resource();
        $this->make_version($b, 1);

        $this->assertSame(2, resource_manager::supersede_all_stale_versions());

        foreach ([$a, $b] as $resourceid) {
            $this->assertNotNull(resource_manager::get_current_version($resourceid));
        }
        $this->assertSame(3, (int) resource_manager::get_current_version($a)->versionnumber);
        $this->assertSame(1, (int) resource_manager::get_current_version($b)->versionnumber);
    }
}
