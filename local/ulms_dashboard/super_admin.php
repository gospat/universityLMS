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
global $PAGE, $OUTPUT, $USER;

require_login();

$routingservice = new \local_ulms_auth\local\service\landing_page_service();
if (!is_siteadmin($USER)) {
    $routingservice->redirect_to_current_user_dashboard(
        get_string('alreadyauthenticatedredirect', 'local_ulms_auth'),
        \core\output\notification::NOTIFY_WARNING,
        302
    );
}

$context = \context::instance_by_id(\context_system::instance()->id);
$view = optional_param('view', 'dashboard', PARAM_ALPHA);
$allowedviews = ['dashboard', 'administrators', 'users', 'institution', 'health', 'integrations', 'security', 'auditlogs', 'reports', 'settings'];
if (!in_array($view, $allowedviews, true)) {
    $view = 'dashboard';
}

$routekey = match ($view) {
    'administrators' => 'superadmin.administrators',
    'users' => 'superadmin.users',
    'institution' => 'superadmin.institution',
    'health' => 'superadmin.health',
    'integrations' => 'superadmin.integrations',
    'security' => 'superadmin.security',
    'auditlogs' => 'superadmin.auditlogs',
    'reports' => 'superadmin.reports',
    'settings' => 'superadmin.settings',
    default => 'superadmin.dashboard',
};
$routingservice->maybe_redirect_legacy_request($routekey);
$url = $routingservice->get_url_for_route($routekey);
local_ulms_dashboard_prepare_page($context, $url, get_string('superadminportalshelltitle', 'local_ulms_dashboard'));

$portalservice = new \local_ulms_dashboard\local\service\super_admin_portal_service();
$overviewservice = new \local_ulms_dashboard\local\service\portal_overview_service();
$data = $overviewservice->get_super_admin_overview_data($view);

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
