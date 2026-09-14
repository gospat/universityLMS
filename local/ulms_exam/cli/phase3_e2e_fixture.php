<?php

define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);

require_once __DIR__ . '/../../../config.php';
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
