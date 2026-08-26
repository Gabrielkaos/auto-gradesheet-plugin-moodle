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

        if (!is_enrolled(\context_course::instance($courseid), $userid)) {
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
            $existing->timemodified = time();
            $DB->update_record('local_gradesheet_status', $existing);
        } else {
            $DB->insert_record('local_gradesheet_status', (object)[
                'courseid'     => $courseid,
                'userid'       => $userid,
                'status'       => $status,
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
     * Transmutes a raw percentage score (0-100) into its displayed value.
     *
     * If the course has custom transmutation brackets defined, the raw score
     * itself is returned (formatted to 2 decimals); the matched bracket only
     * decides passing/failing via its 'ispassing' flag (see is_passing()).
     * A score covered by no bracket returns '-'. Without a custom scale, the
     * default ESSU scale applies ('1.0'-'5.0', with scores below 55 as '5.0').
     *
     * @param float|int|null|string $grade Raw score, or null/'' for "no grade".
     * @param int|null $courseid Course to check for a custom scale. Null = always default.
     * @return string Display value: raw score under a custom scale, ESSU equivalent otherwise, or '-' for no grade/no match.
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
                    if ($grade >= $row->minscore) {
                        return number_format($grade, 2);
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
            if ($grade >= $min) {
                if ($max == $min) {
                    return number_format($eqMax, 1);
                }
                $calcGrade = min($grade, $max);
                $equiv = $eqMax - (($max - $calcGrade) / ($max - $min)) * ($eqMax - $eqMin);
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

    public static function get_custom_transmute_rows(int $courseid): array {
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
                    if ($grade >= $row->minscore) {
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
        ];
    }

    public static function get_non_teaching_students(\context_course $context): array {
        $all = get_enrolled_users($context, '', 0, 'u.*', 'u.lastname ASC, u.firstname ASC');
        $teachers = get_enrolled_users($context, 'local/gradesheet:manage', 0, 'u.id');

        $filtered = [];
        foreach ($all as $student) {
            if (is_siteadmin($student->id)) {
                continue;
            }
            if (!isset($teachers[$student->id])) {
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

    private static $course_grade_data = [];

    private static function prefetch_course_grades(int $courseid): void {
        global $DB;
        if (isset(self::$course_grade_data[$courseid])) {
            return;
        }

        $gitems = $DB->get_records_select(
            'grade_items',
            'courseid = ? AND itemtype != ? AND itemname IS NOT NULL',
            [$courseid, 'course']
        );
        $categories = $DB->get_records('local_gradesheet_categories',
            ['courseid' => $courseid], 'sortorder ASC');

        $maps = [];
        $grades = [];
        if (!empty($gitems)) {
            $itemids = array_keys($gitems);
            list($insql, $inparams) = $DB->get_in_or_equal($itemids);
            
            $maprecs = $DB->get_records_select('local_gradesheet_itemmap', 
                "courseid = ? AND gradeitemid $insql", array_merge([$courseid], $inparams));
            foreach ($maprecs as $m) {
                $maps[$m->gradeitemid] = $m;
            }

            $graderecs = $DB->get_records_select('grade_grades', 
                "itemid $insql", $inparams);
            foreach ($graderecs as $g) {
                $grades[$g->itemid][$g->userid] = $g;
            }
        }

        self::$course_grade_data[$courseid] = [
            'gitems' => $gitems,
            'categories' => $categories,
            'maps' => $maps,
            'grades' => $grades,
        ];
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
        self::prefetch_course_grades($courseid);
        $data = self::$course_grade_data[$courseid];
        
        $gitems     = $data['gitems'];
        $categories = $data['categories'];
        $maps       = $data['maps'];
        $grades     = $data['grades'];

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
            $gi = new \grade_item((array)$gitem, false);
            if ($gi->is_hidden()) {
                continue;
            }

            $ggrade = $grades[$gitem->id][$studentid] ?? null;
            if ($ggrade) {
                $gg = new \grade_grade((array)$ggrade, false);
                if ($gg->is_hidden() || $gg->is_excluded()) {
                    continue;
                }
            }

            $val = ($ggrade && $ggrade->finalgrade !== null)
                ? floatval($ggrade->finalgrade) : 0;

            $max = floatval($gitem->grademax);
            if ($max > 0 && $max != 100) {
                $val = ($val / $max) * 100;
            }

            $map    = $maps[$gitem->id] ?? null;
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
                $catAvg = $data['count'] > 0 ? $data['total'] / $data['count'] : 0;
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
            'valid' => count($categories) > 0 && abs($total - 100) <= 0.01,
            'total' => round($total, 2),
            'count' => count($categories)
        ];
    }

    /**
     * Analyzes the custom scale for common mistakes like overlaps, gaps, and missing bottom brackets.
     * Returns an array of warning strings.
     */
    public static function validate_custom_scale(int $courseid): array {
        $custom = self::get_custom_transmute_rows($courseid);
        if (empty($custom)) {
            return [];
        }

        $warnings = [];
        $custom_values = array_values($custom);
        $labels = array_map([self::class, 'bracket_label'], $custom_values);
        
        // Check for missing bottom bracket
        $lowest_bracket = end($custom_values);
        if ($lowest_bracket && $lowest_bracket->minscore > 0) {
            $warnings[] = "The lowest bracket starts at <strong>{$lowest_bracket->minscore}</strong>. Students scoring below {$lowest_bracket->minscore} will not receive a proper equivalent grade. Consider adding a bracket starting at 0.";
        }

        // Check for overlaps and gaps
        for ($i = 0; $i < count($custom_values) - 1; $i++) {
            $upper = $custom_values[$i];
            $lower = $custom_values[$i+1];
            
            if ($upper->minscore < $lower->maxscore) {
                $warnings[] = "Overlap detected: bracket '{$labels[$i+1]}' ends at <strong>{$lower->maxscore}</strong>, but bracket '{$labels[$i]}' starts at <strong>{$upper->minscore}</strong>. Scores in the overlapping region will be assigned to '{$labels[$i]}'.";
            } elseif ($upper->minscore == $lower->maxscore) {
                $warnings[] = "Boundary overlap detected at score <strong>{$upper->minscore}</strong> between brackets '{$labels[$i+1]}' and '{$labels[$i]}'. Scores exactly at {$upper->minscore} will be assigned to '{$labels[$i]}'.";
            } elseif (($upper->minscore - $lower->maxscore) > 1.01) {
                // If the gap is more than 1 (allowing for integer boundaries like 89 to 90)
                $warnings[] = "Gap detected: bracket '{$labels[$i+1]}' ends at <strong>{$lower->maxscore}</strong>, but the next bracket '{$labels[$i]}' doesn't start until <strong>{$upper->minscore}</strong>. Scores in this gap will automatically fall into '{$labels[$i+1]}'.";
            }
        }
        
        return $warnings;
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

    /** Label for a scale bracket: its descriptor, or its min-max range when no descriptor is set. */
    private static function bracket_label(\stdClass $row): string {
        $desc = trim((string)$row->descriptor);
        return $desc !== '' ? s($desc)
            : self::format_score($row->minscore) . '-' . self::format_score($row->maxscore);
    }

    /**
     * Renders a consistent alert banner across the gradesheet UI.
     */
    public static function render_alert(string $message, string $type = 'info', string $icon = '', string $action_html = ''): string {
        $html = '<div class="alert alert-' . htmlspecialchars($type) . ' d-flex align-items-center gs-alert" role="alert">';
        if ($icon !== '') {
            $html .= '<span class="gs-alert-icon">' . $icon . '</span>';
        }
        $html .= '<div class="w-100">' . $message . '</div>';
        if ($action_html !== '') {
            $html .= '<div class="ml-auto">' . $action_html . '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Renders standard table action buttons (edit / delete).
     */
    public static function render_table_actions(string $edit_url, string $delete_action, string $sesskey, string $delete_label = 'Delete', bool $delete_disabled = false, string $extra_hidden = ''): string {
        $html = '<a href="' . htmlspecialchars($edit_url) . '" class="btn btn-warning btn-sm me-2">Edit</a>';
        if ($delete_disabled) {
            $html .= '<button type="button" class="btn btn-danger btn-sm disabled" tabindex="-1">' . htmlspecialchars($delete_label) . '</button>';
        } else {
            $html .= '<form method="post" class="d-inline m-0">';
            $html .= '<input type="hidden" name="action" value="' . htmlspecialchars($delete_action) . '">';
            $html .= '<input type="hidden" name="sesskey" value="' . htmlspecialchars($sesskey) . '">';
            $html .= $extra_hidden;
            $html .= '<button type="submit" class="btn btn-danger btn-sm">' . htmlspecialchars($delete_label) . '</button>';
            $html .= '</form>';
        }
        return $html;
    }
}