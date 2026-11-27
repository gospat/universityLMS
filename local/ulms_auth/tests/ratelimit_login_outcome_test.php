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

use local_ulms_auth\local\service\ratelimit\login_outcome;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for authentication outcome classification.
 *
 * @package    local_ulms_auth
 * @copyright  2026 Bells University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ulms_auth\local\service\ratelimit\login_outcome
 */
final class ratelimit_login_outcome_test extends \advanced_testcase {
    public function test_authenticated_user_is_always_success(): void {
        $this->assertSame(login_outcome::SUCCESS, login_outcome::classify(true, AUTH_LOGIN_FAILED));
        $this->assertSame(login_outcome::SUCCESS, login_outcome::classify(true, AUTH_LOGIN_OK));
    }

    public function test_wrong_password_and_unknown_user_are_failures(): void {
        $this->assertSame(login_outcome::FAILURE, login_outcome::classify(false, AUTH_LOGIN_FAILED, true));
        $this->assertSame(login_outcome::FAILURE, login_outcome::classify(false, AUTH_LOGIN_NOUSER, true));
    }

    public function test_non_credential_outcomes_are_neutral(): void {
        $this->assertSame(login_outcome::NEUTRAL, login_outcome::classify(false, AUTH_LOGIN_SUSPENDED, true));
        $this->assertSame(login_outcome::NEUTRAL, login_outcome::classify(false, AUTH_LOGIN_LOCKOUT, true));
        $this->assertSame(login_outcome::NEUTRAL, login_outcome::classify(false, AUTH_LOGIN_UNAUTHORISED, true));
        $this->assertSame(login_outcome::NEUTRAL, login_outcome::classify(false, AUTH_LOGIN_FAILED_RECAPTCHA, true));
    }

    public function test_invalid_login_token_is_neutral(): void {
        $this->assertSame(login_outcome::NEUTRAL, login_outcome::classify(false, AUTH_LOGIN_FAILED, false));
        $this->assertSame(login_outcome::NEUTRAL, login_outcome::classify(false, AUTH_LOGIN_NOUSER, false));
    }

    public function test_is_credential_failure_matches_only_expected_codes(): void {
        $this->assertTrue(login_outcome::is_credential_failure(AUTH_LOGIN_FAILED));
        $this->assertTrue(login_outcome::is_credential_failure(AUTH_LOGIN_NOUSER));
        $this->assertFalse(login_outcome::is_credential_failure(AUTH_LOGIN_SUSPENDED));
        $this->assertFalse(login_outcome::is_credential_failure(AUTH_LOGIN_LOCKOUT));
        $this->assertFalse(login_outcome::is_credential_failure(AUTH_LOGIN_UNAUTHORISED));
        $this->assertFalse(login_outcome::is_credential_failure(AUTH_LOGIN_FAILED_RECAPTCHA));
    }

    public function test_is_valid_rejects_unknown_outcomes(): void {
        $this->assertTrue(login_outcome::is_valid(login_outcome::SUCCESS));
        $this->assertTrue(login_outcome::is_valid(login_outcome::FAILURE));
        $this->assertTrue(login_outcome::is_valid(login_outcome::NEUTRAL));
        $this->assertFalse(login_outcome::is_valid('blocked'));
    }
}
