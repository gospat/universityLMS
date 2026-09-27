# UNIVERSITY LMS (ULMS)

A customised Moodle 4.5 based University Learning Management System with dedicated
Student, Lecturer, Admin (Management) and Super Admin portals, plus transactional
Resend email delivery, Kortext digital-textbook adoption/entitlement sync,
Exam engine, Academic structure CRUD + CSV/XLSX bulk imports,
Bill Catalogue + Direct Billing, and an automated **Production Readiness Checker**.

> ⚠ **Documentation vs. Runtime-verification baseline distinction:**
> The documentation you are reading (and the companion
> [ULMS_DEPLOYMENT_SYNC.md](./ULMS_DEPLOYMENT_SYNC.md)) describes the CURRENT
> repository HEAD.  The last commit where the automated 52-assertion Production
> Readiness Checker (`production_readiness_check.php`) was actually executed
> against a LIVE environment and returned **52/52 PASS + exit 0** is commit
> **`b46a1fe8`** ("production hardening: resend transport independence + PRC
> checker fixes").  All commits SINCE `b46a1fe8` (including the current HEAD
> `51ad91cf` and this batch of changes) have consisted of DOCUMENTATION-ONLY
> edits, deployment-script hardening, and CLI safety guards — they do not
> change the runtime application code paths that the PRC script asserts on.
> You MUST re-run `production_readiness_check.php` on your LIVE target
> environment after deployment and confirm it reports 52/52 PASS before you
> mark any commit as "production verified".
>
> **Official production-ready declaration runbook:** See the companion guide
> [ULMS_DEPLOYMENT_SYNC.md](./ULMS_DEPLOYMENT_SYNC.md) section *10. Production-
> Ready Declaration Checklist* — after you run the PRC on the real environment,
> tick every item in §10 before sign-off.

---

## 1. Repository Layout & Components

| Directory | Purpose | Documentation |
|---|---|---|
| `local/ulms_auth/` | Landing pages, clean routes, sign-in, password-reset, rate-limit + security events log, forced password-change workflow | [README](./local/ulms_auth/README.md) |
| `local/ulms_dashboard/` | 4 role portals (Student, Lecturer, Admin, Super Admin), User provisioning (single + CSV bulk), Analytics, CLI ops tools, automated production-readiness checker | [README](./local/ulms_dashboard/README.md) |
| `local/ulms_academics/` | College / Department / Programme / Course CRUD + CSV/XLSX bulk import + Programme ↔ Moodle course mappings | [README](./local/ulms_academics/README.md) |
| `local/ulms_exam/` | Exam engine (create, question banks, take, autosave, autograde cron, audit logs) | — (embedded lang strings + `exam_service.php`) |
| `local/ulms_mail/` | Resend HTTP mail transport (3-tier duck-typed: injected → Symfony HttpClient → native ext-cURL fallback), password reset / welcome delivery, send_test_email CLI | [README](./local/ulms_mail/README.md) |
| `local/ulms_kortext/` | Kortext digital textbook adoptions + entitlement sync (factory adapter: Mock ↔ REST production, idempotency keys, cron) | [README](./local/ulms_kortext/README.md) |
| `theme/ulms_university/` | Custom ULMS Moodle theme (ULMS shell, navbar, layout grid, SCSS compile-verified) | — (theme config + SCSS source inside) |
| `scripts/` | `ulms_refresh_live.sh` (deploy refresh: **Standard PHP-FPM is the primary path**, Docker Compose is a `--docker` opt-in secondary path; see file inline-docs for the full flag list) + `install_post_merge_hook.sh` (auto-refresh after `git pull`) | [Deployment guide §4 + §5](./ULMS_DEPLOYMENT_SYNC.md) |

---

## 2. Server Prerequisites

Platform: Bells University production target is an Ubuntu 24.04 LTS DigitalOcean
Droplet running PHP 8.3-FPM behind Nginx, with DigitalOcean Managed MySQL and
the production domain `https://learn.bellsuniversity.edu.ng`.  The same stack
works on any Ubuntu 24.04 server.

| Component | Production minimum (verified) | Notes |
|---|---|---|
| **OS** | Ubuntu 24.04 LTS (DigitalOcean Droplet) | Other Linux distros work; paths and systemd unit names below are Ubuntu-24.04 specific. |
| **PHP** | 8.3.x with FPM SAPI (php-fpm 8.3) | `apt install php8.3-fpm php8.3-cli`.  Composer platform `>=8.1.0`; 8.3 is the Bells University standard. |
| **PHP extensions (MANDATORY)** | `mysqli pdo_mysql curl mbstring json xml xmlreader zip gd intl opcache iconv openssl ctype zlib simplexml dom spl pcre hash fileinfo sodium` | Extracted from [composer.json](./composer.json) `require` (plus `ext-mysqli` which Moodle needs for MySQL but is only listed under `suggest` upstream — **for ULMS + MySQL deployments mysqli is mandatory, not optional**). |
| **PHP extensions (recommended)** | `tokenizer soap exif` | Improves Moodle Networking / LTI / image metadata.  Install via `apt install php8.3-*`. |
| **Database** | DigitalOcean Managed MySQL 8 (default for Bells University) — or local MySQL 8.0 / MariaDB 10.6+ | Collation `utf8mb4_unicode_ci`, DB driver = `mysqli`.  **Never use the MySQL root account as the application user;** provision a dedicated `ulms_rw` user with the least-privilege grants listed in `.env.example`. |
| **Web server** | Nginx 1.24+ (php-fpm 8.3 via TCP or Unix socket) | Apache 2.4 works but Nginx + FPM is the Bells University default. HTTPS with TLS 1.2+ **mandatory** in production so Secure session cookie + SameSite=Strict flags auto-enable when `$CFG->wwwroot` starts with `https://`. |
| **Disk (dataroot)** | ≥ 10 GB free, **MUST live outside the webroot** | Default production path for Bells University: `/var/lib/ulms/moodledata` (per `.env.example`).  Mounted separately on ULMS deployments so backups + dataroot snapshots are independent of the git repo. |
| **Shell tools** | `bash`, `rsync`, `flock` (util-linux), `git` | Used by deploy refresh scripts + the single-flight cron wrapper. |
| **Composer** | Composer 2.x (optional, not mandatory) | Not required — Resend mail works via the built-in ext-cURL fallback.  Installing Composer vendor (`composer install --no-dev`) unlocks Symfony HttpClient connection pooling only.  When absent, `--composer-required` must never be used unless you first install Composer. |
| **Outbound HTTPS** | 443 to `api.resend.com`, 443 to `api.kortext.co.uk` (when Kortext is live), 443 to DO Managed MySQL port 25060 | Resend and Kortext integrations call out over TLS 1.2+.  DO Managed MySQL typically listens on a non-standard port (e.g. 25060) with TLS enabled. |

---

## 3. Configuration Tier: Database → Environment → Compile-time Defaults (DB>ENV>defaults, strongest→weakest)

ULMS enforces a strict three-level configuration hierarchy (DB>ENV>defaults)
matching the project standard.  Any setting can be overridden at multiple
levels, but the **higher layer always wins**.  Order of precedence (strongest
→ weakest):

1. **Database (Moodle `config_plugins` table + `$CFG` saved preferences)**
   Admin edits made via `/management/settings/` or the Super Admin settings
   panel persist to the database and survive restarts.  These take precedence
   over everything else.
2. **`.env` file (or shell injected env vars)**
   Resend keys, DB credentials, wwwroot, dataroot, lockout thresholds, mail
   transport selector, Kortext API credentials.  Populated by parsing the
   repository root `.env` at boot (see `config.php` lines 95–170).
   `.env` is **never overwritten by deploy refresh scripts** (see
   [ULMS_DEPLOYMENT_SYNC.md §4](./ULMS_DEPLOYMENT_SYNC.md#4-production-safety-notes)).
3. **Compile-time defaults hardcoded in `config.php`**
   Fallbacks only (e.g. default DB host `127.0.0.1`, default mail transport
   `moodle`).  Never rely on these in production.

The helper used for reading is `ulms_env(string $key, $default)` defined in
[config.php lines 156–171](./config.php#L156-L171) (`$_ENV` first, then
`getenv()`, finally the PHP default).  **Do not hardcode secrets directly in
`config.php`.**

---

## 4. Quick Start (Developer Workstation)

```bash
# 1. Clone + bootstrap
git clone https://github.com/gospat/universityLMS.git
cd universityLMS

# 2. Prepare environment (edit values before saving)
cp .env.example .env
#   Minimum: DB_*, APP_URL, MOODLE_DATA_PATH,
#            ULMS_MAIL_TRANSPORT=resend, RESEND_API_KEY, RESEND_FROM_EMAIL

# 3. Start built-in PHP dev server (NOT for production)
php -S 127.0.0.1:8000

# 4. Browser bootstrap install
open http://127.0.0.1:8000/install.php
# -> walk the Moodle install wizard -> create initial siteadmin account

# 5. Install ULMS cron (idempotent)
php local/ulms_dashboard/cli/install_cron.php --install
php local/ulms_dashboard/cli/install_cron.php --status

# 6. (Optional) seed demo academic chain for local dev
php local/ulms_dashboard/cli/seed_demo_academic_chain.php

# 7. Run production-readiness checker (must exit 0)
APP_ENV=local php local/ulms_dashboard/cli/production_readiness_check.php
```

For a production server follow the step-by-step runbook in
[ULMS_DEPLOYMENT_SYNC.md §6](./ULMS_DEPLOYMENT_SYNC.md#6-step-by-step-deployment-runbook).

---

## 5. Mail Transports

ULMS supports two mail transports, selected via the `ULMS_MAIL_TRANSPORT` env
variable (see [config.php §4](./config.php#L295-L367)).

| Transport | `ULMS_MAIL_TRANSPORT=` | Use case |
|---|---|---|
| **Moodle/SMTP** | `moodle` (default) | Fallback — delegates to Moodle's native `email_to_user()` using the SMTP block from `.env.example`. |
| **Resend HTTP API** | `resend` | Recommended.  Password-reset, welcome/provisioning emails, manual password-reset flows, receipts.  3-tier duck-typed HTTP transport in [resend_mail_service.php](./local/ulms_mail/classes/local/service/resend_mail_service.php): (1) injected client → (2) Symfony `HttpClient` if vendor classes exist → (3) native `ext-cURL` anonymous class.  **Composer vendor installation is not required.** |

Verify after deploy:

```bash
# Transport check (part of PRC)
APP_ENV=production php local/ulms_dashboard/cli/production_readiness_check.php \
  | grep -E 'mail:transport|mail:http-client|mail:resend-service|mail:api-key|mail:from-email'

# Send a real smoke email through Resend (CLI only)
php local/ulms_mail/cli/send_test_email.php --to=sysadmin@yourdomain.com
```

Full Resend setup steps: [local/ulms_mail/README.md](./local/ulms_mail/README.md).

---

## 6. Security Gates (Preflight Defaults)

All default security gates are controlled via env in `.env.example` and can be
tightened further in the Super Admin settings panel (which writes to the DB,
the highest layer):

- **Login attempt rate limit** (env `ULMS_LOCKOUT_THRESHOLD=5`, `ULMS_LOCKOUT_WINDOW=900`, `ULMS_LOCKOUT_DURATION=1800`) — three independent buckets per IP, per username, and the (IP, username) combo.
- **Password-reset rate limit** (env `ULMS_PASSWORD_RESET_WINDOW=1800`) — 5 attempts / identifier+IP / 30 min.
- **Guest login** disabled by default (`ULMS_GUEST_LOGIN_BUTTON=0`).
- **Autologin guests** disabled (`ULMS_AUTO_LOGIN_GUESTS=0`).
- **Force password change after admin reset** (`ULMS_PASSWORD_CHANGE_LOGOUT=1` + user preference `auth_forcepasswordchange`) — forces next-login re-auth and logs out all other sessions.
- **Debug output** always off in production (`APP_DEBUG=false`, `ULMS_WEB_DEBUG_DISPLAY=0` → `$CFG->debug=0`, `$CFG->debugdisplay=0`).
- **Session cookies** — HttpOnly on, SameSite=Lax (default), Secure flag auto-enables when `$CFG->wwwroot` begins with `https://`.
- **Write-endpoint protection** — every 22/22 write endpoint is behind `require_login()`; every 7/7 POST write endpoint checks the Moodle `sesskey` CSRF token.
- **Audit trails** — provisioning, login failure, password reset, exam attempts, Kortext entitlements are all audit-logged.  Production logs are scrubbed of passwords/tokens via `scrub_string_secrets()` before writing.
- **Idempotency keys** — Kortext entitlements and test-email sends record unique idempotency tokens in their log tables, so re-running the same sync job twice cannot double-entitle.

---

## 7. Related Guides

| Document | Contents |
|---|---|
| [ULMS_DEPLOYMENT_SYNC.md](./ULMS_DEPLOYMENT_SYNC.md) | **Production runbook**: architecture, deploy steps, rollback, cron install, maintenance scripts, go-live wipe checklist, ops healthcheck/backup-smoke, troubleshooting. |
| [.env.example](./.env.example) | Every env variable with production-safety annotations (do NOT commit real secrets). |
| [INSTALL.txt](./INSTALL.txt) | Legacy short Moodle install summary; prefer the Quick Start above or the full deployment runbook. |
| [UPGRADING.md](./UPGRADING.md) | Upstream Moodle 4.5 core upgrade notes (kept verbatim for upstream reference). |
| [CONTRIBUTING.md](./CONTRIBUTING.md) | Upstream Moodle Tracker contribution rules; for ULMS-local patches use Github PRs against `gospat/universityLMS:main`. |
| `local/ulms_*/README.md` (4 files + 2 new component READMEs) | Component architecture, CLI inventories, bulk-import template ordering. |

---

## 8. First-line Verification After Every Deploy

Run these three commands after pulling updates on any environment; zero failures
indicates a healthy deploy:

```bash
# (1) 52-assertion production readiness checker — MUST exit 0 and show 0 FAIL
APP_ENV=production php local/ulms_dashboard/cli/production_readiness_check.php

# (2) Cron wrapper installed and holding a single-flight lock?
php local/ulms_dashboard/cli/install_cron.php --status

# (3) DB connect + dataroot writable + last cron age OK?
php local/ulms_dashboard/cli/ops_healthcheck.php
```
