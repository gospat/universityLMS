<?php

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');

global $DB;

$rawopts = getopt('', ['apply', 'help', 'verbose', 'force-enrol', 'force-mappings']);
$help = isset($rawopts['help']);
$apply = isset($rawopts['apply']);
$dryrun = !$apply;
$verbose = isset($rawopts['verbose']);
$forceenrol = isset($rawopts['force-enrol']);
$forcemappings = isset($rawopts['force-mappings']);

if ($help) {
    cli_writeln('ULMS Seed Demo Academic Chain CLI');
    cli_writeln('');
    cli_writeln('Idempotently seeds the ULMS demo academic hierarchy and links users and');
    cli_writeln('courses so that scope resolvers render populated dropdowns.');
    cli_writeln('');
    cli_writeln('Seeded entities:');
    cli_writeln('  * College: CST - College of Science and Technology');
    cli_writeln('  * Departments: DCS (Computer Science), DMS (Mathematical Sciences)');
    cli_writeln('  * Programmes: BSCCS (BSc Computer Science), BSMAT (BSc Mathematics)');
    cli_writeln('  * Academic Session: 2026/27');
    cli_writeln('  * Semesters: FIRST Semester, SECOND Semester (2026/27)');
    cli_writeln('  * Moodle Courses: ULMS-CS101, ULMS-CS201, ULMS-MA101, ULMS-MA201');
    cli_writeln('  * Enrolments: lecturer (65) editingteacher + student (66) student on CS101/CS201');
    cli_writeln('  * 4 programme_courses mappings via save_course_mapping validators');
    cli_writeln('');
    cli_writeln('Usage:');
    cli_writeln('  php seed_demo_academic_chain.php [--apply] [--verbose]');
    cli_writeln('');
    cli_writeln('Options:');
    cli_writeln('  --apply          Write records. Defaults to dry-run report only.');
    cli_writeln('  --verbose        Print per-record outcomes.');
    cli_writeln('  --force-enrol    Re-apply enrolments even if users are already enrolled.');
    cli_writeln('  --force-mappings Re-evaluate 4-col unique mappings even if rows already exist.');
    cli_writeln('  --help           Show this help.');
    exit(0);
}

/** @var local_ulms_academics\local\service\academic_structure_service $structservice */
$structservice = new \local_ulms_academics\local\service\academic_structure_service();

$lecturerid = 65;
$studentid = 66;
$categoryname = 'ULMS Demo Courses (2026/27)';

$state = [
    'faculty_created' => 0,
    'faculty_found' => 0,
    'department_created' => 0,
    'programme_created' => 0,
    'session_created' => 0,
    'semester_created' => 0,
    'course_created' => 0,
    'mapping_created' => 0,
    'mapping_skipped_exists' => 0,
    'mapping_error' => 0,
    'enrolments_ensured' => 0,
    'enrolments_skipped' => 0,
];
$warnings = [];

function save_entity_or_idempotent(string $entity, \stdClass $payload, array $uniquefields, string $desclabel, bool $apply, bool $verbose): array {
    global $DB, $structservice, $state;
    $existing = null;
    if (!empty($uniquefields)) {
        $where = [];
        $params = [];
        $conditionarray = [];
        foreach ($uniquefields as $f) {
            if (!property_exists($payload, $f)) { continue; }
            $where[] = $f . ' = :' . $f;
            $params[$f] = $payload->$f;
            $conditionarray[$f] = $payload->$f;
        }
        if (!empty($where)) {
            if (count($where) === count($conditionarray)) {
                $existing = $DB->get_record('local_ulms_' . $entity, $conditionarray, '*', IGNORE_MISSING);
            }
            if (!$existing) {
                $existing = $DB->get_record_sql(
                    'SELECT * FROM {local_ulms_' . $entity . '} WHERE ' . implode(' AND ', $where),
                    $params,
                    IGNORE_MISSING
                );
            }
        }
    }
    if ($existing) {
        $tag = strtoupper($entity) . '_FOUND';
        $statkey = strtolower($entity) . '_found';
        if (isset($state[$statkey])) { $state[$statkey]++; }
        if ($verbose) {
            cli_writeln(sprintf('[%s] %s id=%d', $tag, $desclabel, (int)$existing->id));
        }
        return ['success' => true, 'id' => (int)$existing->id, 'created' => false];
    }
    if (!$apply) {
        $tag = strtoupper($entity) . '_CREATE';
        if ($verbose) {
            $pretty = [];
            foreach ((array)$payload as $k => $v) { $pretty[] = sprintf('%s=%s', $k, var_export($v, true)); }
            cli_writeln(sprintf('[DRY-RUN %s] %s {%s}', $tag, $desclabel, implode(', ', $pretty)));
        }
        return ['success' => true, 'id' => 0, 'created' => true];
    }
    $result = $structservice->save_entity_record($entity, $payload);
    if (empty($result['success'])) {
        $msg = $result['message'] ?? 'unknown error';
        cli_writeln(sprintf('[ERROR %-10s] %s | %s', strtoupper($entity) . '_FAIL', $desclabel, $msg));
        return ['success' => false, 'id' => 0, 'created' => false];
    }
    $statkey = strtolower($entity) . '_created';
    if (isset($state[$statkey])) { $state[$statkey]++; }
    if ($verbose) {
        cli_writeln(sprintf('[%-13s] %s id=%d', strtoupper($entity) . '_CREATED', $desclabel, (int)$result['id']));
    }
    return ['success' => true, 'id' => (int)$result['id'], 'created' => true];
}

$now = time();

$facultyrec = (object)[
    'code' => 'CST',
    'name' => 'College of Science and Technology',
    'description' => 'Seed demo college covering CS and Mathematics programmes for 2026/27 academic chain.',
    'status' => 'active',
    'sortorder' => 1,
    'timecreated' => $now,
    'timemodified' => $now,
];
$facultyresult = save_entity_or_idempotent('faculties', $facultyrec, ['code'], 'College CST', $apply, $verbose);
if (!$facultyresult['success']) { exit(2); }
$facultyid = (int)$facultyresult['id'];

$dcsrec = (object)[
    'code' => 'DCS',
    'facultyid' => $facultyid,
    'name' => 'Department of Computer Science',
    'description' => 'Computer Science undergraduate and postgraduate teaching.',
    'status' => 'active',
    'sortorder' => 1,
    'timecreated' => $now,
    'timemodified' => $now,
];
$dcsresult = save_entity_or_idempotent('departments', $dcsrec, ['code'], 'Department DCS', $apply, $verbose);
if (!$dcsresult['success']) { exit(2); }
$dcsid = (int)$dcsresult['id'];

$dmsrec = (object)[
    'code' => 'DMS',
    'facultyid' => $facultyid,
    'name' => 'Department of Mathematical Sciences',
    'description' => 'Mathematics, statistics, and applied mathematics.',
    'status' => 'active',
    'sortorder' => 2,
    'timecreated' => $now,
    'timemodified' => $now,
];
$dmsresult = save_entity_or_idempotent('departments', $dmsrec, ['code'], 'Department DMS', $apply, $verbose);
if (!$dmsresult['success']) { exit(2); }
$dmsid = (int)$dmsresult['id'];

$bsccsrec = (object)[
    'code' => 'BSCCS',
    'departmentid' => $dcsid,
    'facultyid' => $facultyid,
    'name' => 'BSc Computer Science',
    'description' => 'Four-year Computer Science undergraduate programme.',
    'durationyears' => 4,
    'status' => 'active',
    'sortorder' => 1,
    'timecreated' => $now,
    'timemodified' => $now,
];
$bsccsresult = save_entity_or_idempotent('programmes', $bsccsrec, ['code'], 'Programme BSCCS', $apply, $verbose);
if (!$bsccsresult['success']) { exit(2); }
$bsccsid = (int)$bsccsresult['id'];

$bsmatrec = (object)[
    'code' => 'BSMAT',
    'departmentid' => $dmsid,
    'facultyid' => $facultyid,
    'name' => 'BSc Mathematics',
    'description' => 'Four-year Mathematics undergraduate programme.',
    'durationyears' => 4,
    'status' => 'active',
    'sortorder' => 2,
    'timecreated' => $now,
    'timemodified' => $now,
];
$bsmatresult = save_entity_or_idempotent('programmes', $bsmatrec, ['code'], 'Programme BSMAT', $apply, $verbose);
if (!$bsmatresult['success']) { exit(2); }
$bsmatid = (int)$bsmatresult['id'];

$sessrec = (object)[
    'code' => '2026/27',
    'name' => '2026/27 Academic Session',
    'startdate' => strtotime('2026-09-01 00:00:00'),
    'enddate' => strtotime('2027-08-31 23:59:59'),
    'status' => 'active',
    'iscurrent' => 1,
    'sortorder' => 1,
    'timecreated' => $now,
    'timemodified' => $now,
];
$sessresult = save_entity_or_idempotent('sessions', $sessrec, ['code'], 'Session 2026/27', $apply, $verbose);
if (!$sessresult['success']) { exit(2); }
$sessionid = (int)$sessresult['id'];

$sem1rec = (object)[
    'sessionid' => $sessionid,
    'code' => 'FIRST',
    'name' => 'First Semester 2026/27',
    'status' => 'active',
    'sortorder' => 1,
    'timecreated' => $now,
    'timemodified' => $now,
];
$sem1result = save_entity_or_idempotent('semesters', $sem1rec, ['sessionid', 'code'], 'Semester FIRST', $apply, $verbose);
if (!$sem1result['success']) { exit(2); }
$sem1id = (int)$sem1result['id'];

$sem2rec = (object)[
    'sessionid' => $sessionid,
    'code' => 'SECOND',
    'name' => 'Second Semester 2026/27',
    'status' => 'active',
    'sortorder' => 2,
    'timecreated' => $now,
    'timemodified' => $now,
];
$sem2result = save_entity_or_idempotent('semesters', $sem2rec, ['sessionid', 'code'], 'Semester SECOND', $apply, $verbose);
if (!$sem2result['success']) { exit(2); }
$sem2id = (int)$sem2result['id'];

$levelsneeded = [
    ['code' => '100', 'name' => 'Level 100 (Year 1)'],
    ['code' => '200', 'name' => 'Level 200 (Year 2)'],
    ['code' => '300', 'name' => 'Level 300 (Year 3)'],
    ['code' => '400', 'name' => 'Level 400 (Year 4)'],
];
$levelmap = [];
foreach ($levelsneeded as $lvl) {
    $existinglvl = $DB->get_record('local_ulms_levels', ['code' => $lvl['code']], '*', IGNORE_MISSING);
    if ($existinglvl) {
        $levelmap[$lvl['code']] = (int)$existinglvl->id;
        if ($verbose) {
            cli_writeln(sprintf('[LEVEL_FOUND   ] %s id=%d', $lvl['code'], (int)$existinglvl->id));
        }
        continue;
    }
    $maxsort = (int)$DB->get_field_sql('SELECT COALESCE(MAX(sortorder),0) FROM {local_ulms_levels}');
    $lvlrec = (object)[
        'code' => $lvl['code'],
        'name' => $lvl['name'],
        'description' => 'Seed demo study level for 4-year undergraduate programmes.',
        'status' => 'active',
        'sortorder' => $maxsort + 1,
        'timecreated' => $now,
        'timemodified' => $now,
    ];
    if (!$apply) {
        if ($verbose) {
            cli_writeln(sprintf('[DRY-RUN LEVEL_CREATE] %s (%s)', $lvl['code'], $lvl['name']));
        }
        $levelmap[$lvl['code']] = 0;
        continue;
    }
    $levelres = save_entity_or_idempotent('levels', $lvlrec, ['code'], $lvl['name'] . ' (' . $lvl['code'] . ')', $apply, $verbose);
    if (empty($levelres['success'])) {
        cli_writeln(sprintf('[ERROR LEVEL_FAIL] %s | %s', $lvl['code'], 'save_entity_or_idempotent returned non-success'));
        exit(2);
    }
    $lid = (int)$levelres['id'];
    $levelmap[$lvl['code']] = $lid;
    if ($verbose && !empty($levelres['created'])) {
        cli_writeln(sprintf('[LEVEL_CREATED ] %s id=%d', $lvl['code'], $lid));
    }
}

$coursedefs = [
    'ULMS-CS101' => ['fullname' => 'ULMS-CS101 Introduction to Computer Science', 'shortname' => 'ULMS-CS101',
        'categorypath' => $categoryname, 'summary' => 'Seed CS first semester year 1 course.', 'startdate' => strtotime('2026-09-15')],
    'ULMS-CS201' => ['fullname' => 'ULMS-CS201 Data Structures and Algorithms', 'shortname' => 'ULMS-CS201',
        'categorypath' => $categoryname, 'summary' => 'Seed CS first semester year 2 course.', 'startdate' => strtotime('2026-09-15')],
    'ULMS-MA101' => ['fullname' => 'ULMS-MA101 Calculus and Linear Algebra', 'shortname' => 'ULMS-MA101',
        'categorypath' => $categoryname, 'summary' => 'Seed Maths first semester year 1 course.', 'startdate' => strtotime('2026-09-15')],
    'ULMS-MA201' => ['fullname' => 'ULMS-MA201 Real Analysis', 'shortname' => 'ULMS-MA201',
        'categorypath' => $categoryname, 'summary' => 'Seed Maths first semester year 2 course.', 'startdate' => strtotime('2026-09-15')],
];

function find_or_create_category(string $catname, bool $apply): int {
    global $DB;
    $cat = $DB->get_record('course_categories', ['name' => $catname], '*', IGNORE_MISSING);
    if ($cat) { return (int)$cat->id; }
    if (!$apply) { return 0; }
    $newcat = new stdClass();
    $newcat->name = $catname;
    $newcat->description = 'ULMS demo academic chain 2026/27';
    $newcat->descriptionformat = FORMAT_HTML;
    $newcat->visible = 1;
    $newcat->idnumber = 'ULMS-DEMO-2026/27';
    $newcat->parent = 0;
    $newcat->sortorder = 9999;
    $newcat->coursecount = 0;
    $newcat->visibleold = 1;
    $newcat->timemodified = time();
    $newcat->timecreated = time();
    $newcatid = $DB->insert_record('course_categories', $newcat);
    if (method_exists(\core_course_category::class, 'create_child')) {
        /* no-op, not used */
    }
    fix_course_sortorder();
    return (int)$newcatid;
}

$catid = find_or_create_category($categoryname, $apply);

$courseids = [];
foreach ($coursedefs as $key => $cd) {
    $existing = $DB->get_record('course', ['shortname' => $cd['shortname']], '*', IGNORE_MISSING);
    if ($existing) {
        $courseids[$key] = (int)$existing->id;
        if ($verbose) {
            cli_writeln(sprintf('[COURSE_FOUND  ] %s id=%d', $cd['shortname'], (int)$existing->id));
        }
        continue;
    }
    if (!$apply) {
        if ($verbose) {
            cli_writeln(sprintf('[DRY-RUN COURSE_CREATE] %s (%s)', $cd['shortname'], $cd['fullname']));
        }
        $courseids[$key] = 0;
        continue;
    }
    if ($catid <= 0) { $catid = find_or_create_category($categoryname, true); }
    $newcourse = create_course((object)[
        'shortname' => $cd['shortname'],
        'fullname' => $cd['fullname'],
        'summary' => $cd['summary'],
        'summaryformat' => FORMAT_HTML,
        'category' => $catid,
        'visible' => 1,
        'startdate' => $cd['startdate'],
        'idnumber' => $cd['shortname'],
        'format' => 'topics',
        'numsections' => 5,
    ]);
    $courseids[$key] = (int)$newcourse->id;
    $state['course_created']++;
    if ($verbose) {
        cli_writeln(sprintf('[COURSE_CREATED] %s id=%d', $cd['shortname'], (int)$newcourse->id));
    }
}

$mappingdefs = [
    ['programmeid' => $bsccsid, 'moodlecourseid' => $courseids['ULMS-CS101'] ?? 0, 'semesterid' => $sem1id, 'levelid' => $levelmap['100'] ?? 0, 'coursetype' => 'core', 'iscore' => 1],
    ['programmeid' => $bsccsid, 'moodlecourseid' => $courseids['ULMS-CS201'] ?? 0, 'semesterid' => $sem1id, 'levelid' => $levelmap['200'] ?? 0, 'coursetype' => 'core', 'iscore' => 1],
    ['programmeid' => $bsmatid, 'moodlecourseid' => $courseids['ULMS-MA101'] ?? 0, 'semesterid' => $sem1id, 'levelid' => $levelmap['100'] ?? 0, 'coursetype' => 'core', 'iscore' => 1],
    ['programmeid' => $bsmatid, 'moodlecourseid' => $courseids['ULMS-MA201'] ?? 0, 'semesterid' => $sem1id, 'levelid' => $levelmap['200'] ?? 0, 'coursetype' => 'core', 'iscore' => 1],
];

function user_is_enrolled(int $courseid, int $userid): bool {
    global $DB;
    if ($courseid <= 0) { return false; }
    $sql = "SELECT 1
              FROM {enrol} e
              JOIN {user_enrolments} ue ON ue.enrolid = e.id
             WHERE e.courseid = :cid AND ue.userid = :uid";
    return (bool)$DB->record_exists_sql($sql, ['cid' => $courseid, 'uid' => $userid]);
}
function enrol_user_in(int $courseid, int $userid, string $roleshortname, bool $apply, bool $verbose): bool {
    global $DB, $CFG;
    if ($courseid <= 0 || $userid <= 0) { return false; }
    require_once($CFG->dirroot . '/enrol/manual/lib.php');
    $roleid = (int)$DB->get_field('role', 'id', ['shortname' => $roleshortname], IGNORE_MISSING);
    if ($roleid <= 0) { return false; }
    $manual = enrol_get_plugin('manual');
    $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', IGNORE_MISSING);
    if (!$instance) {
        if (!$apply) { return true; }
        $course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
        if (!$course) { return false; }
        $enrolid = $manual->add_instance($course, []);
        $instance = $DB->get_record('enrol', ['id' => $enrolid], '*', MUST_EXIST);
    }
    if (!$apply) { return true; }
    $manual->enrol_user($instance, $userid, $roleid);
    return true;
}

$enrolneeded = [];
foreach (['ULMS-CS101', 'ULMS-CS201'] as $ck) {
    $cid = $courseids[$ck] ?? 0;
    $enrolneeded[] = [$cid, $lecturerid, 'editingteacher', $ck . '-lecturer'];
    $enrolneeded[] = [$cid, $studentid, 'student', $ck . '-student'];
}
foreach ($enrolneeded as [$cid, $uid, $role, $tag]) {
    if ($cid <= 0) {
        if ($verbose) cli_writeln(sprintf('[ENROL SKIP (course not yet created)] %s uid=%d role=%s', $tag, $uid, $role));
        continue;
    }
    $already = user_is_enrolled($cid, $uid);
    if ($already && !$forceenrol) {
        $state['enrolments_skipped']++;
        if ($verbose) cli_writeln(sprintf('[ENROL EXISTS  ] %s uid=%d', $tag, $uid));
        continue;
    }
    if (!$apply) {
        if ($verbose) cli_writeln(sprintf('[DRY-RUN ENROL ] %s uid=%d courseid=%d role=%s', $tag, $uid, $cid, $role));
        $state['enrolments_ensured']++;
        continue;
    }
    $ok = enrol_user_in($cid, $uid, $role, true, $verbose);
    if ($ok) {
        $state['enrolments_ensured']++;
        if ($verbose) cli_writeln(sprintf('[ENROL_DONE    ] %s uid=%d role=%s', $tag, $uid, $role));
    } else {
        cli_writeln(sprintf('[ENROL_FAIL    ] %s uid=%d role=%s', $tag, $uid, $role));
    }
}

function mapping_exists(array $m): bool {
    global $DB;
    $where = ['programmeid' => (int)$m['programmeid'], 'moodlecourseid' => (int)$m['moodlecourseid']];
    if (!empty($m['semesterid'])) { $where['semesterid'] = (int)$m['semesterid']; }
    if (!empty($m['levelid'])) { $where['levelid'] = (int)$m['levelid']; }
    return $DB->record_exists('local_ulms_programme_courses', $where);
}

foreach ($mappingdefs as $idx => $m) {
    $label = sprintf('mapping BSCCS/BSMAT#%d prog=%d cid=%d sem=%d lvl=%d', $idx + 1, $m['programmeid'], $m['moodlecourseid'], $m['semesterid'], $m['levelid']);
    $incomplete = false;
    foreach (['programmeid', 'moodlecourseid'] as $req) {
        if (empty($m[$req])) { $incomplete = true; }
    }
    if ($incomplete) {
        $warnings[] = 'Mapping skipped because of dry-run missing IDs: ' . $label;
        $state['mapping_error']++;
        if ($verbose) cli_writeln(sprintf('[MAPPING SKIP  ] %s (foreign entities not yet created)', $label));
        continue;
    }
    $exists = mapping_exists($m);
    if ($exists && !$forcemappings) {
        $state['mapping_skipped_exists']++;
        if ($verbose) cli_writeln(sprintf('[MAPPING EXISTS] %s', $label));
        continue;
    }
    if (!$apply) {
        $state['mapping_created']++;
        if ($verbose) cli_writeln(sprintf('[DRY-RUN MAPPING] %s', $label));
        continue;
    }
    $res = $structservice->save_course_mapping($m);
    if (empty($res['success'])) {
        $errs = isset($res['errors']) && is_array($res['errors']) ? json_encode($res['errors']) : '';
        cli_writeln(sprintf('[MAPPING FAIL  ] %s | msg=%s errors=%s', $label, $res['message'] ?? 'unknown', $errs));
        $state['mapping_error']++;
        continue;
    }
    $state['mapping_created']++;
    if ($verbose) cli_writeln(sprintf('[MAPPING DONE  ] %s id=%d', $label, (int)($res['id'] ?? 0)));
}

$facultycount = (int)$DB->count_records('local_ulms_faculties');
$deptcount = (int)$DB->count_records('local_ulms_departments');
$progcount = (int)$DB->count_records('local_ulms_programmes');
$sesscount = (int)$DB->count_records('local_ulms_sessions');
$semcount = (int)$DB->count_records('local_ulms_semesters');
$levelcount = (int)$DB->count_records('local_ulms_levels');
$coursecount = $catid > 0 ? (int)$DB->count_records('course', ['category' => $catid]) : 0;
$mappingcount = (int)$DB->count_records('local_ulms_programme_courses');

$mode = $dryrun ? 'DRY-RUN' : 'APPLY';
cli_writeln('');
cli_writeln('-------------------------------------------------------');
cli_writeln(sprintf('ULMS Demo Academic Chain Seed — %s summary', $mode));
cli_writeln('-------------------------------------------------------');
cli_writeln(sprintf('  College created/found          : %d / %d  (total faculties: %d)', $state['faculty_created'], $state['faculty_found'], $facultycount));
cli_writeln(sprintf('  Departments created            : %d       (total depts: %d)', $state['department_created'], $deptcount));
cli_writeln(sprintf('  Programmes created             : %d       (total programmes: %d)', $state['programme_created'], $progcount));
cli_writeln(sprintf('  Academic Sessions created      : %d       (total sessions: %d)', $state['session_created'], $sesscount));
cli_writeln(sprintf('  Semesters created              : %d       (total semesters: %d)', $state['semester_created'], $semcount));
cli_writeln(sprintf('  Courses created                : %d       (seed category size: %d)', $state['course_created'], $coursecount));
cli_writeln(sprintf('  Study Levels 100..400 in DB    : %d', $levelcount));
cli_writeln(sprintf('  programme_courses created      : %d       (skipped: %d, errors: %d, total: %d)', $state['mapping_created'], $state['mapping_skipped_exists'], $state['mapping_error'], $mappingcount));
cli_writeln(sprintf('  Course enrolments ensured      : %d       (skipped exists: %d)', $state['enrolments_ensured'], $state['enrolments_skipped']));
if ($warnings) {
    cli_writeln('');
    cli_writeln('Warnings:');
    foreach ($warnings as $w) cli_writeln('  - ' . $w);
}
cli_writeln('');
if ($dryrun) {
    cli_writeln('Run with --apply to persist these records. After --apply, a second run');
    cli_writeln('should show all created counts = 0 and only _EXISTS/_FOUND outputs.');
} else {
    cli_writeln('Seed apply complete. Re-running without --force flags should be a no-op.');
}

// ============================================================
if ($apply) {
    global $CFG;
    require_once($CFG->dirroot . '/lib/enrollib.php');
    require_once($CFG->dirroot . '/local/ulms_dashboard/classes/local/service/schedule_service.php');
    $svc = \local_ulms_dashboard\local\service\schedule_service::instance();

    $lecturer_uid = 65;
    $student_sample_uid = 66;

    $faculty = $DB->get_record('local_ulms_faculties', [], '*', IGNORE_MULTIPLE);
    $facultyid = (int)($faculty->id ?? 1);
    $dept = $DB->get_record('local_ulms_departments', ['facultyid' => $facultyid], '*', IGNORE_MULTIPLE);
    $deptid = (int)($dept->id ?? 1);
    $prog = $DB->get_record('local_ulms_programmes', ['departmentid' => $deptid], '*', IGNORE_MULTIPLE);
    $progid = (int)($prog->id ?? 1);
    $asession = $DB->get_record('local_ulms_sessions', ['iscurrent' => 1], '*', IGNORE_MULTIPLE);
    $sessionid = (int)($asession->id ?? 5);
    $semester = $DB->get_record('local_ulms_semesters', ['sessionid' => $sessionid, 'code' => 'FIRST'], '*', IGNORE_MULTIPLE);
    $semesterid = (int)($semester->id ?? 1);
    $level100 = $DB->get_record('local_ulms_levels', ['code' => '100'], '*', IGNORE_MISSING);
    $levelid = (int)($level100->id ?? 1);

    $DB->execute('DELETE FROM {local_ulms_dashboard_attendance} WHERE sessionid IN (SELECT id FROM {local_ulms_dashboard_session} WHERE title LIKE ?)', ['[DEMO] %']);
    $DB->execute('DELETE FROM {local_ulms_dashboard_session} WHERE title LIKE ?', ['[DEMO] %']);

    $allprogs = $DB->get_records('local_ulms_programmes', null, '', 'id,id', 0, 10);
    $allprogids = [];
    foreach ($allprogs as $p) $allprogids[] = (int)$p->id;
    $pcmaps = [];
    foreach ($allprogids as $apid) {
        $maps = $DB->get_records('local_ulms_programme_courses', ['programmeid' => $apid]);
        foreach ($maps as $m) $pcmaps[] = $m;
    }
    $courseids = [];
    foreach ($pcmaps as $map) {
        if (!empty($map->moodlecourseid)) {
            $courseids[] = (int)$map->moodlecourseid;
        }
    }
    $realdb = $DB->get_records('course', null, '', 'id,id', 0, 20);
    foreach ($realdb as $r) {
        if ((int)$r->id > 1) $courseids[] = (int)$r->id;
    }
    $validated = [];
    foreach ($courseids as $cid_raw) {
        $tc = (int)$cid_raw;
        if ($tc <= 0) continue;
        if (in_array($tc, $validated, true)) continue;
        if ($DB->record_exists('course', ['id' => $tc])) {
            $validated[] = $tc;
            if (count($validated) >= 6) break;
        }
    }
    for ($i = 2; count($validated) < 6 && $i < 50; $i++) {
        if ($DB->record_exists('course', ['id' => $i]) && !in_array($i, $validated, true)) {
            $validated[] = $i;
        }
    }
    $courseids = array_values($validated);

    $term_start = strtotime('2026-09-15');
    $term_end = strtotime('2026-12-12');

    $c0 = $courseids[0] ?? 2;
    $c1 = $courseids[1] ?? $c0;
    $c2 = $courseids[2] ?? $c0;
    $c3 = $courseids[3] ?? $c0;
    $c4 = $courseids[4] ?? $c0;
    $c5 = $courseids[5] ?? $c0;

    $sessdefs = [
        [
            'title' => '[DEMO] CS101 Intro to CS — Lecture',
            'facultyid' => $facultyid,
            'departmentid' => $deptid,
            'moodlecourseid' => $c0,
            'programmeid' => $progid,
            'levelid' => $levelid,
            'sessionid' => $sessionid,
            'semesterid' => $semesterid,
            'lecturer_userid' => $lecturer_uid,
            'delivery_mode' => 'lecture',
            'weekday' => 1,
            'start_minutes' => 540,
            'duration_minutes' => 60,
            'term_start_date' => $term_start,
            'term_end_date' => $term_end,
            'location_mode' => 'online',
            'location_label' => 'BBB Room A-101',
            'provider_key' => 'bigbluebutton',
            'recurrence' => 'weekly',
            'status' => 'scheduled',
            'notes_public' => 'Bring laptop. Slides on VLE.',
            'notes_private' => 'Cover chapters 1-3; quiz at 30min.',
        ],
        [
            'title' => '[DEMO] CS101 Intro to CS — Tutorial',
            'facultyid' => $facultyid,
            'departmentid' => $deptid,
            'moodlecourseid' => $c0,
            'programmeid' => $progid,
            'levelid' => $levelid,
            'sessionid' => $sessionid,
            'semesterid' => $semesterid,
            'lecturer_userid' => $lecturer_uid,
            'delivery_mode' => 'tutorial',
            'weekday' => 3,
            'start_minutes' => 660,
            'duration_minutes' => 60,
            'term_start_date' => $term_start,
            'term_end_date' => $term_end,
            'location_mode' => 'physical',
            'location_label' => 'Tutorial Hall B2',
            'provider_key' => 'bigbluebutton',
            'recurrence' => 'weekly',
            'status' => 'scheduled',
            'notes_public' => 'Problem sheet 1 review.',
            'notes_private' => 'Focus on recursion exercises.',
        ],
        [
            'title' => '[DEMO] CS201 Data Structures — Lecture',
            'facultyid' => $facultyid,
            'departmentid' => $deptid,
            'moodlecourseid' => $c1,
            'programmeid' => $progid,
            'levelid' => $levelid,
            'sessionid' => $sessionid,
            'semesterid' => $semesterid,
            'lecturer_userid' => $lecturer_uid,
            'delivery_mode' => 'lecture',
            'weekday' => 2,
            'start_minutes' => 600,
            'duration_minutes' => 60,
            'term_start_date' => $term_start,
            'term_end_date' => $term_end,
            'location_mode' => 'online',
            'location_label' => 'Zoom Room CS201',
            'provider_key' => 'lti_zoom',
            'recurrence' => 'weekly',
            'status' => 'scheduled',
            'notes_public' => 'Linked lists and arrays.',
            'notes_private' => 'Big-O analysis primer.',
        ],
        [
            'title' => '[DEMO] CS201 Data Structures — Lab',
            'facultyid' => $facultyid,
            'departmentid' => $deptid,
            'moodlecourseid' => $c1,
            'programmeid' => $progid,
            'levelid' => $levelid,
            'sessionid' => $sessionid,
            'semesterid' => $semesterid,
            'lecturer_userid' => $lecturer_uid,
            'delivery_mode' => 'tutorial',
            'weekday' => 2,
            'start_minutes' => 600,
            'duration_minutes' => 90,
            'term_start_date' => $term_start,
            'term_end_date' => $term_end,
            'location_mode' => 'physical',
            'location_label' => 'Computer Lab 3',
            'provider_key' => 'bigbluebutton',
            'recurrence' => 'weekly',
            'status' => 'scheduled',
            'notes_public' => 'Hands-on linked list implementation.',
            'notes_private' => 'Conflict pair with CS201 Tue 10:00 lecture.',
        ],
        [
            'title' => '[DEMO] MA101 Calculus I — Lecture',
            'facultyid' => $facultyid,
            'departmentid' => $deptid,
            'moodlecourseid' => $c2,
            'programmeid' => $progid,
            'levelid' => $levelid,
            'sessionid' => $sessionid,
            'semesterid' => $semesterid,
            'lecturer_userid' => $lecturer_uid,
            'delivery_mode' => 'lecture',
            'weekday' => 1,
            'start_minutes' => 840,
            'duration_minutes' => 90,
            'term_start_date' => $term_start,
            'term_end_date' => $term_end,
            'location_mode' => 'physical',
            'location_label' => 'Lecture Theatre 1',
            'provider_key' => 'lti_msft_teams',
            'recurrence' => 'weekly',
            'status' => 'scheduled',
            'notes_public' => 'Limits and continuity.',
            'notes_private' => 'Board examples from textbook §1.2-1.5.',
        ],
        [
            'title' => '[DEMO] MA101 Calculus I — Workshop',
            'facultyid' => $facultyid,
            'departmentid' => $deptid,
            'moodlecourseid' => $c2,
            'programmeid' => $progid,
            'levelid' => $levelid,
            'sessionid' => $sessionid,
            'semesterid' => $semesterid,
            'lecturer_userid' => $lecturer_uid,
            'delivery_mode' => 'workshop',
            'weekday' => 4,
            'start_minutes' => 540,
            'duration_minutes' => 90,
            'term_start_date' => $term_start,
            'term_end_date' => $term_end,
            'location_mode' => 'online',
            'location_label' => 'Teams Live Session',
            'provider_key' => 'lti_msft_teams',
            'recurrence' => 'weekly',
            'status' => 'scheduled',
            'notes_public' => 'Group problem solving.',
            'notes_private' => 'Split into breakout rooms of 4.',
        ],
        [
            'title' => '[DEMO] MA201 Real Analysis — Lecture',
            'facultyid' => $facultyid,
            'departmentid' => $deptid,
            'moodlecourseid' => $c3,
            'programmeid' => $progid,
            'levelid' => $levelid,
            'sessionid' => $sessionid,
            'semesterid' => $semesterid,
            'lecturer_userid' => $lecturer_uid,
            'delivery_mode' => 'lecture',
            'weekday' => 3,
            'start_minutes' => 840,
            'duration_minutes' => 60,
            'term_start_date' => $term_start,
            'term_end_date' => $term_end,
            'location_mode' => 'physical',
            'location_label' => 'Lecture Theatre 2',
            'provider_key' => 'bigbluebutton',
            'recurrence' => 'weekly',
            'status' => 'scheduled',
            'notes_public' => 'Sequences and convergence.',
            'notes_private' => 'Cauchy criterion proof walkthrough.',
        ],
        [
            'title' => '[DEMO] MA201 Real Analysis — Tutorial',
            'facultyid' => $facultyid,
            'departmentid' => $deptid,
            'moodlecourseid' => $c3,
            'programmeid' => $progid,
            'levelid' => $levelid,
            'sessionid' => $sessionid,
            'semesterid' => $semesterid,
            'lecturer_userid' => $lecturer_uid,
            'delivery_mode' => 'tutorial',
            'weekday' => 5,
            'start_minutes' => 660,
            'duration_minutes' => 60,
            'term_start_date' => $term_start,
            'term_end_date' => $term_end,
            'location_mode' => 'online',
            'location_label' => 'Custom Stream URL',
            'provider_key' => 'custom_url',
            'recurrence' => 'weekly',
            'status' => 'scheduled',
            'notes_public' => 'Tutorial sheet 2 solutions.',
            'notes_private' => 'Go through Q5 carefully.',
        ],
        [
            'title' => '[DEMO] CS101 Intro to CS — Lab',
            'facultyid' => $facultyid,
            'departmentid' => $deptid,
            'moodlecourseid' => $c0,
            'programmeid' => $progid,
            'levelid' => $levelid,
            'sessionid' => $sessionid,
            'semesterid' => $semesterid,
            'lecturer_userid' => $lecturer_uid,
            'delivery_mode' => 'lab',
            'weekday' => 4,
            'start_minutes' => 840,
            'duration_minutes' => 90,
            'term_start_date' => $term_start,
            'term_end_date' => $term_end,
            'location_mode' => 'physical',
            'location_label' => 'Computer Lab 1',
            'provider_key' => 'bigbluebutton',
            'recurrence' => 'weekly',
            'status' => 'scheduled',
            'notes_public' => 'Python setup and first programs.',
            'notes_private' => 'Ensure Anaconda installed on lab PCs.',
        ],
        [
            'title' => '[DEMO] CS201 Data Structures — Workshop',
            'facultyid' => $facultyid,
            'departmentid' => $deptid,
            'moodlecourseid' => $c1,
            'programmeid' => $progid,
            'levelid' => $levelid,
            'sessionid' => $sessionid,
            'semesterid' => $semesterid,
            'lecturer_userid' => $lecturer_uid,
            'delivery_mode' => 'workshop',
            'weekday' => 5,
            'start_minutes' => 540,
            'duration_minutes' => 60,
            'term_start_date' => $term_start,
            'term_end_date' => $term_end,
            'location_mode' => 'online',
            'location_label' => 'Zoom Workshop Room',
            'provider_key' => 'lti_zoom',
            'recurrence' => 'weekly',
            'status' => 'scheduled',
            'notes_public' => 'Stacks and queues whiteboarding.',
            'notes_private' => 'Pair programming exercise.',
        ],
        [
            'title' => '[DEMO] MA101 Calculus I — Seminar',
            'facultyid' => $facultyid,
            'departmentid' => $deptid,
            'moodlecourseid' => $c2,
            'programmeid' => $progid,
            'levelid' => $levelid,
            'sessionid' => $sessionid,
            'semesterid' => $semesterid,
            'lecturer_userid' => $lecturer_uid,
            'delivery_mode' => 'seminar',
            'weekday' => 2,
            'start_minutes' => 840,
            'duration_minutes' => 60,
            'term_start_date' => $term_start,
            'term_end_date' => $term_end,
            'location_mode' => 'physical',
            'location_label' => 'Seminar Room C',
            'provider_key' => 'bigbluebutton',
            'recurrence' => 'weekly',
            'status' => 'scheduled',
            'notes_public' => 'History of calculus discussion.',
            'notes_private' => 'Assign reading Newton vs Leibniz.',
        ],
        [
            'title' => '[DEMO] MA201 Real Analysis — Office Hour',
            'facultyid' => $facultyid,
            'departmentid' => $deptid,
            'moodlecourseid' => $c3,
            'programmeid' => $progid,
            'levelid' => $levelid,
            'sessionid' => $sessionid,
            'semesterid' => $semesterid,
            'lecturer_userid' => $lecturer_uid,
            'delivery_mode' => 'office_hour',
            'weekday' => 1,
            'start_minutes' => 660,
            'duration_minutes' => 60,
            'term_start_date' => $term_start,
            'term_end_date' => $term_end,
            'location_mode' => 'online',
            'location_label' => 'BBB Office Hours',
            'provider_key' => 'bigbluebutton',
            'recurrence' => 'weekly',
            'status' => 'scheduled',
            'notes_public' => 'Drop-in Q&A. No appointment needed.',
            'notes_private' => 'Prioritise MA201 students with problem sheets.',
        ],
        [
            'title' => '[DEMO] CS101 Intro to CS — Office Hour',
            'facultyid' => $facultyid,
            'departmentid' => $deptid,
            'moodlecourseid' => $c0,
            'programmeid' => $progid,
            'levelid' => $levelid,
            'sessionid' => $sessionid,
            'semesterid' => $semesterid,
            'lecturer_userid' => $lecturer_uid,
            'delivery_mode' => 'office_hour',
            'weekday' => 3,
            'start_minutes' => 540,
            'duration_minutes' => 60,
            'term_start_date' => $term_start,
            'term_end_date' => $term_end,
            'location_mode' => 'physical',
            'location_label' => 'Staff Office 4B',
            'provider_key' => 'bigbluebutton',
            'recurrence' => 'weekly',
            'status' => 'scheduled',
            'notes_public' => 'Help with assignment 1 setup.',
            'notes_private' => 'Expect many Python install questions.',
        ],
    ];

    $studentids = array_values($DB->get_records_sql_menu('SELECT id,id FROM {user} WHERE deleted=0 AND confirmed=1 AND id != 1 AND id != ? AND id != ? LIMIT 60', [$lecturer_uid, 64]));
    if (count($studentids) < 12) {
        for ($i = 100; $i <= 200; $i++) {
            $studentids[] = $i;
        }
    }
    if (!in_array($student_sample_uid, $studentids, true)) {
        array_unshift($studentids, $student_sample_uid);
    }
    $studentids = array_values(array_unique(array_map('intval', $studentids)));

    foreach ($courseids as $cid_raw) {
        $ecid = (int)$cid_raw;
        if ($ecid <= 0) continue;
        try {
            enrol_try_internal_enrol($ecid, $lecturer_uid, 'editingteacher');
        } catch (\Throwable $e) {
        }
    }

    $admin_ids = array_values($DB->get_records_sql_menu('SELECT id,id FROM {user} WHERE deleted=0 AND confirmed=1 AND id > 0 LIMIT 5'));
    $actor_uid = !empty($admin_ids[0]) ? (int)$admin_ids[0] : 1;
    if (function_exists('is_siteadmin')) {
        foreach ($admin_ids as $aid) {
            if (is_siteadmin((int)$aid)) {
                $actor_uid = (int)$aid;
                break;
            }
        }
    }

    $course_rosters = [];
    for ($ci = 0; $ci < count($courseids); $ci++) {
        $cid = (int)$courseids[$ci];
        if ($cid <= 0) continue;
        $start = $ci * 10;
        $slice = array_slice($studentids, $start, 10);
        if (count($slice) < 10) {
            for ($fi = 0; count($slice) < 10; $fi++) {
                $slice[] = 100 + $fi + $start;
            }
        }
        if (!in_array($student_sample_uid, $slice, true)) {
            $slice[0] = $student_sample_uid;
        }
        $course_rosters[$cid] = array_values(array_map('intval', $slice));
        foreach ($course_rosters[$cid] as $uid) {
            try {
                enrol_try_internal_enrol($cid, $uid, 'student');
            } catch (\Throwable $e) {
            }
        }
    }

    $created_ids = [];
    foreach ($sessdefs as $sd) {
        try {
            $res = $svc->save_session($sd, $actor_uid);
        } catch (\Throwable $e) {
            $res = ['success' => false, 'message' => $e->getMessage()];
        }
        if (!empty($res['success'])) {
            $created_ids[] = (int)$res['id'];
        } else {
            $warnings[] = 'session save: ' . ($res['message'] ?? 'unknown') . ' ' . json_encode($res['errors'] ?? []);
        }
    }

    $attendance_target = array_slice($created_ids, 0, 6);
    if (count($attendance_target) < 6) {
        $attendance_target = $created_ids;
    }

    $occ_mon = strtotime('monday this week', $term_start);
    $occ_tue = strtotime('tuesday this week', $term_start);

    $roster_map = [];
    foreach ($created_ids as $sid) {
        $sessionrec = $DB->get_record('local_ulms_dashboard_session', ['id' => $sid], '*', IGNORE_MISSING);
        if (!$sessionrec) continue;
        $cid = (int)$sessionrec->moodlecourseid;
        if (isset($course_rosters[$cid])) {
            $roster_map[$sid] = $course_rosters[$cid];
        } else {
            $roster_map[$sid] = array_slice($studentids, 0, 10);
        }
    }

    foreach ($attendance_target as $sidx => $sid) {
        $roster = $roster_map[$sid] ?? array_slice($studentids, 0, 10);
        foreach ($roster as $uid) {
            $hash = crc32($sid . '_' . $uid) % 100;
            if ($hash < 70) {
                $status = 'present';
            } elseif ($hash < 81) {
                $status = 'absent';
            } elseif ($hash < 93) {
                $status = 'late';
            } else {
                $status = 'excused';
            }
            try {
                $svc->mark_attendance($sid, $occ_mon, $uid, $status, $actor_uid);
            } catch (\Throwable $e) {
            }
        }
        if ($sidx === 1 && !empty($created_ids[2])) {
            try {
                $svc->bulk_mark_all_present((int)$created_ids[2], $occ_tue, $actor_uid);
            } catch (\Throwable $e) {
            }
        }
    }

    if (count($attendance_target) >= 1) {
        $sid_extra = (int)$attendance_target[count($attendance_target) - 1];
        try {
            $svc->bulk_mark_all_present($sid_extra, $occ_tue, $actor_uid);
        } catch (\Throwable $e) {
        }
    }

    $nowts = time();
    $markdates = [$occ_mon, $occ_tue];
    foreach ($created_ids as $csid) {
        if ($csid <= 0) continue;
        $srec = $DB->get_record('local_ulms_dashboard_session', ['id' => $csid], '*', IGNORE_MISSING);
        if (!$srec) continue;
        $mcid = (int)$srec->moodlecourseid;
        $roster = $course_rosters[$mcid] ?? $studentids;
        if (count($roster) < 10) {
            for ($ri = 100; count($roster) < 10; $ri++) {
                if (!in_array($ri, $roster, true)) $roster[] = $ri;
            }
        }
        if (!in_array($student_sample_uid, $roster, true)) {
            $roster[0] = $student_sample_uid;
        }
        $roster = array_slice(array_values(array_map('intval', $roster)), 0, 10);
        foreach ($markdates as $md_idx => $md_ts) {
            foreach ($roster as $pos => $uid) {
                $existing = $DB->record_exists('local_ulms_dashboard_attendance', [
                    'sessionid' => $csid,
                    'session_occurrence_date' => (int)$md_ts,
                    'userid' => (int)$uid,
                ]);
                if ($existing) continue;
                $hash = crc32($csid . '_' . $md_idx . '_' . $uid) % 100;
                if ($hash < 70) $st = 'present';
                elseif ($hash < 81) $st = 'absent';
                elseif ($hash < 93) $st = 'late';
                else $st = 'excused';
                if ($uid === $student_sample_uid && $md_idx === 0 && $pos < 7) $st = 'present';
                try {
                    $o = new \stdClass();
                    $o->sessionid = $csid;
                    $o->session_occurrence_date = (int)$md_ts;
                    $o->userid = (int)$uid;
                    $o->status = $st;
                    $o->marked_by = $lecturer_uid;
                    $o->marked_at = $nowts;
                    $o->comment = null;
                    $DB->insert_record('local_ulms_dashboard_attendance', $o, false);
                } catch (\Throwable $e) {
                }
            }
        }
    }

    $sesscnt = (int)$DB->count_records_select('local_ulms_dashboard_session', 'title LIKE ?', ['[DEMO] %']);
    $attcnt  = (int)$DB->count_records_select('local_ulms_dashboard_attendance', 'sessionid IN (SELECT id FROM {local_ulms_dashboard_session} WHERE title LIKE ?)', ['[DEMO] %']);
    $s66cnt  = (int)$DB->count_records_select('local_ulms_dashboard_attendance', 'userid = ? AND sessionid IN (SELECT id FROM {local_ulms_dashboard_session} WHERE title LIKE ?)', [$student_sample_uid, '[DEMO] %']);
    $confl   = $svc->find_conflicts(0, is_int($term_start) ? $term_start : strtotime('monday this week'));
    $conflcnt = count($confl);
    cli_writeln('');
    cli_writeln(sprintf('[DEMO_CLASS_DELIVERY] sessions=%d attendance=%d student66_rows=%d conflict_pairs=%d', $sesscnt, $attcnt, $s66cnt, $conflcnt));
    if ($sesscnt < 12 || $attcnt < 60 || $s66cnt < 6 || $conflcnt < 1) {
        $warnings[] = 'demo seed AC threshold not met (need sessions>=12 attendance>=60 s66>=6 conflicts>=1)';
    }
    if ($warnings) {
        cli_writeln('');
        cli_writeln('Demo class-delivery warnings:');
        foreach ($warnings as $w) cli_writeln('  - ' . $w);
    }
}
// ===================== END DEMO CLASS-DELIVERY SEED =======

exit(0);
