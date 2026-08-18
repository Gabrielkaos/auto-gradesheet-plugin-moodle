<?php
require_once('../../config.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->libdir.'/grade/grade_item.php');

require_login();

use local_gradesheet\helper;
use local_gradesheet\gradesheet_service;

$courseid = required_param('courseid', PARAM_INT);
$context  = context_course::instance($courseid);
require_capability('local/gradesheet:manage', $context);

$PAGE->set_url('/local/gradesheet/preview.php', ['courseid' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_gradesheet'));
$PAGE->set_heading(get_string('pluginname', 'local_gradesheet'));

$data = gradesheet_service::compute_all_grades($courseid);
extract($data);

$rows = $data['rows'];

$PAGE->set_url('/local/gradesheet/preview.php', ['courseid' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_title('Report of Grades — ' . $coursename);
$PAGE->set_heading('Report of Grades Preview');

echo $OUTPUT->header();
?>

<style>
.preview-toolbar {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 12px 20px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.gradesheet-wrapper {
    background: white;
    border: 1px solid #ccc;
    box-shadow: 0 4px 15px rgba(0,0,0,0.15);
    padding: 30px 35px;
    max-width: 794px;
    margin: 0 auto 40px auto;
    font-family: Arial, sans-serif;
    font-size: 10px;
}
.gs-info-legend { display: flex; gap: 10px; margin-bottom: 12px; }
.gs-info { flex: 1; font-size: 9.5px; }
.gs-info table td { padding: 1px 4px; vertical-align: top; }
.gs-info table td:first-child { font-style: italic; white-space: nowrap; color: #333; }
.gs-legend { font-size: 8px; }
.gs-legend table { border-collapse: collapse; }
.gs-legend table th, .gs-legend table td { border: 1px solid #999; padding: 1px 4px; text-align: center; }
.gs-legend table th { font-weight: bold; background: #f0f0f0; }
.gs-table { width: 100%; border-collapse: collapse; font-size: 9.5px; margin-bottom: 16px; }
.gs-table th { border: 1px solid #333; padding: 5px 3px; text-align: center; font-weight: bold; background: white; }
.gs-table td { border: 1px solid #555; padding: 4px 3px; text-align: center; }
.gs-table td.name-col { text-align: left; padding-left: 6px; }
.gs-table tr:nth-child(even) td { background: #f9f9f9; }
.failed-cell { color: #b40000; }
.gs-signatures { margin-top: 4px; font-size: 9px; }
.gs-sig-row { display: flex; gap: 20px; margin-bottom: 8px; }
.gs-sig-block { flex: 1; }
.gs-sig-label { font-style: italic; margin-bottom: 4px; }
.gs-sig-name { font-weight: bold; text-align: center; margin-top: 8px; }
.gs-sig-title { text-align: center; font-style: italic; font-size: 8.5px; }
.gs-footer { border-top: 1px solid #333; margin-top: 20px; padding-top: 4px;
             display: flex; justify-content: space-between; font-size: 8px; color: #333; }
@media print {
    body * { visibility: hidden; }
    .gradesheet-wrapper, .gradesheet-wrapper * { visibility: visible; }
    .gradesheet-wrapper {
        position: absolute; left: 0; top: 0;
        box-shadow: none; border: none;
        padding: 15mm 20mm; max-width: 100%; width: 100%;
    }
    .gs-table tr:nth-child(even) td { background: white !important; }
}
</style>

<div class="preview-toolbar">
    <div>
        <strong>Report of Grades Preview</strong>
        <span class="text-muted ml-2">- <?php echo $coursename; ?></span>
    </div>
    <div>
        <a href="index.php?courseid=<?php echo $courseid; ?>" class="btn btn-secondary btn-sm">← Back</a>
        <button onclick="window.print()" class="btn btn-primary btn-sm ml-2">Print</button>
        <a href="export.php?courseid=<?php echo $courseid; ?>" class="btn btn-success btn-sm ml-2">Download PDF</a>
        <a href="course_settings.php?courseid=<?php echo $courseid; ?>" class="btn btn-secondary btn-sm ml-2">Settings</a>
    </div>
</div>

<div class="gradesheet-wrapper">

    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px; width:100%;">
        <img src="<?php echo $CFG->wwwroot; ?>/local/gradesheet/pix/essu-header.png" style="width:160px; height:auto;">
        <div style="text-align:center; align-self:center;">
            <div style="font-size:18px; font-weight:bold; letter-spacing:2px;">REPORT OF GRADES</div>
            <div style="font-size:11px; color:#333; margin-top:4px;">
                <?php echo $semester; ?> &nbsp;&nbsp; SY <?php echo $schoolyear; ?>
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
            <table>
                <thead>
                    <tr><th>Actual<br>Rating</th><th>Equivalent<br>Rating</th><th>Adjectival<br>Rating</th></tr>
                </thead>
                <tbody>
                    <?php foreach (helper::get_rating_legend($courseid) as $lrow): ?>
                    <tr><td><?php echo $lrow[0]; ?></td><td><?php echo $lrow[1]; ?></td><td><?php echo $lrow[2]; ?></td></tr>
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
            <?php foreach ($rows as $i => $row): ?>
            <?php $isfailed = ($row['remarks'] === 'Failed'); ?>
            <tr>
                <td class="<?php echo $isfailed ? 'failed-cell' : ''; ?>"><?php echo $i + 1; ?></td>
                <td class="name-col"><?php echo htmlspecialchars($row['name']); ?></td>
                <td><?php echo htmlspecialchars($row['idnumber']); ?></td>
                <td><?php echo $row['midterm']; ?></td>
				<td><?php echo $row['finals']; ?></td>
                <td><?php echo $row['average']; ?></td>
                <td class="<?php echo $isfailed ? 'failed-cell' : ''; ?>"><?php echo $row['remarks']; ?></td>
            </tr>
            <?php endforeach; ?>
            <tr>
                <td></td>
                <td class="name-col"><em>***Nothing Follows***</em></td>
                <td></td>
                <td></td><td></td>
                <td></td><td></td>
            </tr>
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
        <span>Page 1 of 1</span>
    </div>

</div>

<?php echo $OUTPUT->footer(); ?>