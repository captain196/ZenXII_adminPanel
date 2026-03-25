<?php defined('BASEPATH') or exit('No direct script access allowed');

$s   = $student;
$tc  = $tc;
$sp  = $school_profile;

// School details (dynamic - changes per school)
$schoolName   = $sp['school_name'] ?? $school_name ?? 'School';
$schoolAddr   = $sp['address'] ?? '';
$schoolPhone  = $sp['phone'] ?? '';
$schoolLogo   = $sp['logo'] ?? '';
$schoolAffNo  = $sp['affiliation_no'] ?? $sp['aff_no'] ?? '';
$schoolBoard  = $sp['board'] ?? 'C.B.S.E';
$schoolCode   = $sp['school_code'] ?? '';

// Student details
$studentName = $s['Name'] ?? '';
$fatherName  = $s['Father Name'] ?? $s['Guardian Name'] ?? '';
$motherName  = $s['Mother Name'] ?? '';
$dob         = $s['DOB'] ?? '';
$classOrd    = $s['Class'] ?? '';
$section     = $s['Section'] ?? '';
$rollNo      = $s['Roll No'] ?? '';
$admDate     = $s['Admission Date'] ?? '';
$rawAddr     = $s['Address'] ?? '';
$address     = is_array($rawAddr)
    ? implode(', ', array_filter([
        $rawAddr['Street'] ?? '',
        $rawAddr['City'] ?? '',
        $rawAddr['State'] ?? '',
        $rawAddr['PostalCode'] ?? '',
    ]))
    : (string) $rawAddr;
$gender      = $s['Gender'] ?? '';
$nationality = $s['Nationality'] ?? 'Indian';
$category    = $s['Category'] ?? $s['Caste'] ?? '';
$religion    = $s['Religion'] ?? '';
$admClass    = $s['Admission Class'] ?? $s['Adm Class'] ?? '';
$pen         = $s['PEN'] ?? $s['Pen No'] ?? '';

// TC details
$tcNo        = $tc['tc_no'] ?? '';
$serialNo    = $tc['serial_no'] ?? $tcNo;
$issuedDate  = $tc['issued_date'] ?? date('Y-m-d');
$issuedBy    = $tc['issued_by'] ?? '';
$reason      = $tc['reason'] ?? '';
$dest        = $tc['destination'] ?? '';
$appDate     = $tc['application_date'] ?? '';
$conduct     = $tc['conduct'] ?? 'Good';
$boardExam   = $tc['board_exam'] ?? '';
$failedInfo  = $tc['failed_info'] ?? '';
$subjects    = $tc['subjects'] ?? $s['Subjects'] ?? '';
$promotion   = $tc['promotion'] ?? '';
$promClass   = $tc['promotion_class'] ?? '';
$duesPaidTo  = $tc['dues_paid_upto'] ?? '';
$concession  = $tc['fee_concession'] ?? 'No';
$workingDays = $tc['total_working_days'] ?? '';
$daysPresent = $tc['days_present'] ?? '';
$ncc         = $tc['ncc_scout'] ?? 'No';
$games       = $tc['games_extra'] ?? '';
$remarks     = $tc['remarks'] ?? '';
$checkedBy   = $tc['checked_by'] ?? '';

// Parse subjects into array
$subjectList = [];
if (is_array($subjects)) {
    $subjectList = array_values($subjects);
} elseif (is_string($subjects) && !empty($subjects)) {
    $subjectList = array_map('trim', preg_split('/[,;]/', $subjects));
}

// Helper: convert DOB to words
function dobInWords($dob) {
    if (empty($dob)) return '';
    $ts = strtotime($dob);
    if (!$ts) return '';
    $day   = date('j', $ts);
    $month = date('F', $ts);
    $year  = date('Y', $ts);

    $ones  = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
              'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
              'Seventeen', 'Eighteen', 'Nineteen'];
    // FIXED: was missing Forty–Ninety → crash for DOB years like 1945, 1998, etc.
    $tens  = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    $dayWord = ($day < 20) ? $ones[$day] : $tens[intval($day / 10)] . ' ' . $ones[$day % 10];

    $yearFirst = intval(substr($year, 0, 2));
    $yearLast  = intval(substr($year, 2, 2));
    $firstW = ($yearFirst < 20) ? $ones[$yearFirst] : $tens[intval($yearFirst / 10)] . ' ' . $ones[$yearFirst % 10];
    $lastW  = ($yearLast < 20) ? $ones[$yearLast] : $tens[intval($yearLast / 10)] . ' ' . $ones[$yearLast % 10];

    return trim($dayWord) . ' ' . $month . ' ' . trim($firstW) . ' ' . trim($lastW);
}

// Convert class number to words
function classInWords($cls) {
    $map = [
        '1' => 'One', '1st' => 'One', 'I' => 'One',
        '2' => 'Two', '2nd' => 'Two', 'II' => 'Two',
        '3' => 'Three', '3rd' => 'Three', 'III' => 'Three',
        '4' => 'Four', '4th' => 'Four', 'IV' => 'Four',
        '5' => 'Five', '5th' => 'Five', 'V' => 'Five',
        '6' => 'Six', '6th' => 'Six', 'VI' => 'Six',
        '7' => 'Seven', '7th' => 'Seven', 'VII' => 'Seven',
        '8' => 'Eight', '8th' => 'Eight', 'VIII' => 'Eight',
        '9' => 'Nine', '9th' => 'Nine', 'IX' => 'Nine',
        '10' => 'Ten', '10th' => 'Ten', 'X' => 'Ten',
        '11' => 'Eleven', '11th' => 'Eleven', 'XI' => 'Eleven',
        '12' => 'Twelve', '12th' => 'Twelve', 'XII' => 'Twelve',
    ];
    $c = trim($cls);
    return $map[$c] ?? $c;
}

$dobWords    = dobInWords($dob);
$classWords  = classInWords($classOrd);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Transfer Certificate — <?= htmlspecialchars($tcNo) ?></title>
<style>
/* ── Reset ── */
* { margin:0; padding:0; box-sizing:border-box; }
body {
    font-family: 'Times New Roman', Times, serif;
    background: #e8e8e8;
    color: #000;
    font-size: 12pt;
    line-height: 1.4;
}

/* ── A4 Page Container ── */
.tc-page {
    width: 210mm;
    min-height: 297mm;
    margin: 10mm auto;
    padding: 10mm 14mm 8mm;
    background: #fff;
    border: 2px solid #000;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    position: relative;
}

@media print {
    body { background: #fff; }
    .no-print { display: none !important; }
    .tc-page {
        margin: 0;
        padding: 8mm 12mm 6mm;
        border: 2px solid #000;
        box-shadow: none;
        width: 100%;
        min-height: auto;
    }
    @page { size: A4; margin: 4mm; }
}

/* ── Print / Close Buttons ── */
.no-print {
    position: fixed; top: 20px; right: 20px;
    display: flex; gap: 10px; z-index: 99;
}
.no-print button {
    padding: 8px 20px; border: none; border-radius: 5px;
    cursor: pointer; font-size: 13px; font-family: sans-serif;
    font-weight: 600;
}
.btn-print { background: #0f766e; color: #fff; }
.btn-print:hover { background: #0d6b63; }
.btn-close { background: #eee; color: #333; }
.btn-close:hover { background: #ddd; }

/* ── School Header ── */
.school-hdr {
    display: flex;
    align-items: center;
    gap: 12px;
    padding-bottom: 6px;
    text-align: center;
}
.school-logo {
    width: 72px; height: 72px;
    object-fit: contain;
    flex-shrink: 0;
}
.logo-placeholder {
    width: 72px; height: 72px;
    border: 1px dashed #999;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 8pt; color: #999; flex-shrink: 0;
    text-align: center; line-height: 1.2;
}
.school-info {
    flex: 1;
    text-align: center;
}
.school-info h1 {
    font-size: 22pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    margin-bottom: 2px;
    line-height: 1.2;
}
.school-info .school-addr {
    font-size: 10pt;
    margin-bottom: 1px;
}
.school-info .school-affil {
    font-size: 10.5pt;
    font-weight: bold;
}
.school-info .school-affil-no {
    font-size: 9pt;
    margin-top: 1px;
}

/* ── TC Title Banner ── */
.tc-banner {
    text-align: center;
    margin: 8px 0 6px;
}
.tc-banner h2 {
    display: inline-block;
    background: #c0392b;
    color: #fff;
    font-size: 13pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 3px;
    padding: 4px 30px;
    border-radius: 2px;
}

/* ── Serial / Code Row ── */
.tc-meta-row {
    display: flex;
    justify-content: space-between;
    font-size: 10pt;
    margin-bottom: 4px;
    line-height: 1.6;
}
.tc-meta-row span { white-space: nowrap; }
.tc-meta-row .dotted {
    border-bottom: 1px dotted #000;
    min-width: 60px;
    display: inline-block;
    text-align: center;
}

/* ── Numbered Fields ── */
.tc-body {
    margin-top: 4px;
}
.tc-row {
    display: flex;
    font-size: 10.5pt;
    line-height: 1.55;
    margin-bottom: 2px;
    align-items: baseline;
}
.tc-row .sno {
    width: 24px;
    flex-shrink: 0;
    text-align: right;
    padding-right: 4px;
    font-weight: normal;
}
.tc-row .field-label {
    flex-shrink: 0;
    min-width: 0;
}
.tc-row .field-dots {
    flex: 1;
    border-bottom: 1px dotted #555;
    margin-left: 4px;
    min-width: 30px;
    padding-bottom: 1px;
    font-weight: normal;
    word-break: break-word;
}
.tc-row .field-val {
    font-weight: normal;
}

/* Multi-line fields */
.tc-row-multi {
    font-size: 10.5pt;
    line-height: 1.55;
    margin-bottom: 2px;
}
.tc-row-multi .sno {
    width: 24px;
    display: inline-block;
    text-align: right;
    padding-right: 4px;
    vertical-align: top;
}
.tc-row-multi .field-content {
    display: inline;
}

/* Subjects grid */
.subjects-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1px 20px;
    margin: 2px 0 2px 28px;
    font-size: 10.5pt;
}
.subj-item {
    display: flex;
    gap: 4px;
    line-height: 1.5;
}
.subj-item .subj-no { flex-shrink: 0; }
.subj-item .subj-dots {
    flex: 1;
    border-bottom: 1px dotted #555;
}

/* DOB / Class split values */
.split-vals {
    display: flex;
    gap: 6px;
    margin-left: 28px;
    font-size: 10.5pt;
    line-height: 1.55;
    margin-bottom: 2px;
}
.split-vals .part-label { flex-shrink: 0; }
.split-vals .part-dots {
    flex: 1;
    border-bottom: 1px dotted #555;
    min-width: 40px;
}

/* Indent continuation */
.tc-indent {
    margin-left: 28px;
    font-size: 10.5pt;
    line-height: 1.55;
    margin-bottom: 2px;
    display: flex;
}
.tc-indent .field-label { flex-shrink: 0; }
.tc-indent .field-dots {
    flex: 1;
    border-bottom: 1px dotted #555;
    margin-left: 4px;
    min-width: 30px;
}

/* ── Signature Section ── */
.sig-section {
    margin-top: 30px;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    font-size: 9.5pt;
}
.sig-box {
    text-align: center;
    width: 160px;
}
.sig-box .sig-space {
    height: 35px;
}
.sig-box .sig-title {
    font-weight: bold;
    font-size: 9.5pt;
    line-height: 1.3;
}
.sig-box .sig-sub {
    font-size: 8pt;
    color: #333;
    line-height: 1.2;
}
.sig-right {
    text-align: right;
}
.sig-right .sig-title { text-align: center; }
</style>
</head>
<body>

<!-- Print / Close buttons -->
<div class="no-print">
    <button class="btn-print" onclick="window.print()">&#9113; Print</button>
    <button class="btn-close" onclick="window.close()">Close</button>
</div>

<div class="tc-page">

    <!-- ═══ SCHOOL HEADER ═══ -->
    <div class="school-hdr">
        <?php if ($schoolLogo): ?>
            <img class="school-logo" src="<?= htmlspecialchars($schoolLogo) ?>" alt="Logo">
        <?php else: ?>
            <div class="logo-placeholder">School<br>Logo</div>
        <?php endif; ?>

        <div class="school-info">
            <h1><?= htmlspecialchars($schoolName) ?></h1>
            <?php if ($schoolAddr): ?>
                <div class="school-addr"><?= htmlspecialchars($schoolAddr) ?></div>
            <?php endif; ?>
            <div class="school-affil">Affiliated to <?= htmlspecialchars($schoolBoard) ?>, New Delhi</div>
            <?php if ($schoolAffNo): ?>
                <div class="school-affil-no">Affl. No: <?= htmlspecialchars($schoolAffNo) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══ TRANSFER CERTIFICATE BANNER ═══ -->
    <div class="tc-banner">
        <h2>Transfer Certificate</h2>
    </div>

    <!-- ═══ SERIAL / CODE ROW ═══ -->
    <div class="tc-meta-row">
        <span>Serial No. <span class="dotted"><?= htmlspecialchars($serialNo) ?></span></span>
        <span>PEN: <span class="dotted"><?= htmlspecialchars($pen) ?></span></span>
        <span>School Code No. <span class="dotted"><?= htmlspecialchars($schoolCode) ?></span></span>
    </div>
    <div class="tc-meta-row">
        <span>Adm No. <span class="dotted"><?= htmlspecialchars($rollNo) ?></span></span>
        <span>School Aff.No. <span class="dotted"><?= htmlspecialchars($schoolAffNo) ?></span></span>
    </div>

    <!-- ═══ 22 NUMBERED FIELDS ═══ -->
    <div class="tc-body">

        <!-- 1. Name of the pupil -->
        <div class="tc-row">
            <span class="sno">1.</span>
            <span class="field-label">Name of the pupil</span>
            <span class="field-dots"><?= htmlspecialchars($studentName) ?></span>
        </div>

        <!-- 2. Father/Guardian's Name -->
        <div class="tc-row">
            <span class="sno">2.</span>
            <span class="field-label">Father/Guardian's Name</span>
            <span class="field-dots"><?= htmlspecialchars($fatherName) ?></span>
        </div>

        <!-- 3. Nationality -->
        <div class="tc-row">
            <span class="sno">3.</span>
            <span class="field-label">Nationality</span>
            <span class="field-dots"><?= htmlspecialchars($nationality) ?></span>
        </div>

        <!-- 4. Whether SC/ST -->
        <div class="tc-row">
            <span class="sno">4.</span>
            <span class="field-label">Whether the candidate belongs to Schedule</span>
        </div>
        <div class="tc-indent">
            <span class="field-label">Caste or Scheduled Tribe</span>
            <span class="field-dots"><?= htmlspecialchars($category) ?></span>
        </div>

        <!-- 5. Date of first admission -->
        <div class="tc-row">
            <span class="sno">5.</span>
            <span class="field-label">Date of first admission in the school with class</span>
            <span class="field-dots"><?= htmlspecialchars($admDate) ?><?= $admClass ? ' - ' . htmlspecialchars($admClass) : '' ?></span>
        </div>

        <!-- 6. Date of Birth -->
        <div class="tc-row">
            <span class="sno">6.</span>
            <span class="field-label">Date of Birth (in Christian Era) according to</span>
        </div>
        <div class="tc-indent" style="margin-bottom:0">
            <span class="field-label">Admission Register</span>
            <span class="field-dots"><?= htmlspecialchars($dob) ?> <small>(in figures)</small></span>
        </div>
        <div class="tc-indent">
            <span class="field-dots"><?= htmlspecialchars($dobWords) ?> <small>(in words)</small></span>
        </div>

        <!-- 7. Class last studied -->
        <div class="tc-row">
            <span class="sno">7.</span>
            <span class="field-label">Class in which the pupil last studied</span>
            <span class="field-dots">(in figures) <strong><?= htmlspecialchars($classOrd) ?></strong> &nbsp; (in words) <strong><?= htmlspecialchars($classWords) ?></strong></span>
        </div>

        <!-- 8. Board exam last taken -->
        <div class="tc-row">
            <span class="sno">8.</span>
            <span class="field-label">School/Board Annual examination last taken</span>
        </div>
        <div class="tc-indent">
            <span class="field-label">with result</span>
            <span class="field-dots"><?= htmlspecialchars($boardExam) ?></span>
        </div>

        <!-- 9. Whether failed -->
        <div class="tc-row">
            <span class="sno">9.</span>
            <span class="field-label">Whether failed, if so once/twice in the same class</span>
            <span class="field-dots"><?= htmlspecialchars($failedInfo) ?></span>
        </div>

        <!-- 10. Subjects studied -->
        <div class="tc-row" style="margin-bottom:0">
            <span class="sno">10.</span>
            <span class="field-label">Subjects studied</span>
        </div>
        <div class="subjects-grid">
            <?php for ($i = 0; $i < 6; $i++): ?>
                <div class="subj-item">
                    <span class="subj-no"><?= ($i + 1) ?>.</span>
                    <span class="subj-dots"><?= htmlspecialchars($subjectList[$i] ?? '') ?></span>
                </div>
            <?php endfor; ?>
        </div>

        <!-- 11. Whether qualified for promotion -->
        <div class="tc-row">
            <span class="sno">11.</span>
            <span class="field-label">Whether qualified for promotion to the higher class</span>
            <span class="field-dots"><?= htmlspecialchars($promotion) ?></span>
        </div>
        <div class="tc-indent">
            <span class="field-label">if so, to which class</span>
            <span class="field-dots">(in fig.) <strong><?= htmlspecialchars($promClass) ?></strong> &nbsp; (in words) <strong><?= htmlspecialchars(classInWords($promClass)) ?></strong></span>
        </div>

        <!-- 12. Month upto which dues paid -->
        <div class="tc-row">
            <span class="sno">12.</span>
            <span class="field-label">Month upto which the (pupil has paid) school</span>
        </div>
        <div class="tc-indent">
            <span class="field-label">dues paid</span>
            <span class="field-dots"><?= htmlspecialchars($duesPaidTo) ?></span>
        </div>

        <!-- 13. Fee concession -->
        <div class="tc-row">
            <span class="sno">13.</span>
            <span class="field-label">Any fee concession availed of ; if so, the</span>
        </div>
        <div class="tc-indent">
            <span class="field-label">nature of such concession</span>
            <span class="field-dots"><?= htmlspecialchars($concession) ?></span>
        </div>

        <!-- 14. Total working days -->
        <div class="tc-row">
            <span class="sno">14.</span>
            <span class="field-label">Total No. of working days</span>
            <span class="field-dots"><?= htmlspecialchars($workingDays) ?></span>
        </div>

        <!-- 15. Total working days present -->
        <div class="tc-row">
            <span class="sno">15.</span>
            <span class="field-label">Total No. of Working days present</span>
            <span class="field-dots"><?= htmlspecialchars($daysPresent) ?></span>
        </div>

        <!-- 16. NCC / Scout -->
        <div class="tc-row">
            <span class="sno">16.</span>
            <span class="field-label">Whether NCC Cadet/Boy scout/Girl Guide</span>
        </div>
        <div class="tc-indent">
            <span class="field-label">(details may be given)</span>
            <span class="field-dots"><?= htmlspecialchars($ncc) ?></span>
        </div>

        <!-- 17. Games / Extra curricular -->
        <div class="tc-row">
            <span class="sno">17.</span>
            <span class="field-label">Games played or extra curricular in which the</span>
        </div>
        <div class="tc-indent">
            <span class="field-label">people usually took part <small>(mention achievement label therein)</small></span>
            <span class="field-dots"><?= htmlspecialchars($games) ?></span>
        </div>

        <!-- 18. General conduct -->
        <div class="tc-row">
            <span class="sno">18.</span>
            <span class="field-label">General conduct</span>
            <span class="field-dots"><?= htmlspecialchars($conduct) ?></span>
        </div>

        <!-- 19. Date of application -->
        <div class="tc-row">
            <span class="sno">19.</span>
            <span class="field-label">Date of application for certificate</span>
            <span class="field-dots"><?= htmlspecialchars($appDate) ?></span>
        </div>

        <!-- 20. Date of issue -->
        <div class="tc-row">
            <span class="sno">20.</span>
            <span class="field-label">Date of issue of certificate</span>
            <span class="field-dots"><?= htmlspecialchars($issuedDate) ?></span>
        </div>

        <!-- 21. Reasons for leaving -->
        <div class="tc-row">
            <span class="sno">21.</span>
            <span class="field-label">Reasons for leaving the school</span>
            <span class="field-dots"><?= htmlspecialchars($reason) ?></span>
        </div>

        <!-- 22. Any other remarks -->
        <div class="tc-row">
            <span class="sno">22.</span>
            <span class="field-label">Any other remarks</span>
            <span class="field-dots"><?= htmlspecialchars($remarks) ?></span>
        </div>

    </div><!-- /.tc-body -->

    <!-- ═══ SIGNATURES ═══ -->
    <div class="sig-section">
        <div class="sig-box">
            <div class="sig-space"></div>
            <div class="sig-title">Signature of<br>Class Teacher</div>
        </div>
        <div class="sig-box">
            <div class="sig-space"></div>
            <div class="sig-title">Checked by</div>
            <div class="sig-sub">(state full name and designation)</div>
            <?php if ($checkedBy): ?>
                <div class="sig-sub" style="margin-top:2px"><?= htmlspecialchars($checkedBy) ?></div>
            <?php endif; ?>
        </div>
        <div class="sig-box sig-right">
            <div class="sig-space"></div>
            <div class="sig-title">Principal</div>
            <div class="sig-sub"><?= htmlspecialchars($schoolName) ?></div>
            <div class="sig-sub"><strong>SEAL</strong></div>
        </div>
    </div>

</div><!-- /.tc-page -->

</body>
</html>
