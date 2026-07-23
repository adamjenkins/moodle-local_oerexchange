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
 * Web service definitions for local_oerexchange.
 *
 * Two truly anonymous steps in the design (initial site registration, and
 * exchanging a one-time link code for a freshly minted personal token) have
 * no WS token to authenticate with yet, so they are NOT external functions
 * here — they are plain public endpoints (register.php, link_consume.php).
 * Every function below runs under a real core WS token: either a site's
 * dedicated service-account token (search, get_resource, record_import,
 * get_config — see local_oerexchange\local\site_manager) or a teacher's
 * personally-linked token (publish_resource — see link_manager).
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_oerexchange_search' => [
        'classname'   => 'local_oerexchange\external\search',
        'methodname'  => 'execute',
        'description' => 'Search/browse the resource catalogue.',
        'type'        => 'read',
        'ajax'        => false,
    ],
    'local_oerexchange_get_resource' => [
        'classname'   => 'local_oerexchange\external\get_resource',
        'methodname'  => 'execute',
        'description' => 'Get full detail for one resource, including structure preview.',
        'type'        => 'read',
        'ajax'        => false,
    ],
    'local_oerexchange_publish_resource' => [
        'classname'   => 'local_oerexchange\external\publish_resource',
        'methodname'  => 'execute',
        'description' => 'Publish an uploaded backup (draft area) as a new resource or version.',
        'type'        => 'write',
        'ajax'        => false,
    ],
    'local_oerexchange_record_import' => [
        'classname'   => 'local_oerexchange\external\record_import',
        'methodname'  => 'execute',
        'description' => 'Record a completed import on a client site.',
        'type'        => 'write',
        'ajax'        => false,
    ],
    'local_oerexchange_get_share_status' => [
        'classname'   => 'local_oerexchange\external\get_share_status',
        'methodname'  => 'execute',
        'description' => 'Current Exchange-side state of one of the caller\'s own shared resources.',
        'type'        => 'read',
        'ajax'        => false,
    ],

    'local_oerexchange_get_config' => [
        'classname'   => 'local_oerexchange\external\get_config',
        'methodname'  => 'execute',
        'description' => 'Advertised Exchange limits/capabilities for a registered site.',
        'type'        => 'read',
        'ajax'        => false,
    ],
];

$services = [
    'OER Exchange service' => [
        'functions' => [
            'local_oerexchange_search',
            'local_oerexchange_get_resource',
            'local_oerexchange_publish_resource',
            'local_oerexchange_record_import',
            'local_oerexchange_get_config',
            'local_oerexchange_get_share_status',
        ],
        'restrictedusers' => 0,
        'enabled' => 1,
        'shortname' => 'local_oerexchange',
        'downloadfiles' => 1,
        'uploadfiles' => 1,
    ],
];
