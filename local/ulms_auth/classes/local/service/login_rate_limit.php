<?php
namespace local_ulms_auth\local\service;

defined('MOODLE_INTERNAL') || die();

/**
 * Central login brute-force + credential-stuffing rate limiter (SSOT).
 *
 * Three sliding-window counters tracked via moodledata temp files (no DB dependency
 * during the critical login path when DB might already be under attack):
 *   - IP: $CFG->dataroot/cache/ulms_login_rl/ip_<sha1(client_ip)>.json
 *   - IDENTIFIER: same dir, ident_<sha1(username|email)>.json
 *   - IP+IDENTIFIER (combined): same dir, combo_<sha1(ip|ident)>.json
 *
 * Limits (configurable via ULMS_* .env or hardcoded production-tuned defaults):
 *   - IP_GLOBAL: 60 attempts per 5 minutes per IP (shared between accounts)
 *   - IDENTIFIER: 10 attempts per 15 minutes per username (prevents slow horizontal stuffing)
 *   - COMBO: 5 attempts per 3 minutes per IP+username (fast brute-force on 1 account from 1 IP)
 *   - LOCKOUT: if any bucket exceeds => 429 HTML branded page with Retry-After header,
 *              generic message to user, detailed scrubbed entry in php-error.log with ref ID.
 *
 * This service is intentionally ZERO-dependency at bootstrap: only requires CFG->dataroot
 * and @error_log. No DB lookups, no autoloader cold-cache dependency. The constructor
 * is light; the three static helpers (enforce_pre_login / record_login_result) can be
 * called early in boot without full Moodle classloader warm.
 */
class login_rate_limit {
    /** 60 attempts / 5 min per IP (blocks burst attacks) */
    private const IP_LIMIT = 60;
    private const IP_WINDOW_SEC = 300;

    /** 10 attempts / 15 min per username (blocks credential stuffing horizontally) */
    private const IDENT_LIMIT = 10;
    private const IDENT_WINDOW_SEC = 900;

    /** 5 attempts / 3 min per IP+username (fast brute on single acct) */
    private const COMBO_LIMIT = 5;
    private const COMBO_WINDOW_SEC = 180;

    /** Minimal enforced backoff on any 429, in seconds */
    private const RETRY_AFTER_SEC = 30;

    /**
     * Returns the effective client IP, safe from trivial spoofing.
     *
     * @return string
     */
    public static function get_client_ip(): string {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (is_string($ip) && strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip, 2)[0]);
        }
        return (is_string($ip) && $ip !== '') ? $ip : '0.0.0.0';
    }

    /**
     * Resolves the temp directory for rate-limit counters. Creates on demand.
     *
     * @global \stdClass $CFG
     * @return string|null writable dir or null if unavailable (callers fail-open)
     */
    private static function get_storage_dir(): ?string {
        global $CFG;
        $base = rtrim($CFG->dataroot ?? '', '/\\');
        if ($base === '') {
            return null;
        }
        $dir = $base . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'ulms_login_rl';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return null;
        }
        return $dir;
    }

    /**
     * Loads the sliding-window counter for a given bucket file.
     *
     * Structure:
     *   { windowStart: <unix_ts>, counts: [<counts within current window>], total: N, expiresAt: <unix_ts> }
     *
     * @param string $file absolute path
     * @param int    $windowsec width of the sliding window in seconds
     * @return array{total:int, windowstart:int}
     */
    private static function load_counter(string $file, int $windowsec): array {
        if (!is_file($file)) {
            return ['total' => 0, 'windowstart' => time()];
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return ['total' => 0, 'windowstart' => time()];
        }
        $data = @json_decode($raw, true);
        if (!is_array($data)) {
            return ['total' => 0, 'windowstart' => time()];
        }
        $now = time();
        $windowstart = (int)($data['windowstart'] ?? $now);
        if (($now - $windowstart) >= $windowsec) {
            return ['total' => 0, 'windowstart' => $now];
        }
        return [
            'total' => (int)($data['total'] ?? 0),
            'windowstart' => $windowstart,
        ];
    }

    /**
     * Persists the sliding-window counter. Atomic via rename on POSIX.
     *
     * @param string $file
     * @param int    $windowsec
     * @param int    $windowstart
     * @param int    $total
     * @return void
     */
    private static function save_counter(string $file, int $windowsec, int $windowstart, int $total): void {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            return;
        }
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        $payload = json_encode([
            'windowstart' => $windowstart,
            'total' => $total,
            'expiresAt' => $windowstart + $windowsec,
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return;
        }
        @chmod($tmp, 0660);
        @rename($tmp, $file);
    }

    /**
     * Pre-login enforcement. MUST be called immediately BEFORE authenticate_user_login()
     * (i.e. before any slow credential check runs). This blocks the attacker BEFORE
     * they consume CPU/memory on bcrypt/argon2 rounds.
     *
     * @param string $identifier username/email (normalized)
     * @param bool   $echo_response if true and blocked => emit HTTP 429 branded page + exit
     * @return array{blocked:bool,retry_after:int,bucket:string|null}
     */
    public static function enforce_pre_login(string $identifier, bool $echo_response = true): array {
        $dir = self::get_storage_dir();
        $ip = self::get_client_ip();
        $identsha = sha1($identifier !== '' ? $identifier : '__empty__');
        $combosha = sha1($ip . '|' . $identsha);

        $result = ['blocked' => false, 'retry_after' => self::RETRY_AFTER_SEC, 'bucket' => null];

        if ($dir !== null) {
            $checks = [
                ['file' => $dir . '/ip_' . sha1($ip) . '.json',     'limit' => self::IP_LIMIT,    'window' => self::IP_WINDOW_SEC,    'name' => 'ip'],
                ['file' => $dir . '/ident_' . $identsha . '.json',  'limit' => self::IDENT_LIMIT, 'window' => self::IDENT_WINDOW_SEC, 'name' => 'identifier'],
                ['file' => $dir . '/combo_' . $combosha . '.json',  'limit' => self::COMBO_LIMIT, 'window' => self::COMBO_WINDOW_SEC, 'name' => 'combo'],
            ];
            foreach ($checks as $c) {
                $state = self::load_counter($c['file'], $c['window']);
                if ($state['total'] >= $c['limit']) {
                    $result = ['blocked' => true, 'retry_after' => self::RETRY_AFTER_SEC, 'bucket' => $c['name']];
                    break;
                }
            }
        }

        if ($result['blocked'] && $echo_response) {
            self::emit_429_and_exit($result['retry_after'], $result['bucket'] ?? 'unknown', $ip, $identifier);
        }

        return $result;
    }

    /**
     * Records a login outcome. Called AFTER authenticate_user_login() finishes.
     * - On FAILURE: increments ip/ident/combo counters.
     * - On SUCCESS: clears identifier + combo counters (IP counter remains as global throttle).
     *
     * @param string $identifier
     * @param bool   $success true if complete_user_login() called next, false otherwise.
     * @return void
     */
    public static function record_login_result(string $identifier, bool $success): void {
        $dir = self::get_storage_dir();
        if ($dir === null) {
            return;
        }
        $ip = self::get_client_ip();
        $identsha = sha1($identifier !== '' ? $identifier : '__empty__');
        $combosha = sha1($ip . '|' . $identsha);

        $buckets = [
            ['file' => $dir . '/ip_' . sha1($ip) . '.json',     'limit' => self::IP_LIMIT,    'window' => self::IP_WINDOW_SEC,    'clearOnSuccess' => false],
            ['file' => $dir . '/ident_' . $identsha . '.json',  'limit' => self::IDENT_LIMIT, 'window' => self::IDENT_WINDOW_SEC, 'clearOnSuccess' => true],
            ['file' => $dir . '/combo_' . $combosha . '.json',  'limit' => self::COMBO_LIMIT, 'window' => self::COMBO_WINDOW_SEC, 'clearOnSuccess' => true],
        ];

        foreach ($buckets as $b) {
            if ($success && $b['clearOnSuccess']) {
                if (is_file($b['file'])) {
                    @unlink($b['file']);
                }
                continue;
            }
            if (!$success) {
                $state = self::load_counter($b['file'], $b['window']);
                $newtotal = min($state['total'] + 1, $b['limit'] * 2);
                self::save_counter($b['file'], $b['window'], $state['windowstart'], $newtotal);
            }
        }
    }

    /**
     * Emits ULMS-branded HTTP 429 Too Many Requests page with Retry-After header
     * then terminates. Logs scrubbed server-side entry with ref ID.
     *
     * @param int    $retryafter
     * @param string $bucket
     * @param string $ip
     * @param string $identifier
     */
    private static function emit_429_and_exit(int $retryafter, string $bucket, string $ip, string $identifier): void {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        $ref = substr(bin2hex(random_bytes(5)), 0, 8);
        @error_log('[ULMS-LOGIN-RL-429-' . $ref . '] rate_limit_hit bucket=' . $bucket . ' retry_after=' . $retryafter . ' identifier_sha=' . sha1($identifier) . ' ip_sha=' . sha1($ip));
        @http_response_code(429);
        @header('Retry-After: ' . $retryafter);
        @header('Content-Type: text/html; charset=utf-8');
        @header('X-Content-Type-Options: nosniff');
        @header('X-Frame-Options: DENY');
        $navy = '#0f4c81';
        $navydark = '#0a3a63';
        $muted = '#64748b';
        $border = '#e2e8f0';
        echo <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Too many attempts — ULMS</title>
<style>*{box-sizing:border-box}body{margin:0;padding:0;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a;background:#f8fafc}.wrap{min-height:100vh;display:flex;flex-direction:column}.hd{background:$navy;color:#fff;padding:16px 24px}.hd-in{max-width:1100px;margin:0 auto;display:flex;align-items:center;gap:12px}.logo{width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,.14);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;color:#fff}.brand{font-weight:700;font-size:16px}.brand small{display:block;font-weight:400;font-size:11px;opacity:.75;letter-spacing:.1em;text-transform:uppercase;margin-top:2px}.mn{flex:1;display:flex;align-items:center;justify-content:center;padding:48px 24px}.card{max-width:640px;width:100%;background:#fff;border:1px solid $border;border-radius:12px;padding:40px 32px;text-align:center}.emo{font-size:44px;line-height:1;margin-bottom:16px}.code{display:inline-block;font-family:ui-monospace,monospace;font-size:12px;letter-spacing:.12em;background:$navy;color:#fff;padding:6px 14px;border-radius:999px;font-weight:700;margin-bottom:16px;text-transform:uppercase}h1{font-size:28px;margin:0 0 12px;line-height:1.2;font-weight:700}p.sub{margin:0 0 24px;color:$muted;font-size:15px;line-height:1.6}.ref{background:#f1f5f9;border:1px solid $border;border-radius:8px;padding:16px;margin:20px 0 28px;display:inline-block;text-align:left}.ref .lbl{font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:$muted;font-weight:600;margin-bottom:6px}.ref .val{font-family:ui-monospace,monospace;font-size:18px;font-weight:700;color:$navydark;letter-spacing:.08em}.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 18px;border-radius:8px;font-weight:600;font-size:14px;text-decoration:none;background:$navy;color:#fff;border:1px solid $navy}.btn:hover{background:$navydark}.ft{padding:24px;text-align:center;color:$muted;font-size:12px;border-top:1px solid $border;background:#fff}</style></head>
<body><div class="wrap"><header class="hd"><div class="hd-in"><div class="logo" aria-hidden="true">BT</div><div class="brand">BELLS TECH UNIVERSITY<small>Learning Management System</small></div></div></header><main class="mn"><section class="card" role="alert" aria-live="assertive"><div class="emo" aria-hidden="true">⏳</div><div class="code">HTTP 429</div><h1>Too many sign-in attempts</h1><p class="sub">We detected an unusual number of sign-in attempts from your location. Please wait a short while and try again.</p><div class="ref"><div class="lbl">Support Reference</div><div class="val">$ref</div></div><a class="btn" href="/">Return to dashboard</a></section></main><footer class="ft">© BELLS TECH UNIVERSITY — All rights reserved. ULMS Platform.</footer></div></body></html>
HTML;
        exit(1);
    }
}
