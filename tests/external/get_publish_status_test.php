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
 * Tests for local_oerexchange_get_publish_status, the function the author's
 * own browser polls while an upload is being validated.
 *
 * The behaviour that matters is the 'settled' flag: it is what stops the
 * poller, so a state wrongly reported as settled leaves the author looking at
 * "Checking your upload" forever, and a terminal state wrongly reported as
 * unsettled polls a busy site until the timeout.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(get_publish_status::class)]
final class get_publish_status_test extends \advanced_testcase {
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

    public function test_a_parsing_upload_is_not_settled(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = get_publish_status::execute($this->seed((int) $user->id, 'pending', 'parsing'));

        $this->assertFalse($result['settled']);
        $this->assertFalse($result['published']);
        $this->assertSame('', $result['error']);
    }

    public function test_a_published_upload_is_settled_and_published(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = get_publish_status::execute($this->seed((int) $user->id, 'published', 'ready'));

        $this->assertTrue($result['settled']);
        $this->assertTrue($result['published']);
        $this->assertSame('', $result['error']);
    }

    public function test_a_rejected_upload_reports_the_author_facing_reason(): void {
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

        $result = get_publish_status::execute($resourceid);

        $this->assertTrue($result['settled']);
        $this->assertFalse($result['published']);
        $this->assertSame($reason, $result['error']);
    }

    public function test_a_raw_parse_error_is_never_handed_to_the_author(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        // No author-safe marker: this is a core exception message, which
        // carries absolute server paths. The author gets the generic string.
        $resourceid = $this->seed(
            (int) $user->id,
            'pending',
            'failed',
            'Exception in /srv/lms/moodle/public/backup/util/helper.php line 42'
        );

        $result = get_publish_status::execute($resourceid);

        $this->assertStringNotContainsString('/srv/', $result['error']);
        $this->assertSame(get_string('error_uploadfailedgeneric', 'local_oerexchange'), $result['error']);
    }

    public function test_someone_elses_pending_resource_is_refused(): void {
        $this->resetAfterTest();
        $author = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();

        // Pending means "not in the catalogue yet", so this is exactly the
        // state that must not become readable to anyone who guesses an id.
        $resourceid = $this->seed((int) $author->id, 'pending', 'parsing');

        $this->setUser($stranger);
        $this->expectException(\moodle_exception::class);
        get_publish_status::execute($resourceid);
    }

    public function test_a_coauthor_may_watch_the_upload(): void {
        global $DB;
        $this->resetAfterTest();
        $author = $this->getDataGenerator()->create_user();
        $coauthor = $this->getDataGenerator()->create_user();

        $resourceid = $this->seed((int) $author->id, 'pending', 'parsing');
        $DB->insert_record('local_oerexchange_coauthors', (object) [
            'resourceid' => $resourceid,
            'userid' => $coauthor->id,
            'addedby' => $author->id,
            'timecreated' => time(),
        ]);

        $this->setUser($coauthor);
        $result = get_publish_status::execute($resourceid);

        $this->assertFalse($result['settled']);
        $this->assertSame($resourceid, $result['resourceid']);
    }

    public function test_the_newest_version_decides_not_the_served_one(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        // A published resource whose "Replace the file" upload is still being
        // validated: the poller must keep polling. Reading the served version
        // would report 'ready' and stop, leaving the author believing the
        // replacement had gone through.
        $resourceid = $this->seed((int) $user->id, 'published', 'ready');
        $DB->insert_record('local_oerexchange_versions', (object) [
            'resourceid' => $resourceid, 'versionnumber' => 2, 'itemid' => 0,
            'filename' => 'backup.mbz', 'filesize' => 0, 'status' => 'parsing',
            'parseerror' => null, 'timecreated' => time(),
        ]);

        $result = get_publish_status::execute($resourceid);

        $this->assertSame('parsing', $result['versionstatus']);
        $this->assertFalse($result['settled']);
        $this->assertFalse($result['published']);
    }
}
