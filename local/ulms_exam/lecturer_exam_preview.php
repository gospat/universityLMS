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
$examid = max(0, (int)required_param('examid', PARAM_INT));
$routingservice->maybe_redirect_legacy_request('lecturer.examspreview');
$url = $routingservice->get_url_for_route('lecturer.examspreview', ['examid' => $examid]);
local_ulms_dashboard_prepare_page($context, $url, get_string('exampreviewheading', 'local_ulms_exam'));

$examservice = exam_service::instance();
$examservice->require_manage_exam($examid);
$exam = $examservice->get_exam($examid, false);
$seed = random_int(1, 2147483647);
$loaded = $examservice->get_exam_with_questions($examid, false, $seed);
$questions = $loaded['questions'];
$choicesbyq = $loaded['choices_by_question'];

$now = time();

try {
    $log = new stdClass();
    $log->examid = $examid;
    $log->previewseed = $seed;
    $DB->insert_record('local_ulms_user_management_log', (object)[
        'actorid' => (int)$USER->id,
        'targetuserid' => 0,
        'action' => 'EXAM_PREVIEW_LAUNCHED',
        'status' => 'ok',
        'message' => 'Lecturer launched exam preview (no submission persisted).',
        'detailsjson' => json_encode($log),
        'ipaddress' => substr((string)getremoteaddr(null), 0, 64),
        'timecreated' => $now,
    ]);
} catch (\Throwable $exception) { if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'lecturer_exam_preview::audit_questions', []); } /* Audit never breaks UX. */ }

$portalservice = new lecturer_portal_service();
echo $OUTPUT->header();
$headerctx = $portalservice->get_header_context_for_section('preview');
echo local_ulms_dashboard_render_page_header([
    'eyebrow' => 'EXAM PREVIEW',
    'title' => $headerctx['title'] ?? get_string('exampreviewheading', 'local_ulms_exam'),
    'meta' => $headerctx['meta'] ?? format_string($exam->title),
]);
local_ulms_dashboard_start_shell_wrap();

$wizardhtml = '<div class="card mb-4 shadow-sm p-3">
    <div class="d-flex align-items-center justify-content-between ulms-wizard-steps">
        <div class="d-flex align-items-center ulms-wizard-step ulms-wizard-step--done">
            <span class="ulms-wizard-badge">✓</span>
            <div class="ms-2">
                <div class="fw-semibold">Metadata</div>
                <div class="text-muted small">Title, programme, course</div>
            </div>
        </div>
        <div class="ulms-wizard-divider flex-grow-1 mx-3"></div>
        <div class="d-flex align-items-center ulms-wizard-step ulms-wizard-step--done">
            <span class="ulms-wizard-badge">✓</span>
            <div class="ms-2">
                <div class="fw-semibold">Timing &amp; Options</div>
                <div class="text-muted small">Window, duration, behaviour</div>
            </div>
        </div>
        <div class="ulms-wizard-divider flex-grow-1 mx-3"></div>
        <div class="d-flex align-items-center ulms-wizard-step ulms-wizard-step--active">
            <span class="ulms-wizard-badge">3</span>
            <div class="ms-2">
                <div class="fw-semibold">Preview</div>
                <div class="text-muted small">Verify correct answers highlighted</div>
            </div>
        </div>
    </div>
</div>';
echo $wizardhtml;

echo $OUTPUT->notification(get_string('previewnonpersist', 'local_ulms_exam'), \core\output\notification::NOTIFY_INFO);

$summary = '<div class="card mb-3 shadow-sm p-3">
    <div class="d-flex flex-wrap gap-3 align-items-center">
      <div><strong>'.get_string('examtitle', 'local_ulms_exam').':</strong> '.format_string($exam->title).'</div>
      <div class="ms-auto">
        <span class="ulms-badge ulms-badge--graded">Preview seed: '.$seed.'</span>
      </div>
    </div>
    <div class="text-muted small mt-1">
      '.format_text($exam->instructions ?? '').'
    </div>
</div>';

$qhtml = '';
foreach ($questions as $i => $q) {
    $ch = $choicesbyq[(int)$q->id] ?? [];
    $qtype = !empty($q->questiontype) ? (string)$q->questiontype : 'single';
    $qtypebadge = $qtype === 'multi'
        ? '<span class="ulms-badge ulms-badge--graded ms-2">'.get_string('questiontypemulti', 'local_ulms_exam').'</span>'
        : '<span class="ulms-badge ulms-badge--draft ms-2">'.get_string('questiontypesingle', 'local_ulms_exam').'</span>';
    $pointsbadge = '<span class="ulms-badge ulms-badge--draft ms-2">'.((int)($q->points ?? 1)).' '.get_string('points', 'local_ulms_exam').'</span>';
    $body = '<div class="d-flex flex-wrap align-items-center mb-3"><div class="fw-semibold">Q'.($i+1).'. '.format_text($q->stem_html ?? '').'</div><div class="ms-auto">'.$pointsbadge.$qtypebadge.'</div></div>';
    foreach ($ch as $j => $c) {
        $letter = chr(65 + ($j % 26));
        $iscorrect = !empty($c->iscorrect);
        $rowclass = $iscorrect ? ' ulms-choice-row--correct' : '';
        $body .= '<div class="ulms-choice-row input-group mb-2'.$rowclass.'">
            <span class="ulms-choice-letter ulms-choice-letter--badge" aria-hidden="true">'.$letter.'</span>
            <span class="input-group-text"><input class="form-check-input mt-0" type="'.($qtype === 'multi' ? 'checkbox' : 'radio').'" disabled id="p_'.(int)$q->id.'_'.$j.'"'.($iscorrect ? ' checked' : '').' aria-label="correct"></span>
            <label class="form-control form-label mb-0" for="p_'.(int)$q->id.'_'.$j.'">'.format_text($c->choice_html ?? '').'</label>
        </div>';
    }
    $qhtml .= '<div class="card mb-3 shadow-sm"><div class="card-body">'.$body.'</div></div>';
}

echo local_ulms_dashboard_render_panel([
    'style' => 'cards',
    'title' => get_string('exampreviewheading', 'local_ulms_exam'),
    'subtitle' => get_string('previewnonpersist', 'local_ulms_exam'),
    'items' => [['title' => format_string($exam->title), 'meta' => '', 'footer' => $summary.$qhtml]],
]);

local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
