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
 * The limiter resolves the real client address, derives hashed bucket keys and applies
 * the configured policy. Enforcement is deliberately split into two steps:
 *
 *   - `check()` is the pre-login **admission gate**. It atomically reserves one slot in
 *     the combined bucket *before* any credential work happens, so a burst of
 *     simultaneous requests cannot all slip past the threshold and only record their
 *     failures afterwards: the attempt that crosses the combined allowance is denied at
 *     admission time. The window is anchored to the first hit, so a request that is
 *     already being rejected can never extend its own restriction, and the advertised
 *     Retry-After is the genuine remaining lifetime of the restricting bucket.
 *   - `record()` applies the accounting after the outcome is known. On SUCCESS the
 *     account-specific buckets (including the admission reservation) are cleared while
 *     the shared per-IP abuse counter is preserved.
 *
 * Shared-IP protection is progressive rather than absolute. The per-IP abuse bucket
 * detects distributed abuse but, in the default "detect" mode, crossing it only
 * tightens the per-account combined allowance; it does not block the address. This keeps
 * password-spraying and distributed attacks bounded without locking out unrelated
 * legitimate accounts that happen to share a campus NAT or an institutional egress
 * address. An explicit hard ceiling can be enabled for sites that want one.
 *
 * Redis is preferred. The bounded file store is used in two complementary roles:
 *
 *   - as a **live mirror**: while Redis is healthy, every hit and clear is also applied,
 *     best effort, to the file store, and every read takes the *stronger* of the two
 *     observations (higher count, then longer TTL). Because the mirror therefore already
 *     holds the counters, an active restriction cannot be bypassed when Redis fails
 *     mid-flight, and it also cannot be reset when Redis recovers carrying stale
 *     counters, because recovery is reconciled against the mirror on the next read;
 *   - as the **failover target**: if Redis cannot be reached, or if it connects but an
 *     individual operation fails at runtime (memory exhaustion under the production
 *     noeviction policy, a lost ACL permission, a read timeout, a dropped connection, an
 *     unexpected reply), the limiter hands the whole request over to the file store and
 *     re-runs the failed operation there, so no increment is dropped.
 *
 * Continuity therefore does not depend on Redis's own counters surviving an outage: the
 * mirror carries them, partial updates are completed on the fallback, and an
 * intermittent Redis outage changes *where* the count is kept, not *whether* it is kept.
 *
 * Only when no store is usable at all does the limiter enter degraded mode, and the
 * outcome there is governed by the configurable policy (fail open with a loud security
 * event, or fail closed) rather than by a silent default.
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
    /** @var counter_store|null Prepared fallback, used when the primary fails at runtime. */
    private ?counter_store $fallbackstore = null;
    /** @var bool */
    private bool $storeresolved = false;
    /** @var bool Set when an operation could not be served by any store. */
    private bool $operationfailed = false;
    /** @var string */
    private string $clientip;

    /**
     * Constructor.
     *
     * @param rate_limit_policy $policy Enforcement policy.
     * @param counter_store|null $store Explicit store (tests) or null to resolve from config.
     * @param string|null $clientip Explicit client address or null to resolve from the request.
     * @param counter_store|null $fallbackstore Explicit fallback store, or null to resolve from config.
     */
    public function __construct(
        rate_limit_policy $policy,
        ?counter_store $store = null,
        ?string $clientip = null,
        ?counter_store $fallbackstore = null
    ) {
        $this->policy = $policy;
        $this->store = $store;
        $this->storeresolved = $store !== null;
        $this->fallbackstore = $fallbackstore;
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
     * @return counter_store|null Null only when no store is usable (degraded mode).
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
                if ($this->policy->fallback_enabled() && $this->fallbackstore === null) {
                    // Prepared now, so a runtime failure can transition immediately
                    // without performing any further configuration work.
                    $this->fallbackstore = file_counter_store::from_config();
                    if (!$this->fallbackstore->is_available()) {
                        $this->fallbackstore = null;
                    }
                }
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

        $this->log('ratelimit_unavailable', ['backend' => $backend, 'reason' => (string)($file->last_failure() ?? 'unknown')]);
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
                'category' => (string)($redis->last_failure_category() ?? 'unknown'),
            ]);
            return null;
        }
        return $redis;
    }

    /**
     * Runs a store operation, transitioning to the fallback when the primary fails.
     *
     * A null (read/write) or false (clear) result from a store that is not already the
     * fallback means the primary backend failed *at runtime*, which is different from
     * the initial connection failing. The same operation is then retried on the fallback
     * so the accounting is never lost, and a structured security event records the
     * transition with the driver's classified failure reason.
     *
     * @param string $method Store method name.
     * @param array<int, mixed> $arguments Method arguments.
     * @return mixed The operation result, or null when no store could serve it.
     */
    private function invoke(string $method, array $arguments): mixed {
        $store = $this->store();
        if ($store === null) {
            $this->operationfailed = true;
            return null;
        }
        $result = $store->{$method}(...$arguments);
        if (!$this->is_failure($method, $result)) {
            return $result;
        }

        $fallback = $this->fallbackstore;
        if ($fallback === null || $store === $fallback) {
            // No fallback can take over: the operation could not be served.
            $this->operationfailed = true;
            return $result;
        }

        $this->log('ratelimit_backend_degraded', [
            'from' => $store->name(),
            'to' => $fallback->name(),
            'operation' => $method,
            'reason' => (string)($store->last_failure() ?? 'operation_failed'),
            'category' => $store instanceof redis_counter_store
                ? (string)($store->last_failure_category() ?? 'unknown')
                : 'unknown',
        ]);

        $this->store = $fallback;
        if (!$fallback->is_available()) {
            $this->store = null;
            $this->operationfailed = true;
            return null;
        }

        $retried = $fallback->{$method}(...$arguments);
        if ($this->is_failure($method, $retried)) {
            // The fallback failed too, so the operation genuinely could not be served.
            $this->operationfailed = true;
        }
        return $retried;
    }

    /**
     * Reports whether a store result represents a runtime failure.
     *
     * @param string $method Store method name.
     * @param mixed $result Returned value.
     * @return bool
     */
    private function is_failure(string $method, mixed $result): bool {
        return $method === 'clear' ? $result === false : $result === null;
    }

    /**
     * Returns the fallback store when it can act as a live mirror of the primary.
     *
     * The fallback is used as a mirror whenever it is distinct from the store that is
     * currently authoritative and is reachable. Once the primary has failed over, the
     * fallback *is* the authoritative store, so there is no separate mirror to maintain.
     *
     * @return counter_store|null
     */
    private function mirror(): ?counter_store {
        $fallback = $this->fallbackstore;
        if ($fallback === null || $fallback === $this->store) {
            return null;
        }
        if (!$fallback->is_available()) {
            return null;
        }
        return $fallback;
    }

    /**
     * Mirrors a recorded hit onto the standby store. Best effort by design.
     *
     * The authoritative store has already accounted for the attempt, so a mirror write
     * failure must never trigger a failover or place the request in degraded mode: it is
     * logged and the request continues.
     *
     * @param string $key Bucket key.
     * @param int $windowseconds Window length in seconds.
     * @return void
     */
    private function mirror_hit(string $key, int $windowseconds): void {
        $mirror = $this->mirror();
        if ($mirror === null) {
            return;
        }
        if ($mirror->hit($key, $windowseconds) === null) {
            $this->log('ratelimit_mirror_failed', [
                'backend' => $mirror->name(),
                'operation' => 'hit',
                'reason' => (string)($mirror->last_failure() ?? 'operation_failed'),
            ]);
        }
    }

    /**
     * Mirrors a clear onto the standby store, so a successful sign-in resets both copies.
     *
     * @param string $key Bucket key.
     * @return void
     */
    private function mirror_clear(string $key): void {
        $mirror = $this->mirror();
        if ($mirror === null) {
            return;
        }
        if (!$mirror->clear($key)) {
            $this->log('ratelimit_mirror_failed', [
                'backend' => $mirror->name(),
                'operation' => 'clear',
                'reason' => (string)($mirror->last_failure() ?? 'operation_failed'),
            ]);
        }
    }

    /**
     * Reads the standby mirror without incrementing it. Best effort by design.
     *
     * @param string $key Bucket key.
     * @param int $windowseconds Window length in seconds.
     * @return array{count:int,ttl:int}|null Null when there is no usable mirror or it failed.
     */
    private function mirror_inspect(string $key, int $windowseconds): ?array {
        $mirror = $this->mirror();
        if ($mirror === null) {
            return null;
        }
        $result = $mirror->inspect($key, $windowseconds);
        if ($result === null) {
            $this->log('ratelimit_mirror_failed', [
                'backend' => $mirror->name(),
                'operation' => 'inspect',
                'reason' => (string)($mirror->last_failure() ?? 'operation_failed'),
            ]);
            return null;
        }
        return $result;
    }

    /**
     * Chooses the stronger of two counter observations (higher count, then longer TTL).
     *
     * This is what makes recovery safe: a Redis primary that comes back carrying stale
     * counters can never lower the effective count, because the mirror — which kept
     * counting during the outage — still holds the higher value and always wins.
     *
     * @param array{count:int,ttl:int}|null $a Authoritative observation.
     * @param array{count:int,ttl:int}|null $b Mirror observation.
     * @return array{count:int,ttl:int}|null
     */
    private function strongest(?array $a, ?array $b): ?array {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }
        if ((int)$b['count'] > (int)$a['count']) {
            return $b;
        }
        if ((int)$b['count'] === (int)$a['count'] && (int)$b['ttl'] > (int)$a['ttl']) {
            return $b;
        }
        return $a;
    }

    /**
     * Evaluates the policy for a login attempt and atomically reserves its admission slot.
     *
     * The combined bucket is incremented here, before any credential work, so a burst of
     * simultaneous requests cannot all pass the gate and only record their failures
     * afterwards: the increment that crosses the combined allowance is denied at
     * admission time. The remaining buckets are read-only observations, reconciled against
     * the standby mirror so a stale or restarted primary cannot under-count an active
     * restriction. Every window is anchored to its first hit, so a request that is already
     * being rejected can never extend its own restriction.
     *
     * @param string $identifier Normalised username or email.
     * @return array{blocked:bool,retry_after:int,bucket:string|null,challenged:bool,abuse_level:int,degraded:bool}
     */
    public function check(string $identifier): array {
        $result = [
            'blocked' => false,
            'retry_after' => 0,
            'bucket' => null,
            'challenged' => false,
            'abuse_level' => 0,
            'degraded' => false,
        ];
        if (!$this->policy->enabled()) {
            return $result;
        }
        $this->operationfailed = false;
        if ($this->store() === null) {
            return $this->degraded_result($identifier, $result);
        }

        $keys = $this->keys($identifier);
        $definitions = $this->policy->buckets();
        $states = [];

        // Atomic admission. The reservation is mirrored onto the standby store so it
        // survives a backend transition mid-request.
        foreach ($definitions as $definition) {
            if ($definition['name'] !== 'combo') {
                continue;
            }
            $reservation = $this->invoke('hit', [$keys['combo'], $definition['window']]);
            if (is_array($reservation)) {
                $this->mirror_hit($keys['combo'], $definition['window']);
            }
            $states['combo'] = $this->strongest(
                is_array($reservation) ? $reservation : null,
                $this->mirror_inspect($keys['combo'], $definition['window'])
            );
            break;
        }

        // The remaining buckets are read-only observations, each reconciled against the
        // mirror so recovery can never lower the effective count.
        foreach ($definitions as $definition) {
            $name = $definition['name'];
            if ($name === 'combo') {
                continue;
            }
            $primary = $this->invoke('inspect', [$keys[$name], $definition['window']]);
            $states[$name] = $this->strongest(
                is_array($primary) ? $primary : null,
                $this->mirror_inspect($keys[$name], $definition['window'])
            );
        }

        // The primary can die mid-flight. If no store could serve the inspection, or the
        // fallback also proved unusable, there is nothing left to evaluate against and
        // the request is handed to the degraded-mode policy rather than silently allowed.
        if ($this->store() === null || $this->operationfailed) {
            return $this->degraded_result($identifier, $result);
        }

        $abuse = $states['abuseip'] ?? null;
        $abuselevel = is_array($abuse) ? (int)$abuse['count'] : 0;
        $ipmode = $this->policy->ip_mode();
        $elevated = ($ipmode !== rate_limit_policy::IP_MODE_OFF) && ($abuselevel >= $this->policy->abuse_ip_limit());
        $hardlimit = $this->policy->abuse_ip_hard_limit();

        $result['abuse_level'] = $abuselevel;
        $result['challenged'] = $elevated && $ipmode === rate_limit_policy::IP_MODE_DETECT;

        $longestttl = -1;
        $bucket = null;
        foreach ($definitions as $definition) {
            $state = $states[$definition['name']] ?? null;
            if ($state === null) {
                continue;
            }
            $limit = $definition['limit'];

            if ($definition['name'] === 'abuseip') {
                if ($ipmode === rate_limit_policy::IP_MODE_OFF) {
                    continue;
                }
                $blocksaddress = ($ipmode === rate_limit_policy::IP_MODE_BLOCK)
                    || ($hardlimit > 0 && (int)$state['count'] >= $hardlimit);
                if (!$blocksaddress) {
                    continue;
                }
            }

            if ($definition['name'] === 'combo') {
                if ($elevated) {
                    // Progressive protection: an address that is already generating
                    // abusive volume gets a tighter per-account allowance instead of a
                    // blanket block.
                    $limit = min($limit, $this->policy->progressive_combo_limit());
                }
                // The combined bucket was incremented atomically at admission, so the
                // value already includes the attempt being decided right now. The
                // allowance is the number of attempts that may proceed, so it is only
                // crossed once the reserved count exceeds it.
                $crossed = (int)$state['count'] > $limit;
            } else {
                // The failure buckets are read here before the current attempt has been
                // recorded, so they are crossed as soon as the count reaches the limit.
                $crossed = (int)$state['count'] >= $limit;
            }

            if ($crossed && (int)$state['ttl'] > $longestttl) {
                $longestttl = (int)$state['ttl'];
                $bucket = $definition['name'];
            }
        }

        if ($bucket !== null) {
            $result['blocked'] = true;
            $result['retry_after'] = $this->policy->clamp_retry(max(0, $longestttl));
            $result['bucket'] = $bucket;
            $result['challenged'] = false;
        }
        return $result;
    }

    /**
     * Applies the configured degraded-mode policy when no counter store is usable.
     *
     * The decision is never silent: whichever branch is taken, a structured
     * `ratelimit_degraded` event is emitted so the outage is visible to monitoring.
     *
     * @param string $identifier Normalised username or email.
     * @param array{blocked:bool,retry_after:int,bucket:string|null,challenged:bool,abuse_level:int,degraded:bool} $result
     * @return array{blocked:bool,retry_after:int,bucket:string|null,challenged:bool,abuse_level:int,degraded:bool}
     */
    private function degraded_result(string $identifier, array $result): array {
        $failopen = $this->policy->degraded_fail_open();
        $this->log('ratelimit_degraded', [
            'mode' => $this->policy->degraded_mode(),
            'action' => $failopen ? 'allow' : 'deny',
            'identifier_sha' => sha1($identifier !== '' ? $identifier : '__empty__'),
            'ip_sha' => sha1($this->clientip),
        ]);

        $result['degraded'] = true;
        if (!$failopen) {
            $result['blocked'] = true;
            $result['retry_after'] = $this->policy->clamp_retry($this->policy->minimum_retry());
            $result['bucket'] = 'degraded';
        }
        return $result;
    }

    /**
     * Records a finished authentication attempt.
     *
     * On FAILURE the failure buckets (`account`, `abuseip`) are incremented; the shared
     * combined bucket was already reserved atomically at admission and is deliberately
     * not charged again. On SUCCESS the account-specific buckets — including that
     * admission reservation — are cleared while the shared per-IP abuse counter is
     * preserved. NEUTRAL is a no-op. Every write goes through invoke(), so a primary
     * backend that starts failing mid-request hands the accounting over to the fallback
     * instead of dropping the increment, and each successful write is also mirrored onto
     * the standby store so a later transition or recovery cannot lose the count.
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
        $this->operationfailed = false;
        if ($this->store() === null) {
            $this->log('ratelimit_degraded', [
                'mode' => $this->policy->degraded_mode(),
                'action' => 'record_skipped',
                'outcome' => $outcome,
                'ip_sha' => sha1($this->clientip),
            ]);
            return;
        }

        $keys = $this->keys($identifier);
        if ($outcome === login_outcome::SUCCESS) {
            $this->invoke('clear', [$keys['account']]);
            $this->mirror_clear($keys['account']);
            $this->invoke('clear', [$keys['combo']]);
            $this->mirror_clear($keys['combo']);
            return;
        }

        $ipmode = $this->policy->ip_mode();
        foreach ($this->policy->buckets() as $definition) {
            $name = $definition['name'];
            if ($name === 'combo') {
                // The combined bucket was already incremented atomically at admission,
                // so a failure must not charge the same attempt to it a second time.
                continue;
            }
            if ($name === 'abuseip' && $ipmode === rate_limit_policy::IP_MODE_OFF) {
                continue;
            }
            $this->invoke('hit', [$keys[$name], $definition['window']]);
            $this->mirror_hit($keys[$name], $definition['window']);
        }

        if ($this->operationfailed) {
            // The attempt could not be accounted on any store. It is never silently
            // dropped: the failure is surfaced so the outage is visible to monitoring.
            $this->log('ratelimit_degraded', [
                'mode' => $this->policy->degraded_mode(),
                'action' => 'record_failed',
                'outcome' => $outcome,
                'ip_sha' => sha1($this->clientip),
            ]);
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
