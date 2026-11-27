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

namespace local_ulms_auth;

use local_ulms_auth\local\service\ratelimit\counter_store;
use local_ulms_auth\local\service\ratelimit\file_counter_store;
use local_ulms_auth\local\service\ratelimit\login_outcome;
use local_ulms_auth\local\service\ratelimit\login_rate_limiter;
use local_ulms_auth\local\service\ratelimit\rate_limit_policy;

defined('MOODLE_INTERNAL') || die();

/**
 * Behavioural tests for the login rate limiter using an isolated file store.
 *
 * @package    local_ulms_auth
 * @copyright  2026 Bells University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ulms_auth\local\service\ratelimit\login_rate_limiter
 * @covers     \local_ulms_auth\local\service\ratelimit\rate_limit_policy
 */
final class ratelimit_limiter_test extends \advanced_testcase {
    /**
     * Builds a limiter backed by a fresh isolated file store.
     *
     * @param rate_limit_policy $policy
     * @param string $ip
     * @return login_rate_limiter
     */
    private function limiter(rate_limit_policy $policy, string $ip = '198.51.100.10'): login_rate_limiter {
        return new login_rate_limiter($policy, new file_counter_store(make_request_directory()), $ip);
    }

    public function test_default_policy_matches_documented_thresholds(): void {
        $this->resetAfterTest(true);

        $policy = rate_limit_policy::from_config();
        $buckets = $policy->buckets();

        $this->assertSame(['combo', 'account', 'abuseip'], array_column($buckets, 'name'));
        $this->assertSame(5, $buckets[0]['limit']);
        $this->assertSame(180, $buckets[0]['window']);
        $this->assertSame(10, $buckets[1]['limit']);
        $this->assertSame(900, $buckets[1]['window']);
        $this->assertSame(300, $buckets[2]['limit']);
        $this->assertSame(300, $buckets[2]['window']);

        // Shared-IP handling defaults to progressive detection, not a blanket block, and
        // the degraded backend mode defaults to keeping sign-in available.
        $this->assertSame(rate_limit_policy::IP_MODE_DETECT, $policy->ip_mode());
        $this->assertSame(2, $policy->progressive_combo_limit());
        $this->assertSame(0, $policy->abuse_ip_hard_limit());
        $this->assertSame(rate_limit_policy::DEGRADED_FAILOPEN, $policy->degraded_mode());
        $this->assertTrue($policy->degraded_fail_open());
    }

    public function test_repeated_failures_block_the_combined_bucket(): void {
        $policy = new rate_limit_policy(true, 'file', true, true, 3, 180, 10, 900, 300, 300, 1, 900);
        $limiter = $this->limiter($policy);

        $this->assertFalse($limiter->check('student1')['blocked']);
        $limiter->record('student1', login_outcome::FAILURE);
        $limiter->record('student1', login_outcome::FAILURE);
        $this->assertFalse($limiter->check('student1')['blocked']);

        $limiter->record('student1', login_outcome::FAILURE);
        $result = $limiter->check('student1');

        $this->assertTrue($result['blocked']);
        $this->assertSame('combo', $result['bucket']);
        $this->assertGreaterThanOrEqual(1, $result['retry_after']);
    }

    public function test_success_clears_account_counters(): void {
        $policy = new rate_limit_policy(true, 'file', true, true, 99, 180, 3, 900, 300, 300, 1, 900);
        $limiter = $this->limiter($policy);

        $limiter->record('student1', login_outcome::FAILURE);
        $limiter->record('student1', login_outcome::FAILURE);
        $this->assertFalse($limiter->check('student1')['blocked']);

        $limiter->record('student1', login_outcome::SUCCESS);
        $limiter->record('student1', login_outcome::FAILURE);
        $limiter->record('student1', login_outcome::FAILURE);

        $this->assertFalse($limiter->check('student1')['blocked']);
    }

    public function test_success_does_not_reset_ip_abuse_counter(): void {
        // Shared-IP handling defaults to "detect", so crossing the abuse limit tightens
        // the allowance instead of blocking the address.
        $policy = new rate_limit_policy(true, 'file', true, true, 99, 180, 99, 900, 3, 300, 1, 900);
        $limiter = $this->limiter($policy, '203.0.113.50');

        $limiter->record('a', login_outcome::FAILURE);
        $limiter->record('b', login_outcome::FAILURE);
        $limiter->record('c', login_outcome::FAILURE);
        $this->assertTrue($limiter->check('d')['challenged']);

        $limiter->record('a', login_outcome::SUCCESS);

        $result = $limiter->check('d');
        $this->assertSame(3, $result['abuse_level'], 'A success must not reset the shared per-IP abuse counter.');
        $this->assertTrue($result['challenged']);
        $this->assertFalse($result['blocked']);
    }

    public function test_one_account_does_not_block_unrelated_accounts(): void {
        $policy = new rate_limit_policy(true, 'file', true, true, 3, 180, 10, 900, 300, 300, 1, 900);
        $limiter = $this->limiter($policy, '203.0.113.60');

        $limiter->record('victim', login_outcome::FAILURE);
        $limiter->record('victim', login_outcome::FAILURE);
        $limiter->record('victim', login_outcome::FAILURE);

        $this->assertTrue($limiter->check('victim')['blocked']);
        $this->assertFalse($limiter->check('someoneelse')['blocked']);
    }

    public function test_blocked_requests_do_not_extend_the_restriction(): void {
        $policy = new rate_limit_policy(true, 'file', true, true, 1, 120, 10, 900, 300, 300, 1, 900);
        $limiter = $this->limiter($policy);

        $limiter->record('student1', login_outcome::FAILURE);
        $first = $limiter->check('student1');
        $this->assertTrue($first['blocked']);

        for ($i = 0; $i < 5; $i++) {
            $again = $limiter->check('student1');
            $this->assertTrue($again['blocked']);
            $this->assertLessThanOrEqual($first['retry_after'], $again['retry_after']);
        }
    }

    public function test_retry_after_reflects_actual_expiry(): void {
        $policy = new rate_limit_policy(true, 'file', true, true, 1, 90, 10, 900, 300, 300, 1, 900);
        $limiter = $this->limiter($policy);

        $limiter->record('student1', login_outcome::FAILURE);
        $result = $limiter->check('student1');

        $this->assertTrue($result['blocked']);
        $this->assertGreaterThanOrEqual(85, $result['retry_after']);
        $this->assertLessThanOrEqual(90, $result['retry_after']);
    }

    public function test_neutral_outcome_does_not_change_counters(): void {
        $policy = new rate_limit_policy(true, 'file', true, true, 1, 180, 10, 900, 300, 300, 1, 900);
        $limiter = $this->limiter($policy);

        for ($i = 0; $i < 5; $i++) {
            $limiter->record('student1', login_outcome::NEUTRAL);
        }

        $this->assertFalse($limiter->check('student1')['blocked']);
    }

    public function test_invalid_outcome_is_ignored(): void {
        $policy = new rate_limit_policy(true, 'file', true, true, 1, 180, 10, 900, 300, 300, 1, 900);
        $limiter = $this->limiter($policy);

        $limiter->record('student1', 'something-else');

        $this->assertFalse($limiter->check('student1')['blocked']);
    }

    public function test_disabled_policy_never_blocks(): void {
        $policy = new rate_limit_policy(false, 'file', true, true, 1, 180, 1, 900, 1, 300, 1, 900);
        $limiter = $this->limiter($policy);

        for ($i = 0; $i < 10; $i++) {
            $limiter->record('student1', login_outcome::FAILURE);
        }

        $this->assertFalse($limiter->check('student1')['blocked']);
    }

    public function test_distributed_attempts_are_caught_by_the_account_bucket(): void {
        $policy = new rate_limit_policy(true, 'file', true, true, 5, 180, 4, 900, 300, 300, 1, 900);

        // Every address in the spray shares one counter store, exactly as the
        // backend's shared storage would. The per-IP buckets stay isolated
        // (combo keys include the IP, abuseip is per-IP) while the per-account
        // bucket is deliberately shared, so a distributed attack against a
        // single account must still trip the account threshold.
        $store = new file_counter_store(make_request_directory());
        $addresses = ['198.51.100.1', '198.51.100.2', '198.51.100.3', '198.51.100.4'];

        foreach ($addresses as $ip) {
            $limiter = new login_rate_limiter($policy, $store, $ip);
            // No single address reaches the combo limit (5) on its own.
            $this->assertFalse($limiter->check('target')['blocked']);
            $limiter->record('target', login_outcome::FAILURE);
        }

        // A fifth address is blocked purely on the per-account counter.
        $attacker = new login_rate_limiter($policy, $store, '198.51.100.5');
        $result = $attacker->check('target');
        $this->assertTrue($result['blocked']);
        $this->assertSame('account', $result['bucket']);

        // The restriction is account-scoped: an unrelated account from the
        // same fifth address is not caught up in the lockout.
        $this->assertFalse($attacker->check('unrelated')['blocked']);
    }

    public function test_ip_abuse_threshold_does_not_block_unrelated_legitimate_accounts(): void {
        // A shared campus/NAT address accumulates failures on several different accounts
        // until the per-IP abuse limit is crossed. Unrelated legitimate accounts sharing
        // that address must still be able to authenticate.
        $policy = new rate_limit_policy(true, 'file', true, true, 5, 180, 10, 900, 4, 300, 1, 900);
        $limiter = $this->limiter($policy, '203.0.113.70');

        foreach (['spray1', 'spray2', 'spray3', 'spray4'] as $account) {
            $limiter->record($account, login_outcome::FAILURE);
        }

        $result = $limiter->check('legitimate_student');
        $this->assertFalse($result['blocked'], 'A shared address must not block an unrelated account.');
        $this->assertTrue($result['challenged'], 'The address is flagged as abusive.');
        $this->assertGreaterThanOrEqual(4, $result['abuse_level']);

        // The legitimate user can complete a sign-in: a success clears their own counters.
        $limiter->record('legitimate_student', login_outcome::SUCCESS);
        $this->assertFalse($limiter->check('legitimate_student')['blocked']);
    }

    public function test_progressive_protection_tightens_the_allowance_for_an_abusive_address(): void {
        $policy = new rate_limit_policy(
            true,
            'file',
            true,
            true,
            5,
            180,
            99,
            900,
            3,
            300,
            1,
            900,
            rate_limit_policy::IP_MODE_DETECT,
            2
        );
        $limiter = $this->limiter($policy, '203.0.113.71');

        // Push the address over the per-IP abuse limit.
        $limiter->record('a', login_outcome::FAILURE);
        $limiter->record('b', login_outcome::FAILURE);
        $limiter->record('c', login_outcome::FAILURE);
        $this->assertTrue($limiter->check('d')['challenged']);

        // A single account on that address now gets the tightened allowance (2), not the
        // full combined allowance (5). The first failure is admitted, the second blocks.
        $limiter->record('target', login_outcome::FAILURE);
        $this->assertFalse($limiter->check('target')['blocked']);
        $limiter->record('target', login_outcome::FAILURE);

        $result = $limiter->check('target');
        $this->assertTrue($result['blocked']);
        $this->assertSame('combo', $result['bucket']);
    }

    public function test_legacy_block_mode_still_blocks_the_whole_address(): void {
        $policy = new rate_limit_policy(
            true,
            'file',
            true,
            true,
            99,
            180,
            99,
            900,
            3,
            300,
            1,
            900,
            rate_limit_policy::IP_MODE_BLOCK
        );
        $limiter = $this->limiter($policy, '203.0.113.74');

        $limiter->record('a', login_outcome::FAILURE);
        $limiter->record('b', login_outcome::FAILURE);
        $limiter->record('c', login_outcome::FAILURE);

        $result = $limiter->check('d');
        $this->assertTrue($result['blocked'], 'The legacy block mode must preserve the blanket address block.');
        $this->assertSame('abuseip', $result['bucket']);
    }

    public function test_abuse_hard_limit_blocks_only_when_explicitly_enabled(): void {
        $policy = new rate_limit_policy(
            true,
            'file',
            true,
            true,
            99,
            180,
            99,
            900,
            3,
            300,
            1,
            900,
            rate_limit_policy::IP_MODE_DETECT,
            2,
            6
        );
        $limiter = $this->limiter($policy, '203.0.113.72');

        for ($i = 0; $i < 6; $i++) {
            $limiter->record('acct' . $i, login_outcome::FAILURE);
        }

        $result = $limiter->check('someone');
        $this->assertTrue($result['blocked']);
        $this->assertSame('abuseip', $result['bucket']);
    }

    public function test_ip_mode_off_ignores_the_abuse_bucket(): void {
        $policy = new rate_limit_policy(
            true,
            'file',
            true,
            true,
            99,
            180,
            99,
            900,
            2,
            300,
            1,
            900,
            rate_limit_policy::IP_MODE_OFF
        );
        $limiter = $this->limiter($policy, '203.0.113.73');

        for ($i = 0; $i < 5; $i++) {
            $limiter->record('acct' . $i, login_outcome::FAILURE);
        }

        $result = $limiter->check('someone');
        $this->assertFalse($result['blocked']);
        $this->assertSame(0, $result['abuse_level'], 'The off mode must not accumulate per-IP abuse at all.');
    }

    public function test_runtime_store_failure_transitions_to_the_fallback(): void {
        $policy = new rate_limit_policy(
            true,
            'redis',
            true,
            true,
            3,
            180,
            99,
            900,
            99,
            300,
            1,
            900,
            rate_limit_policy::IP_MODE_OFF
        );
        $primary = new ratelimit_toggle_store(new file_counter_store(make_request_directory()));
        $fallback = new file_counter_store(make_request_directory());
        $limiter = new login_rate_limiter($policy, $primary, '203.0.113.80', $fallback);

        // The backend connected successfully, but every operation now fails at runtime.
        $primary->broken = true;

        // No increment may be dropped: each failure is replayed on the fallback.
        $limiter->record('student1', login_outcome::FAILURE);
        $this->assertFalse($limiter->check('student1')['blocked']);
        $limiter->record('student1', login_outcome::FAILURE);
        $this->assertFalse($limiter->check('student1')['blocked']);
        $limiter->record('student1', login_outcome::FAILURE);

        $result = $limiter->check('student1');
        $this->assertTrue($result['blocked'], 'All three increments must have landed on the fallback.');
        $this->assertSame('combo', $result['bucket']);
        $this->assertSame('simulated_runtime_failure', $primary->last_failure());
    }

    public function test_fail_open_degraded_mode_allows_sign_in_but_reports_it(): void {
        $policy = new rate_limit_policy(
            true,
            'redis',
            false,
            true,
            3,
            180,
            10,
            900,
            300,
            300,
            1,
            900,
            rate_limit_policy::IP_MODE_DETECT,
            2,
            0,
            rate_limit_policy::DEGRADED_FAILOPEN
        );
        $primary = new ratelimit_toggle_store(new file_counter_store(make_request_directory()));
        $primary->broken = true;
        $limiter = new login_rate_limiter($policy, $primary, '203.0.113.90');

        $result = $limiter->check('student1');
        $this->assertTrue($result['degraded']);
        $this->assertFalse($result['blocked'], 'Fail-open must not create a system-wide sign-in outage.');
        $this->assertSame(0, $result['retry_after']);
    }

    public function test_fail_closed_degraded_mode_denies_sign_in_with_a_bounded_retry(): void {
        $policy = new rate_limit_policy(
            true,
            'redis',
            false,
            true,
            3,
            180,
            10,
            900,
            300,
            300,
            15,
            900,
            rate_limit_policy::IP_MODE_DETECT,
            2,
            0,
            rate_limit_policy::DEGRADED_FAILCLOSED
        );
        $primary = new ratelimit_toggle_store(new file_counter_store(make_request_directory()));
        $primary->broken = true;
        $limiter = new login_rate_limiter($policy, $primary, '203.0.113.91');

        $result = $limiter->check('student1');
        $this->assertTrue($result['degraded']);
        $this->assertTrue($result['blocked']);
        $this->assertSame('degraded', $result['bucket']);
        $this->assertSame(15, $result['retry_after'], 'Fail-closed must advertise the clamped minimum retry.');
    }

    public function test_failure_of_both_backends_enters_degraded_mode(): void {
        $policy = new rate_limit_policy(
            true,
            'redis',
            true,
            true,
            3,
            180,
            10,
            900,
            300,
            300,
            15,
            900,
            rate_limit_policy::IP_MODE_DETECT,
            2,
            0,
            rate_limit_policy::DEGRADED_FAILCLOSED
        );
        $primary = new ratelimit_toggle_store(new file_counter_store(make_request_directory()));
        $primary->broken = true;
        $fallback = new ratelimit_toggle_store(new file_counter_store(make_request_directory()));
        $fallback->broken = true;
        $limiter = new login_rate_limiter($policy, $primary, '203.0.113.92', $fallback);

        $result = $limiter->check('student1');
        $this->assertTrue($result['degraded']);
        $this->assertTrue($result['blocked']);
        $this->assertSame('degraded', $result['bucket']);
    }
}

/**
 * A counter store whose operations fail at runtime, to exercise the fallback transition.
 *
 * It wraps a real store so that, while healthy, it behaves exactly like a working
 * backend. Setting {@see ratelimit_toggle_store::$broken} simulates the failure modes
 * that a live Redis can exhibit after a successful connection: out-of-memory under the
 * noeviction policy, a revoked ACL permission, a read timeout or a dropped connection.
 *
 * @package    local_ulms_auth
 * @copyright  2026 Bells University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ratelimit_toggle_store implements counter_store {
    /** @var bool When true, every operation reports a runtime failure. */
    public bool $broken = false;

    /** @var counter_store */
    private counter_store $inner;

    /**
     * Constructor.
     *
     * @param counter_store $inner Backing store used while healthy.
     */
    public function __construct(counter_store $inner) {
        $this->inner = $inner;
    }

    /**
     * Increments a counter, or reports a runtime failure when broken.
     *
     * @param string $key
     * @param int $windowseconds
     * @return array{count:int,ttl:int}|null
     */
    public function hit(string $key, int $windowseconds): ?array {
        return $this->broken ? null : $this->inner->hit($key, $windowseconds);
    }

    /**
     * Reads a counter, or reports a runtime failure when broken.
     *
     * @param string $key
     * @param int $windowseconds
     * @return array{count:int,ttl:int}|null
     */
    public function inspect(string $key, int $windowseconds): ?array {
        return $this->broken ? null : $this->inner->inspect($key, $windowseconds);
    }

    /**
     * Clears a counter, or reports a runtime failure when broken.
     *
     * @param string $key
     * @return bool
     */
    public function clear(string $key): bool {
        return $this->broken ? false : $this->inner->clear($key);
    }

    /**
     * The simulated backend is always considered connected.
     *
     * @return bool
     */
    public function is_available(): bool {
        return true;
    }

    /**
     * Backend identifier.
     *
     * @return string
     */
    public function name(): string {
        return 'toggle';
    }

    /**
     * The simulated runtime failure reason.
     *
     * @return string|null
     */
    public function last_failure(): ?string {
        return $this->broken ? 'simulated_runtime_failure' : null;
    }
}
