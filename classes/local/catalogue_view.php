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
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class catalogue_view {
    /** @var int resources listed per page */
    public const PERPAGE = 20;

    /** @var int|null memoised total, so render()'s paging bar does not re-count */
    private ?int $total = null;

    /** @var array<int, \stdClass>|null memoised resource records for this page */
    private ?array $resources = null;

    /**
     * Builds a catalogue view for one set of filters.
     *
     * @param string $query free-text search over title/summary/tags
     * @param string $type '', 'course', 'activity' or 'data'
     * @param string $license license shortname filter, '' for all
     * @param string $language language filter, '' for all
     * @param int $page zero-based page number
     */
    public function __construct(
        /** @var string $query free-text search over title/summary/tags */
        private readonly string $query = '',
        /** @var string $type '', 'course', 'activity' or 'data' */
        private readonly string $type = '',
        /** @var string $license license shortname filter, '' for all */
        private readonly string $license = '',
        /** @var string $language language filter, '' for all */
        private readonly string $language = '',
        /** @var int $page zero-based page number */
        private readonly int $page = 0,
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
        );
    }

    /**
     * The WHERE clause and its parameters for the current filters. Only
     * published resources are ever listed.
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
     * Total number of published resources matching the current filters,
     * across all pages.
     *
     * @return int
     */
    public function get_total(): int {
        global $DB;

        if ($this->total === null) {
            [$wheresql, $sqlparams] = $this->build_where();
            $this->total = $DB->count_records_select('local_oerexchange_resources', $wheresql, $sqlparams);
        }
        return $this->total;
    }

    /**
     * The resource records for the current page.
     *
     * @return array<int, \stdClass>
     */
    public function get_resources(): array {
        global $DB;

        if ($this->resources === null) {
            [$wheresql, $sqlparams] = $this->build_where();
            $this->resources = $DB->get_records_select(
                'local_oerexchange_resources',
                $wheresql,
                $sqlparams,
                'timeshared DESC',
                '*',
                $this->page * self::PERPAGE,
                self::PERPAGE
            );
        }
        return $this->resources;
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
     * The catalogue body — search form plus result grid plus paging bar.
     *
     * @param \moodle_url $baseurl the URL the catalogue is being served
     *      from; becomes the search form's action and the paging bar's base
     * @return string HTML to sit between $OUTPUT->header() and footer()
     */
    public function render(\moodle_url $baseurl): string {
        global $OUTPUT;

        $resources = $this->get_resources();
        $html = '';

        $html .= \html_writer::start_tag('form', [
            'method' => 'get',
            'action' => $baseurl,
            'class' => 'oerexchange-searchform mb-3',
        ]);
        $html .= \html_writer::empty_tag('input', [
            'type' => 'text', 'name' => 'q', 'value' => $this->query,
            'placeholder' => get_string('searchplaceholder', 'local_oerexchange'), 'class' => 'form-control d-inline w-auto',
        ]);
        $html .= \html_writer::tag(
            'label',
            get_string('filterbytype', 'local_oerexchange'),
            ['for' => 'oerexchange-filter-type', 'class' => 'ms-2 me-1']
        );
        $html .= \html_writer::select(
            [
                '' => '',
                'course' => get_string('typecourse', 'local_oerexchange'),
                'activity' => get_string('typeactivity', 'local_oerexchange'),
                'data' => get_string('typedata', 'local_oerexchange'),
            ],
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

        if (empty($resources)) {
            $hasfilters = $this->query !== '' || $this->type !== '' || $this->license !== '' || $this->language !== '';
            $message = $hasfilters
                ? get_string('nocatalogresources', 'local_oerexchange')
                : get_string('catalogueempty', 'local_oerexchange');
            $html .= $OUTPUT->notification($message, 'info');

            return $html;
        }

        // One query for the whole page's cover images rather than one per card.
        $coverurls = cover_image::urls_for(array_keys($resources));
        // Likewise for sizes: how big a resource is decides whether it is
        // worth downloading on this connection, and it used to be invisible
        // until the download had already started.
        $sizes = size_advice::sizes_for(array_keys($resources));

        $html .= \html_writer::start_tag('div', ['class' => 'oerexchange-list row row-cols-1 row-cols-md-3 g-3']);
        foreach ($resources as $r) {
            $url = new \moodle_url('/local/oerexchange/resource.php', ['id' => $r->id]);
            if ($r->type === 'course') {
                $typelabel = get_string('typecourse', 'local_oerexchange');
            } else if ($r->type === 'data') {
                $typelabel = get_string('typedata', 'local_oerexchange');
                if (!empty($r->dataresourcetype)) {
                    $typelabel .= ' (' . get_string('datatype_' . $r->dataresourcetype, 'local_oerexchange') . ')';
                }
            } else {
                $typelabel = get_string('typeactivity', 'local_oerexchange')
                    . ($r->activitytype ? ' (' . s($r->activitytype) . ')' : '');
            }
            $html .= \html_writer::start_tag('div', ['class' => 'col']);
            $html .= \html_writer::start_tag('div', ['class' => 'card h-100']);
            // The whole card leads with the cover image, so the catalogue reads as
            // a shelf of courseware rather than a list of titles. Resources with
            // no cover get the same-sized default thumbnail, which keeps every
            // row of the grid aligned.
            $html .= \html_writer::link(
                $url,
                cover_image::card($coverurls[$r->id] ?? null),
                ['tabindex' => '-1', 'aria-hidden' => 'true']
            );
            $html .= \html_writer::start_tag('div', ['class' => 'card-body']);
            $title = format_string($r->title, true, ['context' => \context_system::instance()]);
            $html .= \html_writer::tag('h5', \html_writer::link($url, $title), ['class' => 'card-title']);
            // Filter first (multilang collapses to one language), then strip tags
            // and decode entities via content_to_text(), then shorten, then
            // escape exactly once — same order block_oerexchangebrowse.php uses
            // for its card summaries, which fixed a double-escape from
            // strip_tags() + s() on pre-encoded entities.
            $summaryfiltered = format_text($r->summary ?? '', FORMAT_HTML, ['context' => \context_system::instance()]);
            $html .= \html_writer::tag(
                'p',
                s(shorten_text(content_to_text($summaryfiltered, FORMAT_HTML), 140)),
                ['class' => 'card-text text-muted']
            );
            // The licence code is printed exactly as published; licence_display
            // escapes it and applies the capitals (when the setting is on) as a
            // CSS class rather than transforming the text. The filter dropdown
            // above deliberately keeps the stored spelling — its labels are
            // also its values, matched against the column in get_records_sql().
            $meta = $typelabel . ' · ' . licence_display::html($r->licenseshortname);
            if (!empty($sizes[$r->id])) {
                $meta .= ' · ' . s(size_advice::format($sizes[$r->id]));
            }
            $html .= \html_writer::tag('div', $meta, ['class' => 'small text-muted']);
            $html .= \html_writer::tag(
                'div',
                get_string('downloadcountlabel', 'local_oerexchange', $r->downloadcount) . ' · '
                    . get_string('importcountlabel', 'local_oerexchange', $r->importcount),
                ['class' => 'small text-muted']
            );
            $html .= \html_writer::end_tag('div');
            $html .= \html_writer::end_tag('div');
            $html .= \html_writer::end_tag('div');
        }
        $html .= \html_writer::end_tag('div');

        $pagingurl = new \moodle_url($baseurl, [
            'q' => $this->query, 'type' => $this->type,
            'license' => $this->license, 'language' => $this->language,
        ]);
        $html .= $OUTPUT->paging_bar($this->get_total(), $this->page, self::PERPAGE, $pagingurl);

        return $html;
    }
}
