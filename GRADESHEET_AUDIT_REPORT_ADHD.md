# ⚡ Quick-Scan Audit: Gradesheet Plugin Bugs & Holes
*(ADHD-Friendly, Low-Text, High-Signal Edition)*

---

## ⏱️ 30-Second TL;DR
Your plugin mostly looks solid, but **4 bugs break things right now**, **3 permission/privacy holes leak data**, and there is a **duplicate copy of your whole repo** sitting inside a subfolder.

```
🚨 FIX TODAY (Hard Crashes & Bad Math) ──► 4 bugs
⚠️ FIX THIS WEEK (Privacy & Permissions) ──► 4 holes
🧹 HOUSEKEEPING (Clutter & Formatting) ──► 4 cleanups
```

---

## 🚨 Bucket 1: Fix First (Crashes & Grade Corruptions)

### 1. 🛑 Ungraded assignments count as 0% (Students fail on Day 1)
- **Where:** `classes/helper.php` (Line 423)
- **The Problem:** If a quiz hasn't happened yet, the code counts it as `0/100` instead of skipping it.
- **Why it matters:** On Day 1, every student shows **FAILED (5.0)** with a **0% class passing rate**!
- **Quick Fix:** Only include assignments that actually have a student grade recorded (`aggregateonlygraded`).

### 2. 💥 Excel Download crashes if using a custom scale
- **Where:** `export_excel.php` (Line 130)
- **The Problem:** Code looks for index `[2]` on an array that only has 2 items (`[0]` and `[1]`).
- **Why it matters:** Generates a **PHP Fatal Error: Undefined array key 2**. The download fails completely.
- **Quick Fix:** For the header row (`$li === 0`), read index `[1]` instead of `[2]`.

### 3. 📄 PDF export crashes if a course has 0 students
- **Where:** `export.php` (Line 110)
- **The Problem:** TCPDF expects at least one page. If 0 students are enrolled, 0 pages are generated.
- **Why it matters:** Crashes with `TCPDF ERROR: No pages were added to document`.
- **Quick Fix:** Redirect with a friendly error if `$rows` is empty, or call `$pdf->AddPage()` once.

### 4. 🔣 "Reset to Default Scale" button won't click
- **Where:** `course_settings.php` (Line 560)
- **The Problem:** Extra backslash typo (`\\\');`) escapes the closing quote in JavaScript.
- **Why it matters:** Browser throws `Uncaught SyntaxError`. Clicking the button can fail or do nothing.
- **Quick Fix:** Change `\\\');` to `\');`.

---

## ⚠️ Bucket 2: Fix Soon (Privacy & Permissions)

### 5. 🕵️ Students can sneak peek hidden grades
- **Where:** `classes/helper.php` (Line 410) & `index.php` (Line 110)
- **The Problem:** It pulls raw grades directly from the database without checking if the category is hidden or unreleased.
- **Why it matters:** Students can open the Grade Sheet page and see their final grade before faculty releases it.
- **Quick Fix:** Check `$gi->get_parent_category()->is_hidden()` before computing student view.

### 6. 🔓 Non-editing teachers can alter Dean/Registrar names & delete categories
- **Where:** `db/access.php` (Line 5) & `course_settings.php` (Line 11)
- **The Problem:** `local/gradesheet:manage` is given to archetype `teacher` (non-editing).
- **Why it matters:** Non-editing teachers can wipe out grading categories, change weights, or rename college signatories.
- **Quick Fix:** Restrict `course_settings.php` to `editingteacher` and `manager`.

### 7. 👥 Multi-section / Group leak (Separate Groups ignored)
- **Where:** `classes/helper.php` (Line 268)
- **The Problem:** `get_enrolled_users()` uses `$groupid = 0`.
- **Why it matters:** Instructors assigned to Section A can see and export student grades from Section B.
- **Quick Fix:** Pass the active group ID when course is in `SEPARATEGROUPS` mode.

### 8. 📐 Custom Scale ignores `Max Score`
- **Where:** `classes/helper.php` (Line 166)
- **The Problem:** Brackets only check `if ($grade >= $row->minscore)` and completely ignore `$row->maxscore`.
- **Why it matters:** A student scoring outside the bracket range can be matched to the wrong grade bracket.
- **Quick Fix:** Check `if ($grade >= $row->minscore && $grade <= $row->maxscore)`.

---

## 🧹 Bucket 3: Housekeeping (Easy Wins)

### 9. 🗑️ Delete the nested `auto-gradesheet/` folder
- **Where:** `/srv/http/moodle/public/local/gradesheet/auto-gradesheet/`
- **Why:** It's an entire older copy of your repository with an extra `.git` folder inside it. Wastes space and leaks paths if someone hits it via URL.

### 10. 🗑️ Delete the typo test script
- **Where:** `cli/genrate_test_data.php`
- **Why:** Duplicate of `cli/generate_test_data.php` (missing the "e").

### 11. 🏷️ Wrap export filenames in `clean_filename()`
- **Where:** `export.php` (Line 237) & `export_excel.php` (Line 311)
- **Why:** If a course title contains `/` or `"`, browser downloads break.

### 12. 📏 Shorten long PDF status names
- **Where:** `export.php` (Line 188)
- **Why:** `"Withdrawn w/ permission"` is 24 letters long and spills outside the 22mm Remarks box. Use `"WP"`.

---

## 📋 Action Checklist

```markdown
[x] 1. helper.php: Skip ungraded items in compute_student_grades()
[x] 2. export_excel.php: Fix $lrow[2] index error on line 130
[x] 3. export.php: Handle 0 students before calling $pdf->Output()
[x] 4. course_settings.php: Fix quote escaping on line 560
[x] 5. helper.php: Honor hidden grade categories for student view
[x] 6. db/access.php: Remove archetype 'teacher' from manage capability
[x] 7. helper.php: Respect course separate groups mode
[x] 8. Delete auto-gradesheet/ and genrate_test_data.php
```
