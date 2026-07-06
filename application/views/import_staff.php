<?php
/**
 * Bulk Staff Import — upload step (staff/master_staff).
 * Redesigned: guided 3-step flow, drag-&-drop dropzone, scannable guidance.
 * Posts to staff/preview_import (field: excelFile). Theme-aware (light/night).
 */
$flashMsg = $this->session->flashdata('import_result');
$isError  = $flashMsg && (stripos($flashMsg, 'fail') !== false || stripos($flashMsg, 'error') !== false
         || stripos($flashMsg, 'not ') !== false || stripos($flashMsg, 'unsupported') !== false);
?>
<div class="content-wrapper">
<div class="imx">

  <?php if ($flashMsg): ?>
  <div class="imx-flash <?= $isError ? 'err' : 'ok' ?>">
    <i class="fa <?= $isError ? 'fa-exclamation-circle' : 'fa-check-circle' ?>"></i>
    <span><?= htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8') ?></span>
  </div>
  <?php endif; ?>

  <!-- Hero -->
  <div class="imx-hero">
    <div class="imx-hero-icon"><i class="fa fa-users"></i></div>
    <div>
      <h1 class="imx-title">Bulk Staff Import</h1>
      <p class="imx-sub">Add many staff at once from a spreadsheet — three quick steps, and nothing is saved until you preview it.</p>
    </div>
  </div>

  <!-- Stepper -->
  <ol class="imx-steps" aria-label="Import steps">
    <li class="done">
      <span class="imx-step-n"><i class="fa fa-download"></i></span>
      <span class="imx-step-t">Get the template</span>
      <span class="imx-step-d">Start from our sheet</span>
    </li>
    <li class="active">
      <span class="imx-step-n">2</span>
      <span class="imx-step-t">Upload your file</span>
      <span class="imx-step-d">.xlsx or .csv</span>
    </li>
    <li>
      <span class="imx-step-n">3</span>
      <span class="imx-step-t">Map &amp; preview</span>
      <span class="imx-step-d">Confirm, then import</span>
    </li>
  </ol>

  <div class="imx-grid">
    <!-- Upload -->
    <form action="<?= base_url('staff/preview_import') ?>" method="post" enctype="multipart/form-data" class="imx-card imx-upload" id="importForm">
      <input type="hidden" name="<?= $this->security->get_csrf_token_name() ?>" value="<?= $this->security->get_csrf_hash() ?>">

      <label class="imx-drop" id="imDrop" for="excelFile" tabindex="0">
        <input type="file" name="excelFile" id="excelFile" accept=".xlsx,.csv" hidden required>
        <div class="imx-drop-empty" id="imPrompt">
          <div class="imx-drop-ic"><i class="fa fa-cloud-upload"></i></div>
          <div class="imx-drop-main">Drag &amp; drop your file here</div>
          <div class="imx-drop-or">or <span class="imx-link">browse to choose</span></div>
          <div class="imx-drop-hint">Excel (.xlsx) or CSV · up to 5&nbsp;MB</div>
        </div>
        <div class="imx-drop-picked" id="imPicked" hidden>
          <div class="imx-file-ic"><i class="fa fa-file-excel-o"></i></div>
          <div class="imx-file-meta">
            <div class="imx-file-name" id="imFileName">file.xlsx</div>
            <div class="imx-file-size" id="imFileSize">0 KB</div>
          </div>
          <button type="button" class="imx-file-x" id="imRemove" title="Remove"><i class="fa fa-times"></i></button>
        </div>
      </label>
      <div class="imx-err" id="imError" hidden></div>

      <div class="imx-actions">
        <button type="submit" class="imx-btn imx-btn-primary" id="importBtn" disabled>
          <i class="fa fa-arrow-right"></i> Upload &amp; Map Columns
        </button>
        <a href="<?= base_url('staff/all_staff') ?>" class="imx-btn imx-btn-ghost">Cancel</a>
      </div>
    </form>

    <!-- Sidebar: template + guidance -->
    <aside class="imx-side">
      <div class="imx-card imx-tpl">
        <div class="imx-tpl-ic"><i class="fa fa-file-excel-o"></i></div>
        <div class="imx-tpl-body">
          <div class="imx-tpl-title">Start with our template</div>
          <p class="imx-tpl-text">Pre-formatted columns with smart dropdowns — pick a <b>Department</b> and only its <b>Roles</b> appear.</p>
          <a href="<?= base_url('staff/download_staff_template') ?>" class="imx-btn imx-btn-tpl">
            <i class="fa fa-download"></i> Download template (.xlsx)
          </a>
        </div>
      </div>

      <div class="imx-card imx-tips">
        <div class="imx-tips-h">Good to know</div>
        <ul class="imx-tiplist">
          <li><i class="fa fa-asterisk"></i><span>Only <b>Name</b> &amp; <b>Phone</b> are required — everything else is optional.</span></li>
          <li><i class="fa fa-random"></i><span><b>Any column layout works</b> — you'll map columns after upload (we pre-guess them).</span></li>
          <li><i class="fa fa-shield"></i><span><b>Safe to re-run</b> — existing staff (matched by phone) are skipped, never duplicated.</span></li>
          <li><i class="fa fa-eye"></i><span>You'll <b>preview &amp; fix</b> every row before anything is saved.</span></li>
        </ul>
        <details class="imx-more">
          <summary>See all the details</summary>
          <ul class="imx-detaillist">
            <li>A <b>present-but-malformed</b> value (bad email/phone/date) imports with an amber warning — only a missing Name or Phone blocks a row.</li>
            <li>We auto-clean formats: dates (<b>15-06-1985</b>, <b>1985-06-15</b>, <b>04/12/2019</b>), phone (drops +91/spaces), PAN/IFSC (uppercased).</li>
            <li><b>Role</b> comes from the Position/Role column. If unrecognized, the staff imports with no role (assign later) — never silently a Teacher.</li>
            <li>If a role doesn't fit the chosen <b>Department</b>, it still imports with a warning — tidy it in Departments&nbsp;&amp;&nbsp;Roles or Edit Staff.</li>
            <li>For a teacher with no Subjects column, the Department value is treated as the subject.</li>
            <li>Photo &amp; documents can be added later via Edit Staff.</li>
          </ul>
        </details>
      </div>
    </aside>
  </div>
</div>
</div>

<style>
  .imx {
    --bg:#f1f5f9; --surface:#fff; --surface-2:#f8fafc; --ink:#0f172a; --ink-2:#334155;
    --muted:#64748b; --faint:#94a3b8; --border:#e2e8f0; --border-2:#cbd5e1;
    --brand:#2563eb; --brand-d:#1d4ed8; --brand-soft:#eff6ff; --brand-bd:#bfdbfe;
    --ok:#16a34a; --ok-soft:#dcfce7; --warn:#d97706; --warn-soft:#fef3c7; --danger:#dc2626; --danger-soft:#fef2f2;
    --shadow:0 1px 2px rgba(16,24,40,.06),0 1px 3px rgba(16,24,40,.05);
    max-width:1080px; margin:0 auto; padding:22px 16px 60px; color:var(--ink);
    font-family:'Plus Jakarta Sans',system-ui,sans-serif;
  }
  html[data-theme="night"] .imx {
    --bg:#0b1220; --surface:#111a2e; --surface-2:#0f1728; --ink:#e5e9f0; --ink-2:#cbd5e1;
    --muted:#94a3b8; --faint:#64748b; --border:#23304a; --border-2:#33415c;
    --brand:#3b82f6; --brand-d:#2563eb; --brand-soft:#12233f; --brand-bd:#1e3a5f;
    --ok-soft:#0f2e22; --warn-soft:#3a2c0a; --danger-soft:#3a1414;
    --shadow:0 1px 2px rgba(0,0,0,.4);
  }

  .imx-flash { display:flex; align-items:center; gap:9px; padding:11px 14px; border-radius:10px; font-size:.9rem; margin-bottom:16px; }
  .imx-flash.ok  { background:var(--ok-soft); color:var(--ok); border:1px solid var(--ok); }
  .imx-flash.err { background:var(--danger-soft); color:var(--danger); border:1px solid var(--danger); }

  .imx-hero { display:flex; align-items:center; gap:14px; margin-bottom:18px; }
  .imx-hero-icon { width:52px; height:52px; border-radius:14px; background:linear-gradient(135deg,var(--brand),var(--brand-d)); color:#fff; display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0; box-shadow:var(--shadow); }
  .imx-title { font-size:1.55rem; font-weight:800; margin:0; letter-spacing:-.02em; }
  .imx-sub { color:var(--muted); font-size:.92rem; margin:2px 0 0; }

  .imx-steps { list-style:none; display:flex; gap:10px; padding:0; margin:0 0 20px; }
  .imx-steps li { flex:1; background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:12px 14px; display:flex; flex-direction:column; gap:1px; position:relative; box-shadow:var(--shadow); }
  .imx-steps li .imx-step-n { width:26px; height:26px; border-radius:50%; background:var(--surface-2); color:var(--muted); border:1px solid var(--border-2); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:.9rem; margin-bottom:6px; }
  .imx-steps li.done .imx-step-n { background:var(--ok-soft); color:var(--ok); border-color:var(--ok); }
  .imx-steps li.active { border-color:var(--brand); box-shadow:0 0 0 3px var(--brand-soft); }
  .imx-steps li.active .imx-step-n { background:var(--brand); color:#fff; border-color:var(--brand); }
  .imx-step-t { font-weight:700; font-size:.9rem; }
  .imx-step-d { color:var(--faint); font-size:.8rem; }
  @media (max-width:720px){ .imx-steps{ flex-direction:column; } }

  .imx-grid { display:grid; grid-template-columns:1.4fr 1fr; gap:18px; align-items:start; }
  @media (max-width:820px){ .imx-grid{ grid-template-columns:1fr; } }
  .imx-card { background:var(--surface); border:1px solid var(--border); border-radius:14px; box-shadow:var(--shadow); }
  .imx-upload { padding:18px; }

  .imx-drop { display:block; border:2px dashed var(--border-2); border-radius:14px; background:var(--surface-2); padding:34px 20px; text-align:center; cursor:pointer; transition:all .15s; outline:none; }
  .imx-drop:hover, .imx-drop:focus { border-color:var(--brand); background:var(--brand-soft); }
  .imx-drop.drag { border-color:var(--brand); background:var(--brand-soft); transform:scale(1.005); }
  .imx-drop-ic { font-size:40px; color:var(--brand); margin-bottom:8px; }
  .imx-drop-main { font-weight:700; font-size:1.02rem; }
  .imx-drop-or { color:var(--muted); font-size:.88rem; margin-top:2px; }
  .imx-link { color:var(--brand); font-weight:700; text-decoration:underline; }
  .imx-drop-hint { color:var(--faint); font-size:.82rem; margin-top:8px; }

  .imx-drop-picked { display:flex; align-items:center; gap:12px; text-align:left; }
  .imx-file-ic { width:44px; height:44px; border-radius:10px; background:var(--ok-soft); color:var(--ok); display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
  .imx-file-meta { flex:1; min-width:0; }
  .imx-file-name { font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .imx-file-size { color:var(--faint); font-size:.82rem; }
  .imx-file-x { border:none; background:var(--surface-2); color:var(--muted); width:30px; height:30px; border-radius:8px; cursor:pointer; }
  .imx-file-x:hover { background:var(--danger-soft); color:var(--danger); }

  .imx-err { color:var(--danger); font-size:.86rem; margin-top:10px; }
  .imx-actions { display:flex; gap:10px; margin-top:16px; }

  .imx-btn { display:inline-flex; align-items:center; gap:7px; border:1px solid transparent; border-radius:10px; padding:10px 16px; font-size:.9rem; font-weight:700; cursor:pointer; text-decoration:none; }
  .imx-btn-primary { background:var(--brand); color:#fff; }
  .imx-btn-primary:hover { background:var(--brand-d); }
  .imx-btn-primary:disabled { opacity:.5; cursor:not-allowed; }
  .imx-btn-ghost { background:var(--surface-2); color:var(--ink-2); border-color:var(--border); }
  .imx-btn-ghost:hover { background:var(--border); }
  .imx-btn-tpl { background:var(--brand-soft); color:var(--brand-d); border-color:var(--brand-bd); font-size:.9rem; padding:8px 14px; }
  .imx-btn-tpl:hover { background:var(--brand-bd); }

  .imx-side { display:flex; flex-direction:column; gap:14px; }
  .imx-tpl { display:flex; gap:12px; padding:16px; }
  .imx-tpl-ic { width:42px; height:42px; border-radius:10px; background:var(--ok-soft); color:var(--ok); display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
  .imx-tpl-title { font-weight:700; }
  .imx-tpl-text { color:var(--muted); font-size:.88rem; margin:3px 0 10px; line-height:1.45; }

  .imx-tips { padding:16px; }
  .imx-tips-h { font-weight:800; font-size:.82rem; letter-spacing:.5px; text-transform:uppercase; color:var(--faint); margin-bottom:10px; }
  .imx-tiplist { list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:10px; }
  .imx-tiplist li { display:flex; gap:10px; font-size:.9rem; color:var(--ink-2); line-height:1.4; }
  .imx-tiplist li i { color:var(--brand); margin-top:3px; width:15px; text-align:center; flex-shrink:0; }
  .imx-more { margin-top:12px; border-top:1px solid var(--border); padding-top:10px; }
  .imx-more summary { cursor:pointer; font-size:.88rem; font-weight:600; color:var(--brand); }
  .imx-detaillist { margin:10px 0 0; padding-left:18px; color:var(--muted); font-size:.84rem; line-height:1.55; }
  .imx-detaillist li { margin-bottom:6px; }

  /* Neutralize the panel's global `label {…!important}` (header-inline.css:473)
     so the drop-zone label renders as designed (it forces uppercase/teal/11px). */
  .imx label.imx-drop { text-transform:none !important; letter-spacing:normal !important; color:var(--ink) !important; font-size:1rem !important; font-weight:400 !important; }
</style>

<script>
(function(){
  var form   = document.getElementById('importForm');
  var input  = document.getElementById('excelFile');
  var drop   = document.getElementById('imDrop');
  var prompt = document.getElementById('imPrompt');
  var picked = document.getElementById('imPicked');
  var nameEl = document.getElementById('imFileName');
  var sizeEl = document.getElementById('imFileSize');
  var errEl  = document.getElementById('imError');
  var btn    = document.getElementById('importBtn');
  var removeBtn = document.getElementById('imRemove');
  var MAX = 5 * 1024 * 1024;

  function human(b){ return b < 1024 ? b+' B' : b < 1048576 ? (b/1024).toFixed(1)+' KB' : (b/1048576).toFixed(2)+' MB'; }
  function setErr(m){ if(m){ errEl.textContent = m; errEl.hidden = false; } else { errEl.hidden = true; } }

  function onFile(){
    var f = input.files && input.files[0];
    if (!f) { reset(); return; }
    var ok = /\.(xlsx|csv)$/i.test(f.name);
    if (!ok) { setErr('Please choose a .xlsx or .csv file.'); reset(true); return; }
    if (f.size > MAX) { setErr('File is larger than 5 MB. Please split it or use a smaller file.'); reset(true); return; }
    setErr(null);
    nameEl.textContent = f.name; sizeEl.textContent = human(f.size);
    prompt.hidden = true; picked.hidden = false; btn.disabled = false;
  }
  function reset(keepErr){
    input.value = ''; prompt.hidden = false; picked.hidden = true; btn.disabled = true;
    if (!keepErr) setErr(null);
  }

  input.addEventListener('change', onFile);
  removeBtn.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); reset(); });
  drop.addEventListener('keydown', function(e){ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); input.click(); } });
  ['dragenter','dragover'].forEach(function(ev){ drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.add('drag'); }); });
  ['dragleave','drop'].forEach(function(ev){ drop.addEventListener(ev, function(e){ e.preventDefault(); if(ev==='dragleave' && drop.contains(e.relatedTarget)) return; drop.classList.remove('drag'); }); });
  drop.addEventListener('drop', function(e){
    if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length){
      try { input.files = e.dataTransfer.files; } catch(_) {}
      onFile();
    }
  });

  form.addEventListener('submit', function(e){
    if (!input.files.length){ e.preventDefault(); setErr('Choose a file first.'); return; }
    btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Uploading…';
  });
})();
</script>
