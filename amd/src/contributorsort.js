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
 * The sort control on a contributor listing.
 *
 * Progressive enhancement: the control is rendered server-side as a real GET
 * form that reloads the page and works with JavaScript off. This module hides
 * the submit button, takes the change event, and swaps the list in place.
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
 * @param {string} layout 'list' for a block region, 'grid' for a page
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

    select.addEventListener('change', () => {
        select.disabled = true;

        fetchMany([{
            methodname: 'local_oerexchange_get_contributors',
            args: {sort: select.value, limit: limit, offset: 0, layout: layout},
        }])[0]
            .then((result) => {
                region.innerHTML = result.html;
                return result;
            })
            .catch(Notification.exception)
            .finally(() => {
                select.disabled = false;
            });
    });
};
