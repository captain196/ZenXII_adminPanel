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

$amount          = (float) ($amount           ?? 0);
$inputAmount     = (float) ($input_amount     ?? $amount);
$allocatedAmount = (float) ($allocated_amount ?? $amount);
$advanceCredit   = (float) ($advance_credit   ?? 0);
$discount        = (float) ($discount         ?? 0);
$fine            = (float) ($fine             ?? 0);
$netTotal        = (float) ($net_total        ?? 0);
$paymentMode     = $payment_mode    ?? 'N/A';
$reference       = $reference       ?? '';
$allocations     = is_array($allocations ?? null) ? $allocations : [];
$allocatedMonths = is_array($allocated_months ?? null) ? $allocated_months : [];
$breakdownLegacy = is_array($breakdown ?? null) ? $breakdown : [];   // per-head fallback
$isPartial       = !empty($is_partial);

function fmtAmt($v) { return number_format((float)$v, 2); }

/** Strip the trailing year token from a period string. */
function rcptPeriodToMonth($period) {
    return trim((string) preg_replace('/\s+\d{4}(-\d{2,4})?$/', '', (string) $period));
}

/** Friendlier label for yearly fee heads on the receipt. */
function rcptHeadLabel($head, $period) {
    $month = rcptPeriodToMonth($period);
    if ($month === 'Yearly Fees') {
        return htmlspecialchars($head) . ' <span class="head-tag">(Annual · One-time)</span>';
    }
    return htmlspecialchars($head);
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

// Subtotal = sum of all allocated rows. Falls back to allocatedAmount.
$subtotal = 0;
foreach ($allocations as $a) { $subtotal += (float) ($a['allocated'] ?? 0); }
if ($subtotal <= 0) $subtotal = $allocatedAmount;

// Months Covered (preserves "Yearly Fees" → "Annual" in display).
$monthsLabels = [];
foreach ($allocatedMonths as $m) {
    $label = ($m === 'Yearly Fees') ? 'Annual' : $m;
    $monthsLabels[] = $label;
}
$monthsLine = empty($monthsLabels) ? '—' : implode(', ', $monthsLabels);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fee Receipt — F<?= htmlspecialchars($receiptNo) ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body {
    font-family: 'Segoe UI', Arial, sans-serif;
    background:#eef1f5;
    color:#1a1a1a;
    font-size:13px;
    line-height:1.45;
}

.receipt-page {
    width:210mm; max-width:100%; margin:24px auto; background:#fff;
    border:1px solid #d0d5dd; border-radius:6px; overflow:hidden;
    box-shadow:0 4px 16px rgba(15,31,58,.06);
}

/* ── Print rules ── */
@media print {
    body { background:#fff; font-size:12px; }
    .no-print { display:none !important; }
    .receipt-page { margin:0; border:none; border-radius:0; box-shadow:none; width:100%; }
    .pay-pill, .head-tag, .partial-banner {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    @page { size:A4; margin:10mm 12mm; }
}

/* ── Floating action bar (off-paper) ── */
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

/* ── Header (school) ── */
.receipt-header {
    display:flex; align-items:center; gap:16px;
    padding:22px 28px 18px;
    border-bottom:3px solid #0f766e;
}
.receipt-logo {
    width:60px; height:60px; object-fit:contain; flex-shrink:0;
}
.receipt-school { flex:1; text-align:center; }
.receipt-school h1 {
    font-size:20px; font-weight:800; text-transform:uppercase;
    letter-spacing:.5px; color:#0f1f3a; margin-bottom:3px;
}
.receipt-school p { font-size:11.5px; color:#475569; }

/* ── Title band ── */
.receipt-title-band {
    background:#f0fdfa; padding:11px 28px;
    display:flex; justify-content:space-between; align-items:center; gap:12px;
    border-bottom:1px solid #d1fae5;
}
.receipt-title-band h2 {
    font-size:14px; font-weight:700; text-transform:uppercase;
    letter-spacing:1.5px; color:#0f766e;
}
.receipt-title-band-right {
    display:flex; align-items:center; gap:14px;
}
.receipt-meta { text-align:right; font-size:12px; color:#475569; }
.receipt-meta strong { color:#0f1f3a; font-weight:700; font-variant-numeric:tabular-nums; }

/* FULL / PARTIAL pill */
.pay-pill {
    display:inline-flex; align-items:center; gap:5px;
    padding:5px 12px; border-radius:14px;
    font-size:11px; font-weight:800; letter-spacing:.4px; text-transform:uppercase;
}
.pay-pill.full    { background:#dcfce7; color:#15803d; border:1px solid #86efac; }
.pay-pill.partial { background:#fef3c7; color:#92400e; border:1px solid #fcd34d; }

/* ── Body ── */
.receipt-body { padding:22px 28px 12px; }

/* Partial banner — only when isPartial */
.partial-banner {
    background:#fef3c7;
    border:1px solid #fcd34d;
    border-left:4px solid #f59e0b;
    padding:10px 14px;
    margin-bottom:18px;
    border-radius:6px;
    font-size:12.5px;
    color:#92400e;
}
.partial-banner strong { color:#78350f; }

/* Student info — clean two-column */
.info-grid {
    display:grid; grid-template-columns:1fr 1fr; gap:8px 32px;
    margin-bottom:18px; font-size:13px;
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:6px;
    padding:14px 18px;
}
.info-row { display:flex; gap:10px; }
.info-row .lbl {
    color:#64748b; min-width:96px; flex-shrink:0;
    font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px;
    padding-top:1px;
}
.info-row .val { font-weight:600; color:#0f1f3a; }

/* Months covered */
.months-covered {
    background:#eff6ff;
    border:1px solid #bfdbfe;
    border-left:4px solid #2563eb;
    padding:9px 14px;
    margin-bottom:14px;
    border-radius:6px;
    font-size:12.5px;
    color:#1e40af;
}
.months-covered strong { color:#1e3a8a; font-weight:700; margin-left:4px; }

/* Breakdown table */
.fee-table {
    width:100%; border-collapse:collapse; margin-bottom:14px;
    font-size:12.5px;
    border:1px solid #d0d5dd;
}
.fee-table th {
    background:#0f1f3a; color:#fff;
    font-weight:700; padding:9px 12px;
    text-align:left;
    font-size:11px; text-transform:uppercase; letter-spacing:.5px;
}
.fee-table th.amt { text-align:right; }
.fee-table td {
    padding:8px 12px;
    border-top:1px solid #e2e8f0;
    color:#1e293b;
    vertical-align:middle;
}
.fee-table td.amt {
    text-align:right;
    font-variant-numeric:tabular-nums;
    font-weight:600;
    color:#0f1f3a;
}
.fee-table tr:nth-child(even) td { background:#fafbfc; }
.fee-table .head-tag {
    display:inline-block;
    background:#ccfbf1;
    color:#0f766e;
    font-size:10px; font-weight:700;
    padding:1px 7px;
    border-radius:10px;
    margin-left:6px;
    letter-spacing:.2px;
}
.fee-table .row-status {
    display:inline-block;
    font-size:10px; font-weight:700;
    padding:1px 7px;
    border-radius:10px;
    margin-left:6px;
    letter-spacing:.2px;
}
.fee-table .row-status.cleared { background:#dcfce7; color:#15803d; }
.fee-table .row-status.partial { background:#fef3c7; color:#92400e; }

/* Totals */
.totals-block {
    margin-left:auto;
    width:60%;
    border:1px solid #d0d5dd;
    border-radius:6px;
    overflow:hidden;
    margin-bottom:14px;
}
.totals-row {
    display:flex; align-items:center; justify-content:space-between;
    padding:7px 14px;
    font-size:12.5px;
    border-top:1px solid #e2e8f0;
}
.totals-row:first-child { border-top:none; }
.totals-row .lbl { color:#475569; font-weight:600; }
.totals-row .val {
    font-variant-numeric:tabular-nums; font-weight:700; color:#0f1f3a;
}
.totals-row.discount .val { color:#16a34a; }
.totals-row.fine .val     { color:#b45309; }
.totals-row.net {
    background:#0f1f3a;
    color:#fff;
    padding:11px 14px;
    border-top:none;
}
.totals-row.net .lbl { color:#cbd5e1; font-size:12px; text-transform:uppercase; letter-spacing:.5px; }
.totals-row.net .val { color:#ffd479; font-size:16px; }

/* Carry-forward line — shown only when advance_credit > 0 (legacy receipts) */
.advance-block {
    margin-bottom:14px;
    background:#f0fdfa;
    border:1px solid #99f6e4;
    border-left:4px solid #0f766e;
    border-radius:6px;
    padding:11px 14px;
    font-size:12.5px;
    color:#134e4a;
}
.advance-block .ad-row {
    display:flex; justify-content:space-between;
    padding:2px 0; font-variant-numeric:tabular-nums;
}
.advance-block .ad-row.split {
    border-top:1px dashed #99f6e4;
    margin-top:5px; padding-top:6px;
    font-weight:700;
    color:#0f766e;
}
.advance-block .ad-row .lbl { color:#0f766e; font-weight:600; }

/* Payment mode strip */
.payment-info {
    display:flex; gap:24px; flex-wrap:wrap;
    margin-bottom:16px; padding:10px 14px;
    background:#f8fafc; border:1px solid #e2e8f0;
    border-radius:6px; font-size:12.5px;
}
.payment-info .lbl {
    color:#64748b; font-size:11px; font-weight:700;
    text-transform:uppercase; letter-spacing:.4px;
    margin-right:6px;
}
.payment-info .val { font-weight:700; color:#0f1f3a; }

/* Amount in words */
.amount-words {
    padding:9px 14px;
    background:#f0fdfa;
    border:1px solid #ccfbf1;
    border-radius:6px;
    font-size:12.5px; color:#0f766e; font-weight:600;
    margin-bottom:18px;
}

/* Footer signatures */
.receipt-footer {
    display:flex; justify-content:space-between; align-items:flex-end;
    padding-top:14px;
    border-top:1px dashed #cbd5e1;
    margin-top:6px;
}
.sig-box { text-align:center; width:160px; }
.sig-line {
    border-top:1px solid #475569;
    padding-top:6px; margin-top:42px;
    font-size:11px; font-weight:700;
    color:#475569; letter-spacing:.3px; text-transform:uppercase;
}

.system-note {
    text-align:center; color:#94a3b8; font-size:10.5px;
    padding:12px 28px 16px;
    border-top:1px solid #f1f5f9;
}

/* Responsive — small screens still readable on phone */
@media (max-width: 640px) {
    .info-grid { grid-template-columns:1fr; gap:6px; }
    .totals-block { width:100%; }
    .receipt-body { padding:18px 16px 8px; }
    .receipt-header { padding:18px 16px 14px; }
    .receipt-title-band { padding:10px 16px; flex-wrap:wrap; }
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
        <div class="receipt-title-band-right">
            <span class="pay-pill <?= $isPartial ? 'partial' : 'full' ?>">
                <?= $isPartial ? 'Partial Payment' : 'Full Payment' ?>
            </span>
            <div class="receipt-meta">
                <div>Receipt: <strong>F<?= htmlspecialchars($receiptNo) ?></strong></div>
                <div>Date: <strong><?= htmlspecialchars($receiptDate) ?></strong></div>
                <div>Session: <strong><?= htmlspecialchars($sessionYear) ?></strong></div>
            </div>
        </div>
    </div>

    <!-- Body -->
    <div class="receipt-body">

        <!-- Partial banner -->
        <?php if ($isPartial): ?>
        <div class="partial-banner">
            <i>&#9888;</i>
            <strong>This is a partial payment.</strong>
            One or more fee heads listed below were partly cleared by this receipt — outstanding balance remains on the demand.
        </div>
        <?php endif; ?>

        <!-- Student info -->
        <div class="info-grid">
            <div class="info-row">
                <span class="lbl">Student</span>
                <span class="val"><?= htmlspecialchars($studentName) ?></span>
            </div>
            <div class="info-row">
                <span class="lbl">Admission</span>
                <span class="val"><?= htmlspecialchars($uid) ?></span>
            </div>
            <div class="info-row">
                <span class="lbl">Father</span>
                <span class="val"><?= htmlspecialchars($fatherName ?: '—') ?></span>
            </div>
            <div class="info-row">
                <span class="lbl">Class / Sec</span>
                <span class="val"><?= htmlspecialchars($classDisp) ?> / <?= htmlspecialchars($sectionDisp) ?></span>
            </div>
        </div>

        <!-- Months covered line -->
        <div class="months-covered">
            <i>&#128197;</i>
            Months Covered: <strong><?= htmlspecialchars($monthsLine) ?></strong>
        </div>

        <!-- Breakdown table — per-month per-head from allocations -->
        <table class="fee-table">
            <thead>
                <tr>
                    <th style="width:38px;">#</th>
                    <th style="width:110px;">Month</th>
                    <th>Fee Head</th>
                    <th class="amt" style="width:130px;">Amount (&#8377;)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($allocations)): ?>
                    <?php $i = 0; foreach ($allocations as $a):
                        $i++;
                        $period = (string) ($a['period']    ?? '');
                        $head   = (string) ($a['fee_head']  ?? '');
                        $amt    = (float)  ($a['allocated'] ?? 0);
                        $stat   = (string) ($a['status']    ?? '');
                        $month  = rcptPeriodToMonth($period);
                        $monthLabel = ($month === 'Yearly Fees') ? 'Annual' : $month;
                    ?>
                    <tr>
                        <td><?= $i ?></td>
                        <td><?= htmlspecialchars($monthLabel) ?></td>
                        <td>
                            <?= rcptHeadLabel($head, $period) ?>
                            <?php if ($stat === 'partial'): ?>
                                <span class="row-status partial">Partial</span>
                            <?php elseif ($stat === 'paid'): ?>
                                <span class="row-status cleared">Cleared</span>
                            <?php endif; ?>
                        </td>
                        <td class="amt"><?= fmtAmt($amt) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php elseif (!empty($breakdownLegacy)): ?>
                    <!-- Legacy fallback (pre-Phase-11 receipts without allocations) -->
                    <?php $i = 0; foreach ($breakdownLegacy as $b):
                        $i++;
                        $head = (string) ($b['head']      ?? '');
                        $amt  = (float)  ($b['amount']    ?? 0);
                        $freq = (string) ($b['frequency'] ?? '');
                    ?>
                    <tr>
                        <td><?= $i ?></td>
                        <td><?= $freq === 'annual' ? 'Annual' : implode(', ', $monthsLabels) ?></td>
                        <td>
                            <?= htmlspecialchars($head) ?>
                            <?php if ($freq === 'annual'): ?>
                                <span class="head-tag">(Annual · One-time)</span>
                            <?php endif; ?>
                        </td>
                        <td class="amt"><?= fmtAmt($amt) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td>1</td>
                        <td colspan="2">School Fees</td>
                        <td class="amt"><?= fmtAmt($amount) ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Totals -->
        <div class="totals-block">
            <div class="totals-row">
                <span class="lbl">Subtotal</span>
                <span class="val">&#8377; <?= fmtAmt($subtotal) ?></span>
            </div>
            <?php if ($discount > 0): ?>
            <div class="totals-row discount">
                <span class="lbl">Discount</span>
                <span class="val">− &#8377; <?= fmtAmt($discount) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($fine > 0): ?>
            <div class="totals-row fine">
                <span class="lbl">Fine</span>
                <span class="val">+ &#8377; <?= fmtAmt($fine) ?></span>
            </div>
            <?php endif; ?>
            <div class="totals-row net">
                <span class="lbl">Net Amount Paid</span>
                <span class="val">&#8377; <?= fmtAmt($netTotal) ?></span>
            </div>
        </div>

        <!-- Carry-forward line — only on historical receipts that had
             unallocated surplus. Overpayment is rejected upstream now so
             new receipts never trigger this block; kept for prior data. -->
        <?php if ($advanceCredit > 0.005): ?>
        <div class="advance-block">
            <div class="ad-row">
                <span class="lbl">Paid by parent</span>
                <span>&#8377; <?= fmtAmt($inputAmount) ?></span>
            </div>
            <div class="ad-row">
                <span class="lbl">Used towards demands</span>
                <span>&#8377; <?= fmtAmt($allocatedAmount) ?></span>
            </div>
            <div class="ad-row split">
                <span>Carry-forward (unallocated)</span>
                <span>&#8377; <?= fmtAmt($advanceCredit) ?></span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Payment mode strip -->
        <div class="payment-info">
            <div><span class="lbl">Mode</span><span class="val"><?= htmlspecialchars($paymentMode) ?></span></div>
            <?php if ($reference && $reference !== 'Fees Submitted'): ?>
            <div><span class="lbl">Ref</span><span class="val"><?= htmlspecialchars($reference) ?></span></div>
            <?php endif; ?>
        </div>

        <!-- Amount in words -->
        <div class="amount-words">
            Amount Paid: &#8377; <?= fmtAmt($netTotal) ?>
            (Rupees <?= htmlspecialchars(ucfirst(amountInWords($netTotal))) ?> Only)
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
        Computer-generated receipt — does not require a physical signature.
        <?php if ($isPartial): ?>
            <br>This receipt represents a partial payment; outstanding dues remain on one or more fee heads listed above.
        <?php endif; ?>
    </div>

</div>

</body>
</html>
