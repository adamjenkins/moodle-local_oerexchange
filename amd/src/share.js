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
 * Share affordance for profile and resource pages.
 *
 * Replaces an earlier inline js_init_code handler that tried
 * navigator.share, fell back to navigator.clipboard.writeText, and gave the
 * user no feedback on any path — including swallowing the clipboard
 * rejection with an empty catch. Reproduced in Chromium on 2026-07-23:
 * navigator.share is undefined on desktop Linux, and the clipboard write
 * either succeeds silently or rejects silently, so the button was
 * indistinguishable from a dead one either way.
 *
 * Every path here therefore ends in something the user can see.
 *
 * @module     local_oerexchange/share
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {add as addToast} from 'core/toast';
import {get_string as getString} from 'core/str';

/** @type {string} localStorage key remembering the user's Mastodon instance. */
const MASTODON_INSTANCE_KEY = 'local_oerexchange/mastodoninstance';

/**
 * Copy text to the clipboard, falling back to selecting it when the
 * Clipboard API is unavailable or refuses.
 *
 * @param {HTMLElement} region the share disclosure
 * @param {string} url
 */
const copyLink = async(region, url) => {
    const input = region.querySelector('[data-region="oerexchange-share-url"]');

    if (navigator.clipboard) {
        try {
            await navigator.clipboard.writeText(url);
            addToast(await getString('sharecopied', 'local_oerexchange'), {type: 'success'});
            return;
        } catch (e) {
            // Permission denied, or a non-secure context. Fall through to
            // the selection fallback rather than failing silently, which is
            // exactly the bug this module was written to fix.
        }
    }

    if (input) {
        input.focus();
        input.select();
    }
    addToast(await getString('sharecopyfailed', 'local_oerexchange'), {type: 'info'});
};

/**
 * Hand off to the OS/browser share sheet.
 *
 * @param {string} url
 * @param {string} title
 */
const nativeShare = async(url, title) => {
    try {
        await navigator.share({title, url});
    } catch (e) {
        // AbortError just means the user dismissed the sheet — saying
        // anything about that would be noise, so only real failures speak up.
        if (e && e.name !== 'AbortError') {
            addToast(await getString('sharefailed', 'local_oerexchange'), {type: 'warning'});
        }
    }
};

/**
 * Mastodon has no single share endpoint — each instance serves its own — so
 * ask for the user's instance and remember it for next time.
 *
 * @param {string} url
 * @param {string} title
 */
const mastodonShare = async(url, title) => {
    const remembered = window.localStorage ? window.localStorage.getItem(MASTODON_INSTANCE_KEY) : '';
    const prompt = await getString('sharemastodonprompt', 'local_oerexchange');
    const answer = window.prompt(prompt, remembered || 'mastodon.social');
    if (!answer) {
        return;
    }

    // Accept 'mastodon.social', 'https://mastodon.social' or a trailing
    // slash, and normalise to a bare host so the URL we build is predictable.
    const host = answer.trim().replace(/^https?:\/\//, '').replace(/\/+$/, '');
    if (!host || !/^[a-z0-9.-]+\.[a-z]{2,}$/i.test(host)) {
        addToast(await getString('sharemastodonbadinstance', 'local_oerexchange'), {type: 'warning'});
        return;
    }

    if (window.localStorage) {
        window.localStorage.setItem(MASTODON_INSTANCE_KEY, host);
    }
    window.open(
        'https://' + host + '/share?text=' + encodeURIComponent(title + ' ' + url),
        '_blank',
        'noopener,noreferrer'
    );
};

/**
 * Wire up every share disclosure on the page.
 */
export const init = () => {
    document.querySelectorAll('[data-region="oerexchange-share"]').forEach((region) => {
        if (region.dataset.oerexchangeShareInitialised) {
            return;
        }
        region.dataset.oerexchangeShareInitialised = '1';

        const url = region.dataset.shareUrl;
        const title = region.dataset.shareTitle || '';

        // navigator.share only exists on some browsers (mostly mobile), so
        // the button ships hidden and is revealed only where it will work.
        const native = region.querySelector('[data-action="oerexchange-share-nativeshare"]');
        if (native && navigator.share) {
            native.hidden = false;
        }

        region.addEventListener('click', (e) => {
            const target = e.target.closest('[data-action^="oerexchange-share-"]');
            if (!target) {
                return;
            }
            e.preventDefault();

            switch (target.dataset.action) {
                case 'oerexchange-share-copylink':
                    copyLink(region, url);
                    break;
                case 'oerexchange-share-nativeshare':
                    nativeShare(url, title);
                    break;
                case 'oerexchange-share-mastodon':
                    mastodonShare(url, title);
                    break;
            }
        });
    });
};
