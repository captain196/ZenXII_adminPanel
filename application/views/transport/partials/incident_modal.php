<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<!--
  transport/partials/incident_modal.php
  F4 Driver Management (2026-07-07)

  Incident recording form. Critical severity triggers immediate
  Communication notice to Admin + HR Manager + Transport Manager.
-->
<div class="trn-modal-bg" id="incidentModal"><div class="trn-modal" style="width:640px">
  <div class="trn-modal-title">
    <span>Record Driver Incident <span id="incStaffLabel" style="color:var(--gold)"></span></span>
    <button class="trn-modal-close" onclick="TRN.closeIncident()">&times;</button>
  </div>
  <input type="hidden" id="incStaffId">
  <div class="trn-form-row">
    <div class="trn-form-group"><label>Type *</label>
      <select id="incType">
        <option value="Accident">Accident</option>
        <option value="Complaint">Complaint</option>
        <option value="Traffic_Violation">Traffic Violation</option>
        <option value="Late_Arrival">Late Arrival</option>
        <option value="Absenteeism">Absenteeism</option>
        <option value="Safety_Breach">Safety Breach</option>
        <option value="Positive_Recognition">Positive Recognition</option>
      </select>
    </div>
    <div class="trn-form-group"><label>Severity *</label>
      <select id="incSeverity">
        <option value="Info">Info</option>
        <option value="Minor" selected>Minor</option>
        <option value="Major">Major</option>
        <option value="Critical">Critical — will notify HR + Admin + Transport Manager</option>
      </select>
    </div>
  </div>
  <div class="trn-form-row">
    <div class="trn-form-group"><label>Incident Date *</label><input type="date" id="incDate"></div>
    <div class="trn-form-group"><label>Incident Time</label><input type="time" id="incTime"></div>
  </div>
  <div class="trn-form-row">
    <div class="trn-form-group"><label>Related Vehicle</label><select id="incVehicle"><option value="">-- None --</option></select></div>
    <div class="trn-form-group"><label>Related Trip ID</label><input type="text" id="incTripId" placeholder="TRP...... (F7 populates)"></div>
  </div>
  <div class="trn-form-group"><label>Location</label><input type="text" id="incLocation"></div>
  <div class="trn-form-group"><label>Description *</label><textarea id="incDesc" style="width:100%;min-height:100px;padding:8px;font-family:var(--font-b);font-size:13px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--t1)"></textarea></div>
  <button class="trn-btn trn-btn-primary" onclick="TRN.doRecordIncident()" style="width:100%">Record Incident</button>
</div></div>

<!-- Incident resolution mini-modal -->
<div class="trn-modal-bg" id="incResolveModal"><div class="trn-modal" style="width:480px">
  <div class="trn-modal-title">
    <span>Resolve Incident</span>
    <button class="trn-modal-close" onclick="TRN.closeResolveIncident()">&times;</button>
  </div>
  <input type="hidden" id="incResId">
  <div class="trn-form-group"><label>Resolution *</label><input type="text" id="incResText"></div>
  <div class="trn-form-group"><label>Action Taken</label><input type="text" id="incResAction"></div>
  <button class="trn-btn trn-btn-primary" onclick="TRN.doResolveIncident()" style="width:100%">Resolve</button>
</div></div>
