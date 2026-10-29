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
 * Exchange and confirms the link (connect.php), issue a short-lived,
 * single-use code; the client exchanges it for a scoped WS token, minted
 * at that moment — never the token itself in a URL. See DESIGN.md §1 "Identity (hybrid)".
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class link_manager {
    /** @var string The one callback local_oerclient uses, relative to the client site's wwwroot. */
    const CALLBACK_PATH = '/local/oerclient/connect_callback.php';

    /**
     * Whether a client-supplied callback URL may be redirected to for a given
     * registered site — the open-redirect gate on connect.php.
     *
     * The scheme, host and port must match the site's registered URL, and
     * the path must be exactly that site's local_oerclient callback
     * (CALLBACK_PATH under the registered URL's own path, for a site in a
     * subdirectory). Matching on host alone (the behaviour before 0.1.6) let
     * an https-registered site be handed an http:// callback, carrying the
     * one-time link code — and so the personal WS token it is exchanged
     * for — over plaintext. Matching host and scheme but not the path let
     * the code be sent to any page on that host, where whoever reads that
     * page's requests could consume it.
     *
     * @param string $siteurl the registered site's URL
     * @param string $callback the callback URL supplied in the request
     * @return bool
     */
    public static function callback_matches_site(string $siteurl, string $callback): bool {
        $site = parse_url($siteurl);
        $cb = parse_url($callback);
        if (
            !$site || !$cb || empty($site['host']) || empty($cb['host'])
            || empty($site['scheme']) || empty($cb['scheme'])
        ) {
            return false;
        }
        if (isset($cb['user']) || isset($cb['pass'])) {
            return false;
        }

        if (strcasecmp($site['host'], $cb['host']) !== 0 || strcasecmp($site['scheme'], $cb['scheme']) !== 0) {
            return false;
        }

        $defaultport = strcasecmp($site['scheme'], 'https') === 0 ? 443 : 80;
        if (($site['port'] ?? $defaultport) !== ($cb['port'] ?? $defaultport)) {
            return false;
        }

        $expectedpath = rtrim($site['path'] ?? '', '/') . self::CALLBACK_PATH;
        return ($cb['path'] ?? '') === $expectedpath;
    }

    /** @var int Code validity window, seconds. */
    const TTL = 300;

    /**
     * Issue a one-time code the client can exchange for a WS token for
     * $userid, scoped to our service.
     *
     * The token is minted by consume(), not here: a code that is never
     * consumed (an abandoned link attempt, or one that simply expires) then
     * leaves no live permanent token behind.
     *
     * @param int $siteid
     * @param int $userid Exchange-local userid
     * @return string the code to hand back to the client (via redirect)
     */
    public static function issue_code(int $siteid, int $userid): string {
        global $DB;

        $code = bin2hex(random_bytes(32));
        $now = time();
        $DB->insert_record('local_oerexchange_linkcodes', (object) [
            'code' => $code,
            'siteid' => $siteid,
            'userid' => $userid,
            'token' => '',
            'status' => 'pending',
            'timecreated' => $now,
            'timeexpires' => $now + self::TTL,
        ]);

        return $code;
    }

    /**
     * Mint the permanent personal WS token a consumed code hands out.
     *
     * @param int $userid
     * @return string
     */
    protected static function mint_token(int $userid): string {
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
        // Generate_token() records the session user as the creator, and
        // link_consume.php has none (NO_MOODLE_COOKIES). The teacher who
        // confirmed the link on connect.php is the creator, as they were
        // when the token was minted there.
        $DB->set_field('external_tokens', 'creatorid', $userid, ['token' => $token]);
        return $token;
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
            // A code issued before tokens were minted on consumption can
            // still hold a live token; an expired code must not keep it.
            self::delete_held_token($record);
            $DB->set_field('local_oerexchange_linkcodes', 'status', 'expired', ['id' => $record->id]);
            throw new \moodle_exception('error_linkcodeexpired', 'local_oerexchange');
        }

        // A code issued before tokens were minted on consumption still
        // carries its token; hand that one out rather than minting another.
        $legacytoken = (string) $record->token;
        $userid = (int) $record->userid;

        // Atomic single-use claim: two concurrent requests could both read
        // the 'pending' row above before either wrote 'used', handing a
        // token out twice. The conditional UPDATE lets exactly one request
        // win: it writes a claim marker only this request knows, and the
        // loser, seeing someone else's marker (or a cleared one), gets the
        // same error a straight replay would.
        $claim = 'claim:' . bin2hex(random_bytes(16));
        $DB->execute(
            "UPDATE {local_oerexchange_linkcodes} SET status = 'used', token = ? WHERE id = ? AND status = 'pending'",
            [$claim, $record->id]
        );
        $claimed = $DB->get_record('local_oerexchange_linkcodes', ['id' => $record->id], 'id,status,token', MUST_EXIST);
        if ($claimed->status !== 'used' || $claimed->token !== $claim) {
            throw new \moodle_exception('error_linkcodeused', 'local_oerexchange');
        }
        // Nothing token-shaped stays in storage once the code is used.
        $DB->set_field('local_oerexchange_linkcodes', 'token', '', ['id' => $record->id]);

        $token = $legacytoken !== '' ? $legacytoken : self::mint_token($userid);

        // Record which token this handshake produced, so revoking the site
        // deletes it (revoke_site_tokens()).
        $tokenid = $DB->get_field('external_tokens', 'id', ['token' => $token]);
        if ($tokenid) {
            $DB->set_field('local_oerexchange_linkcodes', 'tokenid', (int) $tokenid, ['id' => $record->id]);
        }

        return (object) ['token' => $token, 'userid' => $userid];
    }

    /**
     * Delete the personal tokens a site's account-linking handshakes
     * produced. Called by site_manager::revoke().
     *
     * Exactly the tokens recorded on the site's link-code rows go, so a
     * teacher linked to several sites keeps the tokens the other sites hold.
     * Also deleted: a token still held by a pending code of this site (a
     * code issued before tokens were minted on consumption).
     *
     * Not reached: a token handed out before linkcodes.tokenid existed
     * (2026102900). Nothing records which site such a token was minted for,
     * and deleting a teacher's tokens by guesswork would cut off their other
     * sites too.
     *
     * @param int $siteid
     * @return int how many tokens were deleted
     */
    public static function revoke_site_tokens(int $siteid): int {
        global $DB;

        $deleted = 0;
        $codes = $DB->get_records_select(
            'local_oerexchange_linkcodes',
            "siteid = ? AND (tokenid IS NOT NULL OR (status <> 'used' AND token <> ''))",
            [$siteid]
        );
        foreach ($codes as $code) {
            if ($code->tokenid) {
                $deleted += $DB->count_records('external_tokens', ['id' => $code->tokenid]);
                $DB->delete_records('external_tokens', ['id' => $code->tokenid]);
                $DB->set_field('local_oerexchange_linkcodes', 'tokenid', null, ['id' => $code->id]);
            }
            $deleted += self::delete_held_token($code);
        }

        return $deleted;
    }

    /**
     * Delete link codes that expired without being used, and any token one
     * still holds.
     *
     * Used codes stay: they are the record that a teacher linked through a
     * site, which publish_resource and record_import check. A pending code
     * past its expiry can no longer be consumed, so it is dead weight, and a
     * pending code issued before tokens were minted on consumption holds a
     * live permanent token in 'token' that nobody will ever collect.
     *
     * @param int|null $now
     * @return int how many codes were deleted
     */
    public static function cleanup_expired_codes(?int $now = null): int {
        global $DB;

        $codes = $DB->get_records_select(
            'local_oerexchange_linkcodes',
            "status <> 'used' AND timeexpires < ?",
            [$now ?? time()]
        );
        foreach ($codes as $code) {
            self::delete_held_token($code);
            $DB->delete_records('local_oerexchange_linkcodes', ['id' => $code->id]);
        }

        return count($codes);
    }

    /**
     * Delete the web service token a not-yet-used code still holds, and
     * clear it from the row. Only codes issued before tokens were minted on
     * consumption hold one; a claim marker (consume()) is not a token.
     *
     * @param \stdClass $code a linkcodes row
     * @return int 1 when a token was deleted, else 0
     */
    protected static function delete_held_token(\stdClass $code): int {
        global $DB;

        $token = (string) $code->token;
        if ($code->status === 'used' || $token === '' || str_starts_with($token, 'claim:')) {
            return 0;
        }

        $deleted = $DB->count_records('external_tokens', ['token' => $token]);
        $DB->delete_records('external_tokens', ['token' => $token]);
        $DB->set_field('local_oerexchange_linkcodes', 'token', '', ['id' => $code->id]);

        return $deleted;
    }
}
