<?php
namespace local_gradesheet;

defined('MOODLE_INTERNAL') || die();

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Drawing as SharedDrawing;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Builds the "Report of Grades" workbook for one course or one section.
 *
 * The sheet is laid out page by page so that printing it gives the same pages
 * as the PDF (pdf_builder): every page of up to pdf_builder::ROWS_PER_PAGE
 * students carries the logos, title, course info, rating legend, table header,
 * signature block and a "Page X of Y" footer, and an explicit page break
 * separates one page from the next. All text stays in ordinary cells so the
 * registrar's office can still edit it by hand before printing.
 *
 * render() is a pure function of its arguments (no Moodle calls) so the layout
 * can be exercised outside of Moodle; build() gathers the data it needs.
 */
class excel_builder {

    /** Column widths in Excel character units; their sum is sized to fit A4 at 100%. */
    const COLWIDTHS = ['A' => 5, 'B' => 28, 'C' => 13, 'D' => 10, 'E' => 10, 'F' => 10, 'G' => 14];

    const FONT = 'Arial';

    /** A4 portrait, in points (1/72 in). */
    const PAGE_HEIGHT_PT = 841.9;
    const MARGIN_TOP_IN    = 0.4;
    const MARGIN_BOTTOM_IN = 0.7;
    const MARGIN_SIDE_IN   = 0.5;

    /** Height of the band that holds the two logos, and the logos' size in px (96 dpi). */
    const LOGO_ROWS        = 5;
    const LOGO_ROW_PT      = 14;
    const LOGO_LEFT_WIDTH  = 208;
    const LOGO_RIGHT_SIZE  = 90;

    /** Row heights (pt) of the repeating parts of a page. */
    const H_TITLE     = 24;
    const H_SEMESTER  = 17;
    const H_GAP       = 8;
    const H_INFO      = 12;
    const H_LEGEND    = 11;
    const H_TBLHEAD   = 20;
    const H_TBLROW    = 15.5;
    const H_SIGLINE   = 13;
    const H_SIGSPACE  = 17;

    /** Rough number of characters of a bold 9pt course-info value that fit on one line. */
    const INFO_CHARS_PER_LINE = 26;

    /**
     * Builds the workbook for a course (or one section of it).
     *
     * @return array{spreadsheet: Spreadsheet, filename: string, data: array}|null
     *         Null when the roster is empty (nothing to print).
     */
    public static function build(int $courseid, int $groupid = 0): ?array {
        $data = gradesheet_service::compute_all_grades($courseid, $groupid);
        if (empty($data['rows'])) {
            return null;
        }

        $legend    = helper::get_rating_legend($courseid);
        $hasequiv  = helper::legend_has_equivalent($courseid);
        $logos     = self::prepare_logos();
        $title     = 'Report of Grades - ' . $data['coursename'] . ($groupid > 0 ? ' - ' . $data['courseandyear'] : '');

        return [
            'spreadsheet' => self::render($data, $legend, $hasequiv, $logos, $title),
            'filename'    => pdf_builder::filename($courseid, $groupid, 'xlsx'),
            'data'        => $data,
        ];
    }

    /**
     * Locates the logo images, downscaling the large national logo so it does
     * not bloat the workbook (the image is embedded once per printed page).
     *
     * @return array{left: ?string, right: ?string} file paths, null when missing
     */
    public static function prepare_logos(): array {
        global $CFG;
        $dir   = $CFG->dirroot . '/local/gradesheet/pix/';
        $left  = $dir . 'essu-header.png';
        $right = $dir . 'bagong-pilipinas.png';
        return [
            'left'  => file_exists($left) ? $left : null,
            'right' => file_exists($right) ? self::downscale_png($right, 2 * self::LOGO_RIGHT_SIZE) : null,
        ];
    }

    /**
     * Returns a copy of a PNG no larger than $maxpx on its longest side, or the
     * original path when it is already small enough or GD is unavailable.
     */
    private static function downscale_png(string $path, int $maxpx): string {
        if (!function_exists('imagecreatefrompng') || !function_exists('make_request_directory')) {
            return $path;
        }
        $info = @getimagesize($path);
        if (!$info || max($info[0], $info[1]) <= $maxpx) {
            return $path;
        }
        $src = @imagecreatefrompng($path);
        if (!$src) {
            return $path;
        }
        $ratio = $maxpx / max($info[0], $info[1]);
        $w = max(1, (int)round($info[0] * $ratio));
        $h = max(1, (int)round($info[1] * $ratio));
        $dst = imagecreatetruecolor($w, $h);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
        $out = make_request_directory() . '/' . basename($path);
        $ok  = imagepng($dst, $out, 9);
        imagedestroy($src);
        imagedestroy($dst);
        return $ok ? $out : $path;
    }

    /**
     * Lays out the workbook.
     *
     * @param array  $data      gradesheet_service::compute_all_grades() result.
     * @param array  $legend    helper::get_rating_legend() rows: [actual, equivalent, adjectival].
     * @param bool   $hasequiv  Whether the legend has an "Equivalent Rating" column.
     * @param array  $logos     ['left' => path|null, 'right' => path|null].
     * @param string $title     Document title property.
     */
    public static function render(array $data, array $legend, bool $hasequiv, array $logos, string $title): Spreadsheet {
        $rows  = $data['rows'];
        $pages = empty($rows) ? [[]] : array_chunk($rows, pdf_builder::ROWS_PER_PAGE);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('ESSU Grade Sheet Plugin')
            ->setTitle($title);
        $spreadsheet->getDefaultStyle()->getFont()->setName(self::FONT)->setSize(10);

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Report of Grades');
        $sheet->setShowGridlines(false);

        foreach (self::COLWIDTHS as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $top       = 1;
        $maxheight = 0;
        $lastpage  = count($pages) - 1;
        $rownum    = 1;
        foreach ($pages as $pageindex => $pagerows) {
            [$next, $height] = self::write_page(
                $sheet, $top, $data, $legend, $hasequiv, $logos, $pagerows, $rownum, $pageindex === $lastpage
            );
            $maxheight = max($maxheight, $height);
            $rownum   += count($pagerows);
            if ($pageindex !== $lastpage) {
                // The break goes after the page's last row.
                $sheet->setBreak('A' . ($next - 1), Worksheet::BREAK_ROW);
            }
            $top = $next;
        }

        self::apply_page_setup($sheet, $top - 1, $maxheight);
        $spreadsheet->setActiveSheetIndex(0);
        return $spreadsheet;
    }

    /**
     * Writes one printed page starting at row $top.
     *
     * @return array{0: int, 1: float} first free row after the page, and the page's height in points
     */
    private static function write_page(Worksheet $sheet, int $top, array $data, array $legend, bool $hasequiv,
            array $logos, array $pagerows, int $firstnum, bool $islast): array {
        $r      = $top;
        $height = 0;
        $row    = function (int $rowno, float $pt) use ($sheet, &$height): void {
            $sheet->getRowDimension($rowno)->setRowHeight($pt);
            $height += $pt;
        };
        $thin = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]]];

        // ── Logo band ──
        for ($i = 0; $i < self::LOGO_ROWS; $i++) {
            $row($r + $i, self::LOGO_ROW_PT);
        }
        self::place_logos($sheet, $r, $logos);
        $r += self::LOGO_ROWS;

        // ── Title block ──
        $row($r, self::H_TITLE);
        self::text($sheet, "A{$r}:G{$r}", 'REPORT OF GRADES', 16, true, Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $r++;
        $row($r, self::H_SEMESTER);
        self::text($sheet, "A{$r}:G{$r}", $data['semester'] . '  SY ' . $data['schoolyear'], 10, false, Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $r++;
        $row($r, self::H_GAP);
        $r++;

        // ── Course info (left) and rating legend (right) side by side ──
        $info = [
            ['Subject and Course No. :', $data['coursenumber'], true],
            ['Descriptive Title :',      $data['descriptive'],  true],
            ['Course and Year :',        $data['courseandyear'], false],
            ['Schedule of Classes :',    $data['schedule'],     false],
            ['Number of Units :',        $data['units'],        false],
        ];
        // A long value wraps over extra rows rather than being cut off by the legend.
        $infoRows = 0;
        foreach ($info as $k => $item) {
            $lines = (int)min(3, max(1, ceil(mb_strlen((string)$item[1]) / self::INFO_CHARS_PER_LINE)));
            $info[$k][3] = $lines;
            $infoRows += $lines;
        }
        $legendRows = count($legend) + 1;
        $region     = max($infoRows, $legendRows);
        for ($i = 0; $i < $region; $i++) {
            $row($r + $i, $legendRows > $infoRows ? self::H_LEGEND + 1 : self::H_INFO);
        }

        $ir = $r;
        foreach ($info as [$label, $value, $bold, $lines]) {
            self::text($sheet, "A{$ir}:B{$ir}", $label, 9, false, Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle("A{$ir}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
            $end = $ir + $lines - 1;
            self::text($sheet, "C{$ir}:D{$end}", (string)$value, 9, $bold, Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle("C{$ir}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
            $ir = $end + 1;
        }

        // Legend: Actual | Equivalent | Adjectival, or Actual | Adjectival when the
        // course has no equivalent ratings (same rule as pdf_builder).
        $header = $hasequiv ? ['Actual Rating', 'Equivalent Rating', 'Adjectival Rating']
                            : ['Actual Rating', null, 'Adjectival Rating'];
        $lr = $r;
        foreach (array_merge([$header], $legend) as $li => $lrow) {
            $isheader = ($li === 0);
            $cells = $hasequiv
                ? ['E' => $lrow[0], 'F' => $lrow[1], 'G' => $lrow[2]]
                : ['E' => $lrow[0], 'F' => $lrow[2]];
            if (!$hasequiv) {
                $sheet->mergeCells("F{$lr}:G{$lr}");
            }
            foreach ($cells as $col => $text) {
                $sheet->setCellValueExplicit("{$col}{$lr}", (string)$text, DataType::TYPE_STRING);
            }
            $range = "E{$lr}:G{$lr}";
            $sheet->getStyle($range)->applyFromArray($thin);
            $sheet->getStyle($range)->getFont()->setSize(7)->setBold($isheader);
            $sheet->getStyle($range)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER)
                ->setShrinkToFit(true);
            if (!$isheader) {
                $adjcell = $hasequiv ? "G{$lr}" : "F{$lr}";
                $sheet->getStyle($adjcell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            }
            $lr++;
        }
        $r += $region;
        $row($r, self::H_GAP);
        $r++;

        // ── Table header ──
        $row($r, self::H_TBLHEAD);
        foreach (['NO.', 'NAME OF STUDENTS', 'STUDENT NO.', 'MIDTERM', 'FINALS', 'AVERAGE', 'REMARKS'] as $i => $h) {
            $sheet->setCellValueExplicit(chr(65 + $i) . $r, $h, DataType::TYPE_STRING);
        }
        $sheet->getStyle("A{$r}:G{$r}")->applyFromArray($thin);
        $sheet->getStyle("A{$r}:G{$r}")->getFont()->setSize(8)->setBold(true);
        $sheet->getStyle("A{$r}:G{$r}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $r++;

        // ── Student rows ──
        foreach ($pagerows as $i => $student) {
            $row($r, self::H_TBLROW);
            $failed  = ($student['remarks'] === 'Failed');
            $remarks = ($student['remarks'] === 'Withdrawn w/ permission') ? 'WP' : $student['remarks'];

            $sheet->setCellValueExplicit("A{$r}", $firstnum + $i, DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit("B{$r}", (string)$student['name'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("C{$r}", (string)$student['idnumber'], DataType::TYPE_STRING);
            self::grade_cell($sheet, "D{$r}", $student['midterm']);
            self::grade_cell($sheet, "E{$r}", $student['finals']);
            self::grade_cell($sheet, "F{$r}", $student['average']);
            $sheet->setCellValueExplicit("G{$r}", (string)$remarks, DataType::TYPE_STRING);

            $range = "A{$r}:G{$r}";
            $sheet->getStyle($range)->applyFromArray($thin);
            $sheet->getStyle($range)->getFont()->setSize(8);
            $sheet->getStyle($range)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
            if ($i % 2 === 0) {
                $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F5F5F5');
            }
            $sheet->getStyle("B{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setShrinkToFit(true);
            if ($failed) {
                // Same as the PDF: only the row number and the remarks turn red.
                $sheet->getStyle("A{$r}")->getFont()->getColor()->setRGB('B40000');
                $sheet->getStyle("G{$r}")->getFont()->getColor()->setRGB('B40000');
            }
            $r++;
        }

        // "Nothing follows" closes the real data, on the last page only.
        if ($islast) {
            $row($r, self::H_TBLROW);
            $sheet->setCellValueExplicit("B{$r}", '***Nothing Follows***', DataType::TYPE_STRING);
            $sheet->getStyle("A{$r}:G{$r}")->applyFromArray($thin);
            $sheet->getStyle("A{$r}:G{$r}")->getFont()->setSize(8);
            $sheet->getStyle("B{$r}")->getFont()->setItalic(true);
            $sheet->getStyle("B{$r}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT)
                ->setVertical(Alignment::VERTICAL_CENTER);
            $r++;
        }
        $row($r, self::H_GAP);
        $r++;

        // ── Signature block (repeated on every page, like the PDF) ──
        $sig = [
            [['Certified True & Correct:', $data['instructor'], 'Instructor'],
             ['Checked:', $data['depthead'], 'Department Head']],
            [['Received:', $data['registrar'], 'Registrar'],
             ['Approved:', $data['collegedean'], 'College Dean']],
        ];
        foreach ($sig as $si => $pair) {
            if ($si > 0) {
                $row($r, 11);
                $r++;
            }
            $row($r, self::H_SIGLINE);
            $row($r + 1, self::H_SIGSPACE);
            $row($r + 2, self::H_SIGLINE);
            $row($r + 3, self::H_SIGLINE - 1);
            foreach ([['A', 'C'], ['D', 'G']] as $side => [$c1, $c2]) {
                [$label, $name, $role] = $pair[$side];
                self::text($sheet, "{$c1}{$r}:{$c2}{$r}", $label, 9, false, Alignment::HORIZONTAL_LEFT, true);
                self::text($sheet, "{$c1}" . ($r + 2) . ":{$c2}" . ($r + 2), (string)$name, 9, true, Alignment::HORIZONTAL_CENTER);
                self::text($sheet, "{$c1}" . ($r + 3) . ":{$c2}" . ($r + 3), $role, 8, false, Alignment::HORIZONTAL_CENTER, true);
            }
            $r += 4;
        }

        return [$r, $height];
    }

    /** Places both logos in the band that starts at row $top. */
    private static function place_logos(Worksheet $sheet, int $top, array $logos): void {
        $font = $sheet->getParent()->getDefaultStyle()->getFont();
        $px   = [];
        $total = 0;
        foreach (self::COLWIDTHS as $col => $width) {
            $px[$col] = SharedDrawing::cellDimensionToPixels($width, $font);
            $total   += $px[$col];
        }

        if (!empty($logos['left'])) {
            $d = new Drawing();
            $d->setName('ESSU');
            $d->setPath($logos['left']);
            $d->setCoordinates('A' . $top);
            $d->setWidth(self::LOGO_LEFT_WIDTH);
            $d->setWorksheet($sheet);
        }
        if (!empty($logos['right'])) {
            // Right edge of the logo lines up with the right edge of the table.
            $x = max(0, $total - self::LOGO_RIGHT_SIZE);
            foreach ($px as $col => $w) {
                if ($x < $w) {
                    break;
                }
                $x -= $w;
            }
            $d = new Drawing();
            $d->setName('Bagong Pilipinas');
            $d->setPath($logos['right']);
            $d->setCoordinates($col . $top);
            $d->setOffsetX((int)$x);
            $d->setHeight(self::LOGO_RIGHT_SIZE);
            $d->setWorksheet($sheet);
        }
    }

    /** Merged, plain-text cell (always written as a string so "=..." is never evaluated). */
    private static function text(Worksheet $sheet, string $range, string $value, int $size, bool $bold,
            string $halign, bool $italic = false): void {
        $first = explode(':', $range)[0];
        if (strpos($range, ':') !== false && $range !== "{$first}:{$first}") {
            $sheet->mergeCells($range);
        }
        $sheet->setCellValueExplicit($first, $value, DataType::TYPE_STRING);
        $style = $sheet->getStyle($range);
        $style->getFont()->setSize($size)->setBold($bold)->setItalic($italic);
        $style->getAlignment()->setHorizontal($halign);
    }

    /**
     * Grade cells keep the exact text the PDF shows: numbers stay numbers (with
     * the same number of decimals), anything else ("-", "INC", ranges) is text.
     */
    private static function grade_cell(Worksheet $sheet, string $cell, $value): void {
        $value = trim((string)$value);
        if ($value !== '' && is_numeric($value)) {
            $dot      = strpos($value, '.');
            $decimals = ($dot === false) ? 0 : strlen($value) - $dot - 1;
            $sheet->setCellValueExplicit($cell, (float)$value, DataType::TYPE_NUMERIC);
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($decimals > 0 ? '0.' . str_repeat('0', $decimals) : '0');
        } else {
            $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
        }
    }

    /** A4 portrait with the same margins on every page; scaled down only if a page would overflow. */
    private static function apply_page_setup(Worksheet $sheet, int $lastrow, float $maxheight): void {
        $setup = $sheet->getPageSetup();
        $setup->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
        $setup->setPaperSize(PageSetup::PAPERSIZE_A4);
        $setup->setPrintArea("A1:G{$lastrow}");
        $sheet->getPageMargins()
            ->setTop(self::MARGIN_TOP_IN)
            ->setBottom(self::MARGIN_BOTTOM_IN)
            ->setLeft(self::MARGIN_SIDE_IN)
            ->setRight(self::MARGIN_SIDE_IN)
            ->setHeader(0.2)
            ->setFooter(0.3);
        $setup->setHorizontalCentered(true);

        // Manual page breaks are ignored by Excel under "Fit to", so use a fixed
        // scale. 100% unless a very long rating legend makes a page taller than
        // the sheet of paper, in which case shrink everything just enough.
        $printable = self::PAGE_HEIGHT_PT - 72 * (self::MARGIN_TOP_IN + self::MARGIN_BOTTOM_IN);
        $scale     = (int)floor(min(100, 98 * $printable / max(1, $maxheight)));
        $setup->setScale(max(10, $scale));

        // Same footer as the PDF: form number and effectivity date on the left,
        // "Page X of Y" on the right.
        $font = '&"' . self::FONT . ',Regular"&7';
        $sheet->getHeaderFooter()->setOddFooter(
            '&L' . $font . 'ESSU-ACAD-712.b  |  Version 5' . "\n" . 'Effectivity Date: March 15, 2024'
            . '&R' . $font . 'Page &P of &N'
        );
    }
}
