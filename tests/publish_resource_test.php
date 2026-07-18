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

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for local_oerexchange_publish_resource. Added on the fourth MDL
 * Shield audit pass (2026-07-19) — no WS-layer coverage existed for this
 * function before this pass, which is also the function with the audit's
 * one open (unfixed) trust-boundary finding: `siteid` is only checked for
 * "exists and active", never cross-checked against the calling user's own
 * link history (`classes/external/publish_resource.php`). A correct fix
 * needs a data-model change — `local_oerexchange\local\link_manager` mints
 * a generic per-user WS token with no durable siteid binding at all, and a
 * teacher legitimately linked to more than one client site can hold several
 * simultaneously-valid personal tokens, so "most recently linked site" is
 * not a safe proxy for "the site this specific call's token belongs to".
 * `test_siteid_is_not_cross_checked_against_caller_identity_known_gap()`
 * below documents the current (unfixed) behaviour explicitly so it stays
 * visible to the next audit pass rather than silently regressing further.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oerexchange\external\publish_resource
 */
final class publish_resource_test extends \advanced_testcase {
    /**
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

    public function test_execute_creates_a_new_resource_and_version(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $siteid = $this->create_site();
        $draftitemid = $this->create_draft_file((int) $user->id);

        $result = publish_resource::execute(
            $siteid, $draftitemid, 'course', 'My course', '', '', '', 'cc-4.0'
        );

        $this->assertGreaterThan(0, $result['resourceid']);
        $this->assertGreaterThan(0, $result['versionid']);

        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $result['resourceid']], '*', MUST_EXIST);
        $this->assertSame((int) $user->id, (int) $resource->creatorid, 'creatorid must always be $USER->id, never client-supplied');
        $this->assertSame('published', $resource->status);
    }

    public function test_execute_rejects_an_invalid_type(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $siteid = $this->create_site();
        $draftitemid = $this->create_draft_file((int) $user->id);

        $this->expectException(\moodle_exception::class);
        publish_resource::execute($siteid, $draftitemid, 'not-a-real-type', 'Title', '', '', '', 'cc-4.0');
    }

    public function test_execute_rejects_a_revoked_site(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $siteid = $this->create_site('revoked');
        $draftitemid = $this->create_draft_file((int) $user->id);

        $this->expectException(\moodle_exception::class);
        publish_resource::execute($siteid, $draftitemid, 'course', 'Title', '', '', '', 'cc-4.0');
    }

    public function test_execute_rejects_a_pending_site(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $siteid = $this->create_site('pending');
        $draftitemid = $this->create_draft_file((int) $user->id);

        $this->expectException(\moodle_exception::class);
        publish_resource::execute($siteid, $draftitemid, 'course', 'Title', '', '', '', 'cc-4.0');
    }

    public function test_adding_a_version_to_someone_elses_resource_is_rejected(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $siteid = $this->create_site();

        $this->setUser($owner);
        $ownerdraft = $this->create_draft_file((int) $owner->id);
        $result = publish_resource::execute($siteid, $ownerdraft, 'course', 'Owner course', '', '', '', 'cc-4.0');

        $intruder = $this->getDataGenerator()->create_user();
        $this->setUser($intruder);
        $intruderdraft = $this->create_draft_file((int) $intruder->id);

        $this->expectException(\moodle_exception::class);
        publish_resource::execute(
            $siteid, $intruderdraft, 'course', 'Hijacked version', '', '', '', 'cc-4.0', '', $result['resourceid']
        );
    }

    /**
     * Known gap, not a regression: documents current behaviour rather than
     * asserting it is correct. See the class docblock and the fourth MDL
     * Shield audit pass report (2026-07-19) for why this was reported, not
     * fixed, in that pass. If this test starts failing because the siteid is
     * now cross-checked against the caller's actual link history, that is
     * progress — delete/rewrite this test rather than "fixing" it back to
     * green.
     */
    public function test_siteid_is_not_cross_checked_against_caller_identity_known_gap(): void {
        global $DB;
        $this->resetAfterTest();

        // Two active, unrelated sites; the caller has no recorded connection
        // to either one (no local_oerexchange_linkcodes row at all) — yet
        // execute() accepts any of them as long as status=active.
        $unrelatedsiteid = $this->create_site();
        $anotherunrelatedsiteid = $this->create_site();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $draftitemid = $this->create_draft_file((int) $user->id);

        $result = publish_resource::execute(
            $unrelatedsiteid, $draftitemid, 'course', 'Attributed to an unrelated site', '', '', '', 'cc-4.0'
        );

        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $result['resourceid']], '*', MUST_EXIST);
        $this->assertSame(
            $unrelatedsiteid,
            (int) $resource->siteid,
            'current behaviour: siteid is stored verbatim from the client, with no check that $USER ever linked through it'
        );
        $this->assertNotSame($anotherunrelatedsiteid, (int) $resource->siteid);
    }
}
