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
 * Tests for cover_image::save_from_draft(), the writer behind the author's
 * thumbnail upload on resource.php.
 *
 * This is a security boundary, not a convenience wrapper. The 'coverimage'
 * filearea is served inline by local_oerexchange_pluginfile(), so anything
 * that lands in it and is not a raster image is a stored-XSS vector — an SVG
 * carrying a <script>, or an HTML document renamed 'cover.png'. Two
 * independent checks stand between an upload and that filearea, and the tests
 * here pin BOTH of them separately, because either one alone lets a payload
 * through:
 *
 * - the mimetype allowlist rejects a type core derived from the FILENAME, so
 *   it stops 'evil.svg' but is blind to renamed content;
 * - getimagesizefromstring() sniffs the real bytes, so it stops 'evil.png'
 *   whose content is HTML but says nothing about what the name claims.
 *
 * Each negative test therefore also asserts which check did the rejecting
 * (via the draft file's own mimetype) and that nothing reached the permanent
 * area, so deleting either check makes a test fail rather than leaving the
 * other one to cover for it.
 *
 * Also pinned: an EMPTY draft area is not an error but a removal. The edit
 * form prefills the picker with the current cover, so submitting an empty
 * picker is how an author deletes their thumbnail; treating it as "nothing to
 * do" would silently ignore them.
 *
 * The read side of this class (urls_for/url_for/card/listitem) is covered by
 * tests/cover_image_test.php and is deliberately not repeated here.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(cover_image::class)]
final class cover_image_save_test extends \advanced_testcase {
    /**
     * Insert a published resource row, whose id is the cover filearea's itemid.
     *
     * @param array $overrides field => value pairs replacing the defaults
     * @return \stdClass the stored record
     */
    protected function make_resource(array $overrides = []): \stdClass {
        global $DB;
        $now = time();
        $record = (object) array_merge([
            'type' => 'course', 'title' => 'Shared course', 'summary' => '', 'summaryformat' => FORMAT_HTML,
            'language' => 'en', 'tags' => '', 'licenseshortname' => 'cc-4.0',
            'activitytype' => null, 'dataresourcetype' => null, 'courseformat' => null,
            'creatorid' => 0, 'siteid' => null, 'status' => 'published',
            'downloadcount' => 0, 'importcount' => 0, 'forkedfromid' => null,
            'trydisabled' => 0, 'trydisabledreason' => null,
            'timeshared' => $now, 'timemodified' => $now, 'timefresh' => $now, 'stalenotifiedtime' => 0,
            'modhiddentime' => 0, 'modhiddenby' => 0, 'modhiddenversionid' => null,
        ], $overrides);
        $id = $DB->insert_record('local_oerexchange_resources', $record);
        return $DB->get_record('local_oerexchange_resources', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * The bytes of a genuine, tiny PNG, generated rather than committed as a
     * binary fixture so that what the byte-sniffing check sees is a real
     * encoder's output.
     *
     * @param int $width image width in px
     * @param int $height image height in px
     * @return string raw PNG bytes
     */
    protected function png_bytes(int $width = 8, int $height = 8): string {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        return $bytes;
    }

    /**
     * Put one file in a fresh draft area belonging to the given user, the way
     * the file picker on the resource edit form does.
     *
     * @param int $userid owner of the draft area
     * @param string $filename name the upload claims, which is what core
     *      derives the stored mimetype from
     * @param string $content raw file content
     * @return int the new draftitemid
     */
    protected function create_draft(int $userid, string $filename, string $content): int {
        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($userid)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
        return $draftitemid;
    }

    /**
     * The files actually stored as the given resource's cover image, i.e. what
     * local_oerexchange_pluginfile() would be able to serve.
     *
     * @param int $resourceid
     * @return \stored_file[] real files only, directory entries excluded
     */
    protected function stored_covers(int $resourceid): array {
        return get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_oerexchange',
            'coverimage',
            $resourceid,
            'id',
            false
        );
    }

    /**
     * The mimetype core stored for the single file in a draft area — the value
     * the allowlist check actually tests, which is derived from the filename
     * and not from the content.
     *
     * @param int $userid owner of the draft area
     * @param int $draftitemid the draft area
     * @return string
     */
    protected function draft_mimetype(int $userid, int $draftitemid): string {
        $files = get_file_storage()->get_area_files(
            \context_user::instance($userid)->id,
            'user',
            'draft',
            $draftitemid,
            'id',
            false
        );
        return (string) reset($files)->get_mimetype();
    }

    /**
     * A real PNG upload becomes the resource's cover, in the system context,
     * under this plugin's own component/filearea and the resource's id.
     *
     * The storage coordinates are the contract the read side (urls_for()) and
     * the serving code (local_oerexchange_pluginfile()) both depend on, so
     * this asserts the file is findable at exactly those coordinates rather
     * than merely that the call returned.
     *
     * @return void
     */
    public function test_a_genuine_png_is_stored_as_the_resources_cover(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $resource = $this->make_resource();
        $png = $this->png_bytes();
        $draftitemid = $this->create_draft((int) $user->id, 'cover.png', $png);

        $result = cover_image::save_from_draft((int) $resource->id, $draftitemid);

        $this->assertTrue($result, 'the resource now has a cover image');
        $files = $this->stored_covers((int) $resource->id);
        $this->assertCount(1, $files);
        $file = reset($files);
        $this->assertSame('cover.png', $file->get_filename());
        $this->assertSame('image/png', $file->get_mimetype());
        $this->assertSame($png, $file->get_content(), 'the stored bytes are the uploaded ones');
        $this->assertSame(
            \context_system::instance()->id,
            (int) $file->get_contextid(),
            'covers live in the system context, not the author\'s user context'
        );
        $this->assertSame((int) $resource->id, (int) $file->get_itemid());
    }

    /**
     * Content that is not an image is rejected however the file is named.
     *
     * This is the byte-sniffing check and the important one: 'evil.png' is
     * exactly what an attacker uploads, because the mimetype core derives from
     * that name is 'image/png' and sails straight through the allowlist. The
     * test asserts that mimetype first, so it cannot pass just because the
     * allowlist happened to catch the file.
     *
     * @return void
     */
    public function test_html_content_named_as_a_png_is_rejected_by_the_byte_check(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $resource = $this->make_resource();
        $draftitemid = $this->create_draft(
            (int) $user->id,
            'evil.png',
            '<html><body><script>alert(document.cookie)</script></body></html>'
        );

        // The allowlist cannot help here: core derived the mimetype from the
        // name, and it is an allowed one. Only the content check can reject.
        $mimetype = $this->draft_mimetype((int) $user->id, $draftitemid);
        $this->assertSame('image/png', $mimetype);
        $this->assertContains($mimetype, cover_image::ALLOWED_MIMETYPES);

        try {
            cover_image::save_from_draft((int) $resource->id, $draftitemid);
            $this->fail('a non-image renamed to .png must not become a cover image');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_thumbnailnotanimage', $e->errorcode);
            $this->assertSame('local_oerexchange', $e->module);
        }

        $this->assertSame(
            [],
            $this->stored_covers((int) $resource->id),
            'a rejected file must never reach the served filearea, not even briefly'
        );
    }

    /**
     * An SVG is rejected even though its mimetype begins with "image/".
     *
     * SVG is the reason ALLOWED_MIMETYPES is an explicit allowlist rather than
     * a `str_starts_with($mimetype, 'image/')` test: it is a scriptable XML
     * document served inline from this filearea. The assertions below state
     * both halves — the mimetype does start with "image/", and it is still not
     * allowed — so replacing the allowlist with a prefix test fails here.
     *
     * @return void
     */
    public function test_an_svg_is_rejected_despite_its_image_mimetype(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $resource = $this->make_resource();
        $draftitemid = $this->create_draft(
            (int) $user->id,
            'cover.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>'
        );

        $mimetype = $this->draft_mimetype((int) $user->id, $draftitemid);
        $this->assertSame('image/svg+xml', $mimetype);
        $this->assertStringStartsWith('image/', $mimetype, 'a prefix test would wave this through');
        $this->assertNotContains($mimetype, cover_image::ALLOWED_MIMETYPES);

        try {
            cover_image::save_from_draft((int) $resource->id, $draftitemid);
            $this->fail('an SVG must not become a cover image');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_thumbnailnotanimage', $e->errorcode);
            $this->assertSame('local_oerexchange', $e->module);
        }

        $this->assertSame([], $this->stored_covers((int) $resource->id));
    }

    /**
     * A file above MAX_BYTES is rejected with its own errorcode.
     *
     * The payload is a real PNG followed by padding, so it passes the mimetype
     * allowlist and the byte sniff and can only be rejected on size — pinning
     * the size limit rather than accidentally re-testing the image checks. The
     * distinct errorcode matters because the author sees it: "too large" and
     * "not an image" call for different corrective action.
     *
     * @return void
     */
    public function test_an_oversized_image_is_rejected_with_the_size_errorcode(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $resource = $this->make_resource();
        $content = $this->png_bytes() . str_repeat("\0", cover_image::MAX_BYTES);
        $draftitemid = $this->create_draft((int) $user->id, 'huge.png', $content);

        $this->assertGreaterThan(cover_image::MAX_BYTES, strlen($content));
        $this->assertNotFalse(
            @getimagesizefromstring($content),
            'the payload really is a readable image, so only the size check can reject it'
        );

        try {
            cover_image::save_from_draft((int) $resource->id, $draftitemid);
            $this->fail('a file above MAX_BYTES must be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_thumbnailtoolarge', $e->errorcode);
            $this->assertSame('local_oerexchange', $e->module);
        }

        $this->assertSame([], $this->stored_covers((int) $resource->id));
    }

    /**
     * A file exactly at MAX_BYTES is accepted: the limit is a maximum, not an
     * exclusive bound, and an off-by-one here silently rejects a legal upload.
     *
     * @return void
     */
    public function test_a_file_exactly_at_the_limit_is_accepted(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $resource = $this->make_resource();
        $png = $this->png_bytes();
        $content = $png . str_repeat("\0", cover_image::MAX_BYTES - strlen($png));
        $this->assertSame(cover_image::MAX_BYTES, strlen($content));
        $draftitemid = $this->create_draft((int) $user->id, 'atlimit.png', $content);

        $this->assertTrue(cover_image::save_from_draft((int) $resource->id, $draftitemid));
        $this->assertCount(1, $this->stored_covers((int) $resource->id));
    }

    /**
     * An empty draft area is a removal, not a no-op.
     *
     * The edit form prefills the picker with the current cover, so an empty
     * draft area on submit is the only way an author can say "delete my
     * thumbnail". Skipping the sync would leave the old image in place and
     * silently ignore them, so this asserts the previously stored file is
     * really gone and that the return value reports "no cover" rather than
     * raising.
     *
     * @return void
     */
    public function test_an_empty_draft_area_clears_an_existing_cover(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $resource = $this->make_resource();
        $firstdraft = $this->create_draft((int) $user->id, 'cover.png', $this->png_bytes());
        $this->assertTrue(cover_image::save_from_draft((int) $resource->id, $firstdraft));
        $this->assertCount(1, $this->stored_covers((int) $resource->id), 'precondition: there is a cover to remove');

        // The picker was emptied by the author: a draft area with no files in it.
        $emptydraft = file_get_unused_draft_itemid();

        $result = cover_image::save_from_draft((int) $resource->id, $emptydraft);

        $this->assertFalse($result, 'an empty submission is reported as "no cover", not as an error');
        $this->assertSame(
            [],
            $this->stored_covers((int) $resource->id),
            'the removed image is gone from the filearea, not merely unreferenced'
        );
    }

    /**
     * Removing one resource's cover leaves other resources' covers alone.
     *
     * The filearea is shared across every resource on the site and keyed only
     * by itemid, so a delete that ignored the itemid would wipe the whole
     * catalogue's thumbnails — a plausible and very destructive slip.
     *
     * @return void
     */
    public function test_clearing_one_cover_does_not_touch_another_resources(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $mine = $this->make_resource();
        $theirs = $this->make_resource(['title' => 'Someone else\'s course']);
        cover_image::save_from_draft(
            (int) $mine->id,
            $this->create_draft((int) $user->id, 'mine.png', $this->png_bytes())
        );
        cover_image::save_from_draft(
            (int) $theirs->id,
            $this->create_draft((int) $user->id, 'theirs.png', $this->png_bytes())
        );

        cover_image::save_from_draft((int) $mine->id, file_get_unused_draft_itemid());

        $this->assertSame([], $this->stored_covers((int) $mine->id));
        $this->assertCount(1, $this->stored_covers((int) $theirs->id));
    }

    /**
     * Uploading a new cover replaces the old one instead of accumulating.
     *
     * The filearea is capped at one file per resource (maxfiles => 1) because
     * the read side keeps only the first match; if a second file could linger,
     * which thumbnail a card draws would depend on row ids.
     *
     * @return void
     */
    public function test_a_second_upload_replaces_the_first(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $resource = $this->make_resource();
        cover_image::save_from_draft(
            (int) $resource->id,
            $this->create_draft((int) $user->id, 'first.png', $this->png_bytes(8, 8))
        );

        $result = cover_image::save_from_draft(
            (int) $resource->id,
            $this->create_draft((int) $user->id, 'second.png', $this->png_bytes(12, 12))
        );

        $this->assertTrue($result);
        $files = $this->stored_covers((int) $resource->id);
        $this->assertCount(1, $files, 'one cover per resource, never two');
        $this->assertSame('second.png', reset($files)->get_filename());
    }
}
