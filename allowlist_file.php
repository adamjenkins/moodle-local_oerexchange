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
 * Serves a same-origin mirrored plugin ZIP from the sandbox plugin allowlist,
 * so the playground's installMoodlePlugin blueprint step never fetches an
 * arbitrary third-party host. Public: these are already-public plugin
 * releases the admin deliberately curated. See DESIGN.md §2 pluginallowlist.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

$entry = $DB->get_record('local_oerexchange_pluginallowlist', ['id' => $id, 'status' => 'active'], '*', MUST_EXIST);

$fs = get_file_storage();
$context = context_system::instance();
$files = $fs->get_area_files($context->id, 'local_oerexchange', 'allowlist', $entry->id, 'id', false);
$file = $files ? reset($files) : null;
if (!$file) {
    throw new moodle_exception('error_nofile', 'local_oerexchange');
}

send_stored_file($file, 3600, 0, true);
