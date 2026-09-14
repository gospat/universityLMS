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

use local_ulms_dashboard\local\service\user_management_service;
use local_ulms_dashboard\local\service\user_provisioning_service;
use local_ulms_mail\local\service\resend_mail_service;

require_once(__DIR__ . '/../../../config.php');

final class user_management_service_test_mailer extends resend_mail_service {
    /** @var array{success: bool, messageid: ?string, statuscode: ?int, error: string} */
    private array $result;

    /** @var array<string, mixed>|null */
    private ?array $lastmessage = null;

    public function __construct(array $result) {
        $this->result = $result;
    }

    public function send_transactional_email(array $message): array {
        $this->lastmessage = $message;
        return $this->result;
    }

    public function get_last_message(): ?array {
        return $this->lastmessage;
    }
}

final class user_management_service_testable_provisioning_service extends user_provisioning_service {
    private resend_mail_service $mailservice;

    public function __construct(resend_mail_service $mailservice) {
        $this->mailservice = $mailservice;
    }

    protected function create_mail_service(): resend_mail_service {
        return $this->mailservice;
    }
}

final class user_management_service_test_stub_provisioning_service extends user_provisioning_service {
    /** @var array<string, mixed>|null */
    private ?array $result;

    private ?\Throwable $exception;

    /**
     * @param array<string, mixed>|null $result
     * @param \Throwable|null $exception
     */
    public function __construct(?array $result = null, ?\Throwable $exception = null) {
        $this->result = $result;
        $this->exception = $exception;
    }

    public function create_single_user(string $targetrole, array $data): array {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        if ($this->result === null) {
            throw new \RuntimeException('Missing stub provisioning result');
        }

        return $this->result;
    }
}

final class user_management_service_testable_service extends user_management_service {
    public function __construct(
        user_provisioning_service $provisioningservice,
        ?\local_ulms_academics\local\service\academic_structure_service $academicservice,
        resend_mail_service $mailservice
    ) {
        parent::__construct($provisioningservice, $academicservice, $mailservice);
    }
}

/**
 * Tests ULMS admin user management service flows.
 *
 * @covers \local_ulms_dashboard\local\service\user_management_service
 */
final class user_management_service_test extends \advanced_testcase {
    /**
     * Suppress third-party PHP 8.4 deprecations during a focused assertion.
     *
     * Moodle 4.5 vendor dependencies still emit deprecation notices from DI and
     * Mustache internals under PHP 8.4. These notices are unrelated to the ULMS
     * behavior under test, but PHPUnit treats the printed output as a risky test.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function run_without_deprecation_output(callable $callback): mixed {
        $previousreporting = error_reporting();
        error_reporting($previousreporting & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        try {
            return $callback();
        } finally {
            error_reporting($previousreporting);
        }
    }

    private function configure_resend_transport(): void {
        global $CFG;

        $CFG->ulmsmailtransport = 'resend';
        $CFG->resendapikey = 're_test_secret';
        $CFG->resendfromemail = 'no-reply@example.com';
        $CFG->resendfromname = 'ULMS Mailer';
        $CFG->supportname = 'ULMS Mailer';
        $CFG->supportemail = 'support@example.com';
        $CFG->ulmsreplyto = 'support@example.com';
    }

    private function ensure_user_management_tables(): void {
        global $DB;

        $dbman = $DB->get_manager();

        $profiletable = new \xmldb_table('local_ulms_user_profile');
        if (!$dbman->table_exists($profiletable)) {
            $profiletable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $profiletable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $profiletable->add_field('facultyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $profiletable->add_field('departmentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $profiletable->add_field('programmeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $profiletable->add_field('studylevel', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, '');
            $profiletable->add_field('staffid', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
            $profiletable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $profiletable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $profiletable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $profiletable->add_index('userid_uix', XMLDB_INDEX_UNIQUE, ['userid']);
            $dbman->create_table($profiletable);
        }

        $logtable = new \xmldb_table('local_ulms_user_management_log');
        if (!$dbman->table_exists($logtable)) {
            $logtable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $logtable->add_field('actorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $logtable->add_field('targetuserid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $logtable->add_field('action', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $logtable->add_field('status', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, '');
            $logtable->add_field('message', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $logtable->add_field('detailsjson', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $logtable->add_field('ipaddress', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $logtable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $logtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($logtable);
        }
    }

    private function create_service(resend_mail_service $mailservice): user_management_service_testable_service {
        return new user_management_service_testable_service(
            new user_management_service_testable_provisioning_service($mailservice),
            class_exists(\local_ulms_academics\local\service\academic_structure_service::class)
                ? new \local_ulms_academics\local\service\academic_structure_service()
                : null,
            $mailservice
        );
    }

    private function create_service_with_provisioning(
        user_provisioning_service $provisioningservice,
        ?resend_mail_service $mailservice = null
    ): user_management_service_testable_service {
        $mailer = $mailservice ?? new user_management_service_test_mailer([
            'success' => true,
            'messageid' => 'email_1',
            'statuscode' => 200,
            'error' => '',
        ]);

        return new user_management_service_testable_service(
            $provisioningservice,
            class_exists(\local_ulms_academics\local\service\academic_structure_service::class)
                ? new \local_ulms_academics\local\service\academic_structure_service()
                : null,
            $mailer
        );
    }

    private function get_valid_create_data(array $overrides = []): array {
        return array_merge([
            'firstname' => 'John',
            'middlename' => 'T',
            'lastname' => 'Doe',
            'username' => 'john.doe',
            'email' => 'john.doe@example.com',
            'idnumber' => 'STU-UM-001',
            'role' => 'student',
            'status' => 'active',
            'facultyid' => 0,
            'departmentid' => 0,
            'programmeid' => 0,
            'studylevel' => '100',
            'staffid' => '',
            'password' => 'ValidPassword123!',
            'confirmpassword' => 'ValidPassword123!',
        ], $overrides);
    }

    public function test_create_user_persists_profile_and_audit_log(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();
        $this->ensure_user_management_tables();

        $service = $this->create_service(new user_management_service_test_mailer([
            'success' => true,
            'messageid' => 'email_1',
            'statuscode' => 200,
            'error' => '',
        ]));

        $result = $this->run_without_deprecation_output(
            fn() => $service->create_user($this->get_valid_create_data())
        );

        $this->assertTrue($result['success']);
        $user = $DB->get_record('user', ['username' => 'john.doe'], '*', MUST_EXIST);
        $profile = $DB->get_record('local_ulms_user_profile', ['userid' => $user->id], '*', MUST_EXIST);
        $this->assertSame('100', $profile->studylevel);
        $this->assertTrue($DB->record_exists('local_ulms_user_management_log', [
            'targetuserid' => $user->id,
            'action' => 'USER_CREATED',
        ]));
    }

    public function test_create_user_returns_validation_errors(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();
        $this->ensure_user_management_tables();

        $service = $this->create_service(new user_management_service_test_mailer([
            'success' => true,
            'messageid' => 'email_1',
            'statuscode' => 200,
            'error' => '',
        ]));

        $result = $service->create_user($this->get_valid_create_data([
            'firstname' => '',
            'email' => 'not-an-email',
        ]));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('firstname', $result['errors']);
        $this->assertArrayHasKey('email', $result['errors']);
    }

    public function test_create_user_returns_success_message_when_email_is_sent(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();
        $this->ensure_user_management_tables();

        $service = $this->create_service(new user_management_service_test_mailer([
            'success' => true,
            'messageid' => 'email_1',
            'statuscode' => 200,
            'error' => '',
        ]));

        $result = $service->create_user($this->get_valid_create_data([
            'username' => 'john.success',
            'email' => 'john.success@example.com',
            'idnumber' => 'STU-UM-003',
        ]));

        $this->assertTrue($result['success']);
        $this->assertFalse($result['warning']);
        $this->assertStringContainsString('onboarding email was sent', $result['message']);
    }

    public function test_create_user_returns_warning_message_when_email_send_fails(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();
        $this->ensure_user_management_tables();

        $service = $this->create_service(new user_management_service_test_mailer([
            'success' => false,
            'messageid' => null,
            'statuscode' => 500,
            'error' => 'Mail failure',
        ]));

        $result = $service->create_user($this->get_valid_create_data([
            'username' => 'john.warning',
            'email' => 'john.warning@example.com',
            'idnumber' => 'STU-UM-004',
        ]));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['warning']);
        $this->assertStringContainsString('could not be sent', $result['message']);
    }

    public function test_create_user_returns_unexpected_error_when_provisioning_throws(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();
        $this->ensure_user_management_tables();

        $service = $this->create_service_with_provisioning(
            new user_management_service_test_stub_provisioning_service(
                null,
                new \RuntimeException('Production transport failure')
            )
        );

        $result = $service->create_user($this->get_valid_create_data([
            'username' => 'john.failure',
            'email' => 'john.failure@example.com',
            'idnumber' => 'STU-UM-005',
        ]));

        $this->assertFalse($result['success']);
        $this->assertFalse($result['warning']);
        $this->assertSame(
            get_string('provisioningcreateunexpectederror', 'local_ulms_dashboard'),
            $result['message']
        );
        $this->assertSame(
            get_string('provisioningcreateunexpectederror', 'local_ulms_dashboard'),
            $result['errors']['general']
        );
    }

    public function test_update_user_changes_role_and_status(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();
        $this->ensure_user_management_tables();

        $service = $this->create_service(new user_management_service_test_mailer([
            'success' => true,
            'messageid' => 'email_1',
            'statuscode' => 200,
            'error' => '',
        ]));

        $create = $service->create_user($this->get_valid_create_data());
        $this->assertTrue($create['success']);

        $result = $service->update_user((int)$create['userid'], [
            'firstname' => 'John',
            'middlename' => '',
            'lastname' => 'Doe',
            'username' => 'john.doe',
            'email' => 'john.doe@example.com',
            'idnumber' => 'LEC-UM-001',
            'role' => 'lecturer',
            'status' => 'suspended',
            'facultyid' => 0,
            'departmentid' => 0,
            'programmeid' => 0,
            'studylevel' => '',
            'staffid' => 'STAFF-200',
        ]);

        $this->assertTrue($result['success']);
        $user = $service->get_user_details((int)$create['userid']);
        $this->assertSame('lecturer', $user['rolekey']);
        $this->assertSame(1, $user['suspended']);
        $this->assertTrue($DB->record_exists('local_ulms_user_management_log', [
            'targetuserid' => (int)$create['userid'],
            'action' => 'ROLE_CHANGED',
        ]));
    }

    public function test_get_user_listing_returns_created_user(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();
        $this->ensure_user_management_tables();

        $service = $this->create_service(new user_management_service_test_mailer([
            'success' => true,
            'messageid' => 'email_1',
            'statuscode' => 200,
            'error' => '',
        ]));

        $service->create_user($this->get_valid_create_data());
        $listing = $service->get_user_listing([
            'search' => 'john.doe@example.com',
            'role' => 'student',
            'status' => 'active',
            'page' => 0,
            'perpage' => 25,
            'sort' => 'name',
            'dir' => 'ASC',
        ]);

        $this->assertGreaterThanOrEqual(1, $listing['total']);
        $this->assertNotEmpty($listing['rows']);
        $this->assertSame('john.doe@example.com', $listing['rows'][0]['email']);
    }

    public function test_get_user_listing_matches_multi_term_name_search(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();
        $this->ensure_user_management_tables();

        $service = $this->create_service(new user_management_service_test_mailer([
            'success' => true,
            'messageid' => 'email_1',
            'statuscode' => 200,
            'error' => '',
        ]));

        $service->create_user($this->get_valid_create_data());
        $listing = $service->get_user_listing([
            'search' => 'John Doe',
            'role' => 'student',
            'status' => 'active',
            'page' => 0,
            'perpage' => 25,
            'sort' => 'name',
            'dir' => 'ASC',
        ]);

        $this->assertSame(1, $listing['total']);
        $this->assertSame('john.doe', $listing['rows'][0]['username']);
    }

    public function test_custom_admin_role_is_classified_as_admin(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();
        $this->ensure_user_management_tables();

        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Scoped',
            'lastname' => 'Admin',
            'email' => 'scoped.admin@example.com',
            'username' => 'scoped.admin',
        ]);
        $systemcontext = \context_system::instance();
        $coursecreator = $DB->get_record('role', ['shortname' => 'coursecreator'], 'id', MUST_EXIST);
        role_assign((int)$coursecreator->id, (int)$user->id, $systemcontext->id);

        $service = $this->create_service(new user_management_service_test_mailer([
            'success' => true,
            'messageid' => 'email_1',
            'statuscode' => 200,
            'error' => '',
        ]));

        $details = $service->get_user_details((int)$user->id);

        $this->assertSame('admin', $details['rolekey']);
    }

    public function test_duplicate_email_is_rejected(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();
        $this->ensure_user_management_tables();

        $service = $this->create_service(new user_management_service_test_mailer([
            'success' => true,
            'messageid' => 'email_1',
            'statuscode' => 200,
            'error' => '',
        ]));

        $first = $service->create_user($this->get_valid_create_data());
        $second = $service->create_user($this->get_valid_create_data([
            'username' => 'john.duplicate',
            'idnumber' => 'STU-UM-002',
        ]));

        $this->assertTrue($first['success']);
        $this->assertFalse($second['success']);
        $this->assertArrayHasKey('email', $second['errors']);
    }

    public function test_update_user_removes_existing_admin_role_assignments(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();
        $this->ensure_user_management_tables();

        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Legacy',
            'lastname' => 'Admin',
            'email' => 'legacy.admin@example.com',
            'username' => 'legacy.admin',
            'idnumber' => 'ADM-LEGACY-001',
        ]);
        $systemcontext = \context_system::instance();
        $coursecreator = $DB->get_record('role', ['shortname' => 'coursecreator'], 'id', MUST_EXIST);
        $student = $DB->get_record('role', ['shortname' => 'student'], 'id', MUST_EXIST);
        role_assign((int)$coursecreator->id, (int)$user->id, $systemcontext->id);

        $service = $this->create_service(new user_management_service_test_mailer([
            'success' => true,
            'messageid' => 'email_1',
            'statuscode' => 200,
            'error' => '',
        ]));

        $result = $service->update_user((int)$user->id, [
            'firstname' => 'Legacy',
            'middlename' => '',
            'lastname' => 'Admin',
            'username' => 'legacy.admin',
            'email' => 'legacy.admin@example.com',
            'idnumber' => 'ADM-LEGACY-001',
            'role' => 'student',
            'status' => 'active',
            'facultyid' => 0,
            'departmentid' => 0,
            'programmeid' => 0,
            'studylevel' => '',
            'staffid' => '',
        ]);

        $this->assertTrue($result['success']);
        $this->assertFalse(user_has_role_assignment((int)$user->id, (int)$coursecreator->id, $systemcontext->id));
        $this->assertTrue(user_has_role_assignment((int)$user->id, (int)$student->id, $systemcontext->id));
        $this->assertSame('student', $service->get_user_details((int)$user->id)['rolekey']);
    }

    public function test_unauthorized_create_is_blocked(): void {
        $this->resetAfterTest(true);
        $this->setUser($this->getDataGenerator()->create_user());
        $this->configure_resend_transport();
        $this->ensure_user_management_tables();

        $service = $this->create_service(new user_management_service_test_mailer([
            'success' => true,
            'messageid' => 'email_1',
            'statuscode' => 200,
            'error' => '',
        ]));

        $this->expectException(\required_capability_exception::class);
        $service->create_user($this->get_valid_create_data());
    }

    public function test_suspend_last_admin_is_blocked(): void {
        global $USER;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();
        $this->ensure_user_management_tables();

        $service = $this->create_service(new user_management_service_test_mailer([
            'success' => true,
            'messageid' => 'email_1',
            'statuscode' => 200,
            'error' => '',
        ]));

        $this->expectException(\moodle_exception::class);
        $service->suspend_user((int)$USER->id);
    }

    public function test_suspend_and_unsuspend_non_last_admin_succeeds(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();
        $this->ensure_user_management_tables();

        $service = $this->create_service(new user_management_service_test_mailer([
            'success' => true,
            'messageid' => 'email_1',
            'statuscode' => 200,
            'error' => '',
        ]));

        $create = $service->create_user($this->get_valid_create_data([
            'role' => 'admin',
            'username' => 'jane.admin',
            'email' => 'jane.admin@example.com',
            'idnumber' => 'ADM-UM-001',
        ]));

        $suspend = $service->suspend_user((int)$create['userid']);
        $unsuspend = $service->unsuspend_user((int)$create['userid']);

        $this->assertTrue($suspend['success']);
        $this->assertTrue($unsuspend['success']);
    }

    public function test_delete_non_last_admin_succeeds(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();
        $this->ensure_user_management_tables();

        $service = $this->create_service(new user_management_service_test_mailer([
            'success' => true,
            'messageid' => 'email_1',
            'statuscode' => 200,
            'error' => '',
        ]));

        $create = $service->create_user($this->get_valid_create_data([
            'role' => 'admin',
            'username' => 'delete.admin',
            'email' => 'delete.admin@example.com',
            'idnumber' => 'ADM-UM-002',
        ]));

        $result = $service->delete_user_account((int)$create['userid']);

        $this->assertTrue($result['success']);
        $deleted = $DB->get_record('user', ['id' => (int)$create['userid']], '*', MUST_EXIST);
        $this->assertSame(1, (int)$deleted->deleted);
    }

    public function test_resend_welcome_email_returns_warning_when_mail_fails(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();
        $this->ensure_user_management_tables();

        $service = $this->create_service(new user_management_service_test_mailer([
            'success' => false,
            'messageid' => null,
            'statuscode' => 500,
            'error' => 'Mail failure',
        ]));

        $create = $service->create_user($this->get_valid_create_data());
        $result = $service->resend_welcome_email((int)$create['userid']);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['warning']);
    }

    public function test_resend_welcome_email_uses_clean_activation_url(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();
        $this->ensure_user_management_tables();

        $mailer = new user_management_service_test_mailer([
            'success' => true,
            'messageid' => 'email_2',
            'statuscode' => 200,
            'error' => '',
        ]);
        $service = $this->create_service($mailer);

        $create = $service->create_user($this->get_valid_create_data([
            'username' => 'clean.welcome',
            'email' => 'clean.welcome@example.com',
            'idnumber' => 'STU-UM-CLN-1',
        ]));
        $result = $service->resend_welcome_email((int)$create['userid']);

        $this->assertTrue($result['success']);
        $message = $mailer->get_last_message();
        $this->assertNotNull($message);
        $this->assertStringContainsString('/sign-in/activate/?token=', (string)($message['text'] ?? ''));
    }

    public function test_send_password_reset_email_uses_clean_reset_url(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();
        $this->ensure_user_management_tables();

        $mailer = new user_management_service_test_mailer([
            'success' => true,
            'messageid' => 'email_3',
            'statuscode' => 200,
            'error' => '',
        ]);
        $service = $this->create_service($mailer);

        $create = $service->create_user($this->get_valid_create_data([
            'username' => 'clean.reset',
            'email' => 'clean.reset@example.com',
            'idnumber' => 'STU-UM-CLN-2',
        ]));
        $result = $service->send_password_reset_email((int)$create['userid']);

        $this->assertTrue($result['success']);
        $message = $mailer->get_last_message();
        $this->assertNotNull($message);
        $this->assertStringContainsString('/reset-password/?token=', (string)($message['text'] ?? ''));
    }
}
