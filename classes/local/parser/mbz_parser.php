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

namespace local_oerexchange\local\parser;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/backup/util/includes/backup_includes.php');

/**
 * Thin wrapper over core's backup_general_helper::get_backup_information_from_mbz(),
 * producing a structure-preview JSON tree and a required-plugins list.
 *
 * See DESIGN.md §2 (mbz_parser) — validated against Moodle 5.2.1 source:
 * backup/moodle2/backup_stepslib.php's contents manifest gives each activity
 * {moduleid, sectionid, modulename, title, directory, insubsection} and each
 * section {sectionid, title, directory, parentcmid, modname}.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mbz_parser {
    /**
     * Parse an .mbz file into a structure preview + required-plugins list.
     *
     * @param string $filepath absolute path to the .mbz file
     * @return \stdClass {moodleversion, backupversion, type, courseformat, structurejson (string), requiredplugins (array)}
     * @throws \backup_helper_exception on a malformed/unreadable backup
     */
    public static function parse(string $filepath): \stdClass {
        $info = \backup_general_helper::get_backup_information_from_mbz($filepath);

        $sections = [];
        foreach ($info->sections as $section) {
            $sections[$section->sectionid] = [
                'sectionid' => (int) $section->sectionid,
                'title' => $section->title,
                'activities' => [],
            ];
        }

        foreach ($info->activities as $activity) {
            $sectionid = (int) $activity->sectionid;
            if (!isset($sections[$sectionid])) {
                // Orphaned activity (shouldn't normally happen) — bucket it separately.
                $sections[$sectionid] = [
                    'sectionid' => $sectionid,
                    'title' => null,
                    'activities' => [],
                ];
            }
            $sections[$sectionid]['activities'][] = [
                'modulename' => $activity->modulename,
                'title' => $activity->title,
            ];
        }

        $courseformat = $info->original_course_format ?? '';

        $structure = [
            'coursetitle' => $info->course->title ?? null,
            'courseformat' => $courseformat,
            'type' => $info->type ?? null,
            'sections' => array_values($sections),
        ];

        return (object) [
            'moodleversion' => (string) ($info->moodle_release ?? $info->moodle_version ?? ''),
            'backupversion' => (string) ($info->backup_release ?? $info->backup_version ?? ''),
            'type' => $info->type ?? null,
            'courseformat' => $courseformat,
            'structurejson' => json_encode($structure),
            'requiredplugins' => self::derive_required_plugins($info, $courseformat),
        ];
    }

    /**
     * Compare the backup's activity modnames + course format against Moodle's
     * shipped standard-plugins list for this site's Moodle version, to find
     * non-core components the backup depends on.
     *
     * V1 scope: activity modules + course format only (both are top-level in
     * moodle_backup.xml's contents manifest). Question types, blocks, and
     * filters would need deeper archive parsing and are deliberately deferred
     * — see DESIGN.md §2.
     *
     * @param \stdClass $info
     * @param string $courseformat
     * @return array list of ['type' => string, 'name' => string]
     */
    protected static function derive_required_plugins(\stdClass $info, string $courseformat): array {
        $standardmods = \core\plugin_manager::standard_plugins_list('mod') ?: [];
        $standardformats = \core\plugin_manager::standard_plugins_list('format') ?: [];

        $required = [];
        $seen = [];

        foreach ($info->activities as $activity) {
            $modname = $activity->modulename;
            if (!$modname || in_array($modname, $standardmods, true)) {
                continue;
            }
            $key = 'mod:' . $modname;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $required[] = ['type' => 'mod', 'name' => $modname];
        }

        if ($courseformat !== '' && !in_array($courseformat, $standardformats, true)) {
            $required[] = ['type' => 'format', 'name' => $courseformat];
        }

        return $required;
    }
}
