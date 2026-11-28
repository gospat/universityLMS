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
 * Production {@see redis_client} backed by the phpredis extension.
 *
 * This is a deliberately thin adapter: it forwards to `\Redis` and normalises only
 * the two things the store cares about — that authentication is attempted as the
 * configured ACL user, and that the serializer is disabled so counter values round
 * trip as plain integer strings rather than serialised payloads.
 *
 * The underlying `\Redis` handle is created lazily, on the first command, so that
 * merely constructing the adapter (for example while the rate limiter is deciding
 * whether the extension is even present) never requires the phpredis class to exist.
 *
 * @package    local_ulms_auth
 * @copyright  2026 Bells University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class phpredis_client implements redis_client {
    /** @var \Redis|null */
    private ?\Redis $redis = null;

    /**
     * Returns the underlying handle, creating it on first use.
     *
     * @return \Redis
     */
    private function handle(): \Redis {
        if ($this->redis === null) {
            $this->redis = new \Redis();
        }
        return $this->redis;
    }

    /**
     * Opens a connection and disables the serializer.
     *
     * @param string $host
     * @param int $port
     * @param float $timeout
     * @param float $readtimeout
     * @return bool
     */
    public function connect(string $host, int $port, float $timeout, float $readtimeout): bool {
        $connected = $this->handle()->connect($host, $port, $timeout, null, 0, $readtimeout);
        if ($connected !== true) {
            return false;
        }
        $this->handle()->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
        return true;
    }

    /**
     * Authenticates, optionally as a named ACL user.
     *
     * @param string $user
     * @param string $password
     * @return bool
     */
    public function auth(string $user, string $password): bool {
        if ($user === '') {
            return $this->handle()->auth($password) === true;
        }
        return $this->handle()->auth([$user, $password]) === true;
    }

    /**
     * Sends PING.
     *
     * @return mixed
     */
    public function ping(): mixed {
        return $this->handle()->ping();
    }

    /**
     * Reads a string key.
     *
     * @param string $key
     * @return mixed
     */
    public function get(string $key): mixed {
        return $this->handle()->get($key);
    }

    /**
     * Returns the remaining lifetime of a key in seconds.
     *
     * @param string $key
     * @return mixed
     */
    public function ttl(string $key): mixed {
        return $this->handle()->ttl($key);
    }

    /**
     * Deletes a key.
     *
     * @param string $key
     * @return mixed
     */
    public function del(string $key): mixed {
        return $this->handle()->del($key);
    }

    /**
     * Sets an expiry on a key.
     *
     * @param string $key
     * @param int $seconds
     * @return mixed
     */
    public function expire(string $key, int $seconds): mixed {
        return $this->handle()->expire($key, $seconds);
    }

    /**
     * Evaluates a server-side Lua script.
     *
     * @param string $script
     * @param array<int, mixed> $arguments
     * @param int $numkeys
     * @return mixed
     */
    public function eval(string $script, array $arguments, int $numkeys): mixed {
        return $this->handle()->eval($script, $arguments, $numkeys);
    }
}
