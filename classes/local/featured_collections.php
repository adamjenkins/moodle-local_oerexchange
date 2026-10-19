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
 * The featured-collections strip: a row of admin-chosen collections drawn at
 * the top (or bottom, or in place) of the catalogue landing page.
 *
 * It is rendered from inside {@see catalogue_view::render()} rather than from
 * the page scripts, for the same reason intro_html() is: the catalogue has
 * three entry points — /local/oerexchange/index.php, the anonymous site-root
 * takeover in \local_oerexchange\hook_callbacks::after_config(), and the
 * redirect for users whose default home page is the catalogue — and all three
 * converge on that one method. One setting therefore drives every surface,
 * instead of the front page and the plugin page disagreeing about what the
 * site is showcasing.
 *
 * Which collections appear is a list of collection IDS, not slugs: an owner
 * may rename their collection's slug at any time, and the strip must not
 * lose a featured collection when they do. A chosen collection that is no longer public — drafted again,
 * taken down, deleted — is skipped silently at render time rather than
 * breaking the strip or leaking that it ever existed; the authoritative test
 * is collection_manager::public_where(), the same clause every other listing
 * uses.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class featured_collections {
    /** @var string strip above the search form and the resource grid */
    public const PLACEMENT_ABOVE = 'above';

    /** @var string strip below the resource grid and its paging bar */
    public const PLACEMENT_BELOW = 'below';

    /** @var string strip only: search form, grid and paging bar all suppressed */
    public const PLACEMENT_INSTEAD = 'instead';

    /** @var string[] the valid placements, in the order the admin chooser offers them */
    public const PLACEMENTS = [self::PLACEMENT_ABOVE, self::PLACEMENT_BELOW, self::PLACEMENT_INSTEAD];

    /**
     * @var int how many collections the admin chooser offers.
     *
     * The chooser is built from a live query while the admin tree is built, so
     * it is capped: a site with ten thousand public collections must not
     * render a ten-thousand-option <select>, and an admin who wants one that
     * is not among the most recently published 200 has the browse page to find
     * it from.
     */
    public const MAX_CHOICES = 200;

    /** @var int how many of the chosen collections are actually drawn */
    public const MAX_FEATURED = 9;

    /**
     * Whether collections are switched on at all for this site.
     *
     * An ABSENT config row reads as on, not off, because the setting's
     * declared default is 1 and a multiselect/checkbox default is only written
     * to the database when admin_apply_default_settings() runs or an admin
     * saves the page. The same runtime-fallback discipline as
     * allowed_licenses::shortnames(), and for the same reason: the default an
     * admin is shown must be the default the code actually applies. An
     * explicit 0 is off.
     *
     * @return bool
     */
    public static function enabled(): bool {
        $enabled = get_config('local_oerexchange', 'collectionsenabled');

        return $enabled === false ? true : (bool) $enabled;
    }

    /**
     * Whether anything may query collections at all: switched on, and the
     * tables actually there.
     *
     * The table test is not paranoia. /local/oerexchange/index.php has no
     * login gate (anonymous browsing is this plugin's oldest deliberate
     * behaviour), so it is reachable in the window between new plugin code
     * being deployed and the admin running the upgrade that creates these
     * tables — and a catalogue page that fatals in that window is a catalogue
     * page that fatals for the whole world. $DB->get_tables() is cached, and
     * this is memoised on top of it.
     *
     * @return bool
     */
    public static function available(): bool {
        return self::enabled() && self::table_ready();
    }

    /**
     * Where the strip goes relative to the resource grid.
     *
     * An unrecognised stored value (a hand-edited config row, a setting
     * written by an older version) falls back to 'above' rather than to
     * 'instead': a bad value must never be the reason a visitor is left with
     * no resource grid.
     *
     * @return string one of self::PLACEMENTS
     */
    public static function placement(): string {
        $placement = (string) get_config('local_oerexchange', 'featuredplacement');

        return in_array($placement, self::PLACEMENTS, true) ? $placement : self::PLACEMENT_ABOVE;
    }

    /**
     * The heading above the strip: the admin's own wording, or the default.
     *
     * @return string plain text, NOT escaped — the caller escapes at the sink
     */
    public static function heading(): string {
        $heading = trim((string) get_config('local_oerexchange', 'featuredheading'));

        return $heading !== '' ? $heading : get_string('featuredcollectionsheading', 'local_oerexchange');
    }

    /**
     * The collection ids an admin chose, in the order the setting stores them.
     *
     * Ids, not slugs: an owner may change their collection's slug at any time,
     * and a featured collection must not silently drop off the front page
     * because its web address was tidied. admin_setting_configmultiselect
     * stores a comma-separated list of the values it was given, so this is
     * the one place that format is parsed.
     *
     * @return int[]
     */
    public static function selected_ids(): array {
        $raw = (string) get_config('local_oerexchange', 'featuredcollections');
        if (trim($raw) === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $id = (int) trim($part);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_slice(array_values(array_unique($ids)), 0, self::MAX_FEATURED);
    }

    /**
     * The chosen collections that are still publicly listable, in the admin's
     * order, keyed by collection id.
     *
     * ONE query. The public-listability rule is not restated here: it is
     * collection_manager::public_where(), which public_collections(),
     * search_public_collections() and star_manager's starred lists all share,
     * so a collection cannot stay featured on the front page after it has
     * stopped being listable everywhere else.
     *
     * @return \stdClass[] keyed by collection id
     */
    public static function collections(): array {
        global $DB;

        $ids = self::selected_ids();
        if (!$ids || !self::available()) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'fid');
        $rows = $DB->get_records_select(
            collection_manager::TABLE,
            "id {$insql} AND " . collection_manager::public_where(),
            $inparams + collection_manager::public_params()
        );

        // Back into the admin's order. An id with no row - unpublished, taken
        // down or deleted since it was chosen - is simply absent, which is
        // what "skipped silently" means.
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($rows[$id])) {
                $ordered[$id] = $rows[$id];
            }
        }

        return $ordered;
    }

    /**
     * The strip itself.
     *
     * Returns '' whenever there is nothing to draw — collections switched
     * off, nothing chosen, or nothing chosen that is still public. Callers
     * rely on that: {@see catalogue_view::render()} only suppresses the
     * resource grid for PLACEMENT_INSTEAD when this returned something, so an
     * admin cannot produce a blank landing page by featuring a collection
     * that has since been taken down.
     *
     * Six queries for the whole strip however many cards it draws
     * (collections, covers ×2, item counts, star counts, owners) — the same
     * batched discipline the resource grid follows.
     *
     * @param \moodle_url $catalogueurl the URL the catalogue is being served from
     * @param bool $withbrowselink render the "Browse the full catalogue" link; always
     *      true when the caller is suppressing the grid, so no admin setting can
     *      leave a visitor with no route into the catalogue
     * @return string HTML, or '' when there is nothing to show
     */
    public static function strip(\moodle_url $catalogueurl, bool $withbrowselink = false): string {
        global $DB;

        $collections = self::collections();
        if (!$collections) {
            return '';
        }

        $ids = array_keys($collections);
        // Batched, exactly like the resource grid's covers and sizes: a strip
        // of nine cards must not be nine image lookups and nine count
        // queries. display_urls() deliberately builds no missing mosaics — see
        // its docblock; a collection whose mosaic has not been generated yet
        // draws the default thumbnail here and gets one when somebody opens
        // it.
        $images = collection_cover::display_urls($ids);
        $itemcounts = collection_manager::counts_for($ids);
        $starcounts = star_manager::star_counts_for($ids, star_manager::ITEMTYPE_COLLECTION);
        $owners = $DB->get_records_list(
            'user',
            'id',
            array_values(array_unique(array_map(fn($c) => (int) $c->userid, $collections)))
        );

        $html = \html_writer::start_tag('section', ['class' => 'oerexchange-featured mb-4']);
        // Rendered with format_string(), not s(): the heading is admin-authored, and an
        // admin on a multilingual site writes a multilang <span> in it. It
        // escapes, so this is the only escaping applied.
        $html .= \html_writer::tag(
            'h3',
            format_string(self::heading(), true, ['context' => \context_system::instance()]),
            ['class' => 'h4 oerexchange-featured-heading']
        );
        $html .= \html_writer::start_tag('div', ['class' => 'oerexchange-list row row-cols-1 row-cols-md-3 g-3']);
        foreach ($collections as $id => $collection) {
            // The counts the card draws, set on the record rather than passed,
            // so one card helper serves both this strip and the blended
            // catalogue — whose rows arrive from
            // collection_manager::public_collections() already carrying them.
            $collection->itemcount = $itemcounts[$id] ?? 0;
            $collection->starcount = $starcounts[$id] ?? 0;
            $owner = $owners[(int) $collection->userid] ?? null;
            $html .= catalogue_view::collection_card(
                $collection,
                $images[$id] ?? null,
                $owner ? fullname($owner) : ''
            );
        }
        $html .= \html_writer::end_tag('div');

        if ($withbrowselink) {
            // Carries catalogue_view::PARAM_FULL so the link works even when
            // the strip has replaced the grid on every catalogue surface,
            // including the one the visitor is already looking at.
            $html .= \html_writer::div(
                \html_writer::link(
                    new \moodle_url($catalogueurl, [catalogue_view::PARAM_FULL => 1]),
                    get_string('featuredbrowseall', 'local_oerexchange'),
                    ['class' => 'btn btn-outline-secondary']
                ),
                'oerexchange-featured-browse mt-3'
            );
        }

        $html .= \html_writer::end_tag('section');

        return $html;
    }

    /**
     * The admin chooser's options: id => "Title (curator)".
     *
     * Built from the most recently published MAX_CHOICES public collections.
     * Two queries, and both are skipped entirely when the tables are not there
     * yet — this runs while the admin tree is built, which happens during
     * install and upgrade too, and admin_setting_configmultiselect is handed
     * its choices eagerly. See settings.php for the $ADMIN->fulltree guard
     * that keeps it off the cheap tree builds.
     *
     * Labels are plain text: core_admin/setting_configmultiselect escapes
     * {{name}} itself, so escaping here would show an admin the entities
     * rather than the title.
     *
     * @return array<int, string> collection id => label
     */
    public static function setting_choices(): array {
        global $DB;

        if (during_initial_install() || !self::table_ready()) {
            return [];
        }

        $rows = $DB->get_records_select(
            collection_manager::TABLE,
            collection_manager::public_where(),
            collection_manager::public_params(),
            'timepublished DESC, id DESC',
            'id, slug, title, userid',
            0,
            self::MAX_CHOICES
        );
        if (!$rows) {
            return [];
        }

        $owners = $DB->get_records_list(
            'user',
            'id',
            array_values(array_unique(array_map(fn($r) => (int) $r->userid, $rows)))
        );

        $choices = [];
        foreach ($rows as $row) {
            $owner = $owners[(int) $row->userid] ?? null;
            $choices[(int) $row->id] = $owner
                ? get_string('featuredcollectionchoice', 'local_oerexchange', (object) [
                    'title' => $row->title,
                    'curator' => fullname($owner),
                ])
                : (string) $row->title;
        }

        return $choices;
    }

    /**
     * Whether the collections table exists yet.
     *
     * The strip and the admin chooser both run on pages that are reachable
     * while this plugin is mid-upgrade (the admin tree is built on the plugins
     * check page, before the upgrade step that creates the table has run), and
     * a settings file that fatals there locks the admin out of the upgrade
     * itself.
     *
     * @return bool
     */
    protected static function table_ready(): bool {
        global $DB;

        static $ready = null;
        if ($ready === null) {
            $ready = $DB->get_manager()->table_exists(collection_manager::TABLE);
        }

        return $ready;
    }
}
