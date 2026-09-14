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
$examid = max(0, (int)required_param('examid', PARAM_INT));
$routingservice->maybe_redirect_legacy_request('student.examstake');
$url = $routingservice->get_url_for_route('student.examstake', ['examid' => $examid]);
local_ulms_dashboard_prepare_page($context, $url, get_string('studentexamstakecrumb', 'local_ulms_exam'));

$examservice = exam_service::instance();
$portalservice = new student_portal_service();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $answers = [];
    $selectedsingle = optional_param_array('selected_s', [], PARAM_INT);
    $selectedmulti = [];
    $rawmulti = optional_param_array('selected_m', [], PARAM_RAW);
    foreach ($rawmulti as $qidraw => $arr) {
        $qid = (int)$qidraw;
        $set = [];
        if (is_array($arr)) {
            foreach ($arr as $cv) {
                $cv = (int)$cv;
                if ($cv > 0 && !isset($set[$cv])) {
                    $set[$cv] = true;
                }
            }
        }
        $selectedmulti[$qid] = array_keys($set);
    }
    foreach ($selectedsingle as $examqid => $choiceid) {
        $qid = max(0, (int)$examqid);
        $cid = max(0, (int)$choiceid);
        if ($qid > 0 && $cid > 0) {
            $answers[] = [
                'examquestionid' => $qid,
                'selected_choiceid' => $cid,
            ];
        }
    }
    foreach ($selectedmulti as $examqid => $choiceids) {
        $qid = max(0, (int)$examqid);
        if ($qid > 0 && !empty($choiceids)) {
            $answers[] = [
                'examquestionid' => $qid,
                'selected_choiceids' => array_values(array_map('intval', $choiceids)),
            ];
        }
    }
    $autosubmit = optional_param('autosubmit', 0, PARAM_INT) == 1;
    $result = $examservice->submit_final_attempt((int)$USER->id, $examid, $answers, $autosubmit);
    $redirecturl = $routingservice->get_url_for_route('student.examsresult', ['submissionid' => (int)$result['submission']->id]);
    redirect($redirecturl, $result['message'], null, $result['late'] ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS);
}

$started = $examservice->start_or_resume_attempt((int)$USER->id, $examid);
if (empty($started['started'])) {
    $why = $started['reason'] ?? 'forbidden';
    if (!empty($started['submission'])) {
        redirect($routingservice->get_url_for_route('student.examsresult', ['submissionid' => (int)$started['submission']->id]));
    }
    throw new moodle_exception('examforbidden', 'local_ulms_exam', $routingservice->get_url_for_route('student.exams')->out(false));
}
$exam = $started['exam'];
$submission = $started['submission'];
$seed = (int)($submission->choices_shuffle_seed ?? random_int(1, 2147483647));
$loaded = $examservice->get_exam_with_questions((int)$exam->id, false, $seed);
$questions = $loaded['questions'];
$choicesbyq = $loaded['choices_by_question'];

$existingrows = $DB->get_records('local_ulms_submission_answers', ['submissionid' => (int)$submission->id], 'examquestionid ASC, id ASC');
$existingselected = [];
foreach ($existingrows as $er) {
    $qid = (int)$er->examquestionid;
    $cid = (int)$er->selected_choiceid;
    if ($cid > 0) {
        if (!isset($existingselected[$qid])) $existingselected[$qid] = [];
        $existingselected[$qid][$cid] = true;
    }
}

$server_ts = time();
$secondsleft = max(0, (int)$exam->end_ts - $server_ts);
$autosave_url = new moodle_url('/local/ulms_exam/student_exam_autosave.php');

echo $OUTPUT->header();
$headctx = $portalservice->get_header_context_for_section('take');
$countdown_pill = '<span id="ulms-countdown" class="ulms-countdown-pill ms-2">--:--:--</span>';
echo local_ulms_dashboard_render_page_header([
    'eyebrow' => 'TAKE EXAM',
    'title' => $headctx['title'] ?? format_string($exam->title),
    'meta' => $headctx['meta'] ?? format_text($exam->instructions ?? ''),
    'actions' => [[
        'label' => get_string('timeremaining', 'local_ulms_exam'),
        'url' => null,
        'class' => 'btn btn-light disabled',
        'suffix' => $countdown_pill,
    ]],
]);
local_ulms_dashboard_start_shell_wrap();

$countdown_sticky = '<div id="ulms-sticky-countdown-wrap" class="ulms-countdown-sticky">
  <div class="ulms-countdown-sticky-inner">
    <span class="ulms-countdown-label">'.get_string('timeremaining', 'local_ulms_exam').'</span>
    <span id="ulms-countdown-sticky" class="ulms-countdown-pill ulms-countdown-pill--lg">--:--:--</span>
  </div>
</div>';
echo $countdown_sticky;

$top = '<form method="post" id="exam-form" action="'.s($url->out(false)).'" data-ulms-loading-form="1" autocomplete="off">
    <input type="hidden" name="sesskey" value="'.sesskey().'"/>
    <input type="hidden" name="autosubmit" id="autosubmit" value="0"/>
    <div class="card shadow-sm mb-3 p-3">
        <div class="d-flex flex-wrap gap-3 align-items-center">
            <div><strong>'.format_string($exam->title).'</strong></div>
            <div class="ms-auto small text-muted">
                Submission id: '.(int)$submission->id.' · Shuffle seed: '.(int)$seed.'
            </div>
        </div>
    </div>';

$qhtml = '';
foreach ($questions as $qidx => $q) {
    $qid = (int)$q->id;
    $choices = $choicesbyq[$qid] ?? [];
    $qtype = !empty($q->questiontype) ? (string)$q->questiontype : 'single';
    $selectedmap = $existingselected[$qid] ?? [];
    $qtypebadge = $qtype === 'multi'
        ? '<span class="ulms-badge ulms-badge--graded ms-2" title="'.s(get_string('questiontypemultidescr', 'local_ulms_exam')).'">'.s(get_string('questiontypemulti', 'local_ulms_exam')).'</span>'
        : '';
    $qhtml .= '<div class="card shadow-sm mb-3 ulms-take-questioncard" data-qid="'.$qid.'" data-qtype="'.s($qtype).'">
        <div class="card-header d-flex align-items-center flex-wrap gap-2">
            <strong>Q'.((int)$qidx + 1).'.</strong>
            <span class="ulms-badge ulms-badge--draft ms-2">'.(int)($q->points ?? 1).' '.get_string('points', 'local_ulms_exam').'</span>
            '.$qtypebadge.'
        </div>
        <div class="card-body">
            <div class="fw-semibold mb-3">'.format_text($q->stem_html ?? '').'</div>';
    $j = 0;
    foreach ($choices as $c) {
        $cid = (int)$c->id;
        $letter = chr(65 + ($j % 26));
        $ischecked = isset($selectedmap[$cid]);
        if ($qtype === 'single') {
            $namestr = 'selected_s['.$qid.']';
            $inputtype = 'radio';
            $idattrs = 'id="q_'.$qid.'_'.$cid.'"';
            $checkedattrs = $ischecked ? ' checked' : '';
        } else {
            $namestr = 'selected_m['.$qid.'][]';
            $inputtype = 'checkbox';
            $idattrs = 'id="q_'.$qid.'_'.$cid.'"';
            $checkedattrs = $ischecked ? ' checked' : '';
        }
        $qhtml .= '<div class="ulms-choice-row input-group mb-2">
            <span class="ulms-choice-letter ulms-choice-letter--badge" aria-hidden="true">'.$letter.'</span>
            <span class="input-group-text">
              <input class="form-check-input mt-0 exam-choice" type="'.$inputtype.'"
                     name="'.$namestr.'" '.$idattrs.'
                     value="'.$cid.'"
                     data-examqid="'.$qid.'" data-choiceid="'.$cid.'" data-qtype="'.s($qtype).'"
                     '.$checkedattrs.' aria-label="choice '.$letter.'">
            </span>
            <label class="form-control form-label mb-0" for="q_'.$qid.'_'.$cid.'">
                '.format_text($c->choice_html ?? '').'
            </label>
        </div>';
        $j++;
    }
    $qhtml .= '</div></div>';
}

$bottom = '<div class="sticky-bottom bg-white p-3 rounded border">
        <div class="d-flex ulms-d-flex-gap-2 flex-wrap justify-content-between align-items-center">
            <a href="'.s($routingservice->get_url_for_route('student.exams')->out(false)).'"
               class="btn btn-outline-secondary">'.get_string('cancel').'</a>
            <button type="submit" class="btn btn-primary px-4">'.get_string('submitfinal', 'local_ulms_exam').'</button>
        </div>
        <div class="small text-muted mt-2" id="autosave-status">Auto-save is enabled.</div>
    </div>
</form>';

$counthtml = <<<'JS'
<script>
(function(){
    const WINDOW_END = _END_;
    const AUTOSAVE_MS = 30 * 1000;
    const SESSKEY = _SESSKEY_;
    const SUBMISSION_ID = _SUBID_;
    const AUTOSAVE_URL = _AUTOURL_;

    const serverAtInit = _SINIT_;
    const clientAtInit = Date.now();
    const el = document.getElementById('ulms-countdown');
    const elSticky = document.getElementById('ulms-countdown-sticky');
    const autoSubmit = document.getElementById('autosubmit');
    const form = document.getElementById('exam-form');
    function fmt(sec){
        sec = Math.max(0, Math.floor(sec));
        const h = String(Math.floor(sec/3600)).padStart(2,'0');
        const m = String(Math.floor((sec%3600)/60)).padStart(2,'0');
        const s = String(sec%60).padStart(2,'0');
        return h+':'+m+':'+s;
    }
    let autoSubmitFired = false;
    function serverOffsetSec() {
        return (Date.now() - clientAtInit) / 1000;
    }
    function tick() {
        const serverNow = serverAtInit + serverOffsetSec();
        const remain = WINDOW_END - serverNow;
        const str = fmt(remain);
        if (el) el.textContent = str;
        if (elSticky) elSticky.textContent = str;
        if (remain <= 0 && !autoSubmitFired) {
            autoSubmitFired = true;
            if (autoSubmit) autoSubmit.value = '1';
            if (form && typeof form.submit === 'function') {
                form.submit();
            }
        }
    }
    tick();
    setInterval(tick, 1000);

    const autosaveStatus = document.getElementById('autosave-status');
    function getCurrentAnswersPayload() {
        const byqid = {};
        document.querySelectorAll('input.exam-choice:checked').forEach(function(r){
            const qid = parseInt(r.dataset.examqid || '0', 10);
            const cid = parseInt(r.dataset.choiceid || '0', 10);
            const qt = r.dataset.qtype || 'single';
            if (qid <= 0 || cid <= 0) return;
            if (!byqid[qid]) byqid[qid] = { qid, qt, ids: [] };
            byqid[qid].ids.push(cid);
        });
        const payload = [];
        Object.keys(byqid).forEach(function(k){
            const row = byqid[k];
            if (row.qt === 'multi') {
                payload.push({ examquestionid: row.qid, selected_choiceids: row.ids });
            } else {
                payload.push({ examquestionid: row.qid, selected_choiceid: row.ids[0] || 0 });
            }
        });
        return payload;
    }
    async function doAutosave() {
        const payload = getCurrentAnswersPayload();
        try {
            if (autosaveStatus) autosaveStatus.textContent = 'Autosaving…';
            const resp = await fetch(AUTOSAVE_URL, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'sesskey=' + encodeURIComponent(SESSKEY) +
                      '&submissionid=' + encodeURIComponent(SUBMISSION_ID) +
                      '&answers=' + encodeURIComponent(JSON.stringify(payload))
            });
            const ok = resp.ok;
            const now = new Date();
            const t = now.toLocaleTimeString();
            if (autosaveStatus) autosaveStatus.textContent = ok ? ('Autosaved at ' + t) : ('Autosave failed at ' + t + ' (will retry)');
        } catch(e) {
            if (autosaveStatus) autosaveStatus.textContent = 'Autosave offline (will retry).';
        }
    }
    setInterval(doAutosave, AUTOSAVE_MS);
    let last = 0;
    document.addEventListener('change', (ev) => {
        if (ev.target && ev.target.classList && ev.target.classList.contains('exam-choice')) {
            const now = Date.now();
            if (now - last > 5000) { last = now; setTimeout(doAutosave, 1200); }
        }
    });
    window.addEventListener('beforeunload', function() {
        const payload = getCurrentAnswersPayload();
        const answersJson = JSON.stringify(payload);
        const body = new URLSearchParams({
            sesskey: SESSKEY,
            submissionid: String(SUBMISSION_ID),
            answers: answersJson
        });
        try {
            const beaconOk = navigator.sendBeacon(AUTOSAVE_URL, body);
            if (!beaconOk) {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', AUTOSAVE_URL, false);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.send(body.toString());
            }
        } catch(e) {
            try {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', AUTOSAVE_URL, false);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.send('sesskey=' + encodeURIComponent(SESSKEY) +
                         '&submissionid=' + encodeURIComponent(SUBMISSION_ID) +
                         '&answers=' + encodeURIComponent(answersJson));
            } catch(e2) {}
        }
    });
})();
</script>
JS;

$placeholders = [
    '_END_' => (int)$exam->end_ts,
    '_SESSKEY_' => json_encode(sesskey()),
    '_SUBID_' => (int)$submission->id,
    '_AUTOURL_' => json_encode($autosave_url->out(false)),
    '_SINIT_' => (int)$server_ts,
];
foreach ($placeholders as $k => $v) {
    $counthtml = str_replace($k, $v, $counthtml);
}

echo local_ulms_dashboard_render_panel([
    'style' => 'cards',
    'title' => format_string($exam->title),
    'subtitle' => format_text($exam->instructions ?? ''),
    'items' => [['title' => format_string($exam->title), 'meta' => '', 'footer' => $top.$qhtml.$bottom]],
]);

echo $counthtml;

local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
