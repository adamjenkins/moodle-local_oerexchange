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
 * Tests for local_oerexchange_publish_resource. WS-layer coverage was added
 * on the fourth MDL Shield audit pass (2026-07-19), which also flagged (but
 * did not fix) a trust-boundary gap: `siteid` was only checked for "exists
 * and active", never cross-checked against the calling user's own link
 * history. A fifth pass closed this: publish_resource now requires a
 * completed ('used') local_oerexchange_linkcodes row for
 * ($USER->id, siteid) — see classes/external/publish_resource.php and
 * dev-docs for why "any completed handshake ever" (not "most recent") is
 * the safe check, given a teacher can legitimately hold several
 * simultaneously-valid personal tokens from linking to multiple sites.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(publish_resource::class)]
final class publish_resource_test extends \advanced_testcase {
    /**
     * Create a single-file draft area holding a fake .mbz for $userid.
     *
     * @param int $userid
     * @param string $contents
     * @return int the new draftitemid
     */
    protected function create_draft_file(int $userid, string $contents = 'x'): int {
        $draftitemid = \file_get_unused_draft_itemid();
        $fs = get_file_storage();
        $usercontext = \context_user::instance($userid);
        $fs->create_file_from_string([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'test.mbz',
        ], $contents);
        return $draftitemid;
    }

    /**
     * Create a registered client site with the given status.
     *
     * @param string $status
     * @return int the new site id
     */
    protected function create_site(string $status = 'active'): int {
        global $DB;

        return (int) $DB->insert_record('local_oerexchange_sites', (object) [
            'name' => 'Test site', 'url' => 'https://example.com', 'contact' => 'x@example.com',
            'serviceuserid' => null, 'status' => $status,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    /**
     * Record a completed account-linking handshake for ($userid, $siteid),
     * as link_manager::issue_code() + consume() would leave behind — this is
     * what publish_resource now requires before it will accept that siteid
     * from that caller.
     *
     * @param int $userid
     * @param int $siteid
     */
    protected function create_link(int $userid, int $siteid): void {
        global $DB;

        $now = time();
        $DB->insert_record('local_oerexchange_linkcodes', (object) [
            'code' => 'testcode_' . $userid . '_' . $siteid . '_' . random_string(8),
            'siteid' => $siteid,
            'userid' => $userid,
            'token' => '',
            'tokenid' => null,
            'status' => 'used',
            'timecreated' => $now,
            'timeexpires' => $now + 300,
        ]);
    }

    public function test_execute_creates_a_new_resource_and_version(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $siteid = $this->create_site();
        $this->create_link((int) $user->id, $siteid);
        $draftitemid = $this->create_draft_file((int) $user->id);

        $result = publish_resource::execute(
            $siteid,
            $draftitemid,
            'course',
            'My course',
            '',
            '',
            '',
            'cc-4.0'
        );

        $this->assertGreaterThan(0, $result['resourceid']);
        $this->assertGreaterThan(0, $result['versionid']);

        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $result['resourceid']], '*', MUST_EXIST);
        $this->assertSame((int) $user->id, (int) $resource->creatorid, 'creatorid must always be $USER->id, never client-supplied');
        // Task 2's validation-gap fix (SHARE-UPLOAD-PLAN.md Global Constraints):
        // a brand-new course/activity resource now starts 'pending', not
        // 'published' — publish_resource.php's call shape is explicitly IN
        // SCOPE for this behavior change, only its immediate-then-async
        // validation *timing* is otherwise unaffected. parse_backup_task
        // (Task 3) flips it to 'published' once structural validation
        // succeeds.
        $this->assertSame('pending', $resource->status);
    }

    public function test_execute_rejects_an_invalid_type(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $siteid = $this->create_site();
        $this->create_link((int) $user->id, $siteid);
        $draftitemid = $this->create_draft_file((int) $user->id);

        $this->expectException(\moodle_exception::class);
        publish_resource::execute($siteid, $draftitemid, 'not-a-real-type', 'Title', '', '', '', 'cc-4.0');
    }

    public function test_execute_rejects_a_revoked_site(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $siteid = $this->create_site('revoked');
        $this->create_link((int) $user->id, $siteid);
        $draftitemid = $this->create_draft_file((int) $user->id);

        $this->expectException(\moodle_exception::class);
        publish_resource::execute($siteid, $draftitemid, 'course', 'Title', '', '', '', 'cc-4.0');
    }

    public function test_execute_rejects_a_pending_site(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $siteid = $this->create_site('pending');
        $this->create_link((int) $user->id, $siteid);
        $draftitemid = $this->create_draft_file((int) $user->id);

        $this->expectException(\moodle_exception::class);
        publish_resource::execute($siteid, $draftitemid, 'course', 'Title', '', '', '', 'cc-4.0');
    }

    public function test_adding_a_version_to_someone_elses_resource_is_rejected(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $siteid = $this->create_site();
        $this->create_link((int) $owner->id, $siteid);

        $this->setUser($owner);
        $ownerdraft = $this->create_draft_file((int) $owner->id);
        $result = publish_resource::execute($siteid, $ownerdraft, 'course', 'Owner course', '', '', '', 'cc-4.0');

        $intruder = $this->getDataGenerator()->create_user();
        $this->create_link((int) $intruder->id, $siteid);
        $this->setUser($intruder);
        $intruderdraft = $this->create_draft_file((int) $intruder->id);

        $this->expectException(\moodle_exception::class);
        publish_resource::execute(
            $siteid,
            $intruderdraft,
            'course',
            'Hijacked version',
            '',
            '',
            '',
            'cc-4.0',
            '',
            $result['resourceid']
        );
    }

    /**
     * The trust-boundary fix itself: a caller with an active token but no
     * completed link to the claimed site must be rejected, even though the
     * site itself is perfectly valid (active) and the caller *is* linked to
     * a different site.
     */
    public function test_siteid_is_rejected_when_caller_never_linked_through_it(): void {
        $this->resetAfterTest();

        $linkedsiteid = $this->create_site();
        $unrelatedsiteid = $this->create_site();

        $user = $this->getDataGenerator()->create_user();
        $this->create_link((int) $user->id, $linkedsiteid);
        $this->setUser($user);
        $draftitemid = $this->create_draft_file((int) $user->id);

        $this->expectException(\moodle_exception::class);
        publish_resource::execute($unrelatedsiteid, $draftitemid, 'course', 'Spoofed attribution', '', '', '', 'cc-4.0');
    }

    /**
     * A teacher linked to multiple sites over time must be able to publish
     * attributed to any of them — "most recently linked" would be an unsafe
     * proxy here, so the check must accept any completed link, not just the
     * latest one.
     */
    public function test_a_teacher_linked_to_multiple_sites_may_publish_to_either(): void {
        $this->resetAfterTest();

        $firstsiteid = $this->create_site();
        $secondsiteid = $this->create_site();

        $user = $this->getDataGenerator()->create_user();
        $this->create_link((int) $user->id, $firstsiteid);
        $this->create_link((int) $user->id, $secondsiteid);
        $this->setUser($user);

        $draft1 = $this->create_draft_file((int) $user->id, 'first');
        $result1 = publish_resource::execute($firstsiteid, $draft1, 'course', 'From the first site', '', '', '', 'cc-4.0');
        $this->assertGreaterThan(0, $result1['resourceid']);

        $draft2 = $this->create_draft_file((int) $user->id, 'second');
        $result2 = publish_resource::execute($secondsiteid, $draft2, 'course', 'From the second site', '', '', '', 'cc-4.0');
        $this->assertGreaterThan(0, $result2['resourceid']);
    }
}
