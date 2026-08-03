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
 * Cover-image thumbnails: where to find them, and the two shapes they are
 * drawn in across the platform.
 *
 * A cover image is stored under component local_oerexchange, filearea
 * 'coverimage', itemid = resourceid, in the system context — extracted from a
 * course backup's overviewfiles by parse_backup_task, or uploaded by the
 * author on resource.php. Serving is gated by local_oerexchange_pluginfile()
 * (lib.php), which applies the resource's own view rule, so a URL built here
 * for a resource the viewer may not see resolves to a 404 rather than leaking
 * anything.
 *
 * Both the lookup and the markup live here on purpose: the catalogue page and
 * the three Exchange blocks all draw the same two shapes, and four copies of
 * "img, or the default thumbnail if there is no image" is how they drift
 * apart. A resource whose author added no cover draws the platform's default
 * thumbnail (pix/defaultthumbnail.jpg) at the same size.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cover_image {
    /** @var int Height in px of the banner thumbnail on a catalogue card. */
    const CARD_HEIGHT = 160;

    /** @var int Side in px of the square thumbnail in a block's list row. */
    const LIST_SIZE = 56;

    /** @var int Largest cover image accepted, sized for a thumbnail rather than a course backup. */
    const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Mimetypes a cover image may claim.
     *
     * An explicit allowlist, not a "starts with image/" prefix test: core maps
     * a '.svg' filename to 'image/svg+xml', which begins with "image/" but is
     * not a raster format, and this filearea is served inline. Kept in step
     * with mbz_parser::is_allowed_cover_image_type(), which guards the sibling
     * cover-image-from-backup path.
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
     * Validate a draft-area upload and make it a resource's cover image.
     *
     * The validation lives here rather than at the call site because this
     * class already owns the storage contract, and because there are now two
     * writers — the edit form and parse_backup_task's extraction from a
     * backup. Two copies of "is this really a raster image" is how one of them
     * ends up weaker than the other.
     *
     * Both checks are kept, deliberately, and neither replaces the other: the
     * mimetype allowlist only trusts what core derived from the uploaded
     * FILENAME, so content renamed to 'cover.png' passes it whatever it holds;
     * getimagesizefromstring() sniffs the real bytes and reports the type it
     * actually found. A file must satisfy both.
     *
     * The draft area is always synced to the permanent one, including when it
     * is empty. That is not an oversight: the edit form prefills the picker
     * with the current cover, so an empty draft area on submit means the author
     * removed the image, and skipping the sync would silently ignore them.
     *
     * @param int $resourceid the resource whose cover this becomes
     * @param int $draftitemid the submitted draft file area
     * @return bool true if the resource now has a cover image, false if it was
     *      removed or never had one
     * @throws \moodle_exception if the file is not an acceptable image
     */
    public static function save_from_draft(int $resourceid, int $draftitemid): bool {
        global $USER;

        $fs = get_file_storage();
        $usercontext = \context_user::instance((int) $USER->id);
        $draftfiles = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false);

        // Validate before saving, so a rejected file never reaches the
        // permanent area even briefly.
        if (!empty($draftfiles)) {
            $draftfile = reset($draftfiles);

            // Size FIRST, before anything reads the bytes. get_content()
            // below pulls the whole file into memory, so checking size after
            // it would let an author make the server buffer an arbitrarily
            // large upload purely to reject it.
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
            'coverimage',
            $resourceid,
            ['subdirs' => 0, 'maxfiles' => 1, 'maxbytes' => self::MAX_BYTES]
        );

        // Read the outcome back rather than reporting what was intended:
        // file_save_draft_area_files() can decline to store anything (a draft
        // area over the site limit returns early), and saying "true" then
        // would claim a cover image that does not exist.
        $saved = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_oerexchange',
            'coverimage',
            $resourceid,
            'id',
            false
        );

        return !empty($saved);
    }

    /**
     * Cover-image URLs for many resources at once, in ONE query.
     *
     * The file API has no batch accessor — get_area_files() takes a single
     * itemid — so a catalogue page of 20 cards would otherwise issue 20
     * queries to decide whether to draw an image. This reads the same rows
     * get_area_files() would, filtered the same way (the '.' entries the file
     * API stores for directories are not files).
     *
     * @param int[] $resourceids
     * @return \moodle_url[] keyed by resourceid; resources with no cover image are absent
     */
    public static function urls_for(array $resourceids): array {
        global $DB;

        $resourceids = array_values(array_unique(array_map('intval', $resourceids)));
        if (!$resourceids) {
            return [];
        }

        $contextid = \context_system::instance()->id;
        [$insql, $inparams] = $DB->get_in_or_equal($resourceids, SQL_PARAMS_NAMED, 'itemid');
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
                'filearea' => 'coverimage',
            ],
            'itemid ASC, id ASC',
            'id, itemid, filepath, filename'
        );

        $urls = [];
        foreach ($files as $file) {
            $resourceid = (int) $file->itemid;
            // The filearea is capped at one file per resource everywhere it
            // is written (maxfiles => 1), but ordering by id and keeping the
            // first means a legacy area holding several still resolves the
            // same way every time rather than at the DB's discretion.
            if (isset($urls[$resourceid])) {
                continue;
            }
            $urls[$resourceid] = \moodle_url::make_pluginfile_url(
                $contextid,
                'local_oerexchange',
                'coverimage',
                $resourceid,
                $file->filepath,
                $file->filename
            );
        }

        return $urls;
    }

    /**
     * The cover-image URL for one resource, or null if it has none.
     *
     * @param int $resourceid
     * @return \moodle_url|null
     */
    public static function url_for(int $resourceid): ?\moodle_url {
        return self::urls_for([$resourceid])[$resourceid] ?? null;
    }

    /**
     * The full-width banner thumbnail at the top of a catalogue card.
     *
     * Always returns markup, even with no image: a grid where only some cards
     * carry a picture lands each row's text at a different height, which
     * reads as a broken page rather than as "this one has no cover". A
     * resource with no custom cover draws the platform's default thumbnail
     * at the same size.
     *
     * @param \moodle_url|null $url from url_for()/urls_for()
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
            // Deliberately empty: every call site puts the resource's title
            // immediately next to this image as a link, so alt text here
            // would make a screen reader announce the same resource twice.
            'alt' => '',
        ]);
    }

    /**
     * The small square thumbnail beside a resource in a block's list.
     *
     * A resource with no custom cover draws the platform's default thumbnail
     * at the same size, for the same row-alignment reason as card().
     *
     * @param \moodle_url|null $url from url_for()/urls_for()
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
}
