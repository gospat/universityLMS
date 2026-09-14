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

defined('MOODLE_INTERNAL') || die();

if (isset($ADMIN)) {
    global $ADMIN, $hassiteconfig;
    $routingservice = new \local_ulms_auth\local\service\landing_page_service();

    if ($hassiteconfig) {
        $settings = new admin_settingpage(
            'local_ulms_dashboard',
            get_string('pluginname', 'local_ulms_dashboard')
        );

        $settings->add(new admin_setting_heading(
            'local_ulms_dashboard/generalheading',
            get_string('pluginname', 'local_ulms_dashboard'),
            get_string('adminsettingsnotice', 'local_ulms_dashboard')
        ));

        $ADMIN->add('localplugins', $settings);
        $ADMIN->add('localplugins', new admin_externalpage(
            'local_ulms_dashboard_admin_settings',
            get_string('adminsettingsheading', 'local_ulms_dashboard'),
            $routingservice->get_url_for_route('management.settings'),
            'moodle/site:config'
        ));
    }

    return;
}

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/ulms_dashboard/lib.php');

/** @var moodle_page $PAGE */
/** @var core_renderer $OUTPUT */
/** @var stdClass $USER */

global $PAGE, $OUTPUT;

require_login();

$dashboardservice = new \local_ulms_dashboard\local\service\dashboard_service();
$dashboardservice->enforce_admin_feature_access();
$dashboardservice->require_admin_permissions();

$routingservice = new \local_ulms_auth\local\service\landing_page_service();
$routingservice->maybe_redirect_legacy_request('management.settings');
$context = \context::instance_by_id(\context_system::instance()->id);
$url = $routingservice->get_url_for_route('management.settings');

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('adminsettingsheading', 'local_ulms_dashboard'));
$PAGE->set_heading(get_string('adminsettingsheading', 'local_ulms_dashboard'));
$PAGE->set_pagelayout('ulmsdashboard');

$cards = [
    [
        'title' => get_string('adminsettingscorecta', 'local_ulms_dashboard'),
        'meta' => get_string('adminsettingscorectadesc', 'local_ulms_dashboard'),
        'url' => new \moodle_url('/admin/search.php'),
        'footer' => get_string('adminsystemsettingslink', 'local_ulms_dashboard'),
    ],
    [
        'title' => get_string('adminsettingsuserscta', 'local_ulms_dashboard'),
        'meta' => get_string('adminsettingsusersctadesc', 'local_ulms_dashboard'),
        'url' => new \moodle_url('/admin/category.php', ['category' => 'users']),
        'footer' => get_string('adminsettingsuserscta', 'local_ulms_dashboard'),
    ],
    [
        'title' => get_string('adminsettingsauthcta', 'local_ulms_dashboard'),
        'meta' => get_string('adminsettingsauthctadesc', 'local_ulms_dashboard'),
        'url' => new \moodle_url('/admin/settings.php', ['section' => 'manageauths']),
        'footer' => get_string('adminsettingsauthcta', 'local_ulms_dashboard'),
    ],
    [
        'title' => get_string('adminsettingspluginscta', 'local_ulms_dashboard'),
        'meta' => get_string('adminsettingspluginsctadesc', 'local_ulms_dashboard'),
        'url' => new \moodle_url('/admin/category.php', ['category' => 'modsettings']),
        'footer' => get_string('adminsettingspluginscta', 'local_ulms_dashboard'),
    ],
    [
        'title' => get_string('adminsettingsservercta', 'local_ulms_dashboard'),
        'meta' => get_string('adminsettingsserverctadesc', 'local_ulms_dashboard'),
        'url' => new \moodle_url('/admin/category.php', ['category' => 'server']),
        'footer' => get_string('adminsettingsservercta', 'local_ulms_dashboard'),
    ],
];

echo $OUTPUT->header();
$adminservice = new \local_ulms_dashboard\local\service\admin_portal_service();
$headercontext = $adminservice->get_header_context_for_section('settings');

$headercontext['actions'][] = [
    'label' => get_string('adminsettingscorecta', 'local_ulms_dashboard'),
    'url' => new \moodle_url('/admin/search.php'),
    'class' => 'btn btn-primary',
];

echo local_ulms_dashboard_render_page_header([
    'eyebrow' => $headercontext['eyebrow'] ?? get_string('adminportaleyebrow', 'local_ulms_dashboard'),
    'title' => $headercontext['title'] ?? get_string('adminsettingsheading', 'local_ulms_dashboard'),
    'meta' => $headercontext['meta'] ?? get_string('adminsettingsdesc', 'local_ulms_dashboard'),
    'actions' => $headercontext['actions'] ?? [],
    'navitems' => $headercontext['navitems'] ?? [],
]);

echo html_writer::start_div('ulms-panel ulms-panel--soft');
echo html_writer::start_div('ulms-panel__body');
echo html_writer::div(
    html_writer::tag('h2', get_string('adminsettingsintro', 'local_ulms_dashboard'), ['class' => 'ulms-empty-state__title']) .
    html_writer::tag('p', get_string('adminsettingsnotice', 'local_ulms_dashboard'), ['class' => 'ulms-empty-state__meta']),
    'ulms-empty-state',
    ['role' => 'status']
);
echo html_writer::end_div();
echo html_writer::end_div();

local_ulms_dashboard_start_shell_wrap();

echo local_ulms_dashboard_render_summary_cards([]);

echo local_ulms_dashboard_render_panel([
    'title' => get_string('adminsettingsheading', 'local_ulms_dashboard'),
    'subtitle' => get_string('adminsettingsdesc', 'local_ulms_dashboard'),
    'style' => 'cards',
    'items' => $cards,
]);

local_ulms_dashboard_end_shell_wrap();
echo $OUTPUT->footer();
