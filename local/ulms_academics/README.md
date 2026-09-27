# local_ulms_academics

ULMS academic-structure management plugin.  Provides CRUD + CSV/XLSX
bulk-import of **Colleges → Departments → Programmes → Courses**, plus
Programme ↔ Moodle Course mappings, human-readable `collegeCode`-based
hierarchical linking (instead of numeric IDs), and the academic structure
reporting / route views used by the Admin Management portal.

---

## 1. Responsibilities

- Owns the lifecycle of academic structure records: Colleges (Faculties), Departments, Programmes, (Archival-only: Academic Sessions), and their links.
- Provides Management-side Academics routes + section controllers used inside the `/management/…` shell.
- Provides **CSV bulk import** (4 entities: Colleges, Departments, Programmes, Courses) + **Programme ↔ Moodle Course mapping CSV bulk import**.
- Provides a **multi-sheet XLSX** bulk-upload workbook for Excel-first data entry teams (instructions sheet + 5 perfectly-aligned template sheets; export each sheet to CSV before upload).
- Reusable controller helpers live in `locallib.php`.  Page-only rendering helpers are co-located with their controller until a second real reuse case appears.
- Exposes CRUD + import + reporting helpers via `academic_structure_service.php`.
- Centralised programme-course lookups with the mandatory Study-Level leak guard: `academic_repository::get_programme_moodlecourseids` (SSOT used anywhere that resolves which courses a student should see).

---

## 2. Access & Routing

- Navigation entries are added only for users holding the `local/ulms_academics:viewstructure` capability (Admin Managers + Super Admins by default — see [db/access.php](./db/access.php)).
- All management URLs are resolved through the **shared ULMS landing page route service** (`local_ulms_auth/classes/local/service/landing_page_service.php`).  Never hardcode raw paths.
- Main entry points (reachable inside `/management/academics/…`):

| Page | Path fragment | Controller file |
|---|---|---|
| Academics landing + bulk-import workspace | `academics` | [import.php](./import.php) |
| Programme ↔ Moodle Course mappings | `academics/mappings` | [course_mappings.php](./course_mappings.php) |
| Structure CRUD | `academics/manage` | [manage.php](./manage.php) |
| Structure reporting / summary | `academics/report` | [report.php](./report.php) |

---

## 3. Data Model & Linking

### 3.1 Human-readable Codes (not numeric IDs) — REQUIRED FOR BULK IMPORTS

To keep CSVs portable between staging and production (and to avoid leaks of
auto-increment IDs in audit logs), **all parent/child linking uses
human-readable, stable `*Code` columns, never numeric `id` columns**.

| Entity | Primary code column | Parent link(s) via code |
|---|---|---|
| College | `collegeCode` | (top-level, no parent) |
| Department | `departmentCode` | `collegeCode` (its parent College) |
| Programme | `programmeCode` | `collegeCode` + `departmentCode` |
| Course | `courseCode` | `collegeCode` + `departmentCode` (scope), plus `moodleCourseId` (FK to the real moodle course ID when mapping is created) |
| Programme ↔ Course mapping | `programmeCode + courseCode` composite | `collegeCode + departmentCode` for scope validation |

When you import a Department row, the parser validates the `collegeCode` in
that row actually references a College that already exists (or will exist in
the same batch — ordering rules in §4).  Importing in the wrong order will
fail cleanly with a line-numbered validation list, no rows are half-inserted.

### 3.2 Parent-child Relationship

Strict tree:

```
College (collegeCode: SCI)
└── Department (collegeCode: SCI, departmentCode: CS)
    └── Programme (collegeCode: SCI, departmentCode: CS, programmeCode: BSCCS)
        └── Course (collegeCode: SCI, departmentCode: CS, courseCode: CSC101)
                ↓ mapped via course_mappings
                Moodle Course (real moodlecourseid FK: 6)
```

Study-level filtering is enforced at lookup time by the canonical SSOT
`academic_repository::get_programme_moodlecourseids($programmeid, $studylevel)`.
**All** Student catalogue, enrolment whitelist, and dashboard KPI calls MUST
route through this method — this is the anti-cross-level data leak guard.

---

## 4. CSV Bulk Import — Order & Templates

Hierarchical order is non-negotiable.  **Parents before children.**

### 4.1 Import Sequence

```
1. Colleges CSV        → creates/updates colleges               (import.php, entity=colleges)
2. Departments CSV     → each row links to a college via collegeCode
3. Programmes CSV      → each row links to (collegeCode, departmentCode)
4. Courses CSV         → each row links to (collegeCode, departmentCode)
5. Course mappings CSV → maps existing Programme codes to existing Course codes
                          (installs the programme ↔ real Moodle course FK that
                           enrolment cascades use)
```

### 4.2 Downloading Templates

Each download returns a **ready-to-fill UTF-8 CSV with header row already in
the exact order the importer validates:**

| What | URL/Button | Output filename |
|---|---|---|
| Colleges | Management → Academics → Bulk upload → `Colleges → Download CSV template` | `ulms-colleges-template.csv` |
| Departments | Same page → Departments → Download | `ulms-departments-template.csv` |
| Programmes | Same page → Programmes → Download | `ulms-programmes-template.csv` |
| Courses | Same page → Courses → Download | `ulms-courses-template.csv` |
| Programme ↔ Course mappings | Academics → Course Mappings → Download mapping CSV | `ulms-programme-course-mapping-template.csv` |
| (Excel users) Multi-sheet XLSX workbook | Academics → Bulk upload → "Download multi-sheet XLSX workbook" | `ulms-academic-structure-workbook.xlsx` |

### 4.3 Multi-sheet XLSX Workbook (Excel-first teams)

The XLSX contains **exactly 6 sheets, perfectly aligned column-for-column with the 5 individual CSV templates plus one instructions sheet:**

| Sheet # | Sheet name | Usage |
|---|---|---|
| 1 | `Instructions` | Instructions sheet: step-by-step fill-order + rules (do NOT delete). Read this sheet first before populating any data sheets. |
| 2 | `Colleges` | Same schema as `ulms-colleges-template.csv` |
| 3 | `Departments` | Same schema as `ulms-departments-template.csv`, parent via `collegeCode` |
| 4 | `Programmes` | Same schema as `ulms-programmes-template.csv`, parents via `collegeCode` + `departmentCode` |
| 5 | `Courses` | Same schema as `ulms-courses-template.csv` |
| 6 | `CourseMappings` | Same schema as `ulms-programme-course-mapping-template.csv` |

Before you upload, **export each data sheet (2–6) to a separate CSV** using
Excel's `File → Save As → CSV UTF-8 (Comma delimited) (*.csv)`.  Upload each
CSV in the §4.1 order through the same web importers as direct CSV users.

### 4.4 Import UI Features

- **Inline validation list** — before any DB write, the importer runs a dry pass and returns a per-line list of errors (missing required field, parent code not found, duplicate code, length violation, etc.).  Nothing is written until all rows validate.
- **High-contrast modal + auto-generated codes** (College / Department / Programme creation forms): each create dialog comes with an auto-suggested `*Code` field, show/copy/regenerate trio so admins never have to invent codes manually.
- **Full CRUD lifecycle** including permanent hard-delete.  Delete is modal-gated with a typed confirmation because deletes cascade.  The parent-child guard prevents deleting a College that still has Departments.

---

## 5. Programme ↔ Moodle Course Mappings

The mapping table lives in the Moodle DB as `local_ulms_programme_courses`
with a real FK to Moodle's `course.id` (enforced at install via
[db/install.xml](./db/install.xml) and re-validated at PRC time by
`sec:moodlecourse-fk-exists`).  This means:

- Deleting a course in Moodle **blocks** until you remove the mapping first.
- Importing mappings always runs a "does this course id actually exist" check.
- Enrolment cascade at provisioning time only sees students enrolled in mapped courses.
- PRC verifies both: (a) the FK exists on the table if you query `INFORMATION_SCHEMA`, and (b) it falls back to parsing `db/install.xml` when the table was just created (first deploy) — so both first-deploy and long-running instances always pass.

Full mapping import UI is at [course_mappings.php](./course_mappings.php).

---

## 6. Controller Conventions

- Management controllers generate URLs through the shared landing page service instead of hardcoded paths.
- Request state that is reused by more than one academics controller is hoisted into `locallib.php`.
- Controller-specific presenters (e.g. course-mappings sortable header renderer) stay co-located with their page controller.
- All write endpoints check `require_login` + `confirm_sesskey` / data_submitted sesskey.  The PRC's `audit:guards` assertion will flag missing guards.

See the top-level docs for the end-to-end deployment runbook:
- [README.md §4 Quick start](../../README.md#4-quick-start-developer-workstation)
- [ULMS_DEPLOYMENT_SYNC.md §4.9 Bulk import](./../../ULMS_DEPLOYMENT_SYNC.md#49-bulk-import-real-academic-structure-via-csv-recommended)
