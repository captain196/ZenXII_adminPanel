<?php
/**
 * Admission receipt PDF — Tier-A QW #1.
 *
 * Rendered by Pdf_generator (Dompdf 2.x). Layout uses tables / inline-blocks,
 * no flex/grid (Dompdf has limited flex, zero grid). Print-only — no
 * interactive controls.
 *
 * Inputs (passed by Admission_public::receipt):
 *   $school_id      string
 *   $school_profile array  display_name / address / phone / email / logo_url
 *   $app_id         string
 *   $application    array  full crmApplications doc
 *   $receipt_url    string absolute URL of this receipt (for QR + footer)
 *   $qr_url         string external QR PNG endpoint (qrserver.com)
 */

// Pull the application doc into named locals — keep the view template flat.
$app = is_array($application) ? $application : [];

$studentName  = (string) ($app['student_name']    ?? '');
$class        = (string) ($app['class']           ?? '');
$dob          = (string) ($app['dob']             ?? '');
$gender       = (string) ($app['gender']          ?? '');
$bloodGroup   = (string) ($app['blood_group']     ?? '');

$fatherName   = (string) ($app['father_name']     ?? '');
$motherName   = (string) ($app['mother_name']     ?? '');
$phone        = (string) ($app['phone']           ?? '');
$email        = (string) ($app['email']           ?? '');
$guardPhone   = (string) ($app['guardian_phone']  ?? '');
$guardRelation= (string) ($app['guardian_relation'] ?? '');

$address      = (string) ($app['address']         ?? '');
$city         = (string) ($app['city']            ?? '');
$state        = (string) ($app['state']           ?? '');
$pincode      = (string) ($app['pincode']         ?? '');

$prevSchool   = (string) ($app['previous_school'] ?? '');
$prevClass    = (string) ($app['previous_class']  ?? '');
$prevMarks    = (string) ($app['previous_marks']  ?? '');

$session      = (string) ($app['session']         ?? '');
$status       = (string) ($app['status']          ?? 'pending');
$payStatus    = (string) ($app['payment_status']  ?? 'pending');
$paidAmount   = (float)  ($app['paid_amount']     ?? 0);
$createdAt    = (string) ($app['created_at']      ?? '');
$consentAt    = (string) ($app['consent_given_at'] ?? '');

$schoolName   = htmlspecialchars((string) ($school_profile['display_name'] ?? ''));
$schoolAddr   = htmlspecialchars((string) ($school_profile['address']      ?? ''));
$schoolPhone  = htmlspecialchars((string) ($school_profile['phone']        ?? ''));
$schoolEmail  = htmlspecialchars((string) ($school_profile['email']        ?? ''));
$logoUrl      = (string) ($school_profile['logo_url'] ?? '');

// Helper for a 2-cell label/value row.
$row = function (string $label, string $value): string {
    $value = trim($value);
    if ($value === '') $value = '<span style="color:#94a3b8;">—</span>';
    else               $value = htmlspecialchars($value);
    return '<tr>'
         . '<td class="ar-k">' . htmlspecialchars($label) . '</td>'
         . '<td class="ar-v">' . $value . '</td>'
         . '</tr>';
};

$prettyStatus = ucfirst($status);
$prettyPay    = ucfirst($payStatus);
$paymentLine  = $payStatus === 'paid'
    ? 'Paid &#8377; ' . number_format($paidAmount, 2)
    : 'Payment ' . $prettyPay;
?>
<style>
  body { font-family: 'DejaVu Sans', sans-serif; color: #1f2937; font-size: 11px; }

  .ar-page { padding: 24px 28px; }

  /* ── Header ──────────────────────────────────────────────────── */
  .ar-hdr { display: table; width: 100%; border-bottom: 2px solid #0f766e; padding-bottom: 12px; margin-bottom: 16px; }
  .ar-hdr-l { display: table-cell; vertical-align: middle; width: 70px; }
  .ar-hdr-c { display: table-cell; vertical-align: middle; padding-left: 14px; }
  .ar-hdr-r { display: table-cell; vertical-align: middle; text-align: right; width: 160px; }
  .ar-logo { width: 64px; height: 64px; border: 1px solid #cbd5e1; border-radius: 6px; }
  .ar-school-name { font-size: 16px; font-weight: 800; color: #0f766e; }
  .ar-school-meta { font-size: 10px; color: #475569; margin-top: 2px; }
  .ar-receipt-tag { font-size: 9px; letter-spacing: 1.5px; color: #0f766e; font-weight: 700; }
  .ar-app-id { font-size: 14px; font-weight: 800; color: #1f2937; margin-top: 2px; }

  /* ── Status banner ──────────────────────────────────────────── */
  .ar-banner { background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 6px;
               padding: 10px 14px; margin-bottom: 16px; }
  .ar-banner b { color: #0f766e; }

  /* ── Section card ───────────────────────────────────────────── */
  .ar-sec { margin-bottom: 14px; }
  .ar-sec-hdr { background: #0f766e; color: #fff; padding: 5px 12px;
                font-weight: 700; font-size: 11px; letter-spacing: 0.5px;
                border-radius: 4px 4px 0 0; }
  .ar-tbl { width: 100%; border-collapse: collapse; border: 1px solid #cbd5e1;
            border-top: none; border-radius: 0 0 4px 4px; }
  .ar-tbl td { padding: 6px 12px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
  .ar-tbl tr:last-child td { border-bottom: none; }
  .ar-k { width: 38%; color: #64748b; font-weight: 600; font-size: 10px; }
  .ar-v { color: #1f2937; font-weight: 500; }

  /* ── QR + meta footer ───────────────────────────────────────── */
  .ar-foot { margin-top: 18px; display: table; width: 100%; }
  .ar-foot-l { display: table-cell; vertical-align: top; width: 70%; padding-right: 14px;
               font-size: 10px; color: #475569; line-height: 1.6; }
  .ar-foot-r { display: table-cell; vertical-align: top; width: 30%; text-align: center; }
  .ar-qr { width: 110px; height: 110px; border: 1px solid #e2e8f0; padding: 4px; }
  .ar-foot-r .qr-cap { font-size: 9px; color: #64748b; margin-top: 6px; }

  .ar-page-foot { margin-top: 14px; text-align: center; font-size: 9px; color: #94a3b8;
                  border-top: 1px dashed #cbd5e1; padding-top: 10px; }
</style>

<div class="ar-page">

  <!-- ── Header ──────────────────────────────────────────────── -->
  <div class="ar-hdr">
    <div class="ar-hdr-l">
      <?php if ($logoUrl !== ''): ?>
        <img src="<?= htmlspecialchars($logoUrl) ?>" class="ar-logo" alt="Logo">
      <?php else: ?>
        <div class="ar-logo" style="background:#f1f5f9;text-align:center;line-height:64px;color:#94a3b8;font-size:24px;">&#9733;</div>
      <?php endif; ?>
    </div>
    <div class="ar-hdr-c">
      <div class="ar-school-name"><?= $schoolName ?></div>
      <?php if ($schoolAddr !== ''): ?>
        <div class="ar-school-meta"><?= $schoolAddr ?></div>
      <?php endif; ?>
      <?php if ($schoolPhone !== '' || $schoolEmail !== ''): ?>
        <div class="ar-school-meta">
          <?= $schoolPhone ?><?= ($schoolPhone !== '' && $schoolEmail !== '') ? ' &middot; ' : '' ?><?= $schoolEmail ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="ar-hdr-r">
      <div class="ar-receipt-tag">ADMISSION RECEIPT</div>
      <div class="ar-app-id"><?= htmlspecialchars($app_id) ?></div>
      <?php if ($createdAt !== ''): ?>
        <div class="ar-school-meta">Submitted: <?= htmlspecialchars($createdAt) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Status banner ───────────────────────────────────────── -->
  <div class="ar-banner">
    Application <b><?= htmlspecialchars($prettyStatus) ?></b>
    &middot; <?= htmlspecialchars($paymentLine) ?>
    <?php if ($session !== ''): ?>
      &middot; Session <b><?= htmlspecialchars($session) ?></b>
    <?php endif; ?>
  </div>

  <!-- ── Student details ─────────────────────────────────────── -->
  <div class="ar-sec">
    <div class="ar-sec-hdr">Student Details</div>
    <table class="ar-tbl">
      <?= $row('Name',         $studentName) ?>
      <?= $row('Class',        $class) ?>
      <?= $row('Date of Birth',$dob) ?>
      <?= $row('Gender',       $gender) ?>
      <?= $row('Blood Group',  $bloodGroup) ?>
    </table>
  </div>

  <!-- ── Parent / Guardian ───────────────────────────────────── -->
  <div class="ar-sec">
    <div class="ar-sec-hdr">Parent / Guardian</div>
    <table class="ar-tbl">
      <?= $row("Father's Name", $fatherName) ?>
      <?= $row("Mother's Name", $motherName) ?>
      <?= $row('Primary Phone', $phone) ?>
      <?= $row('Email',         $email) ?>
      <?php if ($guardPhone !== ''): ?>
        <?= $row('Guardian Phone',    $guardPhone) ?>
        <?= $row('Guardian Relation', $guardRelation) ?>
      <?php endif; ?>
    </table>
  </div>

  <!-- ── Address ─────────────────────────────────────────────── -->
  <div class="ar-sec">
    <div class="ar-sec-hdr">Address</div>
    <table class="ar-tbl">
      <?= $row('Street',      $address) ?>
      <?= $row('City',        $city) ?>
      <?= $row('State',       $state) ?>
      <?= $row('Postal Code', $pincode) ?>
    </table>
  </div>

  <!-- ── Previous schooling (only if any field present) ──────── -->
  <?php if ($prevSchool !== '' || $prevClass !== '' || $prevMarks !== ''): ?>
    <div class="ar-sec">
      <div class="ar-sec-hdr">Previous Schooling</div>
      <table class="ar-tbl">
        <?= $row('School',       $prevSchool) ?>
        <?= $row('Class',        $prevClass) ?>
        <?= $row('Marks / Grade',$prevMarks) ?>
      </table>
    </div>
  <?php endif; ?>

  <!-- ── Footer with QR ──────────────────────────────────────── -->
  <div class="ar-foot">
    <div class="ar-foot-l">
      <b>What happens next?</b><br>
      The school's admissions team will review your application and contact you
      using the phone number above. Please keep this receipt for your records;
      the application ID at the top right is your reference for any queries.
      <?php if ($consentAt !== ''): ?>
        <br><br>
        <span style="font-size:9px;color:#64748b;">
          Privacy / DPDP consent recorded at <?= htmlspecialchars($consentAt) ?>.
        </span>
      <?php endif; ?>
    </div>
    <div class="ar-foot-r">
      <img src="<?= htmlspecialchars($qr_url) ?>" class="ar-qr" alt="Receipt QR">
      <div class="qr-cap">Scan to re-open this receipt</div>
    </div>
  </div>

  <div class="ar-page-foot">
    This is a computer-generated receipt and does not require a signature.
    Receipt URL: <?= htmlspecialchars($receipt_url) ?>
  </div>

</div>
