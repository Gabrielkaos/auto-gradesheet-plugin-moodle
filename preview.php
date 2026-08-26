<?php
require_once('../../config.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->libdir.'/grade/grade_item.php');

use local_gradesheet\helper;
use local_gradesheet\gradesheet_service;

$courseid = required_param('courseid', PARAM_INT);
$course   = get_course($courseid);
require_login($course);
$context  = context_course::instance($courseid);
require_capability('local/gradesheet:manage', $context);

$PAGE->set_url('/local/gradesheet/preview.php', ['courseid' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_gradesheet'));
$PAGE->set_heading(get_string('pluginname', 'local_gradesheet'));

$data = gradesheet_service::compute_all_grades($courseid);

$coursename    = $data['coursename'];
$semester      = $data['semester'];
$schoolyear    = $data['schoolyear'];
$coursenumber  = $data['coursenumber'];
$descriptive   = $data['descriptive'];
$courseandyear = $data['courseandyear'];
$schedule      = $data['schedule'];
$units         = $data['units'];
$instructor    = $data['instructor'];
$depthead      = $data['depthead'];
$registrar     = $data['registrar'];
$collegedean   = $data['collegedean'];
$rows          = $data['rows'];

$weightvalid = helper::validate_weight_sum($courseid);
if (!$weightvalid['valid']) {
    echo $OUTPUT->header();
echo '<div class="gradesheet-preview-page">';
    echo '<div class="container mt-4">';
    echo '<div class="alert alert-danger d-flex align-items-center" role="alert" style="font-size:16px; padding:20px 25px;">';
    echo '<span style="font-size:36px; margin-right:15px;">&#9888;</span>';
    echo '<div>';
    echo '<strong>Cannot Preview Grade Sheet</strong><br>';
    echo 'Category weights must sum to exactly <strong>100%</strong>. ';
    echo 'Current total: <strong>' . $weightvalid['total'] . '%</strong>. ';
    if ($weightvalid['count'] === 0) {
        echo 'No categories are defined.<br>';
    }
    echo 'Please fix this in Settings before printing.';
    echo '</div></div>';
    echo '<a href="course_settings.php?courseid=' . $courseid . '" class="btn btn-primary">Go to Settings</a> ';
    echo '<a href="index.php?courseid=' . $courseid . '" class="btn btn-secondary">Back to Grade Sheet</a>';
    echo '</div>';
    echo '</div>';
echo $OUTPUT->footer();
    exit;
}

$PAGE->set_title('Report of Grades — ' . $coursename);
$PAGE->set_heading('Report of Grades Preview');

// ── PAGINATION ──────────────────────────────────────────────────────────
// Split students across multiple A4-sized "pages" instead of one giant
// continuous sheet. Every page now carries its own full signature block,
// so both constants can use the same, slightly smaller, row budget.
// Tune these if rows overflow/underflow a printed page in your browser.
$rowsperpage    = 20; // Max student rows per page (all pages now include signatures).

$pages = array_chunk($rows, $rowsperpage);

$totalpages = count($pages);

echo $OUTPUT->header();
echo '<div class="gradesheet-preview-page">';
?>



<div class="preview-toolbar">
    <div>
        <strong>Report of Grades Preview</strong>
        <span class="text-muted ml-2">- <?php echo s(format_string($coursename)); ?></span>
        <span class="text-muted ml-2">(<?php echo $totalpages; ?> page<?php echo $totalpages === 1 ? '' : 's'; ?>)</span>
    </div>
    <div>
        <a href="index.php?courseid=<?php echo $courseid; ?>" class="btn btn-secondary btn-sm">← Back</a>
        <button onclick="window.print()" class="btn btn-primary btn-sm ml-2">Print</button>
        <a href="export.php?courseid=<?php echo $courseid; ?>" class="btn btn-success btn-sm ml-2">Download PDF</a>
        <a href="course_settings.php?courseid=<?php echo $courseid; ?>" class="btn btn-secondary btn-sm ml-2">Settings</a>
    </div>
</div>

<div class="gradesheet-pages">
<?php
$rownum = 1;
foreach ($pages as $pageindex => $pagerows):
    $islastpage = ($pageindex === $totalpages - 1);
?>
<div class="gradesheet-page">
    <div class="gs-page-label">Page <?php echo $pageindex + 1; ?> of <?php echo $totalpages; ?></div>

    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px; width:100%;">
        <img src="<?php echo $CFG->wwwroot; ?>/local/gradesheet/pix/essu-header.png" style="width:160px; height:auto;">
        <div style="text-align:center; align-self:center;">
            <div style="font-size:18px; font-weight:bold; letter-spacing:2px;">REPORT OF GRADES</div>
            <div style="font-size:11px; color:#333; margin-top:4px;">
                <?php echo s($semester); ?> &nbsp;&nbsp; SY <?php echo s($schoolyear); ?>
            </div>
        </div>
        <img src="<?php echo $CFG->wwwroot; ?>/local/gradesheet/pix/bagong-pilipinas.png" style="width:80px; height:auto;">
    </div>

    <div class="gs-info-legend">
        <div class="gs-info">
            <table>
                <tr><td>Subject and Course No. :</td><td><strong><?php echo htmlspecialchars($coursenumber); ?></strong></td></tr>
                <tr><td>Descriptive Title :</td><td><strong><?php echo htmlspecialchars($descriptive); ?></strong></td></tr>
                <tr><td>Course and Year :</td><td><strong><?php echo htmlspecialchars($courseandyear); ?></strong></td></tr>
                <tr><td>Schedule of Classes :</td><td><strong><?php echo htmlspecialchars($schedule); ?></strong></td></tr>
                <tr><td>Number of Units :</td><td><strong><?php echo htmlspecialchars($units); ?></strong></td></tr>
            </table>
        </div>
        <div class="gs-legend">
            <?php
                $is_custom = helper::get_custom_transmute_rows($courseid) ? true : false;
            ?>
            <table>
                <thead>
                    <tr>
                        <th>Actual<br>Rating</th>
                        <?php if (!$is_custom): ?><th>Equivalent<br>Rating</th><?php endif; ?>
                        <th>Adjectival<br>Rating</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (helper::get_rating_legend($courseid) as $lrow): ?>
                    <tr>
                        <td><?php echo s($lrow[0]); ?></td>
                        <?php if (!$is_custom): ?><td><?php echo s($lrow[1]); ?></td><?php endif; ?>
                        <td><?php echo s($lrow[2]); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <table class="gs-table">
        <thead>
            <tr>
                <th style="width:5.56%">NO.</th>
                <th style="width:33.33%">NAME OF STUDENTS</th>
                <th style="width:15.56%">STUDENT NO.</th>
                <th style="width:11.11%">MIDTERM</th>
				<th style="width:11.11%">FINALS</th>
                <th style="width:11.11%">AVERAGE</th>
                <th style="width:12.22%">REMARKS</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pagerows as $row): ?>
            <?php $isfailed = ($row['remarks'] === 'Failed'); ?>
            <tr>
                <td class="<?php echo $isfailed ? 'failed-cell' : ''; ?>"><?php echo $rownum++; ?></td>
                <td class="name-col"><?php echo htmlspecialchars($row['name']); ?></td>
                <td><?php echo htmlspecialchars($row['idnumber']); ?></td>
                <td><?php echo s($row['midterm']); ?></td>
				<td><?php echo s($row['finals']); ?></td>
                <td><?php echo s($row['average']); ?></td>
                <td class="<?php echo $isfailed ? 'failed-cell' : ''; ?>"><?php echo s($row['remarks']); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if ($islastpage): ?>
            <tr>
                <td></td>
                <td class="name-col"><em>***Nothing Follows***</em></td>
                <td></td>
                <td></td><td></td>
                <td></td><td></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="gs-signatures">
        <div class="gs-sig-row">
            <div class="gs-sig-block">
                <div class="gs-sig-label">Certified True &amp; Correct:</div>
                <div class="gs-sig-name"><?php echo htmlspecialchars($instructor); ?></div>
                <div class="gs-sig-title">Instructor</div>
            </div>
            <div class="gs-sig-block">
                <div class="gs-sig-label">Checked:</div>
                <div class="gs-sig-name"><?php echo htmlspecialchars($depthead); ?></div>
                <div class="gs-sig-title">Department Head</div>
            </div>
        </div>
        <div class="gs-sig-row">
            <div class="gs-sig-block">
                <div class="gs-sig-label">Received:</div>
                <div class="gs-sig-name"><?php echo htmlspecialchars($registrar); ?></div>
                <div class="gs-sig-title">Registrar</div>
            </div>
            <div class="gs-sig-block">
                <div class="gs-sig-label">Approved:</div>
                <div class="gs-sig-name"><?php echo htmlspecialchars($collegedean); ?></div>
                <div class="gs-sig-title">College Dean</div>
            </div>
        </div>
    </div>

    <div class="gs-footer">
        <span>ESSU-ACAD-712.b &nbsp;|&nbsp; Version 5<br>Effectivity Date: March 15, 2024</span>
        <span>Page <?php echo $pageindex + 1; ?> of <?php echo $totalpages; ?></span>
    </div>

</div>
<?php endforeach; ?>
</div>

<?php echo '</div>';
echo $OUTPUT->footer(); ?>