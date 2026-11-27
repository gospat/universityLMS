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
 * Immutable, fully configurable policy for the login rate limiter.
 *
 * Every threshold, window, backend choice and trusted-proxy toggle lives here so the
 * enforcement logic never hardcodes a value. Values are resolved from the plugin
 * settings and may be overridden by the deployment environment through ulms_env().
 *
 * Policy model (three independent buckets):
 *   - combo   : per client IP + account. Catches a fast brute force on one account.
 *   - account : per account, across every IP. Catches slow horizontal stuffing.
 *   - abuseip : per client IP, across every account. Detects distributed abuse with a
 *               deliberately high ceiling so unrelated accounts behind a shared NAT or
 *               institutional egress address are never locked out by one bad account.
 *
 * @package    local_ulms_auth
 * @copyright  2026 Bells University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rate_limit_policy {
    /** @var string Let the limiter pick Redis and fall back to files when needed. */
    public const BACKEND_AUTO = 'auto';

    /** @var string Force the Redis backend. */
    public const BACKEND_REDIS = 'redis';

    /** @var string Force the local file backend. */
    public const BACKEND_FILE = 'file';

    /** @var bool */
    private bool $enabled;
    /** @var string */
    private string $backend;
    /** @var bool */
    private bool $fallbackenabled;
    /** @var bool */
    private bool $securitylogging;

    /** @var int */
    private int $combolimit;
    /** @var int */
    private int $combowindow;
    /** @var int */
    private int $accountlimit;
    /** @var int */
    private int $accountwindow;
    /** @var int */
    private int $abuseiplimit;
    /** @var int */
    private int $abuseipwindow;

    /** @var int */
    private int $minimumretry;
    /** @var int */
    private int $maximumretry;

    /**
     * Constructor.
     *
     * @param bool $enabled Master feature flag.
     * @param string $backend Selected backend (auto|redis|file).
     * @param bool $fallbackenabled Whether a bounded file fallback is permitted.
     * @param bool $securitylogging Whether structured security events are emitted.
     * @param int $combolimit Combined IP+account failure limit.
     * @param int $combowindow Combined IP+account window in seconds.
     * @param int $accountlimit Account failure limit.
     * @param int $accountwindow Account window in seconds.
     * @param int $abuseiplimit Per-IP abuse limit.
     * @param int $abuseipwindow Per-IP abuse window in seconds.
     * @param int $minimumretry Lower bound for the advertised Retry-After, seconds.
     * @param int $maximumretry Upper bound for the advertised Retry-After, seconds.
     */
    public function __construct(
        bool $enabled = true,
        string $backend = self::BACKEND_AUTO,
        bool $fallbackenabled = true,
        bool $securitylogging = true,
        int $combolimit = 5,
        int $combowindow = 180,
        int $accountlimit = 10,
        int $accountwindow = 900,
        int $abuseiplimit = 300,
        int $abuseipwindow = 300,
        int $minimumretry = 15,
        int $maximumretry = 900
    ) {
        $this->enabled = $enabled;
        $this->backend = in_array($backend, [self::BACKEND_AUTO, self::BACKEND_REDIS, self::BACKEND_FILE], true)
            ? $backend
            : self::BACKEND_AUTO;
        $this->fallbackenabled = $fallbackenabled;
        $this->securitylogging = $securitylogging;
        $this->combolimit = max(1, $combolimit);
        $this->combowindow = max(1, $combowindow);
        $this->accountlimit = max(1, $accountlimit);
        $this->accountwindow = max(1, $accountwindow);
        $this->abuseiplimit = max(1, $abuseiplimit);
        $this->abuseipwindow = max(1, $abuseipwindow);
        $this->minimumretry = max(1, $minimumretry);
        $this->maximumretry = max($this->minimumretry, $maximumretry);
    }

    /**
     * Builds a policy from the plugin settings, with environment overrides.
     *
     * @return self
     */
    public static function from_config(): self {
        return new self(
            self::flag('enableratelimit', 'ULMS_LOGIN_RATELIMIT_ENABLED', true),
            self::text('ratelimitbackend', 'ULMS_LOGIN_RATELIMIT_BACKEND', self::BACKEND_AUTO),
            self::flag('ratelimitfallback', 'ULMS_LOGIN_RATELIMIT_FALLBACK', true),
            self::flag('ratelimitsecuritylogging', 'ULMS_LOGIN_RATELIMIT_LOGGING', true),
            self::number('ratelimitcombolimit', 'ULMS_LOGIN_RATELIMIT_COMBO_LIMIT', 5),
            self::number('ratelimitcombowindow', 'ULMS_LOGIN_RATELIMIT_COMBO_WINDOW', 180),
            self::number('ratelimitaccountlimit', 'ULMS_LOGIN_RATELIMIT_ACCOUNT_LIMIT', 10),
            self::number('ratelimitaccountwindow', 'ULMS_LOGIN_RATELIMIT_ACCOUNT_WINDOW', 900),
            self::number('ratelimitabuseiplimit', 'ULMS_LOGIN_RATELIMIT_ABUSE_IP_LIMIT', 300),
            self::number('ratelimitabuseipwindow', 'ULMS_LOGIN_RATELIMIT_ABUSE_IP_WINDOW', 300),
            self::number('ratelimitminimumretry', 'ULMS_LOGIN_RATELIMIT_MIN_RETRY', 15),
            self::number('ratelimitmaximumretry', 'ULMS_LOGIN_RATELIMIT_MAX_RETRY', 900)
        );
    }

    /**
     * Whether the limiter is active at all.
     *
     * @return bool
     */
    public function enabled(): bool {
        return $this->enabled;
    }

    /**
     * The selected backend identifier.
     *
     * @return string
     */
    public function backend(): string {
        return $this->backend;
    }

    /**
     * Whether a bounded file fallback may be used when Redis is unavailable.
     *
     * @return bool
     */
    public function fallback_enabled(): bool {
        return $this->fallbackenabled;
    }

    /**
     * Whether structured security logging is enabled.
     *
     * @return bool
     */
    public function security_logging_enabled(): bool {
        return $this->securitylogging;
    }

    /**
     * Bucket definitions, in evaluation order.
     *
     * @return array<int, array{name:string,limit:int,window:int}>
     */
    public function buckets(): array {
        return [
            ['name' => 'combo', 'limit' => $this->combolimit, 'window' => $this->combowindow],
            ['name' => 'account', 'limit' => $this->accountlimit, 'window' => $this->accountwindow],
            ['name' => 'abuseip', 'limit' => $this->abuseiplimit, 'window' => $this->abuseipwindow],
        ];
    }

    /**
     * Clamps a raw remaining-lifetime value into the advertised Retry-After range.
     *
     * @param int $ttl Remaining lifetime in seconds from the restricting bucket.
     * @return int
     */
    public function clamp_retry(int $ttl): int {
        return max($this->minimumretry, min($this->maximumretry, $ttl));
    }

    /**
     * Reads a boolean plugin setting, falling back to an environment override then default.
     *
     * @param string $name Setting name.
     * @param string $envkey Environment variable name.
     * @param bool $default Default value.
     * @return bool
     */
    private static function flag(string $name, string $envkey, bool $default): bool {
        $raw = self::raw($name, $envkey);
        if ($raw === null) {
            return $default;
        }
        return (bool)clean_param($raw, PARAM_BOOL);
    }

    /**
     * Reads an integer plugin setting, falling back to an environment override then default.
     *
     * @param string $name Setting name.
     * @param string $envkey Environment variable name.
     * @param int $default Default value.
     * @return int
     */
    private static function number(string $name, string $envkey, int $default): int {
        $raw = self::raw($name, $envkey);
        if ($raw === null || $raw === '') {
            return $default;
        }
        return (int)$raw;
    }

    /**
     * Reads a string plugin setting, falling back to an environment override then default.
     *
     * @param string $name Setting name.
     * @param string $envkey Environment variable name.
     * @param string $default Default value.
     * @return string
     */
    private static function text(string $name, string $envkey, string $default): string {
        $raw = self::raw($name, $envkey);
        if ($raw === null || $raw === '') {
            return $default;
        }
        return (string)$raw;
    }

    /**
     * Returns the raw configured value for a setting or environment variable.
     *
     * @param string $name Setting name.
     * @param string $envkey Environment variable name.
     * @return string|null Null when neither source provides a value.
     */
    private static function raw(string $name, string $envkey): ?string {
        $configured = get_config('local_ulms_auth', $name);
        if ($configured !== false && $configured !== null && $configured !== '') {
            return (string)$configured;
        }
        if (function_exists('ulms_env')) {
            $env = ulms_env($envkey, null);
            if ($env !== null && $env !== '') {
                return (string)$env;
            }
        }
        return null;
    }
}
