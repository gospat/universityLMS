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
 * Extends local navigation with a future academics entry point.
 *
 * @param global_navigation $navigation
 */
function local_ulms_academics_extend_navigation(global_navigation $navigation): void {
    /** @var mixed $systemcontext */ $systemcontext = context_system::instance();
    $routingservice = new \local_ulms_auth\local\service\landing_page_service();

    if (!has_capability('local/ulms_academics:viewstructure', $systemcontext)) {
        return;
    }

    $navigation->add(
        get_string('pluginname', 'local_ulms_academics'),
        $routingservice->get_url_for_route('management.academics')
    );
}

/**
 * Enrol one user (as student) into all courses mapped to the given programme
 * for the given semester (or current active semester if omitted).
 *
 * Idempotent: silently skips courses user is already enrolled in.
 *
 * @param int $userid
 * @param int $programmeid
 * @param int|null $semesterid if null, uses the first iscurrent semester.
 * @return int number of course enrolments performed (excluding already-enrolled skips).
 */
function local_ulms_academics_enrol_user_into_programme_courses(int $userid, int $programmeid, ?int $semesterid = null): int {
    global $DB;
    if ($userid <= 0 || $programmeid <= 0) {
        return 0;
    }
    if ($semesterid === null) {
        $semesterid = (int)$DB->get_field_select(
            'local_ulms_semesters',
            'COALESCE(MIN(id), 0)',
            "iscurrent = 1 AND status = 'active'",
            []
        );
    }
    if ($semesterid <= 0) {
        $where = "programmeid = :pid AND status = 'active'";
        $params = ['pid' => $programmeid];
    } else {
        $where = "programmeid = :pid AND semesterid = :sid AND status = 'active'";
        $params = ['pid' => $programmeid, 'sid' => $semesterid];
    }
    $mappings = $DB->get_records_select('local_ulms_programme_courses', $where, $params, 'id ASC', 'id, moodlecourseid');
    if (empty($mappings)) {
        return 0;
    }
    try {
        $manualplugin = enrol_get_plugin('manual');
    } catch (\Throwable $e) {
        $manualplugin = null;
    }
    if (!$manualplugin) {
        return 0;
    }
    $done = 0;
    $studentroleid = (int)$DB->get_field_select('role', 'id', "shortname = 'student'", [], IGNORE_MISSING);
    if ($studentroleid <= 0) {
        $studentroleid = 5;
    }
    foreach ($mappings as $map) {
        $courseid = (int)$map->moodlecourseid;
        if ($courseid <= 0) {
            continue;
        }
        try {
            /** @var mixed $coursectx */ $coursectx = \context_course::instance($courseid, IGNORE_MISSING);
        } catch (\Throwable) {
            $coursectx = null;
        }
        if (!$coursectx) {
            continue;
        }
        if (is_enrolled($coursectx, $userid, 'student', true)) {
            continue;
        }
        $course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
        if (!$course) {
            continue;
        }
        $instances = enrol_get_instances($courseid, false);
        $manualinstance = null;
        foreach ($instances as $inst) {
            if ($inst->enrol === 'manual') {
                $manualinstance = $inst;
                break;
            }
        }
        if (!$manualinstance) {
            try {
                $fields = [
                    'status' => ENROL_INSTANCE_ENABLED,
                    'enrolperiod' => 0,
                    'customint1' => 0,
                    'customint2' => 0,
                    'customint3' => 0,
                    'customint4' => 0,
                    'customint5' => 0,
                    'customint6' => 0,
                    'customint7' => 0,
                ];
                $instanceid = $manualplugin->add_instance($course, $fields);
                $manualinstance = $DB->get_record('enrol', ['id' => $instanceid], '*', IGNORE_MISSING);
            } catch (\Throwable $e) {
                continue;
            }
            if (!$manualinstance) {
                continue;
            }
        }
        try {
            $timestart = time();
            $timeend = 0;
            $manualplugin->enrol_user($manualinstance, $userid, $studentroleid, $timestart, $timeend);
            $done++;
        } catch (\Throwable $e) {
            if (function_exists('local_ulms_dashboard_log_operational_error')) {
                local_ulms_dashboard_log_operational_error($e, 'local_ulms_academics::enrol_programme_course', [
                    'userid' => $userid,
                    'programmeid' => $programmeid,
                    'semesterid' => $semesterid,
                    'courseid' => $courseid,
                ]);
            }
        }
    }
    return $done;
}

/**
 * Retroactively enrols ALL students that have a specific programmeid in their
 * user profile into a SINGLE programme-course mapping (used when a new mapping
 * is added after students already provisioned).
 *
 * @param int $programmeid
 * @param int $courseid
 * @param int|null $semesterid
 * @return int number of student enrolments performed.
 */
function local_ulms_academics_retro_enrol_programme_course(int $programmeid, int $courseid, ?int $semesterid = null): int {
    global $DB;
    if ($programmeid <= 0 || $courseid <= 0) {
        return 0;
    }
    $studentids = $DB->get_records_select_menu(
        'local_ulms_user_profile',
        'programmeid = :pid',
        ['pid' => $programmeid],
        'id ASC',
        'id, userid'
    );
    if (empty($studentids)) {
        return 0;
    }
    $done = 0;
    foreach (array_values($studentids) as $uid) {
        $cidsingle = (int)$courseid;
        $pid = $programmeid;
        try {
            $manualplugin = enrol_get_plugin('manual');
        } catch (\Throwable $e) {
            $manualplugin = null;
        }
        if (!$manualplugin) {
            break;
        }
        $studentroleid = (int)$DB->get_field_select('role', 'id', "shortname = 'student'", [], IGNORE_MISSING);
        if ($studentroleid <= 0) {
            $studentroleid = 5;
        }
        try {
            /** @var mixed $coursectx */ $coursectx = \context_course::instance($cidsingle, IGNORE_MISSING);
        } catch (\Throwable) {
            $coursectx = null;
        }
        if (!$coursectx) {
            continue;
        }
        if (is_enrolled($coursectx, (int)$uid, 'student', true)) {
            continue;
        }
        $course = $DB->get_record('course', ['id' => $cidsingle], '*', IGNORE_MISSING);
        if (!$course) {
            continue;
        }
        $instances = enrol_get_instances($cidsingle, false);
        $manualinstance = null;
        foreach ($instances as $inst) {
            if ($inst->enrol === 'manual') {
                $manualinstance = $inst;
                break;
            }
        }
        if (!$manualinstance) {
            try {
                $instanceid = $manualplugin->add_instance($course, [
                    'status' => ENROL_INSTANCE_ENABLED,
                    'enrolperiod' => 0,
                ]);
                $manualinstance = $DB->get_record('enrol', ['id' => $instanceid], '*', IGNORE_MISSING);
            } catch (\Throwable $e) {
                continue;
            }
            if (!$manualinstance) {
                continue;
            }
        }
        try {
            $manualplugin->enrol_user($manualinstance, (int)$uid, $studentroleid, time(), 0);
            $done++;
        } catch (\Throwable $e) {
            if (function_exists('local_ulms_dashboard_log_operational_error')) {
                local_ulms_dashboard_log_operational_error($e, 'local_ulms_academics::retro_enrol_programme_course', [
                    'userid' => (int)$uid,
                    'programmeid' => $pid,
                    'courseid' => $cidsingle,
                    'semesterid' => $semesterid,
                ]);
            }
        }
    }
    return $done;
}
