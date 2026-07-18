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
 * Serves a version's .mbz file. Two auth paths: a valid HMAC signature
 * (short-lived, used by WS clients and by sandbox trials — same-origin, no
 * Moodle session of their own), or a normal logged-in Moodle session
 * (browsing the catalogue directly).
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_oerexchange\local\download_signer;
use local_oerexchange\local\resource_manager;

require(__DIR__ . '/../../config.php');

$versionid = optional_param('v', 0, PARAM_INT) ?: required_param('id', PARAM_INT);
$exp = optional_param('exp', 0, PARAM_INT);
$sig = optional_param('sig', '', PARAM_ALPHANUM);

$signed = $sig !== '' && download_signer::verify($versionid, $exp, $sig);
if (!$signed) {
    require_login();
}

$version = $DB->get_record('local_oerexchange_versions', ['id' => $versionid], '*', MUST_EXIST);
$file = resource_manager::get_version_file($versionid);
if (!$file) {
    throw new moodle_exception('error_nofile', 'local_oerexchange');
}

send_stored_file($file, 0, 0, true, ['filename' => $version->filename]);
