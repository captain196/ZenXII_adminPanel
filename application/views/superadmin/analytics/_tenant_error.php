<?php
/** Phase 1G — friendly error panel for invalid/non-existent tenant IDs. */
$h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); };
$errorCode = $errorCode ?? 'unknown';
$errorMsg  = $errorMsg  ?? 'An error occurred.';
$schoolId  = $schoolId  ?? '';
?>
<section class="content-header" style="padding:18px 24px 0 24px;">
  <h1 style="margin:0;font-size:20px;color:#0f172a;">
    <i class="fa fa-exclamation-triangle" style="color:#dc2626;margin-right:10px;"></i>Tenant Deep Dive — Error
  </h1>
  <ol class="breadcrumb" style="margin-top:6px;background:transparent;padding:0;">
    <li><a href="<?= base_url('superadmin/dashboard') ?>">Dashboard</a></li>
    <li class="active">Tenant Detail</li>
  </ol>
</section>
<div style="padding:24px;">
  <div style="background:#fff;border:1px solid #e6e8eb;border-radius:8px;padding:30px;max-width:680px;margin:0 auto;text-align:center;">
    <i class="fa fa-question-circle" style="font-size:48px;color:#94a3b8;margin-bottom:14px;"></i>
    <h3 style="margin:0 0 8px 0;color:#475569;"><?= $h($errorMsg) ?></h3>
    <p style="font-size:12.5px;color:#64748b;margin-bottom:16px;">
      Provided school ID: <code><?= $h($schoolId) ?: '(empty)' ?></code> · error code: <code><?= $h($errorCode) ?></code>
    </p>
    <a href="<?= base_url('superadmin/dashboard') ?>" class="btn btn-primary btn-sm" style="margin-right:8px;">
      <i class="fa fa-arrow-left"></i> Back to Dashboard
    </a>
    <a href="<?= base_url('superadmin/dashboard/schools-search') ?>" class="btn btn-default btn-sm">
      <i class="fa fa-search"></i> Find a Tenant
    </a>
  </div>
</div>
