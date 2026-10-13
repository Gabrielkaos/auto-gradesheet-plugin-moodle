<?php
/**
 * Extended batteries (13-19) for the heavy test suite. Included by
 * heavy_test_suite.php in global scope, so $T, $DB, $USER and the MOCK_*
 * globals are shared.
 *
 * Each battery uses its own course id range so nothing leaks between them:
 *   13xx roster matrix, 14xx gradebook edge cases, 15xx transmutation matrix,
 *   17xx status, 18xx defaults/observers, 19xx worked example.
 */

defined('MOODLE_INTERNAL') || die();

use local_gradesheet\helper;
use local_gradesheet\formula;
use local_gradesheet\observer;
use local_gradesheet\gradesheet_service;

/** Resets the mutable mock environment to a neutral state. */
function mock_reset_scenario(): void {
    global $USER, $MOCK_COURSE_GROUPMODE, $MOCK_ACTIVE_GROUP, $MOCK_CAPABILITIES, $MOCK_CONFIG;
    $USER = (object)['id' => 999];
    $MOCK_COURSE_GROUPMODE = NOGROUPS;
    $MOCK_ACTIVE_GROUP = 0;
    $MOCK_CAPABILITIES = [];
    $MOCK_CONFIG = [];
    helper::reset_caches();
}

/** Inserts a user row and enrols them in a course. */
function mock_user(int $id, string $firstname, string $lastname, int $courseid, array $caps = []): void {
    global $DB, $MOCK_ENROLLED_USERS, $MOCK_CAPABILITIES;
    if (!isset($DB->tables['user'][$id])) {
        $DB->insert_record('user', (object)['id' => $id, 'firstname' => $firstname, 'lastname' => $lastname, 'idnumber' => 'ID' . $id]);
    }
    $MOCK_ENROLLED_USERS[$courseid][$id] = true;
    foreach ($caps as $cap) {
        $MOCK_CAPABILITIES["{$cap}:{$id}"] = true;
    }
}

/** Grants the standard editing-teacher capabilities. */
function mock_teacher_caps(int $id): void {
    global $MOCK_CAPABILITIES;
    $MOCK_CAPABILITIES["local/gradesheet:manage:{$id}"] = true;
    $MOCK_CAPABILITIES["moodle/grade:viewall:{$id}"] = true;
}

/** Returns the roster as "Last, First" strings in order; accepts service rows or user objects. */
function roster_names(array $rows): array {
    return array_values(array_map(function ($r) {
        return is_object($r) ? ($r->lastname . ', ' . $r->firstname) : $r['name'];
    }, $rows));
}

/** Convenience: insert a numeric grade item + mapping and return its id. */
function mock_item(int $courseid, string $name, string $period, int $catid, float $grademax = 100.0, array $extra = []): int {
    global $DB;
    $gi = $DB->insert_record('grade_items', (object)array_merge([
        'courseid' => $courseid, 'itemtype' => 'mod', 'itemname' => $name, 'gradetype' => 1, 'grademax' => $grademax,
    ], $extra));
    if ($catid !== 0 || isset($extra['forcemap'])) {
        $DB->insert_record('local_gradesheet_itemmap', (object)['courseid' => $courseid, 'gradeitemid' => $gi, 'period' => $period, 'categoryid' => $catid]);
    }
    return $gi;
}

function mock_grade(int $itemid, int $userid, $finalgrade, array $extra = []): void {
    global $DB;
    $DB->insert_record('grade_grades', (object)array_merge(['itemid' => $itemid, 'userid' => $userid, 'finalgrade' => $finalgrade], $extra));
}

function mock_config(int $courseid, array $overrides = []): void {
    global $DB;
    $base = [
        'courseid' => $courseid, 'semester' => 'First Semester', 'schoolyear' => '2026-2027',
        'coursenumber' => 'C' . $courseid, 'descriptive' => 'Course ' . $courseid, 'courseandyear' => '',
        'schedule' => 'MW 8:00-9:30 AM', 'units' => '3', 'instructor' => '', 'department_head' => 'DH',
        'registrar' => 'REG', 'college_dean' => 'DEAN', 'missingaszero' => 0, 'includehidden' => 1,
        'midtermweight' => 50.0, 'roundaverage' => 0, 'transmutemode' => 'essu', 'formula' => '',
        'formulamin' => null, 'formulamax' => null, 'formuladecimals' => 1, 'passmark' => 75.0,
    ];
    $DB->insert_record('local_gradesheet_config', (object)array_merge($base, $overrides));
}

function mock_set_config(int $courseid, array $fields): void {
    global $DB;
    $cfg = $DB->get_record('local_gradesheet_config', ['courseid' => $courseid]);
    foreach ($fields as $k => $v) {
        $cfg->$k = $v;
    }
    $DB->update_record('local_gradesheet_config', $cfg);
    helper::reset_caches();
}

// =========================================================================
echo "\n======================================================================\n";
echo "BATTERY 13: Multi-Section / Multi-Teacher Roster Matrix\n";
echo "======================================================================\n";
mock_reset_scenario();
$c13 = 1301;
$DB->insert_record('course', (object)['id' => $c13, 'fullname' => 'Sections Course', 'shortname' => 'SEC1301']);
mock_config($c13);
$cat13 = $DB->insert_record('local_gradesheet_categories', (object)['courseid' => $c13, 'name' => 'All', 'weight' => 100.0, 'sortorder' => 0]);
$exam13 = mock_item($c13, 'Final Exam', 'finals', $cat13);

// Staff: site admin (id 1), three editing teachers, one non-editing teacher.
mock_user(1,    'Site',   'Admin',   $c13); mock_teacher_caps(1);
mock_user(1310, 'Teodoro', 'Uno',    $c13); mock_teacher_caps(1310);
mock_user(1311, 'Teresa',  'Dos',    $c13); mock_teacher_caps(1311);
mock_user(1312, 'Tomas',   'Tres',   $c13); mock_teacher_caps(1312);
mock_user(1313, 'Nena',    'Viewer', $c13, ['moodle/grade:viewall']);
// Students, inserted out of alphabetical order on purpose.
mock_user(1327, 'Gil',   'Gomez',    $c13);
mock_user(1321, 'Ana',   'Abad',     $c13);
mock_user(1325, 'Eva',   'Enriquez', $c13);
mock_user(1323, 'Carla', 'Cruz',     $c13);
mock_user(1326, 'Fe',    'Flores',   $c13);
mock_user(1322, 'Ben',   'Bautista', $c13);
mock_user(1324, 'Dan',   'Dizon',    $c13);
foreach ([1321 => 90, 1322 => 70, 1323 => 80, 1324 => 60, 1325 => 85, 1326 => 75, 1327 => 50] as $uid => $score) {
    mock_grade($exam13, $uid, $score);
}
// Groups (sections): A taught by T1, B by T2, C co-taught by T1 and T2.
$gA = $DB->insert_record('groups', (object)['courseid' => $c13, 'name' => 'BSCS 1A']);
$gB = $DB->insert_record('groups', (object)['courseid' => $c13, 'name' => 'BSCS 1B']);
$gC = $DB->insert_record('groups', (object)['courseid' => $c13, 'name' => 'BSCS 1C']);
$MOCK_GROUP_MEMBERS = array_merge($MOCK_GROUP_MEMBERS, [
    "{$gA}:1310", "{$gB}:1311", "{$gC}:1310", "{$gC}:1311",
    "{$gA}:1321", "{$gA}:1322", "{$gA}:1325",
    "{$gB}:1323", "{$gB}:1324", "{$gB}:1325",
    "{$gC}:1327",
]);
$ctx13 = context_course::instance($c13);

// 13a. NOGROUPS whole-course view.
$USER = (object)['id' => 1310];
$all = gradesheet_service::compute_all_grades($c13, 0);
$T->assertEqual("Roster: whole course lists the 7 students only (admin, 3 teachers, non-editing teacher excluded)",
    roster_names($all['rows']), ['Abad, Ana', 'Bautista, Ben', 'Cruz, Carla', 'Dizon, Dan', 'Enriquez, Eva', 'Flores, Fe', 'Gomez, Gil']);
$T->assertEqual("Roster: alphabetical by surname regardless of enrolment order", $all['rows'][0]['idnumber'], 'ID1321');
$T->assertRow("Roster: course-wide pass/fail (>=75 passes)", $all, ['total' => 7, 'passcount' => 4, 'failcount' => 3, 'othercount' => 0, 'passrate' => 57.1]);

// 13b. Per-section sheets.
$secA = gradesheet_service::compute_all_grades($c13, $gA);
$secB = gradesheet_service::compute_all_grades($c13, $gB);
$secC = gradesheet_service::compute_all_grades($c13, $gC);
$T->assertEqual("Section A roster", roster_names($secA['rows']), ['Abad, Ana', 'Bautista, Ben', 'Enriquez, Eva']);
$T->assertEqual("Section B roster", roster_names($secB['rows']), ['Cruz, Carla', 'Dizon, Dan', 'Enriquez, Eva']);
$T->assertEqual("Section C roster", roster_names($secC['rows']), ['Gomez, Gil']);
$T->assertRow("Section A statistics are section-local", $secA, ['total' => 3, 'passcount' => 2, 'failcount' => 1, 'passrate' => 66.7]);
$T->assertRow("Section B statistics are section-local", $secB, ['total' => 3, 'passcount' => 2, 'failcount' => 1, 'passrate' => 66.7]);
$T->assertRow("Section C statistics are section-local", $secC, ['total' => 1, 'passcount' => 0, 'failcount' => 1, 'passrate' => 0.0]);
$T->assert("A student in two groups appears on both section sheets",
    in_array('Enriquez, Eva', roster_names($secA['rows'])) && in_array('Enriquez, Eva', roster_names($secB['rows'])));
$T->assert("A student in no group appears only on the whole-course sheet",
    in_array('Flores, Fe', roster_names($all['rows'])) && !in_array('Flores, Fe', array_merge(roster_names($secA['rows']), roster_names($secB['rows']), roster_names($secC['rows']))));
$T->assertEqual("Section label comes from the group name", [$secA['courseandyear'], $secB['courseandyear'], $secC['courseandyear']], ['BSCS 1A', 'BSCS 1B', 'BSCS 1C']);
$T->assertEqual("Identical grade for the shared student on both sheets (one formula per course)",
    [$secA['rows'][2]['average'], $secB['rows'][2]['average']], ['2.0', '2.0']);

// 13c. Instructor line per section and per viewer.
$T->assertEqual("Instructor A = its only teacher (viewer is that teacher)", $secA['instructor'], 'TEODORO UNO');
$T->assertEqual("Instructor B = its only teacher even though the viewer is a different teacher", $secB['instructor'], 'TERESA DOS');
$T->assertEqual("Instructor C (co-taught) = the viewing teacher T1", $secC['instructor'], 'TEODORO UNO');
$USER = (object)['id' => 1311];
$secC2 = gradesheet_service::compute_all_grades($c13, $gC);
$T->assertEqual("Instructor C (co-taught) = the viewing teacher T2", $secC2['instructor'], 'TERESA DOS');
$USER = (object)['id' => 1];
$secC3 = gradesheet_service::compute_all_grades($c13, $gC);
$T->assertEqual("Instructor C viewed by an admin: two teachers, no typed name -> blank (never guesses)", $secC3['instructor'], '');
$T->assertEqual("Signatory metadata explains why", $secC3['signatories']['instructor']['how'], '');
mock_set_config($c13, ['instructor' => 'COURSE-WIDE NAME']);
$secC4 = gradesheet_service::compute_all_grades($c13, $gC);
$T->assertEqual("Instructor C viewed by admin with a typed course-wide name -> typed name", $secC4['instructor'], 'COURSE-WIDE NAME');
$secA2 = gradesheet_service::compute_all_grades($c13, $gA);
$T->assertEqual("Typed course-wide name does NOT override a section with its own single teacher", $secA2['instructor'], 'TEODORO UNO');
$USER = (object)['id' => 1313]; // non-editing teacher: can view, is not an instructor candidate
$allView = gradesheet_service::compute_all_grades($c13, 0);
$T->assertEqual("Whole-course view by a non-teacher with 4 teachers uses the typed course-wide name", $allView['instructor'], 'COURSE-WIDE NAME');
mock_set_config($c13, ['instructor' => '']);
$allView = gradesheet_service::compute_all_grades($c13, 0);
$T->assertEqual("Whole-course view by a non-teacher with 4 teachers and no typed name -> blank", $allView['instructor'], '');
$USER = (object)['id' => 1];
$allAdmin = gradesheet_service::compute_all_grades($c13, 0);
$T->assertEqual("Whole-course view by an admin who is also an enrolled editing teacher -> the viewer (teacher) wins", $allAdmin['instructor'], 'SITE ADMIN');

// 13d. Per-section header overrides are independent.
helper::set_group_overrides($c13, $gA, ['courseandyear' => '', 'schedule' => 'MW 8:00-9:30 AM', 'instructor' => '']);
helper::set_group_overrides($c13, $gB, ['courseandyear' => 'BSCS 1-B (Evening)', 'schedule' => 'TTH 5:30-7:00 PM', 'instructor' => '']);
$USER = (object)['id' => 1310];
helper::reset_caches();
$secA = gradesheet_service::compute_all_grades($c13, $gA);
$secB = gradesheet_service::compute_all_grades($c13, $gB);
$secC = gradesheet_service::compute_all_grades($c13, $gC);
$T->assertEqual("Section A schedule override", $secA['schedule'], 'MW 8:00-9:30 AM');
$T->assertEqual("Section B schedule + label override", [$secB['schedule'], $secB['courseandyear']], ['TTH 5:30-7:00 PM', 'BSCS 1-B (Evening)']);
$T->assertEqual("Section C without overrides keeps course-wide schedule and group name", [$secC['schedule'], $secC['courseandyear']], ['MW 8:00-9:30 AM', 'BSCS 1C']);

// 13e. Status overrides follow the student onto every sheet they appear on.
helper::set_student_status($c13, 1325, 'inc');
$secA = gradesheet_service::compute_all_grades($c13, $gA);
$secB = gradesheet_service::compute_all_grades($c13, $gB);
$T->assertEqual("INC student shows as Incomplete on section A", $secA['rows'][2]['remarks'], 'Incomplete');
$T->assertEqual("INC student shows as Incomplete on section B", $secB['rows'][2]['remarks'], 'Incomplete');
$T->assertRow("INC student excluded from section A pass rate", $secA, ['total' => 2, 'passcount' => 1, 'failcount' => 1, 'othercount' => 1]);
helper::set_student_status($c13, 1325, '');

// 13f. SEPARATEGROUPS: teachers only see their own sections.
$MOCK_COURSE_GROUPMODE = SEPARATEGROUPS;
$USER = (object)['id' => 1310];
$MOCK_ACTIVE_GROUP = $gA;
$T->assertEqual("SEPARATEGROUPS: T1 with group=0 gets the active group (A)", roster_names(helper::get_non_teaching_students($ctx13, 0)), ['Abad, Ana', 'Bautista, Ben', 'Enriquez, Eva']);
$T->assertEqual("SEPARATEGROUPS: T1 requesting section B gets nothing", helper::get_non_teaching_students($ctx13, $gB), []);
$T->assert("SEPARATEGROUPS: check_group_access denies T1 -> B", !helper::check_group_access($ctx13, $gB));
$T->assert("SEPARATEGROUPS: check_group_access allows T1 -> C (co-teacher)", helper::check_group_access($ctx13, $gC));
$T->assertEqual("SEPARATEGROUPS: T1 requesting section C sees it", roster_names(helper::get_non_teaching_students($ctx13, $gC)), ['Gomez, Gil']);
$USER = (object)['id' => 1312];
$MOCK_ACTIVE_GROUP = 0;
$T->assertEqual("SEPARATEGROUPS: teacher in no group sees an empty roster", helper::get_non_teaching_students($ctx13, 0), []);
$USER = (object)['id' => 1];
$MOCK_CAPABILITIES["moodle/site:accessallgroups:1"] = true;
$T->assertEqual("SEPARATEGROUPS: admin with accessallgroups and no active group sees everyone", count(helper::get_non_teaching_students($ctx13, 0)), 7);
$T->assertEqual("SEPARATEGROUPS: admin can open any section", roster_names(helper::get_non_teaching_students($ctx13, $gB)), ['Cruz, Carla', 'Dizon, Dan', 'Enriquez, Eva']);
$T->assert("A group id from another course is always refused", !helper::check_group_access($ctx13, 1));

// 13g-pre. Section helpers that drive the dashboard picker and the ZIP export.
$MOCK_COURSE_GROUPMODE = NOGROUPS;
$USER = (object)['id' => 1310];
$MOCK_ACTIVE_GROUP = 0;
$T->assertEqual("NOGROUPS: every group is an accessible section, in name order", array_values(array_map(function ($g) { return $g->name; }, helper::get_accessible_sections($ctx13))), ['BSCS 1A', 'BSCS 1B', 'BSCS 1C']);
$T->assert("NOGROUPS: combined sheet allowed", helper::can_view_combined($ctx13));
$T->assertEqual("Default section with no active group = first section (not the combined sheet)", helper::default_section($ctx13), $gA);
$MOCK_ACTIVE_GROUP = $gB;
$T->assertEqual("Default section follows the active group", helper::default_section($ctx13), $gB);
$MOCK_ACTIVE_GROUP = 0;
$MOCK_COURSE_GROUPMODE = SEPARATEGROUPS;
$T->assertEqual("SEPARATEGROUPS: T1 only sees sections A and C", array_keys(helper::get_accessible_sections($ctx13)), [$gA, $gC]);
$T->assert("SEPARATEGROUPS: T1 may not view the combined sheet", !helper::can_view_combined($ctx13));
$USER = (object)['id' => 1312];
$T->assertEqual("SEPARATEGROUPS: teacher in no group has no sections and default 0", [helper::get_accessible_sections($ctx13), helper::default_section($ctx13)], [[], 0]);
$USER = (object)['id' => 1];
$MOCK_CAPABILITIES["moodle/site:accessallgroups:1"] = true;
$T->assertEqual("SEPARATEGROUPS: accessallgroups sees all three sections and the combined sheet", [count(helper::get_accessible_sections($ctx13)), helper::can_view_combined($ctx13)], [3, true]);
$T->assertEqual("Ungrouped students are detected (Fe Flores only); staff never listed", roster_names(helper::get_ungrouped_students($ctx13)), ['Flores, Fe']);
$DB->insert_record('course', (object)['id' => 1302, 'fullname' => 'No Groups', 'shortname' => 'NG']);
$T->assertEqual("A course without groups has no sections and default 0", [helper::get_accessible_sections(context_course::instance(1302)), helper::default_section(context_course::instance(1302))], [[], 0]);

// 13g. VISIBLEGROUPS: everyone may look at any section.
$MOCK_COURSE_GROUPMODE = VISIBLEGROUPS;
$USER = (object)['id' => 1310];
$MOCK_ACTIVE_GROUP = 0;
$T->assertEqual("VISIBLEGROUPS: group=0 with no active group lists the whole course", count(helper::get_non_teaching_students($ctx13, 0)), 7);
$T->assertEqual("VISIBLEGROUPS: T1 may view section B", roster_names(helper::get_non_teaching_students($ctx13, $gB)), ['Cruz, Carla', 'Dizon, Dan', 'Enriquez, Eva']);
$MOCK_ACTIVE_GROUP = $gA;
$T->assertEqual("VISIBLEGROUPS: group=0 follows the active group", roster_names(helper::get_non_teaching_students($ctx13, 0)), ['Abad, Ana', 'Bautista, Ben', 'Enriquez, Eva']);
mock_reset_scenario();

// =========================================================================
echo "\n======================================================================\n";
echo "BATTERY 14: Gradebook Edge Cases & Student Self-View\n";
echo "======================================================================\n";
mock_reset_scenario();
$c14 = 1401;
$DB->insert_record('course', (object)['id' => $c14, 'fullname' => 'Edge Course', 'shortname' => 'EDGE1401']);
mock_config($c14);
$cQ = $DB->insert_record('local_gradesheet_categories', (object)['courseid' => $c14, 'name' => 'Quizzes', 'weight' => 50.0, 'sortorder' => 0]);
$cE = $DB->insert_record('local_gradesheet_categories', (object)['courseid' => $c14, 'name' => 'Exams',   'weight' => 50.0, 'sortorder' => 1]);
$s14 = 1410;
mock_user($s14, 'Edge', 'Student', $c14);
// Midterm: three quizzes with different grademax, an overridden exam, an excluded exam.
$q10  = mock_item($c14, 'Quiz /10',  'midterm', $cQ, 10.0);   mock_grade($q10,  $s14, 8.0);    // 80%
$q50  = mock_item($c14, 'Quiz /50',  'midterm', $cQ, 50.0);   mock_grade($q50,  $s14, 45.0);   // 90%
$q150 = mock_item($c14, 'Quiz /150', 'midterm', $cQ, 150.0);  mock_grade($q150, $s14, 120.0);  // 80%
$eOvr = mock_item($c14, 'Exam (overridden)', 'midterm', $cE); mock_grade($eOvr, $s14, 95.0, ['rawgrade' => 60.0, 'overridden' => time()]);
$eExc = mock_item($c14, 'Exam (excluded)',   'midterm', $cE); mock_grade($eExc, $s14, 10.0, ['excluded' => time()]);
// Finals: four kinds of "hidden".
$fHG  = mock_item($c14, 'Quiz (grade hidden)',     'finals', $cQ); mock_grade($fHG, $s14, 70.0, ['hidden' => 1]);
$fHP  = mock_item($c14, 'Quiz (hidden until yesterday)', 'finals', $cQ, 100.0, ['hidden' => time() - 86400]); mock_grade($fHP, $s14, 60.0);
$fPC  = mock_item($c14, 'Exam (parent category hidden)', 'finals', $cE, 100.0, ['parenthidden' => 1]); mock_grade($fPC, $s14, 50.0);
$fHU  = mock_item($c14, 'Exam (hidden until tomorrow)',  'finals', $cE, 100.0, ['hidden' => time() + 86400]); mock_grade($fHU, $s14, 90.0);
// Noise that must never count: scale item, text item, course total, category total, unmapped numeric item.
$DB->insert_record('grade_items', (object)['courseid' => $c14, 'itemtype' => 'mod', 'itemname' => 'Attendance (scale)', 'gradetype' => GRADE_TYPE_SCALE, 'grademax' => 5]);
$DB->insert_record('grade_items', (object)['courseid' => $c14, 'itemtype' => 'manual', 'itemname' => 'Comments (text)', 'gradetype' => GRADE_TYPE_TEXT]);
$DB->insert_record('grade_items', (object)['courseid' => $c14, 'itemtype' => 'course', 'itemname' => null, 'gradetype' => 1, 'grademax' => 100]);
$DB->insert_record('grade_items', (object)['courseid' => $c14, 'itemtype' => 'category', 'itemname' => null, 'gradetype' => 1, 'grademax' => 100]);
$unm = mock_item($c14, 'Unmapped bonus', 'finals', 0); mock_grade($unm, $s14, 100.0);
helper::reset_caches();

// Faculty view (includehidden = 1 by default).
$f = helper::compute_student_grades($c14, $s14);
// Midterm: Q = (80+90+80)/3 = 83.333; E = 95 (override honoured, excluded skipped) -> 89.1667
// Finals : Q = (70+60)/2 = 65;        E = (50+90)/2 = 70                            -> 67.5
$T->assertDelta("Grademax scaling: /10, /50, /150 items all normalised to percent (Q midterm 83.33)", $f['cattotals'][$cQ]['midtotal'] / $f['cattotals'][$cQ]['midcount'], 83.3333);
$T->assertDelta("Overridden finalgrade (95) is used, not rawgrade (60)", $f['cattotals'][$cE]['midtotal'] / $f['cattotals'][$cE]['midcount'], 95.0);
$T->assertDelta("Faculty midterm = 89.17", $f['midterm'], 89.1667);
$T->assertDelta("Faculty finals include grade-hidden, hidden-until and hidden-category items = 67.5", $f['finals'], 67.5);
$T->assertDelta("Faculty average = 78.33 (PASSED)", $f['average'], 78.3333);
$T->assertEqual("Faculty remarks PASSED", $f['remarks'], 'PASSED');
$T->assertEqual("Excluded grade never counts: mapped = 8 (9 mapped minus 1 excluded), unmapped item not counted", [$f['mapped'], $f['graded'], $f['missing']], [8, 8, 0]);
$T->assertEqual("Scale/text/course-total/category-total items never enter computation", $f['periodcounts'], ['midterm' => ['graded' => 4, 'mapped' => 4], 'finals' => ['graded' => 4, 'mapped' => 4]]);
$T->assertEqual("Mapping warnings count the unmapped numeric item only", helper::get_mapping_warnings($c14), ['unmapped' => 1, 'midterm' => 5, 'finals' => 4]);

// Student self-view: nothing hidden ever counts, whatever includehidden says.
$sv = helper::compute_student_grades($c14, $s14, true);
$T->assertDelta("Student view midterm unchanged (nothing hidden there)", $sv['midterm'], 89.1667);
$T->assertDelta("Student view finals: only the hidden-until-yesterday quiz is visible -> 60", $sv['finals'], 60.0);
$T->assertDelta("Student view average = 74.58 -> FAILED while faculty sees PASSED (embargo respected)", $sv['average'], 74.5833);
$T->assertEqual("Student view remarks", $sv['remarks'], 'FAILED');
$T->assertEqual("Student view mapped count drops to the visible items", [$sv['mapped'], $sv['periodcounts']['finals']['mapped']], [5, 1]);

// includehidden = 0 makes the faculty view match the student view.
mock_set_config($c14, ['includehidden' => 0]);
$f0 = helper::compute_student_grades($c14, $s14);
$T->assertDelta("includehidden=0: faculty finals now 60 like the student view", $f0['finals'], 60.0);
mock_set_config($c14, ['includehidden' => 1, 'missingaszero' => 1]);
$fz = helper::compute_student_grades($c14, $s14);
$T->assertDelta("missingaszero does not resurrect an excluded grade (midterm unchanged)", $fz['midterm'], 89.1667);
mock_set_config($c14, ['missingaszero' => 0]);

// 14b. Weight and category corner cases (course 1402).
$c14b = 1402;
$DB->insert_record('course', (object)['id' => $c14b, 'fullname' => 'Weights Course', 'shortname' => 'W1402']);
mock_config($c14b);
$cA = $DB->insert_record('local_gradesheet_categories', (object)['courseid' => $c14b, 'name' => 'A', 'weight' => 0.0, 'sortorder' => 0]);
$cB = $DB->insert_record('local_gradesheet_categories', (object)['courseid' => $c14b, 'name' => 'B', 'weight' => 0.0, 'sortorder' => 1]);
$s14b = 1420; $s14c = 1421; $s14d = 1422;
mock_user($s14b, 'W', 'One', $c14b); mock_user($s14c, 'W', 'Two', $c14b); mock_user($s14d, 'W', 'Three', $c14b);
$iA = mock_item($c14b, 'Item A', 'midterm', $cA); mock_grade($iA, $s14b, 80.0);
$iB = mock_item($c14b, 'Item B', 'midterm', $cB); mock_grade($iB, $s14b, 60.0);
$iZ = mock_item($c14b, 'Zero-max item', 'midterm', $cA, 0.0); mock_grade($iZ, $s14c, 7.0);
$iO = mock_item($c14b, 'Orphan-category item', 'midterm', 9999); mock_grade($iO, $s14b, 100.0);
$iN = mock_item($c14b, 'Finals with null grade row', 'finals', $cA); mock_grade($iN, $s14d, null);
mock_grade($iA, $s14d, 50.0);
helper::reset_caches();
$w = helper::compute_student_grades($c14b, $s14b);
$T->assertDelta("All weights zero -> plain mean of category averages (80,60 -> 70)", $w['midterm'], 70.0);
$T->assertEqual("Orphan mapping (category id that no longer exists) is excluded: mapped counts the 4 valid items, graded the 2 with scores", [$w['mapped'], $w['graded']], [4, 2]);
$T->assertEqual("Orphan mapping shows up as unmapped in warnings", helper::get_mapping_warnings($c14b)['unmapped'], 1);
$catA = $DB->get_record('local_gradesheet_categories', ['id' => $cA]); $catB = $DB->get_record('local_gradesheet_categories', ['id' => $cB]);
$catA->weight = 70.0; $catB->weight = 50.0; $DB->update_record('local_gradesheet_categories', $catA); $DB->update_record('local_gradesheet_categories', $catB);
helper::reset_caches();
$w = helper::compute_student_grades($c14b, $s14b);
$T->assertDelta("Weights 70/50 (sum 120) still compute, normalised by their sum -> 71.67", $w['midterm'], 71.6667);
$T->assertRow("validate_weight_sum flags 120%", helper::validate_weight_sum($c14b), ['valid' => false, 'total' => 120.0, 'count' => 2]);
$catA->weight = 100.0; $catB->weight = 0.0; $DB->update_record('local_gradesheet_categories', $catA); $DB->update_record('local_gradesheet_categories', $catB);
helper::reset_caches();
$w = helper::compute_student_grades($c14b, $s14b);
$T->assertDelta("A category with 0% weight contributes nothing when other weights are > 0 (-> 80)", $w['midterm'], 80.0);
$catA->weight = 60.0; $catB->weight = 40.0; $DB->update_record('local_gradesheet_categories', $catA); $DB->update_record('local_gradesheet_categories', $catB);
helper::reset_caches();
$w3 = helper::compute_student_grades($c14b, $s14d);
$T->assertDelta("Period with only category A present: re-normalised over A's weight alone (50 -> 50)", $w3['midterm'], 50.0);
$T->assertEqual("A grade row whose finalgrade is NULL counts as missing", [$w3['periodcounts']['finals']['mapped'], $w3['periodcounts']['finals']['graded']], [1, 0]);
$T->assertDelta("A grade row whose finalgrade is NULL is skipped (finals null)", $w3['finals'] ?? -1.0, -1.0);
$wz = helper::compute_student_grades($c14b, $s14c);
$T->assertDelta("grademax 0 is not divided by: raw value used as-is (7)", $wz['midterm'], 7.0);
$T->assertRow("validate_weight_sum tolerance: 33.33+33.33+33.34 valid", (function () use ($DB) {
    $cid = 1404; $DB->insert_record('course', (object)['id' => $cid, 'fullname' => 'Tol', 'shortname' => 'TOL']);
    foreach ([33.33, 33.33, 33.34] as $i => $wt) { $DB->insert_record('local_gradesheet_categories', (object)['courseid' => $cid, 'name' => "C$i", 'weight' => $wt, 'sortorder' => $i]); }
    return helper::validate_weight_sum($cid);
})(), ['valid' => true, 'total' => 100.0]);
$T->assertRow("validate_weight_sum tolerance: 33.33x3 = 99.99 accepted (within 0.01)", (function () use ($DB) {
    $cid = 1405; $DB->insert_record('course', (object)['id' => $cid, 'fullname' => 'Tol2', 'shortname' => 'TOL2']);
    foreach ([33.33, 33.33, 33.33] as $i => $wt) { $DB->insert_record('local_gradesheet_categories', (object)['courseid' => $cid, 'name' => "C$i", 'weight' => $wt, 'sortorder' => $i]); }
    return helper::validate_weight_sum($cid);
})(), ['valid' => true, 'total' => 99.99]);
$T->assertRow("validate_weight_sum: 33.3x3 = 99.9 rejected", (function () use ($DB) {
    $cid = 1406; $DB->insert_record('course', (object)['id' => $cid, 'fullname' => 'Tol3', 'shortname' => 'TOL3']);
    foreach ([33.3, 33.3, 33.3] as $i => $wt) { $DB->insert_record('local_gradesheet_categories', (object)['courseid' => $cid, 'name' => "C$i", 'weight' => $wt, 'sortorder' => $i]); }
    return helper::validate_weight_sum($cid);
})(), ['valid' => false, 'total' => 99.9]);

// 14c. Stale gradebook: needsupdate triggers exactly one regrade per request.
$c14c = 1403;
$DB->insert_record('course', (object)['id' => $c14c, 'fullname' => 'Stale Course', 'shortname' => 'STALE']);
mock_config($c14c);
$cS = $DB->insert_record('local_gradesheet_categories', (object)['courseid' => $c14c, 'name' => 'All', 'weight' => 100.0, 'sortorder' => 0]);
$iS = mock_item($c14c, 'Needs regrade', 'finals', $cS, 100.0, ['needsupdate' => 1]);
mock_user(1430, 'Stale', 'One', $c14c); mock_grade($iS, 1430, 88.0);
mock_user(1431, 'Stale', 'Two', $c14c); mock_grade($iS, 1431, 66.0);
helper::reset_caches();
$MOCK_REGRADE_CALLS = [];
helper::compute_student_grades($c14c, 1430);
helper::compute_student_grades($c14c, 1431);
$T->assertEqual("needsupdate=1 triggers grade_regrade_final_grades once for the course, not per student", $MOCK_REGRADE_CALLS, [$c14c]);
helper::reset_caches();
helper::compute_student_grades($c14c, 1430);
$T->assertEqual("After the regrade cleared needsupdate, no further regrade is requested", count($MOCK_REGRADE_CALLS), 1);
helper::compute_student_grades($c14, $s14);
$T->assertEqual("A course with no stale items never triggers a regrade", count($MOCK_REGRADE_CALLS), 1);
mock_reset_scenario();

// =========================================================================
echo "\n======================================================================\n";
echo "BATTERY 15: Transmutation & Legend Matrix\n";
echo "======================================================================\n";
mock_reset_scenario();
// 15a. Default ESSU table: full integer sweep is monotonic, bounded and formatted.
$prev = null; $monotonic = true; $bounded = true; $formatted = true;
for ($p = 0; $p <= 100; $p++) {
    $eq = helper::transmute_equiv($p);
    $v = (float)$eq;
    if (!preg_match('/^\d\.\d$/', $eq)) { $formatted = false; }
    if ($v < 1.0 || $v > 5.0) { $bounded = false; }
    if ($prev !== null && $v > $prev + 1e-9) { $monotonic = false; }
    $prev = $v;
}
$T->assert("ESSU sweep 0-100: every value formatted d.d", $formatted);
$T->assert("ESSU sweep 0-100: every value within 1.0-5.0", $bounded);
$T->assert("ESSU sweep 0-100: equivalent never gets worse as the percentage rises", $monotonic);
foreach ([[89.99, '1.6'], [90.0, '1.5'], [99.99, '1.1'], [100.0, '1.0'], [54.99, '5.0'], [55.0, '5.0'], [69.99, '3.6'], [74.99, '3.1'], [75.0, '3.0'], [79.99, '2.6'], [84.99, '2.1'], [85.0, '2.0']] as [$p, $e]) {
    $T->assertEqual("ESSU boundary {$p} -> {$e}", helper::transmute_equiv($p), $e);
}
$T->assertEqual("transmute_equiv accepts numeric strings", helper::transmute_equiv('90'), '1.5');
$T->assertEqual("transmute_equiv rejects non-numeric input with '-'", helper::transmute_equiv('abc'), '-');

// 15b. Default adjectival bands (by raw percentage).
foreach ([[100, 'Outstanding'], [99.9, 'Excellent'], [90, 'Excellent'], [89.99, 'Very Good'], [85, 'Very Good'], [84.99, 'Good'],
          [80, 'Good'], [79.99, 'Fair'], [75, 'Fair'], [74.99, 'Conditional'], [70, 'Conditional'], [69.99, 'Failed'], [0, 'Failed']] as [$p, $e]) {
    $T->assertEqual("Adjectival {$p} -> {$e}", helper::adjectival_rating($p), $e);
}
$T->assertEqual("Adjectival rating of no grade is blank", helper::adjectival_rating(null), '');

// 15c. Legacy custom brackets (no formula): bracket rules decide everything.
$c15 = 1501;
$DB->insert_record('course', (object)['id' => $c15, 'fullname' => 'Legacy Scale', 'shortname' => 'LEG']);
mock_config($c15);
$DB->insert_record('local_gradesheet_transmute', (object)['courseid' => $c15, 'minscore' => 95, 'maxscore' => 100, 'equivalent' => '',   'descriptor' => 'A', 'sortorder' => 0, 'ispassing' => 0]); // passing deliberately 0
$DB->insert_record('local_gradesheet_transmute', (object)['courseid' => $c15, 'minscore' => 60, 'maxscore' => 94.99, 'equivalent' => '', 'descriptor' => 'B', 'sortorder' => 1, 'ispassing' => 1]);
helper::reset_caches();
$T->assertEqual("Legacy: score in a bracket without equivalent prints the raw score", helper::transmute_equiv(97.5, $c15), '97.50');
$T->assert("Legacy: bracket ispassing=0 fails a 97.5 even though it beats 75", !helper::is_passing(97.5, $c15));
$T->assert("Legacy: bracket ispassing=1 passes a 61", helper::is_passing(61.0, $c15));
$T->assertEqual("Legacy: score covered by no bracket prints '-'", helper::transmute_equiv(30.0, $c15), '-');
$T->assert("Legacy: score covered by no bracket fails", !helper::is_passing(30.0, $c15));
$T->assertEqual("Legacy: adjectival from bracket; none when uncovered", [helper::adjectival_rating(97.5, $c15), helper::adjectival_rating(30, $c15)], ['A', '']);
$T->assert("Legacy: legend has no Equivalent column when every bracket's equivalent is blank", !helper::legend_has_equivalent($c15));
$T->assertEqual("Legacy legend rows", helper::get_rating_legend($c15), [['100-95', '', 'A'], ['94.99-60', '', 'B']]);
$T->assertEqual("Scale validator: missing bottom bracket + gap reported", count(helper::validate_custom_scale($c15)), 1);
$DB->insert_record('local_gradesheet_transmute', (object)['courseid' => $c15, 'minscore' => 0, 'maxscore' => 65, 'equivalent' => '5.0', 'descriptor' => 'C', 'sortorder' => 2, 'ispassing' => 0]);
helper::reset_caches();
$T->assert("Legacy: one filled equivalent brings the Equivalent column back", helper::legend_has_equivalent($c15));
$warns = implode(' ', helper::validate_custom_scale($c15));
$T->assert("Scale validator: overlap 60-65 detected", stripos($warns, 'Overlap detected') !== false);
$T->assertEqual("Legacy: overlapping score 62 resolves to the higher bracket (B)", helper::adjectival_rating(62, $c15), 'B');

// 15d. Default legend rows and formula-mode legend rows.
$default = helper::get_rating_legend(null);
$T->assertEqual("Default legend has 11 rows ending with the status codes", [count($default), $default[10][0]], [11, 'IP']);
$T->assertEqual("Default legend second row", $default[1], ['99-90', '1.1-1.5', 'Excellent']);
$c15b = 1502;
$DB->insert_record('course', (object)['id' => $c15b, 'fullname' => 'Formula Legend', 'shortname' => 'FL']);
mock_config($c15b, ['transmutemode' => 'formula', 'formula' => '1 + (100 - P) * 0.08', 'formulamin' => 1, 'formulamax' => 5, 'formuladecimals' => 2]);
helper::reset_caches();
$fl = helper::get_rating_legend($c15b);
$T->assertEqual("Formula legend keeps ESSU ranges and derives equivalents (2 decimals)", [$fl[0], $fl[1], $fl[6]], [['100', '1.00', 'Outstanding'], ['99-90', '1.08-1.80', 'Excellent'], ['69-0', '3.48-5.00', 'Failed']]);
$T->assertEqual("Formula legend preserves INC/Dr/WP/IP rows", [$fl[7][0], $fl[8][0], $fl[9][0], $fl[10][0]], ['INC', 'Dr', 'WP', 'IP']);
$T->assert("Formula mode always has an Equivalent column", helper::legend_has_equivalent($c15b));
$T->assertEqual("Formula with 2 decimals: 87 -> '2.04'", helper::transmute_equiv(87, $c15b), '2.04');
mock_set_config($c15b, ['formuladecimals' => 0]);
$T->assertEqual("Formula with 0 decimals: 87 -> '2'", helper::transmute_equiv(87, $c15b), '2');
mock_set_config($c15b, ['formuladecimals' => 1]);
$T->assertEqual("Formula with 1 decimal: 87 -> '2.0' (warms the per-request cache)", helper::transmute_equiv(87, $c15b), '2.0');

// 15e. Clamp both sides and cache invalidation.
$fs = ['mode' => 'formula', 'formula' => 'P', 'min' => 60.0, 'max' => 95.0, 'decimals' => 0, 'passmark' => 75.0];
$T->assertEqual("apply_formula clamps below min and above max", [helper::apply_formula(10, $fs), helper::apply_formula(99, $fs), helper::apply_formula(80, $fs)], [60.0, 95.0, 80.0]);
$T->assertEqual("apply_formula returns null for an unparsable formula", helper::apply_formula(80, ['mode' => 'formula', 'formula' => 'P +', 'min' => null, 'max' => null, 'decimals' => 1, 'passmark' => 75]), null);
$cfg15 = $DB->get_record('local_gradesheet_config', ['courseid' => $c15b]); $cfg15->transmutemode = 'essu'; $DB->update_record('local_gradesheet_config', $cfg15);
$T->assertEqual("Per-request cache: a DB change is not visible until reset_caches()", helper::transmute_equiv(87, $c15b), '2.0');
helper::reset_caches();
$T->assertEqual("After reset_caches() the new mode applies (ESSU table: 87 -> 1.8)", helper::transmute_equiv(87, $c15b), '1.8');

// 15f. Rounding and midterm weight interplay on real computations.
$c15c = 1503;
$DB->insert_record('course', (object)['id' => $c15c, 'fullname' => 'Round Course', 'shortname' => 'RND']);
mock_config($c15c);
$cR = $DB->insert_record('local_gradesheet_categories', (object)['courseid' => $c15c, 'name' => 'All', 'weight' => 100.0, 'sortorder' => 0]);
$rm = mock_item($c15c, 'Mid', 'midterm', $cR); $rf = mock_item($c15c, 'Fin', 'finals', $cR);
mock_user(1530, 'R', 'One', $c15c); mock_grade($rm, 1530, 89.4); mock_grade($rf, 1530, 90.5);
helper::reset_caches();
$r = helper::compute_student_grades($c15c, 1530);
$T->assertRow("No rounding: avg 89.95 stays below 90 -> '1.6'", $r, ['average' => 89.95, 'transmuted' => '1.6']);
mock_set_config($c15c, ['roundaverage' => 1]);
$r = helper::compute_student_grades($c15c, 1530);
$T->assertRow("Rounding: 89.4->89, 90.5->91, avg 90 -> '1.5'", $r, ['midterm' => 89.0, 'finals' => 91.0, 'average' => 90.0, 'transmuted' => '1.5']);
mock_set_config($c15c, ['roundaverage' => 0, 'midtermweight' => 0]);
$T->assertDelta("midtermweight 0: average is the finals grade", helper::compute_student_grades($c15c, 1530)['average'], 90.5);
mock_set_config($c15c, ['midtermweight' => 100]);
$T->assertDelta("midtermweight 100: average is the midterm grade", helper::compute_student_grades($c15c, 1530)['average'], 89.4);
mock_set_config($c15c, ['midtermweight' => 150]);
$T->assertDelta("midtermweight out of range falls back to 50/50", helper::compute_student_grades($c15c, 1530)['average'], 89.95);
mock_set_config($c15c, ['midtermweight' => 50, 'transmutemode' => 'formula', 'formula' => 'min(95, 50 + P / 2)', 'formuladecimals' => 0, 'passmark' => 50]);
$r = helper::compute_student_grades($c15c, 1530);
$T->assertRow("Formula mode on a real computation: 89.95 -> 50+44.975=94.975 -> '95', PASSED, rating Very Good", $r, ['transmuted' => '95', 'remarks' => 'PASSED']);
$T->assertEqual("Rating still comes from the raw percentage (89.95 -> Very Good)", helper::adjectival_rating($r['average'], $c15c), 'Very Good');
mock_reset_scenario();

// =========================================================================
echo "\n======================================================================\n";
echo "BATTERY 16: Formula Evaluator Exhaustive Coverage\n";
echo "======================================================================\n";
$cases = [
    ['P', 73.5, 73.5],
    ['42', 10, 42.0],
    ['.5 + P', 1, 1.5],
    ['1 + 2 * 3', 0, 7.0],
    ['(1 + 2) * 3', 0, 9.0],
    ['10 - 4 - 3', 0, 3.0],
    ['100 / 10 / 2', 0, 5.0],
    ['2 ^ 3 ^ 2', 0, 512.0],
    ['-2 ^ 2', 0, -4.0],
    ['(-2) ^ 2', 0, 4.0],
    ['-(-P)', 5, 5.0],
    ['--P', 5, 5.0],
    ['+P', 5, 5.0],
    ['17 % 5', 0, 2.0],
    ['P % 7', 100, 2.0],
    ['min(3, 1, 2)', 0, 1.0],
    ['max(3, 1, 2)', 0, 3.0],
    ['round(2.345, 2)', 0, 2.35],
    ['round(2.5)', 0, 3.0],
    ['floor(P / 10) * 10', 87, 80.0],
    ['ceil(P / 10) * 10', 81, 90.0],
    ['abs(50 - P)', 20, 30.0],
    ['sqrt(P)', 81, 9.0],
    ['MIN(95, p)', 100, 95.0],
    ['  min ( 95 ,  x  ) ', 100, 95.0],
    ['score * 0.95', 100, 95.0],
    ['((((P))))', 3, 3.0],
    ['1 + (100 - P) * 0.08', 87.5, 2.0],
    ['max(1, min(5, 1 + (100 - P) * 0.08))', 10, 5.0],
];
foreach ($cases as [$expr, $p, $want]) {
    $T->assertDelta("eval \"{$expr}\" at P={$p} = {$want}", formula::evaluate($expr, (float)$p), $want, 1e-9);
}
$errors = ['', '   ', 'P +', '+', '()', 'P * )', '(P', 'P)', 'foo(P)', 'eval(P)', 'system(P)', 'P / 0', 'P % 0', '1..2', 'P ** 2', 'P & 1',
           'min(P)', 'round()', 'round(P, 1, 2)', 'abs(1, 2)', 'sqrt(-4)', 'P P', '2 3', 'y', '$P', 'P;', str_repeat('P+', 130) . 'P'];
foreach ($errors as $bad) {
    $T->assert("validate rejects \"" . (strlen($bad) > 30 ? substr($bad, 0, 27) . '...' : $bad) . "\"", formula::validate($bad) !== '');
}
$T->assert("validate accepts every built-in preset", (function () {
    foreach (helper::formula_presets() as $pr) { if (formula::validate($pr['formula']) !== '') { return false; } }
    return true;
})());
try { formula::evaluate('P / (P - 50)', 50); $T->assert("evaluate throws DivisionByZeroError on runtime zero divisor", false); }
catch (\DivisionByZeroError $e) { $T->assert("evaluate throws DivisionByZeroError on runtime zero divisor", true); }
try { formula::evaluate('P +', 1); $T->assert("evaluate throws InvalidArgumentException on syntax error", false); }
catch (\InvalidArgumentException $e) { $T->assert("evaluate throws InvalidArgumentException on syntax error", true); }

// =========================================================================
echo "\n======================================================================\n";
echo "BATTERY 17: Status Management\n";
echo "======================================================================\n";
mock_reset_scenario();
$c17 = 1701;
$DB->insert_record('course', (object)['id' => $c17, 'fullname' => 'Status Course', 'shortname' => 'ST1701']);
mock_config($c17);
$cS17 = $DB->insert_record('local_gradesheet_categories', (object)['courseid' => $c17, 'name' => 'All', 'weight' => 100.0, 'sortorder' => 0]);
$i17 = mock_item($c17, 'Exam', 'finals', $cS17);
mock_user(1710, 'Stat', 'One', $c17); mock_grade($i17, 1710, 95.0);
mock_user(1711, 'Stat', 'Two', $c17); mock_grade($i17, 1711, 40.0);
$DB->insert_record('user', (object)['id' => 1712, 'firstname' => 'Not', 'lastname' => 'Enrolled', 'idnumber' => 'ID1712']);
helper::reset_caches();

helper::set_student_status($c17, 1710, 'inc');
$T->assertEqual("Set INC and read it back", helper::get_student_status($c17, 1710), 'inc');
helper::set_student_status($c17, 1710, 'dropped');
$T->assertEqual("Overwrite with Dropped (one row per student per course)", [helper::get_student_status($c17, 1710), $DB->count_records('local_gradesheet_status', ['courseid' => $c17, 'userid' => 1710])], ['dropped', 1]);
helper::set_student_status($c17, 1710, 'bogus');
$T->assertEqual("Invalid status value ignored", helper::get_student_status($c17, 1710), 'dropped');
helper::set_student_status($c17, 1712, 'inc');
$T->assertEqual("Status for a non-enrolled user ignored", helper::get_student_status($c17, 1712), '');
$T->assertEqual("Status map lists only non-active students", helper::get_status_map($c17), [1710 => 'dropped']);
$T->assertEqual("Labels", [helper::status_label('inc'), helper::status_label('dropped'), helper::status_label('wp'), helper::status_label('ip'), helper::status_label('nope')], ['Incomplete', 'Dropped', 'Withdrawn w/ permission', 'In Progress', '']);
$T->assertEqual("Options expose every valid status", array_keys(helper::status_options()), helper::VALID_STATUSES);
$exp = gradesheet_service::compute_all_grades($c17);
$T->assertRow("Dropped student: dashes, label, excluded from rate", $exp['rows'][0], ['midterm' => '-', 'finals' => '-', 'average' => '-', 'remarks' => 'Dropped', 'status' => 'dropped', 'graded' => 0]);
$T->assertRow("Statistics: 1 graded (failed), 1 other, rate 0%", $exp, ['total' => 1, 'passcount' => 0, 'failcount' => 1, 'othercount' => 1, 'passrate' => 0.0]);
helper::set_student_status($c17, 1711, 'wp');
$exp = gradesheet_service::compute_all_grades($c17);
$T->assertRow("No graded students: pass rate reported as 0 without division error", $exp, ['total' => 0, 'passrate' => 0.0, 'othercount' => 2]);
helper::set_student_status($c17, 1710, '');
$T->assertEqual("Clearing a status deletes the row", $DB->count_records('local_gradesheet_status', ['courseid' => $c17, 'userid' => 1710]), 0);
$sv = helper::compute_student_grades($c17, 1710, true);
$T->assertRow("Student view after clearing: computed normally", $sv, ['average' => 95.0, 'remarks' => 'PASSED']);
mock_reset_scenario();

// =========================================================================
echo "\n======================================================================\n";
echo "BATTERY 18: Defaults, Config Bootstrap, Observers & Role Bootstrap\n";
echo "======================================================================\n";
mock_reset_scenario();
// 18a. ensure_course_defaults.
$T->assertEqual("ensure_course_defaults(0) returns an empty object and writes nothing", (array)helper::ensure_course_defaults(0), []);
$DB->insert_record('course', (object)['id' => 1801, 'fullname' => 'Defaults Course', 'shortname' => str_repeat('LONGSHORTNAME', 6)]);
$cfg = helper::ensure_course_defaults(1801);
$T->assertEqual("Defaults: config created with blank (auto) signatories", [$cfg->instructor, $cfg->department_head, $cfg->registrar, $cfg->college_dean], ['', '', '', '']);
$T->assertEqual("Defaults: coursenumber taken from shortname, bounded to 50 chars", mb_strlen($cfg->coursenumber), 50);
$T->assertEqual("Defaults: ESSU table, 50/50, hidden included, ungraded skipped", [$cfg->transmutemode, $cfg->midtermweight, $cfg->includehidden, $cfg->missingaszero], ['essu', 50, 1, 0]);
$cats = $DB->get_records('local_gradesheet_categories', ['courseid' => 1801], 'sortorder ASC');
$T->assertEqual("Defaults: three categories 30/30/40", array_values(array_map(function ($c) { return $c->name . '=' . $c->weight; }, $cats)), ['Quizzes=30', 'Activities=30', 'Exams=40']);
helper::ensure_course_defaults(1801);
$T->assertEqual("ensure_course_defaults is idempotent (still 1 config row, 3 categories)",
    [$DB->count_records('local_gradesheet_config', ['courseid' => 1801]), $DB->count_records('local_gradesheet_categories', ['courseid' => 1801])], [1, 3]);
$DB->delete_records('local_gradesheet_categories', ['courseid' => 1801]);
helper::ensure_course_defaults(1801);
$T->assertEqual("Deleted categories are re-seeded only when none remain", $DB->count_records('local_gradesheet_categories', ['courseid' => 1801]), 3);

// 18b. load_course_config without a config row.
$DB->insert_record('course', (object)['id' => 1802, 'fullname' => 'No Config Course', 'shortname' => 'NC']);
$lc = helper::load_course_config(1802);
$T->assertRow("load_course_config fallbacks when no row exists", $lc, ['semester' => 'Second Semester', 'units' => '3', 'instructor' => '', 'coursenumber' => 'No Config Course', 'descriptive' => 'No Config Course']);
$T->assertEqual("load_course_config exposes computation rules with defaults", $lc['rules'], ['missingaszero' => false, 'includehidden' => true, 'midtermweight' => 50.0, 'roundaverage' => false]);

// 18c. Observers.
$DB->insert_record('course', (object)['id' => 1803, 'fullname' => 'Observed', 'shortname' => 'OBS']);
observer::course_created(new \core\event\course_created(1803));
$T->assertEqual("course_created observer seeds config + categories", [$DB->count_records('local_gradesheet_config', ['courseid' => 1803]), $DB->count_records('local_gradesheet_categories', ['courseid' => 1803])], [1, 3]);

$DB->insert_record('course', (object)['id' => 1804, 'fullname' => 'Doomed', 'shortname' => 'DOOM']);
mock_config(1804);
$dc = $DB->insert_record('local_gradesheet_categories', (object)['courseid' => 1804, 'name' => 'X', 'weight' => 100, 'sortorder' => 0]);
$DB->insert_record('local_gradesheet_itemmap', (object)['courseid' => 1804, 'gradeitemid' => 1, 'period' => 'finals', 'categoryid' => $dc]);
$DB->insert_record('local_gradesheet_transmute', (object)['courseid' => 1804, 'minscore' => 0, 'maxscore' => 100, 'equivalent' => '', 'descriptor' => 'All', 'sortorder' => 0, 'ispassing' => 1]);
$DB->insert_record('local_gradesheet_status', (object)['courseid' => 1804, 'userid' => 1710, 'status' => 'inc', 'timemodified' => time()]);
$dg = $DB->insert_record('groups', (object)['courseid' => 1804, 'name' => 'G']);
$DB->insert_record('local_gradesheet_groupcfg', (object)['courseid' => 1804, 'groupid' => $dg, 'courseandyear' => '', 'schedule' => 'X', 'instructor' => '', 'timemodified' => time()]);
$keep_status = $DB->count_records('local_gradesheet_status', ['courseid' => $c17]);
observer::course_deleted(new \core\event\course_deleted(1804));
$left = 0;
foreach (['local_gradesheet_config', 'local_gradesheet_categories', 'local_gradesheet_itemmap', 'local_gradesheet_transmute', 'local_gradesheet_status', 'local_gradesheet_groupcfg'] as $t) {
    $left += $DB->count_records($t, ['courseid' => 1804]);
}
$T->assertEqual("course_deleted observer wipes all six plugin tables for that course", $left, 0);
$T->assertEqual("course_deleted observer leaves other courses alone", $DB->count_records('local_gradesheet_status', ['courseid' => $c17]), $keep_status);

$DB->insert_record('course', (object)['id' => 1805, 'fullname' => 'GroupDel', 'shortname' => 'GD']);
$g1 = $DB->insert_record('groups', (object)['courseid' => 1805, 'name' => 'G1']);
$g2 = $DB->insert_record('groups', (object)['courseid' => 1805, 'name' => 'G2']);
helper::set_group_overrides(1805, $g1, ['courseandyear' => '', 'schedule' => 'A', 'instructor' => '']);
helper::set_group_overrides(1805, $g2, ['courseandyear' => '', 'schedule' => 'B', 'instructor' => '']);
observer::group_deleted(new \core\event\group_deleted($g1));
$T->assertEqual("group_deleted observer removes only that group's overrides", [$DB->count_records('local_gradesheet_groupcfg', ['groupid' => $g1]), $DB->count_records('local_gradesheet_groupcfg', ['groupid' => $g2])], [0, 1]);

$DB->insert_record('local_gradesheet_status', (object)['courseid' => 1805, 'userid' => 1799, 'status' => 'inc', 'timemodified' => time()]);
$DB->insert_record('local_gradesheet_status', (object)['courseid' => 1801, 'userid' => 1799, 'status' => 'wp', 'timemodified' => time()]);
observer::user_deleted(new \core\event\user_deleted(1799));
$T->assertEqual("user_deleted observer removes that user's status rows in every course", $DB->count_records('local_gradesheet_status', ['userid' => 1799]), 0);

// 18d. Signatory role bootstrap and settings.
// Empty site: only Registrar is created; Dean / Department Head never are.
$DB->tables['role'] = [];
$DB->tables['role_assignments'] = [];
$MOCK_ROLE_CONTEXTLEVELS = [];
helper::ensure_signatory_roles();
$T->assertEqual("ensure_signatory_roles creates only the Registrar role", array_values(array_map(function ($r) { return $r->shortname; }, $DB->tables['role'])), ['registrar']);
$reg = reset($DB->tables['role']);
$T->assertEqual("Registrar role is assignable at system and category level", $MOCK_ROLE_CONTEXTLEVELS[$reg->id] ?? null, [CONTEXT_SYSTEM, CONTEXT_COURSECAT]);
helper::ensure_signatory_roles();
$T->assertEqual("ensure_signatory_roles is idempotent", count($DB->tables['role']), 1);
$T->assertEqual("Fallback shortnames when the site has no matching role", [helper::signatory_role_shortname('registrar'), helper::signatory_role_shortname('college_dean'), helper::signatory_role_shortname('department_head'), helper::signatory_role_shortname('instructor')], ['registrar', 'dean', 'programhead', 'editingteacher']);
$MOCK_CONFIG = ['local_gradesheet' => ['role_registrar' => 'univregistrar']];
$T->assertEqual("Admin setting overrides a role shortname", helper::signatory_role_shortname('registrar'), 'univregistrar');
$T->assertEqual("Unknown signatory key -> empty shortname", helper::signatory_role_shortname('janitor'), '');
$MOCK_CONFIG = [];

// Site that already has a "University Registrar": nothing is created.
$DB->tables['role'] = [];
$DB->insert_record('role', (object)['shortname' => 'ureg', 'name' => 'University Registrar', 'description' => '']);
helper::ensure_signatory_roles();
$T->assertEqual("Existing 'University Registrar' role prevents creating a duplicate", count($DB->tables['role']), 1);
$T->assertEqual("Existing registrar role is resolved by name", helper::signatory_role_shortname('registrar'), 'ureg');

// Name matching: case, spaces and underscores are ignored; shortname beats name; alias order is priority.
$DB->tables['role'] = [];
$DB->insert_record('role', (object)['shortname' => 'collegedean', 'name' => 'College Dean', 'description' => 'Signs grade sheets as College Dean. Assign at the college category.']);
$DB->insert_record('role', (object)['shortname' => 'r_dean',      'name' => ' DEAN ',        'description' => '']);
$DB->insert_record('role', (object)['shortname' => 'dept_head',   'name' => 'Department_Head', 'description' => '']);
$DB->insert_record('role', (object)['shortname' => 'ph',          'name' => 'Program Head',  'description' => '']);
$T->assertEqual("Site 'Dean' wins over the plugin's old 'collegedean' (alias priority)", helper::signatory_role_shortname('college_dean'), 'r_dean');
$T->assertEqual("'Program Head' wins over 'Department Head' (alias priority)", helper::signatory_role_shortname('department_head'), 'ph');
$DB->delete_records('role', ['shortname' => 'ph']);
$T->assertEqual("'Department_Head' matched ignoring punctuation and case", helper::signatory_role_shortname('department_head'), 'dept_head');
$T->assertEqual("find_role_by_alias with no aliases -> null", helper::find_role_by_alias([]), null);

// Upgrade cleanup: plugin-created Dean / Department Head roles are removed only when safe.
$DB->tables['role'] = [];
$DB->tables['role_assignments'] = [];
$old_dh = $DB->insert_record('role', (object)['shortname' => 'departmenthead', 'name' => 'Department Head', 'description' => 'Signs grade sheets as Department Head. Assign at the department/program category.']);
$old_cd = $DB->insert_record('role', (object)['shortname' => 'collegedean',    'name' => 'College Dean',    'description' => 'Signs grade sheets as College Dean. Assign at the college category.']);
$DB->insert_record('role', (object)['shortname' => 'dean', 'name' => 'Dean', 'description' => '']);
$DB->insert_record('role', (object)['shortname' => 'programhead', 'name' => 'Program Head', 'description' => '']);
$T->assertEqual("Cleanup removes both unused plugin roles when the site has its own Dean / Program Head", helper::remove_unused_plugin_roles(), ['departmenthead', 'collegedean']);
$T->assertEqual("Cleanup leaves the site's own roles alone", count($DB->tables['role']), 2);

$DB->tables['role'] = [];
$old_dh = $DB->insert_record('role', (object)['shortname' => 'departmenthead', 'name' => 'Department Head', 'description' => 'Signs grade sheets as Department Head. Assign at the department/program category.']);
$old_cd = $DB->insert_record('role', (object)['shortname' => 'collegedean',    'name' => 'College Dean',    'description' => 'Signs grade sheets as College Dean. Assign at the college category.']);
$DB->insert_record('role', (object)['shortname' => 'dean', 'name' => 'Dean', 'description' => '']);
$DB->insert_record('role', (object)['shortname' => 'programhead', 'name' => 'Program Head', 'description' => '']);
$DB->insert_record('role_assignments', (object)['roleid' => $old_cd, 'userid' => 5, 'contextid' => 1]);
$T->assertEqual("Cleanup keeps a plugin role that someone is assigned to", helper::remove_unused_plugin_roles(), ['departmenthead']);
$T->assertEqual("...and that role still exists", $DB->record_exists('role', ['shortname' => 'collegedean']), true);

$DB->tables['role'] = [];
$DB->tables['role_assignments'] = [];
$DB->insert_record('role', (object)['shortname' => 'departmenthead', 'name' => 'Department Head', 'description' => 'Renamed by the admin for something else']);
$T->assertEqual("Cleanup keeps a role whose description the admin changed", helper::remove_unused_plugin_roles(), []);

$DB->tables['role'] = [];
$DB->insert_record('role', (object)['shortname' => 'collegedean', 'name' => 'College Dean', 'description' => 'Signs grade sheets as College Dean. Assign at the college category.']);
$MOCK_CONFIG = ['local_gradesheet' => ['role_collegedean' => 'collegedean']];
$T->assertEqual("Cleanup keeps a plugin role the admin explicitly chose in settings", helper::remove_unused_plugin_roles(), []);
$MOCK_CONFIG = [];
$T->assertEqual("Without an explicit setting, the only Dean-like role on the site is still resolved (so it is NOT removed either)", helper::signatory_role_shortname('college_dean'), 'collegedean');
$T->assertEqual("Cleanup keeps 'collegedean' when it is the only dean role the site has", helper::remove_unused_plugin_roles(), []);

// Stale pre-1.12 settings: the old text defaults were persisted into config.
$DB->tables['role'] = [];
$DB->insert_record('role', (object)['shortname' => 'dean', 'name' => 'Dean', 'description' => '']);
$DB->insert_record('role', (object)['shortname' => 'programhead', 'name' => 'Program Head', 'description' => '']);
$DB->insert_record('role', (object)['shortname' => 'registrar', 'name' => 'Registrar', 'description' => '']);
$MOCK_CONFIG = ['local_gradesheet' => ['role_departmenthead' => 'departmenthead', 'role_collegedean' => 'collegedean', 'role_registrar' => 'registrar']];
$T->assertEqual("Stale legacy setting (role gone) is ignored -> auto-detect finds the site's Dean", helper::signatory_role_shortname('college_dean'), 'dean');
$T->assertEqual("Stale legacy setting (role gone) is ignored -> auto-detect finds Program Head", helper::signatory_role_shortname('department_head'), 'programhead');
$T->assertEqual("Legacy 'registrar' setting whose role still exists is honoured", helper::signatory_role_shortname('registrar'), 'registrar');
$T->assertEqual("Upgrade clears every legacy default from config", helper::clear_legacy_role_settings(), ['role_departmenthead', 'role_registrar', 'role_collegedean']);
$T->assertEqual("...leaving the settings on auto", [get_config('local_gradesheet', 'role_departmenthead'), get_config('local_gradesheet', 'role_collegedean'), get_config('local_gradesheet', 'role_registrar')], ['', '', '']);
$T->assertEqual("Upgrade clear is idempotent", helper::clear_legacy_role_settings(), []);
$MOCK_CONFIG = ['local_gradesheet' => ['role_collegedean' => 'ccs_dean_custom']];
$T->assertEqual("A deliberately chosen (non-legacy) shortname is never cleared", helper::clear_legacy_role_settings(), []);
$T->assertEqual("...and still overrides auto-detect even if missing", helper::signatory_role_shortname('college_dean'), 'ccs_dean_custom');
$MOCK_CONFIG = [];
$T->assertEqual("signatory_is_blank: empty, whitespace and legacy placeholders (any case)",
    [helper::signatory_is_blank(''), helper::signatory_is_blank('   '), helper::signatory_is_blank('department head'), helper::signatory_is_blank('Instructor Name'), helper::signatory_is_blank('Juan Dela Cruz')],
    [true, true, true, true, false]);

// 18e. Group override storage rules.
helper::set_group_overrides(1805, 424242, ['courseandyear' => 'X', 'schedule' => 'X', 'instructor' => 'x']);
$T->assertEqual("set_group_overrides ignores a group that is not in the course", $DB->count_records('local_gradesheet_groupcfg', ['groupid' => 424242]), 0);
helper::set_group_overrides(1805, $g2, ['courseandyear' => '  BSCS 2B  ', 'schedule' => '', 'instructor' => '  maria clara  ']);
$ov = helper::get_group_overrides(1805, $g2);
$T->assertEqual("set_group_overrides trims and upper-cases the instructor", [$ov->courseandyear, $ov->schedule, $ov->instructor], ['BSCS 2B', '', 'MARIA CLARA']);
$T->assertEqual("get_group_overrides for group 0 is null", helper::get_group_overrides(1805, 0), null);
mock_reset_scenario();

// =========================================================================
echo "\n======================================================================\n";
echo "BATTERY 19: End-to-End Worked Example (hand-computed, thesis table)\n";
echo "======================================================================\n";
mock_reset_scenario();
$c19 = 1901;
$DB->insert_record('course', (object)['id' => $c19, 'fullname' => 'Computer Programming 1', 'shortname' => 'CS 101']);
mock_config($c19, ['coursenumber' => 'CS 101', 'descriptive' => 'Computer Programming 1', 'courseandyear' => 'BSCS 1A',
    'transmutemode' => 'formula', 'formula' => '1 + (100 - P) * 0.08', 'formulamin' => 1, 'formulamax' => 5, 'formuladecimals' => 1, 'passmark' => 75]);
$k_q = $DB->insert_record('local_gradesheet_categories', (object)['courseid' => $c19, 'name' => 'Quizzes',    'weight' => 30.0, 'sortorder' => 0]);
$k_a = $DB->insert_record('local_gradesheet_categories', (object)['courseid' => $c19, 'name' => 'Activities', 'weight' => 30.0, 'sortorder' => 1]);
$k_e = $DB->insert_record('local_gradesheet_categories', (object)['courseid' => $c19, 'name' => 'Exams',      'weight' => 40.0, 'sortorder' => 2]);
$Q1 = mock_item($c19, 'Quiz 1', 'midterm', $k_q, 10);  $Q2 = mock_item($c19, 'Quiz 2', 'midterm', $k_q, 10);
$A1 = mock_item($c19, 'Activity 1', 'midterm', $k_a, 50); $ME = mock_item($c19, 'Midterm Exam', 'midterm', $k_e, 100);
$Q3 = mock_item($c19, 'Quiz 3', 'finals', $k_q, 20);  $A2 = mock_item($c19, 'Activity 2', 'finals', $k_a, 50); $FE = mock_item($c19, 'Final Exam', 'finals', $k_e, 100);
mock_user(1911, 'Ana',   'Reyes',       $c19);
mock_user(1912, 'Ben',   'Santos',      $c19);
mock_user(1913, 'Carla', 'Cruz',        $c19);
mock_user(1914, 'Dan',   'Dela Cruz',   $c19);
mock_user(1915, 'Eva',   'Evangelista', $c19);
mock_user(1910, 'Prof',  'Teacher',     $c19); mock_teacher_caps(1910);
$scores = [
    //      Q1  Q2  A1  ME   Q3  A2  FE
    1911 => [9, 10, 45, 88,  18, 48, 92],
    1912 => [6,  7, 35, 70,  12, 30, 65],
    1913 => [8, null, 40, 80, 15, null, 78],
    1914 => [10, 10, 50, 100, 20, 50, 100],
    1915 => [10, 10, 50, 100, 20, 50, 100],
];
foreach ($scores as $uid => [$q1, $q2, $a1, $me, $q3, $a2, $fe]) {
    foreach ([[$Q1, $q1], [$Q2, $q2], [$A1, $a1], [$ME, $me], [$Q3, $q3], [$A2, $a2], [$FE, $fe]] as [$item, $val]) {
        if ($val !== null) { mock_grade($item, $uid, (float)$val); }
    }
}
helper::set_student_status($c19, 1914, 'inc');
$USER = (object)['id' => 1910];
helper::reset_caches();
$sheet = gradesheet_service::compute_all_grades($c19);
$rows = [];
foreach ($sheet['rows'] as $r) { $rows[$r['idnumber']] = $r; }

$T->assertEqual("Worked example: roster order", roster_names($sheet['rows']), ['Cruz, Carla', 'Dela Cruz, Dan', 'Evangelista, Eva', 'Reyes, Ana', 'Santos, Ben']);
// Ana: Mid Q=(90+100)/2=95 A=90 E=88 -> 90.7 ; Fin Q=90 A=96 E=92 -> 92.6 ; avg 91.65 -> 1+8.35*0.08 = 1.668 -> 1.7
$ana = helper::compute_student_grades($c19, 1911);
$T->assertRow("Ana Reyes: 90.70 / 92.60 / 91.65 -> 1.7 PASSED", $ana, ['midterm' => 90.7, 'finals' => 92.6, 'average' => 91.65, 'transmuted' => '1.7', 'remarks' => 'PASSED']);
$T->assertRow("Ana Reyes sheet row", $rows['ID1911'], ['midterm' => '1.7', 'finals' => '1.6', 'average' => '1.7', 'remarks' => 'Passed', 'graded' => 7, 'mapped' => 7]);
$T->assertEqual("Ana Reyes adjectival rating", helper::adjectival_rating($ana['average'], $c19), 'Excellent');
// Ben: Mid Q=65 A=70 E=70 -> 68.5 ; Fin Q=60 A=60 E=65 -> 62.0 ; avg 65.25 -> 1+34.75*0.08 = 3.78 -> 3.8
$ben = helper::compute_student_grades($c19, 1912);
$T->assertRow("Ben Santos: 68.50 / 62.00 / 65.25 -> 3.8 FAILED", $ben, ['midterm' => 68.5, 'finals' => 62.0, 'average' => 65.25, 'transmuted' => '3.8', 'remarks' => 'FAILED']);
$T->assertEqual("Ben Santos adjectival rating", helper::adjectival_rating($ben['average'], $c19), 'Failed');
// Carla (skipped Quiz 2 and Activity 2), ungraded skipped:
//   Mid Q=80 A=80 E=80 -> 80 ; Fin Q=75 E=78, no A -> (75*30+78*40)/70 = 76.714 ; avg 78.357 -> 1+21.643*0.08 = 2.731 -> 2.7
$carla = helper::compute_student_grades($c19, 1913);
$T->assertRow("Carla Cruz (2 missing, skipped): 80.00 / 76.71 / 78.36 -> 2.7 PASSED, graded 5/7", $carla, ['midterm' => 80.0, 'finals' => 76.7143, 'average' => 78.3571, 'transmuted' => '2.7', 'remarks' => 'PASSED', 'graded' => 5, 'mapped' => 7, 'missing' => 2]);
// Dan: INC override despite perfect scores.
$T->assertRow("Dan Dela Cruz: INC override hides perfect scores", $rows['ID1914'], ['midterm' => '-', 'finals' => '-', 'average' => '-', 'remarks' => 'Incomplete', 'status' => 'inc']);
// Eva: perfect.
$T->assertRow("Eva Evangelista: 100 / 100 / 100 -> 1.0 PASSED", helper::compute_student_grades($c19, 1915), ['midterm' => 100.0, 'finals' => 100.0, 'average' => 100.0, 'transmuted' => '1.0', 'remarks' => 'PASSED']);
$T->assertRow("Class summary: 4 graded, 3 passed, 1 failed, 1 INC, 75% pass rate", $sheet, ['total' => 4, 'passcount' => 3, 'failcount' => 1, 'othercount' => 1, 'passrate' => 75.0]);
$T->assertEqual("Header: course info and auto-detected instructor", [$sheet['coursenumber'], $sheet['courseandyear'], $sheet['instructor']], ['CS 101', 'BSCS 1A', 'PROF TEACHER']);

// Same roster with ungraded counted as zero: only Carla changes.
mock_set_config($c19, ['missingaszero' => 1]);
$sheetz = gradesheet_service::compute_all_grades($c19);
$carlaz = helper::compute_student_grades($c19, 1913);
//   Mid Q=(80+0)/2=40 A=80 E=80 -> 12+24+32 = 68 ; Fin Q=75 A=0 E=78 -> 22.5+0+31.2 = 53.7 ; avg 60.85 -> 1+39.15*0.08 = 4.132 -> 4.1
$T->assertRow("Carla Cruz (ungraded as 0): 68.00 / 53.70 / 60.85 -> 4.1 FAILED", $carlaz, ['midterm' => 68.0, 'finals' => 53.7, 'average' => 60.85, 'transmuted' => '4.1', 'remarks' => 'FAILED', 'missing' => 2]);
$T->assertRow("Class summary with ungraded as 0: 2 passed, 2 failed, 50%", $sheetz, ['passcount' => 2, 'failcount' => 2, 'passrate' => 50.0]);
$T->assertRow("Ana unaffected by the ungraded rule (no missing work)", helper::compute_student_grades($c19, 1911), ['average' => 91.65]);
mock_set_config($c19, ['missingaszero' => 0]);

// Determinism and single-source-of-truth: the service rows equal the per-student helper output.
$again = gradesheet_service::compute_all_grades($c19);
$T->assertEqual("Computing the sheet twice yields identical rows", $again['rows'], $sheet['rows']);
$consistent = true;
foreach ($sheet['rows'] as $r) {
    if ($r['status'] !== '') { continue; }
    $uid = (int)substr($r['idnumber'], 2);
    $g = helper::compute_student_grades($c19, $uid);
    if ($r['average'] !== $g['transmuted'] || $r['midterm'] !== helper::transmute_equiv($g['midterm'], $c19) || $r['finals'] !== helper::transmute_equiv($g['finals'], $c19)) { $consistent = false; }
}
$T->assert("Every sheet row matches compute_student_grades for that student (one formula for screen, PDF and Excel)", $consistent);

// Print the worked example so it can be pasted into the manuscript.
echo "\n  Worked example (CS 101, Quizzes 30% / Activities 30% / Exams 40%, formula 1 + (100 - P) * 0.08, pass at 75%):\n";
echo sprintf("  %-18s %8s %8s %8s %6s %-8s %-12s\n", 'Student', 'Midterm', 'Finals', 'Average', 'Grade', 'Remarks', 'Rating');
foreach ($sheet['rows'] as $r) {
    $uid = (int)substr($r['idnumber'], 2);
    $g = helper::compute_student_grades($c19, $uid);
    $fmtp = function ($v) { return $v === null ? '-' : number_format($v, 2); };
    echo sprintf("  %-18s %8s %8s %8s %6s %-8s %-12s\n", $r['name'],
        $r['status'] ? '-' : $fmtp($g['midterm']), $r['status'] ? '-' : $fmtp($g['finals']), $r['status'] ? '-' : $fmtp($g['average']),
        $r['average'], $r['remarks'], $r['status'] ? '' : helper::adjectival_rating($g['average'], $c19));
}
echo "\n";
mock_reset_scenario();
