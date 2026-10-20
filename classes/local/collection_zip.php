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
 * A whole collection as one zip file: what goes in it, whether the stored zip
 * is still current, and building it in the background.
 *
 * WHY PREBUILT. A collection of course backups can run to hundreds of
 * megabytes, which is not something to compress inside a visitor's request.
 * The zip is built by an adhoc task (task\build_collection_zip) and served
 * as a stored file (collection_download.php).
 *
 * WHY A SIGNATURE. Whether a stored zip is still right depends on a lot of
 * things that change in different places: membership and order, each item's
 * current version, titles, licences and curator notes. Rather than trust
 * every one of those code paths to invalidate it, the zip's filename carries
 * a hash of exactly what it was built from (signature()), and state()
 * compares it with the hash of what the collection holds now. A stale zip is
 * never served; the page says the download is being prepared instead. The
 * known change points still queue a rebuild straight away (queue(),
 * queue_for_resource()), and task\queue_collection_zips sweeps for anything
 * they missed.
 *
 * WHAT GOES IN. The current ready version file of every available item, in
 * the collection's order, plus collection.txt: title, description, and per
 * item its title, author, licence, link and the curator's note. Most OER is
 * Creative Commons, whose licences require attribution, and a folder of
 * bare backup files would separate the material from its terms.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class collection_zip {
    /** @var string Filearea of the built zips; itemid = collection id. */
    const FILEAREA = 'collectionzip';

    /** @var string Prefix of a stored zip's filename; the rest is its signature. */
    const FILENAME_PREFIX = 'collection-';

    /** @var int Default for the collectionzipmaxmb setting. */
    const DEFAULT_MAX_MB = 100;

    /** @var int Default for the collectionzipbusyusers setting. */
    const DEFAULT_BUSY_USERS = 20;

    /** @var int Seconds of activity the busy check looks back over. */
    const BUSY_WINDOW = 300;

    /** @var int Seconds a busy build waits before checking again. */
    const BUSY_RETRY = 600;

    /** @var int Longest a build may be put off for being busy before it runs anyway. */
    const MAX_DEFER = 6 * HOURSECS;

    /** @var int Seconds a queued build waits, so a burst of edits makes one build. */
    const DEBOUNCE = 60;

    /** @var string No zip: the admin set the size limit to 0. */
    const STATE_DISABLED = 'disabled';

    /** @var string No zip: draft, taken down or deleted. */
    const STATE_UNAVAILABLE = 'unavailable';

    /** @var string No zip: nothing downloadable in the collection. */
    const STATE_EMPTY = 'empty';

    /** @var string No zip: over the size limit. */
    const STATE_TOOLARGE = 'toolarge';

    /** @var string The stored zip is current and can be served. */
    const STATE_READY = 'ready';

    /** @var string No current zip yet; one has been or will be queued. */
    const STATE_PENDING = 'pending';

    /**
     * The size limit in bytes, or 0 when collection downloads are switched off.
     *
     * @return int
     */
    public static function max_bytes(): int {
        $raw = get_config('local_oerexchange', 'collectionzipmaxmb');
        $mb = $raw === false || $raw === '' ? self::DEFAULT_MAX_MB : (int) $raw;

        return max(0, $mb) * 1024 * 1024;
    }

    /**
     * Whether a collection is in a state where a zip may exist at all:
     * live, not taken down, and public or unlisted (a draft is its owner's
     * work in progress, and building zips of every draft would be waste).
     *
     * @param \stdClass $collection
     * @return bool
     */
    public static function buildable(\stdClass $collection): bool {
        return (string) $collection->status === collection_manager::STATUS_ACTIVE
            && empty($collection->modhidden)
            && in_array((string) $collection->visibility, [
                collection_manager::VISIBILITY_PUBLIC,
                collection_manager::VISIBILITY_UNLISTED,
            ], true);
    }

    /**
     * What would go in the zip: every available item that has a ready
     * version, in the collection's order.
     *
     * Two queries whatever the size: the items (get_items()) and the newest
     * ready version of all their resources at once.
     *
     * @param int $collectionid
     * @return \stdClass[] each with ->item, ->resource and ->version, in order
     */
    public static function entries(int $collectionid): array {
        global $DB;

        $items = collection_manager::get_items($collectionid);
        if (!$items) {
            return [];
        }

        $resourceids = [];
        foreach ($items as $item) {
            $resourceids[] = (int) $item->resourceid;
        }
        [$insql, $params] = $DB->get_in_or_equal($resourceids, SQL_PARAMS_NAMED, 'res');
        $versions = $DB->get_records_select(
            'local_oerexchange_versions',
            "resourceid {$insql} AND status = :ready",
            $params + ['ready' => 'ready'],
            'resourceid ASC, versionnumber DESC'
        );
        // The newest ready version per resource, as get_current_version() picks it.
        $current = [];
        foreach ($versions as $version) {
            $rid = (int) $version->resourceid;
            if (!isset($current[$rid])) {
                $current[$rid] = $version;
            }
        }

        $entries = [];
        foreach ($items as $item) {
            $rid = (int) $item->resourceid;
            if (empty($item->available) || !isset($current[$rid])) {
                continue;
            }
            $entries[] = (object) ['item' => $item, 'resource' => $item->resource, 'version' => $current[$rid]];
        }

        return $entries;
    }

    /**
     * Combined size of the files the zip would hold.
     *
     * @param \stdClass[] $entries from entries()
     * @return int bytes
     */
    public static function total_bytes(array $entries): int {
        $total = 0;
        foreach ($entries as $entry) {
            $total += (int) $entry->version->filesize;
        }

        return $total;
    }

    /**
     * A hash of everything the zip is built from. Any change to it means the
     * stored zip is out of date.
     *
     * @param \stdClass $collection
     * @param \stdClass[] $entries from entries()
     * @return string sha1 hex
     */
    public static function signature(\stdClass $collection, array $entries): string {
        $parts = [
            'title' => (string) $collection->title,
            'summary' => (string) ($collection->summary ?? ''),
            'items' => [],
        ];
        foreach ($entries as $entry) {
            $parts['items'][] = [
                (int) $entry->resource->id,
                (int) $entry->version->id,
                (string) $entry->resource->title,
                (string) $entry->resource->licenseshortname,
                (int) $entry->resource->creatorid,
                (string) ($entry->item->note ?? ''),
            ];
        }

        return sha1(json_encode($parts));
    }

    /**
     * The stored zip's filename for a signature.
     *
     * @param string $signature
     * @return string
     */
    public static function filename(string $signature): string {
        return self::FILENAME_PREFIX . substr($signature, 0, 20) . '.zip';
    }

    /**
     * Where the collection's download stands.
     *
     * @param \stdClass $collection
     * @return \stdClass ->state (a STATE_ constant), ->bytes (combined size), ->file (stored_file when ready)
     */
    public static function state(\stdClass $collection): \stdClass {
        $result = (object) ['state' => self::STATE_UNAVAILABLE, 'bytes' => 0, 'file' => null];

        $max = self::max_bytes();
        if ($max === 0) {
            $result->state = self::STATE_DISABLED;
            return $result;
        }
        if (!self::buildable($collection)) {
            return $result;
        }

        $entries = self::entries((int) $collection->id);
        if (!$entries) {
            $result->state = self::STATE_EMPTY;
            return $result;
        }
        $result->bytes = self::total_bytes($entries);
        if ($result->bytes > $max) {
            $result->state = self::STATE_TOOLARGE;
            return $result;
        }

        $file = get_file_storage()->get_file(
            \context_system::instance()->id,
            'local_oerexchange',
            self::FILEAREA,
            (int) $collection->id,
            '/',
            self::filename(self::signature($collection, $entries))
        );
        if ($file) {
            $result->state = self::STATE_READY;
            $result->file = $file;
        } else {
            $result->state = self::STATE_PENDING;
        }

        return $result;
    }

    /**
     * Queue a (re)build of one collection's zip.
     *
     * Rescheduled rather than added: a second edit within DEBOUNCE seconds
     * pushes the one queued build later instead of queueing another, so
     * reordering ten items builds once.
     *
     * @param int $collectionid
     * @return void
     */
    public static function queue(int $collectionid): void {
        if (self::max_bytes() === 0) {
            return;
        }
        $task = new \local_oerexchange\task\build_collection_zip();
        $task->set_custom_data(['collectionid' => $collectionid]);
        $task->set_component('local_oerexchange');
        $task->set_next_run_time(time() + self::DEBOUNCE);
        \core\task\manager::reschedule_or_queue_adhoc_task($task);
    }

    /**
     * Queue a rebuild for every collection a resource is in.
     *
     * Called where a resource changes in a way a zip carries: a new version,
     * a title, being hidden.
     *
     * @param int $resourceid
     * @return void
     */
    public static function queue_for_resource(int $resourceid): void {
        global $DB;

        if (self::max_bytes() === 0 || !$DB->get_manager()->table_exists(collection_manager::ITEM_TABLE)) {
            return;
        }
        $ids = $DB->get_fieldset_sql(
            "SELECT DISTINCT i.collectionid
               FROM {" . collection_manager::ITEM_TABLE . "} i
               JOIN {" . collection_manager::TABLE . "} c ON c.id = i.collectionid
              WHERE i.resourceid = :resourceid AND c.status = :active",
            ['resourceid' => $resourceid, 'active' => collection_manager::STATUS_ACTIVE]
        );
        foreach ($ids as $id) {
            self::queue((int) $id);
        }
    }

    /**
     * Whether the site is too busy to build zips right now: at least the
     * configured number of signed-in users active in the last BUSY_WINDOW
     * seconds. 0 means never too busy.
     *
     * Read from the sessions table, so on a site whose session handler does
     * not use the database this always answers "not busy".
     *
     * @return bool
     */
    public static function is_busy(): bool {
        global $DB;

        $raw = get_config('local_oerexchange', 'collectionzipbusyusers');
        $threshold = $raw === false || $raw === '' ? self::DEFAULT_BUSY_USERS : (int) $raw;
        if ($threshold <= 0) {
            return false;
        }
        $active = $DB->count_records_select(
            'sessions',
            'timemodified > :since AND userid > 0',
            ['since' => time() - self::BUSY_WINDOW]
        );

        return $active >= $threshold;
    }

    /**
     * Build a collection's zip if it is due, and remove any outdated one.
     *
     * @param int $collectionid
     * @return string what happened: one of the STATE_ constants, or 'built'
     */
    public static function build(int $collectionid): string {
        $collection = collection_manager::get($collectionid);
        if (!$collection) {
            return self::STATE_UNAVAILABLE;
        }

        $state = self::state($collection);
        if ($state->state !== self::STATE_PENDING) {
            if ($state->state !== self::STATE_READY) {
                // Nothing should be served for this collection now.
                self::delete($collectionid);
            }
            return $state->state;
        }

        $entries = self::entries($collectionid);
        $signature = self::signature($collection, $entries);
        $filename = self::filename($signature);

        $files = [];
        $used = [];
        $position = 0;
        foreach ($entries as $entry) {
            $stored = resource_manager::get_version_file((int) $entry->version->id);
            if (!$stored) {
                continue;
            }
            $position++;
            $files[self::entry_name($position, $entry, $used)] = $stored;
        }
        $files['collection.txt'] = [self::manifest($collection, $entries)];

        $contextid = \context_system::instance()->id;
        $packer = get_file_packer('application/zip');
        $zip = $packer->archive_to_storage($files, $contextid, 'local_oerexchange', self::FILEAREA, $collectionid, '/', $filename);
        if (!$zip) {
            return self::STATE_PENDING;
        }

        // Only now drop the previous zip, so there is never a moment with
        // neither the old nor the new one stored.
        $stored = get_file_storage()->get_area_files($contextid, 'local_oerexchange', self::FILEAREA, $collectionid, 'id', false);
        foreach ($stored as $old) {
            if ($old->get_filename() !== $filename) {
                $old->delete();
            }
        }

        return 'built';
    }

    /**
     * Delete a collection's stored zips.
     *
     * @param int $collectionid
     * @return void
     */
    public static function delete(int $collectionid): void {
        get_file_storage()->delete_area_files(
            \context_system::instance()->id,
            'local_oerexchange',
            self::FILEAREA,
            $collectionid
        );
    }

    /**
     * The name of one item inside the zip: its position, title and the
     * extension of the file the author uploaded, made safe for every OS.
     *
     * @param int $position 1-based
     * @param \stdClass $entry from entries()
     * @param array $used names already taken, updated in place
     * @return string
     */
    protected static function entry_name(int $position, \stdClass $entry, array &$used): string {
        $title = content_to_text(
            format_string($entry->resource->title, true, ['context' => \context_system::instance()]),
            FORMAT_HTML
        );
        $title = trim(clean_filename(\core_text::substr(trim($title), 0, 80)), '. ');
        if ($title === '') {
            $title = 'resource-' . (int) $entry->resource->id;
        }
        $extension = pathinfo((string) $entry->version->filename, PATHINFO_EXTENSION);
        $name = sprintf('%02d - %s', $position, $title) . ($extension !== '' ? '.' . clean_filename($extension) : '');
        while (isset($used[$name])) {
            $name = sprintf('%02d - %s (%d)', $position, $title, (int) $entry->resource->id)
                . ($extension !== '' ? '.' . clean_filename($extension) : '');
        }
        $used[$name] = true;

        return $name;
    }

    /**
     * The plain-text manifest that travels with the files: what the
     * collection is, and for each item its title, author, licence, link and
     * the curator's note.
     *
     * @param \stdClass $collection
     * @param \stdClass[] $entries from entries()
     * @return string
     */
    public static function manifest(\stdClass $collection, array $entries): string {
        global $DB;

        // Filter first (a multilang title or description collapses to one
        // language), then flatten to plain text: the manifest is a .txt file,
        // so neither markup nor entities belong in it.
        $context = \context_system::instance();
        $plain = fn(string $value): string => trim(content_to_text(
            format_string($value, true, ['context' => $context]),
            FORMAT_HTML
        ));

        $creatorids = array_filter(array_unique(array_map(fn($e) => (int) $e->resource->creatorid, $entries)));
        $creators = $creatorids ? $DB->get_records_list('user', 'id', $creatorids) : [];

        $lines = [];
        $lines[] = $plain((string) $collection->title);
        $lines[] = str_repeat('=', 60);
        $summary = trim(content_to_text(
            format_text((string) ($collection->summary ?? ''), (int) ($collection->summaryformat ?? FORMAT_HTML), [
                'context' => $context,
                'para' => false,
            ]),
            FORMAT_HTML
        ));
        if ($summary !== '') {
            $lines[] = $summary;
        }
        $lines[] = get_string(
            'collectionzipmanifestlink',
            'local_oerexchange',
            \local_oerexchange\route\controller\collection_controller::collection_url((string) $collection->slug)->out(false)
        );
        $lines[] = '';

        foreach (array_values($entries) as $index => $entry) {
            $resource = $entry->resource;
            $creator = $creators[(int) $resource->creatorid] ?? null;
            $lines[] = sprintf('%02d. %s', $index + 1, $plain((string) $resource->title));
            $lines[] = '    ' . get_string(
                'collectionzipmanifestauthor',
                'local_oerexchange',
                $creator ? fullname($creator) : get_string('collectionzipmanifestunknownauthor', 'local_oerexchange')
            );
            $licence = (string) $resource->licenseshortname;
            $lines[] = '    ' . get_string('collectionzipmanifestlicence', 'local_oerexchange', $licence);
            $lines[] = '    ' . get_string(
                'collectionzipmanifestresource',
                'local_oerexchange',
                (new \moodle_url('/local/oerexchange/resource.php', ['id' => $resource->id]))->out(false)
            );
            $note = trim((string) ($entry->item->note ?? ''));
            if ($note !== '') {
                $lines[] = '    ' . get_string('collectionzipmanifestnote', 'local_oerexchange', $note);
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Collections whose zip should be rebuilt: buildable, and with no current
     * zip. For the background sweep.
     *
     * @param int $limit most collections to check in one sweep
     * @return int[] collection ids
     */
    public static function pending_collection_ids(int $limit = 500): array {
        global $DB;

        if (self::max_bytes() === 0) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(
            [collection_manager::VISIBILITY_PUBLIC, collection_manager::VISIBILITY_UNLISTED],
            SQL_PARAMS_NAMED,
            'vis'
        );
        $collections = $DB->get_records_select(
            collection_manager::TABLE,
            "status = :active AND modhidden = 0 AND visibility {$insql}",
            $params + ['active' => collection_manager::STATUS_ACTIVE],
            'timemodified DESC',
            '*',
            0,
            $limit
        );

        $pending = [];
        foreach ($collections as $collection) {
            if (self::state($collection)->state === self::STATE_PENDING) {
                $pending[] = (int) $collection->id;
            }
        }

        return $pending;
    }
}
