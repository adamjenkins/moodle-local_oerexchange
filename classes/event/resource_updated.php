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
 * An existing resource was updated.
 *
 * Fired when an author-visible change lands on an existing catalogue entry:
 * - a replacement file / new version uploaded through
 *   resource_manager::publish() (other['updated'] = 'file'), after its
 *   transaction has committed;
 * - the details form saved through resource_manager::update_metadata()
 *   (other['updated'] = 'details').
 *
 * Moderation actions (hide/unhide, takedown) and counters deliberately do
 * not fire it: they are not the author updating the resource.
 *
 * Being a regular Events API event, it can be subscribed to via core's
 * Event monitoring (tool_monitor) or observed with an event observer.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resource_updated extends \core\event\base {
    /**
     * Initialises the event's static data.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_oerexchange_resources';
    }

    /**
     * Localised event name (shown in event lists and monitoring rules).
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventresourceupdated', 'local_oerexchange');
    }

    /**
     * Human-readable description of this specific occurrence.
     *
     * @return string
     */
    public function get_description() {
        $what = ($this->other['updated'] ?? '') === 'file' ? 'file' : 'details';
        return "The user with id '{$this->userid}' updated the {$what} of the resource with id '{$this->objectid}' "
            . 'on the OER Exchange.';
    }

    /**
     * URL of the updated resource.
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

    /**
     * other['updated'] is a bare label, nothing to map on restore.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return [];
    }
}
