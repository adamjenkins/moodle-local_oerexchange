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
 * HMAC-signed, short-lived, unauthenticated download URLs for .mbz files —
 * used both for WS clients (returned by get_resource/publish_resource) and
 * for sandbox trial launches (the playground fetches the URL itself,
 * same-origin, with no WS token of its own). See DESIGN.md §4.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class download_signer {
    /** @var int Default validity window, seconds. */
    const DEFAULT_TTL = 900;

    /**
     * Sign a download URL for a version's file.
     *
     * @param int $versionid
     * @param int $ttl seconds of validity
     * @return \moodle_url
     */
    public static function sign_url(int $versionid, int $ttl = self::DEFAULT_TTL): \moodle_url {
        $expires = time() + $ttl;
        $sig = self::sign($versionid, $expires);

        return new \moodle_url('/local/oerexchange/download.php', [
            'v' => $versionid,
            'exp' => $expires,
            'sig' => $sig,
        ]);
    }

    /**
     * Verify a (versionid, expires, signature) tuple.
     *
     * @param int $versionid
     * @param int $expires
     * @param string $sig
     * @return bool
     */
    public static function verify(int $versionid, int $expires, string $sig): bool {
        if ($expires < time()) {
            return false;
        }
        return hash_equals(self::sign($versionid, $expires), $sig);
    }

    /**
     * Computes the HMAC signature for a version id + expiry pair.
     *
     * @param int $versionid
     * @param int $expires
     * @return string
     */
    protected static function sign(int $versionid, int $expires): string {
        return hash_hmac('sha256', $versionid . ':' . $expires, self::get_secret());
    }

    /**
     * Lazily-initialised per-site HMAC secret.
     *
     * @return string
     */
    protected static function get_secret(): string {
        $secret = get_config('local_oerexchange', 'downloadsecret');
        if (empty($secret)) {
            $secret = bin2hex(random_bytes(32));
            set_config('downloadsecret', $secret, 'local_oerexchange');
        }
        return $secret;
    }
}
