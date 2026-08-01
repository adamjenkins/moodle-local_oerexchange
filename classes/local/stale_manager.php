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
 * The abandoned-courseware lifecycle.
 *
 * Every resource carries a freshness clock (resources.timefresh), wound
 * forward by publishing, by every new version, and by the author's one-click
 * "Still fresh" confirmation. When the feature is enabled and a published
 * resource's clock falls further behind than the configured threshold, the
 * author is warned once (resources.stalenotifiedtime records when) and the
 * grace period starts; if the clock is still behind when the grace period
 * ends, the resource is removed from the catalogue.
 *
 * Two deliberate boundaries:
 *
 * 1. Removal writes the existing 'removed' status — the same takedown a
 *    moderator writes, never a new status value — so moderate.php's
 *    "Moderated resources" list offers Restore on it and an over-eager
 *    automatic removal is always reversible by a human. The author-facing
 *    "never write modhidden/removed" rule is not violated: this is system
 *    janitor code acting for the site, not an author action.
 * 2. Resources with no reachable author (creatorid 0 after a tombstone, or
 *    a deleted account) are skipped entirely. The feature's contract is
 *    "the author gets the opportunity to act"; with nobody to warn, removal
 *    stays a human moderator's call.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stale_manager {
    /** Default staleness threshold: unmaintained for two years. */
    const DEFAULT_THRESHOLD = 2 * YEARSECS;

    /** Default grace period after the warning: sixty days ("two months"). */
    const DEFAULT_GRACE = 60 * DAYSECS;

    /**
     * Whether the admin has switched the feature on.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return (bool) get_config('local_oerexchange', 'staleenabled');
    }

    /**
     * Seconds of inactivity after which a resource counts as abandoned.
     *
     * @return int
     */
    public static function threshold(): int {
        $value = (int) get_config('local_oerexchange', 'stalethreshold');
        return $value > 0 ? $value : self::DEFAULT_THRESHOLD;
    }

    /**
     * Seconds between the warning and the automatic removal.
     *
     * @return int
     */
    public static function grace(): int {
        $value = (int) get_config('local_oerexchange', 'stalegrace');
        return $value > 0 ? $value : self::DEFAULT_GRACE;
    }

    /**
     * Wind the freshness clock forward and cancel any pending removal.
     *
     * Called by the author's "Still fresh" button, and by moderate.php when
     * a moderator restores a removed resource — without that, a restored
     * resource would still carry an expired grace clock and the next cron
     * run would remove it right back.
     *
     * @param \stdClass $resource a row from local_oerexchange_resources
     */
    public static function mark_fresh(\stdClass $resource): void {
        global $DB;

        $DB->update_record('local_oerexchange_resources', (object) [
            'id' => $resource->id,
            'timefresh' => time(),
            'stalenotifiedtime' => 0,
        ]);
    }

    /**
     * When the flagged resource will be removed unless the author acts.
     *
     * @param \stdClass $resource a flagged row (stalenotifiedtime > 0)
     * @return int unix timestamp
     */
    public static function removal_deadline(\stdClass $resource): int {
        return (int) $resource->stalenotifiedtime + self::grace();
    }

    /**
     * The nightly sweep. Three passes, in an order chosen so that a
     * just-warned resource always survives at least one task interval even
     * with a pathological zero grace period: unflag, then remove, then warn.
     *
     * @return array{unflagged: int, removed: int, notified: int}
     */
    public static function run_checks(): array {
        global $DB;

        $summary = ['unflagged' => 0, 'removed' => 0, 'notified' => 0];
        if (!self::enabled()) {
            return $summary;
        }

        $now = time();
        $stalebefore = $now - self::threshold();
        $gracebefore = $now - self::grace();

        // Pass 1 — unflag anything the current configuration no longer calls
        // stale (the admin raised the threshold after warnings went out).
        // Writers of timefresh already reset the flag, so this is mostly the
        // threshold-change case.
        $tounflag = $DB->get_records_select(
            'local_oerexchange_resources',
            "status = 'published' AND stalenotifiedtime > 0 AND timefresh > ?",
            [$stalebefore],
            '',
            'id'
        );
        foreach ($tounflag as $row) {
            $DB->set_field('local_oerexchange_resources', 'stalenotifiedtime', 0, ['id' => $row->id]);
            $summary['unflagged']++;
        }

        // Pass 2 — remove flagged resources whose grace ran out and which are
        // still past the threshold. Only 'published' rows: an author hide or
        // moderator takedown during the grace period already took the
        // resource off the catalogue, and this janitor must not overwrite
        // that more specific state. The live-author join mirrors pass 3 —
        // boundary 2 above holds at removal time too, not just at warning
        // time: an author whose account was deleted DURING the grace period
        // leaves the resource authorless, and authorless removal stays a
        // human moderator's call.
        $toremove = $DB->get_records_sql(
            "SELECT r.*
               FROM {local_oerexchange_resources} r
               JOIN {user} u ON u.id = r.creatorid AND u.deleted = 0
              WHERE r.status = 'published' AND r.creatorid > 0
                    AND r.stalenotifiedtime > 0 AND r.stalenotifiedtime <= ?
                    AND r.timefresh <= ?",
            [$gracebefore, $stalebefore]
        );
        foreach ($toremove as $resource) {
            $DB->update_record('local_oerexchange_resources', (object) [
                'id' => $resource->id,
                'status' => 'removed',
                'timemodified' => $now,
            ]);
            self::notify($resource, 'staleremoved');
            $summary['removed']++;
        }

        // Pass 3 — warn authors of newly stale resources and start their
        // grace clocks. The flag is set even if the message could not be
        // delivered: the clock measures abandonment, and an unreachable
        // author must not mean "warn forever, never proceed".
        $tonotify = $DB->get_records_sql(
            "SELECT r.*
               FROM {local_oerexchange_resources} r
               JOIN {user} u ON u.id = r.creatorid AND u.deleted = 0
              WHERE r.status = 'published' AND r.stalenotifiedtime = 0
                    AND r.creatorid > 0 AND r.timefresh <= ?",
            [$stalebefore]
        );
        foreach ($tonotify as $resource) {
            $DB->set_field('local_oerexchange_resources', 'stalenotifiedtime', $now, ['id' => $resource->id]);
            // The deadline in the message must match the flag just written.
            $resource->stalenotifiedtime = $now;
            self::notify($resource, 'stalewarning');
            $summary['notified']++;
        }

        return $summary;
    }

    /**
     * Send one lifecycle notification to a resource's author.
     *
     * @param \stdClass $resource
     * @param string $stringkey 'stalewarning' or 'staleremoved' — picks the
     *                          notify_<key>_subject/_body string pair
     */
    protected static function notify(\stdClass $resource, string $stringkey): void {
        global $DB;

        $creator = $DB->get_record('user', ['id' => $resource->creatorid, 'deleted' => 0]);
        if (!$creator) {
            return;
        }

        $url = new \moodle_url('/local/oerexchange/resource.php', ['id' => $resource->id]);
        // Filter the title, then flatten it back to plain text — see
        // coauthor_manager::notify_added() for why a FORMAT_PLAIN message
        // needs both halves of that.
        $title = content_to_text(
            format_string($resource->title, true, ['context' => \context_system::instance()]),
            FORMAT_HTML
        );
        $a = (object) [
            'title' => $title,
            'url' => $url->out(false),
            'deadline' => userdate(self::removal_deadline($resource)),
        ];

        $message = new \core\message\message();
        $message->component = 'local_oerexchange';
        $message->name = 'stale';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $creator;
        $message->subject = get_string('notify' . $stringkey . 'subject', 'local_oerexchange', $a);
        $message->fullmessage = get_string('notify' . $stringkey . 'body', 'local_oerexchange', $a);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $message->subject;
        $message->notification = 1;
        $message->contexturl = $a->url;
        $message->contexturlname = $title;
        message_send($message);
    }
}
