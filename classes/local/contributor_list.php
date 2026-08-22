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
 * The contributor listing: who has published to this Exchange, how much, and
 * in what order.
 *
 * Owns both the query and the rendering so the Dashboard block and the
 * /contributors page cannot drift apart — the same arrangement catalogue_view
 * uses for index.php and the public-landing hook.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class contributor_list {
    /** @var int contributors per page on the full listing page */
    public const PERPAGE = 24;

    /** @var string sort by number of resources shared */
    public const SORT_RESOURCES = 'resources';

    /** @var string sort by number of courses shared */
    public const SORT_COURSES = 'courses';

    /** @var string sort by most recent share */
    public const SORT_RECENT = 'recent';

    /** @var string GET parameter carrying the chosen sort, on both the block and the page */
    public const PARAM_SORT = 'contribsort';

    /** @var int how many expertise tags a card shows before the rest are dropped */
    protected const MAX_TAGS = 3;

    /**
     * Every accepted sort key, in menu order.
     *
     * @return string[]
     */
    public static function sort_keys(): array {
        return [self::SORT_RESOURCES, self::SORT_COURSES, self::SORT_RECENT];
    }

    /**
     * Map any caller-supplied sort to a known key. The result picks a
     * hardcoded ORDER BY clause — a caller's string is never interpolated
     * into SQL.
     *
     * @param string $sort
     * @return string one of the SORT_* constants
     */
    public static function normalise_sort(string $sort): string {
        return in_array($sort, self::sort_keys(), true) ? $sort : self::SORT_RESOURCES;
    }

    /**
     * The inner UNION that defines contribution: a resource counts for its
     * creator and for every co-author, because a co-author holds exactly the
     * same rights over it (db/install.xml, local_oerexchange_coauthors
     * COMMENT; resource_manager::user_is_author()).
     *
     * UNION rather than UNION ALL: the schema says a creator is never also a
     * co-author row, but UNION makes double-counting impossible even if that
     * invariant is ever broken.
     *
     * @return string
     */
    protected static function contribution_union(): string {
        return "SELECT r.creatorid AS userid, r.id AS resourceid, r.type, r.timeshared
                  FROM {local_oerexchange_resources} r
                 WHERE r.status = :status1 AND r.creatorid <> 0
                 UNION
                SELECT ca.userid, r.id, r.type, r.timeshared
                  FROM {local_oerexchange_coauthors} ca
                  JOIN {local_oerexchange_resources} r ON r.id = ca.resourceid
                 WHERE r.status = :status2";
    }

    /**
     * The joins that decide who is eligible to be shown at all.
     *
     * A deleted or suspended account is not held up for recognition; and the
     * profiles join IS the visibility rule — the contributor's own "show my
     * profile publicly" switch — which simultaneously guarantees every card
     * has somewhere to link.
     *
     * @return string
     */
    protected static function eligibility_joins(): string {
        return "JOIN {user} u ON u.id = c.userid AND u.deleted = 0 AND u.suspended = 0
                JOIN {local_oerexchange_profiles} p ON p.userid = c.userid AND p.visible = 1";
    }

    /**
     * The status parameters both halves of the UNION need.
     *
     * @return array
     */
    protected static function status_params(): array {
        return [
            'status1' => resource_manager::STATUS_PUBLISHED,
            'status2' => resource_manager::STATUS_PUBLISHED,
        ];
    }

    /**
     * Fetch contributors with their contribution totals.
     *
     * @param string $sort one of the SORT_* constants; anything else falls back to SORT_RESOURCES
     * @param int $limit 0 for no limit
     * @param int $offset
     * @return \stdClass[] list of rows: userid, resourcecount, coursecount, latestshared, membersince
     */
    public static function get_contributors(
        string $sort = self::SORT_RESOURCES,
        int $limit = 0,
        int $offset = 0
    ): array {
        global $DB;

        $orderbys = [
            self::SORT_RESOURCES => 'resourcecount DESC, latestshared DESC, c.userid ASC',
            self::SORT_COURSES => 'coursecount DESC, resourcecount DESC, c.userid ASC',
            self::SORT_RECENT => 'latestshared DESC, c.userid ASC',
        ];
        $orderby = $orderbys[self::normalise_sort($sort)];

        $sql = "SELECT c.userid,
                       COUNT(DISTINCT c.resourceid) AS resourcecount,
                       COUNT(DISTINCT CASE WHEN c.type = 'course' THEN c.resourceid END) AS coursecount,
                       MAX(c.timeshared) AS latestshared,
                       MIN(c.timeshared) AS membersince
                  FROM (" . self::contribution_union() . ") c
                  " . self::eligibility_joins() . "
              GROUP BY c.userid
              ORDER BY {$orderby}";

        $records = $DB->get_records_sql($sql, self::status_params(), $offset, $limit);

        return array_values(array_map(fn($r) => (object) [
            'userid' => (int) $r->userid,
            'resourcecount' => (int) $r->resourcecount,
            'coursecount' => (int) $r->coursecount,
            'latestshared' => (int) $r->latestshared,
            'membersince' => (int) $r->membersince,
        ], $records));
    }

    /**
     * How many contributors are eligible in total, for the paging bar.
     *
     * @return int
     */
    public static function count_contributors(): int {
        global $DB;

        $sql = "SELECT COUNT(DISTINCT c.userid)
                  FROM (" . self::contribution_union() . ") c
                  " . self::eligibility_joins();

        return (int) $DB->count_records_sql($sql, self::status_params());
    }

    /**
     * Contributor rows enriched with everything a card needs, batched — one
     * query for users, one for profiles, one for badges, never one per card.
     *
     * @param string $sort one of the SORT_* constants
     * @param int $limit 0 for no limit
     * @param int $offset
     * @return \stdClass[] userid, user, fullname, profileurl, expertise, badges,
     *     resourcecount, coursecount, latestshared
     */
    public static function get_cards(string $sort, int $limit = 0, int $offset = 0): array {
        global $DB;

        $rows = self::get_contributors($sort, $limit, $offset);
        if (!$rows) {
            return [];
        }

        $userids = array_map(fn($r) => $r->userid, $rows);
        $users = $DB->get_records_list('user', 'id', $userids);
        $profiles = profile_manager::get_by_userids($userids);
        $badges = badge_manager::get_badges_for_users($userids);

        $cards = [];
        foreach ($rows as $row) {
            // The query already inner-joins both, so a gap here would mean the
            // row vanished mid-request. Skip rather than draw a card that
            // cannot link anywhere.
            if (!isset($users[$row->userid], $profiles[$row->userid])) {
                continue;
            }

            $expertise = json_decode($profiles[$row->userid]->expertise ?? '[]');
            $expertise = is_array($expertise) ? $expertise : [];

            $cards[] = (object) [
                'userid' => $row->userid,
                'user' => $users[$row->userid],
                'fullname' => fullname($users[$row->userid]),
                // routed_path(), never a plain moodle_url() — see the long
                // comment in profile_controller::view() for why.
                'profileurl' => \moodle_url::routed_path(
                    '/local_oerexchange/u/' . $profiles[$row->userid]->slug
                ),
                'expertise' => array_slice(array_values($expertise), 0, self::MAX_TAGS),
                'badges' => $badges[$row->userid] ?? [],
                'resourcecount' => $row->resourcecount,
                'coursecount' => $row->coursecount,
                'latestshared' => $row->latestshared,
            ];
        }

        return $cards;
    }

    /**
     * The shared inner text of a card: name, badges, tags, counts, recency.
     * Rendered once so the block and the page cannot drift apart.
     *
     * @param \stdClass $card a row from get_cards()
     * @return string HTML
     */
    protected static function render_card_body(\stdClass $card): string {
        $context = \core\context\system::instance();

        // The only anchor on the card. .stretched-link makes the whole card
        // clickable without nesting the counts and tags inside the link text,
        // which a screen reader would otherwise announce as the link's name.
        $out = \html_writer::link(
            $card->profileurl,
            format_string($card->fullname, true, ['context' => $context]),
            ['class' => 'stretched-link fw-semibold text-decoration-none']
        );

        foreach ($card->badges as $badgekey) {
            $out .= \html_writer::tag(
                'span',
                get_string('badge_' . $badgekey, 'local_oerexchange'),
                ['class' => 'badge bg-success ms-2']
            );
        }

        if ($card->expertise) {
            $tags = array_map(
                fn($tag) => format_string($tag, true, ['context' => $context]),
                $card->expertise
            );
            $out .= \html_writer::div(
                implode(' · ', $tags),
                'small text-muted oerexchange-contributor-tags'
            );
        }

        // Separate singular strings: "1 resources" is wrong in English, and
        // Moodle's get_string() has no plural-form selection to lean on.
        $resourcelabel = $card->resourcecount === 1
            ? get_string('contributors_resourcecount_one', 'local_oerexchange')
            : get_string('contributors_resourcecount', 'local_oerexchange', $card->resourcecount);
        $courselabel = $card->coursecount === 1
            ? get_string('contributors_coursecount_one', 'local_oerexchange')
            : get_string('contributors_coursecount', 'local_oerexchange', $card->coursecount);
        $counts = $resourcelabel . ' · ' . $courselabel;
        $out .= \html_writer::div($counts, 'small');

        if ($card->latestshared) {
            $out .= \html_writer::div(
                get_string(
                    'contributors_latestshare',
                    'local_oerexchange',
                    format_time(time() - $card->latestshared)
                ),
                'small text-muted'
            );
        }

        return $out;
    }

    /**
     * Empty state — always text, never a blank region.
     *
     * @return string HTML
     */
    protected static function render_empty(): string {
        return \html_writer::tag(
            'p',
            get_string('contributors_none', 'local_oerexchange'),
            ['class' => 'text-muted']
        );
    }

    /**
     * Narrow rendering for a block region: picture left, text right.
     *
     * @param \stdClass[] $cards
     * @return string HTML
     */
    public static function render_list(array $cards): string {
        global $OUTPUT;

        if (!$cards) {
            return self::render_empty();
        }

        $html = \html_writer::start_tag('ul', ['class' => 'list-unstyled oerexchange-contributors-list']);
        foreach ($cards as $card) {
            $picture = \html_writer::div(
                $OUTPUT->user_picture($card->user, ['size' => 40, 'link' => false]),
                'flex-shrink-0'
            );
            $body = \html_writer::div(
                self::render_card_body($card),
                'flex-grow-1',
                ['style' => 'min-width:0;']
            );
            $html .= \html_writer::tag(
                'li',
                $picture . $body,
                ['class' => 'oerexchange-contributor-item position-relative d-flex gap-2 align-items-start mb-3']
            );
        }
        $html .= \html_writer::end_tag('ul');

        return $html;
    }

    /**
     * Full-width rendering for the listing page: a responsive card grid,
     * matching catalogue_view's grid classes.
     *
     * @param \stdClass[] $cards
     * @return string HTML
     */
    public static function render_grid(array $cards): string {
        global $OUTPUT;

        if (!$cards) {
            return self::render_empty();
        }

        $html = \html_writer::start_div('oerexchange-contributors row row-cols-1 row-cols-md-3 g-3');
        foreach ($cards as $card) {
            $inner = \html_writer::div(
                $OUTPUT->user_picture($card->user, ['size' => 100, 'link' => false]),
                'mb-2'
            ) . self::render_card_body($card);

            $html .= \html_writer::div(
                \html_writer::div($inner, 'card h-100 position-relative p-3 text-center'),
                'col'
            );
        }
        $html .= \html_writer::end_div();

        return $html;
    }

    /**
     * The sort control: a real GET form that works with JavaScript off. The
     * AMD module hides the submit button and takes over the change event —
     * the same progressive-enhancement contract as amd/src/star.js.
     *
     * @param \moodle_url $baseurl the page this form submits back to
     * @param string $sort the currently selected sort
     * @param string $regionid id of the element whose contents get replaced
     * @return string HTML
     */
    public static function render_sort_form(\moodle_url $baseurl, string $sort, string $regionid): string {
        $options = [];
        foreach (self::sort_keys() as $key) {
            $options[$key] = get_string('contributors_sort_' . $key, 'local_oerexchange');
        }

        $select = \html_writer::select(
            $options,
            self::PARAM_SORT,
            self::normalise_sort($sort),
            false,
            [
                'class' => 'form-select form-select-sm d-inline-block w-auto',
                'data-region' => 'oerexchange-contributor-sort',
                'data-target' => $regionid,
                'aria-label' => get_string('contributors_sortby', 'local_oerexchange'),
            ]
        );

        $submit = \html_writer::empty_tag('input', [
            'type' => 'submit',
            'class' => 'btn btn-sm btn-outline-secondary ms-1',
            'data-region' => 'oerexchange-contributor-sortgo',
            'value' => get_string('contributors_sortapply', 'local_oerexchange'),
        ]);

        // Carry every other query parameter so sorting does not silently drop
        // paging or anything the host page put in the URL.
        $hidden = '';
        foreach ($baseurl->params() as $name => $value) {
            if ($name === self::PARAM_SORT) {
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
            $hidden . $select . $submit,
            [
                'method' => 'get',
                'action' => $baseurl->out_omit_querystring(),
                'class' => 'oerexchange-contributor-sortform d-flex justify-content-end mb-2',
            ]
        );
    }
}
