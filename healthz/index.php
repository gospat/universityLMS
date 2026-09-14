<?php
///////////////////////////////////////////////////////////////////////////
// ULMS /healthz — Kubernetes liveness probe.
//
// Liveness = "is the PHP process still basically alive?"
// This is the LIGHTEST possible probe (<1ms). It avoids:
//   - No DB call (DB not required to prove PHP alive)
//   - No file_exists checks (filesystem check != process alive)
//   - No env parsing (not needed for liveness)
// If kubelet sees 3 consecutive liveness failures → pod is RESTARTED.
//
// Response: HTTP 200 JSON {"status":"ok","time":"...","ref":"<8hex>","uptime_boot_id":"<16hex>"}
///////////////////////////////////////////////////////////////////////////

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('html_errors', '0');
@error_reporting(0);

$ulmsRef = substr(bin2hex(random_bytes(5)), 0, 8);
$ulmsBoot = substr(bin2hex(random_bytes(8)), 0, 16);

@http_response_code(200);
@header_remove('Content-Type');
@header('Content-Type: application/json; charset=utf-8');
@header('X-Content-Type-Options: nosniff');
@header('X-Robots-Tag: noindex, nofollow');
@header('X-Frame-Options: DENY');
@header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
@header('Pragma: no-cache');
@header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

echo json_encode([
    'status' => 'ok',
    'time'   => gmdate('c'),
    'ref'    => $ulmsRef,
    'uptime_boot_id' => $ulmsBoot,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit(0);
