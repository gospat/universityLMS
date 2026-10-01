<?php
/**
 * ULMS Health Check Endpoint.
 * Returns JSON {status: "pass|fail", checks: [{name, status, time_ms}, ...], total_ms: X}
 * Used by Nginx upstream health checks and load balancer rolling-deploy probes.
 * No auth required. No side effects. ALL checks READ-ONLY.
 */
define('ULMS_PUBLIC_ROUTE_REQUEST', 1);
require_once __DIR__ . '/config.php';
global $DB, $CFG;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$t0 = microtime(true);
$checks = [];
$overall = 'pass';

// Check 1: DB connectivity
$ts = microtime(true);
try {
    $DB->get_record_sql('SELECT 1 AS ok');
    $checks[] = ['name' => 'db', 'status' => 'pass', 'time_ms' => (int)round((microtime(true)-$ts)*1000)];
} catch (\Throwable $e) {
    $checks[] = ['name' => 'db', 'status' => 'fail', 'time_ms' => (int)round((microtime(true)-$ts)*1000), 'error' => $e->getMessage()];
    $overall = 'fail';
}

// Check 2: Dataroot writable
$ts = microtime(true);
$writable = is_writable($CFG->dataroot);
$checks[] = ['name' => 'dataroot_writable', 'status' => $writable ? 'pass' : 'fail', 'time_ms' => (int)round((microtime(true)-$ts)*1000)];
if (!$writable) $overall = 'fail';

// Check 3: Cache pool reachable (MUC default)
$ts = microtime(true);
try {
    $cache = \cache::make('core', 'string');
    $k = 'ulms_health_probe_' . bin2hex(random_bytes(4));
    $cache->set($k, '1', 2);
    $got = $cache->get($k);
    $cache->delete($k);
    $checks[] = ['name' => 'cache', 'status' => ($got === '1') ? 'pass' : 'fail', 'time_ms' => (int)round((microtime(true)-$ts)*1000)];
    if ($got !== '1') $overall = 'fail';
} catch (\Throwable $e) {
    $checks[] = ['name' => 'cache', 'status' => 'fail', 'time_ms' => (int)round((microtime(true)-$ts)*1000), 'error' => $e->getMessage()];
    $overall = 'fail';
}

http_response_code($overall === 'pass' ? 200 : 503);
echo json_encode([
    'status' => $overall,
    'service' => 'ulms-lms',
    'version' => (string)($CFG->release ?? '4.5.12+'),
    'checks' => $checks,
    'total_ms' => (int)round((microtime(true) - $t0) * 1000),
    'time' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit(0);
