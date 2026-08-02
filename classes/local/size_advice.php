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
 * How big a backup is, and what that means for the browser sandbox.
 *
 * A trial boots by downloading the whole .mbz into the visitor's browser and
 * restoring it there, so file size is the single best predictor of how long
 * "Try it" will appear to do nothing. Nothing in this plugin refuses a large
 * backup — measured 2026-08-02, a 359 MiB course restored successfully in the
 * sandbox — but it took 4 min 15 s, of which 3 min 54 s was a download with no
 * progress reported anywhere, which is indistinguishable from a hung page.
 *
 * The threshold's default is not arbitrary: the Moodle Playground engine
 * downloads a backup browser-side (native fetch, ~35x faster, with a
 * percentage readout) only while it fits MAX_BROWSER_BACKUP_BYTES = 50 MiB
 * (upstream src/blueprint/steps/moodle-restore.js:26, present in the deployed
 * bundle at dist/php-worker.bundle.js). Above that it silently falls back to
 * an in-PHP download over the tcpOverFetch bridge. 50 MiB is therefore the
 * exact point at which a trial stops reporting progress, which is exactly the
 * point at which a visitor deserves to be warned.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class size_advice {
    /**
     * Default warning threshold, bytes: the sandbox engine's own fast-path budget.
     */
    public const DEFAULT_WARN_BYTES = 50 * 1024 * 1024;

    /**
     * Default maximum accepted upload, bytes — the value this plugin has
     * always enforced, now with a setting in front of it.
     */
    public const DEFAULT_MAX_UPLOAD_BYTES = 500 * 1024 * 1024;

    /**
     * The configured threshold above which a trial is called slow, in bytes.
     *
     * A threshold of 0 disables the warning entirely (an admin who does not
     * want visitors told about size), which is why this cannot use the usual
     * `?:` default-on-falsy idiom.
     *
     * @return int bytes; 0 means "never warn"
     */
    public static function warn_threshold(): int {
        $configured = get_config('local_oerexchange', 'sandboxwarnbytes');
        if ($configured === false || $configured === '') {
            return self::DEFAULT_WARN_BYTES;
        }

        return max(0, (int) $configured);
    }

    /**
     * Whether a backup of this size will give a visitor a slow, silent trial.
     *
     * @param int $bytes the .mbz size
     * @return bool
     */
    public static function is_slow_trial(int $bytes): bool {
        $threshold = self::warn_threshold();

        return $threshold > 0 && $bytes > $threshold;
    }

    /**
     * The size above which this site declines to offer a trial at all, in bytes.
     *
     * Zero — the default — means no cap, which is deliberately what an
     * upgrading site keeps: a large trial measurably WORKS (359 MiB booted in
     * 4 min 15 s on 2026-08-02), so turning the button off for everyone would
     * take away something that functions. An admin who would rather not offer
     * a four-minute trial opts in.
     *
     * @return int bytes; 0 means "never refuse"
     */
    public static function max_trial_bytes(): int {
        return max(0, (int) get_config('local_oerexchange', 'sandboxmaxbytes'));
    }

    /**
     * Whether this site refuses to offer an in-browser trial for this size.
     *
     * @param int $bytes the .mbz size
     * @return bool
     */
    public static function is_trial_blocked(int $bytes): bool {
        $max = self::max_trial_bytes();

        return $max > 0 && $bytes > $max;
    }

    /**
     * The largest backup this Exchange accepts at all, in bytes.
     *
     * The single definition of a limit that publish() enforces, the upload
     * pages display, the upload JavaScript pre-checks and get_config()
     * advertises to client sites — it was written out three times before, so
     * a site that changed it could have told a client one number and enforced
     * another.
     *
     * Zero (or unset) means the 500 MB default rather than "unlimited": that
     * is what the `?:` this replaces has always done, and quietly turning an
     * existing 0 into no-limit-at-all on upgrade would be the wrong surprise.
     *
     * @return int bytes
     */
    public static function max_upload_bytes(): int {
        return (int) get_config('local_oerexchange', 'maxbackupbytes') ?: self::DEFAULT_MAX_UPLOAD_BYTES;
    }

    /**
     * Human-readable file size, e.g. "359MB".
     *
     * @param int $bytes
     * @return string
     */
    public static function format(int $bytes): string {
        return display_size($bytes);
    }

    /**
     * The current downloadable size of each of these resources, in one query.
     *
     * Batched for the same reason cover_image::urls_for() is: a catalogue page
     * draws up to PERPAGE cards and must not run a query per card.
     *
     * @param array $resourceids resource ids, as ints or numeric strings
     * @return array<int, int> resourceid => bytes, missing where nothing is ready yet
     */
    public static function sizes_for(array $resourceids): array {
        global $DB;

        $resourceids = array_values(array_unique(array_map('intval', $resourceids)));
        if (!$resourceids) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($resourceids, SQL_PARAMS_NAMED, 'resourceid');
        $versions = $DB->get_records_select(
            'local_oerexchange_versions',
            "resourceid $insql AND status = :status",
            $inparams + ['status' => 'ready'],
            'resourceid ASC, versionnumber ASC',
            'id, resourceid, versionnumber, filesize'
        );

        // Later rows overwrite earlier ones, so ordering by versionnumber
        // ascending leaves the newest ready version's size per resource -
        // the same version download.php and the sandbox actually serve.
        $sizes = [];
        foreach ($versions as $version) {
            $sizes[(int) $version->resourceid] = (int) $version->filesize;
        }

        return $sizes;
    }
}
