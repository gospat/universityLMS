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

$dashboardservice = new \local_ulms_dashboard\local\service\dashboard_service();
$dashboardservice->enforce_dashboard_access('student');

$context = \context::instance_by_id(\context_system::instance()->id);
require_capability('local/ulms_dashboard:viewstudentdashboard', $context);

$view = optional_param('view', 'catalog', PARAM_ALPHA);
$allowedviews = ['catalog', 'assignments', 'quizzes', 'progress', 'timetable', 'announcements', 'messages', 'profile', 'attendance'];
if (!in_array($view, $allowedviews, true)) {
    $view = 'catalog';
}

$pagetitles = [
    'catalog' => get_string('studentcatalogtitle', 'local_ulms_dashboard'),
    'assignments' => get_string('studentassignmentstitle', 'local_ulms_dashboard'),
    'quizzes' => get_string('studentquizzestitle', 'local_ulms_dashboard'),
    'progress' => get_string('studentprogresstitle', 'local_ulms_dashboard'),
    'timetable' => get_string('studenttimetabletitle', 'local_ulms_dashboard'),
    'announcements' => get_string('studentannouncementstitle', 'local_ulms_dashboard'),
    'messages' => get_string('studentmessagespage', 'local_ulms_dashboard'),
    'profile' => get_string('studentprofiletitle', 'local_ulms_dashboard'),
    'attendance' => get_string('studentattendancetitle', 'local_ulms_dashboard'),
];
$pagetitle = $pagetitles[$view] ?? get_string('studentportalpagetitle', 'local_ulms_dashboard');

$routingservice = new \local_ulms_auth\local\service\landing_page_service();
$routekey = match ($view) {
    'assignments' => 'student.assignments',
    'quizzes' => 'student.quizzes',
    'progress' => 'student.progress',
    'timetable' => 'student.timetable',
    'announcements' => 'student.announcements',
    'messages' => 'student.messages',
    'profile' => 'student.profile',
    'attendance' => 'student.attendance',
    default => 'student.catalog',
};
$routingservice->maybe_redirect_legacy_request($routekey);
$url = $routingservice->get_url_for_route($routekey);
local_ulms_dashboard_prepare_page($context, $url, $pagetitle);

$overviewservice = new \local_ulms_dashboard\local\service\portal_overview_service();

local_ulms_dashboard_render_role_portal_page(
    \local_ulms_dashboard\local\service\student_portal_service::class,
    $view,
    fn() => $overviewservice->get_student_overview_data($view),
    static function (array $h) {
        return [
            'eyebrow' => get_string('studentportaleyebrow', 'local_ulms_dashboard'),
            'title'   => $h['title'] ?? get_string('studentportalpagetitle', 'local_ulms_dashboard'),
            'meta'    => $h['meta'] ?? '',
        ];
    }
);
