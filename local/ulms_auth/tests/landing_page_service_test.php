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

use advanced_testcase;

/**
 * Tests role-family routing for ULMS portal authentication.
 */
final class landing_page_service_test extends advanced_testcase {
    public function test_portal_cards_expose_four_separate_role_interfaces(): void {
        $this->resetAfterTest(true);

        $service = new landing_page_service();
        $cards = $service->get_portal_cards();

        $this->assertSame(['student', 'lecturer', 'administrator', 'superadmin'], array_column($cards, 'key'));

        $paths = array_map(
            static fn(array $card): string => (string)parse_url($card['loginurl'], PHP_URL_PATH),
            $cards
        );

        $this->assertCount(4, $paths);
        $this->assertStringEndsWith($service->get_path_for_route('student.login'), $paths[0]);
        $this->assertStringEndsWith($service->get_path_for_route('lecturer.login'), $paths[1]);
        $this->assertStringEndsWith($service->get_path_for_route('management.login'), $paths[2]);
        $this->assertStringEndsWith($service->get_path_for_route('superadmin.login'), $paths[3]);
    }

    public function test_public_activation_route_uses_clean_path(): void {
        $this->resetAfterTest(true);

        $service = new landing_page_service();
        $url = $service->get_url_for_route('public.activate', ['token' => 'abc123']);

        $this->assertStringEndsWith('/sign-in/activate/', $url->get_path());
        $this->assertSame('token=abc123', $url->get_query_string(false));
    }

    public function test_canonical_auth_routes_use_trailing_slashes(): void {
        $this->resetAfterTest(true);

        $service = new landing_page_service();

        $this->assertSame('/sign-in/', $service->get_path_for_route('public.landing'));
        $this->assertSame('/sign-in/activate/', $service->get_path_for_route('public.activate'));
        $this->assertSame('/reset-password/', $service->get_path_for_route('public.passwordreset'));
        $this->assertSame('/student/login/', $service->get_path_for_route('student.login'));
        $this->assertSame('/lecturer/login/', $service->get_path_for_route('lecturer.login'));
        $this->assertSame('/management/login/', $service->get_path_for_route('management.login'));
        $this->assertSame('/management/users/create/', $service->get_path_for_route('management.usercreate'));
        $this->assertSame('/management/academics/', $service->get_path_for_route('management.academics'));
        $this->assertSame('/management/academics/manage/', $service->get_path_for_route('management.academicsmanage'));
        $this->assertSame('/management/academics/import/', $service->get_path_for_route('management.academicsimport'));
        $this->assertSame('/management/academics/course-mappings/', $service->get_path_for_route('management.academicsmappings'));
        $this->assertSame('/management/academics/reports/', $service->get_path_for_route('management.academicsreports'));
        $this->assertSame('/super-admin/login/', $service->get_path_for_route('superadmin.login'));
    }

    public function test_normalise_path_strips_the_moodle_base_path_when_present(): void {
        $this->resetAfterTest(true);

        global $CFG;

        $service = new landing_page_service();
        $basepath = (string)(parse_url($CFG->wwwroot, PHP_URL_PATH) ?? '');
        $basepath = rtrim($basepath, '/');

        if ($basepath === '') {
            $this->assertSame('/management/users', $service->normalise_path('/management/users/'));
            $this->assertSame('/super-admin/system', $service->normalise_path('/super-admin/system/'));
            return;
        }

        $this->assertSame('/management/users', $service->normalise_path($basepath . '/management/users/'));
        $this->assertSame('/super-admin/system', $service->normalise_path($basepath . '/super-admin/system/'));
    }

    public function test_management_user_create_route_uses_canonical_trailing_slash_url(): void {
        $this->resetAfterTest(true);

        $service = new landing_page_service();
        $url = $service->get_url_for_route('management.usercreate');

        $this->assertStringEndsWith('/management/users/create/', $url->get_path());
    }

    public function test_academics_management_routes_use_canonical_trailing_slash_urls(): void {
        $this->resetAfterTest(true);

        $service = new landing_page_service();

        $manageurl = $service->get_url_for_route('management.academicsmanage', ['entity' => 'faculties']);
        $importurl = $service->get_url_for_route('management.academicsimport', ['entity' => 'faculties']);
        $mappingsurl = $service->get_url_for_route('management.academicsmappings');
        $reportsurl = $service->get_url_for_route('management.academicsreports');

        $this->assertStringEndsWith('/management/academics/manage/', $manageurl->get_path());
        $this->assertSame('entity=faculties', $manageurl->get_query_string(false));
        $this->assertStringEndsWith('/management/academics/import/', $importurl->get_path());
        $this->assertStringEndsWith('/management/academics/course-mappings/', $mappingsurl->get_path());
        $this->assertStringEndsWith('/management/academics/reports/', $reportsurl->get_path());
    }

    public function test_site_admin_accounts_route_to_the_super_admin_portal(): void {
        $this->resetAfterTest(true);

        $service = new landing_page_service();
        $user = get_admin();

        $this->assertSame('superadmin', $service->get_matching_portal_for_user($user));
        $this->assertTrue($service->user_matches_portal($user, 'superadmin'));
        $this->assertFalse($service->user_matches_portal($user, 'administrator'));
        $this->assertSame('superadmin', $service->get_dashboard_key_for_role_shortname($service->get_role_shortname_for_user($user)));
    }

    public function test_student_accounts_only_match_the_student_portal(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $this->assign_system_role($user, 'student');

        $service = new landing_page_service();

        $this->assertSame('student', $service->get_matching_portal_for_user($user));
        $this->assertTrue($service->user_matches_portal($user, 'student'));
        $this->assertFalse($service->user_matches_portal($user, 'lecturer'));
        $this->assertFalse($service->user_matches_portal($user, 'administrator'));
    }

    public function test_lecturer_accounts_only_match_the_lecturer_portal(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $this->assign_system_role($user, 'editingteacher');

        $service = new landing_page_service();

        $this->assertSame('lecturer', $service->get_matching_portal_for_user($user));
        $this->assertTrue($service->user_matches_portal($user, 'lecturer'));
        $this->assertFalse($service->user_matches_portal($user, 'student'));
        $this->assertFalse($service->user_matches_portal($user, 'administrator'));
    }

    public function test_manager_accounts_route_to_the_admin_portal(): void {
        $this->resetAfterTest(true);

        $service = new landing_page_service();
        $user = $this->getDataGenerator()->create_user();
        $this->assign_system_role($user, 'manager');

        $this->assertSame('administrator', $service->get_matching_portal_for_user($user));
        $this->assertTrue($service->user_matches_portal($user, 'administrator'));
        $this->assertFalse($service->user_matches_portal($user, 'lecturer'));
        $this->assertFalse($service->user_matches_portal($user, 'student'));
    }

    public function test_custom_admin_accounts_route_to_the_admin_portal(): void {
        $this->resetAfterTest(true);

        $service = new landing_page_service();
        $user = $this->getDataGenerator()->create_user();
        $this->assign_system_role($user, 'ictadmin');

        $this->assertSame('administrator', $service->get_matching_portal_for_user($user));
        $this->assertTrue($service->user_matches_portal($user, 'administrator'));
        $this->assertFalse($service->user_matches_portal($user, 'lecturer'));
        $this->assertFalse($service->user_matches_portal($user, 'student'));
    }

    public function test_admin_routing_priority_beats_other_role_assignments(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $this->assign_system_role($user, 'editingteacher');
        $this->assign_system_role($user, 'manager');

        $service = new landing_page_service();

        $this->assertSame('administrator', $service->get_matching_portal_for_user($user));
        $this->assertSame('admin', $service->get_dashboard_key_for_role_shortname($service->get_role_shortname_for_user($user)));
    }

    public function test_custom_admin_routing_priority_beats_lecturer_and_student_roles(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $this->assign_system_role($user, 'student');
        $this->assign_system_role($user, 'facultyadmin');

        $service = new landing_page_service();

        $this->assertSame('administrator', $service->get_matching_portal_for_user($user));
        $this->assertSame('admin', $service->get_dashboard_key_for_role_shortname($service->get_role_shortname_for_user($user)));
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
}
