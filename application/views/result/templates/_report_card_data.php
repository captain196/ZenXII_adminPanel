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

// ── Grand totals ──────────────────────────────────────────────────────
$grandTotal = $computed['TotalMarks']  ?? 0;
$grandMax   = $computed['MaxMarks']    ?? 0;
$grandPct   = $computed['Percentage']  ?? 0;
$grandGrade = $computed['Grade']       ?? '';
$grandPass  = (string)($computed['PassFail'] ?? '');
$rank       = $computed['Rank']        ?? '';

// ── Grade legend ──────────────────────────────────────────────────────
$scaleLegendMap = [
  'Percentage' => 'A1=(91-100), A2=(81-90), B1=(71-80), B2=(61-70), C1=(51-60), C2=(41-50), D=(33-40), E=(32 &amp; Below - Needs Improvement)',
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
$resultText = ($grandPass === 'Pass')
  ? 'RESULT : PROMOTED' . $nextClass
  : 'RESULT : NOT PROMOTED — FURTHER IMPROVEMENT NEEDED';
