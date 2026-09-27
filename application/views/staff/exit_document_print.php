<?php defined('BASEPATH') or exit('No direct script access allowed');

// Staff HR Advanced Capability Programme — Phase 4 (Exit Documents),
// 2026-08-18. Standalone print view, mirrors sis/tc_print.php's own
// shape (no header/footer chrome, browser print-to-PDF) — the closest
// existing precedent for a school-issued exit document in this codebase.
// Uses ONLY verified employment facts already on the staff record — no
// pay-related figures of any kind (Phase 5's dues/payout aggregation is
// explicitly a separate, later concern, out of scope for this template).

$st = $staff;
$sp = $school_profile;
$type = $doc_type;

$schoolName  = $sp['school_name'] ?? $school_name ?? 'School';
$schoolAddr  = $sp['address'] ?? '';
$schoolPhone = $sp['phone'] ?? '';
$schoolLogo  = $sp['logo'] ?? '';

$staffName   = $st['Name'] ?? $st['name'] ?? '';
$staffId     = $st['staffId'] ?? $st['User ID'] ?? '';
$position    = $st['position'] ?? $st['Position'] ?? '';
$department  = $st['department'] ?? $st['Department'] ?? '';
$joinDateRaw = $st['joinDate'] ?? $st['dateOfJoining'] ?? $st['Date Of Joining'] ?? $st['Joining Date'] ?? '';
$exitDateRaw = $st['exitDate'] ?? $st['Resignation Date'] ?? $st['leaveDate'] ?? '';

function _exitDocFmtDate($raw) {
    if (empty($raw)) return '_______________';
    $ts = strtotime((string) $raw);
    return $ts ? date('jS F, Y', $ts) : htmlspecialchars((string) $raw, ENT_QUOTES, 'UTF-8');
}
$joinDateFmt = _exitDocFmtDate($joinDateRaw);
$exitDateFmt = _exitDocFmtDate($exitDateRaw);
$todayFmt    = date('jS F, Y');

$titles = [
    'relieving'  => 'Relieving Letter',
    'experience' => 'Experience Certificate',
    'service'    => 'Service Certificate',
];
$title = $titles[$type] ?? 'Relieving Letter';

$bodies = [
    'relieving' =>
        'This is to certify that <strong>' . htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') . '</strong>'
        . (!empty($staffId) ? ' (Staff ID: ' . htmlspecialchars($staffId, ENT_QUOTES, 'UTF-8') . ')' : '')
        . ' was employed with us as <strong>' . htmlspecialchars($position ?: '[Position]', ENT_QUOTES, 'UTF-8') . '</strong>'
        . (!empty($department) ? ' in the <strong>' . htmlspecialchars($department, ENT_QUOTES, 'UTF-8') . '</strong> department' : '')
        . ' from <strong>' . $joinDateFmt . '</strong> to <strong>' . $exitDateFmt . '</strong>.'
        . '<br><br>'
        . 'We hereby relieve ' . htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') . ' of all duties and responsibilities '
        . 'with effect from the date mentioned above. All dues, if any, have been settled as per the institution\'s '
        . 'clearance process.'
        . '<br><br>'
        . 'We wish ' . htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') . ' success in future endeavors.',
    'experience' =>
        'This is to certify that <strong>' . htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') . '</strong>'
        . (!empty($staffId) ? ' (Staff ID: ' . htmlspecialchars($staffId, ENT_QUOTES, 'UTF-8') . ')' : '')
        . ' worked with us as <strong>' . htmlspecialchars($position ?: '[Position]', ENT_QUOTES, 'UTF-8') . '</strong>'
        . (!empty($department) ? ' in the <strong>' . htmlspecialchars($department, ENT_QUOTES, 'UTF-8') . '</strong> department' : '')
        . ' from <strong>' . $joinDateFmt . '</strong> to <strong>' . $exitDateFmt . '</strong>.'
        . '<br><br>'
        . 'During the tenure with us, we found ' . htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') . ' to be sincere, '
        . 'hardworking, and professional in conduct.'
        . '<br><br>'
        . 'We wish ' . htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') . ' all success in future endeavors.',
    'service' =>
        'This is to certify that <strong>' . htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') . '</strong>'
        . (!empty($staffId) ? ' (Staff ID: ' . htmlspecialchars($staffId, ENT_QUOTES, 'UTF-8') . ')' : '')
        . ' rendered service to this institution as <strong>' . htmlspecialchars($position ?: '[Position]', ENT_QUOTES, 'UTF-8') . '</strong>'
        . (!empty($department) ? ' in the <strong>' . htmlspecialchars($department, ENT_QUOTES, 'UTF-8') . '</strong> department' : '')
        . ' from <strong>' . $joinDateFmt . '</strong> to <strong>' . $exitDateFmt . '</strong>.'
        . '<br><br>'
        . 'This certificate is issued upon request for record purposes.',
];
$body = $bodies[$type] ?? $bodies['relieving'];
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: 'Times New Roman', Times, serif; background:#e8e8e8; color:#000; font-size:12pt; line-height:1.6; }
.doc-page { width:210mm; min-height:150mm; margin:10mm auto; padding:14mm 18mm; background:#fff; border:2px solid #000; box-shadow:0 4px 20px rgba(0,0,0,.15); position:relative; }
@media print {
    body { background:#fff; }
    .no-print { display:none !important; }
    .doc-page { margin:0; padding:12mm 16mm; border:2px solid #000; box-shadow:none; width:100%; min-height:auto; }
    @page { size:A4; margin:4mm; }
}
.no-print { position:fixed; top:20px; right:20px; }
.btn-print { background:#BC5A3C; color:#fff; border:none; padding:10px 20px; border-radius:8px; font-size:14px; cursor:pointer; }
.doc-header { text-align:center; border-bottom:3px double #000; padding-bottom:10px; margin-bottom:24px; }
.doc-header img { max-height:60px; margin-bottom:6px; }
.doc-header .school-name { font-size:20pt; font-weight:700; letter-spacing:.5px; }
.doc-header .school-addr { font-size:10.5pt; color:#333; margin-top:2px; }
.doc-title { text-align:center; font-size:15pt; font-weight:700; text-decoration:underline; margin:18px 0 26px; text-transform:uppercase; letter-spacing:1px; }
.doc-date { text-align:right; font-size:11pt; margin-bottom:20px; }
.doc-body { font-size:12pt; text-align:justify; }
.doc-sign { margin-top:60px; display:flex; justify-content:flex-end; }
.doc-sign .box { text-align:center; }
.doc-sign .line { border-top:1px solid #000; width:220px; margin-top:50px; padding-top:6px; font-size:10.5pt; }
</style>
</head>
<body>
<div class="no-print">
    <button class="btn-print" onclick="window.print()">&#9113; Print</button>
</div>
<div class="doc-page">
    <div class="doc-header">
        <?php if (!empty($schoolLogo)): ?><img src="<?= htmlspecialchars($schoolLogo, ENT_QUOTES, 'UTF-8') ?>" alt=""><?php endif; ?>
        <div class="school-name"><?= htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8') ?></div>
        <?php if (!empty($schoolAddr)): ?><div class="school-addr"><?= htmlspecialchars($schoolAddr, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if (!empty($schoolPhone)): ?><div class="school-addr">Ph: <?= htmlspecialchars($schoolPhone, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    </div>

    <div class="doc-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="doc-date">Date: <?= $todayFmt ?></div>

    <div class="doc-body">
        <p><?= $body ?></p>
    </div>

    <div class="doc-sign">
        <div class="box">
            <div class="line">Authorized Signatory<br>(<?= htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8') ?>)</div>
        </div>
    </div>
</div>
</body>
</html>
