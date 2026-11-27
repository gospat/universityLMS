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
 * Storage contract for the login rate limiter counters.
 *
 * An implementation must guarantee that hit() is an atomic, race-free increment that
 * also guarantees expiry for the counter key, so that concurrent authentication
 * requests can never lose an update and a restriction can never become permanent.
 *
 * All methods return null (from hit()) or false (from is_available()) on a storage
 * failure, so the caller can fall back safely. Implementations must never throw for
 * an infrastructure failure.
 *
 * @package    local_ulms_auth
 * @copyright  2026 Bells University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface counter_store {
    /**
     * Atomically increments a counter and guarantees it expires within the window.
     *
     * @param string $key Bucket key (already namespaced/hashed by the caller).
     * @param int $windowseconds Window length in seconds; the counter expires after it.
     * @return array{count:int,ttl:int}|null Null when the storage is unavailable.
     */
    public function hit(string $key, int $windowseconds): ?array;

    /**
     * Returns the current counter value and remaining lifetime without incrementing it.
     *
     * @param string $key Bucket key (already namespaced/hashed by the caller).
     * @param int $windowseconds Window length in seconds.
     * @return array{count:int,ttl:int}|null Null when the storage is unavailable.
     */
    public function inspect(string $key, int $windowseconds): ?array;

    /**
     * Deletes a counter.
     *
     * @param string $key Bucket key.
     * @return bool True when the operation succeeded.
     */
    public function clear(string $key): bool;

    /**
     * Reports whether the storage is reachable.
     *
     * @return bool
     */
    public function is_available(): bool;

    /**
     * Returns a short identifier for the storage backend, for logging.
     *
     * @return string
     */
    public function name(): string;
}
