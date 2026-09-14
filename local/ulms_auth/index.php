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
require_once(__DIR__ . '/lib.php');

if (method_exists(\theme_ulms_university\output\core_renderer::class, 'inject_http_security_headers')) {
    \theme_ulms_university\output\core_renderer::inject_http_security_headers();
}

global $OUTPUT, $PAGE;

$service = new \local_ulms_auth\local\service\landing_page_service();
$service->maybe_redirect_legacy_request('public.landing');
if (isloggedin() && !isguestuser()) {
    $service->redirect_to_current_user_dashboard(
        get_string('alreadyauthenticatedredirect', 'local_ulms_auth'),
        \core\output\notification::NOTIFY_INFO,
        302
    );
}

$PAGE->set_context(\context_system::instance());
$PAGE->set_url($service->get_public_portal_landing_url());
$PAGE->set_pagelayout('login');
$PAGE->set_title(get_string('portallandingtitle', 'local_ulms_auth'));
$PAGE->set_heading(get_string('portallandingtitle', 'local_ulms_auth'));
$PAGE->add_body_class('ulms-role-login-page');
$PAGE->add_body_class('ulms-role-landing-page');
local_ulms_auth_require_shared_ui($PAGE);

$portals = $service->get_portal_cards();
$supportitems = [
    get_string('portalsecurityitemportal', 'local_ulms_auth'),
    get_string('portalsecurityitempassword', 'local_ulms_auth'),
    get_string('portalsecurityitemrecovery', 'local_ulms_auth'),
];

echo $OUTPUT->header();
echo html_writer::start_div('ulms-role-login');
echo html_writer::start_div('ulms-role-login__hero');
echo html_writer::tag('p', get_string('portallandingeyebrow', 'local_ulms_auth'), ['class' => 'ulms-role-login__eyebrow']);
echo html_writer::tag('h1', get_string('portallandingtitle', 'local_ulms_auth'), ['class' => 'ulms-role-login__title']);
echo html_writer::tag('p', get_string('portallandingdesc', 'local_ulms_auth'), ['class' => 'ulms-role-login__meta']);
echo html_writer::tag('p', get_string('portallandingnote', 'local_ulms_auth'), ['class' => 'ulms-role-login__note']);
echo html_writer::end_div();

echo html_writer::start_div('ulms-role-login__layout');
echo html_writer::start_div('ulms-panel ulms-role-login__panel ulms-role-login__panel--primary');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', get_string('portallandingpaneltitle', 'local_ulms_auth'), ['class' => 'ulms-panel__title']);
echo html_writer::tag('p', get_string('portallandingpaneldesc', 'local_ulms_auth'), ['class' => 'ulms-panel__subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');
echo local_ulms_auth_render_portal_cards($portals, 'h2');
echo html_writer::div(
    html_writer::link(
        $service->get_url_for_route('public.passwordreset'),
        get_string('portallandingpasswordresetcta', 'local_ulms_auth'),
        ['class' => 'ulms-role-login__secondary-link']
    ),
    'ulms-role-login__actions'
);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('ulms-panel ulms-role-login__panel ulms-role-login__panel--support ulms-panel--soft');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', get_string('portallandinghelpheading', 'local_ulms_auth'), ['class' => 'ulms-panel__title']);
echo html_writer::tag('p', get_string('portallandinghelpdesc', 'local_ulms_auth'), ['class' => 'ulms-panel__subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');
echo local_ulms_auth_render_info_list($supportitems);
echo html_writer::div(
    html_writer::link(
        $service->get_url_for_route('public.passwordreset'),
        get_string('passwordresetsubmit', 'local_ulms_auth'),
        ['class' => 'btn btn-outline-primary']
    ),
    'ulms-role-login__actions'
);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
echo $OUTPUT->footer();
