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
 * Progress bar for the .mbz / data upload forms.
 *
 * These forms carry course backups — the largest measured on the live
 * Exchange is 359 MB — over a plain multipart POST, which a browser reports
 * to the user in no way at all: the page simply sits there, for minutes, with
 * a submit button that looks ignored. That is the whole reason this module
 * exists.
 *
 * It is progressive enhancement, not a rewrite: the form still posts to the
 * same URL with the same fields and the same sesskey. All this does is send
 * it through XMLHttpRequest (the only API that reports upload progress —
 * fetch() still cannot, in any shipping browser) with `ajax=1` appended, so
 * the page answers with JSON instead of a redirect. With JavaScript off, or
 * if anything here throws before submit is intercepted, the ordinary form
 * post is what happens, and the server behaves exactly as it did before.
 *
 * @module     local_oerexchange/upload_progress
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {get_string as getString} from 'core/str';
import Notification from 'core/notification';

/**
 * Move the bar and update the text beside it.
 *
 * @param {HTMLElement} bar the .progress-bar element
 * @param {HTMLElement} label the status text element
 * @param {number|null} percent null for an indeterminate stage
 * @param {string} message
 */
const render = (bar, label, percent, message) => {
    if (percent === null) {
        // Indeterminate: the bytes are all sent and the server is working.
        // Striped-and-animated at 100% reads as "busy", where a bar frozen at
        // 100% reads as "finished but stuck".
        bar.classList.add('progress-bar-striped', 'progress-bar-animated');
        bar.style.width = '100%';
        bar.removeAttribute('aria-valuenow');
    } else {
        bar.classList.remove('progress-bar-striped', 'progress-bar-animated');
        bar.style.width = percent + '%';
        bar.setAttribute('aria-valuenow', String(percent));
    }
    label.textContent = message;
};

/**
 * Send the form through XHR, reporting upload progress.
 *
 * @param {HTMLFormElement} form
 * @param {HTMLElement} region the progress wrapper (hidden until now)
 * @param {HTMLElement} bar
 * @param {HTMLElement} label
 * @param {HTMLElement|null} submit the submit control, disabled while in flight
 */
const send = async(form, region, bar, label, submit) => {
    const data = new FormData(form);
    // The server distinguishes an XHR post from an ordinary one by this
    // parameter alone, and answers with JSON when it is set. It is not a
    // security boundary: sesskey and require_login() are, and both are
    // unchanged on this path.
    data.set('ajax', '1');

    region.classList.remove('d-none');
    if (submit) {
        submit.disabled = true;
    }
    render(bar, label, 0, await getString('uploadstarting', 'local_oerexchange'));

    const xhr = new XMLHttpRequest();
    xhr.open('POST', form.action, true);

    xhr.upload.addEventListener('progress', async(event) => {
        if (!event.lengthComputable) {
            return;
        }
        const percent = Math.min(100, Math.round((event.loaded / event.total) * 100));
        if (percent >= 100) {
            // Every byte has left the browser, but the server has not
            // answered yet: it is still writing the file into the draft area
            // and running publish(). For a 359 MB backup that gap is long
            // enough to matter, so it gets its own stage rather than a bar
            // that hits 100% and then appears to hang.
            render(bar, label, null, await getString('uploadvalidating', 'local_oerexchange'));
            return;
        }
        render(bar, label, percent, await getString('uploadprogress', 'local_oerexchange', percent));
    });

    xhr.addEventListener('load', async() => {
        let payload;
        try {
            payload = JSON.parse(xhr.responseText);
        } catch (e) {
            // Not JSON: a session timeout redirect, a proxy error page, or a
            // PHP fatal. Falling back to a plain re-submit would re-upload
            // hundreds of megabytes, so say what happened and leave the form
            // filled in for the author to retry deliberately.
            payload = null;
        }

        if (payload && payload.ok) {
            window.location.href = payload.url;
            return;
        }

        const reason = payload && payload.error ? payload.error : String(xhr.status);
        render(bar, label, 0, await getString('uploadfailed', 'local_oerexchange', reason));
        bar.classList.add('bg-danger');
        if (submit) {
            submit.disabled = false;
        }
    });

    xhr.addEventListener('error', async() => {
        render(bar, label, 0, await getString('uploadfailed', 'local_oerexchange', 'network'));
        bar.classList.add('bg-danger');
        if (submit) {
            submit.disabled = false;
        }
    });

    xhr.send(data);
};

/**
 * Human-readable bytes, in the same shape PHP's display_size() produces.
 *
 * @param {number} bytes
 * @returns {string}
 */
const formatSize = (bytes) => {
    const units = ['bytes', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${unit === 0 ? value : value.toFixed(1)} ${units[unit]}`;
};

/**
 * Say what the chosen file means before anything is sent: too big to accept
 * at all, or acceptable but awkward for the in-browser trial.
 *
 * The trial notes are advisory — the file uploads and publishes either way —
 * so they are worded as consequences, not refusals.
 *
 * @param {HTMLElement} advice the region under the file input
 * @param {File} file
 * @param {object} limits maxbytes, trialwarnbytes, trialmaxbytes
 */
const describeFile = async(advice, file, limits) => {
    advice.className = 'small mb-2';

    if (limits.maxbytes && file.size > limits.maxbytes) {
        advice.classList.add('text-danger');
        advice.textContent = await getString('uploadtoobig', 'local_oerexchange', {
            size: formatSize(file.size),
            max: formatSize(limits.maxbytes),
        });
        return;
    }

    if (limits.trialmaxbytes && file.size > limits.trialmaxbytes) {
        advice.classList.add('text-muted');
        advice.textContent = await getString('uploadtrialblocked', 'local_oerexchange', {
            size: formatSize(file.size),
            limit: formatSize(limits.trialmaxbytes),
        });
        return;
    }

    if (limits.trialwarnbytes && file.size > limits.trialwarnbytes) {
        advice.classList.add('text-muted');
        advice.textContent = await getString('uploadtrialslow', 'local_oerexchange', {
            size: formatSize(file.size),
            limit: formatSize(limits.trialwarnbytes),
        });
        return;
    }

    advice.textContent = '';
};

/**
 * Wire the upload form on this page.
 *
 * @param {object} limits maxbytes, trialwarnbytes, trialmaxbytes — all in bytes,
 *                        0 meaning "no such limit on this site"
 */
export const init = (limits = {}) => {
    const form = document.querySelector('[data-region="oerexchange-upload-form"]');
    const region = document.querySelector('[data-region="oerexchange-upload-progress"]');
    if (!form || !region) {
        return;
    }
    const bar = region.querySelector('.progress-bar');
    const label = region.querySelector('[data-region="oerexchange-upload-status"]');
    if (!bar || !label) {
        return;
    }
    const advice = document.querySelector('[data-region="oerexchange-upload-advice"]');
    const fileinput = form.querySelector('input[type="file"]');

    if (advice && fileinput) {
        fileinput.addEventListener('change', () => {
            if (!fileinput.files || !fileinput.files.length) {
                advice.textContent = '';
                return;
            }
            describeFile(advice, fileinput.files[0], limits).catch(Notification.exception);
        });
    }

    form.addEventListener('submit', (event) => {
        if (!fileinput || !fileinput.files || !fileinput.files.length) {
            // Nothing chosen: let the browser's own `required` validation
            // speak. Intercepting here would suppress it.
            return;
        }

        if (limits.maxbytes && fileinput.files[0].size > limits.maxbytes) {
            // Refuse here rather than sending hundreds of megabytes for the
            // server to reject on arrival. This is a courtesy, not a control:
            // resource_manager::publish() enforces the same number on every
            // path, including this form with JavaScript disabled.
            event.preventDefault();
            if (advice) {
                describeFile(advice, fileinput.files[0], limits).catch(Notification.exception);
                advice.focus?.();
            }
            return;
        }

        event.preventDefault();
        send(form, region, bar, label, form.querySelector('[type="submit"]'))
            .catch(Notification.exception);
    });
};
