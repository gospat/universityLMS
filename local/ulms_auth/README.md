# local_ulms_auth

ULMS authentication, landing-page, and clean-route plugin.

This plugin defines **all** public-facing URLs that students, lecturers,
admins, and super-admins use to log in, reset their password, and activate
new accounts.  It also hosts the 3-tier lockout engine, password-reset rate
limiter, audited security event logger, forced password-change workflow, and
the Moodle `\core\hook\after_config`-triggered `.htaccess`-style clean route
entry dispatcher (via `clean_route_entry.php`).

---

## 1. Responsibilities

1. **Clean canonical public routes.**  Maps human-readable URLs to controllers without exposing internal Moodle file names.
2. **Landing page service.**  Resolves the current user's *correct* portal entry after sign-in based on their primary role + capabilities.
3. **Login attempt rate limiting** — 3 independent buckets to defeat both distributed brute force and targeted credential stuffing.
4. **Password-reset rate limiting** — prevents account enumeration and SMTP/Resend amplification attacks.
5. **Forced password-change workflow** — when an admin resets a user's password, the user's next login is forced to the password-change screen; all other sessions are logged out.
6. **Activation** flow for self-registered or bulk-provisioned users that have not yet claimed their account.
7. **Audit trails** for every security-relevant event (login fail, password reset trigger/honeypot/limit, role-login exception, force-pw-change completion).
8. **Moodle `email_to_user()` integration** with Resend HTTP transport (via `local_ulms_mail`) for password reset / activation / welcome mails.

---

## 2. Clean Routes Registry

All public routes are registered in [classes/local/service/landing_page_service.php](./classes/local/service/landing_page_service.php).
**Share only these URLs with end users**; never link directly to internal
controller filenames such as `student_login.php`.

| Canonical public URL | Purpose | Who reaches it |
|---|---|---|
| `/sign-in/` | Landing page + universal login entry (role buttons: Student / Lecturer / Management / Super Admin) | Everyone |
| `/sign-in/activate/` | Account activation (token from welcome email) | Newly-provisioned users |
| `/reset-password/` | Password reset request form + token flow | Any user with a valid email on file |
| `/student/login/` | Student-focused branded login page | Students |
| `/lecturer/login/` | Lecturer-focused branded login page | Lecturers |
| `/management/login/` | Admin Manager (Management portal) login page | Admin Managers |
| `/super-admin/login/` | Super Admin login page (separate shell, siteadmin only) | Siteadmins |
| `/student/` | Student dashboard (role-gated) | Logged-in students |
| `/lecturer/` | Lecturer dashboard (role-gated) | Logged-in lecturers |
| `/management/` | Management portal (role-gated: Manager) | Logged-in Admin Managers |
| `/management/users/bulk-upload` | CSV bulk upload workspace | Managers + siteadmins |
| `/management/users/bulk-upload/report` | Bulk-upload audit report | Managers + siteadmins |
| `/super-admin/` | Super Admin dashboard (siteadmin-only gate) | Siteadmins |

Internal controller files you should **never share or link to directly** (they remain as fallback entry points only):
- `student_login.php`
- `lecturer_login.php`
- `admin_login.php`
- `super_admin_login.php`
- `password_reset.php`
- `activate.php`

Clean route dispatch happens through `clean_route_entry.php` and the Moodle
rewrite pipeline; there is no need for separate `.htaccess` rules beyond
standard Moodle rewrites.

---

## 3. Lockouts & Rate Limiting

All lockout and rate limit state is stored in **Moodle cache buckets**
(shared caches) rather than a DB table so the hot path never blocks on writes.

### 3.1 Login Attempt Rate Limit

Configured via env vars in `.env` (middle layer of DB > ENV > defaults):

| env key | default | meaning |
|---|---|---|
| `ULMS_LOCKOUT_THRESHOLD` | `5` | Failed attempts per bucket before the bucket locks. |
| `ULMS_LOCKOUT_WINDOW` | `900` (15 min) | Sliding window for counting attempts. |
| `ULMS_LOCKOUT_DURATION` | `1800` (30 min) | How long the bucket stays locked after threshold is reached. |

**3 independent buckets** apply simultaneously (to stop both distributed and targeted attacks):
1. **Per IP address** — blocks 1 IP trying many passwords.
2. **Per username identifier** — blocks the world trying to brute-force 1 specific account.
3. **Per (IP, identifier) combo** — fine-grained; also used to release IP-only lock when combo passes.

### 3.2 Password Reset Rate Limit

| env key | default | meaning |
|---|---|---|
| `ULMS_PASSWORD_RESET_WINDOW` | `1800` (30 min) | Counting window. |

Within the window, any combination of `(requester identifier + requester IP)`
that hits the reset form more than **5 times** is rate-limited and the event is
audit-logged as `password_reset_rate_limited`.  The form intentionally returns
**the same success message whether or not that email is known** (prevents
account enumeration).

### 3.3 Other Security Gates (env-configurable)

| env key | default | meaning |
|---|---|---|
| `ULMS_GUEST_LOGIN_BUTTON` | `0` (disabled) | Show/hide the "Log in as guest" shortcut.  Production keep disabled. |
| `ULMS_AUTO_LOGIN_GUESTS` | `0` (disabled) | Never auto-login as guest. |
| `ULMS_PROTECT_USERNAMES` | `1` (enabled) | Treat username enumeration attempts as security events. |
| `ULMS_PASSWORD_CHANGE_LOGOUT` | `1` (enabled) | After an admin-initiated password reset, force the target user to set a new password on their very next login AND log out every other session they might still hold. |
| `ULMS_FORCE_LOGIN_FOR_PROFILES` | `1` (enabled) | Profiles not tied to an active login should redirect to sign-in. |

---

## 4. Forced Password Change Workflow

This workflow is triggered **automatically** whenever an admin uses the
"Reset password" feature (single or bulk).  User experience:

1. Admin resets the password → Moodle sets the user preference `auth_forcepasswordchange = 1` → Resend sends a "Your password was reset" email with a one-time link.
2. Target user clicks the link → lands on `/sign-in/` → signs in with the temporary password.
3. ULMS **immediately redirects** to `user/edit.php` forced-password-change screen.  Until they complete this screen they cannot access any dashboards/courses.
4. On successful new-password submit, the forced flag is cleared, all other sessions for that user are killed (using `ULMS_PASSWORD_CHANGE_LOGOUT` semantics), the event is audit-logged, and they are returned to the correct portal shell for their role.

This flow is validated in [tests/auth_flow_test.php](./tests/auth_flow_test.php)
and by the PRC check `audit:guards` which ensures the write endpoints involved
all carry CSRF sesskeys.

---

## 5. Security Event Audit Log

Every important event is sent through `local_ulms_auth_log_security_event()`
in [lib.php](./lib.php).  This function:

- Takes an event name + structured data,
- Attaches userid (when known), source IP, HTTP method, URL, User-Agent, timestamp,
- Scrubs passwords / tokens / keys via `scrub_string_secrets()` **before** writing,
- Emits to the PHP error log (and/or Moodle `error_log` — both end up at the path in the env key `ULMS_LOG_FILE`).

Currently logged events:

| event name | When it fires |
|---|---|
| `portal_login_failed` | Bad credential at any of the 4 role login portals (attaches identifier + IP, never the password). |
| `portal_login_exception` | Unhandled exception in the login pipeline. |
| `password_reset_honeypot_triggered` | Hidden form field was tampered with (bot signal). |
| `password_reset_rate_limited` | Identifier+IP exceeded the 5/30 min threshold. |
| `password_reset_requested` | Valid-looking reset request submitted (email dispatched). |
| `password_reset_failed` | Reset token was invalid / expired / wrong user. |

The audit log events are human-searchable JSON lines and also accessible via
the `/super-admin/auditlogs` view (see `local_ulms_dashboard` Super Admin
sections).

---

## 6. Testing

Two PHPUnit test classes cover the plugin:

| Test file | Coverage |
|---|---|
| [tests/auth_flow_test.php](./tests/auth_flow_test.php) | Force-password-change flow, forced flag clear on successful reset, portal redirection after password change. |
| [tests/landing_page_service_test.php](./tests/landing_page_service_test.php) | Clean route registration, role portal resolution, canonical URL generation for all 4 portals. |

Run with the standard Moodle phpunit runner:

```bash
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --testsuite local_ulms_auth_tests
```

(Composer vendor is not required to use the plugin, only to run the test suite.)
