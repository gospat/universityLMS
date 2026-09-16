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

global $PAGE, $OUTPUT;

require_login();

$routingservice = new \local_ulms_auth\local\service\landing_page_service();
$routingservice->maybe_redirect_legacy_request('student.dashboard');

$service = new \local_ulms_dashboard\local\service\dashboard_service();
$portalservice = new \local_ulms_dashboard\local\service\student_portal_service();
$service->enforce_dashboard_access('student');

$context = \context::instance_by_id(context_system::instance()->id);
require_capability('local/ulms_dashboard:viewstudentdashboard', $context);

$url = $routingservice->get_url_for_route('student.dashboard');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('studentdashboard', 'local_ulms_dashboard'));
$PAGE->set_heading(get_string('studentdashboard', 'local_ulms_dashboard'));
$PAGE->set_pagelayout('ulmsdashboard');

$data = $service->get_student_dashboard_data();

echo $OUTPUT->header();

echo local_ulms_dashboard_render_page_header([
    'eyebrow' => 'STUDENT PORTAL',
    'title' => format_string($data['focusheading']),
    'meta' => format_string($data['focusintro']),
    'actions' => [
        [
            'label' => get_string('mycourseslink', 'local_ulms_dashboard'),
            'url' => $routingservice->get_url_for_route('student.courses'),
            'class' => 'btn btn-primary',
        ],
        [
            'label' => get_string('studentnavassignments', 'local_ulms_dashboard'),
            'url' => $routingservice->get_url_for_route('student.assignments'),
            'class' => 'btn btn-outline-secondary',
        ],
    ],
    'navitems' => [
        ['label' => s($data['currentperiod']), 'active' => true],
        ['label' => format_string(get_string('studentdashboardcoursecountmeta', 'local_ulms_dashboard', (int)$data['summary'][0]['value']))],
        ['label' => format_string(get_string('studentdashboardprogressmeta', 'local_ulms_dashboard', $data['completion']['percent'] . '%'))],
    ],
]);

local_ulms_dashboard_start_shell_wrap();

if (!empty($data['summary'])) {
    $normalisedsummary = [];
    foreach ($data['summary'] as $item) {
        $normalisedsummary[] = [
            'label' => (string)($item['label'] ?? ''),
            'value' => (string)($item['value'] ?? ''),
            'description' => get_string('studentdashboardkpidetail', 'local_ulms_dashboard'),
        ];
    }
    echo local_ulms_dashboard_render_summary_cards($normalisedsummary);
}

$prof = $data['academic_profile'] ?? ['available' => false];
$pills = $data['course_pills'] ?? [];
$profrendered = '';
if (!empty($prof['available'])) {
    $rows = [];
    if (!empty($prof['facultyname'])) {
        $rows[] = ['label' => get_string('profile_faculty', 'local_ulms_dashboard'), 'value' => s($prof['facultyname'])];
    }
    if (!empty($prof['departmentname'])) {
        $rows[] = ['label' => get_string('profile_department', 'local_ulms_dashboard'), 'value' => s($prof['departmentname'])];
    }
    if (!empty($prof['programmename'])) {
        $display = trim(s($prof['programme_code'] ? ($prof['programme_code'] . ' — ') : '') . s($prof['programmename']));
        $rows[] = ['label' => get_string('profile_programme', 'local_ulms_dashboard'), 'value' => $display];
    }
    if (!empty($prof['levelname']) || !empty($prof['levelcode'])) {
        $leveldisplay = trim(s($prof['levelcode'] ? ($prof['levelcode'] . ' — ') : '') . s($prof['levelname']));
        $rows[] = ['label' => get_string('profile_student_level', 'local_ulms_dashboard'), 'value' => $leveldisplay];
    }
    $rowhtml = '';
    foreach ($rows as $r) {
        $rowhtml .= html_writer::start_div('form-group row')
            . html_writer::tag('div', html_writer::tag('strong', $r['label']), ['class' => 'col-md-4 col-form-label text-right'])
            . html_writer::tag('div', $r['value'], ['class' => 'col-md-8 form-control-plaintext'])
            . html_writer::end_div();
    }
    $pillshtml = '';
    if (count($pills) > 0) {
        $badges = [];
        foreach ($pills as $p) {
            $badges[] = html_writer::link(
                $p['url'],
                html_writer::tag('span', s($p['shortname']), ['class' => 'badge badge-' . ($p['badge'] ?? 'primary') . ' px-3 py-2 mx-1']),
                ['class' => 'text-decoration-none']
            );
        }
        $pillshtml = html_writer::tag('h3', get_string('profile_enrolled_courses_heading', 'local_ulms_dashboard'), ['class' => 'mt-4 mb-2 h5'])
            . html_writer::div(implode('', $badges), 'p-3 bg-light rounded');
    }
    $profrendered = html_writer::start_div('ulms-panel mb-4')
        . html_writer::start_div('ulms-panel__header')
        . html_writer::tag('h2', get_string('profile_student_heading', 'local_ulms_dashboard'), ['class' => 'ulms-panel__title'])
        . html_writer::tag('p', get_string('profile_student_subtitle', 'local_ulms_dashboard'), ['class' => 'ulms-panel__subtitle'])
        . html_writer::end_div()
        . html_writer::start_div('ulms-panel__body')
        . html_writer::start_div('container-fluid px-0')
        . $rowhtml
        . html_writer::end_div()
        . $pillshtml
        . html_writer::end_div()
        . html_writer::end_div();
}
echo $profrendered;

echo html_writer::start_div('ulms-dashboard-primary');

echo html_writer::start_div('ulms-panel ulms-dashboard-primary__main');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', format_string($data['coursesheading']), ['class' => 'ulms-panel__title']);
echo html_writer::tag('p', get_string('studentdashboardcoursesintro', 'local_ulms_dashboard'), ['class' => 'ulms-panel__subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');
if (!empty($data['courses'])) {
    $courselinks = [];
    foreach ($data['courses'] as $course) {
        $courselinks[] = html_writer::tag(
            'li',
            html_writer::div(html_writer::link($course['url'], format_string($course['fullname']), ['class' => 'ulms-list__title'])) .
            html_writer::div(get_string('studentcoursecontinuehint', 'local_ulms_dashboard'), 'ulms-list__meta'),
            ['class' => 'ulms-list__item']
        );
    }
    echo html_writer::tag('ul', implode('', $courselinks), ['class' => 'ulms-list']);
} else {
    echo html_writer::div(
        html_writer::tag('h3', get_string('studentcoursespage', 'local_ulms_dashboard'), ['class' => 'ulms-empty-state__title']) .
        html_writer::tag('p', get_string('studentcoursesempty', 'local_ulms_dashboard'), ['class' => 'ulms-empty-state__meta']),
        'ulms-empty-state'
    );
}
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('ulms-panel ulms-dashboard-primary__aside');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', get_string('deadlinesheading', 'local_ulms_dashboard'), ['class' => 'ulms-panel__title']);
echo html_writer::tag('p', get_string('studentdashboarddeadlinesintro', 'local_ulms_dashboard'), ['class' => 'ulms-panel__subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');
if (!empty($data['deadlines'])) {
    $deadlineitems = [];
    foreach ($data['deadlines'] as $deadline) {
        $deadlineitems[] = html_writer::tag(
            'li',
            html_writer::div(html_writer::link($deadline['url'], format_string($deadline['title']), ['class' => 'ulms-list__title'])) .
            html_writer::div(format_string($deadline['subtitle']) . ' - ' . s($deadline['time']), 'ulms-list__meta'),
            ['class' => 'ulms-list__item']
        );
    }
    echo html_writer::tag('ul', implode('', $deadlineitems), ['class' => 'ulms-list']);
} else {
    echo html_writer::div(
        html_writer::tag('h3', get_string('studentdashboardnodeadlines', 'local_ulms_dashboard'), ['class' => 'ulms-empty-state__title']) .
        html_writer::tag('p', get_string('studentdashboardnodeadlinesdesc', 'local_ulms_dashboard'), ['class' => 'ulms-empty-state__meta']),
        'ulms-empty-state'
    );
}
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::start_div('ulms-dashboard-sections');

if (!empty($data['links'])) {
    echo html_writer::start_div('ulms-panel ulms-panel--soft');
    echo html_writer::start_div('ulms-panel__header');
    echo html_writer::start_div();
    echo html_writer::tag('h2', format_string($data['quickactionsheading']), ['class' => 'ulms-panel__title']);
    echo html_writer::tag('p', get_string('studentdashboardquickactionsintro', 'local_ulms_dashboard'), ['class' => 'ulms-panel__subtitle']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::start_div('ulms-panel__body');
    $cards = [];
    foreach ($data['links'] as $link) {
        $cards[] = html_writer::link(
            $link['url'],
            html_writer::tag('div', format_string($link['label']), ['class' => 'ulms-action-card__title']) .
            html_writer::tag('div', format_string($link['description']), ['class' => 'ulms-action-card__meta']),
            ['class' => 'ulms-action-card']
        );
    }
    echo html_writer::div(implode('', $cards), 'ulms-action-grid');
    echo html_writer::end_div();
    echo html_writer::end_div();
}

if (!empty($data['events'])) {
    echo html_writer::start_div('ulms-panel');
    echo html_writer::start_div('ulms-panel__header');
    echo html_writer::start_div();
    echo html_writer::tag('h2', format_string($data['eventsheading']), ['class' => 'ulms-panel__title']);
    echo html_writer::tag('p', get_string('studentdashboardeventsintro', 'local_ulms_dashboard'), ['class' => 'ulms-panel__subtitle']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::start_div('ulms-panel__body');
    $eventitems = [];
    foreach ($data['events'] as $event) {
        $eventitems[] = html_writer::tag(
            'li',
            html_writer::div(html_writer::link($event['url'], format_string($event['title']), ['class' => 'ulms-list__title'])) .
            html_writer::div(format_string($event['subtitle']) . ' - ' . s($event['time']), 'ulms-list__meta'),
            ['class' => 'ulms-list__item']
        );
    }
    echo html_writer::tag('ul', implode('', $eventitems), ['class' => 'ulms-list']);
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo html_writer::end_div();

local_ulms_dashboard_end_shell_wrap();

echo $OUTPUT->footer();
