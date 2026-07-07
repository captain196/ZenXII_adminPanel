<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<!--
  transport/partials/event_modal.php
  F8 Transport Events (LC-23, 2026-07-07)

  Modals:
    1. Create Event (Planned state)
    2. Manage Event (roster + escorts + dispatch history)
    3. Dispatch Event Trip
    4. Cancel Event
    5. Add Escort
    6. Complete Event
-->

<!-- Create Event Modal -->
<div class="trn-modal-bg" id="eventCreateModal"><div class="trn-modal" style="width:680px;max-height:90vh">
  <div class="trn-modal-title">
    <span>Create Event (Planned)</span>
    <button class="trn-modal-close" onclick="TRN.closeEventCreate()">&times;</button>
  </div>
  <div style="background:rgba(59,130,246,.08);border-left:3px solid #3b82f6;padding:10px 12px;border-radius:4px;font-size:12px;color:var(--t1);font-family:var(--font-b);margin-bottom:14px">
    Events are created in <b>Planned</b> state (drafting). Add students + escorts, then Schedule to lock the roster and notify parents. Route is optional per event.
  </div>
  <div class="trn-form-row">
    <div class="trn-form-group"><label>Title *</label><input type="text" id="ecTitle" placeholder="e.g. Museum Excursion"></div>
    <div class="trn-form-group"><label>Type *</label>
      <select id="ecType">
        <option value="Educational_Tour">Educational Tour</option>
        <option value="Sports">Sports</option>
        <option value="Annual_Function">Annual Function</option>
        <option value="Competition">Competition</option>
        <option value="Excursion">Excursion</option>
        <option value="Examination">Examination</option>
        <option value="Field_Trip">Field Trip</option>
        <option value="Cultural_Event">Cultural Event</option>
        <option value="Other">Other</option>
      </select>
    </div>
  </div>
  <div class="trn-form-group"><label>Description</label><input type="text" id="ecDesc" placeholder="Short description"></div>
  <div class="trn-form-row">
    <div class="trn-form-group"><label>Event Date (start) *</label><input type="date" id="ecDate"></div>
    <div class="trn-form-group"><label>Event End Date (multi-day)</label><input type="date" id="ecEndDate"></div>
  </div>
  <div class="trn-form-row">
    <div class="trn-form-group"><label>Destination</label><input type="text" id="ecDestination" placeholder="e.g. National Museum"></div>
    <div class="trn-form-group"><label>Charter Type *</label>
      <select id="ecCharter">
        <option value="Own_Fleet">Own Fleet</option>
        <option value="Chartered">Chartered (hired)</option>
        <option value="Mixed">Mixed</option>
      </select>
    </div>
  </div>
  <div class="trn-form-row">
    <div class="trn-form-group"><label>Pickup Location</label><input type="text" id="ecPickup" placeholder="e.g. School gate"></div>
    <div class="trn-form-group"><label>Drop Location</label><input type="text" id="ecDrop"></div>
  </div>
  <div class="trn-form-row">
    <div class="trn-form-group"><label>Expected Students Count</label><input type="number" id="ecExpectedCount" min="0"></div>
    <div class="trn-form-group"><label>Budget (INR, informational)</label><input type="number" id="ecBudget" min="0" step="0.01"></div>
  </div>
  <div class="trn-form-group">
    <label>Assigned Vehicles (comma-separated IDs) *</label>
    <input type="text" id="ecVehicles" placeholder="VH0001, VH0002">
  </div>
  <div class="trn-form-group"><label>Notes</label><input type="text" id="ecNotes"></div>
  <button class="trn-btn trn-btn-primary" onclick="TRN.confirmCreateEvent()" style="width:100%">Create Event</button>
</div></div>

<!-- Manage Event Modal -->
<div class="trn-modal-bg" id="eventManageModal"><div class="trn-modal" style="width:1100px;max-height:90vh">
  <div class="trn-modal-title">
    <span id="emTitle">Event Management</span>
    <button class="trn-modal-close" onclick="TRN.closeEventManage()">&times;</button>
  </div>
  <div id="emMeta" style="font-size:12px;color:var(--t3);font-family:var(--font-b);margin-bottom:12px"></div>
  <div id="emCostBar" style="margin-bottom:14px"></div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:16px">
    <!-- Roster -->
    <div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <div style="font-family:var(--font-b);font-weight:700;color:var(--t1)">Roster</div>
        <div style="font-size:11px;color:var(--t3)"><span id="emRosterSize">0</span> assigned</div>
      </div>
      <div id="emAssignForm" style="display:flex;gap:6px;margin-bottom:8px">
        <input type="text" id="emStudentIdInput" placeholder="student_id" style="flex:1;padding:6px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--t1);font-size:12px;font-family:var(--font-b)">
        <input type="text" id="emVehicleAssignInput" placeholder="vehicle (opt)" style="flex:0.7;padding:6px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--t1);font-size:12px;font-family:var(--font-b)">
        <button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.assignStudentToEvent()"><i class="fa fa-plus"></i></button>
      </div>
      <table class="trn-table" style="font-size:12px">
        <thead><tr><th>Student</th><th>Vehicle</th><th>Consent</th><th>Actions</th></tr></thead>
        <tbody id="emRoster"></tbody>
      </table>
    </div>

    <!-- Escorts + Dispatched trips -->
    <div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <div style="font-family:var(--font-b);font-weight:700;color:var(--t1)">Teacher Escorts</div>
        <button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.openAddEscort()"><i class="fa fa-plus"></i> Add</button>
      </div>
      <table class="trn-table" style="font-size:12px;margin-bottom:16px">
        <thead><tr><th>Staff</th><th>Role</th><th>Actions</th></tr></thead>
        <tbody id="emEscorts"></tbody>
      </table>

      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <div style="font-family:var(--font-b);font-weight:700;color:var(--t1)">Dispatched Trips</div>
        <button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.openDispatchTrip()"><i class="fa fa-truck"></i> Dispatch</button>
      </div>
      <table class="trn-table" style="font-size:12px">
        <thead><tr><th>Trip ID</th><th>Vehicle</th><th>Direction</th><th>Status</th></tr></thead>
        <tbody id="emTrips"></tbody>
      </table>
    </div>
  </div>

  <div style="margin-top:8px;display:flex;gap:8px;justify-content:flex-end">
    <button id="emScheduleBtn" class="trn-btn trn-btn-primary" onclick="TRN.scheduleEvent()">Schedule</button>
    <button class="trn-btn trn-btn-primary" onclick="TRN.openCompleteEvent()">Complete</button>
    <button class="trn-btn trn-btn-danger" onclick="TRN.openCancelEvent()">Cancel Event</button>
  </div>
</div></div>

<!-- Dispatch Trip Modal -->
<div class="trn-modal-bg" id="eventDispatchModal"><div class="trn-modal" style="width:520px">
  <div class="trn-modal-title">
    <span>Dispatch Event Trip</span>
    <button class="trn-modal-close" onclick="TRN.closeDispatchTrip()">&times;</button>
  </div>
  <input type="hidden" id="edtEventId">
  <div class="trn-form-group"><label>Vehicle * (must be assigned to the event)</label>
    <select id="edtVehicle"></select>
  </div>
  <div class="trn-form-row">
    <div class="trn-form-group"><label>Route (optional)</label>
      <select id="edtRoute"><option value="">-- Free-form (no route) --</option></select>
    </div>
    <div class="trn-form-group"><label>Direction *</label>
      <select id="edtDirection">
        <option value="Pickup">Pickup</option>
        <option value="Drop">Drop</option>
      </select>
    </div>
  </div>
  <div class="trn-form-group"><label>Scheduled Start (ISO, optional)</label><input type="text" id="edtSchedStart"></div>
  <button class="trn-btn trn-btn-primary" onclick="TRN.confirmDispatchTrip()" style="width:100%">Dispatch Trip</button>
</div></div>

<!-- Add Escort Modal -->
<div class="trn-modal-bg" id="eventAddEscortModal"><div class="trn-modal" style="width:440px">
  <div class="trn-modal-title">
    <span>Add Teacher Escort</span>
    <button class="trn-modal-close" onclick="TRN.closeAddEscort()">&times;</button>
  </div>
  <input type="hidden" id="eaeEventId">
  <div class="trn-form-group"><label>Staff ID *</label><input type="text" id="eaeStaffId" placeholder="e.g. STA0001"></div>
  <div class="trn-form-group"><label>Role *</label>
    <select id="eaeRole">
      <option value="Lead_Teacher">Lead Teacher</option>
      <option value="Assistant">Assistant</option>
      <option value="Nurse">Nurse</option>
      <option value="Security">Security</option>
      <option value="Chaperone" selected>Chaperone</option>
      <option value="Coordinator">Coordinator</option>
      <option value="Other">Other</option>
    </select>
  </div>
  <div class="trn-form-group"><label>Notes</label><input type="text" id="eaeNotes"></div>
  <button class="trn-btn trn-btn-primary" onclick="TRN.confirmAddEscort()" style="width:100%">Add Escort</button>
</div></div>

<!-- Complete Event Modal -->
<div class="trn-modal-bg" id="eventCompleteModal"><div class="trn-modal" style="width:460px">
  <div class="trn-modal-title">
    <span>Complete Event</span>
    <button class="trn-modal-close" onclick="TRN.closeCompleteEvent()">&times;</button>
  </div>
  <input type="hidden" id="ecplEventId">
  <div style="background:rgba(59,130,246,.08);border-left:3px solid #3b82f6;padding:10px 12px;border-radius:4px;font-size:12px;color:var(--t1);font-family:var(--font-b);margin-bottom:14px">
    All dispatched trips must already be Completed or Cancelled. Budget is informational — over-budget generates a warning, never blocks.
  </div>
  <div class="trn-form-group"><label>Notes</label><input type="text" id="ecplNotes"></div>
  <button class="trn-btn trn-btn-primary" onclick="TRN.confirmCompleteEvent()" style="width:100%">Complete Event</button>
</div></div>

<!-- Cancel Event Modal -->
<div class="trn-modal-bg" id="eventCancelModal"><div class="trn-modal" style="width:440px">
  <div class="trn-modal-title">
    <span>Cancel Event</span>
    <button class="trn-modal-close" onclick="TRN.closeCancelEvent()">&times;</button>
  </div>
  <input type="hidden" id="ecclEventId">
  <div style="background:rgba(220,38,38,.08);border-left:3px solid #dc2626;padding:10px 12px;border-radius:4px;font-size:12px;color:var(--t1);font-family:var(--font-b);margin-bottom:14px">
    Fires URGENT notification to every roster parent (safety-adjacent — never deduped).
  </div>
  <div class="trn-form-group"><label>Reason *</label><input type="text" id="ecclReason"></div>
  <button class="trn-btn trn-btn-danger" onclick="TRN.confirmCancelEvent()" style="width:100%">Cancel Event</button>
</div></div>
