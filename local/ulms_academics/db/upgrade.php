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

    if ($oldversion < 2026091501) {
        // Savepoint 2026091501: Academic Hierarchy Consistency upgrade.
        //   - ADD COLUMN local_ulms_programme_courses.levelid bigint(10) NOT NULL DEFAULT 0
        //   - DROP any existing 3-column UNIQUE on (programmeid, moodlecourseid, semesterid)
        //     (found under several possible Moodle-generated xmldb names depending on
        //      the upgrade path that built this environment: programme_course_unique
        //      with [pid,cid]; or programme_course_per_semester_unique with
        //      [pid,sid,cid]; or the on-disk 3-col order [pid,cid,sid]).
        //   - ADD NEW 4-column UNIQUE on (programmeid, moodlecourseid, semesterid, levelid)
        //   - ADD INDEX (levelid, programmeid, semesterid) for efficient scope lookups
        //     used by exam_service / adoptions when filtering by Programme + Level +
        //     Semester to discover valid courses.
        //   - Best-effort data migration for existing rows: infer levelid from linked
        //     Moodle course shortname end-digits (e.g. ULMS-CS101 -> 100), fall back
        //     to 0 (= level-wide) when indeterminate. Levelid 0 is always treated as
        //     "any level" by downstream consumers, preserving backward compatibility
        //     with the pre-hierarchy scope resolver.

        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_ulms_programme_courses');

        if ($dbman->table_exists($table)) {
            $levelidfield = new xmldb_field(
                'levelid',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                null,
                '0'
            );
            if (!$dbman->field_exists($table, $levelidfield)) {
                $dbman->add_field($table, $levelidfield);
            }

            // Try dropping every possible legacy 2/3-col unique key shape so the new
            // 4-col unique succeeds regardless of which upgrade path created the
            // existing index on this installation. index_exists() is tolerant to
            // name/column mismatch so it is safe to probe.
            $legacyUniqueCandidates = [
                ['programme_course_unique',              ['programmeid', 'moodlecourseid']],
                ['programme_course_per_semester_unique', ['programmeid', 'semesterid', 'moodlecourseid']],
                ['programme_course_per_semester_unique', ['programmeid', 'moodlecourseid', 'semesterid']],
            ];
            foreach ($legacyUniqueCandidates as [$legacyName, $legacyCols]) {
                $legacyIdx = new xmldb_index($legacyName, XMLDB_INDEX_UNIQUE, $legacyCols);
                if ($dbman->index_exists($table, $legacyIdx)) {
                    try {
                        $dbman->drop_index($table, $legacyIdx);
                    } catch (\Throwable $e) {
                        debugging(
                            'local_ulms_academics 2026091501: drop legacy unique '
                            . $legacyName . ' cols=' . implode(',', $legacyCols)
                            . ' failed (continuing): ' . $e->getMessage(),
                            DEBUG_NORMAL
                        );
                    }
                }
            }

            $newUnique = new xmldb_index(
                'programme_course_full_hierarchy_unique',
                XMLDB_INDEX_UNIQUE,
                ['programmeid', 'moodlecourseid', 'semesterid', 'levelid']
            );
            if (!$dbman->index_exists($table, $newUnique)) {
                $dbman->add_index($table, $newUnique);
            }

            $scopeIdx = new xmldb_index(
                'level_programme_semester_scope_idx',
                XMLDB_INDEX_NOTUNIQUE,
                ['levelid', 'programmeid', 'semesterid']
            );
            if (!$dbman->index_exists($table, $scopeIdx)) {
                $dbman->add_index($table, $scopeIdx);
            }

            // ---- Data migration: best-effort levelid inference from course codes ----
            $rs = $DB->get_recordset_sql(
                "SELECT pc.id, pc.levelid, pc.moodlecourseid, c.shortname, c.fullname
                   FROM {local_ulms_programme_courses} pc
                   JOIN {course} c ON c.id = pc.moodlecourseid
                  WHERE pc.levelid = 0"
            );
            $levelsByCode = [];
            try {
                $allLevels = $DB->get_records_menu(
                    'local_ulms_levels',
                    ['status' => 'active'],
                    '',
                    'code, id'
                );
                if (is_array($allLevels)) {
                    $levelsByCode = $allLevels;
                }
            } catch (\Throwable $ignored) {
                $levelsByCode = [];
            }

            $updated = 0;
            $leftzero = 0;
            $now = time();
            if ($rs->valid()) {
                foreach ($rs as $row) {
                    $inferred = 0;
                    $probe = strtoupper((string)($row->shortname ?? '') . ' ' . (string)($row->fullname ?? ''));
                    // Match trailing digit pattern like CS101 -> 100-level, MATH201 -> 200, etc.
                    if (preg_match('/(\d)(\d)\d\b/', $probe, $m)) {
                        $tentativecode = $m[1] . '00';
                        if (isset($levelsByCode[$tentativecode])) {
                            $inferred = (int)$levelsByCode[$tentativecode];
                        }
                    }
                    // Also match explicit "100" "200" ... "600" tokens inside name.
                    if ($inferred === 0) {
                        foreach (array_keys($levelsByCode) as $code) {
                            if (preg_match('/(^|[^0-9])' . preg_quote($code, '/') . '([^0-9]|$)/', $probe)) {
                                $inferred = (int)$levelsByCode[$code];
                                break;
                            }
                        }
                    }
                    if ($inferred > 0) {
                        $DB->update_record('local_ulms_programme_courses', (object)[
                            'id' => (int)$row->id,
                            'levelid' => $inferred,
                            'timemodified' => $now,
                        ]);
                        $updated++;
                    } else {
                        $leftzero++;
                    }
                }
            }
            $rs->close();

            debugging(
                'local_ulms_academics 2026091501 programme_courses levelid migration: '
                . "inferred=$updated, left-wide=$leftzero",
                DEBUG_NORMAL
            );
            unset($levelsByCode, $rs, $now);
        }

        upgrade_plugin_savepoint(true, 2026091501, 'local', 'ulms_academics');
    }

    return true;
}
