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

/**
 * Sends ULMS transactional mail through the Resend HTTP API.
 *
 * Transport order:
 *   1. Caller-injected HTTP client (useful for tests + Symfony HttpClient users).
 *   2. Symfony HttpClient component when vendor/symfony/http-client is installed.
 *   3. Native PHP ext-curl wrapped in lightweight duck-typed objects (always works).
 *
 * Callers only ever use request() + the duck-typed Response methods, so every
 * transport keeps the exact same behaviour and zero caller changes are needed.
 */
class resend_mail_service {
    /** @var string */
    private const API_URL = 'https://api.resend.com/emails';

    /** @var int */
    private const DEFAULT_TIMEOUT = 15;

    /**
     * Duck-typed HTTP transport; satisfies the same contract as
     * Symfony\Contracts\HttpClient\HttpClientInterface without requiring
     * vendor installation.
     *
     * @var object
     */
    private object $httpclient;

    /** @var LoggerInterface|null */
    private ?LoggerInterface $logger;

    /**
     * Constructor.
     *
     * Transport selection order:
     *   1. Caller-injected duck-typed client (tests / Symfony HttpClient users).
     *   2. Composer vendor/symfony/http-client when present.
     *   3. Native PHP ext-curl (always-available production fallback).
     *
     * @param object|null $httpclient  Duck-typed HTTP client; must expose
     *                                 request(string, string, array):object +
     *                                 withOptions(array):static + getOptions().
     * @param LoggerInterface|null $logger Optional structured logger.
     */
    public function __construct(?object $httpclient = null, ?LoggerInterface $logger = null) {
        $this->logger = $logger;

        if ($httpclient !== null) {
            $this->httpclient = $httpclient;
            return;
        }

        $symfonyhttpclass = 'Symfony\\Component\\HttpClient\\HttpClient';
        if (class_exists($symfonyhttpclass)) {
            $this->httpclient = $symfonyhttpclass::create([
                'timeout' => self::DEFAULT_TIMEOUT,
            ]);
            return;
        }

        if (!extension_loaded('curl')) {
            throw new \moodle_exception('errorhttpclientunavailable', 'local_ulms_mail');
        }

        $timeout = self::DEFAULT_TIMEOUT;
        $this->httpclient = new class($timeout) {
            /** @var int */
            private int $timeout;
            /** @var array<string, mixed> */
            private array $options;

            public function __construct(int $timeout) {
                $this->timeout = $timeout;
                $this->options = ['timeout' => $timeout];
            }

            /**
             * Executes an HTTP request and returns a duck-typed response
             * exposing getStatusCode(), getContent(), getHeaders(),
             * toArray(), getInfo() and __toString().
             *
             * @param string $method
             * @param string $url
             * @param array<string, mixed> $options
             * @return object
             */
            public function request(string $method, string $url, array $options = []): object {
                $ch = curl_init($url);
                assert(is_resource($ch) || is_object($ch));
                $headers = $options['headers'] ?? [];
                $flattened = [];
                foreach ($headers as $key => $value) {
                    if (is_array($value)) {
                        foreach ($value as $v) {
                            $flattened[] = $key . ': ' . $v;
                        }
                    } else {
                        $flattened[] = $key . ': ' . $value;
                    }
                }
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
                curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
                if ($flattened !== []) {
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $flattened);
                }
                if (!empty($options['json'])) {
                    $json = json_encode($options['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    if ($json !== false) {
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
                        $hasct = false;
                        foreach ($flattened as $h) {
                            if (stripos($h, 'content-type:') === 0) {
                                $hasct = true;
                                break;
                            }
                        }
                        if (!$hasct) {
                            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($flattened, ['Content-Type: application/json']));
                        }
                    }
                }

                $body = curl_exec($ch);
                $statuscode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlerror = curl_error($ch);

                if ($body === false || $statuscode === 0) {
                    $message = $curlerror !== '' ? $curlerror : 'cURL transport error for Resend request.';
                    throw new \RuntimeException($message);
                }

                return new class((int)$statuscode, (string)$body) {
                    private int $statuscode;
                    private string $body;

                    public function __construct(int $statuscode, string $body) {
                        $this->statuscode = $statuscode;
                        $this->body = $body;
                    }

                    public function getStatusCode(): int {
                        return $this->statuscode;
                    }

                    /** @return array<string, list<string>> */
                    public function getHeaders(bool $_throw = true): array {
                        return [];
                    }

                    /** @return array<int|string, mixed> */
                    public function getInfo(?string $type = null): mixed {
                        return $type === null ? [] : null;
                    }

                    public function getContent(bool $_throw = true): string {
                        return $this->body;
                    }

                    /** @return array<mixed> */
                    public function toArray(bool $_throw = true): array {
                        $decoded = json_decode($this->body, true);
                        return is_array($decoded) ? $decoded : [];
                    }

                    public function __toString(): string {
                        return $this->body;
                    }
                };
            }

            /**
             * @param iterable|object $_responses
             * @param float|null $_timeout
             * @return \Generator<int, never, never, never>
             */
            public function stream(iterable|object $_responses, ?float $_timeout = null): \Generator {
                throw new \LogicException('stream() is not supported via the cURL fallback.');
            }

            /**
             * @param array<string, mixed> $options
             * @return static
             */
            public function withOptions(array $options): static {
                $clone = clone $this;
                $clone->options = array_replace_recursive($clone->options, $options);
                $clone->timeout = (int)($options['timeout'] ?? $clone->timeout);
                return $clone;
            }

            /** @return array<string, mixed> */
            public function getOptions(): array {
                return $this->options;
            }
        };
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
        } catch (\Throwable $exception) {
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
        if (strlen($trimmed) < 32) {
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
