<?php
///////////////////////////////////////////////////////////////////////////
// Moodle configuration file (ULMS wrapper variant)
// Boot order:
//   1. Load ULMS root .env into $_ENV + $_SERVER via simple parser
//   2. Define helper ulms_env(string $key, $default = null) used by
//      local_ulms_kortext adapter + factory for tiered overrides.
//   3. Populate $CFG for standard Moodle 4.x boot
///////////////////////////////////////////////////////////////////////////

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('html_errors', '0');

function ulms_emergency_shutdown_fallback(): void {
    if (PHP_SAPI === 'cli') {
        return;
    }
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatals = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($err['type'], $fatals, true)) {
        return;
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    @header_remove('Content-Length');
    @http_response_code(500);
    @header('Content-Type: text/html; charset=utf-8');
    @header('X-Content-Type-Options: nosniff');
    @header('X-Frame-Options: DENY');
    $errorid = substr(bin2hex(random_bytes(5)), 0, 8);
    $secrets = [];
    if (isset($_ENV) && is_array($_ENV)) {
        foreach ($_ENV as $k => $v) {
            if (is_string($k) && preg_match('/(PASSWORD|SECRET|TOKEN|KEY|PRIVATE|PASS|SMTP_|AUTH|OAUTH|DATABASE|CREDENTIAL|BEARER|COOKIE|SESSION|SALT|NONCE)/i', $k) && is_string($v) && strlen($v) >= 4) {
                $secrets[] = $v;
            }
        }
    }
    $msg = 'A critical system error occurred during bootstrap. Our team has been notified.';
    $logmsg = '[ULMS-EMERGENCY-' . $errorid . '] FATAL during bootstrap: ' . str_replace($secrets, '[REDACTED]', $err['message']) . ' in ' . $err['file'] . ':' . $err['line'];
    @error_log($logmsg);
    $navy = '#0f4c81';
    $navydark = '#0a3a63';
    $muted = '#64748b';
    $border = '#e2e8f0';
    echo <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>System Error — ULMS</title>
<style>*{box-sizing:border-box}body{margin:0;padding:0;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a;background:#f8fafc}.wrap{min-height:100vh;display:flex;flex-direction:column}.hd{background:$navy;color:#fff;padding:16px 24px}.hd-in{max-width:1100px;margin:0 auto;display:flex;align-items:center;gap:12px}.logo{width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,.14);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;color:#fff}.brand{font-weight:700;font-size:16px}.brand small{display:block;font-weight:400;font-size:11px;opacity:.75;letter-spacing:.1em;text-transform:uppercase;margin-top:2px}.mn{flex:1;display:flex;align-items:center;justify-content:center;padding:48px 24px}.card{max-width:640px;width:100%;background:#fff;border:1px solid $border;border-radius:12px;padding:40px 32px;text-align:center}.emo{font-size:44px;line-height:1;margin-bottom:16px}.code{display:inline-block;font-family:ui-monospace,monospace;font-size:12px;letter-spacing:.12em;background:$navy;color:#fff;padding:6px 14px;border-radius:999px;font-weight:700;margin-bottom:16px;text-transform:uppercase}h1{font-size:28px;margin:0 0 12px;line-height:1.2;font-weight:700}p.sub{margin:0 0 24px;color:$muted;font-size:15px;line-height:1.6}.ref{background:#f1f5f9;border:1px solid $border;border-radius:8px;padding:16px;margin:20px 0 28px;display:inline-block;text-align:left}.ref .lbl{font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:$muted;font-weight:600;margin-bottom:6px}.ref .val{font-family:ui-monospace,monospace;font-size:18px;font-weight:700;color:$navydark;letter-spacing:.08em}.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 18px;border-radius:8px;font-weight:600;font-size:14px;text-decoration:none;background:$navy;color:#fff;border:1px solid $navy}.btn:hover{background:$navydark}.ft{padding:24px;text-align:center;color:$muted;font-size:12px;border-top:1px solid $border;background:#fff}</style></head>
<body><div class="wrap"><header class="hd"><div class="hd-in"><div class="logo" aria-hidden="true">BT</div><div class="brand">BELLS TECH UNIVERSITY<small>Learning Management System</small></div></div></header><main class="mn"><section class="card" role="alert" aria-live="assertive"><div class="emo" aria-hidden="true">🛠️</div><div class="code">HTTP 500</div><h1>Something went wrong on our end</h1><p class="sub">$msg</p><div class="ref"><div class="lbl">Error Reference ID</div><div class="val">$errorid</div></div><a class="btn" href="/">Return to dashboard</a></section></main><footer class="ft">© BELLS TECH UNIVERSITY — All rights reserved. ULMS Platform.</footer></div></body></html>
HTML;
    exit(1);
}
register_shutdown_function('ulms_emergency_shutdown_fallback');

unset($CFG);
global $CFG;
$CFG = new stdClass();

///////////////////////////////////////////////////////////////////////////
// ULMS root .env loader (no composer dependency — simple parse)
// Searches: <moodle_root>/../.env first; falls back to <moodle_root>/.env
///////////////////////////////////////////////////////////////////////////

/**
 * Resolves the absolute ULMS .env file path.
 *
 * @return string
 */
function ulms_env_path(): string {
    $candidates = [
        dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env',
        __DIR__ . DIRECTORY_SEPARATOR . '.env',
    ];
    foreach ($candidates as $p) {
        if (is_file($p) && is_readable($p)) {
            return $p;
        }
    }
    return $candidates[0];
}

/**
 * Simple .env parser — populates $_ENV, $_SERVER, and getenv() using
 * putenv for lines matching KEY=VALUE (no export, no nested quotes,
 * comments #, inline comments after whitespace # stripped).
 *
 * @return void
 */
function ulms_load_env(): void {
    $path = ulms_env_path();
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $raw) {
        $line = trim($raw);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false || $eq === 0) {
            continue;
        }
        $key   = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));
        if ($value === '') {
            $value = '';
        } else {
            $first = $value[0];
            $last  = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            } else {
                $hash = strpos($value, ' #');
                if ($hash !== false) {
                    $value = rtrim(substr($value, 0, $hash));
                }
            }
        }
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        @putenv($key . '=' . $value);
    }
}

ulms_load_env();

/**
 * Reads a key from ULMS environment in order: $_ENV -> getenv -> $default.
 *
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function ulms_env(string $key, $default = null) {
    if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }
    $v = getenv($key);
    if (is_string($v) && $v !== '') {
        return $v;
    }
    return $default;
}

///////////////////////////////////////////////////////////////////////////
// 1. DATABASE SETUP — populated from ULMS root .env
///////////////////////////////////////////////////////////////////////////

$CFG->dbtype    = ulms_env('DB_TYPE', 'mysqli');
$CFG->dblibrary = 'native';
$CFG->dbhost    = ulms_env('DB_HOST', '127.0.0.1');
$CFG->dbname    = ulms_env('DB_NAME', 'ulms');
$CFG->dbuser    = ulms_env('DB_USER', 'root');
$CFG->dbpass    = ulms_env('DB_PASSWORD', '');
$CFG->prefix    = 'mdl_';
$CFG->dboptions = [
    'dbpersist'           => false,
    'dbsocket'            => false,
    'dbport'              => (string)ulms_env('DB_PORT', ''),
    'dbhandlesoptions'    => false,
    'dbcollation'         => 'utf8mb4_unicode_ci',
    'dbschema'            => '',
    'dbtransactions'      => null,
    'fetchbuffersize'     => 100000,
    'clientcompress'      => false,
    'connecttimeout'      => null,
    'logall'              => false,
    'logslow'             => 0,
];

///////////////////////////////////////////////////////////////////////////
// 2. WEB SITE LOCATION
///////////////////////////////////////////////////////////////////////////

$CFG->wwwroot   = rtrim((string)ulms_env('APP_URL', 'http://127.0.0.1:8000'), '/');
$appenv_local = in_array(strtolower((string)ulms_env('APP_ENV', 'local')), ['local', 'dev', 'development', 'testing'], true);
if ($appenv_local) {
    if (strpos($CFG->wwwroot, 'https://') === 0) {
        $CFG->wwwroot = preg_replace('#^https://#', 'http://', $CFG->wwwroot);
    }
    $CFG->sslproxy = false;
    $CFG->sslproxy_ssloffload = false;
    unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_X_FORWARDED_SSL'], $_SERVER['HTTP_FRONT_END_HTTPS']);
}
$CFG->dataroot  = (string)ulms_env('MOODLE_DATA_PATH', dirname(__DIR__) . '/moodledata-local');
if ($appenv_local) {
    $candidate_outside = '/tmp/ulms-moodledata-outside';
    if (is_dir($candidate_outside) && is_writable($candidate_outside) && file_exists($candidate_outside . '/.htaccess')) {
        $CFG->dataroot = $candidate_outside;
    }
}
$CFG->directorypermissions = 0750;
$CFG->filepermissions = 0640;

///////////////////////////////////////////////////////////////////////////
// 3. PHP / ERRORS + ULMS LOG FILE
///////////////////////////////////////////////////////////////////////////

$CFG->debug_developer_use_pretty_exceptions = 0;

$debug = (string)ulms_env('APP_DEBUG', '0');
$display = (string)ulms_env('ULMS_WEB_DEBUG_DISPLAY', '0');
$running_via_cli = (defined('CLI_SCRIPT') && CLI_SCRIPT) || PHP_SAPI === 'cli';
if ($running_via_cli) {
    $debug = (string)ulms_env('APP_DEBUG_CLI', (string)ulms_env('ULMS_CLI_DEBUG', '0'));
}
if (in_array(strtolower($debug), ['1', 'true', 'yes', 'on'], true)) {
    @error_reporting(E_ALL);
    $CFG->debug = 38911;
    $CFG->debugdisplay = 1;
} else {
    @error_reporting(0);
    $CFG->debug = 0;
    $CFG->debugdisplay = 0;
}

if (empty($CFG->smtphosts)) {
    $CFG->smtphosts = 'localhost:25';
}

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('html_errors', '0');

$appenv = strtolower((string)ulms_env('APP_ENV', 'local'));
$is_secure_request = (!$appenv_local) && (function_exists('is_https') ? is_https() : ((isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')));
@ini_set('session.cookie_httponly', '1');
@ini_set('session.cookie_samesite', 'Lax');
@ini_set('session.use_only_cookies', '1');
@ini_set('session.use_strict_mode', '1');
if ($is_secure_request || !in_array($appenv, ['local', 'dev', 'development', 'testing'], true)) {
    @ini_set('session.cookie_secure', '1');
    if (!in_array($appenv, ['local', 'dev', 'development'], true)) {
        @ini_set('session.cookie_samesite', 'Strict');
    }
}
if (!isset($CFG->cookie_options) || !is_array($CFG->cookie_options)) {
    $CFG->cookie_options = [];
}
$CFG->cookie_options['httponly'] = true;
$CFG->cookie_options['samesite'] = $is_secure_request ? 'Strict' : 'Lax';
if ($is_secure_request || !in_array($appenv, ['local', 'dev', 'development', 'testing'], true)) {
    $CFG->cookie_options['secure'] = true;
}

if (in_array(strtolower($display), ['1', 'true', 'yes', 'on'], true)) {
    @define('ULMS_SAFE_HANDLER_VISUAL', true);
} else {
    @define('ULMS_SAFE_HANDLER_VISUAL', false);
}
$logfile = (string)ulms_env('ULMS_LOG_FILE', '');
if ($logfile !== '') {
    $logdir = dirname($logfile);
    if (!is_dir($logdir)) {
        @mkdir($logdir, 0777, true);
    }
    @ini_set('error_log', $logfile);
}

///////////////////////////////////////////////////////////////////////////
// 4. RESEND + SMTP (mail transports)
///////////////////////////////////////////////////////////////////////////

$mail_transport = (string)ulms_env('ULMS_MAIL_TRANSPORT', 'mail');
if (strtolower($mail_transport) === 'resend') {
    $CFG->smtphosts = '';
    $CFG->smtpuser = '';
    $CFG->smtppass = '';
    $CFG->smtpsecure = 'tls';
    $CFG->smtpport = '587';
    $CFG->noreplyaddress = (string)ulms_env('RESEND_FROM_EMAIL', 'no-reply@example.com');
    $CFG->supportemail = (string)ulms_env('SMTP_SUPPORT_EMAIL', $CFG->noreplyaddress);
    $CFG->supportname = (string)ulms_env('RESEND_FROM_NAME', (string)ulms_env('SMTP_SUPPORT_NAME', 'ULMS Support'));
    $CFG->emailonlyreplytoname = (string)ulms_env('ULMS_REPLY_TO', '');
} else {
    $CFG->smtphosts = (string)ulms_env('SMTP_HOSTS', '');
    $CFG->smtpuser = (string)ulms_env('SMTP_USER', '');
    $CFG->smtppass = (string)ulms_env('SMTP_PASS', '');
    $CFG->smtpsecure = (string)ulms_env('SMTP_SECURE', 'tls');
    $CFG->smtpport = (string)ulms_env('SMTP_PORT', '587');
    $CFG->noreplyaddress = (string)ulms_env('SMTP_NO_REPLY', '');
    $CFG->supportemail = (string)ulms_env('SMTP_SUPPORT_EMAIL', '');
    $CFG->supportname = (string)ulms_env('SMTP_SUPPORT_NAME', 'ULMS Support');
    $CFG->emailonlyreplytoname = (string)ulms_env('ULMS_REPLY_TO', '');
}

///////////////////////////////////////////////////////////////////////////
// 5. MISC DEFAULTS
///////////////////////////////////////////////////////////////////////////

$CFG->admin = (string)ulms_env('MOODLE_ADMIN', 'admin');
$CFG->preventexecpath = true;
$CFG->curlsecurity = true;
$CFG->themerevcache = 0;
$CFG->lang = 'en';
$CFG->siteidentifier = 'ulms-' . md5(__DIR__ . '|' . $CFG->dbhost . '|' . $CFG->dbname);
$CFG->slasharguments = true;
$CFG->allowthemechangeonurl = false;
$CFG->passwordsaltmain = 'f18bf9b05c517c8198a3ffe21b51f40a89746c30';
$CFG->passwordsaltalt1 = '5ca7cd475ee6969fd6b1d793978be1d7c6ab9a4ae6';
$CFG->passwordpolicy = 1;
$CFG->disableupdatenotifications = true;
$CFG->noemailever = in_array(strtolower((string)ulms_env('APP_ENV', 'local')), ['local', 'dev', 'development', 'testing'], true);
$CFG->cronclionly = false;
$CFG->pathtophp = PHP_BINARY;
$CFG->branch = 405;
$CFG->release = '4.5+ (ULMS)';

///////////////////////////////////////////////////////////////////////////
// 6. MOODLE BOOT
///////////////////////////////////////////////////////////////////////////

define('CFG_DIRROOT_CONST', __DIR__);

require_once(__DIR__ . '/lib/setup.php');

///////////////////////////////////////////////////////////////////////////
// 7. ULMS SAFE ERROR HANDLER INSTALLATION (FINAL LAYER — overrides any moodle
// default handlers. Executes AFTER setup.php so psr-4 autoloader is live.
///////////////////////////////////////////////////////////////////////////

if (!(defined('PHPUNIT_TEST') && PHPUNIT_TEST) && !(defined('BEHAT_TEST') && BEHAT_TEST)) {
    try {
        require_once(__DIR__ . '/local/ulms_auth/classes/local/error/ulms_safe_error_handler.php');
        \local_ulms_auth\local\error\ulms_safe_error_handler::register();
    } catch (\Throwable $e) {
        @error_log('[ULMS-HANDLER-INSTALL-FAIL] ' . $e->getMessage());
    }
}
