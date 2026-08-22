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
  transport/partials/driver_manage_modal.php
  F4 Driver Management (2026-07-07)

  Sub-tabbed modal for managing one driver:
    Profile   — driver_meta CRUD (licence, medical, badge, availability)
    Incidents — recent incidents + resolve
    Reviews   — performance reviews + save new
    Temp      — active temp assignments where this driver is covering
    Activity  — current-month activity snapshot

  Note: HR-owned staff fields (name, phone, role) are DISPLAY-ONLY here.
  Only driver_meta subobject is editable through this modal.
-->
<div class="trn-modal-bg" id="drvManageModal"><div class="trn-modal" style="width:820px;max-height:90vh">
  <div class="trn-modal-title">
    <span>Driver <span id="dmName" style="color:var(--gold)"></span></span>
    <button class="trn-modal-close" onclick="TRN.closeDrvManage()">&times;</button>
  </div>
  <input type="hidden" id="dmStaffId">

  <nav class="trn-tabs" style="margin-bottom:16px">
    <a href="#" class="trn-tab active" data-dmtab="profile"  onclick="TRN.dmSwitch('profile');return false"><i class="fa fa-user"></i> Profile</a>
    <a href="#" class="trn-tab" data-dmtab="incidents" onclick="TRN.dmSwitch('incidents');return false"><i class="fa fa-exclamation-triangle"></i> Incidents</a>
    <a href="#" class="trn-tab" data-dmtab="reviews"   onclick="TRN.dmSwitch('reviews');return false"><i class="fa fa-star"></i> Reviews</a>
    <a href="#" class="trn-tab" data-dmtab="temp"      onclick="TRN.dmSwitch('temp');return false"><i class="fa fa-clock-o"></i> Temp Assignments</a>
    <a href="#" class="trn-tab" data-dmtab="activity"  onclick="TRN.dmSwitch('activity');return false"><i class="fa fa-line-chart"></i> Activity</a>
  </nav>

  <!-- Profile (driver_meta) -->
  <div class="trn-panel active" data-dmpanel="profile">
    <div class="trn-card">
      <div class="trn-card-title"><span>HR Details (read-only)</span></div>
      <div style="font-family:var(--font-b);font-size:13px;color:var(--t3)">
        <div><b style="color:var(--t1)">Name:</b> <span id="dmHrName">—</span></div>
        <div><b style="color:var(--t1)">Phone:</b> <span id="dmHrPhone">—</span></div>
        <div><b style="color:var(--t1)">Role:</b> <span id="dmHrRole">—</span></div>
        <div style="font-size:11px;color:var(--t3);margin-top:8px;font-style:italic">HR owns these fields — edit via HR module.</div>
      </div>
    </div>
    <div class="trn-card">
      <div class="trn-card-title"><span>Transport Driver Meta</span></div>
      <div class="trn-form-row">
        <div class="trn-form-group"><label>Licence No.</label><input type="text" id="dmLicNo" <?= $can_manage?'':' disabled title="Requires Transport · manage"' ?>></div>
        <div class="trn-form-group"><label>Class</label><select id="dmLicClass" <?= $can_manage?'':' disabled title="Requires Transport · manage"' ?>><option value="">--</option><option>LMV</option><option>HMV</option><option>Bus</option><option>Auto</option><option>Multi</option></select></div>
      </div>
      <div class="trn-form-row">
        <div class="trn-form-group"><label>Licence Issued</label><input type="date" id="dmLicIssued" <?= $can_manage?'':' disabled title="Requires Transport · manage"' ?>></div>
        <div class="trn-form-group"><label>Licence Expires</label><input type="date" id="dmLicExpires" <?= $can_manage?'':' disabled title="Requires Transport · manage"' ?>></div>
      </div>
      <div class="trn-form-row">
        <div class="trn-form-group"><label>Issuing Authority</label><input type="text" id="dmLicAuth" <?= $can_manage?'':' disabled title="Requires Transport · manage"' ?>></div>
        <div class="trn-form-group"><label>Years Experience</label><input type="number" id="dmYearsExp" min="0" value="0"></div>
      </div>
      <div class="trn-form-row">
        <div class="trn-form-group"><label>Medical Last Check</label><input type="date" id="dmMedLast" <?= $can_manage?'':' disabled title="Requires Transport · manage"' ?>></div>
        <div class="trn-form-group"><label>Medical Expires</label><input type="date" id="dmMedExpires" <?= $can_manage?'':' disabled title="Requires Transport · manage"' ?>></div>
      </div>
      <div class="trn-form-row">
        <div class="trn-form-group"><label>Badge No.</label><input type="text" id="dmBadgeNo" <?= $can_manage?'':' disabled title="Requires Transport · manage"' ?>></div>
        <div class="trn-form-group"><label>Badge Expires</label><input type="date" id="dmBadgeExpires" <?= $can_manage?'':' disabled title="Requires Transport · manage"' ?>></div>
      </div>
      <div class="trn-form-row">
        <div class="trn-form-group"><label>Can Drive Types (comma)</label><input type="text" id="dmCanDrive" placeholder="Bus,Van,Auto,Car" <?= $can_manage?'':' disabled title="Requires Transport · manage"' ?>></div>
        <div class="trn-form-group"><label>Operational Status</label><select id="dmOpStatus" <?= $can_manage?'':' disabled title="Requires Transport · manage"' ?>><option value="Available">Available</option><option value="Unavailable">Unavailable</option><option value="On_Leave">On Leave</option><option value="Medical_Leave">Medical Leave</option><option value="Training">Training</option><option value="Suspended">Suspended</option></select></div>
      </div>
      <div class="trn-form-row">
        <div class="trn-form-group"><label>Emergency Contact Name</label><input type="text" id="dmEmerName"></div>
        <div class="trn-form-group"><label>Emergency Contact Phone</label><input type="text" id="dmEmerPhone"></div>
      </div>
      <div class="trn-form-group">
        <label>Replacement Availability</label>
        <select id="dmReplAvail"><option value="0">No</option><option value="1">Yes — willing to cover other vehicles temporarily</option></select>
      </div>
      <button class="trn-btn trn-btn-primary" onclick="TRN.dmSaveMeta()" <?= $can_edit?'':'disabled' ?> style="width:100%">Save Driver Meta</button>
    </div>
  </div>

  <!-- Incidents -->
  <div class="trn-panel" data-dmpanel="incidents" style="display:none">
    <div class="trn-card">
      <div class="trn-card-title"><span>Recent Incidents</span><button class="trn-btn trn-btn-primary trn-btn-sm" onclick="TRN.openIncident()"><i class="fa fa-plus"></i> Record Incident</button></div>
      <table class="trn-table"><thead><tr><th>Date</th><th>Type</th><th>Severity</th><th>Description</th><th>Resolved</th><th>Actions</th></tr></thead><tbody id="dmIncidentsTbody"></tbody></table>
    </div>
  </div>

  <!-- Reviews -->
  <div class="trn-panel" data-dmpanel="reviews" style="display:none">
    <div class="trn-card">
      <div class="trn-card-title"><span>Performance Reviews</span><button class="trn-btn trn-btn-primary trn-btn-sm" onclick="TRN.dmOpenReviewForm()"><i class="fa fa-plus"></i> New Review</button></div>
      <div id="dmReviewForm" style="display:none;padding:12px;background:var(--bg);border-radius:6px;margin-bottom:12px">
        <div class="trn-form-row">
          <div class="trn-form-group"><label>Period Start *</label><input type="date" id="dmRvStart"></div>
          <div class="trn-form-group"><label>Period End *</label><input type="date" id="dmRvEnd"></div>
        </div>
        <div class="trn-form-row">
          <div class="trn-form-group"><label>Rating (1-5)</label><select id="dmRvRating"><option>3</option><option>1</option><option>2</option><option>4</option><option>5</option></select></div>
          <div class="trn-form-group"><label>Next Review Due</label><input type="date" id="dmRvNext"></div>
        </div>
        <div class="trn-form-group"><label>Notes</label><input type="text" id="dmRvNotes"></div>
        <div class="trn-form-group"><label>Action Items (comma-separated)</label><input type="text" id="dmRvActions"></div>
        <div style="font-size:11px;color:var(--t3);margin-bottom:8px;font-family:var(--font-b)">Numeric fields (trips, km, incidents, on-time %) auto-populate from activity snapshot for the period.</div>
        <button class="trn-btn trn-btn-primary" onclick="TRN.dmSaveReview()" <?= $can_manage?'':'disabled title="Requires Transport · manage"' ?> style="width:100%">Save Review</button>
      </div>
      <table class="trn-table"><thead><tr><th>Period</th><th>Trips</th><th>KM</th><th>Incidents</th><th>Rating</th><th>Reviewer</th></tr></thead><tbody id="dmReviewsTbody"></tbody></table>
    </div>
  </div>

  <!-- Temp Assignments -->
  <div class="trn-panel" data-dmpanel="temp" style="display:none">
    <div class="trn-card">
      <div class="trn-card-title"><span>Temporary Assignments</span><button class="trn-btn trn-btn-primary trn-btn-sm" onclick="TRN.dmOpenTempAssign()"><i class="fa fa-plus"></i> Assign Temp</button></div>
      <div id="dmTempForm" style="display:none;padding:12px;background:var(--bg);border-radius:6px;margin-bottom:12px">
        <div class="trn-form-group"><label>Vehicle *</label><select id="dmTaVehicle"></select></div>
        <div class="trn-form-row">
          <div class="trn-form-group"><label>Effective From *</label><input type="date" id="dmTaFrom"></div>
          <div class="trn-form-group"><label>Effective To *</label><input type="date" id="dmTaTo"></div>
        </div>
        <div class="trn-form-group"><label>Reason *</label><input type="text" id="dmTaReason"></div>
        <div class="trn-form-group"><label>Notes</label><input type="text" id="dmTaNotes"></div>
        <button class="trn-btn trn-btn-primary" onclick="TRN.dmSaveTemp()" <?= $can_manage?'':'disabled title="Requires Transport · manage"' ?> style="width:100%">Assign Temporary</button>
      </div>
      <table class="trn-table"><thead><tr><th>Vehicle</th><th>From</th><th>To</th><th>Reason</th><th>Status</th><th>Actions</th></tr></thead><tbody id="dmTempTbody"></tbody></table>
    </div>
  </div>

  <!-- Activity -->
  <div class="trn-panel" data-dmpanel="activity" style="display:none">
    <div class="trn-card">
      <div class="trn-card-title"><span>Current-Month Activity Snapshot</span><button class="trn-btn trn-btn-primary trn-btn-sm" onclick="TRN.dmRebuildActivity()"><i class="fa fa-refresh"></i> Rebuild</button></div>
      <div id="dmActivityBody" style="font-family:var(--font-b);font-size:13px;color:var(--t1)">Loading...</div>
    </div>
  </div>
</div></div>
