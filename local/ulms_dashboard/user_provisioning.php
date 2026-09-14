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
require_once($CFG->dirroot . '/local/ulms_dashboard/lib.php');

/** @var moodle_page $PAGE */
/** @var core_renderer $OUTPUT */
/** @var stdClass $USER */

global $PAGE, $OUTPUT;

$routingservice = new \local_ulms_auth\local\service\landing_page_service();
$section = optional_param('section', '', PARAM_ALPHA);
$section = in_array($section, ['bulk', 'manual'], true) ? $section : '';
$downloadtemplate = optional_param('downloadtemplate', 0, PARAM_BOOL);
$previewimport = optional_param('previewimport', '', PARAM_RAW_TRIMMED) !== '';
$confirmimport = optional_param('confirmimport', '', PARAM_RAW_TRIMMED) !== '';
$section = $section !== ''
    ? $section
    : (($previewimport || $confirmimport || $downloadtemplate) ? 'bulk' : 'manual');
$routekey = $section === 'bulk' ? 'management.bulkupload' : 'management.provisioning';
$routingservice->maybe_redirect_legacy_request($routekey);

require_login();

$dashboardservice = new \local_ulms_dashboard\local\service\dashboard_service();
$dashboardservice->enforce_admin_feature_access();
$dashboardservice->require_admin_permissions();

$context = \context::instance_by_id(\context_system::instance()->id);
$PAGE->set_context($context);
$PAGE->set_title(get_string('adminuserprovisioning', 'local_ulms_dashboard'));
$PAGE->set_heading(get_string('adminuserprovisioning', 'local_ulms_dashboard'));
$PAGE->set_pagelayout('ulmsdashboard');

$provisioningservice = new \local_ulms_dashboard\local\service\user_provisioning_service();
$roleoptions = $provisioningservice->get_available_target_roles();
$defaultrole = array_key_first($roleoptions) ?: 'student';
$singlevalues = [
    'targetrole' => optional_param('singletargetrole', $defaultrole, PARAM_ALPHA),
    'firstname' => optional_param('firstname', '', PARAM_TEXT),
    'lastname' => optional_param('lastname', '', PARAM_TEXT),
    'email' => optional_param('email', '', PARAM_RAW_TRIMMED),
    'username' => optional_param('username', '', PARAM_RAW_TRIMMED),
    'idnumber' => optional_param('idnumber', '', PARAM_RAW_TRIMMED),
    'programmecode' => optional_param('programmecode', '', PARAM_ALPHANUMEXT),
];

$programmeoptions = ['' => get_string('provisioningprogrammeselectplaceholder', 'local_ulms_dashboard')];
$programmehierarchymap = [];
try {
    $acadrepo = new \local_ulms_academics\local\repository\academic_repository();
    $allprogrammes = $acadrepo->get_filtered_records('programmes', '', 'active', 'name', 'ASC', 0, 500);
    foreach ($allprogrammes as $prec) {
        $code = trim((string)($prec->code ?? ''));
        if ($code === '') {
            continue;
        }
        $label = format_string($prec->name ?? $code);
        if ($label !== $code) {
            $label .= ' (' . $code . ')';
        }
        $programmeoptions[$code] = $label;
        $deptid = (int)($prec->departmentid ?? 0);
        $deptname = '';
        $facultyname = '';
        if ($deptid > 0) {
            $deptrec = $acadrepo->get_record('departments', $deptid);
            if ($deptrec) {
                $deptname = format_string($deptrec->name ?? '');
                $facid = (int)($deptrec->facultyid ?? 0);
                if ($facid > 0) {
                    $facrec = $acadrepo->get_record('faculties', $facid);
                    if ($facrec) {
                        $facultyname = format_string($facrec->name ?? '');
                    }
                }
            }
        }
        $programmehierarchymap[$code] = (object)['department' => $deptname, 'college' => $facultyname];
    }
} catch (\Throwable $e) {
    if (function_exists('local_ulms_dashboard_log_operational_error')) {
        local_ulms_dashboard_log_operational_error($e, 'user_provisioning::programme_options', []);
    }
}
$selectedhierarchy = null;
if (!empty($singlevalues['programmecode']) && isset($programmehierarchymap[$singlevalues['programmecode']])) {
    $selectedhierarchy = $programmehierarchymap[$singlevalues['programmecode']];
}
$bulktargetrole = optional_param('bulktargetrole', $defaultrole, PARAM_ALPHA);
$createsingle = optional_param('createsingle', '', PARAM_RAW_TRIMMED) !== '';
$url = $routingservice->get_url_for_route($routekey);
$PAGE->set_url($url);
$importtoken = optional_param('importtoken', '', PARAM_ALPHANUMEXT);
$messages = [];
$warnings = [];
$errors = [];
$previewdata = null;
$previewreporttoken = null;
$resultreporttoken = null;
$recentactivity = $provisioningservice->get_recent_activity(8);

if ($downloadtemplate) {
    local_ulms_dashboard_emit_security_headers();
    $targetrole = in_array($bulktargetrole, array_keys($roleoptions), true) ? $bulktargetrole : $defaultrole;
    $filename = 'ulms-' . $targetrole . '-users-template.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    foreach ($provisioningservice->get_csv_template_rows($targetrole) as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

if ($createsingle && confirm_sesskey()) {
    try {
        $singleoutcome = $provisioningservice->create_single_user($singlevalues['targetrole'], $singlevalues);
        if ($singleoutcome['success']) {
            if ($singleoutcome['emailsent']) {
                $messages[] = $singleoutcome['message'];
            } else {
                $warnings[] = $singleoutcome['message'];
            }
            $singlevalues['firstname'] = '';
            $singlevalues['lastname'] = '';
            $singlevalues['email'] = '';
            $singlevalues['username'] = '';
            $singlevalues['idnumber'] = '';
            $singlevalues['programmecode'] = '';
            $selectedhierarchy = null;
            $recentactivity = $provisioningservice->get_recent_activity(8);
        } else {
            $singleerrors = $singleoutcome['errors'] ?? [];
            if ($singleerrors === [] && !empty($singleoutcome['message'])) {
                $singleerrors[] = $singleoutcome['message'];
            }
            $errors = array_merge($errors, $singleerrors);
        }
    } catch (\Throwable $exception) {
        if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'user_provisioning::create_single', []); }
        $errors[] = get_string('provisioningcreateunexpectederror', 'local_ulms_dashboard');
    }
}

if ($previewimport && confirm_sesskey()) {
    try {
        if (empty($_FILES['csvfile']['tmp_name']) || !is_uploaded_file($_FILES['csvfile']['tmp_name'])) {
            $errors[] = get_string('provisioninguploadrequired', 'local_ulms_dashboard');
        } else {
            $rows = $provisioningservice->parse_csv_upload($_FILES['csvfile']['tmp_name'], $errors);
            if (empty($errors) && empty($rows)) {
                $errors[] = get_string('provisioningcsvemptyfile', 'local_ulms_dashboard');
            }

            if (empty($errors)) {
                $previewdata = $provisioningservice->preview_bulk_import($bulktargetrole, $rows);
                $importtoken = $provisioningservice->store_pending_import($bulktargetrole, $rows);
                $previewreporttoken = $provisioningservice->store_report(
                    get_string('provisioningcsverrorreportfile', 'local_ulms_dashboard'),
                    $previewdata['reportrows']
                );
            }
        }
    } catch (\Throwable $exception) {
        if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'user_provisioning::preview_bulk', []); }
        $errors[] = get_string('provisioningcreateunexpectederror', 'local_ulms_dashboard');
    }
}

if ($confirmimport && confirm_sesskey()) {
    $pendingimport = $provisioningservice->get_pending_import($importtoken);
    if ($pendingimport === null) {
        $errors[] = get_string('provisioningcsvstructureerror', 'local_ulms_dashboard');
    } else {
        try {
            $previewdata = $provisioningservice->preview_bulk_import($pendingimport['targetrole'], $pendingimport['rows']);
            if (($previewdata['invalid'] ?? 0) > 0) {
                $errors[] = get_string('provisioningpreviewfixerrors', 'local_ulms_dashboard');
                $previewreporttoken = $provisioningservice->store_report(
                    get_string('provisioningcsverrorreportfile', 'local_ulms_dashboard'),
                    $previewdata['reportrows']
                );
            } else {
                $result = $provisioningservice->import_bulk_rows($pendingimport['targetrole'], $previewdata['validrows']);
                $messages[] = get_string('provisioningcsvimportsummary', 'local_ulms_dashboard', (object)$result);
                if (!empty($result['errors'])) {
                    $warnings = array_merge($warnings, $result['errors']);
                }
                $resultreporttoken = $provisioningservice->store_report(
                    get_string('provisioningresultreportfile', 'local_ulms_dashboard'),
                    $result['reportrows']
                );
                $provisioningservice->clear_pending_import($importtoken);
                $previewdata = null;
                $importtoken = '';
                $recentactivity = $provisioningservice->get_recent_activity(8);
            }
        } catch (\Throwable $exception) {
            if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'user_provisioning::confirm_bulk', []); }
            $errors[] = get_string('provisioningcreateunexpectederror', 'local_ulms_dashboard');
        }
    }
}

$kpijson = [
    [
        'label' => get_string('provisioningaccounttypessummary', 'local_ulms_dashboard'),
        'value' => (string)count($roleoptions),
        'description' => get_string('provisioningintro', 'local_ulms_dashboard'),
    ],
    [
        'label' => get_string('provisioningactivityheading', 'local_ulms_dashboard'),
        'value' => (string)count($recentactivity),
        'description' => get_string('provisioningactivityintro', 'local_ulms_dashboard'),
    ],
];

echo $OUTPUT->header();
$headersection = $section === 'bulk' ? 'bulkupload' : 'provisioning';
$adminservice = new \local_ulms_dashboard\local\service\admin_portal_service();
$headercontext = $adminservice->get_header_context_for_section($headersection);
$navitems = [
    [
        'label' => get_string('adminuserprovisioning', 'local_ulms_dashboard'),
        'url' => $routingservice->get_url_for_route('management.provisioning'),
        'active' => $section === 'manual',
    ],
    [
        'label' => get_string('adminnavbulkupload', 'local_ulms_dashboard'),
        'url' => $routingservice->get_url_for_route('management.bulkupload'),
        'active' => $section === 'bulk',
    ],
];

echo local_ulms_dashboard_render_page_header([
    'eyebrow' => $headercontext['eyebrow'] ?? get_string('adminportaleyebrow', 'local_ulms_dashboard'),
    'title' => $headercontext['title'] ?? get_string('adminuserprovisioning', 'local_ulms_dashboard'),
    'meta' => $headercontext['meta'] ?? '',
    'actions' => $headercontext['actions'] ?? [],
    'navitems' => $navitems,
]);
local_ulms_dashboard_start_shell_wrap();

foreach ($messages as $message) {
    echo $OUTPUT->notification($message, \core\output\notification::NOTIFY_SUCCESS);
}

foreach ($warnings as $warning) {
    echo $OUTPUT->notification($warning, \core\output\notification::NOTIFY_WARNING);
}

if (!empty($errors)) {
    $errormessage = $createsingle
        ? get_string('provisioningmanualfixerrors', 'local_ulms_dashboard')
        : get_string('provisioningpreviewfixerrors', 'local_ulms_dashboard');
    echo $OUTPUT->notification($errormessage, \core\output\notification::NOTIFY_ERROR);
    echo html_writer::alist(array_values(array_unique($errors)));
}

echo local_ulms_dashboard_render_summary_cards($kpijson);

echo html_writer::start_div('ulms-layout-grid');

if ($section === 'manual') {
    echo html_writer::start_div('ulms-panel');
    echo html_writer::start_div('ulms-panel__header');
    echo html_writer::start_div();
    echo html_writer::tag('h2', get_string('provisioningmanualheading', 'local_ulms_dashboard'), ['class' => 'ulms-panel__title']);
    echo html_writer::tag('p', get_string('provisioningmanualintro', 'local_ulms_dashboard'), ['class' => 'ulms-panel__subtitle']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::start_div('ulms-panel__body');

    if (!$provisioningservice->can_create_admin_accounts()) {
        echo $OUTPUT->notification(get_string('provisioninghelpadmincreation', 'local_ulms_dashboard'), \core\output\notification::NOTIFY_INFO);
    }

    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $url,
        'class' => 'row',
        'data-ulms-loading-form' => 'true',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'section', 'value' => 'manual']);

    foreach ([
        ['name' => 'singletargetrole', 'label' => get_string('provisioningfieldtargetrole', 'local_ulms_dashboard'), 'type' => 'select', 'value' => $singlevalues['targetrole']],
        ['name' => 'firstname', 'label' => get_string('provisioningfieldfirstname', 'local_ulms_dashboard'), 'type' => 'text', 'value' => $singlevalues['firstname']],
        ['name' => 'lastname', 'label' => get_string('provisioningfieldlastname', 'local_ulms_dashboard'), 'type' => 'text', 'value' => $singlevalues['lastname']],
        ['name' => 'email', 'label' => get_string('provisioningfieldemail', 'local_ulms_dashboard'), 'type' => 'email', 'value' => $singlevalues['email']],
        ['name' => 'username', 'label' => get_string('provisioningfieldusername', 'local_ulms_dashboard'), 'type' => 'text', 'value' => $singlevalues['username']],
        ['name' => 'idnumber', 'label' => get_string('provisioningfieldidnumber', 'local_ulms_dashboard'), 'type' => 'text', 'value' => $singlevalues['idnumber']],
    ] as $field) {
        echo html_writer::start_div('col-md-6 mb-3');
        echo html_writer::label($field['label'], 'id_' . $field['name']);
        if ($field['type'] === 'select') {
            echo html_writer::select($roleoptions, $field['name'], $field['value'], false, [
                'id' => 'id_' . $field['name'],
                'class' => 'custom-select',
            ]);
        } else {
            echo html_writer::empty_tag('input', [
                'type' => $field['type'],
                'name' => $field['name'],
                'id' => 'id_' . $field['name'],
                'value' => $field['value'],
                'class' => 'form-control',
            ]);
        }
        echo html_writer::end_div();
    }

    echo html_writer::start_div('col-12 mb-3');
    echo html_writer::label(get_string('provisioningfieldprogrammecode', 'local_ulms_dashboard'), 'id_programmecode');
    echo html_writer::select($programmeoptions, 'programmecode', $singlevalues['programmecode'], false, [
        'id' => 'id_programmecode',
        'class' => 'custom-select',
    ]);
    echo html_writer::tag('small', get_string('provisioningprogrammestudenthint', 'local_ulms_dashboard'), [
        'class' => 'form-text text-muted',
    ]);
    echo html_writer::end_div();

    echo html_writer::start_div('col-md-6 mb-3');
    echo html_writer::label(get_string('provisioningfieldprogrammedepartment', 'local_ulms_dashboard'), 'id_programme_dept');
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'id' => 'id_programme_dept',
        'value' => $selectedhierarchy ? s($selectedhierarchy->department ?? '') : '',
        'class' => 'form-control',
        'readonly' => 'readonly',
        'tabindex' => '-1',
        'aria-disabled' => 'true',
    ]);
    echo html_writer::end_div();

    echo html_writer::start_div('col-md-6 mb-3');
    echo html_writer::label(get_string('provisioningfieldprogrammecollege', 'local_ulms_dashboard'), 'id_programme_college');
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'id' => 'id_programme_college',
        'value' => $selectedhierarchy ? s($selectedhierarchy->college ?? '') : '',
        'class' => 'form-control',
        'readonly' => 'readonly',
        'tabindex' => '-1',
        'aria-disabled' => 'true',
    ]);
    echo html_writer::end_div();

    echo html_writer::start_div('col-12');
    echo html_writer::start_div('d-flex ulms-d-flex-gap-2 flex-wrap justify-content-between align-items-center');
    echo html_writer::link(
        $routingservice->get_url_for_route('management.users'),
        get_string('cancel'),
        ['class' => 'btn btn-outline-secondary']
    );
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'name' => 'createsingle',
        'value' => get_string('provisioningcreatesingle', 'local_ulms_dashboard'),
        'class' => 'btn btn-primary',
        'data-loading-text' => get_string('provisioningcreatesingleloading', 'local_ulms_dashboard'),
    ]);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::end_div();
} else {
    echo html_writer::start_div('ulms-panel ulms-panel--soft');
    echo html_writer::start_div('ulms-panel__header');
    echo html_writer::start_div();
    echo html_writer::tag('h2', get_string('provisioningbulkheading', 'local_ulms_dashboard'), ['class' => 'ulms-panel__title']);
    echo html_writer::tag('p', get_string('provisioningbulkintro', 'local_ulms_dashboard'), ['class' => 'ulms-panel__subtitle']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::start_div('ulms-panel__body');

    echo html_writer::div(
        html_writer::link(
            $routingservice->get_url_for_route('management.bulkupload', [
                'section' => 'bulk',
                'bulktargetrole' => $bulktargetrole,
                'downloadtemplate' => 1,
            ]),
            get_string('provisioningcsvtemplate', 'local_ulms_dashboard'),
            ['class' => 'btn btn-outline-secondary']
        ),
        'mb-3'
    );

    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $url,
        'enctype' => 'multipart/form-data',
        'data-ulms-loading-form' => 'true',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'section', 'value' => 'bulk']);
    echo html_writer::start_div('row');
    echo html_writer::start_div('col-md-6 mb-3');
    echo html_writer::label(get_string('provisioningfieldtargetrole', 'local_ulms_dashboard'), 'id_bulktargetrole');
    echo html_writer::select($roleoptions, 'bulktargetrole', $bulktargetrole, false, [
        'id' => 'id_bulktargetrole',
        'class' => 'custom-select',
    ]);
    echo html_writer::end_div();
    echo html_writer::start_div('col-md-6 mb-3');
    echo html_writer::label(get_string('provisioninguploadfile', 'local_ulms_dashboard'), 'id_csvfile');
    echo html_writer::empty_tag('input', [
        'type' => 'file',
        'name' => 'csvfile',
        'id' => 'id_csvfile',
        'accept' => '.csv,text/csv',
        'class' => 'form-control',
    ]);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::start_div('d-flex ulms-d-flex-gap-2 flex-wrap justify-content-between align-items-center mt-3');
    echo html_writer::link(
        $routingservice->get_url_for_route('management.users'),
        get_string('cancel'),
        ['class' => 'btn btn-outline-secondary']
    );
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'name' => 'previewimport',
        'value' => get_string('provisioningcsvpreview', 'local_ulms_dashboard'),
        'class' => 'btn btn-outline-primary',
        'data-loading-text' => get_string('provisioningcsvpreviewloading', 'local_ulms_dashboard'),
    ]);
    echo html_writer::end_div();
    echo html_writer::end_tag('form');

    if ($previewdata !== null) {
    echo html_writer::empty_tag('hr');
    echo $OUTPUT->notification(
        get_string('provisioningcsvpreviewsummary', 'local_ulms_dashboard', (object)[
            'processed' => $previewdata['processed'],
            'valid' => $previewdata['valid'],
            'invalid' => $previewdata['invalid'],
        ]),
        ($previewdata['invalid'] ?? 0) > 0
            ? \core\output\notification::NOTIFY_WARNING
            : \core\output\notification::NOTIFY_INFO
    );

    if ($previewreporttoken !== null) {
        echo html_writer::div(
            html_writer::link(
                $routingservice->get_url_for_route('management.bulkreport', ['token' => $previewreporttoken, 'section' => 'bulk']),
                get_string('provisioningcsvdownloadpreviewreport', 'local_ulms_dashboard'),
                ['class' => 'btn btn-outline-secondary btn-sm']
            ),
            'mb-3'
        );
    }

    $previewtable = new html_table();
    $previewtable->attributes['class'] = 'generaltable';
    $previewtable->head = [
        get_string('provisioningcolline', 'local_ulms_dashboard'),
        get_string('provisioningfieldfirstname', 'local_ulms_dashboard'),
        get_string('provisioningfieldlastname', 'local_ulms_dashboard'),
        get_string('provisioningfieldemail', 'local_ulms_dashboard'),
        get_string('provisioningfieldusername', 'local_ulms_dashboard'),
        get_string('provisioningfieldidnumber', 'local_ulms_dashboard'),
        get_string('provisioningfieldprogrammecode', 'local_ulms_dashboard'),
        get_string('provisioningcolaction', 'local_ulms_dashboard'),
        get_string('provisioningcolvalidation', 'local_ulms_dashboard'),
    ];

    foreach ($previewdata['previewrows'] as $row) {
        $previewtable->data[] = [
            s((string)$row['linenumber']),
            s((string)$row['firstname']),
            s((string)$row['lastname']),
            s((string)$row['email']),
            s((string)$row['username']),
            s((string)$row['idnumber']),
            s((string)($row['programmecode'] ?? '')),
            s((string)$row['action']),
            s((string)$row['message']),
        ];
    }

    echo html_writer::table($previewtable);

    if (($previewdata['invalid'] ?? 0) === 0 && ($previewdata['valid'] ?? 0) > 0 && $importtoken !== '') {
        echo html_writer::start_tag('form', [
            'method' => 'post',
            'action' => $url,
            'data-ulms-loading-form' => 'true',
            'class' => 'mt-3',
        ]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'section', 'value' => 'bulk']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'importtoken', 'value' => $importtoken]);
        echo html_writer::start_div('d-flex ulms-d-flex-gap-2 flex-wrap justify-content-between align-items-center');
        echo html_writer::link(
            $routingservice->get_url_for_route('management.provisioning'),
            get_string('cancel'),
            ['class' => 'btn btn-outline-secondary']
        );
        echo html_writer::empty_tag('input', [
            'type' => 'submit',
            'name' => 'confirmimport',
            'value' => get_string('provisioningbulkconfirm', 'local_ulms_dashboard'),
            'class' => 'btn btn-primary',
            'data-loading-text' => get_string('provisioningbulkconfirmloading', 'local_ulms_dashboard'),
        ]);
        echo html_writer::end_div();
        echo html_writer::end_tag('form');
    }
}

    if ($resultreporttoken !== null) {
    echo html_writer::div(
        html_writer::link(
            $routingservice->get_url_for_route('management.bulkreport', ['token' => $resultreporttoken, 'section' => 'bulk']),
            get_string('provisioningcsvdownloadresultreport', 'local_ulms_dashboard'),
            ['class' => 'btn btn-outline-secondary btn-sm mt-3']
        )
    );
}

    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo html_writer::start_div('ulms-panel');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', get_string('provisioningactivityheading', 'local_ulms_dashboard'), ['class' => 'ulms-panel__title']);
echo html_writer::tag('p', get_string('provisioningactivityintro', 'local_ulms_dashboard'), ['class' => 'ulms-panel__subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');

if (!empty($recentactivity)) {
    $activityitems = [];
    foreach ($recentactivity as $item) {
        $activityitems[] = html_writer::tag(
            'li',
            html_writer::div(format_string($item['label']), 'ulms-list__title') .
            html_writer::div(format_string($item['subtitle']) . ' - ' . s($item['time']), 'ulms-list__meta'),
            ['class' => 'ulms-list__item']
        );
    }
    echo html_writer::tag('ul', implode('', $activityitems), ['class' => 'ulms-list']);
} else {
    echo html_writer::div(
        html_writer::tag('h3', get_string('provisioningactivityheading', 'local_ulms_dashboard'), ['class' => 'ulms-panel__title']) .
        html_writer::tag('p', get_string('provisioningactivityintro', 'local_ulms_dashboard'), ['class' => 'ulms-empty-state__meta']),
        'ulms-empty-state'
    );
}

echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div();
local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
