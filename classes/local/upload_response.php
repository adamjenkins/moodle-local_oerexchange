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
 * JSON replies for a progress-bar upload.
 *
 * The two upload pages answer both a normal form POST (redirect + notification)
 * and an XMLHttpRequest one (JSON), from the SAME request handler: a browser
 * upload needs `upload.onprogress`, which only exists on an XHR, but the whole
 * point of the validation those pages perform is that it must be identical on
 * both paths. Splitting the handler into a separate ajax endpoint would have
 * duplicated it — the far likelier source of a security defect than this small
 * amount of output plumbing.
 *
 * Not core's `\core\ajax` helpers: those belong to the external-function
 * pipeline, which cannot receive a multipart file upload.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_response {
    /**
     * Whether this request wants JSON rather than a redirect.
     *
     * @return bool
     */
    public static function wanted(): bool {
        return (bool) optional_param('ajax', 0, PARAM_BOOL);
    }

    /**
     * Reply "published, go here" and stop.
     *
     * @param \moodle_url $target where the browser should go next
     * @param string $message the notification to show on arrival
     * @return never
     */
    public static function success(\moodle_url $target, string $message): void {
        self::send(['ok' => true, 'url' => $target->out(false), 'message' => $message]);
    }

    /**
     * Reply "rejected, here is why" and stop.
     *
     * The HTTP status stays 200: this is an application-level rejection the
     * caller renders as text (an invalid licence, a file that is not a .mbz),
     * not a transport failure, and an error status would make the JS treat it
     * as an upload that never arrived.
     *
     * @param string $error already-localised, author-facing
     * @return never
     */
    public static function failure(string $error): void {
        self::send(['ok' => false, 'error' => $error]);
    }

    /**
     * Emit the payload and end the request.
     *
     * @param array $payload
     * @return never
     */
    private static function send(array $payload): void {
        // Nothing has been echoed at either call site (both answer before
        // $OUTPUT->header()), so the buffers hold only whatever debugging
        // output a misconfigured site might have produced — discard it rather
        // than let it corrupt the JSON body.
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        die();
    }
}
