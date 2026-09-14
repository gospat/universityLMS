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

namespace local_ulms_dashboard;

defined('MOODLE_INTERNAL') || die();

use advanced_testcase;
use local_ulms_auth\local\service\landing_page_service;
use local_ulms_dashboard\local\service\admin_portal_service;
use local_ulms_dashboard\local\service\dashboard_service;
use local_ulms_dashboard\local\service\lecturer_portal_service;
use local_ulms_dashboard\local\service\student_portal_service;
use local_ulms_dashboard\local\service\super_admin_portal_service;

require_once(__DIR__ . '/../../../config.php');

/**
 * Tests admin and super admin portal isolation rules.
 *
 * @covers \local_ulms_dashboard\local\service\dashboard_service
 * @covers \local_ulms_dashboard\local\service\admin_portal_service
 * @covers \local_ulms_dashboard\local\service\super_admin_portal_service
 */
final class portal_isolation_service_test extends advanced_testcase {
    public function test_admin_and_super_admin_portal_identity_stays_separate(): void {
        $this->resetAfterTest(true);

        $manager = $this->getDataGenerator()->create_user();
        $this->assign_system_role($manager, 'manager');
        $routingservice = new landing_page_service();
        $dashboardservice = new dashboard_service();

        $this->setUser($manager);
        $this->assertSame('administrator', $routingservice->get_current_user_portal_key());
        $this->assertStringEndsWith('/management/', $routingservice->get_dashboard_url_for_current_user()->get_path());
        $this->assertTrue($dashboardservice->current_user_is_admin_portal_user());
        $this->assertFalse($dashboardservice->current_user_is_super_admin_portal_user());
        $this->assertTrue($dashboardservice->current_user_has_admin_permissions());
        $this->assertTrue($dashboardservice->current_user_can_access_dashboard('admin'));
        $this->assertFalse($dashboardservice->current_user_can_access_dashboard('superadmin'));

        $this->setAdminUser();
        $this->assertSame('superadmin', $routingservice->get_current_user_portal_key());
        $this->assertStringEndsWith('/super-admin/', $routingservice->get_dashboard_url_for_current_user()->get_path());
        $this->assertFalse($dashboardservice->current_user_is_admin_portal_user());
        $this->assertTrue($dashboardservice->current_user_is_super_admin_portal_user());
        $this->assertTrue($dashboardservice->current_user_has_admin_permissions());
        $this->assertFalse($dashboardservice->current_user_can_access_dashboard('admin'));
        $this->assertTrue($dashboardservice->current_user_can_access_dashboard('superadmin'));
    }

    public function test_management_routes_render_admin_shell_for_admin_users_only(): void {
        $this->resetAfterTest(true);

        $manager = $this->getDataGenerator()->create_user();
        $this->assign_system_role($manager, 'manager');
        $page = $this->create_page('/management/users');
        $adminportalservice = new admin_portal_service();
        $routingservice = new landing_page_service();

        $this->setUser($manager);
        $this->assertTrue($adminportalservice->is_admin_portal_user());
        $this->assertSame('/management/users', $routingservice->normalise_path($page->url->get_path()));
        $managercontext = $adminportalservice->get_shell_context_for_page($page);
        $this->assertNotNull($managercontext);
        $this->assertSame(get_string('adminportalshelltitle', 'local_ulms_dashboard'), $managercontext['portalname']);

        $this->setAdminUser();
        $this->assertNull($adminportalservice->get_shell_context_for_page($page));
    }

    public function test_super_admin_keeps_super_admin_shell_on_management_routes(): void {
        $this->resetAfterTest(true);

        $this->setAdminUser();
        $page = $this->create_page('/management/users');
        $portalservice = new super_admin_portal_service();
        $routingservice = new landing_page_service();

        $this->assertSame('/management/users', $routingservice->normalise_path($page->url->get_path()));
        $shellcontext = $portalservice->get_shell_context_for_page($page);
        $headercontext = $portalservice->get_header_context_for_page($page);

        $this->assertNotNull($shellcontext);
        $this->assertSame(get_string('superadminportalshelltitle', 'local_ulms_dashboard'), $shellcontext['portalname']);
        $this->assertNotNull($headercontext);
        $this->assertSame(get_string('superadminportaleyebrow', 'local_ulms_dashboard'), $headercontext['eyebrow']);
        $this->assertSame(get_string('adminusermanagementlink', 'local_ulms_dashboard'), $headercontext['title']);

        $labels = [];
        foreach ($shellcontext['navgroups'] as $group) {
            foreach ($group['items'] as $item) {
                $labels[] = $item['label'];
            }
        }

        $this->assertContains(get_string('superadminnavdashboard', 'local_ulms_dashboard'), $labels);
        $this->assertContains(get_string('adminnavusermanagement', 'local_ulms_dashboard'), $labels);
    }

    public function test_admin_cannot_enter_super_admin_routes(): void {
        $this->resetAfterTest(true);

        $manager = $this->getDataGenerator()->create_user();
        $this->assign_system_role($manager, 'manager');
        $page = $this->create_page('/super-admin/system');

        $this->setUser($manager);
        $portalservice = new super_admin_portal_service();
        $this->assertNull($portalservice->get_shell_context_for_page($page));
        $this->assertNull($portalservice->get_header_context_for_page($page));
    }

    public function test_super_admin_health_route_keeps_super_admin_shell_and_header(): void {
        $this->resetAfterTest(true);

        $this->setAdminUser();
        $page = $this->create_page('/super-admin/system');
        $portalservice = new super_admin_portal_service();
        $routingservice = new landing_page_service();

        $this->assertSame('/super-admin/system', $routingservice->normalise_path($page->url->get_path()));
        $shellcontext = $portalservice->get_shell_context_for_page($page);
        $headercontext = $portalservice->get_header_context_for_page($page);

        $this->assertNotNull($shellcontext);
        $this->assertSame(get_string('superadminportalshelltitle', 'local_ulms_dashboard'), $shellcontext['portalname']);
        $this->assertNotNull($headercontext);
        $this->assertSame(get_string('superadminhealth', 'local_ulms_dashboard'), $headercontext['title']);
    }

    public function test_admin_course_create_page_keeps_admin_shell_and_header(): void {
        $this->resetAfterTest(true);

        $manager = $this->getDataGenerator()->create_user();
        $this->assign_system_role($manager, 'manager');
        $page = $this->create_page('/course/edit.php', ['category' => 1]);
        $portalservice = new admin_portal_service();
        $routingservice = new landing_page_service();

        $this->setUser($manager);
        $this->assertSame('/course/edit.php', $routingservice->normalise_path($page->url->get_path()));

        $shellcontext = $portalservice->get_shell_context_for_page($page);
        $headercontext = $portalservice->get_header_context_for_page($page);

        $this->assertNotNull($shellcontext);
        $this->assertSame(get_string('adminportalshelltitle', 'local_ulms_dashboard'), $shellcontext['portalname']);
        $this->assertNotNull($headercontext);
        $this->assertSame(get_string('admincoursestitle', 'local_ulms_dashboard'), $headercontext['title']);
    }

    public function test_super_admin_course_management_page_keeps_super_admin_shell_and_header(): void {
        $this->resetAfterTest(true);

        $this->setAdminUser();
        $page = $this->create_page('/course/management.php', ['categoryid' => 1]);
        $portalservice = new super_admin_portal_service();
        $routingservice = new landing_page_service();

        $this->assertSame('/course/management.php', $routingservice->normalise_path($page->url->get_path()));

        $shellcontext = $portalservice->get_shell_context_for_page($page);
        $headercontext = $portalservice->get_header_context_for_page($page);

        $this->assertNotNull($shellcontext);
        $this->assertSame(get_string('superadminportalshelltitle', 'local_ulms_dashboard'), $shellcontext['portalname']);
        $this->assertNotNull($headercontext);
        $this->assertSame(get_string('admincoursestitle', 'local_ulms_dashboard'), $headercontext['title']);
    }

    public function test_student_deep_links_keep_student_shell_and_headers(): void {
        $this->resetAfterTest(true);

        $student = $this->getDataGenerator()->create_user();
        $this->assign_system_role($student, 'student');
        $portalservice = new student_portal_service();

        $this->setUser($student);

        $discussionpage = $this->create_page('/mod/forum/discuss.php', ['d' => 1]);
        $discussionshell = $portalservice->get_shell_context_for_page($discussionpage);
        $discussionheader = $portalservice->get_header_context_for_page($discussionpage);
        $this->assertNotNull($discussionshell);
        $this->assertNotNull($discussionheader);
        $this->assertSame(get_string('studentannouncementstitle', 'local_ulms_dashboard'), $discussionheader['title']);

        $assignmentpage = $this->create_page('/mod/assign/view.php', ['id' => 6]);
        $assignmentshell = $portalservice->get_shell_context_for_page($assignmentpage);
        $assignmentheader = $portalservice->get_header_context_for_page($assignmentpage);
        $this->assertNotNull($assignmentshell);
        $this->assertNotNull($assignmentheader);
        $this->assertSame(get_string('studentassignmentstitle', 'local_ulms_dashboard'), $assignmentheader['title']);
    }

    public function test_lecturer_deep_links_keep_lecturer_shell_and_headers(): void {
        $this->resetAfterTest(true);

        $lecturer = $this->getDataGenerator()->create_user();
        $this->assign_system_role($lecturer, 'editingteacher');
        $portalservice = new lecturer_portal_service();

        $this->setUser($lecturer);

        $discussionpage = $this->create_page('/mod/forum/discuss.php', ['d' => 1]);
        $discussionshell = $portalservice->get_shell_context_for_page($discussionpage);
        $discussionheader = $portalservice->get_header_context_for_page($discussionpage);
        $this->assertNotNull($discussionshell);
        $this->assertNotNull($discussionheader);
        $this->assertSame(get_string('lecturerannouncementstitle', 'local_ulms_dashboard'), $discussionheader['title']);

        $participantspage = $this->create_page('/user/index.php', ['id' => 2]);
        $participantsshell = $portalservice->get_shell_context_for_page($participantspage);
        $participantsheader = $portalservice->get_header_context_for_page($participantspage);
        $this->assertNotNull($participantsshell);
        $this->assertNotNull($participantsheader);
        $this->assertSame(get_string('lecturerstudentstitle', 'local_ulms_dashboard'), $participantsheader['title']);
    }

    /**
     * Creates a lightweight Moodle page for route-based portal tests.
     *
     * @param string $path
     * @return \moodle_page
     */
    private function create_page(string $path, array $params = []): \moodle_page {
        $page = new \moodle_page();
        $page->set_context(\context_system::instance());
        $page->set_url(new \moodle_url($path, $params));
        return $page;
    }

    /**
     * Assigns a role to a user at system level, creating the role if needed.
     *
     * @param \stdClass $user
     * @param string $roleshortname
     * @return void
     */
    private function assign_system_role(\stdClass $user, string $roleshortname): void {
        global $DB;

        $role = $DB->get_record('role', ['shortname' => $roleshortname], 'id');
        $roleid = $role ? (int)$role->id : create_role(ucfirst($roleshortname), $roleshortname, ucfirst($roleshortname) . ' role');
        role_assign($roleid, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    public function test_admin_portal_service_is_admin_portal_user_returns_false_for_siteadmin(): void {
        $this->resetAfterTest(true);

        $service = new admin_portal_service();
        $this->setAdminUser();

        $this->assertFalse(
            $service->is_admin_portal_user(),
            'admin_portal_service::is_admin_portal_user() MUST return false for site-level administrators (Super Admin owns them).'
        );
    }

    public function test_manager_user_still_receives_admin_shell_after_identity_refactor(): void {
        $this->resetAfterTest(true);

        $manager = $this->getDataGenerator()->create_user();
        $this->assign_system_role($manager, 'manager');
        $this->setUser($manager);

        $adminservice = new admin_portal_service();
        $sadminservice = new super_admin_portal_service();

        $this->assertTrue(
            $adminservice->is_admin_portal_user(),
            'Regular manager users must still be recognised as admin-portal users.'
        );
        $this->assertFalse(
            method_exists($sadminservice, 'is_super_admin_user') ? $sadminservice->is_super_admin_user() : false,
            'Regular manager users are NOT super admins.'
        );
    }

    public function test_admin_ssot_validator_reports_ok_for_canonical_route_triad(): void {
        $this->resetAfterTest(true);

        $adminservice = new admin_portal_service();

        $this->assertTrue(method_exists($adminservice, 'validate_canonical_route_consistency'));
        $this->assertTrue(method_exists($adminservice, 'get_canonical_admin_portal_routes_for_audit'));

        $routes = $adminservice->get_canonical_admin_portal_routes_for_audit();
        $validation = $adminservice->validate_canonical_route_consistency();

        $this->assertGreaterThanOrEqual(10, count($routes), 'Admin SSOT canonical routes must expose >= 10 distinct path keys.');
        $this->assertTrue(
            !empty($validation['ok']),
            'Admin SSOT validator: ' . (!$validation['ok'] ? implode('; ', $validation['errors'] ?? []) : 'passed')
        );
    }

    public function test_super_admin_ssot_validator_reports_ok_for_canonical_route_triad(): void {
        $this->resetAfterTest(true);

        $sadminservice = new super_admin_portal_service();

        $this->assertTrue(method_exists($sadminservice, 'validate_canonical_route_consistency'));
        $this->assertTrue(method_exists($sadminservice, 'get_canonical_super_admin_portal_routes_for_audit'));

        $routes = $sadminservice->get_canonical_super_admin_portal_routes_for_audit();
        $validation = $sadminservice->validate_canonical_route_consistency();

        $this->assertGreaterThanOrEqual(20, count($routes), 'Super Admin SSOT canonical routes must expose >= 20 distinct path keys.');
        $this->assertTrue(
            !empty($validation['ok']),
            'Super Admin SSOT validator: ' . (!$validation['ok'] ? implode('; ', $validation['errors'] ?? []) : 'passed')
        );
    }

    public function test_siteadmin_navigating_management_users_resolves_super_admin_shell_with_admin_section_and_4_navgroups(): void {
        $this->resetAfterTest(true);

        $this->setAdminUser();
        $page = $this->create_page('/management/users');
        $sadminservice = new super_admin_portal_service();

        $shellcontext = $sadminservice->get_shell_context_for_page($page);
        $headercontext = $sadminservice->get_header_context_for_page($page);

        $this->assertNotNull($shellcontext, 'Super Admin shell MUST resolve for siteadmin on management pages.');
        $this->assertNotNull($headercontext, 'Super Admin header MUST resolve for siteadmin on management pages.');

        $this->assertSame(
            get_string('superadminportaleyebrow', 'local_ulms_dashboard'),
            $shellcontext['eyebrow'],
            'Shell eyebrow on management.users MUST remain SUPER ADMIN (not Admin portal).'
        );

        $this->assertSame(
            get_string('superadminportalshelltitle', 'local_ulms_dashboard'),
            $shellcontext['portalname'],
            'Shell portalname on management.users MUST remain SUPER ADMIN.'
        );

        $this->assertSame(
            4,
            count($shellcontext['navgroups'] ?? []),
            'Super Admin shell on management feature route MUST include 4 nav groups (Overview + Governance + System + Admin Portal subsection).'
        );

        $this->assertSame(
            get_string('adminusermanagementlink', 'local_ulms_dashboard'),
            $headercontext['title'],
            'Header title for management.users correctly reflects admin-owned content despite SUPER ADMIN chrome.'
        );

        $groupheadings = array_map(
            static fn(array $g): string => (string)($g['heading'] ?? ''),
            $shellcontext['navgroups'] ?? []
        );
        $this->assertContains(
            get_string('adminportaltitle', 'local_ulms_auth'),
            $groupheadings,
            'Admin Portal subsection (4th nav group heading) must be present inside Super Admin shell.'
        );
    }

    public function test_super_admin_audit_identity_preservation_helper_returns_ok_for_management_routes(): void {
        $this->resetAfterTest(true);

        $this->setAdminUser();
        $sadminservice = new super_admin_portal_service();

        if (!method_exists($sadminservice, 'audit_super_admin_admin_identity_preservation')) {
            $this->markTestSkipped('Identity preservation audit helper not yet implemented.');
        }

        $auditusers = $sadminservice->audit_super_admin_admin_identity_preservation('management.users');
        $auditdashboard = $sadminservice->audit_super_admin_admin_identity_preservation('management.dashboard');

        $this->assertTrue(
            !empty($auditusers['ok']),
            'management.users audit: ' . ($auditusers['detail'] ?? 'failed')
        );
        $this->assertTrue(
            !empty($auditdashboard['ok']),
            'management.dashboard audit: ' . ($auditdashboard['detail'] ?? 'failed')
        );
    }
}
