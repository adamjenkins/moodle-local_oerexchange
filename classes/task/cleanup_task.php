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

use local_oerexchange\local\invitation_manager;
use local_oerexchange\local\link_manager;
use local_oerexchange\local\site_manager;

/**
 * Hourly housekeeping for rows nobody will act on again:
 *
 * - link codes that expired unused, and any token one still holds
 *   (link_manager::cleanup_expired_codes());
 * - site registrations that waited PENDING_REGISTRATION_TTL with no
 *   decision, and registering-address keys past the throttle window
 *   (site_manager);
 * - invitation rows past their retention (invitation_manager::cleanup()).
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_task extends \core\task\scheduled_task {
    #[\Override]
    public function get_name(): string {
        return get_string('task_cleanup', 'local_oerexchange');
    }

    #[\Override]
    public function execute() {
        $now = time();

        $codes = link_manager::cleanup_expired_codes($now);
        $sites = site_manager::expire_pending_registrations($now);
        site_manager::clear_old_address_keys($now);
        $invitations = invitation_manager::cleanup($now);

        mtrace("local_oerexchange: removed {$codes} expired link codes, {$sites} stale registrations, "
            . "{$invitations} old invitations.");
    }
}
