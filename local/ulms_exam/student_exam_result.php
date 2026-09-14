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

$routingservice = new landing_page_service();
$submissionid = max(0, (int)required_param('submissionid', PARAM_INT));
$routingservice->maybe_redirect_legacy_request('student.examsresult');
$url = $routingservice->get_url_for_route('student.examsresult', ['submissionid' => $submissionid]);
local_ulms_dashboard_prepare_page($context, $url, get_string('studentexamsresultcrumb', 'local_ulms_exam'));

$examservice = exam_service::instance();
$portalservice = new student_portal_service();

$result = $examservice->get_result((int)$USER->id, $submissionid);
if (!$result) {
    $backurl = $routingservice->get_url_for_route('student.exams');
    throw new moodle_exception('invalidexamresult', 'local_ulms_exam', $backurl->out(false));
}
$exam = $result['exam'];
$submission = $result['submission'];
$questions = $result['questions'];
$allchoicesbyqid = [];
foreach ($questions as $q) {
    $qid = (int)$q->id;
    $ch = $DB->get_records('local_ulms_question_choices', ['examquestionid' => $qid], 'ordernum ASC');
    $letterbycid = [];
    $j = 0;
    foreach ($ch as $crow) {
        $letterbycid[(int)$crow->id] = chr(65 + ($j % 26));
        $j++;
    }
    $allchoicesbyqid[$qid] = ['rows' => $ch, 'letterbycid' => $letterbycid];
}
$selectedbyqid = $result['selected_ids_by_qid'] ?? [];
$correctbyqid = $result['correct_ids_by_qid'] ?? [];
$answersdenorm = $result['answers_by_qid'] ?? [];
$scorebyqid = [];
foreach ($answersdenorm as $qid => $rows) {
    $score = 0.0;
    foreach ($rows as $r) {
        if (!empty($r->is_correct_denorm)) {
            $score = max($score, (float)($r->score_points_denorm ?? 0.0));
        }
    }
    if (empty($score) && !empty($rows)) {
        $first = reset($rows);
        $score = (float)($first->score_points_denorm ?? 0.0);
    }
    $scorebyqid[(int)$qid] = $score;
}

echo $OUTPUT->header();
$headctx = $portalservice->get_header_context_for_section('result');
$subctx = new stdClass();
$subctx->points = (float)($submission->score_points ?? 0);
$subctx->total = (float)($submission->score_total_points ?? 0);
$subctx->percent = (float)($submission->score_percent ?? 0);

echo local_ulms_dashboard_render_page_header([
    'eyebrow' => 'EXAM RESULT',
    'title' => format_string($exam->title),
    'meta' => get_string('resultscoreline', 'local_ulms_exam', $subctx),
]);
local_ulms_dashboard_start_shell_wrap();

$badge = 'bg-light text-dark';
$statuslabel = s($submission->status ?? 'unknown');
if (($submission->status ?? '') === exam_service::SUBMISSION_SUBMITTED) { $badge = 'bg-success'; $statuslabel = 'Submitted'; }
elseif (($submission->status ?? '') === exam_service::SUBMISSION_GRADED) { $badge = 'bg-info'; $statuslabel = 'Graded'; }
elseif (($submission->status ?? '') === exam_service::SUBMISSION_LATE_REJECTED) { $badge = 'bg-danger'; $statuslabel = 'Rejected (late)'; }

$summarycards = [
    [
        'label' => 'Score',
        'value' => number_format((float)($submission->score_percent ?? 0), 2) . '%',
        'description' => format_float((float)($submission->score_points ?? 0), 2) . ' / ' . format_float((float)($submission->score_total_points ?? 0), 2) . ' points',
    ],
    [
        'label' => 'Status',
        'value' => $statuslabel,
        'description' => '<span class="badge '.$badge.'">'.$statuslabel.'</span>',
    ],
    [
        'label' => 'Submitted',
        'value' => !empty($submission->time_submitted) ? userdate((int)$submission->time_submitted, get_string('strftimedatetime', 'core_langconfig')) : '-',
        'description' => '',
    ],
];
echo local_ulms_dashboard_render_summary_cards($summarycards);

$body = '';
foreach ($questions as $qi => $q) {
    $qid = (int)$q->id;
    $qtype = !empty($q->questiontype) ? (string)$q->questiontype : 'single';
    $qtypebadge = $qtype === 'multi'
        ? '<span class="ulms-badge ulms-badge--graded ms-2" title="'.s(get_string('questiontypemultidescr', 'local_ulms_exam')).'">'.s(get_string('questiontypemulti', 'local_ulms_exam')).'</span>'
        : '';
    $selectedids = $selectedbyqid[$qid] ?? [];
    $correctids = $correctbyqid[$qid] ?? [];
    $selectedset = [];
    foreach ($selectedids as $cv) { $selectedset[(int)$cv] = true; }
    $correctset = [];
    foreach ($correctids as $cv) { $correctset[(int)$cv] = true; }
    sort($selectedids, SORT_NUMERIC);
    sort($correctids, SORT_NUMERIC);
    $scorepts = (float)($scorebyqid[$qid] ?? 0.0);
    $isok = !empty($correctids) && !empty($selectedids) && $selectedids === $correctids;
    if ($qtype === 'single' && count($correctids) === 1 && count($selectedids) === 1 && $selectedids[0] === $correctids[0]) $isok = true;
    $headerbg = $isok ? 'ulms-result-card ulms-result-card--correct' : 'ulms-result-card ulms-result-card--incorrect';
    $icon = $isok ? '✓ ' : '✗ ';
    $lettermap = $allchoicesbyqid[$qid]['letterbycid'] ?? [];
    $selectedletters = [];
    foreach ($selectedids as $cid) { if (isset($lettermap[$cid])) $selectedletters[] = $lettermap[$cid]; }
    $correctletters = [];
    foreach ($correctids as $cid) { if (isset($lettermap[$cid])) $correctletters[] = $lettermap[$cid]; }
    $choices = $allchoicesbyqid[$qid]['rows'] ?? [];
    $chhtml = '';
    $j = 0;
    foreach ($choices as $c) {
        $cid = (int)$c->id;
        $letter = chr(65 + ($j % 26));
        $iscorrectrow = isset($correctset[$cid]);
        $isselectedrow = isset($selectedset[$cid]);
        $classes = 'ulms-choice-row input-group mb-2 ';
        if ($iscorrectrow && $isselectedrow) $classes .= ' ulms-choice-row--correct';
        elseif ($iscorrectrow && !$isselectedrow) $classes .= ' ulms-choice-row--correctmissed';
        elseif (!$iscorrectrow && $isselectedrow) $classes .= ' ulms-choice-row--incorrect';
        $rowbadges = '';
        if ($iscorrectrow) $rowbadges .= ' <span class="ulms-badge ulms-badge--published ms-2 small">'.s(get_string('correct')).'</span>';
        if ($isselectedrow && !$iscorrectrow) $rowbadges .= ' <span class="ulms-badge ulms-badge--closed ms-2 small">'.s(get_string('resultincorrect', 'local_ulms_exam')).'</span>';
        elseif ($isselectedrow) $rowbadges .= ' <span class="ulms-badge ulms-badge--graded ms-2 small">Your answer</span>';
        $chhtml .= '<div class="'.$classes.'">
            <span class="ulms-choice-letter ulms-choice-letter--badge" aria-hidden="true">'.$letter.'</span>
            <div class="form-control form-label mb-0 d-flex align-items-center justify-content-between flex-wrap gap-2">
              <span>'.format_text($c->choice_html ?? '').'</span>
              <span>'.$rowbadges.'</span>
            </div>
        </div>';
        $j++;
    }
    $badgerow = '';
    if (!empty($selectedletters)) {
        $badgerow .= '<div class="mt-3 mb-2 small"><strong class="me-2">You selected:</strong>';
        foreach ($selectedletters as $L) { $badgerow .= '<span class="ulms-choice-letter ulms-choice-letter--badge ms-1">'.$L.'</span>'; }
        $badgerow .= '</div>';
    }
    if (!empty($correctletters)) {
        $badgerow .= '<div class="mb-1 small"><strong class="me-2">'.s(get_string('resultcorrect', 'local_ulms_exam')).':</strong>';
        foreach ($correctletters as $L) { $badgerow .= '<span class="ulms-choice-letter ulms-choice-letter--badge ulms-choice-letter--correct ms-1">'.$L.'</span>'; }
        $badgerow .= '</div>';
    }
    $body .= '<div class="card mb-3 shadow-sm '.$headerbg.'"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div><strong class="me-2">'.$icon.'Q'.($qi+1).'.</strong>'.format_text($q->stem_html ?? '').$qtypebadge.'</div>
        <span class="ulms-badge ulms-badge--draft">'.format_float($scorepts, 2).' / '.format_float((float)($q->points ?? 0), 2).' pts</span>
      </div><div class="card-body">'.$badgerow.$chhtml.'</div></div>';
}

echo local_ulms_dashboard_render_panel([
    'style' => 'cards',
    'title' => get_string('questions', 'quiz'),
    'subtitle' => get_string('resulttitle', 'local_ulms_exam'),
    'items' => [['title' => get_string('questions', 'quiz'), 'meta' => '', 'footer' => $body]],
]);

local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
