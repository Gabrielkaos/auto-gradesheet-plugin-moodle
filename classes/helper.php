<?php
namespace local_gradesheet;

defined('MOODLE_INTERNAL') || die();

class helper {

    /** Valid faculty-settable statuses. '' means active/normal. */
    const VALID_STATUSES = ['', 'inc', 'dropped', 'wp', 'ip'];

    public static function status_label(string $status): string {
        $labels = [
            'inc'     => 'Incomplete',
            'dropped' => 'Dropped',
            'wp'      => 'Withdrawn w/ permission',
            'ip'      => 'In Progress',
        ];
        return $labels[$status] ?? '';
    }

    public static function status_options(): array {
        return [
            ''        => 'Active',
            'inc'     => 'Incomplete',
            'dropped' => 'Dropped',
            'wp'      => 'Withdrawn w/ permission',
            'ip'      => 'In Progress',
        ];
    }

    /**
     * Returns [userid => status] for every student in the course who has
     * a non-active status override. Students not present here are 'active'.
     */
    public static function get_status_map(int $courseid): array {
        global $DB;
        $records = $DB->get_records('local_gradesheet_status', ['courseid' => $courseid]);
        $map = [];
        foreach ($records as $r) {
            if (!empty($r->status)) {
                $map[$r->userid] = $r->status;
            }
        }
        return $map;
    }

    public static function get_student_status(int $courseid, int $userid): string {
        global $DB;
        $record = $DB->get_record('local_gradesheet_status', ['courseid' => $courseid, 'userid' => $userid]);
        return $record ? $record->status : '';
    }

    /**
     * Faculty sets (or clears, via '') a student's status override for a course.
     */
    public static function set_student_status(int $courseid, int $userid, string $status): void {
        global $DB, $USER;

        if (!in_array($status, self::VALID_STATUSES, true)) {
            return;
        }

        $existing = $DB->get_record('local_gradesheet_status', ['courseid' => $courseid, 'userid' => $userid]);

        if ($status === '') {
            if ($existing) {
                $DB->delete_records('local_gradesheet_status', ['id' => $existing->id]);
            }
            return;
        }

        if ($existing) {
            $existing->status       = $status;
            $existing->usermodified = $USER->id;
            $existing->timemodified = time();
            $DB->update_record('local_gradesheet_status', $existing);
        } else {
            $DB->insert_record('local_gradesheet_status', (object)[
                'courseid'     => $courseid,
                'userid'       => $userid,
                'status'       => $status,
                'note'         => null,
                'usermodified' => $USER->id,
                'timemodified' => time(),
            ]);
        }
    }

    public static function ensure_course_defaults(int $courseid): \stdClass {
        global $DB;

        if ($courseid <= 0) {
            return (object)[];
        }

        $course = $DB->get_record('course', ['id' => $courseid]);

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

    public static function transmute_equiv($grade) {
        if ($grade == 0)    return '-';
        if ($grade == 100)  return '1.0';
        if ($grade >= 94)   return number_format(1.1 + (99 - $grade) * 0.1, 1);
        if ($grade >= 89)   return number_format(1.6 + (93 - $grade) * 0.1, 1);
        if ($grade >= 84)   return number_format(2.1 + (88 - $grade) * 0.1, 1);
        if ($grade >= 79)   return number_format(2.6 + (83 - $grade) * 0.1, 1);
        if ($grade >= 75)   return number_format(3.1 + (78 - $grade) * 0.1, 1);
        if ($grade >= 69)   return number_format(3.6 + (74 - $grade) * 0.1, 1);
        return '5.0';
    }

    public static function load_course_config(int $courseid): array {
        global $DB;

        $course     = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $coursename = format_string($course->fullname);
        $config     = $DB->get_record('local_gradesheet_config', ['courseid' => $courseid]);

        return [
            'course'        => $course,
            'coursename'    => $coursename,
            'config'        => $config,
            'semester'      => ($config && !empty($config->semester))        ? $config->semester        : 'Second Semester',
            'schoolyear'    => ($config && !empty($config->schoolyear))      ? $config->schoolyear      : '2025-2026',
            'coursenumber'  => ($config && !empty($config->coursenumber))    ? $config->coursenumber    : $coursename,
            'descriptive'   => ($config && !empty($config->descriptive))     ? $config->descriptive     : $coursename,
            'courseandyear' => ($config && !empty($config->courseandyear))   ? $config->courseandyear   : '',
            'schedule'      => ($config && !empty($config->schedule))        ? $config->schedule        : '',
            'units'         => ($config && !empty($config->units))           ? $config->units           : '3',
            'instructor'    => ($config && !empty($config->instructor))      ? $config->instructor      : '',
            'depthead'      => ($config && !empty($config->department_head)) ? $config->department_head : '',
            'registrar'     => ($config && !empty($config->registrar))       ? $config->registrar       : '',
            'collegedean'   => ($config && !empty($config->college_dean))    ? $config->college_dean    : '',
            'midweight'     => $config ? floatval($config->quizweight) / 100 : 0.50,
            'finweight'     => $config ? floatval($config->examweight) / 100 : 0.50,
            'mpct'          => $config ? $config->quizweight  : 50,
            'fpct'          => $config ? $config->examweight  : 50,
        ];
    }

    public static function get_non_teaching_students(\context_course $context): array {
        $students = get_enrolled_users($context, '', 0, 'u.*', 'u.lastname ASC, u.firstname ASC');
        $filtered = [];

        foreach ($students as $student) {
            if (is_siteadmin($student->id)) {
                continue;
            }
            $roles     = get_user_roles($context, $student->id);
            $isteacher = false;
            foreach ($roles as $role) {
                if ($role->shortname === 'teacher' || $role->shortname === 'editingteacher') {
                    $isteacher = true;
                    break;
                }
            }
            if (!$isteacher) {
                $filtered[] = $student;
            }
        }

        return $filtered;
    }

    public static function compute_student_grades(int $courseid, int $studentid, float $midweight, float $finweight): array {
        global $DB;

        $gitems = $DB->get_records_select(
            'grade_items',
            'courseid = ? AND itemtype != ? AND itemname IS NOT NULL',
            [$courseid, 'course']
        );

        $categories = $DB->get_records('local_gradesheet_categories',
            ['courseid' => $courseid], 'sortorder ASC');

        $cattotals = [];
        foreach ($categories as $cat) {
            $cattotals[$cat->id] = ['total' => 0, 'count' => 0, 'weight' => $cat->weight, 'name' => $cat->name];
        }

        $midTotal = 0; $midCount = 0;
        $finTotal = 0; $finCount = 0;

        foreach ($gitems as $gitem) {
            $ggrade = $DB->get_record('grade_grades', [
                'itemid' => $gitem->id,
                'userid' => $studentid
            ]);

            $val = ($ggrade && $ggrade->finalgrade !== null)
                ? floatval($ggrade->finalgrade) : 0;

            $max = floatval($gitem->grademax);
            if ($max > 0 && $max != 100) {
                $val = ($val / $max) * 100;
            }

            $map    = $DB->get_record('local_gradesheet_itemmap', [
                'courseid'    => $courseid,
                'gradeitemid' => $gitem->id,
            ]);
            $period = $map ? $map->period     : 'finals';
            $catid  = $map ? $map->categoryid : 0;

            if ($catid && isset($cattotals[$catid])) {
                $cattotals[$catid]['total'] += $val;
                $cattotals[$catid]['count']++;
            }

            if ($period === 'midterm') {
                $midTotal += $val; $midCount++;
            } else {
                $finTotal += $val; $finCount++;
            }
        }

        $weightedFinal = 0;
        $totalWeight   = 0;
        foreach ($cattotals as $data) {
            if ($data['count'] > 0) {
                $catAvg        = $data['total'] / $data['count'];
                $weightedFinal += ($catAvg * ($data['weight'] / 100));
                $totalWeight   += $data['weight'];
            }
        }

        $midAvg = $midCount > 0 ? $midTotal / $midCount : 0;
        $finAvg = $finCount > 0 ? $finTotal / $finCount : 0;

        if ($totalWeight == 0) {
            $weightedFinal = ($midAvg * $midweight) + ($finAvg * $finweight);
        }

        return [
            'midterm'    => $midAvg,
            'finals'     => $finAvg,
            'average'    => $weightedFinal,
            'cattotals'  => $cattotals,
            'transmuted' => self::transmute_equiv($weightedFinal),
            'remarks'    => $weightedFinal >= 75 ? 'PASSED' : 'FAILED',
        ];
    }

    /**
     * Validate that category weights sum to 100% and at least one category exists.
     * Returns ['valid' => bool, 'total' => float, 'count' => int].
     */
    public static function validate_weight_sum(int $courseid): array {
        global $DB;
        $categories = $DB->get_records('local_gradesheet_categories', ['courseid' => $courseid]);
        $total = 0;
        foreach ($categories as $cat) {
            $total += $cat->weight;
        }
        return [
            'valid' => count($categories) > 0 && abs($total - 100) < 0.01,
            'total' => $total,
            'count' => count($categories),
        ];
    }

    public static function get_rating_legend(): array {
        return [
            ['100',   '1.0',     'Outstanding'],
            ['94-90', '1.1-1.5', 'Excellent'],
            ['89-85', '1.6-2.0', 'Very Good'],
            ['84-80', '2.1-2.5', 'Good'],
            ['79-75', '2.6-3.0', 'Fair'],
            ['74-70', '3.1-3.5', 'Conditional'],
            ['69-55', '3.6-5.0', 'Failed'],
            ['INC',   'INC',     'Incomplete'],
            ['Dr',    'Dr',      'Dropped'],
            ['WP',    'WP',      'Withdrawn w/ permission'],
            ['IP',    'IP',      'In Progress'],
        ];
    }
}