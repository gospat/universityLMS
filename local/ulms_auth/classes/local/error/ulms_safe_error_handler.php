<?php
namespace local_ulms_auth\local\error;

defined('MOODLE_INTERNAL') || die();

final class ulms_safe_error_handler {

    private const SECRET_KEY_PATTERN = '/(PASSWORD|SECRET|TOKEN|KEY|PRIVATE|PASS|SMTP_|AUTH|OAUTH|DATABASE|CREDENTIAL|BEARER|COOKIE|SESSION|SALT|NONCE|APITOKEN|ACCESSTOKEN|REFRESHTOKEN|CLIENTSECRET|CLIENT_ID|CLIENT_SECRET|DBPASS|DB_USER|DB_PASSWORD|DBPASSWD|APPLICATION_KEY|APPLICATION_SECRET|CONSUMER_KEY|CONSUMER_SECRET)/i';

    private const REDACTED = '[REDACTED]';

    private const FATAL_ERROR_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];

    private static bool $registered = false;

    public static function register(): void {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        set_exception_handler([self::class, 'handle_exception']);
        set_error_handler([self::class, 'handle_error'], E_ALL | E_STRICT);
        register_shutdown_function([self::class, 'shutdown_handler_catch_fatal']);
    }

    public static function emit_http_response(int $httpcode, string $logmsg = '', ?string $errorid = null): void {
        if (self::is_cli_context()) {
            fwrite(STDERR, "ULMS HTTP " . $httpcode . ": " . ($logmsg ?: 'no details') . PHP_EOL);
            exit(1);
        }
        $id = $errorid ?? self::generate_error_id();
        if ($logmsg !== '') {
            @error_log('[ULMS-HTTP-' . $httpcode . '-' . $id . '] ' . self::scrub_string_secrets($logmsg));
        }
        if (self::is_ajax_request()) {
            self::render_json_and_exit($id, $httpcode);
            return;
        }
        self::render_branded_page_and_exit($httpcode, $id, null);
    }

    private static function is_cli_context(): bool {
        return defined('CLI_SCRIPT') && CLI_SCRIPT;
    }

    private static function is_ajax_request(): bool {
        if (defined('AJAX_SCRIPT') && AJAX_SCRIPT) {
            return true;
        }
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (stripos($accept, 'application/json') !== false) {
            return true;
        }
        $xhr = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        if (strtolower($xhr) === 'xmlhttprequest') {
            return true;
        }
        return false;
    }

    private static function is_visual_dev_mode(): bool {
        return defined('ULMS_SAFE_HANDLER_VISUAL') && ULMS_SAFE_HANDLER_VISUAL;
    }

    private static function generate_error_id(): string {
        return substr(bin2hex(random_bytes(5)), 0, 8);
    }

    public static function scrub_secrets(mixed &$data): void {
        if (is_array($data)) {
            foreach ($data as $key => &$value) {
                if (is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key)) {
                    $data[$key] = self::REDACTED;
                } else {
                    self::scrub_secrets($value);
                }
            }
            unset($value);
            return;
        }
        if (is_object($data)) {
            $arr = (array)$data;
            self::scrub_secrets($arr);
            foreach (array_keys($arr) as $k) {
                if (str_starts_with($k, "\x00")) {
                    $realkey = substr($k, strrpos($k, "\x00") + 1);
                } else {
                    $realkey = $k;
                }
                if (preg_match(self::SECRET_KEY_PATTERN, $realkey)) {
                    if (property_exists($data, $realkey)) {
                        try {
                            $rf = new \ReflectionProperty($data, $realkey);
                            if (!$rf->isPublic()) {
                                $rf->setAccessible(true);
                            }
                            $rf->setValue($data, self::REDACTED);
                        } catch (\Throwable $_e) {
                            $data->$realkey = self::REDACTED;
                        }
                    }
                }
            }
            return;
        }
    }

    private static function scrub_string_secrets(string $text): string {
        $secrets = self::collect_known_secret_values();
        if (empty($secrets)) {
            return $text;
        }
        foreach ($secrets as $secret) {
            if ($secret === '' || $secret === '0') {
                continue;
            }
            if (strlen($secret) < 4) {
                continue;
            }
            $text = str_replace($secret, self::REDACTED, $text);
        }
        return $text;
    }

    private static function collect_known_secret_values(): array {
        $out = [];
        if (isset($_ENV) && is_array($_ENV)) {
            foreach ($_ENV as $k => $v) {
                if (is_string($k) && preg_match(self::SECRET_KEY_PATTERN, $k) && is_string($v)) {
                    $out[] = $v;
                }
            }
        }
        if (isset($_SERVER) && is_array($_SERVER)) {
            foreach ($_SERVER as $k => $v) {
                if (is_string($k) && preg_match(self::SECRET_KEY_PATTERN, $k) && is_string($v)) {
                    $out[] = $v;
                }
            }
        }
        global $CFG;
        if (isset($CFG->dbpass) && is_string($CFG->dbpass)) {
            $out[] = $CFG->dbpass;
        }
        if (isset($CFG->smtppass) && is_string($CFG->smtppass)) {
            $out[] = $CFG->smtppass;
        }
        return array_values(array_unique(array_filter($out, 'is_string')));
    }

    private static function write_full_details_to_log(string $errorid, string $level, string $message, ?\Throwable $ex = null, array $extra = []): void {
        try {
            $envcopy = isset($_ENV) ? $_ENV : [];
            $servercopy = isset($_SERVER) ? $_SERVER : [];
            $postcopy = isset($_POST) ? $_POST : [];
            $getcopy = isset($_GET) ? $_GET : [];
            $cookcopy = isset($_COOKIE) ? $_COOKIE : [];
            $sesscopy = isset($_SESSION) ? $_SESSION : [];
            $usercopy = null;
            global $USER;
            if (isset($USER) && is_object($USER)) {
                $usercopy = clone $USER;
            }
            self::scrub_secrets($envcopy);
            self::scrub_secrets($servercopy);
            self::scrub_secrets($postcopy);
            self::scrub_secrets($getcopy);
            self::scrub_secrets($cookcopy);
            self::scrub_secrets($sesscopy);
            self::scrub_secrets($usercopy);

            $record = [
                'errorid' => $errorid,
                'level' => $level,
                'message' => $message,
                'time' => date('c'),
                'ex_class' => $ex ? get_class($ex) : null,
                'ex_code' => $ex ? $ex->getCode() : null,
                'ex_file' => $ex ? $ex->getFile() : null,
                'ex_line' => $ex ? $ex->getLine() : null,
                'backtrace' => $ex ? self::scrub_string_secrets($ex->getTraceAsString()) : null,
                'extra' => $extra,
                'env' => $envcopy,
                'server' => $servercopy,
                'post' => $postcopy,
                'get' => $getcopy,
                'cookie_keys' => array_keys($cookcopy),
                'session_keys' => is_array($sesscopy) ? array_keys($sesscopy) : null,
                'user' => self::scrub_string_secrets(var_export($usercopy, true)),
            ];
            $logline = '[ULMS-ERR-' . $errorid . '] ' . strtoupper($level) . ': ' . $message
                . ' | CLASS=' . ($ex ? get_class($ex) : 'none')
                . ' | CODE=' . ($ex ? $ex->getCode() : '0')
                . ' | FILE=' . ($ex ? $ex->getFile() : '?')
                . ':' . ($ex ? $ex->getLine() : '0');
            if (!empty($extra)) {
                $logline .= ' | EXTRA=' . json_encode($extra, JSON_UNESCAPED_SLASHES);
            }
            error_log($logline);
            if ($ex) {
                $fulldump = print_r($record, true);
                error_log('[ULMS-ERR-' . $errorid . '-FULL] ' . self::scrub_string_secrets($fulldump));
            }
        } catch (\Throwable $_) {
            @error_log('[ULMS-ERR-' . $errorid . '-FALLBACK] ' . $level . ': ' . self::scrub_string_secrets($message));
        }
    }

    private static function sanitize_stack_for_web(?\Throwable $ex, int $maxframes = 24): array {
        $frames = [];
        if (!$ex) {
            return $frames;
        }
        foreach ($ex->getTrace() as $i => $f) {
            if ($i >= $maxframes) {
                break;
            }
            $file = $f['file'] ?? '(unknown)';
            $line = $f['line'] ?? 0;
            $func = $f['function'] ?? '(closure)';
            $class = $f['class'] ?? '';
            $type = $f['type'] ?? '';
            $sig = ($class ? $class . $type : '') . $func . '()';
            $file = self::shorten_path($file);
            $frames[] = ['#'.$i, $sig, $file . ':' . $line];
        }
        return $frames;
    }

    private static function shorten_path(string $p): string {
        if (defined('CFG_DIRROOT_CONST')) {
            $root = CFG_DIRROOT_CONST;
        } else {
            $root = __DIR__;
            while ($root !== '' && basename($root) !== 'moodle') {
                $root = dirname($root);
            }
        }
        if ($root !== '' && str_starts_with($p, $root)) {
            return '[MOODLE_ROOT]' . substr($p, strlen($root));
        }
        $home = $_SERVER['HOME'] ?? '';
        if (is_string($home) && $home !== '' && str_starts_with($p, $home)) {
            return '[HOME]' . substr($p, strlen($home));
        }
        return $p;
    }

    public static function handle_exception(\Throwable $e): void {
        self::render_and_exit($e);
    }

    public static function handle_error(int $errno, string $errstr, string $errfile, int $errline): bool {
        $fatals = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
        if (in_array($errno, $fatals, true)) {
            $wrapped = new \ErrorException($errstr, 0, $errno, $errfile, $errline);
            self::render_and_exit($wrapped);
            return true;
        }
        $severity = match ($errno) {
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'warning',
            E_NOTICE, E_USER_NOTICE => 'notice',
            E_STRICT => 'strict',
            E_DEPRECATED, E_USER_DEPRECATED => 'deprecated',
            default => 'info',
        };
        $mini = "[PHP-" . strtoupper($severity) . "] " . self::shorten_path($errfile) . ":$errline " . self::scrub_string_secrets($errstr);
        @error_log($mini);
        return true;
    }

    public static function shutdown_handler_catch_fatal(): void {
        $err = error_get_last();
        if ($err === null) {
            return;
        }
        if (!in_array($err['type'], self::FATAL_ERROR_TYPES, true)) {
            return;
        }
        if (self::is_cli_context()) {
            return;
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        $wrapped = new \ErrorException($err['message'], 0, $err['type'], $err['file'], $err['line']);
        self::render_and_exit($wrapped, true);
    }

    private static function render_and_exit(\Throwable $ex, bool $fromshutdown = false): void {
        global $DB;
        try {
            if (isset($DB) && is_object($DB) && method_exists($DB, 'set_debug')) {
                $DB->set_debug(0);
            }
        } catch (\Throwable $_) {
        }
        try {
            if (function_exists('abort_all_db_transactions')) {
                @abort_all_db_transactions();
            }
        } catch (\Throwable $_) {
        }

        if (self::is_cli_context()) {
            self::render_cli_and_exit($ex);
            return;
        }

        $errorid = self::generate_error_id();
        self::write_full_details_to_log($errorid, 'exception', $ex->getMessage(), $ex, [
            'fromshutdown' => $fromshutdown,
        ]);

        if (self::is_ajax_request()) {
            self::render_json_and_exit($errorid, 500);
            return;
        }

        $httpcode = self::http_code_for_exception($ex);
        self::render_branded_page_and_exit($httpcode, $errorid, $ex);
    }

    private static function extract_user_message(\Throwable $ex): string {
        if ($ex instanceof \moodle_exception) {
            try {
                $main = $ex->getMessage();
                return $main ?: 'A system error occurred.';
            } catch (\Throwable $_e) {
                return 'A system error occurred.';
            }
        }
        return 'A system error occurred. Please retry.';
    }

    private static function http_code_for_exception(\Throwable $ex): int {
        if ($ex instanceof \required_capability_exception) {
            return 403;
        }
        if ($ex instanceof \moodle_exception) {
            $ec = strtolower((string)($ex->errorcode ?? ''));
            if (str_contains($ec, 'notfound') || str_contains($ec, 'missing') || str_contains($ec, 'invalidroute')) {
                return 404;
            }
            if (str_contains($ec, 'requirelogin') || str_contains($ec, 'nopermission') || str_contains($ec, 'accessdenied')) {
                return 403;
            }
        }
        return 500;
    }

    private static function render_cli_and_exit(\Throwable $e): void {
        $errid = self::generate_error_id();
        self::write_full_details_to_log($errid, 'exception', $e->getMessage(), $e, ['sapi' => PHP_SAPI]);
        fwrite(STDERR, "ULMS Error [$errid]: " . $e->getMessage() . PHP_EOL);
        fwrite(STDERR, "  in " . self::shorten_path($e->getFile()) . ":" . $e->getLine() . PHP_EOL);
        $trace = self::sanitize_stack_for_web($e, 50);
        foreach ($trace as $f) {
            fwrite(STDERR, "  " . $f[0] . " " . $f[1] . " @ " . $f[2] . PHP_EOL);
        }
        exit(1);
    }

    private static function render_json_and_exit(string $errorid, int $httpcode): void {
        @header_remove('Content-Length');
        @http_response_code($httpcode);
        @header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => true,
            'message' => 'A system error occurred. Please retry.',
            'ref' => $errorid,
        ], JSON_UNESCAPED_SLASHES);
        exit(1);
    }

    private static function render_branded_page_and_exit(int $httpcode, string $errorid, ?\Throwable $ex = null): void {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        @header_remove('Content-Length');
        @http_response_code($httpcode);
        @header('Content-Type: text/html; charset=utf-8');
        @header('X-Content-Type-Options: nosniff');
        @header('X-Frame-Options: DENY');
        @header('Referrer-Policy: no-referrer');

        $brandnavy = '#0f4c81';
        $brandnavydark = '#0a3a63';
        $muted = '#64748b';
        $border = '#e2e8f0';

        if ($httpcode === 404) {
            $title = 'Page not found';
            $emoji = '🔍';
            $headline = 'We couldn\'t find that page';
            $subhead = 'The page you are looking for may have been moved, renamed, or it never existed.';
        } elseif ($httpcode === 403) {
            $title = 'Access denied';
            $emoji = '🔒';
            $headline = 'You don\'t have permission to access this page';
            $subhead = 'If you believe this is a mistake, please contact your administrator or the ULMS support team.';
        } else {
            $title = 'Something went wrong';
            $emoji = '🛠️';
            $headline = 'Something went wrong on our end';
            $subhead = 'Our team has been notified. If the issue persists please contact support and reference the error ID below.';
        }

        $devstack = '';
        if (self::is_visual_dev_mode() && $ex !== null) {
            $frames = self::sanitize_stack_for_web($ex, 40);
            $rows = '';
            foreach ($frames as $f) {
                $rows .= '<tr><td style="padding:8px 12px;border-bottom:1px solid ' . $border . ';color:#334155;white-space:nowrap">' . htmlspecialchars($f[0]) . '</td>'
                    . '<td style="padding:8px 12px;border-bottom:1px solid ' . $border . ';color:#0f172a;font-family:ui-monospace,monospace;font-size:13px">' . htmlspecialchars($f[1]) . '</td>'
                    . '<td style="padding:8px 12px;border-bottom:1px solid ' . $border . ';color:' . $muted . ';font-family:ui-monospace,monospace;font-size:13px">' . htmlspecialchars($f[2]) . '</td></tr>';
            }
            $exmsg = htmlspecialchars(self::scrub_string_secrets($ex->getMessage()));
            $exfile = htmlspecialchars(self::shorten_path($ex->getFile()) . ':' . $ex->getLine());
            $exclass = htmlspecialchars(get_class($ex));
            $devstack = <<<HTML
<div style="margin-top:40px;text-align:left">
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
    <span style="font-family:ui-monospace,monospace;font-size:11px;letter-spacing:.08em;background:#f1f5f9;color:$brandnavy;padding:4px 10px;border-radius:999px;font-weight:600;text-transform:uppercase">Local Dev Only — Sanitized Stack</span>
    <span style="font-family:ui-monospace,monospace;color:$muted;font-size:12px">No globals, no secrets, no env dump.</span>
  </div>
  <div style="background:#f8fafc;border:1px solid $border;border-radius:8px;padding:16px;margin-bottom:12px">
    <div style="font-weight:600;color:#0f172a;font-family:ui-monospace,monospace;font-size:13px;margin-bottom:6px">$exclass</div>
    <div style="color:#1e293b;font-size:14px;line-height:1.5;margin-bottom:6px">$exmsg</div>
    <div style="font-family:ui-monospace,monospace;color:$muted;font-size:12px">$exfile</div>
  </div>
  <div style="background:#ffffff;border:1px solid $border;border-radius:8px;overflow:auto;max-height:520px">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead>
        <tr style="background:#f1f5f9">
          <th style="padding:10px 12px;text-align:left;font-size:11px;letter-spacing:.04em;color:$muted;text-transform:uppercase;font-weight:600;position:sticky;top:0;background:#f1f5f9">#</th>
          <th style="padding:10px 12px;text-align:left;font-size:11px;letter-spacing:.04em;color:$muted;text-transform:uppercase;font-weight:600;position:sticky;top:0;background:#f1f5f9">Call</th>
          <th style="padding:10px 12px;text-align:left;font-size:11px;letter-spacing:.04em;color:$muted;text-transform:uppercase;font-weight:600;position:sticky;top:0;background:#f1f5f9">Location</th>
        </tr>
      </thead>
      <tbody>$rows</tbody>
    </table>
  </div>
</div>
HTML;
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>$title — ULMS</title>
<style>
  *{box-sizing:border-box}
  body{margin:0;padding:0;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a;background:#f8fafc;-webkit-font-smoothing:antialiased}
  .wrap{min-height:100vh;display:flex;flex-direction:column}
  .hd{background:$brandnavy;color:#fff;padding:16px 24px;box-shadow:0 1px 0 rgba(0,0,0,.04)}
  .hd-in{max-width:1100px;margin:0 auto;display:flex;align-items:center;gap:12px}
  .logo{width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,.14);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;letter-spacing:.02em;color:#fff}
  .brand{font-weight:700;font-size:16px;letter-spacing:.01em}
  .brand small{display:block;font-weight:400;font-size:11px;opacity:.75;letter-spacing:.1em;text-transform:uppercase;margin-top:2px}
  .mn{flex:1;display:flex;align-items:center;justify-content:center;padding:48px 24px}
  .card{max-width:640px;width:100%;background:#fff;border:1px solid $border;border-radius:12px;padding:40px 32px;text-align:center;box-shadow:0 1px 2px rgba(15,23,42,.04)}
  .emo{font-size:44px;line-height:1;margin-bottom:16px}
  .code{display:inline-block;font-family:ui-monospace,monospace;font-size:12px;letter-spacing:.12em;background:$brandnavy;color:#fff;padding:6px 14px;border-radius:999px;font-weight:700;margin-bottom:16px;text-transform:uppercase}
  h1{font-size:28px;margin:0 0 12px;line-height:1.2;font-weight:700;color:#0f172a}
  p.sub{margin:0 0 24px;color:$muted;font-size:15px;line-height:1.6}
  .ref{background:#f1f5f9;border:1px solid $border;border-radius:8px;padding:16px;margin:20px 0 28px;display:inline-block;text-align:left}
  .ref .lbl{font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:$muted;font-weight:600;margin-bottom:6px}
  .ref .val{font-family:ui-monospace,monospace;font-size:18px;font-weight:700;color:$brandnavydark;letter-spacing:.08em}
  .actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
  .btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 18px;border-radius:8px;font-weight:600;font-size:14px;text-decoration:none;line-height:1;transition:none;border:1px solid transparent}
  .btn-primary{background:$brandnavy;color:#fff;border-color:$brandnavy}
  .btn-primary:hover{background:$brandnavydark}
  .btn-ghost{background:#fff;color:$brandnavy;border-color:$border}
  .btn-ghost:hover{background:#f1f5f9}
  .ft{padding:24px;text-align:center;color:$muted;font-size:12px;border-top:1px solid $border;background:#fff}
  @media(max-width:480px){
    .card{padding:28px 20px}
    h1{font-size:22px}
    .hd{padding:14px 18px}
    .mn{padding:32px 18px}
  }
</style>
</head>
<body>
<div class="wrap">
  <header class="hd">
    <div class="hd-in">
      <div class="logo" aria-hidden="true">BT</div>
      <div class="brand">
        BELLS TECH UNIVERSITY
        <small>Learning Management System</small>
      </div>
    </div>
  </header>
  <main class="mn">
    <section class="card" role="alert" aria-live="assertive">
      <div class="emo" aria-hidden="true">$emoji</div>
      <div class="code">HTTP $httpcode</div>
      <h1>$headline</h1>
      <p class="sub">$subhead</p>
      <div class="ref" aria-label="Error reference">
        <div class="lbl">Error Reference ID</div>
        <div class="val" id="errid">$errorid</div>
      </div>
      <div class="actions">
        <a class="btn btn-primary" href="/" aria-label="Return to ULMS homepage">Return to dashboard</a>
        <button type="button" class="btn btn-ghost" onclick="(function(){const i=document.getElementById('errid');const n=document.createElement('textarea');n.value=i.textContent;document.body.appendChild(n);n.select();document.execCommand('copy');document.body.removeChild(n);const b=event.target;const o=b.textContent;b.textContent='Copied!';setTimeout(function(){b.textContent=o},1400)})()">Copy reference ID</button>
      </div>
      $devstack
    </section>
  </main>
  <footer class="ft">© BELLS TECH UNIVERSITY — All rights reserved. ULMS Platform.</footer>
</div>
</body>
</html>
HTML;
        echo $html;
        exit(1);
    }
}
