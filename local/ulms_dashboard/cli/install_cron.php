<?php
/**
 * ULMS moodle-crontab installer.
 *
 * Idempotently installs / removes / verifies the 1-minute ULMS cron entry
 * for `admin/cli/cron.php` via the safe wrapper run_moodle_cron.sh.
 *
 * Usage:
 *   php local/ulms_dashboard/cli/install_cron.php --install
 *   php local/ulms_dashboard/cli/install_cron.php --uninstall
 *   php local/ulms_dashboard/cli/install_cron.php --status
 */

define('CLI_SCRIPT', 1);
define('ULMS_CRON_MARKER_BEGIN', '# ULMS-moodle-cron BEGIN (managed by install_cron.php — do not edit manually)');
define('ULMS_CRON_MARKER_END',   '# ULMS-moodle-cron END');

$cliDir = __DIR__;
$repoRoot = dirname($cliDir, 3);
$wrapper = $repoRoot . '/local/ulms_dashboard/cli/run_moodle_cron.sh';

function usage(int $exit = 0): void {
    global $argv;
    $name = basename($argv[0]);
    echo "Usage: php {$name} (--install | --uninstall | --status)\n";
    exit($exit);
}

function crontab_available(): bool {
    $out = []; $rc = 0;
    exec('crontab -l 2>/dev/null', $out, $rc);
    // 0 = crontab exists; 1 = "no crontab for this user" (valid state).
    // >=126 = permission denied / no binary / sandbox restriction.
    return in_array($rc, [0, 1], true);
}

function read_crontab(): string {
    if (!crontab_available()) {
        // Sandbox / restricted shell with no crontab binary — treat as empty.
        return '';
    }
    $lines = [];
    $rc = 0;
    exec('crontab -l 2>/dev/null', $lines, $rc);
    // exit 1 from `crontab -l` means "no crontab for this user" — treat as empty.
    if ($rc !== 0 && $rc !== 1) {
        throw new RuntimeException("crontab -l failed (rc={$rc}). Is crontab installed?");
    }
    return implode("\n", $lines) . ($lines ? "\n" : '');
}

function write_crontab(string $content): void {
    if (!crontab_available()) {
        throw new RuntimeException("crontab binary not found on PATH. Install cronie/cron and try again.");
    }
    $tmp = tempnam(sys_get_temp_dir(), 'ulms-cron-');
    if ($tmp === false) throw new RuntimeException('tempnam() failed');
    file_put_contents($tmp, $content);
    $cmd = 'crontab ' . escapeshellarg($tmp) . ' 2>&1';
    $out = []; $rc = 0;
    exec($cmd, $out, $rc);
    @unlink($tmp);
    if ($rc !== 0) {
        throw new RuntimeException("crontab install failed (rc={$rc}): " . implode("\n", $out));
    }
}

function build_managed_block(string $wrapperPath): string {
    $shell = '/bin/bash';
    return ULMS_CRON_MARKER_BEGIN . "\n"
        . "* * * * * {$shell} " . escapeshellarg($wrapperPath) . "\n"
        . ULMS_CRON_MARKER_END . "\n";
}

function strip_managed_block(string $crontab): string {
    $pattern = '/' . preg_quote(ULMS_CRON_MARKER_BEGIN, '/') . '\R'
        . '.*?'
        . preg_quote(ULMS_CRON_MARKER_END, '/') . '\R?/s';
    return preg_replace($pattern, '', $crontab) ?? '';
}

function has_managed_block(string $crontab): bool {
    return str_contains($crontab, ULMS_CRON_MARKER_BEGIN)
        && str_contains($crontab, ULMS_CRON_MARKER_END);
}

$action = $argv[1] ?? '';
if (!in_array($action, ['--install', '--uninstall', '--status', '-h', '--help'], true)) usage(1);
if ($action === '-h' || $action === '--help') usage(0);

if ($action === '--status') {
    $current = read_crontab();
    $installed = has_managed_block($current);
    $wrapperOk = is_file($wrapper) && is_executable($wrapper);
    echo json_encode([
        'wrapper_path'      => $wrapper,
        'wrapper_exists'    => $wrapperOk,
        'cron_available'    => crontab_available(),
        'cron_installed'    => $installed,
        'install_command'   => 'php local/ulms_dashboard/cli/install_cron.php --install',
        'uninstall_command' => 'php local/ulms_dashboard/cli/install_cron.php --uninstall',
        'daily_log_dir'     => $repoRoot . '/var/log/cron',
        'lock_file'         => $repoRoot . '/var/run/moodle-cron.lock',
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

if ($action === '--install') {
    if (!is_file($wrapper)) {
        fwrite(STDERR, "ERROR: wrapper missing at {$wrapper}.\n"); exit(2);
    }
    if (!is_executable($wrapper)) {
        chmod($wrapper, 0755);
    }
    $current = read_crontab();
    $stripped = rtrim(strip_managed_block($current), "\n") . "\n";
    $next = $stripped . "\n" . build_managed_block($wrapper);
    write_crontab($next);
    echo "INSTALLED. Verify with: php " . escapeshellarg($argv[0]) . " --status\n";
    exit(0);
}

// --uninstall
$current = read_crontab();
if (!has_managed_block($current)) {
    echo "NOT INSTALLED — no managed block found, nothing to remove.\n";
    exit(0);
}
$next = strip_managed_block($current);
write_crontab($next);
echo "UNINSTALLED.\n";
