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
     * Safe wrapper around get_string() that guards against Moodle cache-stale
     * literal `[[stringid]]` placeholders leaking into the UI by falling back to
     * a supplied human-readable default whenever the translated string is empty
     * or contains the unknown-string marker.
     *
     * @param string $identifier language string identifier
     * @param string $fallback   plain-text fallback used when the identifier cannot be resolved
     * @param string|int|float|object|array|null $a optional placeholder substitution value
     * @return string resolved language string (or fallback)
     */
    private static function safe_get_string(string $identifier, string $fallback, $a = null): string {
        try {
            if ($a === null) {
                $value = @get_string($identifier, 'local_ulms_dashboard');
            } else {
                $value = @get_string($identifier, 'local_ulms_dashboard', $a);
            }
        } catch (\Throwable) {
            $value = '';
        }
        if (!is_string($value) || $value === '' || strpos($value, '[[') !== false) {
            if ($a !== null && is_scalar($a)) {
                $str = (string)$a;
                if (str_contains($fallback, '{$a}')) {
                    return strtr($fallback, ['{$a}' => $str]);
                }
                return trim($fallback . ' ' . $str);
            }
            return $fallback;
        }
        return $value;
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
            'lecturers' => $this->build_admin_lecturer_allocation_data(),
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
     * Returns all moodlecourseid values that are mapped to the given
     * student's registered programme via local_ulms_programme_courses.
     *
     * Used as a security whitelist for all self-enrol POST operations.
     *
     * @param int $userid
     * @return array<int,int> moodlecourseid list (empty if student has no programme)
     */
    public static function resolve_programme_courseids_for_student(int $userid): array {
        global $DB;
        if ($userid <= 0) {
            return [];
        }
        $profile = $DB->get_record(
            'local_ulms_user_profile',
            ['userid' => $userid],
            'id,programmeid',
            IGNORE_MISSING
        );
        $programmeid = (int)($profile->programmeid ?? 0);
        if ($programmeid <= 0) {
            return [];
        }
        try {
            $rows = $DB->get_records_sql(
                "SELECT DISTINCT pc.moodlecourseid
                   FROM {local_ulms_programme_courses} pc
                   JOIN {course} c ON c.id = pc.moodlecourseid
                  WHERE pc.programmeid = :pid
                    AND c.id > 1
                    AND c.visible = 1",
                ['pid' => $programmeid]
            );
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $cid = (int)$r->moodlecourseid;
            if ($cid > 0) {
                $out[$cid] = $cid;
            }
        }
        return $out;
    }

    /**
     * Enrol a student into a SINGLE programme course (the "Enrol now" button).
     *
     * Strict security: courseid MUST be inside the programme-scoped whitelist
     * returned by resolve_programme_courseids_for_student(). Otherwise the
     * enrolment is rejected with an error message. This prevents any forged
     * POST attempt to sneak into another programme's courses.
     *
     * Prefers enrol_self when the course already has an enabled self instance.
     * Falls back to enrol_manual (adding the instance if needed) when self
     * is missing/disabled so programme-scoped enrol always succeeds.
     *
     * @param int $actoruserid
     * @param int $courseid
     * @return array{ok:bool, already:bool, msg:string} result tuple
     */
    public static function self_enrol_student_in_programme_course(int $actoruserid, int $courseid): array {
        global $DB;
        if ($actoruserid <= 0 || $courseid <= 0) {
            return ['ok' => false, 'already' => false, 'msg' => 'Invalid request.'];
        }
        $whitelist = self::resolve_programme_courseids_for_student($actoruserid);
        if (empty($whitelist) || !isset($whitelist[$courseid])) {
            return [
                'ok' => false,
                'already' => false,
                'msg' => 'This course is not in your registered programme. Contact your department administrator.',
            ];
        }
        try {
            $coursectx = \context_course::instance($courseid, IGNORE_MISSING);
        } catch (\Throwable) {
            $coursectx = null;
        }
        if (!$coursectx) {
            return ['ok' => false, 'already' => false, 'msg' => 'Course not found.'];
        }
        if (is_enrolled($coursectx, $actoruserid, null, true)) {
            return ['ok' => true, 'already' => true, 'msg' => 'You are already enrolled in this course.'];
        }
        $course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
        if (!$course) {
            return ['ok' => false, 'already' => false, 'msg' => 'Course not found.'];
        }
        $studentroleid = (int)$DB->get_field_select('role', 'id', "shortname = 'student'", [], IGNORE_MISSING);
        if ($studentroleid <= 0) {
            $studentroleid = 5;
        }

        $instances = enrol_get_instances($courseid, true);
        $choseninstance = null;
        $chosenplugin = null;
        foreach ($instances as $inst) {
            if ($inst->enrol === 'self' && (int)$inst->status === ENROL_INSTANCE_ENABLED) {
                try {
                    $plugin = enrol_get_plugin('self');
                } catch (\Throwable) {
                    $plugin = null;
                }
                if ($plugin) {
                    $choseninstance = $inst;
                    $chosenplugin = $plugin;
                    break;
                }
            }
        }
        if (!$chosenplugin) {
            try {
                $manualplugin = enrol_get_plugin('manual');
            } catch (\Throwable) {
                $manualplugin = null;
            }
            if (!$manualplugin) {
                return ['ok' => false, 'already' => false, 'msg' => 'Enrolment is unavailable on this course. Contact your administrator.'];
            }
            $manualinstance = null;
            foreach ($instances as $inst) {
                if ($inst->enrol === 'manual' && (int)$inst->status === ENROL_INSTANCE_ENABLED) {
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
                    $manualinstance = null;
                }
                if (!$manualinstance) {
                    return ['ok' => false, 'already' => false, 'msg' => 'Could not prepare enrolment for this course.'];
                }
            }
            try {
                $manualplugin->enrol_user($manualinstance, $actoruserid, $studentroleid, time(), 0);
                return ['ok' => true, 'already' => false, 'msg' => 'Successfully enrolled in ' . format_string($course->fullname) . '.'];
            } catch (\Throwable $e) {
                return ['ok' => false, 'already' => false, 'msg' => 'Enrolment failed: ' . $e->getMessage()];
            }
        }
        try {
            $chosenplugin->enrol_user($choseninstance, $actoruserid, $studentroleid, time(), 0);
            return ['ok' => true, 'already' => false, 'msg' => 'Successfully enrolled in ' . format_string($course->fullname) . '.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'already' => false, 'msg' => 'Enrolment failed: ' . $e->getMessage()];
        }
    }

    /**
     * Bulk enrol the given student into ALL programme-courses they are not
     * yet enrolled in. Idempotent: already-enrolled courses are skipped.
     *
     * Iterates the programme-courses whitelist from resolve_programme_courseids_for_student()
     * and calls self_enrol_student_in_programme_course() (which enforces the
     * same security guard + enrols via self→manual plugin fallback). This
     * guarantees identical enrolment semantics between single "Enrol now"
     * clicks and the bulk "Enrol in all programme courses" button.
     *
     * @param int $userid
     * @return int total NEW enrolments performed (skips excluded)
     */
    public static function enrol_student_all_programme_courses(int $userid): int {
        $ids = self::resolve_programme_courseids_for_student($userid);
        if (empty($ids)) {
            return 0;
        }
        $new = 0;
        foreach ($ids as $cid) {
            $res = self::self_enrol_student_in_programme_course($userid, (int)$cid);
            if (!empty($res['ok']) && empty($res['already'])) {
                $new++;
            }
        }
        return $new;
    }

    /**
     * Builds a catalog page.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function build_catalog_section_data(array $snapshot): array {
        global $DB, $USER;

        $userid = !empty($USER->id) ? (int)$USER->id : 0;
        $profile = null;
        $programme = null;
        $programmeid = 0;
        if ($userid > 0) {
            $profile = $DB->get_record(
                'local_ulms_user_profile',
                ['userid' => $userid],
                'id,programmeid,facultyid,departmentid,studylevel',
                IGNORE_MISSING
            );
            $programmeid = (int)($profile->programmeid ?? 0);
            if ($programmeid > 0) {
                $programme = $DB->get_record(
                    'local_ulms_programmes',
                    ['id' => $programmeid],
                    'id,code,name,departmentid',
                    IGNORE_MISSING
                );
            }
        }

        $items = [];
        $programmetotal = 0;
        $enrolledcount = 0;
        $sesskey = sesskey();
        $enrolurl = (new \moodle_url('/student/catalog/enrol.php'))->out(false);
        if ($programmeid > 0) {
            $sql = "SELECT c.id, c.shortname, c.fullname, c.summary, c.visible,
                           pc.semesterid, pc.coursetype, pc.iscore
                      FROM {local_ulms_programme_courses} pc
                      JOIN {course} c ON c.id = pc.moodlecourseid
                     WHERE pc.programmeid = :pid
                       AND c.id > 1
                       AND c.visible = 1
                  ORDER BY pc.semesterid ASC, pc.iscore DESC, c.shortname ASC";
            $rows = $DB->get_records_sql($sql, ['pid' => $programmeid]);
            $programmetotal = count($rows);
            foreach ($rows as $row) {
                try {
                    $coursectx = \context_course::instance((int)$row->id, IGNORE_MISSING);
                } catch (\Throwable) {
                    $coursectx = null;
                }
                $isenrolled = $coursectx && is_enrolled($coursectx, $userid, null, true);
                if ($isenrolled) {
                    $enrolledcount++;
                }
                $typebadge = !empty($row->iscore) ? 'Core' : 'Elective';
                $courseurl = (new \moodle_url('/course/view.php', ['id' => (int)$row->id]))->out(false);
                if ($isenrolled) {
                    $footer = sprintf(
                        '<span class="ulms-enroled-line">'
                        . '<span class="ulms-enroled-badge">%s</span>'
                        . '<span class="ulms-enroled-sep">·</span>'
                        . '<span class="ulms-enrol-link">%s →</span>'
                        . '</span>',
                        get_string('studentcatalogenroled', 'local_ulms_dashboard'),
                        get_string('studentcatalogopen', 'local_ulms_dashboard')
                    );
                } else {
                    $footer = sprintf(
                        '<form method="post" action="%s" style="margin:0;display:inline">'
                        . '<input type="hidden" name="sesskey" value="%s">'
                        . '<input type="hidden" name="courseid" value="%d">'
                        . '<button type="submit" class="btn btn-primary btn-sm">%s</button>'
                        . '</form>',
                        $enrolurl,
                        $sesskey,
                        (int)$row->id,
                        get_string('studentcatalogenrolnow', 'local_ulms_dashboard')
                    );
                }
                $items[] = [
                    'title' => format_string($row->fullname),
                    'meta' => shorten_text(strip_tags(format_text((string)$row->summary, FORMAT_HTML)), 120)
                            . ' · ' . $typebadge,
                    'url' => $isenrolled ? $courseurl : null,
                    'footer' => $footer,
                    'courseid' => (int)$row->id,
                    'is_enrolled' => $isenrolled,
                    'coursetype' => $typebadge,
                    'semesterid' => (int)$row->semesterid,
                ];
            }
        }

        $hastile = !empty($programme);
        $tiles = [];
        if ($hastile) {
            $tiles[] = [
                'id' => 'programme-tile',
                'style' => 'banner',
                'title' => sprintf(
                    '%s — %s',
                    format_string($programme->code ?? ''),
                    format_string($programme->name ?? '')
                ),
                'meta' => get_string('studentcatalogprogrammedesc', 'local_ulms_dashboard', (object)[
                    'code' => format_string($programme->code ?? ''),
                ]),
                'footer' => sprintf(
                    '%d courses in your programme · %d currently enrolled',
                    $programmetotal,
                    $enrolledcount
                ),
                'bulkurl' => (new \moodle_url('/student/catalog/enrol.php'))->out(false),
                'showbulk' => $programmetotal > $enrolledcount,
                'courseids_csv' => implode(',', array_column($items, 'courseid')),
            ];
        }

        return [
            'summarycards' => $hastile ? [
                ['label' => get_string('studentcatalogsummaryprogramme', 'local_ulms_dashboard'), 'value' => format_string($programme->code ?? ''), 'description' => format_string($programme->name ?? '')],
                ['label' => get_string('studentcatalogsummaryavailable', 'local_ulms_dashboard'), 'value' => (string)$programmetotal, 'description' => get_string('studentcatalogsummaryavailabledesc', 'local_ulms_dashboard')],
                ['label' => get_string('coursecountsummary', 'local_ulms_dashboard'), 'value' => (string)$enrolledcount, 'description' => get_string('studentcoursescountdesc', 'local_ulms_dashboard')],
            ] : [
                ['label' => get_string('studentcatalogsummaryavailable', 'local_ulms_dashboard'), 'value' => (string)$programmetotal, 'description' => get_string('studentcatalogsummaryavailabledesc', 'local_ulms_dashboard')],
                ['label' => get_string('coursecountsummary', 'local_ulms_dashboard'), 'value' => (string)$snapshot['coursecount'], 'description' => get_string('studentcoursescountdesc', 'local_ulms_dashboard')],
            ],
            'mainpanel' => [
                'title' => get_string('studentcatalogtitle', 'local_ulms_dashboard'),
                'subtitle' => $hastile
                    ? get_string('studentcatalogdesc_programmescoped', 'local_ulms_dashboard', (object)['code' => format_string($programme->code ?? '')])
                    : get_string('studentcatalogdesc', 'local_ulms_dashboard'),
                'style' => 'cards',
                'items' => $items,
                'tiles' => $tiles,
                'programmeid' => $programmeid,
                'emptytitle' => $hastile ? get_string('studentcatalogempty_programme', 'local_ulms_dashboard') : get_string('studentcatalogempty', 'local_ulms_dashboard'),
                'emptydesc' => $hastile ? get_string('studentcatalogemptydesc_programme', 'local_ulms_dashboard') : get_string('studentcatalogemptydesc', 'local_ulms_dashboard'),
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
     * Student view (approved redesign): renders ONE CARD PER ENROLLED COURSE,
     * even if the course has 0 assignments (avoids "page looks empty / broken"
     * UX). Each course card:
     *   - header row with course code/name + aggregate status badge pill
     *     (e.g. "1 Due soon · 2 open" or "0 assignments")
     *   - nested sub-list 0..N of the course's assignments with
     *     per-assignment status (Open / Due soon / Overdue / Submitted / Graded)
     *   - clickable sub-rows link directly to /mod/assign/view.php?id=CMID
     *
     * Lecturer view unchanged: flat assignment list (grading queue style).
     *
     * Scope guarantee: courseids passed in via caller are strictly the user's
     * enrolled courses (snapshot.courseids), so students CANNOT see
     * assignments from courses outside their enrolment / programme scope.
     *
     * @param array<string, mixed> $snapshot
     * @param bool $islecturer
     * @return array<string, mixed>
     */
    private function build_assignment_section_data(array $snapshot, bool $islecturer): array {
        global $DB, $USER;

        if ($islecturer) {
            $items = $this->get_assignment_list_items($snapshot['courseids'], 12);
            return [
                'summarycards' => [
                    ['label' => get_string('assignmentsummary', 'local_ulms_dashboard'), 'value' => (string)count($items), 'description' => get_string('lecturerassignmentsdesc', 'local_ulms_dashboard')],
                    ['label' => get_string('gradingqueuesummary', 'local_ulms_dashboard'), 'value' => (string)count($items), 'description' => get_string('lecturergradingcountdesc', 'local_ulms_dashboard')],
                ],
                'mainpanel' => [
                    'title' => get_string('lecturerassignmentstitle', 'local_ulms_dashboard'),
                    'subtitle' => get_string('lecturerassignmentsdesc', 'local_ulms_dashboard'),
                    'style' => 'list',
                    'items' => $items,
                    'emptytitle' => get_string('lecturerassignmentsempty', 'local_ulms_dashboard'),
                    'emptydesc' => get_string('lecturerassignmentsemptydesc', 'local_ulms_dashboard'),
                ],
                'secondarypanels' => [],
            ];
        }

        $courseids = array_values(array_map('intval', $snapshot['courseids'] ?? []));
        $userid = (int)($snapshot['userid'] ?? $USER->id);
        $grouped = $this->get_assignments_grouped_by_course($courseids, $userid);

        $coursecards = [];
        $totalAssignments = 0;
        $totalDueSoon7 = 0;
        $totalOverdue = 0;
        $totalSubmitted = 0;

        foreach ($grouped as $courseid => $g) {
            $courserec = $g['course'];
            $assignments = $g['assignments'] ?? [];
            $counts = $g['counts'];
            $totalAssignments += (int)$counts['total'];
            $totalDueSoon7 += (int)$counts['duesoon7'];
            $totalOverdue += (int)$counts['overdue'];
            $totalSubmitted += (int)$counts['submitted'];

            $coursetitle = format_string((string)$courserec->fullname);
            $courseurl = new \moodle_url('/course/view.php', ['id' => (int)$courseid]);
            $coursemeta = trim(format_string((string)($courserec->shortname ?? ''))
                . (isset($courserec->coursetype) ? ' · ' . format_string((string)$courserec->coursetype) : '')
                . (isset($courserec->semesterlabel) ? ' · ' . format_string((string)$courserec->semesterlabel) : ''));

            $badgehtml = self::render_course_assignment_badge($counts);

            $subitems = [];
            foreach ($assignments as $a) {
                $statusClass = self::assignment_status_css_class($a['status']);
                $statusText = self::assignment_status_lang($a['status']);
                $dueHtml = !empty($a['duedate']) ? userdate((int)$a['duedate']) : get_string('duedateno');
                $gradeHtml = ($a['status'] === 'graded' && $a['grade'] !== null && $a['grade'] >= 0)
                    ? ' · ' . get_string('gradeoutof', 'local_ulms_dashboard', (object)[
                        'grade' => (string)round((float)$a['grade'], 1),
                        'max' => (string)(int)($a['grademax'] ?? 100),
                    ])
                    : '';
                $subitems[] = [
                    'title' => format_string($a['name']),
                    'meta' => $dueHtml
                        . ' · <span class="ulms-assignstatus ulms-assignstatus--' . $statusClass . '">' . $statusText . '</span>'
                        . $gradeHtml,
                    'meta_raw' => true,
                    'url' => new \moodle_url('/mod/assign/view.php', ['id' => (int)$a['cmid']]),
                ];
            }

            $coursecards[] = [
                'title' => $coursetitle,
                'meta' => $coursemeta,
                'url' => $courseurl,
                'badgehtml' => $badgehtml,
                'assignments' => $subitems,
                'sublistempty' => get_string('studentassignmentsnoassignmentscourse', 'local_ulms_dashboard'),
            ];
        }

        return [
            'summarycards' => [
                [
                    'label' => get_string('studentassignmentstotalcourses', 'local_ulms_dashboard'),
                    'value' => (string)count($coursecards),
                    'description' => get_string('studentassignmentstotalcoursesdesc', 'local_ulms_dashboard'),
                ],
                [
                    'label' => get_string('studentassignmentsduesoon', 'local_ulms_dashboard'),
                    'value' => (string)$totalDueSoon7,
                    'description' => get_string('studentassignmentsduesoondesc', 'local_ulms_dashboard'),
                ],
                [
                    'label' => get_string('studentassignmentsoverdue', 'local_ulms_dashboard'),
                    'value' => (string)$totalOverdue,
                    'description' => get_string('studentassignmentsoverduedesc', 'local_ulms_dashboard'),
                ],
                [
                    'label' => get_string('studentassignmentssubmitted', 'local_ulms_dashboard'),
                    'value' => (string)$totalSubmitted,
                    'description' => get_string('studentassignmentssubmitteddesc', 'local_ulms_dashboard'),
                ],
            ],
            'mainpanel' => [
                'title' => get_string('studentassignmentstitle', 'local_ulms_dashboard'),
                'subtitle' => get_string('studentassignmentsdescgrouped', 'local_ulms_dashboard'),
                'style' => 'coursegroups',
                'items' => $coursecards,
                'emptytitle' => get_string('studentassignmentsemptycourses', 'local_ulms_dashboard'),
                'emptydesc' => get_string('studentassignmentsemptycoursesdesc', 'local_ulms_dashboard'),
            ],
            'secondarypanels' => [],
        ];
    }

    /**
     * Fetches assignments GROUPED by enrolled course + computes per-assignment
     * status (Open / DueSoon / Overdue / Submitted / Graded) by joining
     * submission + grade tables for the specified student.
     *
     * Scope: only course ids in $courseids are joined (guaranteed enrolment
     * scoped by caller). Courses with 0 assignment instances are still returned
     * in the output so they render on the page.
     *
     * @param int[] $courseids enrolled set
     * @param int $userid student
     * @return array<int, array{course:object, assignments:array<int, array>, counts:array{total:int, open:int, duesoon24:int, duesoon7:int, overdue:int, submitted:int, graded:int}>
     */
    private function get_assignments_grouped_by_course(array $courseids, int $userid): array {
        global $DB;
        $out = [];
        if (empty($courseids)) {
            return $out;
        }
        $courseids = array_values(array_unique(array_map('intval', $courseids)));

        $courserecs = $DB->get_records_sql(
            "SELECT c.id, c.shortname, c.fullname, c.visible, c.category
               FROM {course} c
              WHERE c.id IN (" . implode(',', $courseids) . ") AND c.id > 1
              ORDER BY c.shortname ASC"
        );

        $programmeMeta = [];
        try {
            $progRows = $DB->get_records_sql(
                "SELECT pc.moodlecourseid, pc.coursetype, s.name AS semesterlabel
                   FROM {local_ulms_programme_courses} pc
              LEFT JOIN {local_ulms_semesters} s ON s.id = pc.semesterid
                  WHERE pc.moodlecourseid IN (" . implode(',', $courseids) . ")"
            );
            foreach ($progRows as $pr) {
                $programmeMeta[(int)$pr->moodlecourseid] = [
                    'coursetype' => !empty($pr->coursetype) ? ucfirst((string)$pr->coursetype) : 'Core',
                    'semesterlabel' => !empty($pr->semesterlabel) ? (string)$pr->semesterlabel : '',
                ];
            }
        } catch (\Throwable) {
            $programmeMeta = [];
        }

        foreach ($courserecs as $cr) {
            $cid = (int)$cr->id;
            if (isset($programmeMeta[$cid])) {
                $cr->coursetype = $programmeMeta[$cid]['coursetype'];
                if (!empty($programmeMeta[$cid]['semesterlabel'])) {
                    $cr->semesterlabel = $programmeMeta[$cid]['semesterlabel'];
                }
            }
            $out[$cid] = [
                'course' => $cr,
                'assignments' => [],
                'counts' => [
                    'total' => 0,
                    'open' => 0,
                    'duesoon24' => 0,
                    'duesoon7' => 0,
                    'overdue' => 0,
                    'submitted' => 0,
                    'graded' => 0,
                ],
            ];
        }

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $now = time();
        $DAY = 86400;
        $params['subuid'] = $userid;
        $params['gradeuid'] = $userid;
        $sql = "SELECT a.id,
                       a.course,
                       a.name,
                       a.duedate,
                       a.allowsubmissionsfromdate,
                       a.grade,
                       cm.id AS cmid,
                       cm.visible AS cmvisible,
                       s.status AS submissionstatus,
                       s.timemodified AS submissiontime,
                       g.grade AS gradekey
                  FROM {assign} a
                  JOIN {modules} m ON m.name = 'assign'
                  JOIN {course_modules} cm ON cm.instance = a.id AND cm.module = m.id AND cm.course = a.course
             LEFT JOIN {assign_submission} s ON s.assignment = a.id AND s.userid = :subuid AND s.latest = 1
             LEFT JOIN {assign_grades} g ON g.assignment = a.id AND g.userid = :gradeuid
                 WHERE a.course {$insql} AND cm.visible = 1
              ORDER BY a.course ASC, a.duedate ASC, a.name ASC";
        $rows = $DB->get_records_sql($sql, $params);

        foreach ($rows as $r) {
            $cid = (int)$r->course;
            if (!isset($out[$cid])) continue;
            $duedate = (int)$r->duedate;
            $from = (int)($r->allowsubmissionsfromdate ?? 0);
            $isSubmitted = in_array((int)($r->submissionstatus ?? 0), [1 /* submitted */, 2 /* graded after submit */], true)
                || ((int)($r->submissionstatus ?? 0) === 0 && !empty($r->submissiontime));
            $gradeVal = $r->gradekey === null ? null : (float)$r->gradekey;
            $isGraded = ($gradeVal !== null && $gradeVal >= -0.0001);
            $isOverdue = $duedate > 0 && $now > $duedate + 0 && !$isSubmitted;
            $isDueSoon24 = !$isSubmitted && !$isOverdue && $duedate > 0 && $duedate - $now <= $DAY && $duedate - $now > 0;
            $isDueSoon7 = !$isSubmitted && !$isOverdue && $duedate > 0 && $duedate - $now <= 7 * $DAY && $duedate - $now > 0;
            $isOpen = !$isSubmitted && !$isOverdue && ($from <= 0 || $now >= $from);
            if ($isGraded) $status = 'graded';
            else if ($isSubmitted) $status = 'submitted';
            else if ($isOverdue) $status = 'overdue';
            else if ($isDueSoon24) $status = 'duesoon';
            else if ($isOpen) $status = 'open';
            else $status = 'open';

            $a = [
                'id' => (int)$r->id,
                'cmid' => (int)$r->cmid,
                'name' => (string)$r->name,
                'duedate' => $duedate,
                'grademax' => (int)($r->grade > 0 ? $r->grade : 100),
                'grade' => $gradeVal,
                'status' => $status,
            ];
            $out[$cid]['assignments'][] = $a;
            $out[$cid]['counts']['total']++;
            if ($status === 'open') {
                $out[$cid]['counts']['open']++;
            } else if ($status === 'duesoon') {
                $out[$cid]['counts']['duesoon24']++;
                $out[$cid]['counts']['duesoon7']++;
            } else if ($status === 'overdue') {
                $out[$cid]['counts']['overdue']++;
            } else if ($status === 'submitted') {
                $out[$cid]['counts']['submitted']++;
            } else if ($status === 'graded') {
                $out[$cid]['counts']['graded']++;
                $out[$cid]['counts']['submitted']++;
            }
            if ($isDueSoon7 && $status !== 'duesoon') {
                // 7-day window includes DueSoon24 already (counted above); if not, also bump duesoon7 window count for KPI card
            }
        }

        // duesoon7 = duesoon24 already counted + open with due in 3-7d
        foreach ($out as $cid => $g) {
            foreach ($g['assignments'] as $a) {
                if ($a['status'] === 'open' && $a['duedate'] > 0 && ($a['duedate'] - $now) <= 7 * $DAY && ($a['duedate'] - $now) > 0) {
                    if (($a['duedate'] - $now) > $DAY) {
                        $out[$cid]['counts']['duesoon7']++;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Renders the aggregate status pill shown on a course card header.
     *
     * Examples:
     *   - 0 assignments → soft gray "0 assignments" pill
     *   - 1 Due soon + 1 open → indigo "1 Due soon · 1 open"
     *   - 0 due, 2 submitted → green "2 submitted"
     *   - 1 overdue → red "1 Overdue"
     *
     * @param array{total:int, open:int, duesoon24:int, duesoon7:int, overdue:int, submitted:int, graded:int} $c
     * @return string HTML
     */
    private static function render_course_assignment_badge(array $c): string {
        $total = (int)($c['total'] ?? 0);
        if ($total <= 0) {
            return '<span class="ulms-coursestat ulms-coursestat--zero">'
                . get_string('studentassignmentszero', 'local_ulms_dashboard')
                . '</span>';
        }
        $parts = [];
        if (!empty($c['overdue'])) {
            $parts[] = '<span class="ulms-coursestat ulms-coursestat--overdue">'
                . get_string('studentassignmentsoverduecount', 'local_ulms_dashboard', (int)$c['overdue'])
                . '</span>';
        }
        if (!empty($c['duesoon24'])) {
            $parts[] = '<span class="ulms-coursestat ulms-coursestat--duesoon">'
                . get_string('studentassignmentsduesooncount', 'local_ulms_dashboard', (int)$c['duesoon24'])
                . '</span>';
        } else if (!empty($c['duesoon7'])) {
            $parts[] = '<span class="ulms-coursestat ulms-coursestat--duesoon7">'
                . get_string('studentassignmentsdue7count', 'local_ulms_dashboard', (int)$c['duesoon7'])
                . '</span>';
        }
        if (!empty($c['open']) && empty($c['duesoon24'])) {
            $parts[] = '<span class="ulms-coursestat ulms-coursestat--open">'
                . get_string('studentassignmentsopencount', 'local_ulms_dashboard', (int)$c['open'])
                . '</span>';
        }
        if (!empty($c['submitted'])) {
            $parts[] = '<span class="ulms-coursestat ulms-coursestat--submitted">'
                . get_string('studentassignmentssubmittedcount', 'local_ulms_dashboard', (int)$c['submitted'])
                . '</span>';
        }
        if (empty($parts)) {
            $parts[] = '<span class="ulms-coursestat ulms-coursestat--open">'
                . get_string('studentassignmentstotalcount', 'local_ulms_dashboard', $total)
                . '</span>';
        }
        return implode('', $parts);
    }

    /**
     * Returns the CSS modifier class used by a per-assignment status pill.
     *
     * @param string $status one of open/duesoon/overdue/submitted/graded
     * @return string
     */
    private static function assignment_status_css_class(string $status): string {
        return match ($status) {
            'open' => 'open',
            'duesoon' => 'duesoon',
            'overdue' => 'overdue',
            'submitted' => 'submitted',
            'graded' => 'graded',
            default => 'open',
        };
    }

    /**
     * Human-readable label for an assignment status pill.
     *
     * @param string $status
     * @return string
     */
    private static function assignment_status_lang(string $status): string {
        return match ($status) {
            'open' => get_string('studentassignmentsstatusopen', 'local_ulms_dashboard'),
            'duesoon' => get_string('studentassignmentsstatusduesoon', 'local_ulms_dashboard'),
            'overdue' => get_string('studentassignmentsstatusoverdue', 'local_ulms_dashboard'),
            'submitted' => get_string('studentassignmentsstatussubmitted', 'local_ulms_dashboard'),
            'graded' => get_string('studentassignmentsstatusgraded', 'local_ulms_dashboard'),
            default => get_string('studentassignmentsstatusopen', 'local_ulms_dashboard'),
        };
    }

    /**
     * Builds quiz section data.
     *
     * Student view (approved redesign): ONE CARD PER ENROLLED COURSE, always
     * visible even if 0 quizzes (same grouping / mental model pattern as
     * Assignments page, so student UX stays consistent).
     *
     * Each quiz card shows:
     *   - access window (open date → close date) instead of single "due"
     *   - time-limit pill (e.g. "30 min")
     *   - attempts used / max (e.g. "1/3")
     *   - status pill: Not yet open / Open / In progress / Completed /
     *     Closed (missed) / Graded (with grade/max if known)
     *
     * Sync guarantee: reads directly from {quiz}+{course_modules}.visible=1
     * for published-quiz visibility, {quiz_attempts}.state for attempt
     * status, and {quiz_grades}.grade for final grade — so what the student
     * sees here is 100% in sync with what the lecturer uploaded/published and
     * any live grading changes in the quiz module.
     *
     * @param array<string, mixed> $snapshot
     * @param bool $islecturer
     * @return array<string, mixed>
     */
    private function build_quiz_section_data(array $snapshot, bool $islecturer): array {
        global $DB, $USER;

        if ($islecturer) {
            $items = $this->get_quiz_list_items($snapshot['courseids'], 12);
            return [
                'summarycards' => [
                    ['label' => get_string('studentquizsummary', 'local_ulms_dashboard'), 'value' => (string)count($items), 'description' => get_string('lecturerquizzesdesc', 'local_ulms_dashboard')],
                    ['label' => get_string('coursecountsummary', 'local_ulms_dashboard'), 'value' => (string)$snapshot['coursecount'], 'description' => get_string('lecturercoursespagedesc', 'local_ulms_dashboard')],
                ],
                'mainpanel' => [
                    'title' => get_string('lecturerquizzestitle', 'local_ulms_dashboard'),
                    'subtitle' => get_string('lecturerquizzesdesc', 'local_ulms_dashboard'),
                    'style' => 'list',
                    'items' => $items,
                    'emptytitle' => get_string('lecturerquizzesempty', 'local_ulms_dashboard'),
                    'emptydesc' => get_string('lecturerquizzesemptydesc', 'local_ulms_dashboard'),
                ],
                'secondarypanels' => [],
            ];
        }

        $courseids = array_values(array_map('intval', $snapshot['courseids'] ?? []));
        $userid = (int)($snapshot['userid'] ?? $USER->id);
        $grouped = $this->get_quizzes_grouped_by_course($courseids, $userid);

        $coursecards = [];
        $totalQuizzes = 0;
        $totalOpen = 0;
        $totalClosing7 = 0;
        $totalCompleted = 0;
        $totalClosed = 0;

        foreach ($grouped as $courseid => $g) {
            $courserec = $g['course'];
            $quizzes = $g['quizzes'] ?? [];
            $counts = $g['counts'];
            $totalQuizzes += (int)$counts['total'];
            $totalOpen += (int)$counts['open'];
            $totalClosing7 += (int)$counts['closing7'];
            $totalCompleted += (int)$counts['completed'];
            $totalClosed += (int)$counts['closed'];

            $coursetitle = format_string((string)$courserec->fullname);
            $courseurl = new \moodle_url('/course/view.php', ['id' => (int)$courseid]);
            $coursemeta = trim(format_string((string)($courserec->shortname ?? ''))
                . (isset($courserec->coursetype) ? ' · ' . format_string((string)$courserec->coursetype) : '')
                . (isset($courserec->semesterlabel) ? ' · ' . format_string((string)$courserec->semesterlabel) : ''));

            $badgehtml = self::render_course_quiz_badge($counts);

            $subitems = [];
            foreach ($quizzes as $q) {
                $statusClass = self::quiz_status_css_class($q['status']);
                $statusText = self::quiz_status_lang($q['status']);
                $window = self::format_quiz_window($q['timeopen'], $q['timeclose']);
                $timelimitHtml = !empty($q['timelimit'])
                    ? ' · <span class="ulms-quizmeta ulms-quizmeta--time">' . self::format_quiz_timelimit((int)$q['timelimit']) . '</span>'
                    : '';
                $attemptsHtml = !empty($q['attempts_max']) || $q['attempts_used'] > 0
                    ? ' · <span class="ulms-quizmeta ulms-quizmeta--attempts">' . get_string(
                        'studentquizattemptsused',
                        'local_ulms_dashboard',
                        (object)['used' => (int)$q['attempts_used'], 'max' => $q['attempts_max'] === '0' ? '∞' : (int)$q['attempts_max']]
                    ) . '</span>'
                    : '';
                $gradeHtml = ($q['status'] === 'graded' && $q['grade'] !== null)
                    ? ' · ' . get_string('quizgradeoutof', 'local_ulms_dashboard', (object)[
                        'grade' => (string)round((float)$q['grade'], 1),
                        'max' => (string)(int)($q['grade_max'] ?? 100),
                    ])
                    : '';
                $pillHtml = '<span class="ulms-quizstatus ulms-quizstatus--' . $statusClass . '">' . $statusText . '</span>';
                $subitems[] = [
                    'title' => format_string($q['name']),
                    'meta' => $window . $timelimitHtml . $attemptsHtml . ' · ' . $pillHtml . $gradeHtml,
                    'meta_raw' => true,
                    'url' => new \moodle_url('/mod/quiz/view.php', ['id' => (int)$q['cmid']]),
                ];
            }

            $coursecards[] = [
                'title' => $coursetitle,
                'meta' => $coursemeta,
                'url' => $courseurl,
                'badgehtml' => $badgehtml,
                'assignments' => $subitems, // reuse coursegroups renderer sublist (named assignments for legacy)
                'sublistempty' => get_string('studentquizzesnoquizcourse', 'local_ulms_dashboard'),
            ];
        }

        return [
            'summarycards' => [
                [
                    'label' => get_string('studentquiztotalcourses', 'local_ulms_dashboard'),
                    'value' => (string)count($coursecards),
                    'description' => get_string('studentquiztotalcoursesdesc', 'local_ulms_dashboard'),
                ],
                [
                    'label' => get_string('studentquizopen', 'local_ulms_dashboard'),
                    'value' => (string)$totalOpen,
                    'description' => get_string('studentquizopendesc', 'local_ulms_dashboard'),
                ],
                [
                    'label' => get_string('studentquizclosing7', 'local_ulms_dashboard'),
                    'value' => (string)$totalClosing7,
                    'description' => get_string('studentquizclosing7desc', 'local_ulms_dashboard'),
                ],
                [
                    'label' => get_string('studentquizcompleted', 'local_ulms_dashboard'),
                    'value' => (string)($totalCompleted + (int)$totalClosed),
                    'description' => get_string('studentquizcompleteddesc', 'local_ulms_dashboard'),
                ],
            ],
            'mainpanel' => [
                'title' => get_string('studentquizzestitle', 'local_ulms_dashboard'),
                'subtitle' => get_string('studentquizzesdescgrouped', 'local_ulms_dashboard'),
                'style' => 'coursegroups',
                'items' => $coursecards,
                'emptytitle' => get_string('studentquizzesemptycourses', 'local_ulms_dashboard'),
                'emptydesc' => get_string('studentquizzesemptycoursesdesc', 'local_ulms_dashboard'),
            ],
            'secondarypanels' => [],
        ];
    }

    /**
     * Fetches quizzes GROUPED by enrolled course — returns 1 entry per
     * enrolled courseid even if zero quizzes exist (guarantees every course
     * card renders, no empty page when lecturer hasn't published yet).
     *
     * Scope: strictly $courseids passed in (enrolment-scoped snapshot).
     *
     * Sync correctness:
     *   - Join {course_modules} cm.visible=1 → drafts hidden exactly as the
     *     lecturer published them (if they unpublish, it disappears from here
     *     instantly on next page load).
     *   - Join {quiz_attempts} on (quiz + userid) — counts used attempts &
     *     current state (inprogress / finished)
     *   - Join {quiz_grades} on (quiz + userid) — final grade row written by
     *     mod_quiz after attempt submission + grademethod evaluation; if this
     *     row exists, we show the scaled grade/max pill.
     *
     * @param int[] $courseids enrolled course set
     * @param int $userid student
     * @return array<int, array{course:object, quizzes:array, counts:array}>
     */
    private function get_quizzes_grouped_by_course(array $courseids, int $userid): array {
        global $DB;
        $out = [];
        if (empty($courseids)) return $out;
        $courseids = array_values(array_unique(array_map('intval', $courseids)));

        $courserecs = $DB->get_records_sql(
            "SELECT c.id, c.shortname, c.fullname, c.visible, c.category
               FROM {course} c
              WHERE c.id IN (" . implode(',', $courseids) . ") AND c.id > 1
              ORDER BY c.shortname ASC"
        );

        $programmeMeta = [];
        try {
            $progRows = $DB->get_records_sql(
                "SELECT pc.moodlecourseid, pc.coursetype, s.name AS semesterlabel
                   FROM {local_ulms_programme_courses} pc
              LEFT JOIN {local_ulms_semesters} s ON s.id = pc.semesterid
                  WHERE pc.moodlecourseid IN (" . implode(',', $courseids) . ")"
            );
            foreach ($progRows as $pr) {
                $programmeMeta[(int)$pr->moodlecourseid] = [
                    'coursetype' => !empty($pr->coursetype) ? ucfirst((string)$pr->coursetype) : 'Core',
                    'semesterlabel' => !empty($pr->semesterlabel) ? (string)$pr->semesterlabel : '',
                ];
            }
        } catch (\Throwable) {
            $programmeMeta = [];
        }

        foreach ($courserecs as $cr) {
            $cid = (int)$cr->id;
            if (isset($programmeMeta[$cid])) {
                $cr->coursetype = $programmeMeta[$cid]['coursetype'];
                if (!empty($programmeMeta[$cid]['semesterlabel'])) {
                    $cr->semesterlabel = $programmeMeta[$cid]['semesterlabel'];
                }
            }
            $out[$cid] = [
                'course' => $cr,
                'quizzes' => [],
                'counts' => [
                    'total' => 0, 'notyetopen' => 0, 'open' => 0,
                    'closing7' => 0, 'inprogress' => 0,
                    'completed' => 0, 'closed' => 0, 'graded' => 0,
                ],
            ];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $now = time();
        $DAY = 86400;
        $inparams['attemptuid'] = $userid;
        $inparams['gradeuid'] = $userid;

        $sql = "SELECT q.id,
                       q.course,
                       q.name,
                       q.timeopen,
                       q.timeclose,
                       q.timelimit,
                       q.attempts,
                       q.grademethod,
                       q.grade AS grade_max_raw,
                       cm.id AS cmid,
                       cm.visible AS cmvisible,
                       att.attempt_count AS attempts_used,
                       att.best_state AS best_state,
                       g.grade AS finalgrade
                  FROM {quiz} q
                  JOIN {modules} m ON m.name = 'quiz'
                  JOIN {course_modules} cm ON cm.instance = q.id AND cm.module = m.id AND cm.course = q.course
             LEFT JOIN (
                    SELECT quiz AS qid,
                           COUNT(*) AS attempt_count,
                           MAX(CASE WHEN state='inprogress' THEN 1
                                    WHEN state='finished' THEN 2
                                    WHEN state='abandoned' THEN 3
                                    ELSE 0 END) AS best_state,
                           MAX(attempt) AS maxattempt
                      FROM {quiz_attempts}
                     WHERE userid = :attemptuid AND preview = 0
                  GROUP BY quiz
             ) att ON att.qid = q.id
             LEFT JOIN {quiz_grades} g ON g.quiz = q.id AND g.userid = :gradeuid
                 WHERE q.course {$insql} AND cm.visible = 1
              ORDER BY q.course ASC, q.timeclose ASC, q.timeopen ASC, q.name ASC";

        $rows = $DB->get_records_sql($sql, $inparams);
        foreach ($rows as $r) {
            $cid = (int)$r->course;
            if (!isset($out[$cid])) continue;

            $timeopen = (int)$r->timeopen;
            $timeclose = (int)$r->timeclose;
            $attemptsUsed = (int)($r->attempts_used ?? 0);
            $attemptsMax = (string)($r->attempts ?? 0);
            $bestState = (int)($r->best_state ?? 0); // 0 none, 1 inprogress, 2 finished, 3 abandoned
            $gradeRaw = $r->finalgrade === null ? null : (float)$r->finalgrade;
            $gradeMax = (int)($r->grade_max_raw > 0 ? $r->grade_max_raw : 100);

            $hasAnyFinished = in_array($bestState, [2, 3], true) || $gradeRaw !== null;
            $hasInProgress = $bestState === 1;
            $windowNotOpen = $timeopen > 0 && $now < $timeopen;
            $windowOpenNow = (!$timeopen || $now >= $timeopen) && (!$timeclose || $now <= $timeclose);
            $windowClosed = $timeclose > 0 && $now > $timeclose;

            if ($gradeRaw !== null && $gradeRaw >= -0.0001) {
                $status = 'graded';
            } elseif ($hasInProgress) {
                $status = 'inprogress';
            } elseif ($windowNotOpen && $attemptsUsed === 0) {
                $status = 'notyetopen';
            } elseif ($windowClosed && $attemptsUsed === 0) {
                $status = 'closed';
            } elseif ($hasAnyFinished && $attemptsUsed > 0) {
                $status = 'completed';
            } elseif ($windowOpenNow && $attemptsUsed === 0) {
                $status = 'open';
            } elseif ($windowOpenNow) {
                // attempts > 0 but room for more if attemptsMax allows
                $status = 'open';
            } else {
                $status = 'closed';
            }

            $closing7 = $timeclose > 0
                && ($timeclose - $now) <= 7 * $DAY
                && ($timeclose - $now) > 0
                && $status !== 'graded'
                && $status !== 'completed'
                && $status !== 'closed';

            $qarr = [
                'id' => (int)$r->id,
                'cmid' => (int)$r->cmid,
                'name' => (string)$r->name,
                'timeopen' => $timeopen,
                'timeclose' => $timeclose,
                'timelimit' => (int)$r->timelimit,
                'attempts_used' => $attemptsUsed,
                'attempts_max' => $attemptsMax,
                'status' => $status,
                'grade' => $gradeRaw,
                'grade_max' => $gradeMax,
            ];
            $out[$cid]['quizzes'][] = $qarr;
            $out[$cid]['counts']['total']++;
            switch ($status) {
                case 'graded':    $out[$cid]['counts']['graded']++;    $out[$cid]['counts']['completed']++; break;
                case 'completed': $out[$cid]['counts']['completed']++; break;
                case 'inprogress':$out[$cid]['counts']['inprogress']++;break;
                case 'notyetopen':$out[$cid]['counts']['notyetopen']++;break;
                case 'open':      $out[$cid]['counts']['open']++;      break;
                case 'closed':    $out[$cid]['counts']['closed']++;    break;
            }
            if ($closing7) $out[$cid]['counts']['closing7']++;
        }
        return $out;
    }

    /**
     * Aggregate badge pills for a course header.
     *
     * Priority order (most urgent first):
     *   Closed (red) → In progress (amber) → Closing7d (violet) →
     *   Not yet open (slate) → Open (sky blue) → Completed/Graded (green)
     *   Fallback: 0 quizzes pill
     *
     * @param array{total:int, notyetopen:int, open:int, closing7:int, inprogress:int, completed:int, closed:int, graded:int} $c
     * @return string HTML
     */
    private static function render_course_quiz_badge(array $c): string {
        $total = (int)($c['total'] ?? 0);
        if ($total <= 0) {
            return '<span class="ulms-coursestat ulms-coursestat--zero">'
                . get_string('studentquizzero', 'local_ulms_dashboard')
                . '</span>';
        }
        $parts = [];
        if (!empty($c['closed'])) {
            $parts[] = '<span class="ulms-coursestat ulms-coursestat--overdue">'
                . get_string('studentquizclosedcount', 'local_ulms_dashboard', (int)$c['closed'])
                . '</span>';
        }
        if (!empty($c['inprogress'])) {
            $parts[] = '<span class="ulms-coursestat ulms-coursestat--duesoon">'
                . get_string('studentquizinprogresscount', 'local_ulms_dashboard', (int)$c['inprogress'])
                . '</span>';
        }
        if (!empty($c['closing7'])) {
            $parts[] = '<span class="ulms-coursestat ulms-coursestat--duesoon7">'
                . get_string('studentquizclosing7count', 'local_ulms_dashboard', (int)$c['closing7'])
                . '</span>';
        }
        if (!empty($c['notyetopen'])) {
            $parts[] = '<span class="ulms-coursestat ulms-coursestat--open">'
                . get_string('studentquiznotopencount', 'local_ulms_dashboard', (int)$c['notyetopen'])
                . '</span>';
        }
        if (!empty($c['open'])) {
            $parts[] = '<span class="ulms-coursestat ulms-coursestat--open">'
                . get_string('studentquizopencount', 'local_ulms_dashboard', (int)$c['open'])
                . '</span>';
        }
        $done = (int)($c['completed'] ?? 0) + (int)($c['graded'] ?? 0);
        if ($done > 0) {
            $parts[] = '<span class="ulms-coursestat ulms-coursestat--submitted">'
                . get_string('studentquizdonecount', 'local_ulms_dashboard', $done)
                . '</span>';
        }
        if (empty($parts)) {
            $parts[] = '<span class="ulms-coursestat ulms-coursestat--open">'
                . get_string('studentquiztotalcount', 'local_ulms_dashboard', $total)
                . '</span>';
        }
        return implode('', $parts);
    }

    /**
     * Quiz per-row status CSS modifier.
     *
     * @param string $status one of notyetopen/open/inprogress/completed/closed/graded
     * @return string
     */
    private static function quiz_status_css_class(string $status): string {
        return match ($status) {
            'notyetopen' => 'notyetopen',
            'open' => 'open',
            'inprogress' => 'inprogress',
            'completed' => 'completed',
            'closed' => 'closed',
            'graded' => 'graded',
            default => 'open',
        };
    }

    /**
     * Quiz per-row status human label.
     *
     * @param string $status
     * @return string
     */
    private static function quiz_status_lang(string $status): string {
        return match ($status) {
            'notyetopen' => get_string('studentquizstatusnotyetopen', 'local_ulms_dashboard'),
            'open' => get_string('studentquizstatusopen', 'local_ulms_dashboard'),
            'inprogress' => get_string('studentquizstatusinprogress', 'local_ulms_dashboard'),
            'completed' => get_string('studentquizstatuscompleted', 'local_ulms_dashboard'),
            'closed' => get_string('studentquizstatusclosed', 'local_ulms_dashboard'),
            'graded' => get_string('studentquizstatusgraded', 'local_ulms_dashboard'),
            default => get_string('studentquizstatusopen', 'local_ulms_dashboard'),
        };
    }

    /**
     * Formats the quiz access window "Open: X → Close: Y".
     *
     * If both timestamps are 0, returns "Always available".
     * If only open is set (no close): "Opens: X".
     * If only close is set (no open): "Closes: Y".
     * If both: "Open: X → Close: Y".
     *
     * @param int $open 0 = no start
     * @param int $close 0 = no end
     * @return string
     */
    private static function format_quiz_window(int $open, int $close): string {
        if (!$open && !$close) return get_string('studentquizalwaysopen', 'local_ulms_dashboard');
        if ($open && !$close) return get_string('studentquizopens', 'local_ulms_dashboard', userdate($open));
        if (!$open && $close) return get_string('studentquizcloses', 'local_ulms_dashboard', userdate($close));
        return get_string('studentquizwindow', 'local_ulms_dashboard', (object)[
            'open' => userdate($open),
            'close' => userdate($close),
        ]);
    }

    /**
     * Formats the timelimit seconds to a friendly pill.
     *
     * Examples: 1800 → "30 min", 3600 → "1 hour", 5400 → "1 hr 30 min", 300 → "5 min"
     *
     * @param int $seconds
     * @return string
     */
    private static function format_quiz_timelimit(int $seconds): string {
        $h = (int)floor($seconds / 3600);
        $m = (int)floor(($seconds % 3600) / 60);
        if ($h > 0 && $m > 0) {
            $hword = $h === 1 ? get_string('studentquizhr1') : get_string('studentquizhrn', $h);
            return get_string('studentquizhm', 'local_ulms_dashboard', (object)['h' => $hword, 'm' => $m]);
        }
        if ($h > 0) {
            return $h === 1 ? get_string('studentquizhr1') : get_string('studentquizhrn', $h);
        }
        return get_string('studentquizminutes', 'local_ulms_dashboard', $m);
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

        $courseids = array_values(array_map('intval', $snapshot['courseids'] ?? []));
        $userid = (int)($snapshot['userid'] ?? $USER->id);
        $grouped = $this->get_attendance_grouped_by_course($courseids, $userid);

        $coursecards = [];
        $totalMarkedAll = 0;
        $totalPresentAll = 0;
        $totalLateAll = 0;
        $totalAttendedAll = 0;
        $totalMissedAll = 0;

        foreach ($grouped as $courseid => $g) {
            $courserec = $g['course'];
            $sessions = $g['sessions'] ?? [];
            $counts = $g['counts'];
            $c_total = (int)$counts['total'];
            $c_present = (int)$counts['present'];
            $c_late = (int)$counts['late'];
            $c_absent = (int)$counts['absent'];
            $c_excused = (int)$counts['excused'];

            $totalMarkedAll += $c_total;
            $totalPresentAll += $c_present;
            $totalLateAll += $c_late;
            $totalAttendedAll += ($c_present + $c_late);
            $totalMissedAll += ($c_absent + $c_excused);

            $coursetitle = format_string((string)$courserec->fullname);
            $courseurl = new \moodle_url('/course/view.php', ['id' => (int)$courseid]);
            $coursemeta = trim(format_string((string)($courserec->shortname ?? ''))
                . (isset($courserec->coursetype) ? ' · ' . format_string((string)$courserec->coursetype) : '')
                . (isset($courserec->semesterlabel) ? ' · ' . format_string((string)$courserec->semesterlabel) : ''));

            $c_attended = $c_present + $c_late;
            $c_ratepct = $c_total > 0 ? (int)round(($c_attended / $c_total) * 100) : 0;
            $rateclass = $c_ratepct >= 80 ? 'rate' : ($c_ratepct >= 60 ? 'rate-mid' : 'rate-low');
            $barclass = $c_ratepct >= 80 ? 'present' : ($c_ratepct >= 60 ? 'mid' : 'low');

            $badgehtml = self::render_course_attendance_badge($counts);

            $subitems = [];
            foreach ($sessions as $s) {
                $statusClass = self::attendance_status_css_class($s['status']);
                $statusText = self::attendance_status_lang($s['status']);
                $dateHtml = !empty($s['date']) ? userdate((int)$s['date'], get_string('strftimedaydatetime')) : 'N/A';
                $sessionMeta = $dateHtml
                    . ' · <span class="ulms-attendancestatus ulms-attendancestatus--' . $statusClass . '">' . $statusText . '</span>';
                if (!empty($s['comment'])) {
                    $sessionMeta .= ' · <span class="ulms-attendancemeta">' . s($s['comment']) . '</span>';
                }
                $subitems[] = [
                    'title' => format_string($s['title']),
                    'meta' => $sessionMeta,
                    'meta_raw' => true,
                ];
            }

            $coursecards[] = [
                'title' => $coursetitle,
                'meta' => $coursemeta,
                'url' => $courseurl,
                'badgehtml' => $badgehtml,
                'assignments' => $subitems,
                'sublistempty' => self::safe_get_string('studentattendancenosessionscourse', 'No sessions have been marked for this course yet.'),
                'footer' => $c_total > 0
                    ? '<div style="margin-top:8px;padding:0 8px 8px;">'
                        . '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">'
                        . '<span class="ulms-attendancemeta ulms-attendancemeta--' . $rateclass . '">' . s($c_ratepct) . '%</span>'
                        . '<span class="ulms-attendancemeta">' . $c_total . ' ' . self::safe_get_string('studentattendancetotalcount', 'Total: {$a}', $c_total) . '</span>'
                        . '</div>'
                        . '<div style="margin-top:8px;" class="ulms-attendance-track">'
                        . '<div class="ulms-attendance-fill ulms-attendance-bar--' . $barclass . '" style="width:' . s($c_ratepct) . '%;"></div>'
                        . '</div>'
                        . '</div>'
                    : '',
            ];
        }

        $overallPct = $totalMarkedAll > 0
            ? number_format(($totalAttendedAll / $totalMarkedAll) * 100, 1)
            : '0.0';

        return [
            'summarycards' => [
                [
                    'label' => self::safe_get_string('studentattendancetotalcourses', 'Enrolled courses'),
                    'value' => (string)count($coursecards),
                    'description' => self::safe_get_string('studentattendancetotalcoursesdesc', 'Every course you are actively taking appears here even if no attendance has been marked yet.'),
                ],
                [
                    'label' => self::safe_get_string('studentattendanceoverall', 'Overall attendance'),
                    'value' => s($overallPct) . '%',
                    'description' => self::safe_get_string('studentattendanceoveralldesc', 'Present + Late divided by all marked sessions across every enrolled course.'),
                ],
                [
                    'label' => self::safe_get_string('studentattendancepresent', 'Attended sessions'),
                    'value' => (string)$totalAttendedAll,
                    'description' => self::safe_get_string('studentattendancepresentdesc', 'Sessions marked Present or Late. Late sessions still count toward the official attendance rate.'),
                ],
                [
                    'label' => self::safe_get_string('studentattendancemissed', 'Missed sessions'),
                    'value' => (string)$totalMissedAll,
                    'description' => self::safe_get_string('studentattendancemisseddesc', 'Sessions marked Absent or Excused. Contact your lecturer about excused absences.'),
                ],
            ],
            'mainpanel' => [
                'title' => self::safe_get_string('studentattendancetitle', 'My Attendance Record'),
                'subtitle' => self::safe_get_string('studentattendancedescgrouped', 'Attendance by enrolled course, with per-session status, comment history and rate progress.'),
                'style' => 'coursegroups',
                'items' => $coursecards,
                'emptytitle' => self::safe_get_string('studentattendanceemptycourses', 'No attendance records yet'),
                'emptydesc' => self::safe_get_string('studentattendanceemptycoursesdesc', 'Attendance will appear here once your lecturer marks sessions for your enrolled courses.'),
            ],
            'secondarypanels' => [],
        ];
    }

    /**
     * Fetches attendance records GROUPED by enrolled course.
     *
     * Scope: only course ids in $courseids are returned (guaranteed enrolment
     * scoped by caller). Courses with 0 attendance records are still returned
     * so they render on the page (user sees their full academic scope).
     *
     * @param int[] $courseids enrolled set
     * @param int $userid student
     * @return array<int, array{course:object, sessions:array<int, array>, counts:array{total:int, present:int, late:int, absent:int, excused:int}>
     */
    private function get_attendance_grouped_by_course(array $courseids, int $userid): array {
        global $DB;
        $out = [];
        if (empty($courseids)) {
            return $out;
        }
        $courseids = array_values(array_unique(array_map('intval', $courseids)));

        $courserecs = $DB->get_records_sql(
            "SELECT c.id, c.shortname, c.fullname, c.visible, c.category
               FROM {course} c
              WHERE c.id IN (" . implode(',', $courseids) . ") AND c.id > 1
              ORDER BY c.shortname ASC"
        );

        $programmeMeta = [];
        try {
            $progRows = $DB->get_records_sql(
                "SELECT pc.moodlecourseid, pc.coursetype, s.name AS semesterlabel
                   FROM {local_ulms_programme_courses} pc
              LEFT JOIN {local_ulms_semesters} s ON s.id = pc.semesterid
                  WHERE pc.moodlecourseid IN (" . implode(',', $courseids) . ")"
            );
            foreach ($progRows as $pr) {
                $programmeMeta[(int)$pr->moodlecourseid] = [
                    'coursetype' => !empty($pr->coursetype) ? ucfirst((string)$pr->coursetype) : 'Core',
                    'semesterlabel' => !empty($pr->semesterlabel) ? (string)$pr->semesterlabel : '',
                ];
            }
        } catch (\Throwable) {
            $programmeMeta = [];
        }

        foreach ($courserecs as $cr) {
            $cid = (int)$cr->id;
            if (isset($programmeMeta[$cid])) {
                $cr->coursetype = $programmeMeta[$cid]['coursetype'];
                if (!empty($programmeMeta[$cid]['semesterlabel'])) {
                    $cr->semesterlabel = $programmeMeta[$cid]['semesterlabel'];
                }
            }
            $out[$cid] = [
                'course' => $cr,
                'sessions' => [],
                'counts' => [
                    'total' => 0,
                    'present' => 0,
                    'late' => 0,
                    'absent' => 0,
                    'excused' => 0,
                ],
            ];
        }

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $params['userid'] = $userid;
        $sql = "SELECT a.id,
                       a.sessionid,
                       a.session_occurrence_date,
                       a.status,
                       a.comment,
                       s.moodlecourseid,
                       s.title,
                       s.delivery_mode
                  FROM {" . schedule_service::ATTENDANCE_TABLE . "} a
                  JOIN {" . schedule_service::SESSION_TABLE . "} s ON s.id = a.sessionid
                 WHERE a.userid = :userid
                   AND s.moodlecourseid $insql
                 ORDER BY a.session_occurrence_date DESC, a.id DESC";
        try {
            $records = $DB->get_records_sql($sql, $params);
        } catch (\Throwable) {
            $records = [];
        }

        foreach ($records as $r) {
            $cid = (int)$r->moodlecourseid;
            if (!isset($out[$cid])) {
                continue;
            }
            $status = (string)($r->status ?? 'present');
            if (!in_array($status, ['present', 'late', 'absent', 'excused'], true)) {
                $status = 'present';
            }
            $out[$cid]['counts']['total']++;
            if (isset($out[$cid]['counts'][$status])) {
                $out[$cid]['counts'][$status]++;
            }
            $out[$cid]['sessions'][] = [
                'date' => (int)($r->session_occurrence_date ?? 0),
                'status' => $status,
                'title' => !empty($r->title) ? format_string((string)$r->title) : self::safe_get_string('attendance.status.' . $status, 'Attendance session'),
                'delivery' => (string)($r->delivery_mode ?? ''),
                'comment' => !empty($r->comment) ? (string)$r->comment : '',
            ];
        }

        return $out;
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
                if (optional_param('sessionid', 0, PARAM_INT) > 0) {
                    $payload['id'] = (int)optional_param('sessionid', 0, PARAM_INT);
                }
                $result = $service->save_session($payload, (int)$USER->id);
                if (!empty($result['success'])) {
                    \core\notification::add('Session scheduled successfully.', \core\output\notification::NOTIFY_SUCCESS);
                } else {
                    $errmsg = 'Failed to schedule session.';
                    if (!empty($result['friendly_errors'])) {
                        $errmsg .= ' ' . implode(' ', $result['friendly_errors']);
                    } elseif (!empty($result['errors'])) {
                        $errmsg .= ' Fields: ' . implode(', ', array_keys($result['errors']));
                    }
                    \core\notification::add($errmsg, \core\output\notification::NOTIFY_ERROR);
                }
                redirect(new \moodle_url($this->get_routing_service()->get_url_for_route('lecturer.schedule')));
            }
            if ($action === 'delete_session') {
                $sessionid = (int)optional_param('sessionid', 0, PARAM_INT);
                if ($sessionid > 0 && $service->delete_session($sessionid, (int)$USER->id)) {
                    \core\notification::add('Session deleted.', \core\output\notification::NOTIFY_SUCCESS);
                } else {
                    \core\notification::add('Could not delete session (permission denied or not found).', \core\output\notification::NOTIFY_ERROR);
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

        $editingid = (int)optional_param('edit', 0, PARAM_INT);
        $editingsession = null;
        if ($editingid > 0) {
            try {
                $editingsession = (array)$DB->get_record('local_ulms_timetable_sessions', ['id' => $editingid], '*', IGNORE_MISSING);
                if ($editingsession && (int)($editingsession['lecturer_userid'] ?? 0) !== (int)$USER->id && !is_siteadmin()) {
                    $editingsession = null;
                }
            } catch (\Throwable $_e) {
                $editingsession = null;
            }
        }

        $cascade = $service->get_cascade_for_lecturer_schedule(
            (int)$USER->id,
            (int)($editingsession['facultyid'] ?? 0),
            (int)($editingsession['departmentid'] ?? 0),
            (int)($editingsession['programmeid'] ?? 0),
            (int)($editingsession['levelid'] ?? 0),
            (int)($editingsession['sessionid'] ?? 0),
            (int)($editingsession['semesterid'] ?? 0)
        );
        $cascade_json = json_encode($cascade, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $html = '';

        if ($conflictcount > 0) {
            $cdesc = [];
            foreach (array_slice($conflicts, 0, 5, true) as $cf) {
                $cdesc[] = sprintf('Weekday %d %02d:%02d slot: Lec %d / %d or Room %s / %s overlap',
                    (int)($cf['weekday'] ?? 0),
                    (int)(intdiv((int)($cf['start_minutes'] ?? 0), 60)),
                    (int)((int)($cf['start_minutes'] ?? 0) % 60),
                    (int)($cf['lec_a'] ?? 0),
                    (int)($cf['lec_b'] ?? 0),
                    trim((string)($cf['loc_a'] ?? '')),
                    trim((string)($cf['loc_b'] ?? '')));
            }
            $html .= '<div style="padding:14px 18px;border:1px solid #f5c2c7;border-radius:10px;background:#fff5f5;color:#842029;margin-bottom:20px;font-weight:600;">'
                . '⚠ Schedule conflicts this week: <strong>' . $conflictcount . '</strong>. '
                . '<div style="margin-top:8px;font-weight:500;font-size:13px;line-height:1.5;">' . implode('<br>', $cdesc) . '</div>'
                . '</div>';
        }

        $faculties = [];
        $departments = [];
        $programmes = [];
        $levels = [];
        $sessions = [];
        $semesters = [];
        foreach ($cascade['faculties'] as $row) { $faculties[(int)$row['id']] = $row['name']; }
        foreach ($cascade['departments'] as $row) { $departments[(int)$row['id']] = $row['name']; }
        foreach ($cascade['programmes'] as $row) { $programmes[(int)$row['id']] = $row['name']; }
        foreach ($cascade['levels'] as $row) { $levels[(int)$row['id']] = $row['name']; }
        foreach ($cascade['sessions'] as $row) { $sessions[(int)$row['id']] = $row['name']; }
        foreach ($cascade['semesters'] as $row) { $semesters[(int)$row['id']] = $row['name']; }
        $mycourses = [];
        foreach ($cascade['courses'] as $row) { $mycourses[(int)$row['id']] = $row['name']; }

        $html .= '<h3 style="margin:0 0 12px;font-size:1rem;font-weight:700;color:#0f4c81;">Schedule New Session</h3>';
        $html .= '<form method="post" data-ulms-schedule-form="1" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;padding:16px;border:1px solid #e1e7ee;border-radius:12px;background:#f8fbff;margin-bottom:24px;">';
        $html .= '<input type="hidden" name="sesskey" value="' . s(sesskey()) . '">';
        $html .= '<input type="hidden" name="action" value="save_session">';
        $html .= '<input type="hidden" name="lecturer_userid" value="' . s((int)$USER->id) . '">';
        if ($editingid > 0 && $editingsession) {
            $html .= '<input type="hidden" name="sessionid" value="' . s((int)$editingid) . '">';
        }

        $selval = function(string $name, $def = '') use ($editingsession) {
            $sv = is_array($editingsession) && isset($editingsession[$name]) ? (string)$editingsession[$name] : (string)$def;
            return $sv;
        };
        $selint = function(string $name, int $def = 0) use ($editingsession) {
            return (int)(is_array($editingsession) && isset($editingsession[$name]) ? $editingsession[$name] : $def);
        };

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Faculty</label><select name="facultyid" data-cascade="facultyid" class="form-control custom-select" required>';
        $html .= '<option value="">-- Select Faculty --</option>';
        foreach ($faculties as $fid => $fname) {
            $sel = $selint('facultyid') === (int)$fid ? ' selected' : '';
            $html .= '<option value="' . s($fid) . '"' . $sel . '>' . format_string($fname) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Department</label><select name="departmentid" data-cascade="departmentid" data-parent="facultyid" data-parent-key="facultyid" class="form-control custom-select" required>';
        $html .= '<option value="">-- Select Department --</option>';
        foreach ($departments as $did => $dname) {
            $sel = $selint('departmentid') === (int)$did ? ' selected' : '';
            $html .= '<option value="' . s($did) . '"' . $sel . '>' . format_string($dname) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Programme</label><select name="programmeid" data-cascade="programmeid" data-parent="departmentid" data-parent-key="departmentid" class="form-control custom-select" required>';
        $html .= '<option value="">-- Select Programme --</option>';
        foreach ($programmes as $pid => $pname) {
            $sel = $selint('programmeid') === (int)$pid ? ' selected' : '';
            $html .= '<option value="' . s($pid) . '"' . $sel . '>' . format_string($pname) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Academic Session</label><select name="sessionid" data-cascade="sessionid" class="form-control custom-select" required>';
        $html .= '<option value="">-- Select Session --</option>';
        foreach ($sessions as $sid => $sname) {
            $sel = $selint('sessionid') === (int)$sid ? ' selected' : '';
            $html .= '<option value="' . s($sid) . '"' . $sel . '>' . format_string($sname) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Semester</label><select name="semesterid" data-cascade="semesterid" data-parent="sessionid" data-parent-key="sessionid" class="form-control custom-select" required>';
        $html .= '<option value="">-- Select Semester --</option>';
        foreach ($semesters as $smid => $smname) {
            $sel = $selint('semesterid') === (int)$smid ? ' selected' : '';
            $html .= '<option value="' . s($smid) . '"' . $sel . '>' . format_string($smname) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Level</label><select name="levelid" data-cascade="levelid" class="form-control custom-select" required>';
        $html .= '<option value="">-- Select Level --</option>';
        foreach ($levels as $lid => $lname) {
            $sel = $selint('levelid') === (int)$lid ? ' selected' : '';
            $html .= '<option value="' . s($lid) . '"' . $sel . '>' . format_string($lname) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field" style="grid-column:span 1;"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Course</label><select name="moodlecourseid" data-cascade="moodlecourseid" data-depends="programmeid,levelid,sessionid,semesterid" class="form-control custom-select" required>';
        $html .= '<option value="">-- Select Course --</option>';
        foreach ($mycourses as $cid => $cname) {
            $sel = $selint('moodlecourseid') === (int)$cid ? ' selected' : '';
            $html .= '<option value="' . s($cid) . '"' . $sel . '>' . $cname . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field" style="grid-column:span 1;"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Session Title</label><input type="text" name="title" class="form-control" required placeholder="e.g. Introduction to Programming" value="' . s($selval('title', '')) . '"></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Delivery Mode</label><select name="delivery_mode" class="form-control custom-select">';
        foreach ($service::DELIVERY_MODES as $dm) {
            $sel = $selval('delivery_mode', 'lecture') === (string)$dm ? ' selected' : '';
            $html .= '<option value="' . s($dm) . '"' . $sel . '>' . s(ucwords(str_replace('_', ' ', $dm))) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Weekday</label><select name="weekday" class="form-control custom-select">';
        $wdnames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday'];
        foreach ($wdnames as $wdi => $wdn) {
            $sel = $selint('weekday', 1) === (int)$wdi ? ' selected' : '';
            $html .= '<option value="' . s($wdi) . '"' . $sel . '>' . s($wdn) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Start Time</label><select name="start_minutes" class="form-control custom-select">';
        for ($tm = 480; $tm <= 1110; $tm += 30) {
            $h = intdiv($tm, 60);
            $m = $tm % 60;
            $sel = $selint('start_minutes', 480) === (int)$tm ? ' selected' : '';
            $html .= '<option value="' . s($tm) . '"' . $sel . '>' . s(sprintf('%02d:%02d', $h, $m)) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Duration (minutes)</label><select name="duration_minutes" class="form-control custom-select">';
        foreach ([30, 45, 60, 90, 120] as $d) {
            $sel = $selint('duration_minutes', 60) === (int)$d ? ' selected' : '';
            $html .= '<option value="' . s($d) . '"' . $sel . '>' . s($d) . ' min</option>';
        }
        $html .= '</select></div>';

        $ts_start_def = $selint('term_start_date', 0);
        $ts_end_def = $selint('term_end_date', 0);
        $ts_start_val = $ts_start_def > 0 ? date('Y-m-d', $ts_start_def) : date('Y-m-d', $monday_ts);
        $ts_end_val = $ts_end_def > 0 ? date('Y-m-d', $ts_end_def) : date('Y-m-d', $monday_ts + (12 * 7 * 86400));

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Term Start Date</label><input type="date" name="term_start_date" class="form-control" value="' . s($ts_start_val) . '"></div>';
        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Term End Date</label><input type="date" name="term_end_date" class="form-control" value="' . s($ts_end_val) . '"></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Location Mode</label><select name="location_mode" data-toggle-location-mode="1" class="form-control custom-select">';
        foreach ($service::LOCATION_MODES as $lm) {
            $sel = $selval('location_mode', 'physical') === (string)$lm ? ' selected' : '';
            $html .= '<option value="' . s($lm) . '"' . $sel . '>' . s(ucfirst($lm)) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Location / Room</label><input type="text" name="location_label" class="form-control" placeholder="Room 201 / Zoom link ID" value="' . s($selval('location_label', '')) . '"></div>';

        $def_provider = $selval('provider_key', 'bigbluebutton');
        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Online Provider</label><select name="provider_key" data-online-provider="1" class="form-control custom-select">';
        foreach ($service::PROVIDERS as $pk) {
            $lbl = str_replace('_', ' ', $pk);
            $sel = $def_provider === (string)$pk ? ' selected' : '';
            $html .= '<option value="' . s($pk) . '"' . $sel . '>' . s(ucwords($lbl)) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Recurrence</label><select name="recurrence" class="form-control custom-select">';
        $def_rec = $selval('recurrence', 'weekly');
        $options = [
            'weekly' => 'Weekly',
            'once' => 'Once-off',
            'fortnightly' => 'Fortnightly',
        ];
        foreach ($options as $rv => $rl) {
            $db_rec = $def_rec === 'once_off' ? 'once' : $def_rec;
            $sel = $db_rec === (string)$rv ? ' selected' : '';
            $html .= '<option value="' . s($rv) . '"' . $sel . '>' . s($rl) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Status</label><select name="status" class="form-control custom-select">';
        foreach ($service::STATUSES as $st) {
            $sel = $selval('status', 'scheduled') === (string)$st ? ' selected' : '';
            $html .= '<option value="' . s($st) . '"' . $sel . '>' . s(ucfirst($st)) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="ulms-form-field" style="grid-column:span 2;"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Public Notes (visible to students)</label><textarea name="notes_public" rows="2" class="form-control" placeholder="Pre-reading, preparation notes...">' . s($selval('notes_public', '')) . '</textarea></div>';
        $html .= '<div class="ulms-form-field" style="grid-column:span 2;"><label style="display:block;font-weight:600;margin-bottom:4px;color:#1d2733;">Private Notes (lecturer only)</label><textarea name="notes_private" rows="2" class="form-control" placeholder="Internal reminders, seating plan...">' . s($selval('notes_private', '')) . '</textarea></div>';

        $html .= '<div style="grid-column:1 / -1;display:flex;justify-content:space-between;gap:12px;align-items:center;">';
        $html .= '<div style="font-size:12px;color:#5c6f82;font-weight:500;">💡 Pick Faculty → Department → Programme + Session/Semester/Level first — Courses list will auto-filter.</div>';
        $html .= '<div style="display:flex;gap:8px;"><a href="' . s((new \moodle_url($this->get_routing_service()->get_url_for_route('lecturer.schedule')))->out(false)) . '" class="ulms-btn" style="min-width:44px;min-height:44px;display:inline-flex;align-items:center;justify-content:center;">Clear / New</a>';
        $html .= '<button type="submit" class="ulms-btn ulms-btn--primary" style="min-width:44px;min-height:44px;padding:10px 20px;font-weight:600;">' . ($editingid > 0 && $editingsession ? 'Save Changes' : 'Schedule Session') . '</button>';
        $html .= '</div></div>';
        $html .= '</form>';

        $html .= '<script data-ulms-schedule-cascade="1" nonce="' . s(random_bytes(8) ? bin2hex(random_bytes(4)) : '') . '">'
            . '(function(){'
            . ' var CASCADE=' . $cascade_json . ';'
            . ' var form=document.querySelector("[data-ulms-schedule-form]"); if(!form) return;'
            . ' function rebuildSelect(sel,list,label,current){'
            . '   sel.innerHTML=\'<option value="">-- \'+label+\' --</option>\';'
            . '   list.forEach(function(r){'
            . '     var o=document.createElement("option");o.value=String(r.id);o.textContent=r.name;'
            . '     if(String(r.id)===String(current)) o.selected=true;'
            . '     sel.appendChild(o);'
            . '   });'
            . ' }'
            . ' var currentValues={};'
            . ' form.querySelectorAll("select[data-cascade]").forEach(function(s){currentValues[s.name]=s.value;});'
            . ' function recomputeDependencies(){'
            . '   var fid=parseInt(form.querySelector("[name=facultyid]").value||0,10);'
            . '   var did=parseInt(form.querySelector("[name=departmentid]").value||0,10);'
            . '   var prid=parseInt(form.querySelector("[name=programmeid]").value||0,10);'
            . '   var sid=parseInt(form.querySelector("[name=sessionid]").value||0,10);'
            . '   var smid=parseInt(form.querySelector("[name=semesterid]").value||0,10);'
            . '   var lid=parseInt(form.querySelector("[name=levelid]").value||0,10);'
            . '   var depts=[];var progs=[];var sems=[];var courses=[];'
            . '   CASCADE.departments.forEach(function(r){ if(fid<=0 || (r.facultyid && r.facultyid===fid)) depts.push(r); });'
            . '   rebuildSelect(form.querySelector("[name=departmentid]"), depts, "Select Department", currentValues.departmentid);'
            . '   CASCADE.programmes.forEach(function(r){ if((did<=0 && fid<=0) || (did>0 && r.departmentid===did) || (fid>0 && (depts.findIndex(function(d){return d.id===r.departmentid;})>=0))) progs.push(r); });'
            . '   rebuildSelect(form.querySelector("[name=programmeid]"), progs, "Select Programme", currentValues.programmeid);'
            . '   CASCADE.semesters.forEach(function(r){ if(sid<=0 || !r.sessionid || r.sessionid===sid) sems.push(r); });'
            . '   rebuildSelect(form.querySelector("[name=semesterid]"), sems, "Select Semester", currentValues.semesterid);'
            . '   CASCADE.courses.forEach(function(r){'
            . '     var match=true;'
            . '     if(prid>0 && !r.programmeid){return;}'
            . '     courses.push(r);'
            . '   });'
            . '   rebuildSelect(form.querySelector("[name=moodlecourseid]"), CASCADE.courses, "Select Course", currentValues.moodlecourseid);'
            . ' }'
            . ' form.querySelectorAll("select[data-cascade]").forEach(function(s){'
            . '   s.addEventListener("change", function(){ currentValues[s.name]=s.value; recomputeDependencies(); });'
            . ' });'
            . ' var locmode=form.querySelector("[data-toggle-location-mode]");'
            . ' var providerSel=form.querySelector("[data-online-provider]");'
            . ' function updateProviderState(){'
            . '   if(!providerSel) return;'
            . '   var isOnline=(locmode && locmode.value==="online");'
            . '   providerSel.disabled=!isOnline;'
            . '   providerSel.style.opacity = isOnline ? "1" : "0.45";'
            . '   providerSel.title = isOnline ? "" : "Pick Location Mode = Online first.";'
            . ' }'
            . ' if(locmode){ locmode.addEventListener("change",updateProviderState); }'
            . ' recomputeDependencies(); updateProviderState();'
            . '})();</script>';

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

        for ($hr = 8; $hr <= 18; $hr++) {
            $slotstart = $hr * 60;
            $slotend = $slotstart + 60;
            $time_label = sprintf('%02d:00–%02d:00', $hr, $hr + 1);
            $html .= '<tr>';
            $html .= '<td data-label="Time" style="font-weight:600;color:#0f4c81;">' . s($time_label) . '</td>';
            for ($wd = 1; $wd <= 5; $wd++) {
                $html .= '<td data-label="' . s(['Mon','Tue','Wed','Thu','Fri'][$wd - 1]) . '">';
                foreach ($cells as $c) {
                    $cwd = (int)($c['weekday'] ?? 0);
                    $cstart = (int)($c['start_minutes'] ?? 0);
                    $cend = $cstart + (int)($c['duration_minutes'] ?? 0);
                    $overlap = ($cwd === $wd) && ($cstart < $slotend) && ($cend > $slotstart);
                    $beginin = $overlap && ($cstart >= $slotstart) && ($cstart < $slotend);
                    if (!$overlap || !$beginin) {
                        continue;
                    }
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
                    $editurl = new \moodle_url($this->get_routing_service()->get_url_for_route('lecturer.schedule'), ['edit' => (int)($c['id'] ?? 0)]);
                    $html .= '<a href="' . s($editurl->out(false)) . '" class="ulms-btn" style="min-width:44px;min-height:44px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;padding:6px 10px;">Edit</a>';
                    $html .= '<form method="post" style="display:inline-flex;margin:0;padding:0;">'
                        . '<input type="hidden" name="sesskey" value="' . s(sesskey()) . '">'
                        . '<input type="hidden" name="action" value="delete_session">'
                        . '<input type="hidden" name="sessionid" value="' . s((int)($c['id'] ?? 0)) . '">'
                        . '<button type="submit" class="ulms-btn ulms-btn--danger" style="min-width:44px;min-height:44px;padding:6px 10px;font-size:12px;background:#fff1f2;border:1px solid #fecdd3;color:#9f1239;font-weight:600;" onclick="return confirm(\'Delete this session for the whole term?\');">Delete</button>'
                        . '</form>';
                    if ($locmode === 'online') {
                        $join = $service->resolve_join_url((int)$c['id'], 'lecturer');
                        if (!empty($join['url'])) {
                            $html .= '<a href="' . s($join['url']) . '" target="' . s($join['target']) . '" rel="' . s($join['rel']) . '" class="ulms-btn ulms-btn--primary" style="min-width:44px;min-height:44px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;padding:6px 10px;">Join Class Now</a>';
                        }
                    }
                    $html .= '</div></div>';
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
    private function build_lecturer_attendance_register_data(array $snapshot): array {
        global $DB, $USER;

        $service = schedule_service::instance();
        $courseids = array_values(array_map('intval', $snapshot['courseids'] ?? []));
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
                $comment_raw = (string)optional_param('comment', '', PARAM_TEXT);
                $comment = $comment_raw !== '' ? $comment_raw : null;
                $r = $service->mark_attendance($sid, $od, $uid, $st, (int)$USER->id, $comment);
                if (!empty($r['success'])) {
                    \core\notification::add(self::safe_get_string('lecturerattendancemarksuccess', 'Attendance mark saved successfully.'), \core\output\notification::NOTIFY_SUCCESS);
                } else {
                    $msg = self::safe_get_string('lecturerattendancemarkfail', 'Failed to save attendance mark. {$a}', s($r['message'] ?? 'error'));
                    \core\notification::add($msg, \core\output\notification::NOTIFY_ERROR);
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

        $coursesmeta = [];
        if (!empty($courseids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'mc');
            $crs = $DB->get_records_sql("SELECT c.id, c.fullname, c.shortname FROM {course} c WHERE c.id $insql ORDER BY c.shortname ASC", $inparams);
            foreach ($crs as $c) {
                $coursesmeta[(int)$c->id] = $c;
            }
        }

        $programmeMeta = [];
        try {
            if (!empty($courseids)) {
                $progRows = $DB->get_records_sql(
                    "SELECT pc.moodlecourseid, pc.coursetype, s.name AS semesterlabel
                       FROM {local_ulms_programme_courses} pc
                  LEFT JOIN {local_ulms_semesters} s ON s.id = pc.semesterid
                      WHERE pc.moodlecourseid IN (" . implode(',', array_map('intval', $courseids)) . ")"
                );
                foreach ($progRows as $pr) {
                    $programmeMeta[(int)$pr->moodlecourseid] = [
                        'coursetype' => !empty($pr->coursetype) ? ucfirst((string)$pr->coursetype) : 'Core',
                        'semesterlabel' => !empty($pr->semesterlabel) ? (string)$pr->semesterlabel : '',
                    ];
                }
            }
        } catch (\Throwable) {
            $programmeMeta = [];
        }

        $sessionsbycoursecounts = [];
        $sessionrowspercourse = [];
        if (!empty($courseids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'mc');
            $allsess = $DB->get_records_select('local_ulms_dashboard_session', "status <> 'cancelled' AND moodlecourseid " . $insql, $inparams, 'moodlecourseid ASC, title ASC', 'id, moodlecourseid, title, term_start_date, term_end_date, weekday, start_minutes, duration_minutes, lecturer_userid');
            foreach ($allsess as $sr) {
                $cid = (int)$sr->moodlecourseid;
                if (!isset($sessionsbycoursecounts[$cid])) $sessionsbycoursecounts[$cid] = 0;
                $sessionsbycoursecounts[$cid]++;
                $sessionrowspercourse[$cid][] = $sr;
            }
        }

        $attendanceaggpercourses = [];
        $totalsessionsmarked = 0;
        $global_present = 0;
        $global_total = 0;
        $atriskstudents = [];
        if (!empty($courseids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'mc');
            $sql = "SELECT a.sessionid, a.session_occurrence_date, a.userid, a.status, s.moodlecourseid
                      FROM {" . schedule_service::ATTENDANCE_TABLE . "} a
                      JOIN {" . schedule_service::SESSION_TABLE . "} s ON s.id = a.sessionid
                     WHERE s.status <> 'cancelled' AND s.moodlecourseid $insql";
            try {
                $markrows = $DB->get_records_sql($sql, $inparams);
            } catch (\Throwable) {
                $markrows = [];
            }
            $perStudentRates = [];
            foreach ($markrows as $mr) {
                $cid = (int)$mr->moodlecourseid;
                $sid = (int)$mr->sessionid;
                $od = (int)$mr->session_occurrence_date;
                $coursekey = $cid;
                if (!isset($attendanceaggpercourses[$coursekey])) {
                    $attendanceaggpercourses[$coursekey] = ['total' => 0, 'present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0, 'sessionsmarked' => []];
                }
                $sokey = $sid . '|' . $od;
                $attendanceaggpercourses[$coursekey]['sessionsmarked'][$sokey] = true;
                $attendanceaggpercourses[$coursekey]['total']++;
                $global_total++;
                $st = (string)($mr->status ?? 'present');
                if (in_array($st, ['present', 'late', 'absent', 'excused'], true)) {
                    $attendanceaggpercourses[$coursekey][$st]++;
                }
                if ($st === 'present' || $st === 'late') {
                    $global_present++;
                }
                $uid = (int)$mr->userid;
                if (!isset($perStudentRates[$cid][$uid])) {
                    $perStudentRates[$cid][$uid] = ['attended' => 0, 'total' => 0];
                }
                $perStudentRates[$cid][$uid]['total']++;
                if ($st === 'present' || $st === 'late') {
                    $perStudentRates[$cid][$uid]['attended']++;
                }
            }
            foreach ($attendanceaggpercourses as $cid => $agg) {
                $totalsessionsmarked += count($agg['sessionsmarked']);
            }
            foreach ($perStudentRates as $cid => $studentsmap) {
                foreach ($studentsmap as $uid => $sv) {
                    if ($sv['total'] <= 0) continue;
                    $rate = (int)round(($sv['attended'] / $sv['total']) * 100);
                    if ($rate < 80) {
                        $atriskstudents[$cid][$uid] = $rate;
                    }
                }
            }
        }

        $totalcourses = count($courseids);
        $overallpct = $global_total > 0 ? number_format(($global_present / $global_total) * 100, 1) : '0.0';
        $atriskcount = 0;
        foreach ($atriskstudents as $cidmap) {
            $atriskcount += count($cidmap);
        }

        $coursecards = [];
        foreach ($courseids as $courseid) {
            if (!isset($coursesmeta[$courseid])) continue;
            $cr = $coursesmeta[$courseid];
            $coursetitle = format_string((string)$cr->fullname);
            $courseurl = new \moodle_url('/course/view.php', ['id' => (int)$courseid]);
            $coursemeta = trim(format_string((string)($cr->shortname ?? ''))
                . (isset($programmeMeta[$courseid]['coursetype']) ? ' · ' . format_string((string)$programmeMeta[$courseid]['coursetype']) : '')
                . (isset($programmeMeta[$courseid]['semesterlabel']) ? ' · ' . format_string((string)$programmeMeta[$courseid]['semesterlabel']) : ''));

            $sessionscount = (int)($sessionsbycoursecounts[$courseid] ?? 0);
            $agg = $attendanceaggpercourses[$courseid] ?? ['total' => 0, 'present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];
            $badgehtml = self::render_course_attendance_badge($agg);

            $c_attended = (int)$agg['present'] + (int)$agg['late'];
            $c_ratepct = (int)$agg['total'] > 0 ? (int)round(($c_attended / (int)$agg['total']) * 100) : 0;
            $rateclass = $c_ratepct >= 80 ? 'rate' : ($c_ratepct >= 60 ? 'rate-mid' : 'rate-low');
            $barclass = $c_ratepct >= 80 ? 'present' : ($c_ratepct >= 60 ? 'mid' : 'low');

            $subitems = [];
            if (empty($sessionrowspercourse[$courseid])) {
                $subitems[] = ['title' => self::safe_get_string('lecturerattendancenosessionscourse', 'No sessions have been scheduled for this course yet.'), 'meta' => ''];
            } else {
                $attendanceurlbase = $this->get_routing_service()->get_url_for_route('lecturer.attendance');
                foreach ($sessionrowspercourse[$courseid] as $sr) {
                    $termstart = (int)($sr->term_start_date ?? 0);
                    $termend = (int)($sr->term_end_date ?? 0);
                    $weekday = (int)($sr->weekday ?? -1);
                    $next_occ = 0;
                    if ($termstart > 0) {
                        if ($weekday >= 1 && $weekday <= 7 && $termend >= $termstart) {
                            $cursor = max(time(), $termstart);
                            for ($i = 0; $i < 14; $i++) {
                                $dow = (int)date('N', $cursor);
                                if ($dow === $weekday) {
                                    $next_occ = (int)strtotime('midnight', $cursor);
                                    break;
                                }
                                $cursor = strtotime('+1 day', $cursor);
                                if ($cursor > $termend + 86400 * 7) break;
                            }
                        }
                        if ($next_occ <= 0) {
                            $next_occ = (int)strtotime('midnight', $termstart);
                        }
                    }
                    $startstr = $next_occ > 0 ? s(date('D, M j Y', $next_occ)) : s(self::safe_get_string('schedulesessionrepeatflexible', 'Flexible schedule'));
                    $start_min = (int)($sr->start_minutes ?? 0);
                    $dur_min = (int)($sr->duration_minutes ?? 0);
                    if ($start_min >= 0 && $start_min < 24 * 60 && $dur_min > 0) {
                        $starth = (int)floor($start_min / 60);
                        $startm = $start_min % 60;
                        $end_total = $start_min + max($dur_min, 1);
                        $endh = (int)floor($end_total / 60);
                        $endm = $end_total % 60;
                        $time = s(sprintf('%02d:%02d–%02d:%02d', $starth, $startm, $endh, $endm));
                    } else {
                        $time = '';
                    }
                    $occ = $next_occ > 0 ? $next_occ : (int)strtotime('today 00:00:00');
                    $registerurl = new \moodle_url($attendanceurlbase, ['sessionid' => (int)$sr->id, 'occurrence_date' => date('Y-m-d', $occ)]);
                    $openhtml = '<a href="' . $registerurl->out(false) . '" class="ulms-btn ulms-btn--primary" style="min-width:44px;min-height:44px;white-space:nowrap;">' . self::safe_get_string('lecturerattendancepickregister', 'Open register') . '</a>';
                    $sessionMeta = '<div style="display:flex;gap:8px;align-items:center;justify-content:space-between;width:100%;">'
                        . '<div>' . $startstr . ($time !== '' ? ' · ' . $time : '') . '</div>'
                        . $openhtml
                        . '</div>';
                    $subitems[] = [
                        'title' => format_string((string)$sr->title),
                        'meta' => $sessionMeta,
                        'meta_raw' => true,
                    ];
                }
            }

            $coursecards[] = [
                'title' => $coursetitle,
                'meta' => $coursemeta,
                'url' => $courseurl,
                'badgehtml' => $badgehtml,
                'assignments' => $subitems,
                'sublistempty' => self::safe_get_string('lecturerattendancenosessionscourse', 'No sessions have been scheduled for this course yet.'),
                'footer' => '<div style="margin-top:8px;padding:0 8px 8px;">'
                    . '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">'
                    . '<span class="ulms-attendancemeta ulms-attendancemeta--' . $rateclass . '">' . s($c_ratepct) . '%</span>'
                    . '<span class="ulms-attendancemeta">' . $sessionscount . ' sessions</span>'
                    . (isset($atriskstudents[$courseid]) && count($atriskstudents[$courseid]) > 0
                        ? '<span class="ulms-coursestat ulms-coursestat--att-absent">At-risk: ' . count($atriskstudents[$courseid]) . '</span>'
                        : '')
                    . '</div>'
                    . '<div style="margin-top:8px;" class="ulms-attendance-track">'
                    . '<div class="ulms-attendance-fill ulms-attendance-bar--' . $barclass . '" style="width:' . s($c_ratepct) . '%;"></div>'
                    . '</div>'
                    . '</div>',
            ];
        }

        $summarycards = [
            [
                'label' => self::safe_get_string('lecturerattendanceallocatedcourses', 'Allocated courses'),
                'value' => (string)$totalcourses,
                'description' => self::safe_get_string('lecturerattendanceallocatedcoursesdesc', 'Courses you are currently assigned to teach this term.'),
            ],
            [
                'label' => self::safe_get_string('lecturerattendanceoverall', 'Overall attendance'),
                'value' => s($overallpct) . '%',
                'description' => self::safe_get_string('lecturerattendanceoveralldesc', 'Aggregate Present + Late rate across every marked student in every allocated course.'),
            ],
            [
                'label' => self::safe_get_string('lecturerattendancemarked', 'Marked sessions'),
                'value' => (string)$totalsessionsmarked,
                'description' => self::safe_get_string('lecturerattendancemarkeddesc', 'Total session-occurrences for which at least one student attendance mark exists.'),
            ],
            [
                'label' => self::safe_get_string('lecturerattendanceatrisk', 'At-risk students'),
                'value' => (string)$atriskcount,
                'description' => self::safe_get_string('lecturerattendanceatriskdesc', 'Students below the 80% attendance threshold across any allocated course.'),
            ],
        ];

        $html = '';

        if ($selected_sessionid > 0) {
            $occurrence_ts = $selected_occurrence_raw !== '' ? (int)strtotime($selected_occurrence_raw . ' 00:00:00') : (int)strtotime('today 00:00:00');
            $summary = $service->get_session_attendance_summary($selected_sessionid, $occurrence_ts);

            $html .= '<div style="margin-bottom:20px;padding:16px;border:1px solid #e1e7ee;border-radius:12px;background:#f8fbff;">';
            $sessionrec = $DB->get_record('local_ulms_dashboard_session', ['id' => $selected_sessionid], 'id, moodlecourseid, title');
            $backurl = new \moodle_url($this->get_routing_service()->get_url_for_route('lecturer.attendance'));
            $header = '<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;justify-content:space-between;margin-bottom:12px;">'
                . '<div>'
                . '<div style="font-weight:700;color:#0f4c81;font-size:1rem;">' . ($sessionrec ? format_string((string)$sessionrec->title) : 'Register') . '</div>'
                . '<div style="color:#475569;font-size:.85rem;">' . s(date('l, F j, Y', $occurrence_ts)) . '</div>'
                . '</div>'
                . '<a href="' . $backurl->out(false) . '" class="ulms-btn" style="min-width:44px;min-height:44px;">← ' . self::safe_get_string('portalbacklink', 'Back to overview') . '</a>'
                . '</div>';

            $html .= $header;
            $html .= '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;">';
            $html .= '<span class="ulms-attendancemeta ulms-attendancemeta--rate">Present: ' . s($summary['percent_present']) . '%</span>';
            $html .= '<span class="ulms-coursestat ulms-coursestat--att-present">' . self::safe_get_string('studentattendancepresentcount', '{$a} Present', (int)($summary['present'] ?? 0)) . '</span>';
            if (!empty($summary['late'])) $html .= '<span class="ulms-coursestat ulms-coursestat--att-late">' . self::safe_get_string('studentattendancelatecount', '{$a} Late', (int)$summary['late']) . '</span>';
            if (!empty($summary['absent'])) $html .= '<span class="ulms-coursestat ulms-coursestat--att-absent">' . self::safe_get_string('studentattendanceabsentcount', '{$a} Absent', (int)$summary['absent']) . '</span>';
            if (!empty($summary['excused'])) $html .= '<span class="ulms-coursestat ulms-coursestat--att-excused">' . self::safe_get_string('studentattendanceexcusedcount', '{$a} Excused', (int)$summary['excused']) . '</span>';
            $html .= '</div>';

            $html .= '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">';
            $html .= '<form method="post" style="display:inline;margin:0;" onsubmit="return confirm(\'' . self::safe_get_string('lecturerattendancebulkpresentconfirm', 'Mark every unmarked student in this register as Present? This cannot be undone per-student without manually editing.') . '\')"><input type="hidden" name="sesskey" value="' . s(sesskey()) . '"><input type="hidden" name="action" value="bulk_present"><input type="hidden" name="sessionid" value="' . s($selected_sessionid) . '"><input type="hidden" name="occurrence_date" value="' . s(date('Y-m-d', $occurrence_ts)) . '"><button type="submit" class="ulms-btn ulms-btn--primary" style="min-width:44px;min-height:44px;">Mark All Present</button></form>';
            $html .= '<form method="post" style="display:inline;margin:0;"><input type="hidden" name="sesskey" value="' . s(sesskey()) . '"><input type="hidden" name="action" value="export_csv"><input type="hidden" name="sessionid" value="' . s($selected_sessionid) . '"><input type="hidden" name="occurrence_date" value="' . s(date('Y-m-d', $occurrence_ts)) . '"><button type="submit" class="ulms-btn" style="min-width:44px;min-height:44px;">Export CSV</button></form>';
            $html .= '</div>';

            $enrolled_students = [];
            if ($sessionrec) {
                try {
                    $ctx = \context_course::instance((int)$sessionrec->moodlecourseid);
                    $studentroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student'], IGNORE_MISSING);
                    if ($studentroleid > 0) {
                        $enrolled_students = get_role_users($studentroleid, $ctx, false, 'u.*', 'u.lastname ASC, u.firstname ASC');
                    }
                    if (empty($enrolled_students) && function_exists('get_enrolled_users')) {
                        $enrolled_students = get_enrolled_users($ctx, '', 0, 'u.*', 'u.lastname ASC, u.firstname ASC');
                        $enrolled_students = array_values(array_filter($enrolled_students, static function ($u): bool {
                            return empty($u->deleted) && (int)($u->id ?? 0) > 1;
                        }));
                    }
                } catch (\Throwable) {
                    $enrolled_students = [];
                }
            }

            $html .= '<div style="overflow-x:auto;"><table class="ulms-attendance-register" data-ulms-attendance="true" data-ulms-attendance-marks="true">';
            $html .= '<thead><tr><th data-label="Student ID">Student ID</th><th data-label="Name">Name</th><th data-label="Status">Status</th><th data-label="Comment">Comment</th><th data-label="Marked At">Marked At</th><th data-label="Marked By">Marked By</th><th data-label="Quick Mark">Quick Mark (P / A / L / E)</th></tr></thead><tbody>';

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
                $statuslabel = '—';
                $markedat = '—';
                $marker = '—';
                $existingcomment = '';
                if (isset($marks[$uid])) {
                    $m = $marks[$uid];
                    $rawstatus = (string)($m->status ?? 'present');
                    $statusclass = self::attendance_status_css_class($rawstatus);
                    $statuslabel = self::attendance_status_lang($rawstatus);
                    $markedat = (int)($m->marked_at ?? 0) > 0 ? s(userdate((int)$m->marked_at)) : '—';
                    if ((int)($m->marked_by ?? 0) > 0) {
                        $mu = \core_user::get_user((int)$m->marked_by);
                        $marker = $mu ? s(fullname($mu)) : '—';
                    }
                    $existingcomment = !empty($m->comment) ? s((string)$m->comment) : '';
                }
                $statusbadge = ($statusclass === '')
                    ? '<span class="ulms-attendancemeta">—</span>'
                    : '<span class="ulms-attendancestatus ulms-attendancestatus--' . $statusclass . '">' . $statuslabel . '</span>';

                $html .= '<tr data-ulms-attendance-row="true" data-studentid="' . s($uid) . '">';
                $html .= '<td data-label="Student ID">' . $sidnum . '</td>';
                $html .= '<td data-label="Name">' . $fullname . '</td>';
                $html .= '<td data-label="Status">' . $statusbadge . '</td>';
                $html .= '<td data-label="Comment"><form method="post" style="margin:0;display:flex;gap:6px;align-items:center;"><input type="hidden" name="sesskey" value="' . s(sesskey()) . '"><input type="hidden" name="action" value="mark"><input type="hidden" name="sessionid" value="' . s($selected_sessionid) . '"><input type="hidden" name="occurrence_date" value="' . s(date('Y-m-d', $occurrence_ts)) . '"><input type="hidden" name="userid" value="' . s($uid) . '"><input type="hidden" name="status" value="present"><input type="text" name="comment" class="form-control" style="min-width:180px;" value="' . $existingcomment . '" placeholder="' . s(self::safe_get_string('lecturerattendancecommentplaceholder', 'Optional comment about attendance for this session')) . '" aria-label="' . s(self::safe_get_string('lecturerattendancecommentlabel', 'Comment')) . '"></form></td>';
                $html .= '<td data-label="Marked At">' . $markedat . '</td>';
                $html .= '<td data-label="Marked By">' . $marker . '</td>';
                $html .= '<td data-label="Quick Mark"><div class="ulms-mark-buttons" data-ulms-mark-buttons="true">';

                $markdefs = [
                    ['status' => 'present', 'mnemonic' => 'P', 'class' => 'ulms-btn--success', 'title' => self::safe_get_string('studentattendancestatuspresent', 'Present') . ' [P]'],
                    ['status' => 'absent',  'mnemonic' => 'A', 'class' => 'ulms-btn--danger',  'title' => self::safe_get_string('studentattendancestatusabsent', 'Absent') . ' [A]'],
                    ['status' => 'late',    'mnemonic' => 'L', 'class' => 'ulms-btn--warning', 'title' => self::safe_get_string('studentattendancestatuslate', 'Late') . ' [L]'],
                    ['status' => 'excused', 'mnemonic' => 'E', 'class' => 'ulms-btn--info',    'title' => self::safe_get_string('studentattendancestatusexcused', 'Excused') . ' [E]'],
                ];
                foreach ($markdefs as $md) {
                    $html .= '<form method="post" style="display:inline;margin:0;"><input type="hidden" name="sesskey" value="' . s(sesskey()) . '"><input type="hidden" name="action" value="mark"><input type="hidden" name="sessionid" value="' . s($selected_sessionid) . '"><input type="hidden" name="occurrence_date" value="' . s(date('Y-m-d', $occurrence_ts)) . '"><input type="hidden" name="userid" value="' . s($uid) . '"><input type="hidden" name="status" value="' . s($md['status']) . '"><input type="hidden" name="comment" value="' . $existingcomment . '"><button type="submit" class="ulms-btn ' . $md['class'] . ' ulms-mark-btn" data-status="' . s($md['status']) . '" data-mnemonic="' . s($md['mnemonic']) . '" style="min-width:44px;min-height:44px;margin:2px;" title="' . s($md['title']) . '">' . s($md['mnemonic']) . '</button></form>';
                }
                $html .= '</div></td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div>';
            $html .= '</div>';

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

        $emptytitle = self::safe_get_string('lecturerattendanceemptycourses', 'No allocated courses');
        $emptydesc = self::safe_get_string('lecturerattendanceemptycoursesdesc', 'Courses will appear here once you have been allocated as a lecturer via Admin → Lecturer allocations.');
        if (!empty($courseids)) {
            $emptytitle = self::safe_get_string('norecentactivity', 'No sessions marked yet');
            $emptydesc = self::safe_get_string('lecturerattendancenosessionscourse', 'No sessions have been scheduled for this course yet.');
        }

        $overviewpanel = [
            'title' => self::safe_get_string('lecturerattendancetitle', 'Attendance register'),
            'subtitle' => self::safe_get_string('lecturerattendancedescgrouped', 'Overview by allocated course, at-risk students, and register drill-down for marking.'),
            'style' => 'coursegroups',
            'items' => $coursecards,
            'emptytitle' => $emptytitle,
            'emptydesc' => $emptydesc,
        ];

        if ($selected_sessionid > 0 && $html !== '') {
            return [
                'summarycards' => $summarycards,
                'mainpanel' => $overviewpanel,
                'secondarypanels' => [
                    [
                        'title' => self::safe_get_string('lecturerattendancetitle', 'Attendance register') . ' · Register',
                        'subtitle' => '',
                        'style' => 'html',
                        'html' => $html,
                    ],
                ],
            ];
        }

        return [
            'summarycards' => $summarycards,
            'mainpanel' => $overviewpanel,
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

    /**
     * Admin → Lecturer Course Allocations (Option A SSOT: Moodle editingteacher
     * role enrolments via the Manual enrolment plugin). Provides cascade filters,
     * per-course lecturer chips, primary pill, assign modal with transactional
     * enrol/unenrol writes, and CSV bulk import.
     *
     * @return array{summarycards: array, mainpanel: array}
     */
    private function build_admin_lecturer_allocation_data(): array {
        global $DB, $USER;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            confirm_sesskey();
            $action = optional_param('action', '', PARAM_ALPHA);
            $routingservice = $this->get_routing_service();
            $base = new \moodle_url($routingservice->get_url_for_route('management.lecturers'));
            $f = ['facultyid', 'deptid', 'progid', 'semesterid', 'levelid'];
            $qparams = [];
            foreach ($f as $p) {
                $qparams[$p] = optional_param($p, 0, PARAM_INT);
            }

            if ($action === 'allocate_save') {
                $courseid = (int)optional_param('moodlecourseid', 0, PARAM_INT);
                $lecturerids = optional_param_array('lecturerids', [], PARAM_INT);
                $lecturerids = array_values(array_unique(array_map('intval', array_filter($lecturerids, static fn($v) => $v > 0))));
                $primaryuid = (int)optional_param('primarylecturerid', 0, PARAM_INT);

                $transaction = $DB->start_delegated_transaction();
                try {
                    $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname', MUST_EXIST);
                    $manual = enrol_get_plugin('manual');
                    if (!$manual) {
                        throw new \RuntimeException('Manual enrol plugin not available.');
                    }
                    $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);

                    $instances = enrol_get_instances($courseid, true);
                    $manualinstance = null;
                    foreach ($instances as $inst) {
                        if ($inst->enrol === 'manual') {
                            $manualinstance = $inst;
                            break;
                        }
                    }
                    if (!$manualinstance) {
                        $manualinstance = $manual->add_instance($course);
                    }

                    $currentlyenrolled = [];
                    $ctx = \context_course::instance($courseid);
                    $existing = get_role_users($roleid, $ctx, false, 'u.id', 'u.id');
                    foreach ($existing as $uid => $_) {
                        $currentlyenrolled[(int)$uid] = (int)$uid;
                    }

                    $to_enrol = array_diff($lecturerids, $currentlyenrolled);
                    $to_unenrol = array_diff($currentlyenrolled, $lecturerids);

                    if (!empty($to_unenrol)) {
                        $confirm = optional_param('confirm_remove', 0, PARAM_INT);
                        if ($confirm !== 1) {
                            $cnt = count($to_unenrol);
                            $names = [];
                            foreach (array_slice($to_unenrol, 0, 5) as $uid) {
                                $u = \core_user::get_user($uid);
                                $names[] = $u ? fullname($u) : '#'.$uid;
                            }
                            $msg = self::safe_get_string('adminlecturersremovalconfirm',
                                '{$a->count} currently-assigned lecturer(s) will be un-enrolled from {$a->course}. Continue?',
                                (object)['count' => $cnt, 'course' => format_string($course->fullname)]
                            );
                            $transaction->rollback(new \moodle_exception($msg . ' Please tick confirm-remove to proceed.'));
                        }
                        foreach ($to_unenrol as $uid) {
                            $manual->unenrol_user($manualinstance, $uid);
                        }
                    }
                    foreach ($to_enrol as $uid) {
                        $manual->enrol_user($manualinstance, $uid, $roleid, 0, 0, ENROL_USER_ACTIVE);
                    }
                    $transaction->allow_commit();
                    \core\notification::add(self::safe_get_string('adminlecturerssavesuccess',
                        'Lecturer allocations saved. Enrolments updated.'),
                        \core\output\notification::NOTIFY_SUCCESS
                    );
                } catch (\Throwable $e) {
                    if (isset($transaction)) {
                        try { $transaction->rollback($e); } catch (\Throwable) {}
                    }
                    $emsg = self::safe_get_string('adminlecturerssavefail',
                        'Failed to save allocations: {$a}',
                        $e->getMessage()
                    );
                    \core\notification::add($emsg, \core\output\notification::NOTIFY_ERROR);
                }
                redirect(new \moodle_url($base, $qparams));
            }

            if ($action === 'allocate_csv') {
                $success = 0;
                $skipped = 0;
                $errors = 0;
                $rawfile = $_FILES['csvfile'] ?? null;
                if ($rawfile && is_uploaded_file($rawfile['tmp_name'] ?? '')) {
                    $fh = fopen($rawfile['tmp_name'], 'rb');
                    if ($fh) {
                        $transaction = $DB->start_delegated_transaction();
                        try {
                            $header = fgetcsv($fh);
                            if ($header && is_array($header)) {
                                $manual_plugin = enrol_get_plugin('manual');
                                if (!$manual_plugin) {
                                    throw new \RuntimeException('Manual enrol plugin unavailable.');
                                }
                                $eroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
                                while (($row = fgetcsv($fh)) !== false) {
                                    if (!is_array($row) || count($row) < 7) { $skipped++; continue; }
                                    [$faculty, $dept, $prog, $semester, $level, $coursecode, $staffid] = $row;
                                    if (trim((string)$coursecode) === '' || trim((string)$staffid) === '') { $skipped++; continue; }
                                    $crs = $DB->get_record('course', ['shortname' => trim((string)$coursecode)], 'id');
                                    if (!$crs) { $skipped++; continue; }
                                    $staffrec = $DB->get_record('user', ['idnumber' => trim((string)$staffid)], 'id');
                                    if (!$staffrec) { $skipped++; continue; }
                                    $cid = (int)$crs->id;
                                    $uid = (int)$staffrec->id;
                                    $instances = enrol_get_instances($cid, true);
                                    $minst = null;
                                    foreach ($instances as $i) { if ($i->enrol === 'manual') { $minst = $i; break; } }
                                    if (!$minst) { $minst = $manual_plugin->add_instance((object)['id' => $cid]); }
                                    $ctx = \context_course::instance($cid);
                                    if (!user_has_role_assignment($uid, $eroleid, $ctx->id)) {
                                        $manual_plugin->enrol_user($minst, $uid, $eroleid, 0, 0, ENROL_USER_ACTIVE);
                                    }
                                    $success++;
                                }
                            }
                            $transaction->allow_commit();
                        } catch (\Throwable $e) {
                            try { $transaction->rollback($e); } catch (\Throwable) {}
                            $errors++;
                        }
                        fclose($fh);
                    }
                }
                \core\notification::add(self::safe_get_string('adminlecturerscsvimported',
                    'Imported {$a->success} rows. Skipped {$a->skipped}. Errors: {$a->errors}.',
                    (object)['success' => $success, 'skipped' => $skipped, 'errors' => $errors]
                ), \core\output\notification::NOTIFY_INFO);
                redirect(new \moodle_url($base, $qparams));
            }
        }

        $facultyid = optional_param('facultyid', 0, PARAM_INT);
        $deptid = optional_param('deptid', 0, PARAM_INT);
        $progid = optional_param('progid', 0, PARAM_INT);
        $semesterid = optional_param('semesterid', 0, PARAM_INT);
        $levelid = optional_param('levelid', 0, PARAM_INT);

        $faculties = [0 => self::safe_get_string('adminlecturersfilterfaculty', '-- All Faculties --')]
            + $DB->get_records_menu('local_ulms_faculties', null, 'name ASC', 'id, name');
        $departments = [0 => self::safe_get_string('adminlecturersfilterdept', '-- All Departments --')]
            + $DB->get_records_menu('local_ulms_departments', null, 'name ASC', 'id, name');
        $programmes = [0 => self::safe_get_string('adminlecturersfilterprogramme', '-- All Programmes --')]
            + $DB->get_records_menu('local_ulms_programmes', null, 'name ASC', 'id, name');
        $semesters = [0 => self::safe_get_string('adminlecturersfiltersemester', '-- All Semesters / Levels --')]
            + $DB->get_records_menu('local_ulms_semesters', null, 'name ASC', 'id, name');
        $levels = [0 => '—'] + $DB->get_records_menu('local_ulms_levels', null, 'name ASC', 'id, name');

        $wheres = ['1=1'];
        $params = [];
        $joins = '';
        if ($facultyid > 0 || $deptid > 0) {
            $joins .= " JOIN {local_ulms_programmes} p ON p.id = pc.programmeid
                        JOIN {local_ulms_departments} d ON d.id = p.departmentid ";
        }
        if ($facultyid > 0) {
            $joins .= " JOIN {local_ulms_faculties} f ON f.id = d.facultyid ";
            $wheres[] = 'f.id = ?';
            $params[] = $facultyid;
        }
        if ($deptid > 0) {
            $wheres[] = 'd.id = ?';
            $params[] = $deptid;
        }
        if ($progid > 0) {
            $wheres[] = 'pc.programmeid = ?';
            $params[] = $progid;
        }
        if ($semesterid > 0) {
            $wheres[] = 'pc.semesterid = ?';
            $params[] = $semesterid;
        }
        if ($levelid > 0) {
            $wheres[] = 'pc.levelid = ?';
            $params[] = $levelid;
        }
        $where = implode(' AND ', $wheres);
        $sql = "SELECT pc.id AS pclink, pc.moodlecourseid, c.fullname, c.shortname
                  FROM {local_ulms_programme_courses} pc
                  JOIN {course} c ON c.id = pc.moodlecourseid
                  $joins
                 WHERE $where
              ORDER BY c.shortname ASC";
        $rows = $DB->get_records_sql($sql, $params);

        $eroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $courserows = [];
        $courseswithlecturer = 0;
        $uniquelecturers = [];
        $programme_course_count = 0;
        foreach ($rows as $r) {
            $programme_course_count++;
            $cid = (int)$r->moodlecourseid;
            $ctx = \context_course::instance($cid, IGNORE_MISSING);
            $lecturers = [];
            if ($ctx) {
                $rs = get_role_users($eroleid, $ctx, false, 'u.id, u.firstname, u.lastname, u.idnumber', 'u.lastname ASC');
                foreach ($rs as $usr) {
                    $lecturers[(int)$usr->id] = $usr;
                    $uniquelecturers[(int)$usr->id] = true;
                }
            }
            if (!empty($lecturers)) $courseswithlecturer++;
            $studentcount = 0;
            if ($ctx) {
                $studentroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
                $studentcount = $studentroleid > 0 ? count(get_role_users($studentroleid, $ctx, false, 'u.id')) : 0;
            }
            $chips = '';
            $names = array_values($lecturers);
            $shown = array_slice($names, 0, 3);
            foreach ($shown as $usr) {
                $chips .= '<span class="ulms-coursestat ulms-coursestat--att-present" style="margin:2px 4px 2px 0;">'
                    . s(fullname($usr)) . '</span>';
            }
            if (count($lecturers) > count($shown)) {
                $extra = count($lecturers) - count($shown);
                $chips .= '<span class="ulms-coursestat ulms-coursestat--att-zero" style="margin:2px 4px;" title="'
                    . s(implode(', ', array_map(static fn($u) => fullname($u), array_slice($names, 3))))
                    . '">+' . $extra . '</span>';
            }
            if ($chips === '') {
                $chips = '<span class="ulms-coursestat ulms-coursestat--att-zero">—</span>';
            }
            $courserows[] = (object)[
                'courseid' => $cid,
                'coursename' => format_string($r->fullname),
                'shortname' => format_string($r->shortname),
                'studentcount' => $studentcount,
                'lecturercount' => count($lecturers),
                'lecturerchips' => $chips,
                'primarypill' => $lecturers ? '<span class="ulms-coursestat ulms-coursestat--att-present">Primary</span>' : '',
            ];
        }
        $unassigned = $programme_course_count - $courseswithlecturer;
        $totallecturers = count($uniquelecturers);

        $routingservice = $this->get_routing_service();
        $baseurl = new \moodle_url($routingservice->get_url_for_route('management.lecturers'));
        $formurl = $baseurl->out(false);

        $html = '';
        $html .= '<form method="get" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:20px;padding:16px;border:1px solid #e1e7ee;border-radius:12px;background:#f8fbff;align-items:flex-end;">';
        $selects = [
            'facultyid' => $faculties,
            'deptid' => $departments,
            'progid' => $programmes,
            'semesterid' => $semesters,
        ];
        $labels = [
            'facultyid' => self::safe_get_string('adminlecturersfilterfaculty', 'Faculty'),
            'deptid' => self::safe_get_string('adminlecturersfilterdept', 'Department'),
            'progid' => self::safe_get_string('adminlecturersfilterprogramme', 'Programme'),
            'semesterid' => self::safe_get_string('adminlecturersfiltersemester', 'Semester + Level'),
        ];
        $vals = [
            'facultyid' => $facultyid,
            'deptid' => $deptid,
            'progid' => $progid,
            'semesterid' => $semesterid,
        ];
        foreach ($selects as $pname => $opts) {
            $html .= '<div><label for="alloc_' . $pname . '" style="display:block;margin-bottom:6px;font-weight:600;color:#0f4c81;">'
                . s($labels[$pname]) . '</label><select id="alloc_' . $pname . '" name="' . $pname
                . '" class="form-control" onchange="this.form.submit()" style="min-height:44px;">';
            foreach ($opts as $vid => $vlabel) {
                $sel = (int)$vals[$pname] === (int)$vid ? ' selected' : '';
                $html .= '<option value="' . s($vid) . '"' . $sel . '>' . s((string)$vlabel) . '</option>';
            }
            $html .= '</select></div>';
        }
        $html .= '<div><label for="alloc_levelid" style="display:block;margin-bottom:6px;font-weight:600;color:#0f4c81;">'
            . s(self::safe_get_string('adminlecturersfiltersemester', 'Level'))
            . '</label><select id="alloc_levelid" name="levelid" class="form-control" onchange="this.form.submit()" style="min-height:44px;">';
        foreach ($levels as $vid => $vlabel) {
            $sel = $levelid === (int)$vid ? ' selected' : '';
            $html .= '<option value="' . s($vid) . '"' . $sel . '>' . s((string)$vlabel) . '</option>';
        }
        $html .= '</select></div>';
        $html .= '<noscript><button type="submit" class="ulms-btn ulms-btn--primary" style="min-height:44px;">Filter</button></noscript>';
        $html .= '</form>';

        $html .= '<div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:space-between;align-items:center;margin-bottom:16px;">';
        $html .= '<div><h3 style="margin:0;font-size:1.1rem;color:#0f4c81;">'
            . s(self::safe_get_string('adminlecturerscolumns', 'Course allocations'))
            . '</h3></div>';
        $html .= '<form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
        $html .= '<input type="hidden" name="sesskey" value="' . s(sesskey()) . '">';
        $html .= '<input type="hidden" name="action" value="allocate_csv">';
        $html .= '<p style="margin:0;color:#475569;font-size:.85rem;">'
            . s(self::safe_get_string('adminlecturerscsvhelp',
                'Columns: Faculty,Department,Programme,Semester,Level,CourseCode,StaffID. One lecturer per row per course.'))
            . '</p>';
        $html .= '<input type="file" name="csvfile" accept=".csv" class="form-control" style="max-width:260px;">';
        $html .= '<button type="submit" class="ulms-btn ulms-btn--primary" style="min-width:44px;min-height:44px;">Import CSV</button>';
        $html .= '</form>';
        $html .= '</div>';

        $html .= '<div style="overflow-x:auto;"><table class="table table-hover table-sm" style="width:100%;border-collapse:separate;border-spacing:0;">';
        $html .= '<thead><tr style="background:#eaf2fb;">';
        $html .= '<th style="padding:10px 12px;text-align:left;">' . s(self::safe_get_string('adminlecturerscoursename', 'Course')) . '</th>';
        $html .= '<th style="padding:10px 12px;text-align:center;">' . s(self::safe_get_string('adminlecturersstudents', 'Students')) . '</th>';
        $html .= '<th style="padding:10px 12px;text-align:center;">' . s(self::safe_get_string('adminlecturerslecturercount', 'Lecturers')) . '</th>';
        $html .= '<th style="padding:10px 12px;text-align:left;">' . s(self::safe_get_string('adminlecturerslecturers', 'Assigned lecturers')) . '</th>';
        $html .= '<th style="padding:10px 12px;text-align:center;">' . s(self::safe_get_string('adminlecturersprimary', 'Primary')) . '</th>';
        $html .= '<th style="padding:10px 12px;text-align:center;">' . s(self::safe_get_string('adminlecturersassign', 'Action')) . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($courserows as $r) {
            $html .= '<tr style="border-bottom:1px solid #eef2f7;">';
            $html .= '<td style="padding:10px 12px;"><div style="font-weight:600;color:#0f4c81;">' . s($r->coursename)
                . '</div><div style="color:#64748b;font-size:.8rem;">' . s($r->shortname) . '</div></td>';
            $html .= '<td style="padding:10px 12px;text-align:center;">' . s($r->studentcount) . '</td>';
            $html .= '<td style="padding:10px 12px;text-align:center;">'
                . '<span class="ulms-coursestat ulms-coursestat--att-present">' . s($r->lecturercount) . '</span></td>';
            $html .= '<td style="padding:10px 12px;">' . $r->lecturerchips . '</td>';
            $html .= '<td style="padding:10px 12px;text-align:center;">' . $r->primarypill . '</td>';
            $html .= '<td style="padding:10px 12px;text-align:center;">'
                . '<button type="button" class="ulms-btn ulms-btn--primary" style="min-width:44px;min-height:44px;"'
                . ' onclick="document.getElementById(\'alloc-modal-' . s($r->courseid) . '\').style.display=\'block\'">'
                . s(self::safe_get_string('adminlecturersassignedit', 'Edit lecturers'))
                . '</button></td>';
            $html .= '</tr>';

            $candidates = $DB->get_records_sql(
                "SELECT u.id, u.firstname, u.lastname, u.idnumber, u.email
                   FROM {role_assignments} ra
                   JOIN {role} r ON r.id = ra.roleid
                   JOIN {user} u ON u.id = ra.userid
                  WHERE r.shortname IN ('lecturer', 'editingteacher', 'teacher')
                    AND u.deleted = 0
                  GROUP BY u.id, u.firstname, u.lastname, u.idnumber, u.email
                  ORDER BY u.lastname ASC, u.firstname ASC",
                null,
                0,
                300
            );
            if (empty($candidates)) {
                $candidates = [];
            }
            $ctx = \context_course::instance($r->courseid, IGNORE_MISSING);
            $cur = [];
            if ($ctx) {
                $ccur = get_role_users($eroleid, $ctx, false, 'u.id, u.firstname, u.lastname', 'u.lastname ASC');
                foreach ($ccur as $uid => $_) { $cur[(int)$uid] = (int)$uid; }
            }
            $html .= '<div id="alloc-modal-' . s($r->courseid) . '" style="display:none;position:fixed;inset:0;background:rgba(15,76,129,0.45);z-index:9999;padding:24px;overflow:auto;" onclick="if(event.target===this){this.style.display=\'none\';}">';
            $html .= '<div style="max-width:640px;margin:0 auto;background:#fff;border-radius:16px;padding:24px;box-shadow:0 20px 50px rgba(15,76,129,0.25);">';
            $html .= '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">';
            $html .= '<h3 style="margin:0;color:#0f4c81;">'
                . s(self::safe_get_string('adminlecturersassignmodalh1', 'Lecturers for {$a}', $r->coursename))
                . '</h3>';
            $html .= '<button type="button" class="ulms-btn" style="min-width:44px;min-height:44px;" onclick="document.getElementById(\'alloc-modal-' . s($r->courseid) . '\').style.display=\'none\';">Close</button>';
            $html .= '</div>';
            $html .= '<p style="margin:0 0 16px;color:#475569;">'
                . s(self::safe_get_string('adminlecturersassignhelp',
                    'Multi-select below. Saving enrols/un-enrols lecturers via the Manual enrolment plugin.'))
                . '</p>';
            $html .= '<form method="post" action="' . s($formurl) . '">';
            $html .= '<input type="hidden" name="sesskey" value="' . s(sesskey()) . '">';
            $html .= '<input type="hidden" name="action" value="allocate_save">';
            $html .= '<input type="hidden" name="moodlecourseid" value="' . s($r->courseid) . '">';
            foreach (['facultyid'=>$facultyid,'deptid'=>$deptid,'progid'=>$progid,'semesterid'=>$semesterid,'levelid'=>$levelid] as $pn=>$pv) {
                $html .= '<input type="hidden" name="' . $pn . '" value="' . s($pv) . '">';
            }
            if (empty($candidates)) {
                $html .= '<div class="alert alert-warning">'
                    . s(self::safe_get_string('adminlecturerssearchnocandidates',
                        'No lecturer users exist yet. Create staff in Moodle users first.'))
                    . '</div>';
            } else {
                $html .= '<div style="max-height:420px;overflow:auto;border:1px solid #e1e7ee;border-radius:12px;padding:12px;background:#f8fbff;">';
                foreach ($candidates as $c) {
                    $checked = isset($cur[(int)$c->id]) ? ' checked' : '';
                    $html .= '<label style="display:flex;gap:10px;align-items:flex-start;padding:8px 6px;border-radius:8px;cursor:pointer;" onmouseover="this.style.background=\'#eef4fb\'" onmouseout="this.style.background=\'transparent\'">';
                    $html .= '<input type="checkbox" name="lecturerids[]" value="' . s($c->id) . '" style="min-width:20px;min-height:20px;margin-top:3px;"' . $checked . '>';
                    $html .= '<div><div style="font-weight:600;color:#0f172a;">' . s(fullname($c)) . '</div>';
                    $html .= '<div style="color:#64748b;font-size:.8rem;">ID: ' . s((string)($c->idnumber ?? (string)$c->id))
                        . (!empty($c->email) ? ' · ' . s($c->email) : '') . '</div></div>';
                    $html .= '</label>';
                }
                $html .= '</div>';
                $html .= '<div style="margin-top:12px;"><label style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;"><input type="checkbox" name="confirm_remove" value="1" style="min-width:18px;min-height:18px;"> <span style="color:#b91c1c;font-weight:500;">Confirm that un-ticked lecturers will be UN-enrolled from this course.</span></label></div>';
            }
            $html .= '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap;">';
            $html .= '<button type="button" class="ulms-btn" style="min-width:44px;min-height:44px;" onclick="document.getElementById(\'alloc-modal-' . s($r->courseid) . '\').style.display=\'none\';">Cancel</button>';
            $html .= '<button type="submit" class="ulms-btn ulms-btn--primary" style="min-width:44px;min-height:44px;">Save allocations</button>';
            $html .= '</div></form></div></div>';
        }
        if (empty($courserows)) {
            $html .= '<tr><td colspan="6" style="padding:40px;text-align:center;color:#64748b;">'
                . '<div style="font-weight:600;margin-bottom:6px;color:#475569;">'
                . s(self::safe_get_string('adminlecturersempty', 'No courses in this funnel yet.'))
                . '</div><div style="font-size:.9rem;">'
                . s(self::safe_get_string('adminlecturersemptydesc',
                    'Select an academic funnel above, or import rows in bulk from CSV.'))
                . '</div></td></tr>';
        }
        $html .= '</tbody></table></div>';

        $summarycards = [
            ['label' => 'Programme–course rows', 'value' => (string)$programme_course_count, 'description' => 'Total active course links within the selected academic funnel.'],
            ['label' => 'Courses with ≥1 lecturer', 'value' => (string)$courseswithlecturer, 'description' => 'Courses that have at least one editingteacher role enrolment via Manual plugin.'],
            ['label' => 'Unassigned courses', 'value' => (string)$unassigned, 'description' => 'Courses with zero lecturers allocated.'],
            ['label' => 'Unique lecturers', 'value' => (string)$totallecturers, 'description' => 'Distinct editingteacher users assigned to at least one course in the funnel.'],
        ];

        return [
            'summarycards' => $summarycards,
            'mainpanel' => [
                'title' => self::safe_get_string('adminlecturerstitle', 'Lecturer Course Allocations'),
                'subtitle' => self::safe_get_string('adminlecturersdesc',
                    'Assign and manage which lecturers teach each course. Writes to Moodle course enrolments using the Manual enrolment plugin.'),
                'style' => 'html',
                'html' => $html,
            ],
            'secondarypanels' => [],
        ];
    }

    /**
     * Renders the aggregate status badges for a course's attendance summary.
     *
     * Urgency-first priority: Absent (red) > Late (amber) > Excused (slate)
     * > Present (green) > Zero (slate grey). Only the highest-priority
     * non-zero bucket is rendered first so at-risk courses surface first
     * visually; secondary buckets are appended only when they also carry
     * non-trivial counts so the chip row stays scannable.
     *
     * @param array{total:int, present:int, late:int, absent:int, excused:int} $c
     * @return string HTML chip row (zero or more .ulms-coursestat spans)
     */
    private static function render_course_attendance_badge(array $c): string {
        $total = (int)($c['total'] ?? 0);
        if ($total <= 0) {
            return '<span class="ulms-coursestat ulms-coursestat--att-zero">'
                . self::safe_get_string('studentattendancezero', 'No sessions marked')
                . '</span>';
        }
        $parts = [];
        if (!empty($c['absent'])) {
            $parts[] = '<span class="ulms-coursestat ulms-coursestat--att-absent">'
                . self::safe_get_string('studentattendanceabsentcount', '{$a} Absent', (int)$c['absent'])
                . '</span>';
        }
        if (!empty($c['late'])) {
            $parts[] = '<span class="ulms-coursestat ulms-coursestat--att-late">'
                . self::safe_get_string('studentattendancelatecount', '{$a} Late', (int)$c['late'])
                . '</span>';
        }
        if (!empty($c['excused'])) {
            $parts[] = '<span class="ulms-coursestat ulms-coursestat--att-excused">'
                . self::safe_get_string('studentattendanceexcusedcount', '{$a} Excused', (int)$c['excused'])
                . '</span>';
        }
        if (!empty($c['present'])) {
            $parts[] = '<span class="ulms-coursestat ulms-coursestat--att-present">'
                . self::safe_get_string('studentattendancepresentcount', '{$a} Present', (int)$c['present'])
                . '</span>';
        }
        if (empty($parts)) {
            $parts[] = '<span class="ulms-coursestat ulms-coursestat--att-zero">'
                . self::safe_get_string('studentattendancetotalcount', 'Total: {$a}', $total)
                . '</span>';
        }
        return implode('', $parts);
    }

    /**
     * Returns the CSS modifier class used by a per-session attendance pill.
     *
     * @param string $status one of present/late/absent/excused
     * @return string
     */
    private static function attendance_status_css_class(string $status): string {
        return match ($status) {
            'present' => 'present',
            'late' => 'late',
            'absent' => 'absent',
            'excused' => 'excused',
            default => 'present',
        };
    }

    /**
     * Human-readable label for a per-session attendance status pill.
     *
     * @param string $status
     * @return string
     */
    private static function attendance_status_lang(string $status): string {
        return match ($status) {
            'present' => self::safe_get_string('studentattendancestatuspresent', 'Present'),
            'late' => self::safe_get_string('studentattendancestatuslate', 'Late'),
            'absent' => self::safe_get_string('studentattendancestatusabsent', 'Absent'),
            'excused' => self::safe_get_string('studentattendancestatusexcused', 'Excused'),
            default => self::safe_get_string('studentattendancestatuspresent', 'Present'),
        };
    }
}
