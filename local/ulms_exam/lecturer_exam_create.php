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

$canmanageall = has_capability('local/ulms_exam:manageall', $context);
$hasmanageown = false;
if (!$canmanageall && !\is_siteadmin()) {
    $my = enrol_get_all_users_courses($USER->id, true, ['id'],'id ASC');
    foreach ($my as $c) {
        /** @var mixed $ctx */ $ctx = \context_course::instance($c->id, IGNORE_MISSING);
        if ($ctx && has_capability('local/ulms_exam:manageown', $ctx) && has_capability('moodle/course:manageactivities',$ctx)) { $hasmanageown = true; break; }
    }
    if (!$hasmanageown) {
        throw new moodle_exception('nopermissions','error','',null,'local/ulms_exam:manage{own,all} capability required for exam creation');
    }
}

$routingservice = new landing_page_service();
$examid = max(0, (int)optional_param('examid', 0, PARAM_INT));
$mode = $examid > 0 ? 'edit' : 'create';
$routekey = $mode === 'edit' ? 'lecturer.examsedit' : 'lecturer.examscreate';
$routingservice->maybe_redirect_legacy_request($routekey);
$url = $routingservice->get_url_for_route($routekey, $examid > 0 ? ['examid' => $examid] : []);
local_ulms_dashboard_prepare_page($context, $url, get_string('examcreateheading', 'local_ulms_exam'));

$examservice = exam_service::instance();
$portalservice = new lecturer_portal_service();

if ($examid > 0) {
    $examservice->require_manage_exam($examid);
    $exam = $examservice->get_exam($examid, false);
} else {
    $exam = (object)[
        'id' => 0, 'title' => '', 'instructions' => '',
        'programmeid' => 0, 'semesterid' => 0, 'courseid' => 0,
        'durationsec' => 0, 'start_ts' => 0, 'end_ts' => 0,
        'passpct' => 0.0, 'shufflequestions' => 0, 'shufflechoices' => 1, 'allowresume' => 1, 'status' => exam_service::STATUS_DRAFT,
    ];
}

$saved = false;
$notice = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $title = trim(required_param('title', PARAM_TEXT));
    $instructions = trim(optional_param('instructions', '', PARAM_RAW));
    $programmeid = max(0, (int)required_param('programmeid', PARAM_INT));
    $semesterid = max(0, (int)required_param('semesterid', PARAM_INT));
    $courseid = max(0, (int)required_param('courseid', PARAM_INT));
    $startraw = trim(required_param('start_ts', PARAM_RAW));
    $endraw = trim(required_param('end_ts', PARAM_RAW));
    $durationmin = max(0, (int)optional_param('durationmin', 0, PARAM_INT));
    $passpct = max(0, min(100, (float)optional_param('passpct', 0, PARAM_FLOAT)));
    $shufflequestions = optional_param('shufflequestions', 0, PARAM_BOOL) ? 1 : 0;
    $shufflechoices = optional_param('shufflechoices', 1, PARAM_BOOL) ? 1 : 0;
    $allowresume = optional_param('allowresume', 1, PARAM_BOOL) ? 1 : 0;

    $startts = is_numeric($startraw) ? (int)$startraw : (strtotime($startraw) ?: 0);
    $endts = is_numeric($endraw) ? (int)$endraw : (strtotime($endraw) ?: 0);

    try {
        $payload = [
            'title' => $title, 'instructions' => $instructions,
            'programmeid' => $programmeid, 'semesterid' => $semesterid, 'courseid' => $courseid,
            'start_ts' => $startts, 'end_ts' => $endts,
            'durationsec' => $durationmin * 60,
            'passpct' => $passpct,
            'shufflequestions' => $shufflequestions,
            'shufflechoices' => $shufflechoices,
            'allowresume' => $allowresume,
        ];
        if ($examid > 0) {
            $exam = $examservice->update_exam($examid, $payload);
            $notice = get_string('examsaved', 'local_ulms_exam');
            $nexturl = $routingservice->get_url_for_route('lecturer.exams');
            redirect($nexturl, $notice, null, \core\output\notification::NOTIFY_SUCCESS);
        } else {
            $exam = $examservice->create_exam($payload);
            $notice = get_string('examsaved', 'local_ulms_exam');
            $nexturl = $routingservice->get_url_for_route('lecturer.examsquestions', ['examid' => (int)$exam->id]);
            redirect($nexturl, $notice, null, \core\output\notification::NOTIFY_SUCCESS);
        }
    } catch (\Throwable $exception) {
        if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'lecturer_exam_create::save_new_exam', []); }
        $errors[] = $exception->getMessage();
    }
}

$scope = $examservice->get_programme_course_options_for_lecturer();

echo $OUTPUT->header();
$headsection = $mode === 'edit' ? 'edit' : 'exams';
$headerctx = $portalservice->get_header_context_for_section($headsection);
echo local_ulms_dashboard_render_page_header([
    'eyebrow' => $mode === 'edit' ? 'EDIT EXAM' : 'CREATE EXAM',
    'title' => $headerctx['title'] ?? ($mode === 'edit' ? get_string('exameditheading', 'local_ulms_exam') : get_string('examcreateheading', 'local_ulms_exam')),
    'meta' => $headerctx['meta'] ?? get_string('examcreatedesc', 'local_ulms_exam'),
]);
local_ulms_dashboard_start_shell_wrap();

$stepmetadata = 'active';
$steptiming = 'active';
$stepquestions = '';
$steppreview = '';
if ($examid > 0) {
    $steptiming = 'active';
    $stepquestions = '';
}
$wizardhtml = '<div class="card mb-4 shadow-sm p-3">
    <div class="d-flex align-items-center justify-content-between ulms-wizard-steps">
        <div class="d-flex align-items-center ulms-wizard-step ulms-wizard-step--'.$stepmetadata.'">
            <span class="ulms-wizard-badge">1</span>
            <div class="ms-2">
                <div class="fw-semibold">Metadata</div>
                <div class="text-muted small">Title, programme, course</div>
            </div>
        </div>
        <div class="ulms-wizard-divider flex-grow-1 mx-3"></div>
        <div class="d-flex align-items-center ulms-wizard-step ulms-wizard-step--'.$steptiming.'">
            <span class="ulms-wizard-badge">2</span>
            <div class="ms-2">
                <div class="fw-semibold">Timing &amp; Options</div>
                <div class="text-muted small">Window, duration, behaviour</div>
            </div>
        </div>
        <div class="ulms-wizard-divider flex-grow-1 mx-3"></div>
        <div class="d-flex align-items-center ulms-wizard-step ulms-wizard-step--'.$stepquestions.'">
            <span class="ulms-wizard-badge">3</span>
            <div class="ms-2">
                <div class="fw-semibold">Questions</div>
                <div class="text-muted small">MCQs with correct answers</div>
            </div>
        </div>
    </div>
</div>';
echo $wizardhtml;

if (!empty($errors)) {
    foreach ($errors as $e) {
        echo $OUTPUT->notification($e, \core\output\notification::NOTIFY_WARNING);
    }
}

if (($exam->status ?? 'draft') !== exam_service::STATUS_DRAFT) {
    echo $OUTPUT->notification(get_string('editlockedquestions', 'local_ulms_exam'), \core\output\notification::NOTIFY_WARNING);
}

$defaultstart = !empty($exam->start_ts) ? date('Y-m-d\TH:i', (int)$exam->start_ts) : '';
$defaultend = !empty($exam->end_ts) ? date('Y-m-d\TH:i', (int)$exam->end_ts) : '';
$defaultdurmin = !empty($exam->durationsec) ? (int)round((int)$exam->durationsec / 60) : 0;

$programmeopts = [0 => get_string('selectprogramme', 'local_ulms_exam')] + ($scope['programmes'] ?? []);
$semesteropts = [0 => get_string('selectsemester', 'local_ulms_exam')] + ($scope['semesters'] ?? []);
$courseopts = [0 => get_string('selectcourse', 'local_ulms_exam')] + ($scope['courses'] ?? []);

$form = '';
$form .= html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $url,
    'data-ulms-loading-form' => '1',
    'class' => 'needs-validation card p-4 shadow-sm',
    'novalidate' => 'novalidate',
]);
$form .= '<input type="hidden" name="sesskey" value="'.sesskey().'"/>';

$f = fn(string $label, string $control, ?string $hint = null): string =>
    '<div class="mb-3">' .
    html_writer::label($label, '', false, ['class' => 'form-label fw-medium']) .
    $control .
    ($hint !== null ? '<div class="form-text text-muted small">' . $hint . '</div>' : '') .
    '</div>';

$form .= $f(get_string('examtitle', 'local_ulms_exam'),
    '<input type="text" class="form-control" name="title" id="title" maxlength="255" required value="'.s($exam->title ?? '').'">',
);
$form .= $f(get_string('examinstructions', 'local_ulms_exam'),
    '<textarea class="form-control" rows="4" name="instructions" id="instructions">'.s($exam->instructions ?? '').'</textarea>',
);
$form .= '<div class="row g-3 mb-3">';
$form .= '<div class="col-md-4">'. $f(get_string('examprogramme', 'local_ulms_exam'),
    html_writer::select($programmeopts, 'programmeid', (int)($exam->programmeid ?? 0), false, ['class' => 'form-select', 'required' => 'required'])
) . '</div>';
$form .= '<div class="col-md-4">'. $f(get_string('examsemester', 'local_ulms_exam'),
    html_writer::select($semesteropts, 'semesterid', (int)($exam->semesterid ?? 0), false, ['class' => 'form-select', 'required' => 'required'])
) . '</div>';
$form .= '<div class="col-md-4">'. $f(get_string('examcourse', 'local_ulms_exam'),
    html_writer::select($courseopts, 'courseid', (int)($exam->courseid ?? 0), false, ['class' => 'form-select', 'required' => 'required'])
) . '</div>';
$form .= '</div>';

$form .= '<div class="row g-3 mb-3">';
$form .= '<div class="col-md-5">'. $f(get_string('examstart', 'local_ulms_exam'),
    '<input type="datetime-local" class="form-control" name="start_ts" id="start_ts" required value="'.s($defaultstart).'">',
) . '</div>';
$form .= '<div class="col-md-5">'. $f(get_string('examend', 'local_ulms_exam'),
    '<input type="datetime-local" class="form-control" name="end_ts" id="end_ts" required value="'.s($defaultend).'">',
    get_string('exammaxtimewindowdesc', 'local_ulms_exam')
) . '</div>';
$form .= '<div class="col-md-2">'. $f(get_string('examduration', 'local_ulms_exam'),
    '<input type="number" class="form-control" name="durationmin" id="durationmin" min="0" step="1" value="'.(int)$defaultdurmin.'">',
) . '</div>';
$form .= '</div>';

$form .= '<div class="row g-3 mb-4">';
$form .= '<div class="col-md-4">'. $f(get_string('exampasspct', 'local_ulms_exam'),
    '<input type="number" class="form-control" name="passpct" min="0" max="100" step="0.01" value="'.number_format((float)($exam->passpct ?? 0.0), 2).'">',
) . '</div>';
$form .= '<div class="col-md-8 pt-3">';
$cb = fn(string $name, string $label, bool $checked, string $hint): string =>
    '<div class="form-check form-switch mb-2">'.
    '<input class="form-check-input" type="checkbox" role="switch" name="'.s($name).'" id="cb_'.s($name).'"'.($checked ? ' checked' : '').'>'.
    '<label class="form-check-label" for="cb_'.s($name).'">'.s($label).'</label>'.
    '<div class="form-text text-muted small">'.s($hint).'</div>'.
    '</div>';
$form .= $cb('shufflequestions', get_string('examshufflequestions', 'local_ulms_exam'), !empty($exam->shufflequestions), 'Randomise the order questions appear per attempt.');
$form .= $cb('shufflechoices', get_string('examshufflechoices', 'local_ulms_exam'), !empty($exam->shufflechoices), 'Randomise the order of answer options per question per attempt.');
$form .= $cb('allowresume', get_string('examallowresume', 'local_ulms_exam'), !empty($exam->allowresume), 'Students can resume an in-progress attempt if they navigate away before submitting.');
$form .= '</div></div>';

$form .= '<div class="d-flex ulms-d-flex-gap-2 flex-wrap justify-content-between">';
$form .= '<a href="'.$routingservice->get_url_for_route('lecturer.exams').'" class="btn btn-outline-secondary">'.get_string('cancel').'</a>';
$form .= '<button type="submit" class="btn btn-primary px-4">'.get_string('saveexam', 'local_ulms_exam').'</button>';
$form .= '</div>';
$form .= html_writer::end_tag('form');

echo local_ulms_dashboard_render_panel([
    'style' => 'cards',
    'title' => $mode === 'edit' ? get_string('exameditheading', 'local_ulms_exam') : get_string('examcreateheading', 'local_ulms_exam'),
    'subtitle' => get_string('examcreatedesc', 'local_ulms_exam'),
    'items' => [['title' => get_string('examcreateheading', 'local_ulms_exam'), 'meta' => '', 'footer' => $form]],
]);

local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
