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

$result = $examservice->list_student_exams((int)$USER->id);

echo $OUTPUT->header();
$headerctx = $portalservice->get_header_context_for_section('exams');
echo local_ulms_dashboard_render_page_header([
    'eyebrow' => 'EXAMS',
    'title' => $headerctx['title'] ?? get_string('studentexamslistheading', 'local_ulms_exam'),
    'meta' => $headerctx['meta'] ?? get_string('studentexamslistdesc', 'local_ulms_exam'),
]);
local_ulms_dashboard_start_shell_wrap();

if (empty($result['hasprogramme'])) {
    echo $OUTPUT->notification($result['friendly'] ?? get_string('noprogrammeassignederror', 'local_ulms_exam'), \core\output\notification::NOTIFY_WARNING);
    local_ulms_dashboard_end_shell_wrap();
    echo $OUTPUT->footer();
    exit;
}

$rows = $result['rows'];
$items = [];
if (!empty($rows)) {
    $now = time();
    $takeurl = $routingservice->get_url_for_route('student.examstake');
    $resulturl = $routingservice->get_url_for_route('student.examsresult');
    foreach ($rows as $e) {
        $enrolled = !empty($e->is_course_enrolled);
        $cardclass = $enrolled ? '' : ' ulms-exam-card--notenrolled';
        $isopen = $now >= (int)$e->start_ts && $now <= (int)$e->end_ts;
        $upcoming = $now < (int)$e->start_ts;
        $ended = $now > (int)$e->end_ts;
        $submitted = in_array($e->submissionstatus ?? '', ['submitted', 'late_rejected', 'graded'], true);
        $badgeclass = 'ulms-badge ulms-badge--draft';
        $badgelabel = get_string('examstatusdraft', 'local_ulms_exam');
        if (($e->status ?? '') === exam_service::STATUS_PUBLISHED) {
            if ($upcoming) { $badgeclass = 'ulms-badge ulms-badge--graded'; $badgelabel = 'Upcoming'; }
            elseif ($isopen) { $badgeclass = 'ulms-badge ulms-badge--published'; $badgelabel = 'Open now'; }
            elseif ($ended) { $badgeclass = 'ulms-badge ulms-badge--closed'; $badgelabel = 'Window closed'; }
        } elseif (($e->status ?? '') === exam_service::STATUS_CLOSED) {
            $badgeclass = 'ulms-badge ulms-badge--closed'; $badgelabel = get_string('examstatusclosed', 'local_ulms_exam');
        } elseif (($e->status ?? '') === exam_service::STATUS_GRADED) {
            $badgeclass = 'ulms-badge ulms-badge--graded'; $badgelabel = get_string('examstatusgraded', 'local_ulms_exam');
        }
        $meta = '<span class="'.$badgeclass.'">'.s($badgelabel).'</span>';
        if (!$enrolled) {
            $meta .= ' <span class="ulms-badge ulms-badge--notenrolled ms-1" title="'.s(get_string('coursenotenrolledtoast', 'local_ulms_exam')).'">'.s(get_string('notenrolledbadge', 'local_ulms_exam')).'</span>';
        }
        $meta .= '<div class="small text-muted mt-2">Open: '.userdate((int)$e->start_ts, get_string('strftimedatetime', 'core_langconfig')).'</div>';
        $meta .= '<div class="small text-muted">Close: '.userdate((int)$e->end_ts, get_string('strftimedatetime', 'core_langconfig')).'</div>';
        if ($submitted && !empty($e->scorepct)) {
            $meta .= '<div class="mt-1 small"><strong>Score:</strong> '.format_float($e->scorepct, 2).'%</div>';
        }
        $footer = '<div class="d-flex ulms-d-flex-gap-2 mt-2 flex-wrap">';
        if ($submitted && !empty($e->submissionid)) {
            $footer .= html_writer::link(
                new moodle_url($resulturl, ['submissionid' => (int)$e->submissionid]),
                get_string('resulttitle', 'local_ulms_exam'),
                ['class' => 'btn btn-sm btn-outline-primary']
            );
        } elseif (!$submitted && $isopen && ($e->status ?? '') === exam_service::STATUS_PUBLISHED && $enrolled) {
            $footer .= html_writer::link(
                new moodle_url($takeurl, ['examid' => (int)$e->id]),
                get_string('studentexamstakecrumb', 'local_ulms_exam'),
                ['class' => 'btn btn-sm btn-primary']
            );
        } elseif (!$submitted && $isopen && ($e->status ?? '') === exam_service::STATUS_PUBLISHED && !$enrolled) {
            $footer .= '<button type="button" class="btn btn-sm btn-outline-secondary" disabled title="'.s(get_string('coursenotenrollederror', 'local_ulms_exam')).'">'.get_string('notenrolledbadge', 'local_ulms_exam').'</button>';
        }
        $footer .= '</div>';
        $items[] = [
            'title' => format_string($e->title),
            'meta' => $meta,
            'footer' => $footer,
            'cardclass' => trim($cardclass),
        ];
    }
}

echo local_ulms_dashboard_render_panel([
    'style' => 'cards',
    'title' => get_string('exams', 'local_ulms_exam'),
    'subtitle' => get_string('studentexamslistdesc', 'local_ulms_exam'),
    'items' => $items,
    'emptytitle' => get_string('noexamsprogramme', 'local_ulms_exam'),
    'emptydesc' => get_string('studentexamslistdesc', 'local_ulms_exam'),
]);

local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
