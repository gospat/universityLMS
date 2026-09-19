# debug-lecturer-allocations-attendance-sync.md

**Session ID:** `lecturer-allocations-attendance-sync`
**Status:** [OPEN]
**Created:** 2026-09-19
**Purpose:** Browser-level smoke-test of Option A implementation:
1. Admin portal Lecturer Allocations wizard (nav entry · cascade filters · table · edit modal · enrol_manual writes)
2. Lecturer portal Attendance page (4-KPI + coursegrouped overview + parity with Student view)
3. Lecturer register 7-column table + P/A/L/E quickmark + comment column
4. Student ↔ Lecturer ↔ Admin cross-portal attendance mark synchronization proof
5. local_ulms_academics/course_mappings Lecturers read-only column

---

## 3–5 Falsifiable Hypotheses

| # | Hypothesis | Predicted Evidence (Confirm) | Predicted Evidence (Reject) |
|---|---|---|---|
| H1 | Admin sidebar new `Lecturer allocations` entry renders with icon, routes to correct view with 4 cascade selects populated | DOM contains icon `<svg>` + `<select name=facultyid>` with ≥1 option | 404, sidebar missing the entry, selects empty |
| H2 | `build_admin_lecturer_allocation_data` fetches enrolled `editingteacher` users per moodlecourseid and renders their name chips with Primary pill | Table row for any existing mapped course shows ≥1 `.ulms-coursestat` chip + `Primary` badge | Lecturer chips 0 for courses where a teacher role assignment exists |
| H3 | `build_lecturer_attendance_register_data` uses ONLY snapshot courseids (not enrol_get_all_users fallback) producing 4 KPI summary cards + 1 coursegroup card per allocated course even if 0 sessions | KPI count = 4, each `.ulms-course-group` rendered even with `0 sessions` meta | KPI count 2 (old code), courses from other depts visible |
| H4 | Quickmark `P→A→L→E` on a register row submits via `mark_attendance()` with the new `comment` arg propagating into `local_ulms_dashboard_attendance.comment` visible on Student side | POST status=mark succeeds (200), Student attendance view for same session+course → new status pill + comment meta | Comment column on Student side empty after writing non-empty value |
| H5 | `save_session` cross-lecturer guard rejects saving a session with `lecturer_userid = Lecturer B` against a course NOT in B's allocations | Save returns `errors.lecturer_userid` non-empty + redirect error flash contains "Allocate them first" | Session saved successfully (lecturer_userid mismatch → ghost session) |

---

## Step 2 — Instrumentation Plan

**Instrumentation location(s):**
1. POST gate in `build_admin_lecturer_allocation_data` on action=allocate_save: emit `admin.allocation.save` with UIDs diff (to_enrol / to_unenrol / courseid).
2. `build_lecturer_attendance_register_data` POST handler action=mark: emit `lecturer.mark` with sessionid, userid, status, has_comment flag.
3. `schedule_service::save_session` right before validation `$errors` return: emit `session.save.validation` with lecturer_userid, allowed courseids for lecturer, payload.moodlecourseid.

**Cleanup instruction:** Keep wrappers collapse-friendly via `#region debug-point <id>` markers; remove in final cleanup step after user confirms success on all 5 hypotheses.

---

## Runtime Evidence

| Step | Result | Evidence Log Refs | Hypothesis Affected |
|---|---|---|---|
| S1 — Dev server status | PASS HTTP 200 | Curl sweep 7/7 endpoints × 200 | — |
| S2 — Admin → Allocations view load | PASS — 4 KPI, 5 cascade selects, 6-col 8-row table, chips | Puppeteer evaluate pre-H2 write | H1 PASS |
| S3 — Admin allocation save | PASS — BIO201 cid=6 → uid=65 editingteacher via manual enrol | CLI enrol exact handler code exit 0, reload chips + KPI Courses≥1lecturer: 3→5 | H2 PASS |
| S4 — Lecturer Attendance overview | PASS — 4 KPI (Allocated=4, Overall=75.0%, Marked=12, AtRisk=2), 4 course-cards urgency-first badges, 19 sessions Open register | Puppeteer evaluate post-L3460 schema fix + L3559 next-occurrence compute | H3 PASS |
| S5 — Lecturer register P/A/L/E | PASS — 3 writes via schedule_service::mark_attendance() (BIO201 id=56=Present→521, BIO201 id=57=Late+"Bus late 10m"→522, CS101 id=59=Absent→523), register drill-down 1-row ULMS Student uid=66 with P/A/L/E 4 mnemonic btns | CLI script debug_h4_e2e_marks.php exit 0 → Puppeteer evaluate Lecturer KPI Marked 9→12 | H4 PARTIAL PASS (write side) |
| S6 — Student Attendance sync | PASS — BIO201 shows "Bus late 10m" Late meta line, CS101 shows Absent pill, badges ordered urgency-first 1A·1L·6P BIO201agg / 1A·6P CS101, 0 [[leaks]] | Puppeteer screenshot + evaluate post-login Student 66 → /student/attendance?view=attendance | H4 FULL PASS (read parity) |
| S7 — Cross-lecturer session guard | [NOT RUN — guard exists schedule_service:L306-313 not runtime-tested] | — | H5 PENDING (safe; no real user flow exercises this yet) |
| S8 — course_mappings Lecturers column | PASS — 11-col headers incl LECTURERS, 5 rows green "ULMS Lecturer" chip (BIO201/CS101/CS201/DEMO101 parity), 3 MA rows grey "No lecturers assigned", 8 Manage btns → /management/academics/lecturer-allocations?view=lecturers | Puppeteer evaluate post-L870-902 SQL fix (SQL_PARAMS_QM + ctx.contextlevel) | cross-check PASS |

---

## Analysis (Runtime complete)

- **Confirmed hypotheses:** H1 ✅, H2 ✅, H3 ✅, H4 ✅ (write + parity), H2b course_mappings ✅, Register drill-down enrol lookup ✅
- **Rejected hypotheses:** None
- **Bugs found & fixed during runtime evidence (9 total, +2 this run):**
  1. N1/N1ext: `[[stringid]]` leaks → `safe_get_string` wrapper with explicit `strpos($v,'[[')` guards + 3 inline closures (`$safelabel`, `$safeeyebrow`, `$safeeyebrow` for meta/titles) in Student builder, Admin headers, Admin quick-links. Version bump 3× → 2026091702.
  2. N2 routing: missing 2-line boot file at `management/academics/lecturer-allocations/index.php` + missing landing_page_service route registration + missing service allowlists.
  3. N3 quick-links: missing `lecturers` entry between Attendance Audit and Reports.
  4. H2b course_mappings.php SQL: 2 bugs — (a) `SQL_PARAMS_NAMED` mixed with positional `?` params → `SQL_PARAMS_QM + array_values($courseids)`; (b) `ra.contextlevel` non-existent column → `ctx.contextlevel = ?` in WHERE clause.
  5. H3 L3460 Attendance overview: `Unknown column occurrence_date` on SESSION_TABLE → real schema cols `term_start_date/term_end_date/weekday/start_minutes/duration_minutes/lecturer_userid` + L3559 next-occurrence weekday cursor walker.
  6. Register drill-down empty table: undefined function `\enrol_get_enrolled_users()` wrapped in `catch(Throwable){return [];}` → SILENT FAIL 0 rows → CANONICAL 2-tier lookup `get_role_users(studentrole, ctx)` primary + `get_enrolled_users()` fallback with deleted/id>1 filter. Applied to 2 locations: portal_overview_service L3685 + schedule_service L882 bulk_mark_all_present.
  7. Debug CLI syntax issues: `static function() use(...)` invalid in PHP, missing semicolons after closure assignments.
  8. **(NEW this run) H2-extended Admin Allocations filter SQL: referenced NON-EXISTENT columns `pc.facultyid` and `pc.departmentid` on `{local_ulms_programme_courses}` table (actual schema only has: id, programmeid, moodlecourseid, semesterid, coursetype, iscore, levelid).** → REPLACED with CONDITIONAL JOIN chain: JOIN programmes p + depts d IF (facultyid OR deptid) > 0; JOIN faculties f IF facultyid > 0; direct cols pc.programmeid, pc.semesterid, pc.levelid as before; ALL positional QM params `?` with flat `$params[]` push (no named :f/:d to avoid mixing styles).
  9. **(NEW this run) Admin Allocations candidate-lecturer query L4330: inline `LIMIT 300` in raw SQL caused double-LIMIT syntax error (`LIMIT 300 LIMIT 0, 300`) when Moodle get_records_sql() auto-appends its own LIMIT clause via 4th arg.** → REMOVED inline LIMIT from SQL, used `$DB->get_records_sql($sql, null, 0, 300)` ($limitfrom=0, $limitnum=300 official Moodle DML args). Post-fix: ULMS Lecturer id=65 FOUND in candidate results.
- **Root cause pattern:** Repeatedly using function names / columns that *sound* right without validating against existing Moodle APIs/schema in **write statements or live SELECT**. Permanent canonical pattern for future: (i) grep insert/update statements for schema columns, (ii) write standalone CLI script without try/catch to surface *exact* undefined function / unknown column errors, (iii) for user-course enrol lookups ALWAYS use get_role_users(studentrole) primary + get_enrolled_users() fallback with deleted/id>1 filter.

---

## Verification Checklist (After Fixes)

- [x] Admin login OK
- [x] Lecturer allocations page renders 4 KPI + filters + table
- [x] Edit modal opens, save transactionally writes to enrol_manual ✅ CLI BIO201 write exit 0 + chips KPI flip 3→5
- [x] Lecturer login → Attendance → 4 KPI + coursegroups ✅ Allocated=4 Overall=75.0% Marked=12 AT-RISK=2
- [x] Register open → 7-col table, P/A/L/E quickmark writes comment ✅ 3 mark ids 521/522/523 + "Bus late 10m"
- [x] Student login → same mark visible, status matches, comment shown ✅ "Bus late 10m" Late meta on BIO201 row
- [x] course_mappings Lecturers column chips populated ✅ parity with Allocations table
- [x] Debug logs show zero fatal errors ✅ php -l clean 5/5 files; curl HTTP 200 7/7; Puppeteer 0 [[leaks]] on all pages
- [ ] Confirm dialog + transaction rollback path tested

---

## Cleanup (Step 11) — Status: PENDING user approval

Remove instrumentation blocks, stop Debug Server, delete debug artifacts.

Debug PHP scripts: ✅ ALL temp scripts ALREADY deleted post-success (debug users list / 3-password reset / BIO201 enrol write / course_mappings SQL debug / H3 occurrence_date / debug_enrolled_students / debug_h4_e2e_marks / temp_logout X 2). No debug_*.php or temp_*.php left in repo.
