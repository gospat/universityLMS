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

/**
 * Provides consistent header and navigation data for admin portal pages.
 */
class admin_portal_service {
    /**
     * Returns whether the current user is assigned to the admin portal.
     * Site-level administrators (Super Admin) never resolve to the Admin
     * portal identity — the super_admin_portal_service owns them.
     *
     * @return bool
     */
    public function is_admin_portal_user(): bool {
        global $USER;

        if (!isloggedin() || isguestuser()) {
            return false;
        }

        if (is_siteadmin($USER)) {
            return false;
        }

        $routingservice = $this->get_routing_service();
        return $routingservice->get_current_user_portal_key() === 'administrator';
    }

    /**
     * Returns the single-source-of-truth list of admin portal routes.
     * Keys are normalised paths; values map to [section, header].
     *
     * @return array<string, array{section: string, header: string}>
     */
    private function get_canonical_admin_portal_routes(): array {
        $routingservice = $this->get_routing_service();
        $n = static function(string $routekey) use ($routingservice): string {
            return $routingservice->normalise_path($routingservice->get_path_for_route($routekey));
        };

        $provisioningview = static function(): string {
            return optional_param('section', 'manual', PARAM_ALPHA) === 'bulk' ? 'bulkupload' : 'provisioning';
        };
        $legacyprovisioningview = static function(): string {
            return optional_param('section', '', PARAM_ALPHA) === 'bulk' ? 'bulkupload' : 'provisioning';
        };

        return [
            $n('management.dashboard') => ['section' => 'dashboard', 'header' => 'dashboard'],
            '/local/ulms_dashboard/admin.php' => ['section' => 'dashboard', 'header' => 'dashboard'],
            $n('management.users') => ['section' => 'users', 'header' => 'users'],
            $n('management.usercreate') => ['section' => 'users', 'header' => 'users'],
            $n('management.userview') => ['section' => 'users', 'header' => 'users'],
            $n('management.useredit') => ['section' => 'users', 'header' => 'users'],
            '/local/ulms_dashboard/user_management.php' => ['section' => 'users', 'header' => 'users'],
            '/local/ulms_dashboard/user_edit.php' => ['section' => 'users', 'header' => 'users'],
            '/local/ulms_dashboard/user_view.php' => ['section' => 'users', 'header' => 'users'],
            $n('management.provisioning') => ['section' => $provisioningview(), 'header' => $provisioningview()],
            $n('management.bulkupload') => ['section' => 'bulkupload', 'header' => 'bulkupload'],
            $n('management.bulkreport') => ['section' => 'bulkupload', 'header' => 'bulkupload'],
            '/local/ulms_dashboard/user_provisioning.php' => ['section' => $legacyprovisioningview(), 'header' => $legacyprovisioningview()],
            '/local/ulms_dashboard/user_provisioning_report.php' => ['section' => $legacyprovisioningview(), 'header' => $legacyprovisioningview()],
            $n('management.analytics') => ['section' => 'analytics', 'header' => 'analytics'],
            '/local/ulms_dashboard/analytics.php' => ['section' => 'analytics', 'header' => 'analytics'],
            $n('management.settings') => ['section' => 'settings', 'header' => 'settings'],
            '/local/ulms_dashboard/settings.php' => ['section' => 'settings', 'header' => 'settings'],
            $n('management.academics') => ['section' => 'academics', 'header' => 'academics'],
            $n('management.academicsmanage') => ['section' => 'academics', 'header' => 'academics'],
            $n('management.academicslevels') => ['section' => 'academics', 'header' => 'academics'],
            $n('management.academicsreports') => ['section' => 'academics', 'header' => 'academics'],
            $n('management.academicsmappings') => ['section' => 'academics', 'header' => 'academics'],
            $n('management.academicsimport') => ['section' => 'academics', 'header' => 'academics'],
            '/local/ulms_academics/index.php' => ['section' => 'academics', 'header' => 'academics'],
            '/local/ulms_academics/manage.php' => ['section' => 'academics', 'header' => 'academics'],
            '/local/ulms_academics/report.php' => ['section' => 'academics', 'header' => 'academics'],
            '/local/ulms_academics/course_mappings.php' => ['section' => 'academics', 'header' => 'academics'],
            '/local/ulms_academics/import.php' => ['section' => 'academics', 'header' => 'academics'],
            '/local/ulms_dashboard/academics_levels.php' => ['section' => 'academics', 'header' => 'academics'],
            $n('management.courses') => ['section' => 'courses', 'header' => 'courses'],
            $n('management.academicsschedule') => ['section' => 'schedule', 'header' => 'schedule'],
            $n('management.academicsattendanceaudit') => ['section' => 'attendanceaudit', 'header' => 'attendanceaudit'],
            $n('management.lecturers') => ['section' => 'lecturers', 'header' => 'lecturers'],
            '/course/edit.php' => ['section' => 'courses', 'header' => 'courses'],
            '/course/management.php' => ['section' => 'courses', 'header' => 'courses'],
            '/course/index.php' => ['section' => 'courses', 'header' => 'courses'],
            '/course/editcategory.php' => ['section' => 'courses', 'header' => 'courses'],
            $n('management.reports') => ['section' => 'reports', 'header' => 'reports'],
            $n('management.auditlogs') => ['section' => 'auditlogs', 'header' => 'auditlogs'],
            '/local/ulms_dashboard/admin_portal.php' => [
                'section' => $this->normalise_portal_view(optional_param('view', 'courses', PARAM_ALPHA)),
                'header' => $this->normalise_portal_view(optional_param('view', 'courses', PARAM_ALPHA)),
            ],
            '/admin/roles/assign.php' => ['section' => 'users', 'header' => 'users'],
            '/admin/search.php' => ['section' => 'settings', 'header' => 'settings'],
            '/user/edit.php' => ['section' => 'users', 'header' => 'users'],
            '/user/view.php' => ['section' => 'users', 'header' => 'users'],
            '/user/index.php' => ['section' => 'users', 'header' => 'users'],
            '/grade/report/grader/index.php' => ['section' => 'reports', 'header' => 'reports'],
            '/grade/report/overview/index.php' => ['section' => 'reports', 'header' => 'reports'],
            '/report/log/index.php' => ['section' => 'auditlogs', 'header' => 'auditlogs'],
            '/report/stats/index.php' => ['section' => 'reports', 'header' => 'reports'],
        ];
    }

    /**
     * Public audit accessor for the canonical admin route list (readiness checks/CI).
     *
     * @return array<string, array{section: string, header: string}>
     */
    public function get_canonical_admin_portal_routes_for_audit(): array {
        return $this->get_canonical_admin_portal_routes();
    }

    /**
     * Structured self-check: verifies every SSOT entry uses allowed section/header keys.
     *
     * @return array{ok: bool, errors: string[]}
     */
    public function validate_canonical_route_consistency(): array {
        $routes = $this->get_canonical_admin_portal_routes();
        $allowedsections = [
            'dashboard', 'users', 'provisioning', 'bulkupload', 'analytics',
            'academics', 'courses', 'reports', 'auditlogs', 'settings',
            'schedule', 'attendanceaudit', 'lecturers',
        ];
        $allowedheaders = $allowedsections;
        $errors = [];
        foreach ($routes as $path => $entry) {
            if (!is_string($path) || $path === '') {
                $errors[] = 'Empty path key in canonical admin routes';
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
     * Returns the shared ULMS routing service.
     *
     * @return \local_ulms_auth\local\service\landing_page_service
     */
    private function get_routing_service(): \local_ulms_auth\local\service\landing_page_service {
        return new \local_ulms_auth\local\service\landing_page_service();
    }

    /**
     * Returns whether the current user can provision admin accounts.
     *
     * @return bool
     */
    public function can_create_admin_accounts(): bool {
        global $USER;

        return isloggedin() && !isguestuser() && is_siteadmin($USER);
    }

    /**
     * Returns header context for an admin portal section.
     *
     * @param string $section
     * @return array
     */
    public function get_header_context_for_section(string $section): array {
        $def = dashboard_commons::get_admin_header_defs();
        $defaultseyebrow = get_string($def['default_eyebrow_key'], 'local_ulms_dashboard');
        $defaultstitle = get_string($def['default_title_key'], 'local_ulms_dashboard');

        return [
            'eyebrow' => $def['eyebrow'][$section] ?? $defaultseyebrow,
            'navigationaria' => $def['navigationaria'],
            'title' => $def['titles'][$section] ?? $defaultstitle,
            'meta' => $def['meta'][$section] ?? '',
            'showtitle' => true,
            'hasbreadcrumbs' => true,
            'breadcrumbs' => $this->get_breadcrumbs_for_section($section),
            'hasnavitems' => true,
            'navitems' => $this->get_navigation_items($section),
        ];
    }

    /**
     * Returns the current admin section for a page when it belongs to the
     * admin portal or an inherited admin workflow. Reads from the canonical
     * route SSOT to eliminate drift between shell/header/page-resolution.
     *
     * @param \moodle_page $page
     * @return string|null
     */
    private function get_section_for_page(\moodle_page $page): ?string {
        $path = $page->url ? $page->url->get_path() : '';
        $path = $this->get_routing_service()->normalise_path($path);

        $routes = $this->get_canonical_admin_portal_routes();
        if (isset($routes[$path])) {
            return $routes[$path]['section'] ?? null;
        }

        return null;
    }

    /**
     * Returns header context for the current admin page when applicable.
     *
     * @param \moodle_page $page
     * @return array|null
     */
    public function get_header_context_for_page(\moodle_page $page): ?array {
        if (!$this->is_admin_portal_user()) {
            return null;
        }

        $section = $this->get_section_for_page($page);
        if ($section === null) {
            return null;
        }

        return $this->get_header_context_for_section($section);
    }

    /**
     * Returns shell navigation context for the current admin page.
     *
     * @param \moodle_page $page
     * @return array|null
     */
    public function get_shell_context_for_page(\moodle_page $page): ?array {
        if (!$this->is_admin_portal_user()) {
            return null;
        }

        $layout = $page->pagelayout ?? 'standard';
        if (in_array($layout, ['login', 'secure', 'maintenance'], true)) {
            return null;
        }

        $section = $this->get_section_for_page($page);
        if ($section === null) {
            $section = 'dashboard';
        }

        global $USER;
        $routingservice = $this->get_routing_service();

        $bannercta1 = null;
        $bannercta2 = null;
        try {
            $bannercta1 = ['label' => @get_string('adminnavusermanagement', 'local_ulms_dashboard'), 'href' => $routingservice->get_url_for_route('management.users')->out(false)];
            $bannercta2 = ['label' => @get_string('adminnavacademics', 'local_ulms_dashboard'), 'href' => $routingservice->get_url_for_route('management.academics')->out(false)];
        } catch (\Exception $e) {
            $bannercta1 = null;
            $bannercta2 = null;
        }

        $adminname = fullname($USER);

        $banner_eyebrow = @get_string('management.users.eyebrow', 'local_ulms_dashboard');
        if (!is_string($banner_eyebrow) || $banner_eyebrow === '' || str_contains($banner_eyebrow, '[[')) $banner_eyebrow = 'Admin portal';
        $banner_title = @get_string('adminwelcome', 'local_ulms_dashboard', $adminname);
        if (!is_string($banner_title) || $banner_title === '' || str_contains($banner_title, '[[')) $banner_title = 'Welcome back, ' . $adminname;
        $banner_meta = @get_string('admindashboarddesc', 'local_ulms_dashboard');
        if (!is_string($banner_meta) || $banner_meta === '' || str_contains($banner_meta, '[[')) $banner_meta = 'Management workspace — users, academics, courses and institutional reports.';

        $breadcrumb_label = @get_string('admindashboard', 'local_ulms_dashboard');
        if (!is_string($breadcrumb_label) || $breadcrumb_label === '' || str_contains($breadcrumb_label, '[[')) $breadcrumb_label = 'Dashboard';

        $headercontext = [
            'banner_eyebrow' => $banner_eyebrow,
            'banner_title' => $banner_title,
            'banner_meta' => $banner_meta,
            'banner_cta1' => $bannercta1,
            'banner_cta2' => $bannercta2,
            'breadcrumbs' => [['label' => $breadcrumb_label, 'url' => null, 'last' => true]],
        ];

        $dashboardservice = new \local_ulms_dashboard\local\service\dashboard_service();
        $snapshot = $dashboardservice->get_current_user_snapshot();
        $iconcourse = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>';
        $iconchart = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg>';

        $usr_cnt = (int)(($snapshot['coursecount'] ?? 0) * 18) + 20;
        $crs_cnt = (int)($snapshot['coursecount'] ?? 0) + 6;
        $anl_cnt = (int)($snapshot['notificationcount'] ?? 0) + 12;
        $rpt_cnt = (is_array($snapshot['deadlines'] ?? null) ? count($snapshot['deadlines']) : 0) + 3;

        $summarycards = [
            ['eyebrow' => get_string('summarycard.admin.users.eyebrow', 'local_ulms_dashboard'), 'number' => (string)$usr_cnt, 'desc' => get_string('summarycard.admin.users.desc', 'local_ulms_dashboard'), 'mini_icon' => dashboard_commons::icon_svg('users')],
            ['eyebrow' => get_string('summarycard.admin.courses.eyebrow', 'local_ulms_dashboard'), 'number' => (string)$crs_cnt, 'desc' => get_string('summarycard.admin.courses.desc', 'local_ulms_dashboard'), 'mini_icon' => $iconcourse],
            ['eyebrow' => get_string('summarycard.admin.analytics.eyebrow', 'local_ulms_dashboard'), 'number' => (string)$anl_cnt, 'desc' => get_string('summarycard.admin.analytics.desc', 'local_ulms_dashboard'), 'mini_icon' => $iconchart],
            ['eyebrow' => get_string('summarycard.admin.reports.eyebrow', 'local_ulms_dashboard'), 'number' => (string)$rpt_cnt, 'desc' => get_string('summarycard.admin.reports.desc', 'local_ulms_dashboard'), 'mini_icon' => dashboard_commons::icon_svg('reports')],
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
                $fburl1 = method_exists($routingservice, 'get_dashboard_url_for_current_user') ? $routingservice->get_dashboard_url_for_current_user()->out(false) : $routingservice->get_url_for_route('management.dashboard')->out(false);
                $fburl2 = $routingservice->get_url_for_route('management.users')->out(false);
                $fburl3 = $routingservice->get_url_for_route('management.academics')->out(false);
                $fburl4 = $routingservice->get_url_for_route('management.reports')->out(false);
            } catch (\Exception $e) {
                $fburl1 = $fburl2 = $fburl3 = $fburl4 = '#';
            }
            $fallbackurls = [
                ['t' => get_string('fallback.dashboard.title', 'local_ulms_dashboard'), 's' => get_string('fallback.dashboard.subtitle', 'local_ulms_dashboard'), 'u' => $fburl1, 'i' => $iconchart],
                ['t' => get_string('fallback.users.title', 'local_ulms_dashboard'), 's' => get_string('fallback.users.subtitle', 'local_ulms_dashboard'), 'u' => $fburl2, 'i' => dashboard_commons::icon_svg('users')],
                ['t' => get_string('fallback.academics.title', 'local_ulms_dashboard'), 's' => get_string('fallback.academics.subtitle', 'local_ulms_dashboard'), 'u' => $fburl3, 'i' => $iconcourse],
                ['t' => get_string('fallback.reports.title', 'local_ulms_dashboard'), 's' => get_string('fallback.reports.subtitle', 'local_ulms_dashboard'), 'u' => $fburl4, 'i' => dashboard_commons::icon_svg('reports')],
            ];
            foreach ($fallbackurls as $fb) {
                if (count($quickaccess) >= 4) break;
                $has = false;
                foreach ($quickaccess as $qa) if ($qa['title'] === $fb['t']) { $has = true; break; }
                if (!$has) $quickaccess[] = ['title' => $fb['t'], 'subtitle' => $fb['s'], 'icon' => $fb['i'], 'url' => $fb['u']];
            }
        }

        $portal_eyebrow = @get_string('adminportaleyebrow', 'local_ulms_dashboard');
        if (!is_string($portal_eyebrow) || $portal_eyebrow === '' || str_contains($portal_eyebrow, '[[')) $portal_eyebrow = $banner_eyebrow;

        return [
            'eyebrow' => $portal_eyebrow,
            'portalname' => @get_string('adminportalshelltitle', 'local_ulms_dashboard') ?: 'Admin portal',
            'navigationaria' => @get_string('adminportalnavigation', 'local_ulms_dashboard') ?: 'Admin portal navigation',
            'currentuserrole' => @get_string('adminportalshellrole', 'local_ulms_dashboard') ?: 'Administrator',
            'navgroups' => $navgroups,
            'headercontext' => null,
            'summarycards' => [],
            'quickaccess' => $quickaccess,
        ];
    }

    /**
     * Returns admin navigation items.
     *
     * @param string $section
     * @return array
     */
    public function get_navigation_items(string $section): array {
        $routingservice = $this->get_routing_service();

        $safelabel = static function (string $identifier, string $fallback): string {
            $v = @get_string($identifier, 'local_ulms_dashboard');
            if (!is_string($v) || $v === '' || strpos($v, '[[') !== false) {
                return $fallback;
            }
            return $v;
        };

        $items = [
            'dashboard' => [
                'label' => get_string('adminnavdashboard', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('management.dashboard')->out(false),
            ],
            'users' => [
                'label' => get_string('adminnavusermanagement', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('management.users')->out(false),
            ],
            'provisioning' => [
                'label' => get_string('adminnavprovisioning', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('management.provisioning')->out(false),
            ],
            'bulkupload' => [
                'label' => get_string('adminnavbulkupload', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('management.bulkupload')->out(false),
            ],
            'analytics' => [
                'label' => get_string('adminnavanalytics', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('management.analytics')->out(false),
            ],
            'academics' => [
                'label' => get_string('adminnavacademics', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('management.academics')->out(false),
            ],
            'courses' => [
                'label' => get_string('adminnavcourses', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('management.courses')->out(false),
            ],
            'schedule' => [
                'label' => get_string('adminnavclassschedule', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('management.academicsschedule')->out(false),
            ],
            'attendanceaudit' => [
                'label' => get_string('adminnavattendanceaudit', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('management.academicsattendanceaudit')->out(false),
            ],
            'lecturers' => [
                'label' => $safelabel('adminnavlecturers', 'Lecturer allocations'),
                'url' => $routingservice->get_url_for_route('management.lecturers')->out(false),
            ],
            'reports' => [
                'label' => get_string('adminnavreports', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('management.reports')->out(false),
            ],
            'auditlogs' => [
                'label' => get_string('adminnavauditlogs', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('management.auditlogs')->out(false),
            ],
            'settings' => [
                'label' => get_string('adminnavsettings', 'local_ulms_dashboard'),
                'url' => $routingservice->get_url_for_route('management.settings')->out(false),
            ],
        ];

        return array_map(
            static function(array $item, string $key) use ($section): array {
                $item['active'] = $key === $section;
                return $item;
            },
            $items,
            array_keys($items)
        );
    }

    /**
     * Returns grouped admin sidebar navigation.
     *
     * @param string $section
     * @return array<int, array<string, mixed>>
     */
    public function get_navigation_groups(string $section): array {
        $routingservice = $this->get_routing_service();

        return [
            [
                'heading' => get_string('adminnavgroupdashboard', 'local_ulms_dashboard'),
                'items' => $this->mark_active_items([
                    [
                        'key' => 'dashboard',
                        'label' => get_string('adminnavdashboard', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.dashboard')->out(false),
                        'icon' => dashboard_commons::icon_svg('dashboard'),
                    ],
                ], $section),
            ],
            [
                'heading' => get_string('adminnavgroupusers', 'local_ulms_dashboard'),
                'items' => $this->mark_active_items([
                    [
                        'key' => 'users',
                        'label' => get_string('adminnavusermanagement', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.users')->out(false),
                        'icon' => dashboard_commons::icon_svg('users'),
                    ],
                    [
                        'key' => 'provisioning',
                        'label' => get_string('adminnavprovisioning', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.provisioning')->out(false),
                        'icon' => dashboard_commons::icon_svg('provisioning'),
                    ],
                    [
                        'key' => 'bulkupload',
                        'label' => get_string('adminnavbulkupload', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.bulkupload')->out(false),
                        'icon' => dashboard_commons::icon_svg('bulk'),
                    ],
                ], $section),
            ],
            [
                'heading' => get_string('adminnavgroupacademics', 'local_ulms_dashboard'),
                'items' => $this->mark_active_items([
                    [
                        'key' => 'academics',
                        'label' => get_string('adminnavacademics', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.academics')->out(false),
                        'icon' => dashboard_commons::icon_svg('academics'),
                    ],
                    [
                        'key' => 'academics.colleges',
                        'label' => get_string('adminnavcolleges', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.academicsmanage', ['entity' => 'faculties'])->out(false),
                        'icon' => dashboard_commons::icon_svg('college'),
                    ],
                    [
                        'key' => 'academics.departments',
                        'label' => get_string('adminnavdepartments', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.academicsmanage', ['entity' => 'departments'])->out(false),
                        'icon' => dashboard_commons::icon_svg('department'),
                    ],
                    [
                        'key' => 'academics.programmes',
                        'label' => get_string('adminnavprogrammes', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.academicsmanage', ['entity' => 'programmes'])->out(false),
                        'icon' => dashboard_commons::icon_svg('programme'),
                    ],
                    [
                        'key' => 'academics.sessions',
                        'label' => get_string('adminnavsessions', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.academicsmanage', ['entity' => 'sessions'])->out(false),
                        'icon' => dashboard_commons::icon_svg('session'),
                    ],
                    [
                        'key' => 'academics.semesters',
                        'label' => get_string('adminnavsemesters', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.academicsmanage', ['entity' => 'semesters'])->out(false),
                        'icon' => dashboard_commons::icon_svg('semester'),
                    ],
                    [
                        'key' => 'academics.levels',
                        'label' => get_string('adminnavlevels', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.academicslevels')->out(false),
                        'icon' => dashboard_commons::icon_svg('level'),
                    ],
                    [
                        'key' => 'academics.mappings',
                        'label' => get_string('adminnavmappings', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.academicsmappings')->out(false),
                        'icon' => dashboard_commons::icon_svg('mapping'),
                    ],
                    [
                        'key' => 'academics.import',
                        'label' => get_string('adminnavimport', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.academicsimport')->out(false),
                        'icon' => dashboard_commons::icon_svg('import'),
                    ],
                    [
                        'key' => 'courses',
                        'label' => get_string('adminnavcourses', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.courses')->out(false),
                        'icon' => dashboard_commons::icon_svg('courses'),
                    ],
                    [
                        'key' => 'academics.schedule',
                        'label' => get_string('adminnavclassschedule', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.academicsschedule')->out(false),
                        'icon' => dashboard_commons::icon_svg('classschedule'),
                    ],
                    [
                        'key' => 'academics.attendanceaudit',
                        'label' => get_string('adminnavattendanceaudit', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.academicsattendanceaudit')->out(false),
                        'icon' => dashboard_commons::icon_svg('attendanceaudit'),
                    ],
                    [
                        'key' => 'academics.lecturers',
                        'label' => get_string('adminnavlecturers', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.lecturers')->out(false),
                        'icon' => dashboard_commons::icon_svg('lecturers'),
                    ],
                ], $section),
            ],
            [
                'heading' => get_string('adminnavgroupreporting', 'local_ulms_dashboard'),
                'items' => $this->mark_active_items([
                    [
                        'key' => 'analytics',
                        'label' => get_string('adminnavanalytics', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.analytics')->out(false),
                        'icon' => dashboard_commons::icon_svg('analytics'),
                    ],
                    [
                        'key' => 'reports',
                        'label' => get_string('adminnavreports', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.reports')->out(false),
                        'icon' => dashboard_commons::icon_svg('reports'),
                    ],
                    [
                        'key' => 'auditlogs',
                        'label' => get_string('adminnavauditlogs', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.auditlogs')->out(false),
                        'icon' => dashboard_commons::icon_svg('audit'),
                    ],
                ], $section),
            ],
            [
                'heading' => get_string('adminnavgroupsystem', 'local_ulms_dashboard'),
                'items' => $this->mark_active_items([
                    [
                        'key' => 'settings',
                        'label' => get_string('adminnavsettings', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.settings')->out(false),
                        'icon' => dashboard_commons::icon_svg('settings'),
                    ],
                ], $section),
            ],
        ];
    }

    /**
     * Returns breadcrumb items for an admin portal section.
     *
     * @param string $section
     * @return array
     */
    private function get_breadcrumbs_for_section(string $section): array {
        $routingservice = $this->get_routing_service();
        $breadcrumbs = [[
            'label' => get_string('admindashboard', 'local_ulms_dashboard'),
            'url' => $section === 'dashboard'
                ? null
                : $routingservice->get_url_for_route('management.dashboard')->out(false),
        ]];

        if ($section === 'provisioning') {
            $breadcrumbs[] = [
                'label' => get_string('adminuserprovisioning', 'local_ulms_dashboard'),
                'url' => null,
            ];
        }

        if ($section === 'bulkupload') {
            $breadcrumbs[] = [
                'label' => get_string('adminnavbulkupload', 'local_ulms_dashboard'),
                'url' => null,
            ];
        }

        if ($section === 'users') {
            $breadcrumbs[] = [
                'label' => get_string('adminusermanagementlink', 'local_ulms_dashboard'),
                'url' => null,
            ];
        }

        if ($section === 'analytics') {
            $breadcrumbs[] = [
                'label' => get_string('analyticsdashboard', 'local_ulms_dashboard'),
                'url' => null,
            ];
        }

        if ($section === 'academics') {
            $breadcrumbs[] = [
                'label' => get_string('manageacademicstructurelink', 'local_ulms_dashboard'),
                'url' => null,
            ];
        }

        if ($section === 'settings') {
            $breadcrumbs[] = [
                'label' => get_string('adminsettingsheading', 'local_ulms_dashboard'),
                'url' => null,
            ];
        }

        foreach ([
            'courses' => 'admincoursestitle',
            'schedule' => 'adminclassscheduletitle',
            'attendanceaudit' => 'adminattendanceaudittitle',
            'lecturers' => 'adminlecturerstitle',
            'reports' => 'adminreportstitle',
            'auditlogs' => 'adminauditlogstitle',
        ] as $key => $stringkey) {
            if ($section === $key) {
                $breadcrumbs[] = [
                    'label' => get_string($stringkey, 'local_ulms_dashboard'),
                    'url' => null,
                ];
            }
        }

        $lastindex = count($breadcrumbs) - 1;
        foreach ($breadcrumbs as $index => &$breadcrumb) {
            $breadcrumb['active'] = $index === $lastindex;
        }

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
     * Normalises admin overview page views.
     *
     * @param string $view
     * @return string
     */
    private function normalise_portal_view(string $view): string {
        $allowed = ['courses', 'reports', 'auditlogs', 'schedule', 'attendanceaudit', 'lecturers'];
        return in_array($view, $allowed, true) ? $view : 'courses';
    }
}
