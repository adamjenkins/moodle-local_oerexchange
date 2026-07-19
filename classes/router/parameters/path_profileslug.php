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

namespace local_oerexchange\router\parameters;

use core\param;
use core\router\schema\referenced_object;
use core\router\schema\example;

/**
 * Routing parameter for an educator profile slug, e.g. /u/{slug}. Mirrors
 * core's path_themename (lib/classes/router/parameters/path_themename.php) —
 * same pattern, ALPHANUMEXT charset, no custom resolver.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class path_profileslug extends \core\router\schema\parameters\path_parameter implements referenced_object {
    /**
     * Create a new path_profileslug parameter.
     *
     * @param string $name The name of the parameter to use for the profile slug
     * @param mixed ...$args Additional arguments
     */
    public function __construct(
        string $name = 'slug',
        ...$args,
    ) {
        $args['name'] = $name;
        $args['type'] = param::ALPHANUMEXT;
        $args['description'] = 'An educator profile slug.';
        $args['examples'] = [
            new example(name: 'A profile slug', value: 'janedoe'),
        ];

        parent::__construct(...$args);
    }
}
