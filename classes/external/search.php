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

namespace local_oerexchange\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * local_oerexchange_search external function.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search extends external_api {
    /**
     * Describes the parameters this function accepts.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'Free-text search', VALUE_DEFAULT, ''),
            'type' => new external_value(PARAM_ALPHA, 'course|activity|"" for both', VALUE_DEFAULT, ''),
            'license' => new external_value(PARAM_TEXT, 'License shortname filter', VALUE_DEFAULT, ''),
            'language' => new external_value(PARAM_TEXT, 'Language filter', VALUE_DEFAULT, ''),
            'page' => new external_value(PARAM_INT, 'Zero-based page number', VALUE_DEFAULT, 0),
            'perpage' => new external_value(PARAM_INT, 'Results per page (max 50)', VALUE_DEFAULT, 20),
        ]);
    }

    /**
     * Searches published resources by free text, type, license, and language, paginated.
     *
     * @param string $query
     * @param string $type
     * @param string $license
     * @param string $language
     * @param int $page
     * @param int $perpage
     * @return array
     */
    public static function execute(
        string $query = '',
        string $type = '',
        string $license = '',
        string $language = '',
        int $page = 0,
        int $perpage = 20
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'query' => $query, 'type' => $type, 'license' => $license,
            'language' => $language, 'page' => $page, 'perpage' => $perpage,
        ]);
        self::validate_context(\context_system::instance());

        $perpage = min(max($params['perpage'], 1), 50);
        $page = max($params['page'], 0);

        $where = ['status = :status'];
        $sqlparams = ['status' => 'published'];

        if ($params['type'] !== '') {
            $where[] = 'type = :type';
            $sqlparams['type'] = $params['type'];
        }
        if ($params['license'] !== '') {
            $where[] = 'licenseshortname = :license';
            $sqlparams['license'] = $params['license'];
        }
        if ($params['language'] !== '') {
            $where[] = 'language = :language';
            $sqlparams['language'] = $params['language'];
        }
        if ($params['query'] !== '') {
            $like = $DB->sql_like('title', ':q1', false) . ' OR ' . $DB->sql_like('summary', ':q2', false)
                . ' OR ' . $DB->sql_like('tags', ':q3', false);
            $where[] = "({$like})";
            $needle = '%' . $DB->sql_like_escape($params['query']) . '%';
            $sqlparams['q1'] = $needle;
            $sqlparams['q2'] = $needle;
            $sqlparams['q3'] = $needle;
        }

        $wheresql = implode(' AND ', $where);
        $total = (int) $DB->count_records_select('local_oerexchange_resources', $wheresql, $sqlparams);
        $records = $DB->get_records_select(
            'local_oerexchange_resources',
            $wheresql,
            $sqlparams,
            'timeshared DESC',
            '*',
            $page * $perpage,
            $perpage
        );

        $results = [];
        foreach ($records as $r) {
            $results[] = [
                'id' => (int) $r->id,
                'type' => $r->type,
                'title' => $r->title,
                'summary' => $r->summary ?? '',
                'language' => $r->language ?? '',
                'licenseshortname' => $r->licenseshortname,
                'activitytype' => $r->activitytype ?? '',
                'courseformat' => $r->courseformat ?? '',
                'downloadcount' => (int) $r->downloadcount,
                'importcount' => (int) $r->importcount,
                'timeshared' => (int) $r->timeshared,
            ];
        }

        return ['total' => $total, 'results' => $results];
    }

    /**
     * Describes the structure of execute()'s return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'total' => new external_value(PARAM_INT, 'Total matching resources'),
            'results' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Resource id'),
                'type' => new external_value(PARAM_ALPHA, 'course|activity'),
                'title' => new external_value(PARAM_TEXT, 'Title'),
                'summary' => new external_value(PARAM_RAW, 'Summary'),
                'language' => new external_value(PARAM_TEXT, 'Language'),
                'licenseshortname' => new external_value(PARAM_TEXT, 'License shortname'),
                'activitytype' => new external_value(PARAM_TEXT, 'Activity modname, if type=activity'),
                'courseformat' => new external_value(PARAM_TEXT, 'Original course format'),
                'downloadcount' => new external_value(PARAM_INT, 'Download count'),
                'importcount' => new external_value(PARAM_INT, 'Import count'),
                'timeshared' => new external_value(PARAM_INT, 'Unix timestamp'),
            ])),
        ]);
    }
}
