<?php
// local_gradesheet demo scenario generator
// -----------------------------------------
// Builds two realistic courses so every multi-section / multi-teacher path
// of the Grade Sheet Generator can be demonstrated without hand setup:
//
//   Course 1  "CS 101 - Computer Programming 1"  (one teacher, three sections)
//     - groups BSCS 1A / 1B / 1C, one editing teacher for all of them
//     - Visible-groups mode, per-section schedules, section 1C label override
//     - the standard 30/30/40 categories, 7 mapped grade items, ESSU linear formula
//     - a student in two sections, two students with missing work,
//       one Incomplete and one Dropped student
//
//   Course 2  "CS 201 - Data Structures"  (three teachers, three sections)
//     - groups BSCS 2A (Teacher Uno), 2B (Teacher Dos), 2C (co-taught by Uno + Dos)
//     - a third editing teacher in no section, and a non-editing teacher
//     - Separate-groups mode, so each teacher sees only their own sections
//     - two students in no section (dashboard warns about them)
//     - one hidden grade item and one unmapped grade item (settings warnings)
//     - base-50 formula capped at 95
//
// Both courses are placed in a dedicated category "Gradesheet Demo (CCS)",
// and the Department Head / College Dean / Registrar roles are assigned so
// the signatory lines fill in automatically.
//
// Usage (from the Moodle root):
//   php local/gradesheet/cli/generate_demo_scenarios.php
//   php local/gradesheet/cli/generate_demo_scenarios.php --per-section=12
//   php local/gradesheet/cli/generate_demo_scenarios.php --delete
//
// All generated users have the username prefix "gsdemo_" and the password
// "Demopass123!". --delete removes exactly those users, the two courses and
// the demo category, nothing else.

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/grade/grade_item.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/grouplib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/group/lib.php');

use local_gradesheet\helper;

list($options, $unrecognized) = cli_get_params(
    ['per-section' => 8, 'delete' => false, 'seed' => 20261002, 'help' => false],
    ['h' => 'help']
);
if ($unrecognized) {
    cli_error("Unrecognized options: " . implode(', ', $unrecognized) . "\nRun with --help for usage.");
}
if ($options['help']) {
    cli_writeln("Create (or delete) the two local_gradesheet demo courses.\n");
    cli_writeln("  --per-section=N   Students per section (default 8)");
    cli_writeln("  --seed=N          Random seed for grades (default 20261002; same seed = same grades)");
    cli_writeln("  --delete          Remove the demo courses, users and category created earlier");
    exit(0);
}

const DEMO_PREFIX    = 'gsdemo_';
const DEMO_PASSWORD  = 'Demopass123!';
const DEMO_CATEGORY  = 'Gradesheet Demo (CCS)';
const DEMO_SHORT_1   = 'GSDEMO-CS101';
const DEMO_SHORT_2   = 'GSDEMO-CS201';

// ---------------------------------------------------------------------
// --delete
// ---------------------------------------------------------------------
if ($options['delete']) {
    foreach ([DEMO_SHORT_1, DEMO_SHORT_2] as $short) {
        if ($course = $DB->get_record('course', ['shortname' => $short])) {
            delete_course($course, false);
            cli_writeln("Deleted course {$short} (id {$course->id}).");
        }
    }
    $users = $DB->get_records_select('user', $DB->sql_like('username', ':p') . ' AND deleted = 0', ['p' => DEMO_PREFIX . '%']);
    foreach ($users as $u) {
        user_delete_user($u);
    }
    cli_writeln('Deleted ' . count($users) . ' demo user accounts (prefix ' . DEMO_PREFIX . ').');
    if ($cat = $DB->get_record('course_categories', ['name' => DEMO_CATEGORY])) {
        if (!$DB->record_exists('course', ['category' => $cat->id])) {
            \core_course_category::get($cat->id)->delete_full(false);
            cli_writeln('Deleted category "' . DEMO_CATEGORY . '".');
        }
    }
    exit(0);
}

foreach ([DEMO_SHORT_1, DEMO_SHORT_2] as $short) {
    if ($DB->record_exists('course', ['shortname' => $short])) {
        cli_error("Course {$short} already exists. Run with --delete first.");
    }
}

mt_srand((int)$options['seed']);
$persection = max(3, (int)$options['per-section']);

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------
$studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
$editingrole = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
$nonedrole   = $DB->get_record('role', ['shortname' => 'teacher'], '*', MUST_EXIST);

$usercounter = 0;
/** Creates (or reuses) a demo user. $key must be unique within this run. */
$demo_user = function (string $key, string $first, string $last, string $idnumber = '') use (&$usercounter, $CFG, $DB): int {
    $username = DEMO_PREFIX . strtolower(preg_replace('/[^a-z0-9]+/i', '_', $key));
    if ($u = $DB->get_record('user', ['username' => $username, 'deleted' => 0])) {
        return (int)$u->id;
    }
    $usercounter++;
    $user = (object)[
        'username' => $username, 'password' => DEMO_PASSWORD,
        'firstname' => $first, 'lastname' => $last,
        'email' => $username . '@example.invalid', 'auth' => 'manual', 'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id,
        'idnumber' => $idnumber !== '' ? $idnumber : sprintf('%02d-%05d', (int)date('y'), 50000 + $usercounter),
    ];
    return (int)user_create_user($user, true, false);
};

$enrol = function (stdClass $course, int $userid, int $roleid) use ($DB): void {
    $plugin = enrol_get_plugin('manual');
    $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
    if (!$instance) {
        $instance = $DB->get_record('enrol', ['id' => $plugin->add_instance($course)]);
    }
    $plugin->enrol_user($instance, $userid, $roleid);
};

$make_group = function (int $courseid, string $name): int {
    return (int)groups_create_group((object)['courseid' => $courseid, 'name' => $name, 'description' => 'Section ' . $name]);
};

/** itemname => [period, category name, grademax, extra grade_item fields, map?] */
$make_items = function (int $courseid, array $specs) use ($DB): array {
    helper::ensure_course_defaults($courseid);
    $cats = [];
    foreach ($DB->get_records('local_gradesheet_categories', ['courseid' => $courseid]) as $c) {
        $cats[$c->name] = (int)$c->id;
    }
    $items = [];
    foreach ($specs as $name => [$period, $catname, $max, $extra, $map]) {
        $gi = new grade_item(array_merge([
            'courseid' => $courseid, 'itemtype' => 'manual', 'itemname' => $name,
            'gradetype' => GRADE_TYPE_VALUE, 'grademax' => $max, 'grademin' => 0,
        ], $extra), false);
        $gi->insert('local/gradesheet');
        if ($map && isset($cats[$catname])) {
            $DB->insert_record('local_gradesheet_itemmap', (object)[
                'courseid' => $courseid, 'gradeitemid' => $gi->id, 'period' => $period, 'categoryid' => $cats[$catname],
            ]);
        }
        $items[$name] = $gi;
        cli_writeln(sprintf('    item %-28s max %3d  %s%s', $name, $max, $map ? "$period / $catname" : 'UNMAPPED', !empty($extra['hidden']) ? '  (hidden)' : ''));
    }
    return $items;
};

/** Realistic score: profile 'strong' | 'average' | 'weak'; returns null for missing work. */
$score_for = function (string $profile, float $max, float $missingchance): ?float {
    if (mt_rand(1, 100) <= (int)($missingchance * 100)) {
        return null;
    }
    [$lo, $hi] = ['strong' => [85, 100], 'average' => [70, 92], 'weak' => [45, 78]][$profile];
    $pct = mt_rand($lo * 10, $hi * 10) / 10;
    return round($max * $pct / 100, 2);
};

$set_config = function (int $courseid, array $fields) use ($DB): void {
    $cfg = $DB->get_record('local_gradesheet_config', ['courseid' => $courseid], '*', MUST_EXIST);
    foreach ($fields as $k => $v) {
        $cfg->$k = $v;
    }
    $cfg->timemodified = time();
    $DB->update_record('local_gradesheet_config', $cfg);
};

$firstnames = ['Juan', 'Maria', 'Jose', 'Ana', 'Pedro', 'Rosa', 'Carlos', 'Elena', 'Miguel', 'Sofia', 'Antonio', 'Carmen',
               'Manuel', 'Isabel', 'Francisco', 'Teresa', 'Ramon', 'Luz', 'Ricardo', 'Grace', 'Liza', 'Noel', 'Bea', 'Dino'];
$lastnames  = ['Santos', 'Reyes', 'Cruz', 'Bautista', 'Ocampo', 'Garcia', 'Torres', 'Mendoza', 'Castro', 'Villanueva',
               'Aquino', 'Del Rosario', 'Navarro', 'Domingo', 'Salazar', 'Pascual', 'Gonzales', 'Ramos', 'Flores', 'Fernandez',
               'Abad', 'Dizon', 'Lim', 'Padilla'];
$nameidx = 0;
$next_name = function () use (&$nameidx, $firstnames, $lastnames): array {
    $f = $firstnames[$nameidx % count($firstnames)];
    $l = $lastnames[(int)($nameidx / count($firstnames) + $nameidx) % count($lastnames)];
    $nameidx++;
    return [$f, $l];
};

// ---------------------------------------------------------------------
// Category + signatories (shared by both courses)
// ---------------------------------------------------------------------
$category = $DB->get_record('course_categories', ['name' => DEMO_CATEGORY]);
if (!$category) {
    $category = \core_course_category::create((object)['name' => DEMO_CATEGORY, 'parent' => 0, 'visible' => 1]);
    $category = $DB->get_record('course_categories', ['id' => $category->id]);
}
cli_writeln('Category: ' . DEMO_CATEGORY . " (id {$category->id})");

helper::ensure_signatory_roles();
$catctx = context_coursecat::instance($category->id);
$sysctx = context_system::instance();
$sig = [
    'departmenthead' => [$demo_user('dept_head', 'Marilou', 'Villamor', 'DH-001'), $catctx, 'Department Head'],
    'collegedean'    => [$demo_user('dean',      'Rogelio', 'Sabornido', 'DEAN-001'), $catctx, 'College Dean'],
    'registrar'      => [$demo_user('registrar', 'Josefina', 'Lacaba', 'REG-001'), $sysctx, 'Registrar'],
];
foreach ($sig as $shortname => [$uid, $ctx, $label]) {
    $roleid = $DB->get_field('role', 'id', ['shortname' => $shortname]);
    if ($roleid && !$DB->record_exists('role_assignments', ['roleid' => $roleid, 'userid' => $uid, 'contextid' => $ctx->id])) {
        role_assign($roleid, $uid, $ctx->id);
    }
    cli_writeln("Signatory: {$label} -> " . fullname($DB->get_record('user', ['id' => $uid])) . ' (role ' . $shortname . ' at ' . $ctx->get_context_name(false) . ')');
}

// Teachers used across both courses.
$t_uno   = $demo_user('teacher_uno',   'Teodoro', 'Uno',    'T-001');
$t_dos   = $demo_user('teacher_dos',   'Teresa',  'Dos',    'T-002');
$t_tres  = $demo_user('teacher_tres',  'Tomas',   'Tres',   'T-003');
$t_view  = $demo_user('teacher_view',  'Nena',    'Viewer', 'T-004');

// ---------------------------------------------------------------------
// COURSE 1: one teacher, three sections
// ---------------------------------------------------------------------
cli_writeln('');
cli_writeln('== Course 1: CS 101 (one teacher, three sections) ==');
$c1 = create_course((object)[
    'fullname' => 'CS 101 - Computer Programming 1', 'shortname' => DEMO_SHORT_1, 'category' => $category->id,
    'summary' => 'Demo course for local_gradesheet: one teacher, sections 1A/1B/1C.', 'summaryformat' => FORMAT_HTML,
    'visible' => 1, 'startdate' => time() - 60 * 86400, 'groupmode' => VISIBLEGROUPS, 'groupmodeforce' => 0,
]);
cli_writeln("Created course (id {$c1->id}).");
$enrol($c1, $t_uno, $editingrole->id);

$items1 = $make_items($c1->id, [
    'Quiz 1'        => ['midterm', 'Quizzes',    10,  [], true],
    'Quiz 2'        => ['midterm', 'Quizzes',    10,  [], true],
    'Activity 1'    => ['midterm', 'Activities', 50,  [], true],
    'Midterm Exam'  => ['midterm', 'Exams',      100, [], true],
    'Quiz 3'        => ['finals',  'Quizzes',    20,  [], true],
    'Activity 2'    => ['finals',  'Activities', 50,  [], true],
    'Final Exam'    => ['finals',  'Exams',      100, [], true],
]);
$set_config($c1->id, [
    'coursenumber' => 'CS 101', 'descriptive' => 'Computer Programming 1', 'courseandyear' => '', 'schedule' => 'MW 8:00-9:30 AM',
    'units' => '3', 'semester' => 'First Semester', 'schoolyear' => date('Y') . '-' . (date('Y') + 1),
    'transmutemode' => 'formula', 'formula' => '1 + (100 - P) * 0.08', 'formulamin' => 1, 'formulamax' => 5,
    'formuladecimals' => 1, 'passmark' => 75, 'missingaszero' => 0, 'includehidden' => 1,
]);

$g1 = ['BSCS 1A' => $make_group($c1->id, 'BSCS 1A'), 'BSCS 1B' => $make_group($c1->id, 'BSCS 1B'), 'BSCS 1C' => $make_group($c1->id, 'BSCS 1C')];
foreach ($g1 as $name => $gid) {
    groups_add_member($gid, $t_uno);
}
helper::set_group_overrides($c1->id, $g1['BSCS 1A'], ['courseandyear' => '', 'schedule' => 'MW 8:00-9:30 AM', 'instructor' => '']);
helper::set_group_overrides($c1->id, $g1['BSCS 1B'], ['courseandyear' => '', 'schedule' => 'TTH 10:00-11:30 AM', 'instructor' => '']);
helper::set_group_overrides($c1->id, $g1['BSCS 1C'], ['courseandyear' => 'BSCS 1C (Evening)', 'schedule' => 'MW 5:30-7:00 PM', 'instructor' => '']);

$students1 = []; // section => [userid, profile]
$k = 0;
foreach ($g1 as $sname => $gid) {
    for ($i = 1; $i <= $persection; $i++) {
        [$f, $l] = $next_name();
        $uid = $demo_user("c1_{$sname}_{$i}", $f, $l);
        $enrol($c1, $uid, $studentrole->id);
        groups_add_member($gid, $uid);
        $profile = ['strong', 'average', 'average', 'weak'][$k % 4];
        $students1[$sname][] = [$uid, $profile];
        $k++;
    }
}
// One student sits in both 1A and 1B (shows on both sheets).
$shared = $students1['BSCS 1A'][0][0];
groups_add_member($g1['BSCS 1B'], $shared);
// Grades: first student of 1B has 25% missing work; last student of 1C has none at all in finals.
foreach ($students1 as $sname => $list) {
    foreach ($list as $n => [$uid, $profile]) {
        foreach ($items1 as $iname => $gi) {
            $missing = ($sname === 'BSCS 1B' && $n === 0) ? 0.25 : 0.03;
            if ($sname === 'BSCS 1C' && $n === count($list) - 1 && in_array($iname, ['Quiz 3', 'Activity 2', 'Final Exam'], true)) {
                continue;
            }
            $v = $score_for($profile, (float)$gi->grademax, $missing);
            if ($v !== null) {
                $gi->update_final_grade($uid, $v, 'local/gradesheet');
            }
        }
    }
}
// Status overrides: one Incomplete in 1A, one Dropped in 1C.
helper::set_student_status($c1->id, $students1['BSCS 1A'][2][0], 'inc');
helper::set_student_status($c1->id, $students1['BSCS 1C'][1][0], 'dropped');
cli_writeln("Sections: 1A, 1B, 1C with {$persection} students each (+1 shared between 1A and 1B); 1 INC, 1 Dropped; teacher Teodoro Uno.");

// ---------------------------------------------------------------------
// COURSE 2: three teachers, three sections, separate groups
// ---------------------------------------------------------------------
cli_writeln('');
cli_writeln('== Course 2: CS 201 (three teachers, three sections, separate groups) ==');
$c2 = create_course((object)[
    'fullname' => 'CS 201 - Data Structures and Algorithms', 'shortname' => DEMO_SHORT_2, 'category' => $category->id,
    'summary' => 'Demo course for local_gradesheet: teachers Uno (2A), Dos (2B), Uno+Dos (2C), Tres (no section).',
    'summaryformat' => FORMAT_HTML, 'visible' => 1, 'startdate' => time() - 60 * 86400,
    'groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1,
]);
cli_writeln("Created course (id {$c2->id}).");
foreach ([$t_uno, $t_dos, $t_tres] as $tid) {
    $enrol($c2, $tid, $editingrole->id);
}
$enrol($c2, $t_view, $nonedrole->id);

$items2 = $make_items($c2->id, [
    'Lab 1'                 => ['midterm', 'Activities', 30,  [], true],
    'Lab 2'                 => ['midterm', 'Activities', 30,  [], true],
    'Quiz 1'                => ['midterm', 'Quizzes',    15,  [], true],
    'Midterm Exam'          => ['midterm', 'Exams',      100, [], true],
    'Lab 3'                 => ['finals',  'Activities', 30,  [], true],
    'Quiz 2 (hidden)'       => ['finals',  'Quizzes',    15,  ['hidden' => 1], true],
    'Final Exam'            => ['finals',  'Exams',      100, [], true],
    'Bonus (not mapped)'    => ['finals',  'Quizzes',    10,  [], false],
]);
$set_config($c2->id, [
    'coursenumber' => 'CS 201', 'descriptive' => 'Data Structures and Algorithms', 'courseandyear' => '', 'schedule' => 'TTH 1:00-2:30 PM',
    'units' => '3', 'semester' => 'First Semester', 'schoolyear' => date('Y') . '-' . (date('Y') + 1),
    'transmutemode' => 'formula', 'formula' => '50 + P / 2', 'formulamin' => null, 'formulamax' => 95,
    'formuladecimals' => 0, 'passmark' => 50, 'missingaszero' => 1, 'includehidden' => 1,
]);

$g2 = ['BSCS 2A' => $make_group($c2->id, 'BSCS 2A'), 'BSCS 2B' => $make_group($c2->id, 'BSCS 2B'), 'BSCS 2C' => $make_group($c2->id, 'BSCS 2C')];
groups_add_member($g2['BSCS 2A'], $t_uno);
groups_add_member($g2['BSCS 2B'], $t_dos);
groups_add_member($g2['BSCS 2C'], $t_uno);
groups_add_member($g2['BSCS 2C'], $t_dos);
helper::set_group_overrides($c2->id, $g2['BSCS 2A'], ['courseandyear' => '', 'schedule' => 'TTH 1:00-2:30 PM', 'instructor' => '']);
helper::set_group_overrides($c2->id, $g2['BSCS 2B'], ['courseandyear' => '', 'schedule' => 'MW 1:00-2:30 PM', 'instructor' => '']);
helper::set_group_overrides($c2->id, $g2['BSCS 2C'], ['courseandyear' => '', 'schedule' => 'F 1:00-4:00 PM', 'instructor' => 'TEODORO UNO / TERESA DOS']);

$k = 0;
foreach ($g2 as $sname => $gid) {
    for ($i = 1; $i <= $persection; $i++) {
        [$f, $l] = $next_name();
        $uid = $demo_user("c2_{$sname}_{$i}", $f, $l);
        $enrol($c2, $uid, $studentrole->id);
        groups_add_member($gid, $uid);
        $profile = ['average', 'strong', 'weak', 'average'][$k % 4];
        foreach ($items2 as $iname => $gi) {
            $v = $score_for($profile, (float)$gi->grademax, 0.05);
            if ($v !== null) {
                $gi->update_final_grade($uid, $v, 'local/gradesheet');
            }
        }
        $k++;
    }
}
// Two enrolled students in no section at all.
foreach ([1, 2] as $i) {
    [$f, $l] = $next_name();
    $uid = $demo_user("c2_nosection_{$i}", $f, $l);
    $enrol($c2, $uid, $studentrole->id);
    foreach ($items2 as $gi) {
        $v = $score_for('average', (float)$gi->grademax, 0.05);
        if ($v !== null) {
            $gi->update_final_grade($uid, $v, 'local/gradesheet');
        }
    }
}
cli_writeln("Sections: 2A (Uno), 2B (Dos), 2C (Uno + Dos) with {$persection} students each; 2 students in no section; Tres teaches no section; Viewer is non-editing.");

// Regrade so finalgrade is current.
grade_regrade_final_grades($c1->id);
grade_regrade_final_grades($c2->id);

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
cli_writeln('');
cli_writeln('Done. Demo data is ready.');
cli_writeln('');
cli_writeln('Courses:');
cli_writeln("  CS 101 (one teacher, 3 sections):      {$CFG->wwwroot}/local/gradesheet/index.php?courseid={$c1->id}");
cli_writeln("  CS 201 (three teachers, 3 sections):   {$CFG->wwwroot}/local/gradesheet/index.php?courseid={$c2->id}");
cli_writeln('');
cli_writeln('Logins (password for all: ' . DEMO_PASSWORD . '):');
cli_writeln('  ' . DEMO_PREFIX . 'teacher_uno    Teodoro Uno    - CS 101 (all sections); CS 201 sections 2A and 2C');
cli_writeln('  ' . DEMO_PREFIX . 'teacher_dos    Teresa Dos     - CS 201 sections 2B and 2C');
cli_writeln('  ' . DEMO_PREFIX . 'teacher_tres   Tomas Tres     - CS 201, editing teacher in no section (sees an empty roster)');
cli_writeln('  ' . DEMO_PREFIX . 'teacher_view   Nena Viewer    - CS 201, non-editing teacher (read-only)');
cli_writeln('  ' . DEMO_PREFIX . 'dept_head / dean / registrar   - signatory accounts (roles already assigned)');
cli_writeln('  ' . DEMO_PREFIX . 'c1_bscs_1a_1   example student in CS 101 1A (also in 1B)');
cli_writeln('');
cli_writeln('What to show the panel:');
cli_writeln('  1. CS 101 as teacher_uno: section picker, three separate sheets, different schedules, 1C label override,');
cli_writeln('     student 1A-1 appearing on both 1A and 1B, INC/Dropped rows, missing-work badges, ZIP of all sections.');
cli_writeln('  2. CS 201 as teacher_dos: only 2B and 2C are visible (separate groups); instructor line is Dos on 2B,');
cli_writeln('     the typed co-teacher line on 2C; the combined sheet is not offered.');
cli_writeln('  3. CS 201 as admin: all sections, the "2 students in no section" warning, the hidden-item and unmapped-item');
cli_writeln('     notes on the dashboard, and the base-50/cap-95 formula preview in Settings.');
cli_writeln('');
cli_writeln('To remove everything: php local/gradesheet/cli/generate_demo_scenarios.php --delete');
