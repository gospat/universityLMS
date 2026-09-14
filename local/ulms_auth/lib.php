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

require_once($CFG->dirroot . '/login/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/local/ulms_mail/lib.php');

/**
 * Returns the ULMS password reset URL.
 *
 * @param string|null $portalkey
 * @return moodle_url
 */
function local_ulms_auth_get_password_reset_url(?string $portalkey = null): moodle_url {
    $routingservice = new \local_ulms_auth\local\service\landing_page_service();

    return match ($portalkey) {
        'student' => $routingservice->get_url_for_route('student.passwordreset'),
        'lecturer' => $routingservice->get_url_for_route('lecturer.passwordreset'),
        'administrator' => $routingservice->get_url_for_route('management.passwordreset'),
        'superadmin' => $routingservice->get_url_for_route('superadmin.passwordreset'),
        default => $routingservice->get_url_for_route('public.passwordreset'),
    };
}

/**
 * Returns the clean ULMS activation URL.
 *
 * @param string|null $token
 * @return moodle_url
 */
function local_ulms_auth_get_activation_url(?string $token = null): moodle_url {
    $routingservice = new \local_ulms_auth\local\service\landing_page_service();
    $params = [];
    if ($token !== null && $token !== '') {
        $params['token'] = $token;
    }

    return $routingservice->get_url_for_route('public.activate', $params);
}

/**
 * Returns the clean unified sign-in URL.
 *
 * @return moodle_url
 */
function local_ulms_auth_get_unified_sign_in_url(): moodle_url {
    $routingservice = new \local_ulms_auth\local\service\landing_page_service();
    return $routingservice->get_url_for_route('public.landing');
}

/**
 * Returns the clean ULMS password reset URL with an optional token.
 *
 * @param string|null $portalkey
 * @param string|null $token
 * @return moodle_url
 */
function local_ulms_auth_get_password_reset_token_url(?string $portalkey = null, ?string $token = null): moodle_url {
    $url = local_ulms_auth_get_password_reset_url($portalkey);
    if ($token !== null && $token !== '') {
        $url->param('token', $token);
    }

    return $url;
}

/**
 * Returns a resettable active user for the supplied identifier.
 *
 * @param string $identifier
 * @return stdClass|null
 */
function local_ulms_auth_find_resettable_user(string $identifier): ?stdClass {
    global $CFG, $DB;

    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }

    if (str_contains($identifier, '@')) {
        $sql = "SELECT *
                  FROM {user}
                 WHERE " . $DB->sql_equal('email', ':email1', false, true) . "
                   AND id IN (SELECT id
                                FROM {user}
                               WHERE mnethostid = :mnethostid
                                 AND deleted = 0
                                 AND suspended = 0
                                 AND " . $DB->sql_equal('email', ':email2', false, false) . ")";
        $params = [
            'email1' => $identifier,
            'email2' => $identifier,
            'mnethostid' => $CFG->mnet_localhost_id,
        ];
        $user = $DB->get_record_sql($sql, $params, IGNORE_MULTIPLE);
    } else {
        $username = core_text::strtolower($identifier);
        $user = $DB->get_record('user', [
            'username' => $username,
            'mnethostid' => $CFG->mnet_localhost_id,
            'deleted' => 0,
            'suspended' => 0,
        ]);
    }

    if (!$user || empty($user->confirmed)) {
        return null;
    }

    $systemcontext = \context::instance_by_id(\context_system::instance()->id);
    $userauth = get_auth_plugin($user->auth);
    if (
        !$userauth->can_reset_password()
        || !is_enabled_auth($user->auth)
        || !has_capability('moodle/user:changeownpassword', $systemcontext, $user->id)
        || $user->auth === 'nologin'
        || isguestuser($user)
        || trim((string)$user->email) === ''
    ) {
        return null;
    }

    return $user;
}

/**
 * Invalidates old password tokens and issues a fresh one.
 *
 * @param stdClass $user
 * @return stdClass|null
 */
function local_ulms_auth_issue_password_token(stdClass $user): ?stdClass {
    global $DB;

    $resettableuser = local_ulms_auth_find_resettable_user((string)$user->username);
    if (!$resettableuser) {
        return null;
    }

    $DB->delete_records('user_password_resets', ['userid' => $resettableuser->id]);
    return (object)core_login_generate_password_reset($resettableuser);
}

/**
 * Returns token validation state for activation/reset completion.
 *
 * @param string $token
 * @return array{status:string,user:?stdClass,pwresettime:int}
 */
function local_ulms_auth_get_password_token_state(string $token): array {
    global $DB, $CFG;

    $pwresettime = isset($CFG->pwresettime) ? (int)$CFG->pwresettime : 1800;
    $sql = "SELECT u.*, upr.token, upr.timerequested, upr.id AS tokenid
              FROM {user} u
              JOIN {user_password_resets} upr ON upr.userid = u.id
             WHERE upr.token = ?";
    $user = $DB->get_record_sql($sql, [$token]);

    if (empty($user) || ($user->timerequested < (time() - $pwresettime - DAYSECS))) {
        return ['status' => 'invalid', 'user' => null, 'pwresettime' => $pwresettime];
    }

    if ($user->timerequested < (time() - $pwresettime)) {
        return ['status' => 'expired', 'user' => $user, 'pwresettime' => $pwresettime];
    }

    if ($user->auth === 'nologin' || !is_enabled_auth($user->auth) || isguestuser($user)) {
        return ['status' => 'invalid', 'user' => null, 'pwresettime' => $pwresettime];
    }

    return ['status' => 'valid', 'user' => $user, 'pwresettime' => $pwresettime];
}

/**
 * Completes a password token flow and signs the user in.
 *
 * @param stdClass $user
 * @param string $password
 * @param bool $logoutothersessions
 * @param bool $completelogin
 * @return stdClass
 */
function local_ulms_auth_complete_password_token(
    stdClass $user,
    string $password,
    bool $logoutothersessions = true,
    bool $completelogin = true
): stdClass {
    global $DB, $CFG, $SESSION;

    $DB->delete_records('user_password_resets', ['id' => $user->tokenid]);
    $userauth = get_auth_plugin($user->auth);
    if (!$userauth->user_update_password($user, $password)) {
        throw new moodle_exception('errorpasswordupdate', 'auth');
    }

    user_add_password_history($user->id, $password);
    if (!empty($CFG->passwordchangelogout) || $logoutothersessions) {
        \core\session\manager::destroy_user_sessions($user->id, session_id());
    }

    login_unlock_account($user);
    unset_user_preference('auth_forcepasswordchange', $user);
    unset_user_preference('create_password', $user);

    if (!empty($user->lang)) {
        unset($SESSION->lang);
    }

    if ($completelogin) {
        complete_user_login($user);
        \core\session\manager::apply_concurrent_login_limit($user->id, session_id());
    }

    return $user;
}

/**
 * Sends an authentication email through the configured ULMS transport.
 *
 * @param stdClass $user
 * @param string $subject
 * @param string $messagetext
 * @param string $messagehtml
 * @param string $idempotencykey
 * @return bool
 */
function local_ulms_auth_send_transactional_email(
    stdClass $user,
    string $subject,
    string $messagetext,
    string $messagehtml,
    string $idempotencykey
): bool {
    global $CFG;

    $replyto = !empty($CFG->ulmsreplyto) ? (string)$CFG->ulmsreplyto : (string)($CFG->supportemail ?? '');
    $replytoname = !empty($CFG->supportname) ? (string)$CFG->supportname : format_string(get_site()->shortname);

    if (($CFG->ulmsmailtransport ?? 'moodle') === 'resend' && class_exists(\local_ulms_mail\local\service\resend_mail_service::class)) {
        $mailservice = new \local_ulms_mail\local\service\resend_mail_service();
        $result = $mailservice->send_transactional_email([
            'to' => [[
                'email' => (string)$user->email,
                'name' => fullname($user),
            ]],
            'subject' => $subject,
            'text' => $messagetext,
            'html' => $messagehtml,
            'replyto' => $replyto !== '' ? [[
                'email' => $replyto,
                'name' => $replytoname,
            ]] : [],
            'idempotencykey' => $idempotencykey,
        ]);

        return !empty($result['success']);
    }

    if (($CFG->ulmsmailtransport ?? 'moodle') === 'resend') {
        return false;
    }

    $sender = core_user::get_support_user();
    if (!empty($CFG->supportname)) {
        $sender->firstname = (string)$CFG->supportname;
        $sender->lastname = '';
    }

    return email_to_user($user, $sender, $subject, $messagetext, $messagehtml, '', '', true, $replyto, $replytoname);
}

/**
 * Sends a dedicated password reset email using the clean ULMS reset route.
 *
 * @param stdClass $user
 * @param stdClass $resetrecord
 * @return bool
 */
function local_ulms_auth_send_password_reset_email(stdClass $user, stdClass $resetrecord): bool {
    $site = get_site();
    $data = (object)[
        'firstname' => fullname($user),
        'username' => $user->username,
        'resetlink' => local_ulms_auth_get_password_reset_token_url(null, $resetrecord->token)->out(false),
        'signinurl' => local_ulms_auth_get_unified_sign_in_url()->out(false),
        'sitename' => format_string($site->fullname),
        'supportsignature' => generate_email_signoff(),
        'resetminutes' => isset($GLOBALS['CFG']->pwresettime) ? (int)floor($GLOBALS['CFG']->pwresettime / MINSECS) : 30,
    ];

    $subject = get_string('passwordresetemailsubject', 'local_ulms_auth', format_string($site->fullname));
    $messagetext = get_string('passwordresetemailbody', 'local_ulms_auth', $data);
    $messagehtml = text_to_html($messagetext, false, false, true);

    return local_ulms_auth_send_transactional_email(
        $user,
        $subject,
        $messagetext,
        $messagehtml,
        sha1('ulms_password_reset|' . $user->id . '|' . $resetrecord->token)
    );
}

/**
 * Logs a structured security event for developer investigation.
 *
 * @param string $event
 * @param array $data
 * @return void
 */
function local_ulms_auth_log_security_event(string $event, array $data = []): void {
    global $USER;

    $payload = [
        'event' => $event,
        'userid' => isset($USER->id) ? (int)$USER->id : 0,
        'ip' => getremoteaddr(),
        'url' => qualified_me(),
        'data' => $data,
    ];

    error_log('[ULMS_SECURITY] ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * Returns whether the current password reset request should be allowed.
 *
 * @param string $identifier
 * @return bool
 */
function local_ulms_auth_allow_password_reset_request(string $identifier): bool {
    $cache = cache::make('local_ulms_auth', 'password_reset_requests');
    $window = (int)(get_config('local_ulms_auth', 'passwordresetwindow') ?: 900);
    $limit = (int)(get_config('local_ulms_auth', 'passwordresetlimit') ?: 5);
    $now = time();
    $ip = (string)getremoteaddr();
    $normalisedidentifier = core_text::strtolower(trim($identifier));
    $keys = [
        'ip_' . sha1($ip),
        'identifier_' . sha1($normalisedidentifier),
    ];

    foreach ($keys as $key) {
        $timestamps = $cache->get($key);
        if (!is_array($timestamps)) {
            $timestamps = [];
        }

        $timestamps = array_values(array_filter($timestamps, static function(int $timestamp) use ($now, $window): bool {
            return ($now - $timestamp) < $window;
        }));

        if (count($timestamps) >= $limit) {
            $cache->set($key, $timestamps);
            return false;
        }
    }

    foreach ($keys as $key) {
        $timestamps = $cache->get($key);
        if (!is_array($timestamps)) {
            $timestamps = [];
        }

        $timestamps = array_values(array_filter($timestamps, static function(int $timestamp) use ($now, $window): bool {
            return ($now - $timestamp) < $window;
        }));
        $timestamps[] = $now;
        $cache->set($key, $timestamps);
    }

    return true;
}

/**
 * Splits a password reset identifier into username/email fields.
 *
 * @param string $identifier
 * @return array{0:string,1:string}
 */
function local_ulms_auth_resolve_password_reset_identifier(string $identifier): array {
    $identifier = trim($identifier);

    if (str_contains($identifier, '@')) {
        return ['', $identifier];
    }

    return [$identifier, ''];
}

/**
 * Returns the remembered username from the Moodle cookie when enabled.
 *
 * @return string
 */
function local_ulms_auth_get_saved_username(): string {
    if (!local_ulms_auth_should_offer_remember_username()) {
        return '';
    }

    return trim((string)get_moodle_cookie());
}

/**
 * Returns whether the installation permits remembered usernames.
 *
 * @return bool
 */
function local_ulms_auth_should_offer_remember_username(): bool {
    global $CFG;

    return !empty($CFG->rememberusername);
}

/**
 * Returns whether the remember-username control should start checked.
 *
 * @param bool|null $submittedvalue
 * @return bool
 */
function local_ulms_auth_should_remember_username_by_default(?bool $submittedvalue = null): bool {
    if (!local_ulms_auth_should_offer_remember_username()) {
        return false;
    }

    if ($submittedvalue !== null) {
        return $submittedvalue;
    }

    return local_ulms_auth_get_saved_username() !== '';
}

/**
 * Renders a reusable auth information list.
 *
 * @param array<int, string> $items
 * @return string
 */
function local_ulms_auth_render_info_list(array $items): string {
    $content = '';

    foreach ($items as $item) {
        $content .= html_writer::tag('li', s($item), ['class' => 'ulms-role-login__info-item']);
    }

    return html_writer::tag('ul', $content, ['class' => 'ulms-role-login__info-list']);
}

/**
 * Renders a reusable set of portal cards.
 *
 * @param array<int, array<string, mixed>> $portals
 * @param string $headingtag
 * @return string
 */
function local_ulms_auth_render_portal_cards(array $portals, string $headingtag = 'h3'): string {
    $content = '';

    foreach ($portals as $portal) {
        $content .= html_writer::start_div('ulms-role-login__alternate-card');
        $content .= html_writer::tag('p', format_string((string)$portal['eyebrow']), ['class' => 'ulms-role-login__alternate-eyebrow']);
        $content .= html_writer::tag($headingtag, format_string((string)$portal['title']), ['class' => 'ulms-role-login__alternate-title']);
        $content .= html_writer::tag('p', format_string((string)$portal['description']), ['class' => 'ulms-role-login__alternate-meta']);
        $content .= html_writer::tag('p', format_string((string)$portal['audiencesummary']), ['class' => 'ulms-role-login__alternate-meta']);
        $content .= html_writer::link(
            (string)$portal['loginurl'],
            get_string('portalcontinuecta', 'local_ulms_auth'),
            ['class' => 'btn btn-outline-primary']
        );
        $content .= html_writer::end_div();
    }

    return html_writer::div($content, 'ulms-role-login__alternates');
}

/**
 * Loads shared ULMS auth UI assets.
 *
 * @param moodle_page $page
 * @return void
 */
function local_ulms_auth_require_shared_ui(moodle_page $page): void {
    $page->requires->css('/local/ulms_auth/auth-ui.css');
}

/**
 * Finds a local login user by username or email for UX messaging.
 *
 * @param string $identifier
 * @return stdClass|null
 */
function local_ulms_auth_find_login_user(string $identifier): ?stdClass {
    global $CFG, $DB;

    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }

    if (str_contains($identifier, '@')) {
        $sql = "SELECT *
                  FROM {user}
                 WHERE mnethostid = :mnethostid
                   AND deleted = 0
                   AND " . $DB->sql_equal('email', ':email', false, false);
        $user = $DB->get_record_sql($sql, [
            'mnethostid' => $CFG->mnet_localhost_id,
            'email' => $identifier,
        ], IGNORE_MULTIPLE);
    } else {
        $user = $DB->get_record('user', [
            'username' => trim(core_text::strtolower($identifier)),
            'mnethostid' => $CFG->mnet_localhost_id,
            'deleted' => 0,
        ]);
    }

    return $user ?: null;
}

/**
 * Returns whether the matched account still requires activation.
 *
 * @param string $identifier
 * @return bool
 */
function local_ulms_auth_user_requires_activation(string $identifier): bool {
    $user = local_ulms_auth_find_login_user($identifier);
    if (!$user) {
        return false;
    }

    if (empty($user->confirmed)) {
        return true;
    }

    return !empty(get_user_preferences('create_password', 0, $user))
        || !empty(get_user_preferences('auth_forcepasswordchange', 0, $user));
}

/**
 * Returns a safe login error message for the supplied auth outcome.
 *
 * @param string $identifier
 * @param int $errorcode
 * @param stdClass|null $user
 * @return string
 */
function local_ulms_auth_get_login_error_message(string $identifier, int $errorcode, ?stdClass $user = null): string {
    if ($user && empty($user->confirmed)) {
        return get_string('portalloginactivationrequired', 'local_ulms_auth');
    }

    if ($user && (!empty($user->suspended) || ($user->auth ?? '') === 'nologin')) {
        return get_string('portalloginsuspended', 'local_ulms_auth');
    }

    if ($errorcode === AUTH_LOGIN_SUSPENDED) {
        return get_string('portalloginsuspended', 'local_ulms_auth');
    }

    if (local_ulms_auth_user_requires_activation($identifier)) {
        return get_string('portalloginactivationrequired', 'local_ulms_auth');
    }

    return get_string('portallogininvalid', 'local_ulms_auth');
}

/**
 * Adds shared password-visibility behavior for ULMS auth forms.
 *
 * @param moodle_page $page
 * @param string $fieldid
 * @param string $toggleid
 * @param string $statusid
 * @return void
 */
function local_ulms_auth_require_password_toggle(moodle_page $page, string $fieldid, string $toggleid, string $statusid): void {
    local_ulms_auth_require_shared_ui($page);

    $showlabel = json_encode(get_string('portalshowpassword', 'local_ulms_auth'));
    $hidelabel = json_encode(get_string('portalhidepassword', 'local_ulms_auth'));
    $fieldidjson = json_encode($fieldid);
    $toggleidjson = json_encode($toggleid);
    $statusidjson = json_encode($statusid);

    $page->requires->js_init_code(<<<JS
(function() {
    var field = document.getElementById({$fieldidjson});
    var toggle = document.getElementById({$toggleidjson});
    var status = document.getElementById({$statusidjson});
    var showLabel = {$showlabel};
    var hideLabel = {$hidelabel};

    if (!field || !toggle) {
        return;
    }

    var updateState = function() {
        var visible = field.getAttribute('type') === 'text';
        var label = visible ? hideLabel : showLabel;
        var labelNode = toggle.querySelector('[data-role="label"]');
        var iconNode = toggle.querySelector('[data-role="icon"]');

        toggle.setAttribute('aria-label', label);
        toggle.setAttribute('title', label);
        toggle.setAttribute('aria-pressed', visible ? 'true' : 'false');
        toggle.classList.toggle('is-visible', visible);

        if (labelNode) {
            labelNode.textContent = label;
        }

        if (iconNode) {
            iconNode.classList.toggle('fa-eye', !visible);
            iconNode.classList.toggle('fa-eye-slash', visible);
        }

        if (status) {
            status.textContent = label;
        }
    };

    toggle.addEventListener('click', function(event) {
        event.preventDefault();
        field.setAttribute('type', field.getAttribute('type') === 'password' ? 'text' : 'password');
        updateState();
        field.focus({preventScroll: true});
    });

    updateState();
})();
JS
    );
}
