<?php

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/moodlelib.php');

global $DB;

$rawopts = getopt('', ['apply', 'help', 'verbose']);
$help = isset($rawopts['help']);
$apply = isset($rawopts['apply']);
$verbose = isset($rawopts['verbose']);
$dryrun = !$apply;

if ($help) {
    cli_writeln('ULMS Repair Academic User Profiles CLI');
    cli_writeln('');
    cli_writeln('Usage:');
    cli_writeln('  php repair_academic_profiles.php [--apply] [--verbose]');
    cli_writeln('');
    cli_writeln('Options:');
    cli_writeln('  --apply       Apply profile upserts. Defaults to dry-run.');
    cli_writeln('  --verbose     Print per-user diagnostics.');
    cli_writeln('  --help        Show this help.');
    cli_writeln('');
    cli_writeln('Purpose:');
    cli_writeln('  Ensures every managed user (role: superadmin/manager/lecturer/student)');
    cli_writeln('  has a valid local_ulms_user_profile row with consistent faculty/dept/');
    cli_writeln('  programme FKs. Defaults missing hierarchies from programme-level links.');
    cli_writeln('  Idempotent: re-running --apply performs zero extra inserts after first run.');
    exit(0);
}

$profiletable = 'local_ulms_user_profile';
$profilemanager = $DB->get_manager();
if (!$profilemanager->table_exists(new xmldb_table($profiletable))) {
    cli_writeln('[ERROR] Missing required academic table: ' . $profiletable);
    exit(2);
}

$managedroleids = [];
$managedroleshortnames = ['manager', 'coursecreator', 'editingteacher', 'teacher', 'student', 'ictadmin', 'facultyadmin', 'departmentadmin'];
foreach ($managedroleshortnames as $shortname) {
    $role = $DB->get_record('role', ['shortname' => $shortname], 'id,shortname', IGNORE_MISSING);
    if ($role) {
        $managedroleids[$shortname] = (int)$role->id;
    }
}
$siteadminids = array_filter(array_map('intval', explode(',', (string)($CFG->siteadmins ?? ''))));

if (empty($managedroleids) && empty($siteadminids)) {
    cli_writeln('[ERROR] No ULMS-managed Moodle roles configured on this instance.');
    exit(2);
}

$sysctx = \context_system::instance()->id;
$managedusers = [];

if (!empty($managedroleids)) {
    [$inrolesql, $inroleparams] = $DB->get_in_or_equal(array_values($managedroleids), SQL_PARAMS_NAMED, 'rol');
    $roleusers = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.username, u.firstname, u.lastname, u.email, r.shortname AS rolename
           FROM {user} u
           JOIN {role_assignments} ra ON ra.userid = u.id AND ra.contextid = :sysctx
           JOIN {role} r ON r.id = ra.roleid
          WHERE r.id {$inrolesql}
            AND u.deleted = 0
       ORDER BY u.username",
        ['sysctx' => $sysctx] + $inroleparams
    );
    foreach ($roleusers as $u) { $managedusers[(int)$u->id] = $u; }
}

foreach ($siteadminids as $said) {
    if (isset($managedusers[$said])) {
        $managedusers[$said]->rolename = 'superadmin';
        continue;
    }
    $u = $DB->get_record('user', ['id' => $said, 'deleted' => 0], 'id,username,firstname,lastname,email', IGNORE_MISSING);
    if (!$u) { continue; }
    $u->rolename = 'superadmin';
    $managedusers[(int)$u->id] = $u;
}

function pick_first_facultyid(): int {
    global $DB;
    $row = $DB->get_record_sql('SELECT id FROM {local_ulms_faculties} WHERE status = ? ORDER BY id ASC LIMIT 1', ['active'], IGNORE_MISSING);
    return (int)($row->id ?? 0);
}
function pick_first_department_for_faculty(int $facultyid): int {
    global $DB;
    if ($facultyid <= 0) { return 0; }
    $row = $DB->get_record_sql('SELECT id FROM {local_ulms_departments} WHERE facultyid = ? AND status = ? ORDER BY id ASC LIMIT 1', [$facultyid, 'active'], IGNORE_MISSING);
    return (int)($row->id ?? 0);
}
function pick_first_programme_for_department(int $departmentid): int {
    global $DB;
    if ($departmentid <= 0) { return 0; }
    $row = $DB->get_record_sql('SELECT id FROM {local_ulms_programmes} WHERE departmentid = ? AND status = ? ORDER BY id ASC LIMIT 1', [$departmentid, 'active'], IGNORE_MISSING);
    return (int)($row->id ?? 0);
}
function pick_first_active_programme_with_mappings(): int {
    global $DB;
    if (!$DB->get_manager()->table_exists(new xmldb_table('local_ulms_programme_courses'))) {
        return 0;
    }
    $row = $DB->get_record_sql('SELECT DISTINCT p.id FROM {local_ulms_programmes} p
                                  JOIN {local_ulms_programme_courses} pc ON pc.programmeid = p.id
                                 WHERE p.status = ? ORDER BY p.id ASC LIMIT 1', ['active'], IGNORE_MISSING);
    return (int)($row->id ?? 0);
}
function resolve_programme_parent_ids(int $programmeid): array {
    global $DB;
    $out = ['facultyid' => 0, 'departmentid' => 0, 'programmeid' => $programmeid];
    if ($programmeid <= 0) { return $out; }
    $prog = $DB->get_record('local_ulms_programmes', ['id' => $programmeid], 'id,departmentid', IGNORE_MISSING);
    if (!$prog) { return $out; }
    $out['departmentid'] = (int)$prog->departmentid;
    if ($out['departmentid'] > 0) {
        $dept = $DB->get_record('local_ulms_departments', ['id' => $out['departmentid']], 'id,facultyid', IGNORE_MISSING);
        if ($dept) { $out['facultyid'] = (int)$dept->facultyid; }
    }
    return $out;
}
function derive_programme_for_student(int $userid): int {
    global $DB;
    if (!$DB->get_manager()->table_exists(new xmldb_table('local_ulms_programme_courses'))) {
        return 0;
    }
    $enrolids = [];
    $rs = $DB->get_recordset_sql('SELECT DISTINCT e.courseid FROM {enrol} e
                                   JOIN {user_enrolments} ue ON ue.enrolid = e.id
                                  WHERE ue.userid = :uid', ['uid' => $userid]);
    foreach ($rs as $r) { $enrolids[] = (int)$r->courseid; }
    $rs->close();
    if (empty($enrolids)) { return 0; }
    [$in, $p] = $DB->get_in_or_equal($enrolids, SQL_PARAMS_NAMED, 'mc');
    $programmecounts = [];
    $rs2 = $DB->get_recordset_sql("SELECT programmeid FROM {local_ulms_programme_courses} WHERE moodlecourseid {$in}", $p);
    foreach ($rs2 as $r) {
        $pid = (int)$r->programmeid;
        if ($pid <= 0) { continue; }
        $programmecounts[$pid] = isset($programmecounts[$pid]) ? $programmecounts[$pid] + 1 : 1;
    }
    $rs2->close();
    if (empty($programmecounts)) { return 0; }
    arsort($programmecounts);
    $best = (int)array_key_first($programmecounts);
    return $best > 0 ? $best : 0;
}
function derive_programme_for_lecturer(int $userid): int {
    global $DB, $CFG;
    if (!$DB->get_manager()->table_exists(new xmldb_table('local_ulms_programme_courses'))) {
        return 0;
    }
    $lecturershorts = ['editingteacher', 'teacher', 'coursecreator'];
    $lecturerroleids = [];
    foreach ($lecturershorts as $sname) {
        $r = $DB->get_record('role', ['shortname' => $sname], 'id', IGNORE_MISSING);
        if ($r) { $lecturerroleids[(int)$r->id] = (int)$r->id; }
    }
    if (empty($lecturerroleids)) { return 0; }
    [$inr, $rp] = $DB->get_in_or_equal(array_values($lecturerroleids), SQL_PARAMS_NAMED, 'r');
    $courseids = [];
    $rs = $DB->get_recordset_sql(
        "SELECT cx.instanceid AS courseid
           FROM {role_assignments} ra
           JOIN {context} cx ON cx.id = ra.contextid AND cx.contextlevel = :clvl
          WHERE ra.userid = :uid AND ra.roleid {$inr}",
        ['uid' => $userid, 'clvl' => CONTEXT_COURSE] + $rp
    );
    foreach ($rs as $r) { $courseids[] = (int)$r->courseid; }
    $rs->close();
    $courseids = array_values(array_unique($courseids));
    if (empty($courseids)) { return 0; }
    [$in, $p] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'mc');
    $programmecounts = [];
    $rs = $DB->get_recordset_sql("SELECT programmeid FROM {local_ulms_programme_courses} WHERE moodlecourseid {$in}", $p);
    foreach ($rs as $r) {
        $pid = (int)$r->programmeid;
        if ($pid <= 0) { continue; }
        $programmecounts[$pid] = isset($programmecounts[$pid]) ? $programmecounts[$pid] + 1 : 1;
    }
    $rs->close();
    if (empty($programmecounts)) { return 0; }
    arsort($programmecounts);
    $best = (int)array_key_first($programmecounts);
    return $best > 0 ? $best : 0;
}
function derive_programme_by_default(string $rolename): int {
    $try = pick_first_active_programme_with_mappings();
    if ($try > 0) { return $try; }
    $firstfac = pick_first_facultyid();
    $firstdept = pick_first_department_for_faculty($firstfac);
    return pick_first_programme_for_department($firstdept);
}
function validate_or_nulify_invalid_fks(object &$rec): void {
    global $DB;
    if ($rec->facultyid > 0 && !$DB->record_exists('local_ulms_faculties', ['id' => $rec->facultyid])) {
        $rec->facultyid = 0;
    }
    if ($rec->departmentid > 0 && !$DB->record_exists('local_ulms_departments', ['id' => $rec->departmentid])) {
        $rec->departmentid = 0;
    }
    if ($rec->programmeid > 0 && !$DB->record_exists('local_ulms_programmes', ['id' => $rec->programmeid])) {
        $rec->programmeid = 0;
    }
    if ($rec->studylevel !== '' && $DB->get_manager()->table_exists(new xmldb_table('local_ulms_levels'))) {
        if (!$DB->record_exists('local_ulms_levels', ['code' => $rec->studylevel])) {
            $rec->studylevel = '';
        }
    }
}

$totalusers = count($managedusers);
$createdcount = 0;
$patchedcount = 0;
$unchangedcount = 0;
$skippedcount = 0;

$now = time();

foreach ($managedusers as $user) {
    $userid = (int)$user->id;
    $rolename = (string)$user->rolename;
    $existing = $DB->get_record($profiletable, ['userid' => $userid], '*', IGNORE_MISSING);
    $record = $existing ? (object)array_map('strval', (array)$existing) : (object)[
        'userid' => (string)$userid,
        'facultyid' => '0',
        'departmentid' => '0',
        'programmeid' => '0',
        'studylevel' => '',
        'staffid' => '',
        'timecreated' => (string)$now,
        'timemodified' => (string)$now,
    ];

    $record->facultyid = (int)$record->facultyid;
    $record->departmentid = (int)$record->departmentid;
    $record->programmeid = (int)$record->programmeid;
    $record->timecreated = (int)$record->timecreated;
    $record->timemodified = (int)$record->timemodified;

    validate_or_nulify_invalid_fks($record);

    $before = clone $record;

    $enrolmentderivedprog = 0;
    if ($rolename === 'student') {
        $enrolmentderivedprog = derive_programme_for_student($userid);
    } elseif ($rolename === 'editingteacher' || $rolename === 'teacher' || $rolename === 'coursecreator') {
        $enrolmentderivedprog = derive_programme_for_lecturer($userid);
    }

    $usederivedforparent = false;
    if ($enrolmentderivedprog > 0 && $enrolmentderivedprog !== (int)$record->programmeid) {
        $record->programmeid = $enrolmentderivedprog;
        $usederivedforparent = true;
    }

    if ((int)$record->programmeid > 0) {
        $resolved = resolve_programme_parent_ids((int)$record->programmeid);
        if ($record->facultyid <= 0) { $record->facultyid = (int)$resolved['facultyid']; }
        if ($record->departmentid <= 0) { $record->departmentid = (int)$resolved['departmentid']; }
    } else {
        $derivedprog = $enrolmentderivedprog;
        if ($derivedprog <= 0) {
            $derivedprog = derive_programme_by_default($rolename);
        }
        if ($derivedprog > 0) {
            $resolved = resolve_programme_parent_ids($derivedprog);
            $record->programmeid = $derivedprog;
            $record->facultyid = (int)$resolved['facultyid'];
            $record->departmentid = (int)$resolved['departmentid'];
        } else {
            if ($record->facultyid <= 0) { $record->facultyid = pick_first_facultyid(); }
            if ($record->departmentid <= 0) { $record->departmentid = pick_first_department_for_faculty($record->facultyid); }
            if ($record->programmeid <= 0) { $record->programmeid = pick_first_programme_for_department($record->departmentid); }
        }
    }

    if ($usederivedforparent && (int)$record->programmeid > 0) {
        $resolved = resolve_programme_parent_ids((int)$record->programmeid);
        $record->facultyid = (int)$resolved['facultyid'];
        $record->departmentid = (int)$resolved['departmentid'];
    }

    if ($rolename === 'student' && $record->studylevel === '' && $DB->get_manager()->table_exists(new xmldb_table('local_ulms_levels'))) {
        $activelevels = $DB->get_records_menu('local_ulms_levels', ['status' => 'active'], 'sortorder ASC, id ASC', 'id,code');
        if (!empty($activelevels)) {
            $record->studylevel = (string)reset($activelevels);
        }
    }

    if (($rolename !== 'student') && trim((string)$record->staffid) === '') {
        $prefixmap = [
            'superadmin' => 'SA', 'manager' => 'MGR', 'coursecreator' => 'CC',
            'editingteacher' => 'LEC', 'teacher' => 'TCH',
            'ictadmin' => 'ICT', 'facultyadmin' => 'FA', 'departmentadmin' => 'DA',
        ];
        $prefix = isset($prefixmap[$rolename]) ? $prefixmap[$rolename] : strtoupper(substr($rolename, 0, 3));
        $record->staffid = sprintf('%s-%05d', $prefix, $userid);
    }

    validate_or_nulify_invalid_fks($record);

    $equal = true;
    $keydiff = [];
    foreach (['facultyid', 'departmentid', 'programmeid', 'studylevel', 'staffid'] as $k) {
        if (is_int($before->$k) || is_int($record->$k)) {
            if ((int)$before->$k !== (int)$record->$k) { $equal = false; $keydiff[$k] = [$before->$k, $record->$k]; }
        } else {
            if ((string)$before->$k !== (string)$record->$k) { $equal = false; $keydiff[$k] = [$before->$k, $record->$k]; }
        }
    }

    $iscreate = empty($existing);
    if ($iscreate && $dryrun) {
        $createdcount++;
        if ($verbose) {
            cli_writeln(sprintf('[DRY-RUN CREATE] user=%d (%s) role=%s faculty=%d dept=%d prog=%d level="%s" staff="%s"',
                $userid, $user->username, $rolename, $record->facultyid, $record->departmentid, $record->programmeid,
                $record->studylevel, $record->staffid));
        }
        continue;
    }
    if (!$iscreate && $equal) {
        $unchangedcount++;
        if ($verbose) {
            cli_writeln(sprintf('[UNCHANGED       ] user=%d (%s) role=%s', $userid, $user->username, $rolename));
        }
        continue;
    }
    if (!$iscreate && $dryrun) {
        $patchedcount++;
        if ($verbose) {
            $diffstr = [];
            foreach ($keydiff as $k => [$b, $a]) { $diffstr[] = sprintf('%s: %s→%s', $k, var_export($b, true), var_export($a, true)); }
            cli_writeln(sprintf('[DRY-RUN PATCH ] user=%d (%s) role=%s | %s',
                $userid, $user->username, $rolename, implode('; ', $diffstr)));
        }
        continue;
    }

    $payload = (object)[
        'userid' => $userid,
        'facultyid' => (int)$record->facultyid,
        'departmentid' => (int)$record->departmentid,
        'programmeid' => (int)$record->programmeid,
        'studylevel' => (string)$record->studylevel,
        'staffid' => (string)$record->staffid,
        'timemodified' => $now,
    ];
    try {
        if ($iscreate) {
            $payload->timecreated = $now;
            $DB->insert_record($profiletable, $payload);
            $createdcount++;
            if ($verbose) {
                cli_writeln(sprintf('[CREATED         ] user=%d (%s) role=%s prog=%d', $userid, $user->username, $rolename, $record->programmeid));
            }
        } else {
            $payload->id = (int)$existing->id;
            $DB->update_record($profiletable, $payload);
            $patchedcount++;
            if ($verbose) {
                cli_writeln(sprintf('[PATCHED         ] user=%d (%s) role=%s', $userid, $user->username, $rolename));
            }
        }
    } catch (\Throwable $e) {
        $skippedcount++;
        cli_writeln(sprintf('[SKIP %-10s ] user=%d (%s) role=%s | %s', 'DB_FAIL', $userid, $user->username, $rolename, $e->getMessage()));
    }
}

$existingcount = (int)$DB->count_records($profiletable);

$mode = $dryrun ? 'DRY-RUN' : 'APPLY';
cli_writeln('');
cli_writeln('-------------------------------------------------------');
cli_writeln(sprintf('ULMS Academic Profile Repair — %s summary', $mode));
cli_writeln('-------------------------------------------------------');
cli_writeln(sprintf('Managed users examined : %d', $totalusers));
cli_writeln(sprintf('Profile rows to create : %d', $createdcount));
cli_writeln(sprintf('Profile rows to patch  : %d', $patchedcount));
cli_writeln(sprintf('Profiles unchanged     : %d', $unchangedcount));
cli_writeln(sprintf('Rows skipped (errors)  : %d', $skippedcount));
cli_writeln(sprintf('Total %s rows after   : %d', $profiletable, $existingcount));
cli_writeln('');
if ($dryrun) {
    cli_writeln('Run with --apply to persist these changes.');
} else {
    cli_writeln('Apply complete. Second run should report: created=0, patched=0.');
}
exit(0);
