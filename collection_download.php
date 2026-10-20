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
 * Serves a collection's prebuilt download zip.
 *
 * The same access rules as a single resource's download.php: a signed-in
 * session unless the site allows anonymous downloads, and only for someone
 * who may see the collection at all. Only a CURRENT zip is ever sent
 * (collection_zip::state() compares its signature with what the collection
 * holds now); otherwise the visitor goes back to the collection page, which
 * says why.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_oerexchange\local\collection_manager;
use local_oerexchange\local\collection_view;
use local_oerexchange\local\collection_zip;
use local_oerexchange\route\controller\collection_controller;

// Access control here is conditional (the anonymousdownload setting, or
// require_login() below), not absent, exactly as in download.php.
require(__DIR__ . '/../../config.php'); // phpcs:ignore moodle.Files.RequireLogin.Missing

$id = required_param('id', PARAM_INT);

if (!get_config('local_oerexchange', 'anonymousdownload')) {
    require_login();
}
if (!collection_view::enabled()) {
    throw new moodle_exception('error_collectionnotfound', 'local_oerexchange');
}

$collection = collection_manager::get($id);
if (!$collection || !collection_manager::user_can_view_collection($collection, (int) $USER->id)) {
    throw new moodle_exception('error_collectionnotfound', 'local_oerexchange');
}

$state = collection_zip::state($collection);
if ($state->state !== collection_zip::STATE_READY) {
    if ($state->state === collection_zip::STATE_PENDING) {
        collection_zip::queue((int) $collection->id);
    }
    redirect(
        collection_controller::collection_url((string) $collection->slug),
        get_string('collectionzipnotready', 'local_oerexchange'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

$downloadname = clean_filename((string) $collection->slug) . '.zip';
send_stored_file($state->file, 0, 0, true, ['filename' => $downloadname]);
