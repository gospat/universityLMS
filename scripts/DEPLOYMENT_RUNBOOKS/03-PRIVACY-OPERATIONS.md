# ULMS Privacy Operations Runbook
## GDPR / FERPA / NDPR (Nigeria) — PII Lifecycle
## Bells University of Technology — https://learn.bellsuniversity.edu.ng

**Owner**: Data Protection Officer (DPO)
**Co-owners**: ULMS Engineering, Registrar, Bursary
**Regulatory references**: Nigeria NDPR 2019, EU GDPR 2016/679 (where applicable to international students), FERPA (US students on exchange).

This runbook describes the OPERATIONAL implementation of privacy inside ULMS — i.e. how an operator
actually fulfils a Subject Access Request, performs erasure, audits cookies, or notifies the NDPC.
The public-facing privacy policy lives at `/privacy.php`; the cookie policy at `/cookie-policy.php`.

---

## 1. Consent Management

### 1.1 Bulk-Export All Consent Records
Run as DPO delegate (never publicly). Produces a signed CSV for audit.

```sql
-- Export all consent records (cookie, marketing, terms, data-processing)
SELECT
  u.id                 AS user_id,
  u.username           AS matric_staff_no,
  u.firstname,
  u.lastname,
  u.email,
  c.category           AS consent_category,   -- strictly_necessary | analytics | marketing | terms_v1 | data_processing
  c.version            AS policy_version,
  c.consent_status     AS status,             -- 1 = granted, 0 = withdrawn
  c.granted_at         AS granted_at,
  c.withdrawn_at       AS withdrawn_at,
  c.ip_address         AS source_ip,
  c.user_agent         AS ua
FROM local_ulms_consent c
JOIN mdl_user u ON u.id = c.userid
ORDER BY c.granted_at DESC
INTO OUTFILE '/var/lib/mysql-files/ulms_consent_export_<YYYYMMDD>.csv'
FIELDS TERMINATED BY ',' ENCLOSED BY '"' LINES TERMINATED BY '\n';
```
After export:
```bash
# Copy + encrypt for hand-off
gpg --encrypt --recipient <dpo-fingerprint> /var/lib/mysql-files/ulms_consent_export_<YYYYMMDD>.csv
sha256sum /var/lib/mysql-files/ulms_consent_export_<YYYYMMDD>.csv.gpg > /var/lib/mysql-files/ulms_consent_export_<YYYYMMDD>.sha256
```

### 1.2 User-Initiated Consent Withdrawal
Every authenticated user can withdraw non-strictly-necessary consent at:
  **User menu → Preferences → Privacy → Manage consent**
  (URL: `/user/policy.php`)

This page renders:
- Current consent status per category.
- One-click "Withdraw" button for analytics and marketing (strictly_necessary has no Withdraw button, per law).
- Explanation of effects of withdrawal (e.g. "Withdrawing analytics consent will disable the _ga cookies
  for your browser immediately and your user id will be removed from the analytics aggregate table").

Operator actions when a user emails a withdrawal (user unable to self-serve):
```sql
UPDATE local_ulms_consent
SET consent_status = 0, withdrawn_at = NOW(), notes = CONCAT(IFNULL(notes,''), '; operator-withdrawn per email ticket <TICKET>')
WHERE userid = <USER_ID>
  AND category IN ('analytics','marketing');
-- Invalidate cache
php admin/cli/purge_caches.php
```

### 1.3 Cookie Banner Schema v1
The banner on first visit renders 3 categories matching the `/cookie-policy.php` table AND the DB `category` column above.

| Category | Purpose | Lawful basis | User may withdraw? |
|----------|---------|--------------|--------------------|
| `strictly_necessary` | Session cookies, CSRF tokens, consent banner state cookie, remember-me opt-in | NDPR Art. 5.2(c) contract necessity + Art. 5.1(f) legitimate interest for security | NO (banner radio disabled and greyed out) |
| `analytics` | Google Analytics 4, internal learning analytics aggregate | NDPR Art. 5.1(a) consent | YES |
| `marketing` | Meta/Facebook Pixel for programme advert retargeting | NDPR Art. 5.1(a) consent | YES |

Consent state persists in `ulms_consent` cookie (see §5 Cookie Audit) AND in the DB row per user.

---

## 2. Data Subject Rights Workflow

All DSAR (Data Subject Access Request) intake must be logged in the DSAR register
(Google sheet / ticketing system owned by DPO). Do NOT process requests received via Slack DM or
unrecorded phone call.

### 2.1 Right of Access — Subject Access Request (SAR)
**Timeline**: 30 calendar days from receipt of verified identity (NDPR Art. 27; GDPR Art. 15).
Extendable to 60 days only with written notice to data subject, citing complexity.

Steps:
1. **Receive** request via the form on `/privacy.php?action=sar` OR verified email to `dpo@bellsuniversity.edu.ng`.
2. **Verify identity** within 2 working days:
   - Students: compare full name, matric number, programme, date of birth.
   - Staff: compare staff ID, department, work email, government ID last 4 digits.
   - Parents / guardians: require signed letter of authority + minor birth certificate.
   If identity not verified within 7 days: close request with notice; do NOT disclose any data.
3. **Collect PII** for `<USER_ID>` (run the collection script as DPO delegate):
   ```sql
   -- (a) Core user profile (mdl_user)
   SELECT id, username, firstname, lastname, email, phone1, phone2, city, country,
          institution, department, address, lastip, lastlogin, picture
   FROM mdl_user WHERE id = <USER_ID>;

   -- (b) Grades
   SELECT gi.itemname, gg.finalgrade, gg.rawgrademax, gg.timemodified
   FROM mdl_grade_grades gg
   JOIN mdl_grade_items gi ON gi.id = gg.itemid
   WHERE gg.userid = <USER_ID>;

   -- (c) Attendance registers
   SELECT c.fullname AS course, al.statusid, att.sessdate, al.timemodified
   FROM mdl_attendance_log al
   JOIN mdl_attendance_sessions att ON att.id = al.sessionid
   JOIN mdl_attendance a ON a.id = att.attendanceid
   JOIN mdl_course c ON c.id = a.course
   WHERE al.studentid = <USER_ID>;

   -- (d) Exam attempts (quiz)
   SELECT q.name AS exam_name, qa.attempt, qa.sumgrades, q.grade AS grade_max,
          qa.timestart, qa.timefinish
   FROM mdl_quiz_attempts qa
   JOIN mdl_quiz q ON q.id = qa.quiz
   WHERE qa.userid = <USER_ID>;

   -- (e) Receipts / payments
   SELECT receipt_no, amount, currency, gateway, paid_at, purpose, matric_no
   FROM local_ulms_receipts WHERE userid = <USER_ID>;

   -- (f) Consent records
   SELECT category, version, consent_status, granted_at, withdrawn_at
   FROM local_ulms_consent WHERE userid = <USER_ID>;

   -- (g) local_ulms tables (admissions, profile custom fields, bursary extensions)
   --     Run the privacy subsystem helper:
   ```
   ```bash
   sudo -u www-data php admin/tool/privacy/cli/export_data_for_user.php --userid=<USER_ID>
   ```
   This emits a zip at `/var/moodledata/privacy/export/<USER_ID>_<SHA>.zip` containing every
   plugin that implements `provider::export_user_preferences()` / `export_data_for_user()`.
4. **Package and encrypt** — zip the raw SQL CSVs + the Moodle privacy export together into one archive:
   ```bash
   zip -e /tmp/DSAR_<USER_ID>_<YYYYMMDD>.zip /var/moodledata/privacy/export/*_<USER_ID>_*.zip ./datarun_*.csv
   # Password: generated 20-char random, sent separately via SMS, never in same email
   ```
5. **Deliver** via encrypted email or secure portal. Retain the delivery tracking number + date.
6. **Log delivery** in the DSAR register: ticket ref, delivered date, courier/method, DPO sign-off.

### 2.2 Right to Rectification (Art. 16 GDPR, Art. 28 NDPR)
1. Receive request, verify identity (same as §2.1 step 2).
2. Compare the requested correction to the source document (admission letter, HR record, JAMB result).
3. If valid:
   ```php
   // Via CLI for audit trail (never direct DB UPDATE for profile fields on live data)
   sudo -u www-data php admin/cli/update_user.php --id=<USER_ID> --email=new@domain.ng --phone1=+234...
   // Custom fields use the profile tool:
   sudo -u www-data php user/profile/update_field_cli.php --userid=<USER_ID> --field=matricno --value=<NEW>
   ```
4. If request rejected: written explanation within 10 working days with appeal path to DPO.

### 2.3 Right to Erasure (Art. 17 GDPR / "Right to be Forgotten")
**Caution — Nigeria education records retention**: The National Universities Commission (NUC)
mandates 7-year retention of academic records post-graduation. Legal hold overrides erasure.
Always confirm with the Registrar before any erasure.

Preconditions before running:
- Identity verified (§2.1 step 2).
- DPO + Registrar co-signed approval in DSAR register.
- Confirm no open disciplinary case, ongoing litigation, unpaid fees, or FERPA/NUC retention conflict.
- Confirm backup retention window (see §3) allows user's last activity to exit backup
  (or document the justified exception).

Steps:
1. Run the privacy subsystem delete provider:
   ```bash
   sudo -u www-data php admin/tool/privacy/cli/delete_data_for_user.php --userid=<USER_ID>
   ```
   This calls `delete_data_for_user()` on every `local_ulms_*` privacy provider, plus core.
2. Hard-scrub residual PII columns that GDPR Article 17 requires actual erasure on (do NOT rely on soft-delete alone for email/phone/address):
   ```sql
   UPDATE mdl_user SET
     firstname   = 'Deleted',
     lastname    = CONCAT('User', id),
     email       = CONCAT('deleted+', id, '@local.invalid'),
     phone1      = NULL,
     phone2      = NULL,
     city        = '',
     country     = '',
     institution = '',
     department  = '',
     address     = '',
     lastip      = '0.0.0.0',
     picture     = 0,
     imagealt    = NULL,
     idnumber    = CONCAT('HASHED_', SHA2(CONCAT(id, '<SECRET-PII-SALT>'), 256)),
     auth        = 'nologin',
     suspended   = 1,
     deleted     = 1,
     timemodified = UNIX_TIMESTAMP()
   WHERE id = <USER_ID>;

   -- Anonymise grades (retain aggregates for institutional stats, remove personal linkage via idnumber)
   -- Grades keyed by userid FK: keep row but userid is now a deleted-user; acceptable if idnumber is hashed.

   -- Anonymise receipts (financial 7-year retention may require data to remain — DPO decision):
   -- If erasure order overrides retention: hash name/email columns but keep amount + date + receipt_no.
   UPDATE local_ulms_receipts SET payer_email = NULL, payer_name = SHA2(CONCAT(payer_name,'<SALT>'),256) WHERE userid = <USER_ID>;
   ```
3. **Verify erasure across tables** — run the SAR query (§2.1 step 3) again; no row should contain
   unhashed name / email / phone. Save the verification output to DSAR ticket.
4. **Backup retention note**: Rolling daily backups are retained 30 days; weekly backups 12 weeks;
   monthly end-of-semester 7 years. Log that the user's data will exit the rolling window on date `<DATE>`;
   the 7-year archive is NUC/FERPA exempt and explicitly excluded from erasure requests.

### 2.4 Right to Data Portability (Art. 20 GDPR, Art. 31 NDPR)
Deliver machine-readable data in standard formats (JSON + CSV, not PDF).
Use the same collection as SAR (§2.1 step 3) plus:
```bash
# CSV set (grades, attendance, exams, receipts) + JSON summary
sudo -u www-data php admin/tool/privacy/cli/export_data_for_user.php --userid=<USER_ID> --format=json
# Additional JSON export:
mysqldump --user=ulms_ro --password="$DB_RO_PASS" --compact ulms_prod \
  --where="userid=<USER_ID>" mdl_grade_grades mdl_attendance_log mdl_quiz_attempts local_ulms_receipts \
  | python3 -c 'import sys,json; print(json.dumps(sys.stdin.read()))' > /tmp/portability_<USER_ID>.json
# Hash the export; send + log like SAR.
```

### 2.5 Right to Object / Restrict Processing
1. Receive request + verify identity.
2. Record the restriction in DB:
   ```sql
   INSERT INTO local_ulms_privacy_restrictions (userid, restriction_type, scope, effective_from, reason, ticket_ref)
   VALUES (<USER_ID>, 'OBJECT_PROCESSING', '<analytics|marketing|all>', NOW(), '<reason text>', '<DSAR-TICKET>');
   -- Mirror in user account flag for core processing:
   UPDATE mdl_user SET policyagreed = 2 WHERE id = <USER_ID>;   -- 2 = objected (enum agreed=1 / objected=2 / not-set=0)
   ```
3. Disable the objected processing within 7 days:
   - Analytics: delete user row in analytics_user_map, disable GA4 user_properties for that user_id.
   - Marketing: remove from all mailing lists (Mailchimp / SendGrid API call by email).
   - All processing except contract-necessary: suspend user account and record justification.
4. Notify user in writing of completion.

---

## 3. PII Retention Schedule

Minimum retention periods. Do NOT shorten any row below without DPO + Registrar co-signature.
Longer retention may apply where contract, litigation hold, or donor-grant conditions require it.

| Data Category | Source tables | Retention Period | Legal Basis / Reference | Disposal Method |
|---------------|---------------|------------------|-------------------------|-----------------|
| **Student academic records** (admission, grades, transcript, graduation) | `mdl_user`, `mdl_grade_grades`, `local_ulms_admissions` | **7 years after graduation or withdrawal** | NUC Mandatory Minimum Retention for Universities; FERPA §99.607 (US exchange) | Hash name/phone/email in place; retain anonymised grade rows for institutional KPIs |
| **Exam attempts / quiz submissions** | `mdl_quiz_attempts`, `mdl_quiz_slots`, per-module submissions | **5 years after course completion** | University exam regulations | Anonymise user link |
| **Attendance registers** | `mdl_attendance_log`, `mdl_attendance_sessions` | **3 years after semester close** | Internal audit | Delete rows |
| **Financial receipts / fee records** | `local_ulms_receipts`, `local_ulms_payments*` | **7 years from date of payment** | CITA / FIRS Nigeria tax records (S.91 Taxes and Levies Act) | Retain full rows (receipt_no / amount / date) but hash PII (payer name / email) |
| **Consent records** | `local_ulms_consent` | **3 years after last recorded activity (login / change)** | NDPR Art. 26 (accountability) | Delete rows |
| **Audit logs** | `mdl_logstore_standard_log`, `local_ulms_audit` | **Minimum 12 months live + 3 years cold storage** | FERPA §99.32 (if US students covered); NDPR Art. 26 | Move cold storage to tape / object lock S3; shred on expiry |
| **Session logs** | `mdl_sessions`, Redis `sess_*` keys, FPM session files | **90 days from last access** | Session retention policy (NIST SP 800-63B) | TTL/expire automatically; monthly cron sweep orphan rows |
| **Transactional emails sent** | `mdl_message`, `mdl_message_read`, Mailgun/Resend log mirror | **6 months from send date** | Evidence of delivery for password resets / exam reminders | Anonymise body after 6 months; delete after 12 |
| **System/php/nginx access logs** | `/var/log/nginx/*.log`, `journalctl php-fpm`, `/var/log/mysql/*.log` | **30 days rolling on disk + 90 days cold** | NIST SP 800-92 | Rotate via logrotate + compress; delete cold backup on schedule |
| **Backups (operational rolling)** | `/var/backups/ulms/*.sql.gz` | **Daily 30 d / Weekly 12 wk / Monthly (end-of-semester) 7 yr** | NUC + FERPA educational records exception | Encrypted at rest; 7-year end-of-semester snapshots marked WORM + legal hold tag |

---

## 4. Data Breach Response (GDPR Art. 33 / NDPR Schedule 6 / NDPC Nigeria)

**Golden rule**: 72-hour clock starts AT THE MOMENT THE BREACH IS DETECTED, not at confirmation.
DPO is the ONLY sign-off authority for NDPC notification content.

7 steps:

### Step 1 — Detect and Contain (0–2 hours)
- Detection source: SOC alert / user report / operator notice / automated tool.
- Open P0 incident per FILE 2 (02-INCIDENT-RESPONSE.md) Sec. 2 + 3 Scenario D or C.
- Contain the leak IMMEDIATELY:
  - Chmod 0 + remove from web.
  - Revoke compromised credentials (FILE 2 §2 Step 4).
  - Snapshot + write-protect evidence (FILE 2 §2 Steps 5 + 9).
  - Notify DPO + Service Owner within 30 minutes.

### Step 2 — Assess Risk per Data Category (2–8 hours)
Complete the breach risk matrix inside the DSAR / breach register:

| Category | Data affected? | Users affected (N) | Sensitivity | Likelihood of misuse | Overall risk |
|----------|---------------|--------------------|-------------|----------------------|--------------|
| Names / emails | Y/N | `<count>` | Low | `<low/med/high>` | L/M/H |
| Matric / staff IDs | Y/N | `<count>` | Med | — | L/M/H |
| Phone numbers / home address | Y/N | `<count>` | Med-High | — | L/M/H |
| Date of birth | Y/N | `<count>` | High | — | L/M/H |
| Government ID (NIN / passport) | Y/N | `<count>` | Very High | — | L/M/H |
| Password hashes | Y/N | `<count>` | Very High | — | L/M/H |
| Grades / exam submissions | Y/N | `<count>` | High (FERPA) | — | L/M/H |
| Financial / bank / card | Y/N | `<count>` | Very High | — | L/M/H |
| Health / disability | Y/N | `<count>` | Special (GDPR Art. 9 / NDPR 2019 Part II 3.1) | — | SEVERE |

### Step 3 — Notify NDPC Nigeria (within 72 hours if N >= 500)
If `(N >= 500 Nigerian data subjects)` OR any Special Category data exposed → **MANDATORY notification** to:
- Email: `enforcement@ndpc.gov.ng`
- Online form: `https://compliance.ndpc.gov.ng/data-breach-notification`
- Phone: `+234-9-461-5868` (Liaison Office, confirm receipt)

Notification content must include at minimum:
1. Name and contact details of the DPO.
2. Nature and categories of personal data breached, with N per category.
3. Approximate number of data subjects affected (state if exact unknown).
4. Likely consequences of the breach.
5. Measures taken or proposed to address the breach.
6. Timeline of detection → containment.

If N < 500 and no special category, DPO may decide voluntary notification (document the decision).

### Step 4 — Notify Affected Users (without unreasonable delay)
DPO approves the exact wording. Template elements:
- Subject line: "Important notice regarding your personal data at Bells University"
- Date/time of incident window.
- What data was / was not exposed (be specific — do NOT say "your data" without naming categories).
- Concrete steps the University has taken.
- Concrete steps the user SHOULD take (password reset, monitor bank statements, place fraud alert with NCC/NIBSS).
- DPO contact details for questions.
- Right to lodge complaint with NDPC.

**Channels**: In-app banner + email to verified address. SMS follow-up for high-sensitivity cases.

### Step 5 — Document Root Cause + Permanent Fix (T + 5 to T + 10 days)
RCA document (FILE 2 §2 Step 7) signed by IC, DPO, Service Owner.
Fix code / config deployed via full Atomic Deploy procedure (FILE 1 §2), never hot-patch.

### Step 6 — Supervisory Authority Follow-up (T + 30 days ongoing)
DPO provides updates to NDPC as requested.
If NDPC opens a formal investigation: Legal Counsel + DPO own all communication; engineering only provides technical artefacts on DPO written request.

### Step 7 — Post-breach Audit + Test (T + 90 days)
External auditor (DPO contracted) re-runs penetration test + data-exposure checklist:
- Confirm vulnerability closed.
- Confirm residual exposure is 0 (re-test the exact vector).
- Confirm monitoring alerts exist for the vector.
- Report filed with NDPC if original notification promised a follow-up.

---

## 5. Cookie Audit (Full list — matches `/cookie-policy.php` table)

All cookies set by ULMS / Bells subdomains are enumerated below.
Keep this list SYNCED with the public cookie policy page; if a cookie is added or changed,
update both the page and this runbook (same PR).

| Cookie name | Domain | Purpose | Retention (max-age / expires) | Category | Party | Opt-out / withdrawal |
|-------------|--------|---------|-------------------------------|----------|-------|----------------------|
| `MoodleSession` | `learn.bellsuniversity.edu.ng` | Session identifier; maintains authenticated state across page loads | Session (deleted at browser close or logout) | Strictly Necessary | 1st | N/A (necessary) — logout deletes it |
| `MOODLEID_<hash>` | `learn.bellsuniversity.edu.ng` | "Remember username" checkbox — stores only the login name, NOT the password, for pre-fill on next visit | 60 days | Strictly Necessary | 1st | Clear browser cookies or use "Forget this device" on login screen |
| `ulms_consent` | `learn.bellsuniversity.edu.ng` | Stores user's accepted/denied categories from the cookie banner v1 so it does not re-show on every page load | 180 days | Strictly Necessary (banner state persistence) | 1st | Reset via `/user/policy.php` consent manager |
| `MoodleSessionTest` | `learn.bellsuniversity.edu.ng` | Probe to confirm the UA accepts cookies before showing the login form; value is static string | Session | Strictly Necessary | 1st | N/A (necessary) |
| `_ga_<container-id>` | `.bellsuniversity.edu.ng` | Google Analytics 4: per-session state | 2 years | Analytics | 3rd (Google LLC) | Consent manager (Analytics OFF) + GA4 delete request via `myactivity.google.com` |
| `_ga` | `.bellsuniversity.edu.ng` | Google Analytics 4: distinguish unique users | 2 years | Analytics | 3rd (Google LLC) | Consent manager (Analytics OFF) |
| `_gid` | `.bellsuniversity.edu.ng` | Google Analytics 4: distinguish users within a 24-hour window | 24 hours | Analytics | 3rd (Google LLC) | Consent manager (Analytics OFF) |
| `_gat_gtag_<id>` | `.bellsuniversity.edu.ng` | Google Analytics 4: throttle request rate to Google (1 minute cap) | 1 minute | Analytics | 3rd (Google LLC) | Consent manager (Analytics OFF) |
| `_fbp` | `.bellsuniversity.edu.ng` | Meta (Facebook) Pixel: uniquely identify browser for marketing / programme retargeting | 90 days | Marketing | 3rd (Meta Platforms Inc.) | Consent manager (Marketing OFF) + Meta opt-out via `facebook.com/ads/preferences` |
| `fr` | `.facebook.com` (3rd party context) | Meta advertising cookie set via Pixel; rate-limit + frequency capping for retargeting ads | 3 months | Marketing | 3rd (Meta Platforms Inc.) | Consent manager (Marketing OFF) + Meta opt-out |
| `tr` | `.facebook.com` | Meta conversion attribution — records that a user reached a programme landing page | 7 days | Marketing | 3rd (Meta Platforms Inc.) | Consent manager (Marketing OFF) |
| `_tt_enable_cookie` | `.bellsuniversity.edu.ng` | TikTok Pixel: cookie-enablement probe for conversion tracking (only if TikTok Ads configured) | 1 year | Marketing | 3rd (TikTok) | Consent manager (Marketing OFF) |
| `cf_clearance` | `learn.bellsuniversity.edu.ng` | Cloudflare WAF challenge-passed marker — allows subsequent requests without re-solving CAPTCHA | Session (max 1 day if WAF strict) | Strictly Necessary (WAF security) | 1st (via Cloudflare proxy) | N/A (security requirement) |
| `__cf_bm` | `learn.bellsuniversity.edu.ng` | Cloudflare Bot Management — distinguishes human vs bot traffic to stop credential stuffing | 30 minutes | Strictly Necessary (security) | 1st (via Cloudflare proxy) | N/A (security requirement) |
| `ulms_csrf_<nonce>` | `learn.bellsuniversity.edu.ng` | Per-form CSRF token — prevents Cross-Site Request Forgery on all POSTed forms | Session (per form) | Strictly Necessary (security) | 1st | N/A (necessary) |
| `ulms_theme_pref` | `learn.bellsuniversity.edu.ng` | User-selected theme (light/dark/high-contrast) if preference set in user profile | 1 year | Strictly Necessary (UX preference) | 1st | Change via User menu → Preferences → Theme |

### Cookie Audit Frequency
- Quarter: DPO (or delegate) re-runs:
  ```bash
  npx cookiecrawler https://learn.bellsuniversity.edu.ng --domain=bellsuniversity.edu.ng --out=audit-$(date +%YQ%q).json
  ```
  Diff output against the table above; file a PR if categories / names changed.
- Year: Independent DPO review + full re-certification of public cookie policy page.
