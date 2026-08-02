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
 * Watches an upload through validation and says what happened.
 *
 * Publishing is asynchronous: resource_manager::publish() stores the file and
 * queues parse_backup_task, which runs on the next cron tick and either
 * publishes the resource or records a rejection reason. Between those two
 * moments the author used to see a bare "Pending" and had no way of knowing
 * whether to wait, reload, or start again.
 *
 * Polling (rather than a push channel) because the answer comes from cron on
 * the server, arrives once, and is expected within about a minute — a 3 s
 * poll costs a handful of tiny reads and needs nothing running between them.
 * It gives up after five minutes rather than hammering a site whose cron has
 * stopped: the region then says to reload, which is true and honest, instead
 * of spinning forever.
 *
 * @module     local_oerexchange/publish_status
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {call as fetchMany} from 'core/ajax';
import {get_string as getString} from 'core/str';

/** @type {number} milliseconds between polls. */
const POLL_INTERVAL = 3000;

/** @type {number} milliseconds after which polling gives up. */
const POLL_TIMEOUT = 5 * 60 * 1000;

/** @type {number} milliseconds the outcome is shown before the page reloads. */
const RELOAD_DELAY = 1200;

/**
 * Replace the region's contents with a plain message, dropping the spinner.
 *
 * @param {HTMLElement} region
 * @param {string} message
 * @param {string} alertClass e.g. 'alert-success'
 */
const settle = (region, message, alertClass) => {
    region.classList.remove('alert-info');
    region.classList.add(alertClass);
    region.textContent = message;
};

/**
 * Ask the server once.
 *
 * @param {number} resourceid
 * @returns {Promise<object>}
 */
const poll = (resourceid) => fetchMany([{
    methodname: 'local_oerexchange_get_publish_status',
    args: {resourceid: resourceid},
}])[0];

/**
 * Start watching.
 *
 * @param {number} resourceid the resource whose newest version is still parsing
 */
export const init = (resourceid) => {
    const region = document.querySelector('[data-region="oerexchange-publish-status"]');
    if (!region) {
        return;
    }

    const startedAt = Date.now();

    const tick = async() => {
        let status;
        try {
            status = await poll(resourceid);
        } catch (e) {
            // A single failed poll is not worth telling the author about —
            // a lost packet or a restarting web server is exactly what the
            // next tick is for. Only the timeout below is reported.
            scheduleNext();
            return;
        }

        if (!status.settled) {
            scheduleNext();
            return;
        }

        if (status.published) {
            settle(region, await getString('publishready', 'local_oerexchange'), 'alert-success');
        } else if (status.error) {
            settle(region, status.error, 'alert-danger');
        } else {
            // Settled but neither published nor failed: a moderator hid or
            // removed it while we watched. Reloading shows the real state
            // rather than this module inventing a name for it.
            settle(region, await getString('publishsettled', 'local_oerexchange'), 'alert-info');
        }

        // Reload once the answer is in: the rest of the page (Try it,
        // Download, the catalogue link, the owner controls) was rendered for
        // a pending resource and is now out of date. The short delay leaves
        // the outcome on screen long enough to be read, and to be announced.
        window.setTimeout(() => window.location.reload(), RELOAD_DELAY);
    };

    const scheduleNext = () => {
        if (Date.now() - startedAt > POLL_TIMEOUT) {
            getString('publishstillpending', 'local_oerexchange')
                .then((message) => settle(region, message, 'alert-warning'))
                .catch(() => {
                    // Nothing further to try: the region keeps its server-rendered
                    // "checking" text, which remains true.
                    return;
                });
            return;
        }
        window.setTimeout(tick, POLL_INTERVAL);
    };

    scheduleNext();
};
