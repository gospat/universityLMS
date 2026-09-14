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
require_once($CFG->dirroot . '/local/ulms_dashboard/lib.php');

global $PAGE, $OUTPUT;

$routingservice = new \local_ulms_auth\local\service\landing_page_service();
$routingservice->maybe_redirect_legacy_request('management.academics');

require_login();

$context = \context::instance_by_id(context_system::instance()->id);
require_capability('local/ulms_academics:manageacademics', $context);

$PAGE->set_context($context);
$PAGE->set_url($routingservice->get_url_for_route('management.academics'));
$PAGE->set_title(get_string('pluginname', 'local_ulms_academics'));
$PAGE->set_heading(get_string('pluginname', 'local_ulms_academics'));
$PAGE->set_pagelayout('ulmsdashboard');

$service = new \local_ulms_academics\local\service\academic_structure_service();
$entities = $service->get_supported_entities();

echo $OUTPUT->header();
echo html_writer::start_div('ulms-page');
$headercontext = local_ulms_dashboard_get_management_header_context('academics');
$headercontext['showtitle'] = false;
echo $OUTPUT->render_from_template('theme_ulms_university/student_portal_context_header', $headercontext);
echo html_writer::start_div('ulms-page-header');
echo html_writer::tag('div', get_string('pluginname', 'local_ulms_academics'), ['class' => 'ulms-page-header__eyebrow']);
echo html_writer::start_div('ulms-page-header__content');
echo html_writer::start_div('ulms-page-header__main');
echo html_writer::tag('h1', get_string('academicstructuresetup', 'local_ulms_academics'), ['class' => 'ulms-page-header__title']);
echo html_writer::tag('p', get_string('academicstructuresetupdesc', 'local_ulms_academics'), ['class' => 'ulms-page-header__meta']);
echo html_writer::end_div();
echo html_writer::div(
    html_writer::link($routingservice->get_url_for_route('management.academicsreports'), get_string('managereports', 'local_ulms_academics'),
        ['class' => 'btn btn-light']) .
    html_writer::link($routingservice->get_url_for_route('management.academicsimport'), get_string('manageimport', 'local_ulms_academics'),
        ['class' => 'btn btn-light']) .
    html_writer::link($routingservice->get_url_for_route('management.academicsmappings'),
        get_string('managecoursemappings', 'local_ulms_academics'), ['class' => 'btn btn-light']),
    'ulms-page-header__actions'
);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('ulms-panel ulms-panel--soft');
echo html_writer::start_div('ulms-panel__body');
echo html_writer::div(
    html_writer::tag('h2', get_string('academictoolsoverview', 'local_ulms_academics'), ['class' => 'ulms-empty-state__title']) .
    html_writer::tag('p', get_string('importseedhelp', 'local_ulms_academics'), ['class' => 'ulms-empty-state__meta']),
    'ulms-empty-state',
    ['role' => 'status']
);
echo html_writer::end_div();
echo html_writer::end_div();

$items = [];
foreach ($entities as $entity) {
    $url = $routingservice->get_url_for_route('management.academicsmanage', ['entity' => $entity]);
    $items[] = html_writer::link(
        $url,
        html_writer::tag('div', $service->get_entity_label($entity), ['class' => 'ulms-action-card__title']) .
        html_writer::tag('div', get_string('manageentitydesc', 'local_ulms_academics', $service->get_entity_label($entity)),
            ['class' => 'ulms-action-card__meta']),
        ['class' => 'ulms-action-card']
    );
}

echo html_writer::start_div('ulms-layout-grid');
echo html_writer::start_div('ulms-panel');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', get_string('manageacademicstructure', 'local_ulms_academics'), ['class' => 'ulms-panel__title']);
echo html_writer::tag('p', get_string('manageacademicstructuredesc', 'local_ulms_academics'), ['class' => 'ulms-panel__subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');
echo html_writer::div(implode('', $items), 'ulms-action-grid');
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
echo $OUTPUT->footer();
