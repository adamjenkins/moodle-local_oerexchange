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
 * Automatic, threshold-based badge evaluation. Awards are additive and never
 * auto-revoked in v1 (design doc, "Badge computation").
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class badge_manager {
    /** @var string */
    public const BADGE_TRUSTED_CONTRIBUTOR = 'trusted_contributor';

    /** @var int default for badge_trustedcontributor_minresources, shared with settings.php */
    public const DEFAULT_MINRESOURCES = 10;

    /** @var int default for badge_trustedcontributor_mindownloads, shared with settings.php */
    public const DEFAULT_MINDOWNLOADS = 500;

    /** @var float default for badge_trustedcontributor_minrating, shared with settings.php */
    public const DEFAULT_MINRATING = 4.0;

    /**
     * Evaluate all badge rules for a user and award any newly-qualified ones.
     *
     * @param int $userid
     * @return string[] badge keys newly awarded this call (empty if none, including "already held")
     */
    public static function evaluate_and_award(int $userid): array {
        global $DB;

        $already = self::get_badges_for_user($userid);
        $newlyawarded = [];

        if (
            !in_array(self::BADGE_TRUSTED_CONTRIBUTOR, $already, true)
            && self::qualifies_for_trusted_contributor($userid)
        ) {
            $DB->insert_record('local_oerexchange_badges', (object) [
                'userid' => $userid,
                'badgekey' => self::BADGE_TRUSTED_CONTRIBUTOR,
                'timeawarded' => time(),
            ]);
            $newlyawarded[] = self::BADGE_TRUSTED_CONTRIBUTOR;
        }

        return $newlyawarded;
    }

    /**
     * Return the badge keys currently held by a user.
     *
     * @param int $userid
     * @return string[] badge keys currently held
     */
    public static function get_badges_for_user(int $userid): array {
        global $DB;
        return array_values($DB->get_fieldset_select(
            'local_oerexchange_badges',
            'badgekey',
            'userid = ?',
            [$userid]
        ));
    }

    /**
     * Check whether a user currently meets the Trusted Contributor thresholds.
     *
     * @param int $userid
     * @return bool
     */
    protected static function qualifies_for_trusted_contributor(int $userid): bool {
        $metrics = profile_manager::get_metrics($userid);

        // Config reads return false, not the declared setting default, when
        // this plugin was upgraded without a version bump and no admin has
        // yet opened and saved the settings page (admin_apply_default_settings()
        // never ran for these new keys). Fall back explicitly to the same
        // defaults declared in settings.php (which references these same
        // constants as its $defaultsetting args) so an unconfigured install
        // can't silently treat every threshold as 0 and over-award the badge.
        $minresources = self::config_int('badge_trustedcontributor_minresources', self::DEFAULT_MINRESOURCES);
        $mindownloads = self::config_int('badge_trustedcontributor_mindownloads', self::DEFAULT_MINDOWNLOADS);
        $minrating = self::config_float('badge_trustedcontributor_minrating', self::DEFAULT_MINRATING);

        if ($metrics['resourcecount'] < $minresources) {
            return false;
        }
        if ($metrics['downloadtotal'] < $mindownloads) {
            return false;
        }
        // A creator with no reviews yet is not blocked by the rating floor —
        // it only applies once they have at least one rating (settings desc).
        if ($metrics['avgrating'] !== null && $metrics['avgrating'] < $minrating) {
            return false;
        }

        return true;
    }

    /**
     * Read an integer plugin config value, falling back to a default when unset.
     *
     * @param string $name
     * @param int $default
     * @return int
     */
    protected static function config_int(string $name, int $default): int {
        $raw = get_config('local_oerexchange', $name);
        return $raw === false ? $default : (int) $raw;
    }

    /**
     * Read a float plugin config value, falling back to a default when unset.
     *
     * @param string $name
     * @param float $default
     * @return float
     */
    protected static function config_float(string $name, float $default): float {
        $raw = get_config('local_oerexchange', $name);
        return $raw === false ? $default : (float) $raw;
    }
}
