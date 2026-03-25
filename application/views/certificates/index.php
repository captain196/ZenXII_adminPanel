<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<style>
/* ── Certificate Module — Professional Design ──────────────────────── */

/* Layout safety — ensure content sits below header and beside sidebar */
.content-wrapper { margin-left:var(--sw,248px) !important; margin-top:var(--hh,58px) !important; min-height:calc(100vh - var(--hh,58px)) !important; }

.cert-wrap { padding:22px 28px; font-family:var(--font-b,'Segoe UI',sans-serif); min-height:80vh; }

/* Page Header */
.cert-page-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; flex-wrap:wrap; gap:10px; }
.cert-page-header h2 { margin:0; font-size:1.3rem; font-weight:700; color:var(--t1,#1a1a1a); display:flex; align-items:center; gap:10px; }
.cert-page-header h2 i { color:var(--gold,#0f766e); font-size:1.1rem; width:36px; height:36px; display:flex; align-items:center; justify-content:center; background:var(--gold-dim,rgba(15,118,110,.1)); border-radius:8px; }
.cert-page-header .cert-page-sub { font-size:12px; color:var(--t2,#888); font-weight:400; }

/* Tabs — pill style */
.cert-tabs { display:flex; gap:3px; margin-bottom:22px; flex-wrap:wrap; background:var(--bg2,#fff); border-radius:10px; padding:4px; box-shadow:0 2px 8px var(--sh,rgba(0,0,0,.06)); border:1px solid var(--border,#e5e5e5); }
.cert-tab { padding:9px 20px; cursor:pointer; font-weight:600; color:var(--t2,#666); border-radius:8px; transition:all .2s var(--ease,ease); font-size:12.5px; white-space:nowrap; display:flex; align-items:center; gap:7px; text-decoration:none; border:none; }
.cert-tab i { font-size:13px; opacity:.7; }
.cert-tab:hover { color:var(--gold,#0f766e); background:var(--gold-dim,rgba(15,118,110,.06)); }
.cert-tab:hover i { opacity:1; }
.cert-tab.active { color:#fff; background:var(--gold,#0f766e); box-shadow:0 2px 8px rgba(15,118,110,.25); }
.cert-tab.active i { opacity:1; color:#fff; }

/* Panels */
.cert-panel { display:none; animation:certFadeIn .25s ease; }
.cert-panel.active { display:block; }
@keyframes certFadeIn { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:translateY(0)} }

/* Card */
.cert-card { background:var(--bg2,#fff); border-radius:14px; border:1px solid var(--border,#e5e5e5); box-shadow:0 2px 12px var(--sh,rgba(0,0,0,.06)); overflow:hidden; margin-bottom:24px; }
.cert-card-head { display:flex; align-items:center; justify-content:space-between; padding:16px 22px; flex-wrap:wrap; gap:12px; border-bottom:1px solid var(--border,#eee); background:var(--bg3,#f8faf9); }
.cert-card-head h3 { margin:0; font-size:14px; font-weight:700; color:var(--t1,#1a1a1a); display:flex; align-items:center; gap:8px; }
.cert-card-head h3 i { color:var(--gold,#0f766e); font-size:13px; width:30px; height:30px; display:flex; align-items:center; justify-content:center; background:var(--gold-dim,rgba(15,118,110,.1)); border-radius:7px; }
.cert-card-body { padding:18px 22px; }

/* Filter bar */
.cert-filter-bar { display:flex; gap:10px; padding:14px 22px; flex-wrap:wrap; align-items:center; background:var(--bg,#f5f7f6); border-bottom:1px solid var(--border,#eee); }
.cert-filter-bar label { font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:var(--t2,#777); margin-right:-4px; }
.cert-filter-bar select,.cert-filter-bar input { padding:8px 12px; border:1px solid var(--border,#ddd); border-radius:7px; font-size:13px; background:var(--bg2,#fff); color:var(--t1,#333); min-width:140px; transition:border-color .2s,box-shadow .2s; }
.cert-filter-bar select:focus,.cert-filter-bar input:focus { border-color:var(--gold,#0f766e); box-shadow:0 0 0 3px var(--gold-ring,rgba(15,118,110,.18)); outline:none; }

/* Table */
.cert-table-wrap { overflow-x:auto; }
.cert-table { width:100%; border-collapse:collapse; font-size:12.5px; }
.cert-table th { background:var(--bg3,#f0f4f2); color:var(--t1,#333); font-weight:700; text-align:left; padding:10px 16px; font-size:10.5px; text-transform:uppercase; letter-spacing:.3px; border-bottom:2px solid var(--border,#ddd); white-space:nowrap; }
.cert-table td { padding:11px 16px; border-bottom:1px solid var(--border,#eee); font-size:12.5px; color:var(--t1,#333); vertical-align:middle; }
.cert-table tbody tr { transition:background .15s; }
.cert-table tbody tr:hover td { background:var(--gold-dim,rgba(15,118,110,.04)); }
.cert-table tbody tr:last-child td { border-bottom:none; }

/* Buttons */
.cert-btn { padding:8px 18px; border:none; border-radius:7px; font-weight:600; font-size:12.5px; cursor:pointer; transition:all .2s var(--ease,ease); display:inline-flex; align-items:center; gap:6px; text-decoration:none; }
.cert-btn:hover { transform:translateY(-1px); }
.cert-btn-primary { background:var(--gold,#0f766e); color:#fff; box-shadow:0 2px 6px rgba(15,118,110,.2); }
.cert-btn-primary:hover { background:var(--gold2,#0d6b63); box-shadow:0 4px 12px rgba(15,118,110,.3); }
.cert-btn-sm { padding:6px 11px; font-size:11.5px; border-radius:6px; }
.cert-btn-outline { background:transparent; color:var(--gold,#0f766e); border:1.5px solid var(--gold,#0f766e); }
.cert-btn-outline:hover { background:var(--gold-dim,rgba(15,118,110,.08)); }
.cert-btn-danger { background:#dc2626; color:#fff; }
.cert-btn-danger:hover { background:#b91c1c; }
.cert-btn-success { background:#059669; color:#fff; }
.cert-btn-success:hover { background:#047857; }

/* Badge */
.cert-badge { display:inline-block; padding:4px 10px; border-radius:20px; font-size:10.5px; font-weight:600; letter-spacing:.2px; }
.cert-badge-green { background:#d1fae5; color:#065f46; }
.cert-badge-blue  { background:#dbeafe; color:#1e40af; }
.cert-badge-amber { background:#fef3c7; color:#92400e; }
.cert-badge-red   { background:#fee2e2; color:#991b1b; }
.cert-badge-gray  { background:#f3f4f6; color:#4b5563; }
.cert-badge-purple { background:#ede9fe; color:#5b21b6; }

/* Dashboard Cards */
.cert-dash-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:24px; }
.cert-dash-card { background:var(--bg2,#fff); border-radius:12px; padding:20px 18px; box-shadow:0 2px 12px var(--sh,rgba(0,0,0,.07)); border:1px solid var(--border,#e8e8e8); transition:transform .2s,box-shadow .2s; display:flex; align-items:flex-start; gap:14px; }
.cert-dash-card:hover { transform:translateY(-2px); box-shadow:0 6px 18px var(--sh,rgba(0,0,0,.1)); }
.cert-dash-card .cert-dc-icon { width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.cert-dash-card .cert-dc-icon i { font-size:1.2rem; }
.cert-dash-card .cert-dc-body { flex:1; }
.cert-dash-card .cert-dc-val { font-size:28px; font-weight:800; color:var(--t1,#1a1a1a); line-height:1.1; }
.cert-dash-card .cert-dc-label { font-size:11px; color:var(--t2,#888); margin-top:4px; font-weight:500; }
.cert-dc-icon.teal  { background:rgba(15,118,110,.1); color:#0f766e; }
.cert-dc-icon.teal i { color:#0f766e; }
.cert-dc-icon.blue  { background:rgba(59,130,246,.1); }
.cert-dc-icon.blue i { color:#3b82f6; }
.cert-dc-icon.amber { background:rgba(217,119,6,.1); }
.cert-dc-icon.amber i { color:#d97706; }
.cert-dc-icon.purple { background:rgba(139,92,246,.1); }
.cert-dc-icon.purple i { color:#8b5cf6; }

/* Modal — positioning handled globally in header.php; only custom styling here */
.cert-modal { background:var(--bg2,#fff); border-radius:16px; padding:0; width:95%; max-width:720px; max-height:90vh; overflow-y:auto; box-shadow:0 12px 48px rgba(0,0,0,.2); animation:certModalIn .2s ease; }
@keyframes certModalIn { from{opacity:0;transform:scale(.96) translateY(10px)} to{opacity:1;transform:scale(1) translateY(0)} }
.cert-modal-head { padding:16px 22px; border-bottom:1px solid var(--border,#eee); display:flex; align-items:center; justify-content:space-between; background:var(--bg3,#f8faf9); border-radius:16px 16px 0 0; }
.cert-modal-head h4 { margin:0; font-size:14px; font-weight:700; color:var(--t1,#222); }
.cert-modal-close { background:var(--bg,#f0f0f0); border:none; font-size:14px; cursor:pointer; color:var(--t2,#999); width:30px; height:30px; border-radius:7px; display:flex; align-items:center; justify-content:center; transition:background .15s; }
.cert-modal-close:hover { background:#fee2e2; color:#dc2626; }
.cert-modal-body { padding:18px 22px; }
.cert-modal-foot { padding:14px 22px; border-top:1px solid var(--border,#eee); display:flex; justify-content:flex-end; gap:10px; background:var(--bg,#fafafa); border-radius:0 0 16px 16px; }

/* Form */
.cert-fg { margin-bottom:18px; }
.cert-fg label { display:block; font-weight:700; margin-bottom:5px; font-size:11px; color:var(--t2,#555); text-transform:uppercase; letter-spacing:.3px; }
.cert-fg input,.cert-fg select,.cert-fg textarea { width:100%; padding:8px 12px; border:1px solid var(--border,#ddd); border-radius:7px; font-size:13px; background:var(--bg,#f9f9f9); color:var(--t1,#333); transition:border-color .2s,box-shadow .2s; box-sizing:border-box; }
.cert-fg input:focus,.cert-fg select:focus,.cert-fg textarea:focus { border-color:var(--gold,#0f766e); box-shadow:0 0 0 3px var(--gold-ring,rgba(15,118,110,.18)); outline:none; }
.cert-fg textarea { resize:vertical; min-height:120px; font-family:inherit; }
.cert-row { display:flex; gap:16px; }
.cert-row>.cert-fg { flex:1; }

/* Empty state */
.cert-empty { text-align:center; padding:40px 24px; color:var(--t2,#999); font-size:13px; }
.cert-empty i { font-size:2rem; margin-bottom:10px; display:block; opacity:.4; }

/* Generate — steps */
.cert-step-bar { display:flex; gap:0; margin-bottom:24px; }
.cert-step { flex:1; text-align:center; padding:11px 8px; font-weight:600; font-size:12px; color:var(--t2,#999); border-bottom:3px solid var(--border,#ddd); transition:all .2s; }
.cert-step.active { color:var(--gold,#0f766e); border-bottom-color:var(--gold,#0f766e); }
.cert-step.done { color:#059669; border-bottom-color:#059669; }
.cert-step span { display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:var(--border,#e5e5e5); color:var(--t2,#999); font-size:11px; margin-right:6px; }
.cert-step.active span { background:var(--gold,#0f766e); color:#fff; }
.cert-step.done span { background:#059669; color:#fff; }

/* Generate — preview */
.cert-preview-frame { border:2px dashed var(--border,#ddd); border-radius:12px; padding:40px; min-height:400px; background:var(--bg2,#fff); position:relative; }

/* Placeholder tags */
.cert-ph-list { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
.cert-ph-tag { display:inline-block; padding:3px 8px; background:var(--gold-dim,rgba(15,118,110,.08)); color:var(--gold,#0f766e); font-size:11px; border-radius:5px; cursor:pointer; font-family:monospace; transition:background .15s; }
.cert-ph-tag:hover { background:var(--gold,#0f766e); color:#fff; }

/* ── Print Layout ──────────────────────────────────────────────────── */
.cert-print-area { display:none; }

@media print {
  body * { visibility:hidden !important; }
  .cert-print-area, .cert-print-area * { visibility:visible !important; }
  .cert-print-area {
    display:block !important;
    position:fixed; left:0; top:0; width:100%; height:100%;
    z-index:99999; background:#fff;
    padding:40px 60px;
    font-family:'Times New Roman',serif;
    color:#000;
  }
  .cert-print-header { text-align:center; margin-bottom:30px; border-bottom:3px double #333; padding-bottom:20px; }
  .cert-print-logo { height:80px; margin-bottom:10px; }
  .cert-print-school { font-size:24pt; font-weight:bold; margin:0; }
  .cert-print-address { font-size:11pt; color:#444; margin:4px 0 0; }
  .cert-print-title { text-align:center; font-size:20pt; font-weight:bold; margin:30px 0 10px; text-decoration:underline; text-transform:uppercase; letter-spacing:2px; }
  .cert-print-number { text-align:center; font-size:10pt; color:#666; margin-bottom:30px; }
  .cert-print-body { font-size:13pt; line-height:2; text-align:justify; margin:20px 0 40px; }
  .cert-print-footer { display:flex; justify-content:space-between; margin-top:60px; padding-top:20px; }
  .cert-print-sig { text-align:center; min-width:150px; }
  .cert-print-sig-line { border-top:1px solid #333; margin-top:50px; padding-top:8px; font-size:11pt; }
  .cert-print-date { text-align:left; font-size:11pt; margin-top:30px; }
  @page { margin:0.5in; size:A4 portrait; }
}

/* Responsive */
@media (max-width:1100px) { .cert-dash-grid{grid-template-columns:repeat(2,1fr)} }
@media (max-width:768px) {
  .cert-wrap{padding:16px 12px} .cert-row{flex-direction:column;gap:0} .cert-dash-grid{grid-template-columns:1fr 1fr}
  .cert-tabs{gap:2px;padding:4px;border-radius:8px} .cert-tab{padding:8px 14px;font-size:11.5px} .cert-tab i{display:none}
  .cert-page-header h2{font-size:1.1rem} .cert-card-head{padding:14px 16px} .cert-card-head h3{font-size:13px}
  .cert-filter-bar{padding:12px 14px} .cert-table th,.cert-table td{padding:9px 12px}
}
@media (max-width:480px) { .cert-dash-grid{grid-template-columns:1fr} .cert-dash-card{padding:16px 14px} }
</style>

<div class="content-wrapper">
  <section class="content">
    <div class="cert-wrap">
      <!-- Page Header -->
      <div class="cert-page-header">
        <h2><i class="fa fa-certificate"></i> Certificate Management <span class="cert-page-sub">| <?= htmlspecialchars($session_year) ?></span></h2>
      </div>

      <!-- Tab Navigation -->
      <?php $at = $active_tab ?? 'dashboard'; ?>
      <div class="cert-tabs">
        <a href="<?= base_url('certificates') ?>" class="cert-tab <?= $at === 'dashboard' ? 'active' : '' ?>"><i class="fa fa-dashboard"></i> Dashboard</a>
        <a href="<?= base_url('certificates/templates') ?>" class="cert-tab <?= $at === 'templates' ? 'active' : '' ?>"><i class="fa fa-file-text-o"></i> Templates</a>
        <a href="<?= base_url('certificates/generate') ?>" class="cert-tab <?= $at === 'generate' ? 'active' : '' ?>"><i class="fa fa-magic"></i> Generate</a>
        <a href="<?= base_url('certificates/issued') ?>" class="cert-tab <?= $at === 'issued' ? 'active' : '' ?>"><i class="fa fa-list-alt"></i> Issued</a>
      </div>

      <!-- ═══════════════════════════════════════════════════════════════
           DASHBOARD
      ═══════════════════════════════════════════════════════════════ -->
      <div class="cert-panel <?= $at === 'dashboard' ? 'active' : '' ?>" id="panel-dashboard">
        <div class="cert-dash-grid">
          <div class="cert-dash-card">
            <div class="cert-dc-icon teal"><i class="fa fa-certificate"></i></div>
            <div class="cert-dc-body">
              <div class="cert-dc-val" id="dc-total">-</div>
              <div class="cert-dc-label">Total Issued</div>
            </div>
          </div>
          <div class="cert-dash-card">
            <div class="cert-dc-icon blue"><i class="fa fa-calendar-check-o"></i></div>
            <div class="cert-dc-body">
              <div class="cert-dc-val" id="dc-today">-</div>
              <div class="cert-dc-label">Issued Today</div>
            </div>
          </div>
          <div class="cert-dash-card">
            <div class="cert-dc-icon amber"><i class="fa fa-id-card"></i></div>
            <div class="cert-dc-body">
              <div class="cert-dc-val" id="dc-bonafide">-</div>
              <div class="cert-dc-label">Bonafide</div>
            </div>
          </div>
          <div class="cert-dash-card">
            <div class="cert-dc-icon purple"><i class="fa fa-exchange"></i></div>
            <div class="cert-dc-body">
              <div class="cert-dc-val" id="dc-transfer">-</div>
              <div class="cert-dc-label">Transfer</div>
            </div>
          </div>
        </div>

        <!-- Recent certificates -->
        <div class="cert-card">
          <div class="cert-card-head">
            <h3><i class="fa fa-clock-o"></i> Recent Certificates</h3>
          </div>
          <div class="cert-card-body" style="padding:0;">
            <div class="cert-table-wrap" id="dashRecentWrap">
              <div class="cert-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</div>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══════════════════════════════════════════════════════════════
           TEMPLATES
      ═══════════════════════════════════════════════════════════════ -->
      <div class="cert-panel <?= $at === 'templates' ? 'active' : '' ?>" id="panel-templates">
        <div class="cert-card">
          <div class="cert-card-head">
            <h3><i class="fa fa-file-text-o"></i> Certificate Templates</h3>
            <?php if ($can_manage): ?>
            <button class="cert-btn cert-btn-primary" onclick="CERT.tpl.openModal()"><i class="fa fa-plus"></i> New Template</button>
            <?php endif; ?>
          </div>
          <div class="cert-card-body" style="padding:0;">
            <div class="cert-table-wrap" id="templatesTableWrap">
              <div class="cert-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</div>
            </div>
          </div>
        </div>

        <!-- Placeholder Reference -->
        <div class="cert-card" style="margin-top:24px;">
          <div class="cert-card-head">
            <h3><i class="fa fa-info-circle"></i> Available Placeholders</h3>
          </div>
          <div class="cert-card-body">
            <p style="color:var(--t2,#666); font-size:.95rem; margin:0 0 10px;">Click a placeholder to copy it. Use these in template body text.</p>
            <div class="cert-ph-list" id="placeholderList"></div>
          </div>
        </div>
      </div>

      <!-- ═══════════════════════════════════════════════════════════════
           GENERATE CERTIFICATE
      ═══════════════════════════════════════════════════════════════ -->
      <div class="cert-panel <?= $at === 'generate' ? 'active' : '' ?>" id="panel-generate">
        <div class="cert-card">
          <div class="cert-card-head">
            <h3><i class="fa fa-magic"></i> Generate Certificate</h3>
          </div>
          <div class="cert-card-body">
            <!-- Step bar -->
            <div class="cert-step-bar">
              <div class="cert-step active" id="step1"><span>1</span> Select Student</div>
              <div class="cert-step" id="step2"><span>2</span> Choose Template</div>
              <div class="cert-step" id="step3"><span>3</span> Preview & Issue</div>
            </div>

            <!-- Step 1: Select student -->
            <div id="genStep1">
              <div class="cert-row">
                <div class="cert-fg">
                  <label>Class *</label>
                  <select id="gen_classKey" onchange="CERT.gen.loadSections()">
                    <option value="">Select Class</option>
                  </select>
                </div>
                <div class="cert-fg">
                  <label>Section</label>
                  <select id="gen_sectionKey" onchange="CERT.gen.loadStudents()">
                    <option value="">All Sections</option>
                  </select>
                </div>
              </div>
              <div class="cert-fg">
                <label>Student *</label>
                <select id="gen_student">
                  <option value="">Select student after choosing class</option>
                </select>
              </div>
              <div style="text-align:right; margin-top:12px;">
                <button class="cert-btn cert-btn-primary" onclick="CERT.gen.toStep2()"><i class="fa fa-arrow-right"></i> Next: Choose Template</button>
              </div>
            </div>

            <!-- Step 2: Choose template -->
            <div id="genStep2" style="display:none;">
              <div class="cert-row">
                <div class="cert-fg">
                  <label>Certificate Type *</label>
                  <select id="gen_certType" onchange="CERT.gen.filterTemplates()">
                    <option value="">Select Type</option>
                    <option value="bonafide">Bonafide Certificate</option>
                    <option value="transfer">Transfer Certificate</option>
                    <option value="character">Character Certificate</option>
                    <option value="custom">Custom Certificate</option>
                  </select>
                </div>
                <div class="cert-fg">
                  <label>Template *</label>
                  <select id="gen_template">
                    <option value="">Select template after choosing type</option>
                  </select>
                </div>
              </div>

              <!-- Extra fields for transfer certificate -->
              <div id="genExtraFields" style="display:none;">
                <div class="cert-row">
                  <div class="cert-fg">
                    <label>Date of Leaving</label>
                    <input type="date" id="gen_leavingDate">
                  </div>
                  <div class="cert-fg">
                    <label>Conduct</label>
                    <select id="gen_conduct">
                      <option value="Good">Good</option>
                      <option value="Very Good">Very Good</option>
                      <option value="Excellent">Excellent</option>
                      <option value="Satisfactory">Satisfactory</option>
                    </select>
                  </div>
                </div>
                <div class="cert-fg">
                  <label>Reason for Leaving</label>
                  <input type="text" id="gen_reason" placeholder="e.g. Transfer to another school">
                </div>
              </div>

              <div style="display:flex; justify-content:space-between; margin-top:12px;">
                <button class="cert-btn cert-btn-outline" onclick="CERT.gen.toStep1()"><i class="fa fa-arrow-left"></i> Back</button>
                <button class="cert-btn cert-btn-primary" onclick="CERT.gen.toStep3()"><i class="fa fa-eye"></i> Preview Certificate</button>
              </div>
            </div>

            <!-- Step 3: Preview & Issue -->
            <div id="genStep3" style="display:none;">
              <div class="cert-preview-frame" id="genPreview">
                <div class="cert-empty"><i class="fa fa-certificate"></i> Preview will appear here</div>
              </div>
              <div style="display:flex; justify-content:space-between; margin-top:20px; flex-wrap:wrap; gap:12px;">
                <button class="cert-btn cert-btn-outline" onclick="CERT.gen.toStep2()"><i class="fa fa-arrow-left"></i> Back</button>
                <div style="display:flex; gap:12px;">
                  <button class="cert-btn cert-btn-outline" onclick="CERT.gen.print()"><i class="fa fa-print"></i> Print</button>
                  <button class="cert-btn cert-btn-primary" onclick="CERT.gen.issue()"><i class="fa fa-check"></i> Issue Certificate</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══════════════════════════════════════════════════════════════
           ISSUED CERTIFICATES
      ═══════════════════════════════════════════════════════════════ -->
      <div class="cert-panel <?= $at === 'issued' ? 'active' : '' ?>" id="panel-issued">
        <div class="cert-card">
          <div class="cert-card-head">
            <h3><i class="fa fa-list-alt"></i> Issued Certificates</h3>
          </div>
          <div class="cert-filter-bar">
            <label>Type</label>
            <select id="issuedFilterType" onchange="CERT.issued.render()">
              <option value="">All Types</option>
              <option value="bonafide">Bonafide</option>
              <option value="transfer">Transfer</option>
              <option value="character">Character</option>
              <option value="custom">Custom</option>
            </select>
            <label>Search</label>
            <input type="text" id="issuedSearch" placeholder="Search name or number..." oninput="CERT.issued.render()" style="min-width:220px;">
          </div>
          <div class="cert-card-body" style="padding:0;">
            <div class="cert-table-wrap" id="issuedTableWrap">
              <div class="cert-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</div>
            </div>
          </div>
        </div>
      </div>

    </div><!-- .cert-wrap -->
  </section>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     TEMPLATE MODAL
═══════════════════════════════════════════════════════════════════ -->
<div class="cert-modal-bg" id="templateModal">
  <div class="cert-modal" style="max-width:800px;">
    <div class="cert-modal-head">
      <h4 id="templateModalTitle">New Certificate Template</h4>
      <button class="cert-modal-close" onclick="CERT.tpl.closeModal()">&times;</button>
    </div>
    <div class="cert-modal-body">
      <input type="hidden" id="tpl_id">
      <div class="cert-row">
        <div class="cert-fg">
          <label>Template Name *</label>
          <input type="text" id="tpl_name" placeholder="e.g. Bonafide Certificate">
        </div>
        <div class="cert-fg">
          <label>Type *</label>
          <select id="tpl_type">
            <option value="bonafide">Bonafide</option>
            <option value="transfer">Transfer</option>
            <option value="character">Character</option>
            <option value="custom">Custom</option>
          </select>
        </div>
      </div>
      <div class="cert-fg">
        <label>Certificate Title</label>
        <input type="text" id="tpl_title" placeholder="e.g. BONAFIDE CERTIFICATE">
      </div>
      <div class="cert-fg">
        <label>Template Body *</label>
        <textarea id="tpl_body" rows="10" placeholder="This is to certify that {student_name}, son/daughter of {father_name}..."></textarea>
        <div class="cert-ph-list" style="margin-top:8px;" id="tplPlaceholderList"></div>
      </div>
    </div>
    <div class="cert-modal-foot">
      <button class="cert-btn cert-btn-outline" onclick="CERT.tpl.closeModal()">Cancel</button>
      <button class="cert-btn cert-btn-primary" onclick="CERT.tpl.save()"><i class="fa fa-save"></i> Save Template</button>
    </div>
  </div>
</div>

<!-- View Certificate Modal -->
<div class="cert-modal-bg" id="viewCertModal">
  <div class="cert-modal" style="max-width:850px;">
    <div class="cert-modal-head">
      <h4 id="viewCertTitle">Certificate</h4>
      <button class="cert-modal-close" onclick="$('#viewCertModal').removeClass('show')">&times;</button>
    </div>
    <div class="cert-modal-body" id="viewCertBody">
      <div class="cert-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</div>
    </div>
    <div class="cert-modal-foot">
      <button class="cert-btn cert-btn-outline" onclick="CERT.issued.printCert()"><i class="fa fa-print"></i> Print</button>
      <button class="cert-btn cert-btn-outline" onclick="$('#viewCertModal').removeClass('show')">Close</button>
    </div>
  </div>
</div>

<!-- Print Area (hidden, used for printing) -->
<div class="cert-print-area" id="certPrintArea"></div>

<!-- ═══════════════════════════════════════════════════════════════════
     JAVASCRIPT
═══════════════════════════════════════════════════════════════════ -->
<script>
document.addEventListener('DOMContentLoaded', function() {

  var BASE = '<?= base_url() ?>';
  var CSRF = '<?= $this->security->get_csrf_token_name() ?>';
  var CSRF_HASH = '<?= $this->security->get_csrf_hash() ?>';
  var CSRF_COOKIE = '<?= $this->config->item('csrf_cookie_name') ?>';
  var ROLE = '<?= htmlspecialchars($admin_role) ?>';
  var CAN_MANAGE = <?= $can_manage ? 'true' : 'false' ?>;

  var _classes = [];
  var _templates = [];
  var _placeholders = [];
  var _currentCert = null; // for print

  /* ── AJAX helpers ─────────────────────────────────────────────────── */
  function post(url, data) {
    if (!data) data = {};
    data[CSRF] = CSRF_HASH;
    return $.ajax({ url: BASE + url, type: 'POST', data: data, dataType: 'json' }).always(function() {
      var cookie = document.cookie.match(new RegExp('(?:^|;\\s*)' + CSRF_COOKIE + '=([^;]+)'));
      if (cookie) CSRF_HASH = decodeURIComponent(cookie[1]);
    });
  }

  function get(url) {
    return $.ajax({ url: BASE + url, type: 'GET', dataType: 'json' });
  }

  function toast(msg, ok) {
    var el = document.createElement('div');
    el.textContent = msg;
    el.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;padding:14px 26px;border-radius:8px;font-weight:600;font-size:1rem;color:#fff;background:' + (ok ? '#059669' : '#dc2626') + ';box-shadow:0 4px 16px rgba(0,0,0,.2);transition:opacity .3s;';
    document.body.appendChild(el);
    setTimeout(function() { el.style.opacity = '0'; setTimeout(function() { el.remove(); }, 300); }, 3000);
  }

  function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

  /* ── Tab data loading ────────────────────────────────────────────── */
  var ACTIVE_TAB = '<?= $at ?>';

  function loadTabData(tab) {
    if (tab === 'dashboard')  CERT.dash.load();
    if (tab === 'templates')  CERT.tpl.load();
    if (tab === 'generate')   CERT.gen.init();
    if (tab === 'issued')     CERT.issued.load();
  }

  /* ── Load classes for dropdowns ──────────────────────────────────── */
  function loadClasses(cb) {
    get('certificates/get_classes').done(function(r) {
      if (r && r.status === 'success') {
        var d = r.data || {};
        _classes = d.classes || [];
      }
      if (cb) cb();
    });
  }

  function populateClassSelect(selId) {
    var sel = document.getElementById(selId);
    if (!sel) return;
    var html = '<option value="">Select Class</option>';
    var seen = {};
    _classes.forEach(function(c) {
      if (!seen[c.class_key]) {
        seen[c.class_key] = true;
        html += '<option value="' + esc(c.class_key) + '">' + esc(c.class_key) + '</option>';
      }
    });
    sel.innerHTML = html;
  }

  function populateSectionSelect(classKey, selId) {
    var sel = document.getElementById(selId);
    if (!sel) return;
    var html = '<option value="">All Sections</option>';
    _classes.forEach(function(c) {
      if (c.class_key === classKey) {
        html += '<option value="' + esc(c.section) + '">Section ' + esc(c.section) + '</option>';
      }
    });
    sel.innerHTML = html;
  }

  /* ══════════════════════════════════════════════════════════════════
     CERT — main namespace
  ══════════════════════════════════════════════════════════════════ */
  window.CERT = {};

  /* ── DASHBOARD ───────────────────────────────────────────────────── */
  CERT.dash = {
    load: function() {
      get('certificates/get_dashboard').done(function(r) {
        if (!r || r.status !== 'success') return;
        var d = r.data || {};
        document.getElementById('dc-total').textContent = d.total || 0;
        document.getElementById('dc-today').textContent = d.today || 0;
        document.getElementById('dc-bonafide').textContent = d.bonafide || 0;
        document.getElementById('dc-transfer').textContent = d.transfer || 0;

        var wrap = document.getElementById('dashRecentWrap');
        if (!d.recent || !d.recent.length) {
          wrap.innerHTML = '<div class="cert-empty"><i class="fa fa-certificate"></i> No certificates issued yet</div>';
          return;
        }
        var html = '<table class="cert-table"><thead><tr><th>Cert #</th><th>Student</th><th>Type</th><th>Class</th><th>Issue Date</th><th>Issued By</th></tr></thead><tbody>';
        d.recent.forEach(function(c) {
          var typeBadge = c.certificateType === 'bonafide' ? 'amber' : c.certificateType === 'transfer' ? 'purple' : c.certificateType === 'character' ? 'blue' : 'gray';
          html += '<tr>' +
            '<td><strong>' + esc(c.certificateNumber) + '</strong></td>' +
            '<td>' + esc(c.studentName) + '</td>' +
            '<td><span class="cert-badge cert-badge-' + typeBadge + '">' + esc(c.certificateType) + '</span></td>' +
            '<td>' + esc(c.classKey) + (c.sectionKey ? ' / ' + esc(c.sectionKey) : '') + '</td>' +
            '<td>' + esc(c.issueDate) + '</td>' +
            '<td>' + esc(c.issuedBy) + '</td>' +
            '</tr>';
        });
        html += '</tbody></table>';
        wrap.innerHTML = html;
      });
    }
  };

  /* ── TEMPLATES ───────────────────────────────────────────────────── */
  CERT.tpl = {
    _data: [],

    load: function() {
      get('certificates/get_templates').done(function(r) {
        if (!r || r.status !== 'success') return;
        var d = r.data || {};
        CERT.tpl._data = d.templates || [];
        _templates = CERT.tpl._data;
        _placeholders = d.placeholders || [];
        CERT.tpl.render();
        CERT.tpl.renderPlaceholders();
      });
    },

    render: function() {
      var wrap = document.getElementById('templatesTableWrap');
      var rows = CERT.tpl._data;
      if (!rows.length) {
        wrap.innerHTML = '<div class="cert-empty"><i class="fa fa-file-text-o"></i> No templates yet. Create your first template.</div>';
        return;
      }
      var html = '<table class="cert-table"><thead><tr><th>Name</th><th>Type</th><th>Last Updated</th><th>Actions</th></tr></thead><tbody>';
      rows.forEach(function(t) {
        var typeBadge = t.type === 'bonafide' ? 'amber' : t.type === 'transfer' ? 'purple' : t.type === 'character' ? 'blue' : 'gray';
        html += '<tr>' +
          '<td><strong>' + esc(t.name || t.title || '') + '</strong>' + (t.builtIn ? ' <span class="cert-badge cert-badge-green" style="font-size:.75rem;">Built-in</span>' : '') + '</td>' +
          '<td><span class="cert-badge cert-badge-' + typeBadge + '">' + esc(t.type) + '</span></td>' +
          '<td>' + esc((t.updatedAt || t.createdAt || '').substring(0, 10)) + '</td>' +
          '<td>';
        html += '<button class="cert-btn cert-btn-sm cert-btn-outline" onclick="CERT.tpl.preview(\'' + esc(t.id) + '\')"><i class="fa fa-eye"></i></button> ';
        if (CAN_MANAGE) {
          html += '<button class="cert-btn cert-btn-sm cert-btn-outline" onclick="CERT.tpl.edit(\'' + esc(t.id) + '\')"><i class="fa fa-pencil"></i></button> ';
          if (!t.builtIn) {
            html += '<button class="cert-btn cert-btn-sm cert-btn-danger" onclick="CERT.tpl.del(\'' + esc(t.id) + '\')"><i class="fa fa-trash"></i></button>';
          }
        }
        html += '</td></tr>';
      });
      html += '</tbody></table>';
      wrap.innerHTML = html;
    },

    renderPlaceholders: function() {
      var containers = ['placeholderList', 'tplPlaceholderList'];
      containers.forEach(function(cid) {
        var el = document.getElementById(cid);
        if (!el) return;
        var html = '';
        _placeholders.forEach(function(ph) {
          html += '<span class="cert-ph-tag" onclick="CERT.tpl.copyPH(this)" title="Click to copy">' + esc(ph) + '</span>';
        });
        el.innerHTML = html;
      });
    },

    copyPH: function(el) {
      var text = el.textContent;
      // Insert at cursor if body textarea focused, else copy to clipboard
      var ta = document.getElementById('tpl_body');
      if (ta && document.activeElement === ta) {
        var start = ta.selectionStart;
        var end = ta.selectionEnd;
        ta.value = ta.value.substring(0, start) + text + ta.value.substring(end);
        ta.selectionStart = ta.selectionEnd = start + text.length;
        ta.focus();
      } else if (navigator.clipboard) {
        navigator.clipboard.writeText(text);
        toast('Copied: ' + text, true);
      }
    },

    openModal: function(data) {
      var d = data || {};
      document.getElementById('templateModalTitle').textContent = d.id ? 'Edit Template' : 'New Certificate Template';
      document.getElementById('tpl_id').value = d.id || '';
      document.getElementById('tpl_name').value = d.name || '';
      document.getElementById('tpl_type').value = d.type || 'bonafide';
      document.getElementById('tpl_title').value = d.title || '';
      document.getElementById('tpl_body').value = d.body || '';

      // Disable type change for built-in templates
      document.getElementById('tpl_type').disabled = !!d.builtIn;

      $('#templateModal').addClass('show');
    },

    closeModal: function() {
      $('#templateModal').removeClass('show');
      document.getElementById('tpl_type').disabled = false;
    },

    edit: function(id) {
      var tpl = CERT.tpl._data.find(function(t) { return t.id === id; });
      if (tpl) CERT.tpl.openModal(tpl);
    },

    preview: function(id) {
      var tpl = CERT.tpl._data.find(function(t) { return t.id === id; });
      if (!tpl) return;

      var body = esc(tpl.body || '').replace(/\n/g, '<br>');
      var html = '<div style="border:2px dashed var(--border,#ddd); border-radius:12px; padding:40px; text-align:center;">' +
        '<h2 style="text-transform:uppercase; letter-spacing:2px; margin-bottom:20px; color:var(--t1,#222);">' + esc(tpl.title || tpl.name || '') + '</h2>' +
        '<div style="text-align:justify; line-height:2; font-size:1.05rem; color:var(--t1,#333);">' + body + '</div>' +
        '</div>';

      document.getElementById('viewCertTitle').textContent = tpl.name || 'Template Preview';
      document.getElementById('viewCertBody').innerHTML = html;
      $('#viewCertModal').addClass('show');
    },

    save: function() {
      var id = document.getElementById('tpl_id').value;
      var name = document.getElementById('tpl_name').value.trim();
      var type = document.getElementById('tpl_type').value;
      var title = document.getElementById('tpl_title').value.trim();
      var body = document.getElementById('tpl_body').value.trim();

      if (!name) return toast('Template name is required.', false);
      if (!body) return toast('Template body is required.', false);

      post('certificates/save_template', { id: id, name: name, type: type, title: title, body: body })
        .done(function(r) {
          if (r && r.status === 'success') {
            toast('Template saved.', true);
            CERT.tpl.closeModal();
            CERT.tpl.load();
          } else {
            toast(r && r.message || 'Save failed.', false);
          }
        })
        .fail(function() { toast('Network error.', false); });
    },

    del: function(id) {
      if (!confirm('Delete this template?')) return;
      post('certificates/delete_template', { id: id })
        .done(function(r) {
          if (r && r.status === 'success') {
            toast('Template deleted.', true);
            CERT.tpl.load();
          } else {
            toast(r && r.message || 'Delete failed.', false);
          }
        });
    }
  };

  /* ── GENERATE CERTIFICATE ────────────────────────────────────────── */
  CERT.gen = {
    _student: null,
    _template: null,
    _resolvedBody: '',
    _resolvedTitle: '',

    init: function() {
      populateClassSelect('gen_classKey');
      // Also load templates
      if (!_templates.length) {
        get('certificates/get_templates').done(function(r) {
          if (r && r.status === 'success') {
            var d = r.data || {};
            _templates = d.templates || [];
            _placeholders = d.placeholders || [];
          }
        });
      }
    },

    loadSections: function() {
      var ck = document.getElementById('gen_classKey').value;
      populateSectionSelect(ck, 'gen_sectionKey');
      document.getElementById('gen_student').innerHTML = '<option value="">Select student after choosing class</option>';
      if (ck) CERT.gen.loadStudents();
    },

    loadStudents: function() {
      var ck = document.getElementById('gen_classKey').value;
      var sk = document.getElementById('gen_sectionKey').value;
      if (!ck) return;

      post('certificates/get_students', { classKey: ck, sectionKey: sk })
        .done(function(r) {
          if (!r || r.status !== 'success') return;
          var d = r.data || {};
          var sel = document.getElementById('gen_student');
          var html = '<option value="">Select Student</option>';
          (d.students || []).forEach(function(s) {
            html += '<option value="' + esc(s.user_id) + '">' + esc(s.name) + ' (Section ' + esc(s.section) + ')</option>';
          });
          sel.innerHTML = html;
        });
    },

    toStep1: function() {
      document.getElementById('genStep1').style.display = '';
      document.getElementById('genStep2').style.display = 'none';
      document.getElementById('genStep3').style.display = 'none';
      document.getElementById('step1').className = 'cert-step active';
      document.getElementById('step2').className = 'cert-step';
      document.getElementById('step3').className = 'cert-step';
    },

    toStep2: function() {
      var studentId = document.getElementById('gen_student').value;
      if (!studentId) return toast('Please select a student.', false);

      // Load student details
      post('certificates/get_student_details', { userId: studentId })
        .done(function(r) {
          if (!r || r.status !== 'success') { toast(r && r.message || 'Error loading student.', false); return; }
          var d = r.data || {};
          CERT.gen._student = d.student;

          document.getElementById('genStep1').style.display = 'none';
          document.getElementById('genStep2').style.display = '';
          document.getElementById('genStep3').style.display = 'none';
          document.getElementById('step1').className = 'cert-step done';
          document.getElementById('step2').className = 'cert-step active';
          document.getElementById('step3').className = 'cert-step';

          CERT.gen.filterTemplates();
        });
    },

    filterTemplates: function() {
      var type = document.getElementById('gen_certType').value;
      var sel = document.getElementById('gen_template');
      var html = '<option value="">Select Template</option>';

      // Show/hide extra fields for transfer
      document.getElementById('genExtraFields').style.display = (type === 'transfer') ? '' : 'none';

      _templates.forEach(function(t) {
        if (type && t.type !== type) return;
        html += '<option value="' + esc(t.id) + '">' + esc(t.name || t.title || t.id) + '</option>';
      });
      sel.innerHTML = html;
    },

    toStep3: function() {
      var certType = document.getElementById('gen_certType').value;
      var templateId = document.getElementById('gen_template').value;
      if (!certType) return toast('Select a certificate type.', false);
      if (!templateId) return toast('Select a template.', false);

      var tpl = _templates.find(function(t) { return t.id === templateId; });
      if (!tpl) return toast('Template not found.', false);
      CERT.gen._template = tpl;

      // Build resolved text
      var s = CERT.gen._student || {};
      var replacements = {
        '{student_name}': s.name || '',
        '{father_name}': s.father_name || '',
        '{mother_name}': s.mother_name || '',
        '{class}': s.class || '',
        '{section}': s.section || '',
        '{admission_number}': s.admission_number || '',
        '{date_of_birth}': s.dob || '',
        '{issue_date}': new Date().toISOString().substring(0, 10),
        '{school_name}': '<?= addslashes(htmlspecialchars($school_name)) ?>',
        '{school_address}': '',
        '{certificate_number}': '(auto-generated)',
        '{session}': '<?= addslashes(htmlspecialchars($session_year)) ?>',
        '{gender}': s.gender || '',
        '{nationality}': s.nationality || 'Indian',
        '{religion}': s.religion || '',
        '{caste}': s.caste || '',
        '{enrollment_date}': s.enrollment_date || '',
        '{leaving_date}': document.getElementById('gen_leavingDate').value || '',
        '{conduct}': document.getElementById('gen_conduct').value || 'Good',
        '{reason_for_leaving}': document.getElementById('gen_reason').value || ''
      };

      var body = tpl.body || '';
      var title = tpl.title || tpl.name || '';
      Object.keys(replacements).forEach(function(k) {
        body = body.split(k).join(replacements[k]);
        title = title.split(k).join(replacements[k]);
      });

      CERT.gen._resolvedBody = body;
      CERT.gen._resolvedTitle = title;

      // Render preview
      var preview = document.getElementById('genPreview');
      preview.innerHTML =
        '<div style="text-align:center; margin-bottom:24px;">' +
          '<h2 style="font-size:1.5rem; font-weight:700; text-transform:uppercase; letter-spacing:2px; color:var(--t1,#222); margin:0;">' + esc(title) + '</h2>' +
        '</div>' +
        '<div style="font-size:1.08rem; line-height:2; color:var(--t1,#333); text-align:justify; white-space:pre-line;">' + esc(body) + '</div>' +
        '<div style="display:flex; justify-content:space-between; margin-top:60px; color:var(--t2,#888);">' +
          '<div>Date: ' + esc(replacements['{issue_date}']) + '</div>' +
          '<div style="text-align:center; border-top:1px solid var(--border,#ccc); padding-top:8px; min-width:150px;">Principal</div>' +
        '</div>';

      document.getElementById('genStep1').style.display = 'none';
      document.getElementById('genStep2').style.display = 'none';
      document.getElementById('genStep3').style.display = '';
      document.getElementById('step1').className = 'cert-step done';
      document.getElementById('step2').className = 'cert-step done';
      document.getElementById('step3').className = 'cert-step active';
    },

    issue: function() {
      if (!CAN_MANAGE) return toast('Permission denied.', false);
      if (!CERT.gen._student || !CERT.gen._template) return toast('Missing data.', false);

      var s = CERT.gen._student;
      var certType = document.getElementById('gen_certType').value;
      var templateId = document.getElementById('gen_template').value;
      var classKey = document.getElementById('gen_classKey').value;
      var sectionKey = document.getElementById('gen_sectionKey').value;

      var payload = {
        certificateType: certType,
        templateId: templateId,
        userId: s.user_id,
        classKey: classKey,
        sectionKey: sectionKey,
        'extraData[leaving_date]': document.getElementById('gen_leavingDate').value || '',
        'extraData[conduct]': document.getElementById('gen_conduct').value || 'Good',
        'extraData[reason_for_leaving]': document.getElementById('gen_reason').value || ''
      };

      post('certificates/generate_certificate', payload)
        .done(function(r) {
          if (r && r.status === 'success') {
            var d = r.data || {};
            toast('Certificate issued: ' + (d.certificateNumber || ''), true);
            // Reset
            CERT.gen.toStep1();
            document.getElementById('gen_student').value = '';
            document.getElementById('gen_certType').value = '';
            document.getElementById('gen_template').innerHTML = '<option value="">Select template after choosing type</option>';
          } else {
            toast(r && r.message || 'Issue failed.', false);
          }
        })
        .fail(function() { toast('Network error.', false); });
    },

    print: function() {
      CERT.printCertificate({
        title: CERT.gen._resolvedTitle,
        body: CERT.gen._resolvedBody,
        issueDate: new Date().toISOString().substring(0, 10),
        certNumber: '(Preview)',
        schoolName: '<?= addslashes(htmlspecialchars($school_name)) ?>'
      });
    }
  };

  /* ── ISSUED CERTIFICATES ─────────────────────────────────────────── */
  CERT.issued = {
    _data: [],
    _viewCert: null,

    load: function() {
      get('certificates/get_issued').done(function(r) {
        if (!r || r.status !== 'success') return;
        var d = r.data || {};
        CERT.issued._data = d.issued || [];
        CERT.issued.render();
      });
    },

    render: function() {
      var wrap = document.getElementById('issuedTableWrap');
      var rows = CERT.issued._data;
      var ft = document.getElementById('issuedFilterType').value;
      var fs = (document.getElementById('issuedSearch').value || '').toLowerCase();

      if (ft) rows = rows.filter(function(r) { return r.certificateType === ft; });
      if (fs) rows = rows.filter(function(r) {
        return (r.studentName || '').toLowerCase().indexOf(fs) > -1 ||
               (r.certificateNumber || '').toLowerCase().indexOf(fs) > -1;
      });

      if (!rows.length) {
        wrap.innerHTML = '<div class="cert-empty"><i class="fa fa-list-alt"></i> No certificates found</div>';
        return;
      }

      var html = '<table class="cert-table"><thead><tr><th>Cert #</th><th>Student</th><th>Type</th><th>Class</th><th>Issue Date</th><th>Issued By</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
      rows.forEach(function(c) {
        var typeBadge = c.certificateType === 'bonafide' ? 'amber' : c.certificateType === 'transfer' ? 'purple' : c.certificateType === 'character' ? 'blue' : 'gray';
        var statusBadge = c.revoked ? '<span class="cert-badge cert-badge-red">Revoked</span>' : '<span class="cert-badge cert-badge-green">Active</span>';

        html += '<tr>' +
          '<td><strong>' + esc(c.certificateNumber) + '</strong></td>' +
          '<td>' + esc(c.studentName) + '</td>' +
          '<td><span class="cert-badge cert-badge-' + typeBadge + '">' + esc(c.certificateType) + '</span></td>' +
          '<td>' + esc(c.classKey) + (c.sectionKey ? ' / ' + esc(c.sectionKey) : '') + '</td>' +
          '<td>' + esc(c.issueDate) + '</td>' +
          '<td>' + esc(c.issuedBy) + '</td>' +
          '<td>' + statusBadge + '</td>' +
          '<td>' +
            '<button class="cert-btn cert-btn-sm cert-btn-outline" onclick="CERT.issued.view(\'' + esc(c.id) + '\')" title="View"><i class="fa fa-eye"></i></button> ' +
            '<button class="cert-btn cert-btn-sm cert-btn-outline" onclick="CERT.issued.printById(\'' + esc(c.id) + '\')" title="Print"><i class="fa fa-print"></i></button> ';

        if (CAN_MANAGE && !c.revoked) {
          html += '<button class="cert-btn cert-btn-sm cert-btn-danger" onclick="CERT.issued.revoke(\'' + esc(c.id) + '\')" title="Revoke"><i class="fa fa-ban"></i></button>';
        }
        html += '</td></tr>';
      });
      html += '</tbody></table>';
      wrap.innerHTML = html;
    },

    view: function(certId) {
      document.getElementById('viewCertBody').innerHTML = '<div class="cert-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</div>';
      $('#viewCertModal').addClass('show');

      post('certificates/get_certificate', { certId: certId })
        .done(function(r) {
          if (!r || r.status !== 'success') {
            document.getElementById('viewCertBody').innerHTML = '<div class="cert-empty"><i class="fa fa-exclamation-triangle"></i> ' + esc(r && r.message || 'Error') + '</div>';
            return;
          }

          var d = r.data || {};
          var cert = d.certificate || {};
          var school = d.school || {};
          CERT.issued._viewCert = { cert: cert, school: school };

          var td = cert.templateData || {};
          var title = td.title || cert.certificateType || '';
          var body = esc(td.body || '').replace(/\n/g, '<br>');

          var html = '<div style="text-align:center; margin-bottom:20px;">';
          if (school.logo) html += '<img src="' + esc(school.logo) + '" style="height:60px; margin-bottom:10px;"><br>';
          html += '<strong style="font-size:1.3rem;">' + esc(school.name || '') + '</strong><br>';
          html += '<small style="color:var(--t2,#888);">' + esc(school.address || '') + '</small>';
          html += '</div>';
          html += '<hr style="border-color:var(--border,#eee);">';
          html += '<h3 style="text-align:center; text-transform:uppercase; letter-spacing:2px; margin:20px 0 8px;">' + esc(title) + '</h3>';
          html += '<p style="text-align:center; color:var(--t2,#888); font-size:.9rem;">No: ' + esc(cert.certificateNumber || '') + '</p>';
          html += '<div style="line-height:2; font-size:1.05rem; text-align:justify; margin:20px 0;">' + body + '</div>';
          html += '<div style="display:flex; justify-content:space-between; margin-top:40px; color:var(--t2,#888);">';
          html += '<div>Date: ' + esc(cert.issueDate || '') + '</div>';
          html += '<div style="text-align:center; border-top:1px solid var(--border,#ccc); padding-top:8px; min-width:150px;">Principal</div>';
          html += '</div>';

          document.getElementById('viewCertTitle').textContent = cert.certificateNumber || 'Certificate';
          document.getElementById('viewCertBody').innerHTML = html;
        });
    },

    printById: function(certId) {
      post('certificates/get_certificate', { certId: certId })
        .done(function(r) {
          if (!r || r.status !== 'success') { toast('Error loading certificate.', false); return; }
          var d = r.data || {};
          var cert = d.certificate || {};
          var school = d.school || {};
          var td = cert.templateData || {};
          CERT.printCertificate({
            title: td.title || cert.certificateType || '',
            body: td.body || '',
            issueDate: cert.issueDate || '',
            certNumber: cert.certificateNumber || '',
            schoolName: school.name || '',
            schoolAddress: school.address || '',
            schoolLogo: school.logo || ''
          });
        });
    },

    printCert: function() {
      if (!CERT.issued._viewCert) return;
      var c = CERT.issued._viewCert;
      var td = c.cert.templateData || {};
      CERT.printCertificate({
        title: td.title || c.cert.certificateType || '',
        body: td.body || '',
        issueDate: c.cert.issueDate || '',
        certNumber: c.cert.certificateNumber || '',
        schoolName: (c.school || {}).name || '',
        schoolAddress: (c.school || {}).address || '',
        schoolLogo: (c.school || {}).logo || ''
      });
    },

    revoke: function(certId) {
      if (!confirm('Are you sure you want to revoke this certificate? This cannot be undone.')) return;
      post('certificates/revoke_certificate', { certId: certId })
        .done(function(r) {
          if (r && r.status === 'success') {
            toast('Certificate revoked.', true);
            CERT.issued.load();
          } else {
            toast(r && r.message || 'Revoke failed.', false);
          }
        });
    }
  };

  /* ── Print Helper ────────────────────────────────────────────────── */
  CERT.printCertificate = function(opts) {
    var area = document.getElementById('certPrintArea');
    var html = '<div class="cert-print-header">';
    if (opts.schoolLogo) html += '<img class="cert-print-logo" src="' + esc(opts.schoolLogo) + '">';
    html += '<p class="cert-print-school">' + esc(opts.schoolName || '') + '</p>';
    if (opts.schoolAddress) html += '<p class="cert-print-address">' + esc(opts.schoolAddress) + '</p>';
    html += '</div>';
    html += '<div class="cert-print-title">' + esc(opts.title || 'Certificate') + '</div>';
    html += '<div class="cert-print-number">No: ' + esc(opts.certNumber || '') + '</div>';
    html += '<div class="cert-print-body">' + esc(opts.body || '').replace(/\n/g, '<br>') + '</div>';
    html += '<div class="cert-print-footer">';
    html += '<div class="cert-print-sig"><div class="cert-print-sig-line">Date: ' + esc(opts.issueDate || '') + '</div></div>';
    html += '<div class="cert-print-sig"><div class="cert-print-sig-line">Principal</div></div>';
    html += '<div class="cert-print-sig"><div class="cert-print-sig-line">Registrar</div></div>';
    html += '</div>';
    area.innerHTML = html;

    setTimeout(function() { window.print(); }, 200);
  };

  /* ── INIT ────────────────────────────────────────────────────────── */
  loadClasses(function() {
    loadTabData(ACTIVE_TAB);
  });

});
</script>
