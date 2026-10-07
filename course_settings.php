<?php
require_once('../../config.php');
require_once($CFG->libdir.'/formslib.php');

use local_gradesheet\helper;

$courseid = required_param('courseid', PARAM_INT);
$course   = get_course($courseid);
require_login($course);
$context  = context_course::instance($courseid);
require_capability('local/gradesheet:manage', $context);

$PAGE->set_url('/local/gradesheet/course_settings.php', ['courseid' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_title('Grade Sheet Settings');
$PAGE->set_heading('Grade Sheet Settings');

helper::ensure_course_defaults($courseid);
helper::auto_map_unmapped_items($courseid);

$coursename = $DB->get_field('course', 'fullname', ['id' => $courseid]);

$gitems = $DB->get_records_select(
    'grade_items',
    'courseid = ? AND itemtype != ? AND itemname IS NOT NULL AND gradetype = 1',
    [$courseid, 'course']
);

// ── HANDLE FORM SUBMISSIONS ───────────────────────────────────────────────────
// Every field is validated here before anything touches the database. Bad
// input never produces a Moodle exception page: the user is sent back to the
// card they were on with a message that names the field and the rule.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = optional_param('action', '', PARAM_ALPHA);

    $urlfor = function (string $anchor = ''): moodle_url {
        return new moodle_url('/local/gradesheet/course_settings.php', ['courseid' => $courseid], $anchor ?: null);
    };
    $settingsurl = $urlfor();
    $catsurl     = $urlfor('grade-categories');
    $mapurl      = $urlfor('grade-mapping');
    $scaleurl    = $urlfor('grading-scale');
    $rulesurl    = $urlfor('computation-rules');
    $formulaurl  = $urlfor('transmutation-formula');
    $groupsurl   = $urlfor('section-overrides');

    $fail = function (moodle_url $back, string $message): void {
        redirect($back, $message, null, \core\output\notification::NOTIFY_ERROR);
    };
    $ok = function (moodle_url $back, string $message, array $warnings = []): void {
        if (!empty($warnings)) {
            redirect($back, $message . ' Note: ' . implode(' ', $warnings), null, \core\output\notification::NOTIFY_WARNING);
        }
        redirect($back, $message, null, \core\output\notification::NOTIFY_SUCCESS);
    };

    // Reads a text field; enforces required/length instead of silently truncating.
    $read_text = function (string $param, string $label, int $maxlen, bool $required, moodle_url $back) use ($fail): string {
        $v = trim((string)optional_param($param, '', PARAM_TEXT));
        if ($required && $v === '') {
            $fail($back, $label . ' is required.');
        }
        if (mb_strlen($v) > $maxlen) {
            $fail($back, $label . ' must be ' . $maxlen . ' characters or fewer (you entered ' . mb_strlen($v) . ').');
        }
        return $v;
    };

    // Reads a numeric field without PARAM_FLOAT's silent "abc -> 0" behaviour.
    // Accepts "85", "85.5", "85%", " 1,000 ". Returns null when blank and not required.
    $read_number = function (string $param, string $label, ?float $min, ?float $max, bool $required, moodle_url $back, ?float $default = null) use ($fail): ?float {
        $raw = trim((string)optional_param($param, '', PARAM_RAW_TRIMMED));
        if ($raw === '') {
            if ($required) {
                $fail($back, $label . ' is required.');
            }
            return $default;
        }
        $clean = str_replace([',', '%', ' '], '', $raw);
        if (!is_numeric($clean)) {
            $fail($back, $label . ' must be a number (you entered "' . s($raw) . '").');
        }
        $v = (float)$clean;
        if (!is_finite($v)) {
            $fail($back, $label . ' is not a valid number.');
        }
        if ($min !== null && $v < $min) {
            $fail($back, $label . ' cannot be less than ' . $min . ' (you entered ' . s($raw) . ').');
        }
        if ($max !== null && $v > $max) {
            $fail($back, $label . ' cannot be more than ' . $max . ' (you entered ' . s($raw) . ').');
        }
        return $v;
    };

    $fmt = function (float $v): string {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    };

    $duplicate_category_name = function (string $name, int $excludeid = 0) use ($DB, $courseid): bool {
        $records = $DB->get_records('local_gradesheet_categories', ['courseid' => $courseid]);
        $needle  = mb_strtolower(trim($name));
        foreach ($records as $rec) {
            if ((int)$rec->id !== $excludeid && mb_strtolower(trim($rec->name)) === $needle) {
                return true;
            }
        }
        return false;
    };

    // Weight-total note appended to category messages so faculty see the effect immediately.
    $weight_note = function () use ($courseid, $fmt): array {
        $w = helper::validate_weight_sum($courseid);
        if ($w['valid']) {
            return [];
        }
        return ['Category weights now total ' . $fmt((float)$w['total']) . '%; they must total exactly 100% before printing or exporting.'];
    };

    try {
        switch ($action) {

            // ── Course details & signatories ─────────────────────────────────
            case 'savedetails':
                $semester = $read_text('semester', 'Semester', 50, true, $settingsurl);
                if (!in_array($semester, ['First Semester', 'Second Semester', 'Summer'], true)) {
                    $fail($settingsurl, 'Semester must be First Semester, Second Semester or Summer.');
                }

                $schoolyear = $read_text('schoolyear', 'School Year', 20, true, $settingsurl);
                if (!preg_match('/^(\d{4})\s*-\s*(\d{4})$/', $schoolyear, $m)) {
                    $fail($settingsurl, 'School Year must look like 2026-2027 (you entered "' . s($schoolyear) . '").');
                }
                if ((int)$m[2] !== (int)$m[1] + 1) {
                    $fail($settingsurl, 'School Year must be two consecutive years, e.g. 2026-2027 (you entered "' . s($schoolyear) . '").');
                }
                $schoolyear = $m[1] . '-' . $m[2];

                $coursenumber  = $read_text('coursenumber',  'Subject and Course No.', 50,  true,  $settingsurl);
                $descriptive   = $read_text('descriptive',   'Descriptive Title',      100, true,  $settingsurl);
                $courseandyear = $read_text('courseandyear', 'Course and Year',        50,  false, $settingsurl);
                $schedule      = $read_text('schedule',      'Schedule of Classes',    50,  false, $settingsurl);
                $units         = $read_number('units', 'Number of Units', 0, 12, true, $settingsurl);

                $sigs = [];
                foreach (['instructor' => 'Instructor', 'department_head' => 'Department Head',
                          'registrar' => 'Registrar', 'college_dean' => 'College Dean'] as $f => $label) {
                    $v = $read_text($f, $label, 100, false, $settingsurl);
                    // Placeholders from older versions mean "not set": store blank so auto-detect applies.
                    $sigs[$f] = helper::signatory_is_blank($v) ? '' : $v;
                }

                $details = array_merge([
                    'semester'      => $semester,
                    'schoolyear'    => $schoolyear,
                    'coursenumber'  => $coursenumber,
                    'descriptive'   => $descriptive,
                    'courseandyear' => $courseandyear,
                    'schedule'      => $schedule,
                    'units'         => $fmt($units),
                ], $sigs);

                helper::ensure_course_defaults($courseid);
                $existing = $DB->get_record('local_gradesheet_config', ['courseid' => $courseid], '*', MUST_EXIST);
                foreach ($details as $k => $v) {
                    $existing->$k = $v;
                }
                $existing->timemodified = time();
                $DB->update_record('local_gradesheet_config', $existing);
                helper::reset_caches();

                $warn = [];
                $groupcount = count(groups_get_all_groups($courseid));
                if ($courseandyear === '' && $groupcount === 0) {
                    $warn[] = 'Course and Year is blank and this course has no groups, so the section line on the sheet will be empty.';
                }
                if ($schedule === '' || strtoupper($schedule) === 'TBA') {
                    $warn[] = 'Schedule of Classes is not set.';
                }
                $thisyear = (int)date('Y');
                if ((int)$m[1] < $thisyear - 1 || (int)$m[1] > $thisyear + 1) {
                    $warn[] = 'School Year ' . $schoolyear . ' is far from the current year; double-check it.';
                }
                $ok($settingsurl, 'Course details saved!', $warn);
                break;

            // ── Computation rules ────────────────────────────────────────────
            case 'saverules':
                $mw = $read_number('midtermweight', 'Midterm share of final average', 0, 100, true, $rulesurl);
                helper::ensure_course_defaults($courseid);
                $existing = $DB->get_record('local_gradesheet_config', ['courseid' => $courseid], '*', MUST_EXIST);
                $existing->missingaszero = optional_param('missingaszero', 0, PARAM_INT) ? 1 : 0;
                $existing->includehidden = optional_param('includehidden', 0, PARAM_INT) ? 1 : 0;
                $existing->midtermweight = round($mw, 2);
                $existing->roundaverage  = optional_param('roundaverage', 0, PARAM_INT) ? 1 : 0;
                $existing->timemodified  = time();
                $DB->update_record('local_gradesheet_config', $existing);
                helper::reset_caches();

                $warn = [];
                if ($mw == 0 || $mw == 100) {
                    $warn[] = 'With a midterm share of ' . $fmt($mw) . '% one period is ignored entirely in the final average.';
                }
                if (!$existing->includehidden && $DB->record_exists_select('grade_items', 'courseid = ? AND hidden <> 0', [$courseid])) {
                    $warn[] = 'This course has hidden grade items; they are now excluded from the faculty computation.';
                }
                $ok($rulesurl, 'Computation rules saved!', $warn);
                break;

            // ── Transmutation formula ────────────────────────────────────────
            case 'saveformula':
                $mode     = optional_param('transmutemode', 'essu', PARAM_ALPHA) === 'formula' ? 'formula' : 'essu';
                $formulat = $read_text('formula', 'Formula', 255, $mode === 'formula', $formulaurl);
                $fmin     = $read_number('formulamin', 'Minimum grade', null, null, false, $formulaurl);
                $fmax     = $read_number('formulamax', 'Maximum grade', null, null, false, $formulaurl);
                $decimals = optional_param('formuladecimals', 1, PARAM_INT);
                $passmark = $read_number('passmark', 'Passing mark', 0, 100, true, $formulaurl);

                if ($mode === 'formula') {
                    $err = \local_gradesheet\formula::validate($formulat);
                    if ($err !== '') {
                        $fail($formulaurl, 'Formula not saved: ' . $err);
                    }
                }
                if ($fmin !== null && $fmax !== null && $fmin > $fmax) {
                    $fail($formulaurl, 'Minimum grade (' . $fmt($fmin) . ') cannot be greater than the maximum grade (' . $fmt($fmax) . ').');
                }
                if (!in_array($decimals, [0, 1, 2], true)) {
                    $fail($formulaurl, 'Decimals shown must be 0, 1 or 2.');
                }

                helper::ensure_course_defaults($courseid);
                $existing = $DB->get_record('local_gradesheet_config', ['courseid' => $courseid], '*', MUST_EXIST);
                $existing->transmutemode   = $mode;
                $existing->formula         = $formulat;
                $existing->formulamin      = ($fmin === null) ? null : round($fmin, 2);
                $existing->formulamax      = ($fmax === null) ? null : round($fmax, 2);
                $existing->formuladecimals = $decimals;
                $existing->passmark        = round($passmark, 2);
                $existing->timemodified    = time();
                $DB->update_record('local_gradesheet_config', $existing);
                helper::reset_caches();

                $warn = [];
                if ($mode === 'formula') {
                    $fs = helper::get_formula_settings($courseid);
                    $g0   = helper::apply_formula(0.0, $fs);
                    $g100 = helper::apply_formula(100.0, $fs);
                    $gpm  = helper::apply_formula($passmark, $fs);
                    if ($g0 !== null && $g100 !== null && $g0 == $g100) {
                        $warn[] = 'The formula gives the same grade (' . $fmt($g0) . ') for 0% and 100%; the clamp or formula may be wrong.';
                    }
                    if ($fmin !== null && $fmax !== null && $g0 !== null && $g100 !== null) {
                        $lo = min($g0, $g100);
                        $hi = max($g0, $g100);
                        if ($hi <= $fmin || $lo >= $fmax) {
                            $warn[] = 'Every result falls outside the min/max clamp, so all students would get the same grade.';
                        }
                    }
                    if ($gpm !== null) {
                        $warn[] = 'A student exactly at the passing mark (' . $fmt($passmark) . '%) will print ' . number_format($gpm, $fs['decimals']) . '.';
                    }
                }
                $ok($formulaurl, 'Transmutation settings saved!', $warn);
                break;

            // ── Per-section overrides ────────────────────────────────────────
            case 'savegroupcfg':
                $gid = optional_param('groupid', 0, PARAM_INT);
                if ($gid <= 0 || !$DB->record_exists('groups', ['id' => $gid, 'courseid' => $courseid])) {
                    $fail($groupsurl, 'That group does not belong to this course (it may have been deleted).');
                }
                $g_label = $read_text('g_courseandyear', 'Section label', 50,  false, $groupsurl);
                $g_sched = $read_text('g_schedule',      'Schedule',      50,  false, $groupsurl);
                $g_instr = $read_text('g_instructor',    'Instructor',    100, false, $groupsurl);
                helper::set_group_overrides($courseid, $gid, [
                    'courseandyear' => $g_label, 'schedule' => $g_sched, 'instructor' => $g_instr,
                ]);
                $gname = format_string($DB->get_field('groups', 'name', ['id' => $gid]));
                $ok($groupsurl, ($g_label === '' && $g_sched === '' && $g_instr === '')
                    ? 'Overrides cleared for ' . s($gname) . '; it now uses the course-wide values.'
                    : 'Section overrides saved for ' . s($gname) . '!');
                break;

            // ── Categories ───────────────────────────────────────────────────
            case 'addcategory':
                $name   = $read_text('catname', 'Category name', 100, true, $catsurl);
                $weight = $read_number('catweight', 'Weight', 0, 100, true, $catsurl);
                if ($duplicate_category_name($name)) {
                    $fail($catsurl, 'A category named "' . s($name) . '" already exists.');
                }
                $sortorder = $DB->count_records('local_gradesheet_categories', ['courseid' => $courseid]);
                $DB->insert_record('local_gradesheet_categories', (object)[
                    'courseid' => $courseid, 'name' => $name, 'weight' => round($weight, 2), 'sortorder' => $sortorder,
                ]);
                helper::auto_map_unmapped_items($courseid);
                helper::reset_caches();
                $ok($catsurl, "Category '" . s($name) . "' added.", $weight_note());
                break;

            case 'updatecategory':
                $catid    = optional_param('catid', 0, PARAM_INT);
                $category = $catid ? $DB->get_record('local_gradesheet_categories', ['id' => $catid, 'courseid' => $courseid]) : null;
                if (!$category) {
                    $fail($catsurl, 'That category no longer exists.');
                }
                $name   = $read_text('catname', 'Category name', 100, true, $catsurl);
                $weight = $read_number('catweight', 'Weight', 0, 100, true, $catsurl);
                if ($duplicate_category_name($name, $catid)) {
                    $fail($catsurl, 'A category named "' . s($name) . '" already exists.');
                }
                $category->name   = $name;
                $category->weight = round($weight, 2);
                $DB->update_record('local_gradesheet_categories', $category);
                helper::reset_caches();
                $warn = $weight_note();
                if ($weight == 0 && $DB->record_exists('local_gradesheet_itemmap', ['courseid' => $courseid, 'categoryid' => $catid])) {
                    $warn[] = 'Items mapped to "' . s($name) . '" now contribute 0% because its weight is 0.';
                }
                $ok($catsurl, "Category '" . s($name) . "' updated.", $warn);
                break;

            case 'deletecategory':
                $catid    = optional_param('catid', 0, PARAM_INT);
                $category = $catid ? $DB->get_record('local_gradesheet_categories', ['id' => $catid, 'courseid' => $courseid]) : null;
                if (!$category) {
                    $fail($catsurl, 'That category no longer exists.');
                }
                if ($DB->count_records('local_gradesheet_categories', ['courseid' => $courseid]) <= 1) {
                    $fail($catsurl, get_string('warnlastcategory', 'local_gradesheet'));
                }
                $affected = $DB->count_records('local_gradesheet_itemmap', ['courseid' => $courseid, 'categoryid' => $catid]);
                $DB->delete_records('local_gradesheet_categories', ['id' => $catid, 'courseid' => $courseid]);
                $DB->set_field('local_gradesheet_itemmap', 'categoryid', 0, ['courseid' => $courseid, 'categoryid' => $catid]);
                helper::reset_caches();
                $warn = $weight_note();
                if ($affected > 0) {
                    $warn[] = $affected . ' grade item(s) that were in "' . s($category->name) . '" are now unmapped and excluded from computation until you re-map them.';
                }
                $ok($catsurl, 'Category "' . s($category->name) . '" deleted.', $warn);
                break;

            // ── Rating brackets ──────────────────────────────────────────────
            case 'addtransmute':
            case 'updatetransmute':
                $row = null;
                if ($action === 'updatetransmute') {
                    $tid = optional_param('tid', 0, PARAM_INT);
                    $row = $tid ? $DB->get_record('local_gradesheet_transmute', ['id' => $tid, 'courseid' => $courseid]) : null;
                    if (!$row) {
                        $fail($scaleurl, 'That bracket no longer exists.');
                    }
                }
                $min   = $read_number('tmin', 'Min score', 0, 100, true, $scaleurl);
                $max   = $read_number('tmax', 'Max score', 0, 100, true, $scaleurl);
                $desc  = $read_text('tdesc',  'Descriptor', 100, false, $scaleurl);
                $equiv = $read_text('tequiv', 'Equivalent', 10,  false, $scaleurl);
                $ispassing = optional_param('tispassing', 0, PARAM_INT) ? 1 : 0;
                if ($max < $min) {
                    $fail($scaleurl, 'Max score (' . $fmt($max) . ') must be greater than or equal to Min score (' . $fmt($min) . ').');
                }
                if ($desc === '' && $equiv === '') {
                    $fail($scaleurl, 'Give the bracket a Descriptor (adjectival rating) or an Equivalent; a bracket with neither prints nothing.');
                }
                // Exact duplicate range is almost certainly a double-submit.
                $others = $DB->get_records('local_gradesheet_transmute', ['courseid' => $courseid]);
                $overlaps = [];
                foreach ($others as $o) {
                    if ($row && (int)$o->id === (int)$row->id) {
                        continue;
                    }
                    if ((float)$o->minscore == $min && (float)$o->maxscore == $max) {
                        $fail($scaleurl, 'A bracket covering ' . $fmt($min) . '-' . $fmt($max) . ' already exists.');
                    }
                    if ($min <= (float)$o->maxscore && $max >= (float)$o->minscore) {
                        $overlaps[] = $fmt((float)$o->minscore) . '-' . $fmt((float)$o->maxscore);
                    }
                }

                if ($row) {
                    $row->minscore = $min; $row->maxscore = $max; $row->equivalent = $equiv;
                    $row->descriptor = $desc; $row->ispassing = $ispassing;
                    $DB->update_record('local_gradesheet_transmute', $row);
                } else {
                    $DB->insert_record('local_gradesheet_transmute', (object)[
                        'courseid' => $courseid, 'minscore' => $min, 'maxscore' => $max, 'equivalent' => $equiv,
                        'descriptor' => $desc, 'sortorder' => count($others), 'ispassing' => $ispassing,
                    ]);
                }
                helper::reset_caches();
                $warn = [];
                if ($overlaps) {
                    $warn[] = 'This bracket overlaps ' . implode(', ', $overlaps) . '; the higher bracket wins for scores in the overlap.';
                }
                $warn = array_merge($warn, array_map('strip_tags', helper::validate_custom_scale($courseid)));
                $ok($scaleurl, $row ? 'Bracket updated.' : 'Bracket added.', $warn);
                break;

            case 'deletetransmute':
                $tid = optional_param('tid', 0, PARAM_INT);
                if (!$tid || !$DB->record_exists('local_gradesheet_transmute', ['id' => $tid, 'courseid' => $courseid])) {
                    $fail($scaleurl, 'That bracket no longer exists.');
                }
                $DB->delete_records('local_gradesheet_transmute', ['id' => $tid, 'courseid' => $courseid]);
                helper::reset_caches();
                $left = $DB->count_records('local_gradesheet_transmute', ['courseid' => $courseid]);
                $ok($scaleurl, 'Bracket deleted.', $left === 0 ? ['No brackets left: the default ESSU ranges are used for the rating legend.'] : array_map('strip_tags', helper::validate_custom_scale($courseid)));
                break;

            case 'resetscale':
                $n = $DB->count_records('local_gradesheet_transmute', ['courseid' => $courseid]);
                $DB->delete_records('local_gradesheet_transmute', ['courseid' => $courseid]);
                helper::reset_caches();
                $ok($scaleurl, $n ? 'Removed ' . $n . ' bracket(s); reverted to the default ESSU ranges.' : 'There were no custom brackets to remove.');
                break;

            // ── Grade item mapping ───────────────────────────────────────────
            case 'savemapping':
                $validcategories = $DB->get_records('local_gradesheet_categories', ['courseid' => $courseid], '', 'id');
                $dropped = 0;
                foreach ($gitems as $gitem) {
                    $period = optional_param('period_' . $gitem->id, 'finals', PARAM_ALPHA);
                    $catid  = optional_param('cat_' . $gitem->id, 0, PARAM_INT);
                    $period = in_array($period, ['midterm', 'finals'], true) ? $period : 'finals';
                    if ($catid > 0 && !isset($validcategories[$catid])) {
                        $catid = 0; // Category vanished between page load and save.
                        $dropped++;
                    }
                    $existing = $DB->get_record('local_gradesheet_itemmap', ['courseid' => $courseid, 'gradeitemid' => $gitem->id]);
                    if ($existing) {
                        $existing->period = $period;
                        $existing->categoryid = $catid;
                        $DB->update_record('local_gradesheet_itemmap', $existing);
                    } else {
                        $DB->insert_record('local_gradesheet_itemmap', (object)[
                            'courseid' => $courseid, 'gradeitemid' => $gitem->id, 'period' => $period, 'categoryid' => $catid,
                        ]);
                    }
                }
                helper::reset_caches();
                $warn = [];
                if ($dropped > 0) {
                    $warn[] = $dropped . ' item(s) pointed to a category that no longer exists and were left unmapped.';
                }
                $mw = helper::get_mapping_warnings($courseid);
                if ($mw) {
                    if ($mw['unmapped'] > 0) {
                        $warn[] = get_string('warnunmappeditems', 'local_gradesheet', $mw['unmapped']);
                    }
                    if ($mw['midterm'] === 0) {
                        $warn[] = get_string('warnnoperioditems', 'local_gradesheet', 'Midterm');
                    }
                    if ($mw['finals'] === 0) {
                        $warn[] = get_string('warnnoperioditems', 'local_gradesheet', 'Finals');
                    }
                }
                $ok($mapurl, 'Grade item mapping saved!', $warn);
                break;

            case 'automap':
                $count = helper::auto_map_unmapped_items($courseid);
                helper::reset_caches();
                if ($count > 0) {
                    $ok($mapurl, "Successfully auto-detected and mapped {$count} grade item(s)!");
                } else {
                    $ok($mapurl, 'All grade items are already mapped, or no unmapped items were found.');
                }
                break;

            case '':
                $fail($settingsurl, 'Nothing was submitted (the form had no action).');
                break;

            default:
                $fail($settingsurl, 'Unknown action "' . s($action) . '". Nothing was changed.');
        }
    } catch (\dml_exception $e) {
        // A database-level failure (constraint, connection, bad value) should
        // land back on the settings page, not on a Moodle error screen.
        $fail($settingsurl, 'Could not save because of a database error: ' . s($e->getMessage()) . ' Nothing was changed.');
    }
}

// ── LOAD DATA ─────────────────────────────────────────────────────────────────
$config     = $DB->get_record('local_gradesheet_config', ['courseid' => $courseid]);
$rules      = helper::get_computation_rules($courseid);
$health     = helper::settings_health($courseid);
$healthcounts = ['danger' => 0, 'warning' => 0, 'info' => 0];
foreach ($health as $h) {
    $healthcounts[$h['level']]++;
}
$detected   = helper::detect_signatories($courseid);
$fs         = helper::get_formula_settings($courseid);
$presets    = helper::formula_presets();
// Preview of the active formula at representative raw percentages.
$previewpoints = [100, 95, 90, 85, 80, 75, 74, 70, 60, 50, 0];
$coursegroups = groups_get_all_groups($courseid);
$groupcfgs  = [];
foreach ($DB->get_records('local_gradesheet_groupcfg', ['courseid' => $courseid]) as $gc) {
    $groupcfgs[$gc->groupid] = $gc;
}
$categories = $DB->get_records('local_gradesheet_categories', ['courseid' => $courseid], 'sortorder ASC');
$editcatid = optional_param('editcatid', 0, PARAM_INT);
$editcategory = $editcatid ? $DB->get_record('local_gradesheet_categories', ['id' => $editcatid, 'courseid' => $courseid]) : null;

$totalweight = 0;
foreach ($categories as $cat) $totalweight += $cat->weight;
$weightvalid = helper::validate_weight_sum($courseid);

$mappings = [];
$maps = $DB->get_records('local_gradesheet_itemmap', ['courseid' => $courseid]);
foreach ($maps as $map) {
    $mappings[$map->gradeitemid] = ['period' => $map->period, 'categoryid' => $map->categoryid];
}

$transmuterows  = $DB->get_records('local_gradesheet_transmute', ['courseid' => $courseid], 'minscore DESC');
$edittid        = optional_param('edittid', 0, PARAM_INT);
$edittransmute  = $edittid ? $DB->get_record('local_gradesheet_transmute', ['id' => $edittid, 'courseid' => $courseid]) : null;
$usingcustomscale = !empty($transmuterows);
$scalewarnings  = \local_gradesheet\helper::validate_custom_scale($courseid);
$displaylegend  = \local_gradesheet\helper::get_rating_legend($usingcustomscale ? $courseid : null);

echo $OUTPUT->header();
echo '<div class="local-gradesheet-page">';
?>

<div class="container mt-4">
    <div class="d-flex align-items-center justify-content-between flex-wrap">
        <div>
            <h2 class="mb-0">Grade Sheet Settings</h2>
            <div class="text-muted"><?php echo s(format_string($coursename)); ?> &mdash; everything you set here applies to every section of this course.</div>
        </div>
        <a href="index.php?courseid=<?php echo $courseid; ?>" class="btn btn-secondary mt-2">&larr; Back to the grade sheet</a>
    </div>
    <hr>

    <!-- Needs attention: everything that would make the printed sheet wrong, blocked, or incomplete -->
    <?php if (empty($health)): ?>
        <?php echo \local_gradesheet\helper::render_alert('<strong>All checks passed.</strong> Weights, mapping, formula, header and signatories are all in order.', 'success', '&#10003;'); ?>
    <?php else:
        $panelclass = $healthcounts['danger'] ? 'danger' : ($healthcounts['warning'] ? 'warning' : 'info');
        $icons = ['danger' => '&#10060;', 'warning' => '&#9888;', 'info' => '&#8505;'];
    ?>
        <div class="card mb-4 border-<?php echo $panelclass; ?>" id="needs-attention">
            <div class="card-header bg-<?php echo $panelclass; ?> <?php echo $panelclass === 'warning' ? 'text-dark' : 'text-white'; ?>">
                <strong>Needs attention</strong>
                <span class="small ml-2 ms-2">
                    <?php if ($healthcounts['danger']): ?><?php echo $healthcounts['danger']; ?> blocking &middot; <?php endif; ?>
                    <?php if ($healthcounts['warning']): ?><?php echo $healthcounts['warning']; ?> warning(s) &middot; <?php endif; ?>
                    <?php echo $healthcounts['info']; ?> note(s)
                </span>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($health as $h): ?>
                    <li class="list-group-item d-flex align-items-start py-2">
                        <span class="mr-2 me-2 text-<?php echo $h['level']; ?>" style="min-width:1.5em"><?php echo $icons[$h['level']]; ?></span>
                        <span class="flex-grow-1"><?php echo $h['text']; ?></span>
                        <a href="#<?php echo s($h['anchor']); ?>" class="btn btn-outline-secondary btn-sm ml-2 ms-2 text-nowrap">Fix &rarr;</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>


    <!-- Step navigation: the whole setup in order, with status from the health check -->
    <?php
    $stepdefs = [
        ['grade-categories',      '1', 'Categories & Weights'],
        ['grade-mapping',         '2', 'Map Grade Items'],
        ['transmutation-formula', '3', 'Grade Formula'],
        ['course-details',        '4', 'Details & Signatories'],
        ['computation-rules',     '5', 'Rules'],
        ['section-overrides',     '6', 'Sections'],
    ];
    $anchormap = [ // which health anchors roll up into which step
        'grade-categories' => 'grade-categories', 'grade-mapping' => 'grade-mapping',
        'transmutation-formula' => 'transmutation-formula', 'grading-scale' => 'transmutation-formula',
        'course-details' => 'course-details', 'sig-instructor' => 'course-details', 'sig-department_head' => 'course-details',
        'sig-registrar' => 'course-details', 'sig-college_dean' => 'course-details',
        'computation-rules' => 'computation-rules', 'section-overrides' => 'section-overrides',
    ];
    $stepstatus = [];
    foreach ($health as $h) {
        $st = $anchormap[$h['anchor']] ?? null;
        if (!$st) { continue; }
        $cur = $stepstatus[$st] ?? 'info';
        $rank = ['danger' => 3, 'warning' => 2, 'info' => 1];
        if (($rank[$h['level']] ?? 0) > ($rank[$cur] ?? 0)) { $stepstatus[$st] = $h['level']; }
    }
    $donecount = 0;
    foreach ($stepdefs as [$a]) { if (!isset($stepstatus[$a]) || $stepstatus[$a] === 'info') { $donecount++; } }
    ?>
    <nav class="gs-stepnav" aria-label="Setup steps">
        <div class="gs-stepnav-title">
            <strong>Set up in order</strong>
            <span class="text-muted small">&mdash; <?php echo $donecount; ?> of <?php echo count($stepdefs); ?> steps ready</span>
        </div>
        <ol class="gs-stepnav-list">
            <?php foreach ($stepdefs as [$anchor, $num, $label]):
                $st = $stepstatus[$anchor] ?? 'ok';
                $icon = ['danger' => '&#10005;', 'warning' => '&#9888;', 'info' => '&#10003;', 'ok' => '&#10003;'][$st];
            ?>
            <li class="gs-step gs-step-<?php echo $st; ?>">
                <a href="#<?php echo $anchor; ?>" title="<?php echo $st === 'danger' ? 'Blocks printing' : ($st === 'warning' ? 'Needs attention' : 'Ready'); ?>">
                    <span class="gs-step-num"><?php echo $num; ?></span>
                    <span class="gs-step-label"><?php echo $label; ?></span>
                    <span class="gs-step-icon"><?php echo $icon; ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ol>
    </nav>

    <!-- SECTION 2: Grade Categories -->
    <div class="card mb-4" id="grade-categories">
        <div class="card-header bg-dark text-white">
            <span class="gs-step-badge">1</span><strong>Grade Categories & Weights</strong><div class="gs-step-blurb">What counts toward the grade and by how much, e.g. Quizzes 30%, Activities 30%, Exams 40%. The weights must add up to 100%.</div>
        </div>
        <div class="card-body">
            <?php if (!$weightvalid['valid']): ?>
            <div class="alert alert-danger d-flex align-items-center" role="alert" style="font-size:16px; padding:15px 20px;">
                <span style="font-size:28px; margin-right:12px;">&#9888;</span>
                <div>
                    <strong>WARNING:</strong> Category weights must sum to exactly <strong>100%</strong>.
                    Current total: <strong><?php echo $totalweight; ?>%</strong>
                    <?php if ($weightvalid['count'] === 0): ?>
                        &mdash; You have <strong>no categories</strong> defined.
                    <?php endif; ?>
                    <br>You <strong>will not</strong> be able to print or export grades until this is corrected.
                </div>
            </div>
            <?php endif; ?>

            <p class="text-muted">Define the grading components and their percentage weights. Total must equal <strong>100%</strong>.</p>

            <!-- Existing categories -->
            <?php if (!empty($categories)): ?>
            <table class="table table-bordered table-sm mb-3">
                <thead class="thead-dark">
                    <tr>
                        <th>Category Name</th>
                        <th>Weight (%)</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $cat): 
                        $is_editing = ($editcategory && (int) $editcategory->id === (int) $cat->id);
                    ?>
                    <tr id="cat-view-<?php echo $cat->id; ?>" class="cat-view-row" style="<?php echo $is_editing ? 'display:none;' : ''; ?>">
                        <td><strong><?php echo s($cat->name); ?></strong></td>
                        <td><?php echo $cat->weight; ?>%</td>
                        <td>
                            <button type="button" class="btn btn-warning btn-sm" onclick="toggleEditCategory(<?php echo $cat->id; ?>, true)">Edit</button>
                            <?php if (count($categories) > 1): ?>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="action" value="deletecategory">
                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                <input type="hidden" name="catid" value="<?php echo $cat->id; ?>">
                                <button type="submit" class="btn btn-danger btn-sm"
                                        onclick="return confirm('Delete this category?')">Delete</button>
                            </form>
                            <?php else: ?>
                            <button type="button" class="btn btn-danger btn-sm disabled" tabindex="-1"
                                    title="<?php echo s(get_string('warnlastcategory', 'local_gradesheet')); ?>">Delete</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr id="cat-edit-<?php echo $cat->id; ?>" class="table-warning cat-edit-row" style="<?php echo $is_editing ? '' : 'display:none;'; ?>">
                        <td>
                            <form method="post" id="editcategoryform<?php echo $cat->id; ?>">
                                <input type="hidden" name="action" value="updatecategory">
                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                <input type="hidden" name="catid" value="<?php echo $cat->id; ?>">
                                <input type="text" name="catname" id="catname-<?php echo $cat->id; ?>" class="form-control form-control-sm" maxlength="100"
                                       value="<?php echo s($cat->name); ?>" data-original="<?php echo s($cat->name); ?>" required>
                            </form>
                        </td>
                        <td>
                            <input type="number" name="catweight" id="catweight-<?php echo $cat->id; ?>" class="form-control form-control-sm"
                                   form="editcategoryform<?php echo $cat->id; ?>"
                                   value="<?php echo s($cat->weight); ?>" data-original="<?php echo s($cat->weight); ?>" min="0" max="100" step="0.01" required>
                        </td>
                        <td>
                            <button type="submit" class="btn btn-primary btn-sm" form="editcategoryform<?php echo $cat->id; ?>">Save</button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="toggleEditCategory(<?php echo $cat->id; ?>, false)">Cancel</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if ($weightvalid['valid']): ?>
                    <tr class="table-success">
                        <td><strong>&#10003; Total</strong></td>
                        <td><strong><?php echo $totalweight; ?>% &mdash; valid</strong></td>
                        <td></td>
                    </tr>
                    <?php else: ?>
                    <tr class="table-danger">
                        <td><strong>&#9888; Total</strong></td>
                        <td><strong><?php echo $totalweight; ?>% &mdash; must be 100%</strong></td>
                        <td></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <!-- Add new category -->
            <form method="post" class="mt-3">
                <input type="hidden" name="action" value="addcategory">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <h6><strong>+ Add Category</strong></h6>
                <div class="form-row align-items-end">
                    <div class="col-md-5">
                        <label><strong>Category Name</strong></label>
                        <input type="text" name="catname" class="form-control" maxlength="100" required
                               placeholder="e.g. Quizzes, Exams, Projects, Attendance">
                    </div>
                    <div class="col-md-3">
                        <label><strong>Weight (%)</strong></label>
                        <input type="number" name="catweight" class="form-control"
                               placeholder="e.g. 30" min="0" max="100" step="0.01" required>
                    </div>
                    <div class="col-md-2 mt-2">
                        <button type="submit" class="btn btn-primary w-100">Add</button>
                    </div>
                </div>
            </form>
            <div class="gs-step-next text-right">
                <a href="#grade-mapping" class="btn btn-outline-primary btn-sm">Next: map your grade items &rarr;</a>
            </div>
        </div>
    </div>

    <!-- SECTION 3: Grade Item Mapping -->
    <div class="card mb-4" id="grade-mapping">
        <div class="card-header bg-dark text-white">
            <span class="gs-step-badge">2</span><strong>Grade Item Mapping</strong><div class="gs-step-blurb">Tell the sheet which category each Moodle activity belongs to and whether it is Midterm or Finals work. Unmapped items are ignored.</div>
        </div>
        <div class="card-body">
            <?php
            $mapwarn = helper::get_mapping_warnings($courseid);
            if ($mapwarn !== null && !empty($categories)
                    && ($mapwarn['unmapped'] > 0 || $mapwarn['midterm'] === 0 || $mapwarn['finals'] === 0)): ?>
                <div class="alert alert-warning">
                    <strong>WARNING:</strong>
                    <?php if ($mapwarn['unmapped'] > 0): ?>
                        <?php echo get_string('warnunmappeditems', 'local_gradesheet', $mapwarn['unmapped']); ?>
                    <?php endif; ?>
                    <?php if ($mapwarn['midterm'] === 0): ?>
                        <div><?php echo get_string('warnnoperioditems', 'local_gradesheet', 'Midterm'); ?></div>
                    <?php endif; ?>
                    <?php if ($mapwarn['finals'] === 0): ?>
                        <div><?php echo get_string('warnnoperioditems', 'local_gradesheet', 'Finals'); ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($categories)): ?>
                <?php echo \local_gradesheet\helper::render_alert("Add at least one grade category in <strong>Step 1</strong> first; then come back here to assign your activities to it.", "warning", "&#9888;", '<a href="#grade-categories" class="btn btn-light btn-sm">Go to Step 1</a>'); ?>
            <?php elseif (empty($gitems)): ?>
                <?php echo \local_gradesheet\helper::render_alert("Your Moodle gradebook has no graded activities yet, so there is nothing to map. Create quizzes, assignments or manual grade items in the course (Grades &rarr; Gradebook setup) and this list fills in automatically.", "info", "&#8505;"); ?>
            <?php else:
                $validcatids = array_keys($categories);
                $unmappedcount = 0;
                foreach ($gitems as $gitem) {
                    $cc = $mappings[$gitem->id]['categoryid'] ?? 0;
                    if (!$cc || !in_array((int)$cc, $validcatids, true)) { $unmappedcount++; }
                }
            ?>
                <p class="text-muted mb-2">
                    Assign each activity to a <strong>Category</strong> and a <strong>Period</strong>
                    (<em>Midterm</em> = work before the midterm exam, <em>Finals</em> = work after it).
                    Rows shaded yellow are not yet mapped and will not count.
                </p>
                <?php if ($unmappedcount > 0): ?>
                    <div class="gs-mapping-status text-warning mb-2">&#9888; <strong><?php echo $unmappedcount; ?></strong> of <?php echo count($gitems); ?> items still unmapped.</div>
                <?php else: ?>
                    <div class="gs-mapping-status text-success mb-2">&#10003; All <?php echo count($gitems); ?> items are mapped.</div>
                <?php endif; ?>
                <form method="post" id="automapForm" style="display:none;">
                    <input type="hidden" name="action" value="automap">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                </form>
                <form method="post" id="mappingForm">
                    <input type="hidden" name="action" value="savemapping">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

                    <div class="gs-bulk-tools form-inline mb-2 d-flex flex-wrap align-items-center">
                        <span class="mr-2 me-2 text-muted small">Quick fill:</span>
                        <select id="bulkCat" class="form-control form-control-sm mr-1 me-1" aria-label="Category for all unmapped items">
                            <option value="">Category for unmapped items&hellip;</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat->id; ?>"><?php echo s($cat->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-outline-secondary btn-sm mr-2 me-2" onclick="gsBulkCategory()">Apply to unmapped</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm mr-1 me-1" onclick="gsBulkPeriod('midterm')">All &rarr; Midterm</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm mr-3 me-3" onclick="gsBulkPeriod('finals')">All &rarr; Finals</button>
                        <button type="submit" form="automapForm" class="btn btn-sm btn-outline-primary ml-auto ms-auto" title="Automatically classifies quizzes, assignments, and exams based on activity type and title">&#9889; Auto-Detect &amp; Map Items</button>
                    </div>

                    <table class="table table-bordered table-sm gs-mapping-table">
                        <thead class="thead-dark">
                            <tr>
                                <th>Grade Item <small class="font-weight-normal">(from your gradebook)</small></th>
                                <th title="Highest possible score in Moodle; every item is converted to a percentage">Max Score</th>
                                <th>Category</th>
                                <th>Period</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($gitems as $gitem):
                                $curcat    = isset($mappings[$gitem->id]) ? (int)$mappings[$gitem->id]['categoryid'] : 0;
                                $curperiod = isset($mappings[$gitem->id]) ? $mappings[$gitem->id]['period']     : 'finals';
                                $ismapped  = $curcat && in_array($curcat, $validcatids, true);
                            ?>
                            <tr class="<?php echo $ismapped ? '' : 'table-warning'; ?>" data-gs-mapped="<?php echo $ismapped ? '1' : '0'; ?>">
                                <td><strong><?php echo format_string($gitem->itemname); ?></strong>
                                    <?php if (!empty($gitem->hidden)): ?><span class="badge badge-secondary ml-1 ms-1" title="Hidden from students in the gradebook">hidden</span><?php endif; ?>
                                </td>
                                <td><?php echo number_format($gitem->grademax, 0); ?></td>
                                <td>
                                    <select name="cat_<?php echo $gitem->id; ?>" class="form-control form-control-sm gs-map-cat" onchange="gsRowMapped(this)">
                                        <option value="0">-- Not counted --</option>
                                        <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat->id; ?>"
                                            <?php echo ($curcat == $cat->id) ? 'selected' : ''; ?>>
                                            <?php echo s($cat->name); ?> (<?php echo $cat->weight; ?>%)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select name="period_<?php echo $gitem->id; ?>" class="form-control form-control-sm gs-map-period">
                                        <option value="midterm" <?php echo ($curperiod === 'midterm') ? 'selected' : ''; ?>>Midterm</option>
                                        <option value="finals"  <?php echo ($curperiod === 'finals')  ? 'selected' : ''; ?>>Finals</option>
                                    </select>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <button type="submit" class="btn btn-primary">Save Mapping</button>
                    <span class="text-muted small ml-2 ms-2">Activities and quizzes you create in your course are automatically classified and mapped. You can review or adjust their mappings above at any time.</span>
                </form>
            <?php endif; ?>
            <div class="gs-step-next text-right">
                <a href="#transmutation-formula" class="btn btn-outline-primary btn-sm">Next: choose the grade formula &rarr;</a>
            </div>
        </div>
    </div>

    <!-- SECTION 4a: Transmutation Formula -->
    <div class="card mb-4" id="transmutation-formula">
        <div class="card-header bg-dark text-white">
            <span class="gs-step-badge">3</span><strong>Transmutation Formula (Percentage &rarr; Grade)</strong><div class="gs-step-blurb">How a percentage becomes the grade printed on the sheet. Pick a preset or write your own; the preview shows exactly what students will get.</div>
        </div>
        <div class="card-body">
            <p class="text-muted">
                This is the formula the plugin uses to turn a student's raw percentage <code>P</code> (0&ndash;100) into
                the grade printed on the sheet. Write it as an arithmetic expression in <code>P</code>; the result is
                clamped to the minimum/maximum below and rounded to the chosen decimals.
                Allowed: <code>+ - * / ^ ( )</code> and <code>min() max() round() floor() ceil() abs() sqrt()</code>.
            </p>
            <form method="post" id="formulaForm">
                <input type="hidden" name="action" value="saveformula">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

                <div class="form-group row mb-3">
                    <label class="col-sm-3 col-form-label"><strong>Method</strong></label>
                    <div class="col-sm-9">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="transmutemode" id="mode_formula" value="formula" <?php echo $fs['mode'] === 'formula' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="mode_formula"><strong>Formula</strong> &mdash; computed from the expression below (recommended)</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="transmutemode" id="mode_essu" value="essu" <?php echo $fs['mode'] !== 'formula' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="mode_essu"><strong>Built-in ESSU table</strong> &mdash; interpolates inside the registrar's rating brackets (legacy)</label>
                        </div>
                    </div>
                </div>

                <div class="form-group row mb-2">
                    <label class="col-sm-3 col-form-label" for="formula"><strong>Formula</strong></label>
                    <div class="col-sm-9">
                        <input type="text" class="form-control font-monospace" style="font-family:monospace" id="formula" name="formula" maxlength="255"
                               placeholder="e.g. 1 + (100 - P) * 0.08" value="<?php echo s($fs['formula']); ?>">
                        <small class="form-text text-muted">
                            Presets:
                            <?php foreach ($presets as $pk => $pr): ?>
                                <a href="#" class="badge badge-light border" onclick="gsApplyPreset('<?php echo $pk; ?>'); return false;"><?php echo s($pr['label']); ?></a>
                            <?php endforeach; ?>
                        </small>
                    </div>
                </div>

                <div class="form-group row mb-2">
                    <label class="col-sm-3 col-form-label"><strong>Clamp result</strong></label>
                    <div class="col-sm-3">
                        <input type="text" class="form-control" name="formulamin" id="formulamin" placeholder="min (blank = none)"
                               value="<?php echo $fs['min'] === null ? '' : s(rtrim(rtrim(number_format($fs['min'], 2, '.', ''), '0'), '.')); ?>">
                    </div>
                    <div class="col-sm-3">
                        <input type="text" class="form-control" name="formulamax" id="formulamax" placeholder="max (blank = none)"
                               value="<?php echo $fs['max'] === null ? '' : s(rtrim(rtrim(number_format($fs['max'], 2, '.', ''), '0'), '.')); ?>">
                    </div>
                    <div class="col-sm-3 col-form-label"><small class="text-muted">e.g. max 95 so nobody prints above 95</small></div>
                </div>

                <div class="form-group row mb-2">
                    <label class="col-sm-3 col-form-label" for="formuladecimals"><strong>Decimals shown</strong></label>
                    <div class="col-sm-3">
                        <select class="form-control" name="formuladecimals" id="formuladecimals">
                            <?php foreach ([0, 1, 2] as $d): ?>
                                <option value="<?php echo $d; ?>" <?php echo $fs['decimals'] === $d ? 'selected' : ''; ?>><?php echo $d; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <label class="col-sm-3 col-form-label text-sm-right" for="passmark"><strong>Passing mark (raw %)</strong></label>
                    <div class="col-sm-3">
                        <input type="number" step="0.01" min="0" max="100" class="form-control" name="passmark" id="passmark"
                               value="<?php echo s(rtrim(rtrim(number_format($fs['passmark'], 2, '.', ''), '0'), '.')); ?>" required>
                    </div>
                </div>

                <?php if ($fs['mode'] === 'formula'): ?>
                <div class="mt-3">
                    <h6 class="mb-2"><strong>Preview of the saved formula</strong> <code><?php echo s($fs['formula']); ?></code></h6>
                    <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-2 text-center" style="max-width:900px">
                        <thead class="thead-light">
                            <tr><th class="text-left">Raw %</th><?php foreach ($previewpoints as $pp): ?><th><?php echo $pp; ?></th><?php endforeach; ?></tr>
                        </thead>
                        <tbody>
                            <tr><th class="text-left">Grade</th>
                                <?php foreach ($previewpoints as $pp): ?>
                                    <td><strong><?php echo s(helper::transmute_equiv($pp, $courseid)); ?></strong></td>
                                <?php endforeach; ?>
                            </tr>
                            <tr><th class="text-left">Remarks</th>
                                <?php foreach ($previewpoints as $pp): ?>
                                    <td><?php echo helper::is_passing((float)$pp, $courseid) ? '<span class="badge badge-success">Pass</span>' : '<span class="badge badge-danger">Fail</span>'; ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <tr><th class="text-left">Rating</th>
                                <?php foreach ($previewpoints as $pp): ?>
                                    <td><small><?php echo s(helper::adjectival_rating($pp, $courseid)); ?></small></td>
                                <?php endforeach; ?>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                </div>
                <?php else: ?>
                    <?php echo \local_gradesheet\helper::render_alert("Currently using the <strong>built-in ESSU table</strong>. Pick <strong>Formula</strong>, enter or choose a preset, and save to switch.", "secondary"); ?>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary mt-2">Save Transmutation Settings</button>
            </form>
            <div class="gs-step-next text-right">
                <a href="#course-details" class="btn btn-outline-primary btn-sm">Next: fill in the report header &rarr;</a>
            </div>
        </div>
    </div>

    <!-- SECTION 4b: Adjectival rating brackets (legend) — advanced, collapsed unless in use -->
    <?php $scaleopen = $usingcustomscale || isset($stepstatus['transmutation-formula']) && in_array($stepstatus['transmutation-formula'], ['warning', 'danger'], true); ?>
    <details class="gs-advanced mb-4" id="grading-scale-wrap" <?php echo $scaleopen ? 'open' : ''; ?>>
        <summary class="gs-advanced-summary">
            <span class="gs-step-badge gs-step-badge-sub" style="background:#343a40;color:#fff">3b</span>
            <strong>Rating Brackets (Adjectival Legend)</strong>
            <span class="badge badge-light ml-2 ms-2">Optional</span>
            <span class="text-muted small ml-2 ms-2"><?php echo $usingcustomscale ? 'Custom ranges in use' : 'Using the standard ESSU ranges &mdash; open only if your college uses different words or ranges'; ?></span>
        </summary>
    <div class="card mt-2" id="grading-scale">
        <div class="card-header bg-dark text-white">
            <span class="gs-step-badge gs-step-badge-sub">3b</span><strong>Rating Brackets (Adjectival Legend)</strong> <span class="badge badge-light ml-2 ms-2">Optional</span><div class="gs-step-blurb">Optional. The words next to each grade range (Outstanding, Very Good, &hellip;). Leave empty to use the standard ESSU ranges.</div>
        </div>
        <div class="card-body">
            <p class="text-muted">
                <?php if ($fs['mode'] === 'formula'): ?>
                    In Formula mode these brackets <strong>do not compute grades</strong>. They supply the
                    <em>Adjectival Rating</em> (Outstanding, Excellent, &hellip;) for each raw-percentage range and the legend
                    printed on the sheet; the Equivalent column is filled from your formula automatically when left blank.
                    Leave the table empty to use ESSU's standard ranges.
                <?php else: ?>
                    By default this course uses ESSU's standard transmutation table. Add brackets below to define
                    your own scale instead; brackets are matched by the student's numeric average (0&ndash;100) falling between Min and Max.
                <?php endif; ?>
            </p>

            <?php if ($usingcustomscale): ?>
                <?php 
                    $reset_form = '<form method="post" onsubmit="return confirm(\'Delete all custom brackets and revert to the default ESSU scale?\');"><input type="hidden" name="action" value="resetscale"><input type="hidden" name="sesskey" value="' . sesskey() . '"><button type="submit" class="btn btn-danger btn-sm">Reset to Default Scale</button></form>';
                    echo \local_gradesheet\helper::render_alert("This course is using a <strong>custom</strong> grading scale.", "info", "✓", $reset_form);
                ?>
                
                <?php if (!empty($scalewarnings)): ?>
                    <?php
                        $warn_html = "<strong>Scale Configuration Warnings:</strong><ul class=\"mb-0 mt-2\">";
                        foreach ($scalewarnings as $warn) {
                            $warn_html .= "<li>" . $warn . "</li>";
                        }
                        $warn_html .= "</ul>";
                        echo \local_gradesheet\helper::render_alert($warn_html, "warning", "&#9888;");
                    ?>
                <?php endif; ?>

            <?php else: ?>
                <?php echo \local_gradesheet\helper::render_alert("Currently using the <strong>default ESSU</strong> scale (no custom brackets defined).", "secondary"); ?>
            <?php endif; ?>

            <table class="table table-bordered table-sm mb-3">
                <thead class="thead-dark">
                    <tr>
                        <th>Min Score</th>
                        <th>Max Score</th>
                        <th>Equivalent</th>
                        <th>Descriptor</th>
                        <?php if ($usingcustomscale): ?><th>Passing?</th><?php endif; ?>
                        <?php if ($usingcustomscale): ?><th>Action</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($usingcustomscale): ?>
                        <?php foreach ($transmuterows as $row): 
                            $is_editing_scale = ($edittransmute && (int) $edittransmute->id === (int) $row->id);
                        ?>
                        <tr id="scale-view-<?php echo $row->id; ?>" class="scale-view-row" style="<?php echo $is_editing_scale ? 'display:none;' : ''; ?>">
                            <td><?php echo s($row->minscore); ?></td>
                            <td><?php echo s($row->maxscore); ?></td>
                            <td><?php echo trim((string)$row->equivalent) !== '' ? s($row->equivalent) : '<span class="text-muted">raw score</span>'; ?></td>
                            <td><?php echo s($row->descriptor); ?></td>
                            <td><?php echo $row->ispassing ? '<span class="badge badge-success">Yes</span>' : '<span class="badge badge-secondary">No</span>'; ?></td>
                            <td>
                                <button type="button" class="btn btn-warning btn-sm mr-1 me-1" onclick="toggleEditScale(<?php echo $row->id; ?>, true)">Edit</button>
                                <form method="post" class="d-inline m-0" onsubmit="return confirm('Delete this grading bracket?');">
                                    <input type="hidden" name="action" value="deletetransmute">
                                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                    <input type="hidden" name="tid" value="<?php echo $row->id; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <tr id="scale-edit-<?php echo $row->id; ?>" class="table-warning scale-edit-row" style="<?php echo $is_editing_scale ? '' : 'display:none;'; ?>">
                            <td>
                                <form method="post" id="edittransmuteform<?php echo $row->id; ?>">
                                    <input type="hidden" name="action" value="updatetransmute">
                                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                    <input type="hidden" name="tid" value="<?php echo $row->id; ?>">
                                    <input type="number" step="0.01" name="tmin" id="scalemin-<?php echo $row->id; ?>" class="form-control form-control-sm"
                                           value="<?php echo s($row->minscore); ?>" data-original="<?php echo s($row->minscore); ?>" required>
                                </form>
                            </td>
                            <td>
                                <input type="number" step="0.01" name="tmax" id="scalemax-<?php echo $row->id; ?>" class="form-control form-control-sm"
                                       form="edittransmuteform<?php echo $row->id; ?>"
                                       value="<?php echo s($row->maxscore); ?>" data-original="<?php echo s($row->maxscore); ?>" required>
                            </td>
                            <td>
                                <input type="text" name="tequiv" id="scaleequiv-<?php echo $row->id; ?>" class="form-control form-control-sm" maxlength="10"
                                       form="edittransmuteform<?php echo $row->id; ?>" placeholder="e.g. 1.25"
                                       value="<?php echo s($row->equivalent); ?>" data-original="<?php echo s($row->equivalent); ?>">
                            </td>
                            <td>
                                <input type="text" name="tdesc" id="scaledesc-<?php echo $row->id; ?>" class="form-control form-control-sm" maxlength="100"
                                       form="edittransmuteform<?php echo $row->id; ?>"
                                       value="<?php echo s($row->descriptor); ?>" data-original="<?php echo s($row->descriptor); ?>">
                            </td>
                            <td>
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" name="tispassing" id="scalepass-<?php echo $row->id; ?>" value="1"
                                           form="edittransmuteform<?php echo $row->id; ?>"
                                           data-original="<?php echo $row->ispassing ? '1' : '0'; ?>"
                                           <?php echo $row->ispassing ? 'checked' : ''; ?>>
                                </div>
                            </td>
                            <td>
                                <button type="submit" class="btn btn-primary btn-sm mr-1 me-1" form="edittransmuteform<?php echo $row->id; ?>">Save</button>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="toggleEditScale(<?php echo $row->id; ?>, false)">Cancel</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php foreach ($displaylegend as $lrow): ?>
                        <tr class="text-muted">
                            <td colspan="2"><?php echo s($lrow[0]); ?></td>
                            <td><?php echo s($lrow[1]); ?></td>
                            <td colspan="2"><?php echo s($lrow[2]); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Add new bracket -->
            <form method="post" class="mt-3">
                <input type="hidden" name="action" value="addtransmute">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <h6><strong>+ Add Bracket</strong></h6>
                <p class="text-muted small"><?php echo $fs['mode'] === 'formula'
                    ? 'Brackets only define ranges and their adjectival rating. Equivalent is optional (computed from the formula when blank).'
                    : 'Adding your first bracket switches this course to a custom scale.'; ?></p>
                <div class="form-row align-items-end">
                    <div class="col-md-3">
                        <label><strong>Min Score</strong></label>
                        <input type="number" step="0.01" name="tmin" class="form-control" placeholder="e.g. 90" required>
                    </div>
                    <div class="col-md-3">
                        <label><strong>Max Score</strong></label>
                        <input type="number" step="0.01" name="tmax" class="form-control" placeholder="e.g. 100" required>
                    </div>
                    <div class="col-md-2">
                        <label><strong>Equivalent</strong></label>
                        <input type="text" name="tequiv" class="form-control" maxlength="10" placeholder="e.g. 1.25">
                    </div>
                    <div class="col-md-4">
                        <label><strong>Descriptor</strong></label>
                        <input type="text" name="tdesc" class="form-control" maxlength="100" placeholder="e.g. Outstanding">
                    </div>
                    <div class="col-md-2 mt-2">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="tispassing" value="1" checked>
                            <label class="form-check-label"><strong>Counts as Passing</strong></label>
                        </div>
                    </div>
                    <div class="col-md-2 mt-2">
                        <button type="submit" class="btn btn-primary w-100">Add</button>
                    </div>
                </div>
            </form>
            <div class="gs-step-next text-right">
                <a href="#course-details" class="btn btn-outline-primary btn-sm">Next: fill in the report header &rarr;</a>
            </div>
        </div>
    </div>
    </details>

    <!-- SECTION 1: Course Details -->
    <div class="card mb-4" id="course-details">
        <div class="card-header bg-dark text-white">
            <span class="gs-step-badge">4</span><strong>Course Details & Signatories</strong><div class="gs-step-blurb">What prints at the top and bottom of the official sheet. Signatories are filled in automatically from Moodle roles when left blank.</div>
        </div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="action" value="savedetails">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

                <?php if (!class_exists('\\core_course\\hook\\after_form_definition')): ?>
                    <p class="text-muted small">This Moodle version (older than 4.4) has no course-form hook, so these fields are edited here only.</p>
                <?php endif; ?>
                <h6 class="text-muted mb-3">- Report Header -</h6>
                <div class="form-group row mb-3">
                    <label class="col-sm-4 col-form-label"><strong>Semester</strong></label>
                    <div class="col-sm-5">
                        <select name="semester" class="form-control">
                            <?php foreach (['First Semester','Second Semester','Summer'] as $s): ?>
                            <option value="<?php echo $s; ?>" <?php echo ($config && $config->semester === $s) ? 'selected' : ''; ?>>
                                <?php echo $s; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group row mb-3">
                    <label class="col-sm-4 col-form-label"><strong>School Year</strong></label>
                    <div class="col-sm-4">
                        <input type="text" name="schoolyear" class="form-control" maxlength="20" required
                               pattern="\d{4}\s*-\s*\d{4}" title="Two consecutive years, e.g. 2026-2027" placeholder="e.g. <?php echo date('Y') . '-' . (date('Y') + 1); ?>"
                               value="<?php echo $config ? s($config->schoolyear) : date('Y') . '-' . (date('Y') + 1); ?>">
                    </div>
                </div>

                <hr><h6 class="text-muted mb-3">- Course Information -</h6>

                <?php
                // [label, placeholder, maxlen, required, hint]
                $fields = [
                    'coursenumber'  => ['Subject and Course No.', 'e.g. CS 101', 50, true, ''],
                    'descriptive'   => ['Descriptive Title',      'e.g. Computer Programming 1', 100, true, ''],
                    'courseandyear' => ['Course and Year',         'e.g. BSCS 2A', 50, false, empty($coursegroups) ? '' : 'Overridden per section by the group name (see Per-Section Overrides).'],
                    'schedule'      => ['Schedule of Classes',     'e.g. MWF 8:00-9:00 AM', 50, false, empty($coursegroups) ? '' : 'Course-wide default; sections can override it below.'],
                ];
                foreach ($fields as $fname => [$label, $placeholder, $maxlen, $req, $hint]):
                ?>
                <div class="form-group row mb-3">
                    <label class="col-sm-4 col-form-label"><strong><?php echo $label; ?></strong><?php echo $req ? ' <span class="text-danger">*</span>' : ''; ?></label>
                    <div class="col-sm-6">
                        <input type="text" name="<?php echo $fname; ?>" class="form-control" maxlength="<?php echo $maxlen; ?>" <?php echo $req ? 'required' : ''; ?>
                               value="<?php echo $config && isset($config->$fname) ? s($config->$fname) : ''; ?>"
                               placeholder="<?php echo $placeholder; ?>">
                        <?php if ($hint !== ''): ?><small class="form-text text-muted"><?php echo $hint; ?></small><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="form-group row mb-3">
                    <label class="col-sm-4 col-form-label"><strong>Number of Units</strong> <span class="text-danger">*</span></label>
                    <div class="col-sm-3">
                        <input type="number" name="units" class="form-control" min="0" max="12" step="0.5" required placeholder="e.g. 3"
                               value="<?php echo $config && isset($config->units) ? s($config->units) : '3'; ?>">
                    </div>
                </div>

                <hr><h6 class="text-muted mb-1">- Signatories -</h6>
                <p class="text-muted small mb-3">
                    Leave a field <strong>blank</strong> to use the name auto-detected from Moodle roles
                    (Instructor = the course/section teacher; the others = holders of the Department Head,
                    Registrar and College Dean roles in this course's category tree or the site).
                    Type a name only to override.
                </p>

                <?php
                $sigs = [
                    'instructor'      => 'Instructor',
                    'department_head' => 'Department Head',
                    'registrar'       => 'Registrar',
                    'college_dean'    => 'College Dean',
                ];
                foreach ($sigs as $fname => $label):
                    $stored  = ($config && isset($config->$fname)) ? (string)$config->$fname : '';
                    $isblank = helper::signatory_is_blank($stored);
                    $det     = $detected[$fname];
                ?>
                <div class="form-group row mb-3">
                    <label class="col-sm-4 col-form-label"><strong><?php echo $label; ?></strong></label>
                    <div class="col-sm-6">
                        <input type="text" name="<?php echo $fname; ?>" id="sig-<?php echo $fname; ?>" class="form-control" maxlength="100"
                               value="<?php echo $isblank ? '' : s($stored); ?>"
                               placeholder="<?php echo $det['name'] !== '' ? s($det['name']) : 'Auto-detect (nothing found yet)'; ?>">
                        <small class="form-text">
                            <?php if ($det['name'] !== ''): ?>
                                <span class="text-success">&#10003; Auto-detected: <strong><?php echo s($det['name']); ?></strong></span>
                                <span class="text-muted">(<?php echo s($det['source']); ?>)</span>
                                <?php if (!$isblank): ?>
                                    &mdash; <span class="text-warning">overridden by the typed name</span>
                                    <a href="#" onclick="document.getElementById('sig-<?php echo $fname; ?>').value=''; return false;">use auto-detect</a>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">&#9888; Not detected: <?php echo s($det['source']); ?>.<?php echo $isblank ? ' This line will print blank until a role is assigned or a name is typed.' : ''; ?></span>
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
                <?php endforeach; ?>

                <button type="submit" class="btn btn-primary">Save Course Details</button>
            </form>
            <div class="gs-step-next text-right">
                <a href="#computation-rules" class="btn btn-outline-primary btn-sm">Next: review computation rules &rarr;</a>
            </div>
        </div>
    </div>

    <!-- SECTION 1b: Computation Rules -->
    <div class="card mb-4" id="computation-rules">
        <div class="card-header bg-dark text-white">
            <span class="gs-step-badge">5</span><strong>Computation Rules</strong> <span class="badge badge-light ml-2 ms-2">Optional</span><div class="gs-step-blurb">Optional switches for edge cases: ungraded work, hidden items, midterm/finals weighting and rounding. The defaults are safe; revisit before finalizing.</div>
        </div>
        <div class="card-body">
            <p class="text-muted">
                These switches control how the plugin turns gradebook scores into period grades. They apply to
                every section of this course and are shown on the grade sheet page so faculty always know which
                rules produced the numbers.
            </p>
            <form method="post">
                <input type="hidden" name="action" value="saverules">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

                <div class="form-check mb-2">
                    <input type="checkbox" class="form-check-input" id="missingaszero" name="missingaszero" value="1"
                           <?php echo $rules['missingaszero'] ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="missingaszero">
                        <strong>Count ungraded items as 0%</strong>
                        <br><small class="text-muted">Off: a mapped item with no grade is skipped, so a student who completed 1 of 5 quizzes is averaged over 1 quiz. On: missing work counts as zero. Turn this on when finalizing the official sheet.</small>
                    </label>
                </div>

                <div class="form-check mb-2">
                    <input type="checkbox" class="form-check-input" id="includehidden" name="includehidden" value="1"
                           <?php echo $rules['includehidden'] ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="includehidden">
                        <strong>Include hidden grade items in faculty computation</strong>
                        <br><small class="text-muted">Items hidden from students in the gradebook still count on the faculty grade sheet and exports. The student's own view never includes hidden items.</small>
                    </label>
                </div>

                <div class="form-check mb-3">
                    <input type="checkbox" class="form-check-input" id="roundaverage" name="roundaverage" value="1"
                           <?php echo $rules['roundaverage'] ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="roundaverage">
                        <strong>Round averages to whole numbers before transmutation</strong>
                        <br><small class="text-muted">Off: 89.6 transmutes as 89.6 (1.6). On: 89.6 rounds to 90 first (1.5). Match this to the Registrar's rule.</small>
                    </label>
                </div>

                <div class="form-group row mb-3">
                    <label class="col-sm-4 col-form-label" for="midtermweight"><strong>Midterm share of final average (%)</strong></label>
                    <div class="col-sm-3">
                        <input type="number" step="0.01" min="0" max="100" class="form-control" id="midtermweight" name="midtermweight"
                               value="<?php echo s(rtrim(rtrim(number_format($rules['midtermweight'], 2), '0'), '.')); ?>" required>
                    </div>
                    <div class="col-sm-5 col-form-label">
                        <small class="text-muted">Finals share is 100 minus this. 50 = equal weighting; 33.33 = one-third / two-thirds.</small>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Save Computation Rules</button>
            </form>
            <div class="gs-step-next text-right">
                <a href="#section-overrides" class="btn btn-outline-primary btn-sm">Next: sections &rarr;</a>
            </div>
        </div>
    </div>

    <!-- SECTION 1c: Per-section overrides -->
    <div class="card mb-4" id="section-overrides">
        <div class="card-header bg-dark text-white">
            <span class="gs-step-badge">6</span><strong>Per-Section Overrides</strong> <span class="badge badge-light ml-2 ms-2">Optional</span><div class="gs-step-blurb">Only needed when one Moodle course holds several sections (groups). Each section can print its own label, schedule and instructor.</div>
        </div>
        <div class="card-body">
            <?php if (empty($coursegroups)): ?>
                <?php echo \local_gradesheet\helper::render_alert("This course has no groups. If one Moodle course holds several sections, create one group per section (Participants &rarr; Groups); each group then gets its own grade sheet with its own section label, schedule and instructor line.", "secondary"); ?>
            <?php else: ?>
                <p class="text-muted">
                    Each group is treated as a section. Leave a field blank to use the course-wide value from
                    Course Details (section label defaults to the group name; instructor defaults to the group's
                    teacher when there is exactly one). Grading categories, weights and the scale are shared by all sections.
                </p>
                <table class="table table-bordered table-sm mb-0">
                    <thead class="thead-dark">
                        <tr><th>Group / Section</th><th>Section label</th><th>Schedule</th><th>Instructor</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($coursegroups as $grp):
                        $gc = $groupcfgs[$grp->id] ?? null; ?>
                        <tr>
                            <td>
                                <form method="post" id="groupcfg<?php echo $grp->id; ?>">
                                    <input type="hidden" name="action" value="savegroupcfg">
                                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                    <input type="hidden" name="groupid" value="<?php echo $grp->id; ?>">
                                </form>
                                <strong><?php echo s(format_string($grp->name)); ?></strong>
                            </td>
                            <td><input type="text" name="g_courseandyear" form="groupcfg<?php echo $grp->id; ?>" class="form-control form-control-sm" maxlength="50"
                                       placeholder="<?php echo s(format_string($grp->name)); ?>" value="<?php echo $gc ? s($gc->courseandyear) : ''; ?>"></td>
                            <td><input type="text" name="g_schedule" form="groupcfg<?php echo $grp->id; ?>" class="form-control form-control-sm" maxlength="50"
                                       placeholder="<?php echo $config ? s($config->schedule) : ''; ?>" value="<?php echo $gc ? s($gc->schedule) : ''; ?>"></td>
                            <td><input type="text" name="g_instructor" form="groupcfg<?php echo $grp->id; ?>" class="form-control form-control-sm" maxlength="100"
                                       placeholder="<?php echo $config ? s($config->instructor) : ''; ?>" value="<?php echo $gc ? s($gc->instructor) : ''; ?>"></td>
                            <td><button type="submit" form="groupcfg<?php echo $grp->id; ?>" class="btn btn-primary btn-sm">Save</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <div class="gs-step-next text-right">
                <a href="#needs-attention" class="btn btn-outline-primary btn-sm">Back to top &rarr;</a>
            </div>
        </div>
    </div>

</div>

<script>
// If a "Fix" link or the step bar points inside a collapsed section, open it first.
function gsRevealHash() {
    var id = (location.hash || '').replace('#', '');
    if (!id) { return; }
    var el = document.getElementById(id);
    if (!el) { return; }
    var d = el.closest('details');
    if (d && !d.open) { d.open = true; setTimeout(function () { el.scrollIntoView({block: 'start'}); }, 0); }
}
window.addEventListener('hashchange', gsRevealHash);
window.addEventListener('load', gsRevealHash);
function gsRowMapped(sel) {
    var tr = sel.closest('tr');
    var mapped = sel.value !== '0' && sel.value !== '';
    tr.classList.toggle('table-warning', !mapped);
    tr.setAttribute('data-gs-mapped', mapped ? '1' : '0');
}
function gsBulkCategory() {
    var cat = document.getElementById('bulkCat').value;
    if (!cat) { alert('Choose a category first.'); return; }
    document.querySelectorAll('.gs-mapping-table tr[data-gs-mapped="0"] .gs-map-cat').forEach(function (sel) {
        sel.value = cat; gsRowMapped(sel);
    });
}
function gsBulkPeriod(period) {
    document.querySelectorAll('.gs-mapping-table .gs-map-period').forEach(function (sel) { sel.value = period; });
}
var gsFormulaPresets = <?php echo json_encode($presets); ?>;
function gsApplyPreset(key) {
    var p = gsFormulaPresets[key];
    if (!p) { return; }
    document.getElementById('mode_formula').checked = true;
    document.getElementById('formula').value = p.formula;
    document.getElementById('formulamin').value = p.min;
    document.getElementById('formulamax').value = p.max;
    document.getElementById('formuladecimals').value = String(p.decimals);
    document.getElementById('passmark').value = p.passmark;
}
function toggleEditCategory(catId, showEdit) {
    var viewRow = document.getElementById('cat-view-' + catId);
    var editRow = document.getElementById('cat-edit-' + catId);
    if (!viewRow || !editRow) return;

    if (showEdit) {
        viewRow.style.display = 'none';
        editRow.style.display = '';
        var input = document.getElementById('catname-' + catId);
        if (input) {
            input.focus();
            input.select();
        }
    } else {
        var nameInput = document.getElementById('catname-' + catId);
        var weightInput = document.getElementById('catweight-' + catId);
        if (nameInput && nameInput.dataset.original !== undefined) {
            nameInput.value = nameInput.dataset.original;
        }
        if (weightInput && weightInput.dataset.original !== undefined) {
            weightInput.value = weightInput.dataset.original;
        }
        editRow.style.display = 'none';
        viewRow.style.display = '';
    }
}

function toggleEditScale(tid, showEdit) {
    var viewRow = document.getElementById('scale-view-' + tid);
    var editRow = document.getElementById('scale-edit-' + tid);
    if (!viewRow || !editRow) return;

    if (showEdit) {
        viewRow.style.display = 'none';
        editRow.style.display = '';
        var input = document.getElementById('scalemin-' + tid);
        if (input) {
            input.focus();
            input.select();
        }
    } else {
        var minInput = document.getElementById('scalemin-' + tid);
        var maxInput = document.getElementById('scalemax-' + tid);
        var descInput = document.getElementById('scaledesc-' + tid);
        var equivInput = document.getElementById('scaleequiv-' + tid);
        var passInput = document.getElementById('scalepass-' + tid);

        if (minInput && minInput.dataset.original !== undefined) {
            minInput.value = minInput.dataset.original;
        }
        if (maxInput && maxInput.dataset.original !== undefined) {
            maxInput.value = maxInput.dataset.original;
        }
        if (descInput && descInput.dataset.original !== undefined) {
            descInput.value = descInput.dataset.original;
        }
        if (equivInput && equivInput.dataset.original !== undefined) {
            equivInput.value = equivInput.dataset.original;
        }
        if (passInput && passInput.dataset.original !== undefined) {
            passInput.checked = (passInput.dataset.original === '1');
        }
        editRow.style.display = 'none';
        viewRow.style.display = '';
    }
}
</script>

<?php echo '</div>';
echo $OUTPUT->footer(); ?>