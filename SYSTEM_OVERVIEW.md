# SYSTEM OVERVIEW — Auto Gradesheet Plugin for Moodle (`local_gradesheet`)

> **Document Purpose:** Provide an objective, self-contained technical specification of the `local_gradesheet` Moodle plugin architecture, data models, and computation pipeline, aligned with the undergraduate thesis *"Development of an Automated Grade Sheet Generation Plugin for the Moodle Learning Management System for Faculty Grade Reporting"* (College of Computer Studies, Eastern Samar State University).

---

## 1. System at a Glance

| Attribute | Value |
|-----------|-------|
| System type | Moodle **local plugin** (`local_gradesheet`) |
| Host platform | Moodle 4.5+ (`requires = 2024100700`) |
| Current release | v1.6 (`version = 2026091600`, `MATURITY_STABLE`) |
| Language | PHP (Moodle coding standards), HTML/CSS, JavaScript |
| Primary output | ESSU "Report of Grades" — on-screen preview, PDF, Excel |
| Target institution | Eastern Samar State University (ESSU) — College of Computer Studies |
| External libraries | TCPDF (PDF, Moodle core bundled), PhpSpreadsheet (Excel, Moodle core autoloader) |
| License | GNU General Public License v3.0 (GPL-3.0) |

**Operational Context and Objective:** Faculty members within the target academic unit submit a standardized grade report (form ESSU-ACAD-712.b, Version 5). As documented in the study's baseline assessment, the prevailing workflow involves extracting raw grade records from Moodle into external spreadsheets for manual calculation, transmutation, and reformatting. The plugin is implemented to automate the retrieval of gradebook scores, period weighting, institutional transmutation, and document generation directly within the LMS interface, subject to course-level configuration.

---

## 2. Stakeholders & Role-Based Access Control

| Role / Context | Capability / Flag | System View & Permissions |
|----------------|-------------------|---------------------------|
| Student | `local/gradesheet:view` | Dedicated personal grade card only (own period scores, final average, transmuted grade, remarks). Respects course gradebook display policy (`$course->showgrades`). |
| Non-editing Teacher | `local/gradesheet:view` + `moodle/grade:viewall` | Roster and grade overview for assigned groups. Read-only access to roster calculations. |
| Editing Teacher | `local/gradesheet:view` + `local/gradesheet:manage` | Complete grade overview for assigned groups, course settings, item mapping, print preview, and document exports. |
| Manager / Site Admin | Both capabilities | Site-wide access across all courses and sections. |
| Instructor with `manage` | `local/gradesheet:manage` | Administrative status overrides per student (Incomplete, Dropped, Withdrawn with Permission, In Progress). |

**Access Control Enforcement:**
- `classes/helper.php::get_non_teaching_students()` filters the student roster by excluding site administrators, users with `moodle/grade:viewall`, and users holding `local/gradesheet:manage`, while respecting Moodle's group separation modes.
- Explicit capability checks (`require_capability`) are enforced at every controller entry point (`index.php`, `preview.php`, `export.php`, `export_excel.php`, `course_settings.php`).

---

## 3. Architecture Overview

The plugin follows a **thin-controller / thick-service** modular architecture:

```
         Moodle Core (course lifecycle events, course edit form hooks)
                                   │
         ┌─────────────────────────┴─────────────────────────┐
         │                 classes/ (business logic)          │
         │  helper.php            gradesheet_service.php       │
         │  (formula single source)  (batch coordinator)      │
         │  observer.php (events)  hooks.php (form hooks)     │
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

**Architectural Boundaries:**
- Core mathematical operations, normalization, and scale transmutation reside in `classes/helper.php` and `classes/gradesheet_service.php`.
- Page controllers are restricted to presentation, parameter retrieval, and output dispatch; they do not perform independent grade calculations.
- `gradesheet_service::compute_all_grades()` serves as the unified batch coordinator for `preview.php`, `export.php`, and `export_excel.php`, maintaining computational parity across on-screen, PDF, and spreadsheet representations.

---

## 4. Database Schema (5 Custom Tables)

Defined declaratively in `db/install.xml` and migrated via `db/upgrade.php`.

### 4.1 `local_gradesheet_config` (Course Configuration & Metadata)
- `id`, `courseid` (UNIQUE)
- Report metadata: `semester`, `schoolyear`, `coursenumber`, `descriptive`, `courseandyear`, `schedule`, `units`
- Designated signatories: `instructor`, `department_head`, `registrar`, `college_dean`
- Timestamps: `timecreated`, `timemodified`

### 4.2 `local_gradesheet_categories` (Grading Components & Weights)
- `id`, `courseid`, `name`, `weight` (%), `sortorder`
- Default initial configuration: **Quizzes (30%)**, **Activities (30%)**, **Exams (40%)**
- Validation constraint: Weights must sum to 100.0% (±0.01 tolerance) to enable report preview and export operations.

### 4.3 `local_gradesheet_itemmap` (Grade Item Mapping)
- `id`, `courseid`, `gradeitemid` (UNIQUE pair w/ `courseid`), `period` (`midterm` / `finals`), `categoryid` (FK to `local_gradesheet_categories`)
- Links native Moodle gradebook items (`mdl_grade_items`) to a grading period and an institutional category.

### 4.4 `local_gradesheet_transmute` (Custom Transmutation Scale)
- `id`, `courseid`, `minscore`, `maxscore`, `equivalent`, `descriptor`, `sortorder`, `ispassing` (int, default 1)
- Supports optional course-level customization of the grading scale. When records exist for a course, the custom scale is applied; otherwise, the system defaults to the standard ESSU transmutation scale.

### 4.5 `local_gradesheet_status` (Student Academic Status Overrides)
- `id`, `courseid`, `userid` (UNIQUE pair), `status` (`''`=active, `inc`, `dropped`, `wp`, `ip`), `timemodified`
- Replaces computed numeric marks with official non-numeric status notations on reports; excluded from class pass/fail summary statistics.

---

## 5. Core Grading and Computation Logic (`helper.php`)

### 5.1 Grade Extraction and Normalization (`compute_student_grades`)
1. **Query Boundary:** Fetches non-course grade items (`itemtype != 'course'`, `itemname IS NOT NULL`) with numeric grade types (`gradetype = 1`). Textual scales and qualitative comments are excluded.
2. **Exclusion Rules:** Skips items marked hidden (`$gi->is_hidden()`), student-specific exclusions or hidden marks (`$gg->is_hidden() || $gg->is_excluded()`), and ungraded or unsubmitted assignments (`finalgrade === null || finalgrade === ''`).
3. **Score Normalization:** Normalizes raw points to a 0–100 percentage scale: `(raw_grade / grademax) * 100`.
4. **Mapping Association:** Matches each item against `local_gradesheet_itemmap` to obtain assigned `period` and `categoryid`. Unmapped items or items mapped to deleted categories are excluded from period computations.

### 5.2 Period and Final Average Calculations
- **Category Average:** Computes the arithmetic mean of normalized percentage scores for all graded items mapped to each category.
- **Period Calculation:**
  - Computes the weighted average across mapped categories:  
    $$\text{Period Average} = \frac{\sum (\text{Category Average} \times \text{Weight})}{\sum \text{Weight}}$$
  - If all active categories have a configured weight of zero, falls back to an unweighted arithmetic average of active categories.
- **Final Term Average:**
  - Evaluates a 50/50 distribution between periods:  
    $$\text{Final Average} = \frac{\text{Midterm Average} + \text{Finals Average}}{2}$$
  - If only one period contains graded assessments, that period's average serves as the final average.

### 5.3 Institutional Transmutation (`transmute_equiv`)
- **Default ESSU Scale:** Implements piecewise linear interpolation within the institutional rating bands:

  | Raw Percentage (%) | Equivalent Rating | Adjectival Description |
  |--------------------|-------------------|------------------------|
  | 100 | 1.0 | Excellent |
  | 90.00 – 99.99 | 1.1 – 1.5 | Superior |
  | 85.00 – 89.99 | 1.6 – 2.0 | Very Good |
  | 80.00 – 84.99 | 2.1 – 2.5 | Good |
  | 75.00 – 79.99 | 2.6 – 3.0 | Fair / Passing |
  | 70.00 – 74.99 | 3.1 – 3.5 | Conditional Failure |
  | 55.00 – 69.99 | 3.6 – 5.0 | Failing |
  | < 55.00 | 5.0 | Failure |

  *Interpolation formula:*  
  $$\text{Equiv} = \text{Eq}_{\max} - \left( \frac{\text{Band}_{\max} - \text{Score}}{\text{Band}_{\max} - \text{Band}_{\min}} \right) \times (\text{Eq}_{\max} - \text{Eq}_{\min})$$  
  *(Rounded to one decimal place).*

- **Custom Scale Behavior:** When custom brackets exist in `local_gradesheet_transmute`, evaluations match raw scores against brackets ordered descending by `minscore`. The bracket supplies the adjectival descriptor and passing status (`ispassing`). Unmatched scores default to non-passing to prevent unvalidated grade assignment.

### 5.4 Passing Determination and Remarks (`is_passing`)
- **Default Scale:** Scores with a final raw percentage $\ge 75.00\%$ are classified as passed (`PASSED`); scores $< 75.00\%$ are classified as failed (`FAILED`).
- **Custom Scale:** Driven by the `ispassing` flag of the matched bracket.
- Displayed remarks are normalized to title case (`Passed` / `Failed`) during service formatting.

### 5.5 Configuration Validation
- **Weight Totals:** `validate_weight_sum()` verifies that defined category weights total 100.0% within a floating-point tolerance of $\pm 0.01$ and that at least one category exists.
- **Custom Scale Consistency:** `validate_custom_scale()` checks custom scale brackets for contiguous boundaries, overlaps, and minimum score coverage.

---

## 6. System Modules & Codebase Mapping

| File Path | Functional Role | Implementation Scope |
|-----------|-----------------|----------------------|
| `classes/helper.php` | Service Helper | Grading mathematics, transmutation formulas, configuration loaders, roster filtering, validation routines. |
| `classes/gradesheet_service.php` | Batch Service | Roster computation coordinator (`compute_all_grades`), pass/fail aggregation, formatted data rows. |
| `classes/observer.php` | Event Observer | Course creation listener (seeds initial defaults); course and user deletion listeners (purges orphaned records). |
| `classes/hooks.php` | Moodle Form Hooks | Injects official gradesheet metadata and signatory fields into the Moodle course settings form (`/course/edit.php`). |
| `db/access.php` | Access Control | Declares plugin capabilities (`local/gradesheet:view`, `local/gradesheet:manage`). |
| `db/events.php` | Event Registration | Binds core Moodle event handlers. |
| `db/hooks.php` | Hook Registration | Binds course edit form injection callbacks. |
| `db/install.xml` | Schema Definition | XMLDB relational schema for all five custom database tables. |
| `db/upgrade.php` | Schema Migration | Incremental database schema version upgrades. |
| `lib.php` | Navigation | Registers course and secondary navigation nodes for "Grade Sheet". |
| `index.php` | Faculty Dashboard / Student Card | Entry point: renders faculty overview table or student personal grade card based on role capabilities. |
| `preview.php` | On-Screen Print Preview | Generates HTML view matching the ESSU-ACAD-712.b report layout with pagination and signature blocks. |
| `export.php` | PDF Export Engine | Generates vector PDF document via TCPDF formatted to official institutional specifications. |
| `export_excel.php` | Excel Export Engine | Generates the spreadsheet via `classes/excel_builder.php` (PhpSpreadsheet): A4 pages of 20 students with logos, legend, signature block and page-number footer, with explicit page breaks so printing matches the PDF; values are pre-calculated and all text is editable. |
| `course_settings.php` | Course Settings Controller | Configuration management: course details, signatories, category weights, item mapping, custom scale setup. |
| `cli/` | CLI Automation Utilities | Development and verification scripts (`create_test_course.php`, `generate_test_data.php`, `test_form_hook.php`). |
| `tests/heavy_test_suite.php` | Verification Test Suite | Automated test harness executing boundary checks, validation rules, group isolation, and cohort scaling benchmarks. |

---

## 7. Key User Flows

### 7.1 Faculty: Report Generation and Export
1. Access course and select "Grade Sheet" from the course navigation.
2. The dashboard (`index.php`) loads enrolled students, computes period marks and transmutation equivalents, and presents class summary metrics.
3. If category weights do not sum to 100%, action controls for preview and export are disabled, with an advisory banner displayed.
4. Selecting "Print Preview" opens `preview.php`, rendering paginated A4 sheets (20 students per page) with official headers, legends, and signature blocks.
5. Selecting "Export PDF" (`export.php`) or "Export Excel" (`export_excel.php`) generates downloadable documents containing identical computed data.

### 7.2 Student: Individual Performance View
- Enrolled students accessing `index.php` without management capabilities are directed to a read-only view.
- Displays individual midterm percentage, final term percentage, final average, transmutation rating, and remarks.
- Peer records and class rosters remain restricted. If the course gradebook is hidden (`showgrades = 0`), student access is suspended.

### 7.3 Course Configuration and Mapping
- Configurable through `course_settings.php` or native Moodle course settings (`/course/edit.php` via `hooks.php`).
- Faculty define assessment categories, assign weights totaling 100%, and map individual Moodle grade items to their corresponding period and category.

---

## 8. Output Document Specification (Form ESSU-ACAD-712.b)

- **Standard Code:** Form ESSU-ACAD-712.b, Version 5 (Effectivity Date: March 15, 2024).
- **Institutional Header:** Institutional branding featuring the ESSU logo (left), document title "REPORT OF GRADES" (center), and Bagong Pilipinas logo (right).
- **Course Metadata Block:** Subject & Course Number, Descriptive Title, Course and Year, Class Schedule, Credit Units, Semester, and Academic Year.
- **Rating Legend:** Tabular legend defining raw score intervals, equivalent numerical ratings, and adjectival descriptions.
- **Roster Table Structure:** Sequenced columns: `NO.`, `NAME OF STUDENTS`, `STUDENT NO.`, `MIDTERM`, `FINALS`, `AVERAGE`, `REMARKS`. Non-passing remarks are highlighted in red text.
- **Document Pagination:** Structured at exactly 20 student rows per page. Each page includes the full header, metadata, column headings, and official signature block.
- **Trailer & Signatures:** Final student record is followed by `*** Nothing Follows ***`. Institutional signature lines accommodate the Instructor, Department Head, University Registrar, and College Dean.

---

## 9. Security, Data Validation, and Architectural Controls

The plugin implements specific controls to maintain system stability, enforce privacy, and adhere to Moodle platform standards:

1. **Role and Capability Checks:** All page controllers enforce `require_login()` and inspect assigned capabilities (`local/gradesheet:view` or `local/gradesheet:manage`). Unauthenticated or unauthorized requests are rejected.
2. **Group Isolation in Separate Groups Mode:** For courses configured with `SEPARATEGROUPS`, request parameters (`?group=id`) are verified against `groups_is_member()` via `check_group_access()`. Unauthorized cross-group access attempts are intercepted and blocked.
3. **Course Gradebook Visibility Synchronization:** When course settings specify `$course->showgrades = 0`, student access to individual grade cards is suspended to respect institutional publication schedules.
4. **Numerical Data Whitelist Filtering:** The grade extraction query restricts item collection to numeric grades (`gradetype = 1`), preventing non-numeric or text-based grading scales from entering mathematical formulas.
5. **Failsafe Weight Sum Validation Gate:** Preview and export actions are conditioned on `validate_weight_sum()`. Incomplete or unbalanced weighting configurations (not summing to 100.0%) prevent document generation.
6. **Input Sanitization and CSRF Protection:** State-modifying requests in `course_settings.php` require valid Moodle session keys (`require_sesskey()`). Inputs are sanitized using Moodle parameter cleaning types (`PARAM_INT`, `PARAM_TEXT`, `PARAM_FLOAT`, `PARAM_ALPHA`), and rendered strings are escaped via `s()`, `format_string()`, or `htmlspecialchars()`.

---

## 10. Design Decisions and Operational Constraints

- **Unified Computation Pipeline:** The on-screen preview, PDF generator, and Excel exporter consume the same service method (`gradesheet_service::compute_all_grades()`), preventing computational discrepancies across output formats.
- **Dual Mapping Model:** Assessment items require explicit association with both a period (`midterm` or `finals`) and a weighted category. Unmapped items are excluded from computation to prevent unintended grade distortion.
- **Handling of Unmatched Custom Scale Scores:** When a custom scale is active, any score falling outside configured brackets defaults to non-passing/unclassified rather than defaulting to passing.
- **Pre-Calculated Export Values:** The Excel exporter writes final numerical values rather than dynamic spreadsheet formulas, ensuring exported records remain identical to the validated preview and PDF representations.
- **Baseline Configuration Seeding:** Courses automatically seed default categories (Quizzes 30%, Activities 30%, Exams 40%) upon creation or initial plugin access, providing an initial baseline configuration.
- **External Library Availability:** PDF export relies on Moodle's built-in TCPDF library; Excel export utilizes PhpSpreadsheet via Moodle's autoloader, providing graceful error notifications if required components are absent.

---

## 11. Schema Evolution and Version History

| Component Version | Database Release | Summary of Technical Changes |
|-------------------|------------------|------------------------------|
| `2026031201` | Initial mapping table | Introduced `local_gradesheet_itemmap` for item-to-period assignment. |
| `2026031202` | Metadata extension | Added institutional header fields and signatory parameters to `local_gradesheet_config`. |
| `2026031203` | Category schema | Added `local_gradesheet_categories` and linked `categoryid` foreign key into `itemmap`. |
| `2026031300` | Custom scale schema | Added `local_gradesheet_transmute` to store custom score brackets. |
| `2026081800` | Status override schema | Added `local_gradesheet_status` to support administrative status values. |
| `2026081900` | Custom passing flag | Added `ispassing` column to `local_gradesheet_transmute`. |
| `2026082600` | Schema cleanup | Removed deprecated legacy weight columns from `config`. |
| `2026091600` | Security and access update | Restricted management operations to editing teachers, integrated group isolation checks, and updated capability mappings. |

---

## 12. Technical Alignment with Thesis Objectives and Scope

The technical structure of the `local_gradesheet` plugin directly reflects the specific objectives and operational scope defined in Chapter I and Chapter III of the thesis:

### 12.1 Alignment with Specific Objectives

| Thesis Objective (Chapter I) | Corresponding Technical Implementation | Primary Codebase References |
|------------------------------|----------------------------------------|-----------------------------|
| **Objective 1: System Requirements & Architecture** | Thin-controller and thick-service modular design; 5 custom XMLDB tables; Moodle API compliance. | `db/install.xml`, `classes/helper.php`, `classes/gradesheet_service.php` |
| **Objective 2a: Gradebook Data Retrieval** | Automated extraction of numerical scores from `mdl_grade_items` and `mdl_grade_grades`; student enrollment filtering. | `classes/helper.php::prefetch_course_grades()`, `get_non_teaching_students()` |
| **Objective 2b: Computation, Transmutation & Status** | Category percentage weighting, 50/50 period distribution, piecewise linear ESSU transmutation (1.0–5.0), custom scale brackets, and administrative status overrides (INC, Dropped, WP, IP). | `classes/helper.php::compute_student_grades()`, `transmute_equiv()`, `set_student_status()` |
| **Objective 2c: Registrar-Aligned Formatting & Export** | Layout rendering complying with Form ESSU-ACAD-712.b Version 5; 20 rows/page pagination; multi-format generation via HTML preview, TCPDF, and PhpSpreadsheet. | `preview.php`, `export.php`, `export_excel.php`, `styles.css` |
| **Objective 3: Software Verification & Quality Evaluation** | Automated assertion-based test suite covering mathematical boundary conditions, input validation, and cohort scaling up to 2,500 students, supporting ISO/IEC 25010 evaluation. | `tests/heavy_test_suite.php`, `cli/` scripts |

### 12.2 Operational Scope and Delimitations

To maintain consistency with the delimitations stated in the thesis study, the plugin's operational boundaries are established as follows:

1. **Target Operational Setting:** Implementation is tailored to the grading workflow of the College of Computer Studies at Eastern Samar State University Main Campus.
2. **Document Nature:** Generated reports replicate the required structural layout and field arrangement of Form ESSU-ACAD-712.b Version 5 as system-generated documents; the system does not produce scanned or physical document copies.
3. **Integration Boundary:** The plugin operates as an extension within the Moodle LMS. It does not replace the institution's student information system (SIS) or automate central registrar database workflows outside Moodle.
4. **Institutional Grading Rules:** The system applies existing approved institutional grading formulas and transmutation rules; it does not define or alter institutional grading policy.
5. **Configuration Prerequisites:** While grade calculation and document layout are automated, initial course setup—specifically mapping gradebook items to categories and assigning category percentage weights—remains an administrative prerequisite performed by faculty users.
