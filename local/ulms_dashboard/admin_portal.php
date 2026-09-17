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
$allowedviews = ['courses', 'reports', 'auditlogs', 'schedule', 'attendanceaudit'];
if (!in_array($view, $allowedviews, true)) {
    $view = 'courses';
}

$routingservice = new \local_ulms_auth\local\service\landing_page_service();
$routekey = match ($view) {
    'reports' => 'management.reports',
    'auditlogs' => 'management.auditlogs',
    'schedule' => 'management.academicsschedule',
    'attendanceaudit' => 'management.academicsattendanceaudit',
    default => 'management.courses',
};
$routingservice->maybe_redirect_legacy_request($routekey);
$url = $routingservice->get_url_for_route($routekey);
local_ulms_dashboard_prepare_page($context, $url, get_string('adminportalpagetitle', 'local_ulms_dashboard'));

$portalservice = new \local_ulms_dashboard\local\service\admin_portal_service();
$overviewservice = new \local_ulms_dashboard\local\service\portal_overview_service();
$data = $overviewservice->get_admin_overview_data($view);

echo $OUTPUT->header();
$headercontext = $portalservice->get_header_context_for_section($view);
echo local_ulms_dashboard_render_page_header($headercontext);
local_ulms_dashboard_start_shell_wrap();
echo local_ulms_dashboard_render_summary_cards($data['summarycards'] ?? []);
echo local_ulms_dashboard_render_panel($data['mainpanel'] ?? []);

if (!empty($data['secondarypanels'])) {
    echo html_writer::start_div('ulms-layout-grid');
    foreach ($data['secondarypanels'] as $panel) {
        echo local_ulms_dashboard_render_panel($panel);
    }
    echo html_writer::end_div();
}

local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
