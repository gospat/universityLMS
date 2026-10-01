# ULMS Production Deploy and Rollback Runbook
## Bells University of Technology — https://learn.bellsuniversity.edu.ng

**Target**: Single Ubuntu VPS, Nginx reverse proxy, PHP-FPM 8.2+, MySQL 8, Systemd cron.
**Webroot**: `/var/www/universityLMS` (symlink to current release).
**Runtime user**: `www-data`.
**Deployment model**: Parallel release directories + atomic symlink swap (zero-downtime when maintenance mode is not strictly required).

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
| 8 | OPcache clear step script ready | Confirm `scripts/opcache_reset.php` (or equivalent FPM-clearing script) is present in the release tree AND callable via HTTP. | [ ] |
| 9 | SSH bastion access confirmed | `ssh $DEPLOY_USER@$SERVER_NAME "whoami"` succeeds from deploy operator workstation BEFORE the deploy window. | [ ] |
| 10 | Slack/Teams notify list ready | On-call engineer, Product owner, DPO, Hosting support are tagged in the pre-deploy message thread. | [ ] |

If any item fails, DO NOT PROCEED. Resolve or escalate.

---

## 2. Atomic Deploy Procedure (12 Steps)

All steps run on the target server `$SERVER_NAME` as `$DEPLOY_USER` (with sudo for chown / systemctl).
Set session variables first:

```bash
export TIMESTAMP=$(date +%Y%m%d-%H%M%S)
export RELEASE_DIR="/var/www/universityLMS-v$TIMESTAMP"
export CURRENT_DIR="/var/www/universityLMS"
export PREVIOUS_LINK=$(readlink -f "$CURRENT_DIR")
export COMMIT_SHA="<full-40-char-sha-from-main>"
echo "RELEASE_DIR=$RELEASE_DIR  PREVIOUS_LINK=$PREVIOUS_LINK  COMMIT=$COMMIT_SHA"
```

### Step 1 — Clone repository into new release directory
```bash
sudo -u www-data git clone --depth 50 https://github.com/<org>/universityLMS.git "$RELEASE_DIR"
cd "$RELEASE_DIR"
sudo -u www-data git checkout "$COMMIT_SHA"
# Verify
sudo -u www-data git rev-parse HEAD   # must equal $COMMIT_SHA
```

### Step 2 — Composer install (production, no dev deps)
```bash
cd "$RELEASE_DIR"
sudo -u www-data composer install --no-dev --optimize-autoloader --no-interaction
# Exit must be 0. If Composer OOMs: COMPOSER_MEMORY_LIMIT=-1 composer install ...
```

### Step 3 — Node build (nvm use 22, npm ci, grunt sass)
```bash
cd "$RELEASE_DIR"
export NVM_DIR="$HOME/.nvm" && [ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh"
nvm use lts/jod    # Node 22+
npm ci             # Reproduce lockfile exactly
npx grunt sass     # Compile SCSS -> CSS
# Verify
ls -la theme/ulms_university/style/*.css   # timestamps must be $TIMESTAMP-approx
```

### Step 4 — Symlink shared assets and configuration
```bash
# Copy (or symlink) the production .env into the new release
sudo cp /var/www/.env "$RELEASE_DIR/.env"
sudo chown www-data:www-data "$RELEASE_DIR/.env"
sudo chmod 640 "$RELEASE_DIR/.env"

# Symlink moodledata (never clone moodledata!)
ln -sfn /var/moodledata "$RELEASE_DIR/moodledata"
```

### Step 5 — Moodle upgrade dry-run
```bash
cd "$RELEASE_DIR"
sudo -u www-data php admin/cli/upgrade.php --is-already-installed --non-interactive --dry-run
# EXPECTED: "No upgrade needed" OR specific migration plan printed.
# If FAILS here: stop, fix on staging, never migrate blind in production.
```

### Step 6 — Apply ownership to entire release tree
```bash
sudo chown -R www-data:www-data "$RELEASE_DIR"
# Verify a random sample
stat -c "%U:%G" "$RELEASE_DIR/config.php"   # www-data:www-data
```

### Step 7 — PRC POST-UPGRADE verify pass (70+/78)
```bash
cd "$RELEASE_DIR"
sudo -u www-data php admin/cli/check_environment.php
# Record the final score. Minimum 70/78 required to continue.
```

### Step 8 — Enable maintenance mode on CURRENT release
```bash
cd "$CURRENT_DIR"
sudo -u www-data php admin/cli/maintenance.php --enable
# Optional: also flip nginx 503 -> MAINTENANCE.html for anonymous users.
```

### Step 9 — Apply upgrade changes (real run) + atomic symlink swap
```bash
# Run real upgrade while current site is in maintenance mode
cd "$RELEASE_DIR"
sudo -u www-data php admin/cli/upgrade.php --non-interactive

# ATOMIC SWAP — single ln -sfn call, this is the cutover
sudo ln -sfn "$RELEASE_DIR" /var/www/universityLMS
# Verify swap
readlink -f /var/www/universityLMS   # must print $RELEASE_DIR
```

### Step 10 — Graceful Nginx reload + OPcache reset
```bash
sudo nginx -t  # FAIL FAST here if config broken (stop, rollback)
sudo systemctl reload nginx

# Clear OPcache so new PHP files are compiled from new release
sudo -u www-data php -r 'opcache_reset();'
# AND hit the web endpoint (FPM pool uses separate OPcache memory)
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
```

### Step 12 — Disable maintenance mode + notify
```bash
cd "$CURRENT_DIR"
sudo -u www-data php admin/cli/maintenance.php --disable

# Announce on Slack/Teams: "Deploy $TIMESTAMP ($COMMIT_SHA) complete. Release: $RELEASE_DIR"
```

---

## 3. Rollback Procedure (6 Steps)

Execute if health checks fail, PRC degrades post-deploy, or stakeholders escalate.

```bash
export TIMESTAMP=$(date +%Y%m%d-%H%M%S)
export CURRENT_LINK=$(readlink -f /var/www/universityLMS)
export PREVIOUS_DIR="<path of last-known-good, e.g. /var/www/universityLMS-v20260920-080000>"
# Confirm last-known-good exists and is intact:
ls "$PREVIOUS_DIR/config.php" && echo "Previous release directory present"
```

### Step 1 — Enable maintenance mode
```bash
cd /var/www/universityLMS
sudo -u www-data php admin/cli/maintenance.php --enable
```

### Step 2 — Atomic symlink swap to PREVIOUS release
```bash
sudo ln -sfn "$PREVIOUS_DIR" /var/www/universityLMS
readlink -f /var/www/universityLMS    # must equal $PREVIOUS_DIR
```

### Step 3 — Graceful Nginx reload
```bash
sudo nginx -t && sudo systemctl reload nginx
```

### Step 4 — OPcache reset
```bash
sudo -u www-data php -r 'opcache_reset();'
curl -skS https://learn.bellsuniversity.edu.ng/scripts/opcache_reset.php
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

### Step 6 — Database rollback (ONLY if DB migration incompatibility suspected)
Skip this step if the rollback succeeded with only a code swap.
```bash
# STOP WRITE TRAFFIC (keep maintenance on)
sudo systemctl stop php8.2-fpm      # pause PHP entirely
# Restore the pre-deploy mysqldump
gunzip -c /var/backups/ulms/ulms_predeploy_<original-timestamp>.sql.gz | mysql ulms_prod
# Restart FPM
sudo systemctl start php8.2-fpm
```

### Step 7 — Disable maintenance mode + notify
```bash
sudo -u www-data php admin/cli/maintenance.php --disable
# Announce: "ROLLBACK executed — release $CURRENT_LINK reverted to $PREVIOUS_DIR.
#           Root cause investigation opened. Pending: new deploy with fix."
```

---

## 4. Post-deploy Verification (9 Verifications)

Run within 10 minutes of Step 12 completion. Record results in the deploy ticket.

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
| T6 | Asset URLs (CSS/JS) return 404 after deploy | New release not owned by www-data OR SCSS build did not run | 1. `sudo chown -R www-data:www-data $RELEASE_DIR` 2. Re-run `npx grunt sass` in $RELEASE_DIR 3. Purge caches |
| T7 | Curl healthz returns **500** after opcache_reset | OPcache restarted with invalidated filemap, `stat` cache stale | 1. Wait 10s + retry 2. `systemctl restart php8.2-fpm` 3. If still 500, tail `/var/log/nginx/error.log` for actual PHP stack trace |
| T8 | Composer install exits 1 on "Killed" (no error) | Composer ran out of memory | Re-run: `COMPOSER_MEMORY_LIMIT=-1 sudo -u www-data composer install --no-dev --optimize-autoloader` |
| T9 | MySQL `ERROR 1205 (HY000): Lock wait timeout exceeded` during upgrade | Long-running SELECTs / cron jobs block DDL | 1. `mysql -e "SHOW FULL PROCESSLIST;"` find long-running query 2. `mysql -e "KILL <id>;"` 3. Re-run upgrade |
| T10 | Nginx reload fails `nginx -t` → `invalid number of arguments in "try_files"` | Syntax error in nginx site conf | 1. Restore previous working `.conf` from `/etc/nginx/sites-available/` backup 2. `nginx -t` 3. Re-apply changes carefully |
