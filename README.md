# Auto Gradesheet Plugin for Moodle

A Moodle local plugin that generates "Report of Grades" documents for Eastern Samar State University (ESSU), supporting on-screen preview, PDF export, and Excel export.

> **Thesis:** This plugin was developed as part of the undergraduate thesis *"Development of an Automated Grade Sheet Generation Plugin for the Moodle Learning Management System for Faculty Grade Reporting."*

## Features

- Role-based views (students see their own grade card; faculty/admins see all students)
- Weighted grade computation by category or midterm/finals fallback
- Philippine transmutation scale (1.0–5.0 equivalent ratings)
- Print-ready HTML preview with ESSU branding
- PDF export via TCPDF
- Excel export via PhpSpreadsheet
- Course-level configuration (semester, school year, signatories, grade categories)
- Auto-initialization of defaults on course creation
- Course edit form integration via the core course-form hooks (Moodle 4.4+; on 4.3 use the plugin's Settings page)

## Repository Structure

```
local_gradesheet/
├── classes/
│   ├── helper.php              Static helpers (transmute, config loading, grade computation)
│   ├── gradesheet_service.php  Coordinator service for batch grade processing
│   ├── observer.php            Event observer (course_created)
│   └── hooks.php               Course edit form hooks
├── db/
│   ├── access.php              Capability definitions
│   ├── events.php              Event observer registration
│   ├── hooks.php               Hook callback registration
│   ├── install.xml             XMLDB schema (5 tables)
│   ├── install.php             Install callback
│   └── upgrade.php             DB upgrade steps
├── lang/en/local_gradesheet.php  Language strings
├── pix/                        Plugin images (ESSU header, logos)
├── cli/                        CLI test scripts
├── index.php                   Main UI (course selector, student/faculty views)
├── course_settings.php         Course configuration page
├── preview.php                 Print-ready HTML preview
├── export.php                  PDF export (TCPDF)
├── export_excel.php            Excel export (PhpSpreadsheet)
├── lib.php                     Moodle navigation hooks
└── version.php                 Plugin metadata
```

## Architecture

Business logic is centralized in the `classes/` layer:

- **`helper.php`** — Static methods for grade transmutation, config loading, student filtering, and per-student grade computation. Single source of truth for the grading formula.
- **`gradesheet_service.php`** — Coordinator that iterates all students and returns rows + pass/fail statistics. Used by `export.php`, `export_excel.php`, and `preview.php`.

Page files (`index.php`, `export.php`, `export_excel.php`, `preview.php`) are thin controllers responsible only for output formatting.

## Requirements

- Moodle 4.3 or later (`2023100900`); tested on 4.3, 4.4 and 4.5 (Bootstrap 4 themes)
- PHP 8.0+ (whatever the Moodle release requires)
- PhpSpreadsheet (bundled with Moodle)
- TCPDF (bundled with Moodle)

## Installation

1. Clone the repository into `local/gradesheet/` within your Moodle installation:
   ```bash
   git clone https://github.com/Gabrielkaos/auto-gradesheet-plugin-moodle.git local/gradesheet
   ```

2. In Moodle, go to **Site administration → Notifications**.

3. Complete the installation prompts.

## Usage

1. Open a Moodle course.
2. Navigate to the plugin page via the course navigation or admin menu.
3. Configure gradesheet settings (semester, school year, signatories, grade categories).
4. Preview the gradesheet.
5. Export as PDF or Excel.

## Sections (one grade sheet per section)

A Moodle **group** is a section. When a course has groups, the dashboard shows a section picker (regardless of the course's group-mode setting), an "All sections at a glance" table with Preview / PDF / Excel buttons per section, and **Download every section as PDF (ZIP)** (`export_all.php`). Each section's sheet has its own roster, section label, schedule and instructor line (Settings → Per-Section Overrides); grading categories, weights and the formula are shared by all sections of the course. Students who belong to no group are flagged because they appear on no section sheet. Under *Separate groups* mode, teachers only see their own sections and cannot open the combined sheet.

## Configuration

- Course-level settings: `course_settings.php` (all versions) or the Moodle course edit form (Moodle 4.4+ only; `\core_course\hook\after_form_definition` does not exist in 4.3, so on 4.3 the "Gradesheet Configuration" section simply does not appear in the course form — nothing else is affected).
- Signatories are auto-detected from the roles the site already has, matched by name: a role named Dean or College Dean (assign at the college category), one named Program Head, Program Chair, Department Head or Chairperson (assign at the department/program category), and Registrar (assign at the system level). Only a Registrar role is created on install, and only if none exists. Site administration → Plugins → Local plugins → Grade Sheet Generator lets you pick a different role for each line.
- Default categories and config are auto-created on course creation.
- Language strings can be adjusted via `lang/en/local_gradesheet.php`.

## Security & Permissions

- `local/gradesheet:manage` — required for preview, exports, settings and student statuses (editing teachers, managers).
- `local/gradesheet:view` — required for viewing grades (students, teachers, managers).
- Capability checks and context validation are enforced on all entry points.

## Contributing

Contributions are welcome.

1. Fork the repository
2. Create a feature branch
3. Commit your changes
4. Open a pull request

## License

GPL-3.0 (aligned with Moodle ecosystem expectations).
