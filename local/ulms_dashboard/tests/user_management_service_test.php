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
    /** @var array{facultyid: int, departmentid: int, programmeid: int}|null */
    private ?array $academicfixtureids = null;

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

        $this->seed_study_levels();
    }

    /**
     * Seeds the study levels the shared create fixture refers to.
     *
     * local_ulms_levels is declared by the dashboard install.xml, so the table exists in
     * the test database but carries no rows. A submitted study level still has to resolve
     * to a code the user form actually offered.
     */
    private function seed_study_levels(): void {
        global $DB;

        foreach (['100', '200', '300', '400'] as $sortorder => $code) {
            if ($DB->record_exists('local_ulms_levels', ['code' => $code])) {
                continue;
            }

            $DB->insert_record('local_ulms_levels', (object)[
                'code' => $code,
                'name' => 'Level ' . $code,
                'description' => '',
                'status' => 'active',
                'sortorder' => $sortorder,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }
    }

    /**
     * Returns the active college -> department -> programme chain used by the create fixture.
     *
     * The student programme mandate requires an active programme, so the fixture supplies a
     * real hierarchy instead of programmeid = 0. The ids are cached because several tests
     * read the fixture more than once.
     *
     * @return array{facultyid: int, departmentid: int, programmeid: int}
     */
    private function get_academic_fixture_ids(): array {
        if ($this->academicfixtureids !== null) {
            return $this->academicfixtureids;
        }

        $repository = new \local_ulms_academics\local\repository\academic_repository();
        $facultyid = $repository->save_record('faculties', (object)[
            'code' => 'TEST-COLLEGE',
            'name' => 'Test College',
            'status' => 'active',
        ]);
        $departmentid = $repository->save_record('departments', (object)[
            'code' => 'TEST-DEPARTMENT',
            'name' => 'Test Department',
            'status' => 'active',
            'facultyid' => $facultyid,
        ]);
        $programmeid = $repository->save_record('programmes', (object)[
            'code' => 'TEST-PROGRAMME',
            'name' => 'Test Programme',
            'status' => 'active',
            'departmentid' => $departmentid,
            'awardtype' => 'BSc',
            'durationyears' => 4,
        ]);

        $this->academicfixtureids = [
            'facultyid' => $facultyid,
            'departmentid' => $departmentid,
            'programmeid' => $programmeid,
        ];

        return $this->academicfixtureids;
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
        $academicids = $this->get_academic_fixture_ids();

        return array_merge([
            'firstname' => 'John',
            'middlename' => 'T',
            'lastname' => 'Doe',
            'username' => 'john.doe',
            'email' => 'john.doe@example.com',
            'idnumber' => 'STU-UM-001',
            'role' => 'student',
            'status' => 'active',
            'facultyid' => $academicids['facultyid'],
            'departmentid' => $academicids['departmentid'],
            'programmeid' => $academicids['programmeid'],
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

        $academicids = $this->get_academic_fixture_ids();

        $result = $service->update_user((int)$user->id, [
            'firstname' => 'Legacy',
            'middlename' => '',
            'lastname' => 'Admin',
            'username' => 'legacy.admin',
            'email' => 'legacy.admin@example.com',
            'idnumber' => 'ADM-LEGACY-001',
            'role' => 'student',
            'status' => 'active',
            'facultyid' => $academicids['facultyid'],
            'departmentid' => $academicids['departmentid'],
            'programmeid' => $academicids['programmeid'],
            'studylevel' => '100',
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
            // An administrator is staff, so it is identified by a Staff ID rather than by a
            // learner matriculation number, and it holds no student programme or study level.
            'idnumber' => '',
            'staffid' => 'ADM-UM-001',
            'programmeid' => 0,
            'studylevel' => '',
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
            // An administrator is staff, so it is identified by a Staff ID rather than by a
            // learner matriculation number, and it holds no student programme or study level.
            'idnumber' => '',
            'staffid' => 'ADM-UM-002',
            'programmeid' => 0,
            'studylevel' => '',
        ]));

        $result = $service->delete_user_account((int)$create['userid']);

        $this->assertTrue($result['success']);
        $deleted = $DB->get_record('user', ['id' => (int)$create['userid']], '*', MUST_EXIST);
        $this->assertSame(1, (int)$deleted->deleted);
    }

    /**
     * A staff account is identified by a Staff ID, so User Management must reject a staff
     * creation that omits one. This is the same rule the provisioning service enforces, which
     * is what keeps the two account-creation interfaces interchangeable.
     */
    public function test_create_staff_account_without_a_staff_id_is_rejected(): void {
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
            'role' => 'admin',
            'username' => 'no.staff.id.admin',
            'email' => 'no.staff.id.admin@example.com',
            'idnumber' => '',
            'staffid' => '',
        ]));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('staffid', $result['errors']);
        $this->assertSame(
            get_string('provisioningroleadminrequiresstaffid', 'local_ulms_dashboard'),
            $result['errors']['staffid']
        );
    }

    /**
     * A learner matriculation number posted against a staff account must be discarded rather
     * than stored, so a value that does not belong to the account type cannot reach the
     * Moodle user record through User Management either.
     */
    public function test_create_staff_account_discards_a_learner_matriculation_number(): void {
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

        $result = $service->create_user($this->get_valid_create_data([
            'role' => 'lecturer',
            'username' => 'clean.lecturer',
            'email' => 'clean.lecturer@example.com',
            'idnumber' => 'STU-CLEAN-1',
            'programmeid' => 0,
            'studylevel' => '',
            'staffid' => 'STAFF-CLEAN-1',
        ]));

        $this->assertTrue($result['success'], implode(' ', $result['errors'] ?? []));

        $user = $DB->get_record('user', ['username' => 'clean.lecturer'], '*', MUST_EXIST);
        $this->assertSame('', (string)$user->idnumber);

        $profile = $DB->get_record('local_ulms_user_profile', ['userid' => $user->id], '*', MUST_EXIST);
        $this->assertSame('STAFF-CLEAN-1', (string)$profile->staffid);
        $this->assertSame(0, (int)$profile->programmeid);
        $this->assertSame('', (string)$profile->studylevel);
    }

    /**
     * A staff account is never a student programme enrolment, so a programme posted against a
     * staff account is rejected instead of being allowed to cascade a college and a department
     * onto the staff profile.
     */
    public function test_create_staff_account_rejects_a_student_programme(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();
        $this->ensure_user_management_tables();

        $academicids = $this->get_academic_fixture_ids();

        $service = $this->create_service(new user_management_service_test_mailer([
            'success' => true,
            'messageid' => 'email_1',
            'statuscode' => 200,
            'error' => '',
        ]));

        $result = $service->create_user($this->get_valid_create_data([
            'role' => 'lecturer',
            'username' => 'programme.lecturer',
            'email' => 'programme.lecturer@example.com',
            'idnumber' => '',
            'staffid' => 'STAFF-PRG-1',
            'programmeid' => $academicids['programmeid'],
            'studylevel' => '',
        ]));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('programmeid', $result['errors']);
        $this->assertSame(
            get_string('provisioningerrorprogrammenotapplicable', 'local_ulms_dashboard'),
            $result['errors']['programmeid']
        );
    }

    /**
     * The Staff ID requirement is an account-creation rule. An existing staff account that does
     * not carry one must remain editable, so editing must not be blocked by the creation rule.
     */
    public function test_editing_a_staff_account_does_not_require_a_staff_id(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();
        $this->ensure_user_management_tables();

        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Existing',
            'lastname' => 'Staff',
            'email' => 'existing.staff@example.com',
            'username' => 'existing.staff',
        ]);

        $service = $this->create_service(new user_management_service_test_mailer([
            'success' => true,
            'messageid' => 'email_1',
            'statuscode' => 200,
            'error' => '',
        ]));

        $result = $service->update_user((int)$user->id, [
            'firstname' => 'Existing',
            'middlename' => '',
            'lastname' => 'Staff',
            'username' => 'existing.staff',
            'email' => 'existing.staff@example.com',
            'idnumber' => '',
            'role' => 'lecturer',
            'status' => 'active',
            'facultyid' => 0,
            'departmentid' => 0,
            'programmeid' => 0,
            'studylevel' => '',
            'staffid' => '',
        ]);

        $this->assertTrue($result['success'], implode(' ', $result['errors'] ?? []));
        $this->assertSame('lecturer', $service->get_user_details((int)$user->id)['rolekey']);
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

    /**
     * Creates and switches to an operator who holds the manager role but is not a site
     * administrator.
     *
     * This is the least-privileged operator that can still reach the user management services,
     * because manager carries moodle/user:update and local/ulms_dashboard:viewadmindashboard at
     * the system context. It is therefore the operator the Change Role rules must contain.
     *
     * @return \stdClass
     */
    private function become_manager_operator(): \stdClass {
        global $DB;

        $operator = $this->getDataGenerator()->create_user([
            'firstname' => 'Operator',
            'lastname' => 'Manager',
            'username' => 'operator.manager',
            'email' => 'operator.manager@example.com',
        ]);
        $managerrole = $DB->get_record('role', ['shortname' => 'manager'], 'id', MUST_EXIST);
        role_assign((int)$managerrole->id, (int)$operator->id, \context_system::instance()->id);
        $this->setUser($operator);

        return $operator;
    }

    /**
     * Returns the minimal user shape that get_user_actions() reads.
     *
     * @param string $rolekey
     * @return array<string, mixed>
     */
    private function get_action_target(string $rolekey): array {
        return [
            'id' => 999999,
            'rolekey' => $rolekey,
            'deleted' => 0,
            'suspended' => 0,
        ];
    }

    public function test_last_modified_metadata_is_formatted_for_the_account_panel(): void {
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
        $userid = (int)$create['userid'];

        $known = 1700000000;
        $DB->set_field('user', 'timemodified', $known, ['id' => $userid]);

        $details = $service->get_user_details($userid);
        $this->assertSame($known, $details['timemodified']);
        $this->assertSame(
            userdate($known, get_string('strftimedatetimeshort')),
            $details['timemodifiedformatted']
        );

        $DB->set_field('user', 'timemodified', 0, ['id' => $userid]);
        $details = $service->get_user_details($userid);
        $this->assertSame(get_string('never'), $details['timemodifiedformatted']);
    }

    public function test_account_information_last_modified_label_is_a_resolved_string(): void {
        // 'timemodified' is not a string identifier in any component, so the Account Information
        // panel rendered a raw [[timemodified]] placeholder. The label now comes from the core
        // 'lastmodified' string, which must resolve rather than degrade to a placeholder.
        $this->assertTrue(get_string_manager()->string_exists('lastmodified', 'moodle'));
        $this->assertStringNotContainsString('[[', get_string('lastmodified'));

        $source = file_get_contents(__DIR__ . '/../user_view.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString("get_string('timemodified')", $source);
        $this->assertStringContainsString("get_string('lastmodified')", $source);
    }

    public function test_role_change_is_audit_logged_against_the_acting_operator(): void {
        global $DB, $USER;

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

        $academicids = $this->get_academic_fixture_ids();
        $create = $service->create_user($this->get_valid_create_data([
            'username' => 'audit.rolechange',
            'email' => 'audit.rolechange@example.com',
            'idnumber' => 'STU-UM-AUD-1',
        ]));
        $this->assertTrue($create['success']);
        $userid = (int)$create['userid'];

        $result = $service->update_user($userid, $this->get_valid_create_data([
            'role' => 'lecturer',
            'username' => 'audit.rolechange',
            'email' => 'audit.rolechange@example.com',
            // A lecturer is staff: a Staff ID is held instead of a learner matriculation
            // number, and no student programme or study level is stored.
            'idnumber' => '',
            'staffid' => 'STAFF-AUD-001',
            'facultyid' => $academicids['facultyid'],
            'departmentid' => $academicids['departmentid'],
            'programmeid' => 0,
            'studylevel' => '',
        ]));
        $this->assertTrue($result['success']);

        $log = $DB->get_record('local_ulms_user_management_log', [
            'targetuserid' => $userid,
            'action' => 'ROLE_CHANGED',
        ], '*', MUST_EXIST);
        $this->assertSame((int)$USER->id, (int)$log->actorid);
        $this->assertSame('success', $log->status);

        $details = json_decode((string)$log->detailsjson, true);
        $this->assertIsArray($details);
        $this->assertSame('student', $details['oldrole']);
        $this->assertSame('lecturer', $details['newrole']);
    }

    public function test_changerole_action_requires_site_admin_for_administrator_targets(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $service = $this->create_service(new user_management_service_test_mailer([
            'success' => true,
            'messageid' => 'email_1',
            'statuscode' => 200,
            'error' => '',
        ]));

        foreach (['admin', 'superadmin'] as $rolekey) {
            $this->assertTrue(
                $service->get_user_actions($this->get_action_target($rolekey))['changerole'],
                'A site administrator may change the role of an ' . $rolekey . ' account.'
            );
        }

        $this->assertTrue($service->can_manage_admin_accounts());
        $this->assertArrayHasKey('admin', $service->get_available_role_options());

        $this->become_manager_operator();

        $this->assertFalse($service->can_manage_admin_accounts());
        $this->assertArrayNotHasKey('admin', $service->get_available_role_options());

        foreach (['admin', 'superadmin'] as $rolekey) {
            $this->assertFalse(
                $service->get_user_actions($this->get_action_target($rolekey))['changerole'],
                'An operator without site administrator rights must not be offered a role change for '
                    . 'an ' . $rolekey . ' account.'
            );
        }

        $this->assertTrue(
            $service->get_user_actions($this->get_action_target('student'))['changerole'],
            'The administrator rule must not restrict role changes to ordinary accounts.'
        );
    }

    public function test_non_siteadmin_cannot_change_an_administrator_role(): void {
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

        $target = $this->getDataGenerator()->create_user([
            'firstname' => 'Target',
            'lastname' => 'Administrator',
            'username' => 'target.admin',
            'email' => 'target.admin@example.com',
        ]);
        $managerrole = $DB->get_record('role', ['shortname' => 'manager'], 'id', MUST_EXIST);
        role_assign((int)$managerrole->id, (int)$target->id, \context_system::instance()->id);
        $this->assertSame('admin', $service->get_user_details((int)$target->id)['rolekey']);
        $modifiedbefore = (int)$DB->get_field('user', 'timemodified', ['id' => $target->id]);

        $operator = $this->become_manager_operator();

        // Preconditions. Without these the required_capability_exception asserted below could be
        // thrown by the moodle/user:update gate inside require_update_capability() rather than by
        // the role-change authorisation rule this test exists to prove.
        $this->assertFalse(is_siteadmin((int)$operator->id));
        $this->assertTrue(
            has_capability('moodle/user:update', \context::instance_by_id(\context_system::instance()->id)),
            'The operator must hold moodle/user:update for this test to exercise the role rule.'
        );
        $this->assertArrayNotHasKey('admin', $service->get_available_role_options());
        $this->assertFalse($service->can_manage_admin_accounts());
        $this->assertTrue(
            $DB->record_exists('role_assignments', [
                'userid' => $operator->id,
                'roleid' => (int)$managerrole->id,
                'contextid' => \context_system::instance()->id,
            ]),
            'The operator carries administrator access, so the last-admin guard cannot be the rejector.'
        );

        try {
            $service->update_user((int)$target->id, $this->get_valid_create_data([
                'role' => 'student',
                'username' => 'target.admin',
                'email' => 'target.admin@example.com',
                'idnumber' => 'ADM-TARGET-001',
            ]));
            $this->fail('A non-site-admin must not be allowed to change an administrator role.');
        } catch (\required_capability_exception $exception) {
            $this->assertSame('nopermissions', $exception->errorcode);
        }

        // The refusal must happen before any account field is written.
        $this->assertSame('admin', $service->get_user_details((int)$target->id)['rolekey']);
        $this->assertSame($modifiedbefore, (int)$DB->get_field('user', 'timemodified', ['id' => $target->id]));
    }

    public function test_non_siteadmin_cannot_change_a_superadmin_role(): void {
        global $CFG, $DB;

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

        $target = $this->getDataGenerator()->create_user([
            'firstname' => 'Target',
            'lastname' => 'Superadmin',
            'username' => 'target.superadmin',
            'email' => 'target.superadmin@example.com',
        ]);

        // Being listed in siteadmins is what makes an account a Super Administrator. A second
        // Super Administrator is added as well, so the last-super-admin guard cannot be what
        // rejects this request - only the role-change authorisation rule can be.
        $peer = $this->getDataGenerator()->create_user([
            'firstname' => 'Peer',
            'lastname' => 'Superadmin',
            'username' => 'peer.superadmin',
            'email' => 'peer.superadmin@example.com',
        ]);
        $siteadmins = array_values(array_unique(array_filter(array_map(
            'intval',
            explode(',', (string)($CFG->siteadmins ?? ''))
        ))));
        $siteadmins[] = (int)$target->id;
        $siteadmins[] = (int)$peer->id;
        $CFG->siteadmins = implode(',', $siteadmins);
        $this->assertTrue(is_siteadmin((int)$target->id));
        $this->assertSame('superadmin', $service->get_user_details((int)$target->id)['rolekey']);
        $this->assertGreaterThan(
            0,
            $service->count_active_superadmins_excluding((int)$target->id),
            'Another active Super Administrator must exist so the last-super-admin guard is not the rejector.'
        );

        $operator = $this->become_manager_operator();

        // Preconditions, as in the administrator-target test: the operator genuinely holds
        // moodle/user:update, so the exception asserted below can only come from the
        // role-change authorisation rule.
        $this->assertFalse(is_siteadmin((int)$operator->id));
        $this->assertTrue(
            has_capability('moodle/user:update', \context::instance_by_id(\context_system::instance()->id)),
            'The operator must hold moodle/user:update for this test to exercise the role rule.'
        );
        $this->assertArrayNotHasKey('superadmin', $service->get_available_role_options());
        $this->assertFalse($service->can_manage_admin_accounts());

        try {
            $service->update_user((int)$target->id, $this->get_valid_create_data([
                'role' => 'student',
                'username' => 'target.superadmin',
                'email' => 'target.superadmin@example.com',
                'idnumber' => 'SUP-TARGET-001',
            ]));
            $this->fail('A non-site-admin must not be allowed to change a super administrator role.');
        } catch (\required_capability_exception $exception) {
            $this->assertSame('nopermissions', $exception->errorcode);
        }

        // The account must still be a Super Administrator after the refusal.
        $this->assertTrue(is_siteadmin((int)$target->id));
        $this->assertSame('superadmin', $service->get_user_details((int)$target->id)['rolekey']);
    }

    public function test_site_admin_can_still_change_an_administrator_role(): void {
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

        $target = $this->getDataGenerator()->create_user([
            'firstname' => 'Target',
            'lastname' => 'Downgrade',
            'username' => 'target.downgrade',
            'email' => 'target.downgrade@example.com',
        ]);
        $managerrole = $DB->get_record('role', ['shortname' => 'manager'], 'id', MUST_EXIST);
        role_assign((int)$managerrole->id, (int)$target->id, \context_system::instance()->id);
        $this->assertSame('admin', $service->get_user_details((int)$target->id)['rolekey']);

        // A second administrator is created as well, so the last-admin guard is not the reason
        // this demotion is allowed to proceed.
        $peer = $this->getDataGenerator()->create_user([
            'firstname' => 'Peer',
            'lastname' => 'Administrator',
            'username' => 'peer.admin',
            'email' => 'peer.admin@example.com',
        ]);
        role_assign((int)$managerrole->id, (int)$peer->id, \context_system::instance()->id);

        $result = $service->update_user((int)$target->id, $this->get_valid_create_data([
            'role' => 'student',
            'username' => 'target.downgrade',
            'email' => 'target.downgrade@example.com',
            'idnumber' => 'ADM-TARGET-002',
        ]));

        $this->assertTrue($result['success']);
        $this->assertSame('student', $service->get_user_details((int)$target->id)['rolekey']);
    }

    public function test_non_siteadmin_cannot_promote_a_user_to_administrator(): void {
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

        $target = $this->getDataGenerator()->create_user([
            'firstname' => 'Target',
            'lastname' => 'Promotion',
            'username' => 'target.promotion',
            'email' => 'target.promotion@example.com',
        ]);

        $operator = $this->become_manager_operator();

        // The operator holds moodle/user:update, so the rejection below can only come from the
        // role surface: get_available_role_options() never offers administrator roles to an
        // operator who is not a site administrator.
        $this->assertFalse(is_siteadmin((int)$operator->id));
        $this->assertTrue(
            has_capability('moodle/user:update', \context::instance_by_id(\context_system::instance()->id)),
            'The operator must hold moodle/user:update for this test to exercise the role surface.'
        );
        $this->assertArrayNotHasKey('admin', $service->get_available_role_options());
        $this->assertArrayNotHasKey('superadmin', $service->get_available_role_options());

        $result = $service->update_user((int)$target->id, $this->get_valid_create_data([
            'role' => 'admin',
            'username' => 'target.promotion',
            'email' => 'target.promotion@example.com',
            'idnumber' => 'ADM-PROMOTE-001',
            'staffid' => 'STAFF-PROMOTE-001',
        ]));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('role', $result['errors']);
        $this->assertSame('other', $service->get_user_details((int)$target->id)['rolekey']);
    }
}
