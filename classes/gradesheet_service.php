<?php
namespace local_gradesheet;

defined('MOODLE_INTERNAL') || die();

class gradesheet_service {

    public static function compute_all_grades(int $courseid): array {
        global $DB;

        $cfg    = helper::load_course_config($courseid);
        $ctx    = \context_course::instance($courseid);
        $students = helper::get_non_teaching_students($ctx);

        $categories = $DB->get_records('local_gradesheet_categories',
            ['courseid' => $courseid], 'sortorder ASC');

        $rows      = [];
        $passcount = 0;
        $failcount = 0;

        foreach ($students as $student) {
            $g = helper::compute_student_grades(
                $courseid, $student->id, $cfg['midweight'], $cfg['finweight']
            );

            $remarks = $g['remarks'] === 'PASSED' ? 'Passed' : 'Failed';
            if ($remarks === 'Passed') {
                $passcount++;
            } else {
                $failcount++;
            }

            $rows[] = [
                'idnumber'  => $student->idnumber,
                'name'      => $student->lastname . ', ' . $student->firstname,
                'midterm'   => helper::transmute_equiv($g['midterm']),
                'finals'    => helper::transmute_equiv($g['finals']),
                'average'   => $g['transmuted'],
                'remarks'   => $remarks,
                'cattotals' => $g['cattotals'],
            ];
        }

        $total    = $passcount + $failcount;
        $passrate = $total > 0 ? round(($passcount / $total) * 100, 1) : 0;

        return array_merge($cfg, [
            'context'     => $ctx,
            'categories'  => $categories,
            'rows'        => $rows,
            'passcount'   => $passcount,
            'failcount'   => $failcount,
            'total'       => $total,
            'passrate'    => $passrate,
        ]);
    }
}
