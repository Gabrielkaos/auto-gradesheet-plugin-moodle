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

    /**
     * Transmutes a raw percentage score (0-100) into an equivalent grade.
     *
     * If the course has custom transmutation brackets defined, those are used
     * (a straight bracket match on the stored 'equivalent' string — no
     * interpolation, since custom equivalents aren't guaranteed to be numeric,
     * e.g. letter grades). Otherwise falls back to the default ESSU scale.
     *
     * @param float|int|null|string $grade Raw score, or null/'' for "no grade".
     * @param int|null $courseid Course to check for a custom scale. Null = always default.
     * @return string Equivalent (e.g. '1.0'), '5.0' for failing, or '-' if no grade/no match.
     */
    public static function transmute_equiv($grade, ?int $courseid = null): string {
        if ($grade === null || $grade === '' || !is_numeric($grade)) {
            return '-';
        }

        $grade = floatval($grade);

        if ($courseid) {
            $custom = self::get_custom_transmute_rows($courseid);
            if (!empty($custom)) {
                foreach ($custom as $row) {
                    if ($grade >= $row->minscore && $grade <= $row->maxscore) {
                        return $row->equivalent;
                    }
                }
                // Custom scale is active but no bracket covers this score.
                return '-';
            }
        }

        if ($grade < 55) {
            return '5.0';
        }

        // [min, max, equiv_at_min (worst), equiv_at_max (best)]
        $bands = [
            [100, 100, 1.0, 1.0],   // perfect score
            [90,  99,  1.5, 1.1],   // 90-99  -> 1.1-1.5
            [85,  89,  2.0, 1.6],   // 85-89  -> 1.6-2.0
            [80,  84,  2.5, 2.1],   // 80-84  -> 2.1-2.5
            [75,  79,  3.0, 2.6],   // 75-79  -> 2.6-3.0
            [70,  74,  3.5, 3.1],   // 70-74  -> 3.1-3.5
            [55,  69,  5.0, 3.6],   // 55-69  -> 3.6-5.0
        ];

        foreach ($bands as [$min, $max, $eqMin, $eqMax]) {
            if ($grade >= $min && $grade <= $max) {
                if ($max == $min) {
                    return number_format($eqMax, 1);
                }
                $equiv = $eqMax - (($max - $grade) / ($max - $min)) * ($eqMax - $eqMin);
                return number_format(round($equiv, 1), 1);
            }
        }

        return '5.0';
    }

    /**
     * Returns custom transmutation brackets for a course, ordered high-to-low.
     * Cached per request so a full roster computation hits the table once.
     */
    private static array $transmutecache = [];

    private static function get_custom_transmute_rows(int $courseid): array {
        global $DB;
        if (!isset(self::$transmutecache[$courseid])) {
            self::$transmutecache[$courseid] = $DB->get_records(
                'local_gradesheet_transmute', ['courseid' => $courseid], 'minscore DESC'
            );
        }
        return self::$transmutecache[$courseid];
    }

    /**
     * Whether a raw score counts as passing for this course.
     *
     * Under a custom scale, this is driven entirely by the matched bracket's
     * 'ispassing' flag — not a hardcoded percentage. A score that falls
     * outside every custom bracket is treated as failing (safer default than
     * silently passing an unclassified score).
     */
    public static function is_passing(float $grade, ?int $courseid = null): bool {
        if ($courseid) {
            $custom = self::get_custom_transmute_rows($courseid);
            if (!empty($custom)) {
                foreach ($custom as $row) {
                    if ($grade >= $row->minscore && $grade <= $row->maxscore) {
                        return (bool)$row->ispassing;
                    }
                }
                return false; // Custom scale active, no bracket matched.
            }
        }
        return $grade >= 75; // Default ESSU threshold.
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

    /**
     * Course-level mapping sanity counts, used for warnings on the index and
     * settings pages.
     *
     * @param int $courseid Course id.
     * @return array{unmapped:int, midterm:int, finals:int}|null
     *         Counts of grade items not usable in computation (no valid
     *         category mapping) and of items mapped to each period.
     *         Null when the course has no grade items at all.
     */
    public static function get_mapping_warnings(int $courseid): ?array {
        global $DB;

        $gitems = $DB->get_records_select(
            'grade_items',
            'courseid = ? AND itemtype != ? AND itemname IS NOT NULL',
            [$courseid, 'course'],
            '',
            'id'
        );
        if (empty($gitems)) {
            return null;
        }

        $categories = $DB->get_records('local_gradesheet_categories', ['courseid' => $courseid], '', 'id');

        // Re-keyed by gradeitemid — get_records_list indexes rows by their own id.
        $maps = [];
        foreach ($DB->get_records_list('local_gradesheet_itemmap', 'gradeitemid', array_keys($gitems)) as $m) {
            $maps[$m->gradeitemid] = $m;
        }

        $counts = ['unmapped' => 0, 'midterm' => 0, 'finals' => 0];
        foreach (array_keys($gitems) as $itemid) {
            $map = $maps[$itemid] ?? null;
            if (!$map || empty($map->categoryid) || !isset($categories[$map->categoryid])) {
                $counts['unmapped']++;
            } else {
                $counts[$map->period === 'midterm' ? 'midterm' : 'finals']++;
            }
        }

        return $counts;
    }

    /**
     * Computes a student's Midterm/Finals period values and final average.
     *
     * Each period value is the weighted mean of the category averages that
     * have grade items in that period, normalized by their combined weight
     * (plain mean if every participating weight is zero). A period with no
     * mapped items yields null. The final average is (midterm + finals) / 2
     * when both periods have data, otherwise whichever period has data, or
     * null when neither does.
     *
     * @param int $courseid Course id.
     * @param int $studentid Student id.
     * @return array{midterm:?float, finals:?float, average:?float, cattotals:array, transmuted:string, remarks:string}
     */
    public static function compute_student_grades(int $courseid, int $studentid): array {
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
            $cattotals[$cat->id] = [
                'total' => 0, 'count' => 0, 'weight' => $cat->weight, 'name' => $cat->name,
                'midtotal' => 0, 'midcount' => 0, 'fintotal' => 0, 'fincount' => 0,
            ];
        }

        // Items accumulated per period, keyed by category. Items not mapped
        // to an existing category are excluded from computation entirely
        // (surfaced as a warning via get_mapping_warnings()).
        $periodcats = ['midterm' => [], 'finals' => []];

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
            $period = ($map && $map->period === 'midterm') ? 'midterm' : 'finals';
            $catid  = $map ? (int)$map->categoryid : 0;

            if (!$catid || !isset($cattotals[$catid])) {
                continue;
            }

            $cattotals[$catid]['total'] += $val;
            $cattotals[$catid]['count']++;
            if ($period === 'midterm') {
                $cattotals[$catid]['midtotal'] += $val;
                $cattotals[$catid]['midcount']++;
            } else {
                $cattotals[$catid]['fintotal'] += $val;
                $cattotals[$catid]['fincount']++;
            }

            if (!isset($periodcats[$period][$catid])) {
                $periodcats[$period][$catid] = ['total' => 0, 'count' => 0];
            }
            $periodcats[$period][$catid]['total'] += $val;
            $periodcats[$period][$catid]['count']++;
        }

        $periodvals = [];
        foreach ($periodcats as $period => $cats) {
            $sumw   = 0.0;
            $sumwa  = 0.0;
            $sumavg = 0.0;
            $n      = 0;
            foreach ($cats as $catid => $data) {
                $catAvg = $data['total'] / $data['count'];
                $weight = floatval($cattotals[$catid]['weight']);
                $sumw   += $weight;
                $sumwa  += $catAvg * $weight;
                $sumavg += $catAvg;
                $n++;
            }
            if ($n === 0) {
                $periodvals[$period] = null;
            } else if ($sumw > 0) {
                $periodvals[$period] = $sumwa / $sumw;
            } else {
                $periodvals[$period] = $sumavg / $n;
            }
        }

        $mid = $periodvals['midterm'];
        $fin = $periodvals['finals'];

        if ($mid !== null && $fin !== null) {
            $average = ($mid + $fin) / 2;
        } else if ($mid !== null) {
            $average = $mid;
        } else if ($fin !== null) {
            $average = $fin;
        } else {
            $average = null;
        }

        return [
            'midterm'    => $mid,
            'finals'     => $fin,
            'average'    => $average,
            'cattotals'  => $cattotals,
            'transmuted' => self::transmute_equiv($average, $courseid),
            'remarks'    => ($average === null)
                ? ''
                : (self::is_passing($average, $courseid) ? 'PASSED' : 'FAILED'),
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

    /**
     * Returns the rating legend to display. Uses the course's custom scale if
     * one is defined, otherwise the default ESSU scale.
     *
     * @param int|null $courseid Course to check for a custom scale. Null = always default.
     */
    public static function get_rating_legend(?int $courseid = null): array {
        if ($courseid) {
            $custom = self::get_custom_transmute_rows($courseid);
            if (!empty($custom)) {
                $legend = [];
                foreach ($custom as $row) {
                    $range = ($row->minscore == $row->maxscore)
                        ? self::format_score($row->minscore)
                        : self::format_score($row->maxscore) . '-' . self::format_score($row->minscore);
                    $legend[] = [$range, $row->equivalent, $row->descriptor];
                }
                return $legend;
            }
        }

        return [
            ['100',   '1.0',     'Outstanding'],
            ['99-90', '1.1-1.5', 'Excellent'],
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

    /** Trims trailing .00 from a stored numeric score for display, e.g. 90.00 -> 90. */
    private static function format_score($score): string {
        $f = floatval($score);
        return (floor($f) == $f) ? (string)intval($f) : rtrim(rtrim(number_format($f, 2), '0'), '.');
    }
}