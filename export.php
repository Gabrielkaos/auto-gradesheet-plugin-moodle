<?php
ob_start();
require_once('../../config.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->libdir.'/grade/grade_item.php');
require_once($CFG->libdir.'/pdflib.php');

require_login();

use local_gradesheet\helper;
use local_gradesheet\gradesheet_service;

$courseid = required_param('courseid', PARAM_INT);
$context  = context_course::instance($courseid);
require_capability('local/gradesheet:manage', $context);

$weightvalid = helper::validate_weight_sum($courseid);
if (!$weightvalid['valid']) {
    redirect(
        new moodle_url('/local/gradesheet/index.php', ['courseid' => $courseid]),
        'Cannot export: Category weights must sum to exactly 100% (currently ' . $weightvalid['total'] . '%). Please fix this in Settings.',
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$data = gradesheet_service::compute_all_grades($courseid);
extract($data);

$rows = $data['rows'];

class gradesheet_pdf extends pdf {
    public $report = [];

    public function Header() {
        global $CFG;

        $pageW = $this->getPageWidth();
        $topY = 10;
        $essulogo = $CFG->dirroot . '/local/gradesheet/pix/essu-header.png';
        $bagonglogo = $CFG->dirroot . '/local/gradesheet/pix/bagong-pilipinas.png';

        if (file_exists($essulogo)) {
            $this->Image($essulogo, 12, $topY, 55, 0);
        }
        if (file_exists($bagonglogo)) {
            $this->Image($bagonglogo, $pageW - 37, $topY, 25, 0);
        }
    }

    public function Footer() {
        $this->SetY(-24);
        $this->SetFont('helvetica', '', 7);
        $this->Line(15, $this->GetY(), $this->getPageWidth() - 15, $this->GetY());
        $this->Ln(1);
        $this->Cell(0, 4, 'ESSU-ACAD-712.b  |  Version 5', 0, 0, 'L');
        $this->Cell(0, 4, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 1, 'R');
        $this->Cell(0, 4, 'Effectivity Date: March 15, 2024', 0, 0, 'L');
    }
}

$pdf = new gradesheet_pdf('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('ESSU Grade Sheet Plugin');
$pdf->SetTitle('Report of Grades - ' . $coursename);
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->SetMargins(15, 42, 15);
$pdf->SetHeaderMargin(8);
$pdf->SetFooterMargin(24);
$pdf->SetAutoPageBreak(true, 28);
$pdf->report = [
    'semester'      => $semester,
    'schoolyear'    => $schoolyear,
    'coursenumber'  => $coursenumber,
    'descriptive'   => $descriptive,
    'courseandyear' => $courseandyear,
    'schedule'      => $schedule,
    'units'         => $units,
    'legend'        => helper::get_rating_legend($courseid),
];

// ── PAGINATION ──────────────────────────────────────────────────────────
// Mirrors preview.php exactly: every "page" gets its own full header,
// legend, table header, and signature block, capped at 20 student rows.
$rowsperpage    = 20;
$rowsonlastpage = 20;

$totalrows = count($rows);
$pages = [];

if ($totalrows <= $rowsonlastpage) {
    $pages[] = $rows;
} else {
    $offset    = 0;
    $remaining = $totalrows;
    while ($remaining > $rowsonlastpage) {
        $take = min($rowsperpage, $remaining - $rowsonlastpage);
        $pages[] = array_slice($rows, $offset, $take);
        $offset    += $take;
        $remaining -= $take;
    }
    $pages[] = array_slice($rows, $offset);
}

$totalpages = count($pages);

$col     = [10, 60, 28, 20, 20, 20, 22];
$headers = ['NO.', 'NAME OF STUDENTS', 'STUDENT NO.', 'MIDTERM', 'FINALS', 'AVERAGE', 'REMARKS'];

$rownum = 1;

foreach ($pages as $pageindex => $pagerows) {
    $islastpage = ($pageindex === $totalpages - 1);

    $pdf->AddPage();

    // ── Title block ──
    $pdf->SetY(42);
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 8, 'REPORT OF GRADES', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, $semester . '  SY ' . $schoolyear, 0, 1, 'C');
    $pdf->Ln(4);

    // ── Course info + legend ──
    $infoY = $pdf->GetY();
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetX(15); $pdf->Cell(40, 5, 'Subject and Course No. :', 0, 0);
    $pdf->SetFont('helvetica', 'B', 9); $pdf->Cell(0, 5, $coursenumber, 0, 1);

    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetX(15); $pdf->Cell(40, 5, 'Descriptive Title :', 0, 0);
    $pdf->SetFont('helvetica', 'B', 9); $pdf->Cell(0, 5, $descriptive, 0, 1);

    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetX(15); $pdf->Cell(40, 5, 'Course and Year :', 0, 0);
    $pdf->Cell(0, 5, $courseandyear, 0, 1);

    $pdf->SetX(15); $pdf->Cell(40, 5, 'Schedule of Classes :', 0, 0);
    $pdf->Cell(0, 5, $schedule, 0, 1);

    $pdf->SetX(15); $pdf->Cell(40, 5, 'Number of Units :', 0, 0);
    $pdf->Cell(0, 5, $units, 0, 1);

    $legendX = 120;
    $pdf->SetXY($legendX, $infoY);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell(25, 4, 'Actual Rating',     1, 0, 'C');
    $pdf->Cell(25, 4, 'Equivalent Rating', 1, 0, 'C');
    $pdf->Cell(30, 4, 'Adjectival Rating', 1, 1, 'C');

    $pdf->SetFont('helvetica', '', 7);
    foreach ($pdf->report['legend'] as $lrow) {
        $pdf->SetX($legendX);
        $pdf->Cell(25, 3, $lrow[0], 1, 0, 'C');
        $pdf->Cell(25, 3, $lrow[1], 1, 0, 'C');
        $pdf->Cell(30, 3, $lrow[2], 1, 1, 'L');
    }

    $pdf->Ln(3);

    // ── Table header ──
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetTextColor(0, 0, 0);
    foreach ($headers as $i => $h) {
        $pdf->Cell($col[$i], 7, $h, 1, 0, 'C');
    }
    $pdf->Ln();

    // ── Table rows for this page ──
    $pdf->SetFont('helvetica', '', 8);
    foreach ($pagerows as $i => $row) {
        $fill = ($i % 2 === 0);
        $pdf->SetFillColor(245, 245, 245);

        $isFailed = ($row['remarks'] === 'Failed');
        $pdf->SetTextColor(0, 0, 0);

        if ($isFailed) $pdf->SetTextColor(180, 0, 0);
        $pdf->Cell($col[0], 5.5, $rownum,          1, 0, 'C', $fill);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell($col[1], 5.5, $row['name'],     1, 0, 'L', $fill);
        $pdf->Cell($col[2], 5.5, $row['idnumber'], 1, 0, 'C', $fill);
        $pdf->Cell($col[3], 5.5, $row['midterm'],  1, 0, 'C', $fill);
        $pdf->Cell($col[4], 5.5, $row['finals'],   1, 0, 'C', $fill);
        $pdf->Cell($col[5], 5.5, $row['average'],  1, 0, 'C', $fill);
        if ($isFailed) $pdf->SetTextColor(180, 0, 0);
        $pdf->Cell($col[6], 5.5, $row['remarks'],  1, 1, 'C', $fill);
        $pdf->SetTextColor(0, 0, 0);

        $rownum++;
    }

    // "Nothing follows" only belongs after the real data, on the last page.
    if ($islastpage) {
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->Cell($col[0], 5.5, '',                     1, 0, 'C');
        $pdf->Cell($col[1], 5.5, '***Nothing Follows***', 1, 0, 'L');
        foreach ([2, 3, 4, 5, 6] as $ci) {
            $pdf->Cell($col[$ci], 5.5, '', 1, 0, 'C');
        }
        $pdf->Ln();
    }

    $pdf->Ln(3);

    // ── Signature block (repeated on every page, matching preview.php) ──
    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->Cell(90, 3, 'Certified True & Correct:', 0, 0);
    $pdf->Cell(0,  3, 'Checked:', 0, 1);
    $pdf->Ln(6);

    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(90, 3, $instructor, 0, 0, 'C');
    $pdf->Cell(0,  3, $depthead,   0, 1, 'C');

    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(90, 3, 'Instructor',      0, 0, 'C');
    $pdf->Cell(0,  3, 'Department Head', 0, 1, 'C');

    $pdf->Ln(4);

    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->Cell(90, 3, 'Received:', 0, 0);
    $pdf->Cell(0,  3, 'Approved:', 0, 1);
    $pdf->Ln(6);

    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(90, 3, $registrar,   0, 0, 'C');
    $pdf->Cell(0,  3, $collegedean, 0, 1, 'C');

    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(90, 3, 'Registrar',    0, 0, 'C');
    $pdf->Cell(0,  3, 'College Dean', 0, 1, 'C');
}

$filename = 'ReportOfGrades_' . str_replace(' ', '_', $coursename) . '_' . date('Ymd') . '.pdf';
while (ob_get_level()) {
    ob_end_clean();
}
$pdf->Output($filename, 'D');
exit;