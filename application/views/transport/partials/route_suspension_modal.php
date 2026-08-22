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
  transport/partials/route_suspension_modal.php
  F2 Route & Stop Management (2026-07-07)

  Two modals:
    1. Suspend Route — reason category dropdown + notes + notify-parents toggle
                       (default checkbox state derived from category per
                       Transport_service::SUSPENSION_PARENT_NOTIFICATION_DEFAULT)
    2. Resume Route  — resolution notes

  All actions call Transport_service via Transport controller endpoints
  (suspend_route / resume_route). Included by transport/index.php.
-->
<div class="trn-modal-bg" id="suspendModal"><div class="trn-modal" style="width:560px">
  <div class="trn-modal-title">
    <span>Suspend Route <span id="rsModalRoute" style="color:var(--gold)"></span></span>
    <button class="trn-modal-close" onclick="TRN.closeSuspendModal()">&times;</button>
  </div>
  <input type="hidden" id="rsRouteId">

  <div class="trn-form-row">
    <div class="trn-form-group">
      <label>Reason Category *</label>
      <select id="rsCategory" onchange="TRN.suspendCategoryChanged()">
        <option value="">-- Select --</option>
        <option value="Vehicle_Breakdown">Vehicle Breakdown</option>
        <option value="Maintenance">Maintenance</option>
        <option value="Weather">Weather</option>
        <option value="Road_Closure">Road Closure</option>
        <option value="Driver_Unavailable">Driver Unavailable</option>
        <option value="Other">Other</option>
      </select>
    </div>
    <div class="trn-form-group">
      <label>Notify Parents on Affected Route</label>
      <select id="rsNotifyParents">
        <option value="">Use category default</option>
        <option value="1">Yes — notify parents</option>
        <option value="0">No — operations team only</option>
      </select>
      <div id="rsNotifyHint" style="font-size:11px;color:var(--t3);margin-top:4px;font-family:var(--font-b)"></div>
    </div>
  </div>

  <div class="trn-form-row">
    <div class="trn-form-group"><label>Suspended From *</label><input type="date" id="rsFrom"></div>
    <div class="trn-form-group"><label>Suspended To (optional; leave blank while active)</label><input type="date" id="rsTo"></div>
  </div>

  <div class="trn-form-group">
    <label>Reason / Details *</label>
    <input type="text" id="rsReason" placeholder="e.g. Bus in workshop for annual service">
  </div>

  <div style="background:rgba(59,130,246,.08);border-left:3px solid #3b82f6;padding:10px 12px;border-radius:4px;font-size:12px;color:var(--t1);font-family:var(--font-b);margin-bottom:14px">
    <b>Notification policy (F2):</b> Admin and Transport Manager are always notified. Parents are notified only when the category or your override selects it.
  </div>

  <button class="trn-btn trn-btn-primary" onclick="TRN.doSuspendRoute()" <?= $can_manage?'':'disabled title="Requires Transport · manage"' ?> style="width:100%">Suspend Route</button>
</div></div>

<div class="trn-modal-bg" id="resumeModal"><div class="trn-modal" style="width:480px">
  <div class="trn-modal-title">
    <span>Resume Route <span id="rrModalRoute" style="color:var(--gold)"></span></span>
    <button class="trn-modal-close" onclick="TRN.closeResumeModal()">&times;</button>
  </div>
  <input type="hidden" id="rrSuspensionId">
  <div class="trn-form-group"><label>Resolution Notes</label><input type="text" id="rrNotes" placeholder="e.g. Vehicle returned from workshop"></div>
  <div style="font-size:12px;color:var(--t3);font-family:var(--font-b);margin-bottom:12px">
    A resume notice will be sent using the same audience rule as the original suspension.
  </div>
  <button class="trn-btn trn-btn-primary" onclick="TRN.doResumeRoute()" <?= $can_manage?'':'disabled title="Requires Transport · manage"' ?> style="width:100%">Resume Route</button>
</div></div>

<div class="trn-modal-bg" id="archiveModal"><div class="trn-modal" style="width:480px">
  <div class="trn-modal-title">
    <span>Archive Route</span>
    <button class="trn-modal-close" onclick="TRN.closeArchiveModal()">&times;</button>
  </div>
  <input type="hidden" id="arRouteId">
  <div style="font-size:12px;color:var(--t3);font-family:var(--font-b);margin-bottom:12px">
    Archiving preserves historical assignments, trips, and audit data. Use "Permanently Delete" only for routes that were never used.
  </div>
  <div class="trn-form-group"><label>Reason *</label><input type="text" id="arReason" placeholder="e.g. Discontinued for 2026-27 session"></div>
  <div style="display:flex;gap:8px">
    <button class="trn-btn trn-btn-primary" onclick="TRN.doArchiveRoute()" <?= $can_edit?'':'disabled' ?> style="flex:1">Archive</button>
    <button class="trn-btn trn-btn-danger" onclick="TRN.doPermanentlyDeleteRoute()" <?= $can_manage?'':'disabled' ?> style="flex:1" title="Permanent delete — only works when no assignments reference the route">Permanently Delete</button>
  </div>
</div></div>
