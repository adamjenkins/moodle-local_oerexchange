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

namespace local_oerexchange\local\allowlist;

use local_oerexchange\local\sandbox\playground;

/**
 * Editing which Moodle branches an already-allowlisted plugin is offered for.
 *
 * The allowlist stores one row per plugin per branch, each with its own
 * mirrored ZIP, because the sandbox config is emitted per branch
 * (BAKE_PLUGINS_<branch>) and a plugin may legitimately be pinned to a
 * different release on each. That shape is right for the consumers and wrong
 * for the admin, who thinks in terms of "this plugin, these branches" — so
 * this class exists to make branch membership editable without deleting and
 * re-adding the plugin.
 *
 * Two deliberately different sources of truth, because the two operations
 * answer different questions:
 *
 * - {@see add_branch()} copies the release **already pinned** for this plugin.
 *   The admin has reviewed and verified that ZIP; adding 5.0 alongside 5.2
 *   should not silently swap in a different release. It needs no network.
 * - {@see rollover_plan()} re-resolves each plugin's current release from the
 *   plugins directory for the *new* branch, because "Moodle 5.3 exists now"
 *   is exactly the moment a newer release is likely to be the compatible one.
 *   It is a network operation and goes through the same preview-and-confirm
 *   the manual add uses.
 *
 * Both always write the row, marking it {@see ingestor::NOTE_OVERRIDDEN} when
 * the plugin's own version.php disowns the branch, rather than refusing: a
 * plugin whose `supported` array simply was never updated is the common case,
 * and the table keeps showing the warning so the decision stays visible.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class branch_editor {
    /** Same table and file area as {@see ingestor}, whose copies are private. */
    private const TABLE = 'local_oerexchange_pluginallowlist';

    /** File area holding each row's mirrored ZIP, itemid = row id. */
    private const FILEAREA = 'allowlist';

    /** A branch was added for the plugin. */
    public const ADDED = 'added';

    /** The plugin was already listed for that branch; nothing was written. */
    public const EXISTS = 'exists';

    /** No usable source row (or its mirrored ZIP is gone) to copy from. */
    public const NO_SOURCE = 'nosource';

    /**
     * Constructor.
     *
     * Every collaborator is injectable because the rollover talks to the
     * plugins directory and the network, which a unit test must not.
     *
     * @param component_locator|null $locator defaults to the plugins directory
     * @param source_resolver|null $resolver defaults to an HTTP-backed resolver
     * @param zip_inspector|null $inspector defaults to a plain inspector
     */
    public function __construct(
        /** @var component_locator|null where a component's download URL comes from */
        private ?component_locator $locator = null,
        /** @var source_resolver|null turns a URL into a downloaded package */
        private ?source_resolver $resolver = null,
        /** @var zip_inspector|null reads version.php out of a package */
        private ?zip_inspector $inspector = null,
    ) {
        $this->locator ??= new directory_component_locator();
        $this->resolver ??= new source_resolver(new moodle_http_fetcher());
        $this->inspector ??= new zip_inspector();
    }

    /**
     * The branches this plugin is currently listed for.
     *
     * @param string $component frankenstyle name
     * @return string[] dotted branch labels, in deployed order
     */
    public function branches_for(string $component): array {
        global $DB;

        $listed = $DB->get_fieldset_select(self::TABLE, 'moodlebranch', 'component = :component', [
            'component' => $component,
        ]);

        // Deployed order rather than insertion order: the column reads as a
        // range ("5.0, 5.2"), and a jumbled one looks like a data problem.
        return array_values(array_intersect(playground::DEPLOYED_BRANCHES, $listed));
    }

    /**
     * Deployed branches this plugin is NOT listed for yet.
     *
     * @param string $component frankenstyle name
     * @return string[] dotted branch labels
     */
    public function addable_branches(string $component): array {
        return array_values(array_diff(playground::DEPLOYED_BRANCHES, $this->branches_for($component)));
    }

    /**
     * List a plugin for one more branch, reusing the release already pinned.
     *
     * @param string $component frankenstyle name
     * @param string $branch dotted branch label, e.g. "5.0"
     * @param bool $inheritbake whether the new row may inherit the source
     *                          row's bake flag. False for a caller without
     *                          local/oerexchange:managesandbox: extending a
     *                          baked plugin to another branch changes what a
     *                          bundle build ships, which is precisely what
     *                          that capability gates, so such a caller adds
     *                          the branch unbaked rather than being refused
     * @return string one of ADDED, EXISTS or NO_SOURCE
     */
    public function add_branch(string $component, string $branch, bool $inheritbake = true): string {
        global $DB;

        if (!in_array($branch, playground::DEPLOYED_BRANCHES, true)) {
            return self::NO_SOURCE;
        }

        if (in_array($branch, $this->branches_for($component), true)) {
            return self::EXISTS;
        }

        // An active row first: a disabled one is still a valid source of the
        // ZIP, but its status would be inherited by the new row, and copying
        // "disabled" onto a branch the admin just asked for reads as a bug.
        $source = null;
        foreach ($DB->get_records(self::TABLE, ['component' => $component], 'status ASC, id ASC') as $row) {
            $source = $row;
            break;
        }
        if ($source === null) {
            return self::NO_SOURCE;
        }

        $context = \context_system::instance();
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'local_oerexchange', self::FILEAREA, $source->itemid, 'itemid', false);
        $file = reset($files);
        if (!$file) {
            return self::NO_SOURCE;
        }

        $now = time();
        $record = (object) [
            'plugintype' => $source->plugintype,
            'pluginname' => $source->pluginname,
            'component' => $source->component,
            'moodlebranch' => $branch,
            'sourceurl' => $source->sourceurl,
            'sha256' => $source->sha256,
            'status' => $source->status,
            'bake' => $inheritbake ? $source->bake : 0,
            'pluginversion' => $source->pluginversion,
            'pluginrelease' => $source->pluginrelease,
            // A dependency stays a dependency of the same parent, on the new
            // branch's row of that parent when there is one. Falling back to
            // null rather than to the parent's row on another branch: the
            // column means "pulled in by this row", and a cross-branch
            // pointer would make delete()'s dependent check nonsense.
            'parentid' => $this->parent_on_branch($source, $branch),
            'notes' => $this->declines($file, $branch) ? ingestor::NOTE_OVERRIDDEN : '',
            'timecreated' => $now,
            'timemodified' => $now,
            'itemid' => null,
        ];

        $id = (int) $DB->insert_record(self::TABLE, $record);

        $fs->create_file_from_storedfile([
            'contextid' => $context->id,
            'component' => 'local_oerexchange',
            'filearea' => self::FILEAREA,
            'itemid' => $id,
            'filepath' => '/',
            'filename' => $file->get_filename(),
        ], $file);
        $DB->set_field(self::TABLE, 'itemid', $id, ['id' => $id]);

        return self::ADDED;
    }

    /**
     * Components listed for one branch and missing from another.
     *
     * @param string $from dotted branch label to copy the membership of
     * @param string $to dotted branch label being populated
     * @return string[] frankenstyle names, alphabetical
     */
    public function missing_components(string $from, string $to): array {
        global $DB;

        $source = $DB->get_fieldset_select(self::TABLE, 'DISTINCT component', 'moodlebranch = :branch', [
            'branch' => $from,
        ]);
        $target = $DB->get_fieldset_select(self::TABLE, 'DISTINCT component', 'moodlebranch = :branch', [
            'branch' => $to,
        ]);

        $missing = array_values(array_diff($source, $target));
        sort($missing);

        return $missing;
    }

    /**
     * Plan the work of offering everything on one branch on another one.
     *
     * Each plugin is re-resolved from the plugins directory *for the target
     * branch*, so a plugin that published a 5.3-compatible release since it
     * was added gets that release rather than the pinned older one. A plugin
     * the directory cannot resolve becomes an unresolved entry the admin can
     * see and handle by hand, not a silent omission.
     *
     * Nothing is written: the returned plan goes through the same
     * preview-and-confirm as a manual add.
     *
     * @param string $from dotted branch label to copy the membership of
     * @param string $to dotted branch label being populated
     * @param string $workdir writable directory the downloaded ZIPs live in
     *                        until the admin confirms
     * @return ingest_plan
     */
    public function rollover_plan(string $from, string $to, string $workdir): ingest_plan {
        $entries = [];
        $branchnumber = branch_mapper::branch_number($to);

        foreach ($this->missing_components($from, $to) as $component) {
            $url = $this->locator->locate($component, $branchnumber);
            if ($url === null) {
                $entries[] = new entry_plan(
                    $component,
                    entry_plan::UNRESOLVED,
                    entry_plan::PRIMARY,
                    null,
                    null,
                    null,
                    [get_string('allowlistnotindirectory', 'local_oerexchange', $component)]
                );
                continue;
            }

            try {
                $resolved = $this->resolver->resolve($url, $workdir);
                $meta = $this->inspector->inspect($resolved->zipfilepath, $workdir, $resolved->sourceurl);
            } catch (resolution_exception $e) {
                $entries[] = new entry_plan(
                    $component,
                    entry_plan::UNRESOLVED,
                    entry_plan::PRIMARY,
                    null,
                    null,
                    null,
                    [$e->getMessage()]
                );
                continue;
            }

            // Restricted to the target branch deliberately: this operation is
            // "also offer it on 5.3", never "re-decide 5.0 and 5.2 too", which
            // would quietly re-pin branches the admin did not ask about.
            $entries[] = new entry_plan(
                $meta->component,
                entry_plan::ADD,
                entry_plan::PRIMARY,
                $meta,
                $meta->branches([$to], true),
            );
        }

        return new ingest_plan($entries);
    }

    /**
     * Whether the plugin's own version.php disowns this branch.
     *
     * Read from the mirrored ZIP rather than from the stored row: the row
     * keeps the resulting decision (NOTE_OVERRIDDEN), not the declarations it
     * was made from, and re-deriving them is what makes the new row's note
     * honest.
     *
     * @param \stored_file $file the mirrored ZIP
     * @param string $branch dotted branch label
     * @return bool true when the branch is only offered by override
     */
    private function declines(\stored_file $file, string $branch): bool {
        $workdir = make_request_directory();
        $path = $workdir . '/' . $file->get_filename();

        try {
            $file->copy_content_to($path);
            $meta = $this->inspector->inspect($path, $workdir, '');
        } catch (\Throwable $e) {
            // An unreadable mirror must not stop the admin adding a branch;
            // the honest fallback is "we could not check", which is what an
            // override note says anyway.
            return true;
        }

        return in_array($branch, $meta->branches([$branch], true)->declined, true);
    }

    /**
     * The parent row id to record for a copied dependency row.
     *
     * @param \stdClass $source the row being copied from
     * @param string $branch the branch being added
     * @return int|null parent row id on that branch, or null
     */
    private function parent_on_branch(\stdClass $source, string $branch): ?int {
        global $DB;

        if (empty($source->parentid)) {
            return null;
        }

        $parent = $DB->get_record(self::TABLE, ['id' => $source->parentid], 'component');
        if (!$parent) {
            return null;
        }

        $onbranch = $DB->get_record(self::TABLE, [
            'component' => $parent->component,
            'moodlebranch' => $branch,
        ], 'id');

        return $onbranch ? (int) $onbranch->id : null;
    }
}
