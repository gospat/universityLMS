<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/ulms_dashboard/lib.php');

use local_ulms_dashboard\local\service\student_portal_service;
use local_ulms_auth\local\service\landing_page_service;
use local_ulms_exam\local\service\exam_service;

/** @var moodle_page $PAGE */
/** @var core_renderer $OUTPUT */
global $PAGE, $OUTPUT, $DB, $USER;

require_login();
$dashboard = new \local_ulms_dashboard\local\service\dashboard_service();
$dashboard->enforce_dashboard_access('student');
$context = \context::instance_by_id(\context_system::instance()->id);
require_capability('local/ulms_dashboard:viewstudentdashboard', $context);
require_capability('local/ulms_exam:takeany', $context);

$routingservice = new landing_page_service();
$routingservice->maybe_redirect_legacy_request('student.exams');
$url = $routingservice->get_url_for_route('student.exams');
local_ulms_dashboard_prepare_page($context, $url, get_string('studentexamslistheading', 'local_ulms_exam'));

$examservice = exam_service::instance();
$portalservice = new student_portal_service();

$studentuserid = (int)$USER->id;

$profile = $DB->get_record('local_ulms_user_profile', ['userid' => $studentuserid], '*', IGNORE_MISSING);
$hasprogramme = $profile && (int)$profile->programmeid > 0;

echo $OUTPUT->header();
$headerctx = $portalservice->get_header_context_for_section('exams');
echo local_ulms_dashboard_render_page_header([
    'eyebrow' => 'EXAMS',
    'title' => $headerctx['title'] ?? get_string('studentexamslistheading', 'local_ulms_exam'),
    'meta' => $headerctx['meta'] ?? get_string('studentexamslistdesc', 'local_ulms_exam'),
]);
local_ulms_dashboard_start_shell_wrap();

if (!$hasprogramme) {
    echo $OUTPUT->notification(get_string('noprogrammeassignederror', 'local_ulms_exam'), \core\output\notification::NOTIFY_WARNING);
    local_ulms_dashboard_end_shell_wrap();
    echo $OUTPUT->footer();
    exit;
}

$programmeid = (int)$profile->programmeid;

$enrolledcourses = enrol_get_all_users_courses($studentuserid, true, ['id', 'shortname', 'fullname', 'visible', 'category'], 'shortname ASC');
$enrolledcourseids = [];
$courserecs = [];
foreach ($enrolledcourses as $ec) {
    if ((int)$ec->id === 1) continue;
    $enrolledcourseids[] = (int)$ec->id;
    $courserecs[(int)$ec->id] = $ec;
}

$programmeMeta = [];
if (!empty($enrolledcourseids)) {
    [$cin, $cparams] = $DB->get_in_or_equal($enrolledcourseids, SQL_PARAMS_NAMED, 'pcm');
    $progRows = $DB->get_records_sql(
        "SELECT pc.moodlecourseid, pc.coursetype, s.name AS semesterlabel
           FROM {local_ulms_programme_courses} pc
      LEFT JOIN {local_ulms_semesters} s ON s.id = pc.semesterid
          WHERE pc.moodlecourseid $cin AND pc.programmeid = :pid",
        $cparams + ['pid' => $programmeid]
    );
    foreach ($progRows as $pr) {
        $programmeMeta[(int)$pr->moodlecourseid] = [
            'coursetype' => !empty($pr->coursetype) ? ucfirst((string)$pr->coursetype) : 'Core',
            'semesterlabel' => !empty($pr->semesterlabel) ? (string)$pr->semesterlabel : '',
        ];
    }
}

function exam_format_duration_sec(int $sec): string {
    if ($sec <= 0) return '';
    $totalmin = (int)ceil($sec / 60);
    if ($totalmin < 60) {
        return get_string('studentexamminutes', 'local_ulms_dashboard', (string)$totalmin);
    }
    $h = (int)floor($totalmin / 60);
    $m = $totalmin % 60;
    if ($m === 0) {
        return $h === 1
            ? get_string('studentexamhr1', 'local_ulms_dashboard')
            : get_string('studentexamhrn', 'local_ulms_dashboard', (string)$h);
    }
    $hrstr = $h === 1 ? get_string('studentexamhr1', 'local_ulms_dashboard') : get_string('studentexamhrn', 'local_ulms_dashboard', (string)$h);
    return get_string('studentexamhm', 'local_ulms_dashboard', (object)['h' => $hrstr, 'm' => (string)$m]);
}

function exam_classify_status(object $e, int $now): string {
    $sub = trim((string)($e->submissionstatus ?? ''));
    if ($sub === exam_service::SUBMISSION_GRADED) {
        return 'graded';
    }
    if ($sub === exam_service::SUBMISSION_SUBMITTED || $sub === exam_service::SUBMISSION_LATE_REJECTED) {
        return 'submitted';
    }
    if ($sub === exam_service::SUBMISSION_IN_PROGRESS) {
        return 'inprogress';
    }
    $start = (int)$e->start_ts;
    $end = (int)$e->end_ts;
    if ($now < $start) {
        return 'upcoming';
    }
    if ($now > $end) {
        return 'missed';
    }
    return 'open';
}

function exam_status_css(string $s): string {
    switch ($s) {
        case 'graded': return 'graded';
        case 'submitted': return 'submitted';
        case 'inprogress': return 'inprogress';
        case 'missed': return 'missed';
        case 'upcoming': return 'upcoming';
        case 'open':
        default: return 'open';
    }
}

function exam_status_lang(string $s): string {
    switch ($s) {
        case 'graded': return get_string('studentexamstatusgraded', 'local_ulms_dashboard');
        case 'submitted': return get_string('studentexamstatussubmitted', 'local_ulms_dashboard');
        case 'inprogress': return get_string('studentexamstatusinprogress', 'local_ulms_dashboard');
        case 'missed': return get_string('studentexamstatusmissed', 'local_ulms_dashboard');
        case 'upcoming': return get_string('studentexamstatusupcoming', 'local_ulms_dashboard');
        case 'open':
        default: return get_string('studentexamstatusopen', 'local_ulms_dashboard');
    }
}

function exam_count_badge_html(array $counts): string {
    $badges = [];
    $missed = (int)($counts['missed'] ?? 0);
    $inprog = (int)($counts['inprogress'] ?? 0);
    $start7 = (int)($counts['closing7'] ?? 0);
    $upcoming = (int)($counts['upcoming'] ?? 0);
    $open = (int)($counts['open'] ?? 0);
    $done = (int)($counts['done'] ?? 0);
    $total = (int)($counts['total'] ?? 0);

    if ($total === 0) {
        return '<span class="ulms-badge ulms-badge--draft">' . get_string('studentexamzero', 'local_ulms_dashboard') . '</span>';
    }
    if ($missed > 0) {
        $badges[] = '<span class="ulms-badge ulms-badge--overdue">' . get_string('studentexammissedcount', 'local_ulms_dashboard', (string)$missed) . '</span>';
    }
    if ($inprog > 0) {
        $badges[] = '<span class="ulms-badge ulms-badge--duesoon">' . get_string('studentexaminprogresscount', 'local_ulms_dashboard', (string)$inprog) . '</span>';
    }
    if ($start7 > 0) {
        $badges[] = '<span class="ulms-badge ulms-badge--duesoon7">' . get_string('studentexamclosing7count', 'local_ulms_dashboard', (string)$start7) . '</span>';
    }
    if ($upcoming > 0) {
        $badges[] = '<span class="ulms-badge ulms-badge--draft">' . get_string('studentexamupcomingcount', 'local_ulms_dashboard', (string)$upcoming) . '</span>';
    }
    if ($open > 0) {
        $badges[] = '<span class="ulms-badge ulms-badge--published">' . get_string('studentexamopencount', 'local_ulms_dashboard', (string)$open) . '</span>';
    }
    if ($done > 0) {
        $badges[] = '<span class="ulms-badge ulms-badge--graded">' . get_string('studentexamdonecount', 'local_ulms_dashboard', (string)$done) . '</span>';
    }
    if (empty($badges)) {
        $badges[] = '<span class="ulms-badge ulms-badge--draft">' . get_string('studentexamtotalcount', 'local_ulms_dashboard', (string)$total) . '</span>';
    }
    return implode('', $badges);
}

$now = time();
$examsbycourse = [];
if (!empty($enrolledcourseids)) {
    [$cin, $cparams] = $DB->get_in_or_equal($enrolledcourseids, SQL_PARAMS_NAMED, 'ec');
    $sql = "SELECT e.*,
                   s.id AS submissionid,
                   s.status AS submissionstatus,
                   s.time_started AS substarted,
                   s.time_submitted AS subsubmitted,
                   s.score_points AS subscorepoints,
                   s.score_total_points AS subscoretotal,
                   s.score_percent AS subscorepct
              FROM {local_ulms_exams} e
         LEFT JOIN {local_ulms_exam_submissions} s ON s.examid = e.id AND s.examinee_userid = :uid
             WHERE e.programmeid = :pid
               AND e.courseid $cin
               AND e.status <> :draftstat
          ORDER BY e.start_ts ASC";
    $rows = $DB->get_records_sql($sql, [
        'uid' => $studentuserid,
        'pid' => $programmeid,
        'draftstat' => exam_service::STATUS_DRAFT,
    ] + $cparams);
    foreach ($rows as $r) {
        $cid = (int)$r->courseid;
        if (!isset($examsbycourse[$cid])) $examsbycourse[$cid] = [];
        $examsbycourse[$cid][] = $r;
    }
}

$takeurl = $routingservice->get_url_for_route('student.examstake');
$resulturl = $routingservice->get_url_for_route('student.examsresult');

$coursecards = [];
$kpiOpen = 0;
$kpiStart7 = 0;
$kpiDoneMissed = 0;
$datetimefmt = get_string('strftimedatetime', 'core_langconfig');

foreach ($enrolledcourseids as $cid) {
    $courserec = $courserecs[$cid] ?? null;
    if (!$courserec) continue;
    $meta = $programmeMeta[$cid] ?? ['coursetype' => 'Core', 'semesterlabel' => ''];
    $coursemeta = trim(format_string((string)$courserec->shortname)
        . ' · ' . $meta['coursetype']
        . ($meta['semesterlabel'] !== '' ? ' · ' . $meta['semesterlabel'] : ''));

    $exams = $examsbycourse[$cid] ?? [];
    $counts = [
        'total' => 0, 'open' => 0, 'closing7' => 0, 'upcoming' => 0,
        'inprogress' => 0, 'submitted' => 0, 'missed' => 0, 'graded' => 0, 'done' => 0,
    ];
    $subitems = [];
    foreach ($exams as $e) {
        $counts['total']++;
        $status = exam_classify_status($e, $now);
        switch ($status) {
            case 'graded': $counts['graded']++; $counts['done']++; break;
            case 'submitted': $counts['submitted']++; $counts['done']++; break;
            case 'inprogress': $counts['inprogress']++; break;
            case 'missed': $counts['missed']++; break;
            case 'upcoming':
                $counts['upcoming']++;
                if ((int)$e->start_ts <= ($now + 7 * 86400)) $counts['closing7']++;
                break;
            case 'open':
            default:
                $counts['open']++;
                if ((int)$e->end_ts <= ($now + 7 * 86400)) $counts['closing7']++;
                break;
        }

        $startstr = userdate((int)$e->start_ts, $datetimefmt);
        $endstr = userdate((int)$e->end_ts, $datetimefmt);
        $windowHtml = get_string('studentexamwindow', 'local_ulms_dashboard', (object)['open' => $startstr, 'close' => $endstr]);

        $durationSec = (int)($e->durationsec ?? 0);
        $durationHtml = $durationSec > 0
            ? ' · <span class="ulms-exammeta ulms-exammeta--duration">' . exam_format_duration_sec($durationSec) . '</span>'
            : '';
        $passPct = (float)($e->passpct ?? 0);
        $passHtml = $passPct > 0
            ? ' · <span class="ulms-exammeta ulms-exammeta--pass">' . get_string('studentexampasspct', 'local_ulms_dashboard', (string)round($passPct, 0)) . '</span>'
            : '';

        $statusClass = exam_status_css($status);
        $statusText = exam_status_lang($status);
        $pillHtml = '<span class="ulms-examstatus ulms-examstatus--' . $statusClass . '">' . $statusText . '</span>';

        $scoreHtml = '';
        if (($status === 'graded' || $status === 'submitted')
            && ($e->subscoretotal ?? 0) > 0 && $e->submissionid !== null) {
            $points = format_float((float)$e->subscorepoints, 1);
            $total = format_float((float)$e->subscoretotal, 1);
            $pct = format_float((float)$e->subscorepct, 0);
            $scoreHtml = ' · ' . get_string('studentexamscoreoutof', 'local_ulms_dashboard', (object)[
                'points' => $points,
                'total' => $total,
                'percent' => $pct,
            ]);
        }

        $rowlink = null;
        $subid = (int)($e->submissionid ?? 0);
        if ($subid > 0) {
            $rowlink = new moodle_url($resulturl, ['submissionid' => $subid]);
        } elseif ($status === 'open') {
            $rowlink = new moodle_url($takeurl, ['examid' => (int)$e->id]);
        }

        $subitems[] = [
            'title' => format_string((string)$e->title),
            'meta' => $windowHtml . $durationHtml . $passHtml . ' · ' . $pillHtml . $scoreHtml,
            'meta_raw' => true,
            'url' => $rowlink,
        ];
    }

    $kpiOpen += $counts['open'];
    $kpiStart7 += $counts['closing7'];
    $kpiDoneMissed += ($counts['done'] + $counts['missed']);

    $coursecards[] = [
        'title' => format_string((string)$courserec->fullname),
        'meta' => $coursemeta,
        'url' => new moodle_url('/course/view.php', ['id' => $cid]),
        'badgehtml' => exam_count_badge_html($counts),
        'assignments' => $subitems,
        'sublistempty' => get_string('studentexamsnoexamcourse', 'local_ulms_dashboard'),
    ];
}

$summarycards = [
    [
        'label' => get_string('studentexamenrolled', 'local_ulms_dashboard'),
        'value' => (string)count($coursecards),
        'description' => get_string('studentexamenrolleddesc', 'local_ulms_dashboard'),
    ],
    [
        'label' => get_string('studentexamopen', 'local_ulms_dashboard'),
        'value' => (string)$kpiOpen,
        'description' => get_string('studentexamopendesc', 'local_ulms_dashboard'),
    ],
    [
        'label' => get_string('studentexamclosing7', 'local_ulms_dashboard'),
        'value' => (string)$kpiStart7,
        'description' => get_string('studentexamclosing7desc', 'local_ulms_dashboard'),
    ],
    [
        'label' => get_string('studentexamdone', 'local_ulms_dashboard'),
        'value' => (string)$kpiDoneMissed,
        'description' => get_string('studentexamdonedesc', 'local_ulms_dashboard'),
    ],
];

echo local_ulms_dashboard_render_summary_cards($summarycards);

echo local_ulms_dashboard_render_panel([
    'title' => get_string('studentexamsgroupedtitle', 'local_ulms_dashboard'),
    'subtitle' => get_string('studentexamsdescgrouped', 'local_ulms_dashboard'),
    'style' => 'coursegroups',
    'items' => $coursecards,
    'emptytitle' => get_string('studentexamsemptycourses', 'local_ulms_dashboard'),
    'emptydesc' => get_string('studentexamsemptycoursesdesc', 'local_ulms_dashboard'),
]);

local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
