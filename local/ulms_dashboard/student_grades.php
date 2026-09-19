<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/ulms_dashboard/lib.php');

global $PAGE, $OUTPUT, $DB, $USER;

require_login();

$dashboardservice = new \local_ulms_dashboard\local\service\dashboard_service();
$dashboardservice->enforce_dashboard_access('student');
$routingservice = new \local_ulms_auth\local\service\landing_page_service();
$routingservice->maybe_redirect_legacy_request('student.grades');

$context = \context::instance_by_id(\context_system::instance()->id);
require_capability('local/ulms_dashboard:viewstudentdashboard', $context);

$url = $routingservice->get_url_for_route('student.grades');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('studentgradespage', 'local_ulms_dashboard'));
$PAGE->set_heading(get_string('studentgradespage', 'local_ulms_dashboard'));
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
        'reporturl' => (new moodle_url('/grade/report/user/index.php', ['id' => $cid]))->out(false),
    ];
}

function grade_percent_to_letter(float $pct): string {
    if ($pct >= 93) { return 'A'; }
    if ($pct >= 90) { return 'A-'; }
    if ($pct >= 87) { return 'B+'; }
    if ($pct >= 83) { return 'B'; }
    if ($pct >= 80) { return 'B-'; }
    if ($pct >= 77) { return 'C+'; }
    if ($pct >= 73) { return 'C'; }
    if ($pct >= 70) { return 'C-'; }
    if ($pct >= 67) { return 'D+'; }
    if ($pct >= 63) { return 'D'; }
    if ($pct >= 60) { return 'D-'; }
    return 'F';
}

function grade_classify_status(?float $grade, ?float $max, ?bool $hasactiveattempt = null): string {
    if ($grade === null || $max === null || (float)$max <= 0) {
        if ($hasactiveattempt === true) { return 'inprogress'; }
        if ($hasactiveattempt === false) { return 'submitted'; }
        return 'missing';
    }
    $pct = ((float)$grade / (float)$max) * 100.0;
    if ($pct < 50) { return 'dropped'; }
    return 'graded';
}

function grade_status_css(string $status): string {
    static $map = [
        'graded'     => 'ulms-gradestatus ulms-gradestatus--graded',
        'submitted'  => 'ulms-gradestatus ulms-gradestatus--submitted',
        'inprogress' => 'ulms-gradestatus ulms-gradestatus--inprogress',
        'missing'    => 'ulms-gradestatus ulms-gradestatus--missing',
        'pending'    => 'ulms-gradestatus ulms-gradestatus--pending',
        'dropped'    => 'ulms-gradestatus ulms-gradestatus--dropped',
    ];
    return $map[$status] ?? $map['pending'];
}

function grade_status_lang(string $status): string {
    static $map = [
        'graded'     => 'studentgradestatusgraded',
        'submitted'  => 'studentgradestatussubmitted',
        'inprogress' => 'studentgradestatusinprogress',
        'missing'    => 'studentgradestatusmissing',
        'pending'    => 'studentgradestatuspending',
        'dropped'    => 'studentgradestatusdropped',
    ];
    $key = $map[$status] ?? $map['pending'];
    return get_string($key, 'local_ulms_dashboard');
}

function grade_module_label(string $type, string $module = ''): string {
    if ($type === 'course') { return 'Course total'; }
    if ($module === 'assign') { return 'Assignment'; }
    if ($module === 'quiz') { return 'Quiz'; }
    if ($module === 'ulms_exam') { return 'Exam'; }
    if ($module === '') { return 'Assessment'; }
    return ucwords(str_replace('_', ' ', $module));
}

function grade_count_badge_html(array $counts): string {
    $parts = [];
    $order = ['failing', 'warning', 'passing', 'graded', 'pending'];
    $labels = [
        'failing' => ['s' => 'studentgradecountfailing', 'p' => 'studentgradecountfailingplural', 'c' => 'ulms-gradestatus ulms-gradestatus--missing'],
        'warning' => ['s' => 'studentgradecountwarning', 'p' => 'studentgradecountwarningplural', 'c' => 'ulms-gradestatus ulms-gradestatus--inprogress'],
        'passing' => ['s' => 'studentgradecountpassing', 'p' => 'studentgradecountpassingplural', 'c' => 'ulms-gradestatus ulms-gradestatus--pending'],
        'graded' => ['s' => 'studentgradecountgraded', 'p' => 'studentgradecountgradedplural', 'c' => 'ulms-gradestatus ulms-gradestatus--graded'],
        'pending' => ['s' => 'studentgradecountpending', 'p' => 'studentgradecountpendingplural', 'c' => 'ulms-gradestatus ulms-gradestatus--dropped'],
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
        $parts[] = html_writer::tag('span', get_string('studentgradecountzero', 'local_ulms_dashboard'), ['class' => 'ulms-gradestatus ulms-gradestatus--pending']);
    }
    return implode("\n", $parts);
}

$courseids = array_keys($allcourses);
$courserows = [];
if (!empty($courseids)) {
    [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'courseid');
    $params['userid'] = (int)$USER->id;
    $params['gradetypenone'] = 0;

    $sql = "SELECT gi.courseid, gi.id AS itemid, gi.itemtype, gi.itemmodule, gi.iteminstance, gi.itemname,
                   gi.grademax, gi.grademin, gi.gradepass, gi.sortorder, gi.hidden,
                   gg.finalgrade, gg.rawgrademax, gg.timecreated, gg.timemodified
              FROM {grade_items} gi
         LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :userid
             WHERE gi.courseid $insql
               AND gi.gradetype <> :gradetypenone
               AND gi.hidden = 0
               AND (
                   (gi.itemtype IN ('course', 'mod')) OR
                   (gi.itemtype = 'manual' AND gi.itemmodule IN ('assign','quiz','ulms_exam'))
               )
          ORDER BY gi.courseid ASC,
                   CASE WHEN gi.itemtype = 'course' THEN 0 ELSE 1 END ASC,
                   gi.sortorder ASC";
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach ($rs as $r) {
        $courserows[(int)$r->courseid][] = $r;
    }
    $rs->close();
}

$kpiEnrolled = 0;
$kpiGraded = 0;
$kpiTotalItems = 0;
$kpiMissing = 0;
$gpaccum = 0.0;
$gpnum = 0;
$carditems = [];

foreach ($allcourses as $cid => $course) {
    $kpiEnrolled++;
    $rows = $courserows[$cid] ?? [];
    $totalitem = null;
    $items = [];
    foreach ($rows as $r) {
        if ($r->itemtype === 'course') {
            $totalitem = $r;
            continue;
        }
        $items[] = $r;
    }

    $maxp = null; $grade = null; $coursehaspercent = false; $coursepct = null;
    if ($totalitem) {
        $maxp = (float)$totalitem->grademax > 0 ? (float)$totalitem->grademax : null;
        $grade = $totalitem->finalgrade !== null ? (float)$totalitem->finalgrade : null;
        if ($grade !== null && $maxp !== null) {
            $coursepct = round(($grade / $maxp) * 100.0, 1);
            $coursehaspercent = true;
            $gpaccum += $coursepct;
            $gpnum++;
        }
    }

    $counts = ['failing'=>0,'warning'=>0,'passing'=>0,'graded'=>0,'pending'=>0];
    $assessmentrows = [];
    foreach ($items as $r) {
        $kpiTotalItems++;
        $rmax = (float)$r->grademax > 0 ? (float)$r->grademax : null;
        $rgrade = $r->finalgrade !== null ? (float)$r->finalgrade : null;
        $status = grade_classify_status($rgrade, $rmax);
        $pct = null;
        if ($rgrade !== null && $rmax !== null) {
            $pct = round(($rgrade / $rmax) * 100.0, 1);
            $counts['graded']++;
            $kpiGraded++;
            if ($pct < 60) { $counts['failing']++; }
            elseif ($pct < 70) { $counts['warning']++; }
            else { $counts['passing']++; }
        } else {
            $counts['pending']++;
            $kpiMissing++;
        }
        $scorehtml = $rgrade !== null && $rmax !== null
            ? get_string('studentgradescoreoutof', 'local_ulms_dashboard', (object)[
                'score' => format_float($rgrade, 2),
                'max'   => format_float($rmax, 2),
                'percent' => format_float($pct, 1),
            ])
            : get_string('studentgradescorenotgraded', 'local_ulms_dashboard');

        $metachips = [];
        $typelab = grade_module_label((string)$r->itemtype, (string)($r->itemmodule ?? ''));
        $metachips[] = html_writer::tag('span', $typelab, ['class' => 'ulms-grademeta']);
        if ($pct !== null) {
            $letter = grade_percent_to_letter((float)$pct);
            $metachips[] = html_writer::tag('span', get_string('studentgradeletter', 'local_ulms_dashboard', (object)['letter' => $letter]),
                ['class' => 'ulms-grademeta ulms-grademeta--letter']);
        }
        if (!empty($r->gradepass) && (float)$r->gradepass > 0 && $rmax !== null) {
            $passpct = round(((float)$r->gradepass / $rmax) * 100.0, 0);
            $metachips[] = html_writer::tag('span', 'Pass ' . format_float($passpct, 0) . '%',
                ['class' => 'ulms-exammeta ulms-exammeta--pass']);
        }
        $statuspill = html_writer::tag('span', grade_status_lang($status), ['class' => grade_status_css($status)]);

        $assessmentrows[] = [
            'title' => format_string($r->itemname ?: $typelab),
            'meta'  => $scorehtml . ' · ' . implode(' · ', $metachips) . ' · ' . $statuspill,
            'meta_raw' => true,
        ];
    }

    $badgehtml = grade_count_badge_html($counts);
    if ($coursehaspercent) {
        $letter = grade_percent_to_letter((float)$coursepct);
        $badgehtml .= "\n" . html_writer::tag('span',
            get_string('studentgradeletter', 'local_ulms_dashboard', (object)['letter' => $letter]),
            ['class' => 'ulms-grademeta ulms-grademeta--letter']);
        $badgehtml .= "\n" . html_writer::tag('span',
            get_string('studentgradepercent', 'local_ulms_dashboard', format_float($coursepct, 1)),
            ['class' => 'ulms-grademeta ulms-grademeta--weight']);
    }

    $shortname = $course['shortname'];
    $fullname = $course['fullname'];
    $coursetype = 'Core';
    $semesterlabel = 'First Semester 2026/27';
    $subtitlehtml = html_writer::tag('span', $shortname) . ' · ' . $coursetype . ' · ' . $semesterlabel;

    $reporturl = $course['reporturl'];
    $actions = !empty($assessmentrows) ? [[
        'label' => get_string('studentgradecoursetotal', 'local_ulms_dashboard'),
        'url' => $reporturl,
    ]] : [];

    $carditems[] = [
        'title'        => $fullname,
        'title_url'    => $course['courseurl'],
        'meta'         => $subtitlehtml,
        'meta_raw'     => true,
        'badgehtml'    => $badgehtml,
        'actions'      => $actions,
        'assignments'  => $assessmentrows,
        'sublistempty' => get_string('studentgradeitemempty', 'local_ulms_dashboard'),
    ];
}

$summarycards = [
    [
        'label'       => get_string('studentgradesummaryenrolled', 'local_ulms_dashboard'),
        'value'       => (string)$kpiEnrolled,
        'description' => get_string('studentgradesummaryenrolleddesc', 'local_ulms_dashboard'),
    ],
    [
        'label'       => get_string('studentgradesummarygraded', 'local_ulms_dashboard'),
        'value'       => $kpiGraded . ' / ' . $kpiTotalItems,
        'description' => get_string('studentgradesummarygradeddesc', 'local_ulms_dashboard'),
    ],
    [
        'label'       => get_string('studentgradesummarygpa', 'local_ulms_dashboard'),
        'value'       => $gpnum > 0 ? format_float($gpaccum / $gpnum, 1) . '%' : get_string('studentnotgradedlabel', 'local_ulms_dashboard'),
        'description' => get_string('studentgradesummarygpadesc', 'local_ulms_dashboard'),
    ],
    [
        'label'       => get_string('studentgradesummarymissing', 'local_ulms_dashboard'),
        'value'       => $kpiMissing === 0
            ? get_string('studentgradecountzero', 'local_ulms_dashboard')
            : (string)$kpiMissing,
        'description' => get_string('studentgradesummarymissingdesc', 'local_ulms_dashboard'),
    ],
];

echo $OUTPUT->header();
$service = new \local_ulms_dashboard\local\service\student_portal_service();
echo local_ulms_dashboard_render_page_header($service->get_header_context_for_section('grades'));
local_ulms_dashboard_start_shell_wrap();

echo local_ulms_dashboard_render_summary_cards($summarycards);

if (!empty($carditems)) {
    echo local_ulms_dashboard_render_panel([
        'style'    => 'coursegroups',
        'eyebrow'  => get_string('studentgradesgroupedtitle', 'local_ulms_dashboard'),
        'title'    => get_string('studentgradesgroupedtitle', 'local_ulms_dashboard'),
        'subtitle' => get_string('studentgradesgroupeddesc', 'local_ulms_dashboard'),
        'items'    => $carditems,
    ]);
} else {
    echo local_ulms_dashboard_render_panel([
        'style'     => 'list',
        'title'     => get_string('studentgradespage', 'local_ulms_dashboard'),
        'items'     => [],
        'emptytitle' => get_string('studentgradesummaryenrolled', 'local_ulms_dashboard'),
        'emptydesc'  => get_string('studentgradezeroenrolled', 'local_ulms_dashboard'),
    ]);
}

local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
