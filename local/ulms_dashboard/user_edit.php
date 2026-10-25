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

if (optional_param('saveuser', '', PARAM_RAW_TRIMMED) !== '') {
    // Fail loudly on a stale or mismatched session token. confirm_sesskey() returns
    // false without raising anything, so submitting the edit form from an expired
    // page silently discarded the changes.
    require_sesskey();
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

// Account-type-aware field groups. The specification comes from the provisioning service,
// which is also what enforces these rules server-side, so this form and the single-account
// provisioning form accept exactly the same payload per account type.
$provisioningservice = new \local_ulms_dashboard\local\service\user_provisioning_service();
$targetroles = array_keys($provisioningservice->get_available_target_roles());
$accountspec = in_array((string)$values['role'], $targetroles, true)
    ? $provisioningservice->get_account_field_spec((string)$values['role'])
    : $provisioningservice->get_account_field_spec('');
// Which account-type specific field groups this role owns. A role outside the provisioning
// targets (the platform-level super-admin marker, and any legacy role value) is unrestricted
// and keeps the full academic panel it has today, preserving that existing governance path.
$rolestudent = (bool)$accountspec['programme']['visible'];
$rolestaff = (bool)$accountspec['staffid']['visible'];
$roleunrestricted = !$rolestudent && !$rolestaff;
// The Staff ID requirement is an account-creation requirement: existing staff accounts that
// do not carry one must still be editable. This mirrors
// user_management_service::validate_user_form_data(), which applies it on creation only.
$staffidrequired = $iscreate && (bool)$accountspec['staffid']['required'];
// Account-type guidance, announced through a live region so switching the account type is
// perceivable without relying on a visual-only change.
$rolehints = [];
foreach (array_keys($roleoptions) as $hintrole) {
    $rolehints[$hintrole] = get_string('provisioningrolecondhint' . $hintrole, 'local_ulms_dashboard');
}

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

// Shared personal information. These fields belong to every account type, so they are
// never hidden, disabled or cleared when the account type changes. The learner
// matriculation number and the staff identifier are account-type specific and are
// rendered inside the role-scoped groups further down instead.
$fields = [
    ['name' => 'firstname', 'label' => get_string('firstname'), 'type' => 'text', 'required' => true],
    ['name' => 'middlename', 'label' => get_string('usermanagementmiddlenamelabel', 'local_ulms_dashboard'), 'type' => 'text', 'required' => false],
    ['name' => 'lastname', 'label' => get_string('lastname'), 'type' => 'text', 'required' => true],
    ['name' => 'email', 'label' => get_string('email'), 'type' => 'email', 'required' => true],
    ['name' => 'username', 'label' => get_string('username'), 'type' => 'text', 'required' => false],
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

// Account type. Changing it re-scopes the field groups below: groups that do not belong
// to the selected account type are hidden and their controls disabled, so a value
// entered for one account type can never be saved against another.
echo html_writer::start_div('ulms-form-field');
echo html_writer::label(get_string('usermanagementrolelabel', 'local_ulms_dashboard') . ' *', 'id_role');
echo html_writer::select(
    $roleoptions,
    'role',
    $values['role'],
    false,
    [
        'id' => 'id_role',
        'class' => 'custom-select' . (!empty($errors['role']) ? ' is-invalid' : ''),
        'data-ulms-provisioning-role' => 'true',
    ]
);
if (!empty($errors['role'])) {
    echo html_writer::tag('div', s($errors['role']), ['class' => 'invalid-feedback d-block']);
}
echo html_writer::end_div();

echo html_writer::start_div('ulms-form-field');
echo html_writer::label(get_string('usermanagementstatuslabel', 'local_ulms_dashboard') . ' *', 'id_status');
echo html_writer::select(
    $statusoptions,
    'status',
    $values['status'],
    false,
    ['id' => 'id_status', 'class' => 'custom-select' . (!empty($errors['status']) ? ' is-invalid' : '')]
);
if (!empty($errors['status'])) {
    echo html_writer::tag('div', s($errors['status']), ['class' => 'invalid-feedback d-block']);
}
echo html_writer::end_div();

// Account-type guidance is announced through a live region so switching the account
// type is perceivable without a sighted-only visual change.
echo html_writer::tag(
    'p',
    (string)($rolehints[(string)$values['role']] ?? ''),
    [
        'id' => 'id_role_cond_hint',
        'class' => 'form-text text-muted ml-3 mb-3',
        'role' => 'status',
        'aria-live' => 'polite',
    ]
);

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
// Learner academic enrolment. The programme resolves the College and Department, and the
// study level comes from the student vocabulary only.
$studentblockattributes = ['data-ulms-role-block' => 'student'];
if (!$rolestudent) {
    $studentblockattributes['hidden'] = 'hidden';
    $studentblockattributes['aria-hidden'] = 'true';
    $studentblockattributes['style'] = 'display:none;';
}

// Staff academic assignment. A staff account owns a College and a Department; it is never
// enrolled on a student programme and no study level is stored against it.
$staffblockattributes = ['data-ulms-role-block' => 'lecturer admin'];
if (!$rolestaff) {
    $staffblockattributes['hidden'] = 'hidden';
    $staffblockattributes['aria-hidden'] = 'true';
    $staffblockattributes['style'] = 'display:none;';
}

// Any role outside the provisioning targets keeps the full academic panel it has today.
$legacyroles = array_values(array_diff(array_keys($roleoptions), $targetroles));
if ($roleunrestricted && !in_array((string)$values['role'], $legacyroles, true)) {
    $legacyroles[] = (string)$values['role'];
}
$legacyblockattributes = ['data-ulms-role-block' => implode(' ', $legacyroles)];
if (!$roleunrestricted) {
    $legacyblockattributes['hidden'] = 'hidden';
    $legacyblockattributes['aria-hidden'] = 'true';
    $legacyblockattributes['style'] = 'display:none;';
}

/**
 * Renders one account-type-specific select field.
 *
 * @param string $name Field name submitted to the server.
 * @param string $label Visible label.
 * @param array $options Select options.
 * @param mixed $selected Selected value.
 * @param string $id Element id.
 * @param bool $required Whether the field is mandatory.
 * @param string $error Validation message, if any.
 * @param bool $disabled Whether the field belongs to an inactive account type.
 * @return string
 */
$renderroleselect = function (
    string $name,
    string $label,
    array $options,
    $selected,
    string $id,
    bool $required = false,
    string $error = '',
    bool $disabled = false
): string {
    $attributes = [
        'id' => $id,
        'class' => 'custom-select' . ($error !== '' ? ' is-invalid' : ''),
    ];
    if ($required) {
        // Marked so the account-type switcher can restore the requirement when the field
        // becomes applicable again.
        $attributes['data-ulms-field-required'] = 'true';
        $attributes['required'] = 'required';
        $attributes['aria-required'] = 'true';
    }
    if ($disabled) {
        // A disabled control is never submitted. The learner and staff groups both carry
        // facultyid/departmentid, so without this the inactive group would overwrite the
        // value of the active account type.
        $attributes['disabled'] = 'disabled';
    }
    $html = html_writer::start_div('ulms-form-field');
    $html .= html_writer::label($label . ($required ? ' *' : ''), $id);
    $html .= html_writer::select($options, $name, $selected, false, $attributes);
    if ($error !== '') {
        $html .= html_writer::tag('div', s($error), ['class' => 'invalid-feedback d-block']);
    }
    $html .= html_writer::end_div();

    return $html;
};

// Learner group.
echo html_writer::start_div('ulms-form-grid', $studentblockattributes);

$studentinputattributes = [
    'type' => 'text',
    'name' => 'idnumber',
    'id' => 'id_idnumber',
    'value' => (string)($values['idnumber'] ?? ''),
    'class' => 'form-control' . (!empty($errors['idnumber']) ? ' is-invalid' : ''),
];
if (!$rolestudent) {
    // A disabled control is never submitted, so a stale learner value cannot travel with a
    // staff account even if the inline account-type switcher never runs.
    $studentinputattributes['disabled'] = 'disabled';
}
echo html_writer::start_div('ulms-form-field');
echo html_writer::label(get_string('provisioningfieldmatriculation', 'local_ulms_dashboard'), 'id_idnumber');
echo html_writer::empty_tag('input', $studentinputattributes);
if (!empty($errors['idnumber'])) {
    echo html_writer::tag('div', s($errors['idnumber']), ['class' => 'invalid-feedback d-block']);
}
echo html_writer::end_div();

echo $renderroleselect(
    'programmeid',
    get_string('programmesummary', 'local_ulms_dashboard'),
    $academicoptions['programmes'],
    (int)$values['programmeid'],
    'id_programmeid',
    (bool)$accountspec['programme']['required'],
    (string)($errors['programmeid'] ?? ''),
    !$rolestudent
);
echo html_writer::tag('p', get_string('provisioningprogrammestudenthint', 'local_ulms_dashboard'), ['class' => 'form-text text-muted']);
echo $renderroleselect(
    'facultyid',
    get_string('assignedfacultylabel', 'local_ulms_dashboard'),
    $academicoptions['faculties'],
    (int)$values['facultyid'],
    'id_facultyid',
    false,
    (string)($errors['facultyid'] ?? ''),
    !$rolestudent
);
echo $renderroleselect(
    'departmentid',
    get_string('assigneddepartmentlabel', 'local_ulms_dashboard'),
    $academicoptions['departments'],
    (int)$values['departmentid'],
    'id_departmentid',
    false,
    (string)($errors['departmentid'] ?? ''),
    !$rolestudent
);
echo $renderroleselect(
    'studylevel',
    get_string('usermanagementlevellabel', 'local_ulms_dashboard'),
    ['' => get_string('usermanagementnotset', 'local_ulms_dashboard')] + $levellist,
    (string)$values['studylevel'],
    'id_studylevel',
    false,
    (string)($errors['studylevel'] ?? ''),
    !$rolestudent
);

echo html_writer::end_div();

// Staff group.
echo html_writer::start_div('ulms-form-grid', $staffblockattributes);

$staffinputattributes = [
    'type' => 'text',
    'name' => 'staffid',
    'id' => 'id_staffid',
    'value' => (string)($values['staffid'] ?? ''),
    'class' => 'form-control' . (!empty($errors['staffid']) ? ' is-invalid' : ''),
];
if ($staffidrequired) {
    $staffinputattributes['data-ulms-field-required'] = 'true';
    $staffinputattributes['required'] = 'required';
    $staffinputattributes['aria-required'] = 'true';
}
if (!$rolestaff) {
    $staffinputattributes['disabled'] = 'disabled';
}
echo html_writer::start_div('ulms-form-field');
echo html_writer::label(
    get_string('provisioningfieldstaffid', 'local_ulms_dashboard') . ($staffidrequired ? ' *' : ''),
    'id_staffid'
);
echo html_writer::empty_tag('input', $staffinputattributes);
if (!empty($errors['staffid'])) {
    echo html_writer::tag('div', s($errors['staffid']), ['class' => 'invalid-feedback d-block']);
}
echo html_writer::end_div();

echo $renderroleselect(
    'facultyid',
    get_string('provisioningfieldstaffcollege', 'local_ulms_dashboard'),
    $academicoptions['faculties'],
    (int)$values['facultyid'],
    'id_staff_facultyid',
    false,
    (string)($errors['facultyid'] ?? ''),
    !$rolestaff
);
echo $renderroleselect(
    'departmentid',
    get_string('provisioningfieldstaffdepartment', 'local_ulms_dashboard'),
    $academicoptions['departments'],
    (int)$values['departmentid'],
    'id_staff_departmentid',
    false,
    (string)($errors['departmentid'] ?? ''),
    !$rolestaff
);
echo html_writer::tag('p', get_string('provisioningstaffassignmenthint', 'local_ulms_dashboard'), ['class' => 'form-text text-muted']);

echo html_writer::end_div();

// Unrestricted group: unchanged full academic panel for roles outside the provisioning targets.
echo html_writer::start_div('ulms-form-grid', $legacyblockattributes);

$legacyinputattributes = ['type' => 'text', 'class' => 'form-control'];
foreach ([
    ['name' => 'idnumber', 'label' => get_string('usermanagementidnumberlabel', 'local_ulms_dashboard')],
    ['name' => 'staffid', 'label' => get_string('usermanagementstaffidlabel', 'local_ulms_dashboard')],
] as $legacyfield) {
    echo html_writer::start_div('ulms-form-field');
    echo html_writer::label($legacyfield['label'], 'id_legacy_' . $legacyfield['name']);
    echo html_writer::empty_tag('input', array_merge($legacyinputattributes, [
        'name' => $legacyfield['name'],
        'id' => 'id_legacy_' . $legacyfield['name'],
        'value' => (string)($values[$legacyfield['name']] ?? ''),
        'class' => 'form-control' . (!empty($errors[$legacyfield['name']]) ? ' is-invalid' : ''),
        'disabled' => $roleunrestricted ? null : 'disabled',
    ]));
    if (!empty($errors[$legacyfield['name']])) {
        echo html_writer::tag('div', s($errors[$legacyfield['name']]), ['class' => 'invalid-feedback d-block']);
    }
    echo html_writer::end_div();
}

echo $renderroleselect(
    'facultyid',
    get_string('assignedfacultylabel', 'local_ulms_dashboard'),
    $academicoptions['faculties'],
    (int)$values['facultyid'],
    'id_legacy_facultyid',
    false,
    $roleunrestricted ? (string)($errors['facultyid'] ?? '') : '',
    !$roleunrestricted
);
echo $renderroleselect(
    'departmentid',
    get_string('assigneddepartmentlabel', 'local_ulms_dashboard'),
    $academicoptions['departments'],
    (int)$values['departmentid'],
    'id_legacy_departmentid',
    false,
    $roleunrestricted ? (string)($errors['departmentid'] ?? '') : '',
    !$roleunrestricted
);
echo $renderroleselect(
    'programmeid',
    get_string('programmesummary', 'local_ulms_dashboard'),
    $academicoptions['programmes'],
    (int)$values['programmeid'],
    'id_legacy_programmeid',
    false,
    $roleunrestricted ? (string)($errors['programmeid'] ?? '') : '',
    !$roleunrestricted
);
echo $renderroleselect(
    'studylevel',
    get_string('usermanagementlevellabel', 'local_ulms_dashboard'),
    ['' => get_string('usermanagementnotset', 'local_ulms_dashboard')] + $levellist,
    (string)$values['studylevel'],
    'id_legacy_studylevel',
    false,
    $roleunrestricted ? (string)($errors['studylevel'] ?? '') : '',
    !$roleunrestricted
);

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

// Account-type switcher. The server already rendered the correct groups for the current
// account type, so this only keeps the form in step when the operator changes the dropdown.
// A group that does not apply is hidden and its controls disabled, which is also what stops
// a stale learner value travelling with a staff account.
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
        };
        roleSelect.addEventListener("change", applyRole);
        applyRole();
    }());'
);

local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
