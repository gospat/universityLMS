<?php
defined('MOODLE_INTERNAL') || die();

/**
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_ulms_exam_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026090800) {
        $table = new xmldb_table('local_ulms_exams');
        if (!$dbman->table_exists($table)) {
            $dbman->install_from_xmldb_file(__DIR__ . '/install.xml');
        }
        upgrade_plugin_savepoint(true, 2026090800, 'local', 'ulms_exam');
    }

    if ($oldversion < 2026091100) {
        $table = new xmldb_table('local_ulms_exam_questions');
        $field = new xmldb_field('questiontype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'single', 'points');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('local_ulms_exams');
        $field = new xmldb_field('gradeitemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timegraded');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026091100, 'local', 'ulms_exam');
    }

    if ($oldversion < 2026091101) {
        $table = new xmldb_table('local_ulms_submission_answers');
        $oldindex = new xmldb_index('subq_idx', XMLDB_INDEX_UNIQUE, ['submissionid', 'examquestionid']);
        if ($dbman->index_exists($table, $oldindex)) {
            $dbman->drop_index($table, $oldindex);
        }
        $newindex = new xmldb_index('subq_idx', XMLDB_INDEX_NOTUNIQUE, ['submissionid', 'examquestionid']);
        if (!$dbman->index_exists($table, $newindex)) {
            $dbman->add_index($table, $newindex);
        }
        $uniquechoice = new xmldb_index('subchoice_idx', XMLDB_INDEX_UNIQUE, ['submissionid', 'examquestionid', 'selected_choiceid']);
        if (!$dbman->index_exists($table, $uniquechoice)) {
            $dedup = "DELETE FROM {local_ulms_submission_answers}
                       WHERE id NOT IN (
                           SELECT MIN(id) FROM {local_ulms_submission_answers}
                            GROUP BY submissionid, examquestionid, selected_choiceid
                       )";
            try {
                $DB->execute($dedup);
            } catch (\Throwable $e) {
                if (function_exists('local_ulms_dashboard_log_operational_error')) {
                    local_ulms_dashboard_log_operational_error($e, 'exam_upgrade::2026091101::dedup', []);
                }
            }
            try {
                $dbman->add_index($table, $uniquechoice);
            } catch (\Throwable $e) {
                if (function_exists('local_ulms_dashboard_log_operational_error')) {
                    local_ulms_dashboard_log_operational_error($e, 'exam_upgrade::2026091101::add_index', []);
                }
                throw $e;
            }
        }
        upgrade_plugin_savepoint(true, 2026091101, 'local', 'ulms_exam');
    }

    return true;
}
