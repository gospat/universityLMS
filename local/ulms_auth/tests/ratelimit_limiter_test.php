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

        $buckets = rate_limit_policy::from_config()->buckets();

        $this->assertSame(['combo', 'account', 'abuseip'], array_column($buckets, 'name'));
        $this->assertSame(5, $buckets[0]['limit']);
        $this->assertSame(180, $buckets[0]['window']);
        $this->assertSame(10, $buckets[1]['limit']);
        $this->assertSame(900, $buckets[1]['window']);
        $this->assertSame(300, $buckets[2]['limit']);
        $this->assertSame(300, $buckets[2]['window']);
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
        $policy = new rate_limit_policy(true, 'file', true, true, 99, 180, 99, 900, 3, 300, 1, 900);
        $limiter = $this->limiter($policy, '203.0.113.50');

        $limiter->record('a', login_outcome::FAILURE);
        $limiter->record('b', login_outcome::FAILURE);
        $limiter->record('c', login_outcome::FAILURE);

        $limiter->record('a', login_outcome::SUCCESS);

        $result = $limiter->check('d');
        $this->assertTrue($result['blocked']);
        $this->assertSame('abuseip', $result['bucket']);
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
}
