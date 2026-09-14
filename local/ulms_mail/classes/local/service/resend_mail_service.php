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

namespace local_ulms_mail\local\service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/ulms_mail/lib.php');
\local_ulms_mail_bootstrap_dependencies();

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends ULMS transactional mail through the Resend HTTP API.
 */
class resend_mail_service {
    /** @var string */
    private const API_URL = 'https://api.resend.com/emails';

    /** @var int */
    private const DEFAULT_TIMEOUT = 15;

    /** @var HttpClientInterface */
    private HttpClientInterface $httpclient;

    /** @var LoggerInterface|null */
    private ?LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param HttpClientInterface|null $httpclient
     * @param LoggerInterface|null $logger
     */
    public function __construct(?HttpClientInterface $httpclient = null, ?LoggerInterface $logger = null) {
        $this->logger = $logger;

        if ($httpclient !== null) {
            $this->httpclient = $httpclient;
            return;
        }

        if (!class_exists(HttpClient::class)) {
            throw new \moodle_exception('errorhttpclientunavailable', 'local_ulms_mail');
        }

        $this->httpclient = HttpClient::create([
            'timeout' => self::DEFAULT_TIMEOUT,
        ]);
    }

    /**
     * Sends a transactional email through the Resend HTTP API.
     *
     * @param array $message
     * @return array{success: bool, messageid: ?string, statuscode: ?int, error: string}
     */
    public function send_transactional_email(array $message): array {
        global $CFG;

        if (($CFG->ulmsmailtransport ?? 'moodle') !== 'resend') {
            return $this->failure_result('');
        }

        $apikey = trim((string)($CFG->resendapikey ?? ''));
        if ($apikey === '') {
            return $this->failure_result(get_string('errormissingapikey', 'local_ulms_mail'));
        }

        if (!$this->is_valid_resend_api_key($apikey)) {
            $this->log_failure('Resend API key did not match the expected production format.', []);
            return $this->failure_result(get_string('errormissingapikey', 'local_ulms_mail'));
        }

        $sender = $this->build_sender();
        if ($sender === null) {
            return $this->failure_result(get_string('errorinvalidsender', 'local_ulms_mail'));
        }

        $payload = $this->build_payload($message, $sender);
        if (isset($payload['error'])) {
            return $this->failure_result((string)$payload['error']);
        }

        $headers = [
            'Authorization' => 'Bearer ' . $apikey,
            'Content-Type' => 'application/json',
        ];

        if (!empty($message['idempotencykey'])) {
            $headers['Idempotency-Key'] = (string)$message['idempotencykey'];
        }

        try {
            $response = $this->httpclient->request('POST', self::API_URL, [
                'headers' => $headers,
                'json' => $payload,
            ]);

            $statuscode = $response->getStatusCode();
            $rawbody = $response->getContent(false);
            $decoded = json_decode($rawbody, true);

            if (!is_array($decoded)) {
                $this->log_failure('Malformed Resend response received.', [
                    'statuscode' => $statuscode,
                ]);
                return $this->failure_result(get_string('errorsendfailed', 'local_ulms_mail'), $statuscode);
            }

            if ($statuscode < 200 || $statuscode >= 300) {
                $this->log_failure('Resend API rejected the email request.', [
                    'statuscode' => $statuscode,
                    'type' => $decoded['type'] ?? '',
                    'message' => $decoded['message'] ?? '',
                ]);
                return $this->failure_result(get_string('errorsendfailed', 'local_ulms_mail'), $statuscode);
            }

            if (empty($decoded['id']) || !is_string($decoded['id'])) {
                $this->log_failure('Resend API response did not include a message id.', [
                    'statuscode' => $statuscode,
                ]);
                return $this->failure_result(get_string('errorsendfailed', 'local_ulms_mail'), $statuscode);
            }

            return [
                'success' => true,
                'messageid' => $decoded['id'],
                'statuscode' => $statuscode,
                'error' => '',
            ];
        } catch (ExceptionInterface|\Throwable $exception) {
            $this->log_failure('Resend API request failed.', [
                'exception' => $this->sanitize_message($exception->getMessage()),
            ]);

            return $this->failure_result(get_string('errorsendfailed', 'local_ulms_mail'));
        }
    }

    /**
     * Builds the sender details.
     *
     * @return array<string, string>|null
     */
    private function build_sender(): ?array {
        global $CFG;

        $email = trim((string)($CFG->resendfromemail ?? ''));
        if ($email === '' || !validate_email($email)) {
            return null;
        }

        $name = trim((string)($CFG->resendfromname ?? ''));
        if ($name === '') {
            $name = trim((string)($CFG->supportname ?? ''));
        }

        return [
            'email' => $email,
            'name' => $name,
        ];
    }

    /**
     * Builds the Resend request payload.
     *
     * @param array $message
     * @param array $sender
     * @return array
     */
    private function build_payload(array $message, array $sender): array {
        $to = $this->normalize_addresses($message['to'] ?? []);
        if ($to === []) {
            return ['error' => get_string('errorinvalidrecipient', 'local_ulms_mail')];
        }

        $subject = trim((string)($message['subject'] ?? ''));
        if ($subject === '') {
            return ['error' => get_string('errorsendfailed', 'local_ulms_mail')];
        }

        $payload = [
            'from' => $this->format_address($sender['email'], $sender['name']),
            'to' => $to,
            'subject' => $subject,
            'text' => (string)($message['text'] ?? ''),
            'html' => (string)($message['html'] ?? ''),
        ];

        $replyto = $this->normalize_addresses($message['replyto'] ?? []);
        if (($message['replyto'] ?? []) !== [] && $replyto === []) {
            return ['error' => get_string('errorinvalidreplyto', 'local_ulms_mail')];
        }

        if ($replyto !== []) {
            $payload['reply_to'] = count($replyto) === 1 ? $replyto[0] : $replyto;
        }

        $cc = $this->normalize_addresses($message['cc'] ?? []);
        if ($cc !== []) {
            $payload['cc'] = $cc;
        }

        $bcc = $this->normalize_addresses($message['bcc'] ?? []);
        if ($bcc !== []) {
            $payload['bcc'] = $bcc;
        }

        return $payload;
    }

    /**
     * Normalizes a single address or a list of addresses.
     *
     * @param mixed $addresses
     * @return array<int, string>
     */
    private function normalize_addresses($addresses): array {
        if ($addresses === null || $addresses === '') {
            return [];
        }

        if (!is_array($addresses)) {
            $addresses = [$addresses];
        }

        $normalized = [];
        foreach ($addresses as $address) {
            if (is_string($address)) {
                $email = trim($address);
                $name = '';
            } else if (is_array($address)) {
                $email = trim((string)($address['email'] ?? ''));
                $name = trim((string)($address['name'] ?? ''));
            } else {
                continue;
            }

            if ($email === '' || !validate_email($email)) {
                continue;
            }

            $normalized[] = $this->format_address($email, $name);
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Formats an email address for Resend.
     *
     * @param string $email
     * @param string $name
     * @return string
     */
    private function format_address(string $email, string $name = ''): string {
        $name = trim($name);
        if ($name === '') {
            return $email;
        }

        return sprintf('%s <%s>', str_replace(['<', '>'], '', $name), $email);
    }

    /**
     * Builds a failure result.
     *
     * @param string $message
     * @param int|null $statuscode
     * @return array{success: bool, messageid: ?string, statuscode: ?int, error: string}
     */
    private function failure_result(string $message, ?int $statuscode = null): array {
        return [
            'success' => false,
            'messageid' => null,
            'statuscode' => $statuscode,
            'error' => $message,
        ];
    }

    /**
     * Logs a failure without exposing secrets.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    private function log_failure(string $message, array $context = []): void {
        global $CFG;

        $context = array_map(fn($value) => is_string($value) ? $this->sanitize_message($value) : $value, $context);

        if ($this->logger) {
            $this->logger->error($message, $context);
            return;
        }

        if (!empty($CFG->debugdeveloper)) {
            debugging($message, DEBUG_DEVELOPER);
        }
    }

    /**
     * Validates that a Resend API key matches the documented production shape.
     *
     * Production Resend keys are ASCII strings that begin with `re_` and are
     * typically at least 40 characters long. This check rejects obvious
     * placeholders and typos without disclosing the actual key contents.
     *
     * @param string $apikey
     * @return bool
     */
    public static function is_valid_resend_api_key(string $apikey): bool {
        $trimmed = trim($apikey);
        if ($trimmed === '') {
            return false;
        }
        if (strlen($trimmed) < 40) {
            return false;
        }
        return (bool)preg_match('/\Are_[A-Za-z0-9_\-]+\z/', $trimmed);
    }

    /**
     * Removes secrets from loggable strings.
     *
     * @param string $message
     * @return string
     */
    private function sanitize_message(string $message): string {
        global $CFG;

        $sanitized = $message;
        foreach ([
            (string)($CFG->resendapikey ?? ''),
            'Authorization: Bearer ',
            'Bearer ',
        ] as $secretfragment) {
            if ($secretfragment === '') {
                continue;
            }

            $sanitized = str_replace($secretfragment, '[redacted]', $sanitized);
        }

        return $sanitized;
    }
}
