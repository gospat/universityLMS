# ULMS Production Deploy and Rollback Runbook
## Bells University of Technology — https://learn.bellsuniversity.edu.ng

**Target**: Single Ubuntu VPS, Nginx reverse proxy, PHP-FPM 8.2+, MySQL 8, Systemd cron.
**Application path**: `/var/www/universityLMS` — the **live application directory itself**, not a symlink.
**Working copy model (verified)**: a **Git checkout on the `main` branch**, with a
**locally modified production `config.php`**. There is **no** parallel-release-directory
and **no** symlink-swap architecture in production; do not assume one exists.
**Runtime user**: `www-data`.
**Cutover model**: in-place fast-forward of the working tree (maintenance mode around it).

> **Verified production environment (do not assume otherwise).** The production
> application lives at `/var/www/universityLMS` and runs directly from that Git
> checkout on `main`. `config.php` there is locally modified and is **not** managed
> by the upstream branch, so it must never be overwritten by a checkout. All
> deployment steps below preserve the existing `config.php`, the Moodle data
> directory, the database, user sessions and the Redis credentials.

---

## 1. Pre-flight Checklist (10 Items)

Complete EVERY item before executing the deploy procedure. Mark each as done on the deployment ticket.

| # | Check | How to verify | Pass? |
|---|-------|---------------|-------|
| 1 | Database backup taken | `mysqldump --single-transaction --routines --triggers ulms_prod > /var/backups/ulms/ulms_predeploy_$TIMESTAMP.sql.gz` and confirm file size > 100 MB and sha256 written to ticket. | [ ] |
| 2 | Gitleaks scan ran on the deploy commit | Local: `gitleaks detect --no-git --source . -v` OR CI pipeline job "gitleaks" = green. No HIGH/CRITICAL findings. | [ ] |
| 3 | `composer install` clean on staging | Staging `composer install --no-dev --optimize-autoloader` exits 0, no abandoned packages blocklisted. | [ ] |
| 4 | Production Readiness Check (PRC) pass 70/78+ on staging | Run `php admin/cli/check_environment.php` on staging OR the PRC script; record final score in ticket. | [ ] |
| 5 | Maintenance mode prepared | Confirm `/var/www/MAINTENANCE.html` exists and is readable by nginx; nginx 503 snippet loaded. | [ ] |
| 6 | `.env` production values verified — NOT using root DB user | `cat /var/www/.env` → `DB_USER=ulms_rw` (or equivalent non-root account). Reject deploy if DB user is `root`. | [ ] |
| 7 | Resend API key rotated / NOT a development key | `.env` → `RESEND_KEY=re_prod_*` NOT `re_test_*` or `resend_sk_test_*`. Rotate if unsure. | [ ] |
| 8 | OPcache clear step script ready | Confirm `scripts/opcache_reset.php` (or equivalent FPM-clearing script) is present in the application tree AND callable via HTTP. | [ ] |
| 9 | SSH bastion access confirmed | `ssh $DEPLOY_USER@$SERVER_NAME "whoami"` succeeds from deploy operator workstation BEFORE the deploy window. | [ ] |
| 10 | Slack/Teams notify list ready | On-call engineer, Product owner, DPO, Hosting support are tagged in the pre-deploy message thread. | [ ] |

If any item fails, DO NOT PROCEED. Resolve or escalate.

---

## 2. Production Deploy Procedure (11 Steps — in-place Git fast-forward)

All steps run on the target server `$SERVER_NAME` as `$DEPLOY_USER` (with sudo for
ownership / systemctl). The application is updated **in place** at
`/var/www/universityLMS`; there is no release directory and no symlink to swap.
Set session variables first:

```bash
export TIMESTAMP=$(date +%Y%m%d-%H%M%S)
export APP_DIR="/var/www/universityLMS"
export BACKUP_DIR="/var/backups/ulms"
export COMMIT_SHA="<full-40-char-sha-from-main>"
echo "APP_DIR=$APP_DIR  BACKUP_DIR=$BACKUP_DIR  COMMIT=$COMMIT_SHA"
```

### Step 1 — Record the current commit and back up the production `config.php`

The production `config.php` is locally modified and is the single most important
file to protect. Back it up **outside** the application tree before touching Git.

```bash
sudo install -d -m 0750 "$BACKUP_DIR"
cd "$APP_DIR"
sudo -u www-data git rev-parse HEAD | sudo tee "$BACKUP_DIR/previous-commit.txt"
sudo cp -a "$APP_DIR/config.php" "$BACKUP_DIR/config.php.$TIMESTAMP"
sudo sha256sum "$BACKUP_DIR/config.php.$TIMESTAMP" | sudo tee "$BACKUP_DIR/config.php.$TIMESTAMP.sha256"
```

### Step 2 — Back up the database

```bash
mysqldump --single-transaction --routines --triggers ulms_prod \
  | gzip > "$BACKUP_DIR/ulms_predeploy_$TIMESTAMP.sql.gz"
ls -l "$BACKUP_DIR/ulms_predeploy_$TIMESTAMP.sql.gz"   # confirm non-trivial size
```

### Step 3 — Fetch and inspect the incoming change

```bash
cd "$APP_DIR"
sudo -u www-data git fetch --prune origin
# Does the incoming change touch config.php? It must NOT be silently overwritten.
sudo -u www-data git diff --name-only HEAD origin/main | grep -qx 'config.php' \
  && echo "WARNING: upstream changes config.php — reconcile it manually, then restore the production copy after merge" \
  || echo "OK: upstream does not touch config.php"
sudo -u www-data git log --oneline HEAD..origin/main
```

### Step 4 — Enter maintenance mode BEFORE the cutover

```bash
cd "$APP_DIR"
sudo -u www-data php admin/cli/maintenance.php --enable
# Optional: also flip nginx 503 -> MAINTENANCE.html for anonymous users.
```

### Step 5 — Fast-forward the working tree to `main`

A fast-forward never creates a merge commit, and Git will **not** overwrite a
locally-modified tracked file unless the incoming change touches it (checked in
Step 3).

```bash
cd "$APP_DIR"
sudo -u www-data git checkout main
sudo -u www-data git merge --ff-only origin/main
# Verify
sudo -u www-data git rev-parse HEAD   # must equal $COMMIT_SHA
```

### Step 6 — Restore the production `config.php` (belt and braces)

Even when Steps 3/5 left `config.php` untouched, re-assert the production copy so a
checkout can never have replaced it.

```bash
sudo cp -a "$BACKUP_DIR/config.php.$TIMESTAMP" "$APP_DIR/config.php"
sudo chown www-data:www-data "$APP_DIR/config.php"
sudo chmod 0640 "$APP_DIR/config.php"
sudo -u www-data php -r 'define("CLI_SCRIPT", true); require "/var/www/universityLMS/config.php"; echo "config OK\n";'
```

### Step 7 — Composer install (production, no dev deps)

```bash
cd "$APP_DIR"
sudo -u www-data composer install --no-dev --optimize-autoloader --no-interaction
# Exit must be 0. If Composer OOMs: COMPOSER_MEMORY_LIMIT=-1 composer install ...
```

### Step 8 — Rebuild theme CSS only if the theme changed

```bash
cd "$APP_DIR"
PREVIOUS_SHA="$(sudo cat "$BACKUP_DIR/previous-commit.txt")"
if ! sudo -u www-data git diff --quiet "$PREVIOUS_SHA" HEAD -- theme/ulms_university; then
  export NVM_DIR="$HOME/.nvm" && [ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh"
  nvm use lts/jod    # Node 22+
  npm ci             # Reproduce lockfile exactly
  npx grunt sass     # Compile SCSS -> CSS
  ls -la theme/ulms_university/style/*.css   # timestamps must be $TIMESTAMP-approx
fi
```

### Step 9 — Run the Moodle upgrade (dry-run first, then real)

```bash
cd "$APP_DIR"
sudo -u www-data php admin/cli/upgrade.php --is-already-installed --non-interactive --dry-run
# EXPECTED: "No upgrade needed" OR a specific migration plan.
# If it FAILS here: stop, fix on staging, never migrate blind in production.
sudo -u www-data php admin/cli/upgrade.php --non-interactive
sudo -u www-data php admin/cli/purge_caches.php
```

### Step 10 — Leave maintenance mode, reload Nginx, reset OPcache

```bash
cd "$APP_DIR"
sudo -u www-data php admin/cli/maintenance.php --disable
sudo nginx -t              # FAIL FAST — stop and rollback if this errors
sudo systemctl reload nginx
sudo -u www-data php -r 'opcache_reset();'
curl -skS https://learn.bellsuniversity.edu.ng/scripts/opcache_reset.php
```

### Step 11 — Health check: 3 consecutive HTTP 200

```bash
for i in 1 2 3; do
  CODE=$(curl -skS -o /dev/null -w "%{http_code}" https://learn.bellsuniversity.edu.ng/healthz.php)
  echo "healthz attempt $i: $CODE"
  [ "$CODE" != "200" ] && echo "HEALTHZ FAILED -> ROLLBACK REQUIRED" && exit 1
  sleep 2
done
# Announce on Slack/Teams: "Deploy $TIMESTAMP ($COMMIT_SHA) complete at $APP_DIR"
```

---

## 3. Rollback Procedure (7 Steps)

Execute if health checks fail, PRC degrades post-deploy, or stakeholders escalate.

```bash
export TIMESTAMP=$(date +%Y%m%d-%H%M%S)
export APP_DIR="/var/www/universityLMS"
export BACKUP_DIR="/var/backups/ulms"
export PREVIOUS_SHA="$(sudo cat "$BACKUP_DIR/previous-commit.txt")"
echo "Rolling back $APP_DIR to $PREVIOUS_SHA"
```

### Step 1 — Enter maintenance mode

```bash
cd "$APP_DIR"
sudo -u www-data php admin/cli/maintenance.php --enable
```

### Step 2 — Back up the current `config.php`, then check out the previous commit

Rolling back to the previous commit restores the previous **code**. The production
`config.php` is preserved by re-copying the backed-up copy afterwards.

```bash
cd "$APP_DIR"
sudo cp -a "$APP_DIR/config.php" "$BACKUP_DIR/config.php.pre-rollback.$TIMESTAMP"
sudo -u www-data git checkout "$PREVIOUS_SHA"
sudo cp -a "$BACKUP_DIR/config.php.$TIMESTAMP" "$APP_DIR/config.php" 2>/dev/null \
  || sudo cp -a "$BACKUP_DIR"/config.php.* "$APP_DIR/config.php"
sudo chown www-data:www-data "$APP_DIR/config.php"
sudo -u www-data git rev-parse HEAD    # must equal $PREVIOUS_SHA
```

> **Moodle plugin version downgrade — read this before rolling the code back.**
> Moodle records each plugin's installed version in `config_plugins`. If the newer
> code raised a plugin's version (for example `local_ulms_auth` `2026102600` →
> `2026112700`), checking out the older source alone makes Moodle see a recorded
> version **newer than the code**, which triggers a "plugin downgrade" error. Do
> **not** edit the database version. Use one of these instead:
>
> - **Preferred — backend feature-flag rollback (no code change, no version
>   issue).** Keep the newer code deployed and neutralise the feature through its
>   settings: `enableratelimit = 0` (or `ratelimitbackend = file`). This is
>   instant, reversible, and leaves the recorded version ahead of the code, which
>   Moodle accepts. See `04-LOGIN-RATE-LIMITER.md` §6.
> - **Or — forward-version rollback release.** Re-release the previous behaviour
>   with a version **equal to or greater** than the recorded one (e.g. re-tag the
>   old code as `2026112700`, or bump to `2026112701`), so the code version never
>   goes backwards. This is the correct approach when a real source rollback is
>   unavoidable.
>
> Never run an arbitrary `UPDATE mdl_config_plugins SET value=...` to force a
> downgrade.

### Step 3 — Composer install for the restored code

```bash
cd "$APP_DIR"
sudo -u www-data composer install --no-dev --optimize-autoloader --no-interaction
```

### Step 4 — Reload Nginx and reset OPcache

```bash
sudo nginx -t && sudo systemctl reload nginx
sudo -u www-data php -r 'opcache_reset();'
curl -skS https://learn.bellsuniversity.edu.ng/scripts/opcache_reset.php
sudo -u www-data php admin/cli/purge_caches.php
```

### Step 5 — Health check (3x 200)

```bash
for i in 1 2 3; do
  CODE=$(curl -skS -o /dev/null -w "%{http_code}" https://learn.bellsuniversity.edu.ng/healthz.php)
  echo "healthz attempt $i: $CODE"
  [ "$CODE" != "200" ] && echo "ROLLBACK HEALTHZ STILL FAILING -> escalate to P0"
  sleep 2
done
```

### Step 6 — Database rollback (ONLY if a DB migration incompatibility is suspected)

Skip this step if the rollback succeeded with only a code revert.
```bash
# STOP WRITE TRAFFIC (keep maintenance on)
sudo systemctl stop php8.2-fpm      # pause PHP entirely
# Restore the pre-deploy mysqldump
gunzip -c "$BACKUP_DIR"/ulms_predeploy_<original-timestamp>.sql.gz | mysql ulms_prod
# Restart FPM
sudo systemctl start php8.2-fpm
```

### Step 7 — Leave maintenance mode + notify

```bash
cd "$APP_DIR"
sudo -u www-data php admin/cli/maintenance.php --disable
# Announce: "ROLLBACK executed — $APP_DIR reverted to $PREVIOUS_SHA.
#           Root cause investigation opened. Pending: new deploy with fix."
```

---

## 4. Post-deploy Verification (9 Verifications)

Run within 10 minutes of Step 11 completion. Record results in the deploy ticket.

| # | Verification | How to run | Pass criteria |
|---|-------------|------------|---------------|
| V1 | Portal endpoints return 200/303 | `curl -skS -o /dev/null -w "%{http_code}" https://learn.bellsuniversity.edu.ng/` | 200 or 303 (redirect to login) |
| V2 | Sign-in banner present | `curl -skS https://learn.bellsuniversity.edu.ng/login/index.php | grep -i "bells\|university\|welcome"` | Non-empty match |
| V3 | X-Render-Time header sane | `curl -skS -D - -o /dev/null https://learn.bellsuniversity.edu.ng/my/ \| grep -i x-render-time` | Value <= 500 (ms) |
| V4 | PRC rerun >= 75/78 | `sudo -u www-data php admin/cli/check_environment.php` | Score recorded, >= 75 |
| V5 | Zero PHP Fatal errors last 2 min | `sudo journalctl -u php8.2-fpm --since "2 minutes ago" \| grep -i "fatal\|uncaught" \| wc -l` | Count = 0 |
| V6 | Cron service active | `systemctl is-active cron.service` AND `sudo -u www-data php admin/cli/cron.php --status 2>/dev/null \| head -5` | `active` + last run < 2 min ago |
| V7 | MySQL processlist healthy | `mysql -e "SHOW PROCESSLIST;"` + `mysql -e "SHOW STATUS LIKE 'Threads_connected';"` | No zombie queries; Threads_connected < 80% of max_connections |
| V8 | Nginx 2xx/3xx rate > 95% (5 min) | `awk -v d="$(date -d '5 minutes ago' +%d/%b/%Y:%H:%M)" '$4 > "["d' /var/log/nginx/access.log \| awk '{print $9}' \| sort \| uniq -c` | (2xx+3xx)/total > 0.95 |
| V9 | Receipt issued (smoke test via admin portal) | Login as site admin → Finance → Issue test receipt PDF OR call the smoke-test CLI | PDF generated; no exception logged |

---

## 5. Troubleshooting Matrix (10 Symptoms)

| # | Symptom | Likely root cause | Fix steps |
|---|---------|-------------------|-----------|
| T1 | **502 Bad Gateway** on all pages | php-fpm not running or crashed | 1. `systemctl status php8.2-fpm` 2. `systemctl restart php8.2-fpm` 3. If crash loop, check `/var/log/php8.2-fpm.log` + rollback (Sec. 3) |
| T2 | Sign-in / branding banner not showing after deploy | OPcache + MUC stale cache | 1. Run `php admin/cli/purge_caches.php` 2. Hit `/scripts/opcache_reset.php` 3. Hard refresh browser (Shift+reload) |
| T3 | **404** on privacy / consent / routes that should route to `index.php` | Nginx `try_files` missing `/index.php?$query_string` fallback | 1. Edit `/etc/nginx/sites-available/ulms.conf` → `try_files $uri $uri/ /index.php?$query_string;` 2. `nginx -t` + `systemctl reload nginx` |
| T4 | `upgrade.php` fails immediately on DB lock | Stale `upgrade.lock` left from prior interrupted upgrade | 1. `ls /var/moodledata/upgrade.lock` 2. `rm /var/moodledata/upgrade.lock` 3. Re-run `php admin/cli/upgrade.php --non-interactive` |
| T5 | PHP-FPM OOM / 504 spikes under light load | `pm.max_children` too low for traffic OR memory_limit small | 1. `vi /etc/php/8.2/fpm/pool.d/www.conf` → raise `pm.max_children` (formula: RAM_GB * 10 / per_process_MB) 2. Raise `memory_limit` in `php.ini` 3. `systemctl restart php8.2-fpm` |
| T6 | Asset URLs (CSS/JS) return 404 after deploy | New code not owned by www-data OR SCSS build did not run | 1. `sudo chown -R www-data:www-data /var/www/universityLMS` 2. Re-run `npx grunt sass` in `/var/www/universityLMS` 3. Purge caches |
| T7 | Curl healthz returns **500** after opcache_reset | OPcache restarted with invalidated filemap, `stat` cache stale | 1. Wait 10s + retry 2. `systemctl restart php8.2-fpm` 3. If still 500, tail `/var/log/nginx/error.log` for actual PHP stack trace |
| T8 | Composer install exits 1 on "Killed" (no error) | Composer ran out of memory | Re-run: `COMPOSER_MEMORY_LIMIT=-1 sudo -u www-data composer install --no-dev --optimize-autoloader` |
| T9 | MySQL `ERROR 1205 (HY000): Lock wait timeout exceeded` during upgrade | Long-running SELECTs / cron jobs block DDL | 1. `mysql -e "SHOW FULL PROCESSLIST;"` find long-running query 2. `mysql -e "KILL <id>;"` 3. Re-run upgrade |
| T10 | Nginx reload fails `nginx -t` → `invalid number of arguments in "try_files"` | Syntax error in nginx site conf | 1. Restore previous working `.conf` from `/etc/nginx/sites-available/` backup 2. `nginx -t` 3. Re-apply changes carefully |
