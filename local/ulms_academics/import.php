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

global $PAGE, $OUTPUT;

$entity = optional_param('entity', 'faculties', PARAM_ALPHAEXT);
$download = optional_param('download', 0, PARAM_BOOL);
$previewimport = optional_param('previewimport', 0, PARAM_BOOL);
$confirmimport = optional_param('confirmimport', 0, PARAM_BOOL);
$service = new \local_ulms_academics\local\service\academic_structure_service();
$routingservice = new \local_ulms_auth\local\service\landing_page_service();
$allowedentities = ['faculties', 'departments', 'programmes', 'courses'];

$importstateparams = [
    'entity' => $entity,
    'download' => $download ? 1 : 0,
    'previewimport' => $previewimport ? 1 : 0,
    'confirmimport' => $confirmimport ? 1 : 0,
];
$routeparams = local_ulms_academics_select_request_params($importstateparams, [
    'entity',
    'download',
    'previewimport',
    'confirmimport',
], true);
$routingservice->maybe_redirect_legacy_request('management.academicsimport', $routeparams);

require_login();

$context = \context::instance_by_id(context_system::instance()->id);
require_capability('local/ulms_academics:manageacademics', $context);
$messages = [];
$errors = [];
$previewdata = null;
$previewpayload = '';

if (!in_array($entity, $allowedentities, true)) {
    $entity = 'faculties';
}

$importstateparams['entity'] = $entity;
$baseurlparams = local_ulms_academics_select_request_params($importstateparams, ['entity'], true);
$url = $routingservice->get_url_for_route('management.academicsimport', $baseurlparams);
$urlstring = $url->out(false);

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('bulkimport', 'local_ulms_academics'));
$PAGE->set_heading(get_string('bulkimport', 'local_ulms_academics'));
$PAGE->set_pagelayout('ulmsdashboard');

$templates = [
    'faculties' => [
        ['code', 'name', 'status'],
        ['SCI', 'College of Science', 'active'],
    ],
    'departments' => [
        ['code', 'name', 'status', 'facultycode'],
        ['CSC', 'Department of Computer Science', 'active', 'SCI'],
    ],
    'programmes' => [
        ['code', 'name', 'status', 'departmentcode', 'awardtype', 'durationyears'],
        ['BSC-CS', 'BSc Computer Science', 'active', 'CSC', 'BSc', '4'],
    ],
    'courses' => [
        ['shortname', 'fullname', 'idnumber', 'category', 'visible', 'summary', 'format', 'numsections', 'lang'],
        ['CSC101', 'Introduction to Computer Science', 'CSC-101-2026', '1', '1', 'An introduction to the fundamentals of computing.', 'topics', '12', 'en'],
    ],
];

if ($download && isset($templates[$entity])) {
    $filename = 'ulms-' . $entity . '-template.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    foreach ($templates[$entity] as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
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
                fn(array $previewrows): array => $service->preview_csv_rows($entity, $previewrows)
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
        fn(array $previewrows): array => $service->preview_csv_rows($entity, $previewrows),
        fn(array $importrows): array => $service->import_csv_rows($entity, $importrows)
    );
    $previewdata = $confirmstate['previewdata'];
    $previewpayload = $confirmstate['previewpayload'];
    $errors = array_merge($errors, $confirmstate['errors']);

    if ($confirmstate['result'] !== null) {
        $result = $confirmstate['result'];
            $messages[] = get_string('csvimportcompleted', 'local_ulms_academics', (object)$result);
    }
}

echo $OUTPUT->header();
echo html_writer::start_div('ulms-page');
$headercontext = local_ulms_dashboard_get_management_header_context('academics');
$headercontext['showtitle'] = false;
echo $OUTPUT->render_from_template('theme_ulms_university/student_portal_context_header', $headercontext);

echo html_writer::start_div('ulms-page-header');
echo html_writer::tag('div', get_string('pluginname', 'local_ulms_academics'), ['class' => 'ulms-page-header__eyebrow']);
echo html_writer::start_div('ulms-page-header__content');
echo html_writer::start_div('ulms-page-header__main');
echo html_writer::tag('h1', get_string('bulkimport', 'local_ulms_academics'), ['class' => 'ulms-page-header__title']);
echo html_writer::tag('p', get_string('bulkimportdesc', 'local_ulms_academics'), ['class' => 'ulms-page-header__meta']);
echo html_writer::end_div();
echo html_writer::div(
    html_writer::link(
        $routingservice->get_url_for_route('management.academics'),
        get_string('returntooverview', 'local_ulms_academics'),
        ['class' => 'btn btn-outline-secondary']
    ),
    'ulms-page-header__actions'
);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('ulms-panel ulms-panel--soft');
echo html_writer::start_div('ulms-panel__body');
echo html_writer::div(
    html_writer::tag('h2', get_string('csvtemplatedesc', 'local_ulms_academics'), ['class' => 'ulms-empty-state__title']) .
    html_writer::tag('p', get_string('selectentitytoimport', 'local_ulms_academics'), ['class' => 'ulms-empty-state__meta']),
    'ulms-empty-state',
    ['role' => 'status']
);
echo html_writer::end_div();
echo html_writer::end_div();

$templatecards = [];
foreach ($allowedentities as $templateentity) {
    $templatecards[] = html_writer::link(
        $routingservice->get_url_for_route('management.academicsimport', ['entity' => $templateentity, 'download' => 1]),
        html_writer::tag('div', $service->get_entity_label($templateentity), ['class' => 'ulms-action-card__title']) .
        html_writer::tag('div', get_string('csvdownloadtemplate', 'local_ulms_academics'), ['class' => 'ulms-action-card__meta']),
        ['class' => 'ulms-action-card']
    );
}

echo html_writer::start_div('ulms-layout-grid');
echo html_writer::start_div('ulms-panel ulms-panel--soft');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', get_string('csvdownloadtemplate', 'local_ulms_academics'), ['class' => 'ulms-panel__title']);
echo html_writer::tag('p', get_string('csvtemplatedesc', 'local_ulms_academics'), ['class' => 'ulms-panel__subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');
echo html_writer::div(implode('', $templatecards), 'ulms-action-grid');
if ($entity === 'courses') {
    $defcat = $DB->get_record('course_categories', ['name' => 'Miscellaneous'], 'id', IGNORE_MISSING);
    if (!$defcat) {
        $defcat = $DB->get_record_sql('SELECT id FROM {course_categories} ORDER BY id ASC LIMIT 1', [], IGNORE_MISSING);
    }
    $defaultcatid = $defcat ? (int)$defcat->id : 1;
    $singlecard = html_writer::link(
        new moodle_url('/course/edit.php', [
            'category' => $defaultcatid,
            'returnto' => 'url',
            'returnurl' => $routingservice->get_url_for_route('management.academicsimport', ['entity' => 'courses'])->out_as_local_url(false),
        ]),
        html_writer::tag('div', \local_ulms_dashboard\local\service\dashboard_commons::safe_lang_string(
            'courses',
            'Courses',
            null,
            'local_ulms_academics'
        ) . ' — ' . get_string('add'), ['class' => 'ulms-action-card__title']) .
        html_writer::tag('div', \local_ulms_dashboard\local\service\dashboard_commons::safe_lang_string(
            'csvonesingle',
            'Add / Create one course at a time',
            null,
            'local_ulms_academics'
        ), ['class' => 'ulms-action-card__meta']),
        ['class' => 'ulms-action-card ulms-action-card--emphasis']
    );
    echo html_writer::div($singlecard, 'ulms-action-grid mt-3');
}
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('ulms-panel');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', get_string('previewcsvimport', 'local_ulms_academics'), ['class' => 'ulms-panel__title']);
echo html_writer::tag('p', get_string('bulkimportdesc', 'local_ulms_academics'), ['class' => 'ulms-panel__subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');

foreach ($messages as $message) {
    echo $OUTPUT->notification($message, \core\output\notification::NOTIFY_SUCCESS);
}

if (!empty($errors)) {
    echo $OUTPUT->notification(get_string('csvimporterrors', 'local_ulms_academics'), \core\output\notification::NOTIFY_ERROR);
    echo html_writer::alist($errors, ['class' => 'mb-4']);
}

if ($previewdata !== null) {
    echo $OUTPUT->notification(
        get_string('csvpreviewsummary', 'local_ulms_academics', (object)[
            'processed' => $previewdata['processed'],
            'valid' => $previewdata['valid'],
            'invalid' => $previewdata['invalid'],
        ]),
        ($previewdata['invalid'] ?? 0) > 0
            ? \core\output\notification::NOTIFY_WARNING
            : \core\output\notification::NOTIFY_INFO
    );

    $previewtable = new html_table();
    $previewtable->attributes['class'] = 'generaltable';
    $previewtable->head = [
        get_string('line', 'local_ulms_academics'),
        get_string('code', 'local_ulms_academics'),
        get_string('name'),
        get_string('parentrecord', 'local_ulms_academics'),
        get_string('status', 'local_ulms_academics'),
        get_string('actions', 'local_ulms_academics'),
        get_string('validation', 'local_ulms_academics'),
    ];

    foreach ($previewdata['previewrows'] as $row) {
        $previewtable->data[] = [
            (string)$row['linenumber'],
            s((string)$row['code']),
            s((string)$row['name']),
            s((string)$row['parent']),
            s((string)$row['status']),
            s((string)$row['action']),
            s((string)$row['message']),
        ];
    }

    echo html_writer::tag('h3', get_string('csvpreviewheading', 'local_ulms_academics'), ['class' => 'h5']);
    echo html_writer::div(html_writer::table($previewtable), 'ulms-table-wrap');

    if (($previewdata['invalid'] ?? 0) === 0 && ($previewdata['valid'] ?? 0) > 0 && $previewpayload !== '') {
        echo html_writer::start_tag('form', [
            'method' => 'post',
            'action' => $urlstring,
            'class' => 'mt-3',
            'data-ulms-loading-form' => 'true',
        ]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'entity', 'value' => $entity]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'importpayload', 'value' => $previewpayload]);
        echo html_writer::empty_tag('input', [
            'type' => 'submit',
            'name' => 'confirmimport',
            'value' => get_string('confirmcsvimport', 'local_ulms_academics'),
            'class' => 'btn btn-primary',
            'data-loading-text' => get_string('confirmcsvimport', 'local_ulms_academics'),
        ]);
        echo html_writer::end_tag('form');
    }
}

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $urlstring,
    'enctype' => 'multipart/form-data',
    'class' => 'ulms-user-form',
    'data-ulms-loading-form' => 'true',
]);
echo html_writer::input_hidden_params($url);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::start_div('ulms-form-grid');

echo html_writer::start_div('ulms-form-field');
echo html_writer::label(get_string('csventity', 'local_ulms_academics'), 'id_entity');
$entityoptions = [
    'faculties' => get_string('faculties', 'local_ulms_academics'),
    'departments' => get_string('departments', 'local_ulms_academics'),
    'programmes' => get_string('programmes', 'local_ulms_academics'),
];
if (in_array('courses', $allowedentities, true)) {
    $entityoptions['courses'] = \local_ulms_dashboard\local\service\dashboard_commons::safe_lang_string(
        'courses',
        'Courses',
        null,
        'local_ulms_academics'
    );
}
echo html_writer::select(
    $entityoptions,
    'entity',
    $entity,
    false,
    ['id' => 'id_entity', 'class' => 'custom-select']
);
echo html_writer::end_div();

echo html_writer::start_div('ulms-form-field');
echo html_writer::label(get_string('csvupload', 'local_ulms_academics'), 'id_csvfile');
echo html_writer::empty_tag('input', [
    'type' => 'file',
    'name' => 'csvfile',
    'id' => 'id_csvfile',
    'accept' => '.csv,text/csv',
    'class' => 'form-control',
]);
echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::div(
    html_writer::empty_tag('input', [
        'type' => 'submit',
        'name' => 'previewimport',
        'value' => get_string('previewcsvimport', 'local_ulms_academics'),
        'class' => 'btn btn-primary',
        'data-loading-text' => get_string('previewcsvimport', 'local_ulms_academics'),
    ]),
    'ulms-form-actions'
);
echo html_writer::end_tag('form');

echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
echo $OUTPUT->footer();
