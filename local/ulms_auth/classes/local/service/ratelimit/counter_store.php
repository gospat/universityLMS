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
 * Note carefully the two distinct kinds of atomicity. The counter update is atomic in
 * every implementation. Admission is also atomic: the limiter separates a bucket into a
 * *failure ledger* (hit()/inspect()/clear()) and a *reservation* counter. At the
 * pre-login gate it reserves a slot with hit() on the reservation key for both the
 * combined and the per-account bucket, before the credential is verified, so a burst of
 * simultaneous requests cannot all pass the gate and only record their failures
 * afterwards — the reservation that crosses the allowance is refused at admission time.
 * Once the outcome is known the reservation is released with release(): it becomes a
 * recorded failure on a genuine credential failure, is simply released for a neutral
 * outcome, and the whole account ledger is cleared on success. Because a release only
 * ever touches the reservation counter, a concurrent request can never consume or clear
 * another request's recorded failure. The shared per-IP abuse bucket stays a pure failure
 * ledger, read at the gate and written on the outcome; see the README for the concurrency
 * contract.
 *
 * All read/write methods return null (from hit()/inspect()) or false (from
 * clear()/release()) on a storage failure, so the caller can fall back safely, and
 * last_failure() explains a runtime failure so the caller can distinguish a transient
 * outage (reconnect, timeout, memory pressure) from a permanent misconfiguration.
 * Implementations must never throw for an infrastructure failure.
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
     * Atomically releases one outstanding reservation from a counter, clamped at zero.
     *
     * This is the counterpart to the admission reservation taken by hit() and is what
     * lets a neutral authentication outcome be refunded without decrementing a failure
     * ledger. The decrement must be atomic (a compare-and-swap, not a read-modify-write
     * in PHP) so two concurrent releases cannot lose an update or drive the counter
     * below zero, and the key's remaining lifetime must be preserved so an abandoned
     * reservation still expires with the window it was created under. A key that does
     * not exist, or one whose window has already elapsed, is a successful no-op.
     *
     * @param string $key Bucket key (already namespaced/hashed by the caller).
     * @param int $windowseconds Window length in seconds, used to detect an elapsed window.
     * @return bool True when the operation succeeded; false on a storage failure.
     */
    public function release(string $key, int $windowseconds): bool;

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

    /**
     * Returns the reason for the most recent runtime failure, for logging and triage.
     *
     * This is what lets the limiter decide that a connected backend has started failing
     * operations (memory exhaustion, permission error, timeout, lost connection,
     * malformed response) and hand over to the fallback store instead of silently
     * dropping every increment for the rest of the request.
     *
     * @return string|null Null when no failure has been observed.
     */
    public function last_failure(): ?string;
}
