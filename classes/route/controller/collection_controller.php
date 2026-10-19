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

use core\exception\access_denied_exception;
use core\router\require_login;
use core\router\route;
use local_oerexchange\local\collection_manager;
use local_oerexchange\local\collection_view;
use local_oerexchange\local\star_manager;
use local_oerexchange\router\parameters\path_collectionslug;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * One collection's page, GET+POST /c/{slug}.
 *
 * Viewing is intentionally public (no requirelogin on the route) — the same
 * pattern as profile_controller and resource.php, which is how this plugin's
 * catalogue is browsable without an account. Every state change is a POST
 * guarded by require_sesskey() and by a fresh authorisation question asked of
 * collection_manager, and the POST branch calls require_login() itself.
 *
 * THE URL RULE. The #[route(path: '/c/{slug}')] attribute below is relative to
 * this plugin's component path, NOT the real, resolvable URL. Moodle's router
 * only strips the component prefix for `core` components
 * (core\router\util::normalise_component_path()); for a `local_oerexchange`
 * route it is left in place, so the real, working request path is
 * /local_oerexchange/c/{slug} (lib/classes/router/abstract_route_loader.php:112
 * compiles every standard route's pattern as "/{$componentpath}{$path}"). A
 * bare /c/{slug} 404s. Everything this controller generates for outward use —
 * $PAGE->url, every form action, the share link, the post-action redirect —
 * must therefore be built with \moodle_url::routed_path('/local_oerexchange/c/'
 * . $slug) and never with a plain new moodle_url(). routed_path()
 * (lib/classes/url.php) prepends '/r.php/' whenever $CFG->routerconfigured is
 * not confirmed true and leaves the clean form otherwise, so it always produces
 * something that resolves; a plain moodle_url() only happens to work on this
 * dev VM because of a site-specific nginx catch-all. See
 * profile_controller::view()'s longer version of this note, including the
 * documented VM bug that can make the '/r.php/' prefix appear here. Do not
 * "simplify" it away.
 *
 * ORDERING. is_tombstone() is asked FIRST, before the access check, because
 * collection_manager::user_can_view_collection() answers false for a tombstone
 * by design while the tombstone page is shown to everybody. resource.php's
 * 'deleted' branch has the same ordering for the same reason.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class collection_controller {
    use \core\router\route_controller;

    /** @var string[] the POST actions this controller dispatches */
    protected const ACTIONS = [
        'additem',
        'removeitem',
        'moveitem',
        'setnote',
        'report',
        'star',
        'modhide',
        'modrestore',
    ];

    /** @var string[] the report reasons the form offers, re-validated server-side */
    protected const REPORT_TYPES = ['copyright', 'quality', 'spam', 'other'];

    /**
     * Render a collection, or perform one of its POST actions and redirect
     * back to it.
     *
     * A slug that does not resolve, a draft belonging to somebody else and a
     * collection a moderator has taken down are all the same 404: the visibility
     * matrix deliberately leaks no distinction between "no such collection" and
     * "one you may not see".
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param string $slug
     * @return ResponseInterface
     */
    #[route(
        path: '/c/{slug}',
        method: ['GET', 'POST'],
        pathtypes: [
            new path_collectionslug(),
        ],
        requirelogin: new require_login(requirelogin: false),
    )]
    public function view(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $slug,
    ): ResponseInterface {
        global $OUTPUT, $PAGE, $USER;

        // An admin who has turned collections off has turned the pages off
        // too (settings_collectionsenabled_desc says so in as many words), and
        // a 404 is the honest answer: the URL does not serve anything on this
        // site. Asked before the row is even loaded, so no state leaks through
        // a disabled feature.
        if (!collection_view::enabled()) {
            return $this->page_not_found($request, $response);
        }

        $collection = collection_manager::get_by_slug($slug);
        if (!$collection) {
            return $this->page_not_found($request, $response);
        }

        $pageurl = self::collection_url($slug);

        $PAGE->set_url($pageurl);
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_pagelayout('standard');
        $PAGE->set_title($collection->title);
        $PAGE->set_heading($collection->title);

        // Before the access check — see the class docblock.
        if (collection_manager::is_tombstone($collection)) {
            return self::write($response, $OUTPUT->header() . collection_view::tombstone() . $OUTPUT->footer());
        }

        $userid = isloggedin() && !isguestuser() ? (int) $USER->id : 0;
        if (!collection_manager::user_can_view_collection($collection, $userid)) {
            return $this->page_not_found($request, $response);
        }

        if ($request->getMethod() === 'POST') {
            $redirect = $this->handle_action($response, $collection, $slug);
            if ($redirect !== null) {
                return $redirect;
            }
            // Two ways to land here. A stale or missing sesskey, which has
            // already queued the error notification $OUTPUT->header() below
            // prints — re-rendering the page the user was looking at beats the
            // raw 500 an uncaught require_sesskey() would become on Slim's
            // generic error page (the defect profile_edit_controller::save()'s
            // docblock records fixing in the same situation). Or a POST with
            // no recognised action at all, which is simply rendered as the
            // page, with nothing queued and nothing done.
            //
            // Either way the row is re-read: nothing below may draw a state
            // the request has already moved past.
            $collection = collection_manager::get_by_slug($slug) ?: $collection;
        }

        // Unlisted and draft collections are asked not to be indexed. This is
        // an X-Robots-Tag response header rather than a <head> meta: by the
        // time a routed controller runs, $OUTPUT->header() renders and closes
        // <head> inside this method, and the only legitimate <head>-injection
        // surface is \core\hook\output\before_standard_head_html_generation
        // (this plugin listens on it in hook_callbacks for its og:* tags),
        // which is not this file. X-Robots-Tag is honoured by Google, Bing and
        // Yandex for exactly this purpose and needs no <head> access.
        if ((string) $collection->visibility !== collection_manager::VISIBILITY_PUBLIC) {
            $response = $response->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        $out = $OUTPUT->header();
        $out .= collection_view::render($collection, $pageurl, $userid);
        $out .= $OUTPUT->footer();

        return self::write($response, $out);
    }

    /**
     * Dispatch one POST action.
     *
     * Returns the redirect response on success, or null to tell view() to
     * re-render the page — which is what a stale sesskey or an unrecognised
     * action name gets, with a notification already queued, instead of a raw
     * 500 from Slim's generic error page.
     *
     * Every branch re-derives its own authorisation from the database through
     * collection_manager, using the collection the SLUG resolved to. No posted
     * collection id exists to be trusted, and the two actions that do take a
     * posted id (an item id, a resource id) load that row keyed by the
     * slug-resolved collection as well, so a posted id can never reach a
     * collection the caller was not authorised against.
     *
     * @param ResponseInterface $response
     * @param \stdClass $collection the collection the slug resolved to
     * @param string $slug
     * @return ResponseInterface|null
     */
    protected function handle_action(
        ResponseInterface $response,
        \stdClass $collection,
        string $slug,
    ): ?ResponseInterface {
        global $USER;

        // Read with optional_param(), NOT $request->getParsedBody(). This
        // route declares no `requestbody:` schema (form-urlencoded HTML posts
        // do not fit that JSON-oriented API), and
        // core\router\request_validator::validate_request_body()
        // (lib/classes/router/request_validator.php:165-171) unconditionally
        // replaces the request with withParsedBody([]) whenever there is no
        // body schema — so getParsedBody() is guaranteed empty here whatever
        // was posted, while the real $_POST superglobal is intact.
        // profile_edit_controller::save()'s docblock records the empirical
        // verification of that, and every other POST handler in this plugin
        // reads optional_param() too.
        $action = optional_param('action', '', PARAM_ALPHA);
        if (!in_array($action, self::ACTIONS, true)) {
            return null;
        }

        // Viewing is public; acting never is. The route cannot say so — it is
        // one route serving both methods — so the POST branch says it here.
        require_login();

        // The guest account counts as isloggedin(), so require_login() alone
        // would let a shared anonymous session star, report and (on an open
        // collection) contribute under one name. Every action below writes
        // something attributable to a person, so the guest is refused outright
        // — the same explicit guard collection_manager::user_can_add_items()
        // makes for the one action it can see.
        if (isguestuser()) {
            throw new access_denied_exception('guestsarenotallowed');
        }

        // AFTER the action name is read, and deliberately: reading POST values
        // has no side effects, so a stale token can re-render the page the user
        // was looking at rather than throwing. require_sesskey() rather than
        // confirm_sesskey() so a missing token and a wrong one both land here.
        try {
            require_sesskey();
        } catch (\moodle_exception $e) {
            \core\notification::error(get_string('error_sesskeyexpired', 'local_oerexchange'));

            return null;
        }

        $userid = (int) $USER->id;

        switch ($action) {
            case 'additem':
                $this->add_item($collection, $userid);
                break;
            case 'removeitem':
                $this->remove_item($collection, $userid);
                break;
            case 'moveitem':
                $this->move_item($collection, $userid);
                break;
            case 'setnote':
                $this->set_note($collection, $userid);
                break;
            case 'report':
                $this->report($collection, $userid);
                break;
            case 'star':
                $this->star($collection, $userid);
                break;
            case 'modhide':
            case 'modrestore':
                $this->moderate($collection, $userid, $action === 'modhide');
                break;
            default:
                // Unreachable: $action was matched against self::ACTIONS
                // above. Named anyway so adding a constant without adding a
                // branch fails loudly here rather than silently doing a
                // takedown, which is what a bare `default:` would have done.
                throw new \coding_exception('Unhandled collection action: ' . $action);
        }

        return static::redirect($response, self::collection_url($slug));
    }

    /**
     * Add a resource to this collection.
     *
     * add_item() validates STATE for us (the collection is live, the resource
     * is one the public can see) and raises error_collectionitemexists,
     * error_collectionitemunavailable or error_collectiondeleted. Those are
     * ordinary mistakes — a double-tapped button, a resource hidden a moment
     * ago — so they become a notification on the collection page rather than a
     * full-page error the user has to navigate away from, exactly as
     * resource.php does for a mistyped co-author.
     *
     * @param \stdClass $collection
     * @param int $userid
     * @throws access_denied_exception when $userid may not add to this collection
     */
    protected function add_item(\stdClass $collection, int $userid): void {
        if (!collection_manager::user_can_add_items($collection, $userid)) {
            throw new access_denied_exception('error_collectioncannotadd', 'local_oerexchange');
        }

        $resourceid = required_param('resourceid', PARAM_INT);

        try {
            // No note: writing one is a curation right (user_can_curate), and
            // an open contributor who may add has deliberately not got it.
            collection_manager::add_item((int) $collection->id, $resourceid, $userid);
            \core\notification::success(get_string(
                'addtocollectionadded',
                'local_oerexchange',
                self::plain_title($collection)
            ));
        } catch (\moodle_exception $e) {
            \core\notification::error($e->getMessage());
        }
    }

    /**
     * Remove one resource from this collection.
     *
     * The item is loaded by (this collection, posted resource id) — never by
     * the posted id alone — and then handed whole to user_can_remove_item(),
     * which is the rule that lets an open contributor undo their own addition
     * and nobody else's.
     *
     * @param \stdClass $collection
     * @param int $userid
     * @throws access_denied_exception when $userid may not remove this item
     */
    protected function remove_item(\stdClass $collection, int $userid): void {
        global $DB;

        $resourceid = required_param('resourceid', PARAM_INT);
        $item = $DB->get_record(collection_manager::ITEM_TABLE, [
            'collectionid' => $collection->id,
            'resourceid' => $resourceid,
        ]);
        if (!$item) {
            // Already gone — a double-click, or two curators removing the same
            // row at once. Nothing is wrong, so this is not an error.
            \core\notification::warning(get_string('collectionitemremoved', 'local_oerexchange'));

            return;
        }

        if (!collection_manager::user_can_remove_item($collection, $item, $userid)) {
            throw new access_denied_exception('error_collectioncannotremoveitem', 'local_oerexchange');
        }

        collection_manager::remove_item((int) $collection->id, $resourceid);
        \core\notification::success(get_string('collectionitemremoved', 'local_oerexchange'));
    }

    /**
     * Move one item up or down.
     *
     * @param \stdClass $collection
     * @param int $userid
     * @throws access_denied_exception when $userid may not curate this collection
     */
    protected function move_item(\stdClass $collection, int $userid): void {
        global $DB;

        if (!collection_manager::user_can_curate($collection, $userid)) {
            throw new access_denied_exception('error_collectioncannotcurate', 'local_oerexchange');
        }

        $itemid = required_param('itemid', PARAM_INT);
        $direction = required_param('direction', PARAM_INT);
        if ($direction === 0) {
            // A zero direction makes move_item() throw a coding_exception, which
            // is right for a programming error but not for a crafted post.
            return;
        }

        // Keyed by this collection as well as by the posted id: an item id
        // from somebody else's collection must not be reorderable from here.
        if (
            !$DB->record_exists(collection_manager::ITEM_TABLE, [
            'id' => $itemid,
            'collectionid' => $collection->id,
            ])
        ) {
            return;
        }

        collection_manager::move_item($itemid, $direction);
        \core\notification::success(get_string('collectionitemreordered', 'local_oerexchange'));
    }

    /**
     * Set or clear one item's curator note.
     *
     * PARAM_TEXT, not PARAM_RAW: the note is rendered back into a world-
     * readable page. PARAM_TEXT strips every tag except the ones the multilang
     * filter needs (lib/classes/param.php, clean_param_value_text()), which is
     * why collection_view renders it through format_text() rather than s() —
     * s() would turn a multilang span the curator deliberately typed into
     * literal markup. Same pair of decisions, same reasons, as resource.php's
     * trydisabledreason.
     *
     * @param \stdClass $collection
     * @param int $userid
     * @throws access_denied_exception when $userid may not curate this collection
     */
    protected function set_note(\stdClass $collection, int $userid): void {
        global $DB;

        if (!collection_manager::user_can_curate($collection, $userid)) {
            throw new access_denied_exception('error_collectioncannotcurate', 'local_oerexchange');
        }

        $itemid = required_param('itemid', PARAM_INT);
        $note = optional_param('note', '', PARAM_TEXT);

        if (
            !$DB->record_exists(collection_manager::ITEM_TABLE, [
            'id' => $itemid,
            'collectionid' => $collection->id,
            ])
        ) {
            return;
        }

        collection_manager::set_item_note($itemid, $note);
        \core\notification::success(get_string(
            trim($note) === '' ? 'collectionitemnoteremoved' : 'collectionitemnotesaved',
            'local_oerexchange'
        ));
    }

    /**
     * File a moderation report about this collection.
     *
     * One row in the same local_oerexchange_reports table resource reports use,
     * with collectionid set and resourceid left null — the schema's comment
     * records that exactly one of the two is ever set, because which one it is
     * decides which takedown a moderator can apply.
     *
     * @param \stdClass $collection
     * @param int $userid
     * @throws \moodle_exception error_invalidreporttype
     */
    protected function report(\stdClass $collection, int $userid): void {
        global $DB;

        $type = required_param('reporttype', PARAM_ALPHA);
        // Re-validated against the same set the form offers: PARAM_ALPHA alone
        // accepts any alphabetic string, and an unrecognised value would later
        // break moderate.php, which feeds it to
        // get_string('reporttype_' . $type) with no fallback — denying admins
        // the whole moderation queue over one bad row. resource.php's report
        // branch guards the identical way.
        if (!in_array($type, self::REPORT_TYPES, true)) {
            throw new \moodle_exception('error_invalidreporttype', 'local_oerexchange');
        }
        $details = required_param('reportdetails', PARAM_TEXT);

        $DB->insert_record('local_oerexchange_reports', (object) [
            'resourceid' => null,
            'collectionid' => (int) $collection->id,
            'userid' => $userid,
            'type' => $type,
            'details' => $details,
            'status' => 'open',
            'resolvernote' => null,
            'timecreated' => time(),
            'timeresolved' => null,
        ]);

        \core\notification::success(get_string('collectionreported', 'local_oerexchange'));
    }

    /**
     * Toggle this viewer's star on the collection.
     *
     * The wanted state is derived from the stored one rather than posted, so a
     * replayed or double-submitted form cannot flip a star the user has since
     * changed on another tab. Starring is a reader's act, not a curator's: any
     * logged-in non-guest who may SEE the collection may star it, and view()
     * has already established that.
     *
     * @param \stdClass $collection
     * @param int $userid
     */
    protected function star(\stdClass $collection, int $userid): void {
        $wanted = !star_manager::is_starred(
            (int) $collection->id,
            $userid,
            star_manager::ITEMTYPE_COLLECTION
        );
        // Removing a star is always allowed; adding one only where it can be
        // listed (collection_manager::is_publicly_listable()), matching the
        // control view() offers.
        if ($wanted && !collection_manager::is_publicly_listable($collection)) {
            return;
        }
        star_manager::set_starred(
            (int) $collection->id,
            $userid,
            $wanted,
            star_manager::ITEMTYPE_COLLECTION
        );
    }

    /**
     * A moderator taking the collection down, or putting it back.
     *
     * Needs the capability itself, not user_can_manage(): a takedown is a
     * different act from an owner's unpublish, and set_modhidden() writes a
     * column the owner's own visibility switch cannot lift. Same split, same
     * reason, as resource.php's modhide/modrestore branch.
     *
     * @param \stdClass $collection
     * @param int $userid
     * @param bool $hide true to take down, false to restore
     */
    protected function moderate(\stdClass $collection, int $userid, bool $hide): void {
        require_capability('local/oerexchange:moderate', \context_system::instance());

        collection_manager::set_modhidden((int) $collection->id, $hide, $userid);
        \core\notification::success(get_string(
            $hide ? 'collectionmodhidedone' : 'collectionmodrestoredone',
            'local_oerexchange'
        ));
    }

    /**
     * The real, resolvable URL of one collection.
     *
     * The single place this plugin's routed collection URL is spelled, so the
     * routed_path() rule in the class docblock cannot be forgotten on one of
     * the half-dozen places that need it.
     *
     * @param string $slug
     * @return \moodle_url
     */
    public static function collection_url(string $slug): \moodle_url {
        return \moodle_url::routed_path('/local_oerexchange/c/' . $slug);
    }

    /**
     * A collection's title as plain text, for a notification or a message.
     *
     * Filtered first so a multilang title collapses to one language rather
     * than appearing as literal markup, then flattened back to text so
     * format_string()'s '&amp;' is not read out as characters. Same ordering,
     * same reason, as collection_curator_manager::notify_added().
     *
     * @param \stdClass $collection
     * @return string
     */
    protected static function plain_title(\stdClass $collection): string {
        return content_to_text(
            format_string($collection->title, true, ['context' => \context_system::instance()]),
            FORMAT_HTML
        );
    }

    /**
     * Write a body into the PSR-7 response.
     *
     * @param ResponseInterface $response
     * @param string $body
     * @return ResponseInterface
     */
    protected static function write(ResponseInterface $response, string $body): ResponseInterface {
        $response->getBody()->write($body);

        return $response;
    }
}
