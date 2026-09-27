# local_ulms_dashboard

ULMS portal dashboard and management plugin.  Renders the 4 role portals
(Student, Lecturer, Admin, Super Admin), drives user provisioning + CSV bulk
import, exposes the automated 52-assertion Production Readiness Checker
(PRC) + an ops-focused battery of CLI tools.

---

## 1. Responsibilities

- Renders the Student, Lecturer, Admin (Management), and Super Admin dashboard experiences via dedicated portal controllers + service classes.
- Services (`classes/local/service/*.php`) decide shell context, header context, navigation state, and section content.  **All render calls go through 2 canonical helpers or the shared wrapper:**
  - `local_ulms_dashboard_render_page_header()` + `local_ulms_dashboard_start_shell_wrap()` (low-level canonical pair used by `lecturer_portal.php`),
  - `local_ulms_dashboard_render_role_portal_page()` (the **recommended** wrapper used by `admin_portal.php`, `super_admin.php`, `student_portal.php` — internally calls both canonical helpers so new controllers never drift).
- Keeps Admin (Management) and Super Admin as separate portal identities while still allowing the Super Admin to inherit Admin feature permissions (identity preservation is asserted by the PRC check `ui:superadmin-identity-preservation`).
- Serves management tools: user management, provisioning (single + CSV bulk), analytics, settings, production-readiness checks, audit logs.
- Ships 13 CLI tools for operations.

---

## 2. Role Portals & Controllers

| Controller file | Route base (clean URL) | Shell renderer | Notes |
|---|---|---|---|
| [student_portal.php](./student_portal.php) | `/student/` | `render_role_portal_page()` wrapper | Sections: `dashboard`, `courses`, `grades`, `timetable`, `progress` |
| [lecturer_portal.php](./lecturer_portal.php) | `/lecturer/` | Direct canonical pair (`render_page_header` + `start_shell_wrap`) | Sections: `dashboard`, `courses`, `exams` |
| [admin_portal.php](./admin_portal.php) (aka Management) | `/management/` | `render_role_portal_page()` wrapper | Sections: `dashboard`, `users`, `provisioning`, `bulkupload`, `academics`, `analytics` |
| [super_admin.php](./super_admin.php) | `/super-admin/` | `render_role_portal_page()` wrapper | Separate identity; 10 sections: `admin_dashboard`, `admin_users`, `admin_administrators`, `admin_institution`, `admin_health`, `admin_integrations`, `admin_security`, `admin_auditlogs`, `admin_reports`, `admin_settings`.  Super Admin navigating to shared Management features keeps the Super Admin shell (identity preservation verified by PRC). |
| [user_management.php](./user_management.php), [user_provisioning.php](./user_provisioning.php), [user_provisioning_report.php](./user_provisioning_report.php), [user_view.php](./user_view.php), [user_edit.php](./user_edit.php) | `/management/users/*` | Management shell, wrapper-controlled | Single + CSV bulk user provisioning, edit, audit trail, view per-user |
| [admin.php](./admin.php), [academics_levels.php](./academics_levels.php), [analytics.php](./analytics.php), [lecturer_courses.php](./lecturer_courses.php), [student_courses.php](./student_courses.php), [student_grades.php](./student_grades.php), [student_progress.php](./student_progress.php), [student_timetable.php](./student_timetable.php) | Section pages of each portal | Delegated portal shell | |

---

## 3. Production Readiness Checker

CLI-only (web SAPI returns 403).

```bash
APP_ENV=production php local/ulms_dashboard/cli/production_readiness_check.php
```

Exit codes (call as `php production_readiness_check.php ; echo exit=$?`):
| exit literal | meaning |
|---|---|
| exit=0 | All checks passed.  OK to deploy / sign off. |
| exit=1 | Non-critical non-security failures only (e.g. only cURL transport, no Symfony HttpClient).  Still operational but note the warnings. |
| exit=2 | **Contains at least one CRITICAL SECURITY FAILURE** (mail transport broken, CSRF/login rate limits missing, debug=1 on live, unguarded write endpoints).  Block deploy until resolved. |

**The expected production output is:**
```
All ULMS production-readiness checks passed.
```

What it checks (52 assertions):
- 78 canonical routes registered and HTTP reachable (200/301/302/403 allowed)
- 4 role SSOT route validators (Student 30, Lecturer 36, Admin 50, Super Admin 66)
- Super Admin identity preservation (navgroups=4 when visiting Management users as siteadmin)
- Super Admin 10 sections functional (all return valid, non-empty main panels with URLs)
- Theme SCSS compile passes; compiled CSS contains `ulms-shell`, `ulms-portal-navbar`, `ulms-layout-grid` markers
- All 4 ULMS plugins enabled (ulms_auth / ulms_dashboard / ulms_mail / theme_ulms_university)
- Mail transport configured correctly: presence of Resend service class, valid Resend key format (`re_` + ≥32 chars), HTTP transport available (either Symfony HttpClient OR ext-cURL), API key + from email set
- Security gates: debug off, debugdisplay off, session HttpOnly, session SameSite=Lax, Secure cookie skipped only on plaintext localhost, guest login suppressed, wwwroot scheme HTTPS or localhost, DB driver = mysqli, moodlecourse FK on programme-course mapping table exists + fallback install.xml validated
- UI canonical drift detection: 0 inline `ulms-page-header` constructions detected across all portal controllers; wrapper users correctly counted
- CRUD smoke tests: summary_cards / normalise_filters / user_listing_5rows / validate_form_manual / validate_row_csv / recent_activity_3 all return usable results
- Write-endpoint guards: `require_login` on 22/22, `POST sesskey` CSRF on 7/7, CSV header ordering sanity (csv_line=46;output_header_line=0)

---

## 4. CLI Operations Scripts Inventory

All scripts are `CLI_SCRIPT` guarded, tighten `open_basedir` when allowed, disable
`display_errors`, and raise memory limits.  Full descriptions with usage flags
are in the companion deployment guide [ULMS_DEPLOYMENT_SYNC.md §7](../../ULMS_DEPLOYMENT_SYNC.md#7-cli-operations-scripts-inventory).

| Script | Run by | Cadence | Notes |
|---|---|---|---|
| **[cli/production_readiness_check.php](./cli/production_readiness_check.php)** | Devops, CI | After every deploy | 52 assertions; MUST exit=0 on prod |
| **[cli/install_cron.php](./cli/install_cron.php)** | Sysadmin | First deploy + upgrades | `--install / --uninstall / --status / --help`.  Idempotent install of the single-flight cron wrapper. |
| **[cli/run_moodle_cron.sh](./cli/run_moodle_cron.sh)** | System crontab | Every minute | `flock()` single-flight lock; daily rotated logs at `${REPO_ROOT}/var/log/cron/moodle-cron-YYYY-MM-DD.log`.  Runs `admin/cli/cron.php`. |
| **[cli/ops_healthcheck.php](./cli/ops_healthcheck.php)** | Uptime monitor | Every 5–15 min | `--max-cron-age-minutes=180 --max-backup-age-hours=48`.  Checks DB connect / system_context / dataroot writable / cron freshness / backup freshness.  JSON-parseable structured output. |
| **[cli/ops_backup_smoke.php](./cli/ops_backup_smoke.php)** | Nightly ops | After backup job | Confirms the latest backup on disk actually restores cleanly (schema sanity + rowcounts). |
| **[cli/phase3_purge_rebuild.php](./cli/phase3_purge_rebuild.php)** | Maintenance only | Before go-live + maintenance windows | Pristine state utility: wipes 8 moodledata dirs (cache/sessions/sitedata/lang/styles_debug/styles_mashup/localcache/temp), purges MUC caches, rebuilds theme/admin tree/DB/course caches.  **Logs out every user.**  Production-safety guard: **blocked in `APP_ENV=production`** unless the explicit `--i-am-sure` flag is supplied on the command line.  Use `--help` for exact list of directories preserved vs. contents-deleted.  Never run as part of a routine deploy; use `admin/cli/purge_caches.php` + `admin/cli/upgrade.php --non-interactive` instead (which is what `scripts/ulms_refresh_live.sh` runs). |
| **[cli/seed_demo_academic_chain.php](./cli/seed_demo_academic_chain.php)** | Dev only | Before CSV import | Populates a sample College → Department → Programme → Course chain with programme-course mappings.  Production-safety guard: **blocked unconditionally when `APP_ENV=production`** unless `--force-unsafe-in-production` is supplied (for staging-prep validation only).  Real production data must be loaded via the Management → Academics → Bulk upload CSV/XLSX workflow, never via this seeder.  Defaults to dry-run report-only; requires `--apply` to actually write rows even in dev/QA. |
| **[cli/repair_academic_profiles.php](./cli/repair_academic_profiles.php)** | Sysadmin | When manual DB edits leave orphans | Reconciles orphan collegeCode/departmentCode references in user profiles. |

---

## 5. Deployment Safety (from CI)

Run these 3 commands **in order** after every deploy to confirm a healthy state:

```bash
# (1) code + data + config health
APP_ENV=production php local/ulms_dashboard/cli/production_readiness_check.php
# Expected: exit 0, last line = "All ULMS production-readiness checks passed."

# (2) cron installed
php local/ulms_dashboard/cli/install_cron.php --status
# Expected: "Installed — wrapper run_moodle_cron.sh scheduled every 1 minute"

# (3) operational health
php local/ulms_dashboard/cli/ops_healthcheck.php --max-cron-age-minutes=3 --max-backup-age-hours=25
# Expected: all checks[*].ok = true
```

These 3 commands are also documented in the top-level [README.md §8](../../README.md#8-first-line-verification-after-every-deploy).

---

## 6. Architecture Notes for Developers

- New role portals / new controllers MUST NOT write inline `<div class="ulms-page-header"…>` — use the role wrapper `local_ulms_dashboard_render_role_portal_page(ControllerClass, 'view_name', $datafetcher, ?$headermapper)` or the canonical pair.  The PRC detector `ui:canonical-controllers` fails any controller that uses inline construction.
- Management pages use `local_ulms_dashboard_get_management_portal_service()` so Admin stays inside `/management/` and Super Admin retains `/super-admin/` while still sharing the same feature code.  Super Admin identity preservation is checked by `ui:superadmin-identity-preservation`.
- The ULMS dashboard pagelayout is `ulmsdashboard` rendered by the theme (`theme/ulms_university/`) — there is no standalone duplicate stylesheet for the shell (verified by the PRC `theme:ulms-shell` + `theme:ulms-portal-navbar` + `theme:ulms-layout-grid` CSS marker checks after SCSS compile).
- Write endpoints must call `require_login()` + either `confirm_sesskey()` or `$data = data_submitted()` followed by sesskey validation.  The PRC guard checker `audit:guards` fails if any of the 22 tracked write endpoints are missing their required guard.
