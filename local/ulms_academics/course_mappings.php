<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/locallib.php');
require_once($CFG->dirroot . '/local/ulms_dashboard/lib.php');

global $DB, $PAGE, $OUTPUT;

/**
 * Builds a sortable column header link.
 *
 * This remains page-local because it only serves the course-mappings table
 * rendering flow and no second academics view currently shares the same
 * sortable-header presentation pattern.
 *
 * @param string $path
 * @param array $params
 * @param string $field
 * @param string $label
 * @param string $currentsort
 * @param string $currentdir
 * @return string
 */
function local_ulms_academics_course_mapping_sort_link(
    string $path,
    array $params,
    string $field,
    string $label,
    string $currentsort,
    string $currentdir
): string {
    $nextdir = ($currentsort === $field && strtoupper($currentdir) === 'ASC') ? 'DESC' : 'ASC';
    $linkparams = $params + [
        'sort' => $field,
        'dir' => $nextdir,
        'page' => 0,
    ];

    if ($currentsort === $field) {
        $label .= strtoupper($currentdir) === 'DESC' ? ' (DESC)' : ' (ASC)';
    }

    return html_writer::link(new moodle_url($path, $linkparams), $label);
}

require_login();

$context = \context::instance_by_id(context_system::instance()->id);
require_capability('local/ulms_academics:manageacademics', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$deleteid = optional_param('deleteid', 0, PARAM_INT);
$editid = optional_param('editid', 0, PARAM_INT);
$downloadtemplate = optional_param('downloadtemplate', 0, PARAM_BOOL);
$export = optional_param('export', '', PARAM_ALPHA);
$previewimport = optional_param('previewimport', 0, PARAM_BOOL);
$confirmimport = optional_param('confirmimport', 0, PARAM_BOOL);
$search = optional_param('search', '', PARAM_TEXT);
$facultyfilter = optional_param('facultyfilter', 0, PARAM_INT);
$departmentfilter = optional_param('departmentfilter', 0, PARAM_INT);
$programmefilter = optional_param('programmefilter', 0, PARAM_INT);
$semesterfilter = optional_param('semesterfilter', -1, PARAM_INT);
$levelfilter = optional_param('levelfilter', -1, PARAM_INT);
$sessionfilter = optional_param('sessionfilter', -1, PARAM_INT);
$coursetypefilter = optional_param('coursetypefilter', '', PARAM_ALPHA);
$sort = optional_param('sort', 'faculty', PARAM_ALPHA);
$dir = optional_param('dir', 'ASC', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 20, PARAM_INT);

$service = new \local_ulms_academics\local\service\academic_structure_service();
$routingservice = new \local_ulms_auth\local\service\landing_page_service();
$notifications = [];
$errors = [];
$previewdata = null;
$previewpayload = '';
$filtermessages = [];

$mappingstateparams = [
    'search' => $search,
    'facultyfilter' => $facultyfilter,
    'departmentfilter' => $departmentfilter,
    'programmefilter' => $programmefilter,
    'semesterfilter' => $semesterfilter,
    'levelfilter' => $levelfilter,
    'sessionfilter' => $sessionfilter,
    'coursetypefilter' => $coursetypefilter,
    'sort' => $sort,
    'dir' => $dir,
    'page' => $page,
    'perpage' => $perpage,
];
$contextparams = local_ulms_academics_build_mapping_context_params($mappingstateparams);
$formparams = local_ulms_academics_build_mapping_form_params($contextparams, $editid);
$routeparams = $formparams;
if ($action !== '') {
    $routeparams['action'] = $action;
}
if ($deleteid > 0) {
    $routeparams['deleteid'] = $deleteid;
    $routeparams['sesskey'] = sesskey();
}
if ($editid > 0) {
    $routeparams['editid'] = $editid;
}
if ($downloadtemplate) {
    $routeparams['downloadtemplate'] = 1;
}
if ($export !== '') {
    $routeparams['export'] = $export;
}
$routingservice->maybe_redirect_legacy_request('management.academicsmappings', $routeparams);
$baseurl = $routingservice->get_url_for_route('management.academicsmappings');
$contexturl = $routingservice->get_url_for_route('management.academicsmappings', $contextparams);
$formurl = $routingservice->get_url_for_route('management.academicsmappings', $formparams);
$baseurlstring = $baseurl->out(false);
$contexturlstring = $contexturl->out(false);
$formurlstring = $formurl->out(false);

$PAGE->set_context($context);
$PAGE->set_url($formurl);
$PAGE->set_title(get_string('managecoursemappings', 'local_ulms_academics'));
$PAGE->set_heading(get_string('managecoursemappings', 'local_ulms_academics'));
$PAGE->set_pagelayout('ulmsdashboard');

if ($downloadtemplate) {
    $filename = 'ulms-programme-course-mapping-template.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    foreach ($service->get_course_mapping_template_rows() as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

if ($export === 'csv') {
    $filename = 'ulms-programme-course-mappings.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    foreach ($service->get_course_mapping_export_rows(
        $search,
        $facultyfilter,
        $departmentfilter,
        $programmefilter,
        $semesterfilter,
        $coursetypefilter,
        $sort,
        $dir,
        $levelfilter,
        $sessionfilter
    ) as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

if ($action === 'delete' && $deleteid && confirm_sesskey()) {
    $result = $service->delete_course_mapping($deleteid);
    redirect(
        $contexturl,
        $result['message'],
        null,
        $result['success']
            ? \core\output\notification::NOTIFY_SUCCESS
            : \core\output\notification::NOTIFY_ERROR
    );
}

if ($previewimport && confirm_sesskey()) {
    if (empty($_FILES['csvfile']['tmp_name']) || !is_uploaded_file($_FILES['csvfile']['tmp_name'])) {
        $errors[] = get_string('required');
    } else {
        $csvresult = $service->parse_csv_upload_file($_FILES['csvfile']['tmp_name']);
        $rows = $csvresult['rows'];
        $errors = array_merge($errors, $csvresult['errors']);
        if (empty($errors) && empty($rows)) {
            $errors[] = get_string('csvemptyfile', 'local_ulms_academics');
        }

        if (empty($errors)) {
            $previewstate = $service->create_preview_submission_state(
                $rows,
                fn(array $previewrows): array => $service->preview_course_mapping_rows($previewrows)
            );
            $previewdata = $previewstate['previewdata'];
            $previewpayload = $previewstate['previewpayload'];
            $errors = array_merge($errors, $previewstate['errors']);
        }
    }
}

if ($confirmimport && confirm_sesskey()) {
    $payload = required_param('importpayload', PARAM_RAW_TRIMMED);
    $confirmstate = $service->execute_confirmed_preview_import(
        $payload,
        fn(array $previewrows): array => $service->preview_course_mapping_rows($previewrows),
        fn(array $importrows): array => $service->import_course_mapping_rows($importrows)
    );
    $previewdata = $confirmstate['previewdata'];
    $previewpayload = $confirmstate['previewpayload'];
    $errors = array_merge($errors, $confirmstate['errors']);

    if ($confirmstate['result'] !== null) {
        $result = $confirmstate['result'];
            $notifications[] = [
                'message' => get_string('csvimportcompleted', 'local_ulms_academics', (object)$result),
                'type' => \core\output\notification::NOTIFY_SUCCESS,
            ];
    }
}

if (optional_param('savemapping', 0, PARAM_BOOL) && confirm_sesskey()) {
    $result = $service->save_course_mapping([
        'mappingid' => optional_param('mappingid', 0, PARAM_INT),
        'programmeid' => optional_param('programmeid', 0, PARAM_INT),
        'moodlecourseid' => optional_param('moodlecourseid', 0, PARAM_INT),
        'semesterid' => optional_param('semesterid', 0, PARAM_INT),
        'levelid' => optional_param('levelid', 0, PARAM_INT),
        'coursetype' => optional_param('coursetype', 'core', PARAM_ALPHA),
        'iscore' => optional_param('iscore', 0, PARAM_BOOL),
    ]);
    if ($result['success']) {
        redirect($contexturl, $result['message'], null, \core\output\notification::NOTIFY_SUCCESS);
    }

    $notifications[] = [
        'message' => $result['message'],
        'type' => \core\output\notification::NOTIFY_ERROR,
    ];
}

$allprogrammerecords = $service->get_records_for_entity('programmes');
$allprogrammes = [];
foreach ($allprogrammerecords as $programme) {
    $allprogrammes[(int)$programme->id] = $programme->name;
}
$alldepartmentrecords = $service->get_records_for_entity('departments');
$faculties = [];
foreach ($service->get_records_for_entity('faculties') as $faculty) {
    $faculties[(int)$faculty->id] = $faculty->name;
}
$departments = $service->get_department_options_for_faculty($facultyfilter);
if ($departmentfilter > 0 && !array_key_exists($departmentfilter, $departments)) {
    $departmentfilter = 0;
    $filtermessages[] = get_string('mappingfilterdepartmentreset', 'local_ulms_academics');
}

$filteredprogrammes = $service->get_programme_options_for_hierarchy($facultyfilter, $departmentfilter);
if ($programmefilter > 0 && !array_key_exists($programmefilter, $filteredprogrammes)) {
    $programmefilter = 0;
    $filtermessages[] = get_string('mappingfilterprogrammereset', 'local_ulms_academics');
}

$courses = $service->get_moodle_course_options();
$semesters = $service->get_semester_options();
$sessions = $service->get_records_for_entity('sessions');
$sessionoptions = [0 => get_string('all')];
foreach ($sessions as $s) {
    $sessionoptions[(int)$s->id] = sprintf('%s (%s)', $s->code, $s->name ?? $s->code);
}
$levels = $DB->get_manager()->table_exists('local_ulms_levels')
    ? $DB->get_records_menu('local_ulms_levels', ['status' => 'active'], 'sortorder ASC, id ASC', 'id, name')
    : [];
$leveloptions = [0 => get_string('mappinglevelwide', 'local_ulms_academics')] + $levels;
$levelfilteroptions = [-1 => get_string('all')] + $levels;
$sessionfilteroptions = [-1 => get_string('all')] + $sessionoptions;
$coursetypes = $service->get_course_type_options();
$facultyfilters = [0 => get_string('all')] + $faculties;
$departmentfilters = [0 => get_string('all')] + $departments;
$semesterfilters = [-1 => get_string('all')] + $semesters;
$programmefilters = [0 => get_string('all')] + $filteredprogrammes;
$coursetypefilters = ['' => get_string('all')] + $coursetypes;
$perpageoptions = [10 => 10, 20 => 20, 50 => 50, 100 => 100];
if (!array_key_exists($perpage, $perpageoptions)) {
    $perpage = 20;
}
if ($levelfilter > 0 && !array_key_exists($levelfilter, $levels)) {
    $levelfilter = -1;
    $filtermessages[] = get_string('mappingfilterlevelreset', 'local_ulms_academics');
}
if ($sessionfilter > 0 && !isset($sessions[$sessionfilter])) {
    $sessionfilter = -1;
    $filtermessages[] = get_string('mappingfiltersessionreset', 'local_ulms_academics');
}
$summary = $service->get_course_mapping_summary(
    $search,
    $facultyfilter,
    $departmentfilter,
    $programmefilter,
    $semesterfilter,
    $coursetypefilter,
    $levelfilter,
    $sessionfilter
);
$totalmappings = $service->count_course_mappings(
    $search,
    $facultyfilter,
    $departmentfilter,
    $programmefilter,
    $semesterfilter,
    $coursetypefilter,
    $levelfilter,
    $sessionfilter
);
$mappings = $service->get_course_mappings(
    $search,
    $facultyfilter,
    $departmentfilter,
    $programmefilter,
    $semesterfilter,
    $coursetypefilter,
    $sort,
    $dir,
    $page * $perpage,
    $perpage,
    $levelfilter,
    $sessionfilter
);
$editmapping = $editid > 0 ? $service->get_course_mapping($editid) : false;
$formvalues = [
    'mappingid' => $editmapping->id ?? 0,
    'programmeid' => $editmapping->programmeid ?? 0,
    'moodlecourseid' => $editmapping->moodlecourseid ?? 0,
    'semesterid' => $editmapping->semesterid ?? 0,
    'levelid' => $editmapping->levelid ?? 0,
    'sessionid' => 0,
    'coursetype' => $editmapping->coursetype ?? 'core',
    'iscore' => isset($editmapping->iscore) ? (int)$editmapping->iscore : 1,
];
if (!empty($formvalues['semesterid'])) {
    $sem = $DB->get_record('local_ulms_semesters', ['id' => (int)$formvalues['semesterid']], 'sessionid', IGNORE_MISSING);
    if ($sem) {
        $formvalues['sessionid'] = (int)$sem->sessionid;
    }
}
$formfacultyid = optional_param('formfacultyid', 0, PARAM_INT);
$formdepartmentid = optional_param('formdepartmentid', 0, PARAM_INT);

$selectedprogramme = $formvalues['programmeid'] > 0
    ? ($allprogrammerecords[$formvalues['programmeid']] ?? null)
    : null;
if ($formdepartmentid <= 0 && $selectedprogramme) {
    $formdepartmentid = (int)($selectedprogramme->departmentid ?? 0);
}

$selecteddepartment = $formdepartmentid > 0
    ? ($alldepartmentrecords[$formdepartmentid] ?? null)
    : null;
if ($formfacultyid <= 0 && $selecteddepartment) {
    $formfacultyid = (int)($selecteddepartment->facultyid ?? 0);
}

if ($formfacultyid > 0 && $selecteddepartment && (int)($selecteddepartment->facultyid ?? 0) !== $formfacultyid) {
    $formdepartmentid = 0;
    $selecteddepartment = null;
}

$formdepartmentoptions = [0 => get_string('choose')] + $service->get_department_options_for_faculty($formfacultyid);
if ($formdepartmentid > 0 && !array_key_exists($formdepartmentid, $formdepartmentoptions)) {
    $formdepartmentid = 0;
}

$formprogrammeoptions = [0 => get_string('choose')] + $service->get_programme_options_for_hierarchy(
    $formfacultyid,
    $formdepartmentid
);
if ($formvalues['programmeid'] > 0 && !array_key_exists($formvalues['programmeid'], $formprogrammeoptions)) {
    $formvalues['programmeid'] = 0;
}

$departmentjs = [];
foreach ($alldepartmentrecords as $department) {
    $departmentjs[] = [
        'id' => (int)$department->id,
        'name' => $department->name,
        'facultyid' => (int)($department->facultyid ?? 0),
    ];
}

$programmejs = [];
foreach ($allprogrammerecords as $programme) {
    $programmejs[] = [
        'id' => (int)$programme->id,
        'name' => $programme->name,
        'departmentid' => (int)($programme->departmentid ?? 0),
        'facultyid' => isset($alldepartmentrecords[$programme->departmentid])
            ? (int)($alldepartmentrecords[$programme->departmentid]->facultyid ?? 0)
            : 0,
    ];
}

$PAGE->requires->js_init_code(
    '(function() {' .
    'const departments = ' . json_encode($departmentjs) . ';' .
    'const programmes = ' . json_encode($programmejs) . ';' .
    'const facultySelect = document.getElementById("id_formfacultyid");' .
    'const departmentSelect = document.getElementById("id_formdepartmentid");' .
    'const programmeSelect = document.getElementById("id_programmeid");' .
    'if (!facultySelect || !departmentSelect || !programmeSelect) { return; }' .
    'const chooseLabel = ' . json_encode(get_string('choose')) . ';' .
    'const fillSelect = function(select, items, selectedValue) {' .
        'const expected = String(selectedValue || 0);' .
        'select.innerHTML = "";' .
        'const placeholder = document.createElement("option");' .
        'placeholder.value = "0";' .
        'placeholder.textContent = chooseLabel;' .
        'select.appendChild(placeholder);' .
        'let hasMatch = false;' .
        'items.forEach(function(item) {' .
            'const option = document.createElement("option");' .
            'option.value = String(item.id);' .
            'option.textContent = item.name;' .
            'if (String(item.id) === expected) { option.selected = true; hasMatch = true; }' .
            'select.appendChild(option);' .
        '});' .
        'if (!hasMatch) { select.value = "0"; }' .
    '};' .
    'const syncProgrammeOptions = function() {' .
        'const facultyId = parseInt(facultySelect.value || "0", 10);' .
        'const departmentId = parseInt(departmentSelect.value || "0", 10);' .
        'const currentProgrammeId = parseInt(programmeSelect.value || "0", 10);' .
        'const filteredProgrammes = programmes.filter(function(programme) {' .
            'const matchesFaculty = facultyId === 0 || programme.facultyid === facultyId;' .
            'const matchesDepartment = departmentId === 0 || programme.departmentid === departmentId;' .
            'return matchesFaculty && matchesDepartment;' .
        '});' .
        'fillSelect(programmeSelect, filteredProgrammes, currentProgrammeId);' .
    '};' .
    'facultySelect.addEventListener("change", function() {' .
        'const facultyId = parseInt(facultySelect.value || "0", 10);' .
        'const filteredDepartments = departments.filter(function(department) {' .
            'return facultyId === 0 || department.facultyid === facultyId;' .
        '});' .
        'fillSelect(departmentSelect, filteredDepartments, 0);' .
        'syncProgrammeOptions();' .
    '});' .
    'departmentSelect.addEventListener("change", syncProgrammeOptions);' .
    'syncProgrammeOptions();' .
    '})();'
);

$filterparams = local_ulms_academics_build_mapping_context_params($mappingstateparams);

$activefilters = [];
if ($search !== '') {
    $activefilters[] = get_string('search') . ': ' . $search;
}
if ($facultyfilter > 0 && isset($faculties[$facultyfilter])) {
    $activefilters[] = get_string('faculties', 'local_ulms_academics') . ': ' . $faculties[$facultyfilter];
}
if ($departmentfilter > 0 && isset($departments[$departmentfilter])) {
    $activefilters[] = get_string('departments', 'local_ulms_academics') . ': ' . $departments[$departmentfilter];
}
if ($programmefilter > 0 && isset($filteredprogrammes[$programmefilter])) {
    $activefilters[] = get_string('programmes', 'local_ulms_academics') . ': ' . $filteredprogrammes[$programmefilter];
}
if ($semesterfilter === 0) {
    $activefilters[] = get_string('semesters', 'local_ulms_academics') . ': ' . get_string('notset', 'local_ulms_academics');
} else if ($semesterfilter > 0 && isset($semesters[$semesterfilter])) {
    $activefilters[] = get_string('semesters', 'local_ulms_academics') . ': ' . $semesters[$semesterfilter];
}
if ($levelfilter === 0) {
    $activefilters[] = get_string('levels', 'local_ulms_academics') . ': ' . get_string('mappinglevelwide', 'local_ulms_academics');
} else if ($levelfilter > 0 && isset($levels[$levelfilter])) {
    $activefilters[] = get_string('levels', 'local_ulms_academics') . ': ' . $levels[$levelfilter];
}
if ($sessionfilter === 0) {
    $activefilters[] = get_string('academicsessions', 'local_ulms_academics') . ': ' . get_string('notset', 'local_ulms_academics');
} else if ($sessionfilter > 0 && isset($sessionoptions[$sessionfilter])) {
    $activefilters[] = get_string('academicsessions', 'local_ulms_academics') . ': ' . $sessionoptions[$sessionfilter];
}
if ($coursetypefilter !== '' && isset($coursetypes[$coursetypefilter])) {
    $activefilters[] = get_string('mappingcoursetype', 'local_ulms_academics') . ': ' . $coursetypes[$coursetypefilter];
}
if ($sort !== 'faculty' || strtoupper($dir) !== 'ASC') {
    $activefilters[] = get_string('mappingcurrentsort', 'local_ulms_academics', $sort . ' ' . strtoupper($dir));
}

$visiblecount = count($mappings);
$rangestart = $totalmappings > 0 ? ($page * $perpage) + 1 : 0;
$rangeend = $totalmappings > 0 ? min(($page * $perpage) + $visiblecount, $totalmappings) : 0;

echo $OUTPUT->header();
echo html_writer::start_div('ulms-page');
$headercontext = local_ulms_dashboard_get_management_header_context('academics');
$headercontext['showtitle'] = false;
echo $OUTPUT->render_from_template('theme_ulms_university/student_portal_context_header', $headercontext);
echo html_writer::start_div('ulms-page-header');
echo html_writer::tag('div', get_string('pluginname', 'local_ulms_academics'), ['class' => 'ulms-page-header__eyebrow']);
echo html_writer::start_div('ulms-page-header__content');
echo html_writer::start_div('ulms-page-header__main');
echo html_writer::tag('h1', get_string('managecoursemappings', 'local_ulms_academics'), ['class' => 'ulms-page-header__title']);
echo html_writer::tag('p', get_string('managecoursemappingsdesc', 'local_ulms_academics'), ['class' => 'ulms-page-header__meta']);
echo html_writer::end_div();
echo html_writer::div(
    html_writer::link(
        $routingservice->get_url_for_route('management.academicsmappings', ['downloadtemplate' => 1]),
        get_string('mappingdownloadtemplate', 'local_ulms_academics'),
        ['class' => 'btn btn-light']
    ) .
    html_writer::link(
        $routingservice->get_url_for_route('management.academicsmappings', ['export' => 'csv'] + $filterparams),
        get_string('mappingexportcsv', 'local_ulms_academics'),
        ['class' => 'btn btn-light']
    ),
    'ulms-page-header__actions'
);
echo html_writer::end_div();
echo html_writer::end_div();

foreach ($notifications as $notification) {
    echo $OUTPUT->notification($notification['message'], $notification['type']);
}

foreach ($filtermessages as $message) {
    echo $OUTPUT->notification($message, \core\output\notification::NOTIFY_INFO);
}

if (!empty($activefilters)) {
    echo html_writer::start_div('ulms-chip-list');
    foreach ($activefilters as $filterlabel) {
        echo html_writer::tag(
            'span',
            s($filterlabel),
            ['class' => 'ulms-chip']
        );
    }
    echo html_writer::end_div();
}

echo html_writer::start_div('ulms-panel ulms-panel--soft');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', get_string('mappingsummaryheading', 'local_ulms_academics'), ['class' => 'ulms-panel__title']);
echo html_writer::tag(
    'p',
    get_string('mappingresultssummary', 'local_ulms_academics', (object)[
        'start' => $rangestart,
        'end' => $rangeend,
        'total' => $totalmappings,
    ]),
    ['class' => 'ulms-panel__subtitle']
);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');
echo html_writer::start_div('ulms-kpi-grid');
foreach ([
    ['label' => get_string('mappingstotal', 'local_ulms_academics'), 'value' => $summary['totalmappings']],
    ['label' => get_string('mappingcoursescount', 'local_ulms_academics'), 'value' => $summary['totalcourses']],
    ['label' => get_string('mappingprogrammescount', 'local_ulms_academics'), 'value' => $summary['totalprogrammes']],
    ['label' => get_string('mappingcorecount', 'local_ulms_academics'), 'value' => $summary['coremappings']],
] as $item) {
    echo html_writer::start_div('ulms-kpi-card');
    echo html_writer::tag('div', format_string($item['label']), ['class' => 'ulms-kpi-card__label']);
    echo html_writer::tag('div', format_string((string)$item['value']), ['class' => 'ulms-kpi-card__value']);
    echo html_writer::end_div();
}
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

if (!empty($errors)) {
    echo $OUTPUT->notification(get_string('csvimporterrors', 'local_ulms_academics'), \core\output\notification::NOTIFY_ERROR);
    echo html_writer::alist($errors);
}

if ($previewdata !== null) {
    $previewtable = new html_table();
    $previewtable->attributes['class'] = 'generaltable';
    $previewtable->head = [
        get_string('line', 'local_ulms_academics'),
        get_string('programmes', 'local_ulms_academics'),
        get_string('mappingmoodlecourse', 'local_ulms_academics'),
        get_string('academicsessions', 'local_ulms_academics'),
        get_string('semesters', 'local_ulms_academics'),
        get_string('levels', 'local_ulms_academics'),
        get_string('mappingcoursetype', 'local_ulms_academics'),
        get_string('mappingiscore', 'local_ulms_academics'),
        get_string('actions', 'local_ulms_academics'),
        get_string('validation', 'local_ulms_academics'),
    ];

    foreach ($previewdata['previewrows'] as $row) {
        $previewtable->data[] = [
            (string)$row['linenumber'],
            s((string)$row['programme']),
            s((string)$row['course']),
            s((string)($row['session'] ?? '')),
            s((string)$row['semester']),
            s((string)($row['level'] ?? '')),
            s((string)$row['coursetype']),
            s((string)$row['iscore']),
            s((string)$row['action']),
            s((string)$row['message']),
        ];
    }

    echo html_writer::start_div('ulms-panel');
    echo html_writer::start_div('ulms-panel__header');
    echo html_writer::start_div();
    echo html_writer::tag('h2', get_string('mappingimportpreview', 'local_ulms_academics'), ['class' => 'ulms-panel__title']);
    echo html_writer::tag(
        'p',
        get_string('csvpreviewsummary', 'local_ulms_academics', (object)[
            'processed' => $previewdata['processed'],
            'valid' => $previewdata['valid'],
            'invalid' => $previewdata['invalid'],
        ]),
        ['class' => 'ulms-panel__subtitle']
    );
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::start_div('ulms-panel__body');
    echo html_writer::div(html_writer::table($previewtable), 'ulms-table-wrap');

    if (($previewdata['invalid'] ?? 0) === 0 && ($previewdata['valid'] ?? 0) > 0 && $previewpayload !== '') {
        echo html_writer::start_tag('form', [
            'method' => 'post',
            'action' => $contexturlstring,
            'class' => 'mt-3',
            'data-ulms-loading-form' => 'true',
        ]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'importpayload', 'value' => $previewpayload]);
        echo html_writer::empty_tag('input', [
            'type' => 'submit',
            'name' => 'confirmimport',
            'value' => get_string('mappingconfirmimport', 'local_ulms_academics'),
            'class' => 'btn btn-primary',
            'data-loading-text' => get_string('mappingconfirmimport', 'local_ulms_academics'),
        ]);
        echo html_writer::end_tag('form');
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => $baseurlstring,
    'class' => 'ulms-panel',
    'data-ulms-loading-form' => 'true',
]);
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', get_string('mappingfiltersheading', 'local_ulms_academics'), ['class' => 'ulms-panel__title']);
echo html_writer::tag('p', get_string('mappingactivefilters', 'local_ulms_academics'), ['class' => 'ulms-panel__subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sort', 'value' => $sort]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'dir', 'value' => $dir]);
echo html_writer::start_div('ulms-filter-grid');
echo html_writer::start_div('ulms-filter-field ulms-filter-field--wide');
echo html_writer::label(get_string('search'), 'id_mapping_search');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'search',
    'id' => 'id_mapping_search',
    'value' => $search,
    'class' => 'form-control',
]);
echo html_writer::end_div();
echo html_writer::start_div('ulms-filter-field');
echo html_writer::label(get_string('faculties', 'local_ulms_academics'), 'id_facultyfilter');
echo html_writer::select($facultyfilters, 'facultyfilter', $facultyfilter, false, [
    'id' => 'id_facultyfilter',
    'class' => 'custom-select',
    'onchange' => 'this.form.submit()',
]);
echo html_writer::end_div();
echo html_writer::start_div('ulms-filter-field');
echo html_writer::label(get_string('departments', 'local_ulms_academics'), 'id_departmentfilter');
echo html_writer::select($departmentfilters, 'departmentfilter', $departmentfilter, false, [
    'id' => 'id_departmentfilter',
    'class' => 'custom-select',
    'onchange' => 'this.form.submit()',
]);
echo html_writer::end_div();
echo html_writer::start_div('ulms-filter-field');
echo html_writer::label(get_string('programmes', 'local_ulms_academics'), 'id_programmefilter');
echo html_writer::select($programmefilters, 'programmefilter', $programmefilter, false, [
    'id' => 'id_programmefilter',
    'class' => 'custom-select',
    'onchange' => 'this.form.submit()',
]);
echo html_writer::end_div();
echo html_writer::start_div('ulms-filter-field');
echo html_writer::label(get_string('semesters', 'local_ulms_academics'), 'id_semesterfilter');
echo html_writer::select($semesterfilters, 'semesterfilter', $semesterfilter, false, [
    'id' => 'id_semesterfilter',
    'class' => 'custom-select',
]);
echo html_writer::end_div();
echo html_writer::start_div('ulms-filter-field');
echo html_writer::label(get_string('levels', 'local_ulms_academics'), 'id_levelfilter');
echo html_writer::select($levelfilteroptions, 'levelfilter', $levelfilter, false, [
    'id' => 'id_levelfilter',
    'class' => 'custom-select',
]);
echo html_writer::end_div();
echo html_writer::start_div('ulms-filter-field');
echo html_writer::label(get_string('academicsessions', 'local_ulms_academics'), 'id_sessionfilter');
echo html_writer::select($sessionfilteroptions, 'sessionfilter', $sessionfilter, false, [
    'id' => 'id_sessionfilter',
    'class' => 'custom-select',
]);
echo html_writer::end_div();
echo html_writer::start_div('ulms-filter-field');
echo html_writer::label(get_string('mappingcoursetype', 'local_ulms_academics'), 'id_coursetypefilter');
echo html_writer::select($coursetypefilters, 'coursetypefilter', $coursetypefilter, false, [
    'id' => 'id_coursetypefilter',
    'class' => 'custom-select',
]);
echo html_writer::end_div();
echo html_writer::start_div('ulms-filter-field');
echo html_writer::label(get_string('paginationperpage', 'local_ulms_academics'), 'id_perpage');
echo html_writer::select($perpageoptions, 'perpage', $perpage, false, [
    'id' => 'id_perpage',
    'class' => 'custom-select',
]);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::div(
    html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('applymappingfilters', 'local_ulms_academics'),
    'class' => 'btn btn-primary',
    'data-loading-text' => get_string('applymappingfilters', 'local_ulms_academics'),
]) .
html_writer::link(
    $baseurlstring,
    get_string('clearfilters', 'local_ulms_academics'),
    ['class' => 'btn btn-outline-secondary']
),
    'ulms-filter-actions'
);
echo html_writer::end_div();
echo html_writer::end_tag('form');

echo html_writer::start_div('ulms-layout-grid ulms-layout-grid--sidebar');

echo html_writer::start_div('ulms-panel');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', get_string('entityrecords', 'local_ulms_academics'), ['class' => 'ulms-panel__title']);
echo html_writer::tag(
    'p',
    get_string('mappingresultssummary', 'local_ulms_academics', (object)[
        'start' => $rangestart,
        'end' => $rangeend,
        'total' => $totalmappings,
    ]),
    ['class' => 'ulms-panel__subtitle']
);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');

$table = new html_table();
$table->attributes['class'] = 'generaltable';
$sortableparams = local_ulms_academics_select_request_params($mappingstateparams, [
    'search',
    'facultyfilter',
    'departmentfilter',
    'programmefilter',
    'semesterfilter',
    'levelfilter',
    'sessionfilter',
    'coursetypefilter',
    'perpage',
]);
$table->head = [
    local_ulms_academics_course_mapping_sort_link(
        $routingservice->get_path_for_route('management.academicsmappings'),
        $sortableparams,
        'course',
        get_string('mappingmoodlecourse', 'local_ulms_academics'),
        $sort,
        $dir
    ),
    local_ulms_academics_course_mapping_sort_link(
        $routingservice->get_path_for_route('management.academicsmappings'),
        $sortableparams,
        'programme',
        get_string('programmes', 'local_ulms_academics'),
        $sort,
        $dir
    ),
    local_ulms_academics_course_mapping_sort_link(
        $routingservice->get_path_for_route('management.academicsmappings'),
        $sortableparams,
        'department',
        get_string('departments', 'local_ulms_academics'),
        $sort,
        $dir
    ),
    local_ulms_academics_course_mapping_sort_link(
        $routingservice->get_path_for_route('management.academicsmappings'),
        $sortableparams,
        'faculty',
        get_string('faculties', 'local_ulms_academics'),
        $sort,
        $dir
    ),
    local_ulms_academics_course_mapping_sort_link(
        $routingservice->get_path_for_route('management.academicsmappings'),
        $sortableparams,
        'semester',
        get_string('semesters', 'local_ulms_academics'),
        $sort,
        $dir
    ),
    local_ulms_academics_course_mapping_sort_link(
        $routingservice->get_path_for_route('management.academicsmappings'),
        $sortableparams,
        'level',
        get_string('mappingcolumnlevel', 'local_ulms_academics'),
        $sort,
        $dir
    ),
    local_ulms_academics_course_mapping_sort_link(
        $routingservice->get_path_for_route('management.academicsmappings'),
        $sortableparams,
        'session',
        get_string('mappingcolumnsession', 'local_ulms_academics'),
        $sort,
        $dir
    ),
    local_ulms_academics_course_mapping_sort_link(
        $routingservice->get_path_for_route('management.academicsmappings'),
        $sortableparams,
        'coursetype',
        get_string('mappingcoursetype', 'local_ulms_academics'),
        $sort,
        $dir
    ),
    local_ulms_academics_course_mapping_sort_link(
        $routingservice->get_path_for_route('management.academicsmappings'),
        $sortableparams,
        'iscore',
        get_string('mappingiscore', 'local_ulms_academics'),
        $sort,
        $dir
    ),
    get_string('mappingcolumnlecturers', 'local_ulms_academics'),
    get_string('actions', 'local_ulms_academics'),
];

$lecturersbycourse = [];
if (!empty($mappings)) {
    $courseids = [];
    foreach ($mappings as $m) {
        $cid = (int)($m->moodlecourseid ?? 0);
        if ($cid > 0) {
            $courseids[$cid] = $cid;
        }
    }
    if (!empty($courseids)) {
        [$insql, $inparams] = $DB->get_in_or_equal(array_values($courseids), SQL_PARAMS_QM);
        $sql = "SELECT ctx.instanceid AS courseid, u.id AS userid, u.firstname, u.lastname, u.idnumber
                  FROM {context} ctx
                  JOIN {role_assignments} ra ON ra.contextid = ctx.id
                  JOIN {role} r ON r.id = ra.roleid AND r.shortname = ?
                  JOIN {user} u ON u.id = ra.userid AND u.deleted = 0
                 WHERE ctx.contextlevel = ?
                   AND ctx.instanceid {$insql}
              ORDER BY u.lastname ASC, u.firstname ASC";
        $params = array_merge(['editingteacher', CONTEXT_COURSE], $inparams);
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $row) {
            $cid = (int)$row->courseid;
            if (!isset($lecturersbycourse[$cid])) {
                $lecturersbycourse[$cid] = [];
            }
            $lecturersbycourse[$cid][] = $row;
        }
        $rs->close();
    }
}

$allocationsurl = $routingservice->get_url_for_route('management.lecturers', $filterparams);

foreach ($mappings as $mapping) {
    $deleteurl = $routingservice->get_url_for_route('management.academicsmappings', [
        'action' => 'delete',
        'deleteid' => $mapping->id,
        'sesskey' => sesskey(),
    ] + $filterparams);
    $editurl = $routingservice->get_url_for_route('management.academicsmappings', [
        'editid' => $mapping->id,
    ] + $filterparams);

    $leveldisplay = get_string('mappinglevelwide', 'local_ulms_academics');
    $levelbadgeclass = 'ulms-badge ulms-badge--soft ulms-badge--muted';
    if (!empty($mapping->levelname) || !empty($mapping->levelcode)) {
        $leveldisplay = trim(sprintf('%s %s', $mapping->levelcode ?? '', $mapping->levelname ?? ''));
        $levelbadgeclass = 'ulms-badge ulms-badge--soft ulms-badge--info font-weight-bold';
    }
    $levelbadge = html_writer::tag('span', s($leveldisplay), ['class' => $levelbadgeclass]);
    $sessionname = '';
    if (!empty($mapping->semestersessionid) && isset($sessions[$mapping->semestersessionid])) {
        $s = $sessions[$mapping->semestersessionid];
        $sessionname = trim(sprintf('%s %s', $s->code ?? '', $s->name ?? ''));
    }
    if ($sessionname === '') {
        $sessionname = get_string('notset', 'local_ulms_academics');
    }

    $table->data[] = [
        format_string($mapping->coursename),
        format_string($mapping->programmename),
        format_string($mapping->departmentname),
        format_string($mapping->facultyname),
        format_string($mapping->semestername ?? get_string('notset', 'local_ulms_academics')),
        $levelbadge,
        format_string($sessionname),
        format_string($coursetypes[$mapping->coursetype] ?? $mapping->coursetype),
        !empty($mapping->iscore) ? get_string('yes') : get_string('no'),
        (static function (int $courseid, array $lecturersbycourse, moodle_url $allocationsurl) use ($mapping): string {
            $cid = $courseid;
            $users = $lecturersbycourse[$cid] ?? [];
            if (empty($users)) {
                $empty = html_writer::tag(
                    'span',
                    get_string('mappinglecturersempty', 'local_ulms_academics'),
                    ['class' => 'text-muted small']
                );
                $link = html_writer::link(
                    $allocationsurl,
                    get_string('mappingmanagelecturers', 'local_ulms_academics'),
                    ['class' => 'btn btn-sm btn-outline-secondary ml-2']
                );
                return $empty . ' ' . $link;
            }
            $chips = [];
            $show = array_slice($users, 0, 3);
            $extra = count($users) - count($show);
            foreach ($show as $u) {
                $name = trim(sprintf('%s %s', $u->firstname ?? '', $u->lastname ?? ''));
                if ($name === '') {
                    $name = '#' . ($u->userid ?? '?');
                }
                $chips[] = html_writer::tag(
                    'span',
                    s($name),
                    ['class' => 'ulms-badge ulms-badge--soft ulms-badge--success']
                );
            }
            if ($extra > 0) {
                $chips[] = html_writer::tag(
                    'span',
                    sprintf('+%d', $extra),
                    [
                        'class' => 'ulms-badge ulms-badge--soft',
                        'title' => get_string('ofmanymore', 'core', $extra),
                    ]
                );
            }
            $link = html_writer::link(
                $allocationsurl,
                get_string('mappingmanagelecturers', 'local_ulms_academics'),
                ['class' => 'btn btn-sm btn-outline-secondary ml-2']
            );
            return implode(' ', $chips) . ' ' . $link;
        })((int)($mapping->moodlecourseid ?? 0), $lecturersbycourse, $allocationsurl),
        html_writer::link($editurl, get_string('edit')) . ' | ' . html_writer::link($deleteurl, get_string('delete')),
    ];
}

if (empty($table->data)) {
    echo html_writer::div(
        html_writer::tag('h3', get_string('nocoursemappings', 'local_ulms_academics'), ['class' => 'ulms-empty-state__title']) .
        html_writer::tag('p', get_string('mappingbulkimportdesc', 'local_ulms_academics'), ['class' => 'ulms-empty-state__meta']),
        'ulms-empty-state',
        ['role' => 'status']
    );
} else {
    echo html_writer::div(html_writer::table($table), 'ulms-table-wrap');
    echo $OUTPUT->render(new paging_bar(
        $totalmappings,
        $page,
        $perpage,
        $routingservice->get_url_for_route('management.academicsmappings', $filterparams)
    ));
}

echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('ulms-layout-grid');
echo html_writer::start_div('ulms-panel ulms-panel--soft');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag(
    'h2',
    $editmapping ? get_string('editmappingheading', 'local_ulms_academics') : get_string('addmappingheading', 'local_ulms_academics'),
    ['class' => 'ulms-panel__title']
);
echo html_writer::tag('p', get_string('mappingformhierarchyhint', 'local_ulms_academics'), ['class' => 'ulms-panel__subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');
echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $formurlstring,
    'class' => '',
    'data-ulms-loading-form' => 'true',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'mappingid', 'value' => (string)$formvalues['mappingid']]);

echo html_writer::start_div('row');

echo html_writer::start_div('col-md-4 mb-3');
echo html_writer::label(get_string('faculties', 'local_ulms_academics'), 'id_formfacultyid');
echo html_writer::select([0 => get_string('choose')] + $faculties, 'formfacultyid', $formfacultyid, false, [
    'id' => 'id_formfacultyid',
    'class' => 'custom-select',
]);
echo html_writer::end_div();

echo html_writer::start_div('col-md-4 mb-3');
echo html_writer::label(get_string('departments', 'local_ulms_academics'), 'id_formdepartmentid');
echo html_writer::select($formdepartmentoptions, 'formdepartmentid', $formdepartmentid, false, [
    'id' => 'id_formdepartmentid',
    'class' => 'custom-select',
]);
echo html_writer::end_div();

echo html_writer::start_div('col-md-4 mb-3');
echo html_writer::label(get_string('programmes', 'local_ulms_academics'), 'id_programmeid');
echo html_writer::select($formprogrammeoptions, 'programmeid', $formvalues['programmeid'], false, [
    'id' => 'id_programmeid',
    'class' => 'custom-select',
]);
echo html_writer::end_div();

echo html_writer::start_div('col-md-6 mb-3');
echo html_writer::label(get_string('mappingmoodlecourse', 'local_ulms_academics'), 'id_moodlecourseid');
echo html_writer::select($courses, 'moodlecourseid', $formvalues['moodlecourseid'], false, [
    'id' => 'id_moodlecourseid',
    'class' => 'custom-select',
]);
$defaultcatid = 0;
$defcat = $DB->get_record('course_categories', ['name' => 'Miscellaneous'], 'id', IGNORE_MISSING);
if (!$defcat) {
    $defcat = $DB->get_record_sql('SELECT id FROM {course_categories} ORDER BY id ASC LIMIT 1', [], IGNORE_MISSING);
}
if ($defcat) {
    $defaultcatid = (int)$defcat->id;
}
$newcourseurl = new moodle_url('/course/edit.php', [
    'category' => $defaultcatid,
    'returnto' => 'url',
    'returnurl' => $contexturl->out_as_local_url(false),
]);
echo html_writer::div(
    html_writer::link(
        $newcourseurl,
        \local_ulms_dashboard\local\service\dashboard_commons::safe_lang_string(
            'mappingcreatenewcourse',
            '+ Create new course →',
            null,
            'local_ulms_academics'
        ),
        ['class' => 'btn btn-sm btn-outline-secondary mt-2', 'target' => '_blank', 'rel' => 'noopener']
    ),
    'ulms-form-subnote'
);
echo html_writer::end_div();

echo html_writer::start_div('col-md-6 mb-3');
echo html_writer::label(get_string('academicsessions', 'local_ulms_academics'), 'id_sessionid');
$sessionselect = [0 => get_string('choosedots')];
foreach ($sessions as $s) {
    $sessionselect[(int)$s->id] = sprintf('%s — %s', $s->code, $s->name ?? $s->code);
}
echo html_writer::select($sessionselect, 'sessionid', $formvalues['sessionid'], false, [
    'id' => 'id_sessionid',
    'class' => 'custom-select',
]);
echo html_writer::end_div();

echo html_writer::start_div('col-md-4 mb-3');
echo html_writer::label(get_string('semesters', 'local_ulms_academics'), 'id_semesterid');
echo html_writer::select($semesters, 'semesterid', $formvalues['semesterid'], false, [
    'id' => 'id_semesterid',
    'class' => 'custom-select',
]);
echo html_writer::end_div();

echo html_writer::start_div('col-md-4 mb-3');
echo html_writer::label(get_string('levels', 'local_ulms_academics'), 'id_levelid');
echo html_writer::select($leveloptions, 'levelid', $formvalues['levelid'], false, [
    'id' => 'id_levelid',
    'class' => 'custom-select',
]);
echo html_writer::end_div();

echo html_writer::start_div('col-md-4 mb-3');
echo html_writer::label(get_string('mappingcoursetype', 'local_ulms_academics'), 'id_coursetype');
echo html_writer::select($coursetypes, 'coursetype', $formvalues['coursetype'], false, [
    'id' => 'id_coursetype',
    'class' => 'custom-select',
]);
echo html_writer::end_div();

echo html_writer::start_div('col-md-4 mb-3');
echo html_writer::label(get_string('mappingiscore', 'local_ulms_academics'), 'id_iscore');
echo html_writer::empty_tag('input', [
    'type' => 'checkbox',
    'name' => 'iscore',
    'id' => 'id_iscore',
    'value' => '1',
    'class' => 'ml-2',
]
    + ($formvalues['iscore'] ? ['checked' => 'checked'] : []));
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'name' => 'savemapping',
    'value' => $editmapping
        ? get_string('updatemapping', 'local_ulms_academics')
        : get_string('savemapping', 'local_ulms_academics'),
    'class' => 'btn btn-primary',
    'data-loading-text' => $editmapping
        ? get_string('updatemapping', 'local_ulms_academics')
        : get_string('savemapping', 'local_ulms_academics'),
]);
if ($editmapping) {
    echo ' ';
    echo html_writer::link(
        $routingservice->get_url_for_route('management.academicsmappings', $filterparams),
        get_string('canceleditmapping', 'local_ulms_academics'),
        ['class' => 'btn btn-outline-secondary']
    );
}
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('ulms-panel');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', get_string('mappingbulkimportheading', 'local_ulms_academics'), ['class' => 'ulms-panel__title']);
echo html_writer::tag('p', get_string('mappingbulkimportdesc', 'local_ulms_academics'), ['class' => 'ulms-panel__subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');
echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $contexturlstring,
    'enctype' => 'multipart/form-data',
    'class' => '',
    'data-ulms-loading-form' => 'true',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::label(get_string('csvupload', 'local_ulms_academics'), 'id_mapping_csvfile');
echo html_writer::empty_tag('input', [
    'type' => 'file',
    'name' => 'csvfile',
    'id' => 'id_mapping_csvfile',
    'accept' => '.csv,text/csv',
    'class' => 'form-control mb-3',
]);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'name' => 'previewimport',
    'value' => get_string('mappingpreviewimport', 'local_ulms_academics'),
    'class' => 'btn btn-outline-primary',
    'data-loading-text' => get_string('mappingpreviewimport', 'local_ulms_academics'),
]);
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

$deptsbyfac = [];
foreach ($alldepartmentrecords as $d) {
    $fid = (int)($d->facultyid ?? 0);
    if (!isset($deptsbyfac[$fid])) {
        $deptsbyfac[$fid] = [];
    }
    $deptsbyfac[$fid][(int)$d->id] = $d->name;
}
$progsbydept = [];
foreach ($allprogrammerecords as $p) {
    $did = (int)($p->departmentid ?? 0);
    if (!isset($progsbydept[$did])) {
        $progsbydept[$did] = [];
    }
    $progsbydept[$did][(int)$p->id] = $p->name;
}
$semsbysession = [];
foreach ($service->get_records_for_entity('semesters') as $sm) {
    $sid = (int)($sm->sessionid ?? 0);
    if (!isset($semsbysession[$sid])) {
        $semsbysession[$sid] = [];
    }
    $semsbysession[$sid][(int)$sm->id] = $sm->name;
}
$choose = get_string('choosedots');
echo html_writer::start_tag('script');
echo 'const ulmsCascade={'
    . 'deptsByFac:' . json_encode($deptsbyfac) . ','
    . 'progsByDept:' . json_encode($progsbydept) . ','
    . 'semsBySession:' . json_encode($semsbysession) . ','
    . 'choose:' . json_encode($choose)
    . '};'
    . "(function(){"
    . "function rebuildSelect(sel,options,active){sel.innerHTML='';const d=document.createElement('option');d.value='0';d.textContent=ulmsCascade.choose;sel.appendChild(d);for(const k of Object.keys(options)){const o=document.createElement('option');o.value=String(k);o.textContent=options[k];if(String(k)===String(active)){o.selected=true;}sel.appendChild(o);}}"
    . "const ff=document.getElementById('id_formfacultyid');const fd=document.getElementById('id_formdepartmentid');const fp=document.getElementById('id_programmeid');"
    . "const fsess=document.getElementById('id_sessionid');const fsem=document.getElementById('id_semesterid');"
    . "if(ff&&fd){ff.addEventListener('change',()=>{const fac=ff.value||'0';rebuildSelect(fd,ulmsCascade.deptsByFac[fac]||{},'0');if(fp){rebuildSelect(fp,{},'0');}});}"
    . "if(fd&&fp){fd.addEventListener('change',()=>{const dept=fd.value||'0';rebuildSelect(fp,ulmsCascade.progsByDept[dept]||{},'0');});}"
    . "if(fsess&&fsem){fsess.addEventListener('change',()=>{const sid=fsess.value||'0';rebuildSelect(fsem,ulmsCascade.semsBySession[sid]||{},'0');});}"
    . "})();";
echo html_writer::end_tag('script');

echo $OUTPUT->footer();
