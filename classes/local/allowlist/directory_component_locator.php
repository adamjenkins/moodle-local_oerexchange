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

namespace local_oerexchange\local\allowlist;

/**
 * Looks a component up in the moodle.org plugins directory.
 *
 * This is the one place the Moodle plugins directory is consulted. An admin
 * never types a moodle.org address — they paste a ZIP URL, a GitHub
 * repository, or upload a file. But a *dependency* arrives as a bare
 * component name with no URL attached, and the plugins directory is the only
 * general way to turn one into a download.
 *
 * A miss is completely normal and is not an error: a plugin published only on
 * its author's own GitHub (mod_quizquest, for one) is not in the directory at
 * all. The walker surfaces those to the admin as "needs a URL" rather than
 * failing the ingest.
 *
 * Uses core's own \core\update\api client, so it shares core's endpoint
 * configuration, response validation and cURL settings rather than
 * re-implementing any of it.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class directory_component_locator implements component_locator {
    #[\Override]
    public function locate(string $component, ?int $branch): ?string {
        try {
            $info = \core\update\api::client()->find_plugin($component, ANY_VERSION, $branch);
        } catch (\Throwable $e) {
            // The directory being unreachable must not fail the whole ingest;
            // it degrades to the same outcome as "not published there",
            // which the admin can resolve by pasting a URL.
            return null;
        }

        if (empty($info->version->downloadurl)) {
            return null;
        }

        return (string) $info->version->downloadurl;
    }
}
