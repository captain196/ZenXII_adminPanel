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

  <!-- UNIT 2B-3 — campus-geofence prerequisite guidance. Populated by
       tripErr() in transport/index.php ONLY when the server refuses a Pickup
       trip for a missing campus arrival geofence. Empty and hidden otherwise;
       a Drop trip can never trigger it because the server guard is Pickup-only. -->
  <div id="tcGeoWarn" style="display:none"></div>

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

  <button class="trn-btn trn-btn-primary" onclick="TRN.confirmCreateTrip()" <?= $can_manage?'':'disabled title="Requires Transport · manage"' ?> style="width:100%">Create Trip</button>
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

  <!--
    Lifecycle actions. Shown/hidden per trip status by TRN.openTripManage() to
    mirror what the service actually permits:
        Cancel   → Ready | InProgress   (TRIP_ACTIVE_STATUSES)
        Complete → InProgress only
    A Completed/Cancelled trip is terminal and gets #tmStateNote instead. These
    used to render unconditionally, so re-opening a cancelled trip still offered
    "Cancel Trip" — the action then failed server-side with a state error.
  -->
  <div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;align-items:center">
    <span id="tmStateNote" style="display:none;margin-right:auto;font-size:12.5px;color:var(--t3)"></span>
    <button id="tmCancelBtn" class="trn-btn trn-btn-danger" onclick="TRN.openCancelTrip()" <?= $can_manage?'':'disabled' ?>>Cancel Trip</button>
    <!--
      F14 Phase 2A — audited override for the school-arrival deadlock: the bus IS
      at school but GPS cannot prove it (no fix, stale fix, or outside the
      geofence). Requires Transport · manage. It does NOT bypass unresolved
      students or students still on board.
    -->
    <button id="tmOverrideBtn" class="trn-btn" style="display:none;background:#b45309;border-color:#b45309;color:#fff" onclick="TRN.openOverrideComplete()" <?= $can_manage?'':'disabled title="Requires Transport · manage"' ?>>Override Complete…</button>
    <button id="tmCompleteBtn" class="trn-btn trn-btn-primary" onclick="TRN.openCompleteTrip()" <?= $can_edit?'':'disabled' ?>>Complete Trip</button>
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
  <button class="trn-btn trn-btn-primary" onclick="TRN.confirmCompleteTrip()" <?= $can_edit?'':'disabled' ?> style="width:100%">Complete Trip</button>
</div></div>

<!-- Override Complete Modal (F14 Phase 2A) -->
<div class="trn-modal-bg" id="tripOverrideModal"><div class="trn-modal" style="width:540px">
  <div class="trn-modal-title">
    <span>Override Trip Completion</span>
    <button class="trn-modal-close" onclick="TRN.closeOverrideComplete()">&times;</button>
  </div>
  <input type="hidden" id="tovId">
  <div style="background:rgba(180,83,9,.10);border-left:3px solid #b45309;padding:10px 12px;border-radius:4px;font-size:12px;color:var(--t1);font-family:var(--font-b);margin-bottom:14px">
    <strong>Use only when the bus has genuinely reached school</strong> but tracking cannot prove it —
    no GPS fix, a stale fix, or the bus sitting outside the campus geofence.
    <br><br>
    This records a permanent, audited note that arrival was <strong>not GPS-verified</strong>.
    It does <strong>not</strong> bypass student safety: unresolved students and students still marked
    on board will still block completion, and you must resolve them first.
  </div>
  <div class="trn-form-group">
    <label>Reason (required, recorded permanently)</label>
    <input type="text" id="tovReason" placeholder="e.g. Bus reached school; driver phone lost GPS signal in the basement bay">
  </div>
  <div class="trn-form-row">
    <div class="trn-form-group"><label>End Odometer (km, optional)</label><input type="number" id="tovEndOdo" min="0" step="0.1"></div>
    <div class="trn-form-group"><label>Distance (km, optional)</label><input type="number" id="tovDistance" min="0" step="0.1"></div>
  </div>
  <!--
    trn-btn-danger is REQUIRED, not decorative: index.php's busy-button handler
    binds on '.trn-modal-bg .trn-btn-primary, .trn-modal-bg .trn-btn-danger'
    (mousedown), and post() reads that captured button to apply the spinner,
    the disabled state and the trn-busy duplicate-submit block. Styled with
    class-only amber before, this button matched neither selector, so a
    manage-level destructive action had NO duplicate-submit guard — and trip
    writes here can take >10s. The inline background still overrides the
    danger-red, so the amber treatment is unchanged. Found in UAT 2026-08-13.
  -->
  <button class="trn-btn trn-btn-danger" style="width:100%;background:#b45309;border-color:#b45309;color:#fff" onclick="TRN.confirmOverrideComplete()" <?= $can_manage?'':'disabled' ?>>Override &amp; Complete Trip</button>
</div></div>

<!-- Cancel Trip Modal -->
<div class="trn-modal-bg" id="tripCancelModal"><div class="trn-modal" style="width:420px">
  <div class="trn-modal-title">
    <span>Cancel Trip</span>
    <button class="trn-modal-close" onclick="TRN.closeTripCancel()">&times;</button>
  </div>
  <input type="hidden" id="tccId">
  <div class="trn-form-group"><label>Reason *</label><input type="text" id="tccReason"></div>
  <button class="trn-btn trn-btn-danger" onclick="TRN.confirmCancelTrip()" <?= $can_manage?'':'disabled' ?> style="width:100%">Cancel Trip</button>
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
  <button class="trn-btn trn-btn-primary" onclick="TRN.confirmCheckpointSkip()" <?= $can_edit?'':'disabled' ?> style="width:100%">Skip Checkpoint</button>
</div></div>
