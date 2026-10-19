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

namespace local_oerexchange\local;

/**
 * The collection listing: which public collections to show, and how a card
 * for one looks.
 *
 * Owns both the enrichment and the rendering, so the /collections page, the
 * AJAX re-render behind its sort control, and any block or landing-page row
 * that shows collections cannot drift apart — the arrangement
 * contributor_list already uses for /contributors and the dashboard block,
 * and catalogue_view for index.php and the public-landing hook.
 *
 * Listability is NOT decided here. Which collections a stranger may be shown
 * is collection_manager's rule (public_where(): public, active, not taken
 * down) and this class never widens it: every row it draws comes from
 * collection_manager::public_collections() or from another query built on the
 * same clause. Drafts and unlisted collections therefore cannot appear in a
 * listing even by accident.
 *
 * One query per page, not one per card: build_cards() batches covers, visible
 * item counts, star counts, owner user records and owner profiles, which is
 * why callers hand it a whole page of rows rather than calling it per row.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class collection_list {
    /** @var int collections per page on the full listing page */
    public const PERPAGE = 12;

    /**
     * Recently published first.
     *
     * The sorts are aliased from collection_manager rather than re-spelled:
     * a second spelling here is how a page ends up asking for a sort the
     * query layer throws a coding_exception for.
     *
     * @var string
     */
    public const SORT_RECENT = collection_manager::SORT_RECENT;

    /** @var string sort by how many people have starred the collection */
    public const SORT_STARRED = collection_manager::SORT_STARRED;

    /** @var string sort by how many visible resources the collection holds */
    public const SORT_ITEMS = collection_manager::SORT_ITEMS;

    /** @var string GET parameter carrying the chosen sort, on both the block and the page */
    public const PARAM_SORT = 'collsort';

    /** @var string GET parameter carrying the chosen view, on both the block and the page */
    public const PARAM_LAYOUT = 'collview';

    /** @var string picture-led cards in a responsive grid */
    public const LAYOUT_CARDS = 'cards';

    /** @var string compact one-per-row list, for narrow regions */
    public const LAYOUT_LIST = 'list';

    /** @var int how much of a collection's description a card shows */
    protected const TEASER_LENGTH = 140;

    /**
     * Every accepted sort key, in menu order.
     *
     * @return string[]
     */
    public static function sort_keys(): array {
        return [self::SORT_RECENT, self::SORT_STARRED, self::SORT_ITEMS];
    }

    /**
     * Map any caller-supplied sort to a known key. The result picks a
     * hardcoded ORDER BY clause inside collection_manager — a caller's string
     * is never interpolated into SQL.
     *
     * @param string $sort
     * @return string one of the SORT_* constants
     */
    public static function normalise_sort(string $sort): string {
        return in_array($sort, self::sort_keys(), true) ? $sort : self::SORT_RECENT;
    }

    /**
     * Every accepted view, in menu order.
     *
     * @return string[]
     */
    public static function layout_keys(): array {
        return [self::LAYOUT_CARDS, self::LAYOUT_LIST];
    }

    /**
     * Map any caller-supplied view to a known one.
     *
     * @param string $layout
     * @return string one of the LAYOUT_* constants
     */
    public static function normalise_layout(string $layout): string {
        return in_array($layout, self::layout_keys(), true) ? $layout : self::LAYOUT_CARDS;
    }

    /**
     * How many public collections there are, for the paging bar.
     *
     * @return int
     */
    public static function count_collections(): int {
        return collection_manager::count_public_collections();
    }

    /**
     * A page of public collections, enriched with everything a card needs.
     *
     * @param string $sort one of the SORT_* constants
     * @param int $limit 0 for no limit
     * @param int $offset
     * @return \stdClass[] rows from build_cards(), in the requested order
     */
    public static function get_cards(string $sort, int $limit = 0, int $offset = 0): array {
        return self::build_cards(
            collection_manager::public_collections(self::normalise_sort($sort), $limit, $offset)
        );
    }

    /**
     * Turn collection rows into card rows, batching every lookup a card needs.
     *
     * Takes rows rather than fetching them so that the surfaces which have
     * their own query — a featured row on the landing page, a block showing
     * somebody's starred collections — render through exactly this renderer
     * instead of growing a second one.
     *
     * Covers come from collection_cover::display_urls(), which deliberately
     * builds nothing: a collection whose mosaic has not been generated yet
     * draws the default thumbnail here and gets its mosaic the first time
     * somebody opens it. Generating a page of images inside one request is
     * not a thing to do on a page view.
     *
     * Item and star counts are read from the row when the query already
     * carried them (public_collections() does) and are otherwise fetched for
     * the whole page in one batch each — never per card.
     *
     * @param \stdClass[] $collections collections rows, in the order to draw them
     * @return \stdClass[] id, title, url, teaser, ownername, ownerurl, coverurl,
     *     itemcount, starcount, timepublished
     */
    public static function build_cards(array $collections): array {
        global $DB;

        if (!$collections) {
            return [];
        }

        $ids = [];
        $userids = [];
        foreach ($collections as $collection) {
            $ids[] = (int) $collection->id;
            $userids[] = (int) $collection->userid;
        }

        $covers = collection_cover::display_urls($ids);

        // One batch each for anything the rows did not bring with them.
        // Testing the first row is enough: a page of rows always comes from
        // one query, so either all of them carry the counts or none do.
        $first = reset($collections);
        $counts = isset($first->itemcount) ? [] : collection_manager::counts_for($ids);
        $starcounts = isset($first->starcount)
            ? []
            : star_manager::star_counts_for($ids, star_manager::ITEMTYPE_COLLECTION);

        $users = $DB->get_records_list('user', 'id', array_unique($userids));
        $profiles = profile_manager::get_by_userids($userids);

        $cards = [];
        foreach ($collections as $collection) {
            $id = (int) $collection->id;
            $ownerid = (int) $collection->userid;

            // Only a visible profile gets a link: the owner's name is public
            // on a public collection either way, but their profile page is
            // theirs to publish or not, and an invisible one 404s.
            $ownerurl = null;
            if (isset($profiles[$ownerid]) && !empty($profiles[$ownerid]->visible)) {
                // Always routed_path(), never a plain moodle_url() — see the
                // long comment in profile_controller::view() for why.
                $ownerurl = \moodle_url::routed_path('/local_oerexchange/u/' . $profiles[$ownerid]->slug);
            }

            $cards[] = (object) [
                'id' => $id,
                'title' => (string) $collection->title,
                // A collection page lives at /c/{slug} (slug_helper's
                // docblock); routed_path() for the same reason as above.
                'url' => \moodle_url::routed_path('/local_oerexchange/c/' . $collection->slug),
                'teaser' => self::teaser($collection),
                // A collection outlives a deleted account; drop the byline
                // rather than print a blank name.
                'ownername' => isset($users[$ownerid]) ? fullname($users[$ownerid]) : '',
                'ownerurl' => $ownerurl,
                'coverurl' => $covers[$id] ?? null,
                'itemcount' => (int) ($collection->itemcount ?? $counts[$id] ?? 0),
                'starcount' => (int) ($collection->starcount ?? $starcounts[$id] ?? 0),
                'timepublished' => (int) ($collection->timepublished ?? 0),
            ];
        }

        return $cards;
    }

    /**
     * The plain-text opening of a collection's description.
     *
     * Filter first (multilang collapses to one language), then strip tags and
     * decode entities with content_to_text(), then shorten, and let the sink
     * escape exactly once. That order is load-bearing: strip_tags() + s() on
     * already-encoded entities double-escapes them, which is the bug
     * catalogue_view's identical comment records.
     *
     * @param \stdClass $collection a collections row
     * @return string plain text, not yet escaped
     */
    protected static function teaser(\stdClass $collection): string {
        $filtered = format_text(
            (string) ($collection->summary ?? ''),
            (int) ($collection->summaryformat ?? FORMAT_HTML),
            ['context' => \core\context\system::instance()]
        );

        return shorten_text(content_to_text($filtered, FORMAT_HTML), self::TEASER_LENGTH);
    }

    /**
     * Render cards in the requested view. The single entry point callers
     * should use, so neither surface has to know which renderer is which.
     *
     * @param \stdClass[] $cards from build_cards()
     * @param string $layout one of the LAYOUT_* constants
     * @return string HTML
     */
    public static function render(array $cards, string $layout): string {
        return self::normalise_layout($layout) === self::LAYOUT_LIST
            ? self::render_list($cards)
            : self::render_grid($cards);
    }

    /**
     * The title, as a link, escaped by format_string().
     *
     * @param \stdClass $card
     * @param string $tag the element to wrap it in
     * @param string $class
     * @return string HTML
     */
    protected static function render_title(\stdClass $card, string $tag, string $class): string {
        $title = format_string($card->title, true, ['context' => \core\context\system::instance()]);

        return \html_writer::tag($tag, \html_writer::link($card->url, $title), ['class' => $class]);
    }

    /**
     * The byline and the two counts, shared by both views so they cannot
     * drift apart.
     *
     * Deliberately NOT a .stretched-link card: the byline is its own link to
     * the owner's profile, and a stretched link over the whole card would
     * cover it (and would make a screen reader announce the counts as part of
     * the collection's link text). The cover and the title are the links,
     * exactly as catalogue_view's resource cards do it.
     *
     * @param \stdClass $card
     * @return string HTML
     */
    protected static function render_meta(\stdClass $card): string {
        $out = '';

        if ($card->ownername !== '') {
            $name = format_string($card->ownername, true, ['context' => \core\context\system::instance()]);
            $byline = $card->ownerurl ? \html_writer::link($card->ownerurl, $name) : $name;
            // The placeholder is already-escaped markup, and get_string() does
            // no escaping of its own — which is what makes the link survive.
            $out .= \html_writer::div(
                get_string('collectionby', 'local_oerexchange', $byline),
                'small text-muted'
            );
        }

        // Separate singular string: "1 resources" is wrong in English, and
        // Moodle's get_string() has no plural-form selection to lean on.
        $meta = $card->itemcount === 1
            ? get_string('collectionitemcount_one', 'local_oerexchange')
            : get_string('collectionitemcount', 'local_oerexchange', $card->itemcount);

        // No "Starred by 0": a zero reads as a judgement on the collection
        // rather than as a fact about the site.
        if ($card->starcount > 0) {
            $meta .= ' · ' . get_string('collectionstarcount', 'local_oerexchange', $card->starcount);
        }

        return $out . \html_writer::div($meta, 'small text-muted');
    }

    /**
     * Empty state — always text, never a blank region.
     *
     * @return string HTML
     */
    protected static function render_empty(): string {
        return \html_writer::tag(
            'p',
            get_string('collectionsempty', 'local_oerexchange'),
            ['class' => 'text-muted']
        );
    }

    /**
     * Full-width rendering for the listing page: a responsive card grid,
     * matching catalogue_view's grid classes so the two kinds of card line up
     * wherever they are shown together.
     *
     * @param \stdClass[] $cards
     * @return string HTML
     */
    public static function render_grid(array $cards): string {
        if (!$cards) {
            return self::render_empty();
        }

        $html = \html_writer::start_div('oerexchange-collections row row-cols-1 row-cols-md-3 g-3');
        foreach ($cards as $card) {
            // Marked aria-hidden + tabindex="-1": the image link goes to the same
            // place as the title immediately below it, so announcing it twice
            // and stopping on it twice is noise.
            $cover = \html_writer::link(
                $card->url,
                collection_cover::card($card->coverurl),
                ['tabindex' => '-1', 'aria-hidden' => 'true']
            );

            $body = self::render_title($card, 'h5', 'card-title');
            if ($card->teaser !== '') {
                $body .= \html_writer::tag('p', s($card->teaser), ['class' => 'card-text text-muted']);
            }
            $body .= self::render_meta($card);

            $html .= \html_writer::div(
                \html_writer::div($cover . \html_writer::div($body, 'card-body'), 'card h-100'),
                'col'
            );
        }
        $html .= \html_writer::end_div();

        return $html;
    }

    /**
     * Narrow rendering for a block region: thumbnail left, text right.
     *
     * @param \stdClass[] $cards
     * @return string HTML
     */
    public static function render_list(array $cards): string {
        if (!$cards) {
            return self::render_empty();
        }

        $html = \html_writer::start_tag('ul', ['class' => 'list-unstyled oerexchange-collections-list']);
        foreach ($cards as $card) {
            $thumb = \html_writer::link(
                $card->url,
                collection_cover::listitem($card->coverurl),
                ['tabindex' => '-1', 'aria-hidden' => 'true', 'class' => 'flex-shrink-0']
            );

            $body = \html_writer::div(
                self::render_title($card, 'div', 'fw-semibold') . self::render_meta($card),
                'flex-grow-1',
                ['style' => 'min-width:0;']
            );

            $html .= \html_writer::tag(
                'li',
                $thumb . $body,
                ['class' => 'oerexchange-collection-item d-flex gap-2 align-items-start mb-3']
            );
        }
        $html .= \html_writer::end_tag('ul');

        return $html;
    }

    /**
     * The sort and view controls: a real GET form that works with JavaScript
     * off. The AMD module hides the submit button and takes over the change
     * events — the same progressive-enhancement contract as amd/src/star.js.
     *
     * @param \moodle_url $baseurl the page this form submits back to
     * @param string $sort the currently selected sort
     * @param string $regionid id of the element whose contents get replaced
     * @param string $layout the currently selected view, one of the LAYOUT_* constants
     * @return string HTML
     */
    public static function render_sort_form(
        \moodle_url $baseurl,
        string $sort,
        string $regionid,
        string $layout = self::LAYOUT_CARDS
    ): string {
        $options = [];
        foreach (self::sort_keys() as $key) {
            $options[$key] = get_string('collectionsort' . $key, 'local_oerexchange');
        }

        // A real <label>, not just an aria-label: it names the control for
        // sighted users too, and an aria-label alone is not locatable by the
        // accessible-name lookups that assistive tech and test drivers use.
        $selectid = $regionid . '-sort';
        $label = \html_writer::tag(
            'label',
            get_string('collectionsort', 'local_oerexchange'),
            ['for' => $selectid, 'class' => 'small text-muted me-1 align-self-center']
        );

        $select = \html_writer::select(
            $options,
            self::PARAM_SORT,
            self::normalise_sort($sort),
            false,
            [
                'id' => $selectid,
                'class' => 'form-select-sm d-inline-block w-auto',
                'data-region' => 'oerexchange-collection-sort',
                'data-target' => $regionid,
            ]
        );

        $viewid = $regionid . '-view';
        $viewlabel = \html_writer::tag(
            'label',
            get_string('collectionviewas', 'local_oerexchange'),
            ['for' => $viewid, 'class' => 'small text-muted ms-2 me-1 align-self-center']
        );

        $viewoptions = [];
        foreach (self::layout_keys() as $key) {
            $viewoptions[$key] = get_string('collectionview_' . $key, 'local_oerexchange');
        }

        $viewselect = \html_writer::select(
            $viewoptions,
            self::PARAM_LAYOUT,
            self::normalise_layout($layout),
            false,
            [
                'id' => $viewid,
                'class' => 'form-select-sm d-inline-block w-auto',
                'data-region' => 'oerexchange-collection-view',
                'data-target' => $regionid,
            ]
        );

        $submit = \html_writer::empty_tag('input', [
            'type' => 'submit',
            'class' => 'btn btn-sm btn-outline-secondary ms-1',
            'data-region' => 'oerexchange-collection-sortgo',
            'value' => get_string('collectionsortapply', 'local_oerexchange'),
        ]);

        // Carry every other query parameter so sorting does not silently drop
        // paging or anything the host page put in the URL.
        $hidden = '';
        foreach ($baseurl->params() as $name => $value) {
            if ($name === self::PARAM_SORT || $name === self::PARAM_LAYOUT) {
                continue;
            }
            $hidden .= \html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => $name,
                'value' => $value,
            ]);
        }

        return \html_writer::tag(
            'form',
            $hidden . $label . $select . $viewlabel . $viewselect . $submit,
            [
                'method' => 'get',
                'action' => $baseurl->out_omit_querystring(),
                'class' => 'oerexchange-collection-sortform d-flex justify-content-end mb-2',
            ]
        );
    }
}
