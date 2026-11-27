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
 * authentication requests can never lose an update, and expiry is always set when the
 * key is created so a restriction can never become permanent. Only the commands the
 * production ACL user is granted are used: INCR, EXPIRE, TTL, GET, DEL, PING and EVAL.
 *
 * Every key is forced into the `ulms:auth:` namespace. Plaintext identifiers are never
 * sent to Redis: the caller supplies an already-hashed logical key.
 *
 * @package    local_ulms_auth
 * @copyright  2026 Bells University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class redis_counter_store implements counter_store {
    /** Mandatory key namespace enforced by the production ACL. */
    public const KEY_PREFIX = 'ulms:auth:';

    /** Atomic increment + guaranteed expiry. Returns {count, ttl}. */
    private const HIT_SCRIPT = <<<'LUA'
local c = redis.call('INCR', KEYS[1])
if c == 1 then
  redis.call('EXPIRE', KEYS[1], ARGV[1])
end
local t = redis.call('TTL', KEYS[1])
return {c, t}
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
    /** @var \Redis|null */
    private ?\Redis $redis = null;
    /** @var bool */
    private bool $unavailable = false;
    /** @var string|null */
    private ?string $lastfailure = null;

    /**
     * Constructor.
     *
     * @param string $host Redis host.
     * @param int $port Redis port.
     * @param string $acluser ACL username.
     * @param string $secretfile Path to the credential file (never stored in code).
     * @param float $connecttimeout Connection timeout in seconds.
     * @param float $readtimeout Read timeout in seconds.
     */
    public function __construct(
        string $host,
        int $port,
        string $acluser,
        string $secretfile,
        float $connecttimeout,
        float $readtimeout
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->acluser = $acluser;
        $this->secretfile = $secretfile;
        $this->connecttimeout = max(0.1, $connecttimeout);
        $this->readtimeout = max(0.1, $readtimeout);
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
     * Lazily connects and authenticates.
     *
     * @return \Redis|null
     */
    private function connection(): ?\Redis {
        if ($this->redis !== null) {
            return $this->redis;
        }
        if ($this->unavailable) {
            return null;
        }
        if (!self::extension_loaded()) {
            return $this->fail('extension_missing');
        }

        $password = $this->read_secret();
        if ($password === null) {
            return $this->fail('credential_unavailable');
        }

        try {
            $redis = new \Redis();
            $connected = $redis->connect($this->host, $this->port, $this->connecttimeout, null, 0, $this->readtimeout);
            if ($connected !== true) {
                return $this->fail('connect_failed');
            }
            $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
            if ($this->acluser !== '') {
                if ($redis->auth([$this->acluser, $password]) !== true) {
                    return $this->fail('auth_failed');
                }
            } else if ($redis->auth($password) !== true) {
                return $this->fail('auth_failed');
            }
            $redis->ping();
            $this->redis = $redis;

            return $this->redis;
        } catch (\Throwable $exception) {
            return $this->fail('connect_exception');
        }
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
     * Marks the backend unavailable and records a scrubbed reason.
     *
     * @param string $reason
     * @return null
     */
    private function fail(string $reason): ?\Redis {
        $this->unavailable = true;
        $this->lastfailure = $reason;
        $this->redis = null;
        return null;
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
            $this->fail('eval_exception');
            return null;
        }
        if (!is_array($result) || count($result) < 2) {
            $this->fail('unexpected_response');
            return null;
        }
        return [
            'count' => (int)$result[0],
            'ttl' => max(0, (int)$result[1]),
        ];
    }

    /**
     * Returns the current counter value and remaining lifetime without incrementing.
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
        try {
            $namespaced = $this->namespaced($key);
            $value = $redis->get($namespaced);
            $ttl = $redis->ttl($namespaced);
        } catch (\Throwable $exception) {
            $this->fail('inspect_exception');
            return null;
        }
        if ($value === false || $value === null) {
            return ['count' => 0, 'ttl' => 0];
        }
        return [
            'count' => (int)$value,
            'ttl' => max(0, (int)$ttl),
        ];
    }

    /**
     * Deletes a counter.
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
            $redis->del($this->namespaced($key));
            return true;
        } catch (\Throwable $exception) {
            $this->fail('del_exception');
            return false;
        }
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
