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

namespace local_ulms_auth\local\service\ratelimit;

defined('MOODLE_INTERNAL') || die();

/**
 * Classifies an authentication result so only genuine credential failures are counted.
 *
 * The rate limiter must never punish a legitimate user for a non-credential outcome
 * (wrong portal, unconfirmed account, suspended account, infrastructure failure) and
 * must never let an attacker buy free attempts through those same paths.
 *
 * Moodle core collapses several distinct situations into AUTH_LOGIN_FAILED: a wrong
 * password, an unknown account, and an invalid or consumed login token all arrive with
 * the same error code. The caller resolves token validity before authentication so an
 * invalid token can be classified as neutral here.
 *
 * @package    local_ulms_auth
 * @copyright  2026 Bells University of Technology
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class login_outcome {
    /** @var string Credentials were verified. */
    public const SUCCESS = 'success';

    /** @var string A genuine credential failure that must increment the counters. */
    public const FAILURE = 'failure';

    /** @var string Neither a credential success nor a credential failure. */
    public const NEUTRAL = 'neutral';

    /**
     * Classifies a finished authentication attempt.
     *
     * @param bool $authenticated Whether authenticate_user_login() returned a user object.
     * @param int $errorcode The AUTH_LOGIN_* error code reported by core.
     * @param bool $tokenvalid Whether the submitted login token was valid at submit time.
     * @return string One of SUCCESS, FAILURE or NEUTRAL.
     */
    public static function classify(bool $authenticated, int $errorcode, bool $tokenvalid = true): string {
        if ($authenticated) {
            // A returned user means the credential matched, even when the account is
            // later rejected for portal or activation reasons.
            return self::SUCCESS;
        }
        if (!$tokenvalid) {
            // An invalid or expired login token never reached the credential check.
            return self::NEUTRAL;
        }
        return self::is_credential_failure($errorcode) ? self::FAILURE : self::NEUTRAL;
    }

    /**
     * Returns whether an error code represents a genuine credential failure.
     *
     * Only a wrong password (AUTH_LOGIN_FAILED) and an unknown account
     * (AUTH_LOGIN_NOUSER) count. Suspended, locked-out, unauthorised and reCAPTCHA
     * outcomes are deliberately excluded.
     *
     * @param int $errorcode The AUTH_LOGIN_* error code reported by core.
     * @return bool
     */
    public static function is_credential_failure(int $errorcode): bool {
        return $errorcode === AUTH_LOGIN_FAILED || $errorcode === AUTH_LOGIN_NOUSER;
    }

    /**
     * Returns whether an outcome string is one of the recognised values.
     *
     * @param string $outcome Candidate outcome.
     * @return bool
     */
    public static function is_valid(string $outcome): bool {
        return in_array($outcome, [self::SUCCESS, self::FAILURE, self::NEUTRAL], true);
    }
}
