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

use local_ulms_auth\local\service\ratelimit\login_outcome;
use local_ulms_auth\local\service\ratelimit\login_rate_limiter;
use local_ulms_auth\local\service\ratelimit\rate_limit_policy;
use local_ulms_auth\local\service\ratelimit\redis_counter_store;

defined('MOODLE_INTERNAL') || die();

/**
 * Integration tests for the Redis counter store against a real Redis server.
 *
 * These are skipped only when the phpredis extension is missing, which is the one
 * dependency that cannot be supplied from within PHP. When the extension is present the
 * store MUST be reachable, so a Redis container that was never provisioned fails the
 * suite instead of being silently skipped — that is the difference between validating
 * the backend and merely pretending to.
 *
 * Connection details come from the environment so the tests never depend on site
 * configuration and never carry a secret: the password is read from a file whose path
 * is supplied by the CI runner.
 *
 * @package    local_ulms_auth
 * @copyright  2026 Bells University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ulms_auth\local\service\ratelimit\redis_counter_store
 */
final class ratelimit_redis_store_test extends \advanced_testcase {
    /** A documentation-range address, used for the end-to-end policy assertions. */
    private const TEST_IP = '203.0.113.200';

    /**
     * Builds a store from the environment, skipping only when phpredis is absent.
     *
     * @return redis_counter_store
     */
    private function store(): redis_counter_store {
        if (!redis_counter_store::extension_loaded()) {
            $this->markTestSkipped('The phpredis extension is not installed, so the Redis store cannot be exercised.');
        }
        return new redis_counter_store(
            $this->redis_host(),
            $this->redis_port(),
            $this->redis_user(),
            $this->redis_secret_file(),
            1.5,
            1.5
        );
    }

    /**
     * Builds a store and asserts the server is genuinely reachable.
     *
     * @return redis_counter_store
     */
    private function available_store(): redis_counter_store {
        $store = $this->store();
        if (!$store->is_available()) {
            $this->fail(
                'Redis must be reachable whenever phpredis is installed; the store reported: '
                . (string)$store->last_failure()
            );
        }
        return $store;
    }

    /**
     * Opens a raw phpredis connection authenticated as the restricted ACL user.
     *
     * @return \Redis
     */
    private function raw_connection(): \Redis {
        $password = $this->secret();
        $redis = new \Redis();
        try {
            if ($redis->connect($this->redis_host(), $this->redis_port(), 1.5) !== true) {
                $this->fail('Unable to open a raw Redis connection for ACL verification.');
            }
            $redis->auth([$this->redis_user(), $password]);
        } catch (\Throwable $exception) {
            $this->fail('Unable to authenticate the raw Redis connection: ' . $exception->getMessage());
        }
        return $redis;
    }

    /**
     * Reads the ACL password from the configured secret file.
     *
     * @return string
     */
    private function secret(): string {
        $file = $this->redis_secret_file();
        $raw = $file !== '' ? @file_get_contents($file) : false;
        if ($raw === false || trim($raw) === '') {
            $this->fail('The Redis credential file is not readable; the CI runner must provision it.');
        }
        return trim($raw);
    }

    /**
     * Returns a unique logical key so tests never collide with each other or with a
     * previous run of the same suite.
     *
     * @param string $prefix
     * @return string
     */
    private function unique(string $prefix): string {
        return $prefix . '-' . bin2hex(random_bytes(8));
    }

    /**
     * @return string
     */
    private function redis_host(): string {
        return (string)(getenv('ULMS_REDIS_HOST') ?: '127.0.0.1');
    }

    /**
     * @return int
     */
    private function redis_port(): int {
        return (int)(getenv('ULMS_REDIS_PORT') ?: 6379);
    }

    /**
     * @return string
     */
    private function redis_user(): string {
        return (string)(getenv('ULMS_REDIS_ACL_USER') ?: 'ulms_auth');
    }

    /**
     * @return string
     */
    private function redis_secret_file(): string {
        return (string)(getenv('ULMS_REDIS_SECRET_FILE') ?: '/etc/ulms/redis-auth.secret');
    }

    public function test_counters_increment_atomically_and_expire(): void {
        $store = $this->available_store();
        $key = $this->unique('incr');

        $first = $store->hit($key, 60);
        $second = $store->hit($key, 60);
        $third = $store->hit($key, 60);

        $this->assertSame(1, $first['count']);
        $this->assertSame(2, $second['count']);
        $this->assertSame(3, $third['count']);
        $this->assertGreaterThan(0, $third['ttl']);
        $this->assertLessThanOrEqual(60, $third['ttl']);

        $this->assertTrue($store->clear($key));
        $this->assertSame(0, $store->inspect($key, 60)['count']);
    }

    public function test_inspect_does_not_increment(): void {
        $store = $this->available_store();
        $key = $this->unique('inspect');

        $store->hit($key, 120);

        $this->assertSame(1, $store->inspect($key, 120)['count']);
        $this->assertSame(1, $store->inspect($key, 120)['count']);
        $this->assertGreaterThan(0, $store->inspect($key, 120)['ttl']);

        $store->clear($key);
    }

    public function test_clear_removes_counter(): void {
        $store = $this->available_store();
        $key = $this->unique('clear');

        $store->hit($key, 60);
        $this->assertSame(1, $store->inspect($key, 60)['count']);

        $this->assertTrue($store->clear($key));
        $this->assertSame(0, $store->inspect($key, 60)['count']);
    }

    public function test_keys_stay_inside_the_restricted_namespace(): void {
        $store = $this->available_store();
        $key = $this->unique('namespace');
        $store->hit($key, 60);

        $raw = $this->raw_connection();

        // The application key is stored under the mandatory namespace. If the store had
        // written outside it the restricted ACL would have denied this read outright.
        $this->assertSame('1', (string)$raw->get(redis_counter_store::KEY_PREFIX . $key));

        // The ACL user cannot write outside the namespace.
        $outsidenamespace = false;
        try {
            $raw->set('ulms:outside-namespace', '1');
        } catch (\Throwable $exception) {
            $outsidenamespace = true;
        }
        $this->assertTrue($outsidenamespace, 'The ACL user must not be able to write outside the ulms:auth: namespace.');

        // The ACL user cannot run administrative or introspection commands.
        $adminblocked = false;
        try {
            $raw->info();
        } catch (\Throwable $exception) {
            $adminblocked = true;
        }
        $this->assertTrue($adminblocked, 'The ACL user must not be allowed to run INFO.');

        $raw->del(redis_counter_store::KEY_PREFIX . $key);
    }

    public function test_limiter_blocks_after_the_threshold_using_redis(): void {
        $store = $this->available_store();
        $policy = new rate_limit_policy(true, 'redis', true, true, 3, 180, 10, 900, 300, 300, 1, 900);
        $limiter = new login_rate_limiter($policy, $store, self::TEST_IP);
        $identifier = $this->unique('target');

        // The combined bucket is reserved atomically at admission, so exactly three
        // admitted attempts are allowed and the fourth is refused at the gate.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->assertFalse($limiter->check($identifier)['blocked']);
            $limiter->record($identifier, login_outcome::FAILURE);
        }

        $result = $limiter->check($identifier);
        $this->assertTrue($result['blocked']);
        $this->assertSame('combo', $result['bucket']);

        $limiter->record($identifier, login_outcome::SUCCESS);
        $this->assertFalse($limiter->check($identifier)['blocked']);
    }

    public function test_limiter_success_clears_the_account_counter_in_redis(): void {
        $store = $this->available_store();
        $policy = new rate_limit_policy(true, 'redis', true, true, 99, 180, 2, 900, 300, 300, 1, 900);
        $limiter = new login_rate_limiter($policy, $store, self::TEST_IP);
        $identifier = $this->unique('reset');

        $limiter->record($identifier, login_outcome::FAILURE);
        $limiter->record($identifier, login_outcome::FAILURE);
        $this->assertTrue($limiter->check($identifier)['blocked']);
        $this->assertSame('account', $limiter->check($identifier)['bucket']);

        $limiter->record($identifier, login_outcome::SUCCESS);
        $this->assertFalse($limiter->check($identifier)['blocked']);
    }

    public function test_release_refunds_a_reservation_without_touching_the_ledger(): void {
        $store = $this->available_store();
        $reservation = $this->unique('reserve');
        $ledger = $this->unique('ledger');

        $store->hit($reservation, 120);
        $store->hit($reservation, 120);
        $store->hit($ledger, 120);

        $this->assertTrue($store->release($reservation, 120));
        $this->assertSame(1, $store->inspect($reservation, 120)['count']);
        // The refund must be confined to the reservation: a recorded failure lives in a
        // separate key and can never be consumed by another request's release.
        $this->assertSame(1, $store->inspect($ledger, 120)['count'], 'A release must never touch the failure ledger.');

        // Releasing an already-empty reservation is a successful no-op clamped at zero.
        $this->assertTrue($store->release($reservation, 120));
        $this->assertTrue($store->release($reservation, 120));
        $this->assertSame(0, $store->inspect($reservation, 120)['count']);

        // The window is preserved rather than restarted, so the key still expires.
        $ttl = $store->inspect($reservation, 120)['ttl'];
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(120, $ttl);

        $store->clear($reservation);
        $store->clear($ledger);
    }

    public function test_neutral_outcome_releases_the_account_reservation_in_redis(): void {
        $store = $this->available_store();
        // A single permitted attempt. Each neutral attempt passes the gate and is then
        // refunded, so a neutral outcome can never accumulate against the account.
        $policy = new rate_limit_policy(true, 'redis', true, true, 1, 180, 1, 900, 300, 300, 1, 900);
        $limiter = new login_rate_limiter($policy, $store, self::TEST_IP);
        $identifier = $this->unique('neutral');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->assertFalse(
                $limiter->check($identifier)['blocked'],
                'A neutral attempt must remain admissible: the reservation is refunded.'
            );
            $limiter->record($identifier, login_outcome::NEUTRAL);
        }
    }
}
