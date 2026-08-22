<?php defined('BASEPATH') or exit('No direct script access allowed');
// 2026-08-07 — graded RBAC (TL convention, commit 7ccebc0). Recomputed locally:
// this partial is loaded via $this->load->view() with no data, so the parent
// view's flags are NOT in scope. The server remains authoritative — every
// endpoint re-checks via require_permission('Transport', <action>, <level>).
$can_edit   = function_exists('has_permission') ? has_permission('Transport','edit')   : true;
$can_manage = function_exists('has_permission') ? has_permission('Transport','manage') : true;
?>
<!--
  transport/partials/compliance_modal.php
  F12.1 M4 (2026-08-07) — CBSE compliance checklist + attest/waive dialogs.

  Markup follows this module's existing modal idiom:
    .trn-modal-bg > .trn-modal > .trn-modal-title / .trn-modal-close

  Authorization here is UI affordance only. Attest requires Transport · edit
  and Waive requires Transport · manage; both are re-enforced server-side, so
  bypassing these controls still yields a 403 from the endpoint.
-->

<!-- VEHICLE COMPLIANCE CHECKLIST -->
<div class="trn-modal-bg" id="cmpModal"><div class="trn-modal" style="width:860px">
  <div class="trn-modal-title">
    <span id="cmpModalTitle">Compliance</span>
    <button class="trn-modal-close" onclick="TRN.cmpCloseModal()">&times;</button>
  </div>
  <input type="hidden" id="cmpVehId">
  <div id="cmpPosture" style="margin-bottom:12px;font-size:13px"></div>
  <table class="trn-table">
    <thead><tr><th>Requirement</th><th>Status</th><th>Dispatch</th><th>Expires</th><th>Actions</th></tr></thead>
    <tbody id="cmpRuleTbody"></tbody>
  </table>
</div></div>

<!-- ATTEST -->
<div class="trn-modal-bg" id="cmpAttestModal"><div class="trn-modal" style="width:460px">
  <div class="trn-modal-title">
    <span id="cmpAttestTitle">Attest</span>
    <button class="trn-modal-close" onclick="TRN.cmpCloseAttest()">&times;</button>
  </div>
  <input type="hidden" id="cmpAttestRule">
  <div class="trn-form-group">
    <label>Status *</label>
    <select id="cmpAttestStatus">
      <option value="Compliant">Compliant</option>
      <option value="Non_Compliant">Not compliant</option>
    </select>
  </div>
  <div class="trn-form-group">
    <label>Expires on</label>
    <input type="date" id="cmpAttestExpiry">
  </div>
  <div class="trn-form-group">
    <label>Notes</label>
    <input type="text" id="cmpAttestNotes" maxlength="200">
  </div>
  <button class="trn-btn trn-btn-primary" style="width:100%;margin-top:8px" onclick="TRN.cmpAttestSave()" <?= $can_manage?'':'disabled title="Requires Transport · manage"' ?>>Save Attestation</button>
</div></div>

<!-- WAIVE -->
<div class="trn-modal-bg" id="cmpWaiveModal"><div class="trn-modal" style="width:460px">
  <div class="trn-modal-title">
    <span id="cmpWaiveTitle">Waive</span>
    <button class="trn-modal-close" onclick="TRN.cmpCloseWaive()">&times;</button>
  </div>
  <input type="hidden" id="cmpWaiveRule">
  <div class="trn-form-group">
    <label>Waiver Reason *</label>
    <textarea id="cmpWaiveReason" rows="3" placeholder="Why is this requirement waived? Minimum 20 characters."></textarea>
    <div style="font-size:11px;color:var(--t3);margin-top:4px">Shown to inspectors and recorded in the audit trail.</div>
  </div>
  <div class="trn-form-group">
    <label>Waiver Expires *</label>
    <input type="date" id="cmpWaiveExpiry">
    <div style="font-size:11px;color:var(--t3);margin-top:4px">Waivers are never permanent — maximum 90 days.</div>
  </div>
  <button class="trn-btn trn-btn-primary" style="width:100%;margin-top:8px" onclick="TRN.cmpWaiveSave()" <?= $can_manage?'':'disabled' ?>>Record Waiver</button>
</div></div>
