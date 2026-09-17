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

global $CFG;
require_once($CFG->dirroot . '/lib/enrollib.php');
require_once __DIR__ . '/../../../../../lib/enrollib.php';

/**
 * Builds focused portal overview pages from existing Moodle data.
 * @noinspection PhpUndefinedFunctionInspection
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
            'attendance' => $this->build_student_attendance_section_data($snapshot),
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
            'schedule' => $this->build_lecturer_schedule_section_data($snapshot),
            'live' => $this->build_lecturer_live_section_data($snapshot),
            'attendance' => $this->build_lecturer_attendance_register_data($snapshot),
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
            'schedule' => $this->build_admin_schedule_section_data(),
            'attendanceaudit' => $this->build_admin_attendanceaudit_section_data(),
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
        global $USER;

        $service = schedule_service::instance();
        $weekstartparam = optional_param('weekstart', 0, PARAM_INT);
        if ($weekstartparam > 0) {
            $monday_ts = $weekstartparam;
        } else {
            $monday_ts = strtotime('monday this week 00:00:00');
        }
        $monday = date('Y-m-d', $monday_ts);

        $cells = $service->get_timetable_cells_for_user((int)$USER->id, 'student', $monday_ts);

        $sessioncount = count($cells);
        $kpis = $service->get_dashboard_kpis();

        $html = '';

        $prevweek = $monday_ts - (7 * 86400);
        $nextweek = $monday_ts + (7 * 86400);
        $currenturl = new \moodle_url($this->get_routing_service()->get_url_for_route('student.timetable'));

        $html .= '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:16px;">';
        $html .= '<div style="font-weight:600;color:#0f4c81;">Week of ' . s(date('M j, Y', $monday_ts)) . '</div>';
        $html .= '<div style="display:flex;gap:8px;">';
        $prevurl = new \moodle_url($currenturl, ['weekstart' => $prevweek]);
        $nexturl = new \moodle_url($currenturl, ['weekstart' => $nextweek]);
        $html .= '<a href="' . s($prevurl->out(false)) . '" class="ulms-btn" style="min-width:44px;min-height:44px;display:inline-flex;align-items:center;justify-content:center;">← Prev Week</a>';
        $html .= '<a href="' . s($nexturl->out(false)) . '" class="ulms-btn" style="min-width:44px;min-height:44px;display:inline-flex;align-items:center;justify-content:center;">Next Week →</a>';
        $html .= '</div></div>';

        $html .= '<h3 style="margin:16px 0 8px;font-size:1rem;font-weight:700;color:#0f4c81;">Weekly Timetable</h3>';
        $html .= '<table class="ulms-timetable-grid" data-ulms-timetable="true">';
        $html .= '<thead><tr><th data-label="Time">Time</th><th data-label="Mon">Mon</th><th data-label="Tue">Tue</th><th data-label="Wed">Wed</th><th data-label="Thu">Thu</th><th data-label="Fri">Fri</th></tr></thead>';
        $html .= '<tbody>';

        $_daysmap = [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5];

        for ($hr = 8; $hr <= 17; $hr++) {
            $slotstart = $hr * 60;
            $time_label = sprintf('%02d:00–%02d:00', $hr, $hr + 1);
            $html .= '<tr>';
            $html .= '<td data-label="Time" style="font-weight:600;color:#0f4c81;">' . s($time_label) . '</td>';
            for ($wd = 1; $wd <= 5; $wd++) {
                $html .= '<td data-label="' . s(['Mon','Tue','Wed','Thu','Fri'][$wd - 1]) . '">';
                foreach ($cells as $c) {
                    $cwd = (int)($c['weekday'] ?? 0);
                    $cstart = (int)($c['start_minutes'] ?? 0);
                    $cend = $cstart + (int)($c['duration_minutes'] ?? 0);
                    if ($cwd === $wd && $cstart >= $slotstart && $cstart < ($slotstart + 60)) {
                        $starth = intdiv($cstart, 60);
                        $startm = $cstart % 60;
                        $endh = intdiv($cend, 60);
                        $endm = $cend % 60;
                        $timerange = sprintf('%02d:%02d–%02d:%02d', $starth, $startm, $endh, $endm);
                        $locmode = (string)($c['location_mode'] ?? 'physical');
                        $loclabel = (string)($c['location_label'] ?? '');
                        $delivery = (string)($c['delivery_mode'] ?? 'lecture');
                        $statusclass = ($locmode === 'online') ? 'ulms-timetable-slot--online' : '';
                        $html .= '<div class="ulms-timetable-slot ' . $statusclass . '" style="background:#eaf1fa;border-left:3px solid #0f4c81;border-radius:4px;padding:8px;margin:2px 0;">';
                        $html .= '<div style="font-weight:600;font-size:13px;color:#0f1a25;">' . format_string($c['title']) . '</div>';
                        $html .= '<div style="margin-top:4px;"><span class="ulms-status-badge" style="background:#dbe8fb;color:#0969da;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;">' . s($delivery) . '</span></div>';
                        $html .= '<div style="margin-top:4px;font-size:12px;color:#5c6f82;">' . s($timerange) . ' · ' . s($loclabel ?: $locmode) . '</div>';
                        if ($locmode === 'online') {
                            $join = $service->resolve_join_url((int)$c['id'], 'student');
                            if (!empty($join['url'])) {
                                $html .= '<div style="margin-top:6px;"><a href="' . s($join['url']) . '" target="' . s($join['target']) . '" rel="' . s($join['rel']) . '" class="ulms-btn ulms-btn--primary" style="min-width:44px;min-height:44px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;padding:6px 10px;">Join Class Now</a></div>';
                            }
                        }
                        $html .= '</div>';
                    }
                }
                $html .= '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        $html .= '<input type="hidden" name="weekstart" value="' . s($monday) . '">';

        return [
            'summarycards' => [
                ['label' => 'Sessions This Week', 'value' => (string)$sessioncount, 'description' => 'Timetable sessions for selected week'],
                ['label' => 'Platform Attendance', 'value' => $kpis['avg_attendance_percent'] . '%', 'description' => 'Average attendance across sessions'],
                ['label' => get_string('coursecountsummary', 'local_ulms_dashboard'), 'value' => (string)$snapshot['coursecount'], 'description' => get_string('studentcoursescountdesc', 'local_ulms_dashboard')],
            ],
            'mainpanel' => [
                'title' => get_string('studenttimetabletitle', 'local_ulms_dashboard'),
                'subtitle' => get_string('studenttimetabledesc', 'local_ulms_dashboard'),
                'style' => 'html',
                'html' => $html,
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

    private function build_student_attendance_section_data(array $snapshot): array {
        global $USER;

        $service = schedule_service::instance();
        $history = $service->get_student_attendance_history((int)$USER->id);

        $coursecount = (int)$snapshot['coursecount'];
        $presentcount = 0;
        $latecount = 0;
        $totalmarked = 0;
        foreach ($history as $h) {
            $st = (string)($h->status ?? '');
            if ($st === 'present') { $presentcount++; $totalmarked++; }
            elseif ($st === 'late') { $latecount++; $totalmarked++; }
            elseif ($st === 'absent' || $st === 'excused') { $totalmarked++; }
        }
        $overallpct = $totalmarked > 0 ? number_format(($presentcount / max(1, $totalmarked)) * 100, 1) : '0.0';

        $coursegrouped = [];
        global $DB;
        foreach ($history as $h) {
            $sid = (int)($h->sessionid ?? 0);
            $session = $DB->get_record('local_ulms_dashboard_session', ['id' => $sid], 'id, title, moodlecourseid', IGNORE_MISSING);
            if (!$session) continue;
            $cid = (int)$session->moodlecourseid;
            if (!isset($coursegrouped[$cid])) {
                $course = $DB->get_record('course', ['id' => $cid], 'id, fullname', IGNORE_MISSING);
                $coursegrouped[$cid] = [
                    'courseid' => $cid,
                    'coursename' => $course ? format_string($course->fullname) : 'Course #' . $cid,
                    'total' => 0, 'present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0,
                    'sessions' => [],
                ];
            }
            $st = (string)($h->status ?? '');
            $coursegrouped[$cid]['total']++;
            if (isset($coursegrouped[$cid][$st])) { $coursegrouped[$cid][$st]++; }
            $coursegrouped[$cid]['sessions'][] = [
                'date' => (int)($h->session_occurrence_date ?? 0),
                'status' => $st,
                'title' => format_string($session->title),
            ];
        }

        $cardsitems = [];
        foreach ($coursegrouped as $cg) {
            $c_total = (int)$cg['total'];
            $c_present = (int)$cg['present'];
            $c_pct = $c_total > 0 ? number_format(($c_present / $c_total) * 100, 0) : 0;
            $barcolor = $c_pct >= 80 ? '#1a7f37' : ($c_pct >= 60 ? '#c76a00' : '#c8352a');
            $meta = '<div style="margin-top:4px;">';
            $meta .= '<div style="display:flex;gap:6px;flex-wrap:wrap;">';
            $meta .= '<span class="ulms-status-badge ulms-status-badge--present">Present: ' . $cg['present'] . '</span>';
            if ($cg['late'] > 0) $meta .= '<span class="ulms-status-badge ulms-status-badge--late">Late: ' . $cg['late'] . '</span>';
            if ($cg['absent'] > 0) $meta .= '<span class="ulms-status-badge ulms-status-badge--absent">Absent: ' . $cg['absent'] . '</span>';
            $meta .= '</div>';
            $meta .= '<div style="margin-top:8px;background:#e8eef6;border-radius:999px;height:10px;overflow:hidden;"><div style="height:100%;width:' . s($c_pct) . '%;background:' . $barcolor . ';"></div></div>';
            $meta .= '<div style="margin-top:4px;font-size:12px;color:#5c6f82;">' . s($c_pct) . '% Present · ' . $c_total . ' sessions</div>';
            $meta .= '</div>';
            $cardsitems[] = [
                'title' => $cg['coursename'],
                'meta' => $meta,
            ];
            foreach (array_slice($cg['sessions'], 0, 5) as $s) {
                $datestr = $s['date'] > 0 ? userdate($s['date'], get_string('strftimedaydate')) : 'N/A';
                $badgeclass = 'ulms-status-badge--' . $s['status'];
                $cardsitems[] = [
                    'title' => '  · ' . $s['title'],
                    'meta' => $datestr . ' <span class="ulms-status-badge ' . $badgeclass . '" style="margin-left:8px;">' . s(ucfirst($s['status'])) . '</span>',
                ];
            }
        }

        return [
            'summarycards' => [
                ['label' => 'Enrolled Courses', 'value' => (string)$coursecount, 'description' => 'Courses in current programme'],
                ['label' => 'Overall Attendance', 'value' => s($overallpct) . '%', 'description' => 'Present / total marked sessions'],
                ['label' => 'Present Count', 'value' => (string)$presentcount, 'description' => 'Sessions marked present'],
                ['label' => 'Late Count', 'value' => (string)$latecount, 'description' => 'Sessions marked late'],
            ],
            'mainpanel' => [
                'title' => 'My Attendance History',
                'subtitle' => 'Attendance records across all enrolled courses',
                'style' => 'cards',
                'items' => $cardsitems,
                'emptytitle' => 'No Attendance Records',
                'emptydesc' => 'Your attendance will appear here once your lecturer starts marking sessions.',
            ],
            'secondarypanels' => [],
        ];
    }

    private function build_lecturer_schedule_section_data(array $_snapshot): array {
        global $DB, $USER;

        $service = schedule_service::instance();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            confirm_sesskey();
            $action = optional_param('action', '', PARAM_ALPHA);
            if ($action === 'save_session') {
                $payload = [];
                $fkfields = ['facultyid', 'departmentid', 'programmeid', 'sessionid', 'semesterid', 'levelid', 'moodlecourseid', 'lecturer_userid'];
                foreach ($fkfields as $f) {
                    $payload[$f] = (int)optional_param($f, 0, PARAM_INT);
                }
                $payload['title'] = (string)optional_param('title', '', PARAM_TEXT);
                $payload['delivery_mode'] = (string)optional_param('delivery_mode', 'lecture', PARAM_ALPHAEXT);
                $payload['weekday'] = (int)optional_param('weekday', 1, PARAM_INT);
                $payload['start_minutes'] = (int)optional_param('start_minutes', 480, PARAM_INT);
                $payload['duration_minutes'] = (int)optional_param('duration_minutes', 60, PARAM_INT);
                $tsd = (string)optional_param('term_start_date', '', PARAM_TEXT);
                $ted = (string)optional_param('term_end_date', '', PARAM_TEXT);
                $payload['term_start_date'] = $tsd !== '' ? strtotime($tsd . ' 00:00:00') : 0;
                $payload['term_end_date'] = $ted !== '' ? strtotime($ted . ' 23:59:59') : 0;
                $payload['location_mode'] = (string)optional_param('location_mode', 'physical', PARAM_ALPHA);
                $payload['location_label'] = (string)optional_param('location_label', '', PARAM_TEXT);
                $payload['provider_key'] = (string)optional_param('provider_key', 'bigbluebutton', PARAM_ALPHAEXT);
                $rec = (string)optional_param('recurrence', 'weekly', PARAM_ALPHA);
                $payload['recurrence'] = $rec === 'once' ? 'once_off' : $rec;
                $payload['status'] = (string)optional_param('status', 'scheduled', PARAM_ALPHA);
                $payload['notes_public'] = (string)optional_param('notes_public', '', PARAM_RAW);
                $payload['notes_private'] = (string)optional_param('notes_private', '', PARAM_RAW);
                $result = $service->save_session($payload, (int)$USER->id);
                if (!empty($result['success'])) {
                    \core\notification::add('Session scheduled successfully.', \core\output\notification::NOTIFY_SUCCESS);
                } else {
                    $errmsg = 'Failed to schedule session.';
                    if (!empty($result['errors'])) {
                        $errmsg .= ' Fields: ' . implode(', ', array_keys($result['errors']));
                    }
                    \core\notification::add($errmsg, \core\output\notification::NOTIFY_ERROR);
                }
                redirect(new \moodle_url($this->get_routing_service()->get_url_for_route('lecturer.schedule')));
            }
        }

        $weekstartparam = optional_param('weekstart', 0, PARAM_INT);
        if ($weekstartparam > 0) {
            $monday_ts = $weekstartparam;
        } else {
            $monday_ts = strtotime('monday this week 00:00:00');
        }
        $monday = date('Y-m-d', $monday_ts);

        $cells = $service->get_timetable_cells_for_user((int)$USER->id, 'editingteacher', $monday_ts);
        $sessioncount = count($cells);
        $kpis = $service->get_dashboard_kpis();
        $conflicts = $service->find_conflicts(0, $monday_ts);
        $conflictcount = count($conflicts);

        $html = '';

        $faculties = $DB->get_records_menu('local_ulms_faculties', null, 'name ASC', 'id, name');
        $departments = $DB->get_records_menu('local_ulms_departments', null, 'name ASC', 'id, name');
        $programmes = $DB->get_records_menu('local_ulms_programmes', null, 'name ASC', 'id, name');
        $sessions = $DB->get_records_menu('local_ulms_sessions', null, 'name ASC', 'id, name');
        $semesters = $DB->get_records_menu('local_ulms_semesters', null, 'name ASC', 'id, name');
        $levels = $DB->get_records_menu('local_ulms_levels', null, 'name ASC', 'id, name');
        $mycourses = [];
        foreach (enrol_get_all_users_courses((int)$USER->id, false, ['id', 'fullname']) as $c) {
            $mycourses[(int)$c->id] = format_string($c->fullname);
        }

        $html .= '<h3 style="margin:0 0 12px;font-size:1rem;font-weight:700;color:#0f4c81;">Schedule New Session</h3>';
        $html .= '<form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;padding:16px;border:1px solid #e1e7ee;border-radius:12px;background:#f8fbff;margin-bottom:24px;">';
        $html .= '<input type="hidden" name="sesskey" value="' . s(sesskey()) . '">';
        $html .= '<input type="hidden" name="action" value="save_session">';
        $html .= '<input type="hidden" name="lecturer_userid" value="' . s((int)$USER->id) . '">';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Faculty</label><select name="facultyid" class="form-control custom-select" required>';
        $html .= '<option value="">-- Select Faculty --</option>';
        foreach ($faculties as $fid => $fname) {
            $html .= '<option value="' . s($fid) . '">' . format_string($fname) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Department</label><select name="departmentid" class="form-control custom-select" required>';
        $html .= '<option value="">-- Select Department --</option>';
        foreach ($departments as $did => $dname) {
            $html .= '<option value="' . s($did) . '">' . format_string($dname) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Programme</label><select name="programmeid" class="form-control custom-select" required>';
        $html .= '<option value="">-- Select Programme --</option>';
        foreach ($programmes as $pid => $pname) {
            $html .= '<option value="' . s($pid) . '">' . format_string($pname) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Academic Session</label><select name="sessionid" class="form-control custom-select" required>';
        $html .= '<option value="">-- Select Session --</option>';
        foreach ($sessions as $sid => $sname) {
            $html .= '<option value="' . s($sid) . '">' . format_string($sname) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Semester</label><select name="semesterid" class="form-control custom-select" required>';
        $html .= '<option value="">-- Select Semester --</option>';
        foreach ($semesters as $smid => $smname) {
            $html .= '<option value="' . s($smid) . '">' . format_string($smname) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Level</label><select name="levelid" class="form-control custom-select" required>';
        $html .= '<option value="">-- Select Level --</option>';
        foreach ($levels as $lid => $lname) {
            $html .= '<option value="' . s($lid) . '">' . format_string($lname) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field" style="grid-column:span 1;"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Course</label><select name="moodlecourseid" class="form-control custom-select" required>';
        $html .= '<option value="">-- Select Course --</option>';
        foreach ($mycourses as $cid => $cname) {
            $html .= '<option value="' . s($cid) . '">' . $cname . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field" style="grid-column:span 1;"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Session Title</label><input type="text" name="title" class="form-control" required placeholder="e.g. Introduction to Programming"></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Delivery Mode</label><select name="delivery_mode" class="form-control custom-select">';
        foreach ($service::DELIVERY_MODES as $dm) {
            $html .= '<option value="' . s($dm) . '">' . s(ucwords(str_replace('_', ' ', $dm))) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Weekday</label><select name="weekday" class="form-control custom-select">';
        $wdnames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday'];
        foreach ($wdnames as $wdi => $wdn) {
            $html .= '<option value="' . s($wdi) . '">' . s($wdn) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Start Time</label><select name="start_minutes" class="form-control custom-select">';
        for ($tm = 480; $tm <= 1080; $tm += 30) {
            $h = intdiv($tm, 60);
            $m = $tm % 60;
            $html .= '<option value="' . s($tm) . '">' . s(sprintf('%02d:%02d', $h, $m)) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Duration (minutes)</label><select name="duration_minutes" class="form-control custom-select">';
        foreach ([30, 45, 60, 90, 120] as $d) {
            $html .= '<option value="' . s($d) . '">' . s($d) . ' min</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Term Start Date</label><input type="date" name="term_start_date" class="form-control" value="' . s(date('Y-m-d', $monday_ts)) . '"></div>';
        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Term End Date</label><input type="date" name="term_end_date" class="form-control" value="' . s(date('Y-m-d', $monday_ts + (12 * 7 * 86400))) . '"></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Location Mode</label><select name="location_mode" class="form-control custom-select">';
        foreach ($service::LOCATION_MODES as $lm) {
            $html .= '<option value="' . s($lm) . '">' . s(ucfirst($lm)) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Location / Room</label><input type="text" name="location_label" class="form-control" placeholder="Room 201 / Zoom link ID"></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Online Provider</label><select name="provider_key" class="form-control custom-select">';
        foreach ($service::PROVIDERS as $pk) {
            $lbl = str_replace('_', ' ', $pk);
            $html .= '<option value="' . s($pk) . '">' . s(ucwords($lbl)) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Recurrence</label><select name="recurrence" class="form-control custom-select">';
        $html .= '<option value="weekly">Weekly</option><option value="once">Once-off</option><option value="fortnightly">Fortnightly</option>';
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Status</label><select name="status" class="form-control custom-select">';
        foreach ($service::STATUSES as $st) {
            $html .= '<option value="' . s($st) . '">' . s(ucfirst($st)) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field" style="grid-column:span 2;"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Public Notes (visible to students)</label><textarea name="notes_public" rows="2" class="form-control" placeholder="Pre-reading, preparation notes..."></textarea></div>';
        $html .= '<div class="ulms-form-field" style="grid-column:span 2;"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Private Notes (lecturer only)</label><textarea name="notes_private" rows="2" class="form-control" placeholder="Internal reminders, seating plan..."></textarea></div>';

        $html .= '<div style="grid-column:1 / -1;display:flex;justify-content:flex-end;"><button type="submit" class="ulms-btn ulms-btn--primary" style="min-width:44px;min-height:44px;padding:10px 20px;font-weight:600;">Schedule Session</button></div>';
        $html .= '</form>';

        $prevweek = $monday_ts - (7 * 86400);
        $nextweek = $monday_ts + (7 * 86400);
        $currenturl = new \moodle_url($this->get_routing_service()->get_url_for_route('lecturer.schedule'));

        $html .= '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:16px;">';
        $html .= '<h3 style="margin:0;font-size:1rem;font-weight:700;color:#0f4c81;">Weekly Timetable — Week of ' . s(date('M j, Y', $monday_ts)) . '</h3>';
        $html .= '<div style="display:flex;gap:8px;">';
        $prevurl = new \moodle_url($currenturl, ['weekstart' => $prevweek]);
        $nexturl = new \moodle_url($currenturl, ['weekstart' => $nextweek]);
        $html .= '<a href="' . s($prevurl->out(false)) . '" class="ulms-btn" style="min-width:44px;min-height:44px;display:inline-flex;align-items:center;justify-content:center;">← Prev</a>';
        $html .= '<a href="' . s($nexturl->out(false)) . '" class="ulms-btn" style="min-width:44px;min-height:44px;display:inline-flex;align-items:center;justify-content:center;">Next →</a>';
        $html .= '</div></div>';

        $html .= '<table class="ulms-timetable-grid" data-ulms-timetable="true">';
        $html .= '<thead><tr><th data-label="Time">Time</th><th data-label="Mon">Mon</th><th data-label="Tue">Tue</th><th data-label="Wed">Wed</th><th data-label="Thu">Thu</th><th data-label="Fri">Fri</th></tr></thead><tbody>';

        for ($hr = 8; $hr <= 17; $hr++) {
            $slotstart = $hr * 60;
            $time_label = sprintf('%02d:00–%02d:00', $hr, $hr + 1);
            $html .= '<tr>';
            $html .= '<td data-label="Time" style="font-weight:600;color:#0f4c81;">' . s($time_label) . '</td>';
            for ($wd = 1; $wd <= 5; $wd++) {
                $html .= '<td data-label="' . s(['Mon','Tue','Wed','Thu','Fri'][$wd - 1]) . '">';
                foreach ($cells as $c) {
                    $cwd = (int)($c['weekday'] ?? 0);
                    $cstart = (int)($c['start_minutes'] ?? 0);
                    $cend = $cstart + (int)($c['duration_minutes'] ?? 0);
                    if ($cwd === $wd && $cstart >= $slotstart && $cstart < ($slotstart + 60)) {
                        $starth = intdiv($cstart, 60);
                        $startm = $cstart % 60;
                        $endh = intdiv($cend, 60);
                        $endm = $cend % 60;
                        $timerange = sprintf('%02d:%02d–%02d:%02d', $starth, $startm, $endh, $endm);
                        $locmode = (string)($c['location_mode'] ?? 'physical');
                        $loclabel = (string)($c['location_label'] ?? '');
                        $delivery = (string)($c['delivery_mode'] ?? 'lecture');
                        $statusclass = ($locmode === 'online') ? 'ulms-timetable-slot--online' : '';
                        $html .= '<div class="ulms-timetable-slot ' . $statusclass . '" style="background:#eaf1fa;border-left:3px solid #0f4c81;border-radius:4px;padding:8px;margin:2px 0;min-height:44px;">';
                        $html .= '<div style="font-weight:600;font-size:13px;color:#0f1a25;">' . format_string($c['title']) . '</div>';
                        $html .= '<div style="margin-top:4px;"><span class="ulms-status-badge" style="background:#dbe8fb;color:#0969da;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;">' . s($delivery) . '</span></div>';
                        $html .= '<div style="margin-top:4px;font-size:12px;color:#5c6f82;">' . s($timerange) . ' · ' . s($loclabel ?: $locmode) . '</div>';
                        $html .= '<div style="margin-top:6px;display:flex;gap:4px;flex-wrap:wrap;">';
                        $editurl = new \moodle_url('/course/view.php', ['id' => (int)($c['moodlecourseid'] ?? 1)]);
                        $html .= '<a href="' . s($editurl->out(false)) . '" class="ulms-btn" style="min-width:44px;min-height:44px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;padding:6px 10px;">Edit</a>';
                        if ($locmode === 'online') {
                            $join = $service->resolve_join_url((int)$c['id'], 'lecturer');
                            if (!empty($join['url'])) {
                                $html .= '<a href="' . s($join['url']) . '" target="' . s($join['target']) . '" rel="' . s($join['rel']) . '" class="ulms-btn ulms-btn--primary" style="min-width:44px;min-height:44px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;padding:6px 10px;">Join Class Now</a>';
                            }
                        }
                        $html .= '</div></div>';
                    }
                }
                $html .= '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        $html .= '<input type="hidden" name="weekstart" value="' . s($monday) . '">';

        $listitems = [];
        usort($cells, function ($a, $b) {
            $wa = (int)($a['weekday'] ?? 0) * 1440 + (int)($a['start_minutes'] ?? 0);
            $wb = (int)($b['weekday'] ?? 0) * 1440 + (int)($b['start_minutes'] ?? 0);
            return $wa - $wb;
        });
        foreach ($cells as $c) {
            $wdnames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];
            $wd = (int)($c['weekday'] ?? 0);
            $sm = (int)($c['start_minutes'] ?? 0);
            $em = $sm + (int)($c['duration_minutes'] ?? 0);
            $tr = sprintf('%s %02d:%02d–%02d:%02d', $wdnames[$wd] ?? '?', intdiv($sm, 60), $sm % 60, intdiv($em, 60), $em % 60);
            $st = (string)($c['status'] ?? 'scheduled');
            $listitems[] = [
                'title' => format_string($c['title']),
                'meta' => s($tr) . ' · ' . s($c['location_mode'] ?? 'physical') . ' ' . s($c['location_label'] ?? '') . ' · <span class="ulms-status-badge" style="margin-left:4px;">' . s(ucfirst($st)) . '</span>',
            ];
        }

        return [
            'summarycards' => [
                ['label' => 'Sessions This Week', 'value' => (string)$sessioncount, 'description' => 'Timetable sessions for selected week'],
                ['label' => 'Avg Attendance %', 'value' => $kpis['avg_attendance_percent'] . '%', 'description' => 'Platform-wide average attendance'],
                ['label' => 'Conflict Count', 'value' => (string)$conflictcount, 'description' => 'Scheduling conflicts detected'],
            ],
            'mainpanel' => [
                'title' => 'Schedule & Timetable Manager',
                'subtitle' => 'Schedule new class sessions and manage your weekly teaching timetable',
                'style' => 'html',
                'html' => $html,
            ],
            'secondarypanels' => [
                [
                    'title' => 'Upcoming Sessions',
                    'subtitle' => 'Sessions ordered by day and time',
                    'style' => 'list',
                    'soft' => true,
                    'items' => $listitems,
                    'emptytitle' => 'No sessions yet',
                    'emptydesc' => 'Use the form above to schedule your first class session.',
                ],
            ],
        ];
    }

    private function build_lecturer_live_section_data(array $_snapshot): array {
        global $USER;

        $service = schedule_service::instance();
        $today = getdate();
        $wdmap = [0 => 7, 1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6];
        $today_wd = $wdmap[(int)($today['wday'] ?? 0)];
        $now_minutes = ((int)($today['hours'] ?? 0)) * 60 + (int)($today['minutes'] ?? 0);

        $monday_ts = strtotime('monday this week 00:00:00');
        $all = $service->get_timetable_cells_for_user((int)$USER->id, 'editingteacher', $monday_ts);

        $today_sessions = [];
        foreach ($all as $c) {
            if ((int)($c['weekday'] ?? 0) === $today_wd) {
                $today_sessions[] = $c;
            }
        }
        $scheduled_count = count($today_sessions);

        $live_now = 0;
        $completed_today = 0;
        $cardsitems = [];

        foreach ($today_sessions as $c) {
            $sm = (int)($c['start_minutes'] ?? 0);
            $em = $sm + (int)($c['duration_minutes'] ?? 0);
            $is_live = ($now_minutes >= $sm && $now_minutes < $em);
            $is_done = ($now_minutes >= $em);
            if ($is_live) $live_now++;
            if ($is_done) $completed_today++;

            $starth = intdiv($sm, 60);
            $startm = $sm % 60;
            $endh = intdiv($em, 60);
            $endm = $em % 60;
            $timerange = sprintf('%02d:%02d–%02d:%02d', $starth, $startm, $endh, $endm);
            $locmode = (string)($c['location_mode'] ?? 'physical');
            $loclabel = (string)($c['location_label'] ?? '');
            $delivery = (string)($c['delivery_mode'] ?? 'lecture');

            $meta = 'Time: ' . s($timerange) . ' · ' . s($loclabel ?: $locmode) . ' · Delivery: ' . s($delivery);
            if ($is_live) {
                $meta .= ' <span class="ulms-status-badge ulms-status-badge--live" style="margin-left:8px;">LIVE NOW</span>';
            }

            $join = $service->resolve_join_url((int)$c['id'], 'lecturer');
            $footer = '';
            if ($locmode === 'online' && !empty($join['url'])) {
                $footer = '<a href="' . s($join['url']) . '" target="' . s($join['target']) . '" rel="' . s($join['rel']) . '" class="ulms-btn ulms-btn--primary ulms-btn--full" style="display:inline-flex;align-items:center;justify-content:center;min-width:44px;min-height:44px;">Join Class Now</a>';
            }

            $cardsitems[] = [
                'title' => format_string($c['title']),
                'meta' => $meta,
                'footer' => $footer,
            ];
        }

        return [
            'summarycards' => [
                ['label' => 'Scheduled Today', 'value' => (string)$scheduled_count, 'description' => 'Class sessions scheduled for today'],
                ['label' => 'Live Now', 'value' => (string)$live_now, 'description' => 'Sessions currently in progress'],
                ['label' => 'Completed Today', 'value' => (string)$completed_today, 'description' => 'Sessions already finished today'],
            ],
            'mainpanel' => [
                'title' => 'Today — Live Sessions',
                'subtitle' => 'Live class sessions occurring today with one-click join access',
                'style' => 'cards',
                'items' => $cardsitems,
                'emptytitle' => 'No Sessions Today',
                'emptydesc' => 'There are no class sessions on the timetable for today.',
            ],
            'secondarypanels' => [],
        ];
    }

    /** @noinspection PhpUndefinedFunctionInspection */
    private function build_lecturer_attendance_register_data(array $_snapshot): array {
        global $DB, $USER;

        $service = schedule_service::instance();

        $selected_sessionid = optional_param('sessionid', 0, PARAM_INT);
        $selected_occurrence_raw = optional_param('occurrence_date', '', PARAM_TEXT);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            confirm_sesskey();
            $action = optional_param('action', '', PARAM_ALPHA);

            if ($action === 'mark') {
                $sid = (int)optional_param('sessionid', 0, PARAM_INT);
                $od_raw = (string)optional_param('occurrence_date', '', PARAM_TEXT);
                $od = $od_raw !== '' ? (int)strtotime($od_raw . ' 00:00:00') : (int)strtotime('today 00:00:00');
                $uid = (int)optional_param('userid', 0, PARAM_INT);
                $st = (string)optional_param('status', 'present', PARAM_ALPHA);
                $r = $service->mark_attendance($sid, $od, $uid, $st, (int)$USER->id);
                if (!empty($r['success'])) {
                    \core\notification::add('Attendance marked.', \core\output\notification::NOTIFY_SUCCESS);
                } else {
                    \core\notification::add('Failed to mark attendance: ' . s($r['message'] ?? 'error'), \core\output\notification::NOTIFY_ERROR);
                }
                $redir = new \moodle_url($this->get_routing_service()->get_url_for_route('lecturer.attendance'), ['sessionid' => $sid, 'occurrence_date' => $od_raw !== '' ? date('Y-m-d', $od) : '']);
                redirect($redir);
            }

            if ($action === 'bulk_present') {
                $sid = (int)optional_param('sessionid', 0, PARAM_INT);
                $od_raw = (string)optional_param('occurrence_date', '', PARAM_TEXT);
                $od = $od_raw !== '' ? (int)strtotime($od_raw . ' 00:00:00') : (int)strtotime('today 00:00:00');
                $r = $service->bulk_mark_all_present($sid, $od, (int)$USER->id);
                \core\notification::add(
                    'Bulk present complete: ' . (int)($r['newly_marked'] ?? 0) . ' newly marked, ' . (int)($r['skipped_existing'] ?? 0) . ' skipped out of ' . (int)($r['total_count'] ?? 0) . ' total.',
                    \core\output\notification::NOTIFY_SUCCESS
                );
                $redir = new \moodle_url($this->get_routing_service()->get_url_for_route('lecturer.attendance'), ['sessionid' => $sid, 'occurrence_date' => $od_raw !== '' ? date('Y-m-d', $od) : '']);
                redirect($redir);
            }

            if ($action === 'export_csv') {
                $sid = (int)optional_param('sessionid', 0, PARAM_INT);
                $od_raw = (string)optional_param('occurrence_date', '', PARAM_TEXT);
                $od = $od_raw !== '' ? (int)strtotime($od_raw . ' 00:00:00') : (int)strtotime('today 00:00:00');
                local_ulms_dashboard_emit_security_headers();
                $service->export_attendance_csv($sid, $od);
                exit;
            }
        }

        $mycourses = [];
        foreach (enrol_get_all_users_courses((int)$USER->id, false, ['id', 'fullname']) as $c) {
            $mycourses[(int)$c->id] = format_string($c->fullname);
        }
        $sessionsmenu = [0 => '-- Select a Session --'];
        if (count($mycourses) > 0) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($mycourses), SQL_PARAMS_NAMED, 'mc');
            $sessrows = $DB->get_records_select('local_ulms_dashboard_session', "status <> 'cancelled' AND moodlecourseid " . $insql, $inparams, 'title ASC', 'id, title, moodlecourseid');
            foreach ($sessrows as $sr) {
                $cname = $mycourses[(int)$sr->moodlecourseid] ?? ('Course #' . $sr->moodlecourseid);
                $sessionsmenu[(int)$sr->id] = $cname . ' — ' . format_string($sr->title);
            }
        }

        $summarycards = [
            ['label' => 'Allocated Courses', 'value' => (string)count($mycourses), 'description' => 'Courses you are teaching'],
            ['label' => 'Available Sessions', 'value' => (string)(count($sessionsmenu) - 1), 'description' => 'Scheduled class sessions'],
        ];

        $html = '';
        $html .= '<form method="get" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;padding:16px;border:1px solid #e1e7ee;border-radius:12px;background:#f8fbff;align-items:flex-end;">';
        $html .= '<input type="hidden" name="sesskey" value="' . s(sesskey()) . '">';
        $html .= '<div class="ulms-form-field" style="flex:1 1 320px;min-width:220px;"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Select Session</label>';
        $html .= '<select name="sessionid" class="form-control custom-select" onchange="this.form.submit()">';
        foreach ($sessionsmenu as $sidopt => $slbl) {
            $sel = ((int)$sidopt === (int)$selected_sessionid) ? ' selected' : '';
            $html .= '<option value="' . s($sidopt) . '"' . $sel . '>' . s($slbl) . '</option>';
        }
        $html .= '</select></div>';
        $html .= '<div class="ulms-form-field" style="flex:0 0 220px;min-width:180px;"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Occurrence Date</label>';
        $od_default = $selected_occurrence_raw !== '' ? s($selected_occurrence_raw) : s(date('Y-m-d'));
        $html .= '<input type="date" name="occurrence_date" class="form-control" value="' . $od_default . '"></div>';
        $html .= '<div><button type="submit" name="action" value="register_open" class="ulms-btn ulms-btn--primary" style="min-width:44px;min-height:44px;">Show Register</button></div>';
        $html .= '</form>';

        if ($selected_sessionid > 0) {
            $occurrence_ts = $selected_occurrence_raw !== '' ? (int)strtotime($selected_occurrence_raw . ' 00:00:00') : (int)strtotime('today 00:00:00');
            $summary = $service->get_session_attendance_summary($selected_sessionid, $occurrence_ts);

            $summarycards = [
                ['label' => 'Register Summary', 'value' => s($summary['marked']) . '/' . s($summary['total']) . ' Marked · ' . s($summary['percent_present']) . '% Present', 'description' => 'Present: ' . s($summary['present']) . ' · Late: ' . s($summary['late']) . ' · Absent: ' . s($summary['absent']) . ' · Excused: ' . s($summary['excused'])],
            ];

            $sessionrec = $DB->get_record('local_ulms_dashboard_session', ['id' => $selected_sessionid], 'id, moodlecourseid');
            $enrolled_students = [];
            if ($sessionrec) {
                try {
                    $ctx = \context_course::instance((int)$sessionrec->moodlecourseid);
                    $fn = '\enrol_get_enrolled_users';
                    $enrolled_students = $fn($ctx, 'moodle/role:student');
                } catch (\Throwable $_e) {
                    $enrolled_students = [];
                }
            }

            $html .= '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">';
            $html .= '<form method="post" style="display:inline;margin:0;"><input type="hidden" name="sesskey" value="' . s(sesskey()) . '"><input type="hidden" name="action" value="bulk_present"><input type="hidden" name="sessionid" value="' . s($selected_sessionid) . '"><input type="hidden" name="occurrence_date" value="' . s(date('Y-m-d', $occurrence_ts)) . '"><button type="submit" class="ulms-btn ulms-btn--primary" style="min-width:44px;min-height:44px;">Mark All Present</button></form>';
            $html .= '<form method="post" style="display:inline;margin:0;"><input type="hidden" name="sesskey" value="' . s(sesskey()) . '"><input type="hidden" name="action" value="export_csv"><input type="hidden" name="sessionid" value="' . s($selected_sessionid) . '"><input type="hidden" name="occurrence_date" value="' . s(date('Y-m-d', $occurrence_ts)) . '"><button type="submit" class="ulms-btn" style="min-width:44px;min-height:44px;">Export CSV</button></form>';
            $html .= '</div>';

            $html .= '<table class="ulms-attendance-register" data-ulms-attendance="true" data-ulms-attendance-marks="true">';
            $html .= '<thead><tr><th data-label="Student ID">Student ID</th><th data-label="Name">Name</th><th data-label="Status">Status</th><th data-label="Marked At">Marked At</th><th data-label="Marked By">Marked By</th><th data-label="Quick Mark">Quick Mark (P / A / L / E)</th></tr></thead><tbody>';

            $marks = [];
            $rs = $DB->get_records('local_ulms_dashboard_attendance', ['sessionid' => $selected_sessionid, 'session_occurrence_date' => $occurrence_ts]);
            foreach ($rs as $r) {
                $marks[(int)$r->userid] = $r;
            }

            foreach ($enrolled_students as $u) {
                $uid = (int)$u->id;
                $sidnum = s($u->idnumber ?? (string)$uid);
                $fullname = fullname($u);
                $statusclass = '';
                $statuslabel = 'Unmarked';
                $markedat = '—';
                $marker = '—';
                if (isset($marks[$uid])) {
                    $m = $marks[$uid];
                    $statusclass = 'ulms-status-badge--' . s($m->status);
                    $statuslabel = s(ucfirst((string)$m->status));
                    $markedat = (int)($m->marked_at ?? 0) > 0 ? s(userdate((int)$m->marked_at)) : '—';
                    if ((int)($m->marked_by ?? 0) > 0) {
                        $mu = \core_user::get_user((int)$m->marked_by);
                        $marker = $mu ? s(fullname($mu)) : '—';
                    }
                }

                $html .= '<tr data-ulms-attendance-row="true" data-studentid="' . s($uid) . '">';
                $html .= '<td data-label="Student ID">' . $sidnum . '</td>';
                $html .= '<td data-label="Name">' . $fullname . '</td>';
                $html .= '<td data-label="Status"><span class="ulms-status-badge ' . $statusclass . '">' . $statuslabel . '</span></td>';
                $html .= '<td data-label="Marked At">' . $markedat . '</td>';
                $html .= '<td data-label="Marked By">' . $marker . '</td>';
                $html .= '<td data-label="Quick Mark"><div class="ulms-mark-buttons" data-ulms-mark-buttons="true">';

                $markdefs = [
                    ['status' => 'present', 'mnemonic' => 'P', 'class' => 'ulms-btn--success', 'title' => 'Present [P]'],
                    ['status' => 'absent',  'mnemonic' => 'A', 'class' => 'ulms-btn--danger',  'title' => 'Absent [A]'],
                    ['status' => 'late',    'mnemonic' => 'L', 'class' => 'ulms-btn--warning', 'title' => 'Late [L]'],
                    ['status' => 'excused', 'mnemonic' => 'E', 'class' => 'ulms-btn--info',    'title' => 'Excused [E]'],
                ];
                foreach ($markdefs as $md) {
                    $html .= '<form method="post" style="display:inline;margin:0;"><input type="hidden" name="sesskey" value="' . s(sesskey()) . '"><input type="hidden" name="action" value="mark"><input type="hidden" name="sessionid" value="' . s($selected_sessionid) . '"><input type="hidden" name="occurrence_date" value="' . s(date('Y-m-d', $occurrence_ts)) . '"><input type="hidden" name="userid" value="' . s($uid) . '"><input type="hidden" name="status" value="' . s($md['status']) . '"><button type="submit" class="ulms-btn ' . $md['class'] . ' ulms-mark-btn" data-status="' . s($md['status']) . '" data-mnemonic="' . s($md['mnemonic']) . '" style="min-width:44px;min-height:44px;margin:2px;" title="' . s($md['title']) . '">' . s($md['mnemonic']) . '</button></form>';
                }
                $html .= '</div></td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';

            $html .= <<<'FASTMARKSCRIPT'
<script data-ulms-fastmark="true">
(function(){
  if (window.__ULMS_ATTENDANCE_MARKS__ === true) return;
  window.__ULMS_ATTENDANCE_MARKS__ = true;
  document.addEventListener('keydown', function(e){
    if (/^(input|textarea|select)$/i.test(document.activeElement && document.activeElement.tagName || '')) return;
    var key = (e.key || '').toUpperCase();
    if (!/^[PALE]$/.test(key)) return;
    var rows = document.querySelectorAll('tr[data-ulms-attendance-row]');
    var focused = document.activeElement && document.activeElement.closest ? document.activeElement.closest('tr[data-ulms-attendance-row]') : null;
    var row = focused || rows[0];
    if (!row) return;
    var btn = row.querySelector('button[data-mnemonic="'+key+'"]');
    if (btn) btn.click();
  });
})();
</script>
FASTMARKSCRIPT;
        }

        return [
            'summarycards' => $summarycards,
            'mainpanel' => [
                'title' => 'Attendance Register Manager',
                'subtitle' => 'Mark individual attendance, bulk-mark all present, and export CSV registers',
                'style' => 'html',
                'html' => $html,
                'emptytitle' => 'No Sessions Available',
                'emptydesc' => 'Sessions will appear here once scheduled on the timetable.',
            ],
            'secondarypanels' => [],
        ];
    }

    private function build_admin_schedule_section_data(): array {
        global $DB;

        $service = schedule_service::instance();

        $weekstartparam = optional_param('weekstart', 0, PARAM_INT);
        if ($weekstartparam > 0) {
            $monday_ts = $weekstartparam;
        } else {
            $monday_ts = strtotime('monday this week 00:00:00');
        }

        $kpis = $service->get_dashboard_kpis();
        $conflicts = $service->find_conflicts(0, $monday_ts);

        $html = '';
        $html .= '<h3 style="margin:0 0 12px;font-size:1rem;font-weight:700;color:#0f4c81;">Scheduling Conflicts — Week of ' . s(date('M j, Y', $monday_ts)) . '</h3>';

        if (count($conflicts) === 0) {
            $html .= '<div style="padding:24px;border:1px dashed #1a7f37;border-radius:12px;background:#ecfdf5;color:#166534;text-align:center;font-weight:600;">✅ No conflicts detected for this week — all sessions are cleanly scheduled.</div>';
        } else {
            $html .= '<table class="ulms-attendance-register" style="margin-top:12px;">';
            $html .= '<thead><tr><th data-label="Time Slot">Time Slot</th><th data-label="Conflict Type">Conflict Type</th><th data-label="Session A">Session A</th><th data-label="Session B">Session B</th><th data-label="Overlap">Overlap Reason</th><th data-label="Actions">Actions</th></tr></thead><tbody>';
            foreach ($conflicts as $cf) {
                $sess_a = $DB->get_record('local_ulms_dashboard_session', ['id' => (int)$cf['session_a_id']], 'id, title, lecturer_userid, location_label, moodlecourseid');
                $sess_b = $DB->get_record('local_ulms_dashboard_session', ['id' => (int)$cf['session_b_id']], 'id, title, lecturer_userid, location_label, moodlecourseid');
                $lec_a = $sess_a && !empty($sess_a->lecturer_userid) ? s(fullname(\core_user::get_user((int)$sess_a->lecturer_userid))) : 'N/A';
                $lec_b = $sess_b && !empty($sess_b->lecturer_userid) ? s(fullname(\core_user::get_user((int)$sess_b->lecturer_userid))) : 'N/A';
                $type_label = ((string)$cf['conflict_type'] === 'lecturer_double_book')
                    ? '<span class="ulms-status-badge ulms-status-badge--live">Lecturer Double-Booked</span>'
                    : '<span class="ulms-status-badge ulms-status-badge--late">Room Double-Booked</span>';
                $reason = ((string)$cf['conflict_type'] === 'lecturer_double_book')
                    ? 'Same lecturer assigned to two simultaneous sessions'
                    : 'Same physical room booked for two simultaneous sessions';

                $edit_a = new \moodle_url('/course/view.php', ['id' => (int)($sess_a->moodlecourseid ?? 1)]);
                $edit_b = new \moodle_url('/course/view.php', ['id' => (int)($sess_b->moodlecourseid ?? 1)]);
                $action_html = '<div style="display:flex;flex-direction:column;gap:4px;">';
                $action_html .= '<a href="' . s($edit_a->out(false)) . '" class="ulms-btn" style="min-width:44px;min-height:44px;font-size:12px;padding:6px 10px;">Edit A</a>';
                $action_html .= '<a href="' . s($edit_b->out(false)) . '" class="ulms-btn" style="min-width:44px;min-height:44px;font-size:12px;padding:6px 10px;">Edit B</a>';
                $action_html .= '</div>';

                $html .= '<tr>';
                $html .= '<td data-label="Time Slot">' . s($cf['timeslot_label'] ?? '') . '</td>';
                $html .= '<td data-label="Conflict Type">' . $type_label . ' — ' . s($cf['entity_label'] ?? '') . '</td>';
                $html .= '<td data-label="Session A"><strong>' . s($sess_a ? format_string($sess_a->title) : ('Session #' . $cf['session_a_id'])) . '</strong><br><span style="color:#5c6f82;font-size:12px;">Lecturer: ' . $lec_a . '</span><br><span style="color:#5c6f82;font-size:12px;">Room: ' . s($sess_a->location_label ?? '') . '</span></td>';
                $html .= '<td data-label="Session B"><strong>' . s($sess_b ? format_string($sess_b->title) : ('Session #' . $cf['session_b_id'])) . '</strong><br><span style="color:#5c6f82;font-size:12px;">Lecturer: ' . $lec_b . '</span><br><span style="color:#5c6f82;font-size:12px;">Room: ' . s($sess_b->location_label ?? '') . '</span></td>';
                $html .= '<td data-label="Overlap">' . $reason . '</td>';
                $html .= '<td data-label="Actions">' . $action_html . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }

        return [
            'summarycards' => [
                ['label' => 'Total Sessions', 'value' => (string)$kpis['session_count'], 'description' => 'Active sessions platform-wide'],
                ['label' => 'Conflicts Detected', 'value' => (string)count($conflicts), 'description' => 'Lecturer or room double-bookings this week'],
                ['label' => 'Avg Attendance %', 'value' => $kpis['avg_attendance_percent'] . '%', 'description' => 'Platform-wide attendance average'],
            ],
            'mainpanel' => [
                'title' => 'Schedule Conflict Detection',
                'subtitle' => 'Detects lecturer double-bookings and physical room double-bookings across the timetable',
                'style' => 'html',
                'html' => $html,
            ],
            'secondarypanels' => [],
        ];
    }

    private function build_admin_attendanceaudit_section_data(): array {
        global $DB;

        $service = schedule_service::instance();
        $kpis = $service->get_dashboard_kpis();

        $facultyid = optional_param('facultyid', 0, PARAM_INT);
        $deptid = optional_param('deptid', 0, PARAM_INT);
        $progid = optional_param('progid', 0, PARAM_INT);
        $levelid = optional_param('levelid', 0, PARAM_INT);

        $html = '';
        $html .= '<form method="get" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:20px;padding:16px;border:1px solid #e1e7ee;border-radius:12px;background:#f8fbff;align-items:flex-end;">';
        $faculties = [0 => '-- All Faculties --'] + $DB->get_records_menu('local_ulms_faculties', null, 'name ASC', 'id, name');
        $departments = [0 => '-- All Departments --'] + $DB->get_records_menu('local_ulms_departments', null, 'name ASC', 'id, name');
        $programmes = [0 => '-- All Programmes --'] + $DB->get_records_menu('local_ulms_programmes', null, 'name ASC', 'id, name');
        $levels = [0 => '-- All Levels --'] + $DB->get_records_menu('local_ulms_levels', null, 'name ASC', 'id, name');

        foreach (['facultyid' => $faculties, 'deptid' => $departments, 'progid' => $programmes, 'levelid' => $levels] as $paramname => $menu) {
            $labelmap = ['facultyid' => 'Faculty', 'deptid' => 'Department', 'progid' => 'Programme', 'levelid' => 'Level'];
            $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">' . $labelmap[$paramname] . '</label><select name="' . $paramname . '" class="form-control custom-select" onchange="this.form.submit()">';
            foreach ($menu as $mid => $mlbl) {
                $selected = ($$paramname == $mid) ? ' selected' : '';
                $html .= '<option value="' . s($mid) . '"' . $selected . '>' . s($mlbl) . '</option>';
            }
            $html .= '</select></div>';
        }
        $html .= '</form>';

        $sql = "SELECT s.id, s.title, s.moodlecourseid, s.lecturer_userid, c.fullname AS coursename
                  FROM {local_ulms_dashboard_session} s
                  JOIN {course} c ON c.id = s.moodlecourseid
                 WHERE s.status <> 'cancelled'";
        $params = [];
        if ($facultyid > 0) { $sql .= " AND s.facultyid = :fid"; $params['fid'] = $facultyid; }
        if ($deptid > 0)    { $sql .= " AND s.departmentid = :did"; $params['did'] = $deptid; }
        if ($progid > 0)    { $sql .= " AND s.programmeid = :pid"; $params['pid'] = $progid; }
        if ($levelid > 0)   { $sql .= " AND s.levelid = :lid"; $params['lid'] = $levelid; }
        $sql .= " ORDER BY c.fullname ASC, s.title ASC LIMIT 100";

        try {
            $sessions = $DB->get_records_sql($sql, $params);
        } catch (\Throwable $_e) {
            $sessions = [];
        }

        $course_lecturer_rows = [];
        foreach ($sessions as $s) {
            $key = (int)$s->moodlecourseid . '_' . (int)$s->lecturer_userid;
            if (!isset($course_lecturer_rows[$key])) {
                $lecname = !empty($s->lecturer_userid) ? fullname(\core_user::get_user((int)$s->lecturer_userid)) : 'Unassigned';
                $course_lecturer_rows[$key] = [
                    'courseid' => (int)$s->moodlecourseid,
                    'coursename' => format_string($s->coursename),
                    'lecturer' => $lecname,
                    'lecturer_userid' => (int)$s->lecturer_userid,
                    'sessions' => [],
                ];
            }
            $course_lecturer_rows[$key]['sessions'][(int)$s->id] = format_string($s->title);
        }

        $html .= '<table class="ulms-attendance-register">';
        $html .= '<thead><tr><th data-label="Course">Course</th><th data-label="Lecturer">Lecturer</th><th data-label="Sessions">Sessions</th><th data-label="Attendance %">Attendance %</th><th data-label="Actions">Actions</th></tr></thead><tbody>';

        foreach ($course_lecturer_rows as $row) {
            $session_ids = array_keys($row['sessions']);
            $overall_present = 0;
            $overall_total = 0;
            foreach ($session_ids as $sid) {
                $sum = $service->get_session_attendance_summary($sid, (int)strtotime('today 00:00:00'));
                $overall_present += (int)($sum['present'] ?? 0) + (int)($sum['late'] ?? 0);
                $overall_total += (int)($sum['marked'] ?? 0);
            }
            $pct = $overall_total > 0 ? number_format(($overall_present / max(1, $overall_total)) * 100, 1) : 'N/A';
            $pctnum = $overall_total > 0 ? (float)$pct : 0;
            $barcolor = $pctnum >= 80 ? '#1a7f37' : ($pctnum >= 60 ? '#c76a00' : '#c8352a');
            $pctbar = '<div style="margin-top:4px;background:#e8eef6;border-radius:999px;height:8px;overflow:hidden;"><div style="height:100%;width:' . s($pct) . '%;background:' . $barcolor . ';"></div></div>';

            $sessionlist = implode(', ', array_slice($row['sessions'], 0, 3));
            if (count($row['sessions']) > 3) {
                $sessionlist .= ' (+' . (count($row['sessions']) - 3) . ' more)';
            }

            $drillurl = new \moodle_url('/course/view.php', ['id' => (int)$row['courseid']]);

            $html .= '<tr>';
            $html .= '<td data-label="Course"><strong>' . s($row['coursename']) . '</strong></td>';
            $html .= '<td data-label="Lecturer">' . s($row['lecturer']) . '</td>';
            $html .= '<td data-label="Sessions">' . s($sessionlist) . '</td>';
            $html .= '<td data-label="Attendance %"><strong>' . s($pct) . '%</strong>' . $pctbar . '</td>';
            $html .= '<td data-label="Actions"><a href="' . s($drillurl->out(false)) . '" class="ulms-btn ulms-btn--primary" style="min-width:44px;min-height:44px;font-size:12px;padding:6px 10px;">Drill-down</a></td>';
            $html .= '</tr>';
        }
        if (count($course_lecturer_rows) === 0) {
            $html .= '<tr><td colspan="5" style="text-align:center;padding:24px;color:#5c6f82;">No attendance data available for the selected filters.</td></tr>';
        }
        $html .= '</tbody></table>';

        return [
            'summarycards' => [
                ['label' => 'Attendance Records', 'value' => (string)$kpis['attendance_record_count'], 'description' => 'Total marked attendance records platform-wide'],
                ['label' => 'Platform Avg %', 'value' => $kpis['avg_attendance_percent'] . '%', 'description' => 'Average attendance across all sessions'],
                ['label' => 'Sessions Tracked', 'value' => (string)$kpis['session_count'], 'description' => 'Active sessions on the platform'],
                ['label' => 'Active Conflicts', 'value' => (string)$kpis['conflict_count'], 'description' => 'Current scheduling conflicts'],
            ],
            'mainpanel' => [
                'title' => 'Attendance Audit Dashboard',
                'subtitle' => 'Cross-cutting view of attendance by course × lecturer with filterable drill-downs',
                'style' => 'html',
                'html' => $html,
            ],
            'secondarypanels' => [],
        ];
    }
}
