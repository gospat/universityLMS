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

use local_ulms_dashboard\local\service\user_provisioning_service;
use local_ulms_mail\local\service\resend_mail_service;

require_once(__DIR__ . '/../../../config.php');

/**
 * Fake mail service for provisioning tests.
 */
final class user_provisioning_service_test_mailer extends resend_mail_service {
    /** @var array{success: bool, messageid: ?string, statuscode: ?int, error: string} */
    private array $result;

    /** @var \Throwable|null */
    private ?\Throwable $exception;

    /** @var array<string, mixed>|null */
    private ?array $lastmessage = null;

    /**
     * Constructor.
     *
     * @param array{success: bool, messageid: ?string, statuscode: ?int, error: string} $result
     * @param \Throwable|null $exception
     */
    public function __construct(array $result, ?\Throwable $exception = null) {
        $this->result = $result;
        $this->exception = $exception;
    }

    public function send_transactional_email(array $message): array {
        $this->lastmessage = $message;
        if ($this->exception) {
            throw $this->exception;
        }

        return $this->result;
    }

    public function get_last_message(): ?array {
        return $this->lastmessage;
    }
}

/**
 * Fake mail service that returns queued responses for bulk-import tests.
 */
final class user_provisioning_service_test_sequence_mailer extends resend_mail_service {
    /** @var array<int, array{success: bool, messageid: ?string, statuscode: ?int, error: string}> */
    private array $results;

    /**
     * Constructor.
     *
     * @param array<int, array{success: bool, messageid: ?string, statuscode: ?int, error: string}> $results
     */
    public function __construct(array $results) {
        $this->results = $results;
    }

    public function send_transactional_email(array $message): array {
        if ($this->results === []) {
            return [
                'success' => false,
                'messageid' => null,
                'statuscode' => 500,
                'error' => 'No queued mail result available.',
            ];
        }

        return array_shift($this->results);
    }
}

/**
 * Testable provisioning service that injects a fake mailer.
 */
final class user_provisioning_service_testable_service extends user_provisioning_service {
    /** @var resend_mail_service */
    private resend_mail_service $mailservice;

    /**
     * Constructor.
     *
     * @param resend_mail_service $mailservice
     */
    public function __construct(resend_mail_service $mailservice) {
        $this->mailservice = $mailservice;
    }

    protected function create_mail_service(): resend_mail_service {
        return $this->mailservice;
    }
}

/**
 * Tests the ULMS provisioning service feedback and duplicate handling.
 *
 * @covers \local_ulms_dashboard\local\service\user_provisioning_service
 */
final class user_provisioning_service_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        ini_set('display_errors', '0');
    }

    /**
     * Configures a resend transport for testing.
     *
     * @return void
     */
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

    /**
     * Returns valid single-user input data.
     *
     * @param array $overrides
     * @return array
     */
    private function get_valid_data(array $overrides = []): array {
        $defaults = [
            'firstname' => 'Ada',
            'lastname' => 'Okafor',
            'email' => 'ada.okafor@example.com',
            'username' => 'ada.okafor',
            'idnumber' => 'STU-1001',
        ];

        return array_merge($defaults, $overrides);
    }

    /**
     * Returns a testable provisioning service.
     *
     * @param resend_mail_service $mailservice
     * @return user_provisioning_service_testable_service
     */
    private function get_service(resend_mail_service $mailservice): user_provisioning_service_testable_service {
        return new user_provisioning_service_testable_service($mailservice);
    }

    /**
     * Returns a successful fake mailer.
     *
     * @return user_provisioning_service_test_mailer
     */
    private function get_success_mailer(): user_provisioning_service_test_mailer {
        return new user_provisioning_service_test_mailer([
            'success' => true,
            'messageid' => 'email_123',
            'statuscode' => 200,
            'error' => '',
        ]);
    }

    /**
     * Returns the latest provisioning log record.
     *
     * @return \stdClass
     */
    private function get_latest_log_record(): \stdClass {
        global $DB;

        $records = $DB->get_records('local_ulms_user_provisioning_log', [], 'id DESC', '*', 0, 1);
        $record = reset($records);

        if (!$record) {
            throw new \dml_missing_record_exception('No provisioning log record found.');
        }

        return $record;
    }

    /**
     * Returns assigned role shortnames for a user at system context.
     *
     * @param int $userid
     * @return array<int, string>
     */
    private function get_assigned_role_shortnames(int $userid): array {
        global $DB;

        $sql = "SELECT r.shortname
                  FROM {role_assignments} ra
                  JOIN {role} r ON r.id = ra.roleid
                 WHERE ra.userid = :userid
                   AND ra.contextid = :contextid
              ORDER BY r.shortname ASC";

        $records = $DB->get_records_sql($sql, [
            'userid' => $userid,
            'contextid' => \context_system::instance()->id,
        ]);

        return array_map(static fn($record): string => (string)$record->shortname, array_values($records));
    }

    public function test_successful_account_creation_returns_success_feedback(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();

        $service = $this->get_service($this->get_success_mailer());

        $result = $service->create_single_user('student', $this->get_valid_data());

        $this->assertTrue($result['success']);
        $this->assertTrue($result['emailsent']);
        $this->assertSame(1, $DB->count_records('user', ['username' => 'ada.okafor', 'deleted' => 0]));
        $this->assertStringContainsString('onboarding email was sent', $result['message']);
        $this->assertSame('created', $this->get_latest_log_record()->status);
    }

    public function test_successful_account_creation_uses_clean_activation_url_in_email(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();

        $mailer = $this->get_success_mailer();
        $service = $this->get_service($mailer);

        $result = $service->create_single_user('student', $this->get_valid_data([
            'username' => 'activation.clean.user',
            'email' => 'activation.clean.user@example.com',
            'idnumber' => 'STU-1006',
        ]));

        $this->assertTrue($result['success']);
        $message = $mailer->get_last_message();
        $this->assertNotNull($message);
        $this->assertStringContainsString('/sign-in/activate/?token=', (string)($message['text'] ?? ''));

        $user = $DB->get_record('user', ['username' => 'activation.clean.user'], '*', MUST_EXIST);
        $this->assertTrue($DB->record_exists('user_password_resets', ['userid' => $user->id]));
    }

    public function test_validation_failure_returns_visible_errors(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();

        $service = $this->get_service($this->get_success_mailer());

        $result = $service->create_single_user('student', $this->get_valid_data(['firstname' => '']));

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('firstname', implode(' ', $result['errors']));
    }

    public function test_missing_last_name_returns_visible_error(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();

        $service = $this->get_service($this->get_success_mailer());

        $result = $service->create_single_user('student', $this->get_valid_data(['lastname' => '']));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('lastname', implode(' ', $result['errors']));
    }

    public function test_invalid_email_is_rejected_cleanly(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();

        $service = $this->get_service($this->get_success_mailer());

        $result = $service->create_single_user('student', $this->get_valid_data(['email' => 'not-an-email']));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid email address', implode(' ', $result['errors']));
    }

    public function test_duplicate_username_is_rejected_cleanly(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();

        $this->getDataGenerator()->create_user([
            'username' => 'ada.okafor',
            'email' => 'existing@example.com',
            'idnumber' => 'EXIST-1',
        ]);

        $service = $this->get_service($this->get_success_mailer());

        $result = $service->create_single_user('student', $this->get_valid_data(['email' => 'fresh@example.com']));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('username', implode(' ', $result['errors']));
    }

    public function test_duplicate_email_is_rejected_cleanly(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();

        $this->getDataGenerator()->create_user([
            'username' => 'existing.user',
            'email' => 'ada.okafor@example.com',
            'idnumber' => 'EXIST-2',
        ]);

        $service = $this->get_service($this->get_success_mailer());

        $result = $service->create_single_user('student', $this->get_valid_data(['username' => 'new.user']));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('email', implode(' ', $result['errors']));
    }

    public function test_duplicate_idnumber_is_rejected_cleanly(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();

        $this->getDataGenerator()->create_user([
            'username' => 'existing.user',
            'email' => 'existing@example.com',
            'idnumber' => 'STU-1001',
        ]);

        $service = $this->get_service($this->get_success_mailer());

        $result = $service->create_single_user('student', $this->get_valid_data([
            'username' => 'fresh.user',
            'email' => 'fresh@example.com',
        ]));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ID number', implode(' ', $result['errors']));
    }

    public function test_blank_username_is_generated_from_email(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();

        $service = $this->get_service($this->get_success_mailer());

        $result = $service->create_single_user('student', $this->get_valid_data([
            'email' => 'generated.user@example.com',
            'username' => '',
            'idnumber' => 'STU-1004',
        ]));

        $this->assertTrue($result['success']);
        $this->assertSame('generated.user', $result['username']);
        $this->assertTrue($DB->record_exists('user', ['username' => 'generated.user', 'deleted' => 0]));
    }

    public function test_student_account_is_assigned_only_student_role_and_logs_created_user(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();

        $service = $this->get_service($this->get_success_mailer());

        $result = $service->create_single_user('student', $this->get_valid_data([
            'username' => 'student.role.user',
            'email' => 'student.role@example.com',
            'idnumber' => 'STU-1005',
        ]));

        $this->assertTrue($result['success']);

        $user = $DB->get_record('user', ['username' => 'student.role.user'], '*', MUST_EXIST);
        $this->assertSame(['student'], $this->get_assigned_role_shortnames((int)$user->id));
        $this->assertSame(0, (int)$user->suspended);
        $this->assertSame(1, (int)$user->confirmed);

        $log = $this->get_latest_log_record();
        $this->assertSame((int)$user->id, (int)$log->createduserid);
        $this->assertSame('student', $log->targetrole);
        $this->assertSame('single', $log->createmode);
    }

    public function test_lecturer_account_is_assigned_only_lecturer_role(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();

        $service = $this->get_service($this->get_success_mailer());

        $result = $service->create_single_user('lecturer', $this->get_valid_data([
            'username' => 'lecturer.role.user',
            'email' => 'lecturer.role@example.com',
            'idnumber' => 'LEC-1001',
        ]));

        $this->assertTrue($result['success']);

        $user = $DB->get_record('user', ['username' => 'lecturer.role.user'], '*', MUST_EXIST);
        $roles = $this->get_assigned_role_shortnames((int)$user->id);
        $this->assertContains('editingteacher', $roles);
        $this->assertNotContains('student', $roles);
        $this->assertNotContains('manager', $roles);
        $this->assertCount(1, $roles);
    }

    public function test_admin_account_is_assigned_only_manager_role(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();

        $service = $this->get_service($this->get_success_mailer());

        $result = $service->create_single_user('admin', $this->get_valid_data([
            'username' => 'admin.role.user',
            'email' => 'admin.role@example.com',
            'idnumber' => 'ADM-1001',
        ]));

        $this->assertTrue($result['success']);

        $user = $DB->get_record('user', ['username' => 'admin.role.user'], '*', MUST_EXIST);
        $this->assertSame(['manager'], $this->get_assigned_role_shortnames((int)$user->id));
    }

    public function test_email_failure_after_successful_creation_returns_warning_state(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();

        $mailer = new user_provisioning_service_test_mailer([
            'success' => false,
            'messageid' => null,
            'statuscode' => 500,
            'error' => 'send failed',
        ]);
        $service = $this->get_service($mailer);

        $result = $service->create_single_user('student', $this->get_valid_data([
            'username' => 'warning.user',
            'email' => 'warning@example.com',
            'idnumber' => 'STU-1002',
        ]));

        $this->assertTrue($result['success']);
        $this->assertFalse($result['emailsent']);
        $this->assertSame(1, $DB->count_records('user', ['username' => 'warning.user', 'deleted' => 0]));
        $this->assertStringContainsString('could not be sent', $result['message']);
        $this->assertSame('created_with_email_warning', $this->get_latest_log_record()->status);
    }

    public function test_mail_exception_does_not_abort_account_creation(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();

        $mailer = new user_provisioning_service_test_mailer(
            [
                'success' => false,
                'messageid' => null,
                'statuscode' => null,
                'error' => '',
            ],
            new \RuntimeException('transport failed')
        );
        $service = $this->get_service($mailer);

        $result = $service->create_single_user('student', $this->get_valid_data([
            'username' => 'exception.user',
            'email' => 'exception@example.com',
            'idnumber' => 'STU-1003',
        ]));

        $this->assertTrue($result['success']);
        $this->assertFalse($result['emailsent']);
        $this->assertSame(1, $DB->count_records('user', ['username' => 'exception.user', 'deleted' => 0]));
        $this->assertSame('created_with_email_warning', $this->get_latest_log_record()->status);
    }

    public function test_bulk_preview_flags_duplicate_and_existing_rows(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();

        $this->getDataGenerator()->create_user([
            'username' => 'existing.bulk',
            'email' => 'existing.bulk@example.com',
            'idnumber' => 'BULK-EXIST-1',
        ]);

        $service = $this->get_service($this->get_success_mailer());

        $rows = [
            [
                'firstname' => 'Bulk',
                'lastname' => 'Student One',
                'email' => 'bulk.student.one@example.com',
                'username' => 'bulk.student.one',
                'idnumber' => 'BULK-STU-1',
            ],
            [
                'firstname' => 'Bulk',
                'lastname' => 'Student Two',
                'email' => 'bulk.student.one@example.com',
                'username' => 'bulk.student.two',
                'idnumber' => 'BULK-STU-2',
            ],
            [
                'firstname' => 'Bulk',
                'lastname' => 'Student Three',
                'email' => 'existing.bulk@example.com',
                'username' => 'existing.bulk',
                'idnumber' => 'BULK-EXIST-1',
            ],
        ];

        $preview = $service->preview_bulk_import('student', $rows);

        $this->assertSame(3, $preview['processed']);
        $this->assertSame(1, $preview['valid']);
        $this->assertSame(2, $preview['invalid']);
        $this->assertCount(1, $preview['validrows']);
        $this->assertStringContainsString('already exists', implode(' ', $preview['errors']));
        $this->assertStringContainsString('Duplicate email', implode(' ', $preview['errors']));
    }

    public function test_bulk_import_creates_valid_rows_and_tracks_email_warnings(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->configure_resend_transport();

        $mailer = new user_provisioning_service_test_sequence_mailer([
            [
                'success' => true,
                'messageid' => 'email_bulk_1',
                'statuscode' => 200,
                'error' => '',
            ],
            [
                'success' => false,
                'messageid' => null,
                'statuscode' => 500,
                'error' => 'Transport failure',
            ],
            [
                'success' => true,
                'messageid' => 'email_bulk_3',
                'statuscode' => 200,
                'error' => '',
            ],
        ]);
        $service = $this->get_service($mailer);

        $studentresult = $service->import_bulk_rows('student', [[
            'linenumber' => 2,
            'firstname' => 'Bulk',
            'lastname' => 'Student One',
            'email' => 'bulk.student.one@example.com',
            'username' => 'bulk.student.one',
            'idnumber' => 'BULK-STU-1',
        ]]);
        $lecturerresult = $service->import_bulk_rows('lecturer', [[
            'linenumber' => 3,
            'firstname' => 'Bulk',
            'lastname' => 'Lecturer One',
            'email' => 'bulk.lecturer.one@example.com',
            'username' => 'bulk.lecturer.one',
            'idnumber' => 'BULK-LEC-1',
        ]]);
        $adminresult = $service->import_bulk_rows('admin', [[
            'linenumber' => 4,
            'firstname' => 'Bulk',
            'lastname' => 'Admin One',
            'email' => 'bulk.admin.one@example.com',
            'username' => 'bulk.admin.one',
            'idnumber' => 'BULK-ADM-1',
        ]]);

        $this->assertSame(1, $studentresult['created']);
        $this->assertSame(0, $studentresult['emailfailed']);
        $this->assertSame(1, $lecturerresult['created']);
        $this->assertSame(1, $lecturerresult['emailfailed']);
        $this->assertSame(1, $adminresult['created']);
        $this->assertSame(0, $adminresult['emailfailed']);

        $student = $DB->get_record('user', ['username' => 'bulk.student.one'], '*', MUST_EXIST);
        $lecturer = $DB->get_record('user', ['username' => 'bulk.lecturer.one'], '*', MUST_EXIST);
        $admin = $DB->get_record('user', ['username' => 'bulk.admin.one'], '*', MUST_EXIST);

        $this->assertSame(['student'], $this->get_assigned_role_shortnames((int)$student->id));
        $this->assertSame(['editingteacher'], $this->get_assigned_role_shortnames((int)$lecturer->id));
        $this->assertSame(['manager'], $this->get_assigned_role_shortnames((int)$admin->id));

        $this->assertSame(3, $DB->count_records('local_ulms_user_provisioning_log'));
        $this->assertSame(1, $DB->count_records('local_ulms_user_provisioning_log', ['status' => 'created_with_email_warning']));
        $this->assertSame(2, $DB->count_records('local_ulms_user_provisioning_log', ['status' => 'created']));
    }
}
