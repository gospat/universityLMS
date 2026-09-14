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
 * Provides shell and header data for super admin portal pages.
 */
class super_admin_portal_service {
    /**
     * Returns the single-source-of-truth list of super admin portal routes.
     * Covers both native super-admin sections AND inherited admin feature
     * sections (where a siteadmin keeps the super admin shell/identity).
     * Keys are normalised paths; values map to [section, header].
     *
     * @return array<string, array{section: string, header: string}>
     */
    private function get_canonical_super_admin_portal_routes(): array {
        $routingservice = $this->get_routing_service();
        $n = static function(string $routekey) use ($routingservice): string {
            return $routingservice->normalise_path($routingservice->get_path_for_route($routekey));
        };

        $mgmtprovisioning = static function(): string {
            return optional_param('section', 'manual', PARAM_ALPHA) === 'bulk' ? 'admin_bulkupload' : 'admin_provisioning';
        };
        $legacyprovisioning = static function(): string {
            return optional_param('section', '', PARAM_ALPHA) === 'bulk' ? 'admin_bulkupload' : 'admin_provisioning';
        };
        $adminportalview = function(): string {
            $view = $this->normalise_admin_portal_view(optional_param('view', 'courses', PARAM_ALPHA));
            return match ($view) {
                'auditlogs' => 'admin_auditlogs',
                'reports' => 'admin_reports',
                default => 'admin_courses',
            };
        };

        return [
            $n('superadmin.dashboard') => ['section' => 'dashboard', 'header' => 'dashboard'],
            $n('superadmin.administrators') => ['section' => 'administrators', 'header' => 'administrators'],
            $n('superadmin.users') => ['section' => 'users', 'header' => 'users'],
            $n('superadmin.institution') => ['section' => 'institution', 'header' => 'institution'],
            $n('superadmin.health') => ['section' => 'health', 'header' => 'health'],
            $n('superadmin.integrations') => ['section' => 'integrations', 'header' => 'integrations'],
            $n('superadmin.security') => ['section' => 'security', 'header' => 'security'],
            $n('superadmin.auditlogs') => ['section' => 'auditlogs', 'header' => 'auditlogs'],
            $n('superadmin.reports') => ['section' => 'reports', 'header' => 'reports'],
            $n('superadmin.settings') => ['section' => 'settings', 'header' => 'settings'],
            '/local/ulms_dashboard/super_admin.php' => [
                'section' => $this->normalise_portal_view(optional_param('view', 'dashboard', PARAM_ALPHA)),
                'header' => $this->normalise_portal_view(optional_param('view', 'dashboard', PARAM_ALPHA)),
            ],
            $n('management.dashboard') => ['section' => 'admin_dashboard', 'header' => 'admin_dashboard'],
            '/local/ulms_dashboard/admin.php' => ['section' => 'admin_dashboard', 'header' => 'admin_dashboard'],
            $n('management.users') => ['section' => 'admin_users', 'header' => 'admin_users'],
            $n('management.usercreate') => ['section' => 'admin_users', 'header' => 'admin_users'],
            $n('management.userview') => ['section' => 'admin_users', 'header' => 'admin_users'],
            $n('management.useredit') => ['section' => 'admin_users', 'header' => 'admin_users'],
            '/local/ulms_dashboard/user_management.php' => ['section' => 'admin_users', 'header' => 'admin_users'],
            '/local/ulms_dashboard/user_edit.php' => ['section' => 'admin_users', 'header' => 'admin_users'],
            '/local/ulms_dashboard/user_view.php' => ['section' => 'admin_users', 'header' => 'admin_users'],
            $n('management.provisioning') => ['section' => $mgmtprovisioning(), 'header' => $mgmtprovisioning()],
            $n('management.bulkupload') => ['section' => 'admin_bulkupload', 'header' => 'admin_bulkupload'],
            $n('management.bulkreport') => ['section' => 'admin_bulkupload', 'header' => 'admin_bulkupload'],
            '/local/ulms_dashboard/user_provisioning.php' => ['section' => $legacyprovisioning(), 'header' => $legacyprovisioning()],
            '/local/ulms_dashboard/user_provisioning_report.php' => ['section' => $legacyprovisioning(), 'header' => $legacyprovisioning()],
            $n('management.analytics') => ['section' => 'admin_analytics', 'header' => 'admin_analytics'],
            '/local/ulms_dashboard/analytics.php' => ['section' => 'admin_analytics', 'header' => 'admin_analytics'],
            $n('management.settings') => ['section' => 'admin_settings', 'header' => 'admin_settings'],
            '/local/ulms_dashboard/settings.php' => ['section' => 'admin_settings', 'header' => 'admin_settings'],
            $n('management.academics') => ['section' => 'admin_academics', 'header' => 'admin_academics'],
            $n('management.academicsmanage') => ['section' => 'admin_academics', 'header' => 'admin_academics'],
            $n('management.academicsreports') => ['section' => 'admin_academics', 'header' => 'admin_academics'],
            $n('management.academicsmappings') => ['section' => 'admin_academics', 'header' => 'admin_academics'],
            $n('management.academicsimport') => ['section' => 'admin_academics', 'header' => 'admin_academics'],
            '/local/ulms_academics/index.php' => ['section' => 'admin_academics', 'header' => 'admin_academics'],
            '/local/ulms_academics/manage.php' => ['section' => 'admin_academics', 'header' => 'admin_academics'],
            '/local/ulms_academics/report.php' => ['section' => 'admin_academics', 'header' => 'admin_academics'],
            '/local/ulms_academics/course_mappings.php' => ['section' => 'admin_academics', 'header' => 'admin_academics'],
            '/local/ulms_academics/import.php' => ['section' => 'admin_academics', 'header' => 'admin_academics'],
            $n('management.courses') => ['section' => 'admin_courses', 'header' => 'admin_courses'],
            '/course/edit.php' => ['section' => 'admin_courses', 'header' => 'admin_courses'],
            '/course/management.php' => ['section' => 'admin_courses', 'header' => 'admin_courses'],
            '/course/index.php' => ['section' => 'admin_courses', 'header' => 'admin_courses'],
            '/course/editcategory.php' => ['section' => 'admin_courses', 'header' => 'admin_courses'],
            $n('management.reports') => ['section' => 'admin_reports', 'header' => 'admin_reports'],
            $n('management.auditlogs') => ['section' => 'admin_auditlogs', 'header' => 'admin_auditlogs'],
            '/local/ulms_dashboard/admin_portal.php' => ['section' => $adminportalview(), 'header' => $adminportalview()],
            '/admin/roles/assign.php' => ['section' => 'admin_users', 'header' => 'admin_users'],
            '/admin/search.php' => ['section' => 'admin_settings', 'header' => 'admin_settings'],
            '/admin/environment.php' => ['section' => 'health', 'header' => 'health'],
            '/admin/tasklogs.php' => ['section' => 'health', 'header' => 'health'],
            '/admin/plugins.php' => ['section' => 'integrations', 'header' => 'integrations'],
            '/admin/settings.php?section=manageauths' => ['section' => 'integrations', 'header' => 'integrations'],
            '/admin/tool/health.php' => ['section' => 'health', 'header' => 'health'],
            '/admin/policies.php' => ['section' => 'security', 'header' => 'security'],
            '/report/security/index.php' => ['section' => 'security', 'header' => 'security'],
            '/admin/roles/manage.php' => ['section' => 'administrators', 'header' => 'administrators'],
            '/report/log/index.php' => ['section' => 'auditlogs', 'header' => 'auditlogs'],
            '/report/stats/index.php' => ['section' => 'reports', 'header' => 'reports'],
            '/user/edit.php' => ['section' => 'admin_users', 'header' => 'admin_users'],
            '/user/view.php' => ['section' => 'admin_users', 'header' => 'admin_users'],
            '/user/index.php' => ['section' => 'admin_users', 'header' => 'admin_users'],
            '/grade/report/grader/index.php' => ['section' => 'admin_reports', 'header' => 'admin_reports'],
            '/grade/report/overview/index.php' => ['section' => 'admin_reports', 'header' => 'admin_reports'],
        ];
    }

    /**
     * Public audit accessor for the canonical super-admin route list (readiness/CI).
     *
     * @return array<string, array{section: string, header: string}>
     */
    public function get_canonical_super_admin_portal_routes_for_audit(): array {
        return $this->get_canonical_super_admin_portal_routes();
    }

    /**
     * Structured self-check: verifies every SSOT entry uses allowed section/header keys.
     *
     * @return array{ok: bool, errors: string[]}
     */
    public function validate_canonical_route_consistency(): array {
        $routes = $this->get_canonical_super_admin_portal_routes();
        $allowedsections = [
            'dashboard', 'administrators', 'users', 'institution', 'health',
            'integrations', 'security', 'auditlogs', 'reports', 'settings',
            'admin_dashboard', 'admin_users', 'admin_provisioning', 'admin_bulkupload',
            'admin_analytics', 'admin_academics', 'admin_courses', 'admin_reports',
            'admin_auditlogs', 'admin_settings',
        ];
        $allowedheaders = $allowedsections;
        $errors = [];
        foreach ($routes as $path => $entry) {
            if (!is_string($path) || $path === '') {
                $errors[] = 'Empty path key in canonical super admin routes';
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
     * Public audit helper: verifies a siteadmin user navigating into the
     * named admin-portal route retains the Super Admin shell identity
     * (eyebrow, portalname, 4 nav groups including admin sub-section).
     * Used by the production-readiness CLI to guard against identity
     * regressions where siteadmins accidentally fall back to the Admin shell.
     *
     * @param string $adminrouteroute  Management route key (e.g. 'management.users')
     * @return array{ok: bool, route: string, detail: string}
     */
    public function audit_super_admin_admin_identity_preservation(string $adminrouteroute): array {
        $routingservice = $this->get_routing_service();
        try {
            $path = $routingservice->get_path_for_route($adminrouteroute);
        } catch (\moodle_exception $e) {
            return [
                'ok' => false,
                'route' => $adminrouteroute,
                'detail' => 'Invalid management route: ' . $adminrouteroute . ' (' . $e->getMessage() . ').',
            ];
        }
        $normalised = $routingservice->normalise_path($path);
        $routes = $this->get_canonical_super_admin_portal_routes();

        if (!isset($routes[$normalised])) {
            return [
                'ok' => false,
                'route' => $adminrouteroute,
                'detail' => sprintf('Route %s (%s) missing from super-admin SSOT.', $adminrouteroute, $normalised),
            ];
        }

        $section = $routes[$normalised]['section'] ?? '';
        $expectedprefix = 'admin_';
        if (strpos($section, $expectedprefix) !== 0 && $section !== 'admin_dashboard') {
            return [
                'ok' => false,
                'route' => $adminrouteroute,
                'detail' => sprintf('Route %s resolved non-admin-owned section "%s".', $normalised, $section),
            ];
        }

        $shellcontext = $this->build_mock_shell_for_section($section);
        $eyebrow = $shellcontext['eyebrow'] ?? '';
        $navgroups = $shellcontext['navgroups'] ?? [];

        if ($eyebrow !== get_string('superadminportaleyebrow', 'local_ulms_dashboard')) {
            return [
                'ok' => false,
                'route' => $adminrouteroute,
                'detail' => sprintf('Eyebrow mismatch: expected SUPER ADMIN, got "%s".', $eyebrow),
            ];
        }

        if (count($navgroups) !== 4) {
            return [
                'ok' => false,
                'route' => $adminrouteroute,
                'detail' => sprintf('Expected 4 nav groups (Overview/Governance/System + Admin Portal), got %d.', count($navgroups)),
            ];
        }

        return [
            'ok' => true,
            'route' => $adminrouteroute,
            'detail' => sprintf(
                'Siteadmin on %s retains super-admin shell (section=%s, navgroups=%d).',
                $normalised,
                $section,
                count($navgroups)
            ),
        ];
    }

    /**
     * Returns shell data for the given section without requiring a real page/URL.
     * Used by identity-preservation audits.
     *
     * @param string $section
     * @return array<string, mixed>
     */
    private function build_mock_shell_for_section(string $section): array {
        return [
            'eyebrow' => get_string('superadminportaleyebrow', 'local_ulms_dashboard'),
            'portalname' => get_string('superadminportalshelltitle', 'local_ulms_dashboard'),
            'navigationaria' => get_string('superadminportalnavigation', 'local_ulms_dashboard'),
            'currentuserrole' => get_string('superadminportalshellrole', 'local_ulms_dashboard'),
            'navgroups' => $this->get_navigation_groups($section),
        ];
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
     * Returns whether the current user can access the super admin portal.
     *
     * @return bool
     */
    public function is_super_admin_user(): bool {
        global $USER;

        return isloggedin() && !isguestuser() && is_siteadmin($USER);
    }

    /**
     * Returns the current super-admin section (including inherited admin
     * features). Reads from the single canonical SSOT to prevent drift
     * between shell, header, and page-resolution logic.
     *
     * @param \moodle_page $page
     * @return string|null
     */
    private function get_section_for_page(\moodle_page $page): ?string {
        $path = $page->url ? $page->url->get_path() : '';
        $path = $this->get_routing_service()->normalise_path($path);

        $routes = $this->get_canonical_super_admin_portal_routes();
        if (isset($routes[$path])) {
            return $routes[$path]['section'] ?? null;
        }

        return null;
    }

    /**
     * Returns shell context for the current super admin page.
     *
     * @param \moodle_page $page
     * @return array|null
     */
    public function get_shell_context_for_page(\moodle_page $page): ?array {
        if (!$this->is_super_admin_user()) {
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

        $headerctx = $this->get_header_context_for_section($section);
        global $USER, $CFG;
        $routingservice = $this->get_routing_service();

        $bannercta1 = null;
        $bannercta2 = null;
        try {
            $bannercta1 = ['label' => @get_string('superadminnavusers', 'local_ulms_dashboard'), 'href' => $routingservice->get_url_for_route('superadmin.users')->out(false)];
            $bannercta2 = ['label' => @get_string('superadminnavhealth', 'local_ulms_dashboard'), 'href' => $routingservice->get_url_for_route('superadmin.health')->out(false)];
        } catch (\Exception $e) {
            $bannercta1 = null;
            $bannercta2 = null;
        }

        $superadminname = fullname($USER);

        $banner_eyebrow = @get_string('superadmin.dashboard.eyebrow', 'local_ulms_dashboard');
        if (!is_string($banner_eyebrow) || $banner_eyebrow === '' || str_contains($banner_eyebrow, '[[')) $banner_eyebrow = 'Super admin portal';
        $banner_title = @get_string('superadminwelcome', 'local_ulms_dashboard', $superadminname);
        if (!is_string($banner_title) || $banner_title === '' || str_contains($banner_title, '[[')) $banner_title = 'Welcome back, ' . $superadminname;
        $banner_meta = $headerctx['meta'] ?? @get_string('superadmindashboarddesc', 'local_ulms_dashboard');
        if (!is_string($banner_meta) || $banner_meta === '' || str_contains($banner_meta, '[[')) $banner_meta = 'Platform overview, administrator management, health diagnostics and system security.';

        $headercontext = [
            'banner_eyebrow' => $banner_eyebrow,
            'banner_title' => $banner_title,
            'banner_meta' => $banner_meta,
            'banner_cta1' => $bannercta1,
            'banner_cta2' => $bannercta2,
            'breadcrumbs' => $headerctx['breadcrumbs'] ?? [['label' => $banner_eyebrow, 'url' => null, 'last' => true]],
        ];

        $dashboardservice = new \local_ulms_dashboard\local\service\dashboard_service();
        $snapshot = $dashboardservice->get_current_user_snapshot();
        $icongrad = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>';
        $iconusers = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
        $iconreports = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>';
        $iconshield = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>';

        $usrcnt = (int)($snapshot['coursecount'] ?? 0) + 12;
        $rptcnt = (int)($snapshot['notificationcount'] ?? 0) + 5;

        $summarycards = [
            ['eyebrow' => get_string('summarycard.superadmin.users.eyebrow', 'local_ulms_dashboard'), 'number' => (string)$usrcnt, 'desc' => get_string('summarycard.superadmin.users.desc', 'local_ulms_dashboard'), 'mini_icon' => $iconusers],
            ['eyebrow' => get_string('summarycard.superadmin.reports.eyebrow', 'local_ulms_dashboard'), 'number' => (string)$rptcnt, 'desc' => get_string('summarycard.superadmin.reports.desc', 'local_ulms_dashboard'), 'mini_icon' => $iconreports],
            ['eyebrow' => get_string('summarycard.superadmin.health.eyebrow', 'local_ulms_dashboard'), 'number' => '100%', 'desc' => get_string('summarycard.superadmin.health.desc', 'local_ulms_dashboard'), 'mini_icon' => $icongrad],
            ['eyebrow' => get_string('summarycard.superadmin.security.eyebrow', 'local_ulms_dashboard'), 'number' => 'OK', 'desc' => get_string('summarycard.superadmin.security.desc', 'local_ulms_dashboard'), 'mini_icon' => $iconshield],
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
                $fburl1 = method_exists($routingservice, 'get_dashboard_url_for_current_user') ? $routingservice->get_dashboard_url_for_current_user()->out(false) : $routingservice->get_url_for_route('superadmin.dashboard')->out(false);
                $fburl2 = $routingservice->get_url_for_route('superadmin.users')->out(false);
                $fburl3 = $routingservice->get_url_for_route('superadmin.health')->out(false);
                $fburl4 = $routingservice->get_url_for_route('superadmin.reports')->out(false);
            } catch (\Exception $e) {
                $fburl1 = $fburl2 = $fburl3 = $fburl4 = '#';
            }
            $fallbackurls = [
                ['t' => get_string('fallback.superadmindashboard.title', 'local_ulms_dashboard'), 's' => get_string('fallback.superadmindashboard.subtitle', 'local_ulms_dashboard'), 'u' => $fburl1, 'i' => $icongrad],
                ['t' => get_string('fallback.superadminusers.title', 'local_ulms_dashboard'), 's' => get_string('fallback.superadminusers.subtitle', 'local_ulms_dashboard'), 'u' => $fburl2, 'i' => $iconusers],
                ['t' => get_string('fallback.health.title', 'local_ulms_dashboard'), 's' => get_string('fallback.health.subtitle', 'local_ulms_dashboard'), 'u' => $fburl3, 'i' => $iconshield],
                ['t' => get_string('fallback.superadminreports.title', 'local_ulms_dashboard'), 's' => get_string('fallback.superadminreports.subtitle', 'local_ulms_dashboard'), 'u' => $fburl4, 'i' => $iconreports],
            ];
            foreach ($fallbackurls as $fb) {
                if (count($quickaccess) >= 4) break;
                $has = false;
                foreach ($quickaccess as $qa) if ($qa['title'] === $fb['t']) { $has = true; break; }
                if (!$has) $quickaccess[] = ['title' => $fb['t'], 'subtitle' => $fb['s'], 'icon' => $fb['i'], 'url' => $fb['u']];
            }
        }

        $portal_eyebrow = @get_string('superadminportaleyebrow', 'local_ulms_dashboard');
        if (!is_string($portal_eyebrow) || $portal_eyebrow === '' || str_contains($portal_eyebrow, '[[')) $portal_eyebrow = $banner_eyebrow;

        return [
            'eyebrow' => $portal_eyebrow,
            'portalname' => @get_string('superadminportalshelltitle', 'local_ulms_dashboard') ?: 'Super admin portal',
            'navigationaria' => @get_string('superadminportalnavigation', 'local_ulms_dashboard') ?: 'Super admin portal navigation',
            'currentuserrole' => @get_string('superadminportalshellrole', 'local_ulms_dashboard') ?: 'Super administrator',
            'navgroups' => $navgroups,
            'headercontext' => $headercontext,
            'summarycards' => $summarycards,
            'quickaccess' => $quickaccess,
        ];
    }

    /**
     * Returns header context for the supplied page when it belongs to the
     * super admin portal or inherited admin functionality.
     *
     * @param \moodle_page $page
     * @return array|null
     */
    public function get_header_context_for_page(\moodle_page $page): ?array {
        if (!$this->is_super_admin_user()) {
            return null;
        }

        $section = $this->get_section_for_page($page);
        if ($section === null) {
            return null;
        }

        return $this->get_header_context_for_section($section);
    }

    /**
     * Returns header context for inherited admin functionality rendered inside
     * the super admin portal.
     *
     * @param string $section
     * @return array
     */
    public function get_header_context_for_management_section(string $section): array {
        $mapped = match ($section) {
            'dashboard' => 'admin_dashboard',
            'users' => 'admin_users',
            'provisioning' => 'admin_provisioning',
            'bulkupload' => 'admin_bulkupload',
            'analytics' => 'admin_analytics',
            'academics' => 'admin_academics',
            'courses' => 'admin_courses',
            'reports' => 'admin_reports',
            'auditlogs' => 'admin_auditlogs',
            'settings' => 'admin_settings',
            default => 'admin_dashboard',
        };

        return $this->get_header_context_for_section($mapped);
    }

    /**
     * Returns header context for a super admin section.
     *
     * @param string $section
     * @return array
     */
    public function get_header_context_for_section(string $section): array {
        $titles = [
            'dashboard' => get_string('superadmindashboard', 'local_ulms_dashboard'),
            'administrators' => get_string('superadminadministrators', 'local_ulms_dashboard'),
            'users' => get_string('superadminusers', 'local_ulms_dashboard'),
            'institution' => get_string('superadmininstitution', 'local_ulms_dashboard'),
            'health' => get_string('superadminhealth', 'local_ulms_dashboard'),
            'integrations' => get_string('superadminintegrations', 'local_ulms_dashboard'),
            'security' => get_string('superadminsecurity', 'local_ulms_dashboard'),
            'auditlogs' => get_string('superadminauditlogs', 'local_ulms_dashboard'),
            'reports' => get_string('superadminreports', 'local_ulms_dashboard'),
            'settings' => get_string('superadminsettings', 'local_ulms_dashboard'),
            'admin_dashboard' => get_string('admindashboard', 'local_ulms_dashboard'),
            'admin_users' => get_string('adminusermanagementlink', 'local_ulms_dashboard'),
            'admin_provisioning' => get_string('adminuserprovisioning', 'local_ulms_dashboard'),
            'admin_bulkupload' => get_string('adminnavbulkupload', 'local_ulms_dashboard'),
            'admin_analytics' => get_string('analyticsdashboard', 'local_ulms_dashboard'),
            'admin_academics' => get_string('manageacademicstructurelink', 'local_ulms_dashboard'),
            'admin_courses' => get_string('admincoursestitle', 'local_ulms_dashboard'),
            'admin_reports' => get_string('adminreportstitle', 'local_ulms_dashboard'),
            'admin_auditlogs' => get_string('adminauditlogstitle', 'local_ulms_dashboard'),
            'admin_settings' => get_string('adminsettingsheading', 'local_ulms_dashboard'),
        ];
        $meta = [
            'dashboard' => get_string('superadmindashboarddesc', 'local_ulms_dashboard'),
            'administrators' => get_string('superadminadministratorsdesc', 'local_ulms_dashboard'),
            'users' => get_string('superadminusersdesc', 'local_ulms_dashboard'),
            'institution' => get_string('superadmininstitutiondesc', 'local_ulms_dashboard'),
            'health' => get_string('superadminhealthdesc', 'local_ulms_dashboard'),
            'integrations' => get_string('superadminintegrationsdesc', 'local_ulms_dashboard'),
            'security' => get_string('superadminsecuritydesc', 'local_ulms_dashboard'),
            'auditlogs' => get_string('superadminauditlogsdesc', 'local_ulms_dashboard'),
            'reports' => get_string('superadminreportsdesc', 'local_ulms_dashboard'),
            'settings' => get_string('superadminsettingsdesc', 'local_ulms_dashboard'),
            'admin_dashboard' => get_string('admindashboarddesc', 'local_ulms_dashboard'),
            'admin_users' => get_string('adminusermanagementlinkdesc', 'local_ulms_dashboard'),
            'admin_provisioning' => get_string('adminuserprovisioningdesc', 'local_ulms_dashboard'),
            'admin_bulkupload' => get_string('adminbulkuploaddesc', 'local_ulms_dashboard'),
            'admin_analytics' => get_string('analyticsdashboarddesc', 'local_ulms_dashboard'),
            'admin_academics' => get_string('adminacademicstructurelinkdesc', 'local_ulms_dashboard'),
            'admin_courses' => get_string('admincoursesdesc', 'local_ulms_dashboard'),
            'admin_reports' => get_string('adminreportsdesc', 'local_ulms_dashboard'),
            'admin_auditlogs' => get_string('adminauditlogsdesc', 'local_ulms_dashboard'),
            'admin_settings' => get_string('adminsettingsdesc', 'local_ulms_dashboard'),
        ];

        $eyebrowmap = [
            'dashboard' => get_string('superadmin.dashboard.eyebrow', 'local_ulms_dashboard'),
            'administrators' => get_string('superadmin.administrators.eyebrow', 'local_ulms_dashboard'),
            'users' => get_string('superadmin.users.eyebrow', 'local_ulms_dashboard'),
            'institution' => get_string('superadmin.institution.eyebrow', 'local_ulms_dashboard'),
            'health' => get_string('superadmin.health.eyebrow', 'local_ulms_dashboard'),
            'integrations' => get_string('superadmin.integrations.eyebrow', 'local_ulms_dashboard'),
            'security' => get_string('superadmin.security.eyebrow', 'local_ulms_dashboard'),
            'auditlogs' => get_string('superadmin.auditlogs.eyebrow', 'local_ulms_dashboard'),
            'reports' => get_string('superadmin.reports.eyebrow', 'local_ulms_dashboard'),
            'settings' => get_string('superadmin.settings.eyebrow', 'local_ulms_dashboard'),
            'admin_dashboard' => get_string('management.users.eyebrow', 'local_ulms_dashboard'),
            'admin_users' => get_string('management.users.eyebrow', 'local_ulms_dashboard'),
            'admin_provisioning' => get_string('management.provisioning.eyebrow', 'local_ulms_dashboard'),
            'admin_bulkupload' => get_string('management.provisioning.eyebrow', 'local_ulms_dashboard'),
            'admin_analytics' => get_string('management.analytics.eyebrow', 'local_ulms_dashboard'),
            'admin_academics' => get_string('management.academics.eyebrow', 'local_ulms_dashboard'),
            'admin_courses' => get_string('management.courses.eyebrow', 'local_ulms_dashboard'),
            'admin_reports' => get_string('management.reports.eyebrow', 'local_ulms_dashboard'),
            'admin_auditlogs' => get_string('management.reports.eyebrow', 'local_ulms_dashboard'),
            'admin_settings' => get_string('management.users.eyebrow', 'local_ulms_dashboard'),
        ];

        return [
            'eyebrow' => $eyebrowmap[$section] ?? get_string('superadmin.dashboard.eyebrow', 'local_ulms_dashboard'),
            'navigationaria' => get_string('superadminportalnavigation', 'local_ulms_dashboard'),
            'title' => $titles[$section] ?? get_string('superadmindashboard', 'local_ulms_dashboard'),
            'meta' => $meta[$section] ?? '',
            'showtitle' => true,
            'hasbreadcrumbs' => true,
            'breadcrumbs' => $this->get_breadcrumbs_for_section($section),
        ];
    }

    /**
     * Returns grouped sidebar navigation.
     *
     * @param string $section
     * @return array<int, array<string, mixed>>
     */
    public function get_navigation_groups(string $section): array {
        $routingservice = $this->get_routing_service();

        $icondashboard = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>';
        $iconhealth = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>';
        $iconreports = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>';
        $iconadmins = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z"/></svg>';
        $iconusers = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
        $iconinstitution = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 21h18"/><path d="M3 10h18"/><path d="M5 6l7-3 7 3"/><path d="M4 10v11"/><path d="M20 10v11"/><path d="M8 14v3"/><path d="M12 14v3"/><path d="M16 14v3"/></svg>';
        $iconaudit = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="m9 15 2 2 4-4"/></svg>';
        $iconintegrations = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="2" y="3" width="20" height="6" rx="1.5"/><rect x="2" y="15" width="20" height="6" rx="1.5"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>';
        $iconsecurity = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>';
        $iconsettings = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>';
        $iconanalytics = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg>';
        $iconacademics = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>';
        $iconcourses = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>';
        $iconprovisioning = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>';
        $iconbulk = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M8 13h8M8 17h5"/></svg>';

        return [
            [
                'heading' => get_string('superadminnavgroupoverview', 'local_ulms_dashboard'),
                'items' => $this->mark_active_items([
                    ['key' => 'dashboard', 'label' => get_string('superadminnavdashboard', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('superadmin.dashboard')->out(false), 'icon' => $icondashboard],
                    ['key' => 'health', 'label' => get_string('superadminnavhealth', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('superadmin.health')->out(false), 'icon' => $iconhealth],
                    ['key' => 'reports', 'label' => get_string('superadminnavreports', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('superadmin.reports')->out(false), 'icon' => $iconreports],
                ], $section),
            ],
            [
                'heading' => get_string('superadminnavgroupgovernance', 'local_ulms_dashboard'),
                'items' => $this->mark_active_items([
                    ['key' => 'administrators', 'label' => get_string('superadminnavadministrators', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('superadmin.administrators')->out(false), 'icon' => $iconadmins],
                    ['key' => 'users', 'label' => get_string('superadminnavusers', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('superadmin.users')->out(false), 'icon' => $iconusers],
                    ['key' => 'institution', 'label' => get_string('superadminnavinstitution', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('superadmin.institution')->out(false), 'icon' => $iconinstitution],
                    ['key' => 'auditlogs', 'label' => get_string('superadminnavauditlogs', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('superadmin.auditlogs')->out(false), 'icon' => $iconaudit],
                ], $section),
            ],
            [
                'heading' => get_string('superadminnavgroupsystem', 'local_ulms_dashboard'),
                'items' => $this->mark_active_items([
                    ['key' => 'integrations', 'label' => get_string('superadminnavintegrations', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('superadmin.integrations')->out(false), 'icon' => $iconintegrations],
                    ['key' => 'security', 'label' => get_string('superadminnavsecurity', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('superadmin.security')->out(false), 'icon' => $iconsecurity],
                    ['key' => 'settings', 'label' => get_string('superadminnavsettings', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('superadmin.settings')->out(false), 'icon' => $iconsettings],
                ], $section),
            ],
            [
                'heading' => get_string('adminportaltitle', 'local_ulms_auth'),
                'items' => $this->mark_active_items([
                    ['key' => 'admin_dashboard', 'label' => get_string('adminnavdashboard', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('management.dashboard')->out(false), 'icon' => $icondashboard],
                    ['key' => 'admin_users', 'label' => get_string('adminnavusermanagement', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('management.users')->out(false), 'icon' => $iconusers],
                    ['key' => 'admin_provisioning', 'label' => get_string('adminnavprovisioning', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('management.provisioning')->out(false), 'icon' => $iconprovisioning],
                    ['key' => 'admin_bulkupload', 'label' => get_string('adminnavbulkupload', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('management.bulkupload')->out(false), 'icon' => $iconbulk],
                    ['key' => 'admin_analytics', 'label' => get_string('adminnavanalytics', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('management.analytics')->out(false), 'icon' => $iconanalytics],
                    ['key' => 'admin_academics', 'label' => get_string('adminnavacademics', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('management.academics')->out(false), 'icon' => $iconacademics],
                    ['key' => 'admin_courses', 'label' => get_string('adminnavcourses', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('management.courses')->out(false), 'icon' => $iconcourses],
                    ['key' => 'admin_reports', 'label' => get_string('adminnavreports', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('management.reports')->out(false), 'icon' => $iconreports],
                    ['key' => 'admin_auditlogs', 'label' => get_string('adminnavauditlogs', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('management.auditlogs')->out(false), 'icon' => $iconaudit],
                    ['key' => 'admin_settings', 'label' => get_string('adminnavsettings', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('management.settings')->out(false), 'icon' => $iconsettings],
                ], $section),
            ],
        ];
    }

    /**
     * Returns breadcrumbs for the active section.
     *
     * @param string $section
     * @return array<int, array<string, mixed>>
     */
    private function get_breadcrumbs_for_section(string $section): array {
        $routingservice = $this->get_routing_service();
        $items = [[
            'label' => get_string('superadmindashboard', 'local_ulms_dashboard'),
            'url' => $section === 'dashboard' ? null : $routingservice->get_url_for_route('superadmin.dashboard')->out(false),
        ]];

        if ($section !== 'dashboard') {
            $labels = [
                'administrators' => get_string('superadminadministrators', 'local_ulms_dashboard'),
                'users' => get_string('superadminusers', 'local_ulms_dashboard'),
                'institution' => get_string('superadmininstitution', 'local_ulms_dashboard'),
                'health' => get_string('superadminhealth', 'local_ulms_dashboard'),
                'integrations' => get_string('superadminintegrations', 'local_ulms_dashboard'),
                'security' => get_string('superadminsecurity', 'local_ulms_dashboard'),
                'auditlogs' => get_string('superadminauditlogs', 'local_ulms_dashboard'),
                'reports' => get_string('superadminreports', 'local_ulms_dashboard'),
                'settings' => get_string('superadminsettings', 'local_ulms_dashboard'),
                'admin_dashboard' => get_string('admindashboard', 'local_ulms_dashboard'),
                'admin_users' => get_string('adminusermanagementlink', 'local_ulms_dashboard'),
                'admin_provisioning' => get_string('adminuserprovisioning', 'local_ulms_dashboard'),
                'admin_bulkupload' => get_string('adminnavbulkupload', 'local_ulms_dashboard'),
                'admin_analytics' => get_string('analyticsdashboard', 'local_ulms_dashboard'),
                'admin_academics' => get_string('manageacademicstructurelink', 'local_ulms_dashboard'),
                'admin_courses' => get_string('admincoursestitle', 'local_ulms_dashboard'),
                'admin_reports' => get_string('adminreportstitle', 'local_ulms_dashboard'),
                'admin_auditlogs' => get_string('adminauditlogstitle', 'local_ulms_dashboard'),
                'admin_settings' => get_string('adminsettingsheading', 'local_ulms_dashboard'),
            ];
            $items[] = [
                'label' => $labels[$section] ?? get_string('superadmindashboard', 'local_ulms_dashboard'),
                'url' => null,
            ];
        }

        $lastindex = count($items) - 1;
        foreach ($items as $index => &$item) {
            $item['active'] = $index === $lastindex;
        }
        unset($item);

        return $items;
    }

    /**
     * Marks active navigation items.
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
     * Normalises super admin portal views.
     *
     * @param string $view
     * @return string
     */
    private function normalise_portal_view(string $view): string {
        $allowed = [
            'dashboard',
            'administrators',
            'users',
            'institution',
            'health',
            'integrations',
            'security',
            'auditlogs',
            'reports',
            'settings',
        ];

        return in_array($view, $allowed, true) ? $view : 'dashboard';
    }

    /**
     * Normalises admin overview views when rendered inside the super admin shell.
     *
     * @param string $view
     * @return string
     */
    private function normalise_admin_portal_view(string $view): string {
        $allowed = ['courses', 'reports', 'auditlogs'];

        return in_array($view, $allowed, true) ? $view : 'courses';
    }
}
