<?php
require_once('../../config.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->libdir.'/grade/grade_item.php');

use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use local_gradesheet\helper;
use local_gradesheet\excel_builder;

$courseid = required_param('courseid', PARAM_INT);
$groupid  = optional_param('group', 0, PARAM_INT);
$course   = get_course($courseid);
require_login($course);
$context  = context_course::instance($courseid);
require_capability('local/gradesheet:manage', $context);

if ($groupid > 0 && !helper::check_group_access($context, $groupid)) {
    redirect(
        new moodle_url('/local/gradesheet/index.php', ['courseid' => $courseid]),
        'You do not have permission to access the requested group.',
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
    if (file_exists($CFG->libdir . '/phpspreadsheet/vendor/autoload.php')) {
        require_once($CFG->libdir . '/phpspreadsheet/vendor/autoload.php');
    }
}

if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
    redirect(
        new moodle_url('/local/gradesheet/index.php', ['courseid' => $courseid]),
        'Excel export requires PhpSpreadsheet which is not available on this Moodle installation.',
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$weightvalid = helper::validate_weight_sum($courseid);
if (!$weightvalid['valid']) {
    redirect(
        new moodle_url('/local/gradesheet/index.php', ['courseid' => $courseid]),
        'Cannot export: Category weights must sum to exactly 100% (currently ' . $weightvalid['total'] . '%). Please fix this in Settings.',
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$built = excel_builder::build($courseid, $groupid);
if ($built === null) {
    redirect(
        new moodle_url('/local/gradesheet/index.php', ['courseid' => $courseid]),
        $groupid > 0 ? 'Cannot export Excel: this section has no students.' : 'Cannot export Excel: No students are enrolled in this course.',
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

$filename = $built['filename'];
while (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($built['spreadsheet']);
$writer->save('php://output');
exit;
