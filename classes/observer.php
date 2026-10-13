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

    /**
     * Triggered when a course is deleted in Moodle.
     * Cleans up orphaned records.
     *
     * @param \core\event\course_deleted $event
     */
    public static function course_deleted(\core\event\course_deleted $event) {
        global $DB;
        $courseid = $event->objectid;
        if ($courseid > 0) {
            $DB->delete_records('local_gradesheet_config', ['courseid' => $courseid]);
            $DB->delete_records('local_gradesheet_categories', ['courseid' => $courseid]);
            $DB->delete_records('local_gradesheet_itemmap', ['courseid' => $courseid]);
            $DB->delete_records('local_gradesheet_transmute', ['courseid' => $courseid]);
            $DB->delete_records('local_gradesheet_status', ['courseid' => $courseid]);
            $DB->delete_records('local_gradesheet_groupcfg', ['courseid' => $courseid]);
        }
    }

    /**
     * Triggered when a group is deleted: drop its section overrides.
     *
     * @param \core\event\group_deleted $event
     */
    public static function group_deleted(\core\event\group_deleted $event) {
        global $DB;
        $groupid = $event->objectid;
        if ($groupid > 0) {
            $DB->delete_records('local_gradesheet_groupcfg', ['groupid' => $groupid]);
        }
    }

    /**
     * Triggered when a user is deleted in Moodle.
     * Cleans up orphaned status records.
     *
     * @param \core\event\user_deleted $event
     */
    public static function user_deleted(\core\event\user_deleted $event) {
        global $DB;
        $userid = $event->objectid;
        if ($userid > 0) {
            $DB->delete_records('local_gradesheet_status', ['userid' => $userid]);
        }
    }

    /**
     * Triggered when a new grade item is created in Moodle.
     * Automatically classifies and maps the item to the course's gradesheet category and period.
     *
     * @param \core\event\grade_item_created $event
     */
    public static function grade_item_created(\core\event\grade_item_created $event) {
        $gi = null;
        if (method_exists($event, 'get_grade_item')) {
            try {
                $gi = $event->get_grade_item();
            } catch (\Throwable $t) {
                $gi = null;
            }
        }
        if (!$gi) {
            $gi = $event->get_record_snapshot('grade_items', $event->objectid);
        }
        if ($gi && !empty($gi->courseid)) {
            \local_gradesheet\helper::auto_map_grade_item((int)$gi->courseid, $gi);
        }
    }

    /**
     * Triggered when a grade item is updated in Moodle (renamed, maxgrade changed, etc.).
     * Auto-maps if the item was unmapped or mapped to an invalid category.
     *
     * @param \core\event\grade_item_updated $event
     */
    public static function grade_item_updated(\core\event\grade_item_updated $event) {
        $gi = null;
        if (method_exists($event, 'get_grade_item')) {
            try {
                $gi = $event->get_grade_item();
            } catch (\Throwable $t) {
                $gi = null;
            }
        }
        if (!$gi) {
            $gi = $event->get_record_snapshot('grade_items', $event->objectid);
        }
        if ($gi && !empty($gi->courseid)) {
            \local_gradesheet\helper::auto_map_grade_item((int)$gi->courseid, $gi, false);
        }
    }

    /**
     * Triggered when a course module (quiz, assign, etc.) is created in Moodle.
     * Ensures all grade items for the course are automatically mapped.
     *
     * @param \core\event\course_module_created $event
     */
    public static function course_module_created(\core\event\course_module_created $event) {
        $courseid = $event->courseid ?? 0;
        if ($courseid > 0) {
            \local_gradesheet\helper::auto_map_unmapped_items((int)$courseid);
        }
    }

    /**
     * Triggered when a course module is updated in Moodle.
     *
     * @param \core\event\course_module_updated $event
     */
    public static function course_module_updated(\core\event\course_module_updated $event) {
        $courseid = $event->courseid ?? 0;
        if ($courseid > 0) {
            \local_gradesheet\helper::auto_map_unmapped_items((int)$courseid);
        }
    }
}
