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
 * The licences courseware may be shared under on this Exchange.
 *
 * The admin picks the list (allowedlicenses setting); until they save it the
 * Creative Commons set applies. Every publish path — the two direct-upload
 * pages and the publish_resource web service a client site calls — validates
 * against this one list, so restricting it here restricts every future share.
 * Already-published resources keep the licence they were shared under: the
 * list is enforced only when a NEW resource is created, never against the
 * stored licence an update or file replacement carries through.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class allowed_licenses {
    /**
     * The Creative Commons licences as core's license_manager names them
     * (every cc-* licence is a BY variant — core just leaves "BY" out of the
     * shortname), in the display order the share forms offer them.
     */
    const CC_SHORTNAMES = ['cc-4.0', 'cc-sa-4.0', 'cc-nc-4.0', 'cc-nc-sa-4.0', 'cc-nd-4.0', 'cc-nc-nd-4.0'];

    /**
     * Preselected in the share forms when the site default licence
     * ($CFG->sitedefaultlicense) is not usable — ShareAlike is the licence
     * that keeps derivatives shareable, which is the platform's whole point.
     */
    const PREFERRED_DEFAULT = 'cc-sa-4.0';

    /**
     * Default value for the admin setting: the Creative Commons set.
     *
     * @return string comma-separated shortname list, as admin_setting_configmulticheckbox stores it
     */
    public static function default_setting(): string {
        return implode(',', self::CC_SHORTNAMES);
    }

    /**
     * The licence shortnames sharing may currently use, in the admin's
     * configured order.
     *
     * Shortnames core does not know — or knows but has disabled in the site
     * licence manager — are dropped rather than trusted: the stored licence
     * is rendered and filtered all over the catalogue, so only licences the
     * site can actually name may pass. The strict `=== false` is deliberate
     * (same rule as sharetargets): an admin who unticks every box stores '',
     * which must stay "no licence may be chosen" rather than being read as
     * "unset" and silently restoring the CC set.
     *
     * @return string[]
     */
    public static function shortnames(): array {
        global $CFG;
        require_once($CFG->libdir . '/licenselib.php');

        $raw = get_config('local_oerexchange', 'allowedlicenses');
        if ($raw === false) {
            $raw = self::default_setting();
        }
        $chosen = array_filter(array_map('trim', explode(',', (string) $raw)), 'strlen');
        $known = \license_manager::get_active_licenses_as_array();

        return array_values(array_filter($chosen, fn(string $shortname): bool => isset($known[$shortname])));
    }

    /**
     * Dropdown options for the share forms: shortname => core fullname.
     *
     * @return array<string, string>
     */
    public static function menu(): array {
        global $CFG;
        require_once($CFG->libdir . '/licenselib.php');

        $known = \license_manager::get_active_licenses_as_array();

        $menu = [];
        foreach (self::shortnames() as $shortname) {
            $menu[$shortname] = $known[$shortname];
        }

        return $menu;
    }

    /**
     * May a new resource be shared under this licence?
     *
     * @param string $shortname
     * @return bool
     */
    public static function is_allowed(string $shortname): bool {
        return in_array($shortname, self::shortnames(), true);
    }

    /**
     * The licence the share forms preselect, first-allowed-wins:
     *
     * 1. The user's remembered last choice (filepicker_recentlicense —
     *    the same preference core's filepicker keeps), honoured only when
     *    the site's "Remember user licence preference"
     *    ($CFG->rememberuserlicensepref) is on;
     * 2. the site default licence ($CFG->sitedefaultlicense);
     * 3. CC BY-SA;
     * 4. the first allowed licence;
     * 5. null when the admin has allowed none (sharing is then closed).
     *
     * Each candidate counts only if it is on the allowed list. On a stock
     * site the default licence is 'unknown' ("Licence not specified"),
     * which the CC default list never contains — so out of the box this
     * lands on CC BY-SA, and an admin steers it by pointing the site
     * default at a licence they allow.
     *
     * @return string|null
     */
    public static function default_shortname(): ?string {
        global $CFG;

        $shortnames = self::shortnames();
        if (!empty($CFG->rememberuserlicensepref)) {
            $remembered = (string) get_user_preferences('filepicker_recentlicense', '');
            if ($remembered !== '' && in_array($remembered, $shortnames, true)) {
                return $remembered;
            }
        }
        $sitedefault = (string) ($CFG->sitedefaultlicense ?? '');
        if ($sitedefault !== '' && in_array($sitedefault, $shortnames, true)) {
            return $sitedefault;
        }
        if (in_array(self::PREFERRED_DEFAULT, $shortnames, true)) {
            return self::PREFERRED_DEFAULT;
        }

        return $shortnames[0] ?? null;
    }

    /**
     * Remember the licence a user just shared under, so their next share —
     * and core's own filepicker, which reads the same preference — preselects
     * it. A no-op unless the site's "Remember user licence preference"
     * setting is on, mirroring how core treats the preference.
     *
     * @param string $shortname the licence the user chose
     */
    public static function remember(string $shortname): void {
        global $CFG;

        if (!empty($CFG->rememberuserlicensepref)) {
            set_user_preference('filepicker_recentlicense', $shortname);
        }
    }
}
