<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Compensation administration (ZenX HRMS W2.5).
 *
 * Follows the existing Admin Panel conventions used by views/hr/index.php:
 * content-wrapper → section.content → module wrap, icon header + breadcrumb,
 * anchor tabs that all route to one controller method, panels toggled by the
 * active tab, inline <style>, jQuery AJAX. No new frontend framework.
 *
 * Four tabs, in the order an administrator actually needs them:
 *   Components → what a pay element IS
 *   Grades     → the bands salaries must sit inside
 *   Statutory  → PF / ESI / PT / TDS policy, effective-dated
 *   Readiness  → who can and cannot be paid, by name
 */
$at = isset($active_tab) ? $active_tab : 'components';
$tabs = [
    'components'  => ['panel' => 'panelComponents',  'icon' => 'fa-th-list',        'label' => 'Components'],
    'templates'   => ['panel' => 'panelTemplates',   'icon' => 'fa-clone',          'label' => 'Structures'],
    'grades'      => ['panel' => 'panelGrades',      'icon' => 'fa-bars',           'label' => 'Pay Grades'],
    'statutory'   => ['panel' => 'panelStatutory',   'icon' => 'fa-balance-scale',  'label' => 'Statutory'],
    'classification' => ['panel' => 'panelClassification', 'icon' => 'fa-tags',    'label' => 'Wage Definition'],
    'assignments' => ['panel' => 'panelAssignments', 'icon' => 'fa-user',           'label' => 'Employee Salary'],
    'fnf'         => ['panel' => 'panelFnf',         'icon' => 'fa-sign-out',       'label' => 'Full &amp; Final'],
    'readiness'   => ['panel' => 'panelReadiness',   'icon' => 'fa-check-square-o', 'label' => 'Payroll Readiness'],
];
$canManage = !empty($can_manage);
?>

<style>
.cmp-wrap{padding:6px 2px 40px}
.cmp-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:14px;flex-wrap:wrap}
.cmp-header-icon{font-size:20px;font-weight:700;color:#2c3e50}
.cmp-breadcrumb{list-style:none;padding:0;margin:6px 0 0;font-size:12px;color:#8a97a5}
.cmp-breadcrumb li{display:inline}
.cmp-breadcrumb li+li:before{content:"\00a0/\00a0"}
.cmp-tabs{display:flex;gap:4px;flex-wrap:wrap;border-bottom:2px solid #e6ebf0;margin-bottom:18px}
.cmp-tab{padding:9px 16px;font-size:13px;font-weight:600;color:#5a6a7a;border:1px solid transparent;border-bottom:none;
  border-radius:6px 6px 0 0;text-decoration:none;margin-bottom:-2px}
.cmp-tab:hover{background:#f4f7fa;color:#2c3e50}
.cmp-tab.active{background:#fff;border-color:#e6ebf0;border-bottom:2px solid #fff;color:#BC5A3C}
/* UX-P2-1: at narrow widths the sidebar leaves too little room for eight
   wrapped tab labels, and one (Employee Salary) rendered visually clipped
   rather than cleanly wrapped. Below the point where two tabs plus padding
   no longer fit, the strip scrolls horizontally INSIDE its own container
   instead — same principle this stylesheet already applies to wide tables
   (.cmp-tblwrap) — so every label stays intact and reachable, and the page
   itself never gains a horizontal scrollbar. Desktop keeps the existing
   wrap layout untouched. */
@media (max-width:560px){
  .cmp-tabs{flex-wrap:nowrap;overflow-x:auto;-webkit-overflow-scrolling:touch;margin-bottom:0}
  .cmp-tab{flex:0 0 auto;white-space:nowrap}
}
.cmp-panel{display:none}.cmp-panel.active{display:block}
.cmp-card{background:#fff;border:1px solid #e6ebf0;border-radius:8px;margin-bottom:16px;overflow:hidden}
.cmp-card-hd{padding:12px 16px;border-bottom:1px solid #eef2f6;background:#fbfcfd;display:flex;align-items:center;gap:10px}
.cmp-card-hd h3{margin:0;font-size:14px;font-weight:700;color:#2c3e50}
.cmp-card-hd .hint{margin-left:auto;font-size:12px;color:#8a97a5;font-weight:400}
.cmp-card-bd{padding:14px 16px}
.cmp-tblwrap{overflow-x:auto}
.cmp-tbl{width:100%;border-collapse:collapse;font-size:13px;min-width:640px}
.cmp-tbl th,.cmp-tbl td{padding:8px 10px;border-bottom:1px solid #eef2f6;text-align:left;vertical-align:middle}
.cmp-tbl th{background:#f7f9fb;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#7b8794;font-weight:700}
.cmp-tbl td.num,.cmp-tbl th.num{text-align:right;font-variant-numeric:tabular-nums}
.cmp-tbl tr:hover td{background:#fbfcfd}
.cmp-badge{display:inline-block;font-size:10px;font-weight:700;padding:2px 7px;border-radius:4px;text-transform:uppercase;letter-spacing:.3px}
.b-earn{background:#e6f6ec;color:#1a7f43}.b-ded{background:#fdeaea;color:#c0392b}
.b-emp{background:#eaf1fb;color:#2b6cb0}.b-lock{background:#f0eefb;color:#5b4bbd}
.b-off{background:#f1f3f5;color:#868e96}
.cmp-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}
.cmp-field label{display:block;font-size:12px;font-weight:600;color:#4a5568;margin-bottom:4px}
.cmp-field label .req{color:#c0392b}
.cmp-field input,.cmp-field select{width:100%;padding:7px 9px;font-size:13px;border:1px solid #d7dee6;border-radius:5px}
.cmp-field input:focus,.cmp-field select:focus{outline:none;border-color:#BC5A3C;box-shadow:0 0 0 3px rgba(188,90,60,.12)}
.cmp-help{font-size:11px;color:#8a97a5;margin-top:3px;line-height:1.4}
.cmp-actions{margin-top:14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.cmp-btn{padding:8px 15px;font-size:13px;font-weight:600;border-radius:6px;border:1px solid #d7dee6;background:#fff;color:#4a5568;cursor:pointer}
.cmp-btn:hover{border-color:#BC5A3C;color:#BC5A3C}
.cmp-btn.primary{background:#BC5A3C;border-color:#BC5A3C;color:#fff}
.cmp-btn.primary:hover{background:#9E4830;color:#fff}
.cmp-btn:disabled{opacity:.5;cursor:not-allowed}
.cmp-msg{padding:10px 13px;border-radius:6px;font-size:13px;margin-bottom:12px;display:none;line-height:1.5}
.cmp-msg.err{background:#fdeaea;border:1px solid #f5c6c6;color:#a93226;display:block}
.cmp-msg.ok{background:#e6f6ec;border:1px solid #b7e2c6;color:#1a7f43;display:block}
.cmp-note{background:#fff8e6;border:1px solid #ffe0a3;color:#8a6d1f;border-radius:6px;padding:11px 14px;font-size:12.5px;line-height:1.55;margin-bottom:14px}
.cmp-empty{padding:26px;text-align:center;color:#8a97a5;font-size:13px}
.cmp-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}
.cmp-tile{background:#fff;border:1px solid #e6ebf0;border-radius:8px;padding:14px 16px}
.cmp-tile .k{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#8a97a5}
.cmp-tile .v{font-size:26px;font-weight:800;margin-top:4px;line-height:1}
.cmp-tile.ok .v{color:#1a7f43}.cmp-tile.warn .v{color:#b7791f}.cmp-tile.bad .v{color:#c0392b}
.cmp-list{list-style:none;padding:0;margin:0}
.cmp-list li{padding:9px 12px;border:1px solid #eef2f6;border-radius:6px;margin-bottom:7px;font-size:13px;display:flex;gap:10px;align-items:flex-start}
.cmp-list li .who{font-weight:600;color:#2c3e50;flex:0 0 auto;min-width:150px}
.cmp-list li .why{color:#5a6a7a}
.cmp-list li.blk{background:#fdf6f6;border-color:#f5c6c6}
.cmp-list li.wrn{background:#fffaf0;border-color:#ffe0a3}
.cmp-ro{background:#f7f9fb;color:#7b8794}
</style>

<div class="content-wrapper">
<section class="content">
<div class="cmp-wrap">

  <div class="cmp-header">
    <div>
      <div class="cmp-header-icon"><i class="fa fa-money"></i> Compensation &amp; Statutory Setup</div>
      <ol class="cmp-breadcrumb">
        <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li><a href="<?= base_url('hr') ?>">HR</a></li>
        <li>Compensation</li>
      </ol>
    </div>
  </div>

  <nav class="cmp-tabs">
    <?php foreach ($tabs as $slug => $t): ?>
      <a class="cmp-tab<?= $at === $slug ? ' active' : '' ?>" href="<?= base_url('compensation/' . $slug) ?>">
        <i class="fa <?= $t['icon'] ?>"></i> <?= $t['label'] ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$canManage): ?>
    <div class="cmp-note"><i class="fa fa-lock"></i>
      You have <b>view</b> access to compensation. Editing requires the HR <b>manage</b> permission,
      so the forms below are hidden.</div>
  <?php endif; ?>

  <!-- ══════════ COMPONENTS ══════════ -->
  <div class="cmp-panel<?= $at === 'components' ? ' active' : '' ?>" id="panelComponents">
    <div id="cmpMsgComp" class="cmp-msg"></div>

    <div class="cmp-note">
      A component defines <b>what a pay element is</b> — an earning, a deduction or an employer
      contribution, and how it is calculated. Per-employee <em>amounts</em> live on the salary
      assignment, not here. The thirteen system components describe the behaviour the existing
      payroll calculator already performs; their structure is locked so the catalogue cannot
      start describing something the calculator does not do.
    </div>

    <div class="cmp-card">
      <div class="cmp-card-hd">
        <h3><i class="fa fa-th-list"></i> Component catalogue</h3>
        <span class="hint" id="compCount">—</span>
      </div>
      <div class="cmp-card-bd">
        <div class="cmp-tblwrap">
          <table class="cmp-tbl" id="compTbl">
            <thead><tr>
              <th>Code</th><th>Name</th><th>Kind</th><th>Calculation</th><th>Basis</th>
              <th>Statutory</th><th class="num">Order</th><th>Status</th><th></th>
            </tr></thead>
            <tbody><tr><td colspan="9" class="cmp-empty">Loading…</td></tr></tbody>
          </table>
        </div>
        <?php if ($canManage): ?>
        <div class="cmp-actions">
          <button class="cmp-btn" id="btnSeed" type="button">
            <i class="fa fa-magic"></i> Seed the 13 system components
          </button>
          <span class="cmp-help">Safe to run more than once — existing components are never overwritten.</span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($canManage): ?>
    <div class="cmp-card">
      <div class="cmp-card-hd"><h3><i class="fa fa-plus-circle"></i> Add / edit a component</h3>
        <span class="hint">entering an existing code edits that component</span></div>
      <div class="cmp-card-bd">
        <div class="cmp-form">
          <div class="cmp-field"><label>Code <span class="req">*</span></label>
            <input type="text" id="cCode" placeholder="CONVEYANCE" maxlength="40">
            <div class="cmp-help">A–Z, 0–9 and underscore. Cannot be changed later.</div></div>
          <div class="cmp-field"><label>Name <span class="req">*</span></label>
            <input type="text" id="cName" placeholder="Conveyance Allowance" maxlength="80"></div>
          <div class="cmp-field"><label>Kind <span class="req">*</span></label>
            <select id="cKind">
              <option value="earning">Earning</option>
              <option value="deduction">Deduction</option>
              <option value="employer_contribution">Employer contribution</option>
            </select></div>
          <div class="cmp-field"><label>Calculation <span class="req">*</span></label>
            <select id="cCalc">
              <option value="FIXED">Fixed amount</option>
              <option value="PERCENT">Percentage of a basis</option>
              <option value="BALANCING">Balancing (remainder of gross)</option>
            </select>
            <div class="cmp-help">A balancing component absorbs whatever is left of gross, so the
              parts always add up to the whole. Only one per structure.</div></div>
          <div class="cmp-field"><label>Basis</label>
            <select id="cBasis">
              <option value="NONE">Not applicable</option>
              <option value="BASIC">Basic</option>
              <option value="GROSS">Gross</option>
              <option value="CTC">CTC</option>
            </select>
            <div class="cmp-help">Required for a percentage — what the rate applies to.</div></div>
          <div class="cmp-field"><label>Statutory head</label>
            <select id="cStat">
              <option value="">None</option>
              <option value="PF">PF</option><option value="ESI">ESI</option>
              <option value="PT">Professional Tax</option><option value="TDS">TDS</option>
            </select></div>
          <div class="cmp-field"><label>Display order</label>
            <input type="number" id="cOrder" value="0" min="0"></div>
          <div class="cmp-field"><label>Flags</label>
            <div style="font-size:12px;line-height:2">
              <label style="font-weight:400"><input type="checkbox" id="cTaxable"> Taxable</label><br>
              <label style="font-weight:400"><input type="checkbox" id="cPf"> PF applicable</label><br>
              <label style="font-weight:400"><input type="checkbox" id="cEsi"> ESI applicable</label><br>
              <label style="font-weight:400"><input type="checkbox" id="cPro" checked> Prorated by attendance</label>
            </div></div>
        </div>
        <div class="cmp-actions">
          <button class="cmp-btn primary" id="btnSaveComp" type="button"><i class="fa fa-save"></i> Save component</button>
          <button class="cmp-btn" id="btnClearComp" type="button">Clear</button>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ══════════ STRUCTURES / TEMPLATES ══════════ -->
  <div class="cmp-panel<?= $at === 'templates' ? ' active' : '' ?>" id="panelTemplates">
    <div id="cmpMsgTpl" class="cmp-msg"></div>

    <div class="cmp-note">
      A structure is the <b>shape</b> of a salary, not its size — which components it contains and
      at what rate. You then enter one number per employee (their monthly gross) and the components
      derive from it. Include a <b>balancing</b> component so the parts always add up to the whole.
    </div>

    <div class="cmp-card">
      <div class="cmp-card-hd"><h3><i class="fa fa-clone"></i> Salary structures</h3>
        <span class="hint" id="tplCount">—</span></div>
      <div class="cmp-card-bd">
        <div class="cmp-tblwrap">
          <table class="cmp-tbl" id="tplTbl">
            <thead><tr><th>Code</th><th>Name</th><th>Components</th><th>Grade</th><th>Status</th><th></th></tr></thead>
            <tbody><tr><td colspan="6" class="cmp-empty">Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>

    <?php if ($canManage): ?>
    <div class="cmp-card">
      <div class="cmp-card-hd"><h3><i class="fa fa-plus-circle"></i> Define a structure</h3></div>
      <div class="cmp-card-bd">
        <div class="cmp-form">
          <div class="cmp-field"><label>Code <span class="req">*</span></label>
            <input type="text" id="tCode" placeholder="TEACHING" maxlength="40"></div>
          <div class="cmp-field"><label>Name <span class="req">*</span></label>
            <input type="text" id="tName" placeholder="Teaching Staff" maxlength="80"></div>
          <div class="cmp-field"><label>Default pay grade</label>
            <select id="tGrade"><option value="">— none —</option></select></div>
        </div>

        <h4 style="font-size:13px;margin:16px 0 8px;color:#2c3e50">Components in this structure</h4>
        <div class="cmp-tblwrap">
          <table class="cmp-tbl" id="tplLineTbl">
            <thead><tr><th>Component</th><th>Calculation</th><th class="num">Rate % / Amount ₹</th><th></th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <div class="cmp-actions">
          <select id="tAddComp" style="padding:7px 9px;border:1px solid #d7dee6;border-radius:5px;font-size:13px"></select>
          <button class="cmp-btn" id="btnAddLine" type="button"><i class="fa fa-plus"></i> Add component</button>
        </div>
        <div class="cmp-actions">
          <button class="cmp-btn primary" id="btnSaveTpl" type="button"><i class="fa fa-save"></i> Save structure</button>
          <button class="cmp-btn" id="btnClearTpl" type="button">Clear</button>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ══════════ EMPLOYEE SALARY ══════════ -->
  <div class="cmp-panel<?= $at === 'assignments' ? ' active' : '' ?>" id="panelAssignments">
    <div id="cmpMsgAsg" class="cmp-msg"></div>

    <div class="cmp-note">
      An employee's salary is <b>effective-dated</b>. A revision does not overwrite the old one — it
      starts a new period, so past payroll stays reconstructable. Overlapping periods are refused:
      any given date must resolve to exactly one salary.
    </div>

    <div class="cmp-card">
      <div class="cmp-card-hd"><h3><i class="fa fa-user"></i> Employee</h3>
        <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
          <select id="asgStaff" style="min-width:260px;padding:7px 9px;border:1px solid #d7dee6;border-radius:5px;font-size:13px">
            <option value="">— select an employee —</option>
          </select>
          <button class="cmp-btn" id="btnLoadAsg" type="button"><i class="fa fa-search"></i> Load</button>
        </div>
      </div>
      <div class="cmp-card-bd">
        <div id="asgHistory"><div class="cmp-empty">Select an employee to see their salary history.</div></div>
      </div>
    </div>

    <?php if ($canManage): ?>
    <div class="cmp-card">
      <div class="cmp-card-hd"><h3><i class="fa fa-pencil"></i> New / revised salary</h3>
        <span class="hint">preview before saving — nothing is written until you press Save</span></div>
      <div class="cmp-card-bd">
        <div class="cmp-form">
          <div class="cmp-field"><label>Effective from <span class="req">*</span></label>
            <input type="date" id="aFrom">
            <div class="cmp-help">The month this salary starts applying.</div></div>
          <div class="cmp-field"><label>Effective to</label>
            <input type="date" id="aTo">
            <div class="cmp-help">Leave blank for open-ended.</div></div>
          <div class="cmp-field"><label>Monthly gross (₹) <span class="req">*</span></label>
            <input type="number" id="aGross" step="0.01" min="0" placeholder="42000.00"></div>
          <div class="cmp-field"><label>Structure</label>
            <select id="aTpl"><option value="">— build manually —</option></select>
            <div class="cmp-help">Applying a structure fills the components from the gross.</div></div>
          <div class="cmp-field"><label>Pay grade</label>
            <select id="aGrade"><option value="">— none —</option></select></div>
          <div class="cmp-field"><label>Status</label>
            <select id="aStatus"><option value="active">Active</option><option value="draft">Draft</option></select>
            <div class="cmp-help">A draft is not payable — readiness reports it as a blocker.</div></div>
        </div>

        <div class="cmp-actions">
          <button class="cmp-btn" id="btnPreview" type="button"><i class="fa fa-calculator"></i> Preview</button>
          <button class="cmp-btn primary" id="btnSaveAsg" type="button" disabled><i class="fa fa-save"></i> Save salary</button>
          <span class="cmp-help">Save unlocks once a preview is valid.</span>
        </div>

        <div id="asgPreview" style="margin-top:16px"></div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ══════════ GRADES ══════════ -->
  <!-- Employee statutory profile (CA-6): PF membership is an attribute of a
       PERSON, so it lives beside the person's salary rather than in tenant policy. -->
  <div class="cmp-card" id="cardStatProfile" style="display:none">
    <div class="cmp-card-hd"><span><i class="fa fa-id-badge"></i> Employee statutory profile</span></div>
    <div class="cmp-card-bd">
      <div class="cmp-note">
        Provident-fund membership follows the <b>person</b>, not their current salary. Somebody
        who joined below the wage ceiling stays a member after they cross it — which is why this
        is recorded rather than worked out from their pay each month.
      </div>
      <div class="cmp-form">
        <div class="cmp-field"><label>Staff ID</label>
          <input id="spStaff" placeholder="e.g. STA0007">
          <div class="cmp-help">Enter an ID and press Load.</div></div>
        <div class="cmp-field"><label>&nbsp;</label>
          <button class="cmp-btn" id="spLoad">Load</button></div>
      </div>
      <div id="cmpMsgSp"></div>
      <div id="spForm" style="display:none">
        <div class="cmp-form">
          <div class="cmp-field"><label>PF membership</label>
            <select id="spState">
              <option value="undetermined">Not established yet</option>
              <option value="member">Member</option>
              <option value="not_member">Not a member</option>
            </select></div>
          <div class="cmp-field"><label>How membership arose</label>
            <select id="spBasis">
              <option value="undetermined">Not established yet</option>
              <option value="below_ceiling_at_joining">Joined at or below the wage ceiling</option>
              <option value="above_ceiling_election">Joined above the ceiling, opted in</option>
              <option value="transferred_member">Already a member at a previous employer</option>
              <option value="excluded">Not covered</option>
            </select>
            <div class="cmp-help">Opting in above the ceiling carries no pension-scheme share.</div></div>
          <div class="cmp-field"><label>UAN</label>
            <input id="spUan" maxlength="12" placeholder="12 digits"></div>
          <div class="cmp-field"><label>Member since</label>
            <input type="date" id="spSince"></div>
        </div>
        <?php if ($canManage): ?>
          <button class="cmp-btn primary" id="spSave"><i class="fa fa-save"></i> Save profile</button>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="cmp-panel<?= $at === 'grades' ? ' active' : '' ?>" id="panelGrades">
    <div id="cmpMsgGrade" class="cmp-msg"></div>

    <div class="cmp-note">
      A pay grade is a named salary band. When an assignment sits outside its grade's band the
      readiness panel raises a <b>warning</b> — it does not block payroll, because deliberate
      exceptions are legitimate and a system that refuses them just gets worked around.
      Bands may overlap.
    </div>

    <div class="cmp-card">
      <div class="cmp-card-hd"><h3><i class="fa fa-bars"></i> Pay grades</h3>
        <span class="hint" id="gradeCount">—</span></div>
      <div class="cmp-card-bd">
        <div class="cmp-tblwrap">
          <table class="cmp-tbl" id="gradeTbl">
            <thead><tr>
              <th>Code</th><th>Name</th><th class="num">Band floor</th><th class="num">Band ceiling</th>
              <th class="num">Order</th><th>Status</th><th></th>
            </tr></thead>
            <tbody><tr><td colspan="7" class="cmp-empty">Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>

    <?php if ($canManage): ?>
    <div class="cmp-card">
      <div class="cmp-card-hd"><h3><i class="fa fa-plus-circle"></i> Add / edit a grade</h3></div>
      <div class="cmp-card-bd">
        <div class="cmp-form">
          <div class="cmp-field"><label>Code <span class="req">*</span></label>
            <input type="text" id="gCode" placeholder="C" maxlength="40"></div>
          <div class="cmp-field"><label>Name <span class="req">*</span></label>
            <input type="text" id="gName" placeholder="Grade C — Senior Teacher" maxlength="80"></div>
          <div class="cmp-field"><label>Band floor (₹ / month) <span class="req">*</span></label>
            <input type="number" id="gMin" step="0.01" min="0" placeholder="25000.00"></div>
          <div class="cmp-field"><label>Band ceiling (₹ / month) <span class="req">*</span></label>
            <input type="number" id="gMax" step="0.01" min="0" placeholder="40000.00">
            <div class="cmp-help">Leave 0 for an open-ended top grade.</div></div>
          <div class="cmp-field"><label>Display order</label>
            <input type="number" id="gOrder" value="0" min="0"></div>
        </div>
        <div class="cmp-actions">
          <button class="cmp-btn primary" id="btnSaveGrade" type="button"><i class="fa fa-save"></i> Save grade</button>
          <button class="cmp-btn" id="btnClearGrade" type="button">Clear</button>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ══════════ STATUTORY ══════════ -->
  <div class="cmp-panel<?= $at === 'statutory' ? ' active' : '' ?>" id="panelStatutory">
    <div id="cmpMsgStat" class="cmp-msg"></div>

    <div class="cmp-note">
      <b>Statutory policy is per tenant and must be configured explicitly — nothing is inferred.</b>
      Rates, ceilings, thresholds and Professional Tax slabs differ by organisation and by state,
      so ZenX will never guess them from your school's name, city or address. Each configuration
      carries an <b>effective date</b>; a later configuration supersedes an earlier one, and past
      payroll periods keep resolving to the policy that applied at the time.
    </div>

    <div class="cmp-card">
      <div class="cmp-card-hd"><h3><i class="fa fa-history"></i> Configured policies</h3>
        <span class="hint" id="statCount">—</span></div>
      <div class="cmp-card-bd">
        <div class="cmp-tblwrap">
          <table class="cmp-tbl" id="statTbl">
            <thead><tr>
              <th>Effective from</th><th>Until</th><th class="num">PF ee/er</th><th class="num">PF ceiling</th>
              <th class="num">ESI ee/er</th><th class="num">ESI threshold</th><th>PT state</th>
              <th class="num">PT slabs</th><th>TDS</th>
              <th class="num">Add-back</th><th>Gratuity</th>
            </tr></thead>
            <tbody><tr><td colspan="11" class="cmp-empty">Loading…</td></tr></tbody>
          </table>
        </div>
        <div id="statOverlap"></div>
      </div>
    </div>

    <?php if ($canManage): ?>
    <div class="cmp-card">
      <div class="cmp-card-hd"><h3><i class="fa fa-balance-scale"></i> Configure statutory policy</h3>
        <span class="hint">saving with an existing effective date replaces that policy</span></div>
      <div class="cmp-card-bd">
        <div class="cmp-form">
          <div class="cmp-field"><label>Effective from <span class="req">*</span></label>
            <input type="date" id="sFrom">
            <div class="cmp-help">The date this policy starts applying.</div></div>
          <div class="cmp-field"><label>Effective to</label>
            <input type="date" id="sTo">
            <div class="cmp-help">Leave blank for open-ended.</div></div>
        </div>

        <h4 style="font-size:13px;margin:18px 0 8px;color:#2c3e50">Provident Fund</h4>
        <div class="cmp-form">
          <div class="cmp-field"><label>Employee rate (%)</label><input type="number" id="sPfEe" step="0.01" min="0" max="100" value="0"></div>
          <div class="cmp-field"><label>Employer rate (%)</label><input type="number" id="sPfEr" step="0.01" min="0" max="100" value="0"></div>
          <div class="cmp-field"><label>Wage ceiling (₹ / month)</label><input type="number" id="sPfCeil" step="0.01" min="0" value="0">
            <div class="cmp-help">The wage above which PF is capped, if your policy caps it.</div></div>
          <div class="cmp-field"><label>Ceiling behaviour</label>
            <div style="font-size:12px;line-height:2">
              <label style="font-weight:400"><input type="checkbox" id="sPfApply"> Cap contributions at the ceiling</label><br>
              <label style="font-weight:400"><input type="checkbox" id="sPfElig" checked> Staff above the ceiling are still covered</label>
            </div>
            <div class="cmp-help">Unchecking the second means staff earning above the ceiling are
              <b>excluded entirely</b> — no contribution, rather than a capped one.</div></div>
        </div>

        <h4 style="font-size:13px;margin:18px 0 8px;color:#2c3e50">ESI</h4>
        <div class="cmp-form">
          <div class="cmp-field"><label>Employee rate (%)</label><input type="number" id="sEsiEe" step="0.01" min="0" max="100" value="0"></div>
          <div class="cmp-field"><label>Employer rate (%)</label><input type="number" id="sEsiEr" step="0.01" min="0" max="100" value="0"></div>
          <div class="cmp-field"><label>Wage threshold (₹ / month)</label><input type="number" id="sEsiThr" step="0.01" min="0" value="0">
            <div class="cmp-help">At or below this wage staff are covered on their <b>full</b> wage;
              above it they are not covered at all.</div></div>
          <div class="cmp-field"><label>Applicability</label>
            <div style="font-size:12px;line-height:2">
              <label style="font-weight:400"><input type="checkbox" id="sEsiOn"> ESI applies to this organisation</label>
            </div></div>
        </div>

        <h4 style="font-size:13px;margin:18px 0 8px;color:#2c3e50">Professional Tax</h4>
        <div class="cmp-form">
          <div class="cmp-field"><label>State</label>
            <input type="text" id="sPtState" placeholder="e.g. Uttar Pradesh" maxlength="60">
            <div class="cmp-help">PT is state-specific. ZenX does not infer this — enter the state
              whose rules apply to you.</div></div>
          <div class="cmp-field" style="grid-column:1/-1"><label>Slabs</label>
            <div class="cmp-help" style="margin-bottom:6px">One per line as
              <code>from,to,amount</code> in rupees. Leave <code>to</code> empty for the top band.
              Example: <code>0,15000,0</code> then <code>15000.01,,200</code></div>
            <textarea id="sPtSlabs" rows="4" style="width:100%;font-family:monospace;font-size:12px;
              padding:8px;border:1px solid #d7dee6;border-radius:5px"></textarea></div>
        </div>

        <h4 style="font-size:13px;margin:18px 0 8px;color:#2c3e50">TDS</h4>
        <div class="cmp-form">
          <div class="cmp-field"><label>Mode</label>
            <select id="sTdsMode"><option value="FLAT_PERCENT">Flat percentage</option></select>
            <div class="cmp-help">A full slab / regime engine (declarations, exemptions, annual
              projection) is a later phase and is deliberately not offered here rather than
              approximated.</div></div>
          <div class="cmp-field"><label>Rate (%)</label><input type="number" id="sTdsRate" step="0.01" min="0" max="100" value="0"></div>
        </div>

        <div class="cmp-actions">
        </div>

        <h4 style="margin:18px 0 6px;font-size:13px;color:#7b8794;text-transform:uppercase;letter-spacing:.4px">
          Wage definition</h4>
        <div class="cmp-note" style="margin:0 0 10px">
          Where excluded allowances exceed a share of total pay, the excess is pulled back into
          wages. The law currently sets that share at one-half, but says the Central Government may
          notify another — so it is recorded here, with its effective date, rather than fixed in the
          software. <b>Confirm the figure with your accountant before changing it.</b>
        </div>
        <div class="cmp-form">
          <div class="cmp-field"><label>Add-back threshold</label>
            <div style="display:flex;align-items:center;gap:6px">
              <input type="number" id="sAbNum" min="0" step="1" value="1" style="width:80px">
              <span>/</span>
              <input type="number" id="sAbDen" min="1" step="1" value="2" style="width:80px">
            </div>
            <div class="cmp-help">1 / 2 is one-half — the position in force today.</div></div>
          <div class="cmp-field"><label>Source</label>
            <input type="text" id="sAbSrc" maxlength="120" placeholder="e.g. Code on Wages s.2(y) proviso">
            <div class="cmp-help">Which notification or provision this figure comes from.</div></div>
        </div>

        <h4 style="margin:18px 0 6px;font-size:13px;color:#7b8794;text-transform:uppercase;letter-spacing:.4px">
          Gratuity</h4>
        <div class="cmp-note" style="margin:0 0 10px">
          A school may choose to pay gratuity <b>earlier</b> than the statutory qualifying period,
          but never later — a longer period will be refused. The waiver on death or disablement is
          unconditional in law and is therefore not shown as an option.
        </div>
        <div class="cmp-form">
          <div class="cmp-field"><label>Qualifying service (years)</label>
            <input type="number" id="sGrYears" min="0" step="1" value="5">
            <div class="cmp-help">Lower it to pay earlier. Raising it above the statutory period is refused.</div></div>
          <div class="cmp-field"><label>Maximum payable (₹)</label>
            <input type="number" id="sGrCap" min="0" step="0.01" value="0">
            <div class="cmp-help">0 means no cap recorded yet. Confirm the current ceiling with your accountant.</div></div>
          <div class="cmp-field"><label>Service spanning 21 Nov 2025</label>
            <select id="sGrTrans">
              <option value="unset">Not decided yet — settlement will refuse</option>
              <option value="single_base">One wage base for the whole service</option>
              <option value="split_at_transition">Value service before and after separately</option>
            </select>
            <div class="cmp-help">The wage definition changed on that date. Your accountant decides how
              service either side of it is valued.</div></div>
          <div class="cmp-field"><label>Source</label>
            <input type="text" id="sGrSrc" maxlength="120" placeholder="e.g. CA advice, Aug 2026"></div>
        </div>

        <div class="cmp-actions">
          <button class="cmp-btn primary" id="btnSaveStat" type="button"><i class="fa fa-save"></i> Save policy</button>
          <span class="cmp-help">Overlapping effective periods are rejected — a period must resolve to exactly one policy.</span>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ══════════ READINESS ══════════ -->
  <!-- ══════════ WAGE DEFINITION / CLASSIFICATION ══════════ -->
  <div class="cmp-panel<?= $at === 'classification' ? ' active' : '' ?>" id="panelClassification">
    <div class="cmp-note">
      <i class="fa fa-info-circle"></i>
      Each salary component is either <b>wages</b>, <b>excluded but still remuneration</b>, or
      <b>not remuneration at all</b>. The default shown for each component is what the Code on
      Wages implies; where your school pays an allowance to everyone, your accountant may need to
      classify it as wages instead. A determination is <b>dated</b>, so correcting one never
      changes a payroll that has already run.
    </div>

    <div class="cmp-card">
      <div class="cmp-card-hd">
        <span><i class="fa fa-tags"></i> Component classification</span>
        <span>
          <label for="clsAsOf" style="font-weight:400;font-size:12px">As&nbsp;of</label>
          <input type="date" id="clsAsOf" style="margin-left:6px">
          <button class="cmp-btn" id="clsReload">Show</button>
        </span>
      </div>
      <div id="cmpMsgCls"></div>
      <div class="cmp-tblwrap">
        <table class="cmp-tbl" id="clsTable">
          <thead><tr>
            <th>Component</th><th>Statutory default</th><th>In force</th>
            <th>From</th><th>Reason</th><th>Verified by</th>
            <?php if ($canManage): ?><th>Change</th><?php endif; ?>
          </tr></thead>
          <tbody><tr><td colspan="7">Loading…</td></tr></tbody>
        </table>
      </div>
    </div>

    <?php if ($canManage): ?>
    <div class="cmp-card" id="clsFormCard" style="display:none">
      <div class="cmp-card-hd"><span><i class="fa fa-pencil"></i> Record a determination</span></div>
      <div class="cmp-form">
        <div class="cmp-field"><label>Component</label><input id="clsCode" readonly></div>
        <div class="cmp-field"><label>Classification</label>
          <select id="clsValue">
            <option value="INCLUDE">Wages — counts toward the statutory base</option>
            <option value="CONDITIONAL">Excluded, but still remuneration</option>
            <option value="EXCLUDE">Not remuneration (reimbursement of actuals)</option>
          </select>
        </div>
        <div class="cmp-field"><label>Effective from</label><input type="date" id="clsFrom"></div>
        <div class="cmp-field"><label>Verified by</label><input id="clsVerifiedBy" placeholder="e.g. CA firm"></div>
        <div class="cmp-field"><label>Reason</label>
          <input id="clsReason" placeholder="Required when this differs from the statutory default">
        </div>
      </div>
      <div style="margin-top:10px">
        <button class="cmp-btn primary" id="clsSave">Save determination</button>
        <button class="cmp-btn" id="clsCancel">Cancel</button>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ══════════ FULL &amp; FINAL POLICY ══════════ -->
  <div class="cmp-panel<?= $at === 'fnf' ? ' active' : '' ?>" id="panelFnf">
    <div class="cmp-note">
      <i class="fa fa-info-circle"></i>
      These are your school's own choices, not statutory values. No law sets the notice-recovery
      basis — it comes from your appointment letters. Until it is set, a settlement will
      <b>refuse</b> to compute a recovery rather than show a number nobody chose.
    </div>

    <div id="cmpMsgFnf"></div>
    <div id="fnfIncomplete"></div>

    <div class="cmp-card">
      <div class="cmp-card-hd"><span><i class="fa fa-gavel"></i> Notice recovery</span></div>
      <div class="cmp-form">
        <div class="cmp-field"><label>Recovery basis</label>
          <select id="fnfBasis" <?= $canManage ? '' : 'disabled' ?>>
            <option value="unset">Not configured — recovery is refused</option>
            <option value="basic">Basic pay</option>
            <option value="gross">Gross pay (all earnings)</option>
            <option value="ctc">Cost to company</option>
          </select>
        </div>
        <div class="cmp-field"><label>Note (optional)</label>
          <input id="fnfNote" placeholder="e.g. per clause 7 of the appointment letter"
                 <?= $canManage ? '' : 'disabled' ?>>
        </div>
      </div>
    </div>

    <div class="cmp-card">
      <div class="cmp-card-hd"><span><i class="fa fa-check-square-o"></i> Approval</span></div>
      <div class="cmp-note" style="margin:0 0 10px">
        Separation of duties is always enforced and cannot be switched off: whoever raises a
        settlement can never approve it.
      </div>
      <div class="cmp-form">
        <div class="cmp-field"><label>Approval required</label>
          <select id="fnfApproval" <?= $canManage ? '' : 'disabled' ?>>
            <option value="single">One approver</option>
            <option value="dual_above_threshold">Two approvers above an amount</option>
            <option value="always_dual">Always two approvers</option>
          </select>
        </div>
        <div class="cmp-field"><label>Threshold (₹)</label>
          <input type="number" id="fnfThreshold" min="0" step="1"
                 placeholder="Required for the option above"
                 <?= $canManage ? '' : 'disabled' ?>>
        </div>
      </div>
    </div>

    <div class="cmp-card">
      <div class="cmp-card-hd"><span><i class="fa fa-list-ul"></i> Clearance</span></div>
      <div class="cmp-note" style="margin:0 0 10px">
        Clearance blocks <b>approval</b>, not calculation — so a departing employee can still be
        told what they are owed. Leave the list empty to require every clearance department.
      </div>
      <div class="cmp-form">
        <div class="cmp-field"><label>Departments that block a settlement</label>
          <input id="fnfClearance" placeholder="Comma separated, or empty for all"
                 <?= $canManage ? '' : 'disabled' ?>>
        </div>
        <div class="cmp-field"><label>Who may override</label>
          <input id="fnfOverride" placeholder="Role name; empty means nobody"
                 <?= $canManage ? '' : 'disabled' ?>>
        </div>
      </div>
    </div>

    <?php if ($canManage): ?>
      <button class="cmp-btn primary" id="fnfSave">Save policy</button>
    <?php endif; ?>
    </div>

    <!-- ── Settlements ──────────────────────────────────────────────────
         The lifecycle: initiate → compute → review → approve → pay → close.
         Every button here maps to a transition the state machine allows from
         the row's current state; anything it would refuse is not rendered. -->
    <div class="cmp-card" id="fnfSettleCard">
      <div class="cmp-card-hd">
        <span><i class="fa fa-file-text-o"></i> Settlements</span>
        <span class="cmp-help" id="fnfSetCount"></span>
      </div>

      <div id="cmpMsgSet"></div>

      <?php if ($canManage): ?>
      <!-- Opening a settlement is a manage action; the LIST below is not.
           A viewer sees the lifecycle read-only, which is what
           fnf_settlements() (_require_view) and renderSettlementRow's
           "View only" branch were already written for. -->
      <div class="cmp-form" id="fnfOpenRow">
        <div class="cmp-field">
          <label for="fnfStaffId">Employee ID</label>
          <input id="fnfStaffId" placeholder="e.g. STA000123" autocomplete="off">
          <span class="cmp-help">
            The employee must be separated with a settleable notice period.
          </span>
        </div>
        <div class="cmp-field">
          <label>&nbsp;</label>
          <button class="cmp-btn primary" id="fnfOpenBtn">
            <i class="fa fa-plus"></i> Open settlement
          </button>
        </div>
      </div>
      <?php endif; ?>

      <div class="cmp-tblwrap">
        <table class="cmp-tbl" id="fnfSetTbl">
          <thead>
            <tr>
              <th>Employee</th><th>Status</th><th>Last working day</th>
              <th>Policy</th><th>Run</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr><td colspan="6" class="cmp-empty">Loading settlements&hellip;</td></tr>
          </tbody>
        </table>
      </div>

    <div class="cmp-card">
      <div class="cmp-card-hd"><span><i class="fa fa-calculator"></i> Preview a settlement</span></div>
      <div class="cmp-card-bd">
        <div class="cmp-note">
          This calculates only. It creates no settlement, no payslip and no accounting entry,
          and nobody is notified. Use it to see what a policy choice would do before saving it.
        </div>
        <div class="cmp-form">
          <div class="cmp-field"><label>Staff ID</label>
            <input id="pvStaff" placeholder="e.g. STA0007"></div>
          <div class="cmp-field"><label>Compare against basis</label>
            <select id="pvBasis">
              <option value="">Just show the current policy</option>
              <option value="basic">Basic pay</option>
              <option value="gross">Gross pay</option>
              <option value="ctc">Cost to company</option>
            </select>
            <div class="cmp-help">Picking one shows current beside proposed, with the difference per line.</div></div>
        </div>
        <button class="cmp-btn" id="pvRun"><i class="fa fa-eye"></i> Preview</button>
        <div id="cmpMsgPv"></div>
        <div id="pvOut" style="margin-top:12px"></div>
      </div>
    </div>
  </div>
  <div class="cmp-panel<?= $at === 'readiness' ? ' active' : '' ?>" id="panelReadiness">
    <div id="cmpMsgRdy" class="cmp-msg"></div>

    <div class="cmp-note">
      Payroll readiness for a period, <b>by name</b>. Blockers stop a run because the amount cannot
      be computed or paid; warnings do not stop it but should be looked at. This panel exists so a
      gap is found <em>before</em> a run rather than after it.
    </div>

    <div class="cmp-card">
      <div class="cmp-card-hd"><h3><i class="fa fa-check-square-o"></i> Readiness</h3>
        <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
          <label style="font-size:12px;color:#5a6a7a">As of</label>
          <input type="date" id="rdyDate" style="padding:6px 8px;border:1px solid #d7dee6;border-radius:5px;font-size:12px">
          <label style="font-size:12px;color:#5a6a7a;font-weight:400">
            <input type="checkbox" id="rdyPayout"> check bank / PAN details</label>
          <button class="cmp-btn" id="btnRdy" type="button"><i class="fa fa-refresh"></i> Check</button>
        </div>
      </div>
      <div class="cmp-card-bd">
        <div class="cmp-tiles" id="rdyTiles"></div>
        <div id="rdyBlocked"></div>
        <div id="rdyWarnings"></div>
      </div>
    </div>
  </div>

</div>
</section>
</div>

<script src="<?= base_url('assets/js/zx_dialog.js') ?>?v=1"></script>
<script>
(function () {
  var BASE = '<?= base_url() ?>';
  var CAN_MANAGE = <?= $canManage ? 'true' : 'false' ?>;
  var CSRF_NAME = '<?= $this->security->get_csrf_token_name() ?>';
  var csrfHash  = '<?= $this->security->get_csrf_hash() ?>';

  // UX-P2-1: on the narrow-width horizontal-scroll tab strip, the active tab
  // can load scrolled out of view with nothing on screen to say which module
  // is open. `inline: 'nearest'` moves only the tab bar's own scroll
  // position, never the page.
  var activeTab = document.querySelector('.cmp-tab.active');
  if (activeTab && activeTab.scrollIntoView) {
    // 'end' rather than 'nearest': 'nearest' stops as soon as any sliver of
    // the element is on screen, which can leave the active tab clipped at
    // the exact edge it just scrolled to — the opposite of identifiable.
    activeTab.scrollIntoView({ block: 'nearest', inline: 'end' });
  }

  function msg(box, text, isErr) {
    var el = document.getElementById(box);
    if (!el) return;
    el.className = 'cmp-msg ' + (isErr ? 'err' : 'ok');
    el.innerHTML = text;
    if (!isErr) setTimeout(function () { el.className = 'cmp-msg'; }, 4000);
  }
  var esc = function (s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  };
  var rs = function (paise) {
    var n = Number(paise || 0) / 100;
    return n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  };
  // Rupees → integer paise at the UI boundary. The backend refuses decimals,
  // which is what stops a rupee value being stored where paise are expected.
  var toPaise = function (v) { return Math.round(parseFloat(v || 0) * 100); };

  // fetch() does not reject on 403/500, and CI returns a blank 403 when CSRF
  // fails — so every response is checked for BOTH transport and payload status
  // before anything is treated as success.
  function api(path, opts) {
    opts = opts || {};
    return fetch(BASE + path, opts).then(function (r) {
      return r.json().catch(function () {
        throw new Error('The server returned an unreadable response (HTTP ' + r.status + ').');
      }).then(function (j) {
        if (j && j.csrf_hash) csrfHash = j.csrf_hash;
        if (!r.ok || (j && j.status === 'error')) {
          throw new Error((j && j.message) || ('Request failed (HTTP ' + r.status + ').'));
        }
        return j.data || j;
      });
    });
  }
  function post(path, fields) {
    var fd = new FormData();
    fd.append(CSRF_NAME, csrfHash);
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return api(path, { method: 'POST', body: fd });
  }
  /* Every endpoint below accepts its payload EITHER as a raw JSON body OR as a
     named form field — and only the second one actually works from a browser.
     CI3 reads the CSRF token from $_POST alone (system/core/Security.php
     csrf_verify), and a `Content-Type: application/json` body leaves $_POST
     empty, so the token can never arrive no matter where it is put. Sending it
     in the query string, as this helper used to, does not help either.

     The result was a blank 403 on every one of these calls: salary could not be
     assigned, statutory configuration could not be saved, and the F&F policy
     "Save policy" button could not save. This tenant's empty component
     catalogue and empty structure list are what that looks like from the
     outside.

     Each controller already declares the form field it will accept (`assignment`,
     `policy`, `config`, `template`, `classification`) precisely so the token can
     travel beside the payload. Using it costs nothing and keeps CSRF protection
     fully ON — the alternative, adding these routes to csrf_exclude_uris, would
     switch it off for endpoints that write salary and statutory data. */
  var JSON_FIELD = {
    'compensation/save_salary_assignment':        'assignment',
    'compensation/preview_assignment':            'assignment',
    'compensation/save_fnf_policy':               'policy',
    'compensation/save_statutory_config':         'config',
    'compensation/save_template':                 'template',
    'compensation/save_component_classification': 'classification'
  };

  function postJson(path, obj) {
    var field = JSON_FIELD[path];
    if (field) {
      var fields = {};
      fields[field] = JSON.stringify(obj);
      return post(path, fields);          // FormData → $_POST → CSRF passes
    }

    // An endpoint this map does not know about. Keep the old shape rather than
    // guess a field name, and say so, so the next one is not silently broken.
    if (window.console && console.warn) {
      console.warn('postJson: no form field mapped for "' + path +
                   '" — CI3 will reject this with 403 unless the route is CSRF-excluded.');
    }
    return api(path + '?' + CSRF_NAME + '=' + encodeURIComponent(csrfHash), {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(obj)
    });
  }

  // ── F&F settlements ────────────────────────────────────────────────────
  // The state machine decides what is legal; this only renders it. Keeping the
  // two in step matters — a button the server refuses teaches an operator to
  // distrust the screen.
  var FNF_ACTIONS = {
    initiated:  [['preview', 'Preview', 'fa-eye'],
                 ['compute', 'Compute', 'fa-calculator'],
                 ['cancel',  'Cancel',  'fa-ban']],
    calculated: [['preview',         'Preview',          'fa-eye'],
                 ['statement',       'Statement',        'fa-file-pdf-o'],
                 ['compute',         'Recompute',        'fa-refresh'],
                 ['submitForReview', 'Send for review',  'fa-paper-plane'],
                 ['cancel',          'Cancel',           'fa-ban']],
    inReview:   [['preview',   'Preview',   'fa-eye'],
                 ['statement', 'Statement', 'fa-file-pdf-o'],
                 ['approve', 'Approve', 'fa-check'],
                 ['reject',  'Reject',  'fa-times'],
                 ['cancel',  'Cancel',  'fa-ban']],
    approved:   [['statement',     'Statement',      'fa-file-pdf-o'],
                 ['recordPayment', 'Record payment', 'fa-money'],
                 ['cancel',        'Cancel',         'fa-ban']],
    paid:       [['statement', 'Statement', 'fa-file-pdf-o'],
                 ['close',     'Close',     'fa-archive']],
    closed:     [['statement', 'Statement', 'fa-file-pdf-o']],
    cancelled:  []
  };

  /* `preview` is a CLIENT-SIDE action: it moves no state and calls no
     transition. Listed alongside the real events because from the operator's
     side it is another thing they can do to a row — but it is routed away from
     the state machine before anything is posted. */
  var FNF_CLIENT_ONLY = ['preview', 'statement'];

  var fnfBusy = false;

  function loadSettlements() {
    var tb = document.querySelector('#fnfSetTbl tbody');
    if (!tb) return;

    post('compensation/fnf_settlements', {}).then(function (d) {
      var list = d.settlements || [];
      document.getElementById('fnfSetCount').textContent =
        list.length ? (list.length + ' settlement(s)') : '';

      if (!list.length) {
        tb.innerHTML = '<tr><td colspan="6" class="cmp-empty">'
          + 'No settlements yet. Open one for a separated employee above.</td></tr>';
        return;
      }
      tb.innerHTML = list.map(renderSettlementRow).join('');
    }).catch(function (e) {
      tb.innerHTML = '<tr><td colspan="6" class="cmp-empty">'
        + esc(e.message || 'Could not load settlements.') + '</td></tr>';
    });
  }

  /**
   * Preview what this settlement would pay.
   *
   * Fills the existing Preview card and runs the existing renderer, then brings
   * it into view — the operator asked about a specific row and should not have
   * to find the answer.
   *
   * A PROJECTION, not the settled figure: it reads today's data. That is why it
   * is offered on open settlements only, and why the card already says
   * "Preview only — nothing was created or paid."
   */
  function previewSettlement(staffId) {
    if (!staffId) { msg('cmpMsgSet', 'This settlement has no employee ID to preview.', true); return; }

    var box = document.getElementById('pvStaff');
    if (!box) { msg('cmpMsgSet', 'The preview panel is not available on this page.', true); return; }

    box.value = staffId;

    // Preview against the CONFIGURED policy, not a proposed one: the comparison
    // mode is for testing a policy change, and silently comparing here would
    // show a settlement against terms the school has not adopted.
    var basisSel = document.getElementById('pvBasis');
    if (basisSel) basisSel.value = '';

    runPreview();

    var card = document.getElementById('pvOut');
    if (card && card.scrollIntoView) card.scrollIntoView({ block: 'center' });
  }
  function renderSettlementRow(x) {
    var st = String(x.status || '');
    var comp = x.computation || {};

    // A settlement computed when no policy was in force says so on its face
    // rather than showing a version number that means nothing.
    var policyCell = comp.policyVersion
      ? ('v' + esc(comp.policyVersion)
         + (comp.policyEffective ? ' <span class="cmp-help">from ' + esc(comp.policyEffective) + '</span>' : '')
         + (comp.policyResolved === false
              ? ' <span class="cmp-badge b-ded" title="No policy was in force on the last working day">unresolved</span>'
              : ''))
      : '<span class="cmp-help">—</span>';

    var actions = (FNF_ACTIONS[st] || []).map(function (a) {
      return '<button class="cmp-btn" data-fnf-event="' + esc(a[0]) + '" '
           + 'data-fnf-id="' + esc(x.settlementId) + '" '
           + 'data-fnf-staff="' + esc(x.staffId) + '">'
           + '<i class="fa ' + esc(a[2]) + '"></i> ' + esc(a[1]) + '</button>';
    }).join(' ');

    if (!CAN_MANAGE) {
      actions = '<span class="cmp-help">View only</span>';
    } else if (!actions) {
      actions = '<span class="cmp-help">No further action</span>';
    }

    return '<tr>'
      + '<td>' + esc(x.staffName || x.staffId) + '<div class="cmp-help">' + esc(x.staffId) + '</div></td>'
      + '<td><span class="cmp-badge">' + esc(st) + '</span></td>'
      + '<td>' + (x.lastWorkingDay ? esc(x.lastWorkingDay) : '<span class="cmp-help">not set</span>') + '</td>'
      + '<td>' + policyCell + '</td>'
      + '<td>' + (comp.runId ? esc(comp.runId) : '<span class="cmp-help">—</span>') + '</td>'
      + '<td>' + actions + '</td>'
      + '</tr>';
  }

  /* Open a settlement. The refusals that matter — no open slot, notice not
     settleable — come back from the server as sentences, so they are shown
     as-is rather than translated a second time here. */
  function openSettlement() {
    if (fnfBusy) return;

    var idEl = document.getElementById('fnfStaffId');
    var staffId = (idEl.value || '').trim();
    if (!staffId) {
      msg('cmpMsgSet', 'Enter the employee ID to open a settlement for.', true);
      idEl.focus();
      return;
    }

    fnfBusy = true;
    var btn = document.getElementById('fnfOpenBtn');
    var original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Opening…';

    post('compensation/fnf_initiate', { staff_id: staffId }).then(function (d) {
      msg('cmpMsgSet', 'Settlement opened for ' + esc(staffId) + '.', false);
      idEl.value = '';
      loadSettlements();
    }).catch(function (e) {
      msg('cmpMsgSet', esc(e.message || 'Could not open the settlement.'), true);
    }).finally(function () {
      fnfBusy = false;
      btn.disabled = false;
      btn.innerHTML = original;
    });
  }

  /* Drive a transition. Each event collects only what its guard requires —
     asking for a payment reference when cancelling would be noise, and not
     asking when recording payment would be a refusal the operator cannot see
     coming. */
  async function fnfTransition(sid, event, btn) {
    if (fnfBusy) return;

    var fields = { settlement_id: sid, event: event };

    // UI-D3: themed dialogs, not window.prompt/confirm. These are async, so
    // this function is too — the guard order below is unchanged.
    if (event === 'reject') {
      var note = await zxPrompt({
        title: 'Reject this settlement',
        message: 'Why is this settlement being rejected?',
        placeholder: 'Reason for rejection',
        confirmText: 'Reject',
        requiredMessage: 'A note is required when rejecting.'
      });
      if (note === null) return;
      fields.note = note;

    } else if (event === 'cancel') {
      var reason = await zxPrompt({
        title: 'Cancel this settlement',
        message: 'Why is this settlement being cancelled?',
        placeholder: 'Reason for cancellation',
        confirmText: 'Continue',
        requiredMessage: 'A reason is required when cancelling.'
      });
      if (reason === null) return;
      var sure = await zxConfirm({
        title: 'Cancel this settlement?',
        message: 'It cannot be reopened — a new settlement would have to be started.',
        confirmText: 'Cancel settlement',
        cancelText: 'Keep it',
        danger: true
      });
      if (!sure) return;
      fields.note = reason;

    } else if (event === 'recordPayment') {
      var ref = await zxPrompt({
        title: 'Record payment',
        message: 'Payment reference (UTR, cheque number or voucher ID):',
        placeholder: 'e.g. NEFT-2026-000123',
        confirmText: 'Record payment',
        requiredMessage: 'A payment reference is required.'
      });
      if (ref === null) return;
      fields.payment_ref = ref;

    } else if (event === 'close') {
      var entry = await zxPrompt({
        title: 'Close this settlement',
        message: 'Accounting entry ID this settlement was posted under:',
        placeholder: 'e.g. JE_FNF_STA0001',
        confirmText: 'Close settlement',
        requiredMessage: 'An accounting entry ID is required to close.'
      });
      if (entry === null) return;
      fields.accounting_entry_id = entry;

    } else if (event === 'approve') {
      var ok = await zxConfirm({
        title: 'Approve this settlement?',
        message: 'Approval fixes the figures that were computed. '
               + 'You cannot approve a settlement you opened.',
        confirmText: 'Approve'
      });
      if (!ok) return;
    }

    fnfBusy = true;
    var original = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>'; }

    post('compensation/fnf_transition', fields).then(function (d) {
      msg('cmpMsgSet', esc(d.message || 'Settlement updated.'), false);
      loadSettlements();
    }).catch(function (e) {
      // A 409 here means somebody else moved it while this operator was
      // deciding — the message says so and the reload shows the truth.
      msg('cmpMsgSet', esc(e.message || 'Could not update the settlement.'), true);
      loadSettlements();
    }).finally(function () {
      fnfBusy = false;
      if (btn) { btn.disabled = false; btn.innerHTML = original; }
    });
  }

  /* `compute` is its own endpoint — it attaches a run rather than moving the
     machine on its own terms. */
  function fnfCompute(sid, fields, btn) {
    fnfBusy = true;
    var original = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>'; }

    post('compensation/fnf_compute', fields).then(function (d) {
      msg('cmpMsgSet', esc(d.message || 'Settlement computed.'), false);
      loadSettlements();
    }).catch(function (e) {
      msg('cmpMsgSet', esc(e.message || 'Could not compute the settlement.'), true);
      loadSettlements();
    }).finally(function () {
      fnfBusy = false;
      if (btn) { btn.disabled = false; btn.innerHTML = original; }
    });
  }

  document.addEventListener('click', async function (ev) {
    if (!ev.target.closest) return;

    if (ev.target.closest('#fnfOpenBtn')) { openSettlement(); return; }

    var b = ev.target.closest('[data-fnf-event]');
    if (!b) return;

    var event = b.getAttribute('data-fnf-event');
    var sid   = b.getAttribute('data-fnf-id');

    // Preview reuses the existing Preview card and its renderer rather than
    // drawing a second one. One projection, one place it can be wrong.
    if (FNF_CLIENT_ONLY.indexOf(event) !== -1) {
      if (event === 'statement') {
        // A download, not an AJAX call: the PDF is streamed by the server,
        // so a new tab is the right shape and a spinner would never clear.
        window.open(BASE + 'compensation/download_settlement?settlement_id='
          + encodeURIComponent(sid), '_blank');
      } else {
        previewSettlement(b.getAttribute('data-fnf-staff'));
      }
      return;
    }

    if (event === 'compute') {
      var runId = await zxPrompt({
        title: 'Compute this settlement',
        message: 'Off-cycle payroll run ID that computed this settlement.\n'
               + 'The settlement records which run produced the figures — it does not calculate them itself.',
        placeholder: 'e.g. PR0019',
        confirmText: 'Compute',
        requiredMessage: 'A payroll run ID is required to compute.'
      });
      if (runId === null) return;

      var f = { settlement_id: sid, run_id: runId };
      var override = await zxConfirm({
        title: 'Override clearance?',
        message: 'Only do this if clearance is genuinely complete outside the system. '
               + 'The override is recorded against the settlement.',
        confirmText: 'Override clearance',
        cancelText: 'Do not override',
        danger: true
      });
      if (override) { f.clearance_override = '1'; }
      fnfCompute(sid, f, b);
      return;
    }

    fnfTransition(sid, event, b);
  });
  // ── Components ─────────────────────────────────────────────────────────
  function loadComponents() {
    var tb = document.querySelector('#compTbl tbody');
    if (!tb) return;
    api('compensation/get_components').then(function (d) {
      var list = d.components || [];
      document.getElementById('compCount').textContent = list.length + ' component(s)';
      if (!list.length) {
        tb.innerHTML = '<tr><td colspan="9" class="cmp-empty">No components yet.' +
          (CAN_MANAGE ? ' Use <b>Seed the 13 system components</b> to start from what payroll already does.' : '') +
          '</td></tr>';
        return;
      }
      tb.innerHTML = list.map(function (c) {
        var kindCls = c.kind === 'earning' ? 'b-earn' : (c.kind === 'deduction' ? 'b-ded' : 'b-emp');
        var kindTxt = c.kind === 'employer_contribution' ? 'Employer' : (c.kind === 'earning' ? 'Earning' : 'Deduction');
        var calc = c.calcType === 'PERCENT' ? (esc(c.rate) + '% of ' + esc(c.basis))
                 : (c.calcType === 'BALANCING' ? 'Remainder of gross' : 'Fixed');
        return '<tr' + (c.active ? '' : ' class="cmp-ro"') + '>' +
          '<td><b>' + esc(c.code) + '</b>' + (c.systemLocked ? ' <span class="cmp-badge b-lock">system</span>' : '') + '</td>' +
          '<td>' + esc(c.name) + '</td>' +
          '<td><span class="cmp-badge ' + kindCls + '">' + kindTxt + '</span></td>' +
          '<td>' + calc + '</td>' +
          '<td>' + esc(c.basis === 'NONE' ? '—' : c.basis) + '</td>' +
          '<td>' + esc(c.statutory || '—') + '</td>' +
          '<td class="num">' + esc(c.displayOrder) + '</td>' +
          '<td>' + (c.active ? 'Active' : '<span class="cmp-badge b-off">Inactive</span>') + '</td>' +
          '<td>' + (CAN_MANAGE && !c.systemLocked
            ? '<button class="cmp-btn" data-tog="' + esc(c.code) + '" data-on="' + (c.active ? '0' : '1') + '">' +
              (c.active ? 'Deactivate' : 'Activate') + '</button>' : '') + '</td></tr>';
      }).join('');
    }).catch(function (e) { msg('cmpMsgComp', esc(e.message), true); });
  }

  document.addEventListener('click', async function (ev) {
    var t = ev.target;
    if (t.id === 'btnSeed') {
      t.disabled = true;
      post('compensation/seed_system_components', {}).then(function (d) {
        msg('cmpMsgComp', esc(d.message || 'Seeded.'), false); loadComponents();
      }).catch(function (e) { msg('cmpMsgComp', esc(e.message), true); })
        .finally(function () { t.disabled = false; });
    }
    if (t.id === 'btnSaveComp') {
      var calc = document.getElementById('cCalc').value;
      var f = {
        code: document.getElementById('cCode').value.trim(),
        name: document.getElementById('cName').value.trim(),
        kind: document.getElementById('cKind').value,
        calcType: calc,
        basis: document.getElementById('cBasis').value,
        displayOrder: document.getElementById('cOrder').value || '0',
        statutory: document.getElementById('cStat').value,
        taxable: document.getElementById('cTaxable').checked ? '1' : '0',
        pfApplicable: document.getElementById('cPf').checked ? '1' : '0',
        esiApplicable: document.getElementById('cEsi').checked ? '1' : '0',
        prorated: document.getElementById('cPro').checked ? '1' : '0',
        active: '1'
      };
      if (!f.code || !f.name) { msg('cmpMsgComp', 'Code and name are required.', true); return; }
      // Only send the value the chosen calculation actually uses — the backend
      // rejects a percentage carrying an amount, and vice versa.
      if (calc === 'PERCENT') {
        var rate = await zxPrompt({
          title: 'Default rate',
          message: 'Default rate (%) for ' + f.code,
          placeholder: '0',
          confirmText: 'Set rate',
          required: false
        });
        if (rate === null) return;
        f.rate = rate || '0';
      }
      if (calc === 'FIXED')   f.amountPaise = '0';
      t.disabled = true;
      post('compensation/save_component', f).then(function (d) {
        msg('cmpMsgComp', esc(d.message || 'Saved.'), false); loadComponents();
      }).catch(function (e) { msg('cmpMsgComp', esc(e.message), true); })
        .finally(function () { t.disabled = false; });
    }
    if (t.id === 'btnClearComp') {
      ['cCode', 'cName'].forEach(function (id) { document.getElementById(id).value = ''; });
      document.getElementById('cOrder').value = '0';
    }
    if (t.dataset && t.dataset.tog) {
      var on = t.dataset.on;
      if (on === '0') {
        var okDeact = await zxConfirm({
          title: 'Deactivate ' + t.dataset.tog + '?',
          message: 'It stays on record and can be reactivated.',
          confirmText: 'Deactivate',
          danger: true
        });
        if (!okDeact) return;
      }
      post('compensation/set_component_active', { code: t.dataset.tog, active: on }).then(function (d) {
        msg('cmpMsgComp', esc(d.message || 'Updated.'), false); loadComponents();
      }).catch(function (e) { msg('cmpMsgComp', esc(e.message), true); });
    }

    // ── Grades ──
    if (t.id === 'btnSaveGrade') {
      var g = {
        code: document.getElementById('gCode').value.trim(),
        name: document.getElementById('gName').value.trim(),
        minPaise: String(toPaise(document.getElementById('gMin').value)),
        maxPaise: String(toPaise(document.getElementById('gMax').value)),
        displayOrder: document.getElementById('gOrder').value || '0',
        active: '1'
      };
      if (!g.code || !g.name) { msg('cmpMsgGrade', 'Code and name are required.', true); return; }
      t.disabled = true;
      post('compensation/save_grade', g).then(function (d) {
        msg('cmpMsgGrade', esc(d.message || 'Saved.'), false); loadGrades();
      }).catch(function (e) { msg('cmpMsgGrade', esc(e.message), true); })
        .finally(function () { t.disabled = false; });
    }
    if (t.id === 'btnClearGrade') {
      ['gCode', 'gName', 'gMin', 'gMax'].forEach(function (id) { document.getElementById(id).value = ''; });
    }
    if (t.dataset && t.dataset.gtog) {
      post('compensation/set_grade_active', { code: t.dataset.gtog, active: t.dataset.gon }).then(function (d) {
        msg('cmpMsgGrade', esc(d.message || 'Updated.'), false); loadGrades();
      }).catch(function (e) { msg('cmpMsgGrade', esc(e.message), true); });
    }

    // ── Statutory ──
    if (t.id === 'btnSaveStat') {
      var from = document.getElementById('sFrom').value;
      if (!from) { msg('cmpMsgStat', 'An effective-from date is required — a policy without one cannot be applied to a period.', true); return; }
      var slabs = [];
      var raw = (document.getElementById('sPtSlabs').value || '').split('\n');
      for (var i = 0; i < raw.length; i++) {
        var line = raw[i].trim(); if (!line) continue;
        var p = line.split(',');
        if (p.length < 3) { msg('cmpMsgStat', 'Slab line ' + (i + 1) + ' needs three values: from,to,amount.', true); return; }
        slabs.push({
          fromPaise: toPaise(p[0]),
          toPaise: (p[1] || '').trim() === '' ? null : toPaise(p[1]),
          amountPaise: toPaise(p[2])
        });
      }
      var payload = {
        effectiveFrom: from,
        effectiveTo: document.getElementById('sTo').value || null,
        pf: {
          employeeRate: parseFloat(document.getElementById('sPfEe').value || 0),
          employerRate: parseFloat(document.getElementById('sPfEr').value || 0),
          wageCeilingPaise: toPaise(document.getElementById('sPfCeil').value),
          applyCeiling: document.getElementById('sPfApply').checked,
          eligibleAboveCeiling: document.getElementById('sPfElig').checked
        },
        esi: {
          employeeRate: parseFloat(document.getElementById('sEsiEe').value || 0),
          employerRate: parseFloat(document.getElementById('sEsiEr').value || 0),
          wageThresholdPaise: toPaise(document.getElementById('sEsiThr').value),
          applicable: document.getElementById('sEsiOn').checked
        },
        pt: { state: document.getElementById('sPtState').value.trim(), slabs: slabs },
        tds: { mode: document.getElementById('sTdsMode').value, rate: parseFloat(document.getElementById('sTdsRate').value || 0) },
        // CA-4 / CA-7. Sent as part of the SAME dated policy, so a notified
        // change applies from its own effective date and cannot reprice an
        // earlier period. The server enforces the gratuity statutory floor.
        addBack: {
          thresholdNum: parseInt(document.getElementById('sAbNum').value || 1, 10),
          thresholdDen: parseInt(document.getElementById('sAbDen').value || 2, 10),
          source: document.getElementById('sAbSrc').value.trim()
        },
        gratuity: {
          eligibilityYears: parseInt(document.getElementById('sGrYears').value || 0, 10),
          maxPayablePaise: toPaise(document.getElementById('sGrCap').value),
          transitionTreatment: document.getElementById('sGrTrans').value,
          source: document.getElementById('sGrSrc').value.trim()
        }
      };
      t.disabled = true;
      postJson('compensation/save_statutory_config', payload).then(function (d) {
        msg('cmpMsgStat', esc(d.message || 'Saved.'), false); loadStatutory();
      }).catch(function (e) { msg('cmpMsgStat', esc(e.message), true); })
        .finally(function () { t.disabled = false; });
    }

    if (t.id === 'btnRdy') loadReadiness();
  });

  function loadGrades() {
    var tb = document.querySelector('#gradeTbl tbody');
    if (!tb) return;
    api('compensation/get_grades').then(function (d) {
      var list = d.grades || [];
      document.getElementById('gradeCount').textContent = list.length + ' grade(s)';
      if (!list.length) { tb.innerHTML = '<tr><td colspan="7" class="cmp-empty">No pay grades defined yet.</td></tr>'; return; }
      tb.innerHTML = list.map(function (g) {
        return '<tr' + (g.active ? '' : ' class="cmp-ro"') + '>' +
          '<td><b>' + esc(g.code) + '</b></td><td>' + esc(g.name) + '</td>' +
          '<td class="num">₹' + rs(g.minPaise) + '</td>' +
          '<td class="num">' + (Number(g.maxPaise) > 0 ? '₹' + rs(g.maxPaise) : '<i>open</i>') + '</td>' +
          '<td class="num">' + esc(g.displayOrder) + '</td>' +
          '<td>' + (g.active ? 'Active' : '<span class="cmp-badge b-off">Inactive</span>') + '</td>' +
          '<td>' + (CAN_MANAGE ? '<button class="cmp-btn" data-gtog="' + esc(g.code) + '" data-gon="' +
            (g.active ? '0' : '1') + '">' + (g.active ? 'Deactivate' : 'Activate') + '</button>' : '') + '</td></tr>';
      }).join('');
    }).catch(function (e) { msg('cmpMsgGrade', esc(e.message), true); });
  }

  function loadStatutory() {
    var tb = document.querySelector('#statTbl tbody');
    if (!tb) return;
    api('compensation/get_statutory_config').then(function (d) {
      var list = d.configs || [];
      document.getElementById('statCount').textContent = list.length + ' policy(ies)';
      var ov = document.getElementById('statOverlap');
      ov.innerHTML = (d.overlaps && d.overlaps.length)
        ? '<div class="cmp-msg err" style="display:block;margin-top:12px"><b>Overlapping effective periods:</b><br>' +
          d.overlaps.map(esc).join('<br>') + '</div>' : '';
      if (!list.length) {
        tb.innerHTML = '<tr><td colspan="11" class="cmp-empty">' +
          'No statutory policy configured. Payroll statutory deductions cannot be applied until one exists.' +
          '</td></tr>';
        return;
      }
      tb.innerHTML = list.map(function (c) {
        var pf = c.pf || {}, esi = c.esi || {}, pt = c.pt || {}, tds = c.tds || {};
        // CA-4 / CA-7 must be VISIBLE once saved, or they are configured and
        // invisible — the same defect as a setting with no form field.
        var ab = c.addBack || {}, gr = c.gratuity || {};
        return '<tr><td><b>' + esc(c.effectiveFrom) + '</b></td>' +
          '<td>' + esc(c.effectiveTo || 'open') + '</td>' +
          '<td class="num">' + esc(pf.employeeRate) + '% / ' + esc(pf.employerRate) + '%</td>' +
          '<td class="num">' + (pf.applyCeiling ? '₹' + rs(pf.wageCeilingPaise) : '<i>no cap</i>') + '</td>' +
          '<td class="num">' + esc(esi.employeeRate) + '% / ' + esc(esi.employerRate) + '%</td>' +
          '<td class="num">' + (esi.applicable ? '₹' + rs(esi.wageThresholdPaise) : '<i>n/a</i>') + '</td>' +
          '<td>' + esc(pt.state || '—') + '</td>' +
          '<td class="num">' + ((pt.slabs || []).length) + '</td>' +
          '<td>' + esc(tds.mode || '—') + ' ' + esc(tds.rate || 0) + '%</td>' +
          '<td class="num">' + (ab.thresholdDen
              ? esc(ab.thresholdNum) + ' / ' + esc(ab.thresholdDen) : '—') + '</td>' +
          '<td>' + (gr.eligibilityYears !== undefined
              ? esc(gr.eligibilityYears) + 'y' +
                (gr.transitionTreatment && gr.transitionTreatment !== 'unset'
                  ? '' : ' <i>(transition not set)</i>')
              : '—') + '</td></tr>';
      }).join('');
    }).catch(function (e) { msg('cmpMsgStat', esc(e.message), true); });
  }

  function loadReadiness() {
    var dt = document.getElementById('rdyDate');
    if (!dt) return;
    var q = 'compensation/get_payroll_readiness?as_of=' + encodeURIComponent(dt.value || '') +
            '&check_payout=' + (document.getElementById('rdyPayout').checked ? '1' : '0');
    api(q).then(function (d) {
      var s = d.summary || {};
      document.getElementById('rdyTiles').innerHTML =
        '<div class="cmp-tile ok"><div class="k">Ready</div><div class="v">' + (s.ready || 0) + '</div></div>' +
        '<div class="cmp-tile bad"><div class="k">Blocked</div><div class="v">' + (s.blocked || 0) + '</div></div>' +
        '<div class="cmp-tile warn"><div class="k">Warnings</div><div class="v">' + (s.warnings || 0) + '</div></div>' +
        '<div class="cmp-tile"><div class="k">Payroll</div><div class="v" style="font-size:18px;padding-top:6px">' +
          (s.payrollEligible ? 'Eligible' : 'Blocked') + '</div></div>';

      var b = d.blocked || [];
      document.getElementById('rdyBlocked').innerHTML = b.length
        ? '<h4 style="font-size:13px;margin:14px 0 8px;color:#c0392b">Blocked — payroll cannot run</h4><ul class="cmp-list">' +
          b.map(function (x) {
            return '<li class="blk"><span class="who">' + esc(x.name) + '</span><span class="why">' + esc(x.reason) + '</span></li>';
          }).join('') + '</ul>'
        : '<div class="cmp-empty">No blockers.</div>';

      var w = d.warnings || [];
      document.getElementById('rdyWarnings').innerHTML = w.length
        ? '<h4 style="font-size:13px;margin:14px 0 8px;color:#b7791f">Warnings — payroll can still run</h4><ul class="cmp-list">' +
          w.map(function (x) {
            return '<li class="wrn"><span class="who">' + esc(x.name) + '</span><span class="why">' + esc(x.reason) + '</span></li>';
          }).join('') + '</ul>'
        : '';
    }).catch(function (e) { msg('cmpMsgRdy', esc(e.message), true); });
  }

  // ── Structures / templates ─────────────────────────────────────────────
  var CATALOGUE = [];          // component metadata, needed to price a line
  var TPL_LINES = [];

  function loadCatalogue() {
    return api('compensation/get_components?active_only=1').then(function (d) {
      CATALOGUE = d.components || [];
      var sel = document.getElementById('tAddComp');
      if (sel) {
        sel.innerHTML = CATALOGUE.map(function (c) {
          return '<option value="' + esc(c.code) + '">' + esc(c.code) + ' — ' + esc(c.name) + '</option>';
        }).join('');
      }
      return CATALOGUE;
    });
  }
  function compMeta(code) {
    for (var i = 0; i < CATALOGUE.length; i++) if (CATALOGUE[i].code === code) return CATALOGUE[i];
    return null;
  }

  function renderTplLines() {
    var tb = document.querySelector('#tplLineTbl tbody');
    if (!tb) return;
    if (!TPL_LINES.length) {
      tb.innerHTML = '<tr><td colspan="4" class="cmp-empty">No components yet — add at least one earning.</td></tr>';
      return;
    }
    tb.innerHTML = TPL_LINES.map(function (l, i) {
      var m = compMeta(l.componentId) || {};
      // The catalogue owns the calculation type; the structure supplies only
      // the value, so a balancing line offers no input at all.
      var input = m.calcType === 'BALANCING'
        ? '<i style="color:#8a97a5">remainder of gross</i>'
        : '<input type="number" step="0.01" min="0" data-line="' + i + '" value="' + esc(l.val || 0) +
          '" style="width:120px;padding:5px 7px;border:1px solid #d7dee6;border-radius:5px;text-align:right">';
      var calc = m.calcType === 'PERCENT' ? ('% of ' + esc(m.basis))
               : (m.calcType === 'BALANCING' ? 'Balancing' : 'Fixed ₹');
      return '<tr><td><b>' + esc(l.componentId) + '</b> <span style="color:#8a97a5">' + esc(m.name || '') + '</span></td>' +
        '<td>' + calc + '</td><td class="num">' + input + '</td>' +
        '<td><button class="cmp-btn" data-rmline="' + i + '">Remove</button></td></tr>';
    }).join('');
  }

  function loadTemplates() {
    var tb = document.querySelector('#tplTbl tbody');
    if (!tb) return;
    loadCatalogue().then(function () {
      return api('compensation/get_templates');
    }).then(function (d) {
      var list = d.templates || [];
      document.getElementById('tplCount').textContent = list.length + ' structure(s)';
      if (!list.length) { tb.innerHTML = '<tr><td colspan="6" class="cmp-empty">No structures defined yet.</td></tr>'; return; }
      tb.innerHTML = list.map(function (t) {
        return '<tr' + (t.active ? '' : ' class="cmp-ro"') + '><td><b>' + esc(t.code) + '</b></td>' +
          '<td>' + esc(t.name) + '</td>' +
          '<td>' + (t.lines || []).map(function (l) { return esc(l.componentId); }).join(', ') + '</td>' +
          '<td>' + esc(t.payGrade || '—') + '</td>' +
          '<td>' + (t.active ? 'Active' : '<span class="cmp-badge b-off">Inactive</span>') + '</td>' +
          '<td>' + (CAN_MANAGE ? '<button class="cmp-btn" data-ttog="' + esc(t.code) + '" data-ton="' +
            (t.active ? '0' : '1') + '">' + (t.active ? 'Deactivate' : 'Activate') + '</button>' : '') + '</td></tr>';
      }).join('');
    }).catch(function (e) { msg('cmpMsgTpl', esc(e.message), true); });
  }

  function fillGradeSelects() {
    return api('compensation/get_grades?active_only=1').then(function (d) {
      var opts = '<option value="">— none —</option>' + (d.grades || []).map(function (g) {
        return '<option value="' + esc(g.code) + '">' + esc(g.code) + ' — ' + esc(g.name) + '</option>';
      }).join('');
      ['tGrade', 'aGrade'].forEach(function (id) {
        var el = document.getElementById(id); if (el) el.innerHTML = opts;
      });
    }).catch(function () { /* grades are optional */ });
  }

  // ── Employee salary ────────────────────────────────────────────────────
  var PREVIEWED = null;

  function loadStaffSelect() {
    return api('compensation/get_payroll_readiness?as_of=' + encodeURIComponent(today())).then(function (d) {
      var all = (d.ready || []).concat(d.blocked || []);
      all.sort(function (a, b) { return String(a.name).localeCompare(String(b.name)); });
      var sel = document.getElementById('asgStaff');
      if (sel) {
        sel.innerHTML = '<option value="">— select an employee —</option>' + all.map(function (s) {
          return '<option value="' + esc(s.staffId) + '">' + esc(s.name) + ' (' + esc(s.staffId) + ')</option>';
        }).join('');
      }
    }).catch(function (e) { msg('cmpMsgAsg', esc(e.message), true); });
  }

  function loadAssignments() {
    var staffId = document.getElementById('asgStaff').value;
    var box = document.getElementById('asgHistory');
    if (!staffId) { box.innerHTML = '<div class="cmp-empty">Select an employee first.</div>'; return; }

    api('compensation/get_salary_assignments?staff_id=' + encodeURIComponent(staffId) +
        '&as_of=' + encodeURIComponent(today())).then(function (d) {
      var list = d.assignments || [];
      var eff = d.effective;
      var ov = (d.overlaps || []).length
        ? '<div class="cmp-msg err" style="display:block">' + d.overlaps.map(esc).join('<br>') + '</div>' : '';
      if (!list.length) {
        box.innerHTML = ov + '<div class="cmp-empty">No salary has been configured for this employee. ' +
          'They cannot be paid until one exists.</div>';
        return;
      }
      box.innerHTML = ov + '<div class="cmp-tblwrap"><table class="cmp-tbl">' +
        '<thead><tr><th>Effective from</th><th>Until</th><th class="num">Gross</th><th>Grade</th>' +
        '<th>Status</th><th>Components</th><th></th></tr></thead><tbody>' +
        list.map(function (a) {
          var isEff = eff && eff.effectiveFrom === a.effectiveFrom;
          return '<tr' + (isEff ? ' style="background:#f2fbf5"' : '') + '>' +
            '<td><b>' + esc(a.effectiveFrom) + '</b>' + (isEff ? ' <span class="cmp-badge b-earn">in force</span>' : '') + '</td>' +
            '<td>' + esc(a.effectiveTo || 'open') + '</td>' +
            '<td class="num">₹' + rs(a.grossPaise) + '</td>' +
            '<td>' + esc(a.payGrade || '—') + '</td>' +
            '<td>' + esc(a.status) + '</td>' +
            '<td>' + (a.lines || []).length + '</td>' +
            '<td>' + esc(a.reason || '') + '</td></tr>';
        }).join('') + '</tbody></table></div>';
    }).catch(function (e) { msg('cmpMsgAsg', esc(e.message), true); });
  }

  function buildCandidate() {
    var lines = TPL_LINES.length ? TPL_LINES.map(function (l) {
      var m = compMeta(l.componentId) || {};
      var o = { componentId: l.componentId };
      if (m.calcType === 'PERCENT') o.rate = parseFloat(l.val || 0);
      if (m.calcType === 'FIXED')   o.amountPaise = toPaise(l.val);
      return o;
    }) : [];

    return {
      staffId: document.getElementById('asgStaff').value,
      effectiveFrom: document.getElementById('aFrom').value,
      effectiveTo: document.getElementById('aTo').value || null,
      grossPaise: toPaise(document.getElementById('aGross').value),
      templateId: document.getElementById('aTpl').value || null,
      payGrade: document.getElementById('aGrade').value || null,
      status: document.getElementById('aStatus').value,
      payBasis: 'MONTHLY_FIXED',
      lines: lines
    };
  }

  function renderPreview(p) {
    var box = document.getElementById('asgPreview');
    var out = '';

    if (!p.valid) {
      out += '<div class="cmp-msg err" style="display:block"><b>Not saveable yet:</b><br>' +
        (p.errors || []).concat(p.overlaps || []).map(esc).join('<br>') + '</div>';
    }

    var split = p.split || {}, comps = split.components || {};
    var keys = Object.keys(comps);
    if (keys.length) {
      out += '<div class="cmp-tblwrap"><table class="cmp-tbl"><thead><tr>' +
        '<th>Component</th><th class="num">Amount</th></tr></thead><tbody>' +
        keys.map(function (k) {
          return '<tr><td>' + esc(k) + '</td><td class="num">₹' + rs(comps[k]) + '</td></tr>';
        }).join('') +
        '<tr><td><b>Assigned</b></td><td class="num"><b>₹' + rs(split.assignedPaise) + '</b></td></tr>' +
        (split.balanced
          ? ''
          : '<tr><td colspan="2"><span class="cmp-badge b-ded">' +
            (Number(split.remainderPaise) > 0 ? 'Unassigned: ₹' + rs(split.remainderPaise)
             : 'Over-subscribed by ₹' + rs(Math.abs(Number(split.remainderPaise)))) +
            '</span> — add a balancing component so the parts add up to gross.</td></tr>') +
        '</tbody></table></div>';
    }

    var ctc = p.ctc || {};
    out += '<div class="cmp-tiles" style="margin-top:14px">' +
      '<div class="cmp-tile"><div class="k">Gross</div><div class="v" style="font-size:20px">₹' + rs(ctc.grossPaise) + '</div></div>' +
      '<div class="cmp-tile"><div class="k">Employer contributions</div><div class="v" style="font-size:20px">₹' + rs(ctc.employerPaise) + '</div></div>' +
      '<div class="cmp-tile ok"><div class="k">CTC</div><div class="v" style="font-size:20px">₹' + rs(ctc.ctcPaise) + '</div></div>' +
      '</div>';

    var bd = ctc.breakdown || {};
    if (Object.keys(bd).length) {
      out += '<div class="cmp-help">Employer breakdown: ' + Object.keys(bd).map(function (k) {
        return esc(k) + ' ₹' + rs(bd[k]);
      }).join(' · ') + '</div>';
    }

    var band = p.band || {};
    if (band.severity === 'warning') {
      out += '<div class="cmp-note" style="margin-top:12px"><i class="fa fa-exclamation-triangle"></i> ' +
        esc(band.message) + ' <br><span class="cmp-help">This is a warning, not a block — save it if the exception is intended.</span></div>';
    } else if (band.checked) {
      out += '<div class="cmp-help" style="margin-top:10px"><i class="fa fa-check"></i> ' + esc(band.message) + '</div>';
    }

    box.innerHTML = out;
    document.getElementById('btnSaveAsg').disabled = !p.valid;
    PREVIEWED = p.valid ? p.assignment : null;
  }

  function today() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  document.addEventListener('input', function (ev) {
    if (ev.target.dataset && ev.target.dataset.line !== undefined) {
      TPL_LINES[Number(ev.target.dataset.line)].val = ev.target.value;
      // Any edit invalidates the previous preview — the operator must re-run it
      // before saving, so what is saved is always what was shown.
      document.getElementById('btnSaveAsg') && (document.getElementById('btnSaveAsg').disabled = true);
    }
  });

  document.addEventListener('click', async function (ev) {
    var t = ev.target;

    if (t.id === 'btnAddLine') {
      var code = document.getElementById('tAddComp').value;
      if (!code) return;
      if (TPL_LINES.some(function (l) { return l.componentId === code; })) {
        msg('cmpMsgTpl', 'That component is already in the structure.', true); return;
      }
      TPL_LINES.push({ componentId: code, val: 0 });
      renderTplLines();
    }
    if (t.dataset && t.dataset.rmline !== undefined) {
      TPL_LINES.splice(Number(t.dataset.rmline), 1); renderTplLines();
    }
    if (t.id === 'btnClearTpl') {
      TPL_LINES = []; renderTplLines();
      ['tCode', 'tName'].forEach(function (id) { document.getElementById(id).value = ''; });
    }
    if (t.id === 'btnSaveTpl') {
      var payload = {
        code: document.getElementById('tCode').value.trim(),
        name: document.getElementById('tName').value.trim(),
        payGrade: document.getElementById('tGrade').value || null,
        active: true,
        lines: TPL_LINES.map(function (l) {
          var m = compMeta(l.componentId) || {};
          var o = { componentId: l.componentId };
          if (m.calcType === 'PERCENT') o.rate = parseFloat(l.val || 0);
          if (m.calcType === 'FIXED')   o.amountPaise = toPaise(l.val);
          return o;
        })
      };
      if (!payload.code || !payload.name) { msg('cmpMsgTpl', 'Code and name are required.', true); return; }
      t.disabled = true;
      postJson('compensation/save_template', payload).then(function (d) {
        msg('cmpMsgTpl', esc(d.message || 'Saved.'), false); loadTemplates();
      }).catch(function (e) { msg('cmpMsgTpl', esc(e.message), true); })
        .finally(function () { t.disabled = false; });
    }
    if (t.dataset && t.dataset.ttog) {
      post('compensation/set_template_active', { code: t.dataset.ttog, active: t.dataset.ton }).then(function (d) {
        msg('cmpMsgTpl', esc(d.message || 'Updated.'), false); loadTemplates();
      }).catch(function (e) { msg('cmpMsgTpl', esc(e.message), true); });
    }

    if (t.id === 'btnLoadAsg') loadAssignments();

    if (t.id === 'btnPreview') {
      var cand = buildCandidate();
      if (!cand.staffId) { msg('cmpMsgAsg', 'Select an employee first.', true); return; }
      if (!cand.effectiveFrom) { msg('cmpMsgAsg', 'An effective-from date is required.', true); return; }
      t.disabled = true;
      postJson('compensation/preview_assignment', cand)
        .then(renderPreview)
        .catch(function (e) { msg('cmpMsgAsg', esc(e.message), true); })
        .finally(function () { t.disabled = false; });
    }

    if (t.id === 'btnSaveAsg') {
      if (!PREVIEWED) { msg('cmpMsgAsg', 'Preview the salary first — save only writes what the preview showed.', true); return; }
      var okSave = await zxConfirm({
        title: 'Save this salary?',
        message: 'It will apply to the selected employee, effective ' + PREVIEWED.effectiveFrom + '.',
        confirmText: 'Save salary'
      });
      if (!okSave) return;
      t.disabled = true;
      postJson('compensation/save_salary_assignment', PREVIEWED).then(function (d) {
        msg('cmpMsgAsg', esc(d.message || 'Saved.'), false);
        PREVIEWED = null;
        document.getElementById('asgPreview').innerHTML = '';
        loadAssignments();
      }).catch(function (e) { msg('cmpMsgAsg', esc(e.message), true); t.disabled = false; });
    }
  });

  document.addEventListener('change', function (ev) {
    // Applying a structure fills the component lines from the catalogue, so the
    // operator edits real lines rather than an opaque template reference.
    if (ev.target.id === 'aTpl' && ev.target.value) {
      api('compensation/get_templates').then(function (d) {
        var t = (d.templates || []).filter(function (x) { return x.code === ev.target.value; })[0];
        if (!t) return;
        TPL_LINES = (t.lines || []).map(function (l) {
          return { componentId: l.componentId,
                   val: l.calcType === 'PERCENT' ? (l.rate || 0)
                      : (l.amountPaise ? Number(l.amountPaise) / 100 : 0) };
        });
        if (t.payGrade) document.getElementById('aGrade').value = t.payGrade;
        renderTplLines();
        msg('cmpMsgAsg', 'Structure applied — enter the gross and press Preview.', false);
      });
    }
  });

  // ── Boot ──
  var TAB = '<?= $at ?>';
  if (TAB === 'templates') { loadTemplates(); fillGradeSelects(); renderTplLines(); }
  if (TAB === 'assignments') {
    loadCatalogue().then(fillGradeSelects).then(loadStaffSelect).then(function () {
      return api('compensation/get_templates?active_only=1');
    }).then(function (d) {
      var sel = document.getElementById('aTpl');
      if (sel) sel.innerHTML = '<option value="">— build manually —</option>' +
        (d.templates || []).map(function (t) {
          return '<option value="' + esc(t.code) + '">' + esc(t.code) + ' — ' + esc(t.name) + '</option>';
        }).join('');
    }).catch(function (e) { msg('cmpMsgAsg', esc(e.message), true); });
    document.getElementById('aFrom').value = today();
    renderTplLines();
  }
  // ── Wage definition / classification ──────────────────────────────────
  function loadClassifications() {
    var asOf = document.getElementById('clsAsOf').value || today();
    // api() is the page's existing helper: it rejects on a non-ok response OR
    // an {status:'error'} payload, and rotates the CSRF hash. Reusing it is
    // what keeps this screen failing closed like every other one here.
    api('compensation/get_component_classifications?as_of=' + encodeURIComponent(asOf))
      .then(function (d) {
        var rows = (d.components || []).map(function (c) {
          var differs = c.statutoryDefault && c.resolved !== c.statutoryDefault;
          return '<tr>' +
            '<td><b>' + esc(c.code) + '</b><br><span class="cmp-help">' + esc(c.name) + '</span></td>' +
            '<td>' + esc(c.statutoryDefault || '—') + '</td>' +
            '<td>' + (differs ? '<b style="color:#BC5A3C">' + esc(c.resolved) + '</b>'
                              : esc(c.resolved)) +
              '<br><span class="cmp-help">' + esc(c.source) + '</span></td>' +
            '<td>' + esc(c.effectiveFrom || '—') + '</td>' +
            '<td>' + esc(c.reason || '—') + '</td>' +
            '<td>' + esc(c.verifiedBy || '—') + '</td>' +
            (CAN_MANAGE ? '<td><button class="cmp-btn" data-cls="' + esc(c.code) + '">Change</button></td>' : '') +
            '</tr>';
        }).join('');
        document.querySelector('#clsTable tbody').innerHTML =
          rows || '<tr><td colspan="7">No components yet.</td></tr>';
      })
      .catch(function (e) { msg('cmpMsgCls', esc(e.message), true); });
  }

  // ── Full &amp; Final policy ────────────────────────────────────────────
  function loadFnfPolicy() {
    api('compensation/get_fnf_policy')
      .then(function (d) {
        var p = d.policy || {};
        document.getElementById('fnfBasis').value    = (p.noticeRecovery || {}).basis || 'unset';
        document.getElementById('fnfNote').value     = (p.noticeRecovery || {}).note || '';
        document.getElementById('fnfApproval').value = (p.approval || {}).mode || 'single';
        var th = (p.approval || {}).dualThresholdPaise;
        document.getElementById('fnfThreshold').value = (th === null || th === undefined) ? '' : (th / 100);
        document.getElementById('fnfClearance').value = ((p.clearance || {}).required || []).join(', ');
        document.getElementById('fnfOverride').value  = (p.clearance || {}).overrideRole || '';

        var miss = (d.completeness || {}).missing || [];
        document.getElementById('fnfIncomplete').innerHTML = miss.length
          ? '<div class="cmp-note" style="border-color:#BC5A3C"><i class="fa fa-exclamation-triangle"></i> ' +
            'Configuration required before a settlement can be computed: <b>' +
            esc(miss.join(', ')) + '</b></div>'
          : '';
      })
      .catch(function (e) { msg('cmpMsgFnf', esc(e.message), true); });
  }

  // Wire the classification form. Delegated, because the rows are re-rendered.
  function wireClassificationForm() {
    var card = document.getElementById('clsFormCard');
    if (!card) return;   // view-only user: the form is not rendered at all

    document.getElementById('clsTable').addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-cls]');
      if (!b) return;
      document.getElementById('clsCode').value = b.getAttribute('data-cls');
      document.getElementById('clsFrom').value = today();
      document.getElementById('clsReason').value = '';
      card.style.display = '';
      card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });

    document.getElementById('clsCancel').addEventListener('click', function () {
      card.style.display = 'none';
    });

    document.getElementById('clsSave').addEventListener('click', function () {
      var payload = {
        code:           document.getElementById('clsCode').value,
        classification: document.getElementById('clsValue').value,
        effectiveFrom:  document.getElementById('clsFrom').value,
        reason:         document.getElementById('clsReason').value,
        verifiedBy:     document.getElementById('clsVerifiedBy').value,
        verifiedAt:     today()
      };

      // postJson() carries the CSRF token. A raw fetch() here would be rejected
      // by CI's global CSRF filter with a BLANK 403 — no log, no console error,
      // and the save would silently never happen.
      postJson('compensation/save_component_classification', payload)
        .then(function (d) {
          msg('cmpMsgCls', esc((d && d.message) || 'Saved.'), false);
          card.style.display = 'none';
          loadClassifications();
        })
        .catch(function (e) { msg('cmpMsgCls', esc(e.message), true); });
    });
  }

  function wireFnfForm() {
    var btn = document.getElementById('fnfSave');
    if (!btn) return;   // view-only user

    btn.addEventListener('click', function () {
      var thRupees = document.getElementById('fnfThreshold').value;
      var required = document.getElementById('fnfClearance').value
        .split(',').map(function (s) { return s.trim(); }).filter(Boolean);

      var payload = {
        noticeRecovery: {
          basis: document.getElementById('fnfBasis').value,
          note:  document.getElementById('fnfNote').value
        },
        approval: {
          mode: document.getElementById('fnfApproval').value,
          // Rupees in the field, paise on the wire — the server stores paise.
          dualThresholdPaise: thRupees === '' ? null : Math.round(parseFloat(thRupees) * 100)
        },
        clearance: {
          required:     required,
          overrideRole: document.getElementById('fnfOverride').value
        }
      };

      postJson('compensation/save_fnf_policy', payload)
        .then(function (d) {
          msg('cmpMsgFnf', esc((d && d.message) || 'Saved.'), false);
          loadFnfPolicy();
        })
        .catch(function (e) { msg('cmpMsgFnf', esc(e.message), true); });
    });
  }
  // ── F&F preview ───────────────────────────────────────────────────────
  function rupees(p) {
    return '₹' + ((p || 0) / 100).toLocaleString('en-IN', { minimumFractionDigits: 2 });
  }

  function renderProjection(p) {
    var rows = (p.lines || []).map(function (l) {
      return '<tr><td>' + esc(l.label) + '<br><span class="cmp-help">' + esc(l.note) + '</span></td>' +
             '<td class="num">' + rupees(l.amountPaise) + '</td></tr>';
    }).join('');

    var blockers = (p.blockers || []).length
      ? '<div class="cmp-note" style="border-color:#BC5A3C"><i class="fa fa-exclamation-triangle"></i> ' +
        esc(p.blockers.join('; ')) + '</div>'
      : '';

    var gov = '<div class="cmp-help" style="margin-top:8px">' +
      'Clearance: ' + (p.clearance && p.clearance.canApprove ? 'clear to approve' : esc((p.clearance || {}).reason || '—')) +
      ' &middot; Approvals needed: ' + ((p.approvalsRequired || {}).count || 1) +
      ' &middot; Basis: ' + esc((p.configurationUsed || {}).noticeBasis || 'unset') + '</div>';

    return blockers +
      '<div class="cmp-tblwrap"><table class="cmp-tbl">' +
      '<thead><tr><th>Line</th><th class="num">Amount</th></tr></thead><tbody>' + rows +
      '<tr><td><b>Net payable</b></td><td class="num"><b>' + rupees(p.netPayablePaise) + '</b></td></tr>' +
      (p.receivablePaise ? '<tr><td><b>Recoverable from employee</b></td><td class="num"><b>' +
        rupees(p.receivablePaise) + '</b></td></tr>' : '') +
      '</tbody></table></div>' + gov;
  }

  function runPreview() {
    var staff = (document.getElementById('pvStaff').value || '').trim();
    if (!staff) { msg('cmpMsgPv', 'Enter a staff ID.', true); return; }

    var basis = document.getElementById('pvBasis').value;
    var url = 'compensation/preview_fnf?staff_id=' + encodeURIComponent(staff) +
              (basis ? '&proposed_basis=' + encodeURIComponent(basis) : '');

    api(url)
      .then(function (d) {
        if (d && d.current && d.proposed) {
          var deltas = (d.lineDeltas || []).map(function (x) {
            return '<tr><td>' + esc(x.code) + '</td><td class="num">' + rupees(x.fromPaise) +
                   '</td><td class="num">' + rupees(x.toPaise) + '</td><td class="num">' +
                   rupees(x.deltaPaise) + '</td></tr>';
          }).join('');
          document.getElementById('pvOut').innerHTML =
            '<div class="cmp-tblwrap"><table class="cmp-tbl"><thead><tr>' +
            '<th>Line</th><th class="num">Current</th><th class="num">Proposed</th><th class="num">Change</th>' +
            '</tr></thead><tbody>' + (deltas || '<tr><td colspan="4">No change.</td></tr>') +
            '<tr><td><b>Net</b></td><td class="num">' + rupees(d.current.netPayablePaise) +
            '</td><td class="num">' + rupees(d.proposed.netPayablePaise) +
            '</td><td class="num"><b>' + rupees(d.netDelta) + '</b></td></tr>' +
            '</tbody></table></div>';
        } else {
          document.getElementById('pvOut').innerHTML = renderProjection(d);
        }
        msg('cmpMsgPv', 'Preview only — nothing was created or paid.', false);
      })
      .catch(function (e) { msg('cmpMsgPv', esc(e.message), true); });
  }

  // ── Employee statutory profile ────────────────────────────────────────
  function loadStatProfile() {
    var staff = (document.getElementById('spStaff').value || '').trim();
    if (!staff) { msg('cmpMsgSp', 'Enter a staff ID.', true); return; }

    api('compensation/get_staff_statutory_profile?staff_id=' + encodeURIComponent(staff))
      .then(function (d) {
        var pf = (d.profile || {}).pf || {};
        document.getElementById('spState').value = pf.state || 'undetermined';
        document.getElementById('spBasis').value = pf.basis || 'undetermined';
        document.getElementById('spUan').value   = pf.uan || '';
        document.getElementById('spSince').value = pf.memberSince || '';
        document.getElementById('spForm').style.display = '';

        var miss = (d.completeness || {}).missing || [];
        msg('cmpMsgSp', miss.length
          ? 'Loaded ' + esc(d.staff_name || staff) + '. Still needed: ' + esc(miss.join(', '))
          : 'Loaded ' + esc(d.staff_name || staff) + '. Profile complete.', false);
      })
      .catch(function (e) {
        document.getElementById('spForm').style.display = 'none';
        msg('cmpMsgSp', esc(e.message), true);
      });
  }

  function saveStatProfile() {
    var staff = (document.getElementById('spStaff').value || '').trim();
    postJson('compensation/save_staff_statutory_profile?staff_id=' + encodeURIComponent(staff), {
      profile: {
        pf: {
          state:       document.getElementById('spState').value,
          basis:       document.getElementById('spBasis').value,
          uan:         document.getElementById('spUan').value,
          memberSince: document.getElementById('spSince').value
        }
      }
    })
      .then(function (d) { msg('cmpMsgSp', esc((d && d.message) || 'Saved.'), false); })
      .catch(function (e) { msg('cmpMsgSp', esc(e.message), true); });
  }

  if (TAB === 'fnf') {
    var pvBtn = document.getElementById('pvRun');
    if (pvBtn) pvBtn.addEventListener('click', runPreview);
  }
  if (TAB === 'assignments') {
    var card = document.getElementById('cardStatProfile');
    if (card) {
      card.style.display = '';
      document.getElementById('spLoad').addEventListener('click', loadStatProfile);
      var sv = document.getElementById('spSave');
      if (sv) sv.addEventListener('click', saveStatProfile);
    }
  }
  if (TAB === 'classification') {
    document.getElementById('clsAsOf').value = today();
    loadClassifications();
    document.getElementById('clsReload').addEventListener('click', loadClassifications);
    wireClassificationForm();
  }
  if (TAB === 'fnf') { loadFnfPolicy(); wireFnfForm(); loadSettlements(); }
  if (TAB === 'components') loadComponents();
  if (TAB === 'grades')     loadGrades();
  if (TAB === 'statutory')  loadStatutory();
  if (TAB === 'readiness') {
    var d = new Date();
    document.getElementById('rdyDate').value =
      d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    loadReadiness();
  }
})();
</script>
