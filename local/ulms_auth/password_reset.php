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

$portalkey = optional_param('portal', '', PARAM_ALPHA);
$service = new \local_ulms_auth\local\service\landing_page_service();
$legacyroutekey = match ($portalkey) {
    'student' => 'student.passwordreset',
    'lecturer' => 'lecturer.passwordreset',
    'administrator' => 'management.passwordreset',
    'superadmin' => 'superadmin.passwordreset',
    default => 'public.passwordreset',
};
$service->maybe_redirect_legacy_request($legacyroutekey);
$portal = null;

if ($portalkey !== '') {
    try {
        $portal = $service->get_portal_definition($portalkey);
    } catch (\Throwable $exception) {
        $portal = null;
    }
}

if (isloggedin() && !isguestuser()) {
    $service->redirect_to_current_user_dashboard(
        get_string('alreadyauthenticatedredirect', 'local_ulms_auth'),
        \core\output\notification::NOTIFY_INFO,
        302
    );
}

$url = local_ulms_auth_get_password_reset_token_url($portal['key'] ?? null, $token !== '' ? $token : null);
$context = \context::instance_by_id(\context_system::instance()->id);
$tokenstate = $token !== '' ? local_ulms_auth_get_password_token_state($token) : null;
$showpasswordform = $tokenstate !== null && $tokenstate['status'] === 'valid' && !empty($tokenstate['user']);
$title = $showpasswordform
    ? get_string('passwordresetcompleteheading', 'local_ulms_auth')
    : get_string('passwordresetheading', 'local_ulms_auth');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('login');
$PAGE->set_cacheable(false);
$PAGE->set_title($title);
$PAGE->set_heading($title);
$PAGE->add_body_class('ulms-role-login-page');
$PAGE->add_body_class('ulms-password-reset-page');
local_ulms_auth_require_shared_ui($PAGE);
$PAGE->requires->js_call_amd('core/togglesensitive', 'init', ['id_password']);
$PAGE->requires->js_call_amd('core/togglesensitive', 'init', ['id_password2']);

$identifier = '';
$error = '';
$notice = '';
$issuccess = false;
$honeypotname = 'organisation';
$supportemail = trim((string)($CFG->supportemail ?? ''));
$supportcontact = $supportemail !== '' ? $supportemail : get_string('passwordresetcontactsupportfallback', 'local_ulms_auth');
$returnurl = $portal !== null
    ? new moodle_url($portal['login'])
    : $service->get_public_portal_landing_url();
$returnlabel = $portal !== null
    ? get_string('passwordresetreturnportal', 'local_ulms_auth', $portal['title'])
    : get_string('passwordresetreturnlanding', 'local_ulms_auth');
$tokenerror = '';
$setpasswordform = null;

if ($showpasswordform) {
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
            get_string('passwordresetcompletesignin', 'local_ulms_auth'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    $setdata = new \stdClass();
    $setdata->username = $tokenstate['user']->username;
    $setdata->username2 = $tokenstate['user']->username;
    $setdata->token = $tokenstate['user']->token;
    $setpasswordform->set_data($setdata);
} else if ($tokenstate !== null) {
    if ($tokenstate['status'] === 'expired') {
        $tokenerror = get_string('passwordresettokenexpired', 'local_ulms_auth', (int)floor($tokenstate['pwresettime'] / MINSECS));
    } else {
        $tokenerror = get_string('passwordresettokeninvalid', 'local_ulms_auth');
    }
}

if ($token === '' && data_submitted() && confirm_sesskey()) {
    $identifier = trim((string)optional_param('identifier', '', PARAM_RAW_TRIMMED));
    $honeypot = trim((string)optional_param($honeypotname, '', PARAM_RAW_TRIMMED));

    if ($honeypot !== '') {
        local_ulms_auth_log_security_event('password_reset_honeypot_triggered', [
            'portal' => $portal['key'] ?? '',
        ]);
        $notice = get_string('passwordresetgenericnotice', 'local_ulms_auth');
        $issuccess = true;
    } else if ($identifier === '') {
        $error = get_string('passwordresetidentifierrequired', 'local_ulms_auth');
    } else if (str_contains($identifier, '@') && !validate_email($identifier)) {
        $error = get_string('passwordresetidentifierinvalid', 'local_ulms_auth');
    } else if (!local_ulms_auth_allow_password_reset_request($identifier)) {
        local_ulms_auth_log_security_event('password_reset_rate_limited', [
            'portal' => $portal['key'] ?? '',
            'identifierhash' => sha1(core_text::strtolower($identifier)),
        ]);
        $notice = get_string('passwordresetratelimitednotice', 'local_ulms_auth');
        $issuccess = true;
    } else {
        try {
            $user = local_ulms_auth_find_resettable_user($identifier);
            if ($user) {
                $resetrecord = local_ulms_auth_issue_password_token($user);
                if ($resetrecord) {
                    local_ulms_auth_send_password_reset_email($user, $resetrecord);
                }
            }
            $notice = get_string('passwordresetgenericnotice', 'local_ulms_auth');
            $issuccess = true;
            local_ulms_auth_log_security_event('password_reset_requested', [
                'portal' => $portal['key'] ?? '',
                'identifierhash' => sha1(core_text::strtolower($identifier)),
            ]);
        } catch (\Throwable $exception) {
            local_ulms_auth_log_security_event('password_reset_failed', [
                'portal' => $portal['key'] ?? '',
                'identifierhash' => sha1(core_text::strtolower($identifier)),
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
            ]);
            $error = get_string('passwordresettemporarilyunavailable', 'local_ulms_auth', $supportcontact);
        }
    }
}

echo $OUTPUT->header();
echo html_writer::start_div('ulms-role-login ulms-password-reset');
echo html_writer::start_div('ulms-role-login__hero');
echo html_writer::tag('p', $showpasswordform ? get_string('passwordresetcompleteeyebrow', 'local_ulms_auth') : get_string('passwordreseteyebrow', 'local_ulms_auth'), ['class' => 'ulms-role-login__eyebrow']);
echo html_writer::tag('h1', $title, ['class' => 'ulms-role-login__title']);
echo html_writer::tag('p', $showpasswordform ? get_string('passwordresetcompleteintro', 'local_ulms_auth') : get_string('passwordresetintro', 'local_ulms_auth'), ['class' => 'ulms-role-login__meta']);
echo html_writer::tag('p', $showpasswordform ? get_string('passwordresetcompletenote', 'local_ulms_auth') : get_string('passwordresetsecuritynote', 'local_ulms_auth'), ['class' => 'ulms-role-login__note']);
echo html_writer::end_div();

echo html_writer::start_div('ulms-role-login__layout');
echo html_writer::start_div('ulms-panel ulms-role-login__panel');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::start_div();
echo html_writer::tag('h2', $showpasswordform ? get_string('passwordresetcompleteformheading', 'local_ulms_auth') : get_string('passwordresetformheading', 'local_ulms_auth'), ['class' => 'ulms-panel__title']);
echo html_writer::tag('p', $showpasswordform ? get_string('passwordresetcompleteformdesc', 'local_ulms_auth') : get_string('passwordresetformdesc', 'local_ulms_auth'), ['class' => 'ulms-panel__subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');

if ($tokenerror !== '') {
    echo $OUTPUT->notification($tokenerror, \core\output\notification::NOTIFY_ERROR);
}

if ($error !== '') {
    echo $OUTPUT->notification($error, \core\output\notification::NOTIFY_ERROR);
}

if ($issuccess) {
    echo $OUTPUT->notification($notice, \core\output\notification::NOTIFY_SUCCESS);
}

if ($showpasswordform && $setpasswordform) {
    ob_start();
    $setpasswordform->display();
    echo html_writer::div(ob_get_clean(), 'ulms-role-login__form');
} else {
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $url,
        'class' => 'ulms-role-login__form',
        'novalidate' => 'novalidate',
        'data-ulms-loading-form' => '1',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::start_div('form-group');
    echo html_writer::tag('label', get_string('passwordresetidentifierlabel', 'local_ulms_auth'), [
        'for' => 'identifier',
        'class' => 'form-label',
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => 'identifier',
        'id' => 'identifier',
        'class' => 'form-control form-control-lg',
        'value' => s($identifier),
        'autocomplete' => 'username',
        'required' => 'required',
        'aria-describedby' => 'ulms-password-reset-help',
    ]);
    echo html_writer::tag('p', get_string('passwordresetidentifierhelp', 'local_ulms_auth'), [
        'class' => 'form-text text-muted',
        'id' => 'ulms-password-reset-help',
    ]);
    echo html_writer::end_div();
    echo html_writer::start_div('ulms-password-reset__honeypot', ['aria-hidden' => 'true']);
    echo html_writer::tag('label', 'Organisation', ['for' => $honeypotname]);
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => $honeypotname,
        'id' => $honeypotname,
        'tabindex' => '-1',
        'autocomplete' => 'off',
    ]);
    echo html_writer::end_div();
    echo html_writer::tag('button', get_string('passwordresetsubmit', 'local_ulms_auth'), [
        'type' => 'submit',
        'class' => 'btn btn-primary btn-lg btn-block',
        'data-loading-text' => get_string('passwordresetsubmitting', 'local_ulms_auth'),
    ]);
    echo html_writer::end_tag('form');
}
echo html_writer::div(
    html_writer::link($returnurl, $returnlabel, ['class' => 'ulms-nav-pill']) .
    html_writer::link(local_ulms_auth_get_unified_sign_in_url(), get_string('passwordresetreturnsignin', 'local_ulms_auth'), ['class' => 'ulms-nav-pill']),
    'ulms-role-login__actions'
);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('ulms-panel ulms-role-login__panel ulms-panel--soft');
echo html_writer::start_div('ulms-panel__header');
echo html_writer::tag('h2', get_string('passwordresethelpheading', 'local_ulms_auth'), ['class' => 'ulms-panel__title']);
echo html_writer::end_div();
echo html_writer::start_div('ulms-panel__body');
echo html_writer::tag('p', get_string('passwordresethelpbody', 'local_ulms_auth', $supportcontact), ['class' => 'ulms-panel__subtitle']);
echo html_writer::tag('p', get_string('passwordresethelpsupport', 'local_ulms_auth'), ['class' => 'ulms-role-login__alternate-meta']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
echo $OUTPUT->footer();
