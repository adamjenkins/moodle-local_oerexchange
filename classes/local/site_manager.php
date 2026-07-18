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

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/user/lib.php');

/**
 * Client-site registration: register/approve/revoke.
 *
 * The "site key" a client site holds is a real core web service token, minted
 * against a dedicated, non-interactive Moodle user account created for that
 * site (auth=manual so core WS auth accepts it — 'nologin' accounts are
 * explicitly rejected by webservice/lib.php — but with a random, never-shown
 * password and no capabilities beyond what the OER Exchange service grants).
 * Revoking a site suspends that account, which immediately invalidates every
 * token minted against it (webservice/lib.php checks $user->suspended on
 * every call).
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class site_manager {
    /**
     * Register a new client site, pending admin approval. No account/token
     * exists yet — this is the one truly anonymous step in the handshake.
     *
     * @param string $name
     * @param string $url
     * @param string $contact contact email
     * @return int the new site id
     */
    public static function register(string $name, string $url, string $contact): int {
        global $DB;

        $now = time();
        return (int) $DB->insert_record('local_oerexchange_sites', (object) [
            'name' => $name,
            'url' => $url,
            'contact' => $contact,
            'serviceuserid' => null,
            'status' => 'pending',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Approve a pending site: create its dedicated service account (or reuse
     * one from a prior approve/revoke cycle), mint a fresh WS token, email it
     * to the registered contact, and activate the site.
     *
     * @param int $siteid
     * @return string the raw token (shown once — caller is responsible for display/email)
     */
    public static function approve(int $siteid): string {
        global $DB;

        $site = $DB->get_record('local_oerexchange_sites', ['id' => $siteid], '*', MUST_EXIST);

        if (empty($site->serviceuserid)) {
            $site->serviceuserid = self::create_service_account($siteid);
        } else {
            // Re-approval after a revoke: un-suspend the existing account.
            $DB->set_field('user', 'suspended', 0, ['id' => $site->serviceuserid]);
        }

        // Clear any stale tokens from a previous approval before minting a fresh one.
        $DB->delete_records('external_tokens', ['userid' => $site->serviceuserid]);

        $serviceid = $DB->get_field('external_services', 'id', ['shortname' => 'local_oerexchange'], MUST_EXIST);
        $rawtoken = \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            (object) ['id' => $serviceid],
            $site->serviceuserid,
            \context_system::instance(),
            0,
            ''
        );

        $site->status = 'active';
        $site->timemodified = time();
        $DB->update_record('local_oerexchange_sites', $site);

        self::email_site_key($site, $rawtoken);

        return $rawtoken;
    }

    /**
     * Revoke a site: suspend its service account (kills every token minted
     * against it) and delete the tokens for cleanliness.
     *
     * Known gap (reported, not fixed — see dev-docs): this does NOT revoke
     * personal teacher tokens minted via this site's account-linking
     * handshakes (link_manager::issue_code() mints those against the
     * teacher's own userid, not this site's service account, so suspending
     * the service account alone does not touch them). A correct fix needs
     * `local_oerexchange_linkcodes` to durably record the minted token's
     * `external_tokens.id` at mint time (it currently only stores the raw
     * token, cleared after consume()) — a schema change, deliberately not
     * made in this pass to avoid an unplanned plugin version bump while
     * other agents share this test site's PHPUnit environment (bumping any
     * plugin's version invalidates `core\component::get_all_versions_hash()`
     * for every plugin, breaking concurrent PHPUnit runs site-wide).
     *
     * @param int $siteid
     */
    public static function revoke(int $siteid): void {
        global $DB;

        $site = $DB->get_record('local_oerexchange_sites', ['id' => $siteid], '*', MUST_EXIST);

        if (!empty($site->serviceuserid)) {
            $DB->set_field('user', 'suspended', 1, ['id' => $site->serviceuserid]);
            $DB->delete_records('external_tokens', ['userid' => $site->serviceuserid]);
        }

        $DB->set_field('local_oerexchange_sites', 'status', 'revoked', ['id' => $siteid]);
        $DB->set_field('local_oerexchange_sites', 'timemodified', time(), ['id' => $siteid]);
    }

    /**
     * Look up which registered, active site a WS-authenticated $USER represents.
     *
     * @param int $userid
     * @return \stdClass|false the site record, or false if $userid isn't an active site account
     */
    public static function get_site_for_user(int $userid) {
        global $DB;

        return $DB->get_record('local_oerexchange_sites', ['serviceuserid' => $userid, 'status' => 'active']);
    }

    /**
     * Create the dedicated, non-interactive Moodle account for a site.
     *
     * @param int $siteid
     * @return int the new userid
     */
    protected static function create_service_account(int $siteid): int {
        $user = new \stdClass();
        $user->username = 'oersite_' . $siteid;
        $user->password = base64_encode(random_bytes(24)); // Never disclosed; auth is by WS token only.
        $user->auth = 'manual';
        $user->firstname = 'OER site';
        $user->lastname = '#' . $siteid;
        $user->email = 'oersite+' . $siteid . '@' . parse_url($GLOBALS['CFG']->wwwroot, PHP_URL_HOST);
        $user->confirmed = 1;
        $user->mnethostid = $GLOBALS['CFG']->mnet_localhost_id;
        $user->suspended = 0;

        return user_create_user($user, false, false);
    }

    /**
     * Email the freshly-issued raw token to the site's registered contact.
     *
     * @param \stdClass $site
     * @param string $rawtoken
     */
    protected static function email_site_key(\stdClass $site, string $rawtoken): void {
        $supportuser = \core_user::get_support_user();

        $fakeuser = (object) [
            'id' => -1,
            'email' => $site->contact,
            'firstname' => $site->name,
            'lastname' => '',
            'maildisplay' => true,
            'mailformat' => 1,
            'auth' => 'manual',
            'confirmed' => 1,
            'suspended' => 0,
            'deleted' => 0,
            'city' => '',
            'country' => '',
            'lang' => 'en',
            'firstnamephonetic' => '',
            'lastnamephonetic' => '',
            'middlename' => '',
            'alternatename' => '',
        ];

        $subject = get_string('sitekeyemailsubject', 'local_oerexchange');
        $body = get_string('sitekeyemailbody', 'local_oerexchange', (object) [
            'name' => $site->name,
            'sitekey' => $rawtoken,
        ]);

        email_to_user($fakeuser, $supportuser, $subject, $body);
    }
}
