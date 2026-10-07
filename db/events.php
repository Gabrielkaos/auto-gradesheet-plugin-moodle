<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_created',
        'callback'  => '\local_gradesheet\observer::course_created',
    ],
    [
        'eventname' => '\core\event\course_deleted',
        'callback'  => '\local_gradesheet\observer::course_deleted',
    ],
    [
        'eventname' => '\core\event\group_deleted',
        'callback'  => '\local_gradesheet\observer::group_deleted',
    ],
    [
        'eventname' => '\core\event\user_deleted',
        'callback'  => '\local_gradesheet\observer::user_deleted',
    ],
    [
        'eventname' => '\core\event\grade_item_created',
        'callback'  => '\local_gradesheet\observer::grade_item_created',
    ],
    [
        'eventname' => '\core\event\grade_item_updated',
        'callback'  => '\local_gradesheet\observer::grade_item_updated',
    ],
    [
        'eventname' => '\core\event\course_module_created',
        'callback'  => '\local_gradesheet\observer::course_module_created',
    ],
    [
        'eventname' => '\core\event\course_module_updated',
        'callback'  => '\local_gradesheet\observer::course_module_updated',
    ],
];
