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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for local_oerexchange_get_share_status, covering the fields a client
 * site needs in order to tell a teacher that the Exchange refused their
 * upload. A publish is acknowledged before it has been validated (parsing is
 * an adhoc task), so without these fields a client is told the share
 * succeeded and can never discover otherwise — the reason is only readable
 * on the Exchange's own moderation page.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(get_share_status::class)]
final class get_share_status_test extends \advanced_testcase {
    /**
     * Seed a resource owned by $userid with one version in the given state.
     *
     * @param int $userid creator
     * @param string $resourcestatus
     * @param string $versionstatus
     * @param string|null $parseerror
     * @return int resource id
     */
    protected function seed(
        int $userid,
        string $resourcestatus,
        string $versionstatus,
        ?string $parseerror = null
    ): int {
        global $DB;

        $resourceid = (int) $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => 'course', 'title' => 'A course', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-sa-4.0', 'activitytype' => null, 'courseformat' => null,
            'creatorid' => $userid, 'siteid' => null, 'status' => $resourcestatus,
            'downloadcount' => 0, 'importcount' => 0, 'forkedfromid' => null,
            'timeshared' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_oerexchange_versions', (object) [
            'resourceid' => $resourceid, 'versionnumber' => 1, 'itemid' => 0,
            'filename' => 'backup.mbz', 'filesize' => 0, 'status' => $versionstatus,
            'parseerror' => $parseerror, 'timecreated' => time(),
        ]);

        return $resourceid;
    }

    public function test_a_rejected_upload_reports_its_status_and_reason(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $reason = 'This backup appears to contain user data and cannot be published.';
        $resourceid = $this->seed(
            (int) $user->id,
            'pending',
            'failed',
            \local_oerexchange\task\parse_backup_task::AUTHOR_SAFE_MARKER . $reason
        );

        $result = get_share_status::execute($resourceid);

        $this->assertSame('failed', $result['versionstatus']);
        $this->assertSame($reason, $result['versionerror']);
        // The resource-level fields alone could never have told the client
        // WHY: 'pending' and not visible is also what a still-parsing upload
        // looks like.
        $this->assertSame('pending', $result['status']);
        $this->assertFalse($result['visible']);
    }

    public function test_a_healthy_share_reports_no_error(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $resourceid = $this->seed((int) $user->id, 'published', 'ready');

        $result = get_share_status::execute($resourceid);

        $this->assertSame('ready', $result['versionstatus']);
        $this->assertSame('', $result['versionerror']);
        $this->assertTrue($result['visible']);
    }

    public function test_a_still_parsing_upload_is_distinguishable_from_a_rejected_one(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $resourceid = $this->seed((int) $user->id, 'pending', 'parsing');

        $result = get_share_status::execute($resourceid);

        $this->assertSame('parsing', $result['versionstatus']);
        $this->assertSame('', $result['versionerror']);
    }

    public function test_the_newest_version_is_reported_not_the_served_one(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        // A published resource whose replacement upload was rejected: the
        // catalogue still serves version 1, but the author needs to hear
        // about version 2. Reporting the served version would say "ready"
        // and hide the failure completely.
        $resourceid = $this->seed((int) $user->id, 'published', 'ready');
        $DB->insert_record('local_oerexchange_versions', (object) [
            'resourceid' => $resourceid, 'versionnumber' => 2, 'itemid' => 0,
            'filename' => 'backup.mbz', 'filesize' => 0, 'status' => 'failed',
            'parseerror' => \local_oerexchange\task\parse_backup_task::AUTHOR_SAFE_MARKER . 'Rejected',
            'timecreated' => time(),
        ]);

        $result = get_share_status::execute($resourceid);

        $this->assertSame('failed', $result['versionstatus']);
        $this->assertSame('Rejected', $result['versionerror']);
        $this->assertTrue($result['visible'], 'the previous file is still being served');
    }

    public function test_an_internal_failure_does_not_leak_server_paths_to_the_client(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        // A real stored error from this platform. Core exception text carries
        // absolute filesystem paths, and this field crosses the network to
        // another site and is shown to a teacher there.
        $raw = 'Backup is missing XML file: /srv/lms/moodledata/temp/backup/info_from_mbz_123_ab/moodle_backup.xml';
        $resourceid = $this->seed((int) $user->id, 'pending', 'failed', $raw);

        $result = get_share_status::execute($resourceid);

        $this->assertSame('failed', $result['versionstatus']);
        $this->assertStringNotContainsString('/srv/', $result['versionerror']);
        $this->assertStringNotContainsString('moodledata', $result['versionerror']);
        $this->assertSame(
            get_string('error_uploadfailedgeneric', 'local_oerexchange'),
            $result['versionerror']
        );
    }

    public function test_the_user_data_rejection_reason_is_passed_through_verbatim(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        // The one message that must reach the teacher intact: it tells them
        // exactly how to fix the problem.
        $safe = \local_oerexchange\task\parse_backup_task::AUTHOR_SAFE_MARKER
            . get_string('error_sanitycheckfailed', 'local_oerexchange');
        $resourceid = $this->seed((int) $user->id, 'pending', 'failed', $safe);

        $result = get_share_status::execute($resourceid);

        $this->assertSame(
            get_string('error_sanitycheckfailed', 'local_oerexchange'),
            $result['versionerror'],
            'the marker is plumbing and must not reach the client'
        );
    }

    public function test_someone_elses_resource_is_refused(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();

        $resourceid = $this->seed((int) $owner->id, 'pending', 'failed', 'secret reason');

        $this->setUser($stranger);
        $this->expectException(\moodle_exception::class);
        get_share_status::execute($resourceid);
    }
}
