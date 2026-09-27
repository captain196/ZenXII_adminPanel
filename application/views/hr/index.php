<?php defined('BASEPATH') OR exit('No direct script access allowed');
    $at = isset($active_tab) ? $active_tab : 'dashboard';
    $tab_map = [
        'dashboard'   => ['panel'=>'panelDash',      'icon'=>'fa-tachometer',        'label'=>'Dashboard'],
        'recruitment' => ['panel'=>'panelRecruit',    'icon'=>'fa-briefcase',         'label'=>'Recruitment'],
        'leaves'      => ['panel'=>'panelLeaves',     'icon'=>'fa-calendar-minus-o',  'label'=>'Leave Mgmt'],
        'payroll'     => ['panel'=>'panelPayroll',    'icon'=>'fa-money',             'label'=>'Payroll'],
        'appraisals'  => ['panel'=>'panelAppraisal',  'icon'=>'fa-star',              'label'=>'Appraisals'],
        'training'    => ['panel'=>'panelTraining',   'icon'=>'fa-graduation-cap',    'label'=>'Training'],
        'orgchart'    => ['panel'=>'panelOrgChart',   'icon'=>'fa-sitemap',           'label'=>'Org Chart'],
    ];
?>

<div class="content-wrapper">
<section class="content">
<div class="hr-wrap">

  <!-- Header -->
  <div class="hr-header">
    <div>
      <div class="hr-header-icon"><i class="fa fa-users"></i> HR &amp; <?= htmlspecialchars($staff_term ?? 'Staff', ENT_QUOTES, 'UTF-8') ?> Management</div>
      <ol class="hr-breadcrumb">
        <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li>HR Module</li>
      </ol>
    </div>
  </div>

  <!-- Tabs -->
  <nav class="hr-tabs">
    <?php foreach ($tab_map as $slug => $t): ?>
    <a class="hr-tab<?= $at === $slug ? ' active' : '' ?>" href="<?= base_url('hr/' . $slug) ?>">
      <i class="fa <?= $t['icon'] ?>"></i> <?= $t['label'] ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <!-- ================================================================
       1. DASHBOARD
  ================================================================= -->
  <div class="hr-panel<?= $at === 'dashboard' ? ' active' : '' ?>" id="panelDash">

    <div class="hr-stats" id="hrDashStats">
      <div class="hr-stat">
        <div class="hr-stat-value" id="statTotalStaff">--</div>
        <div class="hr-stat-label"><i class="fa fa-users"></i> Total Staff</div>
      </div>
      <div class="hr-stat">
        <div class="hr-stat-value" id="statActiveStaff">--</div>
        <div class="hr-stat-label"><i class="fa fa-user-plus"></i> Active Staff</div>
      </div>
      <div class="hr-stat">
        <div class="hr-stat-value" id="statDepts">--</div>
        <div class="hr-stat-label"><i class="fa fa-building-o"></i> Departments</div>
      </div>
      <div class="hr-stat">
        <div class="hr-stat-value" id="statOpenJobs">--</div>
        <div class="hr-stat-label"><i class="fa fa-briefcase"></i> Open Jobs</div>
      </div>
      <div class="hr-stat">
        <div class="hr-stat-value" id="statPendingLeaves">--</div>
        <div class="hr-stat-label"><i class="fa fa-calendar-minus-o"></i> Pending Leaves</div>
      </div>
      <div class="hr-stat">
        <div class="hr-stat-value" id="statPayrollStatus">--</div>
        <div class="hr-stat-label"><i class="fa fa-money"></i> Payroll Status</div>
      </div>
      <div class="hr-stat" id="statPipelineCard" style="cursor:pointer" onclick="window.location.href='<?= base_url('ats') ?>'">
        <div class="hr-stat-value" id="statPipeline">--</div>
        <div class="hr-stat-label"><i class="fa fa-th-list"></i> In Pipeline</div>
      </div>
    </div>

    <div class="hr-card">
      <div class="hr-card-title"><i class="fa fa-bolt"></i> Quick Actions</div>
      <div class="hr-quick-actions">
        <button class="hr-btn hr-btn-primary" onclick="HR.openJobModal()"><i class="fa fa-plus"></i> New Job</button>
        <a class="hr-btn hr-btn-primary" href="<?= base_url('ats') ?>"><i class="fa fa-th-list"></i> Applicant Pipeline</a>
        <button class="hr-btn hr-btn-primary" onclick="HR.openLeaveTypeModal()"><i class="fa fa-plus"></i> New Leave Type</button>
        <button class="hr-btn hr-btn-primary" onclick="HR.openGeneratePayroll()"><i class="fa fa-play"></i> Run Payroll</button>
        <a class="hr-btn hr-btn-primary" href="<?= base_url('hr/payroll_health_page') ?>"><i class="fa fa-heartbeat"></i> Payroll Health</a>
        <button class="hr-btn hr-btn-primary" onclick="HR.openAppraisalModal()"><i class="fa fa-plus"></i> New Appraisal</button>
        <button class="hr-btn hr-btn-primary" onclick="HR.openTrainingModal()"><i class="fa fa-graduation-cap"></i> New Training Record</button>
      </div>
    </div>

    <!-- ATS Pipeline Mini-View -->
    <div class="hr-card" id="hrAtsPipelineCard">
      <div class="hr-card-title">
        <span><i class="fa fa-th-list"></i> Applicant Pipeline</span>
        <a class="hr-btn hr-btn-ghost hr-btn-sm" href="<?= base_url('ats') ?>"><i class="fa fa-external-link"></i> Open ATS</a>
      </div>
      <div class="hr-pipeline-bar" id="hrPipelineBar">
        <div class="hr-pipe-stage" data-stage="Applied"><div class="hr-pipe-count" id="pipeApplied">0</div><div class="hr-pipe-label">Applied</div></div>
        <div class="hr-pipe-arrow"><i class="fa fa-chevron-right"></i></div>
        <div class="hr-pipe-stage" data-stage="Shortlisted"><div class="hr-pipe-count" id="pipeShortlisted">0</div><div class="hr-pipe-label">Shortlisted</div></div>
        <div class="hr-pipe-arrow"><i class="fa fa-chevron-right"></i></div>
        <div class="hr-pipe-stage" data-stage="Interviewed"><div class="hr-pipe-count" id="pipeInterviewed">0</div><div class="hr-pipe-label">Interviewed</div></div>
        <div class="hr-pipe-arrow"><i class="fa fa-chevron-right"></i></div>
        <div class="hr-pipe-stage" data-stage="Selected"><div class="hr-pipe-count" id="pipeSelected">0</div><div class="hr-pipe-label">Selected</div></div>
        <div class="hr-pipe-arrow"><i class="fa fa-chevron-right"></i></div>
        <div class="hr-pipe-stage" data-stage="Hired"><div class="hr-pipe-count" id="pipeHired">0</div><div class="hr-pipe-label">Hired</div></div>
        <div class="hr-pipe-sep"></div>
        <div class="hr-pipe-stage hr-pipe-rejected"><div class="hr-pipe-count" id="pipeRejected">0</div><div class="hr-pipe-label">Rejected</div></div>
      </div>
    </div>

    <div class="hr-dash-grid">
      <div class="hr-card">
        <div class="hr-card-title"><i class="fa fa-calendar-minus-o"></i> Recent Leave Requests</div>
        <div class="hr-table-wrap">
          <table class="hr-table" id="tblDashLeaves">
            <thead><tr><th>Staff</th><th>Type</th><th>From</th><th>To</th><th>Status</th></tr></thead>
            <tbody><tr><td colspan="5" class="hr-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr></tbody>
          </table>
        </div>
      </div>
      <div class="hr-card">
        <div class="hr-card-title">
          <span><i class="fa fa-users"></i> Recent Applicants</span>
          <a class="hr-btn hr-btn-ghost hr-btn-sm" href="<?= base_url('ats') ?>"><i class="fa fa-th-list"></i> View All</a>
        </div>
        <div class="hr-table-wrap">
          <table class="hr-table" id="tblDashHires">
            <thead><tr><th>Candidate</th><th>Position</th><th>Stage</th><th>Rating</th><th>Applied</th></tr></thead>
            <tbody><tr><td colspan="5" class="hr-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <?php /* The "Departments" tab was REMOVED on 2026-08-23.

     It had already been reduced to a placeholder reading "Departments have
     moved", with a button to Staff & Roles — a tab whose only content was an
     apology for itself. Its menu entry had been removed in the 2026-08-21
     audit, which found it was a THIRD editor of schools.departments alongside
     Staff_access Tab 1 and the collapsed Org door, and that its two extra
     fields (head_staff_id, department status) were written but read by nothing.

     `Hr::index()` no longer accepts the tab, and `/hr/departments` now
     REDIRECTS to Staff & Roles so an old bookmark lands where departments
     actually live rather than silently showing the dashboard.

     `hr/get_departments` stays live — five views read it for their department
     dropdowns. The save/delete writers had already moved to Staff_access before
     this change (Org.php refers to them as the "former" writers), so only the
     reader is still load-bearing. Only the dead UI is gone. */ ?>

  <!-- ================================================================
       3. RECRUITMENT
  ================================================================= -->
  <div class="hr-panel<?= $at === 'recruitment' ? ' active' : '' ?>" id="panelRecruit">
    <div class="hr-card">
      <div class="hr-card-title">
        <span><i class="fa fa-briefcase"></i> Job Postings</span>
        <button class="hr-btn hr-btn-primary hr-btn-sm" onclick="HR.openJobModal()"><i class="fa fa-plus"></i> New Job Posting</button>
      </div>
      <div class="hr-toolbar">
        <div class="hr-fg">
          <label>Status</label>
          <select id="filterJobStatus" onchange="HR.loadJobs()">
            <option value="">All</option>
            <option value="open">Open</option>
            <option value="closed">Closed</option>
          </select>
        </div>
        <div class="hr-fg">
          <label>Department</label>
          <select id="filterJobDept" onchange="HR.loadJobs()"><option value="">All</option></select>
        </div>
      </div>
      <div class="hr-table-wrap">
        <table class="hr-table" id="tblJobs">
          <thead><tr><th>#</th><th>Title</th><th>Department</th><th>Positions</th><th>Applicants</th><th>Status</th><th>Deadline</th><th>Circular</th><th>Actions</th></tr></thead>
          <tbody><tr><td colspan="9" class="hr-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr></tbody>
        </table>
      </div>
      <div id="applicantsContainer" style="display:none;">
        <div class="hr-card-title" style="margin-top:18px;">
          <span><i class="fa fa-users"></i> Applicants for: <strong id="applicantsJobTitle"></strong></span>
          <span>
            <a class="hr-btn hr-btn-ghost hr-btn-sm" href="<?= base_url('ats') ?>" title="Open full ATS Pipeline"><i class="fa fa-th-list"></i> Open ATS</a>
            <button class="hr-btn hr-btn-primary hr-btn-sm" onclick="HR.openApplicantModal()"><i class="fa fa-plus"></i> Add Applicant</button>
          </span>
        </div>
        <div class="hr-table-wrap">
          <table class="hr-table" id="tblApplicants">
            <thead><tr><th>#</th><th>Name</th><th>Email</th><th>Phone</th><th>Status</th><th>Interview</th><th>Rating</th><th>Actions</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- ================================================================
       4. LEAVE MANAGEMENT
  ================================================================= -->
  <div class="hr-panel<?= $at === 'leaves' ? ' active' : '' ?>" id="panelLeaves">

    <div class="hr-sub-tabs">
      <button class="hr-sub-tab active" data-sub="leaveTypes">Leave Types</button>
      <button class="hr-sub-tab" data-sub="leaveRequests">Leave Requests</button>
      <button class="hr-sub-tab" data-sub="leaveBalances">Leave Balances</button>
      <button class="hr-sub-tab" data-sub="leaveCarryForward">Carry-Forward</button>
      <button class="hr-sub-tab" data-sub="compOff">Comp-Off</button>
      <button class="hr-sub-tab" data-sub="leaveAudit">Audit Log</button>
    </div>

    <!-- Leave Types -->
    <div class="hr-sub-panel active" id="subLeaveTypes">
      <!-- Seed suggestions -->
      <div class="hr-card hr-seed-card" id="leaveTypeSeedCard">
        <div class="hr-card-title">
          <span><i class="fa fa-lightbulb-o"></i> Suggested Leave Types</span>
          <button class="hr-btn hr-btn-primary hr-btn-sm" id="seedLeaveBtn" onclick="HR.seedLeaveTypes()"><i class="fa fa-magic"></i> Add All Missing</button>
        </div>
        <div class="hr-seed-pills" id="leaveTypePills">
          <span class="hr-seed-pill" data-code="CL" onclick="HR.seedOnePill(this)"><i class="fa fa-plus-circle"></i> CL <small>Casual Leave</small></span>
          <span class="hr-seed-pill" data-code="SL" onclick="HR.seedOnePill(this)"><i class="fa fa-plus-circle"></i> SL <small>Sick Leave</small></span>
          <span class="hr-seed-pill" data-code="EL" onclick="HR.seedOnePill(this)"><i class="fa fa-plus-circle"></i> EL <small>Earned Leave</small></span>
          <span class="hr-seed-pill" data-code="MATERNITY" onclick="HR.seedOnePill(this)"><i class="fa fa-plus-circle"></i> MATERNITY <small>Maternity</small></span>
          <span class="hr-seed-pill" data-code="PATERNITY" onclick="HR.seedOnePill(this)"><i class="fa fa-plus-circle"></i> PATERNITY <small>Paternity</small></span>
          <span class="hr-seed-pill" data-code="LWP" onclick="HR.seedOnePill(this)"><i class="fa fa-plus-circle"></i> LWP <small>Without Pay</small></span>
          <span class="hr-seed-pill" data-code="COMP_OFF" onclick="HR.seedOnePill(this)"><i class="fa fa-plus-circle"></i> COMP_OFF <small>Comp Off</small></span>
          <span class="hr-seed-pill" data-code="ACADEMIC" onclick="HR.seedOnePill(this)"><i class="fa fa-plus-circle"></i> ACADEMIC <small>Academic</small></span>
          <span class="hr-seed-pill" data-code="DUTY" onclick="HR.seedOnePill(this)"><i class="fa fa-plus-circle"></i> DUTY <small>On Duty</small></span>
        </div>
      </div>
      <div class="hr-card">
        <div class="hr-card-title">
          <span><i class="fa fa-list"></i> Leave Types</span>
          <span>
            <label class="hr-accrual-toggle" style="font-size:12px;color:var(--t2,#667);margin-right:8px;cursor:pointer;user-select:none" title="OFF (default): the full annual leave is granted up front. ON: leave accrues month by month, and staff can only take what has accrued so far. Each leave type also has its own Accrual Mode; this is the school-wide master switch.">
              <input type="checkbox" id="chkAccrualMode" onchange="HR.saveAccrualMode(this.checked)" style="vertical-align:middle"> Monthly accrual mode
            </label>
            <button class="hr-btn hr-btn-ghost hr-btn-sm" onclick="HR.runLeaveAccrual()" title="Credits this month's monthly-accrual leave types — safe to click more than once, already-credited months are skipped"><i class="fa fa-refresh"></i> Run Monthly Accrual</button>
            <button class="hr-btn hr-btn-primary hr-btn-sm" onclick="HR.openLeaveTypeModal()"><i class="fa fa-plus"></i> Add Type</button>
          </span>
        </div>
        <!-- Per-school leave POLICY — each switch is this school's own choice.
             All default OFF; a new school behaves exactly as before until it
             opts in here. These override the platform defaults for this tenant only. -->
        <div class="hr-policy-bar" style="display:flex;flex-wrap:wrap;gap:14px 20px;padding:10px 14px;margin:0 0 10px;background:var(--s2,#f6f7f9);border-radius:8px;font-size:12px;color:var(--t2,#667)">
          <span style="font-weight:600;color:var(--t1,#334)"><i class="fa fa-sliders"></i> Leave policy (this school):</span>
          <label style="cursor:pointer;user-select:none" title="Resolve each leave type's entitlement against the policy version in force for the period, not just the always-current value.">
            <input type="checkbox" id="lpEffectiveDating" onchange="HR.saveLeavePolicy('effective_dating', this.checked)" style="vertical-align:middle"> Effective-dated policy</label>
          <label style="cursor:pointer;user-select:none" title="Pro-rate a mid-year joiner's / leaver's annual entitlement by their join/exit date.">
            <input type="checkbox" id="lpProrata" onchange="HR.saveLeavePolicy('prorata', this.checked)" style="vertical-align:middle"> Join-date pro-rata</label>
          <label style="cursor:pointer;user-select:none" title="Enforce a per-type waiting period before a newly joined employee can take that leave.">
            <input type="checkbox" id="lpWaitingPeriod" onchange="HR.saveLeavePolicy('waiting_period', this.checked)" style="vertical-align:middle"> Waiting period</label>
          <label style="cursor:pointer;user-select:none" title="Cap the balance an employee may hold; further accrual pauses until usage frees room.">
            <input type="checkbox" id="lpMaxBalance" onchange="HR.saveLeavePolicy('max_balance', this.checked)" style="vertical-align:middle"> Max-balance ceiling</label>
          <label style="cursor:pointer;user-select:none" title="Enable the leave-year closure workflow (preview + confirm carry-forward into next year).">
            <input type="checkbox" id="lpYearClosure" onchange="HR.saveLeavePolicy('year_closure', this.checked)" style="vertical-align:middle"> Year-end closure</label>
        </div>
        <div class="hr-table-wrap">
          <table class="hr-table" id="tblLeaveTypes">
            <thead><tr><th>#</th><th>Name</th><th>Code</th><th>Days/Year</th><th>Carry Forward</th><th>Max Carry</th><th>Policy</th><th>Actions</th></tr></thead>
            <tbody><tr><td colspan="8" class="hr-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Leave Requests -->
    <div class="hr-sub-panel" id="subLeaveRequests">
      <div class="hr-card">
        <div class="hr-card-title">
          <span><i class="fa fa-calendar-minus-o"></i> Leave Requests</span>
          <button class="hr-btn hr-btn-primary hr-btn-sm" onclick="HR.openLeaveRequestModal()"><i class="fa fa-plus"></i> New Leave Request</button>
        </div>
        <div class="hr-toolbar">
          <div class="hr-fg">
            <label>Status</label>
            <select id="filterLeaveStatus" onchange="HR.loadLeaveRequests()">
              <option value="">All</option>
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>
          <div class="hr-fg">
            <label>Staff</label>
            <select id="filterLeaveStaff" onchange="HR.loadLeaveRequests()"><option value="">All</option></select>
          </div>
        </div>
        <div class="hr-table-wrap">
          <table class="hr-table" id="tblLeaveRequests">
            <thead><tr><th>#</th><th>Staff</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Pay Status</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody><tr><td colspan="9" class="hr-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Leave Balances -->
    <div class="hr-sub-panel" id="subLeaveBalances">
      <div class="hr-card">
        <div class="hr-card-title">
          <span><i class="fa fa-balance-scale"></i> Leave Balances</span>
          <button class="hr-btn hr-btn-primary hr-btn-sm" onclick="HR.initBalances()"><i class="fa fa-refresh"></i> Initialize Balances</button>
        </div>
        <div class="hr-table-wrap">
          <table class="hr-table" id="tblLeaveBalances">
            <thead id="theadBalances"><tr><th>Staff Name</th></tr></thead>
            <tbody><tr><td class="hr-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Carry-Forward (Leave Policy Programme Phase 7) -->
    <div class="hr-sub-panel" id="subLeaveCarryForward">
      <div class="hr-card">
        <div class="hr-card-title">
          <span><i class="fa fa-arrow-circle-right"></i> Leave-Year Closure &amp; Carry-Forward</span>
        </div>
        <p style="color:var(--t3);font-size:13px;margin:0 0 12px;">
          Preview shows exactly what will carry forward BEFORE anything is written — nothing is
          changed until you Confirm. Confirming twice is safe: a year already closed reports back
          without carrying anything a second time.
        </p>
        <div class="hr-toolbar">
          <div class="hr-fg">
            <label>Year to Close</label>
            <input type="number" id="cfYear" min="2000" max="2100" style="width:110px;">
          </div>
          <div class="hr-fg" style="align-self:flex-end;">
            <button class="hr-btn hr-btn-primary hr-btn-sm" onclick="HR.previewCarryForward()"><i class="fa fa-eye"></i> Preview</button>
          </div>
          <div class="hr-fg" style="align-self:flex-end;">
            <button class="hr-btn hr-btn-sm" id="btnConfirmCarryForward" style="background:#b91c1c;color:#fff;display:none;" onclick="HR.confirmCarryForward()"><i class="fa fa-check-circle"></i> Confirm &amp; Carry Forward</button>
          </div>
        </div>
        <div id="cfEmpty" class="hr-empty" style="padding:24px;text-align:center;">
          <i class="fa fa-info-circle"></i> Enter a year and click Preview to see who has unused balance eligible to carry forward.
        </div>
        <div class="hr-table-wrap" id="cfTableWrap" style="display:none;">
          <table class="hr-table" id="tblCarryForward">
            <thead><tr><th>Staff</th><th>Leave Type</th><th>Unused</th><th>Max Carry</th><th>Eligible</th><th>Will Carry</th><th>Forfeited</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Audit Log -->
    <!-- Comp-Off (COMP-OFF-1) ───────────────────────────────────────────
         Credits a day the staff member ALREADY worked as a rest day. The
         endpoint requires that date to carry attendance's own 'W'
         (extraWorked) mark, so this screen never makes that judgement — it
         converts an established fact into a credit, and says so. -->
    <div class="hr-sub-panel" id="subCompOff">
      <div class="hr-card">
        <div class="hr-card-title">
          <span><i class="fa fa-clock-o"></i> Credit a Comp-Off</span>
        </div>
        <div class="hr-fg">
          <label for="coStaffId">Employee ID</label>
          <input id="coStaffId" placeholder="e.g. STA000123" autocomplete="off">
        </div>
        <div class="hr-fg">
          <label for="coDate">Date worked</label>
          <input id="coDate" type="date">
          <small>The date must already be marked <b>W</b> (worked a rest day) in attendance.</small>
        </div>
        <button class="hr-btn hr-btn-primary" id="coCreditBtn" onclick="HR.creditCompOff()">
          <i class="fa fa-plus-circle"></i> Credit Comp-Off
        </button>
        <button class="hr-btn" id="coLoadBtn" onclick="HR.loadCompOffLedger()">
          <i class="fa fa-list"></i> Show ledger
        </button>
      </div>

      <div class="hr-card">
        <div class="hr-card-title">
          <span><i class="fa fa-book"></i> Comp-Off ledger</span>
          <span id="coBalance"></span>
        </div>
        <div class="hr-table-wrap">
          <table class="hr-table" id="coTable">
            <thead>
              <tr><th>Earned for</th><th>Days</th><th>Status</th><th>Expires</th><th>Consumed</th></tr>
            </thead>
            <tbody>
              <tr><td colspan="5" class="hr-empty">Enter an employee ID and choose <b>Show ledger</b>.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="hr-sub-panel" id="subLeaveAudit">
      <div class="hr-card">
        <div class="hr-card-title">
          <span><i class="fa fa-history"></i> Leave &amp; Payroll Audit Log</span>
          <button class="hr-btn hr-btn-ghost hr-btn-sm" onclick="HR.loadLeaveAudit()"><i class="fa fa-refresh"></i> Refresh</button>
        </div>
        <div class="hr-toolbar">
          <div class="hr-fg">
            <label>Action</label>
            <select id="filterAuditAction" onchange="HR.filterAuditTable()">
              <option value="">All</option>
              <option value="leave_applied">Applied</option>
              <option value="leave_decided">Decided</option>
              <option value="payroll_generated">Payroll</option>
            </select>
          </div>
          <div class="hr-fg">
            <label>Staff</label>
            <select id="filterAuditStaff" onchange="HR.filterAuditTable()"><option value="">All</option></select>
          </div>
        </div>
        <div class="hr-table-wrap">
          <table class="hr-table" id="tblLeaveAudit">
            <thead><tr><th style="width:40px">#</th><th>Timestamp</th><th>Action</th><th>Staff</th><th>Leave Type</th><th>Details</th><th>By</th></tr></thead>
            <tbody><tr><td colspan="7" class="hr-empty"><i class="fa fa-info-circle"></i> Click the Audit Log tab to load entries.</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- ================================================================
       5. PAYROLL
  ================================================================= -->
  <div class="hr-panel<?= $at === 'payroll' ? ' active' : '' ?>" id="panelPayroll">

    <div class="hr-sub-tabs">
      <button class="hr-sub-tab active" data-sub="myPayslips">My Payslips</button>
      <?php if (isset($admin_role) && in_array($admin_role, ['Admin', 'Principal', 'Super Admin', 'School Super Admin'], true)): ?>
      <button class="hr-sub-tab" data-sub="salaryStructures">Salary Structures</button>
      <button class="hr-sub-tab" data-sub="payrollRuns">Payroll Runs</button>
      <button class="hr-sub-tab" data-sub="payslips">Payslips</button>
      <?php endif; ?>
    </div>

    <!-- My Payslips (visible to all staff) -->
    <div class="hr-sub-panel active" id="subMyPayslips">
      <div class="hr-card">
        <div class="hr-card-title">
          <span><i class="fa fa-file-text-o"></i> My Payslips</span>
        </div>
        <div id="myPayslipsLoading" class="hr-empty" style="padding:30px;text-align:center;">
          <i class="fa fa-spinner fa-spin"></i> Loading your payslips...
        </div>
        <div class="hr-table-wrap" id="myPayslipsWrap" style="display:none;">
          <table class="hr-table" id="tblMyPayslips">
            <thead><tr><th>Month</th><th>Year</th><th>Basic</th><th>Allowances</th><th>Gross</th><th>Deductions</th><th>Net Pay</th><th>Days Worked</th><th>Absent</th><th>Paid Leave</th><th>LWP</th><th>Status</th><th></th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Salary Structures -->
    <div class="hr-sub-panel" id="subSalaryStructures">
      <!-- Coverage banner (populated by JS) -->
      <div id="salCoverageBanner" style="display:none"></div>
      <div class="hr-card">
        <div class="hr-card-title">
          <span><i class="fa fa-sitemap"></i> Salary Structures</span>
          <span>
            <button class="hr-btn hr-btn-ghost hr-btn-sm" onclick="HR.backfillStructures()" id="btnBackfill" style="display:none"><i class="fa fa-magic"></i> Auto-Create Missing</button>
            <button class="hr-btn hr-btn-primary hr-btn-sm" onclick="HR.openSalaryModal()"><i class="fa fa-plus"></i> Add Structure</button>
          </span>
        </div>
        <div class="hr-table-wrap">
          <table class="hr-table" id="tblSalary">
            <thead><tr><th>#</th><th>Staff</th><th>Basic</th><th>HRA</th><th>DA</th><th>TA</th><th>Medical</th><th>Other</th><th>Gross</th><th>PF</th><th>ESI</th><th>PT</th><th>TDS</th><th>Deductions</th><th>Net Pay</th><th>Actions</th></tr></thead>
            <tbody><tr><td colspan="16" class="hr-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Payroll Runs -->
    <div class="hr-sub-panel" id="subPayrollRuns">

      <!-- Payroll periods (PAY-1). The lock endpoint existed with no caller, and
           had no unlock at all: once closed, a period stayed closed forever while
           _check_payroll_lock told the operator to "Unlock before making changes".
           Closing a period blocks generation, regeneration and deletion for that
           month — which is the whole point, and why re-opening asks for a reason. -->
      <div class="hr-card">
        <div class="hr-card-title">
          <span><i class="fa fa-lock"></i> Payroll Periods</span>
          <span style="font-size:12px;font-weight:500;color:var(--t3);">
            Closing a month blocks payroll generation, regeneration and deletion for it
          </span>
        </div>
        <div id="payrollCloseRow" style="display:none;gap:10px;align-items:flex-end;margin-bottom:14px;flex-wrap:wrap;">
          <div class="hr-fg">
            <label>Month</label>
            <select id="lockMonthSel">
              <option value="January">January</option><option value="February">February</option>
              <option value="March">March</option><option value="April">April</option>
              <option value="May">May</option><option value="June">June</option>
              <option value="July">July</option><option value="August">August</option>
              <option value="September">September</option><option value="October">October</option>
              <option value="November">November</option><option value="December">December</option>
            </select>
          </div>
          <div class="hr-fg">
            <label>Year</label>
            <input type="number" id="lockYearSel" min="2000" max="2100" style="width:110px;">
          </div>
          <button class="hr-btn hr-btn-sm" id="btnClosePayrollPeriod">
            <i class="fa fa-lock"></i> Close period
          </button>
          <span style="font-size:12px;color:var(--t3);">
            Only a month with a finalized monthly payroll run can be closed.
          </span>
        </div>

        <div id="payrollLocksBody">
          <div class="hr-empty" style="padding:22px;text-align:center;">
            <i class="fa fa-spinner fa-spin"></i> Loading payroll periods...
          </div>
        </div>
      </div>

      <div class="hr-card">
        <div class="hr-card-title">
          <span><i class="fa fa-play-circle"></i> Payroll Runs</span>
          <button class="hr-btn hr-btn-primary hr-btn-sm" onclick="HR.openGeneratePayroll()"><i class="fa fa-play"></i> Generate Payroll</button>
        </div>
        <div class="hr-table-wrap">
          <table class="hr-table" id="tblPayrollRuns">
            <thead><tr><th>#</th><th>Run ID</th><th>Month</th><th>Year</th><th>Staff</th><th>Gross</th><th>Deductions</th><th>Net Pay</th><th>Status</th><th>Payment</th><th>Actions</th></tr></thead>
            <tbody><tr><td colspan="11" class="hr-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Payslips -->
    <div class="hr-sub-panel" id="subPayslips">
      <div class="hr-card">
        <div class="hr-card-title">
          <span><i class="fa fa-file-text-o"></i> Payslips</span>
          <span id="payslipRunInfo" class="hr-badge hr-badge-draft" style="display:none;"></span>
        </div>
        <div id="payslipSelectMsg" class="hr-empty" style="padding:30px;text-align:center;">
          <i class="fa fa-info-circle"></i> Select a payroll run from the Payroll Runs tab to view payslips.
        </div>
        <!-- W3.2.3 — loss-of-pay review. Rendered above the slip grid because
             it must be seen BEFORE finalization, not found by drilling into
             employees one at a time. Hidden entirely when the run has no LOP. -->
        <div id="lopReviewPanel" style="display:none"></div>
        <div class="hr-table-wrap" id="payslipTableWrap" style="display:none;">
          <table class="hr-table" id="tblPayslips">
            <thead><tr><th>#</th><th>Staff</th><th>Basic</th><th>Allowances</th><th>Adjustments</th><th>Gross</th><th>Deductions</th><th>Net</th><th>Days Worked</th><th>Absent</th><th>Paid Leave</th><th>LWP</th><th>Status</th><th></th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- ================================================================
       6. APPRAISALS
  ================================================================= -->
  <div class="hr-panel<?= $at === 'appraisals' ? ' active' : '' ?>" id="panelAppraisal">
    <div class="hr-card">
      <div class="hr-card-title">
        <span><i class="fa fa-star"></i> Performance Appraisals</span>
        <button class="hr-btn hr-btn-primary hr-btn-sm" onclick="HR.openAppraisalModal()"><i class="fa fa-plus"></i> New Appraisal</button>
      </div>
      <div class="hr-toolbar">
        <div class="hr-fg">
          <label>Period</label>
          <select id="filterAppraisalPeriod" onchange="HR.loadAppraisals()"><option value="">All</option></select>
        </div>
        <div class="hr-fg">
          <label>Staff</label>
          <select id="filterAppraisalStaff" onchange="HR.loadAppraisals()"><option value="">All</option></select>
        </div>
        <div class="hr-fg">
          <label>Status</label>
          <select id="filterAppraisalStatus" onchange="HR.loadAppraisals()">
            <option value="">All</option>
            <option value="Draft">Draft</option>
            <option value="Submitted">Submitted</option>
            <option value="Reviewed">Reviewed</option>
          </select>
        </div>
      </div>
      <div class="hr-table-wrap">
        <table class="hr-table" id="tblAppraisals">
          <thead><tr><th>#</th><th>Staff Name</th><th>Department</th><th>Type</th><th>Period</th><th>Overall Rating</th><th>Recommendation</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
          <tbody><tr><td colspan="10" class="hr-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ================================================================
       7. TRAINING / DEVELOPMENT (Staff HR Advanced Capability Programme,
          Phase 7, 2026-08-19) — a factual log entry per staff, not a
          graded/reviewed workflow like Appraisals above (deliberately no
          Draft/Submitted/Reviewed states). Certificates are attached via
          the existing "HR Documents" upload on the staff's own Edit page,
          not duplicated here.
  ================================================================= -->
  <div class="hr-panel<?= $at === 'training' ? ' active' : '' ?>" id="panelTraining">
    <div class="hr-card">
      <div class="hr-card-title">
        <span><i class="fa fa-graduation-cap"></i> Training &amp; Development</span>
        <button class="hr-btn hr-btn-primary hr-btn-sm" onclick="HR.openTrainingModal()"><i class="fa fa-plus"></i> New Record</button>
      </div>
      <div class="hr-toolbar">
        <div class="hr-fg">
          <label>Staff</label>
          <select id="filterTrainingStaff" onchange="HR.loadTrainingRecords()"><option value="">All</option></select>
        </div>
        <div class="hr-fg">
          <label>Category</label>
          <select id="filterTrainingCategory" onchange="HR.loadTrainingRecords()">
            <option value="">All</option>
            <option value="Training">Training</option>
            <option value="Certification">Certification</option>
            <option value="Workshop">Workshop</option>
            <option value="Course">Course</option>
          </select>
        </div>
        <div class="hr-fg">
          <label>Status</label>
          <select id="filterTrainingStatus" onchange="HR.loadTrainingRecords()">
            <option value="">All</option>
            <option value="Planned">Planned</option>
            <option value="In Progress">In Progress</option>
            <option value="Completed">Completed</option>
          </select>
        </div>
      </div>
      <div class="hr-table-wrap">
        <table class="hr-table" id="tblTraining">
          <thead><tr><th>#</th><th>Staff Name</th><th>Department</th><th>Title</th><th>Category</th><th>Provider</th><th>Status</th><th>Completed</th><th>Expiry</th><th>Actions</th></tr></thead>
          <tbody><tr><td colspan="10" class="hr-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ================================================================
       8. ORG CHART / REPORTING HIERARCHY (Staff HR Advanced Capability
          Programme, Phase 8, 2026-08-19) — STRICTLY READ-ONLY. Derived
          every load from the same reportingManagerId field the existing
          writers own; no cached tree, no second source of truth. Also a
          diagnostic surface (modelled on Work_schedules' blocked-staff
          panel): states that validateManagerAssignment() deliberately
          tolerates at write time are reported here rather than silently
          producing an incomplete-looking-complete chart.
  ================================================================= -->
  <div class="hr-panel<?= $at === 'orgchart' ? ' active' : '' ?>" id="panelOrgChart">
    <div class="hr-card">
      <div class="hr-card-title">
        <span><i class="fa fa-sitemap"></i> Reporting Hierarchy</span>
        <button class="hr-btn hr-btn-ghost hr-btn-sm" onclick="HR.loadOrgChart()"><i class="fa fa-refresh"></i> Refresh</button>
      </div>
      <div class="hr-toolbar">
        <div class="hr-fg">
          <label>Search</label>
          <input type="text" id="orgSearch" placeholder="Name, position or department…" oninput="HR.renderOrgChart()">
        </div>
        <div class="hr-fg">
          <label>Show</label>
          <select id="orgShowInactive" onchange="HR.renderOrgChart()">
            <option value="0">Active staff only</option>
            <option value="1">Include inactive</option>
          </select>
        </div>
      </div>
      <div id="orgIssuesWrap"></div>
      <div id="orgChartWrap" style="margin-top:6px;">
        <p class="hr-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</p>
      </div>
    </div>
  </div>

</div><!-- /.hr-wrap -->
</section>
</div><!-- /.content-wrapper -->


<!-- ================================================================
     MODALS
================================================================= -->


<!-- Job Posting Modal -->
<div class="hr-modal-overlay" id="modalJob">
  <div class="hr-modal hr-modal-lg">
    <div class="hr-modal-head">
      <div>
        <div class="hr-modal-title"><i class="fa fa-briefcase"></i> <span id="modalJobTitle">New Job Posting</span></div>
        <div class="hr-modal-sub">Vacancies opened here feed the applicant pipeline and the hire-to-staff conversion.</div>
      </div>
      <button type="button" class="hr-modal-x" onclick="HR.closeModal('modalJob')" aria-label="Close">&times;</button>
    </div>

    <div class="hr-modal-body">
      <input type="hidden" id="jobId">

      <div class="hr-form-section">
        <div class="hr-form-legend">Role</div>
        <div class="hr-modal-grid">
          <div class="hr-fg" style="grid-column:1/-1;">
            <label>Job Title <span class="req">*</span></label>
            <input type="text" id="jobTitle" placeholder="e.g. PGT Mathematics" maxlength="150">
          </div>
          <div class="hr-fg">
            <label>Department <span class="req">*</span></label>
            <select id="jobDept"><option value="">-- Select --</option></select>
          </div>
          <div class="hr-fg">
            <label>Positions <span class="req">*</span></label>
            <input type="number" id="jobPositions" min="1" value="1">
          </div>
        </div>
        <div class="hr-dept-snapshot" id="jobDeptSnapshot" style="display:none;"></div>
      </div>

      <div class="hr-form-section">
        <div class="hr-form-legend">Requirements</div>
        <div class="hr-modal-grid">
          <div class="hr-fg" style="grid-column:1/-1;">
            <label>Description</label>
            <textarea id="jobDescription" rows="3" placeholder="Teach Mathematics to classes IX-XII as per the CBSE curriculum."></textarea>
          </div>
          <div class="hr-fg" style="grid-column:1/-1;">
            <label>Qualifications</label>
            <textarea id="jobQualifications" rows="2" placeholder="M.Sc. Mathematics with B.Ed."></textarea>
          </div>
          <div class="hr-fg" style="grid-column:1/-1;">
            <label>Experience Required</label>
            <input type="text" id="jobExperience" placeholder="e.g. 3+ years" maxlength="100">
          </div>
        </div>
      </div>

      <div class="hr-form-section">
        <div class="hr-form-legend">Compensation &amp; timeline</div>
        <div class="hr-modal-grid">
          <div class="hr-fg">
            <label>Salary Range Min</label>
            <input type="number" id="jobSalaryMin" min="0" step="100" placeholder="0">
          </div>
          <div class="hr-fg">
            <label>Salary Range Max</label>
            <input type="number" id="jobSalaryMax" min="0" step="100" placeholder="0">
          </div>
          <div class="hr-fg">
            <label>Deadline</label>
            <input type="date" id="jobDeadline">
          </div>
          <div class="hr-fg">
            <label>Status</label>
            <select id="jobStatus">
              <option value="Open">Open</option>
              <option value="Closed">Closed</option>
              <option value="On_Hold">On Hold</option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <div class="hr-modal-actions">
      <button class="hr-btn hr-btn-ghost" onclick="HR.closeModal('modalJob')">Cancel</button>
      <button class="hr-btn hr-btn-primary" onclick="HR.saveJob()"><i class="fa fa-check"></i> Save Job Posting</button>
    </div>
  </div>
</div>

<!-- Circular Viewer Modal -->
<div class="hr-modal-overlay" id="modalCircular">
  <div class="hr-modal" style="max-width:680px;">
    <div class="hr-modal-title">
      <i class="fa fa-bullhorn"></i> <span id="circularViewTitle">Job Circular</span>
    </div>
    <div id="circularPosterWrap" style="max-height:70vh;overflow-y:auto;padding:4px;">
      <div style="text-align:center;padding:40px;color:var(--t3);"><i class="fa fa-spinner fa-spin" style="font-size:24px;"></i><br>Loading circular...</div>
    </div>
    <div class="hr-modal-actions">
      <button class="hr-btn hr-btn-ghost" onclick="HR.closeModal('modalCircular')">Close</button>
      <button class="hr-btn hr-btn-ghost" onclick="HR.printCircular()" title="Print / Save as PDF"><i class="fa fa-print"></i> Print</button>
      <button class="hr-btn hr-btn-primary" onclick="HR.regenCircular()" id="btnRegenCirc"><i class="fa fa-refresh"></i> Regenerate</button>
    </div>
  </div>
</div>

<!-- Applicant Modal -->
<div class="hr-modal-overlay" id="modalApplicant">
  <div class="hr-modal" style="max-width:580px;">
    <div class="hr-modal-title"><i class="fa fa-user-plus"></i> <span id="modalApplicantTitle">Add Applicant</span></div>
    <input type="hidden" id="applicantId">
    <input type="hidden" id="applicantJobId">
    <div class="hr-modal-grid">
      <div class="hr-fg">
        <label>Full Name <span class="req">*</span></label>
        <input type="text" id="applicantName" placeholder="Full name" maxlength="100">
      </div>
      <div class="hr-fg">
        <label>Email</label>
        <input type="email" id="applicantEmail" placeholder="email@example.com">
      </div>
      <div class="hr-fg">
        <label>Phone</label>
        <div class="phone-ig"><span class="phone-pfx">+91</span><input type="tel" id="applicantPhone" placeholder="Phone number" maxlength="15"></div>
      </div>
      <div class="hr-fg">
        <label>Status</label>
        <select id="applicantStatus">
          <option value="Applied">Applied</option>
          <option value="Shortlisted">Shortlisted</option>
          <option value="Interview">Interview</option>
          <option value="Selected">Selected</option>
          <option value="Rejected">Rejected</option>
          <option value="Joined">Joined</option>
        </select>
      </div>
      <div class="hr-fg">
        <label>Interview Date</label>
        <input type="date" id="applicantInterview">
      </div>
      <div class="hr-fg">
        <label>Rating</label>
        <div class="hr-stars" id="applicantRatingStars">
          <i class="fa fa-star-o" data-v="1"></i>
          <i class="fa fa-star-o" data-v="2"></i>
          <i class="fa fa-star-o" data-v="3"></i>
          <i class="fa fa-star-o" data-v="4"></i>
          <i class="fa fa-star-o" data-v="5"></i>
        </div>
        <input type="hidden" id="applicantRating" value="0">
      </div>
      <div class="hr-fg" style="grid-column:1/-1;">
        <label>Interview Notes</label>
        <textarea id="applicantNotes" rows="3" placeholder="Notes from interview..."></textarea>
      </div>
    </div>
    <div class="hr-modal-actions">
      <button class="hr-btn hr-btn-ghost" onclick="HR.closeModal('modalApplicant')">Cancel</button>
      <button class="hr-btn hr-btn-primary" id="btnSaveApplicant" onclick="HR.saveApplicant()"><i class="fa fa-check"></i> Save</button>
    </div>
  </div>
</div>

<!-- Leave Type Modal -->
<div class="hr-modal-overlay" id="modalLeaveType">
  <div class="hr-modal">
    <div class="hr-modal-title"><i class="fa fa-list"></i> <span id="modalLeaveTypeTitle">Add Leave Type</span></div>
    <input type="hidden" id="leaveTypeId">
    <div class="hr-modal-grid">
      <div class="hr-fg">
        <label>Name <span class="req">*</span></label>
        <input type="text" id="ltName" placeholder="e.g. Casual Leave" maxlength="60">
      </div>
      <div class="hr-fg">
        <label>Code <span class="req">*</span></label>
        <input type="text" id="ltCode" placeholder="e.g. CL" maxlength="10" style="text-transform:uppercase;">
      </div>
      <div class="hr-fg">
        <label>Days / Year <span class="req">*</span></label>
        <input type="number" id="ltDays" min="0" value="12">
      </div>
      <div class="hr-fg">
        <label>Carry Forward</label>
        <select id="ltCarry">
          <option value="no">No</option>
          <option value="yes">Yes</option>
        </select>
      </div>
      <div class="hr-fg">
        <label>Max Carry Days</label>
        <input type="number" id="ltMaxCarry" min="0" value="0">
      </div>
      <div class="hr-fg">
        <label>Paid Leave</label>
        <select id="ltPaid">
          <option value="true">Yes</option>
          <option value="false">No</option>
        </select>
      </div>
      <div class="hr-fg" style="grid-column:1/-1;">
        <label>Description</label>
        <textarea id="ltDescription" rows="2" placeholder="Brief description..."></textarea>
      </div>
      <!-- Workforce Policy Programme — Leave Phases 2/3/5/6. All default to
           current, non-behavior-changing values; each only takes effect once
           its own flag (leave_policy_v2_flags.php) is turned on for this
           school. Safe to fill in ahead of time. -->
      <div class="hr-fg" style="grid-column:1/-1;border-top:1px dashed #ddd;padding-top:10px;margin-top:2px;">
        <label style="font-weight:700;color:#888;font-size:11px;text-transform:uppercase;letter-spacing:.04em;">Policy Details (effective-dated)</label>
      </div>
      <div class="hr-fg">
        <label>Effective From</label>
        <input type="date" id="ltEffectiveFrom">
        <small style="color:#999;">Blank = today. A change here creates a NEW dated version — past balances are never rewritten.</small>
      </div>
      <div class="hr-fg">
        <label>Accrual Mode</label>
        <select id="ltAccrualMode">
          <option value="lump_sum">Lump Sum (full year upfront)</option>
          <option value="monthly">Monthly (1/12th credited each month)</option>
        </select>
      </div>
      <div class="hr-fg">
        <label>Waiting Period (days)</label>
        <input type="number" id="ltWaitingPeriod" min="0" value="0">
        <small style="color:#999;">Days after joining before this type may be applied. 0 = no delay.</small>
      </div>
      <div class="hr-fg">
        <label>Maximum Balance</label>
        <input type="number" id="ltMaxBalance" min="0" placeholder="No ceiling">
        <small style="color:#999;">Caps how much an employee may HOLD at once (distinct from Max Carry, above). Blank = no ceiling.</small>
      </div>
    </div>
    <div class="hr-modal-actions">
      <button class="hr-btn hr-btn-ghost" onclick="HR.closeModal('modalLeaveType')">Cancel</button>
      <button class="hr-btn hr-btn-primary" onclick="HR.saveLeaveType()"><i class="fa fa-check"></i> Save</button>
    </div>
  </div>
</div>

<!-- Leave Request Modal -->
<div class="hr-modal-overlay" id="modalLeaveRequest">
  <div class="hr-modal">
    <div class="hr-modal-title"><i class="fa fa-calendar-minus-o"></i> New Leave Request</div>
    <input type="hidden" id="leaveReqId">
    <div class="hr-modal-grid">
      <div class="hr-fg">
        <label>Staff <span class="req">*</span></label>
        <select id="lrStaff"><option value="">-- Select --</option></select>
      </div>
      <div class="hr-fg">
        <label>Leave Type <span class="req">*</span></label>
        <select id="lrType"><option value="">-- Select --</option></select>
      </div>
      <div class="hr-fg">
        <label>Start Date <span class="req">*</span></label>
        <input type="date" id="lrStart">
      </div>
      <div class="hr-fg">
        <label>End Date <span class="req">*</span></label>
        <input type="date" id="lrEnd">
      </div>
      <div class="hr-fg" style="grid-column:1/-1;">
        <label>Reason</label>
        <textarea id="lrReason" rows="2" placeholder="Reason for leave..."></textarea>
      </div>
      <div class="hr-fg">
        <label><input type="checkbox" id="lrHalfDay"> Half Day</label>
      </div>
    </div>
    <div class="hr-modal-actions">
      <button class="hr-btn hr-btn-ghost" onclick="HR.closeModal('modalLeaveRequest')">Cancel</button>
      <button class="hr-btn hr-btn-primary" onclick="HR.saveLeaveRequest()"><i class="fa fa-check"></i> Submit</button>
    </div>
  </div>
</div>

<!-- Approve / Reject Leave Modal -->
<div class="hr-modal-overlay" id="modalLeaveAction">
  <div class="hr-modal" style="max-width:400px;">
    <div class="hr-modal-title"><i class="fa fa-gavel"></i> <span id="leaveActionTitle">Approve Leave</span></div>
    <input type="hidden" id="leaveActionId">
    <input type="hidden" id="leaveActionType">
    <div class="hr-fg">
      <label>Remarks</label>
      <textarea id="leaveActionRemarks" rows="3" placeholder="Optional remarks..."></textarea>
    </div>
    <div class="hr-modal-actions">
      <button class="hr-btn hr-btn-ghost" onclick="HR.closeModal('modalLeaveAction')">Cancel</button>
      <button class="hr-btn hr-btn-primary" id="btnLeaveAction" onclick="HR.confirmLeaveAction()"><i class="fa fa-check"></i> Confirm</button>
    </div>
  </div>
</div>

<!-- Salary Structure Modal -->
<div class="hr-modal-overlay" id="modalSalary">
  <div class="hr-modal" style="max-width:680px;">
    <div class="hr-modal-title"><i class="fa fa-sitemap"></i> <span id="modalSalaryTitle">Add Salary Structure</span></div>
    <input type="hidden" id="salaryId">
    <div class="hr-modal-grid hr-salary-grid">
      <div class="hr-fg" style="grid-column:1/-1;">
        <label>Staff <span class="req">*</span></label>
        <select id="salStaff"><option value="">-- Select --</option></select>
      </div>
      <div class="hr-fg-group">
        <div class="hr-fg-group-title">Earnings</div>
        <div class="hr-modal-grid">
          <div class="hr-fg"><label>Basic</label><input type="number" id="salBasic" min="0" step="100" value="0" oninput="HR.calcSalary()"></div>
          <div class="hr-fg"><label>HRA</label><input type="number" id="salHRA" min="0" step="100" value="0" oninput="HR.calcSalary()"></div>
          <div class="hr-fg"><label>DA</label><input type="number" id="salDA" min="0" step="100" value="0" oninput="HR.calcSalary()"></div>
          <div class="hr-fg"><label>TA</label><input type="number" id="salTA" min="0" step="100" value="0" oninput="HR.calcSalary()"></div>
          <div class="hr-fg"><label>Medical</label><input type="number" id="salMedical" min="0" step="100" value="0" oninput="HR.calcSalary()"></div>
          <div class="hr-fg"><label>Special Allowance</label><input type="number" id="salSpecial" min="0" step="100" value="0" oninput="HR.calcSalary()"></div>
        </div>
      </div>
      <div class="hr-fg-group">
        <div class="hr-fg-group-title">Deductions</div>
        <div class="hr-modal-grid">
          <div class="hr-fg"><label>PF Employee %</label><input type="number" id="salPFEmp" min="0" max="100" step="0.1" value="12" oninput="HR.calcSalary()"></div>
          <div class="hr-fg"><label>PF Employer %</label><input type="number" id="salPFEr" min="0" max="100" step="0.1" value="12" oninput="HR.calcSalary()"></div>
          <div class="hr-fg"><label>ESI Employee %</label><input type="number" id="salESIEmp" min="0" max="100" step="0.1" value="0.75" oninput="HR.calcSalary()"></div>
          <div class="hr-fg"><label>ESI Employer %</label><input type="number" id="salESIEr" min="0" max="100" step="0.1" value="3.25" oninput="HR.calcSalary()"></div>
          <div class="hr-fg"><label>Professional Tax</label><input type="number" id="salPT" min="0" step="50" value="200" oninput="HR.calcSalary()"></div>
          <div class="hr-fg"><label>TDS %</label><input type="number" id="salTDS" min="0" max="100" step="0.1" value="0" oninput="HR.calcSalary()"></div>
        </div>
      </div>
      <div class="hr-salary-summary">
        <div><span>Gross:</span> <strong id="salGrossDisplay">0.00</strong></div>
        <div style="display:flex;flex-direction:column;gap:2px">
          <div><span>Deductions:</span> <strong id="salDeductDisplay">0.00</strong></div>
          <div id="salDeductBreakdown" style="font-size:10.5px;color:var(--t3);font-family:var(--font-m)"></div>
        </div>
        <div class="hr-salary-net"><span>Net Pay:</span> <strong id="salNetDisplay" style="color:var(--gold)">0.00</strong></div>
        <div id="salNetWarning" style="display:none;font-size:11px;color:#dc2626;width:100%;text-align:center;margin-top:4px"><i class="fa fa-exclamation-triangle"></i> Deductions exceed gross pay. Net pay capped at 0.</div>
      </div>
    </div>
    <div class="hr-modal-actions">
      <button class="hr-btn hr-btn-ghost" onclick="HR.closeModal('modalSalary')">Cancel</button>
      <button class="hr-btn hr-btn-primary" onclick="HR.saveSalary()"><i class="fa fa-check"></i> Save</button>
    </div>
  </div>
</div>

<!-- Generate Payroll Modal -->
<!-- W3.2.5/UAT: widened from 480px. The pre-flight result now carries a
     per-employee blocker table (N-15), and a 13-row table inside a 480px box
     was unreadable — the operator has to act on these rows, not squint at them.
     Width is capped at 980px and falls back to 96vw on small screens. -->
<div class="hr-modal-overlay" id="modalGenPayroll">
  <div class="hr-modal hr-modal-wide">
    <div class="hr-modal-title"><i class="fa fa-play-circle"></i> Generate Payroll</div>
    <div class="hr-modal-grid">
      <div class="hr-fg">
        <label>Month <span class="req">*</span></label>
        <select id="genMonth">
          <option value="January">January</option><option value="February">February</option><option value="March">March</option>
          <option value="April">April</option><option value="May">May</option><option value="June">June</option>
          <option value="July">July</option><option value="August">August</option><option value="September">September</option>
          <option value="October">October</option><option value="November">November</option><option value="December">December</option>
        </select>
      </div>
      <div class="hr-fg">
        <label>Year <span class="req">*</span></label>
        <input type="number" id="genYear" min="2020" max="2050" value="<?= date('Y') ?>">
      </div>
    </div>

    <!-- Preflight result area (hidden by default) -->
    <div id="genPreflightResult" style="display:none;"></div>

    <div class="hr-modal-actions" id="genActions">
      <button class="hr-btn hr-btn-ghost" onclick="HR.closeModal('modalGenPayroll')">Cancel</button>
      <button class="hr-btn hr-btn-primary" id="btnRunPayroll"><i class="fa fa-play"></i> Generate</button>
    </div>
  </div>
</div>

<!-- Payment Modal -->
<div class="hr-modal-overlay" id="modalPayment">
  <div class="hr-modal" style="max-width:440px;">
    <div class="hr-modal-title"><i class="fa fa-credit-card"></i> Mark Payroll as Paid</div>
    <input type="hidden" id="payRunId">
    <div id="payRunSummary" style="padding:12px 14px;background:var(--bg3);border-radius:8px;margin-bottom:14px;font-size:13px;color:var(--t2)"></div>
    <div class="hr-modal-grid">
      <div class="hr-fg">
        <label>Payment Date <span class="req">*</span></label>
        <input type="date" id="payDate" value="<?= date('Y-m-d') ?>">
      </div>
      <div class="hr-fg">
        <label>Payment Mode <span class="req">*</span></label>
        <select id="payMode">
          <option value="Bank Transfer">Bank Transfer</option>
          <option value="UPI">UPI</option>
          <option value="Cash">Cash</option>
        </select>
      </div>
      <div class="hr-fg" style="grid-column:1/-1">
        <label>Transaction Reference <small style="opacity:.6">(Optional)</small></label>
        <input type="text" id="payReference" placeholder="e.g. NEFT/UTR number, UPI ID, Receipt no.">
      </div>
    </div>
    <div class="hr-modal-actions">
      <button class="hr-btn hr-btn-ghost" onclick="HR.closeModal('modalPayment')">Cancel</button>
      <button class="hr-btn hr-btn-primary" onclick="HR.confirmPayment()" id="btnConfirmPay"><i class="fa fa-check-circle"></i> Confirm Payment</button>
    </div>
  </div>
</div>

<!-- Reason-Prompt Modal (shared by Unlock + Regenerate) -->
<div class="hr-modal-overlay" id="modalReasonPrompt">
  <div class="hr-modal" style="max-width:480px;">
    <div class="hr-modal-title" id="reasonPromptTitle"><i class="fa fa-comment"></i> Reason Required</div>
    <div id="reasonPromptSubtitle" style="padding:10px 12px;background:var(--bg3);border-radius:8px;margin-bottom:14px;font-size:12.5px;color:var(--t2);line-height:1.5"></div>
    <div class="hr-fg" style="margin-bottom:6px">
      <label>Reason <span class="req">*</span> <small style="opacity:.6">(min 10 chars)</small></label>
      <textarea id="reasonPromptText" rows="3" placeholder="Why are you doing this..." style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg3);color:var(--t1);font-size:13px;font-family:inherit;resize:vertical;box-sizing:border-box"></textarea>
      <div id="reasonPromptHint" style="font-size:11px;color:var(--t3);margin-top:4px">0 chars · need 10+</div>
    </div>
    <div class="hr-modal-actions">
      <button class="hr-btn hr-btn-ghost" onclick="HR.closeModal('modalReasonPrompt')">Cancel</button>
      <button class="hr-btn hr-btn-primary" id="btnReasonPromptConfirm" disabled><i class="fa fa-check"></i> Confirm</button>
    </div>
  </div>
</div>

<!-- Appraisal Modal -->
<div class="hr-modal-overlay" id="modalAppraisal">
  <div class="hr-modal" style="max-width:660px;">
    <div class="hr-modal-title"><i class="fa fa-star"></i> <span id="modalAppraisalTitle">New Appraisal</span></div>
    <input type="hidden" id="appraisalId">
    <div class="hr-modal-grid">
      <div class="hr-fg" style="grid-column:1/-1;">
        <label>Staff <span class="req">*</span></label>
        <select id="apStaff" onchange="HR.onApStaffChange()"><option value="">-- Select Staff --</option></select>
      </div>
      <div class="hr-fg">
        <label>Department</label>
        <input type="text" id="apDepartment" readonly placeholder="Auto-filled from staff" style="background:var(--bg3,#F7ECE7);cursor:not-allowed;">
      </div>
      <div class="hr-fg">
        <label>Appraisal Type <span class="req">*</span></label>
        <select id="apType">
          <option value="Annual">Annual</option>
          <option value="Probation">Probation</option>
          <option value="Promotion">Promotion</option>
          <option value="Mid-Year">Mid-Year</option>
          <option value="Special">Special</option>
        </select>
      </div>
      <div class="hr-fg">
        <label>Period <span class="req">*</span></label>
        <input type="text" id="apPeriod" placeholder="e.g. 2025-26 Q1" maxlength="30">
      </div>
      <div class="hr-fg">
        <label>Reviewer</label>
        <select id="apReviewer"><option value="">-- Select --</option></select>
      </div>
      <div class="hr-fg">
        <label>Recommendation</label>
        <select id="apRecommendation">
          <option value="none">None</option>
          <option value="increment">Increment</option>
          <option value="promotion">Promotion</option>
          <option value="warning">Warning</option>
          <option value="termination">Termination</option>
        </select>
      </div>
      <div class="hr-fg">
        <label>Status</label>
        <input type="text" id="apStatus" value="Draft" readonly style="background:var(--bg3,#F7ECE7);cursor:not-allowed;">
      </div>
    </div>
    <div class="hr-fg-group" style="margin-top:8px;">
      <div class="hr-fg-group-title">Performance Scores (1-10)</div>
      <div class="hr-modal-grid hr-scores-grid">
        <div class="hr-fg"><label>Teaching</label><input type="number" id="apTeaching" min="1" max="10" value="5" oninput="HR.calcAppraisal()"></div>
        <div class="hr-fg"><label>Punctuality</label><input type="number" id="apPunctuality" min="1" max="10" value="5" oninput="HR.calcAppraisal()"></div>
        <div class="hr-fg"><label>Behavior</label><input type="number" id="apBehavior" min="1" max="10" value="5" oninput="HR.calcAppraisal()"></div>
        <div class="hr-fg"><label>Innovation</label><input type="number" id="apInnovation" min="1" max="10" value="5" oninput="HR.calcAppraisal()"></div>
        <div class="hr-fg"><label>Teamwork</label><input type="number" id="apTeamwork" min="1" max="10" value="5" oninput="HR.calcAppraisal()"></div>
        <div class="hr-fg hr-overall-box"><label>Overall</label><div class="hr-overall-val" id="apOverall">5.0</div></div>
      </div>
    </div>
    <div class="hr-modal-grid" style="margin-top:8px;">
      <div class="hr-fg" style="grid-column:1/-1;"><label>Strengths</label><textarea id="apStrengths" rows="2" placeholder="Key strengths..."></textarea></div>
      <div class="hr-fg" style="grid-column:1/-1;"><label>Areas of Improvement</label><textarea id="apImprovement" rows="2" placeholder="Areas to work on..."></textarea></div>
      <div class="hr-fg" style="grid-column:1/-1;"><label>Goals</label><textarea id="apGoals" rows="2" placeholder="Goals for next period..."></textarea></div>
    </div>
    <div class="hr-modal-actions">
      <button class="hr-btn hr-btn-ghost" onclick="HR.closeModal('modalAppraisal')">Cancel</button>
      <button class="hr-btn hr-btn-primary" onclick="HR.saveAppraisal()"><i class="fa fa-check"></i> Save</button>
    </div>
  </div>
</div>

<!-- Training / Development Modal (Staff HR Advanced Capability Programme,
     Phase 7) — no certificate upload here; attach via the staff's own
     Edit page → HR Documents (Phase A's canonical Document Service). -->
<div class="hr-modal-overlay" id="modalTraining">
  <div class="hr-modal" style="max-width:600px;">
    <div class="hr-modal-title"><i class="fa fa-graduation-cap"></i> <span id="modalTrainingTitle">New Training Record</span></div>
    <input type="hidden" id="trainingId">
    <div class="hr-modal-grid">
      <div class="hr-fg" style="grid-column:1/-1;">
        <label>Staff <span class="req">*</span></label>
        <select id="trStaff"><option value="">-- Select Staff --</option></select>
      </div>
      <div class="hr-fg" style="grid-column:1/-1;">
        <label>Title <span class="req">*</span></label>
        <input type="text" id="trTitle" placeholder="e.g. First Aid Certification" maxlength="150">
      </div>
      <div class="hr-fg">
        <label>Provider</label>
        <input type="text" id="trProvider" placeholder="e.g. Red Cross" maxlength="120">
      </div>
      <div class="hr-fg">
        <label>Category</label>
        <select id="trCategory">
          <option value="Training">Training</option>
          <option value="Certification">Certification</option>
          <option value="Workshop">Workshop</option>
          <option value="Course">Course</option>
        </select>
      </div>
      <div class="hr-fg">
        <label>Status</label>
        <select id="trStatus" onchange="HR.onTrStatusChange()">
          <option value="Planned">Planned</option>
          <option value="In Progress">In Progress</option>
          <option value="Completed">Completed</option>
        </select>
      </div>
      <div class="hr-fg">
        <label>Hours</label>
        <input type="number" id="trHours" min="0" placeholder="Optional">
      </div>
      <div class="hr-fg">
        <label>Completed Date</label>
        <input type="date" id="trCompletedDate">
      </div>
      <div class="hr-fg">
        <label>Expiry Date <small style="opacity:.6">(certifications)</small></label>
        <input type="date" id="trExpiryDate">
      </div>
      <div class="hr-fg" style="grid-column:1/-1;">
        <label>Notes</label>
        <textarea id="trNotes" rows="2" placeholder="Optional notes..."></textarea>
      </div>
    </div>
    <div class="hr-modal-actions">
      <button class="hr-btn hr-btn-ghost" onclick="HR.closeModal('modalTraining')">Cancel</button>
      <button class="hr-btn hr-btn-primary" onclick="HR.saveTrainingRecord()"><i class="fa fa-check"></i> Save</button>
    </div>
  </div>
</div>

<!-- Toast Container -->
<div id="hrToastContainer"></div>


<!-- ================================================================
     STYLES
================================================================= -->
<style>
/* ================================================================
   HR Module - .hr-* prefix
   Teal/Navy theme using global CSS variables
================================================================ */
.hr-wrap{max-width:1180px;margin:0 auto;padding:20px 16px 48px}

/* Header */
.hr-header{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:20px}
.hr-header-icon{font-size:1.3rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px;margin-bottom:3px;font-family:var(--font-b)}
.hr-header-icon i{color:var(--gold);font-size:1.1rem}
.hr-breadcrumb{list-style:none;margin:0;padding:0;display:flex;gap:6px;font-size:12px;color:var(--t3);font-family:var(--font-b)}
.hr-breadcrumb li+li::before{content:'/';margin-right:6px;color:var(--t3)}
.hr-breadcrumb a{color:var(--gold);text-decoration:none}
.hr-breadcrumb a:hover{text-decoration:underline}

/* Tabs */
.hr-tabs{display:flex;gap:4px;border-bottom:2px solid var(--border);margin-bottom:22px;flex-wrap:wrap}
.hr-tab{display:inline-flex;align-items:center;gap:6px;padding:10px 16px;font-size:13px;font-weight:600;color:var(--t2);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .2s var(--ease);font-family:var(--font-b);border-radius:6px 6px 0 0}
.hr-tab:hover{color:var(--gold);background:var(--gold-dim)}
.hr-tab.active{color:var(--gold);border-bottom-color:var(--gold);background:var(--gold-dim)}
/* UX-P2-1 (same defect class as Compensation's tab bar): at narrow widths
   this module has even more top-level tabs (Dashboard/Recruitment/Leave
   Mgmt/Payroll/Appraisals/Training/Org Chart), and the last one on a wrapped
   row rendered clipped ("App...") rather than cleanly wrapped. Below the
   point where two tabs no longer fit, scroll the strip horizontally inside
   its own container instead, so every label stays intact. Desktop keeps the
   existing wrap layout untouched. */
@media (max-width:560px){
  .hr-tabs{flex-wrap:nowrap;overflow-x:auto;-webkit-overflow-scrolling:touch;margin-bottom:14px}
  .hr-tab{flex:0 0 auto;white-space:nowrap}
}
.hr-tab i{font-size:14px}

/* Panels */
.hr-panel{display:none}
.hr-panel.active{display:block}

/* Cards */
.hr-card{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:18px 20px;margin-bottom:16px;box-shadow:var(--sh)}
.hr-card-title{display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:14px;font-weight:700;color:var(--t1);margin-bottom:14px;font-family:var(--font-b)}

/* Seed suggestion card */
.hr-seed-card{border:1px dashed var(--gold);background:var(--gold-dim)}
.hr-seed-card .hr-card-title{margin-bottom:10px}
.hr-seed-pills{display:flex;flex-wrap:wrap;gap:8px}
.hr-seed-pill{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;font-family:var(--font-b);background:var(--bg2);color:var(--t1);border:1px solid var(--border);cursor:pointer;transition:all .2s;white-space:nowrap}
.hr-seed-pill:hover{border-color:var(--gold);background:var(--gold);color:#fff}
.hr-seed-pill:hover i{color:#fff}
.hr-seed-pill i{color:var(--gold);font-family:FontAwesome!important;font-size:11px;transition:color .2s}
.hr-seed-pill small{font-weight:400;opacity:.7;margin-left:2px}
.hr-seed-pill.added{background:var(--bg3);color:var(--t3);border-color:var(--border);cursor:default;opacity:.6;pointer-events:none}
.hr-seed-pill.added i::before{content:"\f00c"}
.hr-seed-card.hr-all-seeded{display:none}
.hr-card-title i{color:var(--gold)}
.hr-card-title span{display:flex;align-items:center;gap:8px}

/* Stats */
.hr-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:18px}
.hr-stat{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:18px 16px;text-align:center;box-shadow:var(--sh);transition:transform .2s var(--ease)}
.hr-stat:hover{transform:translateY(-2px)}
.hr-stat-value{font-size:1.6rem;font-weight:800;color:var(--gold);font-family:var(--font-m)}
.hr-stat-label{font-size:12px;color:var(--t3);margin-top:4px;font-family:var(--font-b)}
.hr-stat-label i{margin-right:3px}

/* Buttons */
.hr-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;font-size:13px;font-weight:600;border:none;border-radius:7px;cursor:pointer;transition:all .2s var(--ease);font-family:var(--font-b);text-decoration:none}
.hr-btn-primary{background:var(--gold);color:#fff}
.hr-btn-primary:hover{background:var(--gold2);transform:translateY(-1px)}
.hr-btn-danger{background:#dc2626;color:#fff}
.hr-btn-danger:hover{background:#b91c1c}
.hr-btn-ghost{background:transparent;color:var(--t2);border:1px solid var(--border)}
.hr-btn-ghost:hover{background:var(--gold-dim);color:var(--gold)}
.hr-btn-sm{padding:6px 12px;font-size:12px}
.hr-btn i.fa,.hr-btn .fa,.hr-act-btn i.fa,.hr-act-btn .fa{font-family:FontAwesome!important}

/* Toolbar / Filter Group */
.hr-toolbar{display:flex;gap:12px;margin-bottom:14px;flex-wrap:wrap;align-items:flex-end}
.hr-fg{display:flex;flex-direction:column;gap:4px}
.hr-fg label{font-size:12px;font-weight:600;color:var(--t3);font-family:var(--font-b);text-transform:uppercase;letter-spacing:.3px}
.hr-fg input,.hr-fg select,.hr-fg textarea{padding:8px 10px;font-size:13px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--t1);font-family:var(--font-b);outline:none;transition:border-color .2s}
.hr-fg input:focus,.hr-fg select:focus,.hr-fg textarea:focus{border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-ring)}
.hr-fg textarea{resize:vertical}
.hr-fg .req{color:#dc2626}

/* Tables */
.hr-table-wrap{overflow-x:auto}
.hr-table{width:100%;border-collapse:collapse;font-size:13px;font-family:var(--font-b)}
.hr-table thead{background:var(--bg3)}
.hr-table th{padding:10px 12px;text-align:left;font-size:12px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid var(--border);white-space:nowrap}
.hr-table td{padding:10px 12px;border-bottom:1px solid var(--border);color:var(--t1);vertical-align:middle}
.hr-table tbody tr:hover{background:var(--gold-dim)}
.hr-table .hr-num{font-family:var(--font-m);font-size:12px;color:var(--t2)}
.hr-empty{text-align:center;padding:28px 12px;color:var(--t3);font-size:13px}
.hr-empty i{margin-right:6px}

/* Org Chart (Phase 8) — indented tree, not an absolutely-positioned
   graphic: it stays readable at any depth, reflows on narrow screens,
   and needs no layout library. */
.org-tree,.org-tree ul{list-style:none;margin:0;padding:0}
.org-tree ul{margin-left:22px;border-left:1px solid var(--border);padding-left:14px}
.org-node{display:flex;align-items:center;gap:9px;padding:7px 10px;margin:3px 0;border:1px solid var(--border);border-radius:8px;background:var(--bg2);transition:border-color .15s,background .15s}
.org-node:hover{border-color:var(--gold)}
.org-node.is-match{border-color:var(--gold);background:rgba(188,90,60,.06)}
.org-node.is-inactive{opacity:.62}
.org-toggle{flex:0 0 auto;width:18px;height:18px;line-height:16px;text-align:center;border:1px solid var(--border);border-radius:4px;background:var(--bg3);cursor:pointer;font-size:10px;color:var(--t2);padding:0}
.org-toggle.leaf{visibility:hidden}
.org-name{font-weight:700;font-size:13px;color:var(--t1);font-family:var(--font-b)}
.org-meta{font-size:11.5px;color:var(--t3)}
.org-count{margin-left:auto;font-size:11px;color:var(--t3);white-space:nowrap;font-variant-numeric:tabular-nums}
.org-flag{font-size:10.5px;font-weight:700;padding:2px 7px;border-radius:20px;background:#fef3c7;color:#92400e;white-space:nowrap}
.org-flag.sev{background:#fee2e2;color:#991b1b}
.org-issues{border:1px solid #fdba74;background:#fff7ed;border-radius:8px;padding:11px 14px;margin-bottom:4px}
.org-issues h4{margin:0 0 7px;font-size:12.5px;color:#9a3412;font-family:var(--font-b)}
.org-issues ul{margin:0;padding-left:17px}
.org-issues li{font-size:12px;color:#7c2d12;margin-bottom:3px;line-height:1.45}
@media (prefers-color-scheme:dark){
  .org-issues{background:rgba(154,52,18,.14);border-color:rgba(253,186,116,.4)}
  .org-issues h4,.org-issues li{color:#fdba74}
}

/* Badges */
.hr-badge{display:inline-block;padding:3px 10px;font-size:12px;font-weight:700;border-radius:20px;text-transform:capitalize;font-family:var(--font-b);letter-spacing:.3px}
.hr-badge-pending{background:#fef3c7;color:#92400e}
.hr-badge-approved,.hr-badge-open{background:#d1fae5;color:#065f46}
.hr-badge-rejected{background:#fee2e2;color:#991b1b}
.hr-badge-cancelled{background:#e5e7eb;color:#4b5563}
.hr-badge-closed{background:#e5e7eb;color:#4b5563}
.hr-badge-draft{background:#dbeafe;color:#1e40af}
.hr-badge-info{background:#e0f2fe;color:#075985}
.hr-badge-finalized{background:#fef3c7;color:#92400e}
.hr-badge-approved{background:#e0e7ff;color:#3730a3}
.hr-badge-partially-paid{background:#fde68a;color:#78350f}
.hr-badge-paid{background:#d1fae5;color:#065f46}
.hr-badge-selected{background:#d1fae5;color:#065f46}
.hr-badge-applied{background:#dbeafe;color:#1e40af}
.hr-badge-shortlisted{background:#ede9fe;color:#5b21b6}
.hr-badge-interviewed{background:#fef3c7;color:#92400e}

/* Stars */
.hr-stars{display:inline-flex;gap:3px;cursor:pointer;font-size:18px;color:var(--gold)}
.hr-stars i{transition:transform .15s}
.hr-stars i:hover{transform:scale(1.2)}
.hr-stars-display{display:inline-flex;gap:2px;color:var(--gold);font-size:14px}
.hr-stars-display .empty{color:var(--border)}

/* Pipeline bar */
.hr-pipeline-bar{display:flex;align-items:center;gap:6px;flex-wrap:wrap;padding:4px 0}
.hr-pipe-stage{text-align:center;flex:1;min-width:80px;padding:12px 8px;border-radius:8px;background:var(--bg3);border:1px solid var(--border);transition:all .18s var(--ease);cursor:pointer}
.hr-pipe-stage:hover{border-color:var(--gold);background:var(--gold-dim);transform:translateY(-1px)}
.hr-pipe-count{font:800 1.3rem/1 var(--font-m);color:var(--gold);margin-bottom:4px}
.hr-pipe-label{font:600 10px/1 var(--font-b);color:var(--t3);text-transform:uppercase;letter-spacing:.4px}
.hr-pipe-stage[data-stage="Applied"] .hr-pipe-count{color:#4AB5E3}
.hr-pipe-stage[data-stage="Shortlisted"] .hr-pipe-count{color:#d97706}
.hr-pipe-stage[data-stage="Interviewed"] .hr-pipe-count{color:#8b5cf6}
.hr-pipe-stage[data-stage="Selected"] .hr-pipe-count{color:#BC5A3C}
.hr-pipe-stage[data-stage="Hired"] .hr-pipe-count{color:#15803d}
.hr-pipe-rejected .hr-pipe-count{color:#E05C6F}
.hr-pipe-rejected{border-style:dashed}
.hr-pipe-arrow{color:var(--t4);font-size:10px;flex:0 0 auto}
.hr-pipe-sep{width:1px;height:32px;background:var(--border);margin:0 4px;flex:0 0 auto}
@media(max-width:640px){
  .hr-pipeline-bar{gap:4px}
  .hr-pipe-stage{min-width:60px;padding:8px 4px}
  .hr-pipe-count{font-size:1rem}
  .hr-pipe-label{font-size:9px}
  .hr-pipe-arrow{display:none}
  .hr-pipe-sep{display:none}
}

/* Sub-tabs */
.hr-sub-tabs{display:flex;gap:4px;margin-bottom:16px;flex-wrap:wrap}
.hr-sub-tab{padding:8px 14px;font-size:12px;font-weight:600;border:1px solid var(--border);border-radius:7px;background:var(--bg);color:var(--t2);cursor:pointer;transition:all .2s var(--ease);font-family:var(--font-b)}
.hr-sub-tab:hover{background:var(--gold-dim);color:var(--gold)}
.hr-sub-tab.active{background:var(--gold);color:#fff;border-color:var(--gold)}
.hr-sub-panel{display:none}
.hr-sub-panel.active{display:block}

/* Modal */
.hr-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;padding:20px}
.hr-modal-overlay.open{display:flex;animation:hrFadeIn .18s cubic-bezier(.4,0,.2,1) backwards}
.hr-modal{background:var(--bg2);border-radius:12px;padding:24px;max-width:580px;width:95%;max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.hr-modal-overlay.open .hr-modal{animation:hrModalIn .22s cubic-bezier(.4,0,.2,1) backwards}
.hr-modal-title{font-size:16px;font-weight:700;color:var(--t1);margin-bottom:18px;display:flex;align-items:center;gap:8px;font-family:var(--font-b)}
.hr-modal-title i{color:var(--gold)}
.hr-modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:18px;padding-top:14px;border-top:1px solid var(--border)}
/* Large form modal: fixed head + footer, only the body scrolls, so Cancel/Save
   can never be pushed past the viewport edge (reported at 90% browser zoom). */
.hr-modal-lg{max-width:880px;width:min(880px,96vw);padding:0;display:flex;flex-direction:column;max-height:88vh;overflow:hidden}
.hr-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:18px 24px;border-bottom:1px solid var(--border);flex:0 0 auto}
.hr-modal-head .hr-modal-title{margin-bottom:0}
.hr-modal-sub{font-size:12px;color:var(--t3);font-family:var(--font-b);margin-top:4px}
.hr-modal-x{background:none;border:0;font-size:24px;line-height:1;color:var(--t3);cursor:pointer;padding:2px 8px;border-radius:6px;transition:all .2s var(--ease)}
.hr-modal-x:hover{color:var(--t1);background:var(--gold-dim)}
.hr-modal-body{padding:20px 24px;overflow-y:auto;flex:1 1 auto}
.hr-modal-lg .hr-modal-actions{margin-top:0;padding:14px 24px;flex:0 0 auto;background:var(--bg2)}
.hr-form-section{margin-bottom:22px}
.hr-form-section:last-child{margin-bottom:0}
.hr-form-legend{font:700 11px/1 var(--font-b);letter-spacing:.9px;text-transform:uppercase;color:var(--t3);margin-bottom:10px;display:flex;align-items:center;gap:7px}
.hr-form-legend::after{content:'';flex:1;height:1px;background:var(--border)}
/* Who already works in the chosen department. The roster is already in
   deptCache (get_departments returns staff[] per department); it was simply
   never surfaced where a recruiter decides how many positions to open. */
.hr-dept-snapshot{margin-top:12px;padding:12px 14px;border:1px solid var(--border);border-radius:10px;background:var(--bg)}
.hr-dept-snap-head{display:flex;align-items:center;gap:7px;font-size:12.5px;font-weight:700;color:var(--t1);margin-bottom:9px;font-family:var(--font-b)}
.hr-dept-snap-head i{color:var(--gold)}
.hr-dept-snap-list{display:flex;flex-wrap:wrap;gap:6px}
.hr-dept-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;border:1px solid var(--border);background:var(--bg2);font-size:11.5px;color:var(--t1);font-family:var(--font-b)}
.hr-dept-chip small{color:var(--t3);font-size:10.5px}
.hr-dept-snap-empty{font-size:12px;color:var(--t3);font-family:var(--font-b)}
@media(max-width:768px){.hr-modal-lg{max-height:92vh}.hr-modal-body{padding:16px}.hr-modal-head,.hr-modal-lg .hr-modal-actions{padding:14px 16px}}
.hr-modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
/* Wide variant for modals that carry a review table rather than a short form.
   Scoped to a modifier so the 580px default keeps applying everywhere else. */
.hr-modal-wide{max-width:980px !important;width:96vw}
@media (max-width:1024px){ .hr-modal-wide{max-width:96vw !important} }

/* Salary Structure */
.hr-fg-group{grid-column:1/-1;border:1px solid var(--border);border-radius:8px;padding:14px;margin-top:4px}
.hr-fg-group-title{font-size:12px;font-weight:700;color:var(--gold);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;font-family:var(--font-b)}
.hr-salary-summary{grid-column:1/-1;display:flex;gap:20px;align-items:center;flex-wrap:wrap;padding:12px 14px;background:var(--bg3);border-radius:8px;margin-top:4px;font-family:var(--font-m);font-size:13px;color:var(--t2)}
.hr-salary-summary strong{color:var(--t1);font-size:14px}
.hr-salary-net strong{color:var(--gold);font-size:16px}

/* Salary table detail row */
.hr-sal-row{transition:background .15s}
.hr-sal-row:hover{background:var(--gold-dim) !important}
.hr-sal-row-open{background:var(--gold-dim) !important;border-bottom-color:var(--gold-ring)}
.hr-sal-detail{display:none}
.hr-sal-detail.open{display:table-row}
.hr-sal-detail>td{padding:0 !important;border-bottom:2px solid var(--gold-ring)}
.hr-sal-breakdown{display:grid;grid-template-columns:1fr 1fr 1fr;gap:0;background:var(--bg3);animation:hrSalSlide .2s ease}
@keyframes hrSalSlide{from{opacity:0;max-height:0}to{opacity:1;max-height:400px}}
.hr-sal-bd-section{padding:16px 20px;border-right:1px solid var(--border)}
.hr-sal-bd-section:last-child{border-right:none}
.hr-sal-bd-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--t2);margin-bottom:10px;display:flex;align-items:center;gap:6px;font-family:var(--font-b)}
.hr-sal-dl{display:flex;flex-direction:column;gap:0;margin:0}
.hr-sal-dl>div{display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px dashed var(--border);font-size:13px}
.hr-sal-dl>div:last-child{border-bottom:none}
.hr-sal-dl dt{color:var(--t3);font-weight:400;margin:0}
.hr-sal-dl dd{color:var(--t1);font-weight:600;margin:0;font-family:var(--font-m)}
.hr-sal-dl-total{border-top:2px solid var(--border) !important;border-bottom:none !important;margin-top:4px;padding-top:8px !important}
.hr-sal-dl-total dt{color:var(--t1);font-weight:700}
.hr-sal-bd-net{display:flex;flex-direction:column;align-items:center;justify-content:center;background:linear-gradient(135deg,rgba(188,90,60,.06) 0%,rgba(12,30,56,.04) 100%);text-align:center}
.hr-sal-net-amount{font-size:28px;font-weight:800;color:var(--gold);font-family:var(--font-b);margin:8px 0 4px}
.hr-sal-net-formula{font-size:12px;color:var(--t3);font-family:var(--font-m)}
.hr-sal-net-formula strong{color:var(--gold)}

/* Salary tooltip */
.hr-sal-tip{position:relative;cursor:help}
.hr-sal-tip[title]{text-decoration:underline;text-decoration-style:dotted;text-underline-offset:3px;text-decoration-color:var(--border)}

@media(max-width:900px){
  .hr-sal-breakdown{grid-template-columns:1fr}
  .hr-sal-bd-section{border-right:none;border-bottom:1px solid var(--border)}
  .hr-sal-bd-section:last-child{border-bottom:none}
  #tblSalary{font-size:11px}
  #tblSalary th,#tblSalary td{padding:6px 4px}
}
.hr-salary-grid{display:flex;flex-direction:column;gap:12px}

/* Appraisal scores */
.hr-scores-grid{grid-template-columns:repeat(3,1fr)}
.hr-overall-box{display:flex;flex-direction:column;align-items:center;justify-content:center}
.hr-overall-val{font-size:1.5rem;font-weight:800;color:var(--gold);font-family:var(--font-m)}

/* Quick Actions */
.hr-quick-actions{display:flex;gap:10px;flex-wrap:wrap}
.hr-dash-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}

/* Action buttons in tables */
.hr-act-btn{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:6px;background:var(--gold-dim);color:var(--gold);border:none;cursor:pointer;transition:all .2s;font-size:13px;text-decoration:none}
.hr-act-btn:hover{background:var(--gold);color:#fff}
.hr-act-btn.danger{background:#fee2e2;color:#dc2626}
.hr-act-btn.danger:hover{background:#dc2626;color:#fff}
.hr-circular-badge{
  display:inline-flex;align-items:center;gap:5px;
  padding:4px 10px;border-radius:16px;font-size:11.5px;font-weight:600;
  background:rgba(139,92,246,.1);color:#7c3aed;
  text-decoration:none;transition:all .15s;white-space:nowrap;
}
.hr-circular-badge:hover{background:rgba(139,92,246,.2);color:#6d28d9;text-decoration:none}
.hr-circular-badge i{font-size:11px}

/* Payslip expand */
.hr-payslip-detail{display:none;background:var(--bg3);padding:0}
.hr-payslip-detail.open{display:table-row}
.hr-payslip-breakdown{padding:16px 20px;font-size:12px}
.hr-slip-sections{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:800px){.hr-slip-sections{grid-template-columns:1fr}}
.hr-slip-section{border:1px solid var(--border);border-radius:8px;overflow:hidden}
.hr-slip-section-hd{font:700 11px/1 var(--font-b);padding:8px 12px;text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:6px}
.hr-slip-section-hd.earn{background:rgba(188,90,60,.08);color:#BC5A3C}
.hr-slip-section-hd.deduct{background:rgba(220,38,38,.06);color:#b91c1c}
.hr-slip-section-hd.info{background:rgba(100,116,139,.08);color:#475569}
.hr-slip-section-hd.leave{background:rgba(124,58,237,.06);color:#6d28d9}
.hr-slip-section-hd.pay{background:rgba(188,90,60,.1);color:#BC5A3C}
.hr-slip-row{display:flex;justify-content:space-between;align-items:center;padding:6px 12px;border-bottom:1px solid var(--border)}
.hr-slip-row:last-child{border-bottom:none}
.hr-slip-row.total{background:var(--bg);font-weight:700;border-top:2px solid var(--border)}
.hr-slip-label{color:var(--t2);font:400 12px/1.4 var(--font-m)}
.hr-slip-value{color:var(--t1);font:600 12px/1.4 var(--font-m);text-align:right;font-variant-numeric:tabular-nums}
.hr-slip-value.green{color:#BC5A3C}
.hr-slip-value.red{color:#dc2626}
.hr-slip-value.amber{color:#d97706}
.hr-slip-net-row{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;margin-top:14px;border-radius:8px;background:var(--gold-dim);border:1px solid var(--gold-ring)}
.hr-slip-net-label{font:700 13px/1 var(--font-b);color:var(--gold)}
.hr-slip-net-value{font:700 16px/1 var(--font-b);color:var(--gold);font-variant-numeric:tabular-nums}

/* UX-1+UX-2: Day-classification chip strip + tooltips */
.hr-day-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;margin-bottom:14px}
.hr-day-chip{display:flex;flex-direction:column;padding:10px 12px;border-radius:8px;border:1px solid var(--border);background:var(--bg);cursor:help;transition:transform .12s var(--ease)}
.hr-day-chip:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.06)}
.hr-day-chip-label{font:600 10.5px/1.2 var(--font-b);text-transform:uppercase;letter-spacing:.4px;color:var(--t3);margin-bottom:4px;display:flex;align-items:center;gap:5px}
.hr-day-chip-value{font:700 20px/1 var(--font-b);font-variant-numeric:tabular-nums;color:var(--t1)}
.hr-day-chip-sub{font:400 10.5px/1.3 var(--font-m);color:var(--t3);margin-top:3px}
.hr-day-chip.working .hr-day-chip-label{color:#475569}
.hr-day-chip.present .hr-day-chip-label,.hr-day-chip.present .hr-day-chip-value{color:#BC5A3C}
.hr-day-chip.present{background:rgba(188,90,60,.04)}
.hr-day-chip.paid-leave .hr-day-chip-label,.hr-day-chip.paid-leave .hr-day-chip-value{color:#2563eb}
.hr-day-chip.paid-leave{background:rgba(37,99,235,.04)}
.hr-day-chip.lwp .hr-day-chip-label,.hr-day-chip.lwp .hr-day-chip-value{color:#dc2626}
.hr-day-chip.lwp{background:rgba(220,38,38,.04)}
.hr-day-chip.half-day .hr-day-chip-label,.hr-day-chip.half-day .hr-day-chip-value{color:#7c3aed}
.hr-day-chip.half-day{background:rgba(124,58,237,.05)}
.hr-day-chip.absent .hr-day-chip-label,.hr-day-chip.absent .hr-day-chip-value{color:#9a3412}
.hr-day-chip.absent{background:rgba(234,88,12,.04)}
.hr-day-dot{width:8px;height:8px;border-radius:50%;display:inline-block;flex-shrink:0}
.hr-day-dot.present{background:#10b981}
.hr-day-dot.paid-leave{background:#3b82f6}
.hr-day-dot.lwp{background:#dc2626}
.hr-day-dot.half-day{background:#a855f7}
.hr-day-dot.absent{background:#ea580c}

/* UX-2: small ⓘ help-tooltip badge — uses native title attr */
.hr-tip{display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;border-radius:50%;background:var(--bg3);color:var(--t3);font-size:9.5px;cursor:help;margin-left:4px;font-weight:700;border:1px solid var(--border);font-style:italic;font-family:Georgia,serif}
.hr-tip:hover{background:var(--t3);color:var(--bg)}

/* UX-3: Stale-data banner + row decoration */
.hr-stale-banner{display:flex;align-items:flex-start;gap:10px;padding:10px 14px;border-radius:8px;background:rgba(217,119,6,.08);border:1px solid rgba(217,119,6,.25);color:#92400e;font:500 12.5px/1.5 var(--font-m);margin-bottom:12px}
.hr-stale-banner i{font-size:18px;color:#d97706;margin-top:1px;flex-shrink:0}
.hr-stale-banner b{color:#92400e;display:block;font-size:13px}
.hr-stale-row td{background:rgba(217,119,6,.05)!important}
.hr-stale-row td:first-child{box-shadow:inset 3px 0 0 #d97706}
.hr-stale-pill{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;background:rgba(217,119,6,.15);color:#92400e;font:600 10px/1 var(--font-b);text-transform:uppercase;letter-spacing:.4px;margin-left:6px;cursor:help}

/* UX-4: Confirm-modal warning callout */
.hr-confirm-warning{padding:11px 13px;border-radius:8px;background:rgba(220,38,38,.06);border-left:4px solid #dc2626;color:#7f1d1d;font:500 12.5px/1.6 var(--font-m);margin-bottom:6px}
.hr-confirm-warning.amber{background:rgba(217,119,6,.06);border-left-color:#d97706;color:#7c2d12}
.hr-confirm-warning b{color:inherit;display:block;margin-bottom:4px;font-size:13px;font-family:var(--font-b)}
.hr-confirm-warning ul{margin:6px 0 0;padding-left:18px}
.hr-confirm-warning li{margin:2px 0}
.hr-confirm-warning li.muted{color:var(--t2)}

/* UX-5: warnings panel for generate response */
.hr-warnings-panel{margin:14px 0;padding:12px 14px;border-radius:8px;background:rgba(217,119,6,.05);border:1px solid rgba(217,119,6,.2)}
.hr-warnings-panel h5{margin:0 0 8px;font:700 12px/1.2 var(--font-b);color:#92400e;display:flex;align-items:center;gap:6px}
.hr-warnings-panel ul{margin:0;padding-left:20px;font:400 12px/1.6 var(--font-m);color:var(--t2)}
.hr-warnings-panel li{margin:2px 0}
.hr-warnings-panel li b{color:#92400e}

/* Toast */
#hrToastContainer{position:fixed;top:20px;right:20px;z-index:99999;display:flex;flex-direction:column;gap:8px}
.hr-toast{padding:12px 18px;border-radius:8px;font-size:13px;font-weight:600;font-family:var(--font-b);color:#fff;animation:hrSlideIn .3s cubic-bezier(.4,0,.2,1) backwards;min-width:240px;box-shadow:0 8px 24px rgba(0,0,0,.2)}
.hr-toast.success{background:#059669}
.hr-toast.error{background:#dc2626}
.hr-toast.info{background:#2563eb}

/* Animations */
@keyframes hrFadeIn{from{opacity:0}to{opacity:1}}
@keyframes hrSlideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
/* Modal card: NO opacity here. The overlay fades; animating opacity on both
   multiplies the two curves and reads as a second flash. */
@keyframes hrModalIn{from{transform:translateY(12px) scale(.985)}to{transform:none}}
@media(prefers-reduced-motion:reduce){
  .hr-modal-overlay.open,.hr-modal-overlay.open .hr-modal{animation:none}
}
@keyframes hrSlideIn{from{opacity:0;transform:translateX(60px)}to{opacity:1;transform:translateX(0)}}

/* Responsive */
@media(max-width:768px){
  .hr-tabs{gap:2px}
  .hr-tab{padding:8px 10px;font-size:12px}
  .hr-tab i{display:none}
  .hr-dash-grid{grid-template-columns:1fr}
  .hr-modal-grid{grid-template-columns:1fr}
  .hr-scores-grid{grid-template-columns:1fr 1fr}
  .hr-stats{grid-template-columns:repeat(2,1fr)}
  .hr-salary-summary{flex-direction:column;gap:6px}
  .hr-toolbar{flex-direction:column}
}
@media(max-width:480px){
  .hr-stats{grid-template-columns:1fr}
  .hr-scores-grid{grid-template-columns:1fr}
  .hr-wrap{padding:12px 8px 36px}
}
</style>


<!-- ================================================================
     JAVASCRIPT
================================================================= -->
<script src="<?= base_url('assets/js/zx_dialog.js') ?>?v=1"></script>
<script>
/* Save PHP config before jQuery loads */
var _HR_CFG = {
  BASE:      '<?= base_url() ?>',
  CSRF_NAME: '<?= $this->security->get_csrf_token_name() ?>',
  CSRF_HASH: '<?= $this->security->get_csrf_hash() ?>',
  activeTab: '<?= $at ?>'
};
/* W3.2.2 — whether to OFFER the adjustment form. Convenience only: every
   payroll_overrides/* endpoint enforces HR:manage itself, so a user who forces
   this to true still cannot create an adjustment. */
var HR_CAN_MANAGE = <?= (function_exists('has_permission') && has_permission('HR', 'manage')) ? 'true' : 'false' ?>;
/* Adjustment types, fetched once per page from the server so the option list
   can never drift from what Payroll_override_service actually accepts. */
var HR_ADJ_TYPES = [];
/* Defer until jQuery is available (loaded in footer) */
document.addEventListener('DOMContentLoaded', function(){
(function(){
  'use strict';

  // UX-P2-1: on the narrow-width horizontal-scroll tab strip, the active
  // module tab can load scrolled out of view with nothing on screen to say
  // which module is open. inline:'end' brings it fully into view rather
  // than stopping the instant any sliver is visible; block:'nearest' never
  // scrolls the page itself.
  var activeHrTab = document.querySelector('.hr-tab.active');
  if (activeHrTab && activeHrTab.scrollIntoView) {
    activeHrTab.scrollIntoView({ block: 'nearest', inline: 'end' });
  }

  /* ── Config ─────────────────────────────────────────────── */
  var BASE       = _HR_CFG.BASE;
  var CSRF_NAME  = _HR_CFG.CSRF_NAME;
  var CSRF_HASH  = _HR_CFG.CSRF_HASH;
  var activeTab  = _HR_CFG.activeTab;

  /* ── Caches ─────────────────────────────────────────────── */
  var deptCache      = {};
  var staffCache     = {};
  var leaveTypeCache = {};
  var _jobsCache     = {};
  var _appraisalCache= {};
  var _trainingCache = {};
  var _salaryCache   = {};
  var currentJobId   = null;
  var currentRunId   = null;

  /* ── Helpers ────────────────────────────────────────────── */
  function csrfData(){ var o={}; o[CSRF_NAME]=CSRF_HASH; return o; }
  function refreshCsrf(h){ if(h) CSRF_HASH=h; }

  function post(url, data){
    data = $.extend({}, csrfData(), data||{});
    return $.ajax({url:BASE+url, type:'POST', data:data, dataType:'json'}).then(function(r){
      if(r && r.csrf_hash) refreshCsrf(r.csrf_hash);
      return r;
    }).catch(function(xhr){
      var msg='Request failed';
      try{ var j=JSON.parse(xhr.responseText); if(j.csrf_hash) refreshCsrf(j.csrf_hash); msg=j.message||msg; }catch(e){}
      toast(msg,'error');
      return $.Deferred().reject(msg);
    });
  }
  function getJSON(url){
    return $.ajax({url:BASE+url, type:'GET', dataType:'json'}).catch(function(xhr){
      var msg='Failed to load data';
      try{ var j=JSON.parse(xhr.responseText); msg=j.message||msg; }catch(e){}
      toast(msg,'error');
      return $.Deferred().reject(msg);
    });
  }

  function toast(msg, type, duration){
    type = type||'info';
    var $t = $('<div class="hr-toast '+type+'">'+esc(msg)+'</div>');
    $('#hrToastContainer').append($t);
    setTimeout(function(){ $t.fadeOut(300,function(){ $t.remove(); }); }, duration||3500);
  }

  function fmt(n){
    n = parseFloat(n)||0;
    return n.toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
  }

  // Convert array [{id:'X',...},...] to object {X:{...},...}
  function toMap(arr){
    if(!Array.isArray(arr)) return arr||{};
    var m={};
    $.each(arr,function(i,o){ if(o&&o.id) m[o.id]=o; else m[i]=o; });
    return m;
  }

  function esc(s){
    if(!s) return '';
    var d=document.createElement('div'); d.textContent=s; return d.innerHTML;
  }

  function closeModal(id){
    $('#'+id).removeClass('open');
  }
  function openModal(id){
    var $m = $('#'+id);
    if($m.hasClass('open')) return;
    // Close any other open modals first
    $('.hr-modal-overlay.open').removeClass('open');
    $m.addClass('open');
  }

  function starsHtml(rating, max){
    max = max||5;
    var h='';
    for(var i=1;i<=max;i++){
      h += '<i class="fa '+(i<=rating?'fa-star':'fa-star-o')+' '+(i>rating?'empty':'')+'"></i>';
    }
    return '<span class="hr-stars-display">'+h+'</span>';
  }

  function badgeHtml(status){
    var cls = 'hr-badge hr-badge-'+(status||'').toLowerCase().replace(/\s+/g,'-');
    return '<span class="'+cls+'">'+esc(status)+'</span>';
  }

  function staffName(id){
    if(staffCache[id]) return staffCache[id].Name || staffCache[id].name || id;
    return id;
  }
  function deptName(id){
    if(deptCache[id]) return deptCache[id].name || id;
    return id;
  }
  function _fmtAuditDate(ts){
    if(!ts) return '';
    try { var d=new Date(ts); return d.toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'})+' '+d.toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit'}); }
    catch(e){ return ts; }
  }

  function fillStaffSelect(selId, selectedVal){
    var $s = $(selId);
    var first = $s.find('option:first').prop('outerHTML');
    $s.html(first);
    $.each(staffCache, function(k,v){
      var n = v.Name||v.name||k;
      var dept = v.department||v.Department||'';
      var label = k + ' - ' + n + (dept ? ' (' + dept + ')' : '');
      $s.append('<option value="'+esc(k)+'" data-dept="'+esc(dept)+'"'+(k===selectedVal?' selected':'')+'>'+esc(label)+'</option>');
    });
  }
  /* Show who already works in the selected department. get_departments()
     already returns staff[] and staff_count per department (joined on the
     normalised dept_key, so case/spacing variants sum correctly) — the job
     modal simply never displayed it, leaving a recruiter to guess headcount. */
  function renderJobDeptSnapshot(){
    var name = $('#jobDept').val() || '';
    var $box = $('#jobDeptSnapshot');
    if (!name) { $box.hide().empty(); return; }

    var dept = null;
    $.each(deptCache, function(k, v){
      if (v && v.name === name) { dept = v; return false; }
    });

    var staff = (dept && $.isArray(dept.staff)) ? dept.staff : [];
    var count = (dept && dept.staff_count != null) ? dept.staff_count : staff.length;
    var open  = (dept && $.isArray(dept.openings)) ? dept.openings.length : 0;

    var h = '<div class="hr-dept-snap-head"><i class="fa fa-users"></i> '
          + esc(name) + ' &middot; ' + count + (count === 1 ? ' member' : ' members')
          + (open ? ' &middot; ' + open + (open === 1 ? ' open posting' : ' open postings') : '')
          + '</div>';

    if (!staff.length) {
      h += '<div class="hr-dept-snap-empty">No staff are assigned to this department yet.</div>';
    } else {
      h += '<div class="hr-dept-snap-list">';
      $.each(staff, function(i, m){
        h += '<span class="hr-dept-chip">' + esc(m.name || m.id)
           + (m.position ? ' <small>' + esc(m.position) + '</small>' : '')
           + '</span>';
      });
      h += '</div>';
    }
    $box.html(h).show();
  }

  function fillDeptSelect(selId, selectedVal){
    var $s = $(selId);
    var first = $s.find('option:first').prop('outerHTML');
    $s.html(first);
    $.each(deptCache, function(k,v){
      // Option value is the department NAME (save_job + filters key by name, not id).
      // deptCache may be array-indexed or doc-id keyed depending on backend; either way
      // v.name is canonical.
      var name = (v && v.name) ? v.name : '';
      if (!name) return;
      var sel = (name === selectedVal) ? ' selected' : '';
      $s.append('<option value="'+esc(name)+'"'+sel+' data-id="'+esc(v.id || k)+'">'+esc(name)+'</option>');
    });
  }
  function fillLeaveTypeSelect(selId, selectedVal){
    var $s = $(selId);
    var first = $s.find('option:first').prop('outerHTML');
    $s.html(first);
    $.each(leaveTypeCache, function(k,v){
      $s.append('<option value="'+esc(k)+'"'+(k===selectedVal?' selected':'')+'>'+esc(v.name)+' ('+esc(v.code)+')</option>');
    });
  }

  /* ── Init ───────────────────────────────────────────────── */
  function init(){
    // Load staff + departments first, then tab-specific data
    $.when(loadStaffCache(), loadDeptCache()).then(function(){
      loadLeaveTypesCacheQuiet();
      populateFilterDropdowns();
      loadTabData();
    });

    // Sub-tab switching
    $(document).on('change', '#jobDept', renderJobDeptSnapshot);
$(document).on('click', '.hr-sub-tab', function(){
      var sub = $(this).data('sub');
      var $parent = $(this).closest('.hr-panel');
      $parent.find('.hr-sub-tab').removeClass('active');
      $(this).addClass('active');
      $parent.find('.hr-sub-panel').removeClass('active');
      $parent.find('#sub' + sub.charAt(0).toUpperCase() + sub.slice(1)).addClass('active');
      // PAY-1 — payroll period locks live on the Payroll Runs tab. Loaded on
      // each visit rather than cached: a period closed in another session must
      // not keep showing as open here.
      if(sub==='payrollRuns' && typeof loadPayrollLocks === 'function') loadPayrollLocks();
      // Lazy-load audit log on first tab visit
      if(sub==='leaveAudit' && !_auditCache.length) loadLeaveAudit();
      // Comp-Off needs an employee before it can show anything, so the tab
      // opens on its prompt rather than firing a query with no staff id.
      if(sub==='compOff' && ($('#coStaffId').val() || '').trim()) loadCompOffLedger();
      // Default the closure year field to the current year on first visit
      if(sub==='leaveCarryForward' && !$('#cfYear').val()) $('#cfYear').val(new Date().getFullYear());
    });

    // Star rating click
    $(document).on('click', '.hr-stars i', function(){
      var val = $(this).data('v');
      var $parent = $(this).closest('.hr-stars');
      $parent.find('i').each(function(){
        var v = $(this).data('v');
        $(this).toggleClass('fa-star', v<=val).toggleClass('fa-star-o', v>val);
      });
      $parent.next('input[type=hidden]').val(val);
    });

    // Click outside modal to close
    $(document).on('click', '.hr-modal-overlay', function(e){
      if(e.target === this) $(this).removeClass('open');
    });

    // Set current month in payroll generator
    $('#genMonth').val(['January','February','March','April','May','June','July','August','September','October','November','December'][new Date().getMonth()]);
  }

  function loadStaffCache(){
    return getJSON('hr/get_staff_list').then(function(r){
      staffCache = toMap((r && r.staff) ? r.staff : (r && r.data) ? r.data : {});
    });
  }
  function loadDeptCache(){
    return getJSON('hr/get_departments').then(function(r){
      deptCache = toMap((r && r.departments) ? r.departments : (r && r.data) ? r.data : {});
    });
  }
  function loadLeaveTypesCacheQuiet(){
    return getJSON('hr/get_leave_types').then(function(r){
      leaveTypeCache = toMap((r && r.leave_types) ? r.leave_types : (r && r.data) ? r.data : {});
    });
  }

  function populateFilterDropdowns(){
    fillStaffSelect('#filterLeaveStaff');
    fillStaffSelect('#filterAppraisalStaff');
    fillStaffSelect('#filterTrainingStaff');
    fillDeptSelect('#filterJobDept');
  }

  function loadTabData(){
    switch(activeTab){
      case 'dashboard':    loadDashboard(); break;
      case 'recruitment':  loadJobs(); break;
      case 'leaves':       loadLeaveTypes(); loadLeaveRequests(); loadLeaveBalances(); break;
      case 'payroll':      loadMyPayslips(); loadSalaryStructures(); loadPayrollRuns(); break;
      case 'appraisals':   loadAppraisals(); break;
      case 'training':     loadTrainingRecords(); break;
      case 'orgchart':     loadOrgChart(); break;
    }
  }

  /* ================================================================
     DASHBOARD
  ================================================================ */
  function loadDashboard(){
    getJSON('hr/get_dashboard').then(function(r){
      if(!r) return;
      // total_staff = all staff regardless of status; active_staff =
      // status==Active. staff_count alias falls back to active for any
      // older payload that didn't include the new fields.
      $('#statTotalStaff').text(r.total_staff != null ? r.total_staff : (r.staff_count||0));
      $('#statActiveStaff').text(r.active_staff != null ? r.active_staff : (r.staff_count||0));
      $('#statDepts').text(r.dept_count||0);
      $('#statOpenJobs').text(r.open_jobs||0);
      $('#statPendingLeaves').text(r.pending_leaves||0);
      var lp = r.last_payroll;
      $('#statPayrollStatus').text(lp ? (lp.month+' '+lp.year+' - '+lp.status) : '--');

      // Recent leaves
      var $lb = $('#tblDashLeaves tbody');
      if(r.recent_leaves && r.recent_leaves.length){
        var h='';
        $.each(r.recent_leaves.slice(0,5), function(i,l){
          h+='<tr><td>'+esc(l.staff_name||staffName(l.staff_id))+'</td><td>'+esc(l.type_name||l.type||'')+'</td><td>'+esc(l.from_date||l.start_date||'')+'</td><td>'+esc(l.to_date||l.end_date||'')+'</td><td>'+badgeHtml(l.status)+'</td></tr>';
        });
        $lb.html(h);
      } else {
        $lb.html('<tr><td colspan="5" class="hr-empty"><i class="fa fa-inbox"></i> No recent leave requests</td></tr>');
      }

      // ATS Pipeline
      var pc = r.pipeline_counts || {};
      $('#pipeApplied').text(pc.Applied||0);
      $('#pipeShortlisted').text(pc.Shortlisted||0);
      $('#pipeInterviewed').text(pc.Interviewed||0);
      $('#pipeSelected').text(pc.Selected||0);
      $('#pipeHired').text(pc.Hired||0);
      $('#pipeRejected').text(r.rejected_count||0);

      // Pipeline stat card — total active in pipeline (excluding hired & rejected)
      var pipeTotal = (pc.Applied||0) + (pc.Shortlisted||0) + (pc.Interviewed||0) + (pc.Selected||0);
      $('#statPipeline').text(pipeTotal);

      // Click on pipeline stage to go to ATS
      $('.hr-pipe-stage').off('click').on('click', function(){
        window.location.href = BASE + 'ats';
      });

      // Recent applicants table
      var $hb = $('#tblDashHires tbody');
      var ra = r.recent_applicants || [];
      if(ra.length){
        var h = '';
        var stageMap = {Applied:'Applied',Shortlisted:'Shortlisted',Interview:'Interviewed',Interviewed:'Interviewed',Selected:'Selected',Joined:'Hired',Hired:'Hired'};
        $.each(ra.slice(0,6), function(i, a){
          var stage = stageMap[a._stage || a.stage || a.status] || a.stage || 'Applied';
          var atsStatus = a.ats_status || 'active';
          var displayStage = atsStatus === 'rejected' ? 'Rejected' : stage;
          var stageClass = atsStatus === 'rejected' ? 'Rejected' : stage;
          // Star rating
          var stars = '';
          var rating = a.rating || 0;
          for(var s=1; s<=5; s++) stars += '<i class="fa fa-star" style="font-size:10px;color:'+(s<=rating?'#d97706':'var(--t4,#ccc)')+'"></i>';

          h += '<tr>'
            + '<td><strong>'+esc(a.name)+'</strong></td>'
            + '<td>'+esc(a.job_title||'')+'</td>'
            + '<td>'+badgeHtml(displayStage)+'</td>'
            + '<td>'+stars+'</td>'
            + '<td>'+esc(a.applied_date||'')+'</td>'
            + '</tr>';
        });
        $hb.html(h);
      } else {
        $hb.html('<tr><td colspan="5" class="hr-empty"><i class="fa fa-inbox"></i> No applicants yet. <a href="'+BASE+'ats" style="color:var(--gold)">Open ATS</a> to add candidates.</td></tr>');
      }
    });
  }

  /* ================================================================
     RECRUITMENT
  ================================================================ */
  function loadJobs(){
    var params = '?status='+($('#filterJobStatus').val()||'')+'&department='+($('#filterJobDept').val()||'');
    // REC-D2: repaint the spinner the initial markup shows. Without this the
    // table kept displaying the previous rows for the whole round-trip, so a
    // posting that had just been saved looked like it had not been created.
    $('#tblJobs tbody').html('<tr><td colspan="9" class="hr-empty">'
      + '<i class="fa fa-spinner fa-spin"></i> Loading job postings\u2026</td></tr>');
    getJSON('hr/get_jobs'+params).then(function(r){
      var jobsRaw = (r&&r.jobs)?r.jobs:(r&&r.data)?r.data:{};
      // Re-key cache by canonical j.id so editJob/deleteJob lookups work
      var jobs = {};
      $.each(jobsRaw, function(k, j){
        var rid = (j && j.id) ? j.id : k;
        jobs[rid] = j;
      });
      _jobsCache = jobs;
      var $tb=$('#tblJobs tbody');
      var keys=Object.keys(jobs);
      if(!keys.length){ $tb.html('<tr><td colspan="9" class="hr-empty"><i class="fa fa-inbox"></i> No job postings found.</td></tr>'); return; }
      var h='', i=0;
      $.each(jobs, function(k,j){
        i++;
        var rid = j.id || k;
        var circHtml = '<span style="color:var(--t3);font-size:11px;">—</span>';
        if(j.circular_id){
          circHtml = '<button class="hr-circular-badge" onclick="event.stopPropagation();HR.viewCircular(\''+esc(rid)+'\')" title="View circular poster">'
            + '<i class="fa fa-eye"></i> '+esc(j.circular_id)+'</button>';
        } else if(j.status==='Open'){
          circHtml = '<button class="hr-act-btn" onclick="event.stopPropagation();HR.generateCircular(\''+esc(rid)+'\')" title="Generate circular" style="font-size:10px;padding:3px 8px;"><i class="fa fa-plus"></i> Generate</button>';
        }
        h+='<tr class="hr-job-row" data-id="'+esc(rid)+'" style="cursor:pointer">';
        h+='<td class="hr-num">'+i+'</td><td><strong>'+esc(j.title)+'</strong></td><td>'+esc(deptName(j.department))+'</td>';
        h+='<td class="hr-num">'+(j.positions||0)+'</td><td class="hr-num">'+(j.applicant_count||0)+'</td>';
        h+='<td>'+badgeHtml(j.status)+'</td><td>'+esc(j.deadline||'-')+'</td>';
        h+='<td>'+circHtml+'</td>';
        h+='<td><button class="hr-act-btn" onclick="event.stopPropagation();HR.editJob(\''+esc(rid)+'\')"><i class="fa fa-pencil"></i></button> ';
        h+='<button class="hr-act-btn danger" onclick="event.stopPropagation();HR.deleteJob(\''+esc(rid)+'\')"><i class="fa fa-trash"></i></button></td></tr>';
      });
      $tb.html(h);

      // Row click => show applicants
      $tb.find('.hr-job-row').on('click', function(){
        var jid=$(this).data('id');
        loadApplicants(jid, jobs[jid]?jobs[jid].title:'');
      });
    });
  }

  function loadApplicants(jobId, jobTitle){
    currentJobId = jobId;
    $('#applicantsJobTitle').text(jobTitle||jobId);
    $('#applicantsContainer').show();
    getJSON('hr/get_applicants?job_id='+encodeURIComponent(jobId)).then(function(r){
      var apps = toMap((r&&r.applicants)?r.applicants:(r&&r.data)?r.data:{});
      var $tb=$('#tblApplicants tbody');
      var keys=Object.keys(apps);
      if(!keys.length){ $tb.html('<tr><td colspan="8" class="hr-empty"><i class="fa fa-inbox"></i> No applicants yet.</td></tr>'); return; }
      var h='', i=0;
      $.each(apps, function(k,a){
        i++;
        h+='<tr><td class="hr-num">'+i+'</td><td>'+esc(a.name)+'</td><td>'+esc(a.email||'-')+'</td><td>'+esc(a.phone||'-')+'</td>';
        h+='<td>'+badgeHtml(a.status)+'</td><td>'+esc(a.interview_date||'-')+'</td>';
        h+='<td>'+starsHtml(a.rating||0)+'</td>';
        var safeJson = JSON.stringify(a).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
        h+='<td>';
        // Quick status actions
        if(a.status!=='Selected' && a.status!=='Joined' && a.status!=='Rejected'){
          h+='<button class="hr-act-btn" onclick="HR.quickApplicantStatus(\''+esc(k)+'\',\'Selected\')" title="Accept / Select"><i class="fa fa-check"></i></button> ';
          h+='<button class="hr-act-btn danger" onclick="HR.quickApplicantStatus(\''+esc(k)+'\',\'Rejected\')" title="Reject"><i class="fa fa-times"></i></button> ';
        }
        if(a.status==='Selected'){
          h+='<button class="hr-act-btn" onclick="HR.quickApplicantStatus(\''+esc(k)+'\',\'Joined\')" title="Mark as Joined" style="background:#d1fae5;color:#065f46"><i class="fa fa-user-plus"></i></button> ';
        }
        h+='<a class="hr-act-btn" href="<?= base_url("ats") ?>?applicant='+encodeURIComponent(k)+'" title="View in ATS Pipeline"><i class="fa fa-th-list"></i></a> ';
        h+='<button class="hr-act-btn" onclick="HR.editApplicant(\''+esc(k)+'\',this)" data-applicant="'+safeJson+'"><i class="fa fa-pencil"></i></button> ';
        h+='<button class="hr-act-btn danger" onclick="HR.deleteApplicant(\''+esc(k)+'\')"><i class="fa fa-trash"></i></button></td></tr>';
      });
      $tb.html(h);
    });
  }

  function openJobModal(id){
    $('#jobId').val('');
    $('#jobTitle,#jobDescription,#jobQualifications,#jobExperience').val('');
    $('#jobPositions').val(1);
    $('#jobSalaryMin,#jobSalaryMax').val('');
    $('#jobDeadline').val('');
    $('#jobStatus').val('Open');
    fillDeptSelect('#jobDept');
    renderJobDeptSnapshot();
    $('#modalJobTitle').text('New Job Posting');
    openModal('modalJob');
  }

  function editJob(id){
    // Always re-fetch latest jobs before opening edit modal so we never
    // populate from a stale cache (e.g. right after a save .always() reload).
    var openWith = function(j){
      $('#jobId').val(id);
      $('#jobTitle').val(j.title||'');
      fillDeptSelect('#jobDept', j.department);
      renderJobDeptSnapshot();
      $('#jobDescription').val(j.description||'');
      $('#jobQualifications').val(j.qualifications||'');
      $('#jobExperience').val(j.experience_required||'');
      $('#jobPositions').val(j.positions||1);
      $('#jobSalaryMin').val(j.salary_range_min||'');
      $('#jobSalaryMax').val(j.salary_range_max||'');
      $('#jobDeadline').val(j.deadline||'');
      $('#jobStatus').val(j.status||'Open');
      $('#modalJobTitle').text('Edit Job Posting');
      openModal('modalJob');
    };
    var params = '?status='+($('#filterJobStatus').val()||'')+'&department='+($('#filterJobDept').val()||'');
    getJSON('hr/get_jobs'+params).then(function(r){
      var raw = (r&&r.jobs)?r.jobs:(r&&r.data)?r.data:{};
      var fresh = null;
      $.each(raw, function(k, j){ if((j&&j.id)===id || k===id){ fresh = j; return false; } });
      if(!fresh){ toast('Job not found — was it deleted?','error'); loadJobs(); return; }
      _jobsCache[id] = fresh;
      openWith(fresh);
    }).fail(function(){
      // Fallback: use cache if network fails
      if(_jobsCache[id]){ openWith(_jobsCache[id]); }
      else { toast('Could not load job','error'); }
    });
  }

  function saveJob(){
    var title=$('#jobTitle').val().trim();
    var $deptOpt=$('#jobDept option:selected');
    var dept=$deptOpt.val();
    var deptId=$deptOpt.attr('data-id') || '';
    if(!title||!dept){ toast('Title and department are required','error'); return; }
    if(window._saveJobInFlight) return;
    window._saveJobInFlight = true;
    var $btn=$('button.hr-btn-primary[onclick*="saveJob"]');
    var orig=$btn.html();
    $btn.prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Saving…');
    post('hr/save_job', {
      id:$('#jobId').val(), title:title, department:dept, department_id:deptId,
      description:$('#jobDescription').val().trim(), qualifications:$('#jobQualifications').val().trim(),
      experience_required:$('#jobExperience').val().trim(),
      positions:$('#jobPositions').val(), salary_range_min:$('#jobSalaryMin').val(),
      salary_range_max:$('#jobSalaryMax').val(), deadline:$('#jobDeadline').val(), status:$('#jobStatus').val()
    }).then(function(r){
      if(r&&r.status==='success'){
        closeModal('modalJob');
        loadJobs();
        // Show enhanced notification with circular info
        if(r.circular_id){
          toast('Job posting created! Circular ' + r.circular_id + ' generated and published to Communication \u2192 Circulars.', 'success', 6000);
        } else {
          toast(r.message||'Saved','success');
        }
      }
      else toast((r&&r.message)||'Failed to save job','error');
    }).fail(function(){
      toast('Server error — refreshing list','error'); loadJobs();
    }).always(function(){
      window._saveJobInFlight=false;
      $btn.prop('disabled',false).html(orig);
    });
  }

  async function deleteJob(id){
    if(!await zxConfirm({ title: 'Delete this job posting and all its applicants?' })) return;
    post('hr/delete_job', {id:id}).then(function(r){
      if(r&&r.status==='success'){ toast('Deleted','success'); $('#applicantsContainer').hide(); }
      else toast((r&&r.message)||'Failed to delete','error');
    }).fail(function(){ toast('Server error — refreshing list','error'); }).always(function(){ loadJobs(); });
  }

  function openApplicantModal(){
    if(!currentJobId){ toast('Select a job first','error'); return; }
    $('#applicantId').val('');
    $('#applicantJobId').val(currentJobId);
    $('#applicantName,#applicantEmail,#applicantPhone,#applicantNotes').val('');
    // REC-D5: the options are title-case ("Applied"). Passing 'applied' matched
    // no option, so jQuery set selectedIndex=-1: the control rendered BLANK and
    // .val() returned null, posting an empty status.
    $('#applicantStatus').val('Applied');
    $('#applicantInterview').val('');
    $('#applicantRating').val(0);
    $('#applicantRatingStars i').removeClass('fa-star').addClass('fa-star-o');
    $('#modalApplicantTitle').text('Add Applicant');
    openModal('modalApplicant');
  }

  function editApplicant(id, btnEl){
    // Read data from data-applicant attribute on the button element
    var data;
    if(typeof btnEl === 'object' && btnEl.getAttribute){
      try { data = JSON.parse(btnEl.getAttribute('data-applicant')); } catch(e){ data={}; }
    } else {
      data = btnEl || {};
    }
    $('#applicantId').val(id);
    $('#applicantJobId').val(currentJobId);
    $('#applicantName').val(data.name||'');
    $('#applicantEmail').val(data.email||'');
    $('#applicantPhone').val(data.phone||'');
    $('#applicantStatus').val(data.status||'Applied');
    $('#applicantInterview').val(data.interview_date||'');
    $('#applicantNotes').val(data.interview_notes||'');
    var rating=parseInt(data.rating)||0;
    $('#applicantRating').val(rating);
    $('#applicantRatingStars i').each(function(){ var v=$(this).data('v'); $(this).toggleClass('fa-star',v<=rating).toggleClass('fa-star-o',v>rating); });
    $('#modalApplicantTitle').text('Edit Applicant');
    openModal('modalApplicant');
  }

  async function quickApplicantStatus(id, newStatus){
    var label = newStatus==='Selected'?'Accept':newStatus==='Rejected'?'Reject':'Mark as Joined';
    if(!await zxConfirm({ title: 'this applicant?' })) return;
    post('hr/update_applicant_status', {id:id, job_id:currentJobId, status:newStatus}).then(function(r){
      if (r && r.status && r.status !== 'error'){ toast(newStatus+' successfully','success'); loadApplicants(currentJobId,$('#applicantsJobTitle').text()); loadJobs(); }
      else toast(r&&r.message||'Failed','error');
    });
  }

  function saveApplicant(){
    var name=$('#applicantName').val().trim();
    if(!name){ toast('Name is required','error'); return; }
    // REC-D6: without this guard a second click POSTs again with a blank id
    // and creates a DUPLICATE applicant.
    if(window._saveApplicantInFlight) return;
    window._saveApplicantInFlight = true;
    var $btn=$('#btnSaveApplicant');
    var orig=$btn.html();
    $btn.prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Saving\u2026');
    post('hr/save_applicant', {
      id:$('#applicantId').val(), job_id:$('#applicantJobId').val(),
      name:name, email:$('#applicantEmail').val().trim(), phone:$('#applicantPhone').val().trim(),
      status:$('#applicantStatus').val(), interview_date:$('#applicantInterview').val(),
      interview_notes:$('#applicantNotes').val().trim(), rating:$('#applicantRating').val()
    }).then(function(r){
      if (r && r.status && r.status !== 'error'){ toast(r.message||'Saved','success'); closeModal('modalApplicant'); loadApplicants(currentJobId,$('#applicantsJobTitle').text()); loadJobs(); }
      else toast(r.message||'Failed','error');
    }).fail(function(){
      // REC-D6: a dropped or 500 response used to surface nothing at all.
      toast('Server error \u2014 the applicant was not saved','error');
    }).always(function(){
      window._saveApplicantInFlight = false;
      $btn.prop('disabled',false).html(orig);
    });
  }

  async function deleteApplicant(id){
    if(!await zxConfirm({ title: 'Delete this applicant?' })) return;
    post('hr/delete_applicant', {id:id, job_id:currentJobId}).then(function(r){
      if (r && r.status && r.status !== 'error'){ toast('Deleted','success'); loadApplicants(currentJobId,$('#applicantsJobTitle').text()); }
      else toast(r.message||'Failed','error');
    });
  }

  /* ================================================================
     LEAVE MANAGEMENT
  ================================================================ */
  var SEED_CODES = ['CL','SL','EL','MATERNITY','PATERNITY','LWP','COMP_OFF','ACADEMIC','DUTY'];

  /* Workforce Policy Programme — compact Phase 3/5/6 summary for the Leave
     Types table. Shows nothing extra for a type left at every default
     (monthly=off, waiting=0, no ceiling) so the table stays uncluttered for
     schools not using these capabilities yet. */
  function leaveTypePolicyBadges(t){
    var out = [];
    if(t.accrual_mode==='monthly') out.push('<span class="hr-badge hr-badge-info" title="Accrues 1/12th monthly instead of a lump sum">Monthly Accrual</span>');
    if(t.waiting_period_days && Number(t.waiting_period_days) > 0) out.push('<span class="hr-badge" style="background:#f3f4f6;color:#374151" title="Days after joining before this type may be applied">Wait '+esc(t.waiting_period_days)+'d</span>');
    if(t.max_balance!==null && t.max_balance!==undefined && t.max_balance!=='') out.push('<span class="hr-badge" style="background:#f3f4f6;color:#374151" title="Maximum balance an employee may hold at once">Cap '+esc(t.max_balance)+'</span>');
    return out.length ? out.join(' ') : '<span style="color:var(--t3)">—</span>';
  }

  function loadLeaveTypes(){
    getJSON('hr/get_leave_types').then(function(r){
      leaveTypeCache = toMap((r&&r.leave_types)?r.leave_types:(r&&r.data)?r.data:{});
      var _acc=document.getElementById('chkAccrualMode'); if(_acc){ _acc.checked = !!(r && r.accrualEnabled===true); }
      var _lp = (r && r.leavePolicy) ? r.leavePolicy : {};
      var _lpMap = {lpEffectiveDating:'effective_dating', lpProrata:'prorata', lpWaitingPeriod:'waiting_period', lpMaxBalance:'max_balance', lpYearClosure:'year_closure'};
      Object.keys(_lpMap).forEach(function(id){ var el=document.getElementById(id); if(el){ el.checked = _lp[_lpMap[id]]===true; } });
      var $tb=$('#tblLeaveTypes tbody');
      var keys=Object.keys(leaveTypeCache);
      if(!keys.length){ $tb.html('<tr><td colspan="7" class="hr-empty"><i class="fa fa-inbox"></i> No leave types defined.</td></tr>'); }
      else {
        var h='', i=0;
        $.each(leaveTypeCache, function(k,t){
          i++;
          h+='<tr><td class="hr-num">'+i+'</td><td><strong>'+esc(t.name)+'</strong></td><td class="hr-num">'+esc(t.code)+'</td>';
          h+='<td class="hr-num">'+(t.days_per_year||0)+'</td><td>'+(t.carry_forward===true||t.carry_forward==='yes'||t.carry_forward==='true'?'<i class="fa fa-check" style="color:var(--gold)"></i>':'<i class="fa fa-times" style="color:var(--t3)"></i>')+'</td>';
          h+='<td class="hr-num">'+(t.max_carry||0)+'</td>';
          h+='<td>'+leaveTypePolicyBadges(t)+'</td>';
          h+='<td><button class="hr-act-btn" onclick="HR.editLeaveType(\''+esc(k)+'\')"><i class="fa fa-pencil"></i></button> ';
          h+='<button class="hr-act-btn danger" onclick="HR.deleteLeaveType(\''+esc(k)+'\')"><i class="fa fa-trash"></i></button></td></tr>';
        });
        $tb.html(h);
      }
      refreshSeedPills();
    });
  }

  function refreshSeedPills(){
    // Build set of existing codes (uppercase)
    var existing = {};
    $.each(leaveTypeCache, function(k,t){ if(t.code) existing[t.code.toUpperCase()] = true; });
    var allSeeded = true;
    $('#leaveTypePills .hr-seed-pill').each(function(){
      var code = $(this).data('code').toUpperCase();
      if(existing[code]){
        $(this).addClass('added').off('click');
      } else {
        $(this).removeClass('added');
        allSeeded = false;
      }
    });
    // Hide card if all defaults already exist
    if(allSeeded) $('#leaveTypeSeedCard').addClass('hr-all-seeded');
    else $('#leaveTypeSeedCard').removeClass('hr-all-seeded');
  }

  function seedLeaveTypes(){
    var btn = document.getElementById('seedLeaveBtn');
    if(btn){ btn.disabled=true; btn.innerHTML='<i class="fa fa-spinner fa-spin"></i> Seeding...'; }
    post('hr/seed_leave_types', {}).then(function(r){
      if(btn){ btn.disabled=false; btn.innerHTML='<i class="fa fa-magic"></i> Add All Missing'; }
      if(r && r.status==='success'){
        toast(r.message||'Done','success');
        loadLeaveTypes();
      } else {
        toast(r&&r.message||'Failed','error');
      }
    });
  }

  function seedOnePill(el){
    var $el = $(el);
    if($el.hasClass('added')) return;
    var code = ($el.data('code')||'').toUpperCase();
    // Map code to default values for quick-add via existing save endpoint
    var map = {
      CL:       {name:'Casual Leave',     days:12, paid:'true', carry:'no',  maxCarry:0},
      SL:       {name:'Sick Leave',        days:12, paid:'true', carry:'no',  maxCarry:0},
      EL:       {name:'Earned Leave',      days:15, paid:'true', carry:'yes', maxCarry:30},
      MATERNITY:{name:'Maternity Leave',   days:180,paid:'true', carry:'no',  maxCarry:0},
      PATERNITY:{name:'Paternity Leave',   days:15, paid:'true', carry:'no',  maxCarry:0},
      LWP:      {name:'Leave Without Pay', days:0,  paid:'false',carry:'no',  maxCarry:0},
      COMP_OFF: {name:'Compensatory Off',  days:0,  paid:'true', carry:'no',  maxCarry:0},
      ACADEMIC: {name:'Academic Leave',    days:10, paid:'true', carry:'no',  maxCarry:0},
      DUTY:     {name:'On Duty Leave',     days:0,  paid:'true', carry:'no',  maxCarry:0}
    };
    var d = map[code];
    if(!d) return;
    $el.html('<i class="fa fa-spinner fa-spin"></i> ' + code);
    post('hr/save_leave_type', {
      id:'', name:d.name, code:code, days_per_year:d.days,
      carry_forward:d.carry, max_carry:d.maxCarry, paid:d.paid, description:''
    }).then(function(r){
      if (r && r.status && r.status !== 'error'){
        toast(d.name+' added','success');
        loadLeaveTypes();
      } else {
        toast(r&&r.message||'Failed','error');
        $el.html('<i class="fa fa-plus-circle"></i> '+code+' <small>'+d.name.replace(/Leave|Off/g,'').trim()+'</small>');
      }
    });
  }

  function openLeaveTypeModal(id){
    $('#leaveTypeId').val('');
    $('#ltName,#ltCode,#ltDescription').val('');
    $('#ltDays').val(12);
    $('#ltCarry').val('no');
    $('#ltMaxCarry').val(0);
    $('#ltPaid').val('true');
    $('#ltEffectiveFrom').val('');
    $('#ltAccrualMode').val('lump_sum');
    $('#ltWaitingPeriod').val(0);
    $('#ltMaxBalance').val('');
    $('#modalLeaveTypeTitle').text('Add Leave Type');
    if(id && leaveTypeCache[id]){
      var t=leaveTypeCache[id];
      $('#leaveTypeId').val(id);
      $('#ltName').val(t.name);
      $('#ltCode').val(t.code);
      $('#ltDays').val(t.days_per_year);
      $('#ltCarry').val(t.carry_forward||'no');
      $('#ltMaxCarry').val(t.max_carry||0);
      $('#ltPaid').val(t.paid===true||t.paid==='true'?'true':'false');
      $('#ltDescription').val(t.description||'');
      // Leave a blank Effective From on edit (defaults to today server-side)
      // — showing the LAST version's date here would look like it's still
      // in force, when saving always creates a NEW version going forward.
      $('#ltAccrualMode').val(t.accrual_mode||'lump_sum');
      $('#ltWaitingPeriod').val(t.waiting_period_days||0);
      $('#ltMaxBalance').val(t.max_balance===null||t.max_balance===undefined?'':t.max_balance);
      $('#modalLeaveTypeTitle').text('Edit Leave Type');
    }
    openModal('modalLeaveType');
  }

  function saveLeaveType(){
    var name=$('#ltName').val().trim(), code=$('#ltCode').val().trim().toUpperCase();
    if(!name||!code){ toast('Name and code are required','error'); return; }
    post('hr/save_leave_type', {
      id:$('#leaveTypeId').val(), name:name, code:code,
      days_per_year:$('#ltDays').val(), carry_forward:$('#ltCarry').val(), max_carry:$('#ltMaxCarry').val(),
      paid:$('#ltPaid').val(), description:$('#ltDescription').val().trim(),
      effective_from:$('#ltEffectiveFrom').val(),
      accrual_mode:$('#ltAccrualMode').val(),
      waiting_period_days:$('#ltWaitingPeriod').val(),
      max_balance:$('#ltMaxBalance').val()
    }).then(function(r){
      if (r && r.status && r.status !== 'error'){
        toast(r.message||'Saved','success');
        if(r.warning) toast(r.warning,'error');
        closeModal('modalLeaveType'); loadLeaveTypes();
      }
      else toast(r.message||'Failed','error');
    });
  }

  async function deleteLeaveType(id){
    if(!await zxConfirm({ title: 'Delete this leave type?' })) return;
    post('hr/delete_leave_type', {id:id}).then(function(r){
      if (r && r.status && r.status !== 'error'){ toast('Deleted','success'); loadLeaveTypes(); }
      else toast(r.message||'Failed','error');
    });
  }

  /* Workforce Policy Programme Phase 3 — safe to click repeatedly; already-
     credited months are skipped server-side (Hr::_accrue_monthly_leave_one_type()
     tracks lastAccrualMonth per staff/type). No-ops entirely while
     LEAVE_ACCRUAL_ENABLED is off. */
  function runLeaveAccrual(){
    post('hr/run_leave_accrual', {}).then(function(r){
      if(!r){ toast('Failed to run accrual','error'); return; }
      if(r.ran===false){ toast(r.reason||'Monthly accrual is not enabled for this school.','error'); return; }
      toast('Accrual run: '+(r.staffProcessed||0)+' staff, '+(r.creditsIssued||0)+' credit(s) issued.','success');
    });
  }

  /* Per-school master accrual switch (schools.leavePolicy.accrualEnabled).
     OFF (default) = full annual up front; ON = accrue month-by-month and cap
     what staff can take at accrued-so-far. Reverts the checkbox on failure so
     the control never lies about the saved state. */
  function saveAccrualMode(enabled){
    if(!HR_CAN_MANAGE){ toast('You do not have permission to change this.','error'); var c=document.getElementById('chkAccrualMode'); if(c) c.checked=!enabled; return; }
    post('hr/save_leave_policy', {accrual_enabled: enabled ? 1 : 0}).then(function(r){
      if(r && r.status==='success'){
        toast(r.message || (enabled?'Monthly accrual ON':'Monthly accrual OFF'),'success',6000);
        loadLeaveTypes(); loadLeaveBalances();
      } else {
        toast((r&&r.message)||'Could not save the accrual setting','error');
        var c=document.getElementById('chkAccrualMode'); if(c) c.checked=!enabled;
      }
    });
  }

  /* Generic per-school leave-policy toggle (schools.leavePolicy.*). Each switch
     is this school's own choice; defaults OFF. Reverts the checkbox on failure
     so the control never lies about the saved state. */
  function saveLeavePolicy(postKey, checked){
    var idMap = {effective_dating:'lpEffectiveDating', prorata:'lpProrata', waiting_period:'lpWaitingPeriod', max_balance:'lpMaxBalance', year_closure:'lpYearClosure'};
    var revert = function(){ var el=document.getElementById(idMap[postKey]); if(el) el.checked=!checked; };
    if(!HR_CAN_MANAGE){ toast('You do not have permission to change this.','error'); revert(); return; }
    var body = {}; body[postKey] = checked ? 1 : 0;
    post('hr/save_leave_policy', body).then(function(r){
      if(r && r.status==='success'){
        toast(r.message || 'Leave policy saved','success',6000);
        loadLeaveTypes(); loadLeaveBalances();
      } else {
        toast((r&&r.message)||'Could not save the leave policy','error');
        revert();
      }
    });
  }

  /* Workforce Policy Programme Phase 7 — Carry-Forward. Preview persists a
     snapshot server-side; Confirm replays exactly that snapshot (never
     recomputes against possibly-drifted live balances), and is idempotent —
     confirming an already-closed year reports back without carrying twice. */
  var _cfLastPreviewedYear = null;

  function previewCarryForward(){
    var year = $('#cfYear').val();
    if(!year){ toast('Enter a year first','error'); return; }
    $('#btnConfirmCarryForward').hide();
    post('hr/preview_leave_year_closure', {year:year}).then(function(r){
      if(!r || !r.status){ toast((r&&r.message)||'Preview failed — is Leave-Year Closure enabled for this school?','error'); return; }
      var rows = r.perStaff || [];
      $('#cfEmpty').toggle(!rows.length);
      $('#cfTableWrap').toggle(!!rows.length);
      if(!rows.length){
        $('#cfEmpty').html('<i class="fa fa-check-circle"></i> No unused, carry-forward-eligible balance found for '+esc(year)+'.');
        return;
      }
      var h='';
      rows.forEach(function(row){
        var staffName = staffCache[row.staffId] ? (staffCache[row.staffId].Name||staffCache[row.staffId].name||row.staffId) : row.staffId;
        var typeName = (leaveTypeCache[row.typeId]&&leaveTypeCache[row.typeId].name) || row.typeId;
        h += '<tr><td>'+esc(staffName)+'</td><td>'+esc(typeName)+'</td>';
        h += '<td class="hr-num">'+row.unused+'</td><td class="hr-num">'+row.maxCarry+'</td>';
        h += '<td class="hr-num">'+row.eligibleCarry+'</td><td class="hr-num"><strong>'+row.actualCarry+'</strong></td>';
        h += '<td class="hr-num" style="color:'+(row.forfeited>0?'#b91c1c':'inherit')+'">'+row.forfeited+'</td></tr>';
      });
      $('#tblCarryForward tbody').html(h);
      _cfLastPreviewedYear = year;
      $('#btnConfirmCarryForward').show();
      toast('Preview ready — review before confirming.','success');
    });
  }

  async function confirmCarryForward(){
    var year = $('#cfYear').val();
    if(!year || year !== _cfLastPreviewedYear){ toast('Preview this year again first — the year changed since the last preview.','error'); return; }
    if (!await zxConfirm({
      title: 'Close Leave Year ' + year + '?',
      message: 'Balances shown will be carried forward. This cannot be undone.',
      confirmText: 'Close year', danger: true
    })) return;
    post('hr/confirm_leave_year_closure', {year:year}).then(function(r){
      if(!r || !r.status){ toast((r&&r.message)||'Confirm failed','error'); return; }
      if(r.idempotent){ toast('This Leave Year was already closed — nothing carried twice.','success'); }
      else { toast('Leave Year '+year+' closed. '+(r.staffApplied||0)+' staff balance(s) updated.','success'); }
      $('#btnConfirmCarryForward').hide();
    });
  }

  function leavePayLabel(l){
    // Determine paid status from the leave type definition (authoritative source)
    var isPaid = l.type_paid;
    var typeId = l.type_id||'';
    if(typeId && leaveTypeCache[typeId]){
      var lt = leaveTypeCache[typeId];
      isPaid = (lt.paid===true||lt.paid==='true'||lt.paid==='1'||lt.paid===1);
    }
    // Fallback: if no type_id, try matching by leaveType name (Teacher-app format)
    if(isPaid===undefined && !typeId){
      var ltName = (l.leave_type||l.leaveType||l.type_code||'').toLowerCase();
      for(var tid in leaveTypeCache){
        var ltc = leaveTypeCache[tid];
        if((ltc.code||'').toLowerCase()===ltName || (ltc.name||'').toLowerCase()===ltName){
          isPaid = (ltc.paid===true||ltc.paid==='true'||ltc.paid==='1'||ltc.paid===1);
          break;
        }
      }
      // Default: common types are paid
      if(isPaid===undefined){
        var paidTypes = ['casual','cl','sick','sl','earned','el','ml','maternity','paternity'];
        isPaid = paidTypes.indexOf(ltName)!==-1;
      }
    }

    var status = (l.status||'').toLowerCase();
    if(status==='rejected') return 'Rejected';
    if(status==='pending') return isPaid ? 'Paid Leave' : 'Unpaid Leave (LWP)';

    // For approved/decided requests — trust the leave type definition over stored lwp_days
    if(!isPaid) return 'Unpaid Leave (LWP)';

    // Paid leave type — check if balance was exceeded (only trust lwp_days if type is actually unpaid)
    // Fraction safety (2026-08-19): these were parseInt, which floored a
    // 0.5-day LOP to 0 — so a half-day loss of pay fell through both tests
    // below and rendered as "Fully Paid". Leave days are legitimately
    // fractional (half-day leave = 0.5). parseFloat matches the canonical
    // pattern already used by the admin payslip split further down this file.
    var lwp  = parseFloat(l.lwp_days||0);
    var days = parseFloat(l.days||0);
    // If the stored type_paid was wrong (false when type is actually paid), ignore stored lwp_days
    var storedTypePaid = l.type_paid;
    if(storedTypePaid===false||storedTypePaid==='false'){
      // Stored data was computed when type was wrongly treated as unpaid — show as Fully Paid
      return 'Fully Paid';
    }
    if(lwp>0 && lwp<days) return 'Partially Paid (Exceeds Balance)';
    if(lwp>=days && days>0) return 'Unpaid Leave (LWP)';
    return 'Fully Paid';
  }

  function payLabelBadge(label){
    if(!label) return '';
    if(label.indexOf('Fully')>-1)     return '<span class="hr-badge hr-badge-approved" style="font-size:11px"><i class="fa fa-check-circle"></i> '+esc(label)+'</span>';
    if(label.indexOf('Partial')>-1)   return '<span class="hr-badge" style="background:#fef3c7;color:#92400e;font-size:11px"><i class="fa fa-exclamation-circle"></i> '+esc(label)+'</span>';
    if(label.indexOf('Unpaid')>-1||label.indexOf('LWP')>-1) return '<span class="hr-badge" style="background:#fee2e2;color:#991b1b;font-size:11px"><i class="fa fa-minus-circle"></i> '+esc(label)+'</span>';
    if(label.indexOf('Reject')>-1)    return '<span class="hr-badge hr-badge-rejected" style="font-size:11px">'+esc(label)+'</span>';
    return '<span class="hr-badge" style="font-size:11px">'+esc(label)+'</span>';
  }

  function loadLeaveRequests(){
    var params='?status='+($('#filterLeaveStatus').val()||'')+'&staff_id='+($('#filterLeaveStaff').val()||'');
    getJSON('hr/get_leave_requests'+params).then(function(r){
      var reqs=toMap((r&&r.leave_requests)?r.leave_requests:(r&&r.data)?r.data:{});
      var $tb=$('#tblLeaveRequests tbody');
      var keys=Object.keys(reqs);
      if(!keys.length){ $tb.html('<tr><td colspan="9" class="hr-empty"><i class="fa fa-inbox"></i> No leave requests found.</td></tr>'); return; }
      var h='', i=0;
      $.each(reqs, function(k,l){
        i++;
        var label = leavePayLabel(l);
        var code  = l.type_code ? ' <small style="opacity:.6">('+esc(l.type_code)+')</small>' : '';
        // Days breakdown
        var daysCol = ''+(l.days||'-');
        // Fraction safety (2026-08-19): parseInt suppressed a 0.5P/0.5L split entirely.
        var pd = parseFloat(l.paid_days||0), lw = parseFloat(l.lwp_days||0);
        if(pd>0 && lw>0) daysCol += ' <small style="color:var(--t3)">('+pd+'P+'+lw+'L)</small>';

        h+='<tr><td class="hr-num">'+i+'</td><td>'+esc(l.staff_name||staffName(l.staff_id))+'</td>';
        h+='<td>'+esc(l.type_name||l.leave_type||l.type_code)+code+'</td>';
        h+='<td>'+esc(l.from_date||l.start_date)+'</td><td>'+esc(l.to_date||l.end_date)+'</td>';
        h+='<td class="hr-num">'+daysCol+'</td>';
        h+='<td>'+payLabelBadge(label)+'</td>';
        h+='<td>'+badgeHtml(l.status)+'</td>';
        h+='<td>';
        var _st = (l.status||'').toLowerCase();
        if(_st==='pending'){
          h+='<button class="hr-act-btn" onclick="event.stopPropagation();this.disabled=true;HR.approveLeave(\''+esc(k)+'\')" title="Approve"><i class="fa fa-check" style="pointer-events:none"></i></button> ';
          h+='<button class="hr-act-btn danger" onclick="event.stopPropagation();this.disabled=true;HR.rejectLeave(\''+esc(k)+'\')" title="Reject"><i class="fa fa-times" style="pointer-events:none"></i></button>';
        }
        // LEAVE-1 — cancelling restores the balance, which is why it is offered
        // for an APPROVED leave too. The endpoint accepts exactly pending or
        // approved and refuses anything else, so this mirrors the server rather
        // than deciding for it.
        if(_st==='pending'||_st==='approved'){
          h+=' <button class="hr-act-btn" onclick="event.stopPropagation();HR.cancelLeave(\''+esc(k)+'\')" title="Cancel this leave and restore the balance"><i class="fa fa-ban" style="pointer-events:none"></i></button>';
        }
        // Info tooltip for calculation reason
        if(l.calculation_reason){
          h+=' <button class="hr-act-btn" title="'+esc(l.calculation_reason)+'" style="cursor:help;opacity:.6"><i class="fa fa-info-circle"></i></button>';
        }
        h+='</td></tr>';
      });
      $tb.html(h);
    });
  }

  var _leaveReqModalOpening = false;
  function openLeaveRequestModal(){
    if(_leaveReqModalOpening) return;
    _leaveReqModalOpening = true;
    setTimeout(function(){ _leaveReqModalOpening = false; }, 500);
    $('#leaveReqId').val('');
    $('#lrReason').val('');
    $('#lrStart,#lrEnd').val('');
    $('#lrHalfDay').prop('checked',false);
    fillStaffSelect('#lrStaff');
    fillLeaveTypeSelect('#lrType');
    openModal('modalLeaveRequest');
  }

  function saveLeaveRequest(){
    var staff=$('#lrStaff').val(), type=$('#lrType').val(), start=$('#lrStart').val(), end=$('#lrEnd').val();
    if(!staff||!type||!start||!end){ toast('Staff, type, start and end dates are required','error'); return; }
    var $btn=$('#modalLeaveRequest .hr-btn-primary');
    var origHtml=$btn.html();
    $btn.prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Submitting...');
    post('hr/apply_leave', {
      staff_id:staff, type_id:type,
      from_date:start, to_date:end, reason:$('#lrReason').val().trim(),
      half_day:$('#lrHalfDay').prop('checked')?'1':'0'
    }).then(function(r){
      $btn.prop('disabled',false).html(origHtml);
      if (r && r.status && r.status !== 'error'){
        var msg = r.message||'Submitted';
        if(r.lwp_warning){ toast(msg,'warning'); } else { toast(msg,'success'); }
        closeModal('modalLeaveRequest'); loadLeaveRequests();
      } else toast(r.message||'Failed','error');
    }).catch(function(){ $btn.prop('disabled',false).html(origHtml); toast('Request failed','error'); });
  }

  function approveLeave(id){
    if($('#modalLeaveAction').hasClass('open')) return;
    $('#leaveActionId').val(id);
    $('#leaveActionType').val('approved');
    $('#leaveActionTitle').text('Approve Leave');
    $('#leaveActionRemarks').val('');
    $('#btnLeaveAction').removeClass('hr-btn-danger').addClass('hr-btn-primary').html('<i class="fa fa-check"></i> Approve');
    openModal('modalLeaveAction');
  }
  function rejectLeave(id){
    if($('#modalLeaveAction').hasClass('open')) return;
    $('#leaveActionId').val(id);
    $('#leaveActionType').val('rejected');
    $('#leaveActionTitle').text('Reject Leave');
    $('#leaveActionRemarks').val('');
    $('#btnLeaveAction').removeClass('hr-btn-primary').addClass('hr-btn-danger').html('<i class="fa fa-times"></i> Reject');
    openModal('modalLeaveAction');
  }
  /**
   * Cancel a leave request and give the days back.
   *
   * Confirmed, and the confirmation says what actually happens — the balance
   * moves, which is the part an approver needs to have understood before
   * clicking. The server is idempotent and CAS-locked, so a double-click
   * cannot restore twice; the guard here is for the operator, not for safety.
   */
  var _cancellingLeave = false;
  async function cancelLeave(id){
    if(_cancellingLeave) return;
    if(!await zxConfirm({ title: 'Cancel this leave request?', message: 'The leave will be marked cancelled and the days will be added back to the employee\\\'s balance. This cannot be undone from here — a new request would have to be filed.' })) return;

    _cancellingLeave = true;
    post('hr/cancel_leave', {id:id}).then(function(r){
      _cancellingLeave = false;
      if(r && r.status === 'success'){
        toast(r.message || 'Leave cancelled and balance restored','success');
        loadLeaveRequests();
        if(typeof initBalances === 'function') initBalances();
      } else {
        toast((r && r.message) || 'Could not cancel this leave','error');
      }
    }).fail(function(){
      _cancellingLeave = false;
      toast('Network error','error');
    });
  }
  function confirmLeaveAction(){
    var id=$('#leaveActionId').val(), action=$('#leaveActionType').val();
    var decision = action === 'approved' ? 'Approved' : 'Rejected';
    var $btn=$('#btnLeaveAction');
    var origHtml=$btn.html();
    $btn.prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Processing...');
    post('hr/decide_leave', {id:id, decision:decision, remarks:$('#leaveActionRemarks').val().trim()}).then(function(r){
      $btn.prop('disabled',false).html(origHtml);
      if (r && r.status && r.status !== 'error'){
        var msg = r.message||'Updated';
        // Fraction safety (2026-08-19): parseInt made a 0.5-day LOP show a green
        // success toast, suppressing the loss-of-pay warning entirely.
        if(r.lwp_days && parseFloat(r.lwp_days)>0){ toast(msg,'warning'); } else { toast(msg,'success'); }
        closeModal('modalLeaveAction');
        // Refresh BOTH panels — the request grid AND the balance grid.
        // Approving deducts BAL.used server-side via _fsSyncLeaveBalance,
        // but the balances panel was previously left stale because only
        // loadLeaveRequests() was called here. That made the user think
        // the deduction didn't happen even though it had. Permanent fix:
        // refresh both whenever a decision is recorded.
        loadLeaveRequests();
        loadLeaveBalances();
        // Dashboard pending-count widget also needs to drop. Refresh
        // only if the dashboard panel is visible.
        if (typeof loadDashboard === 'function' && document.getElementById('panelDashboard')) {
          try { loadDashboard(); } catch (_) {}
        }
      } else { toast(r.message||'Failed','error'); }
    }).catch(function(){ $btn.prop('disabled',false).html(origHtml); toast('Request failed','error'); });
  }

  function loadLeaveBalances(){
    getJSON('hr/get_leave_balances').then(function(r){
      var data=(r&&r.balances)?r.balances:(r&&r.data)?r.data:{};
      var types=(r&&r.types)?r.types:{};
      var $thead=$('#theadBalances tr');
      var $tb=$('#tblLeaveBalances tbody');

      // Build header with leave type columns
      var typeKeys=Object.keys(types);

      // If no types returned, try to extract type keys from the first staff's balance data
      if(!typeKeys.length){
        var firstStaff=Object.keys(data)[0];
        if(firstStaff && data[firstStaff]){
          typeKeys=Object.keys(data[firstStaff]);
          typeKeys.forEach(function(tk){ types[tk]=tk; });
        }
      }

      // Sort leave types by priority: CL, SL, EL first, then alphabetical
      var priorityOrder=['Casual','Sick','Earned','Academic','Compensatory','Paternity','Maternity','Leave Without','On Duty'];
      typeKeys.sort(function(a,b){
        var na=(types[a]||a).toLowerCase(), nb=(types[b]||b).toLowerCase();
        var ia=-1, ib=-1;
        for(var p=0;p<priorityOrder.length;p++){
          if(ia<0 && na.indexOf(priorityOrder[p].toLowerCase())>=0) ia=p;
          if(ib<0 && nb.indexOf(priorityOrder[p].toLowerCase())>=0) ib=p;
        }
        if(ia<0) ia=99; if(ib<0) ib=99;
        return ia-ib;
      });

      var thH='<th>Staff Name</th>';
      $.each(typeKeys, function(i,tk){
        thH+='<th class="hr-num">'+esc(types[tk])+'<br><small style="font-weight:400;opacity:.7">Alloc / Used / Bal</small></th>';
      });
      $thead.html(thH);

      var staffKeys=Object.keys(data);
      if(!staffKeys.length){ $tb.html('<tr><td colspan="'+(typeKeys.length+1)+'" class="hr-empty"><i class="fa fa-inbox"></i> No balance data. Click Initialize Balances to set up.</td></tr>'); return; }
      var h='';
      $.each(data, function(sid,balances){
        h+='<tr><td>'+esc(staffName(sid))+'</td>';
        $.each(typeKeys, function(i,tk){
          var b=balances[tk];
          if(b && typeof b==='object'){
            var alloc=b.allocated||0, used=b.used||0, bal=b.balance||0, carried=b.carried||0;
            var balColor=bal>0?'color:#15803d':'color:#dc2626';
            h+='<td class="hr-num"><span style="opacity:.7">'+alloc+(carried>0?'+'+carried:'')+'</span> / <span style="color:#d97706">'+used+'</span> / <strong style="'+balColor+'">'+bal+'</strong></td>';
          } else {
            h+='<td class="hr-num">'+(b!=null?b:'-')+'</td>';
          }
        });
        h+='</tr>';
      });
      $tb.html(h);
    });
  }

  async function initBalances(){
    if(!await zxConfirm({ title: 'Initialize leave balances for all staff based on leave type definitions? Existing balances will not be overwritten.' })) return;
    post('hr/allocate_leave_balances').then(function(r){
      if (r && r.status && r.status !== 'error'){ toast(r.message||'Initialized','success'); loadLeaveBalances(); }
      else toast(r.message||'Failed','error');
    });
  }

  /* ================================================================
     PAYROLL — MY PAYSLIPS (self-service, all staff)
  ================================================================ */
  function loadMyPayslips(){
    getJSON('hr/my_payslips').then(function(r){
      var slips = (r && r.payslips) ? r.payslips : [];
      var $tb = $('#tblMyPayslips tbody');
      $('#myPayslipsLoading').hide();
      $('#myPayslipsWrap').show();
      if(!slips.length){
        $tb.html('<tr><td colspan="13" class="hr-empty"><i class="fa fa-inbox"></i> No payslips available yet.</td></tr>');
        return;
      }
      var h='';
      $.each(slips, function(i, p){
        var sl = p.slip || {};
        var allowances = (parseFloat(sl.hra)||0)+(parseFloat(sl.da)||0)+(parseFloat(sl.ta)||0)+(parseFloat(sl.medical)||0)+(parseFloat(sl.other_allowances)||0);
        h+='<tr class="hr-payslip-row" data-myslip="'+i+'" style="cursor:pointer">';
        h+='<td>'+esc(p.month)+'</td><td>'+esc(p.year)+'</td>';
        h+='<td class="hr-num">'+fmt(sl.basic)+'</td>';
        h+='<td class="hr-num">'+fmt(allowances)+'</td>';
        h+='<td class="hr-num"><strong>'+fmt(sl.gross)+'</strong></td>';
        h+='<td class="hr-num">'+fmt(sl.total_deductions)+'</td>';
        h+='<td class="hr-num"><strong style="color:var(--gold)">'+fmt(sl.net_pay)+'</strong></td>';
        h+='<td class="hr-num">'+(sl.days_worked||'-')+'</td>';
        h+='<td class="hr-num">'+(sl.days_absent||0)+'</td>';
        h+='<td class="hr-num">'+(sl.paid_leave_days||0)+'</td>';
        h+='<td class="hr-num">'+(sl.lwp_days||0)+'</td>';
        h+='<td>'+payrollBadge(p.status)+'</td>';
        h+='<td><i class="fa fa-chevron-down" style="color:var(--t3)"></i></td></tr>';
        // Detail row — structured payslip breakdown
        h+='<tr class="hr-payslip-detail" id="mydetail_'+i+'">';
        h+='<td colspan="13"><div class="hr-payslip-breakdown">';
        h+='<div class="hr-slip-sections">';

        // ── LEFT: Earnings ──
        h+='<div class="hr-slip-section">';
        h+='<div class="hr-slip-section-hd earn"><i class="fa fa-plus-circle"></i> Earnings</div>';
        h+='<div class="hr-slip-row"><span class="hr-slip-label">Basic Salary</span><span class="hr-slip-value">'+fmt(sl.basic)+'</span></div>';
        if(parseFloat(sl.hra))  h+='<div class="hr-slip-row"><span class="hr-slip-label">HRA (House Rent)</span><span class="hr-slip-value">'+fmt(sl.hra)+'</span></div>';
        if(parseFloat(sl.da))   h+='<div class="hr-slip-row"><span class="hr-slip-label">DA (Dearness)</span><span class="hr-slip-value">'+fmt(sl.da)+'</span></div>';
        if(parseFloat(sl.ta))   h+='<div class="hr-slip-row"><span class="hr-slip-label">TA (Transport)</span><span class="hr-slip-value">'+fmt(sl.ta)+'</span></div>';
        if(parseFloat(sl.medical)) h+='<div class="hr-slip-row"><span class="hr-slip-label">Medical Allowance</span><span class="hr-slip-value">'+fmt(sl.medical)+'</span></div>';
        if(parseFloat(sl.other_allowances)) h+='<div class="hr-slip-row"><span class="hr-slip-label">Other Allowances</span><span class="hr-slip-value">'+fmt(sl.other_allowances)+'</span></div>';
        h+='<div class="hr-slip-row total"><span class="hr-slip-label">Gross Pay</span><span class="hr-slip-value green">'+fmt(sl.gross)+'</span></div>';
        h+='</div>';

        // ── RIGHT: Deductions ──
        h+='<div class="hr-slip-section">';
        h+='<div class="hr-slip-section-hd deduct"><i class="fa fa-minus-circle"></i> Deductions</div>';
        if(parseFloat(sl.pf_employee))      h+='<div class="hr-slip-row"><span class="hr-slip-label">PF (Employee)</span><span class="hr-slip-value">'+fmt(sl.pf_employee)+'</span></div>';
        if(parseFloat(sl.esi_employee))     h+='<div class="hr-slip-row"><span class="hr-slip-label">ESI (Employee)</span><span class="hr-slip-value">'+fmt(sl.esi_employee)+'</span></div>';
        if(parseFloat(sl.professional_tax)) h+='<div class="hr-slip-row"><span class="hr-slip-label">Professional Tax</span><span class="hr-slip-value">'+fmt(sl.professional_tax)+'</span></div>';
        if(parseFloat(sl.tds))              h+='<div class="hr-slip-row"><span class="hr-slip-label">TDS (Income Tax)</span><span class="hr-slip-value">'+fmt(sl.tds)+'</span></div>';
        if(parseFloat(sl.other_deductions)) h+='<div class="hr-slip-row"><span class="hr-slip-label">Other Deductions</span><span class="hr-slip-value">'+fmt(sl.other_deductions)+'</span></div>';
        if(parseFloat(sl.lwp_deduction)>0){
          // Fraction safety (2026-08-19): this printed "LWP Deduction (0 days)"
          // beside a real rupee figure computed on 0.5 — the slip did not
          // reconcile. Matches the admin-side payslip formatting further down.
          h+='<div class="hr-slip-row"><span class="hr-slip-label">LWP Deduction ('+(parseFloat(sl.lwp_days||0).toFixed(1).replace(/\.0$/,''))+' days)</span><span class="hr-slip-value amber">-'+fmt(sl.lwp_deduction)+'</span></div>';
        }
        h+='<div class="hr-slip-row total"><span class="hr-slip-label">Total Deductions</span><span class="hr-slip-value red">'+fmt(sl.total_deductions)+'</span></div>';
        h+='</div>';

        h+='</div>'; // end sections

        // ── NET PAY highlight + Download ──
        h+='<div class="hr-slip-net-row">';
        h+='<span class="hr-slip-net-label"><i class="fa fa-inr" style="margin-right:4px"></i>Net Pay</span>';
        h+='<span class="hr-slip-net-value">'+fmt(sl.net_pay)+'</span>';
        h+='</div>';
        h+='<div style="text-align:center;margin-top:8px">';
        h+='<a class="hr-btn hr-btn-ghost hr-btn-sm" href="'+BASE+'hr/download_payslip?run_id='+encodeURIComponent(p.run_id)+'&staff_id='+encodeURIComponent(sl.staff_id||'')+'" target="_blank"><i class="fa fa-file-pdf-o"></i> Download Payslip PDF</a>';
        h+='</div>';

        // ── Working Info + Leave ──
        var hasWorkInfo = sl.working_days||sl.daily_salary;
        var hasLeaves = sl.leave_details && typeof sl.leave_details==='object' && Object.keys(sl.leave_details).length;
        var myStatus=(sl.status||p.status||'').toLowerCase();
        if(hasWorkInfo||hasLeaves||myStatus==='paid'){
          h+='<div class="hr-slip-sections" style="margin-top:14px">';
          if(hasWorkInfo){
            h+='<div class="hr-slip-section">';
            h+='<div class="hr-slip-section-hd info"><i class="fa fa-calendar"></i> Working Info</div>';
            h+='<div class="hr-slip-row"><span class="hr-slip-label">Working Days</span><span class="hr-slip-value">'+(sl.working_days||'-')+'</span></div>';
            h+='<div class="hr-slip-row"><span class="hr-slip-label">Days Worked</span><span class="hr-slip-value">'+(sl.days_worked||'-')+'</span></div>';
            h+='<div class="hr-slip-row"><span class="hr-slip-label">Days Absent</span><span class="hr-slip-value">'+(sl.days_absent||0)+'</span></div>';
            // Fraction safety (2026-08-19): the parseInt gate hid the LWP row
            // entirely for a sub-1-day LOP, while net pay was still reduced —
            // the employee could not see why they were paid less.
            if(parseFloat(sl.lwp_days)>0) h+='<div class="hr-slip-row"><span class="hr-slip-label">LWP Days</span><span class="hr-slip-value amber">'+(sl.lwp_days||0)+'</span></div>';
            h+='<div class="hr-slip-row"><span class="hr-slip-label">Daily Salary</span><span class="hr-slip-value">'+fmt(sl.daily_salary)+'</span></div>';
            if(sl.deduction_reason && sl.deduction_reason!=='No deduction') h+='<div class="hr-slip-row"><span class="hr-slip-label">Deduction Reason</span><span class="hr-slip-value" style="font-size:11px;max-width:180px;text-align:right">'+esc(sl.deduction_reason)+'</span></div>';
            h+='</div>';
          }
          if(hasLeaves){
            h+='<div class="hr-slip-section">';
            h+='<div class="hr-slip-section-hd leave"><i class="fa fa-calendar-minus-o"></i> Leave Breakdown</div>';
            $.each(sl.leave_details, function(idx, ld){
              var paidTag = ld.paid ? ' <span style="color:var(--gold);font-size:10px">(Paid)</span>' : ' <span style="color:#991b1b;font-size:10px">(LWP)</span>';
              var label = esc(ld.type_code||ld.type_name||'Leave') + paidTag;
              var dateRange = ld.from ? '<div style="font-size:10px;color:var(--t3);margin-top:1px">'+esc(ld.from)+' to '+esc(ld.to||'?')+'</div>' : '';
              h+='<div class="hr-slip-row" style="flex-wrap:wrap"><span class="hr-slip-label">'+label+dateRange+'</span><span class="hr-slip-value">'+esc(''+(ld.total_days||ld.days||0))+' day(s)</span></div>';
            });
            h+='</div>';
          }
          if(myStatus==='paid'){
            h+='<div class="hr-slip-section">';
            h+='<div class="hr-slip-section-hd pay"><i class="fa fa-check-circle"></i> Payment Details</div>';
            if(sl.payment_mode) h+='<div class="hr-slip-row"><span class="hr-slip-label">Payment Mode</span><span class="hr-slip-value">'+esc(sl.payment_mode)+'</span></div>';
            if(sl.payment_date) h+='<div class="hr-slip-row"><span class="hr-slip-label">Payment Date</span><span class="hr-slip-value">'+esc(sl.payment_date)+'</span></div>';
            if(sl.payment_reference) h+='<div class="hr-slip-row"><span class="hr-slip-label">Reference No.</span><span class="hr-slip-value">'+esc(sl.payment_reference)+'</span></div>';
            h+='</div>';
          }
          h+='</div>';
        }

        h+='</div></td></tr>';
      });
      $tb.html(h);
      $tb.find('.hr-payslip-row').on('click', function(){
        var did='mydetail_'+$(this).data('myslip');
        var $d=$('#'+did);
        $d.toggleClass('open');
        $(this).find('.fa-chevron-down,.fa-chevron-up').toggleClass('fa-chevron-down fa-chevron-up');
      });
    }).fail(function(){
      $('#myPayslipsLoading').html('<i class="fa fa-inbox"></i> Could not load payslips.');
    });
  }

  /* ================================================================
     PAYROLL — ADMIN
  ================================================================ */
  function loadSalaryStructures(){
    getJSON('hr/get_salary_structures').then(function(r){
      var structs=toMap((r&&r.salary_structures)?r.salary_structures:(r&&r.data)?r.data:{});
      _salaryCache = structs;

      // Coverage banner
      var cov = r.coverage||{};
      var $banner = $('#salCoverageBanner');
      var $btnBf = $('#btnBackfill');
      if(cov.missing > 0){
        $banner.html('<div style="padding:10px 16px;margin-bottom:12px;border-radius:8px;background:rgba(217,119,6,.08);border:1px solid rgba(217,119,6,.2);font:400 12px/1.5 var(--font-m);color:#92400e;display:flex;align-items:center;gap:8px">'
          +'<i class="fa fa-exclamation-triangle"></i>'
          +'<span><strong>'+cov.missing+'</strong> of <strong>'+cov.total_staff+'</strong> staff members have no salary structure and will be skipped in payroll.</span>'
          +'</div>').show();
        $btnBf.show();
      } else if(cov.total_staff > 0) {
        $banner.html('<div style="padding:8px 16px;margin-bottom:12px;border-radius:8px;background:rgba(188,90,60,.06);border:1px solid rgba(188,90,60,.15);font:400 12px/1.5 var(--font-m);color:#BC5A3C;display:flex;align-items:center;gap:8px">'
          +'<i class="fa fa-check-circle"></i>'
          +'<span>All <strong>'+cov.total_staff+'</strong> staff members have salary structures. Payroll ready.</span>'
          +'</div>').show();
        $btnBf.hide();
      } else {
        $banner.hide();
        $btnBf.hide();
      }

      var $tb=$('#tblSalary tbody');
      var keys=Object.keys(structs);
      if(!keys.length){ $tb.html('<tr><td colspan="16" class="hr-empty"><i class="fa fa-inbox"></i> No salary structures defined.</td></tr>'); return; }
      var h='', i=0;
      $.each(structs, function(k,s){
        i++;
        var basic=parseFloat(s.basic)||0;
        var hra=parseFloat(s.hra)||0, da=parseFloat(s.da)||0, ta=parseFloat(s.ta)||0;
        var med=parseFloat(s.medical)||0, sp=parseFloat(s.other_allowances||s.special_allowance)||0;
        var gross=basic+hra+da+ta+med+sp;

        var pfPct=parseFloat(s.pf_employee)||0;
        var esiPct=parseFloat(s.esi_employee)||0;
        var tdsPct=parseFloat(s.tds)||0;
        var pfEmp=Math.round(basic*(pfPct/100)*100)/100;
        var esiEmp=Math.round(gross*(esiPct/100)*100)/100;
        var pt=parseFloat(s.professional_tax)||0;
        var otherDed=parseFloat(s.other_deductions)||0;
        var tds=Math.round(gross*(tdsPct/100)*100)/100;
        var deductions=pfEmp+esiEmp+pt+tds+otherDed;
        var net=gross-deductions;
        if(net<0) net=0;

        // Tooltip for Gross
        var grossTip='Basic: '+fmt(basic)+'\nHRA: '+fmt(hra)+'\nDA: '+fmt(da)+'\nTA: '+fmt(ta)+'\nMedical: '+fmt(med)+'\nOther: '+fmt(sp);
        // Tooltip for Deductions
        var dedTip='PF ('+pfPct+'%): '+fmt(pfEmp)+'\nESI ('+esiPct+'%): '+fmt(esiEmp)+'\nProf. Tax: '+fmt(pt)+'\nTDS ('+tdsPct+'%): '+fmt(tds)+(otherDed?'\nOther: '+fmt(otherDed):'');
        // Tooltip for Net
        var netTip='Gross: '+fmt(gross)+' - Deductions: '+fmt(deductions)+' = Net: '+fmt(net);

        h+='<tr class="hr-sal-row" data-salid="'+esc(k)+'" style="cursor:pointer" title="Click to view full breakdown">';
        var srcTag = (s.source==='registration') ? ' <span style="font-size:9px;padding:1px 5px;border-radius:3px;background:rgba(217,119,6,.1);color:#d97706;font-weight:600">Auto</span>' : (s.source==='manual' ? ' <span style="font-size:9px;padding:1px 5px;border-radius:3px;background:rgba(188,90,60,.1);color:#BC5A3C;font-weight:600">HR</span>' : '');
        h+='<td class="hr-num">'+i+'</td><td>'+esc(staffName(s.staff_id))+srcTag+'</td>';
        h+='<td class="hr-num">'+fmt(basic)+'</td>';
        h+='<td class="hr-num">'+fmt(hra)+'</td>';
        h+='<td class="hr-num">'+fmt(da)+'</td>';
        h+='<td class="hr-num">'+fmt(ta)+'</td>';
        h+='<td class="hr-num">'+fmt(med)+'</td>';
        h+='<td class="hr-num">'+fmt(sp)+'</td>';
        h+='<td class="hr-num hr-sal-tip" title="'+esc(grossTip)+'"><strong>'+fmt(gross)+'</strong></td>';
        h+='<td class="hr-num" style="font-size:11px">'+fmt(pfEmp)+'</td>';
        h+='<td class="hr-num" style="font-size:11px">'+fmt(esiEmp)+'</td>';
        h+='<td class="hr-num" style="font-size:11px">'+fmt(pt)+'</td>';
        h+='<td class="hr-num" style="font-size:11px">'+fmt(tds)+'</td>';
        h+='<td class="hr-num hr-sal-tip" title="'+esc(dedTip)+'"><strong style="color:#d97706">'+fmt(deductions)+'</strong></td>';
        h+='<td class="hr-num hr-sal-tip" title="'+esc(netTip)+'"><strong style="color:'+(net>0?'var(--gold)':'#dc2626')+'">'+fmt(net)+'</strong></td>';
        h+='<td style="white-space:nowrap">';
        h+='<button class="hr-act-btn" onclick="event.stopPropagation();HR.viewSalaryDetail(\''+esc(k)+'\')" title="View Details"><i class="fa fa-eye"></i></button> ';
        h+='<button class="hr-act-btn" onclick="event.stopPropagation();HR.editSalary(\''+esc(k)+'\')" title="Edit"><i class="fa fa-pencil"></i></button> ';
        h+='<button class="hr-act-btn danger" onclick="event.stopPropagation();HR.deleteSalary(\''+esc(k)+'\')" title="Delete"><i class="fa fa-trash"></i></button>';
        h+='</td></tr>';

        // Expandable detail row
        h+='<tr class="hr-sal-detail" id="saldetail_'+esc(k)+'">';
        h+='<td colspan="16"><div class="hr-sal-breakdown">';
        h+='<div class="hr-sal-bd-section">';
        h+='<div class="hr-sal-bd-title"><i class="fa fa-plus-circle" style="color:var(--gold)"></i> Earnings</div>';
        h+='<dl class="hr-sal-dl">';
        h+='<div><dt>Basic Salary</dt><dd>'+fmt(basic)+'</dd></div>';
        h+='<div><dt>HRA</dt><dd>'+fmt(hra)+'</dd></div>';
        h+='<div><dt>DA</dt><dd>'+fmt(da)+'</dd></div>';
        h+='<div><dt>TA</dt><dd>'+fmt(ta)+'</dd></div>';
        h+='<div><dt>Medical Allowance</dt><dd>'+fmt(med)+'</dd></div>';
        h+='<div><dt>Other Allowances</dt><dd>'+fmt(sp)+'</dd></div>';
        h+='<div class="hr-sal-dl-total"><dt>Total Gross</dt><dd><strong>'+fmt(gross)+'</strong></dd></div>';
        h+='</dl></div>';
        h+='<div class="hr-sal-bd-section">';
        h+='<div class="hr-sal-bd-title"><i class="fa fa-minus-circle" style="color:#dc2626"></i> Deductions</div>';
        h+='<dl class="hr-sal-dl">';
        h+='<div><dt>PF (Employee '+pfPct+'%)</dt><dd>'+fmt(pfEmp)+'</dd></div>';
        h+='<div><dt>ESI (Employee '+esiPct+'%)</dt><dd>'+fmt(esiEmp)+'</dd></div>';
        h+='<div><dt>Professional Tax</dt><dd>'+fmt(pt)+'</dd></div>';
        h+='<div><dt>TDS ('+tdsPct+'%)</dt><dd>'+fmt(tds)+'</dd></div>';
        if(otherDed>0) h+='<div><dt>Other Deductions</dt><dd>'+fmt(otherDed)+'</dd></div>';
        h+='<div class="hr-sal-dl-total"><dt>Total Deductions</dt><dd><strong style="color:#d97706">'+fmt(deductions)+'</strong></dd></div>';
        h+='</dl></div>';
        h+='<div class="hr-sal-bd-section hr-sal-bd-net">';
        h+='<div class="hr-sal-bd-title"><i class="fa fa-rupee" style="color:var(--gold)"></i> Net Pay</div>';
        h+='<div class="hr-sal-net-amount">'+fmt(net)+'</div>';
        h+='<div class="hr-sal-net-formula">'+fmt(gross)+' - '+fmt(deductions)+' = <strong>'+fmt(net)+'</strong></div>';
        if(net<=0) h+='<div style="color:#dc2626;font-size:12px;margin-top:4px"><i class="fa fa-exclamation-triangle"></i> Net pay is zero or negative</div>';
        // Audit trail
        var auditHtml='';
        if(s.created_at || s.updated_by){
          auditHtml+='<div style="margin-top:10px;padding-top:8px;border-top:1px dashed var(--border);font-size:11px;color:var(--t3);line-height:1.7">';
          if(s.created_at) auditHtml+='<div><i class="fa fa-clock-o"></i> Created: '+_fmtAuditDate(s.created_at)+'</div>';
          if(s.updated_by) auditHtml+='<div><i class="fa fa-pencil"></i> Last updated by <strong style="color:var(--t2)">'+esc(s.updated_by)+'</strong>'+(s.updated_at?' on '+_fmtAuditDate(s.updated_at):'')+'</div>';
          if(s.source) auditHtml+='<div><i class="fa fa-tag"></i> Source: '+(s.source==='manual'?'Manual (HR)':s.source==='registration'?'Auto (Staff Registration)':esc(s.source))+'</div>';
          if(s._version) auditHtml+='<div><i class="fa fa-code-fork"></i> Version: '+s._version+'</div>';
          auditHtml+='</div>';
        }
        h+=auditHtml;
        h+='</div>';
        h+='</div></td></tr>';
      });
      $tb.html(h);

      // Click row to toggle detail
      $tb.find('.hr-sal-row').on('click', function(){
        var id=$(this).data('salid');
        var $d=$('#saldetail_'+id);
        $d.toggleClass('open');
        $(this).toggleClass('hr-sal-row-open');
      });
    });
  }

  function viewSalaryDetail(id){
    var $d=$('#saldetail_'+id);
    if($d.length){
      $d.toggleClass('open');
      $d.prev('.hr-sal-row').toggleClass('hr-sal-row-open');
      if($d.hasClass('open')) $d[0].scrollIntoView({behavior:'smooth',block:'nearest'});
    }
  }

  function calcGrossFromObj(s){
    return (parseFloat(s.basic)||0)+(parseFloat(s.hra)||0)+(parseFloat(s.da)||0)+(parseFloat(s.ta)||0)+(parseFloat(s.medical)||0)+(parseFloat(s.special_allowance)||0);
  }
  function calcDeductionsFromObj(s,gross){
    var basic=parseFloat(s.basic)||0;
    var pfEmp=basic*((parseFloat(s.pf_employee)||0)/100);
    var esiEmp=gross*((parseFloat(s.esi_employee)||0)/100);
    var pt=parseFloat(s.professional_tax)||0;
    var tds=gross*((parseFloat(s.tds)||0)/100);
    return pfEmp+esiEmp+pt+tds;
  }

  function calcSalary(){
    var basic=parseFloat($('#salBasic').val())||0;
    var hra=parseFloat($('#salHRA').val())||0;
    var da=parseFloat($('#salDA').val())||0;
    var ta=parseFloat($('#salTA').val())||0;
    var med=parseFloat($('#salMedical').val())||0;
    var sp=parseFloat($('#salSpecial').val())||0;
    var gross=basic+hra+da+ta+med+sp;

    var pfEmp=basic*((parseFloat($('#salPFEmp').val())||0)/100);
    var esiEmp=gross*((parseFloat($('#salESIEmp').val())||0)/100);
    var pt=parseFloat($('#salPT').val())||0;
    var tds=gross*((parseFloat($('#salTDS').val())||0)/100);
    var deductions=pfEmp+esiEmp+pt+tds;
    var net=gross-deductions;
    if(net<0) net=0;

    $('#salGrossDisplay').text(fmt(gross));
    $('#salDeductDisplay').text(fmt(deductions));
    $('#salNetDisplay').text(fmt(net)).css('color', net>0?'var(--gold)':'#dc2626');

    // Update deduction breakdown in modal
    var dedBreakdown = 'PF: '+fmt(pfEmp)+' | ESI: '+fmt(esiEmp)+' | PT: '+fmt(pt)+' | TDS: '+fmt(tds);
    $('#salDeductBreakdown').text(dedBreakdown);

    // Warning if net <= 0
    if(gross>0 && net<=0){
      $('#salNetWarning').show();
    } else {
      $('#salNetWarning').hide();
    }
  }

  function openSalaryModal(id){
    $('#salaryId').val('');
    fillStaffSelect('#salStaff');
    $('#salBasic,#salHRA,#salDA,#salTA,#salMedical,#salSpecial').val(0);
    $('#salPFEmp').val(12); $('#salPFEr').val(12);
    $('#salESIEmp').val(0.75); $('#salESIEr').val(3.25);
    $('#salPT').val(200); $('#salTDS').val(0);
    $('#modalSalaryTitle').text('Add Salary Structure');
    calcSalary();
    openModal('modalSalary');
  }

  function editSalary(id){
    if(!_salaryCache[id]){ toast('Salary not found in cache — refreshing','warning'); loadSalaryStructures(); return; }
    var s=_salaryCache[id];
    $('#salaryId').val(id);
    fillStaffSelect('#salStaff', s.staff_id);
    $('#salBasic').val(s.basic); $('#salHRA').val(s.hra); $('#salDA').val(s.da);
    $('#salTA').val(s.ta); $('#salMedical').val(s.medical); $('#salSpecial').val(s.special_allowance||s.other_allowances||'');
    $('#salPFEmp').val(s.pf_employee); $('#salPFEr').val(s.pf_employer);
    $('#salESIEmp').val(s.esi_employee); $('#salESIEr').val(s.esi_employer);
    $('#salPT').val(s.professional_tax); $('#salTDS').val(s.tds);
    $('#modalSalaryTitle').text('Edit Salary Structure');
    calcSalary();
    openModal('modalSalary');
  }

  function saveSalary(){
    var staff=$('#salStaff').val();
    if(!staff){ toast('Staff is required','error'); return; }
    post('hr/save_salary_structure', {
      id:$('#salaryId').val(), staff_id:staff,
      basic:$('#salBasic').val(), hra:$('#salHRA').val(), da:$('#salDA').val(),
      ta:$('#salTA').val(), medical:$('#salMedical').val(), other_allowances:$('#salSpecial').val(),
      pf_employee:$('#salPFEmp').val(), pf_employer:$('#salPFEr').val(),
      esi_employee:$('#salESIEmp').val(), esi_employer:$('#salESIEr').val(),
      professional_tax:$('#salPT').val(), tds:$('#salTDS').val()
    }).then(function(r){
      if (r && r.status && r.status !== 'error'){ toast(r.message||'Saved','success'); closeModal('modalSalary'); loadSalaryStructures(); }
      else toast(r.message||'Failed','error');
    });
  }

  async function deleteSalary(id){
    var name=staffName(id);
    if (!await zxConfirm({
      title: 'Delete salary structure for ' + name + '?',
      message: 'It is archived for audit purposes. If this employee has active payroll, deletion is blocked.',
      confirmText: 'Delete structure', danger: true
    })) return;
    post('hr/delete_salary_structure', {id:id}).then(function(r){
      if(r&&r.status==='success'){ toast(r.message||'Deleted and archived','success'); loadSalaryStructures(); }
      else toast(r&&r.message||'Failed to delete','error');
    });
  }

  async function backfillStructures(){
    if (!await zxConfirm({
      title: 'Auto-create missing salary structures?',
      message: 'Uses registration salary data (Basic + Allowances) and applies default deduction rates. '
             + 'Existing structures are not changed.',
      confirmText: 'Create missing'
    })) return;
    var $btn=$('#btnBackfill');
    $btn.prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Creating...');
    post('hr/backfill_salary_structures', {}).then(function(r){
      $btn.prop('disabled',false).html('<i class="fa fa-magic"></i> Auto-Create Missing');
      if(r&&r.status==='success'){
        toast(r.message,'success');
        loadSalaryStructures();
      } else toast(r.message||'Failed','error');
    }).fail(function(){
      $btn.prop('disabled',false).html('<i class="fa fa-magic"></i> Auto-Create Missing');
      toast('Request failed','error');
    });
  }

  async function approvePayroll(id){
    if(!await zxConfirm({ title: 'Approve this payroll run for payment?', message: 'This confirms the salary calculations are correct.' })) return;
    post('hr/approve_payroll', {run_id:id}).then(function(r){
      if(r&&r.status==='success'){ toast(r.message||'Approved','success'); loadPayrollRuns(); }
      else toast(r.message||'Failed','error');
    });
  }

  function exportPayroll(type, runId){
    window.open(BASE+'hr/export_payroll_report?type='+encodeURIComponent(type)+'&run_id='+encodeURIComponent(runId), '_blank');
  }

  /* ── W3.2.4 — per-employee calculation trace ────────────────────────────
     Renders the server's assembled chain verbatim. The browser formats; it
     never computes. Every number arrives already priced by the payroll engine
     and frozen on the payslip. */
  $(document).on('click', '.trace-btn', function(e){
    e.stopPropagation();
    var $b=$(this), sid=$b.data('staff'), runId=$b.data('run');
    var $out=$('.trace-out[data-staff="'+sid+'"]');

    if($out.data('loaded')){ $out.toggle(); return; }

    $b.prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Loading');
    getJSON('hr/payslip_trace?run_id='+encodeURIComponent(runId)+'&staff_id='+encodeURIComponent(sid))
      .then(function(r){
        $b.prop('disabled',false).html('<i class="fa fa-list-ol"></i> Why is this net pay?');
        if(!r || r.status!=='success'){
          $out.html('<span style="color:#b91c1c;font:400 11px/1.5 var(--font-m)">'
            +esc((r&&r.message)||'Could not load the trace.')+'</span>').show();
          return;
        }

        var h='<div style="border:1px solid var(--border);border-radius:8px;overflow:hidden">';

        /* Consistency first. A trace that does not reconcile with the payslip
           must say so loudly rather than be read as fact. */
        var c=r.consistency||{};
        if(c.available===false){
          h+='<div style="padding:8px 10px;background:rgba(217,119,6,.09);color:#b45309;font:400 11px/1.5 var(--font-m)">'
            +'<i class="fa fa-exclamation-triangle"></i> '+esc(c.reason||'No trace recorded.')+'</div>';
        } else if(c.consistent===false){
          h+='<div style="padding:8px 10px;background:rgba(220,38,38,.09);color:#b91c1c;font:600 11px/1.5 var(--font-m)">'
            +'<i class="fa fa-times-circle"></i> '+esc(c.reason||'Trace does not reconcile with the payslip.')+'</div>';
        } else if(c.consistent===true){
          h+='<div style="padding:8px 10px;background:rgba(10,125,51,.08);color:#0a7d33;font:400 11px/1.5 var(--font-m)">'
            +'<i class="fa fa-check-circle"></i> Trace reconciles with the stored payslip to the paisa.</div>';
        }

        var steps=r.steps||[];
        for(var i=0;i<steps.length;i++){
          var st=steps[i];
          h+='<div style="border-top:1px solid var(--border);padding:9px 11px">';
          h+='<div style="font:600 11px/1.4 var(--font-b);color:var(--t1)">'+(i+1)+'. '+esc(st.title)
            +(st.source?' <span style="font-weight:400;color:var(--t3)">· from '+esc(st.source)+'</span>':'')+'</div>';

          if(st.unavailable || st.blocked){
            h+='<div style="color:#b45309;font:400 11px/1.5 var(--font-m);margin-top:3px">'
              +'<i class="fa fa-info-circle"></i> '+esc(st.unavailable||st.blocked)+'</div>';
          }

          var facts=st.facts||{};
          var keys=Object.keys(facts);
          if(keys.length){
            h+='<table style="width:100%;border-collapse:collapse;font:400 11px/1.6 var(--font-m);margin-top:4px">';
            for(var j=0;j<keys.length;j++){
              var v=facts[keys[j]];
              h+='<tr><td style="color:var(--t2);padding-right:10px">'+esc(keys[j])+'</td>'
                +'<td style="text-align:right;color:var(--t1)">'
                +(typeof v==='number' ? esc(String(v)) : esc(String(v)))+'</td></tr>';
            }
            h+='</table>';
          }
          if(st.note){
            h+='<div style="color:var(--t3);font:400 10px/1.5 var(--font-m);margin-top:4px">'+esc(st.note)+'</div>';
          }
          h+='</div>';
        }

        if(r.statutory_snapshot_status){
          h+='<div style="border-top:1px solid var(--border);padding:8px 11px;color:var(--t3);font:400 10px/1.5 var(--font-m)">'
            +'<i class="fa fa-lock"></i> '+esc(r.statutory_snapshot_status)+'</div>';
        }
        h+='</div>';

        $out.html(h).data('loaded',true).show();
      })
      .catch(function(){
        $b.prop('disabled',false).html('<i class="fa fa-list-ol"></i> Why is this net pay?');
        $out.html('<span style="color:#b91c1c;font:400 11px/1.5 var(--font-m)">Network error — trace not loaded.</span>').show();
      });
  });

  /* ── W3.2.3 — loss-of-pay review ───────────────────────────────────────
     Every figure comes from the server, which read it from the FROZEN slip
     (the W3.2.1 attendance snapshot + leave_details). Nothing is recomputed
     here and nothing is read from live attendance — a review that disagreed
     with the payslip would be worse than no review. */
  function loadLopReview(runId){
    var $p = $('#lopReviewPanel');
    if(!runId){ $p.hide().empty(); return; }

    getJSON('hr/lop_review?run_id='+encodeURIComponent(runId)).then(function(r){
      if(!r || r.status!=='success' || !r.affected_count){ $p.hide().empty(); return; }

      // `run_status`, not `status`: the envelope's `status` is 'success'.
      var isDraft = (r.run_status||'').toLowerCase()==='draft';
      var conf = r.confirmation;
      var h='';

      h+='<div style="margin:0 0 14px;padding:14px;border-radius:8px;border:1px solid rgba(217,119,6,.28);background:rgba(217,119,6,.06)">';
      h+='<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">';
      h+='<div><div style="font:600 13px/1.4 var(--font-b);color:#b45309"><i class="fa fa-user-clock"></i> Loss of pay — review before finalizing</div>';
      h+='<div style="font:400 12px/1.6 var(--font-m);color:var(--t2);margin-top:4px">'
        +'<strong>'+r.affected_count+'</strong> employee(s) · <strong>'+r.total_lwp_days+'</strong> LOP day(s) · '
        +'total deduction <strong>'+fmt(r.total_deduction)+'</strong></div></div>';

      if(conf){
        h+='<div style="text-align:right;font:400 11px/1.5 var(--font-m);color:#0a7d33">'
          +'<i class="fa fa-check-circle"></i> Confirmed by '+esc(conf.confirmed_by||'')+'<br>'
          +'<span style="color:var(--t3)">'+esc(conf.confirmed_at||'')+'</span></div>';
      } else if(isDraft && HR_CAN_MANAGE){
        h+='<div><button id="btnConfirmLop" class="btn btn-warning btn-sm" data-run="'+esc(runId)+'">'
          +'<i class="fa fa-check"></i> Review &amp; confirm</button></div>';
      }
      h+='</div>';

      /* Per-employee: which days, full or half, from which leave request. */
      h+='<div style="margin-top:10px;max-height:260px;overflow:auto;border:1px solid var(--border);border-radius:6px;background:var(--bg1)">';
      h+='<table style="width:100%;border-collapse:collapse;font:400 11px/1.5 var(--font-m)">';
      for(var i=0;i<r.affected.length;i++){
        var e=r.affected[i];
        h+='<tr style="border-bottom:1px solid var(--border)"><td style="padding:8px 10px">';
        h+='<div><strong>'+esc(e.staff_name)+'</strong>'
          +(e.department?' <span style="color:var(--t3)">· '+esc(e.department)+'</span>':'')
          +' <span style="float:right;color:#b91c1c;font-weight:600">−'+fmt(e.lwp_deduction)+'</span></div>';

        var dayBits=[];
        if(e.lwp_full_days && e.lwp_full_days.length) dayBits.push('full: '+e.lwp_full_days.join(', '));
        if(e.lwp_half_days && e.lwp_half_days.length) dayBits.push('half: '+e.lwp_half_days.join(', '));
        if(dayBits.length){
          h+='<div style="color:var(--t2)">'+e.lwp_days+' LOP day(s) — '+esc(dayBits.join(' · '))
            +' <span style="color:var(--t3)">(at '+fmt(e.daily_salary)+'/day)</span></div>';
        } else if(!e.day_detail_available){
          /* Pre-W3.2.1 slip: say so rather than reconstruct a day list from
             today's attendance, which would be invented history. */
          h+='<div style="color:var(--t3)">'+e.lwp_days+' LOP day(s) — day-level detail not recorded for this run</div>';
        }

        for(var s=0;s<(e.sources||[]).length;s++){
          var src=e.sources[s];
          h+='<div style="color:var(--t3);padding-left:12px">↳ '+esc(src.type_name||src.type_code||'Leave')
            +' '+esc(src.from)+'→'+esc(src.to)
            +(src.half_day?' (half day)':'')
            +(src.request_id?' <span style="opacity:.7">['+esc(src.request_id)+']</span>':'')+'</div>';
        }
        if(e.balance_exhausted){
          h+='<div style="color:#b45309;padding-left:12px">⚠ A paid leave type produced LOP — the balance was exhausted when the leave was approved.</div>';
        }
        h+='</td></tr>';
      }
      h+='</table></div>';

      /* Server-supplied guidance. Deliberately not written in the browser: the
         correct fix for wrong LOP is in Attendance/Leave, never a payroll
         adjustment that would leave the two disagreeing permanently. */
      if(r.correction_guidance){
        h+='<div style="margin-top:8px;font:400 11px/1.6 var(--font-m);color:var(--t2)">'
          +'<i class="fa fa-info-circle"></i> '+esc(r.correction_guidance)+'</div>';
      }
      h+='</div>';

      $p.html(h).show();
    }).catch(function(){ $p.hide().empty(); });
  }

  $(document).on('click', '#btnConfirmLop', function(){
    var $b=$(this), runId=$b.data('run');
    var note = window.prompt('Optional note for the record (who approved these deductions, any context):', '');
    if(note===null) return;   /* cancelled */
    $b.prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Confirming');
    post('hr/confirm_lop', {run_id:runId, note:note}).then(function(r){
      $b.prop('disabled',false).html('<i class="fa fa-check"></i> Review &amp; confirm');
      if(!r || r.status!=='success'){ toast((r&&r.message)||'Could not confirm','error'); return; }
      toast(r.message||'Loss of pay confirmed','success');
      loadLopReview(runId);
    }).catch(function(){
      $b.prop('disabled',false).html('<i class="fa fa-check"></i> Review &amp; confirm');
      toast('Network error — not confirmed','error');
    });
  });

  /* W3.2.2 — populate the adjustment-type dropdowns from the server list.
     Called after slip rows render, since the selects live inside them. */
  function _fillAdjTypes(){
    if(!HR_CAN_MANAGE) return;
    function paint(){
      $('.adj-type').each(function(){
        if($(this).children().length) return;
        var opts='<option value="">Adjustment type…</option>';
        for(var i=0;i<HR_ADJ_TYPES.length;i++){
          var t=HR_ADJ_TYPES[i];
          opts+='<option value="'+esc(t.code)+'">'+esc(t.label)+' ('+(t.kind==='earning'?'+':'−')+')</option>';
        }
        $(this).html(opts);
      });
    }
    if(HR_ADJ_TYPES.length){ paint(); return; }
    getJSON('payroll_overrides/types').then(function(r){
      if(r && r.status==='success' && r.types){ HR_ADJ_TYPES=r.types; paint(); }
    });
  }

  /* W3.2.2 — save an adjustment. Stores only; the operator then regenerates
     the employee, which is what recalculates the payslip and posts the ledger
     delta through the existing certified path. */
  $(document).on('click', '.adj-save', function(e){
    e.stopPropagation();   /* the row itself toggles the drill-down */
    var $b=$(this), sid=$b.data('staff'), runId=$b.data('run');
    var $msg=$('.adj-msg[data-staff="'+sid+'"]');
    var type=$('.adj-type[data-staff="'+sid+'"]').val();
    var amount=$('.adj-amount[data-staff="'+sid+'"]').val();
    var reason=$('.adj-reason[data-staff="'+sid+'"]').val();

    function fail(m){
      $msg.html('<span style="color:#b91c1c;font:400 11px/1.5 var(--font-m)"><i class="fa fa-times-circle"></i> '+esc(m)+'</span>').show();
    }
    /* Mirrors the server rules so the operator gets an instant answer. The
       server re-validates everything regardless — this is not the guard. */
    if(!type)   return fail('Choose an adjustment type.');
    if(!amount || parseFloat(amount)<=0) return fail('Amount must be greater than zero.');
    if(!reason || reason.length<10)      return fail('A reason of at least 10 characters is required.');

    $b.prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Saving');
    post('payroll_overrides/create', {run_id:runId, staff_id:sid, type:type, amount:amount, reason:reason})
      .then(function(r){
        $b.prop('disabled',false).html('<i class="fa fa-check"></i> Save');
        /* Phantom-success guard: fetch() does not reject on 403/500. */
        if(!r || r.status!=='success'){ fail((r&&r.message)||'Could not save the adjustment.'); return; }
        $msg.html('<span style="color:#0a7d33;font:400 11px/1.5 var(--font-m)"><i class="fa fa-check-circle"></i> '
          +esc(r.message||'Saved.')+' Click <b>Regenerate</b> on this employee to apply it.</span>').show();
        $('.adj-amount[data-staff="'+sid+'"]').val('');
        $('.adj-reason[data-staff="'+sid+'"]').val('');
      })
      .catch(function(){
        $b.prop('disabled',false).html('<i class="fa fa-check"></i> Save');
        fail('Network error — the adjustment was not saved.');
      });
  });

  /* Keep clicks inside the form from toggling the row open/closed. */
  $(document).on('click', '.hr-adjust-form', function(e){ e.stopPropagation(); });

  function loadPayrollRuns(){
    getJSON('hr/get_payroll_runs').then(function(r){
      var runs=(r&&r.payroll_runs)?r.payroll_runs:(r&&r.data)?r.data:{};
      _payrollRunCache = runs;
      var $tb=$('#tblPayrollRuns tbody');
      var keys=Object.keys(runs);
      if(!keys.length){ $tb.html('<tr><td colspan="11" class="hr-empty"><i class="fa fa-inbox"></i> No payroll runs yet.</td></tr>'); return; }
      var h='', i=0;
      $.each(runs, function(k,pr){
        i++;
        var rid = pr.id || k;
        var st=(pr.status||'draft').toLowerCase();
        // Payment info column
        var payInfo='-';
        if(st==='paid'){
          payInfo='<div style="font-size:11px;line-height:1.5">';
          payInfo+='<strong>'+esc(pr.payment_mode||'Bank')+'</strong>';
          if(pr.payment_date) payInfo+='<br>'+esc(pr.payment_date);
          if(pr.payment_reference) payInfo+='<br><span style="opacity:.6">Ref: '+esc(pr.payment_reference)+'</span>';
          if(pr.paid_by) payInfo+='<br><span style="opacity:.6">By: '+esc(pr.paid_by)+'</span>';
          payInfo+='</div>';
        }
        // UX-3: stale runs get a yellow stripe + pill in status column
        var rowClass = pr.stale ? ' class="hr-stale-row"' : '';
        h+='<tr'+rowClass+'><td class="hr-num">'+i+'</td><td class="hr-num" style="font-size:11px">'+esc(rid)+'</td>';
        h+='<td>'+(pr.month||'')+'</td><td class="hr-num">'+(pr.year||'')+'</td>';
        h+='<td class="hr-num">'+(pr.staff_count||0)+'</td>';
        h+='<td class="hr-num">'+fmt(pr.total_gross)+'</td>';
        h+='<td class="hr-num" style="color:#d97706">'+fmt(pr.total_deductions)+'</td>';
        h+='<td class="hr-num"><strong style="color:var(--gold)">'+fmt(pr.total_net)+'</strong></td>';
        h+='<td>'+payrollBadge(pr.status);
        if(pr.stale) h+='<span class="hr-stale-pill" title="'+esc(pr.stale_reason||'Corrections approved after this run was generated')+'"><i class="fa fa-exclamation-triangle"></i> Stale</span>';
        h+='</td>';
        h+='<td>'+payInfo+'</td>';
        h+='<td style="white-space:nowrap">';
        h+='<button class="hr-act-btn" onclick="HR.viewPayslips(\''+esc(rid)+'\')" title="View Payslips"><i class="fa fa-file-text-o"></i></button> ';
        if(st==='draft') h+='<button class="hr-act-btn" onclick="HR.finalizeRun(\''+esc(rid)+'\')" title="Finalize"><i class="fa fa-lock"></i></button> ';
        if(st==='finalized') h+='<button class="hr-act-btn" style="background:#fef3c7;color:#92400e" onclick="HR.approvePayroll(\''+esc(rid)+'\')" title="Approve"><i class="fa fa-check-circle"></i></button> ';
        if(st==='finalized') h+='<button class="hr-act-btn" style="background:#ede9fe;color:#6d28d9" onclick="HR.unlockRun(\''+esc(rid)+'\')" title="Unlock (requires reason)"><i class="fa fa-unlock-alt"></i></button> ';
        if(st==='finalized'||st==='approved') h+='<button class="hr-act-btn" style="background:#d1fae5;color:#065f46" onclick="HR.openPaymentModal(\''+esc(rid)+'\')" title="Mark as Paid"><i class="fa fa-credit-card"></i></button> ';
        if(st==='partially paid') h+='<button class="hr-act-btn" style="background:#d1fae5;color:#065f46" onclick="HR.openPaymentModal(\''+esc(rid)+'\')" title="Record Payment"><i class="fa fa-credit-card"></i></button> ';
        // Export buttons
        if(st!=='draft') h+='<button class="hr-act-btn" onclick="HR.exportPayroll(\'salary_register\',\''+esc(rid)+'\')" title="Export Salary Register"><i class="fa fa-file-excel-o"></i></button> ';
        if(st==='draft') h+='<button class="hr-act-btn danger" onclick="HR.deletePayrollRun(\''+esc(rid)+'\')" title="Delete Run"><i class="fa fa-trash"></i></button> ';
        h+='</td></tr>';
      });
      $tb.html(h);
    });
  }

  var _payrollRunCache={};

  function payrollBadge(status){
    var s=(status||'Draft').toLowerCase();
    var icons={draft:'fa-pencil',finalized:'fa-lock',approved:'fa-check-circle-o','partially paid':'fa-adjust',paid:'fa-check-circle'};
    var ico=icons[s]||'fa-circle-o';
    return '<span class="hr-badge hr-badge-'+s.replace(/\s+/g,'-')+'"><i class="fa '+ico+'" style="margin-right:4px"></i>'+esc(status||'Draft')+'</span>';
  }

  function openGeneratePayroll(){
    $('#genMonth').val(['January','February','March','April','May','June','July','August','September','October','November','December'][new Date().getMonth()]);
    $('#genYear').val(new Date().getFullYear());
    $('#genPreflightResult').hide().empty();
    // Reset button to initial state — always rebind to preflight flow
    $('#btnRunPayroll').prop('disabled',false).html('<i class="fa fa-play"></i> Generate').off('click').on('click', function(){ HR.generatePayroll(); });
    openModal('modalGenPayroll');
  }

  /* ── Build missing-accounts error UI ── */
  function _renderMissingAccounts(missing){
    var h='<div style="margin:14px 0;padding:14px;border-radius:8px;background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.18)">';
    h+='<div style="font:600 13px/1.4 var(--font-b);color:#b91c1c;margin-bottom:8px"><i class="fa fa-exclamation-triangle"></i> Payroll Cannot Be Generated</div>';
    h+='<div style="font:400 12px/1.5 var(--font-m);color:var(--t2);margin-bottom:10px">The following accounting accounts are missing or inactive:</div>';
    h+='<table style="width:100%;font:400 12px/1.6 var(--font-m);border-collapse:collapse;margin-bottom:12px">';
    h+='<thead><tr style="border-bottom:1px solid var(--border);text-align:left"><th style="padding:4px 8px;font-weight:600">Code</th><th style="padding:4px 8px;font-weight:600">Account Name</th><th style="padding:4px 8px;font-weight:600">Type</th></tr></thead><tbody>';
    for(var i=0;i<missing.length;i++){
      var a=missing[i];
      var catColor = a.category==='Expense'?'#d97706':a.category==='Liability'?'#7c3aed':'var(--t2)';
      h+='<tr style="border-bottom:1px solid var(--border)">';
      h+='<td style="padding:4px 8px;font-family:var(--font-m);font-weight:600">'+esc(a.code)+'</td>';
      h+='<td style="padding:4px 8px">'+esc(a.name)+'</td>';
      h+='<td style="padding:4px 8px"><span style="font-size:10px;padding:2px 6px;border-radius:4px;background:'+catColor+'1a;color:'+catColor+';font-weight:600">'+esc(a.category)+'</span></td>';
      h+='</tr>';
    }
    h+='</tbody></table>';
    h+='<div style="display:flex;gap:8px;flex-wrap:wrap">';
    h+='<button class="hr-btn hr-btn-primary hr-btn-sm" onclick="HR.autoCreateAccounts()" id="btnAutoCreate"><i class="fa fa-magic"></i> Auto-Create Missing Accounts</button>';
    h+='<a class="hr-btn hr-btn-ghost hr-btn-sm" href="<?= base_url("accounting") ?>" target="_blank"><i class="fa fa-external-link"></i> Open Accounting Setup</a>';
    h+='</div></div>';
    return h;
  }

  /* ── Build preflight success/warnings UI ── */
  function _renderPreflightOk(r){
    var h='<div style="margin:14px 0;padding:14px;border-radius:8px;background:rgba(188,90,60,.07);border:1px solid rgba(188,90,60,.18)">';
    // UAT finding: the header read "Pre-flight Check Passed" in green even when
    // the response carried blockers — six employees who cannot be paid, under a
    // green tick. A "blocker" that reports success is a warning wearing the
    // wrong word, and an operator who trusts the header will not read the list.
    //
    // The BEHAVIOUR is deliberately unchanged: missing bank details stop
    // PAYMENT, not calculation, so generation is still allowed. Only the claim
    // is corrected, so the header states what the payload actually says.
    var _bc = parseInt(r.blocker_count || 0, 10);
    if(_bc > 0){
      h+='<div style="font:600 13px/1.4 var(--font-b);color:#b45309;margin-bottom:6px">'
        +'<i class="fa fa-exclamation-triangle"></i> Pre-flight completed — '+_bc+' employee issue(s) need attention</div>';
    } else {
      h+='<div style="font:600 13px/1.4 var(--font-b);color:#BC5A3C;margin-bottom:6px"><i class="fa fa-check-circle"></i> Pre-flight Check Passed</div>';
    }
    h+='<div style="font:400 12px/1.5 var(--font-m);color:var(--t2)">Staff covered: <strong>'+(r.staff_covered||0)+'</strong> / '+(r.staff_total||0)+'</div>';
    var w=r.warnings||[];
    if(w.length){
      h+='<div style="margin-top:8px;padding-top:8px;border-top:1px dashed var(--border)">';
      h+='<div style="font:600 11px/1.3 var(--font-b);color:#d97706;margin-bottom:4px"><i class="fa fa-exclamation-circle"></i> Warnings</div>';
      h+='<ul style="margin:0;padding-left:18px;font:400 11px/1.6 var(--font-m);color:var(--t2)">';
      for(var i=0;i<w.length;i++) h+='<li>'+esc(w[i])+'</li>';
      h+='</ul></div>';
    }

    // W3.1 — the named, per-employee view of the same facts.
    //
    // The prose warnings above say "3 staff member(s) will be skipped", which
    // tells an operator a number but not who to go fix. This table names the
    // people and states the resolution for each, so the run can be corrected
    // BEFORE it is generated rather than after.
    var b=r.blockers||[];
    if(b.length){
      h+='<div style="margin-top:10px;padding-top:10px;border-top:1px dashed var(--border)">';
      h+='<div style="font:600 11px/1.3 var(--font-b);color:var(--t1);margin-bottom:6px">';
      h+='<i class="fa fa-users"></i> Affected employees ';
      if(r.blocker_count) h+='<span style="color:#b91c1c">&middot; '+r.blocker_count+' blocked</span> ';
      if(r.warning_count) h+='<span style="color:#d97706">&middot; '+r.warning_count+' warning</span>';
      h+='</div>';
      // Taller now the modal is wide: 13 rows in a 220px box meant scrolling
      // through the very list the operator is meant to act on.
      h+='<div style="max-height:46vh;overflow:auto;border:1px solid var(--border);border-radius:6px">';
      h+='<table style="width:100%;border-collapse:collapse;font:400 11px/1.5 var(--font-m)">';
      for(var j=0;j<b.length;j++){
        var row=b[j], isBlock=(row.severity==='blocker');
        var accent=isBlock?'#b91c1c':'#d97706';
        h+='<tr style="border-bottom:1px solid var(--border)">';
        h+='<td style="padding:7px 9px;border-left:3px solid '+accent+'">';
        h+='<div style="font-weight:600;color:var(--t1)">'+esc(row.staff_name||row.staff_id||'')+
           (row.department?' <span style="font-weight:400;color:var(--t3)">&middot; '+esc(row.department)+'</span>':'')+'</div>';
        h+='<div style="color:'+accent+'">'+esc(row.reason||'')+'</div>';
        if(row.resolution) h+='<div style="color:var(--t3)">&rarr; '+esc(row.resolution)+'</div>';
        h+='</td></tr>';
      }
      h+='</table></div></div>';
    }

    h+='</div>';
    return h;
  }

  function generatePayroll(){
    var m=$('#genMonth').val(), y=$('#genYear').val();
    if(!m||!y){ toast('Month and year are required','error'); return; }

    var $btn=$('#btnRunPayroll'), $result=$('#genPreflightResult');
    $btn.prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Checking...');
    $result.hide().empty();

    getJSON('hr/preflight_payroll?month='+encodeURIComponent(m)+'&year='+encodeURIComponent(y)).then(function(r){
      if(!r){ $btn.prop('disabled',false).html('<i class="fa fa-play"></i> Generate'); toast('Pre-flight check failed','error'); return; }

      // ── Missing accounts → show structured UI ──
      if(r.error_type==='missing_accounts' || (r.status==='error' && r.missing_accounts)){
        $result.html(_renderMissingAccounts(r.missing_accounts)).slideDown(200);
        $btn.prop('disabled',true).html('<i class="fa fa-ban"></i> Blocked');
        return;
      }

      // ── Other errors ──
      if(r.status==='error'){
        $result.html('<div style="margin:14px 0;padding:12px;border-radius:8px;background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.18);font:400 12px/1.5 var(--font-m);color:#b91c1c"><i class="fa fa-times-circle"></i> '+esc(r.message)+'</div>').slideDown(200);
        $btn.prop('disabled',false).html('<i class="fa fa-play"></i> Generate');
        return;
      }

      // ── Success — show preflight summary, then confirm & generate ──
      $result.html(_renderPreflightOk(r)).slideDown(200);
      $btn.prop('disabled',false).html('<i class="fa fa-play"></i> Confirm & Generate');

      // Rebind button for the actual generation
      $btn.off('click').one('click', function(){
        $btn.prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Generating...');
        post('hr/generate_payroll', {month:m, year:y}).then(function(r2){
          if(r2&&r2.status==='success'){
            // UX-5: surface warnings inline before closing the modal so
            // admin can SEE who got skipped instead of silently scrolling
            // past a brief toast.
            var w = (r2.warnings && r2.warnings.length) ? r2.warnings : [];
            if (w.length){
              var panel = '<div class="hr-warnings-panel"><h5><i class="fa fa-exclamation-triangle"></i> Payroll generated with '+w.length+' warning(s)</h5><ul>';
              for (var i=0;i<w.length;i++){
                panel += '<li>'+esc(w[i])+'</li>';
              }
              panel += '</ul><div style="margin-top:10px;font-size:11px;color:var(--t3)">Affected staff did not receive a slip. Backfill attendance or change <code>vacant_treatment</code> to override, then regenerate the run.</div></div>';
              $result.html(panel).slideDown(200);
              $btn.prop('disabled',false).html('<i class="fa fa-check"></i> OK, Close').off('click').on('click', function(){
                closeModal('modalGenPayroll');
                loadPayrollRuns();
              });
              toast('Payroll generated — '+w.length+' warning(s) — review the modal','info');
            } else {
              toast(r2.message||'Payroll generated successfully','success');
              closeModal('modalGenPayroll');
              loadPayrollRuns();
            }
          } else if(r2 && r2.error_type==='missing_accounts'){
            $result.html(_renderMissingAccounts(r2.missing_accounts||[])).slideDown(200);
            $btn.prop('disabled',true).html('<i class="fa fa-ban"></i> Blocked');
          } else {
            toast(r2&&r2.message||'Generation failed','error');
            $btn.prop('disabled',false).html('<i class="fa fa-play"></i> Retry');
            $btn.off('click').on('click', function(){ HR.generatePayroll(); });
          }
        });
      });
    }).fail(function(){
      $btn.prop('disabled',false).html('<i class="fa fa-play"></i> Generate').off('click').on('click', function(){ HR.generatePayroll(); });
      toast('Pre-flight check failed. Please try again.','error');
    });
  }

  /* ── Auto-create missing payroll accounts ── */
  function autoCreateAccounts(){
    var $btn=$('#btnAutoCreate');
    $btn.prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Creating...');
    post('hr/auto_create_payroll_accounts', {}).then(function(r){
      if(r&&r.status==='success'){
        toast(r.message||'Accounts created','success');
        // Re-run preflight to refresh the UI
        $('#genPreflightResult').hide().empty();
        $('#btnRunPayroll').prop('disabled',false).html('<i class="fa fa-play"></i> Generate').off('click').on('click', function(){ HR.generatePayroll(); });
        HR.generatePayroll();
      } else {
        toast(r.message||'Failed to create accounts','error');
        $btn.prop('disabled',false).html('<i class="fa fa-magic"></i> Retry');
      }
    }).fail(function(){
      toast('Failed to create accounts','error');
      $btn.prop('disabled',false).html('<i class="fa fa-magic"></i> Retry');
    });
  }

  async function finalizeRun(id){
    if(window._finalizeInFlight) return;
    if(!await zxConfirm({ title: 'Finalize this payroll run? Draft payslips will be locked.' })) return;
    window._finalizeInFlight = true;
    toast('Finalizing payroll… please wait','info');
    post('hr/finalize_payroll', {run_id:id}).then(function(r){
      if(r&&r.status==='success'){ toast(r.message||'Finalized','success'); }
      else toast((r&&r.message)||'Failed to finalize','error');
    }).fail(function(){
      toast('Request failed — refreshing to show current status','error');
    }).always(function(){
      window._finalizeInFlight = false;
      loadPayrollRuns();
    });
  }

  /* ── Reason-prompt modal (shared by Unlock + Regenerate) ──
     opts: { title, subtitle, confirmLabel, confirmIcon, titleIcon, onConfirm } */
  /* ── Payroll period locks (PAY-1) ──────────────────────────────────────
     Read + close + re-open. The lock itself is enforced server-side by
     _check_payroll_lock() on generate / regenerate / delete; this surface only
     lets an administrator see and operate it. */
  var payrollLocksBusy = false;

  function loadPayrollLocks(){
    var $b = $('#payrollLocksBody');
    if (!$b.length) return;

    post('hr/payroll_lock_status', {}).then(function(r){
      if (!r || r.status !== 'success'){
        $b.html('<div class="hr-empty" style="padding:22px;text-align:center;">'
              + '<i class="fa fa-exclamation-triangle"></i> '
              + esc((r && r.message) || 'Could not read payroll periods.') + '</div>');
        return;
      }
      renderPayrollLocks(r.locks || [], r.canManage === true);
    }).fail(function(){
      $b.html('<div class="hr-empty" style="padding:22px;text-align:center;">'
            + '<i class="fa fa-exclamation-triangle"></i> Could not read payroll periods.</div>');
    });
  }

  function renderPayrollLocks(locks, canManage){
    var $b = $('#payrollLocksBody');

    // Only months that have ever been locked carry a record. A month with no
    // record is simply open — saying "no periods have been closed" is clearer
    // than listing twelve rows of "open".
    var closed = locks.filter(function(l){ return l.locked; });

    var html = '<div class="hr-table-wrap"><table class="hr-table"><thead><tr>'
             + '<th>Period</th><th>State</th><th>Closed by</th><th>Last change</th><th></th>'
             + '</tr></thead><tbody>';

    if (!locks.length){
      html += '<tr><td colspan="5" class="hr-empty">No payroll period has been closed yet. '
            + 'Close a month from a finalized payroll run to freeze it.</td></tr>';
    } else {
      locks.forEach(function(l){
        var when = l.locked ? (l.lockedAt || '') : (l.unlockedAt || l.lockedAt || '');
        html += '<tr>'
          + '<td><b>' + esc(l.month) + ' ' + esc(l.year) + '</b></td>'
          + '<td>' + (l.locked
              ? '<span class="hr-badge hr-badge-draft" style="background:rgba(220,38,38,.12);color:#dc2626;">'
                + '<i class="fa fa-lock"></i> Closed</span>'
              : '<span class="hr-badge hr-badge-draft" style="background:rgba(22,163,74,.12);color:#16a34a;">'
                + '<i class="fa fa-unlock"></i> Open</span>')
          + '</td>'
          + '<td>' + esc(l.locked ? (l.lockedBy || '—') : (l.unlockedBy || '—')) + '</td>'
          + '<td style="color:var(--t3);">' + esc(when ? when.substring(0, 10) : '—') + '</td>'
          + '<td style="text-align:right;">'
          + (canManage && l.locked
              ? '<button class="hr-btn hr-btn-sm" data-reopen-month="' + esc(l.month)
                + '" data-reopen-year="' + esc(l.year) + '">'
                + '<i class="fa fa-unlock-alt"></i> Re-open</button>'
              : '')
          + '</td></tr>';

        if (!l.locked && l.unlockReason){
          html += '<tr><td colspan="5" style="font-size:12px;color:var(--t3);padding-top:0;">'
                + '<i class="fa fa-info-circle"></i> Re-opened: ' + esc(l.unlockReason) + '</td></tr>';
        }
      });
    }

    html += '</tbody></table></div>';

    // The close control is a manage action; the endpoints enforce it again.
    $('#payrollCloseRow').toggle(canManage === true);
    if (canManage && !$('#lockYearSel').val()) $('#lockYearSel').val(new Date().getFullYear());

    if (!canManage){
      html += '<div style="font-size:12px;color:var(--t3);margin-top:8px;">'
            + '<i class="fa fa-lock"></i> View-only — closing or re-opening a period needs HR manage access.</div>';
    }

    $b.html(html);
  }

  /* Re-open a closed period. Uses the panel's own reason dialog — the same one
     unlock_payroll() uses for a single run, with the same 10-character bar. */
  function reopenPayrollMonth(month, year){
    if (payrollLocksBusy) return;

    promptReason({
      title:        'Re-open Payroll Period',
      titleIcon:    'fa-unlock-alt',
      subtitle:
        '<div class="hr-confirm-warning amber">'
        + '<b><i class="fa fa-exclamation-triangle"></i> You are re-opening a closed payroll period.</b>'
        + '<ul>'
        +   '<li><b>' + esc(month) + ' ' + esc(year) + '</b> becomes generatable, regeneratable and deletable again.</li>'
        +   '<li>Payslips, run statuses and the accounting books are <b>not</b> changed by this action.</li>'
        +   '<li>Attendance for the month is governed separately — re-opening payroll does not re-open the attendance register.</li>'
        +   '<li class="muted">Reason is audit-logged with your admin id and timestamp.</li>'
        + '</ul>'
        + '</div>',
      confirmLabel: 'Yes, Re-open Period',
      confirmIcon:  'fa-unlock-alt',
      onConfirm: function(reason){
        return new Promise(function(resolve, reject){
          payrollLocksBusy = true;
          post('hr/unlock_payroll_month', {month: month, year: year, reason: reason}).then(function(r){
            payrollLocksBusy = false;
            if (r && r.status === 'success'){
              toast(r.message || 'Payroll period re-opened', 'success');
              loadPayrollLocks();
              if (typeof loadPayrollRuns === 'function') loadPayrollRuns();
              resolve();
            } else {
              toast((r && r.message) || 'Failed to re-open the period', 'error');
              reject(r);
            }
          }).fail(function(){
            payrollLocksBusy = false;
            toast('Network error', 'error');
            reject();
          });
        });
      }
    });
  }

  /* Close a period. Confirmed rather than reason-gated: closing is the ordinary
     month-end step and is reversible through the re-open above, which IS
     reason-gated. Mirrors how the attendance month lock treats the two acts. */
  async function closePayrollMonth(month, year){
    if (payrollLocksBusy) return;
    if (!await zxConfirm({
      title: 'Close payroll for ' + month + ' ' + year + '?',
      message: 'Payroll for this period can no longer be generated, regenerated or deleted '
             + 'until it is re-opened. Do this once the month is settled and paid.',
      confirmText: 'Close payroll', danger: true
    })) return;

    payrollLocksBusy = true;
    post('hr/lock_payroll_month', {month: month, year: year}).then(function(r){
      payrollLocksBusy = false;
      if (r && r.status === 'success'){
        toast(r.message || 'Payroll period closed', 'success');
        loadPayrollLocks();
        if (typeof loadPayrollRuns === 'function') loadPayrollRuns();
      } else {
        toast((r && r.message) || 'Failed to close the period', 'error');
      }
    }).fail(function(){
      payrollLocksBusy = false;
      toast('Network error', 'error');
    });
  }

  $(document).on('click', '#btnClosePayrollPeriod', function(){
    var m = $('#lockMonthSel').val();
    var y = String($('#lockYearSel').val() || '').trim();
    if (!/^\d{4}$/.test(y)) { toast('Enter a valid 4-digit year', 'error'); return; }
    closePayrollMonth(m, y);
  });
  $(document).on('click', '[data-reopen-month]', function(){
    reopenPayrollMonth($(this).data('reopen-month'), String($(this).data('reopen-year')));
  });
  $(document).on('click', '[data-close-month]', function(){
    closePayrollMonth($(this).data('close-month'), String($(this).data('close-year')));
  });
  function promptReason(opts){
    var titleIcon = opts.titleIcon || 'fa-comment';
    $('#reasonPromptTitle').html('<i class="fa '+titleIcon+'"></i> '+esc(opts.title||'Reason Required'));
    $('#reasonPromptSubtitle').html(opts.subtitle || '');
    $('#reasonPromptText').val('');
    $('#reasonPromptHint').text('0 chars · need 10+').css('color','var(--t3)');
    var $btn=$('#btnReasonPromptConfirm');
    $btn.html('<i class="fa '+(opts.confirmIcon||'fa-check')+'"></i> '+esc(opts.confirmLabel||'Confirm')).prop('disabled', true);

    $('#reasonPromptText').off('input').on('input', function(){
      var n = (this.value||'').trim().length;
      $('#reasonPromptHint').text(n + ' chars · need 10+').css('color', n>=10 ? '#16a34a' : 'var(--t3)');
      $btn.prop('disabled', n < 10);
    });

    $btn.off('click').on('click', function(){
      var reason = ($('#reasonPromptText').val()||'').trim();
      if (reason.length < 10) return;
      $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Working…');
      Promise.resolve(opts.onConfirm(reason))
        .then(function(){ closeModal('modalReasonPrompt'); })
        .catch(function(){
          // Keep modal open so user can adjust reason / retry
          $btn.prop('disabled', false).html('<i class="fa '+(opts.confirmIcon||'fa-check')+'"></i> '+esc(opts.confirmLabel||'Confirm'));
        });
    });

    openModal('modalReasonPrompt');
    setTimeout(function(){ $('#reasonPromptText').focus(); }, 100);
  }

  function unlockRun(runId){
    promptReason({
      title:        'Unlock Payroll Run',
      titleIcon:    'fa-unlock-alt',
      subtitle:
        '<div class="hr-confirm-warning amber">'
        + '<b><i class="fa fa-exclamation-triangle"></i> You are unlocking a finalized payroll.</b>'
        + 'Changes will affect salary calculations.'
        + '<ul>'
        +   '<li>Run <b>'+esc(runId)+'</b> will move from <b>Finalized/Approved</b> back to <b>Draft</b>.</li>'
        +   '<li>All slips become editable. Admin can regenerate individual slips.</li>'
        +   '<li>Accounting books are <b>not</b> reversed by this action — re-finalize after edits to keep them aligned.</li>'
        +   '<li class="muted">Reason is audit-logged with your admin id and timestamp.</li>'
        + '</ul>'
        + '</div>',
      confirmLabel: 'Yes, Unlock Run',
      confirmIcon:  'fa-unlock-alt',
      onConfirm: function(reason){
        return new Promise(function(resolve, reject){
          post('hr/unlock_payroll', {run_id:runId, reason:reason}).then(function(r){
            if (r && r.status === 'success') {
              toast(r.message || 'Run unlocked','success');
              loadPayrollRuns();
              resolve();
            } else {
              toast((r && r.message) || 'Failed to unlock','error');
              reject(r);
            }
          }).fail(function(){ toast('Network error','error'); reject(); });
        });
      }
    });
  }

  function regenerateSlip(runId, staffId, staffName){
    promptReason({
      title:        'Regenerate Salary Slip',
      titleIcon:    'fa-refresh',
      subtitle:
        '<div class="hr-confirm-warning amber">'
        + '<b><i class="fa fa-refresh"></i> This will recalculate salary.</b>'
        + 'Previous values will be archived (audit-safe).'
        + '<ul>'
        +   '<li>Recomputing slip for <b>'+esc(staffName||staffId)+'</b> from the latest attendance + approved leaves.</li>'
        +   '<li>The existing slip is archived (not deleted) — old net pay remains queryable.</li>'
        +   '<li>An adjustment journal entry will be posted automatically to keep accounting books aligned.</li>'
        +   '<li class="muted">Reason is audit-logged with your admin id and timestamp.</li>'
        + '</ul>'
        + '</div>',
      confirmLabel: 'Yes, Regenerate',
      confirmIcon:  'fa-refresh',
      onConfirm: function(reason){
        return new Promise(function(resolve, reject){
          post('hr/regenerate_staff_payroll', {run_id:runId, staff_id:staffId, reason:reason}).then(function(r){
            if (r && r.status === 'success') {
              var diff = parseFloat(r.difference || 0);
              var direction = diff > 0 ? 'increased' : (diff < 0 ? 'decreased' : 'unchanged');
              var amt = fmt(Math.abs(diff));
              var msg = 'Slip regenerated. Net pay '+direction+(diff===0?'':' by '+amt);
              // UX-2: surface server warnings (M-2 inactive staff, etc.)
              if (r.warnings && r.warnings.length) {
                msg += ' · ' + r.warnings.length + ' warning(s) — see slip detail';
              }
              toast(msg, 'success');
              viewPayslips(runId);
              resolve();
            } else {
              toast((r && r.message) || 'Failed to regenerate','error');
              reject(r);
            }
          }).fail(function(){ toast('Network error','error'); reject(); });
        });
      }
    });
  }

  async function deletePayrollRun(id){
    if(!await zxConfirm({ title: 'Delete this draft payroll run and all its payslips? This cannot be undone.' })) return;
    post('hr/delete_payroll_run', {run_id:id}).then(function(r){
      if(r&&r.status==='success'){ toast(r.message||'Payroll run deleted','success'); loadPayrollRuns(); }
      else toast(r.message||'Failed to delete','error');
    });
  }

  function openPaymentModal(runId){
    $('#payRunId').val(runId);
    $('#payDate').val(new Date().toISOString().slice(0,10));
    $('#payMode').val('Bank Transfer');
    $('#payReference').val('');

    // Show run summary in modal
    var pr=null;
    if(Array.isArray(_payrollRunCache)){
      for(var i=0;i<_payrollRunCache.length;i++){
        if((_payrollRunCache[i].id||'')=== runId){ pr=_payrollRunCache[i]; break; }
      }
    } else if(_payrollRunCache){
      $.each(_payrollRunCache, function(k,v){ if((v.id||k)===runId){ pr=v; return false; } });
    }
    var summary='';
    if(pr){
      summary='<strong>'+(pr.month||'')+' '+(pr.year||'')+'</strong>';
      summary+=' &bull; '+esc(pr.staff_count||0)+' staff';
      summary+=' &bull; Net: <strong style="color:var(--gold)">'+fmt(pr.total_net)+'</strong>';
    } else {
      summary='Run: '+esc(runId);
    }
    $('#payRunSummary').html(summary);
    $('#btnConfirmPay').prop('disabled',false).html('<i class="fa fa-check-circle"></i> Confirm Payment');
    openModal('modalPayment');
  }

  function confirmPayment(){
    var runId=$('#payRunId').val();
    var date=$('#payDate').val();
    var mode=$('#payMode').val();
    var ref=$('#payReference').val().trim();
    if(!date){ toast('Payment date is required','error'); return; }
    var $btn=$('#btnConfirmPay');
    $btn.prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Processing...');
    post('hr/mark_payroll_paid', {
      run_id: runId,
      payment_mode: mode,
      payment_date: date,
      payment_reference: ref
    }).then(function(r){
      if(r&&r.status==='success'){
        toast(r.message||'Marked as paid','success');
      } else {
        toast((r&&r.message)||'Payment may not have completed — check list','error');
      }
    }).fail(function(){
      // Server may have succeeded despite the client failure (e.g. timeout on
      // a long-running request). Close modal and reload the list so UI reflects
      // whatever the actual server state is, rather than leaving a stuck modal.
      toast('Request failed — refreshing to show current status','error');
    }).always(function(){
      closeModal('modalPayment');
      $btn.prop('disabled',false).html('<i class="fa fa-check-circle"></i> Confirm Payment');
      loadPayrollRuns();
    });
  }

  // Legacy alias
  function markPaid(id){ openPaymentModal(id); }

  function viewPayslips(runId){
    currentRunId = runId;
    // Switch to payslips sub-tab
    var $panel=$('#panelPayroll');
    $panel.find('.hr-sub-tab').removeClass('active').filter('[data-sub=payslips]').addClass('active');
    $panel.find('.hr-sub-panel').removeClass('active');
    $('#subPayslips').addClass('active');

    $('#payslipRunInfo').text('Run: '+runId).show();
    $('#payslipSelectMsg').hide();
    $('#payslipTableWrap').show();

    getJSON('hr/get_payroll_slips?run_id='+encodeURIComponent(runId)).then(function(r){
      var slips=(r&&r.slips)?r.slips:(r&&r.data)?r.data:{};
      var $tb=$('#tblPayslips tbody');
      var keys=Object.keys(slips);
      // Run-level info for payslip header
      var runData = r.run||{};
      var runStatus = (runData.status||'Draft').toLowerCase();
      var runHeaderHtml = 'Run: '+esc(runData.id||runId)+' '+payrollBadge(runData.status||'Draft');
      // UX-3: surface stale status next to the run header
      if(runData.stale){
        runHeaderHtml += '<span class="hr-stale-pill" title="'+esc(runData.stale_reason||'')+'"><i class="fa fa-exclamation-triangle"></i> Stale</span>';
      }
      $('#payslipRunInfo').html(runHeaderHtml).show();

      // UX-3: prominent banner above the slip table when stale
      var $banner = $('#staleBanner');
      if($banner.length === 0){
        $banner = $('<div id="staleBanner" class="hr-stale-banner" style="display:none"></div>').insertBefore('#payslipTableWrap');
      }
      if(runData.stale){
        var actionHtml = (runStatus==='draft')
          ? ' Open any slip\'s row and click <b>Regenerate</b> to refresh, OR delete and re-create the run.'
          : ' <b>Unlock</b> the run from the Payroll Runs tab, then regenerate affected slips.';
        $banner.html('<i class="fa fa-exclamation-triangle"></i><div><b>This payroll run may be stale.</b><br>'
          +esc(runData.stale_reason||'Attendance corrections were approved after this run was generated.')
          +actionHtml+'</div>').show();
      } else {
        $banner.hide();
      }

      if(!keys.length){ $tb.html('<tr><td colspan="14" class="hr-empty"><i class="fa fa-inbox"></i> No payslips in this run.</td></tr>'); return; }
      var h='', i=0;
      $.each(slips, function(k,p){
        i++;
        var slipStatus = (p.status||'Draft').toLowerCase();
        var allowances=(parseFloat(p.hra)||0)+(parseFloat(p.da)||0)+(parseFloat(p.ta)||0)+(parseFloat(p.medical)||0)+(parseFloat(p.other_allowances)||0);
        h+='<tr class="hr-payslip-row" data-id="'+esc(k)+'" style="cursor:pointer">';
        h+='<td class="hr-num">'+i+'</td><td>'+esc(p.staff_name||staffName(p.staff_id||k))+'</td>';
        h+='<td class="hr-num">'+fmt(p.basic)+'</td><td class="hr-num">'+fmt(allowances)+'</td>';
        // W3.2.2 — payroll-period adjustments. Read from the slip's own
        // `_payroll_adjustments` block, which the engine wrote; the browser does
        // no payroll arithmetic of its own.
        var adjBlock = p._payroll_adjustments || null;
        if(adjBlock){
          var adjNet = (parseFloat(adjBlock.final_gross)||0) - (parseFloat(adjBlock.original_gross)||0);
          var adjColor = adjNet >= 0 ? 'var(--gold)' : '#b91c1c';
          h+='<td class="hr-num" title="'+esc(adjBlock.count+' adjustment(s) — open the row for the breakdown')+'">'
            +'<strong style="color:'+adjColor+'">'+(adjNet>=0?'+':'−')+fmt(Math.abs(adjNet))+'</strong></td>';
        } else {
          h+='<td class="hr-num" style="color:var(--t3)">—</td>';
        }
        h+='<td class="hr-num"><strong>'+fmt(p.gross)+'</strong></td><td class="hr-num">'+fmt(p.total_deductions)+'</td>';
        h+='<td class="hr-num"><strong style="color:var(--gold)">'+fmt(p.net_pay)+'</strong></td>';
        h+='<td class="hr-num">'+(p.days_worked||'-')+'</td><td class="hr-num">'+(p.days_absent||0)+'</td>';
        h+='<td class="hr-num">'+(p.paid_leave_days||0)+'</td>';
        h+='<td class="hr-num">'+(p.lwp_days||0)+'</td>';
        h+='<td>'+payrollBadge(p.status||'Draft')+'</td>';
        h+='<td><i class="fa fa-chevron-down" style="color:var(--t3)"></i></td></tr>';
        // Detail row — structured payslip breakdown
        h+='<tr class="hr-payslip-detail" id="detail_'+esc(k)+'">';
        h+='<td colspan="14"><div class="hr-payslip-breakdown">';

        // ── W3.2.2 — ORIGINAL → ADJUSTMENT → FINAL ──
        //
        // Shown FIRST in the drill-down and never collapsed into a single
        // revised figure: an operator who sees only "Gross ₹45,000" cannot tell
        // an adjusted run from an unadjusted one, which destroys exactly the
        // auditability the override model exists to provide.
        //
        // Every number here is read from the slip's `_payroll_adjustments`
        // block, which the payroll engine wrote. The browser performs no
        // payroll arithmetic.
        if(adjBlock){
          h+='<div class="hr-adjust-panel" style="margin-bottom:14px;padding:12px;border-radius:8px;border:1px solid rgba(188,90,60,.25);background:rgba(188,90,60,.05)">';
          h+='<div style="font:600 12px/1.4 var(--font-b);color:#BC5A3C;margin-bottom:8px">'
            +'<i class="fa fa-sliders"></i> Payroll-period adjustments ('+adjBlock.count+')</div>';
          h+='<table style="width:100%;border-collapse:collapse;font:400 12px/1.6 var(--font-m)">';
          h+='<tr><td style="color:var(--t2)">Original gross <span style="color:var(--t3)">(before adjustments)</span></td>'
            +'<td class="hr-num" style="text-align:right">'+fmt(adjBlock.original_gross)+'</td></tr>';
          var lines = adjBlock.lines||[];
          for(var li=0; li<lines.length; li++){
            var L = lines[li];
            var isEarn = (L.kind==='earning');
            h+='<tr><td style="padding-left:14px;color:var(--t1)">'
              + (isEarn?'+ ':'− ') + esc(L.label||L.type||'Adjustment')
              + (L.prorated?' <span style="color:var(--t3)">(prorated)</span>':'')
              + '</td>'
              + '<td class="hr-num" style="text-align:right;color:'+(isEarn?'var(--gold)':'#b91c1c')+'">'
              + (isEarn?'+':'−')+fmt(L.amount)+'</td></tr>';
          }
          h+='<tr style="border-top:1px solid rgba(188,90,60,.25)">'
            +'<td style="font-weight:600;padding-top:6px">Revised gross</td>'
            +'<td class="hr-num" style="text-align:right;font-weight:600;padding-top:6px">'+fmt(adjBlock.final_gross)+'</td></tr>';
          h+='<tr><td style="color:var(--t2)">Original net</td>'
            +'<td class="hr-num" style="text-align:right">'+fmt(adjBlock.original_net)+'</td></tr>';
          h+='<tr><td style="font-weight:600">Revised net</td>'
            +'<td class="hr-num" style="text-align:right;font-weight:600;color:var(--gold)">'+fmt(adjBlock.final_net)+'</td></tr>';
          h+='</table>';
          h+='<div style="margin-top:8px;font:400 11px/1.5 var(--font-m);color:var(--t3)">'
            +'Applied '+esc(adjBlock.applied_at||'')+(adjBlock.applied_by?' by '+esc(adjBlock.applied_by):'')
            +'. Statutory deductions were recalculated by the payroll engine — an added earning also moves the ESI and TDS base.</div>';
          h+='</div>';
        }

        // ── W3.2.4 — "why is this net pay?" ──
        //
        // Loaded on demand rather than with the grid: a full trace per employee
        // would be dead weight on a 200-row run that nobody has drilled into.
        h+='<div style="margin-bottom:14px">';
        h+='<button class="btn btn-default btn-sm trace-btn" data-run="'+esc(runId)+'" data-staff="'+esc(p.staff_id||k)+'">'
          +'<i class="fa fa-list-ol"></i> Why is this net pay?</button>';
        h+='<div class="trace-out" data-staff="'+esc(p.staff_id||k)+'" style="margin-top:10px"></div>';
        h+='</div>';

        // ── W3.2.2 — create a payroll-period adjustment ──
        //
        // Only offered while the run is Draft and only to HR:manage. Both are
        // conveniences: `Payroll_overrides::create()` re-checks the run status,
        // the tenant, and the permission itself, because hiding a form is not
        // authorization.
        if(runStatus==='draft' && HR_CAN_MANAGE){
          var sid = esc(p.staff_id||k);
          h+='<div class="hr-adjust-form" style="margin-bottom:14px;padding:12px;border-radius:8px;border:1px dashed var(--border)">';
          h+='<div style="font:600 12px/1.4 var(--font-b);color:var(--t1);margin-bottom:8px"><i class="fa fa-plus-circle"></i> Add payroll-period adjustment</div>';
          h+='<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-start">';
          h+='<select class="form-control adj-type" data-staff="'+sid+'" style="width:190px;font-size:12px"></select>';
          h+='<input type="number" class="form-control adj-amount" data-staff="'+sid+'" placeholder="Amount ₹" min="0.01" step="0.01" style="width:130px;font-size:12px">';
          h+='<input type="text" class="form-control adj-reason" data-staff="'+sid+'" placeholder="Reason (min 10 characters)" style="flex:1;min-width:220px;font-size:12px" maxlength="500">';
          h+='<button class="btn btn-primary btn-sm adj-save" data-staff="'+sid+'" data-run="'+esc(runId)+'"><i class="fa fa-check"></i> Save</button>';
          h+='</div>';
          h+='<div style="margin-top:6px;font:400 11px/1.5 var(--font-m);color:var(--t3)">'
            +'Saving records the adjustment. It reaches the payslip and the ledger only when you <b>Regenerate</b> this employee — '
            +'the same certified recalculation the run already uses. Permanent salary is never changed.</div>';
          h+='<div class="adj-msg" data-staff="'+sid+'" style="margin-top:6px;display:none"></div>';
          h+='</div>';
        }

        // ── UX-1: DAY CLASSIFICATION strip (top) ──
        // Computes summary chips so admin sees at-a-glance how the month
        // breaks down. All values come from server fields — no client calc.
        var leaveDetailsArr = (p.leave_details && typeof p.leave_details==='object') ? Object.values(p.leave_details) : [];
        var halfDayCount   = leaveDetailsArr.filter(function(x){return x && x.half_day;}).length;
        var halfDayUnpaidCount = leaveDetailsArr.filter(function(x){return x && x.half_day && !x.paid;}).length;
        var halfDayDeduction = halfDayUnpaidCount * 0.5 * (parseFloat(p.daily_salary)||0);
        var fullLwpDeduction = Math.max(0, (parseFloat(p.lwp_deduction)||0) - halfDayDeduction);
        h+='<div class="hr-day-strip">';
        h+= '<div class="hr-day-chip working" title="Calendar working days for this month: total days minus Sundays, 2nd/4th Saturdays, and configured holidays">'
          +   '<div class="hr-day-chip-label">Working Days <span class="hr-tip" title="Total days payable for this month (excludes Sundays, 2nd/4th Saturdays, holidays)">i</span></div>'
          +   '<div class="hr-day-chip-value">'+(p.working_days||'-')+'</div>'
          +   '<div class="hr-day-chip-sub">'+(runData.month||'')+' '+(runData.year||'')+'</div>'
          + '</div>';
        h+= '<div class="hr-day-chip present" title="Days the staff was Present (P) at work">'
          +   '<div class="hr-day-chip-label"><span class="hr-day-dot present"></span>Present</div>'
          +   '<div class="hr-day-chip-value">'+(p.days_worked||0)+'</div>'
          +   '<div class="hr-day-chip-sub">no salary impact</div>'
          + '</div>';
        h+= '<div class="hr-day-chip paid-leave" title="Approved paid-type leave days. No salary deduction; balance was reduced.">'
          +   '<div class="hr-day-chip-label"><span class="hr-day-dot paid-leave"></span>Paid Leave</div>'
          +   '<div class="hr-day-chip-value">'+(p.paid_leave_days||0)+'</div>'
          +   '<div class="hr-day-chip-sub">no deduction</div>'
          + '</div>';
        h+= '<div class="hr-day-chip lwp" title="Leave Without Pay — salary deducted for these days">'
          +   '<div class="hr-day-chip-label"><span class="hr-day-dot lwp"></span>LWP <span class="hr-tip" title="Leave Without Pay — salary is deducted at the daily rate for each LWP day">i</span></div>'
          +   '<div class="hr-day-chip-value">'+(p.lwp_days||0)+'</div>'
          +   '<div class="hr-day-chip-sub">'+(parseFloat(p.lwp_deduction)>0 ? '−'+fmt(p.lwp_deduction)+' total' : 'no deduction')+'</div>'
          + '</div>';
        h+= '<div class="hr-day-chip half-day" title="Half-day leave count — each contributes 0.5 day. Unpaid half-days are deducted at half the daily rate.">'
          +   '<div class="hr-day-chip-label"><span class="hr-day-dot half-day"></span>Half-Day <span class="hr-tip" title="Half-day leaves don\'t mark attendance as L; they live on the leave doc. 0.5-day deduction applies if the leave type is unpaid (or balance was exhausted).">i</span></div>'
          +   '<div class="hr-day-chip-value">'+halfDayCount+'</div>'
          +   '<div class="hr-day-chip-sub">'+(halfDayUnpaidCount>0 ? halfDayUnpaidCount+' unpaid · −'+fmt(halfDayDeduction) : (halfDayCount>0 ? 'all paid' : 'none'))+'</div>'
          + '</div>';
        if(parseFloat(p.days_absent)>0){
          h+= '<div class="hr-day-chip absent" title="Days marked Absent in attendance — full-day deduction applies (overlap with approved LWP is excluded automatically).">'
            +   '<div class="hr-day-chip-label"><span class="hr-day-dot absent"></span>Absent</div>'
            +   '<div class="hr-day-chip-value">'+(p.days_absent||0)+'</div>'
            +   '<div class="hr-day-chip-sub">deducted</div>'
            + '</div>';
        }
        h+='</div>';

        h+='<div class="hr-slip-sections">';

        // ── LEFT: Earnings ──
        h+='<div class="hr-slip-section">';
        h+='<div class="hr-slip-section-hd earn"><i class="fa fa-plus-circle"></i> Earnings</div>';
        h+='<div class="hr-slip-row"><span class="hr-slip-label">Basic Salary</span><span class="hr-slip-value">'+fmt(p.basic)+'</span></div>';
        if(parseFloat(p.hra))  h+='<div class="hr-slip-row"><span class="hr-slip-label">HRA (House Rent)</span><span class="hr-slip-value">'+fmt(p.hra)+'</span></div>';
        if(parseFloat(p.da))   h+='<div class="hr-slip-row"><span class="hr-slip-label">DA (Dearness)</span><span class="hr-slip-value">'+fmt(p.da)+'</span></div>';
        if(parseFloat(p.ta))   h+='<div class="hr-slip-row"><span class="hr-slip-label">TA (Transport)</span><span class="hr-slip-value">'+fmt(p.ta)+'</span></div>';
        if(parseFloat(p.medical)) h+='<div class="hr-slip-row"><span class="hr-slip-label">Medical Allowance</span><span class="hr-slip-value">'+fmt(p.medical)+'</span></div>';
        if(parseFloat(p.other_allowances)) h+='<div class="hr-slip-row"><span class="hr-slip-label">Other Allowances</span><span class="hr-slip-value">'+fmt(p.other_allowances)+'</span></div>';
        h+='<div class="hr-slip-row total"><span class="hr-slip-label">Gross Pay</span><span class="hr-slip-value green">'+fmt(p.gross)+'</span></div>';
        h+='</div>';

        // ── RIGHT: Deductions ──
        h+='<div class="hr-slip-section">';
        h+='<div class="hr-slip-section-hd deduct"><i class="fa fa-minus-circle"></i> Deductions</div>';
        if(parseFloat(p.pf_employee))      h+='<div class="hr-slip-row"><span class="hr-slip-label">PF (Employee) <span class="hr-tip" title="Provident Fund — % of basic salary (statutory)">i</span></span><span class="hr-slip-value">'+fmt(p.pf_employee)+'</span></div>';
        if(parseFloat(p.esi_employee))     h+='<div class="hr-slip-row"><span class="hr-slip-label">ESI (Employee) <span class="hr-tip" title="Employees State Insurance — % of gross">i</span></span><span class="hr-slip-value">'+fmt(p.esi_employee)+'</span></div>';
        if(parseFloat(p.professional_tax)) h+='<div class="hr-slip-row"><span class="hr-slip-label">Professional Tax</span><span class="hr-slip-value">'+fmt(p.professional_tax)+'</span></div>';
        if(parseFloat(p.tds))              h+='<div class="hr-slip-row"><span class="hr-slip-label">TDS (Income Tax) <span class="hr-tip" title="Tax Deducted at Source on gross salary">i</span></span><span class="hr-slip-value">'+fmt(p.tds)+'</span></div>';
        if(parseFloat(p.other_deductions)) h+='<div class="hr-slip-row"><span class="hr-slip-label">Other Deductions</span><span class="hr-slip-value">'+fmt(p.other_deductions)+'</span></div>';
        // UX-1: split LWP deduction into full-day vs half-day so admin sees the source
        if(parseFloat(p.lwp_deduction)>0){
          if(halfDayUnpaidCount>0 && fullLwpDeduction>0){
            // Full-day LWP first
            var fullLwpDays = (parseFloat(p.lwp_days)||0) - 0.5*halfDayUnpaidCount;
            h+='<div class="hr-slip-row"><span class="hr-slip-label">Full-Day LWP <span style="opacity:.7">('+fullLwpDays.toFixed(1).replace(/\.0$/,'')+' days)</span></span><span class="hr-slip-value amber">−'+fmt(fullLwpDeduction)+'</span></div>';
            h+='<div class="hr-slip-row"><span class="hr-slip-label">Half-Day Leave <span style="opacity:.7">('+halfDayUnpaidCount+' × 0.5 day)</span></span><span class="hr-slip-value amber">−'+fmt(halfDayDeduction)+'</span></div>';
          } else if(halfDayUnpaidCount>0){
            h+='<div class="hr-slip-row"><span class="hr-slip-label">Half-Day Leave <span style="opacity:.7">('+halfDayUnpaidCount+' × 0.5 day)</span></span><span class="hr-slip-value amber">−'+fmt(p.lwp_deduction)+'</span></div>';
          } else {
            h+='<div class="hr-slip-row"><span class="hr-slip-label">LWP Deduction <span style="opacity:.7">('+(p.lwp_days||0)+' days)</span></span><span class="hr-slip-value amber">−'+fmt(p.lwp_deduction)+'</span></div>';
          }
        }
        h+='<div class="hr-slip-row total"><span class="hr-slip-label">Total Deductions</span><span class="hr-slip-value red">'+fmt(p.total_deductions)+'</span></div>';
        h+='</div>';

        h+='</div>'; // end hr-slip-sections

        // ── NET PAY highlight + Download ──
        h+='<div class="hr-slip-net-row">';
        h+='<span class="hr-slip-net-label"><i class="fa fa-inr" style="margin-right:4px"></i>Net Pay</span>';
        h+='<span class="hr-slip-net-value">'+fmt(p.net_pay)+'</span>';
        h+='</div>';
        h+='<div style="text-align:center;margin-top:8px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap">';
        h+='<a class="hr-btn hr-btn-ghost hr-btn-sm" href="'+BASE+'hr/download_payslip?run_id='+encodeURIComponent(currentRunId)+'&staff_id='+encodeURIComponent(p.staff_id||k)+'" target="_blank"><i class="fa fa-file-pdf-o"></i> Download Payslip PDF</a>';
        // Regenerate is only allowed while the run is in Draft. Finalized runs
        // must be unlocked first via the runs-table Unlock button.
        if(runStatus==='draft'){
          h+='<button class="hr-btn hr-btn-ghost hr-btn-sm" onclick="event.stopPropagation();HR.regenerateSlip(\''+esc(currentRunId)+'\',\''+esc(p.staff_id||k)+'\',\''+esc(p.staff_name||'').replace(/\'/g,"&#39;")+'\')" title="Recompute this slip from latest attendance + leaves"><i class="fa fa-refresh"></i> Regenerate</button>';
        }
        h+='</div>';

        // ── Working Info + Leave (bottom row, 2 cols) ──
        var hasWorkInfo = p.working_days||p.daily_salary;
        var hasLeaves = p.leave_details && typeof p.leave_details==='object' && Object.keys(p.leave_details).length;
        if(hasWorkInfo||hasLeaves||slipStatus==='paid'){
          h+='<div class="hr-slip-sections" style="margin-top:14px">';

          // Working info — calendar breakdown + daily salary
          // (headline counts are now in the day-classification chips above)
          if(hasWorkInfo){
            h+='<div class="hr-slip-section">';
            h+='<div class="hr-slip-section-hd info"><i class="fa fa-calendar"></i> Calendar & Daily Rate</div>';
            if(runData.days_in_month){
              h+='<div class="hr-slip-row"><span class="hr-slip-label">Total Days in Month</span><span class="hr-slip-value">'+(runData.days_in_month||'-')+'</span></div>';
              h+='<div class="hr-slip-row" style="opacity:.7"><span class="hr-slip-label">Sundays</span><span class="hr-slip-value">−'+(runData.sundays||0)+'</span></div>';
              if(parseInt(runData.off_saturdays)>0) h+='<div class="hr-slip-row" style="opacity:.7"><span class="hr-slip-label">2nd/4th Saturdays</span><span class="hr-slip-value">−'+(runData.off_saturdays||0)+'</span></div>';
              if(parseInt(runData.holidays)>0) h+='<div class="hr-slip-row" style="opacity:.7"><span class="hr-slip-label">School Holidays</span><span class="hr-slip-value">−'+(runData.holidays||0)+'</span></div>';
              h+='<div class="hr-slip-row total"><span class="hr-slip-label">= Working Days</span><span class="hr-slip-value">'+(p.working_days||'-')+'</span></div>';
            }
            h+='<div class="hr-slip-row"><span class="hr-slip-label">Daily Salary <span class="hr-tip" title="Basic salary ÷ Working days. Used to compute LWP deductions.">i</span></span><span class="hr-slip-value">'+fmt(p.daily_salary)+'</span></div>';
            if(p.deduction_reason && p.deduction_reason!=='No deduction'){
              h+='<div class="hr-slip-row" style="background:var(--bg);border-radius:4px;padding:6px 8px"><span class="hr-slip-label" style="font-size:11px">Deduction Reason</span><span class="hr-slip-value" style="font-size:11px;max-width:200px;text-align:right;white-space:normal">'+esc(p.deduction_reason)+'</span></div>';
            }
            // Minor 8 hint — only when both absent days AND leave records exist.
            if(parseFloat(p.days_absent)>0 && hasLeaves){
              h+='<div class="hr-slip-row" style="background:rgba(124,58,237,.06);border-radius:4px;padding:6px 8px;margin-top:4px"><span class="hr-slip-label" style="font-size:11px;color:#7c3aed;font-weight:500"><i class="fa fa-info-circle"></i> Verify against leave breakdown</span><span class="hr-slip-value" style="font-size:11px;color:#7c3aed">overlap days are not double-counted</span></div>';
            }
            h+='</div>';
          }

          // Leave breakdown
          if(hasLeaves){
            h+='<div class="hr-slip-section">';
            h+='<div class="hr-slip-section-hd leave"><i class="fa fa-calendar-minus-o"></i> Leave Breakdown</div>';
            $.each(p.leave_details, function(idx, ld){
              var paidTag = ld.paid ? ' <span style="color:var(--gold);font-size:10px">(Paid)</span>' : ' <span style="color:#991b1b;font-size:10px">(LWP)</span>';
              // Minor 7: half-day badge
              var halfTag = ld.half_day
                ? ' <span style="background:rgba(124,58,237,.15);color:#7c3aed;font-size:10px;padding:1px 6px;border-radius:8px;margin-left:4px">Half-day' + (ld.half_day_period ? ' · ' + esc(ld.half_day_period) : '') + '</span>'
                : '';
              var label = esc(ld.type_code||ld.type_name||'Leave') + paidTag + halfTag;
              var dateRange = ld.from ? '<div style="font-size:10px;color:var(--t3);margin-top:1px">'+esc(ld.from)+' to '+esc(ld.to||'?')+'</div>' : '';
              // Show 0.5 day for half-day; otherwise integer day count
              var dayLabel = ld.half_day ? '0.5 day' : (esc(''+(ld.total_days||ld.days||0))+' day(s)');
              h+='<div class="hr-slip-row" style="flex-wrap:wrap"><span class="hr-slip-label">'+label+dateRange+'</span><span class="hr-slip-value">'+dayLabel+'</span></div>';
            });
            h+='</div>';
          }

          // Payment info
          if(slipStatus==='paid'){
            h+='<div class="hr-slip-section"'+(hasWorkInfo&&hasLeaves?' style="grid-column:1/-1"':'')+'>';
            h+='<div class="hr-slip-section-hd pay"><i class="fa fa-check-circle"></i> Payment Details</div>';
            h+='<div class="hr-slip-row"><span class="hr-slip-label">Status</span><span class="hr-slip-value">'+payrollBadge('Paid')+'</span></div>';
            if(p.payment_mode) h+='<div class="hr-slip-row"><span class="hr-slip-label">Payment Mode</span><span class="hr-slip-value">'+esc(p.payment_mode)+'</span></div>';
            if(p.payment_date) h+='<div class="hr-slip-row"><span class="hr-slip-label">Payment Date</span><span class="hr-slip-value">'+esc(p.payment_date)+'</span></div>';
            if(p.payment_reference) h+='<div class="hr-slip-row"><span class="hr-slip-label">Reference No.</span><span class="hr-slip-value">'+esc(p.payment_reference)+'</span></div>';
            if(p.paid_at) h+='<div class="hr-slip-row"><span class="hr-slip-label">Processed At</span><span class="hr-slip-value" style="font-size:11px">'+esc(p.paid_at)+'</span></div>';
            h+='</div>';
          }

          h+='</div>'; // end bottom sections
        }

        h+='</div></td></tr>';
      });
      $tb.html(h);
      _fillAdjTypes();   // W3.2.2 — selects exist only after the rows render
      loadLopReview(runId);   // W3.2.3 — LOP review sits above this grid

      // Toggle detail rows
      $tb.find('.hr-payslip-row').on('click', function(){
        var did='detail_'+$(this).data('id');
        var $d=$('#'+did);
        $d.toggleClass('open');
        $(this).find('.fa-chevron-down,.fa-chevron-up').toggleClass('fa-chevron-down fa-chevron-up');
      });
    });
  }

  /* ================================================================
     LEAVE AUDIT LOG
  ================================================================ */
  var _auditCache = [];

  function loadLeaveAudit(){
    getJSON('hr/get_leave_audit_log').then(function(r){
      var entries = (r&&r.audit_logs) ? r.audit_logs : (r&&r.data) ? r.data : [];
      if(!Array.isArray(entries)){
        if(entries && typeof entries==='object') entries = Object.values(entries);
        else entries = [];
      }
      _auditCache = entries;
      // Populate staff filter
      var staffIds={};
      $.each(entries, function(i,e){ if(e.staff_id) staffIds[e.staff_id]=1; });
      var $sf=$('#filterAuditStaff');
      var curVal=$sf.val();
      $sf.html('<option value="">All</option>');
      $.each(Object.keys(staffIds).sort(), function(i,sid){ $sf.append('<option value="'+esc(sid)+'">'+esc(staffName(sid))+'</option>'); });
      if(curVal) $sf.val(curVal);
      renderAuditTable(entries);
    }).catch(function(){
      $('#tblLeaveAudit tbody').html('<tr><td colspan="7" class="hr-empty"><i class="fa fa-exclamation-triangle"></i> Failed to load audit log. Please try again.</td></tr>');
    });
  }

  function filterAuditTable(){
    var action=$('#filterAuditAction').val()||'';
    var staff=$('#filterAuditStaff').val()||'';
    var filtered=_auditCache.filter(function(e){
      if(action && (e.action||'')!==action) return false;
      if(staff && (e.staff_id||'')!==staff) return false;
      return true;
    });
    renderAuditTable(filtered);
  }

  function renderAuditTable(entries){
    var $tb=$('#tblLeaveAudit tbody');
    if(!entries.length){ $tb.html('<tr><td colspan="7" class="hr-empty"><i class="fa fa-inbox"></i> No audit entries found.</td></tr>'); return; }
    var h='', i=0;
    $.each(entries, function(idx,e){
      i++;
      var actionBadge = auditActionBadge(e.action, e.decision);
      var details = '';
      if(e.total_days) details += esc(''+e.total_days)+' day(s)';
      // Fraction safety (2026-08-19): parseInt floored a 0.5-day value to 0,
      // so the gate failed and the badge was dropped — the leave AUDIT TRAIL
      // visually read "no LOP" while payroll had deducted half a day. The
      // printed value itself was already unrounded; only the gate was wrong.
      if(parseFloat(e.paid_days)>0) details += ' <small style="color:var(--gold)">('+esc(e.paid_days)+' paid)</small>';
      if(parseFloat(e.lwp_days)>0) details += ' <small style="color:#991b1b">('+esc(e.lwp_days)+' LWP)</small>';
      if(e.balance_before!==undefined) details += (details?' | ':'') + 'Bal: '+esc(''+e.balance_before);
      if(e.decision_reason) details += '<br><small style="opacity:.7">'+esc(e.decision_reason)+'</small>';
      if(e.remarks) details += '<br><small style="opacity:.6;font-style:italic">Remarks: '+esc(e.remarks)+'</small>';
      h+='<tr><td class="hr-num">'+i+'</td>';
      h+='<td style="white-space:nowrap;font-size:11px">'+formatAuditTime(e.timestamp)+'</td>';
      h+='<td>'+actionBadge+'</td>';
      h+='<td>'+esc(e.staff_name||staffName(e.staff_id))+'</td>';
      h+='<td>'+esc(e.leave_type||'-')+'</td>';
      h+='<td style="font-size:12px">'+details+'</td>';
      h+='<td>'+esc(e.admin_name||'-')+'</td>';
      h+='</tr>';
    });
    $tb.html(h);
  }

  function auditActionBadge(action, decision){
    if(!action) return '-';
    if(action==='leave_decided' && decision){
      var dl = (decision+'').toLowerCase();
      if(dl==='approved') return '<span class="hr-badge hr-badge-approved" style="font-size:11px"><i class="fa fa-check"></i> Approved</span>';
      if(dl==='rejected') return '<span class="hr-badge hr-badge-rejected" style="font-size:11px"><i class="fa fa-times"></i> Rejected</span>';
      return '<span class="hr-badge" style="font-size:11px">'+esc(decision)+'</span>';
    }
    var map = {
      'leave_applied':'<span class="hr-badge" style="background:#dbeafe;color:#1e40af;font-size:11px"><i class="fa fa-paper-plane"></i> Applied</span>',
      'leave_decided':'<span class="hr-badge" style="background:#e0e7ff;color:#3730a3;font-size:11px"><i class="fa fa-gavel"></i> Decided</span>',
      'payroll_generated':'<span class="hr-badge" style="background:#fef3c7;color:#92400e;font-size:11px"><i class="fa fa-money"></i> Payroll</span>'
    };
    return map[action] || '<span class="hr-badge" style="font-size:11px">'+esc(action)+'</span>';
  }

  function formatAuditTime(ts){
    if(!ts) return '-';
    try {
      var d = new Date(ts);
      return d.toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'})+' '+d.toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit'});
    } catch(e){ return esc(ts); }
  }

  /* ================================================================
     APPRAISALS
  ================================================================ */
  function loadAppraisals(){
    var params='?period='+encodeURIComponent($('#filterAppraisalPeriod').val()||'')+'&staff_id='+($('#filterAppraisalStaff').val()||'')+'&status='+($('#filterAppraisalStatus').val()||'');
    getJSON('hr/get_appraisals'+params).then(function(r){
      var appraisals=toMap((r&&r.appraisals)?r.appraisals:(r&&r.data)?r.data:{});
      _appraisalCache = appraisals;
      var $tb=$('#tblAppraisals tbody');
      var keys=Object.keys(appraisals);

      // Populate period filter
      var periods={};
      $.each(appraisals, function(k,a){ if(a.period) periods[a.period]=1; });
      var $pf=$('#filterAppraisalPeriod');
      var curVal=$pf.val();
      $pf.html('<option value="">All</option>');
      $.each(Object.keys(periods).sort(), function(i,p){ $pf.append('<option value="'+esc(p)+'">'+esc(p)+'</option>'); });
      if(curVal) $pf.val(curVal);

      if(!keys.length){ $tb.html('<tr><td colspan="10" class="hr-empty"><i class="fa fa-inbox"></i> No appraisals found.</td></tr>'); return; }
      var h='', i=0;
      $.each(appraisals, function(k,a){
        i++;
        // a.id is the canonical APR id from the controller; fall back to map key for legacy responses
        var rid = a.id || k;
        var statusCls = (a.status||'Draft').toLowerCase();
        h+='<tr><td class="hr-num">'+i+'</td>';
        h+='<td>'+esc(a.staff_name||staffName(a.staff_id))+'</td>';
        h+='<td>'+esc(a.department||'-')+'</td>';
        h+='<td>'+badgeHtml(a.appraisal_type||'Annual')+'</td>';
        h+='<td>'+esc(a.period)+'</td>';
        h+='<td>'+starsHtml(Math.round(parseFloat(a.overall_rating)||0))+'<span class="hr-num" style="margin-left:6px">'+parseFloat(a.overall_rating||0).toFixed(1)+'</span></td>';
        h+='<td>'+badgeHtml(a.recommendation||'none')+'</td>';
        h+='<td>'+badgeHtml(a.status||'Draft')+'</td>';
        h+='<td>'+esc(a.created_at ? a.created_at.substring(0,10) : '-')+'</td>';
        h+='<td>';
        if((a.status||'Draft')==='Draft'){
          h+='<button class="hr-act-btn" onclick="HR.editAppraisal(\''+esc(rid)+'\')"><i class="fa fa-pencil"></i></button> ';
          h+='<button class="hr-act-btn" onclick="HR.submitAppraisal(\''+esc(rid)+'\')" title="Submit for review"><i class="fa fa-paper-plane"></i></button> ';
          h+='<button class="hr-act-btn danger" onclick="HR.deleteAppraisal(\''+esc(rid)+'\')"><i class="fa fa-trash"></i></button>';
        } else if((a.status||'')==='Submitted'){
          h+='<button class="hr-act-btn" onclick="HR.reviewAppraisal(\''+esc(rid)+'\')" title="Mark as Reviewed"><i class="fa fa-check-circle"></i></button>';
        } else {
          h+='<span style="font-size:11px;color:var(--t3)">Finalized</span>';
        }
        h+='</td></tr>';
      });
      $tb.html(h);
    });
  }

  function calcAppraisal(){
    var t=parseFloat($('#apTeaching').val())||0;
    var p=parseFloat($('#apPunctuality').val())||0;
    var b=parseFloat($('#apBehavior').val())||0;
    var n=parseFloat($('#apInnovation').val())||0;
    var w=parseFloat($('#apTeamwork').val())||0;
    var avg=((t+p+b+n+w)/5).toFixed(1);
    $('#apOverall').text(avg);
  }

  function onApStaffChange(){
    var $opt = $('#apStaff option:selected');
    var dept = $opt.data('dept') || '';
    $('#apDepartment').val(dept);
  }

  function openAppraisalModal(id){
    $('#appraisalId').val('');
    fillStaffSelect('#apStaff');
    fillStaffSelect('#apReviewer');
    $('#apDepartment').val('');
    $('#apType').val('Annual');
    $('#apPeriod').val('');
    $('#apTeaching,#apPunctuality,#apBehavior,#apInnovation,#apTeamwork').val(5);
    $('#apRecommendation').val('none');
    $('#apStatus').val('Draft');
    $('#apStrengths,#apImprovement,#apGoals').val('');
    calcAppraisal();
    $('#modalAppraisalTitle').text('New Appraisal');
    openModal('modalAppraisal');
  }

  function editAppraisal(id){
    if(!_appraisalCache[id]){ toast('Appraisal not found in cache — refreshing','warning'); loadAppraisals(); return; }
    var a=_appraisalCache[id];
    $('#appraisalId').val(id);
    fillStaffSelect('#apStaff', a.staff_id);
    fillStaffSelect('#apReviewer', a.reviewer_id);
    $('#apDepartment').val(a.department||'');
    $('#apType').val(a.appraisal_type||'Annual');
    $('#apPeriod').val(a.period);
    $('#apTeaching').val(a.teaching||a.teaching_quality||5);
    $('#apPunctuality').val(a.punctuality||5);
    $('#apBehavior').val(a.behavior||a.student_feedback||5);
    $('#apInnovation').val(a.innovation||a.initiative||5);
    $('#apTeamwork').val(a.teamwork||5);
    $('#apRecommendation').val(a.recommendation||'none');
    $('#apStatus').val(a.status||'Draft');
    $('#apStrengths').val(a.strengths||a.comments||'');
    $('#apImprovement').val(a.areas_of_improvement||'');
    $('#apGoals').val(a.goals||'');
    calcAppraisal();
    $('#modalAppraisalTitle').text('Edit Appraisal');
    openModal('modalAppraisal');
  }

  function saveAppraisal(){
    var staff=$('#apStaff').val(), period=$('#apPeriod').val().trim();
    if(!staff){ toast('Please select a staff member','error'); return; }
    if(!$('#apDepartment').val()){ toast('Selected staff has no department. Update staff profile first.','error'); return; }
    if(!period){ toast('Period is required','error'); return; }
    if(window._saveAppraisalInFlight) return;
    window._saveAppraisalInFlight = true;
    var $btn = $('button.hr-btn-primary[onclick*="saveAppraisal"]');
    var orig = $btn.html();
    $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving…');
    post('hr/save_appraisal', {
      id:$('#appraisalId').val(), staff_id:staff, period:period,
      appraisal_type:$('#apType').val(),
      reviewer_id:$('#apReviewer').val(),
      teaching:$('#apTeaching').val(), punctuality:$('#apPunctuality').val(),
      behavior:$('#apBehavior').val(), innovation:$('#apInnovation').val(), teamwork:$('#apTeamwork').val(),
      overall_rating:$('#apOverall').text(),
      strengths:$('#apStrengths').val().trim(), areas_of_improvement:$('#apImprovement').val().trim(),
      goals:$('#apGoals').val().trim(), recommendation:$('#apRecommendation').val()
    }).then(function(r){
      if(r&&r.status==='success'){ toast(r.message||'Saved','success'); closeModal('modalAppraisal'); loadAppraisals(); }
      else toast((r&&r.message)||'Failed to save','error');
    }).fail(function(){
      toast('Server error — refreshing list','error');
      loadAppraisals();
    }).always(function(){
      window._saveAppraisalInFlight = false;
      $btn.prop('disabled', false).html(orig);
    });
  }

  async function submitAppraisal(id){
    if(!await zxConfirm({ title: 'Submit this appraisal for review? It cannot be edited afterwards.' })) return;
    post('hr/submit_appraisal', {id:id}).then(function(r){
      if(r&&r.status==='success'){ toast(r.message||'Submitted','success'); }
      else toast((r&&r.message)||'Failed to submit','error');
    }).fail(function(){
      toast('Server error — refreshing list','error');
    }).always(function(){ loadAppraisals(); });
  }

  async function reviewAppraisal(id){
    // UI-D3: optional note, so the dialog does not force a value.
    var comments = await zxPrompt({
      title: 'Mark appraisal reviewed',
      message: 'Reviewer comments (optional):',
      placeholder: 'Optional',
      confirmText: 'Mark reviewed',
      required: false
    });
    if(comments === null) return; // cancelled
    post('hr/review_appraisal', {id:id, comments:comments}).then(function(r){
      if(r&&r.status==='success'){ toast(r.message||'Reviewed','success'); }
      else toast((r&&r.message)||'Failed to mark reviewed','error');
    }).fail(function(){
      toast('Server error — refreshing list','error');
    }).always(function(){ loadAppraisals(); });
  }

  async function deleteAppraisal(id){
    if(!await zxConfirm({ title: 'Delete this appraisal?' })) return;
    post('hr/delete_appraisal', {id:id}).then(function(r){
      if(r&&r.status==='success'){ toast('Deleted','success'); }
      else toast((r&&r.message)||'Failed to delete','error');
    }).fail(function(){
      toast('Server error — refreshing list','error');
    }).always(function(){ loadAppraisals(); });
  }

  /* ================================================================
     TRAINING / DEVELOPMENT (Staff HR Advanced Capability Programme,
     Phase 7) — a factual log entry, not a graded/reviewed workflow like
     Appraisals above: no submit/review step, just save + delete.
  ================================================================ */
  function loadTrainingRecords(){
    var params='?staff_id='+($('#filterTrainingStaff').val()||'')+'&category='+encodeURIComponent($('#filterTrainingCategory').val()||'')+'&status='+encodeURIComponent($('#filterTrainingStatus').val()||'');
    getJSON('hr/get_training_records'+params).then(function(r){
      var records=toMap((r&&r.training_records)?r.training_records:(r&&r.data)?r.data:{});
      _trainingCache = records;
      var $tb=$('#tblTraining tbody');
      var keys=Object.keys(records);
      if(!keys.length){ $tb.html('<tr><td colspan="10" class="hr-empty"><i class="fa fa-inbox"></i> No training records found.</td></tr>'); return; }
      var h='', i=0;
      $.each(records, function(k,t){
        i++;
        var rid = t.id || k;
        h+='<tr><td class="hr-num">'+i+'</td>';
        h+='<td>'+esc(t.staff_name||staffName(t.staff_id))+'</td>';
        h+='<td>'+esc(t.department||'-')+'</td>';
        h+='<td>'+esc(t.title)+'</td>';
        h+='<td>'+badgeHtml(t.category||'Training')+'</td>';
        h+='<td>'+esc(t.provider||'-')+'</td>';
        h+='<td>'+badgeHtml(t.status||'Completed')+'</td>';
        h+='<td>'+esc(t.completed_date||'-')+'</td>';
        h+='<td>'+esc(t.expiry_date||'-')+'</td>';
        h+='<td>';
        h+='<button class="hr-act-btn" onclick="HR.editTrainingRecord(\''+esc(rid)+'\')"><i class="fa fa-pencil"></i></button> ';
        h+='<button class="hr-act-btn danger" onclick="HR.deleteTrainingRecord(\''+esc(rid)+'\')"><i class="fa fa-trash"></i></button>';
        h+='</td></tr>';
      });
      $tb.html(h);
    });
  }

  function onTrStatusChange(){
    // No forced value — save_training_record() itself requires
    // completed_date only when status is Completed; this just nudges the
    // date field into focus for the common case.
    if($('#trStatus').val()==='Completed' && !$('#trCompletedDate').val()){
      $('#trCompletedDate').val(new Date().toISOString().slice(0,10));
    }
  }

  function openTrainingModal(){
    $('#trainingId').val('');
    fillStaffSelect('#trStaff');
    $('#trTitle,#trProvider,#trHours,#trCompletedDate,#trExpiryDate,#trNotes').val('');
    $('#trCategory').val('Training');
    $('#trStatus').val('Completed');
    $('#modalTrainingTitle').text('New Training Record');
    openModal('modalTraining');
  }

  function editTrainingRecord(id){
    if(!_trainingCache[id]){ toast('Training record not found in cache — refreshing','warning'); loadTrainingRecords(); return; }
    var t=_trainingCache[id];
    $('#trainingId').val(id);
    fillStaffSelect('#trStaff', t.staff_id);
    $('#trTitle').val(t.title||'');
    $('#trProvider').val(t.provider||'');
    $('#trCategory').val(t.category||'Training');
    $('#trStatus').val(t.status||'Completed');
    $('#trHours').val(t.hours||'');
    $('#trCompletedDate').val(t.completed_date||'');
    $('#trExpiryDate').val(t.expiry_date||'');
    $('#trNotes').val(t.notes||'');
    $('#modalTrainingTitle').text('Edit Training Record');
    openModal('modalTraining');
  }

  function saveTrainingRecord(){
    var staff=$('#trStaff').val(), title=$('#trTitle').val().trim();
    if(!staff){ toast('Please select a staff member','error'); return; }
    if(!title){ toast('Title is required','error'); return; }
    if($('#trStatus').val()==='Completed' && !$('#trCompletedDate').val()){
      toast('Completed date is required for a Completed record','error'); return;
    }
    if(window._saveTrainingInFlight) return;
    window._saveTrainingInFlight = true;
    var $btn = $('button.hr-btn-primary[onclick*="saveTrainingRecord"]');
    var orig = $btn.html();
    $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving…');
    post('hr/save_training_record', {
      id:$('#trainingId').val(), staff_id:staff, title:title,
      provider:$('#trProvider').val().trim(), category:$('#trCategory').val(),
      status:$('#trStatus').val(), hours:$('#trHours').val(),
      completed_date:$('#trCompletedDate').val(), expiry_date:$('#trExpiryDate').val(),
      notes:$('#trNotes').val().trim()
    }).then(function(r){
      if(r&&r.status==='success'){ toast(r.message||'Saved','success'); closeModal('modalTraining'); loadTrainingRecords(); }
      else toast((r&&r.message)||'Failed to save','error');
    }).fail(function(){
      toast('Server error — refreshing list','error');
      loadTrainingRecords();
    }).always(function(){
      window._saveTrainingInFlight = false;
      $btn.prop('disabled', false).html(orig);
    });
  }

  async function deleteTrainingRecord(id){
    if(!await zxConfirm({ title: 'Delete this training record?' })) return;
    post('hr/delete_training_record', {id:id}).then(function(r){
      if(r&&r.status==='success'){ toast('Deleted','success'); }
      else toast((r&&r.message)||'Failed to delete','error');
    }).fail(function(){
      toast('Server error — refreshing list','error');
    }).always(function(){ loadTrainingRecords(); });
  }

  /* ================================================================
     ORG CHART / REPORTING HIERARCHY (Staff HR Advanced Capability
     Programme, Phase 8) — read-only. Renders downward from the roots
     the server computed; never derives its own hierarchy rules, and
     never writes.
  ================================================================ */
  var _orgData = null;
  var _orgCollapsed = {};

  function loadOrgChart(){
    $('#orgChartWrap').html('<p class="hr-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</p>');
    $('#orgIssuesWrap').empty();
    getJSON('hr/get_org_chart').then(function(r){
      if(!r || r.status!=='success'){
        $('#orgChartWrap').html('<p class="hr-empty"><i class="fa fa-exclamation-triangle"></i> '+esc((r&&r.message)||'Could not load the reporting hierarchy.')+'</p>');
        return;
      }
      _orgData = r;
      renderOrgChart();
    }).fail(function(){
      $('#orgChartWrap').html('<p class="hr-empty"><i class="fa fa-exclamation-triangle"></i> Server error loading the reporting hierarchy.</p>');
    });
  }

  var ORG_ISSUE_LABEL = {
    orphan_manager:   'Missing manager',
    inactive_manager: 'Inactive manager',
    cycle:            'Circular chain',
    self_reference:   'Reports to self',
    unreachable:      'Detached from hierarchy'
  };

  function renderOrgChart(){
    if(!_orgData) return;
    var nodes = _orgData.nodes || {};
    var roots = _orgData.roots || [];
    var issues = _orgData.issues || [];
    var showInactive = $('#orgShowInactive').val()==='1';
    var q = ($('#orgSearch').val()||'').trim().toLowerCase();

    /* Issues panel — always rendered when non-empty, never hidden behind
       a toggle: a silently-incomplete chart is the failure mode this
       whole surface exists to prevent. */
    var $iw = $('#orgIssuesWrap');
    if(issues.length){
      var ih = '<div class="org-issues"><h4><i class="fa fa-exclamation-triangle"></i> '+issues.length+' hierarchy issue'+(issues.length===1?'':'s')+' found</h4><ul>';
      issues.forEach(function(is){
        ih += '<li><strong>'+esc(ORG_ISSUE_LABEL[is.type]||is.type)+':</strong> '+esc(is.staffName||is.staffId)+' — '+esc(is.detail||'')+'</li>';
      });
      ih += '</ul></div>';
      $iw.html(ih);
    } else {
      $iw.empty();
    }

    /* children index, built from the server's node map only */
    var kids = {};
    Object.keys(nodes).forEach(function(sid){
      var m = nodes[sid].managerId;
      if(m && m!==sid && nodes[m]) (kids[m] = kids[m]||[]).push(sid);
    });
    Object.keys(kids).forEach(function(k){
      kids[k].sort(function(a,b){ return (nodes[a].name||'').localeCompare(nodes[b].name||''); });
    });

    var cyclicSet = {};
    (_orgData.cyclic_ids||[]).forEach(function(id){ cyclicSet[id]=true; });

    function isActive(n){ return (n.status||'Active').toLowerCase().trim()==='active'; }
    function matches(n){
      if(!q) return false;
      return (n.name||'').toLowerCase().indexOf(q)>=0
          || (n.position||'').toLowerCase().indexOf(q)>=0
          || (n.department||'').toLowerCase().indexOf(q)>=0;
    }
    /* A node is kept if it matches, or if any descendant matches — so a
       search never orphans a matching person by hiding their parent. */
    function subtreeHasMatch(sid){
      var n = nodes[sid]; if(!n) return false;
      if(matches(n)) return true;
      var cs = kids[sid]||[];
      for(var i=0;i<cs.length;i++){ if(subtreeHasMatch(cs[i])) return true; }
      return false;
    }
    function countDescendants(sid){
      var cs = kids[sid]||[], total = 0;
      for(var i=0;i<cs.length;i++){ total += 1 + countDescendants(cs[i]); }
      return total;
    }

    function renderNode(sid, depth){
      var n = nodes[sid]; if(!n) return '';
      if(depth > 40) return '';   /* client-side belt-and-braces; server already bounds depth */
      var active = isActive(n);
      if(!showInactive && !active && !subtreeHasMatch(sid)) return '';
      if(q && !subtreeHasMatch(sid)) return '';

      var cs = (kids[sid]||[]).filter(function(c){
        if(!showInactive && !isActive(nodes[c]) && !subtreeHasMatch(c)) return false;
        if(q && !subtreeHasMatch(c)) return false;
        return true;
      });
      var collapsed = !!_orgCollapsed[sid] && !q;
      var total = countDescendants(sid);

      var cls = 'org-node' + (matches(n)?' is-match':'') + (active?'':' is-inactive');
      var h = '<li>';
      h += '<div class="'+cls+'">';
      h += '<button type="button" class="org-toggle'+(cs.length?'':' leaf')+'" onclick="HR.toggleOrgNode('+JSON.stringify(sid)+')">'
         + (cs.length ? (collapsed?'<i class="fa fa-plus"></i>':'<i class="fa fa-minus"></i>') : '')
         + '</button>';
      h += '<div><div class="org-name">'+esc(n.name||sid)+'</div>';
      var meta = [];
      if(n.position) meta.push(esc(n.position));
      if(n.department) meta.push(esc(n.department));
      h += '<div class="org-meta">'+(meta.join(' · ')||'&nbsp;')+'</div></div>';
      if(!active) h += '<span class="org-flag">Inactive</span>';
      if(cyclicSet[sid]) h += '<span class="org-flag sev">In a circular chain</span>';
      if(total) h += '<span class="org-count">'+total+' report'+(total===1?'':'s')+'</span>';
      h += '</div>';

      if(cs.length && !collapsed){
        var inner = '';
        for(var i=0;i<cs.length;i++) inner += renderNode(cs[i], depth+1);
        if(inner) h += '<ul>'+inner+'</ul>';
      }
      h += '</li>';
      return h;
    }

    var body = '';
    for(var i=0;i<roots.length;i++) body += renderNode(roots[i], 0);

    if(!body){
      $('#orgChartWrap').html('<p class="hr-empty"><i class="fa fa-inbox"></i> '
        + (q ? 'No one matches that search.' : 'No reporting hierarchy to show yet — set a Reporting Manager on a staff member to begin.')
        + '</p>');
      return;
    }
    $('#orgChartWrap').html('<ul class="org-tree">'+body+'</ul>');
  }

  function toggleOrgNode(sid){
    _orgCollapsed[sid] = !_orgCollapsed[sid];
    renderOrgChart();
  }

  /* ── Circular Viewer ──────────────────────────────────────── */
  var _circularJobId = '';

  function viewCircular(jobId){
    _circularJobId = jobId;
    var j = _jobsCache[jobId];
    $('#circularViewTitle').text('Circular — ' + (j ? j.title : jobId));
    $('#circularPosterWrap').html('<div style="text-align:center;padding:40px;color:var(--t3);"><i class="fa fa-spinner fa-spin" style="font-size:24px;"></i><br>Loading circular...</div>');
    openModal('modalCircular');

    getJSON('hr/view_circular?job_id=' + encodeURIComponent(jobId)).then(function(r){
      if(r && r.status === 'success' && r.poster_html){
        $('#circularPosterWrap').html(r.poster_html);
      } else {
        $('#circularPosterWrap').html('<div style="text-align:center;padding:40px;color:var(--t3);"><i class="fa fa-exclamation-triangle" style="font-size:24px;color:#ef4444;"></i><br>'+(r.message||'Failed to load circular')+'</div>');
      }
    });
  }

  function printCircular(){
    var content = document.getElementById('circularPosterWrap').innerHTML;
    var w = window.open('', '_blank', 'width=700,height=900');
    w.document.write('<!DOCTYPE html><html><head><title>Job Circular</title>');
    // The circular carries its own scoped stylesheet; all this window owns is
    // page geometry. A4 with a 14mm margin is the sheet a school prints on.
    w.document.write('<style>@page{size:A4;margin:14mm;}'
      + 'body{margin:0;font-family:"Segoe UI",system-ui,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact;}</style>');
    w.document.write('</head><body>');
    w.document.write(content);
    w.document.write('<script>setTimeout(function(){window.print();},500);<\/script>');
    w.document.write('</body></html>');
    w.document.close();
  }

  async function regenCircular(){
    if(!_circularJobId) return;
    if(!await zxConfirm({ title: 'Regenerate the circular for this job posting? The old circular will be replaced.' })) return;
    var $btn = $('#btnRegenCirc').prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Regenerating...');
    post('hr/regenerate_circular', { job_id: _circularJobId }).then(function(r){
      $btn.prop('disabled',false).html('<i class="fa fa-refresh"></i> Regenerate');
      if (r && r.status && r.status !== 'error'){
        toast(r.message || 'Regenerated', 'success');
        loadJobs();
        // Refresh poster
        viewCircular(_circularJobId);
      } else {
        toast(r.message || 'Failed', 'error');
      }
    });
  }

  async function generateCircular(jobId){
    if(!await zxConfirm({ title: 'Generate a circular for this job posting?' })) return;
    post('hr/regenerate_circular', { job_id: jobId }).then(function(r){
      if (r && r.status && r.status !== 'error'){
        toast('Circular ' + (r.circular_id||'') + ' generated and published!', 'success', 5000);
        loadJobs();
      } else {
        toast(r.message || 'Failed', 'error');
      }
    });
  }

  /* ── Public API ─────────────────────────────────────────── */
  /* ── Comp-Off (COMP-OFF-1) ─────────────────────────────────────────────
     Thin wrappers over hr/earn_comp_off and hr/get_comp_off_ledger. Every
     refusal that matters (not marked W, future date, staff inactive) is a
     sentence from the server, so it is shown as-is rather than re-worded. */
  var _coBusy = false;

  function coRenderLedger(d){
    var $tb = $('#coTable tbody').empty();
    var entries = (d && d.entries) || [];
    $('#coBalance').text(d && d.available_balance !== undefined
      ? (fmt(d.available_balance) + ' day(s) available') : '');

    if(!entries.length){
      $tb.append('<tr><td colspan="5" class="hr-empty">No Comp-Off credits for this employee.</td></tr>');
      return;
    }
    entries.forEach(function(e){
      $tb.append('<tr>'
        + '<td>' + esc(e.earnedDate || '') + '</td>'
        + '<td>' + esc(String(e.creditDays !== undefined ? e.creditDays : '')) + '</td>'
        + '<td>' + esc(e.status || '') + '</td>'
        + '<td>' + esc(e.expiresOn || '—') + '</td>'
        + '<td>' + esc(e.consumedAt || '—') + '</td>'
        + '</tr>');
    });
  }

  function loadCompOffLedger(){
    var staffId = ($('#coStaffId').val() || '').trim();
    if(!staffId){ toast('Enter an employee ID first.','error'); return; }
    if(_coBusy) return;
    _coBusy = true;

    var $b = $('#coLoadBtn'), old = $b.html();
    $b.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
    $('#coTable tbody').html('<tr><td colspan="5" class="hr-empty">Loading&hellip;</td></tr>');

    getJSON('hr/get_comp_off_ledger?staff_id=' + encodeURIComponent(staffId))
      .then(function(r){
        coRenderLedger((r && r.data) ? r.data : r);
      })
      .catch(function(){
        $('#coTable tbody').html('<tr><td colspan="5" class="hr-empty">Could not load the ledger.</td></tr>');
      })
      .always(function(){
        _coBusy = false;
        $b.prop('disabled', false).html(old);
      });
  }

  function creditCompOff(){
    var staffId = ($('#coStaffId').val() || '').trim();
    var date    = ($('#coDate').val() || '').trim();
    if(!staffId){ toast('Enter an employee ID first.','error'); return; }
    if(!date){ toast('Choose the date that was worked.','error'); return; }
    if(_coBusy) return;
    _coBusy = true;

    var $b = $('#coCreditBtn'), old = $b.html();
    $b.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');

    post('hr/earn_comp_off', {staff_id: staffId, date: date})
      .then(function(r){
        toast((r && r.message) || 'Comp-Off credited.','success');
        loadCompOffLedger();
      })
      .always(function(){
        _coBusy = false;
        $b.prop('disabled', false).html(old);
      });
  }
  window.HR = {
    closeModal:       closeModal,
    openJobModal:     openJobModal,
    editJob:          editJob,
    saveJob:          saveJob,
    deleteJob:        deleteJob,
    viewCircular:     viewCircular,
    printCircular:    printCircular,
    regenCircular:    regenCircular,
    generateCircular: generateCircular,
    openApplicantModal: openApplicantModal,
    editApplicant:    editApplicant,
    saveApplicant:    saveApplicant,
    deleteApplicant:  deleteApplicant,
    quickApplicantStatus: quickApplicantStatus,
    loadJobs:         loadJobs,
    openLeaveTypeModal: openLeaveTypeModal,
    editLeaveType:    function(id){ openLeaveTypeModal(id); },
    saveLeaveType:    saveLeaveType,
    deleteLeaveType:  deleteLeaveType,
    runLeaveAccrual:  runLeaveAccrual,
    saveAccrualMode:  saveAccrualMode,
    saveLeavePolicy:  saveLeavePolicy,
    previewCarryForward: previewCarryForward,
    confirmCarryForward: confirmCarryForward,
    seedLeaveTypes:   seedLeaveTypes,
    seedOnePill:      seedOnePill,
    creditCompOff:    creditCompOff,
    loadCompOffLedger: loadCompOffLedger,
    openLeaveRequestModal: openLeaveRequestModal,
    saveLeaveRequest: saveLeaveRequest,
    loadLeaveRequests: loadLeaveRequests,
    approveLeave:     approveLeave,
    rejectLeave:      rejectLeave,
    cancelLeave:      cancelLeave,
    confirmLeaveAction: confirmLeaveAction,
    initBalances:     initBalances,
    loadLeaveAudit:   loadLeaveAudit,
    filterAuditTable: filterAuditTable,
    openSalaryModal:  openSalaryModal,
    editSalary:       editSalary,
    saveSalary:       saveSalary,
    deleteSalary:     deleteSalary,
    backfillStructures: backfillStructures,
    viewSalaryDetail: viewSalaryDetail,
    calcSalary:       calcSalary,
    openGeneratePayroll: openGeneratePayroll,
    generatePayroll:  generatePayroll,
    autoCreateAccounts: autoCreateAccounts,
    finalizeRun:      finalizeRun,
    unlockRun:        unlockRun,
    regenerateSlip:   regenerateSlip,
    approvePayroll:   approvePayroll,
    deletePayrollRun: deletePayrollRun,
    exportPayroll:    exportPayroll,
    markPaid:         markPaid,
    openPaymentModal: openPaymentModal,
    confirmPayment:   confirmPayment,
    viewPayslips:     viewPayslips,
    openAppraisalModal: function(id){ openAppraisalModal(id); },
    editAppraisal:    editAppraisal,
    saveAppraisal:    saveAppraisal,
    submitAppraisal:  submitAppraisal,
    reviewAppraisal:  reviewAppraisal,
    deleteAppraisal:  deleteAppraisal,
    calcAppraisal:    calcAppraisal,
    onApStaffChange:  onApStaffChange,
    loadAppraisals:   loadAppraisals,
    openTrainingModal:   openTrainingModal,
    editTrainingRecord:  editTrainingRecord,
    saveTrainingRecord:  saveTrainingRecord,
    deleteTrainingRecord: deleteTrainingRecord,
    onTrStatusChange:    onTrStatusChange,
    loadTrainingRecords: loadTrainingRecords,
    loadOrgChart:        loadOrgChart,
    renderOrgChart:      renderOrgChart,
    toggleOrgNode:       toggleOrgNode
  };

  /* ── Boot ───────────────────────────────────────────────── */
  init();

})();
}); /* end DOMContentLoaded */
</script>
