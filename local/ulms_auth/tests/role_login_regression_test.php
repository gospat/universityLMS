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

namespace local_ulms_auth;

use local_ulms_auth\local\service\ratelimit\file_counter_store;
use local_ulms_auth\local\service\ratelimit\login_outcome;
use local_ulms_auth\local\service\ratelimit\login_rate_limiter;
use local_ulms_auth\local\service\ratelimit\rate_limit_policy;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../config.php');
global $CFG;
require_once($CFG->dirroot . '/local/ulms_auth/lib.php');

/**
 * Integration tests for the role login controller's authentication contract.
 *
 * The role login controller (role_login.php) is not unit-testable in isolation because
 * it bootstraps config.php and terminates the request on success, so these tests drive
 * the exact helpers and core calls it makes: the real Moodle authentication path with
 * genuine session login tokens, the token-validity pre-check, the portal matching gate,
 * the activation gate, the exception branch's neutral accounting, and the security-log
 * scrubbing. Together they give integration-level evidence that the controller still
 * behaves correctly with Moodle's actual authentication mechanisms, that a stale login
 * token is never mistaken for a credential failure, and that the LTI/SSO login paths
 * remain unbroken.
 *
 * @package    local_ulms_auth
 * @copyright  2026 Bells University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class role_login_regression_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        // Core writes a failed-login line that references the user agent.
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
    }

    public function test_valid_login_token_authenticates_and_precheck_does_not_consume_it(): void {
        global $CFG;
        unset($CFG->disablelogintoken, $CFG->alternateloginurl);

        $password = $this->generate_strong_test_password();
        $student = $this->create_portal_user('student', $password);

        $token = \core\session\manager::get_login_token();
        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        // The controller resolves token validity before calling core. That pre-check must
        // not consume the token, or the subsequent authentication would be rejected.
        $this->assertTrue(local_ulms_auth_login_token_is_valid($token));
        $this->assertTrue(local_ulms_auth_login_token_is_valid($token));

        $reason = -1;
        $authenticated = authenticate_user_login($student->username, $password, false, $reason, $token, false);

        $this->assertNotFalse($authenticated);
        $this->assertSame(AUTH_LOGIN_OK, $reason);
        $this->assertSame(login_outcome::SUCCESS, login_outcome::classify(true, (int)$reason, true));

        // Core consumes the token, so a replayed submit is now recognised as stale.
        $this->assertFalse(local_ulms_auth_login_token_is_valid($token));
    }

    public function test_invalid_login_token_fails_without_counting_as_a_credential_failure(): void {
        global $CFG;
        unset($CFG->disablelogintoken, $CFG->alternateloginurl);

        $password = $this->generate_strong_test_password();
        $student = $this->create_portal_user('student', $password);

        $bogus = 'stale-token-that-was-never-issued';
        $this->assertFalse(local_ulms_auth_login_token_is_valid($bogus));

        $reason = -1;
        $result = authenticate_user_login($student->username, $password, false, $reason, $bogus, false);

        $this->assertFalse($result);
        $this->assertSame(AUTH_LOGIN_FAILED, $reason);
        // A stale token is neutral: it must never consume the account's credential budget.
        $this->assertSame(login_outcome::NEUTRAL, login_outcome::classify(false, (int)$reason, false));
    }

    public function test_wrong_password_with_a_valid_token_is_a_credential_failure(): void {
        global $CFG;
        unset($CFG->disablelogintoken, $CFG->alternateloginurl);

        $student = $this->create_portal_user('student', $this->generate_strong_test_password());
        $token = \core\session\manager::get_login_token();
        $this->assertTrue(local_ulms_auth_login_token_is_valid($token));

        $reason = -1;
        $result = authenticate_user_login(
            $student->username,
            'Definitely-Wrong-Pass-987!',
            false,
            $reason,
            $token,
            false
        );

        $this->assertFalse($result);
        $this->assertSame(AUTH_LOGIN_FAILED, $reason);
        $this->assertSame(login_outcome::FAILURE, login_outcome::classify(false, (int)$reason, true));
    }

    public function test_unknown_account_is_a_credential_failure(): void {
        global $CFG;
        unset($CFG->disablelogintoken, $CFG->alternateloginurl);

        $token = \core\session\manager::get_login_token();
        $reason = -1;
        $result = authenticate_user_login(
            'no-such-user-' . random_string(8),
            'Some-Password-123!',
            false,
            $reason,
            $token,
            false
        );

        $this->assertFalse($result);
        $this->assertSame(AUTH_LOGIN_NOUSER, $reason);
        $this->assertSame(login_outcome::FAILURE, login_outcome::classify(false, (int)$reason, true));
    }

    public function test_unconfirmed_account_authenticates_but_is_gated_before_session_creation(): void {
        global $CFG;
        unset($CFG->disablelogintoken, $CFG->alternateloginurl);

        $password = $this->generate_strong_test_password();
        $user = $this->getDataGenerator()->create_user([
            'auth' => 'manual',
            'confirmed' => 0,
            'password' => $password,
            'email' => 'unconfirmed-' . random_string(8) . '@example.com',
        ]);

        $token = \core\session\manager::get_login_token();
        $reason = -1;
        $authenticated = authenticate_user_login($user->username, $password, false, $reason, $token, false);

        $this->assertNotFalse($authenticated);
        $this->assertSame(AUTH_LOGIN_OK, $reason);
        // The controller gates on confirmed before completing the session.
        $this->assertEmpty($authenticated->confirmed);
        // Credentials matched, so the activation gate is not a credential failure.
        $this->assertSame(login_outcome::SUCCESS, login_outcome::classify(true, (int)$reason, true));
    }

    public function test_wrong_portal_selection_is_rejected_by_the_portal_gate(): void {
        global $CFG;
        unset($CFG->disablelogintoken, $CFG->alternateloginurl);

        $password = $this->generate_strong_test_password();
        $student = $this->create_portal_user('student', $password);
        $service = new \local_ulms_auth\local\service\landing_page_service();

        $token = \core\session\manager::get_login_token();
        $reason = -1;
        $authenticated = authenticate_user_login($student->username, $password, false, $reason, $token, false);
        $this->assertNotFalse($authenticated);

        $this->assertTrue($service->user_matches_portal($authenticated, 'student'));
        $this->assertFalse($service->user_matches_portal($authenticated, 'lecturer'));
        $this->assertSame('student', $service->get_matching_portal_for_user($authenticated));
        // A valid credential on the wrong portal is a success, not a brute-force signal.
        $this->assertSame(login_outcome::SUCCESS, login_outcome::classify(true, (int)$reason, true));
    }

    public function test_lti_and_sso_configurations_preserve_token_validation_exemption(): void {
        global $CFG;

        $originaldisable = $CFG->disablelogintoken ?? null;
        $originalalternate = $CFG->alternateloginurl ?? null;

        $password = $this->generate_strong_test_password();
        $user = $this->create_portal_user('student', $password);

        try {
            $CFG->disablelogintoken = 1;
            // An LTI / SSO launch cannot carry the login-form token, so core skips the check.
            $this->assertTrue(\core\session\manager::validate_login_token('anything'));
            $this->assertTrue(local_ulms_auth_login_token_is_valid('anything'));

            $reason = -1;
            $authenticated = authenticate_user_login($user->username, $password, false, $reason, 'anything', false);
            $this->assertNotFalse($authenticated);
            $this->assertSame(AUTH_LOGIN_OK, $reason);

            unset($CFG->disablelogintoken);
            $CFG->alternateloginurl = 'https://vle.kortext.com/api/v1/lti/v1.3/auth';
            $this->assertTrue(\core\session\manager::validate_login_token('anything'));
            $this->assertTrue(local_ulms_auth_login_token_is_valid('anything'));
        } finally {
            if ($originaldisable === null) {
                unset($CFG->disablelogintoken);
            } else {
                $CFG->disablelogintoken = $originaldisable;
            }
            if ($originalalternate === null) {
                unset($CFG->alternateloginurl);
            } else {
                $CFG->alternateloginurl = $originalalternate;
            }
        }
    }

    public function test_internal_exception_outcome_is_neutral_and_never_locks_the_account(): void {
        $policy = new rate_limit_policy(
            enabled: true,
            backend: rate_limit_policy::BACKEND_FILE,
            fallbackenabled: true,
            securitylogging: false,
            combolimit: 2,
            combowindow: 180,
            accountlimit: 2,
            accountwindow: 900,
            abuseiplimit: 100,
            abuseipwindow: 300,
            minimumretry: 15,
            maximumretry: 900,
            ipmode: rate_limit_policy::IP_MODE_DETECT,
            progressivecombolimit: 2,
            abuseiphardlimit: 0,
            degradedmode: rate_limit_policy::DEGRADED_FAILOPEN
        );
        $limiter = new login_rate_limiter($policy, new file_counter_store(make_request_directory()), '198.51.100.77');

        // The controller's catch block records NEUTRAL for an internal failure.
        for ($i = 0; $i < 10; $i++) {
            $limiter->record('exception_account', login_outcome::NEUTRAL);
        }

        $result = $limiter->check('exception_account');
        $this->assertFalse($result['blocked']);
    }

    public function test_security_logging_redacts_secrets_from_exception_messages(): void {
        global $CFG;

        $originaldbpass = $CFG->dbpass;
        $secret = 'S3cret-Db-Pass-9f2a';
        $CFG->dbpass = $secret;

        try {
            $scrubbed = local_ulms_auth_scrub_log_data([
                'message' => 'Connection failed using ' . $secret . ' against 127.0.0.1',
                'password' => $secret,
                'identifierhash' => sha1('student1'),
            ]);

            $this->assertSame('[REDACTED]', $scrubbed['password']);
            $this->assertStringContainsString('[REDACTED]', $scrubbed['message']);
            $this->assertStringNotContainsString($secret, $scrubbed['message']);
            $this->assertSame(sha1('student1'), $scrubbed['identifierhash']);

            $logfile = make_request_directory() . '/security.log';
            $previous = ini_get('error_log');
            ini_set('error_log', $logfile);
            try {
                local_ulms_auth_log_security_event('portal_login_exception', [
                    'portal' => 'student',
                    'type' => \RuntimeException::class,
                    'message' => 'Boom while connecting with ' . $secret,
                ]);
            } finally {
                ini_set('error_log', $previous);
            }

            $captured = (string)file_get_contents($logfile);
            $this->assertStringContainsString('[ULMS_SECURITY]', $captured);
            $this->assertStringContainsString('portal_login_exception', $captured);
            $this->assertStringContainsString('[REDACTED]', $captured);
            $this->assertStringNotContainsString($secret, $captured);
        } finally {
            $CFG->dbpass = $originaldbpass;
        }
    }

    /**
     * Creates an active manual-login user and assigns a system role.
     *
     * @param string $roleshortname
     * @param string $password
     * @return \stdClass
     */
    private function create_portal_user(string $roleshortname, string $password): \stdClass {
        global $DB;

        $user = $this->getDataGenerator()->create_user([
            'auth' => 'manual',
            'confirmed' => 1,
            'password' => $password,
            'email' => $roleshortname . '-' . random_string(8) . '@example.com',
        ]);

        $role = $DB->get_record('role', ['shortname' => $roleshortname], 'id');
        $roleid = $role ? (int)$role->id : create_role(ucfirst($roleshortname), $roleshortname, ucfirst($roleshortname) . ' role');
        role_assign($roleid, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        return $user;
    }

    /**
     * Generates a strong runtime-only password for auth tests.
     *
     * @return string
     */
    private function generate_strong_test_password(): string {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $special = '!@#$%^&*';
        $pool = $upper . $lower . $digits . $special;

        $characters = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $special[random_int(0, strlen($special) - 1)],
        ];

        for ($i = 0; $i < 12; $i++) {
            $characters[] = $pool[random_int(0, strlen($pool) - 1)];
        }

        shuffle($characters);
        return implode('', $characters);
    }
}
