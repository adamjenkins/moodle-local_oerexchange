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
 * The star button on a resource page.
 *
 * Progressive enhancement: the button is rendered server-side as a real link
 * to resource.php?action=star, which works with JavaScript off. This module
 * takes the click, does it over AJAX instead, and updates the label in place
 * so starring does not cost a page reload.
 *
 * @module     local_oerexchange/star
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {call as fetchMany} from 'core/ajax';
import {get_string as getString} from 'core/str';
import Notification from 'core/notification';

/**
 * Repaint the button for a given state.
 *
 * @param {HTMLElement} button
 * @param {boolean} starred
 * @param {number} count
 * @return {Promise<void>}
 */
const render = async(button, starred, count) => {
    const label = await getString(starred ? 'unstar' : 'star', 'local_oerexchange');
    const countlabel = await getString('starcount', 'local_oerexchange', count);

    button.dataset.starred = starred ? '1' : '0';
    button.setAttribute('aria-pressed', starred ? 'true' : 'false');
    button.textContent = `${label} (${countlabel})`;
    button.classList.toggle('btn-secondary', starred);
    button.classList.toggle('btn-outline-secondary', !starred);
};

/**
 * Wire up the star button, if this page has one.
 *
 * @param {number} resourceid
 */
export const init = (resourceid) => {
    const button = document.querySelector('[data-region="oerexchange-star"]');
    if (!button) {
        return;
    }

    button.addEventListener('click', (e) => {
        // Only now do we take over from the plain link: if anything below
        // throws before this point the server-side link still works.
        e.preventDefault();

        const wanted = button.dataset.starred !== '1';
        button.disabled = true;

        // Promise.resolve() is load-bearing: core/ajax returns a jQuery
        // Deferred promise, and jQuery 3.7.1 promises have then/catch/always
        // but NO finally. Chaining .finally() straight onto it threw a
        // TypeError the moment the chain was built — after the button had
        // been disabled — so starring worked exactly once per page load and
        // the button then stayed disabled. Promise.resolve() adopts the
        // thenable into a native promise, which has .finally.
        Promise.resolve(fetchMany([{
            methodname: 'local_oerexchange_set_resource_star',
            args: {resourceid: resourceid, starred: wanted},
        }])[0])
            .then((result) => render(button, result.starred, result.count))
            .catch(Notification.exception)
            .finally(() => {
                button.disabled = false;
            });
    });
};
