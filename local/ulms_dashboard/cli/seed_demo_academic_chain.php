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
foreach ($levelsneeded as $idx => $lvl) {
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
    $levelres = $structservice->save_level((array)$lvlrec);
    if (empty($levelres['success']) || empty($levelres['level'])) {
        cli_writeln(sprintf('[ERROR LEVEL_FAIL] %s | %s', $lvl['code'], $levelres['message'] ?? 'unknown error'));
        exit(2);
    }
    $lid = (int)$levelres['level']->id;
    $levelmap[$lvl['code']] = $lid;
    if ($verbose) {
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
exit(0);
