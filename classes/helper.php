<?php
namespace local_gradesheet;

defined('MOODLE_INTERNAL') || die();

class helper {

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

    public static function transmute_equiv($grade, ?int $courseid = null) {
        if ($courseid) {
            $custom = self::get_custom_scale($courseid);
            if (!empty($custom)) {
                if ($grade == 0) {
                    return '-';
                }
                foreach ($custom as $row) {
                    if ($grade >= $row->minscore && $grade <= $row->maxscore) {
                        return $row->equivalent;
                    }
                }
                // Grade doesn't fall in any configured bracket (gap in the scale) —
                // do not guess; flag it so the gap gets noticed and fixed rather than
                // silently showing the wrong equivalent.
                return 'N/A';
            }
        }

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

    /**
     * Fetch a course's custom transmutation scale, highest bracket first.
     * Returns an empty array if the course has no custom scale (i.e. uses the default).
     */
    public static function get_custom_scale(int $courseid): array {
        global $DB;
        return array_values($DB->get_records('local_gradesheet_transmute', ['courseid' => $courseid], 'minscore DESC'));
    }

    public static function has_custom_scale(int $courseid): bool {
        global $DB;
        return $DB->record_exists('local_gradesheet_transmute', ['courseid' => $courseid]);
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

    private static function format_score($score): string {
        return (floor($score) == $score) ? (string) intval($score) : rtrim(rtrim(number_format($score, 2), '0'), '.');
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
            'transmuted' => self::transmute_equiv($weightedFinal, $courseid),
            'remarks'    => $weightedFinal >= 75 ? 'PASSED' : 'FAILED',
        ];
    }

    public static function get_rating_legend(?int $courseid = null): array {
        if ($courseid) {
            $custom = self::get_custom_scale($courseid);
            if (!empty($custom)) {
                $legend = [];
                foreach ($custom as $row) {
                    $range = (abs($row->minscore - $row->maxscore) < 0.001)
                        ? self::format_score($row->maxscore)
                        : self::format_score($row->maxscore) . '-' . self::format_score($row->minscore);
                    $legend[] = [$range, $row->equivalent, $row->descriptor];
                }
                // Non-numeric enrollment statuses aren't part of the numeric scale being
                // customized here, so keep them visible regardless of the course's scale.
                $legend[] = ['INC', 'INC', 'Incomplete'];
                $legend[] = ['Dr',  'Dr',  'Dropped'];
                $legend[] = ['WP',  'WP',  'Withdrawn w/ permission'];
                $legend[] = ['IP',  'IP',  'In Progress'];
                return $legend;
            }
        }

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