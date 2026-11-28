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
 * Redis-backed counter store using a restricted ACL user.
 *
 * Counters are incremented atomically by a server-side Lua script so concurrent
 * authentication requests can never lose an update, and expiry is always ensured for
 * the key so a restriction can never become permanent. Only the commands the
 * production ACL user is granted are used: INCR, EXPIRE, TTL, GET, SET, DEL, PING and
 * EVAL.
 *
 * The admission reservation is refunded by a second Lua script. It is deliberately
 * written with GET/SET/TTL/EXPIRE rather than DECR, because the production ACL grants no
 * DECR permission; because Redis checks the ACL for every command a script calls, a
 * `redis.call('DECR', ...)` would be denied. The decrement is a server-side
 * compare-and-swap (read, clamp at zero, write the value back with SET and keep the
 * remaining lifetime) so two concurrent releases can never lose an update or drive the
 * counter below zero, and no new ACL permission is required.
 *
 * Every key is forced into the `ulms:auth:` namespace. Plaintext identifiers are never
 * sent to Redis: the caller supplies an already-hashed logical key.
 *
 * Every reply is validated. The production phpredis extension reports a failing command
 * in two different ways — it throws a `RedisException` for a command error (an ACL
 * denial, an out-of-memory rejection under the noeviction policy, a read timeout) and it
 * returns `false` when a command could not be executed at all — so a reply that is not
 * exactly what was expected is treated as a runtime failure and the limiter hands the
 * request to the fallback. In particular a failed GET is never mistaken for a missing
 * key, and a counter that has somehow lost its expiry is repaired in place rather than
 * being left to restrict a client forever.
 *
 * @package    local_ulms_auth
 * @copyright  2026 Bells University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class redis_counter_store implements counter_store {
    /** Mandatory key namespace enforced by the production ACL. */
    public const KEY_PREFIX = 'ulms:auth:';

    /**
     * Atomic increment + guaranteed expiry. Returns {count, ttl}.
     *
     * The TTL is set whenever the key has no expiry (a fresh key reports -1, and a key
     * that somehow lost its expiry also reports -1), so the restriction can never become
     * permanent even if that key was not the one that created the counter.
     */
    private const HIT_SCRIPT = <<<'LUA'
local c = redis.call('INCR', KEYS[1])
local t = redis.call('TTL', KEYS[1])
if t < 0 then
  redis.call('EXPIRE', KEYS[1], ARGV[1])
  t = tonumber(ARGV[1])
end
return {c, t}
LUA;

    /**
     * Atomic release of one reservation, clamped at zero, preserving the remaining
     * lifetime. Returns 1 on success.
     *
     * Written only from commands the production ACL grants (GET, SET, TTL, EXPIRE) so it
     * needs no `DECR`; the decrement is a server-side compare-and-swap. A missing key, a
     * non-numeric value or a value already at zero is a successful no-op: a reservation
     * that has expired or was never taken can never turn a release into a decrement of
     * some other request's recorded failure.
     *
     * The remaining lifetime is deliberately preserved rather than cleared, mirroring the
     * file store. A refunded reservation therefore keeps the window it was created under
     * instead of starting a fresh one, so a request that is being refused cannot advertise
     * a freshly-extended retry window, and the key expires naturally with its window. The
     * value is written back even when it reaches zero, so the window start is not lost.
     */
    private const RELEASE_SCRIPT = <<<'LUA'
local v = redis.call('GET', KEYS[1])
if v == false then
  return 1
end
local n = tonumber(v)
if n == nil or n <= 0 then
  return 1
end
n = n - 1
local t = redis.call('TTL', KEYS[1])
if t < 0 then
  redis.call('SET', KEYS[1], n)
  redis.call('EXPIRE', KEYS[1], ARGV[1])
else
  redis.call('SET', KEYS[1], n, 'KEEPTTL')
end
return 1
LUA;

    /** @var string */
    private string $host;
    /** @var int */
    private int $port;
    /** @var string */
    private string $acluser;
    /** @var string */
    private string $secretfile;
    /** @var float */
    private float $connecttimeout;
    /** @var float */
    private float $readtimeout;
    /** @var redis_client */
    private redis_client $client;
    /** @var bool Whether the client was injected (so the extension is not required). */
    private bool $clientinjected;
    /** @var bool Whether the connection has been established for this request. */
    private bool $ready = false;
    /** @var bool */
    private bool $unavailable = false;
    /** @var string|null */
    private ?string $lastfailure = null;
    /** @var string|null */
    private ?string $lastfailurecategory = null;

    /**
     * Constructor.
     *
     * @param string $host Redis host.
     * @param int $port Redis port.
     * @param string $acluser ACL username.
     * @param string $secretfile Path to the credential file (never stored in code).
     * @param float $connecttimeout Connection timeout in seconds.
     * @param float $readtimeout Read timeout in seconds.
     * @param redis_client|null $client Injectable client, or null for the phpredis adapter.
     */
    public function __construct(
        string $host,
        int $port,
        string $acluser,
        string $secretfile,
        float $connecttimeout,
        float $readtimeout,
        ?redis_client $client = null
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->acluser = $acluser;
        $this->secretfile = $secretfile;
        $this->connecttimeout = max(0.1, $connecttimeout);
        $this->readtimeout = max(0.1, $readtimeout);
        $this->clientinjected = $client !== null;
        $this->client = $client ?? new phpredis_client();
    }

    /**
     * Builds an instance from the plugin settings and safe defaults.
     *
     * @return self
     */
    public static function from_config(): self {
        return new self(
            (string)(get_config('local_ulms_auth', 'redishost') ?: '127.0.0.1'),
            (int)(get_config('local_ulms_auth', 'redisport') ?: 6379),
            (string)(get_config('local_ulms_auth', 'redisacluser') ?: 'ulms_auth'),
            (string)(get_config('local_ulms_auth', 'rediscredentialfile') ?: '/etc/ulms/redis-auth.secret'),
            (float)(get_config('local_ulms_auth', 'redisconnecttimeout') ?: 1.5),
            (float)(get_config('local_ulms_auth', 'redisreadtimeout') ?: 1.5)
        );
    }

    /**
     * Reports whether the phpredis extension is loaded.
     *
     * @return bool
     */
    public static function extension_loaded(): bool {
        return class_exists(\Redis::class);
    }

    /**
     * Returns the last recorded failure reason, for logging.
     *
     * @return string|null
     */
    public function last_failure(): ?string {
        return $this->lastfailure;
    }

    /**
     * Returns the classified category of the last failure, for triage.
     *
     * Categories are stable strings the runbook and monitoring can key on:
     *   config        - extension missing or credential file unreadable
     *   connect       - TCP connection refused, reset, or lost after initialisation
     *   auth          - ACL authentication rejected
     *   permission    - NOPERM: the ACL user is not allowed to run the command
     *   memory        - OOM: memory exhausted under the production noeviction policy
     *   timeout       - read/connect timeout
     *   response      - unexpected, malformed or failed command reply
     *   exception     - any other driver error
     *
     * @return string|null
     */
    public function last_failure_category(): ?string {
        return $this->lastfailurecategory;
    }

    /**
     * Lazily connects, authenticates and verifies the connection with PING.
     *
     * @return redis_client|null
     */
    private function connection(): ?redis_client {
        if ($this->ready) {
            return $this->client;
        }
        if ($this->unavailable) {
            return null;
        }
        if (!$this->clientinjected && !self::extension_loaded()) {
            return $this->fail('extension_missing', 'config');
        }

        $password = $this->read_secret();
        if ($password === null) {
            return $this->fail('credential_unavailable', 'config');
        }

        try {
            if (!$this->client->connect($this->host, $this->port, $this->connecttimeout, $this->readtimeout)) {
                return $this->fail('connect_failed', 'connect');
            }
            if (!$this->client->auth($this->acluser, $password)) {
                return $this->fail('auth_failed', 'auth');
            }
            if (!self::valid_ping($this->client->ping())) {
                // phpredis answers PING with `true` or the string `+PONG`; anything else
                // means the connection is not actually usable even though it opened.
                return $this->fail('ping_failed', 'response');
            }
        } catch (\Throwable $exception) {
            return $this->fail('connect_exception', self::classify_exception($exception));
        }

        $this->ready = true;
        return $this->client;
    }

    /**
     * Validates a PING reply.
     *
     * @param mixed $reply Raw reply.
     * @return bool
     */
    private static function valid_ping(mixed $reply): bool {
        if ($reply === true || $reply === 1) {
            return true;
        }
        if (is_string($reply)) {
            return strcasecmp(trim($reply, "+ \t\n\r\0\x0B"), 'PONG') === 0;
        }
        return false;
    }

    /**
     * Reads the credential from the configured file.
     *
     * The file is expected to contain the ACL password (optionally as `user:password`
     * or a bare password). It is never logged.
     *
     * @return string|null
     */
    private function read_secret(): ?string {
        if ($this->secretfile === '' || !is_readable($this->secretfile)) {
            return null;
        }
        $raw = @file_get_contents($this->secretfile);
        if ($raw === false) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (strpos($raw, ':') !== false && substr_count($raw, ':') >= 1 && strpos($raw, "\n") === false) {
            $parts = explode(':', $raw, 2);
            if (count($parts) === 2 && $parts[0] !== '') {
                return $parts[1];
            }
        }
        return $raw;
    }

    /**
     * Marks this instance unavailable and records a scrubbed, classified reason.
     *
     * The instance stops attempting to reconnect for the remainder of the request so a
     * dead or misbehaving backend cannot stall sign-in with repeated timeouts. Recovery
     * is immediate on the next request, because the limiter builds a fresh store (and
     * therefore a fresh connection) every time it runs. When an operation fails *after*
     * a successful connection the limiter is told the reason and hands the request over
     * to the file fallback rather than dropping the increment.
     *
     * @param string $reason Machine-readable reason string.
     * @param string|null $category One of the documented failure categories, or null to classify.
     * @return null
     */
    private function fail(string $reason, ?string $category = null): ?redis_client {
        $this->unavailable = true;
        $this->ready = false;
        $this->lastfailure = $reason;
        $this->lastfailurecategory = $category ?? self::categorise($reason);
        return null;
    }

    /**
     * Maps a driver exception to a stable failure category.
     *
     * Redis reports memory exhaustion ("OOM command not allowed when used memory >
     * 'maxmemory'"), ACL denials ("NOPERM ...") and connection problems as plain error
     * strings, so the message has to be inspected. The category is what the operator
     * sees in the degraded-mode event, and it is the difference between "Redis is out of
     * memory", "the ACL user lost a permission" and "the network dropped the connection".
     *
     * @param \Throwable $exception Driver exception.
     * @return string
     */
    private static function classify_exception(\Throwable $exception): string {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'noperm') || str_contains($message, 'no permissions')) {
            return 'permission';
        }
        if (str_contains($message, 'oom') || str_contains($message, 'maxmemory')) {
            return 'memory';
        }
        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return 'timeout';
        }
        if (str_contains($message, 'connection lost') || str_contains($message, 'went away')
                || str_contains($message, 'read error') || str_contains($message, 'broken pipe')
                || str_contains($message, 'connection reset')) {
            return 'connect';
        }
        return 'exception';
    }

    /**
     * Derives a category from a static reason string.
     *
     * @param string $reason
     * @return string
     */
    private static function categorise(string $reason): string {
        return match ($reason) {
            'extension_missing', 'credential_unavailable' => 'config',
            'connect_failed', 'connect_exception' => 'connect',
            'auth_failed' => 'auth',
            'unexpected_response', 'partial_update', 'inspect_response',
                'del_response', 'release_response', 'ping_failed', 'inspect_repair_failed' => 'response',
            default => 'exception',
        };
    }

    /**
     * Builds a fully namespaced Redis key.
     *
     * @param string $key Logical key from the caller.
     * @return string
     */
    private function namespaced(string $key): string {
        $key = ltrim($key, ':');
        if (strpos($key, self::KEY_PREFIX) === 0) {
            return $key;
        }
        return self::KEY_PREFIX . $key;
    }

    /**
     * Atomically increments a counter and guarantees expiry.
     *
     * @param string $key
     * @param int $windowseconds
     * @return array{count:int,ttl:int}|null
     */
    public function hit(string $key, int $windowseconds): ?array {
        $redis = $this->connection();
        if ($redis === null) {
            return null;
        }
        try {
            $result = $redis->eval(self::HIT_SCRIPT, [$this->namespaced($key), max(1, $windowseconds)], 1);
        } catch (\Throwable $exception) {
            return $this->fail('eval_exception', self::classify_exception($exception));
        }
        if (!is_array($result) || count($result) < 2 || !is_numeric($result[0]) || !is_numeric($result[1])) {
            // A partial or malformed reply means the INCR may or may not have landed.
            // The counter is only ever used to restrict, so an uncertain update is never
            // allowed to under-count: the limiter re-applies the increment in the file
            // fallback, which can only over-count in the safe direction.
            return $this->fail(
                is_array($result) ? 'partial_update' : 'unexpected_response',
                'response'
            );
        }
        return [
            'count' => max(0, (int)$result[0]),
            'ttl' => max(0, (int)$result[1]),
        ];
    }

    /**
     * Returns the current counter value and remaining lifetime without incrementing.
     *
     * A missing key is confirmed by Redis's own sentinel: GET returns `false` *and* TTL
     * returns -2. If GET looks empty but TTL says the key is not missing, the read did
     * not genuinely return an empty counter and is reported as a failure instead of being
     * treated as "no restriction". A counter that exists without an expiry (TTL -1) is
     * repaired with EXPIRE, because a restriction must never be permanent.
     *
     * @param string $key
     * @param int $windowseconds
     * @return array{count:int,ttl:int}|null
     */
    public function inspect(string $key, int $windowseconds): ?array {
        $redis = $this->connection();
        if ($redis === null) {
            return null;
        }
        $namespaced = $this->namespaced($key);
        try {
            $value = $redis->get($namespaced);
            $ttl = $redis->ttl($namespaced);
        } catch (\Throwable $exception) {
            return $this->fail('inspect_exception', self::classify_exception($exception));
        }

        if ($value === false || $value === null) {
            if ($ttl === -2) {
                // GET returned nothing and TTL confirms the key does not exist.
                return ['count' => 0, 'ttl' => 0];
            }
            // GET reported an empty value but the key is not actually missing, so the
            // command did not run as expected. Never read this as "no restriction".
            return $this->fail('inspect_response', 'response');
        }

        if (is_array($value) || is_object($value) || !is_numeric($value)) {
            return $this->fail('inspect_response', 'response');
        }

        if (!is_int($ttl)) {
            // `false`, a string, or any structured reply is not a valid TTL.
            return $this->fail('inspect_response', 'response');
        }
        if ($ttl === -2) {
            // The key expired between the GET and the TTL: report an empty window.
            return ['count' => 0, 'ttl' => 0];
        }
        if ($ttl < 0) {
            return $this->repair_expiry($redis, $namespaced, (int)$value, $windowseconds);
        }

        return [
            'count' => max(0, (int)$value),
            'ttl' => max(0, $ttl),
        ];
    }

    /**
     * Repairs a counter that exists without an expiry so it cannot restrict forever.
     *
     * @param redis_client $redis Established client.
     * @param string $namespaced Fully namespaced key.
     * @param int $count Observed counter value.
     * @param int $windowseconds Window to re-apply.
     * @return array{count:int,ttl:int}|null
     */
    private function repair_expiry(redis_client $redis, string $namespaced, int $count, int $windowseconds): ?array {
        $window = max(1, $windowseconds);
        try {
            $applied = $redis->expire($namespaced, $window);
        } catch (\Throwable $exception) {
            return $this->fail('inspect_repair_exception', self::classify_exception($exception));
        }
        if ($applied !== true && $applied !== 1) {
            return $this->fail('inspect_repair_failed', 'response');
        }
        return ['count' => max(0, $count), 'ttl' => $window];
    }

    /**
     * Deletes a counter.
     *
     * DEL returns the number of keys removed (0 when the counter was already absent);
     * any other reply means the command did not demonstrably run and is reported as a
     * failure so the limiter can fall back rather than assume the counter was cleared.
     *
     * @param string $key
     * @return bool
     */
    public function clear(string $key): bool {
        $redis = $this->connection();
        if ($redis === null) {
            return false;
        }
        try {
            $deleted = $redis->del($this->namespaced($key));
        } catch (\Throwable $exception) {
            $this->fail('del_exception', self::classify_exception($exception));
            return false;
        }
        if (!is_int($deleted)) {
            $this->fail('del_response', 'response');
            return false;
        }
        return true;
    }

    /**
     * Releases one admission reservation atomically, clamped at zero.
     *
     * The refund is a single EVAL, so two concurrent releases are serialised by Redis,
     * the value can never go negative, and the reservation's remaining lifetime is
     * preserved. A missing key or an elapsed window is a successful no-op — releasing a
     * reservation that no longer exists must never fail the request, and must never
     * touch the failure ledger, which is a separate key.
     *
     * @param string $key
     * @param int $windowseconds
     * @return bool
     */
    public function release(string $key, int $windowseconds): bool {
        $redis = $this->connection();
        if ($redis === null) {
            return false;
        }
        try {
            $result = $redis->eval(self::RELEASE_SCRIPT, [$this->namespaced($key), max(1, $windowseconds)], 1);
        } catch (\Throwable $exception) {
            $this->fail('release_exception', self::classify_exception($exception));
            return false;
        }
        // The script always returns the integer 1 on success, and only never returns
        // when Redis could not run it at all. Anything else means the reply cannot be
        // trusted, so the caller is told the release failed and the accounting is
        // completed on the fallback rather than silently dropped.
        if ($result !== 1) {
            $this->fail('release_response', 'response');
            return false;
        }
        return true;
    }

    /**
     * Reports whether the backend is reachable.
     *
     * @return bool
     */
    public function is_available(): bool {
        return $this->connection() !== null;
    }

    /**
     * Backend identifier.
     *
     * @return string
     */
    public function name(): string {
        return 'redis';
    }
}
