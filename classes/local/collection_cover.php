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
 * Collection cover images: the one a curator uploaded, the 2x2 mosaic built
 * from the collection's first four resources when they did not, and the two
 * shapes both are drawn in.
 *
 * Storage mirrors cover_image exactly — component local_oerexchange, system
 * context, itemid = collectionid — in two fileareas:
 *
 * - 'collectioncover': what the owner uploaded. At most one file.
 * - 'collectionmosaic': a single derived PNG, generated on demand from the
 *   covers of the first four items and thrown away by invalidate_mosaic()
 *   whenever membership or order changes. Nothing in here is authored, so it
 *   can always be rebuilt; it is a cache that happens to live in the file
 *   API, which is also what makes it servable by URL like any other image.
 *
 * Both areas need serving through local_oerexchange_pluginfile() behind the
 * collection's own view rule (collection_manager::user_can_view_collection()),
 * exactly as 'coverimage' is served behind the resource's — a cover for a
 * draft collection must 404 for everybody but its owner.
 *
 * WHY A GENERATED MOSAIC RATHER THAN A CSS GRID. A collection's cover is
 * also its og:image, and a social network's crawler fetches one image, not a
 * page fragment, so the mosaic has to exist as a file. Core was read before
 * choosing: there is no montage/composite API (/srv/lms/moodle/public/lib/
 * classes/ has no image class, only output/image_icon.php), and lib/gdlib.php's
 * helpers letterbox rather than crop (generate_image_thumbnail_from_string()
 * centres the image on a forced canvas), which leaves every tile of a cover
 * mosaic framed in empty margin. So the composing is plain GD here:
 * compose_mosaic() centre-crops each cover to fill its cell.
 * process_new_icon() is also core's own pattern for this shape of work:
 * derive an image, then store it in a filearea under an itemid. The
 * alternative — four <img> in a CSS grid — needs no GD at all but puts a
 * four-element layout in every consumer (card, list row, collection page,
 * blocks, and anything built later), which is precisely how cover_image's
 * two shapes were centralised here in the first place. mosaic_tiles() is
 * provided anyway, both because the mosaic builder needs it and because it is
 * the honest fallback on a server whose GD cannot composite.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class collection_cover {
    /** @var string Filearea holding an uploaded collection cover, itemid = collectionid. */
    const FILEAREA = 'collectioncover';

    /** @var string Filearea holding the generated mosaic, itemid = collectionid. */
    const MOSAIC_FILEAREA = 'collectionmosaic';

    /**
     * Filename prefix of the generated mosaic.
     *
     * The rest of the name is a hash of the image's own bytes, which makes
     * the file content-addressed and the URL therefore self-versioning. That
     * matters because pluginfile.php URLs are cacheable: with one fixed
     * filename, a collection whose mosaic was invalidated and rebuilt would
     * keep serving the browser's copy of the OLD image from the identical
     * URL. Nothing outside build_mosaic() needs to know the name — every
     * reader gets it from the {files} row (area_urls()).
     *
     * @var string
     */
    const MOSAIC_FILENAME_PREFIX = 'mosaic-';

    /** @var int Largest cover image accepted, matching cover_image::MAX_BYTES. */
    const MAX_BYTES = 5 * 1024 * 1024;

    /** @var int Height in px of the banner thumbnail on a collection card. */
    const CARD_HEIGHT = 160;

    /** @var int Side in px of the square thumbnail in a block's list row. */
    const LIST_SIZE = 56;

    /**
     * Mosaic width in px. With MOSAIC_HEIGHT, the 1.91:1 shape social
     * networks draw a large link-preview card at, since the mosaic is a
     * collection's og:image whenever no cover was uploaded.
     *
     * @var int
     */
    const MOSAIC_WIDTH = 1200;

    /** @var int Mosaic height in px; see MOSAIC_WIDTH. */
    const MOSAIC_HEIGHT = 630;

    /** @var int Px of background left between neighbouring tiles. */
    const MOSAIC_GUTTER = 4;

    /** @var int Most tiles in the mosaic: four, as a 2x2 grid. */
    const MOSAIC_TILES = 4;

    /**
     * Fewest tiles worth generating a mosaic from.
     *
     * Two. A "mosaic" of one image is just that image with three empty
     * quarters, which looks like a rendering failure rather than a design;
     * a collection with one cover falls through to the default thumbnail
     * instead.
     *
     * @var int
     */
    const MOSAIC_MIN_TILES = 2;

    /**
     * Widest or tallest source image the mosaic builder will decode.
     *
     * A cover uploaded through save_from_draft() is capped at MAX_BYTES, but a
     * cover extracted from a course backup is not, and a 20000x20000 PNG is a
     * small file that costs well over a gigabyte to decode. The pixel count,
     * not the byte count, is what GD allocates.
     *
     * @var int
     */
    const MOSAIC_MAX_SOURCE_PIXELS = 5000;

    /**
     * Mimetypes a cover image may claim.
     *
     * An explicit allowlist, not a "starts with image/" prefix test: core maps
     * a '.svg' filename to 'image/svg+xml', which begins with "image/" but is
     * not a raster format, and this filearea is served inline. Deliberately
     * identical to cover_image::ALLOWED_MIMETYPES — the two areas are served
     * by the same pluginfile callback, so a type one of them rejects and the
     * other accepts would be a hole in whichever is laxer.
     *
     * @var string[]
     */
    const ALLOWED_MIMETYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /**
     * Image types the actual bytes are allowed to be.
     *
     * @var int[]
     */
    const ALLOWED_IMAGE_TYPES = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

    /**
     * Validate a draft-area upload and make it a collection's cover image.
     *
     * The validation contract is cover_image::save_from_draft()'s, deliberately
     * unchanged, including the order of the checks:
     *
     * - Size FIRST, before anything reads the bytes. get_content() below pulls
     *   the whole file into memory, so checking size after it would let an
     *   author make the server buffer an arbitrarily large upload purely to
     *   reject it.
     * - The mimetype allowlist, which only trusts what core derived from the
     *   uploaded FILENAME.
     * - getimagesizefromstring(), which sniffs the real bytes and reports the
     *   type actually found. Content renamed to 'cover.png' passes the second
     *   check whatever it holds; a file must satisfy both.
     *
     * The draft area is always synced to the permanent one, including when it
     * is empty. That is not an oversight: the edit form prefills the picker
     * with the current cover, so an empty draft area on submit means the owner
     * removed the image, and skipping the sync would silently ignore them.
     *
     * Either way the mosaic is invalidated. An upload supersedes it, and a
     * removal means the mosaic is what display_url() will want next.
     *
     * @param int $collectionid
     * @param int $draftitemid the submitted draft file area
     * @return bool true if the collection now has an uploaded cover, false if it was
     *      removed or never had one
     * @throws \moodle_exception error_thumbnailtoolarge, error_thumbnailnotanimage
     */
    public static function save_from_draft(int $collectionid, int $draftitemid): bool {
        global $USER;

        $fs = get_file_storage();
        $usercontext = \context_user::instance((int) $USER->id);
        $draftfiles = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false);

        // Validate before saving, so a rejected file never reaches the
        // permanent area even briefly.
        //
        // Every file in the area is refused unless it is the one root-level
        // file the save below would keep. Validating only reset($draftfiles)
        // was a bypass: the draft itemid is client-supplied, the draft-file
        // AJAX lets a user create subdirectories, get_area_files() returns
        // files inside them, and file_save_draft_area_files() with
        // subdirs => 0 skips those BEFORE counting towards maxfiles
        // (lib/filelib.php, the merge loop). So a real PNG in /sub/ with a
        // lower id was validated while an HTML file at the root was stored
        // and served inline from the site origin. The filepicker this area
        // is edited through allows one file and no folders, so nothing a
        // legitimate form posts is refused here.
        if (count($draftfiles) > 1 || (($first = reset($draftfiles)) && $first->get_filepath() !== '/')) {
            throw new \moodle_exception('error_thumbnailnotanimage', 'local_oerexchange');
        }
        if (!empty($draftfiles)) {
            $draftfile = reset($draftfiles);

            if ($draftfile->get_filesize() > self::MAX_BYTES) {
                throw new \moodle_exception(
                    'error_thumbnailtoolarge',
                    'local_oerexchange',
                    '',
                    display_size(self::MAX_BYTES)
                );
            }
            if (!in_array((string) $draftfile->get_mimetype(), self::ALLOWED_MIMETYPES, true)) {
                throw new \moodle_exception('error_thumbnailnotanimage', 'local_oerexchange');
            }
            $imageinfo = @getimagesizefromstring($draftfile->get_content());
            if ($imageinfo === false || !in_array($imageinfo[2], self::ALLOWED_IMAGE_TYPES, true)) {
                throw new \moodle_exception('error_thumbnailnotanimage', 'local_oerexchange');
            }
        }

        file_save_draft_area_files(
            $draftitemid,
            \context_system::instance()->id,
            'local_oerexchange',
            self::FILEAREA,
            $collectionid,
            ['subdirs' => 0, 'maxfiles' => 1, 'maxbytes' => self::MAX_BYTES]
        );

        self::invalidate_mosaic($collectionid);

        // Read the outcome back rather than reporting what was intended:
        // file_save_draft_area_files() can decline to store anything (a draft
        // area over the site limit returns early), and saying "true" then
        // would claim a cover image that does not exist.
        $saved = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_oerexchange',
            self::FILEAREA,
            $collectionid,
            'id',
            false
        );

        return !empty($saved);
    }

    /**
     * Uploaded-cover URLs for many collections at once, in ONE query.
     *
     * The file API has no batch accessor — get_area_files() takes a single
     * itemid — so a browse page of 20 cards would otherwise issue 20 queries
     * to decide whether to draw an image. This reads the same rows
     * get_area_files() would, filtered the same way (the '.' entries the file
     * API stores for directories are not files).
     *
     * @param int[] $collectionids
     * @return \moodle_url[] keyed by collectionid; collections with no uploaded cover are absent
     */
    public static function urls_for(array $collectionids): array {
        return self::area_urls(self::FILEAREA, $collectionids);
    }

    /**
     * The uploaded-cover URL for one collection, or null if it has none.
     *
     * Deliberately does NOT fall back to the mosaic — that is display_url(),
     * and a caller asking "did somebody upload a cover for this?" (the edit
     * form, the privacy export) must get a straight answer.
     *
     * @param int $collectionid
     * @return \moodle_url|null
     */
    public static function url_for(int $collectionid): ?\moodle_url {
        return self::urls_for([$collectionid])[$collectionid] ?? null;
    }

    /**
     * The image to draw for a collection: the uploaded cover, else the
     * mosaic, generating it if it is not there yet, else null.
     *
     * Generation happens on the first request that needs it rather than when
     * items change, because the overwhelming majority of collections are
     * never looked at by a stranger and building an image for each of them on
     * every add would be work thrown away. The cost of this choice is that
     * one page view per invalidation decodes four images; the file it writes
     * serves every view after that.
     *
     * Returns null when there is nothing to draw — no upload, and fewer than
     * MOSAIC_MIN_TILES item covers, or a GD that cannot composite. card() and
     * listitem() both render the platform's default thumbnail for null, so a
     * collection without a picture still lays out like one that has one.
     *
     * @param int $collectionid
     * @return \moodle_url|null
     */
    public static function display_url(int $collectionid): ?\moodle_url {
        $uploaded = self::url_for($collectionid);
        if ($uploaded) {
            return $uploaded;
        }

        $mosaic = self::area_urls(self::MOSAIC_FILEAREA, [$collectionid])[$collectionid] ?? null;
        if ($mosaic) {
            return $mosaic;
        }

        return self::build_mosaic($collectionid);
    }

    /**
     * Display URLs for many collections at once, without generating anything.
     *
     * The browse page's batch counterpart to display_url(): two queries for a
     * whole page of cards. It deliberately does NOT build missing mosaics —
     * generating up to twenty images inside one page render is not a thing to
     * do on a request. A collection whose mosaic has not been built yet is
     * simply absent, draws the default thumbnail, and gets its mosaic the
     * first time somebody opens it.
     *
     * @param int[] $collectionids
     * @return \moodle_url[] keyed by collectionid; collections with nothing to draw are absent
     */
    public static function display_urls(array $collectionids): array {
        $uploaded = self::urls_for($collectionids);
        $mosaics = self::area_urls(self::MOSAIC_FILEAREA, $collectionids);

        // Uploaded covers win, which is what + does with the left operand.
        return $uploaded + $mosaics;
    }

    /**
     * Throw away a collection's generated mosaic.
     *
     * Called whenever anything the mosaic is built FROM could have changed:
     * an item added, removed or reordered (collection_manager), and a cover
     * uploaded or removed (save_from_draft()). Cheap, idempotent, and safe to
     * over-call — the next display_url() rebuilds.
     *
     * Deliberately not clever about whether the change actually affected the
     * first four tiles. Working that out costs a query and gets it wrong the
     * day somebody changes MOSAIC_TILES.
     *
     * @param int $collectionid
     */
    public static function invalidate_mosaic(int $collectionid): void {
        get_file_storage()->delete_area_files(
            \context_system::instance()->id,
            'local_oerexchange',
            self::MOSAIC_FILEAREA,
            $collectionid
        );
    }

    /**
     * Up to MOSAIC_TILES cover-image URLs from the start of a collection, in
     * curated order.
     *
     * Two consumers: build_mosaic(), which needs to know which resources to
     * read bytes from, and any renderer that would rather lay the tiles out
     * as a CSS grid than serve a generated PNG (see the class docblock).
     *
     * Only available items count, and only those that actually have a cover —
     * a mosaic is not the place to advertise that three of the collection's
     * resources are hidden.
     *
     * @param int $collectionid
     * @return \moodle_url[] zero to MOSAIC_TILES URLs, in curated order, re-indexed from 0
     */
    public static function mosaic_tiles(int $collectionid): array {
        $items = collection_manager::get_items($collectionid);
        if (!$items) {
            return [];
        }

        $resourceids = [];
        foreach ($items as $item) {
            $resourceids[] = (int) $item->resourceid;
        }

        // Covers for every item in ONE query, then taken in curated order:
        // the first four ITEMS are not the first four with covers.
        $covers = cover_image::urls_for($resourceids);

        $tiles = [];
        foreach ($resourceids as $resourceid) {
            if (isset($covers[$resourceid])) {
                $tiles[] = $covers[$resourceid];
                if (count($tiles) === self::MOSAIC_TILES) {
                    break;
                }
            }
        }

        return $tiles;
    }

    /**
     * The full-width banner thumbnail at the top of a collection card.
     *
     * Always returns markup, even with no image: a grid where only some cards
     * carry a picture lands each row's text at a different height, which reads
     * as a broken page rather than as "this one has no cover". Identical
     * contract to cover_image::card(), so the two kinds of card line up when
     * the blended catalogue interleaves them.
     *
     * @param \moodle_url|null $url from display_url()/url_for()
     * @return string HTML
     */
    public static function card(?\moodle_url $url): string {
        global $OUTPUT;

        $isdefault = $url === null;
        $src = $isdefault ? $OUTPUT->image_url('defaultthumbnail', 'local_oerexchange')->out(false) : $url->out(false);

        return \html_writer::empty_tag('img', [
            'src' => $src,
            'class' => 'oerexchange-thumb card-img-top' . ($isdefault ? ' oerexchange-thumb-default' : ''),
            'style' => 'height:' . self::CARD_HEIGHT . 'px;object-fit:cover;',
            'loading' => 'lazy',
            // Deliberately empty: every call site puts the collection's title
            // immediately next to this image as a link, so alt text here would
            // make a screen reader announce the same collection twice.
            'alt' => '',
        ]);
    }

    /**
     * The small square thumbnail beside a collection in a block's list.
     *
     * @param \moodle_url|null $url from display_url()/url_for()
     * @return string HTML
     */
    public static function listitem(?\moodle_url $url): string {
        global $OUTPUT;

        $isdefault = $url === null;
        $src = $isdefault ? $OUTPUT->image_url('defaultthumbnail', 'local_oerexchange')->out(false) : $url->out(false);

        return \html_writer::empty_tag('img', [
            'src' => $src,
            'class' => 'oerexchange-thumb rounded flex-shrink-0' . ($isdefault ? ' oerexchange-thumb-default' : ''),
            'style' => 'width:' . self::LIST_SIZE . 'px;height:' . self::LIST_SIZE . 'px;object-fit:cover;',
            'loading' => 'lazy',
            // Empty for the same reason as card(): the title is right there.
            'alt' => '',
        ]);
    }

    /**
     * Build and store the 2x2 mosaic, and return its URL.
     *
     * Returns null — never throws — whenever the mosaic cannot be made: too
     * few covers, a GD without the functions needed to composite, a source
     * image too large to decode safely, or a file-API refusal. A missing
     * cover picture must never be the reason a collection page 500s.
     *
     * @param int $collectionid
     * @return \moodle_url|null
     */
    protected static function build_mosaic(int $collectionid): ?\moodle_url {
        $png = self::compose_mosaic(self::tile_contents($collectionid));
        if ($png === null) {
            return null;
        }

        $fs = get_file_storage();
        $contextid = \context_system::instance()->id;
        // Content-addressed: see MOSAIC_FILENAME_PREFIX. Truncated to 16 hex
        // characters, which is a cache key rather than a security claim.
        $filename = self::MOSAIC_FILENAME_PREFIX . substr(sha1($png), 0, 16) . '.png';
        $record = [
            'contextid' => $contextid,
            'component' => 'local_oerexchange',
            'filearea' => self::MOSAIC_FILEAREA,
            'itemid' => $collectionid,
            'filepath' => '/',
            'filename' => $filename,
        ];

        try {
            $fs->create_file_from_string($record, $png);
        } catch (\stored_file_creation_exception $e) {
            // Two requests can reach an un-built mosaic at the same moment.
            // The loser does not need to rebuild or complain: both composed
            // the same image from the same inputs, so the name is the same
            // name and the winner's file is the file we wanted.
            if (
                !$fs->get_file(
                    $contextid,
                    'local_oerexchange',
                    self::MOSAIC_FILEAREA,
                    $collectionid,
                    '/',
                    $filename
                )
            ) {
                return null;
            }
        }

        return \moodle_url::make_pluginfile_url(
            $contextid,
            'local_oerexchange',
            self::MOSAIC_FILEAREA,
            $collectionid,
            '/',
            $filename
        );
    }

    /**
     * The raw bytes of up to MOSAIC_TILES item cover images, in curated order.
     *
     * Read through the file API rather than fetched over HTTP from the URLs
     * mosaic_tiles() returns: the files are right here, and fetching our own
     * pluginfile URLs would be a self-inflicted SSRF-shaped round trip
     * through the view gate.
     *
     * Sources whose pixel dimensions are implausible are dropped rather than
     * decoded — see MOSAIC_MAX_SOURCE_PIXELS.
     *
     * @param int $collectionid
     * @return string[] image file contents, in curated order
     */
    protected static function tile_contents(int $collectionid): array {
        $fs = get_file_storage();
        $contextid = \context_system::instance()->id;

        $contents = [];
        foreach (collection_manager::get_items($collectionid) as $item) {
            $files = $fs->get_area_files(
                $contextid,
                'local_oerexchange',
                'coverimage',
                (int) $item->resourceid,
                'id',
                false
            );
            if (!$files) {
                continue;
            }

            $file = reset($files);
            if ($file->get_filesize() > self::MAX_BYTES) {
                continue;
            }

            $content = $file->get_content();
            $imageinfo = @getimagesizefromstring($content);
            if (
                $imageinfo === false
                    || !in_array($imageinfo[2], self::ALLOWED_IMAGE_TYPES, true)
                    || $imageinfo[0] > self::MOSAIC_MAX_SOURCE_PIXELS
                    || $imageinfo[1] > self::MOSAIC_MAX_SOURCE_PIXELS
            ) {
                continue;
            }

            $contents[] = $content;
            if (count($contents) === self::MOSAIC_TILES) {
                break;
            }
        }

        return $contents;
    }

    /**
     * Compose up to four covers into one MOSAIC_WIDTH x MOSAIC_HEIGHT PNG.
     *
     * The layout follows the number of covers, so the result never has an
     * empty quarter: two sit side by side, three are one large tile beside
     * two stacked ones, four make a 2x2 grid. Each cover is centre-cropped to
     * its cell's shape and scaled to fill it, which is what makes a mosaic of
     * differently shaped covers read as one picture. Fewer than
     * MOSAIC_MIN_TILES never reaches here.
     *
     * @param string[] $contents raw image bytes, in the order to lay them out
     * @return string|null PNG data, or null if this server's GD cannot do it
     */
    protected static function compose_mosaic(array $contents): ?string {
        foreach (['imagecreatetruecolor', 'imagecopyresampled', 'imagecreatefromstring', 'imagepng'] as $fn) {
            if (!function_exists($fn)) {
                return null;
            }
        }

        $images = [];
        foreach (array_slice($contents, 0, self::MOSAIC_TILES) as $content) {
            $image = @imagecreatefromstring($content);
            if ($image) {
                $images[] = $image;
            }
        }
        if (count($images) < self::MOSAIC_MIN_TILES) {
            array_map('imagedestroy', $images);
            return null;
        }

        $canvas = @imagecreatetruecolor(self::MOSAIC_WIDTH, self::MOSAIC_HEIGHT);
        if (!$canvas) {
            array_map('imagedestroy', $images);
            return null;
        }

        try {
            // A light neutral behind the tiles, which is all the gutters show.
            $background = imagecolorallocate($canvas, 233, 236, 239);
            imagefilledrectangle($canvas, 0, 0, self::MOSAIC_WIDTH - 1, self::MOSAIC_HEIGHT - 1, $background);

            foreach (self::mosaic_cells(count($images)) as $index => [$x, $y, $w, $h]) {
                $image = $images[$index];
                $sw = imagesx($image);
                $sh = imagesy($image);
                // The largest centred region of the source with the cell's
                // aspect ratio: crop the long side, never the short one.
                if ($sw * $h > $sh * $w) {
                    $cropw = (int) round($sh * $w / $h);
                    $croph = $sh;
                } else {
                    $cropw = $sw;
                    $croph = (int) round($sw * $h / $w);
                }
                $srcx = intdiv($sw - $cropw, 2);
                $srcy = intdiv($sh - $croph, 2);
                imagecopyresampled($canvas, $image, $x, $y, $srcx, $srcy, $w, $h, max(1, $cropw), max(1, $croph));
            }

            ob_start();
            $ok = imagepng($canvas, null, 9, PNG_NO_FILTER);
            $png = ob_get_clean();

            return ($ok && $png !== false && $png !== '') ? $png : null;
        } finally {
            imagedestroy($canvas);
            array_map('imagedestroy', $images);
        }
    }

    /**
     * The cells of the mosaic layout for a given number of tiles, gutters
     * already taken out.
     *
     * @param int $count 2, 3 or 4
     * @return array[] one [x, y, width, height] per tile, in tile order
     */
    protected static function mosaic_cells(int $count): array {
        $g = intdiv(self::MOSAIC_GUTTER, 2);
        $halfw = intdiv(self::MOSAIC_WIDTH, 2);
        $halfh = intdiv(self::MOSAIC_HEIGHT, 2);
        $full = self::MOSAIC_HEIGHT;

        // Each region is [x, y, width, height] before the gutter is taken out.
        if ($count === 2) {
            $regions = [[0, 0, $halfw, $full], [$halfw, 0, $halfw, $full]];
        } else if ($count === 3) {
            $regions = [[0, 0, $halfw, $full], [$halfw, 0, $halfw, $halfh], [$halfw, $halfh, $halfw, $full - $halfh]];
        } else {
            $regions = [
                [0, 0, $halfw, $halfh],
                [$halfw, 0, $halfw, $halfh],
                [0, $halfh, $halfw, $full - $halfh],
                [$halfw, $halfh, $halfw, $full - $halfh],
            ];
        }

        // Inset only the edges that touch another tile, so the outer border
        // stays flush with the canvas.
        $cells = [];
        foreach ($regions as [$x, $y, $w, $h]) {
            $left = $x > 0 ? $g : 0;
            $top = $y > 0 ? $g : 0;
            $right = ($x + $w) < self::MOSAIC_WIDTH ? $g : 0;
            $bottom = ($y + $h) < self::MOSAIC_HEIGHT ? $g : 0;
            $cells[] = [$x + $left, $y + $top, $w - $left - $right, $h - $top - $bottom];
        }

        return $cells;
    }

    /**
     * File URLs for one of this class's fileareas, for many itemids, in ONE
     * query.
     *
     * The query shape is cover_image::urls_for()'s. It is repeated rather than
     * shared because that method is hard-wired to resources and the
     * 'coverimage' area, and widening a method the catalogue and three blocks
     * depend on, in a parallel piece of work, is the riskier of the two
     * duplications. The two can be folded together later; what must not
     * happen is one of them quietly starting to include the '.' directory
     * rows.
     *
     * @param string $filearea
     * @param int[] $itemids
     * @return \moodle_url[] keyed by itemid; itemids with no file are absent
     */
    protected static function area_urls(string $filearea, array $itemids): array {
        global $DB;

        $itemids = array_values(array_unique(array_map('intval', $itemids)));
        if (!$itemids) {
            return [];
        }

        $contextid = \context_system::instance()->id;
        [$insql, $inparams] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'itemid');
        $files = $DB->get_records_select(
            'files',
            "contextid = :contextid
                 AND component = :component
                 AND filearea = :filearea
                 AND itemid $insql
                 AND filename <> '.'",
            $inparams + [
                'contextid' => $contextid,
                'component' => 'local_oerexchange',
                'filearea' => $filearea,
            ],
            'itemid ASC, id ASC',
            'id, itemid, filepath, filename'
        );

        $urls = [];
        foreach ($files as $file) {
            $itemid = (int) $file->itemid;
            // Both areas are capped at one file everywhere they are written,
            // but ordering by id and keeping the first means an area holding
            // several still resolves the same way every time rather than at
            // the database's discretion.
            if (isset($urls[$itemid])) {
                continue;
            }
            $urls[$itemid] = \moodle_url::make_pluginfile_url(
                $contextid,
                'local_oerexchange',
                $filearea,
                $itemid,
                $file->filepath,
                $file->filename
            );
        }

        return $urls;
    }
}
