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

namespace local_ulms_auth\local\service;

defined('MOODLE_INTERNAL') || die();

/**
 * Determines role-based landing and portal routes for ULMS users.
 */
class landing_page_service {
    /**
     * Returns canonical public ULMS route definitions.
     *
     * The path values are intentionally clean, user-facing URLs. Internal plugin
     * scripts continue to exist and can redirect here for backward compatibility.
     *
     * @return array<string, array<string, mixed>>
     */
    private function get_route_definitions(): array {
        return [
            'public.landing' => ['path' => '/sign-in/'],
            'public.activate' => ['path' => '/sign-in/activate/'],
            'public.passwordreset' => ['path' => '/reset-password/'],

            'student.login' => ['path' => '/student/login/'],
            'student.passwordreset' => ['path' => '/student/password-reset/', 'params' => ['portal' => 'student']],
            'student.dashboard' => ['path' => '/student/'],
            'student.courses' => ['path' => '/student/courses'],
            'student.catalog' => ['path' => '/student/catalog', 'params' => ['view' => 'catalog']],
            'student.assignments' => ['path' => '/student/assignments', 'params' => ['view' => 'assignments']],
            'student.quizzes' => ['path' => '/student/quizzes', 'params' => ['view' => 'quizzes']],
            'student.exams' => ['path' => '/student/exams', 'params' => ['view' => 'exams']],
            'student.examstake' => ['path' => '/student/exams/take', 'params' => ['view' => 'take', 'page' => 'take']],
            'student.examsresult' => ['path' => '/student/exams/result', 'params' => ['view' => 'result', 'page' => 'result']],
            'student.grades' => ['path' => '/student/grades'],
            'student.progress' => ['path' => '/student/progress', 'params' => ['view' => 'progress']],
            'student.timetable' => ['path' => '/student/timetable', 'params' => ['view' => 'timetable']],
            'student.announcements' => ['path' => '/student/announcements', 'params' => ['view' => 'announcements']],
            'student.messages' => ['path' => '/student/messages', 'params' => ['view' => 'messages']],
            'student.profile' => ['path' => '/student/profile', 'params' => ['view' => 'profile']],

            'lecturer.login' => ['path' => '/lecturer/login/'],
            'lecturer.passwordreset' => ['path' => '/lecturer/password-reset/', 'params' => ['portal' => 'lecturer']],
            'lecturer.dashboard' => ['path' => '/lecturer/'],
            'lecturer.courses' => ['path' => '/lecturer/courses'],
            'lecturer.materials' => ['path' => '/lecturer/materials', 'params' => ['view' => 'materials']],
            'lecturer.assignments' => ['path' => '/lecturer/assignments', 'params' => ['view' => 'assignments']],
            'lecturer.quizzes' => ['path' => '/lecturer/quizzes', 'params' => ['view' => 'quizzes']],
            'lecturer.exams' => ['path' => '/lecturer/exams', 'params' => ['view' => 'exams']],
            'lecturer.examscreate' => ['path' => '/lecturer/exams/create', 'params' => ['view' => 'create', 'page' => 'create']],
            'lecturer.examsedit' => ['path' => '/lecturer/exams/edit', 'params' => ['view' => 'edit', 'page' => 'edit']],
            'lecturer.examsquestions' => ['path' => '/lecturer/exams/questions', 'params' => ['view' => 'questions', 'page' => 'questions']],
            'lecturer.examspreview' => ['path' => '/lecturer/exams/preview', 'params' => ['view' => 'preview', 'page' => 'preview']],
            'lecturer.students' => ['path' => '/lecturer/students', 'params' => ['view' => 'students']],
            'lecturer.attendance' => ['path' => '/lecturer/attendance', 'params' => ['view' => 'attendance']],
            'lecturer.grades' => ['path' => '/lecturer/grades', 'params' => ['view' => 'grades']],
            'lecturer.announcements' => ['path' => '/lecturer/announcements', 'params' => ['view' => 'announcements']],
            'lecturer.messages' => ['path' => '/lecturer/messages', 'params' => ['view' => 'messages']],
            'lecturer.profile' => ['path' => '/lecturer/profile', 'params' => ['view' => 'profile']],

            'management.login' => ['path' => '/management/login/'],
            'management.passwordreset' => ['path' => '/management/password-reset/', 'params' => ['portal' => 'administrator']],
            'management.dashboard' => ['path' => '/management/'],
            'management.users' => ['path' => '/management/users'],
            'management.usercreate' => ['path' => '/management/users/create/'],
            'management.userview' => ['path' => '/management/users/view'],
            'management.useredit' => ['path' => '/management/users/edit'],
            'management.provisioning' => ['path' => '/management/users/provisioning', 'params' => ['section' => 'manual']],
            'management.bulkupload' => ['path' => '/management/users/bulk-upload', 'params' => ['section' => 'bulk']],
            'management.bulkreport' => ['path' => '/management/users/bulk-upload/report'],
            'management.analytics' => ['path' => '/management/analytics'],
            'management.academicsmanage' => ['path' => '/management/academics/manage/'],
            'management.academicslevels' => ['path' => '/management/academics/levels/'],
            'management.academicsimport' => ['path' => '/management/academics/import/'],
            'management.academicsmappings' => ['path' => '/management/academics/course-mappings/'],
            'management.academicsreports' => ['path' => '/management/academics/reports/'],
            'management.academics' => ['path' => '/management/academics/'],
            'management.courses' => ['path' => '/management/courses', 'params' => ['view' => 'courses']],
            'management.reports' => ['path' => '/management/reports', 'params' => ['view' => 'reports']],
            'management.auditlogs' => ['path' => '/management/audit-logs', 'params' => ['view' => 'auditlogs']],
            'management.settings' => ['path' => '/management/settings'],

            'superadmin.login' => ['path' => '/super-admin/login/'],
            'superadmin.passwordreset' => ['path' => '/super-admin/password-reset/', 'params' => ['portal' => 'superadmin']],
            'superadmin.dashboard' => ['path' => '/super-admin/'],
            'superadmin.administrators' => ['path' => '/super-admin/administrators', 'params' => ['view' => 'administrators']],
            'superadmin.users' => ['path' => '/super-admin/users', 'params' => ['view' => 'users']],
            'superadmin.institution' => ['path' => '/super-admin/institution', 'params' => ['view' => 'institution']],
            'superadmin.health' => ['path' => '/super-admin/system', 'params' => ['view' => 'health']],
            'superadmin.integrations' => ['path' => '/super-admin/integrations', 'params' => ['view' => 'integrations']],
            'superadmin.security' => ['path' => '/super-admin/security', 'params' => ['view' => 'security']],
            'superadmin.auditlogs' => ['path' => '/super-admin/audit-logs', 'params' => ['view' => 'auditlogs']],
            'superadmin.reports' => ['path' => '/super-admin/reports', 'params' => ['view' => 'reports']],
            'superadmin.settings' => ['path' => '/super-admin/settings', 'params' => ['view' => 'settings']],
        ];
    }

    /**
     * Returns whether the supplied user is a Moodle site administrator.
     *
     * @param \stdClass $user
     * @return bool
     */
    private function is_super_admin_user(\stdClass $user): bool {
        return \is_siteadmin($user);
    }

    /**
     * Returns role shortnames that should resolve to the administrator portal.
     *
     * @return array<int, string>
     */
    private function get_admin_role_shortnames(): array {
        return ['manager', 'coursecreator', 'ictadmin', 'facultyadmin', 'departmentadmin'];
    }

    /**
     * Returns the role shortnames in portal-routing priority order.
     *
     * @return string[]
     */
    private function get_role_priority(): array {
        return array_merge(
            ['siteadmin'],
            $this->get_admin_role_shortnames(),
            [
            'editingteacher',
            'teacher',
            'student',
            'user',
            ]
        );
    }

    /**
     * Returns canonical role routing metadata.
     *
     * @return array<string, array<string, string>>
     */
    private function get_role_route_map(): array {
        $map = [
            'siteadmin' => [
                'portalkey' => 'superadmin',
                'dashboardkey' => 'superadmin',
            ],
            'editingteacher' => [
                'portalkey' => 'lecturer',
                'dashboardkey' => 'lecturer',
            ],
            'teacher' => [
                'portalkey' => 'lecturer',
                'dashboardkey' => 'lecturer',
            ],
            'student' => [
                'portalkey' => 'student',
                'dashboardkey' => 'student',
            ],
            'user' => [
                'portalkey' => 'student',
                'dashboardkey' => 'student',
            ],
        ];

        foreach ($this->get_admin_role_shortnames() as $roleshortname) {
            $map[$roleshortname] = [
                'portalkey' => 'administrator',
                'dashboardkey' => 'admin',
            ];
        }

        return $map;
    }

    /**
     * Returns canonical portal definitions for each supported auth family.
     *
     * @return array<string, array<string, mixed>>
     */
    public function get_portal_definitions(): array {
        return [
            'student' => [
                'key' => 'student',
                'title' => get_string('studentportaltitle', 'local_ulms_auth'),
                'description' => get_string('studentportaldesc', 'local_ulms_auth'),
                'eyebrow' => get_string('studentportaleyebrow', 'local_ulms_auth'),
                'audiencesummary' => get_string('studentportalaudience', 'local_ulms_auth'),
                'roles' => ['student', 'user'],
                'dashboard' => $this->get_path_for_route('student.dashboard'),
                'login' => $this->get_path_for_route('student.login'),
            ],
            'lecturer' => [
                'key' => 'lecturer',
                'title' => get_string('lecturerportaltitle', 'local_ulms_auth'),
                'description' => get_string('lecturerportaldesc', 'local_ulms_auth'),
                'eyebrow' => get_string('lecturerportaleyebrow', 'local_ulms_auth'),
                'audiencesummary' => get_string('lecturerportalaudience', 'local_ulms_auth'),
                'roles' => ['editingteacher', 'teacher'],
                'dashboard' => $this->get_path_for_route('lecturer.dashboard'),
                'login' => $this->get_path_for_route('lecturer.login'),
            ],
            'administrator' => [
                'key' => 'administrator',
                'title' => get_string('adminportaltitle', 'local_ulms_auth'),
                'description' => get_string('adminportaldesc', 'local_ulms_auth'),
                'eyebrow' => get_string('adminportaleyebrow', 'local_ulms_auth'),
                'audiencesummary' => get_string('adminportalaudience', 'local_ulms_auth'),
                'roles' => $this->get_admin_role_shortnames(),
                'dashboard' => $this->get_path_for_route('management.dashboard'),
                'login' => $this->get_path_for_route('management.login'),
            ],
            'superadmin' => [
                'key' => 'superadmin',
                'title' => get_string('superadminportaltitle', 'local_ulms_auth'),
                'description' => get_string('superadminportaldesc', 'local_ulms_auth'),
                'eyebrow' => get_string('superadminportaleyebrow', 'local_ulms_auth'),
                'audiencesummary' => get_string('superadminportalaudience', 'local_ulms_auth'),
                'roles' => ['siteadmin'],
                'dashboard' => $this->get_path_for_route('superadmin.dashboard'),
                'login' => $this->get_path_for_route('superadmin.login'),
            ],
        ];
    }

    /**
     * Returns the public homepage URL.
     *
     * @return \moodle_url
     */
    public function get_homepage_url(): \moodle_url {
        return new \moodle_url('/');
    }

    /**
     * Returns the public portal-selection URL used as the single guest login entry.
     *
     * @return \moodle_url
     */
    public function get_public_portal_landing_url(): \moodle_url {
        return $this->get_url_for_route('public.landing');
    }

    /**
     * Returns a canonical clean route path by key.
     *
     * @param string $routekey
     * @return string
     */
    public function get_path_for_route(string $routekey): string {
        $routes = $this->get_route_definitions();

        if (!isset($routes[$routekey]['path'])) {
            throw new \coding_exception('Unknown ULMS route key: ' . $routekey);
        }

        return (string)$routes[$routekey]['path'];
    }

    /**
     * Returns a canonical clean route URL by key.
     *
     * @param string $routekey
     * @param array<string, mixed> $params
     * @return \moodle_url
     */
    public function get_url_for_route(string $routekey, array $params = []): \moodle_url {
        $routes = $this->get_route_definitions();

        if (!isset($routes[$routekey])) {
            throw new \coding_exception('Unknown ULMS route key: ' . $routekey);
        }

        $definition = $routes[$routekey];
        $defaults = isset($definition['params']) && is_array($definition['params']) ? $definition['params'] : [];

        return new \moodle_url((string)$definition['path'], array_merge($defaults, $params));
    }

    /**
     * Returns whether a request path belongs to a clean ULMS route.
     *
     * @param string $path
     * @return bool
     */
    public function is_ulms_route_path(string $path): bool {
        $path = $this->normalise_path($path);

        foreach ($this->get_route_definitions() as $definition) {
            if ($path === $this->normalise_path((string)$definition['path'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns whether a path belongs to a dashboard-style ULMS portal route.
     *
     * @param string $path
     * @return bool
     */
    public function is_ulms_dashboard_path(string $path): bool {
        $path = $this->normalise_path($path);

        foreach (['/student', '/lecturer', '/management', '/super-admin'] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns whether role-based landing is enabled.
     *
     * @return bool
     */
    public function is_role_landing_enabled(): bool {
        return (bool)\get_config('local_ulms_auth', 'enablerolelanding');
    }

    /**
     * Returns the available portal cards for public navigation.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_portal_cards(): array {
        $cards = [];

        foreach ($this->get_portal_definitions() as $portal) {
            $cards[] = [
                'key' => $portal['key'],
                'title' => $portal['title'],
                'description' => $portal['description'],
                'eyebrow' => $portal['eyebrow'],
                'audiencesummary' => $portal['audiencesummary'],
                'loginurl' => (new \moodle_url($portal['login']))->out(false),
            ];
        }

        return $cards;
    }

    /**
     * Returns a portal definition by key.
     *
     * @param string $portalkey
     * @return array<string, mixed>
     */
    public function get_portal_definition(string $portalkey): array {
        $portals = $this->get_portal_definitions();

        if (!array_key_exists($portalkey, $portals)) {
            throw new \moodle_exception('invalidportalroute', 'local_ulms_auth');
        }

        return $portals[$portalkey];
    }

    /**
     * Returns the effective system role shortname for a user.
     *
     * @param \stdClass $user
     * @return string
     */
    public function get_role_shortname_for_user(\stdClass $user): string {
        if ($this->is_super_admin_user($user)) {
            return 'siteadmin';
        }

        $roles = $this->get_assigned_role_shortnames_for_user((int)$user->id);

        foreach ($this->get_role_priority() as $shortname) {
            if (in_array($shortname, $roles, true)) {
                return $shortname;
            }
        }

        return 'user';
    }

    /**
     * Returns the current user's portal key.
     *
     * @return string
     */
    public function get_current_user_portal_key(): string {
        global $USER;

        return $this->get_portal_key_for_role_shortname($this->get_role_shortname_for_user($USER));
    }

    /**
     * Returns a portal key for the supplied role.
     *
     * @param string $roleshortname
     * @return string
     */
    public function get_portal_key_for_role_shortname(string $roleshortname): string {
        $routes = $this->get_role_route_map();
        return $routes[$roleshortname]['portalkey'] ?? $routes['user']['portalkey'];
    }

    /**
     * Returns a dashboard key for the supplied role.
     *
     * @param string $roleshortname
     * @return string
     */
    public function get_dashboard_key_for_role_shortname(string $roleshortname): string {
        $routes = $this->get_role_route_map();
        return $routes[$roleshortname]['dashboardkey'] ?? $routes['user']['dashboardkey'];
    }

    /**
     * Returns the best matching portal for the supplied user.
     *
     * @param \stdClass $user
     * @return string
     */
    public function get_matching_portal_for_user(\stdClass $user): string {
        return $this->get_portal_key_for_role_shortname($this->get_role_shortname_for_user($user));
    }

    /**
     * Returns whether the supplied user can authenticate through the portal.
     *
     * @param \stdClass $user
     * @param string $portalkey
     * @return bool
     */
    public function user_matches_portal(\stdClass $user, string $portalkey): bool {
        return $this->get_matching_portal_for_user($user) === $portalkey;
    }

    /**
     * Returns the dashboard URL for the supplied portal.
     *
     * @param string $portalkey
     * @return \moodle_url
     */
    public function get_dashboard_url_for_portal(string $portalkey): \moodle_url {
        $portal = $this->get_portal_definition($portalkey);

        return new \moodle_url($portal['dashboard']);
    }

    /**
     * Returns the dashboard URL for the current user.
     *
     * @return \moodle_url
     */
    public function get_dashboard_url_for_current_user(): \moodle_url {
        return $this->get_dashboard_url_for_portal($this->get_current_user_portal_key());
    }

    /**
     * Returns the canonical courses URL for the current user.
     *
     * @return \moodle_url
     */
    public function get_courses_url_for_current_user(): \moodle_url {
        return match ($this->get_current_user_portal_key()) {
            'student' => $this->get_url_for_route('student.courses'),
            'lecturer' => $this->get_url_for_route('lecturer.courses'),
            'superadmin' => $this->get_url_for_route('superadmin.users'),
            default => $this->get_dashboard_url_for_current_user(),
        };
    }

    /**
     * Returns the login URL for the supplied portal.
     *
     * @param string $portalkey
     * @return \moodle_url
     */
    public function get_login_url_for_portal(string $portalkey): \moodle_url {
        $portal = $this->get_portal_definition($portalkey);

        return new \moodle_url($portal['login']);
    }

    /**
     * Returns the login URL for the current user.
     *
     * @return \moodle_url
     */
    public function get_login_url_for_current_user(): \moodle_url {
        return $this->get_login_url_for_portal($this->get_current_user_portal_key());
    }

    /**
     * Normalises a request path for route matching.
     *
     * @param string $path
     * @return string
     */
    public function normalise_path(string $path): string {
        global $CFG;

        $path = (string)(parse_url($path, PHP_URL_PATH) ?? '');
        $path = rawurldecode(trim($path));
        $path = preg_replace('#/+#', '/', $path);

        if ($path === '' || $path === false) {
            return '/';
        }

        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        $basepath = (string)(parse_url((string)($CFG->wwwroot ?? ''), PHP_URL_PATH) ?? '');
        $basepath = rawurldecode(trim($basepath));
        $basepath = preg_replace('#/+#', '/', $basepath);

        if ($basepath !== '' && $basepath !== false && $basepath !== '/') {
            if ($basepath[0] !== '/') {
                $basepath = '/' . $basepath;
            }

            $basepath = rtrim($basepath, '/');
            if ($path === $basepath) {
                $path = '/';
            } else if (str_starts_with($path, $basepath . '/')) {
                $path = substr($path, strlen($basepath));
            }
        }

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * Resolves a redirect definition for known ULMS aliases and fallback routes.
     *
     * @param string $path
     * @return array<string, mixed>|null
     */
    public function resolve_route_redirect(string $path): ?array {
        $path = $this->normalise_path($path);

        $staticroutes = [
            '/local/ulms_auth' => [
                'type' => 'authindex',
            ],
            '/local/ulms_auth/index.php' => [
                'type' => 'authindex',
            ],
            '/local/ulms_dashboard' => [
                'type' => 'dashboardindex',
            ],
            '/local/ulms_dashboard/index.php' => [
                'type' => 'dashboardindex',
            ],
        ];

        if (!array_key_exists($path, $staticroutes)) {
            return null;
        }

        $route = $staticroutes[$path];
        if (isset($route['target'])) {
            return $route;
        }

        if ($route['type'] === 'authindex') {
            return $this->is_authenticated_user()
                ? [
                    'target' => $this->get_dashboard_url_for_current_user(),
                    'statuscode' => 302,
                    'message' => get_string('alreadyauthenticatedredirect', 'local_ulms_auth'),
                    'messagetype' => \core\output\notification::NOTIFY_INFO,
                ]
                : [
                    'target' => $this->get_public_portal_landing_url(),
                    'statuscode' => 302,
                ];
        }

        return $this->is_authenticated_user()
            ? [
                'target' => $this->get_dashboard_url_for_current_user(),
                'statuscode' => 302,
            ]
            : [
                'target' => $this->get_homepage_url(),
                'statuscode' => 302,
                'message' => get_string('dashboardguestredirect', 'local_ulms_dashboard'),
                'messagetype' => \core\output\notification::NOTIFY_INFO,
            ];
    }

    /**
     * Returns the best fallback redirect target for a request path.
     *
     * @param string $path
     * @return \moodle_url
     */
    public function get_fallback_redirect_url(string $path): \moodle_url {
        $path = $this->normalise_path($path);

        if (
            $this->is_authenticated_user() &&
            (str_starts_with($path, '/local/ulms_dashboard') || str_starts_with($path, '/local/ulms_auth') || str_starts_with($path, '/my'))
        ) {
            return $this->get_dashboard_url_for_current_user();
        }

        return $this->get_homepage_url();
    }

    /**
     * Redirects the current request based on the shared ULMS route map.
     *
     * @param string $path
     * @return never
     */
    public function redirect_for_path(string $path): never {
        $redirect = $this->resolve_route_redirect($path);

        if ($redirect === null) {
            $this->redirect_to_url($this->get_fallback_redirect_url($path));
        }

        $message = (string)($redirect['message'] ?? '');
        $messagetype = (string)($redirect['messagetype'] ?? \core\output\notification::NOTIFY_INFO);
        $statuscode = (int)($redirect['statuscode'] ?? 302);
        $target = $redirect['target'] instanceof \moodle_url
            ? $redirect['target']
            : new \moodle_url((string)$redirect['target']);

        $this->redirect_to_url($target, $message, $messagetype, $statuscode);
    }

    /**
     * Redirects a legacy internal request to its clean canonical route on safe methods.
     *
     * @param string $routekey
     * @param array<string, mixed> $params
     * @param array<int, string> $allowedmethods
     * @return void
     */
    public function maybe_redirect_legacy_request(
        string $routekey,
        array $params = [],
        array $allowedmethods = ['GET', 'HEAD']
    ): void {
        if (defined('ULMS_PUBLIC_ROUTE_REQUEST')) {
            return;
        }

        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, $allowedmethods, true)) {
            return;
        }

        $target = $this->get_url_for_route($routekey, $params);
        $currentpath = $this->normalise_path((string)($_SERVER['REQUEST_URI'] ?? '/'));
        $targetpath = $this->normalise_path($target->get_path());

        if ($currentpath === $targetpath) {
            return;
        }

        $this->redirect_to_url($target, '', \core\output\notification::NOTIFY_INFO, 301);
    }

    /**
     * Performs an HTTP redirect with an explicit status code.
     *
     * @param \moodle_url $url
     * @param string $message
     * @param string $messagetype
     * @param int $statuscode
     * @return never
     */
    public function redirect_to_url(
        \moodle_url $url,
        string $message = '',
        string $messagetype = \core\output\notification::NOTIFY_INFO,
        int $statuscode = 302
    ): never {
        if (!in_array($statuscode, [301, 302], true)) {
            throw new \coding_exception('Unsupported redirect status code for ULMS routing.');
        }

        if ($message !== '') {
            \core\notification::add($message, $messagetype);
        }

        if (!headers_sent()) {
            header('Location: ' . $url->out(false), true, $statuscode);
            exit;
        }

        redirect($url, $message, null, $messagetype);
    }

    /**
     * Redirects the current user to the assigned dashboard.
     *
     * @param string $message
     * @param string $messagetype
     * @param int $statuscode
     * @return never
     */
    public function redirect_to_current_user_dashboard(
        string $message = '',
        string $messagetype = \core\output\notification::NOTIFY_INFO,
        int $statuscode = 302
    ): never {
        $this->redirect_to_url($this->get_dashboard_url_for_current_user(), $message, $messagetype, $statuscode);
    }

    /**
     * Redirects to the dashboard for the supplied portal.
     *
     * @param string $portalkey
     * @param string $message
     * @param string $messagetype
     * @param int $statuscode
     * @return never
     */
    public function redirect_to_portal_dashboard(
        string $portalkey,
        string $message = '',
        string $messagetype = \core\output\notification::NOTIFY_INFO,
        int $statuscode = 302
    ): never {
        $this->redirect_to_url($this->get_dashboard_url_for_portal($portalkey), $message, $messagetype, $statuscode);
    }

    /**
     * Returns whether the current request belongs to an authenticated user session.
     *
     * @return bool
     */
    private function is_authenticated_user(): bool {
        return isloggedin() && !isguestuser();
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
}
