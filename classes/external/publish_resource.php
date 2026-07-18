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
use core_external\external_single_structure;
use core_external\external_value;
use local_oerexchange\local\resource_manager;

/**
 * local_oerexchange_publish_resource external function. Authenticated with
 * the teacher's personally-linked token (from the account-linking handshake)
 * — creatorid is always $USER->id, never client-supplied.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class publish_resource extends external_api {
    /**
     * Describes the parameters this function accepts.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'siteid' => new external_value(PARAM_INT, 'The registered site this share came from'),
            'draftitemid' => new external_value(PARAM_INT, 'Draft area (from webservice/upload.php) holding the .mbz'),
            'type' => new external_value(PARAM_ALPHA, 'course|activity'),
            'title' => new external_value(PARAM_TEXT, 'Title'),
            'summary' => new external_value(PARAM_RAW, 'Summary', VALUE_DEFAULT, ''),
            'language' => new external_value(PARAM_TEXT, 'Language', VALUE_DEFAULT, ''),
            'tags' => new external_value(PARAM_TEXT, 'Comma-separated tags', VALUE_DEFAULT, ''),
            'licenseshortname' => new external_value(PARAM_TEXT, 'License shortname (core license_manager)'),
            'activitytype' => new external_value(PARAM_TEXT, 'Activity modname, if type=activity', VALUE_DEFAULT, ''),
            'resourceid' => new external_value(
                PARAM_INT,
                'Existing resource id to add a version to, or 0 for new',
                VALUE_DEFAULT,
                0
            ),
            'forkedfromid' => new external_value(PARAM_INT, 'Attribution: source resource id, or 0', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Publishes a draft-area backup as a new resource, or as a new version of an existing one the caller owns.
     *
     * @param int $siteid
     * @param int $draftitemid
     * @param string $type
     * @param string $title
     * @param string $summary
     * @param string $language
     * @param string $tags
     * @param string $licenseshortname
     * @param string $activitytype
     * @param int $resourceid
     * @param int $forkedfromid
     * @return array
     */
    public static function execute(
        int $siteid,
        int $draftitemid,
        string $type,
        string $title,
        string $summary = '',
        string $language = '',
        string $tags = '',
        string $licenseshortname = '',
        string $activitytype = '',
        int $resourceid = 0,
        int $forkedfromid = 0
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'siteid' => $siteid, 'draftitemid' => $draftitemid, 'type' => $type, 'title' => $title,
            'summary' => $summary, 'language' => $language, 'tags' => $tags,
            'licenseshortname' => $licenseshortname, 'activitytype' => $activitytype,
            'resourceid' => $resourceid, 'forkedfromid' => $forkedfromid,
        ]);
        self::validate_context(\context_system::instance());

        if (!in_array($params['type'], ['course', 'activity'], true)) {
            throw new \moodle_exception('error_invalidresourcetype', 'local_oerexchange');
        }

        $site = $DB->get_record('local_oerexchange_sites', ['id' => $params['siteid'], 'status' => 'active']);
        if (!$site) {
            throw new \moodle_exception('error_sitenotactive', 'local_oerexchange');
        }

        // Siteid is client-supplied and otherwise only checked for "exists
        // and active" — re-validate it against $USER's own link history so
        // a site can't attribute a share to a *different* site the caller
        // never actually linked through. Checking "any completed handshake
        // ever" (not "most recent") is deliberate: a teacher legitimately
        // linked to several sites over time holds several simultaneously
        // valid personal tokens, and "most recent" would false-positive
        // reject that case.
        $everlinked = $DB->record_exists('local_oerexchange_linkcodes', [
            'userid' => (int) $USER->id,
            'siteid' => $site->id,
            'status' => 'used',
        ]);
        if (!$everlinked) {
            throw new \moodle_exception('error_sitenotlinked', 'local_oerexchange');
        }

        [$newresourceid, $versionid] = resource_manager::publish(
            $params['draftitemid'],
            (int) $USER->id,
            $site->id,
            [
                'type' => $params['type'],
                'title' => $params['title'],
                'summary' => $params['summary'],
                'language' => $params['language'],
                'tags' => $params['tags'],
                'licenseshortname' => $params['licenseshortname'],
                'activitytype' => $params['activitytype'] ?: null,
                'forkedfromid' => $params['forkedfromid'] ?: null,
            ],
            $params['resourceid'] ?: null
        );

        return ['resourceid' => $newresourceid, 'versionid' => $versionid];
    }

    /**
     * Describes the structure of execute()'s return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'resourceid' => new external_value(PARAM_INT, 'Resource id (new or existing)'),
            'versionid' => new external_value(PARAM_INT, 'New version id — parsing runs asynchronously'),
        ]);
    }
}
