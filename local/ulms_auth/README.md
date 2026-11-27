# local_ulms_auth

ULMS authentication, landing-page, and clean-route plugin.

This plugin defines **all** public-facing URLs that students, lecturers,
admins, and super-admins use to log in, reset their password, and activate
new accounts.  It also hosts the Redis-backed login rate limiter, password-reset
rate limiter, audited security event logger, forced password-change workflow, and
the Moodle `\core\hook\after_config`-triggered `.htaccess`-style clean route
entry dispatcher (via `clean_route_entry.php`).

---

## 1. Responsibilities

1. **Clean canonical public routes.**  Maps human-readable URLs to controllers without exposing internal Moodle file names.
2. **Landing page service.**  Resolves the current user's *correct* portal entry after sign-in based on their primary role + capabilities.
3. **Login attempt rate limiting** — Redis-backed, 3 independent buckets to defeat both distributed brute force and targeted credential stuffing.
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

The login rate limiter is **Redis-backed** by default and keeps every counter
outside the request hot path. The password-reset limiter uses short-lived Moodle
cache state (see §3.2). Neither writes to a database table.

### 3.1 Login Attempt Rate Limit

**Why this was rebuilt.** The previous limiter stored counters in **JSON files**
and did a read-modify-write per request, so concurrent logins could lose
updates; it counted the wrong events as failures (wrong-role portal, unconfirmed
accounts, internal exceptions); thresholds were hardcoded; and the 429 page
advertised a fixed 30-second retry regardless of the real remaining restriction.
It could also let a single account's failed attempts collide with unrelated
accounts sharing one public IP. The rebuild below replaces the storage with
atomic Redis counters, classifies outcomes precisely, makes every threshold
configurable, derives `Retry-After` from the actual expiry, and keeps the IP
bucket at an abuse-detection ceiling rather than a hard network lockout.

The login limiter is layered so each concern can be reasoned about and tested on
its own:

| Layer | File | Responsibility |
|---|---|---|
| Client address | [classes/local/service/ratelimit/client_ip.php](./classes/local/service/ratelimit/client_ip.php) | Resolve one canonical, spoof-safe client IP. |
| Store contract | [classes/local/service/ratelimit/counter_store.php](./classes/local/service/ratelimit/counter_store.php) | `hit` / `inspect` / `clear` / `is_available`. |
| Redis store | [classes/local/service/ratelimit/redis_counter_store.php](./classes/local/service/ratelimit/redis_counter_store.php) | Atomic counters via a server-side Lua script. |
| File store | [classes/local/service/ratelimit/file_counter_store.php](./classes/local/service/ratelimit/file_counter_store.php) | Bounded, `flock`-serialised local fallback. |
| Policy | [classes/local/service/ratelimit/rate_limit_policy.php](./classes/local/service/ratelimit/rate_limit_policy.php) | Thresholds, windows, backend, retry clamp. |
| Outcome | [classes/local/service/ratelimit/login_outcome.php](./classes/local/service/ratelimit/login_outcome.php) | Classify an attempt as success / failure / neutral. |
| Limiter | [classes/local/service/ratelimit/login_rate_limiter.php](./classes/local/service/ratelimit/login_rate_limiter.php) | Derive keys, enforce, record, choose backend. |
| Facade | [classes/local/service/login_rate_limit.php](./classes/local/service/login_rate_limit.php) | Static API used by the login controllers + the 429 page. |

#### 3.1.1 Buckets

**3 independent buckets** are evaluated on every attempt. A counter is only ever
incremented on a genuine credential failure, and enforcement itself never
increments, so a request that is already blocked cannot extend its own
restriction.

| Bucket | Key scope | Default limit / window | Defeats |
|---|---|---|---|
| `combo` | one client IP + one account | 5 / 180 s (3 min) | fast brute force on a single account from one source |
| `account` | one account, across every IP | 10 / 900 s (15 min) | slow, low-and-slow credential stuffing from many addresses |
| `abuseip` | one client IP, across every account | 300 / 300 s (5 min) | distributed / password-spray abuse from one source |

The `abuseip` ceiling is deliberately high so that students and staff sharing a
campus NAT or institutional egress address are **not** locked out because one
unrelated account behind the same address is being attacked. Locking one account
never blocks the other accounts sharing that IP.

#### 3.1.2 What counts as a failure

Only a **wrong credential** is counted. The limiter inspects the
`authenticate_user_login()` result through
[login_outcome::classify()](./classes/local/service/ratelimit/login_outcome.php)
and treats everything else as **neutral** (a no-op that leaves all counters
untouched):

| Counted as failure | Never counted |
|---|---|
| `AUTH_LOGIN_FAILED` (wrong password) | wrong-role portal rejection |
| `AUTH_LOGIN_NOUSER` (unknown user) | account not yet activated / confirmed |
| | suspended account (`AUTH_LOGIN_SUSPENDED`) |
| | `AUTH_LOGIN_LOCKOUT` / `AUTH_LOGIN_UNAUTHORISED` |
| | invalid / expired session or login token |
| | reCAPTCHA failure (`AUTH_LOGIN_FAILED_RECAPTCHA`) |
| | internal exceptions, DB errors, Redis errors, any other infra failure |

A **successful** login clears the account-specific `account` and `combo`
counters, but deliberately **preserves** the shared `abuseip` counter so that a
successful login cannot be used to reset network-abuse detection. Unknown users
and wrong passwords are treated identically, so the response never reveals
whether an account exists.

> The plugin resolves login-token validity *before* Moodle consumes the token
> (see `local_ulms_auth_login_token_is_valid()` in [lib.php](./lib.php)), so a
> stale-token re-submit is correctly classified as neutral instead of being
> charged against the account.

#### 3.1.3 Backend selection, fallback and resilience

| Setting (UI / `local_ulms_auth/...`) | Env override | Default | Meaning |
|---|---|---|---|
| `enableratelimit` | `ULMS_LOGIN_RATELIMIT_ENABLED` | on | Master switch for the login limiter. |
| `ratelimitbackend` | `ULMS_LOGIN_RATELIMIT_BACKEND` | `auto` | `auto` \| `redis` \| `file`. |
| `ratelimitfallback` | `ULMS_LOGIN_RATELIMIT_FALLBACK` | on | Allow the bounded file fallback when Redis is down. |
| `ratelimitsecuritylogging` | `ULMS_LOGIN_RATELIMIT_LOGGING` | on | Emit structured security events. |

Behaviour:

- **`auto` (default)** — use Redis when it is reachable; otherwise fall back to
  the file store. This is the recommended production setting.
- **`redis`** — prefer Redis. If Redis is unreachable and `ratelimitfallback` is
  on, the file store takes over; if fallback is off, the limiter fails open and
  logs `ratelimit_backend_unavailable`.
- **`file`** — never touch Redis.

The LMS **must not become inaccessible when Redis is down**. If Redis is
unavailable the file store (bounded, `flock`-serialised, self-pruning) keeps
protection active rather than silently disabling it. Only when *both* stores are
unusable does the limiter fail open, and that event is always logged as
`ratelimit_unavailable`. Failures never surface Redis errors, stack traces or
infrastructure detail to the user, and a restriction can never become permanent
because every counter is written with an expiry.

#### 3.1.4 Trusted proxy / Cloudflare client IP

Production sits behind **Cloudflare** → **Nginx** → PHP-FPM. Nginx terminates
the real client address with `real_ip_header CF-Connecting-IP;` and the official
Cloudflare `set_real_ip_from` ranges (see `deploy/nginx/`), so PHP must **not**
blindly trust a client-supplied header. [client_ip.php](./classes/local/service/ratelimit/client_ip.php)
therefore:

- only honours a forwarding header when the **direct peer** is a configured
  trusted proxy (Moodle's `getremoteaddr()` chain or the `trustedproxies`
  setting below);
- otherwise falls back to the direct peer address, so spoofed
  `X-Forwarded-For` / `CF-Connecting-IP` headers from a direct-origin hit are
  ignored;
- handles **IPv4 and IPv6**, canonicalises the address (IPv6 compression,
  bracket/port and `%zone` stripping), and safely falls back to the raw peer on
  malformed input;
- never collapses every Cloudflare visitor onto a single IP.

| Setting | Type | Default | Meaning |
|---|---|---|---|
| `trustedproxies` | textarea | (empty — use Moodle's configured chain) | Newline/comma list of trusted proxy IPs or CIDR ranges. |
| `trustedproxyheaders` | text | `cf-connecting-ip,x-real-ip,x-forwarded-for` | Header preference order to read from a trusted peer. |

#### 3.1.5 HTTP 429 contract

When a restriction applies, the limiter responds with **HTTP 429** and:

- an accurate `Retry-After` header taken from the **real remaining expiry** of the
  restricting bucket (clamped to the configured minimum/maximum);
- anti-cache headers (`Cache-Control: no-store, no-cache, must-revalidate`,
  `Pragma: no-cache`, `Expires: 0`);
- `X-Content-Type-Options: nosniff` and `X-Frame-Options: DENY`;
- a **Bells University branded** HTML page (desktop and mobile) with a short
  human-readable wait time and an 8-character **support reference**
  (`[ULMS-LOGIN-RL-429-<ref>]` is logged server-side for correlation).

The 429 body never exposes thresholds, bucket names, counters, account
existence, stack traces or Redis/infrastructure detail.

#### 3.1.6 Configuration example

Thresholds and windows may be tuned from **Site administration → Plugins →
Local plugins → ULMS Authentication** or overridden per environment. Example
`.env` overrides (placeholders only — never commit real secrets):

```dotenv
# Login rate limiter (values shown are the defaults)
ULMS_LOGIN_RATELIMIT_ENABLED=1
ULMS_LOGIN_RATELIMIT_BACKEND=auto          # auto | redis | file
ULMS_LOGIN_RATELIMIT_FALLBACK=1            # allow bounded file fallback
ULMS_LOGIN_RATELIMIT_LOGGING=1
ULMS_LOGIN_RATELIMIT_COMBO_LIMIT=5
ULMS_LOGIN_RATELIMIT_COMBO_WINDOW=180
ULMS_LOGIN_RATELIMIT_ACCOUNT_LIMIT=10
ULMS_LOGIN_RATELIMIT_ACCOUNT_WINDOW=900
ULMS_LOGIN_RATELIMIT_ABUSE_IP_LIMIT=300
ULMS_LOGIN_RATELIMIT_ABUSE_IP_WINDOW=300
ULMS_LOGIN_RATELIMIT_MIN_RETRY=15
ULMS_LOGIN_RATELIMIT_MAX_RETRY=900

# Redis connection (credential is read from a file, never from an env var)
ULMS_REDIS_HOST=<redis-host>
ULMS_REDIS_PORT=6379
ULMS_REDIS_ACL_USER=<acl-username>
ULMS_REDIS_SECRET_FILE=<path-to-root-owned-credential-file>
```

The Redis ACL user must be restricted to the `ulms:auth:*` key namespace and to
the commands the store actually uses (`PING`, `GET`, `SET`, `SETEX`, `INCR`,
`EXPIRE`, `TTL`, `PTTL`, `DEL`, `EXISTS`, `EVAL`, `EVALSHA`). The credential file
must be readable only by the web user (e.g. `root:www-data 0640`). **Never commit
the password**, and never give the application the Redis admin credential.

#### 3.1.7 Transition plan (JSON/cache → Redis)

1. Deploy the plugin code with `ratelimitbackend = auto` and
   `ratelimitfallback = 1`. No data migration is required: counters are
   ephemeral and start empty, so the worst case is a single fresh counting
   window.
2. Confirm Redis is healthy and the ACL user authenticates
   (`redis-cli --user <acl-user> --pass <secret> PING` → `PONG`, and a write
   inside `ulms:auth:*` succeeds while a write outside it is denied).
3. Verify enforcement with `redis-cli --scan --pattern 'ulms:auth:*'` during a
   deliberate test of failed logins, then confirm successful logins clear the
   `account`/`combo` keys.
4. The old per-IP / per-identifier / per-combo cache-bucket limiter and its
   `ULMS_LOCKOUT_*` environment variables are **superseded** by this policy and
   are no longer read by the login path.

Operational steps for deploying, verifying, monitoring and rolling this limiter
back are in
[scripts/DEPLOYMENT_RUNBOOKS/04-LOGIN-RATE-LIMITER.md](../../scripts/DEPLOYMENT_RUNBOOKS/04-LOGIN-RATE-LIMITER.md).

#### 3.1.8 Coexistence with Moodle's native account lockout

Moodle core has its own account lockout (`$CFG->lockoutthreshold` /
`lockoutwindow`) that counts failed logins against a user record. It is
currently **disabled** in production, and this plugin does **not** re-enable it
silently — enabling an overlapping lockout policy at the same time would let one
attacker lock a real account out of Moodle entirely (a denial-of-service on the
victim, not the attacker).

Recommended coexistence:

- **Keep Moodle's native lockout disabled** while the Redis limiter is the
  primary control. The limiter already stops brute force at the network/account
  edge and, crucially, does **not** modify the user account, so a victim's
  account is never actually locked by an attacker.
- Treat the Redis limiter's `account` and `combo` buckets as the *front door*
  and Moodle's core lockout as an optional *second* layer to be enabled only
  deliberately, with a longer window and a lockout that an administrator can
  clear via the standard "unlock" workflow.
- If native lockout is ever enabled, raise its threshold well above the Redis
  `account` limit so the Redis layer absorbs normal attack traffic first, and
  document the change as a separate, approved production decision.
- Both mechanisms may coexist, but the rule is **one intentional policy at a
  time**: never enable native lockout as an incidental side effect of this
  change.

This is a proposal for approval; no core lockout setting is changed by this
plugin.

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
| `ratelimit_backend_unavailable` | The preferred counter backend (Redis) was unreachable; records a scrubbed reason. |
| `ratelimit_backend_fallback` | The limiter switched to the bounded file fallback store. |
| `ratelimit_unavailable` | Both stores were unusable and the limiter failed open (logged only). |

The audit log events are human-searchable JSON lines and also accessible via
the `/super-admin/auditlogs` view (see `local_ulms_dashboard` Super Admin
sections).

---

## 6. Testing

The plugin ships PHPUnit test classes covering the auth flow, the clean-route
registry, the email template, the bootstrap buffer guard, and the login rate
limiter end-to-end:

| Test file | Coverage |
|---|---|
| [tests/auth_flow_test.php](./tests/auth_flow_test.php) | Force-password-change flow, forced flag cleared on successful reset, portal redirection after password change. |
| [tests/landing_page_service_test.php](./tests/landing_page_service_test.php) | Clean route registration, role portal resolution, canonical URL generation for all 4 portals. |
| [tests/route_canonical_url_test.php](./tests/route_canonical_url_test.php) | Every directory-backed clean route keeps its canonical trailing slash (a missing slash makes a POST a GET and drops the body). |
| [tests/email_template_service_test.php](./tests/email_template_service_test.php) | Branded email template: client-compatible markup, exhaustive escaping, action button cannot diverge from the visible fallback link. |
| [tests/bootstrap_output_buffer_guard_test.php](./tests/bootstrap_output_buffer_guard_test.php) | No ULMS entry point flushes or ends an output buffer without first proving one exists. |
| [tests/ratelimit_login_outcome_test.php](./tests/ratelimit_login_outcome_test.php) | `login_outcome::classify()` for every `AUTH_LOGIN_*` code: only `FAILED`/`NOUSER` count, everything else is neutral. |
| [tests/ratelimit_client_ip_test.php](./tests/ratelimit_client_ip_test.php) | Trusted-proxy forwarding, XFF/CF header handling, IPv4 + IPv6 canonicalisation, CIDR matching, malformed-input fallback. |
| [tests/ratelimit_limiter_test.php](./tests/ratelimit_limiter_test.php) | Bucket thresholds, one account not blocking unrelated accounts, blocked requests not extending the window, success clearing account counters without resetting the abuse counter, Retry-After reflecting the real expiry. |
| [tests/ratelimit_redis_store_test.php](./tests/ratelimit_redis_store_test.php) | Redis integration against a real server: atomic increment + expiry, inspect-does-not-increment, clear, the `ulms:auth:*` ACL namespace enforcing least privilege, and the limiter tripping/completing through Redis. |

Run with the standard Moodle phpunit runner:

```bash
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --testsuite local_ulms_auth_tests
```

The Redis integration suite (`ratelimit_redis_store_test.php`) is skipped only
when the `phpredis` extension is absent. When `phpredis` **is** present it
requires a reachable Redis and fails rather than skipping, so a real Redis
regression cannot pass unnoticed. It reads its connection from environment
variables and never contains a secret:

| Env var | Default |
|---|---|
| `ULMS_REDIS_HOST` | `127.0.0.1` |
| `ULMS_REDIS_PORT` | `6379` |
| `ULMS_REDIS_ACL_USER` | `ulms_auth` |
| `ULMS_REDIS_SECRET_FILE` | `/etc/ulms/redis-auth.secret` |

CI (`.github/workflows/ulms-ci.yml`) provisions a throwaway Redis 7 container
with an equivalent least-privilege ACL user for each run, so these tests execute
against a real server instead of being skipped. The remaining suites use only
isolated Moodle test data and never touch production.

(Composer vendor is not required to use the plugin, only to run the test suite.)
