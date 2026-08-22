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
use local_oerexchange\local\contributor_list;

/**
 * local_oerexchange_get_contributors external function — re-render the
 * contributor listing for a different sort, without a page reload.
 *
 * AJAX-only and deliberately NOT in the token-facing "OER Exchange service":
 * this answers a visitor's own browser on this site, never a registered client
 * site. Unlike the other AJAX functions here it is loginrequired => false,
 * because both surfaces that call it (the /contributors page and the block on
 * the site front page) are public, and it returns only what those pages
 * already show anonymously.
 *
 * It returns rendered HTML rather than card data because contributor_list is
 * the single renderer for both layouts; returning data would mean a second
 * renderer in JavaScript to keep in step with it.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_contributors extends external_api {
    /**
     * Describes the parameters this function accepts.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'sort' => new external_value(PARAM_ALPHA, 'Sort key; unknown values fall back to the default'),
            'limit' => new external_value(PARAM_INT, 'Maximum cards to return'),
            'offset' => new external_value(PARAM_INT, 'How many cards to skip'),
            'layout' => new external_value(PARAM_ALPHA, 'list for a block region, grid for a full page'),
        ]);
    }

    /**
     * Re-render the listing.
     *
     * @param string $sort
     * @param int $limit
     * @param int $offset
     * @param string $layout
     * @return array
     */
    public static function execute(string $sort, int $limit, int $offset, string $layout): array {
        global $PAGE;

        $params = self::validate_parameters(self::execute_parameters(), [
            'sort' => $sort,
            'limit' => $limit,
            'offset' => $offset,
            'layout' => $layout,
        ]);

        // NOT self::validate_context(): that ends in require_login()
        // (lib/external/classes/external_api.php), which would refuse the
        // anonymous callers this function exists to serve — the same
        // behaviour set_resource_star's docblock relies on to refuse them.
        // Setting the page context directly is what a loginrequired => false
        // function needs, and $OUTPUT needs it anyway to render user pictures.
        $PAGE->set_context(\context_system::instance());

        // Clamp rather than trust: an unbounded limit from the browser would
        // let anyone pull every contributor in one request.
        $limit = max(1, min((int) $params['limit'], contributor_list::PERPAGE));
        $offset = max(0, (int) $params['offset']);

        $cards = contributor_list::get_cards(
            contributor_list::normalise_sort($params['sort']),
            $limit,
            $offset
        );

        $html = $params['layout'] === 'grid'
            ? contributor_list::render_grid($cards)
            : contributor_list::render_list($cards);

        return ['html' => $html];
    }

    /**
     * Describes what this function returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'html' => new external_value(PARAM_RAW, 'Rendered listing markup for the requested sort'),
        ]);
    }
}
