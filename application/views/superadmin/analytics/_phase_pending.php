<?php
/** B2.3.4-A — placeholder for spokes that haven't shipped yet. */
$spoke = $spoke ?? 'This spoke';
$phase = $phase ?? '?';
?>
<section class="content-header">
  <h1><i class="fa fa-clock-o" style="color:var(--amber, #f59e0b);margin-right:10px;font-size:20px;"></i><?= htmlspecialchars($spoke) ?></h1>
  <ol class="breadcrumb">
    <li><a href="<?= base_url('superadmin/dashboard') ?>">Dashboard</a></li>
    <li class="active"><?= htmlspecialchars($spoke) ?></li>
  </ol>
</section>
<section class="content" style="padding:40px 24px;">
  <div class="box" style="max-width:560px;margin:0 auto;text-align:center;padding:30px;">
    <i class="fa fa-flask" style="font-size:36px;color:var(--sa3);margin-bottom:14px;"></i>
    <h3 style="margin:0 0 8px 0;font-family:var(--font-b);color:var(--t1);"><?= htmlspecialchars($spoke) ?></h3>
    <p style="color:var(--t3);font-size:13px;margin-bottom:18px;">
      Coming in Phase <strong><?= htmlspecialchars($phase) ?></strong> of the B2.3.4-A Dashboard Analytics cycle.
      Foundation (Phase 1A) and Hub (Phase 1B) are live.
    </p>
    <a href="<?= base_url('superadmin/dashboard') ?>" class="btn btn-primary btn-sm">
      <i class="fa fa-arrow-left"></i> Back to Dashboard
    </a>
  </div>
</section>
