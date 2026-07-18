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

/**
 * Builds a Moodle Playground trial launch URL for a resource and redirects
 * to it. See DESIGN.md §4.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_oerexchange\local\download_signer;
use local_oerexchange\local\sandbox\playground;

// Intentionally public - the sandbox trial is a stateless, anonymous,
// client-side session with no Moodle account of its own.
require(__DIR__ . '/../../config.php'); // phpcs:ignore moodle.Files.RequireLogin.Missing

$id = required_param('id', PARAM_INT);

$PAGE->set_url('/local/oerexchange/sandbox_launch.php', ['id' => $id]);
$PAGE->set_context(context_system::instance());

if (!get_config('local_oerexchange', 'sandboxenabled')) {
    throw new moodle_exception('tryitunavailable', 'local_oerexchange');
}
$sandboxbaseurl = get_config('local_oerexchange', 'sandboxbaseurl');
if (empty($sandboxbaseurl)) {
    throw new moodle_exception('tryitunavailable', 'local_oerexchange');
}

$resource = $DB->get_record('local_oerexchange_resources', ['id' => $id, 'status' => 'published'], '*', MUST_EXIST);

$latest = $DB->get_records(
    'local_oerexchange_versions',
    ['resourceid' => $resource->id, 'status' => 'ready'],
    'versionnumber DESC',
    '*',
    0,
    1
);
if (!$latest) {
    throw new moodle_exception('tryitunavailable', 'local_oerexchange');
}
$version = reset($latest);

$branch = playground::map_branch($version->moodleversion ?: '5.2');

$allowedinstalls = [];
$requiredplugins = $version->requiredplugins ? json_decode($version->requiredplugins, true) : [];
foreach ($requiredplugins as $plugin) {
    $entry = $DB->get_record('local_oerexchange_pluginallowlist', [
        'plugintype' => $plugin['type'], 'pluginname' => $plugin['name'],
        'moodlebranch' => $branch, 'status' => 'active',
    ]);
    if ($entry && $entry->itemid) {
        $allowedinstalls[] = [
            'type' => $plugin['type'],
            'name' => $plugin['name'],
            'zipurl' => (new moodle_url('/local/oerexchange/allowlist_file.php', ['id' => $entry->id]))->out(false),
        ];
    }
}

$signedmbzurl = download_signer::sign_url($version->id, playground::SIGNED_URL_TTL)->out(false);
$blueprint = playground::build_blueprint($resource->title, $signedmbzurl, $allowedinstalls);
$launchurl = playground::build_launch_url($sandboxbaseurl, $branch, $blueprint);

$DB->insert_record('local_oerexchange_trials', (object) [
    'resourceid' => $resource->id,
    'versionid' => $version->id,
    'userid' => isloggedin() && !isguestuser() ? $USER->id : null,
    'moodlebranch' => $branch,
    'timecreated' => time(),
]);

redirect($launchurl);
