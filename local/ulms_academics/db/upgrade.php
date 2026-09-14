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

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade steps for the ULMS academics plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_ulms_academics_upgrade(int $oldversion): bool {
    global $DB;

    if ($oldversion < 2026062200) {
        upgrade_plugin_savepoint(true, 2026062200, 'local', 'ulms_academics');
    }

    if ($oldversion < 2026062600) {
        $dbman = $DB->get_manager();

        $table = new xmldb_table('local_ulms_programme_courses');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('programmeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('moodlecourseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('semesterid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('coursetype', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'core');
        $table->add_field('iscore', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('programme_fk', XMLDB_KEY_FOREIGN, ['programmeid'], 'local_ulms_programmes', ['id']);
        $table->add_key('semester_fk', XMLDB_KEY_FOREIGN, ['semesterid'], 'local_ulms_semesters', ['id']);

        $table->add_index('programme_idx', XMLDB_INDEX_NOTUNIQUE, ['programmeid']);
        $table->add_index('course_idx', XMLDB_INDEX_NOTUNIQUE, ['moodlecourseid']);
        $table->add_index('semester_idx', XMLDB_INDEX_NOTUNIQUE, ['semesterid']);
        $table->add_index('programme_course_unique', XMLDB_INDEX_UNIQUE, ['programmeid', 'moodlecourseid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026062600, 'local', 'ulms_academics');
    }

    if ($oldversion < 2026082601) {
        if ($DB->get_manager()->table_exists('local_ulms_faculties')) {
            $records = $DB->get_records('local_ulms_faculties', null, '', 'id, name');
            foreach ($records as $record) {
                $currentname = trim((string)($record->name ?? ''));
                if ($currentname === '') {
                    continue;
                }

                $updatedname = preg_replace('/^Faculty of\s+/i', 'College of ', $currentname);
                $updatedname = preg_replace('/^Faculty\s+/i', 'College ', (string)$updatedname);
                $updatedname = trim((string)$updatedname);

                if ($updatedname !== '' && $updatedname !== $currentname) {
                    $DB->update_record('local_ulms_faculties', (object)[
                        'id' => (int)$record->id,
                        'name' => $updatedname,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026082601, 'local', 'ulms_academics');
    }

    if ($oldversion < 2026090701) {
        $dbman = $DB->get_manager();

        $progCoursesTable = new xmldb_table('local_ulms_programme_courses');

        if ($dbman->table_exists($progCoursesTable)) {
            $oldIdx = new xmldb_index('programme_course_unique', XMLDB_INDEX_UNIQUE, ['programmeid', 'moodlecourseid']);
            if ($dbman->index_exists($progCoursesTable, $oldIdx)) {
                $dbman->drop_index($progCoursesTable, $oldIdx);
            }

            $newIdx = new xmldb_index('programme_course_per_semester_unique', XMLDB_INDEX_UNIQUE, ['programmeid', 'semesterid', 'moodlecourseid']);
            if (!$dbman->index_exists($progCoursesTable, $newIdx)) {
                $dbman->add_index($progCoursesTable, $newIdx);
            }

            $newkey = new xmldb_key('moodlecourse_fk', XMLDB_KEY_FOREIGN, ['moodlecourseid'], 'course', ['id']);
            if (!$dbman->key_exists($progCoursesTable, $newkey)) {
                try { $dbman->add_key($progCoursesTable, $newkey); } catch (\Throwable $e) { /* already exists or fk data violation: ignore */ }
            }
        }

        upgrade_plugin_savepoint(true, 2026090701, 'local', 'ulms_academics');
    }

    if ($oldversion < 2026091100) {
        // (No schema or data changes needed at this version; this savepoint only
        //  aligns the mdl_config_plugins plugin-version stamp with version.php.)
        upgrade_plugin_savepoint(true, 2026091100, 'local', 'ulms_academics');
    }

    return true;
}
