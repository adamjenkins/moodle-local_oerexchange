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

        // Make siteid nullable using raw SQL to bypass foreign key/index dependencies.
        $dbtype = get_class($DB);
        if (strpos($dbtype, 'pgsql') !== false || strpos($dbtype, 'postgres') !== false) {
            // PostgreSQL - try to drop foreign key constraint first if it exists
            try {
                $DB->execute("ALTER TABLE mdl_local_oerexchange_resources DROP CONSTRAINT mdl_locaoerereso_sit_fk CASCADE");
            } catch (Exception $e) {
                // Constraint might not exist, continue anyway
            }
            // Now make the column nullable
            $DB->execute("ALTER TABLE mdl_local_oerexchange_resources ALTER COLUMN siteid DROP NOT NULL");
        } else {
            // MySQL/MariaDB - drop foreign key if it exists
            try {
                $DB->execute("ALTER TABLE mdl_local_oerexchange_resources DROP FOREIGN KEY mdl_locaoerereso_sit_fk");
            } catch (Exception $e) {
                // Foreign key might not exist, continue anyway
            }
            // Now modify the column to be nullable
            $DB->execute("ALTER TABLE mdl_local_oerexchange_resources MODIFY siteid INT(10) NULL");
        }

        upgrade_plugin_savepoint(true, 2026072001, 'local', 'oerexchange');
    }

    return true;
}
