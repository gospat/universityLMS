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
    $levelid = max(0, (int)required_param('levelid', PARAM_INT));
    $sessionid = max(0, (int)required_param('sessionid', PARAM_INT));
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
            'programmeid' => $programmeid,
            'levelid' => $levelid,
            'sessionid' => $sessionid,
            'semesterid' => $semesterid,
            'courseid' => $courseid,
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

$scope = $examservice->get_lecturer_hierarchy_scope();

$programmedepts = [];
$programmelevelmap = [];
if (!empty($scope['departments']) && !empty($scope['programmes'])) {
    [$din, $dparams] = $DB->get_in_or_equal(array_keys($scope['programmes']), SQL_PARAMS_NAMED, 'dpm');
    $rs = $DB->get_recordset_sql("SELECT id, departmentid FROM {local_ulms_programmes} WHERE id $din", $dparams);
    foreach ($rs as $r) {
        $programmedepts[(int)$r->id] = (int)$r->departmentid;
    }
    $rs->close();
}
$deptfaculties = [];
if (!empty($programmedepts)) {
    [$din, $dparams] = $DB->get_in_or_equal(array_unique(array_values($programmedepts)), SQL_PARAMS_NAMED, 'dfm');
    $rs = $DB->get_recordset_sql("SELECT id, facultyid FROM {local_ulms_departments} WHERE id $din", $dparams);
    foreach ($rs as $r) {
        $deptfaculties[(int)$r->id] = (int)$r->facultyid;
    }
    $rs->close();
}

$currentfacultyid = 0;
$currentdepartmentid = 0;
if ($examid > 0 && !empty($exam->programmeid)) {
    $currentdepartmentid = (int)($programmedepts[(int)$exam->programmeid] ?? 0);
    $currentfacultyid = (int)($deptfaculties[$currentdepartmentid] ?? 0);
    if (empty($exam->sessionid) && !empty($exam->semesterid)) {
        $exam->sessionid = (int)($DB->get_field('local_ulms_semesters', 'sessionid', ['id' => (int)$exam->semesterid]) ?: 0);
    }
} else {
    $exam->levelid = 0;
    $exam->sessionid = 0;
}

$hierarchy_json = json_encode([
    'departments_by_faculty' => (object)array_map(static fn($fid): array => array_values(
        array_filter(array_map(static function ($deptid, $f) use ($fid, $deptfaculties): ?int {
            $myfac = $deptfaculties[$deptid] ?? 0;
            return $myfac === (int)$fid ? (int)$deptid : null;
        }, array_keys($scope['departments']), array_fill(0, count($scope['departments']), null))
    )), array_keys($scope['faculties'])),
    'programmes_by_department' => (object)array_map(static fn($did): array => array_values(
        array_keys(array_filter($programmedepts, static fn($dpid): bool => (int)$dpid === (int)$did))
    ), array_keys($scope['departments'])),
    'levels_by_programme' => (object)array_map(static fn($pid): array => isset($scope['hierarchy'][$pid])
        ? array_values(array_unique(array_map(static fn($v): int => (int)$v, array_keys($scope['hierarchy'][$pid]))))
        : [], array_keys($scope['programmes'])),
    'sessions_by_programme' => (object)($scope['programme_sessions'] ?? []),
    'semesters_by_session' => (object)($scope['session_semesters'] ?? []),
    'courses_by_combination' => (function() use ($scope): array {
        $out = [];
        foreach (($scope['hierarchy'] ?? []) as $pid => $levelmap) {
            foreach ($levelmap as $lid => $semmap) {
                foreach ($semmap as $sid => $courseids) {
                    $out["{$pid}_{$lid}_{$sid}"] = array_values(array_map(static fn($c): int => (int)$c, $courseids));
                }
            }
        }
        return $out;
    })(),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$facultyopts = [0 => get_string('selectfaculty', 'local_ulms_exam')] + ($scope['faculties'] ?? []);
$departmentopts = [0 => get_string('selectdepartment', 'local_ulms_exam')] + ($scope['departments'] ?? []);
$programmeopts = [0 => get_string('selectprogramme', 'local_ulms_exam')] + ($scope['programmes'] ?? []);
$levelopts = [0 => get_string('selectlevel', 'local_ulms_exam')] + ($scope['levels'] ?? []);
$sessionopts = [0 => get_string('selectsession', 'local_ulms_exam')] + ($scope['sessions'] ?? []);
$semesteropts = [0 => get_string('selectsemester', 'local_ulms_exam')] + ($scope['semesters'] ?? []);
$courseopts = [0 => get_string('selectcourse', 'local_ulms_exam')] + ($scope['courses'] ?? []);

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
echo '<div class="card mb-4 shadow-sm p-4">
  <div class="mb-2">
    <div class="fw-semibold mb-1">'.get_string('examscopeheading', 'local_ulms_exam').'</div>
    <div class="text-muted small">'.get_string('examscopedesc', 'local_ulms_exam').'</div>
  </div>
  <div class="row g-3 mb-3">
    <div class="col-md-4">'. $f(get_string('examfaculty', 'local_ulms_exam'),
      html_writer::select($facultyopts, 'facultyid', $currentfacultyid, false, ['class' => 'form-select', 'required' => 'required', 'id' => 'f_facultyid'])
    ) . '</div>
    <div class="col-md-4">'. $f(get_string('examdepartment', 'local_ulms_exam'),
      html_writer::select($departmentopts, 'departmentid', $currentdepartmentid, false, ['class' => 'form-select', 'required' => 'required', 'id' => 'f_departmentid'])
    ) . '</div>
    <div class="col-md-4">'. $f(get_string('examprogramme', 'local_ulms_exam'),
      html_writer::select($programmeopts, 'programmeid', (int)($exam->programmeid ?? 0), false, ['class' => 'form-select', 'required' => 'required', 'id' => 'f_programmeid'])
    ) . '</div>
  </div>
  <div class="row g-3 mb-3">
    <div class="col-md-4">'. $f(get_string('examlevel', 'local_ulms_exam'),
      html_writer::select($levelopts, 'levelid', (int)($exam->levelid ?? 0), false, ['class' => 'form-select', 'required' => 'required', 'id' => 'f_levelid'])
    ) . '</div>
    <div class="col-md-4">'. $f(get_string('examsession', 'local_ulms_exam'),
      html_writer::select($sessionopts, 'sessionid', (int)($exam->sessionid ?? 0), false, ['class' => 'form-select', 'required' => 'required', 'id' => 'f_sessionid'])
    ) . '</div>
    <div class="col-md-4">'. $f(get_string('examsemester', 'local_ulms_exam'),
      html_writer::select($semesteropts, 'semesterid', (int)($exam->semesterid ?? 0), false, ['class' => 'form-select', 'required' => 'required', 'id' => 'f_semesterid'])
    ) . '</div>
  </div>
  <div class="row g-3 mb-3">
    <div class="col-md-12">'. $f(get_string('examcourse', 'local_ulms_exam'),
      html_writer::select($courseopts, 'courseid', (int)($exam->courseid ?? 0), false, ['class' => 'form-select', 'required' => 'required', 'id' => 'f_courseid'])
    ) . '</div>
  </div>
</div>';

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

echo html_writer::script("
(function(){
  var scope = " . $hierarchy_json . ";
  var facultySel = document.getElementById('f_facultyid');
  var deptSel = document.getElementById('f_departmentid');
  var progSel = document.getElementById('f_programmeid');
  var levelSel = document.getElementById('f_levelid');
  var sessionSel = document.getElementById('f_sessionid');
  var semSel = document.getElementById('f_semesterid');
  var courseSel = document.getElementById('f_courseid');
  function opt(label, value){ return '<option value=\"'+String(value)+'\">'+String(label).replace(/&/g,'&amp;').replace(/</g,'&lt;')+'</option>'; }
  function rebuild(sel, zeroLabel, ids, labelMap, currentVal){
    var html = opt(zeroLabel, 0);
    for (var i = 0; i < ids.length; i++){
      var id = ids[i];
      if (Object.prototype.hasOwnProperty.call(labelMap, id)) {
        html += opt(labelMap[id], id);
      }
    }
    sel.innerHTML = html;
    if (currentVal != null && ids.indexOf(parseInt(currentVal,10)) !== -1) {
      sel.value = String(currentVal);
    } else {
      sel.value = '0';
    }
  }
  function hasInt(arr, val){ for (var i=0;i<arr.length;i++){ if (parseInt(arr[i],10)===parseInt(val,10)){return true;}} return false; }
  function applyCascade(resetChildren){
    var facultyVal = parseInt(facultySel.value,10) || 0;
    var deptPool = (facultyVal > 0 && scope.departments_by_faculty && scope.departments_by_faculty[facultyVal])
      ? scope.departments_by_faculty[facultyVal] : " . json_encode(array_keys($scope['departments'] ?? [])) . ";
    rebuild(deptSel, " . json_encode(get_string('selectdepartment', 'local_ulms_exam')) . ", deptPool, " . json_encode((object)($scope['departments'] ?? [])) . ", resetChildren ? null : deptSel.value);
    var deptVal = parseInt(deptSel.value,10) || 0;
    var progPool = [];
    if (deptVal > 0 && scope.programmes_by_department && scope.programmes_by_department[deptVal]) {
      progPool = scope.programmes_by_department[deptVal];
    } else {
      progPool = " . json_encode(array_keys($scope['programmes'] ?? [])) . ";
      if (deptVal > 0) {
        var subP = [];
        for (var j=0;j<progPool.length;j++){
          var pid = progPool[j];
          var p2d = " . json_encode((object)array_map('intval', $programmedepts ?? [])) . ";
          if (parseInt(p2d[pid],10) === deptVal) subP.push(parseInt(pid,10));
        }
        progPool = subP;
      }
    }
    rebuild(progSel, " . json_encode(get_string('selectprogramme', 'local_ulms_exam')) . ", progPool, " . json_encode((object)($scope['programmes'] ?? [])) . ", resetChildren ? null : progSel.value);
    var progVal = parseInt(progSel.value,10) || 0;
    var levelPool = (progVal > 0 && scope.levels_by_programme && scope.levels_by_programme[progVal])
      ? scope.levels_by_programme[progVal] : " . json_encode(array_keys($scope['levels'] ?? [])) . ";
    rebuild(levelSel, " . json_encode(get_string('selectlevel', 'local_ulms_exam')) . ", levelPool, " . json_encode((object)($scope['levels'] ?? [])) . ", resetChildren ? null : levelSel.value);
    var sessionPool = (progVal > 0 && scope.sessions_by_programme && scope.sessions_by_programme[progVal])
      ? scope.sessions_by_programme[progVal] : " . json_encode(array_keys($scope['sessions'] ?? [])) . ";
    rebuild(sessionSel, " . json_encode(get_string('selectsession', 'local_ulms_exam')) . ", sessionPool, " . json_encode((object)($scope['sessions'] ?? [])) . ", resetChildren ? null : sessionSel.value);
    var sessionVal = parseInt(sessionSel.value,10) || 0;
    var semPool = (sessionVal > 0 && scope.semesters_by_session && scope.semesters_by_session[sessionVal])
      ? scope.semesters_by_session[sessionVal] : " . json_encode(array_keys($scope['semesters'] ?? [])) . ";
    rebuild(semSel, " . json_encode(get_string('selectsemester', 'local_ulms_exam')) . ", semPool, " . json_encode((object)($scope['semesters'] ?? [])) . ", resetChildren ? null : semSel.value);
    var progV = parseInt(progSel.value,10) || 0;
    var levelV = parseInt(levelSel.value,10) || 0;
    var sessionV = parseInt(sessionSel.value,10) || 0;
    var semesterV = parseInt(semSel.value,10) || 0;
    var combos = scope.courses_by_combination || {};
    var allCourses = " . json_encode((object)($scope['courses'] ?? [])) . ";
    var coursePool = [];
    var usedComboKeys = Object.keys(combos);
    if (progV > 0 && levelV > 0 && semesterV > 0) {
      var k1 = progV+'_'+levelV+'_'+semesterV;
      var k2 = progV+'_0_'+semesterV;
      if (Object.prototype.hasOwnProperty.call(combos, k1)) coursePool = coursePool.concat(combos[k1]);
      else if (Object.prototype.hasOwnProperty.call(combos, k2)) coursePool = coursePool.concat(combos[k2]);
    } else if (progV > 0 && levelV > 0) {
      var k3 = progV+'_'+levelV+'_0';
      if (Object.prototype.hasOwnProperty.call(combos, k3)) coursePool = coursePool.concat(combos[k3]);
      var prefilt = [];
      for (var ck=0; ck<usedComboKeys.length; ck++){
        var parts = usedComboKeys[ck].split('_');
        if (parseInt(parts[0],10) === progV && parseInt(parts[1],10) === levelV) {
          var cs = combos[usedComboKeys[ck]];
          for (var ci=0; ci<cs.length; ci++) if (prefilt.indexOf(cs[ci]) === -1) prefilt.push(cs[ci]);
        }
      }
      if (prefilt.length > 0) coursePool = prefilt;
    }
    if (coursePool.length === 0 && progV > 0) {
      var fall = [];
      for (var ck2=0; ck2<usedComboKeys.length; ck2++){
        var parts2 = usedComboKeys[ck2].split('_');
        if (parseInt(parts2[0],10) === progV) {
          var cs2 = combos[usedComboKeys[ck2]];
          for (var ci2=0; ci2<cs2.length; ci2++) if (fall.indexOf(cs2[ci2]) === -1) fall.push(cs2[ci2]);
        }
      }
      coursePool = fall;
    }
    if (coursePool.length === 0) {
      coursePool = Object.keys(allCourses).map(function(x){return parseInt(x,10);});
    }
    rebuild(courseSel, " . json_encode(get_string('selectcourse', 'local_ulms_exam')) . ", coursePool, allCourses, resetChildren ? null : courseSel.value);
  }
  facultySel.addEventListener('change', function(){ applyCascade(true); });
  deptSel.addEventListener('change', function(){ applyCascade(true); });
  progSel.addEventListener('change', function(){ applyCascade(true); });
  levelSel.addEventListener('change', function(){ applyCascade(false); });
  sessionSel.addEventListener('change', function(){ applyCascade(false); });
  semSel.addEventListener('change', function(){ applyCascade(false); });
  applyCascade(false);
})();
");

local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
