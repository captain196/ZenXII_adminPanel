<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<style>
/* ── LMS Module — Professional Design ─────────────────────────────── */

/* Layout safety — ensure content sits below header and beside sidebar */
.content-wrapper { margin-left:var(--sw,248px) !important; margin-top:var(--hh,58px) !important; min-height:calc(100vh - var(--hh,58px)) !important; }

.lms-wrap { padding: 28px 32px; font-family: var(--font-b, 'Segoe UI', sans-serif); min-height: 80vh; font-size: 14px !important; }

/* Page Header */
.lms-page-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:28px; flex-wrap:wrap; gap:12px; }
.lms-page-header h2 { margin:0; font-size:1.75rem !important; font-weight:700; color:var(--t1,#1a1a1a); display:flex; align-items:center; gap:12px; }
.lms-page-header h2 i { color:var(--gold,#0f766e); font-size:1.5rem; width:44px; height:44px; display:flex; align-items:center; justify-content:center; background:var(--gold-dim,rgba(15,118,110,.1)); border-radius:10px; }
.lms-page-header .lms-page-sub { font-size:1rem; color:var(--t2,#888); font-weight:400; }

/* Tabs — pill style */
.lms-tabs { display:flex; gap:4px; margin-bottom:28px; flex-wrap:wrap; background:var(--bg2,#fff); border-radius:12px; padding:6px; box-shadow:0 2px 8px var(--sh,rgba(0,0,0,.06)); border:1px solid var(--border,#e5e5e5); }
.lms-tab { padding:12px 24px; cursor:pointer; font-weight:600; color:var(--t2,#666); border-radius:8px; transition:all .2s var(--ease,ease); font-size:1.05rem !important; white-space:nowrap; display:flex; align-items:center; gap:9px; text-decoration:none; border:none; }
.lms-tab i { font-size:1.1rem; opacity:.7; }
.lms-tab:hover { color:var(--gold,#0f766e); background:var(--gold-dim,rgba(15,118,110,.06)); }
.lms-tab:hover i { opacity:1; }
.lms-tab.active { color:#fff; background:var(--gold,#0f766e); box-shadow:0 2px 8px rgba(15,118,110,.25); }
.lms-tab.active i { opacity:1; color:#fff; }

/* Panels */
.lms-panel { display:none; animation:lmsFadeIn .25s ease; }
.lms-panel.active { display:block; }
@keyframes lmsFadeIn { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:translateY(0)} }

/* Panel Card — wraps each tab's content */
.lms-card { background:var(--bg2,#fff); border-radius:14px; border:1px solid var(--border,#e5e5e5); box-shadow:0 2px 12px var(--sh,rgba(0,0,0,.06)); overflow:hidden; }
.lms-card-head { display:flex; align-items:center; justify-content:space-between; padding:22px 28px; flex-wrap:wrap; gap:14px; border-bottom:1px solid var(--border,#eee); background:var(--bg3,#f8faf9); }
.lms-card-head h3 { margin:0; font-size:1.35rem !important; font-weight:700; color:var(--t1,#1a1a1a); display:flex; align-items:center; gap:10px; }
.lms-card-head h3 i { color:var(--gold,#0f766e); font-size:1.15rem; width:36px; height:36px; display:flex; align-items:center; justify-content:center; background:var(--gold-dim,rgba(15,118,110,.1)); border-radius:8px; }
.lms-card-body { padding:0; }

/* Filter Bar — inside card */
.lms-filter-bar { display:flex; gap:12px; padding:18px 28px; flex-wrap:wrap; align-items:center; background:var(--bg,#f5f7f6); border-bottom:1px solid var(--border,#eee); }
.lms-filter-bar label { font-size:.85rem !important; font-weight:700 !important; text-transform:uppercase !important; letter-spacing:.4px !important; color:var(--t2,#777) !important; margin-right:-4px; }
.lms-filter-bar select, .lms-filter-bar input { padding:10px 16px; border:1px solid var(--border,#ddd); border-radius:8px; font-size:1rem !important; background:var(--bg2,#fff); color:var(--t1,#333) !important; min-width:160px; transition:border-color .2s,box-shadow .2s; }
.lms-filter-bar select:focus, .lms-filter-bar input:focus { border-color:var(--gold,#0f766e); box-shadow:0 0 0 3px var(--gold-ring,rgba(15,118,110,.18)); outline:none; }

/* Table */
.lms-table-wrap { overflow-x:auto; }
.lms-table { width:100%; border-collapse:collapse; font-size:1.02rem !important; }
.lms-table th { background:var(--bg3,#f0f4f2); color:var(--t1,#333) !important; font-weight:700; text-align:left; padding:14px 20px !important; font-size:.9rem !important; text-transform:uppercase; letter-spacing:.3px; border-bottom:2px solid var(--border,#ddd); white-space:nowrap; }
.lms-table td { padding:16px 20px !important; border-bottom:1px solid var(--border,#eee); font-size:1.02rem !important; color:var(--t1,#333) !important; vertical-align:middle; }
.lms-table tbody tr { transition:background .15s; }
.lms-table tbody tr:hover td { background:var(--gold-dim,rgba(15,118,110,.04)); }
.lms-table tbody tr:last-child td { border-bottom:none; }

/* Legacy toolbar compat (kept for any external usage) */
.lms-toolbar { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; flex-wrap:wrap; gap:12px; }
.lms-toolbar h3 { margin:0; color:var(--t1,#222); font-size:1.35rem !important; }

/* Buttons */
.lms-btn { padding:11px 24px !important; border:none; border-radius:8px !important; font-weight:600; font-size:1.02rem !important; cursor:pointer; transition:all .2s var(--ease,ease); display:inline-flex; align-items:center; gap:8px; text-decoration:none; }
.lms-btn:hover { transform:translateY(-1px); }
.lms-btn-primary { background:var(--gold,#0f766e); color:#fff; box-shadow:0 2px 6px rgba(15,118,110,.2); }
.lms-btn-primary:hover { background:var(--gold2,#0d6b63); box-shadow:0 4px 12px rgba(15,118,110,.3); }
.lms-btn-sm { padding:8px 14px !important; font-size:.95rem !important; border-radius:7px !important; }
.lms-btn-outline { background:transparent; color:var(--gold,#0f766e); border:1.5px solid var(--gold,#0f766e); }
.lms-btn-outline:hover { background:var(--gold-dim,rgba(15,118,110,.08)); }
.lms-btn-danger { background:#dc2626; color:#fff; }
.lms-btn-danger:hover { background:#b91c1c; }
.lms-btn-success { background:#059669; color:#fff; }
.lms-btn-success:hover { background:#047857; }

/* Badge */
.lms-badge { display:inline-block; padding:5px 14px !important; border-radius:20px !important; font-size:.9rem !important; font-weight:600; letter-spacing:.2px; }
.lms-badge-green { background:#d1fae5; color:#065f46; }
.lms-badge-blue  { background:#dbeafe; color:#1e40af; }
.lms-badge-amber { background:#fef3c7; color:#92400e; }
.lms-badge-red   { background:#fee2e2; color:#991b1b; }
.lms-badge-gray  { background:#f3f4f6; color:#4b5563; }

/* Modal — positioning handled globally in header.php; only custom styling here */
.lms-modal { background:var(--bg2,#fff); border-radius:16px; padding:0; width:95%; max-width:720px; max-height:90vh; overflow-y:auto; box-shadow:0 12px 48px rgba(0,0,0,.2); animation:lmsModalIn .2s ease; }
@keyframes lmsModalIn { from{opacity:0;transform:scale(.96) translateY(10px)} to{opacity:1;transform:scale(1) translateY(0)} }
.lms-modal-head { padding:20px 28px; border-bottom:1px solid var(--border,#eee); display:flex; align-items:center; justify-content:space-between; background:var(--bg3,#f8faf9); border-radius:16px 16px 0 0; }
.lms-modal-head h4 { margin:0; font-size:1.25rem !important; font-weight:700; color:var(--t1,#222); }
.lms-modal-close { background:var(--bg,#f0f0f0); border:none; font-size:1.3rem; cursor:pointer; color:var(--t2,#999); width:34px; height:34px; border-radius:8px; display:flex; align-items:center; justify-content:center; transition:background .15s; }
.lms-modal-close:hover { background:#fee2e2; color:#dc2626; }
.lms-modal-body { padding:24px 28px; }
.lms-modal-foot { padding:18px 28px; border-top:1px solid var(--border,#eee); display:flex; justify-content:flex-end; gap:12px; background:var(--bg,#fafafa); border-radius:0 0 16px 16px; }

/* Form */
.lms-fg { margin-bottom:18px; }
.lms-fg label { display:block; font-weight:700; margin-bottom:6px; font-size:.92rem !important; color:var(--t2,#555) !important; text-transform:uppercase !important; letter-spacing:.3px !important; }
.lms-fg input, .lms-fg select, .lms-fg textarea { width:100%; padding:11px 14px; border:1px solid var(--border,#ddd); border-radius:8px; font-size:1.02rem !important; background:var(--bg,#f9f9f9); color:var(--t1,#333) !important; transition:border-color .2s,box-shadow .2s; }
.lms-fg input:focus, .lms-fg select:focus, .lms-fg textarea:focus { border-color:var(--gold,#0f766e); box-shadow:0 0 0 3px var(--gold-ring,rgba(15,118,110,.18)); outline:none; }
.lms-fg textarea { resize:vertical; min-height:80px; }
.lms-row { display:flex; gap:16px; }
.lms-row>.lms-fg { flex:1; }

/* Question builder */
.lms-q-item { background:var(--bg,#f5f5f5); border-radius:10px; padding:18px; margin-bottom:14px; border:1px solid var(--border,#e5e5e5); }
.lms-q-item .lms-q-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
.lms-q-item .lms-q-head strong { color:var(--gold,#0f766e); font-size:1.08rem !important; }
.lms-opt-row { display:flex; gap:10px; align-items:center; margin-bottom:8px; }
.lms-opt-row input[type=radio] { flex-shrink:0; width:18px; height:18px; accent-color:var(--gold,#0f766e); }
.lms-opt-row input[type=text] { flex:1; }

/* Dashboard — Stat Cards */
.lms-dash-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:22px; margin-bottom:32px; }
.lms-dash-card { background:var(--bg2,#fff); border-radius:14px; padding:26px 24px; box-shadow:0 2px 12px var(--sh,rgba(0,0,0,.07)); border:1px solid var(--border,#e8e8e8); transition:transform .2s,box-shadow .2s; display:flex; align-items:flex-start; gap:18px; }
.lms-dash-card:hover { transform:translateY(-3px); box-shadow:0 8px 24px var(--sh,rgba(0,0,0,.1)); }
.lms-dash-card .lms-dc-icon { width:56px; height:56px; border-radius:14px; background:var(--gold-dim,rgba(15,118,110,.1)); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.lms-dash-card .lms-dc-icon i { font-size:1.5rem; color:var(--gold,#0f766e); }
.lms-dash-card .lms-dc-body { flex:1; }
.lms-dash-card .lms-dc-val { font-size:2.4rem !important; font-weight:800; color:var(--t1,#1a1a1a); line-height:1.1; }
.lms-dash-card .lms-dc-label { font-size:1.02rem !important; color:var(--t2,#888); margin-top:4px; font-weight:500; }

/* Dashboard — Upcoming & Pending */
.lms-dash-bottom { display:grid; grid-template-columns:1.2fr .8fr; gap:24px; }
.lms-dash-section-title { font-size:1.2rem !important; font-weight:700; color:var(--t1,#1a1a1a); margin-bottom:14px; display:flex; align-items:center; gap:10px; }
.lms-dash-section-title i { color:var(--gold,#0f766e); font-size:1.1rem; width:32px; height:32px; display:flex; align-items:center; justify-content:center; background:var(--gold-dim,rgba(15,118,110,.1)); border-radius:8px; }
.lms-dash-box { background:var(--bg2,#fff); border-radius:14px; box-shadow:0 2px 10px var(--sh,rgba(0,0,0,.06)); border:1px solid var(--border,#e8e8e8); overflow:hidden; }

/* Upcoming list */
.lms-upcoming { list-style:none; padding:0; margin:0; }
.lms-upcoming li { display:flex; align-items:center; gap:16px; padding:16px 20px; border-bottom:1px solid var(--border,#eee); transition:background .15s; }
.lms-upcoming li:hover { background:var(--gold-dim,rgba(15,118,110,.03)); }
.lms-upcoming li:last-child { border-bottom:none; }
.lms-up-date { background:var(--gold-dim,rgba(15,118,110,.1)); color:var(--gold,#0f766e); padding:10px 14px; border-radius:10px; font-weight:700; text-align:center; min-width:58px; font-size:.95rem; line-height:1.3; }
.lms-up-info { flex:1; }
.lms-up-info .lms-up-title { font-weight:600; color:var(--t1,#222); font-size:1.08rem !important; }
.lms-up-info .lms-up-meta { font-size:.95rem !important; color:var(--t2,#888); margin-top:4px; }

/* Pending */
.lms-dash-pending { padding:36px 28px; text-align:center; }
.lms-dash-pending .lms-dp-val { font-size:3.2rem !important; font-weight:800; color:var(--gold,#0f766e); line-height:1; }
.lms-dash-pending .lms-dp-label { font-size:1.05rem !important; color:var(--t2,#888); margin-top:10px; font-weight:500; }

/* Empty state */
.lms-empty { text-align:center; padding:56px 28px; color:var(--t2,#999); font-size:1.08rem !important; }
.lms-empty i { font-size:3rem; margin-bottom:14px; display:block; opacity:.4; }

/* Responsive */
@media (max-width:1100px) { .lms-dash-grid{grid-template-columns:repeat(2,1fr)} .lms-dash-bottom{grid-template-columns:1fr} }
@media (max-width:768px) {
  .lms-wrap{padding:18px 14px} .lms-row{flex-direction:column;gap:0} .lms-dash-grid{grid-template-columns:1fr 1fr}
  .lms-tabs{gap:2px;padding:4px;border-radius:10px} .lms-tab{padding:10px 16px;font-size:.98rem} .lms-tab i{display:none}
  .lms-page-header h2{font-size:1.35rem} .lms-card-head{padding:16px 18px} .lms-card-head h3{font-size:1.15rem}
  .lms-filter-bar{padding:14px 18px} .lms-filter-bar select{min-width:130px} .lms-table th,.lms-table td{padding:12px 14px}
}
@media (max-width:480px) { .lms-dash-grid{grid-template-columns:1fr} .lms-dash-card{padding:20px 18px} }
</style>

<div class="content-wrapper">
  <section class="content">
    <div class="lms-wrap">
      <!-- ── Page Header ─────────────────────────────────────────────────── -->
      <div class="lms-page-header">
        <h2><i class="fa fa-laptop"></i> Learning Management System <span class="lms-page-sub">|
            <?= htmlspecialchars($session_year) ?></span></h2>
      </div>

      <!-- ── Tab Navigation ──────────────────────────────────────────────── -->
      <?php $at = $active_tab ?? 'dashboard'; ?>
      <div class="lms-tabs">
        <a href="<?= base_url('lms') ?>" class="lms-tab <?= $at === 'dashboard' ? 'active' : '' ?>"
          data-tab="dashboard"><i class="fa fa-dashboard"></i> Dashboard</a>
        <a href="<?= base_url('lms/classes') ?>" class="lms-tab <?= $at === 'classes' ? 'active' : '' ?>"
          data-tab="classes"><i class="fa fa-video-camera"></i> Online Classes</a>
        <a href="<?= base_url('lms/materials') ?>" class="lms-tab <?= $at === 'materials' ? 'active' : '' ?>"
          data-tab="materials"><i class="fa fa-book"></i> Study Materials</a>
        <a href="<?= base_url('lms/assignments') ?>"
          class="lms-tab <?= $at === 'assignments' ? 'active' : '' ?>" data-tab="assignments"><i
            class="fa fa-file-text"></i> Assignments</a>
        <a href="<?= base_url('lms/quizzes') ?>" class="lms-tab <?= $at === 'quizzes' ? 'active' : '' ?>"
          data-tab="quizzes"><i class="fa fa-question-circle"></i> Quizzes</a>
      </div>

      <!-- ═══════════════════════════════════════════════════════════════════
       DASHBOARD
  ═══════════════════════════════════════════════════════════════════ -->
      <div class="lms-panel <?= $at === 'dashboard' ? 'active' : '' ?>" id="panel-dashboard">
        <div class="lms-dash-grid" id="dashCards">
          <div class="lms-dash-card">
            <div class="lms-dc-icon"><i class="fa fa-video-camera"></i></div>
            <div class="lms-dc-body">
              <div class="lms-dc-val" id="dc-classes">-</div>
              <div class="lms-dc-label">Online Classes</div>
            </div>
          </div>
          <div class="lms-dash-card">
            <div class="lms-dc-icon"><i class="fa fa-book"></i></div>
            <div class="lms-dc-body">
              <div class="lms-dc-val" id="dc-materials">-</div>
              <div class="lms-dc-label">Study Materials</div>
            </div>
          </div>
          <div class="lms-dash-card">
            <div class="lms-dc-icon"><i class="fa fa-file-text"></i></div>
            <div class="lms-dc-body">
              <div class="lms-dc-val" id="dc-assignments">-</div>
              <div class="lms-dc-label">Assignments</div>
            </div>
          </div>
          <div class="lms-dash-card">
            <div class="lms-dc-icon"><i class="fa fa-question-circle"></i></div>
            <div class="lms-dc-body">
              <div class="lms-dc-val" id="dc-quizzes">-</div>
              <div class="lms-dc-label">Quizzes</div>
            </div>
          </div>
        </div>

        <div class="lms-dash-bottom">
          <div>
            <div class="lms-dash-section-title"><i class="fa fa-calendar"></i> Upcoming Classes</div>
            <div class="lms-dash-box">
              <ul class="lms-upcoming" id="upcomingList">
                <li class="lms-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</li>
              </ul>
            </div>
          </div>
          <div>
            <div class="lms-dash-section-title"><i class="fa fa-clock-o"></i> Pending Assignments</div>
            <div class="lms-dash-box lms-dash-pending">
              <div class="lms-dp-val" id="dc-pending">-</div>
              <div class="lms-dp-label">assignments due within this week</div>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══════════════════════════════════════════════════════════════════
       ONLINE CLASSES
  ═══════════════════════════════════════════════════════════════════ -->
      <div class="lms-panel <?= $at === 'classes' ? 'active' : '' ?>" id="panel-classes">
        <div class="lms-card">
          <div class="lms-card-head">
            <h3><i class="fa fa-video-camera"></i> Online Classes</h3>
            <button class="lms-btn lms-btn-primary" onclick="LMS.cls.openModal()"><i class="fa fa-plus"></i>
              Schedule Class</button>
          </div>
          <div class="lms-filter-bar">
            <label>Class</label>
            <select id="clsFilterClass" onchange="LMS.cls.render()">
              <option value="">All Classes</option>
            </select>
            <label>Status</label>
            <select id="clsFilterStatus" onchange="LMS.cls.render()">
              <option value="">All Status</option>
              <option value="scheduled">Scheduled</option>
              <option value="live">Live</option>
              <option value="completed">Completed</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>
          <div class="lms-card-body">
            <div class="lms-table-wrap" id="classesTableWrap"></div>
          </div>
        </div>
      </div>

      <!-- ═══════════════════════════════════════════════════════════════════
       STUDY MATERIALS
  ═══════════════════════════════════════════════════════════════════ -->
      <div class="lms-panel <?= $at === 'materials' ? 'active' : '' ?>" id="panel-materials">
        <div class="lms-card">
          <div class="lms-card-head">
            <h3><i class="fa fa-book"></i> Study Materials</h3>
            <button class="lms-btn lms-btn-primary" onclick="LMS.mat.openModal()"><i class="fa fa-plus"></i> Add
              Material</button>
          </div>
          <div class="lms-filter-bar">
            <label>Class</label>
            <select id="matFilterClass" onchange="LMS.mat.render()">
              <option value="">All Classes</option>
            </select>
            <label>Type</label>
            <select id="matFilterType" onchange="LMS.mat.render()">
              <option value="">All Types</option>
              <option value="document">Document</option>
              <option value="video">Video</option>
              <option value="link">Link</option>
              <option value="image">Image</option>
              <option value="presentation">Presentation</option>
            </select>
          </div>
          <div class="lms-card-body">
            <div class="lms-table-wrap" id="materialsTableWrap"></div>
          </div>
        </div>
      </div>

      <!-- ═══════════════════════════════════════════════════════════════════
       ASSIGNMENTS
  ═══════════════════════════════════════════════════════════════════ -->
      <div class="lms-panel <?= $at === 'assignments' ? 'active' : '' ?>" id="panel-assignments">
        <div class="lms-card">
          <div class="lms-card-head">
            <h3><i class="fa fa-file-text"></i> Assignments</h3>
            <button class="lms-btn lms-btn-primary" onclick="LMS.asgn.openModal()"><i class="fa fa-plus"></i>
              Create Assignment</button>
          </div>
          <div class="lms-filter-bar">
            <label>Class</label>
            <select id="asgnFilterClass" onchange="LMS.asgn.render()">
              <option value="">All Classes</option>
            </select>
            <label>Status</label>
            <select id="asgnFilterStatus" onchange="LMS.asgn.render()">
              <option value="">All Status</option>
              <option value="active">Active</option>
              <option value="closed">Closed</option>
            </select>
          </div>
          <div class="lms-card-body">
            <div class="lms-table-wrap" id="assignmentsTableWrap"></div>
          </div>
        </div>
      </div>

      <!-- ═══════════════════════════════════════════════════════════════════
       QUIZZES
  ═══════════════════════════════════════════════════════════════════ -->
      <div class="lms-panel <?= $at === 'quizzes' ? 'active' : '' ?>" id="panel-quizzes">
        <div class="lms-card">
          <div class="lms-card-head">
            <h3><i class="fa fa-question-circle"></i> Quizzes</h3>
            <button class="lms-btn lms-btn-primary" onclick="LMS.quiz.openModal()"><i class="fa fa-plus"></i>
              Create Quiz</button>
          </div>
          <div class="lms-filter-bar">
            <label>Class</label>
            <select id="quizFilterClass" onchange="LMS.quiz.render()">
              <option value="">All Classes</option>
            </select>
            <label>Status</label>
            <select id="quizFilterStatus" onchange="LMS.quiz.render()">
              <option value="">All Status</option>
              <option value="draft">Draft</option>
              <option value="active">Active</option>
              <option value="closed">Closed</option>
            </select>
          </div>
          <div class="lms-card-body">
            <div class="lms-table-wrap" id="quizzesTableWrap"></div>
          </div>
        </div>
      </div>
    </div>
  </section>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     MODALS
═══════════════════════════════════════════════════════════════════ -->

<!-- Class Modal -->
<div class="lms-modal-bg" id="classModal">
  <div class="lms-modal">
    <div class="lms-modal-head">
      <h4 id="classModalTitle">Schedule Online Class</h4><button class="lms-modal-close"
        onclick="LMS.cls.closeModal()">&times;</button>
    </div>
    <div class="lms-modal-body">
      <input type="hidden" id="cls_id">
      <div class="lms-fg"><label>Title *</label><input type="text" id="cls_title"
          placeholder="e.g. Math — Chapter 5 Revision"></div>
      <div class="lms-row">
        <div class="lms-fg"><label>Class *</label><select id="cls_classKey"
            onchange="LMS.populateSections(this,'cls_sectionKey')">
            <option value="">Select Class</option>
          </select></div>
        <div class="lms-fg"><label>Section</label><select id="cls_sectionKey">
            <option value="">All Sections</option>
          </select></div>
        <div class="lms-fg"><label>Subject</label><select id="cls_subject">
            <option value="">Select Subject</option>
          </select></div>
      </div>
      <div class="lms-row">
        <div class="lms-fg"><label>Date *</label><input type="date" id="cls_date"></div>
        <div class="lms-fg"><label>Time</label><input type="time" id="cls_time"></div>
        <div class="lms-fg"><label>Duration (min)</label><input type="number" id="cls_duration" value="60"
            min="10" max="300"></div>
      </div>
      <div class="lms-fg"><label>Meet Link / URL</label><input type="url" id="cls_meetLink"
          placeholder="https://meet.google.com/..."></div>
      <div class="lms-fg"><label>Description</label><textarea id="cls_description" rows="2"></textarea></div>
      <div class="lms-fg"><label>Status</label>
        <select id="cls_status">
          <option value="scheduled">Scheduled</option>
          <option value="live">Live</option>
          <option value="completed">Completed</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>
    </div>
    <div class="lms-modal-foot">
      <button class="lms-btn lms-btn-outline" onclick="LMS.cls.closeModal()">Cancel</button>
      <button class="lms-btn lms-btn-primary" onclick="LMS.cls.save()"><i class="fa fa-save"></i> Save</button>
    </div>
  </div>
</div>

<!-- Material Modal -->
<div class="lms-modal-bg" id="materialModal">
  <div class="lms-modal">
    <div class="lms-modal-head">
      <h4 id="materialModalTitle">Add Study Material</h4><button class="lms-modal-close"
        onclick="LMS.mat.closeModal()">&times;</button>
    </div>
    <div class="lms-modal-body">
      <input type="hidden" id="mat_id">
      <div class="lms-fg"><label>Title *</label><input type="text" id="mat_title"
          placeholder="e.g. Chapter 5 Notes"></div>
      <div class="lms-row">
        <div class="lms-fg"><label>Class *</label><select id="mat_classKey"
            onchange="LMS.populateSections(this,'mat_sectionKey')">
            <option value="">Select Class</option>
          </select></div>
        <div class="lms-fg"><label>Section</label><select id="mat_sectionKey">
            <option value="">All Sections</option>
          </select></div>
        <div class="lms-fg"><label>Subject</label><select id="mat_subject">
            <option value="">Select Subject</option>
          </select></div>
      </div>
      <div class="lms-row">
        <div class="lms-fg"><label>Type</label>
          <select id="mat_type">
            <option value="document">Document</option>
            <option value="video">Video</option>
            <option value="link">Link</option>
            <option value="image">Image</option>
            <option value="presentation">Presentation</option>
          </select>
        </div>
        <div class="lms-fg"><label>URL / File Link</label><input type="url" id="mat_url"
            placeholder="https://..."></div>
      </div>
      <div class="lms-fg"><label>Description</label><textarea id="mat_description" rows="3"></textarea></div>
    </div>
    <div class="lms-modal-foot">
      <button class="lms-btn lms-btn-outline" onclick="LMS.mat.closeModal()">Cancel</button>
      <button class="lms-btn lms-btn-primary" onclick="LMS.mat.save()"><i class="fa fa-save"></i> Save</button>
    </div>
  </div>
</div>

<!-- Assignment Modal -->
<div class="lms-modal-bg" id="assignmentModal">
  <div class="lms-modal">
    <div class="lms-modal-head">
      <h4 id="assignmentModalTitle">Create Assignment</h4><button class="lms-modal-close"
        onclick="LMS.asgn.closeModal()">&times;</button>
    </div>
    <div class="lms-modal-body">
      <input type="hidden" id="asgn_id">
      <div class="lms-fg"><label>Title *</label><input type="text" id="asgn_title"
          placeholder="e.g. Chapter 5 Worksheet"></div>
      <div class="lms-row">
        <div class="lms-fg"><label>Class *</label><select id="asgn_classKey"
            onchange="LMS.populateSections(this,'asgn_sectionKey')">
            <option value="">Select Class</option>
          </select></div>
        <div class="lms-fg"><label>Section</label><select id="asgn_sectionKey">
            <option value="">All Sections</option>
          </select></div>
        <div class="lms-fg"><label>Subject</label><select id="asgn_subject">
            <option value="">Select Subject</option>
          </select></div>
      </div>
      <div class="lms-row">
        <div class="lms-fg"><label>Due Date *</label><input type="date" id="asgn_dueDate"></div>
        <div class="lms-fg"><label>Max Marks</label><input type="number" id="asgn_maxMarks" value="100" min="1">
        </div>
        <div class="lms-fg"><label>Status</label>
          <select id="asgn_status">
            <option value="active">Active</option>
            <option value="closed">Closed</option>
          </select>
        </div>
      </div>
      <div class="lms-fg"><label>Description</label><textarea id="asgn_description" rows="3"></textarea></div>
      <div class="lms-fg"><label>Attachment URL</label><input type="url" id="asgn_attachUrl"
          placeholder="https://..."></div>
    </div>
    <div class="lms-modal-foot">
      <button class="lms-btn lms-btn-outline" onclick="LMS.asgn.closeModal()">Cancel</button>
      <button class="lms-btn lms-btn-primary" onclick="LMS.asgn.save()"><i class="fa fa-save"></i> Save</button>
    </div>
  </div>
</div>

<!-- Submissions Modal -->
<div class="lms-modal-bg" id="submissionsModal">
  <div class="lms-modal" style="max-width:850px;">
    <div class="lms-modal-head">
      <h4 id="submissionsModalTitle">Submissions</h4><button class="lms-modal-close"
        onclick="$('#submissionsModal').removeClass('show')">&times;</button>
    </div>
    <div class="lms-modal-body" id="submissionsBody">
      <div class="lms-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</div>
    </div>
  </div>
</div>

<!-- Quiz Modal -->
<div class="lms-modal-bg" id="quizModal">
  <div class="lms-modal" style="max-width:850px;">
    <div class="lms-modal-head">
      <h4 id="quizModalTitle">Create Quiz</h4><button class="lms-modal-close"
        onclick="LMS.quiz.closeModal()">&times;</button>
    </div>
    <div class="lms-modal-body">
      <input type="hidden" id="quiz_id">
      <div class="lms-fg"><label>Title *</label><input type="text" id="quiz_title"
          placeholder="e.g. Chapter 5 Quick Quiz"></div>
      <div class="lms-row">
        <div class="lms-fg"><label>Class *</label><select id="quiz_classKey"
            onchange="LMS.populateSections(this,'quiz_sectionKey')">
            <option value="">Select Class</option>
          </select></div>
        <div class="lms-fg"><label>Section</label><select id="quiz_sectionKey">
            <option value="">All Sections</option>
          </select></div>
        <div class="lms-fg"><label>Subject</label><select id="quiz_subject">
            <option value="">Select Subject</option>
          </select></div>
      </div>
      <div class="lms-row">
        <div class="lms-fg"><label>Duration (min)</label><input type="number" id="quiz_duration" value="30"
            min="5" max="300"></div>
        <div class="lms-fg"><label>Status</label>
          <select id="quiz_status">
            <option value="draft">Draft</option>
            <option value="active">Active</option>
            <option value="closed">Closed</option>
          </select>
        </div>
      </div>
      <div class="lms-fg"><label>Description</label><textarea id="quiz_description" rows="2"></textarea></div>

      <hr style="margin:16px 0; border-color:var(--border,#eee);">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
        <h4 style="margin:0; color:var(--t1,#222); font-size:1.1rem;">Questions</h4>
        <button class="lms-btn lms-btn-sm lms-btn-outline" onclick="LMS.quiz.addQuestion()"><i
            class="fa fa-plus"></i> Add Question</button>
      </div>
      <div id="quizQuestionsWrap"></div>
    </div>
    <div class="lms-modal-foot">
      <button class="lms-btn lms-btn-outline" onclick="LMS.quiz.closeModal()">Cancel</button>
      <button class="lms-btn lms-btn-primary" onclick="LMS.quiz.save()"><i class="fa fa-save"></i> Save
        Quiz</button>
    </div>
  </div>
</div>

<!-- Quiz Attempts Modal -->
<div class="lms-modal-bg" id="attemptsModal">
  <div class="lms-modal" style="max-width:750px;">
    <div class="lms-modal-head">
      <h4 id="attemptsModalTitle">Quiz Results</h4><button class="lms-modal-close"
        onclick="$('#attemptsModal').removeClass('show')">&times;</button>
    </div>
    <div class="lms-modal-body" id="attemptsBody">
      <div class="lms-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</div>
    </div>
  </div>
</div>

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

    var _classes = []; // session class-sections
    var _subjects = {}; // subject catalog per classNum

    /* ── AJAX helper ─────────────────────────────────────────────────── */
    function post(url, data, isJson) {
      var opts = {
        url: BASE + url,
        type: 'POST',
        dataType: 'json'
      };
      if (isJson) {
        opts.contentType = 'application/json';
        data[CSRF] = CSRF_HASH;
        opts.data = JSON.stringify(data);
      } else {
        if (!data) data = {};
        data[CSRF] = CSRF_HASH;
        opts.data = data;
      }
      return $.ajax(opts).always(function(r) {
        if (r && r.responseJSON) r = r.responseJSON;
        var cookie = document.cookie.match(new RegExp('(?:^|;\\s*)' + CSRF_COOKIE + '=([^;]+)'));
        if (cookie) CSRF_HASH = decodeURIComponent(cookie[1]);
      });
    }

    function get(url) {
      return $.ajax({
        url: BASE + url,
        type: 'GET',
        dataType: 'json'
      });
    }

    function toast(msg, ok) {
      var el = document.createElement('div');
      el.textContent = msg;
      el.style.cssText =
        'position:fixed;top:20px;right:20px;z-index:99999;padding:14px 26px;border-radius:8px;font-weight:600;font-size:1rem;color:#fff;background:' +
        (ok ? '#059669' : '#dc2626') + ';box-shadow:0 4px 16px rgba(0,0,0,.2);transition:opacity .3s;';
      document.body.appendChild(el);
      setTimeout(function() {
        el.style.opacity = '0';
        setTimeout(function() {
          el.remove();
        }, 300);
      }, 3000);
    }

    function esc(s) {
      var d = document.createElement('div');
      d.textContent = s || '';
      return d.innerHTML;
    }

    function escAttr(s) {
      return (s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g,
        '&lt;').replace(/>/g, '&gt;');
    }

    /* ── Active tab — set by server via clean URLs ───────────────────── */
    var ACTIVE_TAB = '<?= $at ?>';

    function loadTabData(tab) {
      if (tab === 'dashboard') LMS.dash.load();
      else if (tab === 'classes') LMS.cls.load();
      else if (tab === 'materials') LMS.mat.load();
      else if (tab === 'assignments') LMS.asgn.load();
      else if (tab === 'quizzes') LMS.quiz.load();
    }

    /* ── Populate class/section selectors ────────────────────────────── */
    function populateClassSelects() {
      var uniqueClasses = {};
      _classes.forEach(function(c) {
        uniqueClasses[c.class_key] = c.class_key;
      });

      var selIds = ['cls_classKey', 'mat_classKey', 'asgn_classKey', 'quiz_classKey',
        'clsFilterClass', 'matFilterClass', 'asgnFilterClass', 'quizFilterClass'
      ];
      selIds.forEach(function(sid) {
        var sel = document.getElementById(sid);
        if (!sel) return;
        var isFilter = sid.indexOf('Filter') > -1;
        var html = '<option value="">' + (isFilter ? 'All Classes' : 'Select Class') + '</option>';
        Object.keys(uniqueClasses).sort().forEach(function(ck) {
          html += '<option value="' + esc(ck) + '">' + esc(ck) + '</option>';
        });
        sel.innerHTML = html;
      });
    }

    /* ── LMS namespace ───────────────────────────────────────────────── */
    window.LMS = {

      populateSections: function(classSelect, sectionSelectId) {
        var ck = classSelect.value;
        var sel = document.getElementById(sectionSelectId);
        if (!sel) return;
        var html = '<option value="">All Sections</option>';
        _classes.forEach(function(c) {
          if (c.class_key === ck) {
            html += '<option value="Section ' + esc(c.section) + '">Section ' + esc(c
              .section) + '</option>';
          }
        });
        sel.innerHTML = html;

        var prefix = sectionSelectId.split('_')[0];
        var subSel = document.getElementById(prefix + '_subject');
        if (subSel && ck) {
          var num = ck.replace(/\D/g, '');
          var subs = _subjects[num] || [];
          var sh = '<option value="">Select Subject</option>';
          subs.forEach(function(s) {
            sh += '<option value="' + esc(s.name) + '">' + esc(s.name) + '</option>';
          });
          subSel.innerHTML = sh;
        }
      },

      /* ─── DASHBOARD ──────────────────────────────────────────────── */
      dash: {
        load: function() {
          get('lms/get_dashboard').done(function(r) {
            if (r.status !== 'success') return;
            document.getElementById('dc-classes').textContent = r.totalClasses || 0;
            document.getElementById('dc-materials').textContent = r.totalMaterials || 0;
            document.getElementById('dc-assignments').textContent = r
              .totalAssignments || 0;
            document.getElementById('dc-quizzes').textContent = r.totalQuizzes || 0;
            document.getElementById('dc-pending').textContent = r.pendingAssignments ||
              0;

            var list = document.getElementById('upcomingList');
            if (!r.upcomingClasses || !r.upcomingClasses.length) {
              list.innerHTML =
                '<li class="lms-empty"><i class="fa fa-calendar-o"></i> No upcoming classes</li>';
              return;
            }
            var html = '';
            r.upcomingClasses.forEach(function(c) {
              var d = (c.date || '').split('-');
              var dateLabel = d.length === 3 ? d[2] + '<br><small>' + (d[1] ||
                '') + '</small>' : c.date;
              html += '<li>' +
                '<div class="lms-up-date">' + dateLabel + '</div>' +
                '<div class="lms-up-info">' +
                '<div class="lms-up-title">' + esc(c.title) + '</div>' +
                '<div class="lms-up-meta">' + esc(c.subject || '') +
                ' &bull; ' + esc(c.classKey || '') + ' &bull; ' + esc(c
                  .time || '') + '</div>' +
                '</div>' +
                (c.meetLink ? '<a href="' + esc(c.meetLink) +
                  '" target="_blank" class="lms-btn lms-btn-sm lms-btn-primary"><i class="fa fa-external-link"></i> Join</a>' :
                  '') +
                '</li>';
            });
            list.innerHTML = html;
          });
        }
      },

      /* ─── ONLINE CLASSES ─────────────────────────────────────────── */
      cls: {
        _data: [],
        _loaded: false,
        load: function() {
          get('lms/get_classes').done(function(r) {
            if (r.status === 'success') {
              LMS.cls._data = r.classes || [];
              LMS.cls._loaded = true;
              LMS.cls.render();
            }
          });
        },
        render: function() {
          var rows = LMS.cls._data;
          var fc = document.getElementById('clsFilterClass').value;
          var fs = document.getElementById('clsFilterStatus').value;
          if (fc) rows = rows.filter(function(r) {
            return r.classKey === fc;
          });
          if (fs) rows = rows.filter(function(r) {
            return r.status === fs;
          });

          if (!rows.length) {
            document.getElementById('classesTableWrap').innerHTML =
              '<div class="lms-empty"><i class="fa fa-video-camera"></i> No online classes found</div>';
            return;
          }
          var html =
            '<table class="lms-table"><thead><tr><th>Title</th><th>Class</th><th>Subject</th><th>Date / Time</th><th>Duration</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
          rows.forEach(function(c) {
            var sBadge = c.status === 'live' ? 'green' : c.status === 'completed' ?
              'blue' : c.status === 'cancelled' ? 'red' : 'amber';
            html += '<tr>' +
              '<td><strong>' + esc(c.title) +
              '</strong><br><small style="color:var(--t2)">' + esc(c.teacherName ||
                '') + '</small></td>' +
              '<td>' + esc(c.classKey || '') + (c.sectionKey ? ' / ' + esc(c
                .sectionKey) : '') + '</td>' +
              '<td>' + esc(c.subject || '-') + '</td>' +
              '<td>' + esc(c.date || '') + ' ' + esc(c.time || '') + '</td>' +
              '<td>' + (c.duration || 60) + ' min</td>' +
              '<td><span class="lms-badge lms-badge-' + sBadge + '">' + esc(c
                .status || 'scheduled') + '</span></td>' +
              '<td>' +
              (c.meetLink ? '<a href="' + esc(c.meetLink) +
                '" target="_blank" class="lms-btn lms-btn-sm lms-btn-success" title="Join"><i class="fa fa-external-link"></i></a> ' :
                '') +
              '<button class="lms-btn lms-btn-sm lms-btn-outline" onclick="LMS.cls.edit(\'' +
              c.id + '\')"><i class="fa fa-pencil"></i></button> ' +
              '<button class="lms-btn lms-btn-sm lms-btn-danger" onclick="LMS.cls.del(\'' +
              c.id + '\')"><i class="fa fa-trash"></i></button>' +
              '</td></tr>';
          });
          html += '</tbody></table>';
          document.getElementById('classesTableWrap').innerHTML = html;
        },
        openModal: function(data) {
          var d = data || {};
          document.getElementById('classModalTitle').textContent = d.id ? 'Edit Online Class' :
            'Schedule Online Class';
          document.getElementById('cls_id').value = d.id || '';
          document.getElementById('cls_title').value = d.title || '';
          document.getElementById('cls_date').value = d.date || '';
          document.getElementById('cls_time').value = d.time || '';
          document.getElementById('cls_duration').value = d.duration || 60;
          document.getElementById('cls_meetLink').value = d.meetLink || '';
          document.getElementById('cls_description').value = d.description || '';
          document.getElementById('cls_status').value = d.status || 'scheduled';
          if (d.classKey) {
            document.getElementById('cls_classKey').value = d.classKey;
            LMS.populateSections(document.getElementById('cls_classKey'), 'cls_sectionKey');
          }
          if (d.sectionKey) document.getElementById('cls_sectionKey').value = d.sectionKey;
          if (d.subject) setTimeout(function() {
            document.getElementById('cls_subject').value = d.subject;
          }, 50);
          document.getElementById('classModal').classList.add('show');
        },
        closeModal: function() {
          document.getElementById('classModal').classList.remove('show');
        },
        edit: function(id) {
          var item = LMS.cls._data.find(function(c) {
            return c.id === id;
          });
          if (item) LMS.cls.openModal(item);
        },
        save: function() {
          var data = {
            id: document.getElementById('cls_id').value,
            title: document.getElementById('cls_title').value,
            classKey: document.getElementById('cls_classKey').value,
            sectionKey: document.getElementById('cls_sectionKey').value,
            subject: document.getElementById('cls_subject').value,
            date: document.getElementById('cls_date').value,
            time: document.getElementById('cls_time').value,
            duration: document.getElementById('cls_duration').value,
            meetLink: document.getElementById('cls_meetLink').value,
            description: document.getElementById('cls_description').value,
            status: document.getElementById('cls_status').value,
          };
          post('lms/save_class', data).done(function(r) {
            if (r.status === 'success') {
              toast('Class saved!', true);
              LMS.cls.closeModal();
              LMS.cls.load();
            } else toast(r.message || 'Error', false);
          }).fail(function(x) {
            toast(x.responseJSON?.message || 'Error', false);
          });
        },
        del: function(id) {
          if (!confirm('Delete this class?')) return;
          post('lms/delete_class', {
            id: id
          }).done(function(r) {
            if (r.status === 'success') {
              toast('Deleted', true);
              LMS.cls.load();
            } else toast(r.message || 'Error', false);
          });
        }
      },

      /* ─── STUDY MATERIALS ────────────────────────────────────────── */
      mat: {
        _data: [],
        _loaded: false,
        load: function() {
          get('lms/get_materials').done(function(r) {
            if (r.status === 'success') {
              LMS.mat._data = r.materials || [];
              LMS.mat._loaded = true;
              LMS.mat.render();
            }
          });
        },
        render: function() {
          var rows = LMS.mat._data;
          var fc = document.getElementById('matFilterClass').value;
          var ft = document.getElementById('matFilterType').value;
          if (fc) rows = rows.filter(function(r) {
            return r.classKey === fc;
          });
          if (ft) rows = rows.filter(function(r) {
            return r.type === ft;
          });

          if (!rows.length) {
            document.getElementById('materialsTableWrap').innerHTML =
              '<div class="lms-empty"><i class="fa fa-book"></i> No study materials found</div>';
            return;
          }
          var typeIcons = {
            document: 'fa-file-pdf-o',
            video: 'fa-play-circle',
            link: 'fa-link',
            image: 'fa-image',
            presentation: 'fa-file-powerpoint-o'
          };
          var html =
            '<table class="lms-table"><thead><tr><th>Title</th><th>Class</th><th>Subject</th><th>Type</th><th>Added By</th><th>Date</th><th>Actions</th></tr></thead><tbody>';
          rows.forEach(function(m) {
            html += '<tr>' +
              '<td><i class="fa ' + (typeIcons[m.type] || 'fa-file') +
              '"></i> <strong>' + esc(m.title) + '</strong></td>' +
              '<td>' + esc(m.classKey || '') + (m.sectionKey ? ' / ' + esc(m
                .sectionKey) : '') + '</td>' +
              '<td>' + esc(m.subject || '-') + '</td>' +
              '<td><span class="lms-badge lms-badge-blue">' + esc(m.type ||
                'document') + '</span></td>' +
              '<td>' + esc(m.teacherName || '') + '</td>' +
              '<td>' + esc((m.createdAt || '').substring(0, 10)) + '</td>' +
              '<td>' +
              (m.url ? '<a href="' + esc(m.url) +
                '" target="_blank" class="lms-btn lms-btn-sm lms-btn-success" title="Open"><i class="fa fa-external-link"></i></a> ' :
                '') +
              '<button class="lms-btn lms-btn-sm lms-btn-outline" onclick="LMS.mat.edit(\'' +
              m.id + '\')"><i class="fa fa-pencil"></i></button> ' +
              '<button class="lms-btn lms-btn-sm lms-btn-danger" onclick="LMS.mat.del(\'' +
              m.id + '\')"><i class="fa fa-trash"></i></button>' +
              '</td></tr>';
          });
          html += '</tbody></table>';
          document.getElementById('materialsTableWrap').innerHTML = html;
        },
        openModal: function(data) {
          var d = data || {};
          document.getElementById('materialModalTitle').textContent = d.id ? 'Edit Material' :
            'Add Study Material';
          document.getElementById('mat_id').value = d.id || '';
          document.getElementById('mat_title').value = d.title || '';
          document.getElementById('mat_type').value = d.type || 'document';
          document.getElementById('mat_url').value = d.url || '';
          document.getElementById('mat_description').value = d.description || '';
          if (d.classKey) {
            document.getElementById('mat_classKey').value = d.classKey;
            LMS.populateSections(document.getElementById('mat_classKey'), 'mat_sectionKey');
          }
          if (d.sectionKey) document.getElementById('mat_sectionKey').value = d.sectionKey;
          if (d.subject) setTimeout(function() {
            document.getElementById('mat_subject').value = d.subject;
          }, 50);
          document.getElementById('materialModal').classList.add('show');
        },
        closeModal: function() {
          document.getElementById('materialModal').classList.remove('show');
        },
        edit: function(id) {
          var item = LMS.mat._data.find(function(m) {
            return m.id === id;
          });
          if (item) LMS.mat.openModal(item);
        },
        save: function() {
          var data = {
            id: document.getElementById('mat_id').value,
            title: document.getElementById('mat_title').value,
            classKey: document.getElementById('mat_classKey').value,
            sectionKey: document.getElementById('mat_sectionKey').value,
            subject: document.getElementById('mat_subject').value,
            type: document.getElementById('mat_type').value,
            url: document.getElementById('mat_url').value,
            description: document.getElementById('mat_description').value,
          };
          post('lms/save_material', data).done(function(r) {
            if (r.status === 'success') {
              toast('Material saved!', true);
              LMS.mat.closeModal();
              LMS.mat.load();
            } else toast(r.message || 'Error', false);
          }).fail(function(x) {
            toast(x.responseJSON?.message || 'Error', false);
          });
        },
        del: function(id) {
          if (!confirm('Delete this material?')) return;
          post('lms/delete_material', {
            id: id
          }).done(function(r) {
            if (r.status === 'success') {
              toast('Deleted', true);
              LMS.mat.load();
            } else toast(r.message || 'Error', false);
          });
        }
      },

      /* ─── ASSIGNMENTS ────────────────────────────────────────────── */
      asgn: {
        _data: [],
        _loaded: false,
        load: function() {
          get('lms/get_assignments').done(function(r) {
            if (r.status === 'success') {
              LMS.asgn._data = r.assignments || [];
              LMS.asgn._loaded = true;
              LMS.asgn.render();
            }
          });
        },
        render: function() {
          var rows = LMS.asgn._data;
          var fc = document.getElementById('asgnFilterClass').value;
          var fs = document.getElementById('asgnFilterStatus').value;
          if (fc) rows = rows.filter(function(r) {
            return r.classKey === fc;
          });
          if (fs) rows = rows.filter(function(r) {
            return r.status === fs;
          });

          if (!rows.length) {
            document.getElementById('assignmentsTableWrap').innerHTML =
              '<div class="lms-empty"><i class="fa fa-file-text"></i> No assignments found</div>';
            return;
          }
          var today = new Date().toISOString().substring(0, 10);
          var html =
            '<table class="lms-table"><thead><tr><th>Title</th><th>Class</th><th>Subject</th><th>Due Date</th><th>Max Marks</th><th>Submissions</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
          rows.forEach(function(a) {
            var isOverdue = a.status === 'active' && a.dueDate < today;
            var sBadge = a.status === 'closed' ? 'gray' : isOverdue ? 'red' : 'green';
            var statusText = a.status === 'closed' ? 'Closed' : isOverdue ? 'Overdue' :
              'Active';
            html += '<tr>' +
              '<td><strong>' + esc(a.title) +
              '</strong><br><small style="color:var(--t2)">' + esc(a.teacherName ||
                '') + '</small></td>' +
              '<td>' + esc(a.classKey || '') + (a.sectionKey ? ' / ' + esc(a
                .sectionKey) : '') + '</td>' +
              '<td>' + esc(a.subject || '-') + '</td>' +
              '<td>' + esc(a.dueDate || '') + '</td>' +
              '<td>' + (a.maxMarks || 100) + '</td>' +
              '<td><button class="lms-btn lms-btn-sm lms-btn-outline" onclick="LMS.asgn.viewSubmissions(\'' +
              a.id + '\')">' +
              '<i class="fa fa-eye"></i> ' + (a.submissionCount || 0) +
              '</button></td>' +
              '<td><span class="lms-badge lms-badge-' + sBadge + '">' + statusText +
              '</span></td>' +
              '<td>' +
              '<button class="lms-btn lms-btn-sm lms-btn-outline" onclick="LMS.asgn.edit(\'' +
              a.id + '\')"><i class="fa fa-pencil"></i></button> ' +
              '<button class="lms-btn lms-btn-sm lms-btn-danger" onclick="LMS.asgn.del(\'' +
              a.id + '\')"><i class="fa fa-trash"></i></button>' +
              '</td></tr>';
          });
          html += '</tbody></table>';
          document.getElementById('assignmentsTableWrap').innerHTML = html;
        },
        openModal: function(data) {
          var d = data || {};
          document.getElementById('assignmentModalTitle').textContent = d.id ? 'Edit Assignment' :
            'Create Assignment';
          document.getElementById('asgn_id').value = d.id || '';
          document.getElementById('asgn_title').value = d.title || '';
          document.getElementById('asgn_dueDate').value = d.dueDate || '';
          document.getElementById('asgn_maxMarks').value = d.maxMarks || 100;
          document.getElementById('asgn_description').value = d.description || '';
          document.getElementById('asgn_attachUrl').value = d.attachUrl || '';
          document.getElementById('asgn_status').value = d.status || 'active';
          if (d.classKey) {
            document.getElementById('asgn_classKey').value = d.classKey;
            LMS.populateSections(document.getElementById('asgn_classKey'), 'asgn_sectionKey');
          }
          if (d.sectionKey) document.getElementById('asgn_sectionKey').value = d.sectionKey;
          if (d.subject) setTimeout(function() {
            document.getElementById('asgn_subject').value = d.subject;
          }, 50);
          document.getElementById('assignmentModal').classList.add('show');
        },
        closeModal: function() {
          document.getElementById('assignmentModal').classList.remove('show');
        },
        edit: function(id) {
          var item = LMS.asgn._data.find(function(a) {
            return a.id === id;
          });
          if (item) LMS.asgn.openModal(item);
        },
        save: function() {
          var data = {
            id: document.getElementById('asgn_id').value,
            title: document.getElementById('asgn_title').value,
            classKey: document.getElementById('asgn_classKey').value,
            sectionKey: document.getElementById('asgn_sectionKey').value,
            subject: document.getElementById('asgn_subject').value,
            dueDate: document.getElementById('asgn_dueDate').value,
            maxMarks: document.getElementById('asgn_maxMarks').value,
            description: document.getElementById('asgn_description').value,
            attachUrl: document.getElementById('asgn_attachUrl').value,
            status: document.getElementById('asgn_status').value,
          };
          post('lms/save_assignment', data).done(function(r) {
            if (r.status === 'success') {
              toast('Assignment saved!', true);
              LMS.asgn.closeModal();
              LMS.asgn.load();
            } else toast(r.message || 'Error', false);
          }).fail(function(x) {
            toast(x.responseJSON?.message || 'Error', false);
          });
        },
        del: function(id) {
          if (!confirm('Delete this assignment and all its submissions?')) return;
          post('lms/delete_assignment', {
            id: id
          }).done(function(r) {
            if (r.status === 'success') {
              toast('Deleted', true);
              LMS.asgn.load();
            } else toast(r.message || 'Error', false);
          });
        },
        viewSubmissions: function(assignmentId) {
          document.getElementById('submissionsBody').innerHTML =
            '<div class="lms-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</div>';
          document.getElementById('submissionsModal').classList.add('show');

          get('lms/get_submissions?assignmentId=' + assignmentId).done(function(r) {
            if (r.status !== 'success') {
              document.getElementById('submissionsBody').innerHTML =
                '<div class="lms-empty">Error loading submissions</div>';
              return;
            }
            var a = r.assignment || {};
            document.getElementById('submissionsModalTitle').textContent =
              'Submissions — ' + (a.title || '');
            var subs = r.submissions || [];
            if (!subs.length) {
              document.getElementById('submissionsBody').innerHTML =
                '<div class="lms-empty"><i class="fa fa-inbox"></i> No submissions yet</div>';
              return;
            }
            var html =
              '<table class="lms-table"><thead><tr><th>Student</th><th>Submitted At</th><th>Status</th><th>Marks</th><th>Actions</th></tr></thead><tbody>';
            subs.forEach(function(s) {
              var sBadge = s.status === 'graded' ? 'green' : 'amber';
              html += '<tr>' +
                '<td>' + esc(s.studentName) + '</td>' +
                '<td>' + esc(s.submittedAt || '') + '</td>' +
                '<td><span class="lms-badge lms-badge-' + sBadge + '">' +
                esc(s.status || 'submitted') + '</span></td>' +
                '<td>' + (s.marks !== undefined && s.marks !== null ? s
                  .marks + '/' + (a.maxMarks || 100) : '-') + '</td>' +
                '<td>' +
                (s.fileUrl ? '<a href="' + esc(s.fileUrl) +
                  '" target="_blank" class="lms-btn lms-btn-sm lms-btn-outline"><i class="fa fa-download"></i></a> ' :
                  '') +
                '<button class="lms-btn lms-btn-sm lms-btn-primary" onclick="LMS.asgn.gradePrompt(\'' +
                assignmentId + '\',\'' + s.studentId + '\',' + (a
                  .maxMarks || 100) +
                ')"><i class="fa fa-check"></i> Grade</button>' +
                '</td></tr>';
            });
            html += '</tbody></table>';
            document.getElementById('submissionsBody').innerHTML = html;
          });
        },
        gradePrompt: function(assignmentId, studentId, maxMarks) {
          var marks = prompt('Enter marks (max ' + maxMarks + '):');
          if (marks === null) return;
          marks = parseFloat(marks);
          if (isNaN(marks) || marks < 0 || marks > maxMarks) {
            toast('Invalid marks', false);
            return;
          }
          var feedback = prompt('Feedback (optional):') || '';
          post('lms/grade_submission', {
              assignmentId: assignmentId,
              studentId: studentId,
              marks: marks,
              feedback: feedback
            })
            .done(function(r) {
              if (r.status === 'success') {
                toast('Graded!', true);
                LMS.asgn.viewSubmissions(assignmentId);
              } else toast(r.message || 'Error', false);
            });
        }
      },

      /* ─── QUIZZES ────────────────────────────────────────────────── */
      quiz: {
        _data: [],
        _loaded: false,
        _questions: [],
        load: function() {
          get('lms/get_quizzes').done(function(r) {
            if (r.status === 'success') {
              LMS.quiz._data = r.quizzes || [];
              LMS.quiz._loaded = true;
              LMS.quiz.render();
            }
          });
        },
        render: function() {
          var rows = LMS.quiz._data;
          var fc = document.getElementById('quizFilterClass').value;
          var fs = document.getElementById('quizFilterStatus').value;
          if (fc) rows = rows.filter(function(r) {
            return r.classKey === fc;
          });
          if (fs) rows = rows.filter(function(r) {
            return r.status === fs;
          });

          if (!rows.length) {
            document.getElementById('quizzesTableWrap').innerHTML =
              '<div class="lms-empty"><i class="fa fa-question-circle"></i> No quizzes found</div>';
            return;
          }
          var html =
            '<table class="lms-table"><thead><tr><th>Title</th><th>Class</th><th>Subject</th><th>Questions</th><th>Duration</th><th>Attempts</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
          rows.forEach(function(q) {
            var sBadge = q.status === 'active' ? 'green' : q.status === 'closed' ?
              'gray' : 'amber';
            var qCount = (q.questions || []).length;
            html += '<tr>' +
              '<td><strong>' + esc(q.title) +
              '</strong><br><small style="color:var(--t2)">' + esc(q.teacherName ||
                '') + '</small></td>' +
              '<td>' + esc(q.classKey || '') + (q.sectionKey ? ' / ' + esc(q
                .sectionKey) : '') + '</td>' +
              '<td>' + esc(q.subject || '-') + '</td>' +
              '<td>' + qCount + ' Q &bull; ' + (q.maxMarks || 0) + ' marks</td>' +
              '<td>' + (q.duration || 30) + ' min</td>' +
              '<td><button class="lms-btn lms-btn-sm lms-btn-outline" onclick="LMS.quiz.viewAttempts(\'' +
              q.id + '\')">' +
              '<i class="fa fa-users"></i> ' + (q.attemptCount || 0) +
              '</button></td>' +
              '<td><span class="lms-badge lms-badge-' + sBadge + '">' + esc(q
                .status || 'draft') + '</span></td>' +
              '<td>' +
              '<button class="lms-btn lms-btn-sm lms-btn-outline" onclick="LMS.quiz.edit(\'' +
              q.id + '\')"><i class="fa fa-pencil"></i></button> ' +
              '<button class="lms-btn lms-btn-sm lms-btn-danger" onclick="LMS.quiz.del(\'' +
              q.id + '\')"><i class="fa fa-trash"></i></button>' +
              '</td></tr>';
          });
          html += '</tbody></table>';
          document.getElementById('quizzesTableWrap').innerHTML = html;
        },
        openModal: function(data) {
          var d = data || {};
          document.getElementById('quizModalTitle').textContent = d.id ? 'Edit Quiz' :
            'Create Quiz';
          document.getElementById('quiz_id').value = d.id || '';
          document.getElementById('quiz_title').value = d.title || '';
          document.getElementById('quiz_duration').value = d.duration || 30;
          document.getElementById('quiz_description').value = d.description || '';
          document.getElementById('quiz_status').value = d.status || 'draft';
          if (d.classKey) {
            document.getElementById('quiz_classKey').value = d.classKey;
            LMS.populateSections(document.getElementById('quiz_classKey'), 'quiz_sectionKey');
          }
          if (d.sectionKey) document.getElementById('quiz_sectionKey').value = d.sectionKey;
          if (d.subject) setTimeout(function() {
            document.getElementById('quiz_subject').value = d.subject;
          }, 50);

          LMS.quiz._questions = (d.questions && d.questions.length) ? JSON.parse(JSON.stringify(d
            .questions)) : [];
          if (!LMS.quiz._questions.length) LMS.quiz.addQuestion();
          LMS.quiz.renderQuestions();
          document.getElementById('quizModal').classList.add('show');
        },
        closeModal: function() {
          document.getElementById('quizModal').classList.remove('show');
        },
        edit: function(id) {
          get('lms/get_quiz?id=' + id).done(function(r) {
            if (r.status === 'success') LMS.quiz.openModal(r.quiz);
            else toast(r.message || 'Error', false);
          });
        },
        addQuestion: function() {
          LMS.quiz._questions.push({
            question: '',
            options: ['', '', '', ''],
            correctIndex: 0,
            marks: 1
          });
          LMS.quiz.renderQuestions();
        },
        removeQuestion: function(idx) {
          LMS.quiz._questions.splice(idx, 1);
          LMS.quiz.renderQuestions();
        },
        renderQuestions: function() {
          var wrap = document.getElementById('quizQuestionsWrap');
          if (!LMS.quiz._questions.length) {
            wrap.innerHTML = '<div class="lms-empty">No questions added</div>';
            return;
          }
          var html = '';
          LMS.quiz._questions.forEach(function(q, qi) {
            html += '<div class="lms-q-item">' +
              '<div class="lms-q-head"><strong>Q' + (qi + 1) + '</strong>' +
              '<div>' +
              '<input type="number" value="' + (q.marks || 1) +
              '" min="0.5" step="0.5" style="width:60px;padding:4px;border:1px solid var(--border);border-radius:4px;" onchange="LMS.quiz._questions[' +
              qi + '].marks=parseFloat(this.value)" title="Marks"> marks ' +
              '<button class="lms-btn lms-btn-sm lms-btn-danger" onclick="LMS.quiz.removeQuestion(' +
              qi + ')"><i class="fa fa-times"></i></button>' +
              '</div></div>' +
              '<div class="lms-fg"><input type="text" value="' + escAttr(q.question) +
              '" placeholder="Enter question..." onchange="LMS.quiz._questions[' +
              qi + '].question=this.value"></div>';

            (q.options || []).forEach(function(opt, oi) {
              html += '<div class="lms-opt-row">' +
                '<input type="radio" name="q' + qi + '_correct" ' + (oi ===
                  q.correctIndex ? 'checked' : '') +
                ' onchange="LMS.quiz._questions[' + qi + '].correctIndex=' +
                oi + '">' +
                '<input type="text" value="' + escAttr(opt) +
                '" placeholder="Option ' + (oi + 1) +
                '" onchange="LMS.quiz._questions[' + qi + '].options[' +
                oi + ']=this.value">' +
                (oi > 1 ?
                  '<button style="background:none;border:none;color:#dc2626;cursor:pointer;" onclick="LMS.quiz._questions[' +
                  qi + '].options.splice(' + oi +
                  ',1);LMS.quiz.renderQuestions()"><i class="fa fa-minus-circle"></i></button>' :
                  '') +
                '</div>';
            });

            html +=
              '<button class="lms-btn lms-btn-sm lms-btn-outline" style="margin-top:4px;" onclick="LMS.quiz._questions[' +
              qi +
              '].options.push(\'\');LMS.quiz.renderQuestions()"><i class="fa fa-plus"></i> Add Option</button>';
            html += '</div>';
          });
          wrap.innerHTML = html;
        },
        save: function() {
          var data = {
            id: document.getElementById('quiz_id').value,
            title: document.getElementById('quiz_title').value,
            classKey: document.getElementById('quiz_classKey').value,
            sectionKey: document.getElementById('quiz_sectionKey').value,
            subject: document.getElementById('quiz_subject').value,
            duration: parseInt(document.getElementById('quiz_duration').value) || 30,
            description: document.getElementById('quiz_description').value,
            status: document.getElementById('quiz_status').value,
            questions: LMS.quiz._questions,
          };
          data[CSRF] = CSRF_HASH;

          $.ajax({
            url: BASE + 'lms/save_quiz',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(data),
            dataType: 'json'
          }).always(function(r) {
            if (r && r.responseJSON) r = r.responseJSON;
            var cookie = document.cookie.match(new RegExp('(?:^|;\\s*)' + CSRF_COOKIE +
              '=([^;]+)'));
            if (cookie) CSRF_HASH = decodeURIComponent(cookie[1]);
          }).done(function(r) {
            if (r.status === 'success') {
              toast('Quiz saved!', true);
              LMS.quiz.closeModal();
              LMS.quiz.load();
            } else toast(r.message || 'Error', false);
          }).fail(function(x) {
            toast(x.responseJSON?.message || 'Error', false);
          });
        },
        del: function(id) {
          if (!confirm('Delete this quiz and all attempts?')) return;
          post('lms/delete_quiz', {
            id: id
          }).done(function(r) {
            if (r.status === 'success') {
              toast('Deleted', true);
              LMS.quiz.load();
            } else toast(r.message || 'Error', false);
          });
        },
        viewAttempts: function(quizId) {
          document.getElementById('attemptsBody').innerHTML =
            '<div class="lms-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</div>';
          document.getElementById('attemptsModal').classList.add('show');

          get('lms/get_quiz_attempts?quizId=' + quizId).done(function(r) {
            if (r.status !== 'success') {
              document.getElementById('attemptsBody').innerHTML =
                '<div class="lms-empty">Error</div>';
              return;
            }
            var atts = r.attempts || [];
            if (!atts.length) {
              document.getElementById('attemptsBody').innerHTML =
                '<div class="lms-empty"><i class="fa fa-users"></i> No attempts yet</div>';
              return;
            }
            var html =
              '<table class="lms-table"><thead><tr><th>#</th><th>Student</th><th>Attempt</th><th>Score</th><th>Started</th><th>Completed</th></tr></thead><tbody>';
            atts.forEach(function(a, i) {
              html += '<tr>' +
                '<td>' + (i + 1) + '</td>' +
                '<td>' + esc(a.studentName || a.studentId) + '</td>' +
                '<td>' + esc(a.attemptId || 'ATT001') + '</td>' +
                '<td><strong>' + (a.score || 0) + '</strong></td>' +
                '<td>' + esc(a.startedAt || '') + '</td>' +
                '<td>' + esc(a.completedAt || '-') + '</td>' +
                '</tr>';
            });
            html += '</tbody></table>';
            document.getElementById('attemptsBody').innerHTML = html;
          });
        }
      }
    };

    /* ── INIT ─────────────────────────────────────────────────────────── */
    get('lms/get_classes_subjects').done(function(r) {
      if (r.status === 'success') {
        _classes = r.classes || [];
        _subjects = r.subjects || {};
        populateClassSelects();
        loadTabData(ACTIVE_TAB);
      }
    }).fail(function() {
      toast('Failed to load class data', false);
    });

  }); // DOMContentLoaded
</script>