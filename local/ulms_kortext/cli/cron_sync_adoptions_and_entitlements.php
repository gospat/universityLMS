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
define('NO_OUTPUT_BUFFERING', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

raise_memory_limit(MEMORY_HUGE);
set_time_limit(0);

/**
 * Attempts to acquire an exclusive lock for this cron run.
 *
 * @return resource|false Locked file handle or false on failure.
 */
function local_ulms_kortext_cron_acquire_lock() {
    global $CFG;
    $lockdir = $CFG->dataroot . '/local_ulms_kortext/';
    if (!is_dir($lockdir)) {
        @mkdir($lockdir, $CFG->directorypermissions ?? 0755, true);
    }
    $lockfile = $lockdir . 'sync_adoptions.lock';
    $fh = @fopen($lockfile, 'w');
    if (!$fh) {
        return false;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return false;
    }
    return $fh;
}

cli_writeln(get_string('cron_header', 'local_ulms_kortext'));
cli_writeln(get_string('cron_acquiring_lock', 'local_ulms_kortext'));

$lockfh = local_ulms_kortext_cron_acquire_lock();
if (!$lockfh) {
    cli_writeln(get_string('cron_locked_skip', 'local_ulms_kortext'));
    exit(0);
}
cli_writeln(get_string('cron_lock_ok', 'local_ulms_kortext'));

global $DB;

$service = new \local_ulms_kortext\local\service\adoption_sync_service();

try {
    cli_writeln(get_string('cron_step_purge', 'local_ulms_kortext'));
    cli_writeln(get_string('cron_step_collect', 'local_ulms_kortext'));
    $result = $service->sync_once(\local_ulms_kortext\local\service\adoption_sync_service::DIRECTION_ULMS_PUSH);

    $total = $result['granted'] + $result['failed'] + $result['skipped'];
    cli_writeln(get_string('cron_pairs_count', 'local_ulms_kortext', (string)$total));
    cli_writeln(get_string('cron_step_entitle', 'local_ulms_kortext'));

    $a = new stdClass();
    $a->done    = $total;
    $a->total   = $total;
    $a->granted = $result['granted'];
    $a->failed  = $result['failed'];
    $a->skipped = $result['skipped'];
    cli_writeln(get_string('cron_entitle_progress', 'local_ulms_kortext', $a));

    if (count($result['errors']) > 0) {
        $errors = array_slice($result['errors'], 0, 10);
        foreach ($errors as $_e) {
            cli_writeln('  · ' . $_e);
        }
    }

    cli_writeln(get_string('cron_step_usage', 'local_ulms_kortext'));
    cli_writeln(get_string('cron_done', 'local_ulms_kortext'));
} catch (\Throwable $e) {
    cli_writeln(get_string('cron_error_throwable', 'local_ulms_kortext', $e->getMessage()));
} finally {
    if (is_resource($lockfh)) {
        flock($lockfh, LOCK_UN);
        fclose($lockfh);
    }
}

exit(0);
