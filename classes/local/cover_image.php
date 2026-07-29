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
