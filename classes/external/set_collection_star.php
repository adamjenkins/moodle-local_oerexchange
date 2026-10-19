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
use local_oerexchange\local\collection_manager;
use local_oerexchange\local\star_manager;

/**
 * local_oerexchange_set_collection_star external function — star or unstar a
 * collection from the browser.
 *
 * AJAX-only and session-authenticated, like set_resource_star and for the same
 * reason: this answers a visitor's own browser on this site, so it is
 * deliberately kept out of the token-facing "OER Exchange service" that client
 * sites call. A remote site has no business starring on someone's behalf.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_collection_star extends external_api {
    /**
     * Describes the parameters this function accepts.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'collectionid' => new external_value(PARAM_INT, 'Collection being starred or unstarred'),
            'starred' => new external_value(PARAM_BOOL, 'True to star, false to unstar'),
        ]);
    }

    /**
     * Set the calling user's star on a collection.
     *
     * @param int $collectionid
     * @param bool $starred
     * @return array
     */
    public static function execute(int $collectionid, bool $starred): array {
        global $USER;

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['collectionid' => $collectionid, 'starred' => $starred]
        );

        // The system context, matching the context the star is recorded in and
        // the context this plugin's collections live in. Not the collection's
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

        // The collection_manager::get() call is already the IGNORE_MISSING spelling —
        // it returns null rather than raising dml_missing_record_exception —
        // so that "no such collection" and "a collection you may not see" are
        // the SAME refusal below. MUST_EXIST would name the table in its
        // message and carry a different error code, handing somebody probing
        // ids exactly the distinction the visibility check exists to deny
        // them.
        $collection = collection_manager::get((int) $params['collectionid']);

        // Re-derive visibility server-side rather than trusting that the
        // caller could only have got the id from a page they were allowed to
        // see. Starring is not a read, but it should not be a way to confirm
        // that a draft, taken-down or deleted collection exists either.
        //
        // An unlisted collection IS starrable by anyone who has its link,
        // which is what user_can_view_collection() answers: unlisted means
        // "not advertised", not "secret", and somebody given the link should
        // be able to bookmark it. A tombstone answers false there, so a
        // deleted collection cannot be starred.
        if (!$collection || !collection_manager::user_can_view_collection($collection, (int) $USER->id)) {
            throw new \moodle_exception('error_collectionnotfound', 'local_oerexchange');
        }

        // Adding a star only where it can be listed, matching the page's own
        // control (collection_manager::is_publicly_listable()); removing one
        // is always allowed. Answered as the unchanged state, not an error:
        // the collection is visible to this user, so there is nothing to hide.
        if (
            (bool) $params['starred'] && !collection_manager::is_publicly_listable($collection)
                && !star_manager::is_starred((int) $collection->id, (int) $USER->id, star_manager::ITEMTYPE_COLLECTION)
        ) {
            return [
                'starred' => false,
                'count' => star_manager::star_count((int) $collection->id, star_manager::ITEMTYPE_COLLECTION),
            ];
        }

        $now = star_manager::set_starred(
            (int) $collection->id,
            (int) $USER->id,
            (bool) $params['starred'],
            star_manager::ITEMTYPE_COLLECTION
        );

        return [
            'starred' => $now,
            'count' => star_manager::star_count((int) $collection->id, star_manager::ITEMTYPE_COLLECTION),
        ];
    }

    /**
     * Describes what this function returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'starred' => new external_value(PARAM_BOOL, 'Whether the caller now stars this collection'),
            'count' => new external_value(PARAM_INT, 'How many people star it'),
        ]);
    }
}
