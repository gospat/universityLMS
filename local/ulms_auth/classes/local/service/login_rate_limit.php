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

namespace local_ulms_auth\local\service;

use local_ulms_auth\local\service\ratelimit\client_ip;
use local_ulms_auth\local\service\ratelimit\login_outcome;
use local_ulms_auth\local\service\ratelimit\login_rate_limiter;

defined('MOODLE_INTERNAL') || die();

/**
 * Entry point for login brute-force and credential-stuffing protection.
 *
 * This class is the stable facade used by the login controller. Enforcement and
 * accounting are delegated to {@see login_rate_limiter}, which selects the Redis
 * backend (falling back to a bounded file store) and applies the configurable policy.
 *
 * The client address is resolved by {@see client_ip}, which only honours forwarded
 * headers when the immediate peer is a verified trusted proxy, so a direct-to-origin
 * request cannot spoof its address and Cloudflare users are not collapsed into one IP.
 *
 * @package    local_ulms_auth
 * @copyright  2026 Bells University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class login_rate_limit {
    /**
     * Returns the effective client IP, safe from trivial spoofing.
     *
     * @return string
     */
    public static function get_client_ip(): string {
        return client_ip::resolve();
    }

    /**
     * Pre-login enforcement. Must be called immediately before authenticate_user_login()
     * so a blocked client never consumes password-hashing resources.
     *
     * @param string $identifier username/email (normalised)
     * @param bool $echo_response if true and blocked => emit a branded HTTP 429 page + exit
     * @return array{blocked:bool,retry_after:int,bucket:string|null}
     */
    public static function enforce_pre_login(string $identifier, bool $echo_response = true): array {
        $limiter = login_rate_limiter::instance();
        $result = $limiter->check($identifier);

        if ($result['blocked']) {
            self::log_blocked($result, $limiter->client_ip(), $identifier);
            if ($echo_response) {
                self::emit_429_and_exit(
                    (int)$result['retry_after'],
                    (string)($result['bucket'] ?? 'unknown'),
                    $limiter->client_ip(),
                    $identifier
                );
            }
        }

        return $result;
    }

    /**
     * Records a login outcome using the legacy boolean contract.
     *
     * @param string $identifier
     * @param bool $success true for a full success, false for a credential failure.
     * @return void
     */
    public static function record_login_result(string $identifier, bool $success): void {
        self::record_outcome($identifier, $success ? login_outcome::SUCCESS : login_outcome::FAILURE);
    }

    /**
     * Records a login outcome using the three-state contract.
     *
     * @param string $identifier
     * @param string $outcome One of login_outcome::SUCCESS|FAILURE|NEUTRAL.
     * @return void
     */
    public static function record_outcome(string $identifier, string $outcome): void {
        login_rate_limiter::instance()->record($identifier, $outcome);
    }

    /**
     * Emits a structured security event for a blocked attempt.
     *
     * @param array{blocked:bool,retry_after:int,bucket:string|null} $result
     * @param string $ip
     * @param string $identifier
     * @return void
     */
    private static function log_blocked(array $result, string $ip, string $identifier): void {
        $data = [
            'bucket' => (string)($result['bucket'] ?? 'unknown'),
            'retry_after' => (int)($result['retry_after'] ?? 0),
            'identifier_sha' => sha1($identifier),
            'ip_sha' => sha1($ip),
        ];
        if (function_exists('\local_ulms_auth_log_security_event')) {
            \local_ulms_auth_log_security_event('login_rate_limited', $data);
        }
    }

    /**
     * Resolves institution branding values with graceful fallbacks for very early boot.
     *
     * @return array{name:string,short:string,footer:string,color:string}
     */
    private static function resolve_branding(): array {
        $generic_name = 'Your University';
        if (function_exists('ulms_institution_cascade')) {
            $name = ulms_institution_cascade('NAME', [
                'ULMS_DEFAULT_SUPPORT_NAME',
                'SMTP_SUPPORT_NAME',
                'RESEND_FROM_NAME',
            ], $generic_name);
        } else {
            $name = (string)(($_ENV['INSTITUTION_NAME'] ?? getenv('INSTITUTION_NAME')) ?: $generic_name);
        }
        if (function_exists('ulms_env')) {
            $short_override = (string)ulms_env('INSTITUTION_SHORT_CODE', '');
            $footer_override = (string)ulms_env('INSTITUTION_FOOTER', '');
        } else {
            $short_override = (string)(($_ENV['INSTITUTION_SHORT_CODE'] ?? getenv('INSTITUTION_SHORT_CODE')) ?: '');
            $footer_override = (string)(($_ENV['INSTITUTION_FOOTER'] ?? getenv('INSTITUTION_FOOTER')) ?: '');
        }
        if ($short_override !== '') {
            $short = $short_override;
        } elseif (function_exists('ulms_institution_short_code')) {
            $short = ulms_institution_short_code($name);
        } else {
            $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
            $s = '';
            foreach ((array)$words as $w) {
                $c = mb_substr($w, 0, 1);
                if (preg_match('/[A-Za-z0-9]/', $c)) {
                    $s .= strtoupper($c);
                }
                if (mb_strlen($s) >= 4) {
                    break;
                }
            }
            $short = $s !== '' ? $s : 'ULMS';
        }
        if ($footer_override !== '') {
            $footer = $footer_override;
        } else {
            $footer = '© ' . $name . ' — All rights reserved. ULMS Platform.';
        }

        return [
            'name' => $name,
            'short' => $short,
            'footer' => $footer,
            'color' => self::brand_colour(),
        ];
    }

    /**
     * Returns the validated brand colour, falling back to the ULMS primary.
     *
     * @return string
     */
    private static function brand_colour(): string {
        $candidate = (string)(get_config('theme_ulms_university', 'brandcolor') ?: '');
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $candidate) === 1) {
            return strtolower($candidate);
        }
        return '#0f4c81';
    }

    /**
     * Emits a ULMS-branded, non-cacheable HTTP 429 response then terminates.
     *
     * The advertised Retry-After is derived from the genuine remaining lifetime of the
     * restricting bucket. No thresholds, bucket identifiers or account existence are
     * revealed to the user; details are written to the server log only.
     *
     * @param int $retryafter
     * @param string $bucket
     * @param string $ip
     * @param string $identifier
     * @return void
     */
    private static function emit_429_and_exit(int $retryafter, string $bucket, string $ip, string $identifier): void {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        $retryafter = max(1, $retryafter);
        $branding = self::resolve_branding();
        $colour = $branding['color'];
        $colourdark = self::darken($colour);
        $ref = substr(bin2hex(random_bytes(5)), 0, 8);

        error_log('[ULMS-LOGIN-RL-429-' . $ref . '] rate_limit_hit bucket=' . $bucket
            . ' retry_after=' . $retryafter . ' identifier_sha=' . sha1($identifier) . ' ip_sha=' . sha1($ip));

        if (!headers_sent()) {
            @http_response_code(429);
            @header('Retry-After: ' . $retryafter);
            @header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            @header('Pragma: no-cache');
            @header('Expires: 0');
            @header('Content-Type: text/html; charset=utf-8');
            @header('X-Content-Type-Options: nosniff');
            @header('X-Frame-Options: DENY');
        }

        $brand_name_html = htmlspecialchars($branding['name'], ENT_QUOTES, 'UTF-8');
        $brand_short_html = htmlspecialchars($branding['short'], ENT_QUOTES, 'UTF-8');
        $brand_footer_html = htmlspecialchars($branding['footer'], ENT_QUOTES, 'UTF-8');
        $wait_text = htmlspecialchars(self::format_wait($retryafter), ENT_QUOTES, 'UTF-8');
        $muted = '#64748b';
        $border = '#e2e8f0';
        echo <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Too many attempts — ULMS</title>
<style>*{box-sizing:border-box}body{margin:0;padding:0;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a;background:#f8fafc}.wrap{min-height:100vh;display:flex;flex-direction:column}.hd{background:$colour;color:#fff;padding:16px 24px}.hd-in{max-width:1100px;margin:0 auto;display:flex;align-items:center;gap:12px}.logo{width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,.14);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;color:#fff}.brand{font-weight:700;font-size:16px}.brand small{display:block;font-weight:400;font-size:11px;opacity:.75;letter-spacing:.1em;text-transform:uppercase;margin-top:2px}.mn{flex:1;display:flex;align-items:center;justify-content:center;padding:48px 24px}.card{max-width:640px;width:100%;background:#fff;border:1px solid $border;border-radius:12px;padding:40px 32px;text-align:center}.emo{font-size:44px;line-height:1;margin-bottom:16px}.code{display:inline-block;font-family:ui-monospace,monospace;font-size:12px;letter-spacing:.12em;background:$colour;color:#fff;padding:6px 14px;border-radius:999px;font-weight:700;margin-bottom:16px;text-transform:uppercase}h1{font-size:28px;margin:0 0 12px;line-height:1.2;font-weight:700}p.sub{margin:0 0 24px;color:$muted;font-size:15px;line-height:1.6}.ref{background:#f1f5f9;border:1px solid $border;border-radius:8px;padding:16px;margin:20px 0 28px;display:inline-block;text-align:left}.ref .lbl{font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:$muted;font-weight:600;margin-bottom:6px}.ref .val{font-family:ui-monospace,monospace;font-size:18px;font-weight:700;color:$colourdark;letter-spacing:.08em}.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 18px;border-radius:8px;font-weight:600;font-size:14px;text-decoration:none;background:$colour;color:#fff;border:1px solid $colour}.btn:hover{background:$colourdark}.ft{padding:24px;text-align:center;color:$muted;font-size:12px;border-top:1px solid $border;background:#fff}</style></head>
<body><div class="wrap"><header class="hd"><div class="hd-in"><div class="logo" aria-hidden="true">{$brand_short_html}</div><div class="brand">{$brand_name_html}<small>Learning Management System</small></div></div></header><main class="mn"><section class="card" role="alert" aria-live="assertive"><div class="emo" aria-hidden="true">⏳</div><div class="code">HTTP 429</div><h1>Too many sign-in attempts</h1><p class="sub">We detected an unusual number of sign-in attempts from your location. For your security, please wait {$wait_text} before trying again.</p><div class="ref"><div class="lbl">Support Reference</div><div class="val">{$ref}</div></div><a class="btn" href="/">Return to dashboard</a></section></main><footer class="ft">{$brand_footer_html}</footer></div></body></html>
HTML;
        exit(1);
    }

    /**
     * Formats a wait duration for display without exposing exact thresholds.
     *
     * @param int $seconds
     * @return string
     */
    private static function format_wait(int $seconds): string {
        if ($seconds >= 60) {
            $minutes = (int)ceil($seconds / 60);
            return $minutes === 1 ? 'about a minute' : 'about ' . $minutes . ' minutes';
        }
        return $seconds . ' seconds';
    }

    /**
     * Returns a darker shade of a hex colour for hover/contrast states.
     *
     * @param string $hex
     * @return string
     */
    private static function darken(string $hex): string {
        if (preg_match('/^#([0-9a-fA-F]{2})([0-9a-fA-F]{2})([0-9a-fA-F]{2})$/', $hex, $m) !== 1) {
            return '#0a3a63';
        }
        $shade = static function (string $channel): string {
            return str_pad(dechex(max(0, (int)round(hexdec($channel) * 0.78))), 2, '0', STR_PAD_LEFT);
        };
        return '#' . $shade($m[1]) . $shade($m[2]) . $shade($m[3]);
    }
}
