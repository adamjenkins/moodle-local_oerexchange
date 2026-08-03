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
 * The record behind the moderator-only report of held resources.
 *
 * A takedown used to write nothing but the status, so nobody could later say
 * when a resource was hidden, by whom, or whether its author had changed it
 * since. This class writes that record and reads it back.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moderation_report {
    /**
     * Take a resource down and record who did it, when, and against what.
     *
     * The version id is the anchor the report uses to decide whether the
     * content has changed since. resources.timemodified cannot answer that on
     * its own: it does not move when the cover image or the co-author list
     * changes, and it does move for a hide or unhide, so it both misses real
     * changes and invents ones.
     *
     * @param int $resourceid
     * @param string $status 'modhidden' for a takedown, 'removed' for a removal
     * @return void
     */
    public static function record_takedown(int $resourceid, string $status): void {
        global $DB, $USER;

        $version = resource_manager::get_current_version($resourceid);

        $DB->update_record('local_oerexchange_resources', (object) [
            'id' => $resourceid,
            'status' => $status,
            'modhiddentime' => time(),
            'modhiddenby' => (int) $USER->id,
            'modhiddenversionid' => $version ? (int) $version->id : null,
        ]);
    }

    /**
     * How many resources a moderator is currently holding.
     *
     * Counts 'modhidden' only. 'removed' is deliberately excluded: the
     * stale-courseware janitor writes that status automatically
     * (stale_manager), so counting it would report resources no moderator ever
     * touched under a heading that says a moderator hid them.
     *
     * @return int
     */
    public static function hidden_count(): int {
        global $DB;

        return $DB->count_records('local_oerexchange_resources', ['status' => 'modhidden']);
    }

    /**
     * Every moderator-hidden resource, newest takedown first, with its note.
     *
     * @return \stdClass[] resource rows, each carrying: modnote, modnotetime,
     *      modnoteby, and changedsincehidden
     */
    public static function hidden_resources(): array {
        global $DB;

        $rows = $DB->get_records('local_oerexchange_resources', ['status' => 'modhidden'], 'modhiddentime DESC, id DESC');
        if (!$rows) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($rows), SQL_PARAMS_NAMED, 'rid');
        $notes = $DB->get_records_select('local_oerexchange_modnotes', "resourceid $insql", $params);
        $notesbyresource = [];
        foreach ($notes as $note) {
            $notesbyresource[(int) $note->resourceid] = $note;
        }

        foreach ($rows as $row) {
            $note = $notesbyresource[(int) $row->id] ?? null;
            $row->modnote = $note->note ?? '';
            $row->modnotetime = $note ? (int) $note->timemodified : 0;
            $row->modnoteby = $note ? (int) $note->usermodified : 0;
            $row->changedsincehidden = self::has_changed_since_hidden($row);
        }

        return $rows;
    }

    /**
     * Whether a held resource has changed since the moment it was taken down.
     *
     * Two independent signals, either of which counts: a different served
     * version (the author replaced the file) or a later timemodified (they
     * edited the details). A resource taken down before this record existed
     * has modhiddentime 0, and is reported as unknown rather than changed —
     * saying "changed" of something we cannot actually compare would be worse
     * than admitting the gap.
     *
     * @param \stdClass $resource a resources row
     * @return bool|null true/false, or null when there is no takedown record to compare against
     */
    public static function has_changed_since_hidden(\stdClass $resource): ?bool {
        if (empty($resource->modhiddentime)) {
            return null;
        }

        $current = resource_manager::get_current_version((int) $resource->id);
        $currentversionid = $current ? (int) $current->id : null;
        $hiddenversionid = isset($resource->modhiddenversionid) ? (int) $resource->modhiddenversionid : null;
        if ($currentversionid !== $hiddenversionid) {
            return true;
        }

        return (int) $resource->timemodified > (int) $resource->modhiddentime;
    }

    /**
     * Read one resource's moderator note.
     *
     * @param int $resourceid
     * @return \stdClass|null the note row, or null if none has been written
     */
    public static function get_note(int $resourceid): ?\stdClass {
        global $DB;

        $note = $DB->get_record('local_oerexchange_modnotes', ['resourceid' => $resourceid]);

        return $note ?: null;
    }

    /**
     * Write one resource's moderator note, replacing whatever was there.
     *
     * The note is shared working state rather than a per-moderator comment —
     * every moderator reads and edits the same one — so saving records who
     * touched it last rather than appending a new row.
     *
     * An empty note deletes the row instead of storing a blank, so the report
     * can distinguish "nobody has written anything" from "somebody wrote
     * something and then cleared it", which would otherwise both render as an
     * empty box with a misleading last-edited date.
     *
     * @param int $resourceid
     * @param string $note
     * @return void
     */
    public static function save_note(int $resourceid, string $note): void {
        global $DB, $USER;

        $note = trim($note);
        $existing = self::get_note($resourceid);

        if ($note === '') {
            if ($existing) {
                $DB->delete_records('local_oerexchange_modnotes', ['id' => $existing->id]);
            }
            return;
        }

        $now = time();
        if ($existing) {
            $DB->update_record('local_oerexchange_modnotes', (object) [
                'id' => $existing->id,
                'note' => $note,
                'usermodified' => (int) $USER->id,
                'timemodified' => $now,
            ]);
            return;
        }

        $DB->insert_record('local_oerexchange_modnotes', (object) [
            'resourceid' => $resourceid,
            'note' => $note,
            'usermodified' => (int) $USER->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }
}
