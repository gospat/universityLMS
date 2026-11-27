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

namespace local_ulms_auth\local\service\ratelimit;

defined('MOODLE_INTERNAL') || die();

/**
 * Orchestrates the login rate limiter over a selectable counter store.
 *
 * The limiter resolves the real client address, derives three hashed bucket keys and
 * applies the configured policy. Enforcement never increments a counter, so a request
 * that is already being rejected cannot extend its own restriction indefinitely, and
 * the advertised Retry-After is the genuine remaining lifetime of the restricting
 * bucket.
 *
 * Redis is preferred; when it is unavailable a bounded, lock-serialised file fallback
 * may take over so the LMS keeps working without silently disabling protection. Only
 * when both stores are unusable does the limiter fail open, and that is logged.
 *
 * @package    local_ulms_auth
 * @copyright  2026 Bells University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class login_rate_limiter {
    /** @var rate_limit_policy */
    private rate_limit_policy $policy;
    /** @var counter_store|null */
    private ?counter_store $store;
    /** @var bool */
    private bool $storeresolved = false;
    /** @var string */
    private string $clientip;

    /**
     * Constructor.
     *
     * @param rate_limit_policy $policy Enforcement policy.
     * @param counter_store|null $store Explicit store (tests) or null to resolve from config.
     * @param string|null $clientip Explicit client address or null to resolve from the request.
     */
    public function __construct(rate_limit_policy $policy, ?counter_store $store = null, ?string $clientip = null) {
        $this->policy = $policy;
        $this->store = $store;
        $this->storeresolved = $store !== null;
        $this->clientip = $clientip ?? client_ip::resolve();
    }

    /**
     * Builds a limiter from the plugin configuration.
     *
     * @return self
     */
    public static function instance(): self {
        return new self(rate_limit_policy::from_config());
    }

    /**
     * Returns the resolved client address.
     *
     * @return string
     */
    public function client_ip(): string {
        return $this->clientip;
    }

    /**
     * Returns the active policy.
     *
     * @return rate_limit_policy
     */
    public function policy(): rate_limit_policy {
        return $this->policy;
    }

    /**
     * Resolves the counter store, applying the configured backend and fallback.
     *
     * @return counter_store|null Null only when no store is usable (fail-open).
     */
    public function store(): ?counter_store {
        if ($this->storeresolved) {
            return $this->store;
        }
        $this->storeresolved = true;
        $backend = $this->policy->backend();

        if ($backend !== rate_limit_policy::BACKEND_FILE) {
            $redis = $this->try_redis();
            if ($redis !== null) {
                $this->store = $redis;
                return $this->store;
            }
            if ($backend === rate_limit_policy::BACKEND_REDIS && !$this->policy->fallback_enabled()) {
                $this->log('ratelimit_backend_unavailable', ['backend' => 'redis', 'fallback' => 'disabled']);
                return null;
            }
        }

        $file = file_counter_store::from_config();
        if ($file->is_available()) {
            $this->store = $file;
            if ($backend !== rate_limit_policy::BACKEND_FILE) {
                $this->log('ratelimit_backend_fallback', ['backend' => 'file']);
            }
            return $this->store;
        }

        $this->log('ratelimit_unavailable', ['backend' => $backend]);
        return null;
    }

    /**
     * Attempts to build an available Redis store.
     *
     * @return redis_counter_store|null
     */
    private function try_redis(): ?redis_counter_store {
        if (!redis_counter_store::extension_loaded()) {
            $this->log('ratelimit_backend_unavailable', ['backend' => 'redis', 'reason' => 'extension_missing']);
            return null;
        }
        $redis = redis_counter_store::from_config();
        if (!$redis->is_available()) {
            $this->log('ratelimit_backend_unavailable', [
                'backend' => 'redis',
                'reason' => (string)($redis->last_failure() ?? 'unknown'),
            ]);
            return null;
        }
        return $redis;
    }

    /**
     * Evaluates the policy for a login attempt without incrementing any counter.
     *
     * @param string $identifier Normalised username or email.
     * @return array{blocked:bool,retry_after:int,bucket:string|null}
     */
    public function check(string $identifier): array {
        $result = ['blocked' => false, 'retry_after' => 0, 'bucket' => null];
        if (!$this->policy->enabled()) {
            return $result;
        }
        $store = $this->store();
        if ($store === null) {
            return $result;
        }

        $keys = $this->keys($identifier);
        $longestttl = -1;
        $bucket = null;
        foreach ($this->policy->buckets() as $definition) {
            $state = $store->inspect($keys[$definition['name']], $definition['window']);
            if ($state === null) {
                continue;
            }
            if ($state['count'] >= $definition['limit'] && $state['ttl'] > $longestttl) {
                $longestttl = $state['ttl'];
                $bucket = $definition['name'];
            }
        }

        if ($bucket !== null) {
            $result = [
                'blocked' => true,
                'retry_after' => $this->policy->clamp_retry(max(0, $longestttl)),
                'bucket' => $bucket,
            ];
        }
        return $result;
    }

    /**
     * Records a finished authentication attempt.
     *
     * On FAILURE every bucket is incremented. On SUCCESS the account-specific buckets
     * are cleared while the shared per-IP abuse counter is preserved. NEUTRAL is a
     * no-op.
     *
     * @param string $identifier Normalised username or email.
     * @param string $outcome One of login_outcome::SUCCESS|FAILURE|NEUTRAL.
     * @return void
     */
    public function record(string $identifier, string $outcome): void {
        if (!$this->policy->enabled() || !login_outcome::is_valid($outcome)) {
            return;
        }
        if ($outcome === login_outcome::NEUTRAL) {
            return;
        }
        $store = $this->store();
        if ($store === null) {
            return;
        }

        $keys = $this->keys($identifier);
        if ($outcome === login_outcome::SUCCESS) {
            $store->clear($keys['account']);
            $store->clear($keys['combo']);
            return;
        }

        foreach ($this->policy->buckets() as $definition) {
            $store->hit($keys[$definition['name']], $definition['window']);
        }
    }

    /**
     * Builds the hashed bucket keys for an attempt. Plaintext is never stored.
     *
     * @param string $identifier Normalised username or email.
     * @return array{combo:string,account:string,abuseip:string}
     */
    private function keys(string $identifier): array {
        $identifier = $identifier !== '' ? $identifier : '__empty__';
        $identsha = sha1($identifier);
        $ipsha = sha1($this->clientip);

        return [
            'combo' => 'combo:' . sha1($this->clientip . '|' . $identsha),
            'account' => 'account:' . $identsha,
            'abuseip' => 'abuseip:' . $ipsha,
        ];
    }

    /**
     * Emits a structured security event when logging is enabled.
     *
     * @param string $event Event name.
     * @param array $data Scrubbed event payload (no plaintext identifiers).
     * @return void
     */
    private function log(string $event, array $data = []): void {
        if (!$this->policy->security_logging_enabled()) {
            return;
        }
        if (function_exists('\local_ulms_auth_log_security_event')) {
            \local_ulms_auth_log_security_event($event, $data);
            return;
        }
        error_log('[ULMS_SECURITY] ' . json_encode(
            ['event' => $event, 'data' => $data],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }
}
