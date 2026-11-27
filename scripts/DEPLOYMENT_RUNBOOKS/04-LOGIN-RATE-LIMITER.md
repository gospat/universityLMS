# ULMS Login Rate Limiter — Deploy, Verify, Monitor, Rollback
## Bells University of Technology — https://learn.bellsuniversity.edu.ng

**Scope**: the Redis-backed login rate limiter in `local/ulms_auth`
(`classes/local/service/login_rate_limit.php` +
`classes/local/service/ratelimit/*`).
**Design, policy and configuration reference**: see
`local/ulms_auth/README.md` §3.1.
**Deployment model (verified)**: the production application is a **Git checkout on
the `main` branch at `/var/www/universityLMS`**, with a **locally modified
production `config.php`**. There is no parallel release directory and no symlink
swap. General deploy/rollback mechanics are in
`01-DEPLOY-AND-ROLLBACK.md`; this runbook covers only what is specific to the
limiter. This change adds **no** database schema, no core patch and no new service.

> Production deployment is a separate, manually approved activity. Do **not**
> deploy, reload services or change configuration without approval.

---

## 1. What changes on production

| Area | Change | Operator action |
|---|---|---|
| Application code | New `local/ulms_auth` classes + settings + lang + version bump | Fast-forward the `main` checkout (see `01-DEPLOY-AND-ROLLBACK.md` §2) |
| Plugin version | `local_ulms_auth` version raised `2026102600` → `2026112700`; `db/upgrade.php` savepoint added | Moodle runs the upgrade on first request after deploy (no schema change) |
| Settings | New admin settings under **Plugins → Local plugins → ULMS Authentication** | No action; defaults are safe (`backend=auto`, `fallback=on`, `ipmode=detect`, `degradedmode=failopen`) |
| Redis | Reused — **already installed and secured**. No install, no config change, no new ACL permission | None as part of this change |
| Nginx | **No change required.** Production does not currently set `real_ip_header` / `set_real_ip_from` / `real_ip_recursive`; the limiter resolves the client IP correctly regardless (see §8) | None; adopting the snippet is optional and human-applied |
| Core lockout | **Unchanged** — remains disabled by deliberate decision | None |
| Secrets | Read from `/etc/ulms/redis-auth.secret` (root:www-data 0640) | Confirm file perms only |

Explicitly required on production: **purge the Moodle caches** after deploy
(`php admin/cli/purge_caches.php`) so the new settings/lang and compiled plugin
code are picked up. No database migration and no Redis modification are required.

---

## 2. Pre-deploy checklist

| # | Check | How to verify |
|---|---|---|
| 1 | Release includes the merge of `feature/redis-login-rate-limit` into `main` | `git log -1 --oneline` on the production checkout |
| 2 | `php -m \| grep -i redis` shows `redis` | On the production host as the FPM PHP version |
| 3 | Redis reachable and the ACL user authenticates (credential via env var, never a command-line argument) | `REDISCLI_AUTH="$(sudo cat /etc/ulms/redis-auth.secret)" redis-cli -h 127.0.0.1 -p 6379 --user ulms_auth PING` → `PONG` |
| 4 | ACL user writes only inside the namespace | `... SET ulms:auth:probe:<ref> 1` → `OK`; `... SET ulms:outside 1` → `NOPERM` |
| 5 | Credential file perms | `ls -l /etc/ulms/redis-auth.secret` → `root www-data 0640` |
| 6 | Backups current | Standard pre-deploy DB + code backup per `01-DEPLOY-AND-ROLLBACK.md` §2 (Steps 1–2) |

> **Credential handling.** Supply the Redis credential through the `REDISCLI_AUTH`
> environment variable (or an interactive prompt), never as `--pass <secret>` on
> the command line — a command-line argument is visible to any local user via the
> process list. Never echo the credential to a terminal that is being logged.

---

## 3. Deployment steps

1. Deploy the new code by fast-forwarding the production Git checkout exactly as
   described in `01-DEPLOY-AND-ROLLBACK.md` §2. That procedure preserves the
   locally modified `config.php`.
2. On the app host, run the Moodle upgrade + cache purge:
   ```bash
   cd /var/www/universityLMS
   sudo -u www-data php admin/cli/upgrade.php --non-interactive
   sudo -u www-data php admin/cli/purge_caches.php
   ```
3. Confirm the settings defaults are active (leave as-is unless tuning is
   approved): **Site administration → Plugins → Local plugins → ULMS
   Authentication** → `ratelimitbackend = auto`, `ratelimitfallback = on`,
   `ratelimitabuseipmode = detect`, `ratelimitdegradedmode = failopen`.
4. Do **not** change Moodle's native lockout settings.
5. Nginx requires **no** change (see §8). If the origin has *not* adopted the
   managed real-IP snippet, that is the expected, verified state and the limiter
   still works correctly.

---

## 4. Post-deployment verification

```bash
# 4.1 PHP extension present (as www-data)
sudo -u www-data php -r 'var_dump(class_exists("Redis"));'

# 4.2 The limiter's own store is reachable with the restricted ACL user.
#     Credential via env var, never on the command line.
REDISCLI_AUTH="$(sudo cat /etc/ulms/redis-auth.secret)" \
  redis-cli -h 127.0.0.1 -p 6379 --user ulms_auth PING        # -> PONG

# 4.3 Counters appear only when failures happen, and they expire.
#     The application ACL user has NO SCAN permission, so enumerate keys with the
#     ROOT-CONTROLLED ADMINISTRATIVE credential (see §5), not the app user.
#     Drive a few obviously-wrong logins against a throwaway account first.
sudo REDISCLI_AUTH="$(sudo cat <root-only-admin-credential>)" \
  redis-cli -h 127.0.0.1 -p 6379 --scan --pattern 'ulms:auth:*'

#     Every key MUST report a positive TTL — a -1 (no expiry) is a defect:
sudo REDISCLI_AUTH="$(sudo cat <root-only-admin-credential>)" \
  redis-cli -h 127.0.0.1 -p 6379 TTL <one-of-the-keys-above>   # -> positive integer

# 4.4 A successful login clears the account-specific counters
#     (keys prefixed account:/combo: for that account disappear; abuseip: stays).
```

Functional smoke test (browser): sign in successfully as a normal user (no 429),
then deliberately fail a login a few times and confirm the branded **429** page
renders on desktop and mobile with a sensible waiting period and a support
reference.

---

## 5. Monitoring (read-only diagnostics)

- **Backend health**: watch for `ratelimit_backend_unavailable`,
  `ratelimit_backend_fallback`, `ratelimit_backend_degraded`, `ratelimit_degraded`
  and `ratelimit_unavailable` in the security log. A steady stream of
  `ratelimit_backend_unavailable` / `ratelimit_backend_degraded` means Redis is
  failing and the file fallback is carrying the load.
- **Security events**: `portal_login_failed` spikes indicate attack traffic; pair
  them with a Redis key scan to see which bucket is absorbing it.
- **Redis health**: monitor `used_memory` against the 256 MB limit and the
  `noeviction` policy. Because keys are namespaced to `ulms:auth:*` and always
  carry a TTL, growth is bounded.
- **Where to look**: `local_ulms_auth` structured events go through
  `local_ulms_auth_log_security_event()` (searchable JSON lines) and are visible
  in the Super Admin audit view.

> **Operational Redis access (verified ACL).** The production application ACL user
> `ulms_auth` can run only the commands the store needs — `PING`, `GET`, `SET`,
> `SETEX`, `INCR`, `EXPIRE`, `TTL`, `PTTL`, `DEL`, `EXISTS`, `EVAL`, `EVALSHA`. It
> deliberately **cannot** run `SCAN` or `INFO`. Do **not** widen the application
> ACL to make diagnostics easier. Use the separate, **root-controlled
> administrative credential** that the operator already holds for key enumeration
> and server introspection:
>
> ```bash
> # Key enumeration (requires SCAN — admin access only)
> sudo REDISCLI_AUTH="$(sudo cat <root-only-admin-credential>)" \
>   redis-cli -h 127.0.0.1 -p 6379 --scan --pattern 'ulms:auth:*'
>
> # Server introspection (requires INFO — admin access only)
> sudo REDISCLI_AUTH="$(sudo cat <root-only-admin-credential>)" \
>   redis-cli -h 127.0.0.1 -p 6379 INFO memory
> ```
>
> The exact location of the administrative credential is operator-held and must
> not be committed to this repository. Never pass it with `--pass`.

> **Diagnostic keys must expire.** Any key you create while testing (a probe, a
> marker) must carry a TTL and be removed afterwards. The namespace is otherwise
> ephemeral, but leaving non-expiring keys behind is a defect:
> ```bash
> # Create any probe with an expiry, then delete it explicitly when finished
> ... SET ulms:auth:probe:<ref> 1 EX 300
> ... DEL ulms:auth:probe:<ref>
> ```

---

## 6. Rollback

The change is safe to neutralise, but **a plain source rollback will hit Moodle's
plugin-version check**: the deployed code records `local_ulms_auth` version
`2026112700`, and checking out the older source (`2026102600`) makes Moodle see a
recorded version **newer than the code**, producing a plugin downgrade error. Do
**not** edit the database version.

Choose one of the following, in order of preference:

### 6a. Backend feature-flag rollback (preferred — no code change, no version issue)

Neutralise the limiter through its own settings while leaving the newer code in
place. The recorded plugin version stays ahead of nothing that matters, so Moodle
is satisfied and no database change is involved.

```bash
cd /var/www/universityLMS
# Option A: disable the limiter entirely
sudo -u www-data php admin/cli/cfg.php --component=local_ulms_auth --name=enableratelimit --set=0
# Option B (if some protection must remain): keep it but use only the local file
# store, bypassing Redis
# sudo -u www-data php admin/cli/cfg.php --component=local_ulms_auth --name=ratelimitbackend --set=file
sudo -u www-data php admin/cli/purge_caches.php
```

To re-enable, set the same setting back to `1` (or `auto`) and purge caches again.
This is instant and fully reversible.

> `cfg.php` refuses (exit status 4) if the setting is hard-set in `config.php`.
> In that case use the corresponding `ULMS_LOGIN_RATELIMIT_*` environment override
> (or change the `config.php` line with a minimal, reviewed patch) rather than
> forcing a database value.

### 6b. Forward-version rollback release (if a real source rollback is required)

If the newer source genuinely must be replaced, re-release the previous behaviour
with a version **equal to or greater** than the recorded one — e.g. restore the
old code and set `$plugin->version` to `2026112700` (or a higher `2026112701`) —
so the code version never decreases. Deploy it with the normal procedure in
`01-DEPLOY-AND-ROLLBACK.md` §3, which copies the production `config.php` back over
the checkout.

```bash
cd /var/www/universityLMS
sudo -u www-data php admin/cli/purge_caches.php
```

### 6c. Clear the limiter's own keys (optional)

Counters are ephemeral and expire on their own; deleting them simply resets the
counting windows. Enumerate with **admin access** (the app user has no `SCAN`),
scoped to the namespace only — **never** `FLUSHALL`/`FLUSHDB`:

```bash
# Only inside the namespace.
sudo REDISCLI_AUTH="$(sudo cat <root-only-admin-credential>)" \
  redis-cli -h 127.0.0.1 -p 6379 --scan --pattern 'ulms:auth:*' | \
  xargs -r sudo REDISCLI_AUTH="$(sudo cat <root-only-admin-credential>)" \
    redis-cli -h 127.0.0.1 -p 6379 DEL
```

### 6d. Nginx

No Nginx change is part of this feature, so no Nginx rollback is required. If the
optional real-IP snippet was adopted separately, follow the rollback section in
`deploy/nginx/ulms-cloudflare-realip.conf`.

### 6e. Redis

No Redis service or configuration change was made, so no Redis rollback is needed.

---

## 7. Native lockout

Moodle's core account lockout stays **disabled**. See
`local/ulms_auth/README.md` §3.1.10 for the coexistence proposal. Do not enable it
as part of this change.

---

## 8. Real client IP / Cloudflare (verified production state)

A direct inspection of the production Nginx configuration returned **no**
`real_ip_header`, `set_real_ip_from` or `real_ip_recursive` directives. The
reference site config in `deploy/nginx/` contains them, but that file has **not**
been applied to the live server, so it must not be treated as the current state.

This is not a defect for the limiter. `classes/local/service/ratelimit/client_ip.php`
performs its own trusted-peer validation and ships the official Cloudflare ranges
as defaults, so:

- **As-is (no rewrite)**: PHP sees the Cloudflare edge address as `REMOTE_ADDR`
  (inside the trusted ranges), so the limiter resolves the true client from
  `CF-Connecting-IP`. A direct-to-origin request has a peer outside the ranges, so
  its forwarded headers are ignored.
- **With the rewrite (optional)**: `REMOTE_ADDR` becomes the true client address,
  which is outside the trusted ranges, so the resolver returns it directly.
  Spoofing is inert in both cases.

Adopting `deploy/nginx/ulms-cloudflare-realip.conf` is an **optional,
human-applied, defence-in-depth** step (see that file for the safe deployment,
range validation, direct-origin spoof test and rollback). The plugin never
modifies production Nginx or firewall settings, and no such change should be made
merely to make the limiter work.
