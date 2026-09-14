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
require_once($CFG->dirroot . '/login/lib.php');
require_once($CFG->dirroot . '/login/set_password_form.php');
require_once(__DIR__ . '/lib.php');

if (method_exists(\theme_ulms_university\output\core_renderer::class, 'inject_http_security_headers')) {
    \theme_ulms_university\output\core_renderer::inject_http_security_headers();
}

global $OUTPUT, $PAGE;

$token = optional_param('token', '', PARAM_ALPHANUM);
$service = new \local_ulms_auth\local\service\landing_page_service();

if (isloggedin() && !isguestuser()) {
    $service->redirect_to_current_user_dashboard(
        get_string('alreadyauthenticatedredirect', 'local_ulms_auth'),
        \core\output\notification::NOTIFY_INFO,
        302
    );
}

$url = local_ulms_auth_get_activation_url($token !== '' ? $token : null);
$context = \context::instance_by_id(\context_system::instance()->id);
$title = get_string('activationheading', 'local_ulms_auth');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('login');
$PAGE->set_cacheable(false);
$PAGE->set_title($title);
$PAGE->set_heading($title);
$PAGE->add_body_class('ulms-role-login-page');
$PAGE->add_body_class('ulms-activation-page');
local_ulms_auth_require_shared_ui($PAGE);
$PAGE->requires->js_call_amd('core/togglesensitive', 'init', ['id_password']);
$PAGE->requires->js_call_amd('core/togglesensitive', 'init', ['id_password2']);

$tokenerror = '';
$setpasswordform = null;
$tokenstate = $token !== '' ? local_ulms_auth_get_password_token_state($token) : ['status' => 'invalid', 'user' => null, 'pwresettime' => 0];

if ($tokenstate['status'] === 'valid' && !empty($tokenstate['user'])) {
    $setpasswordform = new \login_set_password_form(null, $tokenstate['user']);
    if ($data = $setpasswordform->get_data()) {
        local_ulms_auth_complete_password_token(
            $tokenstate['user'],
            (string)$data->password,
            !empty($data->logoutothersessions),
            false
        );
        redirect(
            local_ulms_auth_get_unified_sign_in_url(),
            get_string('activationcompletesignin', 'local_ulms_auth'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    $setdata = new \stdClass();
    $setdata->username = $tokenstate['user']->username;
    $setdata->username2 = $tokenstate['user']->username;
    $setdata->token = $tokenstate['user']->token;
    $setpasswordform->set_data($setdata);
} else if ($tokenstate['status'] === 'expired') {
    $tokenerror = get_string('activationtokenexpired', 'local_ulms_auth', (int)floor($tokenstate['pwresettime'] / MINSECS));
} else {
    $tokenerror = get_string('activationtokeninvalid', 'local_ulms_auth');
}

echo $OUTPUT->header();
echo html_writer::start_div('ulms-role-login ulms-activation');
echo html_writer::start_div('ulms-role-login__hero');
echo html_writer::tag('p', get_string('activationeyebrow', 'local_ulms_auth'), ['class' => 'ulms-role-login__eyebrow']);
echo html_writer::tag('h1', $title, ['class' => 'ulms-role-login__title']);
echo html_writer::tag('p', get_string('activationintro', 'local_ulms_auth'), ['class' => 'ulms-role-login__meta']);
echo html_writer::tag('p', get_string('activationnote', 'local_ulms_auth'), ['class' => 'ulms-role-login__note']);
echo html_writer::end_div();

echo html_writer::start_div('ulms-role-login__layout');
echo html_writer::start_div('ulms-panel ulms-role-login__panel');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', get_string('activationformheading', 'local_ulms_auth'), ['class' => 'ulms-panel__title']);
echo html_writer::tag('p', get_string('activationformdesc', 'local_ulms_auth'), ['class' => 'ulms-panel__subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');

if ($tokenerror !== '') {
    echo $OUTPUT->notification($tokenerror, \core\output\notification::NOTIFY_ERROR);
}

if ($setpasswordform) {
    ob_start();
    $setpasswordform->display();
    echo html_writer::div(ob_get_clean(), 'ulms-role-login__form');
}

echo html_writer::div(
    html_writer::link(local_ulms_auth_get_unified_sign_in_url(), get_string('passwordresetreturnsignin', 'local_ulms_auth'), ['class' => 'ulms-nav-pill']) .
    html_writer::link(local_ulms_auth_get_password_reset_url(), get_string('activationrequestnewlink', 'local_ulms_auth'), ['class' => 'ulms-nav-pill']),
    'ulms-role-login__actions'
);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('ulms-panel ulms-role-login__panel ulms-panel--soft');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::tag('h2', get_string('activationhelpheading', 'local_ulms_auth'), ['class' => 'ulms-panel__title']);
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');
echo html_writer::tag('p', get_string('activationhelpbody', 'local_ulms_auth'), ['class' => 'ulms-panel__subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
echo $OUTPUT->footer();
