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

use PHPUnit\Framework\Attributes\CoversClass;
use local_oerexchange\task\parse_backup_task;

/**
 * Tests for parse_backup_task resource status transitions: 'pending' → 'published'
 * on successful parse, and 'pending' → stays 'pending' on failure.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(parse_backup_task::class)]
final class parse_backup_task_test extends \advanced_testcase {
    /**
     * Insert a resource + version row and stage the given fixture .mbz into
     * the version's 'resource' filearea, ready for parse_backup_task::execute().
     *
     * @param string $fixturefilename filename under tests/fixtures/
     * @param string $resourcestatus resource status ('pending'|'published')
     * @param string $type resource type ('course'|'activity')
     * @return array [resourceid, versionid]
     */
    protected function stage_version(
        string $fixturefilename,
        string $resourcestatus = 'pending',
        string $type = 'course'
    ): array {
        global $DB, $USER;

        $siteid = $DB->insert_record('local_oerexchange_sites', (object) [
            'name' => 'S', 'url' => 'https://x', 'contact' => 'x@x.com', 'serviceuserid' => null,
            'status' => 'active', 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $resourceid = (int) $DB->insert_record('local_oerexchange_resources', (object) [
            'type' => $type, 'title' => 'A', 'summary' => '', 'language' => '', 'tags' => '',
            'licenseshortname' => 'cc-4.0', 'activitytype' => $type === 'activity' ? 'forum' : null,
            'courseformat' => null, 'creatorid' => $USER->id, 'siteid' => $siteid,
            'status' => $resourcestatus, 'downloadcount' => 0, 'importcount' => 0,
            'forkedfromid' => null, 'timeshared' => time(), 'timemodified' => time(),
        ]);
        $versionid = (int) $DB->insert_record('local_oerexchange_versions', (object) [
            'resourceid' => $resourceid, 'versionnumber' => 1, 'itemid' => 0,
            'filename' => $fixturefilename, 'filesize' => 0, 'status' => 'parsing',
            'timecreated' => time(),
        ]);

        $fs = get_file_storage();
        $context = \context_system::instance();
        $fs->create_file_from_pathname([
            'contextid' => $context->id,
            'component' => 'local_oerexchange',
            'filearea' => 'resource',
            'itemid' => $versionid,
            'filepath' => '/',
            'filename' => $fixturefilename,
        ], __DIR__ . '/fixtures/' . $fixturefilename);
        $DB->set_field('local_oerexchange_versions', 'itemid', $versionid, ['id' => $versionid]);

        return [$resourceid, $versionid];
    }

    public function test_execute_flips_pending_resource_to_published_on_success(): void {
        global $DB;
        $this->resetAfterTest();

        // Seed a resource with status='pending' and a version with status='parsing'
        // pointing at a real small valid .mbz fixture.
        [$resourceid, $versionid] = $this->stage_version('course_no_userdata.mbz', 'pending', 'course');

        $task = new parse_backup_task();
        $task->set_custom_data(['versionid' => $versionid]);
        $task->execute();

        // Assert the resource's status is now 'published'.
        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $resourceid]);
        $this->assertSame('published', $resource->status);

        // Also verify the version is 'ready'.
        $version = $DB->get_record('local_oerexchange_versions', ['id' => $versionid]);
        $this->assertSame('ready', $version->status);
    }

    public function test_execute_leaves_pending_resource_pending_on_sanitycheck_failure(): void {
        global $DB;
        $this->resetAfterTest();

        // Create a corrupt fixture by truncating a valid backup file.
        $tmpdir = make_temp_directory('oerexchange_test_corrupt');
        $tmpfile = $tmpdir . '/corrupt.mbz';
        copy(__DIR__ . '/fixtures/course_no_userdata.mbz', $tmpfile);
        file_put_contents($tmpfile, substr(file_get_contents($tmpfile), 0, 10));

        // Seed a resource with status='pending' and a version pointing at the corrupt file.
        [$resourceid, $versionid] = $this->stage_version('course_no_userdata.mbz', 'pending', 'course');

        // Replace the staged fixture with the corrupt version.
        $fs = get_file_storage();
        $context = \context_system::instance();
        $files = $fs->get_area_files($context->id, 'local_oerexchange', 'resource', $versionid);
        foreach ($files as $file) {
            if (!$file->is_directory()) {
                $file->delete();
            }
        }
        $fs->create_file_from_pathname([
            'contextid' => $context->id,
            'component' => 'local_oerexchange',
            'filearea' => 'resource',
            'itemid' => $versionid,
            'filepath' => '/',
            'filename' => 'corrupt.mbz',
        ], $tmpfile);
        $DB->set_field('local_oerexchange_versions', 'filename', 'corrupt.mbz', ['id' => $versionid]);
        remove_dir($tmpdir);

        $task = new parse_backup_task();
        $task->set_custom_data(['versionid' => $versionid]);
        $task->execute();

        // Assert the resource's status is still 'pending' (not flipped, not any other value).
        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $resourceid]);
        $this->assertSame('pending', $resource->status);

        // Assert the version's status is 'failed'.
        $version = $DB->get_record('local_oerexchange_versions', ['id' => $versionid]);
        $this->assertSame('failed', $version->status);
    }

    /**
     * Build a backup that is structurally valid — so
     * get_backup_information_from_mbz() succeeds and the sanity check is
     * actually reached — but which ships a populated users.xml, so it is
     * refused by sanitycheck's second (independent) check.
     *
     * Repacked with Moodle's OWN backup packer rather than a plain zip/tar:
     * the .mbz format carries an archive index the packer writes itself, and
     * a hand-rolled repack is rejected upstream as a malformed archive —
     * which would make this test pass for the wrong reason.
     *
     * @return string absolute path to the built .mbz
     */
    protected function build_backup_containing_user_data(): string {
        $packer = get_file_packer('application/vnd.moodle.backup');
        $extracted = make_request_directory() . '/extracted';
        check_dir_exists($extracted);
        $packer->extract_to_pathname(__DIR__ . '/fixtures/course_no_userdata.mbz', $extracted);

        file_put_contents(
            $extracted . '/users.xml',
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<users><user id="5"><username>canary</username>'
                . '<email>canary@students.invalid</email></user></users>'
        );

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extracted, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace($extracted, '', $file->getPathname()), '/');
            if ($relative === '.ARCHIVE_INDEX') {
                // The packer writes its own.
                continue;
            }
            $files[$relative] = $file->getPathname();
        }

        $path = make_request_directory() . '/with_userdata.mbz';
        $packer->archive_to_pathname($files, $path);

        return $path;
    }

    /**
     * Replace a staged version's stored file with the one at $path.
     *
     * @param int $versionid
     * @param string $path
     * @param string $filename
     */
    protected function restage_file(int $versionid, string $path, string $filename): void {
        global $DB;

        $fs = get_file_storage();
        $context = \context_system::instance();
        $fs->delete_area_files($context->id, 'local_oerexchange', 'resource', $versionid);
        $fs->create_file_from_pathname([
            'contextid' => $context->id,
            'component' => 'local_oerexchange',
            'filearea' => 'resource',
            'itemid' => $versionid,
            'filepath' => '/',
            'filename' => $filename,
        ], $path);
        $DB->set_field('local_oerexchange_versions', 'filename', $filename, ['id' => $versionid]);
    }

    public function test_execute_purges_the_uploaded_file_when_it_contains_user_data(): void {
        global $DB;
        $this->resetAfterTest();

        [$resourceid, $versionid] = $this->stage_version('course_no_userdata.mbz', 'pending', 'course');
        $this->restage_file($versionid, $this->build_backup_containing_user_data(), 'with_userdata.mbz');

        // Precondition: the file really is stored before the task runs,
        // otherwise "it is gone afterwards" would prove nothing.
        $this->assertNotNull(\local_oerexchange\local\resource_manager::get_version_file($versionid));

        $task = new parse_backup_task();
        $task->set_custom_data(['versionid' => $versionid]);
        $task->execute();

        $version = $DB->get_record('local_oerexchange_versions', ['id' => $versionid]);
        $this->assertSame('failed', $version->status);
        $this->assertStringContainsString(
            get_string('error_sanitycheckfailed', 'local_oerexchange'),
            $version->parseerror
        );
        // The reason must also say the file is gone — an author told only
        // "rejected" would reasonably assume their students' data is still
        // sitting on the Exchange.
        $this->assertStringContainsString(
            get_string('sanitycheckfilediscarded', 'local_oerexchange'),
            $version->parseerror
        );

        // The point of the fix: the rejected upload is not retained.
        $this->assertNull(\local_oerexchange\local\resource_manager::get_version_file($versionid));

        // The resource itself survives as a pending row carrying the reason.
        $this->assertSame(
            'pending',
            $DB->get_field('local_oerexchange_resources', 'status', ['id' => $resourceid])
        );
    }

    public function test_execute_keeps_the_file_when_the_backup_is_merely_unreadable(): void {
        global $DB;
        $this->resetAfterTest();

        // A corrupt .mbz holds nothing that needs minimising, and keeping it
        // is what lets a moderator diagnose the failure — so this failure
        // path must NOT purge.
        [, $versionid] = $this->stage_version('course_no_userdata.mbz', 'pending', 'course');
        $corrupt = make_request_directory() . '/corrupt.mbz';
        file_put_contents($corrupt, 'not a backup at all');
        $this->restage_file($versionid, $corrupt, 'corrupt.mbz');

        $task = new parse_backup_task();
        $task->set_custom_data(['versionid' => $versionid]);
        $task->execute();

        // Core's zip layer reports the unreadable archive through debugging()
        // on its way to throwing; consume it so it is an asserted part of
        // this scenario rather than an unexplained notice.
        $this->assertDebuggingCalled();

        $version = $DB->get_record('local_oerexchange_versions', ['id' => $versionid]);
        $this->assertSame('failed', $version->status);
        $this->assertStringNotContainsString(
            get_string('sanitycheckfilediscarded', 'local_oerexchange'),
            (string) $version->parseerror
        );
        $this->assertNotNull(\local_oerexchange\local\resource_manager::get_version_file($versionid));
    }

    public function test_execute_does_not_touch_already_published_resource_status(): void {
        global $DB;
        $this->resetAfterTest();

        // Seed a resource with status='published' (simulating a second version being added)
        // and a version pointing at a valid fixture.
        [$resourceid, $versionid] = $this->stage_version('course_no_userdata.mbz', 'published', 'course');

        $task = new parse_backup_task();
        $task->set_custom_data(['versionid' => $versionid]);
        $task->execute();

        // Assert the resource's status is still 'published' (the WHERE ... AND status = 'pending'
        // guard means this path is a no-op for it either way, but assert the outcome
        // explicitly since it's a Global Constraint).
        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $resourceid]);
        $this->assertSame('published', $resource->status);

        // Also verify the version is 'ready'.
        $version = $DB->get_record('local_oerexchange_versions', ['id' => $versionid]);
        $this->assertSame('ready', $version->status);
    }
}
