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
 * Collection cover images: the upload gate, the generated mosaic, and the
 * fallback when there is neither.
 *
 * The upload half is a security boundary rather than a convenience wrapper.
 * 'collectioncover' is served inline by local_oerexchange_pluginfile(), so
 * anything that lands in it and is not a raster image is stored XSS — an SVG
 * carrying a <script>, or an HTML document renamed 'cover.png'. Two
 * independent checks stand in the way and EITHER ONE ALONE lets a payload
 * through, so each negative test below also asserts which check did the
 * rejecting:
 *
 * - the mimetype allowlist only sees what core derived from the uploaded
 *   FILENAME, so it stops 'evil.svg' and is blind to renamed content;
 * - getimagesizefromstring() sniffs the real bytes, so it stops 'evil.png'
 *   holding HTML and says nothing about what the name claims.
 *
 * The mosaic half has a different failure mode: staleness. pluginfile URLs are
 * cacheable, so a mosaic stored under one fixed filename would keep serving
 * the browser's copy of the OLD image from the identical URL after the
 * collection changed. The answer is content-addressing (the sha1 of the
 * image's own bytes is in its name) plus invalidation on every change that
 * could affect the first four tiles — and both halves of that are asserted
 * here, including the cases where no mosaic can be made at all and the
 * default thumbnail has to carry the layout.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(collection_cover::class)]
final class collection_cover_test extends \advanced_testcase {
    /**
     * This plugin's data generator.
     *
     * @return \local_oerexchange_generator
     */
    protected function generator(): \local_oerexchange_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_oerexchange');
    }

    /**
     * The bytes of a genuine PNG in a nameable colour.
     *
     * Generated rather than committed as a binary fixture, so that what the
     * byte-sniffing check and the mosaic builder see is a real encoder's
     * output. The colour matters for the content-addressing test: two
     * different mosaics have to be made of visibly different tiles, or the
     * composed bytes would be identical and the test would prove nothing.
     *
     * @param array $rgb the fill colour as [red, green, blue]
     * @param int $width image width in px
     * @param int $height image height in px
     * @return string raw PNG bytes
     */
    protected function png_bytes(array $rgb = [10, 20, 30], int $width = 16, int $height = 16): string {
        $image = imagecreatetruecolor($width, $height);
        $colour = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $colour);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * Put one file in a fresh draft area belonging to the given user, the way
     * the file picker on the collection edit form does.
     *
     * @param int $userid owner of the draft area
     * @param string $filename the name the upload claims, which is what core
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
     * The mimetype core stored for the single file in a draft area.
     *
     * This is the value the allowlist check actually tests, and it is derived
     * from the filename rather than from the content — which is the whole
     * reason the second, byte-level check exists.
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
     * The real files stored in one of this class's fileareas for a
     * collection, i.e. what local_oerexchange_pluginfile() could serve.
     *
     * @param string $filearea collection_cover::FILEAREA or MOSAIC_FILEAREA
     * @param int $collectionid
     * @return \stored_file[] real files only, directory entries excluded
     */
    protected function stored_files(string $filearea, int $collectionid): array {
        return get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_oerexchange',
            $filearea,
            $collectionid,
            'id',
            false
        );
    }

    /**
     * Give a resource a cover image, the way parse_backup_task and the
     * author's own upload both do.
     *
     * @param int $resourceid the resource the cover belongs to
     * @param string $content raw file content; not required to be an image,
     *      because the backup path can store things the upload path refuses
     * @param string $filename
     * @return void
     */
    protected function attach_resource_cover(
        int $resourceid,
        string $content,
        string $filename = 'cover.png'
    ): void {
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_oerexchange',
            'filearea' => 'coverimage',
            'itemid' => $resourceid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * A collection holding three resources, each with a cover image in its
     * own colour, in curated order.
     *
     * Three rather than two so that removing one still leaves enough covers
     * for a mosaic, which is what the invalidation tests need.
     *
     * @param int $ownerid the collection's owner, also recorded as addedby
     * @return array [collection row, [resource rows in curated order]]
     */
    protected function make_collection_with_covers(int $ownerid): array {
        $collection = $this->generator()->create_collection(['userid' => $ownerid]);

        $resources = [];
        foreach ([[220, 30, 30], [30, 220, 30], [30, 30, 220]] as $index => $rgb) {
            $resource = $this->generator()->create_resource(['title' => 'Covered ' . $index]);
            $this->attach_resource_cover((int) $resource->id, $this->png_bytes($rgb));
            collection_manager::add_item((int) $collection->id, (int) $resource->id, $ownerid);
            $resources[] = $resource;
        }

        return [$collection, $resources];
    }

    /**
     * A real PNG upload becomes the collection's cover, at exactly the
     * coordinates the read side and the serving code both expect.
     *
     * @return void
     */
    public function test_a_genuine_png_becomes_the_collections_cover(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $collection = $this->generator()->create_collection(['userid' => (int) $user->id]);
        $png = $this->png_bytes();
        $draftitemid = $this->create_draft((int) $user->id, 'cover.png', $png);

        $result = collection_cover::save_from_draft((int) $collection->id, $draftitemid);

        $this->assertTrue($result, 'the collection now has an uploaded cover');
        $files = $this->stored_files(collection_cover::FILEAREA, (int) $collection->id);
        $this->assertCount(1, $files);
        $file = reset($files);
        $this->assertSame('cover.png', $file->get_filename());
        $this->assertSame('image/png', $file->get_mimetype());
        $this->assertSame($png, $file->get_content(), 'the stored bytes are the uploaded ones');
        $this->assertSame(\context_system::instance()->id, (int) $file->get_contextid());
        $this->assertSame((int) $collection->id, (int) $file->get_itemid());

        $url = collection_cover::url_for((int) $collection->id);
        $this->assertNotNull($url);
        $this->assertStringContainsString(
            '/local_oerexchange/' . collection_cover::FILEAREA . '/' . $collection->id . '/',
            $url->out(false),
            'the URL addresses this collection\'s own itemid'
        );
        $this->assertSame(
            $url->out(false),
            collection_cover::display_url((int) $collection->id)->out(false)
        );
    }

    /**
     * Content that is not an image is rejected however the file is named.
     *
     * 'evil.png' is exactly what an attacker uploads, because the mimetype
     * core derives from that name is an allowed one and sails through the
     * allowlist. The mimetype is asserted FIRST so the test cannot pass
     * because the allowlist happened to catch the file.
     *
     * @return void
     */
    public function test_html_content_named_as_a_png_is_rejected_by_the_byte_check(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $collection = $this->generator()->create_collection(['userid' => (int) $user->id]);
        $draftitemid = $this->create_draft(
            (int) $user->id,
            'evil.png',
            '<html><body><script>alert(document.cookie)</script></body></html>'
        );

        $mimetype = $this->draft_mimetype((int) $user->id, $draftitemid);
        $this->assertSame('image/png', $mimetype);
        $this->assertContains(
            $mimetype,
            collection_cover::ALLOWED_MIMETYPES,
            'the allowlist cannot help here: only the byte check can reject this'
        );

        try {
            collection_cover::save_from_draft((int) $collection->id, $draftitemid);
            $this->fail('a non-image renamed to .png must not become a collection cover');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_thumbnailnotanimage', $e->errorcode);
            $this->assertSame('local_oerexchange', $e->module);
        }

        $this->assertSame(
            [],
            $this->stored_files(collection_cover::FILEAREA, (int) $collection->id),
            'a rejected file must never reach the served filearea, not even briefly'
        );
    }

    /**
     * An SVG is rejected even though its mimetype begins with "image/".
     *
     * SVG is why ALLOWED_MIMETYPES is an explicit allowlist rather than a
     * str_starts_with($mimetype, 'image/') test: it is a scriptable XML
     * document, and this filearea is served inline. Both halves are stated,
     * so replacing the allowlist with a prefix test fails here.
     *
     * @return void
     */
    public function test_an_svg_is_rejected_despite_its_image_mimetype(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $collection = $this->generator()->create_collection(['userid' => (int) $user->id]);
        $draftitemid = $this->create_draft(
            (int) $user->id,
            'cover.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>'
        );

        $mimetype = $this->draft_mimetype((int) $user->id, $draftitemid);
        $this->assertSame('image/svg+xml', $mimetype);
        $this->assertStringStartsWith('image/', $mimetype, 'a prefix test would wave this through');
        $this->assertNotContains($mimetype, collection_cover::ALLOWED_MIMETYPES);

        try {
            collection_cover::save_from_draft((int) $collection->id, $draftitemid);
            $this->fail('an SVG must not become a collection cover');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_thumbnailnotanimage', $e->errorcode);
            $this->assertSame('local_oerexchange', $e->module);
        }

        $this->assertSame([], $this->stored_files(collection_cover::FILEAREA, (int) $collection->id));
    }

    /**
     * A file above MAX_BYTES is rejected on SIZE, with its own errorcode.
     *
     * The payload is a real PNG followed by padding, so it passes the
     * allowlist and the byte sniff and can only be rejected on size —
     * pinning the limit rather than accidentally re-testing the image checks.
     * The distinct errorcode matters because the owner sees it: "too large"
     * and "not an image" call for different corrective action.
     *
     * @return void
     */
    public function test_an_oversized_image_is_rejected_with_the_size_errorcode(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $collection = $this->generator()->create_collection(['userid' => (int) $user->id]);
        $content = $this->png_bytes() . str_repeat("\0", collection_cover::MAX_BYTES);
        $draftitemid = $this->create_draft((int) $user->id, 'huge.png', $content);

        $this->assertGreaterThan(collection_cover::MAX_BYTES, strlen($content));
        $this->assertNotFalse(
            @getimagesizefromstring($content),
            'the payload really is a readable image, so only the size check can reject it'
        );

        try {
            collection_cover::save_from_draft((int) $collection->id, $draftitemid);
            $this->fail('a file above MAX_BYTES must be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_thumbnailtoolarge', $e->errorcode);
            $this->assertSame('local_oerexchange', $e->module);
            $this->assertSame(
                display_size(collection_cover::MAX_BYTES),
                $e->a,
                'the message tells the owner what the limit is'
            );
        }

        $this->assertSame([], $this->stored_files(collection_cover::FILEAREA, (int) $collection->id));
    }

    /**
     * A file exactly at MAX_BYTES is accepted: the cap is a maximum, and an
     * off-by-one here silently refuses a legal upload.
     *
     * @return void
     */
    public function test_a_file_exactly_at_the_limit_is_accepted(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $collection = $this->generator()->create_collection(['userid' => (int) $user->id]);
        $png = $this->png_bytes();
        $content = $png . str_repeat("\0", collection_cover::MAX_BYTES - strlen($png));
        $this->assertSame(collection_cover::MAX_BYTES, strlen($content));
        $draftitemid = $this->create_draft((int) $user->id, 'atlimit.png', $content);

        $this->assertTrue(collection_cover::save_from_draft((int) $collection->id, $draftitemid));
        $this->assertCount(1, $this->stored_files(collection_cover::FILEAREA, (int) $collection->id));
    }

    /**
     * An EMPTY draft area is a removal, not a no-op.
     *
     * The edit form prefills the picker with the current cover, so submitting
     * an empty picker is how an owner deletes their image. Treating it as
     * "nothing to do" would silently ignore them.
     *
     * @return void
     */
    public function test_an_empty_draft_area_removes_the_existing_cover(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $collection = $this->generator()->create_collection(['userid' => (int) $user->id]);
        $draftitemid = $this->create_draft((int) $user->id, 'cover.png', $this->png_bytes());
        $this->assertTrue(collection_cover::save_from_draft((int) $collection->id, $draftitemid));

        $empty = file_get_unused_draft_itemid();
        $result = collection_cover::save_from_draft((int) $collection->id, $empty);

        $this->assertFalse($result, 'the collection no longer has an uploaded cover');
        $this->assertSame([], $this->stored_files(collection_cover::FILEAREA, (int) $collection->id));
        $this->assertNull(collection_cover::url_for((int) $collection->id));
    }

    /**
     * The file that is VALIDATED must be the file that is SAVED.
     *
     * THIS TEST DESCRIBES CORRECT BEHAVIOUR AND IS EXPECTED TO FAIL against
     * collection_cover.php as written. It is a bug report in executable form;
     * see the note below and do not weaken it to make it green.
     *
     * save_from_draft() validates reset($draftfiles) — the lowest-id file in
     * the draft area, subdirectories included — and then hands the whole area
     * to file_save_draft_area_files() with subdirs => 0, maxfiles => 1.
     * Core's merge loop (lib/filelib.php, inside file_save_draft_area_files())
     * skips a file whose filepath is not '/' BEFORE it increments its own
     * filecount, so with
     *
     *   /sub/cover.png   (a genuine PNG, lower file id)
     *   /payload.svg     (anything at all, higher file id)
     *
     * the PNG is what gets validated and the SVG is what gets stored, in a
     * filearea local_oerexchange_pluginfile() serves inline. The draftitemid
     * arrives from the client (the filepicker element on the collection edit
     * form posts it), and a user can put a subdirectory in their own draft
     * area through the ordinary draft-file AJAX, so the two-file state is
     * reachable by an authenticated author.
     *
     * The fix belongs in collection_cover.php (and identically in
     * cover_image.php, which has the same shape): validate every root-level
     * file the save will actually keep, not just the first one the file API
     * happens to return.
     *
     * @return void
     */
    public function test_a_payload_beside_a_valid_image_in_a_subdirectory_is_rejected(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $collection = $this->generator()->create_collection(['userid' => (int) $user->id]);
        $fs = get_file_storage();
        $usercontextid = \context_user::instance((int) $user->id)->id;
        $draftitemid = file_get_unused_draft_itemid();

        // The decoy goes in first so that it holds the lower file id and is
        // therefore what reset() picks for validation.
        $fs->create_directory($usercontextid, 'user', 'draft', $draftitemid, '/sub/');
        $fs->create_file_from_string([
            'contextid' => $usercontextid,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/sub/',
            'filename' => 'cover.png',
        ], $this->png_bytes());
        $fs->create_file_from_string([
            'contextid' => $usercontextid,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'payload.svg',
        ], '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>');

        $draftfiles = $fs->get_area_files($usercontextid, 'user', 'draft', $draftitemid, 'id', false);
        $this->assertSame(
            '/sub/',
            reset($draftfiles)->get_filepath(),
            'the fixture really does put the decoy first, which is what makes this a bypass'
        );

        try {
            collection_cover::save_from_draft((int) $collection->id, $draftitemid);
            $this->fail('the root-level file is the one that will be stored, so it must be validated');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_thumbnailnotanimage', $e->errorcode);
            $this->assertSame('local_oerexchange', $e->module);
        }

        $this->assertSame(
            [],
            $this->stored_files(collection_cover::FILEAREA, (int) $collection->id),
            'an SVG carrying a script must never reach an inline-served filearea'
        );
    }

    /**
     * url_for() answers only about the UPLOADED cover and never falls back to
     * the mosaic; display_url() is the one that falls back.
     *
     * The edit form and the privacy export both ask "did somebody upload a
     * cover for this?" and need a straight answer — a generated mosaic is not
     * the owner's file and must not be offered to them as one.
     *
     * @return void
     */
    public function test_url_for_never_falls_back_to_the_mosaic(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        [$collection] = $this->make_collection_with_covers((int) $owner->id);

        $this->assertNull(
            collection_cover::url_for((int) $collection->id),
            'nobody uploaded anything here'
        );

        $display = collection_cover::display_url((int) $collection->id);

        $this->assertNotNull($display, 'but there are enough item covers for a mosaic');
        $this->assertStringContainsString(collection_cover::MOSAIC_FILEAREA, $display->out(false));
    }

    /**
     * An uploaded cover wins over the mosaic, and uploading one throws the
     * mosaic away.
     *
     * @return void
     */
    public function test_an_uploaded_cover_supersedes_and_invalidates_the_mosaic(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $this->setUser($owner);
        [$collection] = $this->make_collection_with_covers((int) $owner->id);
        $this->assertNotNull(collection_cover::display_url((int) $collection->id));
        $this->assertCount(1, $this->stored_files(collection_cover::MOSAIC_FILEAREA, (int) $collection->id));

        $draftitemid = $this->create_draft((int) $owner->id, 'chosen.png', $this->png_bytes([1, 2, 3]));
        collection_cover::save_from_draft((int) $collection->id, $draftitemid);

        $this->assertSame(
            [],
            $this->stored_files(collection_cover::MOSAIC_FILEAREA, (int) $collection->id),
            'the generated image is stale the moment a real one arrives'
        );
        $display = collection_cover::display_url((int) $collection->id);
        $this->assertStringContainsString('chosen.png', $display->out(false));
        $this->assertStringContainsString(collection_cover::FILEAREA, $display->out(false));
    }

    /**
     * The mosaic is built on demand, stored as a single square PNG in its own
     * filearea, and named after its own bytes.
     *
     * @return void
     */
    public function test_the_mosaic_is_built_on_demand_as_one_square_png(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        [$collection] = $this->make_collection_with_covers((int) $owner->id);
        $this->assertSame([], $this->stored_files(collection_cover::MOSAIC_FILEAREA, (int) $collection->id));

        $url = collection_cover::display_url((int) $collection->id);

        $this->assertNotNull($url);
        $files = $this->stored_files(collection_cover::MOSAIC_FILEAREA, (int) $collection->id);
        $this->assertCount(1, $files);
        $file = reset($files);
        $this->assertStringStartsWith(collection_cover::MOSAIC_FILENAME_PREFIX, $file->get_filename());
        $this->assertStringEndsWith('.png', $file->get_filename());
        $this->assertStringContainsString($file->get_filename(), $url->out(false));

        $info = getimagesizefromstring($file->get_content());
        $this->assertNotFalse($info, 'the stored mosaic really is an image');
        $this->assertSame(IMAGETYPE_PNG, $info[2], 'stored under .png, so it had better be one');
        $this->assertSame(collection_cover::MOSAIC_WIDTH, $info[0]);
        $this->assertSame(collection_cover::MOSAIC_HEIGHT, $info[1]);
    }

    /**
     * A second call does not rebuild: the stored file is served.
     *
     * @return void
     */
    public function test_a_built_mosaic_is_reused_rather_than_rebuilt(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        [$collection] = $this->make_collection_with_covers((int) $owner->id);

        $first = collection_cover::display_url((int) $collection->id)->out(false);
        $second = collection_cover::display_url((int) $collection->id)->out(false);

        $this->assertSame($first, $second);
        $this->assertCount(1, $this->stored_files(collection_cover::MOSAIC_FILEAREA, (int) $collection->id));
    }

    /**
     * The mosaic's URL changes when its content changes.
     *
     * This is the whole reason the filename carries a hash of the image's own
     * bytes: pluginfile URLs are cacheable, so a mosaic stored under one fixed
     * name would keep serving the browser's copy of the superseded image from
     * the identical URL.
     *
     * @return void
     */
    public function test_the_mosaic_url_self_versions_when_the_collection_changes(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id]);
        foreach ([[220, 30, 30], [30, 220, 30]] as $index => $rgb) {
            $resource = $this->generator()->create_resource(['title' => 'Covered ' . $index]);
            $this->attach_resource_cover((int) $resource->id, $this->png_bytes($rgb));
            collection_manager::add_item((int) $collection->id, (int) $resource->id, (int) $owner->id);
        }
        $before = collection_cover::display_url((int) $collection->id)->out(false);

        $third = $this->generator()->create_resource(['title' => 'Third cover']);
        $this->attach_resource_cover((int) $third->id, $this->png_bytes([250, 250, 10]));
        collection_manager::add_item((int) $collection->id, (int) $third->id, (int) $owner->id);
        $after = collection_cover::display_url((int) $collection->id)->out(false);

        $this->assertNotSame(
            $before,
            $after,
            'a different picture must be served from a different URL'
        );
    }

    /**
     * Every membership and order change throws the mosaic away; a note change
     * does not, because a note is not in the picture.
     *
     * Over-invalidating is cheap (the next read rebuilds) and under-
     * invalidating serves a stale image, so this asserts all three writers
     * that touch the first four tiles.
     *
     * @return void
     */
    public function test_adding_removing_and_reordering_invalidate_the_mosaic(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        [$collection, $resources] = $this->make_collection_with_covers((int) $owner->id);
        $collectionid = (int) $collection->id;

        // Adding.
        $this->assertNotNull(collection_cover::display_url($collectionid));
        $extra = $this->generator()->create_resource(['title' => 'Added later']);
        $this->attach_resource_cover((int) $extra->id, $this->png_bytes([5, 5, 5]));
        collection_manager::add_item($collectionid, (int) $extra->id, (int) $owner->id);
        $this->assertSame(
            [],
            $this->stored_files(collection_cover::MOSAIC_FILEAREA, $collectionid),
            'adding an item can change the first four tiles'
        );

        // Removing.
        $this->assertNotNull(collection_cover::display_url($collectionid));
        collection_manager::remove_item($collectionid, (int) $resources[0]->id);
        $this->assertSame(
            [],
            $this->stored_files(collection_cover::MOSAIC_FILEAREA, $collectionid),
            'removing an item can change the first four tiles'
        );

        // Reordering.
        $this->assertNotNull(collection_cover::display_url($collectionid));
        $items = collection_manager::get_items($collectionid);
        $lastitemid = (int) array_key_last($items);
        collection_manager::move_item($lastitemid, -1);
        $this->assertSame(
            [],
            $this->stored_files(collection_cover::MOSAIC_FILEAREA, $collectionid),
            'the mosaic is laid out in curated order, so reordering changes it'
        );

        // A note is not in the picture.
        $this->assertNotNull(collection_cover::display_url($collectionid));
        collection_manager::set_item_note($lastitemid, 'Why this one is here');
        $this->assertCount(
            1,
            $this->stored_files(collection_cover::MOSAIC_FILEAREA, $collectionid),
            'a note change must not throw away a perfectly good image'
        );
    }

    /**
     * invalidate_mosaic() is idempotent and safe to over-call: calling it
     * twice, or on a collection that never had a mosaic, raises nothing.
     *
     * @return void
     */
    public function test_invalidate_mosaic_is_idempotent(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        [$collection] = $this->make_collection_with_covers((int) $owner->id);
        collection_cover::display_url((int) $collection->id);

        collection_cover::invalidate_mosaic((int) $collection->id);
        collection_cover::invalidate_mosaic((int) $collection->id);
        collection_cover::invalidate_mosaic(999999);

        $this->assertSame([], $this->stored_files(collection_cover::MOSAIC_FILEAREA, (int) $collection->id));
    }

    /**
     * One item cover is not a mosaic, and the fallback is the platform's
     * default thumbnail at the same size.
     *
     * A "mosaic" of one image is that image with three empty quarters, which
     * reads as a rendering failure rather than as a design — hence
     * MOSAIC_MIN_TILES.
     *
     * @return void
     */
    public function test_a_single_item_cover_produces_no_mosaic_and_falls_back(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id]);
        $covered = $this->generator()->create_resource(['title' => 'The only cover']);
        $this->attach_resource_cover((int) $covered->id, $this->png_bytes());
        collection_manager::add_item((int) $collection->id, (int) $covered->id, (int) $owner->id);
        $bare = $this->generator()->create_resource(['title' => 'No cover at all']);
        collection_manager::add_item((int) $collection->id, (int) $bare->id, (int) $owner->id);

        $this->assertSame(2, collection_manager::item_count((int) $collection->id));
        $this->assertCount(1, collection_cover::mosaic_tiles((int) $collection->id));
        $this->assertNull(
            collection_cover::display_url((int) $collection->id),
            'two items, but only one of them has a picture'
        );
        $this->assertSame(
            [],
            $this->stored_files(collection_cover::MOSAIC_FILEAREA, (int) $collection->id),
            'a refused mosaic must not leave a half-built file behind'
        );
    }

    /**
     * A collection with nothing in it has nothing to draw, and both shapes
     * still render an image so a grid of cards keeps its rows aligned.
     *
     * @return void
     */
    public function test_the_no_cover_fallback_renders_the_default_thumbnail(): void {
        $this->resetAfterTest();
        $collection = $this->generator()->create_collection();

        $this->assertNull(collection_cover::display_url((int) $collection->id));
        $this->assertSame([], collection_cover::mosaic_tiles((int) $collection->id));

        foreach ([collection_cover::card(null), collection_cover::listitem(null)] as $html) {
            $this->assertStringContainsString('<img', $html);
            $this->assertStringContainsString('defaultthumbnail', $html);
            $this->assertStringContainsString('oerexchange-thumb-default', $html);
            // Decorative: every call site puts the title next to the image as
            // a link, so alt text would announce the collection twice.
            $this->assertStringContainsString('alt=""', $html);
        }

        $this->assertStringContainsString(
            (string) collection_cover::CARD_HEIGHT . 'px',
            collection_cover::card(null)
        );
        $this->assertStringContainsString(
            (string) collection_cover::LIST_SIZE . 'px',
            collection_cover::listitem(null)
        );
    }

    /**
     * With an image, neither shape claims to be the default.
     *
     * @return void
     */
    public function test_both_shapes_render_a_real_cover_without_the_default_marker(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $collection = $this->generator()->create_collection(['userid' => (int) $user->id]);
        $draftitemid = $this->create_draft((int) $user->id, 'banner.png', $this->png_bytes());
        collection_cover::save_from_draft((int) $collection->id, $draftitemid);
        $url = collection_cover::url_for((int) $collection->id);

        foreach ([collection_cover::card($url), collection_cover::listitem($url)] as $html) {
            $this->assertStringContainsString('banner.png', $html);
            $this->assertStringNotContainsString('oerexchange-thumb-default', $html);
            $this->assertStringContainsString('loading="lazy"', $html);
            $this->assertStringContainsString('alt=""', $html);
        }
    }

    /**
     * mosaic_tiles() takes the first MOSAIC_TILES covers in CURATED order,
     * skipping items that have no cover at all.
     *
     * "The first four items" and "the first four items with covers" are
     * different lists, and the tiles have to be the second one — a mosaic is
     * not the place to advertise that some of the collection's resources have
     * no picture.
     *
     * @return void
     */
    public function test_mosaic_tiles_takes_the_first_four_covers_in_curated_order(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id]);

        // Six items, alternating cover / no cover, so the first four TILES
        // come from items 1, 3, 5 and 7 of the list rather than 1 to 4.
        $covered = [];
        for ($index = 0; $index < 5; $index++) {
            $withcover = $this->generator()->create_resource(['title' => 'With cover ' . $index]);
            $this->attach_resource_cover(
                (int) $withcover->id,
                $this->png_bytes([$index * 40, 10, 10]),
                'cover' . $index . '.png'
            );
            collection_manager::add_item((int) $collection->id, (int) $withcover->id, (int) $owner->id);
            $covered[] = $withcover;

            $bare = $this->generator()->create_resource(['title' => 'Without cover ' . $index]);
            collection_manager::add_item((int) $collection->id, (int) $bare->id, (int) $owner->id);
        }

        $tiles = collection_cover::mosaic_tiles((int) $collection->id);

        $this->assertCount(collection_cover::MOSAIC_TILES, $tiles);
        $this->assertSame([0, 1, 2, 3], array_keys($tiles), 're-indexed from 0');
        foreach ([0, 1, 2, 3] as $position) {
            $this->assertStringContainsString(
                '/coverimage/' . $covered[$position]->id . '/',
                $tiles[$position]->out(false),
                "tile $position is the cover of the item at curated position $position"
            );
        }
    }

    /**
     * An item whose resource is no longer publicly viewable contributes no
     * tile.
     *
     * The mosaic is the public face of the collection, so it must not show a
     * picture of something the public cannot open.
     *
     * @return void
     */
    public function test_mosaic_tiles_ignores_an_item_whose_resource_is_unavailable(): void {
        global $DB;
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        [$collection, $resources] = $this->make_collection_with_covers((int) $owner->id);
        $this->assertCount(3, collection_cover::mosaic_tiles((int) $collection->id));

        $DB->set_field('local_oerexchange_resources', 'status', 'hidden', ['id' => $resources[0]->id]);

        $tiles = collection_cover::mosaic_tiles((int) $collection->id);

        $this->assertCount(2, $tiles);
        foreach ($tiles as $tile) {
            $this->assertStringNotContainsString(
                '/coverimage/' . $resources[0]->id . '/',
                $tile->out(false)
            );
        }
    }

    /**
     * A cover whose pixel dimensions are implausible is dropped rather than
     * decoded.
     *
     * A cover uploaded through save_from_draft() is capped at MAX_BYTES, but
     * one extracted from a course backup is not, and the pixel count rather
     * than the byte count is what GD allocates: a 20000x20000 PNG is a small
     * file that costs well over a gigabyte to decode. Dropping the one
     * oversized source here leaves only a single usable tile, so no mosaic is
     * made at all — which is the observable consequence.
     *
     * @return void
     */
    public function test_a_source_image_with_implausible_dimensions_is_dropped(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id]);

        $sane = $this->generator()->create_resource(['title' => 'Sane cover']);
        $this->attach_resource_cover((int) $sane->id, $this->png_bytes([10, 10, 200]));
        collection_manager::add_item((int) $collection->id, (int) $sane->id, (int) $owner->id);

        $huge = $this->generator()->create_resource(['title' => 'Absurdly wide cover']);
        $wide = collection_cover::MOSAIC_MAX_SOURCE_PIXELS + 1;
        $this->attach_resource_cover((int) $huge->id, $this->png_bytes([200, 10, 10], $wide, 4));
        collection_manager::add_item((int) $collection->id, (int) $huge->id, (int) $owner->id);

        $this->assertCount(
            2,
            collection_cover::mosaic_tiles((int) $collection->id),
            'both items do have covers; the builder is what refuses one of them'
        );
        $this->assertNull(
            collection_cover::display_url((int) $collection->id),
            'one usable tile is below MOSAIC_MIN_TILES, so there is no mosaic'
        );
    }

    /**
     * A 'coverimage' file that is not a decodable image contributes no tile.
     *
     * The backup-extraction path can store things the upload path refuses, so
     * the mosaic builder sniffs the bytes itself instead of trusting that
     * everything in that filearea is an image.
     *
     * @return void
     */
    public function test_a_cover_that_is_not_an_image_contributes_no_tile(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id]);

        $good = $this->generator()->create_resource(['title' => 'Real image']);
        $this->attach_resource_cover((int) $good->id, $this->png_bytes([10, 200, 10]));
        collection_manager::add_item((int) $collection->id, (int) $good->id, (int) $owner->id);

        $bad = $this->generator()->create_resource(['title' => 'Not an image']);
        $this->attach_resource_cover((int) $bad->id, '<html><body>not a picture</body></html>');
        collection_manager::add_item((int) $collection->id, (int) $bad->id, (int) $owner->id);

        $this->assertNull(
            collection_cover::display_url((int) $collection->id),
            'only one of the two covers can be decoded, which is not enough'
        );
        $this->assertSame([], $this->stored_files(collection_cover::MOSAIC_FILEAREA, (int) $collection->id));
    }

    /**
     * urls_for() answers for a whole page of collections in ONE query, and
     * leaves out the ones with no file.
     *
     * The file API has no batch accessor, so without this a browse page of
     * twenty cards would issue twenty queries just to decide whether to draw
     * an image. The read count is calibrated against a known single query
     * first, because what one logical query costs in reads depends on the
     * driver.
     *
     * @return void
     */
    public function test_urls_for_answers_a_whole_page_in_one_query(): void {
        global $DB;
        $this->resetAfterTest();
        $withcover = $this->generator()->create_collection(['title' => 'With cover']);
        $alsocovered = $this->generator()->create_collection(['title' => 'Also covered']);
        $bare = $this->generator()->create_collection(['title' => 'Bare']);
        foreach ([$withcover, $alsocovered] as $collection) {
            get_file_storage()->create_file_from_string([
                'contextid' => \context_system::instance()->id,
                'component' => 'local_oerexchange',
                'filearea' => collection_cover::FILEAREA,
                'itemid' => (int) $collection->id,
                'filepath' => '/',
                'filename' => 'cover.png',
            ], $this->png_bytes());
        }
        $ids = [(int) $withcover->id, (int) $alsocovered->id, (int) $bare->id];

        $start = $DB->perf_get_reads();
        $DB->count_records(collection_manager::TABLE);
        $readsperquery = $DB->perf_get_reads() - $start;
        $this->assertGreaterThan(0, $readsperquery, 'the driver counts reads, so the calibration is usable');

        $start = $DB->perf_get_reads();
        $urls = collection_cover::urls_for($ids);
        $used = $DB->perf_get_reads() - $start;

        $this->assertSame([(int) $withcover->id, (int) $alsocovered->id], array_keys($urls));
        $this->assertSame($readsperquery, $used, 'one query however many collections are asked about');
        $this->assertSame([], collection_cover::urls_for([]));
    }

    /**
     * The '.' entry the file API stores for a directory is not a file, and a
     * pluginfile URL built for it would 404.
     *
     * @return void
     */
    public function test_urls_for_ignores_the_directory_entry(): void {
        $this->resetAfterTest();
        $collection = $this->generator()->create_collection();
        get_file_storage()->create_directory(
            \context_system::instance()->id,
            'local_oerexchange',
            collection_cover::FILEAREA,
            (int) $collection->id,
            '/'
        );

        $this->assertSame([], collection_cover::urls_for([(int) $collection->id]));
        $this->assertNull(collection_cover::url_for((int) $collection->id));
    }

    /**
     * display_urls() is the batch read, and it deliberately builds nothing.
     *
     * Generating up to twenty images inside one page render is not a thing to
     * do on a request, so a collection whose mosaic has not been built yet is
     * simply absent from the batch answer and draws the default thumbnail
     * until somebody opens it.
     *
     * @return void
     */
    public function test_display_urls_never_builds_a_missing_mosaic(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        [$unbuilt] = $this->make_collection_with_covers((int) $owner->id);
        [$built] = $this->make_collection_with_covers((int) $owner->id);
        collection_cover::display_url((int) $built->id);

        $urls = collection_cover::display_urls([(int) $unbuilt->id, (int) $built->id]);

        $this->assertSame([(int) $built->id], array_keys($urls));
        $this->assertSame(
            [],
            $this->stored_files(collection_cover::MOSAIC_FILEAREA, (int) $unbuilt->id),
            'the batch read is a read'
        );
        $this->assertNotNull(
            collection_cover::display_url((int) $unbuilt->id),
            'and the single read still builds it when somebody actually opens the collection'
        );
    }

    /**
     * An uploaded cover wins over a built mosaic in the batch read too.
     *
     * Both files have to exist at once for the preference to mean anything,
     * and display_url() cannot produce that state (it returns the upload
     * without ever building the mosaic), so the leftover mosaic is written
     * straight into its filearea — which is also the real-world state after
     * an upload whose invalidation raced a concurrent build.
     *
     * @return void
     */
    public function test_display_urls_prefers_an_uploaded_cover_over_a_mosaic(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $this->setUser($owner);
        $collection = $this->generator()->create_collection(['userid' => (int) $owner->id]);
        $draftitemid = $this->create_draft((int) $owner->id, 'chosen.png', $this->png_bytes([7, 7, 7]));
        collection_cover::save_from_draft((int) $collection->id, $draftitemid);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_oerexchange',
            'filearea' => collection_cover::MOSAIC_FILEAREA,
            'itemid' => (int) $collection->id,
            'filepath' => '/',
            'filename' => collection_cover::MOSAIC_FILENAME_PREFIX . 'stale.png',
        ], $this->png_bytes([9, 9, 9]));

        $this->assertCount(1, $this->stored_files(collection_cover::FILEAREA, (int) $collection->id));
        $this->assertCount(1, $this->stored_files(collection_cover::MOSAIC_FILEAREA, (int) $collection->id));

        $urls = collection_cover::display_urls([(int) $collection->id]);

        $this->assertStringContainsString('chosen.png', $urls[(int) $collection->id]->out(false));
        $this->assertStringContainsString(
            'chosen.png',
            collection_cover::display_url((int) $collection->id)->out(false),
            'the single read agrees with the batch read'
        );
    }

    /**
     * The two fileareas have distinct names and the mosaic is capped at four
     * tiles, both of which the serving code in lib.php branches on.
     *
     * @return void
     */
    public function test_the_published_constants_are_what_the_serving_code_expects(): void {
        $this->resetAfterTest();

        $this->assertSame('collectioncover', collection_cover::FILEAREA);
        $this->assertSame('collectionmosaic', collection_cover::MOSAIC_FILEAREA);
        $this->assertNotSame(collection_cover::FILEAREA, collection_cover::MOSAIC_FILEAREA);
        $this->assertSame(4, collection_cover::MOSAIC_TILES);
        $this->assertSame(2, collection_cover::MOSAIC_MIN_TILES);
        $this->assertSame(
            collection_cover::MAX_BYTES,
            cover_image::MAX_BYTES,
            'the two cover areas are served by the same pluginfile callback, so a type or size '
                . 'one of them accepts and the other refuses would be a hole in whichever is laxer'
        );
        $this->assertSame(cover_image::ALLOWED_MIMETYPES, collection_cover::ALLOWED_MIMETYPES);
    }
}
