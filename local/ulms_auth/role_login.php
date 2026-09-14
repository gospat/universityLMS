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
require_once(__DIR__ . '/lib.php');

if (!(defined('PHPUNIT_TEST') && PHPUNIT_TEST) && !(defined('BEHAT_TEST') && BEHAT_TEST)) {
    try {
        require_once(__DIR__ . '/classes/local/service/login_rate_limit.php');
    } catch (\Throwable $_) {
    }
}

if (method_exists(\theme_ulms_university\output\core_renderer::class, 'inject_http_security_headers')) {
    \theme_ulms_university\output\core_renderer::inject_http_security_headers();
}

global $PAGE, $OUTPUT, $USER;

if (!defined('ULMS_AUTH_PORTAL_KEY')) {
    throw new moodle_exception('invalidportalroute', 'local_ulms_auth');
}

$portalkey = (string)constant('ULMS_AUTH_PORTAL_KEY');
$service = new \local_ulms_auth\local\service\landing_page_service();
$portal = $service->get_portal_definition($portalkey);
$url = $service->get_login_url_for_portal($portalkey);
$context = \context::instance_by_id(context_system::instance()->id);
$username = local_ulms_auth_get_saved_username();
$formerror = '';
$fielderrors = ['username' => '', 'password' => ''];
$rememberusername = local_ulms_auth_should_remember_username_by_default();
$matchedportal = null;
$otherportals = array_values(array_filter(
    $service->get_portal_cards(),
    static fn(array $item): bool => $item['key'] !== $portalkey
));

if (isloggedin() && !isguestuser()) {
    $service->redirect_to_current_user_dashboard(
        get_string('alreadyauthenticatedredirect', 'local_ulms_auth'),
        \core\output\notification::NOTIFY_INFO,
        302
    );
}

if (data_submitted() && confirm_sesskey()) {
    $username = trim((string)optional_param('username', '', PARAM_RAW_TRIMMED));
    $password = (string)optional_param('password', '', PARAM_RAW);
    $rememberusername = local_ulms_auth_should_remember_username_by_default(
        local_ulms_auth_should_offer_remember_username() ? (bool)optional_param('rememberusername', 0, PARAM_BOOL) : false
    );
    $logintoken = (string)optional_param('logintoken', '', PARAM_RAW);
    $errorcode = 0;

    if ($username === '') {
        $fielderrors['username'] = get_string('loginfieldrequired', 'local_ulms_auth', get_string('portalusername', 'local_ulms_auth'));
    } else if ($password === '') {
        $fielderrors['password'] = get_string('loginfieldrequired', 'local_ulms_auth', get_string('password'));
    } else {
        $normalisedusername = !empty($CFG->loginwithemail)
            ? $username
            : trim(core_text::strtolower($username));
        if (class_exists(\local_ulms_auth\local\service\login_rate_limit::class, false)) {
            \local_ulms_auth\local\service\login_rate_limit::enforce_pre_login($normalisedusername, true);
        }
        try {
            $user = authenticate_user_login($normalisedusername, $password, false, $errorcode, $logintoken, false);

            if (!$user) {
                $formerror = local_ulms_auth_get_login_error_message($normalisedusername, $errorcode);
                if (class_exists(\local_ulms_auth\local\service\login_rate_limit::class, false)) {
                    \local_ulms_auth\local\service\login_rate_limit::record_login_result($normalisedusername, false);
                }
                local_ulms_auth_log_security_event('portal_login_failed', [
                    'portal' => $portalkey,
                    'reason' => $errorcode,
                    'identifierhash' => sha1($normalisedusername),
                ]);
            } else if (empty($user->confirmed)) {
                $formerror = get_string('portalloginactivationrequired', 'local_ulms_auth');
                if (class_exists(\local_ulms_auth\local\service\login_rate_limit::class, false)) {
                    \local_ulms_auth\local\service\login_rate_limit::record_login_result($normalisedusername, false);
                }
            } else if (!$service->user_matches_portal($user, $portalkey)) {
                $matchedportal = $service->get_portal_definition($service->get_matching_portal_for_user($user));
                $formerror = get_string('portalrolemismatch', 'local_ulms_auth', (object) [
                    'expected' => $portal['title'],
                    'actual' => $matchedportal['title'],
                ]);
                if (class_exists(\local_ulms_auth\local\service\login_rate_limit::class, false)) {
                    \local_ulms_auth\local\service\login_rate_limit::record_login_result($normalisedusername, false);
                }
            } else {
                if (class_exists(\local_ulms_auth\local\service\login_rate_limit::class, false)) {
                    \local_ulms_auth\local\service\login_rate_limit::record_login_result($normalisedusername, true);
                }
                complete_user_login($user);

                if (local_ulms_auth_should_offer_remember_username() && $rememberusername) {
                    set_moodle_cookie($USER->username);
                } else {
                    set_moodle_cookie('');
                }

                $service->redirect_to_portal_dashboard($portalkey);
            }
        } catch (\Throwable $exception) {
            if (class_exists(\local_ulms_auth\local\service\login_rate_limit::class, false)) {
                \local_ulms_auth\local\service\login_rate_limit::record_login_result($normalisedusername, false);
            }
            local_ulms_auth_log_security_event('portal_login_exception', [
                'portal' => $portalkey,
                'identifierhash' => sha1($normalisedusername),
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
            ]);
            $formerror = get_string('portalloginfailure', 'local_ulms_auth');
        }
    }
}

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('login');
$PAGE->set_title(get_string('portalloginheading', 'local_ulms_auth', $portal['title']));
$PAGE->set_heading(format_string($portal['title']));
$PAGE->add_body_class('ulms-role-login-page');
$PAGE->add_body_class('ulms-role-login-page--' . $portalkey);
$PAGE->requires->js_call_amd('core_form/submit', 'init', ['ulms-role-login-submit']);
local_ulms_auth_require_shared_ui($PAGE);
local_ulms_auth_require_password_toggle($PAGE, 'password', 'ulms-role-login-password-toggle', 'ulms-role-login-password-status');

$errorattributes = ['class' => 'alert alert-danger', 'id' => 'ulms-role-login-error', 'role' => 'alert', 'tabindex' => '-1'];
$usernamedescribedby = ['ulms-role-login-username-help'];
$passworddescribedby = ['ulms-role-login-password-help'];
$usernameerrorid = 'ulms-role-login-username-error';
$passworderrorid = 'ulms-role-login-password-error';
$rememberhelpid = 'ulms-role-login-remember-help';

if ($fielderrors['username'] !== '') {
    $usernamedescribedby[] = $usernameerrorid;
}

if ($fielderrors['password'] !== '') {
    $passworddescribedby[] = $passworderrorid;
}

$usernameattributes = [
    'type' => 'text',
    'name' => 'username',
    'id' => 'username',
    'class' => 'form-control form-control-lg',
    'value' => s($username),
    'autocomplete' => 'username',
    'required' => 'required',
    'spellcheck' => 'false',
    'autocapitalize' => 'none',
    'aria-describedby' => implode(' ', $usernamedescribedby),
];
$passwordattributes = [
    'type' => 'password',
    'name' => 'password',
    'id' => 'password',
    'class' => 'form-control form-control-lg',
    'value' => '',
    'autocomplete' => 'current-password',
    'required' => 'required',
    'aria-describedby' => implode(' ', $passworddescribedby),
];

if ($fielderrors['username'] !== '') {
    $usernameattributes['aria-invalid'] = 'true';
}

if ($fielderrors['password'] !== '') {
    $passwordattributes['aria-invalid'] = 'true';
}

if ($username === '') {
    $usernameattributes['autofocus'] = 'autofocus';
} else {
    $passwordattributes['autofocus'] = 'autofocus';
}

echo $OUTPUT->header();
echo html_writer::start_div('ulms-role-login');
echo html_writer::start_div('ulms-role-login__hero');
echo html_writer::tag('p', format_string($portal['eyebrow']), ['class' => 'ulms-role-login__eyebrow']);
echo html_writer::tag('h1', format_string($portal['title']), ['class' => 'ulms-role-login__title']);
echo html_writer::tag('p', format_string($portal['description']), ['class' => 'ulms-role-login__meta']);
echo html_writer::tag('p', get_string('portalaudience', 'local_ulms_auth', $portal['audiencesummary']), ['class' => 'ulms-role-login__note']);
echo html_writer::tag('p', get_string('portalaccessnote', 'local_ulms_auth'), ['class' => 'ulms-role-login__note']);
echo local_ulms_auth_render_info_list([
    get_string('portalsecurityitemportal', 'local_ulms_auth'),
    get_string('portalsecurityitempassword', 'local_ulms_auth'),
    get_string('portalsecurityitemrecovery', 'local_ulms_auth'),
]);
echo html_writer::end_div();

echo html_writer::start_div('ulms-role-login__layout');
echo html_writer::start_div('ulms-panel ulms-role-login__panel ulms-role-login__panel--form');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', get_string('portalloginheading', 'local_ulms_auth', $portal['title']), ['class' => 'ulms-panel__title']);
echo html_writer::tag('p', get_string('portallogindesc', 'local_ulms_auth', $portal['title']), ['class' => 'ulms-panel__subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');

if ($formerror !== '') {
    echo html_writer::tag('div', s($formerror), $errorattributes);
    if ($matchedportal !== null) {
        echo html_writer::div(
            html_writer::link(
                new moodle_url($matchedportal['login']),
                get_string('portalmatchedcta', 'local_ulms_auth', $matchedportal['title']),
                ['class' => 'ulms-role-login__secondary-link']
            ),
            'ulms-role-login__actions'
        );
    }
}

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $url,
    'id' => 'ulms-role-login-form',
    'class' => 'ulms-role-login__form',
    'data-ulms-loading-form' => '1',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'logintoken', 'value' => \core\session\manager::get_login_token()]);

echo html_writer::start_div('form-group');
echo html_writer::tag('label', get_string('portalusername', 'local_ulms_auth'), ['for' => 'username', 'class' => 'form-label']);
echo html_writer::empty_tag('input', $usernameattributes);
echo html_writer::tag('p', get_string('portalusernamehelp', 'local_ulms_auth'), [
    'class' => 'ulms-role-login__field-help',
    'id' => 'ulms-role-login-username-help',
]);
if ($fielderrors['username'] !== '') {
    echo html_writer::tag('p', s($fielderrors['username']), [
        'class' => 'ulms-role-login__field-error',
        'id' => $usernameerrorid,
    ]);
}
echo html_writer::end_div();

echo html_writer::start_div('form-group login-form-password');
echo html_writer::tag('label', get_string('password'), ['for' => 'password', 'class' => 'form-label']);
echo html_writer::start_div('ulms-password-field ulms-role-login__password-wrap');
echo html_writer::empty_tag('input', $passwordattributes);
echo html_writer::tag(
    'button',
    html_writer::span('', 'icon fa fa-eye', ['aria-hidden' => 'true', 'data-role' => 'icon']) .
    html_writer::span(get_string('portalshowpassword', 'local_ulms_auth'), 'sr-only', ['data-role' => 'label']),
    [
        'type' => 'button',
        'id' => 'ulms-role-login-password-toggle',
        'class' => 'btn btn-light ulms-password-toggle ulms-role-login__password-toggle',
        'aria-controls' => 'password',
        'aria-label' => get_string('portalshowpassword', 'local_ulms_auth'),
        'aria-pressed' => 'false',
    ]
);
echo html_writer::end_div();
echo html_writer::tag('p', get_string('portalpasswordhelp', 'local_ulms_auth'), [
    'class' => 'ulms-role-login__field-help',
    'id' => 'ulms-role-login-password-help',
]);
echo html_writer::tag('span', '', [
    'class' => 'sr-only',
    'id' => 'ulms-role-login-password-status',
    'aria-live' => 'polite',
]);
if ($fielderrors['password'] !== '') {
    echo html_writer::tag('p', s($fielderrors['password']), [
        'class' => 'ulms-role-login__field-error',
        'id' => $passworderrorid,
    ]);
}
echo html_writer::end_div();

if (local_ulms_auth_should_offer_remember_username()) {
    echo html_writer::start_div('ulms-role-login__remember');
    echo html_writer::start_div('form-check');
    echo html_writer::checkbox('rememberusername', 1, $rememberusername, get_string('portalrememberme', 'local_ulms_auth'), [
        'id' => 'rememberusername',
        'class' => 'form-check-input',
        'aria-describedby' => $rememberhelpid,
    ]);
    echo html_writer::end_div();
    echo html_writer::tag('p', get_string('portalremembermehelp', 'local_ulms_auth'), [
        'class' => 'ulms-role-login__field-help',
        'id' => $rememberhelpid,
    ]);
    echo html_writer::end_div();
}

echo html_writer::tag('button', get_string('portalloginbutton', 'local_ulms_auth', $portal['title']), [
    'type' => 'submit',
    'id' => 'ulms-role-login-submit',
    'class' => 'btn btn-primary btn-lg btn-block',
    'data-loading-text' => get_string('portalloginloading', 'local_ulms_auth', $portal['title']),
]);
echo html_writer::div(
    html_writer::link(
        local_ulms_auth_get_password_reset_url($portalkey),
        get_string('passwordresetlink', 'local_ulms_auth'),
        ['class' => 'ulms-role-login__secondary-link']
    ) .
    html_writer::link(
        (new \local_ulms_auth\local\service\landing_page_service())->get_url_for_route('public.landing'),
        get_string('portalbacktolanding', 'local_ulms_auth'),
        ['class' => 'ulms-role-login__secondary-link']
    ),
    'ulms-role-login__actions'
);
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('ulms-panel ulms-role-login__panel ulms-role-login__panel--support ulms-panel--soft');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', get_string('portalloginhelpheading', 'local_ulms_auth'), ['class' => 'ulms-panel__title']);
echo html_writer::tag('p', get_string('portalloginhelpdesc', 'local_ulms_auth'), ['class' => 'ulms-panel__subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');
echo local_ulms_auth_render_info_list([
    get_string('portalsecurityitemportal', 'local_ulms_auth'),
    get_string('portalsecurityitempassword', 'local_ulms_auth'),
    get_string('portalsecurityitemrecovery', 'local_ulms_auth'),
]);
echo html_writer::tag('h3', get_string('portalalternateheading', 'local_ulms_auth'), ['class' => 'ulms-role-login__section-title']);
echo local_ulms_auth_render_portal_cards($otherportals);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
echo $OUTPUT->footer();
