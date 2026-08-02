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

    if ($oldversion < 2026072702) {
        // Co-authors: additional users who hold exactly the same rights over a
        // resource as its creator. Nothing to migrate — every existing
        // resource simply has no co-authors, which is the same state a new
        // one starts in.
        $table = new xmldb_table('local_oerexchange_coauthors');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('resourceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('addedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('resourceid', XMLDB_KEY_FOREIGN, ['resourceid'], 'local_oerexchange_resources', ['id']);
        // Unique so a double-submitted "Add co-author" cannot seat the same
        // person twice — coauthor_manager::add() checks first, but the index
        // is what makes it true under concurrency.
        $table->add_index('resourceiduserid', XMLDB_INDEX_UNIQUE, ['resourceid', 'userid']);
        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026072702, 'local', 'oerexchange');
    }

    if ($oldversion < 2026073100) {
        $table = new xmldb_table('local_oerexchange_pluginallowlist');
        $field = new xmldb_field('bake', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'status');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026073100, 'local', 'oerexchange');
    }

    if ($oldversion < 2026080101) {
        // Drop the three hardcoded portfolio-link columns. The public profile
        // page now renders the user's own "Visible to everyone" custom user
        // profile fields (/user/profile/index.php) in the same position
        // instead, which the admin controls site-wide rather than this plugin
        // hardcoding three networks. Deliberately NO migration: the project
        // owner's decision is to discard this data outright, so there is
        // nothing to copy anywhere first.
        $table = new xmldb_table('local_oerexchange_profiles');
        foreach (['orcidurl', 'linkedinurl', 'researchmapurl'] as $fieldname) {
            $field = new xmldb_field($fieldname);
            if ($dbman->field_exists($table, $field)) {
                $dbman->drop_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026080101, 'local', 'oerexchange');
    }

    if ($oldversion < 2026080300) {
        // The allowlist now derives its own metadata from a plugin's ZIP and
        // pulls in that plugin's dependencies, so an entry records what it
        // is (component, version, release) and, when it was added
        // automatically, what pulled it in (parentid).
        $table = new xmldb_table('local_oerexchange_pluginallowlist');

        $fields = [
            new xmldb_field('component', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '', 'timemodified'),
            new xmldb_field('parentid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'component'),
            new xmldb_field('pluginversion', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'parentid'),
            new xmldb_field('pluginrelease', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'pluginversion'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Backfill component for rows added through the old form, which only
        // ever stored the type and name separately.
        $DB->execute("
            UPDATE {local_oerexchange_pluginallowlist}
               SET component = " . $DB->sql_concat('plugintype', "'_'", 'pluginname') . "
             WHERE component = '' OR component IS NULL");

        // The (type, name, branch) index becomes unique, which is what makes
        // re-adding a plugin update its entry instead of duplicating it.
        // Nothing enforced that before — 1.0.4's index was NOTUNIQUE and the
        // old add form inserted unconditionally — so ordinary admin use could
        // produce duplicates, and they have to go before the index can be
        // created. Keep the most recently ADDED row of each set (MAX(id));
        // note that is not necessarily the most recently *modified* one, since
        // toggling an entry's status bumps timemodified on an older row.
        //
        // get_recordset_sql(), NOT get_records_sql(): the latter keys its
        // returned array on the FIRST COLUMN, so two duplicate sets that share
        // a plugintype — 'mod' twice, the ordinary case — would collapse into
        // one entry and the second set would survive un-deduplicated. The
        // unique index below would then throw, and because that happens before
        // upgrade_plugin_savepoint() the whole site upgrade aborts and every
        // retry fails identically, needing manual SQL to recover. A recordset
        // does no keying at all.
        $rs = $DB->get_recordset_sql("
            SELECT MAX(id) AS keepid, plugintype, pluginname, moodlebranch
              FROM {local_oerexchange_pluginallowlist}
          GROUP BY plugintype, pluginname, moodlebranch
            HAVING COUNT(*) > 1");
        $duplicatesets = [];
        foreach ($rs as $duplicate) {
            $duplicatesets[] = (object) [
                'keepid' => $duplicate->keepid,
                'plugintype' => $duplicate->plugintype,
                'pluginname' => $duplicate->pluginname,
                'moodlebranch' => $duplicate->moodlebranch,
            ];
        }
        $rs->close();

        $fs = get_file_storage();
        $systemcontext = context_system::instance();
        foreach ($duplicatesets as $duplicate) {
            $conditions = 'plugintype = :plugintype AND pluginname = :pluginname
                               AND moodlebranch = :moodlebranch AND id <> :keepid';
            $params = [
                'plugintype' => $duplicate->plugintype,
                'pluginname' => $duplicate->pluginname,
                'moodlebranch' => $duplicate->moodlebranch,
                'keepid' => $duplicate->keepid,
            ];

            // Drop each doomed row's mirrored ZIP as well as the row. An entry's
            // itemid is its own row id, so deleting the row alone would strand
            // the file with nothing left to reach it — the same cleanup the
            // 2026072100 step above does for the 'resource' area.
            foreach ($DB->get_fieldset_select('local_oerexchange_pluginallowlist', 'id', $conditions, $params) as $id) {
                $fs->delete_area_files($systemcontext->id, 'local_oerexchange', 'allowlist', $id);
            }

            $DB->delete_records_select('local_oerexchange_pluginallowlist', $conditions, $params);
        }

        $oldindex = new xmldb_index('typenamebranch', XMLDB_INDEX_NOTUNIQUE, ['plugintype', 'pluginname', 'moodlebranch']);
        if ($dbman->index_exists($table, $oldindex)) {
            $dbman->drop_index($table, $oldindex);
        }
        $newindex = new xmldb_index('typenamebranch', XMLDB_INDEX_UNIQUE, ['plugintype', 'pluginname', 'moodlebranch']);
        if (!$dbman->index_exists($table, $newindex)) {
            $dbman->add_index($table, $newindex);
        }

        $parentindex = new xmldb_index('parentid', XMLDB_INDEX_NOTUNIQUE, ['parentid']);
        if (!$dbman->index_exists($table, $parentindex)) {
            $dbman->add_index($table, $parentindex);
        }

        upgrade_plugin_savepoint(true, 2026080300, 'local', 'oerexchange');
    }

    return true;
}
