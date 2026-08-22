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

namespace local_oerexchange\route\controller;

use core\router\require_login;
use core\router\route;
use local_oerexchange\local\contributor_list;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Public listing of every contributor with something in the catalogue.
 *
 * The #[route(path: '/contributors')] attribute below is relative to this
 * plugin's component path, NOT the real, resolvable URL: Moodle's router only
 * strips the component prefix for `core` components, so the working request
 * path is /local_oerexchange/contributors and a bare /contributors 404s. See
 * profile_controller's class docblock for the full derivation.
 *
 * Viewing is intentionally public, matching the catalogue (index.php) and the
 * profile page. Only contributors who have left their profile visible appear —
 * contributor_list enforces that in SQL, not here.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class contributors_controller {
    use \core\router\route_controller;

    /**
     * Render the full contributor listing.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    #[route(
        path: '/contributors',
        method: 'GET',
        requirelogin: new require_login(requirelogin: false),
    )]
    public function view(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        global $OUTPUT, $PAGE;

        $sort = contributor_list::normalise_sort(
            optional_param(contributor_list::PARAM_SORT, contributor_list::SORT_RESOURCES, PARAM_ALPHA)
        );
        $page = max(0, optional_param('page', 0, PARAM_INT));

        // Always routed_path(), never a plain moodle_url() — see the long
        // comment in profile_controller::view(). On this dev VM the link may
        // render with an '/r.php/' prefix because routerconfigured reads false
        // during a routed request; that is a documented environment bug,
        // cosmetic, and must not be "simplified" away.
        $pageurl = \moodle_url::routed_path('/local_oerexchange/contributors');
        $pageurl->params([contributor_list::PARAM_SORT => $sort]);

        $PAGE->set_url($pageurl);
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_pagelayout('standard');
        $PAGE->set_title(get_string('contributors', 'local_oerexchange'));
        $PAGE->set_heading(get_string('contributors', 'local_oerexchange'));

        $total = contributor_list::count_contributors();
        $cards = contributor_list::get_cards(
            $sort,
            contributor_list::PERPAGE,
            $page * contributor_list::PERPAGE
        );

        $regionid = 'oerexchange-contributors-page';

        $PAGE->requires->js_call_amd('local_oerexchange/contributorsort', 'init', [
            $regionid,
            contributor_list::PERPAGE,
            'grid',
        ]);

        $out = $OUTPUT->header();
        $out .= contributor_list::render_sort_form($pageurl, $sort, $regionid);
        $out .= \html_writer::div(
            contributor_list::render_grid($cards),
            '',
            ['id' => $regionid, 'data-region' => 'oerexchange-contributors']
        );
        $out .= $OUTPUT->paging_bar($total, $page, contributor_list::PERPAGE, $pageurl);
        $out .= $OUTPUT->footer();

        $response->getBody()->write($out);

        return $response;
    }
}
