<?php
require_once('../../config.php');
require_once($CFG->libdir.'/formslib.php');

use local_gradesheet\helper;

require_login();

$courseid = required_param('courseid', PARAM_INT);
$context  = context_course::instance($courseid);
require_capability('local/gradesheet:manage', $context);

$PAGE->set_url('/local/gradesheet/course_settings.php', ['courseid' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_title('Grade Sheet Settings');
$PAGE->set_heading('Grade Sheet Settings');

$coursename = $DB->get_field('course', 'fullname', ['id' => $courseid]);

$gitems = $DB->get_records_select(
    'grade_items',
    'courseid = ? AND itemtype != ? AND itemname IS NOT NULL',
    [$courseid, 'course']
);

// ── HANDLE FORM SUBMISSIONS ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = optional_param('action', '', PARAM_TEXT);

    // Save course details
    if ($action === 'savedetails') {
        $existing = $DB->get_record('local_gradesheet_config', ['courseid' => $courseid]);
        $details  = [
            'semester'        => required_param('semester',        PARAM_TEXT),
            'schoolyear'      => required_param('schoolyear',      PARAM_TEXT),
            'coursenumber'    => required_param('coursenumber',     PARAM_TEXT),
            'descriptive'     => required_param('descriptive',      PARAM_TEXT),
            'courseandyear'   => required_param('courseandyear',    PARAM_TEXT),
            'schedule'        => required_param('schedule',         PARAM_TEXT),
            'units'           => required_param('units',            PARAM_TEXT),
            'instructor'      => required_param('instructor',       PARAM_TEXT),
            'department_head' => required_param('department_head',  PARAM_TEXT),
            'registrar'       => required_param('registrar',        PARAM_TEXT),
            'college_dean'    => required_param('college_dean',     PARAM_TEXT),
        ];
        if ($existing) {
            foreach ($details as $k => $v) $existing->$k = $v;
            $existing->timemodified = time();
            $DB->update_record('local_gradesheet_config', $existing);
        } else {
            $record = (object) array_merge([
                'courseid' => $courseid, 'quizweight' => 50,
                'examweight' => 50, 'activityweight' => 0,
                'timecreated' => time(), 'timemodified' => time(),
            ], $details);
            $DB->insert_record('local_gradesheet_config', $record);
        }
        $detailsuccess = "Course details saved!";
    }

    // Add a new category
    if ($action === 'addcategory') {
        $name   = required_param('catname',   PARAM_TEXT);
        $weight = required_param('catweight', PARAM_FLOAT);
        if (!empty($name) && $weight >= 0) {
            $sortorder = $DB->count_records('local_gradesheet_categories', ['courseid' => $courseid]);
            $DB->insert_record('local_gradesheet_categories', (object)[
                'courseid'  => $courseid,
                'name'      => $name,
                'weight'    => $weight,
                'sortorder' => $sortorder,
            ]);
            $catsuccess = "Category '{$name}' added!";
        }
    }

    // Update an existing category
    if ($action === 'updatecategory') {
        $catid  = required_param('catid', PARAM_INT);
        $name   = required_param('catname', PARAM_TEXT);
        $weight = required_param('catweight', PARAM_FLOAT);

        $category = $DB->get_record('local_gradesheet_categories', ['id' => $catid, 'courseid' => $courseid]);
        if ($category && !empty($name) && $weight >= 0) {
            $category->name = $name;
            $category->weight = $weight;
            $DB->update_record('local_gradesheet_categories', $category);
            redirect(
                new moodle_url('/local/gradesheet/course_settings.php', ['courseid' => $courseid], 'grade-categories'),
                "Category '{$name}' updated!",
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
    }

    // Delete a category
    if ($action === 'deletecategory') {
        $catid    = required_param('catid', PARAM_INT);
        $catcount = $DB->count_records('local_gradesheet_categories', ['courseid' => $courseid]);
        if ($catcount <= 1) {
            redirect(
                new moodle_url('/local/gradesheet/course_settings.php', ['courseid' => $courseid], 'grade-categories'),
                get_string('warnlastcategory', 'local_gradesheet'),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }
        $DB->delete_records('local_gradesheet_categories', ['id' => $catid, 'courseid' => $courseid]);
        // Remove mapping for items in this category
        $DB->set_field('local_gradesheet_itemmap', 'categoryid', 0, [
            'courseid' => $courseid, 'categoryid' => $catid
        ]);
        $catsuccess = "Category deleted.";
    }

    // Add a new transmutation bracket
    if ($action === 'addtransmute') {
        $min   = required_param('tmin', PARAM_FLOAT);
        $max   = required_param('tmax', PARAM_FLOAT);
        $equiv = required_param('tequiv', PARAM_TEXT);
        $desc  = required_param('tdesc', PARAM_TEXT);
        $ispassing = optional_param('tispassing', 0, PARAM_INT) ? 1 : 0;
        if ($max >= $min && $equiv !== '') {
            $sortorder = $DB->count_records('local_gradesheet_transmute', ['courseid' => $courseid]);
            $DB->insert_record('local_gradesheet_transmute', (object)[
                'courseid'   => $courseid,
                'minscore'   => $min,
                'maxscore'   => $max,
                'equivalent' => $equiv,
                'descriptor' => $desc,
                'sortorder'  => $sortorder,
                'ispassing'  => $ispassing,
            ]);
            $scalesuccess = "Bracket added!";
        } else {
            $scaleerror = "Max score must be greater than or equal to min score.";
        }
    }

    // Update an existing transmutation bracket
    if ($action === 'updatetransmute') {
        $tid   = required_param('tid', PARAM_INT);
        $min   = required_param('tmin', PARAM_FLOAT);
        $max   = required_param('tmax', PARAM_FLOAT);
        $equiv = required_param('tequiv', PARAM_TEXT);
        $desc  = required_param('tdesc', PARAM_TEXT);
        $ispassing = optional_param('tispassing', 0, PARAM_INT) ? 1 : 0;

        $row = $DB->get_record('local_gradesheet_transmute', ['id' => $tid, 'courseid' => $courseid]);
        if (!$row) {
            $scaleerror = "That bracket no longer exists.";
        } else if ($max < $min) {
            $scaleerror = "Max score must be greater than or equal to min score.";
        } else if ($equiv === '') {
            $scaleerror = "Equivalent cannot be empty.";
        } else {
            $row->minscore   = $min;
            $row->maxscore   = $max;
            $row->equivalent = $equiv;
            $row->descriptor = $desc;
            $row->ispassing  = $ispassing;
            $DB->update_record('local_gradesheet_transmute', $row);
            redirect(
                new moodle_url('/local/gradesheet/course_settings.php', ['courseid' => $courseid], 'grading-scale'),
                "Bracket updated!",
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
    }

    // Delete a transmutation bracket
    if ($action === 'deletetransmute') {
        $tid = required_param('tid', PARAM_INT);
        $DB->delete_records('local_gradesheet_transmute', ['id' => $tid, 'courseid' => $courseid]);
        $scalesuccess = "Bracket deleted.";
    }

    // Reset to the default ESSU scale (deletes all custom brackets for this course)
    if ($action === 'resetscale') {
        $DB->delete_records('local_gradesheet_transmute', ['courseid' => $courseid]);
        $scalesuccess = "Reverted to the default grading scale.";
    }

    // Save grade item mapping
    if ($action === 'savemapping') {
        foreach ($gitems as $gitem) {
            $period   = optional_param('period_' . $gitem->id,  'finals', PARAM_TEXT);
            $catid    = optional_param('cat_'    . $gitem->id,  0,        PARAM_INT);
            $period   = in_array($period, ['midterm', 'finals']) ? $period : 'finals';

            $existing = $DB->get_record('local_gradesheet_itemmap', [
                'courseid' => $courseid, 'gradeitemid' => $gitem->id,
            ]);
            if ($existing) {
                $existing->period     = $period;
                $existing->categoryid = $catid;
                $DB->update_record('local_gradesheet_itemmap', $existing);
            } else {
                $DB->insert_record('local_gradesheet_itemmap', (object)[
                    'courseid'    => $courseid,
                    'gradeitemid' => $gitem->id,
                    'period'      => $period,
                    'categoryid'  => $catid,
                ]);
            }
        }
        $mapsuccess = "Grade item mapping saved!";
    }
}

// ── LOAD DATA ─────────────────────────────────────────────────────────────────
$config     = $DB->get_record('local_gradesheet_config', ['courseid' => $courseid]);
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
$displaylegend  = \local_gradesheet\helper::get_rating_legend($usingcustomscale ? $courseid : null);

echo $OUTPUT->header();
?>

<div class="container mt-4">
    <h2>Grade Sheet Settings</h2>
    <h5 class="text-muted">Course: <?php echo s(format_string($coursename)); ?></h5>
    <a href="index.php?courseid=<?php echo $courseid; ?>" class="btn btn-secondary btn-sm mb-3">← Back to Grade Sheet</a>
    <hr>

    <!-- SECTION 1: Course Details -->
    <div class="card mb-4">
        <div class="card-header bg-dark text-white">
            <strong>Course Details & Signatories</strong>
        </div>
        <div class="card-body">
            <?php if (!empty($detailsuccess)): ?>
                <div class="alert alert-success"><?php echo $detailsuccess; ?></div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="savedetails">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

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
                        <input type="text" name="schoolyear" class="form-control"
                               value="<?php echo $config ? s($config->schoolyear) : '2025-2026'; ?>">
                    </div>
                </div>

                <hr><h6 class="text-muted mb-3">- Course Information -</h6>

                <?php
                $fields = [
                    'coursenumber'  => ['Subject and Course No.', 'e.g. CS 101'],
                    'descriptive'   => ['Descriptive Title',      'e.g. Computer Programming 1'],
                    'courseandyear' => ['Course and Year',         'e.g. BSCS 2A'],
                    'schedule'      => ['Schedule of Classes',     'e.g. MWF 8:00-9:00 AM'],
                    'units'         => ['Number of Units',         'e.g. 3'],
                ];
                foreach ($fields as $fname => [$label, $placeholder]):
                ?>
                <div class="form-group row mb-3">
                    <label class="col-sm-4 col-form-label"><strong><?php echo $label; ?></strong></label>
                    <div class="col-sm-6">
                        <input type="text" name="<?php echo $fname; ?>" class="form-control"
                               value="<?php echo $config && isset($config->$fname) ? s($config->$fname) : ''; ?>"
                               placeholder="<?php echo $placeholder; ?>">
                    </div>
                </div>
                <?php endforeach; ?>

                <hr><h6 class="text-muted mb-3">- Signatories -</h6>

                <?php
                $sigs = [
                    'instructor'      => 'Instructor',
                    'department_head' => 'Department Head',
                    'registrar'       => 'Registrar',
                    'college_dean'    => 'College Dean',
                ];
                foreach ($sigs as $fname => $label):
                ?>
                <div class="form-group row mb-3">
                    <label class="col-sm-4 col-form-label"><strong><?php echo $label; ?></strong></label>
                    <div class="col-sm-6">
                        <input type="text" name="<?php echo $fname; ?>" class="form-control"
                               value="<?php echo $config && isset($config->$fname) ? s($config->$fname) : ''; ?>"
                               placeholder="Full name in CAPS">
                    </div>
                </div>
                <?php endforeach; ?>

                <button type="submit" class="btn btn-primary">Save Course Details</button>
            </form>
        </div>
    </div>

    <!-- SECTION 2: Grade Categories -->
    <div class="card mb-4" id="grade-categories">
        <div class="card-header bg-dark text-white">
            <strong>Grade Categories & Weights</strong>
        </div>
        <div class="card-body">
            <?php if (!empty($catsuccess)): ?>
                <div class="alert alert-success"><?php echo $catsuccess; ?></div>
            <?php endif; ?>

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
                    <?php foreach ($categories as $cat): ?>
                    <?php if ($editcategory && (int) $editcategory->id === (int) $cat->id): ?>
                    <tr class="table-warning">
                        <td>
                            <form method="post" id="editcategoryform<?php echo $cat->id; ?>">
                                <input type="hidden" name="action" value="updatecategory">
                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                <input type="hidden" name="catid" value="<?php echo $cat->id; ?>">
                                <input type="text" name="catname" class="form-control form-control-sm"
                                       value="<?php echo s($cat->name); ?>">
                            </form>
                        </td>
                        <td>
                            <input type="number" name="catweight" class="form-control form-control-sm"
                                   form="editcategoryform<?php echo $cat->id; ?>"
                                   value="<?php echo s($cat->weight); ?>" min="0" max="100" step="0.01">
                        </td>
                        <td>
                            <button type="submit" class="btn btn-primary btn-sm" form="editcategoryform<?php echo $cat->id; ?>">Save</button>
                            <a href="course_settings.php?courseid=<?php echo $courseid; ?>#grade-categories" class="btn btn-secondary btn-sm">Cancel</a>
                        </td>
                    </tr>
                    <?php else: ?>
                    <tr>
                        <td><strong><?php echo s($cat->name); ?></strong></td>
                        <td><?php echo $cat->weight; ?>%</td>
                        <td>
                            <a href="course_settings.php?courseid=<?php echo $courseid; ?>&editcatid=<?php echo $cat->id; ?>#grade-categories"
                               class="btn btn-warning btn-sm">Edit</a>
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
                    <?php endif; ?>
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
                        <input type="text" name="catname" class="form-control"
                               placeholder="e.g. Quizzes, Exams, Projects, Attendance">
                    </div>
                    <div class="col-md-3">
                        <label><strong>Weight (%)</strong></label>
                        <input type="number" name="catweight" class="form-control"
                               placeholder="e.g. 30" min="0" max="100" step="0.01">
                    </div>
                    <div class="col-md-2 mt-2">
                        <button type="submit" class="btn btn-success btn-block">Add</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- SECTION 3: Grade Item Mapping -->
    <div class="card mb-4">
        <div class="card-header bg-dark text-white">
            <strong>Grade Item Mapping</strong>
        </div>
        <div class="card-body">
            <?php if (!empty($mapsuccess)): ?>
                <div class="alert alert-success"><?php echo $mapsuccess; ?></div>
            <?php endif; ?>

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
                <div class="alert alert-warning">
                    ⚠ Please add grade categories first before mapping grade items.
                </div>
            <?php elseif (empty($gitems)): ?>
                <div class="alert alert-warning">No grade items found in this course.</div>
            <?php else: ?>
                <p class="text-muted">Assign each grade item to a <strong>Category</strong> and a <strong>Period</strong>.</p>
                <form method="post">
                    <input type="hidden" name="action" value="savemapping">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <table class="table table-bordered table-striped">
                        <thead class="thead-dark">
                            <tr>
                                <th>Grade Item</th>
                                <th>Max Grade</th>
                                <th>Category</th>
                                <th>Period</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($gitems as $gitem):
                                $curcat    = isset($mappings[$gitem->id]) ? $mappings[$gitem->id]['categoryid'] : 0;
                                $curperiod = isset($mappings[$gitem->id]) ? $mappings[$gitem->id]['period']     : 'finals';
                            ?>
                            <tr>
                                <td><strong><?php echo format_string($gitem->itemname); ?></strong></td>
                                <td><?php echo number_format($gitem->grademax, 0); ?></td>
                                <td>
                                    <select name="cat_<?php echo $gitem->id; ?>" class="form-control form-control-sm">
                                        <option value="0">-- Select Category --</option>
                                        <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat->id; ?>"
                                            <?php echo ($curcat == $cat->id) ? 'selected' : ''; ?>>
                                            <?php echo s($cat->name); ?> (<?php echo $cat->weight; ?>%)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select name="period_<?php echo $gitem->id; ?>" class="form-control form-control-sm">
                                        <option value="midterm" <?php echo ($curperiod === 'midterm') ? 'selected' : ''; ?>>Midterm</option>
                                        <option value="finals"  <?php echo ($curperiod === 'finals')  ? 'selected' : ''; ?>>Finals</option>
                                    </select>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <button type="submit" class="btn btn-primary">Save Mapping</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- SECTION 4: Grading Scale / Transmutation -->
    <div class="card mb-4" id="grading-scale">
        <div class="card-header bg-dark text-white">
            <strong>Grading Scale / Transmutation</strong>
        </div>
        <div class="card-body">
            <?php if (!empty($scalesuccess)): ?>
                <div class="alert alert-success"><?php echo $scalesuccess; ?></div>
            <?php endif; ?>
            <?php if (!empty($scaleerror)): ?>
                <div class="alert alert-danger"><?php echo $scaleerror; ?></div>
            <?php endif; ?>

            <p class="text-muted">
                By default this course uses ESSU's standard transmutation table. Add brackets below to define
                your own scale instead — for example, if the college uses a different equivalent-rating system.
                Brackets are matched by the student's numeric average (0–100) falling between Min and Max.
            </p>

            <?php if ($usingcustomscale): ?>
                <div class="alert alert-info d-flex justify-content-between align-items-center">
                    <span>✓ This course is using a <strong>custom</strong> grading scale.</span>
                    <form method="post" onsubmit="return confirm('Delete all custom brackets and revert to the default ESSU scale?');">
                        <input type="hidden" name="action" value="resetscale">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm">Reset to Default Scale</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="alert alert-secondary">
                    Currently using the <strong>default ESSU</strong> scale (no custom brackets defined).
                </div>
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
                        <?php foreach ($transmuterows as $row): ?>
                        <?php if ($edittransmute && (int) $edittransmute->id === (int) $row->id): ?>
                        <tr class="table-warning">
                            <td>
                                <form method="post" id="edittransmuteform<?php echo $row->id; ?>">
                                    <input type="hidden" name="action" value="updatetransmute">
                                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                    <input type="hidden" name="tid" value="<?php echo $row->id; ?>">
                                    <input type="number" step="0.01" name="tmin" class="form-control form-control-sm" value="<?php echo s($row->minscore); ?>">
                                </form>
                            </td>
                            <td><input type="number" step="0.01" name="tmax" class="form-control form-control-sm" form="edittransmuteform<?php echo $row->id; ?>" value="<?php echo s($row->maxscore); ?>"></td>
                            <td><input type="text" name="tequiv" class="form-control form-control-sm" form="edittransmuteform<?php echo $row->id; ?>" value="<?php echo s($row->equivalent); ?>"></td>
                            <td><input type="text" name="tdesc" class="form-control form-control-sm" form="edittransmuteform<?php echo $row->id; ?>" value="<?php echo s($row->descriptor); ?>"></td>
                            <td>
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" name="tispassing" value="1"
                                           form="edittransmuteform<?php echo $row->id; ?>"
                                           <?php echo $row->ispassing ? 'checked' : ''; ?>>
                                </div>
                            </td>
                            <td>
                                <button type="submit" class="btn btn-primary btn-sm" form="edittransmuteform<?php echo $row->id; ?>">Save</button>
                                <a href="course_settings.php?courseid=<?php echo $courseid; ?>#grading-scale" class="btn btn-secondary btn-sm">Cancel</a>
                            </td>
                        </tr>
                        <?php else: ?>
                        <tr>
                            <td><?php echo s($row->minscore); ?></td>
                            <td><?php echo s($row->maxscore); ?></td>
                            <td><strong><?php echo s($row->equivalent); ?></strong></td>
                            <td><?php echo s($row->descriptor); ?></td>
                            <td><?php echo $row->ispassing ? '<span class="badge badge-success">Yes</span>' : '<span class="badge badge-secondary">No</span>'; ?></td>
                            <td>
                                <a href="course_settings.php?courseid=<?php echo $courseid; ?>&edittid=<?php echo $row->id; ?>#grading-scale"
                                   class="btn btn-warning btn-sm">Edit</a>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="action" value="deletetransmute">
                                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                    <input type="hidden" name="tid" value="<?php echo $row->id; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"
                                            onclick="return confirm('Delete this bracket?')">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php foreach ($displaylegend as $lrow): ?>
                        <tr class="text-muted">
                            <td colspan="2"><?php echo s($lrow[0]); ?></td>
                            <td><?php echo s($lrow[1]); ?></td>
                            <td><?php echo s($lrow[2]); ?></td>
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
                <p class="text-muted small">Adding your first bracket switches this course to a custom scale.</p>
                <div class="form-row align-items-end">
                    <div class="col-md-2">
                        <label><strong>Min Score</strong></label>
                        <input type="number" step="0.01" name="tmin" class="form-control" placeholder="e.g. 90" required>
                    </div>
                    <div class="col-md-2">
                        <label><strong>Max Score</strong></label>
                        <input type="number" step="0.01" name="tmax" class="form-control" placeholder="e.g. 100" required>
                    </div>
                    <div class="col-md-2">
                        <label><strong>Equivalent</strong></label>
                        <input type="text" name="tequiv" class="form-control" placeholder="e.g. A" required>
                    </div>
                    <div class="col-md-4">
                        <label><strong>Descriptor</strong></label>
                        <input type="text" name="tdesc" class="form-control" placeholder="e.g. Outstanding">
                    </div>
                    <div class="col-md-2 mt-2">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="tispassing" value="1" checked>
                            <label class="form-check-label"><strong>Counts as Passing</strong></label>
                        </div>
                    </div>
                    <div class="col-md-2 mt-2">
                        <button type="submit" class="btn btn-success btn-block">Add</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php echo $OUTPUT->footer(); ?>