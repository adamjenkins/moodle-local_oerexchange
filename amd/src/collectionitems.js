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
 * The reorder and remove controls on a collection page.
 *
 * Progressive enhancement, and nothing more. Every control is rendered
 * server-side as a real submit button inside its own POST form
 * (collection_view::render_item_controls()), so with this module absent — or
 * with scripting off entirely — pressing one posts, the server acts, and the
 * page comes back reordered. There is deliberately no web service behind any
 * of it: a curated list is a handful of rows a human is rearranging, not
 * something worth an AJAX endpoint and a second copy of the authorisation
 * rules.
 *
 * What this module adds is the half-second of honesty in between. A move or a
 * remove is applied to the DOM the moment the button is pressed, so the row
 * visibly goes where it was sent while the POST is still in flight, instead of
 * the page sitting unchanged and then jumping. The submission itself is never
 * intercepted: there is no preventDefault() anywhere below, so if any of this
 * throws, the form has already been handed to the browser and the server-side
 * path still completes.
 *
 * @module     local_oerexchange/collectionitems
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @type {string} the <ol> holding the items. */
const LIST = '[data-region="oerexchange-collection-items"]';

/** @type {string} one item row. */
const ROW = '[data-region="oerexchange-collection-item"]';

/**
 * Repaint the first/last disabled state of every move button in the list.
 *
 * Without this an optimistic move leaves the arrows describing the order the
 * page was drawn with: the row that just arrived at the top would still offer
 * "Move up", and the row it displaced would not.
 *
 * @param {HTMLElement} list
 */
const refreshArrows = (list) => {
    const rows = Array.from(list.querySelectorAll(ROW));

    rows.forEach((row, index) => {
        const up = row.querySelector('[data-action="oerexchange-collection-moveup"]');
        const down = row.querySelector('[data-action="oerexchange-collection-movedown"]');

        if (up) {
            up.disabled = index === 0;
        }
        if (down) {
            down.disabled = index === rows.length - 1;
        }
    });
};

/**
 * Apply one action to the DOM straight away.
 *
 * @param {HTMLElement} list
 * @param {HTMLElement} row
 * @param {string} action the pressed control's data-action value
 */
const applyOptimistically = (list, row, action) => {
    if (action === 'oerexchange-collection-remove') {
        // Hidden rather than removed: the POST is still in flight, and if it
        // fails the reloaded page will simply draw the row again.
        row.hidden = true;
        refreshArrows(list);

        return;
    }

    if (action === 'oerexchange-collection-moveup') {
        const previous = row.previousElementSibling;
        if (previous) {
            list.insertBefore(row, previous);
        }
    } else if (action === 'oerexchange-collection-movedown') {
        const next = row.nextElementSibling;
        if (next) {
            list.insertBefore(next, row);
        }
    }

    refreshArrows(list);
};

/**
 * Wire up the item list, if this page has one.
 */
export const init = () => {
    const list = document.querySelector(LIST);
    if (!list) {
        return;
    }

    // One listener on the list rather than one per button: submit bubbles, and
    // the rows are re-ordered under it, so a per-button listener would have to
    // be re-attached after every move.
    list.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const button = form.querySelector('[data-action]');
        const row = form.closest(ROW);
        if (!button || !row) {
            // The note form, which has no data-action control: it posts a
            // textarea and there is nothing useful to anticipate.
            return;
        }

        applyOptimistically(list, row, button.dataset.action);

        // Disabled on the next tick, never synchronously: the submission has
        // already been started by the time this listener runs, but disabling a
        // form control inside the submit handler is the classic way to lose
        // the submit in older browsers. A tick later it is safely a guard
        // against the double-click that two POSTs would otherwise make of one
        // reorder. Nothing re-enables them — the page is about to be replaced
        // by the server's response.
        window.setTimeout(() => {
            row.querySelectorAll('input[type="submit"]').forEach((control) => {
                control.disabled = true;
            });
        }, 0);
    });
};
