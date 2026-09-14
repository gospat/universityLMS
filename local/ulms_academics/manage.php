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
require_once(__DIR__ . '/classes/form/academic_entity_form.php');
require_once(__DIR__ . '/locallib.php');
require_once($CFG->dirroot . '/local/ulms_dashboard/lib.php');

global $PAGE, $OUTPUT;

require_login();

$entity = required_param('entity', PARAM_ALPHAEXT);
$id = optional_param('id', 0, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);
$status = optional_param('status', '', PARAM_ALPHA);
$sort = optional_param('sort', 'name', PARAM_ALPHA);
$dir = optional_param('dir', 'ASC', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 20, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$deleteid = optional_param('deleteid', 0, PARAM_INT);

$service = new \local_ulms_academics\local\service\academic_structure_service();
$routingservice = new \local_ulms_auth\local\service\landing_page_service();

$context = \context::instance_by_id(context_system::instance()->id);
require_capability('local/ulms_academics:manageacademics', $context);

if (!$service->is_supported_entity($entity)) {
    throw new moodle_exception('invalidentity', 'local_ulms_academics');
}

$managestateparams = [
    'entity' => $entity,
    'search' => $search,
    'status' => $status,
    'sort' => $sort,
    'dir' => $dir,
    'page' => $page,
    'perpage' => $perpage,
];
$contextparams = local_ulms_academics_build_manage_context_params($managestateparams);
$formparams = local_ulms_academics_build_manage_form_params($contextparams, $id);
$routeparams = $formparams;
if ($action !== '') {
    $routeparams['action'] = $action;
}
if ($deleteid > 0) {
    $routeparams['deleteid'] = $deleteid;
    $routeparams['sesskey'] = optional_param('sesskey', '', PARAM_RAW);
}
$routingservice->maybe_redirect_legacy_request('management.academicsmanage', $routeparams);

$contexturl = $routingservice->get_url_for_route('management.academicsmanage', $contextparams);
$formurl = $routingservice->get_url_for_route('management.academicsmanage', $formparams);
$contexturlstring = $contexturl->out(false);
$formurlstring = $formurl->out(false);
$PAGE->set_context($context);
$PAGE->set_url($formurl);
$PAGE->set_title($service->get_entity_label($entity));
$PAGE->set_heading($service->get_entity_label($entity));
$PAGE->set_pagelayout('ulmsdashboard');

$record = $id ? $service->get_record_for_entity($entity, $id) : false;
$parentoptions = $service->get_parent_options($entity);
$tabs = $service->get_entity_tabs($entity);

if ($action === 'delete' && $deleteid) {
    require_sesskey();
    $result = $service->delete_entity_record($entity, $deleteid);
    redirect($contexturl, $result['message'], null, $result['success'] ? \core\output\notification::NOTIFY_SUCCESS :
        \core\output\notification::NOTIFY_ERROR);
}

$form = new \local_ulms_academics\form\academic_entity_form(
    $formurlstring,
    [
        'entity' => $entity,
        'parentoptions' => $parentoptions,
    ]
);

if ($record) {
    $data = clone $record;
    if ($entity === 'departments') {
        $data->parentid = $record->facultyid;
    } else if ($entity === 'programmes') {
        $data->parentid = $record->departmentid;
    } else if ($entity === 'semesters') {
        $data->parentid = $record->sessionid;
    }
    $form->set_data($data);
}

if ($form->is_cancelled()) {
    redirect($contexturl);
} else if ($data = $form->get_data()) {
    $payload = new stdClass();
    $payload->id = $data->id ?? 0;
    $payload->code = trim($data->code ?? '');
    $payload->name = trim($data->name ?? '');

    if (property_exists($data, 'status')) {
        $payload->status = $data->status;
    }

    if ($entity === 'departments') {
        $payload->facultyid = (int)($data->parentid ?? 0);
    } else if ($entity === 'programmes') {
        $payload->departmentid = (int)($data->parentid ?? 0);
        $payload->awardtype = trim($data->awardtype ?? '');
        $payload->durationyears = (int)($data->durationyears ?? 4);
    } else if ($entity === 'sessions') {
        $payload->startdate = (int)($data->startdate ?? 0);
        $payload->enddate = (int)($data->enddate ?? 0);
        $payload->iscurrent = !empty($data->iscurrent) ? 1 : 0;
    } else if ($entity === 'semesters') {
        $payload->sessionid = (int)($data->parentid ?? 0);
        $payload->startdate = (int)($data->startdate ?? 0);
        $payload->enddate = (int)($data->enddate ?? 0);
        $payload->iscurrent = !empty($data->iscurrent) ? 1 : 0;
    }

    $service->save_entity_record($entity, $payload);
    redirect($contexturl, get_string('recordsaved', 'local_ulms_academics'));
}

$perpage = in_array($perpage, [10, 20, 50, 100], true) ? $perpage : 20;
$page = max(0, $page);
$limitfrom = $page * $perpage;
$sortoptions = $service->get_sort_options();
$totalrecords = $service->count_filtered_records_for_entity($entity, $search, $status);
$records = $service->get_filtered_records_for_entity($entity, $search, $status, $sort, $dir, $limitfrom, $perpage);
$filterparams = local_ulms_academics_build_manage_context_params($managestateparams);
$pagedfilterparams = $filterparams;
if ($page > 0) {
    $pagedfilterparams['page'] = $page;
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
echo html_writer::tag('h1', $service->get_entity_label($entity), ['class' => 'ulms-page-header__title']);
echo html_writer::tag(
    'p',
    get_string('manageentitydesc', 'local_ulms_academics', $service->get_entity_label($entity)),
    ['class' => 'ulms-page-header__meta']
);
echo html_writer::end_div();
echo html_writer::div(
    html_writer::link(
        $routingservice->get_url_for_route('management.academics'),
        get_string('returntooverview', 'local_ulms_academics'),
        ['class' => 'btn btn-light']
    ),
    'ulms-page-header__actions'
);
echo html_writer::end_div();
echo html_writer::end_div();

if (!empty($tabs)) {
    $tablinks = [];
    foreach ($tabs as $tab) {
        $classes = 'ulms-nav-pill' . ($tab['active'] ? ' ulms-nav-pill--active' : '');
        $tablinks[] = html_writer::link($tab['url'], $tab['label'], ['class' => $classes]);
    }
    echo html_writer::div(implode('', $tablinks), 'ulms-nav-pills');
}

$filterform = html_writer::start_tag('form', [
    'method' => 'get',
    'action' => $routingservice->get_url_for_route('management.academicsmanage', ['entity' => $entity])->out(false),
    'class' => 'ulms-panel',
    'data-ulms-loading-form' => 'true',
]);
$filterform .= html_writer::start_div('ulms-panel__header');
$filterform .= html_writer::start_div();
$filterform .= html_writer::tag('h2', get_string('filterlabel', 'local_ulms_academics'), ['class' => 'ulms-panel__title']);
$filterform .= html_writer::tag(
    'p',
    get_string('filterrecordsdesc', 'local_ulms_academics'),
    ['class' => 'ulms-panel__subtitle']
);
$filterform .= html_writer::end_div();
$filterform .= html_writer::end_div();
$filterform .= html_writer::start_div('ulms-panel__body');
$filterform .= html_writer::start_div('ulms-filter-grid');
$filterform .= html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'entity',
    'value' => $entity,
]);
$filterform .= html_writer::start_div('ulms-filter-field ulms-filter-field--wide');
$filterform .= html_writer::label(get_string('search', 'local_ulms_academics'), 'id_search', false, ['class' => 'mr-2']);
$filterform .= html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'search',
    'id' => 'id_search',
    'value' => $search,
    'class' => 'form-control',
]);
$filterform .= html_writer::end_div();

if ($service->supports_status_filter($entity)) {
    $filterform .= html_writer::start_div('ulms-filter-field');
    $filterform .= html_writer::label(get_string('status', 'local_ulms_academics'), 'id_status', false, ['class' => 'mr-2']);
    $filterform .= html_writer::select(
        [
            '' => get_string('all'),
            'active' => get_string('active', 'local_ulms_academics'),
            'inactive' => get_string('inactive', 'local_ulms_academics'),
        ],
        'status',
        $status,
        false,
        ['id' => 'id_status', 'class' => 'custom-select']
    );
    $filterform .= html_writer::end_div();
}

$filterform .= html_writer::start_div('ulms-filter-field');
$filterform .= html_writer::label(get_string('sortfield', 'local_ulms_academics'), 'id_sort', false, ['class' => 'mr-2']);
$filterform .= html_writer::select(
    $sortoptions,
    'sort',
    $sort,
    false,
    ['id' => 'id_sort', 'class' => 'custom-select']
);
$filterform .= html_writer::end_div();
$filterform .= html_writer::start_div('ulms-filter-field');
$filterform .= html_writer::label(get_string('sortdirection', 'local_ulms_academics'), 'id_dir', false, ['class' => 'mr-2']);
$filterform .= html_writer::select(
    ['ASC' => 'ASC', 'DESC' => 'DESC'],
    'dir',
    strtoupper($dir),
    false,
    ['id' => 'id_dir', 'class' => 'custom-select']
);
$filterform .= html_writer::end_div();
$filterform .= html_writer::start_div('ulms-filter-field');
$filterform .= html_writer::label(get_string('paginationperpage', 'local_ulms_academics'), 'id_perpage', false, ['class' => 'mr-2']);
$filterform .= html_writer::select(
    [10 => '10', 20 => '20', 50 => '50', 100 => '100'],
    'perpage',
    $perpage,
    false,
    ['id' => 'id_perpage', 'class' => 'custom-select']
);
$filterform .= html_writer::end_div();
$filterform .= html_writer::end_div();

$filterform .= html_writer::div(
    html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('filter'),
    'class' => 'btn btn-primary',
    'data-loading-text' => get_string('filter'),
]) .
html_writer::link(
    $routingservice->get_url_for_route('management.academicsmanage', ['entity' => $entity]),
    get_string('clearfilters', 'local_ulms_academics'),
    ['class' => 'btn btn-outline-secondary']
),
    'ulms-filter-actions'
);
$filterform .= html_writer::end_div();
$filterform .= html_writer::end_tag('form');
echo $filterform;

echo html_writer::start_div('ulms-layout-grid ulms-layout-grid--sidebar');
echo html_writer::start_div('ulms-layout-grid');

echo html_writer::start_div('ulms-panel');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', get_string('entityrecords', 'local_ulms_academics'), ['class' => 'ulms-panel__title']);
echo html_writer::tag(
    'p',
    get_string('mappingresultssummary', 'local_ulms_academics', (object)[
        'start' => $totalrecords > 0 ? $limitfrom + 1 : 0,
        'end' => min($limitfrom + count($records), $totalrecords),
        'total' => $totalrecords,
    ]),
    ['class' => 'ulms-panel__subtitle']
);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');

if (!empty($records)) {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable';
    $table->head = array_filter([
        get_string('code', 'local_ulms_academics'),
        get_string('name'),
        $service->get_parent_entity($entity) ? get_string('parentrecord', 'local_ulms_academics') : null,
        $service->get_extra_column_label($entity) ?: null,
        get_string('actions'),
    ]);

    foreach ($records as $item) {
        $editurl = $routingservice->get_url_for_route('management.academicsmanage', [
            'id' => $item->id,
        ] + $pagedfilterparams);
        $deleteurl = $routingservice->get_url_for_route('management.academicsmanage', [
            'entity' => $entity,
            'action' => 'delete',
            'deleteid' => $item->id,
            'sesskey' => sesskey(),
        ] + $pagedfilterparams);
        $row = [
            format_string($item->code),
            format_string($item->name),
        ];

        if ($service->get_parent_entity($entity)) {
            $row[] = format_string($service->get_parent_label_for_record($entity, $item));
        }

        $extracolumn = $service->get_extra_value_for_record($entity, $item);
        if ($extracolumn !== '') {
            $row[] = format_string($extracolumn);
        }

        $actions = [];
        $actions[] = html_writer::link($editurl, get_string('edit'));
        $actions[] = html_writer::link(
            $deleteurl,
            get_string('delete'),
            ['onclick' => "return confirm('" . addslashes_js(get_string('confirmdeleteentity', 'local_ulms_academics',
                format_string($item->name))) . "');"]
        );
        $row[] = implode(' | ', $actions);
        $table->data[] = $row;
    }

    echo html_writer::div(html_writer::table($table), 'ulms-table-wrap');
    echo $OUTPUT->render(new paging_bar(
        $totalrecords,
        $page,
        $perpage,
        $routingservice->get_url_for_route('management.academicsmanage', $filterparams)
    ));
} else {
    echo html_writer::div(
        html_writer::tag('h3', get_string('norecordsfound', 'local_ulms_academics'), ['class' => 'ulms-empty-state__title']) .
        html_writer::tag('p', get_string('manageentitydesc', 'local_ulms_academics', $service->get_entity_label($entity)),
            ['class' => 'ulms-empty-state__meta']),
        'ulms-empty-state',
        ['role' => 'status']
    );
}

echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('ulms-panel ulms-panel--soft');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', $record ? get_string('edit') . ' ' . $service->get_entity_label($entity) : get_string('newrecordheading', 'local_ulms_academics'),
    ['class' => 'ulms-panel__title']);
echo html_writer::tag('p', get_string('manageentitydesc', 'local_ulms_academics', $service->get_entity_label($entity)),
    ['class' => 'ulms-panel__subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');
$form->display();
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
echo $OUTPUT->footer();
