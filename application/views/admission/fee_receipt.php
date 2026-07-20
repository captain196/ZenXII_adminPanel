<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Commercial admission-fee receipt (PDF/print). Reads ONE admissionReceipts
 * doc ($receipt) + the school profile ($school_profile). No business logic —
 * pure presentation of the record the shared Admission_collection engine wrote.
 */
$sp   = is_array($school_profile ?? null) ? $school_profile : [];
$r    = is_array($receipt ?? null) ? $receipt : [];
$e    = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$money = function ($v) { return number_format((float) $v, 2); };
$typeLabel = ucfirst((string) ($r['receiptType'] ?? 'admission')) . ' Fee';
$dt = (string) ($r['datetime'] ?? $r['date'] ?? '');
$logo = (string) ($sp['logo_url'] ?? '');
?>
<style>
  * { box-sizing: border-box; }
  body { font-family: DejaVu Sans, Arial, sans-serif; color:#1f2937; font-size:12px; margin:0; }
  .rcpt { max-width: 720px; margin: 0 auto; padding: 18px 22px; border:1px solid #e5e7eb; }
  .hd { display:flex; align-items:center; border-bottom:2px solid #0f766e; padding-bottom:10px; }
  .hd img { height:56px; width:auto; margin-right:14px; }
  .hd .sn { font-size:20px; font-weight:800; color:#0f766e; line-height:1.15; }
  .hd .ad { font-size:11px; color:#6b7280; margin-top:2px; }
  .title { text-align:center; font-size:15px; font-weight:800; letter-spacing:1.5px; margin:12px 0 4px; color:#111827; }
  .subttl { text-align:center; font-size:11px; color:#6b7280; margin-bottom:10px; }
  .meta { display:flex; justify-content:space-between; font-size:11px; margin:6px 0 12px; color:#374151; }
  table.kv { width:100%; border-collapse:collapse; }
  table.kv td { padding:6px 8px; border-bottom:1px solid #f1f5f9; vertical-align:top; }
  table.kv td.k { color:#6b7280; width:38%; }
  table.kv td.v { font-weight:600; }
  .amt { margin-top:14px; background:#f0fdfa; border:1px solid #99f6e4; border-radius:8px; padding:12px 14px; display:flex; justify-content:space-between; align-items:center; }
  .amt .lbl { font-size:12px; color:#0f766e; font-weight:700; }
  .amt .val { font-size:20px; font-weight:800; color:#0f766e; }
  .paid { display:inline-block; margin-top:10px; background:#16a34a; color:#fff; font-weight:800; letter-spacing:1px; padding:5px 14px; border-radius:6px; font-size:12px; }
  .ft { margin-top:16px; display:flex; justify-content:space-between; font-size:10px; color:#9ca3af; border-top:1px dashed #e5e7eb; padding-top:8px; }
  .sig { text-align:right; margin-top:26px; font-size:11px; }
  .sig .ln { border-top:1px solid #9ca3af; width:180px; margin-left:auto; padding-top:4px; }
</style>

<div class="rcpt">
  <div class="hd">
    <?php if ($logo !== ''): ?><img src="<?= $e($logo) ?>" alt=""><?php endif; ?>
    <div>
      <div class="sn"><?= $e($sp['display_name'] ?? $sp['school_name'] ?? 'School') ?></div>
      <?php if (!empty($sp['address'])): ?><div class="ad"><?= $e($sp['address']) ?></div><?php endif; ?>
      <?php if (!empty($sp['phone']) || !empty($sp['email'])): ?>
        <div class="ad"><?= $e($sp['phone'] ?? '') ?><?= (!empty($sp['phone']) && !empty($sp['email'])) ? ' &bull; ' : '' ?><?= $e($sp['email'] ?? '') ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="title">ADMISSION FEE RECEIPT</div>
  <div class="subttl"><?= $e(strtoupper((string) ($r['collectionSource'] ?? 'Admission Collection'))) ?></div>

  <div class="meta">
    <div><strong>Receipt No:</strong> <?= $e($r['receiptKey'] ?? '') ?></div>
    <div><strong>Date:</strong> <?= $e($dt) ?></div>
  </div>

  <table class="kv">
    <tr><td class="k">Admission Reference No</td><td class="v"><?= $e($r['admissionRef'] ?? '') ?></td></tr>
    <tr><td class="k">Applicant Name</td><td class="v"><?= $e($r['studentName'] ?? '') ?></td></tr>
    <tr><td class="k">Father / Guardian Name</td><td class="v"><?= $e($r['fatherName'] ?? '-') ?></td></tr>
    <tr><td class="k">Applied Class</td><td class="v"><?= $e($r['appliedClass'] ?? '-') ?></td></tr>
    <tr><td class="k">Admission Fee Type</td><td class="v"><?= $e($typeLabel) ?><?= !empty($r['feeLabel']) ? ' (' . $e($r['feeLabel']) . ')' : '' ?></td></tr>
    <tr><td class="k">Payment Mode</td><td class="v"><?= $e($r['paymentMode'] ?? '') ?><?= !empty($r['bankReference']) ? ' &mdash; Ref: ' . $e($r['bankReference']) : '' ?></td></tr>
    <tr><td class="k">Collection Source</td><td class="v"><?= $e($r['collectionSource'] ?? '') ?></td></tr>
    <?php if (($r['source'] ?? '') === 'offline'): ?>
      <tr><td class="k">Collected By</td><td class="v"><?= $e($r['collectedBy'] ?? '') ?></td></tr>
    <?php endif; ?>
    <?php if (!empty($r['remarks'])): ?>
      <tr><td class="k">Remarks</td><td class="v"><?= $e($r['remarks']) ?></td></tr>
    <?php endif; ?>
  </table>

  <div class="amt">
    <div class="lbl">Amount Received (<?= $e($r['currency'] ?? 'INR') ?>)</div>
    <div class="val"><?= $e(($r['currency'] ?? 'INR') === 'INR' ? '&#8377; ' : '') ?><?= $money($r['amount'] ?? 0) ?></div>
  </div>
  <div style="text-align:center"><span class="paid">STATUS: PAID</span></div>

  <div class="sig"><div class="ln">Authorised Signatory</div></div>

  <div class="ft">
    <div>This is a system-generated admission fee receipt.</div>
    <div>Admission fee is non-refundable.</div>
  </div>
</div>
