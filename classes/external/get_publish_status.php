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
 * local_oerexchange_get_publish_status external function — what happened to
 * the backup this author just uploaded.
 *
 * An AJAX-only companion to get_share_status: that one answers a registered
 * CLIENT SITE over a web-service token about a share it made, this one answers
 * the author's own browser, on this site, while they watch the page. Both read
 * the same rows and both gate on user_can_edit_resource(); keeping them apart
 * means the token-facing contract (published as part of the Exchange service)
 * does not have to grow an `ajax => true` flag, and this one never appears in
 * the service's function list.
 *
 * It exists because publishing is asynchronous: publish() returns as soon as
 * the file is stored, leaving the resource 'pending' and its version 'parsing'
 * until parse_backup_task runs on the next cron tick. Before this, the author
 * had to reload the page to find out whether their upload had been accepted,
 * with nothing telling them to.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_publish_status extends external_api {
    /**
     * Describes the parameters this function accepts.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'resourceid' => new external_value(PARAM_INT, 'Resource id being watched'),
        ]);
    }

    /**
     * The current publish state of one of the caller's own resources.
     *
     * @param int $resourceid
     * @return array
     */
    public static function execute(int $resourceid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['resourceid' => $resourceid]);
        $context = \context_system::instance();
        self::validate_context($context);

        $resource = $DB->get_record(
            'local_oerexchange_resources',
            ['id' => $params['resourceid']],
            '*',
            MUST_EXIST
        );

        // Same gate as every other author-side action, so a co-author or a
        // moderator watching the page gets an answer too. A visitor who is not
        // one of those gets nothing: the polled resource may still be pending,
        // i.e. not yet in the catalogue at all.
        if (!resource_manager::user_can_edit_resource($resource, (int) $USER->id)) {
            throw new \moodle_exception('error_notyourresource', 'local_oerexchange');
        }

        $newest = $DB->get_records(
            'local_oerexchange_versions',
            ['resourceid' => (int) $resource->id],
            'versionnumber DESC, id DESC',
            '*',
            0,
            1
        );
        $newest = $newest ? reset($newest) : null;
        $versionstatus = $newest ? (string) $newest->status : '';

        // The 'settled' flag is what the poller actually needs: keep asking,
        // or stop.
        // Deciding it here rather than in JavaScript keeps the list of
        // non-terminal states (only 'parsing' today) in one place, next to the
        // task that writes them.
        $settled = ($versionstatus !== 'parsing');

        return [
            'resourceid' => (int) $resource->id,
            'status' => (string) $resource->status,
            'versionstatus' => $versionstatus,
            'settled' => $settled,
            'published' => ($resource->status === 'published' && $versionstatus === 'ready'),
            // Never the raw stored text: core exception messages carry
            // absolute server paths (see author_facing_parse_error()).
            'error' => ($newest && $versionstatus === 'failed')
                ? resource_manager::author_facing_parse_error($newest->parseerror)
                : '',
        ];
    }

    /**
     * Describes the structure of execute()'s return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'resourceid' => new external_value(PARAM_INT, 'Resource id'),
            'status' => new external_value(PARAM_ALPHA, 'Resource status: pending, published, hidden, removed, deleted'),
            'versionstatus' => new external_value(PARAM_ALPHA, 'Newest version status: parsing, ready, failed, superseded'),
            'settled' => new external_value(PARAM_BOOL, 'False while validation is still running — keep polling'),
            'published' => new external_value(PARAM_BOOL, 'True once the resource is live in the catalogue'),
            'error' => new external_value(PARAM_TEXT, 'Author-facing rejection reason, empty unless the upload failed'),
        ]);
    }
}
