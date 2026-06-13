<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Shared data preparation for all report card templates.
 * Extracts and computes all variables that templates need for rendering.
 * Included by each template at the top via $this->load->view().
 */

// ── Data contract: ensure required variables exist with safe defaults ──
$computed    = isset($computed) && is_array($computed) ? $computed : [];
$templates   = isset($templates) && is_array($templates) ? $templates : [];
$marks       = isset($marks) && is_array($marks) ? $marks : [];
$profile     = isset($profile) && is_array($profile) ? $profile : [];
$exam        = isset($exam) && is_array($exam) ? $exam : [];
$schoolInfo  = isset($schoolInfo) && is_array($schoolInfo) ? $schoolInfo : [];
$schoolName  = isset($schoolName) ? (string)$schoolName : '';
$classKey    = isset($classKey) ? (string)$classKey : '';
$sectionKey  = isset($sectionKey) ? (string)$sectionKey : '';
$sessionYear = isset($sessionYear) ? (string)$sessionYear : '';

// ── Student info ──────────────────────────────────────────────────────
$studentName = $profile['Name']        ?? 'Unknown';
$fatherName  = $profile['Father Name'] ?? '';
$motherName  = $profile['Mother Name'] ?? '';
$dob         = $profile['DOB']         ?? '';
$gender      = $profile['Gender']      ?? '';
$rollNo      = $profile['User Id']     ?? '';

$addrObj = $profile['Address'] ?? [];
if (is_object($addrObj)) $addrObj = (array)$addrObj;
if (is_string($addrObj)) { $address = $addrObj; $addrObj = []; }
elseif (!is_array($addrObj)) { $addrObj = []; }

if (!isset($address)) {
    $street = $addrObj['Street'] ?? '';
    $city   = $addrObj['City'] ?? '';
    $state  = $addrObj['State'] ?? '';
    $postal = $addrObj['PostalCode'] ?? '';

    $addressParts = [];
    if ($street) $addressParts[] = $street;
    if ($city)   $addressParts[] = $city;
    if ($state)  $addressParts[] = $state;
    if ($postal) $addressParts[] = $postal;
    $address = implode(', ', $addressParts);
}

$photoUrl = $profile['Profile Pic'] ?? '';

// ── Class info ────────────────────────────────────────────────────────
$classNameRaw  = ltrim(trim(str_ireplace('Class', '', $classKey)));   // "9th"
$sectionLetter = str_replace('Section ', '', $sectionKey);            // "A"
$gradeLabel    = $classNameRaw . ($sectionLetter ? ' - ' . $sectionLetter : '');

// ── Exam info ─────────────────────────────────────────────────────────
$examName     = $exam['Name']              ?? 'Exam';
$examType     = $exam['Type']              ?? '';
$startDate    = $exam['StartDate']         ?? '';
$endDate      = $exam['EndDate']           ?? '';
$gradingScale = $exam['GradingScale']      ?? 'Percentage';
$passingPct   = (int)($exam['PassingPercent'] ?? 33);

// ── School info ───────────────────────────────────────────────────────
$schoolDisplayName = $schoolInfo['Name']    ?? $schoolName;
$schoolCity        = $schoolInfo['City']    ?? '';
$schoolAddress     = $schoolInfo['Address'] ?? '';
$schoolAffNo       = $schoolInfo['AffNo']   ?? $schoolInfo['affiliation_no'] ?? '';
$schoolBoard       = $schoolInfo['Board']   ?? '';
$schoolCode        = $schoolInfo['Code']    ?? '';
$schoolLogoUrl     = $schoolInfo['Logo']    ?? '';

// ── Build subject rows ────────────────────────────────────────────────
$subjectRows = [];
$allCompDefs = [];

if (!empty($computed['Subjects']) && is_array($computed['Subjects'])) {
  foreach ($computed['Subjects'] as $subj => $sd) {
    if (!is_array($sd)) continue; // H3: skip non-array subject entries
    $tmpl     = $templates[$subj] ?? [];
    $comps    = $tmpl['Components'] ?? [];
    if (!is_array($comps)) $comps = []; // H1: prevent ksort() TypeError
    ksort($comps);
    $stuMarks = $marks[$subj] ?? [];
    if (!is_array($stuMarks)) $stuMarks = [];

    $row = [
      'subject'  => $subj,
      'comps'    => [],
      'total'    => $sd['Total']      ?? 0,
      'maxMarks' => $sd['MaxMarks']   ?? 0,
      'pct'      => $sd['Percentage'] ?? 0,
      'grade'    => $sd['Grade']      ?? '',
      'passFail' => (string)($sd['PassFail'] ?? ''), // H5: cast to string for strtolower()
      'absent'   => $sd['Absent']     ?? false,
    ];

    foreach ($comps as $ci => $comp) {
      if (!is_array($comp)) continue; // M3: skip non-array components
      $cn = $comp['Name'] ?? ('Component ' . $ci); // H4: fallback for missing Name
      $mx = (int)($comp['MaxMarks'] ?? 0);
      $val = $stuMarks[$cn] ?? ($sd['Absent'] ? 0 : '—');
      $row['comps'][$cn] = is_scalar($val) ? $val : '—'; // M2: reject nested arrays
      $allCompDefs[$cn]  = $mx;
    }
    $subjectRows[] = $row;
  }
}

// ── Collapse redundant synthetic "Total" component ─────────────────────
// When the ONLY component across all subjects is the synthetic "Total"
// (created by the datesheet->template fallback for total-only exams), the
// per-component column just duplicates "Marks Obtained". Drop it so every
// template renders a clean Subject | Marks | Grade table. Genuine breakdowns
// (Theory/Practical/Internal, or any 2+ components, or any non-"Total" single
// component) are retained untouched. Single-point fix — all 6 templates derive
// their component columns from $allCompDefs / $row['comps'].
if (count($allCompDefs) === 1 && array_key_exists('Total', $allCompDefs)) {
  $allCompDefs = [];
  foreach ($subjectRows as $__i => $__row) {
    $subjectRows[$__i]['comps'] = [];
  }
}

// ── Grand totals ──────────────────────────────────────────────────────
$grandTotal = $computed['TotalMarks']  ?? 0;
$grandMax   = $computed['MaxMarks']    ?? 0;
$grandPct   = $computed['Percentage']  ?? 0;
$grandGrade = $computed['Grade']       ?? '';
$grandPass  = (string)($computed['PassFail'] ?? '');
$rank       = $computed['Rank']        ?? '';

// CC-8-view: a fully-absent student (Absent flag from compute_results) must
// render AB overall — never 0%, PASS or FAIL. Existing Pass/Fail rendering is
// untouched ($overallAbsent is false for every non-fully-absent student).
$overallAbsent = !empty($computed['Absent']);
$resultIcon    = $overallAbsent ? '' : ($grandPass === 'Pass' ? '&#10003;' : '&#10007;');

// ── Grade legend ──────────────────────────────────────────────────────
$scaleLegendMap = [
  'Percentage' => 'A+=(90-100), A=(80-89), B+=(70-79), B=(60-69), C=(50-59), D=(33-49), F=(Below 33)',
  'A-F Grades' => 'A=(90-100), B=(80-89), C=(70-79), D=(60-69), E=(50-59), F=(&lt;50)',
  'O-E Grades' => 'O=(91-100), E1=(81-90), E2=(71-80), B1=(61-70), B2=(51-60), C1=(41-50), C2=(33-40), D=(&lt;33)',
  '10-Point'   => '10=(91-100), 9=(81-90), 8=(71-80), 7=(61-70), 6=(51-60), 5=(41-50), 4=(33-40), F=(&lt;33)',
  'Pass/Fail'  => 'Pass=(&ge;' . $passingPct . '%), Fail=(&lt;' . $passingPct . '%)',
];
$gradeLegend = $scaleLegendMap[$gradingScale] ?? '';

// ── Promotion text ────────────────────────────────────────────────────
$nextClass = '';
if (preg_match('/\d+/', $classNameRaw, $m)) {
  $nextNum = (int)$m[0] + 1;
  if ($nextNum <= 12) {
      $nextClass = ' TO GRADE ' . $nextNum;
  }
}
// Promotion is an ANNUAL/FINAL decision — only the final/annual exam may declare
// "PROMOTED TO GRADE X". Term/unit/mid-term report cards show a neutral
// pass/fail result instead (a single term test must never promote a student).
$isFinalExam = (bool) preg_match('/\b(final|annual)\b/i', (string) $examType . ' ' . (string) $examName);
$resultText = $overallAbsent
  ? 'RESULT : ABSENT'
  : ($grandPass === 'Pass'
      ? ($isFinalExam ? ('RESULT : PROMOTED' . $nextClass) : 'RESULT : PASS')
      : ($isFinalExam ? 'RESULT : NOT PROMOTED — FURTHER IMPROVEMENT NEEDED' : 'RESULT : FAIL'));

// ──────────────────────────────────────────────────────────────────────
// Report-card CUSTOMIZATION config (per-school, from school doc
// `reportCardConfig`). Every template may read these $rc* variables.
// All values fall back to safe defaults so templates render unchanged
// when a school has not customised anything.
// ──────────────────────────────────────────────────────────────────────
$rc_config = isset($rc_config) && is_array($rc_config) ? $rc_config : [];

// — Accent / theme colour ——————————————————————————————————————
$rcAccent = (string)($rc_config['accentColor'] ?? '');
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $rcAccent)) {
    $rcAccent = '#1a3a6b';   // default professional navy
}
// Derive a darker shade for gradients / borders (multiply each channel by 0.72)
$_r = max(0, (int)round(hexdec(substr($rcAccent, 1, 2)) * 0.72));
$_g = max(0, (int)round(hexdec(substr($rcAccent, 3, 2)) * 0.72));
$_b = max(0, (int)round(hexdec(substr($rcAccent, 5, 2)) * 0.72));
$rcAccentDark = sprintf('#%02x%02x%02x', $_r, $_g, $_b);
// Very light tint for zebra rows / panels
$_lr = (int)round(hexdec(substr($rcAccent, 1, 2)) + (255 - hexdec(substr($rcAccent, 1, 2))) * 0.92);
$_lg = (int)round(hexdec(substr($rcAccent, 3, 2)) + (255 - hexdec(substr($rcAccent, 3, 2))) * 0.92);
$_lb = (int)round(hexdec(substr($rcAccent, 5, 2)) + (255 - hexdec(substr($rcAccent, 5, 2))) * 0.92);
$rcAccentTint = sprintf('#%02x%02x%02x', $_lr, $_lg, $_lb);

// — Section visibility toggles (default ON) ——————————————————————
$rcSections = is_array($rc_config['sections'] ?? null) ? $rc_config['sections'] : [];
$_rcShow = function (string $k) use ($rcSections): bool {
    // Missing key => visible (backward-compatible default).
    return !array_key_exists($k, $rcSections) || (bool)$rcSections[$k];
};
$rcShowPhoto        = $_rcShow('photo');
$rcShowResultStrip  = $_rcShow('resultStrip');
$rcShowLegend       = $_rcShow('gradingLegend');
$rcShowCoScholastic = $_rcShow('coScholastic');
$rcShowAttendance   = $_rcShow('attendance');
$rcShowSignatures   = $_rcShow('signatures');

// — Custom text ————————————————————————————————————————————————
$rcText            = is_array($rc_config['text'] ?? null) ? $rc_config['text'] : [];
$rcTitle           = trim((string)($rcText['title'] ?? ''));            // overrides the "REPORT CARD" heading
$rcFooter          = trim((string)($rcText['footer'] ?? 'This is a computer-generated report card. No signature is required for electronic copies.'));
$rcPrincipalName   = trim((string)($rcText['principalName'] ?? ''));
$rcControllerName  = trim((string)($rcText['examControllerName'] ?? ''));
$rcClassTeacherNm  = trim((string)($rcText['classTeacherName'] ?? ''));
$rcDefaultRemark   = trim((string)($rcText['defaultRemark'] ?? ''));

// — Asset images (logo override + signature images) ————————————————
$rcAssets           = is_array($rc_config['assets'] ?? null) ? $rc_config['assets'] : [];
$rcLogoUrl          = trim((string)($rcAssets['logoUrl'] ?? '')) ?: $schoolLogoUrl;   // custom logo overrides school logo
$rcPrincipalSignUrl = trim((string)($rcAssets['principalSignUrl'] ?? ''));
$rcTeacherSignUrl   = trim((string)($rcAssets['classTeacherSignUrl'] ?? ''));
