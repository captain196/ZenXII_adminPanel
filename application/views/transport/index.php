<?php
$at = isset($active_tab) ? $active_tab : 'vehicles';
$tab_map = [
    'vehicles'    => ['panel'=>'panelVehicles',    'icon'=>'fa-bus',           'label'=>'Vehicles'],
    'vendors'     => ['panel'=>'panelVendors',     'icon'=>'fa-handshake-o',   'label'=>'Vendors'],
    'drivers'     => ['panel'=>'panelDrivers',     'icon'=>'fa-id-card-o',     'label'=>'Drivers'],
    'expenses'    => ['panel'=>'panelExpenses',    'icon'=>'fa-money',         'label'=>'Expenses'],
    'sos'         => ['panel'=>'panelSos',         'icon'=>'fa-bell',          'label'=>'SOS Monitor'],
    'expiry'      => ['panel'=>'panelExpiry',      'icon'=>'fa-exclamation-triangle', 'label'=>'Expiry Dashboard'],
    'routes'      => ['panel'=>'panelRoutes',      'icon'=>'fa-road',          'label'=>'Routes & Stops'],
    'assignments' => ['panel'=>'panelAssignments',  'icon'=>'fa-users',         'label'=>'Assignments'],
];
?>
<style>
.trn-wrap{padding:20px;max-width:1400px;margin:0 auto}
.trn-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.trn-header-icon{font-family:var(--font-b);font-size:1.3rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px}
.trn-header-icon i{color:var(--gold);font-size:1.1rem}
.trn-breadcrumb{list-style:none;display:flex;gap:6px;font-size:12px;color:var(--t3);padding:0;margin:6px 0 0;font-family:var(--font-b)}
.trn-breadcrumb a{color:var(--gold);text-decoration:none}
.trn-breadcrumb li+li::before{content:"›";margin-right:6px;color:var(--t4)}
.trn-tabs{display:flex;gap:4px;margin-bottom:24px;border-bottom:1px solid var(--border);overflow-x:auto}
.trn-tab{padding:10px 16px;font-size:13px;font-weight:600;color:var(--t3);text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap;transition:all var(--ease);font-family:var(--font-b)}
.trn-tab:hover{color:var(--t1)} .trn-tab.active{color:var(--gold);border-bottom-color:var(--gold)} .trn-tab i{margin-right:6px;font-size:14px}
.trn-panel{display:none} .trn-panel.active{display:block}
.trn-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:20px;margin-bottom:18px}
.trn-card-title{font-family:var(--font-b);font-size:14px;font-weight:700;color:var(--t1);margin-bottom:14px;display:flex;align-items:center;justify-content:space-between}
.trn-btn{padding:8px 16px;border-radius:var(--r-sm);font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all var(--ease);font-family:var(--font-b)}
.trn-btn-primary{background:var(--gold);color:#fff} .trn-btn-primary:hover{background:var(--gold2)}
.trn-btn-danger{background:var(--rose);color:#fff} .trn-btn-sm{padding:6px 12px;font-size:12px}
.trn-table{width:100%;border-collapse:collapse;font-size:13px;font-family:var(--font-b)}
.trn-table th,.trn-table td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--border)}
.trn-table th{color:var(--t3);font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
.trn-table td{color:var(--t1)} .trn-table tr:hover td{background:var(--gold-dim)}
.trn-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;font-family:var(--font-b)}
.trn-badge-green{background:rgba(34,197,94,.12);color:#22c55e}
.trn-badge-blue{background:rgba(59,130,246,.12);color:#3b82f6}
.trn-badge-amber{background:rgba(245,158,11,.12);color:#f59e0b}
.trn-badge-red{background:rgba(239,68,68,.12);color:#ef4444}
.trn-form-group{margin-bottom:14px}
.trn-form-group label{display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:4px;font-family:var(--font-b);text-transform:uppercase;letter-spacing:.3px}
.trn-form-group input,.trn-form-group select{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--t1);font-size:13px;font-family:var(--font-b)}
.trn-form-group input:focus,.trn-form-group select:focus{border-color:var(--gold);outline:none}
.trn-form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.trn-modal-bg{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.55);z-index:9999;display:none;align-items:center;justify-content:center}
.trn-modal-bg.show{display:flex}
.trn-modal{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);width:560px;max-width:95vw;max-height:85vh;overflow-y:auto;padding:24px}
.trn-modal-title{font-family:var(--font-b);font-size:16px;font-weight:700;color:var(--t1);margin-bottom:18px;display:flex;align-items:center;justify-content:space-between}
.trn-modal-close{cursor:pointer;color:var(--t3);font-size:1.2rem;background:none;border:none}
.trn-search-box{position:relative}
.trn-search-results{position:absolute;top:100%;left:0;right:0;background:var(--bg2);border:1px solid var(--border);border-radius:0 0 var(--r-sm) var(--r-sm);max-height:200px;overflow-y:auto;z-index:100;display:none}
.trn-search-results.show{display:block}
.trn-search-item{padding:8px 12px;cursor:pointer;font-size:13px;color:var(--t1);border-bottom:1px solid var(--border);font-family:var(--font-b)}
.trn-search-item:hover{background:var(--gold-dim)}
</style>

<div class="content-wrapper"><section class="content"><div class="trn-wrap">
  <div class="trn-header"><div>
    <div class="trn-header-icon"><i class="fa fa-bus"></i> Transport Management</div>
    <ol class="trn-breadcrumb"><li><a href="<?= base_url('admin') ?>">Dashboard</a></li><li><a href="<?= base_url('operations') ?>">Operations</a></li><li>Transport</li></ol>
  </div></div>

  <nav class="trn-tabs">
    <?php foreach ($tab_map as $slug => $t): ?>
    <a class="trn-tab<?= $at === $slug ? ' active' : '' ?>" href="<?= base_url('transport/' . $slug) ?>"><i class="fa <?= $t['icon'] ?>"></i> <?= $t['label'] ?></a>
    <?php endforeach; ?>
  </nav>

  <!-- VEHICLES -->
  <div class="trn-panel<?= $at === 'vehicles' ? ' active' : '' ?>" id="panelVehicles">
    <div class="trn-card">
      <div class="trn-card-title"><span>Vehicles</span><button class="trn-btn trn-btn-primary" onclick="TRN.openVehModal()"><i class="fa fa-plus"></i> Add Vehicle</button></div>
      <table class="trn-table"><thead><tr><th>ID</th><th>Number</th><th>Type</th><th>Capacity</th><th>Driver</th><th>Ownership</th><th>Lifecycle</th><th>Actions</th></tr></thead><tbody id="vehTbody"></tbody></table>
    </div>
  </div>

  <!-- VENDORS (F1) -->
  <div class="trn-panel<?= $at === 'vendors' ? ' active' : '' ?>" id="panelVendors">
    <div class="trn-card">
      <div class="trn-card-title"><span>Transport Vendors</span><button class="trn-btn trn-btn-primary" onclick="TRN.openVendorModal()"><i class="fa fa-plus"></i> Add Vendor</button></div>
      <table class="trn-table"><thead><tr><th>ID</th><th>Name</th><th>Contact</th><th>Phone</th><th>GSTIN</th><th>Status</th><th>Actions</th></tr></thead><tbody id="vendorsTbody"></tbody></table>
    </div>
  </div>

  <!-- DRIVERS (F4) -->
  <div class="trn-panel<?= $at === 'drivers' ? ' active' : '' ?>" id="panelDrivers">
    <div class="trn-card">
      <div class="trn-card-title">
        <span>Drivers</span>
        <div style="display:flex;gap:6px">
          <button class="trn-btn trn-btn-primary trn-btn-sm" onclick="TRN.dmRebuildActivityAll()" title="Rebuild all activity summaries for current month"><i class="fa fa-refresh"></i> Rebuild Activity</button>
        </div>
      </div>
      <div style="background:rgba(59,130,246,.08);border-left:3px solid #3b82f6;padding:10px 12px;border-radius:4px;font-size:12px;color:var(--t1);font-family:var(--font-b);margin-bottom:14px">
        Drivers are managed in HR. This surface exposes Transport-specific meta (licence, medical, badge, availability), incidents, reviews and temp-cover assignments. Comprehensive readiness validation applies to every driver-vehicle assignment.
      </div>
      <table class="trn-table"><thead><tr><th>Staff ID</th><th>Name</th><th>Phone</th><th>Licence Expires</th><th>Medical Expires</th><th>Op. Status</th><th>Actions</th></tr></thead><tbody id="driversTbody"></tbody></table>
    </div>
  </div>

  <!-- EXPENSES (F5) -->
  <div class="trn-panel<?= $at === 'expenses' ? ' active' : '' ?>" id="panelExpenses">
    <div class="trn-card">
      <div class="trn-card-title">
        <span>Transport Expenses</span>
        <div style="display:flex;gap:6px">
          <button class="trn-btn trn-btn-primary trn-btn-sm" onclick="TRN.reconcilePendingPosts()" title="Retry pending Accounting posts"><i class="fa fa-wrench"></i> Reconcile Pending</button>
          <button class="trn-btn trn-btn-primary trn-btn-sm" onclick="TRN.rebuildExpenseSummary()" title="Rebuild monthly summary"><i class="fa fa-refresh"></i> Rebuild Summary</button>
          <button class="trn-btn trn-btn-primary" onclick="TRN.openExpense()"><i class="fa fa-plus"></i> Record Expense</button>
        </div>
      </div>
      <div style="background:rgba(59,130,246,.08);border-left:3px solid #3b82f6;padding:10px 12px;border-radius:4px;font-size:12px;color:var(--t1);font-family:var(--font-b);margin-bottom:14px">
        Posting to Accounting follows the school's <code>schoolControl.transport.expense_posting_mode</code> (default 'manual'). Auto mode posts immediately; manual mode leaves the expense unpostpated until admin clicks "Post Now" or ticks "Force Post" on save.
      </div>
      <div id="expensesSummaryBar" style="margin-bottom:14px"></div>
      <table class="trn-table"><thead><tr><th>ID</th><th>Date</th><th>Type</th><th>Vehicle</th><th>Amount</th><th>Payee</th><th>Accounting</th><th>Status</th><th>Actions</th></tr></thead><tbody id="expensesTbody"></tbody></table>
    </div>
  </div>

  <!-- SOS MONITOR (F6) -->
  <div class="trn-panel<?= $at === 'sos' ? ' active' : '' ?>" id="panelSos">
    <div class="trn-card">
      <div class="trn-card-title">
        <span>SOS Alerts</span>
        <div style="display:flex;gap:6px">
          <select id="sosStatusFilter" onchange="TRN.loadSos()" class="trn-btn trn-btn-sm" style="background:var(--bg);border:1px solid var(--border);color:var(--t1);cursor:pointer">
            <option value="">All statuses</option>
            <option value="active" selected>Active only</option>
            <option value="responded">Responded</option>
            <option value="resolved">Resolved</option>
          </select>
          <button class="trn-btn trn-btn-danger" onclick="TRN.openRaiseSos()"><i class="fa fa-exclamation-triangle"></i> Raise SOS</button>
        </div>
      </div>
      <div style="background:rgba(220,38,38,.06);border-left:3px solid #dc2626;padding:10px 12px;border-radius:4px;font-size:12px;color:var(--t1);font-family:var(--font-b);margin-bottom:14px">
        F6 admin-fired SOS MVP. Teacher-app SOS trigger path lands in F9. Escalation is manual only — architecture stays open for future auto-escalation without redesign.
      </div>
      <table class="trn-table">
        <thead><tr><th>ID</th><th>Raised</th><th>Type</th><th>Vehicle</th><th>Route</th><th>Message</th><th>Status</th><th>Responder</th><th>Actions</th></tr></thead>
        <tbody id="sosTbody"></tbody>
      </table>
    </div>
  </div>

  <!-- EXPIRY DASHBOARD (F1) -->
  <div class="trn-panel<?= $at === 'expiry' ? ' active' : '' ?>" id="panelExpiry">
    <div class="trn-card">
      <div class="trn-card-title">
        <span>Fleet Expiry Dashboard</span>
        <button class="trn-btn trn-btn-primary trn-btn-sm" onclick="TRN.recomputeExpiry()"><i class="fa fa-refresh"></i> Recompute Now</button>
      </div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:16px">
        <div class="trn-card" style="text-align:center;margin:0;border-left:4px solid var(--rose)">
          <div style="font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.3px">Expiring in 7 days</div>
          <div style="font-size:2.4rem;font-weight:700;font-family:var(--font-b);color:var(--rose)" id="exp7">—</div>
        </div>
        <div class="trn-card" style="text-align:center;margin:0;border-left:4px solid #f59e0b">
          <div style="font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.3px">Expiring in 15 days</div>
          <div style="font-size:2.4rem;font-weight:700;font-family:var(--font-b);color:#f59e0b" id="exp15">—</div>
        </div>
        <div class="trn-card" style="text-align:center;margin:0;border-left:4px solid #3b82f6">
          <div style="font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.3px">Expiring in 30 days</div>
          <div style="font-size:2.4rem;font-weight:700;font-family:var(--font-b);color:#3b82f6" id="exp30">—</div>
        </div>
      </div>
      <div id="expLastRun" style="font-size:12px;color:var(--t3);margin-bottom:10px;font-family:var(--font-b)"></div>
      <table class="trn-table"><thead><tr><th>Vehicle</th><th>Item</th><th>Expiry</th><th>Days Left</th><th>Window</th></tr></thead>
      <tbody id="expTbody"></tbody></table>
    </div>
  </div>

  <!-- ROUTES & STOPS -->
  <div class="trn-panel<?= $at === 'routes' ? ' active' : '' ?>" id="panelRoutes">
    <!-- F2 (2026-07-07): active suspensions banner (rendered above routes) -->
    <?php $this->load->view('transport/partials/route_suspension_list'); ?>

    <div class="trn-card">
      <div class="trn-card-title">
        <span>Routes</span>
        <div style="display:flex;gap:6px;align-items:center">
          <label style="font-size:12px;color:var(--t3);font-family:var(--font-b);cursor:pointer">
            <input type="checkbox" id="routeShowArchived" onchange="loadRoutes()"> Show Archived
          </label>
          <button class="trn-btn trn-btn-primary" onclick="TRN.openRouteModal()"><i class="fa fa-plus"></i> Add Route</button>
        </div>
      </div>
      <table class="trn-table"><thead><tr><th>ID</th><th>Name</th><th>Vehicle</th><th>Distance</th><th>Monthly Fee</th><th>Status</th><th>Actions</th></tr></thead><tbody id="routesTbody"></tbody></table>
    </div>
    <div class="trn-card">
      <div class="trn-card-title"><span>Stops</span><button class="trn-btn trn-btn-primary" onclick="TRN.openStopModal()"><i class="fa fa-plus"></i> Add Stop</button></div>
      <div class="trn-form-group" style="max-width:300px;margin-bottom:14px">
        <label>Filter by Route</label><select id="stopRouteFilter"><option value="">All Routes</option></select>
      </div>
      <table class="trn-table"><thead><tr><th>ID</th><th>Route</th><th>Stop Name</th><th>Pickup</th><th>Drop</th><th>Order</th><th>Actions</th></tr></thead><tbody id="stopsTbody"></tbody></table>
    </div>
  </div>

  <!-- ASSIGNMENTS (F3 extended) -->
  <div class="trn-panel<?= $at === 'assignments' ? ' active' : '' ?>" id="panelAssignments">
    <div class="trn-card">
      <div class="trn-card-title">
        <span>Student Transport Assignments</span>
        <div style="display:flex;gap:6px">
          <button class="trn-btn trn-btn-primary trn-btn-sm" onclick="TRN.openBulkAssign()"><i class="fa fa-list"></i> Bulk Assign</button>
          <button class="trn-btn trn-btn-primary trn-btn-sm" onclick="TRN.openRollover()"><i class="fa fa-refresh"></i> Session Rollover</button>
          <button class="trn-btn trn-btn-primary trn-btn-sm" onclick="TRN.reconcilePendingFees()" title="Retry any pending fee demands"><i class="fa fa-wrench"></i> Reconcile Fees</button>
          <button class="trn-btn trn-btn-primary trn-btn-sm" onclick="TRN.rebuildCapacityCache()" title="Rebuild capacity cache from source"><i class="fa fa-database"></i> Rebuild Cache</button>
          <button class="trn-btn trn-btn-primary" onclick="TRN.openAsnModal()"><i class="fa fa-plus"></i> Assign Student</button>
        </div>
      </div>
      <table class="trn-table"><thead><tr>
        <th>Student</th><th>Class</th><th>Route</th><th>Stop</th><th>Subscription</th><th>Fee/Month</th><th>Status</th><th>Actions</th>
      </tr></thead><tbody id="asnTbody"></tbody></table>
    </div>
  </div>
</div></section></div>

<!-- VEHICLE MODAL (F1 extended fields) -->
<div class="trn-modal-bg" id="vehModal"><div class="trn-modal" style="width:720px">
  <div class="trn-modal-title"><span id="vehModalTitle">Add Vehicle</span><button class="trn-modal-close" onclick="TRN.closeVehModal()">&times;</button></div>
  <input type="hidden" id="vhId">
  <div class="trn-form-row"><div class="trn-form-group"><label>Vehicle Number *</label><input type="text" id="vhNumber"></div><div class="trn-form-group"><label>Type</label><select id="vhType"><option>Bus</option><option>Van</option><option>Auto</option><option>Car</option></select></div></div>
  <div class="trn-form-row"><div class="trn-form-group"><label>Capacity</label><input type="number" id="vhCapacity" value="40" min="1"></div><div class="trn-form-group"><label>Fuel Type</label><select id="vhFuelType"><option>Diesel</option><option>Petrol</option><option>CNG</option><option>Electric</option><option>Hybrid</option></select></div></div>

  <!-- HR-linked driver: staff_id is the canonical FK; driver_name/phone are
       historical snapshots taken at save time. -->
  <div class="trn-form-row"><div class="trn-form-group"><label>Driver Staff ID (HR link)</label><input type="text" id="vhStaffId" placeholder="STA0001 (optional)"></div><div class="trn-form-group"><label>Conductor Staff ID</label><input type="text" id="vhConductorStaffId" placeholder="STA0002 (optional)"></div></div>
  <div class="trn-form-row"><div class="trn-form-group"><label>Driver Name (fallback if no staff)</label><input type="text" id="vhDriverName"></div><div class="trn-form-group"><label>Driver Phone (fallback if no staff)</label><input type="text" id="vhDriverPhone"></div></div>

  <!-- Ownership + vendor (Contract requires vendor_id) -->
  <div class="trn-form-row"><div class="trn-form-group"><label>Ownership</label><select id="vhOwnership" onchange="TRN.vhToggleContract()"><option value="School">School</option><option value="Contract">Contract</option></select></div><div class="trn-form-group"><label>Vendor (if Contract)</label><select id="vhVendorId"><option value="">-- None --</option></select></div></div>
  <div class="trn-form-row" id="vhContractRow" style="display:none">
    <div class="trn-form-group"><label>Monthly Hire (Rs)</label><input type="number" id="vhHire" min="0" step="0.01" value="0"></div>
    <div class="trn-form-group"><label>Contract Start</label><input type="date" id="vhContractStart"></div>
  </div>
  <div class="trn-form-row" id="vhContractRow2" style="display:none">
    <div class="trn-form-group"><label>Contract End</label><input type="date" id="vhContractEnd"></div>
    <div class="trn-form-group"></div>
  </div>

  <!-- Statutory documents (top-level, quick fields) -->
  <div class="trn-form-row"><div class="trn-form-group"><label>Insurance No.</label><input type="text" id="vhInsNo"></div><div class="trn-form-group"><label>Insurance Expiry</label><input type="date" id="vhInsExp"></div></div>
  <div class="trn-form-row"><div class="trn-form-group"><label>Permit No.</label><input type="text" id="vhPermitNo"></div><div class="trn-form-group"><label>Permit Expiry</label><input type="date" id="vhPermitExp"></div></div>
  <div class="trn-form-row"><div class="trn-form-group"><label>Fitness Expiry</label><input type="date" id="vhFitExp"></div><div class="trn-form-group"><label>Pollution (PUC) Expiry</label><input type="date" id="vhPollutionExp"></div></div>
  <div class="trn-form-row"><div class="trn-form-group"><label>Road Tax Expiry</label><input type="date" id="vhRoadTaxExp"></div><div class="trn-form-group"><label>Service KM Interval</label><input type="number" id="vhSvcKmInt" min="0" value="10000"></div></div>

  <!-- GPS (F1 fields only; Phase 5 owns live position writes) -->
  <div class="trn-form-row"><div class="trn-form-group"><label>GPS Enabled</label><select id="vhGps"><option value="0">No</option><option value="1">Yes</option></select></div><div class="trn-form-group"><label>GPS Device ID</label><input type="text" id="vhGpsDeviceId" placeholder="(Phase 5 will populate live fields)"></div></div>

  <button class="trn-btn trn-btn-primary" onclick="TRN.saveVehicle()" style="width:100%;margin-top:8px">Save Vehicle</button>
</div></div>

<!-- VENDOR MODAL (F1) -->
<div class="trn-modal-bg" id="vendorModal"><div class="trn-modal" style="width:560px">
  <div class="trn-modal-title"><span id="vendorModalTitle">Add Vendor</span><button class="trn-modal-close" onclick="TRN.closeVendorModal()">&times;</button></div>
  <input type="hidden" id="vndId">
  <div class="trn-form-group"><label>Vendor Name *</label><input type="text" id="vndName"></div>
  <div class="trn-form-row"><div class="trn-form-group"><label>Contact Person</label><input type="text" id="vndContact"></div><div class="trn-form-group"><label>Phone</label><input type="text" id="vndPhone"></div></div>
  <div class="trn-form-row"><div class="trn-form-group"><label>Email</label><input type="email" id="vndEmail"></div><div class="trn-form-group"><label>GSTIN</label><input type="text" id="vndGstin"></div></div>
  <div class="trn-form-group"><label>Address</label><input type="text" id="vndAddress"></div>
  <div class="trn-form-group"><label>Notes</label><input type="text" id="vndNotes"></div>
  <button class="trn-btn trn-btn-primary" onclick="TRN.saveVendor()" style="width:100%;margin-top:8px">Save Vendor</button>
</div></div>

<!-- VENDOR TERMINATE MODAL (F1) -->
<div class="trn-modal-bg" id="vendorTerminateModal"><div class="trn-modal" style="width:440px">
  <div class="trn-modal-title"><span>Terminate Vendor</span><button class="trn-modal-close" onclick="TRN.closeVendorTerminate()">&times;</button></div>
  <input type="hidden" id="vndTermId">
  <div class="trn-form-group"><label>Reason *</label><input type="text" id="vndTermReason" placeholder="e.g. Contract not renewed"></div>
  <button class="trn-btn trn-btn-danger" onclick="TRN.confirmTerminateVendor()" style="width:100%">Terminate</button>
</div></div>

<!-- Vehicle Manage modal (partial) -->
<?php $this->load->view('transport/partials/vehicle_manage_modal'); ?>

<!-- F2 Suspension + Archive modals (partial) -->
<?php $this->load->view('transport/partials/route_suspension_modal'); ?>

<!-- F3 Bulk assign + history + change + suspend modals -->
<?php $this->load->view('transport/partials/bulk_assign_modal'); ?>

<!-- F3 Session rollover modal -->
<?php $this->load->view('transport/partials/rollover_modal'); ?>

<!-- F4 Driver management + incident modals -->
<?php $this->load->view('transport/partials/driver_manage_modal'); ?>
<?php $this->load->view('transport/partials/incident_modal'); ?>

<!-- F5 Expense modal -->
<?php $this->load->view('transport/partials/expense_modal'); ?>

<!-- F6 SOS modal (admin-fired MVP) -->
<?php $this->load->view('transport/partials/sos_modal'); ?>


<!-- ROUTE MODAL -->
<div class="trn-modal-bg" id="routeModal"><div class="trn-modal" style="width:480px">
  <div class="trn-modal-title"><span id="routeModalTitle">Add Route</span><button class="trn-modal-close" onclick="TRN.closeRouteModal()">&times;</button></div>
  <input type="hidden" id="rtId">
  <div class="trn-form-group"><label>Route Name *</label><input type="text" id="rtName"></div>
  <div class="trn-form-row"><div class="trn-form-group"><label>Vehicle</label><select id="rtVehicleId"><option value="">-- None --</option></select></div><div class="trn-form-group"><label>Monthly Fee (Rs)</label><input type="number" id="rtFee" value="0" min="0"></div></div>
  <div class="trn-form-row"><div class="trn-form-group"><label>Start Point</label><input type="text" id="rtStart"></div><div class="trn-form-group"><label>End Point</label><input type="text" id="rtEnd"></div></div>
  <div class="trn-form-group"><label>Distance (km)</label><input type="number" id="rtDistance" value="0" min="0" step="0.1"></div>
  <button class="trn-btn trn-btn-primary" onclick="TRN.saveRoute()" style="width:100%;margin-top:8px">Save Route</button>
</div></div>

<!-- STOP MODAL -->
<div class="trn-modal-bg" id="stopModal"><div class="trn-modal" style="width:420px">
  <div class="trn-modal-title"><span id="stopModalTitle">Add Stop</span><button class="trn-modal-close" onclick="TRN.closeStopModal()">&times;</button></div>
  <input type="hidden" id="stId">
  <div class="trn-form-group"><label>Route *</label><select id="stRouteId"></select></div>
  <div class="trn-form-group"><label>Stop Name *</label><input type="text" id="stName"></div>
  <div class="trn-form-row"><div class="trn-form-group"><label>Pickup Time</label><input type="time" id="stPickup"></div><div class="trn-form-group"><label>Drop Time</label><input type="time" id="stDrop"></div></div>
  <div class="trn-form-group"><label>Order</label><input type="number" id="stOrder" value="0" min="0"></div>
  <button class="trn-btn trn-btn-primary" onclick="TRN.saveStop()" style="width:100%;margin-top:8px">Save Stop</button>
</div></div>

<!-- ASSIGNMENT MODAL -->
<div class="trn-modal-bg" id="asnModal"><div class="trn-modal" style="width:480px;overflow:visible">
  <div class="trn-modal-title"><span>Assign Student</span><button class="trn-modal-close" onclick="TRN.closeAsnModal()">&times;</button></div>
  <div class="trn-form-group trn-search-box"><label>Student</label><input type="text" id="asnStudentSearch" placeholder="Search by name or ID..." autocomplete="off"><input type="hidden" id="asnStudentId"><div class="trn-search-results" id="asnStudentResults"></div></div>
  <div class="trn-form-group"><label>Route *</label><select id="asnRouteId"></select></div>
  <div class="trn-form-row"><div class="trn-form-group"><label>Stop</label><select id="asnStopId"><option value="">-- Select --</option></select></div><div class="trn-form-group"><label>Type</label><select id="asnType"><option value="both">Both (Pickup & Drop)</option><option value="pickup">Pickup Only</option><option value="drop">Drop Only</option></select></div></div>
  <button class="trn-btn trn-btn-primary" onclick="TRN.saveAssignment()" style="width:100%;margin-top:8px">Assign</button>
</div></div>

<script>
var _TRN_CFG={BASE:'<?= base_url() ?>',CSRF_NAME:'<?= $this->security->get_csrf_token_name() ?>',CSRF_HASH:'<?= $this->security->get_csrf_hash() ?>',activeTab:'<?= $at ?>'};
document.addEventListener('DOMContentLoaded', function(){
(function(){
  'use strict';
  var BASE=_TRN_CFG.BASE,CSRF_NAME=_TRN_CFG.CSRF_NAME,CSRF_HASH=_TRN_CFG.CSRF_HASH;
  var vehicles=[],routes=[],stops=[];
  function getJSON(u){return $.getJSON(BASE+u)} function post(u,d){d[CSRF_NAME]=CSRF_HASH;return $.post(BASE+u,d)}
  function toast(m,ok){var t=$('<div style="position:fixed;top:20px;right:20px;z-index:99999;padding:12px 20px;border-radius:8px;font-size:.85rem;color:#fff;background:'+(ok?'var(--green)':'var(--rose)')+'">'+m+'</div>');$('body').append(t);setTimeout(function(){t.fadeOut(300,function(){t.remove()})},3000)}
  function escH(s){return $('<span>').text(s||'').html()}
  function vehName(id){for(var i=0;i<vehicles.length;i++) if(vehicles[i].id===id) return vehicles[i].number; return id||'—'}
  function routeName(id){for(var i=0;i<routes.length;i++) if(routes[i].id===id) return routes[i].name; return id||'—'}

  window.TRN={};

  // ── Vehicles (F1 extended) ──
  var vendors=[];   // populated by loadVendors()
  function loadVehicles(){
    getJSON('transport/get_vehicles').done(function(r){
      if(r.status!=='success') return; vehicles=r.vehicles||[];
      var html=''; vehicles.forEach(function(v){
        var lc = v.lifecycle_status || 'Active';
        var lcCls = lc==='Active'?'trn-badge-green':lc==='Maintenance'?'trn-badge-amber':(lc==='Retired'||lc==='Disposed')?'trn-badge-red':'trn-badge-blue';
        var own = v.ownership_type || 'School';
        html+='<tr><td>'+escH(v.id)+'</td><td>'+escH(v.number)+'</td><td>'+escH(v.type)+'</td><td>'+v.capacity+'</td><td>'+escH(v.driver_name||'—')+(v.staff_id?' <small style="opacity:.6">('+escH(v.staff_id)+')</small>':'')+'</td>';
        html+='<td>'+escH(own)+(v.vendor_id?' <small style="opacity:.6">'+escH(v.vendor_id)+'</small>':'')+'</td>';
        html+='<td><span class="trn-badge '+lcCls+'">'+lc+'</span></td>';
        html+='<td>'+
          '<button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.editVeh(\''+v.id+'\')"><i class="fa fa-pencil"></i></button> '+
          '<button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.openVehManage(\''+v.id+'\')"><i class="fa fa-cog"></i> Manage</button> '+
          '<button class="trn-btn trn-btn-sm trn-btn-danger" onclick="TRN.delVeh(\''+v.id+'\')"><i class="fa fa-trash"></i></button>'+
          '</td></tr>';
      });
      $('#vehTbody').html(html||'<tr><td colspan="8" style="text-align:center;color:var(--t3)">No vehicles</td></tr>');
      var opts='<option value="">-- None --</option>'; vehicles.forEach(function(v){opts+='<option value="'+v.id+'">'+escH(v.number)+'</option>'});
      $('#rtVehicleId').html(opts);
      $('#vmReplVeh').html(opts);
    });
  }
  TRN.openVehModal=function(){
    $('#vhId,#vhNumber,#vhDriverName,#vhDriverPhone,#vhInsNo,#vhInsExp,#vhFitExp,#vhStaffId,#vhConductorStaffId,#vhPermitNo,#vhPermitExp,#vhPollutionExp,#vhRoadTaxExp,#vhGpsDeviceId,#vhContractStart,#vhContractEnd').val('');
    $('#vhCapacity').val(40);$('#vhType').val('Bus');$('#vhFuelType').val('Diesel');$('#vhGps').val('0');
    $('#vhOwnership').val('School');$('#vhVendorId').val('');$('#vhHire').val(0);$('#vhSvcKmInt').val(10000);
    TRN.vhToggleContract();
    $('#vehModalTitle').text('Add Vehicle');$('#vehModal').addClass('show');
  };
  TRN.closeVehModal=function(){$('#vehModal').removeClass('show')};
  TRN.editVeh=function(id){
    var v=vehicles.find(function(x){return x.id===id});if(!v)return;
    $('#vhId').val(v.id);$('#vhNumber').val(v.number);$('#vhType').val(v.type);$('#vhCapacity').val(v.capacity);
    $('#vhStaffId').val(v.staff_id||'');$('#vhConductorStaffId').val(v.conductor_staff_id||'');
    $('#vhDriverName').val(v.driver_name||'');$('#vhDriverPhone').val(v.driver_phone||'');
    $('#vhFuelType').val(v.fuel_type||'Diesel');
    $('#vhGps').val(v.gps_enabled?'1':'0');$('#vhGpsDeviceId').val(v.gps_device_id||'');
    $('#vhInsNo').val(v.insurance_no||'');$('#vhInsExp').val(v.insurance_expiry||'');
    $('#vhPermitNo').val(v.permit_no||'');$('#vhPermitExp').val(v.permit_expiry||'');
    $('#vhFitExp').val(v.fitness_expiry||'');$('#vhPollutionExp').val(v.pollution_expiry||'');
    $('#vhRoadTaxExp').val(v.road_tax_expiry||'');$('#vhSvcKmInt').val(v.service_km_interval||10000);
    $('#vhOwnership').val(v.ownership_type||'School');$('#vhVendorId').val(v.vendor_id||'');
    $('#vhHire').val(v.monthly_hire_amount||0);$('#vhContractStart').val(v.contract_start_date||'');$('#vhContractEnd').val(v.contract_end_date||'');
    TRN.vhToggleContract();
    $('#vehModalTitle').text('Edit Vehicle');$('#vehModal').addClass('show');
  };
  TRN.vhToggleContract=function(){var isC=$('#vhOwnership').val()==='Contract';$('#vhContractRow,#vhContractRow2').toggle(isC);};
  TRN.saveVehicle=function(){
    post('transport/save_vehicle',{
      id:$('#vhId').val(),number:$('#vhNumber').val(),type:$('#vhType').val(),capacity:$('#vhCapacity').val(),
      staff_id:$('#vhStaffId').val(),conductor_staff_id:$('#vhConductorStaffId').val(),
      driver_name:$('#vhDriverName').val(),driver_phone:$('#vhDriverPhone').val(),
      fuel_type:$('#vhFuelType').val(),
      gps_enabled:$('#vhGps').val(),gps_device_id:$('#vhGpsDeviceId').val(),
      insurance_no:$('#vhInsNo').val(),insurance_expiry:$('#vhInsExp').val(),
      permit_no:$('#vhPermitNo').val(),permit_expiry:$('#vhPermitExp').val(),
      fitness_expiry:$('#vhFitExp').val(),pollution_expiry:$('#vhPollutionExp').val(),
      road_tax_expiry:$('#vhRoadTaxExp').val(),service_km_interval:$('#vhSvcKmInt').val(),
      ownership_type:$('#vhOwnership').val(),vendor_id:$('#vhVendorId').val(),
      monthly_hire_amount:$('#vhHire').val(),contract_start_date:$('#vhContractStart').val(),contract_end_date:$('#vhContractEnd').val()
    }).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);TRN.closeVehModal();loadVehicles()}else toast(r.message,false)})
  };
  TRN.delVeh=function(id){if(!confirm('Delete vehicle?'))return;post('transport/delete_vehicle',{id:id}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);loadVehicles()}else toast(r.message,false)})};

  // ═════════════════════════════════════════════════════════════════════
  //  Vendors (F1) — CRUD + terminate
  // ═════════════════════════════════════════════════════════════════════
  function loadVendors(){
    getJSON('transport_vendors/get_vendors').done(function(r){
      if(r.status!=='success') return; vendors=r.vendors||[];
      var html='';
      vendors.forEach(function(v){
        var cls = (v.status==='Active')?'trn-badge-green':'trn-badge-red';
        html+='<tr><td>'+escH(v.id)+'</td><td>'+escH(v.vendor_name)+'</td><td>'+escH(v.contact_person||'—')+'</td><td>'+escH(v.phone||'—')+'</td><td>'+escH(v.gstin||'—')+'</td>';
        html+='<td><span class="trn-badge '+cls+'">'+escH(v.status)+'</span></td>';
        html+='<td><button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.editVendor(\''+v.id+'\')"><i class="fa fa-pencil"></i></button>';
        if(v.status==='Active') html+=' <button class="trn-btn trn-btn-sm trn-btn-danger" onclick="TRN.openVendorTerminate(\''+v.id+'\')"><i class="fa fa-ban"></i> Terminate</button>';
        html+='</td></tr>';
      });
      $('#vendorsTbody').html(html||'<tr><td colspan="7" style="text-align:center;color:var(--t3)">No vendors</td></tr>');
      // Also refresh the vendor picker inside the vehicle modal
      var opts='<option value="">-- None --</option>';
      vendors.filter(function(v){return v.status==='Active'}).forEach(function(v){opts+='<option value="'+v.id+'">'+escH(v.vendor_name)+' ('+v.id+')</option>'});
      $('#vhVendorId').html(opts);
    });
  }
  TRN.openVendorModal=function(){$('#vndId,#vndName,#vndContact,#vndPhone,#vndEmail,#vndGstin,#vndAddress,#vndNotes').val('');$('#vendorModalTitle').text('Add Vendor');$('#vendorModal').addClass('show')};
  TRN.closeVendorModal=function(){$('#vendorModal').removeClass('show')};
  TRN.editVendor=function(id){var v=vendors.find(function(x){return x.id===id});if(!v)return;$('#vndId').val(v.id);$('#vndName').val(v.vendor_name);$('#vndContact').val(v.contact_person||'');$('#vndPhone').val(v.phone||'');$('#vndEmail').val(v.email||'');$('#vndGstin').val(v.gstin||'');$('#vndAddress').val(v.address||'');$('#vndNotes').val(v.notes||'');$('#vendorModalTitle').text('Edit Vendor');$('#vendorModal').addClass('show')};
  TRN.saveVendor=function(){post('transport_vendors/save_vendor',{id:$('#vndId').val(),vendor_name:$('#vndName').val(),contact_person:$('#vndContact').val(),phone:$('#vndPhone').val(),email:$('#vndEmail').val(),gstin:$('#vndGstin').val(),address:$('#vndAddress').val(),notes:$('#vndNotes').val()}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);TRN.closeVendorModal();loadVendors()}else toast(r.message,false)})};
  TRN.openVendorTerminate=function(id){$('#vndTermId').val(id);$('#vndTermReason').val('');$('#vendorTerminateModal').addClass('show')};
  TRN.closeVendorTerminate=function(){$('#vendorTerminateModal').removeClass('show')};
  TRN.confirmTerminateVendor=function(){var id=$('#vndTermId').val();var reason=$('#vndTermReason').val();if(!reason){toast('Reason is required.',false);return}post('transport_vendors/terminate_vendor',{id:id,reason:reason}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);TRN.closeVendorTerminate();loadVendors()}else toast(r.message,false)})};

  // ═════════════════════════════════════════════════════════════════════
  //  Vehicle Manage modal (F1) — Lifecycle, Docs, Maintenance, Fuel,
  //  Replacement, Transfer.
  // ═════════════════════════════════════════════════════════════════════
  var vmCurrentVid = '';
  var LC_STATES = ['Purchased','Onboarding','Active','Maintenance','Retired','Disposed'];
  var LC_MATRIX = {
    Purchased:['Onboarding'],Onboarding:['Active','Retired'],
    Active:['Maintenance','Retired'],Maintenance:['Active','Retired'],
    Retired:['Disposed','Active'],Disposed:[]
  };
  TRN.openVehManage=function(vid){
    vmCurrentVid = vid;
    var v = vehicles.find(function(x){return x.id===vid});if(!v)return;
    $('#vmVehLabel').text(v.number+' ('+vid+')');
    $('#vmLifecycleNow').text(v.lifecycle_status||'Active');
    // Populate the "Transition To" dropdown from legal states
    var opts = '<option value="">-- Select --</option>';
    (LC_MATRIX[v.lifecycle_status||'Active']||[]).forEach(function(s){opts+='<option value="'+s+'">'+s+'</option>'});
    $('#vmToState').html(opts);
    // Populate route dropdowns for Transfer
    var rOpts = '<option value="">-- Select --</option>';
    (routes||[]).forEach(function(r){rOpts+='<option value="'+r.id+'">'+escH(r.name)+'</option>'});
    $('#vmXferFrom').html(rOpts);$('#vmXferTo').html(rOpts);
    // Reset input fields
    $('#vmReason,#vmDocType,#vmDocNumber,#vmDocIssue,#vmDocExpiry,#vmDocNotes,#vmMaintWorkshop,#vmMaintParts,#vmMaintNotes,#vmMaintNextDate,#vmFuelStation,#vmFuelNotes,#vmReplReason,#vmReplNotes,#vmXferReason').val('');
    $('#vmMaintKm,#vmMaintCost,#vmFuelOdo,#vmFuelLitres,#vmFuelPpl,#vmFuelTotal').val('');
    $('#vmMaintType').val('Routine');$('#vmReplType').val('Temporary');
    var today = new Date().toISOString().slice(0,10);
    $('#vmMaintDate,#vmFuelDate,#vmReplFrom').val(today);
    $('#vmFuelKmpl').text('');
    // Default to lifecycle tab
    TRN.vmSwitch('lifecycle');
    // Load lifecycle + docs + maint + fuel
    TRN.vmLoadLifecycle();
    TRN.vmLoadDocs();
    TRN.vmLoadMaint();
    TRN.vmLoadFuel();
    $('#vehManageModal').addClass('show');
  };
  TRN.closeVehManage=function(){$('#vehManageModal').removeClass('show'); vmCurrentVid=''; loadVehicles();};
  TRN.vmSwitch=function(t){
    $('[data-vmtab]').removeClass('active');
    $('[data-vmtab="'+t+'"]').addClass('active');
    $('[data-vmpanel]').hide();
    $('[data-vmpanel="'+t+'"]').show();
  };
  TRN.vmLoadLifecycle=function(){
    if(!vmCurrentVid) return;
    getJSON('transport/get_lifecycle_log?vehicle_id='+encodeURIComponent(vmCurrentVid)).done(function(r){
      if(r.status!=='success') return;
      var html='';
      (r.events||[]).forEach(function(e){
        html+='<tr><td>'+escH((e.event_at||'').slice(0,16).replace('T',' '))+'</td><td>'+escH(e.event_type)+'</td><td>'+escH(e.prev_state)+' → '+escH(e.new_state)+'</td><td>'+escH(e.reason||'')+'</td><td>'+escH(e.event_by||'')+'</td></tr>';
      });
      $('#vmLifecycleTbody').html(html||'<tr><td colspan="5" style="text-align:center;color:var(--t3)">No events yet</td></tr>');
    });
  };
  TRN.vmTransition=function(){
    var to=$('#vmToState').val();var reason=$('#vmReason').val();
    if(!to){toast('Select a target state.',false);return}
    if(!reason){toast('Reason is required.',false);return}
    post('transport/transition_vehicle_lifecycle',{id:vmCurrentVid,to_state:to,reason:reason}).done(function(r){
      r=typeof r==='string'?JSON.parse(r):r;
      if(r.status==='success'){toast(r.message,true);TRN.vmLoadLifecycle();loadVehicles();$('#vmLifecycleNow').text(to);}
      else toast(r.message,false);
    });
  };
  TRN.vmLoadDocs=function(){
    if(!vmCurrentVid) return;
    getJSON('transport/get_vehicle_documents?vehicle_id='+encodeURIComponent(vmCurrentVid)).done(function(r){
      if(r.status!=='success') return;
      var html='';
      (r.documents||[]).forEach(function(d){
        var link=d.file_path?'<a href="'+escH(d.file_path)+'" target="_blank">Open</a>':'—';
        html+='<tr><td>'+escH(d.doc_type)+'</td><td>'+escH(d.doc_number||'')+'</td><td>'+escH(d.issue_date||'')+'</td><td>'+escH(d.expiry_date||'')+'</td><td>'+link+'</td>';
        html+='<td><button class="trn-btn trn-btn-sm trn-btn-danger" onclick="TRN.vmDeleteDoc(\''+d.id+'\')"><i class="fa fa-trash"></i></button></td></tr>';
      });
      $('#vmDocsTbody').html(html||'<tr><td colspan="6" style="text-align:center;color:var(--t3)">No documents</td></tr>');
    });
  };
  TRN.vmUploadDoc=function(){
    var type=$('#vmDocType').val();var file=$('#vmDocFile')[0].files[0];
    if(!type){toast('Type is required.',false);return}
    if(!file){toast('Select a file.',false);return}
    var fd = new FormData();
    fd.append('vehicle_id',vmCurrentVid);
    fd.append('doc_type',type);
    fd.append('doc_number',$('#vmDocNumber').val());
    fd.append('issue_date',$('#vmDocIssue').val());
    fd.append('expiry_date',$('#vmDocExpiry').val());
    fd.append('notes',$('#vmDocNotes').val());
    fd.append('file',file);
    fd.append(CSRF_NAME,CSRF_HASH);
    $.ajax({url:BASE+'transport/upload_vehicle_document',method:'POST',data:fd,processData:false,contentType:false}).done(function(r){
      r=typeof r==='string'?JSON.parse(r):r;
      if(r.status==='success'){toast(r.message,true);$('#vmDocType,#vmDocNumber,#vmDocIssue,#vmDocExpiry,#vmDocNotes').val('');$('#vmDocFile').val('');TRN.vmLoadDocs();}
      else toast(r.message,false);
    });
  };
  TRN.vmDeleteDoc=function(id){if(!confirm('Delete document?'))return;post('transport/delete_vehicle_document',{id:id}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);TRN.vmLoadDocs();}else toast(r.message,false)})};
  TRN.vmLoadMaint=function(){
    if(!vmCurrentVid) return;
    getJSON('transport_ops/get_maintenance_logs?vehicle_id='+encodeURIComponent(vmCurrentVid)).done(function(r){
      if(r.status!=='success') return;
      var html='';
      (r.logs||[]).forEach(function(l){
        html+='<tr><td>'+escH(l.service_date)+'</td><td>'+escH(l.service_type)+'</td><td>'+l.service_km+'</td><td>Rs '+l.cost+'</td><td>'+escH(l.workshop||'')+'</td><td>'+escH(l.notes||'')+'</td></tr>';
      });
      $('#vmMaintTbody').html(html||'<tr><td colspan="6" style="text-align:center;color:var(--t3)">No maintenance yet</td></tr>');
    });
  };
  TRN.vmSaveMaint=function(){
    post('transport_ops/save_maintenance_log',{
      vehicle_id:vmCurrentVid,service_type:$('#vmMaintType').val(),service_date:$('#vmMaintDate').val(),
      service_km:$('#vmMaintKm').val(),cost:$('#vmMaintCost').val(),workshop:$('#vmMaintWorkshop').val(),
      parts_replaced:$('#vmMaintParts').val(),next_service_date_projected:$('#vmMaintNextDate').val(),
      notes:$('#vmMaintNotes').val()
    }).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);TRN.vmLoadMaint();}else toast(r.message,false)});
  };
  TRN.vmLoadFuel=function(){
    if(!vmCurrentVid) return;
    getJSON('transport_ops/get_fuel_logs?vehicle_id='+encodeURIComponent(vmCurrentVid)).done(function(r){
      if(r.status!=='success') return;
      var html='';
      (r.logs||[]).forEach(function(l){
        html+='<tr><td>'+escH(l.fuel_date)+'</td><td>'+l.litres+'</td><td>Rs '+l.price_per_litre+'</td><td>Rs '+l.total_amount+'</td><td>'+l.odometer_km+'</td><td>'+(l.computed_kmpl||'—')+'</td></tr>';
      });
      $('#vmFuelTbody').html(html||'<tr><td colspan="6" style="text-align:center;color:var(--t3)">No fuel entries yet</td></tr>');
    });
  };
  TRN.vmSaveFuel=function(){
    post('transport_ops/save_fuel_log',{
      vehicle_id:vmCurrentVid,fuel_date:$('#vmFuelDate').val(),
      litres:$('#vmFuelLitres').val(),price_per_litre:$('#vmFuelPpl').val(),total_amount:$('#vmFuelTotal').val(),
      station:$('#vmFuelStation').val(),odometer_km:$('#vmFuelOdo').val(),notes:$('#vmFuelNotes').val()
    }).done(function(r){r=typeof r==='string'?JSON.parse(r):r;
      if(r.status==='success'){
        toast(r.message,true);
        if(r.computed_kmpl>0) $('#vmFuelKmpl').text('Rolling kmpl (last 5 entries): '+r.computed_kmpl);
        TRN.vmLoadFuel();
      }else toast(r.message,false);
    });
  };
  TRN.vmRecordReplacement=function(){
    var repl=$('#vmReplVeh').val();var type=$('#vmReplType').val();var from=$('#vmReplFrom').val();var to=$('#vmReplTo').val();var reason=$('#vmReplReason').val();
    if(!repl){toast('Select a replacement vehicle.',false);return}
    if(!reason){toast('Reason is required.',false);return}
    post('transport/record_replacement',{
      original_vehicle_id:vmCurrentVid,replacement_vehicle_id:repl,replacement_type:type,
      effective_from:from,effective_to:to,reason:reason,notes:$('#vmReplNotes').val()
    }).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);loadVehicles();TRN.vmLoadLifecycle();}else toast(r.message,false)});
  };
  TRN.vmTransfer=function(){
    var f=$('#vmXferFrom').val();var t=$('#vmXferTo').val();var reason=$('#vmXferReason').val();
    if(!f||!t){toast('Select both from and to routes.',false);return}
    if(!reason){toast('Reason is required.',false);return}
    post('transport/transfer_vehicle_between_routes',{vehicle_id:vmCurrentVid,from_route_id:f,to_route_id:t,reason:reason}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);loadRoutes();}else toast(r.message,false)});
  };

  // ═════════════════════════════════════════════════════════════════════
  //  Expiry Dashboard (F1)
  // ═════════════════════════════════════════════════════════════════════
  function loadExpiry(){
    getJSON('transport_ops/get_expiry_dashboard').done(function(r){
      if(r.status!=='success') return;
      var d = r.dashboard || {};
      $('#exp7').text(d.count_7_days||0);$('#exp15').text(d.count_15_days||0);$('#exp30').text(d.count_30_days||0);
      $('#expLastRun').text(d.computed_at?('Last computed: '+d.computed_at):'Not yet computed — click "Recompute Now"');
      var html='';
      (d.items||[]).forEach(function(i){
        var wCls = i.window===7?'trn-badge-red':i.window===15?'trn-badge-amber':'trn-badge-blue';
        html+='<tr><td>'+escH(i.number||i.vehicleId)+'</td><td>'+escH(i.label)+'</td><td>'+escH(i.expiry_date)+'</td><td>'+i.days_left+'</td><td><span class="trn-badge '+wCls+'">'+i.window+'d</span></td></tr>';
      });
      $('#expTbody').html(html||'<tr><td colspan="5" style="text-align:center;color:var(--t3)">No expirations in the next 30 days</td></tr>');
    });
  }
  TRN.recomputeExpiry=function(){post('transport_ops/recompute_expiry_dashboard',{}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);loadExpiry();}else toast(r.message,false)})};

  // ── Routes (F2 extended) ──
  function loadRoutes(){
    var includeArchived = $('#routeShowArchived').is(':checked') ? '1' : '0';
    getJSON('transport/get_routes?include_archived='+includeArchived).done(function(r){
      if(r.status!=='success') return; routes=r.routes||[];
      var html=''; routes.forEach(function(rt){
        var st = rt.status || 'Active';
        var isArchived = st === 'Archived';
        var isSuspended = (rt.active_suspension_ref || '') !== '';
        var badge;
        if (isArchived) badge = '<span class="trn-badge trn-badge-red">Archived</span>';
        else if (isSuspended) badge = '<span class="trn-badge trn-badge-amber">Suspended</span>';
        else if (st === 'Inactive') badge = '<span class="trn-badge trn-badge-red">Inactive</span>';
        else badge = '<span class="trn-badge trn-badge-green">Active</span>';
        html+='<tr><td>'+escH(rt.id)+'</td><td>'+escH(rt.name)+'</td><td>'+escH(vehName(rt.vehicle_id))+'</td><td>'+rt.distance_km+' km</td><td>Rs '+rt.monthly_fee+'</td><td>'+badge+'</td>';
        html+='<td>';
        html+='<button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.editRoute(\''+rt.id+'\')" title="Edit"><i class="fa fa-pencil"></i></button> ';
        if (isArchived) {
          html+='<button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.unarchiveRoute(\''+rt.id+'\')" title="Unarchive"><i class="fa fa-undo"></i></button>';
        } else if (isSuspended) {
          html+='<button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.openResumeModal(\''+rt.active_suspension_ref+'\',\''+escH(rt.name)+'\')" title="Resume"><i class="fa fa-play"></i></button>';
        } else {
          html+='<button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.openSuspendModal(\''+rt.id+'\',\''+escH(rt.name)+'\')" title="Suspend"><i class="fa fa-ban"></i></button> ';
          html+='<button class="trn-btn trn-btn-sm trn-btn-danger" onclick="TRN.openArchiveModal(\''+rt.id+'\')" title="Archive / Delete"><i class="fa fa-archive"></i></button>';
        }
        html+='</td></tr>';
      });
      $('#routesTbody').html(html||'<tr><td colspan="7" style="text-align:center;color:var(--t3)">No routes</td></tr>');
      var opts='<option value="">All Routes</option>',selOpts='';
      routes.forEach(function(rt){
        if (rt.status === 'Archived') return;
        opts+='<option value="'+rt.id+'">'+escH(rt.name)+'</option>';
        selOpts+='<option value="'+rt.id+'">'+escH(rt.name)+'</option>';
      });
      $('#stopRouteFilter').html(opts);$('#stRouteId').html(selOpts);$('#asnRouteId').html(selOpts);
      // Refresh active suspensions banner after routes reload
      TRN.loadActiveSuspensions();
    });
  }
  TRN.openRouteModal=function(){$('#rtId,#rtName,#rtStart,#rtEnd').val('');$('#rtFee,#rtDistance').val(0);$('#rtVehicleId').val('');$('#routeModalTitle').text('Add Route');$('#routeModal').addClass('show')};
  TRN.closeRouteModal=function(){$('#routeModal').removeClass('show')};
  TRN.editRoute=function(id){var r=routes.find(function(x){return x.id===id});if(!r)return;$('#rtId').val(r.id);$('#rtName').val(r.name);$('#rtVehicleId').val(r.vehicle_id);$('#rtFee').val(r.monthly_fee);$('#rtStart').val(r.start_point);$('#rtEnd').val(r.end_point);$('#rtDistance').val(r.distance_km);$('#routeModalTitle').text('Edit Route');$('#routeModal').addClass('show')};
  TRN.saveRoute=function(){post('transport/save_route',{id:$('#rtId').val(),name:$('#rtName').val(),vehicle_id:$('#rtVehicleId').val(),monthly_fee:$('#rtFee').val(),start_point:$('#rtStart').val(),end_point:$('#rtEnd').val(),distance_km:$('#rtDistance').val()}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);TRN.closeRouteModal();loadRoutes()}else toast(r.message,false)})};
  // F2: soft-delete by default via delete_route (archives); hard delete only from Archive modal
  TRN.delRoute=function(id){
    // Legacy binding — now routes to Archive modal for safety.
    TRN.openArchiveModal(id);
  };

  // ═════════════════════════════════════════════════════════════════════
  //  F2 — Route suspension + archive/unarchive
  // ═════════════════════════════════════════════════════════════════════
  TRN.openSuspendModal=function(routeId,routeName){
    $('#rsRouteId').val(routeId);
    $('#rsModalRoute').text(routeName?'('+routeName+')':'');
    $('#rsCategory,#rsNotifyParents,#rsFrom,#rsTo,#rsReason').val('');
    $('#rsFrom').val(new Date().toISOString().slice(0,10));
    $('#rsNotifyHint').text('');
    $('#suspendModal').addClass('show');
  };
  TRN.closeSuspendModal=function(){$('#suspendModal').removeClass('show')};
  TRN.suspendCategoryChanged=function(){
    var cat=$('#rsCategory').val();
    if(!cat){$('#rsNotifyHint').text('');return}
    getJSON('transport/preview_suspension_notify?reason_category='+encodeURIComponent(cat)).done(function(r){
      if(r.status!=='success')return;
      var def=r.notify_parents_default?'Yes':'No';
      $('#rsNotifyHint').text('Category default for parent notification: '+def);
    });
  };
  TRN.doSuspendRoute=function(){
    var routeId=$('#rsRouteId').val();
    var cat=$('#rsCategory').val();
    var reason=$('#rsReason').val();
    var from=$('#rsFrom').val();
    var to=$('#rsTo').val();
    var np=$('#rsNotifyParents').val(); // '', '0', or '1'
    if(!cat){toast('Select a reason category.',false);return}
    if(!reason){toast('Reason is required.',false);return}
    if(!from){toast('Suspended-from date is required.',false);return}
    var payload={route_id:routeId,reason_category:cat,reason:reason,suspended_from:from,suspended_to:to};
    if(np==='0'||np==='1')payload.notify_parents=np;
    post('transport/suspend_route',payload).done(function(r){
      r=typeof r==='string'?JSON.parse(r):r;
      if(r.status==='success'){toast(r.message,true);TRN.closeSuspendModal();loadRoutes();}
      else toast(r.message,false);
    });
  };
  TRN.openResumeModal=function(suspensionId,routeName){
    $('#rrSuspensionId').val(suspensionId);
    $('#rrModalRoute').text(routeName?'('+routeName+')':'');
    $('#rrNotes').val('');
    $('#resumeModal').addClass('show');
  };
  TRN.closeResumeModal=function(){$('#resumeModal').removeClass('show')};
  TRN.doResumeRoute=function(){
    var sid=$('#rrSuspensionId').val();var notes=$('#rrNotes').val();
    post('transport/resume_route',{suspension_id:sid,notes:notes}).done(function(r){
      r=typeof r==='string'?JSON.parse(r):r;
      if(r.status==='success'){toast(r.message,true);TRN.closeResumeModal();loadRoutes();}
      else toast(r.message,false);
    });
  };
  TRN.openArchiveModal=function(routeId){
    $('#arRouteId').val(routeId);$('#arReason').val('');
    $('#archiveModal').addClass('show');
  };
  TRN.closeArchiveModal=function(){$('#archiveModal').removeClass('show')};
  TRN.doArchiveRoute=function(){
    var id=$('#arRouteId').val();var reason=$('#arReason').val()||'Archived';
    post('transport/delete_route',{id:id,reason:reason}).done(function(r){
      r=typeof r==='string'?JSON.parse(r):r;
      if(r.status==='success'){toast(r.message,true);TRN.closeArchiveModal();loadRoutes();loadStops();}
      else toast(r.message,false);
    });
  };
  TRN.doPermanentlyDeleteRoute=function(){
    if(!confirm('PERMANENT delete: this action cannot be undone. Only proceed for routes never referenced by any assignment. Continue?'))return;
    var id=$('#arRouteId').val();
    post('transport/delete_route',{id:id,hard:'1'}).done(function(r){
      r=typeof r==='string'?JSON.parse(r):r;
      if(r.status==='success'){toast(r.message,true);TRN.closeArchiveModal();loadRoutes();loadStops();}
      else toast(r.message,false);
    });
  };
  TRN.unarchiveRoute=function(id){
    if(!confirm('Restore this route to Active?'))return;
    post('transport/unarchive_route',{id:id,to_status:'Active'}).done(function(r){
      r=typeof r==='string'?JSON.parse(r):r;
      if(r.status==='success'){toast(r.message,true);loadRoutes();}
      else toast(r.message,false);
    });
  };

  // Active suspensions banner (top of Routes tab)
  TRN.loadActiveSuspensions=function(){
    getJSON('transport/get_active_suspensions').done(function(r){
      if(r.status!=='success')return;
      var list=r.suspensions||[];
      if(list.length===0){$('#activeSuspensionsCard').hide();return}
      $('#activeSuspensionsCard').show();
      $('#activeSuspCount').text('('+list.length+')');
      var html='';
      list.forEach(function(s){
        var routeName='';
        for(var i=0;i<routes.length;i++)if(routes[i].id===s.route_id){routeName=routes[i].name;break}
        var np=s.notify_parents?'<span class="trn-badge trn-badge-blue">Notified</span>':'<span class="trn-badge trn-badge-amber">Ops only</span>';
        html+='<tr><td>'+escH(s.id)+'</td><td>'+escH(routeName||s.route_id)+'</td><td>'+escH((s.reason_category||'').replace(/_/g,' '))+'</td><td>'+escH(s.suspended_from||'')+'</td><td>'+escH(s.reason||'')+'</td><td>'+np+'</td>';
        html+='<td><button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.openResumeModal(\''+s.id+'\',\''+escH(routeName||'')+'\')" title="Resume"><i class="fa fa-play"></i> Resume</button></td></tr>';
      });
      $('#activeSuspTbody').html(html);
    });
  };

  // ── Stops ──
  function loadStops(){
    var routeId=$('#stopRouteFilter').val()||'';
    getJSON('transport/get_stops?route_id='+routeId).done(function(r){
      if(r.status!=='success') return; stops=r.stops||[];
      var html=''; stops.forEach(function(s){
        html+='<tr><td>'+escH(s.id)+'</td><td>'+escH(routeName(s.route_id))+'</td><td>'+escH(s.name)+'</td><td>'+escH(s.pickup_time)+'</td><td>'+escH(s.drop_time)+'</td><td>'+s.order+'</td>';
        html+='<td><button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.editStop(\''+s.id+'\')"><i class="fa fa-pencil"></i></button> <button class="trn-btn trn-btn-sm trn-btn-danger" onclick="TRN.delStop(\''+s.id+'\')"><i class="fa fa-trash"></i></button></td></tr>';
      });
      $('#stopsTbody').html(html||'<tr><td colspan="7" style="text-align:center;color:var(--t3)">No stops</td></tr>');
      // populate assignment stop select
      var stOpts='<option value="">-- Select --</option>';stops.forEach(function(s){stOpts+='<option value="'+s.id+'">'+escH(s.name)+'</option>'});$('#asnStopId').html(stOpts);
    });
  }
  $('#stopRouteFilter').on('change', loadStops);
  TRN.openStopModal=function(){$('#stId,#stName,#stPickup,#stDrop').val('');$('#stOrder').val(0);$('#stopModalTitle').text('Add Stop');$('#stopModal').addClass('show')};
  TRN.closeStopModal=function(){$('#stopModal').removeClass('show')};
  TRN.editStop=function(id){var s=stops.find(function(x){return x.id===id});if(!s)return;$('#stId').val(s.id);$('#stRouteId').val(s.route_id);$('#stName').val(s.name);$('#stPickup').val(s.pickup_time);$('#stDrop').val(s.drop_time);$('#stOrder').val(s.order);$('#stopModalTitle').text('Edit Stop');$('#stopModal').addClass('show')};
  TRN.saveStop=function(){post('transport/save_stop',{id:$('#stId').val(),route_id:$('#stRouteId').val(),name:$('#stName').val(),pickup_time:$('#stPickup').val(),drop_time:$('#stDrop').val(),order:$('#stOrder').val()}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);TRN.closeStopModal();loadStops()}else toast(r.message,false)})};
  TRN.delStop=function(id){if(!confirm('Delete stop?'))return;post('transport/delete_stop',{id:id}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);loadStops()}else toast(r.message,false)})};

  // ── Assignments (F3 extended) ──
  var assignments=[];
  function loadAssignments(){
    getJSON('transport/get_assignments').done(function(r){
      if(r.status!=='success') return; assignments=r.assignments||[];
      var html=''; assignments.forEach(function(a){
        var subs = a.subscription_type || 'Permanent';
        var subCls = subs==='Permanent'?'trn-badge-green':subs==='Temporary'?'trn-badge-amber':subs==='Seasonal'?'trn-badge-blue':'trn-badge-blue';
        var isSuspended = (a.suspended_from||'')!=='';
        var status = isSuspended?'Suspended':(a.status||'Active');
        var stCls = isSuspended?'trn-badge-amber':status==='Active'?'trn-badge-green':'trn-badge-red';
        var feeIsPending = a.fee_demand_ref==='pending';
        var feeSuffix = feeIsPending?' <small style="color:var(--rose)">(pending)</small>':'';
        html+='<tr><td>'+escH(a.student_name)+'</td><td>'+escH(a.student_class)+'</td><td>'+escH(a.route_name)+'</td><td>'+escH(a.stop_name||'—')+'</td>';
        html+='<td><span class="trn-badge '+subCls+'">'+escH(subs)+'</span></td>';
        html+='<td>Rs '+a.monthly_fee+feeSuffix+'</td>';
        html+='<td><span class="trn-badge '+stCls+'">'+status+'</span></td>';
        html+='<td>';
        html+='<button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.editAsn(\''+a.student_id+'\')" title="Edit"><i class="fa fa-pencil"></i></button> ';
        html+='<button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.openHistory(\''+a.student_id+'\',\''+escH(a.student_name)+'\')" title="History"><i class="fa fa-history"></i></button> ';
        if (isSuspended) {
          html+='<button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.resumeAsn(\''+a.student_id+'\')" title="Resume"><i class="fa fa-play"></i></button> ';
        } else {
          html+='<button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.openChange(\''+a.student_id+'\',\'route\')" title="Change Route"><i class="fa fa-exchange"></i></button> ';
          html+='<button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.openChange(\''+a.student_id+'\',\'stop\')" title="Change Stop"><i class="fa fa-map-marker"></i></button> ';
          html+='<button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.openSuspendAsn(\''+a.student_id+'\')" title="Suspend"><i class="fa fa-pause"></i></button> ';
        }
        html+='<button class="trn-btn trn-btn-sm trn-btn-danger" onclick="TRN.delAsn(\''+a.student_id+'\')" title="Unassign"><i class="fa fa-times"></i></button>';
        html+='</td></tr>';
      });
      $('#asnTbody').html(html||'<tr><td colspan="8" style="text-align:center;color:var(--t3)">No assignments</td></tr>');
    });
  }
  TRN.openAsnModal=function(editData){
    $('#asnStudentSearch,#asnStudentId').val('');$('#asnType').val('both');$('#asnStopId').html('<option value="">-- Select --</option>');
    if(editData){
      // Edit mode: pre-fill fields
      $('#asnStudentId').val(editData.student_id);$('#asnStudentSearch').val(editData.student_name).prop('disabled',true);
      $('#asnRouteId').val(editData.route_id);$('#asnType').val(editData.type||'both');
      // Load stops for selected route, then select the stop
      loadStopsForAssignment(editData.route_id,editData.stop_id);
    } else {
      $('#asnStudentSearch').prop('disabled',false);
      // Load stops for first route in dropdown
      var firstRoute=$('#asnRouteId').val();
      if(firstRoute) loadStopsForAssignment(firstRoute);
    }
    $('#asnModal').addClass('show');
  };
  TRN.editAsn=function(sid){var a=assignments.find(function(x){return x.student_id===sid});if(!a)return;TRN.openAsnModal(a)};
  TRN.closeAsnModal=function(){$('#asnStudentSearch').prop('disabled',false);$('#asnModal').removeClass('show')};

  // Load stops for a route and optionally pre-select a stop
  function loadStopsForAssignment(routeId,selectStopId){
    if(!routeId)return;
    getJSON('transport/get_stops?route_id='+routeId).done(function(r){
      if(r.status!=='success')return;
      var o='<option value="">-- Select --</option>';
      (r.stops||[]).forEach(function(s){o+='<option value="'+s.id+'"'+(selectStopId===s.id?' selected':'')+'>'+escH(s.name)+'</option>'});
      $('#asnStopId').html(o);
    });
  }

  // Student search for assignments
  var asnTimer;
  $('#asnStudentSearch').on('input',function(){clearTimeout(asnTimer);var q=$(this).val();if(q.length<2){$('#asnStudentResults').removeClass('show');return}
    asnTimer=setTimeout(function(){getJSON('transport/search_students?q='+encodeURIComponent(q)).done(function(r){if(r.status!=='success'||!r.students.length){$('#asnStudentResults').html('<div class="trn-search-item" style="color:var(--t3);cursor:default">No students found</div>').addClass('show');return}var h='';r.students.forEach(function(s){h+='<div class="trn-search-item" data-id="'+s.id+'" data-name="'+escH(s.name)+'">'+escH(s.name)+' <small style="opacity:.6">'+escH(s.id)+'</small> <small>'+escH(s.class)+'</small></div>'});$('#asnStudentResults').html(h).addClass('show')}).fail(function(xhr){$('#asnStudentResults').html('<div class="trn-search-item" style="color:var(--rose);cursor:default">Search failed: '+(xhr.responseJSON?xhr.responseJSON.message:xhr.statusText)+'</div>').addClass('show')})},300)});
  $(document).on('click','#asnStudentResults .trn-search-item',function(){$('#asnStudentId').val($(this).data('id'));$('#asnStudentSearch').val($(this).data('name'));$('#asnStudentResults').removeClass('show')});
  $(document).on('click',function(e){if(!$(e.target).closest('.trn-search-box').length) $('#asnStudentResults').removeClass('show')});
  // Route change → load stops for assignment
  $('#asnRouteId').on('change',function(){loadStopsForAssignment($(this).val())});
  TRN.saveAssignment=function(){
    var sid=$('#asnStudentId').val();if(!sid){toast('Please select a student.',false);return}
    var rid=$('#asnRouteId').val();if(!rid){toast('Please select a route.',false);return}
    post('transport/save_assignment',{student_id:sid,route_id:rid,stop_id:$('#asnStopId').val(),type:$('#asnType').val()}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);TRN.closeAsnModal();loadAssignments()}else toast(r.message,false)})};
  TRN.delAsn=function(sid){
    if(!confirm('Remove transport assignment?'))return;
    var reason = prompt('Reason (optional):','unassigned via admin');
    post('transport/delete_assignment',{student_id:sid,reason:reason||''}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);loadAssignments()}else toast(r.message,false)});
  };

  // ═════════════════════════════════════════════════════════════════════
  //  F3 Assignment lifecycle
  // ═════════════════════════════════════════════════════════════════════
  TRN.openBulkAssign=function(){$('#baRowsJson,#baResult').val('');$('#baResult').html('');$('#bulkAssignModal').addClass('show')};
  TRN.closeBulkAssign=function(){$('#bulkAssignModal').removeClass('show')};
  TRN.doBulkAssign=function(){
    var raw=$('#baRowsJson').val().trim();
    if(!raw){toast('Paste a JSON rows array.',false);return}
    try { JSON.parse(raw); } catch(e){toast('Invalid JSON: '+e.message,false);return}
    $('#baResult').html('<div style="color:var(--t3)">Processing...</div>');
    post('transport/bulk_assign',{rows_json:raw}).done(function(r){
      r=typeof r==='string'?JSON.parse(r):r;
      if(r.status==='success'){
        var html='<div style="font-family:var(--font-b);font-size:13px">';
        html+='<b>Batch:</b> '+escH(r.batch_id)+'<br>';
        html+='<b>Assigned:</b> '+r.assigned_count+'<br>';
        if(r.skipped_capacity.length) html+='<b style="color:var(--rose)">Capacity-skipped:</b> '+r.skipped_capacity.length+'<br>';
        if(r.skipped_conflict.length) html+='<b style="color:var(--rose)">Conflict-skipped:</b> '+r.skipped_conflict.length+'<br>';
        if(r.errors.length) html+='<b style="color:var(--rose)">Errors:</b> '+r.errors.length+'<br>';
        html+='</div>';
        $('#baResult').html(html);
        loadAssignments();
      } else toast(r.message,false);
    });
  };

  TRN.openHistory=function(sid,name){
    $('#ahStudent').text('— '+name);
    $('#ahTbody').html('<tr><td colspan="6" style="text-align:center;color:var(--t3)">Loading...</td></tr>');
    getJSON('transport/get_assignment_history?student_id='+encodeURIComponent(sid)).done(function(r){
      if(r.status!=='success')return;
      var html='';
      (r.history||[]).forEach(function(e){
        html+='<tr><td>'+escH(e.event_type)+'</td><td>'+escH((e.event_at||'').slice(0,16).replace('T',' '))+'</td><td>'+escH(e.event_by||'')+'</td><td>'+escH(e.source||'')+'</td><td>'+escH(e.batch_id||'')+'</td><td>'+escH(e.reason||'')+'</td></tr>';
      });
      $('#ahTbody').html(html||'<tr><td colspan="6" style="text-align:center;color:var(--t3)">No history</td></tr>');
    });
    $('#asnHistoryModal').addClass('show');
  };
  TRN.closeHistory=function(){$('#asnHistoryModal').removeClass('show')};

  TRN.openChange=function(sid,mode){
    $('#acStudentId').val(sid);$('#acMode').val(mode);
    $('#acTitle').text(mode==='route'?'Change Route':'Change Stop');
    $('#acRouteField').toggle(mode==='route');
    var a=assignments.find(function(x){return x.student_id===sid});
    if(!a){toast('Assignment not found.',false);return}
    if(mode==='route'){
      var opts='<option value="">-- Select --</option>';
      routes.forEach(function(rt){if(rt.status!=='Archived'&&rt.id!==a.route_id)opts+='<option value="'+rt.id+'">'+escH(rt.name)+'</option>'});
      $('#acRouteId').html(opts);
      $('#acStopId').html('<option value="">-- Select route first --</option>');
    } else {
      // Populate stops for current route
      getJSON('transport/get_stops?route_id='+encodeURIComponent(a.route_id)).done(function(r){
        var opts='<option value="">-- Select --</option>';
        (r.stops||[]).forEach(function(s){if(s.id!==a.stop_id)opts+='<option value="'+s.id+'">'+escH(s.name)+'</option>'});
        $('#acStopId').html(opts);
      });
    }
    $('#acReason').val('');
    $('#asnChangeModal').addClass('show');
  };
  TRN.acRouteChanged=function(){
    var rid=$('#acRouteId').val();
    if(!rid){$('#acStopId').html('<option value="">-- Select route first --</option>');return}
    getJSON('transport/get_stops?route_id='+encodeURIComponent(rid)).done(function(r){
      var opts='<option value="">-- Select --</option>';
      (r.stops||[]).forEach(function(s){opts+='<option value="'+s.id+'">'+escH(s.name)+'</option>'});
      $('#acStopId').html(opts);
    });
  };
  TRN.closeChange=function(){$('#asnChangeModal').removeClass('show')};
  TRN.doChange=function(){
    var sid=$('#acStudentId').val();var mode=$('#acMode').val();var reason=$('#acReason').val();
    if(!reason){toast('Reason required.',false);return}
    if(mode==='route'){
      var rid=$('#acRouteId').val();var stopId=$('#acStopId').val();
      if(!rid){toast('Select new route.',false);return}
      post('transport/change_route',{student_id:sid,new_route_id:rid,new_stop_id:stopId,reason:reason}).done(function(r){
        r=typeof r==='string'?JSON.parse(r):r;
        if(r.status==='success'){toast(r.message,true);TRN.closeChange();loadAssignments();}else toast(r.message,false);
      });
    } else {
      var stopId=$('#acStopId').val();
      if(!stopId){toast('Select new stop.',false);return}
      post('transport/change_stop',{student_id:sid,new_stop_id:stopId,reason:reason}).done(function(r){
        r=typeof r==='string'?JSON.parse(r):r;
        if(r.status==='success'){toast(r.message,true);TRN.closeChange();loadAssignments();}else toast(r.message,false);
      });
    }
  };

  TRN.openSuspendAsn=function(sid){
    $('#sasStudentId').val(sid);
    $('#sasFrom').val(new Date().toISOString().slice(0,10));
    $('#sasUntil,#sasReason').val('');
    $('#asnSuspendModal').addClass('show');
  };
  TRN.closeSuspendAsn=function(){$('#asnSuspendModal').removeClass('show')};
  TRN.doSuspendAsn=function(){
    var sid=$('#sasStudentId').val();var from=$('#sasFrom').val();var until=$('#sasUntil').val();var reason=$('#sasReason').val();
    if(!from||!reason){toast('From date and reason required.',false);return}
    post('transport/suspend_assignment',{student_id:sid,from:from,until:until,reason:reason}).done(function(r){
      r=typeof r==='string'?JSON.parse(r):r;
      if(r.status==='success'){toast(r.message,true);TRN.closeSuspendAsn();loadAssignments();}else toast(r.message,false);
    });
  };
  TRN.resumeAsn=function(sid){
    var reason=prompt('Resume reason (optional):','resumed');
    post('transport/resume_assignment',{student_id:sid,reason:reason||''}).done(function(r){
      r=typeof r==='string'?JSON.parse(r):r;
      if(r.status==='success'){toast(r.message,true);loadAssignments();}else toast(r.message,false);
    });
  };

  TRN.reconcilePendingFees=function(){
    if(!confirm('Retry any pending fee demands for assignments?'))return;
    post('transport/reconcile_pending_fees',{}).done(function(r){
      r=typeof r==='string'?JSON.parse(r):r;
      if(r.status==='success'){toast(r.message,true);loadAssignments();}else toast(r.message,false);
    });
  };

  TRN.rebuildCapacityCache=function(){
    if(!confirm('Rebuild capacity cache from source data? (This iterates all routes and counts current assignments.)'))return;
    post('transport/rebuild_capacity_cache',{route_id:''}).done(function(r){
      r=typeof r==='string'?JSON.parse(r):r;
      if(r.status==='success'){toast(r.message,true);loadAssignments();}else toast(r.message,false);
    });
  };

  // ═════════════════════════════════════════════════════════════════════
  //  F3 Session rollover (auto-continuing)
  // ═════════════════════════════════════════════════════════════════════
  TRN.openRollover=function(){
    $('#roFrom,#roTo').val('');
    $('#roStatus').hide().text('');
    $('#roStartBtn').text('Start Rollover').prop('disabled',false);
    $('#rolloverModal').addClass('show');
  };
  TRN.closeRollover=function(){$('#rolloverModal').removeClass('show')};
  TRN.startRollover=function(){
    var from=$('#roFrom').val().trim();var to=$('#roTo').val().trim();
    if(!from||!to){toast('Both from and to sessions are required.',false);return}
    if(from===to){toast('From and to sessions must differ.',false);return}
    if(!confirm('Roll over ALL transport assignments from '+from+' to '+to+'? This cannot be easily undone.'))return;
    $('#roStartBtn').text('Processing...').prop('disabled',true);
    TRN.rolloverPump(from,to);
  };
  TRN.rolloverPump=function(from,to){
    // Auto-continuing pump — invokes /start (idempotent, resumes via checkpoint)
    // in a loop until status='complete' or an error.
    post('transport_rollover/start',{from_session:from,to_session:to}).done(function(r){
      r=typeof r==='string'?JSON.parse(r):r;
      if(r.status!=='success'){
        $('#roStartBtn').text('Retry').prop('disabled',false);
        $('#roStatus').show().html('<span style="color:var(--rose)">'+escH(r.message||'Failed')+'</span>');
        return;
      }
      var st=r.status||'';
      $('#roStatus').show().html('<b>Status:</b> '+escH(st)+' — <b>Processed:</b> '+r.processed+' — <b>Batch:</b> '+escH(r.batch_id||''));
      if(st==='complete'){
        $('#roStartBtn').text('Done').prop('disabled',true);
        toast(r.message,true);
        loadAssignments();
        return;
      }
      if(st==='in_progress'){
        // Auto-continue (Refinement #1) — no admin interaction required
        setTimeout(function(){TRN.rolloverPump(from,to)},500);
        return;
      }
      // 'error' or unknown
      $('#roStartBtn').text('Retry').prop('disabled',false);
    }).fail(function(){
      $('#roStartBtn').text('Retry').prop('disabled',false);
      $('#roStatus').show().html('<span style="color:var(--rose)">Network error — click Retry.</span>');
    });
  };

  // ═════════════════════════════════════════════════════════════════════
  //  F4 Driver Management
  // ═════════════════════════════════════════════════════════════════════
  var drivers=[];
  function loadDrivers(){
    getJSON('transport_driver/list').done(function(r){
      if(r.status!=='success')return;
      drivers=r.drivers||[];
      var today=new Date().toISOString().slice(0,10);
      var html='';
      drivers.forEach(function(d){
        var m=d.driver_meta||{};
        var licExp=m.licence_expires||'—';
        var medExp=m.medical_expires||'—';
        var licCls=m.licence_expires&&m.licence_expires<today?'trn-badge-red':'trn-badge-green';
        var medCls=m.medical_expires&&m.medical_expires<today?'trn-badge-red':'trn-badge-green';
        var op=(m.operational_status||'Available');
        var opCls=op==='Available'?'trn-badge-green':op==='On_Leave'?'trn-badge-amber':'trn-badge-red';
        html+='<tr><td>'+escH(d.staff_id||d.id||'')+'</td>';
        html+='<td>'+escH(d.name||d.Name||'')+'</td>';
        html+='<td>'+escH(d.phone||'')+'</td>';
        html+='<td><span class="trn-badge '+licCls+'">'+escH(licExp)+'</span></td>';
        html+='<td><span class="trn-badge '+medCls+'">'+escH(medExp)+'</span></td>';
        html+='<td><span class="trn-badge '+opCls+'">'+escH(op)+'</span></td>';
        html+='<td><button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.openDrvManage(\''+(d.staff_id||d.id)+'\')" title="Manage"><i class="fa fa-cog"></i> Manage</button></td>';
        html+='</tr>';
      });
      $('#driversTbody').html(html||'<tr><td colspan="7" style="text-align:center;color:var(--t3)">No drivers — enable a staff\'s flags.can_drive_transport in HR</td></tr>');
    });
  }
  var dmCurrentStaff='';
  TRN.openDrvManage=function(sid){
    dmCurrentStaff=sid;
    var d=drivers.find(function(x){return (x.staff_id||x.id)===sid});
    if(!d){toast('Driver not found.',false);return}
    $('#dmStaffId').val(sid);
    $('#dmName').text('— '+(d.name||d.Name||sid));
    $('#dmHrName').text(d.name||d.Name||'—');
    $('#dmHrPhone').text(d.phone||'—');
    $('#dmHrRole').text(d.role||d.Role||'—');
    var m=d.driver_meta||{};
    $('#dmLicNo').val(m.licence_no||'');
    $('#dmLicClass').val(m.licence_class||'');
    $('#dmLicIssued').val(m.licence_issued||'');
    $('#dmLicExpires').val(m.licence_expires||'');
    $('#dmLicAuth').val(m.licence_authority||'');
    $('#dmYearsExp').val(m.years_experience||0);
    $('#dmMedLast').val(m.medical_check_last||'');
    $('#dmMedExpires').val(m.medical_expires||'');
    $('#dmBadgeNo').val(m.badge_no||'');
    $('#dmBadgeExpires').val(m.badge_expires||'');
    $('#dmCanDrive').val((m.can_drive_types||[]).join(','));
    $('#dmOpStatus').val(m.operational_status||'Available');
    $('#dmEmerName').val(m.emergency_contact_name||'');
    $('#dmEmerPhone').val(m.emergency_contact_phone||'');
    $('#dmReplAvail').val(m.replacement_availability?'1':'0');
    TRN.dmSwitch('profile');
    TRN.dmLoadIncidents();
    TRN.dmLoadReviews();
    TRN.dmLoadTemp();
    TRN.dmLoadActivity();
    $('#drvManageModal').addClass('show');
  };
  TRN.closeDrvManage=function(){$('#drvManageModal').removeClass('show');dmCurrentStaff='';loadDrivers();};
  TRN.dmSwitch=function(t){
    $('[data-dmtab]').removeClass('active');
    $('[data-dmtab="'+t+'"]').addClass('active');
    $('[data-dmpanel]').hide();
    $('[data-dmpanel="'+t+'"]').show();
  };
  TRN.dmSaveMeta=function(){
    post('transport_driver/save_meta',{
      staff_id:dmCurrentStaff,
      licence_no:$('#dmLicNo').val(),licence_class:$('#dmLicClass').val(),
      licence_issued:$('#dmLicIssued').val(),licence_expires:$('#dmLicExpires').val(),
      licence_authority:$('#dmLicAuth').val(),years_experience:$('#dmYearsExp').val(),
      medical_check_last:$('#dmMedLast').val(),medical_expires:$('#dmMedExpires').val(),
      badge_no:$('#dmBadgeNo').val(),badge_expires:$('#dmBadgeExpires').val(),
      can_drive_types:$('#dmCanDrive').val(),operational_status:$('#dmOpStatus').val(),
      emergency_contact_name:$('#dmEmerName').val(),emergency_contact_phone:$('#dmEmerPhone').val(),
      replacement_availability:$('#dmReplAvail').val()
    }).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);}else toast(r.message,false)});
  };
  TRN.dmLoadIncidents=function(){
    if(!dmCurrentStaff)return;
    getJSON('transport_driver/list_incidents?staff_id='+encodeURIComponent(dmCurrentStaff)).done(function(r){
      if(r.status!=='success')return;
      var html='';
      (r.incidents||[]).forEach(function(i){
        var sevCls=i.severity==='Critical'?'trn-badge-red':i.severity==='Major'?'trn-badge-amber':i.severity==='Minor'?'trn-badge-blue':'trn-badge-green';
        var resolved=i.resolved_at?'<span class="trn-badge trn-badge-green">Yes</span>':'<span class="trn-badge trn-badge-red">No</span>';
        html+='<tr><td>'+escH(i.incident_date||'')+'</td><td>'+escH((i.incident_type||'').replace(/_/g,' '))+'</td>';
        html+='<td><span class="trn-badge '+sevCls+'">'+escH(i.severity||'')+'</span></td>';
        html+='<td>'+escH((i.description||'').substring(0,80))+'</td><td>'+resolved+'</td>';
        html+='<td>'+(!i.resolved_at?'<button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.openResolveIncident(\''+i.id+'\')"><i class="fa fa-check"></i></button>':'')+'</td></tr>';
      });
      $('#dmIncidentsTbody').html(html||'<tr><td colspan="6" style="text-align:center;color:var(--t3)">No incidents</td></tr>');
    });
  };
  TRN.openIncident=function(){
    $('#incStaffId').val(dmCurrentStaff);
    var d=drivers.find(function(x){return(x.staff_id||x.id)===dmCurrentStaff});
    $('#incStaffLabel').text(d?'('+d.name+')':'');
    $('#incType').val('Complaint');$('#incSeverity').val('Minor');
    $('#incDate').val(new Date().toISOString().slice(0,10));$('#incTime,#incTripId,#incLocation,#incDesc').val('');
    // Populate vehicle picker
    var vopts='<option value="">-- None --</option>';(vehicles||[]).forEach(function(v){vopts+='<option value="'+v.id+'">'+escH(v.number)+'</option>'});
    $('#incVehicle').html(vopts);
    $('#incidentModal').addClass('show');
  };
  TRN.closeIncident=function(){$('#incidentModal').removeClass('show')};
  TRN.doRecordIncident=function(){
    var desc=$('#incDesc').val().trim();
    if(!desc){toast('Description required.',false);return}
    post('transport_driver/record_incident',{
      staff_id:$('#incStaffId').val(),incident_type:$('#incType').val(),severity:$('#incSeverity').val(),
      incident_date:$('#incDate').val(),incident_time:$('#incTime').val(),
      related_vehicle_id:$('#incVehicle').val(),related_trip_id:$('#incTripId').val(),
      location:$('#incLocation').val(),description:desc
    }).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);TRN.closeIncident();TRN.dmLoadIncidents();}else toast(r.message,false)});
  };
  TRN.openResolveIncident=function(id){$('#incResId').val(id);$('#incResText,#incResAction').val('');$('#incResolveModal').addClass('show')};
  TRN.closeResolveIncident=function(){$('#incResolveModal').removeClass('show')};
  TRN.doResolveIncident=function(){
    var res=$('#incResText').val();if(!res){toast('Resolution required.',false);return}
    post('transport_driver/resolve_incident',{incident_id:$('#incResId').val(),resolution:res,action_taken:$('#incResAction').val()}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);TRN.closeResolveIncident();TRN.dmLoadIncidents();}else toast(r.message,false)});
  };
  TRN.dmLoadReviews=function(){
    if(!dmCurrentStaff)return;
    getJSON('transport_driver/list_reviews?staff_id='+encodeURIComponent(dmCurrentStaff)).done(function(r){
      if(r.status!=='success')return;
      var html='';
      (r.reviews||[]).forEach(function(v){
        html+='<tr><td>'+escH(v.review_period_start||'')+' → '+escH(v.review_period_end||'')+'</td>';
        html+='<td>'+v.total_trips+'</td><td>'+v.total_km+'</td><td>'+v.incidents_count+'</td>';
        html+='<td>'+'⭐'.repeat(v.rating||0)+'</td><td>'+escH(v.reviewer_staff_id||'')+'</td></tr>';
      });
      $('#dmReviewsTbody').html(html||'<tr><td colspan="6" style="text-align:center;color:var(--t3)">No reviews</td></tr>');
    });
  };
  TRN.dmOpenReviewForm=function(){
    var today=new Date();var first=new Date(today.getFullYear(),today.getMonth(),1);
    $('#dmRvStart').val(first.toISOString().slice(0,10));
    $('#dmRvEnd').val(today.toISOString().slice(0,10));
    $('#dmRvRating').val(3);$('#dmRvNext,#dmRvNotes,#dmRvActions').val('');
    $('#dmReviewForm').show();
  };
  TRN.dmSaveReview=function(){
    post('transport_driver/save_review',{
      staff_id:dmCurrentStaff,review_period_start:$('#dmRvStart').val(),review_period_end:$('#dmRvEnd').val(),
      rating:$('#dmRvRating').val(),notes:$('#dmRvNotes').val(),
      action_items:$('#dmRvActions').val(),next_review_due:$('#dmRvNext').val()
    }).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);$('#dmReviewForm').hide();TRN.dmLoadReviews();}else toast(r.message,false)});
  };
  TRN.dmLoadTemp=function(){
    if(!dmCurrentStaff)return;
    getJSON('transport_driver/list_temp?vehicle_id=&active_only=0').done(function(r){
      if(r.status!=='success')return;
      var mine=(r.assignments||[]).filter(function(t){return t.covering_staff_id===dmCurrentStaff});
      var html='';
      mine.forEach(function(t){
        var st=t.status||'Active';
        var stCls=st==='Active'?'trn-badge-green':st==='Resolved'?'trn-badge-blue':'trn-badge-red';
        html+='<tr><td>'+escH(t.vehicle_id||'')+'</td><td>'+escH(t.effective_from||'')+'</td><td>'+escH(t.effective_to||'')+'</td>';
        html+='<td>'+escH(t.reason||'')+'</td><td><span class="trn-badge '+stCls+'">'+st+'</span></td>';
        html+='<td>'+(st==='Active'?'<button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.dmResolveTemp(\''+t.id+'\')"><i class="fa fa-check"></i> Resolve</button>':'')+'</td></tr>';
      });
      $('#dmTempTbody').html(html||'<tr><td colspan="6" style="text-align:center;color:var(--t3)">No temporary assignments</td></tr>');
    });
  };
  TRN.dmOpenTempAssign=function(){
    var vopts='<option value="">-- Select --</option>';(vehicles||[]).forEach(function(v){vopts+='<option value="'+v.id+'">'+escH(v.number)+'</option>'});
    $('#dmTaVehicle').html(vopts);
    $('#dmTaFrom').val(new Date().toISOString().slice(0,10));$('#dmTaTo,#dmTaReason,#dmTaNotes').val('');
    $('#dmTempForm').show();
  };
  TRN.dmSaveTemp=function(){
    var reason=$('#dmTaReason').val();var vid=$('#dmTaVehicle').val();var to=$('#dmTaTo').val();
    if(!vid||!to||!reason){toast('Vehicle, effective-to and reason required.',false);return}
    post('transport_driver/assign_temp',{
      vehicle_id:vid,covering_staff_id:dmCurrentStaff,
      effective_from:$('#dmTaFrom').val(),effective_to:to,
      reason:reason,notes:$('#dmTaNotes').val()
    }).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);$('#dmTempForm').hide();TRN.dmLoadTemp();}else toast(r.message,false)});
  };
  TRN.dmResolveTemp=function(id){
    post('transport_driver/resolve_temp',{assignment_id:id,reason:'manual'}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);TRN.dmLoadTemp();}else toast(r.message,false)});
  };
  TRN.dmLoadActivity=function(){
    if(!dmCurrentStaff){return}
    var today=new Date();var first=new Date(today.getFullYear(),today.getMonth(),1);
    getJSON('transport_driver/activity_snapshot?staff_id='+encodeURIComponent(dmCurrentStaff)+'&from='+first.toISOString().slice(0,10)+'&to='+today.toISOString().slice(0,10)).done(function(r){
      if(r.status!=='success')return;
      var s=r.snapshot||{};
      var html='<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px">';
      html+='<div><b>Trips:</b> '+s.trips_count+'</div>';
      html+='<div><b>Total KM:</b> '+s.total_km+'</div>';
      html+='<div><b>On-Time %:</b> '+s.on_time_pct+'</div>';
      html+='<div><b>Incidents:</b> '+s.incidents_count+'</div>';
      html+='<div><b>Critical:</b> <span style="color:var(--rose)">'+s.critical_incidents+'</span></div>';
      html+='<div><b>Complaints:</b> '+s.complaints_count+'</div>';
      html+='<div><b>Positive Recognition:</b> <span style="color:var(--green)">'+s.positive_recognitions+'</span></div>';
      html+='</div>';
      html+='<div style="margin-top:12px;font-size:11px;color:var(--t3);font-style:italic">Trip data is populated once the Trip Logs feature (F7) ships. Incident data live from F4.</div>';
      $('#dmActivityBody').html(html);
    });
  };
  TRN.dmRebuildActivity=function(){
    post('transport_driver/rebuild_activity',{staff_id:dmCurrentStaff,year_month:''}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);TRN.dmLoadActivity();}else toast(r.message,false)});
  };
  TRN.dmRebuildActivityAll=function(){
    if(!confirm('Rebuild activity summaries for ALL drivers for the current month?'))return;
    post('transport_driver/rebuild_activity',{staff_id:'',year_month:''}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);}else toast(r.message,false)});
  };

  // ═════════════════════════════════════════════════════════════════════
  //  F5 Expenses (2026-07-07)
  // ═════════════════════════════════════════════════════════════════════
  var expenseCategories={types:[],payee_types:[],payment_methods:[],attachment_types:[]};
  var expenses=[];
  function loadExpenseCategories(){
    getJSON('transport_ops/get_expense_categories').done(function(r){
      if(r.status!=='success')return;
      expenseCategories={
        types:r.types||[],payee_types:r.payee_types||[],
        payment_methods:r.payment_methods||[],attachment_types:r.attachment_types||[]
      };
    });
  }
  function loadExpenses(){
    getJSON('transport_ops/get_expenses').done(function(r){
      if(r.status!=='success')return; expenses=r.expenses||[];
      var html=''; var totalActive=0;
      expenses.forEach(function(e){
        var acctRef=e.accounting_journal_ref||'';
        var acctCls=acctRef===''?'trn-badge-amber':acctRef==='pending'?'trn-badge-amber':acctRef==='failed'?'trn-badge-red':'trn-badge-green';
        var acctLabel=acctRef===''?'Not posted':acctRef==='pending'?'Pending':acctRef==='failed'?'Failed':'Posted';
        var stCls=e.status==='Voided'?'trn-badge-red':'trn-badge-green';
        if(e.status==='Active') totalActive+=(e.total_amount||e.amount||0);
        html+='<tr><td>'+escH(e.expenseId||e.id||'')+'</td>';
        html+='<td>'+escH(e.expense_date||'')+'</td>';
        html+='<td>'+escH((e.expense_type||'').replace(/_/g,' '))+'</td>';
        html+='<td>'+escH(e.vehicle_id||'—')+'</td>';
        html+='<td>INR '+(e.total_amount||e.amount||0).toFixed(2)+'</td>';
        html+='<td>'+escH(e.payee_name||e.payee_id||e.payee_type||'')+'</td>';
        html+='<td><span class="trn-badge '+acctCls+'">'+acctLabel+'</span>';
        if(acctRef && acctRef!=='pending' && acctRef!=='failed') html+=' <small style="opacity:.6">'+escH(acctRef.substring(0,12))+'</small>';
        html+='</td>';
        html+='<td><span class="trn-badge '+stCls+'">'+escH(e.status||'Active')+'</span></td>';
        html+='<td>';
        if(e.status==='Active'){
          if(acctRef===''){html+='<button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.postExpenseNow(\''+e.expenseId+'\')" title="Post to Accounting"><i class="fa fa-upload"></i></button> ';}
          if(acctRef==='pending'||acctRef==='failed'){html+='<button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.retryExpensePost(\''+e.expenseId+'\')" title="Retry post"><i class="fa fa-repeat"></i></button> ';}
          html+='<button class="trn-btn trn-btn-sm trn-btn-danger" onclick="TRN.openVoidExpense(\''+e.expenseId+'\')" title="Void"><i class="fa fa-times"></i></button>';
        }
        html+='</td></tr>';
      });
      $('#expensesTbody').html(html||'<tr><td colspan="9" style="text-align:center;color:var(--t3)">No expenses recorded</td></tr>');
      $('#expensesSummaryBar').html('<b>Active month-to-date:</b> INR '+totalActive.toFixed(2)+' across '+expenses.filter(function(e){return e.status==='Active'}).length+' expense(s)');
    });
  }
  TRN.openExpense=function(){
    $('#expId,#expAmount,#expTaxAmt,#expTaxRate,#expCustomType,#expPayeeId,#expPayeeName,#expPayRef,#expRelMnt,#expRelFuel,#expNotes,#expVehicle').val('');
    $('#expTaxAmt,#expTaxRate').val(0);
    $('#expDate').val(new Date().toISOString().slice(0,10));
    $('#expForcePost').prop('checked',false);
    // Populate type dropdown
    var to='';(expenseCategories.types||[]).forEach(function(t){to+='<option value="'+t+'">'+escH(t.replace(/_/g,' '))+'</option>'});$('#expType').html(to);
    // Payment method
    var pmo='';(expenseCategories.payment_methods||[]).forEach(function(t){pmo+='<option value="'+t+'">'+escH(t.replace(/_/g,' '))+'</option>'});$('#expPayMethod').html(pmo);
    // Payee type
    var pto='';(expenseCategories.payee_types||[]).forEach(function(t){pto+='<option value="'+t+'">'+escH(t)+'</option>'});$('#expPayeeType').html(pto).val('External');
    // Vehicle picker
    var vopts='<option value="">-- None --</option>';(vehicles||[]).forEach(function(v){vopts+='<option value="'+v.id+'">'+escH(v.number)+'</option>'});$('#expVehicle').html(vopts);
    // Reset attachments container
    $('#expAttachments').html('');
    TRN.expTypeChanged();
    $('#expenseModal').addClass('show');
  };
  TRN.closeExpense=function(){$('#expenseModal').removeClass('show')};
  TRN.expTypeChanged=function(){
    $('#expCustomTypeRow').toggle($('#expType').val()==='Other');
  };
  TRN.expPayeeTypeChanged=function(){
    var t=$('#expPayeeType').val();
    if(t==='External'){$('#expPayeeId').prop('placeholder','(optional for external)');}
    else if(t==='Vendor'){$('#expPayeeId').prop('placeholder','VND0001 (required)');}
    else{$('#expPayeeId').prop('placeholder','STA0001 (required)');}
  };
  TRN.expAddAttachment=function(){
    var opts='';(expenseCategories.attachment_types||[]).forEach(function(t){opts+='<option value="'+t+'">'+escH(t.replace(/_/g,' '))+'</option>'});
    var idx=$('#expAttachments .trn-att-row').length;
    var html='<div class="trn-att-row" style="display:grid;grid-template-columns:150px 1fr 1fr 30px;gap:8px;margin-bottom:6px" data-idx="'+idx+'">';
    html+='<select class="trn-att-type">'+opts+'</select>';
    html+='<input type="text" class="trn-att-path" placeholder="storage path or URL">';
    html+='<input type="text" class="trn-att-notes" placeholder="notes">';
    html+='<button class="trn-btn trn-btn-sm trn-btn-danger" onclick="$(this).parent().remove()"><i class="fa fa-times"></i></button>';
    html+='</div>';
    $('#expAttachments').append(html);
  };
  TRN.saveExpense=function(){
    var attachments=[];
    $('#expAttachments .trn-att-row').each(function(){
      var p=$(this).find('.trn-att-path').val().trim();
      if(!p)return;
      attachments.push({type:$(this).find('.trn-att-type').val(),path:p,notes:$(this).find('.trn-att-notes').val()});
    });
    post('transport_ops/save_expense',{
      id:$('#expId').val(),
      expense_type:$('#expType').val(),expense_type_custom:$('#expCustomType').val(),
      expense_date:$('#expDate').val(),
      amount:$('#expAmount').val(),tax_amount:$('#expTaxAmt').val(),tax_rate:$('#expTaxRate').val(),
      payee_type:$('#expPayeeType').val(),payee_id:$('#expPayeeId').val(),payee_name:$('#expPayeeName').val(),
      vehicle_id:$('#expVehicle').val(),
      payment_method:$('#expPayMethod').val(),payment_reference:$('#expPayRef').val(),
      related_maintenance_id:$('#expRelMnt').val(),related_fuel_id:$('#expRelFuel').val(),
      attachments_json:JSON.stringify(attachments),
      notes:$('#expNotes').val(),
      force_post:$('#expForcePost').is(':checked')?'1':'0'
    }).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);TRN.closeExpense();loadExpenses();}else toast(r.message,false)});
  };
  TRN.postExpenseNow=function(id){
    if(!confirm('Post this expense to Accounting now?'))return;
    post('transport_ops/post_expense_now',{id:id}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);loadExpenses();}else toast(r.message,false)});
  };
  TRN.retryExpensePost=function(id){
    post('transport_ops/retry_expense_post',{id:id}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);loadExpenses();}else toast(r.message,false)});
  };
  TRN.openVoidExpense=function(id){$('#expVoidId').val(id);$('#expVoidReason').val('');$('#expVoidModal').addClass('show')};
  TRN.closeExpVoid=function(){$('#expVoidModal').removeClass('show')};
  TRN.confirmVoidExpense=function(){
    var reason=$('#expVoidReason').val();if(!reason){toast('Reason required.',false);return}
    post('transport_ops/void_expense',{id:$('#expVoidId').val(),reason:reason}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);TRN.closeExpVoid();loadExpenses();}else toast(r.message,false)});
  };
  TRN.reconcilePendingPosts=function(){
    if(!confirm('Retry all pending Accounting posts?'))return;
    post('transport_ops/reconcile_pending_posts',{}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);loadExpenses();}else toast(r.message,false)});
  };
  TRN.rebuildExpenseSummary=function(){
    post('transport_ops/rebuild_expense_summary',{year_month:''}).done(function(r){r=typeof r==='string'?JSON.parse(r):r;if(r.status==='success'){toast(r.message,true);}else toast(r.message,false)});
  };

  // ═════════════════════════════════════════════════════════════════════
  //  F6 SOS Monitor (LC-13, 2026-07-07)
  // ═════════════════════════════════════════════════════════════════════
  TRN.loadSos = function(){
    var st = $('#sosStatusFilter').val() || '';
    getJSON('transport_sos/list_all?status=' + encodeURIComponent(st) + '&limit=100').done(function(r){
      r = typeof r === 'string' ? JSON.parse(r) : r;
      if(r.status !== 'success'){ $('#sosTbody').html('<tr><td colspan="9" style="text-align:center;color:var(--rose)">'+(r.message||'load failed')+'</td></tr>'); return; }
      var rows = r.alerts || [];
      if(rows.length === 0){ $('#sosTbody').html('<tr><td colspan="9" style="text-align:center;color:var(--t3)">No SOS alerts</td></tr>'); return; }
      var html = '';
      rows.forEach(function(a){
        var st = String(a.status||'');
        var cls = st === 'active' ? 'trn-badge-red' : (st === 'responded' ? 'trn-badge-amber' : 'trn-badge-green');
        html += '<tr>';
        html += '<td>' + escH(a.id||'') + '</td>';
        html += '<td>' + escH((a.createdAt||'').substring(0,19).replace('T',' ')) + '</td>';
        html += '<td>' + escH(a.alertType||'') + '</td>';
        html += '<td>' + escH(a.vehicleId||'—') + '</td>';
        html += '<td>' + escH(a.routeId||'—') + '</td>';
        html += '<td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+escH(a.message||'')+'">' + escH((a.message||'').substring(0,50)) + '</td>';
        html += '<td><span class="trn-badge ' + cls + '">' + escH(st) + '</span></td>';
        html += '<td>' + escH(a.respondedBy||'—') + '</td>';
        html += '<td>';
        if(st === 'active'){
          html += '<button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.openSosAction(\''+a.id+'\',\'respond\')"><i class="fa fa-check"></i></button> ';
        }
        if(st === 'active' || st === 'responded'){
          html += '<button class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.openSosAction(\''+a.id+'\',\'resolve\')"><i class="fa fa-flag-checkered"></i></button>';
        }
        html += '</td></tr>';
      });
      $('#sosTbody').html(html);
    });
  };
  TRN.openRaiseSos = function(){
    // Populate vehicle + route pickers
    var vopts = '<option value="">-- None --</option>';
    (vehicles||[]).forEach(function(v){ vopts += '<option value="'+v.id+'">'+escH(v.number)+'</option>'; });
    $('#sosVehicle').html(vopts);
    var ropts = '<option value="">-- None --</option>';
    (routes||[]).forEach(function(r){ ropts += '<option value="'+r.id+'">'+escH(r.name||r.id)+'</option>'; });
    $('#sosRoute').html(ropts);
    $('#sosMessage,#sosLat,#sosLng').val('');
    $('#sosAlertType').val('emergency');
    $('#sosRaiseModal').addClass('show');
  };
  TRN.closeSosRaise = function(){ $('#sosRaiseModal').removeClass('show'); };
  TRN.confirmRaiseSos = function(){
    var msg = $('#sosMessage').val().trim();
    if(!msg){ toast('Message is required.', false); return; }
    if(!confirm('This will fire an URGENT alert to Admin, Transport Manager, Principal AND all parents on the affected route. Continue?')) return;
    post('transport_sos/raise', {
      vehicle_id: $('#sosVehicle').val(),
      route_id:   $('#sosRoute').val(),
      lat:        $('#sosLat').val() || 0,
      lng:        $('#sosLng').val() || 0,
      alert_type: $('#sosAlertType').val(),
      message:    msg,
    }).done(function(r){
      r = typeof r === 'string' ? JSON.parse(r) : r;
      if(r.status === 'success'){ toast(r.message, true); TRN.closeSosRaise(); TRN.loadSos(); }
      else toast(r.message, false);
    });
  };
  TRN.openSosAction = function(id, kind){
    $('#sosActionId').val(id); $('#sosActionKind').val(kind);
    $('#sosActionTitle').text(kind === 'respond' ? 'Respond to SOS' : 'Resolve SOS');
    $('#sosActionNotes').val('');
    $('#sosActionModal').addClass('show');
  };
  TRN.closeSosAction = function(){ $('#sosActionModal').removeClass('show'); };
  TRN.confirmSosAction = function(){
    var notes = $('#sosActionNotes').val().trim();
    if(!notes){ toast('Notes required.', false); return; }
    var kind = $('#sosActionKind').val();
    post('transport_sos/' + kind, {
      sos_id: $('#sosActionId').val(),
      notes:  notes,
    }).done(function(r){
      r = typeof r === 'string' ? JSON.parse(r) : r;
      if(r.status === 'success'){ toast(r.message, true); TRN.closeSosAction(); TRN.loadSos(); }
      else toast(r.message, false);
    });
  };

  // Init
  // F1 (2026-07-07): load vendors FIRST so the vendor picker inside the
  // vehicle modal is populated before the first Add-Vehicle click.
  loadVendors();
  loadVehicles();
  loadRoutes();
  loadDrivers();
  loadExpenseCategories();
  loadExpenses();
  TRN.loadSos();
  setTimeout(function(){loadStops();loadAssignments();loadExpiry();},500);
})();
});
</script>
