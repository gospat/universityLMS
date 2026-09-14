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
require_once($CFG->libdir . '/gradelib.php');

/**
 * Provides consistent navigation, header, and grade data for student portal pages.
 */
class student_portal_service {
    /**
     * SINGLE SOURCE OF TRUTH for every student portal path.
     *
     * Structure: path => ['section' => shellSidebarKey, 'header' => headerContextKey]
     * The three public methods (path ownership check, shell sidebar selection,
     * header context builder) all derive from this list. New student-portal
     * pages MUST be added here exactly once; this guarantees the three layers
     * never drift apart and the unified theme shell is always applied.
     *
     * @return array<string, array{section: string, header: string}>
     */
    private function get_canonical_student_portal_routes(): array {
        $routingservice = new \local_ulms_auth\local\service\landing_page_service();
        $n = static function(string $route) use ($routingservice): string {
            return $routingservice->normalise_path($routingservice->get_path_for_route($route));
        };
        return [
            $n('student.dashboard') => ['section' => 'dashboard', 'header' => 'dashboard'],
            $n('student.courses') => ['section' => 'courses', 'header' => 'courses'],
            $n('student.catalog') => ['section' => 'catalog', 'header' => 'catalog'],
            $n('student.assignments') => ['section' => 'assignments', 'header' => 'assignments'],
            $n('student.quizzes') => ['section' => 'quizzes', 'header' => 'quizzes'],
            $n('student.exams') => ['section' => 'exams', 'header' => 'exams'],
            $n('student.examstake') => ['section' => 'exams', 'header' => 'take'],
            $n('student.examsresult') => ['section' => 'exams', 'header' => 'result'],
            $n('student.grades') => ['section' => 'grades', 'header' => 'grades'],
            $n('student.progress') => ['section' => 'progress', 'header' => 'progress'],
            $n('student.timetable') => ['section' => 'timetable', 'header' => 'timetable'],
            $n('student.announcements') => ['section' => 'announcements', 'header' => 'announcements'],
            $n('student.messages') => ['section' => 'messages', 'header' => 'messages'],
            $n('student.profile') => ['section' => 'profile', 'header' => 'profile'],
            '/my/courses.php' => ['section' => 'courses', 'header' => 'courses'],
            '/course/view.php' => ['section' => 'courses', 'header' => 'course'],
            '/course/section.php' => ['section' => 'courses', 'header' => 'course'],
            '/mod/assign/view.php' => ['section' => 'assignments', 'header' => 'assignments'],
            '/mod/quiz/view.php' => ['section' => 'quizzes', 'header' => 'quizzes'],
            '/mod/forum/view.php' => ['section' => 'announcements', 'header' => 'announcements'],
            '/mod/forum/discuss.php' => ['section' => 'announcements', 'header' => 'announcements'],
            '/calendar/view.php' => ['section' => 'timetable', 'header' => 'timetable'],
            '/grade/report/overview/index.php' => ['section' => 'grades', 'header' => 'grades'],
            '/grade/report/user/index.php' => ['section' => 'grades', 'header' => 'coursegrades'],
            '/message/index.php' => ['section' => 'messages', 'header' => 'messages'],
            '/user/profile.php' => ['section' => 'profile', 'header' => 'profile'],
            '/user/edit.php' => ['section' => 'profile', 'header' => 'profile'],
            '/user/preferences.php' => ['section' => 'profile', 'header' => 'preferences'],
            '/user/files.php' => ['section' => 'profile', 'header' => 'privatefiles'],
        ];
    }

    /**
     * Exposes the canonical route list for automated audit / guard checks.
     *
     * @return array<string, array{section: string, header: string}>
     */
    public function get_canonical_student_portal_routes_for_audit(): array {
        return $this->get_canonical_student_portal_routes();
    }

    /**
     * Validates that the SSOT is internally consistent. Used by the
     * production-readiness CLI script to catch regressions.
     *
     * @return array{ok: bool, errors: string[]}
     */
    public function validate_canonical_route_consistency(): array {
        $routes = $this->get_canonical_student_portal_routes();
        $knownsections = ['dashboard', 'courses', 'course', 'catalog', 'assignments', 'quizzes', 'exams', 'take', 'result',
            'grades', 'coursegrades', 'progress', 'timetable', 'announcements', 'messages',
            'profile', 'preferences', 'privatefiles'];
        $errors = [];
        foreach ($routes as $path => $meta) {
            if (!is_string($path) || $path === '') {
                $errors[] = 'Empty path key in canonical student routes';
                continue;
            }
            if (empty($meta['section'])) {
                $errors[] = "Missing section for $path";
            } else if (!in_array($meta['section'], $knownsections, true)) {
                $errors[] = "Unknown section '{$meta['section']}' for $path";
            }
            if (empty($meta['header'])) {
                $errors[] = "Missing header for $path";
            } else if (!in_array($meta['header'], $knownsections, true)) {
                $errors[] = "Unknown header '{$meta['header']}' for $path";
            }
        }
        return ['ok' => empty($errors), 'errors' => $errors];
    }

    /**
     * Returns whether the current user is using the student portal.
     *
     * @return bool
     */
    public function is_student_portal_user(): bool {
        global $USER;

        if (!isloggedin() || isguestuser()) {
            return false;
        }

        $routingservice = new \local_ulms_auth\local\service\landing_page_service();
        $roleshortname = $routingservice->get_role_shortname_for_user($USER);

        return in_array($roleshortname, ['student', 'user'], true);
    }

    /**
     * Returns whether the supplied page belongs to the student portal journey.
     *
     * @param \moodle_page $page
     * @return bool
     */
    public function is_student_portal_page(\moodle_page $page): bool {
        if (!$this->is_student_portal_user()) {
            return false;
        }

        $path = $page->url ? $page->url->get_path() : '';
        $path = (new \local_ulms_auth\local\service\landing_page_service())->normalise_path($path);
        return array_key_exists($path, $this->get_canonical_student_portal_routes());
    }

    /**
     * Returns consistent header context for a live Moodle page.
     *
     * @param \moodle_page $page
     * @return array|null
     */
    public function get_header_context_for_page(\moodle_page $page): ?array {
        if (!$this->is_student_portal_page($page)) {
            return null;
        }

        $routingservice = new \local_ulms_auth\local\service\landing_page_service();
        $path = $page->url ? $page->url->get_path() : '';
        $path = $routingservice->normalise_path($path);
        $course = $this->resolve_page_course($page);
        $routes = $this->get_canonical_student_portal_routes();

        if (!isset($routes[$path])) {
            return null;
        }

        $headerseed = $routes[$path]['header'];
        $headerkey = match ($path) {
            '/student/catalog' => $this->normalise_portal_view(optional_param('view', $headerseed, PARAM_ALPHA)),
            '/student/assignments' => $this->normalise_portal_view(optional_param('view', $headerseed, PARAM_ALPHA)),
            '/student/quizzes' => $this->normalise_portal_view(optional_param('view', $headerseed, PARAM_ALPHA)),
            '/student/progress' => $this->normalise_portal_view(optional_param('view', $headerseed, PARAM_ALPHA)),
            '/student/timetable' => $this->normalise_portal_view(optional_param('view', $headerseed, PARAM_ALPHA)),
            '/student/announcements' => $this->normalise_portal_view(optional_param('view', $headerseed, PARAM_ALPHA)),
            '/student/messages' => $this->normalise_portal_view(optional_param('view', $headerseed, PARAM_ALPHA)),
            '/student/profile' => $this->normalise_portal_view(optional_param('view', $headerseed, PARAM_ALPHA)),
            default => $headerseed,
        };

        return $this->get_header_context_for_section($headerkey, $course);
    }

    /**
     * Returns header context for a student portal section.
     *
     * @param string $section
     * @param \stdClass|null $course
     * @return array
     */
    public function get_header_context_for_section(string $section, ?\stdClass $course = null): array {
        $titles = [
            'dashboard' => get_string('studentdashboard', 'local_ulms_dashboard'),
            'courses' => get_string('studentcoursespage', 'local_ulms_dashboard'),
            'course' => format_string($course->fullname ?? get_string('studentcoursespage', 'local_ulms_dashboard')),
            'catalog' => get_string('studentcatalogtitle', 'local_ulms_dashboard'),
            'assignments' => get_string('studentassignmentstitle', 'local_ulms_dashboard'),
            'quizzes' => get_string('studentquizzestitle', 'local_ulms_dashboard'),
            'exams' => get_string('studentexamstitle', 'local_ulms_dashboard'),
            'take' => get_string('studentexamstitle', 'local_ulms_dashboard'),
            'result' => get_string('studentexamstitle', 'local_ulms_dashboard'),
            'grades' => get_string('studentgradespage', 'local_ulms_dashboard'),
            'coursegrades' => get_string('studentcoursegradespage', 'local_ulms_dashboard'),
            'progress' => get_string('studentprogresstitle', 'local_ulms_dashboard'),
            'timetable' => get_string('studenttimetabletitle', 'local_ulms_dashboard'),
            'announcements' => get_string('studentannouncementstitle', 'local_ulms_dashboard'),
            'messages' => get_string('studentmessagespage', 'local_ulms_dashboard'),
            'profile' => get_string('studentprofiletitle', 'local_ulms_dashboard'),
            'preferences' => get_string('preferences', 'core'),
            'privatefiles' => get_string('privatefileslink', 'local_ulms_dashboard'),
        ];

        $meta = [
            'dashboard' => get_string('studentdashboarddesc', 'local_ulms_dashboard'),
            'courses' => get_string('studentcoursespagedesc', 'local_ulms_dashboard'),
            'course' => get_string('studentcoursepagedesc', 'local_ulms_dashboard', $course->fullname ?? ''),
            'catalog' => get_string('studentcatalogdesc', 'local_ulms_dashboard'),
            'assignments' => get_string('studentassignmentsdesc', 'local_ulms_dashboard'),
            'quizzes' => get_string('studentquizzesdesc', 'local_ulms_dashboard'),
            'exams' => get_string('studentexamsdesc', 'local_ulms_dashboard'),
            'take' => get_string('studentexamsdesc', 'local_ulms_dashboard'),
            'result' => get_string('studentexamsdesc', 'local_ulms_dashboard'),
            'grades' => get_string('studentgradespagedesc', 'local_ulms_dashboard'),
            'coursegrades' => get_string('studentcoursegradespagedesc', 'local_ulms_dashboard', $course->fullname ?? ''),
            'progress' => get_string('studentprogressdesc', 'local_ulms_dashboard'),
            'timetable' => get_string('studenttimetabledesc', 'local_ulms_dashboard'),
            'announcements' => get_string('studentannouncementsdesc', 'local_ulms_dashboard'),
            'messages' => get_string('studentmessagespagedesc', 'local_ulms_dashboard'),
            'profile' => get_string('studentprofiledesc', 'local_ulms_dashboard'),
            'preferences' => get_string('portalprofilepreferences', 'local_ulms_dashboard'),
            'privatefiles' => get_string('privatefileslinkdesc', 'local_ulms_dashboard'),
        ];

        $eyebrowmap = [
            'dashboard' => get_string('student.dashboard.eyebrow', 'local_ulms_dashboard'),
            'courses' => get_string('student.courses.eyebrow', 'local_ulms_dashboard'),
            'course' => get_string('student.courses.eyebrow', 'local_ulms_dashboard'),
            'catalog' => get_string('student.catalog.eyebrow', 'local_ulms_dashboard'),
            'assignments' => get_string('student.assignments.eyebrow', 'local_ulms_dashboard'),
            'quizzes' => get_string('student.quizzes.eyebrow', 'local_ulms_dashboard'),
            'exams' => get_string('student.exams.eyebrow', 'local_ulms_dashboard'),
            'take' => get_string('student.take.eyebrow', 'local_ulms_dashboard'),
            'result' => get_string('student.result.eyebrow', 'local_ulms_dashboard'),
            'grades' => get_string('student.progress.eyebrow', 'local_ulms_dashboard'),
            'coursegrades' => get_string('student.progress.eyebrow', 'local_ulms_dashboard'),
            'progress' => get_string('student.progress.eyebrow', 'local_ulms_dashboard'),
            'timetable' => get_string('student.timetable.eyebrow', 'local_ulms_dashboard'),
            'announcements' => get_string('student.announcements.eyebrow', 'local_ulms_dashboard'),
            'messages' => get_string('student.messages.eyebrow', 'local_ulms_dashboard'),
            'profile' => get_string('student.profile.eyebrow', 'local_ulms_dashboard'),
            'preferences' => get_string('student.profile.eyebrow', 'local_ulms_dashboard'),
            'privatefiles' => get_string('student.profile.eyebrow', 'local_ulms_dashboard'),
        ];

        return [
            'eyebrow' => $eyebrowmap[$section] ?? get_string('studentportaleyebrow', 'local_ulms_dashboard'),
            'navigationaria' => get_string('studentportalnavigation', 'local_ulms_dashboard'),
            'title' => $titles[$section] ?? get_string('studentdashboard', 'local_ulms_dashboard'),
            'meta' => $meta[$section] ?? '',
            'showtitle' => true,
            'hasbreadcrumbs' => true,
            'breadcrumbs' => $this->get_breadcrumbs_for_section($section, $course),
            'backaction' => $this->get_back_action_for_section($section),
            'hasnavitems' => true,
            'navitems' => $this->get_navigation_items($section),
        ];
    }

    /**
     * Returns a history-aware back action for student pages.
     *
     * @param string $section
     * @return array<string, mixed>|null
     */
    public function get_back_action_for_section(string $section): ?array {
        $routingservice = new \local_ulms_auth\local\service\landing_page_service();
        $fallbackroute = match ($section) {
            'dashboard' => null,
            'course' => 'student.courses',
            'coursegrades' => 'student.grades',
            'preferences', 'privatefiles' => 'student.profile',
            default => 'student.dashboard',
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
     * Returns shell navigation context for the current student page.
     *
     * @param \moodle_page $page
     * @return array|null
     */
    public function get_shell_context_for_page(\moodle_page $page): ?array {
        if (!$this->is_student_portal_user()) {
            return null;
        }

        $layout = $page->pagelayout ?? 'standard';
        if (in_array($layout, ['login', 'secure', 'maintenance'], true)) {
            return null;
        }

        $routingservice = new \local_ulms_auth\local\service\landing_page_service();
        $path = $page->url ? $page->url->get_path() : '';
        $path = $routingservice->normalise_path($path);
        $routes = $this->get_canonical_student_portal_routes();

        if (isset($routes[$path])) {
            $snapshot = $routes[$path]['section'];
        } else {
            $snapshot = 'dashboard';
        }
        $section = match ($path) {
            '/student/catalog' => $this->normalise_portal_view(optional_param('view', $snapshot, PARAM_ALPHA)),
            '/student/assignments' => $this->normalise_portal_view(optional_param('view', $snapshot, PARAM_ALPHA)),
            '/student/quizzes' => $this->normalise_portal_view(optional_param('view', $snapshot, PARAM_ALPHA)),
            '/student/progress' => $this->normalise_portal_view(optional_param('view', $snapshot, PARAM_ALPHA)),
            '/student/timetable' => $this->normalise_portal_view(optional_param('view', $snapshot, PARAM_ALPHA)),
            '/student/announcements' => $this->normalise_portal_view(optional_param('view', $snapshot, PARAM_ALPHA)),
            '/student/profile' => $this->normalise_portal_view(optional_param('view', $snapshot, PARAM_ALPHA)),
            default => $snapshot,
        };

        global $USER, $CFG;
        $routingservice = new \local_ulms_auth\local\service\landing_page_service();

        $bannercta1 = null;
        $bannercta2 = null;
        try {
            $bannercta1 = ['label' => get_string('studentnavcourses', 'local_ulms_dashboard'), 'href' => $routingservice->get_url_for_route('student.courses')->out(false)];
            $bannercta2 = ['label' => get_string('studentnavassignments', 'local_ulms_dashboard'), 'href' => $routingservice->get_url_for_route('student.assignments')->out(false)];
        } catch (\Exception $e) {
            $bannercta1 = null;
            $bannercta2 = null;
        }

        $studentfullname = fullname($USER);

        $breadcrumbs = [[
            'label' => get_string('studentdashboard', 'local_ulms_dashboard'),
            'url' => null,
            'last' => true,
        ]];

        $banner_eyebrow = @get_string('studentportaleyebrow', 'local_ulms_dashboard');
        if (!is_string($banner_eyebrow) || $banner_eyebrow === '' || str_contains($banner_eyebrow, '[[')) $banner_eyebrow = 'Student portal';
        $banner_title = @get_string('studentwelcome', 'local_ulms_dashboard', $studentfullname);
        if (!is_string($banner_title) || $banner_title === '' || str_contains($banner_title, '[[')) $banner_title = 'Welcome back, ' . $studentfullname;
        $banner_meta = @get_string('studentdashboarddesc', 'local_ulms_dashboard');
        if (!is_string($banner_meta) || $banner_meta === '' || str_contains($banner_meta, '[[')) $banner_meta = 'Your enrolled courses, upcoming deadlines, notifications, and progress overview in one place.';

        $headercontext = [
            'banner_eyebrow' => $banner_eyebrow,
            'banner_title' => $banner_title,
            'banner_meta' => $banner_meta,
            'banner_cta1' => $bannercta1,
            'banner_cta2' => $bannercta2,
            'breadcrumbs' => $breadcrumbs,
        ];

        $dashboardservice = new dashboard_service();
        $snapshot = $dashboardservice->get_current_user_snapshot();
        $iconbook = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>';
        $iconbell = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>';
        $iconclock = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
        $iconcheck = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';

        $coursecnt = $snapshot['coursecount'] ?? 0;
        $notcnt = $snapshot['notificationcount'] ?? 0;
        $deadcnt = is_array($snapshot['deadlines'] ?? null) ? count($snapshot['deadlines']) : 0;
        $comppct = is_array($snapshot['completion'] ?? null) ? ($snapshot['completion']['percent'] ?? 0) : 0;

        $summarycards = [
            ['eyebrow' => get_string('summarycard.student.courses.eyebrow', 'local_ulms_dashboard'), 'number' => (string)$coursecnt, 'desc' => get_string('summarycard.student.courses.desc', 'local_ulms_dashboard'), 'mini_icon' => $iconbook],
            ['eyebrow' => get_string('summarycard.student.notifications.eyebrow', 'local_ulms_dashboard'), 'number' => (string)$notcnt, 'desc' => get_string('summarycard.student.notifications.desc', 'local_ulms_dashboard'), 'mini_icon' => $iconbell],
            ['eyebrow' => get_string('summarycard.student.deadlines.eyebrow', 'local_ulms_dashboard'), 'number' => (string)$deadcnt, 'desc' => get_string('summarycard.student.deadlines.desc', 'local_ulms_dashboard'), 'mini_icon' => $iconclock],
            ['eyebrow' => get_string('summarycard.student.progress.eyebrow', 'local_ulms_dashboard'), 'number' => $comppct . '%', 'desc' => get_string('summarycard.student.progress.desc', 'local_ulms_dashboard'), 'mini_icon' => $iconcheck],
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
            $fallbackurls = [
                ['t' => get_string('fallback.studentdashboard.title', 'local_ulms_dashboard'), 's' => get_string('fallback.studentdashboard.subtitle', 'local_ulms_dashboard'), 'u' => $routingservice->get_dashboard_url_for_current_user()->out(false), 'i' => $iconcheck],
                ['t' => get_string('fallback.mycourses.title', 'local_ulms_dashboard'), 's' => get_string('fallback.mycourses.subtitle', 'local_ulms_dashboard'), 'u' => $routingservice->get_url_for_route('student.courses')->out(false), 'i' => $iconbook],
                ['t' => get_string('fallback.coursecatalogue.title', 'local_ulms_dashboard'), 's' => get_string('fallback.coursecatalogue.subtitle', 'local_ulms_dashboard'), 'u' => $routingservice->get_url_for_route('student.catalog')->out(false), 'i' => $iconbook],
                ['t' => get_string('fallback.assignments.title', 'local_ulms_dashboard'), 's' => get_string('fallback.assignments.subtitle', 'local_ulms_dashboard'), 'u' => $routingservice->get_url_for_route('student.assignments')->out(false), 'i' => $iconclock],
            ];
            foreach ($fallbackurls as $fb) {
                if (count($quickaccess) >= 4) break;
                $has = false;
                foreach ($quickaccess as $qa) if ($qa['title'] === $fb['t']) { $has = true; break; }
                if (!$has) $quickaccess[] = ['title' => $fb['t'], 'subtitle' => $fb['s'], 'icon' => $fb['i'], 'url' => $fb['u']];
            }
        }

        return [
            'eyebrow' => $banner_eyebrow,
            'portalname' => get_string('studentportalshelltitle', 'local_ulms_dashboard'),
            'navigationaria' => get_string('studentportalnavigation', 'local_ulms_dashboard'),
            'currentuserrole' => get_string('studentportalshellrole', 'local_ulms_dashboard'),
            'navgroups' => $navgroups,
            'headercontext' => $headercontext,
            'summarycards' => $summarycards,
            'quickaccess' => $quickaccess,
        ];
    }

    /**
     * Returns the data required to render the student courses page.
     *
     * @return array
     */
    public function get_student_courses_page_data(): array {
        $dashboardservice = new dashboard_service();
        $snapshot = $dashboardservice->get_current_user_snapshot();
        $courses = [];

        foreach ($snapshot['courses'] as $course) {
            $courses[] = [
                'fullname' => format_string($course['fullname']),
                'courseurl' => $course['url']->out(false),
                'reporturl' => (new \moodle_url('/grade/report/user/index.php', ['id' => $course['id']]))->out(false),
                'meta' => get_string('studentcoursecarddesc', 'local_ulms_dashboard', $course['fullname']),
            ];
        }

        return [
            'header' => $this->get_header_context_for_section('courses'),
            'summarycards' => [
                [
                    'label' => get_string('coursecountsummary', 'local_ulms_dashboard'),
                    'value' => (string)$snapshot['coursecount'],
                    'description' => get_string('studentcoursescountdesc', 'local_ulms_dashboard'),
                ],
                [
                    'label' => get_string('notificationssummary', 'local_ulms_dashboard'),
                    'value' => (string)$snapshot['notificationcount'],
                    'description' => get_string('studentnotificationsdesc', 'local_ulms_dashboard'),
                ],
                [
                    'label' => get_string('upcomingdeadlinessummary', 'local_ulms_dashboard'),
                    'value' => (string)count($snapshot['deadlines']),
                    'description' => get_string('studentdeadlinesdesc', 'local_ulms_dashboard'),
                ],
                [
                    'label' => get_string('completionsummary', 'local_ulms_dashboard'),
                    'value' => $snapshot['completion']['percent'] . '%',
                    'description' => get_string('studentcompletiondesc', 'local_ulms_dashboard'),
                ],
            ],
            'hascourses' => !empty($courses),
            'courses' => $courses,
        ];
    }

    /**
     * Returns the data required to render the student grades page.
     *
     * @return array
     */
    public function get_student_grades_page_data(): array {
        $dashboardservice = new dashboard_service();
        $snapshot = $dashboardservice->get_current_user_snapshot();
        $courses = $snapshot['courses'];
        $gradecourses = $this->get_grade_courses($courses);
        $coursepercentages = array_values(array_filter(array_map(
            static fn(array $course): ?float => $course['totalpercent'],
            $gradecourses
        ), static fn(?float $percent): bool => $percent !== null));
        $gradedassessments = 0;
        $assessmentcount = 0;

        foreach ($gradecourses as $course) {
            $assessmentcount += count($course['assessments']);
            $gradedassessments += count(array_filter(
                $course['assessments'],
                static fn(array $assessment): bool => $assessment['isgraded']
            ));
        }

        $averagecoursegrade = !empty($coursepercentages)
            ? format_float(array_sum($coursepercentages) / count($coursepercentages), 1) . '%'
            : get_string('studentnotgradedlabel', 'local_ulms_dashboard');

        return [
            'header' => $this->get_header_context_for_section('grades'),
            'summarycards' => [
                [
                    'label' => get_string('studentgradesummarycourses', 'local_ulms_dashboard'),
                    'value' => (string)count($gradecourses),
                    'description' => get_string('studentgradesummarycoursesdesc', 'local_ulms_dashboard'),
                ],
                [
                    'label' => get_string('studentgradesummaryitems', 'local_ulms_dashboard'),
                    'value' => (string)$gradedassessments . ' / ' . (string)$assessmentcount,
                    'description' => get_string('studentgradesummaryitemsdesc', 'local_ulms_dashboard'),
                ],
                [
                    'label' => get_string('studentgradesummaryaverage', 'local_ulms_dashboard'),
                    'value' => $averagecoursegrade,
                    'description' => get_string('studentgradesummaryaveragedesc', 'local_ulms_dashboard'),
                ],
                [
                    'label' => get_string('completionsummary', 'local_ulms_dashboard'),
                    'value' => $snapshot['completion']['percent'] . '%',
                    'description' => get_string('studentcompletiondesc', 'local_ulms_dashboard'),
                ],
            ],
            'hascourses' => !empty($gradecourses),
            'courses' => $gradecourses,
        ];
    }

    /**
     * Returns the navigation tabs shared across student pages.
     *
     * @param string $section
     * @return array
     */
    public function get_navigation_items(string $section): array {
        $routingservice = new \local_ulms_auth\local\service\landing_page_service();
        $activesection = match ($section) {
            'course' => 'courses',
            'coursegrades' => 'grades',
            'preferences', 'privatefiles' => 'profile',
            default => $section,
        };

        $items = [
            'dashboard' => [
                'label' => get_string('studentnavdashboard', 'local_ulms_dashboard'),
                'url' => $routingservice->get_dashboard_url_for_current_user()->out(false),
            ],
            'courses' => [
                'label' => get_string('studentnavcourses', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('student.courses')->out(false),
            ],
            'grades' => [
                'label' => get_string('studentnavgrades', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('student.grades')->out(false),
            ],
            'catalog' => [
                'label' => get_string('studentnavcatalog', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('student.catalog')->out(false),
            ],
            'assignments' => [
                'label' => get_string('studentnavassignments', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('student.assignments')->out(false),
            ],
            'quizzes' => [
                'label' => get_string('studentnavquizzes', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('student.quizzes')->out(false),
            ],
            'exams' => [
                'label' => get_string('studentnavexams', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('student.exams')->out(false),
            ],
            'progress' => [
                'label' => get_string('studentnavprogress', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('student.progress')->out(false),
            ],
            'timetable' => [
                'label' => get_string('studentnavtimetable', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('student.timetable')->out(false),
            ],
            'announcements' => [
                'label' => get_string('studentnavannouncements', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('student.announcements')->out(false),
            ],
            'messages' => [
                'label' => get_string('studentnavmessages', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('student.messages')->out(false),
            ],
            'profile' => [
                'label' => get_string('studentnavprofile', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('student.profile')->out(false),
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
     * Returns grouped student sidebar navigation.
     *
     * @param string $section
     * @return array<int, array<string, mixed>>
     */
    public function get_navigation_groups(string $section): array {
        $routingservice = new \local_ulms_auth\local\service\landing_page_service();
        $accountsection = in_array($section, ['profile', 'preferences', 'privatefiles'], true) ? 'profile' : $section;
        $icondashboard = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>';
        $iconcourses = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>';
        $iconcatalog = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="m15.5 9.5a3.5 3.5 0 1 1-7 0 3.5 3.5 0 0 1 7 0z"/><path d="M12 3v2"/><path d="M12 19v2"/><path d="m5.6 6.2 1.4 1.4"/><path d="m17 16.4 1.4 1.4"/><path d="M3 12h2"/><path d="M19 12h2"/><path d="m5.6 17.8 1.4-1.4"/><path d="m17 7.6 1.4-1.4"/></svg>';
        $iconassignments = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>';
        $iconquizzes = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9.5 2A2.5 2.5 0 0 1 12 4.5v15a2.5 2.5 0 0 1-4.96.44 2.5 2.5 0 0 1-2.96-3.08 3 3 0 0 1-.34-5.58 2.5 2.5 0 0 1 1.32-4.24 2.5 2.5 0 0 1 1.98-3A2.5 2.5 0 0 1 9.5 2Z"/><path d="M14.5 17.5a2.5 2.5 0 0 1 4.96.44 2.5 2.5 0 0 1 2.96-3.08 3 3 0 0 0 .34-5.58 2.5 2.5 0 0 0-1.32-4.24 2.5 2.5 0 0 0-1.98-3A2.5 2.5 0 0 0 14.5 6.5"/></svg>';
        $iconexams = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M8 2v3"/><path d="M16 2v3"/><path d="M3 10h18"/><path d="M7 14h4"/><path d="M7 17h10"/><path d="m15 14 1.5 1.5L19 13"/></svg>';
        $icongrades = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>';
        $iconprogress = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 3v18h18"/><path d="m7 14 4-3 3-3"/><path d="m12 18 4-4 4 4"/></svg>';
        $icontimetable = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>';
        $iconannouncements = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 11v2a1 1 0 0 0 1 1h2l4 3V7L6 10H4a1 1 0 0 0-1 1z"/><path d="M11.7 7a5 5 0 0 0 0 10"/><path d="M15 9.3a8 8 0 0 1 0 5.4"/></svg>';
        $iconmessages = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
        $iconprofile = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';

        return [
            [
                'heading' => get_string('studentnavgroupdashboard', 'local_ulms_dashboard'),
                'items' => $this->mark_active_items([
                    [
                        'key' => 'dashboard',
                        'label' => get_string('studentnavdashboard', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_dashboard_url_for_current_user()->out(false),
                        'icon' => $icondashboard,
                    ],
                ], $section),
            ],
            [
                'heading' => get_string('studentnavgrouplearning', 'local_ulms_dashboard'),
                'items' => $this->mark_active_items([
                    [
                        'key' => 'courses',
                        'label' => get_string('studentnavcourses', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('student.courses')->out(false),
                        'icon' => $iconcourses,
                    ],
                    [
                        'key' => 'catalog',
                        'label' => get_string('studentnavcatalog', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('student.catalog')->out(false),
                        'icon' => $iconcatalog,
                    ],
                    [
                        'key' => 'assignments',
                        'label' => get_string('studentnavassignments', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('student.assignments')->out(false),
                        'icon' => $iconassignments,
                    ],
                    [
                        'key' => 'quizzes',
                        'label' => get_string('studentnavquizzes', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('student.quizzes')->out(false),
                        'icon' => $iconquizzes,
                    ],
                    [
                        'key' => 'exams',
                        'label' => get_string('studentnavexams', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('student.exams')->out(false),
                        'icon' => $iconexams,
                    ],
                    [
                        'key' => 'grades',
                        'label' => get_string('studentnavgrades', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('student.grades')->out(false),
                        'icon' => $icongrades,
                    ],
                ], $section),
            ],
            [
                'heading' => get_string('studentnavgroupacademic', 'local_ulms_dashboard'),
                'items' => $this->mark_active_items([
                    [
                        'key' => 'progress',
                        'label' => get_string('studentnavprogress', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('student.progress')->out(false),
                        'icon' => $iconprogress,
                    ],
                    [
                        'key' => 'timetable',
                        'label' => get_string('studentnavtimetable', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('student.timetable')->out(false),
                        'icon' => $icontimetable,
                    ],
                ], $section),
            ],
            [
                'heading' => get_string('studentnavgroupcommunication', 'local_ulms_dashboard'),
                'items' => $this->mark_active_items([
                    [
                        'key' => 'announcements',
                        'label' => get_string('studentnavannouncements', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('student.announcements')->out(false),
                        'icon' => $iconannouncements,
                    ],
                    [
                        'key' => 'messages',
                        'label' => get_string('studentnavmessages', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('student.messages')->out(false),
                        'icon' => $iconmessages,
                    ],
                ], $section),
            ],
            [
                'heading' => get_string('studentnavgroupaccount', 'local_ulms_dashboard'),
                'items' => $this->mark_active_items([
                    [
                        'key' => 'profile',
                        'label' => get_string('studentnavprofile', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('student.profile')->out(false),
                        'icon' => $iconprofile,
                    ],
                ], $accountsection),
            ],
        ];
    }

    /**
     * Returns breadcrumb items for a student portal section.
     *
     * @param string $section
     * @param \stdClass|null $course
     * @return array
     */
    private function get_breadcrumbs_for_section(string $section, ?\stdClass $course = null): array {
        $routingservice = new \local_ulms_auth\local\service\landing_page_service();
        $breadcrumbs = [
            [
                'label' => get_string('studentdashboard', 'local_ulms_dashboard'),
                'url' => $routingservice->get_dashboard_url_for_current_user()->out(false),
            ],
        ];

        if (in_array($section, ['courses', 'course'], true)) {
            $breadcrumbs[] = [
                'label' => get_string('studentcoursespage', 'local_ulms_dashboard'),
                'url' => $section === 'courses'
                    ? null
                    : $routingservice->get_url_for_route('student.courses')->out(false),
            ];
        }

        if (in_array($section, ['grades', 'coursegrades'], true)) {
            $breadcrumbs[] = [
                'label' => get_string('studentgradespage', 'local_ulms_dashboard'),
                'url' => $section === 'grades'
                    ? null
                    : $routingservice->get_url_for_route('student.grades')->out(false),
            ];
        }

        if ($section === 'catalog') {
            $breadcrumbs[] = [
                'label' => get_string('studentcatalogtitle', 'local_ulms_dashboard'),
                'url' => null,
            ];
        }

        if ($section === 'assignments') {
            $breadcrumbs[] = [
                'label' => get_string('studentassignmentstitle', 'local_ulms_dashboard'),
                'url' => null,
            ];
        }

        if ($section === 'quizzes') {
            $breadcrumbs[] = [
                'label' => get_string('studentquizzestitle', 'local_ulms_dashboard'),
                'url' => null,
            ];
        }

        if ($section === 'exams' || $section === 'take' || $section === 'result') {
            $breadcrumbs[] = [
                'label' => get_string('studentexamstitle', 'local_ulms_dashboard'),
                'url' => $section === 'exams' ? null : $routingservice->get_url_for_route('student.exams')->out(false),
            ];
            if ($section === 'take') {
                $breadcrumbs[] = [
                    'label' => get_string('studentexamstakecrumb', 'local_ulms_dashboard'),
                    'url' => null,
                ];
            }
            if ($section === 'result') {
                $breadcrumbs[] = [
                    'label' => get_string('studentexamsresultcrumb', 'local_ulms_dashboard'),
                    'url' => null,
                ];
            }
        }

        if ($section === 'progress') {
            $breadcrumbs[] = [
                'label' => get_string('studentprogresstitle', 'local_ulms_dashboard'),
                'url' => null,
            ];
        }

        if ($section === 'timetable') {
            $breadcrumbs[] = [
                'label' => get_string('studenttimetabletitle', 'local_ulms_dashboard'),
                'url' => null,
            ];
        }

        if ($section === 'announcements') {
            $breadcrumbs[] = [
                'label' => get_string('studentannouncementstitle', 'local_ulms_dashboard'),
                'url' => null,
            ];
        }

        if ($section === 'messages') {
            $breadcrumbs[] = [
                'label' => get_string('studentmessagespage', 'local_ulms_dashboard'),
                'url' => null,
            ];
        }

        if ($section === 'profile') {
            $breadcrumbs[] = [
                'label' => get_string('studentprofiletitle', 'local_ulms_dashboard'),
                'url' => null,
            ];
        }

        if (in_array($section, ['preferences', 'privatefiles'], true)) {
            $breadcrumbs[] = [
                'label' => get_string('studentprofiletitle', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('student.profile')->out(false),
            ];
        }

        if ($section === 'preferences') {
            $breadcrumbs[] = [
                'label' => get_string('preferences', 'core'),
                'url' => null,
            ];
        }

        if ($section === 'privatefiles') {
            $breadcrumbs[] = [
                'label' => get_string('privatefileslink', 'local_ulms_dashboard'),
                'url' => null,
            ];
        }

        if ($section === 'course' && !empty($course->fullname)) {
            $breadcrumbs[] = [
                'label' => format_string($course->fullname),
                'url' => null,
            ];
        }

        if ($section === 'coursegrades' && !empty($course->fullname)) {
            $breadcrumbs[] = [
                'label' => format_string($course->fullname),
                'url' => null,
            ];
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
     * Returns the course object relevant to the current page, if any.
     *
     * @param \moodle_page $page
     * @return \stdClass|null
     */
    private function resolve_page_course(\moodle_page $page): ?\stdClass {
        global $DB;

        $enrolled_or_view = static function(int $courseid): bool {
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
            return false;
        };

        if (!empty($page->course) && !empty($page->course->id) && (int)$page->course->id !== SITEID) {
            $cid = (int)$page->course->id;
            if ($enrolled_or_view($cid)) {
                return $page->course;
            }
        }

        if (!empty($page->cm) && !empty($page->cm->course) && (int)$page->cm->course !== SITEID) {
            $cid = (int)$page->cm->course;
            if (!$enrolled_or_view($cid)) {
                return null;
            }
            $course = $DB->get_record('course', ['id' => $cid], 'id, fullname', IGNORE_MISSING);
            return $course ?: null;
        }

        $courseid = optional_param('id', 0, PARAM_INT);
        if ($courseid > 0 && $courseid !== SITEID) {
            if (!$enrolled_or_view($courseid)) {
                return null;
            }
            $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname', IGNORE_MISSING);
            return $course ?: null;
        }

        return null;
    }

    /**
     * Returns all course-grade data for the supplied student courses.
     *
     * @param array $courses
     * @return array
     */
    private function get_grade_courses(array $courses): array {
        global $DB, $USER;

        if (empty($courses)) {
            return [];
        }

        $coursemap = [];
        foreach ($courses as $course) {
            $coursemap[(int)$course['id']] = [
                'fullname' => format_string($course['fullname']),
                'courseurl' => $course['url']->out(false),
                'reporturl' => (new \moodle_url('/grade/report/user/index.php', ['id' => $course['id']]))->out(false),
                'totalgrade' => get_string('studentnotgradedlabel', 'local_ulms_dashboard'),
                'totalpercent' => null,
                'haspercent' => false,
                'assessments' => [],
            ];
        }

        $courseids = array_keys($coursemap);
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'courseid');
        $params['userid'] = (int)$USER->id;
        $params['gradetypenone'] = GRADE_TYPE_NONE;

        $records = $DB->get_recordset_sql(
            "SELECT gi.courseid,
                    gi.itemtype,
                    gi.itemmodule,
                    gi.itemname,
                    gi.grademax,
                    gi.sortorder,
                    gg.finalgrade
               FROM {grade_items} gi
          LEFT JOIN {grade_grades} gg
                 ON gg.itemid = gi.id
                AND gg.userid = :userid
              WHERE gi.courseid $insql
                AND gi.itemtype IN ('course', 'mod')
                AND gi.gradetype <> :gradetypenone
                AND gi.hidden = 0
           ORDER BY gi.courseid ASC,
                    CASE WHEN gi.itemtype = 'course' THEN 0 ELSE 1 END ASC,
                    gi.sortorder ASC",
            $params
        );

        foreach ($records as $record) {
            $courseid = (int)$record->courseid;
            if (!array_key_exists($courseid, $coursemap)) {
                continue;
            }

            if ($record->itemtype === 'course') {
                $coursemap[$courseid]['totalgrade'] = $this->format_grade_value($record->finalgrade, $record->grademax);
                $coursemap[$courseid]['totalpercent'] = $this->calculate_grade_percent($record->finalgrade, $record->grademax);
                $coursemap[$courseid]['haspercent'] = $coursemap[$courseid]['totalpercent'] !== null;
                continue;
            }

            $percent = $this->calculate_grade_percent($record->finalgrade, $record->grademax);
            $coursemap[$courseid]['assessments'][] = [
                'name' => format_string($record->itemname ?: $this->get_module_label((string)$record->itemmodule)),
                'meta' => $this->get_module_label((string)$record->itemmodule),
                'score' => $this->format_grade_value($record->finalgrade, $record->grademax),
                'percentlabel' => $percent !== null ? format_float($percent, 1) . '%' : get_string('studentnotgradedlabel', 'local_ulms_dashboard'),
                'isgraded' => $record->finalgrade !== null,
            ];
        }

        $records->close();

        return array_values($coursemap);
    }

    /**
     * Formats a grade value for display.
     *
     * @param float|null $grade
     * @param float|null $grademax
     * @return string
     */
    private function format_grade_value(?float $grade, ?float $grademax): string {
        if ($grade === null) {
            return get_string('studentnotgradedlabel', 'local_ulms_dashboard');
        }

        $formattedgrade = format_float($grade, 2);
        if ($grademax === null || (float)$grademax <= 0) {
            return $formattedgrade;
        }

        return $formattedgrade . ' / ' . format_float((float)$grademax, 2);
    }

    /**
     * Calculates a percentage from a grade/max pair.
     *
     * @param float|null $grade
     * @param float|null $grademax
     * @return float|null
     */
    private function calculate_grade_percent(?float $grade, ?float $grademax): ?float {
        if ($grade === null || $grademax === null || (float)$grademax <= 0) {
            return null;
        }

        return round(((float)$grade / (float)$grademax) * 100, 1);
    }

    /**
     * Returns a readable module label.
     *
     * @param string $module
     * @return string
     */
    private function get_module_label(string $module): string {
        if ($module === '') {
            return get_string('studentgradeassessmentheading', 'local_ulms_dashboard');
        }

        return ucwords(str_replace('_', ' ', $module));
    }

    /**
     * Normalises student overview views.
     *
     * @param string $view
     * @return string
     */
    private function normalise_portal_view(string $view): string {
        $allowed = ['catalog', 'assignments', 'quizzes', 'progress', 'timetable', 'announcements', 'messages', 'profile'];
        return in_array($view, $allowed, true) ? $view : 'catalog';
    }
}
