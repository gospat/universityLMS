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

namespace local_ulms_dashboard\local\service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/lib/enrollib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/user/lib.php');

/**
 * Provides starter dashboard metadata for ULMS role-based portals.
 */
class dashboard_service {
    /** @var string */
    private const UNIFIED_ADMIN_CAPABILITY = 'local/ulms_dashboard:viewadmindashboard';

    /**
     * Returns the shared ULMS routing service.
     *
     * @return \local_ulms_auth\local\service\landing_page_service
     */
    private function get_routing_service(): \local_ulms_auth\local\service\landing_page_service {
        return new \local_ulms_auth\local\service\landing_page_service();
    }

    /**
     * Returns starter widget keys for a given role shortname.
     *
     * @param string $roleshortname
     * @return string[]
     */
    public function get_default_widgets_for_role(string $roleshortname): array {
        return match ($roleshortname) {
            'siteadmin' => ['system_health', 'alerts', 'integrations'],
            'manager' => ['system_health', 'faculty_summary', 'alerts'],
            'editingteacher', 'teacher' => ['my_courses', 'grading_queue', 'notifications'],
            'student', 'user' => ['my_courses', 'deadlines', 'notifications'],
            default => ['notifications'],
        };
    }

    /**
     * Returns the current user's highest priority role shortname.
     *
     * @return string
     */
    public function get_current_user_role_shortname(): string {
        global $USER;

        return $this->resolve_role_shortname_for_user($USER);
    }

    /**
     * Returns a lightweight dashboard snapshot for the current user.
     *
     * @return array
     */
    public function get_current_user_snapshot(): array {
        global $USER;

        $roleshortname = $this->get_current_user_role_shortname();
        $courses = enrol_get_my_courses(['id', 'fullname'], 'fullname ASC', 5);
        $courseids = [];

        $courselist = [];
        foreach ($courses as $course) {
            $courseids[] = (int)$course->id;
            $courselist[] = [
                'id' => $course->id,
                'fullname' => $course->fullname,
                'url' => new \moodle_url('/course/view.php', ['id' => $course->id]),
            ];
        }

        $completion = $this->get_completion_summary($courseids, (int)$USER->id);

        return [
            'fullname' => fullname($USER),
            'roleshortname' => $roleshortname,
            'widgetkeys' => $this->get_default_widgets_for_role($roleshortname),
            'courseids' => $courseids,
            'coursecount' => count($courselist),
            'courses' => $courselist,
            'notificationcount' => $this->get_unread_notification_count((int)$USER->id),
            'completion' => $completion,
            'deadlines' => $this->get_upcoming_assignment_deadlines($courseids, 5),
            'events' => $this->get_upcoming_calendar_events($courseids, (int)$USER->id, 5),
            'calendarurl' => new \moodle_url('/calendar/view.php', ['view' => 'upcoming']),
        ];
    }

    /**
     * Returns the enrolled / allocated course IDs for a user in a portal role.
     *
     * @param int $userid
     * @param string $role one of 'student' or 'lecturer'
     * @return int[]
     */
    private function resolve_courseids_for_user(int $userid, string $role): array {
        $courseids = [];
        try {
            if (!class_exists(\local_ulms_kortext\local\service\adoption_service::class)) {
                require_once($GLOBALS['CFG']->dirroot . '/local/ulms_kortext/classes/local/service/adoption_service.php');
            }
            $svc = new \local_ulms_kortext\local\service\adoption_service();
            if (method_exists($svc, 'resolve_courseids_for_user')) {
                $result = $svc->resolve_courseids_for_user($userid, $role);
                if (is_array($result)) {
                    foreach ($result as $cid) {
                        $courseids[] = (int)$cid;
                    }
                }
            }
        } catch (\Throwable) {
            $courseids = [];
        }
        if (count($courseids) === 0) {
            foreach (enrol_get_all_users_courses($userid, false, ['id']) as $rec) {
                $courseids[] = (int)($rec->id ?? 0);
            }
            $courseids = array_values(array_unique(array_filter($courseids)));
        }
        return $courseids;
    }

    /**
     * Builds a course pills list suitable for profile card rendering.
     *
     * @param int[] $courseids
     * @return array<int, array{id:int, shortname:string, fullname:string, url:string, badge:string}>
     */
    private function build_course_pills(array $courseids): array {
        global $CFG;
        $out = [];
        $courseids = array_values(array_filter(array_map('intval', $courseids)));
        if (count($courseids) === 0) {
            return $out;
        }
        [$in, $params] = $GLOBALS['DB']->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
        $rs = $GLOBALS['DB']->get_records_sql("SELECT id, shortname, fullname FROM {course} WHERE id {$in}", $params);
        $i = 0;
        $palette = ['primary', 'secondary', 'success', 'info', 'warning'];
        foreach ($rs as $c) {
            $out[] = [
                'id' => (int)$c->id,
                'shortname' => (string)$c->shortname,
                'fullname' => (string)$c->fullname,
                'url' => (string)(new \moodle_url('/course/view.php', ['id' => (int)$c->id])),
                'badge' => $palette[$i % count($palette)],
            ];
            $i++;
        }
        return $out;
    }

    /**
     * Returns a user's academic profile based on ULMS user_profile / hierarchy tables.
     *
     * @param int $userid
     * @param string $role 'student' or 'lecturer'
     * @return array
     */
    public function get_user_academic_profile(int $userid, string $role): array {
        global $DB;
        $out = [
            'available' => false,
            'facultyid' => 0,
            'facultyname' => '',
            'departmentid' => 0,
            'departmentname' => '',
            'programmeid' => 0,
            'programmename' => '',
            'programme_code' => '',
            'staffid' => '',
            'levelid' => 0,
            'levelname' => '',
            'levelcode' => '',
        ];
        if (!$DB->get_manager()->table_exists('local_ulms_user_profile')) {
            return $out;
        }
        $profile = $DB->get_record('local_ulms_user_profile', ['userid' => $userid]);
        if (!$profile) {
            return $out;
        }
        $out['available'] = true;
        $out['staffid'] = (string)($profile->staff_id ?? '');
        $facid = (int)($profile->facultyid ?? 0);
        $deptid = (int)($profile->departmentid ?? 0);
        $progid = (int)($profile->programmeid ?? 0);
        $lvlid  = (int)($profile->studylevel ?? 0);
        if ($facid > 0 && $DB->get_manager()->table_exists('local_ulms_faculties')) {
            $fac = $DB->get_record('local_ulms_faculties', ['id' => $facid], 'id, name, code');
            if ($fac) {
                $out['facultyid'] = (int)$fac->id;
                $out['facultyname'] = trim((string)($fac->code ? ($fac->code . ' — ') : '') . (string)$fac->name);
            }
        }
        if ($deptid > 0 && $DB->get_manager()->table_exists('local_ulms_departments')) {
            $dep = $DB->get_record('local_ulms_departments', ['id' => $deptid], 'id, name, code');
            if ($dep) {
                $out['departmentid'] = (int)$dep->id;
                $out['departmentname'] = trim((string)($dep->code ? ($dep->code . ' — ') : '') . (string)$dep->name);
            }
        }
        if ($progid > 0 && $DB->get_manager()->table_exists('local_ulms_programmes')) {
            $prog = $DB->get_record('local_ulms_programmes', ['id' => $progid], 'id, name, code');
            if ($prog) {
                $out['programmeid'] = (int)$prog->id;
                $out['programmename'] = (string)$prog->name;
                $out['programme_code'] = (string)($prog->code ?? '');
            }
        }
        if ($role === 'student' && $lvlid > 0 && $DB->get_manager()->table_exists('local_ulms_levels')) {
            $lv = $DB->get_record('local_ulms_levels', ['id' => $lvlid], 'id, name, code');
            if (!$lv && is_numeric($profile->studylevel)) {
                $lv = $DB->get_record('local_ulms_levels', ['code' => (string)$profile->studylevel], 'id, name, code');
            }
            if (!$lv) {
                $lv = $DB->get_record('local_ulms_levels', ['code' => (string)$lvlid], 'id, name, code');
            }
            if ($lv) {
                $out['levelid'] = (int)$lv->id;
                $out['levelname'] = (string)$lv->name;
                $out['levelcode'] = (string)($lv->code ?? '');
            }
        }
        return $out;
    }

    /**
     * Returns the canonical dashboard key for the current user.
     *
     * @return string
     */
    public function get_dashboard_key_for_current_user(): string {
        $routingservice = new \local_ulms_auth\local\service\landing_page_service();
        return $routingservice->get_dashboard_key_for_role_shortname($this->get_current_user_role_shortname());
    }

    /**
     * Returns whether the current user belongs to the supplied dashboard family.
     *
     * @param string $dashboardkey
     * @return bool
     */
    public function current_user_can_access_dashboard(string $dashboardkey): bool {
        return $this->get_dashboard_key_for_current_user() === $dashboardkey;
    }

    /**
     * Returns whether the current user is assigned to the admin portal.
     *
     * @return bool
     */
    public function current_user_is_admin_portal_user(): bool {
        return $this->current_user_can_access_dashboard('admin');
    }

    /**
     * Returns whether the current user is assigned to the super admin portal.
     *
     * @return bool
     */
    public function current_user_is_super_admin_portal_user(): bool {
        return $this->current_user_can_access_dashboard('superadmin');
    }

    /**
     * Returns whether the current user has admin-management permissions.
     *
     * Super admins inherit these capabilities through Moodle's capability
     * layer, but they do not become admin-portal users.
     *
     * @return bool
     */
    public function current_user_has_admin_permissions(): bool {
        /** @var \context $systemcontext */
        $systemcontext = \context::instance_by_id(SYSCONTEXTID);
        return \has_capability(self::UNIFIED_ADMIN_CAPABILITY, $systemcontext);
    }

    /**
     * Requires admin-management permissions.
     *
     * @return void
     */
    public function require_admin_permissions(): void {
        /** @var \context $systemcontext */
        $systemcontext = \context::instance_by_id(SYSCONTEXTID);
        \require_capability(self::UNIFIED_ADMIN_CAPABILITY, $systemcontext);
    }

    /**
     * Enforces access to admin functionality without changing portal identity.
     *
     * Admin users remain in the admin portal, while super admins may access
     * the same server-side functionality through inherited admin capabilities.
     *
     * @return void
     */
    public function enforce_admin_feature_access(): void {
        if ($this->current_user_has_admin_permissions()) {
            return;
        }

        $routingservice = new \local_ulms_auth\local\service\landing_page_service();
        $routingservice->redirect_to_current_user_dashboard(
            get_string('dashboardaccessredirect', 'local_ulms_dashboard'),
            \core\output\notification::NOTIFY_WARNING,
            302
        );
    }

    /**
     * Resolves the highest-priority effective role for a user across system and course contexts.
     *
     * @param \stdClass $user
     * @return string
     */
    private function resolve_role_shortname_for_user(\stdClass $user): string {
        if (is_siteadmin($user)) {
            return 'siteadmin';
        }

        $roleshortnames = $this->get_assigned_role_shortnames_for_user((int)$user->id);
        $priority = ['manager', 'coursecreator', 'ictadmin', 'facultyadmin', 'departmentadmin', 'editingteacher', 'teacher', 'student', 'user'];

        foreach ($priority as $shortname) {
            if (in_array($shortname, $roleshortnames, true)) {
                return $shortname;
            }
        }

        return 'user';
    }

    /**
     * Returns whether the current user has any supplied system capability.
     *
     * @param string[] $capabilities
     * @return bool
     */
    private function user_has_any_system_capability(array $capabilities): bool {
        if (empty($capabilities)) {
            return true;
        }

        /** @var \context $context */
        $context = \context::instance_by_id(SYSCONTEXTID);
        foreach ($capabilities as $capability) {
            if (\has_capability($capability, $context)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns distinct assigned role shortnames in supported Moodle contexts.
     *
     * @param int $userid
     * @return string[]
     */
    private function get_assigned_role_shortnames_for_user(int $userid): array {
        global $DB;

        $records = $DB->get_records_sql(
            "SELECT DISTINCT r.shortname
               FROM {role_assignments} ra
               JOIN {role} r ON r.id = ra.roleid
               JOIN {context} ctx ON ctx.id = ra.contextid
              WHERE ra.userid = :userid
                AND (
                    ctx.contextlevel = :systemcontext
                    OR ctx.contextlevel = :coursecategorycontext
                    OR ctx.contextlevel = :coursecontext
                )",
            [
                'userid' => $userid,
                'systemcontext' => CONTEXT_SYSTEM,
                'coursecategorycontext' => CONTEXT_COURSECAT,
                'coursecontext' => CONTEXT_COURSE,
            ]
        );

        return array_values(array_map(static fn($record): string => (string)$record->shortname, $records));
    }

    /**
     * Redirects users to their authorised dashboard if they open the wrong route.
     *
     * @param string $expecteddashboard
     * @return void
     */
    public function enforce_dashboard_access(string $expecteddashboard): void {
        if ($this->current_user_can_access_dashboard($expecteddashboard)) {
            return;
        }

        $routingservice = new \local_ulms_auth\local\service\landing_page_service();
        $routingservice->redirect_to_current_user_dashboard(
            get_string('dashboardaccessredirect', 'local_ulms_dashboard'),
            \core\output\notification::NOTIFY_WARNING,
            302
        );
    }

    /**
     * Returns exam summary KPIs for a student dashboard.
     *
     * @param int $userid
     * @return array{0:int,1:int,2:int} [upcoming, open_now, graded]
     */
    private function get_student_exam_summary(int $userid): array {
        try {
            if (!class_exists(\local_ulms_exam\local\service\exam_service::class)) {
                return [0, 0, 0];
            }
            $svc = \local_ulms_exam\local\service\exam_service::instance();
            $result = $svc->list_student_exams($userid);
            $rows = $result['rows'] ?? [];
        } catch (\Throwable $e) { return [0, 0, 0]; }
        $now = time();
        $upcoming = 0;
        $open = 0;
        $graded = 0;
        foreach ($rows as $e) {
            $enrolled = !empty($e->is_course_enrolled);
            $status = (string)($e->status ?? '');
            $start = (int)($e->start_ts ?? 0);
            $end = (int)($e->end_ts ?? 0);
            $substatus = (string)($e->submissionstatus ?? '');
            if (!$enrolled) continue;
            if ($status === \local_ulms_exam\local\service\exam_service::STATUS_PUBLISHED) {
                if ($now < $start) $upcoming++;
                elseif ($now >= $start && $now <= $end) $open++;
            }
            if (in_array($substatus, ['graded', 'submitted'], true) && !empty($e->submissionid)) {
                $graded++;
            }
        }
        return [$upcoming, $open, $graded];
    }

    /**
     * Returns exam summary KPIs for a lecturer dashboard.
     *
     * @param int $userid
     * @return array{0:int,1:int,2:int} [drafts, in_progress_window, closed_pending_grade]
     */
    private function get_lecturer_exam_summary(int $userid): array {
        global $DB;
        try {
            if (!class_exists(\local_ulms_exam\local\service\exam_service::class)) {
                return [0, 0, 0];
            }
            $svc = \local_ulms_exam\local\service\exam_service::instance();
            $scope = $svc->get_programme_course_options_for_lecturer();
            $courseids = array_values(array_filter(array_map('intval', array_keys($scope['courses'] ?? []))));
        } catch (\Throwable $e) { return [0, 0, 0]; }
        if (empty($courseids)) return [0, 0, 0];
        [$insql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'courseid', true);
        $now = time();
        try {
            $drafts = (int)$DB->count_records_select('local_ulms_exams',
                "courseid {$insql} AND status = :dstatus",
                array_merge($courseparams, ['dstatus' => \local_ulms_exam\local\service\exam_service::STATUS_DRAFT])
            );
            $inprogress = (int)$DB->count_records_select('local_ulms_exams',
                "courseid {$insql} AND status = :pstatus AND start_ts <= :now1 AND end_ts >= :now2",
                array_merge($courseparams, ['pstatus' => \local_ulms_exam\local\service\exam_service::STATUS_PUBLISHED, 'now1' => $now, 'now2' => $now])
            );
            $closedpending = (int)$DB->count_records_select('local_ulms_exams',
                "courseid {$insql} AND (status = :pstatus2 OR status = :cstatus) AND end_ts + 60 < :now3 AND status != :gstatus",
                array_merge($courseparams, [
                    'pstatus2' => \local_ulms_exam\local\service\exam_service::STATUS_PUBLISHED,
                    'cstatus' => \local_ulms_exam\local\service\exam_service::STATUS_CLOSED,
                    'now3' => $now,
                    'gstatus' => \local_ulms_exam\local\service\exam_service::STATUS_GRADED,
                ])
            );
        } catch (\Throwable $e) { return [0, 0, 0]; }
        return [$drafts, $inprogress, $closedpending];
    }

    /**
     * Returns student dashboard data.
     *
     * @return array
     */
    public function get_student_dashboard_data(): array {
        global $USER;

        $snapshot = $this->get_current_user_snapshot();
        $currentperiod = $this->get_current_academic_period_label();
        $routingservice = $this->get_routing_service();
        [$upcomingexams, $openexams, $gradedexams] = $this->get_student_exam_summary((int)$USER->id);
        $academicprofile = $this->get_user_academic_profile((int)$USER->id, 'student');
        $cids = $this->resolve_courseids_for_user((int)$USER->id, 'student');
        $coursepills = $this->build_course_pills($cids);

        return [
            'title' => \get_string('studentdashboard', 'local_ulms_dashboard'),
            'intro' => \get_string('studentdashboarddesc', 'local_ulms_dashboard'),
            'focusheading' => \get_string('studentdashboardfocusheading', 'local_ulms_dashboard', fullname($USER)),
            'focusintro' => \get_string('studentdashboardfocusintro', 'local_ulms_dashboard'),
            'currentperiod' => $currentperiod,
            'academic_profile' => $academicprofile,
            'course_pills' => $coursepills,
            'summary' => [
                ['label' => \get_string('coursecountsummary', 'local_ulms_dashboard'), 'value' => $snapshot['coursecount']],
                ['label' => \get_string('upcomingdeadlinessummary', 'local_ulms_dashboard'), 'value' => count($snapshot['deadlines'])],
                ['label' => \get_string('completionsummary', 'local_ulms_dashboard'), 'value' => $snapshot['completion']['percent'] . '%'],
                ['label' => \get_string('upcomingexamscardtitle', 'local_ulms_exam'), 'value' => $upcomingexams],
                ['label' => \get_string('openexamscardtitle', 'local_ulms_exam'), 'value' => $openexams],
                ['label' => \get_string('gradedresultscardtitle', 'local_ulms_exam'), 'value' => $gradedexams],
            ],
            'links' => [
                [
                    'label' => \get_string('mycourseslink', 'local_ulms_dashboard'),
                    'description' => \get_string('studentmycourseslinkdesc', 'local_ulms_dashboard'),
                    'url' => $routingservice->get_url_for_route('student.courses'),
                ],
                [
                    'label' => \get_string('studentnavassignments', 'local_ulms_dashboard'),
                    'description' => \get_string('studentassignmentsdesc', 'local_ulms_dashboard'),
                    'url' => $routingservice->get_url_for_route('student.assignments'),
                ],
                [
                    'label' => \get_string('viewgradeslink', 'local_ulms_dashboard'),
                    'description' => \get_string('viewgradeslinkdesc', 'local_ulms_dashboard'),
                    'url' => $routingservice->get_url_for_route('student.grades'),
                ],
                [
                    'label' => \get_string('studentnavannouncements', 'local_ulms_dashboard'),
                    'description' => \get_string('studentannouncementsdesc', 'local_ulms_dashboard'),
                    'url' => $routingservice->get_url_for_route('student.announcements'),
                ],
            ],
            'completion' => $snapshot['completion'],
            'coursesheading' => \get_string('studentcoursesheading', 'local_ulms_dashboard'),
            'quickactionsheading' => \get_string('studentquickactionsheading', 'local_ulms_dashboard'),
            'eventsheading' => \get_string('studenteventsheading', 'local_ulms_dashboard'),
            'courses' => $snapshot['courses'],
            'deadlines' => $snapshot['deadlines'],
            'events' => $snapshot['events'],
            'calendarurl' => $snapshot['calendarurl'],
        ];
    }

    /**
     * Returns lecturer dashboard data.
     *
     * @return array
     */
    public function get_lecturer_dashboard_data(): array {
        global $USER;

        $snapshot = $this->get_current_user_snapshot();
        $gradingqueue = $this->get_pending_grading_queue($snapshot['courseids'], 5);
        $currentperiod = $this->get_current_academic_period_label();
        $routingservice = $this->get_routing_service();
        [$draftexams, $openwindowexams, $pendinggradeexams] = $this->get_lecturer_exam_summary((int)$USER->id);
        $academicprofile = $this->get_user_academic_profile((int)$USER->id, 'lecturer');
        $cids = $this->resolve_courseids_for_user((int)$USER->id, 'lecturer');
        $coursepills = $this->build_course_pills($cids);

        return [
            'title' => \get_string('lecturerdashboard', 'local_ulms_dashboard'),
            'intro' => \get_string('lecturerdashboarddesc', 'local_ulms_dashboard'),
            'focusheading' => \get_string('lecturerdashboardfocusheading', 'local_ulms_dashboard', fullname($USER)),
            'focusintro' => \get_string('lecturerdashboardfocusintro', 'local_ulms_dashboard'),
            'currentperiod' => $currentperiod,
            'academic_profile' => $academicprofile,
            'course_pills' => $coursepills,
            'summary' => [
                ['label' => \get_string('allocatedcoursessummary', 'local_ulms_dashboard'), 'value' => $snapshot['coursecount']],
                ['label' => \get_string('gradingqueuesummary', 'local_ulms_dashboard'), 'value' => $this->get_pending_grading_count($gradingqueue)],
                ['label' => \get_string('upcomingeventssummary', 'local_ulms_dashboard'), 'value' => count($snapshot['events'])],
                ['label' => \get_string('examstatusdraft', 'local_ulms_exam'), 'value' => $draftexams],
                ['label' => \get_string('examsopennowlecturertitle', 'local_ulms_exam'), 'value' => $openwindowexams],
                ['label' => \get_string('examsgradewaitinglecturertitle', 'local_ulms_exam'), 'value' => $pendinggradeexams],
            ],
            'links' => [
                [
                    'label' => \get_string('mycourseslink', 'local_ulms_dashboard'),
                    'description' => \get_string('lecturermycourseslinkdesc', 'local_ulms_dashboard'),
                    'url' => $routingservice->get_url_for_route('lecturer.courses'),
                ],
                [
                    'label' => \get_string('lecturernavassignments', 'local_ulms_dashboard'),
                    'description' => \get_string('lecturerassignmentsdesc', 'local_ulms_dashboard'),
                    'url' => $routingservice->get_url_for_route('lecturer.assignments'),
                ],
                [
                    'label' => \get_string('lecturernavstudents', 'local_ulms_dashboard'),
                    'description' => \get_string('lecturerstudentsdesc', 'local_ulms_dashboard'),
                    'url' => $routingservice->get_url_for_route('lecturer.students'),
                ],
                [
                    'label' => \get_string('lecturernavannouncements', 'local_ulms_dashboard'),
                    'description' => \get_string('lecturerannouncementsdesc', 'local_ulms_dashboard'),
                    'url' => $routingservice->get_url_for_route('lecturer.announcements'),
                ],
            ],
            'coursesheading' => \get_string('lecturercoursesheading', 'local_ulms_dashboard'),
            'quickactionsheading' => \get_string('lecturerquickactionsheading', 'local_ulms_dashboard'),
            'gradingqueueheading' => \get_string('gradingqueueheading', 'local_ulms_dashboard'),
            'eventsheading' => \get_string('teachingscheduleheading', 'local_ulms_dashboard'),
            'completion' => $snapshot['completion'],
            'courses' => $snapshot['courses'],
            'gradingqueue' => $gradingqueue,
            'events' => $snapshot['events'],
            'calendarurl' => $snapshot['calendarurl'],
        ];
    }

    /**
     * Returns admin dashboard data.
     *
     * @return array<string, mixed>
     */
    public function get_admin_dashboard_data(): array {
        global $DB, $USER;

        $analytics = $this->get_platform_analytics_data();
        $snapshot = $this->get_current_user_snapshot();
        $routingservice = $this->get_routing_service();
        $adminrole = $snapshot['roleshortname'];
        $studentcount = $this->count_users_with_roles(['student']);
        $lecturercount = $this->count_users_with_roles(['editingteacher', 'teacher']);
        $admincount = $this->count_users_with_roles(
            ['manager', 'coursecreator', 'ictadmin', 'facultyadmin', 'departmentadmin'],
            true
        );

        $systemcontrol = array_values(array_filter([
            $this->build_admin_action_card(
                \get_string('admindashboardaddstudent', 'local_ulms_dashboard'),
                \get_string('admindashboardaddstudentdesc', 'local_ulms_dashboard'),
                $routingservice->get_url_for_route('management.provisioning', ['singletargetrole' => 'student']),
                [self::UNIFIED_ADMIN_CAPABILITY]
            ),
            $this->build_admin_action_card(
                \get_string('admindashboardaddlecturer', 'local_ulms_dashboard'),
                \get_string('admindashboardaddlecturerdesc', 'local_ulms_dashboard'),
                $routingservice->get_url_for_route('management.provisioning', ['singletargetrole' => 'lecturer']),
                [self::UNIFIED_ADMIN_CAPABILITY]
            ),
            $this->build_admin_action_card(
                \get_string('adminnavbulkupload', 'local_ulms_dashboard'),
                \get_string('adminbulkuploaddesc', 'local_ulms_dashboard'),
                $routingservice->get_url_for_route('management.bulkupload'),
                [self::UNIFIED_ADMIN_CAPABILITY]
            ),
            $this->build_admin_action_card(
                \get_string('adminusermanagementlink', 'local_ulms_dashboard'),
                \get_string('adminusermanagementlinkdesc', 'local_ulms_dashboard'),
                $routingservice->get_url_for_route('management.users'),
                ['moodle/site:config', 'moodle/user:update']
            ),
        ]));

        $oversight = array_values(array_filter([
            $this->build_admin_action_card(
                \get_string('manageacademicstructurelink', 'local_ulms_dashboard'),
                \get_string('adminacademicstructurelinkdesc', 'local_ulms_dashboard'),
                $routingservice->get_url_for_route('management.academics')
            ),
            $this->build_admin_action_card(
                \get_string('viewreportslink', 'local_ulms_dashboard'),
                \get_string('adminreportslinkdesc', 'local_ulms_dashboard'),
                $routingservice->get_url_for_route('management.academicsreports')
            ),
            $this->build_admin_action_card(
                \get_string('coursecataloglink', 'local_ulms_dashboard'),
                \get_string('admincoursecataloglinkdesc', 'local_ulms_dashboard'),
                $routingservice->get_url_for_route('management.courses')
            ),
        ]));

        $sections = array_values(array_filter([
            !empty($systemcontrol) ? [
                'heading' => \get_string('adminsystemcontrolheading', 'local_ulms_dashboard'),
                'intro' => \get_string('adminsystemcontrolintro', 'local_ulms_dashboard'),
                'cards' => $systemcontrol,
            ] : null,
            !empty($oversight) ? [
                'heading' => \get_string('adminacademicoversightheading', 'local_ulms_dashboard'),
                'intro' => \get_string('adminacademicoversightintro', 'local_ulms_dashboard'),
                'cards' => $oversight,
            ] : null,
        ]));

        $panels = [
            [
                'heading' => \get_string('facultybreakdownheading', 'local_ulms_dashboard'),
                'intro' => \get_string('adminfacultyoverviewintro', 'local_ulms_dashboard'),
                'items' => $analytics['facultybreakdown'],
                'emptyheading' => \get_string('nobreakdowndata', 'local_ulms_dashboard'),
                'emptydesc' => \get_string('nobreakdowndata', 'local_ulms_dashboard'),
            ],
            [
                'heading' => \get_string('departmentbreakdownheading', 'local_ulms_dashboard'),
                'intro' => \get_string('admindepartmentoverviewintro', 'local_ulms_dashboard'),
                'items' => $analytics['departmentbreakdown'],
                'emptyheading' => \get_string('nobreakdowndata', 'local_ulms_dashboard'),
                'emptydesc' => \get_string('nobreakdowndata', 'local_ulms_dashboard'),
            ],
        ];

        if (class_exists(\local_ulms_dashboard\local\service\user_provisioning_service::class)
            && $DB->get_manager()->table_exists(new \xmldb_table('local_ulms_user_provisioning_log'))) {
            $provisioningservice = new \local_ulms_dashboard\local\service\user_provisioning_service();
            $recentactivity = $provisioningservice->get_recent_activity(5);
            $panels[] = [
                'heading' => \get_string('provisioningactivityheading', 'local_ulms_dashboard'),
                'intro' => \get_string('provisioningactivityintro', 'local_ulms_dashboard'),
                'items' => array_map(static function(array $item): array {
                    return [
                        'label' => $item['label'],
                        'subtitle' => $item['subtitle'] . ' - ' . $item['time'],
                    ];
                }, $recentactivity),
                'emptyheading' => \get_string('provisioningactivityemptyheading', 'local_ulms_dashboard'),
                'emptydesc' => \get_string('provisioningactivityemptydesc', 'local_ulms_dashboard'),
            ];
        }

        return [
            'title' => \get_string('admindashboard', 'local_ulms_dashboard'),
            'intro' => \get_string('admindashboarddesc', 'local_ulms_dashboard'),
            'focusheading' => \get_string('admindashboardfocusheading', 'local_ulms_dashboard', fullname($USER)),
            'focusintro' => \get_string('admindashboardfocusintro', 'local_ulms_dashboard'),
            'currentperiod' => $this->get_current_academic_period_label(),
            'summary' => [
                ['label' => \get_string('studentssummary', 'local_ulms_dashboard'), 'value' => $studentcount],
                ['label' => \get_string('lecturerssummary', 'local_ulms_dashboard'), 'value' => $lecturercount],
                ['label' => \get_string('administratorssummary', 'local_ulms_dashboard'), 'value' => $admincount],
                ['label' => \get_string('activeuserssummary', 'local_ulms_dashboard'), 'value' => $analytics['summary'][1]['value']],
                ['label' => \get_string('totalcoursessummary', 'local_ulms_dashboard'), 'value' => $DB->count_records_select('course', 'id > :sitecourse', ['sitecourse' => 1])],
                ['label' => \get_string('rolelabel', 'local_ulms_dashboard'), 'value' => $adminrole],
            ],
            'analyticscards' => $analytics['cards'],
            'sections' => $sections,
            'panels' => $panels,
        ];
    }

    /**
     * Builds a unified admin action card when the user can access it.
     *
     * @param string $label
     * @param string $description
     * @param \moodle_url $url
     * @param string[] $capabilities
     * @return array<string, mixed>|null
     */
    private function build_admin_action_card(
        string $label,
        string $description,
        \moodle_url $url,
        array $capabilities = []
    ): ?array {
        if (!$this->user_has_any_system_capability($capabilities)) {
            return null;
        }

        return [
            'label' => $label,
            'description' => $description,
            'url' => $url,
        ];
    }

    /**
     * Returns platform-wide analytics data for dashboard reporting.
     *
     * @return array
     */
    public function get_platform_analytics_data(int $days = 30): array {
        global $DB;

        $days = $this->normalise_analytics_days($days);
        $cutoff = strtotime("-{$days} days");
        $upcomingwindow = strtotime("+{$days} days");
        $totalusers = (int)$DB->count_records('user', ['deleted' => 0]);
        $activeusers = (int)$DB->count_records_select(
            'user',
            'deleted = 0 AND suspended = 0 AND lastaccess >= :cutoff',
            ['cutoff' => $cutoff]
        );
        $totalcourses = (int)$DB->count_records_select('course', 'id > :sitecourse', ['sitecourse' => 1]);
        $activecourses = (int)$DB->count_records_select(
            'course',
            'id > :sitecourse AND visible = :visible',
            ['sitecourse' => 1, 'visible' => 1]
        );
        $assignments = (int)$DB->count_records('assign');
        $upcomingevents = (int)$DB->count_records_select(
            'event',
            'timestart >= :now AND timestart <= :windowend',
            ['now' => time(), 'windowend' => $upcomingwindow]
        );

        $trackedactivities = (int)$DB->count_records_select(
            'course_modules',
            'completion > :incomplete',
            ['incomplete' => COMPLETION_INCOMPLETE]
        );
        $completedstates = [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS];
        if (defined('COMPLETION_COMPLETE_FAIL')) {
            $completedstates[] = COMPLETION_COMPLETE_FAIL;
        }

        $completedactivities = $this->count_distinct_records_in_or_equal(
            'course_modules_completion',
            'coursemoduleid',
            'completionstate',
            $completedstates
        );
        $completioncoverage = $trackedactivities > 0
            ? (int)floor(($completedactivities / $trackedactivities) * 100)
            : 0;
        $facultybreakdown = $this->get_faculty_breakdown_data();
        $departmentbreakdown = $this->get_department_breakdown_data();

        return [
            'title' => \get_string('analyticsdashboard', 'local_ulms_dashboard'),
            'intro' => \get_string('analyticsdashboarddesc', 'local_ulms_dashboard'),
            'days' => $days,
            'windows' => $this->get_analytics_window_options($days),
            'cards' => [
                [
                    'label' => \get_string('activeuserssummary', 'local_ulms_dashboard'),
                    'value' => $activeusers,
                    'description' => \get_string('analyticsactiveuserscaption', 'local_ulms_dashboard', $days),
                ],
                [
                    'label' => \get_string('activecoursessummary', 'local_ulms_dashboard'),
                    'value' => $activecourses,
                    'description' => \get_string('activecoursessummarydesc', 'local_ulms_dashboard'),
                ],
                [
                    'label' => \get_string('assignmentsummary', 'local_ulms_dashboard'),
                    'value' => $assignments,
                    'description' => \get_string('assignmentsummarydesc', 'local_ulms_dashboard'),
                ],
                [
                    'label' => \get_string('completioncoveragesummary', 'local_ulms_dashboard'),
                    'value' => $completioncoverage . '%',
                    'description' => \get_string('completioncoveragesummarydesc', 'local_ulms_dashboard'),
                ],
            ],
            'summary' => [
                ['label' => \get_string('totaluserssummary', 'local_ulms_dashboard'), 'value' => $totalusers],
                ['label' => \get_string('activeuserssummary', 'local_ulms_dashboard'), 'value' => $activeusers],
                ['label' => \get_string('totalcoursessummary', 'local_ulms_dashboard'), 'value' => $totalcourses],
                ['label' => \get_string('activecoursessummary', 'local_ulms_dashboard'), 'value' => $activecourses],
                ['label' => \get_string('assignmentsummary', 'local_ulms_dashboard'), 'value' => $assignments],
                ['label' => \get_string('upcomingeventssummary', 'local_ulms_dashboard'), 'value' => $upcomingevents],
                ['label' => \get_string('trackedactivitiessummary', 'local_ulms_dashboard'), 'value' => $trackedactivities],
                ['label' => \get_string('completedactivitiessummary', 'local_ulms_dashboard'), 'value' => $completedactivities],
                ['label' => \get_string('completioncoveragesummary', 'local_ulms_dashboard'), 'value' => $completioncoverage . '%'],
            ],
            'engagementchart' => [
                [
                    'label' => \get_string('activeuserssummary', 'local_ulms_dashboard'),
                    'value' => $activeusers,
                    'caption' => \get_string('analyticsactiveuserscaption', 'local_ulms_dashboard', $days),
                    'percent' => $this->calculate_percentage($activeusers, $totalusers),
                ],
                [
                    'label' => \get_string('activecoursessummary', 'local_ulms_dashboard'),
                    'value' => $activecourses,
                    'caption' => \get_string('analyticsvisiblecoursescaption', 'local_ulms_dashboard'),
                    'percent' => $this->calculate_percentage($activecourses, $totalcourses),
                ],
                [
                    'label' => \get_string('completioncoveragesummary', 'local_ulms_dashboard'),
                    'value' => $completioncoverage . '%',
                    'caption' => \get_string('analyticscompletioncaption', 'local_ulms_dashboard'),
                    'percent' => $completioncoverage,
                ],
            ],
            'facultybreakdown' => $facultybreakdown,
            'departmentbreakdown' => $departmentbreakdown,
        ];
    }

    /**
     * Returns unread notification count.
     *
     * @param int $userid
     * @return int
     */
    private function get_unread_notification_count(int $userid): int {
        global $DB;

        return (int)$DB->count_records_sql(
            "SELECT COUNT(n.id)
               FROM {notifications} n
              WHERE n.useridto = :userid
                AND n.timeread IS NULL",
            ['userid' => $userid]
        );
    }

    /**
     * Returns upcoming assignment deadlines for the supplied courses.
     *
     * @param int[] $courseids
     * @param int $limit
     * @return array
     */
    private function get_upcoming_assignment_deadlines(array $courseids, int $limit = 5): array {
        global $DB;

        if (empty($courseids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $params['assignmodule'] = 'assign';
        $params['now'] = time();
        $sql = "SELECT a.id, a.name, a.duedate, c.fullname AS coursename, c.id AS courseid, cm.id AS cmid
                  FROM {assign} a
                  JOIN {course} c ON c.id = a.course
                  JOIN {modules} m ON m.name = :assignmodule
                  JOIN {course_modules} cm ON cm.instance = a.id AND cm.module = m.id AND cm.course = c.id
                 WHERE a.course {$insql}
                   AND a.duedate > :now
              ORDER BY a.duedate ASC";

        $records = $DB->get_records_sql($sql, $params, 0, $limit);
        $items = [];
        foreach ($records as $record) {
            $items[] = [
                'title' => $record->name,
                'subtitle' => $record->coursename,
                'time' => userdate((int)$record->duedate),
                'url' => new \moodle_url('/mod/assign/view.php', ['id' => $record->cmid]),
            ];
        }

        return $items;
    }

    /**
     * Returns assignments with submissions still awaiting grading for lecturer workflows.
     *
     * @param int[] $courseids
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    private function get_pending_grading_queue(array $courseids, int $limit = 5): array {
        global $DB;

        if (empty($courseids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $params['assignmodule'] = 'assign';
        $params['submitted'] = 'submitted';
        $sql = "SELECT a.id,
                       a.name,
                       c.fullname AS coursename,
                       cm.id AS cmid,
                       COUNT(DISTINCT s.userid) AS pendingcount,
                       MAX(s.timemodified) AS latestsubmission
                  FROM {assign_submission} s
                  JOIN {assign} a ON a.id = s.assignment
                  JOIN {course} c ON c.id = a.course
                  JOIN {modules} m ON m.name = :assignmodule
                  JOIN {course_modules} cm ON cm.instance = a.id AND cm.module = m.id AND cm.course = c.id
             LEFT JOIN {assign_grades} g ON g.assignment = s.assignment AND g.userid = s.userid
                 WHERE a.course {$insql}
                   AND s.status = :submitted
                   AND (g.id IS NULL OR g.timemodified < s.timemodified)
              GROUP BY a.id, a.name, c.fullname, cm.id
              ORDER BY latestsubmission DESC";

        $records = $DB->get_records_sql($sql, $params, 0, $limit);
        $items = [];
        foreach ($records as $record) {
            $pendingcount = (int)$record->pendingcount;
            $items[] = [
                'title' => $record->name,
                'subtitle' => $record->coursename,
                'meta' => \get_string('gradingqueueitemmeta', 'local_ulms_dashboard', $pendingcount),
                'time' => userdate((int)$record->latestsubmission),
                'url' => new \moodle_url('/mod/assign/view.php', ['id' => $record->cmid]),
                'pendingcount' => $pendingcount,
            ];
        }

        return $items;
    }

    /**
     * Returns the number of submissions awaiting grading.
     *
     * @param array<int, array<string, mixed>> $queue
     * @return int
     */
    private function get_pending_grading_count(array $queue): int {
        $count = 0;

        foreach ($queue as $item) {
            $count += (int)($item['pendingcount'] ?? 0);
        }

        return $count;
    }

    /**
     * Returns upcoming user and course events.
     *
     * @param int[] $courseids
     * @param int $userid
     * @param int $limit
     * @return array
     */
    private function get_upcoming_calendar_events(array $courseids, int $userid, int $limit = 5): array {
        global $DB;

        $params = ['now' => time(), 'userid' => $userid];
        $scopeclause = '(userid = :userid)';

        if (!empty($courseids)) {
            [$insql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
            $scopeclause = "(userid = :userid OR courseid {$insql})";
            $params += $courseparams;
        }

        $sql = "SELECT e.id, e.name, e.timestart, e.courseid, c.fullname AS coursename
                  FROM {event} e
             LEFT JOIN {course} c ON c.id = e.courseid
                 WHERE e.timestart >= :now
                   AND {$scopeclause}
              ORDER BY timestart ASC";

        $records = $DB->get_records_sql($sql, $params, 0, $limit);
        $items = [];
        foreach ($records as $record) {
            $items[] = [
                'title' => $record->name,
                'subtitle' => !empty($record->coursename) ? $record->coursename : \get_string('calendar'),
                'time' => userdate((int)$record->timestart),
                'url' => new \moodle_url('/calendar/view.php', ['view' => 'day']),
            ];
        }

        return $items;
    }

    /**
     * Returns a lightweight completion summary.
     *
     * @param int[] $courseids
     * @param int $userid
     * @return array
     */
    private function get_completion_summary(array $courseids, int $userid): array {
        global $DB;

        if (empty($courseids)) {
            return ['completed' => 0, 'total' => 0, 'percent' => 0];
        }

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $params['userid'] = $userid;
        $params['incomplete'] = COMPLETION_INCOMPLETE;
        $sqltotal = "SELECT COUNT(cm.id)
                       FROM {course_modules} cm
                      WHERE cm.course {$insql}
                        AND cm.completion <> :incomplete";
        $total = (int)$DB->count_records_sql($sqltotal, $params);

        $completedstates = [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS];
        if (defined('COMPLETION_COMPLETE_FAIL')) {
            $completedstates[] = COMPLETION_COMPLETE_FAIL;
        }
        [$statesql, $stateparams] = $DB->get_in_or_equal($completedstates, SQL_PARAMS_NAMED, 'state');
        $completedsql = "SELECT COUNT(cmc.id)
                           FROM {course_modules_completion} cmc
                           JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                          WHERE cm.course {$insql}
                            AND cmc.userid = :userid
                            AND cmc.completionstate {$statesql}";
        $completed = (int)$DB->count_records_sql($completedsql, $params + $stateparams);

        $percent = $total > 0 ? (int)floor(($completed / $total) * 100) : 0;
        return ['completed' => $completed, 'total' => $total, 'percent' => $percent];
    }

    /**
     * Counts distinct field values filtered by one or more state values.
     *
     * @param string $table
     * @param string $distinctfield
     * @param string $filterfield
     * @param array $values
     * @return int
     */
    private function count_distinct_records_in_or_equal(
        string $table,
        string $distinctfield,
        string $filterfield,
        array $values
    ): int {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($values, SQL_PARAMS_NAMED);
        $sql = "SELECT COUNT(DISTINCT {$distinctfield})
                  FROM {{$table}}
                 WHERE {$filterfield} {$insql}";

        return (int)$DB->count_records_sql($sql, $params);
    }

    /**
     * Returns supported analytics time windows.
     *
     * @param int $selecteddays
     * @return array
     */
    private function get_analytics_window_options(int $selecteddays): array {
        $options = [];

        foreach ([7, 30, 90, 180] as $days) {
            $options[] = [
                'value' => $days,
                'label' => \get_string('analyticswindowlabel', 'local_ulms_dashboard', $days),
                'selected' => $days === $selecteddays,
            ];
        }

        return $options;
    }

    /**
     * Restricts analytics windows to supported values.
     *
     * @param int $days
     * @return int
     */
    private function normalise_analytics_days(int $days): int {
        return in_array($days, [7, 30, 90, 180], true) ? $days : 30;
    }

    /**
     * Calculates a rounded percentage for chart displays.
     *
     * @param int $value
     * @param int $total
     * @return int
     */
    private function calculate_percentage(int $value, int $total): int {
        if ($total <= 0) {
            return 0;
        }

        return (int)floor(($value / $total) * 100);
    }

    /**
     * Returns faculty-level academic breakdown rows.
     *
     * @return array
     */
    private function get_faculty_breakdown_data(): array {
        global $DB;

        try {
            $sql = "SELECT f.id, f.name,
                           COUNT(DISTINCT pcm.moodlecourseid) AS mappedcoursecount,
                           COUNT(DISTINCT CASE WHEN c.visible = 1 THEN c.id ELSE NULL END) AS visiblecoursecount,
                           COUNT(DISTINCT cm.id) AS activitycount
                      FROM {local_ulms_faculties} f
                      JOIN {local_ulms_departments} d ON d.facultyid = f.id
                      JOIN {local_ulms_programmes} p ON p.departmentid = d.id
                      JOIN {local_ulms_programme_courses} pcm ON pcm.programmeid = p.id
                      JOIN {course} c ON c.id = pcm.moodlecourseid
                 LEFT JOIN {course_modules} cm ON cm.course = c.id AND cm.completion > :incomplete
                  GROUP BY f.id, f.name
                  ORDER BY COUNT(DISTINCT pcm.moodlecourseid) DESC, f.name ASC";
            $records = $DB->get_records_sql($sql, ['incomplete' => COMPLETION_INCOMPLETE]);
        } catch (\Exception) {
            return [];
        }

        $maxmappedcourses = 0;
        foreach ($records as $record) {
            $maxmappedcourses = max($maxmappedcourses, (int)$record->mappedcoursecount);
        }

        $items = [];
        foreach ($records as $record) {
            $items[] = [
                'label' => $record->name,
                'value' => (int)$record->mappedcoursecount,
                'subtitle' => \get_string('facultybreakdownsubtitle', 'local_ulms_dashboard', (object)[
                    'visiblecourses' => (int)$record->visiblecoursecount,
                    'activities' => (int)$record->activitycount,
                ]),
                'percent' => $this->calculate_percentage((int)$record->mappedcoursecount, max($maxmappedcourses, 1)),
            ];
        }

        return $items;
    }

    /**
     * Returns department-level academic breakdown rows.
     *
     * @return array
     */
    private function get_department_breakdown_data(): array {
        global $DB;

        try {
            $sql = "SELECT d.id, d.name, f.name AS facultyname,
                           COUNT(DISTINCT pcm.moodlecourseid) AS mappedcoursecount,
                           COUNT(DISTINCT a.id) AS assignmentcount
                      FROM {local_ulms_departments} d
                      JOIN {local_ulms_faculties} f ON f.id = d.facultyid
                      JOIN {local_ulms_programmes} p ON p.departmentid = d.id
                      JOIN {local_ulms_programme_courses} pcm ON pcm.programmeid = p.id
                      JOIN {course} c ON c.id = pcm.moodlecourseid
                 LEFT JOIN {assign} a ON a.course = c.id
                  GROUP BY d.id, d.name, f.name
                  ORDER BY COUNT(DISTINCT pcm.moodlecourseid) DESC, d.name ASC";
            $records = $DB->get_records_sql($sql, null, 0, 10);
        } catch (\Exception) {
            return [];
        }

        $maxmappedcourses = 0;
        foreach ($records as $record) {
            $maxmappedcourses = max($maxmappedcourses, (int)$record->mappedcoursecount);
        }

        $items = [];
        foreach ($records as $record) {
            $items[] = [
                'label' => $record->name,
                'value' => (int)$record->mappedcoursecount,
                'subtitle' => \get_string('departmentbreakdownsubtitle', 'local_ulms_dashboard', (object)[
                    'faculty' => $record->facultyname,
                    'assignments' => (int)$record->assignmentcount,
                ]),
                'percent' => $this->calculate_percentage((int)$record->mappedcoursecount, max($maxmappedcourses, 1)),
            ];
        }

        return $items;
    }

    /**
     * Returns a readable current academic period label when configured.
     *
     * @return string
     */
    private function get_current_academic_period_label(): string {
        if (!class_exists(\local_ulms_academics\local\service\academic_structure_service::class)) {
            return \get_string('dashboardfocusperiodfallback', 'local_ulms_dashboard');
        }

        $service = new \local_ulms_academics\local\service\academic_structure_service();
        $sessioncode = trim($service->get_current_session_code());

        if ($sessioncode === '') {
            return \get_string('dashboardfocusperiodfallback', 'local_ulms_dashboard');
        }

        return \get_string('dashboardfocusperiodlabel', 'local_ulms_dashboard', $sessioncode);
    }

    /**
     * Counts distinct active users assigned to one or more role shortnames.
     *
     * @param string[] $roleshortnames
     * @param bool $includesiteadmins
     * @return int
     */
    private function count_users_with_roles(array $roleshortnames, bool $includesiteadmins = false): int {
        global $DB;

        if (empty($roleshortnames)) {
            return $includesiteadmins ? count(get_admins()) : 0;
        }

        [$rolesql, $params] = $DB->get_in_or_equal($roleshortnames, SQL_PARAMS_NAMED);
        $params['systemcontext'] = CONTEXT_SYSTEM;
        $params['coursecategorycontext'] = CONTEXT_COURSECAT;
        $params['coursecontext'] = CONTEXT_COURSE;

        $sql = "SELECT DISTINCT u.id
                  FROM {user} u
                  JOIN {role_assignments} ra ON ra.userid = u.id
                  JOIN {role} r ON r.id = ra.roleid
                  JOIN {context} ctx ON ctx.id = ra.contextid
                 WHERE u.deleted = 0
                   AND r.shortname {$rolesql}
                   AND (
                       ctx.contextlevel = :systemcontext
                       OR ctx.contextlevel = :coursecategorycontext
                       OR ctx.contextlevel = :coursecontext
                   )";

        $userids = array_map('intval', $DB->get_fieldset_sql($sql, $params));

        if ($includesiteadmins) {
            $userids = array_merge($userids, array_map('intval', array_keys(get_admins())));
        }

        return count(array_values(array_unique($userids)));
    }
}
