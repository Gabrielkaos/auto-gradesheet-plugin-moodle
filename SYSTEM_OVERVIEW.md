# SYSTEM OVERVIEW — Auto Gradesheet Plugin for Moodle (`local_gradesheet`)

> **Purpose of this document:** Provide a self-contained technical description of the system for comparison against the undergraduate thesis *"Development of an Automated Grade Sheet Generation Plugin for the Moodle Learning Management System for Faculty Grade Reporting"* (Eastern Samar State University, ESSU).

---

## 1. System at a Glance

| Attribute | Value |
|-----------|-------|
| System type | Moodle **local plugin** (`local_gradesheet`) |
| Host platform | Moodle 4.5+ (`requires = 2024100700`) |
| Current release | v1.5 (`version = 2026081900`, `MATURITY_STABLE`) |
| Language | PHP (Moodle coding standard), HTML/CSS, JS (inline) |
| Primary output | ESSU "Report of Grades" — on-screen preview, PDF, Excel |
| Target institution | Eastern Samar State University (ESSU) |
| External libraries | TCPDF (PDF, Moodle-bundled), PhpSpreadsheet (Excel, Moodle-bundled, loaded via `vendor/autoload.php`) |
| License | GPL-3.0 |

**Core problem solved:** Faculty at ESSU must submit a standardized, paper-formatted "Report of Grades" (form ESSU-ACAD-712.b, Version 5). Manually computing weighted grades, transmuting to the Philippine 1.0–5.0 scale, and laying out the form is time-consuming and error-prone. This plugin automates grade extraction from Moodle's gradebook, computation, transmutation, and document generation — directly inside Moodle.

---

## 2. Stakeholders & Roles

| Role | Capability | View |
|------|------------|------|
| Student | `local/gradesheet:view` | Personal grade card only (own scores, transmuted grade, remarks) |
| Teacher / Editing Teacher | `local/gradesheet:view` + `local/gradesheet:manage` | All students, settings, preview, exports |
| Manager / Site Admin | both | Full access; admins additionally see **all** courses |
| Faculty (subset of teacher) | `manage` | Can set per-student status overrides (Incomplete, Dropped, WP, In Progress) |

Role checks:
- `classes/helper.php:get_non_teaching_students()` excludes site admins and users holding `teacher`/`editingteacher` roles from the student roster.
- Capability enforcement on every entry point (`index.php`, `preview.php`, `export.php`, `export_excel.php`, `course_settings.php`).

---

## 3. Architecture Overview

The plugin follows a **thin-controller / thick-service** pattern:

```
                Moodle Core (course_created event, course edit form hooks)
                                  │
        ┌─────────────────────────┴─────────────────────────┐
        │                 classes/ (business logic)          │
        │  helper.php            gradesheet_service.php       │
        │  (formula single source)  (batch coordinator)      │
        │  observer.php           hooks.php                   │
        └─────────────────────────┬─────────────────────────┘
                                  │  reads/writes
        ┌─────────────────────────┴─────────────────────────┐
        │   Page controllers (presentation only)              │
        │  index.php  preview.php  export.php  export_excel.php│
        │  course_settings.php   lib.php (navigation)         │
        └───────────────────────────────────────────────────┘
                                  │
               Moodle DB tables + gradebook (grade_items, grade_grades)
```

**Design rules observed in code:**
- All grading math lives in `helper.php` (single source of truth) and `gradesheet_service.php`.
- Page files only format/print data; they never recompute formulas.
- `gradesheet_service::compute_all_grades()` is the shared coordinator used by `preview.php`, `export.php`, and `export_excel.php` — guaranteeing the on-screen, PDF, and Excel outputs are identical.

---

## 4. Database Schema (5 custom tables)

Defined in `db/install.xml`; evolution tracked in `db/upgrade.php`.

### 4.1 `local_gradesheet_config` (per-course settings)
- `id`, `courseid` (UNIQUE)
- Grading weights: (Computation strictly follows category weights within periods)
- Report header: `semester`, `schoolyear`, `coursenumber`, `descriptive`, `courseandyear`, `schedule`, `units`
- Signatories: `instructor`, `department_head`, `registrar`, `college_dean`
- `timecreated`, `timemodified`

### 4.2 `local_gradesheet_categories` (grading components)
- `id`, `courseid`, `name`, `weight` (%), `sortorder`
- Default seed on course creation: **Quizzes 30%**, **Activities 30%**, **Exams 40%**
- Weights **must sum to exactly 100%** or printing/exporting is blocked.

### 4.3 `local_gradesheet_itemmap` (grade-item → category/period mapping)
- `id`, `courseid`, `gradeitemid` (UNIQUE pair w/ courseid), `period` (`midterm`/`finals`, default `finals`), `categoryid` (FK to categories, default 0)
- Lets faculty assign each Moodle grade item to a category **and** a period independently.

### 4.4 `local_gradesheet_transmute` (custom grading scale)
- `id`, `courseid`, `minscore`, `maxscore`, `equivalent` (string, e.g. "1.0" or "A"), `descriptor`, `sortorder`, `ispassing` (bool, default 1)
- Optional per-course override of the default ESSU scale. If any row exists for a course, the **custom scale is active** for that course.

### 4.5 `local_gradesheet_status` (faculty status overrides)
- `id`, `courseid`, `userid` (UNIQUE pair), `status` (`''`=active, `inc`, `dropped`, `wp`, `ip`), `timemodified`
- When set, the student's row shows dashes and the status label; excluded from pass/fail statistics.

---

## 5. Core Grading Logic (`helper.php`)

### 5.1 Grade extraction & normalization (`compute_student_grades`, helper.php:354)
1. Fetch all grade items for the course except the course total (`itemtype != 'course'`, `itemname IS NOT NULL`).
2. For each item, read the student's `finalgrade` from `grade_grades`; normalize to 0–100 (`val / grademax * 100` when `grademax ≠ 100`).
3. Look up the item's `period` (midterm/finals) and `categoryid` from `itemmap`. (Unmapped items are ignored).
4. Accumulate per-category totals/counts and per-period totals/counts.

### 5.2 Weighted computation
- **Period Calculation**:
  - For each period (Midterm, Finals), it calculates the weighted average of its mapped categories: `periodAvg = Σ(catAvg × catWeight) / Σ(catWeight)`.
  - If all mapped categories for a period have zero weight, it falls back to a simple unweighted average of the category averages: `periodAvg = Σ(catAvg) / count`.
- **Final Average Calculation**:
  - The final average is a strict 50/50 split between the two periods: `finalAverage = (midterm + finals) / 2`.
  - If only one period has graded items, that period's average becomes the final average.

### 5.3 Transmutation to equivalent rating (`transmute_equiv`, helper.php:156)
- Returns `'-'` for null/empty/non-numeric input.
- **If a custom scale exists** for the course: straight bracket match on `[minscore, maxscore]` → returns the `equivalent` string. No interpolation (custom equivalents may be non-numeric, e.g. letter grades). Unmatched score → `'-'`.
- **Default ESSU scale** (piecewise linear interpolation within bands):

  | Raw % | Equivalent range |
  |-------|------------------|
  | 100 | 1.0 |
  | 90–99 | 1.1–1.5 |
  | 85–89 | 1.6–2.0 |
  | 80–84 | 2.1–2.5 |
  | 75–79 | 2.6–3.0 |
  | 70–74 | 3.1–3.5 |
  | 55–69 | 3.6–5.0 |
  | < 55 | 5.0 |

  Interpolation: `equiv = eqMax − ((max − grade)/(max − min)) × (eqMax − eqMin)`, rounded to 1 decimal.

### 5.4 Pass/Fail determination (`is_passing`, helper.php:228)
- Custom scale active → driven by matched bracket's `ispassing` flag; unmatched → **failing** (safe default).
- Default → `grade >= 75` passes.
- Final remarks: `PASSED` / `FAILED`.

### 5.5 Status overrides & validation
- `VALID_STATUSES = ['', 'inc', 'dropped', 'wp', 'ip']` (helper.php:9).
- `set_student_status()` inserts/updates/deletes `local_gradesheet_status`.
- `validate_weight_sum()` enforces sum = 100% and ≥ 1 category (helper.php:474).

### 5.6 Defaults & caching
- `ensure_course_defaults()` auto-creates config + 3 default categories; triggered on `course_created`, on navigation render, and on first `index.php` load.
- Custom transmute rows cached per request (`$transmutecache`) so roster computation hits the table once.

---

## 6. Module / Component Responsibilities

| File | Responsibility |
|------|----------------|
| `classes/helper.php` | Formula, transmutation, config load, student filter, status, validation, legend |
| `classes/gradesheet_service.php` | `compute_all_grades()` — iterate students → rows + pass/fail stats |
| `classes/observer.php` | `course_created` event → seed defaults |
| `classes/hooks.php` | Inject gradesheet fields into Moodle **course edit form** (Moodle 4.5+ hooks); save on submission |
| `db/access.php` | Capability definitions (`manage`, `view`) |
| `db/events.php` | Event observer registration |
| `db/hooks.php` | Hook callback registration |
| `db/install.xml` | 5-table XMLDB schema |
| `db/install.php` | Install stub |
| `db/upgrade.php` | Incremental schema migrations (versions 2026031201 → 2026081900) |
| `lib.php` | Course & global navigation nodes ("Grade Sheet" link) |
| `index.php` | Main UI: course selector, student grade card, faculty roster table w/ search/filter/status, class summary |
| `preview.php` | Print-ready HTML "Report of Grades" (paginated A4, ESSU branding, signature blocks) |
| `export.php` | PDF export via TCPDF (`gradesheet_pdf` subclass) — mirrors preview pagination |
| `export_excel.php` | Excel export via PhpSpreadsheet — mirrors layout |
| `course_settings.php` | Full config UI: course details/signatories, categories, item mapping, custom transmutation scale |
| `version.php` | Plugin metadata |
| `lang/en/local_gradesheet.php` | Language strings |
| `pix/` | `essu-header.png`, `bagong-pilipinas.png` (report logos) |
| `cli/` | Developer/test scripts: `create_test_course.php`, `genrate_test_data.php`, `test_form_hook.php` |

---

## 7. Key User Flows

### 7.1 Faculty: Generate & export a Report of Grades
1. Open course → "Grade Sheet" (navigation node from `lib.php`).
2. `index.php` lists students with category/midterm/finals/average/remarks + status controls + class summary (pass/fail rates).
3. If weights ≠ 100%, preview/PDF/Excel buttons are **disabled**.
4. `preview.php` → on-screen A4 paginated report (20 rows/page, each page w/ full signature block) → Print or Download PDF.
5. `export.php` (TCPDF) and `export_excel.php` (PhpSpreadsheet) produce the same data in file formats.

### 7.2 Student: View own grade card
- `index.php` detects `student` role → renders a personal card (Student ID, Name, Course, category/midterm/finals averages, final average, transmuted grade, remarks). Status overrides shown in place of computed grades.

### 7.3 Course configuration
- **Two entry points:**
  - `course_settings.php` — full management UI (categories CRUD, grade-item mapping, custom scale CRUD).
  - Moodle **course edit form** — `hooks.php` injects semester, school year, course code, descriptive title, course & year, schedule, units, instructor, and signatory dropdowns (with "type new name" option). Saved on course submission.
- **Grade item mapping** (`course_settings.php` §3): each Moodle grade item → Category + Period.

### 7.4 Custom grading scale
- Adding the first bracket in `course_settings.php` switches the course to a custom transmutation scale; `reset` reverts to default ESSU.

---

## 8. Output Document Specification (ESSU Report of Grades)

- Form code: **ESSU-ACAD-712.b, Version 5**, Effectivity Date March 15, 2024.
- Header: ESSU logo (left), "REPORT OF GRADES" (center), Bagong Pilipinas logo (right).
- Metadata block: Subject & Course No., Descriptive Title, Course and Year, Schedule, Units, Semester, School Year.
- Rating legend (Actual / Equivalent / Adjectival) — custom or default scale.
- Table columns: NO. | NAME OF STUDENTS | STUDENT NO. | MIDTERM | FINALS | AVERAGE | REMARKS. Failed rows rendered in red.
- Trailer: `***Nothing Follows***` on last page/data block; signature block (Instructor, Department Head, Registrar, College Dean).
- Pagination: 20 student rows per page; every page carries its own header, legend, table header, and signature block (HTML + PDF). Excel is a single sheet.

---

## 9. Security & Integrity Mechanisms

- `require_login()` + `require_capability()` on all sensitive pages.
- `require_sesskey()` on all `course_settings.php` POST actions (CSRF protection).
- Input sanitized via Moodle `PARAM_*` constants (`PARAM_INT`, `PARAM_TEXT`, `PARAM_FLOAT`, `PARAM_ALPHA`).
- Output escaped with `s()`, `htmlspecialchars()`, `format_string()`.
- Students cannot reach faculty/export paths (capability gate).
- Weight-sum validation prevents exporting inconsistent documents.

---

## 10. Notable Design Decisions & Constraints

- **Single source of truth:** all three outputs (preview/PDF/Excel) consume `gradesheet_service::compute_all_grades()`, eliminating divergence.
- **Midterm/Finals + Category duality:** A grade item is mapped to both a *period* (midterm/finals) and a *category* (weighting). Category weighting is always applied to compute the period average; if an item is not mapped to an existing category, it is excluded from computation.
- **Custom scale flexibility:** equivalents can be non-numeric (e.g., letter grades) — hence no interpolation on custom scales.
- **Safe failure:** unmatched custom-scale scores are treated as failing/not-classified rather than silently passing.
- **Auto-initialization:** defaults seeded on course creation and on first access, reducing setup friction.
- **Excel dependency path** uses `dirname($CFG->dirroot).'/vendor/autoload.php'` (project-root vendor), typical for non-composer Moodle setups.

---

## 11. Evolution / Version History (from `db/upgrade.php`)

| Version | Change |
|---------|--------|
| 2026031201 | Add `local_gradesheet_itemmap` table |
| 2026031202 | Add report header fields to `config` (semester, schoolyear, course info, signatories) |
| 2026031203 | Add `categories` table; add `categoryid` to `itemmap` |
| 2026031300 | Add `transmute` (custom scale) table |
| 2026081800 | Add `status` (override) table |
| 2026081900 | Add `ispassing` flag to `transmute` |

---

## 12. Suggested Thesis Comparison Points

This overview supports comparison against thesis claims such as:

1. **Automation scope** — Does the thesis claim "automated generation"? Verify: grade extraction is automatic from Moodle gradebook; computation, transmutation, and document layout are automatic; only mapping/configuration requires manual input.
2. **Grading formula fidelity** — The Philippine/ESSU 1.0–5.0 transmutation (§5.3) and weighted-category model (§5.2) should match the thesis's described formula.
3. **Output compliance** — The ESSU-ACAD-712.b form layout (§8) is the document the thesis targets.
4. **Role/permission model** — Student vs faculty views (§2, §7.2) align with thesis user-role descriptions.
5. **Maintainability** — Thin-controller/service architecture (§3) and single-source formula support any "scalability/maintainability" claims.
6. **Customizability** — Per-course custom scales and categories (§5.4, §7.4) support claims of adaptability across departments.
