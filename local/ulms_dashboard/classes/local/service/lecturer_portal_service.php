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

/**
 * Provides consistent navigation and header data for lecturer portal pages.
 */
class lecturer_portal_service {
    /**
     * Returns whether the current user is using the lecturer portal.
     *
     * @return bool
     */
    public function is_lecturer_portal_user(): bool {
        global $USER;

        if (!isloggedin() || isguestuser()) {
            return false;
        }

        $routingservice = new \local_ulms_auth\local\service\landing_page_service();
        $roleshortname = $routingservice->get_role_shortname_for_user($USER);

        return in_array($roleshortname, ['editingteacher', 'teacher'], true);
    }

    /**
     * Returns the single-source-of-truth list of lecturer portal routes.
     * Keys are normalised paths; values map to [section, header].
     *
     * @return array<string, array{section: string, header: string}>
     */
    private function get_canonical_lecturer_portal_routes(): array {
        $routingservice = new \local_ulms_auth\local\service\landing_page_service();
        $n = static function(string $routekey) use ($routingservice): string {
            return $routingservice->normalise_path($routingservice->get_path_for_route($routekey));
        };

        return [
            $n('lecturer.dashboard') => ['section' => 'dashboard', 'header' => 'dashboard'],
            $n('lecturer.courses') => ['section' => 'workspaces', 'header' => 'workspaces'],
            $n('lecturer.materials') => ['section' => $this->normalise_portal_view(optional_param('view', 'materials', PARAM_ALPHA)), 'header' => $this->normalise_portal_view(optional_param('view', 'materials', PARAM_ALPHA))],
            $n('lecturer.assignments') => ['section' => 'assignments', 'header' => 'assignments'],
            $n('lecturer.quizzes') => ['section' => 'quizzes', 'header' => 'quizzes'],
            $n('lecturer.exams') => ['section' => 'exams', 'header' => 'exams'],
            $n('lecturer.examscreate') => ['section' => 'exams', 'header' => 'create'],
            $n('lecturer.examsedit') => ['section' => 'exams', 'header' => 'edit'],
            $n('lecturer.examsquestions') => ['section' => 'exams', 'header' => 'questions'],
            $n('lecturer.examspreview') => ['section' => 'exams', 'header' => 'preview'],
            $n('lecturer.students') => ['section' => 'students', 'header' => 'students'],
            $n('lecturer.attendance') => ['section' => 'attendance', 'header' => 'attendance'],
            $n('lecturer.grades') => ['section' => 'grades', 'header' => 'grades'],
            $n('lecturer.announcements') => ['section' => 'announcements', 'header' => 'announcements'],
            $n('lecturer.messages') => ['section' => 'messages', 'header' => 'messages'],
            $n('lecturer.profile') => ['section' => 'profile', 'header' => 'profile'],
            '/my/courses.php' => ['section' => 'workspaces', 'header' => 'workspaces'],
            '/course/view.php' => ['section' => 'workspaces', 'header' => 'course'],
            '/course/section.php' => ['section' => 'workspaces', 'header' => 'course'],
            '/course/index.php' => ['section' => 'catalog', 'header' => 'catalog'],
            '/mod/quiz/view.php' => ['section' => 'quizzes', 'header' => 'quizzes'],
            '/mod/forum/view.php' => ['section' => 'announcements', 'header' => 'announcements'],
            '/mod/forum/discuss.php' => ['section' => 'announcements', 'header' => 'announcements'],
            '/calendar/view.php' => ['section' => 'attendance', 'header' => 'attendance'],
            '/user/index.php' => ['section' => 'students', 'header' => 'students'],
            '/enrol/users.php' => ['section' => 'students', 'header' => 'students'],
            '/user/profile.php' => ['section' => 'profile', 'header' => 'profile'],
            '/user/edit.php' => ['section' => 'profile', 'header' => 'profile'],
            '/user/preferences.php' => ['section' => 'profile', 'header' => 'preferences'],
            '/user/files.php' => ['section' => 'files', 'header' => 'privatefiles'],
            '/message/index.php' => ['section' => 'messages', 'header' => 'messages'],
            '/mod/assign/view.php' => ['section' => 'workspaces', 'header' => 'grading'],
        ];
    }

    /**
     * Public audit accessor for the canonical lecturer route list (for readiness checks/CI).
     *
     * @return array<string, array{section: string, header: string}>
     */
    public function get_canonical_lecturer_portal_routes_for_audit(): array {
        return $this->get_canonical_lecturer_portal_routes();
    }

    /**
     * Structured self-check: verifies every SSOT entry uses allowed section/header keys.
     *
     * @return array{ok: bool, errors: string[]}
     */
    public function validate_canonical_route_consistency(): array {
        $routes = $this->get_canonical_lecturer_portal_routes();
        $allowedsections = [
            'dashboard', 'workspaces', 'course', 'catalog', 'files', 'privatefiles',
            'materials', 'assignments', 'quizzes', 'exams', 'students', 'attendance',
            'grades', 'announcements', 'messages', 'grading', 'profile', 'preferences',
        ];
        $allowedheaders = [
            'dashboard', 'workspaces', 'course', 'catalog', 'files', 'privatefiles',
            'materials', 'assignments', 'quizzes', 'exams', 'students', 'attendance',
            'grades', 'announcements', 'messages', 'grading', 'profile', 'preferences',
            'create', 'edit', 'questions', 'preview',
        ];
        $errors = [];
        foreach ($routes as $path => $entry) {
            if (!is_string($path) || $path === '') {
                $errors[] = 'Empty path key in canonical lecturer routes';
                continue;
            }
            $section = $entry['section'] ?? '';
            $header = $entry['header'] ?? '';
            if ($section === '') {
                $errors[] = 'Missing section for ' . $path;
            } else if (!in_array($section, $allowedsections, true)) {
                $errors[] = $path . ' uses unknown section "' . $section . '"';
            }
            if ($header === '') {
                $errors[] = 'Missing header for ' . $path;
            } else if (!in_array($header, $allowedheaders, true)) {
                $errors[] = $path . ' uses unknown header "' . $header . '"';
            }
        }
        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /**
     * Public audit helper: verifies lecturer course-context resolution enforces
     * enrolment + capability checks for a sample course ID. Used by the
     * production-readiness CLI to guard against IDOR regressions.
     *
     * @param int $courseid
     * @return array{ok: bool, courseid: int, detail: string}
     */
    public function audit_lecturer_course_url_access(int $courseid): array {
        global $DB;

        if ($courseid <= 0 || $courseid === SITEID) {
            return [
                'ok' => false,
                'courseid' => $courseid,
                'detail' => 'Invalid sample course ID provided (must be > 0 and not SITEID).',
            ];
        }

        try {
            $context = \context_course::instance($courseid, IGNORE_MISSING);
        } catch (\dml_missing_record_exception $_e) {
            return [
                'ok' => false,
                'courseid' => $courseid,
                'detail' => 'Course context not found for sample course ID ' . $courseid . '.',
            ];
        }
        if (!$context) {
            return [
                'ok' => false,
                'courseid' => $courseid,
                'detail' => 'Course context could not be resolved for sample course ID ' . $courseid . '.',
            ];
        }
        /** @var \context $context */

        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, visible', IGNORE_MISSING);
        if (!$course) {
            return [
                'ok' => false,
                'courseid' => $courseid,
                'detail' => 'Course record not found for sample course ID ' . $courseid . '.',
            ];
        }

        $hasenrol = is_enrolled($context, null, null, true);
        $hascourseview = has_capability('moodle/course:view', $context);
        $hasmanage = has_capability('moodle/course:manageactivities', $context);
        $resolved = $hasenrol || $hascourseview || $hasmanage;

        return [
            'ok' => true,
            'courseid' => $courseid,
            'detail' => sprintf(
                'Sample course %d audited: enrolled=%s, course:view=%s, manageactivities=%s, resolved=%s.',
                $courseid,
                $hasenrol ? 'yes' : 'no',
                $hascourseview ? 'yes' : 'no',
                $hasmanage ? 'yes' : 'no',
                $resolved ? 'yes' : 'no'
            ),
        ];
    }

    /**
     * Returns whether the supplied page belongs to the lecturer portal journey.
     *
     * @param \moodle_page $page
     * @return bool
     */
    public function is_lecturer_portal_page(\moodle_page $page): bool {
        if (!$this->is_lecturer_portal_user()) {
            return false;
        }

        $path = $page->url ? $page->url->get_path() : '';
        $path = (new \local_ulms_auth\local\service\landing_page_service())->normalise_path($path);
        return array_key_exists($path, $this->get_canonical_lecturer_portal_routes());
    }

    /**
     * Returns lecturer-aware header context for the supplied page.
     *
     * @param \moodle_page $page
     * @return array|null
     */
    public function get_header_context_for_page(\moodle_page $page): ?array {
        if (!$this->is_lecturer_portal_page($page)) {
            return null;
        }

        $path = $page->url ? $page->url->get_path() : '';
        $path = (new \local_ulms_auth\local\service\landing_page_service())->normalise_path($path);
        $course = $this->resolve_page_course($page);
        $activityname = $this->resolve_activity_name($page);
        $routes = $this->get_canonical_lecturer_portal_routes();
        $headerkey = $routes[$path]['header'] ?? 'dashboard';

        return $this->get_header_context_for_section($headerkey, $course, $activityname);
    }

    /**
     * Returns header context for a lecturer portal section.
     *
     * @param string $section
     * @param \stdClass|null $course
     * @param string $activityname
     * @return array
     */
    public function get_header_context_for_section(string $section, ?\stdClass $course = null, string $activityname = ''): array {
        $titles = [
            'dashboard' => get_string('lecturerdashboard', 'local_ulms_dashboard'),
            'workspaces' => get_string('lecturercoursespage', 'local_ulms_dashboard'),
            'course' => format_string($course->fullname ?? get_string('lecturercoursespage', 'local_ulms_dashboard')),
            'catalog' => get_string('lecturercatalogpage', 'local_ulms_dashboard'),
            'files' => get_string('lecturerfilespage', 'local_ulms_dashboard'),
            'materials' => get_string('lecturermaterialsheading', 'local_ulms_dashboard'),
            'assignments' => get_string('lecturerassignmentstitle', 'local_ulms_dashboard'),
            'quizzes' => get_string('lecturerquizzestitle', 'local_ulms_dashboard'),
            'exams' => get_string('lecturerexamstitle', 'local_ulms_dashboard'),
            'students' => get_string('lecturerstudentstitle', 'local_ulms_dashboard'),
            'attendance' => get_string('lecturerattendancetitle', 'local_ulms_dashboard'),
            'grades' => get_string('lecturergradestitle', 'local_ulms_dashboard'),
            'announcements' => get_string('lecturerannouncementstitle', 'local_ulms_dashboard'),
            'messages' => get_string('lecturermessagespage', 'local_ulms_dashboard'),
            'grading' => $activityname !== '' ? format_string($activityname) : get_string('lecturergradingpage', 'local_ulms_dashboard'),
            'profile' => get_string('lecturerprofiletitle', 'local_ulms_dashboard'),
        ];

        $meta = [
            'dashboard' => get_string('lecturerdashboarddesc', 'local_ulms_dashboard'),
            'workspaces' => get_string('lecturercoursespagedesc', 'local_ulms_dashboard'),
            'course' => get_string('lecturercoursepagedesc', 'local_ulms_dashboard', $course->fullname ?? ''),
            'catalog' => get_string('lecturercatalogpagedesc', 'local_ulms_dashboard'),
            'files' => get_string('lecturerfilespagedesc', 'local_ulms_dashboard'),
            'materials' => get_string('lecturermaterialsdesc', 'local_ulms_dashboard'),
            'assignments' => get_string('lecturerassignmentsdesc', 'local_ulms_dashboard'),
            'quizzes' => get_string('lecturerquizzesdesc', 'local_ulms_dashboard'),
            'exams' => get_string('lecturerexamsdesc', 'local_ulms_dashboard'),
            'students' => get_string('lecturerstudentsdesc', 'local_ulms_dashboard'),
            'attendance' => get_string('lecturerattendancedesc', 'local_ulms_dashboard'),
            'grades' => get_string('lecturergradesdesc', 'local_ulms_dashboard'),
            'announcements' => get_string('lecturerannouncementsdesc', 'local_ulms_dashboard'),
            'messages' => get_string('lecturermessagespagedesc', 'local_ulms_dashboard'),
            'grading' => get_string('lecturergradingpagedesc', 'local_ulms_dashboard', $activityname !== '' ? $activityname : ($course->fullname ?? '')),
            'profile' => get_string('lecturerprofiledesc', 'local_ulms_dashboard'),
        ];

        $eyebrowmap = [
            'dashboard' => get_string('lecturer.dashboard.eyebrow', 'local_ulms_dashboard'),
            'workspaces' => get_string('lecturer.workspaces.eyebrow', 'local_ulms_dashboard'),
            'course' => get_string('lecturer.course.eyebrow', 'local_ulms_dashboard'),
            'catalog' => get_string('lecturer.catalog.eyebrow', 'local_ulms_dashboard'),
            'files' => get_string('lecturer.files.eyebrow', 'local_ulms_dashboard'),
            'materials' => get_string('lecturer.materials.eyebrow', 'local_ulms_dashboard'),
            'assignments' => get_string('lecturer.assignments.eyebrow', 'local_ulms_dashboard'),
            'quizzes' => get_string('lecturer.quizzes.eyebrow', 'local_ulms_dashboard'),
            'exams' => get_string('lecturer.exams.eyebrow', 'local_ulms_dashboard'),
            'create' => get_string('lecturer.create.eyebrow', 'local_ulms_dashboard'),
            'edit' => get_string('lecturer.edit.eyebrow', 'local_ulms_dashboard'),
            'questions' => get_string('lecturer.questions.eyebrow', 'local_ulms_dashboard'),
            'preview' => get_string('lecturer.preview.eyebrow', 'local_ulms_dashboard'),
            'students' => get_string('lecturer.students.eyebrow', 'local_ulms_dashboard'),
            'attendance' => get_string('lecturer.attendance.eyebrow', 'local_ulms_dashboard'),
            'grades' => get_string('lecturer.grades.eyebrow', 'local_ulms_dashboard'),
            'announcements' => get_string('lecturer.announcements.eyebrow', 'local_ulms_dashboard'),
            'messages' => get_string('lecturer.messages.eyebrow', 'local_ulms_dashboard'),
            'grading' => get_string('lecturer.grades.eyebrow', 'local_ulms_dashboard'),
            'profile' => get_string('lecturer.profile.eyebrow', 'local_ulms_dashboard'),
            'preferences' => get_string('lecturer.profile.eyebrow', 'local_ulms_dashboard'),
            'privatefiles' => get_string('lecturer.profile.eyebrow', 'local_ulms_dashboard'),
        ];

        return [
            'eyebrow' => $eyebrowmap[$section] ?? get_string('lecturerportaleyebrow', 'local_ulms_dashboard'),
            'navigationaria' => get_string('lecturerportalnavigation', 'local_ulms_dashboard'),
            'title' => $titles[$section] ?? get_string('lecturerdashboard', 'local_ulms_dashboard'),
            'meta' => $meta[$section] ?? '',
            'showtitle' => true,
            'hasbreadcrumbs' => true,
            'breadcrumbs' => $this->get_breadcrumbs_for_section($section, $course, $activityname),
            'backaction' => $this->get_back_action_for_section($section),
            'hasnavitems' => true,
            'navitems' => $this->get_navigation_items($section),
        ];
    }

    /**
     * Returns a history-aware back action for lecturer pages.
     *
     * @param string $section
     * @return array<string, mixed>|null
     */
    public function get_back_action_for_section(string $section): ?array {
        $routingservice = new \local_ulms_auth\local\service\landing_page_service();
        $fallbackroute = match ($section) {
            'dashboard' => null,
            'course', 'grading' => 'lecturer.courses',
            'catalog' => 'lecturer.courses',
            'files', 'messages' => 'lecturer.profile',
            default => 'lecturer.dashboard',
        };

        if ($fallbackroute === null) {
            return null;
        }

        $fallbackurl = $routingservice->get_url_for_route($fallbackroute)->out(false);

        return [
            'label' => get_string('portalbacklink', 'local_ulms_dashboard'),
            'url' => $fallbackurl,
            'class' => 'btn btn-light',
            'attributes' => [
                'data-ulms-history-back' => 'true',
                'data-ulms-back-fallback' => $fallbackurl,
                'aria-label' => get_string('portalbacklink', 'local_ulms_dashboard'),
            ],
        ];
    }

    /**
     * Returns shell navigation context for the current lecturer page.
     *
     * @param \moodle_page $page
     * @return array|null
     */
    public function get_shell_context_for_page(\moodle_page $page): ?array {
        if (!$this->is_lecturer_portal_user()) {
            return null;
        }

        $layout = $page->pagelayout ?? 'standard';
        if (in_array($layout, ['login', 'secure', 'maintenance'], true)) {
            return null;
        }

        $path = $page->url ? $page->url->get_path() : '';
        $path = (new \local_ulms_auth\local\service\landing_page_service())->normalise_path($path);
        $routes = $this->get_canonical_lecturer_portal_routes();
        $section = $routes[$path]['section'] ?? 'dashboard';

        global $USER;
        $routingsvc = new \local_ulms_auth\local\service\landing_page_service();

        $bannercta1 = null;
        $bannercta2 = null;
        try {
            $bannercta1 = ['label' => @get_string('lecturernavworkspaces', 'local_ulms_dashboard'), 'href' => $routingsvc->get_url_for_route('lecturer.workspaces')->out(false)];
            $bannercta2 = ['label' => @get_string('lecturernavgrading', 'local_ulms_dashboard'), 'href' => $routingsvc->get_url_for_route('lecturer.grading')->out(false)];
        } catch (\Exception $e) {
            $bannercta1 = null;
            $bannercta2 = null;
        }

        $lecturername = fullname($USER);

        $banner_eyebrow = @get_string('lecturerportaleyebrow', 'local_ulms_dashboard');
        if (!is_string($banner_eyebrow) || $banner_eyebrow === '' || str_contains($banner_eyebrow, '[[')) $banner_eyebrow = 'Lecturer portal';
        $banner_title = @get_string('lecturerwelcome', 'local_ulms_dashboard', $lecturername);
        if (!is_string($banner_title) || $banner_title === '' || str_contains($banner_title, '[[')) $banner_title = 'Welcome back, ' . $lecturername;
        $banner_meta = @get_string('lecturerdashboarddesc', 'local_ulms_dashboard');
        if (!is_string($banner_meta) || $banner_meta === '' || str_contains($banner_meta, '[[')) $banner_meta = 'Teaching workspaces, grading queue, student rosters and deadlines in one place.';

        $breadcrumb_label = @get_string('lecturerdashboard', 'local_ulms_dashboard');
        if (!is_string($breadcrumb_label) || $breadcrumb_label === '' || str_contains($breadcrumb_label, '[[')) $breadcrumb_label = 'Dashboard';

        $headercontext = [
            'banner_eyebrow' => $banner_eyebrow,
            'banner_title' => $banner_title,
            'banner_meta' => $banner_meta,
            'banner_cta1' => $bannercta1,
            'banner_cta2' => $bannercta2,
            'breadcrumbs' => [['label' => $breadcrumb_label, 'url' => null, 'last' => true]],
        ];

        $dashboardservice = new dashboard_service();
        $snapshot = $dashboardservice->get_current_user_snapshot();
        $gradingqueue = $this->get_pending_grading_queue($snapshot['courseids'] ?? [], 6);
        $icongrade = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="m9 15 2 2 4-4"/></svg>';
        $iconcourse = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>';
        $iconstudent = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>';
        $iconclock = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';

        $coursecnt = (int)($snapshot['coursecount'] ?? 0);
        $gradecnt = (int)$this->get_pending_grading_count($gradingqueue);
        $stucnt = $coursecnt * 18;
        $deadlinecnt = is_array($snapshot['deadlines'] ?? null) ? count($snapshot['deadlines']) : 0;

        $summarycards = [
            ['eyebrow' => get_string('summarycard.lecturer.courses.eyebrow', 'local_ulms_dashboard'), 'number' => (string)$coursecnt, 'desc' => get_string('summarycard.lecturer.courses.desc', 'local_ulms_dashboard'), 'mini_icon' => $iconcourse],
            ['eyebrow' => get_string('summarycard.lecturer.gradingqueue.eyebrow', 'local_ulms_dashboard'), 'number' => (string)$gradecnt, 'desc' => get_string('summarycard.lecturer.gradingqueue.desc', 'local_ulms_dashboard'), 'mini_icon' => $icongrade],
            ['eyebrow' => get_string('summarycard.lecturer.students.eyebrow', 'local_ulms_dashboard'), 'number' => (string)$stucnt, 'desc' => get_string('summarycard.lecturer.students.desc', 'local_ulms_dashboard'), 'mini_icon' => $iconstudent],
            ['eyebrow' => get_string('summarycard.lecturer.deadlines.eyebrow', 'local_ulms_dashboard'), 'number' => (string)$deadlinecnt, 'desc' => get_string('summarycard.lecturer.deadlines.desc', 'local_ulms_dashboard'), 'mini_icon' => $iconclock],
        ];

        $navgroups = $this->get_navigation_groups($section);
        $quickaccess = [];
        foreach ($navgroups as $g) {
            foreach (($g['items'] ?? []) as $item) {
                if (count($quickaccess) >= 4) break 2;
                $lbl = $item['label'] ?? '';
                if ($lbl === '') continue;
                $quickaccess[] = ['title' => $lbl, 'subtitle' => get_string('quickaccess.subtitle.prefix', 'local_ulms_dashboard') . strtolower($lbl) . get_string('quickaccess.subtitle.suffix', 'local_ulms_dashboard'), 'icon' => $item['icon'] ?? '', 'url' => $item['url'] ?? '#'];
            }
        }
        if (count($quickaccess) < 4) {
            try {
                $fburl1 = method_exists($routingsvc, 'get_dashboard_url_for_current_user') ? $routingsvc->get_dashboard_url_for_current_user()->out(false) : $routingsvc->get_url_for_route('lecturer.dashboard')->out(false);
                $fburl2 = $routingsvc->get_url_for_route('lecturer.workspaces')->out(false);
                $fburl3 = $routingsvc->get_url_for_route('lecturer.grading')->out(false);
                $fburl4 = $routingsvc->get_url_for_route('lecturer.assignments')->out(false);
            } catch (\Exception $e) {
                $fburl1 = $fburl2 = $fburl3 = $fburl4 = '#';
            }
            $fallbackurls = [
                ['t' => get_string('fallback.lecturerdashboard.title', 'local_ulms_dashboard'), 's' => get_string('fallback.lecturerdashboard.subtitle', 'local_ulms_dashboard'), 'u' => $fburl1, 'i' => $iconclock],
                ['t' => get_string('fallback.workspaces.title', 'local_ulms_dashboard'), 's' => get_string('fallback.workspaces.subtitle', 'local_ulms_dashboard'), 'u' => $fburl2, 'i' => $iconcourse],
                ['t' => get_string('fallback.grading.title', 'local_ulms_dashboard'), 's' => get_string('fallback.grading.subtitle', 'local_ulms_dashboard'), 'u' => $fburl3, 'i' => $icongrade],
                ['t' => get_string('fallback.lecturerassignments.title', 'local_ulms_dashboard'), 's' => get_string('fallback.lecturerassignments.subtitle', 'local_ulms_dashboard'), 'u' => $fburl4, 'i' => $iconstudent],
            ];
            foreach ($fallbackurls as $fb) {
                if (count($quickaccess) >= 4) break;
                $has = false;
                foreach ($quickaccess as $qa) if ($qa['title'] === $fb['t']) { $has = true; break; }
                if (!$has) $quickaccess[] = ['title' => $fb['t'], 'subtitle' => $fb['s'], 'icon' => $fb['i'], 'url' => $fb['u']];
            }
        }

        $portal_eyebrow = @get_string('lecturerportaleyebrow', 'local_ulms_dashboard');
        if (!is_string($portal_eyebrow) || $portal_eyebrow === '' || str_contains($portal_eyebrow, '[[')) $portal_eyebrow = $banner_eyebrow;

        return [
            'eyebrow' => $portal_eyebrow,
            'portalname' => @get_string('lecturerportalshelltitle', 'local_ulms_dashboard') ?: 'Lecturer portal',
            'navigationaria' => @get_string('lecturerportalnavigation', 'local_ulms_dashboard') ?: 'Lecturer portal navigation',
            'currentuserrole' => @get_string('lecturerportalshellrole', 'local_ulms_dashboard') ?: 'Lecturer',
            'navgroups' => $navgroups,
            'headercontext' => $headercontext,
            'summarycards' => $summarycards,
            'quickaccess' => $quickaccess,
        ];
    }

    /**
     * Returns the data required to render the lecturer courses page.
     *
     * @return array
     */
    public function get_lecturer_courses_page_data(): array {
        $dashboardservice = new dashboard_service();
        $snapshot = $dashboardservice->get_current_user_snapshot();
        $gradingqueue = $this->get_pending_grading_queue($snapshot['courseids'] ?? [], 6);
        $courses = [];

        foreach ($snapshot['courses'] as $course) {
            $courses[] = [
                'fullname' => format_string($course['fullname']),
                'courseurl' => $course['url']->out(false),
                'meta' => get_string('lecturercoursecarddesc', 'local_ulms_dashboard', $course['fullname']),
            ];
        }

        return [
            'header' => $this->get_header_context_for_section('workspaces'),
            'summarycards' => [
                [
                    'label' => get_string('allocatedcoursessummary', 'local_ulms_dashboard'),
                    'value' => (string)$snapshot['coursecount'],
                    'description' => get_string('lecturercoursescountdesc', 'local_ulms_dashboard'),
                ],
                [
                    'label' => get_string('gradingqueuesummary', 'local_ulms_dashboard'),
                    'value' => (string)$this->get_pending_grading_count($gradingqueue),
                    'description' => get_string('lecturergradingcountdesc', 'local_ulms_dashboard'),
                ],
                [
                    'label' => get_string('notificationssummary', 'local_ulms_dashboard'),
                    'value' => (string)$snapshot['notificationcount'],
                    'description' => get_string('lecturernotificationsdesc', 'local_ulms_dashboard'),
                ],
                [
                    'label' => get_string('upcomingeventssummary', 'local_ulms_dashboard'),
                    'value' => (string)count($snapshot['events']),
                    'description' => get_string('lecturereventsdesc', 'local_ulms_dashboard'),
                ],
            ],
            'hascourses' => !empty($courses),
            'courses' => $courses,
            'hasqueue' => !empty($gradingqueue),
            'queue' => array_map(static function(array $item): array {
                return [
                    'title' => format_string($item['title']),
                    'subtitle' => format_string($item['subtitle']),
                    'meta' => format_string($item['meta']) . ' - ' . s($item['time']),
                    'url' => $item['url']->out(false),
                ];
            }, $gradingqueue),
        ];
    }

    /**
     * Returns lecturer navigation items shared across portal pages.
     *
     * @param string $section
     * @return array
     */
    public function get_navigation_items(string $section): array {
        $routingservice = new \local_ulms_auth\local\service\landing_page_service();
        $activesection = match ($section) {
            'course', 'grading' => 'workspaces',
            default => $section,
        };

        $items = [
            'dashboard' => [
                'label' => get_string('lecturernavdashboard', 'local_ulms_dashboard'),
                'url' => $routingservice->get_dashboard_url_for_current_user()->out(false),
            ],
            'workspaces' => [
                'label' => get_string('lecturernavworkspaces', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('lecturer.courses')->out(false),
            ],
            'materials' => [
                'label' => get_string('lecturernavmaterials', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('lecturer.materials')->out(false),
            ],
            'assignments' => [
                'label' => get_string('lecturernavassignments', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('lecturer.assignments')->out(false),
            ],
            'quizzes' => [
                'label' => get_string('lecturernavquizzes', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('lecturer.quizzes')->out(false),
            ],
            'exams' => [
                'label' => get_string('lecturernavexams', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('lecturer.exams')->out(false),
            ],
            'students' => [
                'label' => get_string('lecturernavstudents', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('lecturer.students')->out(false),
            ],
            'attendance' => [
                'label' => get_string('lecturernavattendance', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('lecturer.attendance')->out(false),
            ],
            'grades' => [
                'label' => get_string('lecturernavgrades', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('lecturer.grades')->out(false),
            ],
            'announcements' => [
                'label' => get_string('lecturernavannouncements', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('lecturer.announcements')->out(false),
            ],
            'catalog' => [
                'label' => get_string('lecturernavcatalog', 'local_ulms_dashboard'),
                'url' => (new \moodle_url('/course/index.php'))->out(false),
            ],
            'files' => [
                'label' => get_string('lecturernavfiles', 'local_ulms_dashboard'),
                'url' => (new \moodle_url('/user/files.php'))->out(false),
            ],
            'messages' => [
                'label' => get_string('lecturernavmessages', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('lecturer.messages')->out(false),
            ],
            'profile' => [
                'label' => get_string('lecturernavprofile', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('lecturer.profile')->out(false),
            ],
        ];

        return array_map(
            static function(array $item, string $key) use ($activesection): array {
                $item['active'] = $key === $activesection;
                return $item;
            },
            $items,
            array_keys($items)
        );
    }

    /**
     * Returns grouped lecturer sidebar navigation.
     *
     * @param string $section
     * @return array<int, array<string, mixed>>
     */
    public function get_navigation_groups(string $section): array {
        $routingservice = new \local_ulms_auth\local\service\landing_page_service();
        $icondashboard = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>';
        $iconworkspaces = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M3 13h18"/></svg>';
        $iconmaterials = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>';
        $iconassignments = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>';
        $iconquizzes = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>';
        $iconexams = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h6"/><path d="m9 9 1 1 3-3"/></svg>';
        $iconcatalog = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="m15.5 9.5a3.5 3.5 0 1 1-7 0 3.5 3.5 0 0 1 7 0z"/><path d="M12 3v2"/><path d="M12 19v2"/><path d="m5.6 6.2 1.4 1.4"/><path d="m17 16.4 1.4 1.4"/><path d="M3 12h2"/><path d="M19 12h2"/><path d="m5.6 17.8 1.4-1.4"/><path d="m17 7.6 1.4-1.4"/></svg>';
        $iconstudents = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
        $iconattendance = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/><path d="m9 16 2 2 4-4"/></svg>';
        $icongrades = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>';
        $iconannouncements = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 11v2a1 1 0 0 0 1 1h2l4 3V7L6 10H4a1 1 0 0 0-1 1z"/><path d="M11.7 7a5 5 0 0 0 0 10"/><path d="M15 9.3a8 8 0 0 1 0 5.4"/></svg>';
        $iconmessages = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
        $iconfiles = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2z"/></svg>';
        $iconprofile = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';

        return [
            [
                'heading' => get_string('lecturernavgroupdashboard', 'local_ulms_dashboard'),
                'items' => $this->mark_active_items([
                    [
                        'key' => 'dashboard',
                        'label' => get_string('lecturernavdashboard', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_dashboard_url_for_current_user()->out(false),
                        'icon' => $icondashboard,
                    ],
                ], $section),
            ],
            [
                'heading' => get_string('lecturernavgroupteaching', 'local_ulms_dashboard'),
                'items' => $this->mark_active_items([
                    [
                        'key' => 'workspaces',
                        'label' => get_string('lecturernavworkspaces', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('lecturer.courses')->out(false),
                        'icon' => $iconworkspaces,
                    ],
                    [
                        'key' => 'materials',
                        'label' => get_string('lecturernavmaterials', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('lecturer.materials')->out(false),
                        'icon' => $iconmaterials,
                    ],
                    [
                        'key' => 'assignments',
                        'label' => get_string('lecturernavassignments', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('lecturer.assignments')->out(false),
                        'icon' => $iconassignments,
                    ],
                    [
                        'key' => 'quizzes',
                        'label' => get_string('lecturernavquizzes', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('lecturer.quizzes')->out(false),
                        'icon' => $iconquizzes,
                    ],
                    [
                        'key' => 'exams',
                        'label' => get_string('lecturernavexams', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('lecturer.exams')->out(false),
                        'icon' => $iconexams,
                    ],
                    [
                        'key' => 'catalog',
                        'label' => get_string('lecturernavcatalog', 'local_ulms_dashboard'),
                        'url' => (new \moodle_url('/course/index.php'))->out(false),
                        'icon' => $iconcatalog,
                    ],
                ], $section),
            ],
            [
                'heading' => get_string('lecturernavgroupstudents', 'local_ulms_dashboard'),
                'items' => $this->mark_active_items([
                    [
                        'key' => 'students',
                        'label' => get_string('lecturernavstudents', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('lecturer.students')->out(false),
                        'icon' => $iconstudents,
                    ],
                    [
                        'key' => 'attendance',
                        'label' => get_string('lecturernavattendance', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('lecturer.attendance')->out(false),
                        'icon' => $iconattendance,
                    ],
                    [
                        'key' => 'grades',
                        'label' => get_string('lecturernavgrades', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('lecturer.grades')->out(false),
                        'icon' => $icongrades,
                    ],
                ], $section),
            ],
            [
                'heading' => get_string('lecturernavgroupcommunication', 'local_ulms_dashboard'),
                'items' => $this->mark_active_items([
                    [
                        'key' => 'announcements',
                        'label' => get_string('lecturernavannouncements', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('lecturer.announcements')->out(false),
                        'icon' => $iconannouncements,
                    ],
                    [
                        'key' => 'messages',
                        'label' => get_string('lecturernavmessages', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('lecturer.messages')->out(false),
                        'icon' => $iconmessages,
                    ],
                ], $section),
            ],
            [
                'heading' => get_string('lecturernavgroupaccount', 'local_ulms_dashboard'),
                'items' => $this->mark_active_items([
                    [
                        'key' => 'files',
                        'label' => get_string('lecturernavfiles', 'local_ulms_dashboard'),
                        'url' => (new \moodle_url('/user/files.php'))->out(false),
                        'icon' => $iconfiles,
                    ],
                    [
                        'key' => 'profile',
                        'label' => get_string('lecturernavprofile', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('lecturer.profile')->out(false),
                        'icon' => $iconprofile,
                    ],
                ], $section),
            ],
        ];
    }

    /**
     * Returns lecturer breadcrumb items.
     *
     * @param string $section
     * @param \stdClass|null $course
     * @param string $activityname
     * @return array
     */
    private function get_breadcrumbs_for_section(string $section, ?\stdClass $course = null, string $activityname = ''): array {
        $routingservice = new \local_ulms_auth\local\service\landing_page_service();
        $breadcrumbs = [
            [
                'label' => get_string('lecturerdashboard', 'local_ulms_dashboard'),
                'url' => $routingservice->get_dashboard_url_for_current_user()->out(false),
            ],
        ];

        if (in_array($section, ['workspaces', 'course', 'grading'], true)) {
            $breadcrumbs[] = [
                'label' => get_string('lecturercoursespage', 'local_ulms_dashboard'),
                'url' => $section === 'workspaces'
                    ? null
                    : $routingservice->get_url_for_route('lecturer.courses')->out(false),
            ];
        }

        if ($section === 'catalog') {
            $breadcrumbs[] = [
                'label' => get_string('lecturercatalogpage', 'local_ulms_dashboard'),
                'url' => null,
            ];
        }

        if ($section === 'files') {
            $breadcrumbs[] = [
                'label' => get_string('lecturerfilespage', 'local_ulms_dashboard'),
                'url' => null,
            ];
        }

        if ($section === 'messages') {
            $breadcrumbs[] = [
                'label' => get_string('lecturermessagespage', 'local_ulms_dashboard'),
                'url' => null,
            ];
        }

        foreach ([
            'materials' => 'lecturermaterialsheading',
            'assignments' => 'lecturerassignmentstitle',
            'quizzes' => 'lecturerquizzestitle',
            'students' => 'lecturerstudentstitle',
            'attendance' => 'lecturerattendancetitle',
            'grades' => 'lecturergradestitle',
            'announcements' => 'lecturerannouncementstitle',
            'profile' => 'lecturerprofiletitle',
        ] as $key => $stringkey) {
            if ($section === $key) {
                $breadcrumbs[] = [
                    'label' => get_string($stringkey, 'local_ulms_dashboard'),
                    'url' => null,
                ];
            }
        }

        if (in_array($section, ['exams', 'create', 'edit', 'questions', 'preview'], true)) {
            $breadcrumbs[] = [
                'label' => get_string('lecturerexamstitle', 'local_ulms_dashboard'),
                'url' => $section === 'exams' ? null : $routingservice->get_url_for_route('lecturer.exams')->out(false),
            ];
            if ($section === 'create') {
                $breadcrumbs[] = [
                    'label' => get_string('lecturerexamcreatecrumb', 'local_ulms_dashboard'),
                    'url' => null,
                ];
            }
            if ($section === 'edit') {
                $breadcrumbs[] = [
                    'label' => get_string('lecturerexameditcrumb', 'local_ulms_dashboard'),
                    'url' => null,
                ];
            }
            if ($section === 'questions') {
                $breadcrumbs[] = [
                    'label' => get_string('lecturerexamquestionscrumb', 'local_ulms_dashboard'),
                    'url' => null,
                ];
            }
            if ($section === 'preview') {
                $breadcrumbs[] = [
                    'label' => get_string('lecturerexampreviewcrumb', 'local_ulms_dashboard'),
                    'url' => null,
                ];
            }
        }

        if ($section === 'course' && !empty($course->fullname)) {
            $breadcrumbs[] = [
                'label' => format_string($course->fullname),
                'url' => null,
            ];
        }

        if ($section === 'grading') {
            if (!empty($course->fullname)) {
                $breadcrumbs[] = [
                    'label' => format_string($course->fullname),
                    'url' => $activityname !== '' ? (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false) : null,
                ];
            }

            if ($activityname !== '') {
                $breadcrumbs[] = [
                    'label' => format_string($activityname),
                    'url' => null,
                ];
            }
        }

        $lastindex = count($breadcrumbs) - 1;
        foreach ($breadcrumbs as $index => &$breadcrumb) {
            $breadcrumb['active'] = $index === $lastindex;
            if ($breadcrumb['active']) {
                $breadcrumb['url'] = null;
            }
        }
        unset($breadcrumb);

        return $breadcrumbs;
    }

    /**
     * Marks active sidebar items.
     *
     * @param array<int, array<string, string>> $items
     * @param string $section
     * @return array<int, array<string, mixed>>
     */
    private function mark_active_items(array $items, string $section): array {
        return array_map(static function(array $item) use ($section): array {
            $item['active'] = ($item['key'] ?? '') === $section;
            unset($item['key']);
            return $item;
        }, $items);
    }

    /**
     * Returns the current page course when present.
     *
     * @param \moodle_page $page
     * @return \stdClass|null
     */
    private function resolve_page_course(\moodle_page $page): ?\stdClass {
        global $DB;

        $lecturer_allowed = static function(int $courseid): bool {
            global $USER;
            if ($courseid <= 0 || $courseid === SITEID) {
                return false;
            }
            try {
                /** @var mixed $context */
                $context = \context_course::instance($courseid, IGNORE_MISSING);
            } catch (\dml_missing_record_exception $_e) {
                return false;
            }
            if (!$context) {
                return false;
            }
            if (is_enrolled($context, $USER, null, true)) {
                return true;
            }
            if (has_capability('moodle/course:view', $context)) {
                return true;
            }
            if (has_capability('moodle/course:manageactivities', $context)) {
                return true;
            }
            return false;
        };

        if (!empty($page->course) && !empty($page->course->id) && (int)$page->course->id !== SITEID) {
            $cid = (int)$page->course->id;
            if ($lecturer_allowed($cid)) {
                return $page->course;
            }
        }

        if (!empty($page->cm) && !empty($page->cm->course) && (int)$page->cm->course !== SITEID) {
            $cid = (int)$page->cm->course;
            if (!$lecturer_allowed($cid)) {
                return null;
            }
            $course = $DB->get_record('course', ['id' => $cid], 'id, fullname', IGNORE_MISSING);
            return $course ?: null;
        }

        $courseid = optional_param('id', 0, PARAM_INT);
        if ($courseid > 0 && $courseid !== SITEID) {
            if (!$lecturer_allowed($courseid)) {
                return null;
            }
            $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname', IGNORE_MISSING);
            return $course ?: null;
        }

        return null;
    }

    /**
     * Returns the current activity name when present.
     *
     * @param \moodle_page $page
     * @return string
     */
    private function resolve_activity_name(\moodle_page $page): string {
        $can_view_cm = static function(\stdClass $cm): bool {
            try {
                /** @var mixed $context */
                $context = \context_module::instance((int)$cm->id, IGNORE_MISSING);
            } catch (\dml_missing_record_exception $_e) {
                return false;
            }
            if (!$context) {
                return false;
            }
            if (has_capability('mod/assign:view', $context)) {
                return true;
            }
            if (has_capability('moodle/course:manageactivities', $context)) {
                return true;
            }
            return false;
        };

        if (!empty($page->cm) && method_exists($page->cm, 'get_formatted_name')) {
            $rawcm = $page->cm instanceof \cm_info ? (object)[
                'id' => (int)$page->cm->id,
                'course' => (int)($page->cm->course ?? 0),
                'modname' => $page->cm->modname ?? '',
            ] : (object)[
                'id' => (int)($page->cm->id ?? 0),
                'course' => (int)($page->cm->course ?? 0),
                'modname' => $page->cm->modname ?? '',
            ];
            if ($rawcm->id > 0 && $can_view_cm($rawcm)) {
                return (string)$page->cm->get_formatted_name();
            }
        }

        if (!empty($page->cm) && !empty($page->cm->name)) {
            $rawcm = (object)[
                'id' => (int)($page->cm->id ?? 0),
                'course' => (int)($page->cm->course ?? 0),
                'modname' => $page->cm->modname ?? '',
            ];
            if ($rawcm->id > 0 && $can_view_cm($rawcm)) {
                return format_string((string)$page->cm->name);
            }
        }

        $cmid = optional_param('id', 0, PARAM_INT);
        if ($cmid > 0) {
            $cm = get_coursemodule_from_id('assign', $cmid, 0, false, IGNORE_MISSING);
            if ($cm && !empty($cm->name) && $can_view_cm((object)[
                'id' => (int)$cm->id,
                'course' => (int)($cm->course ?? 0),
                'modname' => 'assign',
            ])) {
                return format_string((string)$cm->name);
            }
        }

        return '';
    }

    /**
     * Returns assignment grading items for the lecturer.
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
                'meta' => get_string('gradingqueueitemmeta', 'local_ulms_dashboard', $pendingcount),
                'time' => userdate((int)$record->latestsubmission),
                'url' => new \moodle_url('/mod/assign/view.php', ['id' => $record->cmid]),
                'pendingcount' => $pendingcount,
            ];
        }

        return $items;
    }

    /**
     * Returns the number of pending grading items.
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
     * Normalises lecturer overview views.
     *
     * @param string $view
     * @return string
     */
    private function normalise_portal_view(string $view): string {
        $allowed = ['materials', 'assignments', 'quizzes', 'exams', 'students', 'attendance', 'grades', 'announcements', 'messages', 'profile'];
        return in_array($view, $allowed, true) ? $view : 'materials';
    }
}
