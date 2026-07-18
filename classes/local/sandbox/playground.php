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

namespace local_oerexchange\local\sandbox;

defined('MOODLE_INTERNAL') || die();

/**
 * Moodle Playground sandbox integration (DESIGN.md §4, option B): branch
 * mapper + blueprint builder + launch-URL builder. Pure functions — no HTTP
 * calls, no orchestrator, no server-side trial execution. Replaces the
 * Podman-fleet client.php from DESIGN.md §3.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class playground {
    /**
     * Moodle versions the oer-sandbox deployment kit has actually built and
     * deployed, oldest first. Keep in sync with the kit's build list
     * (dev-docs/oer-platform/SANDBOX-UPGRADES.md).
     *
     * @var string[]
     */
    const DEPLOYED_BRANCHES = ['5.0', '5.2'];

    /**
     * Signed-download link TTL, seconds.
     */
    const SIGNED_URL_TTL = 900;

    /**
     * Map a resource's source Moodle version to the lowest deployed playground
     * branch that is >= it (restore is forward-compatible, never backward).
     * Falls back to the highest deployed branch if the source is newer than
     * everything we've deployed.
     *
     * @param string $sourceversion e.g. "4.5.2 (Build: 20250101)" or "5.2.1+"
     * @return string e.g. "5.2"
     */
    public static function map_branch(string $sourceversion): string {
        $deployed = self::DEPLOYED_BRANCHES;
        $newest = end($deployed);

        if (!preg_match('/(\d+)\.(\d+)/', $sourceversion, $matches)) {
            // Unparseable — assume it needs the newest deployed branch.
            return $newest;
        }
        $sourcenum = ((int) $matches[1]) * 100 + (int) $matches[2];

        foreach ($deployed as $branch) {
            [$maj, $min] = explode('.', $branch);
            $branchnum = ((int) $maj) * 100 + (int) $min;
            if ($branchnum >= $sourcenum) {
                return $branch;
            }
        }

        return $newest;
    }

    /**
     * Build the blueprint JSON for a trial: install, login, allowlisted
     * required plugins, restore the resource, land on the restored course.
     *
     * @param string $resourcetitle
     * @param string $signedmbzurl signed, short-lived, same-origin-reachable URL to the .mbz
     * @param array $allowedplugininstalls list of ['type'=>, 'name'=>, 'zipurl'=>] — already
     *                                     intersected with the pluginallowlist by the caller;
     *                                     this method does not consult resource metadata for
     *                                     what to install, only what it is given.
     * @return array the blueprint structure (JSON-encode before use)
     */
    public static function build_blueprint(string $resourcetitle, string $signedmbzurl, array $allowedplugininstalls): array {
        $steps = [];

        $steps[] = [
            'step' => 'installMoodle',
            'options' => ['siteName' => $resourcetitle],
        ];
        $steps[] = ['step' => 'login', 'username' => 'admin'];

        foreach ($allowedplugininstalls as $plugin) {
            // pluginType/pluginName must be explicit: Moodle Playground only
            // auto-detects them from a GitHub-style archive URL matching
            // /<repo>/archive/... (moodle-{type}_{name} naming) - our
            // allowlist_file.php?id=N URLs never match that, so omitting
            // these silently threw "pluginType could not be detected from
            // URL" inside the sandbox on every install attempt (found live,
            // 2026-07-19, tracing why mod_quizquest never appeared in a
            // trial despite an active allowlist entry - see
            // reference-clones/moodle-playground/src/blueprint/steps/moodle-plugins.js
            // detectPluginTypeAndName()). We already know both values
            // exactly; there's no need to rely on auto-detection at all.
            $steps[] = [
                'step' => 'installMoodlePlugin',
                'url' => $plugin['zipurl'],
                'pluginType' => $plugin['type'],
                'pluginName' => $plugin['name'],
            ];
        }

        $steps[] = [
            'step' => 'restoreCourse',
            'url' => $signedmbzurl,
            'category' => 'Trial',
        ];

        return [
            'steps' => $steps,
            // Fresh snapshot install has only the site course (id 1) — the
            // restored course is expected to land as id 2. Verified by the
            // fidelity spike / kit smoke test; falls back gracefully to the
            // course index if wrong (restoreCourse failures are non-fatal).
            'landingPage' => '/course/view.php?id=2',
        ];
    }

    /**
     * Build the full launch URL for a trial.
     *
     * @param string $sandboxbaseurl e.g. "https://vagrant.wisecat.net/try/"
     * @param string $branch e.g. "5.2"
     * @param array $blueprint from build_blueprint()
     * @return \moodle_url
     */
    public static function build_launch_url(string $sandboxbaseurl, string $branch, array $blueprint): \moodle_url {
        $encoded = base64_encode(json_encode($blueprint));

        return new \moodle_url(rtrim($sandboxbaseurl, '/') . '/', [
            'moodle' => $branch,
            'blueprint' => $encoded,
        ]);
    }
}
