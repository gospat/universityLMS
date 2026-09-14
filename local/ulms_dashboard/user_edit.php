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
$userid = optional_param('id', 0, PARAM_INT);
$iscreate = $userid <= 0;
$baseparams = $userid > 0 ? ['id' => $userid] : [];
$routekey = $iscreate ? 'management.usercreate' : 'management.useredit';
$routingservice->maybe_redirect_legacy_request($routekey, $baseparams);

require_login();

$dashboardservice = new \local_ulms_dashboard\local\service\dashboard_service();
$dashboardservice->enforce_admin_feature_access();
$dashboardservice->require_admin_permissions();

$service = new \local_ulms_dashboard\local\service\user_management_service();
$service->require_management_access();

$context = \context::instance_by_id(\context_system::instance()->id);
$requiredcapability = $iscreate ? 'moodle/user:create' : 'moodle/user:update';
\require_capability($requiredcapability, $context);
$url = $routingservice->get_url_for_route($routekey, $baseparams);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string($iscreate ? 'usermanagementaddusertitle' : 'usermanagementeditusertitle', 'local_ulms_dashboard'));
$PAGE->set_heading(get_string($iscreate ? 'usermanagementaddusertitle' : 'usermanagementeditusertitle', 'local_ulms_dashboard'));
$PAGE->set_pagelayout('ulmsdashboard');

$user = null;
if (!$iscreate) {
    $user = $service->get_user_details($userid);
}

$values = $service->get_form_defaults($user);
$errors = [];

if (optional_param('cancel', '', PARAM_RAW_TRIMMED) !== '') {
    redirect($userid > 0
        ? $routingservice->get_url_for_route('management.userview', ['id' => $userid])
        : $routingservice->get_url_for_route('management.users'));
}

if (optional_param('saveuser', '', PARAM_RAW_TRIMMED) !== '' && confirm_sesskey()) {
    $values = [
        'firstname' => optional_param('firstname', '', PARAM_TEXT),
        'middlename' => optional_param('middlename', '', PARAM_TEXT),
        'lastname' => optional_param('lastname', '', PARAM_TEXT),
        'username' => optional_param('username', '', PARAM_RAW_TRIMMED),
        'email' => optional_param('email', '', PARAM_RAW_TRIMMED),
        'idnumber' => optional_param('idnumber', '', PARAM_RAW_TRIMMED),
        'role' => optional_param('role', 'student', PARAM_ALPHA),
        'status' => optional_param('status', 'active', PARAM_ALPHA),
        'facultyid' => optional_param('facultyid', 0, PARAM_INT),
        'departmentid' => optional_param('departmentid', 0, PARAM_INT),
        'programmeid' => optional_param('programmeid', 0, PARAM_INT),
        'studylevel' => optional_param('studylevel', '', PARAM_TEXT),
        'staffid' => optional_param('staffid', '', PARAM_RAW_TRIMMED),
        'password' => optional_param('password', '', PARAM_RAW_TRIMMED),
        'confirmpassword' => optional_param('confirmpassword', '', PARAM_RAW_TRIMMED),
    ];

    try {
        $result = $iscreate ? $service->create_user($values) : $service->update_user($userid, $values);
    } catch (\Throwable $exception) {
        if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'user_edit::save_post', ['ctx' => basename(__FILE__)]); }
        $result = [
            'success' => false,
            'warning' => false,
            'message' => $exception->getMessage(),
            'errors' => ['general' => $exception->getMessage()],
            'values' => $values,
        ];
    }

    if (!empty($result['success'])) {
        $redirectid = (int)($result['userid'] ?? $userid);
        $redirecturl = $routingservice->get_url_for_route('management.userview', ['id' => $redirectid]);
        $type = !empty($result['warning'])
            ? \core\output\notification::NOTIFY_WARNING
            : \core\output\notification::NOTIFY_SUCCESS;
        redirect($redirecturl, (string)$result['message'], null, $type);
    }

    $errors = $result['errors'] ?? [];
    $values = $result['values'] ?? $values;
}

$academicoptions = $service->get_academic_form_options((int)$values['facultyid'], (int)$values['departmentid']);
$roleoptions = $service->get_available_role_options();
$statusoptions = [
    'active' => get_string('usermanagementstatusactive', 'local_ulms_dashboard'),
    'suspended' => get_string('usermanagementstatussuspended', 'local_ulms_dashboard'),
];
$levellist = $service->get_level_options();

echo $OUTPUT->header();
$adminservice = new \local_ulms_dashboard\local\service\admin_portal_service();
$headercontext = $adminservice->get_header_context_for_section('users');

$backurl = $userid > 0
    ? $routingservice->get_url_for_route('management.userview', ['id' => $userid])
    : $routingservice->get_url_for_route('management.users');
$headercontext['actions'][] = [
    'label' => get_string('cancel'),
    'url' => $backurl,
    'class' => 'btn btn-outline-secondary',
];

$pagetitle = get_string($iscreate ? 'usermanagementaddusertitle' : 'usermanagementeditusertitle', 'local_ulms_dashboard');
$pagemeta = $iscreate
    ? get_string('usermanagementadduserdesc', 'local_ulms_dashboard')
    : get_string('usermanagementedituserdesc', 'local_ulms_dashboard', (object)[
        'name' => $user['fullname'],
        'role' => $user['rolelabel'],
    ]);

echo local_ulms_dashboard_render_page_header([
    'eyebrow' => $headercontext['eyebrow'] ?? get_string('adminportaleyebrow', 'local_ulms_dashboard'),
    'title' => $pagetitle,
    'meta' => $pagemeta,
    'actions' => $headercontext['actions'] ?? [],
    'navitems' => $headercontext['navitems'] ?? [],
]);
local_ulms_dashboard_start_shell_wrap();

if (!empty($errors['general'])) {
    echo $OUTPUT->notification((string)$errors['general'], \core\output\notification::NOTIFY_ERROR);
}

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $url,
    'class' => 'ulms-user-form',
    'data-ulms-loading-form' => 'true',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
if ($userid > 0) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $userid]);
}

echo html_writer::start_div('ulms-layout-grid');

echo html_writer::start_div('ulms-panel');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', get_string('usermanagementaccountinformation', 'local_ulms_dashboard'), ['class' => 'ulms-panel__title']);
echo html_writer::tag('p', get_string('usermanagementaccountinformationdesc', 'local_ulms_dashboard'), ['class' => 'ulms-panel__subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');
echo html_writer::start_div('ulms-form-grid');

$fields = [
    ['name' => 'firstname', 'label' => get_string('firstname'), 'type' => 'text', 'required' => true],
    ['name' => 'middlename', 'label' => get_string('usermanagementmiddlenamelabel', 'local_ulms_dashboard'), 'type' => 'text', 'required' => false],
    ['name' => 'lastname', 'label' => get_string('lastname'), 'type' => 'text', 'required' => true],
    ['name' => 'email', 'label' => get_string('email'), 'type' => 'email', 'required' => true],
    ['name' => 'username', 'label' => get_string('username'), 'type' => 'text', 'required' => false],
    ['name' => 'idnumber', 'label' => get_string('usermanagementidnumberlabel', 'local_ulms_dashboard'), 'type' => 'text', 'required' => false],
    ['name' => 'staffid', 'label' => get_string('usermanagementstaffidlabel', 'local_ulms_dashboard'), 'type' => 'text', 'required' => false],
];

foreach ($fields as $field) {
    echo html_writer::start_div('ulms-form-field');
    echo html_writer::label($field['label'] . ($field['required'] ? ' *' : ''), 'id_' . $field['name']);
    echo html_writer::empty_tag('input', [
        'type' => $field['type'],
        'name' => $field['name'],
        'id' => 'id_' . $field['name'],
        'value' => (string)($values[$field['name']] ?? ''),
        'class' => 'form-control' . (!empty($errors[$field['name']]) ? ' is-invalid' : ''),
    ]);
    if ($field['name'] === 'username') {
        echo html_writer::tag('p', get_string('usermanagementusernamehint', 'local_ulms_dashboard'), ['class' => 'form-text text-muted']);
    }
    if (!empty($errors[$field['name']])) {
        echo html_writer::tag('div', s($errors[$field['name']]), ['class' => 'invalid-feedback d-block']);
    }
    echo html_writer::end_div();
}

foreach ([
    ['name' => 'role', 'label' => get_string('usermanagementrolelabel', 'local_ulms_dashboard'), 'options' => $roleoptions],
    ['name' => 'status', 'label' => get_string('usermanagementstatuslabel', 'local_ulms_dashboard'), 'options' => $statusoptions],
] as $field) {
    echo html_writer::start_div('ulms-form-field');
    echo html_writer::label($field['label'] . ' *', 'id_' . $field['name']);
    echo html_writer::select(
        $field['options'],
        $field['name'],
        $values[$field['name']],
        false,
        ['id' => 'id_' . $field['name'], 'class' => 'custom-select' . (!empty($errors[$field['name']]) ? ' is-invalid' : '')]
    );
    if (!empty($errors[$field['name']])) {
        echo html_writer::tag('div', s($errors[$field['name']]), ['class' => 'invalid-feedback d-block']);
    }
    echo html_writer::end_div();
}

if ($iscreate) {
    foreach ([
        ['name' => 'password', 'label' => get_string('password'), 'type' => 'password'],
        ['name' => 'confirmpassword', 'label' => get_string('usermanagementconfirmpassword', 'local_ulms_dashboard'), 'type' => 'password'],
    ] as $field) {
        echo html_writer::start_div('ulms-form-field');
        echo html_writer::label($field['label'], 'id_' . $field['name']);
        echo html_writer::empty_tag('input', [
            'type' => $field['type'],
            'name' => $field['name'],
            'id' => 'id_' . $field['name'],
            'value' => (string)($values[$field['name']] ?? ''),
            'class' => 'form-control' . (!empty($errors[$field['name']]) ? ' is-invalid' : ''),
            'autocomplete' => 'new-password',
        ]);
        if (!empty($errors[$field['name']])) {
            echo html_writer::tag('div', s($errors[$field['name']]), ['class' => 'invalid-feedback d-block']);
        }
        echo html_writer::end_div();
    }
}

echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('ulms-panel ulms-panel--soft');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', get_string('usermanagementacademicinformation', 'local_ulms_dashboard'), ['class' => 'ulms-panel__title']);
echo html_writer::tag('p', get_string('usermanagementacademicinformationdesc', 'local_ulms_dashboard'), ['class' => 'ulms-panel__subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');
echo html_writer::start_div('ulms-form-grid');

foreach ([
    ['name' => 'facultyid', 'label' => get_string('assignedfacultylabel', 'local_ulms_dashboard'), 'options' => $academicoptions['faculties']],
    ['name' => 'departmentid', 'label' => get_string('assigneddepartmentlabel', 'local_ulms_dashboard'), 'options' => $academicoptions['departments']],
    ['name' => 'programmeid', 'label' => get_string('programmesummary', 'local_ulms_dashboard'), 'options' => $academicoptions['programmes']],
    ['name' => 'studylevel', 'label' => get_string('usermanagementlevellabel', 'local_ulms_dashboard')],
] as $field) {
    echo html_writer::start_div('ulms-form-field');
    echo html_writer::label($field['label'], 'id_' . $field['name']);
    if ($field['name'] === 'studylevel') {
        echo html_writer::select(
            ['' => get_string('usermanagementnotset', 'local_ulms_dashboard')] + $levellist,
            'studylevel',
            (string)$values['studylevel'],
            false,
            ['id' => 'id_studylevel', 'class' => 'custom-select' . (!empty($errors['studylevel']) ? ' is-invalid' : '')]
        );
    } else {
        echo html_writer::select(
            $field['options'],
            $field['name'],
            (int)$values[$field['name']],
            false,
            ['id' => 'id_' . $field['name'], 'class' => 'custom-select' . (!empty($errors[$field['name']]) ? ' is-invalid' : '')]
        );
    }
    if (!empty($errors[$field['name']])) {
        echo html_writer::tag('div', s($errors[$field['name']]), ['class' => 'invalid-feedback d-block']);
    }
    echo html_writer::end_div();
}

echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::div(
    html_writer::empty_tag('input', [
        'type' => 'submit',
        'name' => 'cancel',
        'value' => get_string('cancel'),
        'class' => 'btn btn-outline-secondary',
    ]) .
    html_writer::empty_tag('input', [
        'type' => 'submit',
        'name' => 'saveuser',
        'value' => $iscreate
            ? get_string('usermanagementcreateuser', 'local_ulms_dashboard')
            : get_string('savechanges'),
        'class' => 'btn btn-primary',
        'data-loading-text' => get_string($iscreate ? 'usermanagementcreatinguser' : 'usermanagementsavingchanges', 'local_ulms_dashboard'),
    ]),
    'ulms-form-actions'
);

echo html_writer::end_tag('form');
local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
