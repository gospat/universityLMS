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
$dashboardservice->enforce_dashboard_access('student');
$routingservice = new \local_ulms_auth\local\service\landing_page_service();
$routingservice->maybe_redirect_legacy_request('student.courses');

$context = \context::instance_by_id(\context_system::instance()->id);
require_capability('local/ulms_dashboard:viewstudentdashboard', $context);

$url = $routingservice->get_url_for_route('student.courses');
$title = get_string('studentcoursespage', 'local_ulms_dashboard');
local_ulms_dashboard_prepare_page($context, $url, $title);

try {
    $service = new \local_ulms_dashboard\local\service\student_portal_service();
    $data = $service->get_student_courses_page_data();
} catch (\Throwable $exception) {
    local_ulms_dashboard_log_operational_error($exception, 'student_courses', [
        'page' => 'student_courses',
    ]);
    local_ulms_dashboard_render_operational_error(
        $title,
        'We could not load your course spaces right now. Please refresh the page or return to your dashboard.',
        $routingservice->get_url_for_route('student.dashboard')
    );
    return;
}

echo $OUTPUT->header();
$headercontext = $service->get_header_context_for_section('courses');
echo local_ulms_dashboard_render_page_header($headercontext);
local_ulms_dashboard_start_shell_wrap();
echo local_ulms_dashboard_render_summary_cards($data['summarycards'] ?? []);

$courseitems = [];
if ($data['hascourses']) {
    foreach ($data['courses'] as $course) {
        $courseitems[] = [
            'title' => $course['fullname'],
            'meta' => $course['meta'],
            'url' => $course['courseurl'],
            'footer' => get_string('studentcoursegradescta', 'local_ulms_dashboard'),
        ];
    }
}

echo local_ulms_dashboard_render_panel([
    'style' => 'cards',
    'title' => get_string('studentcoursespage', 'local_ulms_dashboard'),
    'items' => $courseitems,
    'emptytitle' => get_string('studentcoursesempty', 'local_ulms_dashboard'),
    'emptydesc' => get_string('studentcoursesemptydesc', 'local_ulms_dashboard'),
]);

local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
