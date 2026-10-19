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

/**
 * Library functions for local_oerexchange, containing only the callbacks
 * Moodle core discovers by name (pluginfile serving). All other plugin
 * logic lives under classes/ per this plugin's existing convention.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Maps this plugin's icon names onto Moodle's bundled FontAwesome (6.7.2 as
 * of Moodle 5.2), so the share buttons show each network's own logo without
 * this plugin shipping any icon artwork or a FontAwesome copy of its own.
 *
 * @return array<string, string> 'component:iconname' => FontAwesome classes
 */
function local_oerexchange_get_fontawesome_icon_map() {
    return [
        'local_oerexchange:sharetarget_copylink' => 'fa-solid fa-link',
        'local_oerexchange:sharetarget_nativeshare' => 'fa-solid fa-share-nodes',
        'local_oerexchange:sharetarget_mastodon' => 'fa-brands fa-mastodon',
        'local_oerexchange:sharetarget_facebook' => 'fa-brands fa-facebook',
        'local_oerexchange:sharetarget_x' => 'fa-brands fa-x-twitter',
        'local_oerexchange:sharetarget_linkedin' => 'fa-brands fa-linkedin',
        'local_oerexchange:sharetarget_email' => 'fa-solid fa-envelope',
        'local_oerexchange:sharetarget_sms' => 'fa-solid fa-comment-sms',
    ];
}

/**
 * Serves files from the local_oerexchange file areas via pluginfile.php.
 *
 * Three areas are servable this way, all itemid-keyed and all stored under
 * context_system::instance():
 *
 * - 'coverimage', keyed by resource id (parse_backup_task);
 * - collection_cover::FILEAREA, the cover a collection's owner uploaded;
 * - collection_cover::MOSAIC_FILEAREA, the mosaic generated from the covers
 *   of a collection's first few items.
 *
 * Backup .mbz files (local_oerexchange_versions) are deliberately NOT exposed
 * here — they are already served by download.php with its own
 * signed-URL/anonymous-download access control, and must stay off the generic
 * pluginfile.php path.
 *
 * Every area re-derives its authorisation from the database rather than
 * trusting the URL. A cover image is only ever attached to a resource once it
 * is stored, and resource.php only links to it from the resource's own page;
 * this mirrors resource.php's own view gate (status === 'published', or the
 * viewer holds local/oerexchange:moderate) rather than the file API's default
 * of "anyone who can guess the itemid" — thumbnails for an
 * unpublished/pending resource should not be independently discoverable from
 * a bare pluginfile.php URL. The two collection areas ask
 * collection_manager::user_can_view_collection() for the same reason: a draft
 * collection is its owner's alone, so its cover must not be world-readable to
 * anybody who guesses the id, and a collection under takedown or tombstoned
 * stops serving its images at the same moment its page does.
 *
 * @param stdClass|null $course
 * @param stdClass|null $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool false if the file was not served (core sends a 404)
 */
function local_oerexchange_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB, $USER;

    if ($context->contextlevel != CONTEXT_SYSTEM) {
        return false;
    }

    $collectionareas = [
        \local_oerexchange\local\collection_cover::FILEAREA,
        \local_oerexchange\local\collection_cover::MOSAIC_FILEAREA,
    ];

    if ($filearea === 'coverimage') {
        $itemid = (int) array_shift($args);
        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $itemid]);
        if (!$resource) {
            return false;
        }
        // The same gate resource.php itself uses (published = public;
        // otherwise the creator or a moderator) — the previous
        // published-or-moderator rule was stricter than the page's, so an
        // author viewing their own hidden or pending resource got its page
        // but a broken cover image.
        if (!\local_oerexchange\local\resource_manager::user_can_view_resource($resource, (int) $USER->id)) {
            return false;
        }
    } else if (in_array($filearea, $collectionareas, true)) {
        $itemid = (int) array_shift($args);
        $collection = \local_oerexchange\local\collection_manager::get($itemid);
        if (!$collection) {
            return false;
        }
        // The collection page's own 404 gate, asked of the collection record
        // read back here rather than of anything in the URL: draft = the
        // owner alone, modhidden = the owner and moderators, tombstoned =
        // nobody (its files have been deleted anyway, so this only decides
        // whether a stale URL 404s or serves). Unlisted deliberately passes
        // for anyone holding the link, which is exactly what a pluginfile
        // URL in the page is.
        if (!\local_oerexchange\local\collection_manager::user_can_view_collection($collection, (int) $USER->id)) {
            return false;
        }
    } else {
        return false;
    }

    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_oerexchange', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    // Every area here holds images only, and the upload paths now enforce
    // that (cover_image::save_from_draft()). Up to 1.0.13 they did not: a
    // second file placed beside a validated one could be stored, and
    // send_stored_file() serves text/html inline. Refusing anything that is
    // not an allowed image at the point of serving neutralises such a file
    // on a site that already holds one, without needing to find it first.
    if (!in_array((string) $file->get_mimetype(), \local_oerexchange\local\cover_image::ALLOWED_MIMETYPES, true)) {
        return false;
    }

    // Send_stored_file() sends the response and terminates the request; it
    // does not return.
    send_stored_file($file, DAYSECS, 0, $forcedownload, $options);
}
