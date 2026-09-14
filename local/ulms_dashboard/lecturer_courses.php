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
global $PAGE, $OUTPUT;

require_login();

$dashboardservice = new \local_ulms_dashboard\local\service\dashboard_service();
$dashboardservice->enforce_dashboard_access('lecturer');
$routingservice = new \local_ulms_auth\local\service\landing_page_service();
$routingservice->maybe_redirect_legacy_request('lecturer.courses');

$context = \context::instance_by_id(\context_system::instance()->id);
require_capability('local/ulms_dashboard:viewlecturerdashboard', $context);

$url = $routingservice->get_url_for_route('lecturer.courses');
$title = get_string('lecturercoursespage', 'local_ulms_dashboard');
local_ulms_dashboard_prepare_page($context, $url, $title);

try {
    $service = new \local_ulms_dashboard\local\service\lecturer_portal_service();
    $data = $service->get_lecturer_courses_page_data();
} catch (\Throwable $exception) {
    local_ulms_dashboard_log_operational_error($exception, 'lecturer_courses', [
        'page' => 'lecturer_courses',
    ]);
    local_ulms_dashboard_render_operational_error(
        $title,
        'We could not load your teaching workspaces right now. Please refresh the page or return to your lecturer dashboard.',
        $routingservice->get_url_for_route('lecturer.dashboard')
    );
    return;
}

echo $OUTPUT->header();
$headercontext = $service->get_header_context_for_section('workspaces');
echo local_ulms_dashboard_render_page_header($headercontext);

local_ulms_dashboard_start_shell_wrap();

echo local_ulms_dashboard_render_summary_cards($data['summarycards'] ?? []);

echo html_writer::start_div('ulms-layout-grid');

$workspaceitems = [];
if ($data['hascourses']) {
    foreach ($data['courses'] as $course) {
        $metaparts = [];
        if (!empty($course['meta'])) {
            $metaparts[] = $course['meta'];
        }
        $quicknavlabels = [
            get_string('lecturernavmaterials', 'local_ulms_dashboard'),
            get_string('lecturernavassignments', 'local_ulms_dashboard'),
            get_string('lecturernavgrades', 'local_ulms_dashboard'),
            get_string('lecturernavparticipants', 'local_ulms_dashboard'),
        ];
        $metaparts[] = implode(' · ', $quicknavlabels);
        $workspaceitems[] = [
            'title' => $course['fullname'],
            'url' => $course['courseurl'],
            'meta' => implode(' | ', $metaparts),
        ];
    }
}
echo local_ulms_dashboard_render_panel([
    'style' => 'list',
    'title' => get_string('lecturernavworkspaces', 'local_ulms_dashboard'),
    'subtitle' => get_string('lecturercoursespagedesc', 'local_ulms_dashboard'),
    'items' => $workspaceitems,
    'emptytitle' => get_string('lecturercoursesempty', 'local_ulms_dashboard'),
    'emptydesc' => get_string('lecturercoursesemptydesc', 'local_ulms_dashboard'),
]);

$queueitems = [];
if ($data['hasqueue']) {
    foreach ($data['queue'] as $item) {
        $queueitems[] = [
            'title' => $item['title'],
            'url' => $item['url'],
            'meta' => $item['subtitle'] . ' - ' . $item['meta'],
        ];
    }
}
echo local_ulms_dashboard_render_panel([
    'style' => 'list',
    'title' => get_string('gradingqueueheading', 'local_ulms_dashboard'),
    'subtitle' => get_string('gradingqueuesubtitle', 'local_ulms_dashboard'),
    'items' => $queueitems,
    'emptytitle' => get_string('gradingqueueemptyheading', 'local_ulms_dashboard'),
    'emptydesc' => get_string('gradingqueueemptydesc', 'local_ulms_dashboard'),
]);

echo html_writer::end_div();

local_ulms_dashboard_end_shell_wrap();

echo $OUTPUT->footer();
