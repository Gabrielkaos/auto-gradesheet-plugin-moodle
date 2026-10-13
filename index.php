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

    //first require the user to have the manage capability in the course context
    require_capability('local/gradesheet:manage', context_course::instance($scourseid));
    helper::set_student_status($scourseid, $studentid, $status);

    redirect(new moodle_url('/local/gradesheet/index.php', ['courseid' => $scourseid]));
}

//optional params
$courseid = optional_param('courseid', 0, PARAM_INT);
// 'group' may be a group id, 'all' for the combined sheet, or absent (pick a sensible default).
$grouprequested = optional_param('group', '', PARAM_ALPHANUMEXT);
$groupid  = ctype_digit($grouprequested) ? (int)$grouprequested : 0;
$context = null;

//if there is a course selected set context and require login
if ($courseid > 0) {
    $course_obj = get_course($courseid);
    require_login($course_obj);
    $context = context_course::instance($courseid);
    helper::ensure_course_defaults($courseid);
    helper::auto_map_unmapped_items($courseid);
}

//set the url for the PAGE global, moodle requires this
$urlparams = array_filter(['courseid' => $courseid, 'group' => $groupid]);
$PAGE->set_url('/local/gradesheet/index.php', $urlparams);


//set context for the page, if no course is selected use system context
if ($context) {
    $PAGE->set_context($context);
} else {
    $PAGE->set_context(context_system::instance());
}

//tell page the title and heading
$PAGE->set_title(get_string('pluginname', 'local_gradesheet'));
$PAGE->set_heading(get_string('pluginname', 'local_gradesheet'));

//echo the header and start the page output
//mandatory to have posts/get before displaying headers
echo $OUTPUT->header();
echo '<div class="local-gradesheet-page">';

$isadmin = is_siteadmin();
$admin_too_many = false;


//if admin and has too many course dont display to prevent errors in memory
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

//SITE the front page is not a real course so we remove it so dropdown only shows real courses
global $SITE;
if (isset($courses[$SITE->id])) {
    unset($courses[$SITE->id]);
}

if ($admin_too_many && $courseid > 0) {
    $courses[$courseid] = $DB->get_record('course', ['id' => $courseid], 'id, fullname');
}

echo '<div class="container mt-4">';

//the dropdown for selecting a course
if ($admin_too_many && !$courseid) {
    echo \local_gradesheet\helper::render_alert("There are too many courses on this site to display in a dropdown. Please navigate to a specific course and click &quot;Grade Sheet&quot; in the course navigation.", "info");
} else {
    echo '<form method="get" action="">';
    echo '<div class="form-group">';
    echo '<label for="courseid"><strong>Choose a course</strong> <span class="text-muted small">to open its grade sheet</span></label>';
    echo '<select name="courseid" id="courseid" class="form-control" onchange="this.form.submit()">';
    echo '<option value="">-- Choose a course --</option>';

    foreach ($courses as $course) {
        if (!$course) continue;
        $selected = ($courseid == $course->id) ? 'selected' : '';
        //we use s() to escape the course name for safe output in HTML
        $safename = s(format_string($course->fullname));
        echo "<option value='{$course->id}' {$selected}>{$safename}</option>";
    }

    echo '</select>';
    echo '</div>';
    echo '</form>';
}

//if selected a course
if ($courseid) {
    $cfg        = helper::load_course_config($courseid);
    $coursename = $cfg['coursename'];

    $context = context_course::instance($courseid);

    $categories = $DB->get_records('local_gradesheet_categories',
        ['courseid' => $courseid], 'sortorder ASC');
    $weightvalid = helper::validate_weight_sum($courseid);

    $canmanage = has_capability('local/gradesheet:manage', $context);
    $isteacher = $canmanage || has_capability('moodle/grade:viewall', $context);

    if (!$isteacher) {
        if (!has_capability('local/gradesheet:view', $context)) {
            echo $OUTPUT->notification('You do not have permission to view grade sheets.', 'error');
            echo '</div></div>';
            echo $OUTPUT->footer();
            exit;
        }

        if (empty($course_obj->showgrades)) {
            echo $OUTPUT->notification(get_string('gradesarehidden', 'grades'), 'warning');
            echo '</div></div>';
            echo $OUTPUT->footer();
            exit;
        }

        $grades = helper::compute_student_grades($courseid, $USER->id, true);
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
        ';

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
            echo '<div class="grade-row" class="grade-row-highlight">
                    <span class="grade-label">Final Average</span>
                    <span class="grade-value">' . ($grades['average'] === null ? '—' : number_format($grades['average'], 2)) . '</span>
                  </div>';
            $is_custom = !helper::legend_has_equivalent($courseid);
            if (!$is_custom) {
                echo '<div class="grade-row" class="grade-row-highlight">
                        <span class="grade-label">Transmuted Grade</span>
                        <span class="grade-value" class="grade-value-large">' . s($grades['transmuted']) . '</span>
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
        //if student
        $course_obj = get_course($courseid);
        $groupmode = groups_get_course_groupmode($course_obj);

        if ($groupid > 0 && !helper::check_group_access($context, $groupid)) {
            echo $OUTPUT->notification('You do not have permission to access the requested section.', 'error');
            $groupid = -1;
        }

        // ── Sections ───────────────────────────────────────────────────────
        // A Moodle group is a section. Each section gets its own grade sheet:
        // own roster, own header, own exports. Shown whenever the course has
        // groups, whatever the course's group mode setting.
        $sections    = helper::get_accessible_sections($context);
        $cancombined = helper::can_view_combined($context);
        if ($grouprequested === '' && $groupid === 0) {
            $groupid = helper::default_section($context);   // first section when sections exist
        } else if ($grouprequested === 'all') {
            $groupid = $cancombined ? 0 : helper::default_section($context);
        }
        if ($groupid === 0 && !$cancombined) {
            $groupid = -1; // SEPARATEGROUPS teacher with no section at all
        }

        if (!empty($sections)) {
            $cursection = ($groupid > 0 && isset($sections[$groupid])) ? $sections[$groupid] : null;
            echo '<div class="gs-sections card mb-3">';
            echo '<div class="card-body py-2">';
            echo '<form method="get" class="form-inline gs-section-picker">';
            echo '<input type="hidden" name="courseid" value="' . $courseid . '">';
            echo '<label for="gsSection" class="mr-2 me-2"><strong>Section:</strong></label>';
            echo '<select name="group" id="gsSection" class="form-control form-control-sm mr-2 me-2" onchange="this.form.submit()">';
            foreach ($sections as $gid => $g) {
                echo '<option value="' . $gid . '"' . ($groupid === (int)$gid ? ' selected' : '') . '>' . s(format_string($g->name)) . '</option>';
            }
            if ($cancombined) {
                echo '<option value="all"' . ($groupid === 0 ? ' selected' : '') . '>All sections combined (one sheet)</option>';
            }
            echo '</select>';
            echo '<span class="text-muted small">Each section is a separate grade sheet with its own roster, header and exports.</span>';
            echo '</form>';

            // Overview: one row per section with its own print/export buttons.
            if ($canmanage) {
                echo '<details class="gs-details mt-2"' . (count($sections) > 1 ? ' open' : '') . '>';
                echo '<summary>All sections at a glance (' . count($sections) . ')</summary>';
                echo '<div class="table-responsive"><table class="table table-sm table-bordered mb-2 gs-section-table">';
                echo '<thead class="thead-light"><tr><th>Section</th><th>Students</th><th>Graded</th><th>Pass rate</th><th>Instructor line</th><th class="text-right">Grade sheet</th></tr></thead><tbody>';
                $printok = $weightvalid['valid'];
                foreach ($sections as $gid => $g) {
                    $sec = \local_gradesheet\gradesheet_service::compute_all_grades($courseid, (int)$gid);
                    $n   = count($sec['rows']);
                    $gp  = '&group=' . (int)$gid;
                    $dis = $printok && $n > 0 ? '' : ' disabled';
                    echo '<tr' . ($groupid === (int)$gid ? ' class="table-active"' : '') . '>';
                    echo '<td><a href="index.php?courseid=' . $courseid . $gp . '"><strong>' . s(format_string($g->name)) . '</strong></a></td>';
                    echo '<td>' . $n . ($n === 0 ? ' <span class="badge badge-warning">empty</span>' : '') . '</td>';
                    echo '<td>' . $sec['total'] . ($sec['othercount'] ? ' <small class="text-muted">+' . $sec['othercount'] . ' INC/Dr/WP/IP</small>' : '') . '</td>';
                    echo '<td>' . ($sec['total'] > 0 ? $sec['passrate'] . '%' : '-') . '</td>';
                    echo '<td>' . ($sec['instructor'] !== '' ? s($sec['instructor']) : '<span class="text-danger">blank</span>') . '</td>';
                    echo '<td class="text-right text-nowrap">';
                    echo '<a href="preview.php?courseid=' . $courseid . $gp . '" class="btn btn-outline-primary btn-sm' . $dis . '" title="Preview this section\'s sheet">Preview</a> ';
                    echo '<a href="export.php?courseid=' . $courseid . $gp . '" class="btn btn-outline-success btn-sm' . $dis . '">PDF</a> ';
                    echo '<a href="export_excel.php?courseid=' . $courseid . $gp . '" class="btn btn-outline-success btn-sm' . $dis . '">Excel</a>';
                    echo '</td></tr>';
                }
                echo '</tbody></table></div>';
                echo '<a href="export_all.php?courseid=' . $courseid . '" class="btn btn-success btn-sm' . ($printok ? '' : ' disabled') . '">&#128230; Download every section as PDF (ZIP)</a>';
                $ungrouped = helper::get_ungrouped_students($context);
                if (!empty($ungrouped)) {
                    $names = array_map(function ($u) { return s($u->lastname . ', ' . $u->firstname); }, array_slice($ungrouped, 0, 5));
                    echo '<div class="alert alert-warning py-2 mt-2 mb-0"><strong>' . count($ungrouped) . ' student(s) are in no section</strong> and will appear on no section sheet: '
                        . implode('; ', $names) . (count($ungrouped) > 5 ? '; &hellip;' : '')
                        . '. Add them to a group under <em>Participants &rarr; Groups</em>.</div>';
                }
                echo '</details>';
            }
            echo '</div></div>';

            if ($cursection) {
                $safecoursename = s(format_string($coursename));
                echo '<h4 class="gs-section-title">' . $safecoursename . ' <span class="badge badge-dark">' . s(format_string($cursection->name)) . '</span></h4>';
            }
        }

        $students  = helper::get_non_teaching_students($context, $groupid);
        $statusmap = helper::get_status_map($courseid);
        $statusoptions = helper::status_options();
        $rules     = helper::get_computation_rules($courseid);

        $safecoursename = s(format_string($coursename));
        if (empty($sections)) {
            echo '<hr>';
            echo "<h4>Students and Grades - {$safecoursename}</h4>";
        } else if ($groupid === 0) {
            echo '<h4 class="gs-section-title">' . $safecoursename . ' <span class="badge badge-secondary">All sections combined</span></h4>';
        }

        // ── One status panel instead of a stack of alerts ─────────────────
        // Blocking problems get a "Getting started" checklist; otherwise a quiet
        // one-line status with the computation details tucked behind a toggle.
        $mapwarn     = helper::get_mapping_warnings($courseid);
        $health      = helper::settings_health($courseid, $groupid);
        $nblock      = count(array_filter($health, function ($h) { return $h['level'] === 'danger'; }));
        $nwarn       = count(array_filter($health, function ($h) { return $h['level'] === 'warning'; }));
        $fs          = helper::get_formula_settings($courseid);
        $signatories = helper::resolve_signatories($cfg, $courseid, $groupid);
        $pct         = function ($v) { return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); };

        $nothingmapped = ($mapwarn === null) || ($mapwarn['midterm'] === 0 && $mapwarn['finals'] === 0);
        $firstrun = $canmanage && ($nblock > 0 || $nothingmapped);

        if ($firstrun) {
            // Four-step checklist for a course that cannot print yet.
            $steps = [
                ['grade-categories',      'Set categories and weights',  $weightvalid['valid'],
                    $weightvalid['count'] === 0 ? 'No categories yet.' : 'Weights total ' . $pct($weightvalid['total']) . '%.'],
                ['grade-mapping',         'Map your graded activities', !$nothingmapped,
                    $mapwarn === null ? 'The gradebook has no graded activities yet.' : ($nothingmapped ? 'Nothing is mapped yet.' : ($mapwarn['unmapped'] . ' unmapped.'))],
                ['transmutation-formula', 'Choose the grade formula',   $fs['mode'] === 'formula',
                    $fs['mode'] === 'formula' ? 'Formula: ' . s($fs['formula']) : 'Using the built-in table (works, but a formula is clearer).'],
                ['course-details',        'Fill in the report header',
                    !helper::signatory_is_blank($cfg['coursenumber']) && $signatories['instructor']['name'] !== '',
                    $signatories['instructor']['name'] !== '' ? 'Instructor: ' . s($signatories['instructor']['name']) : 'Instructor line is blank.'],
            ];
            $done = count(array_filter($steps, function ($st) { return $st[2]; }));
            echo '<div class="card gs-getting-started mb-3">';
            echo '<div class="card-body">';
            echo '<h5 class="mb-1">Set up this grade sheet <small class="text-muted">(' . $done . ' of 4 done)</small></h5>';
            echo '<p class="text-muted mb-2">The sheet cannot be printed until the required steps are complete. Each step takes a minute; the Settings page walks you through them in order.</p>';
            echo '<ol class="gs-checklist">';
            foreach ($steps as $i => [$anchor, $label, $ok, $note]) {
                echo '<li class="' . ($ok ? 'done' : 'todo') . '">'
                    . '<span class="gs-check">' . ($ok ? '&#10003;' : ($i + 1)) . '</span>'
                    . '<a href="course_settings.php?courseid=' . $courseid . '#' . $anchor . '">' . $label . '</a>'
                    . ' <small class="text-muted">' . $note . '</small></li>';
            }
            echo '</ol>';
            echo '<a href="course_settings.php?courseid=' . $courseid . '" class="btn btn-primary">Open Settings &rarr;</a>';
            echo '</div></div>';
        } else {
            // Ready (or nearly): one line, details on demand.
            if ($nwarn > 0) {
                $line  = '<strong>' . $nwarn . ' thing' . ($nwarn === 1 ? '' : 's') . ' to check</strong> before you print.';
                $cls   = 'warning'; $icon = '&#9888;';
                $action = $canmanage ? '<a href="course_settings.php?courseid=' . $courseid . '#needs-attention" class="btn btn-light btn-sm">See what</a>' : '';
            } else {
                $line  = '<strong>Ready to print.</strong> Weights, mapping, formula and signatories are all set.';
                $cls   = 'success'; $icon = '&#10003;';
                $action = '';
            }
            echo helper::render_alert($line, $cls, $icon, $action);
        }

        // "How are these numbers computed?" — collapsed by default so the page stays calm.
        $rulesparts = [];
        $rulesparts[] = 'Final average = <strong>' . $pct($rules['midtermweight']) . '% Midterm + ' . $pct(100 - $rules['midtermweight']) . '% Finals</strong>';
        $rulesparts[] = 'Grade from percentage: <strong>' . ($fs['mode'] === 'formula' ? '<code>' . s($fs['formula']) . '</code>' : 'built-in ESSU table') . '</strong>'
            . ($fs['mode'] === 'formula' ? ', passing at <strong>' . $pct($fs['passmark']) . '%</strong>' : '');
        $rulesparts[] = 'Ungraded work is <strong>' . ($rules['missingaszero'] ? 'counted as 0%' : 'skipped') . '</strong>; hidden items are <strong>' . ($rules['includehidden'] ? 'included' : 'excluded') . '</strong>'
            . ($rules['roundaverage'] ? '; averages are <strong>rounded to whole numbers</strong> first' : '');
        $sigparts = [];
        foreach (['instructor' => 'Instructor', 'department_head' => 'Dept. Head', 'registrar' => 'Registrar', 'college_dean' => 'Dean'] as $k => $lbl) {
            $sg = $signatories[$k];
            $sigparts[] = $lbl . ': ' . ($sg['name'] === ''
                ? '<span class="text-danger">blank</span> <small class="text-muted">(' . s($sg['source']) . ')</small>'
                : '<strong>' . s($sg['name']) . '</strong> <small class="text-muted" title="' . s($sg['source']) . '">' . $sg['how'] . '</small>');
        }
        echo '<details class="gs-details mb-3">';
        echo '<summary>How are these grades computed, and who signs the sheet?</summary>';
        echo '<ul class="mb-1"><li>' . implode('</li><li>', $rulesparts) . '</li></ul>';
        echo '<div class="small">' . implode(' &middot; ', $sigparts) . '</div>';
        if ($canmanage) {
            echo '<a href="course_settings.php?courseid=' . $courseid . '" class="small">Change in Settings &rarr;</a>';
        }
        echo '</details>';

        if ($canmanage) {
            $groupparam = ($groupid > 0) ? '&group=' . $groupid : '';
            $btncls = $weightvalid['valid'] ? '' : ' disabled';
            $why    = $weightvalid['valid'] ? '' : ' title="Disabled: category weights must total 100% (see Settings, Step 1)"';
            echo '<div class="gs-actions mb-3">';
            echo '<a href="preview.php?courseid='      . $courseid . $groupparam . '" id="btnGradesheetPreview" onclick="openGradesheetPdfPreview(event)" class="btn btn-primary' . $btncls . '"' . $why . '>&#128424; Preview &amp; Print</a> ';
            echo '<a href="export.php?courseid='       . $courseid . $groupparam . '" class="btn btn-outline-success' . $btncls . '"' . $why . '>Download PDF</a> ';
            echo '<a href="export_excel.php?courseid=' . $courseid . $groupparam . '" class="btn btn-outline-success' . $btncls . '"' . $why . '>Download Excel</a> ';
            echo '<a href="course_settings.php?courseid=' . $courseid . '" class="btn btn-outline-secondary ml-auto ms-auto">&#9881; Settings</a>';
            echo '</div>';
            if ($groupid > 0 && isset($sections[$groupid])) {
                echo '<p class="text-muted small">These buttons print <strong>' . s(format_string($sections[$groupid]->name)) . '</strong> only. Use the section picker above to switch, or "Download every section" for all of them at once.</p>';
            }
        }

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
        echo '<button type="button" class="btn btn-outline-secondary w-100" onclick="gradesheetResetFilters()" title="Clear filters">&#10005;</button>';
        echo '</div>';

        echo '<div class="col-12"><small class="form-text text-muted" id="gradesheetSearchCount"></small></div>';
        echo '</div>';

        // Period tabs: one number per cell. Cells carry data-gs-period and the
        // table's gs-period-* class decides which set is visible (see styles.css).
        $mapcounts = $mapwarn ?? ['midterm' => 0, 'finals' => 0, 'unmapped' => 0];
        echo '<ul class="nav nav-tabs gs-period-tabs mb-0" id="gradesheetPeriodTabs" role="tablist">';
        foreach (['midterm' => 'Midterm', 'finals' => 'Finals', 'summary' => 'Final Grades (what prints)'] as $pk => $plabel) {
            $count = ($pk === 'summary') ? '' : ' <span class="badge badge-light border">' . (int)$mapcounts[$pk] . ' item' . ((int)$mapcounts[$pk] === 1 ? '' : 's') . '</span>';
            echo '<li class="nav-item"><a href="#" class="nav-link" data-gs-tab="' . $pk . '" onclick="gradesheetShowPeriod(\'' . $pk . '\'); return false;" role="tab">' . $plabel . $count . '</a></li>';
        }
        echo '</ul>';

        echo '<table class="table table-bordered table-striped gs-period-table" id="gradesheetStudentTable">';
        echo '<thead class="thead-dark"><tr>';
        echo '<th>#</th><th>Student ID</th><th>Student Name</th>';
        $help = function (string $text): string { return ' <span class="gs-help" title="' . s($text) . '">?</span>'; };

        // Midterm / Finals tabs: category averages for that period only.
        foreach (['midterm', 'finals'] as $pk) {
            if (!empty($categories)) {
                foreach ($categories as $cat) {
                    echo '<th data-gs-period="' . $pk . '">' . s($cat->name) . ' <small>(' . $cat->weight . '%)</small></th>';
                }
            }
            echo '<th data-gs-period="' . $pk . '">Graded' . $help('Scored items / counted items in this period. Yellow means some work is still ungraded.') . '</th>';
            echo '<th data-gs-period="' . $pk . '">' . ucfirst($pk) . ' %' . $help('The weighted percentage for this period, before transmutation.') . '</th>';
            echo '<th data-gs-period="' . $pk . '">Grade' . $help('The percentage converted with the course formula (what the registrar sheet shows).') . '</th>';
        }

        // Summary tab: what goes on the official sheet.
        echo '<th data-gs-period="summary">Graded' . $help('Scored items / counted items across both periods.') . '</th>';
        echo '<th data-gs-period="summary">Midterm</th><th data-gs-period="summary">Finals</th>';
        echo '<th data-gs-period="summary">Final Grade' . $help('Transmuted final grade; the raw average is shown beside it.') . '</th>';
        echo '<th data-gs-period="summary">Rating' . $help('The adjectival rating (Outstanding, Very Good, ...) for the raw average.') . '</th>';
        echo '<th data-gs-period="summary">Remarks</th>';
        echo '<th>Academic Status' . $help('Leave as Active to compute normally. Choose Incomplete, Dropped, Withdrawn or In Progress to print that label instead of grades.') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        $numcats = count($categories);
        // Columns per period tab (categories + graded + grade + equivalent); summary has 5.
        $percols = $numcats + 3;

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
                foreach (['midterm', 'finals'] as $pk) {
                    for ($c = 0; $c < $percols; $c++) {
                        echo '<td data-gs-period="' . $pk . '">-</td>';
                    }
                }
                for ($c = 0; $c < 5; $c++) {
                    echo '<td data-gs-period="summary">-</td>';
                }
                echo '<td data-gs-period="summary"><span class="badge badge-secondary">' . s(helper::status_label($curstatus)) . '</span></td>';
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

                // Renders "graded/mapped" with a warning badge when items are missing.
                $gradedcell = function (int $graded, int $mapped, string $period) use ($rules): string {
                    $missing = $mapped - $graded;
                    if ($mapped > 0 && $missing > 0) {
                        $title = $missing . ' mapped item(s) ungraded' . ($rules['missingaszero'] ? ' (counted as 0%)' : ' (skipped)');
                        return '<td data-gs-period="' . $period . '"><span class="badge badge-warning" title="' . s($title) . '">' . $graded . '/' . $mapped . '</span></td>';
                    }
                    return '<td data-gs-period="' . $period . '">' . $graded . '/' . $mapped . '</td>';
                };

                // Midterm / Finals tabs.
                foreach (['midterm', 'finals'] as $pk) {
                    $tkey = ($pk === 'midterm') ? 'mid' : 'fin';
                    if (!empty($categories)) {
                        foreach ($categories as $cat) {
                            $catdata = $g['cattotals'][$cat->id] ?? null;
                            $cell = ($catdata && $catdata[$tkey . 'count'] > 0)
                                ? number_format($catdata[$tkey . 'total'] / $catdata[$tkey . 'count'], 2) . '%'
                                : '<span class="text-muted">-</span>';
                            echo '<td data-gs-period="' . $pk . '">' . $cell . '</td>';
                        }
                    }
                    echo $gradedcell($g['periodcounts'][$pk]['graded'], $g['periodcounts'][$pk]['mapped'], $pk);
                    $pval = $g[$pk];
                    echo '<td data-gs-period="' . $pk . '"><strong>' . ($pval === null ? '-' : number_format($pval, 2) . '%') . '</strong></td>';
                    echo '<td data-gs-period="' . $pk . '">' . s(helper::transmute_equiv($pval, $courseid)) . '</td>';
                }

                // Summary tab.
                echo $gradedcell($g['graded'], $g['mapped'], 'summary');
                echo '<td data-gs-period="summary">' . s(helper::transmute_equiv($g['midterm'], $courseid)) . '</td>';
                echo '<td data-gs-period="summary">' . s(helper::transmute_equiv($g['finals'], $courseid)) . '</td>';
                echo '<td data-gs-period="summary"><strong>' . s($g['transmuted']) . '</strong>'
                    . ($g['average'] === null ? '' : ' <small class="text-muted">(' . number_format($g['average'], 2) . '%)</small>') . '</td>';
                echo '<td data-gs-period="summary">' . ($g['average'] === null ? '-' : s(helper::adjectival_rating($g['average'], $courseid))) . '</td>';
                echo '<td data-gs-period="summary"><span class="badge ' . $badgeclass . '">' . ($hasdata ? s($g['remarks']) : '-') . '</span></td>';
            }

            // Status-setting control — editable for managers/editing teachers, read-only for non-editing teachers.
            echo '<td>';
            if ($canmanage) {
                echo '<form method="post" class="m-0">';
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
            } else {
                echo '<select name="status" class="form-control form-control-sm" disabled>';
                foreach ($statusoptions as $val => $label) {
                    $sel = ($curstatus === $val) ? 'selected' : '';
                    echo "<option value='{$val}' {$sel}>" . s($label) . "</option>";
                }
                echo '</select>';
            }
            echo '</td>';

            echo '</tr>';

            $rownum++;
        }

        echo '</tbody></table>';
        echo '<div id="gradesheetNoResults" style="display:none">' . \local_gradesheet\helper::render_alert("No students match your search.", "info") . '</div>';

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

            // Period tabs (Midterm / Finals / Summary). The chosen tab is
            // remembered per course so faculty land where they left off.
            var storageKey = "local_gradesheet_tab_' . $courseid . '";
            var validTabs = ["midterm", "finals", "summary"];
            window.gradesheetShowPeriod = function(period) {
                if (validTabs.indexOf(period) === -1) { period = "midterm"; }
                validTabs.forEach(function(p) { table.classList.remove("gs-period-" + p); });
                table.classList.add("gs-period-" + period);
                document.querySelectorAll("#gradesheetPeriodTabs [data-gs-tab]").forEach(function(a) {
                    a.classList.toggle("active", a.getAttribute("data-gs-tab") === period);
                });
                try { localStorage.setItem(storageKey, period); } catch (e) {}
            };
            var initial = "midterm";
            try { initial = localStorage.getItem(storageKey) || initial; } catch (e) {}
            gradesheetShowPeriod(initial);
        })();
        </script>';

        if ($canmanage) {
            $groupparam = ($groupid > 0) ? '&group=' . $groupid : '';
            ?>
            <!-- Gradesheet PDF Preview Modal (Single Source of Truth) -->
            <div id="gradesheetPdfModal" class="gs-modal-backdrop" role="dialog" aria-modal="true" aria-label="Report of Grades PDF Preview">
                <div class="gs-modal-dialog">
                    <div class="gs-modal-header">
                        <button type="button" class="gs-modal-close-btn" onclick="closeGradesheetPdfPreview()" title="Close (Esc)" aria-label="Close">&times;</button>
                    </div>
                    <div class="gs-modal-body">
                        <div id="gsModalSpinner" class="gs-modal-spinner-wrap">
                            <div class="gs-spinner"></div>
                            <div class="gs-modal-spinner-text">Generating official PDF grade sheet...</div>
                            <div class="small text-muted mt-1">Single source of truth: Loading official registrar-aligned document</div>
                        </div>
                        <div id="gsModalError" class="alert alert-danger gs-modal-error" role="alert">
                            <span id="gsModalErrorText">Failed to load PDF preview.</span>
                            <button type="button" class="btn btn-sm btn-outline-danger ml-3" onclick="loadGradesheetPdf(true)">Retry</button>
                        </div>
                        <iframe id="gsPdfPreviewIframe" class="gs-pdf-iframe" title="Gradesheet PDF Preview"></iframe>
                    </div>
                </div>
            </div>

            <script>
            (function() {
                var cachedPdfBlob = null;
                var cachedPdfUrl = null;
                var cachedPdfFilename = <?php echo json_encode('ReportOfGrades_' . str_replace(' ', '_', clean_filename($coursename)) . '_' . date('Ymd') . '.pdf'); ?>;
                var isFetching = false;

                var courseId = <?php echo (int)$courseid; ?>;
                var groupId  = <?php echo (int)$groupid; ?>;

                window.openGradesheetPdfPreview = function(event) {
                    if (event) {
                        event.preventDefault();
                    }
                    var btn = document.getElementById('btnGradesheetPreview');
                    if (btn && btn.classList.contains('disabled')) {
                        return;
                    }

                    var modal = document.getElementById('gradesheetPdfModal');
                    if (!modal) return;
                    modal.classList.add('gs-show');
                    document.body.style.overflow = 'hidden';

                    if (!cachedPdfBlob) {
                        loadGradesheetPdf(false);
                    }
                };

                window.closeGradesheetPdfPreview = function() {
                    var modal = document.getElementById('gradesheetPdfModal');
                    if (!modal) return;
                    modal.classList.remove('gs-show');
                    document.body.style.overflow = '';
                };

                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        closeGradesheetPdfPreview();
                    }
                });

                var modalBackdrop = document.getElementById('gradesheetPdfModal');
                if (modalBackdrop) {
                    modalBackdrop.addEventListener('click', function(e) {
                        if (e.target === modalBackdrop) {
                            closeGradesheetPdfPreview();
                        }
                    });
                }

                window.loadGradesheetPdf = function(forceReload) {
                    if (isFetching) return;
                    if (cachedPdfBlob && !forceReload) return;

                    var spinner = document.getElementById('gsModalSpinner');
                    var errBox  = document.getElementById('gsModalError');
                    var iframe  = document.getElementById('gsPdfPreviewIframe');
                    var printBtn = document.getElementById('btnModalPrintPdf');
                    var dlBtn    = document.getElementById('btnModalDownloadPdf');

                    if (spinner) spinner.style.display = 'flex';
                    if (errBox) errBox.style.display = 'none';
                    if (printBtn) printBtn.disabled = true;
                    if (dlBtn) dlBtn.disabled = true;

                    isFetching = true;

                    var exportUrl = 'export.php?courseid=' + courseId + (groupId ? '&group=' + groupId : '') + '&action=preview';

                    fetch(exportUrl, {
                        method: 'GET',
                        credentials: 'same-origin'
                    })
                    .then(function(response) {
                        if (!response.ok) {
                            throw new Error('HTTP error ' + response.status);
                        }
                        var cd = response.headers.get('Content-Disposition') || '';
                        var match = cd.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/);
                        if (match && match[1]) {
                            cachedPdfFilename = match[1].replace(/['"]/g, '').trim();
                        }
                        var contentType = response.headers.get('content-type') || '';
                        if (contentType.indexOf('application/pdf') === -1) {
                            throw new Error('Server returned unexpected content. Please verify course settings and student enrollment.');
                        }
                        return response.blob();
                    })
                    .then(function(blob) {
                        cachedPdfBlob = blob;
                        if (cachedPdfUrl) {
                            URL.revokeObjectURL(cachedPdfUrl);
                        }
                        cachedPdfUrl = URL.createObjectURL(blob);
                        if (iframe) {
                            iframe.src = cachedPdfUrl;
                        }
                        if (spinner) spinner.style.display = 'none';
                        if (printBtn) printBtn.disabled = false;
                        if (dlBtn) dlBtn.disabled = false;
                        isFetching = false;
                    })
                    .catch(function(err) {
                        isFetching = false;
                        if (spinner) spinner.style.display = 'none';
                        if (errBox) {
                            var errText = document.getElementById('gsModalErrorText');
                            if (errText) {
                                errText.textContent = err.message || 'Failed to load PDF preview.';
                            }
                            errBox.style.display = 'block';
                        }
                    });
                };

                window.downloadGradesheetPdf = function() {
                    if (!cachedPdfBlob) {
                        window.location.href = 'export.php?courseid=' + courseId + (groupId ? '&group=' + groupId : '');
                        return;
                    }
                    var tempUrl = URL.createObjectURL(cachedPdfBlob);
                    var a = document.createElement('a');
                    a.style.display = 'none';
                    a.href = tempUrl;
                    a.download = cachedPdfFilename;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    setTimeout(function() {
                        URL.revokeObjectURL(tempUrl);
                    }, 2000);
                };

                window.printGradesheetPdf = function() {
                    var iframe = document.getElementById('gsPdfPreviewIframe');
                    if (iframe && iframe.contentWindow) {
                        try {
                            iframe.contentWindow.focus();
                            iframe.contentWindow.print();
                            return;
                        } catch (e) {
                            console.warn('Direct iframe print encountered an issue, trying window fallback', e);
                        }
                    }
                    if (cachedPdfUrl) {
                        var printWin = window.open(cachedPdfUrl, '_blank');
                        if (printWin) {
                            printWin.focus();
                        }
                    }
                };
            })();
            </script>
            <?php
        }
    }
} else {
    echo '<hr>';
    echo '<div class="text-center text-muted mt-4">';
    echo '<p style="font-size:18px;">No course selected. Please select a course from the dropdown above.</p>';
    echo '</div>';
}

echo '</div>';
echo '</div>';
echo $OUTPUT->footer();