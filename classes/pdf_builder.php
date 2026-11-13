<?php
namespace local_gradesheet;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds the official "Report of Grades" PDF for one course or one section.
 * Used by export.php (single download / inline preview) and export_all.php
 * (one PDF per section, zipped), so both produce byte-identical layouts.
 */
class pdf_builder {

    /** Student rows per printed page; excel_builder paginates with the same number. */
    const ROWS_PER_PAGE = 20;

    /**
     * @return array{pdf: gradesheet_pdf, filename: string, data: array}|null
     *         Null when the roster is empty (nothing to print).
     */
    public static function build(int $courseid, int $groupid = 0): ?array {
        global $DB;

        $data = gradesheet_service::compute_all_grades($courseid, $groupid);
        if (empty($data['rows'])) {
            return null;
        }

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

        $pdf = new gradesheet_pdf('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('ESSU Grade Sheet Plugin');
        $pdf->SetTitle('Report of Grades - ' . $coursename . ($groupid > 0 ? ' - ' . $courseandyear : ''));
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
        $pages = empty($rows) ? [[]] : array_chunk($rows, self::ROWS_PER_PAGE);

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

            $is_custom = !helper::legend_has_equivalent($courseid);
            $legendX = $is_custom ? 145 : 120;
            $pdf->SetXY($legendX, $infoY);
            $pdf->SetFont('helvetica', 'B', 7);
            $pdf->Cell(25, 4, 'Actual Rating',     1, 0, 'C');
            if (!$is_custom) {
                $pdf->Cell(25, 4, 'Equivalent Rating', 1, 0, 'C');
            }
            $pdf->Cell(30, 4, 'Adjectival Rating', 1, 1, 'C');

            $pdf->SetFont('helvetica', '', 7);
            foreach ($pdf->report['legend'] as $lrow) {
                $pdf->SetX($legendX);
                $pdf->Cell(25, 3, $lrow[0], 1, 0, 'C');
                if (!$is_custom) {
                    $pdf->Cell(25, 3, $lrow[1], 1, 0, 'C');
                }
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
                $remarks = ($row['remarks'] === 'Withdrawn w/ permission') ? 'WP' : $row['remarks'];
                $pdf->Cell($col[6], 5.5, $remarks,  1, 1, 'C', $fill);
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

        return ['pdf' => $pdf, 'filename' => self::filename($courseid, $groupid, 'pdf'), 'data' => $data];
    }

    /** ReportOfGrades_<course>[_<section>]_<yyyymmdd>.<ext> */
    public static function filename(int $courseid, int $groupid, string $ext): string {
        global $DB;
        $coursename = $DB->get_field('course', 'fullname', ['id' => $courseid]);
        $suffix = '';
        if ($groupid > 0) {
            $gname = $DB->get_field('groups', 'name', ['id' => $groupid]);
            if (!empty($gname)) {
                $suffix = '_' . clean_filename($gname);
            }
        }
        return clean_filename('ReportOfGrades_' . str_replace(' ', '_', format_string($coursename)) . $suffix . '_' . date('Ymd') . '.' . $ext);
    }
}
