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
  transport/partials/bulk_assign_modal.php
  F3 Student Assignment (2026-07-07)

  Admin bulk-assign — accepts JSON array of rows. Server enforces cap of
  500 rows per invocation (Transport_service::BULK_ASSIGN_HARD_CAP).
-->
<div class="trn-modal-bg" id="bulkAssignModal"><div class="trn-modal" style="width:720px">
  <div class="trn-modal-title">
    <span>Bulk Assign Students</span>
    <button class="trn-modal-close" onclick="TRN.closeBulkAssign()">&times;</button>
  </div>
  <div style="background:rgba(59,130,246,.08);border-left:3px solid #3b82f6;padding:10px 12px;border-radius:4px;font-size:12px;color:var(--t1);font-family:var(--font-b);margin-bottom:14px">
    <b>Format:</b> paste a JSON array of rows (up to 500). Each row minimum:<br>
    <code style="background:var(--bg);padding:2px 6px;border-radius:3px;font-size:11px">{"student_id":"STU0001","route_id":"RT0001","stop_id":"STP0001","type":"both","subscription_type":"Permanent","fee_category":"Standard"}</code>
  </div>
  <div class="trn-form-group">
    <label>Rows JSON *</label>
    <textarea id="baRowsJson" style="width:100%;min-height:220px;padding:10px;font-family:monospace;font-size:12px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--t1)"
      placeholder='[{"student_id":"STU0001","route_id":"RT0001","stop_id":"STP0001","type":"both","subscription_type":"Permanent","fee_category":"Standard"}]'></textarea>
  </div>
  <button class="trn-btn trn-btn-primary" onclick="TRN.doBulkAssign()" <?= $can_manage?'':'disabled title="Requires Transport · manage"' ?> style="width:100%">Bulk Assign</button>
  <div id="baResult" style="margin-top:12px"></div>
</div></div>

<!-- Assignment history + edit + change-route/stop + suspend modals -->
<div class="trn-modal-bg" id="asnHistoryModal"><div class="trn-modal" style="width:820px;max-height:88vh">
  <div class="trn-modal-title">
    <span>Assignment History <span id="ahStudent" style="color:var(--gold)"></span></span>
    <button class="trn-modal-close" onclick="TRN.closeHistory()">&times;</button>
  </div>
  <div style="max-height:70vh;overflow-y:auto">
    <table class="trn-table">
      <thead><tr><th>Event</th><th>When</th><th>By</th><th>Source</th><th>Batch</th><th>Reason</th></tr></thead>
      <tbody id="ahTbody"></tbody>
    </table>
  </div>
</div></div>

<div class="trn-modal-bg" id="asnChangeModal"><div class="trn-modal" style="width:520px">
  <div class="trn-modal-title">
    <span id="acTitle">Change Assignment</span>
    <button class="trn-modal-close" onclick="TRN.closeChange()">&times;</button>
  </div>
  <input type="hidden" id="acStudentId">
  <input type="hidden" id="acMode">
  <div class="trn-form-group" id="acRouteField"><label>New Route *</label><select id="acRouteId" onchange="TRN.acRouteChanged()"></select></div>
  <div class="trn-form-group" id="acStopField"><label>New Stop *</label><select id="acStopId"></select></div>
  <div class="trn-form-group"><label>Reason *</label><input type="text" id="acReason"></div>
  <button class="trn-btn trn-btn-primary" onclick="TRN.doChange()" <?= $can_manage?'':'disabled title="Requires Transport · manage"' ?> style="width:100%">Apply Change</button>
</div></div>

<div class="trn-modal-bg" id="asnSuspendModal"><div class="trn-modal" style="width:480px">
  <div class="trn-modal-title">
    <span>Suspend Assignment</span>
    <button class="trn-modal-close" onclick="TRN.closeSuspendAsn()">&times;</button>
  </div>
  <input type="hidden" id="sasStudentId">
  <div class="trn-form-row">
    <div class="trn-form-group"><label>From *</label><input type="date" id="sasFrom"></div>
    <div class="trn-form-group"><label>Until (blank for open-ended)</label><input type="date" id="sasUntil"></div>
  </div>
  <div class="trn-form-group"><label>Reason *</label><input type="text" id="sasReason"></div>
  <button class="trn-btn trn-btn-primary" onclick="TRN.doSuspendAsn()" <?= $can_manage?'':'disabled title="Requires Transport · manage"' ?> style="width:100%">Suspend</button>
</div></div>
