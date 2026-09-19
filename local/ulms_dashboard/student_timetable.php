<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/ulms_dashboard/lib.php');

global $PAGE, $OUTPUT, $DB, $USER;

require_login();

$dashboardservice = new \local_ulms_dashboard\local\service\dashboard_service();
$dashboardservice->enforce_dashboard_access('student');
$routingservice = new \local_ulms_auth\local\service\landing_page_service();
$routingservice->maybe_redirect_legacy_request('student.timetable');

$context = \context::instance_by_id(\context_system::instance()->id);
require_capability('local/ulms_dashboard:viewstudentdashboard', $context);

$url = $routingservice->get_url_for_route('student.timetable');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('studenttimetabletitle', 'local_ulms_dashboard'));
$PAGE->set_heading(get_string('studenttimetabletitle', 'local_ulms_dashboard'));
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

function timetable_classify_session(array $session, int $week_monday_ts, int $now_ts): string {
    $weekday = (int)($session['weekday'] ?? 0);
    $startmin = (int)($session['start_minutes'] ?? 0);
    $durmin = (int)($session['duration_minutes'] ?? 0);
    $session_start_ts = $week_monday_ts + (($weekday - 1) * 86400) + ($startmin * 60);
    $session_end_ts = $session_start_ts + ($durmin * 60);

    if ($now_ts >= $session_start_ts && $now_ts < $session_end_ts) {
        return 'live';
    }
    if ($now_ts >= $session_end_ts) {
        return 'completed';
    }
    if ($session_start_ts > $now_ts) {
        $diff = $session_start_ts - $now_ts;
        if ($diff <= 3600) {
            return 'starting';
        }
        return 'upcoming';
    }
    return 'upcoming';
}

function timetable_session_is_missed(array $session, int $week_monday_ts, int $now_ts): bool {
    $weekday = (int)($session['weekday'] ?? 0);
    $startmin = (int)($session['start_minutes'] ?? 0);
    $durmin = (int)($session['duration_minutes'] ?? 0);
    $session_end_ts = $week_monday_ts + (($weekday - 1) * 86400) + (($startmin + $durmin) * 60);
    $locmode = (string)($session['location_mode'] ?? 'physical');
    $dmode = (string)($session['delivery_mode'] ?? 'lecture');
    if ($locmode === 'online' || $dmode === 'online_live') {
        return false;
    }
    return $now_ts > ($session_end_ts + (15 * 60));
}

function timetable_status_css(string $status): string {
    static $map = [
        'live'       => 'ulms-timetablestatus ulms-timetablestatus--live',
        'starting'   => 'ulms-timetablestatus ulms-timetablestatus--starting',
        'upcoming'   => 'ulms-timetablestatus ulms-timetablestatus--upcoming',
        'completed'  => 'ulms-timetablestatus ulms-timetablestatus--completed',
        'missed'     => 'ulms-timetablestatus ulms-timetablestatus--missed',
        'cancelled'  => 'ulms-timetablestatus ulms-timetablestatus--cancelled',
    ];
    return $map[$status] ?? $map['upcoming'];
}

function timetable_status_lang(string $status): string {
    static $map = [
        'live'       => 'studenttimetablestatuslive',
        'starting'   => 'studenttimetablestatusstarting',
        'upcoming'   => 'studenttimetablestatusupcoming',
        'completed'  => 'studenttimetablestatuscompleted',
        'missed'     => 'studenttimetablestatusmissed',
        'cancelled'  => 'studenttimetablestatuscancelled',
    ];
    $key = $map[$status] ?? $map['upcoming'];
    return get_string($key, 'local_ulms_dashboard');
}

function timetable_delivery_label(string $mode): string {
    static $map = [
        'lecture'     => 'studenttimetabledeliverylecture',
        'tutorial'    => 'studenttimetabledeliverytutorial',
        'lab'         => 'studenttimetabledeliverylab',
        'seminar'     => 'studenttimetabledeliveryseminar',
        'workshop'    => 'studenttimetabledeliveryworkshop',
        'office_hour' => 'studenttimetabledeliveryofficehour',
        'online_live' => 'studenttimetabledeliveryonlinelive',
    ];
    $key = $map[$mode] ?? $map['lecture'];
    return get_string($key, 'local_ulms_dashboard');
}

function timetable_delivery_css(string $mode): string {
    static $map = [
        'lecture'     => 'ulms-timetablemeta ulms-timetablemeta--delivery-lecture',
        'tutorial'    => 'ulms-timetablemeta ulms-timetablemeta--delivery-tutorial',
        'lab'         => 'ulms-timetablemeta ulms-timetablemeta--delivery-lab',
        'seminar'     => 'ulms-timetablemeta ulms-timetablemeta--delivery-seminar',
        'workshop'    => 'ulms-timetablemeta ulms-timetablemeta--delivery-workshop',
        'office_hour' => 'ulms-timetablemeta ulms-timetablemeta--delivery-officehour',
        'online_live' => 'ulms-timetablemeta ulms-timetablemeta--delivery-onlinelive',
    ];
    return $map[$mode] ?? $map['lecture'];
}

function timetable_format_timerange(int $startmin, int $durmin): string {
    $sh = intdiv($startmin, 60);
    $sm = $startmin % 60;
    $endmin = $startmin + $durmin;
    $eh = intdiv($endmin, 60);
    $em = $endmin % 60;
    return sprintf('%02d:%02d–%02d:%02d', $sh, $sm, $eh, $em);
}

function timetable_weekday_lang(int $wd): string {
    static $map = [
        1 => 'studenttimetableweekdaymon',
        2 => 'studenttimetableweekdaytue',
        3 => 'studenttimetableweekdaywed',
        4 => 'studenttimetableweekdaythu',
        5 => 'studenttimetableweekdayfri',
        6 => 'studenttimetableweekdaysat',
        7 => 'studenttimetableweekdaysun',
    ];
    $key = $map[$wd] ?? $map[1];
    return get_string($key, 'local_ulms_dashboard');
}

function timetable_count_badge_html(array $counts): string {
    $parts = [];
    $order = ['live', 'missed', 'starting', 'upcoming', 'completed'];
    $labels = [
        'live'      => ['s' => 'studenttimetablecountlive',      'p' => 'studenttimetablecountliveplural',      'c' => 'ulms-timetablestatus ulms-timetablestatus--live'],
        'missed'    => ['s' => 'studenttimetablecountmissed',    'p' => 'studenttimetablecountmissedplural',    'c' => 'ulms-timetablestatus ulms-timetablestatus--missed'],
        'starting'  => ['s' => 'studenttimetablecountstarting',  'p' => 'studenttimetablecountstartingplural',  'c' => 'ulms-timetablestatus ulms-timetablestatus--starting'],
        'upcoming'  => ['s' => 'studenttimetablecountupcoming',  'p' => 'studenttimetablecountupcomingplural',  'c' => 'ulms-timetablestatus ulms-timetablestatus--upcoming'],
        'completed' => ['s' => 'studenttimetablecountcompleted', 'p' => 'studenttimetablecountcompletedplural', 'c' => 'ulms-timetablestatus ulms-timetablestatus--completed'],
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
        $parts[] = html_writer::tag('span', get_string('studenttimetablecountzero', 'local_ulms_dashboard'),
            ['class' => 'ulms-timetablestatus ulms-timetablestatus--cancelled']);
    }
    return implode("\n", $parts);
}

$weekstartparam = optional_param('weekstart', 0, PARAM_INT);
if ($weekstartparam > 0) {
    $monday_ts = $weekstartparam;
} else {
    $dow = (int)idate('N');
    $monday_ts = strtotime(sprintf('-%d days midnight', max(0, $dow - 1)));
}

$prevweek = $monday_ts - (7 * 86400);
$nextweek = $monday_ts + (7 * 86400);
$currenturl = new moodle_url($routingservice->get_url_for_route('student.timetable'));
$prevurl = new moodle_url($currenturl, ['weekstart' => $prevweek]);
$nexturl = new moodle_url($currenturl, ['weekstart' => $nextweek]);

$service = \local_ulms_dashboard\local\service\schedule_service::instance();
$cells = $service->get_timetable_cells_for_user((int)$USER->id, 'student', $monday_ts);

$sessions_by_course = [];
foreach ($cells as $c) {
    $cid = (int)($c['moodlecourseid'] ?? 0);
    if ($cid <= 0) { continue; }
    $sessions_by_course[$cid][] = $c;
}

$now_ts = time();
$kpiWeekSessions = count($cells);
$kpiLiveNow = 0;
$kpiNext24h = 0;
$kpiOnlineCount = 0;
$kpiPhysicalCount = 0;
$liveJoinUrls = [];

foreach ($cells as $c) {
    $status = timetable_classify_session($c, $monday_ts, $now_ts);
    $weekday = (int)($c['weekday'] ?? 0);
    $startmin = (int)($c['start_minutes'] ?? 0);
    $durmin = (int)($c['duration_minutes'] ?? 0);
    $locmode = (string)($c['location_mode'] ?? 'physical');

    if ($locmode === 'online') {
        $kpiOnlineCount++;
    } else {
        $kpiPhysicalCount++;
    }

    if ($status === 'live') {
        $kpiLiveNow++;
        if ($locmode === 'online') {
            $join = $service->resolve_join_url((int)$c['id'], 'student');
            if (!empty($join['url'])) {
                $liveJoinUrls[(int)$c['id']] = $join;
            }
        }
    }

    $session_start_ts = $monday_ts + (($weekday - 1) * 86400) + ($startmin * 60);
    $diff = $session_start_ts - $now_ts;
    if ($diff > 0 && $diff <= 86400) {
        $kpiNext24h++;
    }
}

$carditems = [];

foreach ($allcourses as $cid => $course) {
    $sessions = $sessions_by_course[$cid] ?? [];
    usort($sessions, function ($a, $b) {
        $wa = (int)($a['weekday'] ?? 0);
        $wb = (int)($b['weekday'] ?? 0);
        if ($wa !== $wb) { return $wa - $wb; }
        return ((int)($a['start_minutes'] ?? 0)) - ((int)($b['start_minutes'] ?? 0));
    });

    $counts = ['live'=>0,'missed'=>0,'starting'=>0,'upcoming'=>0,'completed'=>0];
    $assessmentrows = [];
    $onlineCount = 0;
    $physicalCount = 0;

    foreach ($sessions as $c) {
        $locmode = (string)($c['location_mode'] ?? 'physical');
        if ($locmode === 'online') { $onlineCount++; } else { $physicalCount++; }
        $status = timetable_classify_session($c, $monday_ts, $now_ts);
        if ($status === 'upcoming' && timetable_session_is_missed($c, $monday_ts, $now_ts)) {
            $status = 'missed';
        }
        $counts[$status]++;
        $sid = (int)$c['id'];
        $title = format_string($c['title'] ?? '');
        $dmode = (string)($c['delivery_mode'] ?? 'lecture');
        $startmin = (int)($c['start_minutes'] ?? 0);
        $durmin = (int)($c['duration_minutes'] ?? 0);
        $weekday = (int)($c['weekday'] ?? 0);
        $loclabel = (string)($c['location_label'] ?? '');

        $metaParts = [];
        $metaParts[] = html_writer::tag('span', timetable_delivery_label($dmode),
            ['class' => timetable_delivery_css($dmode)]);
        $timestr = timetable_weekday_lang($weekday) . ' · ' . timetable_format_timerange($startmin, $durmin);
        $metaParts[] = html_writer::tag('span', $timestr,
            ['class' => 'ulms-timetablemeta ulms-timetablemeta--time']);

        if ($locmode === 'online') {
            $joinHtml = '';
            if ($status === 'live' && isset($liveJoinUrls[$sid])) {
                $j = $liveJoinUrls[$sid];
                $joinHtml = html_writer::tag('a', get_string('studenttimetablejoinlive', 'local_ulms_dashboard'), [
                    'href'   => $j['url'],
                    'target' => $j['target'],
                    'rel'    => $j['rel'],
                    'class'  => 'ulms-timetablemeta ulms-timetablemeta--joinlive',
                    'style'  => 'display:inline-flex;align-items:center;justify-content:center;min-width:44px;min-height:44px;padding:.3rem .7rem;text-decoration:none;',
                ]);
            } else {
                $joinHtml = html_writer::tag('span', get_string('studenttimetablelocationonline', 'local_ulms_dashboard'),
                    ['class' => 'ulms-timetablemeta ulms-timetablemeta--location']);
            }
            $metaParts[] = $joinHtml;
        } else {
            $metaParts[] = html_writer::tag('span', $loclabel !== '' ? $loclabel : get_string('studenttimetablelocationphysical', 'local_ulms_dashboard'),
                ['class' => 'ulms-timetablemeta ulms-timetablemeta--location']);
        }

        $metaParts[] = html_writer::tag('span', timetable_status_lang($status),
            ['class' => timetable_status_css($status)]);

        $wrappedMeta = '<div style="display:flex;flex-direction:column;gap:.35rem;">'
            . '<div style="display:flex;flex-wrap:wrap;gap:.3rem;">' . implode(' · ', $metaParts) . '</div>'
            . '</div>';

        $assessmentrows[] = [
            'title'      => $title,
            'meta'       => $wrappedMeta,
            'meta_raw'   => true,
        ];
    }

    $badgehtml = timetable_count_badge_html($counts);
    if ($onlineCount > 0) {
        $badgehtml .= "\n" . html_writer::tag('span',
            get_string('studenttimetablecountonlineplural', 'local_ulms_dashboard', (string)$onlineCount),
            ['class' => 'ulms-timetablemeta ulms-timetablemeta--location']);
    }
    if ($physicalCount > 0) {
        $badgehtml .= "\n" . html_writer::tag('span',
            get_string('studenttimetablecountphysicalplural', 'local_ulms_dashboard', (string)$physicalCount),
            ['class' => 'ulms-timetablemeta ulms-timetablemeta--delivery-lab']);
    }

    $shortname = $course['shortname'];
    $fullname = $course['fullname'];
    $coursetype = 'Core';
    $semesterlabel = 'First Semester 2026/27';
    $subtitlehtml = html_writer::tag('span', $shortname) . ' · ' . $coursetype . ' · ' . $semesterlabel;

    $carditems[] = [
        'title'        => $fullname,
        'title_url'    => $course['courseurl'],
        'meta'         => $subtitlehtml,
        'meta_raw'     => true,
        'badgehtml'    => $badgehtml,
        'assignments'  => $assessmentrows,
        'sublistempty' => get_string('studenttimetablecourseempty', 'local_ulms_dashboard'),
    ];
}

$next24hValue = $kpiNext24h === 0
    ? get_string('studenttimetablecountzero', 'local_ulms_dashboard')
    : (string)$kpiNext24h;
$deliveryValue = ($kpiOnlineCount === 0 && $kpiPhysicalCount === 0)
    ? get_string('studenttimetablecountzero', 'local_ulms_dashboard')
    : get_string('studenttimetablesummarydeliveryformat', 'local_ulms_dashboard', (object)[
        'online'   => (string)$kpiOnlineCount,
        'physical' => (string)$kpiPhysicalCount,
    ]);

$summarycards = [
    [
        'label'       => get_string('studenttimetablesummaryweek', 'local_ulms_dashboard'),
        'value'       => (string)$kpiWeekSessions,
        'description' => get_string('studenttimetablesummaryweekdesc', 'local_ulms_dashboard',
            userdate($monday_ts, get_string('strftimedatefullshort', 'langconfig'))),
    ],
    [
        'label'       => get_string('studenttimetablesummarylive', 'local_ulms_dashboard'),
        'value'       => $kpiLiveNow === 0
            ? get_string('studenttimetablecountzero', 'local_ulms_dashboard')
            : (string)$kpiLiveNow,
        'description' => get_string('studenttimetablesummarylivedesc', 'local_ulms_dashboard'),
    ],
    [
        'label'       => get_string('studenttimetablesummarynext24', 'local_ulms_dashboard'),
        'value'       => $next24hValue,
        'description' => get_string('studenttimetablesummarynext24desc', 'local_ulms_dashboard'),
    ],
    [
        'label'       => get_string('studenttimetablesummarydelivery', 'local_ulms_dashboard'),
        'value'       => $deliveryValue,
        'description' => get_string('studenttimetablesummarydeliverydesc', 'local_ulms_dashboard'),
    ],
];

$weeknavhtml = '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:16px;">'
    . '<div style="font-weight:600;color:#0f4c81;">'
    . get_string('studenttimetableweekof', 'local_ulms_dashboard', date('M j, Y', $monday_ts))
    . '</div>'
    . '<div style="display:flex;gap:8px;">'
    . '<a href="' . s($prevurl->out(false)) . '" class="ulms-btn" style="min-width:44px;min-height:44px;display:inline-flex;align-items:center;justify-content:center;">'
    . get_string('studenttimetableprevweek', 'local_ulms_dashboard')
    . '</a>'
    . '<a href="' . s($nexturl->out(false)) . '" class="ulms-btn" style="min-width:44px;min-height:44px;display:inline-flex;align-items:center;justify-content:center;">'
    . get_string('studenttablenextweek', 'local_ulms_dashboard')
    . '</a>'
    . '</div></div>';

echo $OUTPUT->header();
$serviceheader = new \local_ulms_dashboard\local\service\student_portal_service();
echo local_ulms_dashboard_render_page_header($serviceheader->get_header_context_for_section('timetable'));
local_ulms_dashboard_start_shell_wrap();

echo $weeknavhtml;

echo local_ulms_dashboard_render_summary_cards($summarycards);

if (!empty($carditems)) {
    echo local_ulms_dashboard_render_panel([
        'style'    => 'coursegroups',
        'eyebrow'  => get_string('studenttimetablegroupedeyebrow', 'local_ulms_dashboard'),
        'title'    => get_string('studenttimetablegroupedtitle', 'local_ulms_dashboard'),
        'subtitle' => get_string('studenttimetablegroupeddesc', 'local_ulms_dashboard'),
        'items'    => $carditems,
    ]);
} else {
    echo local_ulms_dashboard_render_panel([
        'style'      => 'list',
        'title'      => get_string('studenttimetabletitle', 'local_ulms_dashboard'),
        'items'      => [],
        'emptytitle' => get_string('studenttimetableempty', 'local_ulms_dashboard'),
        'emptydesc'  => get_string('studenttimetableemptydesc', 'local_ulms_dashboard'),
    ]);
}

local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
