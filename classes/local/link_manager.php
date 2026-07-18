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
 * Account-linking handshake: after the teacher logs in/signs up on the
 * Exchange (connect.php), mint a scoped WS token and hand it to the client
 * site via a short-lived, single-use, signed code — never the token itself
 * in a URL. See DESIGN.md §1 "Identity (hybrid)".
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class link_manager {
    /** @var int Code validity window, seconds. */
    const TTL = 300;

    /**
     * Mint a WS token for $userid scoped to our service, and issue a
     * one-time code the client can exchange for it.
     *
     * @param int $siteid
     * @param int $userid Exchange-local userid
     * @return string the code to hand back to the client (via redirect)
     */
    public static function issue_code(int $siteid, int $userid): string {
        global $DB;

        $serviceid = $DB->get_field('external_services', 'id', ['shortname' => 'local_oerexchange'], MUST_EXIST);
        $token = \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            (object) ['id' => $serviceid],
            $userid,
            \context_system::instance(),
            0,
            ''
        );

        $code = bin2hex(random_bytes(32));
        $now = time();
        $DB->insert_record('local_oerexchange_linkcodes', (object) [
            'code' => $code,
            'siteid' => $siteid,
            'userid' => $userid,
            'token' => $token,
            'status' => 'pending',
            'timecreated' => $now,
            'timeexpires' => $now + self::TTL,
        ]);

        return $code;
    }

    /**
     * Consume a one-time code: returns the token once, then invalidates it.
     *
     * @param string $code
     * @return \stdClass {token, userid} on success
     * @throws \moodle_exception on expired/used/unknown code
     */
    public static function consume(string $code): \stdClass {
        global $DB;

        $record = $DB->get_record('local_oerexchange_linkcodes', ['code' => $code]);
        if (!$record) {
            throw new \moodle_exception('error_notfound', 'local_oerexchange');
        }
        if ($record->status === 'used') {
            throw new \moodle_exception('error_linkcodeused', 'local_oerexchange');
        }
        if ($record->timeexpires < time()) {
            $DB->set_field('local_oerexchange_linkcodes', 'status', 'expired', ['id' => $record->id]);
            throw new \moodle_exception('error_linkcodeexpired', 'local_oerexchange');
        }

        $token = $record->token;
        $userid = (int) $record->userid;

        // Clear the token from storage immediately — it has now been handed off.
        $DB->update_record('local_oerexchange_linkcodes', (object) [
            'id' => $record->id,
            'status' => 'used',
            'token' => '',
        ]);

        return (object) ['token' => $token, 'userid' => $userid];
    }
}
