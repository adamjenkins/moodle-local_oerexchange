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

namespace local_oerexchange\task;

use local_oerexchange\local\allowlist\branch_editor;
use local_oerexchange\local\allowlist\branch_selection;
use local_oerexchange\local\allowlist\entry_plan;
use local_oerexchange\local\allowlist\ingest_plan;
use local_oerexchange\local\allowlist\ingestor;
use local_oerexchange\local\allowlist\plugin_meta;

/**
 * Adhoc task: work out what offering one branch's plugins on another would add.
 *
 * Custom data: from, to (dotted branch labels) and dir, the name of the
 * directory under the temp dir that manage_allowlist.php made for the
 * downloads. Queued as the admin who asked, whose session holds that name.
 *
 * Every plugin is a plugins-directory query plus a ZIP download, a few
 * seconds each and up to the fetcher's download timeout for a slow host, so
 * the rollover cannot run inside the request that asks for it: the page sat
 * blank for minutes, holding the admin's session lock. The task writes the
 * plan into the download directory, and the page picks it up from there for
 * the same preview-and-confirm as before. Nothing is written to the allowlist
 * here.
 *
 * Progress is stored per plugin, so the page can show which one is being
 * looked up ({@see \core\output\task_indicator}).
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rollover_preview extends \core\task\adhoc_task {
    use \core\task\stored_progress_task_trait;

    /** File in the download directory that holds the finished plan. */
    public const PLANFILE = 'rolloverplan.ser';

    /** Parent of every download directory, under $CFG->tempdir. */
    public const TEMPDIR = 'local_oerexchange/allowlist/';

    /**
     * A task for one rollover, for the current user.
     *
     * @param string $from dotted branch label to copy the membership of
     * @param string $to dotted branch label being populated
     * @param string $dir download directory name, under TEMPDIR
     * @return self
     */
    public static function create(string $from, string $to, string $dir): self {
        global $USER;

        $task = new self();
        $task->set_custom_data(['from' => $from, 'to' => $to, 'dir' => $dir]);
        $task->set_component('local_oerexchange');
        $task->set_userid((int) $USER->id);
        return $task;
    }

    /**
     * The queued task, rebuilt from its row, for the progress indicator.
     *
     * @param int $id task_adhoc id
     * @return self|null null once the task has finished (its row is deleted)
     */
    public static function load(int $id): ?self {
        global $DB;

        $record = $DB->get_record('task_adhoc', ['id' => $id, 'classname' => '\\' . self::class]);
        if (!$record) {
            return null;
        }
        $task = new self();
        $task->set_id($id);
        $task->set_custom_data(json_decode($record->customdata));
        $task->set_component('local_oerexchange');
        $task->set_userid((int) $record->userid);
        return $task;
    }

    /**
     * Whether a queued task has used up its attempts and will not run again.
     *
     * @param int $id task_adhoc id
     * @return bool
     */
    public static function has_failed(int $id): bool {
        global $DB;

        $record = $DB->get_record('task_adhoc', ['id' => $id], 'attemptsavailable, faildelay');
        return $record && (int) $record->faildelay > 0 && (int) $record->attemptsavailable === 0;
    }

    /**
     * The finished plan in a download directory, if the task has written it.
     *
     * @param string $workdir full path of the download directory
     * @return ingest_plan|null
     */
    public static function read_plan(string $workdir): ?ingest_plan {
        $file = $workdir . '/' . self::PLANFILE;
        if (!is_readable($file)) {
            return null;
        }
        // Only the plan's own classes: the file is ours, but an unserialize
        // that may build any class is one stray write away from code execution.
        $plan = unserialize(file_get_contents($file), [
            'allowed_classes' => [ingest_plan::class, entry_plan::class, plugin_meta::class, branch_selection::class],
        ]);
        return $plan instanceof ingest_plan ? $plan : null;
    }

    #[\Override]
    public function get_name(): string {
        return get_string('task_rolloverpreview', 'local_oerexchange');
    }

    #[\Override]
    public function execute() {
        global $CFG;

        $data = (object) $this->get_custom_data();
        $dir = clean_param((string) ($data->dir ?? ''), PARAM_ALPHANUM);
        $workdir = $CFG->tempdir . '/' . self::TEMPDIR . $dir;
        // The page removes the directory when the admin cancels or starts
        // something else, so its absence means nobody is waiting for this.
        if ($dir === '' || !is_dir($workdir)) {
            mtrace('Allowlist rollover: cancelled before it ran.');
            return;
        }

        $this->start_stored_progress();
        $plan = (new ingestor())->preview(
            $this->editor()->rollover_plan((string) $data->from, (string) $data->to, $workdir, $this->get_progress())
        );

        // Written whole and then renamed, so the page never reads half a plan.
        $file = $workdir . '/' . self::PLANFILE;
        file_put_contents($file . '.part', serialize($plan));
        rename($file . '.part', $file);
        mtrace("Allowlist rollover {$data->from} to {$data->to}: " . count($plan->entries) . ' plugin(s) looked up.');
    }

    /**
     * The branch editor doing the work; a seam for tests, which must not reach the network.
     *
     * @return branch_editor
     */
    protected function editor(): branch_editor {
        return new branch_editor();
    }
}
