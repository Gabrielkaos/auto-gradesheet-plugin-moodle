<?php
namespace local_gradesheet;

defined('MOODLE_INTERNAL') || die();

global $CFG;
if (!empty($CFG->libdir) && file_exists($CFG->libdir . '/gradelib.php')) {
    require_once($CFG->libdir . '/gradelib.php');
}
if (!empty($CFG->libdir) && file_exists($CFG->libdir . '/grade/grade_item.php')) {
    require_once($CFG->libdir . '/grade/grade_item.php');
}

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
                'coursenumber'    => $course ? mb_substr($course->shortname, 0, 50) : 'CSS 101',
                'descriptive'     => $course ? mb_substr($course->fullname, 0, 100) : 'Course Title',
                'courseandyear'   => 'BSCS 1A',
                'schedule'        => 'TBA',
                'units'           => '3',
                'instructor'      => '',   // blank = auto-detect from roles
                'department_head' => '',
                'registrar'       => '',
                'college_dean'    => '',
                'missingaszero'   => 0,
                'includehidden'   => 1,
                'midtermweight'   => 50,
                'roundaverage'    => 0,
                'transmutemode'   => 'essu',
                'formula'         => '',
                'formulamin'      => null,
                'formulamax'      => null,
                'formuladecimals' => 1,
                'passmark'        => 75,
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
            self::auto_map_unmapped_items($courseid);
        }

        return $config;
    }

    /**
     * Transmutes a raw percentage score (0-100) into its displayed value.
     *
     * If the course has custom transmutation brackets defined, the matched
     * bracket's 'equivalent' value is returned (e.g. '1.25' or 'A'). When the
     * bracket has no equivalent, the raw score itself is returned formatted to
     * 2 decimals. The bracket also decides passing/failing via 'ispassing'
     * (see is_passing()). A score covered by no bracket returns '-'. Without a
     * custom scale, the default ESSU scale applies ('1.0'-'5.0', with scores
     * below 55 as '5.0').
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
            // Formula mode: percentage -> transmuted value by the faculty's
            // own expression. Brackets are then only the adjectival legend.
            $fs = self::get_formula_settings($courseid);
            if ($fs['mode'] === 'formula') {
                $v = self::apply_formula($grade, $fs);
                return ($v === null) ? '-' : number_format($v, $fs['decimals']);
            }

            $custom = self::get_custom_transmute_rows($courseid);
            if (!empty($custom)) {
                foreach ($custom as $row) {
                    if ($grade >= $row->minscore && $grade <= $row->maxscore) {
                        $equiv = trim((string)$row->equivalent);
                        return $equiv !== '' ? $equiv : number_format($grade, 2);
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

    /** Per-request cache of formula settings, keyed by course id. */
    private static $formulacache = [];

    /** Built-in presets offered on the settings page. */
    public static function formula_presets(): array {
        return [
            'essu_linear' => [
                'label'    => 'ESSU 1.0-5.0, linear (100 -> 1.0, 75 -> 3.0, 50 -> 5.0)',
                'formula'  => '1 + (100 - P) * 0.08',
                'min'      => '1', 'max' => '5', 'decimals' => 1, 'passmark' => '75',
            ],
            'base50_cap95' => [
                'label'    => 'Base-50 percentage, capped at 95 (0 -> 50, 50 -> 75, 90+ -> 95)',
                'formula'  => '50 + P / 2',
                'min'      => '', 'max' => '95', 'decimals' => 0, 'passmark' => '50',
            ],
            'raw_cap95' => [
                'label'    => 'Raw percentage, capped at 95',
                'formula'  => 'P',
                'min'      => '', 'max' => '95', 'decimals' => 0, 'passmark' => '75',
            ],
        ];
    }

    /**
     * Transmutation settings for a course.
     *
     * @return array{mode:string, formula:string, min:?float, max:?float, decimals:int, passmark:float}
     */
    public static function get_formula_settings(int $courseid): array {
        global $DB;
        if (!isset(self::$formulacache[$courseid])) {
            $config = $DB->get_record('local_gradesheet_config', ['courseid' => $courseid]);
            $mode = ($config && isset($config->transmutemode)) ? (string)$config->transmutemode : 'essu';
            $formula = ($config && isset($config->formula)) ? trim((string)$config->formula) : '';
            if ($mode === 'formula' && $formula === '') {
                $mode = 'essu'; // Nothing to evaluate: fall back safely.
            }
            $dec = ($config && isset($config->formuladecimals)) ? (int)$config->formuladecimals : 1;
            self::$formulacache[$courseid] = [
                'mode'     => $mode,
                'formula'  => $formula,
                'min'      => ($config && isset($config->formulamin) && $config->formulamin !== null && $config->formulamin !== '') ? floatval($config->formulamin) : null,
                'max'      => ($config && isset($config->formulamax) && $config->formulamax !== null && $config->formulamax !== '') ? floatval($config->formulamax) : null,
                'decimals' => max(0, min(2, $dec)),
                'passmark' => ($config && isset($config->passmark)) ? floatval($config->passmark) : 75.0,
            ];
        }
        return self::$formulacache[$courseid];
    }

    /**
     * Evaluates the course formula at P = $grade, applies the min/max clamp
     * and the configured rounding. Null when the formula cannot be evaluated.
     */
    public static function apply_formula(float $grade, array $fs): ?float {
        try {
            $v = formula::evaluate($fs['formula'], $grade);
        } catch (\Throwable $e) {
            return null;
        }
        if ($fs['max'] !== null && $v > $fs['max']) {
            $v = $fs['max'];
        }
        if ($fs['min'] !== null && $v < $fs['min']) {
            $v = $fs['min'];
        }
        return round($v, $fs['decimals']);
    }

    /**
     * Adjectival rating (descriptor) for a raw percentage, looked up in the
     * course's bracket table or, without one, the default ESSU legend. This is
     * what the brackets are for in formula mode.
     */
    public static function adjectival_rating($grade, ?int $courseid = null): string {
        if ($grade === null || $grade === '' || !is_numeric($grade)) {
            return '';
        }
        $grade = floatval($grade);
        if ($courseid) {
            $custom = self::get_custom_transmute_rows($courseid);
            if (!empty($custom)) {
                foreach ($custom as $row) {
                    if ($grade >= $row->minscore && $grade <= $row->maxscore) {
                        return (string)$row->descriptor;
                    }
                }
                return '';
            }
        }
        // Default ESSU legend, by raw percentage.
        $bands = [[100, 'Outstanding'], [90, 'Excellent'], [85, 'Very Good'], [80, 'Good'],
                  [75, 'Fair'], [70, 'Conditional'], [0, 'Failed']];
        foreach ($bands as [$min, $label]) {
            if ($grade >= $min) {
                return $label;
            }
        }
        return 'Failed';
    }

    /**
     * Whether the printed legend should carry an "Equivalent Rating" column.
     * True in formula mode (equivalents are computed from the formula), true
     * for the default ESSU scale, and true for legacy custom brackets only when
     * at least one bracket has an equivalent filled in.
     */
    public static function legend_has_equivalent(int $courseid): bool {
        if (self::get_formula_settings($courseid)['mode'] === 'formula') {
            return true;
        }
        $custom = self::get_custom_transmute_rows($courseid);
        if (empty($custom)) {
            return true;
        }
        foreach ($custom as $row) {
            if (trim((string)$row->equivalent) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns custom transmutation brackets for a course, ordered high-to-low.
     * Cached per request so a full roster computation hits the table once.
     */
    private static $transmutecache = [];

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
            $fs = self::get_formula_settings($courseid);
            if ($fs['mode'] === 'formula') {
                return $grade >= $fs['passmark'];
            }
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

    /** Legacy placeholder values that mean "not set" (pre-1.10 defaults). */
    const SIGNATORY_PLACEHOLDERS = ['INSTRUCTOR NAME', 'DEPARTMENT HEAD', 'REGISTRAR NAME', 'COLLEGE DEAN'];

    /** Signatory key => [config column, admin-setting name, default role shortname, role display name]. */
    public static function signatory_roles(): array {
        return [
            'instructor'      => ['instructor',      'role_instructor',     'editingteacher', 'Instructor'],
            'department_head' => ['department_head', 'role_departmenthead', 'departmenthead', 'Department Head'],
            'registrar'       => ['registrar',       'role_registrar',      'registrar',      'Registrar'],
            'college_dean'    => ['college_dean',    'role_collegedean',    'collegedean',    'College Dean'],
        ];
    }

    /** Role shortname configured for a signatory (admin setting, with default). */
    public static function signatory_role_shortname(string $key): string {
        $def = self::signatory_roles()[$key] ?? null;
        if (!$def) {
            return '';
        }
        $v = '';
        if (function_exists('get_config')) {
            $v = (string)get_config('local_gradesheet', $def[1]);
        }
        $v = trim($v);
        return $v !== '' ? $v : $def[2];
    }

    /**
     * Creates the Department Head / Registrar / College Dean roles if the site
     * does not have them yet, assignable at category and system level. Called
     * on install and upgrade so auto-detection works out of the box.
     */
    public static function ensure_signatory_roles(): void {
        global $DB;
        if (!function_exists('create_role')) {
            return;
        }
        $wanted = [
            'departmenthead' => ['Department Head', 'Signs grade sheets as Department Head. Assign at the department/program category.'],
            'collegedean'    => ['College Dean',    'Signs grade sheets as College Dean. Assign at the college category.'],
            'registrar'      => ['Registrar',       'Signs grade sheets as University Registrar. Assign at the system level.'],
        ];
        foreach ($wanted as $shortname => [$name, $desc]) {
            try {
                if ($DB->record_exists('role', ['shortname' => $shortname])) {
                    continue;
                }
                $roleid = create_role($name, $shortname, $desc);
                set_role_contextlevels($roleid, [CONTEXT_SYSTEM, CONTEXT_COURSECAT]);
            } catch (\Throwable $e) {
                debugging('local_gradesheet: could not create role ' . $shortname . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /** Display form of a signatory name: full name in CAPS. */
    private static function signatory_name(\stdClass $user): string {
        return strtoupper(trim(fullname($user)));
    }

    /**
     * Auto-detects the four signatories from Moodle role assignments.
     *
     * Instructor: a user with local/gradesheet:manage enrolled in the course
     * (restricted to the group when $groupid > 0). The current user wins when
     * they are one; otherwise the only teacher; otherwise nothing is detected.
     *
     * Department Head / Registrar / College Dean: the first user holding the
     * configured role, searched upward from the course context through its
     * categories to the system context. So a dean assigned at the college
     * category is found by every course under it.
     *
     * @return array<string, array{name:string, source:string}>
     */
    public static function detect_signatories(int $courseid, int $groupid = 0): array {
        global $DB, $USER;

        $out = [];
        foreach (array_keys(self::signatory_roles()) as $key) {
            $out[$key] = ['name' => '', 'source' => ''];
        }
        if ($courseid <= 0) {
            return $out;
        }
        $ctx = \context_course::instance($courseid);

        // Instructor.
        $teachers = get_enrolled_users($ctx, 'local/gradesheet:manage', $groupid, 'u.id, u.firstname, u.lastname, u.middlename, u.firstnamephonetic, u.lastnamephonetic, u.alternatename', 'u.lastname ASC, u.firstname ASC');
        $scope = $groupid > 0 ? 'this section' : 'this course';
        if (!empty($USER->id) && isset($teachers[$USER->id])) {
            $out['instructor'] = ['name' => self::signatory_name($teachers[$USER->id]), 'source' => 'you are a teacher in ' . $scope];
        } else if (count($teachers) === 1) {
            $out['instructor'] = ['name' => self::signatory_name(reset($teachers)), 'source' => 'only teacher in ' . $scope];
        } else if (count($teachers) > 1) {
            $out['instructor'] = ['name' => '', 'source' => count($teachers) . ' teachers in ' . $scope . ' — cannot pick one automatically'];
        } else {
            $out['instructor'] = ['name' => '', 'source' => 'no teacher enrolled in ' . $scope];
        }

        // Role-based signatories, searched upward through the context tree.
        $contexts = method_exists($ctx, 'get_parent_contexts') ? $ctx->get_parent_contexts(true) : [$ctx];
        foreach (['department_head', 'registrar', 'college_dean'] as $key) {
            $shortname = self::signatory_role_shortname($key);
            $roleid = $DB->get_field('role', 'id', ['shortname' => $shortname]);
            if (!$roleid) {
                $out[$key] = ['name' => '', 'source' => "role '" . $shortname . "' does not exist on this site"];
                continue;
            }
            $found = null;
            $where = '';
            foreach ($contexts as $c) {
                $users = get_role_users($roleid, $c, false, 'u.id, u.firstname, u.lastname, u.middlename, u.firstnamephonetic, u.lastnamephonetic, u.alternatename', 'u.lastname ASC, u.firstname ASC');
                if (!empty($users)) {
                    $found = reset($users);
                    $where = method_exists($c, 'get_context_name') ? $c->get_context_name(false, true) : '';
                    break;
                }
            }
            if ($found) {
                $out[$key] = ['name' => self::signatory_name($found), 'source' => "role '" . $shortname . "'" . ($where !== '' ? ' at ' . $where : '')];
            } else {
                $out[$key] = ['name' => '', 'source' => "nobody holds role '" . $shortname . "' in this course, its categories, or the site"];
            }
        }
        return $out;
    }

    /**
     * Resolves the four signatory lines for a course/section, applying one
     * precedence everywhere (dashboard, preview, PDF, Excel):
     *   instructor: section override > detected section teacher > typed course name > detected course teacher
     *   others:     typed course name > detected role holder
     *
     * @param array $cfg Output of load_course_config().
     * @return array<string, array{name:string, how:string, source:string}> how = override|typed|auto|''
     */
    public static function resolve_signatories(array $cfg, int $courseid, int $groupid = 0): array {
        $detected = self::detect_signatories($courseid, $groupid);
        $cfgkeys  = ['instructor' => 'instructor', 'department_head' => 'depthead', 'registrar' => 'registrar', 'college_dean' => 'collegedean'];
        $override = $groupid > 0 ? self::get_group_overrides($courseid, $groupid) : null;

        $out = [];
        foreach ($cfgkeys as $dkey => $ckey) {
            $typed = $cfg[$ckey] ?? '';
            $det   = $detected[$dkey];
            if ($dkey === 'instructor' && $override && !empty($override->instructor)) {
                $out[$dkey] = ['name' => $override->instructor, 'how' => 'override', 'source' => 'per-section override'];
            } else if ($dkey === 'instructor' && $groupid > 0 && $det['name'] !== '') {
                $out[$dkey] = ['name' => $det['name'], 'how' => 'auto', 'source' => $det['source']];
            } else if (!self::signatory_is_blank($typed)) {
                $out[$dkey] = ['name' => $typed, 'how' => 'typed', 'source' => 'typed in Settings'];
            } else {
                $out[$dkey] = ['name' => $det['name'], 'how' => $det['name'] !== '' ? 'auto' : '', 'source' => $det['source']];
            }
        }
        return $out;
    }

    /** True when a stored signatory value should be treated as "not set". */
    public static function signatory_is_blank($value): bool {
        $v = strtoupper(trim((string)$value));
        return $v === '' || in_array($v, self::SIGNATORY_PLACEHOLDERS, true);
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
            'rules'         => self::get_computation_rules($courseid),
        ];
    }

    /** Per-request cache of computation rules, keyed by course id. */
    private static $rulescache = [];

    /**
     * Computation rules for a course, with safe defaults when the config row
     * is missing or predates the columns.
     *
     * @return array{missingaszero:bool, includehidden:bool, midtermweight:float, roundaverage:bool}
     */
    public static function get_computation_rules(int $courseid): array {
        global $DB;
        if (!isset(self::$rulescache[$courseid])) {
            $config = $DB->get_record('local_gradesheet_config', ['courseid' => $courseid]);
            $mw = ($config && isset($config->midtermweight)) ? floatval($config->midtermweight) : 50.0;
            if ($mw < 0 || $mw > 100) {
                $mw = 50.0;
            }
            self::$rulescache[$courseid] = [
                'missingaszero' => $config ? !empty($config->missingaszero) : false,
                'includehidden' => $config ? (!isset($config->includehidden) || !empty($config->includehidden)) : true,
                'midtermweight' => $mw,
                'roundaverage'  => $config ? !empty($config->roundaverage) : false,
            ];
        }
        return self::$rulescache[$courseid];
    }

    /** Clears per-request caches after settings are saved. */
    public static function reset_caches(): void {
        self::$rulescache = [];
        self::$formulacache = [];
        self::$transmutecache = [];
        self::$course_grade_data = [];
    }

    /**
     * Returns the per-section (group) header overrides, or null if none saved.
     */
    public static function get_group_overrides(int $courseid, int $groupid): ?\stdClass {
        global $DB;
        if ($groupid <= 0) {
            return null;
        }
        $rec = $DB->get_record('local_gradesheet_groupcfg', ['courseid' => $courseid, 'groupid' => $groupid]);
        return $rec ?: null;
    }

    /**
     * Saves per-section header overrides. Empty strings mean "use the course value".
     */
    public static function set_group_overrides(int $courseid, int $groupid, array $fields): void {
        global $DB;
        if (!$DB->record_exists('groups', ['id' => $groupid, 'courseid' => $courseid])) {
            return;
        }
        $data = (object)[
            'courseid'      => $courseid,
            'groupid'       => $groupid,
            'courseandyear' => mb_substr(trim($fields['courseandyear'] ?? ''), 0, 50),
            'schedule'      => mb_substr(trim($fields['schedule'] ?? ''), 0, 50),
            'instructor'    => mb_substr(strtoupper(trim($fields['instructor'] ?? '')), 0, 100),
            'timemodified'  => time(),
        ];
        $existing = $DB->get_record('local_gradesheet_groupcfg', ['courseid' => $courseid, 'groupid' => $groupid]);
        if ($data->courseandyear === '' && $data->schedule === '' && $data->instructor === '') {
            if ($existing) {
                $DB->delete_records('local_gradesheet_groupcfg', ['id' => $existing->id]);
            }
            return;
        }
        if ($existing) {
            $data->id = $existing->id;
            $DB->update_record('local_gradesheet_groupcfg', $data);
        } else {
            $DB->insert_record('local_gradesheet_groupcfg', $data);
        }
    }

    /**
     * Verify whether the current user is allowed to access the specified group in this course context.
     *
     * @param \context_course $context Course context.
     * @param int $groupid Group ID to validate (0 means all/default group).
     * @return bool True if allowed, false otherwise.
     */
    public static function check_group_access(\context_course $context, int $groupid): bool {
        global $DB, $USER;

        if ($groupid <= 0) {
            return true;
        }

        $courseid = (int)$context->instanceid;
        if (!$DB->record_exists('groups', ['id' => $groupid, 'courseid' => $courseid])) {
            return false;
        }

        $course = $DB->get_record('course', ['id' => $courseid]);
        if ($course) {
            $groupmode = groups_get_course_groupmode($course);
            if ($groupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
                return groups_is_member($groupid, $USER->id);
            }
        }

        return true;
    }

    /**
     * Sections (Moodle groups) the current user may open a grade sheet for.
     * Under SEPARATEGROUPS without accessallgroups that is only their own
     * groups; otherwise every group in the course. Keyed by group id, in
     * name order.
     */
    public static function get_accessible_sections(\context_course $context): array {
        global $DB, $USER;
        $courseid = (int)$context->instanceid;
        $groups = groups_get_all_groups($courseid);
        if (empty($groups)) {
            return [];
        }
        $course = $DB->get_record('course', ['id' => $courseid]);
        $restricted = $course && groups_get_course_groupmode($course) == SEPARATEGROUPS
            && !has_capability('moodle/site:accessallgroups', $context);
        $out = [];
        foreach ($groups as $g) {
            if ($restricted && !groups_is_member((int)$g->id, $USER->id)) {
                continue;
            }
            $out[(int)$g->id] = $g;
        }
        uasort($out, function ($a, $b) {
            return strnatcasecmp((string)$a->name, (string)$b->name);
        });
        return $out;
    }

    /** Whether the current user may view the combined (all sections) sheet. */
    public static function can_view_combined(\context_course $context): bool {
        global $DB;
        $course = $DB->get_record('course', ['id' => (int)$context->instanceid]);
        if (!$course || groups_get_course_groupmode($course) != SEPARATEGROUPS) {
            return true;
        }
        return has_capability('moodle/site:accessallgroups', $context);
    }

    /**
     * Resolves which section to show when the page is opened without an
     * explicit choice: the active group if any, else the first accessible
     * section, else 0 (combined / no groups).
     */
    public static function default_section(\context_course $context): int {
        global $DB;
        $sections = self::get_accessible_sections($context);
        if (empty($sections)) {
            return 0;
        }
        $course = $DB->get_record('course', ['id' => (int)$context->instanceid]);
        $active = $course ? (int)groups_get_course_group($course) : 0;
        if ($active > 0 && isset($sections[$active])) {
            return $active;
        }
        return (int)array_key_first($sections);
    }

    /**
     * Students enrolled in the course who belong to no group at all. When a
     * course is split into sections these students appear on no section
     * sheet, so faculty must be told.
     */
    public static function get_ungrouped_students(\context_course $context): array {
        $courseid = (int)$context->instanceid;
        $all = get_enrolled_users($context, '', 0, 'u.*', 'u.lastname ASC, u.firstname ASC');
        $teachers = get_enrolled_users($context, 'local/gradesheet:manage', 0, 'u.id');
        $out = [];
        foreach ($all as $u) {
            if (is_siteadmin($u->id) || isset($teachers[$u->id]) || has_capability('moodle/grade:viewall', $context, $u->id)) {
                continue;
            }
            if (empty(groups_get_user_groups($courseid, $u->id)[0])) {
                $out[] = $u;
            }
        }
        return $out;
    }

    public static function get_non_teaching_students(\context_course $context, int $groupid = 0): array {
        global $DB, $USER;

        $courseid = (int)$context->instanceid;
        $course = $DB->get_record('course', ['id' => $courseid]);

        if ($groupid === 0) {
            if ($course) {
                $groupmode = groups_get_course_groupmode($course);
                if ($groupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
                    $activegroup = groups_get_course_group($course);
                    $groupid = $activegroup ? (int)$activegroup : -1;
                } else if ($groupmode != NOGROUPS) {
                    $activegroup = groups_get_course_group($course);
                    if ($activegroup) {
                        $groupid = (int)$activegroup;
                    }
                }
            }
        } else if ($groupid > 0) {
            if (!self::check_group_access($context, $groupid)) {
                return [];
            }
        }

        if ($groupid === -1 || $groupid < 0) {
            return [];
        }

        $all = get_enrolled_users($context, '', $groupid, 'u.*', 'u.lastname ASC, u.firstname ASC');
        $teachers = get_enrolled_users($context, 'local/gradesheet:manage', 0, 'u.id');

        $filtered = [];
        foreach ($all as $student) {
            if (is_siteadmin($student->id)) {
                continue;
            }
            if (has_capability('moodle/grade:viewall', $context, $student->id)) {
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
            'courseid = ? AND itemtype != ? AND itemname IS NOT NULL AND gradetype = 1',
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

    /**
     * Make sure grade_grades.finalgrade is current before we read it.
     * Moodle marks items with needsupdate=1 when a regrade is pending; the
     * gradebook UI triggers that regrade lazily, so a direct table read could
     * otherwise see stale totals.
     */
    private static function ensure_gradebook_current(int $courseid): void {
        global $DB, $CFG;
        if ($DB->record_exists('grade_items', ['courseid' => $courseid, 'needsupdate' => 1])) {
            require_once($CFG->libdir . '/gradelib.php');
            grade_regrade_final_grades($courseid);
        }
    }

    private static function prefetch_course_grades(int $courseid): void {
        global $DB;
        if (isset(self::$course_grade_data[$courseid])) {
            return;
        }

        self::ensure_gradebook_current($courseid);

        $gitems = $DB->get_records_select(
            'grade_items',
            'courseid = ? AND itemtype != ? AND itemname IS NOT NULL AND gradetype = 1',
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
     * Computation rules (see get_computation_rules()):
     *  - includehidden: hidden grade items count for faculty; the student
     *    view ($forstudent = true) never includes them.
     *  - missingaszero: a mapped item with no grade counts as 0% instead of
     *    being skipped.
     *  - midtermweight: midterm share of the final average (default 50).
     *  - roundaverage: round period and final averages to whole numbers
     *    before transmutation and the pass/fail test.
     *
     * @param int $courseid Course id.
     * @param int $studentid Student id.
     * @param bool $forstudent True when rendering the student's own view.
     * @return array{midterm:?float, finals:?float, average:?float, cattotals:array, transmuted:string, remarks:string, graded:int, mapped:int, missing:int, periodcounts:array}
     */
    public static function compute_student_grades(int $courseid, int $studentid, bool $forstudent = false): array {
        self::prefetch_course_grades($courseid);
        $data  = self::$course_grade_data[$courseid];
        $rules = self::get_computation_rules($courseid);
        $includehidden = $rules['includehidden'] && !$forstudent;
        $graded = 0;
        $mapped = 0;
        $periodcounts = [
            'midterm' => ['graded' => 0, 'mapped' => 0],
            'finals'  => ['graded' => 0, 'mapped' => 0],
        ];
        
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
            // Items not mapped to an existing category never count, graded or not.
            $map    = $maps[$gitem->id] ?? null;
            $period = ($map && $map->period === 'midterm') ? 'midterm' : 'finals';
            $catid  = $map ? (int)$map->categoryid : 0;
            if (!$catid || !isset($cattotals[$catid])) {
                continue;
            }

            $gi = new \grade_item((array)$gitem, false);
            if (!$includehidden && $gi->is_hidden()) {
                continue;
            }

            $parentcat = $gi->get_parent_category();
            if (!$includehidden && $parentcat && method_exists($parentcat, 'is_hidden') && $parentcat->is_hidden()) {
                continue;
            }

            $ggrade = $grades[$gitem->id][$studentid] ?? null;
            if ($ggrade) {
                $gg = new \grade_grade((array)$ggrade, false);
                if ($gg->is_excluded()) {
                    continue; // Teacher explicitly excluded this grade: never counts.
                }
                if (!$includehidden && $gg->is_hidden()) {
                    continue;
                }
            }

            $mapped++;
            $periodcounts[$period]['mapped']++;
            $hasgrade = $ggrade && $ggrade->finalgrade !== null && $ggrade->finalgrade !== '';

            if ($hasgrade) {
                $graded++;
                $periodcounts[$period]['graded']++;
                $val = floatval($ggrade->finalgrade);
                $max = floatval($gitem->grademax);
                if ($max > 0 && $max != 100) {
                    $val = ($val / $max) * 100;
                }
            } else if ($rules['missingaszero']) {
                // Ungraded mapped item counts as 0% (faculty opted in).
                $val = 0.0;
            } else {
                // Default: skip ungraded items so future or uncompleted
                // activities do not pull the average down. The graded/mapped
                // counts let the UI flag this.
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

        if ($rules['roundaverage']) {
            $mid = ($mid === null) ? null : (float)round($mid);
            $fin = ($fin === null) ? null : (float)round($fin);
        }

        if ($mid !== null && $fin !== null) {
            $w = $rules['midtermweight'] / 100;
            $average = $mid * $w + $fin * (1 - $w);
        } else if ($mid !== null) {
            $average = $mid;
        } else if ($fin !== null) {
            $average = $fin;
        } else {
            $average = null;
        }

        if ($rules['roundaverage'] && $average !== null) {
            $average = (float)round($average);
        }

        return [
            'midterm'    => $mid,
            'finals'     => $fin,
            'average'    => $average,
            'graded'     => $graded,
            'mapped'     => $mapped,
            'missing'    => $mapped - $graded,
            'periodcounts' => $periodcounts,
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
        // Compare on the rounded difference so 33.33 + 33.33 + 33.33 (99.99) is
        // accepted: a raw float comparison makes 100 - 99.99 come out a hair
        // above 0.01 and would block printing for a perfectly normal split.
        return [
            'valid' => count($categories) > 0 && round(abs($total - 100), 4) <= 0.01,
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
        $fs = $courseid ? self::get_formula_settings($courseid) : ['mode' => 'essu'];
        $informula = ($fs['mode'] === 'formula');

        // Equivalent shown for a legend range: the stored value, or in formula
        // mode the formula evaluated at both ends of the range.
        $equivfor = function (float $lo, float $hi, string $stored) use ($fs, $informula): string {
            if (trim($stored) !== '' || !$informula) {
                return $stored;
            }
            $a = self::apply_formula($hi, $fs);
            $b = self::apply_formula($lo, $fs);
            if ($a === null || $b === null) {
                return '';
            }
            $fa = number_format($a, $fs['decimals']);
            $fb = number_format($b, $fs['decimals']);
            return ($fa === $fb) ? $fa : $fa . '-' . $fb;
        };

        if ($courseid) {
            $custom = self::get_custom_transmute_rows($courseid);
            if (!empty($custom)) {
                $legend = [];
                foreach ($custom as $row) {
                    $range = ($row->minscore == $row->maxscore)
                        ? self::format_score($row->minscore)
                        : self::format_score($row->maxscore) . '-' . self::format_score($row->minscore);
                    $legend[] = [$range, $equivfor((float)$row->minscore, (float)$row->maxscore, (string)$row->equivalent), $row->descriptor];
                }
                return $legend;
            }
        }

        if ($informula) {
            // Default ESSU ranges as the adjectival legend, equivalents from the formula.
            return [
                ['100',   $equivfor(100, 100, ''), 'Outstanding'],
                ['99-90', $equivfor(90, 99, ''),   'Excellent'],
                ['89-85', $equivfor(85, 89, ''),   'Very Good'],
                ['84-80', $equivfor(80, 84, ''),   'Good'],
                ['79-75', $equivfor(75, 79, ''),   'Fair'],
                ['74-70', $equivfor(70, 74, ''),   'Conditional'],
                ['69-0',  $equivfor(0, 69, ''),    'Failed'],
                ['INC',   'INC',     'Incomplete'],
                ['Dr',    'Dr',      'Dropped'],
                ['WP',    'WP',      'Withdrawn w/ permission'],
                ['IP',    'IP',      'In Progress'],
            ];
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
     * Configuration health check for a course, used by the "Needs attention"
     * panel on the settings page and the dashboard reminder.
     *
     * @return array<int, array{level:string, text:string, anchor:string}>
     *         level is 'danger' (blocks printing), 'warning' (sheet will be
     *         wrong or incomplete) or 'info' (worth knowing).
     */
    public static function settings_health(int $courseid, int $groupid = 0): array {
        global $DB;
        $issues = [];
        $add = function (string $level, string $text, string $anchor) use (&$issues): void {
            $issues[] = ['level' => $level, 'text' => $text, 'anchor' => $anchor];
        };
        $fmt = function ($v): string {
            return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
        };

        $config = $DB->get_record('local_gradesheet_config', ['courseid' => $courseid]);
        $groups = groups_get_all_groups($courseid);

        // 1. Category weights (hard gate).
        $w = self::validate_weight_sum($courseid);
        if ($w['count'] === 0) {
            $add('danger', 'No grade categories are defined. Printing and exporting are blocked.', 'grade-categories');
        } else if (!$w['valid']) {
            $add('danger', 'Category weights total ' . $fmt($w['total']) . '%, not 100%. Printing and exporting are blocked.', 'grade-categories');
        }

        // 2. Grade items and mapping.
        $gitems = $DB->get_records_select('grade_items',
            'courseid = ? AND itemtype != ? AND itemname IS NOT NULL AND gradetype = 1', [$courseid, 'course']);
        if (empty($gitems)) {
            $add('info', 'This course has no numeric grade items yet, so every student will show "-".', 'grade-mapping');
        } else {
            $mw = self::get_mapping_warnings($courseid);
            if ($mw) {
                if ($mw['unmapped'] > 0) {
                    $add('warning', $mw['unmapped'] . ' of ' . count($gitems) . ' grade item(s) are not mapped to a category and are excluded from computation.', 'grade-mapping');
                }
                if ($mw['midterm'] === 0) {
                    $add('warning', 'No grade items are mapped to Midterm; that column will print "-".', 'grade-mapping');
                }
                if ($mw['finals'] === 0) {
                    $add('warning', 'No grade items are mapped to Finals; that column will print "-".', 'grade-mapping');
                }
            }
            $cats = $DB->get_records('local_gradesheet_categories', ['courseid' => $courseid]);
            $maps = $DB->get_records('local_gradesheet_itemmap', ['courseid' => $courseid]);
            $percat = [];
            foreach ($maps as $m) {
                if (isset($gitems[$m->gradeitemid]) && $m->categoryid) {
                    $percat[$m->categoryid] = ($percat[$m->categoryid] ?? 0) + 1;
                }
            }
            foreach ($cats as $cat) {
                $n = $percat[$cat->id] ?? 0;
                if ($n === 0 && (float)$cat->weight > 0) {
                    $add('warning', 'Category "' . s($cat->name) . '" (' . $fmt($cat->weight) . '%) has no grade items mapped to it; its weight is redistributed to the other categories.', 'grade-mapping');
                } else if ($n > 0 && (float)$cat->weight == 0) {
                    $add('warning', 'Category "' . s($cat->name) . '" has ' . $n . ' item(s) but a weight of 0%, so they do not count.', 'grade-categories');
                }
            }
            $hidden = $DB->count_records_select('grade_items',
                'courseid = ? AND itemtype != ? AND itemname IS NOT NULL AND gradetype = 1 AND hidden <> 0', [$courseid, 'course']);
            $rules = self::get_computation_rules($courseid);
            if ($hidden > 0) {
                $add($rules['includehidden'] ? 'info' : 'warning',
                    $hidden . ' grade item(s) are hidden from students and are ' . ($rules['includehidden'] ? 'included in' : 'EXCLUDED from') . ' the faculty computation.', 'computation-rules');
            }
            if (!$rules['missingaszero']) {
                $add('info', 'Ungraded items are skipped (not counted as 0%). Turn on "Count ungraded items as 0%" before finalizing the official sheet.', 'computation-rules');
            }
        }

        // 3. Transmutation.
        $fs = self::get_formula_settings($courseid);
        if ($fs['mode'] !== 'formula') {
            $add('info', 'Transmutation uses the built-in ESSU table. Set an explicit formula under Transmutation Formula so the computation is stated, not implied.', 'transmutation-formula');
        } else {
            $g0 = self::apply_formula(0.0, $fs);
            $g100 = self::apply_formula(100.0, $fs);
            if ($g0 === null || $g100 === null) {
                $add('danger', 'The transmutation formula cannot be evaluated; grades will print "-".', 'transmutation-formula');
            } else if ($g0 == $g100) {
                $add('warning', 'The transmutation formula gives the same grade (' . $fmt($g0) . ') for 0% and 100%.', 'transmutation-formula');
            }
        }
        foreach (self::validate_custom_scale($courseid) as $sw) {
            $add('warning', strip_tags($sw), 'grading-scale');
        }
        $custom = self::get_custom_transmute_rows($courseid);
        foreach ($custom as $row) {
            if (trim((string)$row->descriptor) === '' && trim((string)$row->equivalent) === '') {
                $add('warning', 'Bracket ' . $fmt($row->minscore) . '-' . $fmt($row->maxscore) . ' has no descriptor or equivalent and prints blank in the legend.', 'grading-scale');
            }
        }

        // 4. Report header.
        if (!$config) {
            $add('warning', 'Course details have not been saved yet.', 'course-details');
        } else {
            if (trim((string)$config->coursenumber) === '') {
                $add('warning', 'Subject and Course No. is blank.', 'course-details');
            }
            if (trim((string)$config->descriptive) === '') {
                $add('warning', 'Descriptive Title is blank.', 'course-details');
            }
            if (trim((string)$config->courseandyear) === '' && empty($groups)) {
                $add('warning', 'Course and Year is blank and the course has no groups, so the section line will be empty.', 'course-details');
            }
            $sched = strtoupper(trim((string)$config->schedule));
            if ($sched === '' || $sched === 'TBA') {
                $add('warning', 'Schedule of Classes is not set.', 'course-details');
            }
            if (trim((string)$config->units) === '' || !is_numeric($config->units)) {
                $add('warning', 'Number of Units is blank or not a number.', 'course-details');
            }
            if (preg_match('/^(\d{4})-(\d{4})$/', (string)$config->schoolyear, $m)) {
                $y = (int)date('Y');
                if ((int)$m[1] < $y - 1 || (int)$m[1] > $y + 1) {
                    $add('info', 'School Year is ' . s($config->schoolyear) . '; check that it is current.', 'course-details');
                }
            } else {
                $add('warning', 'School Year "' . s((string)$config->schoolyear) . '" is not in the form 2026-2027.', 'course-details');
            }
        }

        // 5. Signatories.
        $cfg = self::load_course_config($courseid);
        $sig = self::resolve_signatories($cfg, $courseid, $groupid);
        $labels = ['instructor' => 'Instructor', 'department_head' => 'Department Head', 'registrar' => 'Registrar', 'college_dean' => 'College Dean'];
        foreach ($labels as $k => $label) {
            if ($sig[$k]['name'] === '') {
                $add('warning', $label . ' line will print blank: ' . s($sig[$k]['source']) . '. Assign the role or type a name.', 'sig-' . $k);
            }
        }

        // 6. Sections.
        if (count($groups) > 1) {
            $withsched = $DB->count_records_select('local_gradesheet_groupcfg', "courseid = ? AND schedule <> ''", [$courseid]);
            if ($withsched < count($groups)) {
                $add('info', count($groups) . ' sections (groups) share the course-wide schedule; set per-section schedules if they differ.', 'section-overrides');
            }
        }

        // Most serious first.
        $rank = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($issues, function ($a, $b) use ($rank) {
            return $rank[$a['level']] <=> $rank[$b['level']];
        });
        return $issues;
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
    public static function render_table_actions(string $edit_url, string $delete_action, string $sesskey, string $delete_label = 'Delete', bool $delete_disabled = false, string $extra_hidden = '', string $confirm_msg = 'Delete this bracket?'): string {
        $html = '<a href="' . htmlspecialchars($edit_url) . '" class="btn btn-warning btn-sm mr-2 me-2">Edit</a>';
        if ($delete_disabled) {
            $html .= '<button type="button" class="btn btn-danger btn-sm disabled" tabindex="-1">' . htmlspecialchars($delete_label) . '</button>';
        } else {
            $confirm_attr = !empty($confirm_msg) ? ' onsubmit="return confirm(\'' . addslashes(htmlspecialchars($confirm_msg, ENT_QUOTES)) . '\');"' : '';
            $html .= '<form method="post" class="d-inline m-0"' . $confirm_attr . '>';
            $html .= '<input type="hidden" name="action" value="' . htmlspecialchars($delete_action) . '">';
            $html .= '<input type="hidden" name="sesskey" value="' . htmlspecialchars($sesskey) . '">';
            $html .= $extra_hidden;
            $html .= '<button type="submit" class="btn btn-danger btn-sm">' . htmlspecialchars($delete_label) . '</button>';
            $html .= '</form>';
        }
        return $html;
    }

    /**
     * Automatically maps a grade item to an appropriate category and period based on its module type and title.
     *
     * @param int $courseid
     * @param object $gitem grade_item instance or database record
     * @param bool $force If true, overwrites existing mapping. If false, preserves existing valid mapping.
     * @return bool True if mapped, false otherwise.
     */
    public static function auto_map_grade_item(int $courseid, $gitem, bool $force = false): bool {
        global $DB;

        if (empty($gitem) || empty($gitem->id)) {
            return false;
        }
        if (!empty($gitem->courseid)) {
            $courseid = (int)$gitem->courseid;
        }

        // Ignore course total items and non-numeric grade items.
        if (isset($gitem->itemtype) && $gitem->itemtype === 'course') {
            return false;
        }
        if (isset($gitem->gradetype) && (int)$gitem->gradetype !== 1) { // 1 = GRADE_TYPE_VALUE
            return false;
        }

        $categories = $DB->get_records('local_gradesheet_categories', ['courseid' => $courseid], 'sortorder ASC');
        if (empty($categories)) {
            return false;
        }

        $existing = $DB->get_record('local_gradesheet_itemmap', [
            'courseid' => $courseid,
            'gradeitemid' => $gitem->id,
        ]);

        // If already mapped to an existing valid category and not forcing overwrite, keep it.
        if ($existing && !empty($existing->categoryid) && isset($categories[$existing->categoryid]) && !$force) {
            return false;
        }

        $name = strtolower(trim((string)($gitem->itemname ?? '')));
        $mod  = strtolower(trim((string)($gitem->itemmodule ?? '')));

        // 1. Determine period (Midterm vs Finals)
        $period = null;
        if (preg_match('/\b(midterm|prelim|prelims|1st\s*period|first\s*period|1st\s*half|part\s*1)\b/i', $name)) {
            $period = 'midterm';
        } else if (preg_match('/\b(final|finals|semi[- ]?final|semifinal|2nd\s*period|second\s*period|2nd\s*half|part\s*2)\b/i', $name)) {
            $period = 'finals';
        }

        if ($period === null) {
            // Check balance of existing mappings in course to avoid empty midterm
            $midcount = 0;
            $fincount = 0;
            if (method_exists($DB, 'count_records')) {
                $midcount = $DB->count_records('local_gradesheet_itemmap', ['courseid' => $courseid, 'period' => 'midterm']);
                $fincount = $DB->count_records('local_gradesheet_itemmap', ['courseid' => $courseid, 'period' => 'finals']);
            }
            $period = ($midcount <= $fincount) ? 'midterm' : 'finals';
        }

        // 2. Identify target category
        $matched_cat_id = 0;

        $is_exam = preg_match('/\b(exam|examination|periodical|major\s*exam|quarterly|assessment)\b/i', $name);
        $is_quiz = (!$is_exam && ($mod === 'quiz' || preg_match('/\b(quiz|quizzes|seatwork|test|drill|written)\b/i', $name)));
        $is_act  = (!$is_exam && !$is_quiz && ($mod === 'assign' || $mod === 'workshop' || $mod === 'h5pactivity' || $mod === 'lesson' || $mod === 'scorm' || preg_match('/\b(activity|activities|lab|laboratory|project|exercise|assignment|assign|task|problem\s*set|seatwork|homework|hw|case\s*study|performance)\b/i', $name)));

        $find_cat = function(string $regex) use ($categories): int {
            foreach ($categories as $cat) {
                if (preg_match($regex, strtolower($cat->name))) {
                    return (int)$cat->id;
                }
            }
            return 0;
        };

        if ($is_exam) {
            $matched_cat_id = $find_cat('/(exam|major|periodical|quarterly|assessment)/i');
        } else if ($is_quiz) {
            $matched_cat_id = $find_cat('/(quiz|test|written)/i');
            if (!$matched_cat_id) {
                $matched_cat_id = $find_cat('/(act|assign|lab|task|work|performance|exam)/i');
            }
        } else if ($is_act) {
            $matched_cat_id = $find_cat('/(act|lab|assign|project|task|problem|work|performance|exercise)/i');
        }

        // Fallback: substring match against defined category names.
        if (!$matched_cat_id) {
            foreach ($categories as $cat) {
                $cname = strtolower(trim($cat->name));
                if (strpos($name, $cname) !== false || strpos($cname, $name) !== false) {
                    $matched_cat_id = (int)$cat->id;
                    break;
                }
            }
        }

        // Fallback for module types if no specialized name matched.
        if (!$matched_cat_id) {
            if ($mod === 'quiz') {
                $matched_cat_id = $find_cat('/(quiz|test|written|exam|act)/i');
            } else if ($mod === 'assign' || $mod === 'workshop' || $mod === 'h5pactivity') {
                $matched_cat_id = $find_cat('/(act|assign|lab|task|work|performance|proj)/i');
            }
        }

        // Default fallback: first available category in the course if still unmatched.
        if (!$matched_cat_id) {
            $first = reset($categories);
            $matched_cat_id = $first ? (int)$first->id : 0;
        }

        if (!$matched_cat_id) {
            return false;
        }

        if ($existing) {
            $existing->categoryid = $matched_cat_id;
            $existing->period = $period;
            $DB->update_record('local_gradesheet_itemmap', $existing);
        } else {
            $DB->insert_record('local_gradesheet_itemmap', (object)[
                'courseid'    => $courseid,
                'gradeitemid' => $gitem->id,
                'categoryid'  => $matched_cat_id,
                'period'      => $period,
            ]);
        }

        return true;
    }

    /**
     * Auto-maps all unmapped or unassigned numeric grade items in a course.
     *
     * @param int $courseid
     * @return int Number of items mapped.
     */
    public static function auto_map_unmapped_items(int $courseid): int {
        global $DB;
        $categories = $DB->get_records('local_gradesheet_categories', ['courseid' => $courseid], '', 'id');
        if (empty($categories)) {
            return 0;
        }

        $gitems = $DB->get_records_select('grade_items',
            'courseid = ? AND itemtype != ? AND itemname IS NOT NULL AND gradetype = 1',
            [$courseid, 'course']
        );
        if (empty($gitems)) {
            return 0;
        }

        $mapped_count = 0;
        foreach ($gitems as $gi) {
            $existing = $DB->get_record('local_gradesheet_itemmap', ['courseid' => $courseid, 'gradeitemid' => $gi->id]);
            if (!$existing || empty($existing->categoryid) || !isset($categories[$existing->categoryid])) {
                if (self::auto_map_grade_item($courseid, $gi, false)) {
                    $mapped_count++;
                }
            }
        }

        if ($mapped_count > 0) {
            self::reset_caches();
        }

        return $mapped_count;
    }
}