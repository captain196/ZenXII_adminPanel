<?php
/**
 * Bulk Staff Import — single-page wizard (staff/master_staff).
 * One page, three steps: Get template → Upload → Map & preview. The upload posts
 * to staff/preview_import via AJAX (JSON back), then the mapping/preview/import
 * UI reveals in place and the stepper advances 2→3 — no page navigation.
 * A plain (no-JS) submit still works: preview_import renders import_staff_map.php.
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

  <!-- Stepper (advances 2→3 in place once the file is uploaded) -->
  <ol class="imx-steps" id="imxSteps" aria-label="Import steps" role="list">
    <li class="done" aria-label="Step 1: Get the template (done)">
      <span class="imx-step-n"><i class="fa fa-check"></i></span>
      <span class="imx-step-tx">
        <span class="imx-step-t">Get the template</span>
        <span class="imx-step-d">Start from our sheet</span>
      </span>
    </li>
    <li class="active" aria-current="step" aria-label="Step 2: Upload your file (current)">
      <span class="imx-step-n">2</span>
      <span class="imx-step-tx">
        <span class="imx-step-t">Upload your file</span>
        <span class="imx-step-d">.xlsx or .csv</span>
      </span>
    </li>
    <li aria-label="Step 3: Map & preview (upcoming)">
      <span class="imx-step-n">3</span>
      <span class="imx-step-tx">
        <span class="imx-step-t">Map &amp; preview</span>
        <span class="imx-step-d">Confirm, then import</span>
      </span>
    </li>
  </ol>

  <!-- ══ STAGE 1: UPLOAD ══ -->
  <div id="uploadStage">
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

  <!-- ══ STAGE 2: MAP & PREVIEW (revealed after upload) ══ -->
  <div id="mapStage" class="imx-hidden">
    <div class="imp-topbar">
      <div class="imp-fileline" id="fileLine"></div>
      <a href="<?= base_url('staff/master_staff') ?>" class="imp-btn ghost"><i class="fa fa-arrow-left"></i> Start over</a>
    </div>

    <div class="imp-cap imp-hidden" id="capNote"><i class="fa fa-info-circle"></i> <span id="capText"></span></div>

    <!-- Match columns -->
    <div class="imp-card" id="mapCard">
      <h2>Match your columns</h2>
      <p class="hint">We pre-filled the best guess for each column. Fix any that look wrong, or set them to “— Ignore —”. Required fields must be mapped.</p>
      <div class="req-chips" id="reqChips"></div>
      <div class="map-scroll">
        <table class="map-table">
          <thead><tr><th style="width:30%">Your column</th><th style="width:30%">Sample value</th><th style="width:40%">Maps to field</th></tr></thead>
          <tbody id="mapBody"></tbody>
        </table>
      </div>
      <div class="imp-actions">
        <button class="imp-btn primary" id="validateBtn"><i class="fa fa-eye"></i> Validate &amp; Preview</button>
      </div>
    </div>

    <!-- Preview -->
    <div class="imp-card imp-hidden" id="prevCard">
      <h2>Preview &amp; fix</h2>
      <p class="hint">Values below are cleaned/normalized. Red rows have blocking errors and won't import. You can edit any cell, then re-validate. Staff whose phone already exists are skipped automatically.</p>
      <div class="sum-chips" id="sumChips"></div>
      <div class="prev-wrap">
        <table class="prev-table">
          <thead><tr id="prevHead"></tr></thead>
          <tbody id="prevBody"></tbody>
        </table>
      </div>
      <div class="imp-note imp-hidden" id="editNote"><i class="fa fa-info-circle"></i> You edited some cells — click “Re-validate” to refresh statuses before importing.</div>

      <div class="imp-actions">
        <button class="imp-btn ghost" id="revalidateBtn"><i class="fa fa-refresh"></i> Re-validate</button>
        <button class="imp-btn green" id="commitBtn"><i class="fa fa-check"></i> Import valid rows</button>
        <button class="imp-btn ghost imp-hidden" id="errReportBtn"><i class="fa fa-download"></i> Download error rows</button>
        <span id="previewMeta" style="font-size:12px;color:var(--muted);"></span>
      </div>

      <div class="imp-hidden" id="progWrap" style="margin-top:14px;">
        <div style="height:10px;background:var(--surface-2);border-radius:6px;overflow:hidden;">
          <div id="progBar" style="height:100%;width:0;background:var(--ok);transition:width .2s;"></div>
        </div>
        <div id="progText" style="font-size:12px;color:var(--ink-2);margin-top:6px;"></div>
      </div>
    </div>

    <!-- Result -->
    <div class="imp-card imp-hidden" id="resultCard">
      <h2>Result</h2>
      <div id="resultMsg" style="font-size:13px;line-height:1.6;"></div>
      <div class="imp-actions">
        <a href="<?= base_url('staff/all_staff') ?>" class="imp-btn primary"><i class="fa fa-users"></i> Go to Staff List</a>
        <a href="<?= base_url('staff/master_staff') ?>" class="imp-btn ghost"><i class="fa fa-upload"></i> Import another file</a>
      </div>
    </div>
  </div>

</div>
</div>

<style>
  .imx {
    /* Panel "Clay" palette + panel fonts (Syne display / Plus Jakarta body) so
       the importer matches the rest of the admin, not a stray blue. */
    --font-b:'Plus Jakarta Sans',system-ui,sans-serif; --font-d:'Syne','Plus Jakarta Sans',sans-serif;
    --surface:#ffffff; --surface-2:#FAF5F0; --ink:#22160F; --ink-2:#6B5346;
    --muted:#9E8578; --faint:#C2A89A; --border:rgba(0,0,0,.08); --border-2:rgba(0,0,0,.15);
    --brand:#BC5A3C; --brand-d:#9E4830; --brand-soft:#F7ECE7; --brand-bd:#E7CFC5;
    --ok:#2FA875; --ok-soft:rgba(47,168,117,.13); --warn:#B5741F; --warn-soft:rgba(221,148,51,.15); --danger:#C0402E; --danger-soft:rgba(219,90,72,.12);
    --shadow:0 1px 3px rgba(60,30,15,.06),0 1px 2px rgba(60,30,15,.05);
    max-width:1080px; margin:0 auto; padding:22px 16px 60px; color:var(--ink);
    font-family:var(--font-b); font-size:14px;
  }
  html[data-theme="night"] .imx {
    --surface:#201711; --surface-2:#271A14; --ink:#F4E9E2; --ink-2:#D8C6BA;
    --muted:#A89A90; --faint:#7A6A5E; --border:rgba(255,255,255,.08); --border-2:rgba(255,255,255,.16);
    --brand:#D4725C; --brand-d:#BC5A3C; --brand-soft:rgba(212,114,92,.16); --brand-bd:rgba(212,114,92,.34);
    --ok:#3BBE86; --ok-soft:rgba(47,168,117,.18); --warn:#D79A4A; --warn-soft:rgba(221,148,51,.18); --danger:#DB6B58; --danger-soft:rgba(219,90,72,.20);
    --shadow:0 1px 3px rgba(0,0,0,.4);
  }

  .imx-hidden { display:none !important; }

  .imx-flash { display:flex; align-items:center; gap:9px; padding:11px 14px; border-radius:10px; font-size:13px; margin-bottom:16px; }
  .imx-flash.ok  { background:var(--ok-soft); color:var(--ok); border:1px solid var(--ok); }
  .imx-flash.err { background:var(--danger-soft); color:var(--danger); border:1px solid var(--danger); }

  .imx-hero { display:flex; align-items:center; gap:14px; margin-bottom:18px; }
  .imx-hero-icon { width:44px; height:44px; border-radius:10px; background:linear-gradient(135deg,var(--brand),var(--brand-d)); color:#fff; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; box-shadow:var(--shadow); }
  .imx-title { font-family:var(--font-b); font-size:18px; font-weight:700; margin:0; letter-spacing:-.02em; }
  .imx-sub { color:var(--muted); font-size:13px; margin:3px 0 0; }

  /* Connected progress stepper — joined circles read as sequential steps, not tabs. */
  .imx-steps { list-style:none; display:flex; padding:0; margin:0 0 24px; }
  .imx-steps li { flex:1; position:relative; display:flex; flex-direction:column; align-items:center; text-align:center; padding:0 6px; }
  .imx-steps li:not(:last-child)::after {
    content:""; position:absolute; top:17px; height:2px; border-radius:2px;
    left:calc(50% + 22px); right:calc(-50% + 22px);
    background:var(--border-2); z-index:0;
  }
  .imx-steps li.done::after { background:var(--ok); }
  .imx-step-n {
    position:relative; z-index:1; box-sizing:border-box;
    width:34px; height:34px; border-radius:50%; margin-bottom:9px;
    background:var(--surface); color:var(--muted); border:2px solid var(--border-2);
    display:flex; align-items:center; justify-content:center; font-weight:800; font-size:13px;
    transition:background .2s, border-color .2s, box-shadow .2s;
  }
  .imx-steps li.done .imx-step-n { background:var(--ok); color:#fff; border-color:var(--ok); }
  .imx-steps li.active .imx-step-n { background:var(--brand); color:#fff; border-color:var(--brand); box-shadow:0 0 0 4px var(--brand-soft); }
  .imx-step-tx { display:flex; flex-direction:column; gap:1px; }
  .imx-step-t { font-weight:700; font-size:13px; color:var(--muted); }
  .imx-step-d { color:var(--faint); font-size:12px; }
  .imx-steps li.done .imx-step-t, .imx-steps li.active .imx-step-t { color:var(--ink); }
  @media (max-width:720px){
    .imx-steps { flex-direction:column; align-items:stretch; }
    .imx-steps li { flex-direction:row; align-items:flex-start; text-align:left; gap:12px; padding:0 0 20px; }
    .imx-steps li:last-child { padding-bottom:0; }
    .imx-steps li:not(:last-child)::after { top:34px; bottom:6px; left:16px; right:auto; width:2px; height:auto; }
    .imx-step-n { margin-bottom:0; }
  }

  .imx-grid { display:grid; grid-template-columns:1.4fr 1fr; gap:18px; align-items:start; }
  @media (max-width:820px){ .imx-grid{ grid-template-columns:1fr; } }
  .imx-card { background:var(--surface); border:1px solid var(--border); border-radius:14px; box-shadow:var(--shadow); }
  .imx-upload { padding:18px; }

  .imx-drop { display:block; border:2px dashed var(--border-2); border-radius:14px; background:var(--surface-2); padding:34px 20px; text-align:center; cursor:pointer; transition:all .15s; outline:none; }
  .imx-drop:hover, .imx-drop:focus { border-color:var(--brand); background:var(--brand-soft); }
  .imx-drop.drag { border-color:var(--brand); background:var(--brand-soft); transform:scale(1.005); }
  .imx-drop-ic { font-size:40px; color:var(--brand); margin-bottom:8px; }
  .imx-drop-main { font-weight:700; font-size:14px; }
  .imx-drop-or { color:var(--muted); font-size:13px; margin-top:2px; }
  .imx-link { color:var(--brand); font-weight:700; text-decoration:underline; }
  .imx-drop-hint { color:var(--faint); font-size:12px; margin-top:8px; }

  .imx-drop-picked { display:flex; align-items:center; gap:12px; text-align:left; }
  .imx-file-ic { width:44px; height:44px; border-radius:10px; background:var(--brand-soft); color:var(--brand); display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
  .imx-file-meta { flex:1; min-width:0; }
  .imx-file-name { font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .imx-file-size { color:var(--faint); font-size:12px; }
  .imx-file-x { border:none; background:var(--surface-2); color:var(--muted); width:30px; height:30px; border-radius:8px; cursor:pointer; }
  .imx-file-x:hover { background:var(--danger-soft); color:var(--danger); }

  .imx-err { color:var(--danger); font-size:13px; margin-top:10px; }
  .imx-actions { display:flex; gap:10px; margin-top:16px; }

  .imx-btn { display:inline-flex; align-items:center; gap:7px; border:1px solid transparent; border-radius:10px; padding:10px 16px; font-size:13px; font-weight:600; cursor:pointer; text-decoration:none; }
  .imx-btn-primary { background:var(--brand); color:#fff; }
  .imx-btn-primary:hover { background:var(--brand-d); }
  .imx-btn-primary:disabled { opacity:.5; cursor:not-allowed; }
  .imx-btn-ghost { background:var(--surface-2); color:var(--ink-2); border-color:var(--border); }
  .imx-btn-ghost:hover { background:var(--border); }
  .imx-btn-tpl { background:var(--brand-soft); color:var(--brand-d); border-color:var(--brand-bd); font-size:13px; padding:8px 14px; }
  .imx-btn-tpl:hover { background:var(--brand-bd); }

  .imx-side { display:flex; flex-direction:column; gap:14px; }
  .imx-tpl { display:flex; gap:12px; padding:16px; }
  .imx-tpl-ic { width:42px; height:42px; border-radius:10px; background:var(--brand-soft); color:var(--brand); display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
  .imx-tpl-title { font-weight:700; }
  .imx-tpl-text { color:var(--muted); font-size:13px; margin:3px 0 10px; line-height:1.45; }

  .imx-tips { padding:16px; }
  .imx-tips-h { font-weight:800; font-size:12px; letter-spacing:.5px; text-transform:uppercase; color:var(--faint); margin-bottom:10px; }
  .imx-tiplist { list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:10px; }
  .imx-tiplist li { display:flex; gap:10px; font-size:13px; color:var(--ink-2); line-height:1.4; }
  .imx-tiplist li i { color:var(--brand); margin-top:3px; width:15px; text-align:center; flex-shrink:0; }
  .imx-more { margin-top:12px; border-top:1px solid var(--border); padding-top:10px; }
  .imx-more summary { cursor:pointer; font-size:13px; font-weight:600; color:var(--brand); }
  .imx-detaillist { margin:10px 0 0; padding-left:18px; color:var(--muted); font-size:12px; line-height:1.55; }
  .imx-detaillist li { margin-bottom:6px; }

  /* ── Map & Preview stage (revealed after upload) — same blue system ── */
  .imp-topbar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:14px; }
  .imp-fileline { font-size:13px; color:var(--muted); }
  .imp-fileline b { color:var(--ink); }
  .imp-cap { display:flex; align-items:center; gap:8px; font-size:12px; padding:9px 13px; border-radius:10px; margin-bottom:14px; background:var(--warn-soft); color:var(--warn); }
  .imp-card { background:var(--surface); border:1px solid var(--border); border-radius:14px; box-shadow:var(--shadow); padding:18px 20px; margin-bottom:18px; }
  .imp-card h2 { font-family:var(--font-b); font-size:14px; margin:0 0 4px; color:var(--ink); font-weight:700; letter-spacing:-.01em; }
  .imp-card .hint { font-size:13px; color:var(--muted); margin:0 0 14px; line-height:1.5; }
  .req-chips { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
  .req-chip { font-size:12px; padding:4px 11px; border-radius:20px; font-weight:600; display:inline-flex; align-items:center; gap:6px; }
  .req-chip.ok  { background:var(--ok-soft); color:var(--ok); }
  .req-chip.bad { background:var(--danger-soft); color:var(--danger); }
  .map-scroll { overflow-x:auto; border:1px solid var(--border); border-radius:8px; }
  .map-table, .prev-table { width:100%; border-collapse:collapse; font-size:13px; }
  .map-table th, .prev-table th { background:var(--surface-2); color:var(--ink-2); text-align:left; padding:9px 12px; border-bottom:1px solid var(--border); font-size:11px; text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; position:sticky; top:0; z-index:1; }
  .map-table td { padding:8px 12px; border-bottom:1px solid var(--border); vertical-align:middle; color:var(--ink); }
  .map-table tr:last-child td { border-bottom:none; }
  .map-table .src { font-weight:600; color:var(--ink); }
  .map-table .sample { color:var(--faint); font-size:12px; max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  /* Professional select — flat, custom chevron, brand focus ring (no native OS look). */
  .map-table select, .imp-sel {
    -webkit-appearance:none; -moz-appearance:none; appearance:none;
    width:100%; padding:8px 32px 8px 11px; border:1px solid var(--border-2); border-radius:8px;
    background-color:var(--surface); color:var(--ink); font-family:inherit; font-size:13px; font-weight:500; line-height:1.35;
    cursor:pointer; transition:border-color .14s, box-shadow .14s, background-color .14s;
    background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239E8578' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'><path d='M6 9l6 6 6-6'/></svg>");
    background-repeat:no-repeat; background-position:right 11px center; background-size:11px;
  }
  .map-table select:hover, .imp-sel:hover { border-color:var(--brand); background-color:var(--surface-2); }
  .map-table select:focus, .imp-sel:focus { outline:none; border-color:var(--brand); box-shadow:0 0 0 3px var(--brand-soft); }
  .map-table select option, .imp-sel option { color:var(--ink); background:var(--surface); }
  .map-table select.unmapped { color:var(--faint); border-style:dashed; }
  .imp-sel { min-width:160px; }
  html[data-theme="night"] .map-table select, html[data-theme="night"] .imp-sel {
    background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23A89A90' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'><path d='M6 9l6 6 6-6'/></svg>");
  }
  .prev-wrap { overflow-x:auto; border:1px solid var(--border); border-radius:8px; max-height:62vh; }
  .prev-table td { padding:6px 10px; border-bottom:1px solid var(--border); white-space:nowrap; color:var(--ink); }
  .prev-table td[contenteditable] { outline:none; min-width:70px; }
  .prev-table td[contenteditable]:focus { background:var(--warn-soft); box-shadow:inset 0 0 0 2px var(--warn); }
  .prev-table tr.row-error  td.statuscell { color:var(--danger); }
  .prev-table tr.row-warn   td.statuscell { color:var(--warn); }
  .prev-table tr.row-ok     td.statuscell { color:var(--ok); }
  .prev-table tr.row-error { background:var(--danger-soft); }
  .prev-table tr.row-warn  { background:var(--warn-soft); }
  .prev-table .issues { color:var(--warn); font-size:12px; white-space:normal; min-width:220px; }
  .prev-table tr.row-error .issues { color:var(--danger); }
  .dot { display:inline-block; width:9px; height:9px; border-radius:50%; margin-right:6px; }
  .dot.ok{background:var(--ok);} .dot.warn{background:var(--warn);} .dot.err{background:var(--danger);}
  .sum-chips { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px; }
  .sum-chip { font-size:13px; padding:6px 14px; border-radius:8px; font-weight:600; }
  .sum-chip.ok{background:var(--ok-soft);color:var(--ok);} .sum-chip.warn{background:var(--warn-soft);color:var(--warn);}
  .sum-chip.err{background:var(--danger-soft);color:var(--danger);} .sum-chip.tot{background:var(--surface-2);color:var(--ink-2);}
  .imp-btn { padding:9px 16px; border:1px solid transparent; border-radius:10px; cursor:pointer; font-size:13px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:7px; font-family:inherit; }
  .imp-btn.primary { background:var(--brand); color:#fff; }
  .imp-btn.primary:hover { background:var(--brand-d); }
  .imp-btn.primary:disabled { opacity:.5; cursor:not-allowed; }
  .imp-btn.ghost { background:var(--surface-2); color:var(--ink-2); border:1px solid var(--border); }
  .imp-btn.ghost:hover { background:var(--border); }
  .imp-btn.green { background:var(--ok); color:#fff; }
  .imp-btn.green:disabled { opacity:.5; cursor:not-allowed; }
  .imp-actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:14px; }
  .imp-note { font-size:12px; color:var(--warn); margin-top:8px; }
  .imp-hidden { display:none !important; }
  .spin { display:inline-block; width:14px; height:14px; border:2px solid rgba(255,255,255,.5); border-top-color:#fff; border-radius:50%; animation:impspin .7s linear infinite; vertical-align:-2px; }
  @keyframes impspin { to { transform:rotate(360deg); } }

  /* Neutralize the panel's global `label {…!important}` (header-inline.css:473)
     so the drop-zone label renders as designed (it forces uppercase/teal/11px). */
  .imx label.imx-drop { text-transform:none !important; letter-spacing:normal !important; color:var(--ink) !important; font-size:1rem !important; font-weight:400 !important; }
</style>

<script>
(function () {
  var SITE_URL = <?= json_encode(rtrim(base_url(), '/')) ?>;

  /* ── Elements ── */
  function el(id) { return document.getElementById(id); }
  var form   = el('importForm');
  var input  = el('excelFile');
  var drop   = el('imDrop');
  var prompt = el('imPrompt');
  var picked = el('imPicked');
  var nameEl = el('imFileName');
  var sizeEl = el('imFileSize');
  var errEl  = el('imError');
  var btn    = el('importBtn');
  var removeBtn = el('imRemove');
  var MAX = 5 * 1024 * 1024;

  /* CSRF sourced from the form's hidden token (regenerate is OFF, so it stays
     valid across the upload + every subsequent validate/commit call). */
  var csrfEl    = form.querySelector('input[type="hidden"][name]');
  var CSRF_NAME = csrfEl ? csrfEl.getAttribute('name') : 'csrf_token';
  var CSRF_HASH = csrfEl ? csrfEl.value : '';

  function human(b){ return b < 1024 ? b+' B' : b < 1048576 ? (b/1024).toFixed(1)+' KB' : (b/1048576).toFixed(2)+' MB'; }
  function setErr(m){ if(m){ errEl.textContent = m; errEl.hidden = false; } else { errEl.hidden = true; } }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c];
    });
  }

  /* ══════════ STAGE 1 — file selection ══════════ */
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

  /* Upload → parse on the server (AJAX) → reveal the mapping stage in place. */
  form.addEventListener('submit', function(e){
    e.preventDefault();
    if (!input.files.length){ setErr('Choose a file first.'); return; }
    setErr(null);
    btn.disabled = true; btn.innerHTML = '<span class="spin"></span> Uploading…';

    var fd = new FormData(form);
    fetch(form.getAttribute('action'), {
      method: 'POST', body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){ return r.text(); }).then(function(raw){
      var resp; try { resp = JSON.parse(raw); } catch(_) { throw new Error('Server returned an unexpected response.'); }
      if (!resp || resp.status !== 'success') { throw new Error((resp && resp.message) || 'Could not read the file.'); }
      initMapper(resp);
      advanceStepper();
      el('uploadStage').classList.add('imx-hidden');
      el('mapStage').classList.remove('imx-hidden');
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }).catch(function(err){
      setErr(err.message || 'Upload failed. Please try again.');
      btn.disabled = false; btn.innerHTML = '<i class="fa fa-arrow-right"></i> Upload &amp; Map Columns';
    });
  });

  function advanceStepper(){
    var lis = el('imxSteps').querySelectorAll(':scope > li');
    if (lis[1]) { lis[1].classList.remove('active'); lis[1].classList.add('done'); lis[1].removeAttribute('aria-current');
      var n = lis[1].querySelector('.imx-step-n'); if (n) n.innerHTML = '<i class="fa fa-check"></i>'; }
    if (lis[2]) { lis[2].classList.add('active'); lis[2].setAttribute('aria-current','step'); }
  }

  /* ══════════ STAGE 2 — map → preview → import (client-side wizard) ══════════ */
  var RAW_HEADERS = [], RAW_ROWS = [], AUTOMAP = {}, SCHEMA = [];
  var PREVIEW_LIMIT = 50;
  var VALIDATED = null, dirty = false;
  var schemaByKey = {}, requiredKeys = [];

  /* ── Department/Role catalogue → powers the preview's Department & Role dropdowns
        (cascade: pick a Department, the Role list narrows to that department's roles),
        mirroring the single-add form. Falls back to free-text cells if unavailable. ── */
  var CAT_READY = false, DEPT_CAT = [], ROLE_LIST = [], ROLE_BY_ID = {}, DEPT_ROLE_IDS = {};
  function dkeyJS(s){ return String(s == null ? '' : s).trim().toLowerCase(); }
  function loadCatalogue(){
    return fetch(SITE_URL + '/staff/import_catalogue', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || j.status !== 'success') return;
        DEPT_CAT  = Array.isArray(j.departments) ? j.departments : [];
        ROLE_LIST = Array.isArray(j.roles) ? j.roles : [];
        ROLE_BY_ID = {}; DEPT_ROLE_IDS = {};
        ROLE_LIST.forEach(function (r) { ROLE_BY_ID[String(r.id).toUpperCase()] = r.label; });
        DEPT_CAT.forEach(function (d) { DEPT_ROLE_IDS[dkeyJS(d.name)] = Array.isArray(d.role_ids) ? d.role_ids : []; });
        CAT_READY = ROLE_LIST.length > 0;
        if (VALIDATED) renderPreview();   // upgrade already-rendered free-text cells to dropdowns
      })
      .catch(function () { CAT_READY = false; });
  }
  // Resolve any raw role text (id OR label) to the catalogue's canonical LABEL; else raw.
  function roleLabelResolve(t){
    if (!t) return '';
    var up = String(t).toUpperCase();
    if (ROLE_BY_ID[up]) return ROLE_BY_ID[up];
    var low = String(t).toLowerCase();
    for (var i = 0; i < ROLE_LIST.length; i++) { if (String(ROLE_LIST[i].label).toLowerCase() === low) return ROLE_LIST[i].label; }
    return String(t);
  }
  function deptNameResolve(t){
    if (!t) return '';
    for (var i = 0; i < DEPT_CAT.length; i++) { if (dkeyJS(DEPT_CAT[i].name) === dkeyJS(t)) return DEPT_CAT[i].name; }
    return String(t);
  }
  // Roles available for a department (cascade). No dept → the full role list.
  function rolesForDept(dn){
    // A department's own roles when it HAS any; otherwise fall back to the full list
    // (an empty role_ids[] must never leave the Role dropdown blank).
    if (dn) { var ids = DEPT_ROLE_IDS[dkeyJS(dn)]; if (ids && ids.length) return ids.map(function (id) { return { id: id, label: ROLE_BY_ID[String(id).toUpperCase()] || id }; }); }
    return ROLE_LIST.slice();
  }
  function deptSelectHtml(cur){
    var html = '<option value="">— none —</option>', seen = false;
    DEPT_CAT.forEach(function (d) { var s = dkeyJS(d.name) === dkeyJS(cur); if (s) seen = true; html += '<option' + (s ? ' selected' : '') + '>' + esc(d.name) + '</option>'; });
    if (cur && !seen) html += '<option selected>' + esc(cur) + '</option>';   // preserve unknown value
    return html;
  }
  function roleSelectHtml(curRaw, dn){
    var curLabel = roleLabelResolve(curRaw), list = rolesForDept(dn), html = '<option value="">— none —</option>', seen = false;
    list.forEach(function (r) { var s = dkeyJS(r.label) === dkeyJS(curLabel); if (s) seen = true; html += '<option' + (s ? ' selected' : '') + '>' + esc(r.label) + '</option>'; });
    if (curLabel && !seen) html += '<option selected>' + esc(curLabel) + '</option>';   // preserve role not in this dept
    return html;
  }

  function initMapper(resp){
    RAW_HEADERS = resp.headers || [];
    RAW_ROWS    = resp.rows || [];
    AUTOMAP     = resp.autoMap || {};
    SCHEMA      = resp.schema || [];
    schemaByKey = {};
    SCHEMA.forEach(function (f) { schemaByKey[f.key] = f; });
    requiredKeys = SCHEMA.filter(function (f) { return f.required; }).map(function (f) { return f.key; });

    el('fileLine').innerHTML = 'File: <b>' + esc(resp.fileName || 'spreadsheet') + '</b> — '
      + RAW_ROWS.length + ' data row' + (RAW_ROWS.length === 1 ? '' : 's') + ' detected';
    if (resp.capped) {
      el('capText').textContent = 'Showing the first ' + resp.capLimit + ' rows — split larger files into batches.';
      el('capNote').classList.remove('imp-hidden');
    }
    renderMapping();
  }

  function fieldOptions(selectedKey) {
    var html = '<option value="">— Ignore —</option>';
    SCHEMA.forEach(function (f) {
      var sel = (f.key === selectedKey) ? ' selected' : '';
      html += '<option value="' + esc(f.key) + '"' + sel + '>' + esc(f.label) + (f.required ? ' *' : '') + '</option>';
    });
    return html;
  }
  function renderMapping() {
    var body = el('mapBody');
    body.innerHTML = RAW_HEADERS.map(function (h, i) {
      var sample = '';
      for (var r = 0; r < RAW_ROWS.length && r < 5; r++) {
        if (RAW_ROWS[r][i] != null && String(RAW_ROWS[r][i]).trim() !== '') { sample = RAW_ROWS[r][i]; break; }
      }
      var guess = AUTOMAP[i] || '';
      return '<tr>'
        + '<td class="src">' + esc(h || ('Column ' + (i + 1))) + '</td>'
        + '<td class="sample">' + esc(sample) + '</td>'
        + '<td><select data-col="' + i + '" class="' + (guess ? '' : 'unmapped') + '">' + fieldOptions(guess) + '</select></td>'
        + '</tr>';
    }).join('');
    body.querySelectorAll('select').forEach(function (s) {
      s.addEventListener('change', function () { s.classList.toggle('unmapped', !s.value); refreshReqChips(); });
    });
    refreshReqChips();
  }
  function currentMapping() {
    var map = {};
    el('mapBody').querySelectorAll('select').forEach(function (s) { map[parseInt(s.getAttribute('data-col'), 10)] = s.value || null; });
    return map;
  }
  function mappedKeys() {
    var map = currentMapping(), set = {};
    Object.keys(map).forEach(function (k) { if (map[k]) set[map[k]] = true; });
    return set;
  }
  function refreshReqChips() {
    var set = mappedKeys();
    el('reqChips').innerHTML = requiredKeys.map(function (k) {
      var ok = !!set[k];
      return '<span class="req-chip ' + (ok ? 'ok' : 'bad') + '"><i class="fa fa-' + (ok ? 'check' : 'exclamation') + '"></i>'
        + esc(schemaByKey[k].label) + (ok ? ' mapped' : ' — not mapped') + '</span>';
    }).join('');
    var allReq = requiredKeys.every(function (k) { return set[k]; });
    el('validateBtn').disabled = !allReq;
    return allReq;
  }
  function buildCanonRows() {
    var map = currentMapping();
    return RAW_ROWS.map(function (row) {
      var o = {};
      for (var i = 0; i < row.length; i++) { var fk = map[i]; if (fk) o[fk] = row[i]; }
      return o;
    });
  }
  function post(action, payloadObj) {
    var fd = new FormData();
    fd.append(CSRF_NAME, CSRF_HASH);
    fd.append('payload', JSON.stringify(payloadObj));
    return fetch(SITE_URL + '/staff/' + action, {
      method: 'POST', body: fd,
      headers: { 'X-CSRF-Token': CSRF_HASH, 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.text(); }).then(function (raw) {
      try { return JSON.parse(raw); }
      catch (e) { console.error(action + ' raw:', raw.substring(0, 600)); throw new Error('Server returned an unexpected response.'); }
    });
  }
  function displayedFields() {
    var set = mappedKeys();
    return SCHEMA.filter(function (f) { return f.required || set[f.key]; });
  }
  function renderSummary(sum) {
    el('sumChips').innerHTML =
        '<span class="sum-chip tot">Total: ' + sum.total + '</span>'
      + '<span class="sum-chip ok">Ready: ' + sum.ok + '</span>'
      + '<span class="sum-chip warn">Warnings: ' + sum.warning + '</span>'
      + '<span class="sum-chip err">Errors: ' + sum.error + '</span>';
    el('errReportBtn').classList.toggle('imp-hidden', sum.error === 0);
    var importable = sum.ok + sum.warning;
    el('commitBtn').disabled = importable === 0;
    el('commitBtn').innerHTML = '<i class="fa fa-check"></i> Import ' + importable + ' valid row' + (importable === 1 ? '' : 's');
  }
  function renderPreview() {
    var fields = displayedFields();
    el('prevHead').innerHTML = '<th>#</th><th class="statuscell">Status</th>'
      + fields.map(function (f) { return '<th>' + esc(f.label) + (f.required ? ' *' : '') + '</th>'; }).join('')
      + '<th>Issues</th>';

    // When the catalogue is loaded, normalise Department/Role to canonical values so
    // the dropdowns are WYSIWYG and commit stores clean names (not raw sheet casing).
    if (CAT_READY) {
      VALIDATED.forEach(function (v) {
        if (!v || !v.data) return;
        var l = roleLabelResolve(v.data.role || '');       if (l !== (v.data.role || '')) v.data.role = l;
        var d = deptNameResolve(v.data.department || '');   if (d !== (v.data.department || '')) v.data.department = d;
      });
    }

    var n = Math.min(VALIDATED.length, PREVIEW_LIMIT);
    var rowsHtml = '';
    for (var i = 0; i < n; i++) {
      var v = VALIDATED[i];
      var cls = v.status === 'error' ? 'row-error' : (v.status === 'warning' ? 'row-warn' : 'row-ok');
      var dotc = v.status === 'error' ? 'err' : (v.status === 'warning' ? 'warn' : 'ok');
      var issues = (v.errors || []).concat(v.warnings || []);
      rowsHtml += '<tr class="' + cls + '" data-row="' + i + '">'
        + '<td>' + (i + 1) + '</td>'
        + '<td class="statuscell"><span class="dot ' + dotc + '"></span>' + v.status + '</td>'
        + fields.map(function (f) {
            if (CAT_READY && f.key === 'department')
              return '<td><select class="imp-sel" data-key="department">' + deptSelectHtml(v.data.department || '') + '</select></td>';
            if (CAT_READY && f.key === 'role')
              return '<td><select class="imp-sel" data-key="role">' + roleSelectHtml(v.data.role || '', v.data.department || '') + '</select></td>';
            return '<td contenteditable data-key="' + esc(f.key) + '">' + esc(v.data[f.key] || '') + '</td>';
          }).join('')
        + '<td class="issues">' + esc(issues.join('; ')) + '</td>'
        + '</tr>';
    }
    el('prevBody').innerHTML = rowsHtml;
    el('previewMeta').textContent = VALIDATED.length > PREVIEW_LIMIT
      ? ('Showing first ' + PREVIEW_LIMIT + ' of ' + VALIDATED.length + ' rows (all are validated & imported).') : '';

    el('prevBody').querySelectorAll('td[contenteditable]').forEach(function (td) {
      td.addEventListener('input', function () {
        var tr = td.closest('tr'), rowIdx = parseInt(tr.getAttribute('data-row'), 10);
        VALIDATED[rowIdx].data[td.getAttribute('data-key')] = td.textContent;
        scheduleRowRevalidate(tr, rowIdx);   // live status/issues after typing settles
      });
    });

    // Department/Role dropdowns — editing a role, and cascade when department changes.
    el('prevBody').querySelectorAll('select.imp-sel').forEach(function (sel) {
      sel.addEventListener('change', function () {
        var tr = sel.closest('tr'), rowIdx = parseInt(tr.getAttribute('data-row'), 10), key = sel.getAttribute('data-key');
        VALIDATED[rowIdx].data[key] = sel.value;
        if (key === 'department') {
          var roleSel = tr.querySelector('select[data-key="role"]');
          if (roleSel) {
            // Strict cascade: drop a role that isn't in the newly-chosen department.
            var allowed = rolesForDept(sel.value).map(function (r) { return dkeyJS(r.label); });
            if (VALIDATED[rowIdx].data.role && allowed.indexOf(dkeyJS(VALIDATED[rowIdx].data.role)) === -1) VALIDATED[rowIdx].data.role = '';
            roleSel.innerHTML = roleSelectHtml(VALIDATED[rowIdx].data.role || '', sel.value);
            VALIDATED[rowIdx].data.role = roleSel.value;
          }
        }
        revalidateRowLive(tr, rowIdx);   // dropdown change → validate this row immediately
      });
    });
  }

  /* ── Live per-row re-validation: fixing a cell updates that row's status +
        Issues + the summary counts on the fly, no manual "Re-validate" needed. ── */
  var pendingRV = 0, rvTimers = {};
  function recomputeSummary(){
    var ok = 0, w = 0, e = 0;
    VALIDATED.forEach(function (v) { if (v.status === 'error') e++; else if (v.status === 'warning') w++; else ok++; });
    renderSummary({ total: VALIDATED.length, ok: ok, warning: w, error: e });
  }
  function patchRowStatus(tr, v){
    var dotc = v.status === 'error' ? 'err' : (v.status === 'warning' ? 'warn' : 'ok');
    tr.className = v.status === 'error' ? 'row-error' : (v.status === 'warning' ? 'row-warn' : 'row-ok');
    var sc = tr.querySelector('.statuscell'); if (sc) sc.innerHTML = '<span class="dot ' + dotc + '"></span>' + v.status;
    var ic = tr.querySelector('.issues');     if (ic) ic.textContent = (v.errors || []).concat(v.warnings || []).join('; ');
  }
  function scheduleRowRevalidate(tr, rowIdx){
    dirty = true; el('editNote').classList.remove('imp-hidden');
    clearTimeout(rvTimers[rowIdx]);
    rvTimers[rowIdx] = setTimeout(function () { revalidateRowLive(tr, rowIdx); }, 550);
  }
  function revalidateRowLive(tr, rowIdx){
    pendingRV++;
    post('import_validate', { rows: [VALIDATED[rowIdx].data] }).then(function (resp) {
      if (resp && resp.rows && resp.rows[0]) {
        VALIDATED[rowIdx] = resp.rows[0];
        patchRowStatus(tr, VALIDATED[rowIdx]);
        // Reflect any server auto-fill (e.g. department inferred from the role).
        if (CAT_READY) {
          var d = VALIDATED[rowIdx].data;
          var dsel = tr.querySelector('select[data-key="department"]');
          var rsel = tr.querySelector('select[data-key="role"]');
          if (dsel) dsel.innerHTML = deptSelectHtml(d.department || '');
          if (rsel) rsel.innerHTML = roleSelectHtml(d.role || '', d.department || '');
        }
        recomputeSummary();
      }
    }).catch(function () {}).finally(function () {
      if (--pendingRV <= 0) { pendingRV = 0; dirty = false; el('editNote').classList.add('imp-hidden'); }
    });
  }
  function setBtnLoading(b, on, labelHtml) {
    if (on) { b.dataset.html = b.innerHTML; b.disabled = true; b.innerHTML = '<span class="spin"></span> ' + (labelHtml || 'Working…'); }
    else { b.disabled = false; b.innerHTML = b.dataset.html || b.innerHTML; }
  }

  el('validateBtn').addEventListener('click', function () {
    if (!refreshReqChips()) { alert('Please map all required fields first.'); return; }
    var b = this; setBtnLoading(b, true, 'Validating…');
    post('import_validate', { rows: buildCanonRows() }).then(function (resp) {
      VALIDATED = resp.rows; dirty = false;
      el('prevCard').classList.remove('imp-hidden');
      el('editNote').classList.add('imp-hidden');
      renderSummary(resp.summary); renderPreview();
      el('prevCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }).catch(function (e) { alert(e.message || 'Validation failed.'); })
      .finally(function () { setBtnLoading(b, false); });
  });

  el('revalidateBtn').addEventListener('click', function () {
    if (!VALIDATED) return;
    var b = this; setBtnLoading(b, true, 'Re-validating…');
    post('import_validate', { rows: VALIDATED.map(function (r) { return r.data; }) }).then(function (resp) {
      VALIDATED = resp.rows; dirty = false;
      el('editNote').classList.add('imp-hidden');
      renderSummary(resp.summary); renderPreview();
    }).catch(function (e) { alert(e.message || 'Re-validation failed.'); })
      .finally(function () { setBtnLoading(b, false); });
  });

  var BATCH_SIZE = 25;

  el('commitBtn').addEventListener('click', function () {
    if (!VALIDATED) return;
    if (dirty && !confirm('You have unsaved edits that were not re-validated. Import anyway?')) return;
    var importable = VALIDATED.filter(function (r) { return r.status !== 'error'; }).length;
    if (!importable) { alert('No valid rows to import.'); return; }
    if (!confirm('Import ' + importable + ' staff member(s)? This writes to the database.')) return;

    var b = this; setBtnLoading(b, true, 'Importing… (do not close)');
    el('revalidateBtn').disabled = true;

    var allRows = VALIDATED.filter(function (r) { return r.status !== 'error'; }).map(function (r) { return r.data; });
    var agg = { success: 0, duplicates: 0, error: 0 };
    var batched = allRows.length > BATCH_SIZE;
    if (batched) { el('progWrap').classList.remove('imp-hidden'); }

    function setProgress(done) {
      var pct = Math.round((done / allRows.length) * 100);
      el('progBar').style.width = pct + '%';
      el('progText').textContent = 'Imported ' + done + ' / ' + allRows.length + ' rows…';
    }
    function commitBatch(start) {
      var batch = allRows.slice(start, start + BATCH_SIZE);
      return post('import_commit', { rows: batch, firstBatch: start === 0 }).then(function (resp) {
        if (resp.status !== 'success') throw new Error(resp.message || 'Import failed.');
        var c = resp.counts || {};
        agg.success += c.success || 0; agg.duplicates += c.duplicates || 0; agg.error += c.error || 0;
        setProgress(Math.min(start + BATCH_SIZE, allRows.length));
        if (start + BATCH_SIZE < allRows.length) return commitBatch(start + BATCH_SIZE);
      });
    }

    if (batched) setProgress(0);
    commitBatch(0).then(function () {
      el('mapCard').classList.add('imp-hidden');
      el('prevCard').classList.add('imp-hidden');
      el('resultCard').classList.remove('imp-hidden');
      var html = '<div class="sum-chips">'
        + '<span class="sum-chip ok">Imported: ' + agg.success + '</span>'
        + (agg.duplicates ? '<span class="sum-chip warn">Duplicates skipped: ' + agg.duplicates + '</span>' : '')
        + '<span class="sum-chip err">Failed: ' + agg.error + '</span>'
        + '</div>';
      if (agg.success > 0) {
        html += '<div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border);">'
          + '<a href="' + SITE_URL + '/staff/import_credentials_pdf" target="_blank" rel="noopener" '
          + 'style="display:inline-block;background:var(--brand);color:#fff;text-decoration:none;font-weight:600;padding:9px 16px;border-radius:8px;font-size:13px;">'
          + '<i class="fa fa-file-pdf-o"></i> Download Credentials PDF</a>'
          + '<div style="margin-top:6px;color:var(--muted);font-size:12px;">'
          + 'Name, mobile number, User ID &amp; default password for the ' + agg.success
          + ' newly imported staff member' + (agg.success === 1 ? '' : 's') + '. Keep it confidential.</div></div>';
      }
      el('resultMsg').innerHTML = html;
      el('resultCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }).catch(function (e) {
      alert((e.message || 'Import failed.') + '\n\nImported so far: ' + agg.success
        + '. You can re-run — already-imported staff are detected as duplicates and skipped.');
      setBtnLoading(b, false); el('revalidateBtn').disabled = false;
      el('progWrap').classList.add('imp-hidden');
    });
  });

  el('errReportBtn').addEventListener('click', function () {
    if (!VALIDATED) return;
    var fields = SCHEMA.map(function (f) { return f.key; });
    var lines = [['Row'].concat(fields).concat(['Issues']).map(csvCell).join(',')];
    VALIDATED.forEach(function (v, i) {
      if (v.status !== 'error') return;
      var row = [String(i + 1)].concat(fields.map(function (k) { return v.data[k] || ''; }))
        .concat([(v.errors || []).concat(v.warnings || []).join(' | ')]);
      lines.push(row.map(csvCell).join(','));
    });
    var blob = new Blob([lines.join('\r\n')], { type: 'text/csv' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'staff_import_error_rows.csv';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
  });
  function csvCell(s) {
    s = String(s == null ? '' : s);
    return /[",\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
  }

  // Preload the Department/Role catalogue so the preview can offer dropdowns.
  loadCatalogue();
})();
</script>
