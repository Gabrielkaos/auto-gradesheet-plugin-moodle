<?php
namespace local_gradesheet;

defined('MOODLE_INTERNAL') || die();

/**
 * Event observer for local_gradesheet plugin.
 */
class observer {

    /**
     * Triggered when a new course is created in Moodle.
     *
     * @param \core\event\course_created $event
     */
    public static function course_created(\core\event\course_created $event) {
        $courseid = $event->objectid;
        if ($courseid > 0) {
            \local_gradesheet\helper::ensure_course_defaults($courseid);
        }
    }
}
