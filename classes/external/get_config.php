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

namespace local_oerexchange\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * local_oerexchange_get_config external function — advertised Exchange
 * limits/capabilities, also usable by a client as a lightweight
 * "is my token still valid" health check.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_config extends external_api {
    /**
     * Describes the (empty) parameters this function accepts.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Returns the Exchange's advertised limits/capabilities.
     *
     * @return array
     */
    public static function execute(): array {
        global $CFG;

        self::validate_parameters(self::execute_parameters(), []);
        self::validate_context(\context_system::instance());

        require_once($CFG->libdir . '/licenselib.php');

        $maxbytes = (int) get_config('local_oerexchange', 'maxbackupbytes') ?: (500 * 1024 * 1024);
        $licenses = \license_manager::get_licenses();

        return [
            'maxbackupbytes' => $maxbytes,
            'sandboxenabled' => (bool) get_config('local_oerexchange', 'sandboxenabled'),
            'acceptedlicenses' => implode(',', $licenses ? array_keys($licenses) : []),
        ];
    }

    /**
     * Describes the structure of execute()'s return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'maxbackupbytes' => new external_value(PARAM_INT, 'Maximum accepted backup file size, bytes'),
            'sandboxenabled' => new external_value(PARAM_BOOL, 'Whether the Try-it sandbox is currently enabled'),
            'acceptedlicenses' => new external_value(PARAM_RAW, 'Comma-separated license shortnames'),
        ]);
    }
}
