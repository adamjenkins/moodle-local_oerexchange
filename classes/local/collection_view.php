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
 * One collection's page body — everything between $OUTPUT->header() and
 * $OUTPUT->footer().
 *
 * Extracted from collection_controller for the same reason catalogue_view was
 * extracted from index.php: the body has to be renderable from somewhere that
 * is not the controller (the controller re-renders it after a failed POST, and
 * a stale-sesskey POST re-renders it rather than redirecting), and two copies
 * of a page this long would drift apart within a week.
 *
 * It follows this plugin's established page idiom exactly: html_writer inside
 * static render* methods, no Mustache template and no classes/output renderer,
 * because that is what profile_controller, catalogue_view, share_targets and
 * every page script in this plugin already do.
 *
 * AUTHORISATION. This class decides nothing. Every question about what the
 * viewer may do is asked of collection_manager (user_can_curate(),
 * user_can_add_items(), user_can_remove_item(), user_can_manage()), which is
 * the single gate for all three curation tiers. The controller asks the same
 * questions again before acting on any POST, so a control this class chose not
 * to draw is not the thing preventing the action — the markup is a courtesy,
 * never the check.
 *
 * ESCAPING. Every value reaching the page is escaped at its sink: titles
 * through format_string() (a multilang title must collapse to one language,
 * not render as literal markup), the collection description through
 * format_text() with its stored format, curator notes through format_text()
 * with FORMAT_MOODLE (they are typed into a plain textarea, so real newlines
 * are significant — the same reasoning profile_controller documents for a
 * profile bio), user names and plain strings through s(). Nothing is escaped
 * twice: html_writer escapes its own attribute values, and format_string()
 * escapes internally.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class collection_view {
    /** @var int how many catalogue matches the "Add resources" search offers at once */
    public const ADDPERPAGE = 10;

    /** @var int how many characters of a resource teaser an add-search result shows */
    public const TEASER_LENGTH = 140;

    /**
     * Whether collections are offered on this site at all.
     *
     * The admin setting's own description (settings_collectionsenabled_desc)
     * states what turning it off means: "hides the Collections pages and the
     * 'Add to collection' control; nothing already created is deleted, and
     * turning it back on restores it". So this is asked by the collection
     * page, the collection edit page and the add-to-collection control, and
     * each of them refuses rather than hiding a control the server would still
     * honour.
     *
     * An UNSAVED setting reads as enabled, deliberately, and with the same
     * reasoning share_targets::enabled() documents for its own default: a
     * feature must not be invisible on an upgraded site merely because nobody
     * has yet opened its settings page and pressed Save. Only an explicit '0'
     * turns it off.
     *
     * @return bool
     */
    public static function enabled(): bool {
        $setting = get_config('local_oerexchange', 'collectionsenabled');

        return $setting === false || (bool) $setting;
    }

    /**
     * The tombstone body for a deleted collection.
     *
     * Shown to everyone, moderators and strangers alike, and deliberately
     * rendered BEFORE any access check — collection_manager::is_tombstone() is
     * the hook for that, because user_can_view_collection() answers false for
     * a tombstone by design. Same ordering, same reason, as resource.php's
     * 'deleted' branch.
     *
     * The body itself carries no content: no owner, no description, no item
     * list, no reason. The collection's title is still the page's heading,
     * which collection_manager::delete() deliberately preserves so the
     * tombstone can say honestly which collection this was (its docblock
     * explains why, and notes that a GDPR erasure scrubs it on a different
     * path). Everything else a tombstone could show is content, and a
     * tombstone exists so an old link does not break — not so a deleted
     * collection can still be read through its gravestone.
     *
     * @return string HTML
     */
    public static function tombstone(): string {
        global $OUTPUT;

        $out = $OUTPUT->heading(get_string('collectiontombstoneheading', 'local_oerexchange'));
        $out .= \html_writer::tag('p', get_string('collectiontombstone', 'local_oerexchange'));
        $out .= \html_writer::link(
            new \moodle_url('/local/oerexchange/index.php'),
            get_string('catalogtitle', 'local_oerexchange'),
            ['class' => 'btn btn-outline-primary']
        );

        return $out;
    }

    /**
     * The full collection page body.
     *
     * @param \stdClass $collection a collections row, already known to be viewable by $userid
     * @param \moodle_url $pageurl the collection's own real, routed URL — every form posts
     *      back to it and the share disclosure advertises it, so it must be the
     *      moodle_url::routed_path() one the controller built, never a plain moodle_url()
     * @param int $userid the viewer; 0 for a logged-out visitor
     * @return string HTML
     */
    public static function render(\stdClass $collection, \moodle_url $pageurl, int $userid): string {
        $cancurate = collection_manager::user_can_curate($collection, $userid);
        $canmanage = collection_manager::user_can_manage($collection, $userid);
        $canadd = collection_manager::user_can_add_items($collection, $userid);
        // Asked directly rather than through collection_manager, whose
        // is_moderator() is protected: this is the same inline
        // has_capability() call resource.php's own moderator controls use.
        $ismoderator = $userid > 0
            && has_capability('local/oerexchange:moderate', \context_system::instance(), $userid);

        // Curators (the owner included) see the items whose resource has since
        // been hidden or taken down, because they are the only people who can
        // do anything about them. Everybody else — moderators included — sees
        // the collection as the public sees it.
        $items = collection_manager::get_items((int) $collection->id, $cancurate);

        $out = self::render_notices($collection, $userid, $cancurate, $canadd, $ismoderator);
        $out .= self::render_head($collection, $pageurl, $userid, $canmanage);
        $out .= self::render_items($collection, $pageurl, $items, $userid, $cancurate);

        if ($canadd) {
            $out .= self::render_add_items($pageurl, $items);
        }

        $out .= self::render_moderation($collection, $pageurl, $ismoderator);
        $out .= self::render_report($pageurl, $userid);

        return $out;
    }

    /**
     * The state notices above the page: draft, unlisted, taken down, open for
     * contributions.
     *
     * Each one is drawn only for somebody it tells something actionable. A
     * stranger on an unlisted collection is not told it is unlisted — they
     * have the link, that is the whole of what unlisted means, and labelling
     * it would only invite them to wonder who else has it.
     *
     * @param \stdClass $collection
     * @param int $userid
     * @param bool $cancurate
     * @param bool $canadd
     * @param bool $ismoderator
     * @return string HTML
     */
    protected static function render_notices(
        \stdClass $collection,
        int $userid,
        bool $cancurate,
        bool $canadd,
        bool $ismoderator
    ): string {
        global $OUTPUT;

        $out = '';
        $isowner = $userid > 0 && (int) $collection->userid === $userid;

        if (!empty($collection->modhidden)) {
            if ($isowner) {
                $out .= $OUTPUT->notification(
                    get_string('collectionmodhiddenownernotice', 'local_oerexchange'),
                    'warning'
                );
            } else if ($ismoderator) {
                $out .= $OUTPUT->notification(
                    get_string(
                        'collectionmodhiddenmodnotice',
                        'local_oerexchange',
                        userdate((int) $collection->modhiddentime)
                    ),
                    'info'
                );
            }
        }

        if ((string) $collection->visibility === collection_manager::VISIBILITY_DRAFT && $isowner) {
            $out .= $OUTPUT->notification(
                get_string('collectionvisibilitydraftnotice', 'local_oerexchange'),
                'info'
            );
        } else if ((string) $collection->visibility === collection_manager::VISIBILITY_UNLISTED && $cancurate) {
            $out .= $OUTPUT->notification(
                get_string('collectionvisibilityunlistednotice', 'local_oerexchange'),
                'info'
            );
        }

        // Only to somebody who is NOT already a curator: the invitation is the
        // news here, and telling the owner their own collection is open to
        // contributions is just repeating their own setting back at them (the
        // badge in the meta line already does that).
        if (!empty($collection->opencuration) && !$cancurate && $canadd) {
            $out .= $OUTPUT->notification(
                get_string('collectionopencurationnotice', 'local_oerexchange'),
                'info'
            );
        }

        return $out;
    }

    /**
     * Cover, title, byline, description, counts and the action row.
     *
     * @param \stdClass $collection
     * @param \moodle_url $pageurl
     * @param int $userid
     * @param bool $canmanage
     * @return string HTML
     */
    protected static function render_head(
        \stdClass $collection,
        \moodle_url $pageurl,
        int $userid,
        bool $canmanage
    ): string {
        $out = '';

        // The display_url() call builds the mosaic on demand when there is no uploaded
        // cover; it returns null — never throws — when there is nothing to
        // draw, and card() renders the platform default for null so a
        // collection without a picture still lays out like one that has one.
        $coverurl = collection_cover::display_url((int) $collection->id);
        $out .= \html_writer::tag(
            'div',
            collection_cover::card($coverurl),
            ['class' => 'oerexchange-collection-cover mb-3', 'style' => 'max-width:48rem;']
        );

        $out .= \html_writer::tag('p', get_string('collection', 'local_oerexchange'), [
            'class' => 'text-muted small mb-1',
        ]);
        $out .= \html_writer::tag(
            'h2',
            format_string($collection->title, true, ['context' => \context_system::instance()]),
            ['class' => 'mb-2']
        );

        $out .= self::render_byline($collection);

        if ((string) ($collection->summary ?? '') !== '') {
            // The stored format, not an assumed one: the edit form's editor
            // element can be the plain text area on a site whose default
            // editor is textarea, and collection_edit.php normalises what it
            // stores to FORMAT_HTML, so honouring the column is both correct
            // today and correct if that normalisation ever changes.
            $out .= \html_writer::tag(
                'div',
                format_text(
                    $collection->summary,
                    (int) ($collection->summaryformat ?? FORMAT_HTML),
                    ['context' => \context_system::instance()]
                ),
                ['class' => 'oerexchange-collection-summary mb-2']
            );
        }

        $out .= self::render_meta($collection, $canmanage);
        $out .= self::render_actions($collection, $pageurl, $userid, $canmanage);

        return $out;
    }

    /**
     * "Collected by X", plus the named curators when there are any.
     *
     * The owner's name links to their educator profile, but only when that
     * profile is visible: profile_controller 404s a hidden profile to everybody
     * but its owner, and a byline that links somewhere nobody can reach is
     * worse than a byline that does not link.
     *
     * @param \stdClass $collection
     * @return string HTML
     */
    protected static function render_byline(\stdClass $collection): string {
        global $DB;

        $owner = $DB->get_record('user', ['id' => $collection->userid, 'deleted' => 0]);
        if (!$owner) {
            // The owner's account has gone (or is mid-deletion). The
            // collection is still readable; it simply has no byline rather
            // than a byline naming nobody.
            return '';
        }

        $out = \html_writer::tag(
            'p',
            // The link markup is passed as the string's {$a} placeholder,
            // which get_string() substitutes verbatim. The only untrusted part
            // of it is the name, escaped with s() here because
            // html_writer::link() does not escape its link text (it hands it
            // to html_writer::tag(), which never escapes content).
            get_string('collectionby', 'local_oerexchange', self::user_link($owner)),
            ['class' => 'small text-muted mb-2']
        );

        $curators = collection_curator_manager::get_curators((int) $collection->id);
        if ($curators) {
            $links = [];
            foreach ($curators as $curator) {
                $links[] = self::user_link($curator);
            }
            $out .= \html_writer::tag(
                'p',
                get_string('collectioncurators', 'local_oerexchange') . ': ' . implode(', ', $links),
                ['class' => 'small text-muted mb-2']
            );
        }

        return $out;
    }

    /**
     * A user's name, linked to their educator profile when they have a visible
     * one.
     *
     * @param \stdClass $user a user record
     * @return string HTML, already escaped
     */
    protected static function user_link(\stdClass $user): string {
        $name = s(fullname($user));
        $profile = profile_manager::get_by_userid((int) $user->id);
        if (!$profile || empty($profile->visible)) {
            return $name;
        }

        // Built with routed_path(), not a plain moodle_url(): see
        // profile_controller::view()'s long comment on why the component
        // prefix and this factory are both required for a routed URL to
        // resolve off this dev VM.
        return \html_writer::link(
            \moodle_url::routed_path('/local_oerexchange/u/' . $profile->slug),
            $name
        );
    }

    /**
     * The small grey line of counts and dates, plus the owner-facing state
     * badges.
     *
     * @param \stdClass $collection
     * @param bool $canmanage whether to show the visibility/contribution badges
     * @return string HTML
     */
    protected static function render_meta(\stdClass $collection, bool $canmanage): string {
        // Using item_count() rather than count($items): it counts only items whose
        // resource is still published, so the caption cannot promise more than
        // the page below then draws, even on the owner's own view where the
        // list itself also carries the unavailable ones.
        $itemcount = collection_manager::item_count((int) $collection->id);
        $starcount = star_manager::star_count((int) $collection->id, star_manager::ITEMTYPE_COLLECTION);

        $bits = [
            get_string('collectionitemcountlabel', 'local_oerexchange', $itemcount),
        ];
        if ($starcount > 0) {
            $bits[] = get_string('collectionstarcount', 'local_oerexchange', $starcount);
        }
        if (!empty($collection->timepublished)) {
            $bits[] = get_string(
                'collectionpublished',
                'local_oerexchange',
                userdate((int) $collection->timepublished, get_string('strftimedate', 'langconfig'))
            );
        }
        $bits[] = get_string(
            'collectionupdated',
            'local_oerexchange',
            userdate((int) $collection->timemodified, get_string('strftimedate', 'langconfig'))
        );

        $out = \html_writer::tag('div', implode(' · ', $bits), ['class' => 'small text-muted mb-2']);

        if (!$canmanage) {
            return $out;
        }

        // Owner-only state badges. They are not shown to visitors: on a public
        // collection they would say nothing ("Public", "Closed"), and on an
        // unlisted one they would volunteer how the viewer came to be here.
        $visibilitylabels = [
            collection_manager::VISIBILITY_DRAFT => 'collectionvisibilitydraft',
            collection_manager::VISIBILITY_UNLISTED => 'collectionvisibilityunlisted',
            collection_manager::VISIBILITY_PUBLIC => 'collectionvisibilitypublic',
        ];
        $visibilitykey = $visibilitylabels[(string) $collection->visibility] ?? null;

        $out .= \html_writer::start_tag('div', ['class' => 'mb-3']);
        if ($visibilitykey !== null) {
            $out .= \html_writer::tag(
                'span',
                get_string($visibilitykey, 'local_oerexchange'),
                ['class' => 'badge bg-secondary me-1']
            );
        }
        $out .= \html_writer::tag(
            'span',
            get_string(
                empty($collection->opencuration) ? 'collectionopencurationoff' : 'collectionopencurationon',
                'local_oerexchange'
            ),
            ['class' => 'badge bg-secondary me-1']
        );
        if (!empty($collection->modhidden)) {
            $out .= \html_writer::tag(
                'span',
                get_string('collectionmodhidden', 'local_oerexchange'),
                ['class' => 'badge bg-danger me-1']
            );
        }
        $out .= \html_writer::end_tag('div');

        return $out;
    }

    /**
     * Edit, star and share.
     *
     * The star is a real submit button in its own POST form rather than the
     * link-plus-AMD arrangement resource.php uses for resources: there is no
     * web service for starring a collection, so there is nothing for an AMD
     * module to call, and a state change with nothing to intercept belongs in
     * a POST rather than in a GET link carrying a sesskey.
     *
     * @param \stdClass $collection
     * @param \moodle_url $pageurl
     * @param int $userid
     * @param bool $canmanage
     * @return string HTML
     */
    protected static function render_actions(
        \stdClass $collection,
        \moodle_url $pageurl,
        int $userid,
        bool $canmanage
    ): string {
        global $PAGE;

        $out = \html_writer::start_tag('div', ['class' => 'oerexchange-collection-actions mb-4']);

        if ($canmanage) {
            $out .= \html_writer::link(
                new \moodle_url('/local/oerexchange/collection_edit.php', ['id' => $collection->id]),
                get_string('collectionedit', 'local_oerexchange'),
                ['class' => 'btn btn-outline-primary me-2', 'id' => 'oerexchange-collection-editlink']
            );
        }

        $isstarred = $userid > 0 && !isguestuser($userid) && star_manager::is_starred(
            (int) $collection->id,
            $userid,
            star_manager::ITEMTYPE_COLLECTION
        );
        // Offered only where a star can show up (see
        // collection_manager::is_publicly_listable()), but never withheld
        // from someone who already holds one and wants to remove it.
        if (
            $userid > 0 && !isguestuser($userid)
                && ($isstarred || collection_manager::is_publicly_listable($collection))
        ) {
            // A <button>, not an <input>: amd/src/collectionstar.js repaints
            // the label in place with textContent, which an <input> ignores.
            // The label carries the count in the same "Star (N)" shape the
            // module writes back, as resource.php's star does.
            $starcount = star_manager::star_count((int) $collection->id, star_manager::ITEMTYPE_COLLECTION);
            $out .= self::action_form($pageurl, 'star', [], \html_writer::tag(
                'button',
                get_string($isstarred ? 'unstar' : 'star', 'local_oerexchange')
                    . ' (' . get_string('starcount', 'local_oerexchange', $starcount) . ')',
                [
                    'type' => 'submit',
                    'class' => 'btn btn-sm me-2 ' . ($isstarred ? 'btn-secondary' : 'btn-outline-secondary'),
                    'aria-pressed' => $isstarred ? 'true' : 'false',
                    'data-region' => 'oerexchange-collectionstar',
                    'data-starred' => $isstarred ? '1' : '0',
                ]
            ), 'd-inline-block');
            // Progressive enhancement: the form above works without it.
            $PAGE->requires->js_call_amd('local_oerexchange/collectionstar', 'init', [(int) $collection->id]);
        }

        // The share_targets class wants PLAIN TEXT: the title ends up in a tweet body, a
        // mailto: subject, an sms: body and navigator.share()'s title, none of
        // which render HTML entities. Filter first (a multilang title collapses
        // to the viewer's language rather than being shared as literal markup),
        // then flatten the filtered HTML back to text so format_string()'s
        // '&amp;' never reaches a share sheet as visible characters. Same
        // ordering, same reason, as resource.php's own share call.
        $sharetitle = content_to_text(
            format_string($collection->title, true, ['context' => \context_system::instance()]),
            FORMAT_HTML
        );
        $out .= share_targets::render(
            $pageurl->out(false),
            $sharetitle,
            get_string('sharecollection', 'local_oerexchange')
        );

        $out .= \html_writer::end_tag('div');

        return $out;
    }

    /**
     * The ordered list of items.
     *
     * @param \stdClass $collection
     * @param \moodle_url $pageurl
     * @param \stdClass[] $items from collection_manager::get_items()
     * @param int $userid
     * @param bool $cancurate
     * @return string HTML
     */
    protected static function render_items(
        \stdClass $collection,
        \moodle_url $pageurl,
        array $items,
        int $userid,
        bool $cancurate
    ): string {
        global $OUTPUT, $PAGE;

        $out = $OUTPUT->heading(get_string('collectionitemsheading', 'local_oerexchange'), 4);

        if (!$items) {
            $out .= \html_writer::tag('p', get_string(
                $cancurate ? 'collectionnoitemsowner' : 'collectionnoitems',
                'local_oerexchange'
            ));

            return $out;
        }

        $unavailable = 0;
        foreach ($items as $item) {
            if (empty($item->available)) {
                $unavailable++;
            }
        }
        if ($unavailable > 0 && $cancurate) {
            $out .= $OUTPUT->notification(
                get_string('collectionitemunavailablecount', 'local_oerexchange', $unavailable),
                'info'
            );
        }

        // One query for every thumbnail on the page rather than one per row —
        // the same N+1 cover_image::urls_for() exists to avoid.
        $resourceids = [];
        foreach ($items as $item) {
            $resourceids[] = (int) $item->resourceid;
        }
        $coverurls = cover_image::urls_for($resourceids);

        // Who added each item, for the open-curation attribution line. One
        // query for the whole page; 0 is this plugin's "no attributable user"
        // sentinel (a GDPR erasure), so it is never looked up.
        $adders = self::adder_names($items);

        $itemlist = array_values($items);
        $out .= \html_writer::start_tag('ol', [
            'class' => 'oerexchange-collection-items list-unstyled',
            'data-region' => 'oerexchange-collection-items',
        ]);
        foreach ($itemlist as $position => $item) {
            // A curator is usually not the resource's author. An unavailable
            // resource (hidden, pending, taken down) is withheld from them on
            // its own page, so it must not be named here either: they get the
            // same anonymous "no longer available" row as a resource that has
            // gone entirely, plus their own note and the remove button.
            if (
                empty($item->available) && $item->resource
                    && !resource_manager::user_can_view_resource($item->resource, $userid)
            ) {
                $item = clone $item;
                $item->resource = null;
            }
            $out .= self::render_item(
                $collection,
                $pageurl,
                $item,
                $userid,
                $cancurate,
                $coverurls[(int) $item->resourceid] ?? null,
                $adders[(int) $item->addedby] ?? null,
                $position === 0,
                $position === count($itemlist) - 1
            );
        }
        $out .= \html_writer::end_tag('ol');

        if ($cancurate) {
            // The optimistic-reorder/remove enhancement. Everything above
            // works without it: each control is a real submit button in its
            // own POST form.
            $PAGE->requires->js_call_amd('local_oerexchange/collectionitems', 'init');
        }

        return $out;
    }

    /**
     * One item row.
     *
     * @param \stdClass $collection
     * @param \moodle_url $pageurl
     * @param \stdClass $item an item row from get_items(), with ->resource and ->available
     * @param int $userid
     * @param bool $cancurate
     * @param \moodle_url|null $coverurl
     * @param string|null $addername already-escaped name of whoever added it, or null
     * @param bool $isfirst
     * @param bool $islast
     * @return string HTML
     */
    protected static function render_item(
        \stdClass $collection,
        \moodle_url $pageurl,
        \stdClass $item,
        int $userid,
        bool $cancurate,
        ?\moodle_url $coverurl,
        ?string $addername,
        bool $isfirst,
        bool $islast
    ): string {
        $resource = $item->resource;
        $available = !empty($item->available);

        $out = \html_writer::start_tag('li', [
            'class' => 'd-flex gap-3 mb-3 pb-3 border-bottom' . ($available ? '' : ' opacity-50'),
            'data-region' => 'oerexchange-collection-item',
            'data-itemid' => (int) $item->id,
        ]);

        if ($resource) {
            $out .= cover_image::listitem($coverurl);
        }

        $out .= \html_writer::start_tag('div', ['class' => 'flex-grow-1']);

        if ($resource) {
            $title = format_string($resource->title, true, ['context' => \context_system::instance()]);
            $resourceurl = new \moodle_url('/local/oerexchange/resource.php', ['id' => $resource->id]);
            $out .= \html_writer::tag(
                'h5',
                // An unavailable resource's page 404s for anyone but its own
                // author, so its title is text rather than a dead link.
                $available ? \html_writer::link($resourceurl, $title) : $title,
                ['class' => 'mb-1 h6']
            );
            $out .= \html_writer::tag(
                'div',
                resource_type::label($resource) . ' · ' . licence_display::html($resource->licenseshortname),
                ['class' => 'small text-muted mb-1']
            );
        } else {
            // The resources row itself has gone, which get_items() reports as
            // ->resource === null. Nothing to name, so the row says only that
            // something was here.
            $out .= \html_writer::tag(
                'div',
                get_string('collectionitemunavailable', 'local_oerexchange'),
                ['class' => 'small text-muted mb-1']
            );
        }

        if (!$available && $resource) {
            $out .= \html_writer::tag(
                'div',
                get_string('collectionitemunavailable', 'local_oerexchange'),
                ['class' => 'small text-warning mb-1']
            );
        }

        if ((string) ($item->note ?? '') !== '') {
            // FORMAT_MOODLE, not FORMAT_HTML: the note comes from a plain
            // <textarea> read as PARAM_TEXT, so it is raw text whose newlines
            // are significant. 'para' => false because this sits inside a <p>,
            // where format_text()'s default text_to_html <div> wrapper is
            // invalid nesting browsers repair by closing the <p> early — the
            // same pair of decisions, for the same reasons, as the profile bio
            // in profile_controller::view().
            $out .= \html_writer::tag(
                'p',
                format_text($item->note, FORMAT_MOODLE, [
                    'context' => \context_system::instance(),
                    'para' => false,
                ]),
                ['class' => 'oerexchange-collection-note mb-1 fst-italic']
            );
        }

        // Attribution only where it means something: under open curation
        // anybody may have put this here, so saying who did is part of reading
        // the list. On a closed collection every item is the owner's or a named
        // curator's choice and the byline above already says so.
        if (!empty($collection->opencuration) && $addername !== null) {
            $out .= \html_writer::tag(
                'div',
                get_string('collectionaddedby', 'local_oerexchange', $addername),
                ['class' => 'small text-muted mb-1']
            );
        }

        $out .= self::render_item_controls($collection, $pageurl, $item, $userid, $cancurate, $isfirst, $islast);

        $out .= \html_writer::end_tag('div');
        $out .= \html_writer::end_tag('li');

        return $out;
    }

    /**
     * Reorder, remove and note controls for one item.
     *
     * Each is its own <form method="post">, so each works with scripting off
     * and none of them can be triggered by a crafted GET.
     *
     * @param \stdClass $collection
     * @param \moodle_url $pageurl
     * @param \stdClass $item
     * @param int $userid
     * @param bool $cancurate
     * @param bool $isfirst
     * @param bool $islast
     * @return string HTML
     */
    protected static function render_item_controls(
        \stdClass $collection,
        \moodle_url $pageurl,
        \stdClass $item,
        int $userid,
        bool $cancurate,
        bool $isfirst,
        bool $islast
    ): string {
        $canremove = collection_manager::user_can_remove_item($collection, $item, $userid);
        if (!$cancurate && !$canremove) {
            return '';
        }

        // The plain-text title the move buttons' aria-labels need. Filtered so
        // a multilang title is announced in one language, then flattened back
        // to text: html_writer escapes the attribute itself, so an entity left
        // in here would be announced as "ampersand a m p".
        $label = $item->resource
            ? content_to_text(
                format_string($item->resource->title, true, ['context' => \context_system::instance()]),
                FORMAT_HTML
            )
            : get_string('collectionitemunavailable', 'local_oerexchange');

        $out = \html_writer::start_tag('div', ['class' => 'd-flex flex-wrap gap-2 align-items-start mt-1']);

        if ($cancurate) {
            $out .= self::action_form(
                $pageurl,
                'moveitem',
                ['itemid' => (int) $item->id, 'direction' => -1],
                \html_writer::empty_tag('input', array_merge([
                    'type' => 'submit',
                    'class' => 'btn btn-outline-secondary btn-sm',
                    'value' => get_string('collectionitemmoveup', 'local_oerexchange'),
                    'aria-label' => get_string('collectionitemmoveuparia', 'local_oerexchange', $label),
                    'data-action' => 'oerexchange-collection-moveup',
                ], $isfirst ? ['disabled' => 'disabled'] : []))
            );
            $out .= self::action_form(
                $pageurl,
                'moveitem',
                ['itemid' => (int) $item->id, 'direction' => 1],
                \html_writer::empty_tag('input', array_merge([
                    'type' => 'submit',
                    'class' => 'btn btn-outline-secondary btn-sm',
                    'value' => get_string('collectionitemmovedown', 'local_oerexchange'),
                    'aria-label' => get_string('collectionitemmovedownaria', 'local_oerexchange', $label),
                    'data-action' => 'oerexchange-collection-movedown',
                ], $islast ? ['disabled' => 'disabled'] : []))
            );
        }

        if ($canremove) {
            // Keyed by resourceid, not itemid, because that is what
            // collection_manager::remove_item() takes: it deletes by
            // (collectionid, resourceid), so a posted id can only ever affect
            // the collection the controller already authorised against.
            $out .= self::action_form(
                $pageurl,
                'removeitem',
                ['resourceid' => (int) $item->resourceid],
                \html_writer::empty_tag('input', [
                    'type' => 'submit',
                    'class' => 'btn btn-outline-danger btn-sm',
                    'value' => get_string('collectionitemremove', 'local_oerexchange'),
                    'aria-label' => get_string('collectionitemremovearia', 'local_oerexchange', $label),
                    'data-action' => 'oerexchange-collection-remove',
                ])
            );
        }

        $out .= \html_writer::end_tag('div');

        if ($cancurate) {
            $out .= self::render_note_form($pageurl, $item);
        }

        return $out;
    }

    /**
     * The collapsible "edit this item's note" form.
     *
     * A <details> rather than a Bootstrap collapse: it opens with no
     * JavaScript at all, which is the standard this whole page is held to.
     * share_targets::render() uses the same element for the same reason.
     *
     * @param \moodle_url $pageurl
     * @param \stdClass $item
     * @return string HTML
     */
    protected static function render_note_form(\moodle_url $pageurl, \stdClass $item): string {
        $hasnote = (string) ($item->note ?? '') !== '';
        $textareaid = \html_writer::random_id('oerexchange-collection-note-');

        $inner = \html_writer::tag(
            'label',
            get_string('collectionitemnote', 'local_oerexchange'),
            ['for' => $textareaid, 'class' => 'form-label small mb-1']
        );
        // Escaped with s(), and no format_string(): an edit field must show the raw stored
        // source — including any multilang markup the curator typed — or saving
        // the form would silently rewrite what they wrote.
        $inner .= \html_writer::tag('textarea', s((string) ($item->note ?? '')), [
            'name' => 'note',
            'id' => $textareaid,
            'rows' => 3,
            'class' => 'form-control form-control-sm mb-2',
        ]);
        $inner .= \html_writer::empty_tag('input', [
            'type' => 'submit',
            'class' => 'btn btn-outline-secondary btn-sm',
            'value' => get_string('savechanges'),
        ]);

        $out = \html_writer::start_tag('details', ['class' => 'mt-2']);
        $out .= \html_writer::tag(
            'summary',
            get_string(
                $hasnote ? 'collectionitemnoteedit' : 'collectionitemnoteadd',
                'local_oerexchange'
            ),
            ['class' => 'small']
        );
        $out .= self::action_form(
            $pageurl,
            'setnote',
            ['itemid' => (int) $item->id],
            $inner,
            'mt-2'
        );
        $out .= \html_writer::end_tag('details');

        return $out;
    }

    /**
     * The "Add resources" search, for anyone who may add items.
     *
     * The query is read from the URL rather than kept in the session, so the
     * result list is a bookmarkable, back-button-safe GET and the add buttons
     * below it are POSTs — the usual split, and the reason a double-tapped
     * browser Back never re-adds anything.
     *
     * @param \moodle_url $pageurl
     * @param \stdClass[] $items the collection's current items, so matches already in it can be marked
     * @return string HTML
     */
    protected static function render_add_items(\moodle_url $pageurl, array $items): string {
        global $OUTPUT;

        // Not declared on the #[route] attribute's queryparams, and
        // deliberately so: core's request_validator::validate_query() strips
        // undeclared query parameters from the PSR-7 request and says in its
        // own comment that "They will still be accessible via optional_param"
        // (lib/classes/router/request_validator.php:120-132), which is the API
        // every other POST/GET handler in this plugin already reads.
        $query = trim(optional_param('addq', '', PARAM_TEXT));

        $out = $OUTPUT->heading(get_string('collectionadditems', 'local_oerexchange'), 4);
        $out .= \html_writer::tag('p', get_string('collectionadditemsintro', 'local_oerexchange'), [
            'class' => 'small text-muted',
        ]);

        $out .= \html_writer::start_tag('form', [
            'method' => 'get',
            'action' => $pageurl,
            'class' => 'd-flex flex-wrap gap-2 align-items-center mb-3',
        ]);
        $out .= \html_writer::tag('label', get_string('collectionadditems', 'local_oerexchange'), [
            'for' => 'oerexchange-collection-addq',
            'class' => 'visually-hidden',
        ]);
        $out .= \html_writer::empty_tag('input', [
            'type' => 'text',
            'name' => 'addq',
            'id' => 'oerexchange-collection-addq',
            // No s(): html_writer escapes attribute values itself.
            'value' => $query,
            'class' => 'form-control form-control-sm',
            'style' => 'max-width:24rem;',
            'placeholder' => get_string('collectionadditemsplaceholder', 'local_oerexchange'),
        ]);
        $out .= \html_writer::empty_tag('input', [
            'type' => 'submit',
            'class' => 'btn btn-outline-primary btn-sm',
            'value' => get_string('searchbutton', 'local_oerexchange'),
        ]);
        $out .= \html_writer::end_tag('form');

        if ($query === '') {
            return $out;
        }

        // One more than the page shows, so "there are further matches" can be
        // said without a second COUNT query.
        $matches = self::search_resources($query, self::ADDPERPAGE + 1);
        if (!$matches) {
            $out .= $OUTPUT->notification(
                get_string('collectionadditemsnoresults', 'local_oerexchange', s($query)),
                'info'
            );

            return $out;
        }

        $truncated = count($matches) > self::ADDPERPAGE;
        $matches = array_slice($matches, 0, self::ADDPERPAGE, true);

        $present = [];
        foreach ($items as $item) {
            $present[(int) $item->resourceid] = true;
        }
        $coverurls = cover_image::urls_for(array_keys($matches));

        $out .= \html_writer::start_tag('ul', ['class' => 'list-unstyled']);
        foreach ($matches as $match) {
            $out .= self::render_add_candidate(
                $pageurl,
                $match,
                $coverurls[(int) $match->id] ?? null,
                isset($present[(int) $match->id])
            );
        }
        $out .= \html_writer::end_tag('ul');

        if ($truncated) {
            $out .= \html_writer::tag(
                'p',
                get_string('collectionadditemsmore', 'local_oerexchange', self::ADDPERPAGE),
                ['class' => 'small text-muted']
            );
        }

        return $out;
    }

    /**
     * One catalogue match in the "Add resources" result list.
     *
     * @param \moodle_url $pageurl
     * @param \stdClass $resource a published resources row
     * @param \moodle_url|null $coverurl
     * @param bool $alreadyin whether this resource is already in the collection
     * @return string HTML
     */
    protected static function render_add_candidate(
        \moodle_url $pageurl,
        \stdClass $resource,
        ?\moodle_url $coverurl,
        bool $alreadyin
    ): string {
        $resourceurl = new \moodle_url('/local/oerexchange/resource.php', ['id' => $resource->id]);
        $title = format_string($resource->title, true, ['context' => \context_system::instance()]);

        $out = \html_writer::start_tag('li', ['class' => 'd-flex gap-3 mb-3 align-items-start']);
        $out .= cover_image::listitem($coverurl);
        $out .= \html_writer::start_tag('div', ['class' => 'flex-grow-1']);
        $out .= \html_writer::tag('div', \html_writer::link($resourceurl, $title), ['class' => 'fw-semibold']);

        // Filter, flatten, shorten, escape exactly once — the ordering
        // catalogue_view and block_oerexchangebrowse both use, which fixed a
        // double-escape from strip_tags() + s() on pre-encoded entities.
        $teaser = s(shorten_text(
            content_to_text(
                format_text(
                    $resource->summary ?? '',
                    (int) ($resource->summaryformat ?? FORMAT_HTML),
                    ['context' => \context_system::instance()]
                ),
                FORMAT_HTML
            ),
            self::TEASER_LENGTH
        ));
        $out .= \html_writer::tag('div', $teaser, ['class' => 'small text-muted']);
        $out .= \html_writer::end_tag('div');

        if ($alreadyin) {
            $out .= \html_writer::tag(
                'span',
                get_string('addtocollectionalreadyin', 'local_oerexchange'),
                ['class' => 'badge bg-secondary align-self-center']
            );
        } else {
            $out .= self::action_form(
                $pageurl,
                'additem',
                ['resourceid' => (int) $resource->id],
                \html_writer::empty_tag('input', [
                    'type' => 'submit',
                    'class' => 'btn btn-outline-primary btn-sm',
                    'value' => get_string('addtocollectionsubmit', 'local_oerexchange'),
                ]),
                'align-self-center'
            );
        }

        $out .= \html_writer::end_tag('li');

        return $out;
    }

    /**
     * The moderator's take down / restore control.
     *
     * Deliberately a moderation action and not a visibility change: it writes
     * the modhidden column through collection_manager::set_modhidden(), which
     * the owner's own publish/unpublish switch cannot lift. Same split, same
     * reason, as resource.php's moderator controls.
     *
     * @param \stdClass $collection
     * @param \moodle_url $pageurl
     * @param bool $ismoderator
     * @return string HTML
     */
    protected static function render_moderation(
        \stdClass $collection,
        \moodle_url $pageurl,
        bool $ismoderator
    ): string {
        if (!$ismoderator) {
            return '';
        }

        $plaintitle = content_to_text(
            format_string($collection->title, true, ['context' => \context_system::instance()]),
            FORMAT_HTML
        );

        if (!empty($collection->modhidden)) {
            return self::action_form(
                $pageurl,
                'modrestore',
                [],
                \html_writer::empty_tag('input', [
                    'type' => 'submit',
                    'class' => 'btn btn-outline-secondary btn-sm',
                    'value' => get_string('collectionmodrestore', 'local_oerexchange'),
                ]),
                'mt-3'
            );
        }

        return self::action_form(
            $pageurl,
            'modhide',
            [],
            \html_writer::empty_tag('input', [
                'type' => 'submit',
                'class' => 'btn btn-outline-danger btn-sm',
                'value' => get_string('collectionmodhide', 'local_oerexchange'),
                // A confirm attribute, not a JS-only guard: without scripting
                // the press simply goes through, which is why the action itself
                // is reversible.
                'data-confirm' => get_string('collectionmodhideconfirm', 'local_oerexchange', $plaintitle),
            ]),
            'mt-3'
        );
    }

    /**
     * The report form.
     *
     * Same four reasons and the same collapsed-by-default shape as
     * resource.php's report form, so a moderator's queue reads the same
     * whichever kind of thing was reported.
     *
     * @param \moodle_url $pageurl
     * @param int $userid
     * @return string HTML
     */
    protected static function render_report(\moodle_url $pageurl, int $userid): string {
        if ($userid <= 0 || isguestuser($userid)) {
            return '';
        }

        $inner = \html_writer::tag(
            'label',
            get_string('reporttype', 'local_oerexchange'),
            ['for' => 'oerexchange-collection-reporttype', 'class' => 'form-label small mb-1']
        );
        $inner .= \html_writer::select([
            'copyright' => get_string('reporttype_copyright', 'local_oerexchange'),
            'quality' => get_string('reporttype_quality', 'local_oerexchange'),
            'spam' => get_string('reporttype_spam', 'local_oerexchange'),
            'other' => get_string('reporttype_other', 'local_oerexchange'),
        ], 'reporttype', '', false, [
            'id' => 'oerexchange-collection-reporttype',
            'class' => 'form-select form-select-sm mb-2',
        ]);
        $inner .= \html_writer::tag(
            'label',
            get_string('reportdetails', 'local_oerexchange'),
            ['for' => 'oerexchange-collection-reportdetails', 'class' => 'form-label small mb-1']
        );
        $inner .= \html_writer::tag('textarea', '', [
            'name' => 'reportdetails',
            'id' => 'oerexchange-collection-reportdetails',
            'rows' => 3,
            'required' => 'required',
            'class' => 'form-control form-control-sm mb-2',
        ]);
        $inner .= \html_writer::empty_tag('input', [
            'type' => 'submit',
            'class' => 'btn btn-outline-danger btn-sm',
            'value' => get_string('reportsubmit', 'local_oerexchange'),
        ]);

        $out = \html_writer::start_tag('details', ['class' => 'mt-4']);
        $out .= \html_writer::tag(
            'summary',
            get_string('collectionreport', 'local_oerexchange'),
            ['class' => 'btn btn-outline-secondary btn-sm']
        );
        $out .= self::action_form($pageurl, 'report', [], $inner, 'mt-2 p-2 border rounded');
        $out .= \html_writer::end_tag('details');

        return $out;
    }

    /**
     * The "Add this resource to a collection" picker shown on a resource page.
     *
     * It lives here rather than inline in resource.php because it is
     * collection markup, and because resource.php hands it to
     * share_targets::render() as that method's extra-content argument: the
     * control belongs inside the existing "Share this resource" disclosure,
     * not in a second panel beside it.
     *
     * Returns '' — so the caller can simply concatenate it, and
     * share_targets::render() behaves exactly as it did before the parameter
     * existed — whenever there is nothing to offer: a logged-out or guest
     * visitor, a user without local/oerexchange:createcollection, or a
     * resource that is not published and therefore cannot be collected at all
     * (collection_manager::add_item() refuses it, so offering the control
     * would only produce an error).
     *
     * It works with scripting off: a <select> and a submit button in a plain
     * POST form, posting to resource.php's own action chain. There is
     * deliberately no AJAX variant — nothing here is worth a web service.
     *
     * Collections that already contain this resource are shown DISABLED and
     * marked, not hidden: "it is already in Week 1" is the answer the person
     * is looking for, and silently omitting Week 1 from a list of eight would
     * read as having lost it.
     *
     * @param \stdClass $resource a resources row
     * @param int $userid the viewer; 0 for a logged-out visitor
     * @param \moodle_url $returnurl the resource page's own URL — the form posts back to it
     * @return string HTML, or '' when there is nothing to offer
     */
    public static function render_add_to_collection(
        \stdClass $resource,
        int $userid,
        \moodle_url $returnurl
    ): string {
        if (!self::enabled()) {
            return '';
        }
        if ($userid <= 0 || isguestuser($userid)) {
            return '';
        }
        if ((string) $resource->status !== resource_manager::STATUS_PUBLISHED) {
            return '';
        }
        if (!has_capability('local/oerexchange:createcollection', \context_system::instance(), $userid)) {
            return '';
        }

        [$groups, $hasany] = self::collection_options($resource, $userid);

        $selectid = \html_writer::random_id('oerexchange-addtocollection-');

        $inner = \html_writer::tag(
            'label',
            get_string('addtocollectionheading', 'local_oerexchange'),
            ['for' => $selectid, 'class' => 'form-label small mb-1 d-block']
        );

        $inner .= \html_writer::start_tag('select', [
            'name' => 'collectionid',
            'id' => $selectid,
            'class' => 'form-select form-select-sm d-inline w-auto me-2',
        ]);
        $inner .= \html_writer::tag('option', get_string('addtocollectionchoose', 'local_oerexchange'), [
            'value' => '',
        ]);
        foreach ($groups as $group) {
            if (!$group['options']) {
                continue;
            }
            $inner .= \html_writer::start_tag('optgroup', ['label' => $group['label']]);
            $inner .= implode('', $group['options']);
            $inner .= \html_writer::end_tag('optgroup');
        }
        // The value 'new' rather than an id: resource.php reads this select with
        // PARAM_ALPHANUM and treats the literal 'new' as "create one and add
        // this resource to it", which it delegates to collection_edit.php.
        $inner .= \html_writer::tag('option', get_string('collectionnew', 'local_oerexchange'), [
            'value' => 'new',
        ]);
        $inner .= \html_writer::end_tag('select');

        // The label is "Add to collection" in full, as the owner specified the
        // button: it sits among share targets, where a bare "Add" would not
        // say what it adds to.
        $inner .= \html_writer::empty_tag('input', [
            'type' => 'submit',
            'class' => 'btn btn-outline-primary btn-sm',
            'value' => get_string('addtocollection', 'local_oerexchange'),
        ]);

        if (!$hasany) {
            $inner .= \html_writer::tag(
                'div',
                get_string('addtocollectioncreatefirst', 'local_oerexchange'),
                ['class' => 'small text-muted mt-1']
            );
        }

        return \html_writer::tag(
            'div',
            self::action_form($returnurl, 'addtocollection', ['id' => (int) $resource->id], $inner, ''),
            ['class' => 'oerexchange-addtocollection']
        );
    }

    /**
     * The grouped <option> markup for the add-to-collection picker.
     *
     * Three groups, in this order: the collections the user owns, the ones
     * they are a named curator of, and the open-curation collections they have
     * starred. The last group is what makes a public, open collection usable
     * by somebody who has no formal role in it — starring it is how they said
     * they wanted to contribute, so it is the list this picker draws from
     * rather than every open collection on the site.
     *
     * @param \stdClass $resource
     * @param int $userid
     * @return array{0: array<int, array{label: string, options: string[]}>, 1: bool}
     *      the groups, and whether the user has any collection at all to add to
     */
    protected static function collection_options(\stdClass $resource, int $userid): array {
        global $DB;

        // Owned and curated, in one call. Tombstones are already excluded;
        // drafts are not, and belong here — a draft is the commonest place to
        // be gathering resources.
        $mine = collection_manager::collections_for_user($userid);

        $owned = [];
        $curated = [];
        foreach ($mine as $collection) {
            if (!collection_manager::user_can_add_items($collection, $userid)) {
                continue;
            }
            if (!empty($collection->isowner)) {
                $owned[(int) $collection->id] = $collection;
            } else {
                $curated[(int) $collection->id] = $collection;
            }
        }

        // The open-curation collections this user starred. starred_collections()
        // returns only publicly listable ones, which is right here: a star is
        // how somebody keeps a stranger's collection to hand, and one that has
        // since been unpublished or taken down is not somewhere to add to.
        $open = [];
        foreach (star_manager::starred_collections($userid) as $collection) {
            $id = (int) $collection->id;
            if (isset($owned[$id]) || isset($curated[$id]) || empty($collection->opencuration)) {
                continue;
            }
            if (!collection_manager::user_can_add_items($collection, $userid)) {
                continue;
            }
            $open[$id] = $collection;
        }

        // Which of them already hold this resource — ONE query for the whole
        // picker rather than one per option.
        $alreadyin = [];
        $candidateids = array_keys($owned + $curated + $open);
        if ($candidateids) {
            [$insql, $inparams] = $DB->get_in_or_equal($candidateids, SQL_PARAMS_NAMED, 'cid');
            $rows = $DB->get_fieldset_select(
                collection_manager::ITEM_TABLE,
                'collectionid',
                "resourceid = :resourceid AND collectionid $insql",
                $inparams + ['resourceid' => (int) $resource->id]
            );
            $alreadyin = array_fill_keys(array_map('intval', $rows), true);
        }

        $groups = [
            ['label' => get_string('collectionsmine', 'local_oerexchange'), 'options' => []],
            ['label' => get_string('collectionscurated', 'local_oerexchange'), 'options' => []],
            ['label' => get_string('addtocollectionopengroup', 'local_oerexchange'), 'options' => []],
        ];
        foreach ([0 => $owned, 1 => $curated, 2 => $open] as $index => $set) {
            foreach ($set as $id => $collection) {
                $groups[$index]['options'][] = self::collection_option(
                    $collection,
                    isset($alreadyin[$id])
                );
            }
        }

        return [$groups, (bool) $candidateids];
    }

    /**
     * One <option> in the add-to-collection picker.
     *
     * An <option> cannot hold markup, so the title is filtered (a multilang
     * title collapses to one language), flattened back to plain text,
     * shortened, and escaped exactly once — the same filter → content_to_text()
     * → shorten_text() ordering this plugin uses for every plain-text teaser.
     * html_writer::tag() does not escape its content, so the s() here is the
     * sink, not a belt-and-braces extra.
     *
     * @param \stdClass $collection
     * @param bool $alreadyin
     * @return string HTML
     */
    protected static function collection_option(\stdClass $collection, bool $alreadyin): string {
        $label = content_to_text(
            format_string($collection->title, true, ['context' => \context_system::instance()]),
            FORMAT_HTML
        );
        $label = shorten_text($label, 70);
        if ($alreadyin) {
            $label .= ' (' . get_string('addtocollectionalreadyin', 'local_oerexchange') . ')';
        }

        $attributes = ['value' => (int) $collection->id];
        if ($alreadyin) {
            // Disabled, not omitted: "it is already in there" is the answer
            // the person wants. The server refuses it anyway —
            // collection_manager::add_item() raises
            // error_collectionitemexists, and its unique index is what
            // actually guarantees it.
            $attributes['disabled'] = 'disabled';
        }

        return \html_writer::tag('option', s($label), $attributes);
    }

    /**
     * A one-action POST form: the action name, its sesskey, any hidden ids, and
     * whatever control the caller wants inside it.
     *
     * Every state change on this page goes through here, so the sesskey can
     * never be forgotten on one of them — which is exactly the kind of
     * per-control omission a dozen hand-written forms produce.
     *
     * @param \moodle_url $pageurl the collection's own URL; the form posts back to it
     * @param string $action the action name the controller dispatches on
     * @param array $hidden extra hidden fields, name => int or string value
     * @param string $inner the control(s) inside the form
     * @param string $class extra classes for the form element
     * @return string HTML
     */
    protected static function action_form(
        \moodle_url $pageurl,
        string $action,
        array $hidden,
        string $inner,
        string $class = 'd-inline-block'
    ): string {
        $out = \html_writer::start_tag('form', [
            'method' => 'post',
            'action' => $pageurl,
            'class' => $class,
        ]);
        $out .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $action]);
        $out .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        foreach ($hidden as $name => $value) {
            $out .= \html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => $name,
                'value' => $value,
            ]);
        }
        $out .= $inner;
        $out .= \html_writer::end_tag('form');

        return $out;
    }

    /**
     * Published resources matching a free-text query.
     *
     * Deliberately a small, local copy of catalogue_view's title/summary/tags
     * LIKE rather than a call into it: catalogue_view::build_where() is private,
     * its public surface is a whole rendered catalogue page (search form, card
     * grid, paging bar) rather than a row set, and it is owned by other work in
     * flight. What is shared is the SHAPE — $DB->sql_like() with
     * sql_like_escape(), case-insensitive, status = published — not a copy of
     * the filter UI.
     *
     * @param string $query as typed
     * @param int $limit maximum rows
     * @return \stdClass[] keyed by resource id, most recently shared first
     */
    protected static function search_resources(string $query, int $limit): array {
        global $DB;

        if ($query === '') {
            return [];
        }

        $like = $DB->sql_like('title', ':q1', false)
            . ' OR ' . $DB->sql_like('summary', ':q2', false)
            . ' OR ' . $DB->sql_like('tags', ':q3', false);
        $needle = '%' . $DB->sql_like_escape($query) . '%';

        return $DB->get_records_select(
            'local_oerexchange_resources',
            "status = :status AND ({$like})",
            [
                'status' => resource_manager::STATUS_PUBLISHED,
                'q1' => $needle,
                'q2' => $needle,
                'q3' => $needle,
            ],
            'timeshared DESC',
            '*',
            0,
            $limit
        );
    }

    /**
     * The already-escaped display names of everyone who added one of these
     * items, in ONE query.
     *
     * @param \stdClass[] $items item rows carrying ->addedby
     * @return array<int, string> keyed by userid; addedby 0 and deleted users are absent
     */
    protected static function adder_names(array $items): array {
        global $DB;

        $userids = [];
        foreach ($items as $item) {
            $addedby = (int) $item->addedby;
            // 0 is this plugin's "no attributable user" sentinel after a GDPR
            // erasure, not a real id, so it is never looked up.
            if ($addedby > 0) {
                $userids[$addedby] = $addedby;
            }
        }
        if (!$userids) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $users = $DB->get_records_select('user', "id $insql AND deleted = 0", $inparams);

        $names = [];
        foreach ($users as $user) {
            $names[(int) $user->id] = s(fullname($user));
        }

        return $names;
    }
}
