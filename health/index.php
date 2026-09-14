<?php
///////////////////////////////////////////////////////////////////////////
// ULMS /health JSON probe endpoint.
//
// Design constraints:
//   - MUST NOT boot full Moodle (no require lib/setup.php): health probes run
//     every 5-30s; we avoid session init / MUC cache / handler double-
//     registration and keep response <10ms on cold.
//   - Silent by design: never echo HTML, never throw, never write user data.
//   - HTTP 200 = ok all checks pass; HTTP 503 = at least one check degraded
//     (Kubernetes / UptimeRobot / load balancers use status code, not body).
//   - Reference ID uses the same 8-hex ULMS error-handler format for log correlation.
//   - No cookies, no sessions, no response body ever contains any secret.
///////////////////////////////////////////////////////////////////////////

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('html_errors', '0');
@error_reporting(0);

$ulmsCheckEnv = [];
$ulmsEnvCandidates = [
    dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env',
];
$ulmsEnvPath = '';
foreach ($ulmsEnvCandidates as $ulmsP) {
    if (is_file($ulmsP) && is_readable($ulmsP)) {
        $ulmsEnvPath = $ulmsP;
        break;
    }
}
if ($ulmsEnvPath !== '') {
    $ulmsLines = @file($ulmsEnvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($ulmsLines)) {
        foreach ($ulmsLines as $ulmsLine) {
            $ulmsLine = trim($ulmsLine);
            if ($ulmsLine === '' || $ulmsLine[0] === '#') {
                continue;
            }
            $ulmsEq = strpos($ulmsLine, '=');
            if ($ulmsEq === false) {
                continue;
            }
            $ulmsK = trim(substr($ulmsLine, 0, $ulmsEq));
            $ulmsV = trim(substr($ulmsLine, $ulmsEq + 1));
            if ($ulmsK === '' || strpos($ulmsK, ' ') !== false || strpos($ulmsK, "\t") !== false) {
                continue;
            }
            if (strlen($ulmsV) >= 2 && $ulmsV[0] === '"' && $ulmsV[strlen($ulmsV) - 1] === '"') {
                $ulmsV = substr($ulmsV, 1, -1);
            } elseif (strlen($ulmsV) >= 2 && $ulmsV[0] === "'" && $ulmsV[strlen($ulmsV) - 1] === "'") {
                $ulmsV = substr($ulmsV, 1, -1);
            }
            $ulmsHash = strpos($ulmsV, ' #');
            if ($ulmsHash !== false) {
                $ulmsV = rtrim(substr($ulmsV, 0, $ulmsHash));
            }
            $ulmsCheckEnv[$ulmsK] = $ulmsV;
            if (!array_key_exists($ulmsK, $_ENV)) {
                $_ENV[$ulmsK] = $ulmsV;
            }
        }
    }
}
unset($ulmsLines, $ulmsLine, $ulmsEq, $ulmsK, $ulmsV, $ulmsHash, $ulmsP, $ulmsEnvCandidates, $ulmsEnvPath);

$ulmsRef = substr(bin2hex(random_bytes(5)), 0, 8);

$ulmsChecks = [
    'db'      => false,
    'cache'   => false,
    'log'     => false,
    'handler' => false,
];

$ulmsDbHost = $ulmsCheckEnv['DB_HOST'] ?? '127.0.0.1';
$ulmsDbPort = (int)($ulmsCheckEnv['DB_PORT'] ?? $ulmsCheckEnv['DB_HOST_PORT'] ?? 3306);
$ulmsDbName = $ulmsCheckEnv['DB_NAME'] ?? '';
$ulmsDbUser = $ulmsCheckEnv['DB_USER'] ?? '';
$ulmsDbPass = $ulmsCheckEnv['DB_PASSWORD'] ?? '';
if ($ulmsDbHost !== '' && $ulmsDbName !== '' && $ulmsDbUser !== '' && function_exists('mysqli_init')) {
    $ulmsM = @mysqli_init();
    if ($ulmsM !== null) {
        $ulmsConnected = @mysqli_real_connect($ulmsM, $ulmsDbHost, $ulmsDbUser, $ulmsDbPass, $ulmsDbName, $ulmsDbPort, null, MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT);
        if ($ulmsConnected) {
            $ulmsChecks['db'] = @mysqli_ping($ulmsM);
            @mysqli_close($ulmsM);
        }
        unset($ulmsConnected);
    }
    unset($ulmsM);
}
unset($ulmsDbHost, $ulmsDbPort, $ulmsDbName, $ulmsDbUser, $ulmsDbPass);

$ulmsDataRoot = rtrim($ulmsCheckEnv['MOODLE_DATA_PATH'] ?? (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'moodledata-local'), '/\\');
$ulmsCacheDir = $ulmsDataRoot . DIRECTORY_SEPARATOR . 'cache';
if (is_dir($ulmsCacheDir) && is_writable($ulmsCacheDir)) {
    $ulmsChecks['cache'] = true;
}
unset($ulmsCacheDir);

$ulmsLogPath = $ulmsCheckEnv['ULMS_LOG_FILE'] ?? '';
if ($ulmsLogPath === '') {
    $ulmsLogPath = $ulmsDataRoot . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'php-error.log';
}
$ulmsLogDir = dirname($ulmsLogPath);
if ((is_file($ulmsLogPath) && is_writable($ulmsLogPath)) || (is_dir($ulmsLogDir) && is_writable($ulmsLogDir))) {
    $ulmsChecks['log'] = true;
}
unset($ulmsLogPath, $ulmsLogDir, $ulmsDataRoot);

$ulmsHandlerPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'local' . DIRECTORY_SEPARATOR . 'ulms_auth' . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'local' . DIRECTORY_SEPARATOR . 'error' . DIRECTORY_SEPARATOR . 'ulms_safe_error_handler.php';
if (is_file($ulmsHandlerPath) && is_readable($ulmsHandlerPath)) {
    $ulmsChecks['handler'] = true;
}
unset($ulmsHandlerPath);

$ulmsAllOk = !in_array(false, $ulmsChecks, true);
@http_response_code($ulmsAllOk ? 200 : 503);
@header_remove('Content-Type');
@header('Content-Type: application/json; charset=utf-8');
@header('X-Content-Type-Options: nosniff');
@header('X-Robots-Tag: noindex, nofollow');
@header('X-Frame-Options: DENY');
@header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
@header('Pragma: no-cache');
@header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

echo json_encode([
    'status' => $ulmsAllOk ? 'ok' : 'degraded',
    'time'   => gmdate('c'),
    'checks' => $ulmsChecks,
    'ref'    => $ulmsRef,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit(0);
