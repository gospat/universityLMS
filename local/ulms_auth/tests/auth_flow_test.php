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

namespace local_ulms_auth;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../config.php');
global $CFG;
require_once($CFG->dirroot . '/local/ulms_auth/lib.php');

/**
 * Tests clean activation and password reset helpers.
 *
 * @coversNothing
 */
final class auth_flow_test extends \advanced_testcase {
    public function test_clean_auth_url_helpers_use_public_routes(): void {
        $activationurl = local_ulms_auth_get_activation_url('abc123');
        $reseturl = local_ulms_auth_get_password_reset_token_url(null, 'xyz789');
        $service = new \local_ulms_auth\local\service\landing_page_service();

        $this->assertStringEndsWith('/sign-in/activate/', $activationurl->get_path());
        $this->assertSame('token=abc123', $activationurl->get_query_string(false));
        $this->assertStringEndsWith('/reset-password/', $reseturl->get_path());
        $this->assertSame('token=xyz789', $reseturl->get_query_string(false));
        $this->assertStringEndsWith('/student/login/', $service->get_login_url_for_portal('student')->get_path());
        $this->assertStringEndsWith('/lecturer/login/', $service->get_login_url_for_portal('lecturer')->get_path());
        $this->assertStringEndsWith('/management/login/', $service->get_login_url_for_portal('administrator')->get_path());
        $this->assertStringEndsWith('/super-admin/login/', $service->get_login_url_for_portal('superadmin')->get_path());
    }

    public function test_issue_password_token_replaces_existing_tokens(): void {
        global $DB;

        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user([
            'auth' => 'manual',
            'confirmed' => 1,
            'email' => 'resettable@example.com',
        ]);

        $first = local_ulms_auth_issue_password_token($user);
        $second = local_ulms_auth_issue_password_token($user);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first->token, $second->token);
        $this->assertSame(1, $DB->count_records('user_password_resets', ['userid' => $user->id]));
    }

    public function test_password_token_state_detects_expired_tokens(): void {
        global $DB, $CFG;

        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user([
            'auth' => 'manual',
            'confirmed' => 1,
            'email' => 'expired@example.com',
        ]);

        $record = (object)[
            'userid' => $user->id,
            'token' => random_string(32),
            'timerequested' => time() - ((int)$CFG->pwresettime + 5),
        ];
        $DB->insert_record('user_password_resets', $record);

        $state = local_ulms_auth_get_password_token_state($record->token);

        $this->assertSame('expired', $state['status']);
        $this->assertNotNull($state['user']);
    }

    public function test_complete_password_token_updates_password_and_clears_preferences(): void {
        global $DB;

        $this->resetAfterTest(true);
        $initialpassword = $this->generate_strong_test_password();
        $newpassword = $this->generate_strong_test_password();
        $user = $this->getDataGenerator()->create_user([
            'auth' => 'manual',
            'confirmed' => 1,
            'email' => 'complete@example.com',
            'password' => $initialpassword,
        ]);
        set_user_preference('auth_forcepasswordchange', 1, $user);
        set_user_preference('create_password', 1, $user);

        $resetrecord = local_ulms_auth_issue_password_token($user);
        $state = local_ulms_auth_get_password_token_state($resetrecord->token);

        $this->assertSame('valid', $state['status']);
        local_ulms_auth_complete_password_token($state['user'], $newpassword, true, false);

        $reason = 0;
        $authenticated = authenticate_user_login($user->username, $newpassword, false, $reason, false, false);

        $this->assertNotFalse($authenticated);
        $this->assertSame(0, $reason);
        $this->assertFalse($DB->record_exists('user_password_resets', ['userid' => $user->id]));
        $this->assertFalse($DB->record_exists('user_preferences', ['userid' => $user->id, 'name' => 'auth_forcepasswordchange']));
        $this->assertFalse($DB->record_exists('user_preferences', ['userid' => $user->id, 'name' => 'create_password']));
    }

    public function test_login_error_messages_cover_invalid_suspended_and_activation_required_states(): void {
        $this->resetAfterTest(true);

        $activationuser = $this->getDataGenerator()->create_user([
            'auth' => 'manual',
            'confirmed' => 1,
            'email' => 'activation-required@example.com',
        ]);
        set_user_preference('create_password', 1, $activationuser);

        $suspendeduser = $this->getDataGenerator()->create_user([
            'auth' => 'manual',
            'confirmed' => 1,
            'suspended' => 1,
            'email' => 'suspended@example.com',
        ]);

        $this->assertSame(
            get_string('portallogininvalid', 'local_ulms_auth'),
            local_ulms_auth_get_login_error_message('missing-user', AUTH_LOGIN_FAILED)
        );
        $this->assertSame(
            get_string('portalloginactivationrequired', 'local_ulms_auth'),
            local_ulms_auth_get_login_error_message($activationuser->username, AUTH_LOGIN_FAILED)
        );
        $this->assertSame(
            get_string('portalloginsuspended', 'local_ulms_auth'),
            local_ulms_auth_get_login_error_message($suspendeduser->username, AUTH_LOGIN_SUSPENDED, $suspendeduser)
        );
    }

    public function test_student_lecturer_admin_and_super_admin_logins_map_to_expected_portals(): void {
        $this->resetAfterTest(true);

        $service = new \local_ulms_auth\local\service\landing_page_service();

        $studentsecret = $this->generate_strong_test_password();
        $lecturersecret = $this->generate_strong_test_password();
        $managersecret = $this->generate_strong_test_password();
        $superadminsecret = $this->generate_strong_test_password();

        $student = $this->create_login_user_with_role('student', $studentsecret);
        $lecturer = $this->create_login_user_with_role('editingteacher', $lecturersecret);
        $manager = $this->create_login_user_with_role('manager', $managersecret);
        $superadmin = get_admin();
        update_internal_user_password($superadmin, $superadminsecret);

        $studentreason = 0;
        $lecturerreason = 0;
        $managerreason = 0;
        $superadminreason = 0;

        $authenticatedstudent = authenticate_user_login($student->username, $studentsecret, false, $studentreason, false, false);
        $authenticatedlecturer = authenticate_user_login($lecturer->username, $lecturersecret, false, $lecturerreason, false, false);
        $authenticatedmanager = authenticate_user_login($manager->username, $managersecret, false, $managerreason, false, false);
        $authenticatedsuperadmin = authenticate_user_login($superadmin->username, $superadminsecret, false, $superadminreason, false, false);

        $this->assertNotFalse($authenticatedstudent);
        $this->assertNotFalse($authenticatedlecturer);
        $this->assertNotFalse($authenticatedmanager);
        $this->assertNotFalse($authenticatedsuperadmin);
        $this->assertSame(0, $studentreason);
        $this->assertSame(0, $lecturerreason);
        $this->assertSame(0, $managerreason);
        $this->assertSame(0, $superadminreason);
        $this->assertTrue($service->user_matches_portal($authenticatedstudent, 'student'));
        $this->assertTrue($service->user_matches_portal($authenticatedlecturer, 'lecturer'));
        $this->assertTrue($service->user_matches_portal($authenticatedmanager, 'administrator'));
        $this->assertTrue($service->user_matches_portal($authenticatedsuperadmin, 'superadmin'));
        $this->assertStringEndsWith('/student/', $service->get_dashboard_url_for_portal('student')->get_path());
        $this->assertStringEndsWith('/lecturer/', $service->get_dashboard_url_for_portal('lecturer')->get_path());
        $this->assertStringEndsWith('/management/', $service->get_dashboard_url_for_portal('administrator')->get_path());
        $this->assertStringEndsWith('/super-admin/', $service->get_dashboard_url_for_portal('superadmin')->get_path());
    }

    /**
     * Creates an active manual-login user and assigns a system role.
     *
     * @param string $roleshortname
     * @param string $password
     * @return \stdClass
     */
    private function create_login_user_with_role(string $roleshortname, string $password): \stdClass {
        global $DB;

        $user = $this->getDataGenerator()->create_user([
            'auth' => 'manual',
            'confirmed' => 1,
            'password' => $password,
            'email' => $roleshortname . '@example.com',
        ]);

        $role = $DB->get_record('role', ['shortname' => $roleshortname], 'id');
        $roleid = $role ? (int)$role->id : create_role(ucfirst($roleshortname), $roleshortname, ucfirst($roleshortname) . ' role');
        role_assign($roleid, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        return $user;
    }

    /**
     * Generates a strong runtime-only password for auth tests.
     *
     * @return string
     */
    private function generate_strong_test_password(): string {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $special = '!@#$%^&*';
        $pool = $upper . $lower . $digits . $special;

        $characters = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $special[random_int(0, strlen($special) - 1)],
        ];

        for ($i = 0; $i < 12; $i++) {
            $characters[] = $pool[random_int(0, strlen($pool) - 1)];
        }

        shuffle($characters);
        return implode('', $characters);
    }
}
