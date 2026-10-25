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
    'middlename' => optional_param('middlename', '', PARAM_TEXT),
    'lastname' => optional_param('lastname', '', PARAM_TEXT),
    'email' => optional_param('email', '', PARAM_RAW_TRIMMED),
    'username' => optional_param('username', '', PARAM_RAW_TRIMMED),
    'idnumber' => optional_param('idnumber', '', PARAM_RAW_TRIMMED),
    'staffid' => optional_param('staffid', '', PARAM_RAW_TRIMMED),
    'studylevel' => optional_param('studylevel', '', PARAM_ALPHANUMEXT),
    'programmecode' => optional_param('programmecode', '', PARAM_ALPHANUMEXT),
    // Staff academic assignment. A staff account owns an explicit Department (and, through
    // it, a College) instead of a student programme enrolment.
    'departmentid' => optional_param('departmentid', 0, PARAM_INT),
];
// Master study-level vocabulary (local_ulms_levels). Empty when the levels table is
// not present, in which case the field is hidden rather than guessed.
$studyleveloptions = $provisioningservice->get_study_level_options();

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

// Payload for the client-side programme -> Department/College cascade. It is derived from
// the live academic structure above, so no programme relationship is ever hard-coded. The
// server-side relationship validation in user_provisioning_service::validate_row() remains
// authoritative: the JavaScript only reflects the resolved hierarchy in the form.
$programmehierarchyjs = [];
foreach ($programmehierarchymap as $hierarchycode => $hierarchynode) {
    $programmehierarchyjs[(string)$hierarchycode] = [
        'department' => (string)($hierarchynode->department ?? ''),
        'college' => (string)($hierarchynode->college ?? ''),
    ];
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
$inactiveprogrammes = [];
$showfixerrorsheading = false;
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

if ($createsingle) {
    // Fail loudly on a stale or invalid session token. confirm_sesskey()
    // silently returned false here, so a re-submitted or expired form produced
    // no response and no error at all ("Preview upload does nothing").
    require_sesskey();
    try {
        $singleoutcome = $provisioningservice->create_single_user($singlevalues['targetrole'], $singlevalues);
        if ($singleoutcome['success']) {
            if ($singleoutcome['emailsent']) {
                $messages[] = $singleoutcome['message'];
            } else {
                $warnings[] = $singleoutcome['message'];
            }
            $singlevalues['firstname'] = '';
            $singlevalues['middlename'] = '';
            $singlevalues['lastname'] = '';
            $singlevalues['email'] = '';
            $singlevalues['username'] = '';
            $singlevalues['idnumber'] = '';
            $singlevalues['staffid'] = '';
            $singlevalues['studylevel'] = '';
            $singlevalues['programmecode'] = '';
            $singlevalues['departmentid'] = 0;
            $selectedhierarchy = null;
            $recentactivity = $provisioningservice->get_recent_activity(8);
        } else {
            $singleerrors = $singleoutcome['errors'] ?? [];
            if ($singleerrors === [] && !empty($singleoutcome['message'])) {
                $singleerrors[] = $singleoutcome['message'];
            }
            $errors = array_merge($errors, $singleerrors);
            $showfixerrorsheading = $errors !== [];
            if (!empty($singleoutcome['inactiveprogramme']['id'])) {
                $inactiveprogrammes[(int)$singleoutcome['inactiveprogramme']['id']] =
                    $singleoutcome['inactiveprogramme'];
            }
        }
    } catch (\Throwable $exception) {
        if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'user_provisioning::create_single', []); }
        $errors[] = get_string('provisioningcreateunexpectederror', 'local_ulms_dashboard');
    }
}

if ($previewimport) {
    // See $createsingle above: a stale token must surface as an error instead
    // of a silent no-op.
    require_sesskey();
    try {
        if (empty($_FILES['csvfile']['tmp_name']) || !is_uploaded_file($_FILES['csvfile']['tmp_name'])) {
            $errors[] = get_string('provisioninguploadrequired', 'local_ulms_dashboard');
        } else {
            // Upload policy (size ceiling, CSV extension, binary sniff) is decided in
            // one testable place before any parsing happens.
            $uploaderror = $provisioningservice->validate_csv_upload($_FILES['csvfile']);
            if ($uploaderror !== '') {
                $errors[] = $uploaderror;
            } else {
                $rows = $provisioningservice->parse_csv_upload($_FILES['csvfile']['tmp_name'], $errors);
                if (empty($errors) && empty($rows)) {
                    $errors[] = get_string('provisioningcsvemptyfile', 'local_ulms_dashboard');
                }

                if (empty($errors)) {
                    $previewdata = $provisioningservice->preview_bulk_import($bulktargetrole, $rows);
                    $inactiveprogrammes = $previewdata['inactiveprogrammes'] ?? [];
                    $importtoken = $provisioningservice->store_pending_import($bulktargetrole, $rows);
                    $previewreporttoken = $provisioningservice->store_report(
                        get_string('provisioningcsverrorreportfile', 'local_ulms_dashboard'),
                        $previewdata['reportrows']
                    );
                }
            }
        }
    } catch (\Throwable $exception) {
        if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'user_provisioning::preview_bulk', []); }
        $errors[] = get_string('provisioningcreateunexpectederror', 'local_ulms_dashboard');
    }
}

if ($confirmimport) {
    // See $createsingle above: a stale token must surface as an error instead
    // of a silent no-op. Account creation must never be silently skipped.
    require_sesskey();
    $pendingimport = $provisioningservice->get_pending_import($importtoken);
    if ($pendingimport === null) {
        $errors[] = get_string('provisioningcsvstructureerror', 'local_ulms_dashboard');
    } else {
        try {
            $previewdata = $provisioningservice->preview_bulk_import($pendingimport['targetrole'], $pendingimport['rows']);
            $inactiveprogrammes = $previewdata['inactiveprogrammes'] ?? [];
            if (($previewdata['invalid'] ?? 0) > 0) {
                // The page shows the "fix the invalid rows" heading for this case, so
                // the string is not repeated here.
                $showfixerrorsheading = true;
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

if ($showfixerrorsheading || !empty($errors)) {
    if ($showfixerrorsheading) {
        // Validation actually ran against rows/fields, so the generic repair
        // heading applies.
        $errormessage = $createsingle
            ? get_string('provisioningmanualfixerrors', 'local_ulms_dashboard')
            : get_string('provisioningpreviewfixerrors', 'local_ulms_dashboard');
    } else {
        // Bulk/CSV errors (no file chosen, empty file, unreadable structure) are
        // already specific and self-describing, so the first one becomes the
        // notification instead of a generic heading that contradicted it.
        $errormessage = (string)array_shift($errors);
    }
    echo $OUTPUT->notification($errormessage, \core\output\notification::NOTIFY_ERROR);
    if (!empty($errors)) {
        echo html_writer::alist(array_values(array_unique($errors)));
    }
}

if (!empty($inactiveprogrammes)) {
    // The programme exists but is inactive. Provisioning stays blocked; the link to
    // activate that one programme is only offered to administrators who could perform
    // the activation anyway (manage.php re-checks the same capability server-side).
    $canactivate = has_capability('local/ulms_academics:manageacademics', $context);
    $inactiveitems = [];
    foreach ($inactiveprogrammes as $inactiveprogramme) {
        // The service already ran format_string() on the programme name, so it is
        // safe to echo as-is; the code is a raw database value and must be escaped.
        $inactivelabel = (string)($inactiveprogramme['name'] ?? '');
        $inactivecode = (string)($inactiveprogramme['code'] ?? '');
        if ($inactivelabel === '') {
            $inactivelabel = s($inactivecode);
        }
        $item = $inactivelabel;
        if ($inactivecode !== '') {
            $item .= ' (' . s($inactivecode) . ')';
        }
        if ($canactivate) {
            $activateurl = $routingservice->get_url_for_route('management.academicsmanage', [
                'entity' => 'programmes',
                'id' => (int)$inactiveprogramme['id'],
                'action' => 'activate',
                'sesskey' => sesskey(),
                'returnurl' => $url->out(false),
            ]);
            $item .= ' — ' . html_writer::link(
                $activateurl,
                get_string('provisioningprogrammeactivate', 'local_ulms_dashboard', $inactivelabel)
            );
        }
        $inactiveitems[] = $item;
    }

    $inactivenotice = html_writer::tag(
        'p',
        get_string('provisioningprogrammeinactiveheading', 'local_ulms_dashboard')
    );
    $inactivenotice .= html_writer::alist($inactiveitems);
    $inactivenotice .= html_writer::tag(
        'p',
        get_string('provisioningprogrammeinactivehelp', 'local_ulms_dashboard')
    );
    echo $OUTPUT->notification($inactivenotice, \core\output\notification::NOTIFY_WARNING);
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

    echo html_writer::tag('p', get_string('provisioningrequiredfieldsnote', 'local_ulms_dashboard'), [
        'class' => 'form-text text-muted ml-3 mb-1',
    ]);
    echo html_writer::tag('p', get_string('provisioningonboardinghelp', 'local_ulms_dashboard'), [
        'class' => 'form-text text-muted ml-3 mb-3',
    ]);

    echo html_writer::start_div('col-md-6 mb-3');
    echo html_writer::label(get_string('provisioningfieldtargetrole', 'local_ulms_dashboard'), 'id_singletargetrole');
    echo html_writer::select($roleoptions, 'singletargetrole', $singlevalues['targetrole'], false, [
        'id' => 'id_singletargetrole',
        'class' => 'custom-select',
        'data-ulms-provisioning-role' => 'true',
    ]);
    echo html_writer::end_div();

    // The field specification below is produced by the same service method that
    // user_provisioning_service::validate_row() uses on the server. One matrix therefore
    // decides what the form shows AND what the service accepts, so the two can never drift.
    $accountspec = $provisioningservice->get_account_field_spec($singlevalues['targetrole']);
    $staffassignment = $provisioningservice->get_staff_assignment_options();
    $staffdepartmentoptions = ['' => get_string('usermanagementnotset', 'local_ulms_dashboard')];
    foreach ($staffassignment['departments'] as $staffdepartmentid => $staffdepartmentname) {
        $staffdepartmentoptions[(int)$staffdepartmentid] = (string)$staffdepartmentname;
    }
    $selectedstaffdepartment = max(0, (int)$singlevalues['departmentid']);
    $selectedstaffcollege = $selectedstaffdepartment > 0
        ? max(0, (int)($staffassignment['departmentcollege'][$selectedstaffdepartment] ?? 0))
        : 0;
    $selectedstaffcollegename = $selectedstaffcollege > 0
        ? (string)($staffassignment['colleges'][$selectedstaffcollege] ?? '')
        : '';

    // Shared personal information. These fields belong to every account type, so they are
    // never hidden, disabled or cleared when the account type changes.
    foreach ([
        ['name' => 'firstname', 'label' => get_string('provisioningfieldfirstname', 'local_ulms_dashboard'), 'value' => $singlevalues['firstname'], 'required' => true],
        ['name' => 'middlename', 'label' => get_string('provisioningfieldmiddlename', 'local_ulms_dashboard'), 'value' => $singlevalues['middlename'], 'required' => false],
        ['name' => 'lastname', 'label' => get_string('provisioningfieldlastname', 'local_ulms_dashboard'), 'value' => $singlevalues['lastname'], 'required' => true],
        ['name' => 'email', 'label' => get_string('provisioningfieldemail', 'local_ulms_dashboard'), 'value' => $singlevalues['email'], 'required' => true],
        ['name' => 'username', 'label' => get_string('provisioningfieldusername', 'local_ulms_dashboard'), 'value' => $singlevalues['username'], 'required' => false],
    ] as $field) {
        echo html_writer::start_div('col-md-6 mb-3');
        echo html_writer::label(
            $field['label'] . ($field['required'] ? ' *' : ''),
            'id_' . $field['name']
        );
        echo html_writer::empty_tag('input', [
            'type' => $field['name'] === 'email' ? 'email' : 'text',
            'name' => $field['name'],
            'id' => 'id_' . $field['name'],
            'value' => $field['value'],
            'class' => 'form-control',
            'required' => $field['required'] ? 'required' : null,
            'aria-required' => $field['required'] ? 'true' : 'false',
        ]);
        if ($field['name'] === 'username') {
            echo html_writer::tag('small', get_string('provisioningusernamehelp', 'local_ulms_dashboard'), [
                'class' => 'form-text text-muted',
            ]);
        }
        echo html_writer::end_div();
    }

    // Contextual guidance for the selected account type. The account-type script below keeps
    // this in step with the selector and announces the change through aria-live.
    $rolehints = [];
    foreach (array_keys($roleoptions) as $hintrole) {
        $rolehints[$hintrole] = get_string('provisioningrolecondhint' . $hintrole, 'local_ulms_dashboard');
    }
    echo html_writer::tag('p', $rolehints[$singlevalues['targetrole']] ?? '', [
        'id' => 'id_role_cond_hint',
        'class' => 'form-text text-muted ml-3 mb-3',
        'role' => 'status',
        'aria-live' => 'polite',
    ]);

    // -------------------------------------------------------------------------------------
    // Learner academic fields (student accounts only).
    //
    // A learner account is enrolled on a Programme, from which Department and College are
    // derived. There is no Staff ID here: a Staff ID belongs to a staff account and is
    // rejected by the service if it is posted with a student account type.
    // -------------------------------------------------------------------------------------
    $studentactive = (bool)$accountspec['programme']['visible'];
    $studentblockattributes = ['data-ulms-role-block' => 'student'];
    if (!$studentactive) {
        $studentblockattributes['hidden'] = 'hidden';
        $studentblockattributes['aria-hidden'] = 'true';
        $studentblockattributes['style'] = 'display:none;';
    }
    $studentinputattributes = $studentactive ? [] : ['disabled' => 'disabled'];

    echo html_writer::start_div('col-12', $studentblockattributes);
    echo html_writer::start_div('row');

    echo html_writer::start_div('col-md-6 mb-3');
    echo html_writer::label(get_string('provisioningfieldmatriculation', 'local_ulms_dashboard'), 'id_idnumber');
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => 'idnumber',
        'id' => 'id_idnumber',
        'value' => $singlevalues['idnumber'],
        'class' => 'form-control',
        'aria-required' => 'false',
    ] + $studentinputattributes);
    echo html_writer::end_div();

    echo html_writer::start_div('col-md-6 mb-3');
    echo html_writer::label(
        get_string('provisioningfieldprogrammecode', 'local_ulms_dashboard')
            . ' <span class="ulms-required-mark" aria-hidden="true">*</span>',
        'id_programmecode'
    );
    echo html_writer::select($programmeoptions, 'programmecode', $singlevalues['programmecode'], false, [
        'id' => 'id_programmecode',
        'class' => 'custom-select',
        'data-ulms-programme-select' => 'true',
        'data-ulms-field-required' => 'true',
        'required' => 'required',
        'aria-required' => 'true',
    ] + $studentinputattributes);
    echo html_writer::tag('small', get_string('provisioningprogrammestudenthint', 'local_ulms_dashboard'), [
        'class' => 'form-text text-muted',
    ]);
    echo html_writer::tag(
        'div',
        s(get_string('provisioningprogrammehierarchyincomplete', 'local_ulms_dashboard')),
        [
            'id' => 'id_programme_hierarchy_notice',
            'class' => 'alert alert-warning mt-2 d-none',
            'role' => 'status',
            'aria-live' => 'polite',
            'hidden' => 'hidden',
        ]
    );
    echo html_writer::end_div();

    // Department and College are read-only projections of the selected Programme. They carry
    // no name attribute because the server derives them from the programme relationship, so
    // they can never be submitted as an independent academic assignment.
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
        'data-ulms-programme-department' => 'true',
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
        'data-ulms-programme-college' => 'true',
    ]);
    echo html_writer::end_div();

    if ($studyleveloptions !== []) {
        // Master study-level vocabulary. Left empty by default: the service never
        // invents an academic assignment for an account that was not given one. This control
        // exists only in the learner block, so a staff account can never be given a level.
        echo html_writer::start_div('col-md-6 mb-3');
        echo html_writer::label(get_string('provisioningfieldstudylevel', 'local_ulms_dashboard'), 'id_studylevel');
        echo html_writer::select(
            ['' => get_string('usermanagementnotset', 'local_ulms_dashboard')] + $studyleveloptions,
            'studylevel',
            $singlevalues['studylevel'],
            false,
            ['id' => 'id_studylevel', 'class' => 'custom-select'] + $studentinputattributes
        );
        echo html_writer::end_div();
    }

    echo html_writer::end_div();
    echo html_writer::end_div();

    // -------------------------------------------------------------------------------------
    // Staff academic fields (lecturer and administrator accounts).
    //
    // A staff account is identified by a Staff ID and attached to a Department, which owns
    // the College. It is not a student enrolment, so no Programme and no study level are
    // offered. The college is derived from the department here and re-derived on the server,
    // so the two can never disagree.
    // -------------------------------------------------------------------------------------
    $staffactive = (bool)$accountspec['staffid']['visible'];
    $staffblockattributes = ['data-ulms-role-block' => 'lecturer admin'];
    if (!$staffactive) {
        $staffblockattributes['hidden'] = 'hidden';
        $staffblockattributes['aria-hidden'] = 'true';
        $staffblockattributes['style'] = 'display:none;';
    }
    $staffinputattributes = $staffactive ? [] : ['disabled' => 'disabled'];

    echo html_writer::start_div('col-12', $staffblockattributes);
    echo html_writer::start_div('row');

    echo html_writer::start_div('col-md-6 mb-3');
    echo html_writer::label(
        get_string('provisioningfieldstaffid', 'local_ulms_dashboard')
            . ' <span class="ulms-required-mark" aria-hidden="true">*</span>',
        'id_staffid'
    );
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => 'staffid',
        'id' => 'id_staffid',
        'value' => $singlevalues['staffid'],
        'class' => 'form-control',
        'data-ulms-field-required' => 'true',
        'required' => 'required',
        'aria-required' => 'true',
    ] + $staffinputattributes);
    echo html_writer::end_div();

    echo html_writer::start_div('col-md-6 mb-3');
    echo html_writer::label(get_string('provisioningfieldstaffdepartment', 'local_ulms_dashboard'), 'id_departmentid');
    echo html_writer::select(
        $staffdepartmentoptions,
        'departmentid',
        $selectedstaffdepartment > 0 ? (string)$selectedstaffdepartment : '',
        false,
        ['id' => 'id_departmentid', 'class' => 'custom-select', 'data-ulms-staff-department' => 'true'] + $staffinputattributes
    );
    echo html_writer::end_div();

    echo html_writer::start_div('col-md-6 mb-3');
    echo html_writer::label(get_string('provisioningfieldstaffcollege', 'local_ulms_dashboard'), 'id_staff_college');
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'id' => 'id_staff_college',
        'value' => s($selectedstaffcollegename),
        'class' => 'form-control',
        'readonly' => 'readonly',
        'tabindex' => '-1',
        'aria-disabled' => 'true',
        'data-ulms-staff-college' => 'true',
    ]);
    echo html_writer::end_div();

    echo html_writer::start_div('col-12 mb-3');
    echo html_writer::tag('small', get_string('provisioningstaffassignmenthint', 'local_ulms_dashboard'), [
        'class' => 'form-text text-muted',
    ]);
    echo html_writer::end_div();

    echo html_writer::end_div();
    echo html_writer::end_div();

    // Client-side cascade: reflect the resolved Department/College for the selected
    // programme immediately, without a round trip. The values come from the live academic
    // structure and are re-validated on the server when the form is submitted.
    $PAGE->requires->js_init_code(
        '(function() {
            var hierarchy = ' . json_encode(
            $programmehierarchyjs,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) . ';
            var select = document.querySelector("[data-ulms-programme-select=\"true\"]");
            var departmentField = document.querySelector("[data-ulms-programme-department=\"true\"]");
            var collegeField = document.querySelector("[data-ulms-programme-college=\"true\"]");
            var notice = document.getElementById("id_programme_hierarchy_notice");

            if (!select || !departmentField || !collegeField) {
                return;
            }

            var syncHierarchy = function() {
                var code = select.value || "";
                var entry = Object.prototype.hasOwnProperty.call(hierarchy, code) ? hierarchy[code] : null;
                var department = entry && entry.department ? entry.department : "";
                var college = entry && entry.college ? entry.college : "";

                departmentField.value = department;
                collegeField.value = college;

                var incomplete = code !== "" && (department === "" || college === "");
                departmentField.setAttribute("aria-invalid", incomplete ? "true" : "false");
                collegeField.setAttribute("aria-invalid", incomplete ? "true" : "false");
                select.setAttribute("aria-invalid", incomplete ? "true" : "false");

                if (notice) {
                    if (incomplete) {
                        notice.classList.remove("d-none");
                        notice.removeAttribute("hidden");
                    } else {
                        notice.classList.add("d-none");
                        notice.setAttribute("hidden", "hidden");
                    }
                }
            };

            select.addEventListener("change", syncHierarchy);
            syncHierarchy();
        })();',
        true
    );

    // Account-type switching. Fields are shown or hidden by account type and the controls
    // inside a hidden block are disabled, so a value entered for one account type can never
    // be submitted with another. Shared personal information is never touched. The same
    // account-type rules are enforced again on the server in
    // user_provisioning_service::validate_row(), so this script is presentational only.
    $PAGE->requires->js_init_code(
        '(function() {
            var roleSelect = document.querySelector("[data-ulms-provisioning-role=\"true\"]");
            if (!roleSelect) {
                return;
            }

            var blocks = Array.prototype.slice.call(document.querySelectorAll("[data-ulms-role-block]"));
            var hint = document.getElementById("id_role_cond_hint");
            var hints = ' . json_encode(
            $rolehints,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) . ';
            var departmentCollege = ' . json_encode(
            $staffassignment['departmentcollege'],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) . ';
            var collegeNames = ' . json_encode(
            $staffassignment['colleges'],
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) . ';
            var staffDepartmentField = document.querySelector("[data-ulms-staff-department=\"true\"]");
            var staffCollegeField = document.querySelector("[data-ulms-staff-college=\"true\"]");

            // The College of a staff account is the college that owns the chosen Department,
            // mirroring the derivation the service performs when the form is submitted.
            var syncStaffCollege = function() {
                if (!staffDepartmentField || !staffCollegeField) {
                    return;
                }
                var departmentId = String(staffDepartmentField.value || "");
                var collegeId = Object.prototype.hasOwnProperty.call(departmentCollege, departmentId)
                    ? String(departmentCollege[departmentId])
                    : "";
                staffCollegeField.value = collegeId !== "" && Object.prototype.hasOwnProperty.call(collegeNames, collegeId)
                    ? collegeNames[collegeId]
                    : "";
            };

            var applyRole = function() {
                var role = String(roleSelect.value || "");

                blocks.forEach(function(block) {
                    var roles = String(block.getAttribute("data-ulms-role-block") || "").split(/\s+/).filter(Boolean);
                    var applicable = roles.indexOf(role) !== -1;

                    block.hidden = !applicable;
                    block.style.display = applicable ? "" : "none";
                    block.setAttribute("aria-hidden", applicable ? "false" : "true");

                    Array.prototype.slice.call(block.querySelectorAll("input, select, textarea")).forEach(function(control) {
                        if (applicable) {
                            control.removeAttribute("disabled");
                            if (control.getAttribute("data-ulms-field-required") === "true") {
                                control.setAttribute("required", "required");
                                control.setAttribute("aria-required", "true");
                            }
                        } else {
                            control.setAttribute("disabled", "disabled");
                            control.removeAttribute("required");
                            if (control.hasAttribute("aria-required")) {
                                control.setAttribute("aria-required", "false");
                            }
                        }
                    });
                });

                if (hint && Object.prototype.hasOwnProperty.call(hints, role)) {
                    hint.textContent = hints[role];
                }

                syncStaffCollege();
            };

            roleSelect.addEventListener("change", applyRole);
            if (staffDepartmentField) {
                staffDepartmentField.addEventListener("change", syncStaffCollege);
            }
            applyRole();
        })();',
        true
    );

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
    // Advisory client-side ceiling; the server enforces the same limit in
    // user_provisioning_service::validate_csv_upload().
    echo html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => 'MAX_FILE_SIZE',
        'value' => \local_ulms_dashboard\local\service\user_provisioning_service::CSV_UPLOAD_MAX_BYTES,
    ]);
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
    echo html_writer::tag('small', get_string('provisioninguploadformathelp', 'local_ulms_dashboard'), [
        'class' => 'form-text text-muted',
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
