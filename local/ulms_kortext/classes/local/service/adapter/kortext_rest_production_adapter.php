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

namespace local_ulms_kortext\local\service\adapter;

defined('MOODLE_INTERNAL') || die();

/**
 * Live Kortext REST adapter using Moodle's curl() class.
 *
 * Bearer token caching is done via MUC (cache store 'core') with a 50-minute
 * TTL (Kortext tokens are 60-minute), ensuring we only re-authenticate on
 * expiry or explicit 401.
 *
 * @package local_ulms_kortext
 */
class kortext_rest_production_adapter implements kortext_adapter_interface {

    use kortext_reads_lti_config;

    /**
     * MUC cache key used for the bearer token.
     */
    private const CACHE_KEY_BEARER = 'local_ulms_kortext_bearer';

    /**
     * Reads a plugin setting string with ulms_env() fallback.
     *
     * @param string $cfgkey   Plugin setting (e.g. 'oauth2_client_id')
     * @param string $envkey   Environment variable (e.g. 'KORTEXT_OAUTH2_CLIENT_ID')
     * @param string $default  Literal default
     * @return string
     */
    private static function env_cfg(string $cfgkey, string $envkey, string $default): string {
        $db = (string)get_config('local_ulms_kortext', $cfgkey);
        if ($db !== '') {
            return $db;
        }
        if (function_exists('ulms_env')) {
            $v = ulms_env($envkey, null);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
        $ev = getenv($envkey);
        if (is_string($ev) && $ev !== '') {
            return $ev;
        }
        return $default;
    }

    /**
     * Returns a short integer timeout (seconds) read from plugin config.
     *
     * @return int
     */
    private function timeout(): int {
        $v = (int)self::env_cfg('adapter_timeout_sec', 'KORTEXT_TIMEOUT_SEC', '5');
        if ($v < 1 || $v > 60) {
            return 5;
        }
        return $v;
    }

    /**
     * Base URL with trailing slash removed.
     *
     * @return string
     */
    private function base(): string {
        return rtrim(self::env_cfg('kortext_rest_base', 'KORTEXT_REST_BASE_URL', 'https://api.kortext.com'), '/');
    }

    /**
     * Returns the OAuth2 client_id / client_secret / token_endpoint triple.
     *
     * Missing values result in an immediate health/entitlement failure (no
     * Throwable leaked to the caller — they return ok=false with an error
     * message, as required by reliability NFR-3).
     *
     * @return array{cid: string, csec: string, tokenurl: string}|null
     */
    private function oauth_config(): ?array {
        $cid  = self::env_cfg('oauth2_client_id', 'KORTEXT_OAUTH2_CLIENT_ID', '');
        $csec = self::env_cfg('oauth2_client_secret', 'KORTEXT_OAUTH2_CLIENT_SECRET', '');
        $url  = self::env_cfg('oauth2_token_endpoint', 'KORTEXT_OAUTH2_TOKEN_URL', 'https://api.kortext.com/oauth2/token');
        if ($cid === '' || $csec === '' || $url === '') {
            return null;
        }
        return ['cid' => $cid, 'csec' => $csec, 'tokenurl' => $url];
    }

    /**
     * Runs a POST/PATCH/GET HTTP call and returns the decoded JSON body.
     *
     * @param string $method 'GET'|'POST'|'PUT'|'DELETE'
     * @param string $url Full URL
     * @param array<string, string> $headers
     * @param string|null $body JSON body
     * @return array{http_code: int, body: mixed, error?: string}
     */
    private function request(string $method, string $url, array $headers = [], ?string $body = null): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        try {
            $ch = new \curl();
            $ch->setopt([
                'CURLOPT_TIMEOUT'        => $this->timeout(),
                'CURLOPT_CONNECTTIMEOUT' => $this->timeout(),
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_FOLLOWLOCATION' => false,
                'CURLOPT_SSL_VERIFYPEER' => true,
                'CURLOPT_SSL_VERIFYHOST' => 2,
                'CURLOPT_HTTP_VERSION'   => CURL_HTTP_VERSION_1_1,
            ]);
            if (count($headers) > 0) {
                $ch->setHeader($headers);
            }
            if ($method === 'GET') {
                $resp = $ch->get($url);
            } elseif ($method === 'POST') {
                $resp = $ch->post($url, $body ?? '');
            } else {
                $resp = $ch->post($url, $body ?? '', ['CURLOPT_CUSTOMREQUEST' => $method]);
            }
            $code = (int)($ch->info['http_code'] ?? 0);
            unset($ch);
            if ($resp === false || $resp === null) {
                return [
                    'http_code' => $code,
                    'body'      => null,
                    'error'     => 'http-call-failed',
                ];
            }
            $decoded = json_decode((string)$resp, true);
            return [
                'http_code' => $code,
                'body'      => $decoded,
            ];
        } catch (\Throwable) {
            return [
                'http_code' => 0,
                'body'      => null,
                'error'     => 'http-throwable',
            ];
        }
    }

    /**
     * Retrieves a valid bearer token (MUC-cached).
     *
     * @return string|null Token string on success, null on any failure.
     */
    private function get_bearer(): ?string {
        $cache = \cache::make('core', 'config');
        $existing = $cache->get(self::CACHE_KEY_BEARER);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }
        $cfg = $this->oauth_config();
        if ($cfg === null) {
            return null;
        }
        $body = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $cfg['cid'],
            'client_secret' => $cfg['csec'],
            'scope'         => 'entitlements:write usage:read catalog:read',
        ]);
        $resp = $this->request('POST', $cfg['tokenurl'], ['Content-Type: application/x-www-form-urlencoded'], $body);
        if ($resp['http_code'] !== 200 || !is_array($resp['body']) || empty($resp['body']['access_token'])) {
            return null;
        }
        $token = (string)$resp['body']['access_token'];
        $cache->set(self::CACHE_KEY_BEARER, $token);
        return $token;
    }

    /**
     * Small convenience wrapper that issues a request with bearer + retries on 401.
     *
     * @param string $method
     * @param string $path Relative REST path (leading slash accepted)
     * @param array<string, mixed>|null $jsonbody JSON body (for POST/PUT)
     * @return array{http_code: int, body: mixed, error?: string}
     */
    private function json_call(string $method, string $path, ?array $jsonbody = null, bool $retry_on_401 = true): array {
        $bearer = $this->get_bearer();
        if ($bearer === null) {
            return ['http_code' => 0, 'body' => null, 'error' => 'bearer-unavailable'];
        }
        $url = $this->base() . '/' . ltrim($path, '/');
        $body = $jsonbody !== null ? json_encode($jsonbody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $bearer];
        $resp = $this->request($method, $url, $headers, $body);
        if ($retry_on_401 && $resp['http_code'] === 401) {
            $cache = \cache::make('core', 'config');
            $cache->delete(self::CACHE_KEY_BEARER);
            return $this->json_call($method, $path, $jsonbody, false);
        }
        return $resp;
    }

    /**
     * {@inheritDoc}
     */
    public function health(): array {
        $start = hrtime(true);
        $cfg = $this->oauth_config();
        if ($cfg === null) {
            return [
                'ok'         => false,
                'latency_ms' => (int)((hrtime(true) - $start) / 1e6),
                'error'      => 'oauth-config-missing',
            ];
        }
        $bearer = $this->get_bearer();
        if ($bearer === null) {
            return [
                'ok'         => false,
                'latency_ms' => (int)((hrtime(true) - $start) / 1e6),
                'error'      => 'oauth-token-exchange-failed',
            ];
        }
        $probe = $this->json_call('GET', '/v1/health');
        $ok = ($probe['http_code'] >= 200 && $probe['http_code'] < 400)
            || (isset($probe['error']) && $probe['error'] === 'http-call-failed' && false);
        return [
            'ok'         => $ok,
            'latency_ms' => (int)((hrtime(true) - $start) / 1e6),
            'error'      => $probe['error'] ?? null,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function entitle(int $userid, string $ebook_id, array $user_pii_context = [], string $idempotency_key = ''): array {
        global $CFG;
        $sendpii = (bool)get_config('local_ulms_kortext', 'sendpii');
        if (!$sendpii && function_exists('ulms_env')) {
            $sp = ulms_env('KORTEXT_SEND_PII', null);
            if ($sp !== null && $sp !== '') {
                $sendpii = in_array(strtolower((string)$sp), ['1', 'true', 'yes', 'on'], true);
            }
        }
        if (!$sendpii) {
            $raw = getenv('KORTEXT_SEND_PII');
            if (is_string($raw) && $raw !== '') {
                $sendpii = in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
            }
        }
        $payload = [
            'ebook_id'         => $ebook_id,
            'user_identifier'  => hash('sha256', $CFG->wwwroot . '|u:' . $userid),
            'idempotency_key'  => $idempotency_key !== '' ? $idempotency_key : hash('sha256', (string)$userid . '|' . $ebook_id . '|' . time()),
        ];
        if ($sendpii) {
            if (!empty($user_pii_context['firstname'])) {
                $payload['first_name'] = (string)$user_pii_context['firstname'];
            }
            if (!empty($user_pii_context['lastname'])) {
                $payload['last_name'] = (string)$user_pii_context['lastname'];
            }
            if (!empty($user_pii_context['email'])) {
                $payload['email'] = (string)$user_pii_context['email'];
            }
        }
        $resp = $this->json_call('POST', '/v1/entitlements', $payload);
        if ($resp['http_code'] >= 200 && $resp['http_code'] < 300 && is_array($resp['body']) && !empty($resp['body']['id'])) {
            return [
                'ok'        => true,
                'remote_id' => (string)$resp['body']['id'],
                'http_code' => $resp['http_code'],
            ];
        }
        $err = $resp['error'] ?? 'http-' . $resp['http_code'];
        if (is_array($resp['body']) && !empty($resp['body']['error']['message'])) {
            $err = (string)$resp['body']['error']['message'];
        } elseif (is_array($resp['body']) && !empty($resp['body']['message'])) {
            $err = (string)$resp['body']['message'];
        }
        return [
            'ok'        => false,
            'http_code' => $resp['http_code'],
            'error'     => (string)$err,
        ];
    }

    /**
     * Builds a Kortext LTI launch target URL based on the configured tool URL and
     * supplied ISBN / e-book ID. Supports three launch scopes supported by the
     * Kortext platform:
     *   - bookshelf   : generic landing / bookshelf tab (default KLP admin setting)
     *   - book        : specific title (ISBN)
     *   - book+page   : specific title + page number (HTML fragment / query param)
     *
     * Returned value is always safe to pass to the portal injector action URL;
     * callers are expected to wrap it in an LTI 1.3 launch (External tool activity)
     * or use it as a direct deep-link fallback when a custom deeplink_url is empty.
     *
     * @param string $scope      'bookshelf' | 'book'
     * @param string $isbn       ISBN 10/13 (used when scope = book)
     * @param string $ebook_id   Kortext e-book ID (fallback identifier)
     * @param int|null $page     Optional page number (when scope = book + page)
     * @return string
     */
    public function build_lti_launch_url(string $scope = 'bookshelf', string $isbn = '', string $ebook_id = '', ?int $page = null): string {
        $cfg = $this->lti_tool_config();
        $base = rtrim($cfg['tool_url'], '/');
        if ($scope !== 'book') {
            return $base;
        }
        if ($isbn !== '') {
            $url = $base . '/#/book/' . rawurlencode($isbn);
        } elseif ($ebook_id !== '') {
            $url = $base . '/#/book/' . rawurlencode($ebook_id);
        } else {
            return $base;
        }
        if ($page !== null && $page > 0) {
            $url .= '?page=' . $page;
        }
        return $url;
    }

    /**
     * {@inheritDoc}
     */
    public function list_entitlements(int $adoptionid, string $ebook_id, array $filter = []): array {
        $limit  = (int)($filter['limit'] ?? 50);
        $offset = (int)($filter['offset'] ?? 0);
        $resp = $this->json_call('GET', '/v1/entitlements?ebook_id=' . rawurlencode($ebook_id) . '&limit=' . $limit . '&offset=' . $offset);
        $out = [];
        if ($resp['http_code'] < 200 || $resp['http_code'] >= 300 || !is_array($resp['body'])) {
            return $out;
        }
        $items = $resp['body']['items'] ?? $resp['body']['data'] ?? $resp['body'];
        if (!is_array($items)) {
            return $out;
        }
        foreach ($items as $_item) {
            if (!is_array($_item)) {
                continue;
            }
            $uid = 0;
            if (!empty($_item['user_identifier'])) {
                $uid = (int)preg_replace('/[^0-9]/', '', (string)$_item['user_identifier']);
            }
            $out[] = [
                'userid'      => $uid,
                'remote_id'   => (string)($_item['id'] ?? ''),
                'granted_at'  => (int)($_item['created_at'] ?? time()),
            ];
        }
        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function usage_daily(int $adoptionid, string $ebook_id, int $fromts, int $tots): array {
        $path = '/v1/usage/daily?ebook_id=' . rawurlencode($ebook_id) . '&from=' . $fromts . '&to=' . $tots;
        $resp = $this->json_call('GET', $path);
        $out = [];
        if ($resp['http_code'] < 200 || $resp['http_code'] >= 300 || !is_array($resp['body'])) {
            return $out;
        }
        $rows = $resp['body']['rows'] ?? $resp['body']['items'] ?? $resp['body'];
        if (!is_array($rows)) {
            return $out;
        }
        foreach ($rows as $_r) {
            if (!is_array($_r)) {
                continue;
            }
            $day = (int)($_r['snapshot_date'] ?? $_r['date_ts'] ?? 0);
            if ($day <= 0) {
                continue;
            }
            $out[] = [
                'snapshot_date' => $day,
                'unique_users'  => (int)($_r['unique_users'] ?? 0),
                'opens_count'   => (int)($_r['opens_count'] ?? 0),
            ];
        }
        return $out;
    }
}
