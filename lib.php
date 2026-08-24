<?php
defined('MOODLE_INTERNAL') || die();

function local_gradesheet_extend_navigation_course($navigation, $course, $context) {
    if (isloggedin() && !isguestuser()) {
        $url  = new moodle_url('/local/gradesheet/index.php', ['courseid' => $course->id]);
        $node = navigation_node::create(
            'Grade Sheet',
            $url,
            navigation_node::TYPE_CUSTOM,
            null,
            'local_gradesheet',
            new pix_icon('i/grades', '')
        );
        $node->showinflatnavigation = true;
        $navigation->add_node($node);
    }
}

function local_gradesheet_extend_navigation(global_navigation $nav) {
    if (isloggedin() && !isguestuser()) {
        $url  = new moodle_url('/local/gradesheet/index.php');
        $node = $nav->add(
            'Grade Sheet',
            $url,
            navigation_node::TYPE_CUSTOM,
            null,
            'local_gradesheet'
        );
        $node->showinflatnavigation = true;
    }
}

function local_gradesheet_extend_settings_navigation($settingsnav, $context) {
    // Intentionally empty — no settings nav needed
}
