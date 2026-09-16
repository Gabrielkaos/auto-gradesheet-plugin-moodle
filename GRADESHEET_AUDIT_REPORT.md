# Security, Logic & Quality Audit Report: Moodle Grade Sheet Generator (`local_gradesheet`)

**Target Plugin:** `local_gradesheet` (v1.6, `2026091600`)  
**Assessment Scope:** Source code auditing, access control validation, grading calculation logic, data integrity, and Moodle standard compliance.  
**Mode:** Current State Assessment (Read-Only).

---

## 1. Executive Summary & Active Findings Matrix

| Ref # | Category | Severity | Description | Location | Impact |
|:---|:---|:---:|:---|:---|:---|
| **SEC-01** | Access Control / UI | **HIGH** | Role Inversion: Non-Editing Teachers Demoted to Student View | [`index.php:105`](file:///srv/http/moodle/public/local/gradesheet/index.php#L105) | Non-editing teachers are served a personal student grade card instead of the student roster. |
| **SEC-02** | Data Isolation | **HIGH** | Group Isolation Bypass via URL Parameter (IDOR) | [`classes/helper.php:271`](file:///srv/http/moodle/public/local/gradesheet/classes/helper.php#L271) | Instructors in `SEPARATEGROUPS` mode can inspect/export other sections by supplying `?group=<id>`. |
| **SEC-03** | Privacy / Policy | **MEDIUM** | Course-Level Gradebook Display Policy Bypass (`showgrades = 0`) | [`index.php:112`](file:///srv/http/moodle/public/local/gradesheet/index.php#L112) | Students can view calculated term grades even if the course gradebook visibility is turned off. |
| **SEC-04** | Access Control / UI | **MEDIUM** | Crashing Action Buttons for Non-Editing Teachers | [`index.php:251-254`](file:///srv/http/moodle/public/local/gradesheet/index.php#L251-L254) | Roster displays Settings, Export, and Preview buttons that throw fatal permission errors when clicked. |
| **SEC-05** | Injection Risk | **LOW** | Direct SQL Identifier Interpolation | [`classes/hooks.php:22`](file:///srv/http/moodle/public/local/gradesheet/classes/hooks.php#L22) | Dynamic field name in `get_distinct_options()` is injected into raw SQL without column whitelisting. |
| **DAT-01** | Data Integrity | **LOW** | Unbounded Text Inputs Causing Database Exceptions | [`course_settings.php:51-61`](file:///srv/http/moodle/public/local/gradesheet/course_settings.php#L51-L61) | Fields exceeding table schema bounds (e.g. 100+ chars) trigger fatal `dml_write_exception` errors. |
| **DAT-02** | Integrity / IDOR | **LOW** | Grade Item Mapping Lacks Course Ownership Check | [`course_settings.php:215-233`](file:///srv/http/moodle/public/local/gradesheet/course_settings.php#L215-L233) | `categoryid` supplied during grade item mapping is stored without verifying it belongs to the course. |
| **UI-01** | Usability / Safety | **LOW** | Missing Confirmation Modal on Bracket Deletion | [`course_settings.php:623`](file:///srv/http/moodle/public/local/gradesheet/course_settings.php#L623) | Clicking "Delete" on custom transmutation brackets immediately drops the record without confirmation. |

---

## 2. Detailed Technical Breakdown & Mechanics

### SEC-01: Role Inversion — Non-Editing Teachers Treated as Students
- **Location:** [`index.php` (Line 105)](file:///srv/http/moodle/public/local/gradesheet/index.php#L105) and [`db/access.php` (Lines 8–11)](file:///srv/http/moodle/public/local/gradesheet/db/access.php#L8-L11)
- **Mechanics:**  
  `db/access.php` assigns `local/gradesheet:manage` exclusively to `editingteacher` and `manager`. Non-editing teachers (`teacher` archetype) only hold `local/gradesheet:view`.
  However, `index.php` uses `local/gradesheet:manage` as the decision branch for whether a user is faculty or a student:
  ```php
  // index.php: Line 105
  if (!has_capability('local/gradesheet:manage', $context)) {
      if (!has_capability('local/gradesheet:view', $context)) {
          ...
      }
      // ── STUDENT VIEW: Renders personal "My Grades" card for $USER->id ──
      $grades = helper::compute_student_grades($courseid, $USER->id);
      ...
  } else {
      // ── TEACHER VIEW: Renders student roster table ──
  }
  ```
- **Consequence:**  
  Because non-editing teachers do not have `local/gradesheet:manage`, `index.php` treats them as a **student**, rendering a personal grade card with their own name and empty grades. They cannot access the class roster.
- **Recommended Remediation:**  
  Identify faculty using Moodle's core grading capability `moodle/grade:viewall` (held by both editing and non-editing teachers, but never by students):
  ```php
  $isteacher = has_capability('local/gradesheet:manage', $context) || has_capability('moodle/grade:viewall', $context);

  if (!$isteacher) {
      // Student personal grade card
  } else {
      // Teacher roster table
  }
  ```

---

### SEC-02: Group Isolation Bypass via URL Parameter (IDOR)
- **Location:** [`classes/helper.php` (Lines 271–292)](file:///srv/http/moodle/public/local/gradesheet/classes/helper.php#L271-L292) and [`index.php` (Line 24)](file:///srv/http/moodle/public/local/gradesheet/index.php#L24)
- **Mechanics:**  
  In `classes/helper.php`, group validation only occurs when `$groupid === 0`:
  ```php
  public static function get_non_teaching_students(\context_course $context, int $groupid = 0): array {
      global $DB;

      if ($groupid === 0) {
          $courseid = (int)$context->instanceid;
          $course = $DB->get_record('course', ['id' => $courseid]);
          if ($course) {
              $groupmode = groups_get_course_groupmode($course);
              if ($groupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
                  $activegroup = groups_get_course_group($course);
                  $groupid = $activegroup ? (int)$activegroup : -1;
              }
          }
      }

      if ($groupid === -1) {
          return [];
      }

      $all = get_enrolled_users($context, '', $groupid, 'u.*', 'u.lastname ASC, u.firstname ASC');
  ```
  `$groupid` is accepted directly from the URL query string:
  ```php
  $groupid = optional_param('group', 0, PARAM_INT);
  ```
  If an instructor belonging only to Group A manually navigates to `index.php?courseid=2&group=102` (Group B), `$groupid` is `102` (`!= 0`). The entire validation block is skipped, and `get_enrolled_users()` queries and returns Group B's student roster.
- **Consequence:**  
  In courses using **Separate Groups**, instructors lacking `moodle/site:accessallgroups` can inspect, print, and export grade sheets for any other section/group by tampering with the URL parameter.
- **Recommended Remediation:**  
  Enforce a group membership check whenever `$groupid > 0`:
  ```php
  if ($groupid > 0) {
      $course = $DB->get_record('course', ['id' => (int)$context->instanceid]);
      if ($course && groups_get_course_groupmode($course) == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
          if (!groups_is_member($groupid, $USER->id)) {
              $groupid = -1; // Access denied: user is not a member of the requested group
          }
      }
  }
  ```

---

### SEC-03: Course-Level Gradebook Display Policy Bypass (`showgrades = 0`)
- **Location:** [`index.php` (Lines 26–31, 105–112)](file:///srv/http/moodle/public/local/gradesheet/index.php#L105-L112)
- **Mechanics:**  
  Moodle course settings allow administrators and teachers to disable the gradebook for students (`$course->showgrades = 0`). In core Moodle, this completely suppresses grade views for students.
  `local_gradesheet` computes student grades by querying `grade_grades` directly and does not verify `$course->showgrades`.
- **Consequence:**  
  Students in courses where grade reporting has been disabled by institutional policy can bypass the restriction by navigating directly to `/local/gradesheet/index.php?courseid=X`.
- **Recommended Remediation:**  
  Verify `$course_obj->showgrades` before rendering the student view:
  ```php
  if (!$isteacher && empty($course_obj->showgrades)) {
      echo $OUTPUT->notification(get_string('gradesarehidden', 'grades'), 'warning');
      echo '</div></div>';
      echo $OUTPUT->footer();
      exit;
  }
  ```

---

### SEC-04: Non-Functional Action Buttons for Non-Editing Teachers
- **Location:** [`index.php` (Lines 250–255)](file:///srv/http/moodle/public/local/gradesheet/index.php#L250-L255)
- **Mechanics:**  
  When viewing the roster table, `index.php` renders:
  ```php
  echo '<a href="preview.php?courseid='      . $courseid . $groupparam . '" class="btn btn-primary mb-3">Preview & Print</a> ';
  echo '<a href="export.php?courseid='       . $courseid . $groupparam . '" class="btn btn-success mb-3">Download PDF</a> ';
  echo '<a href="export_excel.php?courseid=' . $courseid . $groupparam . '" class="btn btn-warning mb-3">Download Excel</a> ';
  echo '<a href="course_settings.php?courseid=' . $courseid . '" class="btn btn-secondary mb-3">Settings</a>';
  ```
  However, `preview.php`, `export.php`, `export_excel.php`, and `course_settings.php` all require `local/gradesheet:manage`.
- **Consequence:**  
  Non-editing teachers see action buttons that immediately throw a fatal `required_capability_exception` error when clicked.
- **Recommended Remediation:**  
  1. Hide the "Settings" button from users lacking `local/gradesheet:manage`.
  2. If non-editing teachers are allowed to export and preview reports for their assigned groups, change the permission requirement on `preview.php`, `export.php`, and `export_excel.php` from `manage` to `view` (or a dedicated `export` capability), while keeping `course_settings.php` locked to `manage`.

---

### SEC-05: Direct SQL Identifier Interpolation (`hooks.php`)
- **Location:** [`classes/hooks.php` (Line 22)](file:///srv/http/moodle/public/local/gradesheet/classes/hooks.php#L22)
- **Mechanics:**  
  ```php
  $records = $DB->get_fieldset_sql(
      "SELECT DISTINCT {$fieldname} FROM {local_gradesheet_config} WHERE {$fieldname} IS NOT NULL AND {$fieldname} != '' ORDER BY {$fieldname} ASC"
  );
  ```
- **Consequence:**  
  `$fieldname` is interpolated directly into raw SQL. Even though current calls use internal strings, this violates Moodle's secure coding standard.
- **Recommended Remediation:**  
  Add an explicit column whitelist check:
  ```php
  $allowed = ['department_head', 'registrar', 'college_dean'];
  if (!in_array($fieldname, $allowed, true)) {
      return [];
  }
  ```

---

### DAT-01: Unbounded Text Inputs Causing Database Exceptions
- **Location:** [`course_settings.php` (Lines 51–61)](file:///srv/http/moodle/public/local/gradesheet/course_settings.php#L51-L61) and [`db/install.xml` (Lines 13–21)](file:///srv/http/moodle/public/local/gradesheet/db/install.xml#L13-L21)
- **Mechanics:**  
  The schema in `install.xml` restricts field lengths:
  - `descriptive`: 100 characters
  - `coursenumber`: 50 characters
  - `courseandyear`: 50 characters
  - `schedule`: 50 characters
  - `instructor` / signatories: 100 characters
  Form submissions accept unrestricted text via `required_param(..., PARAM_TEXT)` and pass it directly to `$DB->update_record()`.
- **Consequence:**  
  Any input exceeding these length limits triggers an unhandled `dml_write_exception: Data too long for column` database error.
- **Recommended Remediation:**  
  Enforce `maxlength` attributes in the HTML form and truncate in PHP prior to writing to the database:
  ```php
  $details['descriptive'] = mb_substr($details['descriptive'], 0, 100);
  $details['schedule']    = mb_substr($details['schedule'], 0, 50);
  ```

---

### DAT-02: Grade Item Mapping Lacks Course Boundary Validation
- **Location:** [`course_settings.php` (Lines 215–233)](file:///srv/http/moodle/public/local/gradesheet/course_settings.php#L215-L233)
- **Mechanics:**  
  In the `savemapping` action:
  ```php
  $catid = optional_param('cat_' . $gitem->id, 0, PARAM_INT);
  ```
  The code saves `$catid` into `local_gradesheet_itemmap` without checking whether `$catid` belongs to the current `$courseid`.
- **Consequence:**  
  A forged POST request can link grade items to category IDs from completely different courses.
- **Recommended Remediation:**  
  Verify `$catid === 0 || isset($categories[$catid])` before storing.

---

### UI-01: Missing Confirmation Modal on Transmutation Bracket Deletion
- **Location:** [`course_settings.php` (Line 623)](file:///srv/http/moodle/public/local/gradesheet/course_settings.php#L623)
- **Mechanics:**  
  `helper::render_table_actions()` generates a delete form without an `onsubmit` confirmation dialog.
- **Consequence:**  
  Accidental clicks immediately delete the transmutation bracket.
- **Recommended Remediation:**  
  Add `onsubmit="return confirm('Delete this grading bracket?');"` to the generated form.

---

## 3. Prioritized Action Checklist

```markdown
### 🚨 Immediate Priority (Broken Workflows & Authorization)
- [x] 1. index.php (Line 105): Use moodle/grade:viewall to separate faculty from students (Fix SEC-01)
- [x] 2. classes/helper.php (Line 271): Enforce groups_is_member() when groupid > 0 in SEPARATEGROUPS mode (Fix SEC-02)

### ⚠️ Medium Priority (Access Permissions & Policy)
- [x] 3. index.php (Line 105): Check empty($course_obj->showgrades) to respect course gradebook lock (Fix SEC-03)
- [x] 4. index.php (Lines 251-254): Protect or adapt action buttons based on user capabilities (Fix SEC-04)

### 🧹 Low Priority (Hardening & Quality)
- [x] 5. classes/hooks.php (Line 22): Add column whitelist to get_distinct_options() (Fix SEC-05)
- [x] 6. course_settings.php (Line 50): Add maxlength and mb_substr() to prevent DB overflow (Fix DAT-01)
- [x] 7. course_settings.php (Line 215): Validate category ownership in savemapping (Fix DAT-02)
- [x] 8. classes/helper.php (Line 633): Add confirmation dialog to render_table_actions() (Fix UI-01)
```
