# UNIVERSITY LMS (ULMS)

A customised Moodle 4.5 based University Learning Management System with dedicated
Student, Lecturer, Admin (Management) and Super Admin portals, plus transactional
Resend email delivery, Kortext digital-textbook adoption/entitlement sync,
Exam engine, Academic structure CRUD + CSV/XLSX bulk imports,
Bill Catalogue + Direct Billing, and an automated **Production Readiness Checker**.

> **Official production-ready declaration:** See the companion guide
> [ULMS_DEPLOYMENT_SYNC.md](./ULMS_DEPLOYMENT_SYNC.md) section *8. Production-Ready
> Declaration Checklist* — the automated `production_readiness_check.php` returns
> 52/52 PASS and exit 0 after every clean deploy.

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
| `scripts/` | `ulms_refresh_live.sh` (deploy refresh) + `install_post_merge_hook.sh` (auto-refresh after `git pull`) | [Deployment guide](./ULMS_DEPLOYMENT_SYNC.md) |

---

## 2. Server Prerequisites

Minimum production environment (verified on commit `b46a1fe8`):

| Component | Minimum | Notes |
|---|---|---|
| **PHP** | 8.2+ (8.3 recommended) | Required extensions: `mysqli pdo_mysql curl mbstring json xml zip gd intl opcache`. `curl` is **mandatory** when the Resend mail transport is used without Composer vendor. |
| **Database** | MySQL 8.0 / MariaDB 10.6+ | Collation `utf8mb4_unicode_ci`, DB driver = `mysqli` (default in `.env.example`). |
| **Web server** | Nginx 1.24+ (preferred) or Apache 2.4+ | HTTPS mandatory in production. The [ULMS cron wrapper](./local/ulms_dashboard/cli/run_moodle_cron.sh) is run independently of the web server. |
| **Disk (dataroot)** | ≥ 5 GB free (grows with backups/cache) | **Must live outside webroot**; `.env.example` variable `MOODLE_DATA_PATH`. |
| **Shell (optional)** | `bash`, `rsync`, `flock` (provided by `util-linux`) | Used by deploy refresh scripts and the single-flight cron wrapper. |
| **Composer (optional)** | Composer 2.x | Not mandatory — Resend mail works via the built-in cURL fallback. Installing Composer vendor unlocks Symfony HttpClient connection pooling. |
| **Outbound HTTPS** | 443 to `api.resend.com`, (optionally) `api.kortext.co.uk` | Resend and Kortext integrations call out over TLS 1.2+. |

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
