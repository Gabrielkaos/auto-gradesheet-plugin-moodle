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
- Course edit form integration via Moodle 4.5+ hooks

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

- Moodle 4.5+ (`2024100700`)
- PHP version supported by Moodle
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

## Configuration

- Course-level settings: `course_settings.php` or the Moodle course edit form (Moodle 4.5+ hooks).
- Default categories and config are auto-created on course creation.
- Language strings can be adjusted via `lang/en/local_gradesheet.php`.

## Security & Permissions

- `local/gradesheet:manage` — required for preview and exports (teachers, managers).
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
