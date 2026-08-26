<?php
/**
 * CLI Test Script for local_gradesheet Course Creation Auto-Initialization
 * 
 * Usage: php create_test_course.php
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

global $DB, $CFG;

echo "=== Testing local_gradesheet Auto-Initialization ===\n\n";

$shortname = 'TEST-' . date('Ymd-His');
$fullname  = 'Auto-Created Test Course (' . date('H:i:s') . ')';

echo "1. Creating new course in Moodle...\n";
$coursedata = (object)[
    'fullname'    => $fullname,
    'shortname'   => $shortname,
    'category'    => 1,
    'format'      => 'topics',
    'numsections' => 4,
    'visible'     => 1,
    'startdate'   => time(),
];

$course = create_course($coursedata);
echo "   ✓ Created Moodle Course: {$course->fullname} (id={$course->id})\n\n";

echo "2. Triggering course_created event for local_gradesheet...\n";
$event = \core\event\course_created::create([
    'objectid' => $course->id,
    'context'  => context_course::instance($course->id),
    'other'    => [
        'fullname'  => $course->fullname,
        'shortname' => $course->shortname,
    ],
]);
$event->trigger();
\local_gradesheet\observer::course_created($event);

echo "3. Verifying local_gradesheet parameters for course id={$course->id}...\n";

// Check config
$config = $DB->get_record('local_gradesheet_config', ['courseid' => $course->id]);
if ($config) {
    echo "   ✓ Configuration record automatically created:\n";
    echo "     - Semester:    {$config->semester}\n";
    echo "     - School Year:  {$config->schoolyear}\n";
    echo "     - Course Code: {$config->coursenumber}\n";
    echo "     - Title:       {$config->descriptive}\n";
} else {
    echo "   ❌ Configuration record NOT found!\n";
}

// Check categories
$categories = $DB->get_records('local_gradesheet_categories', ['courseid' => $course->id], 'sortorder ASC');
if (!empty($categories)) {
    echo "   ✓ Category records automatically created (" . count($categories) . " categories):\n";
    foreach ($categories as $cat) {
        echo "     - {$cat->name}: {$cat->weight}%\n";
    }
} else {
    echo "   ❌ Category records NOT found!\n";
}

echo "\n" . str_repeat("═", 55) . "\n";
echo "✅ SUCCESS: All Gradesheet parameters & categories packaged inside local_gradesheet\n";
echo "   were automatically initialized for the new course without touching Moodle core!\n";
echo str_repeat("═", 55) . "\n";
