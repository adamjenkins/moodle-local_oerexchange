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
 * The sort and view controls on a contributor listing.
 *
 * Progressive enhancement: the controls are rendered server-side as a real GET
 * form that reloads the page and works with JavaScript off. This module hides
 * the submit button, takes their change events, and swaps the list in place.
 *
 * @module     local_oerexchange/contributorsort
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {call as fetchMany} from 'core/ajax';
import Notification from 'core/notification';

/**
 * Wire up the sort control for one listing region.
 *
 * @param {string} regionid id of the element whose contents are replaced
 * @param {number} limit how many cards the region shows
 * @param {string} layout the view to open with: 'cards' or 'list'
 */
export const init = (regionid, limit, layout) => {
    const region = document.getElementById(regionid);
    if (!region) {
        return;
    }

    const select = document.querySelector(
        `[data-region="oerexchange-contributor-sort"][data-target="${regionid}"]`
    );
    if (!select) {
        return;
    }

    const view = document.querySelector(
        `[data-region="oerexchange-contributor-view"][data-target="${regionid}"]`
    );

    const form = select.closest('form');

    // Only now is the plain form redundant: if anything above returned early,
    // the server-rendered submit button is still the working control.
    if (form) {
        const submit = form.querySelector('[data-region="oerexchange-contributor-sortgo"]');
        if (submit) {
            submit.classList.add('d-none');
        }
        form.addEventListener('submit', (e) => e.preventDefault());
    }

    /**
     * Re-fetch the listing for whatever the two controls currently say.
     *
     * @return {void}
     */
    const refresh = () => {
        select.disabled = true;
        if (view) {
            view.disabled = true;
        }

        // Promise.resolve() around the core/ajax result is load-bearing, not
        // decoration: core/ajax returns a jQuery Deferred promise, and jQuery
        // 3.7.1 promises have then/catch/always but NO finally. Chaining
        // .finally() straight onto it threw a TypeError the moment the chain
        // was built — after the line above had disabled the control — so the
        // list re-sorted exactly once and the dropdown then stayed disabled
        // forever. Promise.resolve() adopts the thenable into a native
        // promise, which has .finally, and keeps working if core/ajax ever
        // returns native promises itself.
        Promise.resolve(fetchMany([{
            methodname: 'local_oerexchange_get_contributors',
            args: {
                sort: select.value,
                limit: limit,
                offset: 0,
                layout: view ? view.value : layout,
            },
        }])[0])
            .then((result) => {
                region.innerHTML = result.html;
                return result;
            })
            .catch(Notification.exception)
            .finally(() => {
                select.disabled = false;
                if (view) {
                    view.disabled = false;
                }
            });
    };

    select.addEventListener('change', refresh);
    if (view) {
        view.addEventListener('change', refresh);
    }
};
