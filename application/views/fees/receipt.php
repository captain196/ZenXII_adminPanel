<?php defined('BASEPATH') or exit('No direct script access allowed');

$schoolName    = $school_name    ?? 'School';
$schoolAddress = $school_address ?? '';
$schoolPhone   = $school_phone   ?? '';
$schoolLogo    = $school_logo    ?? '';
$sessionYear   = $session_year   ?? '';

$receiptNo     = $receipt_no      ?? '';
$receiptDate   = $receipt_date    ?? '';
$studentName   = $student_name    ?? '';
$fatherName    = $father_name     ?? '';
$uid           = $user_id         ?? '';
$classDisp     = $class_display   ?? '';
$sectionDisp   = $section_display ?? '';

$amount        = (float)($amount   ?? 0);
$discount      = (float)($discount ?? 0);
$fine          = (float)($fine     ?? 0);
$netTotal      = (float)($net_total ?? 0);
$paymentMode   = $payment_mode    ?? 'N/A';
$reference     = $reference       ?? '';

function fmtAmt($v) {
    return number_format((float)$v, 2);
}

function amountInWords($num) {
    $num = (int) round((float) $num);
    if ($num === 0) return 'zero';
    $ones  = ['','one','two','three','four','five','six','seven','eight','nine','ten',
              'eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen',
              'eighteen','nineteen'];
    $tens  = ['','','twenty','thirty','forty','fifty','sixty','seventy','eighty','ninety'];

    $parts = [];
    if ($num >= 10000000) { $parts[] = amountInWords((int)($num / 10000000)) . ' crore'; $num %= 10000000; }
    if ($num >= 100000)   { $parts[] = amountInWords((int)($num / 100000)) . ' lakh'; $num %= 100000; }
    if ($num >= 1000)     { $parts[] = amountInWords((int)($num / 1000)) . ' thousand'; $num %= 1000; }
    if ($num >= 100)      { $parts[] = $ones[(int)($num / 100)] . ' hundred'; $num %= 100; }
    if ($num >= 20)       { $w = $tens[(int)($num / 10)]; if ($num % 10) $w .= '-' . $ones[$num % 10]; $parts[] = $w; }
    elseif ($num > 0)     { $parts[] = $ones[$num]; }

    return implode(' ', $parts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fee Receipt — <?= htmlspecialchars($receiptNo) ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: 'Segoe UI', Arial, sans-serif; background:#f5f5f5; color:#1a1a1a; font-size:14px; }

.receipt-page {
    width:210mm; max-width:100%; margin:20px auto; background:#fff;
    border:1px solid #d0d0d0; border-radius:4px; overflow:hidden;
}

/* Print */
@media print {
    body { background:#fff; }
    .no-print { display:none !important; }
    .receipt-page { margin:0; border:none; border-radius:0; width:100%; box-shadow:none; }
    @page { size:A4; margin:10mm 12mm; }
}

/* Action bar */
.no-print {
    position:fixed; top:16px; right:16px; display:flex; gap:10px; z-index:99;
}
.no-print button {
    padding:10px 22px; border:none; border-radius:6px; cursor:pointer;
    font-size:14px; font-family:inherit; font-weight:600;
}
.btn-print { background:#0f766e; color:#fff; }
.btn-print:hover { background:#0d6b63; }
.btn-close { background:#e5e7eb; color:#374151; }
.btn-close:hover { background:#d1d5db; }

/* Header */
.receipt-header {
    display:flex; align-items:center; gap:16px; padding:24px 32px 18px;
    border-bottom:3px solid #0f766e;
}
.receipt-logo { width:64px; height:64px; object-fit:contain; flex-shrink:0; }
.receipt-school { flex:1; text-align:center; }
.receipt-school h1 {
    font-size:20px; font-weight:800; text-transform:uppercase;
    letter-spacing:.5px; color:#0f766e; margin-bottom:2px;
}
.receipt-school p { font-size:12px; color:#6b7280; }

/* Title band */
.receipt-title-band {
    background:#f0fdfa; padding:10px 32px; display:flex; justify-content:space-between;
    align-items:center; border-bottom:1px solid #e5e7eb;
}
.receipt-title-band h2 {
    font-size:15px; font-weight:700; text-transform:uppercase; letter-spacing:1.5px; color:#0f766e;
}
.receipt-meta { text-align:right; font-size:13px; color:#374151; }
.receipt-meta strong { color:#0f766e; }

/* Body */
.receipt-body { padding:24px 32px; }

/* Info grid */
.info-grid {
    display:grid; grid-template-columns:1fr 1fr; gap:10px 32px;
    margin-bottom:24px; font-size:13.5px;
}
.info-row { display:flex; gap:8px; }
.info-row .lbl { color:#6b7280; min-width:110px; flex-shrink:0; }
.info-row .val { font-weight:600; color:#111827; }

/* Table */
.fee-table { width:100%; border-collapse:collapse; margin-bottom:20px; font-size:13.5px; }
.fee-table th {
    background:#f0fdfa; color:#0f766e; font-weight:700; padding:10px 14px;
    text-align:left; border:1px solid #d1d5db; font-size:12px; text-transform:uppercase;
    letter-spacing:.4px;
}
.fee-table td {
    padding:10px 14px; border:1px solid #e5e7eb; color:#374151;
}
.fee-table td.amt { text-align:right; font-family:'Courier New', monospace; font-weight:600; }
.fee-table tfoot td {
    font-weight:700; background:#f9fafb; color:#111827;
}
.fee-table tfoot .total-row td {
    background:#f0fdfa; color:#0f766e; font-size:15px; border-top:2px solid #0f766e;
}

/* Payment mode */
.payment-info {
    display:flex; gap:32px; margin-bottom:24px; padding:12px 16px;
    background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; font-size:13.5px;
}
.payment-info .lbl { color:#6b7280; }
.payment-info .val { font-weight:600; color:#111827; margin-left:6px; }

/* Amount in words */
.amount-words {
    padding:10px 16px; background:#f0fdfa; border:1px solid #ccfbf1; border-radius:6px;
    font-size:13px; color:#0f766e; font-weight:600; margin-bottom:28px;
}

/* Footer */
.receipt-footer {
    display:flex; justify-content:space-between; align-items:flex-end;
    padding-top:20px; border-top:1px dashed #d1d5db; margin-top:8px;
}
.sig-box { text-align:center; width:160px; }
.sig-line {
    border-top:1px solid #374151; padding-top:8px; margin-top:48px;
    font-size:12px; font-weight:600; color:#374151;
}

.system-note {
    text-align:center; color:#9ca3af; font-size:11px; padding:16px 32px 20px;
    border-top:1px solid #f3f4f6;
}
</style>
</head>
<body>

<div class="no-print">
    <button class="btn-print" onclick="window.print()">&#9113; Print</button>
    <button class="btn-close" onclick="window.close()">Close</button>
</div>

<div class="receipt-page">

    <!-- Header -->
    <div class="receipt-header">
        <?php if ($schoolLogo): ?>
        <img class="receipt-logo" src="<?= htmlspecialchars($schoolLogo) ?>" alt="Logo">
        <?php endif; ?>
        <div class="receipt-school">
            <h1><?= htmlspecialchars($schoolName) ?></h1>
            <?php if ($schoolAddress): ?><p><?= htmlspecialchars($schoolAddress) ?></p><?php endif; ?>
            <?php if ($schoolPhone): ?><p>Phone: <?= htmlspecialchars($schoolPhone) ?></p><?php endif; ?>
        </div>
        <?php if ($schoolLogo): ?>
        <img class="receipt-logo" src="<?= htmlspecialchars($schoolLogo) ?>" alt="" style="visibility:hidden;">
        <?php endif; ?>
    </div>

    <!-- Title band -->
    <div class="receipt-title-band">
        <h2>Fee Receipt</h2>
        <div class="receipt-meta">
            <div>Receipt No: <strong>F<?= htmlspecialchars($receiptNo) ?></strong></div>
            <div>Date: <strong><?= htmlspecialchars($receiptDate) ?></strong></div>
            <div>Session: <strong><?= htmlspecialchars($sessionYear) ?></strong></div>
        </div>
    </div>

    <!-- Body -->
    <div class="receipt-body">

        <!-- Student info -->
        <div class="info-grid">
            <div class="info-row">
                <span class="lbl">Student Name</span>
                <span class="val"><?= htmlspecialchars($studentName) ?></span>
            </div>
            <div class="info-row">
                <span class="lbl">Admission No</span>
                <span class="val"><?= htmlspecialchars($uid) ?></span>
            </div>
            <div class="info-row">
                <span class="lbl">Father's Name</span>
                <span class="val"><?= htmlspecialchars($fatherName) ?></span>
            </div>
            <div class="info-row">
                <span class="lbl">Class / Section</span>
                <span class="val"><?= htmlspecialchars($classDisp) ?> / <?= htmlspecialchars($sectionDisp) ?></span>
            </div>
        </div>

        <!-- Fee table -->
        <table class="fee-table">
            <thead>
                <tr>
                    <th style="width:40px;">#</th>
                    <th>Fee Head</th>
                    <th style="width:140px;text-align:right;">Amount (&#8377;)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>1</td>
                    <td>School Fees</td>
                    <td class="amt"><?= fmtAmt($amount) ?></td>
                </tr>
                <?php if ($discount > 0): ?>
                <tr>
                    <td>2</td>
                    <td>Discount</td>
                    <td class="amt" style="color:#dc2626;">- <?= fmtAmt($discount) ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($fine > 0): ?>
                <tr>
                    <td><?= $discount > 0 ? 3 : 2 ?></td>
                    <td>Fine</td>
                    <td class="amt"><?= fmtAmt($fine) ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td colspan="2" style="text-align:right;">Net Amount Paid</td>
                    <td class="amt">&#8377; <?= fmtAmt($netTotal) ?></td>
                </tr>
            </tfoot>
        </table>

        <!-- Payment info -->
        <div class="payment-info">
            <div><span class="lbl">Payment Mode:</span><span class="val"><?= htmlspecialchars($paymentMode) ?></span></div>
            <?php if ($reference && $reference !== 'Fees Submitted'): ?>
            <div><span class="lbl">Reference:</span><span class="val"><?= htmlspecialchars($reference) ?></span></div>
            <?php endif; ?>
        </div>

        <!-- Amount in words -->
        <div class="amount-words">
            Amount Paid: &#8377; <?= fmtAmt($netTotal) ?> (Rupees <?= htmlspecialchars(ucfirst(amountInWords($netTotal))) ?> Only)
        </div>

        <!-- Signatures -->
        <div class="receipt-footer">
            <div class="sig-box">
                <div class="sig-line">Parent / Guardian</div>
            </div>
            <div class="sig-box">
                <div class="sig-line">Cashier</div>
            </div>
            <div class="sig-box">
                <div class="sig-line">Principal / Head</div>
            </div>
        </div>

    </div>

    <div class="system-note">
        This is a computer-generated receipt and does not require a physical signature.
    </div>

</div>

</body>
</html>
