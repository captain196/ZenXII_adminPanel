<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
// 2026-08-07 — graded RBAC (TL convention, commit 7ccebc0). Recomputed locally:
// this partial is loaded via $this->load->view() with no data, so the parent
// view's flags are NOT in scope. The server remains authoritative — every
// endpoint re-checks via require_permission('Transport', <action>, <level>).
$can_edit   = function_exists('has_permission') ? has_permission('Transport','edit')   : true;
$can_manage = function_exists('has_permission') ? has_permission('Transport','manage') : true;
?>
<!--
  transport/partials/rollover_modal.php
  F3 Session Rollover (2026-07-07)

  Auto-continuing rollover UI — server processes chunks in one call until
  wall-clock ceiling or completion. Client polls /status; on in_progress,
  automatically re-invokes /continue with no admin interaction required
  (Refinement #1 — checkpoint is recovery-only).
-->
<div class="trn-modal-bg" id="rolloverModal"><div class="trn-modal" style="width:640px">
  <div class="trn-modal-title">
    <span>Session Rollover</span>
    <button class="trn-modal-close" onclick="TRN.closeRollover()">&times;</button>
  </div>
  <div style="background:rgba(245,158,11,.08);border-left:3px solid #f59e0b;padding:10px 12px;border-radius:4px;font-size:12px;color:var(--t1);font-family:var(--font-b);margin-bottom:14px">
    <b>Rollover archives every current-session assignment to routeAssignments and updates studentRoutes to the new session.</b> Fee_lifecycle cancels old-session Transport demands and creates new-session demands. Processing auto-continues in the background — no repeated clicks required.
  </div>
  <div class="trn-form-row">
    <div class="trn-form-group"><label>From Session *</label><input type="text" id="roFrom" placeholder="e.g. 2025-26"></div>
    <div class="trn-form-group"><label>To Session *</label><input type="text" id="roTo" placeholder="e.g. 2026-27"></div>
  </div>
  <div id="roStatus" style="font-family:var(--font-b);font-size:13px;color:var(--t1);margin-bottom:14px;padding:10px;background:var(--bg);border-radius:6px;display:none"></div>
  <button class="trn-btn trn-btn-primary" onclick="TRN.startRollover()" <?= $can_edit?'':'disabled' ?> style="width:100%" id="roStartBtn">Start Rollover</button>
</div></div>
