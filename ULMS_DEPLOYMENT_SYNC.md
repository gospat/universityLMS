# ULMS Deployment & Production Runbook

The single source of truth for deploying, updating, hardening, and running a
UNIVERSITY LMS production instance.  Covers: architecture, the 3-level config
hierarchy, step-by-step first deploy + incremental updates, rollback, the
automated 52-assertion Production Readiness Checker (PRC), cron, maintenance
scripts, go-live wipe, Kortext + Resend setup, ops monitoring, and
troubleshooting.

> **Every section below is verified against commit `b46a1fe8` — the last known
> production-ready commit where `production_readiness_check.php` returned
> 52/52 PASS + exit code 0 on a live environment.**

---

## 1. Architecture Overview

```
                        ┌──────────────────────────────────────┐
                        │           Web / HTTPS Edge           │
                        │  (Nginx / Apache, TLS termination)   │
                        └─────────────────────┬────────────────┘
                                              │
 ┌────────────────────────────────────────────┼────────────────────────────────────────────┐
 │ ULMS Moodle 4.5 App Root                   │                                            │
 │                                            ▼                                            │
 │  config.php (boot: .env parser → ulms_env → $CFG defaults)  tier = DB → ENV → DEFAULT  │
 │                                                                                          │
 │  ┌───────────────┬────────────────┬────────────────┬─────────────────┬──────────────┐  │
 │  │ ulms_auth     │ ulms_dashboard │ ulms_academics │ local/ulms_mail │ ulms_kortext │  │
 │  │ sign-in/      │ 4 role portals │ Colleges/Depts/ │ Resend HTTP API │ Kortext REST │  │
 │  │ password reset│ (Stu/Lec/Adm/ │ Programmes/     │ transport:      │ Adoption     │  │
 │  │ rate limits   │ SA) + analytics│ Courses +      │ Symfony→cURL    │ Entitlement  │  │
 │  │ force pw chg │ CRUD provision-│ CSV/XLSX bulk  │ duck-typed 3-tier;idempotency    │  │
 │  │ audit events  │ ing + bulk CSV │ import +       │ pw resets,      │ cron sync     │  │
 │  │ clean routes  │ CLI ops scripts│ programme<->   │ welcome,         │ LTI config    │  │
 │  └───────┬───────┴───────┬────────┘ moodle courses  │ receipts       └──────┬────────┘  │
 │          │               │          mappings        └──────────┬─────────────┘            │
 └──────────┼───────────────┼────────────────────────────────────┼──────────────────────────┘
            │               ▼                                    │
            │    ┌─────────────────────────┐     ┌──────────────▼──────────────┐
            │    │ Moodle DB (mysqli 8.0+) │     │ moodledata (OUTSIDE webroot)│
            │    │ + ULMS custom tables    │     │ cache, sessions, backups,   │
            │    │ (install.xml per plugin)│     │ Kortext import CSV, logs    │
            │    └─────────────┬───────────┘     └──────────────┬──────────────┘
            │                  │                                │
            │         ┌────────▼─────────────────────────────────▼─────────┐
            │         │  ULMS CLI jobs (cron every minute → run_*.sh)       │
            │         │  · admin/cli/cron.php (Moodle core background tasks)│
            │         │  · Kortext adoption ↔ entitlement sync cron         │
            │         │  · Exam batch autograde cron                         │
            │         │  · (optional) log rotation / backup smoke           │
            │         └────────────────────────────────────────────────────┘
            │
            ▼
   External APIs over HTTPS 443
   · Resend       api.resend.com          (transactional email – HTTP API)
   · Kortext      api.kortext.co.uk       (digital textbook entitlements)
   · (future)     ALAT Pay by WEMA        (inline Flow B payment gateway)
```

---

## 2. Pre-deployment Checklist

Run through this list **before** attempting the first install on any new host.

| # | Gate | How to verify |
|---|---|---|
| 2.1 | PHP 8.2+ with required extensions | `php -m \| grep -E 'mysqli|curl|mbstring|json|xml|zip|gd|intl|opcache'` — **at minimum `mysqli + curl` MUST be present.** |
| 2.2 | MySQL 8.0 / MariaDB 10.6+ | `mysqladmin version` → utf8mb4 default collation.  Create a DB + user with CREATE/ALTER/INDEX/SELECT/INSERT/UPDATE/DELETE. |
| 2.3 | `moodledata` directory created outside webroot, writable by webserver user, with `.htaccess` denying direct access | `mkdir -p /var/lib/ulms/moodledata && chown www-data:www-data /var/lib/ulms/moodledata && chmod 0750 /var/lib/ulms/moodledata` |
| 2.4 | Outbound HTTPS on 443 to `api.resend.com` (and `api.kortext.co.uk` when enabled) | `curl -I https://api.resend.com` → non-zero status (401 Unauthorized is fine, it proves the endpoint is reachable). |
| 2.5 | Shell user can write to repo root + run `php`, `rsync`, `flock` | `php -v`, `rsync --version`, `flock --version` all return 0 exit codes. |
| 2.6 | (Optional) Composer 2.x available **or** `composer.phar` placed in repo root | **Optional.**  Resend + core work without Composer vendor via cURL duck-typed fallback; Symfony HttpClient connection pooling is only unlocked if vendor is installed. |
| 2.7 | TLS certificate issued for production domain | `curl -I https://your-ulms-domain` → trusted chain.  In production `wwwroot` must be `https://...` so the Secure session cookie flag auto-enables. |
| 2.8 | Backup strategy in place (disk snapshots + DB dumps, retention ≥ 30 days)  | Confirm DB dump cron OR managed DB point-in-time recovery enabled *before* prod writes happen. |

---

## 3. Configuration (DB > Environment > Compile-time Defaults)

ULMS enforces the project-standard hierarchy.  **For any key:**

| Layer | Location | Overwrites | Example keys |
|---|---|---|---|
| 🔴 **HIGHEST (wins)** | Moodle database (`config_plugins`, `$CFG->settings`) — Super Admin `/super-admin/settings/` and Admin `/management/settings/` panels | Environment + defaults | Resend keys override, lockout thresholds, Kortext adapter factory override (Mock ↔ Production) |
| 🟡 **MIDDLE** | Repo-root `.env` file (shell-injected env vars also work)  | Compile-time defaults in `config.php` | `APP_ENV`, `APP_URL`, `DB_*`, `MOODLE_DATA_PATH`, `ULMS_MAIL_TRANSPORT`, `RESEND_*`, `SMTP_*`, `ULMS_LOCKOUT_*`, `ULMS_PASSWORD_RESET_WINDOW`, Kortext secrets |
| 🟢 **LOWEST (fallback only)** | Hardcoded defaults in `config.php` via `ulms_env($key, $default)` | Nothing — only used when `.env` key is missing AND DB override absent | DB host `127.0.0.1`, mail transport `moodle`, lockout defaults |

### 3.1 `.env` Quick Reference

Copy the template → fill in per-environment:

```bash
cp .env.example .env
# edit .env (NEVER commit real secrets to git — this file is gitignored)
```

The most important keys for production (see full annotated file `.env.example`):

```
APP_ENV=production
APP_URL=https://ulms.youruniversity.edu      # must be https:// in prod
APP_DEBUG=false
ULMS_WEB_DEBUG_DISPLAY=0

# === Database ===
DB_TYPE=mysqli
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=ulms
DB_USER=ulms_rw
DB_PASSWORD='replace-with-long-random-password'
MOODLE_DATA_PATH=/var/lib/ulms/moodledata

# === Lockouts / Security ===
ULMS_LOCKOUT_THRESHOLD=5        # failed attempts per bucket
ULMS_LOCKOUT_WINDOW=900         # 15 minutes
ULMS_LOCKOUT_DURATION=1800      # lockout 30 minutes when tripped
ULMS_PASSWORD_RESET_WINDOW=1800 # password reset rate limit
ULMS_PASSWORD_CHANGE_LOGOUT=1   # force re-login after admin pw reset
ULMS_GUEST_LOGIN_BUTTON=0
ULMS_AUTO_LOGIN_GUESTS=0

# === Mail transport (Resend HTTP API — RECOMMENDED) ===
ULMS_MAIL_TRANSPORT=resend
RESEND_API_KEY=re_prod_xxxxxxxxxxxxxxxxxxxxxxxxxxxx   # ≥ 40 chars production key
RESEND_FROM_EMAIL=no-reply@youruniversity.edu          # MUST be a verified Resend sender/domain
RESEND_FROM_NAME="ULMS Support"
ULMS_REPLY_TO=support@youruniversity.edu

# === Mail fallback (Moodle SMTP) ===
# Only used when ULMS_MAIL_TRANSPORT=moodle
SMTP_HOSTS=smtp.sendgrid.net:587
SMTP_USER=apikey
SMTP_PASS='replace-smtp-secret'
SMTP_SECURE=tls
```

---

## 4. Step-by-step: First-time Production Deployment

> **Perform steps 4.1 → 4.11 in order.**  Incremental updates (after go-live)
> use the shorter procedure in §5 instead.

### 4.1 Checkout the release

```bash
sudo -u www-data bash                 # or your webserver user
cd /var/www
git clone --depth 1 --branch main https://github.com/gospat/universityLMS.git ulms
cd ulms
git rev-parse --short HEAD            # write this down for the change log
```

### 4.2 `.env`, dataroot, permissions

```bash
cp .env.example .env
$EDITOR .env                          # fill per §3.1 — minimally APP_ENV/APP_URL/DB_*/MOODLE_DATA_PATH/MAIL_*
chown www-data:www-data .env
chmod 0640 .env                       # readable only by webserver user

# Moodledata (from .env)
mkdir -p /var/lib/ulms/moodledata
chown www-data:www-data /var/lib/ulms/moodledata
chmod 0750 /var/lib/ulms/moodledata
echo "Deny from all" > /var/lib/ulms/moodledata/.htaccess
chown www-data:www-data /var/lib/ulms/moodledata/.htaccess
```

### 4.3 (Optional) Install Composer vendor

```bash
# NOT REQUIRED — Resend + core work without vendor.
# Install only when you want Symfony HttpClient connection pooling:
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
# …or drop composer.phar in repo root and use:
#   php composer.phar install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
```

### 4.4 Run the Moodle browser installer OR CLI installer

```bash
# Web installer → open:
#   https://ulms.youruniversity.edu/install.php
# walk Moodle install wizard → create initial siteadmin.

# CLI alternative (headless):
php admin/cli/install.php \
  --wwwroot="$(grep '^APP_URL=' .env | cut -d= -f2-)" \
  --dataroot="$(grep '^MOODLE_DATA_PATH=' .env | cut -d= -f2-)" \
  --dbtype="$(grep '^DB_TYPE=' .env | cut -d= -f2-)" \
  --dbhost="$(grep '^DB_HOST=' .env | cut -d= -f2-)" \
  --dbname="$(grep '^DB_NAME=' .env | cut -d= -f2-)" \
  --dbuser="$(grep '^DB_USER=' .env | cut -d= -f2-)" \
  --dbpass="$(grep '^DB_PASSWORD=' .env | cut -d= -f2-)" \
  --dbport="$(grep '^DB_PORT=' .env | cut -d= -f2-)" \
  --fullname="UNIVERSITY LMS" \
  --shortname="ULMS" \
  --adminuser="siteadmin" \
  --adminpass='ChangeMe123!!!_Immediately' \
  --adminemail='sysadmin@youruniversity.edu' \
  --non-interactive \
  --agree-license
```

### 4.5 Install the single-flight cron wrapper (idempotent)

```bash
sudo -u www-data php local/ulms_dashboard/cli/install_cron.php --install
sudo -u www-data php local/ulms_dashboard/cli/install_cron.php --status
```

The installer writes a crontab entry using a BEGIN/END marker block pointing at
[run_moodle_cron.sh](./local/ulms_dashboard/cli/run_moodle_cron.sh) — this
wrapper uses `flock()` to guarantee a single cron run executes at any given
moment (prevents pile-up when a long adopter-sync job bleeds into the next
minute).  Output is appended to a daily-rotated log under
`${REPO_ROOT}/var/log/cron/moodle-cron-YYYY-MM-DD.log`.

### 4.6 Initial production readiness check

```bash
APP_ENV=production php local/ulms_dashboard/cli/production_readiness_check.php
# Expected: last line = "All ULMS production-readiness checks passed."
# Expected: exit = 0, grep FAIL = 0 lines
```

If any check fails, see §10 Troubleshooting.

### 4.7 Send a real smoke email through Resend

```bash
# Verify the 3-tier duck-typed transport actually delivers to your inbox.
php local/ulms_mail/cli/send_test_email.php --to=sysadmin@youruniversity.edu
```

Expect HTTP 200 from `api.resend.com` and the message in the Resend dashboard
"Sent" list, then in sysadmin inbox.  The first run may use cURL fallback which
prints `cURL extension available (fallback transport)` in the PRC output — this
is fully production-safe and equivalent in behaviour to Symfony HttpClient.

### 4.8 Seed initial academic structure (only on a brand-new empty system)

```bash
# (Optional — only if you want the demo Colleges/Depts/Programmes/Courses chain
#  instead of importing your own CSV §4.9).
php local/ulms_dashboard/cli/seed_demo_academic_chain.php
```

### 4.9 Bulk-import real academic structure via CSV (recommended)

Hierarchical **order matters** — parent entities must exist before children can
link to them.  Always import in this exact sequence:

1. **Colleges** → Management → Academics → Bulk upload → Colleges → "Download CSV template" → fill → upload.
2. **Departments** → same page, `collegeCode` (human-readable, not numeric ID) links each department to its parent college.
3. **Programmes** → same page, `collegeCode` + `departmentCode` as the parent link.
4. **Courses** → same page, `collegeCode` + `departmentCode` as scope.
5. **Programme ↔ Moodle course mappings** → Academics → Course Mappings → Download mapping CSV.  Uses the same human-readable codes (not numeric Moodle course id).

For Excel/XLSX users: the bulk upload modal ships a multi-sheet workbook where
the first sheet is step-by-step instructions and the remaining sheets are
perfectly aligned templates for each of the 5 steps above.  Fill each sheet in
order, export to CSV per sheet, upload 5x using the same UI as direct CSV.

See [local/ulms_academics/README.md](./local/ulms_academics/README.md) §4 and
§5 for the schema of each template + the human-readable code link rule.

### 4.10 Provision initial users (Admin + first cohort)

1. Log in as siteadmin → `/super-admin/` (Super Admin portal).
2. Create at least one Admin Manager (role = Manager) via **Admin Users → Create** with a strong password; **enable the Force Password Change toggle** so they are forced to reset on first login (this also triggers the audited Resend welcome email).
3. Switch to `/management/users/bulk-upload`; download the per-role CSV template for `Student` → `Lecturer` → `Admin`, fill, upload in preview → confirm.  Every user in the confirmed batch gets a welcome email and auto-enrolment in their Programme's mapped Moodle courses in the same DB transaction.

### 4.11 Final smoke test (browser)

Open an incognito window and verify each URL returns the expected shell:

| URL | Expected |
|---|---|
| `https://ulms.youruniversity.edu/sign-in/` | Clean ULMS landing page with 4 role-entry buttons |
| `https://ulms.youruniversity.edu/reset-password/` | Password-reset form (branded) |
| `https://ulms.youruniversity.edu/sign-in/activate/` | Account activation entry |
| `https://ulms.youruniversity.edu/student/login/` → sign in as student | Student shell, nav = My Courses / Grades / Timetable / Progress |
| `https://ulms.youruniversity.edu/lecturer/login/` → sign in as lecturer | Lecturer shell, nav = Courses / Exams |
| `https://ulms.youruniversity.edu/management/login/` → sign in as Manager | Admin/Management shell, nav = Dashboard / Users / Provisioning / Bulk / Academics / Analytics |
| `https://ulms.youruniversity.edu/super-admin/login/` → sign in as siteadmin | Super Admin shell, separate identity, has Audit logs + Integrations |

Sign in to each role, then **sign out** — this produces an auditable entry for
your change log.

---

## 5. Step-by-step: Incremental Update (After Go-live)

The short, safe deploy path using the managed refresh script.

### 5.1 Take a point-in-time backup (non-negotiable)

```bash
# DB dump + dataroot snapshot BEFORE pulling code.
mysqldump --single-transaction --routines ulms | gzip > /var/backups/ulms-db-predeploy-$(date +%Y%m%d-%H%M).sql.gz
tar -czf /var/backups/ulms-dataroot-predeploy-$(date +%Y%m%d-%H%M).tar.gz \
  -C "$(grep '^MOODLE_DATA_PATH=' .env | cut -d= -f2-)" .
```

### 5.2 Pull the new release

```bash
sudo -u www-data bash
cd /var/www/ulms
git fetch --tags origin main
git log --oneline HEAD..origin/main | head -n 20   # review incoming changes
git merge --ff-only origin/main
```

### 5.3 Run the managed refresh script

```bash
./scripts/ulms_refresh_live.sh
```

This script performs (see source at [scripts/ulms_refresh_live.sh](./scripts/ulms_refresh_live.sh)):
1. **Composer install** of locked production deps if `composer.json/composer.lock` exist (skips cleanly if Composer is absent).
2. **Legacy overlay sync** from tracked source into any `../custom/` overlay directory (rsync with delete).
3. **Container refresh** if `../docker-compose.yml` exists (moodle-app + nginx `up -d`, then Moodle upgrade + purge caches inside the app container).
4. **Moodle upgrade non-interactive** + **Moodle cache purge** via `admin/cli/upgrade.php` and `admin/cli/purge_caches.php`.

The script never overwrites:
- `.env`
- `config.php`
- `moodledata/`

### 5.4 Automated post-deploy verification

```bash
# MUST exit 0 with 0 FAIL lines.
APP_ENV=production php local/ulms_dashboard/cli/production_readiness_check.php

# Health (DB + system context + dataroot + cron age + backup age).
php local/ulms_dashboard/cli/ops_healthcheck.php --max-cron-age-minutes=3 --max-backup-age-hours=25

# Backup smoke — checks the last known backup on disk still restores cleanly (schema + rowcount sanity)
php local/ulms_dashboard/cli/ops_backup_smoke.php
```

### 5.5 Install the auto-refresh post-merge git hook (optional, recommended)

```bash
./scripts/install_post_merge_hook.sh
```

After install, every successful `git pull origin main` automatically runs
`ulms_refresh_live.sh` for you in the post-merge hook.

---

## 6. Rollback

If §5.4 fails at any step, roll back within minutes:

```bash
# (1) restore code pointer
OLD_COMMIT=$(git rev-parse --short HEAD~1)   # or the pre-deploy commit hash
git merge --ff-only "$OLD_COMMIT"            # requires clean pre-deploy backup step

# (2) restore DB + dataroot backups from §5.1
sudo mysql ulms < <(zcat /var/backups/ulms-db-predeploy-YYYYMMDD-HHMM.sql.gz)
tar -xzf /var/backups/ulms-dataroot-predeploy-YYYYMMDD-HHMM.tar.gz \
  -C "$(grep '^MOODLE_DATA_PATH=' .env | cut -d= -f2-)"

# (3) reapply refresh to purge caches + sync overlays
./scripts/ulms_refresh_live.sh

# (4) re-run PRC + health to confirm green
APP_ENV=production php local/ulms_dashboard/cli/production_readiness_check.php
php local/ulms_dashboard/cli/ops_healthcheck.php
```

---

## 7. CLI Operations Scripts Inventory

All ULMS-provided CLI tools live under `local/ulms_dashboard/cli/` +
`local/ulms_mail/cli/` + `local/ulms_kortext/cli/`.  Every script is
`CLI_SCRIPT` guarded (returns 403 if hit via web SAPI), uses open_basedir
tightening, disables `display_errors`, and raises memory limits when needed.

| Script | Run by | When | Notes |
|---|---|---|---|
| **[production_readiness_check.php](./local/ulms_dashboard/cli/production_readiness_check.php)** | Devops, after every deploy | MUST exit 0 with 0 FAIL lines.  52 assertions: routes, theme compile, SSOT routing, mail transport presence, Resend key format, cURL/Symfony availability, DB driver FK, controller canonical usage, guards (require_login 22/22, POST sesskey 7/7), 78 routes reachable over HTTP. |
| **[install_cron.php](./local/ulms_dashboard/cli/install_cron.php)** | Sysadmin, first deploy + upgrades | `--install`, `--uninstall`, `--status`, `--help`.  Idempotent — safe to re-run. |
| **[run_moodle_cron.sh](./local/ulms_dashboard/cli/run_moodle_cron.sh)** | Cron (every minute) | Installed by install_cron.  Single-flight `flock()`, daily rotated logs.  Runs `admin/cli/cron.php`. |
| **[ops_healthcheck.php](./local/ulms_dashboard/cli/ops_healthcheck.php)** | Sysadmin or uptime monitor, every 5–15 min | `--max-cron-age-minutes=180 --max-backup-age-hours=48`.  Checks DB connectivity, system_context, dataroot writability, cron fresh, backup fresh.  JSON parseable output. |
| **[ops_backup_smoke.php](./local/ulms_dashboard/cli/ops_backup_smoke.php)** | Nightly ops run after backup job | Confirms the last backup on disk actually restores (schema sanity + rowcount sanity) without you having to do a restore manually. |
| **[phase3_purge_rebuild.php](./local/ulms_dashboard/cli/phase3_purge_rebuild.php)** | Sysadmin, **only before go-live / on maintenance windows** | Pristine state utility: wipes 8 moodledata dirs (cache/localcache/temp/sessions/sitedata/lang/styles_debug/styles_mashup), purges MUC caches, rebuilds admin tree, upgrades DB, rebuilds themes, resets course caches.  **Wipes all sessions (all users re-login) — run only on maintenance windows.** |
| **[seed_demo_academic_chain.php](./local/ulms_dashboard/cli/seed_demo_academic_chain.php)** | Developer or initial deploy | Creates a sample College → Department → Programme → Course chain with mappings.  Only useful before real CSV bulk import. |
| **[repair_academic_profiles.php](./local/ulms_dashboard/cli/repair_academic_profiles.php)** | Sysadmin | Reconciles orphan academic profiles.  Use only when a manual DB edit has left orphan `collegeCode` / `departmentCode` dangling references. |
| **[send_test_email.php](./local/ulms_mail/cli/send_test_email.php)** | Sysadmin, after mail setup changes | `--to=admin@domain.com` sends a real smoke email via Resend (or SMTP if transport=moodle).  Uses an idempotency key per recipient per hour. |
| **[cron_sync_adoptions_and_entitlements.php](./local/ulms_kortext/cli/cron_sync_adoptions_and_entitlements.php)** | Cron (daily, through Moodle core cron + Kortext scheduled task config) | Adoptions → entitlements sync against the active Kortext adapter.  Uses per-(user, adoption) idempotency keys so re-runs cannot double-entitle. |
| **[import_seed.php](./local/ulms_academics/cli/import_seed.php)** | Dev only | Seeds minimal CSV import fixtures for local testing. |
| **[batch_autograde_cron.php](./local/ulms_exam/cli/batch_autograde_cron.php)** | Moodle core scheduled task | Auto-grades completed exam attempts that have only objective questions (MCQ/TF/Matching etc.), writes audit entry per attempt. |
| **[phase3_e2e_fixture.php](./local/ulms_exam/cli/phase3_e2e_fixture.php)** | Dev + QA only | Creates an end-to-end exam fixture (questions, attempts, student) to validate exam engine behaviour locally. |

---

## 8. Go-live: Pristine Production Wipe (Non-negotiable)

**Immediately before flipping the DNS / opening the system to real students:**

```bash
# (1) Confirm DB backup + dataroot snapshot taken §5.1 in case of rollback.
# (2) Wipe transactional + student data + caches (preserves academic structure + admin configs).
sudo -u www-data php local/ulms_dashboard/cli/phase3_purge_rebuild.php

# (3) Rotate any remaining demo / dev Resend keys to a fresh production key:
$EDITOR .env
#    RESEND_API_KEY=re_prod_… (≥ 40 chars, fresh production key not shared with dev)
#    RESEND_FROM_EMAIL=no-reply@youruniversity.edu (verified Resend domain)

# (4) Re-run PRC + healthcheck on production keys
APP_ENV=production php local/ulms_dashboard/cli/production_readiness_check.php
php local/ulms_dashboard/cli/ops_healthcheck.php --max-backup-age-hours=1

# (5) Final Resend smoke to real sysadmin account (proves production key is valid)
php local/ulms_mail/cli/send_test_email.php --to=sysadmin@youruniversity.edu

# (6) Log out from the Super Admin session in all browsers.
# (7) Clear the local Moodle cache in case of dev-only JS/CSS asset staleness:
sudo -u www-data php admin/cli/purge_caches.php
```

After these 7 steps the system is in **pristine, empty, ready state** — the
next user to register will be the first real user, not a demo account.

---

## 9. Monitoring, Logging, Retention

| Source | Path / Table | Retention recommendation |
|---|---|---|
| Moodle cron log | `${REPO_ROOT}/var/log/cron/moodle-cron-YYYY-MM-DD.log` | 30 days, then compress + archive 6 months |
| PHP error log | `.env` key `ULMS_LOG_FILE` (default: `moodledata/logs/php-error.log`) | 30 days |
| Moodle `logstore_standard` table | DB table `mdl_logstore_standard_log` | 12 months, export older rows to cold storage |
| ULMS provisioning audit log | `/super-admin/auditlogs` view + DB tables provisioning `_log` | Permanent (audit trail for GDPR / institutional policy) |
| ULMS exam audit log | `exam_service::audit_log()` writes to `local_ulms_exam_*_log` | Permanent |
| ULMS Kortext entitlement log | `local_ulms_kortext_entitlement_log` (contains idempotency key) | Permanent |
| ULMS Resend mail log | implicit via Resend dashboard (search by recipient/date) | Resend account-level retention (keep 90 days in Resend; 12 months in Moodle logstore) |
| DB backups | As configured in §2.8 | 30 days hot, 1 year cold.  Monthly full backup archived separately. |
| Dataroot snapshots | As configured in §2.8 | 7 days daily, 4 weekly. |

### 9.1 Uptime monitors (suggested)

Hit these from an external monitoring service (UptimeRobot, Pingdom, etc.) every 60 s:

1. `https://ulms.youruniversity.edu/sign-in/` → expect HTTP 200, contains string `ULMS` (proves 200 is not a cached upstream 502 error page).
2. `https://ulms.youruniversity.edu/reset-password/` → expect HTTP 200.
3. *Optional privileged endpoint* — call `ops_healthcheck.php` via a runner that has shell access to the host every 5 min; alert on any non-`ok: true` check.

---

## 10. Production-Ready Declaration Checklist

Tick every item before signing off a new instance as production-ready.

```
☑  §2  Pre-deployment checklist: 8/8 gates passed
☑  §3  .env populated with real secrets; APP_ENV=production; APP_URL=https://…
☑  §4  First-deploy steps 1–11 executed in order
☑  §4.6   production_readiness_check.php → exit 0, 52/52 PASS, 0 FAIL
☑  §4.7   send_test_email.php delivers to real inbox via Resend
☑  §4.11  4 role portals smoke-tested via browser with different users
☑  §5  Incremental deploy path rehearsed once on staging (backup → pull → refresh → PRC → rollback)
☑  §6  Rollback procedure validated on staging (restored code+DB+dataroot, PRC returns green)
☑  §7  install_cron.php --status → installed + active; cron log rotating daily
☑  §8  Go-live pristine wipe performed; fresh Resend production key in place; demo data gone
☑  §9  External uptime monitors 1 + 2 returning green for 24 h
☑  §9  Backup strategy verified: last DB dump actually restored via ops_backup_smoke.php
☑  Security: siteadmin initial password changed; Manager accounts created with force-pw-change;
       lockout thresholds at 5/15 min; Guest login disabled; debug=0; Secure cookies active (https).
☑  Docs: all component READMEs reviewed, Kortext adapter factory set to Production REST (not Mock)
        when live entitlements are enabled.
```

---

## 11. Troubleshooting

### 11.1 Production Readiness Check fails `mail:http-client`

- Root cause A: `ext-curl` is not installed (no Symfony vendor either).
  Fix: `apt install php-curl` (or equivalent) and re-run.
- Root cause B: You want Symfony HttpClient vendor but Composer did not run.
  Fix: `composer install --no-dev --optimize-autoloader`; re-run.

### 11.2 Production Readiness Check fails `sec:resend-key-format`

- Root cause: current key does not start with `re_` or is shorter than 32 characters
  after the `re_` prefix.  (Threshold is 32 chars min to accommodate Resend
  test accounts; production keys should be at least 40 characters.)
- Fix: Log into Resend dashboard → generate a fresh API key → paste into `.env`
  → reload PHP-FPM / webserver.

### 11.3 Production Readiness Check fails `audit:routes-reachable` (all 78 routes 000)

- Root cause: Web server (or PHP dev server) is not running, or `wwwroot` is
  wrong (http vs https, missing trailing `/`).
- Fix: Ensure Nginx/Apache is serving the repo.  `curl -I https://ulms.youruniversity.edu/sign-in/`
  returns HTTP 200/302/403.  Fix `APP_URL` in `.env` and purge caches.

### 11.4 `ui:canonical-controllers` fails with missing wrapper/render calls

- Root cause: A new role portal controller was added but it renders the ULMS
  shell by hand instead of through `local_ulms_dashboard_render_role_portal_page()`
  wrapper or the canonical `local_ulms_dashboard_render_page_header()` +
  `local_ulms_dashboard_start_shell_wrap()` pair.
- Fix: Refactor the new controller to use the wrapper (preferred) or add the
  canonical pair calls.  Do not instantiate `ulms-page-header` inline.

### 11.5 Kortext syncs seem "stuck" with idempotent "already done" rows

- This is by design — idempotency keys are the safety mechanism preventing
  double-entitlements.
- To re-trigger a sync for a given (user, adoption) pair, clear the matching
  row in `local_ulms_kortext_entitlement_log` (through Super Admin → Integrations
  → Kortext) **after** confirming the user's entitlement really should be
  re-granted.  Never bulk-clear the table without a rollback backup.

### 11.6 Moodle cron runs are piling up

- Should never happen because `run_moodle_cron.sh` uses `flock -n`.  If you see
  "SKIP — previous moodle-cron run still holds" messages repeatedly in the cron
  log, investigate the long-running task (typically Kortext sync or Exam
  autograde) and consider adding more capacity or splitting it into a separate
  ad-hoc job.  Do NOT remove the flock guard.

### 11.7 Password reset emails are not delivered

- Confirm PRC `mail:*` all pass.
- Run `send_test_email.php --to=<recipient>` to get CLI-level diagnostics.
- Check Resend dashboard for bounces.  If the sender domain is not yet
  verified at Resend, no email outside the creating admin account's domain can
  be delivered.  Verify the domain and re-test.
- Confirm `.env` key `ULMS_MAIL_TRANSPORT=resend` (not `moodle`).

### 11.8 Student reports seeing "no courses" / empty dashboard after successful enrol via CSV bulk

- Root cause almost always: missing Programme ↔ Moodle course mappings for
  their Programme (step 5 in §4.9), OR the student's `studylevel` was not set
  (centralized `academic_repository::get_programme_moodlecourseids` filters by
  studylevel as the anti-cross-level leak guard).
- Fix: `/management/academics/mappings` → upload mapping CSV.  For students
  with no studylevel: edit their profile (or re-run bulk import with explicit
  studylevel column).
