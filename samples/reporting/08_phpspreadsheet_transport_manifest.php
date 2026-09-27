<?php
/**
 * OPTION 8 (NEW) — Transport Trip Manifest  (Transport / TMS module)
 * STATUS: RUNS TODAY (PhpSpreadsheet, already installed)
 *
 *   php samples/reporting/08_phpspreadsheet_transport_manifest.php   →   out/trip_manifest.xlsx
 *
 * GAP CLOSED: the entire Transport module (12 controllers) has ZERO report/export code —
 * everything is a JSON dashboard. This is one exemplar (a daily trip manifest: stop-by-stop
 * boarding count for a route) showing the same PhpSpreadsheet pattern already proven in
 * Accounting/HR can extend cleanly into Transport with no new library.
 */
require __DIR__ . '/_bootstrap.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

$accent = '0F766E';
$ss = new Spreadsheet();
$sheet = $ss->getActiveSheet();
$sheet->setTitle('Trip Manifest');

$sheet->setCellValue('A1', $SCHOOL['name'] . ' — Transport Trip Manifest');
$sheet->mergeCells('A1:E1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB($accent);
$sheet->getRowDimension(1)->setRowHeight(22);

$sheet->setCellValue('A2', $TRIP_MANIFEST['route'] . '  ·  ' . $TRIP_MANIFEST['date'] . '  ·  ' . $TRIP_MANIFEST['shift']);
$sheet->mergeCells('A2:E2');
$sheet->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('64748B');

$sheet->setCellValue('A4', 'Vehicle'); $sheet->setCellValue('B4', $TRIP_MANIFEST['vehicle']);
$sheet->setCellValue('A5', 'Driver');  $sheet->setCellValue('B5', $TRIP_MANIFEST['driver']);
$sheet->setCellValue('A6', 'Attendant'); $sheet->setCellValue('B6', $TRIP_MANIFEST['attendant']);
$sheet->getStyle('A4:A6')->getFont()->setBold(true);

$head = ['#', 'Stop', 'Scheduled Time', 'Students Assigned', 'Boarded'];
$sheet->fromArray($head, null, 'A8');
$sheet->getStyle('A8:E8')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$sheet->getStyle('A8:E8')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($accent);
$sheet->getStyle('A8:E8')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$row = 9; $totalAssigned = 0; $totalBoarded = 0;
foreach ($TRIP_MANIFEST['stops'] as $s) {
    $sheet->fromArray([$s['seq'], $s['stop'], $s['time'], $s['students'], $s['boarded']], null, "A{$row}");
    $totalAssigned += $s['students']; $totalBoarded += $s['boarded'];
    if ($s['students'] > 0 && $s['boarded'] < $s['students']) {
        $sheet->getStyle("E{$row}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('DC2626'))->setBold(true);
    }
    if ($row % 2 === 0) $sheet->getStyle("A{$row}:E{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F0FDFA');
    $row++;
}
$sheet->setCellValue("C{$row}", 'TOTAL');
$sheet->setCellValue("D{$row}", $totalAssigned);
$sheet->setCellValue("E{$row}", $totalBoarded);
$sheet->getStyle("C{$row}:E{$row}")->getFont()->setBold(true)->getColor()->setRGB($accent);

$sheet->getStyle('A8:E' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E5E7EB');
$sheet->setAutoFilter('A8:E' . ($row - 1));
$sheet->freezePane('A9');
foreach (['A'=>5, 'B'=>22, 'C'=>16, 'D'=>16, 'E'=>10] as $col => $w) $sheet->getColumnDimension($col)->setWidth($w);
$sheet->getStyle('B4:B6')->getAlignment()->setWrapText(true);

IOFactory::createWriter($ss, 'Xlsx')->save(out_path('trip_manifest.xlsx'));
echo "OK  →  samples/reporting/out/trip_manifest.xlsx\n";
