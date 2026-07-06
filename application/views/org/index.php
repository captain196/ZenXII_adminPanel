<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<!-- ============================================================
     Departments & Roles — org structure setup (redesigned)
     Reads /org/get_data; departments via hr/save_department; roles via org/*.
     Self-contained, theme-aware (light / night).
     ============================================================ -->
<div class="content-wrapper">
<div class="orgx">

  <div id="orgAlert" class="orgx-alert" hidden></div>

  <!-- Hero -->
  <div class="orgx-hero">
    <div class="orgx-hero-icon"><i class="fa fa-sitemap"></i></div>
    <div class="orgx-hero-text">
      <h1 class="orgx-title">Departments &amp; Roles</h1>
      <p class="orgx-sub">Your org structure in one place. Create departments, define staff roles, then choose which roles live in each department.</p>
    </div>
    <div class="orgx-hero-actions">
      <button class="orgx-btn orgx-btn-ghost" id="suggestBtn" title="Auto-suggest roles for each department by name & category"><i class="fa fa-magic"></i> Suggest mapping</button>
    </div>
  </div>

  <!-- Concept guide (dismissible) -->
  <div class="orgx-guide" id="orgGuide">
    <div class="orgx-guide-flow">
      <span class="orgx-guide-step"><b>1</b> Create departments</span>
      <i class="fa fa-arrow-right"></i>
      <span class="orgx-guide-step"><b>2</b> Define staff roles</span>
      <i class="fa fa-arrow-right"></i>
      <span class="orgx-guide-step"><b>3</b> Assign roles to departments</span>
    </div>
    <p class="orgx-guide-note">A <b>role is a subset of a department</b> — once set, staff forms, imports and the Excel template only offer a department's own roles.</p>
    <button class="orgx-guide-x" id="orgGuideX" title="Dismiss">&times;</button>
  </div>

  <!-- Summary tiles -->
  <div class="orgx-tiles">
    <div class="orgx-tile"><div class="orgx-tile-n" id="tDept">0</div><div class="orgx-tile-l"><i class="fa fa-building-o"></i> Departments</div></div>
    <div class="orgx-tile"><div class="orgx-tile-n" id="tRole">0</div><div class="orgx-tile-l"><i class="fa fa-id-badge"></i> Staff roles</div></div>
    <div class="orgx-tile warn" id="tNeedWrap" hidden><div class="orgx-tile-n" id="tNeed">0</div><div class="orgx-tile-l"><i class="fa fa-exclamation-triangle"></i> Need roles</div></div>
  </div>

  <div class="orgx-grid">
    <!-- DEPARTMENTS -->
    <section class="orgx-panel">
      <div class="orgx-panel-head">
        <h2><i class="fa fa-building-o"></i> Departments</h2>
        <div style="display:flex;gap:8px;">
          <button class="orgx-btn orgx-btn-ghost orgx-btn-sm" id="seedDeptBtn" title="Create the common departments in one click"><i class="fa fa-magic"></i> Seed defaults</button>
          <button class="orgx-btn orgx-btn-primary" id="addDeptBtn"><i class="fa fa-plus"></i> Add Department</button>
        </div>
      </div>
      <div id="deptList" class="dcard-grid"><div class="orgx-empty">Loading…</div></div>
    </section>

    <!-- ROLES -->
    <aside class="orgx-panel orgx-panel-side">
      <div class="orgx-panel-head">
        <h2><i class="fa fa-id-badge"></i> Roles</h2>
        <button class="orgx-btn orgx-btn-primary orgx-btn-sm" id="addRoleBtn"><i class="fa fa-plus"></i> Add</button>
      </div>
      <p class="orgx-side-hint">Job functions you assign to departments. <b>System</b> roles can't be renamed or deleted — only their capabilities.</p>
      <div id="roleList" class="rrow-list"><div class="orgx-empty">Loading…</div></div>
    </aside>
  </div>

  <!-- ── Department modal ─────────────────────────────────────── -->
<div class="orgx-modal" id="deptModal" aria-hidden="true">
  <div class="orgx-modal-box">
    <div class="orgx-modal-head">
      <h3 id="deptModalTitle">Add Department</h3>
      <button class="orgx-x" data-close-dept>&times;</button>
    </div>
    <div class="orgx-modal-body">
      <input type="hidden" id="deptId">
      <div class="orgx-field">
        <label>Department name <span class="req">*</span></label>
        <input type="text" id="deptName" class="orgx-input" placeholder="e.g. Academic, Finance, Operations" maxlength="60">
      </div>
      <div class="orgx-field">
        <label>Description</label>
        <input type="text" id="deptDesc" class="orgx-input" placeholder="Optional" maxlength="160">
      </div>
      <div class="orgx-field-row">
        <div class="orgx-field" style="flex:2;">
          <label>Head of Department</label>
          <select id="deptHeadSelect" class="orgx-input"><option value="">— None —</option></select>
        </div>
        <div class="orgx-field" style="flex:1;">
          <label>Status</label>
          <select id="deptStatus" class="orgx-input"><option value="Active">Active</option><option value="Inactive">Inactive</option></select>
        </div>
      </div>
      <div class="orgx-field">
        <label>Roles allowed here <span class="orgx-count-badge" id="deptRoleCount">0</span></label>
        <p class="orgx-field-hint">Tick the roles staff in this department can hold. These are what the staff form &amp; Excel dropdown will offer.</p>
        <div id="deptRoleChecks" class="chip-pick"></div>
      </div>
    </div>
    <div class="orgx-modal-foot">
      <button class="orgx-btn orgx-btn-ghost" data-close-dept>Cancel</button>
      <button class="orgx-btn orgx-btn-primary" id="saveDeptBtn"><i class="fa fa-check"></i> Save Department</button>
    </div>
  </div>
</div>

<!-- ── Role modal ───────────────────────────────────────────── -->
<div class="orgx-modal" id="roleModal" aria-hidden="true">
  <div class="orgx-modal-box">
    <div class="orgx-modal-head">
      <h3 id="roleModalTitle">Add Staff Role</h3>
      <button class="orgx-x" data-close-role>&times;</button>
    </div>
    <div class="orgx-modal-body">
      <div id="roleSysNote" class="orgx-note" hidden><i class="fa fa-lock"></i> System role — you can adjust capabilities, but not its name, ID or category.</div>
      <div class="orgx-field-row">
        <div class="orgx-field" style="flex:1;">
          <label>Role ID <span class="req">*</span></label>
          <input type="text" id="roleId" class="orgx-input mono" placeholder="ROLE_COUNSELLOR" maxlength="40">
        </div>
        <div class="orgx-field" style="flex:1;">
          <label>Display label <span class="req">*</span></label>
          <input type="text" id="roleLabel" class="orgx-input" placeholder="Counsellor" maxlength="40">
        </div>
      </div>
      <p class="orgx-field-hint" style="margin-top:-6px;">Role ID is uppercase, starts with <code>ROLE_</code>, and can't change later.</p>
      <div class="orgx-field-row">
        <div class="orgx-field" style="flex:1;">
          <label>Category <span class="req">*</span></label>
          <select id="roleCategory" class="orgx-input"><option>Teaching</option><option>Non-Teaching</option><option>Administrative</option><option>Support</option></select>
        </div>
        <div class="orgx-field" style="flex:1;">
          <label>Attendance type</label>
          <select id="roleAttendance" class="orgx-input"><option value="standard">Standard</option><option value="shift">Shift</option><option value="flexible">Flexible</option></select>
        </div>
      </div>
      <div class="orgx-field">
        <label>Capabilities</label>
        <div id="roleFlags" class="chip-pick"></div>
      </div>
    </div>
    <div class="orgx-modal-foot">
      <button class="orgx-btn orgx-btn-danger" id="deleteRoleBtn" hidden style="margin-right:auto;"><i class="fa fa-trash"></i> Delete</button>
      <button class="orgx-btn orgx-btn-ghost" data-close-role>Cancel</button>
      <button class="orgx-btn orgx-btn-primary" id="saveRoleBtn"><i class="fa fa-check"></i> Save Role</button>
    </div>
  </div>
</div>

</div><!-- /.orgx -->
</div><!-- /.content-wrapper -->

<style>
  .orgx {
    --bg:#f1f5f9; --surface:#fff; --surface-2:#f8fafc; --ink:#0f172a; --ink-2:#334155;
    --muted:#64748b; --faint:#94a3b8; --border:#e2e8f0; --border-2:#cbd5e1;
    --brand:#2563eb; --brand-d:#1d4ed8; --brand-soft:#eff6ff; --brand-bd:#bfdbfe;
    --ok:#16a34a; --ok-soft:#dcfce7; --warn:#b45309; --warn-soft:#fef3c7; --warn-bd:#fde68a;
    --danger:#dc2626; --danger-soft:#fef2f2; --danger-bd:#fecaca;
    --shadow:0 1px 2px rgba(16,24,40,.06),0 1px 3px rgba(16,24,40,.05); --shadow-lg:0 16px 40px rgba(16,24,40,.18);
    max-width:1200px; margin:0 auto; padding:22px 16px 60px; color:var(--ink);
    font-family:'Plus Jakarta Sans',system-ui,sans-serif;
  }
  html[data-theme="night"] .orgx {
    --bg:#0b1220; --surface:#111a2e; --surface-2:#0f1728; --ink:#e5e9f0; --ink-2:#cbd5e1;
    --muted:#94a3b8; --faint:#64748b; --border:#23304a; --border-2:#33415c;
    --brand:#3b82f6; --brand-d:#2563eb; --brand-soft:#12233f; --brand-bd:#1e3a5f;
    --ok:#34d399; --ok-soft:#0f2e22; --warn:#fbbf24; --warn-soft:#3a2c0a; --warn-bd:#5b4708;
    --danger:#f87171; --danger-soft:#3a1414; --danger-bd:#5b2020;
    --shadow:0 1px 2px rgba(0,0,0,.4); --shadow-lg:0 18px 44px rgba(0,0,0,.55);
  }

  .orgx-hero { display:flex; align-items:center; gap:14px; margin-bottom:14px; }
  .orgx-hero-icon { width:52px; height:52px; border-radius:14px; background:linear-gradient(135deg,var(--brand),var(--brand-d)); color:#fff; display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0; box-shadow:var(--shadow); }
  .orgx-hero-text { flex:1; min-width:0; }
  .orgx-title { font-size:1.55rem; font-weight:800; margin:0; letter-spacing:-.02em; }
  .orgx-sub { color:var(--muted); font-size:.9rem; margin:2px 0 0; }
  .orgx-hero-actions { flex-shrink:0; }

  .orgx-guide { position:relative; background:var(--brand-soft); border:1px solid var(--brand-bd); border-radius:12px; padding:12px 40px 12px 16px; margin-bottom:16px; }
  .orgx-guide-flow { display:flex; align-items:center; gap:10px; flex-wrap:wrap; font-size:.86rem; }
  .orgx-guide-step { display:inline-flex; align-items:center; gap:7px; font-weight:600; color:var(--brand-d); }
  .orgx-guide-step b { width:20px; height:20px; border-radius:50%; background:var(--brand); color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:.76rem; }
  .orgx-guide-flow i { color:var(--brand); opacity:.6; }
  .orgx-guide-note { margin:8px 0 0; font-size:.86rem; color:var(--ink-2); }
  .orgx-guide-x { position:absolute; top:8px; right:10px; border:none; background:transparent; color:var(--brand-d); font-size:1.3rem; line-height:1; cursor:pointer; opacity:.6; }
  .orgx-guide-x:hover { opacity:1; }

  .orgx-tiles { display:flex; gap:12px; margin-bottom:18px; flex-wrap:wrap; }
  .orgx-tile { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:12px 18px; min-width:130px; box-shadow:var(--shadow); }
  .orgx-tile-n { font-size:1.5rem; font-weight:800; line-height:1; }
  .orgx-tile-l { font-size:.82rem; color:var(--muted); margin-top:4px; display:flex; align-items:center; gap:6px; }
  .orgx-tile.warn { background:var(--warn-soft); border-color:var(--warn-bd); }
  .orgx-tile.warn .orgx-tile-n, .orgx-tile.warn .orgx-tile-l { color:var(--warn); }

  .orgx-grid { display:grid; grid-template-columns:1.6fr 1fr; gap:18px; align-items:start; }
  @media (max-width:920px){ .orgx-grid{ grid-template-columns:1fr; } }
  .orgx-panel { background:var(--surface-2); border:1px solid var(--border); border-radius:14px; padding:16px; box-shadow:var(--shadow); }
  .orgx-panel-head { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:12px; }
  .orgx-panel-head h2 { font-size:1.05rem; font-weight:700; margin:0; display:flex; align-items:center; gap:8px; }
  .orgx-side-hint { color:var(--faint); font-size:.84rem; margin:0 0 12px; line-height:1.4; }

  .orgx-btn { display:inline-flex; align-items:center; gap:6px; border:1px solid transparent; border-radius:9px; padding:8px 14px; font-size:.9rem; font-weight:700; cursor:pointer; text-decoration:none; }
  .orgx-btn-sm { padding:6px 11px; font-size:.84rem; }
  .orgx-btn-primary { background:var(--brand); color:#fff; }
  .orgx-btn-primary:hover { background:var(--brand-d); }
  .orgx-btn-ghost { background:var(--surface-2); color:var(--ink-2); border-color:var(--border); }
  .orgx-btn-ghost:hover { background:var(--border); }
  .orgx-btn-danger { background:var(--danger-soft); color:var(--danger); border-color:var(--danger-bd); }
  .orgx-btn:disabled { opacity:.55; cursor:not-allowed; }

  /* Department cards — white & elevated so they read as distinct tiles */
  .dcard-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(244px,1fr)); gap:14px; }
  .dcard { border:1px solid var(--border); border-radius:12px; padding:16px; background:var(--surface); cursor:pointer; transition:transform .15s,box-shadow .15s,border-color .15s; display:flex; flex-direction:column; box-shadow:0 1px 2px rgba(16,24,40,.05),0 1px 3px rgba(16,24,40,.09); }
  .dcard:hover { border-color:var(--brand); box-shadow:0 10px 24px rgba(37,99,235,.15); transform:translateY(-2px); }
  .dcard.inactive { opacity:.6; }
  .dcard.needs { border-color:var(--warn-bd); }
  .dcard-top { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; }
  .dcard-name { font-weight:700; font-size:1.06rem; line-height:1.2; }
  .dcard-meta { color:var(--muted); font-size:.83rem; margin-top:4px; }
  .pill { font-size:.72rem; font-weight:800; padding:3px 9px; border-radius:999px; white-space:nowrap; }
  .pill.Active { background:var(--ok-soft); color:var(--ok); }
  .pill.Inactive { background:var(--border); color:var(--muted); }
  .dcard-roles { display:flex; flex-wrap:wrap; gap:6px; margin:12px 0; flex:1; align-content:flex-start; min-height:26px; }
  .chip { background:var(--brand-soft); color:var(--brand-d); border:1px solid var(--brand-bd); border-radius:999px; padding:3px 10px; font-size:.8rem; font-weight:600; }
  .chip-more { background:var(--surface-2); color:var(--muted); border:1px solid var(--border); border-radius:999px; padding:3px 9px; font-size:.8rem; }
  .assign-prompt { display:inline-flex; align-items:center; gap:6px; color:var(--warn); font-size:.82rem; font-weight:600; background:var(--surface-2); border:1px dashed var(--warn-bd); border-radius:8px; padding:5px 11px; }
  .dcard-foot { display:flex; align-items:center; justify-content:space-between; border-top:1px solid var(--border); padding-top:10px; margin-top:2px; }
  .dcard-count { font-size:.83rem; color:var(--muted); font-weight:600; }
  .dcard-acts { display:flex; gap:4px; }
  .icobtn { border:none; background:transparent; color:var(--muted); width:30px; height:30px; border-radius:7px; cursor:pointer; font-size:.9rem; }
  .icobtn:hover { background:var(--surface-2); color:var(--brand); }
  .icobtn.del:hover { background:var(--danger-soft); color:var(--danger); }
  .dcard-add { border:2px dashed var(--border-2); background:var(--surface); border-radius:12px; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px; color:var(--muted); cursor:pointer; min-height:130px; font-weight:600; font-size:.92rem; }
  .dcard-add:hover { border-color:var(--brand); color:var(--brand); background:var(--brand-soft); }
  .dcard-add i { font-size:22px; }

  /* Roles library */
  .rrow-list { display:flex; flex-direction:column; gap:5px; max-height:560px; overflow:auto; }
  .rcat { font-size:.72rem; font-weight:800; letter-spacing:.5px; text-transform:uppercase; color:var(--faint); margin:10px 2px 3px; }
  .rrow { display:flex; align-items:center; justify-content:space-between; gap:8px; border:1px solid var(--border); border-radius:9px; padding:9px 11px; cursor:pointer; background:var(--surface); }
  .rrow:hover { border-color:var(--brand); background:var(--brand-soft); }
  .rrow-l { display:flex; align-items:center; gap:7px; min-width:0; }
  .rrow-label { font-weight:600; font-size:.91rem; }
  .badge { font-size:.67rem; font-weight:800; border-radius:4px; padding:1px 5px; text-transform:uppercase; letter-spacing:.3px; }
  .badge.sys { background:var(--surface-2); color:var(--muted); border:1px solid var(--border); }
  .badge.cst { background:var(--ok-soft); color:var(--ok); }
  .rrow-meta { font-size:.8rem; color:var(--faint); white-space:nowrap; }

  /* Chip pickers (modal) */
  .chip-pick { display:flex; flex-wrap:wrap; gap:7px; max-height:220px; overflow:auto; padding:2px; }
  .chip-pick label { display:inline-flex; align-items:center; gap:7px; border:1px solid var(--border); border-radius:999px; padding:6px 12px; font-size:.88rem; cursor:pointer; background:var(--surface); user-select:none; transition:all .12s; }
  .chip-pick label:has(input:checked) { background:var(--brand-soft); border-color:var(--brand); color:var(--brand-d); font-weight:600; }
  .chip-pick input { accent-color:var(--brand); }
  .chip-pick .rc-cat { font-size:.71rem; color:var(--faint); }

  .orgx-empty { color:var(--faint); font-size:.86rem; padding:24px; text-align:center; grid-column:1/-1; }
  .orgx-alert { padding:10px 14px; border-radius:9px; font-size:.86rem; margin-bottom:14px; }
  .orgx-alert.ok  { background:var(--ok-soft); color:var(--ok); border:1px solid var(--ok); }
  .orgx-alert.err { background:var(--danger-soft); color:var(--danger); border:1px solid var(--danger); }

  /* Fields */
  .orgx-field { margin-bottom:12px; }
  .orgx-field-row { display:flex; gap:12px; }
  .orgx-field label { display:block; font-size:.84rem; font-weight:600; color:var(--ink-2); margin-bottom:5px; }
  .orgx-field-hint { color:var(--faint); font-size:.82rem; margin:0 0 8px; line-height:1.4; }
  .req { color:var(--danger); }
  .orgx-count-badge { background:var(--brand-soft); color:var(--brand-d); border-radius:999px; padding:0 8px; font-size:.76rem; font-weight:700; margin-left:4px; }
  .orgx-input { width:100%; border:1px solid var(--border-2); border-radius:9px; padding:9px 11px; font-size:.88rem; color:var(--ink); background:var(--surface); box-sizing:border-box; }
  .orgx-input:focus { outline:none; border-color:var(--brand); box-shadow:0 0 0 3px var(--brand-soft); }
  .mono, .orgx-input.mono { font-family:'JetBrains Mono',monospace; }
  .orgx-note { background:var(--brand-soft); border:1px solid var(--brand-bd); color:var(--brand-d); font-size:.84rem; padding:8px 11px; border-radius:8px; margin-bottom:12px; }

  /* Modal */
  .orgx-modal { position:fixed; inset:0; background:rgba(2,6,23,.55); display:none; align-items:flex-start; justify-content:center; z-index:9999; padding:32px 14px; overflow:auto; }
  .orgx-modal.open { display:flex; }
  .orgx-modal-box { background:var(--surface); border:1px solid var(--border); border-radius:16px; width:100%; max-width:580px; box-shadow:var(--shadow-lg); }
  .orgx-modal-head { display:flex; justify-content:space-between; align-items:center; padding:16px 18px; border-bottom:1px solid var(--border); }
  .orgx-modal-head h3 { margin:0; font-size:1.08rem; font-weight:700; }
  .orgx-x { background:none; border:none; font-size:1.6rem; line-height:1; color:var(--faint); cursor:pointer; }
  .orgx-modal-body { padding:16px 18px; }
  .orgx-modal-foot { display:flex; gap:10px; justify-content:flex-end; align-items:center; padding:14px 18px; border-top:1px solid var(--border); }

  /* ── Neutralize the panel's global `label {…!important}` (header-inline.css:473)
     which was uppercasing/bolding/recoloring our labels and jamming the checkbox
     against its text. Scoped + !important so our design wins; explicit checkbox
     margin instead of relying on flex gap. ── */
  .orgx label { text-transform:none !important; letter-spacing:normal !important; }
  .orgx-field > label { color:var(--ink-2) !important; font-size:.84rem !important; font-weight:600 !important; }
  .orgx .chip-pick label {
    display:inline-flex !important; align-items:center !important;
    font-size:.88rem !important; font-weight:600 !important; color:var(--ink-2) !important;
    padding:6px 12px; margin:0 !important; line-height:1.2; cursor:pointer;
  }
  .orgx .chip-pick label:has(input:checked) { color:var(--brand-d) !important; }
  .orgx .chip-pick label input[type="checkbox"] {
    position:static !important; margin:0 8px 0 0 !important;
    width:15px; height:15px; min-width:15px; flex:0 0 auto; accent-color:var(--brand);
  }
  .orgx .chip-pick label .rc-cat {
    font-size:.71rem !important; font-weight:600 !important; color:var(--faint) !important;
    text-transform:uppercase !important; letter-spacing:.3px !important; margin-left:6px;
  }
</style>

<script>
(function () {
  var BASE      = <?= json_encode(rtrim(base_url(), '/')) ?>;
  var CSRF_NAME = (document.querySelector('meta[name="csrf-name"]')  || {}).content || 'csrf_token';
  var CSRF_HASH = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var CHIP_LIMIT = 5;

  var DEPARTMENTS = [], ROLES = [], ROLE_BY_ID = {}, STAFF = [];
  var SEED_DEPTS = ['Academic','Administration','Finance','HR','Operations','Library','Medical','Admissions'];
  var CAP_FLAGS = [
    { key:'can_teach', label:'Can teach' }, { key:'can_access_timetable', label:'Timetable access' },
    { key:'can_handle_fees', label:'Handle fees' }, { key:'can_manage_library', label:'Manage library' },
    { key:'can_manage_transport', label:'Manage transport' }, { key:'can_manage_hostel', label:'Manage hostel' }
  ];
  var CATEGORIES = ['Teaching','Non-Teaching','Administrative','Support'];

  function el(id){ return document.getElementById(id); }
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
  function alertMsg(kind,msg){ var a=el('orgAlert'); a.className='orgx-alert '+kind; a.textContent=msg; a.hidden=false; if(kind==='ok') setTimeout(function(){a.hidden=true;},4000); }

  function post(path,obj){
    var fd=new FormData();
    Object.keys(obj).forEach(function(k){ var v=obj[k]; if(Array.isArray(v)) v.forEach(function(it){fd.append(k+'[]',it);}); else fd.append(k,v); });
    fd.append(CSRF_NAME,CSRF_HASH);
    return fetch(BASE+path,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){return r.text();}).then(function(raw){ try{return JSON.parse(raw);}catch(e){console.error(path,raw.slice(0,400));throw new Error('Server returned an unexpected response.');} });
  }

  function load(){
    fetch(BASE+'/org/get_data',{headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json();})
      .then(function(resp){ DEPARTMENTS=resp.departments||[]; ROLES=resp.roles||[]; ROLE_BY_ID={}; ROLES.forEach(function(r){ROLE_BY_ID[r.id]=r;}); renderAll(); })
      .catch(function(e){ alertMsg('err','Could not load data: '+(e.message||e)); });
  }

  // Staff list powers the Head-of-Department picker (reuses the HR endpoint).
  function loadStaff(){
    fetch(BASE+'/hr/get_staff_list',{headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json();})
      .then(function(resp){ STAFF=resp.staff||(resp.data&&resp.data.staff)||[]; fillHeadSelect(); })
      .catch(function(){});
  }
  function fillHeadSelect(){
    var sel=el('deptHeadSelect'); if(!sel) return; var cur=sel.value;
    sel.innerHTML='<option value="">— None —</option>'+STAFF.map(function(s){ return '<option value="'+esc(s.id)+'">'+esc(s.name||s.Name||s.id)+'</option>'; }).join('');
    sel.value=cur;
  }

  function roleLabel(id){ return (ROLE_BY_ID[id]&&ROLE_BY_ID[id].label)||id.replace(/^ROLE_/,'').replace(/_/g,' '); }
  function isActive(d){ return (d.status||'Active')!=='Inactive'; }

  function renderAll(){ renderTiles(); renderDepts(); renderRoles(); }

  function renderTiles(){
    el('tDept').textContent = DEPARTMENTS.length;
    el('tRole').textContent = ROLES.length;
    var need = DEPARTMENTS.filter(function(d){ return isActive(d) && (!d.role_ids || !d.role_ids.length); }).length;
    if (need){ el('tNeed').textContent=need; el('tNeedWrap').hidden=false; } else { el('tNeedWrap').hidden=true; }
  }

  function renderDepts(){
    var grid = el('deptList');
    var cards = DEPARTMENTS.map(function(d){
      var rids=d.role_ids||[]; var needs = isActive(d) && !rids.length;
      var chips = rids.length
        ? rids.slice(0,CHIP_LIMIT).map(function(id){return '<span class="chip">'+esc(roleLabel(id))+'</span>';}).join('')
            + (rids.length>CHIP_LIMIT ? '<span class="chip-more">+'+(rids.length-CHIP_LIMIT)+'</span>' : '')
        : '<span class="assign-prompt"><i class="fa fa-arrow-right"></i> Assign roles</span>';
      return '<div class="dcard '+(!isActive(d)?'inactive ':'')+(needs?'needs':'')+'" data-edit="'+esc(d.id)+'">'
        + '<div class="dcard-top"><div><div class="dcard-name">'+esc(d.name||'(unnamed)')+'</div>'
        + '<div class="dcard-meta"><i class="fa fa-users"></i> '+(d.staff_count||0)+' staff</div></div>'
        + '<span class="pill '+(isActive(d)?'Active':'Inactive')+'">'+(isActive(d)?'Active':'Inactive')+'</span></div>'
        + '<div class="dcard-roles">'+chips+'</div>'
        + '<div class="dcard-foot"><span class="dcard-count">'+rids.length+' role'+(rids.length===1?'':'s')+'</span>'
        + '<div class="dcard-acts"><button class="icobtn" data-edit="'+esc(d.id)+'" title="Edit"><i class="fa fa-pencil"></i></button>'
        + '<button class="icobtn del" data-del="'+esc(d.id)+'" title="Delete"><i class="fa fa-trash"></i></button></div></div>'
        + '</div>';
    }).join('');
    grid.innerHTML = cards + '<div class="dcard-add" id="addDeptTile"><i class="fa fa-plus-circle"></i> Add department</div>';
    grid.querySelectorAll('[data-edit]').forEach(function(n){ n.onclick=function(e){ e.stopPropagation(); openDept(n.getAttribute('data-edit')); }; });
    grid.querySelectorAll('[data-del]').forEach(function(n){ n.onclick=function(e){ e.stopPropagation(); delDept(n.getAttribute('data-del')); }; });
    el('addDeptTile').onclick=function(){ openDept(null); };
  }

  function renderRoles(){
    if(!ROLES.length){ el('roleList').innerHTML='<div class="orgx-empty">No roles yet.</div>'; return; }
    var html='';
    CATEGORIES.forEach(function(cat){
      var inCat=ROLES.filter(function(r){return (r.category||'')===cat;});
      if(!inCat.length) return;
      html+='<div class="rcat">'+esc(cat)+'</div>';
      inCat.forEach(function(r){
        var caps=Array.isArray(r.flags)?r.flags:Object.keys(r.flags||{}).filter(function(k){return (r.flags||{})[k];});
        html+='<div class="rrow" data-role="'+esc(r.id)+'"><div class="rrow-l"><span class="rrow-label">'+esc(r.label)+'</span>'
          +(r.is_system?'<span class="badge sys">system</span>':'<span class="badge cst">custom</span>')+'</div>'
          +'<span class="rrow-meta">'+esc(r.attendance_type||'standard')+(caps.length?(' · '+caps.length+' cap'+(caps.length>1?'s':'')):'')+'</span></div>';
      });
    });
    el('roleList').innerHTML=html;
    el('roleList').querySelectorAll('[data-role]').forEach(function(n){ n.onclick=function(){ openRole(n.getAttribute('data-role')); }; });
  }

  // ── Department modal ───────────────────────────────────────
  function deptRoleChecks(sel){
    sel=sel||[];
    if(!ROLES.length) return '<div class="orgx-empty" style="padding:12px;">Add staff roles first →</div>';
    return ROLES.map(function(r){ var on=sel.indexOf(r.id)!==-1;
      return '<label><input type="checkbox" value="'+esc(r.id)+'" '+(on?'checked':'')+'><span>'+esc(r.label)+'</span><span class="rc-cat">'+esc(r.category||'')+'</span></label>';
    }).join('');
  }
  function updateDeptRoleCount(){ el('deptRoleCount').textContent = el('deptRoleChecks').querySelectorAll('input:checked').length; }
  function openDept(id){
    var d=id?DEPARTMENTS.filter(function(x){return x.id===id;})[0]:null;
    el('deptModalTitle').textContent=d?'Edit Department':'Add Department';
    el('deptId').value=d?d.id:''; fillHeadSelect(); if(el('deptHeadSelect')) el('deptHeadSelect').value=d?(d.head_staff_id||''):'';
    el('deptName').value=d?(d.name||''):''; el('deptDesc').value=d?(d.description||''):'';
    el('deptStatus').value=d?(d.status||'Active'):'Active';
    el('deptRoleChecks').innerHTML=deptRoleChecks(d?(d.role_ids||[]):[]);
    updateDeptRoleCount();
    el('deptRoleChecks').querySelectorAll('input').forEach(function(i){ i.onchange=updateDeptRoleCount; });
    openModal('deptModal'); setTimeout(function(){ el('deptName').focus(); },50);
  }
  function saveDept(){
    var name=el('deptName').value.trim(); if(!name){ alert('Department name is required.'); return; }
    var roleIds=Array.prototype.map.call(el('deptRoleChecks').querySelectorAll('input:checked'),function(i){return i.value;});
    var b=el('saveDeptBtn'); b.disabled=true;
    post('/hr/save_department',{id:el('deptId').value,name:name,description:el('deptDesc').value.trim(),status:el('deptStatus').value,head_staff_id:(el('deptHeadSelect')?el('deptHeadSelect').value:''),role_ids:roleIds})
      .then(function(resp){ b.disabled=false; if(resp.status==='error'){alert(resp.message||'Save failed.');return;} closeModal('deptModal'); alertMsg('ok','Department saved.'); load(); })
      .catch(function(e){ b.disabled=false; alert(e.message||'Save failed.'); });
  }
  function delDept(id){
    var d=DEPARTMENTS.filter(function(x){return x.id===id;})[0];
    if(d&&d.staff_count>0){ alert('“'+d.name+'” has '+d.staff_count+' staff assigned. Reassign them before deleting.'); return; }
    if(!confirm('Delete this department?')) return;
    post('/hr/delete_department',{id:id}).then(function(resp){ if(resp.status==='error'){alert(resp.message||'Delete failed.');return;} alertMsg('ok','Department deleted.'); load(); }).catch(function(e){ alert(e.message||'Delete failed.'); });
  }

  // ── Role modal ─────────────────────────────────────────────
  function roleFlagChecks(flags){
    flags=flags||[]; var on=Array.isArray(flags)?flags:Object.keys(flags).filter(function(k){return flags[k];});
    return CAP_FLAGS.map(function(f){ return '<label><input type="checkbox" value="'+f.key+'" '+(on.indexOf(f.key)!==-1?'checked':'')+'><span>'+esc(f.label)+'</span></label>'; }).join('');
  }
  function openRole(id){
    var r=id?ROLE_BY_ID[id]:null, sys=r&&r.is_system;
    el('roleModalTitle').textContent=r?'Edit Staff Role':'Add Staff Role';
    el('roleSysNote').hidden=!sys;
    el('roleId').value=r?r.id:''; el('roleId').disabled=!!r;
    el('roleLabel').value=r?r.label:''; el('roleLabel').disabled=!!sys;
    el('roleCategory').value=r?(r.category||'Teaching'):'Teaching'; el('roleCategory').disabled=!!sys;
    el('roleAttendance').value=r?(r.attendance_type||'standard'):'standard'; el('roleAttendance').disabled=!!sys;
    el('roleFlags').innerHTML=roleFlagChecks(r?r.flags:[]);
    el('deleteRoleBtn').hidden=!(r&&!sys); el('deleteRoleBtn').setAttribute('data-role',r?r.id:'');
    openModal('roleModal'); if(!r) setTimeout(function(){ el('roleId').focus(); },50);
  }
  function saveRole(){
    var roleId=el('roleId').value.trim().toUpperCase(), label=el('roleLabel').value.trim();
    if(!roleId||!label){ alert('Role ID and label are required.'); return; }
    if(!/^ROLE_[A-Z0-9_]+$/.test(roleId)){ alert('Role ID must be ROLE_ followed by UPPERCASE letters/digits/underscores.'); return; }
    var flags=Array.prototype.map.call(el('roleFlags').querySelectorAll('input:checked'),function(i){return i.value;});
    var b=el('saveRoleBtn'); b.disabled=true;
    post('/org/save_role',{role_id:roleId,label:label,category:el('roleCategory').value,attendance_type:el('roleAttendance').value,flags:flags})
      .then(function(resp){ b.disabled=false; if(resp.status==='error'){alert(resp.message||'Save failed.');return;} closeModal('roleModal'); alertMsg('ok','Role saved.'); load(); })
      .catch(function(e){ b.disabled=false; alert(e.message||'Save failed.'); });
  }
  function delRole(){
    var id=el('deleteRoleBtn').getAttribute('data-role'); if(!id) return;
    if(!confirm('Delete this custom role? It will be removed from every department too.')) return;
    post('/org/delete_role',{role_id:id}).then(function(resp){ if(resp.status==='error'){alert(resp.message||'Delete failed.');return;} closeModal('roleModal'); alertMsg('ok','Role deleted.'); load(); }).catch(function(e){ alert(e.message||'Delete failed.'); });
  }

  // ── Suggest mapping (client heuristic; user reviews & saves) ──
  function suggestMapping(){
    if(!DEPARTMENTS.length||!ROLES.length){ alert('Add some departments and roles first.'); return; }
    function deptType(n){ n=(n||'').toLowerCase();
      if(/academ|teach|science|math|english|humanit|commerce|studies|primary|second|junior|senior|subject/.test(n)) return 'Teaching';
      if(/financ|account|admin|office|reception|front|clerk/.test(n)) return 'Administrative';
      if(/librar|lab|exam|hostel/.test(n)) return 'Non-Teaching';
      if(/support|transport|operation|maintenance|security|facilit|house|estate/.test(n)) return 'Support';
      return ''; }
    var applied=0;
    DEPARTMENTS.forEach(function(d){ var t=deptType(d.name); if(!t) return;
      var match=ROLES.filter(function(r){return (r.category||'')===t;}).map(function(r){return r.id;});
      var merged=(d.role_ids||[]).slice(); match.forEach(function(id){ if(merged.indexOf(id)===-1) merged.push(id); });
      if(merged.length!==(d.role_ids||[]).length){ d.role_ids=merged; applied++; }
    });
    if(!applied){ alertMsg('ok','Nothing new to suggest — departments already cover their role categories, or names are too custom to guess. Open a department to assign roles manually.'); renderAll(); return; }
    renderAll();
    if(confirm('Suggested roles for '+applied+' department(s), merged with existing. Save all now?')) saveAllSuggested();
  }
  function saveAllSuggested(){
    var q=DEPARTMENTS.slice();
    (function next(i){ if(i>=q.length){ alertMsg('ok','Suggested mapping saved.'); load(); return; }
      var d=q[i]; post('/hr/save_department',{id:d.id,name:d.name,description:d.description||'',status:d.status||'Active',head_staff_id:d.head_staff_id||'',role_ids:d.role_ids||[]})
        .then(function(){next(i+1);}).catch(function(){next(i+1);}); })(0);
  }

  // ── Modal + guide ──────────────────────────────────────────
  function openModal(id){ el(id).classList.add('open'); el(id).setAttribute('aria-hidden','false'); }
  function closeModal(id){ el(id).classList.remove('open'); el(id).setAttribute('aria-hidden','true'); }

  // One-click create the common departments (skips any that already exist).
  function seedDefaults(){
    var have={}; DEPARTMENTS.forEach(function(d){ have[(d.name||'').toLowerCase()]=true; });
    var add=SEED_DEPTS.filter(function(n){ return !have[n.toLowerCase()]; });
    if(!add.length){ alertMsg('ok','All common departments already exist.'); return; }
    if(!confirm('Create '+add.length+' department(s): '+add.join(', ')+'?')) return;
    var b=el('seedDeptBtn'); if(b) b.disabled=true;
    (function next(i){
      if(i>=add.length){ if(b) b.disabled=false; alertMsg('ok','Added '+add.length+' department(s).'); load(); return; }
      post('/hr/save_department',{id:'',name:add[i],description:'',status:'Active',head_staff_id:'',role_ids:[]}).then(function(){ next(i+1); }).catch(function(){ next(i+1); });
    })(0);
  }

  el('addDeptBtn').onclick=function(){ openDept(null); };
  el('addRoleBtn').onclick=function(){ openRole(null); };
  el('saveDeptBtn').onclick=saveDept; el('saveRoleBtn').onclick=saveRole; el('deleteRoleBtn').onclick=delRole;
  el('suggestBtn').onclick=suggestMapping;
  if(el('seedDeptBtn')) el('seedDeptBtn').onclick=seedDefaults;
  document.querySelectorAll('[data-close-dept]').forEach(function(n){ n.onclick=function(){closeModal('deptModal');}; });
  document.querySelectorAll('[data-close-role]').forEach(function(n){ n.onclick=function(){closeModal('roleModal');}; });
  [el('deptModal'),el('roleModal')].forEach(function(m){ m.onclick=function(e){ if(e.target===m) closeModal(m.id); }; });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape'){ closeModal('deptModal'); closeModal('roleModal'); } });

  try { if(localStorage.getItem('orgGuideHidden')==='1') el('orgGuide').style.display='none'; } catch(_){}
  el('orgGuideX').onclick=function(){ el('orgGuide').style.display='none'; try{localStorage.setItem('orgGuideHidden','1');}catch(_){} };

  load();
  loadStaff();
})();
</script>
