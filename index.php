<?php
require_once('../../config.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->libdir.'/grade/grade_item.php');

require_login();

use local_gradesheet\helper;

// ── HANDLE STATUS UPDATE (faculty setting Incomplete/Dropped/WP/In Progress) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('action', '', PARAM_TEXT) === 'setstatus') {
    require_sesskey();
    $scourseid = required_param('courseid', PARAM_INT);
    $studentid = required_param('studentid', PARAM_INT);
    $status    = optional_param('status', '', PARAM_ALPHA);

    require_capability('local/gradesheet:manage', context_course::instance($scourseid));
    helper::set_student_status($scourseid, $studentid, $status);

    redirect(new moodle_url('/local/gradesheet/index.php', ['courseid' => $scourseid]));
}

$courseid = optional_param('courseid', 0, PARAM_INT);
$context = null;

if ($courseid > 0) {
    $course_obj = get_course($courseid);
    require_login($course_obj);
    $context = context_course::instance($courseid);
    helper::ensure_course_defaults($courseid);
}

$PAGE->set_url('/local/gradesheet/index.php', $courseid > 0 ? ['courseid' => $courseid] : []);

if ($context) {
    $PAGE->set_context($context);
} else {
    $PAGE->set_context(context_system::instance());
}

$PAGE->set_title(get_string('pluginname', 'local_gradesheet'));
$PAGE->set_heading(get_string('pluginname', 'local_gradesheet'));

echo $OUTPUT->header();

$isadmin = is_siteadmin();
$admin_too_many = false;

if ($isadmin) {
    if ($DB->count_records('course') > 500) {
        $courses = [];
        $admin_too_many = true;
    } else {
        $courses = $DB->get_records('course', null, 'fullname ASC', 'id, fullname');
    }
} else {
    $courses = enrol_get_my_courses();
}

global $SITE;
if (isset($courses[$SITE->id])) {
    unset($courses[$SITE->id]);
}

if ($admin_too_many && $courseid > 0) {
    $courses[$courseid] = $DB->get_record('course', ['id' => $courseid], 'id, fullname');
}

echo '<div class="container mt-4">';

if ($admin_too_many && !$courseid) {
    echo '<div class="alert alert-info">There are too many courses on this site to display in a dropdown. Please navigate to a specific course and click "Grade Sheet" in the course navigation.</div>';
} else {
    echo '<form method="get" action="">';
    echo '<div class="form-group">';
    echo '<label for="courseid"><strong>Select Course:</strong></label>';
    echo '<select name="courseid" id="courseid" class="form-control" onchange="this.form.submit()">';
    echo '<option value="">-- Select a Course --</option>';

    foreach ($courses as $course) {
        if (!$course) continue;
        $selected = ($courseid == $course->id) ? 'selected' : '';
        $safename = s(format_string($course->fullname));
        echo "<option value='{$course->id}' {$selected}>{$safename}</option>";
    }

    echo '</select>';
    echo '</div>';
    echo '</form>';
}

if ($courseid) {
    $cfg        = helper::load_course_config($courseid);
    $coursename = $cfg['coursename'];

    $context = context_course::instance($courseid);

    $categories = $DB->get_records('local_gradesheet_categories',
        ['courseid' => $courseid], 'sortorder ASC');
    $weightvalid = helper::validate_weight_sum($courseid);

    if (!has_capability('local/gradesheet:manage', $context)) {
        if (!has_capability('local/gradesheet:view', $context)) {
            echo $OUTPUT->notification('You do not have permission to view grade sheets.', 'error');
            echo $OUTPUT->footer();
            exit;
        }
        $grades = helper::compute_student_grades($courseid, $USER->id);
        $mystatus = helper::get_student_status($courseid, $USER->id);

        if ($mystatus !== '') {
            $color = '#555';
            $bg    = '#e2e3e5';
        } else if ($grades['remarks'] === '') {
            // No computable grades yet — neutral styling instead of fail-red.
            $color = '#555';
            $bg    = '#e2e3e5';
        } else {
            $color  = $grades['remarks'] === 'PASSED' ? '#155724' : '#721c24';
            $bg     = $grades['remarks'] === 'PASSED' ? '#d4edda' : '#f8d7da';
        }

        $safecoursename = s(format_string($coursename));
        echo '<hr>';
        echo "<h4>My Grades - {$safecoursename}</h4>";
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
                <span class="grade-value">' . s(fullname($USER)) . '</span>
              </div>';
        echo '<div class="grade-row">
                <span class="grade-label">Course</span>
                <span class="grade-value">' . $safecoursename . '</span>
              </div>';

        if ($mystatus !== '') {
            echo '<div class="grade-row">
                    <span class="grade-label">Status</span>
                    <span class="grade-value">' . s(helper::status_label($mystatus)) . '</span>
                  </div>';
        } else {
            echo '<div class="grade-row">
                    <span class="grade-label">Midterm</span>
                    <span class="grade-value">' . ($grades['midterm'] === null ? '—' : number_format($grades['midterm'], 2)) . '</span>
                  </div>';
            echo '<div class="grade-row">
                    <span class="grade-label">Finals</span>
                    <span class="grade-value">' . ($grades['finals'] === null ? '—' : number_format($grades['finals'], 2)) . '</span>
                  </div>';
        }

        if ($mystatus === '') {
            echo '<div class="grade-row" style="background:#f8f9fa">
                    <span class="grade-label">Final Average</span>
                    <span class="grade-value">' . ($grades['average'] === null ? '—' : number_format($grades['average'], 2)) . '</span>
                  </div>';
            $is_custom = helper::get_custom_transmute_rows($courseid) ? true : false;
            if (!$is_custom) {
                echo '<div class="grade-row" style="background:#f8f9fa">
                        <span class="grade-label">Transmuted Grade</span>
                        <span class="grade-value" style="font-size:18px">' . s($grades['transmuted']) . '</span>
                      </div>';
            }
        }

        echo '</div></div>';
        $myremarks = ($mystatus !== '')
            ? s(helper::status_label($mystatus))
            : ($grades['remarks'] !== '' ? s($grades['remarks']) : '—');
        echo '<div class="remarks-box" style="background:' . $bg . '; color:' . $color . '">
                ' . $myremarks . '
              </div>';
        echo '</div>';

    } else {
        $students  = helper::get_non_teaching_students($context);
        $statusmap = helper::get_status_map($courseid);
        $statusoptions = helper::status_options();

        $safecoursename = s(format_string($coursename));
        echo '<hr>';
        echo "<h4>Students and Grades - {$safecoursename}</h4>";

        if (!$weightvalid['valid']) {
            echo '<div class="alert alert-danger d-flex align-items-center" role="alert" style="font-size:16px; padding:15px 20px;">';
            echo '<span style="font-size:28px; margin-right:12px;">&#9888;</span>';
            echo '<div>';
            echo '<strong>WARNING:</strong> Category weights must sum to exactly <strong>100%</strong>. ';
            echo 'Current total: <strong>' . $weightvalid['total'] . '%</strong>. ';
            if ($weightvalid['count'] === 0) {
                echo 'You have <strong>no categories</strong> defined. ';
            }
            echo 'Printing and exporting are <strong>disabled</strong> until this is corrected. ';
            echo '<a href="course_settings.php?courseid=' . $courseid . '" class="btn btn-light btn-sm ml-2"><strong>Go to Settings</strong></a>';
            echo '</div></div>';
        }

        // Mapping sanity warnings: unmapped items and empty periods never
        // block printing, but faculty must see why columns show "-".
        $mapwarn = helper::get_mapping_warnings($courseid);
        if ($mapwarn !== null && ($mapwarn['unmapped'] > 0 || $mapwarn['midterm'] === 0 || $mapwarn['finals'] === 0)) {
            echo '<div class="alert alert-warning d-flex align-items-center" role="alert" style="font-size:16px; padding:15px 20px;">';
            echo '<span style="font-size:28px; margin-right:12px;">&#9888;</span>';
            echo '<div>';
            echo '<strong>WARNING:</strong> ';
            $parts = [];
            if ($mapwarn['unmapped'] > 0) {
                $parts[] = get_string('warnunmappeditems', 'local_gradesheet', $mapwarn['unmapped']);
            }
            if ($mapwarn['midterm'] === 0) {
                $parts[] = get_string('warnnoperioditems', 'local_gradesheet', 'Midterm');
            }
            if ($mapwarn['finals'] === 0) {
                $parts[] = get_string('warnnoperioditems', 'local_gradesheet', 'Finals');
            }
            echo implode(' ', $parts);
            echo '<a href="course_settings.php?courseid=' . $courseid . '" class="btn btn-light btn-sm ml-2"><strong>Go to Settings</strong></a>';
            echo '</div></div>';
        }

        $btncls = $weightvalid['valid'] ? '' : ' disabled';
        echo '<a href="preview.php?courseid='      . $courseid . '" class="btn btn-primary mb-3' . $btncls . '">Preview & Print</a> ';
        echo '<a href="export.php?courseid='       . $courseid . '" class="btn btn-success mb-3' . $btncls . '">Download PDF</a> ';
        echo '<a href="export_excel.php?courseid=' . $courseid . '" class="btn btn-warning mb-3' . $btncls . '">Download Excel</a> ';
        echo '<a href="course_settings.php?courseid=' . $courseid . '" class="btn btn-secondary mb-3">Settings</a>';

        echo '<div class="row align-items-end mb-3">';

        echo '<div class="col-md-5 mb-2">';
        echo '<label class="small text-muted mb-1" for="gradesheetStudentSearch">Search</label>';
        echo '<div class="input-group">';
        echo '<div class="input-group-prepend"><span class="input-group-text">&#128269;</span></div>';
        echo '<input type="text" id="gradesheetStudentSearch" class="form-control" placeholder="Search by name or student ID..." onkeyup="gradesheetFilterStudents()" autocomplete="off">';
        echo '</div>';
        echo '</div>';

        echo '<div class="col-md-3 mb-2">';
        echo '<label class="small text-muted mb-1" for="gradesheetRemarksFilter">Remarks</label>';
        echo '<select id="gradesheetRemarksFilter" class="form-control" onchange="gradesheetFilterStudents()">';
        echo '<option value="">All Remarks</option>';
        echo '<option value="passed">Passed</option>';
        echo '<option value="failed">Failed</option>';
        echo '</select>';
        echo '</div>';

        echo '<div class="col-md-3 mb-2">';
        echo '<label class="small text-muted mb-1" for="gradesheetStatusFilter">Status</label>';
        echo '<select id="gradesheetStatusFilter" class="form-control" onchange="gradesheetFilterStudents()">';
        echo '<option value="">All Statuses</option>';
        foreach ($statusoptions as $val => $label) {
            $optval = ($val === '') ? 'active' : $val;
            echo '<option value="' . s($optval) . '">' . s($label) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="col-md-1 mb-2">';
        echo '<button type="button" class="btn btn-outline-secondary btn-block" onclick="gradesheetResetFilters()" title="Clear filters">&#10005;</button>';
        echo '</div>';

        echo '<div class="col-12"><small class="form-text text-muted" id="gradesheetSearchCount"></small></div>';
        echo '</div>';

        echo '<table class="table table-bordered table-striped" id="gradesheetStudentTable">';
        echo '<thead class="thead-dark"><tr>';
        echo '<th>#</th><th>Student ID</th><th>Student Name</th>';

        if (!empty($categories)) {
            foreach ($categories as $cat) {
                echo '<th>' . s($cat->name) . ' (' . $cat->weight . '%)</th>';
            }
        }

        echo '<th>Midterm</th><th>Finals</th><th>Average</th><th>Remarks</th><th>Status</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        $passcount = 0;
        $failcount = 0;
        $othercount = 0;
        $rownum    = 1;

        foreach ($students as $student) {
            $curstatus = $statusmap[$student->id] ?? '';
            $searchkey = strtolower($student->idnumber . ' ' . $student->lastname . ' ' . $student->firstname);
            $statuskey = ($curstatus === '') ? 'active' : $curstatus;

            if ($curstatus !== '') {
                echo "<tr data-gs-search='" . s($searchkey) . "' data-gs-remarks='' data-gs-status='" . s($statuskey) . "'>
                <td>{$rownum}</td>
                <td>" . s($student->idnumber) . "</td>
                <td>" . s($student->lastname) . ", " . s($student->firstname) . "</td>";

                // Faculty override: show dashes across the board instead of computed grades.
                $othercount++;
                $numcols = count($categories);
                for ($c = 0; $c < $numcols; $c++) {
                    echo '<td>-</td>';
                }
                echo '<td>-</td><td>-</td><td>-</td>';
                echo '<td><span class="badge badge-secondary">' . s(helper::status_label($curstatus)) . '</span></td>';
            } else {
                $g          = helper::compute_student_grades($courseid, $student->id);
                $hasdata    = $g['remarks'] !== '';
                $badgeclass = !$hasdata ? 'badge-secondary' : ($g['remarks'] === 'PASSED' ? 'badge-success' : 'badge-danger');
                $remarkskey = strtolower($g['remarks']); // 'passed', 'failed', or '' (no data)

                echo "<tr data-gs-search='" . s($searchkey) . "' data-gs-remarks='" . s($remarkskey) . "' data-gs-status='" . s($statuskey) . "'>
                <td>{$rownum}</td>
                <td>" . s($student->idnumber) . "</td>
                <td>" . s($student->lastname) . ", " . s($student->firstname) . "</td>";

                if ($g['remarks'] === 'PASSED') {
                    $passcount++;
                } else if ($g['remarks'] === 'FAILED') {
                    $failcount++;
                }

                if (!empty($categories)) {
                    foreach ($categories as $cat) {
                        $catdata = isset($g['cattotals'][$cat->id]) ? $g['cattotals'][$cat->id] : null;
                        $midpart = ($catdata && $catdata['midcount'] > 0)
                            ? number_format($catdata['midtotal'] / $catdata['midcount'], 0) . '%'
                            : '-';
                        $finpart = ($catdata && $catdata['fincount'] > 0)
                            ? number_format($catdata['fintotal'] / $catdata['fincount'], 0) . '%'
                            : '-';
                        echo "<td>{$midpart}/{$finpart}</td>";
                    }
                }

                echo '<td>' . helper::transmute_equiv($g['midterm'], $courseid) . '</td>';
                echo '<td>' . helper::transmute_equiv($g['finals'], $courseid) . '</td>';
                echo '<td>' . $g['transmuted'] . '</td>';
                echo '<td><span class="badge ' . $badgeclass . '">' . ($hasdata ? $g['remarks'] : '-') . '</span></td>';
            }

            // Status-setting control — always available regardless of current status.
            echo '<td>';
            echo '<form method="post" style="margin:0">';
            echo '<input type="hidden" name="action" value="setstatus">';
            echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
            echo '<input type="hidden" name="courseid" value="' . $courseid . '">';
            echo '<input type="hidden" name="studentid" value="' . $student->id . '">';
            echo '<select name="status" class="form-control form-control-sm" onchange="this.form.submit()">';
            foreach ($statusoptions as $val => $label) {
                $sel = ($curstatus === $val) ? 'selected' : '';
                echo "<option value='{$val}' {$sel}>" . s($label) . "</option>";
            }
            echo '</select>';
            echo '</form>';
            echo '</td>';

            echo '</tr>';

            $rownum++;
        }

        echo '</tbody></table>';
        echo '<div id="gradesheetNoResults" class="alert alert-info" style="display:none">No students match your search.</div>';

        $total    = $passcount + $failcount;
        $passrate = $total > 0 ? round(($passcount / $total) * 100, 1) : 0;
        $failrate = $total > 0 ? round(($failcount / $total) * 100, 1) : 0;

        echo '<div class="card mt-3">';
        echo '<div class="card-header"><strong>Class Summary</strong></div>';
        echo '<div class="card-body">';
        echo '<div class="row text-center">';
        echo "<div class='col-md-2'><h4>{$total}</h4><p class='text-muted'>Graded Students</p></div>";
        echo "<div class='col-md-2'><h4 class='text-success'>{$passcount}</h4><p class='text-muted'>Passed ({$passrate}%)</p></div>";
        echo "<div class='col-md-2'><h4 class='text-danger'>{$failcount}</h4><p class='text-muted'>Failed</p></div>";
        echo "<div class='col-md-2'><h4 class='text-secondary'>{$othercount}</h4><p class='text-muted'>Inc/Dropped/WP/IP</p></div>";
        echo "<div class='col-md-2'><h4>{$passrate}%</h4><p class='text-muted'>Passing Rate</p></div>";
        echo '</div>';
        echo "<div class='progress mt-2' style='height:25px'>
                <div class='progress-bar bg-success' style='width:{$passrate}%'>{$passrate}% Passed</div>
                <div class='progress-bar bg-danger'  style='width:{$failrate}%'>{$failrate}% Failed</div>
              </div>";
        echo '</div></div>';

        echo '<script>
        (function() {
            var input = document.getElementById("gradesheetStudentSearch");
            if (!input) { return; }
            var remarksSelect = document.getElementById("gradesheetRemarksFilter");
            var statusSelect  = document.getElementById("gradesheetStatusFilter");
            var table = document.getElementById("gradesheetStudentTable");
            var rows  = table.querySelectorAll("tbody tr");
            var noResults = document.getElementById("gradesheetNoResults");
            var countLabel = document.getElementById("gradesheetSearchCount");

            window.gradesheetFilterStudents = function() {
                var q = input.value.trim().toLowerCase();
                var remarksWant = remarksSelect.value;
                var statusWant  = statusSelect.value;
                var visible = 0;

                rows.forEach(function(row) {
                    var key     = row.getAttribute("data-gs-search")  || "";
                    var remarks = row.getAttribute("data-gs-remarks") || "";
                    var status  = row.getAttribute("data-gs-status")  || "";

                    var matchesSearch  = q === "" || key.indexOf(q) !== -1;
                    var matchesRemarks = remarksWant === "" || remarks === remarksWant;
                    var matchesStatus  = statusWant === "" || status === statusWant;
                    var match = matchesSearch && matchesRemarks && matchesStatus;

                    row.style.display = match ? "" : "none";
                    if (match) { visible++; }
                });

                var filtersActive = (q !== "" || remarksWant !== "" || statusWant !== "");
                noResults.style.display = (visible === 0) ? "" : "none";
                countLabel.textContent = filtersActive ? ("Showing " + visible + " of " + rows.length + " students") : "";
            };

            window.gradesheetResetFilters = function() {
                input.value = "";
                remarksSelect.value = "";
                statusSelect.value = "";
                gradesheetFilterStudents();
            };
        })();
        </script>';
    }
} else {
    echo '<hr>';
    echo '<div class="text-center text-muted mt-4">';
    echo '<p style="font-size:18px;">No course selected. Please select a course from the dropdown above.</p>';
    echo '</div>';
}

echo '</div>';
echo $OUTPUT->footer();