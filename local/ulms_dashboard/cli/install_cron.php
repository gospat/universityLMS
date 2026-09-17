<?php
/**
 * ULMS moodle-crontab installer.
 *
 * Idempotently installs / removes / verifies the 1-minute ULMS cron entry
 * for `admin/cli/cron.php` via the safe wrapper run_moodle_cron.sh.
 *
 * CLI-only script — will exit with HTTP 403 if invoked via web SAPI
 * or without CLI_SCRIPT defined.
 *
 * Usage:
 *   php local/ulms_dashboard/cli/install_cron.php --install
 *   php local/ulms_dashboard/cli/install_cron.php --uninstall
 *   php local/ulms_dashboard/cli/install_cron.php --status
 *   php local/ulms_dashboard/cli/install_cron.php --help
 */

define('CLI_SCRIPT', 1);
define('ULMS_CRON_MARKER_BEGIN', '# ULMS-moodle-cron BEGIN (managed by install_cron.php — do not edit manually)');
define('ULMS_CRON_MARKER_END',   '# ULMS-moodle-cron END');

$ulmsInstallerPaths = [
    __DIR__,
    dirname(__DIR__, 3),
    sys_get_temp_dir(),
];

// ==== Production guard 1: CLI-only invocation (deny any web/fpm access) ====
(function (array $allowedPaths): void {
    $sapi = PHP_SAPI;
    $isCli = $sapi === 'cli' || $sapi === 'phpdbg';
    $scriptFlag = defined('CLI_SCRIPT') && CLI_SCRIPT;
    if (!$isCli || !$scriptFlag) {
        if (!headers_sent()) http_response_code(403);
        if ($isCli) {
            fwrite(STDERR, "install_cron.php: CLI_SCRIPT must be defined. Refusing to run.\n");
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo "403 Forbidden: install_cron.php is a CLI tool only.\n";
        }
        exit(1);
    }
    // Harden: tighten open_basedir IF no master value already restricts us.
    $masterValue = (string)ini_get('open_basedir');
    if ($masterValue === '' || $masterValue === '0') {
        @ini_set('open_basedir', implode(PATH_SEPARATOR, $allowedPaths));
    }
    // No execution timeout for shell / pipe operations.
    @ini_set('max_execution_time', '0');
    if (((int)ini_get('memory_limit')) < 128) {
        @ini_set('memory_limit', '128M');
    }
    // No display of errors to STDOUT during cron ops; only log.
    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');
})($ulmsInstallerPaths);

$cliDir      = __DIR__;
$repoRoot    = dirname($cliDir, 3);
$wrapper     = $repoRoot . '/local/ulms_dashboard/cli/run_moodle_cron.sh';
$phpBinary   = getenv('ULMS_PHP_BINARY') ?: (PHP_BINARY ?: 'php');

function usage(int $exit = 0): void {
    global $argv, $wrapper, $phpBinary;
    $name = basename($argv[0]);
    $out  =<<<HELP
ULMS Moodle Cron Installer
==========================

Manages a single-flight cron entry that runs Moodle's admin/cli/cron.php every
minute via the safe wrapper run_moodle_cron.sh. All changes are idempotent:
the installer only edits lines inside its BEGIN/END marker block.

Usage:
  php {$name} --install     Install or refresh the managed cron entry.
  php {$name} --uninstall   Remove ONLY the managed cron entry.
  php {$name} --status      Print JSON audit (wrapper, installed, logs, lock).
  php {$name} --help        Show this help.

Environment variables (optional):
  ULMS_PHP_BINARY           Absolute path to PHP used when exec()-ing cron.
                            Current: {$phpBinary}

Managed wrapper path:
  {$wrapper}

Wrapper guarantees:
  * flock() single-flight lock — never piles up overlapping runs.
  * CLI_SCRIPT=1 + memory_limit=512M for Moodle's admin/cli/cron.php.
  * Daily rotated logs under \$REPO_ROOT/var/log/cron (14-day retention).
  * STDERR + STDOUT merged — inspect with: tail -f var/log/cron/moodle-cron-\$(date +%F).log

HELP;
    fwrite(STDOUT, $out);
    exit($exit);
}

function crontab_available(): bool {
    // Testing hook: if ULMS_CRON_STUB_FILE is set, we simulate a crontab binary
    // via that file (for CI / restricted sandboxes with no real crontab).
    if (is_string(getenv('ULMS_CRON_STUB_FILE')) && getenv('ULMS_CRON_STUB_FILE') !== '') {
        return true;
    }
    $out = []; $rc = 0;
    // exit 0 = crontab populated; exit 1 = "no crontab for this user" (valid state)
    @exec('crontab -l 2>/dev/null', $out, $rc);
    return in_array($rc, [0, 1], true);
}

function read_crontab(): string {
    $stub = (string)getenv('ULMS_CRON_STUB_FILE');
    if ($stub !== '') {
        if (!is_file($stub)) return '';
        $c = (string)@file_get_contents($stub);
        return $c === '' ? '' : rtrim($c, "\n") . "\n";
    }
    if (!crontab_available()) return '';
    $lines = []; $rc = 0;
    @exec('crontab -l 2>/dev/null', $lines, $rc);
    if ($rc !== 0 && $rc !== 1) {
        throw new RuntimeException("crontab -l failed (rc={$rc}). Install cronie and try again.");
    }
    return implode("\n", $lines) . ($lines ? "\n" : '');
}

function write_crontab(string $content): void {
    $stub = (string)getenv('ULMS_CRON_STUB_FILE');
    if ($stub !== '') {
        $dir = dirname($stub);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (file_put_contents($stub, $content) === false) {
            throw new RuntimeException("failed writing stub crontab at {$stub}");
        }
        return;
    }
    if (!crontab_available()) {
        throw new RuntimeException("crontab binary not available on PATH. Install cronie/cron.");
    }
    $tmp = tempnam(sys_get_temp_dir(), 'ulms-cron-');
    if ($tmp === false) throw new RuntimeException('tempnam() failed');
    if (file_put_contents($tmp, $content) === false) {
        @unlink($tmp);
        throw new RuntimeException('tmpfile write failed');
    }
    $cmd = 'crontab ' . escapeshellarg($tmp) . ' 2>&1';
    $out = []; $rc = 0;
    @exec($cmd, $out, $rc);
    @unlink($tmp);
    if ($rc !== 0) {
        throw new RuntimeException("crontab install failed (rc={$rc}): " . implode("\n", $out));
    }
}

function build_managed_block(string $wrapperPath, string $phpBinary): string {
    if (!is_file($wrapperPath)) {
        throw new RuntimeException("Wrapper file not found at {$wrapperPath}");
    }
    if (!is_executable($wrapperPath)) {
        @chmod($wrapperPath, 0755);
        clearstatcache(true, $wrapperPath);
        if (!is_executable($wrapperPath)) {
            throw new RuntimeException("Wrapper is not executable after chmod 0755: {$wrapperPath}");
        }
    }
    // Signature sanity — refuse to install if wrapper starts with suspicious bytes
    $head = (string)@file_get_contents($wrapperPath, false, null, 0, 2);
    if ($head !== '#!') {
        throw new RuntimeException("Wrapper does not look like a shell script (missing #! shebang): {$wrapperPath}");
    }
    $shell = '/bin/bash';
    return ULMS_CRON_MARKER_BEGIN . "\n"
        . '* * * * * ' . escapeshellarg($shell) . ' ' . escapeshellarg($wrapperPath)
        . ' # PHP_BIN=' . $phpBinary . "\n"
        . ULMS_CRON_MARKER_END . "\n";
}

function strip_managed_block(string $crontab): string {
    // Deeply anchored — ONLY exact BEGIN line → content on its own lines → exact END line.
    // Uses A...\z anchors + multiline not-greedy so stray "similar" markers can't wipe user content.
    $pattern = '/(?:^|\R)\K' . preg_quote(ULMS_CRON_MARKER_BEGIN, '/') . '\R'
        . '.*?'
        . preg_quote(ULMS_CRON_MARKER_END, '/') . '\R?/s';
    $out = preg_replace($pattern, '', $crontab);
    if (!is_string($out)) return $crontab;
    return $out;
}

function has_managed_block(string $crontab): bool {
    return str_contains($crontab, ULMS_CRON_MARKER_BEGIN)
        && str_contains($crontab, ULMS_CRON_MARKER_END);
}

$action = $argv[1] ?? '';
if ($action === '' && ($argv[0] ?? '') !== '') $action = '--help';
$allowed = ['--install', '--uninstall', '--status', '-h', '--help'];
if (!in_array($action, $allowed, true)) {
    fwrite(STDERR, "Unknown action: {$action}\n\n");
    usage(1);
}
if ($action === '-h' || $action === '--help') usage(0);

if ($action === '--status') {
    $current    = read_crontab();
    $installed  = has_managed_block($current);
    $wrapperOk  = is_file($wrapper) && is_executable($wrapper);
    $logDir     = $repoRoot . '/var/log/cron';
    $lockFile   = $repoRoot . '/var/run/moodle-cron.lock';
    $lastLog    = '';
    if (is_dir($logDir)) {
        $logs = glob($logDir . '/moodle-cron-*.log');
        if (is_array($logs) && $logs !== []) {
            rsort($logs);
            $lastLog = $logs[0];
        }
    }
    echo json_encode([
        'wrapper_path'      => $wrapper,
        'wrapper_exists'    => $wrapperOk,
        'wrapper_shebang_ok'=> $wrapperOk && (@file_get_contents($wrapper, false, null, 0, 2) === '#!'),
        'php_binary'        => $phpBinary,
        'cron_available'    => crontab_available(),
        'cron_installed'    => $installed,
        'install_command'   => 'php local/ulms_dashboard/cli/install_cron.php --install',
        'uninstall_command' => 'php local/ulms_dashboard/cli/install_cron.php --uninstall',
        'daily_log_dir'     => $logDir,
        'last_log_file'     => $lastLog,
        'lock_file'         => $lockFile,
        'lock_held'         => is_file($lockFile),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

if ($action === '--install') {
    $current  = read_crontab();
    $stripped = rtrim(strip_managed_block($current), "\n") . "\n";
    $block    = build_managed_block($wrapper, $phpBinary);
    $next     = $stripped . "\n" . $block;
    write_crontab($next);
    fwrite(STDOUT, "INSTALLED managed cron block.\nVerify with: php local/ulms_dashboard/cli/install_cron.php --status\n");
    exit(0);
}

// --uninstall
$current = read_crontab();
if (!has_managed_block($current)) {
    fwrite(STDOUT, "NOT INSTALLED — managed block not present; nothing to remove.\n");
    exit(0);
}
write_crontab(strip_managed_block($current));
fwrite(STDOUT, "UNINSTALLED managed cron block.\nRemaining crontab untouched.\n");
