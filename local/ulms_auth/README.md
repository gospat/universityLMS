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

Only the `combo` and `account` buckets can block a request by themselves. The
`abuseip` bucket is a **detection** signal, not a network-wide lockout: by
default (`ratelimitabuseipmode = detect`) crossing it does **not** block the
address or any account — see §3.1.3.1. This is deliberate, because Bells
students and staff share a campus NAT and a small number of institutional egress
addresses; a blanket per-IP block would take the whole campus offline the moment
one unrelated account behind that address was attacked. Locking one account never
blocks the other accounts sharing that IP.

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

#### 3.1.3 Settings reference

| Setting (UI / `local_ulms_auth/...`) | Env override | Default | Meaning |
|---|---|---|---|
| `enableratelimit` | `ULMS_LOGIN_RATELIMIT_ENABLED` | on | Master switch for the login limiter. |
| `ratelimitbackend` | `ULMS_LOGIN_RATELIMIT_BACKEND` | `auto` | `auto` \| `redis` \| `file`. |
| `ratelimitfallback` | `ULMS_LOGIN_RATELIMIT_FALLBACK` | on | Allow the bounded file fallback when Redis is unavailable or an operation fails. |
| `ratelimitsecuritylogging` | `ULMS_LOGIN_RATELIMIT_LOGGING` | on | Emit structured security events. |
| `ratelimitcombolimit` | `ULMS_LOGIN_RATELIMIT_COMBO_LIMIT` | 5 | `combo` bucket limit. |
| `ratelimitcombowindow` | `ULMS_LOGIN_RATELIMIT_COMBO_WINDOW` | 180 | `combo` bucket window (seconds). |
| `ratelimitaccountlimit` | `ULMS_LOGIN_RATELIMIT_ACCOUNT_LIMIT` | 10 | `account` bucket limit. |
| `ratelimitaccountwindow` | `ULMS_LOGIN_RATELIMIT_ACCOUNT_WINDOW` | 900 | `account` bucket window (seconds). |
| `ratelimitabuseiplimit` | `ULMS_LOGIN_RATELIMIT_ABUSE_IP_LIMIT` | 300 | `abuseip` detection threshold. |
| `ratelimitabuseipwindow` | `ULMS_LOGIN_RATELIMIT_ABUSE_IP_WINDOW` | 300 | `abuseip` window (seconds). |
| `ratelimitabuseipmode` | `ULMS_LOGIN_RATELIMIT_ABUSE_IP_MODE` | `detect` | `off` \| `detect` \| `block` — how the `abuseip` bucket is enforced. |
| `ratelimitprogressivelimit` | `ULMS_LOGIN_RATELIMIT_PROGRESSIVE_LIMIT` | 2 | Tighter `combo` allowance applied to an address already above the abuse threshold (detect mode). |
| `ratelimitabuseiphardlimit` | `ULMS_LOGIN_RATELIMIT_ABUSE_IP_HARD_LIMIT` | 0 | Optional absolute per-IP ceiling that blocks in detect mode; `0` disables it. |
| `ratelimitdegradedmode` | `ULMS_LOGIN_RATELIMIT_DEGRADED_MODE` | `failopen` | `failopen` \| `failclosed` — behaviour when no counter store is usable. |
| `ratelimitminimumretry` | `ULMS_LOGIN_RATELIMIT_MIN_RETRY` | 15 | Lower clamp for the advertised `Retry-After`. |
| `ratelimitmaximumretry` | `ULMS_LOGIN_RATELIMIT_MAX_RETRY` | 900 | Upper clamp for the advertised `Retry-After`. |

#### 3.1.3.1 Progressive shared-IP protection (`ratelimitabuseipmode`)

Raising the `abuseip` threshold alone does not solve the institutional
shared-network problem: a fixed ceiling either blocks an entire campus egress
address or is high enough to be useless. The limiter therefore makes the
per-IP bucket **progressive** by default:

| Mode | Behaviour when an address is above `ratelimitabuseiplimit` |
|---|---|
| `detect` (default) | **No hard block.** The address is flagged (`challenged` in the check result, `abuse_level` in the metrics) and its per-account `combo` allowance is tightened from `ratelimitcombolimit` (5) to `ratelimitprogressivelimit` (2). Effective protection against spraying and distributed attacks is retained, but unrelated legitimate accounts behind the same address keep the normal `account` allowance and are **not** blocked. |
| `block` | Legacy behaviour: the whole address is blocked once the abuse threshold is crossed. Available for sites that explicitly want a blanket network lockout. |
| `off` | The `abuseip` bucket is neither counted nor evaluated. |

In `detect` mode an optional absolute ceiling can be layered on top: when
`ratelimitabuseiphardlimit` is greater than `0`, an address whose count reaches
that value **is** blocked (the threshold is intentionally far above
`ratelimitabuseiplimit`). This gives an operator an explicit, opt-in hard stop
without making it the default.

The important property is that protection scales with the abuse volume instead of
switching between "no effect" and "everyone on this IP is locked out". A shared
address under attack sees only its *per-account* allowance tighten; the accounts
behind it that are not part of the attack continue to authenticate normally.

#### 3.1.4 Backend selection, runtime failures and fallback

The backend is chosen once per request, and every individual store operation is
guarded, because **Redis can stay connected while its operations fail**.

Resolution order (`store()`):

- **`auto` (default)** — use Redis when it is reachable; otherwise fall back to
  the file store. This is the recommended production setting.
- **`redis`** — prefer Redis. If Redis is unreachable and `ratelimitfallback` is
  on, the file store takes over; if fallback is off, no store is used and the
  degraded-mode policy applies.
- **`file`** — never touch Redis.

##### Runtime operation failures (not just the initial connection)

Initial resolution is not enough, so `inspect()`, `hit()` and `clear()` are all
routed through a guarded `invoke()` that treats a failed operation as a
*transition trigger*, not as a "not counted" result. The following production
conditions are handled:

| Condition | Example | Handled as |
|---|---|---|
| Memory exhaustion | Redis `noeviction` at 256 MB → `OOM command not allowed` | permission/memory failure category → handover |
| Command permission error | ACL denies a command → `NOPERM` | permission failure category → handover |
| Connection loss after init | socket closed mid-request | connect failure category → handover |
| Read/write timeout | slow or stalled server | timeout failure category → handover |
| Unexpected reply | malformed / wrong-type reply | response failure category → handover |
| Partial counter update | `INCR` applied but `EXPIRE` failed | the whole increment is re-applied on the fallback |

When the primary fails mid-request the limiter:

1. emits a `ratelimit_backend_degraded` security event with the driver's
   classified failure category;
2. switches **this request** to the already-prepared fallback store;
3. **re-runs the same operation on the fallback** so the attempt is not dropped.

If the fallback also fails, the operation is genuinely unserved and the
degraded-mode policy (§3.1.5) decides the outcome. A failed *read* can never
under-count a *write*, because the write is retried on the fallback rather than
being treated as zero.

##### Counter continuity across backend transitions and recovery

Counter continuity is preserved by design:

- **No counter is reset, deleted or migrated during a switch.** A client cannot
  escape a restriction by inducing a Redis failure, and a transition never rewinds
  an account's or an address's history.
- **The failed increment is re-applied on the fallback**, so attempts are never
  silently dropped while a store is degraded.
- **A restriction is only ever delayed until the fallback applies it** — the
  limiter never returns "allowed" merely because the primary failed.
- **Redis keeps its own counters and TTLs.** Nothing deletes them on the way out,
  so when Redis recovers the counters are still there and counting resumes
  seamlessly.
- **A fresh limiter instance is created per request**, so recovery takes effect
  immediately on the next request — there is no sticky "degraded" state to clear.
- **Counters written on the file fallback expire on their own** (each key is
  written with a TTL), so the fallback cannot accumulate stale restrictions after
  Redis returns.

The practical consequence is that a Redis outage shortens nothing and lengthens
nothing: it changes *where* the count is kept, not *whether* it is kept.

#### 3.1.5 Degraded mode when no store is usable

If **both** stores are unusable (Redis down *and* the file fallback unavailable
or disabled), the request is handed to the bounded degraded-mode policy. This is
never silent: a `ratelimit_degraded` event is always emitted, and the branch taken
is controlled by `ratelimitdegradedmode`:

| Value | Behaviour | When to use |
|---|---|---|
| `failopen` (default) | Sign-in stays **available**; the request is allowed and the outage is logged loudly (`ratelimit_degraded`, action `allow`). | Keeps the LMS usable — availability first. Moodle's native lockout is **not** re-enabled as a compensating control. |
| `failclosed` | Sign-in is **denied** with a bounded `Retry-After` (clamped to `ratelimitminimumretry`), bucket `degraded`, action `deny`. | For a deployment that would rather stop all logins than run with no counter store. |

Both branches are bounded: `failclosed` advertises the minimum retry, so it
cannot become a permanent lockout, and `failopen` is visible in the security log
so the gap is detectable rather than invisible. The system never silently
disables protection *and* never silently breaks authentication: the operator
picks the trade-off with `ratelimitdegradedmode` before an incident, not during
one.

> **Recommendation for Bells production.** Keep the default `failopen` while
> Moodle's native account lockout stays disabled, and alert on any
> `ratelimit_degraded` / `ratelimit_unavailable` event. If policy later requires
> authentication to stop rather than proceed unprotected, switch to `failclosed`
> — but only after confirming the file fallback is healthy, so that a Redis
> outage alone never reaches degraded mode.

Failures never surface Redis errors, stack traces or infrastructure detail to the
user, and a restriction can never become permanent because every counter is
written with an expiry.

#### 3.1.6 Trusted proxy / Cloudflare client IP

Production sits behind **Cloudflare** → **Nginx** → PHP-FPM.

> **Verified production state (do not assume the Nginx rewrite is active).** A
> direct inspection of the production Nginx configuration returned **no**
> `real_ip_header`, `set_real_ip_from` or `real_ip_recursive` directives. The
> reference site config in `deploy/nginx/` *does* contain them, but that file has
> not been applied to the live server, so it must not be treated as the current
> production state. The limiter is designed to be correct **with or without** that
> Nginx change:
>
> - **Without the Nginx rewrite (current production).** The immediate peer that
>   PHP sees is the **Cloudflare edge** address, which is one of the trusted
>   ranges below, so the resolver honours `CF-Connecting-IP` (set by Cloudflare
>   and passed through by Nginx by default) and returns the true browser IP.
>   A direct-to-origin request has a peer outside those ranges, so its
>   `CF-Connecting-IP` / `X-Forwarded-For` headers are ignored.
> - **With the Nginx rewrite (optional defence in depth).** The peer becomes the
>   true client IP, which is not a trusted range, so the resolver simply returns
>   it. Spoofing is inert in both configurations.
>
> Adopting the managed snippet
> ([deploy/nginx/ulms-cloudflare-realip.conf](../../deploy/nginx/ulms-cloudflare-realip.conf))
> is therefore an **optional** hardening step, applied by a human during an
> approved change window. It is not required for the limiter to attribute
> failures to the correct client, and the plugin never modifies Nginx or firewall
> settings itself.

[client_ip.php](./classes/local/service/ratelimit/client_ip.php) resolves the
address as follows:

- only honours a forwarding header when the **direct peer** is a configured
  trusted proxy (the `trustedproxies` setting below, or the built-in defaults —
  loopback plus the 22 published Cloudflare ranges);
- otherwise returns the direct peer address, so spoofed
  `X-Forwarded-For` / `CF-Connecting-IP` headers from a direct-origin hit are
  ignored;
- handles **IPv4 and IPv6**, canonicalises the address (IPv6 compression,
  bracket/port and `%zone` stripping), and safely falls back to the raw peer on
  malformed input;
- never collapses every Cloudflare visitor onto a single IP.

| Setting | Type | Default | Meaning |
|---|---|---|---|
| `trustedproxies` | textarea | (empty — use the built-in loopback + Cloudflare ranges) | Newline/comma list of trusted proxy IPs or CIDR ranges. |
| `trustedproxyheaders` | text | `cf-connecting-ip,x-real-ip,x-forwarded-for` | Header preference order to read from a trusted peer. |

> The `trustedproxies` setting **adds to** the built-in defaults; leaving it empty
> is the supported configuration for the current Cloudflare-fronted production
> setup. Never add an origin address or a private range to `trustedproxies`, and
> never add `set_real_ip_from` for a non-Cloudflare network — either would let a
> direct client dictate its own address.

#### 3.1.7 HTTP 429 contract

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

#### 3.1.8 Configuration example

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
ULMS_LOGIN_RATELIMIT_ABUSE_IP_MODE=detect  # off | detect | block
ULMS_LOGIN_RATELIMIT_PROGRESSIVE_LIMIT=2   # combo allowance for an abusive IP
ULMS_LOGIN_RATELIMIT_ABUSE_IP_HARD_LIMIT=0 # 0 = no absolute per-IP ceiling
ULMS_LOGIN_RATELIMIT_DEGRADED_MODE=failopen # failopen | failclosed
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
`EXPIRE`, `TTL`, `PTTL`, `DEL`, `EXISTS`, `EVAL`, `EVALSHA`). It deliberately has
**no** `SCAN` or `INFO` permission — operational diagnostics use the separate
root-controlled administrative access, not the application user (see
[04-LOGIN-RATE-LIMITER.md](../../scripts/DEPLOYMENT_RUNBOOKS/04-LOGIN-RATE-LIMITER.md) §5).
The credential file must be readable only by the web user (e.g.
`root:www-data 0640`). **Never commit the password**, and never give the
application the Redis admin credential.

#### 3.1.9 Transition plan (JSON/cache → Redis)

1. Deploy the plugin code with `ratelimitbackend = auto` and
   `ratelimitfallback = 1`. No data migration is required: counters are
   ephemeral and start empty, so the worst case is a single fresh counting
   window.
2. Confirm Redis is healthy and the ACL user authenticates. Supply the credential
   through `REDISCLI_AUTH` (an environment variable) rather than a command-line
   argument, so it never appears in the process list:
   ```bash
   REDISCLI_AUTH="$(sudo cat /etc/ulms/redis-auth.secret)" \
     redis-cli -h 127.0.0.1 -p 6379 --user ulms_auth PING   # -> PONG
   ```
   Then confirm the ACL user can write only inside the namespace
   (`SET ulms:auth:probe 1` → `OK`) and is denied outside it
   (`SET ulms:outside 1` → `NOPERM`).
3. Verify enforcement during a deliberate test of failed logins. Because the
   application ACL user intentionally has **no** `SCAN` permission, enumerate
   keys with the **root-controlled administrative access**, not the app user
   (see
   [04-LOGIN-RATE-LIMITER.md](../../scripts/DEPLOYMENT_RUNBOOKS/04-LOGIN-RATE-LIMITER.md) §5);
   confirm every key carries a positive TTL and that successful logins clear the
   `account`/`combo` keys. Remove any diagnostic key you create after testing.
4. The old per-IP / per-identifier / per-combo cache-bucket limiter and its
   `ULMS_LOCKOUT_*` environment variables are **superseded** by this policy and
   are no longer read by the login path.

Operational steps for deploying, verifying, monitoring and rolling this limiter
back are in
[scripts/DEPLOYMENT_RUNBOOKS/04-LOGIN-RATE-LIMITER.md](../../scripts/DEPLOYMENT_RUNBOOKS/04-LOGIN-RATE-LIMITER.md).

#### 3.1.10 Coexistence with Moodle's native account lockout

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
| `ratelimit_backend_unavailable` | The preferred counter backend (Redis) was unreachable; records a scrubbed reason and failure category. |
| `ratelimit_backend_fallback` | The limiter selected the bounded file fallback store at resolution time. |
| `ratelimit_backend_degraded` | A primary store operation failed **at runtime** and the request was handed to the fallback store (records the operation and failure category). |
| `ratelimit_degraded` | No counter store could serve a check or a write; records the configured degraded mode and the action taken (`allow` / `deny` / `record_skipped` / `record_failed`). |
| `ratelimit_unavailable` | Both stores were unusable and the limiter entered degraded mode (logged only). |

The audit log events are human-searchable JSON lines and also accessible via
the `/super-admin/auditlogs` view (see `local_ulms_dashboard` Super Admin
sections).

---

## 6. Testing

The plugin ships PHPUnit test classes covering the auth flow, the clean-route
registry, the email template, the bootstrap buffer guard, the login rate limiter
end-to-end, genuine parallel-process concurrency, and the role login controller
regression:

| Test file | Coverage |
|---|---|
| [tests/auth_flow_test.php](./tests/auth_flow_test.php) | Force-password-change flow, forced flag cleared on successful reset, portal redirection after password change. |
| [tests/landing_page_service_test.php](./tests/landing_page_service_test.php) | Clean route registration, role portal resolution, canonical URL generation for all 4 portals. |
| [tests/route_canonical_url_test.php](./tests/route_canonical_url_test.php) | Every directory-backed clean route keeps its canonical trailing slash (a missing slash makes a POST a GET and drops the body). |
| [tests/email_template_service_test.php](./tests/email_template_service_test.php) | Branded email template: client-compatible markup, exhaustive escaping, action button cannot diverge from the visible fallback link. |
| [tests/bootstrap_output_buffer_guard_test.php](./tests/bootstrap_output_buffer_guard_test.php) | No ULMS entry point flushes or ends an output buffer without first proving one exists. |
| [tests/ratelimit_login_outcome_test.php](./tests/ratelimit_login_outcome_test.php) | `login_outcome::classify()` for every `AUTH_LOGIN_*` code: only `FAILED`/`NOUSER` count, everything else is neutral. |
| [tests/ratelimit_client_ip_test.php](./tests/ratelimit_client_ip_test.php) | Trusted-proxy forwarding, XFF/CF header handling, IPv4 + IPv6 canonicalisation, CIDR matching, malformed-input fallback. |
| [tests/ratelimit_limiter_test.php](./tests/ratelimit_limiter_test.php) | Bucket thresholds, one account not blocking unrelated accounts, blocked requests not extending the window, success clearing account counters without resetting the abuse counter, Retry-After reflecting the real expiry. Also covers progressive shared-IP modes, the opt-in abuse hard limit, runtime store handover, and both degraded-mode branches. |
| [tests/ratelimit_redis_store_test.php](./tests/ratelimit_redis_store_test.php) | Redis integration against a real server: atomic increment + expiry, inspect-does-not-increment, clear, the `ulms:auth:*` ACL namespace enforcing least privilege, and the limiter tripping/completing through Redis. |
| [tests/ratelimit_concurrency_test.php](./tests/ratelimit_concurrency_test.php) | **Genuine parallel-process** concurrency: simultaneous counter increments (file and Redis), concurrent authentication admissions bounded by the threshold, counter expiry under parallel load, simultaneous success + failure, and a Redis→file runtime transition that loses no increment. |
| [tests/role_login_regression_test.php](./tests/role_login_regression_test.php) | Integration regression for the role login controller against Moodle's real auth: valid/invalid login tokens, wrong portal, unconfirmed account, internal exceptions staying neutral, and the LTI/SSO token-validation exemption, plus log redaction. |

#### 6.1 Atomic counter update vs atomic admission

Two different properties are deliberately separated, and the tests assert both:

- **Atomic counter update.** `counter_store::hit()` is atomic — Redis applies
  `INCR` + `EXPIRE` in one server-side Lua script, and the file store serialises
  with `flock(LOCK_EX)`. Parallel increments therefore never lose a count.
- **Non-atomic admission.** Enforcement (`check()`) is intentionally **not**
  atomic with accounting (`record()`): the limiter has to return an allow/deny
  decision *before* the credential is verified and the outcome recorded. Under
  genuine parallelism, N simultaneous requests can each pass `check()` before any
  of them records a failure, so the count can *overshoot* the threshold by at
  most the number of in-flight requests.

The threshold is therefore a **bound, not an exact cut-off**, and the concurrency
suite asserts that overshoot stays bounded (requests cannot substantially exceed
the intended threshold) rather than asserting an unrealisable exact ceiling. This
is the standard trade-off for a pre-authentication gate that must not depend on
the authentication result to decide whether to run it.

Run with the standard Moodle phpunit runner:

```bash
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --testsuite local_ulms_auth_tests
```

The Redis integration suites (`ratelimit_redis_store_test.php` and the Redis
cases in `ratelimit_concurrency_test.php`) skip **only** when the `phpredis`
extension is absent. When `phpredis` **is** present they require a reachable Redis
and fail rather than skipping, so a real Redis regression cannot pass unnoticed.
The file-store concurrency cases and every other test always run. The tests read
their connection from environment variables and never contain a secret:

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
