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
 * Public, tokenless endpoint: exchange a one-time linkcode (issued by
 * connect.php after the teacher logged in) for the personal WS token it was
 * minted for. Single-use — see local_oerexchange\local\link_manager.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_oerexchange\local\link_manager;

define('NO_MOODLE_COOKIES', true);
require(__DIR__ . '/../../config.php');

header('Content-Type: application/json');

$code = required_param('code', PARAM_ALPHANUM);

try {
    $result = link_manager::consume($code);
    echo json_encode(['token' => $result->token, 'userid' => $result->userid]);
} catch (\moodle_exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
