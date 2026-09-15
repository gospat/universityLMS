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

require_once(__DIR__ . '/../../config.php');

if (!(defined('PHPUNIT_TEST') && PHPUNIT_TEST) && !(defined('BEHAT_TEST') && BEHAT_TEST)) {
    try {
        require_once(__DIR__ . '/classes/local/error/ulms_safe_error_handler.php');
        \local_ulms_auth\local\error\ulms_safe_error_handler::register();
    } catch (\Throwable $e) {
        @error_log('[ULMS-HANDLER-ROUTE-INSTALL-FAIL] ' . $e->getMessage());
    }
}

if (method_exists(\theme_ulms_university\output\core_renderer::class, 'inject_http_security_headers')) {
    \theme_ulms_university\output\core_renderer::inject_http_security_headers();
}

/**
 * Boots a clean public ULMS route and hands off to the existing controller.
 *
 * @param string $relativefile Path from $CFG->dirroot to the existing controller.
 * @param array<string, mixed> $params Fixed query parameters for the controller.
 * @return void
 */
function local_ulms_auth_boot_public_route(string $relativefile, array $params = []): void {
    global $CFG, $SESSION;

    if (!defined('ULMS_PUBLIC_ROUTE_REQUEST')) {
        define('ULMS_PUBLIC_ROUTE_REQUEST', true);
    }

    // ULMS defensive guard: some inner controllers (e.g. local/ulms_exam/*) also
    // require_once(config.php) inside the require() below. Even though
    // require_once prevents duplicate file loading, the nested re-entry through
    // the global scope plus the PHP session alias dance in
    // core\session\manager::start() can desync $GLOBALS['SESSION'] from
    // $_SESSION['SESSION'], leaving one of them as a string on stale or partial
    // session payloads. Re-bind to the canonical $_SESSION['SESSION'] stdClass
    // (instantiate if missing) BEFORE any further code reads or writes $SESSION.
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
        if (!isset($_SESSION['SESSION']) || !is_object($_SESSION['SESSION']) || !($_SESSION['SESSION'] instanceof stdClass)) {
            $_SESSION['SESSION'] = new stdClass();
        }
        $GLOBALS['SESSION'] = $_SESSION['SESSION'];
        $SESSION = $_SESSION['SESSION'];
    } elseif (!isset($SESSION) || !is_object($SESSION) || !($SESSION instanceof stdClass)) {
        $SESSION = new stdClass();
        $GLOBALS['SESSION'] = $SESSION;
    }

    foreach ($params as $key => $value) {
        $_GET[$key] = $value;
        $_REQUEST[$key] = $value;
    }

    $publicpath = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (is_string($publicpath) && $publicpath !== '') {
        $_SERVER['SCRIPT_NAME'] = $publicpath;
        $_SERVER['PHP_SELF'] = $publicpath;
    }

    $isauthentry = str_starts_with($relativefile, '/local/ulms_auth/');
    if (!$isauthentry && (!isloggedin() || isguestuser())) {
        $landingservice = new \local_ulms_auth\local\service\landing_page_service();
        if (!isset($SESSION) || !is_object($SESSION)) {
            $SESSION = new \stdClass();
        }
        $SESSION->wantsurl = new \moodle_url($publicpath !== '' ? $publicpath : '/');
        redirect($landingservice->get_public_portal_landing_url());
    }

    $controller = $CFG->dirroot . $relativefile;
    if (!is_file($controller) || !is_readable($controller)) {
        if (!(defined('PHPUNIT_TEST') && PHPUNIT_TEST) && !(defined('BEHAT_TEST') && BEHAT_TEST) && PHP_SAPI !== 'cli') {
            try {
                require_once(__DIR__ . '/classes/local/error/ulms_safe_error_handler.php');
                \local_ulms_auth\local\error\ulms_safe_error_handler::emit_http_response(
                    404,
                    'Missing controller file: ' . $relativefile . ' requested from ' . ($_SERVER['REQUEST_URI'] ?? '?')
                );
            } catch (\Throwable $e) {
                @http_response_code(404);
                @header('Content-Type: text/html; charset=utf-8');
                echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>404 Not Found — ULMS</title><style>body{margin:0;background:#f8fafc;font-family:system-ui}.c{max-width:520px;margin:10vh auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:32px;text-align:center}.h{background:#0f4c81;color:#fff;padding:14px 20px;margin:-32px -32px 24px;border-radius:12px 12px 0 0;font-weight:700}h1{margin:0 0 8px;font-size:22px;color:#0f172a}p{color:#64748b;margin:0 0 16px;font-size:14px}.b{display:inline-block;padding:10px 16px;background:#0f4c81;color:#fff;border-radius:8px;font-weight:600;text-decoration:none;font-size:14px}</style></head><body><div class="c"><div class="h">BELLS TECH UNIVERSITY</div><h1>Page not found</h1><p>The page you requested is not available. Return to the dashboard or try again later.</p><a class="b" href="/">Return to dashboard</a></div></body></html>';
                exit(1);
            }
        }
        return;
    }

    require($controller);
}
