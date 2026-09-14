<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/ulms_dashboard/lib.php');

use local_ulms_dashboard\local\service\lecturer_portal_service;
use local_ulms_auth\local\service\landing_page_service;
use local_ulms_exam\local\service\exam_service;

/** @var moodle_page $PAGE */
/** @var core_renderer $OUTPUT */
global $PAGE, $OUTPUT, $DB, $USER;

require_login();
$dashboard = new \local_ulms_dashboard\local\service\dashboard_service();
$dashboard->enforce_dashboard_access('lecturer');
$context = \context::instance_by_id(\context_system::instance()->id);
require_capability('local/ulms_dashboard:viewlecturerdashboard', $context);

$routingservice = new landing_page_service();
$routingservice->maybe_redirect_legacy_request('lecturer.exams');
$url = $routingservice->get_url_for_route('lecturer.exams');
local_ulms_dashboard_prepare_page($context, $url, get_string('examslistheading', 'local_ulms_exam'));

$examservice = exam_service::instance();
$portalservice = new lecturer_portal_service();

$message = '';
$warning = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = optional_param('action', '', PARAM_ALPHAEXT);
    $examid = max(0, (int)optional_param('examid', 0, PARAM_INT));
    try {
        if ($action === 'publish' && $examid > 0) {
            $examservice->publish_exam($examid);
            $message = get_string('exampublished', 'local_ulms_exam');
            redirect($url, $message, null, \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($action === 'close' && $examid > 0) {
            $examservice->close_exam($examid);
            $message = get_string('examclosed', 'local_ulms_exam');
            redirect($url, $message, null, \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($action === 'batchgrade' && $examid > 0) {
            $batchresult = $examservice->batch_autograde_exam($examid, 'button');
            $messagedata = new stdClass();
            $messagedata->graded = (int)($batchresult['graded'] ?? 0);
            $messagedata->already = (int)($batchresult['already'] ?? 0);
            $messagedata->rejected = (int)($batchresult['rejected'] ?? 0);
            $message = get_string('batchgraderesult', 'local_ulms_exam', $messagedata);
            redirect($url, $message, null, \core\output\notification::NOTIFY_SUCCESS);
        }
    } catch (\Throwable $exception) {
        if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'lecturer_exams::publish_close_batchgrade', []); }
        $warning = $exception->getMessage();
    }
}

$rows = $examservice->list_lecturer_exams_for_current_user();
$newexamurl = $routingservice->get_url_for_route('lecturer.examscreate');

echo $OUTPUT->header();
$header = $portalservice->get_header_context_for_section('exams');
echo local_ulms_dashboard_render_page_header([
    'eyebrow' => 'EXAMS',
    'title' => $header['title'] ?? get_string('examslistheading', 'local_ulms_exam'),
    'meta' => $header['meta'] ?? get_string('examslistdesc', 'local_ulms_exam'),
    'actions' => [[
        'label' => get_string('newexam', 'local_ulms_exam'),
        'url' => $newexamurl,
        'class' => 'btn btn-primary',
    ]],
]);
local_ulms_dashboard_start_shell_wrap();

if ($warning) {
    echo $OUTPUT->notification($warning, \core\output\notification::NOTIFY_WARNING);
}

$statusmap = [
    exam_service::STATUS_DRAFT => 'ulms-badge ulms-badge--draft',
    exam_service::STATUS_PUBLISHED => 'ulms-badge ulms-badge--published',
    exam_service::STATUS_CLOSED => 'ulms-badge ulms-badge--closed',
    exam_service::STATUS_GRADED => 'ulms-badge ulms-badge--graded',
];
$statuslabelmap = [
    exam_service::STATUS_DRAFT => get_string('examstatusdraft', 'local_ulms_exam'),
    exam_service::STATUS_PUBLISHED => get_string('examstatuspublished', 'local_ulms_exam'),
    exam_service::STATUS_CLOSED => get_string('examstatusclosed', 'local_ulms_exam'),
    exam_service::STATUS_GRADED => get_string('examstatusgraded', 'local_ulms_exam'),
];
$items = [];
foreach ($rows as $e) {
    $status = $e->status ?? 'draft';
    $qcount = (int)$DB->count_records_select('local_ulms_exam_questions', 'examid=:e AND isactive=1', ['e' => (int)$e->id]);
    $subcount = (int)$DB->count_records('local_ulms_exam_submissions', ['examid' => (int)$e->id]);
    $meta = '<span class="'.($statusmap[$status] ?? 'ulms-badge ulms-badge--draft').'">'.($statuslabelmap[$status] ?? s($status)).'</span>';
    if (!empty($e->start_ts)) {
        $meta .= '<div class="small text-muted mt-2">'.userdate((int)$e->start_ts, get_string('strftimedatetime', 'core_langconfig')).'</div>';
    }
    $qstr = $qcount === 1 ? '1 Question' : get_string('questionscount', 'local_ulms_exam', $qcount);
    $sstr = $subcount === 1 ? get_string('submissioncountone', 'local_ulms_exam') : get_string('submissionscount', 'local_ulms_exam', $subcount);
    $meta .= '<div class="small mt-1">'.$qstr.' · '.$sstr.'</div>';
    $footer = '';
    $footer .= html_writer::start_div('d-flex ulms-d-flex-gap-2 flex-wrap mt-2 align-items-center');
    $footer .= html_writer::link($routingservice->get_url_for_route('lecturer.examsedit', ['examid' => (int)$e->id]), get_string('edittitle', 'local_ulms_exam'), ['class' => 'btn btn-sm btn-outline-secondary']);
    $footer .= html_writer::link($routingservice->get_url_for_route('lecturer.examsquestions', ['examid' => (int)$e->id]), get_string('editquestions', 'local_ulms_exam'), ['class' => 'btn btn-sm btn-outline-secondary']);
    $footer .= html_writer::link($routingservice->get_url_for_route('lecturer.examspreview', ['examid' => (int)$e->id]), get_string('previewexam', 'local_ulms_exam'), ['class' => 'btn btn-sm btn-outline-primary']);
    $now = time();
    $grace = exam_service::WINDOW_GRACE_SEC;
    $windowclosed = !empty($e->end_ts) && $now > ((int)$e->end_ts + $grace);
    $canbatchgrade = ($status === exam_service::STATUS_PUBLISHED || $status === exam_service::STATUS_CLOSED)
        && $windowclosed
        && $status !== exam_service::STATUS_GRADED;
    $footer .= html_writer::start_div('ulms-card-actions--danger-group ms-auto ms-3 d-inline-flex gap-2');
    if ($canbatchgrade) {
        $confirmmsg = json_encode(get_string('batchgradeconfirm', 'local_ulms_exam'), JSON_UNESCAPED_UNICODE);
        $footer .= html_writer::start_tag('form', ['method' => 'post', 'action' => $url, 'class' => 'd-inline', 'onsubmit' => "return confirm($confirmmsg);"]) .
            '<input type="hidden" name="sesskey" value="'.sesskey().'"/>'.
            '<input type="hidden" name="action" value="batchgrade"/>'.
            '<input type="hidden" name="examid" value="'.(int)$e->id.'"/>'.
            '<button type="submit" class="btn btn-sm btn-primary" title="'.s(get_string('batchgradeprogress', 'local_ulms_exam')).'">'.get_string('batchgradebutton', 'local_ulms_exam').'</button>'.
            html_writer::end_tag('form');
    }
    if ($status === exam_service::STATUS_DRAFT) {
        $footer .= html_writer::start_tag('form', ['method' => 'post', 'action' => $url, 'class' => 'd-inline']) .
            '<input type="hidden" name="sesskey" value="'.sesskey().'"/>'.
            '<input type="hidden" name="action" value="publish"/>'.
            '<input type="hidden" name="examid" value="'.(int)$e->id.'"/>'.
            '<button type="submit" class="btn btn-sm btn-success">'.get_string('publish', 'local_ulms_exam').'</button>'.
            html_writer::end_tag('form');
    } elseif ($status === exam_service::STATUS_PUBLISHED) {
        $footer .= html_writer::start_tag('form', ['method' => 'post', 'action' => $url, 'class' => 'd-inline']) .
            '<input type="hidden" name="sesskey" value="'.sesskey().'"/>'.
            '<input type="hidden" name="action" value="close"/>'.
            '<input type="hidden" name="examid" value="'.(int)$e->id.'"/>'.
            '<button type="submit" class="btn btn-sm btn-outline-danger">'.get_string('close', 'local_ulms_exam').'</button>'.
            html_writer::end_tag('form');
    }
    $footer .= html_writer::end_div();
    $footer .= html_writer::end_div();
    $items[] = [
        'title' => format_string($e->title),
        'meta' => $meta,
        'footer' => $footer,
    ];
}

echo local_ulms_dashboard_render_panel([
    'style' => 'cards',
    'title' => get_string('exams', 'local_ulms_exam'),
    'subtitle' => get_string('examslistdesc', 'local_ulms_exam'),
    'items' => $items,
    'emptytitle' => get_string('noexamsyet', 'local_ulms_exam'),
    'emptydesc' => get_string('newexam', 'local_ulms_exam').' '.get_string('examslistdesc', 'local_ulms_exam'),
]);

local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
