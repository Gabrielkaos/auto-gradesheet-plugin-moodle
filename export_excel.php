<?php
require_once('../../config.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->libdir.'/grade/grade_item.php');
require_once(dirname($CFG->dirroot) . '/vendor/autoload.php');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use local_gradesheet\helper;
use local_gradesheet\gradesheet_service;

require_login();

$courseid = required_param('courseid', PARAM_INT);
$context  = context_course::instance($courseid);
require_capability('local/gradesheet:manage', $context);

$data = gradesheet_service::compute_all_grades($courseid);
extract($data);

$rows = $data['rows'];

$colNO      = 'A';
$colNAME    = 'B';
$colSTUDENT = 'C';

$alphabet = range('A', 'Z');
$dynamicCols            = [];
$dynamicCols['midterm'] = 'D';
$dynamicCols['finals']  = 'E';
$colAVERAGE             = 'F';
$colREMARKS             = 'G';
$lastCol                = 'G';

$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('Report of Grades');

$allBorderThin = [
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
];

$sheet->mergeCells("A1:{$lastCol}1");
$sheet->setCellValue('A1', 'EASTERN SAMAR STATE UNIVERSITY');
$sheet->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 14],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

$sheet->mergeCells("A2:{$lastCol}2");
$sheet->setCellValue('A2', 'Excellence  •  Accountability  •  Service');
$sheet->getStyle('A2')->applyFromArray([
    'font'      => ['italic' => true, 'size' => 9],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

$sheet->mergeCells("A3:{$lastCol}3");
$sheet->setCellValue('A3', 'REPORT OF GRADES');
$sheet->getStyle('A3')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 13],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

$sheet->mergeCells("A4:{$lastCol}4");
$sheet->setCellValue('A4', $semester . '  SY ' . $schoolyear);
$sheet->getStyle('A4')->applyFromArray([
    'font'      => ['size' => 10],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

$infoData = [
    6  => ['Subject and Course No. :', $coursenumber],
    7  => ['Descriptive Title :',      $descriptive],
    8  => ['Course and Year :',        $courseandyear],
    9  => ['Schedule of Classes :',    $schedule],
    10 => ['Number of Units :',        $units],
];
foreach ($infoData as $r => [$label, $val]) {
    $sheet->setCellValue('A' . $r, $label);
    $sheet->mergeCells('B' . $r . ':D' . $r);
    $sheet->setCellValue('B' . $r, $val);
    $sheet->getStyle('A' . $r)->getFont()->setItalic(true);
    $sheet->getStyle('B' . $r)->getFont()->setBold(true);
}

$legend = [['Actual Rating', 'Equivalent Rating', 'Adjectival Rating']];
$legend = array_merge($legend, helper::get_rating_legend());

$legendStartRow = 6;
foreach ($legend as $li => $lrow) {
    $r        = $legendStartRow + $li;
    $isHeader = ($li === 0);
    $sheet->setCellValue('E' . $r, $lrow[0]);
    $sheet->setCellValue('F' . $r, $lrow[1]);
    $sheet->setCellValue('G' . $r, $lrow[2]);
    $sheet->getStyle("E{$r}:G{$r}")->applyFromArray([
        'font'      => ['bold' => $isHeader, 'size' => 8],
        'fill'      => $isHeader
            ? ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDDDDD']]
            : ['fillType' => Fill::FILL_NONE],
        'borders'   => $allBorderThin['borders'],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
    $sheet->getStyle('G' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
}

$tableHeaderRow = 19;

$sheet->setCellValue('A' . $tableHeaderRow, 'NO.');
$sheet->setCellValue('B' . $tableHeaderRow, 'NAME OF STUDENTS');
$sheet->setCellValue('C' . $tableHeaderRow, 'STUDENT NO.');

$sheet->setCellValue('D' . $tableHeaderRow, 'MIDTERM');
$sheet->setCellValue('E' . $tableHeaderRow, 'FINALS');

$sheet->setCellValue($colAVERAGE . $tableHeaderRow, 'AVERAGE');
$sheet->setCellValue($colREMARKS . $tableHeaderRow, 'REMARKS');

$sheet->getStyle("A{$tableHeaderRow}:{$lastCol}{$tableHeaderRow}")->applyFromArray([
    'font'      => ['bold' => true, 'size' => 10],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical'   => Alignment::VERTICAL_CENTER,
        'wrapText'   => true,
    ],
    'borders'   => $allBorderThin['borders'],
]);
$sheet->getRowDimension($tableHeaderRow)->setRowHeight(28);

$dataRow = $tableHeaderRow + 1;

foreach ($rows as $i => $row) {
    $evenFill  = ($i % 2 === 0) ? 'F5F5F5' : 'FFFFFF';
    $isFailed  = ($row['remarks'] === 'Failed');
    $textColor = $isFailed ? 'CC0000' : '000000';

    $sheet->setCellValue('A' . $dataRow, $i + 1);
    $sheet->setCellValue('B' . $dataRow, $row['name']);
    $sheet->setCellValue('C' . $dataRow, $row['idnumber']);

    $sheet->setCellValue('D' . $dataRow, $row['midterm']);
    $sheet->setCellValue('E' . $dataRow, $row['finals']);

    $sheet->setCellValue($colAVERAGE . $dataRow, $row['average']);
    $sheet->setCellValue($colREMARKS . $dataRow, $row['remarks']);

    $sheet->getStyle("A{$dataRow}:{$lastCol}{$dataRow}")->applyFromArray([
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $evenFill]],
        'borders'   => $allBorderThin['borders'],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'font'      => ['color' => ['rgb' => $textColor], 'size' => 10],
    ]);
    $sheet->getStyle('B' . $dataRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getRowDimension($dataRow)->setRowHeight(16);

    $dataRow++;
}

$sheet->setCellValue('B' . $dataRow, '***Nothing Follows***');
$sheet->getStyle("A{$dataRow}:{$lastCol}{$dataRow}")->applyFromArray([
    'font'    => ['italic' => true, 'size' => 10],
    'borders' => $allBorderThin['borders'],
]);

$sigRow = $dataRow + 3;

$sheet->setCellValue('A' . $sigRow, 'Certified True & Correct:');
$sheet->setCellValue('E' . $sigRow, 'Checked:');
$sheet->getStyle('A' . $sigRow)->getFont()->setItalic(true);
$sheet->getStyle('E' . $sigRow)->getFont()->setItalic(true);

$sigRow += 3;
$sheet->mergeCells('A' . $sigRow . ':C' . $sigRow);
$sheet->setCellValue('A' . $sigRow, $instructor);
$sheet->getStyle('A' . $sigRow)->applyFromArray([
    'font'      => ['bold' => true],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$sheet->mergeCells('E' . $sigRow . ':G' . $sigRow);
$sheet->setCellValue('E' . $sigRow, $depthead);
$sheet->getStyle('E' . $sigRow)->applyFromArray([
    'font'      => ['bold' => true],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

$sigRow++;
$sheet->mergeCells('A' . $sigRow . ':C' . $sigRow);
$sheet->setCellValue('A' . $sigRow, 'Instructor');
$sheet->getStyle('A' . $sigRow)->applyFromArray([
    'font'      => ['italic' => true],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$sheet->mergeCells('E' . $sigRow . ':G' . $sigRow);
$sheet->setCellValue('E' . $sigRow, 'Department Head');
$sheet->getStyle('E' . $sigRow)->applyFromArray([
    'font'      => ['italic' => true],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

$sigRow += 4;
$sheet->setCellValue('A' . $sigRow, 'Received:');
$sheet->setCellValue('E' . $sigRow, 'Approved:');
$sheet->getStyle('A' . $sigRow)->getFont()->setItalic(true);
$sheet->getStyle('E' . $sigRow)->getFont()->setItalic(true);

$sigRow += 3;
$sheet->mergeCells('A' . $sigRow . ':C' . $sigRow);
$sheet->setCellValue('A' . $sigRow, $registrar);
$sheet->getStyle('A' . $sigRow)->applyFromArray([
    'font'      => ['bold' => true],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$sheet->mergeCells('E' . $sigRow . ':G' . $sigRow);
$sheet->setCellValue('E' . $sigRow, $collegedean);
$sheet->getStyle('E' . $sigRow)->applyFromArray([
    'font'      => ['bold' => true],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

$sigRow++;
$sheet->mergeCells('A' . $sigRow . ':C' . $sigRow);
$sheet->setCellValue('A' . $sigRow, 'Registrar');
$sheet->getStyle('A' . $sigRow)->applyFromArray([
    'font'      => ['italic' => true],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$sheet->mergeCells('E' . $sigRow . ':G' . $sigRow);
$sheet->setCellValue('E' . $sigRow, 'College Dean');
$sheet->getStyle('E' . $sigRow)->applyFromArray([
    'font'      => ['italic' => true],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

$footerRow = $sigRow + 3;
$sheet->setCellValue('A' . $footerRow,       'ESSU-ACAD-712.b  |  Version 5');
$sheet->setCellValue('A' . ($footerRow + 1), 'Effectivity Date: March 15, 2024');
$sheet->getStyle('A' . $footerRow)->getFont()->setSize(7);
$sheet->getStyle('A' . ($footerRow + 1))->getFont()->setSize(7);
$sheet->mergeCells('E' . $footerRow . ':' . $lastCol . $footerRow);
$sheet->setCellValue('E' . $footerRow, 'Page 1 of 1');
$sheet->getStyle('E' . $footerRow)->applyFromArray([
    'font'      => ['size' => 7],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
]);

$sheet->getColumnDimension('A')->setWidth(6);
$sheet->getColumnDimension('B')->setWidth(32);
$sheet->getColumnDimension('C')->setWidth(16);

$sheet->getColumnDimension('D')->setWidth(12);
$sheet->getColumnDimension('E')->setWidth(12);

$sheet->getColumnDimension($colAVERAGE)->setWidth(12);
$sheet->getColumnDimension($colREMARKS)->setWidth(12);

$sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_PORTRAIT);
$sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_LETTER);
$sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.5)->setRight(0.5);
$sheet->getPageSetup()->setFitToPage(true);
$sheet->getPageSetup()->setFitToWidth(1);
$sheet->getPageSetup()->setFitToHeight(0);

$filename = 'ReportOfGrades_' . str_replace(' ', '_', $coursename) . '_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
