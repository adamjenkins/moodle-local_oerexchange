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
 * The catalogue's query and markup, extracted from index.php so that the
 * same listing can be rendered both at /local/oerexchange/index.php and,
 * when the publiclanding setting is on, in place at the site root by
 * \local_oerexchange\hook_callbacks::after_config().
 *
 * render() returns the body that sits between $OUTPUT->header() and
 * $OUTPUT->footer(); the caller owns the page setup and those two calls.
 *
 * The caller also supplies the base URL, which is what makes the second
 * render path possible: the search form and paging bar point at whatever
 * URL the catalogue is actually being served from, rather than always at
 * /local/oerexchange/index.php. Hardcoding it would mean the first search
 * from the site-root landing page threw the visitor back to the plugin
 * page.
 *
 * The catalogue lists TWO kinds of thing: published resources and public
 * collections. They come from different tables with different sort columns and
 * are blended in PHP, not in SQL — see {@see self::load_page()} for the bound
 * that makes that safe and for why a UNION was deliberately not used.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class catalogue_view {
    /** @var int cards listed per page, counting both kinds */
    public const PERPAGE = 20;

    /** @var string the type filter's fourth option: collections only */
    public const TYPE_COLLECTION = 'collection';

    /**
     * @var string request parameter that forces the full catalogue.
     *
     * The featured strip's PLACEMENT_INSTEAD mode suppresses the search form,
     * the grid and the paging bar, so the strip's "Browse the full catalogue"
     * link carries this to bring them back. Without it that link would land
     * the visitor on another copy of the strip — the placement applies to
     * every catalogue surface, including the one they are already on.
     */
    public const PARAM_FULL = 'full';

    /** @var string a merged row holding a resource record */
    public const KIND_RESOURCE = 'resource';

    /** @var string a merged row holding a collection record */
    public const KIND_COLLECTION = 'collection';

    /** @var int characters of summary shown on a card */
    public const TEASER_LENGTH = 140;

    /** @var int|null memoised total, so render()'s paging bar does not re-count */
    private ?int $total = null;

    /** @var array<int, \stdClass>|null memoised merged page: {kind, sortkey, id, record} */
    private ?array $rows = null;

    /**
     * Builds a catalogue view for one set of filters.
     *
     * @param string $query free-text search over title/summary/tags
     * @param string $type '', 'course', 'activity', 'data' or 'collection'
     * @param string $license license shortname filter, '' for all
     * @param string $language language filter, '' for all
     * @param int $page zero-based page number
     * @param bool $full force the search form, grid and paging bar on even when the
     *      featured strip is configured to replace them
     */
    public function __construct(
        /** @var string $query free-text search over title/summary/tags */
        private readonly string $query = '',
        /** @var string $type '', 'course', 'activity', 'data' or 'collection' */
        private readonly string $type = '',
        /** @var string $license license shortname filter, '' for all */
        private readonly string $license = '',
        /** @var string $language language filter, '' for all */
        private readonly string $language = '',
        /** @var int $page zero-based page number */
        private readonly int $page = 0,
        /** @var bool $full force the full catalogue on past an 'instead' featured strip */
        private readonly bool $full = false,
    ) {
    }

    /**
     * Builds a view from the current request's filter parameters. Safe to
     * call from a hook: it reads only optional_param(), and needs neither
     * a session nor a configured $PAGE.
     *
     * @return self
     */
    public static function from_request(): self {
        return new self(
            optional_param('q', '', PARAM_TEXT),
            optional_param('type', '', PARAM_ALPHA),
            optional_param('license', '', PARAM_TEXT),
            optional_param('language', '', PARAM_TEXT),
            optional_param('page', 0, PARAM_INT),
            (bool) optional_param(self::PARAM_FULL, 0, PARAM_BOOL),
        );
    }

    /**
     * Whether collections may appear in this catalogue at all.
     *
     * Asks the one place that owns the answer, so the catalogue cannot start
     * listing collections on a site whose admin turned the feature off — or
     * one whose tables the upgrade has not created yet; see
     * featured_collections::available().
     *
     * @return bool
     */
    private static function collections_enabled(): bool {
        return featured_collections::available();
    }

    /**
     * Whether resources take part in this page's results.
     *
     * @return bool
     */
    private function include_resources(): bool {
        return $this->type !== self::TYPE_COLLECTION;
    }

    /**
     * Whether collections take part in this page's results.
     *
     * The licence and language filters are properties of a resource — a
     * collection has neither — so setting either one is a statement that only
     * resources are wanted. Saying so here, once, is what keeps that rule out
     * of the three places that would otherwise each have to remember it.
     *
     * @return bool
     */
    private function include_collections(): bool {
        if (!self::collections_enabled()) {
            return false;
        }
        // Asking for collections explicitly wins: a licence or language left
        // set from an earlier search would otherwise exclude collections while
        // the type excludes resources, and the page would show nothing with
        // no reason given.
        if ($this->type === self::TYPE_COLLECTION) {
            return true;
        }
        if ($this->license !== '' || $this->language !== '') {
            return false;
        }

        return $this->type === '' || $this->type === self::TYPE_COLLECTION;
    }

    /**
     * The WHERE clause and its parameters for the current resource filters.
     * Only published resources are ever listed.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function build_where(): array {
        global $DB;

        $where = ['status = :status'];
        $sqlparams = ['status' => 'published'];

        if ($this->type !== '') {
            $where[] = 'type = :type';
            $sqlparams['type'] = $this->type;
        }
        if ($this->license !== '') {
            $where[] = 'licenseshortname = :license';
            $sqlparams['license'] = $this->license;
        }
        if ($this->language !== '') {
            $where[] = 'language = :language';
            $sqlparams['language'] = $this->language;
        }
        if ($this->query !== '') {
            $like = $DB->sql_like('title', ':q1', false) . ' OR ' . $DB->sql_like('summary', ':q2', false)
                . ' OR ' . $DB->sql_like('tags', ':q3', false);
            $where[] = "({$like})";
            $needle = '%' . $DB->sql_like_escape($this->query) . '%';
            $sqlparams['q1'] = $needle;
            $sqlparams['q2'] = $needle;
            $sqlparams['q3'] = $needle;
        }

        return [implode(' AND ', $where), $sqlparams];
    }

    /**
     * Total number of matching rows across all pages, both kinds together —
     * which is what the paging bar has to count, because one page holds
     * PERPAGE cards of either kind.
     *
     * @return int
     */
    public function get_total(): int {
        global $DB;

        if ($this->total === null) {
            $total = 0;
            if ($this->include_resources()) {
                [$wheresql, $sqlparams] = $this->build_where();
                $total += $DB->count_records_select('local_oerexchange_resources', $wheresql, $sqlparams);
            }
            if ($this->include_collections()) {
                $total += collection_manager::count_public_collections($this->query);
            }
            $this->total = $total;
        }

        return $this->total;
    }

    /**
     * This page's cards, both kinds, in one merged order.
     *
     * MERGED IN PHP, NOT IN SQL, deliberately. A UNION over two tables with
     * different column sets means casting both sides to a common shape, and
     * the cast rules differ between MariaDB and PostgreSQL — both test sites
     * here are MariaDB, so a cross-dialect UNION is exactly the kind of thing
     * that would pass every test here and fail on a user's PostgreSQL site.
     *
     * The cost is the bound: each side fetches (page + 1) * PERPAGE rows so
     * that the requested page can be sliced out of the merge, which is
     * PERPAGE * 2 rows for page 0 and grows linearly with the page number.
     * That is affordable for the first handful of pages and is the price of
     * the portability. If deep paging ever matters, this is the method to
     * replace with a UNION (and a dialect test for it), not the renderer.
     *
     * @return array<int, \stdClass> each row {kind, sortkey, id, record}
     */
    private function load_page(): array {
        global $DB;

        if ($this->rows !== null) {
            return $this->rows;
        }

        $window = ($this->page + 1) * self::PERPAGE;
        $rows = [];

        if ($this->include_resources()) {
            [$wheresql, $sqlparams] = $this->build_where();
            $resources = $DB->get_records_select(
                'local_oerexchange_resources',
                $wheresql,
                $sqlparams,
                'timeshared DESC',
                '*',
                0,
                $window
            );
            foreach ($resources as $resource) {
                $rows[] = (object) [
                    'kind' => self::KIND_RESOURCE,
                    'sortkey' => (int) $resource->timeshared,
                    'id' => (int) $resource->id,
                    'record' => $resource,
                ];
            }
        }

        if ($this->include_collections()) {
            // Both of these already order by timepublished DESC and carry
            // ->itemcount and ->starcount, so a card never costs a query.
            $collections = $this->query !== ''
                ? collection_manager::search_public_collections($this->query, $window, 0)
                : collection_manager::public_collections(collection_manager::SORT_RECENT, $window, 0);
            foreach ($collections as $collection) {
                $rows[] = (object) [
                    'kind' => self::KIND_COLLECTION,
                    // The timepublished column is stamped on the first transition to
                    // public, so a listed collection has one; a row that
                    // somehow does not sorts last rather than first.
                    'sortkey' => (int) ($collection->timepublished ?? 0),
                    'id' => (int) $collection->id,
                    'record' => $collection,
                ];
            }
        }

        // Newest first, with kind and id as tie-breakers so two things shared
        // in the same second do not swap places between page 1 and page 2.
        usort($rows, fn($a, $b) => [$b->sortkey, $b->kind, $b->id] <=> [$a->sortkey, $a->kind, $a->id]);

        $this->rows = array_slice($rows, $this->page * self::PERPAGE, self::PERPAGE);

        return $this->rows;
    }

    /**
     * The resource records on the current page, keyed by id.
     *
     * @return array<int, \stdClass>
     */
    public function get_resources(): array {
        $resources = [];
        foreach ($this->load_page() as $row) {
            if ($row->kind === self::KIND_RESOURCE) {
                $resources[$row->id] = $row->record;
            }
        }

        return $resources;
    }

    /**
     * The collection records on the current page, keyed by id.
     *
     * @return array<int, \stdClass>
     */
    public function get_collections(): array {
        $collections = [];
        foreach ($this->load_page() as $row) {
            if ($row->kind === self::KIND_COLLECTION) {
                $collections[$row->id] = $row->record;
            }
        }

        return $collections;
    }

    /**
     * The distinct non-empty values of a column across published
     * resources, sorted — used to populate the license/language filters
     * from what is actually in the catalogue.
     *
     * Populated from real data rather than a hardcoded list: these strings
     * existed (filterlicense, filterlanguage) but the form never used
     * them, so the only way to filter by license/language was to know the
     * exact query string params (found live, 2026-07-19, while documenting
     * the browse page for the walkthrough).
     *
     * @param string $column 'licenseshortname' or 'language'
     * @return array<int, string>
     */
    private function get_distinct(string $column): array {
        global $DB;

        $values = $DB->get_fieldset_select(
            'local_oerexchange_resources',
            'DISTINCT ' . $column,
            "status = :status AND {$column} <> ''",
            ['status' => 'published']
        );
        sort($values);
        return $values;
    }

    /**
     * The admin-authored content area shown above the catalogue, if any.
     *
     * Deliberately rendered here inside render() rather than in the page
     * scripts, because the catalogue has three entry points — the plugin's own
     * index.php, the anonymous site-root takeover in hook_callbacks, and the
     * redirect for users whose default home page is the catalogue — and all
     * three converge on this one method. Putting it anywhere else would give
     * one of them content the others lack. The featured-collections strip is
     * inserted immediately after it, for exactly the same reason.
     *
     * Sitting before render()'s empty-catalogue early return is intentional: a
     * welcome message is most useful on a catalogue with nothing in it yet.
     *
     * @return string HTML, empty when the area is switched off or unset
     */
    private static function intro_html(): string {
        if (!get_config('local_oerexchange', 'catalogueintroenabled')) {
            return '';
        }

        $intro = (string) get_config('local_oerexchange', 'catalogueintro');
        // The admin_setting_confightmleditor class normalises visually-empty markup to
        // '' on save, but a value stored before the setting existed, or by
        // another route, gets the same treatment here.
        if (html_is_blank($intro)) {
            return '';
        }

        // Rendered with format_text() and cleaning left on, matching core's own handling of
        // $CFG->searchbanner. The author is an admin, but the output is
        // world-readable and an admin's paste is still worth cleaning.
        return \html_writer::div(
            format_text($intro, FORMAT_HTML, ['context' => \context_system::instance()]),
            'oerexchange-intro mb-3'
        );
    }

    /**
     * The real, resolvable URL of a collection page.
     *
     * Always moodle_url::routed_path(), never a plain moodle_url(): the
     * #[route] attribute's declared path is component-relative, and a plain
     * moodle_url() only happens to resolve on a site whose router is
     * configured — see profile_controller::view()'s long comment for the full
     * reasoning behind the same call for /u/{slug}.
     *
     * @param \stdClass $collection a collections row, with ->slug
     * @return \moodle_url
     */
    public static function collection_url(\stdClass $collection): \moodle_url {
        return \moodle_url::routed_path('/local_oerexchange/c/' . $collection->slug);
    }

    /**
     * One collection card, shaped to sit in the same grid as a resource card.
     *
     * Public and static because two surfaces draw it: the blended catalogue
     * below, and {@see featured_collections::strip()}. It reads ->itemcount
     * and ->starcount off the record when they are there — public_collections()
     * and search_public_collections() both supply them, and the strip sets them
     * from its own batched counts — and omits that line when they are not,
     * rather than issuing a count query of its own.
     *
     * @param \stdClass $collection a collections row
     * @param \moodle_url|null $image from collection_cover::display_urls()
     * @param string $ownername the curator's full name, '' when unknown
     * @param string $badgeshtml extra state badges (already-built HTML), drawn
     *      after the kind label; the profile page uses it to show an owner
     *      which of their collections are drafts, unlisted or taken down
     * @return string HTML
     */
    public static function collection_card(
        \stdClass $collection,
        ?\moodle_url $image,
        string $ownername = '',
        string $badgeshtml = ''
    ): string {
        $context = \context_system::instance();
        $url = self::collection_url($collection);

        $html = \html_writer::start_tag('div', ['class' => 'col']);
        $html .= \html_writer::start_tag('div', ['class' => 'card h-100 oerexchange-collection-card']);
        // Same lead-with-the-picture shape as a resource card, so a blended
        // page reads as one shelf rather than two interleaved lists.
        $html .= \html_writer::link(
            $url,
            collection_cover::card($image),
            ['tabindex' => '-1', 'aria-hidden' => 'true']
        );
        $html .= \html_writer::start_tag('div', ['class' => 'card-body']);
        $html .= \html_writer::tag(
            'h5',
            \html_writer::link($url, format_string($collection->title, true, ['context' => $context])),
            ['class' => 'card-title']
        );
        $html .= \html_writer::tag(
            'p',
            self::teaser($collection->summary ?? '', (int) ($collection->summaryformat ?? FORMAT_HTML)),
            ['class' => 'card-text text-muted']
        );
        // The kind label is not decoration: in a blended grid it is the only
        // thing that tells a visitor this card is a reading list rather than a
        // single resource.
        $meta = \html_writer::tag(
            'span',
            get_string('collection', 'local_oerexchange'),
            ['class' => 'badge bg-secondary me-1']
        );
        $meta .= $badgeshtml;
        if ($ownername !== '') {
            $meta .= s(get_string('collectionby', 'local_oerexchange', $ownername));
        }
        $html .= \html_writer::tag('div', $meta, ['class' => 'small text-muted']);
        if (isset($collection->itemcount)) {
            $counts = s(get_string(
                (int) $collection->itemcount === 1 ? 'collectionitemcount_one' : 'collectionitemcount',
                'local_oerexchange',
                (int) $collection->itemcount
            ));
            // No "Starred by 0": a zero reads as a judgement on the collection
            // rather than as a fact about the site.
            if (!empty($collection->starcount)) {
                $counts .= ' · ' . s(get_string('collectionstarcount', 'local_oerexchange', (int) $collection->starcount));
            }
            $html .= \html_writer::tag('div', $counts, ['class' => 'small text-muted']);
        }
        $html .= \html_writer::end_tag('div');
        $html .= \html_writer::end_tag('div');
        $html .= \html_writer::end_tag('div');

        return $html;
    }

    /**
     * One resource card.
     *
     * @param \stdClass $resource a resources row
     * @param \moodle_url|null $coverurl from cover_image::urls_for()
     * @param int|null $size from size_advice::sizes_for(), null when unknown
     * @return string HTML
     */
    private static function resource_card(\stdClass $resource, ?\moodle_url $coverurl, ?int $size): string {
        $context = \context_system::instance();
        $url = new \moodle_url('/local/oerexchange/resource.php', ['id' => $resource->id]);

        $html = \html_writer::start_tag('div', ['class' => 'col']);
        $html .= \html_writer::start_tag('div', ['class' => 'card h-100']);
        // The whole card leads with the cover image, so the catalogue reads as
        // a shelf of courseware rather than a list of titles. Resources with
        // no cover get the same-sized default thumbnail, which keeps every
        // row of the grid aligned.
        $html .= \html_writer::link(
            $url,
            cover_image::card($coverurl),
            ['tabindex' => '-1', 'aria-hidden' => 'true']
        );
        $html .= \html_writer::start_tag('div', ['class' => 'card-body']);
        $title = format_string($resource->title, true, ['context' => $context]);
        $html .= \html_writer::tag('h5', \html_writer::link($url, $title), ['class' => 'card-title']);
        $html .= \html_writer::tag(
            'p',
            self::teaser($resource->summary ?? '', (int) ($resource->summaryformat ?? FORMAT_HTML)),
            ['class' => 'card-text text-muted']
        );
        // The licence code is printed exactly as published; licence_display
        // escapes it and applies the capitals (when the setting is on) as a
        // CSS class rather than transforming the text. The filter dropdown
        // deliberately keeps the stored spelling — its labels are also its
        // values, matched against the column in get_records_select().
        $meta = resource_type::label($resource) . ' · ' . licence_display::html($resource->licenseshortname);
        if (!empty($size)) {
            $meta .= ' · ' . s(size_advice::format($size));
        }
        $html .= \html_writer::tag('div', $meta, ['class' => 'small text-muted']);
        $html .= \html_writer::tag(
            'div',
            get_string('downloadcountlabel', 'local_oerexchange', $resource->downloadcount) . ' · '
                . get_string('importcountlabel', 'local_oerexchange', $resource->importcount),
            ['class' => 'small text-muted']
        );
        $html .= \html_writer::end_tag('div');
        $html .= \html_writer::end_tag('div');
        $html .= \html_writer::end_tag('div');

        return $html;
    }

    /**
     * A card's plain-text teaser, escaped exactly once.
     *
     * Filter first (multilang collapses to one language), then strip tags and
     * decode entities via content_to_text(), then shorten, then escape — the
     * same order block_oerexchangebrowse.php uses for its card summaries,
     * which fixed a double-escape from strip_tags() + s() on pre-encoded
     * entities.
     *
     * @param string $text the stored summary
     * @param int $format its FORMAT_* constant
     * @return string escaped plain text
     */
    private static function teaser(string $text, int $format): string {
        $filtered = format_text($text, $format, ['context' => \context_system::instance()]);

        return s(shorten_text(content_to_text($filtered, FORMAT_HTML), self::TEASER_LENGTH));
    }

    /**
     * The catalogue body — the featured strip, the search form, the result
     * grid and the paging bar.
     *
     * @param \moodle_url $baseurl the URL the catalogue is being served
     *      from; becomes the search form's action and the paging bar's base
     * @return string HTML to sit between $OUTPUT->header() and footer()
     */
    public function render(\moodle_url $baseurl): string {
        global $OUTPUT;

        $html = self::intro_html();

        // The strip sits immediately after the intro area, on every catalogue
        // surface, because one setting has to drive both of them (see
        // featured_collections' class docblock).
        $placement = featured_collections::placement();
        $replacescatalogue = $placement === featured_collections::PLACEMENT_INSTEAD && !$this->full;
        $strip = featured_collections::strip($baseurl, $replacescatalogue);
        // Suppress the catalogue ONLY when the strip actually drew something.
        // An admin who sets 'instead' and then features a collection that is
        // later taken down must get the resource grid back, not a blank page.
        $replacescatalogue = $replacescatalogue && $strip !== '';
        $stripisbelow = $placement === featured_collections::PLACEMENT_BELOW;

        if (!$stripisbelow) {
            $html .= $strip;
        }
        if ($replacescatalogue) {
            // Nothing below this point runs, which is also why the page's rows
            // are not loaded until after it: an 'instead' landing page must
            // not pay for a catalogue query whose results it never draws.
            return $html;
        }

        $rows = $this->load_page();

        $html .= \html_writer::start_tag('form', [
            'method' => 'get',
            'action' => $baseurl,
            'class' => 'oerexchange-searchform mb-3',
        ]);
        if ($this->full) {
            // Carried through the search and (below) the paging bar, so a
            // visitor who followed the strip's "Browse the full catalogue"
            // link does not get bounced back to the strip by their first
            // search or their second page.
            $html .= \html_writer::empty_tag('input', [
                'type' => 'hidden', 'name' => self::PARAM_FULL, 'value' => '1',
            ]);
        }
        $html .= \html_writer::empty_tag('input', [
            'type' => 'text', 'name' => 'q', 'value' => $this->query,
            'placeholder' => get_string('searchplaceholder', 'local_oerexchange'), 'class' => 'form-control d-inline w-auto',
        ]);
        $html .= \html_writer::tag(
            'label',
            get_string('filterbytype', 'local_oerexchange'),
            ['for' => 'oerexchange-filter-type', 'class' => 'ms-2 me-1']
        );
        $typechoices = [
            '' => '',
            'course' => get_string('typecourse', 'local_oerexchange'),
            'activity' => get_string('typeactivity', 'local_oerexchange'),
            'data' => get_string('typedata', 'local_oerexchange'),
        ];
        if (self::collections_enabled()) {
            // Offered only when the feature is on, so the filter cannot ask
            // for something the site never lists.
            $typechoices[self::TYPE_COLLECTION] = get_string('collection', 'local_oerexchange');
        }
        $html .= \html_writer::select(
            $typechoices,
            'type',
            $this->type,
            false,
            ['id' => 'oerexchange-filter-type', 'class' => 'form-select d-inline w-auto']
        );
        $html .= \html_writer::tag(
            'label',
            get_string('filterlicense', 'local_oerexchange'),
            ['for' => 'oerexchange-filter-license', 'class' => 'ms-2 me-1']
        );
        // The option *labels* are escaped with s(); the keys are not, because
        // they become the option's value attribute, which html_writer escapes
        // itself. html_writer::select() passes each label straight to
        // html_writer::tag() (lib/classes/output/html_writer.php:346), and
        // tag() does not escape its content — so an unescaped label here
        // would be an output sink for whatever is in the column. The publish
        // web service declares these PARAM_TEXT, which strips tags, so this
        // is defence in depth rather than a live hole; it is applied at the
        // sink so the value is neutralised however it reached the database,
        // not only via the one write path that happens to clean it today.
        $distinctlicenses = $this->get_distinct('licenseshortname');
        $html .= \html_writer::select(
            array_merge(['' => ''], array_combine($distinctlicenses, array_map('s', $distinctlicenses))),
            'license',
            $this->license,
            false,
            ['id' => 'oerexchange-filter-license', 'class' => 'form-select d-inline w-auto']
        );
        $html .= \html_writer::tag(
            'label',
            get_string('filterlanguage', 'local_oerexchange'),
            ['for' => 'oerexchange-filter-language', 'class' => 'ms-2 me-1']
        );
        // Labels escaped at the sink, keys left raw — see the licence filter
        // above for why.
        $distinctlanguages = $this->get_distinct('language');
        $html .= \html_writer::select(
            array_merge(['' => ''], array_combine($distinctlanguages, array_map('s', $distinctlanguages))),
            'language',
            $this->language,
            false,
            ['id' => 'oerexchange-filter-language', 'class' => 'form-select d-inline w-auto']
        );
        $html .= ' ';
        $html .= \html_writer::empty_tag('input', [
            'type' => 'submit',
            'value' => get_string('searchbutton', 'local_oerexchange'),
            'class' => 'btn btn-primary ms-2',
        ]);
        $html .= \html_writer::end_tag('form');

        if (empty($rows)) {
            $hasfilters = $this->query !== '' || $this->type !== '' || $this->license !== '' || $this->language !== '';
            $message = $hasfilters
                ? get_string('nocatalogresources', 'local_oerexchange')
                : get_string('catalogueempty', 'local_oerexchange');
            $html .= $OUTPUT->notification($message, 'info');

            return $stripisbelow ? $html . $strip : $html;
        }

        $resourceids = array_keys($this->get_resources());
        $collectionids = array_keys($this->get_collections());
        // One query per kind of lookup for the whole page, never one per card:
        // covers and sizes for the resources (how big a resource is decides
        // whether it is worth downloading on this connection, and that used to
        // be invisible until the download had already started), and cover
        // images for the collections.
        $coverurls = $resourceids ? cover_image::urls_for($resourceids) : [];
        $sizes = $resourceids ? size_advice::sizes_for($resourceids) : [];
        $collectionimages = $collectionids ? collection_cover::display_urls($collectionids) : [];
        $owners = $this->collection_owner_names($this->get_collections());

        $html .= \html_writer::start_tag('div', ['class' => 'oerexchange-list row row-cols-1 row-cols-md-3 g-3']);
        foreach ($rows as $row) {
            if ($row->kind === self::KIND_COLLECTION) {
                $html .= self::collection_card(
                    $row->record,
                    $collectionimages[$row->id] ?? null,
                    $owners[(int) $row->record->userid] ?? ''
                );
                continue;
            }
            $html .= self::resource_card(
                $row->record,
                $coverurls[$row->id] ?? null,
                isset($sizes[$row->id]) ? (int) $sizes[$row->id] : null
            );
        }
        $html .= \html_writer::end_tag('div');

        $pagingparams = [
            'q' => $this->query, 'type' => $this->type,
            'license' => $this->license, 'language' => $this->language,
        ];
        if ($this->full) {
            $pagingparams[self::PARAM_FULL] = 1;
        }
        $pagingurl = new \moodle_url($baseurl, $pagingparams);
        $html .= $OUTPUT->paging_bar($this->get_total(), $this->page, self::PERPAGE, $pagingurl);

        if ($stripisbelow) {
            $html .= $strip;
        }

        return $html;
    }

    /**
     * The curators' names for a page of collections, in ONE query.
     *
     * @param \stdClass[] $collections keyed by collection id
     * @return array<int, string> keyed by userid
     */
    private function collection_owner_names(array $collections): array {
        global $DB;

        if (!$collections) {
            return [];
        }

        $userids = array_values(array_unique(array_map(fn($c) => (int) $c->userid, $collections)));
        $users = $DB->get_records_list('user', 'id', $userids);

        $names = [];
        foreach ($users as $user) {
            $names[(int) $user->id] = fullname($user);
        }

        return $names;
    }
}
