# ULMS Deployment & Production Runbook

The single source of truth for deploying, updating, hardening, and running a
UNIVERSITY LMS production instance.  Covers: architecture, the 3-level config
hierarchy, step-by-step first deploy + incremental updates, rollback, the
automated 52-assertion Production Readiness Checker (PRC), cron, maintenance
scripts, go-live wipe, Kortext + Resend setup, ops monitoring, and
troubleshooting.

> ⚠ **Documentation reality vs. runtime-verification baseline — read before deploying:**
> This runbook describes the CURRENT repository HEAD (application + scripts + docs).
> The **last commit where the 52-assertion Production Readiness Checker
> (`production_readiness_check.php`) was EXECUTED AGAINST A LIVE ENVIRONMENT and
> returned 52/52 PASS + exit code 0** is **commit `b46a1fe8`** ("production
> hardening: resend transport independence + PRC checker fixes").
>
> Every commit *after* `b46a1fe8` — including the previous docs-only commit
> `51ad91cf` and *this batch of fixes* — has consisted of:
>   (a) markdown documentation improvements,
>   (b) deployment shell-script hardening (safety flags, PHP-FPM primary path,
>       rsync --delete guards, verbose usage),
>   (c) CLI safety gates on destructive scripts (phase3_purge_rebuild →
>       requires `--i-am-sure` in production; seed_demo_academic_chain is
>       blocked entirely in APP_ENV=production).
>
> None of these post-b46a1fe8 changes modify the runtime PHP application logic
> that the PRC asserts on (routes, controllers, auth flow, CSRF checks, mail
> transport internals, DB FK constraints).  Nonetheless, you MUST re-run the
> PRC on YOUR live target environment after completing §4 and §5 of this
> runbook.  Do NOT sign off a deployment as "production verified" until you
> have personally observed `All ULMS production-readiness checks passed.`
> (exit=0) on that exact server.  See §10 (Production-Ready Declaration
> Checklist) for the full 14-point sign-off, of which a live PRC run is only
> one of the 14 items.
>
> Target infrastructure of the Bells University deployment (the reference
> environment for this runbook):
>   · Server: DigitalOcean Droplet, Ubuntu 24.04 LTS, 4+ GB RAM recommended
>   · Application: Moodle 4.5.12+ / ULMS
>   · PHP: PHP 8.3 with php-fpm 8.3 SAPI (NOT a dockerized PHP)
>   · Database: DigitalOcean Managed MySQL 8 (port 25060 typically, TLS enabled)
>   · Domain: learn.bellsuniversity.edu.ng  (HTTPS mandatory)
>   · Deployment model: Standard PHP deployment (git repo → git pull →
>     ulms_refresh_live.sh with the default PHP-FPM primary path).  Docker
>     Compose is supported only as an opt-in secondary path via the
>     `--docker` flag if you later migrate; it is NOT the default.

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

## 2. Pre-deployment Checklist (Bells University — Ubuntu 24.04 / DO Managed MySQL)

Run through this list **before** attempting the first install on any new host.
Paths and package names are Ubuntu 24.04 LTS specific; translate as needed for
other distributions.

| # | Gate | How to verify (Ubuntu 24.04 commands) |
|---|---|---|
| 2.1 | PHP 8.3 with required extensions installed via php-fpm + php-cli | `php -v` reports 8.3.x.  Then run: `php -m \| grep -E '^mysqli$\|^pdo_mysql$\|^curl$\|^mbstring$\|^json$\|^xml$\|^xmlreader$\|^zip$\|^gd$\|^intl$\|^opcache$\|^iconv$\|^openssl$\|^ctype$\|^zlib$\|^simplexml$\|^dom$\|^spl$\|^pcre$\|^hash$\|^fileinfo$\|^sodium$' \| sort -u \| wc -l` → count ≥ 18.  **ext-mysqli is mandatory (not optional) for MySQL deployments** despite Moodle upstream listing it under `suggest` in composer.json. Install command: `sudo apt update && sudo apt install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-curl php8.3-mbstring php8.3-xml php8.3-zip php8.3-gd php8.3-intl php8.3-opcache php8.3-iconv php8.3-ctype php8.3-dom php8.3-simplexml ca-certificates`. (php-json/php-spl/pcre/hash/fileinfo/sodium are typically built in).  `ca-certificates` is **MANDATORY**: `DB_SSL_MODE=verify-full` needs `/etc/ssl/certs/ca-certificates.crt` to validate the Managed MySQL certificate chain (see §3.1 DB_SSL_MODE + §11.9).
| 2.2 | DigitalOcean Managed MySQL 8 reachable over TLS (verify-full) + dedicated `ulms_rw` user provisioned | From the Droplet, confirm full TLS **with identity verification** using a diagnostic that works across MySQL 5.7 → 8.0 → 8.4+ (DO NOT use `@@ssl_version` / `@@ssl_cipher` — those are deprecated and removed in MySQL 8.4; use the SESSION STATUS `Ssl_*` variables which are the portable, non-deprecated API).  Run:
```
mysql \
  -h bells-ulms-db-do-user-xxxx-0.b.db.ondigitalocean.com \
  -P 25060 -u ulms_rw -p ulms --ssl-mode=VERIFY_IDENTITY \
  -e "SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_version','Ssl_cipher','Ssl_server_not_after','Ssl_sessions_reused');
      SELECT current_user() AS db_user, CURRENT_TIMESTAMP AS server_time;"
```
Expected output: `Ssl_version` = `TLSv1.2` or `TLSv1.3` (TLSv1.0/1.1 were removed in 8.0.28), `Ssl_cipher` non-empty, `Ssl_server_not_after` far in the future, and NO `ERROR 2026 (HY000): SSL connection error` / cert-mismatch output.  The `--ssl-mode=VERIFY_IDENTITY` CLI flag proves the Droplet trusts the CA chain AND the exact wildcard hostname on the cert matches DB_HOST — this diagnostic is the ground truth for whether PHP Moodle `DB_SSL_MODE=verify-full` will succeed.  If this CLI test fails, Moodle WILL fail to connect — fix the CA bundle / firewall / DB_HOST hostname before going further.  Run the exact grant list from `.env.example §Database` so the app user never has SUPER/FILE privileges.  **Never use the cluster's `doadmin` or `root` credentials as the application DB user.** |
| 2.3 | `moodledata` directory created OUTSIDE the webroot, writable by `www-data`, with `.htaccess` denying direct access | Bells standard path: `/var/lib/ulms/moodledata`.  `sudo mkdir -p /var/lib/ulms/moodledata && sudo chown www-data:www-data /var/lib/ulms/moodledata && sudo chmod 0750 /var/lib/ulms/moodledata && echo "Deny from all" \| sudo tee /var/lib/ulms/moodledata/.htaccess && sudo chown www-data:www-data /var/lib/ulms/moodledata/.htaccess`. |
| 2.4 | Outbound HTTPS on 443 to `api.resend.com`, (optionally) `api.kortext.co.uk`, and the DO Managed MySQL port | `curl -I https://api.resend.com` → HTTP 401/404 (proves endpoint reachable; 401 is fine because no key was supplied).  `nc -zv bells-ulms-db-…ondigitalocean.com 25060` → `succeeded!`. |
| 2.5 | Shell user can write to repo root + run `php`, `rsync`, `flock`, `git` | `php -v`, `rsync --version`, `flock --version`, `git --version` all return 0 exit codes.  Install any missing ones: `sudo apt install -y rsync util-linux git curl`. |
| 2.6 | Composer 2.x available globally **or** `composer.phar` placed in repo root | **Optional.**  Resend + core work without Composer vendor via cURL duck-typed fallback; Symfony HttpClient connection pooling is only unlocked if vendor is installed.  When installed use `composer install --no-dev --optimize-autoloader`; if vendor is not desired, skip it — the Resend transport explicitly supports both modes and the refresh script documents this explicitly. |
| 2.7 | TLS certificate issued for `learn.bellsuniversity.edu.ng` and the Nginx/Apache vhost serving HTTPS on port 443 | `curl -I https://learn.bellsuniversity.edu.ng/sign-in/` → trusted TLS, HTTP 200/302.  In production `$CFG->wwwroot` must be `https://learn.bellsuniversity.edu.ng` so the Secure session cookie + SameSite=Strict flags auto-enable in `config.php` lines 260-278. |
| 2.8 | Backup strategy in place (DO Droplet volume snapshots + Managed DB point-in-time recovery, retention ≥ 30 days)  | Confirm **both** the Managed DB point-in-time recovery is enabled (DO UI) AND a cron job writes nightly DB logical dumps (mysqldump → compressed) to a separate volume from the webroot before prod writes happen.  Managed DB snapshots are not a substitute for logical dumps during rollback drills. |

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

The most important keys for production (see full annotated file `.env.example`;
note the Bells University-specific defaults: `learn.bellsuniversity.edu.ng`,
DO Managed MySQL host placeholder, `/var/lib/ulms/moodledata` external dataroot,
`ulms_rw` dedicated DB user, `APP_ENV=production`, `APP_DEBUG=false`):

```
APP_ENV=production
APP_URL=https://learn.bellsuniversity.edu.ng      # Bells University: always HTTPS
APP_DEBUG=false
ULMS_WEB_DEBUG_DISPLAY=0

# === Database (DigitalOcean Managed MySQL — dedicated ulms_rw, never root) ===
DB_TYPE=mysqli
DB_HOST=bells-ulms-db-do-user-xxxx-0.b.db.ondigitalocean.com
DB_PORT=25060
DB_NAME=ulms
DB_USER=ulms_rw
DB_PASSWORD='replace-with-strong-32-char-password-use-double-quotes-if-special-chars'
# DB_SSL_MODE: Moodle MySQLi driver supported values = require | verify-full.
#   * verify-full = MANDATORY for DigitalOcean Managed MySQL and ANY DBaaS.
#       Sets PHP MYSQLI_CLIENT_SSL | MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT.
#       Enables TLS encryption + certificate chain validation against the
#       OS trust store (/etc/ssl/certs/ca-certificates.crt).  The PHP
#       constant MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT historically performs
#       **certificate chain validation** in mysqlnd (so an untrusted /
#       expired / self-signed cert fails with 0A000086
#       "certificate verify failed").  Hostname/CN/SAN-vs-DB_HOST matching
#       is provided implicitly by the underlying OpenSSL/mysqlnd handshake
#       when chain validation is on; to get an independently-verified
#       strong guarantee that BOTH checks pass, always use the §2.2 gate
#       command `mysql --ssl-mode=VERIFY_IDENTITY` before install.php —
#       that mode asserts both checks explicitly.
#       A valid ca-certificates bundle MUST be installed on the Droplet
#       (see §2.1 apt install command which includes ca-certificates).
#   * require = encrypts the wire only.  No CA / hostname verification.
#       Use ONLY inside a private VLAN/peering network with pre-existing
#       operator trust.  DO NOT use with public-network DBaaS endpoints.
#   * leave blank / omit = plain TCP (only for 127.0.0.1 dev containers).
# For Bells/DO Managed MySQL: DB_HOST must be the exact hostname DO
# provides in the "Connection details" panel (wildcard cert CN/SAN of
# *.b.db.ondigitalocean.com) — DO NOT put a numeric IPv4 in DB_HOST
# (wildcard certs do NOT match numeric IPs).
DB_SSL_MODE=verify-full
MOODLE_DATA_PATH=/var/lib/ulms/moodledata   # MUST be outside webroot

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
sudo bash -c '
  APP_WWW_DIR=/var/www/ulms
  mkdir -p "$APP_WWW_DIR"
  chown www-data:www-data "$APP_WWW_DIR"
  cd "$APP_WWW_DIR/.."
  git clone --branch main https://github.com/gospat/universityLMS.git ulms
  cd "$APP_WWW_DIR"
  chown -R www-data:www-data .
  git rev-parse --short HEAD > ./var/run/deployed-commit.txt
  echo "Deployed commit: $(cat ./var/run/deployed-commit.txt)"
'
```

### 4.2 `.env`, dataroot, permissions + DB_TLS_PROOF (mandatory)

```bash
cp .env.example .env
$EDITOR .env                          # fill per §3.1 — minimally APP_ENV/APP_URL/DB_*/MOODLE_DATA_PATH/MAIL_*/DB_SSL_MODE=verify-full
chown www-data:www-data .env
chmod 0640 .env                       # readable only by webserver user

# Moodledata (from .env)
mkdir -p /var/lib/ulms/moodledata
chown www-data:www-data /var/lib/ulms/moodledata
chmod 0750 /var/lib/ulms/moodledata
echo "Deny from all" > /var/lib/ulms/moodledata/.htaccess
chown www-data:www-data /var/lib/ulms/moodledata/.htaccess

# —— DB TLS PRE-FLIGHT (MANDATORY BEFORE install.php) ——
# Do NOT skip this block.  It runs the EXACT same real_connect() call
# Moodle's native MySQLi driver will use, with DB_SSL_MODE=verify-full
# flags, and fails FAST with a structured exit code + errno/error message
# if CA chain/host/network/auth is wrong.  Zero writes to DB, zero
# moodle boot, uses only ext-mysqli + standard .env parser.
sudo -u www-data php scripts/ulms_test_db_ssl.php
# Expected exit code 0, output includes:
#   [1/4] DB_SSL_MODE validation PASS
#   [2/4] Final flags = 0x40000800 (SSL | SSL_VERIFY_SERVER_CERT)
#   [3/4] real_connect() succeeded
#   [4/4] Ssl_version = TLSv1.2 or TLSv1.3, Ssl_cipher non-empty.
# If this command exits non-zero, fix the issue (see §11.9 matrix) BEFORE
# proceeding to install.php.
```

Also run the MySQL CLI identity-verification gate from §2.2 — both diagnostics
must pass.

### 4.3 (Optional) Install Composer vendor

```bash
# NOT REQUIRED — Resend + core work without vendor.
# Install only when you want Symfony HttpClient connection pooling:
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
# …or drop composer.phar in repo root and use:
#   php composer.phar install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
```

### 4.4 Run the Moodle browser installer OR CLI installer (PROTECTED ADMIN PASSWORD)

**Web installer — recommended, because Moodle's web wizard validates PHP extensions,
dataroot permissions and DB connectivity interactively before writing:**

1. Open: `https://learn.bellsuniversity.edu.ng/install.php` in an incognito window
2. Walk the Moodle install wizard.  When prompted for the *initial siteadmin* account:
   - **Do not reuse a personal/email password.**  Use a fresh, unique password of
     at least 20 characters (Moodle enforces its own password policy on top).  Store
     it in an institutional password vault (not a plaintext file, not a spreadsheet).
   - Do NOT enable "Email based self-registration" for production; Bells University
     provisions all users via Admin bulk CSV upload.
3. After install completes you are dropped into the siteadmin dashboard.
4. **Immediately:** click the siteadmin menu → **Preferences** → **Change password** →
   generate a fresh 24+ char password in the vault and rotate it.  This ensures the
   password you typed into the install wizard (which may be cached in your browser's
   form history, or visible on-screen during a screenshare) is no longer live.

**CLI alternative (headless servers, no browser access):**

The CLI installer accepts an admin password via `--adminpass`.  **If you use this
variant:**
* Do NOT paste a plaintext password into the command line (it would be visible in
  `ps` output, shell history files, and audit logs).
* Instead read it from a *file* that is deleted immediately, or use an
  environment variable that is cleared after the run:

```bash
# Create a strong random admin password (24 chars, mixed case + symbols)
# and pass it VIA STDIN using a heredoc-style wrapper, NOT on the command line.
ADMIN_PASS_FILE="$(mktemp /tmp/ulms-adminpass.XXXXXX)"
chmod 600 "$ADMIN_PASS_FILE"
openssl rand -base64 32 | tr -d '\n' > "$ADMIN_PASS_FILE"

php admin/cli/install.php \
  --wwwroot="$(grep '^APP_URL=' .env | cut -d= -f2-)" \
  --dataroot="$(grep '^MOODLE_DATA_PATH=' .env | cut -d= -f2-)" \
  --dbtype="$(grep '^DB_TYPE=' .env | cut -d= -f2-)" \
  --dbhost="$(grep '^DB_HOST=' .env | cut -d= -f2-)" \
  --dbname="$(grep '^DB_NAME=' .env | cut -d= -f2-)" \
  --dbuser="$(grep '^DB_USER=' .env | cut -d= -f2-)" \
  --dbpass="$(cat "$ADMIN_PASS_FILE")" \
  --dbport="$(grep '^DB_PORT=' .env | cut -d= -f2-)" \
  --fullname="BELLS UNIVERSITY OF TECHNOLOGY" \
  --shortname="BellsTech-ULMS" \
  --adminuser="siteadmin" \
  --adminemail="sysadmin-ops@bellsuniversity.edu.ng" \
  --non-interactive \
  --agree-license

# Wipe the password temp file (MANDATORY — do not skip)
shred -u -z -n 3 "$ADMIN_PASS_FILE" 2>/dev/null || rm -f "$ADMIN_PASS_FILE"
unset ADMIN_PASS_FILE

# IMPORTANT: the password in the vault must be this initial password.  After
# first login as siteadmin, ROTATE IT AGAIN via Moodle's "Preferences / Change
# password" page so no artifact (shell history, process list, Moodle install
# log) holds a live credential.
```

**Passwords containing special characters (#, space, $, !, quotes, shell metachars):**
Wrap both the DB_PASSWORD value in `.env` AND any `--dbpass=` CLI argument in
**double quotes**.  The ULMS env parser (config.php lines 111–152) explicitly
strips a matching pair of surrounding quotes so `"abc#123"` is read as the literal
string `abc#123` (not truncated at the `#` inline comment marker).

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

### 4.8 Do NOT run demo seed scripts in production

> **Hard block:** `seed_demo_academic_chain.php` is guarded against
> `APP_ENV=production` — it will exit 2 with a banner explaining why.  The
> script inserts fictional Colleges "CST", Departments "DCS/DMS", Programmes
> "BSCCS/BSMAT", hardcoded userid-65/66 enrolments, and a 2026/27 session.
> If you mistakenly run it on staging before a bulk import you must purge
> those rows (or restore DB backup from §2.8) before loading real data.
>
> Real Bells University data is loaded via CSV/XLSX bulk import (next step).
> Demo seeding is ONLY allowed on local developer workstations and QA VMs
> where APP_ENV=local, and even there it is always a dry-run unless you
> pass `--apply`.  For completeness the full production-safe guard and
> override flag are documented in the script's own `--help`.

### 4.9 Bulk-import real Bells University academic structure via CSV/XLSX (REQUIRED for production)

Hierarchical **order matters** — parent entities must exist before children can
link to them.  Always import in this exact sequence (use Management → Academics
→ Bulk upload):

1. **Colleges** → download the `ulms-colleges-template.csv` template → fill with
   Bells University real colleges → upload, preview, confirm.
2. **Departments** → same page; link each department to its parent using the
   **human-readable `collegeCode`** (not numeric IDs) so the import is portable
   across databases.
3. **Programmes** → same page; parent links = `collegeCode` + `departmentCode`.
4. **Courses** → same page; scoped by `collegeCode` + `departmentCode`.
5. **Programme ↔ Moodle course mappings** → Academics → Course Mappings →
   Download mapping CSV.  Uses the same human-readable programmeCode + courseCode
   (not numeric Moodle course IDs).

For Excel/XLSX-first data entry teams: the bulk upload modal ships a **6-sheet
multi-sheet XLSX workbook** (`ulms-academic-structure-workbook.xlsx`) — sheet 1
is the Instructions sheet (read it first), sheets 2–6 correspond exactly to
steps 1–5 above.  Export each data sheet **individually to UTF-8 CSV** before
upload.

See [local/ulms_academics/README.md](./local/ulms_academics/README.md) §4 for
the exact schema of each template + the human-readable code linking rule.

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

## 5. Step-by-step: Incremental Update (After Go-live, Bells University standard PHP-FPM)

The short, safe deploy path for routine code updates.  Bells University uses
the default **standard PHP-FPM** path; `--docker` and `--overlay-delete-ok`
flags are for legacy/other deployments only and are NOT used here.

### 5.1 Take a point-in-time backup (NON-NEGOTIABLE.  Do not skip.)

```bash
PREDEPLOY_TIMESTAMP=$(date +%Y%m%d-%H%M)
BACKUP_DIR="/var/backups/ulms"
sudo mkdir -p "$BACKUP_DIR" && sudo chmod 0750 "$BACKUP_DIR"

# (1) Database — use the dedicated ulms_rw user for reads; NEVER use root/doadmin
#     credentials in scripts.  For DigitalOcean Managed MySQL the port is 25060.
DB_HOST="$(grep '^DB_HOST=' .env | cut -d= -f2-)"
DB_PORT="$(grep '^DB_PORT=' .env | cut -d= -f2-)"
DB_NAME="$(grep '^DB_NAME=' .env | cut -d= -f2-)"
DB_USER="$(grep '^DB_USER=' .env | cut -d= -f2-)"
DB_PASS_FILE="$(mktemp /tmp/ulms-dbpass.XXXXXX)"
chmod 600 "$DB_PASS_FILE"
grep '^DB_PASSWORD=' .env | sed -E 's/^DB_PASSWORD=//; s/^"(.*)"$/\1/; s/^'"'"'(.*)'"'"'$/\1/' > "$DB_PASS_FILE"

mysqldump --single-transaction --routines --quick --default-character-set=utf8mb4 \
  --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
  --password="$(cat "$DB_PASS_FILE")" "$DB_NAME" \
  | gzip > "$BACKUP_DIR/ulms-db-predeploy-$PREDEPLOY_TIMESTAMP.sql.gz"

# (2) Moodledata snapshot (preserves all user uploads, backups, Kortext CSVs)
tar -czpf "$BACKUP_DIR/ulms-dataroot-predeploy-$PREDEPLOY_TIMESTAMP.tar.gz" \
  -C "$(grep '^MOODLE_DATA_PATH=' .env | cut -d= -f2-)" .

# (3) Store the PREVIOUS git commit so rollback can code-restore it:
git rev-parse HEAD > "$BACKUP_DIR/ulms-predeploy-commit-$PREDEPLOY_TIMESTAMP.txt"

# (4) Wipe temp DB password file from disk.
shred -u -z -n 3 "$DB_PASS_FILE" 2>/dev/null || rm -f "$DB_PASS_FILE"
unset DB_PASS_FILE PREDEPLOY_TIMESTAMP BACKUP_DIR DB_HOST DB_PORT DB_NAME DB_USER
```

### 5.2 Pull the new release and review incoming diff

```bash
cd /var/www/ulms
sudo -u www-data git fetch origin main
sudo -u www-data git log --oneline HEAD..origin/main | head -n 20   # review changes
sudo -u www-data git merge --ff-only origin/main                 # fast-forward only (never a merge commit)
```

### 5.3 Run the managed refresh script (Bells standard flags)

```bash
# Recommended invocations for Bells University standard PHP-FPM deployment:
#
#   * --skip-overlay — legacy overlay sync is NOT used on this server (the
#     default server layout is git repo + PHP-FPM; there is no ../custom/
#     directory, so this step is skipped anyway; the flag makes it explicit).
#   * Omit --composer-required — vendor installation is optional because
#     the Resend transport works via ext-cURL duck-typed fallback.  Only
#     add --composer-required if you have already verified Composer is
#     installed and you want Symfony HttpClient pool behaviour.
#   * Omit --overlay-delete-ok — Bells University does not use legacy
#     overlay sync, so the delete guard is irrelevant but keeping it
#     off is the safe default.
#   * Add --dry-run first to preview overlay rsync (if any) + composer status
#     before any writes happen.

# First: preview only (no file writes, no Moodle DB writes)
sudo -u www-data bash scripts/ulms_refresh_live.sh --dry-run --skip-overlay

# Then: real run (performs composer install → upgrade.php --non-interactive →
# purge_caches.php → best-effort opcache reset via cachetool if installed,
# otherwise prints the php-fpm restart command for you)
sudo -u www-data bash scripts/ulms_refresh_live.sh --skip-overlay
```

The refresh script explicitly performs (see source at
[scripts/ulms_refresh_live.sh](./scripts/ulms_refresh_live.sh) which contains
its entire 2-page usage + deployment-mode documentation at the top of the file):
1. **Composer install** (when present, optional) of locked production deps if
   `composer.json/composer.lock` exist.  Clean skip when Composer is absent;
   exits 2 only if you explicitly add `--composer-required`.
2. **Legacy overlay sync** — **SAFE-BY-DEFAULT for Bells University:** rsyncs
   from tracked plugin directories into `../custom/` WITHOUT `--delete` unless
   you explicitly pass `--overlay-delete-ok` (which also triggers an interactive
   `type YES` confirmation per overlay target).  `--skip-overlay` disables the
   step entirely (recommended).
3. **Moodle upgrade + cache purge (STANDARD PHP-FPM PATH, PRIMARY):** runs
   `php admin/cli/upgrade.php --non-interactive` (upgrade step continues
   safely when there are no pending schema changes), then
   `php admin/cli/purge_caches.php` (cache purge ALWAYS runs), then best
   effort `cachetool opcache:reset` (or prints the php8.3-fpm systemctl restart
   command when cachetool is unavailable).
4. **Docker Compose refresh (SECONDARY, OPT-IN):** runs only when you pass
   `--docker` explicitly; NEVER runs silently when `docker-compose.yml` is
   absent or docker CLI is missing — both produce a WARNING.

**Files the refresh script GUARANTEES it never modifies:**
`.env`, `config.php`, any path under `MOODLE_DATA_PATH`, `.htaccess` at repo
root and at dataroot, `composer.phar` if present, `var/run/moodle-cron.lock`
(cron single-flight lock).

### 5.4 Automated post-deploy verification (Bells University)

```bash
# 1. MUST exit 0 with 0 FAIL lines — re-runs the full 52-assertion PRC on the
#    live environment.  Treat ANY non-zero exit or FAIL line as BLOCKING.
sudo -u www-data APP_ENV=production php local/ulms_dashboard/cli/production_readiness_check.php

# 2. Health (DB connectivity + system_context + dataroot writable + cron not stale + backup not stale)
#    Lower --max-* thresholds RIGHT AFTER a deploy so you notice any immediately stale data.
sudo -u www-data php local/ulms_dashboard/cli/ops_healthcheck.php \
  --max-cron-age-minutes=3 --max-backup-age-hours=25

# 3. Backup smoke — restores the LAST backup on disk into an ephemeral check DB
#    and asserts schema + rowcount sanity.  Pass when the §5.1 backup completed
#    cleanly; use it each night to validate backups.
sudo -u www-data php local/ulms_dashboard/cli/ops_backup_smoke.php
```

### 5.5 Install the auto-refresh post-merge git hook (optional, recommended)

```bash
sudo -u www-data bash scripts/install_post_merge_hook.sh
```

After install, every successful `git pull origin main` automatically runs
`ulms_refresh_live.sh --skip-overlay` (Bells default flags are injected
by the hook installer).  Edit the hook at `.git/hooks/post-merge` to change
the flags if you later add Composer vendor.

---

## 6. Rollback — TESTED procedure for standard PHP-FPM deployments

Rollback restores the **application code + (optionally) database + moodledata
snapshot state** back to a known-good deploy taken in §5.1.  The old suggestion
of `git merge --ff-only <old_commit>` is **NOT a rollback**; it fast-forwards
*forward* only and therefore fails 100% of the time when your current HEAD
is newer than the target.  The tested procedure below is what we actually use.

Rollback comes in two flavours.  **Use Code-only Rollback for code-only deploys
where the DB schema did not change;** use **Full Rollback** when the deploy
included a Moodle/plugin DB schema upgrade (upgrade.php ran DDL statements) or
any user writes landed after the deploy.

---

### 6.1 Preconditions (run these FIRST every single time)

```bash
cd /var/www/ulms

# Pick the rollback target:
#   (a) AUTO — the PREDEPLOY backup commit from §5.1
#       PREV_COMMIT=$(cat /var/backups/ulms/ulms-predeploy-commit-YYYYMMDD-HHMM.txt)
#   (b) MANUAL — a specific git commit SHA you know is good
#       PREV_COMMIT="abc123de"
# Verify by showing the 1-line summary:
git show --no-patch --oneline "$PREV_COMMIT"

# Locate matching backups (if doing full rollback, not code-only):
ls -la /var/backups/ulms/*-predeploy-*.{sql.gz,tar.gz} | head -20
```

---

### 6.2 Code-only Rollback (SAFE — only touches git-tracked files)

Use when only PHP/JS/CSS/markdown assets changed and no DB schema upgrade ran
(you ran upgrade.php and it printed "No upgrades available").  This rollback
CANNOT corrupt Moodle data because it does not touch the DB or moodledata.

```bash
# ---- what it AFFECTS: git-tracked files under /var/www/ulms ONLY
# ---- what it PRESERVES: .env, config.php, moodledata, vendor/,
#                         var/run/moodle-cron.lock, DB, all user uploads, backups.

sudo -u www-data bash -c "
  set -euo pipefail
  cd /var/www/ulms

  # (1) Reset the working tree AND INDEX to the known-good commit.
  #     --hard is safe here: git only touches tracked files; .env and moodledata
  #     are OUTSIDE the git tracking scope so they are untouched.
  git reset --hard '$PREV_COMMIT'

  # (2) Confirm rollback target matches what we expect.
  echo 'Rolled back to:'; git show --no-patch --oneline HEAD

  # (3) Run the refresh script with the EXACT same flags as a normal deploy.
  #     This re-runs upgrade.php --non-interactive (safe, schema now matches
  #     the code), purge_caches.php, opcache reset.  Moodle occasionally
  #     keeps cached plugin versions that must be flushed after code reset.
  bash scripts/ulms_refresh_live.sh --skip-overlay

  # (4) Verification gate.  If any of these fail, escalate to Full Rollback.
  APP_ENV=production php local/ulms_dashboard/cli/production_readiness_check.php
  php local/ulms_dashboard/cli/ops_healthcheck.php --max-cron-age-minutes=3 --max-backup-age-hours=25
  echo 'Code-only rollback OK.'
"

echo 'Service should be live on the rolled-back code.  Smoke-test the URLs in §4.12.'
```

---

### 6.3 Full Rollback — code + database + moodledata (RESTORE FROM §5.1 BACKUPS)

Use when the bad deploy ran a DB schema migration, inserted bad rows, or
produced user-visible data corruption.  Full rollback is a two-phase
restore of the exact point-in-time artefacts you captured in §5.1.  New
writes that happened AFTER the backup are LOST — this is why we require
a backup taken *immediately before* every deploy.

```bash
# ---- IMPORTANT: take MAINTENANCE MODE first so users cannot write during
# restore.  Moodle 4.x built-in maintenance mode:
sudo -u www-data php admin/cli/maintenance.php --enable
# Verify: any web request returns the branded maintenance page with 503.

# (1) Roll code back (SAME as §6.2 — git reset --hard + refresh).
sudo -u www-data bash -c "
  set -euo pipefail
  cd /var/www/ulms
  git reset --hard '$PREV_COMMIT'
  bash scripts/ulms_refresh_live.sh --skip-overlay
"

# (2) Restore the MATCHING moodledata tarball captured in §5.1.
#     OVERWRITES user uploads, cache dirs, Kortext CSV imports, backups.
#     Run as root because tarball contents are owned by www-data.
cd "$(grep '^MOODLE_DATA_PATH=' /var/www/ulms/.env | cut -d= -f2-)"
# Safety: do not extract into wrong dir — abort if the chosen tarball header
# does not look like a moodledata backup.
tar --to-stdout -xzf /var/backups/ulms/ulms-dataroot-predeploy-YYYYMMDD-HHMM.tar.gz \
  | head -1 > /dev/null   # aborts on corrupt tar (non-zero exit)
sudo tar --same-owner -xzp -f /var/backups/ulms/ulms-dataroot-predeploy-YYYYMMDD-HHMM.tar.gz .

# (3) Restore the MATCHING database SQL dump captured in §5.1.
#     For DigitalOcean Managed MySQL we use a temp credentials file to avoid
#     putting passwords on the mysqldump/mysql command lines.
DB_HOST="$(grep '^DB_HOST=' /var/www/ulms/.env | cut -d= -f2-)"
DB_PORT="$(grep '^DB_PORT=' /var/www/ulms/.env | cut -d= -f2-)"
DB_NAME="$(grep '^DB_NAME=' /var/www/ulms/.env | cut -d= -f2-)"
DB_USER="$(grep '^DB_USER=' /var/www/ulms/.env | cut -d= -f2-)"
DB_PASS_FILE="$(mktemp /tmp/ulms-dbpass.XXXXXX)"
chmod 600 "$DB_PASS_FILE"
grep '^DB_PASSWORD=' /var/www/ulms/.env | sed -E 's/^DB_PASSWORD=//; s/^"(.*)"$/\1/; s/^'"'"'(.*)'"'"'$/\1/' > "$DB_PASS_FILE"

# Double-check dump belongs to the same restore point by peeking at first 10 lines:
zcat "/var/backups/ulms/ulms-db-predeploy-YYYYMMDD-HHMM.sql.gz" | head -10

# Restore (drops and recreates per-table rows from the dump).
zcat "/var/backups/ulms/ulms-db-predeploy-YYYYMMDD-HHMM.sql.gz" \
  | mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
          --password="$(cat "$DB_PASS_FILE")" "$DB_NAME"

# Wipe DB password temp file from disk.
shred -u -z -n 3 "$DB_PASS_FILE" 2>/dev/null || rm -f "$DB_PASS_FILE"
unset DB_PASS_FILE DB_HOST DB_PORT DB_NAME DB_USER

# (4) Verification gate — run EXACTLY the same checks as post-deploy.
sudo -u www-data bash -c "
  cd /var/www/ulms
  APP_ENV=production php local/ulms_dashboard/cli/production_readiness_check.php
  php local/ulms_dashboard/cli/ops_healthcheck.php --max-cron-age-minutes=3 --max-backup-age-hours=25
  php local/ulms_dashboard/cli/ops_backup_smoke.php
"

# (5) Turn maintenance mode OFF.
sudo -u www-data php /var/www/ulms/admin/cli/maintenance.php --disable

# (6) Browser smoke test (§4.12 URLs) — confirm each role shell renders.
echo 'Full rollback OK.  If symptoms persist, escalate to DO Managed MySQL
point-in-time restore and restore from the most recent dataroot volume snapshot.'
```

---

### 6.4 Validation — confirm rollback worked

From an incognito window:
1. `https://learn.bellsuniversity.edu.ng/sign-in/` → returns HTTP 200, clean ULMS landing shell.
2. Sign in as the Manager account you created in §4.11 → `/management/` renders correctly.
3. Sign in as a test student → student dashboard shows courses (if already enrolled).
4. `APP_ENV=production php local/ulms_dashboard/cli/production_readiness_check.php` → exit 0.

Log the rollback (reason + PREV_COMMIT chosen + PREDEPLOY timestamp pair used +
result of §6.4 step 4) in the institutional change log.

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

### 11.9 DB connection fails with TLS handshake / certificate verification errors (DB_SSL_MODE=verify-full)

Moodle 4.5 native MySQLi driver maps `$CFG->dboptions['ssl'] = 'verify-full'`
(which ULMS sets automatically when you set `DB_SSL_MODE=verify-full` in `.env`)
to PHP flags `MYSQLI_CLIENT_SSL | MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT`
in `lib/dml/mysqli_native_moodle_database.php` lines 558–566.  The PHP
constant `MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT` historically performs
**certificate chain validation** in the underlying mysqlnd driver: the leaf
server certificate → any intermediates → a trusted root CA MUST all chain
correctly against the OS-wide trust bundle
(`/etc/ssl/certs/ca-certificates.crt` on Ubuntu 24.04).  Chain validation
also implicitly covers basic leaf validity (notBefore, notAfter, not-revoked
if CRL/OCSP stapling is enabled).  Hostname/CN/SAN-vs-`DB_HOST` matching
is applied by the OpenSSL/mysqlnd handshake when chain verification is on;
to get a **strong, independently verified guarantee** that BOTH checks
pass and you are connected to the intended DO hostname, ALWAYS run the
§2.2 `mysql --ssl-mode=VERIFY_IDENTITY` diagnostic BEFORE going into the
Moodle install wizard.  That CLI mode asserts both chain + identity checks
explicitly and is the authoritative diagnostic.

> **Post-connect portable TLS check (MySQL 5.7 → 8.0 → 8.4):** When you have
> a working CLI connection, run:
> ```
> SHOW SESSION STATUS WHERE Variable_name IN
>   ('Ssl_version','Ssl_cipher','Ssl_server_not_after','Ssl_sessions_reused');
> ```
> Do **NOT** use `SELECT @@ssl_version, @@ssl_cipher` — those were deprecated
> and removed in MySQL 8.4.  `Ssl_version` must be `TLSv1.2` or `TLSv1.3`;
> `Ssl_cipher` must be non-empty; `Ssl_server_not_after` is the notAfter of
> the server cert.  If these are empty while the connection succeeded, the
> client negotiated the session in plaintext (or TLS fell back to NULL) and
> you must debug why `--ssl-mode=REQUIRED`/`VERIFY_IDENTITY` wasn't honoured.

Typical failure modes:

| Symptom in Moodle / error text | Root cause | Fix |
|---|---|---|
| `mysqli::real_connect(): SSL operation failed with code 1. OpenSSL Error messages: error:0A000086:SSL routines::certificate verify failed` | OS CA bundle empty or outdated (most common). `ca-certificates` package is not installed, or `update-ca-certificates` has never been run after the OS was bootstrapped. | Run §2.1 install command which includes `ca-certificates`, then: `sudo apt-get install -y --reinstall ca-certificates && sudo update-ca-certificates --fresh`.  Confirm with `ls -l /etc/ssl/certs/ca-certificates.crt` that the bundle is ≥ 150 KB.  Do NOT copy/paste a single CA PEM onto the system: verify the whole bundle works using §2.2 `mysql --ssl-mode=VERIFY_IDENTITY ...` command. |
| `Peer certificate CN='*.b.db.ondigitalocean.com' did not match expected CN='164.92.xx.xx'` (or `IP address mismatch`) | You put a numeric IPv4 address in `DB_HOST=` instead of the **hostname** that the Managed MySQL wildcard cert is issued for.  Wildcard cert CN/SAN patterns match DNS names, not numeric IP addresses, in PHP mysqlnd / OpenSSL. | Fix `.env` `DB_HOST=` to the exact DO-managed DNS hostname, e.g. `bells-ulms-db-do-user-1234-0.b.db.ondigitalocean.com` — the same hostname shown in the DO Managed MySQL "Connection details" panel.  Do **not** use private VPC IPs directly unless you attach a matching CN/SAN to them (DO DBaaS issues wildcard DNS-name certs). |
| `dml_connection_exception (HY000/2003): Can't connect to MySQL server on '…ondigitalocean.com:25060' (113 "No route to host")` | Firewall egress blocked, Droplet VPC not peered, or DO trusted sources whitelist missing the Droplet. | Verify outbound 25060/TCP is reachable with `nc -zv …ondigitalocean.com 25060`; verify DO Managed MySQL → Settings → Trusted sources includes the Droplet's public IP / VPC; re-run §2.2 CLI test until it returns non-empty `Ssl_version` / `Ssl_cipher`.  This is NOT an SSL error — treat as network first. |
| Same as above, but `nc` works; the §2.2 CLI test passes with `--ssl-mode=REQUIRED` but fails `VERIFY_IDENTITY` | Hostname typo in DB_HOST (e.g. missing `-0-` vs `-1-` or regional mismatch) OR the DO server cert was rotated and the new intermediate is not in the (stale) CA bundle. | Copy-paste the hostname exactly from DO UI (do not hand-type).  `nslookup <hostname>` and `openssl s_client -connect <hostname>:25060 -starttls mysql -servername <hostname>` → check the returned CN/SANs + issuer chain and make them match.  Re-run `sudo update-ca-certificates --fresh`. |
| Errors only happen **after** a DB maintenance window; worked previously | DO rotated the server cert; a stale local CA bundle; or DB_HOST was changed during maintenance (unlikely but possible). | Re-run the full §2.2 VERIFY_IDENTITY CLI test — if it fails, diagnose there first (your diagnostic ground-truth) and only then update .env or reinstall ca-certificates.  Never "downgrade to `require`" as a workaround unless there is a documented, time-bound emergency and you have a temporary internal plan to re-enable verify-full within hours. |

> **CRITICAL LIMITATION — please read before debugging:** PHP MySQLi's
> `MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT` flag (unlike PostgreSQL's libpq
> `sslmode=verify-full`) does **not** expose a per-connection CA file or
> per-connection hostname override setting.  There is no PHP API in
> `mysqli_options()` to point MySQLi at a single `.pem` file for the trust
> chain (it would require `mysqli_ssl_set(..., ca, ...)` to be called
> BEFORE `real_connect()` — Moodle's native driver currently does NOT
> call `mysqli_ssl_set()` at all when only `dboptions['ssl']` is set).
> Validation therefore always uses the OS-wide trust bundle only.
> Trust any private / custom CA by appending its PEM to a file under
> `/usr/local/share/ca-certificates/` and running `update-ca-certificates`
> (NEVER hand-edit `/etc/ssl/certs/ca-certificates.crt` directly).
> For DigitalOcean Managed MySQL this is not needed because the server
> cert chains to a widely-trusted public CA already included in the
> `ca-certificates` package.
>
> Because of this driver limitation, `DB_HOST` **must** be the exact DNS
> hostname from DO's connection panel (not an IP, not a short alias in
> `/etc/hosts`) so that OpenSSL's default hostname matching can confirm
> the wildcard CN/SAN.

> **DO NOT** drop from `verify-full` → `require` as a long-term fix on a
> public-network DBaaS connection; that disables certificate chain
> validation entirely, leaving the connection exposed to MITM attacks.
> If you must use it temporarily, write a P1 ticket to return to
> `verify-full` with a deadline and log it in ULMS audit.

## 12. Backups & Disaster Recovery (Bells University)

Goal: recover to any point in the last 30 days within 60 minutes for code + DB + moodledata, RPO ≤ 1 day, RTO ≤ 60 min.

### 12.1 What to back up & where

| Backup scope | Source on Droplet | Primary backup method | Secondary backup method | Retention |
|---|---|---|---|---|
| Managed MySQL `ulms` DB | DigitalOcean Managed DB server, port 25060 | DO Managed DB **Point-in-Time Recovery** (PITR) + daily full snapshots (verify in DO Console → Managed MySQL → Backups) | Nightly `mysqldump` from the Droplet → gzip -9 → GPG symmetric → encrypted push to DO Spaces (S3-compatible, private bucket `bells-ulms-backups`, enable versioning) | PITR: 7 days minimum, set to 30 days if billing allows.  DO snapshots: 30 days.  mysqldump on Spaces: 90 days with 1st-of-month kept 1 year. |
| `/var/lib/moodledata` | `/var/lib/moodledata/*` (filedir, cache, sessions, temp, backup, repository, scormdirs, localcache) | Nightly `rsnapshot` or `rdiff-backup` (incremental) from the Droplet to a 2nd DO volume mounted at `/var/backups/ulms/` with noatime, plus daily tarball → DO Spaces encrypted | Real-time rsync to a second region bucket (cold storage) for disaster recovery — schedule via cron at 02:00 local Nigeria time (lowest LMS traffic) | On-volume incremental: 30 days.  Cold bucket: 1 year. |
| Moodle code + custom ULMS changes | `/var/www/universityLMS` (git) | Every deploy is a commit on `main` with a tag: `deploy/live-YYYYMMDD-HHMM-<short-hash>` → this alone is a full backup of code.  The tag must point to the exact code actually swapped into place in §13. | On every deploy `git bundle create /var/backups/ulms/ulms-code-<tag>.bundle --all` → GPG → Spaces.  | Tags kept forever in git (immutable).  Bundles on Spaces: 2 years. |
| Nginx + PHP-FPM pool config | `/etc/nginx/sites-available/learn.bellsuniversity.edu.ng.conf`, `/etc/nginx/nginx.conf`, `/etc/php/8.3/fpm/pool.d/www-ulms.conf`, `/etc/php/8.3/fpm/php.ini` overrides | `etckeeper` commit on each deploy + `dpkg -l` manifest. | `tar cf etc-configs.tar /etc/nginx /etc/php/8.3` → GPG → bundled with the code bundle from the deploy tag. | 2 years on Spaces. |
| Production environment file | `/var/www/.env` (DB password, Resend key, Kortext OAuth, SMTP, etc.) | 1Password / Bitwarden secure note (primary), NEVER git. | Encrypted `age -r <ops-pubkey>` copy bundled with the deploy bundle stored in a separate "secrets vault" bucket (NOT the same as the code/dump bucket, different IAM keys). | Indefinite; rotate keys any time an operator leaves the university. |

### 12.2 Operator actions — FIRST, before anything else

1. **Verify DO Managed DB backups are actually enabled.**
   DigitalOcean Console → Managed MySQL → `bells-ulms-db` → Backups → Point-in-time recovery: ON, schedule window set, daily snapshots: ON.  Capture a screenshot showing this ON state — keep it with the deploy audit log.  If it is OFF, **enable NOW** before any destructive step (§14 install/upgrade decision).
2. `apt-get install -y etckeeper rdiff-backup gnupg2 s3cmd` (s3cmd is for Spaces upload).
3. Create `/usr/local/sbin/ulms-nightly-backup.sh` using the reference in §12.4.  Installed mode 0700 root:root.
4. Install nightly cron as root:
   ```
   # m  h dom mon dow user  command
   13 2  *   *   *   root  /usr/local/sbin/ulms-nightly-backup.sh >> /var/log/ulms-backup.log 2>&1
   ```
5. Restore-test the backup ONCE within 24h of go-live (§12.5).  If restore-test fails, backups do not exist — treat this as BLOCKING go-live.

### 12.3 GPG encryption keys

Use **two** independent recipients for every backup GPG so that one person's lost key does not cause data loss:
```bash
# Ops primary: Bells IT manager
# Ops secondary: University external auditor / disaster-recovery contract
gpg2 --batch --passphrase-file /root/.ulms-backup-passphrase.txt \
     --symmetric --cipher-algo AES256 --compress-algo 1 dump.sql.gz
# store /root/.ulms-backup-passphrase.txt as chmod 0400 root:root, same secret
# recorded in 1Password.
```
**Age** (alternative to GPG for `.env` and tiny secrets) is also acceptable — use 2 recipients (`-r ops1 -r ops2`) to avoid single-key loss.

### 12.4 Reference nightly backup script

Save as `/usr/local/sbin/ulms-nightly-backup.sh` mode 0700 root:root.  Review each line BEFORE using:
```bash
#!/bin/bash
set -euo pipefail
umask 0027
STAMP="$(date +%Y%m%d-%H%M)"
OUTDIR="/var/backups/ulms/$STAMP"
mkdir -p "$OUTDIR"
chown root:root "$OUTDIR"
chmod 0700 "$OUTDIR"
PASSFILE="/root/.ulms-backup-passphrase.txt"

# ------------------------------------------------------------------
# (A) MySQL dump — DO NOT put the password on the command line.
# Pull DB_PASSWORD from /var/www/.env using a tiny awk snippet,
# write a TEMPORARY mysql --defaults-extra-file, delete immediately.
# ------------------------------------------------------------------
TMPMYSQL="$(mktemp)"
chmod 0600 "$TMPMYSQL"
awk -F= '
/^DB_HOST=/     {h=substr($0,index($0,"=")+1); gsub(/"/,"",h)}
/^DB_PORT=/     {p=substr($0,index($0,"=")+1); gsub(/"/,"",p)}
/^DB_NAME=/     {n=substr($0,index($0,"=")+1); gsub(/"/,"",n)}
/^DB_USER=/     {u=substr($0,index($0,"=")+1); gsub(/"/,"",u)}
/^DB_PASSWORD=/ {w=substr($0,index($0,"=")+1); gsub(/"/,"",w)}
END {
  printf("[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\nssl-mode=VERIFY_IDENTITY\n", h,p,u,w)
}' /var/www/.env >"$TMPMYSQL"

mysqldump --defaults-extra-file="$TMPMYSQL" \
  --single-transaction --quick --routines --triggers --events \
  --column-statistics=0 --set-gtid-purged=OFF \
  ulms | gzip -9 > "$OUTDIR/ulms-db-$STAMP.sql.gz"
rm -f "$TMPMYSQL"

gpg --batch --pinentry-mode loopback --passphrase-file "$PASSFILE" \
    --symmetric --cipher-algo AES256 --compress-algo 1 \
    "$OUTDIR/ulms-db-$STAMP.sql.gz"
shred -u "$OUTDIR/ulms-db-$STAMP.sql.gz"

# ------------------------------------------------------------------
# (B) Moodledata incremental + tarball of changed files only
# ------------------------------------------------------------------
rdiff-backup --force /var/lib/moodledata /var/backups/ulms/moodledata-rdiff
rdiff-backup --force --remove-older-than 30D /var/backups/ulms/moodledata-rdiff

tar --one-file-system --exclude=/var/lib/moodledata/cache \
    --exclude=/var/lib/moodledata/localcache --exclude=/var/lib/moodledata/temp \
    --exclude=/var/lib/moodledata/sessions --exclude=/var/lib/moodledata/trashdir \
    -I 'gzip -9' -cf - /var/lib/moodledata > "$OUTDIR/ulms-moodledata-$STAMP.tar.gz" 2>/dev/null || true
gpg --batch --pinentry-mode loopback --passphrase-file "$PASSFILE" \
    --symmetric --cipher-algo AES256 --compress-algo 1 \
    "$OUTDIR/ulms-moodledata-$STAMP.tar.gz"
shred -u "$OUTDIR/ulms-moodledata-$STAMP.tar.gz"

# ------------------------------------------------------------------
# (C) Code bundle + etc configs (nginx, PHP-FPM)
# ------------------------------------------------------------------
cd /var/www/universityLMS
TAG="deploy/live-${STAMP}-$(git rev-parse --short HEAD)"
git tag "$TAG" HEAD || true
git bundle create "$OUTDIR/ulms-code-$STAMP.bundle" --tags --branches --remotes
tar -I 'gzip -9' -cf "$OUTDIR/ulms-etc-$STAMP.tar.gz" \
  /etc/nginx/sites-available/learn.bellsuniversity.edu.ng.conf \
  /etc/nginx/nginx.conf \
  /etc/php/8.3/fpm/pool.d/www-ulms.conf \
  /etc/php/8.3/fpm/php.ini \
  /etc/php/8.3/cli/php.ini \
  2>/dev/null || true

# ------------------------------------------------------------------
# (D) Push encrypted outputs to DO Spaces bucket bells-ulms-backups.
#     ~/.s3cfg must be owned root, mode 0600, and contain the Spaces
#     access key for the private backup bucket (different region
#     recommended for disaster).  If s3cmd is not set up yet, the
#     local copies remain on /var/backups/ulms so nothing is lost.
# ------------------------------------------------------------------
if [ -r /root/.s3cfg ]; then
  s3cmd --ssl -c /root/.s3cfg sync --delete-removed --preserve \
    "$OUTDIR/ulms-*.gpg" "$OUTDIR/ulms-*.bundle" "$OUTDIR/ulms-etc-*.tar.gz" \
    "s3://bells-ulms-backups/nightly/$STAMP/" || true
fi

# Cleanup local unencrypted dumps if they still exist.
find "$OUTDIR" -type f -name '*.gz' -o -name '*.sql' | xargs -r shred -u

etckeeper commit -m "backup $STAMP" || true
echo "[ok ulms-backup] $STAMP finished, files in $OUTDIR, synced to s3://bells-ulms-backups"
```

### 12.5 Restore test (MANDATORY within 24h of first go-live)

Do NOT skip this.  Unverified backups = no backups.

```bash
# 1. Pick a recent encrypted dump:
B=/var/backups/ulms/YYYYMMDD-HHMM/ulms-db-YYYYMMDD-HHMM.sql.gz.gpg
# 2. Decrypt (temporary).
gpg --batch --pinentry-mode loopback --passphrase-file /root/.ulms-backup-passphrase.txt \
    --decrypt -o /tmp/restore-check.sql.gz "$B"
# 3. Create a TEMPORARY scratch DB `ulms_restorecheck_YYYYMMDD` on the same
#    Managed MySQL instance (different name, no writes to live DB).
# 4. Import and do two sanity checks:
zcat /tmp/restore-check.sql.gz | mysql --defaults-extra-file=/tmp/mysql-extra.cnf ulms_restorecheck_YYYYMMDD
TMPUSERS=$(mysql --defaults-extra-file=/tmp/mysql-extra.cnf -N -B ulms_restorecheck_YYYYMMDD -e "SELECT COUNT(*) FROM mdl_user WHERE deleted=0 AND username<>'guest'")
TMPCOURSES=$(mysql --defaults-extra-file=/tmp/mysql-extra.cnf -N -B ulms_restorecheck_YYYYMMDD -e "SELECT COUNT(*) FROM mdl_course WHERE id>1")
# Expect: TMPUSERS matches what you observed before the dump +/- 5; TMPCOURSES >= 0.
# 5. DROP the scratch DB immediately:
mysql --defaults-extra-file=/tmp/mysql-extra.cnf -e "DROP DATABASE ulms_restorecheck_YYYYMMDD"
shred -u /tmp/restore-check.sql.gz /tmp/mysql-extra.cnf
```
Moodledata restore check = extract 1 random student submission file from the encrypted tarball and confirm it SHA-sums to the same value as the live file in `/var/lib/moodledata/filedir`.

### 12.6 Deploy rollback (short version; see §13.5 for the full atomic swap + rollback procedure)

If the §13.4 post-deploy smoke test FAILS at any step, roll back with:
```bash
PREV_CODE=/var/www/universityLMS.prev
PREV_TAG=$(cat /var/backups/ulms/ulms-prev-deploy-tag.txt)
sudo -u www-data php8.3 /var/www/universityLMS/admin/cli/maintenance.php --enable
cd /var/www
# atomic revert the symlink (both .live.tmp then mv pattern below):
sudo mv universityLMS universityLMS.failed-deploy
sudo mv universityLMS.prev universityLMS
sudo -u www-data php8.3 /var/www/universityLMS/admin/cli/purge_caches.php
sudo nginx -t && sudo systemctl reload nginx php8.3-fpm
# If deploy failed AFTER a DB upgrade and schema changes are already applied,
# run the full §6.3 full rollback including MATCHING moodledata + DB restore
# using the backup captured in §13.1 PRE_DEPLOY_BACKUP.
sudo -u www-data php8.3 /var/www/universityLMS/admin/cli/maintenance.php --disable
```

## 13. Production Deployment — Atomic Swap Procedure (Bells University standard)

**DO NOT TRUST scripts/ulms_refresh_live.sh or local/ulms_dashboard/cli/phase3_purge_rebuild.php as generic deploy helpers.**  Both are reviewed and declared UNSAFE for routine deploy (phase3_purge_rebuild.php wipes moodledata, sessions, cache, demo data — it destroys production data).  Use ONLY this atomic swap procedure below.

### 13.0 Pre-requisites (confirm all before running anything)

- A. `.gitignore` no longer strips cache/backup/repository → deploy commit ≥ the commit containing this section.
- B. Deploy commit hash is known, `git fetch origin main; git log --oneline origin/main | head -1` returns it.
- C. Nginx config file §13.6 reference is in place and `nginx -t` passes on the current site.
- D. PHP-FPM 8.3 service is healthy: `systemctl status php8.3-fpm --no-pager` returns active (running).
- E. §12 backups (§12.2 step 1) confirmed running + §12.5 restore-tested successfully.  If either check is outstanding → stop and resolve before deploy.
- F. `trustedproxy` / Cloudflare checklist from `deploy/operator/cloudflare-checklist.md` completed; SSL/TLS mode = Full (strict).

### 13.1 PRE_DEPLOY_BACKUP (non-negotiable, 4 parts)

```bash
cd /var/www
STAMP="$(date +%Y%m%d-%H%M)"
COMMIT_NEW="$(git ls-remote origin main | awk '{print $1}')"
COMMIT_CURRENT="$(cd universityLMS && git rev-parse HEAD)"

# (1) DB — encrypted dump before touching anything — re-use §12.4 helper.
sudo /usr/local/sbin/ulms-nightly-backup.sh
# (2) Moodledata LATEST snapshot (fast hardlink snapshot).
sudo rsync -aHAX --delete --numeric-ids /var/lib/moodledata/ \
  /var/backups/ulms/moodledata-predeploy-$STAMP/ 2>&1 | tail -5
# (3) Tag current code in the PRE_DEPLOY state so rollback is 1 command.
sudo bash -c "cd universityLMS && git tag deploy/pre-$STAMP-$COMMIT_CURRENT HEAD"
# (4) Save the tag / commit for rollback.
echo "$COMMIT_CURRENT" | sudo tee /var/backups/ulms/ulms-predeploy-commit-$STAMP.txt
echo "deploy/pre-$STAMP-$COMMIT_CURRENT" | sudo tee /var/backups/ulms/ulms-predeploy-tag.txt
# (5) Save current deploy commit as previous.
sudo cp -a /var/www/universityLMS /var/www/universityLMS.prev
```

### 13.2 Clone and prepare new release in a parallel directory

Do NOT touch `/var/www/universityLMS` in-place until §13.3 atomic swap.

```bash
NEWSRC="/var/www/universityLMS.rev.$COMMIT_NEW"
sudo rm -rf "$NEWSRC"
sudo git clone --branch main --depth 1 --single-branch \
  https://github.com/gospat/universityLMS.git "$NEWSRC"
sudo bash -c "cd $NEWSRC && git reset --hard $COMMIT_NEW" 2>/dev/null || true
# Copy .env from LIVE — NEVER overwrite, NEVER edit, NEVER place under the new dir via any deploy helper.
sudo cp -a /var/www/.env "$NEWSRC/.env"
sudo chown root:root -R "$NEWSRC"
sudo chmod -R a-w "$NEWSRC"
sudo chmod -R u+w "$NEWSRC"
sudo find "$NEWSRC" -type d -exec chmod 0755 {} \;
sudo find "$NEWSRC" -type f -exec chmod 0644 {} \;
# Ensure config.php and .env are root-owned, NOT writable by www-data:
sudo chown root:root "$NEWSRC/config.php" "$NEWSRC/.env"
sudo chmod 0640 "$NEWSRC/config.php" "$NEWSRC/.env"
sudo chmod go-rwx "$NEWSRC/.env"
# Ensure moodledata stays READ ONLY for code — www-data writes go only to dataroot.
```

### 13.3 Run gates BEFORE swap

Run both of these.  If either exits non-zero → roll back the NEWSRC dir and do NOT swap:
```bash
# (A) TLS + DB connectivity + SSL verify-full gate (§4.2 DB_TLS_PROOF gate)
sudo -u www-data php8.3 "$NEWSRC/scripts/ulms_test_db_ssl.php"

# (B) Install vs Upgrade decision — 100% reads.  Capture output to a file for audit:
sudo -u www-data php8.3 "$NEWSRC/scripts/ulms_db_state_probe.php" /var/www/.env \
  2>&1 | sudo tee /var/backups/ulms/ulms-db-state-$COMMIT_NEW.txt
# inspect captured: STATE=EMPTY, INSTALLED_MATCH, INSTALLED_UPGRADE_REQUIRED, or DOWNGRADE_UNSAFE
#   - DOWNGRADE_UNSAFE → ABORT deploy — DB newer than code.
#   - INSTALLED_UPGRADE_REQUIRED → continue only if §13.1 backup is verified.
#   - EMPTY → CLI install (§13.4.1).
#   - INSTALLED_MATCH → no install or upgrade needed.

# (C) Syntax + sanity
sudo php8.3 -l "$NEWSRC/config.php"
sudo php8.3 -l "$NEWSRC/scripts/ulms_test_db_ssl.php"
sudo php8.3 -l "$NEWSRC/scripts/ulms_db_state_probe.php"
# (D) vendor (optional, only if you need Symfony HttpClient for Resend)
# cd "$NEWSRC" && sudo composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
```

### 13.4 Atomic swap, upgrade/install, cache purge, maintenance mode

```bash
# (1) Maintenance ON — prevents writes during schema change.
sudo -u www-data php8.3 /var/www/universityLMS/admin/cli/maintenance.php --enable

# (2) Upgrade only if STATE=INSTALLED_UPGRADE_REQUIRED:
STATE=$(grep -E '^STATE=' /var/backups/ulms/ulms-db-state-$COMMIT_NEW.txt | tail -1 | cut -d= -f2)
if [ "$STATE" = "INSTALLED_UPGRADE_REQUIRED" ]; then
  sudo -u www-data php8.3 "$NEWSRC/admin/cli/upgrade.php" --non-interactive --keep-maintenance-mode
fi
# (2b) FIRST INSTALL ONLY if STATE=EMPTY and this is the very first deploy:
if [ "$STATE" = "EMPTY" ]; then
  # Read the site admin password from 1Password into a TMP file, DO NOT put it on CLI.
  TMPPW=$(mktemp -u /tmp/.ulms-admin-pw.XXXXXX)
  # op read op://Private/ULMS/siteadmin-password > "$TMPPW"  # <-- ops to run; placeholder here.
  sudo chown www-data:www-data "$TMPPW"
  sudo -u www-data bash -c "
    php8.3 $NEWSRC/admin/cli/install_database.php \
      --lang=en \
      --wwwroot='https://learn.bellsuniversity.edu.ng' \
      --dataroot='/var/lib/moodledata' \
      --dbhost=\"\$(awk -F= '/^DB_HOST=/     {gsub(/\"/, \"\", \$2); print \$2}' /var/www/.env)\" \
      --dbport=\"\$(awk -F= '/^DB_PORT=/     {gsub(/\"/, \"\", \$2); print \$2}' /var/www/.env)\" \
      --dbname=\"\$(awk -F= '/^DB_NAME=/     {gsub(/\"/, \"\", \$2); print \$2}' /var/www/.env)\" \
      --dbuser=\"\$(awk -F= '/^DB_USER=/     {gsub(/\"/, \"\", \$2); print \$2}' /var/www/.env)\" \
      --dbpass=\"\$(awk -F= '/^DB_PASSWORD=/ {gsub(/\"/, \"\", \$2); print \$2}' /var/www/.env)\" \
      --prefix=mdl_ \
      --fullname='Bells University of Technology LMS' \
      --shortname='BELLS-ULMS' \
      --adminpassfile='$TMPPW' \
      --adminemail='lms-admin@bellsuniversity.edu.ng' \
      --non-interactive \
      --agree-license
    "
  sudo shred -u "$TMPPW"
  sudo -u www-data php8.3 "$NEWSRC/admin/cli/maintenance.php" --disable  # temp disable to verify, re-enabled below
  sudo -u www-data php8.3 "$NEWSRC/admin/cli/maintenance.php" --enable
fi

# (3) Cache purge on NEW code tree.
sudo -u www-data php8.3 "$NEWSRC/admin/cli/purge_caches.php"

# (4) Nginx / PHP-FPM pre-validate + update reference site config if it changed:
sudo cp -f "$NEWSRC/deploy/nginx/learn.bellsuniversity.edu.ng.conf" \
          /etc/nginx/sites-available/learn.bellsuniversity.edu.ng.conf
sudo ln -sf /etc/nginx/sites-available/learn.bellsuniversity.edu.ng.conf \
            /etc/nginx/sites-enabled/learn.bellsuniversity.edu.ng.conf
sudo rm -f /etc/nginx/sites-enabled/default
sudo cp -f "$NEWSRC/deploy/php-fpm/www-ulms.conf.24.04" \
          /etc/php/8.3/fpm/pool.d/www-ulms.conf
# REMOVE the default www pool (pool name redeclaration is fatal).
sudo mv -f /etc/php/8.3/fpm/pool.d/www.conf /etc/php/8.3/fpm/pool.d/www.conf.orig 2>/dev/null || true
sudo nginx -t
sudo php-fpm8.3 -t
sudo systemctl restart php8.3-fpm
sudo systemctl reload nginx

# (5) Atomic swap: place NEW code at /var/www/universityLMS.live.tmp then RENAME.
#     This is 1 syscall rename() — no window where the dir is missing.
cd /var/www
sudo mv universityLMS universityLMS.failed.$STAMP
sudo mv "$NEWSRC" universityLMS
sudo chown -R root:root /var/www/universityLMS
sudo find /var/www/universityLMS -type d -exec chmod 0755 {} \;
sudo find /var/www/universityLMS -type f -exec chmod 0644 {} \;
sudo chown root:root /var/www/universityLMS/config.php /var/www/universityLMS/.env
sudo chmod 0640 /var/www/universityLMS/config.php /var/www/universityLMS/.env
sudo chmod go-rwx /var/www/universityLMS/.env

# (6) Post-swap cache purge + opcache reset + verify.
sudo -u www-data php8.3 /var/www/universityLMS/admin/cli/purge_caches.php
# opcache reset via cachetool (if installed):
# sudo cachetool.phar opcache:reset --fcgi=/run/php/php8.3-fpm.sock 2>/dev/null || sudo systemctl reload php8.3-fpm
sudo systemctl reload php8.3-fpm

# (7) Maintenance OFF after post swap checks pass §13.4.1:
sudo -u www-data php8.3 /var/www/universityLMS/admin/cli/maintenance.php --disable
```

#### 13.4.1 Post-swap smoke test — BLOCKING

Run ALL locally on the Droplet before going into §14 go-live checks.

```bash
# (a) HTTP 200 for login with correct host header
curl -fsS -o /dev/null -w "HTTP_STATUS=%{http_code}\n" \
  -H 'Host: learn.bellsuniversity.edu.ng' \
  --resolve learn.bellsuniversity.edu.ng:443:127.0.0.1 \
  https://learn.bellsuniversity.edu.ng/login/index.php
# Expect HTTP_STATUS=200 (or 303 → login page).

# (b) HTTP to HTTPS redirect returns 301:
curl -fsS -o /dev/null -w "HTTP_STATUS=%{http_code} REDIRECT=%{redirect_url}\n" \
  -H 'Host: learn.bellsuniversity.edu.ng' \
  --resolve learn.bellsuniversity.edu.ng:80:127.0.0.1 \
  http://learn.bellsuniversity.edu.ng/
# Expect 301 → https://learn.bellsuniversity.edu.ng/

# (c) Sensitive files MUST return 403:
for p in /.env /.git/config /config.php /README.md /INSTALL.txt /.env.example; do
  printf "  %-30s -> " "$p"
  curl -sS -o /dev/null -w "%{http_code}\n" \
    -H 'Host: learn.bellsuniversity.edu.ng' \
    --resolve learn.bellsuniversity.edu.ng:80:127.0.0.1 \
    "http://learn.bellsuniversity.edu.ng$p"
done
# Expect: all 403 (NOT 200, NOT 404).

# (d) PHP-FPM health:
curl -fsS -u status:status -H "Host: 127.0.0.1" http://127.0.0.1/fpm-status || echo "fpm-status endpoint not mounted — expected"
sudo systemctl status php8.3-fpm --no-pager | head -5

# (e) Moodle bootstrap via CLI:
sudo -u www-data php8.3 /var/www/universityLMS/admin/cli/purge_caches.php
# Expect exit 0, no PHP warnings or missing class cache fatal.

# (f) Cron one-shot verification:
sudo -u www-data /usr/bin/flock -n /var/lib/moodledata/tmp/.cron.lock \
  /usr/bin/php8.3 /var/www/universityLMS/admin/cli/cron.php | tail -5
# Expect no FATAL errors, output mentions scheduled tasks run.

# (g) Moodle CLI health checks:
sudo -u www-data php8.3 /var/www/universityLMS/local/ulms_dashboard/cli/ops_healthcheck.php
sudo -u www-data php8.3 /var/www/universityLMS/local/ulms_dashboard/cli/production_readiness_check.php 2>&1 | tail -15
# Expect 0 FAIL lines, exit 0.

# (h) DB_TLS_PROOF re-verify on the NEW code:
sudo -u www-data php8.3 /var/www/universityLMS/scripts/ulms_test_db_ssl.php
```

### 13.5 Rollback (if any step in §13.4.1 fails)

```bash
sudo -u www-data php8.3 /var/www/universityLMS/admin/cli/maintenance.php --enable
cd /var/www
sudo mv universityLMS universityLMS.failed-after-swap.$STAMP
sudo mv universityLMS.prev universityLMS
sudo chown -R root:root universityLMS
sudo find universityLMS -type d -exec chmod 0755 {} \;
sudo find universityLMS -type f -exec chmod 0644 {} \;
sudo chown root:root universityLMS/config.php universityLMS/.env
sudo chmod 0640 universityLMS/config.php universityLMS/.env
sudo chmod go-rwx universityLMS/.env
sudo -u www-data php8.3 universityLMS/admin/cli/purge_caches.php
sudo systemctl reload php8.3-fpm nginx
# If DB upgrade already happened in §13.4(2), FULL rollback per §6.3.
# Otherwise only the code swap above is sufficient and DB is still consistent.
sudo -u www-data php8.3 universityLMS/admin/cli/maintenance.php --disable
```

## 14. Cron, Scheduled Tasks & Email

### 14.1 Moodle cron — production canonical entry

```bash
# Install exactly this crontab for user www-data:
sudo crontab -u www-data -l | { cat; echo '* * * * * /usr/bin/flock -n /var/lib/moodledata/tmp/.cron.lock /usr/bin/php8.3 /var/www/universityLMS/admin/cli/cron.php >> /var/log/moodle/cron.log 2>&1'; } | sudo crontab -u www-data -

# Create the log dir and lock dir on first install:
sudo mkdir -p /var/log/moodle /var/lib/moodledata/tmp
sudo chown www-data:adm /var/log/moodle
sudo chmod 2750 /var/log/moodle
sudo chown www-data:www-data /var/lib/moodledata/tmp
sudo chmod 2770 /var/lib/moodledata/tmp
# Logrotate for moodle cron (place under /etc/logrotate.d/moodle-cron):
cat <<'LOGROT' | sudo tee /etc/logrotate.d/moodle-cron
/var/log/moodle/*.log {
  daily
  rotate 60
  missingok
  notifempty
  compress
  delaycompress
  copytruncate
  su www-data adm
  create 0640 www-data adm
}
LOGROT
sudo chmod 0644 /etc/logrotate.d/moodle-cron
```

Verify immediately with a one-shot run (see §13.4.1 f).  The `flock -n` guard means overlapping runs SKIP silently — never remove it.

### 14.2 ULMS-specific scheduled tasks (local plugins)
- Kortext entitlement sync: runs via `local/ulms_kortext/cli/cron_sync_adoptions_and_entitlements.php` — scheduled through Moodle's built-in scheduled_task registered in `local/ulms_kortext/db/tasks.php` (no separate cron entry).
- Exam batch auto-grade: `local/ulms_exam/cli/batch_autograde_cron.php` (same, via Moodle scheduler).
- Mail resend queue: `local/ulms_mail/cli/send_test_email.php` is one-shot; the actual resend transport runs per-Moodle-cron-task as configured in `local_ulms_mail`.

### 14.3 Email smoke test (SMTP/Resend)

Run only after `.env` contains the production Resend key or SMTP password:
```bash
sudo -u www-data php8.3 /var/www/universityLMS/local/ulms_mail/cli/send_test_email.php --to=ops-verify@bellsuniversity.edu.ng
```
Expected: exit 0, recipient inbox contains the test email in <2 min.  If it does not → check §11.7.

## 15. Final Go-live Declaration Checklist

Only mark PASSED after the check has actually been run.  **Do not mark "LMS is live" unless all rows in the "Verified on Production Server" column are PASS.**

| ID | Check | Verified in dev/build env | Verified on Production Server | Not yet verified |
|---|---|---|---|---|
| GL-01 | `php -l config.php` PASS on deploy commit | | | ▢ |
| GL-02 | Clean git clone of deploy commit → `cache/classes/cache.php`, `backup/backup.class.php`, `repository/filepicker.js` present | | | ▢ |
| GL-03 | `scripts/ulms_test_db_ssl.php` against real DO endpoint → exit 0, `Ssl_version` TLS 1.2/1.3, `Ssl_cipher` non-empty, `final flags = 0x40000800` | | | ▢ |
| GL-04 | `scripts/ulms_db_state_probe.php` → STATE resolved correctly (not DOWNGRADE_UNSAFE) | | | ▢ |
| GL-05 | `nginx -t` PASS; `php-fpm8.3 -t` PASS; reloads succeed | | | ▢ |
| GL-06 | HTTP → HTTPS 301 redirect works on public curl | | | ▢ |
| GL-07 | `curl -I https://learn.bellsuniversity.edu.ng/.env` → 403; same for `/config.php` → 403 | | | ▢ |
| GL-08 | Public `https://learn.bellsuniversity.edu.ng/login/index.php` → HTTP 200; renders login form | | | ▢ |
| GL-09 | Super admin login + admin dashboard load with no errors | | | ▢ |
| GL-10 | Test course created + file upload + download works (use test teacher account, not real users) | | | ▢ |
| GL-11 | §12 backups confirmed on; §12.5 restore-test completed | | | ▢ |
| GL-12 | Cron one-shot run (§14.1) succeeds; `/var/log/moodle/cron.log` is populated after 2 min | | | ▢ |
| GL-13 | Email smoke test (§14.3) delivered to real inbox | | | ▢ |
| GL-14 | Cloudflare SSL/TLS mode = Full (strict) with origin CA cert installed on Droplet | | | ▢ |
| GL-15 | Session cookie Secure + HttpOnly + SameSite Strict set when browsing over HTTPS | | | ▢ |
| GL-16 | Moodledata 0750 www-data:www-data, source tree root-owned, not writable by www-data | | | ▢ |
| GL-17 | Install or Upgrade DB decision actually executed correctly (empty=install, match=no change, upgrade=upgrade) + backup taken first | | | ▢ |
| GL-18 | Atomic swap deployment (§13) used, no in-place overwrites, .env not touched, rollback steps ready | | | ▢ |
| GL-19 | `ulms_refresh_live.sh` AND `phase3_purge_rebuild.php` NOT run during deploy | | | ▢ |

Go-live requires GL-01 through GL-19 all PASS on the Production Server column.  Any GL-NOT-VERIFIED → remain at "deployment complete, not yet live" until operator returns evidence for each outstanding row.

