<?php
/**
 * OPTION 4 — PhpSpreadsheet  (Fees: defaulters/collection register · SIS/HR: student/staff exports)
 * STATUS: RUNS TODAY — phpoffice/phpspreadsheet ^2.1 is already installed and already used in
 *         Accounting.php / Hr.php / Sis.php / Staff.php. You are NOT missing Excel — just extend it to Fees.
 *
 *   php samples/reporting/02_phpspreadsheet_fee_defaulters.php   →   out/fee_defaulters.xlsx
 *
 * Best for: anything the office needs to sort/filter/pivot in Excel — defaulters, collection,
 * attendance registers, payroll sheets, admission registers. Styled, auto-filter, frozen header,
 * totals. (For CSV: IOFactory::createWriter($ss,'Csv').)
 */
require __DIR__ . '/_bootstrap.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

$ss = new Spreadsheet();
$sheet = $ss->getActiveSheet();
$sheet->setTitle('Fee Defaulters');

$accent = '0F766E';
// Title band
$sheet->setCellValue('A1', $SCHOOL['name'] . ' — Fee Defaulters (' . $SCHOOL['session'] . ')');
$sheet->mergeCells('A1:F1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB($accent);
$sheet->getRowDimension(1)->setRowHeight(22);

// Header row
$head = ['Student ID', 'Name', 'Class', 'Phone', 'Months Due', 'Amount Due (₹)'];
$sheet->fromArray($head, null, 'A3');
$sheet->getStyle('A3:F3')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$sheet->getStyle('A3:F3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($accent);
$sheet->getStyle('A3:F3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Data
$row = 4;
foreach ($DEFAULTERS as $d) {
    $sheet->fromArray([$d['id'], $d['name'], $d['class'], $d['phone'], $d['months'], $d['due']], null, "A{$row}");
    $sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
    if ($row % 2 === 0) {
        $sheet->getStyle("A{$row}:F{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F0FDFA');
    }
    $row++;
}
// Total
$sheet->setCellValue("E{$row}", 'TOTAL');
$sheet->setCellValue("F{$row}", '=SUM(F4:F' . ($row - 1) . ')');
$sheet->getStyle("E{$row}:F{$row}")->getFont()->setBold(true)->getColor()->setRGB($accent);
$sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode('#,##0.00');

// Polish: borders, auto-filter, frozen header, column widths
$sheet->getStyle("A3:F" . ($row))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E5E7EB');
$sheet->setAutoFilter('A3:F' . ($row - 1));
$sheet->freezePane('A4');
foreach (['A' => 12, 'B' => 22, 'C' => 8, 'D' => 14, 'E' => 12, 'F' => 16] as $col => $w) {
    $sheet->getColumnDimension($col)->setWidth($w);
}

IOFactory::createWriter($ss, 'Xlsx')->save(out_path('fee_defaulters.xlsx'));
echo "OK  →  samples/reporting/out/fee_defaulters.xlsx\n";

/* In a controller you'd stream it:
     header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
     header('Content-Disposition: attachment; filename="fee_defaulters.xlsx"');
     IOFactory::createWriter($ss,'Xlsx')->save('php://output'); exit;
*/
