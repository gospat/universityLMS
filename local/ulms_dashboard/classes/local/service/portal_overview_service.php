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
 * Builds focused portal overview pages from existing Moodle data.
 */
class portal_overview_service {
    /**
     * Returns the shared ULMS routing service.
     *
     * @return \local_ulms_auth\local\service\landing_page_service
     */
    private function get_routing_service(): \local_ulms_auth\local\service\landing_page_service {
        return new \local_ulms_auth\local\service\landing_page_service();
    }

    /**
     * Returns overview data for student portal sections.
     *
     * @param string $section
     * @return array<string, mixed>
     */
    public function get_student_overview_data(string $section): array {
        $dashboardservice = new dashboard_service();
        $snapshot = $dashboardservice->get_current_user_snapshot();
        $courseids = $snapshot['courseids'];

        return match ($section) {
            'catalog' => $this->build_catalog_section_data($snapshot),
            'assignments' => $this->build_assignment_section_data($snapshot, false),
            'quizzes' => $this->build_quiz_section_data($snapshot, false),
            'progress' => $this->build_progress_section_data($snapshot),
            'timetable' => $this->build_timetable_section_data($snapshot),
            'announcements' => $this->build_announcement_section_data($snapshot, false),
            'messages' => $this->build_messages_section_data($snapshot, false),
            'profile' => $this->build_profile_section_data(false),
            default => [
                'summarycards' => [
                    [
                        'label' => get_string('coursecountsummary', 'local_ulms_dashboard'),
                        'value' => (string)count($courseids),
                        'description' => get_string('studentcoursespagedesc', 'local_ulms_dashboard'),
                    ],
                ],
                'mainpanel' => [
                    'title' => get_string('studentcoursespage', 'local_ulms_dashboard'),
                    'subtitle' => get_string('studentcoursespagedesc', 'local_ulms_dashboard'),
                    'style' => 'cards',
                    'items' => $this->get_course_workspace_cards($courseids, false),
                    'emptytitle' => get_string('studentcoursesempty', 'local_ulms_dashboard'),
                    'emptydesc' => get_string('studentcoursesemptydesc', 'local_ulms_dashboard'),
                ],
                'secondarypanels' => [
                    [
                        'title' => get_string('studentmaterialsheading', 'local_ulms_dashboard'),
                        'subtitle' => get_string('studentmaterialsdesc', 'local_ulms_dashboard'),
                        'style' => 'list',
                        'soft' => true,
                        'items' => $this->get_material_list_items($courseids),
                        'emptytitle' => get_string('studentmaterialsheading', 'local_ulms_dashboard'),
                        'emptydesc' => get_string('studentmaterialsempty', 'local_ulms_dashboard'),
                    ],
                ],
            ],
        };
    }

    /**
     * Returns overview data for lecturer portal sections.
     *
     * @param string $section
     * @return array<string, mixed>
     */
    public function get_lecturer_overview_data(string $section): array {
        $dashboardservice = new dashboard_service();
        $snapshot = $dashboardservice->get_current_user_snapshot();

        return match ($section) {
            'materials' => $this->build_materials_section_data($snapshot, true),
            'assignments' => $this->build_assignment_section_data($snapshot, true),
            'quizzes' => $this->build_quiz_section_data($snapshot, true),
            'students' => $this->build_students_section_data($snapshot),
            'attendance' => $this->build_attendance_section_data($snapshot),
            'grades' => $this->build_lecturer_grades_section_data($snapshot),
            'announcements' => $this->build_announcement_section_data($snapshot, true),
            'messages' => $this->build_messages_section_data($snapshot, true),
            'profile' => $this->build_profile_section_data(true),
            default => [
                'summarycards' => [
                    [
                        'label' => get_string('allocatedcoursessummary', 'local_ulms_dashboard'),
                        'value' => (string)$snapshot['coursecount'],
                        'description' => get_string('lecturercoursespagedesc', 'local_ulms_dashboard'),
                    ],
                ],
                'mainpanel' => [
                    'title' => get_string('lecturercoursespage', 'local_ulms_dashboard'),
                    'subtitle' => get_string('lecturercoursespagedesc', 'local_ulms_dashboard'),
                    'style' => 'cards',
                    'items' => $this->get_course_workspace_cards($snapshot['courseids'], true),
                    'emptytitle' => get_string('lecturercoursesempty', 'local_ulms_dashboard'),
                    'emptydesc' => get_string('lecturercoursesemptydesc', 'local_ulms_dashboard'),
                ],
                'secondarypanels' => [
                    [
                        'title' => get_string('lecturermaterialsheading', 'local_ulms_dashboard'),
                        'subtitle' => get_string('lecturermaterialsdesc', 'local_ulms_dashboard'),
                        'style' => 'list',
                        'soft' => true,
                        'items' => $this->get_material_list_items($snapshot['courseids']),
                        'emptytitle' => get_string('lecturermaterialsheading', 'local_ulms_dashboard'),
                        'emptydesc' => get_string('lecturermaterialsempty', 'local_ulms_dashboard'),
                    ],
                ],
            ],
        };
    }

    /**
     * Returns admin overview data for focused admin sections.
     *
     * @param string $section
     * @return array<string, mixed>
     */
    public function get_admin_overview_data(string $section): array {
        return match ($section) {
            'courses' => $this->build_admin_courses_section_data(),
            'reports' => $this->build_admin_reports_section_data(),
            default => $this->build_admin_audit_section_data(),
        };
    }

    /**
     * Returns super admin overview data.
     *
     * @param string $section
     * @return array<string, mixed>
     */
    public function get_super_admin_overview_data(string $section): array {
        global $CFG, $DB;

        $routingservice = $this->get_routing_service();
        $activeusercutoff = strtotime('-30 days');
        $activeusers = (int)$DB->count_records_select(
            'user',
            'deleted = 0 AND suspended = 0 AND lastaccess >= :cutoff',
            ['cutoff' => $activeusercutoff]
        );
        $administratorcount = $this->count_users_with_roles([
            'manager',
            'coursecreator',
            'ictadmin',
            'facultyadmin',
            'departmentadmin',
        ], true);
        $mailtransport = strtoupper((string)($CFG->ulmsmailtransport ?? 'moodle'));
        $mailtransportconfigured = !empty($CFG->ulmsmailtransport) && $CFG->ulmsmailtransport !== 'moodle';
        $overduetaskscount = $this->get_overdue_scheduled_tasks_count();
        $enabledtaskscount = $this->get_enabled_scheduled_tasks_count();
        $totalplugincount = $this->get_total_plugins_count();
        $diskusagepercent = $this->get_disk_usage_percent();
        $userstotalcount = (int)$DB->count_records('user', ['deleted' => 0]);
        $coursestotalcount = (int)$DB->count_records_select('course', 'id > :sitecourse', ['sitecourse' => 1]);
        $enabledauthplugins = \get_enabled_auth_plugins();
        $enabledauthlist = implode(', ', array_map('strtoupper', $enabledauthplugins));

        if ($section === 'dashboard') {
            $summarycards = [
                [
                    'label' => get_string('superadminsummaryusers', 'local_ulms_dashboard'),
                    'value' => (string)$DB->count_records('user', ['deleted' => 0]),
                    'description' => get_string('superadminsummaryusersdesc', 'local_ulms_dashboard'),
                ],
                [
                    'label' => get_string('superadminsummaryactiveusers', 'local_ulms_dashboard'),
                    'value' => (string)$activeusers,
                    'description' => get_string('superadminsummaryactiveusersdesc', 'local_ulms_dashboard'),
                ],
                [
                    'label' => get_string('superadminsummaryadministrators', 'local_ulms_dashboard'),
                    'value' => (string)$administratorcount,
                    'description' => get_string('superadminsummaryadministratorsdesc', 'local_ulms_dashboard'),
                ],
                [
                    'label' => get_string('superadminsummarycourses', 'local_ulms_dashboard'),
                    'value' => (string)$DB->count_records_select('course', 'id > :sitecourse', ['sitecourse' => 1]),
                    'description' => get_string('superadminsummarycoursesdesc', 'local_ulms_dashboard'),
                ],
            ];
        } else {
            $summarycards = $this->build_sa_section_summarycards($section, get_defined_vars());
        }

        $actionmap = [
            'dashboard' => [
                'title' => get_string('superadminplatformoverviewheading', 'local_ulms_dashboard'),
                'subtitle' => get_string('superadminplatformoverviewintro', 'local_ulms_dashboard'),
                'items' => [
                    ['title' => get_string('superadminhealth', 'local_ulms_dashboard'), 'meta' => get_string('superadminhealthdesc', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('superadmin.health')],
                    ['title' => get_string('superadminsecurity', 'local_ulms_dashboard'), 'meta' => get_string('superadminsecuritydesc', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('superadmin.security')],
                    ['title' => get_string('superadminauditlogs', 'local_ulms_dashboard'), 'meta' => get_string('superadminauditlogsdesc', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('superadmin.auditlogs')],
                    ['title' => get_string('superadminintegrations', 'local_ulms_dashboard'), 'meta' => get_string('superadminintegrationsdesc', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('superadmin.integrations')],
                    ['title' => get_string('superadminsettings', 'local_ulms_dashboard'), 'meta' => get_string('superadminsettingsdesc', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('superadmin.settings')],
                ],
            ],
            'administrators' => [
                'title' => get_string('superadminadministrators', 'local_ulms_dashboard'),
                'subtitle' => get_string('superadminadministratorsdesc', 'local_ulms_dashboard'),
                'items' => [
                    ['title' => 'Assign role grants', 'meta' => 'Current role assignments across all system contexts', 'url' => new \moodle_url('/admin/roles/assign.php')],
                    ['title' => get_string('adminusermanagementlink', 'local_ulms_dashboard'), 'meta' => $administratorcount . ' administrators', 'url' => $routingservice->get_url_for_route('management.users')],
                    ['title' => 'Manage role definitions', 'meta' => get_string('superadminopenroleassignmentsdesc', 'local_ulms_dashboard'), 'url' => new \moodle_url('/admin/roles/manage.php')],
                    ['title' => 'Capability overview', 'meta' => get_string('adminusermanagementlinkdesc', 'local_ulms_dashboard'), 'url' => new \moodle_url('/admin/roles/check.php')],
                ],
            ],
            'users' => [
                'title' => get_string('superadminusers', 'local_ulms_dashboard'),
                'subtitle' => get_string('superadminusersdesc', 'local_ulms_dashboard'),
                'items' => [
                    ['title' => get_string('adminusermanagementlink', 'local_ulms_dashboard'), 'meta' => $userstotalcount . ' total users', 'url' => $routingservice->get_url_for_route('management.users')],
                    ['title' => get_string('adminuserprovisioning', 'local_ulms_dashboard'), 'meta' => $activeusers . ' active (30d)', 'url' => $routingservice->get_url_for_route('management.provisioning')],
                ],
            ],
            'institution' => [
                'title' => get_string('superadmininstitution', 'local_ulms_dashboard'),
                'subtitle' => get_string('superadmininstitutiondesc', 'local_ulms_dashboard'),
                'items' => [
                    ['title' => get_string('manageacademicstructurelink', 'local_ulms_dashboard'), 'meta' => $coursestotalcount . ' courses', 'url' => $routingservice->get_url_for_route('management.academics')],
                    ['title' => get_string('adminnavcourses', 'local_ulms_dashboard'), 'meta' => $coursestotalcount . ' total courses', 'url' => $routingservice->get_url_for_route('management.courses')],
                ],
            ],
            'health' => [
                'title' => get_string('superadminhealth', 'local_ulms_dashboard'),
                'subtitle' => get_string('superadminhealthdesc', 'local_ulms_dashboard'),
                'items' => [
                    ['title' => get_string('superadminopenenvironment', 'local_ulms_dashboard'), 'meta' => \PHP_VERSION, 'url' => new \moodle_url('/admin/environment.php')],
                    ['title' => get_string('superadminopenscheduledtasks', 'local_ulms_dashboard'), 'meta' => $overduetaskscount . ' overdue tasks', 'url' => new \moodle_url('/admin/tasklogs.php')],
                    ['title' => get_string('superadminopenphpinfo', 'local_ulms_dashboard'), 'meta' => \PHP_SAPI, 'url' => new \moodle_url('/admin/phpinfo.php')],
                    ['title' => 'Health tool', 'meta' => 'Deep diagnostic scanner', 'url' => new \moodle_url('/admin/tool/health.php')],
                    ['title' => 'Disk usage', 'meta' => $diskusagepercent . ' used', 'url' => new \moodle_url('/admin/phpinfo.php')],
                    ['title' => 'Scheduled tasks', 'meta' => $enabledtaskscount . ' enabled', 'url' => new \moodle_url('/admin/tasklogs.php')],
                ],
            ],
            'integrations' => [
                'title' => get_string('superadminintegrations', 'local_ulms_dashboard'),
                'subtitle' => get_string('superadminintegrationsdesc', 'local_ulms_dashboard'),
                'items' => [
                    ['title' => get_string('adminsettingspluginscta', 'local_ulms_dashboard'), 'meta' => $totalplugincount . ' plugins registered', 'url' => new \moodle_url('/admin/plugins.php')],
                    ['title' => get_string('adminsettingsauthcta', 'local_ulms_dashboard'), 'meta' => $enabledauthlist, 'url' => new \moodle_url('/admin/settings.php', ['section' => 'manageauths'])],
                    ['title' => 'Mail transport', 'meta' => $mailtransport, 'url' => new \moodle_url('/admin/settings.php', ['section' => 'messagesettingemail'])],
                ],
            ],
            'security' => [
                'title' => get_string('superadminsecurity', 'local_ulms_dashboard'),
                'subtitle' => get_string('superadminsecuritydesc', 'local_ulms_dashboard'),
                'style' => 'cards',
                'items' => array_merge(
                    [
                        ['title' => get_string('superadminopensitepolicies', 'local_ulms_dashboard'), 'meta' => 'configured', 'url' => new \moodle_url('/admin/policies.php')],
                        ['title' => get_string('superadminopensecurityreport', 'local_ulms_dashboard'), 'meta' => 'run now', 'url' => new \moodle_url('/report/security/index.php')],
                    ],
                    [
                        [
                            'title' => 'HTTP security headers',
                            'style' => 'definition',
                            'items' => $this->get_http_security_headers_rows(),
                        ],
                    ]
                ),
            ],
            'auditlogs' => [
                'title' => get_string('superadminauditlogs', 'local_ulms_dashboard'),
                'subtitle' => get_string('superadminauditlogsdesc', 'local_ulms_dashboard'),
                'items' => $this->get_recent_provisioning_list_items(8),
            ],
            'reports' => [
                'title' => get_string('superadminreports', 'local_ulms_dashboard'),
                'subtitle' => get_string('superadminreportsdesc', 'local_ulms_dashboard'),
                'items' => [
                    ['title' => get_string('analyticsdashboard', 'local_ulms_dashboard'), 'meta' => get_string('analyticsdashboarddesc', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('management.analytics')],
                    ['title' => get_string('viewreportslink', 'local_ulms_dashboard'), 'meta' => get_string('adminreportslinkdesc', 'local_ulms_dashboard'), 'url' => $routingservice->get_url_for_route('management.academicsreports')],
                    ['title' => 'System stats', 'meta' => get_string('analyticsdashboarddesc', 'local_ulms_dashboard'), 'url' => new \moodle_url('/report/stats/index.php')],
                ],
            ],
            'settings' => [
                'title' => get_string('superadminsettings', 'local_ulms_dashboard'),
                'subtitle' => get_string('superadminsettingsdesc', 'local_ulms_dashboard'),
                'items' => [
                    ['title' => get_string('adminsettingscorecta', 'local_ulms_dashboard'), 'meta' => 'Search tree', 'url' => new \moodle_url('/admin/search.php')],
                    ['title' => get_string('adminsettingsservercta', 'local_ulms_dashboard'), 'meta' => \PHP_VERSION, 'url' => new \moodle_url('/admin/phpinfo.php')],
                    ['title' => 'User policies', 'meta' => get_string('superadminopensitepoliciesdesc', 'local_ulms_dashboard'), 'url' => new \moodle_url('/admin/settings.php', ['section' => 'sitepolicies'])],
                    ['title' => 'Plugin management', 'meta' => get_string('adminsettingspluginsctadesc', 'local_ulms_dashboard'), 'url' => new \moodle_url('/admin/plugins.php')],
                    ['title' => 'Theme settings', 'meta' => get_string('adminsettingsserverctadesc', 'local_ulms_dashboard'), 'url' => new \moodle_url('/admin/settings.php', ['section' => 'themesettings'])],
                ],
            ],
        ];

        $mainpanel = $actionmap[$section] ?? $actionmap['dashboard'];
        $mainpanel['style'] = ($section === 'auditlogs') ? 'list' : ($mainpanel['style'] ?? 'cards');
        $mainpanel['emptytitle'] = get_string('norecentactivity', 'local_ulms_dashboard');
        $mainpanel['emptydesc'] = get_string('norecentactivitydesc', 'local_ulms_dashboard');

        return [
            'summarycards' => $summarycards,
            'mainpanel' => $mainpanel,
            'secondarypanels' => $section === 'dashboard' ? [
                [
                    'title' => get_string('superadminactivitypaneltitle', 'local_ulms_dashboard'),
                    'subtitle' => get_string('superadminactivitypanelintro', 'local_ulms_dashboard'),
                    'style' => 'list',
                    'items' => $this->get_recent_provisioning_list_items(6),
                    'emptytitle' => get_string('norecentactivity', 'local_ulms_dashboard'),
                    'emptydesc' => get_string('norecentactivitydesc', 'local_ulms_dashboard'),
                ],
            ] : [],
        ];
    }

    /**
     * Builds domain-specific summary cards for non-dashboard super-admin sections.
     *
     * @param string $section
     * @param array<string, mixed> $ctx
     * @return array<int, array<string, mixed>>
     */
    private function build_sa_section_summarycards(string $section, array $ctx): array {
        $activeusers = (int)($ctx['activeusers'] ?? 0);
        $administratorcount = (int)($ctx['administratorcount'] ?? 0);
        $userstotalcount = (int)($ctx['userstotalcount'] ?? 0);
        $coursestotalcount = (int)($ctx['coursestotalcount'] ?? 0);
        $overduetaskscount = (int)($ctx['overduetaskscount'] ?? 0);
        $enabledtaskscount = (int)($ctx['enabledtaskscount'] ?? 0);
        $totalplugincount = (int)($ctx['totalplugincount'] ?? 0);
        $diskusagepercent = (string)($ctx['diskusagepercent'] ?? 'N/A');
        $enabledauthlist = (string)($ctx['enabledauthlist'] ?? '');
        $mailtransport = (string)($ctx['mailtransport'] ?? 'MOODLE');

        return match ($section) {
            'health' => [
                ['label' => 'PHP version', 'value' => \PHP_VERSION, 'description' => 'Current runtime version'],
                ['label' => 'Overdue tasks', 'value' => (string)$overduetaskscount, 'description' => 'Scheduled tasks past due'],
                ['label' => 'Disk usage', 'value' => $diskusagepercent, 'description' => 'Dataroot disk consumption'],
                ['label' => 'Enabled tasks', 'value' => (string)$enabledtaskscount, 'description' => 'Active scheduled tasks'],
            ],
            'administrators' => [
                ['label' => 'Administrators', 'value' => (string)$administratorcount, 'description' => 'High-privilege accounts'],
                ['label' => 'Active (30d)', 'value' => (string)$activeusers, 'description' => 'Users active last 30 days'],
                ['label' => 'Users total', 'value' => (string)$userstotalcount, 'description' => 'Total user records'],
                ['label' => 'Role grants', 'value' => (string)$administratorcount, 'description' => 'System role assignments'],
            ],
            'users' => [
                ['label' => 'Total users', 'value' => (string)$userstotalcount, 'description' => 'All platform user records'],
                ['label' => 'Active (30d)', 'value' => (string)$activeusers, 'description' => 'Recently active accounts'],
                ['label' => 'Provisioned (CSV)', 'value' => '0', 'description' => 'CSV-provisioned accounts'],
                ['label' => 'Pending', 'value' => '0', 'description' => 'Pending provisioning items'],
            ],
            'institution' => [
                ['label' => 'Faculties', 'value' => '0', 'description' => 'College-level structure entries'],
                ['label' => 'Departments', 'value' => '0', 'description' => 'Department-level entries'],
                ['label' => 'Programmes', 'value' => '0', 'description' => 'Programme of study records'],
                ['label' => 'Courses', 'value' => (string)$coursestotalcount, 'description' => 'Total course spaces'],
            ],
            'security' => [
                ['label' => 'HTTP headers', 'value' => '4', 'description' => 'Configured security headers'],
                ['label' => 'Site policies', 'value' => 'Configured', 'description' => 'Active policy controls'],
                ['label' => 'Security report', 'value' => 'Run now', 'description' => 'Diagnostic scanner'],
                ['label' => 'Enabled auth', 'value' => $enabledauthlist === '' ? '1' : (string)count(explode(', ', $enabledauthlist)), 'description' => 'Active auth methods'],
            ],
            'integrations' => [
                ['label' => 'Plugins', 'value' => (string)$totalplugincount, 'description' => 'Registered plugin configurations'],
                ['label' => 'Auth list', 'value' => $enabledauthlist === '' ? 'MANUAL' : $enabledauthlist, 'description' => 'Enabled authentication plugins'],
                ['label' => 'Mail transport', 'value' => $mailtransport, 'description' => 'Outbound email provider'],
                ['label' => 'Filters', 'value' => '0', 'description' => 'Enabled text filters'],
            ],
            'auditlogs' => [
                ['label' => 'Today', 'value' => '0', 'description' => 'Audit events recorded today'],
                ['label' => 'Provisioning', 'value' => '0', 'description' => 'Provisioning audit entries'],
                ['label' => 'Access', 'value' => '0', 'description' => 'Access-audit entries'],
                ['label' => 'Bulk', 'value' => '0', 'description' => 'Bulk operation entries'],
            ],
            'reports' => [
                ['label' => 'Programmes', 'value' => '0', 'description' => 'Programme reporting coverage'],
                ['label' => 'Users total', 'value' => (string)$userstotalcount, 'description' => 'Users available for reporting'],
                ['label' => 'Reporting accessible', 'value' => '4', 'description' => 'Accessible report modules'],
                ['label' => 'Analytics', 'value' => 'Enabled', 'description' => 'Analytics workspace status'],
            ],
            'settings' => [
                ['label' => 'Plugin types', 'value' => '0', 'description' => 'Plugin type categories'],
                ['label' => 'Search tree', 'value' => 'Available', 'description' => 'Core settings search index'],
                ['label' => 'Theme', 'value' => 'ulms_university', 'description' => 'Active platform theme'],
                ['label' => 'Mail transport', 'value' => $mailtransport, 'description' => 'Outbound email provider'],
            ],
            default => [],
        };
    }

    /**
     * Returns header context for a super-admin section with per-section eyebrow.
     *
     * @param string $section
     * @return array<string, mixed>
     */
    public function get_header_context_for_section(string $section): array {
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
        ];

        $titles = [
            'dashboard' => get_string('superadminplatformoverviewheading', 'local_ulms_dashboard'),
            'administrators' => get_string('superadminadministrators', 'local_ulms_dashboard'),
            'users' => get_string('superadminusers', 'local_ulms_dashboard'),
            'institution' => get_string('superadmininstitution', 'local_ulms_dashboard'),
            'health' => get_string('superadminhealth', 'local_ulms_dashboard'),
            'integrations' => get_string('superadminintegrations', 'local_ulms_dashboard'),
            'security' => get_string('superadminsecurity', 'local_ulms_dashboard'),
            'auditlogs' => get_string('superadminauditlogs', 'local_ulms_dashboard'),
            'reports' => get_string('superadminreports', 'local_ulms_dashboard'),
            'settings' => get_string('superadminsettings', 'local_ulms_dashboard'),
        ];

        return [
            'eyebrow' => $eyebrowmap[$section] ?? get_string('superadmin.dashboard.eyebrow', 'local_ulms_dashboard'),
            'title' => $titles[$section] ?? get_string('superadminplatformoverviewheading', 'local_ulms_dashboard'),
        ];
    }

    /**
     * Builds a catalog page.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function build_catalog_section_data(array $snapshot): array {
        global $DB;

        $courses = $DB->get_records_select('course', 'id > :sitecourse AND visible = :visible', [
            'sitecourse' => 1,
            'visible' => 1,
        ], 'fullname ASC', 'id, fullname, summary', 0, 12);

        $items = [];
        foreach ($courses as $course) {
            $items[] = [
                'title' => format_string($course->fullname),
                'meta' => shorten_text(strip_tags(format_text((string)$course->summary, FORMAT_HTML)), 120),
                'url' => new \moodle_url('/course/view.php', ['id' => (int)$course->id]),
                'footer' => get_string('studentcatalogopen', 'local_ulms_dashboard'),
            ];
        }

        return [
            'summarycards' => [
                ['label' => get_string('studentcatalogsummaryavailable', 'local_ulms_dashboard'), 'value' => (string)count($courses), 'description' => get_string('studentcatalogsummaryavailabledesc', 'local_ulms_dashboard')],
                ['label' => get_string('coursecountsummary', 'local_ulms_dashboard'), 'value' => (string)$snapshot['coursecount'], 'description' => get_string('studentcoursescountdesc', 'local_ulms_dashboard')],
            ],
            'mainpanel' => [
                'title' => get_string('studentcatalogtitle', 'local_ulms_dashboard'),
                'subtitle' => get_string('studentcatalogdesc', 'local_ulms_dashboard'),
                'style' => 'cards',
                'items' => $items,
                'emptytitle' => get_string('studentcatalogempty', 'local_ulms_dashboard'),
                'emptydesc' => get_string('studentcatalogemptydesc', 'local_ulms_dashboard'),
            ],
            'secondarypanels' => [],
        ];
    }

    /**
     * Builds materials section data.
     *
     * @param array<string, mixed> $snapshot
     * @param bool $islecturer
     * @return array<string, mixed>
     */
    private function build_materials_section_data(array $snapshot, bool $islecturer): array {
        $courseids = $snapshot['courseids'];

        return [
            'summarycards' => [
                ['label' => $islecturer ? get_string('allocatedcoursessummary', 'local_ulms_dashboard') : get_string('coursecountsummary', 'local_ulms_dashboard'), 'value' => (string)$snapshot['coursecount'], 'description' => $islecturer ? get_string('lecturercoursescountdesc', 'local_ulms_dashboard') : get_string('studentcoursescountdesc', 'local_ulms_dashboard')],
                ['label' => get_string('studentmaterialssummary', 'local_ulms_dashboard'), 'value' => (string)count($this->get_material_list_items($courseids)), 'description' => $islecturer ? get_string('lecturermaterialsdesc', 'local_ulms_dashboard') : get_string('studentmaterialsdesc', 'local_ulms_dashboard')],
            ],
            'mainpanel' => [
                'title' => $islecturer ? get_string('lecturermaterialsheading', 'local_ulms_dashboard') : get_string('studentmaterialsheading', 'local_ulms_dashboard'),
                'subtitle' => $islecturer ? get_string('lecturermaterialsdesc', 'local_ulms_dashboard') : get_string('studentmaterialsdesc', 'local_ulms_dashboard'),
                'style' => 'list',
                'items' => $this->get_material_list_items($courseids),
                'emptytitle' => $islecturer ? get_string('lecturermaterialsempty', 'local_ulms_dashboard') : get_string('studentmaterialsempty', 'local_ulms_dashboard'),
                'emptydesc' => $islecturer ? get_string('lecturermaterialsemptydesc', 'local_ulms_dashboard') : get_string('studentmaterialsemptydesc', 'local_ulms_dashboard'),
            ],
            'secondarypanels' => [],
        ];
    }

    /**
     * Builds assignment section data.
     *
     * @param array<string, mixed> $snapshot
     * @param bool $islecturer
     * @return array<string, mixed>
     */
    private function build_assignment_section_data(array $snapshot, bool $islecturer): array {
        $items = $this->get_assignment_list_items($snapshot['courseids'], $islecturer ? 12 : 10);

        return [
            'summarycards' => [
                ['label' => get_string('assignmentsummary', 'local_ulms_dashboard'), 'value' => (string)count($items), 'description' => $islecturer ? get_string('lecturerassignmentsdesc', 'local_ulms_dashboard') : get_string('studentassignmentsdesc', 'local_ulms_dashboard')],
                ['label' => $islecturer ? get_string('gradingqueuesummary', 'local_ulms_dashboard') : get_string('upcomingdeadlinessummary', 'local_ulms_dashboard'), 'value' => (string)count($items), 'description' => $islecturer ? get_string('lecturergradingcountdesc', 'local_ulms_dashboard') : get_string('studentdeadlinesdesc', 'local_ulms_dashboard')],
            ],
            'mainpanel' => [
                'title' => $islecturer ? get_string('lecturerassignmentstitle', 'local_ulms_dashboard') : get_string('studentassignmentstitle', 'local_ulms_dashboard'),
                'subtitle' => $islecturer ? get_string('lecturerassignmentsdesc', 'local_ulms_dashboard') : get_string('studentassignmentsdesc', 'local_ulms_dashboard'),
                'style' => 'list',
                'items' => $items,
                'emptytitle' => $islecturer ? get_string('lecturerassignmentsempty', 'local_ulms_dashboard') : get_string('studentassignmentsempty', 'local_ulms_dashboard'),
                'emptydesc' => $islecturer ? get_string('lecturerassignmentsemptydesc', 'local_ulms_dashboard') : get_string('studentassignmentsemptydesc', 'local_ulms_dashboard'),
            ],
            'secondarypanels' => [],
        ];
    }

    /**
     * Builds quiz section data.
     *
     * @param array<string, mixed> $snapshot
     * @param bool $islecturer
     * @return array<string, mixed>
     */
    private function build_quiz_section_data(array $snapshot, bool $islecturer): array {
        $items = $this->get_quiz_list_items($snapshot['courseids'], 10);

        return [
            'summarycards' => [
                ['label' => get_string('studentquizsummary', 'local_ulms_dashboard'), 'value' => (string)count($items), 'description' => $islecturer ? get_string('lecturerquizzesdesc', 'local_ulms_dashboard') : get_string('studentquizzesdesc', 'local_ulms_dashboard')],
                ['label' => get_string('coursecountsummary', 'local_ulms_dashboard'), 'value' => (string)$snapshot['coursecount'], 'description' => $islecturer ? get_string('lecturercoursespagedesc', 'local_ulms_dashboard') : get_string('studentcoursespagedesc', 'local_ulms_dashboard')],
            ],
            'mainpanel' => [
                'title' => $islecturer ? get_string('lecturerquizzestitle', 'local_ulms_dashboard') : get_string('studentquizzestitle', 'local_ulms_dashboard'),
                'subtitle' => $islecturer ? get_string('lecturerquizzesdesc', 'local_ulms_dashboard') : get_string('studentquizzesdesc', 'local_ulms_dashboard'),
                'style' => 'list',
                'items' => $items,
                'emptytitle' => $islecturer ? get_string('lecturerquizzesempty', 'local_ulms_dashboard') : get_string('studentquizzesempty', 'local_ulms_dashboard'),
                'emptydesc' => $islecturer ? get_string('lecturerquizzesemptydesc', 'local_ulms_dashboard') : get_string('studentquizzesemptydesc', 'local_ulms_dashboard'),
            ],
            'secondarypanels' => [],
        ];
    }

    /**
     * Builds student progress section data.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function build_progress_section_data(array $snapshot): array {
        $items = $this->get_progress_list_items($snapshot['courses']);

        return [
            'summarycards' => [
                ['label' => get_string('completionsummary', 'local_ulms_dashboard'), 'value' => $snapshot['completion']['percent'] . '%', 'description' => get_string('studentcompletiondesc', 'local_ulms_dashboard')],
                ['label' => get_string('coursecountsummary', 'local_ulms_dashboard'), 'value' => (string)$snapshot['coursecount'], 'description' => get_string('studentcoursescountdesc', 'local_ulms_dashboard')],
            ],
            'mainpanel' => [
                'title' => get_string('studentprogresstitle', 'local_ulms_dashboard'),
                'subtitle' => get_string('studentprogressdesc', 'local_ulms_dashboard'),
                'style' => 'list',
                'items' => $items,
                'emptytitle' => get_string('studentprogressempty', 'local_ulms_dashboard'),
                'emptydesc' => get_string('studentprogressemptydesc', 'local_ulms_dashboard'),
            ],
            'secondarypanels' => [],
        ];
    }

    /**
     * Builds timetable section data.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function build_timetable_section_data(array $snapshot): array {
        return [
            'summarycards' => [
                ['label' => get_string('upcomingeventssummary', 'local_ulms_dashboard'), 'value' => (string)count($snapshot['events']), 'description' => get_string('studenttimetabledesc', 'local_ulms_dashboard')],
            ],
            'mainpanel' => [
                'title' => get_string('studenttimetabletitle', 'local_ulms_dashboard'),
                'subtitle' => get_string('studenttimetabledesc', 'local_ulms_dashboard'),
                'style' => 'list',
                'items' => $this->map_event_items($snapshot['events']),
                'emptytitle' => get_string('studenttimetableempty', 'local_ulms_dashboard'),
                'emptydesc' => get_string('studenttimetableemptydesc', 'local_ulms_dashboard'),
            ],
            'secondarypanels' => [],
        ];
    }

    /**
     * Builds announcements section data.
     *
     * @param array<string, mixed> $snapshot
     * @param bool $islecturer
     * @return array<string, mixed>
     */
    private function build_announcement_section_data(array $snapshot, bool $islecturer): array {
        $items = $this->get_announcement_list_items($snapshot['courseids'], 10);

        return [
            'summarycards' => [
                [
                    'label' => get_string('studentannouncementssummary', 'local_ulms_dashboard'),
                    'value' => (string)count($items),
                    'description' => $islecturer
                        ? get_string('lecturerannouncementsdesc', 'local_ulms_dashboard')
                        : get_string('studentannouncementsdesc', 'local_ulms_dashboard'),
                ],
            ],
            'mainpanel' => [
                'title' => $islecturer
                    ? get_string('lecturerannouncementstitle', 'local_ulms_dashboard')
                    : get_string('studentannouncementstitle', 'local_ulms_dashboard'),
                'subtitle' => $islecturer
                    ? get_string('lecturerannouncementsdesc', 'local_ulms_dashboard')
                    : get_string('studentannouncementsdesc', 'local_ulms_dashboard'),
                'style' => 'list',
                'items' => $items,
                'emptytitle' => get_string('studentannouncementsempty', 'local_ulms_dashboard'),
                'emptydesc' => get_string('studentannouncementsemptydesc', 'local_ulms_dashboard'),
            ],
            'secondarypanels' => [],
        ];
    }

    /**
     * Builds message overview section data.
     *
     * @param array<string, mixed> $snapshot
     * @param bool $islecturer
     * @return array<string, mixed>
     */
    private function build_messages_section_data(array $snapshot, bool $islecturer): array {
        return [
            'summarycards' => [
                ['label' => get_string('notificationssummary', 'local_ulms_dashboard'), 'value' => (string)$snapshot['notificationcount'], 'description' => $islecturer ? get_string('lecturermessagespagedesc', 'local_ulms_dashboard') : get_string('studentmessagespagedesc', 'local_ulms_dashboard')],
            ],
            'mainpanel' => [
                'title' => $islecturer ? get_string('lecturermessagespage', 'local_ulms_dashboard') : get_string('studentmessagespage', 'local_ulms_dashboard'),
                'subtitle' => $islecturer ? get_string('lecturermessagespagedesc', 'local_ulms_dashboard') : get_string('studentmessagespagedesc', 'local_ulms_dashboard'),
                'style' => 'cards',
                'items' => [
                    [
                        'title' => $islecturer
                            ? get_string('lecturermessagespage', 'local_ulms_dashboard')
                            : get_string('studentmessagespage', 'local_ulms_dashboard'),
                        'meta' => get_string('portalmessagesopeninbox', 'local_ulms_dashboard'),
                        'url' => new \moodle_url('/message/index.php'),
                    ],
                ],
            ],
            'secondarypanels' => [],
        ];
    }

    /**
     * Builds profile section data.
     *
     * @param bool $islecturer
     * @return array<string, mixed>
     */
    private function build_profile_section_data(bool $islecturer): array {
        global $USER;

        return [
            'summarycards' => [],
            'mainpanel' => [
                'title' => $islecturer ? get_string('lecturerprofiletitle', 'local_ulms_dashboard') : get_string('studentprofiletitle', 'local_ulms_dashboard'),
                'subtitle' => $islecturer ? get_string('lecturerprofiledesc', 'local_ulms_dashboard') : get_string('studentprofiledesc', 'local_ulms_dashboard'),
                'style' => 'definition',
                'items' => [
                    ['label' => get_string('fullnameuser'), 'value' => fullname($USER)],
                    ['label' => get_string('email'), 'value' => (string)$USER->email],
                    ['label' => get_string('username'), 'value' => (string)$USER->username],
                    ['label' => get_string('lastaccess'), 'value' => !empty($USER->lastaccess) ? userdate((int)$USER->lastaccess) : get_string('never')],
                ],
            ],
            'secondarypanels' => [[
                'title' => get_string('studentprofileactions', 'local_ulms_dashboard'),
                'subtitle' => get_string('studentprofileactionsdesc', 'local_ulms_dashboard'),
                'style' => 'cards',
                'soft' => true,
                'items' => [
                    ['title' => get_string('profile'), 'meta' => get_string('portalprofileopen', 'local_ulms_dashboard'), 'url' => new \moodle_url('/user/profile.php', ['id' => (int)$USER->id])],
                    ['title' => get_string('passwordresetlink', 'local_ulms_auth'), 'meta' => get_string('portalprofileresetpassword', 'local_ulms_dashboard'), 'url' => $this->get_routing_service()->get_url_for_route($islecturer ? 'lecturer.passwordreset' : 'student.passwordreset')],
                ],
            ]],
        ];
    }

    /**
     * Builds lecturer students section data.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function build_students_section_data(array $snapshot): array {
        $items = $this->get_course_roster_list_items($snapshot['courseids']);

        return [
            'summarycards' => [
                ['label' => get_string('lecturerstudentssummary', 'local_ulms_dashboard'), 'value' => (string)count($items), 'description' => get_string('lecturerstudentsdesc', 'local_ulms_dashboard')],
            ],
            'mainpanel' => [
                'title' => get_string('lecturerstudentstitle', 'local_ulms_dashboard'),
                'subtitle' => get_string('lecturerstudentsdesc', 'local_ulms_dashboard'),
                'style' => 'list',
                'items' => $items,
                'emptytitle' => get_string('lecturerstudentsempty', 'local_ulms_dashboard'),
                'emptydesc' => get_string('lecturerstudentsemptydesc', 'local_ulms_dashboard'),
            ],
            'secondarypanels' => [],
        ];
    }

    /**
     * Builds lecturer attendance section data.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function build_attendance_section_data(array $snapshot): array {
        $items = $this->map_event_items($snapshot['events']);

        return [
            'summarycards' => [
                ['label' => get_string('upcomingeventssummary', 'local_ulms_dashboard'), 'value' => (string)count($items), 'description' => get_string('lecturerattendancedesc', 'local_ulms_dashboard')],
            ],
            'mainpanel' => [
                'title' => get_string('lecturerattendancetitle', 'local_ulms_dashboard'),
                'subtitle' => get_string('lecturerattendancedesc', 'local_ulms_dashboard'),
                'style' => 'list',
                'items' => $items,
                'emptytitle' => get_string('lecturerattendanceempty', 'local_ulms_dashboard'),
                'emptydesc' => get_string('lecturerattendanceemptydesc', 'local_ulms_dashboard'),
            ],
            'secondarypanels' => [],
        ];
    }

    /**
     * Builds lecturer grades section data.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function build_lecturer_grades_section_data(array $snapshot): array {
        $items = $this->get_assignment_list_items($snapshot['courseids'], 10);

        return [
            'summarycards' => [
                ['label' => get_string('gradingqueuesummary', 'local_ulms_dashboard'), 'value' => (string)count($items), 'description' => get_string('lecturergradesdesc', 'local_ulms_dashboard')],
            ],
            'mainpanel' => [
                'title' => get_string('lecturergradestitle', 'local_ulms_dashboard'),
                'subtitle' => get_string('lecturergradesdesc', 'local_ulms_dashboard'),
                'style' => 'list',
                'items' => $items,
                'emptytitle' => get_string('lecturergradesempty', 'local_ulms_dashboard'),
                'emptydesc' => get_string('lecturergradesemptydesc', 'local_ulms_dashboard'),
            ],
            'secondarypanels' => [],
        ];
    }

    /**
     * Builds admin courses section data.
     *
     * @return array<string, mixed>
     */
    private function build_admin_courses_section_data(): array {
        global $DB;

        $courses = $DB->get_records_select('course', 'id > :sitecourse', ['sitecourse' => 1], 'timemodified DESC', 'id, fullname, visible, timemodified', 0, 12);
        $items = [];
        foreach ($courses as $course) {
            $items[] = [
                'title' => format_string($course->fullname),
                'meta' => ($course->visible ? get_string('activecoursessummary', 'local_ulms_dashboard') : get_string('hidden')) . ' - ' . userdate((int)$course->timemodified),
                'url' => new \moodle_url('/course/view.php', ['id' => (int)$course->id]),
                'footer' => get_string('admincourseopen', 'local_ulms_dashboard'),
            ];
        }

        return [
            'summarycards' => [
                ['label' => get_string('totalcoursessummary', 'local_ulms_dashboard'), 'value' => (string)$DB->count_records_select('course', 'id > :sitecourse', ['sitecourse' => 1]), 'description' => get_string('admincoursecataloglinkdesc', 'local_ulms_dashboard')],
            ],
            'mainpanel' => [
                'title' => get_string('admincoursestitle', 'local_ulms_dashboard'),
                'subtitle' => get_string('admincoursesdesc', 'local_ulms_dashboard'),
                'style' => 'cards',
                'items' => $items,
                'emptytitle' => get_string('admincoursesempty', 'local_ulms_dashboard'),
                'emptydesc' => get_string('admincoursesemptydesc', 'local_ulms_dashboard'),
            ],
            'secondarypanels' => [
                [
                    'title' => get_string('admincourseactionstitle', 'local_ulms_dashboard'),
                    'subtitle' => get_string('admincourseactionsdesc', 'local_ulms_dashboard'),
                    'style' => 'cards',
                    'soft' => true,
                    'items' => $this->get_admin_course_action_items(),
                    'emptytitle' => get_string('admincoursestitle', 'local_ulms_dashboard'),
                    'emptydesc' => get_string('admincourseactionsemptydesc', 'local_ulms_dashboard'),
                ],
            ],
        ];
    }

    /**
     * Returns action cards for admin course workflows.
     *
     * @return array<int, array<string, mixed>>
     */
    private function get_admin_course_action_items(): array {
        $items = [];
        $createurl = $this->get_first_course_creation_url();
        if ($createurl !== null) {
            $items[] = [
                'title' => get_string('addnewcourse'),
                'meta' => get_string('admincoursecreatelinkdesc', 'local_ulms_dashboard'),
                'url' => $createurl,
                'footer' => get_string('admincoursecreatecta', 'local_ulms_dashboard'),
            ];
        }

        $managementurl = $this->get_first_course_management_url();
        if ($managementurl !== null) {
            $items[] = [
                'title' => get_string('coursemgmt', 'admin'),
                'meta' => get_string('admincoursemanagementlinkdesc', 'local_ulms_dashboard'),
                'url' => $managementurl,
                'footer' => get_string('admincoursemanagementcta', 'local_ulms_dashboard'),
            ];
        }

        $items[] = [
            'title' => get_string('manageacademicstructurelink', 'local_ulms_dashboard'),
            'meta' => get_string('adminacademicstructurelinkdesc', 'local_ulms_dashboard'),
            'url' => $this->get_routing_service()->get_url_for_route('management.academics'),
            'footer' => get_string('adminnavacademics', 'local_ulms_dashboard'),
        ];

        return $items;
    }

    /**
     * Returns the first course-creation URL available to the current user.
     *
     * @return \moodle_url|null
     */
    private function get_first_course_creation_url(): ?\moodle_url {
        $category = \core_course_category::get_nearest_editable_subcategory(
            \core_course_category::top(),
            ['moodle/course:create']
        );
        $categoryid = $this->resolve_course_action_category_id($category);

        if ($categoryid === null) {
            return null;
        }

        return new \moodle_url('/course/edit.php', [
            'category' => $categoryid,
            'returnto' => 'catmanage',
        ]);
    }

    /**
     * Returns the first course-management URL available to the current user.
     *
     * @return \moodle_url|null
     */
    private function get_first_course_management_url(): ?\moodle_url {
        $category = \core_course_category::get_nearest_editable_subcategory(
            \core_course_category::top(),
            ['moodle/category:manage']
        );
        $categoryid = $this->resolve_course_action_category_id($category);

        if ($categoryid === null) {
            return null;
        }

        return new \moodle_url('/course/management.php', [
            'categoryid' => $categoryid,
        ]);
    }

    /**
     * Normalises a course action category so it always points to a real
     * course category instead of the top pseudo-category.
     *
     * @param \core_course_category|null $category
     * @return int|null
     */
    private function resolve_course_action_category_id(?\core_course_category $category): ?int {
        global $CFG;

        if ($category === null) {
            return null;
        }

        if ((int)$category->id > 0) {
            return (int)$category->id;
        }

        $defaultcategoryid = (int)($CFG->defaultrequestcategory ?? 0);
        if ($defaultcategoryid > 0) {
            return $defaultcategoryid;
        }

        $topchildren = \core_course_category::top()->get_children();
        if (empty($topchildren)) {
            return null;
        }

        $firstcategory = reset($topchildren);
        if ($firstcategory === false) {
            return null;
        }

        return (int)$firstcategory->id;
    }

    /**
     * Builds admin reports section data.
     *
     * @return array<string, mixed>
     */
    private function build_admin_reports_section_data(): array {
        return [
            'summarycards' => [],
            'mainpanel' => [
                'title' => get_string('adminreportstitle', 'local_ulms_dashboard'),
                'subtitle' => get_string('adminreportsdesc', 'local_ulms_dashboard'),
                'style' => 'cards',
                'items' => [
                    ['title' => get_string('analyticsdashboard', 'local_ulms_dashboard'), 'meta' => get_string('analyticsdashboarddesc', 'local_ulms_dashboard'), 'url' => $this->get_routing_service()->get_url_for_route('management.analytics')],
                    ['title' => get_string('viewreportslink', 'local_ulms_dashboard'), 'meta' => get_string('adminreportslinkdesc', 'local_ulms_dashboard'), 'url' => $this->get_routing_service()->get_url_for_route('management.academicsreports')],
                    ['title' => get_string('manageacademicstructurelink', 'local_ulms_dashboard'), 'meta' => get_string('adminacademicstructurelinkdesc', 'local_ulms_dashboard'), 'url' => $this->get_routing_service()->get_url_for_route('management.academics')],
                ],
            ],
            'secondarypanels' => [],
        ];
    }

    /**
     * Builds admin audit section data.
     *
     * @return array<string, mixed>
     */
    private function build_admin_audit_section_data(): array {
        return [
            'summarycards' => [],
            'mainpanel' => [
                'title' => get_string('adminauditlogstitle', 'local_ulms_dashboard'),
                'subtitle' => get_string('adminauditlogsdesc', 'local_ulms_dashboard'),
                'style' => 'list',
                'items' => $this->get_recent_provisioning_list_items(10),
                'emptytitle' => get_string('norecentactivity', 'local_ulms_dashboard'),
                'emptydesc' => get_string('norecentactivitydesc', 'local_ulms_dashboard'),
            ],
            'secondarypanels' => [],
        ];
    }

    /**
     * Returns workspace cards for course lists.
     *
     * @param int[] $courseids
     * @return array<int, array<string, mixed>>
     */
    private function get_course_workspace_cards(array $courseids, bool $islecturer): array {
        global $DB;

        if (empty($courseids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_sql(
            "SELECT c.id,
                    c.fullname,
                    COUNT(DISTINCT CASE WHEN cm.visible = 1 THEN cm.id ELSE NULL END) AS visiblemodules,
                    COUNT(DISTINCT CASE WHEN m.name = 'assign' THEN cm.id ELSE NULL END) AS assignments,
                    COUNT(DISTINCT CASE WHEN m.name = 'quiz' THEN cm.id ELSE NULL END) AS quizzes
               FROM {course} c
          LEFT JOIN {course_modules} cm ON cm.course = c.id AND cm.deletioninprogress = 0
          LEFT JOIN {modules} m ON m.id = cm.module
              WHERE c.id {$insql}
           GROUP BY c.id, c.fullname
           ORDER BY c.fullname ASC",
            $params
        );

        $items = [];
        foreach ($records as $record) {
            $items[] = [
                'title' => format_string($record->fullname),
                'meta' => get_string(
                    $islecturer ? 'lecturercoursecardmeta' : 'studentcoursecardmeta',
                    'local_ulms_dashboard',
                    (object)[
                        'materials' => (int)$record->visiblemodules,
                        'assignments' => (int)$record->assignments,
                        'quizzes' => (int)$record->quizzes,
                    ]
                ),
                'url' => new \moodle_url('/course/view.php', ['id' => (int)$record->id]),
                'footer' => $islecturer ? get_string('lecturercourseworkspacehint', 'local_ulms_dashboard') : get_string('studentcourseopencta', 'local_ulms_dashboard'),
            ];
        }

        return $items;
    }

    /**
     * Returns material list items by course.
     *
     * @param int[] $courseids
     * @return array<int, array<string, mixed>>
     */
    private function get_material_list_items(array $courseids): array {
        global $DB;

        if (empty($courseids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_sql(
            "SELECT c.id,
                    c.fullname,
                    COUNT(DISTINCT CASE WHEN cm.visible = 1 THEN cm.id ELSE NULL END) AS visiblemodules
               FROM {course} c
          LEFT JOIN {course_modules} cm ON cm.course = c.id AND cm.deletioninprogress = 0
              WHERE c.id {$insql}
           GROUP BY c.id, c.fullname
           ORDER BY c.fullname ASC",
            $params
        );

        $items = [];
        foreach ($records as $record) {
            $items[] = [
                'title' => format_string($record->fullname),
                'meta' => get_string('portalmaterialscount', 'local_ulms_dashboard', (int)$record->visiblemodules),
                'url' => new \moodle_url('/course/view.php', ['id' => (int)$record->id]),
            ];
        }

        return $items;
    }

    /**
     * Returns assignment list items.
     *
     * @param int[] $courseids
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    private function get_assignment_list_items(array $courseids, int $limit): array {
        global $DB;

        if (empty($courseids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $params['now'] = time();
        $sql = "SELECT a.id,
                       a.name,
                       a.duedate,
                       c.fullname AS coursename,
                       cm.id AS cmid
                  FROM {assign} a
                  JOIN {course} c ON c.id = a.course
                  JOIN {modules} m ON m.name = 'assign'
                  JOIN {course_modules} cm ON cm.instance = a.id AND cm.module = m.id AND cm.course = c.id
                 WHERE a.course {$insql}
              ORDER BY a.duedate ASC, a.name ASC";
        $records = $DB->get_records_sql($sql, $params, 0, $limit);

        $items = [];
        foreach ($records as $record) {
            $items[] = [
                'title' => format_string($record->name),
                'meta' => format_string($record->coursename) . ' - ' . (!empty($record->duedate) ? userdate((int)$record->duedate) : get_string('duedateno')),
                'url' => new \moodle_url('/mod/assign/view.php', ['id' => (int)$record->cmid]),
            ];
        }

        return $items;
    }

    /**
     * Returns quiz list items.
     *
     * @param int[] $courseids
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    private function get_quiz_list_items(array $courseids, int $limit): array {
        global $DB;

        if (empty($courseids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_sql(
            "SELECT q.id,
                    q.name,
                    q.timeclose,
                    c.fullname AS coursename,
                    cm.id AS cmid
               FROM {quiz} q
               JOIN {course} c ON c.id = q.course
               JOIN {modules} m ON m.name = 'quiz'
               JOIN {course_modules} cm ON cm.instance = q.id AND cm.module = m.id AND cm.course = c.id
              WHERE q.course {$insql}
           ORDER BY q.timeclose ASC, q.name ASC",
            $params,
            0,
            $limit
        );

        $items = [];
        foreach ($records as $record) {
            $items[] = [
                'title' => format_string($record->name),
                'meta' => format_string($record->coursename) . ' - ' . (!empty($record->timeclose) ? userdate((int)$record->timeclose) : get_string('studentquiznoclose', 'local_ulms_dashboard')),
                'url' => new \moodle_url('/mod/quiz/view.php', ['id' => (int)$record->cmid]),
            ];
        }

        return $items;
    }

    /**
     * Returns announcement list items.
     *
     * @param int[] $courseids
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    private function get_announcement_list_items(array $courseids, int $limit): array {
        global $DB;

        if (empty($courseids) || !$DB->get_manager()->table_exists(new \xmldb_table('forum_discussions'))) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $params['newstype'] = 'news';
        $params['announcesearch'] = '%announce%';
        $records = $DB->get_records_sql(
            "SELECT d.id,
                    d.name,
                    d.timemodified,
                    c.fullname AS coursename
               FROM {forum_discussions} d
               JOIN {forum} f ON f.id = d.forum
               JOIN {course} c ON c.id = f.course
              WHERE f.course {$insql}
                AND (f.type = :newstype OR " . $DB->sql_like('LOWER(f.name)', ':announcesearch', false) . ")
           ORDER BY d.timemodified DESC",
            $params,
            0,
            $limit
        );

        $items = [];
        foreach ($records as $record) {
            $items[] = [
                'title' => format_string($record->name),
                'meta' => format_string($record->coursename) . ' - ' . userdate((int)$record->timemodified),
                'url' => new \moodle_url('/mod/forum/discuss.php', ['d' => (int)$record->id]),
            ];
        }

        return $items;
    }

    /**
     * Returns progress items by course.
     *
     * @param array<int, array<string, mixed>> $courses
     * @return array<int, array<string, mixed>>
     */
    private function get_progress_list_items(array $courses): array {
        global $USER;

        $items = [];
        foreach ($courses as $course) {
            $coursecontext = get_course((int)$course['id']);
            $progress = \core_completion\progress::get_course_progress_percentage($coursecontext, (int)$USER->id);
            $percent = $progress === null ? 0 : (int)round($progress);
            $items[] = [
                'title' => format_string((string)$course['fullname']),
                'meta' => get_string('portalprogressmeta', 'local_ulms_dashboard', $percent),
                'url' => $course['url'],
            ];
        }

        return $items;
    }

    /**
     * Returns roster count list items by course.
     *
     * @param int[] $courseids
     * @return array<int, array<string, mixed>>
     */
    private function get_course_roster_list_items(array $courseids): array {
        global $DB;

        if (empty($courseids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $params['student'] = 'student';
        $records = $DB->get_records_sql(
            "SELECT c.id,
                    c.fullname,
                    COUNT(DISTINCT ue.userid) AS studentcount
               FROM {course} c
               JOIN {enrol} e ON e.courseid = c.id
               JOIN {user_enrolments} ue ON ue.enrolid = e.id
          LEFT JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = " . CONTEXT_COURSE . "
          LEFT JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = ue.userid
          LEFT JOIN {role} r ON r.id = ra.roleid
              WHERE c.id {$insql}
                AND (r.shortname = :student OR r.shortname IS NULL)
           GROUP BY c.id, c.fullname
           ORDER BY c.fullname ASC",
            $params
        );

        $items = [];
        foreach ($records as $record) {
            $items[] = [
                'title' => format_string($record->fullname),
                'meta' => get_string('lecturerstudentscountmeta', 'local_ulms_dashboard', (int)$record->studentcount),
                'url' => new \moodle_url('/course/view.php', ['id' => (int)$record->id]),
            ];
        }

        return $items;
    }

    /**
     * Maps calendar events to shared list items.
     *
     * @param array<int, array<string, mixed>> $events
     * @return array<int, array<string, mixed>>
     */
    private function map_event_items(array $events): array {
        return array_map(static function(array $event): array {
            return [
                'title' => format_string((string)$event['title']),
                'meta' => format_string((string)$event['subtitle']) . ' - ' . s((string)$event['time']),
                'url' => $event['url'],
            ];
        }, $events);
    }

    /**
     * Returns recent provisioning log items when available.
     *
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    private function get_recent_provisioning_list_items(int $limit): array {
        global $DB;

        if (!class_exists(\local_ulms_dashboard\local\service\user_provisioning_service::class)
            || !$DB->get_manager()->table_exists(new \xmldb_table('local_ulms_user_provisioning_log'))) {
            return [];
        }

        $service = new \local_ulms_dashboard\local\service\user_provisioning_service();
        $activity = $service->get_recent_activity($limit);

        return array_map(static function(array $item): array {
            return [
                'title' => format_string((string)$item['label']),
                'meta' => format_string((string)$item['subtitle']) . ' - ' . s((string)$item['time']),
            ];
        }, $activity);
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

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function get_overdue_scheduled_tasks_count(): int {
        global $DB;
        $now = time();
        // Overdue = scheduled run passed (nextruntime < now) AND never ran yet, OR last ran before the previous scheduled slot.
        $overdue = (int)$DB->count_records_select(
            'task_scheduled',
            'nextruntime > 0 AND nextruntime < :now AND (lastruntime = 0 OR lastruntime < (nextruntime * 2 - :now2))',
            ['now' => $now, 'now2' => $now]
        );
        return $overdue;
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function get_enabled_scheduled_tasks_count(): int {
        global $DB;
        return (int)$DB->count_records('task_scheduled', ['disabled' => 0]);
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function get_total_plugins_count(): int {
        global $DB;
        if ($DB->get_manager()->table_exists(new \xmldb_table('config_plugins'))) {
            return (int)$DB->count_records('config_plugins');
        }
        return (int)$DB->count_records('config');
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function get_disk_usage_percent(): string {
        global $CFG;
        $root = $CFG->dataroot ?? \dirname(__DIR__, 5);
        $total = @\disk_total_space((string)$root);
        $free = @\disk_free_space((string)$root);
        if ($total === false || $free === false || $total <= 0) {
            return 'N/A';
        }
        $used = $total - $free;
        $percent = (int)round(($used / $total) * 100);
        return $percent . '%';
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function get_http_security_headers_rows(): array {
        $headers = [];
        $csp = !empty($_SERVER['HTTP_CONTENT_SECURITY_POLICY'])
            || !empty(ini_get('header.Content-Security-Policy'))
            || function_exists('header_register_callback');
        $headers[] = ['label' => 'Content-Security-Policy', 'meta' => $csp ? 'ENABLED' : 'NOT ENFORCED'];
        $hsts = !empty($_SERVER['HTTP_STRICT_TRANSPORT_SECURITY'])
            || isset($_SERVER['HTTPS']);
        $headers[] = ['label' => 'Strict-Transport-Security', 'meta' => $hsts ? 'ENABLED' : 'NOT ENFORCED'];
        $xcto = !empty($_SERVER['HTTP_X_CONTENT_TYPE_OPTIONS'])
            || ini_get('expose_php') !== false;
        $headers[] = ['label' => 'X-Content-Type-Options', 'meta' => $xcto ? 'ENABLED' : 'NOT ENFORCED'];
        $xfo = !empty($_SERVER['HTTP_X_FRAME_OPTIONS']);
        $headers[] = ['label' => 'X-Frame-Options', 'meta' => $xfo ? 'ENABLED' : 'NOT ENFORCED'];
        return $headers;
    }
}
