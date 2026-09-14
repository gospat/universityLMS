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

namespace local_ulms_mail;

defined('MOODLE_INTERNAL') || die();

use local_ulms_dashboard\local\service\user_provisioning_service;
use local_ulms_mail\local\service\resend_mail_service;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

require_once(__DIR__ . '/../../../config.php');

/**
 * Collects log records for mail service tests.
 */
final class resend_mail_service_test_logger extends AbstractLogger {
    /** @var array<int, array{message: string, context: array}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void {
        $this->records[] = [
            'message' => (string)$message,
            'context' => $context,
        ];
    }
}

/**
 * Tests the Resend HTTP mail service.
 *
 * @covers \local_ulms_mail\local\service\resend_mail_service
 */
final class resend_mail_service_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        ini_set('display_errors', '0');
    }

    /**
     * Provides a valid base message.
     *
     * @return array
     */
    private function get_message_payload(): array {
        return [
            'to' => [[
                'email' => 'student@example.com',
                'name' => 'Student User',
            ]],
            'subject' => 'Welcome to ULMS',
            'text' => 'Text body',
            'html' => '<p>Text body</p>',
            'replyto' => [[
                'email' => 'support@example.com',
                'name' => 'Support Team',
            ]],
            'idempotencykey' => 'ulms-test-key',
        ];
    }

    /**
     * Configures the Resend transport defaults.
     *
     * @return void
     */
    private function configure_resend_transport(): void {
        global $CFG;

        $CFG->ulmsmailtransport = 'resend';
        $CFG->resendapikey = 're_test_secret';
        $CFG->resendfromemail = 'no-reply@example.com';
        $CFG->resendfromname = 'ULMS Mailer';
    }

    /**
     * Returns a logger that collects records for assertions.
     *
     * @return resend_mail_service_test_logger
     */
    private function create_collecting_logger(): resend_mail_service_test_logger {
        return new resend_mail_service_test_logger();
    }

    public function test_missing_api_key_is_rejected_safely(): void {
        global $CFG;

        $this->resetAfterTest(true);
        $this->configure_resend_transport();
        $CFG->resendapikey = '';

        $service = new resend_mail_service(new MockHttpClient());
        $result = $service->send_transactional_email($this->get_message_payload());

        $this->assertFalse($result['success']);
        $this->assertSame(get_string('errormissingapikey', 'local_ulms_mail'), $result['error']);
    }

    public function test_sender_configuration_is_validated(): void {
        global $CFG;

        $this->resetAfterTest(true);
        $this->configure_resend_transport();
        $CFG->resendfromemail = 'invalid-email';

        $service = new resend_mail_service(new MockHttpClient());
        $result = $service->send_transactional_email($this->get_message_payload());

        $this->assertFalse($result['success']);
        $this->assertSame(get_string('errorinvalidsender', 'local_ulms_mail'), $result['error']);
    }

    public function test_successful_resend_response_is_handled(): void {
        $this->resetAfterTest(true);
        $this->configure_resend_transport();

        $client = new MockHttpClient([
            new MockResponse(json_encode(['id' => 'email_123']), ['http_code' => 201]),
        ]);

        $service = new resend_mail_service($client);
        $result = $service->send_transactional_email($this->get_message_payload());

        $this->assertTrue($result['success']);
        $this->assertSame('email_123', $result['messageid']);
        $this->assertSame(201, $result['statuscode']);
    }

    public function test_resend_4xx_response_is_handled(): void {
        $this->resetAfterTest(true);
        $this->configure_resend_transport();

        $client = new MockHttpClient([
            new MockResponse(json_encode(['message' => 'Invalid request']), ['http_code' => 422]),
        ]);
        $logger = $this->create_collecting_logger();

        $service = new resend_mail_service($client, $logger);
        $result = $service->send_transactional_email($this->get_message_payload());

        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['statuscode']);
        $this->assertSame(get_string('errorsendfailed', 'local_ulms_mail'), $result['error']);
    }

    public function test_resend_5xx_response_is_handled(): void {
        $this->resetAfterTest(true);
        $this->configure_resend_transport();

        $client = new MockHttpClient([
            new MockResponse(json_encode(['message' => 'Server error']), ['http_code' => 500]),
        ]);
        $logger = $this->create_collecting_logger();

        $service = new resend_mail_service($client, $logger);
        $result = $service->send_transactional_email($this->get_message_payload());

        $this->assertFalse($result['success']);
        $this->assertSame(500, $result['statuscode']);
    }

    public function test_network_errors_are_handled_without_leaking_api_key(): void {
        $this->resetAfterTest(true);
        $this->configure_resend_transport();

        $logger = $this->create_collecting_logger();

        $client = new MockHttpClient(static function(): void {
            throw new TransportException('Bearer re_test_secret connection timed out');
        });

        $service = new resend_mail_service($client, $logger);
        $result = $service->send_transactional_email($this->get_message_payload());

        $this->assertFalse($result['success']);
        $this->assertSame(get_string('errorsendfailed', 'local_ulms_mail'), $result['error']);
        $this->assertNotEmpty($logger->records);
        $this->assertStringNotContainsString('re_test_secret', json_encode($logger->records));
    }

    public function test_reply_to_and_bodies_are_passed_correctly(): void {
        $this->resetAfterTest(true);
        $this->configure_resend_transport();

        $captured = [];
        $client = new MockHttpClient(static function(string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = [
                'method' => $method,
                'url' => $url,
                'headers' => $options['headers'] ?? [],
                'body' => $options['body'] ?? '',
            ];

            return new MockResponse(json_encode(['id' => 'email_456']), ['http_code' => 200]);
        });

        $service = new resend_mail_service($client);
        $result = $service->send_transactional_email($this->get_message_payload());

        $this->assertTrue($result['success']);
        $this->assertSame('POST', $captured['method']);
        $this->assertSame('https://api.resend.com/emails', $captured['url']);
        $payload = json_decode((string)($captured['body'] ?? ''), true);
        $this->assertIsArray($payload);
        $this->assertSame('Support Team <support@example.com>', $payload['reply_to']);
        $this->assertSame('Text body', $payload['text']);
        $this->assertSame('<p>Text body</p>', $payload['html']);
        $headerblob = json_encode($captured['headers']);
        $this->assertIsString($headerblob);
        $this->assertStringContainsStringIgnoringCase('idempotency-key', $headerblob);
        $this->assertStringContainsString('ulms-test-key', $headerblob);
    }

    public function test_account_provisioning_can_invoke_resend_service(): void {
        $this->resetAfterTest(true);
        $this->configure_resend_transport();

        $calls = 0;
        $client = new MockHttpClient(static function() use (&$calls): MockResponse {
            $calls++;
            return new MockResponse(json_encode(['id' => 'email_789']), ['http_code' => 200]);
        });

        $service = new resend_mail_service($client);

        $provisioning = $this->getMockBuilder(user_provisioning_service::class)
            ->onlyMethods(['create_mail_service'])
            ->getMock();

        $provisioning->method('create_mail_service')->willReturn($service);

        $user = (object)[
            'id' => 42,
            'username' => 'studentdemo',
            'email' => 'student@example.com',
            'firstname' => 'Student',
            'lastname' => 'Demo',
            'firstnamephonetic' => '',
            'lastnamephonetic' => '',
            'middlename' => '',
            'alternatename' => '',
        ];

        $reflection = new \ReflectionMethod(user_provisioning_service::class, 'send_account_email');
        $result = (bool)$reflection->invoke($provisioning, $user, 'student', 'https://example.com/activate');

        $this->assertTrue($result);
        $this->assertSame(1, $calls);
    }
}
