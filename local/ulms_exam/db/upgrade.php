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

    if ($oldversion < 2026091501) {
        $table = new xmldb_table('local_ulms_exams');

        $field = new xmldb_field('levelid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'semesterid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'levelid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $oldindex = new xmldb_index('scope_idx', XMLDB_INDEX_NOTUNIQUE, ['programmeid', 'semesterid', 'courseid']);
        if ($dbman->index_exists($table, $oldindex)) {
            $dbman->drop_index($table, $oldindex);
        }
        $newindex = new xmldb_index('scope_idx', XMLDB_INDEX_NOTUNIQUE, ['programmeid', 'levelid', 'sessionid', 'semesterid', 'courseid']);
        if (!$dbman->index_exists($table, $newindex)) {
            $dbman->add_index($table, $newindex);
        }

        upgrade_plugin_savepoint(true, 2026091501, 'local', 'ulms_exam');
    }

    if ($oldversion < 2026091601) {
        // 2026091601: Version parity savepoint; refreshes access.php + language caches.
        upgrade_plugin_savepoint(true, 2026091601, 'local', 'ulms_exam');
    }

    if ($oldversion < 2026091602) {
        // 2026091602: Referential integrity + ordernum uniqueness across exam child tables.
        // Applies ON DELETE CASCADE for examid/submissionid FKs, ON DELETE SET NULL for user/usermodified,
        // and UNIQUE(examid/bankquestionid + ordernum) constraints on exam_questions, bank_choices, question_choices.
        $fks = [
            ['local_ulms_exam_questions',   'exam_fk_cascade',      ['examid'],         'local_ulms_exams',     ['id'], XMLDB_KEY_CASCADE],
            ['local_ulms_exam_questions',   'usermodified_fk',     ['usermodified'],   'user',                 ['id'], XMLDB_KEY_SETNULL],
            ['local_ulms_exam_bank_choices','question_fk_cascade', ['bankquestionid'], 'local_ulms_exam_questions', ['id'], XMLDB_KEY_CASCADE],
            ['local_ulms_exam_bank_choices','usermodified_fk',     ['usermodified'],   'user',                 ['id'], XMLDB_KEY_SETNULL],
            ['local_ulms_exam_question_choices','submission_fk',   ['submissionid'],   'local_ulms_exam_submissions', ['id'], XMLDB_KEY_CASCADE],
            ['local_ulms_exam_question_choices','question_fk',     ['examquestionid'], 'local_ulms_exam_questions',   ['id'], XMLDB_KEY_CASCADE],
            ['local_ulms_exam_submissions', 'exam_fk_cascade',     ['examid'],         'local_ulms_exams',     ['id'], XMLDB_KEY_CASCADE],
            ['local_ulms_exam_submissions', 'user_fk_setnull',     ['examinee_userid'], 'user',                ['id'], XMLDB_KEY_SETNULL],
            ['local_ulms_exam_submissions', 'usermodified_fk',     ['usermodified'],   'user',                 ['id'], XMLDB_KEY_SETNULL],
            ['local_ulms_exam_gradings',    'submission_fk',       ['submissionid'],   'local_ulms_exam_submissions', ['id'], XMLDB_KEY_CASCADE],
            ['local_ulms_exam_gradings',    'exam_fk',             ['examid'],         'local_ulms_exams',     ['id'], XMLDB_KEY_CASCADE],
            ['local_ulms_exam_gradings',    'grader_fk',           ['grader_userid'],  'user',                 ['id'], XMLDB_KEY_SETNULL],
            ['local_ulms_exam_gradings',    'usermodified_fk',     ['usermodified'],   'user',                 ['id'], XMLDB_KEY_SETNULL],
        ];
        foreach ($fks as [$tname, $kname, $cols, $reftable, $refcols, $ondelete]) {
            $table = new xmldb_table($tname);
            if (!$dbman->table_exists($table)) {
                continue;
            }
            $key = new xmldb_key($kname, XMLDB_KEY_FOREIGN, $cols, $reftable, $refcols);
            $key->set_on_delete($ondelete);
            try {
                if (!$dbman->key_exists($table, $key)) {
                    $dbman->add_key($table, $key);
                }
            } catch (\Throwable) {
            }
        }
        $orderuniques = [
            ['local_ulms_exam_questions',         'exam_ordernum_unique',    ['examid',           'ordernum']],
            ['local_ulms_exam_bank_choices',      'bank_ordernum_unique',    ['bankquestionid',   'ordernum']],
            ['local_ulms_exam_question_choices',  'choice_ordernum_unique',  ['examquestionid',   'ordernum']],
        ];
        foreach ($orderuniques as [$tname, $name, $cols]) {
            $table = new xmldb_table($tname);
            if (!$dbman->table_exists($table)) {
                continue;
            }
            $idx = new xmldb_index($name, XMLDB_INDEX_UNIQUE, $cols);
            try {
                if (!$dbman->index_exists($table, $idx)) {
                    $dbman->add_index($table, $idx);
                }
            } catch (\Throwable) {
            }
        }
        upgrade_plugin_savepoint(true, 2026091602, 'local', 'ulms_exam');
    }

    if ($oldversion < 2026100100) {
        // 2026100100: Professional audit batch version stamp parity.
        upgrade_plugin_savepoint(true, 2026100100, 'local', 'ulms_exam');
    }

    return true;
}
