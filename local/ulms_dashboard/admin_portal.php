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
$dashboardservice->enforce_admin_feature_access();
$dashboardservice->require_admin_permissions();

$context = \context::instance_by_id(\context_system::instance()->id);
$view = optional_param('view', 'courses', PARAM_ALPHA);
$allowedviews = ['courses', 'reports', 'auditlogs', 'schedule', 'attendanceaudit', 'lecturers'];
if (!in_array($view, $allowedviews, true)) {
    $view = 'courses';
}

$routingservice = new \local_ulms_auth\local\service\landing_page_service();
$routekey = match ($view) {
    'reports' => 'management.reports',
    'auditlogs' => 'management.auditlogs',
    'schedule' => 'management.academicsschedule',
    'attendanceaudit' => 'management.academicsattendanceaudit',
    'lecturers' => 'management.lecturers',
    default => 'management.courses',
};
$routingservice->maybe_redirect_legacy_request($routekey);
$url = $routingservice->get_url_for_route($routekey);
local_ulms_dashboard_prepare_page($context, $url, get_string('adminportalpagetitle', 'local_ulms_dashboard'));

$overviewservice = new \local_ulms_dashboard\local\service\portal_overview_service();

local_ulms_dashboard_render_role_portal_page(
    \local_ulms_dashboard\local\service\admin_portal_service::class,
    $view,
    fn() => $overviewservice->get_admin_overview_data($view)
);
