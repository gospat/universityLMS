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
$routingservice->maybe_redirect_legacy_request('student.grades');

$context = \context::instance_by_id(\context_system::instance()->id);
require_capability('local/ulms_dashboard:viewstudentdashboard', $context);

$url = $routingservice->get_url_for_route('student.grades');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('studentgradespage', 'local_ulms_dashboard'));
$PAGE->set_heading(get_string('studentgradespage', 'local_ulms_dashboard'));
$PAGE->set_pagelayout('ulmsdashboard');

$service = new \local_ulms_dashboard\local\service\student_portal_service();
$data = $service->get_student_grades_page_data();

echo $OUTPUT->header();
$headercontext = $service->get_header_context_for_section('grades');
echo local_ulms_dashboard_render_page_header($headercontext);
local_ulms_dashboard_start_shell_wrap();

echo local_ulms_dashboard_render_summary_cards($data['summarycards'] ?? []);

if ($data['hascourses']) {
    foreach ($data['courses'] as $course) {
        $totalpercentlabel = $course['haspercent']
            ? ((string)$course['totalpercent'] . '%')
            : get_string('studentnotgradedlabel', 'local_ulms_dashboard');
        $coursesubtitle = get_string('studentgradecoursetotal', 'local_ulms_dashboard') . ': '
            . $course['totalgrade'] . ' | ' . $totalpercentlabel;

        $assessmentitems = [];
        foreach ($course['assessments'] as $assessment) {
            $assessmentitems[] = [
                'title' => $assessment['name'],
                'meta' => $assessment['meta'] . ' - ' . $assessment['score'] . ' | ' . $assessment['percentlabel'],
            ];
        }
        echo local_ulms_dashboard_render_panel([
            'style' => 'list',
            'title' => $course['fullname'],
            'subtitle' => $coursesubtitle,
            'items' => $assessmentitems,
            'emptytitle' => get_string('studentgradeempty', 'local_ulms_dashboard'),
            'emptydesc' => get_string('studentgradeemptydesc', 'local_ulms_dashboard'),
        ]);
    }
} else {
    echo local_ulms_dashboard_render_panel([
        'style' => 'list',
        'title' => get_string('studentgradespage', 'local_ulms_dashboard'),
        'items' => [],
        'emptytitle' => get_string('studentgradeempty', 'local_ulms_dashboard'),
        'emptydesc' => get_string('studentgradeemptydesc', 'local_ulms_dashboard'),
    ]);
}

local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
