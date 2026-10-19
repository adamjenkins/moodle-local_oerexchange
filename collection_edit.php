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

/**
 * Create a collection, or edit one you own: title, description, address,
 * visibility, contributions, cover image, curators, deletion.
 *
 * Owner-only, and deliberately not capability-overridable: every gate on this
 * page is collection_manager::user_can_manage(), which is owner-only by design
 * (a moderator's power over a collection is a reversible takedown on the
 * collection page, not the ability to rename or delete a stranger's reading
 * list). A moderator who is not the owner is refused here exactly like anyone
 * else.
 *
 * Supports ?addresourceid=N: create the collection and put that resource in it
 * in one step, which is what the resource page's "New collection…" option
 * posts here for. The id is re-authorised when it is used — being handed it in
 * a URL is not permission to add anything.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
// Filelib.php's free functions (file_get_submitted_draft_itemid(),
// file_prepare_draft_area()) are not in setup.php's default require chain —
// see resource.php's long note on why this is required explicitly rather than
// relied upon as a side effect of some other file having loaded it.
require_once($CFG->libdir . '/filelib.php');

use local_oerexchange\form\edit_collection_form;
use local_oerexchange\local\coauthor_manager;
use local_oerexchange\local\collection_cover;
use local_oerexchange\local\collection_curator_manager;
use local_oerexchange\local\collection_manager;
use local_oerexchange\local\collection_view;
use local_oerexchange\route\controller\collection_controller;

$id = optional_param('id', 0, PARAM_INT);
$addresourceid = optional_param('addresourceid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

require_login();

$context = context_system::instance();
$collection = null;

// Collections turned off site-wide turns this page off with them — the admin
// setting's description says it hides the Collections pages, and a page that
// still saved would make a lie of that. Unset reads as enabled; see
// collection_view::enabled().
if (!collection_view::enabled()) {
    throw new moodle_exception('error_collectionnotfound', 'local_oerexchange');
}

if ($id) {
    $collection = collection_manager::get($id);
    if (!$collection) {
        throw new moodle_exception('error_collectionnotfound', 'local_oerexchange');
    }
    // A tombstoned collection has nothing left to edit, and re-populating its
    // fields would partially resurrect it — the same reasoning, and the same
    // ordering, as edit_resource.php's 'deleted' guard.
    if (collection_manager::is_tombstone($collection)) {
        throw new moodle_exception('error_collectiondeleted', 'local_oerexchange');
    }
    // The one ownership gate. Never write a second, differently-guarded copy
    // of it: collection_manager is the single authorisation gate for all three
    // curation tiers.
    if (!collection_manager::user_can_manage($collection, (int) $USER->id)) {
        throw new moodle_exception('error_collectioncannotmanage', 'local_oerexchange');
    }
} else {
    // Creating is the only act on this page that needs a capability; editing
    // your own collection must keep working even if an admin later revokes
    // the right to create new ones.
    require_capability('local/oerexchange:createcollection', $context);
}

$urlparams = [];
if ($id) {
    $urlparams['id'] = $id;
}
if ($addresourceid) {
    $urlparams['addresourceid'] = $addresourceid;
}

$PAGE->set_url('/local/oerexchange/collection_edit.php', $urlparams);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$heading = get_string($collection ? 'collectionedit' : 'collectioncreate', 'local_oerexchange');
$PAGE->set_title($heading);
$PAGE->set_heading($heading);

$thispageurl = new moodle_url('/local/oerexchange/collection_edit.php', $urlparams);
$catalogueurl = new moodle_url('/local/oerexchange/index.php');
$collectionurl = $collection ? collection_controller::collection_url($collection->slug) : $catalogueurl;

// Curator and deletion actions. All of them require an existing collection the
// current user owns, which the gate above has already established — so these
// branches add a sesskey check and nothing else to re-derive.

if ($collection && $action === 'addcurator') {
    require_sesskey();

    // PARAM_RAW_TRIMMED, not PARAM_TEXT: this is matched against
    // user.username / user.email in a parameterized query and is never echoed
    // back, so stripping characters out of it would only break legitimate
    // addresses. The success notice names the resolved user, not the string
    // that was typed. Same decision, same wording, as resource.php's
    // addcoauthor branch.
    $identifier = required_param('curatoridentifier', PARAM_RAW_TRIMMED);

    try {
        // The coauthor_manager::find_user() method is the plugin's one username-or-email
        // resolver (case-insensitive username, then a unique local email),
        // reused rather than copied. Its two failure codes are co-author
        // worded, so they are translated to the curator wording here — the
        // only thing this page does differently from resource.php's co-author
        // add, which calls coauthor_manager::add() and gets both at once.
        try {
            $user = coauthor_manager::find_user($identifier);
        } catch (moodle_exception $e) {
            throw new moodle_exception(
                $e->errorcode === 'error_coauthorambiguous'
                    ? 'error_curatorambiguous'
                    : 'error_curatornotfound',
                'local_oerexchange'
            );
        }

        // The add() call refuses the guest account, the owner and an existing curator,
        // and raises error_curatorexists on the unique-index race.
        collection_curator_manager::add((int) $collection->id, (int) $user->id, (int) $USER->id);
        collection_curator_manager::notify_added($collection, $user, $USER);
        \core\notification::success(get_string('collectioncuratoradded', 'local_oerexchange', fullname($user)));
    } catch (moodle_exception $e) {
        // A typo in a username is an ordinary mistake, not an exceptional one:
        // back to the form with the reason, not a full-page error.
        \core\notification::error($e->getMessage());
    }

    redirect($thispageurl);
}

if ($collection && $action === 'removecurator') {
    // A sesskey-carrying link rather than a form, copying resource.php's
    // removecoauthor control exactly. require_sesskey() (not
    // confirm_sesskey()) so a missing token and a wrong one both stop here.
    require_sesskey();

    $curatorid = required_param('curatorid', PARAM_INT);
    if (collection_curator_manager::is_curator((int) $collection->id, $curatorid)) {
        collection_curator_manager::remove((int) $collection->id, $curatorid);
        \core\notification::success(get_string('collectioncuratorremoved', 'local_oerexchange'));
    } else {
        // Already gone — a double-click, or the same person removed twice.
        // Nothing is wrong, so this is not an error.
        \core\notification::warning(get_string('collectioncuratorremoved', 'local_oerexchange'));
    }

    redirect($thispageurl);
}

if ($collection && $action === 'deleteconfirm') {
    require_sesskey();

    collection_manager::delete((int) $collection->id);

    // Confirm the outcome before claiming it happened, rather than reporting
    // success off the back of a call that returns nothing — the same read-back
    // resource.php's deleteconfirm branch does.
    $after = collection_manager::get((int) $collection->id);
    if (!$after || !collection_manager::is_tombstone($after)) {
        throw new moodle_exception('error_collectiondeletefailed', 'local_oerexchange');
    }

    \core\notification::success(get_string('collectiondeleted', 'local_oerexchange'));
    redirect($catalogueurl);
}

// The form.

// Prefill the cover picker with the current image, so saving the form without
// touching it leaves the existing cover alone rather than clearing it. A new
// collection has no itemid yet, which file_prepare_draft_area() accepts — it
// simply prepares an empty draft area.
$draftitemid = file_get_submitted_draft_itemid('cover');
file_prepare_draft_area(
    $draftitemid,
    $context->id,
    'local_oerexchange',
    collection_cover::FILEAREA,
    $collection ? (int) $collection->id : null,
    ['subdirs' => 0, 'maxfiles' => 1, 'maxbytes' => collection_cover::MAX_BYTES]
);

$form = new edit_collection_form($thispageurl, ['collectionid' => $id]);

$defaults = [
    'id' => $id,
    'addresourceid' => $addresourceid,
    'cover' => $draftitemid,
];
if ($collection) {
    $defaults['title'] = $collection->title;
    $defaults['slug'] = $collection->slug;
    $defaults['summary_editor'] = [
        'text' => $collection->summary ?? '',
        'format' => (int) ($collection->summaryformat ?? FORMAT_HTML),
    ];
    $defaults['visibility'] = $collection->visibility;
    $defaults['opencuration'] = (int) $collection->opencuration;
}
$form->set_data($defaults);

if ($form->is_cancelled()) {
    redirect($collectionurl);
}

if ($data = $form->get_data()) {
    // Normalise the description to HTML before storing it, for the same reason
    // edit_resource.php does: the column has readers this page cannot reach
    // (the collection browser's cards, the blended catalogue, the landing
    // page's featured row), and an author whose site default editor is the
    // plain text area could otherwise choose Markdown and store markup that
    // renders correctly here and wrongly in three other places. The format
    // column is still stored and honoured on output, so a later round can
    // carry real multi-format support without a migration.
    $summary = (string) $data->summary_editor['text'];
    $submittedformat = (int) $data->summary_editor['format'];
    if ($submittedformat !== FORMAT_HTML) {
        $summary = format_text($summary, $submittedformat, [
            'context' => $context,
            'filter' => false,
        ]);
    }

    try {
        if (!$collection) {
            // The create() call mints the slug from the title and retries the
            // generate-then-insert race itself; it never reports
            // error_slugtaken, because the person did not choose it.
            $collection = collection_manager::create(
                (int) $USER->id,
                (string) $data->title,
                $summary,
                FORMAT_HTML
            );
            $created = true;
        } else {
            collection_manager::update_metadata((int) $collection->id, [
                'title' => (string) $data->title,
                'summary' => $summary,
                'summaryformat' => FORMAT_HTML,
                'slug' => (string) $data->slug,
            ]);
            $created = false;
        }

        // Visibility and the contributions switch are deliberately not part of
        // update_metadata(): each has its own side effects (the first
        // transition to public stamps timepublished; closing contributions
        // must not remove what open contributors already added), which is why
        // collection_manager keeps them as separate calls.
        collection_manager::set_visibility((int) $collection->id, (string) $data->visibility);
        collection_manager::set_open_curation((int) $collection->id, (bool) $data->opencuration);

        // Validates the mimetype and sniffs the actual bytes; returns false,
        // and changes nothing, when the picker was left as it was. It also
        // invalidates any generated mosaic, so an uploaded cover takes effect
        // immediately.
        collection_cover::save_from_draft((int) $collection->id, (int) $data->cover);

        // Re-read: set_visibility() and set_open_curation() have both moved
        // the row, and the add below is authorised against its CURRENT state,
        // never the one the form was drawn from.
        $collection = collection_manager::get((int) $collection->id);

        $wantedresourceid = (int) $data->addresourceid;
        if ($collection && $wantedresourceid) {
            // Re-derived, not trusted: arriving with ?addresourceid=N is a
            // request, not an authorisation. add_item() then validates state
            // too — the resource has to be one the public can actually see.
            if (collection_manager::user_can_add_items($collection, (int) $USER->id)) {
                try {
                    collection_manager::add_item(
                        (int) $collection->id,
                        $wantedresourceid,
                        (int) $USER->id
                    );
                } catch (moodle_exception $e) {
                    // The collection was still created or saved, which is the
                    // greater part of what was asked for. Say what did not
                    // happen rather than undoing what did.
                    \core\notification::warning($e->getMessage());
                }
            }
        }

        \core\notification::success(get_string(
            $created ? 'collectioncreated' : 'collectionsaved',
            'local_oerexchange'
        ));
        redirect(collection_controller::collection_url($collection->slug));
    } catch (moodle_exception $e) {
        // The slug's unique index is the real arbiter of an address collision,
        // and the form's own check is only the courtesy that gives the common
        // case an inline error. Losing that race here must not be a raw 500:
        // report it and fall through to redisplay the form, which formslib
        // repopulates from what was just submitted.
        \core\notification::error($e->getMessage());
    }
}

echo $OUTPUT->header();

if ($collection && $action === 'delete') {
    // Deleting is irreversible (the tombstone stays, but the order, the notes
    // and the cover do not), so it gets a confirmation step of its own rather
    // than a one-click button. The sesskey lives on the confirm action, not on
    // reaching this page.
    echo $OUTPUT->confirm(
        get_string('collectiondeleteconfirm', 'local_oerexchange', content_to_text(
            format_string($collection->title, true, ['context' => $context]),
            FORMAT_HTML
        )),
        new moodle_url($thispageurl, ['action' => 'deleteconfirm', 'sesskey' => sesskey()]),
        $collectionurl
    );
    echo $OUTPUT->footer();
    exit;
}

$form->display();

if ($collection) {
    // Curators. Owner-only, which the gate at the top of this page has already
    // established — a named curator never reaches this file.
    echo $OUTPUT->heading(get_string('collectioncurators', 'local_oerexchange'), 4, 'mt-4');
    echo html_writer::tag(
        'p',
        get_string('collectioncurators_help', 'local_oerexchange'),
        ['class' => 'small text-muted']
    );

    $curators = collection_curator_manager::get_curators((int) $collection->id);
    if ($curators) {
        echo html_writer::start_tag('ul', ['class' => 'list-unstyled mb-2']);
        foreach ($curators as $curator) {
            $removeurl = new moodle_url($thispageurl, [
                'action' => 'removecurator',
                'curatorid' => $curator->id,
                'sesskey' => sesskey(),
            ]);
            echo html_writer::tag(
                'li',
                s(fullname($curator)) . ' '
                    . html_writer::tag('span', s($curator->email), ['class' => 'small text-muted'])
                    . ' '
                    . html_writer::link($removeurl, get_string('collectioncuratorremove', 'local_oerexchange'), [
                        'class' => 'btn btn-outline-danger btn-sm ms-2',
                        // Shown by the browser's own confirm on a plain click
                        // where scripting is on; the action is cheap to undo
                        // (re-add the person) where it is not.
                        'title' => get_string(
                            'collectioncuratorremoveconfirm',
                            'local_oerexchange',
                            fullname($curator)
                        ),
                    ]),
                ['class' => 'mb-1']
            );
        }
        echo html_writer::end_tag('ul');
    } else {
        echo html_writer::tag('p', get_string('collectioncuratornone', 'local_oerexchange'), ['class' => 'mb-2']);
    }

    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $thispageurl,
        'class' => 'd-flex gap-2 align-items-start flex-wrap mb-4',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'addcurator']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::tag('label', get_string('collectioncuratoridentifier', 'local_oerexchange'), [
        'class' => 'visually-hidden',
        'for' => 'oerexchange-curatoridentifier',
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'class' => 'form-control form-control-sm',
        'style' => 'max-width:22rem;',
        'id' => 'oerexchange-curatoridentifier',
        'name' => 'curatoridentifier',
        'maxlength' => 255,
        'required' => 'required',
        'placeholder' => get_string('collectioncuratoridentifier', 'local_oerexchange'),
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-outline-secondary btn-sm',
        'value' => get_string('collectioncuratoradd', 'local_oerexchange'),
    ]);
    echo html_writer::end_tag('form');

    // Delete. Last on the page, and a link to a confirmation rather than a
    // control that acts.
    echo html_writer::link(
        new moodle_url($thispageurl, ['action' => 'delete']),
        get_string('collectiondelete', 'local_oerexchange'),
        ['class' => 'btn btn-outline-danger btn-sm']
    );
}

echo $OUTPUT->footer();
