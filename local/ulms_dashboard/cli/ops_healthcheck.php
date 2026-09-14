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

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/cronlib.php');

global $DB;

$rawopts = getopt('', ['max-cron-age-minutes:', 'max-backup-age-hours:']);
$maxcronminutes = isset($rawopts['max-cron-age-minutes']) ? (int)$rawopts['max-cron-age-minutes'] : 180;
$maxbackuphours = isset($rawopts['max-backup-age-hours']) ? (int)$rawopts['max-backup-age-hours'] : 48;

$now = time();

$checks = [];
$record = static function(string $name, bool $ok, array $detail = []) use (&$checks, $now): void {
    $checks[$name] = [
        'ok' => $ok,
        'checked_at' => $now,
    ] + $detail;
};

try {
    $dbh = $DB->get_record_sql('SELECT 1 AS alive');
    $record('db.connectivity', (bool)$dbh);
} catch (\Throwable $exception) {
    if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'ops_healthcheck::ping_db', ['ctx' => basename(__FILE__)]); }
    $record('db.connectivity', false, ['error' => $exception->getMessage()]);
}

try {
    $syscontext = context_system::instance();
    $record('db.context.system', $syscontext instanceof \context_system && $syscontext->id > 0, ['id' => $syscontext->id ?? null]);
} catch (\Throwable $exception) {
    if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'ops_healthcheck::ping_mail', ['ctx' => basename(__FILE__)]); }
    $record('db.context.system', false, ['error' => $exception->getMessage()]);
}

$datarootwritable = is_dir($CFG->dataroot ?? '') && is_writable($CFG->dataroot ?? '');
$record('fs.dataroot.writable', $datarootwritable, [
    'path' => $CFG->dataroot ?? '[unset]',
]);

if (is_dir($CFG->dataroot ?? '')) {
    $diskfreekb = @disk_free_space($CFG->dataroot ?? '') ?: 0;
    $diskfreekb = $diskfreekb > 0 ? (int)floor($diskfreekb / 1024) : 0;
    $record('fs.dataroot.diskspace', $diskfreekb >= 100 * 1024, [
        'free_kb' => $diskfreekb,
        'threshold_kb' => 100 * 1024,
    ]);
}

$cronlast = (int)($CFG->lastcron ?? 0);
$cronelapsed = max(0, $now - $cronlast);
$record('scheduler.cron.fresh', ($cronlast === 0 || $cronelapsed <= max(60, $maxcronminutes) * 60), [
    'last_ts' => $cronlast,
    'elapsed_seconds' => $cronelapsed,
    'threshold_seconds' => $maxcronminutes * 60,
]);

$taskruntime = 0;
try {
    $lasttask = $DB->get_record_sql(
        "SELECT MAX(timestarted) AS laststart FROM {task_log} WHERE result = :ok",
        ['ok' => 1]
    );
    $taskruntime = (int)($lasttask->laststart ?? 0);
} catch (\Throwable $exception) {
    if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'ops_healthcheck::ping_fs', ['ctx' => basename(__FILE__)]); }
    $taskruntime = 0;
}
$taskelapsed = max(0, $now - $taskruntime);
$record('scheduler.tasks.lastok', $taskruntime === 0 || $taskelapsed <= 24 * 3600, [
    'last_ok_ts' => $taskruntime,
    'elapsed_seconds' => $taskelapsed,
]);

$backupage = null;
try {
    $lastbackup = $DB->get_record_sql(
        "SELECT MAX(timecreated) AS ts FROM {backup_controllers} WHERE status = :ok AND type = 'course'",
        ['ok' => 100]
    );
    $backupage = (int)($lastbackup->ts ?? 0);
} catch (\Throwable $exception) {
    if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'ops_healthcheck::ping_cron', ['ctx' => basename(__FILE__)]); }
    $backupage = 0;
}
$backupelapsed = max(0, $now - $backupage);
$record('backup.course.fresh', $backupage === 0 || $backupelapsed <= max(1, $maxbackuphours) * 3600, [
    'last_ts' => $backupage,
    'elapsed_seconds' => $backupelapsed,
    'threshold_seconds' => $maxbackuphours * 3600,
]);

$alerts = 0;
$warnings = 0;
$ok = 0;
foreach ($checks as $c) {
    if (!empty($c['ok'])) {
        $ok++;
    } else {
        $name = (string)(array_search($c, $checks, true));
        if (in_array($name, ['db.connectivity', 'fs.dataroot.writable'], true)) {
            $alerts++;
        } else {
            $warnings++;
        }
    }
}

$exitcode = 0;
if ($alerts > 0) {
    $exitcode = 2;
} elseif ($warnings > 0) {
    $exitcode = 1;
}

echo json_encode([
    'ok' => $exitcode === 0,
    'summary' => [
        'checks_ok' => $ok,
        'checks_warn' => $warnings,
        'checks_alert' => $alerts,
    ],
    'checks' => $checks,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

exit($exitcode);
