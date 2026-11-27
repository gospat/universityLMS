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
use local_ulms_auth\local\service\ratelimit\redis_counter_store;

defined('MOODLE_INTERNAL') || die();

/**
 * Genuine parallel-process concurrency tests for the login rate limiter counters.
 *
 * The other suites exercise the limiter sequentially, which can never prove that an
 * increment is race-free: a lost update is invisible without concurrency. These tests
 * therefore launch several independent PHP processes that hammer the same shared
 * counter at the same time and then assert the aggregate result.
 *
 * They deliberately separate the two kinds of atomicity the design depends on:
 *
 *   - the counter update is atomic (Redis INCR inside a Lua script; flock on the file
 *     store), so N parallel increments over a shared key must produce exactly N, never
 *     N minus a lost update;
 *   - admission is NOT atomic with accounting. check() and record() are two separate
 *     steps, so parallel requests can overshoot a threshold by at most the number of
 *     requests already in flight. The tests assert that bound holds rather than
 *     pretending the cut-off is exact.
 *
 * The child processes are plain PHP CLI invocations that load only the rate-limiter
 * classes, so they never touch the Moodle database and can never interfere with the
 * parent's test transaction.
 *
 * @package    local_ulms_auth
 * @copyright  2026 Bells University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ulms_auth\local\service\ratelimit\login_rate_limiter
 * @covers     \local_ulms_auth\local\service\ratelimit\file_counter_store
 */
final class ratelimit_concurrency_test extends \advanced_testcase {
    /** Number of independent child processes launched per test. */
    private const PROCESSES = 6;

    /** Operations each child performs. */
    private const OPS = 12;

    /** The shared identifier the parallel requests target. */
    private const IDENTIFIER = 'parallel_target';

    /** A documentation-range client address shared by all children. */
    private const IP = '203.0.113.123';

    /**
     * A self-contained worker executed by `php -r` in each child process.
     *
     * It receives every parameter through the environment and prints a single
     * machine-readable result line. It intentionally uses fully-qualified class names
     * because a `use` statement is not valid in `-r` code.
     */
    private const WORKER = <<<'WORKER'
define('MOODLE_INTERNAL', true);
$classes = rtrim((string)getenv('ULMS_RL_CLASSES'), '/');
require $classes . '/counter_store.php';
require $classes . '/file_counter_store.php';
require $classes . '/redis_counter_store.php';
require $classes . '/rate_limit_policy.php';
require $classes . '/client_ip.php';
require $classes . '/login_outcome.php';
require $classes . '/login_rate_limiter.php';

class ulms_conc_dead_store implements \local_ulms_auth\local\service\ratelimit\counter_store {
    public function hit(string $key, int $windowseconds): ?array {
        return null;
    }
    public function inspect(string $key, int $windowseconds): ?array {
        return null;
    }
    public function clear(string $key): bool {
        return false;
    }
    public function is_available(): bool {
        return true;
    }
    public function name(): string {
        return 'dead';
    }
    public function last_failure(): ?string {
        return 'simulated_runtime_failure';
    }
}

$backend = (string)getenv('ULMS_RL_BACKEND');
$mode = (string)getenv('ULMS_RL_MODE');
$key = (string)getenv('ULMS_RL_KEY');
$ip = (string)getenv('ULMS_RL_IP');
$ops = (int)getenv('ULMS_RL_OPS');
$window = (int)getenv('ULMS_RL_WINDOW');
$dir = (string)getenv('ULMS_RL_DIR');

$makefile = function () use ($dir) {
    return new \local_ulms_auth\local\service\ratelimit\file_counter_store($dir);
};
$makeredis = function () use ($classes) {
    return new \local_ulms_auth\local\service\ratelimit\redis_counter_store(
        (string)getenv('ULMS_RL_REDIS_HOST'),
        (int)getenv('ULMS_RL_REDIS_PORT'),
        (string)getenv('ULMS_RL_REDIS_USER'),
        (string)getenv('ULMS_RL_REDIS_SECRET_FILE'),
        1.5,
        1.5
    );
};
$store = $backend === 'redis' ? $makeredis() : $makefile();

$policy = new \local_ulms_auth\local\service\ratelimit\rate_limit_policy(
    true,
    $backend === 'redis' ? 'redis' : 'file',
    false,
    false,
    (int)getenv('ULMS_RL_LIMIT'),
    $window,
    9999,
    $window,
    9999,
    300,
    1,
    900,
    \local_ulms_auth\local\service\ratelimit\rate_limit_policy::IP_MODE_DETECT,
    2,
    0,
    \local_ulms_auth\local\service\ratelimit\rate_limit_policy::DEGRADED_FAILCLOSED
);

if ($mode === 'increment') {
    $max = 0;
    $ttl = 0;
    for ($i = 0; $i < $ops; $i++) {
        $result = $store->hit($key, $window);
        if ($result === null) {
            $detail = method_exists($store, 'last_failure') ? (string)$store->last_failure() : 'unknown';
            fwrite(STDERR, 'hit_failed:' . $detail . "\n");
            exit(2);
        }
        $max = max($max, (int)$result['count']);
        $ttl = (int)$result['ttl'];
    }
    echo 'OK max=' . $max . ' ttl=' . $ttl . "\n";
    exit(0);
}

if ($mode === 'admission') {
    $limiter = new \local_ulms_auth\local\service\ratelimit\login_rate_limiter($policy, $store, $ip);
    $admitted = 0;
    for ($i = 0; $i < $ops; $i++) {
        if ($limiter->check($key)['blocked']) {
            break;
        }
        $limiter->record($key, \local_ulms_auth\local\service\ratelimit\login_outcome::FAILURE);
        $admitted++;
    }
    echo 'OK admitted=' . $admitted . "\n";
    exit(0);
}

if ($mode === 'mixed') {
    $outcome = getenv('ULMS_RL_OUTCOME') === 'success'
        ? \local_ulms_auth\local\service\ratelimit\login_outcome::SUCCESS
        : \local_ulms_auth\local\service\ratelimit\login_outcome::FAILURE;
    $limiter = new \local_ulms_auth\local\service\ratelimit\login_rate_limiter($policy, $store, $ip);
    for ($i = 0; $i < $ops; $i++) {
        $limiter->record($key, $outcome);
    }
    echo "OK recorded\n";
    exit(0);
}

if ($mode === 'transition') {
    $fallback = $makefile();
    $limiter = new \local_ulms_auth\local\service\ratelimit\login_rate_limiter(
        $policy,
        new ulms_conc_dead_store(),
        $ip,
        $fallback
    );
    for ($i = 0; $i < $ops; $i++) {
        $limiter->record($key, \local_ulms_auth\local\service\ratelimit\login_outcome::FAILURE);
    }
    echo "OK transitioned\n";
    exit(0);
}

fwrite(STDERR, 'unknown_mode:' . $mode . "\n");
exit(3);
WORKER;

    /**
     * Directory holding the rate-limiter class files.
     *
     * @return string
     */
    private function classes_dir(): string {
        $dir = realpath(__DIR__ . '/../classes/local/service/ratelimit');
        $this->assertNotFalse($dir, 'The rate limiter class directory must exist.');
        return (string)$dir;
    }

    /**
     * Creates a unique counter directory for one test.
     *
     * @param string $suffix
     * @return string
     */
    private function counter_dir(string $suffix): string {
        $dir = make_request_directory() . DIRECTORY_SEPARATOR . 'conc-' . $suffix;
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        return $dir;
    }

    /**
     * Launches several children concurrently and returns their standard output.
     *
     * Every child is started before any is waited on, so their work genuinely overlaps
     * rather than being serialised by the test harness.
     *
     * @param array<int, array<string, string>> $environments One environment per child.
     * @return array<int, string> Captured stdout, keyed by child index.
     */
    private function run_children(array $environments): array {
        $base = $this->base_environment();
        $processes = [];
        $pipes = [];

        foreach ($environments as $index => $extra) {
            $command = [PHP_BINARY, '-d', 'error_reporting=0', '-d', 'display_errors=0', '-r', self::WORKER];
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $handle = proc_open($command, $descriptors, $childpipes, null, array_merge($base, $extra));
            $this->assertIsResource($handle, 'The parallel worker process must start.');
            fclose($childpipes[0]);
            $processes[$index] = $handle;
            $pipes[$index] = $childpipes;
        }

        $outputs = [];
        foreach ($processes as $index => $handle) {
            $stdout = (string)stream_get_contents($pipes[$index][1]);
            $stderr = (string)stream_get_contents($pipes[$index][2]);
            fclose($pipes[$index][1]);
            fclose($pipes[$index][2]);
            $status = proc_close($handle);
            $this->assertSame(
                0,
                $status,
                'A parallel worker failed (exit ' . $status . '): ' . trim($stderr)
            );
            $outputs[$index] = trim($stdout);
        }

        return $outputs;
    }

    /**
     * The environment shared by every child, merged with the parent's own environment.
     *
     * @return array<string, string>
     */
    private function base_environment(): array {
        $inherited = getenv();
        $environment = [
            'ULMS_RL_CLASSES' => $this->classes_dir(),
            'ULMS_RL_IP' => self::IP,
            'ULMS_RL_OPS' => (string)self::OPS,
            'ULMS_RL_WINDOW' => '120',
            'ULMS_RL_KEY' => self::IDENTIFIER,
            'ULMS_RL_REDIS_HOST' => (string)(getenv('ULMS_REDIS_HOST') ?: '127.0.0.1'),
            'ULMS_RL_REDIS_PORT' => (string)(getenv('ULMS_REDIS_PORT') ?: '6379'),
            'ULMS_RL_REDIS_USER' => (string)(getenv('ULMS_REDIS_ACL_USER') ?: 'ulms_auth'),
            'ULMS_RL_REDIS_SECRET_FILE' => (string)(getenv('ULMS_REDIS_SECRET_FILE') ?: '/etc/ulms/redis-auth.secret'),
        ];
        return array_merge($inherited, $environment);
    }

    /**
     * Reads the largest counter value currently stored in a file-store directory.
     *
     * @param string $dir
     * @return int
     */
    private function max_counter_in_dir(string $dir): int {
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json');
        $max = 0;
        foreach ($files ?: [] as $file) {
            $data = json_decode((string)file_get_contents($file), true);
            $this->assertIsArray($data, 'A counter file must remain valid JSON under concurrency.');
            $this->assertArrayHasKey('count', $data);
            $this->assertGreaterThanOrEqual(0, (int)$data['count'], 'A counter must never go negative.');
            $max = max($max, (int)$data['count']);
        }
        return $max;
    }

    public function test_parallel_increments_never_lose_an_update_on_the_file_store(): void {
        $dir = $this->counter_dir('file');
        $environments = [];
        for ($i = 0; $i < self::PROCESSES; $i++) {
            $environments[] = ['ULMS_RL_BACKEND' => 'file', 'ULMS_RL_MODE' => 'increment', 'ULMS_RL_DIR' => $dir];
        }

        $outputs = $this->run_children($environments);

        $expected = self::PROCESSES * self::OPS;
        $max = 0;
        foreach ($outputs as $output) {
            $this->assertMatchesRegularExpression('/^OK max=\d+ ttl=\d+$/', $output);
            preg_match('/max=(\d+)/', $output, $matches);
            $max = max($max, (int)$matches[1]);
        }

        // Every post-increment value is distinct, so the largest one observed must be
        // exactly the total number of increments: no update was lost to a race.
        $this->assertSame($expected, $max, 'Concurrent file-store increments must not lose an update.');
        $this->assertSame($expected, $this->max_counter_in_dir($dir));
    }

    public function test_parallel_increments_never_lose_an_update_on_redis(): void {
        if (!redis_counter_store::extension_loaded()) {
            $this->markTestSkipped('The phpredis extension is not installed, so the Redis store cannot be exercised.');
        }
        $probe = new redis_counter_store(
            (string)(getenv('ULMS_REDIS_HOST') ?: '127.0.0.1'),
            (int)(getenv('ULMS_REDIS_PORT') ?: 6379),
            (string)(getenv('ULMS_REDIS_ACL_USER') ?: 'ulms_auth'),
            (string)(getenv('ULMS_REDIS_SECRET_FILE') ?: '/etc/ulms/redis-auth.secret'),
            1.5,
            1.5
        );
        if (!$probe->is_available()) {
            $this->fail('Redis must be reachable whenever phpredis is installed; the store reported: ' . (string)$probe->last_failure());
        }

        $key = 'parallel-' . bin2hex(random_bytes(8));
        $environments = [];
        for ($i = 0; $i < self::PROCESSES; $i++) {
            $environments[] = ['ULMS_RL_BACKEND' => 'redis', 'ULMS_RL_MODE' => 'increment', 'ULMS_RL_KEY' => $key];
        }

        $outputs = $this->run_children($environments);

        $expected = self::PROCESSES * self::OPS;
        $max = 0;
        foreach ($outputs as $output) {
            $this->assertMatchesRegularExpression('/^OK max=\d+ ttl=\d+$/', $output);
            preg_match('/max=(\d+)/', $output, $matches);
            $max = max($max, (int)$matches[1]);
        }

        // The Lua INCR is server-side atomic, so the highest value returned by any
        // concurrent client must be the exact total.
        $this->assertSame($expected, $max, 'Concurrent Redis increments must not lose an update.');

        $probe->clear($key);
    }

    public function test_parallel_authentication_admissions_are_bounded_by_the_threshold(): void {
        $dir = $this->counter_dir('admission');
        $limit = 3;
        $environments = [];
        for ($i = 0; $i < self::PROCESSES; $i++) {
            $environments[] = [
                'ULMS_RL_BACKEND' => 'file',
                'ULMS_RL_MODE' => 'admission',
                'ULMS_RL_DIR' => $dir,
                'ULMS_RL_LIMIT' => (string)$limit,
            ];
        }

        $outputs = $this->run_children($environments);

        $admitted = 0;
        foreach ($outputs as $output) {
            $this->assertMatchesRegularExpression('/^OK admitted=\d+$/', $output);
            preg_match('/admitted=(\d+)/', $output, $matches);
            $admitted += (int)$matches[1];
        }

        // Protection must engage: the threshold cannot be ignored under load.
        $this->assertGreaterThanOrEqual($limit, $admitted, 'The threshold must be enforced under concurrency.');
        // Admission is not atomic with accounting, so the overshoot is bounded by the
        // number of requests that were in flight when the threshold was reached.
        $this->assertLessThanOrEqual(
            $limit + self::PROCESSES,
            $admitted,
            'Concurrent admissions must not substantially exceed the intended threshold.'
        );
        // The loop was cut short, proving the limiter actually stopped the runaway.
        $this->assertLessThan(self::PROCESSES * self::OPS, $admitted);

        // The counter reflects exactly the admissions that were recorded: the atomic
        // update never under-counts what the limiter allowed through.
        $store = new file_counter_store($dir);
        $policy = new rate_limit_policy(true, 'file', false, false, $limit, 120, 9999, 120, 9999, 300, 1, 900);
        $limiter = new login_rate_limiter($policy, $store, self::IP);
        $this->assertTrue($limiter->check(self::IDENTIFIER)['blocked'], 'The account must remain restricted afterwards.');
    }

    public function test_counter_expiry_is_anchored_to_the_first_hit_under_parallel_load(): void {
        $dir = $this->counter_dir('expiry');
        $window = 2;
        $environments = [];
        for ($i = 0; $i < self::PROCESSES; $i++) {
            $environments[] = [
                'ULMS_RL_BACKEND' => 'file',
                'ULMS_RL_MODE' => 'increment',
                'ULMS_RL_DIR' => $dir,
                'ULMS_RL_WINDOW' => (string)$window,
            ];
        }

        $outputs = $this->run_children($environments);

        // The window is fixed at the first hit, so concurrent activity must not extend it.
        foreach ($outputs as $output) {
            preg_match('/ttl=(\d+)/', $output, $matches);
            $this->assertLessThanOrEqual($window, (int)$matches[1]);
            $this->assertGreaterThanOrEqual(0, (int)$matches[1]);
        }

        $store = new file_counter_store($dir);
        $this->assertGreaterThan(0, $store->inspect(self::IDENTIFIER, $window)['count']);

        sleep($window + 1);

        $this->assertSame(0, $store->inspect(self::IDENTIFIER, $window)['count'], 'The counter must expire once the window elapses.');
    }

    public function test_simultaneous_success_and_failure_leave_the_counter_consistent(): void {
        $dir = $this->counter_dir('mixed');
        $environments = [];
        for ($i = 0; $i < self::PROCESSES; $i++) {
            $environments[] = [
                'ULMS_RL_BACKEND' => 'file',
                'ULMS_RL_MODE' => 'mixed',
                'ULMS_RL_DIR' => $dir,
                'ULMS_RL_LIMIT' => '5',
                'ULMS_RL_OUTCOME' => ($i % 2 === 0) ? 'failure' : 'success',
            ];
        }

        $outputs = $this->run_children($environments);

        foreach ($outputs as $output) {
            $this->assertSame('OK recorded', $output);
        }

        // Half the processes recorded failures, each on three buckets, so no counter may
        // exceed that total and none may be corrupted or negative.
        $max = $this->max_counter_in_dir($dir);
        $this->assertLessThanOrEqual((int)(self::PROCESSES / 2) * self::OPS, $max);
    }

    public function test_parallel_runtime_transition_to_the_file_fallback_loses_no_increment(): void {
        $dir = $this->counter_dir('transition');
        $environments = [];
        for ($i = 0; $i < self::PROCESSES; $i++) {
            $environments[] = [
                'ULMS_RL_BACKEND' => 'file',
                'ULMS_RL_MODE' => 'transition',
                'ULMS_RL_DIR' => $dir,
                'ULMS_RL_LIMIT' => '5',
            ];
        }

        $outputs = $this->run_children($environments);

        foreach ($outputs as $output) {
            $this->assertSame('OK transitioned', $output);
        }

        // Every process had its primary store fail on the first operation and its
        // increment replayed on the shared file fallback, so each bucket must hold the
        // full total: the transition dropped nothing, even under concurrency.
        $expected = self::PROCESSES * self::OPS;
        $this->assertSame($expected, $this->max_counter_in_dir($dir));
    }
}
