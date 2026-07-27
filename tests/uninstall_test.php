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

use PHPUnit\Framework\Attributes\CoversFunction;

/**
 * Tests for the uninstall hook. This plugin writes into two of core's own
 * tables — a dedicated service account per registered site ({user}) and the
 * web-service token minted against it ({external_tokens}) — which core's
 * uninstall cleanup knows nothing about, so dropping our tables used to
 * strand one suspended-looking account per site, with a live token and no
 * remaining record of which accounts were ours (MDL Shield self-audit,
 * class 7, 2026-07-27).
 *
 * The hook deletes accounts, so the "leave anything that is not obviously
 * ours alone" branch is tested as carefully as the happy path.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('xmldb_local_oerexchange_uninstall')]
final class uninstall_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        global $CFG;
        require_once($CFG->dirroot . '/local/oerexchange/db/uninstall.php');
    }

    /**
     * Insert a registration and the service account create_service_account()
     * would have minted for it.
     *
     * @param string|null $username override to simulate an account that is no longer ours
     * @return array [siteid, userid]
     */
    private function create_registered_site(?string $username = null): array {
        global $DB;

        $siteid = $DB->insert_record('local_oerexchange_sites', (object) [
            'name' => 'Their site', 'url' => 'https://theirs.example.net', 'contact' => 'a@example.com',
            'serviceuserid' => null, 'status' => 'active', 'timecreated' => time(), 'timemodified' => time(),
        ]);

        $user = $this->getDataGenerator()->create_user([
            'username' => $username ?? ('oersite_' . $siteid),
            'auth' => 'manual',
        ]);
        $DB->set_field('local_oerexchange_sites', 'serviceuserid', $user->id, ['id' => $siteid]);

        $DB->insert_record('external_tokens', (object) [
            'token' => 'tok' . $siteid . str_repeat('0', 20),
            'tokentype' => EXTERNAL_TOKEN_PERMANENT,
            'userid' => $user->id,
            'externalserviceid' => 1,
            'contextid' => \context_system::instance()->id,
            'creatorid' => 2,
            'timecreated' => time(),
            'validuntil' => 0,
        ]);

        return [(int) $siteid, (int) $user->id];
    }

    public function test_uninstall_deletes_the_service_account_and_its_tokens(): void {
        global $DB;

        [, $userid] = $this->create_registered_site();
        $this->assertEquals(1, $DB->count_records('external_tokens', ['userid' => $userid]));

        xmldb_local_oerexchange_uninstall();

        $this->assertEquals(0, $DB->count_records('external_tokens', ['userid' => $userid]), 'the live token is revoked');
        $this->assertEquals(
            1,
            $DB->get_field('user', 'deleted', ['id' => $userid]),
            'the account this plugin minted does not outlive the plugin'
        );
    }

    public function test_uninstall_leaves_an_account_that_is_no_longer_ours_alone(): void {
        global $DB;

        // Someone repointed serviceuserid at a real person, or renamed the
        // account. An orphaned account is recoverable; a deleted person is not.
        [, $userid] = $this->create_registered_site('a.real.person');

        xmldb_local_oerexchange_uninstall();

        $this->assertEquals(
            0,
            $DB->get_field('user', 'deleted', ['id' => $userid]),
            'an account whose username is not ours is never deleted'
        );
        // The token is still revoked: it grants access to this plugin's
        // service, which is going away regardless of who owns the account.
        $this->assertEquals(0, $DB->count_records('external_tokens', ['userid' => $userid]));
    }

    public function test_uninstall_handles_a_registration_that_never_reached_approval(): void {
        global $DB;

        // A serviceuserid stays null until an admin approves — the loop must
        // skip these rather than fall over looking up user id null.
        $DB->insert_record('local_oerexchange_sites', (object) [
            'name' => 'Unapproved', 'url' => 'https://pending.example.net', 'contact' => 'a@example.com',
            'serviceuserid' => null, 'status' => 'pending', 'timecreated' => time(), 'timemodified' => time(),
        ]);

        $this->assertTrue(xmldb_local_oerexchange_uninstall());
    }

    public function test_uninstall_purges_this_plugins_stored_files(): void {
        $fs = get_file_storage();
        $contextid = \context_system::instance()->id;
        $fs->create_file_from_string([
            'contextid' => $contextid, 'component' => 'local_oerexchange', 'filearea' => 'resource',
            'itemid' => 1, 'filepath' => '/', 'filename' => 'course.mbz',
        ], 'backup-bytes');

        xmldb_local_oerexchange_uninstall();

        $this->assertEmpty($fs->get_area_files($contextid, 'local_oerexchange', 'resource', 1, 'id', false));
    }
}
