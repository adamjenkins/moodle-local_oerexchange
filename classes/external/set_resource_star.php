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
use local_oerexchange\local\resource_manager;
use local_oerexchange\local\star_manager;

/**
 * local_oerexchange_set_resource_star external function — star or unstar a
 * resource from the browser.
 *
 * AJAX-only and session-authenticated, like get_publish_status and for the same
 * reason: this answers a visitor's own browser on this site, so it is
 * deliberately kept out of the token-facing "OER Exchange service" that client
 * sites call. A remote site has no business starring on someone's behalf.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_resource_star extends external_api {
    /**
     * Describes the parameters this function accepts.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'resourceid' => new external_value(PARAM_INT, 'Resource being starred or unstarred'),
            'starred' => new external_value(PARAM_BOOL, 'True to star, false to unstar'),
        ]);
    }

    /**
     * Set the calling user's star on a resource.
     *
     * @param int $resourceid
     * @param bool $starred
     * @return array
     */
    public static function execute(int $resourceid, bool $starred): array {
        global $DB, $USER;

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['resourceid' => $resourceid, 'starred' => $starred]
        );

        // The system context, matching the context the star is recorded in and
        // the context this plugin's resources live in. Not the resource's
        // "own" context — there isn't one.
        $context = \context_system::instance();
        self::validate_context($context);

        // A guest has a user id but must not be able to write. In practice
        // only the guest reaches this line: a logged-OUT caller is already
        // refused by validate_context() above, which ends in require_login()
        // (lib/external/classes/external_api.php), so that case surfaces as
        // core's requireloginerror rather than this string.
        if (!isloggedin() || isguestuser()) {
            throw new \moodle_exception('error_notloggedin', 'local_oerexchange');
        }

        // IGNORE_MISSING, not MUST_EXIST, so that "no such resource" and "a
        // resource you may not see" are the SAME refusal. MUST_EXIST raises
        // dml_missing_record_exception, whose message names the table and
        // whose error code differs from the one below — which would hand
        // somebody probing ids exactly the distinction the visibility check
        // underneath exists to deny them.
        $resource = $DB->get_record(
            'local_oerexchange_resources',
            ['id' => $params['resourceid']],
            '*',
            IGNORE_MISSING
        );

        // Re-derive visibility server-side rather than trusting that the
        // caller could only have got the id from a page they were allowed to
        // see. Starring is not a read, but it should not be a way to confirm
        // that a hidden or taken-down resource exists either.
        if (!$resource || !resource_manager::user_can_view_resource($resource, (int) $USER->id)) {
            throw new \moodle_exception('error_notfound', 'local_oerexchange');
        }

        $now = star_manager::set_starred(
            (int) $resource->id,
            (int) $USER->id,
            (bool) $params['starred']
        );

        return [
            'starred' => $now,
            'count' => star_manager::star_count((int) $resource->id),
        ];
    }

    /**
     * Describes what this function returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'starred' => new external_value(PARAM_BOOL, 'Whether the caller now stars this resource'),
            'count' => new external_value(PARAM_INT, 'How many people star it'),
        ]);
    }
}
