<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/ulms_dashboard/lib.php');
require_once($CFG->libdir . '/completionlib.php');

global $PAGE, $OUTPUT, $DB, $USER;

require_login();

$dashboardservice = new \local_ulms_dashboard\local\service\dashboard_service();
$dashboardservice->enforce_dashboard_access('student');
$routingservice = new \local_ulms_auth\local\service\landing_page_service();
$routingservice->maybe_redirect_legacy_request('student.progress');

$context = \context::instance_by_id(\context_system::instance()->id);
require_capability('local/ulms_dashboard:viewstudentdashboard', $context);

$url = $routingservice->get_url_for_route('student.progress');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('studentprogresstitle', 'local_ulms_dashboard'));
$PAGE->set_heading(get_string('studentprogresstitle', 'local_ulms_dashboard'));
$PAGE->set_pagelayout('ulmsdashboard');

$snapshot = $dashboardservice->get_current_user_snapshot();
$allcourses = [];
foreach ($snapshot['courses'] ?? [] as $c) {
    $cid = (int)($c['id'] ?? 0);
    if ($cid <= 0 || $cid === 1) {
        continue;
    }
    $allcourses[$cid] = [
        'id' => $cid,
        'fullname' => format_string($c['fullname'] ?? ''),
        'shortname' => format_string($c['shortname'] ?? ''),
        'courseurl' => !empty($c['url']) ? $c['url']->out(false) : (new moodle_url('/course/view.php', ['id' => $cid]))->out(false),
    ];
}

function progress_status_label(int $state, bool $hasoverdue = false): string {
    if ($state >= 1) {
        return 'completed';
    }
    if ($state === 0 && $hasoverdue === true) {
        return 'overdue';
    }
    if ($state === 0) {
        return 'incomplete';
    }
    return 'pending';
}

function progress_status_css(string $status): string {
    static $map = [
        'completed'  => 'ulms-progressstatus ulms-progressstatus--completed',
        'inprogress' => 'ulms-progressstatus ulms-progressstatus--inprogress',
        'pending'    => 'ulms-progressstatus ulms-progressstatus--pending',
        'overdue'    => 'ulms-progressstatus ulms-progressstatus--overdue',
        'incomplete' => 'ulms-progressstatus ulms-progressstatus--incomplete',
        'excluded'   => 'ulms-progressstatus ulms-progressstatus--excluded',
    ];
    return $map[$status] ?? $map['pending'];
}

function progress_status_lang(string $status): string {
    static $map = [
        'completed'  => 'studentprogressstatuscompleted',
        'inprogress' => 'studentprogressstatusinprogress',
        'pending'    => 'studentprogressstatuspending',
        'overdue'    => 'studentprogressstatusoverdue',
        'incomplete' => 'studentprogressstatusincomplete',
        'excluded'   => 'studentprogressstatusexcluded',
    ];
    $key = $map[$status] ?? $map['pending'];
    return get_string($key, 'local_ulms_dashboard');
}

function progress_bar_html(int $percent, string $status = 'completed'): string {
    $percent = max(0, min(100, $percent));
    $cls = match (true) {
        $percent >= 100 => 'ulms-progress-bar--complete',
        $percent >= 50  => 'ulms-progress-bar--progress',
        $status === 'overdue' => 'ulms-progress-bar--overdue',
        $status === 'inprogress' => 'ulms-progress-bar--progress',
        default => 'ulms-progress-bar--start',
    };
    $html = '<div class="ulms-progress-track" aria-hidden="true">';
    $html .= '<div class="ulms-progress-fill ' . $cls . '" style="width:' . $percent . '%"></div>';
    $html .= '</div>';
    return $html;
}

function progress_count_badge_html(array $counts): string {
    $parts = [];
    $order = ['overdue', 'incomplete', 'inprogress', 'completed', 'pending'];
    $labels = [
        'overdue'    => ['s' => 'studentprogresscountoverdue',    'p' => 'studentprogresscountoverdueplural',    'c' => 'ulms-progressstatus ulms-progressstatus--overdue'],
        'incomplete' => ['s' => 'studentprogresscountincomplete', 'p' => 'studentprogresscountincompleteplural', 'c' => 'ulms-progressstatus ulms-progressstatus--incomplete'],
        'inprogress' => ['s' => 'studentprogresscountinprogress', 'p' => 'studentprogresscountinprogressplural', 'c' => 'ulms-progressstatus ulms-progressstatus--inprogress'],
        'completed'  => ['s' => 'studentprogresscountcompleted',  'p' => 'studentprogresscountcompletedplural',  'c' => 'ulms-progressstatus ulms-progressstatus--completed'],
        'pending'    => ['s' => 'studentprogresscountpending',    'p' => 'studentprogresscountpendingplural',    'c' => 'ulms-progressstatus ulms-progressstatus--pending'],
    ];
    foreach ($order as $k) {
        $n = (int)($counts[$k] ?? 0);
        if ($n <= 0) { continue; }
        $lab = $n === 1
            ? get_string($labels[$k]['s'], 'local_ulms_dashboard')
            : get_string($labels[$k]['p'], 'local_ulms_dashboard', (string)$n);
        $parts[] = html_writer::tag('span', $lab, ['class' => $labels[$k]['c']]);
    }
    if (empty($parts)) {
        $parts[] = html_writer::tag('span', get_string('studentprogresscountzero', 'local_ulms_dashboard'),
            ['class' => 'ulms-progressstatus ulms-progressstatus--excluded']);
    }
    return implode("\n", $parts);
}

function progress_module_type_label(string $modname): string {
    $map = [
        'assign' => 'Assignment',
        'quiz' => 'Quiz',
        'forum' => 'Forum',
        'resource' => 'Reading',
        'page' => 'Page',
        'lesson' => 'Lesson',
        'scorm' => 'SCORM',
        'workshop' => 'Workshop',
        'choice' => 'Choice',
        'feedback' => 'Feedback',
        'url' => 'Link',
    ];
    return $map[$modname] ?? ucfirst($modname);
}

$courseids = array_keys($allcourses);

$completionrows = [];
$modulemeta = [];
if (!empty($courseids)) {
    [$incs, $par] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
    $par['uid'] = (int)$USER->id;
    $par['del'] = 0;

    $sql = "SELECT cm.id AS cmid, cm.course, cm.instance, cm.completion AS cmcompletion,
                   cm.completionview AS viewrequired, cm.completiongradeitemnumber AS gradeitemnum,
                   cm.completionexpected AS expected_ts,
                   m.name AS modname
              FROM {course_modules} cm
              JOIN {modules} m ON m.id = cm.module
         LEFT JOIN {course_modules_completion} mc
                   ON mc.coursemoduleid = cm.id AND mc.userid = :uid
              WHERE cm.course $incs
                AND cm.deletioninprogress = :del
              AND (cm.completion <> 0 OR mc.id IS NOT NULL OR m.name IN ('assign','quiz','forum','resource','page','lesson'))
           ORDER BY cm.course ASC, cm.section ASC, cm.id ASC";
    $rs = $DB->get_recordset_sql($sql, $par);
    $instnames = [];
    $modrowsbycourse = [];
    $rawrows = [];
    foreach ($rs as $r) {
        $rawrows[] = $r;
        $mod = $r->modname;
        if (!isset($instnames[$mod])) { $instnames[$mod] = []; }
    }
    $rs->close();

    foreach ($instnames as $mod => $_) {
        $instids = [];
        foreach ($rawrows as $r) { if ($r->modname === $mod && (int)$r->instance > 0) { $instids[(int)$r->instance] = true; } }
        if (empty($instids)) { continue; }
        $ids = array_keys($instids);
        [$inm, $parm] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        try {
            $namers = $DB->get_records_select($mod, "id $inm", $parm, '', 'id, name');
            foreach ($namers as $id => $row) {
                $instnames[$mod][(int)$id] = format_string($row->name ?? '');
            }
        } catch (\Throwable $e) {
            foreach ($ids as $id) { $instnames[$mod][$id] = ucfirst($mod).' #'.$id; }
        }
    }

    foreach ($rawrows as $r) {
        $cid = (int)$r->course;
        $name = $instnames[$r->modname][(int)$r->instance] ?? null;
        if ($name === null || $name === '') { $name = progress_module_type_label($r->modname) . ' #' . (int)$r->instance; }
        $compstate = COMPLETION_INCOMPLETE;
        $timemodified = 0;
        $viewed = false;
        $gradeok = null;
        $existing = $DB->get_record_select('course_modules_completion',
            'coursemoduleid = :cmid AND userid = :uid',
            ['cmid' => (int)$r->cmid, 'uid' => (int)$USER->id], '*', IGNORE_MISSING);
        if ($existing) {
            $compstate = (int)$existing->completionstate;
            $timemodified = (int)($existing->timemodified ?? 0);
            $viewed = !empty($existing->viewed);
            $gradeok = isset($existing->overrideby) ? (int)$existing->overrideby : null;
        }
        $expected = (int)$r->expected_ts;
        $now = time();
        $hasoverdue = $expected > 0 && $now > $expected && $compstate < COMPLETION_COMPLETE;
        $status = progress_status_label($compstate, $hasoverdue);
        $modrowsbycourse[$cid][] = (object)[
            'cmid' => (int)$r->cmid,
            'modname' => $r->modname,
            'instance' => (int)$r->instance,
            'name' => $name,
            'state' => $compstate,
            'status' => $status,
            'expected_ts' => $expected,
            'timemodified' => $timemodified,
        ];
    }
    $completionrows = $modrowsbycourse;
}

$kpiEnrolled = 0;
$kpiCompleted = 0;
$kpiStarted = 0;
$kpiOverdue = 0;
$kpiTotal = 0;
$overallCompletedCount = 0;
$overallTotal = 0;
$carditems = [];

$today = time();
$endofweek = strtotime('next sunday 23:59:59', $today);

foreach ($allcourses as $cid => $course) {
    $kpiEnrolled++;
    $rows = $completionrows[$cid] ?? [];

    $counts = ['overdue'=>0,'incomplete'=>0,'inprogress'=>0,'completed'=>0,'pending'=>0];
    $assessmentrows = [];
    $courseTotal = count($rows);
    $courseDone = 0;
    $thisweek = 0;

    foreach ($rows as $r) {
        $kpiTotal++;
        if ($r->status === 'completed') {
            $counts['completed']++;
            $kpiCompleted++;
            $courseDone++;
            $overallCompletedCount++;
        } elseif ($r->status === 'overdue') {
            $counts['overdue']++;
            $kpiOverdue++;
            $kpiStarted++;
        } elseif ($r->status === 'inprogress') {
            $counts['inprogress']++;
            $kpiStarted++;
        } elseif ($r->status === 'incomplete') {
            $counts['incomplete']++;
            $kpiStarted++;
        } else {
            $counts['pending']++;
        }
        $overallTotal++;
        if ($r->expected_ts > 0 && $r->expected_ts <= $endofweek && $r->status !== 'completed') {
            $thisweek++;
        }
        $percent = match ($r->status) {
            'completed' => 100,
            'overdue', 'incomplete' => 15,
            'inprogress' => 60,
            default => 0,
        };
        $activityurl = (new moodle_url('/mod/' . $r->modname . '/view.php', ['id' => $r->cmid]))->out(false);
        $metaParts = [];
        $metaParts[] = html_writer::tag('span', progress_module_type_label((string)$r->modname),
            ['class' => 'ulms-progressmeta ulms-progressmeta--type']);
        if ($r->expected_ts > 0) {
            $metaParts[] = html_writer::tag('span',
                get_string('studentprogressdueon', 'local_ulms_dashboard', userdate($r->expected_ts, '%b %e, %Y')),
                ['class' => 'ulms-progressmeta ulms-progressmeta--due']);
        }
        $metaParts[] = html_writer::tag('span', progress_status_lang((string)$r->status),
            ['class' => progress_status_css((string)$r->status)]);
        $progbar = progress_bar_html($percent, (string)$r->status);
        $metaPartsHtml = implode(' · ', $metaParts);
        $wrappedMeta = '<div style="display:flex;flex-direction:column;gap:.35rem;">'
            . $progbar
            . '<div style="display:flex;flex-wrap:wrap;gap:.3rem;">' . $metaPartsHtml . '</div>'
            . '</div>';
        $assessmentrows[] = [
            'title' => $r->name,
            'title_url' => $activityurl,
            'meta'  => $wrappedMeta,
            'meta_raw' => true,
        ];
    }

    $coursepct = $courseTotal > 0 ? (int)round(($courseDone / $courseTotal) * 100) : 0;
    $badgehtml = progress_count_badge_html($counts);
    $badgehtml .= "\n" . html_writer::tag('span',
        get_string('studentprogresspctmeta', 'local_ulms_dashboard', (string)$coursepct),
        ['class' => 'ulms-progressmeta ulms-progressmeta--pct']);
    if ($thisweek > 0) {
        $badgehtml .= "\n" . html_writer::tag('span',
            get_string('studentprogressthisweek', 'local_ulms_dashboard', (string)$thisweek),
            ['class' => 'ulms-progressmeta ulms-progressmeta--due']);
    }

    $shortname = $course['shortname'];
    $fullname = $course['fullname'];
    $coursetype = 'Core';
    $semesterlabel = 'First Semester 2026/27';
    $subtitlehtml = html_writer::tag('span', $shortname) . ' · ' . $coursetype . ' · ' . $semesterlabel;

    $progresspanel = '<div style="margin-top:.35rem;">' . progress_bar_html($coursepct, $coursepct >= 100 ? 'completed' : ($counts['overdue'] > 0 ? 'overdue' : 'inprogress')) . '</div>';
    $subtitlehtml .= $progresspanel;

    $carditems[] = [
        'title'        => $fullname,
        'title_url'    => $course['courseurl'],
        'meta'         => $subtitlehtml,
        'meta_raw'     => true,
        'badgehtml'    => $badgehtml,
        'assignments'  => $assessmentrows,
        'sublistempty' => get_string('studentprogresscourseempty', 'local_ulms_dashboard'),
    ];
}

$overallpct = $overallTotal > 0 ? (int)round(($overallCompletedCount / $overallTotal) * 100) : 0;
$summarycards = [
    [
        'label'       => get_string('studentprogresssummaryoverall', 'local_ulms_dashboard'),
        'value'       => $overallpct . '%',
        'description' => get_string('studentprogresssummaryoveralldesc', 'local_ulms_dashboard'),
    ],
    [
        'label'       => get_string('studentprogresssummarycompleted', 'local_ulms_dashboard'),
        'value'       => $kpiCompleted . ' / ' . $kpiTotal,
        'description' => get_string('studentprogresssummarycompleteddesc', 'local_ulms_dashboard'),
    ],
    [
        'label'       => get_string('studentprogresssummarystarted', 'local_ulms_dashboard'),
        'value'       => $kpiStarted === 0
            ? get_string('studentprogresscountzero', 'local_ulms_dashboard')
            : (string)$kpiStarted,
        'description' => get_string('studentprogresssummarystarteddesc', 'local_ulms_dashboard'),
    ],
    [
        'label'       => get_string('studentprogresssummaryoverdue', 'local_ulms_dashboard'),
        'value'       => $kpiOverdue === 0
            ? get_string('studentprogresscountzero', 'local_ulms_dashboard')
            : (string)$kpiOverdue,
        'description' => get_string('studentprogresssummaryoverduedesc', 'local_ulms_dashboard'),
    ],
];

echo $OUTPUT->header();
$service = new \local_ulms_dashboard\local\service\student_portal_service();
echo local_ulms_dashboard_render_page_header($service->get_header_context_for_section('progress'));
local_ulms_dashboard_start_shell_wrap();

echo local_ulms_dashboard_render_summary_cards($summarycards);

if (!empty($carditems)) {
    echo local_ulms_dashboard_render_panel([
        'style'    => 'coursegroups',
        'eyebrow'  => get_string('studentprogressgroupedeyebrow', 'local_ulms_dashboard'),
        'title'    => get_string('studentprogressgroupedtitle', 'local_ulms_dashboard'),
        'subtitle' => get_string('studentprogressgroupeddesc', 'local_ulms_dashboard'),
        'items'    => $carditems,
    ]);
} else {
    echo local_ulms_dashboard_render_panel([
        'style'      => 'list',
        'title'      => get_string('studentprogresstitle', 'local_ulms_dashboard'),
        'items'      => [],
        'emptytitle' => get_string('studentprogressempty', 'local_ulms_dashboard'),
        'emptydesc'  => get_string('studentprogressemptydesc', 'local_ulms_dashboard'),
    ]);
}

local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
