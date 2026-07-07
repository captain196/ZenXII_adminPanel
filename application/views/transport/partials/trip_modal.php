<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<!--
  transport/partials/trip_modal.php
  F7 Trip Logs + Transport Attendance (LC-17, LC-18, 2026-07-07)

  Three modals:
    1. Create Trip (Ready state)
    2. Trip Manage (checkpoint + boarding grid; only when InProgress)
    3. Complete Trip

  Design decisions honoured:
    • Ready → InProgress → Completed | Cancelled lifecycle
    • Roster is immutable after creation (Refinement 3) — grid is read-only
    • Completion allowed when every checkpoint is Reached OR Skipped
      (Refinement 4)
    • GPS-native fields exposed in the schema but NOT displayed here
      (GPS phase will render lat/lng, live map, ETA, etc.)
-->

<!-- Create Trip Modal -->
<div class="trn-modal-bg" id="tripCreateModal"><div class="trn-modal" style="width:560px">
  <div class="trn-modal-title">
    <span>Create Trip (Ready)</span>
    <button class="trn-modal-close" onclick="TRN.closeTripCreate()">&times;</button>
  </div>
  <div style="background:rgba(59,130,246,.08);border-left:3px solid #3b82f6;padding:10px 12px;border-radius:4px;font-size:12px;color:var(--t1);font-family:var(--font-b);margin-bottom:14px">
    Trips are created in <b>Ready</b> state (dispatch preparation). Roster and checkpoints are snapshot at create time. Start the trip separately from the Trip Monitor row action.
  </div>

  <div class="trn-form-row">
    <div class="trn-form-group"><label>Trip Date *</label><input type="date" id="tcDate"></div>
    <div class="trn-form-group"><label>Direction *</label>
      <select id="tcDirection">
        <option value="Pickup">Pickup (morning)</option>
        <option value="Drop">Drop (afternoon)</option>
      </select>
    </div>
  </div>

  <div class="trn-form-row">
    <div class="trn-form-group"><label>Vehicle *</label>
      <select id="tcVehicle"></select>
    </div>
    <div class="trn-form-group"><label>Route *</label>
      <select id="tcRoute"></select>
    </div>
  </div>

  <div class="trn-form-group"><label>Scheduled Start (ISO ts, optional)</label>
    <input type="text" id="tcSchedStart" placeholder="2026-07-07T07:00:00+05:30">
  </div>
  <div class="trn-form-group"><label>Notes</label><input type="text" id="tcNotes"></div>

  <button class="trn-btn trn-btn-primary" onclick="TRN.confirmCreateTrip()" style="width:100%">Create Trip</button>
</div></div>

<!-- Trip Manage Modal -->
<div class="trn-modal-bg" id="tripManageModal"><div class="trn-modal" style="width:1000px;max-height:90vh">
  <div class="trn-modal-title">
    <span id="tmTitle">Trip Management</span>
    <button class="trn-modal-close" onclick="TRN.closeTripManage()">&times;</button>
  </div>
  <div id="tmMeta" style="font-size:12px;color:var(--t3);font-family:var(--font-b);margin-bottom:12px"></div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
    <!-- Checkpoints -->
    <div>
      <div style="font-family:var(--font-b);font-weight:700;color:var(--t1);margin-bottom:8px">Checkpoints</div>
      <table class="trn-table" style="font-size:12px">
        <thead><tr><th>#</th><th>Stop</th><th>State</th><th>Reached</th><th>Actions</th></tr></thead>
        <tbody id="tmCheckpoints"></tbody>
      </table>
    </div>
    <!-- Boarding Grid -->
    <div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <div style="font-family:var(--font-b);font-weight:700;color:var(--t1)">Roster (immutable)</div>
        <div style="font-size:11px;color:var(--t3)"><span id="tmRosterSize">0</span> students</div>
      </div>
      <table class="trn-table" style="font-size:12px">
        <thead><tr><th>Student</th><th>State</th><th>Actions</th></tr></thead>
        <tbody id="tmRoster"></tbody>
      </table>
    </div>
  </div>

  <div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end">
    <button class="trn-btn trn-btn-danger" onclick="TRN.openCancelTrip()">Cancel Trip</button>
    <button class="trn-btn trn-btn-primary" onclick="TRN.openCompleteTrip()">Complete Trip</button>
  </div>
</div></div>

<!-- Complete Trip Modal -->
<div class="trn-modal-bg" id="tripCompleteModal"><div class="trn-modal" style="width:520px">
  <div class="trn-modal-title">
    <span>Complete Trip</span>
    <button class="trn-modal-close" onclick="TRN.closeTripComplete()">&times;</button>
  </div>
  <input type="hidden" id="tcplId">
  <div style="background:rgba(59,130,246,.08);border-left:3px solid #3b82f6;padding:10px 12px;border-radius:4px;font-size:12px;color:var(--t1);font-family:var(--font-b);margin-bottom:14px">
    Every checkpoint must be either Reached or Skipped before completion. This is enforced server-side.
  </div>
  <div class="trn-form-row">
    <div class="trn-form-group"><label>End Time (ISO)</label><input type="text" id="tcplEndTime"></div>
    <div class="trn-form-group"><label>End Odometer (km)</label><input type="number" id="tcplEndOdo" min="0" step="0.1"></div>
  </div>
  <div class="trn-form-row">
    <div class="trn-form-group"><label>Distance (km, optional)</label><input type="number" id="tcplDistance" min="0" step="0.1"></div>
    <div class="trn-form-group"><label>Fuel Used (L, optional)</label><input type="number" id="tcplFuel" min="0" step="0.1"></div>
  </div>
  <div class="trn-form-group"><label>Notes</label><input type="text" id="tcplNotes"></div>
  <button class="trn-btn trn-btn-primary" onclick="TRN.confirmCompleteTrip()" style="width:100%">Complete Trip</button>
</div></div>

<!-- Cancel Trip Modal -->
<div class="trn-modal-bg" id="tripCancelModal"><div class="trn-modal" style="width:420px">
  <div class="trn-modal-title">
    <span>Cancel Trip</span>
    <button class="trn-modal-close" onclick="TRN.closeTripCancel()">&times;</button>
  </div>
  <input type="hidden" id="tccId">
  <div class="trn-form-group"><label>Reason *</label><input type="text" id="tccReason"></div>
  <button class="trn-btn trn-btn-danger" onclick="TRN.confirmCancelTrip()" style="width:100%">Cancel Trip</button>
</div></div>

<!-- Skip Checkpoint Modal -->
<div class="trn-modal-bg" id="checkpointSkipModal"><div class="trn-modal" style="width:420px">
  <div class="trn-modal-title">
    <span>Skip Checkpoint</span>
    <button class="trn-modal-close" onclick="TRN.closeCheckpointSkip()">&times;</button>
  </div>
  <input type="hidden" id="cskTripId">
  <input type="hidden" id="cskStopId">
  <div class="trn-form-group"><label>Reason *</label><input type="text" id="cskReason" placeholder="e.g. no students at this stop"></div>
  <button class="trn-btn trn-btn-primary" onclick="TRN.confirmCheckpointSkip()" style="width:100%">Skip Checkpoint</button>
</div></div>
