<?php
require_once('../../config.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->libdir.'/grade/grade_item.php');

require_login();

use local_gradesheet\helper;

$courseid = optional_param('courseid', 0, PARAM_INT);

if ($courseid > 0) {
    helper::ensure_course_defaults($courseid);
}

$PAGE->set_url('/local/gradesheet/index.php', $courseid > 0 ? ['courseid' => $courseid] : []);

if ($courseid > 0) {
    $PAGE->set_context(context_course::instance($courseid));
} else {
    $PAGE->set_context(context_system::instance());
}

$PAGE->set_title(get_string('pluginname', 'local_gradesheet'));
$PAGE->set_heading(get_string('pluginname', 'local_gradesheet'));

echo $OUTPUT->header();

$isadmin   = is_siteadmin();
$isstudent = false;

if ($courseid) {
    $ctx   = context_course::instance($courseid);
    $roles = get_user_roles($ctx, $USER->id);
    foreach ($roles as $role) {
        if ($role->shortname === 'student') {
            $isstudent = true;
            break;
        }
    }
}

if ($isadmin) {
    $courses = $DB->get_records('course', null, 'fullname ASC');
    unset($courses[1]);
} else {
    $courses = enrol_get_my_courses();
    unset($courses[1]);
}

echo '<div class="container mt-4">';
echo '<form method="get" action="">';
echo '<div class="form-group">';
echo '<label for="courseid"><strong>Select Course:</strong></label>';
echo '<select name="courseid" id="courseid" class="form-control" onchange="this.form.submit()">';
echo '<option value="">-- Select a Course --</option>';

foreach ($courses as $course) {
    $selected = ($courseid == $course->id) ? 'selected' : '';
    echo "<option value='{$course->id}' {$selected}>{$course->fullname}</option>";
}

echo '</select>';
echo '</div>';
echo '</form>';

if ($courseid) {
    $cfg     = helper::load_course_config($courseid);

    $context = context_course::instance($courseid);
    extract($cfg);

    $categories = $DB->get_records('local_gradesheet_categories',
        ['courseid' => $courseid], 'sortorder ASC');

    if ($isstudent && !$isadmin) {
        $grades = helper::compute_student_grades($courseid, $USER->id, $midweight, $finweight);
        $color  = $grades['remarks'] === 'PASSED' ? '#155724' : '#721c24';
        $bg     = $grades['remarks'] === 'PASSED' ? '#d4edda' : '#f8d7da';

        echo '<hr>';
        echo "<h4>My Grades - {$coursename}</h4>";
        echo '
        <style>
            .grade-card { max-width: 520px; margin: 0 auto; }
            .grade-card .card-header {
                background: #1a1a2e; color: white;
                font-size: 16px; font-weight: bold; text-align: center;
            }
            .grade-row { display: flex; justify-content: space-between;
                         padding: 10px 15px; border-bottom: 1px solid #eee; }
            .grade-row:last-child { border-bottom: none; }
            .grade-label { color: #555; font-weight: 500; }
            .grade-value { font-weight: bold; }
            .remarks-box {
                text-align: center; padding: 15px;
                border-radius: 8px; margin-top: 15px;
                font-size: 20px; font-weight: bold;
            }
        </style>';

        echo '<div class="grade-card">';
        echo '<div class="card">';
        echo '<div class="card-header">My Grade Report</div>';
        echo '<div class="card-body p-0">';

        echo '<div class="grade-row">
                <span class="grade-label">Student ID</span>
                <span class="grade-value">' . s($USER->idnumber) . '</span>
              </div>';
        echo '<div class="grade-row">
                <span class="grade-label">Name</span>
                <span class="grade-value">' . fullname($USER) . '</span>
              </div>';
        echo '<div class="grade-row">
                <span class="grade-label">Course</span>
                <span class="grade-value">' . $coursename . '</span>
              </div>';

        if (!empty($categories)) {
            foreach ($grades['cattotals'] as $catid => $data) {
                if ($data['count'] > 0) {
                    $catAvg = $data['total'] / $data['count'];
                    echo '<div class="grade-row">
                            <span class="grade-label">' . s($data['name']) . ' (' . $data['weight'] . '%)</span>
                            <span class="grade-value">' . number_format($catAvg, 2) . '</span>
                          </div>';
                }
            }
        } else {
            echo '<div class="grade-row">
                    <span class="grade-label">Midterm (' . $mpct . '%)</span>
                    <span class="grade-value">' . number_format($grades['midterm'], 2) . '</span>
                  </div>';
            echo '<div class="grade-row">
                    <span class="grade-label">Finals (' . $fpct . '%)</span>
                    <span class="grade-value">' . number_format($grades['finals'],  2) . '</span>
                  </div>';
        }

        echo '<div class="grade-row" style="background:#f8f9fa">
                <span class="grade-label">Final Average</span>
                <span class="grade-value">' . number_format($grades['average'], 2) . '</span>
              </div>';
        echo '<div class="grade-row" style="background:#f8f9fa">
                <span class="grade-label">Transmuted Grade</span>
                <span class="grade-value" style="font-size:18px">' . $grades['transmuted'] . '</span>
              </div>';

        echo '</div></div>';
        echo '<div class="remarks-box" style="background:' . $bg . '; color:' . $color . '">
                ' . $grades['remarks'] . '
              </div>';
        echo '</div>';

    } else {
        if (!has_capability('local/gradesheet:manage', $context)) {
            echo $OUTPUT->notification('You do not have permission to view grade sheets.', 'error');
            echo $OUTPUT->footer();
            exit;
        }

        $students = helper::get_non_teaching_students(context_course::instance($courseid));

        echo '<hr>';
        echo "<h4>Students and Grades - {$coursename}</h4>";
        echo '<a href="preview.php?courseid='      . $courseid . '" class="btn btn-primary mb-3">Preview & Print</a> ';
        echo '<a href="export.php?courseid='       . $courseid . '" class="btn btn-success mb-3">Download PDF</a> ';
        echo '<a href="export_excel.php?courseid=' . $courseid . '" class="btn btn-warning mb-3">Download Excel</a> ';
        echo '<a href="course_settings.php?courseid=' . $courseid . '" class="btn btn-secondary mb-3">Settings</a>';

        echo '<table class="table table-bordered table-striped">';
        echo '<thead class="thead-dark"><tr>';
        echo '<th>#</th><th>Student ID</th><th>Student Name</th>';

        if (!empty($categories)) {
            foreach ($categories as $cat) {
                echo '<th>' . s($cat->name) . ' (' . $cat->weight . '%)</th>';
            }
        } else {
            echo '<th>Midterm (' . $mpct . '%)</th>';
            echo '<th>Finals (' . $fpct . '%)</th>';
        }

        echo '<th>Average</th><th>Transmuted</th><th>Remarks</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        $passcount = 0;
        $failcount = 0;
        $rownum    = 1;

        foreach ($students as $student) {
            $g          = helper::compute_student_grades($courseid, $student->id, $midweight, $finweight);
            $badgeclass = $g['remarks'] === 'PASSED' ? 'badge-success' : 'badge-danger';

            if ($g['remarks'] === 'PASSED') $passcount++;
            else $failcount++;

            echo "<tr>
                <td>{$rownum}</td>
                <td>{$student->idnumber}</td>
                <td>{$student->lastname}, {$student->firstname}</td>";

            if (!empty($categories)) {
                foreach ($categories as $cat) {
                    $catdata = isset($g['cattotals'][$cat->id]) ? $g['cattotals'][$cat->id] : null;
                    $catavg  = ($catdata && $catdata['count'] > 0)
                        ? number_format($catdata['total'] / $catdata['count'], 2)
                        : '0.00';
                    echo "<td>{$catavg}</td>";
                }
            } else {
                echo '<td>' . number_format($g['midterm'], 2) . '</td>';
                echo '<td>' . number_format($g['finals'],  2) . '</td>';
            }

            echo '<td>' . number_format($g['average'],   2) . '</td>';
            echo '<td><strong>' . $g['transmuted'] . '</strong></td>';
            echo '<td><span class="badge ' . $badgeclass . '">' . $g['remarks'] . '</span></td>';
            echo '</tr>';

            $rownum++;
        }

        echo '</tbody></table>';

        $total    = $passcount + $failcount;
        $passrate = $total > 0 ? round(($passcount / $total) * 100, 1) : 0;
        $failrate = $total > 0 ? round(($failcount / $total) * 100, 1) : 0;

        echo '<div class="card mt-3">';
        echo '<div class="card-header"><strong>Class Summary</strong></div>';
        echo '<div class="card-body">';
        echo '<div class="row text-center">';
        echo "<div class='col-md-3'><h4>{$total}</h4><p class='text-muted'>Total Students</p></div>";
        echo "<div class='col-md-3'><h4 class='text-success'>{$passcount}</h4><p class='text-muted'>Passed ({$passrate}%)</p></div>";
        echo "<div class='col-md-3'><h4 class='text-danger'>{$failcount}</h4><p class='text-muted'>Failed</p></div>";
        echo "<div class='col-md-3'><h4>{$passrate}%</h4><p class='text-muted'>Passing Rate</p></div>";
        echo '</div>';
        echo "<div class='progress mt-2' style='height:25px'>
                <div class='progress-bar bg-success' style='width:{$passrate}%'>{$passrate}% Passed</div>
                <div class='progress-bar bg-danger'  style='width:{$failrate}%'>{$failrate}% Failed</div>
              </div>";
        echo '</div></div>';
    }
} else {
    echo '<hr>';
    echo '<div class="text-center text-muted mt-4">';
    echo '<p style="font-size:18px;">No course selected. Please select a course from the dropdown above.</p>';
    echo '</div>';
}

echo '</div>';
echo $OUTPUT->footer();
