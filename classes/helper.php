<?php
namespace local_gradesheet;

defined('MOODLE_INTERNAL') || die();

/**
 * Helper utility for local_gradesheet plugin.
 */
class helper {

    /**
     * Ensures default configuration and categories exist for a course.
     *
     * @param int $courseid
     * @return \stdClass The course config object.
     */
    public static function ensure_course_defaults(int $courseid): \stdClass {
        global $DB;

        if ($courseid <= 0) {
            return (object)[];
        }

        // Fetch course details if available for smart defaults.
        $course = $DB->get_record('course', ['id' => $courseid]);

        // 1. Ensure local_gradesheet_config record exists.
        $config = $DB->get_record('local_gradesheet_config', ['courseid' => $courseid]);
        if (!$config) {
            $configdata = (object)[
                'courseid'        => $courseid,
                'quizweight'      => 50.00,
                'examweight'      => 50.00,
                'activityweight'  => 0.00,
                'timecreated'     => time(),
                'timemodified'    => time(),
                'semester'        => 'First Semester',
                'schoolyear'      => date('Y') . '-' . (date('Y') + 1),
                'coursenumber'    => $course ? $course->shortname : 'CSS 101',
                'descriptive'     => $course ? $course->fullname : 'Course Title',
                'courseandyear'   => 'BSCS 1A',
                'schedule'        => 'TBA',
                'units'           => '3',
                'instructor'      => 'INSTRUCTOR NAME',
                'department_head' => 'DEPARTMENT HEAD',
                'registrar'       => 'REGISTRAR NAME',
                'college_dean'    => 'COLLEGE DEAN',
            ];
            $configdata->id = $DB->insert_record('local_gradesheet_config', $configdata);
            $config = $configdata;
        }

        // 2. Ensure default categories exist for this course if none exist.
        $existingcats = $DB->count_records('local_gradesheet_categories', ['courseid' => $courseid]);
        if ($existingcats == 0) {
            $default_categories = [
                ['name' => 'Quizzes',    'weight' => 30.00, 'sortorder' => 0],
                ['name' => 'Activities', 'weight' => 30.00, 'sortorder' => 1],
                ['name' => 'Exams',      'weight' => 40.00, 'sortorder' => 2],
            ];
            foreach ($default_categories as $cat) {
                $catrecord = (object)[
                    'courseid'  => $courseid,
                    'name'      => $cat['name'],
                    'weight'    => $cat['weight'],
                    'sortorder' => $cat['sortorder'],
                ];
                $DB->insert_record('local_gradesheet_categories', $catrecord);
            }
        }

        return $config;
    }
}
