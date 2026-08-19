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

namespace local_oerexchange\event;

/**
 * A new resource was shared on the Exchange.
 *
 * Fired by resource_manager::publish() for every NEW catalogue entry — a
 * direct upload on either upload page or a share arriving over the
 * publish_resource web service — after the transaction that created it has
 * committed. Replacing the file of an existing resource fires
 * resource_updated instead. Note a course/activity resource is still
 * status 'pending' at this moment (parse_backup_task publishes it once
 * validation passes); a 'data' resource is already 'published'.
 *
 * Being a regular Events API event, it can be subscribed to via core's
 * Event monitoring (tool_monitor) or observed with an event observer.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resource_shared extends \core\event\base {
    /**
     * Initialises the event's static data.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_oerexchange_resources';
    }

    /**
     * Localised event name (shown in event lists and monitoring rules).
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventresourceshared', 'local_oerexchange');
    }

    /**
     * Human-readable description of this specific occurrence.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' shared a new resource with id '{$this->objectid}' on the OER Exchange.";
    }

    /**
     * URL of the shared resource.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/oerexchange/resource.php', ['id' => $this->objectid]);
    }

    /**
     * There is no site-course mapping for Exchange resources on restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'local_oerexchange_resources', 'restore' => \core\event\base::NOT_MAPPED];
    }
}
