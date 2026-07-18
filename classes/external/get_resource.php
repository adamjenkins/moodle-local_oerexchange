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
use local_oerexchange\local\download_signer;

/**
 * local_oerexchange_get_resource external function.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_resource extends external_api {
    /**
     * Describes the parameters this function accepts.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'resourceid' => new external_value(PARAM_INT, 'Resource id'),
        ]);
    }

    /**
     * Returns a published resource's full detail, including a signed download URL for its latest ready version.
     *
     * @param int $resourceid
     * @return array
     */
    public static function execute(int $resourceid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['resourceid' => $resourceid]);
        self::validate_context(\context_system::instance());

        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $params['resourceid']], '*', MUST_EXIST);
        if ($resource->status !== 'published') {
            throw new \moodle_exception('error_notfound', 'local_oerexchange');
        }

        $version = $DB->get_record(
            'local_oerexchange_versions',
            ['resourceid' => $resource->id, 'status' => 'ready'],
            '*',
            IGNORE_MULTIPLE
        );
        // If more than one ready version exists, prefer the latest.
        if ($version) {
            $latest = $DB->get_records(
                'local_oerexchange_versions',
                ['resourceid' => $resource->id, 'status' => 'ready'],
                'versionnumber DESC',
                '*',
                0,
                1
            );
            $version = reset($latest);
        }

        $reviews = $DB->get_records(
            'local_oerexchange_reviews',
            ['resourceid' => $resource->id, 'status' => 'visible'],
            'timecreated DESC'
        );
        $reviewsout = [];
        foreach ($reviews as $rv) {
            $reviewsout[] = [
                'contexttext' => $rv->contexttext ?? '',
                'adaptationtext' => $rv->adaptationtext ?? '',
                'outcometext' => $rv->outcometext ?? '',
                'rating' => $rv->rating !== null ? (int) $rv->rating : -1,
                'timecreated' => (int) $rv->timecreated,
            ];
        }

        $downloadurl = '';
        if ($version) {
            $downloadurl = download_signer::sign_url($version->id)->out(false);
            // Atomic increment — a read-modify-write ($resource->downloadcount + 1)
            // loses updates under concurrent get_resource calls for the same resource.
            $DB->execute(
                'UPDATE {local_oerexchange_resources} SET downloadcount = downloadcount + 1 WHERE id = ?',
                [$resource->id]
            );
        }

        return [
            'id' => (int) $resource->id,
            'type' => $resource->type,
            'title' => $resource->title,
            'summary' => $resource->summary ?? '',
            'language' => $resource->language ?? '',
            'tags' => $resource->tags ?? '',
            'licenseshortname' => $resource->licenseshortname,
            'activitytype' => $resource->activitytype ?? '',
            'courseformat' => $resource->courseformat ?? '',
            'downloadcount' => (int) $resource->downloadcount,
            'importcount' => (int) $resource->importcount,
            'forkedfromid' => $resource->forkedfromid !== null ? (int) $resource->forkedfromid : -1,
            'timeshared' => (int) $resource->timeshared,
            'versionid' => $version ? (int) $version->id : -1,
            'moodleversion' => $version->moodleversion ?? '',
            'structurejson' => $version->structurejson ?? '',
            'requiredplugins' => $version->requiredplugins ?? '[]',
            'downloadurl' => $downloadurl,
            'reviews' => $reviewsout,
        ];
    }

    /**
     * Describes the structure of execute()'s return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Resource id'),
            'type' => new external_value(PARAM_ALPHA, 'course|activity'),
            'title' => new external_value(PARAM_TEXT, 'Title'),
            'summary' => new external_value(PARAM_RAW, 'Summary'),
            'language' => new external_value(PARAM_TEXT, 'Language'),
            'tags' => new external_value(PARAM_RAW, 'Comma-separated tags'),
            'licenseshortname' => new external_value(PARAM_TEXT, 'License shortname'),
            'activitytype' => new external_value(PARAM_TEXT, 'Activity modname, if type=activity'),
            'courseformat' => new external_value(PARAM_TEXT, 'Original course format'),
            'downloadcount' => new external_value(PARAM_INT, 'Download count'),
            'importcount' => new external_value(PARAM_INT, 'Import count'),
            'forkedfromid' => new external_value(PARAM_INT, 'Attribution: source resource id, or -1'),
            'timeshared' => new external_value(PARAM_INT, 'Unix timestamp'),
            'versionid' => new external_value(PARAM_INT, 'Latest ready version id, or -1 if none ready yet'),
            'moodleversion' => new external_value(PARAM_TEXT, 'Moodle release the backup was made from'),
            'structurejson' => new external_value(PARAM_RAW, 'JSON structure preview'),
            'requiredplugins' => new external_value(PARAM_RAW, 'JSON array of [{type,name}]'),
            'downloadurl' => new external_value(PARAM_RAW, 'Signed, short-lived download URL for the .mbz'),
            'reviews' => new external_multiple_structure(new external_single_structure([
                'contexttext' => new external_value(PARAM_RAW, 'Context'),
                'adaptationtext' => new external_value(PARAM_RAW, 'Adaptation'),
                'outcometext' => new external_value(PARAM_RAW, 'Outcome'),
                'rating' => new external_value(PARAM_INT, 'Rating 1-5, or -1'),
                'timecreated' => new external_value(PARAM_INT, 'Unix timestamp'),
            ])),
        ]);
    }
}
