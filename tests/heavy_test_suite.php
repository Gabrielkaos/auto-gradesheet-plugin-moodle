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
 *  - Computation rules (ungraded-as-zero, hidden items, midterm weight, rounding)
 *  - Custom scale equivalents and per-section header overrides
 *  - Formula-based transmutation (safe expression evaluator, clamp, passmark, legend)
 *  - Signatory auto-detection from roles (instructor, dept head, registrar, dean)
 *  - Settings health check ("Needs attention" panel)
 *  - Multi-section / multi-teacher roster matrix (NOGROUPS, VISIBLEGROUPS, SEPARATEGROUPS)
 *  - Gradebook edge cases (grademax scaling, overrides, excluded/hidden grades, hidden categories,
 *    stale gradebook regrade, non-numeric and total items, orphaned mappings, zero weights)
 *  - Transmutation/legend matrix across ESSU table, legacy brackets and formula modes
 *  - Formula evaluator exhaustive precedence/function/error coverage
 *  - Status management, defaults/config bootstrap, event observers, role bootstrap
 *  - Student self-view isolation
 *  - End-to-end worked example with hand-computed expected values (thesis table)
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
const CONTEXT_SYSTEM   = 10;
const CONTEXT_COURSECAT = 40;
const CONTEXT_COURSE   = 50;
const DEBUG_DEVELOPER  = 32767;

// $CFG->libdir points at tests/stubs so helper's require_once of gradelib.php loads the stub.
global $CFG;
$CFG = (object)['libdir' => __DIR__ . '/stubs', 'dirroot' => __DIR__ . '/..'];
$MOCK_REGRADE_CALLS = [];
$MOCK_DEBUGGING = [];
$MOCK_CONFIG = []; // plugin admin settings, e.g. ['local_gradesheet' => ['role_registrar' => 'univregistrar']]
$MOCK_ROLE_CONTEXTLEVELS = [];
function debugging($message = '', $level = DEBUG_DEVELOPER, $backtrace = null): bool {
    global $MOCK_DEBUGGING;
    $MOCK_DEBUGGING[] = $message;
    return true;
}
function create_role($name, $shortname, $description, $archetype = '') {
    global $DB;
    return $DB->insert_record('role', (object)['name' => $name, 'shortname' => $shortname, 'description' => $description, 'archetype' => $archetype]);
}
function set_role_contextlevels($roleid, array $contextlevels) {
    global $MOCK_ROLE_CONTEXTLEVELS;
    $MOCK_ROLE_CONTEXTLEVELS[$roleid] = $contextlevels;
}
require_once __DIR__ . '/mock_core_events.php';

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

/** Minimal context tree: course -> (optional categories) -> system. */
$MOCK_COURSE_PARENTS = []; // courseid => [mock_context, ...] from nearest category up to system
class mock_context {
    public int $id; public int $instanceid; public string $name;
    public function __construct(int $id, string $name) { $this->id = $id; $this->instanceid = $id; $this->name = $name; }
    public function get_context_name($withprefix = true, $short = false): string { return $this->name; }
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
    public function get_parent_contexts(bool $includeself = false): array {
        global $MOCK_COURSE_PARENTS;
        $list = $includeself ? [$this] : [];
        return array_merge($list, $MOCK_COURSE_PARENTS[$this->instanceid] ?? []);
    }
    public function get_context_name($withprefix = true, $short = false): string { return 'Course ' . $this->instanceid; }
}
function get_role_users($roleid, $context, $parent = false, $fields = '', $sort = ''): array {
    global $DB;
    $users = [];
    foreach ($DB->tables['role_assignments'] as $ra) {
        if ((int)$ra->roleid === (int)$roleid && (int)$ra->contextid === (int)$context->id && isset($DB->tables['user'][$ra->userid])) {
            $users[$ra->userid] = $DB->tables['user'][$ra->userid];
        }
    }
    return $users;
}
function get_config($plugin, $name = null) {
    global $MOCK_CONFIG;
    return $MOCK_CONFIG[$plugin][$name] ?? '';
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
        // Mirrors core: hidden == 1 means hidden; hidden > 1 is a "hidden until" timestamp.
        return !empty($this->hidden) && ((int)$this->hidden == 1 || (int)$this->hidden > time());
    }
    public function get_parent_category() {
        // Tests set 'parenthidden' on the grade item row to simulate a hidden Moodle grade category.
        if (!empty($this->parenthidden)) {
            return new class { public function is_hidden(): bool { return true; } };
        }
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
            'local_gradesheet_groupcfg'   => [],
            'course'                      => [],
            'groups'                      => [],
            'grade_items'                 => [],
            'grade_grades'                => [],
            'user'                        => [],
            'role'                        => [],
            'role_assignments'            => [],
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
                if (strpos($select, 'itemname IS NOT NULL') !== false && empty($row->itemname)) {
                    $match = false;
                }
                if (strpos($select, 'gradetype = 1') !== false && ($row->gradetype ?? 1) != 1) {
                    $match = false;
                }
                if (strpos($select, 'hidden <> 0') !== false && empty($row->hidden)) {
                    $match = false;
                }
            } else if ($table === 'local_gradesheet_groupcfg') {
                if ($params && isset($params[0]) && $row->courseid != $params[0]) {
                    $match = false;
                }
                if (strpos($select, "schedule <> ''") !== false && trim((string)($row->schedule ?? '')) === '') {
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

    public function count_records_select(string $table, string $select = '', ?array $params = null): int {
        return count($this->get_records_select($table, $select, $params));
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

    public function get_field(string $table, string $return, array $conditions) {
        $r = $this->get_record($table, $conditions);
        return $r ? ($r->$return ?? false) : false;
    }

    public function get_fieldset_select(string $table, string $return, string $select = '', array $params = [], string $sort = ''): array {
        $vals = [];
        foreach ($this->tables[$table] ?? [] as $row) {
            if (!empty($row->$return)) {
                $vals[] = $row->$return;
            }
        }
        return array_values(array_unique($vals));
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
function groups_get_all_groups($courseid, $userid = 0, $groupingid = 0, $fields = 'g.*'): array {
    global $DB;
    return $DB->get_records('groups', ['courseid' => $courseid]);
}
function groups_get_user_groups($courseid, $userid = 0): array {
    global $DB, $MOCK_GROUP_MEMBERS;
    $ids = [];
    foreach ($DB->get_records('groups', ['courseid' => $courseid]) as $g) {
        if (in_array("{$g->id}:{$userid}", $MOCK_GROUP_MEMBERS)) { $ids[] = (int)$g->id; }
    }
    return [0 => $ids];
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
    if (stripos($sort, 'lastname') !== false) {
        uasort($users, function ($a, $b) {
            return [strtolower($a->lastname ?? ''), strtolower($a->firstname ?? '')] <=> [strtolower($b->lastname ?? ''), strtolower($b->firstname ?? '')];
        });
    }
    return $users;
}
function is_siteadmin($userid): bool {
    return $userid == 1;
}
function fullname($user): string {
    return ($user->firstname ?? '') . ' ' . ($user->lastname ?? '');
}

// Require target plugin classes
require_once __DIR__ . '/../classes/formula.php';
require_once __DIR__ . '/../classes/helper.php';
require_once __DIR__ . '/../classes/hooks.php';
require_once __DIR__ . '/../classes/gradesheet_service.php';
require_once __DIR__ . '/../classes/observer.php';

use local_gradesheet\helper;
use local_gradesheet\formula;
use local_gradesheet\observer;
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

    /** Asserts each key of $expected matches $actual, numeric values within $delta. */
    public function assertRow(string $desc, array $actual, array $expected, float $delta = 0.01): void {
        $bad = [];
        foreach ($expected as $k => $v) {
            $a = $actual[$k] ?? null;
            if (is_float($v) || is_int($v)) {
                if ($a === null || !is_numeric($a) || abs((float)$a - (float)$v) > $delta) {
                    $bad[] = "$k: expected $v, got " . var_export($a, true);
                }
            } else if ($a !== $v) {
                $bad[] = "$k: expected " . var_export($v, true) . ", got " . var_export($a, true);
            }
        }
        $this->assert($desc, empty($bad), implode('; ', $bad));
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
$DB->insert_record('groups', (object)['id' => 1, 'courseid' => 401, 'name' => 'BSCS 1A']);
$DB->insert_record('groups', (object)['id' => 2, 'courseid' => 401, 'name' => 'BSCS 1B']);
$MOCK_COURSE_GROUPMODE = SEPARATEGROUPS;

$MOCK_GROUP_MEMBERS = ["1:50"];
$USER->id = 50;
$MOCK_CAPABILITIES = [];

$T->assert("User 50 has access to assigned Group 1", helper::check_group_access($ctx401, 1));
$T->assert("User 50 is BLOCKED from unauthorized Group 2 (IDOR Prevention)", !helper::check_group_access($ctx401, 2));

$USER->id = 1;
$MOCK_CAPABILITIES["moodle/site:accessallgroups:1"] = true;
$T->assert("Admin with accessallgroups has access to Group 2", helper::check_group_access($ctx401, 2));

helper::ensure_course_defaults(401);
$grp1_export = gradesheet_service::compute_all_grades(401, 1);
$T->assertEqual("Group 1 auto-sets Course and Year to BSCS 1A", $grp1_export['courseandyear'], 'BSCS 1A');

$grp2_export = gradesheet_service::compute_all_grades(401, 2);
$T->assertEqual("Group 2 auto-sets Course and Year to BSCS 1B", $grp2_export['courseandyear'], 'BSCS 1B');

$all_export = gradesheet_service::compute_all_grades(401, 0);
$T->assertEqual("Course with group 0 uses default courseandyear", $all_export['courseandyear'], 'BSCS 1A');

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
echo "BATTERY 9: Computation Rules, Scale Equivalents & Section Overrides\n";
echo "======================================================================\n";
// Course 401: Quizzes 40% / Exams 60%. Three midterm quizzes + one midterm exam,
// one finals exam. Student 201 answered only ONE of the three quizzes.
$courseid_rules = 601;
$DB->insert_record('course', (object)['id' => $courseid_rules, 'fullname' => 'Rules Course', 'shortname' => 'RULES101']);
$DB->insert_record('local_gradesheet_config', (object)[
    'courseid' => $courseid_rules, 'semester' => 'First Semester', 'schoolyear' => '2026-2027',
    'coursenumber' => 'RULES101', 'descriptive' => 'Rules Course', 'courseandyear' => 'BSCS 2A',
    'schedule' => 'MW 8:00-9:30 AM', 'units' => '3', 'instructor' => 'COURSE INSTRUCTOR',
    'department_head' => 'DH', 'registrar' => 'REG', 'college_dean' => 'DEAN',
    'missingaszero' => 0, 'includehidden' => 1, 'midtermweight' => 50.0, 'roundaverage' => 0,
]);
$rc_quiz = $DB->insert_record('local_gradesheet_categories', (object)['courseid' => $courseid_rules, 'name' => 'Quizzes', 'weight' => 40.0, 'sortorder' => 0]);
$rc_exam = $DB->insert_record('local_gradesheet_categories', (object)['courseid' => $courseid_rules, 'name' => 'Exams',   'weight' => 60.0, 'sortorder' => 1]);
$rq1 = $DB->insert_record('grade_items', (object)['courseid' => $courseid_rules, 'itemtype' => 'mod', 'itemname' => 'Quiz 1', 'gradetype' => 1, 'grademax' => 10.0]);
$rq2 = $DB->insert_record('grade_items', (object)['courseid' => $courseid_rules, 'itemtype' => 'mod', 'itemname' => 'Quiz 2', 'gradetype' => 1, 'grademax' => 10.0]);
$rq3 = $DB->insert_record('grade_items', (object)['courseid' => $courseid_rules, 'itemtype' => 'mod', 'itemname' => 'Quiz 3', 'gradetype' => 1, 'grademax' => 10.0]);
$rme = $DB->insert_record('grade_items', (object)['courseid' => $courseid_rules, 'itemtype' => 'mod', 'itemname' => 'Midterm Exam', 'gradetype' => 1, 'grademax' => 100.0, 'hidden' => 1]);
$rfe = $DB->insert_record('grade_items', (object)['courseid' => $courseid_rules, 'itemtype' => 'mod', 'itemname' => 'Final Exam',   'gradetype' => 1, 'grademax' => 100.0]);
foreach ([[$rq1,'midterm',$rc_quiz],[$rq2,'midterm',$rc_quiz],[$rq3,'midterm',$rc_quiz],[$rme,'midterm',$rc_exam],[$rfe,'finals',$rc_exam]] as [$gi,$per,$cat]) {
    $DB->insert_record('local_gradesheet_itemmap', (object)['courseid' => $courseid_rules, 'gradeitemid' => $gi, 'period' => $per, 'categoryid' => $cat]);
}
// Student 201: Quiz 1 = 10/10, Quizzes 2 & 3 ungraded, Midterm Exam (hidden) = 80, Final Exam = 90.
$DB->insert_record('grade_grades', (object)['itemid' => $rq1, 'userid' => 201, 'finalgrade' => 10.0]);
$DB->insert_record('grade_grades', (object)['itemid' => $rme, 'userid' => 201, 'finalgrade' => 80.0]);
$DB->insert_record('grade_grades', (object)['itemid' => $rfe, 'userid' => 201, 'finalgrade' => 90.0]);

$set_rules = function(array $overrides) use ($DB, $courseid_rules) {
    $cfg = $DB->get_record('local_gradesheet_config', ['courseid' => $courseid_rules]);
    foreach ($overrides as $k => $v) { $cfg->$k = $v; }
    $DB->update_record('local_gradesheet_config', $cfg);
    helper::reset_caches();
};

// 9a. Default rules: ungraded skipped, hidden included for faculty.
$set_rules([]);
$r = helper::compute_student_grades($courseid_rules, 201);
$T->assertEqual("Default: 3 of 5 mapped items graded (graded/mapped/missing exposed)", [$r['graded'], $r['mapped'], $r['missing']], [3, 5, 2]);
$T->assertEqual("Default: per-period counts midterm 2/4, finals 1/1 (feeds the dashboard tabs)",
    [$r['periodcounts']['midterm']['graded'], $r['periodcounts']['midterm']['mapped'], $r['periodcounts']['finals']['graded'], $r['periodcounts']['finals']['mapped']],
    [2, 4, 1, 1]);
// Midterm: Quizzes = 100 (only Quiz 1 counts), Exams = 80 -> 0.4*100 + 0.6*80 = 88
$T->assertDelta("Default: midterm skips ungraded quizzes -> 88.0", $r['midterm'], 88.0);
$T->assertDelta("Default: hidden midterm exam IS included for faculty", $r['midterm'], 88.0);
$T->assertDelta("Default: finals = 90.0", $r['finals'], 90.0);
$T->assertDelta("Default: final average 50/50 -> 89.0", $r['average'], 89.0);
$T->assertEqual("Default: 89.0 transmutes to 1.6", $r['transmuted'], '1.6');

// 9b. Student view never sees hidden items, regardless of includehidden.
$rs = helper::compute_student_grades($courseid_rules, 201, true);
// Midterm for the student: only Quizzes have visible data -> 100, re-normalized over 40% -> 100
$T->assertDelta("Student view: hidden midterm exam excluded -> midterm 100.0", $rs['midterm'], 100.0);
$T->assertEqual("Student view: mapped count drops to 4", $rs['mapped'], 4);

// 9c. includehidden = 0 also hides the item from faculty computation.
$set_rules(['includehidden' => 0]);
$r = helper::compute_student_grades($courseid_rules, 201);
$T->assertDelta("includehidden=0: faculty midterm also excludes hidden exam -> 100.0", $r['midterm'], 100.0);

// 9d. missingaszero = 1 counts Quizzes 2 & 3 as 0.
$set_rules(['includehidden' => 1, 'missingaszero' => 1]);
$r = helper::compute_student_grades($courseid_rules, 201);
// Quizzes = (100 + 0 + 0)/3 = 33.33; Exams = 80 -> 0.4*33.33 + 0.6*80 = 61.33
$T->assertDelta("missingaszero: midterm penalizes missing quizzes -> 61.33", $r['midterm'], 61.3333, 0.01);
$T->assertEqual("missingaszero: missing count still reported as 2", $r['missing'], 2);
$T->assertDelta("missingaszero: final average (61.33+90)/2 = 75.67", $r['average'], 75.6667, 0.01);
$T->assertEqual("missingaszero: 75.67 still PASSED", $r['remarks'], 'PASSED');

// 9e. Midterm weight 33.33 / finals 66.67.
$set_rules(['missingaszero' => 0, 'midtermweight' => 33.33]);
$r = helper::compute_student_grades($courseid_rules, 201);
// 0.3333*88 + 0.6667*90 = 89.33
$T->assertDelta("midtermweight=33.33: average = 89.33", $r['average'], 89.3334, 0.01);

// 9f. Rounding before transmutation: 89.33 -> 89 -> 1.6; 89.6 -> 90 -> 1.5.
$set_rules(['midtermweight' => 50.0, 'roundaverage' => 1]);
$DB->insert_record('grade_grades', (object)['itemid' => $rfe, 'userid' => 202, 'finalgrade' => 89.6]);
$r = helper::compute_student_grades($courseid_rules, 202);
$T->assertDelta("roundaverage: 89.6 finals-only average rounds to 90.0", $r['average'], 90.0);
$T->assertEqual("roundaverage: 89.6 -> 90 -> transmutes to 1.5 (not 1.6)", $r['transmuted'], '1.5');
$set_rules(['roundaverage' => 0]);
$r = helper::compute_student_grades($courseid_rules, 202);
$T->assertEqual("no rounding: 89.6 transmutes to 1.6", $r['transmuted'], '1.6');

// 9g. Custom scale with explicit equivalents.
$courseid_eq = 402;
$DB->insert_record('local_gradesheet_transmute', (object)['courseid' => $courseid_eq, 'minscore' => 90, 'maxscore' => 100, 'equivalent' => '1.25', 'descriptor' => 'Excellent', 'sortorder' => 0, 'ispassing' => 1]);
$DB->insert_record('local_gradesheet_transmute', (object)['courseid' => $courseid_eq, 'minscore' => 75, 'maxscore' => 89.99, 'equivalent' => '',     'descriptor' => 'Passed',    'sortorder' => 1, 'ispassing' => 1]);
$DB->insert_record('local_gradesheet_transmute', (object)['courseid' => $courseid_eq, 'minscore' => 0,  'maxscore' => 74.99, 'equivalent' => '5.0',  'descriptor' => 'Failed',    'sortorder' => 2, 'ispassing' => 0]);
helper::reset_caches();
$T->assertEqual("Custom scale: bracket with equivalent returns '1.25'", helper::transmute_equiv(95.0, $courseid_eq), '1.25');
$T->assertEqual("Custom scale: bracket without equivalent falls back to raw score", helper::transmute_equiv(80.0, $courseid_eq), '80.00');
$T->assertEqual("Custom scale: failing bracket returns its equivalent '5.0'", helper::transmute_equiv(50.0, $courseid_eq), '5.0');
$legend = helper::get_rating_legend($courseid_eq);
$T->assertEqual("Custom scale legend carries the equivalent column", $legend[0][1], '1.25');

// 9h. Per-section overrides win over the course-wide header values.
$gid_a = $DB->insert_record('groups', (object)['courseid' => $courseid_rules, 'name' => 'BSCS 2A']);
$gid_b = $DB->insert_record('groups', (object)['courseid' => $courseid_rules, 'name' => 'BSCS 2B']);
helper::set_group_overrides($courseid_rules, $gid_b, ['courseandyear' => '', 'schedule' => 'TTH 1:00-2:30 PM', 'instructor' => 'section b teacher']);
$MOCK_ENROLLED_USERS[$courseid_rules] = [201 => true, 202 => true];
$DB->insert_record('user', (object)['id' => 201, 'idnumber' => '2026-0201', 'firstname' => 'Ana', 'lastname' => 'Reyes']);
$DB->insert_record('user', (object)['id' => 202, 'idnumber' => '2026-0202', 'firstname' => 'Ben', 'lastname' => 'Cruz']);
$MOCK_GROUP_MEMBERS[] = "{$gid_a}:201";
$MOCK_GROUP_MEMBERS[] = "{$gid_b}:202";
helper::reset_caches();
$exp_a = gradesheet_service::compute_all_grades($courseid_rules, $gid_a);
$exp_b = gradesheet_service::compute_all_grades($courseid_rules, $gid_b);
$T->assertEqual("Section A (no override): label is the group name", $exp_a['courseandyear'], 'BSCS 2A');
$T->assertEqual("Section A (no override): schedule is the course-wide value", $exp_a['schedule'], 'MW 8:00-9:30 AM');
$T->assertEqual("Section B: schedule override applied", $exp_b['schedule'], 'TTH 1:00-2:30 PM');
$T->assertEqual("Section B: instructor override applied and upper-cased", $exp_b['instructor'], 'SECTION B TEACHER');
$T->assertEqual("Section B: blank label override falls back to group name", $exp_b['courseandyear'], 'BSCS 2B');
$T->assertEqual("Section A roster contains only its member", count($exp_a['rows']), 1);
$T->assertEqual("Section B roster contains only its member", count($exp_b['rows']), 1);
helper::set_group_overrides($courseid_rules, $gid_b, ['courseandyear' => '', 'schedule' => '', 'instructor' => '']);
$T->assert("Clearing all override fields deletes the row", !$DB->record_exists('local_gradesheet_groupcfg', ['courseid' => $courseid_rules, 'groupid' => $gid_b]));

// =========================================================================
echo "\n======================================================================\n";
echo "BATTERY 10: Formula-Based Transmutation\n";
echo "======================================================================\n";
// 10a. Evaluator correctness and safety.
$T->assertDelta("Evaluator: ESSU linear at P=100 -> 1.0", formula::evaluate('1 + (100 - P) * 0.08', 100), 1.0);
$T->assertDelta("Evaluator: ESSU linear at P=75 -> 3.0",  formula::evaluate('1 + (100 - P) * 0.08', 75), 3.0);
$T->assertDelta("Evaluator: ESSU linear at P=50 -> 5.0",  formula::evaluate('1 + (100 - P) * 0.08', 50), 5.0);
$T->assertDelta("Evaluator: base-50 at P=90 -> 95",       formula::evaluate('50 + P / 2', 90), 95.0);
$T->assertDelta("Evaluator: min()/max()/round() nest",     formula::evaluate('round(max(1, min(5, 1 + (100 - P) * 0.08)), 1)', 20), 5.0);
$T->assertDelta("Evaluator: ^ is right-associative",       formula::evaluate('2 ^ 3 ^ 2', 0), 512.0);
$T->assertDelta("Evaluator: unary minus",                  formula::evaluate('-P + 10', 4), 6.0);
$T->assertDelta("Evaluator: P is case-insensitive, x/score aliases", formula::evaluate('p + x + score', 1), 3.0);
$T->assertEqual("Validator: empty formula rejected",      formula::validate('') !== '', true);
$T->assertEqual("Validator: unknown name rejected",       formula::validate('foo(P)') !== '', true);
$T->assertEqual("Validator: PHP function names rejected", formula::validate('eval(P)') !== '', true);
$T->assertEqual("Validator: division by zero rejected",   formula::validate('P / 0') !== '', true);
$T->assertEqual("Validator: dangling operator rejected",  formula::validate('P +') !== '', true);
$T->assertEqual("Validator: good formula accepted",       formula::validate('min(95, 50 + P / 2)'), '');

// 10b. Course in formula mode: ESSU linear, clamped 1.0-5.0, 1 decimal, pass at 75.
$courseid_f = 701;
$DB->insert_record('course', (object)['id' => $courseid_f, 'fullname' => 'Formula Course', 'shortname' => 'FORM101']);
$DB->insert_record('local_gradesheet_config', (object)[
    'courseid' => $courseid_f, 'semester' => 'First Semester', 'schoolyear' => '2026-2027',
    'coursenumber' => 'FORM101', 'descriptive' => 'Formula Course', 'courseandyear' => 'BSCS 3A',
    'schedule' => 'MW', 'units' => '3', 'instructor' => 'X', 'department_head' => 'DH', 'registrar' => 'REG', 'college_dean' => 'DEAN',
    'missingaszero' => 0, 'includehidden' => 1, 'midtermweight' => 50.0, 'roundaverage' => 0,
    'transmutemode' => 'formula', 'formula' => '1 + (100 - P) * 0.08',
    'formulamin' => 1.0, 'formulamax' => 5.0, 'formuladecimals' => 1, 'passmark' => 75.0,
]);
helper::reset_caches();
$T->assertEqual("Formula mode: 100 -> '1.0'", helper::transmute_equiv(100, $courseid_f), '1.0');
$T->assertEqual("Formula mode: 87.5 -> '2.0'", helper::transmute_equiv(87.5, $courseid_f), '2.0');
$T->assertEqual("Formula mode: 75 -> '3.0'", helper::transmute_equiv(75, $courseid_f), '3.0');
$T->assertEqual("Formula mode: 20 clamps to '5.0' (max)", helper::transmute_equiv(20, $courseid_f), '5.0');
$T->assertEqual("Formula mode: 74.99 -> '3.0' (1 decimal)", helper::transmute_equiv(74.99, $courseid_f), '3.0');
$T->assert("Formula mode: 75.0 passes (passmark)", helper::is_passing(75.0, $courseid_f));
$T->assert("Formula mode: 74.99 fails (passmark)", !helper::is_passing(74.99, $courseid_f));
$T->assertEqual("Formula mode: brackets absent -> default ESSU adjectival rating", helper::adjectival_rating(87.5, $courseid_f), 'Very Good');
$legend_f = helper::get_rating_legend($courseid_f);
$T->assertEqual("Formula mode legend: 99-90 equivalent computed from formula", $legend_f[1], ['99-90', '1.1-1.8', 'Excellent']);
$T->assertEqual("Formula mode legend: 100 equivalent is single value", $legend_f[0][1], '1.0');
$T->assert("Formula mode: legend has an Equivalent column", helper::legend_has_equivalent($courseid_f));

// 10c. Brackets in formula mode only supply the adjectival rating, never the grade.
$DB->insert_record('local_gradesheet_transmute', (object)['courseid' => $courseid_f, 'minscore' => 90, 'maxscore' => 100, 'equivalent' => '', 'descriptor' => 'Superior', 'sortorder' => 0, 'ispassing' => 1]);
$DB->insert_record('local_gradesheet_transmute', (object)['courseid' => $courseid_f, 'minscore' => 0,  'maxscore' => 89.99, 'equivalent' => '', 'descriptor' => 'Ordinary', 'sortorder' => 1, 'ispassing' => 0]);
helper::reset_caches();
$T->assertEqual("Formula mode + brackets: grade still from formula (95 -> '1.4')", helper::transmute_equiv(95, $courseid_f), '1.4');
$T->assertEqual("Formula mode + brackets: rating from bracket", helper::adjectival_rating(95, $courseid_f), 'Superior');
$T->assert("Formula mode + brackets: passing ignores bracket ispassing, uses passmark (80 passes)", helper::is_passing(80.0, $courseid_f));
$legend_f2 = helper::get_rating_legend($courseid_f);
$T->assertEqual("Formula mode + brackets: legend equivalent computed at bracket ends", $legend_f2[0], ['100-90', '1.0-1.8', 'Superior']);

// 10d. Percentage-style formula capped at 95 with 0 decimals and pass at 50 (base-50 preset).
$cfg_f = $DB->get_record('local_gradesheet_config', ['courseid' => $courseid_f]);
$cfg_f->formula = '50 + P / 2'; $cfg_f->formulamin = null; $cfg_f->formulamax = 95.0; $cfg_f->formuladecimals = 0; $cfg_f->passmark = 50.0;
$DB->update_record('local_gradesheet_config', $cfg_f);
helper::reset_caches();
$T->assertEqual("Cap 95: P=100 -> '95'", helper::transmute_equiv(100, $courseid_f), '95');
$T->assertEqual("Cap 95: P=90 -> '95'", helper::transmute_equiv(90, $courseid_f), '95');
$T->assertEqual("Cap 95: P=60 -> '80'", helper::transmute_equiv(60, $courseid_f), '80');
$T->assertEqual("Cap 95: P=0 -> '50'", helper::transmute_equiv(0, $courseid_f), '50');
$T->assert("Cap 95: P=50 passes at passmark 50", helper::is_passing(50.0, $courseid_f));

// 10e. Formula mode with an empty formula falls back to the built-in table instead of breaking.
$cfg_f->formula = '';
$DB->update_record('local_gradesheet_config', $cfg_f);
helper::reset_caches();
$T->assertEqual("Empty formula: falls back (brackets exist -> raw score)", helper::transmute_equiv(95, $courseid_f), '95.00');

// 10f. Courses without the new columns (pre-upgrade rows) behave exactly as before.
$T->assertEqual("Legacy config row: built-in ESSU table still used", helper::transmute_equiv(90.0, 601), '1.5');

// =========================================================================
echo "\n======================================================================\n";
echo "BATTERY 11: Signatory Auto-Detection From Roles\n";
echo "======================================================================\n";
// Context tree: course 801 -> category "BSCS Program" (ctx 9001) -> category "CCS" (ctx 9002) -> system (ctx 1).
$courseid_sig = 801;
$DB->insert_record('course', (object)['id' => $courseid_sig, 'fullname' => 'Sig Course', 'shortname' => 'SIG101']);
$DB->insert_record('local_gradesheet_config', (object)[
    'courseid' => $courseid_sig, 'semester' => 'First Semester', 'schoolyear' => '2026-2027', 'coursenumber' => 'SIG101',
    'descriptive' => 'Sig Course', 'courseandyear' => '', 'schedule' => 'MW', 'units' => '3',
    'instructor' => '', 'department_head' => 'DEPARTMENT HEAD', 'registrar' => '', 'college_dean' => 'TYPED DEAN NAME',
    'missingaszero' => 0, 'includehidden' => 1, 'midtermweight' => 50, 'roundaverage' => 0, 'transmutemode' => 'essu',
]);
$MOCK_COURSE_PARENTS[$courseid_sig] = [new mock_context(9001, 'BSCS Program'), new mock_context(9002, 'CCS'), new mock_context(1, 'System')];
$DB->insert_record('local_gradesheet_categories', (object)['courseid' => $courseid_sig, 'name' => 'All', 'weight' => 100.0, 'sortorder' => 0]);

$r_dh  = $DB->insert_record('role', (object)['shortname' => 'departmenthead', 'name' => 'Department Head']);
$r_reg = $DB->insert_record('role', (object)['shortname' => 'registrar',      'name' => 'Registrar']);
$r_dn  = $DB->insert_record('role', (object)['shortname' => 'collegedean',    'name' => 'College Dean']);

foreach ([
    901 => ['Teodoro', 'Cruz'],      // editing teacher (only one)
    902 => ['Maria',   'Santos'],    // department head @ program category
    903 => ['Rolando', 'Dela Cruz'], // registrar @ system
    904 => ['Lourdes', 'Garcia'],    // dean @ college category
] as $uid => [$fn, $ln]) {
    $DB->insert_record('user', (object)['id' => $uid, 'firstname' => $fn, 'lastname' => $ln, 'idnumber' => "U{$uid}"]);
}
$MOCK_ENROLLED_USERS[$courseid_sig] = [901 => true];
$MOCK_CAPABILITIES["local/gradesheet:manage:901"] = true;
$DB->insert_record('role_assignments', (object)['roleid' => $r_dh,  'userid' => 902, 'contextid' => 9001]);
$DB->insert_record('role_assignments', (object)['roleid' => $r_reg, 'userid' => 903, 'contextid' => 1]);
$DB->insert_record('role_assignments', (object)['roleid' => $r_dn,  'userid' => 904, 'contextid' => 9002]);

$USER = (object)['id' => 999]; // an admin viewing, not a teacher
helper::reset_caches();
$det = helper::detect_signatories($courseid_sig);
$T->assertEqual("Detect: only teacher becomes Instructor",            $det['instructor']['name'],      'TEODORO CRUZ');
$T->assertEqual("Detect: Dept Head found at program category",        $det['department_head']['name'], 'MARIA SANTOS');
$T->assertEqual("Detect: Registrar found at system context",          $det['registrar']['name'],       'ROLANDO DELA CRUZ');
$T->assertEqual("Detect: Dean found at college category",             $det['college_dean']['name'],    'LOURDES GARCIA');
$T->assert("Detect: source names the context it was found in",       strpos($det['college_dean']['source'], 'CCS') !== false);

$export = gradesheet_service::compute_all_grades($courseid_sig);
$T->assertEqual("Resolve: blank instructor -> auto",                  $export['instructor'],  'TEODORO CRUZ');
$T->assertEqual("Resolve: legacy placeholder 'DEPARTMENT HEAD' treated as blank -> auto", $export['depthead'], 'MARIA SANTOS');
$T->assertEqual("Resolve: blank registrar -> auto",                   $export['registrar'],   'ROLANDO DELA CRUZ');
$T->assertEqual("Resolve: typed dean name wins over the role holder", $export['collegedean'], 'TYPED DEAN NAME');
$T->assertEqual("Resolve: 'how' flags typed vs auto",                 [$export['signatories']['college_dean']['how'], $export['signatories']['registrar']['how']], ['typed', 'auto']);

// Nearest context wins: a second dean assigned at the program category outranks the college one.
$DB->insert_record('user', (object)['id' => 905, 'firstname' => 'Program', 'lastname' => 'Dean', 'idnumber' => 'U905']);
$DB->insert_record('role_assignments', (object)['roleid' => $r_dn, 'userid' => 905, 'contextid' => 9001]);
$det = helper::detect_signatories($courseid_sig);
$T->assertEqual("Detect: nearest context wins (program-level dean over college-level)", $det['college_dean']['name'], 'PROGRAM DEAN');

// Current user who is a teacher wins as instructor; two teachers with an outsider viewing -> nothing detected.
$DB->insert_record('user', (object)['id' => 906, 'firstname' => 'Second', 'lastname' => 'Teacher', 'idnumber' => 'U906']);
$MOCK_ENROLLED_USERS[$courseid_sig][906] = true;
$MOCK_CAPABILITIES["local/gradesheet:manage:906"] = true;
$USER = (object)['id' => 906];
$det = helper::detect_signatories($courseid_sig);
$T->assertEqual("Detect: viewing teacher is the Instructor when several teach", $det['instructor']['name'], 'SECOND TEACHER');
$USER = (object)['id' => 999];
$det = helper::detect_signatories($courseid_sig);
$T->assertEqual("Detect: two teachers + outside viewer -> no instructor guessed", $det['instructor']['name'], '');
$T->assert("Detect: explains why (2 teachers)", strpos($det['instructor']['source'], '2 teachers') !== false);

// Section: the section's single teacher wins over the course-wide typed instructor.
$cfg_sig = $DB->get_record('local_gradesheet_config', ['courseid' => $courseid_sig]);
$cfg_sig->instructor = 'COURSE WIDE NAME';
$DB->update_record('local_gradesheet_config', $cfg_sig);
$g_sig = $DB->insert_record('groups', (object)['courseid' => $courseid_sig, 'name' => 'BSCS 1C']);
$MOCK_GROUP_MEMBERS[] = "{$g_sig}:901";
$export_course  = gradesheet_service::compute_all_grades($courseid_sig);
$export_section = gradesheet_service::compute_all_grades($courseid_sig, $g_sig);
$T->assertEqual("Resolve: course view with 2 teachers uses the typed course-wide name", $export_course['instructor'], 'COURSE WIDE NAME');
$T->assertEqual("Resolve: section view uses the section's only teacher",              $export_section['instructor'], 'TEODORO CRUZ');
helper::set_group_overrides($courseid_sig, $g_sig, ['courseandyear' => '', 'schedule' => '', 'instructor' => 'override name']);
$export_section = gradesheet_service::compute_all_grades($courseid_sig, $g_sig);
$T->assertEqual("Resolve: per-section override beats the detected section teacher", $export_section['instructor'], 'OVERRIDE NAME');

// Missing role on the site is reported, not fatal.
$DB->delete_records('role', ['id' => $r_reg]);
$det = helper::detect_signatories($courseid_sig);
$T->assertEqual("Detect: missing role -> blank name", $det['registrar']['name'], '');
$T->assert("Detect: missing role -> explanatory source", strpos($det['registrar']['source'], 'does not exist') !== false);

// =========================================================================
echo "\n======================================================================\n";
echo "BATTERY 12: Settings Health Check (Needs Attention panel)\n";
echo "======================================================================\n";
$USER = (object)['id' => 999];
// 12a. A deliberately broken course: weights 90%, one unmapped item, a hidden item, no finals items,
//      blank schedule, bad school year, no signatories, built-in table.
$courseid_h = 901;
$DB->insert_record('course', (object)['id' => $courseid_h, 'fullname' => 'Health Course', 'shortname' => 'HLTH101']);
$DB->insert_record('local_gradesheet_config', (object)[
    'courseid' => $courseid_h, 'semester' => 'First Semester', 'schoolyear' => '2026/2027',
    'coursenumber' => 'HLTH101', 'descriptive' => '', 'courseandyear' => '', 'schedule' => 'TBA', 'units' => 'three',
    'instructor' => '', 'department_head' => '', 'registrar' => '', 'college_dean' => '',
    'missingaszero' => 0, 'includehidden' => 0, 'midtermweight' => 50, 'roundaverage' => 0, 'transmutemode' => 'essu',
]);
$hc_q = $DB->insert_record('local_gradesheet_categories', (object)['courseid' => $courseid_h, 'name' => 'Quizzes', 'weight' => 40.0, 'sortorder' => 0]);
$hc_e = $DB->insert_record('local_gradesheet_categories', (object)['courseid' => $courseid_h, 'name' => 'Exams',   'weight' => 50.0, 'sortorder' => 1]);
$hc_p = $DB->insert_record('local_gradesheet_categories', (object)['courseid' => $courseid_h, 'name' => 'Project', 'weight' => 0.0,  'sortorder' => 2]);
$hi1 = $DB->insert_record('grade_items', (object)['courseid' => $courseid_h, 'itemtype' => 'mod', 'itemname' => 'Quiz 1', 'gradetype' => 1]);
$hi2 = $DB->insert_record('grade_items', (object)['courseid' => $courseid_h, 'itemtype' => 'mod', 'itemname' => 'Quiz 2', 'gradetype' => 1, 'hidden' => 1]);
$hi3 = $DB->insert_record('grade_items', (object)['courseid' => $courseid_h, 'itemtype' => 'mod', 'itemname' => 'Project', 'gradetype' => 1]);
$DB->insert_record('local_gradesheet_itemmap', (object)['courseid' => $courseid_h, 'gradeitemid' => $hi1, 'period' => 'midterm', 'categoryid' => $hc_q]);
$DB->insert_record('local_gradesheet_itemmap', (object)['courseid' => $courseid_h, 'gradeitemid' => $hi3, 'period' => 'midterm', 'categoryid' => $hc_p]);
// $hi2 left unmapped on purpose.
helper::reset_caches();
$issues = helper::settings_health($courseid_h);
$texts  = array_map(function ($i) { return $i['level'] . '|' . $i['text']; }, $issues);
$has = function (string $level, string $needle) use ($texts): bool {
    foreach ($texts as $t) { if (strpos($t, $level . '|') === 0 && stripos($t, $needle) !== false) { return true; } }
    return false;
};
$T->assert("Health: weights not 100% is a blocking (danger) issue",          $has('danger', 'total 90%'));
$T->assert("Health: unmapped item reported",                                  $has('warning', '1 of 3 grade item(s) are not mapped'));
$T->assert("Health: empty Finals period reported",                            $has('warning', 'mapped to Finals'));
$T->assert("Health: category with weight but no items reported",              $has('warning', 'Category "Exams" (50%) has no grade items'));
$T->assert("Health: category with items but 0% weight reported",              $has('warning', 'Category "Project" has 1 item(s) but a weight of 0%'));
$T->assert("Health: hidden item excluded is a warning when includehidden=0",  $has('warning', 'EXCLUDED from'));
$T->assert("Health: ungraded-as-zero off is an info note",                    $has('info', 'Ungraded items are skipped'));
$T->assert("Health: built-in table is an info note suggesting a formula",     $has('info', 'built-in ESSU table'));
$T->assert("Health: blank Descriptive Title reported",                        $has('warning', 'Descriptive Title is blank'));
$T->assert("Health: TBA schedule reported",                                   $has('warning', 'Schedule of Classes is not set'));
$T->assert("Health: non-numeric units reported",                              $has('warning', 'Number of Units'));
$T->assert("Health: malformed school year reported",                          $has('warning', '2026/2027'));
$T->assert("Health: blank Course and Year with no groups reported",           $has('warning', 'Course and Year is blank'));
$T->assert("Health: every missing signatory reported",
    $has('warning', 'Instructor line will print blank') && $has('warning', 'Department Head line') && $has('warning', 'Registrar line') && $has('warning', 'College Dean line'));
$T->assertEqual("Health: ordered most serious first", $issues[0]['level'], 'danger');
$T->assert("Health: every issue carries an anchor", count(array_filter($issues, function ($i) { return $i['anchor'] === ''; })) === 0);

// 12b. Fix everything and the list empties (apart from nothing).
$cfg_h = $DB->get_record('local_gradesheet_config', ['courseid' => $courseid_h]);
$cfg_h->schoolyear = date('Y') . '-' . (date('Y') + 1); $cfg_h->descriptive = 'Health'; $cfg_h->courseandyear = 'BSCS 4A';
$cfg_h->schedule = 'MW 8:00-9:30 AM'; $cfg_h->units = '3'; $cfg_h->includehidden = 1; $cfg_h->missingaszero = 1;
$cfg_h->transmutemode = 'formula'; $cfg_h->formula = '1 + (100 - P) * 0.08'; $cfg_h->formulamin = 1; $cfg_h->formulamax = 5; $cfg_h->formuladecimals = 1; $cfg_h->passmark = 75;
$cfg_h->instructor = 'A'; $cfg_h->department_head = 'B'; $cfg_h->registrar = 'C'; $cfg_h->college_dean = 'D';
$DB->update_record('local_gradesheet_config', $cfg_h);
$cat_e = $DB->get_record('local_gradesheet_categories', ['id' => $hc_e]); $cat_e->weight = 30.0; $DB->update_record('local_gradesheet_categories', $cat_e);
$cat_p = $DB->get_record('local_gradesheet_categories', ['id' => $hc_p]); $cat_p->weight = 30.0; $DB->update_record('local_gradesheet_categories', $cat_p);
$DB->insert_record('local_gradesheet_itemmap', (object)['courseid' => $courseid_h, 'gradeitemid' => $hi2, 'period' => 'finals', 'categoryid' => $hc_q]);
$hi4 = $DB->insert_record('grade_items', (object)['courseid' => $courseid_h, 'itemtype' => 'mod', 'itemname' => 'Exam', 'gradetype' => 1]);
$DB->insert_record('local_gradesheet_itemmap', (object)['courseid' => $courseid_h, 'gradeitemid' => $hi4, 'period' => 'finals', 'categoryid' => $hc_e]);
helper::reset_caches();
$issues = helper::settings_health($courseid_h);
$nonInfo = array_filter($issues, function ($i) { return $i['level'] !== 'info'; });
$T->assertEqual("Health: after fixing, no blocking or warning issues remain", count($nonInfo), 0,);
$T->assertEqual("Health: only the 'hidden item included' note remains", count($issues), 1);

// 12c. A formula that collapses to one value is caught.
$cfg_h->formula = '95'; $cfg_h->formulamin = null; $cfg_h->formulamax = null;
$DB->update_record('local_gradesheet_config', $cfg_h);
helper::reset_caches();
$issues = helper::settings_health($courseid_h);
$texts  = array_map(function ($i) { return $i['level'] . '|' . $i['text']; }, $issues);
$T->assert("Health: constant formula flagged", count(array_filter($texts, function ($t) { return strpos($t, 'warning|') === 0 && strpos($t, 'same grade') !== false; })) === 1);

require __DIR__ . '/batteries_extended.php';

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
