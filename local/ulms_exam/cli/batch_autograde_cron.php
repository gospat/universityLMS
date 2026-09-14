<?php
define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

raise_memory_limit(MEMORY_HUGE);
set_time_limit(0);

function local_ulms_exam_cron_acquire_lock(): bool {
    global $DB;
    global $CFG;
    $lockdir = $CFG->dataroot . '/local_ulms_exam/';
    if (!is_dir($lockdir)) { @mkdir($lockdir, $CFG->directorypermissions ?? 0755, true); }
    $lockfile = $lockdir . 'batch_autograde.lock';
    $fh = fopen($lockfile, 'w');
    if (!$fh) return false;
    if (!flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return false; }
    $GLOBALS['_exam_lock_fh'] = $fh;
    return true;
}

// Explicit global $DB declaration for usage in script scope below.
global $DB;

if (!local_ulms_exam_cron_acquire_lock()) {
    cli_writeln('Another batch autograde run active; exiting.');
    exit(0);
}

$service = \local_ulms_exam\local\service\exam_service::instance();

$now = time();
$grace = \local_ulms_exam\local\service\exam_service::WINDOW_GRACE_SEC;

$sql = "SELECT id, title, programmeid, courseid, start_ts, end_ts, status
          FROM {local_ulms_exams} e
         WHERE e.status <> :draft
           AND (
             e.status <> :graded
             OR EXISTS (
               SELECT 1 FROM {local_ulms_exam_submissions} s
                WHERE s.examid = e.id AND s.status <> :subgraded
             )
           )
           AND (e.end_ts + :graceend) < :now
      ORDER BY e.end_ts ASC";
$params = [
    'draft'    => \local_ulms_exam\local\service\exam_service::STATUS_DRAFT,
    'graded'   => \local_ulms_exam\local\service\exam_service::STATUS_GRADED,
    'subgraded'=> \local_ulms_exam\local\service\exam_service::SUBMISSION_GRADED,
    'graceend' => $grace,
    'now'      => $now,
];

$exams = $DB->get_records_sql($sql, $params);

$totalprocessed = 0;
$totalgraded = 0;
$totalalready = 0;
$totalrejected = 0;
$failures = 0;

if (empty($exams)) {
    cli_writeln('[ulms_exam_cron] No exams eligible for auto-grade at this time.');
    exit(0);
}

foreach ($exams as $exam) {
    $totalprocessed++;
    $examid = (int)$exam->id;
    try {
        $result = $service->batch_autograde_exam($examid, 'cron');
        $totalgraded   += (int)($result['graded'] ?? 0);
        $totalalready  += (int)($result['already'] ?? 0);
        $totalrejected += (int)($result['rejected'] ?? 0);
        $line = sprintf(
            '[ulms_exam_cron] Exam #%d (%s) — graded=%d already=%d rejected=%d',
            $examid,
            $exam->title,
            (int)($result['graded'] ?? 0),
            (int)($result['already'] ?? 0),
            (int)($result['rejected'] ?? 0)
        );
        cli_writeln($line);
    } catch (\Throwable $e) {
        $failures++;
        $line = sprintf('[ulms_exam_cron] FAIL Exam #%d (%s): %s', $examid, $exam->title, $e->getMessage());
        cli_writeln($line);
        if (function_exists('local_ulms_dashboard_log_operational_error')) {
            local_ulms_dashboard_log_operational_error($e, 'local_ulms_exam::batch_autograde_cron::exam_loop', ['examid' => $examid]);
        }
    }
}

$summary = sprintf(
    '[ulms_exam_cron] DONE. processed=%d exams, graded_submissions=%d, already=%d, rejected=%d, failures=%d',
    $totalprocessed,
    $totalgraded,
    $totalalready,
    $totalrejected,
    $failures
);
cli_writeln($summary);
exit(0);
