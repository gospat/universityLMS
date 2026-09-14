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

require_login();

$context = \context::instance_by_id(context_system::instance()->id);
$service = new \local_ulms_dashboard\local\service\dashboard_service();
$service->enforce_admin_feature_access();
$service->require_admin_permissions();

$days = optional_param('days', 30, PARAM_INT);
$routingservice = new \local_ulms_auth\local\service\landing_page_service();
$routingservice->maybe_redirect_legacy_request('management.analytics', ['days' => $days]);
$url = $routingservice->get_url_for_route('management.analytics', ['days' => $days]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('analyticsdashboard', 'local_ulms_dashboard'));
$PAGE->set_heading(get_string('analyticsdashboard', 'local_ulms_dashboard'));
$PAGE->set_pagelayout('ulmsdashboard');

$data = $service->get_platform_analytics_data($days);

$windowoptions = [];
foreach ($data['windows'] as $window) {
    $windowoptions[$window['value']] = $window['label'];
}

echo $OUTPUT->header();
$adminservice = new \local_ulms_dashboard\local\service\admin_portal_service();
$headercontext = $adminservice->get_header_context_for_section('analytics');

echo local_ulms_dashboard_render_page_header([
    'eyebrow' => $headercontext['eyebrow'] ?? get_string('adminportaleyebrow', 'local_ulms_dashboard'),
    'title' => $headercontext['title'] ?? get_string('analyticsdashboard', 'local_ulms_dashboard'),
    'meta' => $headercontext['meta'] ?? get_string('analyticsdashboarddesc', 'local_ulms_dashboard'),
    'actions' => $headercontext['actions'] ?? [],
    'navitems' => $headercontext['navitems'] ?? [],
]);
local_ulms_dashboard_start_shell_wrap();

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => $routingservice->get_url_for_route('management.analytics'),
    'class' => 'ulms-panel ulms-panel--soft',
    'data-ulms-loading-form' => 'true',
]);
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', get_string('analyticswindow', 'local_ulms_dashboard'), ['class' => 'ulms-panel__title']);
echo html_writer::tag('p', get_string('analyticsdashboarddesc', 'local_ulms_dashboard'), ['class' => 'ulms-panel__subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');
echo html_writer::start_div('ulms-filter-grid');
echo html_writer::start_div('ulms-filter-field');
echo html_writer::label(get_string('analyticswindow', 'local_ulms_dashboard'), 'id_days');

echo html_writer::select(
    $windowoptions,
    'days',
    $data['days'],
    false,
    ['id' => 'id_days', 'class' => 'custom-select']
);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::div(
    html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('applyanalyticsfilter', 'local_ulms_dashboard'),
    'class' => 'btn btn-primary',
    'data-loading-text' => get_string('applyanalyticsfilter', 'local_ulms_dashboard'),
]),
    'ulms-filter-actions'
);
echo html_writer::end_div();
echo html_writer::end_tag('form');

echo local_ulms_dashboard_render_summary_cards($data['cards'] ?? []);

if (!empty($data['engagementchart'])) {
    echo html_writer::start_div('ulms-panel');
    echo html_writer::start_div('ulms-panel__header');
    echo html_writer::start_div();
    echo html_writer::tag('h2', get_string('engagementchartheading', 'local_ulms_dashboard'), ['class' => 'ulms-panel__title']);
    echo html_writer::tag('p', get_string('analyticscompletioncaption', 'local_ulms_dashboard'), ['class' => 'ulms-panel__subtitle']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::start_div('ulms-panel__body');
    echo html_writer::start_div('ulms-progress-stack');

    foreach ($data['engagementchart'] as $item) {
        echo html_writer::start_div('ulms-progress-item');
        echo html_writer::tag(
            'div',
            format_string($item['label']) . ': ' . format_string((string)$item['value']),
            ['class' => 'ulms-progress-item__title']
        );
        echo html_writer::tag('div', format_string($item['caption']), ['class' => 'ulms-progress-item__meta']);
        echo html_writer::start_div('progress');
        echo html_writer::tag('div', '', [
            'class' => 'progress-bar',
            'role' => 'progressbar',
            'style' => 'width: ' . max(0, min(100, (int)$item['percent'])) . '%;',
            'aria-valuenow' => (string)$item['percent'],
            'aria-valuemin' => '0',
            'aria-valuemax' => '100',
        ]);
        echo html_writer::end_div();
        echo html_writer::end_div();
    }

    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
}

$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->head = [get_string('name'), get_string('summaryvalue', 'local_ulms_dashboard')];

foreach ($data['summary'] as $item) {
    $table->data[] = [
        format_string($item['label']),
        format_string((string)$item['value']),
    ];
}

echo html_writer::start_div('ulms-layout-grid ulms-layout-grid--sidebar');

echo html_writer::start_div('ulms-panel');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', get_string('analyticsoverview', 'local_ulms_dashboard'), ['class' => 'ulms-panel__title']);
echo html_writer::tag('p', get_string('analyticsdashboarddesc', 'local_ulms_dashboard'), ['class' => 'ulms-panel__subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');
echo html_writer::div(html_writer::table($table), 'ulms-table-wrap');
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('ulms-layout-grid');

foreach ([
    [
        'heading' => get_string('facultybreakdownheading', 'local_ulms_dashboard'),
        'items' => $data['facultybreakdown'],
        'barclass' => 'progress-bar bg-info',
    ],
    [
        'heading' => get_string('departmentbreakdownheading', 'local_ulms_dashboard'),
        'items' => $data['departmentbreakdown'],
        'barclass' => 'progress-bar bg-success',
    ],
] as $section) {
    echo html_writer::start_div('ulms-panel ulms-panel--soft');
    echo html_writer::start_div('ulms-panel__header');
    echo html_writer::start_div();
    echo html_writer::tag('h2', $section['heading'], ['class' => 'ulms-panel__title']);
    echo html_writer::tag('p', get_string('nobreakdowndata', 'local_ulms_dashboard'), ['class' => 'ulms-panel__subtitle']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::start_div('ulms-panel__body');
    if (!empty($section['items'])) {
        echo html_writer::start_div('ulms-progress-stack');
        foreach ($section['items'] as $item) {
            echo html_writer::start_div('ulms-progress-item');
            echo html_writer::tag(
                'div',
                format_string($item['label']) . ': ' . format_string((string)$item['value']),
                ['class' => 'ulms-progress-item__title']
            );
            echo html_writer::tag('div', format_string($item['subtitle']), ['class' => 'ulms-progress-item__meta']);
            echo html_writer::start_div('progress');
            echo html_writer::tag('div', '', [
                'class' => $section['barclass'],
                'role' => 'progressbar',
                'style' => 'width: ' . max(0, min(100, (int)$item['percent'])) . '%;',
                'aria-valuenow' => (string)$item['percent'],
                'aria-valuemin' => '0',
                'aria-valuemax' => '100',
            ]);
            echo html_writer::end_div();
            echo html_writer::end_div();
        }
        echo html_writer::end_div();
    } else {
        echo html_writer::div(
            html_writer::tag('h3', get_string('nobreakdowndata', 'local_ulms_dashboard'), ['class' => 'ulms-empty-state__title']) .
            html_writer::tag('p', get_string('managecoursemappingsdesc', 'local_ulms_academics'), ['class' => 'ulms-empty-state__meta']),
            'ulms-empty-state',
            ['role' => 'status']
        );
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
