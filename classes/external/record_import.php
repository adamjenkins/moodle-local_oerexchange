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
use local_oerexchange\local\site_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * local_oerexchange_record_import external function. Authenticated with the
 * calling site's own service-account token — the importing teacher may or
 * may not be linked, so 'userid' (Exchange-local) is optional.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class record_import extends external_api {
    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'resourceid' => new external_value(PARAM_INT, 'Resource id'),
            'versionid' => new external_value(PARAM_INT, 'Version id that was imported'),
            'userid' => new external_value(PARAM_INT, 'Exchange-local userid of the importer, or 0 if unlinked', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * @param int $resourceid
     * @param int $versionid
     * @param int $userid
     * @return array
     */
    public static function execute(int $resourceid, int $versionid, int $userid = 0): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'resourceid' => $resourceid, 'versionid' => $versionid, 'userid' => $userid,
        ]);
        self::validate_context(\context_system::instance());

        $site = site_manager::get_site_for_user((int) $USER->id);
        if (!$site) {
            throw new \moodle_exception('error_sitenotactive', 'local_oerexchange');
        }

        $resource = $DB->get_record('local_oerexchange_resources', ['id' => $params['resourceid']], '*', MUST_EXIST);
        $version = $DB->get_record('local_oerexchange_versions', ['id' => $params['versionid']], '*', MUST_EXIST);

        $DB->insert_record('local_oerexchange_imports', (object) [
            'resourceid' => $resource->id,
            'versionid' => $version->id,
            'siteid' => $site->id,
            'userid' => $params['userid'] ?: null,
            'timecreated' => time(),
        ]);
        $DB->set_field('local_oerexchange_resources', 'importcount', $resource->importcount + 1, ['id' => $resource->id]);

        // Notify the creator, if they still have a valid Exchange account.
        $creator = $DB->get_record('user', ['id' => $resource->creatorid, 'deleted' => 0]);
        if ($creator) {
            message_send(self::build_message($creator, $resource));
        }

        return ['success' => true];
    }

    /**
     * @param \stdClass $creator
     * @param \stdClass $resource
     * @return \core\message\message
     */
    protected static function build_message(\stdClass $creator, \stdClass $resource): \core\message\message {
        $message = new \core\message\message();
        $message->component = 'local_oerexchange';
        $message->name = 'import';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $creator;
        $message->subject = get_string('notifyimportsubject', 'local_oerexchange', $resource->title);
        $message->fullmessage = get_string('notifyimportbody', 'local_oerexchange', $resource->title);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $message->subject;
        $message->notification = 1;
        $message->contexturl = (new \moodle_url('/local/oerexchange/resource.php', ['id' => $resource->id]))->out(false);
        $message->contexturlname = $resource->title;
        return $message;
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'True on success'),
        ]);
    }
}
