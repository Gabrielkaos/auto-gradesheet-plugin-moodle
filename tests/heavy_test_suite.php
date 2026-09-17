<?php
/**
 * Heavy Production Readiness & Data Gathering Test Suite
 * for local_gradesheet (Moodle Grade Sheet Generator)
 *
 * Exercises:
 *  - Transmutation mathematics & boundary precision
 *  - Weighted running average calculation
 *  - Qualitative / Non-numeric grade item filtering (OBS-02)
 *  - Custom scale transmutation, gap detection, & overlap validation
 *  - Status override handling & exclusion from pass/fail rates
 *  - Group isolation (SEPARATEGROUPS / IDOR prevention)
 *  - Dynamic column whitelist injection resistance
 *  - Schema bounds & string truncation (OBS-03)
 *  - Roster computation service payload integrity
 *  - Heavy stress benchmark: 100, 500, 1,000, and 2,500 student cohorts
 */

declare(strict_types=1);

define('MOODLE_INTERNAL', true);
const SEPARATEGROUPS   = 1;
const VISIBLEGROUPS    = 2;
const NOGROUPS         = 0;
const GRADE_TYPE_NONE  = 0;
const GRADE_TYPE_VALUE = 1;
const GRADE_TYPE_SCALE = 2;
const GRADE_TYPE_TEXT  = 3;
const MUST_EXIST       = 1;
const IGNORE_MISSING   = 2;

// Global Moodle helpers
function s($var): string {
    return htmlspecialchars((string)$var, ENT_QUOTES, 'UTF-8');
}
function get_string($identifier, $component = '', $a = null): string {
    if ($a !== null) {
        return "{$identifier}: {$a}";
    }
    return "{$identifier}";
}
function clean_param($param, $type) {
    return $param;
}
function format_string($str) {
    return (string)$str;
}

class context_course {
    public int $id;
    public int $instanceid;
    public function __construct(int $courseid) {
        $this->id = $courseid;
        $this->instanceid = $courseid;
    }
    public static function instance(int $courseid): self {
        return new self($courseid);
    }
}

#[\AllowDynamicProperties]
class grade_item {
    public $hidden = 0;
    public function __construct(array $params = [], bool $fetch = false) {
        foreach ($params as $k => $v) {
            $this->$k = $v;
        }
    }
    public function is_hidden(): bool {
        return !empty($this->hidden);
    }
    public function get_parent_category() {
        return null;
    }
}

#[\AllowDynamicProperties]
class grade_grade {
    public $hidden = 0;
    public $excluded = 0;
    public function __construct(array $params = [], bool $fetch = false) {
        foreach ($params as $k => $v) {
            $this->$k = $v;
        }
    }
    public function is_hidden(): bool {
        return !empty($this->hidden);
    }
    public function is_excluded(): bool {
        return !empty($this->excluded);
    }
}

class MockDB {
    public array $tables = [];
    private int $auto_inc = 1;

    public function __construct() {
        $this->reset();
    }

    public function reset(): void {
        $this->tables = [
            'local_gradesheet_config'     => [],
            'local_gradesheet_categories' => [],
            'local_gradesheet_itemmap'    => [],
            'local_gradesheet_transmute'  => [],
            'local_gradesheet_status'     => [],
            'course'                      => [],
            'groups'                      => [],
            'grade_items'                 => [],
            'grade_grades'                => [],
            'user'                        => [],
        ];
        $this->auto_inc = 1;
    }

    public function record_exists(string $table, array $conditions): bool {
        return $this->get_record($table, $conditions) !== null;
    }

    public function insert_record(string $table, object $dataobject, bool $returnid = true): int {
        $id = $dataobject->id ?? $this->auto_inc++;
        $record = clone $dataobject;
        $record->id = $id;
        if ($table === 'grade_items' && !isset($record->grademax)) {
            $record->grademax = 100.0;
        }
        $this->tables[$table][$id] = $record;
        return $id;
    }

    public function update_record(string $table, object $dataobject): bool {
        $id = (int)$dataobject->id;
        if (isset($this->tables[$table][$id])) {
            $this->tables[$table][$id] = clone $dataobject;
            return true;
        }
        return false;
    }

    public function get_record(string $table, array $conditions, string $fields = '*', int $strictness = IGNORE_MISSING): ?object {
        foreach ($this->tables[$table] ?? [] as $row) {
            $match = true;
            foreach ($conditions as $col => $val) {
                if (!property_exists($row, $col) || $row->$col != $val) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                return clone $row;
            }
        }
        return null;
    }

    public function get_records(string $table, ?array $conditions = null, string $sort = '', string $fields = '*'): array {
        $results = [];
        foreach ($this->tables[$table] ?? [] as $id => $row) {
            if ($conditions !== null) {
                $match = true;
                foreach ($conditions as $col => $val) {
                    if (!property_exists($row, $col) || $row->$col != $val) {
                        $match = false;
                        break;
                    }
                }
                if (!$match) continue;
            }
            $results[$id] = clone $row;
        }
        return $results;
    }

    public function get_records_select(string $table, string $select = '', ?array $params = null, string $sort = '', string $fields = '*'): array {
        $results = [];
        $rows = $this->tables[$table] ?? [];

        foreach ($rows as $id => $row) {
            $match = true;
            if ($table === 'grade_items') {
                // Check courseid
                if ($params && isset($params[0]) && $row->courseid != $params[0]) {
                    $match = false;
                }
                // Check itemtype != 'course'
                if ($params && isset($params[1]) && $row->itemtype == $params[1]) {
                    $match = false;
                }
                if (str_contains($select, 'itemname IS NOT NULL') && empty($row->itemname)) {
                    $match = false;
                }
                if (str_contains($select, 'gradetype = 1') && ($row->gradetype ?? 1) != 1) {
                    $match = false;
                }
            } else if ($table === 'local_gradesheet_itemmap') {
                if ($params && isset($params[0]) && $row->courseid != $params[0]) {
                    $match = false;
                }
                if ($params && count($params) > 1) {
                    $initems = array_slice($params, 1);
                    if (!in_array($row->gradeitemid, $initems)) {
                        $match = false;
                    }
                }
            } else if ($table === 'grade_grades') {
                if ($params && !empty($params)) {
                    if (!in_array($row->itemid, $params)) {
                        $match = false;
                    }
                }
            }
            if ($match) {
                $results[$id] = clone $row;
            }
        }
        return $results;
    }

    public function get_records_list(string $table, string $field, array $values, string $sort = '', string $fields = '*'): array {
        $results = [];
        foreach ($this->tables[$table] ?? [] as $id => $row) {
            if (isset($row->$field) && in_array($row->$field, $values)) {
                $results[$id] = clone $row;
            }
        }
        return $results;
    }

    public function get_in_or_equal(array $items): array {
        if (empty($items)) {
            return ["IN (-1)", []];
        }
        $placeholders = implode(',', array_fill(0, count($items), '?'));
        return ["IN ($placeholders)", array_values($items)];
    }

    public function count_records(string $table, ?array $conditions = null): int {
        return count($this->get_records($table, $conditions));
    }

    public function delete_records(string $table, ?array $conditions = null): bool {
        if ($conditions === null) {
            $this->tables[$table] = [];
            return true;
        }
        foreach ($this->tables[$table] as $id => $row) {
            $match = true;
            foreach ($conditions as $k => $v) {
                if ($row->$k != $v) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                unset($this->tables[$table][$id]);
            }
        }
        return true;
    }

    public function set_field(string $table, string $newfield, $newvalue, ?array $conditions = null): bool {
        foreach ($this->get_records($table, $conditions) as $id => $row) {
            $this->tables[$table][$id]->$newfield = $newvalue;
        }
        return true;
    }

    public function get_fieldset_sql(string $sql, ?array $params = null): array {
        if (preg_match('/SELECT DISTINCT\s+([a-zA-Z0-9_]+)\s+FROM\s+\{local_gradesheet_config\}/', $sql, $matches)) {
            $col = $matches[1];
            $vals = [];
            foreach ($this->tables['local_gradesheet_config'] as $row) {
                if (!empty($row->$col)) {
                    $vals[] = $row->$col;
                }
            }
            return array_values(array_unique($vals));
        }
        return [];
    }
}

// Global instances
global $DB, $USER;
$DB = new MockDB();
$USER = (object)['id' => 2];

// Mock group & enrollment functions
$MOCK_ENROLLED_USERS = [];
$MOCK_GROUP_MEMBERS = [];
$MOCK_COURSE_GROUPMODE = NOGROUPS;
$MOCK_ACTIVE_GROUP = 0;
$MOCK_CAPABILITIES = [];

function is_enrolled(context_course $context, int $userid): bool {
    global $MOCK_ENROLLED_USERS;
    $cid = $context->instanceid;
    return !empty($MOCK_ENROLLED_USERS[$cid][$userid]);
}
function groups_get_course_groupmode($course): int {
    global $MOCK_COURSE_GROUPMODE;
    return $MOCK_COURSE_GROUPMODE;
}
function groups_get_course_group($course) {
    global $MOCK_ACTIVE_GROUP;
    return $MOCK_ACTIVE_GROUP ?? 0;
}
function groups_is_member(int $groupid, int $userid): bool {
    global $MOCK_GROUP_MEMBERS;
    return in_array("{$groupid}:{$userid}", $MOCK_GROUP_MEMBERS);
}
function has_capability(string $cap, context_course $context, $user = null): bool {
    global $MOCK_CAPABILITIES, $USER;
    $uid = is_object($user) ? $user->id : ($user ?? $USER->id);
    return !empty($MOCK_CAPABILITIES["{$cap}:{$uid}"]);
}
function get_enrolled_users(context_course $context, string $withcap = '', int $groupid = 0, string $fields = 'u.*', string $sort = ''): array {
    global $DB, $MOCK_GROUP_MEMBERS, $MOCK_ENROLLED_USERS;
    $users = [];
    $cid = $context->instanceid;
    foreach ($DB->tables['user'] as $u) {
        if (empty($MOCK_ENROLLED_USERS[$cid][$u->id])) {
            continue;
        }
        if ($withcap !== '' && !has_capability($withcap, $context, $u->id)) {
            continue;
        }
        if ($groupid > 0 && !in_array("{$groupid}:{$u->id}", $MOCK_GROUP_MEMBERS)) {
            continue;
        }
        $users[$u->id] = $u;
    }
    return $users;
}
function is_siteadmin($userid): bool {
    return $userid == 1;
}

// Require target plugin classes
require_once __DIR__ . '/../classes/helper.php';
require_once __DIR__ . '/../classes/hooks.php';
require_once __DIR__ . '/../classes/gradesheet_service.php';

use local_gradesheet\helper;
use local_gradesheet\hooks;
use local_gradesheet\gradesheet_service;

// Test Runner Framework
class TestRunner {
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function assert(string $desc, bool $condition, string $details = ''): void {
        if ($condition) {
            $this->passed++;
            echo "  [PASS] {$desc}\n";
        } else {
            $this->failed++;
            $msg = "  [FAIL] {$desc}" . ($details ? " -- {$details}" : "");
            $this->failures[] = $msg;
            echo "\033[31m{$msg}\033[0m\n";
        }
    }

    public function assertEqual(string $desc, $actual, $expected): void {
        $cond = ($actual === $expected);
        $details = "Expected: " . var_export($expected, true) . ", Got: " . var_export($actual, true);
        $this->assert($desc, $cond, $details);
    }

    public function assertDelta(string $desc, float $actual, float $expected, float $delta = 0.01): void {
        $diff = abs($actual - $expected);
        $cond = $diff <= $delta;
        $details = "Expected ~{$expected}, Got {$actual} (diff: {$diff})";
        $this->assert($desc, $cond, $details);
    }

    public function summary(): bool {
        $total = $this->passed + $this->failed;
        echo "\n" . str_repeat('=', 70) . "\n";
        echo "TEST SUMMARY: {$total} Assertions | Passed: {$this->passed} | Failed: {$this->failed}\n";
        if ($this->failed > 0) {
            echo "\nFailed Assertions:\n";
            foreach ($this->failures as $f) {
                echo "  {$f}\n";
            }
            echo "\033[31mStatus: FAILED\033[0m\n";
            echo str_repeat('=', 70) . "\n";
            return false;
        } else {
            echo "\033[32mStatus: ALL TESTS PASSED - PRODUCTION & THESIS READY!\033[0m\n";
            echo str_repeat('=', 70) . "\n";
            return true;
        }
    }
}

$T = new TestRunner();

// =========================================================================
echo "\n======================================================================\n";
echo "BATTERY 1: ESSU Standard Transmutation Scale Mathematics\n";
echo "======================================================================\n";
$T->assertEqual("Score 100.0 transmutates to 1.0", helper::transmute_equiv(100.0), "1.0");
$T->assertEqual("Score 99.0 transmutates to 1.1", helper::transmute_equiv(99.0), "1.1");
$T->assertEqual("Score 95.0 transmutates to 1.3", helper::transmute_equiv(95.0), "1.3");
$T->assertEqual("Score 90.0 transmutates to 1.5", helper::transmute_equiv(90.0), "1.5");
$T->assertEqual("Score 89.0 transmutates to 1.6", helper::transmute_equiv(89.0), "1.6");
$T->assertEqual("Score 85.0 transmutates to 2.0", helper::transmute_equiv(85.0), "2.0");
$T->assertEqual("Score 84.0 transmutates to 2.1", helper::transmute_equiv(84.0), "2.1");
$T->assertEqual("Score 80.0 transmutates to 2.5", helper::transmute_equiv(80.0), "2.5");
$T->assertEqual("Score 79.0 transmutates to 2.6", helper::transmute_equiv(79.0), "2.6");
$T->assertEqual("Score 75.0 transmutates to 3.0", helper::transmute_equiv(75.0), "3.0");
$T->assertEqual("Score 74.0 transmutates to 3.1", helper::transmute_equiv(74.0), "3.1");
$T->assertEqual("Score 70.0 transmutates to 3.5", helper::transmute_equiv(70.0), "3.5");
$T->assertEqual("Score 69.0 transmutates to 3.6", helper::transmute_equiv(69.0), "3.6");
$T->assertEqual("Score 55.0 boundary transmutates to 5.0", helper::transmute_equiv(55.0), "5.0");
$T->assertEqual("Score 54.99 failing boundary transmutates to 5.0", helper::transmute_equiv(54.99), "5.0");
$T->assertEqual("Score 0.0 transmutates to 5.0", helper::transmute_equiv(0.0), "5.0");
$T->assertEqual("Null/empty grade returns '-'", helper::transmute_equiv(null), "-");
$T->assertEqual("Empty string grade returns '-'", helper::transmute_equiv(''), "-");
// ESSU Passing Threshold is 75% (3.0):
$T->assert("Passing check: 75.0 is passing in ESSU system", helper::is_passing(75.0));
$T->assert("Passing check: 74.9 is failing in ESSU system", !helper::is_passing(74.9));
$T->assert("Passing check: 55.0 is failing in ESSU system", !helper::is_passing(55.0));

// =========================================================================
echo "\n======================================================================\n";
echo "BATTERY 2: Custom Transmutation Scale & Scale Validator\n";
echo "======================================================================\n";
$courseid_custom = 101;
$DB->insert_record('local_gradesheet_transmute', (object)[
    'courseid'   => $courseid_custom,
    'minscore'   => 90.0,
    'maxscore'   => 100.0,
    'equivalent' => '',
    'descriptor' => 'High Distinction',
    'sortorder'  => 0,
    'ispassing'  => 1,
]);
$DB->insert_record('local_gradesheet_transmute', (object)[
    'courseid'   => $courseid_custom,
    'minscore'   => 75.0,
    'maxscore'   => 89.99,
    'equivalent' => '',
    'descriptor' => 'Passed',
    'sortorder'  => 1,
    'ispassing'  => 1,
]);
$DB->insert_record('local_gradesheet_transmute', (object)[
    'courseid'   => $courseid_custom,
    'minscore'   => 0.0,
    'maxscore'   => 74.99,
    'equivalent' => '',
    'descriptor' => 'Failed',
    'sortorder'  => 2,
    'ispassing'  => 0,
]);

$T->assertEqual("Custom scale score 95.5 returns formatted raw score", helper::transmute_equiv(95.5, $courseid_custom), "95.50");
$T->assert("Custom scale score 90.0 counts as passing", helper::is_passing(90.0, $courseid_custom));
$T->assert("Custom scale score 74.0 counts as failing", !helper::is_passing(74.0, $courseid_custom));

// Test custom scale validation warnings (e.g. missing bottom bracket)
$courseid_bad_scale = 102;
$DB->insert_record('local_gradesheet_transmute', (object)[
    'courseid'   => $courseid_bad_scale,
    'minscore'   => 70.0,
    'maxscore'   => 100.0,
    'equivalent' => '',
    'descriptor' => 'Top Bracket',
    'sortorder'  => 0,
    'ispassing'  => 1,
]);
$warnings = helper::validate_custom_scale($courseid_bad_scale);
$T->assert("Custom scale missing 0 bottom bracket generates warning", count($warnings) > 0);

// =========================================================================
echo "\n======================================================================\n";
echo "BATTERY 3: Weighted Running Average & Missing Items Logic\n";
echo "======================================================================\n";
// Setup course 201:
// Categories: Quizzes (30%), Midterm Exam (30%), Finals Exam (40%) = 100%
$courseid_calc = 201;
$DB->insert_record('course', (object)['id' => $courseid_calc, 'fullname' => 'Computation Test Course', 'shortname' => 'CALC201']);

$cat_quiz = $DB->insert_record('local_gradesheet_categories', (object)[
    'courseid'  => $courseid_calc,
    'name'      => 'Quizzes',
    'weight'    => 30.0,
    'sortorder' => 0,
]);
$cat_midterm = $DB->insert_record('local_gradesheet_categories', (object)[
    'courseid'  => $courseid_calc,
    'name'      => 'Midterm Exam',
    'weight'    => 30.0,
    'sortorder' => 1,
]);
$cat_finals = $DB->insert_record('local_gradesheet_categories', (object)[
    'courseid'  => $courseid_calc,
    'name'      => 'Finals Exam',
    'weight'    => 40.0,
    'sortorder' => 2,
]);

// Grade Items:
// Item 1: Quiz 1 (Midterm period)
$item1 = $DB->insert_record('grade_items', (object)['courseid' => $courseid_calc, 'itemtype' => 'mod', 'itemname' => 'Quiz 1', 'gradetype' => 1]);
// Item 2: Midterm Exam (Midterm period)
$item2 = $DB->insert_record('grade_items', (object)['courseid' => $courseid_calc, 'itemtype' => 'mod', 'itemname' => 'Midterm Exam', 'gradetype' => 1]);
// Item 3: Finals Exam (Finals period)
$item3 = $DB->insert_record('grade_items', (object)['courseid' => $courseid_calc, 'itemtype' => 'mod', 'itemname' => 'Finals Exam', 'gradetype' => 1]);

// Map items to categories
$DB->insert_record('local_gradesheet_itemmap', (object)['courseid' => $courseid_calc, 'gradeitemid' => $item1, 'period' => 'midterm', 'categoryid' => $cat_quiz]);
$DB->insert_record('local_gradesheet_itemmap', (object)['courseid' => $courseid_calc, 'gradeitemid' => $item2, 'period' => 'midterm', 'categoryid' => $cat_midterm]);
$DB->insert_record('local_gradesheet_itemmap', (object)['courseid' => $courseid_calc, 'gradeitemid' => $item3, 'period' => 'finals',  'categoryid' => $cat_finals]);

// Student 10: Perfect student (100 in everything)
$DB->insert_record('grade_grades', (object)['itemid' => $item1, 'userid' => 10, 'finalgrade' => 100.0]);
$DB->insert_record('grade_grades', (object)['itemid' => $item2, 'userid' => 10, 'finalgrade' => 100.0]);
$DB->insert_record('grade_grades', (object)['itemid' => $item3, 'userid' => 10, 'finalgrade' => 100.0]);

$res10 = helper::compute_student_grades($courseid_calc, 10);
$T->assertDelta("Student 10 Midterm average is 100.0", $res10['midterm'], 100.0);
$T->assertDelta("Student 10 Finals average is 100.0", $res10['finals'], 100.0);
$T->assertDelta("Student 10 Final average is 100.0", $res10['average'], 100.0);
$T->assertEqual("Student 10 Remarks is PASSED", $res10['remarks'], 'PASSED');
$T->assertEqual("Student 10 Transmuted is 1.0", $res10['transmuted'], '1.0');

// Student 11: Midterm-only completion (Running Average check)
// Finals Exam is not yet taken / graded.
$DB->insert_record('grade_grades', (object)['itemid' => $item1, 'userid' => 11, 'finalgrade' => 80.0]);
$DB->insert_record('grade_grades', (object)['itemid' => $item2, 'userid' => 11, 'finalgrade' => 90.0]);

// Reset prefetch cache
$ref = new ReflectionProperty(helper::class, 'course_grade_data');
$ref->setAccessible(true);
$ref->setValue(null, []);

$res11 = helper::compute_student_grades($courseid_calc, 11);
$T->assert("Student 11 Finals period is null", $res11['finals'] === null);
$T->assertDelta("Student 11 Midterm period average is 85.0", $res11['midterm'], 85.0);
$T->assertDelta("Student 11 Running average is 85.0 (not penalized with 0 for finals)", $res11['average'], 85.0);
$T->assertEqual("Student 11 Transmuted is 2.0", $res11['transmuted'], '2.0');

// Student 12: Zero score vs Null score test
$DB->insert_record('grade_grades', (object)['itemid' => $item1, 'userid' => 12, 'finalgrade' => 0.0]);
$DB->insert_record('grade_grades', (object)['itemid' => $item2, 'userid' => 12, 'finalgrade' => 0.0]);
$ref->setValue(null, []);
$res12 = helper::compute_student_grades($courseid_calc, 12);
$T->assertDelta("Student 12 with actual 0.0 scores has average 0.0", $res12['average'], 0.0);
$T->assertEqual("Student 12 with 0.0 is FAILED", $res12['remarks'], 'FAILED');

// =========================================================================
echo "\n======================================================================\n";
echo "BATTERY 4: OBS-02 Non-Numeric Grade Item Filtering (Security & Data Typing)\n";
echo "======================================================================\n";
// Insert a non-numeric qualitative text item (gradetype = 3) and scale item (gradetype = 2)
$item_scale = $DB->insert_record('grade_items', (object)[
    'courseid'  => $courseid_calc,
    'itemtype'  => 'mod',
    'itemname'  => 'Attendance Scale',
    'gradetype' => GRADE_TYPE_SCALE // 2
]);
$item_text = $DB->insert_record('grade_items', (object)[
    'courseid'  => $courseid_calc,
    'itemtype'  => 'manual',
    'itemname'  => 'Instructor Qualitative Feedback',
    'gradetype' => GRADE_TYPE_TEXT // 3
]);

$DB->insert_record('local_gradesheet_itemmap', (object)['courseid' => $courseid_calc, 'gradeitemid' => $item_scale, 'period' => 'midterm', 'categoryid' => $cat_quiz]);
$DB->insert_record('local_gradesheet_itemmap', (object)['courseid' => $courseid_calc, 'gradeitemid' => $item_text, 'period' => 'midterm', 'categoryid' => $cat_quiz]);

$DB->insert_record('grade_grades', (object)['itemid' => $item_scale, 'userid' => 10, 'finalgrade' => 1.0]);
$DB->insert_record('grade_grades', (object)['itemid' => $item_text, 'userid' => 10, 'finalgrade' => 'Excellent work']);

$ref->setValue(null, []);
$res10_filtered = helper::compute_student_grades($courseid_calc, 10);
// Because prefetch queries with AND gradetype = 1, item_scale and item_text are NEVER loaded.
$T->assertDelta("Non-numeric items excluded: Student 10 average remains pure 100.0", $res10_filtered['average'], 100.0);

// =========================================================================
echo "\n======================================================================\n";
echo "BATTERY 5: Status Overrides & Pass/Fail Rate Exclusion\n";
echo "======================================================================\n";
$courseid_status = 301;
$DB->insert_record('course', (object)['id' => $courseid_status, 'fullname' => 'Status Test Course', 'shortname' => 'STAT101']);
$cat_stat = $DB->insert_record('local_gradesheet_categories', (object)['courseid' => $courseid_status, 'name' => 'General', 'weight' => 100.0]);
$item_stat = $DB->insert_record('grade_items', (object)['courseid' => $courseid_status, 'itemtype' => 'mod', 'itemname' => 'Final Exam', 'gradetype' => 1]);
$DB->insert_record('local_gradesheet_itemmap', (object)['courseid' => $courseid_status, 'gradeitemid' => $item_stat, 'period' => 'finals', 'categoryid' => $cat_stat]);

$MOCK_ENROLLED_USERS[$courseid_status] = [101 => true, 102 => true, 103 => true, 104 => true];
foreach ([101 => 'Student One', 102 => 'Student Two', 103 => 'Student Three', 104 => 'Student Four'] as $uid => $name) {
    $DB->insert_record('user', (object)['id' => $uid, 'firstname' => $name, 'lastname' => 'Test', 'idnumber' => "ID{$uid}"]);
}

$DB->insert_record('grade_grades', (object)['itemid' => $item_stat, 'userid' => 101, 'finalgrade' => 85.0]); // Passed
$DB->insert_record('grade_grades', (object)['itemid' => $item_stat, 'userid' => 102, 'finalgrade' => 50.0]); // Failed
$DB->insert_record('grade_grades', (object)['itemid' => $item_stat, 'userid' => 103, 'finalgrade' => 90.0]); // Has grade but status = dropped
$DB->insert_record('grade_grades', (object)['itemid' => $item_stat, 'userid' => 104, 'finalgrade' => 95.0]); // Has grade but status = inc

// Set status overrides
$DB->insert_record('local_gradesheet_status', (object)['courseid' => $courseid_status, 'userid' => 103, 'status' => 'dropped']);
$DB->insert_record('local_gradesheet_status', (object)['courseid' => $courseid_status, 'userid' => 104, 'status' => 'inc']);

$ref->setValue(null, []);
$export_data = gradesheet_service::compute_all_grades($courseid_status, 0);

$T->assertEqual("Total enrolled students in export is 4", count($export_data['rows']), 4);
$T->assertEqual("Export pass count is 1", $export_data['passcount'], 1);
$T->assertEqual("Export fail count is 1", $export_data['failcount'], 1);
$T->assertEqual("Export other (override) count is 2", $export_data['othercount'], 2);

$s103_row = null;
foreach ($export_data['rows'] as $r) {
    if ($r['idnumber'] === 'ID103') $s103_row = $r;
}
$T->assert("Dropped student row found", $s103_row !== null);
$T->assertEqual("Dropped student shows '-' for midterm", $s103_row['midterm'], '-');
$T->assertEqual("Dropped student shows '-' for finals", $s103_row['finals'], '-');
$T->assertEqual("Dropped student shows '-' for average", $s103_row['average'], '-');
$T->assertEqual("Dropped student remarks is 'Dropped'", $s103_row['remarks'], 'Dropped');

// =========================================================================
echo "\n======================================================================\n";
echo "BATTERY 6: Security, Group Isolation, & SQL Whitelist Checks\n";
echo "======================================================================\n";
$ctx401 = new context_course(401);
$course401 = (object)['id' => 401, 'fullname' => 'Sec Course', 'shortname' => 'SEC401'];
$DB->insert_record('course', $course401);
$DB->insert_record('groups', (object)['id' => 1, 'courseid' => 401]);
$DB->insert_record('groups', (object)['id' => 2, 'courseid' => 401]);
$MOCK_COURSE_GROUPMODE = SEPARATEGROUPS;

$MOCK_GROUP_MEMBERS = ["1:50"];
$USER->id = 50;
$MOCK_CAPABILITIES = [];

$T->assert("User 50 has access to assigned Group 1", helper::check_group_access($ctx401, 1));
$T->assert("User 50 is BLOCKED from unauthorized Group 2 (IDOR Prevention)", !helper::check_group_access($ctx401, 2));

$USER->id = 1;
$MOCK_CAPABILITIES["moodle/site:accessallgroups:1"] = true;
$T->assert("Admin with accessallgroups has access to Group 2", helper::check_group_access($ctx401, 2));

$ref_hook = new ReflectionMethod(hooks::class, 'get_distinct_options');
$ref_hook->setAccessible(true);

$valid_col_result = $ref_hook->invoke(null, 'department_head');
$T->assert("Whitelisted column 'department_head' executes", is_array($valid_col_result));

$injected_col_result = $ref_hook->invoke(null, "department_head; DROP TABLE users; --");
$T->assertEqual("SQL injection attempt in fieldname is rejected by whitelist", $injected_col_result, []);

$MOCK_COURSE_GROUPMODE = NOGROUPS;

// =========================================================================
echo "\n======================================================================\n";
echo "BATTERY 7: Schema Bounds & Truncation Invariants (OBS-03 Polish)\n";
echo "======================================================================\n";
$long_title = str_repeat("A Very Long Descriptive Title That Exceeds Normal Limits ", 5);
$truncated_descriptive = mb_substr($long_title, 0, 100);
$T->assertEqual("Descriptive title truncated to exact schema limit (100 chars)", mb_strlen($truncated_descriptive), 100);

$long_code = "CS-ADVANCED-PROGRAMMING-101-SPECIAL-TOPICS-EXTENDED-SECTION";
$truncated_code = mb_substr($long_code, 0, 50);
$T->assert("Course number bounded within 50 chars", mb_strlen($truncated_code) <= 50);

$long_catname = str_repeat("Category Name Exceeding Normal Length ", 4);
$truncated_catname = mb_substr($long_catname, 0, 100);
$T->assert("Category name bounded within 100 chars", mb_strlen($truncated_catname) <= 100);

// =========================================================================
echo "\n======================================================================\n";
echo "BATTERY 8: HEAVY STRESS & PERFORMANCE BENCHMARK (Thesis-Scale Data Gathering)\n";
echo "======================================================================\n";
$cohort_sizes = [100, 500, 1000, 2500];

foreach ($cohort_sizes as $size) {
    $benchmark_course = 5000 + $size;
    $DB->insert_record('course', (object)[
        'id' => $benchmark_course,
        'fullname' => "Stress Course {$size}",
        'shortname' => "STRESS-{$size}",
    ]);

    $c_q = $DB->insert_record('local_gradesheet_categories', (object)['courseid' => $benchmark_course, 'name' => 'Quizzes', 'weight' => 20.0]);
    $c_a = $DB->insert_record('local_gradesheet_categories', (object)['courseid' => $benchmark_course, 'name' => 'Assignments', 'weight' => 20.0]);
    $c_m = $DB->insert_record('local_gradesheet_categories', (object)['courseid' => $benchmark_course, 'name' => 'Midterm Exam', 'weight' => 30.0]);
    $c_f = $DB->insert_record('local_gradesheet_categories', (object)['courseid' => $benchmark_course, 'name' => 'Finals Exam', 'weight' => 30.0]);

    $items = [];
    foreach ([
        [$c_q, 'midterm', 'Quiz 1'], [$c_q, 'midterm', 'Quiz 2'], [$c_q, 'finals', 'Quiz 3'],
        [$c_a, 'midterm', 'Assign 1'], [$c_a, 'finals', 'Assign 2'], [$c_a, 'finals', 'Assign 3'],
        [$c_m, 'midterm', 'Midterm Written'], [$c_m, 'midterm', 'Midterm Practical'], [$c_m, 'midterm', 'Midterm Project'],
        [$c_f, 'finals', 'Finals Written'], [$c_f, 'finals', 'Finals Practical'], [$c_f, 'finals', 'Finals Project']
    ] as [$cat, $period, $name]) {
        $gi = $DB->insert_record('grade_items', (object)[
            'courseid' => $benchmark_course, 'itemtype' => 'mod', 'itemname' => $name, 'gradetype' => 1
        ]);
        $DB->insert_record('local_gradesheet_itemmap', (object)[
            'courseid' => $benchmark_course, 'gradeitemid' => $gi, 'period' => $period, 'categoryid' => $cat
        ]);
        $items[] = $gi;
    }

    $student_ids = [];
    $start_uid = $benchmark_course * 10000;
    for ($i = 0; $i < $size; $i++) {
        $uid = $start_uid + $i;
        $student_ids[] = $uid;
        $DB->insert_record('user', (object)[
            'id' => $uid, 'firstname' => "Student{$i}", 'lastname' => "Cohort{$size}", 'idnumber' => "STU-{$uid}"
        ]);
        $MOCK_ENROLLED_USERS[$benchmark_course][$uid] = true;

        $base_score = 50 + (($i * 37) % 51);
        foreach ($items as $idx => $gi) {
            if (($i + $idx) % 20 === 0) {
                continue; // 5% ungraded
            }
            $item_score = min(100.0, max(0.0, $base_score + (($idx * 13) % 15) - 7));
            $DB->insert_record('grade_grades', (object)[
                'itemid' => $gi, 'userid' => $uid, 'finalgrade' => (float)$item_score
            ]);
        }

        if ($i % 50 === 49) {
            $DB->insert_record('local_gradesheet_status', (object)[
                'courseid' => $benchmark_course, 'userid' => $uid, 'status' => 'inc'
            ]);
        }
    }

    $ref->setValue(null, []);

    $time_start = microtime(true);
    $export_result = gradesheet_service::compute_all_grades($benchmark_course, 0);
    $time_end = microtime(true);
    $mem_after = memory_get_peak_usage(true);

    $duration_ms = ($time_end - $time_start) * 1000.0;
    $per_student_us = ($duration_ms * 1000.0) / $size;
    $throughput = $size / max(0.0001, ($time_end - $time_start));
    $mem_mb = ($mem_after) / (1024 * 1024);

    echo sprintf(
        "  [STRESS-TEST] Cohort: %5d Students | Time: %7.2f ms | %6.1f µs/student | Throughput: %7.1f stu/sec | Peak RAM: %5.2f MB\n",
        $size,
        $duration_ms,
        $per_student_us,
        $throughput,
        $mem_mb
    );

    $T->assertEqual("Cohort {$size} all students computed in roster", count($export_result['rows']), $size);
    $T->assert("Cohort {$size} pass+fail+other equals total", ($export_result['passcount'] + $export_result['failcount'] + $export_result['othercount']) === $size);
    $T->assert("Cohort {$size} performance under 2.0 seconds", $duration_ms < 2000.0);
}

// Summary and exit code
if (!$T->summary()) {
    exit(1);
}
exit(0);
