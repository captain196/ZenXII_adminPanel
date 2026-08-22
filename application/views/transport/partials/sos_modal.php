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
  transport/partials/sos_modal.php
  F6 SOS admin panel (LC-13, 2026-07-07)

  Two modals: raise SOS (admin-fired MVP; Teacher-app path lands in F9)
  and respond/resolve SOS. Both hit Transport_notifier through
  Transport_sos controller endpoints.
-->

<!-- Raise SOS Modal -->
<div class="trn-modal-bg" id="sosRaiseModal"><div class="trn-modal" style="width:520px">
  <div class="trn-modal-title">
    <span>Raise SOS Alert</span>
    <button class="trn-modal-close" onclick="TRN.closeSosRaise()">&times;</button>
  </div>
  <div style="background:rgba(220,38,38,.08);border-left:3px solid #dc2626;padding:10px 12px;border-radius:4px;font-size:12px;color:var(--t1);font-family:var(--font-b);margin-bottom:14px">
    <b>Urgent broadcast.</b> This alerts Admin, Transport Manager, Principal (ops) AND all parents of the affected vehicle's route. Do not use for non-emergencies.
  </div>

  <div class="trn-form-row">
    <div class="trn-form-group"><label>Alert Type *</label>
      <select id="sosAlertType">
        <option value="emergency">Emergency</option>
        <option value="breakdown">Vehicle Breakdown</option>
        <option value="accident">Accident</option>
        <option value="medical">Medical</option>
        <option value="other">Other</option>
      </select>
    </div>
    <div class="trn-form-group"><label>Vehicle (optional)</label>
      <select id="sosVehicle"><option value="">-- None --</option></select>
    </div>
  </div>
  <div class="trn-form-group"><label>Route (optional)</label>
    <select id="sosRoute"><option value="">-- None --</option></select>
  </div>

  <div class="trn-form-row">
    <div class="trn-form-group"><label>Latitude</label><input type="number" id="sosLat" step="0.000001" placeholder="0.000000"></div>
    <div class="trn-form-group"><label>Longitude</label><input type="number" id="sosLng" step="0.000001" placeholder="0.000000"></div>
  </div>

  <div class="trn-form-group"><label>Message *</label>
    <textarea id="sosMessage" rows="3" placeholder="Concise emergency description; will fan out unedited."></textarea>
  </div>

  <button class="trn-btn trn-btn-danger" onclick="TRN.confirmRaiseSos()" <?= $can_edit?'':'disabled' ?> style="width:100%">
    <i class="fa fa-exclamation-triangle"></i> Raise SOS
  </button>
</div></div>

<!-- Respond/Resolve SOS Modal -->
<div class="trn-modal-bg" id="sosActionModal"><div class="trn-modal" style="width:480px">
  <div class="trn-modal-title">
    <span id="sosActionTitle">SOS Action</span>
    <button class="trn-modal-close" onclick="TRN.closeSosAction()">&times;</button>
  </div>
  <input type="hidden" id="sosActionId">
  <input type="hidden" id="sosActionKind">
  <div class="trn-form-group"><label>Notes *</label>
    <textarea id="sosActionNotes" rows="3" placeholder="Describe the response / resolution briefly."></textarea>
  </div>
  <button id="sosActionSubmit" class="trn-btn trn-btn-primary" onclick="TRN.confirmSosAction()" <?= $can_edit?'':'disabled' ?> style="width:100%">Submit</button>
</div></div>
