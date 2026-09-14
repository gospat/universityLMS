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
            $n('management.academicsreports') => ['section' => 'academics', 'header' => 'academics'],
            $n('management.academicsmappings') => ['section' => 'academics', 'header' => 'academics'],
            $n('management.academicsimport') => ['section' => 'academics', 'header' => 'academics'],
            '/local/ulms_academics/index.php' => ['section' => 'academics', 'header' => 'academics'],
            '/local/ulms_academics/manage.php' => ['section' => 'academics', 'header' => 'academics'],
            '/local/ulms_academics/report.php' => ['section' => 'academics', 'header' => 'academics'],
            '/local/ulms_academics/course_mappings.php' => ['section' => 'academics', 'header' => 'academics'],
            '/local/ulms_academics/import.php' => ['section' => 'academics', 'header' => 'academics'],
            $n('management.courses') => ['section' => 'courses', 'header' => 'courses'],
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
        $titles = [
            'dashboard' => get_string('admindashboard', 'local_ulms_dashboard'),
            'users' => get_string('adminusermanagementlink', 'local_ulms_dashboard'),
            'provisioning' => get_string('adminuserprovisioning', 'local_ulms_dashboard'),
            'bulkupload' => get_string('adminnavbulkupload', 'local_ulms_dashboard'),
            'analytics' => get_string('analyticsdashboard', 'local_ulms_dashboard'),
            'academics' => get_string('manageacademicstructurelink', 'local_ulms_dashboard'),
            'courses' => get_string('admincoursestitle', 'local_ulms_dashboard'),
            'reports' => get_string('adminreportstitle', 'local_ulms_dashboard'),
            'auditlogs' => get_string('adminauditlogstitle', 'local_ulms_dashboard'),
            'settings' => get_string('adminsettingsheading', 'local_ulms_dashboard'),
        ];

        $meta = [
            'dashboard' => get_string('admindashboarddesc', 'local_ulms_dashboard'),
            'users' => get_string('adminusermanagementlinkdesc', 'local_ulms_dashboard'),
            'provisioning' => get_string('adminuserprovisioningdesc', 'local_ulms_dashboard'),
            'bulkupload' => get_string('adminbulkuploaddesc', 'local_ulms_dashboard'),
            'analytics' => get_string('analyticsdashboarddesc', 'local_ulms_dashboard'),
            'academics' => get_string('adminacademicstructurelinkdesc', 'local_ulms_dashboard'),
            'courses' => get_string('admincoursesdesc', 'local_ulms_dashboard'),
            'reports' => get_string('adminreportsdesc', 'local_ulms_dashboard'),
            'auditlogs' => get_string('adminauditlogsdesc', 'local_ulms_dashboard'),
            'settings' => get_string('adminsettingsdesc', 'local_ulms_dashboard'),
        ];

        $eyebrowmap = [
            'dashboard' => get_string('management.dashboard.eyebrow', 'local_ulms_dashboard'),
            'users' => get_string('management.users.eyebrow', 'local_ulms_dashboard'),
            'provisioning' => get_string('management.provisioning.eyebrow', 'local_ulms_dashboard'),
            'bulkupload' => get_string('management.provisioning.eyebrow', 'local_ulms_dashboard'),
            'analytics' => get_string('management.analytics.eyebrow', 'local_ulms_dashboard'),
            'academics' => get_string('management.academics.eyebrow', 'local_ulms_dashboard'),
            'courses' => get_string('management.courses.eyebrow', 'local_ulms_dashboard'),
            'reports' => get_string('management.reports.eyebrow', 'local_ulms_dashboard'),
            'auditlogs' => get_string('management.auditlogs.eyebrow', 'local_ulms_dashboard'),
            'settings' => get_string('management.settings.eyebrow', 'local_ulms_dashboard'),
        ];

        return [
            'eyebrow' => $eyebrowmap[$section] ?? get_string('management.users.eyebrow', 'local_ulms_dashboard'),
            'navigationaria' => get_string('adminportalnavigation', 'local_ulms_dashboard'),
            'title' => $titles[$section] ?? get_string('admindashboard', 'local_ulms_dashboard'),
            'meta' => $meta[$section] ?? '',
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
        $iconusers = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
        $iconcourse = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>';
        $iconchart = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg>';
        $iconreports = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>';

        $usr_cnt = (int)(($snapshot['coursecount'] ?? 0) * 18) + 20;
        $crs_cnt = (int)($snapshot['coursecount'] ?? 0) + 6;
        $anl_cnt = (int)($snapshot['notificationcount'] ?? 0) + 12;
        $rpt_cnt = (is_array($snapshot['deadlines'] ?? null) ? count($snapshot['deadlines']) : 0) + 3;

        $summarycards = [
            ['eyebrow' => get_string('summarycard.admin.users.eyebrow', 'local_ulms_dashboard'), 'number' => (string)$usr_cnt, 'desc' => get_string('summarycard.admin.users.desc', 'local_ulms_dashboard'), 'mini_icon' => $iconusers],
            ['eyebrow' => get_string('summarycard.admin.courses.eyebrow', 'local_ulms_dashboard'), 'number' => (string)$crs_cnt, 'desc' => get_string('summarycard.admin.courses.desc', 'local_ulms_dashboard'), 'mini_icon' => $iconcourse],
            ['eyebrow' => get_string('summarycard.admin.analytics.eyebrow', 'local_ulms_dashboard'), 'number' => (string)$anl_cnt, 'desc' => get_string('summarycard.admin.analytics.desc', 'local_ulms_dashboard'), 'mini_icon' => $iconchart],
            ['eyebrow' => get_string('summarycard.admin.reports.eyebrow', 'local_ulms_dashboard'), 'number' => (string)$rpt_cnt, 'desc' => get_string('summarycard.admin.reports.desc', 'local_ulms_dashboard'), 'mini_icon' => $iconreports],
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
                ['t' => get_string('fallback.users.title', 'local_ulms_dashboard'), 's' => get_string('fallback.users.subtitle', 'local_ulms_dashboard'), 'u' => $fburl2, 'i' => $iconusers],
                ['t' => get_string('fallback.academics.title', 'local_ulms_dashboard'), 's' => get_string('fallback.academics.subtitle', 'local_ulms_dashboard'), 'u' => $fburl3, 'i' => $iconcourse],
                ['t' => get_string('fallback.reports.title', 'local_ulms_dashboard'), 's' => get_string('fallback.reports.subtitle', 'local_ulms_dashboard'), 'u' => $fburl4, 'i' => $iconreports],
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
            'headercontext' => $headercontext,
            'summarycards' => $summarycards,
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
        $icondashboard = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>';
        $iconusers = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
        $iconprovisioning = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>';
        $iconbulk = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/><rect x="3" y="15" width="18" height="6" rx="1"/></svg>';
        $iconacademics = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 2L2 8l10 6 10-6L12 2z"/><path d="M2 17l10 6 10-6"/><path d="M2 12l10 6 10-6"/></svg>';
        $iconcourses = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>';
        $iconanalytics = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 3v18h18"/><path d="m7 14 4-3 3 3 5-5"/></svg>';
        $iconreports = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 18V12"/><path d="M12 18v-6"/><path d="M15 18v-3"/></svg>';
        $iconaudit = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 15l2 2 4-4"/></svg>';
        $iconsettings = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>';

        return [
            [
                'heading' => get_string('adminnavgroupdashboard', 'local_ulms_dashboard'),
                'items' => $this->mark_active_items([
                    [
                        'key' => 'dashboard',
                        'label' => get_string('adminnavdashboard', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.dashboard')->out(false),
                        'icon' => $icondashboard,
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
                        'icon' => $iconusers,
                    ],
                    [
                        'key' => 'provisioning',
                        'label' => get_string('adminnavprovisioning', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.provisioning')->out(false),
                        'icon' => $iconprovisioning,
                    ],
                    [
                        'key' => 'bulkupload',
                        'label' => get_string('adminnavbulkupload', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.bulkupload')->out(false),
                        'icon' => $iconbulk,
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
                        'icon' => $iconacademics,
                    ],
                    [
                        'key' => 'courses',
                        'label' => get_string('adminnavcourses', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.courses')->out(false),
                        'icon' => $iconcourses,
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
                        'icon' => $iconanalytics,
                    ],
                    [
                        'key' => 'reports',
                        'label' => get_string('adminnavreports', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.reports')->out(false),
                        'icon' => $iconreports,
                    ],
                    [
                        'key' => 'auditlogs',
                        'label' => get_string('adminnavauditlogs', 'local_ulms_dashboard'),
                        'url' => $routingservice->get_url_for_route('management.auditlogs')->out(false),
                        'icon' => $iconaudit,
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
                        'icon' => $iconsettings,
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
        $allowed = ['courses', 'reports', 'auditlogs'];
        return in_array($view, $allowed, true) ? $view : 'courses';
    }
}
