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
 * The minimal Redis client contract the counter store depends on.
 *
 * The store never touches the phpredis extension directly; it drives a small,
 * explicit set of commands through this seam. The production implementation
 * ({@see phpredis_client}) delegates to the real extension. The seam exists so the
 * store's reply validation can be exercised deterministically — including the two
 * different ways phpredis reports failure (a thrown `RedisException` for a command
 * error, and a `false` return when a command could not be executed at all) — without
 * requiring a live server or an ACL change.
 *
 * @package    local_ulms_auth
 * @copyright  2026 Bells University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface redis_client {
    /**
     * Opens a connection.
     *
     * @param string $host Host.
     * @param int $port Port.
     * @param float $timeout Connect timeout in seconds.
     * @param float $readtimeout Read timeout in seconds.
     * @return bool True on success.
     */
    public function connect(string $host, int $port, float $timeout, float $readtimeout): bool;

    /**
     * Authenticates, optionally as a named ACL user.
     *
     * @param string $user ACL username, or an empty string for the default user.
     * @param string $password Password (never logged).
     * @return bool True on success.
     */
    public function auth(string $user, string $password): bool;

    /**
     * Sends PING.
     *
     * @return mixed `true`/`PONG` on success, `false` or another reply otherwise.
     */
    public function ping(): mixed;

    /**
     * Reads a string key.
     *
     * @param string $key
     * @return mixed The value, `false` for a missing key.
     */
    public function get(string $key): mixed;

    /**
     * Returns the remaining lifetime of a key in seconds.
     *
     * @param string $key
     * @return mixed `-1` (no expiry), `-2` (missing), a positive TTL, or `false` on failure.
     */
    public function ttl(string $key): mixed;

    /**
     * Deletes a key.
     *
     * @param string $key
     * @return mixed The number of keys removed, or `false` on failure.
     */
    public function del(string $key): mixed;

    /**
     * Sets an expiry on a key.
     *
     * @param string $key
     * @param int $seconds
     * @return mixed `true`/`1` when the expiry was set.
     */
    public function expire(string $key, int $seconds): mixed;

    /**
     * Evaluates a server-side Lua script.
     *
     * @param string $script Lua source.
     * @param array<int, mixed> $arguments KEYS followed by ARGV.
     * @param int $numkeys Number of leading KEYS entries.
     * @return mixed The script reply.
     */
    public function eval(string $script, array $arguments, int $numkeys): mixed;
}
