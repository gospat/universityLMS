# ULMS Login Rate Limiter — Deploy, Verify, Monitor, Rollback
## Bells University of Technology — https://learn.bellsuniversity.edu.ng

**Scope**: the Redis-backed login rate limiter in `local/ulms_auth`
(`classes/local/service/login_rate_limit.php` +
`classes/local/service/ratelimit/*`).
**Design, policy and configuration reference**: see
`local/ulms_auth/README.md` §3.1.
**Deployment model**: normal release deploy (parallel release dir + symlink swap);
this change adds **no** database schema, no core patch and no new service.

> Production deployment is a separate, manually approved activity. Do **not**
> deploy, reload services or change configuration without approval.

---

## 1. What changes on production

| Area | Change | Operator action |
|---|---|---|
| Application code | New `local/ulms_auth` classes + settings + lang + version bump | Ship with the release |
| Plugin version | `local_ulms_auth` version bumped (`2026112700`); `db/upgrade.php` savepoint added | Moodle runs the upgrade on first request after deploy (no schema change) |
| Settings | New admin settings page under **Plugins → Local plugins → ULMS Authentication** | No action; defaults are safe (`backend=auto`, `fallback=on`) |
| Redis | Reused — **already installed and secured**. No install, no config change | None as part of this change |
| Nginx | Optional: adopt the managed trusted-proxy snippet | Only if not already using `real_ip_header CF-Connecting-IP` (see §3) |
| Core lockout | **Unchanged** — remains disabled by deliberate decision | None |
| Secrets | Read from `/etc/ulms/redis-auth.secret` (root:www-data 0640) | Confirm file perms only |

Explicitly required on production: **purge the Moodle caches** after deploy
(`php admin/cli/purge_caches.php`) so the new settings/lang and compiled plugin
code are picked up. No database migration and no Redis modification are required.

---

## 2. Pre-deploy checklist

| # | Check | How to verify |
|---|---|---|
| 1 | Release includes the `feature/redis-login-rate-limiter` merge to `main` | `git log -1 --oneline` on the release tree |
| 2 | `php -m \| grep -i redis` shows `redis` | On the production host as the FPM PHP version |
| 3 | Redis reachable and ACL user authenticates | `redis-cli -h 127.0.0.1 -p 6379 --user ulms_auth --pass "$(sudo cat /etc/ulms/redis-auth.secret)" PING` → `PONG` |
| 4 | ACL user can write only inside the namespace | `... SET ulms:auth:probe 1` → `OK`; `... SET ulms:outside 1` → `NOPERM` |
| 5 | Credential file perms | `ls -l /etc/ulms/redis-auth.secret` → `root www-data 0640` |
| 6 | Backups current | Standard pre-deploy DB + code backup per the main runbook |

---

## 3. Deployment steps

1. Deploy the release as usual (`scripts/DEPLOYMENT_RUNBOOKS/01-DEPLOY-AND-ROLLBACK.md`).
2. On the app host, run the Moodle upgrade + cache purge:
   ```bash
   cd /var/www/universityLMS
   sudo -u www-data php admin/cli/upgrade.php --non-interactive
   sudo -u www-data php admin/cli/purge_caches.php
   ```
3. Confirm the settings defaults are active (leave as-is unless tuning is
   approved): **Site administration → Plugins → Local plugins → ULMS
   Authentication** → `ratelimitbackend = auto`, `ratelimitfallback = on`.
4. (Only if the origin does not already rewrite the client IP) adopt the managed
   Nginx snippet — see `deploy/nginx/ulms-cloudflare-realip.conf` for the full
   deployment, verification and rollback instructions for that file.
5. Do **not** change Moodle's native lockout settings.

---

## 4. Post-deployment verification

```bash
# 4.1 PHP extension + Redis auth from the app host (as www-data)
sudo -u www-data php -r 'var_dump(class_exists("Redis"));'

# 4.2 The limiter's own store is reachable with the restricted ACL user
redis-cli -h 127.0.0.1 -p 6379 --user ulms_auth \
  --pass "$(sudo cat /etc/ulms/redis-auth.secret)" PING        # -> PONG

# 4.3 Counters appear only when failures happen, and expire
#     (drive a few obviously-wrong logins against a throwaway account first)
redis-cli -h 127.0.0.1 -p 6379 --user ulms_auth \
  --pass "$(sudo cat /etc/ulms/redis-auth.secret)" \
  --scan --pattern 'ulms:auth:*'
# Each key MUST report a positive TTL — a -1 (no expiry) is a defect:
redis-cli ... TTL <one-of-the-keys-above>          # -> positive integer

# 4.4 A successful login clears the account-specific counters
#     (keys prefixed account:/combo: for that account disappear; abuseip: stays)
```

Functional smoke test (browser): sign in successfully as a normal user (no 429),
then deliberately fail a login a few times and confirm the branded **429** page
renders on desktop and mobile with a sensible waiting period and a support
reference.

---

## 5. Monitoring

- **Backend health**: watch for `ratelimit_backend_unavailable`,
  `ratelimit_backend_fallback` and `ratelimit_unavailable` in the security log.
  A steady stream of `ratelimit_backend_unavailable` means Redis is unreachable
  and the file fallback is carrying the load.
- **Security events**: `portal_login_failed` spikes indicate attack traffic;
  pair with the Redis key scan above to see which bucket is absorbing it.
- **Redis health**: monitor `used_memory` against the 256 MB limit and the
  `noeviction` policy. Because keys are namespaced to `ulms:auth:*` and always
  carry a TTL, growth is bounded; a persistently growing `ulms:auth:*` count is
  itself a signal of sustained abuse.
- **Where to look**: `local_ulms_auth` structured events go through
  `local_ulms_auth_log_security_event()` (searchable JSON lines) and are visible
  in the Super Admin audit view.

---

## 6. Rollback

The change is **safe to revert by code only** — there is no schema to unwind.

1. Redeploy the previous known-good release and swap the symlink (standard
   rollback in `01-DEPLOY-AND-ROLLBACK.md`).
2. Purge caches:
   ```bash
   sudo -u www-data php admin/cli/purge_caches.php
   ```
3. Optionally clear the limiter's own keys (they are ephemeral and will expire
   on their own; deleting them simply resets the counting windows):
   ```bash
   # Only inside the namespace; never FLUSHALL/FLUSHDB.
   redis-cli -h 127.0.0.1 -p 6379 --user ulms_auth \
     --pass "$(sudo cat /etc/ulms/redis-auth.secret)" \
     --scan --pattern 'ulms:auth:*' | \
     xargs -r redis-cli -h 127.0.0.1 -p 6379 --user ulms_auth \
       --pass "$(sudo cat /etc/ulms/redis-auth.secret)" DEL
   ```
4. If the trusted-proxy snippet was adopted, follow the rollback section inside
   `deploy/nginx/ulms-cloudflare-realip.conf`, then `nginx -t && systemctl reload nginx`.
5. No Redis service change was made, so no Redis rollback is needed.

---

## 7. Native lockout

Moodle's core account lockout stays **disabled**. See
`local/ulms_auth/README.md` §3.1.8 for the coexistence proposal. Do not enable
it as part of this change.
