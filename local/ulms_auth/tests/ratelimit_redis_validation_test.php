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

use local_ulms_auth\local\service\ratelimit\redis_client;
use local_ulms_auth\local\service\ratelimit\redis_counter_store;

defined('MOODLE_INTERNAL') || die();

/**
 * Reply-validation tests for the Redis counter store.
 *
 * The production phpredis extension reports a failing command in two different ways: it
 * throws a `RedisException` for a command error (an ACL denial, an out-of-memory
 * rejection under the production noeviction policy, a read timeout) and it returns
 * `false` when a command could not be executed at all. Both are reproduced here, for
 * every command the store issues, through an injected {@see redis_client} fake — so the
 * reply validation runs everywhere (it needs neither a live server nor the extension)
 * and a failed read can never be mistaken for a missing key.
 *
 * @package    local_ulms_auth
 * @copyright  2026 Bells University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ulms_auth\local\service\ratelimit\redis_counter_store
 */
final class ratelimit_redis_validation_test extends \advanced_testcase {
    /**
     * Builds a store driven by an injected fake client.
     *
     * A readable credential file is provisioned because the store always resolves the
     * password before it issues any command, even when the client is injected.
     *
     * @param fake_redis_client $client
     * @return redis_counter_store
     */
    private function store_with(fake_redis_client $client): redis_counter_store {
        $secret = make_request_directory() . '/redis-auth.secret';
        file_put_contents($secret, "unit-test-password\n");
        return new redis_counter_store('127.0.0.1', 6379, 'ulms_auth', $secret, 1.5, 1.5, $client);
    }

    /**
     * Builds the exception phpredis would raise, so the tests exercise the real driver
     * error type in CI (where the extension exists) and a generic throwable locally.
     *
     * @param string $message Driver error message.
     * @return \Throwable
     */
    private function driver_exception(string $message): \Throwable {
        if (class_exists(\RedisException::class)) {
            return new \RedisException($message);
        }
        return new \RuntimeException($message);
    }

    public function test_connect_false_return_is_reported_as_a_connection_failure(): void {
        $client = new fake_redis_client();
        $client->connectresult = false;
        $store = $this->store_with($client);

        $this->assertFalse($store->is_available());
        $this->assertSame('connect_failed', $store->last_failure());
        $this->assertSame('connect', $store->last_failure_category());
    }

    public function test_connect_exception_is_caught_and_classified(): void {
        $client = new fake_redis_client();
        $client->connectexception = $this->driver_exception('Connection lost while reading from the socket');
        $store = $this->store_with($client);

        $this->assertFalse($store->is_available());
        $this->assertSame('connect_exception', $store->last_failure());
        $this->assertSame('connect', $store->last_failure_category());
    }

    public function test_auth_exception_with_noperm_is_classified_as_a_permission_failure(): void {
        $client = new fake_redis_client();
        $client->authexception = $this->driver_exception('NOPERM this user has no permissions to run the command');
        $store = $this->store_with($client);

        $this->assertFalse($store->is_available());
        $this->assertSame('connect_exception', $store->last_failure());
        $this->assertSame('permission', $store->last_failure_category());
    }

    public function test_auth_false_return_is_reported_as_an_authentication_failure(): void {
        $client = new fake_redis_client();
        $client->authresult = false;
        $store = $this->store_with($client);

        $this->assertFalse($store->is_available());
        $this->assertSame('auth_failed', $store->last_failure());
        $this->assertSame('auth', $store->last_failure_category());
    }

    public function test_ping_unexpected_reply_triggers_fallback(): void {
        $client = new fake_redis_client();
        $client->pingreply = 'garbage';
        $store = $this->store_with($client);

        $this->assertFalse($store->is_available());
        $this->assertSame('ping_failed', $store->last_failure());
        $this->assertSame('response', $store->last_failure_category());
    }

    public function test_ping_false_return_triggers_fallback(): void {
        $client = new fake_redis_client();
        $client->pingreply = false;
        $store = $this->store_with($client);

        $this->assertFalse($store->is_available());
        $this->assertSame('ping_failed', $store->last_failure());
    }

    public function test_missing_credential_stops_the_connection_before_any_command(): void {
        $client = new fake_redis_client();
        $store = new redis_counter_store('127.0.0.1', 6379, 'ulms_auth', '/nonexistent/redis-auth.secret', 1.5, 1.5, $client);

        $this->assertFalse($store->is_available());
        $this->assertSame('credential_unavailable', $store->last_failure());
        $this->assertSame('config', $store->last_failure_category());
        $this->assertSame([], $client->calls, 'No command may be sent when the credential cannot be read.');
    }

    public function test_hit_returns_count_and_ttl_on_success(): void {
        $client = new fake_redis_client();
        $client->evalreply = [3, 57];

        $this->assertSame(['count' => 3, 'ttl' => 57], $this->store_with($client)->hit('counter', 60));
    }

    public function test_hit_false_return_triggers_fallback(): void {
        $client = new fake_redis_client();
        $client->evalreply = false;
        $store = $this->store_with($client);

        $this->assertNull($store->hit('counter', 60));
        $this->assertSame('unexpected_response', $store->last_failure());
        $this->assertSame('response', $store->last_failure_category());
    }

    public function test_hit_partial_reply_triggers_fallback(): void {
        $client = new fake_redis_client();
        $client->evalreply = [1];
        $store = $this->store_with($client);

        $this->assertNull($store->hit('counter', 60));
        $this->assertSame('partial_update', $store->last_failure());
    }

    public function test_hit_non_numeric_reply_triggers_fallback(): void {
        $client = new fake_redis_client();
        $client->evalreply = ['one', 'two'];
        $store = $this->store_with($client);

        $this->assertNull($store->hit('counter', 60));
        $this->assertSame('partial_update', $store->last_failure());
    }

    public function test_hit_exception_triggers_fallback(): void {
        $client = new fake_redis_client();
        $client->evalexception = $this->driver_exception('read error on connection');
        $store = $this->store_with($client);

        $this->assertNull($store->hit('counter', 60));
        $this->assertSame('eval_exception', $store->last_failure());
        $this->assertSame('connect', $store->last_failure_category());
    }

    public function test_memory_exhaustion_is_classified_as_a_memory_failure(): void {
        $client = new fake_redis_client();
        $client->evalexception = $this->driver_exception('OOM command not allowed when used memory > maxmemory');
        $store = $this->store_with($client);

        $this->assertNull($store->hit('counter', 60));
        $this->assertSame('eval_exception', $store->last_failure());
        $this->assertSame('memory', $store->last_failure_category());
    }

    public function test_inspect_reports_a_genuine_missing_key(): void {
        $client = new fake_redis_client();
        $client->getreply = false;
        $client->ttlreply = -2;

        $this->assertSame(['count' => 0, 'ttl' => 0], $this->store_with($client)->inspect('counter', 60));
    }

    public function test_inspect_failed_get_is_not_treated_as_a_missing_key(): void {
        $client = new fake_redis_client();
        $client->getreply = false;
        $client->ttlreply = 30;
        $store = $this->store_with($client);

        $this->assertNull($store->inspect('counter', 60));
        $this->assertSame('inspect_response', $store->last_failure());
        $this->assertSame('response', $store->last_failure_category());
    }

    public function test_inspect_get_exception_triggers_fallback(): void {
        $client = new fake_redis_client();
        $client->getexception = $this->driver_exception('read error on connection');
        $store = $this->store_with($client);

        $this->assertNull($store->inspect('counter', 60));
        $this->assertSame('inspect_exception', $store->last_failure());
    }

    public function test_inspect_reads_value_and_remaining_ttl(): void {
        $client = new fake_redis_client();
        $client->getreply = '4';
        $client->ttlreply = 12;

        $this->assertSame(['count' => 4, 'ttl' => 12], $this->store_with($client)->inspect('counter', 60));
    }

    public function test_inspect_repairs_a_counter_that_lost_its_expiry(): void {
        $client = new fake_redis_client();
        $client->getreply = '5';
        $client->ttlreply = -1;
        $client->expirereply = true;

        $this->assertSame(['count' => 5, 'ttl' => 120], $this->store_with($client)->inspect('counter', 120));
        $this->assertSame(
            ['ulms:auth:counter', 120],
            $client->call_args('expire'),
            'A counter without an expiry must be re-armed with EXPIRE.'
        );
    }

    public function test_inspect_expiry_repair_false_return_triggers_fallback(): void {
        $client = new fake_redis_client();
        $client->getreply = '5';
        $client->ttlreply = -1;
        $client->expirereply = false;
        $store = $this->store_with($client);

        $this->assertNull($store->inspect('counter', 120));
        $this->assertSame('inspect_repair_failed', $store->last_failure());
        $this->assertSame('response', $store->last_failure_category());
    }

    public function test_inspect_expiry_repair_exception_triggers_fallback(): void {
        $client = new fake_redis_client();
        $client->getreply = '5';
        $client->ttlreply = -1;
        $client->expireexception = $this->driver_exception('read error on connection');
        $store = $this->store_with($client);

        $this->assertNull($store->inspect('counter', 120));
        $this->assertSame('inspect_repair_exception', $store->last_failure());
    }

    public function test_inspect_malformed_ttl_triggers_fallback(): void {
        $client = new fake_redis_client();
        $client->getreply = '3';
        $client->ttlreply = 'not-a-number';
        $store = $this->store_with($client);

        $this->assertNull($store->inspect('counter', 60));
        $this->assertSame('inspect_response', $store->last_failure());
    }

    public function test_inspect_expired_between_reads_is_an_empty_window(): void {
        $client = new fake_redis_client();
        $client->getreply = '2';
        $client->ttlreply = -2;

        $this->assertSame(['count' => 0, 'ttl' => 0], $this->store_with($client)->inspect('counter', 60));
    }

    public function test_clear_validates_the_del_reply(): void {
        $client = new fake_redis_client();
        $client->delreply = 1;

        $this->assertTrue($this->store_with($client)->clear('counter'));
    }

    public function test_clear_treats_an_absent_counter_as_success(): void {
        $client = new fake_redis_client();
        $client->delreply = 0;

        $this->assertTrue($this->store_with($client)->clear('counter'));
    }

    public function test_clear_false_return_is_a_failure(): void {
        $client = new fake_redis_client();
        $client->delreply = false;
        $store = $this->store_with($client);

        $this->assertFalse($store->clear('counter'));
        $this->assertSame('del_response', $store->last_failure());
        $this->assertSame('response', $store->last_failure_category());
    }

    public function test_clear_exception_is_a_failure(): void {
        $client = new fake_redis_client();
        $client->delexception = $this->driver_exception('read error on connection');
        $store = $this->store_with($client);

        $this->assertFalse($store->clear('counter'));
        $this->assertSame('del_exception', $store->last_failure());
    }

    public function test_commands_use_the_mandatory_namespaced_key(): void {
        $client = new fake_redis_client();
        $client->getreply = '1';
        $client->ttlreply = 30;

        $this->store_with($client)->inspect('account:abc', 60);
        $this->assertSame(['ulms:auth:account:abc'], $client->call_args('get'));
    }
}

/**
 * A scriptable {@see redis_client} double for exercising reply validation.
 *
 * Each command can be made to return a chosen value or to throw, which is exactly the
 * two ways phpredis reports failure. Every call is recorded so a test can assert on the
 * arguments the store actually sent.
 *
 * @package    local_ulms_auth
 * @copyright  2026 Bells University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fake_redis_client implements redis_client {
    /** @var bool */
    public bool $connectresult = true;
    /** @var bool */
    public bool $authresult = true;
    /** @var mixed */
    public mixed $pingreply = 'PONG';
    /** @var mixed */
    public mixed $getreply = false;
    /** @var mixed */
    public mixed $ttlreply = -2;
    /** @var mixed */
    public mixed $delreply = 0;
    /** @var mixed */
    public mixed $expirereply = true;
    /** @var mixed */
    public mixed $evalreply = [1, 60];

    /** @var \Throwable|null */
    public ?\Throwable $connectexception = null;
    /** @var \Throwable|null */
    public ?\Throwable $authexception = null;
    /** @var \Throwable|null */
    public ?\Throwable $pingexception = null;
    /** @var \Throwable|null */
    public ?\Throwable $getexception = null;
    /** @var \Throwable|null */
    public ?\Throwable $ttlexception = null;
    /** @var \Throwable|null */
    public ?\Throwable $delexception = null;
    /** @var \Throwable|null */
    public ?\Throwable $expireexception = null;
    /** @var \Throwable|null */
    public ?\Throwable $evalexception = null;

    /** @var array<int, array{0:string,1:array<int, mixed>}> Recorded calls as [command, arguments]. */
    public array $calls = [];

    public function connect(string $host, int $port, float $timeout, float $readtimeout): bool {
        $this->calls[] = ['connect', [$host, $port]];
        if ($this->connectexception !== null) {
            throw $this->connectexception;
        }
        return $this->connectresult;
    }

    public function auth(string $user, string $password): bool {
        $this->calls[] = ['auth', [$user]];
        if ($this->authexception !== null) {
            throw $this->authexception;
        }
        return $this->authresult;
    }

    public function ping(): mixed {
        $this->calls[] = ['ping', []];
        if ($this->pingexception !== null) {
            throw $this->pingexception;
        }
        return $this->pingreply;
    }

    public function get(string $key): mixed {
        $this->calls[] = ['get', [$key]];
        if ($this->getexception !== null) {
            throw $this->getexception;
        }
        return $this->getreply;
    }

    public function ttl(string $key): mixed {
        $this->calls[] = ['ttl', [$key]];
        if ($this->ttlexception !== null) {
            throw $this->ttlexception;
        }
        return $this->ttlreply;
    }

    public function del(string $key): mixed {
        $this->calls[] = ['del', [$key]];
        if ($this->delexception !== null) {
            throw $this->delexception;
        }
        return $this->delreply;
    }

    public function expire(string $key, int $seconds): mixed {
        $this->calls[] = ['expire', [$key, $seconds]];
        if ($this->expireexception !== null) {
            throw $this->expireexception;
        }
        return $this->expirereply;
    }

    public function eval(string $script, array $arguments, int $numkeys): mixed {
        $this->calls[] = ['eval', $arguments];
        if ($this->evalexception !== null) {
            throw $this->evalexception;
        }
        return $this->evalreply;
    }

    /**
     * Returns the argument list recorded for the first call to a command.
     *
     * @param string $command
     * @return array<int, mixed>|null
     */
    public function call_args(string $command): ?array {
        foreach ($this->calls as $call) {
            if ($call[0] === $command) {
                return $call[1];
            }
        }
        return null;
    }
}
