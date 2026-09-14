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
$routingservice->maybe_redirect_legacy_request('lecturer.examsquestions');
$examid = max(0, (int)required_param('examid', PARAM_INT));
$url = $routingservice->get_url_for_route('lecturer.examsquestions', ['examid' => $examid]);
local_ulms_dashboard_prepare_page($context, $url, get_string('examquestionsheading', 'local_ulms_exam'));

$examservice = exam_service::instance();
$portalservice = new lecturer_portal_service();
$examservice->require_manage_exam($examid);
$exam = $examservice->get_exam($examid, false);
$locked = ($exam->status ?? 'draft') !== exam_service::STATUS_DRAFT;

$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    if ($locked) {
        throw new moodle_exception('editlockedquestions', 'local_ulms_exam');
    }
    $action = optional_param('action', 'savequestions', PARAM_ALPHAEXT);
    try {
        if ($action === 'importfrombank') {
            $bankids = optional_param_array('bankids', [], PARAM_INT);
            $bankids = array_values(array_filter(array_map('intval', $bankids)));
            $imported = $examservice->import_from_bank($examid, $bankids);
            redirect($url, "Imported {$imported} questions from the bank.", null, \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($action === 'savequestions') {
            $stems = optional_param_array('stem_html', [], PARAM_RAW);
            $pointses = optional_param_array('points', [], PARAM_INT);
            $qtypes = optional_param_array('questiontype', [], PARAM_ALPHA);
            $bank = optional_param('addtobank', 0, PARAM_BOOL);
            $questions = [];
            foreach ($stems as $idx => $stem) {
                $stem = trim((string)$stem);
                if ($stem === '') continue;
                $rawtype = (string)($qtypes[$idx] ?? 'single');
                $qtype = in_array($rawtype, ['single', 'multi'], true) ? $rawtype : 'single';
                $choicesraw = [];
                $choicekey = "choice_{$idx}_html";
                $correctkey = "correct_{$idx}";
                $choices = optional_param_array($choicekey, [], PARAM_RAW);
                if ($qtype === 'single') {
                    $correct = (int)optional_param($correctkey, -1, PARAM_INT);
                    foreach ($choices as $ci => $ctext) {
                        $ctext = trim((string)$ctext);
                        if ($ctext === '') continue;
                        $choicesraw[] = ['choice_html' => $ctext, 'iscorrect' => $ci === $correct ? 1 : 0];
                    }
                } else {
                    $correctarr = optional_param_array($correctkey, [], PARAM_INT);
                    $correctset = [];
                    foreach ($correctarr as $cv) {
                        $cv = (int)$cv;
                        if ($cv >= 0) $correctset[$cv] = true;
                    }
                    foreach ($choices as $ci => $ctext) {
                        $ctext = trim((string)$ctext);
                        if ($ctext === '') continue;
                        $choicesraw[] = ['choice_html' => $ctext, 'iscorrect' => isset($correctset[$ci]) ? 1 : 0];
                    }
                }
                $questions[] = [
                    'stem_html' => $stem,
                    'questiontype' => $qtype,
                    'points' => max(1, (int)($pointses[$idx] ?? 1)),
                    'choices' => $choicesraw,
                ];
            }
            if (!empty($questions)) {
                $res = $examservice->save_questions($examid, $questions, !empty($bank));
                $notice = "Saved {$res['saved']} questions.";
            }
            redirect($url, $notice, null, \core\output\notification::NOTIFY_SUCCESS);
        }
    } catch (\Throwable $exception) {
        if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'lecturer_exam_questions::save_questions', []); }
        $notice = $exception->getMessage();
    }
}

$loaded = $examservice->get_exam_with_questions($examid, false);
$questions = $loaded['questions'];
$choicesbyq = $loaded['choices_by_question'];
$bankitems = $examservice->list_bank_items((int)($exam->programmeid ?? 0), (int)($exam->courseid ?? 0), (int)($exam->semesterid ?? 0));

echo $OUTPUT->header();
$headerctx = $portalservice->get_header_context_for_section('questions');
echo local_ulms_dashboard_render_page_header([
    'eyebrow' => 'EXAM QUESTIONS',
    'title' => $headerctx['title'] ?? get_string('examquestionsheading', 'local_ulms_exam'),
    'meta' => $headerctx['meta'] ?? format_string($exam->title),
    'actions' => [
        ['label' => get_string('previewexam', 'local_ulms_exam'), 'url' => $routingservice->get_url_for_route('lecturer.examspreview', ['examid' => $examid]), 'class' => 'btn btn-outline-primary'],
        ['label' => get_string('exams', 'local_ulms_exam'), 'url' => $routingservice->get_url_for_route('lecturer.exams'), 'class' => 'btn btn-outline-secondary'],
    ],
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
                <div class="fw-semibold">Questions</div>
                <div class="text-muted small">MCQs with correct answers</div>
            </div>
        </div>
    </div>
</div>';
echo $wizardhtml;

if ($locked) {
    echo $OUTPUT->notification(get_string('editlockedquestions', 'local_ulms_exam'), \core\output\notification::NOTIFY_WARNING);
}
if ($notice) {
    echo $OUTPUT->notification(s($notice), \core\output\notification::NOTIFY_INFO);
}

$existinghtml = '';
foreach ($questions as $qi => $q) {
    $ch = $choicesbyq[(int)$q->id] ?? [];
    $qtype = !empty($q->questiontype) ? (string)$q->questiontype : 'single';
    $qtypebadge = $qtype === 'multi'
        ? '<span class="ulms-badge ulms-badge--graded ms-2">'.get_string('questiontypemulti', 'local_ulms_exam').'</span>'
        : '<span class="ulms-badge ulms-badge--draft ms-2">'.get_string('questiontypesingle', 'local_ulms_exam').'</span>';
    $correctids = [];
    $choiceshtml = '';
    $ci = 0;
    foreach ($ch as $c) {
        $letter = chr(65 + ($ci % 26));
        $iscorrect = !empty($c->iscorrect);
        if ($iscorrect) $correctids[] = $ci;
        $rowclass = $iscorrect ? ' ulms-choice-row--correct' : '';
        $choiceshtml .= '<div class="input-group mb-2 ulms-choice-row'.$rowclass.'">
            <span class="ulms-choice-letter ulms-choice-letter--badge">'.$letter.'</span>
            <div class="input-group-text"><input type="'.($qtype === 'multi' ? 'checkbox' : 'radio').'" disabled'.($iscorrect ? ' checked' : '').' aria-label="correct"></div>
            <input type="text" class="form-control" readonly value="'.s($c->choice_html ?? '').'">
          </div>';
        $ci++;
    }
    $existinghtml .= '<div class="card mb-3 shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
          <div><strong>Q'.($qi+1).'</strong> <span class="ulms-badge ulms-badge--draft ms-2">'.((int)($q->points ?? 1)).' '.get_string('points', 'local_ulms_exam').'</span>'.$qtypebadge.'</div>
        </div>
        <div class="card-body">
          <div class="mb-2 fw-semibold">'.format_text($q->stem_html ?? '').'</div>
          '.$choiceshtml.'
        </div>
      </div>';
}
if ($existinghtml !== '') {

    echo local_ulms_dashboard_render_panel([
        'style' => 'cards',
        'title' => get_string('currentquestions', 'local_ulms_exam'),
        'items' => [['title' => get_string('currentquestions', 'local_ulms_exam'), 'meta' => '', 'footer' => $existinghtml]],
    ]);
}

$addqurl = new moodle_url($url);
$formaction = s($url->out(false));
$qtypesingle = get_string('questiontypesingle', 'local_ulms_exam');
$qtypemulti = get_string('questiontypemulti', 'local_ulms_exam');
$qtypesingledescr = get_string('questiontypesingledescr', 'local_ulms_exam');
$qtypemultidescr = get_string('questiontypemultidescr', 'local_ulms_exam');
$sesskeyval = sesskey();
$addqlabel = get_string('addquestion', 'local_ulms_exam');
$saveqlabel = get_string('savequestions', 'local_ulms_exam');
$saveandbanklabel = get_string('saveandbank', 'local_ulms_exam');
$form = '<form method="post" action="'.$formaction.'" id="qform">'
    .'<input type="hidden" name="sesskey" value="'.$sesskeyval.'"/>'
    .'<input type="hidden" name="action" value="savequestions"/>'
    .'<div id="newquestions"></div>'
    .'<div class="d-flex ulms-d-flex-gap-2 my-3">'
    .'  <button type="button" id="btn-add-q" class="btn btn-outline-secondary">'.$addqlabel.'</button>'
    .'  <button type="submit" class="btn btn-primary">'.$saveqlabel.'</button>'
    .'  <label class="form-check ms-auto my-auto">'
    .'    <input class="form-check-input" type="checkbox" name="addtobank" id="addtobank" value="1">'
    .'    <span class="form-check-label">'.$saveandbanklabel.'</span>'
    .'  </label>'
    .'</div>'
    .'</form>';

$addscript = <<<JS
(function(){
    const QTYPE_SINGLE_LABEL = "{$qtypesingle}";
    const QTYPE_MULTI_LABEL = "{$qtypemulti}";
    const QTYPE_SINGLE_DESC = "{$qtypesingledescr}";
    const QTYPE_MULTI_DESC = "{$qtypemultidescr}";
    let nextQ = 0;
    function addQ() {
        const wrap = document.getElementById('newquestions');
        if (!wrap) return;
        const tpl = `
        <fieldset class="card mb-3 shadow-sm new-q border ulms-question-card" data-qidx="QIDX">
          <div class="card-header d-flex justify-content-between align-items-center bg-light">
            <div class="fw-semibold">ORDER</div>
            <button type="button" class="btn btn-sm btn-outline-danger del-q">Delete</button>
          </div>
          <div class="card-body">
            <div class="mb-3">
              <label class="form-label fw-medium">Question stem</label>
              <textarea class="form-control" rows="2" name="stem_html[]" required></textarea>
            </div>
            <div class="mb-3 row g-2 align-items-start">
              <div class="col-md-3">
                <label class="form-label">Points</label>
                <input type="number" min="1" step="1" class="form-control" name="points[]" value="1">
              </div>
              <div class="col-md-9">
                <label class="form-label">Question type</label>
                <div class="ulms-qtype-toggle-wrap">
                  <select class="form-select ulms-qtype-toggle" name="questiontype[]" aria-label="Question type">
                    <option value="single">\${QTYPE_SINGLE_LABEL} — exactly one correct (A/B/C/D classic)</option>
                    <option value="multi">\${QTYPE_MULTI_LABEL} — select ALL that apply (all-or-nothing)</option>
                  </select>
                  <div class="text-muted small mt-1 ulms-qtype-hint">\${QTYPE_SINGLE_DESC}</div>
                </div>
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label">Choices (toggle the correct row(s))</label>
              <div class="choices mb-2"></div>
              <button type="button" class="btn btn-sm btn-outline-secondary add-c">Add choice</button>
            </div>
          </div>
        </fieldset>`;
        const t = document.createElement('template');
        t.innerHTML = tpl.replace(/ORDER/g, 'New question #'+(nextQ+1)).replace(/QIDX/g, String(nextQ));
        const node = t.content.firstElementChild;
        wrap.appendChild(node);
        const cwrap = node.querySelector('.choices');
        for (let i = 0; i < 4; i++) addChoice(cwrap, nextQ, 'single');
        node.querySelector('.del-q').addEventListener('click', () => { node.remove(); renumber(); });
        node.querySelector('.add-c').addEventListener('click', () => addChoice(cwrap, nextQ, node.dataset.qtype || 'single'));
        const qselect = node.querySelector('.ulms-qtype-toggle');
        qselect.addEventListener('change', () => onQtypeChange(node, cwrap, nextQ, qselect.value));
        node.dataset.qtype = 'single';
        nextQ++;
        renumber();
    }
    function onQtypeChange(node, cwrap, qidx, newtype) {
        node.dataset.qtype = newtype;
        const hint = node.querySelector('.ulms-qtype-hint');
        if (hint) hint.textContent = newtype === 'multi' ? QTYPE_MULTI_DESC : QTYPE_SINGLE_DESC;
        const rows = cwrap.querySelectorAll('.choice-row');
        let firstChecked = 0;
        rows.forEach((r, i) => {
            const corrInp = r.querySelector('.correct-input');
            if (!corrInp) return;
            if (newtype === 'single') {
                corrInp.type = 'radio';
                corrInp.name = `correct_\${qidx}`;
                corrInp.value = String(i);
                if (i === firstChecked) corrInp.checked = true;
                else corrInp.checked = false;
            } else {
                corrInp.type = 'checkbox';
                corrInp.name = `correct_\${qidx}[]`;
                corrInp.value = String(i);
            }
        });
    }
    function addChoice(cwrap, qidx, qtype) {
        const idx = cwrap.querySelectorAll('.choice-row').length;
        if (idx > 7) return;
        const letter = String.fromCharCode(65 + (idx % 26));
        const parentCard = cwrap.closest('.ulms-question-card');
        const cardQidx = parentCard ? parentCard.dataset.qidx : qidx;
        const row = document.createElement('div');
        row.className = 'choice-row input-group mb-2 ulms-choice-row';
        const isSingle = qtype === 'single';
        const corrAttrs = isSingle
            ? `type="radio" name="correct_\${cardQidx}" value="\${idx}" \${idx === 0 ? 'checked' : ''}`
            : `type="checkbox" name="correct_\${cardQidx}[]" value="\${idx}"`;
        row.innerHTML = `
            <span class="ulms-choice-letter ulms-choice-letter--badge" aria-hidden="true">\${letter}</span>
            <span class="input-group-text">
              <input class="form-check-input mt-0 correct-input" \${corrAttrs} aria-label="correct">
            </span>
            <input type="text" class="form-control" name="choice_\${cardQidx}_html[]" placeholder="Option \${letter}" \${idx < 2 ? 'required' : ''}/>
            \${idx > 1 ? '<button class="btn btn-outline-secondary del-c" type="button" aria-label="Remove">×</button>' : ''}
        `;
        cwrap.appendChild(row);
        const del = row.querySelector('.del-c');
        if (del) del.addEventListener('click', () => { row.remove(); syncCorrect(cwrap, cardQidx, parentCard); });
        syncCorrect(cwrap, cardQidx, parentCard);
    }
    function syncCorrect(cwrap, qidx, parentCard) {
        const rows = cwrap.querySelectorAll('.choice-row');
        const curtype = parentCard && parentCard.dataset.qtype ? parentCard.dataset.qtype : 'single';
        let firstCheckedSet = false;
        rows.forEach((r, i) => {
            const letter = String.fromCharCode(65 + (i % 26));
            const letSpan = r.querySelector('.ulms-choice-letter');
            if (letSpan) letSpan.textContent = letter;
            const corr = r.querySelector('.correct-input');
            if (corr) {
                if (curtype === 'single') {
                    corr.type = 'radio';
                    corr.name = `correct_\${qidx}`;
                    corr.value = String(i);
                    if (!firstCheckedSet) { corr.checked = true; firstCheckedSet = true; }
                } else {
                    corr.type = 'checkbox';
                    corr.name = `correct_\${qidx}[]`;
                    corr.value = String(i);
                }
            }
            const inp = r.querySelector('input[name^=choice]');
            if (inp) {
                inp.name = `choice_\${qidx}_html[]`;
                inp.placeholder = "Option " + letter;
            }
        });
    }
    function renumber() {
        document.querySelectorAll('.new-q .card-header .fw-semibold').forEach((n,i) => n.textContent = 'New question #'+(i+1));
    }
    document.getElementById('btn-add-q')?.addEventListener('click', addQ);
    document.getElementById('qform')?.addEventListener('submit', (e) => {
        const f = e.target;
        const bankall = document.getElementById('bank-ids');
        if (!bankall) return true;
        if (f.querySelector('input[type=checkbox][name="bankids[]"]:checked')) return true;
    });
    addQ();
})();
JS;

echo local_ulms_dashboard_render_panel([
    'style' => 'cards',
    'title' => get_string('newquestion', 'question'),
    'items' => [['title' => get_string('addquestion', 'local_ulms_exam'), 'meta' => $locked ? get_string('editlockedquestions', 'local_ulms_exam') : '', 'footer' => $form.html_writer::script($addscript)]],
]);

if (!$locked && !empty($bankitems)) {
    $importform = '<form method="post" action="'.s($url->out(false)).'">'
        .'<input type="hidden" name="sesskey" value="'.sesskey().'"/>'
        .'<input type="hidden" name="action" value="importfrombank"/>';
    $rows = '';
    foreach ($bankitems as $b) {
        $rows .= '<div class="form-check mb-2 border p-2 rounded"><label>'
            .'<input class="form-check-input me-2" type="checkbox" name="bankids[]" value="'.(int)$b->id.'">'
            .'<span>'.format_text($b->stem_html ?? '').'</span></label></div>';
    }
    $importform .= '<div id="bank-ids">'.$rows.'</div>';
    $importform .= '<div class="mt-3"><button type="submit" class="btn btn-outline-primary">'.get_string('importfrombank', 'local_ulms_exam').'</button></div></form>';
    echo local_ulms_dashboard_render_panel([
        'style' => 'cards',
        'title' => get_string('questionbank', 'question'),
        'items' => [['title' => get_string('importfrombank', 'local_ulms_exam'), 'meta' => '', 'footer' => $importform]],
    ]);
}

local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
