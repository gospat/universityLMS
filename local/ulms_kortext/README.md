# local_ulms_kortext

Kortext digital-textbook adoption + entitlement sync plugin for ULMS.
Connects ULMS academic Programme/Course structure with the Kortext
digital bookshelf so enrolled students get automatic access to the
textbooks their lecturers have adopted for each course.

---

## 1. Responsibilities

- Maintains a catalogue of **Adoptions** — "Course (Moodle) CSC101 adopts Textbook X (Kortext ISBN/Ebook ID)."
- Runs a **periodic sync cron** that transforms Adoptions + Enrolments into **Entitlements** — "Student U (in course C that adopted textbook X) is granted access on Kortext's side."
- Provides an **adapter factory** so the same ULMS codebase runs on (a) local dev with no outbound calls (Mock adapter), (b) staging with Kortext's test environment, (c) production with Kortext's live REST API — without code changes.
- Implements **per-(user, adoption) idempotency keys** so re-running the same cron 2× can never double-entitle a student or create duplicate rows on Kortext's side.
- Provides a LTI 1.3 configuration reader (`kortext_reads_lti_config`) so students can deep-link into their Kortext bookshelf from within a Moodle course / activity.
- Emits Moodle Events on state changes (`adoption_created`, `adoption_updated`, `adoption_archived`, `entitlement_granted`, `entitlement_failed`) so other plugins / audit logs can react.
- Ships a Super Admin Integrations UI at `/super-admin/integrations/kortext` (portal section `admin_integrations`) to toggle adapter, re-trigger sync, and inspect recent idempotent log rows.

---

## 2. Architecture

```
┌────────────────────────────────────────────────────────────────────┐
│ ULMS Moodle App                                                    │
│                                                                    │
│  adoptions.php  ←  Admin/Super Admin UI: list/create/archive adopt │
│         │                                                          │
│         ▼                                                          │
│  adoption_service.php  →  stores to {local_ulms_kortext_adoptions} │
│         │                                                          │
│         ▼                                                          │
│  adoption_sync_service.php                                         │
│       - build (user, adoption) pairs from enrolled students        │
│       - per pair: idempotency_key() → check entitlement_log        │
│       - if not-yet-done → call Kortext adapter.entitle(user,book)  │
│       - persist result in entitlement_log with idempotency key     │
│         │                                                          │
│         ▼                                                          │
│  adapter_factory → kortext_rest_production_adapter (production)    │
│                     or kortext_mock_adapter (dev/staging)          │
│                     or kortext_reads_lti_config (LTI)              │
│                                                                    │
└────────────────────┬───────────────────────────────────────────────┘
                     │  HTTPS 443 only (production adapter)
                     ▼
             api.kortext.co.uk (Kortext REST entitlements API)
```

---

## 3. Adapter Factory & Configuration

All adapter selection goes through
[classes/local/service/adapter/kortext_adapter_factory.php](./classes/local/service/adapter/kortext_adapter_factory.php)
which returns the correct adapter based on the resolved configuration.  The
factory honours the standard ULMS tier:
**Database (Super Admin settings)  →  .env  →  compile-time default (Mock).**

### 3.1 Available Adapters

| Adapter class | Use case | Outbound calls? |
|---|---|---|
| `kortext_mock_adapter` | Local dev, CI, first stages of staging | **NO** — never calls out.  Generates deterministic mock entitlement tokens so flows can be tested without Kortext credentials. |
| `kortext_rest_production_adapter` | Production + staging against Kortext's test tenant | **YES** → `https://api.kortext.co.uk/…` — requires `KORTEXT_*` env vars set. |
| `kortext_reads_lti_config` | Course-level LTI launch configuration (both) | NO — reads configuration for how to render the LTI deep-link button inside courses; does not entitle. |

### 3.2 `.env` Configuration (middle layer)

```
# Kortext adapter selector (mock | production).  Super Admin settings
# panel "Integrations → Kortext" override (highest layer) once live.
KORTEXT_ADAPTER=mock

# Production REST credentials — required only when KORTEXT_ADAPTER=production
KORTEXT_BASE_URL=https://api.kortext.co.uk/v3
KORTEXT_API_KEY=ktx_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
KORTEXT_TENANT_ID=your-tenant-uuid
KORTEXT_INSTITUTION_ID=INS-00000

# LTI
KORTEXT_LTI_CLIENT_ID=lti-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
KORTEXT_LTI_DEPLOYMENT_ID=lti-depl-xxxxxxxxxxxxxxxxxxxxx
```

### 3.3 Database Override (Highest Layer)

Set in Super Admin → Integrations → Kortext.  Any setting saved here persists
through `.env` edits and restarts.  Use this to toggle from `mock` to
`production` on your live deployment without shelling into the box.

---

## 4. Idempotency Model

The critical anti-double-entitlement guard.

```php
// adoption_sync_service.php:138
private function idempotency_key(int $userid, int $adoptionid): string {
    return 'ktx-ent|v1|uid=' . $userid . '|aid=' . $adoptionid . '|rev=1';
}
```

Before any entitle call, the sync service hashes the (userid, adoptionid)
pair into a deterministic string and looks up
`local_ulms_kortext_entitlement_log WHERE idempotency_key = ?`.  If a row
already exists → skip (status shows in UI as "Skipped (idempotent)").  Only
new pairs call the real adapter → then persist the result into the log
table with that key so retries are safe.

What this means for operations:
- **Re-running the sync cron is always safe.** Server timed out mid-run? Kill it and launch again; any entitlement already granted this cycle is skipped.
- **Rolling back a failed deploy never grants books twice.** Resetting DB + moodledata snapshot and re-running sync yields identical outcome sets.
- **Bulk-retry is a one-click operation in the Super Admin UI** per (user, adoption) — clear only the one entitlement log row you actually want to re-grant, then re-run sync.  Never bulk-clear the log table without a rollback backup snapshot.

---

## 5. Cron & Sync

### 5.1 When Sync Runs

```bash
# File: local/ulms_kortext/cli/cron_sync_adoptions_and_entitlements.php
# Schedule: registered as a Moodle scheduled task → runs daily by default
#           (configure the task schedule via Super Admin → Scheduled Tasks.)
php local/ulms_kortext/cli/cron_sync_adoptions_and_entitlements.php
```

What the script does, in order:
1. Locks via Moodle task runner (prevents concurrent runs).
2. Loads all active (non-archived) Adoptions.
3. For each Adoption loads enrolled students via ULMS enrolment tables (filtered by study level, again using the SSOT guard).
4. For each (student userid, adoptionid) pair → compute idempotency key.
5. Skip if already logged; otherwise call the adapter.
6. Persist result (success, error, Kortext transaction id) into `local_ulms_kortext_entitlement_log`.
7. Emit Moodle Events: `entitlement_granted` on success, `entitlement_failed` on error.
8. Emit ULMS operational error log via `local_ulms_dashboard_log_operational_error` on any unhandled exception so ops_healthcheck picks it up.

### 5.2 Manual On-demand Sync

Use the Super Admin → Integrations → Kortext UI:
- **"Resync all pending"** → runs step 2–8 (same as cron, but immediate).  Safe to run concurrently? No — use the UI button only when the daily cron has not already fired (the scheduled task's built-in lock prevents overlap).
- **"Re-sync one failed entitlement"** → deletes only the chosen log row and re-runs that single (user, adoption) pair.  This is the correct recovery step for a transient Kortext 5xx.

---

## 6. Adoptions CRUD

[adoptions.php](./adoptions.php) lists/creates/archives adoptions, each
with:

| Field | Meaning |
|---|---|
| Moodle Course | Moodle course id (autocomplete — cannot create adoption for a non-existent course). |
| Kortext Ebook ID | Kortext's stable identifier for the adopted digital textbook. |
| ISBN (optional) | Printed book ISBN for cross-ref / library reporting. |
| Academic session (archival metadata only) | Optional — shows on receipts / library reports.  Never used in active enrolment gating or level-filtering logic. |
| Status | Active / Archived.  Archiving an adoption stops new entitlements; existing ones are preserved until explicitly revoked (revoke UI available for exceptional cases only — see Super Admin Integrations screen). |

The `Adoption events (created / updated / archived)` are emitted as standard
Moodle Events so the provisioning audit log can show them alongside user
changes.

---

## 7. Production Checklist for Kortext Enablement

```
☑  KORTEXT_ADAPTER=production (or DB panel override set to production)
☑  All 4 KORTEXT_* REST creds populated in .env
☑  Outbound HTTPS 443 to api.kortext.co.uk open from the app server
   (verify: curl -I https://api.kortext.co.uk/v3/status returns non-zero HTTP)
☑  At least one Active Adoption exists (adoptions.php shows non-zero rows)
☑  At least one student is enrolled in that Adoption's Moodle Course
☑  cron_sync_adoptions_and_entitlements.php ran once (CLI or scheduled task log
   shows run complete; Kortext entitlement log table contains rows)
☑  ops_backup_smoke.php passes (idempotency log rows are backed up along with
   the DB; you need them in any rollback)
☑  PRC still green (adding a plugin does not break any existing assertion —
   if it does, re-verify routes/SSOT/controllers)
```

After this 9-point checklist the integration is live.  You can confirm per-user
access by visiting the Moodle Course as a student → the Kortext deep-link
button (rendered using the `kortext_reads_lti_config` adapter) launches the
book directly into Kortext's reader for entitled users.
