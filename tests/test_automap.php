<?php
/**
 * Test Suite for local_gradesheet Auto-Mapping functionality.
 */

declare(strict_types=1);

define('MOODLE_INTERNAL', true);
const GRADE_TYPE_VALUE = 1;
const GRADE_TYPE_NONE  = 0;

global $CFG;
$CFG = (object)['libdir' => __DIR__ . '/stubs', 'dirroot' => __DIR__ . '/..'];

require_once(__DIR__ . '/stubs/gradelib.php');
require_once(__DIR__ . '/../classes/helper.php');
require_once(__DIR__ . '/../classes/observer.php');

use local_gradesheet\helper;
use local_gradesheet\observer;

class AutomapMockDB {
    public $categories = [];
    public $itemmap = [];
    public $grade_items = [];
    private $auto_inc = 1;

    public function get_records(string $table, ?array $conditions = null, string $sort = '', string $fields = '*'): array {
        if ($table === 'local_gradesheet_categories') {
            return $this->categories;
        }
        return [];
    }

    public function get_record(string $table, array $conditions) {
        if ($table === 'local_gradesheet_itemmap') {
            $gid = $conditions['gradeitemid'] ?? 0;
            return $this->itemmap[$gid] ?? null;
        }
        return null;
    }

    public function insert_record(string $table, object $data): int {
        $id = $this->auto_inc++;
        $data->id = $id;
        if ($table === 'local_gradesheet_itemmap') {
            $this->itemmap[$data->gradeitemid] = clone $data;
        }
        return $id;
    }

    public function update_record(string $table, object $data): bool {
        if ($table === 'local_gradesheet_itemmap') {
            $this->itemmap[$data->gradeitemid] = clone $data;
            return true;
        }
        return false;
    }

    public function get_records_select(string $table, string $select, array $params = []): array {
        if ($table === 'grade_items') {
            return $this->grade_items;
        }
        return [];
    }

    public function count_records(string $table, ?array $conditions = null): int {
        if ($table === 'local_gradesheet_itemmap') {
            $count = 0;
            foreach ($this->itemmap as $m) {
                $match = true;
                if ($conditions) {
                    foreach ($conditions as $k => $v) {
                        if (($m->$k ?? null) != $v) { $match = false; break; }
                    }
                }
                if ($match) { $count++; }
            }
            return $count;
        }
        if ($table === 'local_gradesheet_categories') {
            return count($this->categories);
        }
        return 0;
    }
}

global $DB;
$DB = new AutomapMockDB();

// Mock course categories: Quizzes (1), Activities (2), Exams (3)
$DB->categories = [
    1 => (object)['id' => 1, 'courseid' => 101, 'name' => 'Quizzes', 'weight' => 30.0],
    2 => (object)['id' => 2, 'courseid' => 101, 'name' => 'Activities', 'weight' => 30.0],
    3 => (object)['id' => 3, 'courseid' => 101, 'name' => 'Exams', 'weight' => 40.0],
];

echo "========================================================\n";
echo "Testing local_gradesheet Auto-Mapping Engine\n";
echo "========================================================\n";

$pass = 0;
$fail = 0;
$assert = function($desc, $cond) use (&$pass, &$fail) {
    if ($cond) {
        echo "  [PASS] $desc\n";
        $pass++;
    } else {
        echo "  [FAIL] $desc\n";
        $fail++;
    }
};

// Test 1: Quiz Module Item
$item_quiz = (object)[
    'id' => 10,
    'courseid' => 101,
    'itemname' => 'Chapter 1 Quiz',
    'itemmodule' => 'quiz',
    'itemtype' => 'mod',
    'gradetype' => GRADE_TYPE_VALUE,
];
$res = helper::auto_map_grade_item(101, $item_quiz);
$assert("Chapter 1 Quiz auto-maps successfully", $res === true);
$m = $DB->itemmap[10] ?? null;
$assert("Chapter 1 Quiz maps to Quizzes category (id=1)", $m && $m->categoryid === 1);
$assert("Chapter 1 Quiz initial period defaults to midterm", $m && $m->period === 'midterm');

// Test 2: Midterm Quiz Module Item
$item_midquiz = (object)[
    'id' => 11,
    'courseid' => 101,
    'itemname' => 'Midterm Diagnostic Quiz',
    'itemmodule' => 'quiz',
    'itemtype' => 'mod',
    'gradetype' => GRADE_TYPE_VALUE,
];
$res = helper::auto_map_grade_item(101, $item_midquiz);
$assert("Midterm Diagnostic Quiz auto-maps successfully", $res === true);
$m = $DB->itemmap[11] ?? null;
$assert("Midterm Diagnostic Quiz maps to Quizzes category (id=1)", $m && $m->categoryid === 1);
$assert("Midterm Diagnostic Quiz period detected as midterm", $m && $m->period === 'midterm');

// Test 3: Assignment Module Item
$item_assign = (object)[
    'id' => 12,
    'courseid' => 101,
    'itemname' => 'Lab Exercise 1: Linux Commands',
    'itemmodule' => 'assign',
    'itemtype' => 'mod',
    'gradetype' => GRADE_TYPE_VALUE,
];
$res = helper::auto_map_grade_item(101, $item_assign);
$assert("Lab Exercise 1 auto-maps successfully", $res === true);
$m = $DB->itemmap[12] ?? null;
$assert("Lab Exercise 1 maps to Activities category (id=2)", $m && $m->categoryid === 2);

// Test 4: Major Exam created as Quiz module
$item_exam = (object)[
    'id' => 13,
    'courseid' => 101,
    'itemname' => 'Midterm Periodical Examination',
    'itemmodule' => 'quiz', // Note: created using Quiz activity in Moodle!
    'itemtype' => 'mod',
    'gradetype' => GRADE_TYPE_VALUE,
];
$res = helper::auto_map_grade_item(101, $item_exam);
$assert("Midterm Examination auto-maps successfully", $res === true);
$m = $DB->itemmap[13] ?? null;
$assert("Midterm Examination maps to Exams (id=3), overriding quiz module type", $m && $m->categoryid === 3);
$assert("Midterm Examination period is midterm", $m && $m->period === 'midterm');

// Test 5: Final Exam
$item_finexam = (object)[
    'id' => 14,
    'courseid' => 101,
    'itemname' => 'Final Examination',
    'itemmodule' => 'quiz',
    'itemtype' => 'mod',
    'gradetype' => GRADE_TYPE_VALUE,
];
$res = helper::auto_map_grade_item(101, $item_finexam);
$m = $DB->itemmap[14] ?? null;
$assert("Final Examination maps to Exams (id=3)", $m && $m->categoryid === 3);
$assert("Final Examination period is finals", $m && $m->period === 'finals');

// Test 6: Course Total is ignored
$item_course_total = (object)[
    'id' => 15,
    'courseid' => 101,
    'itemname' => 'Course Total',
    'itemtype' => 'course',
    'gradetype' => GRADE_TYPE_VALUE,
];
$res = helper::auto_map_grade_item(101, $item_course_total);
$assert("Course total item is ignored", $res === false && !isset($DB->itemmap[15]));

// Test 7: Batch auto-map unmapped items
$DB->grade_items = [
    20 => (object)['id' => 20, 'courseid' => 101, 'itemname' => 'Homework 1', 'itemmodule' => 'assign', 'itemtype' => 'mod', 'gradetype' => GRADE_TYPE_VALUE],
    21 => (object)['id' => 21, 'courseid' => 101, 'itemname' => 'Pop Quiz 2', 'itemmodule' => 'quiz', 'itemtype' => 'mod', 'gradetype' => GRADE_TYPE_VALUE],
];
$count = helper::auto_map_unmapped_items(101);
$assert("Batch auto-map processed 2 items", $count === 2);
$assert("Homework 1 mapped to Activities", ($DB->itemmap[20]->categoryid ?? 0) === 2);
$assert("Pop Quiz 2 mapped to Quizzes", ($DB->itemmap[21]->categoryid ?? 0) === 1);

echo "\nTest Summary: Passed=$pass, Failed=$fail\n";
exit($fail === 0 ? 0 : 1);
