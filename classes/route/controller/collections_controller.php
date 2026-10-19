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
use local_oerexchange\local\collection_list;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Public listing of every published collection on this Exchange.
 *
 * The #[route(path: '/collections')] attribute below is relative to this
 * plugin's component path, NOT the real, resolvable URL: Moodle's router only
 * strips the component prefix for `core` components, so the working request
 * path is /local_oerexchange/collections and a bare /collections 404s. See
 * profile_controller's class docblock (lines 32-43) for the full derivation.
 *
 * Viewing is intentionally public, matching the catalogue (index.php), the
 * contributor listing and the profile page. Only public, active, not
 * taken-down collections appear — collection_manager enforces that in SQL
 * (public_where()), not here, so a draft or an unlisted collection cannot
 * reach this page even if the listing code is wrong.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class collections_controller {
    use \core\router\route_controller;

    /**
     * Render the full collection listing.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    #[route(
        path: '/collections',
        method: 'GET',
        requirelogin: new require_login(requirelogin: false),
    )]
    public function view(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        global $OUTPUT, $PAGE;

        // The admin switch that turns the whole feature off hides this page.
        // Tested against an explicit value rather than truthiness alone: the
        // setting does not exist until the plugin's settings page has been
        // saved once, and get_config() answers false for "never set", which
        // must not read as "switched off".
        $enabled = get_config('local_oerexchange', 'collectionsenabled');
        if ($enabled !== false && !$enabled) {
            return $this->page_not_found($request, $response);
        }

        $sort = collection_list::normalise_sort(
            optional_param(collection_list::PARAM_SORT, collection_list::SORT_RECENT, PARAM_ALPHA)
        );
        $layout = collection_list::normalise_layout(
            optional_param(collection_list::PARAM_LAYOUT, collection_list::LAYOUT_CARDS, PARAM_ALPHA)
        );
        $page = max(0, optional_param('page', 0, PARAM_INT));

        // Always routed_path(), never a plain moodle_url() — see the long
        // comment in profile_controller::view(). On this dev VM the link may
        // render with an '/r.php/' prefix because routerconfigured reads false
        // during a routed request; that is a documented environment bug,
        // cosmetic, and must not be "simplified" away.
        $pageurl = \moodle_url::routed_path('/local_oerexchange/collections');
        $pageurl->params([
            collection_list::PARAM_SORT => $sort,
            collection_list::PARAM_LAYOUT => $layout,
        ]);

        $PAGE->set_url($pageurl);
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_pagelayout('standard');
        $PAGE->set_title(get_string('collections', 'local_oerexchange'));
        $PAGE->set_heading(get_string('collectionsheading', 'local_oerexchange'));

        $total = collection_list::count_collections();
        $cards = collection_list::get_cards(
            $sort,
            collection_list::PERPAGE,
            $page * collection_list::PERPAGE
        );

        $regionid = 'oerexchange-collections-page';

        $PAGE->requires->js_call_amd('local_oerexchange/collectionsort', 'init', [
            $regionid,
            collection_list::PERPAGE,
            $layout,
        ]);

        $out = $OUTPUT->header();
        $out .= \html_writer::tag(
            'p',
            get_string('collectionsbrowseintro', 'local_oerexchange'),
            ['class' => 'text-muted']
        );
        $out .= collection_list::render_sort_form($pageurl, $sort, $regionid, $layout);
        // The total stays outside the swapped region: it does not change when
        // the sort does, and a count that re-rendered with the cards would
        // have to be recomputed on every AJAX call to say the same thing.
        $out .= \html_writer::div(
            get_string('collectionsresultcount', 'local_oerexchange', $total),
            'small text-muted mb-2'
        );
        $out .= \html_writer::div(
            collection_list::render($cards, $layout),
            '',
            ['id' => $regionid, 'data-region' => 'oerexchange-collections']
        );
        $out .= $OUTPUT->paging_bar($total, $page, collection_list::PERPAGE, $pageurl);
        $out .= $OUTPUT->footer();

        $response->getBody()->write($out);

        return $response;
    }
}
