<?php
namespace local_ulms_exam\local\service;

use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class exam_service {
    private const EXAM_LOG_TABLE = 'local_ulms_user_management_log';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_GRADED = 'graded';
    public const SUBMISSION_IN_PROGRESS = 'in_progress';
    public const SUBMISSION_SUBMITTED = 'submitted';
    public const SUBMISSION_LATE_REJECTED = 'late_rejected';
    public const SUBMISSION_GRADED = 'graded';
    public const WINDOW_GRACE_SEC = 60;
    public const AUTOSAVE_EVERY_MS = 30000;
    public const MAX_QUESTIONS = 200;
    public const MIN_CHOICES = 2;
    public const MAX_CHOICES = 8;

    public static function instance(): self {
        static $singleton = null;
        if ($singleton === null) {
            $singleton = new self();
        }
        return $singleton;
    }

    public function current_user_can_manage_exam(int $examid): bool {
        global $DB, $USER;
        if (\is_siteadmin()) {
            return true;
        }
        $exam = $DB->get_record('local_ulms_exams', ['id' => $examid], '*', IGNORE_MISSING);
        if (!$exam) {
            return false;
        }
        /** @var \context $syscontext */
        $syscontext = \context::instance_by_id(\context_system::instance()->id);
        if (has_capability('local/ulms_exam:manageall', $syscontext)) {
            return true;
        }
        if ((int)$exam->courseid > 0) {
            try {
                $coursecontextid = @\context_course::instance($exam->courseid);
                /** @var \context $ctx */
                $coursectx = \context::instance_by_id($coursecontextid->id);
                if (has_capability('local/ulms_exam:manageown', $coursectx) &&
                    has_capability('moodle/course:manageactivities', $coursectx)) {
                    try {
                        $repository = new \local_ulms_academics\local\repository\academic_repository();
                        $mappingexists = (bool)$repository->get_course_mapping_by_hierarchy(
                            (int)$exam->programmeid,
                            (int)$exam->courseid,
                            (int)$exam->semesterid,
                            (int)($exam->levelid ?? 0)
                        );
                    } catch (\Throwable $e) {
                        $conditions = [
                            'programmeid' => (int)$exam->programmeid,
                            'moodlecourseid' => (int)$exam->courseid,
                        ];
                        if ((int)$exam->semesterid > 0) {
                            $conditions['semesterid'] = (int)$exam->semesterid;
                        }
                        if (!empty($exam->levelid) && (int)$exam->levelid > 0) {
                            $conditions['levelid'] = (int)$exam->levelid;
                        }
                        $mappingexists = $DB->record_exists('local_ulms_programme_courses', $conditions);
                    }
                    if ($mappingexists) {
                        return true;
                    }
                    // Fallback: if user created the exam, still allow editing in draft state only.
                    return (int)$exam->createdby === (int)$USER->id && $exam->status === self::STATUS_DRAFT;
                }
            } catch (\Throwable $exception) {
                if (function_exists('local_ulms_dashboard_log_operational_error')) {
                    local_ulms_dashboard_log_operational_error($exception, 'exam_service::current_user_can_manage_exam', []);
                }
                return false;
            }
        }
        return false;
    }

    public function require_manage_exam(int $examid): void {
        if (!$this->current_user_can_manage_exam($examid)) {
            throw new moodle_exception('examtakeforbidden', 'local_ulms_exam', '', null, 'manage exam forbidden');
        }
    }

    public function list_lecturer_exams_for_current_user(): array {
        global $DB, $USER;
        $siteadmin = \is_siteadmin();
        /** @var \context $syscontext */
        $syscontext = \context::instance_by_id(\context_system::instance()->id);
        $canmanageall = has_capability('local/ulms_exam:manageall', $syscontext);
        if ($siteadmin || $canmanageall) {
            return array_values($DB->get_records('local_ulms_exams', null, 'timemodified DESC'));
        }
        $mycourses = enrol_get_all_users_courses($USER->id, true);
        $rows = [];
        if (!empty($mycourses)) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($mycourses), SQL_PARAMS_NAMED);
            $sql = "SELECT e.* FROM {local_ulms_exams} e
                     WHERE e.courseid $insql
                        OR e.createdby = :uid
                     ORDER BY e.timemodified DESC";
            $rows = $DB->get_records_sql($sql, $inparams + ['uid' => (int)$USER->id]);
        } else {
            $rows = $DB->get_records('local_ulms_exams', ['createdby' => (int)$USER->id], 'timemodified DESC');
        }
        return array_values($rows);
    }

    public function get_programme_course_options_for_lecturer(): array {
        $scope = $this->get_lecturer_hierarchy_scope();
        return [
            'courses' => $scope['courses'],
            'programmes' => $scope['programmes'],
            'semesters' => $scope['semesters'],
            'mappings' => $scope['mappings_flat_keys'],
        ];
    }

    public function get_lecturer_hierarchy_scope(): array {
        global $DB, $USER;

        $siteadmin = \is_siteadmin();
        /** @var \context $syscontext */
        $syscontext = \context::instance_by_id(\context_system::instance()->id);
        $canmanageall = has_capability('local/ulms_exam:manageall', $syscontext);

        $profile_table = 'local_ulms_user_profile';
        $profile_table_exists = $DB->get_manager()->table_exists(new \xmldb_table($profile_table));

        $mycourses = enrol_get_all_users_courses($USER->id, true);
        $courseids = array_values(array_map(static fn($c): int => (int)$c->id, $mycourses));

        $profile_facultyid = 0;
        $profile_deptid = 0;
        $profile_programmeid = 0;
        $profile_level_code = '';
        if ($profile_table_exists) {
            $profile = $DB->get_record($profile_table, ['userid' => (int)$USER->id], '*', IGNORE_MISSING);
            if ($profile) {
                $profile_facultyid = (int)($profile->facultyid ?? 0);
                $profile_deptid = (int)($profile->departmentid ?? 0);
                $profile_programmeid = (int)($profile->programmeid ?? 0);
                $profile_level_code = trim((string)($profile->studylevel ?? ''));
            }
        }

        $scoped_courseids = $courseids;
        $scoped_programmeids = [];
        $scoped_departmentids = [];
        $scoped_facultyids = [];
        $scoped_levelids = [];
        $scoped_level_codes = [];
        $scoped_sessionids = [];
        $scoped_semesterids = [];

        if ($siteadmin || $canmanageall) {
            $scoped_facultyids = array_keys($DB->get_records_menu('local_ulms_faculties', ['status' => 'active'], 'name ASC', 'id,name'));
            $scoped_departmentids = array_keys($DB->get_records_menu('local_ulms_departments', ['status' => 'active'], 'name ASC', 'id,name'));
            $scoped_programmeids = array_keys($DB->get_records_menu('local_ulms_programmes', ['status' => 'active'], 'name ASC', 'id,name'));
            $allcourses = $DB->get_records_menu('course', null, 'fullname ASC', 'id,fullname');
            unset($allcourses[1]);
            $scoped_courseids = array_keys($allcourses);
            $scoped_levelids = array_keys($DB->get_records_menu('local_ulms_levels', ['status' => 'active'], 'sortorder ASC, code ASC', 'id,code'));
            $scoped_sessionids = array_keys($DB->get_records_menu('local_ulms_sessions', null, 'startdate DESC, name ASC', 'id,name'));
            $scoped_semesterids = array_keys($DB->get_records_menu('local_ulms_semesters', null, 'startdate DESC, code ASC', 'id,name'));
        } else {
            if ($profile_facultyid > 0) {
                $scoped_facultyids[] = $profile_facultyid;
            }
            if ($profile_deptid > 0) {
                $scoped_departmentids[] = $profile_deptid;
            }
            if ($profile_programmeid > 0) {
                $scoped_programmeids[] = $profile_programmeid;
            }
            if ($profile_level_code !== '' && $DB->get_manager()->table_exists(new \xmldb_table('local_ulms_levels'))) {
                $levelrec = $DB->get_record('local_ulms_levels', ['code' => $profile_level_code, 'status' => 'active'], '*', IGNORE_MISSING);
                if ($levelrec) {
                    $scoped_levelids[] = (int)$levelrec->id;
                    $scoped_level_codes[(int)$levelrec->id] = $profile_level_code;
                }
            }
            if ($profile_deptid > 0 && empty($scoped_programmeids)) {
                $childprogs = $DB->get_records_menu('local_ulms_programmes', ['departmentid' => $profile_deptid, 'status' => 'active'], 'name ASC', 'id,name');
                $scoped_programmeids = array_merge($scoped_programmeids, array_keys($childprogs));
            }
            if ($profile_facultyid > 0 && empty($scoped_departmentids)) {
                $childdepts = $DB->get_records_menu('local_ulms_departments', ['facultyid' => $profile_facultyid, 'status' => 'active'], 'name ASC', 'id,name');
                $scoped_departmentids = array_merge($scoped_departmentids, array_keys($childdepts));
            }
            if (!empty($scoped_departmentids) && empty($scoped_programmeids)) {
                [$din, $dparams] = $DB->get_in_or_equal($scoped_departmentids, SQL_PARAMS_NAMED);
                $progs = $DB->get_records_sql_menu("SELECT id, name FROM {local_ulms_programmes} WHERE status = 'active' AND departmentid $din", $dparams);
                $scoped_programmeids = array_unique(array_merge($scoped_programmeids, array_keys($progs)));
            }
        }

        $mappings_by_course = [];
        $mappings_all = [];
        $mapping_select = "SELECT pc.id, pc.programmeid, pc.moodlecourseid, pc.semesterid, pc.levelid, pc.coursetype
                            FROM {local_ulms_programme_courses} pc";
        $mapping_where = [];
        $mapping_params = [];
        if (!$siteadmin && !$canmanageall) {
            $ors = [];
            if (!empty($courseids)) {
                [$cin, $cparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'mc');
                $ors[] = "pc.moodlecourseid $cin";
                $mapping_params = array_merge($mapping_params, $cparams);
            }
            if (!empty($scoped_programmeids)) {
                [$pin, $pparams] = $DB->get_in_or_equal($scoped_programmeids, SQL_PARAMS_NAMED, 'mp');
                $ors[] = "pc.programmeid $pin";
                $mapping_params = array_merge($mapping_params, $pparams);
            }
            if (!empty($ors)) {
                $mapping_where[] = '(' . implode(' OR ', $ors) . ')';
            }
        }
        $mapping_sql = $mapping_select;
        if (!empty($mapping_where)) {
            $mapping_sql .= ' WHERE ' . implode(' AND ', $mapping_where);
        }
        $mapping_rs = $DB->get_recordset_sql($mapping_sql, $mapping_params);
        $mappings_flat_keys = [];
        foreach ($mapping_rs as $m) {
            $mid = (int)$m->id;
            $pid = (int)$m->programmeid;
            $cid = (int)$m->moodlecourseid;
            $sid = (int)$m->semesterid;
            $lid = (int)$m->levelid;
            $mappings_all[$mid] = [
                'id' => $mid,
                'programmeid' => $pid,
                'courseid' => $cid,
                'semesterid' => $sid,
                'levelid' => $lid,
                'coursetype' => (string)($m->coursetype ?? 'core'),
            ];
            $mappings_flat_keys[] = "{$pid}_{$cid}_{$sid}";
            if (!in_array($pid, $scoped_programmeids, true)) {
                $scoped_programmeids[] = $pid;
            }
            if (!in_array($cid, $scoped_courseids, true)) {
                $scoped_courseids[] = $cid;
            }
            if ($sid > 0 && !in_array($sid, $scoped_semesterids, true)) {
                $scoped_semesterids[] = $sid;
            }
            if ($lid > 0 && !in_array($lid, $scoped_levelids, true)) {
                $scoped_levelids[] = $lid;
            }
            if (!isset($mappings_by_course[$pid])) {
                $mappings_by_course[$pid] = [];
            }
            if (!isset($mappings_by_course[$pid][$lid])) {
                $mappings_by_course[$pid][$lid] = [];
            }
            if (!isset($mappings_by_course[$pid][$lid][$sid])) {
                $mappings_by_course[$pid][$lid][$sid] = [];
            }
            $mappings_by_course[$pid][$lid][$sid][] = $cid;
        }
        $mapping_rs->close();

        if (!empty($scoped_programmeids) && (empty($scoped_departmentids) || empty($scoped_facultyids))) {
            [$pin, $pparams] = $DB->get_in_or_equal($scoped_programmeids, SQL_PARAMS_NAMED, 'pg');
            $progdeps = $DB->get_records_sql_menu("SELECT id, departmentid FROM {local_ulms_programmes} WHERE id $pin", $pparams);
            $deptids = array_values(array_unique(array_map(static fn($v): int => (int)$v, $progdeps)));
            foreach ($deptids as $did) {
                if ($did > 0 && !in_array($did, $scoped_departmentids, true)) {
                    $scoped_departmentids[] = $did;
                }
            }
            if (!empty($scoped_departmentids)) {
                [$din, $dparams] = $DB->get_in_or_equal($scoped_departmentids, SQL_PARAMS_NAMED, 'dg');
                $depfacs = $DB->get_records_sql_menu("SELECT id, facultyid FROM {local_ulms_departments} WHERE id $din", $dparams);
                foreach ($depfacs as $fid) {
                    $fid = (int)$fid;
                    if ($fid > 0 && !in_array($fid, $scoped_facultyids, true)) {
                        $scoped_facultyids[] = $fid;
                    }
                }
            }
        }

        if (!empty($scoped_semesterids)) {
            [$sein, $separams] = $DB->get_in_or_equal($scoped_semesterids, SQL_PARAMS_NAMED, 'se');
            $semsess = $DB->get_records_sql_menu("SELECT id, sessionid FROM {local_ulms_semesters} WHERE id $sein", $separams);
            foreach ($semsess as $ssid) {
                $ssid = (int)$ssid;
                if ($ssid > 0 && !in_array($ssid, $scoped_sessionids, true)) {
                    $scoped_sessionids[] = $ssid;
                }
            }
        }

        $flat = static function(string $table, ?array $ids, string $orderby = 'name ASC', ?string $extrawhere = null, array $extraparams = []): array {
            global $DB;
            if (!$DB->get_manager()->table_exists(new \xmldb_table($table))) {
                return [];
            }
            $where = [];
            $params = $extraparams;
            if ($extrawhere !== null && $extrawhere !== '') {
                $where[] = $extrawhere;
            }
            if ($ids !== null && !empty($ids)) {
                [$in, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'flt');
                $where[] = "id $in";
                $params = array_merge($params, $inparams);
            }
            $sql = "SELECT id, name FROM {{$table}}";
            if (!empty($where)) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= " ORDER BY {$orderby}";
            return array_map(static fn($v): string => format_string($v), $DB->get_records_sql_menu($sql, $params));
        };

        $faculties = $flat('local_ulms_faculties', empty($scoped_facultyids) ? null : $scoped_facultyids, 'name ASC');
        $departments = $flat('local_ulms_departments', empty($scoped_departmentids) ? null : $scoped_departmentids, 'name ASC');
        $programmes = $flat('local_ulms_programmes', empty($scoped_programmeids) ? null : $scoped_programmeids, 'name ASC', 'status = :st', ['st' => 'active']);
        $levels = $DB->get_manager()->table_exists(new \xmldb_table('local_ulms_levels'))
            ? $flat('local_ulms_levels', empty($scoped_levelids) ? null : $scoped_levelids, 'sortorder ASC, code ASC', 'status = :st', ['st' => 'active'])
            : [];
        $sessions = $flat('local_ulms_sessions', empty($scoped_sessionids) ? null : $scoped_sessionids, 'startdate DESC, name ASC');
        $semesters = $flat('local_ulms_semesters', empty($scoped_semesterids) ? null : $scoped_semesterids, 'startdate DESC, code ASC');
        $courses = [];
        if (!empty($scoped_courseids)) {
            [$cin, $cparams] = $DB->get_in_or_equal($scoped_courseids, SQL_PARAMS_NAMED, 'co');
            $crs = $DB->get_records_sql_menu("SELECT id, fullname FROM {course} WHERE id $cin ORDER BY fullname ASC", $cparams);
            unset($crs[1]);
            $courses = array_map(static fn($v): string => format_string($v), $crs);
        }

        $hierarchy = [];
        foreach ($mappings_by_course as $pid => $levelmap) {
            if (!isset($programmes[$pid])) {
                continue;
            }
            $hierarchy[$pid] = [];
            foreach ($levelmap as $lid => $semmap) {
                $hierarchy[$pid][$lid] = [];
                foreach ($semmap as $sid => $coursearr) {
                    $hierarchy[$pid][$lid][$sid] = array_values(array_unique(array_map(static fn($v): int => (int)$v, $coursearr)));
                }
            }
        }

        $session_semesters = [];
        if (!empty($scoped_semesterids)) {
            [$sein, $separams] = $DB->get_in_or_equal($scoped_semesterids, SQL_PARAMS_NAMED, 'sm');
            $rs = $DB->get_recordset_sql("SELECT id, sessionid FROM {local_ulms_semesters} WHERE id $sein", $separams);
            foreach ($rs as $row) {
                $sid = (int)$row->sessionid;
                $seid = (int)$row->id;
                if ($sid <= 0) {
                    continue;
                }
                if (!isset($session_semesters[$sid])) {
                    $session_semesters[$sid] = [];
                }
                if (!in_array($seid, $session_semesters[$sid], true)) {
                    $session_semesters[$sid][] = $seid;
                }
            }
            $rs->close();
        }

        $programme_sessions = [];
        foreach ($mappings_all as $m) {
            $pid = $m['programmeid'];
            $semid = $m['semesterid'];
            if ($semid <= 0) {
                continue;
            }
            $sessid = (int)($DB->get_field('local_ulms_semesters', 'sessionid', ['id' => $semid]) ?: 0);
            if ($sessid <= 0) {
                continue;
            }
            if (!isset($programme_sessions[$pid])) {
                $programme_sessions[$pid] = [];
            }
            if (!in_array($sessid, $programme_sessions[$pid], true)) {
                $programme_sessions[$pid][] = $sessid;
            }
        }

        return [
            'faculties' => $faculties,
            'departments' => $departments,
            'programmes' => $programmes,
            'levels' => $levels,
            'sessions' => $sessions,
            'semesters' => $semesters,
            'courses' => $courses,
            'hierarchy' => $hierarchy,
            'session_semesters' => $session_semesters,
            'programme_sessions' => $programme_sessions,
            'mappings' => array_values($mappings_all),
            'mappings_flat_keys' => $mappings_flat_keys,
        ];
    }

    public function create_exam(array $data): stdClass {
        global $DB, $USER;
        $data = $this->normalise_exam_data($data, null);
        /** @var mixed $syscontext */ $syscontext = \context_system::instance();
        $canmanageall = has_capability('local/ulms_exam:manageall', $syscontext);
        $hasmanageown_somewhere = false;
        if (!$canmanageall && !\is_siteadmin()) {
            $cid = (int)($data['courseid'] ?? 0);
            if ($cid > 0) {
                /** @var mixed $coursectx */ $coursectx = \context_course::instance($cid, IGNORE_MISSING);
                if ($coursectx && has_capability('local/ulms_exam:manageown', $coursectx) && has_capability('moodle/course:manageactivities', $coursectx)) {
                    $hasmanageown_somewhere = true;
                }
            }
            if (!$hasmanageown_somewhere) {
                $mycourses = enrol_get_all_users_courses($USER->id, true, ['id'], 'id ASC');
                foreach ($mycourses as $c) {
                    /** @var mixed $ctx */ $ctx = \context_course::instance($c->id, IGNORE_MISSING);
                    if ($ctx && has_capability('local/ulms_exam:manageown', $ctx) && has_capability('moodle/course:manageactivities', $ctx)) {
                        $hasmanageown_somewhere = true;
                        break;
                    }
                }
            }
            if (!$hasmanageown_somewhere) {
                throw new moodle_exception('nopermissions', 'error', '', null, 'create_exam manage capability');
            }
        }
        $this->validate_exam_window($data);
        $pid = (int)($data['programmeid'] ?? 0);
        $lid = (int)($data['levelid'] ?? 0);
        $sid = (int)($data['sessionid'] ?? 0);
        $emid = (int)($data['semesterid'] ?? 0);
        $cid = (int)($data['courseid'] ?? 0);
        if ($pid > 0 && $cid > 0) {
            $mappingconditions = ['programmeid' => $pid, 'moodlecourseid' => $cid];
            if ($emid > 0) {
                $mappingconditions['semesterid'] = $emid;
            }
            if ($lid > 0) {
                $mappingconditions['levelid'] = $lid;
            }
            try {
                $repository = new \local_ulms_academics\local\repository\academic_repository();
                $existing = $repository->get_course_mapping_by_hierarchy($pid, $cid, $emid, $lid);
                $mappingexists = (bool)$existing;
            } catch (\Throwable $e) {
                $mappingexists = $DB->record_exists('local_ulms_programme_courses', $mappingconditions);
            }
            if (!$mappingexists) {
                throw new moodle_exception('coursetypenotinprogramme', 'local_ulms_exam', '', null,
                    "programme={$pid} level={$lid} session={$sid} semester={$emid} course={$cid}");
            }
        }
        $now = time();
        $record = (object)array_merge([
            'createdby' => (int)$USER->id,
            'usermodified' => (int)$USER->id,
            'timecreated' => $now,
            'timemodified' => $now,
            'timepublished' => 0,
            'timeclosed' => 0,
            'timegraded' => 0,
            'status' => self::STATUS_DRAFT,
        ], $data);
        $record->id = (int)$DB->insert_record('local_ulms_exams', $record);
        $this->audit_log(
            (int)$USER->id,
            'EXAM_CREATED',
            'success',
            "Exam '{$record->title}' (id {$record->id}) created.",
            [
                'examid' => $record->id,
                'programmeid' => $record->programmeid,
                'semesterid' => $record->semesterid,
                'courseid' => $record->courseid,
                'start_ts' => $record->start_ts,
                'end_ts' => $record->end_ts,
                'status' => $record->status,
            ]
        );
        return $record;
    }

    public function update_exam(int $examid, array $data): stdClass {
        global $DB, $USER;
        $this->require_manage_exam($examid);
        $existing = $DB->get_record('local_ulms_exams', ['id' => $examid], '*', MUST_EXIST);
        if ($existing->status === self::STATUS_GRADED) {
            $whitelist = ['title', 'instructions'];
            $data = array_intersect_key($data, array_fill_keys($whitelist, true));
        } elseif (in_array($existing->status, [self::STATUS_PUBLISHED, self::STATUS_CLOSED], true)) {
            $whitelist = ['title', 'instructions', 'durationsec'];
            $data = array_intersect_key($data, array_fill_keys($whitelist, true));
        }
        $data = $this->normalise_exam_data($data, $existing);
        // Re-validate time window only if start_ts/end_ts touched.
        if (isset($data['start_ts']) || isset($data['end_ts'])) {
            $this->validate_exam_window([
                'start_ts' => (int)($data['start_ts'] ?? $existing->start_ts),
                'end_ts' => (int)($data['end_ts'] ?? $existing->end_ts),
            ]);
        }
        foreach ($data as $k => $v) {
            if (property_exists($existing, $k)) {
                $existing->$k = $v;
            }
        }
        $existing->usermodified = (int)$USER->id;
        $existing->timemodified = time();
        $DB->update_record('local_ulms_exams', $existing);
        return $existing;
    }

    public function publish_exam(int $examid): stdClass {
        global $DB, $USER;
        $this->require_manage_exam($examid);
        $exam = $DB->get_record('local_ulms_exams', ['id' => $examid], '*', MUST_EXIST);
        $activequestions = $DB->count_records_select(
            'local_ulms_exam_questions',
            'examid = :eid AND isactive = 1',
            ['eid' => $examid]
        );
        if ($activequestions <= 0) {
            throw new moodle_exception('publishemptyerror', 'local_ulms_exam', '', null, 'no questions');
        }
        $this->validate_exam_window(['start_ts' => (int)$exam->start_ts, 'end_ts' => (int)$exam->end_ts]);
        $valid_from = [self::STATUS_DRAFT];
        if (!in_array($exam->status, $valid_from, true)) {
            throw new moodle_exception('invalidstatustransition', 'local_ulms_exam', '', null, "publish from {$exam->status}");
        }
        // Verify every active question has 2-8 choices with exactly one correct.
        $problems = $this->audit_questions_for_publish($examid);
        if (!empty($problems)) {
            throw new moodle_exception('publishquestionserror', 'local_ulms_exam', '', null, implode('; ', $problems));
        }
        $exam->status = self::STATUS_PUBLISHED;
        $exam->timepublished = time();
        $exam->timemodified = $exam->timepublished;
        $exam->usermodified = (int)$USER->id;
        $DB->update_record('local_ulms_exams', $exam);
        $this->audit_log(
            (int)$USER->id,
            'EXAM_PUBLISHED',
            'success',
            "Exam '{$exam->title}' (id {$exam->id}) published with {$activequestions} active questions.",
            ['examid' => $examid, 'questioncount' => $activequestions]
        );
        return $exam;
    }

    public function close_exam(int $examid): stdClass {
        global $DB, $USER;
        $this->require_manage_exam($examid);
        $exam = $DB->get_record('local_ulms_exams', ['id' => $examid], '*', MUST_EXIST);
        $valid_from = [self::STATUS_PUBLISHED];
        if (!in_array($exam->status, $valid_from, true)) {
            throw new moodle_exception('invalidstatustransition', 'local_ulms_exam', '', null, "close from {$exam->status}");
        }
        $exam->status = self::STATUS_CLOSED;
        $exam->timeclosed = time();
        $exam->timemodified = $exam->timeclosed;
        $exam->usermodified = (int)$USER->id;
        $DB->update_record('local_ulms_exams', $exam);
        return $exam;
    }

    private function audit_questions_for_publish(int $examid): array {
        global $DB;
        $problems = [];
        $questions = $DB->get_records('local_ulms_exam_questions', ['examid' => $examid, 'isactive' => 1], 'ordernum ASC');
        foreach ($questions as $q) {
            $qtype = property_exists($q, 'questiontype') && in_array((string)$q->questiontype, ['single', 'multi'], true)
                ? (string)$q->questiontype
                : 'single';
            $choices = $DB->get_records('local_ulms_question_choices', ['examquestionid' => $q->id], 'ordernum ASC');
            $count = count($choices);
            $minc = $qtype === 'multi' ? 3 : self::MIN_CHOICES;
            $maxc = self::MAX_CHOICES;
            if ($count < $minc || $count > $maxc) {
                $a = (object)['ordernum' => (int)$q->ordernum, 'min' => $minc, 'max' => $maxc, 'actual' => $count];
                $problems[] = get_string('invalidchoicecountrange', 'local_ulms_exam', $a);
                continue;
            }
            $correctcount = 0;
            foreach ($choices as $c) {
                if (!empty($c->iscorrect)) {
                    $correctcount++;
                }
            }
            if ($qtype === 'single') {
                if ($correctcount !== 1) {
                    $a = (object)['ordernum' => (int)$q->ordernum, 'actual' => $correctcount];
                    $problems[] = get_string('singleinvalidcorrectcount', 'local_ulms_exam', $a);
                }
            } else {
                $maxcorrect = $count - 1;
                if ($correctcount < 2 || $correctcount > $maxcorrect) {
                    $a = (object)['ordernum' => (int)$q->ordernum, 'maxcorrect' => $maxcorrect, 'actual' => $correctcount];
                    $problems[] = get_string('multiinvalidcorrectcount', 'local_ulms_exam', $a);
                }
            }
        }
        return $problems;
    }

    public function get_exam(int $examid, bool $forcestrict = true): ?stdClass {
        global $DB;
        $record = $DB->get_record('local_ulms_exams', ['id' => $examid], '*', IGNORE_MISSING);
        if (!$record) {
            return null;
        }
        if ($forcestrict) {
            $this->require_manage_exam($examid);
        }
        return $record;
    }

    public function get_exam_with_questions(int $examid, bool $forcestrict = true, int $shuffleseed = 0): array {
        global $DB;
        $exam = $this->get_exam($examid, $forcestrict);
        if (!$exam) {
            return ['exam' => null, 'questions' => []];
        }
        $questions = array_values($DB->get_records(
            'local_ulms_exam_questions',
            ['examid' => $examid, 'isactive' => 1],
            'ordernum ASC'
        ));
        $shuffleq = !empty($exam->shufflequestions) && $shuffleseed > 0;
        if ($shuffleq) {
            mt_srand($shuffleseed);
            $count = count($questions);
            for ($i = $count - 1; $i > 0; $i--) {
                $j = mt_rand(0, $i);
                [$questions[$i], $questions[$j]] = [$questions[$j], $questions[$i]];
            }
            mt_srand();
        }
        $qmap = [];
        foreach ($questions as $q) {
            $choices = array_values($DB->get_records(
                'local_ulms_question_choices',
                ['examquestionid' => (int)$q->id],
                'ordernum ASC'
            ));
            $perqshuffle = (int)$q->shufflechoices_override;
            $shouldshuffle = $perqshuffle === 1 || ($perqshuffle === -1 && !empty($exam->shufflechoices));
            if ($shouldshuffle && $shuffleseed > 0) {
                mt_srand($shuffleseed + (int)$q->id);
                $ccount = count($choices);
                for ($i = $ccount - 1; $i > 0; $i--) {
                    $j = mt_rand(0, $i);
                    [$choices[$i], $choices[$j]] = [$choices[$j], $choices[$i]];
                }
                mt_srand();
            }
            $qmap[(int)$q->id] = $choices;
        }
        return ['exam' => $exam, 'questions' => $questions, 'choices_by_question' => $qmap];
    }

    public function save_questions(int $examid, array $questionsinput, bool $addtobank = false): array {
        global $DB, $USER;
        $this->require_manage_exam($examid);
        $exam = $DB->get_record('local_ulms_exams', ['id' => $examid], '*', MUST_EXIST);
        if ($exam->status !== self::STATUS_DRAFT) {
            throw new moodle_exception('editlockedquestions', 'local_ulms_exam', '', null, 'locked');
        }
        $now = time();
        $saved = 0;
        $idscreated = [];
        $ordernum = 1;
        $existingperqcount = $DB->count_records_select('local_ulms_exam_questions', 'examid = :eid', ['eid' => $examid]);
        if ($existingperqcount + count($questionsinput) > self::MAX_QUESTIONS) {
            throw new moodle_exception('maxquestions', 'local_ulms_exam', '', null, 'max ' . self::MAX_QUESTIONS);
        }
        foreach ($questionsinput as $in) {
            $stem = trim((string)($in['stem_html'] ?? ''));
            if ($stem === '') {
                continue;
            }
            $points = max(1, (int)($in['points'] ?? 1));
            $bankquestionid = 0;
            if ($addtobank) {
                $bankrec = (object)[
                    'programmeid' => (int)$exam->programmeid,
                    'semesterid' => (int)$exam->semesterid,
                    'courseid' => (int)$exam->courseid,
                    'createdby' => (int)$USER->id,
                    'stem_html' => $stem,
                    'sharedlevel' => 'programme',
                    'status' => 'active',
                    'usermodified' => (int)$USER->id,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ];
                $bankquestionid = (int)$DB->insert_record('local_ulms_question_bank', $bankrec);
            }
            $rawtype = $in['questiontype'] ?? 'single';
            $qtype = in_array((string)$rawtype, ['single', 'multi'], true) ? (string)$rawtype : 'single';
            $qrec = (object)[
                'examid' => $examid,
                'questionbankid' => $bankquestionid,
                'stem_html' => $stem,
                'questiontype' => $qtype,
                'ordernum' => $ordernum++,
                'points' => $points,
                'shufflechoices_override' => isset($in['shufflechoices_override']) ? (int)$in['shufflechoices_override'] : -1,
                'isactive' => 1,
                'usermodified' => (int)$USER->id,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $qid = (int)$DB->insert_record('local_ulms_exam_questions', $qrec);
            $idscreated[] = $qid;
            $saved++;
            $choices = $in['choices'] ?? [];
            $cnum = 0;
            foreach ($choices as $c) {
                $text = trim((string)($c['choice_html'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $cnum++;
                $crec = (object)[
                    'examquestionid' => $qid,
                    'choice_html' => $text,
                    'iscorrect' => !empty($c['iscorrect']) ? 1 : 0,
                    'ordernum' => $cnum,
                    'usermodified' => (int)$USER->id,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ];
                $_cid = (int)$DB->insert_record('local_ulms_question_choices', $crec);
                if ($bankquestionid > 0) {
                    $DB->insert_record('local_ulms_question_bank_choices', (object)[
                        'bankquestionid' => $bankquestionid,
                        'choice_html' => $text,
                        'iscorrect' => $crec->iscorrect,
                        'ordernum' => $cnum,
                        'usermodified' => (int)$USER->id,
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ]);
                }
            }
        }
        return ['saved' => $saved, 'ids' => $idscreated];
    }

    public function import_from_bank(int $examid, array $bankquestionids): int {
        global $DB, $USER;
        $this->require_manage_exam($examid);
        $exam = $DB->get_record('local_ulms_exams', ['id' => $examid], '*', MUST_EXIST);
        if ($exam->status !== self::STATUS_DRAFT) {
            throw new moodle_exception('editlockedquestions', 'local_ulms_exam', '', null, 'locked');
        }
        if (empty($bankquestionids)) {
            return 0;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($bankquestionids, SQL_PARAMS_NAMED);
        $bankrows = $DB->get_records_sql(
            "SELECT * FROM {local_ulms_question_bank} WHERE id $insql AND status = 'active'",
            $inparams
        );
        $now = time();
        $currentmax = (int)$DB->get_field_select(
            'local_ulms_exam_questions',
            'COALESCE(MAX(ordernum), 0)',
            'examid = :eid',
            ['eid' => $examid]
        );
        $imported = 0;
        foreach ($bankrows as $bq) {
            if ((int)$bq->programmeid !== 0 && (int)$bq->programmeid !== (int)$exam->programmeid) {
                continue;
            }
            $currentmax++;
            $choices = $DB->get_records(
                'local_ulms_question_bank_choices',
                ['bankquestionid' => (int)$bq->id],
                'ordernum ASC'
            );
            $importqtype = property_exists($bq, 'questiontype') && in_array((string)$bq->questiontype, ['single', 'multi'], true)
                ? (string)$bq->questiontype : 'single';
            $qid = (int)$DB->insert_record('local_ulms_exam_questions', (object)[
                'examid' => $examid,
                'questionbankid' => (int)$bq->id,
                'stem_html' => $bq->stem_html,
                'questiontype' => $importqtype,
                'ordernum' => $currentmax,
                'points' => 1,
                'shufflechoices_override' => -1,
                'isactive' => 1,
                'usermodified' => (int)$USER->id,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            foreach ($choices as $bc) {
                $DB->insert_record('local_ulms_question_choices', (object)[
                    'examquestionid' => $qid,
                    'choice_html' => $bc->choice_html,
                    'iscorrect' => (int)$bc->iscorrect,
                    'ordernum' => (int)$bc->ordernum,
                    'usermodified' => (int)$USER->id,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            }
            $imported++;
        }
        return $imported;
    }

    public function list_bank_items(int $programmeid, int $courseid = 0, int $semesterid = 0): array {
        global $DB;
        $where = ['programmeid' => $programmeid, 'status' => 'active'];
        if ($courseid > 0) {
            $where['courseid'] = $courseid;
        }
        if ($semesterid > 0) {
            $where['semesterid'] = $semesterid;
        }
        return array_values($DB->get_records('local_ulms_question_bank', $where, 'timemodified DESC'));
    }

    /**
     * Programme-gated listing of exams a student can see (including in_window checks).
     * Returns [rows, hasprogramme(bool), friendlymessage]
     */
    public function list_student_exams(int $studentuserid): array {
        global $DB;
        $profile = $DB->get_record('local_ulms_user_profile', ['userid' => $studentuserid], '*', IGNORE_MISSING);
        if (!$profile || (int)$profile->programmeid <= 0) {
            return [
                'rows' => [],
                'hasprogramme' => false,
                'friendly' => get_string('noprogrammeassignederror', 'local_ulms_exam'),
            ];
        }
        $levelid = 0;
        if (!empty($profile->studylevel) && $DB->get_manager()->table_exists(new \xmldb_table('local_ulms_levels'))) {
            $levelrow = $DB->get_record('local_ulms_levels', ['code' => trim((string)$profile->studylevel), 'status' => 'active'], 'id', IGNORE_MISSING);
            if ($levelrow) { $levelid = (int)$levelrow->id; }
        }
        $now = time();
        $levelwhere = '';
        $levelparams = [];
        if ($levelid > 0) {
            $levelwhere = ' AND (e.levelid = :lvlmatch0 OR e.levelid = :lvlmatch1 OR e.levelid IS NULL)';
            $levelparams['lvlmatch0'] = $levelid;
            $levelparams['lvlmatch1'] = 0;
        }
        $rows = $DB->get_records_sql(
            "SELECT e.*,
                    CASE WHEN s.id IS NOT NULL THEN s.status ELSE '' END AS submissionstatus,
                    s.id AS submissionid,
                    s.score_percent AS scorepct
               FROM {local_ulms_exams} e
          LEFT JOIN {local_ulms_exam_submissions} s ON s.examid = e.id AND s.examinee_userid = :uid
              WHERE e.programmeid = :pid
                AND e.status <> :draftstat
                AND (e.start_ts <= :window1)
                {$levelwhere}
           ORDER BY e.start_ts DESC",
            [
                'uid' => $studentuserid,
                'pid' => (int)$profile->programmeid,
                'draftstat' => self::STATUS_DRAFT,
                'window1' => $now + 7 * 86400,
            ] + $levelparams
        );
        $enrolledcourseids = [];
        try {
            $enrolledcourses = enrol_get_all_users_courses($studentuserid, false, ['id'], 'id ASC');
            foreach ($enrolledcourses as $ec) {
                $enrolledcourseids[(int)$ec->id] = true;
            }
        } catch (\Throwable $e) {
            if (function_exists('local_ulms_dashboard_log_operational_error')) {
                local_ulms_dashboard_log_operational_error($e, 'exam_service::list_student_exams::enrol', ['uid' => $studentuserid]);
            }
        }
        $finalrows = [];
        foreach ($rows as $r) {
            $cid = (int)$r->courseid;
            /** @var mixed $ctx */ $ctx = $cid > 0 ? \context_course::instance($cid, IGNORE_MISSING) : null;
            $enrolled = false;
            if (isset($enrolledcourseids[$cid])) {
                $enrolled = true;
            } elseif ($ctx && is_enrolled($ctx, $studentuserid, '', true)) {
                $enrolled = true;
            }
            $r->is_course_enrolled = $enrolled ? 1 : 0;
            $finalrows[] = $r;
        }
        return [
            'rows' => $finalrows,
            'hasprogramme' => true,
            'programmeid' => (int)$profile->programmeid,
            'friendly' => '',
        ];
    }

    public function student_can_take_exam(int $studentuserid, int $examid): array {
        global $DB;
        $profile = $DB->get_record('local_ulms_user_profile', ['userid' => $studentuserid], '*', IGNORE_MISSING);
        if (!$profile || (int)$profile->programmeid <= 0) {
            return [
                'ok' => false,
                'code' => 'no_programme',
                'message' => get_string('noprogrammeassignederror', 'local_ulms_exam'),
            ];
        }
        $exam = $DB->get_record('local_ulms_exams', ['id' => $examid], '*', IGNORE_MISSING);
        if (!$exam) {
            return ['ok' => false, 'code' => 'not_found', 'message' => get_string('examtakenoaccess', 'local_ulms_exam')];
        }
        if ((int)$exam->programmeid !== (int)$profile->programmeid) {
            $this->audit_log(
                $studentuserid,
                'EXAM_ACCESS_FORBIDDEN',
                'blocked',
                "User {$studentuserid} denied exam {$examid} (cross-programme IDOR).",
                ['examid' => $examid, 'userprogrammeid' => (int)$profile->programmeid, 'examprogrammeid' => (int)$exam->programmeid]
            );
            return ['ok' => false, 'code' => 'programme_mismatch', 'message' => get_string('examtakenoaccess', 'local_ulms_exam')];
        }
        /** @var mixed $coursectx */ $coursectx = \context_course::instance((int)$exam->courseid, IGNORE_MISSING);
        if (!$coursectx || !is_enrolled($coursectx, $studentuserid, '', true)) {
            $this->audit_log(
                $studentuserid,
                'EXAM_ACCESS_FORBIDDEN',
                'blocked',
                "User {$studentuserid} denied exam {$examid} (not enrolled in course id {$exam->courseid}).",
                ['examid' => $examid, 'examcourseid' => (int)$exam->courseid]
            );
            return ['ok' => false, 'code' => 'course_not_enrolled', 'message' => get_string('coursenotenrollederror', 'local_ulms_exam')];
        }
        $stat = (string)$exam->status;
        if ($stat === self::STATUS_DRAFT) {
            return ['ok' => false, 'code' => 'draft', 'message' => get_string('examtakenoaccess', 'local_ulms_exam')];
        }
        if ($stat === self::STATUS_GRADED) {
            return ['ok' => false, 'code' => 'exam_graded', 'message' => get_string('examtakenoaccess', 'local_ulms_exam')];
        }
        $now = time();
        if ($now < (int)$exam->start_ts) {
            return ['ok' => false, 'code' => 'not_open_yet', 'message' => get_string('examnotopen', 'local_ulms_exam')];
        }
        $window_close = (int)$exam->end_ts + self::WINDOW_GRACE_SEC;
        if ($now > $window_close) {
            return ['ok' => false, 'code' => 'window_closed', 'message' => get_string('examtakenoaccess', 'local_ulms_exam')];
        }
        $submission = $DB->get_record(
            'local_ulms_exam_submissions',
            ['examid' => $examid, 'examinee_userid' => $studentuserid],
            '*',
            IGNORE_MISSING
        );
        if ($stat === self::STATUS_CLOSED && !$submission) {
            return ['ok' => false, 'code' => 'exam_closed', 'message' => get_string('examtakenoaccess', 'local_ulms_exam')];
        }
        if ($submission && in_array($submission->status, [self::SUBMISSION_SUBMITTED, self::SUBMISSION_LATE_REJECTED, self::SUBMISSION_GRADED], true)) {
            return ['ok' => false, 'code' => 'already_submitted', 'message' => get_string('examtakenoaccess', 'local_ulms_exam'), 'submission' => $submission];
        }
        return ['ok' => true, 'exam' => $exam, 'submission' => $submission];
    }

    public function start_or_resume_attempt(int $studentuserid, int $examid): array {
        global $DB;
        $access = $this->student_can_take_exam($studentuserid, $examid);
        if (!$access['ok']) {
            if (!empty($access['submission'])) {
                return ['started' => false, 'submission' => $access['submission'], 'reason' => $access['code']];
            }
            throw new moodle_exception('examforbidden', 'local_ulms_exam', '', null, $access['code']);
        }
        $exam = $access['exam'];
        $now = time();
        $submission = $access['submission'] ?? null;
        if ($submission) {
            $per_attempt_past = false;
            if ((int)$exam->durationsec > 0 && $now > ((int)$submission->time_started + (int)$exam->durationsec)) {
                $per_attempt_past = true;
            }
            $window_past = empty($exam->allowresume) && $submission->status === self::SUBMISSION_IN_PROGRESS && $now > ((int)$exam->end_ts + self::WINDOW_GRACE_SEC);
            if ($window_past || $per_attempt_past) {
                return $this->submit_final_attempt($studentuserid, $examid, [], true);
            }
            $per_attempt_deadline_ts = (int)$exam->durationsec > 0 ? ((int)$submission->time_started + (int)$exam->durationsec) : 0;
            return ['started' => true, 'submission' => $submission, 'is_resume' => true, 'exam' => $exam, 'per_attempt_deadline_ts' => $per_attempt_deadline_ts];
        }
        $seed = random_int(1, 2147483647);
        $submission = (object)[
            'examid' => $examid,
            'examinee_userid' => $studentuserid,
            'status' => self::SUBMISSION_IN_PROGRESS,
            'choices_shuffle_seed' => $seed,
            'time_started' => $now,
            'time_submitted' => 0,
            'score_points' => 0,
            'score_total_points' => 0,
            'score_percent' => 0,
            'late_rejected_reason' => '',
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $per_attempt_deadline_ts = (int)$exam->durationsec > 0 ? ($now + (int)$exam->durationsec) : 0;
        try {
            $submission->id = (int)$DB->insert_record('local_ulms_exam_submissions', $submission);
        } catch (\Throwable $exception) {
            if (function_exists('local_ulms_dashboard_log_operational_error')) {
                local_ulms_dashboard_log_operational_error($exception, 'exam_service::start_or_resume_attempt', []);
            }
            // Race on unique (examid, userid): refetch.
            $submission = $DB->get_record(
                'local_ulms_exam_submissions',
                ['examid' => $examid, 'examinee_userid' => $studentuserid],
                '*',
                MUST_EXIST
            );
            $per_attempt_deadline_ts = (int)$exam->durationsec > 0 ? ((int)$submission->time_started + (int)$exam->durationsec) : 0;
            return ['started' => true, 'submission' => $submission, 'is_resume' => true, 'exam' => $exam, 'per_attempt_deadline_ts' => $per_attempt_deadline_ts];
        }
        return ['started' => true, 'submission' => $submission, 'is_resume' => false, 'exam' => $exam, 'per_attempt_deadline_ts' => $per_attempt_deadline_ts];
    }

    public function autosave_answers(int $studentuserid, int $submissionid, array $answers): int {
        global $DB;
        $submission = $DB->get_record('local_ulms_exam_submissions', ['id' => $submissionid], '*', IGNORE_MISSING);
        if (!$submission || (int)$submission->examinee_userid !== $studentuserid) {
            return 0;
        }
        $exam = $DB->get_record('local_ulms_exams', ['id' => (int)$submission->examid], '*', IGNORE_MISSING);
        if (!$exam) {
            return 0;
        }
        if ($exam->status === self::STATUS_DRAFT || $exam->status === self::STATUS_GRADED) {
            return 0;
        }
        $now_a = time();
        if ($now_a > ((int)$exam->end_ts + self::WINDOW_GRACE_SEC)) {
            return 0;
        }
        /** @var mixed $coursectx_a */ $coursectx_a = \context_course::instance((int)$exam->courseid, IGNORE_MISSING);
        if (!$coursectx_a || !is_enrolled($coursectx_a, $studentuserid, '', true)) {
            return 0;
        }
        if ($submission->status !== self::SUBMISSION_IN_PROGRESS) {
            return 0;
        }
        $now = time();
        $byqid = [];
        foreach ($answers as $a) {
            $qid = max(0, (int)($a['examquestionid'] ?? 0));
            if ($qid <= 0) {
                continue;
            }
            if (!isset($byqid[$qid])) {
                $byqid[$qid] = [];
            }
            if (isset($a['selected_choiceids']) && is_array($a['selected_choiceids'])) {
                foreach ($a['selected_choiceids'] as $cidraw) {
                    $cid = max(0, (int)$cidraw);
                    if ($cid > 0 && !in_array($cid, $byqid[$qid], true)) {
                        $byqid[$qid][] = $cid;
                    }
                }
            } else {
                $cid = max(0, (int)($a['selected_choiceid'] ?? 0));
                if ($cid > 0 && !in_array($cid, $byqid[$qid], true)) {
                    $byqid[$qid][] = $cid;
                }
            }
        }
        if (empty($byqid)) {
            return 0;
        }
        $qidlist = array_keys($byqid);
        [$qinsql, $qinparams] = $DB->get_in_or_equal($qidlist, SQL_PARAMS_NAMED);
        $qinparams['submissionid'] = $submissionid;
        $existingrows = $DB->get_records_sql(
            "SELECT id, examquestionid, selected_choiceid, is_correct_denorm, score_points_denorm
               FROM {local_ulms_submission_answers}
              WHERE submissionid = :submissionid AND examquestionid $qinsql",
            $qinparams
        );
        $denormcache = [];
        foreach ($existingrows as $er) {
            $key = (int)$er->examquestionid . ':' . (int)$er->selected_choiceid;
            $denormcache[$key] = [
                (int)$er->is_correct_denorm,
                (float)$er->score_points_denorm,
            ];
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->delete_records_select(
                'local_ulms_submission_answers',
                "submissionid = :submissionid AND examquestionid $qinsql",
                $qinparams
            );
            $saved = 0;
            foreach ($byqid as $qid => $choiceids) {
                foreach ($choiceids as $cid) {
                    $key = $qid . ':' . $cid;
                    [$dcorrect, $dscore] = $denormcache[$key] ?? [0, 0.0];
                    $DB->insert_record('local_ulms_submission_answers', (object)[
                        'submissionid' => $submissionid,
                        'examquestionid' => $qid,
                        'selected_choiceid' => $cid,
                        'is_correct_denorm' => $dcorrect,
                        'score_points_denorm' => $dscore,
                        'answered_ts' => $now,
                        'timesaved' => $now,
                    ]);
                    $saved++;
                }
            }
            $DB->set_field('local_ulms_exam_submissions', 'timemodified', $now, ['id' => $submissionid]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
        return $saved;
    }

    public function submit_final_attempt(int $studentuserid, int $examid, array $answers, bool $autosubmit = false, bool $skipgradewrite = false): array {
        global $DB;
        $submission_exists_before = $DB->record_exists('local_ulms_exam_submissions', ['examid' => $examid, 'examinee_userid' => $studentuserid]);
        if (!$submission_exists_before) {
            $access = $this->student_can_take_exam($studentuserid, $examid);
            if (!$access['ok']) {
                throw new moodle_exception('examforbidden', 'local_ulms_exam', '', null, $access['code']);
            }
        }
        $existingStatus = $DB->get_field_select('local_ulms_exam_submissions', 'status', 'examid = :eid AND examinee_userid = :uid', ['eid' => $examid, 'uid' => $studentuserid]);
        if ($existingStatus === self::SUBMISSION_GRADED) {
            throw new moodle_exception('submissionalreadygraded', 'local_ulms_exam');
        }
        $now = time();
        $exam = $DB->get_record('local_ulms_exams', ['id' => $examid], '*', IGNORE_MISSING);
        if (!$exam) {
            throw new moodle_exception('examnotfound', 'local_ulms_exam');
        }
        $submission = $DB->get_record(
            'local_ulms_exam_submissions',
            ['examid' => $examid, 'examinee_userid' => $studentuserid],
            '*',
            IGNORE_MISSING
        );
        if (!$submission) {
            // No in_progress attempt: create one now (edge case auto-submit without heartbeat).
            $submission = (object)[
                'examid' => $examid,
                'examinee_userid' => $studentuserid,
                'status' => self::SUBMISSION_IN_PROGRESS,
                'choices_shuffle_seed' => random_int(1, 2147483647),
                'time_started' => $now,
                'time_submitted' => 0,
                'score_points' => 0,
                'score_total_points' => 0,
                'score_percent' => 0,
                'late_rejected_reason' => '',
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            try {
                $submission->id = (int)$DB->insert_record('local_ulms_exam_submissions', $submission);
            } catch (\Throwable $exception) {
                if (function_exists('local_ulms_dashboard_log_operational_error')) {
                    local_ulms_dashboard_log_operational_error($exception, 'exam_service::submit_final_attempt', []);
                }
                $submission = $DB->get_record(
                    'local_ulms_exam_submissions',
                    ['examid' => $examid, 'examinee_userid' => $studentuserid],
                    '*',
                    MUST_EXIST
                );
            }
        }

        // Flush final answers into submission_answers first (denorm computed AFTER).
        $this->autosave_answers($studentuserid, (int)$submission->id, $answers);

        // Grace window rule: now <= end_ts + 60s → ok, else late_rejected.
        $grace_end = (int)$exam->end_ts + self::WINDOW_GRACE_SEC;
        $islate = $now > $grace_end;
        $totalpoints = 0.0;
        $earned = 0.0;
        // Compute correctness snapshot NOW (denormalised immutable).
        $questions = $DB->get_records('local_ulms_exam_questions', ['examid' => $examid, 'isactive' => 1]);
        $qids = [];
        foreach ($questions as $q) {
            $totalpoints += (float)$q->points;
            $qids[] = (int)$q->id;
        }
        $correct_ids_by_qid = [];
        if (!empty($qids)) {
            [$qinsql, $qinparams] = $DB->get_in_or_equal($qids, SQL_PARAMS_NAMED);
            $allcorrect = $DB->get_records_sql(
                "SELECT examquestionid, id FROM {local_ulms_question_choices}
                  WHERE examquestionid $qinsql AND iscorrect = 1
                  ORDER BY examquestionid ASC, id ASC",
                $qinparams
            );
            foreach ($allcorrect as $cc) {
                $cqid = (int)$cc->examquestionid;
                if (!isset($correct_ids_by_qid[$cqid])) {
                    $correct_ids_by_qid[$cqid] = [];
                }
                $correct_ids_by_qid[$cqid][] = (int)$cc->id;
            }
            foreach ($correct_ids_by_qid as $cqid => $_ids) {
                sort($correct_ids_by_qid[$cqid], SORT_NUMERIC);
            }
        }
        $storedanswers = $DB->get_records('local_ulms_submission_answers', ['submissionid' => (int)$submission->id]);
        $student_ids_by_qid = [];
        foreach ($storedanswers as $sa) {
            $sqid = (int)$sa->examquestionid;
            if (!isset($student_ids_by_qid[$sqid])) {
                $student_ids_by_qid[$sqid] = [];
            }
            $scid = (int)$sa->selected_choiceid;
            if ($scid > 0 && !in_array($scid, $student_ids_by_qid[$sqid], true)) {
                $student_ids_by_qid[$sqid][] = $scid;
            }
        }
        foreach ($student_ids_by_qid as $sqid => $_ids) {
            sort($student_ids_by_qid[$sqid], SORT_NUMERIC);
        }
        $ok_by_qid = [];
        $score_by_qid = [];
        foreach ($questions as $q) {
            $qid = (int)$q->id;
            $points = (float)$q->points;
            $qtype = property_exists($q, 'questiontype') && in_array((string)$q->questiontype, ['single', 'multi'], true)
                ? (string)$q->questiontype : 'single';
            $expected_set = $correct_ids_by_qid[$qid] ?? [];
            $student_set = $student_ids_by_qid[$qid] ?? [];
            $ok = 0;
            if ($qtype === 'single') {
                if (count($expected_set) === 1 && count($student_set) === 1 && $expected_set[0] === $student_set[0]) {
                    $ok = 1;
                }
            } else {
                if ($expected_set === $student_set && !empty($expected_set)) {
                    $ok = 1;
                }
            }
            $score = $ok === 1 ? $points : 0.0;
            $ok_by_qid[$qid] = $ok;
            $score_by_qid[$qid] = $score;
            if (!$islate) {
                $earned += $score;
            }
        }
        foreach ($storedanswers as $sa) {
            $qid = (int)$sa->examquestionid;
            $update = (object)[
                'id' => (int)$sa->id,
                'is_correct_denorm' => $ok_by_qid[$qid] ?? 0,
                'score_points_denorm' => (float)($score_by_qid[$qid] ?? 0.0),
                'timesaved' => $now,
            ];
            $DB->update_record('local_ulms_submission_answers', $update);
        }

        // Update submission.
        if ($islate) {
            $submission->status = self::SUBMISSION_LATE_REJECTED;
            $submission->score_points = 0.0;
            $submission->score_percent = 0.0;
            $submission->late_rejected_reason = 'received ' . ($now - (int)$exam->end_ts) . 's past close (>60s grace)';
        } else {
            $submission->status = self::SUBMISSION_SUBMITTED;
            $submission->score_points = $earned;
            $submission->score_percent = $totalpoints > 0 ? round(($earned / $totalpoints) * 100, 2) : 0.0;
        }
        $submission->score_total_points = $totalpoints;
        $submission->time_submitted = $now;
        $submission->timemodified = $now;
        $DB->update_record('local_ulms_exam_submissions', $submission);

        if (!$skipgradewrite) {
            try {
                $src = $autosubmit ? 'cron' : 'submit';
                $this->write_grade_to_gradebook($exam, $submission, $src);
            } catch (\Throwable $ge) {
                if (function_exists('local_ulms_dashboard_log_operational_error')) {
                    local_ulms_dashboard_log_operational_error($ge, 'exam_service::submit_final_attempt::grade', [
                        'examid' => $examid,
                        'submissionid' => (int)$submission->id,
                        'uid' => $studentuserid,
                        'autosubmit' => $autosubmit ? 1 : 0,
                    ]);
                }
            }
        }

        $this->audit_log(
            $studentuserid,
            $islate ? 'EXAM_SUBMITTED_LATE' : 'EXAM_SUBMITTED_OK',
            $islate ? 'rejected' : 'success',
            $islate
                ? "User {$studentuserid} submitted exam {$examid} LATE after window. Score forced 0. {$submission->late_rejected_reason}"
                : "User {$studentuserid} submitted exam {$examid}. Score {$submission->score_points}/{$submission->score_total_points} ({$submission->score_percent}%).",
            [
                'examid' => $examid,
                'submissionid' => (int)$submission->id,
                'status' => $submission->status,
                'score_points' => $submission->score_points,
                'score_total_points' => $submission->score_total_points,
                'score_percent' => $submission->score_percent,
                'autosubmit' => $autosubmit ? 1 : 0,
                'received_sec_after_close' => $now - (int)$exam->end_ts,
            ]
        );
        return [
            'submitted' => true,
            'late' => $islate,
            'submission' => $submission,
            'score_points' => $submission->score_points,
            'score_total' => $submission->score_total_points,
            'score_percent' => $submission->score_percent,
            'message' => $islate ? get_string('submitlate', 'local_ulms_exam') : get_string('submissionsuccess', 'local_ulms_exam', $submission->score_percent),
        ];
    }

    public function get_result(int $studentuserid, int $submissionid): ?array {
        global $DB;
        $submission = $DB->get_record('local_ulms_exam_submissions', ['id' => $submissionid], '*', IGNORE_MISSING);
        if (!$submission || (int)$submission->examinee_userid !== $studentuserid) {
            return null;
        }
        $exam = $DB->get_record('local_ulms_exams', ['id' => (int)$submission->examid], '*', MUST_EXIST);
        $answers = $DB->get_records('local_ulms_submission_answers', ['submissionid' => $submissionid]);
        $answers_by_qid = [];
        $selected_ids_by_qid = [];
        foreach ($answers as $a) {
            $aqid = (int)$a->examquestionid;
            if (!isset($answers_by_qid[$aqid])) {
                $answers_by_qid[$aqid] = [];
                $selected_ids_by_qid[$aqid] = [];
            }
            $answers_by_qid[$aqid][] = $a;
            $scid = (int)$a->selected_choiceid;
            if ($scid > 0 && !in_array($scid, $selected_ids_by_qid[$aqid], true)) {
                $selected_ids_by_qid[$aqid][] = $scid;
            }
        }
        foreach ($selected_ids_by_qid as $sqid => $_ids) {
            sort($selected_ids_by_qid[$sqid], SORT_NUMERIC);
        }
        $questions = $DB->get_records('local_ulms_exam_questions', ['examid' => (int)$submission->examid], 'ordernum ASC');
        $qids = array_keys($questions);
        $released = $exam->status === self::STATUS_GRADED;
        $correct_ids_by_qid = [];
        if ($released) {
            if (!empty($qids)) {
                [$qinsql, $qinparams] = $DB->get_in_or_equal($qids, SQL_PARAMS_NAMED);
                $allcorrect = $DB->get_records_sql(
                    "SELECT examquestionid, id FROM {local_ulms_question_choices}
                      WHERE examquestionid $qinsql AND iscorrect = 1
                      ORDER BY examquestionid ASC, id ASC",
                    $qinparams
                );
                foreach ($allcorrect as $cc) {
                    $cqid = (int)$cc->examquestionid;
                    if (!isset($correct_ids_by_qid[$cqid])) {
                        $correct_ids_by_qid[$cqid] = [];
                    }
                    $correct_ids_by_qid[$cqid][] = (int)$cc->id;
                }
                foreach ($correct_ids_by_qid as $cqid => $_ids) {
                    sort($correct_ids_by_qid[$cqid], SORT_NUMERIC);
                }
            }
        }
        return [
            'exam' => $exam,
            'submission' => $submission,
            'questions' => array_values($questions),
            'answers_by_qid' => $answers_by_qid,
            'selected_ids_by_qid' => $selected_ids_by_qid,
            'correct_ids_by_qid' => $correct_ids_by_qid,
            'results_released' => $released,
        ];
    }

    private function normalise_exam_data(array $data, ?stdClass $existing): array {
        $normalised = [];
        $fieldmap = [
            'programmeid' => 'int',
            'levelid' => 'int',
            'sessionid' => 'int',
            'semesterid' => 'int',
            'courseid' => 'int',
            'title' => 'string',
            'instructions' => 'string',
            'durationsec' => 'int',
            'start_ts' => 'int',
            'end_ts' => 'int',
            'passpct' => 'float',
            'maxattempts' => 'int',
            'shufflequestions' => 'bool',
            'shufflechoices' => 'bool',
            'allowresume' => 'bool',
        ];
        $defaults = [
            'programmeid' => (int)($existing->programmeid ?? 0),
            'levelid' => (int)($existing->levelid ?? 0),
            'sessionid' => (int)($existing->sessionid ?? 0),
            'semesterid' => (int)($existing->semesterid ?? 0),
            'courseid' => (int)($existing->courseid ?? 0),
            'title' => (string)($existing->title ?? ''),
            'instructions' => (string)($existing->instructions ?? ''),
            'durationsec' => (int)($existing->durationsec ?? 0),
            'start_ts' => (int)($existing->start_ts ?? 0),
            'end_ts' => (int)($existing->end_ts ?? 0),
            'passpct' => (float)($existing->passpct ?? 0.0),
            'maxattempts' => (int)($existing->maxattempts ?? 1),
            'shufflequestions' => (bool)($existing->shufflequestions ?? false),
            'shufflechoices' => (bool)($existing->shufflechoices ?? true),
            'allowresume' => (bool)($existing->allowresume ?? true),
        ];
        foreach ($fieldmap as $field => $type) {
            $value = array_key_exists($field, $data) ? $data[$field] : $defaults[$field];
            if ($type === 'int') {
                $value = max(0, (int)$value);
            } elseif ($type === 'bool') {
                $value = !empty($value) ? 1 : 0;
            } elseif ($type === 'float') {
                $value = max(0, min(100, round((float)$value, 2)));
            } else {
                $value = trim((string)$value);
            }
            $normalised[$field] = $value;
        }
        // Start and end timestamp fields: accept either Unix ints or ISO-ish strings via strtotime only if not already int-formed.
        $stringformats = ['start_ts', 'end_ts'];
        foreach ($stringformats as $f) {
            if (isset($data[$f]) && is_string($data[$f]) && !ctype_digit($data[$f])) {
                $parsed = strtotime($data[$f]);
                if ($parsed !== false) {
                    $normalised[$f] = $parsed;
                }
            }
        }
        if (trim((string)$normalised['title']) === '') {
            throw new moodle_exception('examtitlerequired', 'local_ulms_exam');
        }
        if ((int)$normalised['programmeid'] <= 0 || (int)$normalised['courseid'] <= 0) {
            throw new moodle_exception('examscoperequired', 'local_ulms_exam');
        }
        return $normalised;
    }

    public function batch_autograde_exam(int $examid, string $source = 'button'): array {
        global $DB, $USER;
        $this->require_manage_exam($examid);
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $exam = $DB->get_record('local_ulms_exams', ['id' => $examid], '*', MUST_EXIST);
            $graded = 0;
            $already = 0;
            $rejected = 0;
            $submissions = $DB->get_records(
                'local_ulms_exam_submissions',
                ['examid' => $examid],
                'id ASC'
            );
            foreach ($submissions as $sub) {
                $status = (string)$sub->status;
                if ($status === self::SUBMISSION_GRADED) {
                    $already++;
                    continue;
                }
                $uid = (int)$sub->examinee_userid;
                if ($status === self::SUBMISSION_IN_PROGRESS) {
                    $rawanswers = $DB->get_records(
                        'local_ulms_submission_answers',
                        ['submissionid' => (int)$sub->id],
                        'id ASC'
                    );
                    $grouped = [];
                    foreach ($rawanswers as $ra) {
                        $qid = (int)$ra->examquestionid;
                        if (!isset($grouped[$qid])) {
                            $grouped[$qid] = ['examquestionid' => $qid, 'selected_choiceids' => []];
                        }
                        $cid = (int)$ra->selected_choiceid;
                        if ($cid > 0 && !in_array($cid, $grouped[$qid]['selected_choiceids'], true)) {
                            $grouped[$qid]['selected_choiceids'][] = $cid;
                        }
                    }
                    $payload = [];
                    foreach ($grouped as $g) {
                        if (count($g['selected_choiceids']) === 1) {
                            $payload[] = ['examquestionid' => $g['examquestionid'], 'selected_choiceid' => $g['selected_choiceids'][0]];
                        } else {
                            $payload[] = $g;
                        }
                    }
                    $res = $this->submit_final_attempt($uid, $examid, $payload, true, true);
                    $sub = $DB->get_record(
                        'local_ulms_exam_submissions',
                        ['examid' => $examid, 'examinee_userid' => $uid],
                        '*',
                        IGNORE_MISSING
                    );
                    if ($sub) {
                        $newstatus = (string)$sub->status;
                        if ($newstatus === self::SUBMISSION_LATE_REJECTED) {
                            $rejected++;
                        } else {
                            $sub->status = self::SUBMISSION_GRADED;
                            $graded++;
                        }
                        $sub->timemodified = $now;
                        $DB->update_record('local_ulms_exam_submissions', $sub);
                        try {
                            $this->write_grade_to_gradebook($exam, $sub, $source);
                        } catch (\Throwable $ge) {
                            if (function_exists('local_ulms_dashboard_log_operational_error')) {
                                local_ulms_dashboard_log_operational_error($ge, 'exam_service::batch_autograde::grade', [
                                    'examid' => $examid,
                                    'submissionid' => (int)$sub->id,
                                    'uid' => $uid,
                                ]);
                            }
                        }
                    } else {
                        if (!empty($res['late'])) {
                            $rejected++;
                        } else {
                            $graded++;
                        }
                    }
                } elseif ($status === self::SUBMISSION_SUBMITTED || $status === self::SUBMISSION_LATE_REJECTED) {
                    try {
                        $this->write_grade_to_gradebook($exam, $sub, $source);
                    } catch (\Throwable $ge) {
                        if (function_exists('local_ulms_dashboard_log_operational_error')) {
                            local_ulms_dashboard_log_operational_error($ge, 'exam_service::batch_autograde::syncgrade', [
                                'examid' => $examid,
                                'submissionid' => (int)$sub->id,
                            ]);
                        }
                    }
                    if ($status !== self::SUBMISSION_LATE_REJECTED) {
                        $sub->status = self::SUBMISSION_GRADED;
                    }
                    $sub->timemodified = $now;
                    $DB->update_record('local_ulms_exam_submissions', $sub);
                    if ($status === self::SUBMISSION_LATE_REJECTED) {
                        $rejected++;
                    } else {
                        $graded++;
                    }
                } else {
                    $already++;
                }
            }
            $valid_exam_from = [self::STATUS_PUBLISHED, self::STATUS_CLOSED];
            if (!in_array($exam->status, $valid_exam_from, true)) {
                throw new moodle_exception('invalidstatustransition', 'local_ulms_exam', '', null, "grade exam from {$exam->status}");
            }
            $exam->status = self::STATUS_GRADED;
            $exam->timegraded = $now;
            $exam->timemodified = $now;
            $DB->update_record('local_ulms_exams', $exam);
            $this->audit_log(
                (int)($USER->id ?? 0),
                'BATCH_GRADED_COMPLETED',
                'success',
                "Exam {$examid} batch grade via {$source} complete. graded={$graded}, already={$already}, rejected={$rejected}.",
                ['examid' => $examid, 'graded' => $graded, 'already' => $already, 'rejected' => $rejected, 'source' => $source]
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
        return ['graded' => $graded, 'already' => $already, 'rejected' => $rejected, 'examid' => $examid];
    }

    private function write_grade_to_gradebook(stdClass $exam, stdClass $submission, string $source): void {
        global $DB, $USER, $CFG;
        $actorid = !empty($USER->id) ? (int)$USER->id : (int)(explode(',', (string)($CFG->siteadmins ?? ''))[0] ?? 0);
        $courseid = (int)$exam->courseid;
        if ($courseid <= 0) {
            return;
        }
        $now = time();
        $examid = (int)$exam->id;
        $totalpts = (float)($submission->score_total_points > 0 ? $submission->score_total_points : 0.0);
        if ($totalpts <= 0) {
            $totalpts = (float)$DB->get_field_select(
                'local_ulms_exam_questions',
                'COALESCE(SUM(points), 0)',
                'examid = :eid AND isactive = 1',
                ['eid' => $examid]
            );
        }
        $gradeitem = $DB->get_record_select(
            'grade_items',
            "courseid = :cid AND itemmodule = 'ulms_exam' AND iteminstance = :eid AND itemtype = 'manual'",
            ['cid' => $courseid, 'eid' => $examid],
            '*',
            IGNORE_MISSING
        );
        if (!$gradeitem) {
            $categoryid = 0;
            try {
                if (class_exists('grade_category')) {
                    $gc = \grade_category::fetch_course_category($courseid);
                    if ($gc && !empty($gc->id)) {
                        $categoryid = (int)$gc->id;
                    }
                }
            } catch (\Throwable) {
                $categoryid = 0;
            }
            $maxsort = (int)$DB->get_field_select(
                'grade_items',
                'COALESCE(MAX(sortorder), 0)',
                'courseid = :cid',
                ['cid' => $courseid]
            );
            $prefix = get_string('examgradeitemnameprefix', 'local_ulms_exam', $exam->title);
            $passpct = max(0.0, min(100.0, (float)($exam->passpct ?? 0.0)));
            $gradepass = round(($passpct / 100.0) * $totalpts, 2);
            $gi = (object)[
                'courseid' => $courseid,
                'categoryid' => $categoryid,
                'itemname' => $prefix,
                'itemtype' => 'manual',
                'itemmodule' => 'ulms_exam',
                'iteminstance' => $examid,
                'itemnumber' => 0,
                'idnumber' => '',
                'calculation' => '',
                'gradetype' => 1,
                'grademax' => $totalpts,
                'grademin' => 0.0,
                'scaleid' => 0,
                'outcomeid' => 0,
                'gradepass' => $gradepass,
                'multfactor' => 1.0,
                'plusfactor' => 0.0,
                'aggregationcoef' => 0.0,
                'aggregationcoef2' => 0.0,
                'sortorder' => $maxsort + 1,
                'display' => 0,
                'decimals' => null,
                'hidden' => 0,
                'locked' => 0,
                'locktime' => 0,
                'needsupdate' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => $actorid,
            ];
            try {
                $gi->id = (int)$DB->insert_record('grade_items', $gi);
                $gradeitem = $gi;
                $exam->gradeitemid = $gi->id;
                $DB->set_field('local_ulms_exams', 'gradeitemid', (int)$gi->id, ['id' => $examid]);
            } catch (\Throwable) {
                $existing = $DB->get_record_select(
                    'grade_items',
                    "courseid = :cid AND itemmodule = 'ulms_exam' AND iteminstance = :eid AND itemtype = 'manual'",
                    ['cid' => $courseid, 'eid' => $examid],
                    '*',
                    MUST_EXIST
                );
                $gradeitem = $existing;
                $exam->gradeitemid = (int)$existing->id;
                $DB->set_field('local_ulms_exams', 'gradeitemid', (int)$existing->id, ['id' => $examid]);
            }
        } else {
            $dirty = false;
            if ((float)$gradeitem->grademax !== (float)$totalpts && $totalpts > 0) {
                $gradeitem->grademax = $totalpts;
                $dirty = true;
            }
            $prefix = get_string('examgradeitemnameprefix', 'local_ulms_exam', $exam->title);
            if ((string)$gradeitem->itemname !== (string)$prefix) {
                $gradeitem->itemname = $prefix;
                $dirty = true;
            }
            if ($dirty) {
                $gradeitem->timemodified = $now;
                $gradeitem->usermodified = $actorid;
                $DB->update_record('grade_items', $gradeitem);
            }
            if ((int)$exam->gradeitemid !== (int)$gradeitem->id) {
                $exam->gradeitemid = (int)$gradeitem->id;
                $DB->set_field('local_ulms_exams', 'gradeitemid', (int)$gradeitem->id, ['id' => $examid]);
            }
        }
        $itemid = (int)$gradeitem->id;
        $userid = (int)$submission->examinee_userid;
        $finalgrade = (float)$submission->score_points;
        $statuslabel = $submission->status;
        switch ($source) {
            case 'cron':
                $gts = userdate($now);
                $gviastring = get_string('gradedviacron', 'local_ulms_exam', $gts);
                break;
            case 'button':
                $gts = userdate($now);
                $gviastring = get_string('gradedviabutton', 'local_ulms_exam', $gts);
                break;
            default:
                $gts = userdate($now);
                $gviastring = get_string('gradedviastudentsubmit', 'local_ulms_exam', $gts);
                break;
        }
        $scoreline = "Score {$finalgrade}/{$totalpts} ({$submission->score_percent}%)";
        if ($statuslabel === self::SUBMISSION_LATE_REJECTED) {
            $scoreline .= " — SUBMISSION LATE REJECTED (0)";
        }
        $feedback = "ULMS Exam result. {$gviastring}. {$scoreline}.";
        $existinggg = $DB->get_record_select(
            'grade_grades',
            'itemid = :iid AND userid = :uid',
            ['iid' => $itemid, 'uid' => $userid],
            '*',
            IGNORE_MISSING
        );
        $ggpayload = (object)[
            'itemid' => $itemid,
            'userid' => $userid,
            'rawgrade' => $finalgrade,
            'rawgrademax' => (float)$gradeitem->grademax,
            'rawgrademin' => (float)$gradeitem->grademin,
            'rawscaleid' => 0,
            'usermodified' => $actorid,
            'finalgrade' => $finalgrade,
            'hidden' => 0,
            'locked' => 0,
            'locktime' => 0,
            'exported' => 0,
            'overridden' => 0,
            'excluded' => 0,
            'feedback' => $feedback,
            'feedbackformat' => 1,
            'information' => '',
            'informationformat' => 1,
            'timemodified' => $now,
        ];
        if ($existinggg) {
            $ggpayload->id = (int)$existinggg->id;
            if ((int)$existinggg->timecreated <= 0) {
                $ggpayload->timecreated = $now;
            } else {
                $ggpayload->timecreated = (int)$existinggg->timecreated;
            }
            $DB->update_record('grade_grades', $ggpayload);
        } else {
            $ggpayload->timecreated = $now;
            try {
                $DB->insert_record('grade_grades', $ggpayload);
            } catch (\Throwable) {
                $existinggg2 = $DB->get_record_select(
                    'grade_grades',
                    'itemid = :iid AND userid = :uid',
                    ['iid' => $itemid, 'uid' => $userid],
                    '*',
                    MUST_EXIST
                );
                $ggpayload->id = (int)$existinggg2->id;
                $ggpayload->timecreated = (int)$existinggg2->timecreated > 0 ? (int)$existinggg2->timecreated : $now;
                $DB->update_record('grade_grades', $ggpayload);
            }
        }
    }

    private function validate_exam_window(array $data): void {
        $start = (int)($data['start_ts'] ?? 0);
        $end = (int)($data['end_ts'] ?? 0);
        if ($end <= $start) {
            throw new moodle_exception('publishtimeerror', 'local_ulms_exam');
        }
    }

    private function audit_log(int $targetuserid, string $action, string $status, string $message, array $details = []): void {
        global $DB, $USER;
        if (!$DB->get_manager()->table_exists(new \xmldb_table(self::EXAM_LOG_TABLE))) {
            return;
        }
        $scrubbed = [];
        foreach ($details as $k => $v) {
            if (is_array($v) || is_object($v)) {
                $v = json_encode($v);
            }
            $scrubbed[$k] = is_string($v) && strlen($v) > 1024 ? substr($v, 0, 1024) . '…' : $v;
        }
        $DB->insert_record(self::EXAM_LOG_TABLE, (object)[
            'actorid' => (int)($USER->id ?? 0),
            'targetuserid' => $targetuserid,
            'action' => substr($action, 0, 64),
            'status' => substr($status, 0, 32),
            'message' => $message,
            'detailsjson' => json_encode($scrubbed),
            'ipaddress' => substr((string)getremoteaddr(null), 0, 64),
            'timecreated' => time(),
        ]);
    }
}
