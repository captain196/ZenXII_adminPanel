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
  transport/partials/vehicle_manage_modal.php
  F1 Fleet Management (2026-07-07)

  Single deep-focus modal for a selected vehicle. Contains 5 sub-tabs:
    1. Lifecycle       — state machine transition + timeline
    2. Documents       — upload + list + delete
    3. Maintenance     — record + list
    4. Fuel            — record + list (rolling kmpl computed on save)
    5. Replacement/Transfer — record replacement / transfer between routes

  Every action calls a Transport_service endpoint (via the appropriate
  controller). Included by transport/index.php.
-->
<div class="trn-modal-bg" id="vehManageModal"><div class="trn-modal" style="width:820px;max-height:90vh">
  <div class="trn-modal-title">
    <span>Manage Vehicle <span id="vmVehLabel"></span></span>
    <button class="trn-modal-close" onclick="TRN.closeVehManage()">&times;</button>
  </div>

  <nav class="trn-tabs" style="margin-bottom:16px">
    <a href="#" class="trn-tab active" data-vmtab="lifecycle" onclick="TRN.vmSwitch('lifecycle');return false"><i class="fa fa-clock-o"></i> Lifecycle</a>
    <a href="#" class="trn-tab" data-vmtab="documents" onclick="TRN.vmSwitch('documents');return false"><i class="fa fa-file"></i> Documents</a>
    <a href="#" class="trn-tab" data-vmtab="maintenance" onclick="TRN.vmSwitch('maintenance');return false"><i class="fa fa-wrench"></i> Maintenance</a>
    <a href="#" class="trn-tab" data-vmtab="fuel" onclick="TRN.vmSwitch('fuel');return false"><i class="fa fa-tint"></i> Fuel</a>
    <a href="#" class="trn-tab" data-vmtab="replacement" onclick="TRN.vmSwitch('replacement');return false"><i class="fa fa-exchange"></i> Replace/Transfer</a>
  </nav>

  <!-- Lifecycle -->
  <div class="trn-panel active" data-vmpanel="lifecycle">
    <div class="trn-card">
      <div class="trn-card-title"><span>Current State: <b id="vmLifecycleNow">—</b></span></div>
      <div class="trn-form-row">
        <div class="trn-form-group"><label>Transition To</label>
          <select id="vmToState"><option value="">-- Select --</option></select>
        </div>
        <div class="trn-form-group"><label>Reason *</label>
          <input type="text" id="vmReason" placeholder="e.g. Sent to workshop for annual service">
        </div>
      </div>
      <button class="trn-btn trn-btn-primary" onclick="TRN.vmTransition()" <?= $can_edit?'':'disabled' ?>>Apply Transition</button>
    </div>
    <div class="trn-card">
      <div class="trn-card-title"><span>Timeline</span></div>
      <table class="trn-table"><thead><tr><th>Date</th><th>Event</th><th>From → To</th><th>Reason</th><th>By</th></tr></thead>
      <tbody id="vmLifecycleTbody"></tbody></table>
    </div>
  </div>

  <!-- Documents -->
  <div class="trn-panel" data-vmpanel="documents" style="display:none">
    <div class="trn-card">
      <div class="trn-card-title"><span>Upload Document</span></div>
      <div class="trn-form-row">
        <div class="trn-form-group"><label>Type *</label>
          <input type="text" id="vmDocType" list="vmDocTypeList" placeholder="e.g. Insurance">
          <datalist id="vmDocTypeList">
            <option value="RC"><option value="Insurance"><option value="Permit"><option value="PUC">
            <option value="Fitness"><option value="RoadTax"><option value="Contract"><option value="Misc">
          </datalist>
        </div>
        <div class="trn-form-group"><label>Document Number</label><input type="text" id="vmDocNumber"></div>
      </div>
      <div class="trn-form-row">
        <div class="trn-form-group"><label>Issue Date</label><input type="date" id="vmDocIssue"></div>
        <div class="trn-form-group"><label>Expiry Date</label><input type="date" id="vmDocExpiry"></div>
      </div>
      <div class="trn-form-group"><label>File *</label><input type="file" id="vmDocFile"></div>
      <div class="trn-form-group"><label>Notes</label><input type="text" id="vmDocNotes"></div>
      <button class="trn-btn trn-btn-primary" onclick="TRN.vmUploadDoc()" <?= $can_edit?'':'disabled' ?>>Upload</button>
    </div>
    <div class="trn-card">
      <div class="trn-card-title"><span>Attached Documents</span></div>
      <table class="trn-table"><thead><tr><th>Type</th><th>Number</th><th>Issued</th><th>Expiry</th><th>File</th><th></th></tr></thead>
      <tbody id="vmDocsTbody"></tbody></table>
    </div>
  </div>

  <!-- Maintenance -->
  <div class="trn-panel" data-vmpanel="maintenance" style="display:none">
    <div class="trn-card">
      <div class="trn-card-title"><span>Record Maintenance</span></div>
      <div class="trn-form-row">
        <div class="trn-form-group"><label>Type</label>
          <select id="vmMaintType"><option>Routine</option><option>Repair</option><option>Tyre</option><option>Overhaul</option><option>Body</option><option>Other</option></select>
        </div>
        <div class="trn-form-group"><label>Service Date</label><input type="date" id="vmMaintDate"></div>
      </div>
      <div class="trn-form-row">
        <div class="trn-form-group"><label>Odometer (km)</label><input type="number" id="vmMaintKm" min="0"></div>
        <div class="trn-form-group"><label>Cost (Rs)</label><input type="number" id="vmMaintCost" min="0" step="0.01"></div>
      </div>
      <div class="trn-form-row">
        <div class="trn-form-group"><label>Workshop</label><input type="text" id="vmMaintWorkshop"></div>
        <div class="trn-form-group"><label>Next Service Date (projected)</label><input type="date" id="vmMaintNextDate"></div>
      </div>
      <div class="trn-form-group"><label>Parts Replaced (comma-separated)</label><input type="text" id="vmMaintParts"></div>
      <div class="trn-form-group"><label>Notes</label><input type="text" id="vmMaintNotes"></div>
      <button class="trn-btn trn-btn-primary" onclick="TRN.vmSaveMaint()" <?= $can_edit?'':'disabled' ?>>Save Maintenance</button>
    </div>
    <div class="trn-card">
      <div class="trn-card-title"><span>Maintenance History</span></div>
      <table class="trn-table"><thead><tr><th>Date</th><th>Type</th><th>KM</th><th>Cost</th><th>Workshop</th><th>Notes</th></tr></thead>
      <tbody id="vmMaintTbody"></tbody></table>
    </div>
  </div>

  <!-- Fuel -->
  <div class="trn-panel" data-vmpanel="fuel" style="display:none">
    <div class="trn-card">
      <div class="trn-card-title"><span>Record Fuel</span></div>
      <div class="trn-form-row">
        <div class="trn-form-group"><label>Fuel Date</label><input type="date" id="vmFuelDate"></div>
        <div class="trn-form-group"><label>Odometer (km)</label><input type="number" id="vmFuelOdo" min="0"></div>
      </div>
      <div class="trn-form-row">
        <div class="trn-form-group"><label>Litres *</label><input type="number" id="vmFuelLitres" min="0.01" step="0.01"></div>
        <div class="trn-form-group"><label>Rs / litre</label><input type="number" id="vmFuelPpl" min="0" step="0.01"></div>
      </div>
      <div class="trn-form-row">
        <div class="trn-form-group"><label>Total (Rs)</label><input type="number" id="vmFuelTotal" min="0" step="0.01"></div>
        <div class="trn-form-group"><label>Station</label><input type="text" id="vmFuelStation"></div>
      </div>
      <div class="trn-form-group"><label>Notes</label><input type="text" id="vmFuelNotes"></div>
      <button class="trn-btn trn-btn-primary" onclick="TRN.vmSaveFuel()" <?= $can_edit?'':'disabled' ?>>Save Fuel</button>
      <div id="vmFuelKmpl" style="margin-top:12px;font-family:var(--font-b);color:var(--gold)"></div>
    </div>
    <div class="trn-card">
      <div class="trn-card-title"><span>Fuel History</span></div>
      <table class="trn-table"><thead><tr><th>Date</th><th>Litres</th><th>Rs/L</th><th>Total</th><th>Odo</th><th>kmpl</th></tr></thead>
      <tbody id="vmFuelTbody"></tbody></table>
    </div>
  </div>

  <!-- Replacement / Transfer -->
  <div class="trn-panel" data-vmpanel="replacement" style="display:none">
    <div class="trn-card">
      <div class="trn-card-title"><span>Record Replacement</span></div>
      <div class="trn-form-row">
        <div class="trn-form-group"><label>Replacement Vehicle</label>
          <select id="vmReplVeh"><option value="">-- Select --</option></select>
        </div>
        <div class="trn-form-group"><label>Type</label>
          <select id="vmReplType"><option value="Temporary">Temporary</option><option value="Permanent">Permanent</option></select>
        </div>
      </div>
      <div class="trn-form-row">
        <div class="trn-form-group"><label>Effective From</label><input type="date" id="vmReplFrom"></div>
        <div class="trn-form-group"><label>Effective To</label><input type="date" id="vmReplTo"></div>
      </div>
      <div class="trn-form-group"><label>Reason *</label><input type="text" id="vmReplReason"></div>
      <div class="trn-form-group"><label>Notes</label><input type="text" id="vmReplNotes"></div>
      <button class="trn-btn trn-btn-primary" onclick="TRN.vmRecordReplacement()" <?= $can_edit?'':'disabled' ?>>Record Replacement</button>
    </div>
    <div class="trn-card">
      <div class="trn-card-title"><span>Transfer Between Routes</span></div>
      <div class="trn-form-row">
        <div class="trn-form-group"><label>From Route</label>
          <select id="vmXferFrom"><option value="">-- Select --</option></select>
        </div>
        <div class="trn-form-group"><label>To Route</label>
          <select id="vmXferTo"><option value="">-- Select --</option></select>
        </div>
      </div>
      <div class="trn-form-group"><label>Reason *</label><input type="text" id="vmXferReason"></div>
      <button class="trn-btn trn-btn-primary" onclick="TRN.vmTransfer()" <?= $can_manage?'':'disabled title="Requires Transport · manage"' ?>>Transfer Vehicle</button>
    </div>
  </div>
</div></div>
