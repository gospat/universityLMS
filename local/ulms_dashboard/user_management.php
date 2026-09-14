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
$rawfilters = [
    'search' => optional_param('search', '', PARAM_TEXT),
    'role' => optional_param('role', '', PARAM_ALPHA),
    'status' => optional_param('status', '', PARAM_ALPHA),
    'departmentid' => optional_param('departmentid', 0, PARAM_INT),
    'level' => optional_param('level', '', PARAM_TEXT),
    'sort' => optional_param('sort', 'name', PARAM_ALPHA),
    'dir' => optional_param('dir', 'ASC', PARAM_ALPHA),
    'page' => optional_param('page', 0, PARAM_INT),
    'perpage' => optional_param('perpage', 25, PARAM_INT),
];
$service = new \local_ulms_dashboard\local\service\user_management_service();
$filters = $service->normalise_filters($rawfilters);
$routingservice->maybe_redirect_legacy_request('management.users', $service->get_filter_url_params($filters));

require_login();

$dashboardservice = new \local_ulms_dashboard\local\service\dashboard_service();
$dashboardservice->enforce_admin_feature_access();
$dashboardservice->require_admin_permissions();

$service->require_management_access();

$context = \context::instance_by_id(\context_system::instance()->id);
$url = $routingservice->get_url_for_route('management.users', $service->get_filter_url_params($filters));
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('adminusermanagementlink', 'local_ulms_dashboard'));
$PAGE->set_heading(get_string('adminusermanagementlink', 'local_ulms_dashboard'));
$PAGE->set_pagelayout('ulmsdashboard');

$action = optional_param('action', '', PARAM_ALPHA);
$actionuserid = optional_param('userid', 0, PARAM_INT);

if ($action !== '' && $actionuserid > 0 && data_submitted() && confirm_sesskey()) {
    $redirectparams = $service->get_filter_url_params($filters);
    $redirecturl = $routingservice->get_url_for_route('management.users', $redirectparams);

    try {
        $result = match ($action) {
            'suspend' => $service->suspend_user($actionuserid),
            'unsuspend' => $service->unsuspend_user($actionuserid),
            'resetpassword' => $service->send_password_reset_email($actionuserid),
            'resendwelcome' => $service->resend_welcome_email($actionuserid),
            'delete' => $service->delete_user_account($actionuserid),
            default => [
                'success' => false,
                'warning' => false,
                'message' => get_string('invaliddata'),
            ],
        };
    } catch (\Throwable $exception) {
        if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'user_management::bulk_post_actions', ['ctx' => basename(__FILE__)]); }
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

    redirect($redirecturl, (string)$result['message'], null, $type);
}

$listing = $service->get_user_listing($filters);
$summarycards = $service->get_summary_cards();
$roleoptions = ['' => get_string('all')] + $service->get_available_role_options();
$statusoptions = $service->get_status_options();
$departmentoptions = $service->get_department_options();
$leveloptions = ['' => get_string('all')] + $service->get_level_options();
$sortoptions = $service->get_sort_options();
$perpageoptions = $service->get_per_page_options();
$cancreateusers = has_capability('moodle/user:create', $context);

$basepagingparams = $service->get_filter_url_params($filters);
unset($basepagingparams['page']);
$pagingurl = $routingservice->get_url_for_route('management.users', $basepagingparams);
$totalpages = (int)ceil(($listing['total'] ?: 1) / $filters['perpage']);

echo $OUTPUT->header();
$adminservice = new \local_ulms_dashboard\local\service\admin_portal_service();
$headercontext = $adminservice->get_header_context_for_section('users');

if ($cancreateusers) {
    $adduserurl = $routingservice->get_url_for_route('management.usercreate');
    $headercontext['actions'][] = [
        'label' => get_string('usermanagementadduser', 'local_ulms_dashboard'),
        'url' => $adduserurl,
        'class' => 'btn btn-primary ulms-cta',
    ];
}

echo local_ulms_dashboard_render_page_header([
    'eyebrow' => $headercontext['eyebrow'] ?? get_string('adminportaleyebrow', 'local_ulms_dashboard'),
    'title' => $headercontext['title'] ?? get_string('adminusermanagementlink', 'local_ulms_dashboard'),
    'meta' => $headercontext['meta'] ?? get_string('usermanagementintro', 'local_ulms_dashboard'),
    'actions' => $headercontext['actions'] ?? [],
    'navitems' => $headercontext['navitems'] ?? [],
]);
local_ulms_dashboard_start_shell_wrap();

echo local_ulms_dashboard_render_summary_cards($summarycards);

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => $routingservice->get_url_for_route('management.users'),
    'class' => 'ulms-panel ulms-panel--soft',
    'data-ulms-loading-form' => 'true',
]);
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', get_string('usermanagementfilterlabel', 'local_ulms_dashboard'), ['class' => 'ulms-panel__title']);
echo html_writer::tag('p', get_string('usermanagementfilterdesc', 'local_ulms_dashboard'), ['class' => 'ulms-panel__subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');
echo html_writer::start_div('ulms-filter-grid', ['style' => 'min-width:0;']);

echo html_writer::start_div('ulms-filter-field ulms-filter-field--wide', ['style' => 'min-width:0;']);
echo html_writer::label(get_string('usermanagementsearchlabel', 'local_ulms_dashboard'), 'id_search', false);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'search',
    'id' => 'id_search',
    'value' => $filters['search'],
    'placeholder' => get_string('usermanagementsearchplaceholder', 'local_ulms_dashboard'),
    'class' => 'form-control',
    'style' => 'width:100%;min-width:0;',
]);
echo html_writer::end_div();

foreach ([
    ['name' => 'role', 'label' => get_string('usermanagementrolelabel', 'local_ulms_dashboard'), 'options' => $roleoptions, 'value' => $filters['role']],
    ['name' => 'status', 'label' => get_string('usermanagementstatuslabel', 'local_ulms_dashboard'), 'options' => $statusoptions, 'value' => $filters['status']],
    ['name' => 'departmentid', 'label' => get_string('assigneddepartmentlabel', 'local_ulms_dashboard'), 'options' => $departmentoptions, 'value' => $filters['departmentid']],
    ['name' => 'level', 'label' => get_string('usermanagementlevellabel', 'local_ulms_dashboard'), 'options' => $leveloptions, 'value' => $filters['level']],
    ['name' => 'sort', 'label' => get_string('usermanagementsortbylabel', 'local_ulms_dashboard'), 'options' => $sortoptions, 'value' => $filters['sort']],
    ['name' => 'dir', 'label' => get_string('usermanagementsortdirectionlabel', 'local_ulms_dashboard'), 'options' => ['ASC' => 'ASC', 'DESC' => 'DESC'], 'value' => $filters['dir']],
    ['name' => 'perpage', 'label' => get_string('usermanagementperpagelabel', 'local_ulms_dashboard'), 'options' => $perpageoptions, 'value' => $filters['perpage']],
] as $field) {
    echo html_writer::start_div('ulms-filter-field', ['style' => 'min-width:0;']);
    echo html_writer::label($field['label'], 'id_' . $field['name'], false);
    echo html_writer::select(
        $field['options'],
        $field['name'],
        $field['value'],
        false,
        ['id' => 'id_' . $field['name'], 'class' => 'custom-select', 'style' => 'width:100%;min-width:0;']
    );
    echo html_writer::end_div();
}

echo html_writer::end_div();
echo html_writer::div(
    html_writer::link(
        $routingservice->get_url_for_route('management.users'),
        get_string('usermanagementresetlabel', 'local_ulms_dashboard'),
        ['class' => 'btn btn-outline-secondary']
    ) .
    html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('usermanagementsearchlabel', 'local_ulms_dashboard'),
        'class' => 'btn btn-primary',
        'data-loading-text' => get_string('usermanagementloadingusers', 'local_ulms_dashboard'),
    ]),
    'ulms-filter-actions d-flex justify-content-between align-items-center'
);
echo html_writer::end_div();
echo html_writer::end_tag('form');

echo html_writer::start_div('ulms-panel');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', get_string('adminusermanagementlink', 'local_ulms_dashboard'), ['class' => 'ulms-panel__title']);
echo html_writer::tag(
    'p',
    get_string('usermanagementresultssummary', 'local_ulms_dashboard', (object)[
        'start' => $listing['start'],
        'end' => $listing['end'],
        'total' => $listing['total'],
    ]),
    ['class' => 'ulms-panel__subtitle']
);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');

if (empty($listing['rows'])) {
    $emptytext = $filters['search'] !== '' || $filters['role'] !== '' || $filters['status'] !== '' ||
        $filters['departmentid'] > 0 || $filters['level'] !== ''
        ? get_string('usermanagementemptysearch', 'local_ulms_dashboard')
        : get_string('usermanagementempty', 'local_ulms_dashboard');

    echo html_writer::div(
        html_writer::tag('h3', $emptytext, ['class' => 'ulms-empty-state__title']) .
        html_writer::link(
            $routingservice->get_url_for_route('management.users'),
            get_string('usermanagementclearsearch', 'local_ulms_dashboard'),
            ['class' => 'btn btn-outline-secondary']
        ),
        'ulms-empty-state'
    );
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable ulms-desktop-table';
    $table->head = [
        get_string('name'),
        get_string('username'),
        get_string('email'),
        get_string('usermanagementrolelabel', 'local_ulms_dashboard'),
        get_string('usermanagementidnumberlabel', 'local_ulms_dashboard'),
        get_string('usermanagementstatuslabel', 'local_ulms_dashboard'),
        get_string('usermanagementcreatedlabel', 'local_ulms_dashboard'),
        get_string('lastaccess'),
        get_string('usermanagementactionsheading', 'local_ulms_dashboard'),
    ];

    foreach ($listing['rows'] as $row) {
        $primary_actions = [];
        $danger_actions = [];
        $viewurl = $routingservice->get_url_for_route('management.userview', ['id' => $row['id']]);
        $editurl = $routingservice->get_url_for_route('management.useredit', ['id' => $row['id']]);
        $primary_actions[] = html_writer::link($viewurl, get_string('view'));
        if (!empty($row['actions']['edit'])) {
            $primary_actions[] = html_writer::link($editurl, get_string('edit'));
        }
        if (!empty($row['actions']['changerole'])) {
            $primary_actions[] = html_writer::link($routingservice->get_url_for_route('management.useredit', ['id' => $row['id'], 'focus' => 'role']), get_string('usermanagementchangerole', 'local_ulms_dashboard'));
        }
        if (!empty($row['actions']['resetpassword'])) {
            $primary_actions[] = html_writer::tag('form',
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => $row['id']]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'resetpassword']) .
                html_writer::empty_tag('input', [
                    'type' => 'submit',
                    'value' => get_string('usermanagementresetpassword', 'local_ulms_dashboard'),
                    'class' => 'btn btn-link btn-sm',
                    'data-loading-text' => get_string('usermanagementsending', 'local_ulms_dashboard'),
                    'onclick' => 'return confirm(' . json_encode(get_string('usermanagementresetpasswordconfirm', 'local_ulms_dashboard', $row['fullname'])) . ');',
                ]),
                ['method' => 'post', 'action' => $url->out(false), 'class' => 'd-inline', 'data-ulms-loading-form' => 'true']
            );
        }
        if (!empty($row['actions']['resendwelcome'])) {
            $primary_actions[] = html_writer::tag('form',
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => $row['id']]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'resendwelcome']) .
                html_writer::empty_tag('input', [
                    'type' => 'submit',
                    'value' => get_string('usermanagementresendwelcome', 'local_ulms_dashboard'),
                    'class' => 'btn btn-link btn-sm',
                    'data-loading-text' => get_string('usermanagementsending', 'local_ulms_dashboard'),
                ]),
                ['method' => 'post', 'action' => $url->out(false), 'class' => 'd-inline', 'data-ulms-loading-form' => 'true']
            );
        }
        if (!empty($row['actions']['suspend'])) {
            $danger_actions[] = html_writer::tag('form',
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => $row['id']]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'suspend']) .
                html_writer::empty_tag('input', [
                    'type' => 'submit',
                    'value' => get_string('usermanagementsuspendaction', 'local_ulms_dashboard'),
                    'class' => 'btn btn-link btn-sm',
                    'data-loading-text' => get_string('usermanagementsavingchanges', 'local_ulms_dashboard'),
                    'onclick' => 'return confirm(' . json_encode(get_string('usermanagementsuspendconfirm', 'local_ulms_dashboard', $row['fullname'])) . ');',
                ]),
                ['method' => 'post', 'action' => $url->out(false), 'class' => 'd-inline', 'data-ulms-loading-form' => 'true']
            );
        }
        if (!empty($row['actions']['unsuspend'])) {
            $primary_actions[] = html_writer::tag('form',
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => $row['id']]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'unsuspend']) .
                html_writer::empty_tag('input', [
                    'type' => 'submit',
                    'value' => get_string('unsuspenduser', 'admin'),
                    'class' => 'btn btn-link btn-sm',
                    'data-loading-text' => get_string('usermanagementsavingchanges', 'local_ulms_dashboard'),
                ]),
                ['method' => 'post', 'action' => $url->out(false), 'class' => 'd-inline', 'data-ulms-loading-form' => 'true']
            );
        }
        if (!empty($row['actions']['delete'])) {
            $danger_actions[] = html_writer::tag('form',
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => $row['id']]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'delete']) .
                html_writer::empty_tag('input', [
                    'type' => 'submit',
                    'value' => get_string('usermanagementdeleteaction', 'local_ulms_dashboard'),
                    'class' => 'btn btn-link btn-sm text-danger',
                    'data-loading-text' => get_string('usermanagementdeleting', 'local_ulms_dashboard'),
                    'onclick' => 'return confirm(' . json_encode(get_string('usermanagementdeleteconfirm', 'local_ulms_dashboard', $row['fullname'])) . ');',
                ]),
                ['method' => 'post', 'action' => $url->out(false), 'class' => 'd-inline', 'data-ulms-loading-form' => 'true']
            );
        }

        $actions_html = html_writer::start_div('d-flex ulms-d-flex-gap-2 flex-wrap align-items-center');
        $actions_html .= implode('', $primary_actions);
        if (!empty($danger_actions)) {
            $actions_html .= html_writer::start_div('ulms-card-actions--danger-group ms-auto ms-3 d-inline-flex ulms-d-inline-flex-gap-2');
            $actions_html .= implode('', $danger_actions);
            $actions_html .= html_writer::end_div();
        }
        $actions_html .= html_writer::end_div();

        $table->data[] = [
            format_string($row['fullname']),
            s($row['username']),
            s($row['email']),
            format_string($row['rolelabel']),
            s($row['idnumber']),
            format_string($row['statuslabel']),
            s($row['timecreatedformatted']),
            s($row['lastaccessformatted']),
            html_writer::div($actions_html, 'ulms-user-actions'),
        ];
    }

    echo html_writer::div(html_writer::table($table), 'ulms-table-wrap');

    echo html_writer::start_div('ulms-mobile-cards');
    foreach ($listing['rows'] as $row) {
        echo html_writer::start_div('ulms-record-card');
        echo html_writer::tag('h3', format_string($row['fullname']), ['class' => 'ulms-record-card__title']);
        echo html_writer::tag('p', s($row['email']), ['class' => 'ulms-record-card__meta']);
        echo html_writer::tag('p', format_string($row['rolelabel']) . ' | ' . format_string($row['statuslabel']), ['class' => 'ulms-record-card__meta']);
        echo html_writer::tag('p', get_string('username') . ': ' . s($row['username']), ['class' => 'ulms-record-card__meta']);
        echo html_writer::tag('p', get_string('usermanagementidnumberlabel', 'local_ulms_dashboard') . ': ' . s($row['idnumber']), ['class' => 'ulms-record-card__meta']);
        echo html_writer::div(
            html_writer::link($routingservice->get_url_for_route('management.userview', ['id' => $row['id']]), get_string('view'), ['class' => 'btn btn-outline-secondary btn-sm']) .
            (!empty($row['actions']['edit']) ? html_writer::link($routingservice->get_url_for_route('management.useredit', ['id' => $row['id']]), get_string('edit'), ['class' => 'btn btn-primary btn-sm']) : ''),
            'ulms-user-card-actions'
        );
        echo html_writer::end_div();
    }
    echo html_writer::end_div();

    if ($listing['total'] > $filters['perpage']) {
        echo $OUTPUT->render(new paging_bar($listing['total'], $filters['page'], $filters['perpage'], $pagingurl));
    }
}

echo html_writer::end_div();
echo html_writer::end_div();
local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
