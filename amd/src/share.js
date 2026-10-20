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
import {setUserPreference} from 'core_user/repository';
import config from 'core/config';

/**
 * Cookie remembering this browser's Mastodon instance (share_targets::MASTODON_COOKIE).
 *
 * A cookie, not localStorage: core/storage_validation clears the whole of the
 * site's localStorage whenever the stored login time differs from the current
 * one (every page load with JS caching off, otherwise every new login), so a
 * value kept there does not survive.
 *
 * @type {string}
 */
const MASTODON_COOKIE = 'local_oerexchange_mastodon';

/** @type {number} How long the cookie is kept, in seconds: a year. */
const MASTODON_COOKIE_AGE = 365 * 24 * 60 * 60;

/** @type {string} The user preference a signed-in user's instance is kept in (share_targets::MASTODON_PREFERENCE). */
const MASTODON_PREFERENCE = 'local_oerexchange_mastodoninstance';

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
 * Whether a value is a plausible Mastodon server host. The same rule as
 * share_targets::valid_mastodon_instance() on the server.
 *
 * @param {string} host
 * @return {boolean}
 */
const validHost = (host) => host.length <= 253 && /^[a-z0-9.-]+\.[a-z]{2,}$/i.test(host);

/**
 * Read this browser's remembered instance from its cookie.
 *
 * @return {string} '' when there is none or it is not a valid host
 */
const storedHost = () => {
    const pair = document.cookie.split('; ').find((c) => c.startsWith(MASTODON_COOKIE + '='));
    const host = pair ? decodeURIComponent(pair.slice(MASTODON_COOKIE.length + 1)) : '';
    return validHost(host) ? host : '';
};

/**
 * Remember an instance in this browser's cookie, scoped to the site's path.
 *
 * @param {string} host
 */
const storeHost = (host) => {
    let path = '/';
    try {
        path = new URL(config.wwwroot).pathname || '/';
    } catch (e) {
        // Keep '/': wider than needed, never narrower.
    }
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = MASTODON_COOKIE + '=' + encodeURIComponent(host) + '; Max-Age=' + MASTODON_COOKIE_AGE
        + '; Path=' + path + '; SameSite=Lax' + secure;
};

/**
 * The instance to share to: what share_targets::render() put on the region
 * (the account preference, else the cookie), else the cookie as it is now
 * (it may have been set in another tab since the page loaded).
 *
 * @param {HTMLElement} region
 * @return {string} '' when none is known yet
 */
const knownHost = (region) => region.dataset.mastodonInstance || storedHost();

/**
 * Show the remembered instance on the button and reveal "Change server".
 *
 * @param {HTMLElement} region
 * @param {string} host
 */
const showHost = async(region, host) => {
    const label = region.querySelector('[data-region="oerexchange-share-mastodon-label"]');
    if (label) {
        label.textContent = await getString('sharetarget_mastodon_at', 'local_oerexchange', host);
    }
    const change = region.querySelector('[data-action="oerexchange-share-mastodon-change"]');
    if (change) {
        change.hidden = false;
    }
};

/**
 * Remember an instance: in this browser, on the account when signed in, and
 * on every share panel on the page.
 *
 * The account half is fire-and-forget. If it fails, the browser still
 * remembers, and the next choice will try again; it is not worth an error
 * message in the middle of sharing something.
 *
 * @param {HTMLElement} region the panel the choice was made in
 * @param {string} host
 */
const rememberHost = (region, host) => {
    storeHost(host);
    if (region.dataset.mastodonSave === '1') {
        Promise.resolve(setUserPreference(MASTODON_PREFERENCE, host)).catch(() => null);
    }
    document.querySelectorAll('[data-region="oerexchange-share"]').forEach((panel) => {
        panel.dataset.mastodonInstance = host;
        showHost(panel, host);
    });
};

/**
 * Ask which instance to use.
 *
 * @param {string} current the instance to offer, or ''
 * @return {Promise<string>} the normalised host, or '' when cancelled or invalid
 */
const askHost = async(current) => {
    const prompt = await getString('sharemastodonprompt', 'local_oerexchange');
    // Deliberate prompt: Mastodon is federated, so the instance host can only
    // come from the user, and this fires on an explicit button press rather
    // than on page load. It is now asked once, not on every share.
    // eslint-disable-next-line no-alert
    const answer = window.prompt(prompt, current || 'mastodon.social');
    if (!answer) {
        return '';
    }

    // Accept 'mastodon.social', 'https://mastodon.social' or a trailing
    // slash, and normalise to a bare host so the URL we build is predictable.
    const host = answer.trim().replace(/^https?:\/\//, '').replace(/\/+$/, '').toLowerCase();
    if (!validHost(host)) {
        addToast(await getString('sharemastodonbadinstance', 'local_oerexchange'), {type: 'warning'});
        return '';
    }

    return host;
};

/**
 * Open the instance's own share page.
 *
 * @param {string} host
 * @param {string} url
 * @param {string} title
 */
const openMastodon = (host, url, title) => {
    window.open(
        'https://' + host + '/share?text=' + encodeURIComponent(title + ' ' + url),
        '_blank',
        'noopener,noreferrer'
    );
};

/**
 * Mastodon has no single share endpoint (each instance serves its own), so
 * the first share asks for the user's instance and every later one goes
 * straight there. "Change server" asks again.
 *
 * The remembered path opens the window synchronously, inside the click, so
 * no popup blocker treats it as unrequested.
 *
 * @param {HTMLElement} region
 * @param {string} url
 * @param {string} title
 */
const mastodonShare = async(region, url, title) => {
    const known = knownHost(region);
    if (known) {
        openMastodon(known, url, title);
        return;
    }
    const host = await askHost('');
    if (!host) {
        return;
    }
    rememberHost(region, host);
    openMastodon(host, url, title);
};

/**
 * Choose a different instance, without sharing.
 *
 * @param {HTMLElement} region
 */
const changeMastodon = async(region) => {
    const host = await askHost(knownHost(region));
    if (host) {
        rememberHost(region, host);
    }
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

        // Navigator.share only exists on some browsers (mostly mobile), so
        // the button ships hidden and is revealed only where it will work.
        const native = region.querySelector('[data-action="oerexchange-share-nativeshare"]');
        if (native && navigator.share) {
            native.hidden = false;
        }

        // The account's instance wins: copy it into this browser's cookie so
        // the browser agrees after signing out. (The button was already named
        // server-side from the preference or the cookie.)
        const rendered = region.dataset.mastodonInstance;
        if (rendered && rendered !== storedHost()) {
            storeHost(rendered);
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
                    mastodonShare(region, url, title);
                    break;
                case 'oerexchange-share-mastodon-change':
                    changeMastodon(region);
                    break;
            }
        });
    });
};
