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

use local_ulms_auth\local\service\ratelimit\client_ip;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for trusted-proxy client IP resolution.
 *
 * @package    local_ulms_auth
 * @copyright  2026 Bells University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ulms_auth\local\service\ratelimit\client_ip
 */
final class ratelimit_client_ip_test extends \advanced_testcase {
    public function test_untrusted_peer_ignores_forwarded_headers(): void {
        $this->resetAfterTest(true);

        $ip = client_ip::resolve([
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_CF_CONNECTING_IP' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '10.0.0.2, 10.0.0.3',
        ]);

        $this->assertSame('203.0.113.7', $ip);
    }

    public function test_trusted_peer_honours_cloudflare_header(): void {
        $this->resetAfterTest(true);

        $ip = client_ip::resolve([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_CF_CONNECTING_IP' => '198.51.100.24',
        ]);

        $this->assertSame('198.51.100.24', $ip);
    }

    public function test_trusted_peer_uses_first_forwarded_hop(): void {
        $this->resetAfterTest(true);

        $ip = client_ip::resolve([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 70.41.3.18',
        ]);

        $this->assertSame('203.0.113.9', $ip);
    }

    public function test_trusted_peer_falls_back_when_headers_malformed(): void {
        $this->resetAfterTest(true);

        $ip = client_ip::resolve([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_CF_CONNECTING_IP' => 'not-an-ip',
        ]);

        $this->assertSame('127.0.0.1', $ip);
    }

    public function test_ipv6_is_canonicalised(): void {
        $this->resetAfterTest(true);

        $ip = client_ip::resolve([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_CF_CONNECTING_IP' => '2001:0db8:0000:0000:0000:0000:0000:0001',
        ]);

        $this->assertSame('2001:db8::1', $ip);
    }

    public function test_configured_trusted_ranges_are_respected(): void {
        $this->resetAfterTest(true);
        set_config('trustedproxies', '10.0.0.0/8', 'local_ulms_auth');

        $trusted = client_ip::resolve([
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.5',
        ]);
        $untrusted = client_ip::resolve([
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.5',
        ]);

        $this->assertSame('198.51.100.5', $trusted);
        $this->assertSame('192.0.2.10', $untrusted);
    }

    public function test_in_ranges_matches_ipv4_and_ipv6_cidr(): void {
        $this->assertTrue(client_ip::in_ranges('172.68.10.5', ['172.64.0.0/13']));
        $this->assertFalse(client_ip::in_ranges('172.80.10.5', ['172.64.0.0/13']));
        $this->assertTrue(client_ip::in_ranges('2606:4700::1111', ['2606:4700::/32']));
        $this->assertFalse(client_ip::in_ranges('2606:4701::1111', ['2606:4700::/32']));
        $this->assertFalse(client_ip::in_ranges('not-an-ip', ['172.64.0.0/13']));
    }

    public function test_normalise_handles_ports_and_zones(): void {
        $this->assertSame('203.0.113.4', client_ip::normalise('203.0.113.4:443'));
        $this->assertSame('2001:db8::1', client_ip::normalise('[2001:db8::1]:8443'));
        $this->assertSame('fe80::1', client_ip::normalise('fe80::1%eth0'));
        $this->assertNull(client_ip::normalise('garbage'));
        $this->assertNull(client_ip::normalise(''));
    }
}
