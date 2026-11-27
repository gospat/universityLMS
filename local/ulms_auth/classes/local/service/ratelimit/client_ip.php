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
 * Resolves the originating client IP address safely behind a trusted reverse proxy.
 *
 * Forwarded headers (CF-Connecting-IP, X-Forwarded-For) are ONLY honoured when the
 * immediate peer (REMOTE_ADDR) is itself a configured trusted proxy. A request that
 * reaches the origin directly therefore cannot spoof those headers, and all Cloudflare
 * users are never collapsed into a single Cloudflare edge address.
 *
 * The resolved value is canonicalised with inet_pton()/inet_ntop() so that the same
 * client always produces the same string for IPv4 and IPv6, which keeps the derived
 * Redis/fallback bucket keys stable.
 *
 * @package    local_ulms_auth
 * @copyright  2026 Bells University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class client_ip {
    /**
     * Default trusted proxy ranges used when no setting is configured.
     *
     * The loopback entries cover the local Nginx reverse proxy; the remaining entries
     * are the published Cloudflare edge ranges (IPv4 and IPv6).
     */
    private const DEFAULT_TRUSTED_RANGES = [
        '127.0.0.1/32',
        '::1/128',
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /** Forwarded headers inspected, in priority order, for a trusted peer. */
    private const DEFAULT_TRUSTED_HEADERS = ['cf-connecting-ip', 'x-real-ip', 'x-forwarded-for'];

    /**
     * Resolves the canonical client IP for the supplied server variables.
     *
     * @param array|null $server Server variables (defaults to $_SERVER).
     * @return string Canonical IPv4/IPv6 address, or a sentinel when undeterminable.
     */
    public static function resolve(?array $server = null): string {
        $server = $server ?? $_SERVER;
        $peer = self::normalise((string)($server['REMOTE_ADDR'] ?? ''));
        if ($peer === null) {
            return '0.0.0.0';
        }

        if (!self::in_ranges($peer, self::trusted_ranges())) {
            // Untrusted peer: forwarded headers are attacker-controlled and ignored.
            return $peer;
        }

        foreach (self::trusted_headers() as $header) {
            $raw = self::header_value($server, $header);
            if ($raw === null) {
                continue;
            }
            $resolved = self::normalise(self::first_hop($raw));
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return $peer;
    }

    /**
     * Returns whether an IP literal is inside one of the supplied CIDR ranges.
     *
     * @param string $ip Canonical IP address.
     * @param array<int, string> $ranges CIDR ranges.
     * @return bool
     */
    public static function in_ranges(string $ip, array $ranges): bool {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        foreach ($ranges as $range) {
            if (self::in_cidr($packed, $range)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns whether a packed address is inside a single CIDR range.
     *
     * @param string $packed Binary address from inet_pton().
     * @param string $cidr CIDR range.
     * @return bool
     */
    private static function in_cidr(string $packed, string $cidr): bool {
        $parts = explode('/', trim($cidr), 2);
        $network = @inet_pton($parts[0]);
        if ($network === false || strlen($network) !== strlen($packed)) {
            return false;
        }
        $bits = strlen($packed) * 8;
        $prefix = isset($parts[1]) ? (int)$parts[1] : $bits;
        if ($prefix < 0 || $prefix > $bits) {
            return false;
        }

        $fullbytes = intdiv($prefix, 8);
        $remaining = $prefix % 8;

        if ($fullbytes > 0 && substr($packed, 0, $fullbytes) !== substr($network, 0, $fullbytes)) {
            return false;
        }
        if ($remaining === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remaining)) & 0xff;
        return (ord($packed[$fullbytes]) & $mask) === (ord($network[$fullbytes]) & $mask);
    }

    /**
     * Validates and canonicalises an IP literal.
     *
     * @param string $ip Candidate value.
     * @return string|null Canonical address, or null when not a valid IP.
     */
    public static function normalise(string $ip): ?string {
        $ip = trim($ip);
        if ($ip === '') {
            return null;
        }
        // Strip an IPv6 zone identifier and any port suffix (e.g. 203.0.113.4:443).
        if (preg_match('/^\[(.+)\](?::\d+)?$/', $ip, $matches) === 1) {
            $ip = $matches[1];
        } else if (substr_count($ip, ':') === 1 && strpos($ip, '.') !== false) {
            $ip = explode(':', $ip, 2)[0];
        }
        $ip = preg_replace('/%[0-9a-zA-Z]+$/', '', $ip) ?? $ip;

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        return inet_ntop($packed) ?: null;
    }

    /**
     * Returns the first (left-most, client) hop from a forwarded-for style list.
     *
     * @param string $value Raw header value.
     * @return string
     */
    private static function first_hop(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        return trim(explode(',', $value, 2)[0]);
    }

    /**
     * Reads a header value from server variables in CGI form.
     *
     * @param array $server Server variables.
     * @param string $header Lower-case header name (dashes).
     * @return string|null
     */
    private static function header_value(array $server, string $header): ?string {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
        if (!isset($server[$key]) || !is_string($server[$key]) || trim($server[$key]) === '') {
            return null;
        }
        return $server[$key];
    }

    /**
     * Returns the configured trusted proxy ranges, falling back to the defaults.
     *
     * @return array<int, string>
     */
    private static function trusted_ranges(): array {
        $configured = (string)(get_config('local_ulms_auth', 'trustedproxies') ?: '');
        if (trim($configured) === '') {
            return self::DEFAULT_TRUSTED_RANGES;
        }
        $ranges = [];
        foreach (preg_split('/[\s,]+/', $configured) as $entry) {
            $entry = trim($entry);
            if ($entry !== '' && strpos($entry, '/') !== false) {
                $ranges[] = $entry;
            }
        }
        return $ranges === [] ? self::DEFAULT_TRUSTED_RANGES : $ranges;
    }

    /**
     * Returns the configured forwarded headers honoured for a trusted peer.
     *
     * @return array<int, string>
     */
    private static function trusted_headers(): array {
        $configured = (string)(get_config('local_ulms_auth', 'trustedproxyheaders') ?: '');
        if (trim($configured) === '') {
            return self::DEFAULT_TRUSTED_HEADERS;
        }
        $headers = [];
        foreach (preg_split('/[\s,]+/', strtolower($configured)) as $entry) {
            $entry = trim($entry);
            if ($entry !== '') {
                $headers[] = $entry;
            }
        }
        return $headers === [] ? self::DEFAULT_TRUSTED_HEADERS : $headers;
    }
}
