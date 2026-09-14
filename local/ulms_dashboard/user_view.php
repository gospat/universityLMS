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
$userid = required_param('id', PARAM_INT);
$routingservice->maybe_redirect_legacy_request('management.userview', ['id' => $userid]);

require_login();

$dashboardservice = new \local_ulms_dashboard\local\service\dashboard_service();
$dashboardservice->enforce_admin_feature_access();
$dashboardservice->require_admin_permissions();

$service = new \local_ulms_dashboard\local\service\user_management_service();
$service->require_management_access();
$context = \context::instance_by_id(\context_system::instance()->id);
$url = $routingservice->get_url_for_route('management.userview', ['id' => $userid]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('usermanagementviewusertitle', 'local_ulms_dashboard'));
$PAGE->set_heading(get_string('usermanagementviewusertitle', 'local_ulms_dashboard'));
$PAGE->set_pagelayout('ulmsdashboard');

$action = optional_param('action', '', PARAM_ALPHA);
if ($action !== '' && data_submitted() && confirm_sesskey()) {
    try {
        if ($action === 'changerole') {
            $current = $service->get_user_details($userid);
            $payload = $service->get_form_defaults($current);
            $payload['firstname'] = $current['firstname'];
            $payload['middlename'] = $current['middlename'];
            $payload['lastname'] = $current['lastname'];
            $payload['username'] = $current['username'];
            $payload['email'] = $current['email'];
            $payload['idnumber'] = $current['idnumber'];
            $payload['role'] = optional_param('role', $current['rolekey'], PARAM_ALPHA);
            $payload['status'] = $current['statuskey'];
            $result = $service->update_user($userid, $payload);
        } else {
            $result = match ($action) {
                'suspend' => $service->suspend_user($userid),
                'unsuspend' => $service->unsuspend_user($userid),
                'resetpassword' => $service->send_password_reset_email($userid),
                'resendwelcome' => $service->resend_welcome_email($userid),
                'delete' => $service->delete_user_account($userid),
                default => [
                    'success' => false,
                    'warning' => false,
                    'message' => get_string('invaliddata'),
                ],
            };
        }
    } catch (\Throwable $exception) {
        if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'user_view::role_change_delete_post', []); }
        $result = [
            'success' => false,
            'warning' => false,
            'message' => $exception->getMessage(),
        ];
    }

    $type = \core\output\notification::NOTIFY_ERROR;
    if (!empty($result['success']) && !empty($result['warning'])) {
        $type = \core\output\notification::NOTIFY_WARNING;
    } else if (!empty($result['success'])) {
        $type = \core\output\notification::NOTIFY_SUCCESS;
    } else if (!empty($result['warning'])) {
        $type = \core\output\notification::NOTIFY_WARNING;
    }

    $redirecturl = ($action === 'delete')
        ? $routingservice->get_url_for_route('management.users')
        : $url;
    redirect($redirecturl, (string)$result['message'], null, $type);
}

$user = $service->get_user_details($userid);
$actions = $user['actions'];
$roleoptions = $service->get_available_role_options();

$profileitems = [];
$rows = [
    get_string('name') => $user['fullname'],
    get_string('username') => $user['username'],
    get_string('email') => $user['email'],
    get_string('usermanagementidnumberlabel', 'local_ulms_dashboard') => $user['idnumber'],
    get_string('usermanagementrolelabel', 'local_ulms_dashboard') => $user['rolelabel'],
    get_string('usermanagementstatuslabel', 'local_ulms_dashboard') => $user['statuslabel'],
];
foreach ($rows as $label => $value) {
    $profileitems[] = [
        'label' => $label,
        'value' => $value !== '' ? (string)$value : get_string('usermanagementnotset', 'local_ulms_dashboard'),
    ];
}

$academicitems = [];
$academicrows = [
    get_string('assignedfacultylabel', 'local_ulms_dashboard') => $user['facultyname'],
    get_string('assigneddepartmentlabel', 'local_ulms_dashboard') => $user['departmentname'],
    get_string('programmesummary', 'local_ulms_dashboard') => $user['programmename'],
    get_string('usermanagementlevellabel', 'local_ulms_dashboard') => $user['studylevel'],
    get_string('usermanagementstaffidlabel', 'local_ulms_dashboard') => $user['staffid'],
];
foreach ($academicrows as $label => $value) {
    $academicitems[] = [
        'label' => $label,
        'value' => $value !== '' ? (string)$value : get_string('usermanagementnotset', 'local_ulms_dashboard'),
    ];
}

$accountitems = [];
$accountrows = [
    get_string('usermanagementcreatedlabel', 'local_ulms_dashboard') => $user['timecreatedformatted'],
    get_string('timemodified') => !empty($user['timemodified']) ? userdate((int)$user['timemodified'], get_string('strftimedatetimeshort')) : get_string('never'),
    get_string('lastaccess') => $user['lastaccessformatted'],
    get_string('usermanagementauthenticationlabel', 'local_ulms_dashboard') => $user['auth'],
];
foreach ($accountrows as $label => $value) {
    $accountitems[] = [
        'label' => $label,
        'value' => $value !== '' ? (string)$value : get_string('usermanagementnotset', 'local_ulms_dashboard'),
    ];
}

$activityitems = [];
if (!empty($user['activity'])) {
    foreach ($user['activity'] as $item) {
        $activityitems[] = [
            'title' => $item['action'] . ' - ' . $item['status'],
            'meta' => $item['message'] . ' - ' . $item['timestring'],
        ];
    }
}

echo $OUTPUT->header();
$adminservice = new \local_ulms_dashboard\local\service\admin_portal_service();
$headercontext = $adminservice->get_header_context_for_section('users');

$backurl = $routingservice->get_url_for_route('management.users');
$headercontext['actions'][] = [
    'label' => get_string('usermanagementbacktolist', 'local_ulms_dashboard'),
    'url' => $backurl,
    'class' => 'btn btn-outline-secondary',
];

if (!empty($actions['edit'])) {
    $editurl = $routingservice->get_url_for_route('management.useredit', ['id' => $userid]);
    $headercontext['actions'][] = [
        'label' => get_string('edit'),
        'url' => $editurl,
        'class' => 'btn btn-primary',
    ];
}

echo local_ulms_dashboard_render_page_header([
    'eyebrow' => $headercontext['eyebrow'] ?? get_string('adminportaleyebrow', 'local_ulms_dashboard'),
    'title' => format_string($user['fullname']),
    'meta' => get_string('usermanagementviewuserdesc', 'local_ulms_dashboard', (object)[
        'role' => $user['rolelabel'],
        'status' => $user['statuslabel'],
    ]),
    'actions' => $headercontext['actions'] ?? [],
    'navitems' => $headercontext['navitems'] ?? [],
]);
local_ulms_dashboard_start_shell_wrap();

echo html_writer::start_div('ulms-layout-grid ulms-layout-grid--sidebar');
echo html_writer::start_div('ulms-layout-grid');

echo local_ulms_dashboard_render_panel([
    'title' => get_string('usermanagementprofileheading', 'local_ulms_dashboard'),
    'style' => 'definition',
    'items' => $profileitems,
]);

echo local_ulms_dashboard_render_panel([
    'title' => get_string('usermanagementacademicinformation', 'local_ulms_dashboard'),
    'style' => 'definition',
    'items' => $academicitems,
]);

echo local_ulms_dashboard_render_panel([
    'title' => get_string('usermanagementaccountdetails', 'local_ulms_dashboard'),
    'style' => 'definition',
    'items' => $accountitems,
]);

echo local_ulms_dashboard_render_panel([
    'title' => get_string('usermanagementactivityheading', 'local_ulms_dashboard'),
    'style' => 'list',
    'items' => $activityitems,
    'emptytitle' => get_string('usermanagementnoactivity', 'local_ulms_dashboard'),
    'emptydesc' => '',
]);

echo html_writer::end_div();

echo html_writer::start_div('ulms-panel ulms-panel--soft');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::tag('h2', get_string('usermanagementactionsheading', 'local_ulms_dashboard'), ['class' => 'ulms-panel__title']);
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');

if (!empty($actions['changerole'])) {
    echo html_writer::tag('h3', get_string('usermanagementchangerole', 'local_ulms_dashboard'), ['class' => 'h5']);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url, 'class' => 'mb-4', 'data-ulms-loading-form' => 'true']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'changerole']);
    echo html_writer::label(get_string('usermanagementrolelabel', 'local_ulms_dashboard'), 'id_role');
    echo html_writer::select($roleoptions, 'role', $user['rolekey'], false, ['id' => 'id_role', 'class' => 'custom-select mb-2']);
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('usermanagementchangerole', 'local_ulms_dashboard'),
        'class' => 'btn btn-outline-primary',
        'data-loading-text' => get_string('usermanagementsavingchanges', 'local_ulms_dashboard'),
        'onclick' => 'return confirm(' . json_encode(get_string('usermanagementchangeroleconfirm', 'local_ulms_dashboard', (object)[
            'name' => $user['fullname'],
            'role' => $user['rolelabel'],
        ])) . ');',
    ]);
    echo html_writer::end_tag('form');
}

foreach ([
    'resetpassword' => ['label' => get_string('usermanagementresetpassword', 'local_ulms_dashboard'), 'loading' => get_string('usermanagementsending', 'local_ulms_dashboard'), 'confirm' => get_string('usermanagementresetpasswordconfirm', 'local_ulms_dashboard', $user['fullname'])],
    'resendwelcome' => ['label' => get_string('usermanagementresendwelcome', 'local_ulms_dashboard'), 'loading' => get_string('usermanagementsending', 'local_ulms_dashboard'), 'confirm' => ''],
    'suspend' => ['label' => get_string('usermanagementsuspendaction', 'local_ulms_dashboard'), 'loading' => get_string('usermanagementsavingchanges', 'local_ulms_dashboard'), 'confirm' => get_string('usermanagementsuspendconfirm', 'local_ulms_dashboard', $user['fullname'])],
    'unsuspend' => ['label' => get_string('unsuspenduser', 'admin'), 'loading' => get_string('usermanagementsavingchanges', 'local_ulms_dashboard'), 'confirm' => ''],
    'delete' => ['label' => get_string('usermanagementdeleteaction', 'local_ulms_dashboard'), 'loading' => get_string('usermanagementdeleting', 'local_ulms_dashboard'), 'confirm' => get_string('usermanagementdeleteconfirm', 'local_ulms_dashboard', $user['fullname'])],
] as $key => $config) {
    if (empty($actions[$key])) {
        continue;
    }

    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url, 'class' => 'mb-2', 'data-ulms-loading-form' => 'true']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $key]);
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => $config['label'],
        'class' => 'btn ' . ($key === 'delete' ? 'btn-outline-danger' : 'btn-outline-secondary'),
        'data-loading-text' => $config['loading'],
        'onclick' => $config['confirm'] !== '' ? 'return confirm(' . json_encode($config['confirm']) . ');' : null,
    ]);
    echo html_writer::end_tag('form');
}

echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div();
local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
