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
 * Upgrade steps for local_oerexchange.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade steps.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_oerexchange_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026072000) {
        $table = new xmldb_table('local_oerexchange_profiles');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('slug', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('bio', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('expertise', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('orcidurl', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('linkedinurl', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('researchmapurl', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('visible', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('userid', XMLDB_INDEX_UNIQUE, ['userid']);
        $table->add_index('slug', XMLDB_INDEX_UNIQUE, ['slug']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_oerexchange_badges');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('badgekey', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timeawarded', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('useridbadgekey', XMLDB_INDEX_UNIQUE, ['userid', 'badgekey']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026072000, 'local', 'oerexchange');
    }

    if ($oldversion < 2026072001) {
        $table = new xmldb_table('local_oerexchange_resources');

        $field = new xmldb_field('dataresourcetype', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'activitytype');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // The siteid foreign key is implemented, per Moodle's XMLDB layer, as a plain
        // index rather than a DB-enforced constraint on both MySQL/MariaDB and
        // PostgreSQL (sql_generator::$foreign_keys is false for both). dbman's
        // change_field_notnull() unconditionally refuses to modify a column that any
        // index still covers (database_manager::check_field_dependencies()), so the
        // key has to be dropped and recreated around the NOT NULL change. Both
        // drop_key() and add_key() are safe/no-op if the underlying index is already
        // absent/present, so this is safe to run unconditionally.
        $field = new xmldb_field('siteid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        if ($dbman->field_exists($table, $field)) {
            $key = new xmldb_key('siteid', XMLDB_KEY_FOREIGN, ['siteid'], 'local_oerexchange_sites', ['id']);
            $dbman->drop_key($table, $key);
            $dbman->change_field_notnull($table, $field);
            $dbman->add_key($table, $key);
        }

        upgrade_plugin_savepoint(true, 2026072001, 'local', 'oerexchange');
    }

    if ($oldversion < 2026072002) {
        // A now-superseded version of this upgrade step (deployed briefly before this
        // fix) used hand-rolled, hardcoded-table-prefix raw SQL instead of the dbman
        // API above, and db/install.xml briefly had the siteid foreign key removed
        // entirely. This defensively reconciles any environment's live schema to the
        // same target state (siteid nullable, siteid foreign key/index present)
        // using only dbman calls, checking before acting rather than assuming a
        // specific starting state.
        $table = new xmldb_table('local_oerexchange_resources');
        $field = new xmldb_field('siteid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $key = new xmldb_key('siteid', XMLDB_KEY_FOREIGN, ['siteid'], 'local_oerexchange_sites', ['id']);
        $index = new xmldb_index('siteid', XMLDB_INDEX_NOTUNIQUE, ['siteid']);

        if ($dbman->field_exists($table, $field)) {
            // Drop the underlying index first only if it is actually present, since
            // change_field_notnull() refuses to touch a column any index still
            // covers (this is a no-op on an environment where the earlier raw-SQL
            // step already dropped it).
            if ($dbman->index_exists($table, $index)) {
                $dbman->drop_key($table, $key);
            }
            $dbman->change_field_notnull($table, $field);
            // Re-add the key/index only if it's actually missing.
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_key($table, $key);
            }
        }

        upgrade_plugin_savepoint(true, 2026072002, 'local', 'oerexchange');
    }

    if ($oldversion < 2026072300) {
        // Author opt-out of the sandbox ("Try it"), with an optional reason
        // shown in place of the button.
        $table = new xmldb_table('local_oerexchange_resources');

        $field = new xmldb_field('trydisabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'forkedfromid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('trydisabledreason', XMLDB_TYPE_TEXT, null, null, null, null, null, 'trydisabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Only one version per resource is served from now on. Retire the
        // files of any historical extra versions and mark those rows
        // 'superseded', keeping the rows themselves so existing
        // imports.versionid / trials.versionid references still resolve.
        //
        // Inlined (not a call into the plugin's own classes): upgrade steps
        // must never depend on the plugin API of the version being upgraded
        // TO — a future rename of that method would break every site
        // upgrading through this version. This is the exact logic
        // resource_manager::supersede_all_stale_versions() performed when
        // this step shipped.
        $fs = get_file_storage();
        $syscontextid = context_system::instance()->id;
        $resourceids = $DB->get_fieldset_sql('SELECT DISTINCT resourceid FROM {local_oerexchange_versions}');
        foreach ($resourceids as $resourceid) {
            $current = $DB->get_records(
                'local_oerexchange_versions',
                ['resourceid' => $resourceid, 'status' => 'ready'],
                'versionnumber DESC',
                'id',
                0,
                1
            );
            if (!$current) {
                continue;
            }
            $keepid = (int) reset($current)->id;
            $stale = $DB->get_records_select(
                'local_oerexchange_versions',
                'resourceid = ? AND id <> ? AND status <> ?',
                [$resourceid, $keepid, 'superseded'],
                '',
                'id'
            );
            foreach ($stale as $version) {
                $fs->delete_area_files($syscontextid, 'local_oerexchange', 'resource', $version->id);
                $DB->set_field('local_oerexchange_versions', 'status', 'superseded', ['id' => $version->id]);
            }
        }

        upgrade_plugin_savepoint(true, 2026072300, 'local', 'oerexchange');
    }

    if ($oldversion < 2026072700) {
        // Abandoned-courseware lifecycle: a freshness clock per resource and
        // the flag recording that a stale warning went out to the author.
        $table = new xmldb_table('local_oerexchange_resources');

        $field = new xmldb_field('timefresh', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timemodified');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('stalenotifiedtime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timefresh');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Existing rows start their freshness clock from timemodified — the
        // best signal we have of when they last changed. timeshared would be
        // unfairly old for anything updated since first publication (it never
        // changes on update by design).
        $DB->execute('UPDATE {local_oerexchange_resources} SET timefresh = timemodified WHERE timefresh = 0');

        upgrade_plugin_savepoint(true, 2026072700, 'local', 'oerexchange');
    }

    return true;
}
