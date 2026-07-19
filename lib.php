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
 * Serves files from the local_oerexchange file areas via pluginfile.php.
 *
 * Only the 'coverimage' area (parse_backup_task, resourceid-keyed, stored
 * under context_system::instance()) is servable this way. Backup .mbz files
 * (local_oerexchange_versions) are deliberately NOT exposed here — they are
 * already served by download.php with its own signed-URL/anonymous-download
 * access control, and must stay off the generic pluginfile.php path.
 *
 * A cover image is only ever attached to a resource once it is stored, and
 * resource.php only links to it from the resource's own page. This mirrors
 * resource.php's own view gate (status === 'published', or the viewer holds
 * local/oerexchange:moderate) rather than the file API's default of "anyone
 * who can guess the itemid" — thumbnails for an unpublished/pending resource
 * should not be independently discoverable from a bare pluginfile.php URL.
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
    global $DB;

    if ($context->contextlevel != CONTEXT_SYSTEM) {
        return false;
    }
    if ($filearea !== 'coverimage') {
        return false;
    }

    $resourceid = (int) array_shift($args);
    $resource = $DB->get_record('local_oerexchange_resources', ['id' => $resourceid]);
    if (!$resource) {
        return false;
    }
    if ($resource->status !== 'published' && !has_capability('local/oerexchange:moderate', $context)) {
        return false;
    }

    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_oerexchange', 'coverimage', $resourceid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    // Send_stored_file() sends the response and terminates the request; it
    // does not return.
    send_stored_file($file, DAYSECS, 0, $forcedownload, $options);
}
