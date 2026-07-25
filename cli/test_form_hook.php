<?php
define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

global $DB;

echo "=== Testing Gradesheet Course Edit Form Hook ===\n\n";

// Create a dummy course first.
$shortname = 'WEBHOOK-' . date('Ymd-His');
$coursedata = (object)[
    'fullname'  => 'Web Hook Test Course',
    'shortname' => $shortname,
    'category'  => 1,
    'format'    => 'topics',
    'visible'   => 1,
];

$course = create_course($coursedata);
echo "1. Created course: {$course->fullname} (id={$course->id})\n";

// Simulate after_form_submission hook data.
$submitteddata = (object)[
    'id'                             => $course->id,
    'gradesheet_semester'               => 'Second Semester',
    'gradesheet_schoolyear'             => '2026-2027',
    'gradesheet_coursenumber'           => 'IT 202',
    'gradesheet_descriptive'            => 'Web Development 2',
    'gradesheet_courseandyear'          => 'BSIT 2B',
    'gradesheet_schedule_days'          => 'TTH',
    'gradesheet_schedule_time'          => '1:15-2:45 PM',
    'gradesheet_units'                  => '3',
    'gradesheet_instructor'             => 'PROF. ALICE SMITH',
    'gradesheet_department_head_select' => 'DR. BOB JOHNSON',
    'gradesheet_registrar_new'          => 'MS. CAROL WHITE',
    'gradesheet_college_dean_select'    => 'DR. DAVID BROWN',
];

$submissionhook = new \core_course\hook\after_form_submission($submitteddata, false);
\local_gradesheet\hooks::after_form_submission($submissionhook);

echo "2. Invoked after_form_submission hook.\n";

// Verify saved config in database.
$config = $DB->get_record('local_gradesheet_config', ['courseid' => $course->id]);
if ($config) {
    echo "\n3. Successfully verified saved Gradesheet parameters:\n";
    echo "   • Semester:        {$config->semester}\n";
    echo "   • School Year:     {$config->schoolyear}\n";
    echo "   • Course Number:   {$config->coursenumber}\n";
    echo "   • Descriptive Title:{$config->descriptive}\n";
    echo "   • Course & Year:   {$config->courseandyear}\n";
    echo "   • Schedule:        {$config->schedule}\n";
    echo "   • Units:           {$config->units}\n";
    echo "   • Instructor:      {$config->instructor}\n";
    echo "   • Dept Head:       {$config->department_head}\n";
    echo "   • Registrar:       {$config->registrar}\n";
    echo "   • College Dean:    {$config->college_dean}\n";
    echo "\n✅ SUCCESS: Form submission hook stored parameters seamlessly!\n";
} else {
    echo "\n❌ ERROR: Config record was not saved!\n";
}
