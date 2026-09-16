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
global $PAGE, $OUTPUT, $DB;

require_login();

$dashboardservice = new \local_ulms_dashboard\local\service\dashboard_service();
$dashboardservice->enforce_admin_feature_access();

$context = \context::instance_by_id(\context_system::instance()->id);
require_capability('local/ulms_dashboard:managelevels', $context);

$search = optional_param('search', '', PARAM_TEXT);
$status = optional_param('status', '', PARAM_ALPHA);
$sort = optional_param('sort', 'sortorder', PARAM_ALPHA);
$dir = optional_param('dir', 'ASC', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 20, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$editid = optional_param('editid', 0, PARAM_INT);
$deleteid = optional_param('deleteid', 0, PARAM_INT);
$toggleid = optional_param('toggleid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$usersvc = new \local_ulms_dashboard\local\service\user_management_service();
$routingservice = new \local_ulms_auth\local\service\landing_page_service();
$portalservice = new \local_ulms_dashboard\local\service\admin_portal_service();

$stateparams = [
    'search' => $search,
    'status' => $status,
    'sort' => $sort,
    'dir' => $dir,
    'page' => $page,
    'perpage' => $perpage,
];
$routingservice->maybe_redirect_legacy_request('management.academicslevels', $stateparams);
$contexturl = $routingservice->get_url_for_route('management.academicslevels', $stateparams);
$contexturlstring = $contexturl->out(false);
$formurlstring = $routingservice->get_url_for_route('management.academicslevels')->out(false);

local_ulms_dashboard_prepare_page(
    $context,
    $contexturl,
    get_string('levels', 'local_ulms_academics')
);

$notifications = [];

if ($action === 'delete' && $deleteid > 0 && $confirm && confirm_sesskey()) {
    $result = $usersvc->delete_level($deleteid);
    $notifications[] = [
        'message' => $result['message'],
        'type' => !empty($result['success'])
            ? \core\output\notification::NOTIFY_SUCCESS
            : \core\output\notification::NOTIFY_ERROR,
    ];
    redirect($contexturl, $result['message'], null, end($notifications)['type']);
}

if ($action === 'toggle' && $toggleid > 0 && confirm_sesskey()) {
    $result = $usersvc->toggle_level_status($toggleid);
    $notifications[] = [
        'message' => $result['message'],
        'type' => !empty($result['success'])
            ? \core\output\notification::NOTIFY_SUCCESS
            : \core\output\notification::NOTIFY_ERROR,
    ];
    redirect($contexturl, $result['message'], null, end($notifications)['type']);
}

$editrecord = $editid > 0 ? $usersvc->get_level($editid) : null;
$formdefaults = [
    'id' => $editrecord->id ?? 0,
    'code' => $editrecord->code ?? '',
    'name' => $editrecord->name ?? '',
    'sortorder' => (int)($editrecord->sortorder ?? 0),
    'status' => $editrecord->status ?? 'active',
];

if (optional_param('savelevel', 0, PARAM_BOOL) && confirm_sesskey()) {
    $payload = [
        'id' => optional_param('id', 0, PARAM_INT),
        'code' => trim(optional_param('code', '', PARAM_TEXT)),
        'name' => trim(optional_param('name', '', PARAM_TEXT)),
        'sortorder' => optional_param('sortorder', 0, PARAM_INT),
        'status' => optional_param('status', 'active', PARAM_ALPHA),
    ];
    $result = $usersvc->save_level($payload);
    if (!empty($result['success'])) {
        redirect(
            $contexturl,
            $result['message'],
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    $formdefaults = $payload;
    $notifications[] = [
        'message' => $result['message'] ?? get_string('recordnotsaved', 'local_ulms_academics'),
        'type' => \core\output\notification::NOTIFY_ERROR,
    ];
    if (!empty($result['errors'])) {
        foreach ($result['errors'] as $err) {
            if (is_string($err) && $err !== ($result['message'] ?? '')) {
                $notifications[] = [
                    'message' => $err,
                    'type' => \core\output\notification::NOTIFY_ERROR,
                ];
            }
        }
    }
}

$resultset = $usersvc->get_levels([
    'search' => $search,
    'status' => $status,
    'sort' => $sort,
    'dir' => $dir,
    'page' => $page,
    'perpage' => $perpage,
]);
$rows = $resultset['rows'] ?? [];
$total = (int)($resultset['total'] ?? 0);

$statusoptions = [
    'active' => get_string('active'),
    'inactive' => get_string('inactive', 'local_ulms_dashboard'),
];

$statusfilteroptions = ['' => get_string('allstatuses', 'local_ulms_academics')] + $statusoptions;


$perpageoptions = [10 => 10, 20 => 20, 50 => 50, 100 => 100];

$statusstats = [];
foreach (['all' => $total, 'active' => 0, 'inactive' => 0] as $k => $v) {
    if ($k === 'all') {
        $statusstats[$k] = $total;
    } else {
        $s = $usersvc->get_levels(['status' => $k, 'page' => 0, 'perpage' => 1]);
        $statusstats[$k] = (int)($s['total'] ?? 0);
    }
}

echo $OUTPUT->header();
$headercontext = $portalservice->get_header_context_for_section('academics');
$headercontext['hasbreadcrumbs'] = true;
$headercontext['breadcrumbs'] = array_merge($headercontext['breadcrumbs'] ?? [], [
    ['label' => get_string('levels', 'local_ulms_academics'), 'url' => null],
]);
echo local_ulms_dashboard_render_page_header($headercontext);
local_ulms_dashboard_start_shell_wrap();

foreach ($notifications as $n) {
    echo $OUTPUT->notification($n['message'], $n['type']);
}

echo html_writer::start_div('ulms-layout-grid');
echo html_writer::start_div('ulms-panel ulms-panel--soft');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', $editrecord
    ? get_string('editlevel', 'local_ulms_dashboard')
    : get_string('addlevel', 'local_ulms_dashboard'),
    ['class' => 'ulms-panel__title']);
echo html_writer::tag(
    'p',
    get_string('levelsformhint', 'local_ulms_dashboard'),
    ['class' => 'ulms-panel__subtitle']
);
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
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => (string)$formdefaults['id']]);

echo html_writer::start_div('row');
echo html_writer::start_div('col-md-4 mb-3');
echo html_writer::label(get_string('code', 'local_ulms_academics'), 'id_levelcode');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'code',
    'id' => 'id_levelcode',
    'class' => 'form-control',
    'required' => 'required',
    'maxlength' => 50,
    'value' => s($formdefaults['code']),
]);
echo html_writer::end_div();

echo html_writer::start_div('col-md-4 mb-3');
echo html_writer::label(get_string('name'), 'id_levelname');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'name',
    'id' => 'id_levelname',
    'class' => 'form-control',
    'required' => 'required',
    'maxlength' => 255,
    'value' => s($formdefaults['name']),
]);
echo html_writer::end_div();

echo html_writer::start_div('col-md-4 mb-3');
echo html_writer::label(get_string('status', 'local_ulms_academics'), 'id_levelstatus');
echo html_writer::select($statusoptions, 'status', $formdefaults['status'], false, [
    'id' => 'id_levelstatus',
    'class' => 'custom-select',
]);
echo html_writer::end_div();

echo html_writer::start_div('col-md-4 mb-3');
echo html_writer::label(get_string('sortorder', 'local_ulms_dashboard'), 'id_levelsort');
echo html_writer::empty_tag('input', [
    'type' => 'number',
    'name' => 'sortorder',
    'id' => 'id_levelsort',
    'class' => 'form-control',
    'step' => '1',
    'min' => '0',
    'value' => (string)$formdefaults['sortorder'],
]);
echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::start_div('row');
echo html_writer::start_div('col-md-12 mb-3');
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'name' => 'savelevel',
    'value' => $editrecord
        ? get_string('updatelevel', 'local_ulms_dashboard')
        : get_string('savelevel', 'local_ulms_dashboard'),
    'class' => 'btn btn-primary',
]);
if ($editrecord) {
    echo ' ';
    echo html_writer::link($contexturlstring, get_string('canceleditmapping', 'local_ulms_academics'), [
        'class' => 'btn btn-outline-secondary',
    ]);
}
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('ulms-panel');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', get_string('levelsheading', 'local_ulms_dashboard', (object)['total' => $total]), ['class' => 'ulms-panel__title']);
echo html_writer::tag(
    'p',
    get_string('levelssubtitle', 'local_ulms_dashboard'),
    ['class' => 'ulms-panel__subtitle']
);
echo html_writer::end_div();
echo html_writer::start_div('ulms-kpi-grid');
$kpis = [
    ['label' => get_string('all'), 'value' => $statusstats['all']],
    ['label' => get_string('active'), 'value' => $statusstats['active']],
    ['label' => get_string('inactive'), 'value' => $statusstats['inactive']],
];
foreach ($kpis as $kpi) {
    echo html_writer::start_div('ulms-kpi-card');
    echo html_writer::tag('div', format_string($kpi['label']), ['class' => 'ulms-kpi-card__label']);
    echo html_writer::tag('div', format_string((string)$kpi['value']), ['class' => 'ulms-kpi-card__value']);
    echo html_writer::end_div();
}
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => $contexturlstring,
    'class' => 'form-inline mb-4',
]);
echo html_writer::start_div('row');
echo html_writer::start_div('col-md-4 mb-2');
echo html_writer::empty_tag('input', [
    'type' => 'search',
    'name' => 'search',
    'class' => 'form-control',
    'placeholder' => get_string('searchlevelscodeorname', 'local_ulms_dashboard'),
    'value' => s($search),
    'aria-label' => get_string('search'),
]);
echo html_writer::end_div();
echo html_writer::start_div('col-md-2 mb-2');
echo html_writer::select($statusfilteroptions, 'status', $status, false, ['class' => 'custom-select w-100']);
echo html_writer::end_div();
echo html_writer::start_div('col-md-2 mb-2');
echo html_writer::select($perpageoptions, 'perpage', $perpage, false, ['class' => 'custom-select w-100']);
echo html_writer::end_div();
echo html_writer::start_div('col-md-3 mb-2');
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('applymappingfilters', 'local_ulms_academics'),
    'class' => 'btn btn-outline-primary w-100',
]);
echo html_writer::end_div();
echo html_writer::start_div('col-md-1 mb-2 text-right');
if ($search !== '' || $status !== '' || $page > 0 || $perpage != 20) {
    echo html_writer::link($contexturlstring, get_string('clearfilters', 'local_ulms_academics'), [
        'class' => 'btn btn-outline-secondary w-100',
    ]);
}
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_tag('form');

$table = new html_table();
$table->attributes['class'] = 'generaltable';
$sortstateparams = array_merge($stateparams, ['page' => 0]);
$sortcol = static function(string $col, string $label) use ($sort, $dir, $contexturl, $sortstateparams) {
    $newdir = ($sort === $col && $dir === 'ASC') ? 'DESC' : 'ASC';
    $url = new moodle_url($contexturl, array_merge($sortstateparams, ['sort' => $col, 'dir' => $newdir]));
    $arrow = $sort === $col ? ($dir === 'ASC' ? ' ↑' : ' ↓') : '';
    return html_writer::link($url, format_string($label) . $arrow);
};
$table->head = [
    $sortcol('code', get_string('code', 'local_ulms_academics')),
    $sortcol('name', get_string('name')),
    $sortcol('sortorder', get_string('sortorder', 'local_ulms_dashboard')),
    $sortcol('status', get_string('status', 'local_ulms_academics')),
    get_string('actions'),
];

$currentrow = 0;
$startfrom = $page * $perpage + 1;
$endat = min($page * $perpage + $perpage, $total);

foreach ($rows as $level) {
    $editurl = new moodle_url($contexturl, array_merge($stateparams, ['editid' => (int)$level->id]));
    $toggleurl = new moodle_url($contexturl, array_merge($stateparams, ['action' => 'toggle', 'toggleid' => (int)$level->id, 'sesskey' => sesskey()]));
    $delbase = new moodle_url($contexturl, array_merge($stateparams, [
        'action' => 'delete',
        'deleteid' => (int)$level->id,
        'sesskey' => sesskey(),
        'confirm' => 1,
    ]));
    $deleteurl = new moodle_url('/local/ulms_dashboard/academics_levels.php', $delbase->params());

    $actions = '';
    $actions .= html_writer::link($editurl, get_string('edit'), ['class' => 'btn btn-sm btn-outline-secondary mr-2']);
    $actions .= html_writer::link($toggleurl,
        ((string)$level->status === 'active')
            ? get_string('deactivate', 'local_ulms_dashboard')
            : get_string('activate', 'local_ulms_dashboard'),
        ['class' => 'btn btn-sm btn-outline-primary mr-2']
    );
    $actions .= html_writer::link(
        $delbase->out(false),
        get_string('delete'),
        [
            'class' => 'btn btn-sm btn-outline-danger',
            'data-confirm' => get_string('confirmdeleteentity', 'local_ulms_academics', s((string)$level->name)),
        ]
    );

    $statusclass = ((string)$level->status === 'active')
        ? 'ulms-tag ulms-tag--success'
        : 'ulms-tag ulms-tag--muted';
    $statushtml = html_writer::tag('span',
        get_string(($level->status === 'active') ? 'active' : 'inactive'),
        ['class' => $statusclass]
    );

    $table->data[] = [
        format_string($level->code ?? ''),
        format_string($level->name ?? ''),
        (string)($level->sortorder ?? 0),
        $statushtml,
        $actions,
    ];
    $currentrow++;
}

if (empty($table->data)) {
    echo html_writer::tag('div', get_string('nolevels', 'local_ulms_dashboard'), [
        'class' => 'alert alert-info text-center my-4',
    ]);
} else {
    echo html_writer::table($table);

    $basepagination = new moodle_url($contexturl, array_merge($stateparams, ['page' => 0]));
    echo $OUTPUT->paging_bar($total, $page, $perpage, $basepagination, 'page');
    echo html_writer::tag('small',
        get_string('showingxofyresults', 'local_ulms_dashboard', (object)[
            'start' => $startfrom,
            'end' => $endat,
            'total' => $total,
        ]),
        ['class' => 'text-muted mt-2 d-block']
    );
}

echo html_writer::script("
document.querySelectorAll('[data-confirm]').forEach(function(el){
  el.addEventListener('click', function(e){
    if (!confirm(el.getAttribute('data-confirm'))) { e.preventDefault(); }
  });
});
");

echo html_writer::end_div();
echo html_writer::end_div();

local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
