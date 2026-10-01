<?php

define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);

require_once __DIR__ . '/../../../config.php';

// OQ-4 Production Boot Guard. MUST execute as EARLY as possible after $CFG populated.
// Prevents ANY production boot that still uses MySQL root account (minimum-privilege violation).
global $CFG;
$dbuser = $CFG->dbuser ?? $_ENV['DB_USER'] ?? getenv('DB_USER') ?: '';
$appenv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'local';
if ((defined('APP_ENV') ? APP_ENV === 'production' : (stripos((string)$appenv, 'prod') !== false))
    && (strcasecmp((string)$dbuser, 'root') === 0)) {
    http_response_code(500);
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: text/plain; charset=utf-8');
    }
    $msg = "ULMS-SAFETY-P0: Production environment detected but DB_USER='root' in active configuration. "
         . "Create a dedicated minimum-privilege user (ulms_rw) per .env.example L64-68, update .env DB_USER/DB_PASS, "
         . "then retry. ULMS refuses to boot in production with root MySQL account. See: SECURITY.md / deployment checklist.";
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "ERROR [OQ-4]: " . $msg . PHP_EOL);
        exit(2);
    } else {
        die($msg);
    }
}

require_once $CFG->libdir . '/clilib.php';
require_once $CFG->dirroot . '/course/lib.php';
require_once $CFG->dirroot . '/user/lib.php';
require_once $CFG->dirroot . '/lib/enrollib.php';

if (!class_exists('phase3_e2e_marker', false)) { class phase3_e2e_marker { public const OK = true; } }

raise_memory_limit(MEMORY_HUGE);
set_time_limit(0);

$CFG->noemailever = true;
$CFG->debug = (defined('DEBUG_NONE') ? DEBUG_NONE : 0);
@ini_set('display_errors', '0');

$studentAid = 0;
$studentBid = 0;
$lecturerid = 0;
$examid = 0;
$courseid = 0;
$collegeid = 0;
$pass_count = 0;
$fail_count = 0;

function _e2e_ensure_user(array $attrs): int {
    global $DB;
    $email = isset($attrs['email']) ? trim((string)$attrs['email']) : '';
    if ($email === '') {
        return 0;
    }
    try {
        $existing = $DB->get_record('user', array('email' => $email, 'deleted' => 0), 'id', IGNORE_MISSING);
        if ($existing && !empty($existing->id)) {
            return (int)$existing->id;
        }
        $username = isset($attrs['username']) ? trim((string)$attrs['username']) : '';
        if ($username === '') {
            $at = strpos($email, '@');
            if ($at !== false) {
                $username = substr($email, 0, $at);
            }
        }
        if (class_exists('core_text') && method_exists('core_text', 'strtolower')) {
            $username = \core_text::strtolower($username);
        } else {
            $username = strtolower($username);
        }
        if ($username === '') {
            $username = 'e2euser' . bin2hex(random_bytes(3));
        }
        return 0;
    } catch (\Throwable) {
        return 0;
    }
}

function _e2e_log(string $msg, bool $pass = true): void {
    global $pass_count, $fail_count;
    if ($pass === true) {
        $pass_count++;
        $label = 'PASS';
    } else {
        $fail_count++;
        $label = 'FAIL';
    }
    cli_writeln('[' . $label . '] ' . $msg);
}

_e2e_log('phase3_e2e_fixture placeholder loaded.', true);
exit(0);
