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
 * Add a plugin (and its dependencies) to the sandbox allowlist from the
 * command line.
 *
 * The same pipeline the admin page runs, so --dry-run prints exactly the plan
 * the page would show for confirmation, and without it the same rows get
 * written. Useful for scripting, and for adding a plugin on a site where
 * reaching the admin UI is inconvenient.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(dirname(dirname(dirname(dirname(__DIR__)))) . '/config.php');
require_once($CFG->libdir . '/clilib.php');

use local_oerexchange\local\allowlist\dependency_walker;
use local_oerexchange\local\allowlist\directory_component_locator;
use local_oerexchange\local\allowlist\entry_plan;
use local_oerexchange\local\allowlist\ingest_plan;
use local_oerexchange\local\allowlist\ingestor;
use local_oerexchange\local\allowlist\moodle_http_fetcher;
use local_oerexchange\local\allowlist\resolution_exception;
use local_oerexchange\local\allowlist\source_resolver;
use local_oerexchange\local\allowlist\zip_inspector;

[$options, $unrecognised] = cli_get_params([
    'url' => null,
    'zip' => null,
    'bake' => false,
    'ignore-supported' => false,
    'dry-run' => false,
    'help' => false,
], [
    'h' => 'help',
    'n' => 'dry-run',
]);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'core_admin', implode(PHP_EOL . '  ', $unrecognised)));
}

if ($options['help'] || (empty($options['url']) && empty($options['zip']))) {
    cli_writeln(<<<EOT
    Add a plugin to the sandbox plugin allowlist, deriving its type, name,
    supported Moodle versions and dependencies from the package itself.

    Options:
      --url=URL     A plugin ZIP URL, or a GitHub repository URL.
      --zip=PATH    A plugin ZIP already on this machine.
      --bake        Also flag the entries to be baked into the sandbox bundle.
                    Cascades to dependencies.
      --ignore-supported
                    List the plugin for every Moodle version the sandbox runs,
                    whatever its version.php declares. For a plugin whose
                    supported range is simply out of date. Cascades to
                    dependencies. Still honours an explicit
                    \$plugin->incompatible.
      -n, --dry-run Show what would be added and change nothing.
      -h, --help    This text.

    Examples:
      \$ sudo -u www-data php add_allowlist_plugin.php \\
            --url=https://github.com/adamjenkins/moodle-mod_quizquest --dry-run
      \$ sudo -u www-data php add_allowlist_plugin.php --zip=/tmp/mod_thing.zip --bake
    EOT);
    exit(0);
}

$workdir = make_request_directory();
$resolver = new source_resolver(new moodle_http_fetcher());
$inspector = new zip_inspector();

try {
    if (!empty($options['zip'])) {
        $path = (string) $options['zip'];
        if (!is_readable($path)) {
            cli_error('Cannot read ' . $path);
        }
        $resolved = $resolver->accept_local_file($path, basename($path));
    } else {
        cli_writeln('Resolving ' . $options['url'] . ' ...');
        $resolved = $resolver->resolve((string) $options['url'], $workdir);
        cli_writeln('  fetched ' . $resolved->sourceurl);
    }

    $meta = $inspector->inspect($resolved->zipfilepath, $workdir, $resolved->sourceurl);
} catch (resolution_exception $e) {
    cli_error($e->getMessage());
}

cli_writeln('Reading dependencies ...');
$walker = new dependency_walker(
    $resolver,
    $inspector,
    new directory_component_locator(),
    null,
    (bool) $options['ignore-supported']
);
$ingestor = new ingestor();
$plan = $ingestor->preview($walker->walk($meta, $workdir));

print_plan($plan);

if ($options['dry-run']) {
    cli_writeln('');
    cli_writeln('Dry run — nothing was changed.');
    exit(0);
}

if ($plan->is_empty()) {
    cli_error('Nothing to add.');
}

$result = $ingestor->commit($plan, (bool) $options['bake']);

cli_writeln('');
cli_writeln(sprintf(
    'Done: %d row(s) added, %d refreshed, across %d plugin(s).',
    $result->added,
    $result->refreshed,
    count($result->components)
));

/**
 * Render a plan as a table, the same information the admin page previews.
 *
 * @param ingest_plan $plan
 */
function print_plan(ingest_plan $plan): void {
    cli_writeln('');
    cli_writeln(str_pad('PLUGIN', 32) . str_pad('BRANCHES', 14) . 'WHAT WILL HAPPEN');
    cli_writeln(str_repeat('-', 78));

    foreach ($plan->entries as $entry) {
        $label = $entry->component;
        if ($entry->role === entry_plan::DEPENDENCY) {
            $label = '  └ ' . $label;
        }
        if ($entry->meta !== null && $entry->meta->release !== null) {
            $label .= ' ' . $entry->meta->release;
        }

        $branches = $entry->branches ? implode(', ', $entry->branches->branches) : '';

        cli_writeln(
            str_pad(\core_text::substr($label, 0, 31), 32)
            . str_pad($branches, 14)
            . get_string('allowlistaction_' . $entry->action, 'local_oerexchange')
        );

        if ($entry->branches !== null && $entry->branches->declined !== []) {
            cli_writeln('      ! ' . get_string(
                'allowlistoverriddenbranches',
                'local_oerexchange',
                implode(', ', $entry->branches->declined)
            ));
        }

        foreach ($entry->messages as $message) {
            cli_writeln('      ! ' . $message);
        }
    }

    // Why a branch set is what it is matters when nothing declared it.
    $primary = $plan->primary();
    if ($primary !== null && $primary->branches !== null) {
        cli_writeln('');
        cli_writeln(get_string(
            'allowlistconfidence_' . $primary->branches->confidence,
            'local_oerexchange'
        ));
    }

    foreach ($plan->messages as $message) {
        cli_writeln('! ' . $message);
    }
}
