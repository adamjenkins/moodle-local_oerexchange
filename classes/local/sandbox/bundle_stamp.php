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

namespace local_oerexchange\local\sandbox;

/**
 * The deployed sandbox bundle's own stamp: written by oer-sandbox's build
 * (SANDBOX-CONFIG-PLAN.md Task 11) to `<sandboxbaseurl>/assets/oer-bundle-stamp.json`
 * once a build with a `--config` file has actually finished and deployed.
 * Comparing it against config::stamp() on the sandbox configuration admin
 * page is how the Exchange verifies an admin's "these settings are bundled"
 * claim rather than trusting the checkbox (SANDBOX-CONFIG-DESIGN.md D1).
 *
 * interpret() is the pure half, fully unit-tested. deployed() wraps it
 * around the actual HTTP fetch, which only ever happens when an admin loads
 * the config page (MUC-cached — see db/caches.php's 'sandboxbundlestamp'
 * definition) — never during a trial launch, and never allowed to turn into
 * a false "mismatch": core's curl/Guzzle security guard has blocked
 * cross-site calls in this suite before (local_oerclient's 0.1.3 round), and
 * the sandbox may be on a different host than this Exchange, so any fetch
 * failure here is reported as unverifiable, never as a mismatch.
 *
 * Confirmed live 2026-07-31 (SANDBOX-CONFIG-PLAN.md Task 12 Defect 3): on a
 * sandboxbaseurl that resolves to a private-range address (this dev VM,
 * 192.168.56.10), core's curl_security_helper blocks the request outright
 * ("The URL is blocked") before any TLS handshake — so a self-hosted "try
 * it" sandbox on an internal network can never verify here by default. See
 * fetch()'s own docblock for the fix and why it is opt-in.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bundle_stamp {
    /** @var int connection timeout, seconds — the fetch runs inline on an admin page load. */
    const CONNECT_TIMEOUT = 3;

    /** @var int total request timeout, seconds. */
    const TOTAL_TIMEOUT = 5;

    /**
     * Parse a stamp document's raw body.
     *
     * A missing/blank `stamp` is treated the same as invalid JSON — an
     * error, not a stamp — because a document that parses but carries
     * nothing usable is exactly as unable to prove a match as one that
     * doesn't parse at all; letting either through as `null` with no
     * `error` would let a caller mistake "nothing to compare" for "verified
     * absent", which is not a thing this contract has.
     *
     * @param string $body
     * @return array{stamp: ?string, built: ?int, error: ?string}
     */
    public static function interpret(string $body): array {
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['stamp']) || !is_string($data['stamp']) || $data['stamp'] === '') {
            return [
                'stamp' => null,
                'built' => null,
                'error' => get_string('error_stampmalformed', 'local_oerexchange'),
            ];
        }

        $built = null;
        if (isset($data['built']) && (is_int($data['built']) || (is_string($data['built']) && ctype_digit($data['built'])))) {
            $built = (int) $data['built'];
        }

        return ['stamp' => $data['stamp'], 'built' => $built, 'error' => null];
    }

    /**
     * Fetch and interpret the deployed bundle's stamp file, MUC-cached.
     *
     * Never called from the trial-launch path (sandbox_launch.php) — only
     * from the sandbox configuration admin page, which is the one place this
     * comparison is shown.
     *
     * @return array{stamp: ?string, built: ?int, error: ?string}
     */
    public static function deployed(): array {
        $baseurl = (string) get_config('local_oerexchange', 'sandboxbaseurl');
        if ($baseurl === '') {
            return [
                'stamp' => null,
                'built' => null,
                'error' => get_string('error_stampnourl', 'local_oerexchange'),
            ];
        }

        $cache = \cache::make('local_oerexchange', 'sandboxbundlestamp');
        $cached = $cache->get('deployed');
        if ($cached !== false) {
            return $cached;
        }

        $result = self::fetch($baseurl);
        $cache->set('deployed', $result);

        return $result;
    }

    /**
     * The actual HTTP fetch, isolated from deployed()'s caching so a test
     * can exercise the no-URL-configured path without ever reaching this
     * method — SANDBOX-CONFIG-PLAN.md Task 7 deliberately scopes automated
     * coverage of the fetch itself to that one case, leaving a real fetch to
     * live verification (Task 12).
     *
     * `sandboxbaseurl` is admin-configured, not attacker input — the same
     * trust local_oerclient\exchange_client::http_options() places in its
     * own admin-configured Exchange URL, and 'ignoresecurity' here follows
     * that precedent to let core's curl SSRF guard (curlsecurityblockedhosts,
     * which blocks private-range addresses by default) pass a fetch the
     * admin explicitly asked for. Unlike exchange_client, this is NOT
     * unconditional: it is gated behind its own opt-in setting
     * ('sandboxbaseurlinsecure', default off), because a public "try it"
     * sandbox is expected to sit behind a normal, validly-certificated host,
     * and a self-hosted/private-network/self-signed-cert deployment (this
     * dev VM's own situation — see the class docblock) is the exception, not
     * the rule. Turning the setting on also disables TLS certificate
     * verification for this one fetch, for the same reason
     * exchange_client's 'verify' is tied to its own equivalent setting: a
     * self-signed dev cert fails default verification independently of the
     * SSRF guard, and there is no reason to relax one without the other for
     * a deployment that needs either. See exchange_client's own docblock for
     * why this must stay opt-in rather than unconditional ("Earlier
     * revisions shipped verify=false + ignoresecurity unconditionally...
     * every site and personal token travelled over unverified TLS on ANY
     * production install").
     *
     * @param string $baseurl
     * @return array{stamp: ?string, built: ?int, error: ?string}
     */
    private static function fetch(string $baseurl): array {
        $url = rtrim($baseurl, '/') . '/assets/oer-bundle-stamp.json';

        $options = [
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'timeout' => self::TOTAL_TIMEOUT,
        ];
        if ((bool) get_config('local_oerexchange', 'sandboxbaseurlinsecure')) {
            $options['verify'] = false;
            $options['ignoresecurity'] = true;
        }

        try {
            $client = new \core\http_client($options);
            $response = $client->get($url);
            if ($response->getStatusCode() !== 200) {
                return [
                    'stamp' => null,
                    'built' => null,
                    'error' => get_string(
                        'error_stampfetchfailed',
                        'local_oerexchange',
                        (string) $response->getStatusCode()
                    ),
                ];
            }

            return self::interpret((string) $response->getBody());
        } catch (\Throwable $e) {
            // Deliberately broad: a blocked-by-SSRF-guard request, a DNS
            // failure, a timeout and a connection refusal all mean the same
            // thing here — this Exchange could not verify the deployed
            // bundle, which is a materially different, weaker claim than
            // "the deployed bundle disagrees" and must never be rendered as
            // the latter.
            return [
                'stamp' => null,
                'built' => null,
                'error' => get_string('error_stampfetchfailed', 'local_oerexchange', $e->getMessage()),
            ];
        }
    }
}
