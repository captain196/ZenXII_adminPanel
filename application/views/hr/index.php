<?php defined('BASEPATH') OR exit('No direct script access allowed');
    $at = isset($active_tab) ? $active_tab : 'dashboard';
    $tab_map = [
        'dashboard'   => ['panel'=>'panelDash',      'icon'=>'fa-tachometer',        'label'=>'Dashboard'],
        'departments' => ['panel'=>'panelDept',       'icon'=>'fa-building-o',        'label'=>'Departments'],
        'recruitment' => ['panel'=>'panelRecruit',    'icon'=>'fa-briefcase',         'label'=>'Recruitment'],
        'leaves'      => ['panel'=>'panelLeaves',     'icon'=>'fa-calendar-minus-o',  'label'=>'Leave Mgmt'],
        'payroll'     => ['panel'=>'panelPayroll',    'icon'=>'fa-money',             'label'=>'Payroll'],
        'appraisals'  => ['panel'=>'panelAppraisal',  'icon'=>'fa-star',              'label'=>'Appraisals'],
    ];
?>

<div class="content-wrapper">
<section class="content">
<div class="hr-wrap">

  <!-- Header -->
  <div class="hr-header">
    <div>
      <div class="hr-header-icon"><i class="fa fa-users"></i> HR &amp; Staff Management</div>
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
        <button class="hr-btn hr-btn-primary" onclick="HR.openAppraisalModal()"><i class="fa fa-plus"></i> New Appraisal</button>
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

  <!-- ================================================================
       2. DEPARTMENTS
  ================================================================= -->
  <div class="hr-panel<?= $at === 'departments' ? ' active' : '' ?>" id="panelDept">
    <div class="hr-card">
      <div class="hr-card-title">
        <span><i class="fa fa-building-o"></i> Departments</span>
        <div style="display:flex;gap:8px;">
          <button class="hr-btn hr-btn-ghost hr-btn-sm" onclick="HR.seedDepartments()" id="seedDeptBtn"><i class="fa fa-magic"></i> Seed Suggestions</button>
          <button class="hr-btn hr-btn-primary hr-btn-sm" onclick="HR.openDeptModal()"><i class="fa fa-plus"></i> Add Department</button>
        </div>
      </div>
      <div class="hr-table-wrap">
        <table class="hr-table" id="tblDepts">
          <thead><tr><th>#</th><th>Name</th><th>Head</th><th>Staff Count</th><th>Description</th><th>Actions</th></tr></thead>
          <tbody><tr><td colspan="6" class="hr-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

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
          <button class="hr-btn hr-btn-primary hr-btn-sm" onclick="HR.openLeaveTypeModal()"><i class="fa fa-plus"></i> Add Type</button>
        </div>
        <div class="hr-table-wrap">
          <table class="hr-table" id="tblLeaveTypes">
            <thead><tr><th>#</th><th>Name</th><th>Code</th><th>Days/Year</th><th>Carry Forward</th><th>Max Carry</th><th>Actions</th></tr></thead>
            <tbody><tr><td colspan="7" class="hr-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr></tbody>
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

    <!-- Audit Log -->
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
        <div class="hr-table-wrap" id="payslipTableWrap" style="display:none;">
          <table class="hr-table" id="tblPayslips">
            <thead><tr><th>#</th><th>Staff</th><th>Basic</th><th>Allowances</th><th>Gross</th><th>Deductions</th><th>Net</th><th>Days Worked</th><th>Absent</th><th>Paid Leave</th><th>LWP</th><th>Status</th><th></th></tr></thead>
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

</div><!-- /.hr-wrap -->
</section>
</div><!-- /.content-wrapper -->


<!-- ================================================================
     MODALS
================================================================= -->

<!-- Department Modal -->
<div class="hr-modal-overlay" id="modalDept">
  <div class="hr-modal">
    <div class="hr-modal-title"><i class="fa fa-building-o"></i> <span id="modalDeptTitle">Add Department</span></div>
    <input type="hidden" id="deptId">
    <div class="hr-fg">
      <label>Department Name <span class="req">*</span></label>
      <input type="text" id="deptName" placeholder="e.g. Mathematics" maxlength="100">
    </div>
    <div class="hr-fg">
      <label>Department Head</label>
      <select id="deptHead"><option value="">-- Select Staff --</option></select>
    </div>
    <div class="hr-fg">
      <label>Description</label>
      <textarea id="deptDesc" rows="3" placeholder="Brief description..."></textarea>
    </div>
    <div class="hr-modal-actions">
      <button class="hr-btn hr-btn-ghost" onclick="HR.closeModal('modalDept')">Cancel</button>
      <button class="hr-btn hr-btn-primary" onclick="HR.saveDept()"><i class="fa fa-check"></i> Save</button>
    </div>
  </div>
</div>

<!-- Job Posting Modal -->
<div class="hr-modal-overlay" id="modalJob">
  <div class="hr-modal" style="max-width:620px;">
    <div class="hr-modal-title"><i class="fa fa-briefcase"></i> <span id="modalJobTitle">New Job Posting</span></div>
    <input type="hidden" id="jobId">
    <div class="hr-modal-grid">
      <div class="hr-fg" style="grid-column:1/-1;">
        <label>Job Title <span class="req">*</span></label>
        <input type="text" id="jobTitle" placeholder="e.g. Mathematics Teacher" maxlength="150">
      </div>
      <div class="hr-fg">
        <label>Department <span class="req">*</span></label>
        <select id="jobDept"><option value="">-- Select --</option></select>
      </div>
      <div class="hr-fg">
        <label>Positions <span class="req">*</span></label>
        <input type="number" id="jobPositions" min="1" value="1">
      </div>
      <div class="hr-fg" style="grid-column:1/-1;">
        <label>Description</label>
        <textarea id="jobDescription" rows="3" placeholder="Role description..."></textarea>
      </div>
      <div class="hr-fg" style="grid-column:1/-1;">
        <label>Qualifications</label>
        <textarea id="jobQualifications" rows="2" placeholder="Required qualifications..."></textarea>
      </div>
      <div class="hr-fg" style="grid-column:1/-1;">
        <label>Experience Required</label>
        <input type="text" id="jobExperience" placeholder="e.g. 3+ years" maxlength="100">
      </div>
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
    <div class="hr-modal-actions">
      <button class="hr-btn hr-btn-ghost" onclick="HR.closeModal('modalJob')">Cancel</button>
      <button class="hr-btn hr-btn-primary" onclick="HR.saveJob()"><i class="fa fa-check"></i> Save</button>
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
      <button class="hr-btn hr-btn-primary" onclick="HR.saveApplicant()"><i class="fa fa-check"></i> Save</button>
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
<div class="hr-modal-overlay" id="modalGenPayroll">
  <div class="hr-modal" style="max-width:480px;">
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
        <input type="text" id="apDepartment" readonly placeholder="Auto-filled from staff" style="background:var(--bg3,#e6f4f1);cursor:not-allowed;">
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
        <input type="text" id="apStatus" value="Draft" readonly style="background:var(--bg3,#e6f4f1);cursor:not-allowed;">
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

/* Badges */
.hr-badge{display:inline-block;padding:3px 10px;font-size:12px;font-weight:700;border-radius:20px;text-transform:capitalize;font-family:var(--font-b);letter-spacing:.3px}
.hr-badge-pending{background:#fef3c7;color:#92400e}
.hr-badge-approved,.hr-badge-open{background:#d1fae5;color:#065f46}
.hr-badge-rejected{background:#fee2e2;color:#991b1b}
.hr-badge-cancelled{background:#e5e7eb;color:#4b5563}
.hr-badge-closed{background:#e5e7eb;color:#4b5563}
.hr-badge-draft{background:#dbeafe;color:#1e40af}
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
.hr-pipe-stage[data-stage="Selected"] .hr-pipe-count{color:#0f766e}
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
.hr-modal-overlay.open{display:flex;animation:hrFadeIn .2s}
.hr-modal{background:var(--bg2);border-radius:12px;padding:24px;max-width:580px;width:95%;max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.hr-modal-overlay.open .hr-modal{animation:hrSlideUp .25s var(--ease)}
.hr-modal-title{font-size:16px;font-weight:700;color:var(--t1);margin-bottom:18px;display:flex;align-items:center;gap:8px;font-family:var(--font-b)}
.hr-modal-title i{color:var(--gold)}
.hr-modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:18px;padding-top:14px;border-top:1px solid var(--border)}
.hr-modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}

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
.hr-sal-bd-net{display:flex;flex-direction:column;align-items:center;justify-content:center;background:linear-gradient(135deg,rgba(15,118,110,.06) 0%,rgba(12,30,56,.04) 100%);text-align:center}
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
.hr-slip-section-hd.earn{background:rgba(15,118,110,.08);color:#0f766e}
.hr-slip-section-hd.deduct{background:rgba(220,38,38,.06);color:#b91c1c}
.hr-slip-section-hd.info{background:rgba(100,116,139,.08);color:#475569}
.hr-slip-section-hd.leave{background:rgba(124,58,237,.06);color:#6d28d9}
.hr-slip-section-hd.pay{background:rgba(15,118,110,.1);color:#0f766e}
.hr-slip-row{display:flex;justify-content:space-between;align-items:center;padding:6px 12px;border-bottom:1px solid var(--border)}
.hr-slip-row:last-child{border-bottom:none}
.hr-slip-row.total{background:var(--bg);font-weight:700;border-top:2px solid var(--border)}
.hr-slip-label{color:var(--t2);font:400 12px/1.4 var(--font-m)}
.hr-slip-value{color:var(--t1);font:600 12px/1.4 var(--font-m);text-align:right;font-variant-numeric:tabular-nums}
.hr-slip-value.green{color:#0f766e}
.hr-slip-value.red{color:#dc2626}
.hr-slip-value.amber{color:#d97706}
.hr-slip-net-row{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;margin-top:14px;border-radius:8px;background:var(--gold-dim);border:1px solid var(--gold-ring)}
.hr-slip-net-label{font:700 13px/1 var(--font-b);color:var(--gold)}
.hr-slip-net-value{font:700 16px/1 var(--font-b);color:var(--gold);font-variant-numeric:tabular-nums}

/* Toast */
#hrToastContainer{position:fixed;top:20px;right:20px;z-index:99999;display:flex;flex-direction:column;gap:8px}
.hr-toast{padding:12px 18px;border-radius:8px;font-size:13px;font-weight:600;font-family:var(--font-b);color:#fff;animation:hrSlideIn .3s var(--ease);min-width:240px;box-shadow:0 8px 24px rgba(0,0,0,.2)}
.hr-toast.success{background:#059669}
.hr-toast.error{background:#dc2626}
.hr-toast.info{background:#2563eb}

/* Animations */
@keyframes hrFadeIn{from{opacity:0}to{opacity:1}}
@keyframes hrSlideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
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
<script>
/* Save PHP config before jQuery loads */
var _HR_CFG = {
  BASE:      '<?= base_url() ?>',
  CSRF_NAME: '<?= $this->security->get_csrf_token_name() ?>',
  CSRF_HASH: '<?= $this->security->get_csrf_hash() ?>',
  activeTab: '<?= $at ?>'
};
/* Defer until jQuery is available (loaded in footer) */
document.addEventListener('DOMContentLoaded', function(){
(function(){
  'use strict';

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
    $(document).on('click', '.hr-sub-tab', function(){
      var sub = $(this).data('sub');
      var $parent = $(this).closest('.hr-panel');
      $parent.find('.hr-sub-tab').removeClass('active');
      $(this).addClass('active');
      $parent.find('.hr-sub-panel').removeClass('active');
      $parent.find('#sub' + sub.charAt(0).toUpperCase() + sub.slice(1)).addClass('active');
      // Lazy-load audit log on first tab visit
      if(sub==='leaveAudit' && !_auditCache.length) loadLeaveAudit();
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
    fillDeptSelect('#filterJobDept');
  }

  function loadTabData(){
    switch(activeTab){
      case 'dashboard':    loadDashboard(); break;
      case 'departments':  loadDepartments(); break;
      case 'recruitment':  loadJobs(); break;
      case 'leaves':       loadLeaveTypes(); loadLeaveRequests(); loadLeaveBalances(); break;
      case 'payroll':      loadMyPayslips(); loadSalaryStructures(); loadPayrollRuns(); break;
      case 'appraisals':   loadAppraisals(); break;
    }
  }

  /* ================================================================
     DASHBOARD
  ================================================================ */
  function loadDashboard(){
    getJSON('hr/get_dashboard').then(function(r){
      if(!r) return;
      $('#statTotalStaff').text(r.staff_count||0);
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
     DEPARTMENTS
  ================================================================ */
  function loadDepartments(){
    getJSON('hr/get_departments').then(function(r){
      deptCache = toMap((r&&r.departments)?r.departments:(r&&r.data)?r.data:{});
      var $tb=$('#tblDepts tbody');
      var keys=Object.keys(deptCache);
      if(!keys.length){ $tb.html('<tr><td colspan="6" class="hr-empty"><i class="fa fa-inbox"></i> No departments found. Add one to get started.</td></tr>'); return; }
      var h='', i=0;
      $.each(deptCache, function(k,d){
        i++;
        var cnt = (d.staff_count||0);
        var cntBadge = cnt > 0
          ? '<span style="background:var(--gold-dim);color:var(--gold);padding:2px 8px;border-radius:10px;font-weight:700;font-size:12px;cursor:pointer" onclick="$(\'#deptStaff_'+esc(k)+'\').toggle()">'+cnt+'</span>'
          : '<span style="color:var(--t3)">0</span>';
        h+='<tr><td class="hr-num">'+i+'</td><td><strong>'+esc(d.name)+'</strong></td><td>'+esc(staffName(d.head_staff_id))+'</td><td class="hr-num">'+cntBadge+'</td><td>'+esc(d.description||'-')+'</td>';
        h+='<td><button class="hr-act-btn" onclick="HR.editDept(\''+esc(k)+'\')"><i class="fa fa-pencil"></i></button> ';
        h+='<button class="hr-act-btn danger" onclick="HR.deleteDept(\''+esc(k)+'\')"><i class="fa fa-trash"></i></button></td></tr>';
        // Expandable detail: staff list + job openings
        if(cnt > 0 || (d.openings && d.openings.length)){
          h+='<tr id="deptStaff_'+esc(k)+'" style="display:none"><td colspan="6" style="padding:8px 16px 12px 40px;background:var(--bg3)">';

          // Staff list
          if(cnt > 0 && d.staff && d.staff.length){
            h+='<div style="font:600 11px/1.3 var(--font-b);color:var(--t2);margin-bottom:6px"><i class="fa fa-users" style="margin-right:4px"></i> Staff ('+cnt+')</div>';
            h+='<table style="width:100%;font-size:12px;border-collapse:collapse;margin-bottom:12px">';
            h+='<thead><tr style="border-bottom:1px solid var(--border)"><th style="text-align:left;padding:4px 8px;font-size:10px;color:var(--t3)">ID</th><th style="text-align:left;padding:4px 8px;font-size:10px;color:var(--t3)">Name</th><th style="text-align:left;padding:4px 8px;font-size:10px;color:var(--t3)">Position</th><th style="text-align:left;padding:4px 8px;font-size:10px;color:var(--t3)">Phone</th></tr></thead><tbody>';
            d.staff.forEach(function(s){
              h+='<tr style="border-bottom:1px solid var(--border)"><td style="padding:4px 8px;font-family:var(--font-m);font-size:11px">'+esc(s.id)+'</td><td style="padding:4px 8px">'+esc(s.name)+'</td><td style="padding:4px 8px;color:var(--t2)">'+esc(s.position||'-')+'</td><td style="padding:4px 8px;color:var(--t3)">'+esc(s.phone||'-')+'</td></tr>';
            });
            h+='</tbody></table>';
          }

          // Active job openings
          if(d.openings && d.openings.length){
            h+='<div style="font:600 11px/1.3 var(--font-b);color:#d97706;margin-bottom:6px"><i class="fa fa-briefcase" style="margin-right:4px"></i> Active Openings ('+d.openings.length+')</div>';
            h+='<table style="width:100%;font-size:12px;border-collapse:collapse">';
            h+='<thead><tr style="border-bottom:1px solid var(--border)"><th style="text-align:left;padding:4px 8px;font-size:10px;color:var(--t3)">Job</th><th style="text-align:left;padding:4px 8px;font-size:10px;color:var(--t3)">Filled / Total</th><th style="text-align:left;padding:4px 8px;font-size:10px;color:var(--t3)">Remaining</th><th style="text-align:left;padding:4px 8px;font-size:10px;color:var(--t3)">Status</th><th style="text-align:left;padding:4px 8px;font-size:10px;color:var(--t3)">Deadline</th></tr></thead><tbody>';
            d.openings.forEach(function(j){
              var progPct = j.total_positions > 0 ? Math.round((j.filled_positions / j.total_positions) * 100) : 0;
              h+='<tr style="border-bottom:1px solid var(--border)">';
              h+='<td style="padding:4px 8px"><a href="<?= base_url("ats") ?>" style="color:var(--gold);text-decoration:none">'+esc(j.title)+'</a></td>';
              h+='<td style="padding:4px 8px">'+j.filled_positions+' / '+j.total_positions+' <div style="height:4px;background:var(--border);border-radius:2px;margin-top:2px;width:80px"><div style="height:4px;background:var(--gold);border-radius:2px;width:'+progPct+'%"></div></div></td>';
              h+='<td style="padding:4px 8px;font-weight:600;color:'+(j.remaining > 0 ? '#d97706' : '#0f766e')+'">'+j.remaining+'</td>';
              h+='<td style="padding:4px 8px">'+badgeHtml(j.status)+'</td>';
              h+='<td style="padding:4px 8px;color:var(--t3);font-size:11px">'+esc(j.deadline||'-')+'</td>';
              h+='</tr>';
            });
            h+='</tbody></table>';
          }

          h+='</td></tr>';
        }
      });
      $tb.html(h);
    });
  }

  function openDeptModal(id){
    $('#deptId').val('');
    $('#deptName').val('');
    $('#deptDesc').val('');
    fillStaffSelect('#deptHead');
    $('#modalDeptTitle').text('Add Department');
    if(id && deptCache[id]){
      var d=deptCache[id];
      $('#deptId').val(id);
      $('#deptName').val(d.name);
      $('#deptDesc').val(d.description);
      fillStaffSelect('#deptHead', d.head_staff_id);
      $('#modalDeptTitle').text('Edit Department');
    }
    openModal('modalDept');
  }

  function saveDept(){
    var name=$('#deptName').val().trim();
    if(!name){ toast('Department name is required','error'); return; }
    var data={
      id: $('#deptId').val(),
      name: name,
      head_staff_id: $('#deptHead').val(),
      description: $('#deptDesc').val().trim()
    };
    post('hr/save_department', data).then(function(r){
      if(r&&r.status){ toast(r.message||'Saved','success'); closeModal('modalDept'); loadDepartments(); }
      else toast(r.message||'Failed','error');
    });
  }

  function deleteDept(id){
    if(!confirm('Delete this department? This cannot be undone.')) return;
    post('hr/delete_department', {id:id}).then(function(r){
      if(r&&r.status){ toast('Deleted','success'); loadDepartments(); }
      else toast(r.message||'Failed','error');
    });
  }

  function seedDepartments(){
    var defaults = [
      {name:'Academic',       description:'Teaching staff, curriculum planning, and academic coordination'},
      {name:'Administration', description:'School administration, office management, and general operations'},
      {name:'Finance',        description:'Fees collection, accounts, budgeting, and financial management'},
      {name:'HR',             description:'Human resources, recruitment, payroll, and staff welfare'},
      {name:'Operations',     description:'Facilities, maintenance, transport, and day-to-day operations'},
      {name:'Library',        description:'Library management, cataloguing, and reading programs'},
      {name:'Medical',        description:'Health services, infirmary, and student wellness'},
      {name:'Admissions',     description:'Student admissions, enrollment, and registration'}
    ];
    // Filter out already-existing departments (case-insensitive)
    var existingNames = [];
    $.each(deptCache, function(k,v){ existingNames.push((v.name||'').toLowerCase()); });
    var toAdd = defaults.filter(function(d){ return existingNames.indexOf(d.name.toLowerCase()) === -1; });

    if(toAdd.length === 0){
      toast('All suggested departments already exist.','info');
      return;
    }

    if(!confirm('This will add ' + toAdd.length + ' department(s):\n\n' + toAdd.map(function(d){return '• ' + d.name;}).join('\n') + '\n\nAlready existing departments will be skipped. Proceed?')) return;

    var btn = document.getElementById('seedDeptBtn');
    if(btn){ btn.disabled=true; btn.innerHTML='<i class="fa fa-spinner fa-spin"></i> Seeding...'; }

    var pending = toAdd.length, added = 0, failed = 0;
    toAdd.forEach(function(d){
      post('hr/save_department', {name:d.name, description:d.description, status:'Active'}).then(function(r){
        if(r && r.status === 'success') added++; else failed++;
        pending--;
        if(pending === 0){
          if(btn){ btn.disabled=false; btn.innerHTML='<i class="fa fa-magic"></i> Seed Suggestions'; }
          toast(added + ' department(s) added' + (failed ? ', ' + failed + ' failed' : ''), added ? 'success' : 'error');
          loadDepartments();
          loadDeptCache();
        }
      });
    });
  }

  /* ================================================================
     RECRUITMENT
  ================================================================ */
  function loadJobs(){
    var params = '?status='+($('#filterJobStatus').val()||'')+'&department='+($('#filterJobDept').val()||'');
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
    $('#modalJobTitle').text('New Job Posting');
    openModal('modalJob');
  }

  function editJob(id){
    // Use cached jobs data instead of a separate API call
    if(!_jobsCache[id]){ toast('Job not found in cache — refreshing','warning'); loadJobs(); return; }
    var j=_jobsCache[id];
    $('#jobId').val(id);
    $('#jobTitle').val(j.title||'');
    fillDeptSelect('#jobDept', j.department);
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

  function deleteJob(id){
    if(!confirm('Delete this job posting and all its applicants?')) return;
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
    $('#applicantStatus').val('applied');
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

  function quickApplicantStatus(id, newStatus){
    var label = newStatus==='Selected'?'Accept':newStatus==='Rejected'?'Reject':'Mark as Joined';
    if(!confirm(label+' this applicant?')) return;
    post('hr/update_applicant_status', {id:id, job_id:currentJobId, status:newStatus}).then(function(r){
      if(r&&r.status){ toast(newStatus+' successfully','success'); loadApplicants(currentJobId,$('#applicantsJobTitle').text()); loadJobs(); }
      else toast(r&&r.message||'Failed','error');
    });
  }

  function saveApplicant(){
    var name=$('#applicantName').val().trim();
    if(!name){ toast('Name is required','error'); return; }
    post('hr/save_applicant', {
      id:$('#applicantId').val(), job_id:$('#applicantJobId').val(),
      name:name, email:$('#applicantEmail').val().trim(), phone:$('#applicantPhone').val().trim(),
      status:$('#applicantStatus').val(), interview_date:$('#applicantInterview').val(),
      interview_notes:$('#applicantNotes').val().trim(), rating:$('#applicantRating').val()
    }).then(function(r){
      if(r&&r.status){ toast(r.message||'Saved','success'); closeModal('modalApplicant'); loadApplicants(currentJobId,$('#applicantsJobTitle').text()); loadJobs(); }
      else toast(r.message||'Failed','error');
    });
  }

  function deleteApplicant(id){
    if(!confirm('Delete this applicant?')) return;
    post('hr/delete_applicant', {id:id, job_id:currentJobId}).then(function(r){
      if(r&&r.status){ toast('Deleted','success'); loadApplicants(currentJobId,$('#applicantsJobTitle').text()); }
      else toast(r.message||'Failed','error');
    });
  }

  /* ================================================================
     LEAVE MANAGEMENT
  ================================================================ */
  var SEED_CODES = ['CL','SL','EL','MATERNITY','PATERNITY','LWP','COMP_OFF','ACADEMIC','DUTY'];

  function loadLeaveTypes(){
    getJSON('hr/get_leave_types').then(function(r){
      leaveTypeCache = toMap((r&&r.leave_types)?r.leave_types:(r&&r.data)?r.data:{});
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
      if(r && r.status){
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
      paid:$('#ltPaid').val(), description:$('#ltDescription').val().trim()
    }).then(function(r){
      if(r&&r.status){ toast(r.message||'Saved','success'); closeModal('modalLeaveType'); loadLeaveTypes(); }
      else toast(r.message||'Failed','error');
    });
  }

  function deleteLeaveType(id){
    if(!confirm('Delete this leave type?')) return;
    post('hr/delete_leave_type', {id:id}).then(function(r){
      if(r&&r.status){ toast('Deleted','success'); loadLeaveTypes(); }
      else toast(r.message||'Failed','error');
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
    var lwp  = parseInt(l.lwp_days||0);
    var days = parseInt(l.days||0);
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
        var pd = parseInt(l.paid_days||0), lw = parseInt(l.lwp_days||0);
        if(pd>0 && lw>0) daysCol += ' <small style="color:var(--t3)">('+pd+'P+'+lw+'L)</small>';

        h+='<tr><td class="hr-num">'+i+'</td><td>'+esc(l.staff_name||staffName(l.staff_id))+'</td>';
        h+='<td>'+esc(l.type_name||l.leave_type||l.type_code)+code+'</td>';
        h+='<td>'+esc(l.from_date||l.start_date)+'</td><td>'+esc(l.to_date||l.end_date)+'</td>';
        h+='<td class="hr-num">'+daysCol+'</td>';
        h+='<td>'+payLabelBadge(label)+'</td>';
        h+='<td>'+badgeHtml(l.status)+'</td>';
        h+='<td>';
        if((l.status||'').toLowerCase()==='pending'){
          h+='<button class="hr-act-btn" onclick="event.stopPropagation();this.disabled=true;HR.approveLeave(\''+esc(k)+'\')" title="Approve"><i class="fa fa-check" style="pointer-events:none"></i></button> ';
          h+='<button class="hr-act-btn danger" onclick="event.stopPropagation();this.disabled=true;HR.rejectLeave(\''+esc(k)+'\')" title="Reject"><i class="fa fa-times" style="pointer-events:none"></i></button>';
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
      if(r&&r.status){
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
  function confirmLeaveAction(){
    var id=$('#leaveActionId').val(), action=$('#leaveActionType').val();
    var decision = action === 'approved' ? 'Approved' : 'Rejected';
    var $btn=$('#btnLeaveAction');
    var origHtml=$btn.html();
    $btn.prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Processing...');
    post('hr/decide_leave', {id:id, decision:decision, remarks:$('#leaveActionRemarks').val().trim()}).then(function(r){
      $btn.prop('disabled',false).html(origHtml);
      if(r&&r.status){
        var msg = r.message||'Updated';
        if(r.lwp_days && parseInt(r.lwp_days)>0){ toast(msg,'warning'); } else { toast(msg,'success'); }
        closeModal('modalLeaveAction'); loadLeaveRequests();
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

  function initBalances(){
    if(!confirm('Initialize leave balances for all staff based on leave type definitions? Existing balances will not be overwritten.')) return;
    post('hr/allocate_leave_balances').then(function(r){
      if(r&&r.status){ toast(r.message||'Initialized','success'); loadLeaveBalances(); }
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
          h+='<div class="hr-slip-row"><span class="hr-slip-label">LWP Deduction ('+parseInt(sl.lwp_days||0)+' days)</span><span class="hr-slip-value amber">-'+fmt(sl.lwp_deduction)+'</span></div>';
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
            if(parseInt(sl.lwp_days)>0) h+='<div class="hr-slip-row"><span class="hr-slip-label">LWP Days</span><span class="hr-slip-value amber">'+(sl.lwp_days||0)+'</span></div>';
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
        $banner.html('<div style="padding:8px 16px;margin-bottom:12px;border-radius:8px;background:rgba(15,118,110,.06);border:1px solid rgba(15,118,110,.15);font:400 12px/1.5 var(--font-m);color:#0f766e;display:flex;align-items:center;gap:8px">'
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
        var srcTag = (s.source==='registration') ? ' <span style="font-size:9px;padding:1px 5px;border-radius:3px;background:rgba(217,119,6,.1);color:#d97706;font-weight:600">Auto</span>' : (s.source==='manual' ? ' <span style="font-size:9px;padding:1px 5px;border-radius:3px;background:rgba(15,118,110,.1);color:#0f766e;font-weight:600">HR</span>' : '');
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
      if(r&&r.status){ toast(r.message||'Saved','success'); closeModal('modalSalary'); loadSalaryStructures(); }
      else toast(r.message||'Failed','error');
    });
  }

  function deleteSalary(id){
    var name=staffName(id);
    if(!confirm('Delete salary structure for "'+name+'"?\n\nThis will be archived for audit purposes.\nIf this staff has active payroll, deletion will be blocked.')) return;
    post('hr/delete_salary_structure', {id:id}).then(function(r){
      if(r&&r.status==='success'){ toast(r.message||'Deleted and archived','success'); loadSalaryStructures(); }
      else toast(r&&r.message||'Failed to delete','error');
    });
  }

  function backfillStructures(){
    if(!confirm('Auto-create salary structures for all staff who are missing one?\n\nThis uses registration salary data (Basic + Allowances) and applies default deduction rates.\n\nExisting structures will NOT be changed.')) return;
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

  function approvePayroll(id){
    if(!confirm('Approve this payroll run for payment?\n\nThis confirms the salary calculations are correct.')) return;
    post('hr/approve_payroll', {run_id:id}).then(function(r){
      if(r&&r.status==='success'){ toast(r.message||'Approved','success'); loadPayrollRuns(); }
      else toast(r.message||'Failed','error');
    });
  }

  function exportPayroll(type, runId){
    window.open(BASE+'hr/export_payroll_report?type='+encodeURIComponent(type)+'&run_id='+encodeURIComponent(runId), '_blank');
  }

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
        h+='<tr><td class="hr-num">'+i+'</td><td class="hr-num" style="font-size:11px">'+esc(rid)+'</td>';
        h+='<td>'+(pr.month||'')+'</td><td class="hr-num">'+(pr.year||'')+'</td>';
        h+='<td class="hr-num">'+(pr.staff_count||0)+'</td>';
        h+='<td class="hr-num">'+fmt(pr.total_gross)+'</td>';
        h+='<td class="hr-num" style="color:#d97706">'+fmt(pr.total_deductions)+'</td>';
        h+='<td class="hr-num"><strong style="color:var(--gold)">'+fmt(pr.total_net)+'</strong></td>';
        h+='<td>'+payrollBadge(pr.status)+'</td>';
        h+='<td>'+payInfo+'</td>';
        h+='<td style="white-space:nowrap">';
        h+='<button class="hr-act-btn" onclick="HR.viewPayslips(\''+esc(rid)+'\')" title="View Payslips"><i class="fa fa-file-text-o"></i></button> ';
        if(st==='draft') h+='<button class="hr-act-btn" onclick="HR.finalizeRun(\''+esc(rid)+'\')" title="Finalize"><i class="fa fa-lock"></i></button> ';
        if(st==='finalized') h+='<button class="hr-act-btn" style="background:#fef3c7;color:#92400e" onclick="HR.approvePayroll(\''+esc(rid)+'\')" title="Approve"><i class="fa fa-check-circle"></i></button> ';
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
    var h='<div style="margin:14px 0;padding:14px;border-radius:8px;background:rgba(15,118,110,.07);border:1px solid rgba(15,118,110,.18)">';
    h+='<div style="font:600 13px/1.4 var(--font-b);color:#0f766e;margin-bottom:6px"><i class="fa fa-check-circle"></i> Pre-flight Check Passed</div>';
    h+='<div style="font:400 12px/1.5 var(--font-m);color:var(--t2)">Staff covered: <strong>'+(r.staff_covered||0)+'</strong> / '+(r.staff_total||0)+'</div>';
    var w=r.warnings||[];
    if(w.length){
      h+='<div style="margin-top:8px;padding-top:8px;border-top:1px dashed var(--border)">';
      h+='<div style="font:600 11px/1.3 var(--font-b);color:#d97706;margin-bottom:4px"><i class="fa fa-exclamation-circle"></i> Warnings</div>';
      h+='<ul style="margin:0;padding-left:18px;font:400 11px/1.6 var(--font-m);color:var(--t2)">';
      for(var i=0;i<w.length;i++) h+='<li>'+esc(w[i])+'</li>';
      h+='</ul></div>';
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
            toast(r2.message||'Payroll generated successfully','success');
            closeModal('modalGenPayroll');
            loadPayrollRuns();
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

  function finalizeRun(id){
    if(window._finalizeInFlight) return;
    if(!confirm('Finalize this payroll run? Draft payslips will be locked.')) return;
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

  function deletePayrollRun(id){
    if(!confirm('Delete this draft payroll run and all its payslips? This cannot be undone.')) return;
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
      $('#payslipRunInfo').html('Run: '+esc(runData.id||runId)+' '+payrollBadge(runData.status||'Draft')).show();

      if(!keys.length){ $tb.html('<tr><td colspan="13" class="hr-empty"><i class="fa fa-inbox"></i> No payslips in this run.</td></tr>'); return; }
      var h='', i=0;
      $.each(slips, function(k,p){
        i++;
        var slipStatus = (p.status||'Draft').toLowerCase();
        var allowances=(parseFloat(p.hra)||0)+(parseFloat(p.da)||0)+(parseFloat(p.ta)||0)+(parseFloat(p.medical)||0)+(parseFloat(p.other_allowances)||0);
        h+='<tr class="hr-payslip-row" data-id="'+esc(k)+'" style="cursor:pointer">';
        h+='<td class="hr-num">'+i+'</td><td>'+esc(p.staff_name||staffName(p.staff_id||k))+'</td>';
        h+='<td class="hr-num">'+fmt(p.basic)+'</td><td class="hr-num">'+fmt(allowances)+'</td>';
        h+='<td class="hr-num"><strong>'+fmt(p.gross)+'</strong></td><td class="hr-num">'+fmt(p.total_deductions)+'</td>';
        h+='<td class="hr-num"><strong style="color:var(--gold)">'+fmt(p.net_pay)+'</strong></td>';
        h+='<td class="hr-num">'+(p.days_worked||'-')+'</td><td class="hr-num">'+(p.days_absent||0)+'</td>';
        h+='<td class="hr-num">'+(p.paid_leave_days||0)+'</td>';
        h+='<td class="hr-num">'+(p.lwp_days||0)+'</td>';
        h+='<td>'+payrollBadge(p.status||'Draft')+'</td>';
        h+='<td><i class="fa fa-chevron-down" style="color:var(--t3)"></i></td></tr>';
        // Detail row — structured payslip breakdown
        h+='<tr class="hr-payslip-detail" id="detail_'+esc(k)+'">';
        h+='<td colspan="13"><div class="hr-payslip-breakdown">';
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
        if(parseFloat(p.pf_employee))      h+='<div class="hr-slip-row"><span class="hr-slip-label">PF (Employee)</span><span class="hr-slip-value">'+fmt(p.pf_employee)+'</span></div>';
        if(parseFloat(p.esi_employee))     h+='<div class="hr-slip-row"><span class="hr-slip-label">ESI (Employee)</span><span class="hr-slip-value">'+fmt(p.esi_employee)+'</span></div>';
        if(parseFloat(p.professional_tax)) h+='<div class="hr-slip-row"><span class="hr-slip-label">Professional Tax</span><span class="hr-slip-value">'+fmt(p.professional_tax)+'</span></div>';
        if(parseFloat(p.tds))              h+='<div class="hr-slip-row"><span class="hr-slip-label">TDS (Income Tax)</span><span class="hr-slip-value">'+fmt(p.tds)+'</span></div>';
        if(parseFloat(p.other_deductions)) h+='<div class="hr-slip-row"><span class="hr-slip-label">Other Deductions</span><span class="hr-slip-value">'+fmt(p.other_deductions)+'</span></div>';
        if(parseFloat(p.lwp_deduction)>0){
          h+='<div class="hr-slip-row"><span class="hr-slip-label">LWP Deduction ('+parseInt(p.lwp_days||0)+' days)</span><span class="hr-slip-value amber">-'+fmt(p.lwp_deduction)+'</span></div>';
        }
        h+='<div class="hr-slip-row total"><span class="hr-slip-label">Total Deductions</span><span class="hr-slip-value red">'+fmt(p.total_deductions)+'</span></div>';
        h+='</div>';

        h+='</div>'; // end hr-slip-sections

        // ── NET PAY highlight + Download ──
        h+='<div class="hr-slip-net-row">';
        h+='<span class="hr-slip-net-label"><i class="fa fa-inr" style="margin-right:4px"></i>Net Pay</span>';
        h+='<span class="hr-slip-net-value">'+fmt(p.net_pay)+'</span>';
        h+='</div>';
        h+='<div style="text-align:center;margin-top:8px">';
        h+='<a class="hr-btn hr-btn-ghost hr-btn-sm" href="'+BASE+'hr/download_payslip?run_id='+encodeURIComponent(currentRunId)+'&staff_id='+encodeURIComponent(p.staff_id||k)+'" target="_blank"><i class="fa fa-file-pdf-o"></i> Download Payslip PDF</a>';
        h+='</div>';

        // ── Working Info + Leave (bottom row, 2 cols) ──
        var hasWorkInfo = p.working_days||p.daily_salary;
        var hasLeaves = p.leave_details && typeof p.leave_details==='object' && Object.keys(p.leave_details).length;
        if(hasWorkInfo||hasLeaves||slipStatus==='paid'){
          h+='<div class="hr-slip-sections" style="margin-top:14px">';

          // Working info
          if(hasWorkInfo){
            h+='<div class="hr-slip-section">';
            h+='<div class="hr-slip-section-hd info"><i class="fa fa-calendar"></i> Working Info</div>';
            // Working days breakdown (from run data)
            if(runData.days_in_month){
              h+='<div class="hr-slip-row" style="opacity:.7"><span class="hr-slip-label">Total Days in Month</span><span class="hr-slip-value">'+(runData.days_in_month||'-')+'</span></div>';
              h+='<div class="hr-slip-row" style="opacity:.7"><span class="hr-slip-label">Sundays</span><span class="hr-slip-value">-'+(runData.sundays||0)+'</span></div>';
              if(parseInt(runData.off_saturdays)>0) h+='<div class="hr-slip-row" style="opacity:.7"><span class="hr-slip-label">2nd/4th Saturdays</span><span class="hr-slip-value">-'+(runData.off_saturdays||0)+'</span></div>';
              if(parseInt(runData.holidays)>0) h+='<div class="hr-slip-row" style="opacity:.7"><span class="hr-slip-label">Holidays</span><span class="hr-slip-value">-'+(runData.holidays||0)+'</span></div>';
            }
            h+='<div class="hr-slip-row"><span class="hr-slip-label"><strong>Working Days</strong></span><span class="hr-slip-value"><strong>'+(p.working_days||'-')+'</strong></span></div>';
            h+='<div class="hr-slip-row"><span class="hr-slip-label">Days Worked</span><span class="hr-slip-value">'+(p.days_worked||'-')+'</span></div>';
            h+='<div class="hr-slip-row"><span class="hr-slip-label">Days Absent</span><span class="hr-slip-value">'+(p.days_absent||0)+'</span></div>';
            if(parseInt(p.lwp_days)>0) h+='<div class="hr-slip-row"><span class="hr-slip-label">LWP Days</span><span class="hr-slip-value amber">'+(p.lwp_days||0)+'</span></div>';
            h+='<div class="hr-slip-row"><span class="hr-slip-label">Daily Salary</span><span class="hr-slip-value">'+fmt(p.daily_salary)+'</span></div>';
            if(p.deduction_reason && p.deduction_reason!=='No deduction') h+='<div class="hr-slip-row"><span class="hr-slip-label">Deduction Reason</span><span class="hr-slip-value" style="font-size:11px;max-width:180px;text-align:right">'+esc(p.deduction_reason)+'</span></div>';
            h+='</div>';
          }

          // Leave breakdown
          if(hasLeaves){
            h+='<div class="hr-slip-section">';
            h+='<div class="hr-slip-section-hd leave"><i class="fa fa-calendar-minus-o"></i> Leave Breakdown</div>';
            $.each(p.leave_details, function(idx, ld){
              var paidTag = ld.paid ? ' <span style="color:var(--gold);font-size:10px">(Paid)</span>' : ' <span style="color:#991b1b;font-size:10px">(LWP)</span>';
              var label = esc(ld.type_code||ld.type_name||'Leave') + paidTag;
              var dateRange = ld.from ? '<div style="font-size:10px;color:var(--t3);margin-top:1px">'+esc(ld.from)+' to '+esc(ld.to||'?')+'</div>' : '';
              h+='<div class="hr-slip-row" style="flex-wrap:wrap"><span class="hr-slip-label">'+label+dateRange+'</span><span class="hr-slip-value">'+esc(''+(ld.total_days||ld.days||0))+' day(s)</span></div>';
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
      if(parseInt(e.paid_days)>0) details += ' <small style="color:var(--gold)">('+esc(e.paid_days)+' paid)</small>';
      if(parseInt(e.lwp_days)>0) details += ' <small style="color:#991b1b">('+esc(e.lwp_days)+' LWP)</small>';
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

  function submitAppraisal(id){
    if(!confirm('Submit this appraisal for review? It cannot be edited afterwards.')) return;
    post('hr/submit_appraisal', {id:id}).then(function(r){
      if(r&&r.status==='success'){ toast(r.message||'Submitted','success'); }
      else toast((r&&r.message)||'Failed to submit','error');
    }).fail(function(){
      toast('Server error — refreshing list','error');
    }).always(function(){ loadAppraisals(); });
  }

  function reviewAppraisal(id){
    var comments = prompt('Optional reviewer comments:','');
    if(comments === null) return; // cancelled
    post('hr/review_appraisal', {id:id, comments:comments}).then(function(r){
      if(r&&r.status==='success'){ toast(r.message||'Reviewed','success'); }
      else toast((r&&r.message)||'Failed to mark reviewed','error');
    }).fail(function(){
      toast('Server error — refreshing list','error');
    }).always(function(){ loadAppraisals(); });
  }

  function deleteAppraisal(id){
    if(!confirm('Delete this appraisal?')) return;
    post('hr/delete_appraisal', {id:id}).then(function(r){
      if(r&&r.status==='success'){ toast('Deleted','success'); }
      else toast((r&&r.message)||'Failed to delete','error');
    }).fail(function(){
      toast('Server error — refreshing list','error');
    }).always(function(){ loadAppraisals(); });
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
    w.document.write('<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">');
    w.document.write('<style>body{margin:20px;font-family:"Segoe UI",system-ui,sans-serif;}@media print{body{margin:0;}}</style>');
    w.document.write('</head><body>');
    w.document.write(content);
    w.document.write('<script>setTimeout(function(){window.print();},500);<\/script>');
    w.document.write('</body></html>');
    w.document.close();
  }

  function regenCircular(){
    if(!_circularJobId) return;
    if(!confirm('Regenerate the circular for this job posting? The old circular will be replaced.')) return;
    var $btn = $('#btnRegenCirc').prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Regenerating...');
    post('hr/regenerate_circular', { job_id: _circularJobId }).then(function(r){
      $btn.prop('disabled',false).html('<i class="fa fa-refresh"></i> Regenerate');
      if(r && r.status){
        toast(r.message || 'Regenerated', 'success');
        loadJobs();
        // Refresh poster
        viewCircular(_circularJobId);
      } else {
        toast(r.message || 'Failed', 'error');
      }
    });
  }

  function generateCircular(jobId){
    if(!confirm('Generate a circular for this job posting?')) return;
    post('hr/regenerate_circular', { job_id: jobId }).then(function(r){
      if(r && r.status){
        toast('Circular ' + (r.circular_id||'') + ' generated and published!', 'success', 5000);
        loadJobs();
      } else {
        toast(r.message || 'Failed', 'error');
      }
    });
  }

  /* ── Public API ─────────────────────────────────────────── */
  window.HR = {
    closeModal:       closeModal,
    openDeptModal:    openDeptModal,
    editDept:         function(id){ openDeptModal(id); },
    saveDept:         saveDept,
    deleteDept:       deleteDept,
    seedDepartments:  seedDepartments,
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
    seedLeaveTypes:   seedLeaveTypes,
    seedOnePill:      seedOnePill,
    openLeaveRequestModal: openLeaveRequestModal,
    saveLeaveRequest: saveLeaveRequest,
    loadLeaveRequests: loadLeaveRequests,
    approveLeave:     approveLeave,
    rejectLeave:      rejectLeave,
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
    loadAppraisals:   loadAppraisals
  };

  /* ── Boot ───────────────────────────────────────────────── */
  init();

})();
}); /* end DOMContentLoaded */
</script>
