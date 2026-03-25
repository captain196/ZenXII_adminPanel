<?php defined('BASEPATH') OR exit('No direct script access allowed');
$activeTab = $active_tab ?? 'pipeline';
?>

<div class="content-wrapper">
<section class="content">
<div class="ats-wrap">

  <!-- ── Header ── -->
  <div class="ats-header">
    <div>
      <div class="ats-header-title"><i class="fa fa-th-list"></i> Applicant Tracking</div>
      <ol class="ats-breadcrumb">
        <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li><a href="<?= base_url('hr') ?>">HR Module</a></li>
        <li>Applicant Tracking</li>
      </ol>
    </div>
    <div class="ats-header-actions">
      <button class="ats-btn ats-btn-primary" onclick="ATS.openApplicantModal()"><i class="fa fa-plus"></i> Add Applicant</button>
    </div>
  </div>

  <!-- ── Tabs ── -->
  <nav class="ats-tabs">
    <a class="ats-tab<?= $activeTab==='pipeline'?' active':'' ?>" href="#" data-tab="pipeline">
      <i class="fa fa-columns"></i> Pipeline
    </a>
    <a class="ats-tab<?= $activeTab==='table'?' active':'' ?>" href="#" data-tab="table">
      <i class="fa fa-table"></i> Table View
    </a>
  </nav>

  <!-- ── Pipeline (Kanban) ── -->
  <div class="ats-panel<?= $activeTab==='pipeline'?' active':'' ?>" id="ats-pipeline">

    <!-- Stats -->
    <div class="ats-stats" id="atsStats">
      <?php foreach(['Applied','Shortlisted','Interviewed','Selected','Hired'] as $s): ?>
      <div class="ats-stat"><div class="ats-stat-val">--</div><div class="ats-stat-label"><?= $s ?></div></div>
      <?php endforeach; ?>
      <div class="ats-stat ats-stat-danger"><div class="ats-stat-val">--</div><div class="ats-stat-label">Rejected</div></div>
    </div>

    <!-- Kanban Board -->
    <div class="ats-board" id="atsPipeline">
      <div class="ats-board-loading">
        <i class="fa fa-spinner fa-spin"></i> Loading pipeline...
      </div>
    </div>
  </div>

  <!-- ── Table View ── -->
  <div class="ats-panel<?= $activeTab==='table'?' active':'' ?>" id="ats-table">
    <div class="ats-card">
      <div class="ats-card-title">
        <span><i class="fa fa-list-alt"></i> All Applicants</span>
        <div class="ats-toolbar">
          <select id="atsFilterStage" class="ats-select">
            <option value="all">All Stages</option>
            <option value="Applied">Applied</option>
            <option value="Shortlisted">Shortlisted</option>
            <option value="Interviewed">Interviewed</option>
            <option value="Selected">Selected</option>
            <option value="Hired">Hired</option>
          </select>
          <select id="atsFilterJob" class="ats-select"><option value="all">All Positions</option></select>
          <select id="atsFilterStatus" class="ats-select">
            <option value="all">All</option>
            <option value="active">Active</option>
            <option value="rejected">Rejected</option>
            <option value="hired">Hired</option>
          </select>
          <button class="ats-btn ats-btn-ghost ats-btn-sm" onclick="ATS.loadPipeline()" title="Refresh"><i class="fa fa-refresh"></i></button>
        </div>
      </div>
      <div class="ats-table-wrap">
        <table class="ats-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Candidate</th>
              <th>Position</th>
              <th>Stage</th>
              <th>Rating</th>
              <th>Applied</th>
              <th>Status</th>
              <th style="text-align:right">Actions</th>
            </tr>
          </thead>
          <tbody id="atsTableBody">
            <tr><td colspan="8" class="ats-empty-cell"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>
</section>
</div>

<!-- ══════════════════════════════════════════════════════════════════
     MODALS
     ══════════════════════════════════════════════════════════════════ -->

<!-- Add/Edit Applicant -->
<div class="ats-modal-bg" id="atsApplicantModal">
  <div class="ats-modal">
    <div class="ats-modal-head">
      <h3 id="atsApplicantModalTitle"><i class="fa fa-user-plus"></i> Add Applicant</h3>
      <button class="ats-modal-close" onclick="ATS.closeModal('atsApplicantModal')" title="Close">&times;</button>
    </div>
    <div class="ats-modal-body">
      <input type="hidden" id="atsAppId" value="">
      <div class="ats-fg-row">
        <div class="ats-fg"><label>Full Name <span class="ats-req">*</span></label><input type="text" id="atsAppName" maxlength="100" placeholder="e.g. Radhika Sharma"></div>
        <div class="ats-fg"><label>Job Position <span class="ats-req">*</span></label><select id="atsAppJobId"><option value="">Loading...</option></select></div>
      </div>
      <div class="ats-fg-row">
        <div class="ats-fg"><label>Email</label><input type="email" id="atsAppEmail" placeholder="radhika@email.com"></div>
        <div class="ats-fg"><label>Phone</label><input type="tel" id="atsAppPhone" maxlength="15" placeholder="10-digit number"></div>
      </div>
      <div class="ats-fg-row">
        <div class="ats-fg"><label>Qualification</label><input type="text" id="atsAppQualification" placeholder="e.g. B.Ed, M.Sc"></div>
        <div class="ats-fg"><label>Experience</label><input type="text" id="atsAppExperience" placeholder="e.g. 5 years"></div>
      </div>
      <div class="ats-fg"><label>Resume Notes</label><textarea id="atsAppResume" rows="3" placeholder="Key highlights, links, or notes from resume..."></textarea></div>
      <div class="ats-modal-foot">
        <button class="ats-btn ats-btn-ghost" onclick="ATS.closeModal('atsApplicantModal')">Cancel</button>
        <button class="ats-btn ats-btn-primary" id="btnSaveApplicant" onclick="ATS.saveApplicant()"><i class="fa fa-check"></i> Save Applicant</button>
      </div>
    </div>
  </div>
</div>

<!-- Applicant Detail -->
<div class="ats-modal-bg" id="atsDetailModal">
  <div class="ats-modal ats-modal-lg">
    <div class="ats-modal-head">
      <h3 id="atsDetailTitle"><i class="fa fa-user"></i> Applicant Details</h3>
      <button class="ats-modal-close" onclick="ATS.closeModal('atsDetailModal')" title="Close">&times;</button>
    </div>
    <div class="ats-modal-body" id="atsDetailBody">
      <div class="ats-empty-cell"><i class="fa fa-spinner fa-spin"></i> Loading...</div>
    </div>
  </div>
</div>

<!-- Add Review -->
<div class="ats-modal-bg" id="atsReviewModal">
  <div class="ats-modal ats-modal-sm">
    <div class="ats-modal-head">
      <h3><i class="fa fa-comment"></i> Add Review</h3>
      <button class="ats-modal-close" onclick="ATS.closeModal('atsReviewModal')" title="Close">&times;</button>
    </div>
    <div class="ats-modal-body">
      <input type="hidden" id="atsReviewAppId" value="">
      <div class="ats-fg">
        <label>Stage</label>
        <input type="text" id="atsReviewStage" readonly class="ats-input-readonly">
      </div>
      <div class="ats-fg">
        <label>Rating <span class="ats-req">*</span></label>
        <div class="ats-stars" id="atsReviewStars">
          <i class="fa fa-star empty" data-val="1"></i>
          <i class="fa fa-star empty" data-val="2"></i>
          <i class="fa fa-star empty" data-val="3"></i>
          <i class="fa fa-star empty" data-val="4"></i>
          <i class="fa fa-star empty" data-val="5"></i>
        </div>
        <input type="hidden" id="atsReviewRating" value="0">
      </div>
      <div class="ats-fg">
        <label>Comments <span class="ats-req">*</span></label>
        <textarea id="atsReviewComment" rows="4" maxlength="2000" placeholder="Detailed feedback on candidate performance..."></textarea>
      </div>
      <div class="ats-modal-foot">
        <button class="ats-btn ats-btn-ghost" onclick="ATS.closeModal('atsReviewModal')">Cancel</button>
        <button class="ats-btn ats-btn-primary" onclick="ATS.submitReview()"><i class="fa fa-check"></i> Submit Review</button>
      </div>
    </div>
  </div>
</div>

<!-- Reject -->
<div class="ats-modal-bg" id="atsRejectModal">
  <div class="ats-modal ats-modal-sm">
    <div class="ats-modal-head ats-modal-head-danger">
      <h3><i class="fa fa-ban"></i> Reject Applicant</h3>
      <button class="ats-modal-close" onclick="ATS.closeModal('atsRejectModal')" title="Close">&times;</button>
    </div>
    <div class="ats-modal-body">
      <input type="hidden" id="atsRejectAppId" value="">
      <p class="ats-reject-warn"><i class="fa fa-exclamation-triangle"></i> This action will permanently reject the candidate from the pipeline.</p>
      <div class="ats-fg">
        <label>Reason for Rejection</label>
        <textarea id="atsRejectReason" rows="3" maxlength="1000" placeholder="Optional — provide reason for records..."></textarea>
      </div>
      <div class="ats-modal-foot">
        <button class="ats-btn ats-btn-ghost" onclick="ATS.closeModal('atsRejectModal')">Cancel</button>
        <button class="ats-btn ats-btn-danger" onclick="ATS.confirmReject()"><i class="fa fa-times"></i> Confirm Reject</button>
      </div>
    </div>
  </div>
</div>


<!-- ══════════════════════════════════════════════════════════════════
     STYLES
     ══════════════════════════════════════════════════════════════════ -->
<style>
/* ── Layout ────────────────────────────────────────────────────── */
.ats-wrap{max-width:1400px;margin:0 auto;padding:20px 16px 48px}

/* Header */
.ats-header{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:20px}
.ats-header-title{font:700 1.3rem/1 var(--font-b);color:var(--t1);display:flex;align-items:center;gap:8px;margin-bottom:3px}
.ats-header-title i{color:var(--gold);font-size:1.1rem}
.ats-header-actions{display:flex;gap:8px}
.ats-breadcrumb{list-style:none;margin:0;padding:0;display:flex;gap:6px;font:400 12px/1 var(--font-b);color:var(--t3)}
.ats-breadcrumb li+li::before{content:'/';margin-right:6px;color:var(--t4)}
.ats-breadcrumb a{color:var(--gold);text-decoration:none}
.ats-breadcrumb a:hover{text-decoration:underline}

/* Tabs */
.ats-tabs{display:flex;gap:4px;border-bottom:2px solid var(--border);margin-bottom:22px;flex-wrap:wrap}
.ats-tab{display:inline-flex;align-items:center;gap:6px;padding:10px 18px;font:600 13px/1 var(--font-b);color:var(--t2);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .2s;border-radius:6px 6px 0 0}
.ats-tab:hover{color:var(--gold);background:var(--gold-dim)}
.ats-tab.active{color:var(--gold);border-bottom-color:var(--gold);background:var(--gold-dim)}
.ats-tab i{font-size:14px}

/* Panels */
.ats-panel{display:none}
.ats-panel.active{display:block}

/* ── Stats ──────────────────────────────────────────────────────── */
.ats-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:22px}
.ats-stat{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:18px 14px;text-align:center;box-shadow:var(--sh);transition:transform .18s var(--ease);position:relative;overflow:hidden}
.ats-stat:hover{transform:translateY(-2px)}
.ats-stat::after{content:'';position:absolute;bottom:0;left:0;right:0;height:3px;background:var(--gold);border-radius:0 0 10px 10px}
.ats-stat-val{font:800 1.5rem/1 var(--font-m);color:var(--gold);margin-bottom:6px}
.ats-stat-label{font:600 11px/1 var(--font-b);color:var(--t3);text-transform:uppercase;letter-spacing:.5px}
.ats-stat-danger .ats-stat-val{color:#E05C6F}
.ats-stat-danger::after{background:#E05C6F}

/* ── Kanban Board ───────────────────────────────────────────────── */
.ats-board{display:flex;gap:10px;padding-bottom:12px;min-height:420px}
.ats-board-loading{width:100%;text-align:center;padding:80px 20px;color:var(--t3);font:500 14px/1 var(--font-b)}
.ats-board-loading i{font-size:24px;display:block;margin-bottom:12px;color:var(--gold)}

.ats-lane{flex:1 1 0;min-width:0;background:var(--bg2);border:1px solid var(--border);border-radius:10px;display:flex;flex-direction:column;max-height:calc(100vh - 280px);box-shadow:var(--sh);overflow:hidden}
.ats-lane-head{padding:14px 16px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:2;border-bottom:1px solid var(--border)}
.ats-lane-title{font:700 12px/1 var(--font-b);color:var(--t1);text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:7px}
.ats-lane-title i{font-size:13px}
.ats-lane-count{font:700 11px/1 var(--font-m);background:var(--gold-dim);color:var(--gold);padding:3px 9px;border-radius:10px;min-width:24px;text-align:center}
.ats-lane-body{flex:1;overflow-y:auto;padding:10px;scrollbar-width:thin}
.ats-lane-body::-webkit-scrollbar{width:4px}
.ats-lane-body::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px}
.ats-lane-empty{text-align:center;padding:30px 12px;color:var(--t4);font:400 12px/1.4 var(--font-b)}
.ats-lane-empty i{font-size:28px;display:block;margin-bottom:8px;opacity:.35}

/* Candidate card */
.ats-card{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:18px 20px;margin-bottom:16px;box-shadow:var(--sh)}
.ats-card-title{display:flex;align-items:center;justify-content:space-between;gap:10px;font:700 14px/1 var(--font-b);color:var(--t1);margin-bottom:14px}
.ats-card-title i{color:var(--gold)}
.ats-card-title span{display:flex;align-items:center;gap:8px}

.ats-ccard{background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:12px;margin-bottom:8px;cursor:pointer;transition:all .18s var(--ease);border-left:3px solid transparent}
.ats-ccard:hover{border-color:var(--gold);box-shadow:0 3px 12px var(--gold-glow);transform:translateY(-1px)}
.ats-ccard:active{transform:translateY(0)}
.ats-ccard-name{font:600 13px/1.3 var(--font-b);color:var(--t1);margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ats-ccard-pos{font:400 11px/1.2 var(--font-b);color:var(--t3);margin-bottom:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ats-ccard-foot{display:flex;align-items:center;justify-content:space-between}
.ats-ccard-rating{display:flex;gap:1px}
.ats-ccard-rating i{font-size:10px}
.ats-ccard-rating i.filled{color:#d97706}
.ats-ccard-rating i.empty{color:var(--t4)}
.ats-ccard-date{font:400 10px/1 var(--font-m);color:var(--t4)}
.ats-ccard.rejected{opacity:.45;border-left-color:#E05C6F}
.ats-ccard.hired{border-left-color:#15803d}

/* Stage color accents on lane headers */
.ats-lane[data-stage="Applied"] .ats-lane-head{border-top:3px solid #4AB5E3}
.ats-lane[data-stage="Applied"] .ats-lane-count{background:rgba(74,181,227,.12);color:#4AB5E3}
.ats-lane[data-stage="Shortlisted"] .ats-lane-head{border-top:3px solid #d97706}
.ats-lane[data-stage="Shortlisted"] .ats-lane-count{background:rgba(217,119,6,.12);color:#d97706}
.ats-lane[data-stage="Interviewed"] .ats-lane-head{border-top:3px solid #8b5cf6}
.ats-lane[data-stage="Interviewed"] .ats-lane-count{background:rgba(139,92,246,.12);color:#8b5cf6}
.ats-lane[data-stage="Selected"] .ats-lane-head{border-top:3px solid #0f766e}
.ats-lane[data-stage="Selected"] .ats-lane-count{background:rgba(15,118,110,.12);color:#0f766e}
.ats-lane[data-stage="Hired"] .ats-lane-head{border-top:3px solid #15803d}
.ats-lane[data-stage="Hired"] .ats-lane-count{background:rgba(21,128,61,.12);color:#15803d}

/* ── Stage badges ───────────────────────────────────────────────── */
.ats-stage{display:inline-flex;align-items:center;gap:4px;font:700 10px/1 var(--font-b);padding:4px 10px;border-radius:10px;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap}
.ats-stage.Applied{background:rgba(74,181,227,.12);color:#4AB5E3}
.ats-stage.Shortlisted{background:rgba(217,119,6,.12);color:#d97706}
.ats-stage.Interviewed{background:rgba(139,92,246,.12);color:#8b5cf6}
.ats-stage.Selected{background:rgba(15,118,110,.12);color:#0f766e}
.ats-stage.Hired{background:rgba(21,128,61,.12);color:#15803d}
.ats-stage.rejected{background:rgba(224,92,111,.12);color:#E05C6F}

/* ── Table ──────────────────────────────────────────────────────── */
.ats-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.ats-select{padding:7px 10px;background:var(--bg3);border:1px solid var(--border);border-radius:6px;color:var(--t1);font:400 12px/1 var(--font-b);transition:border-color .15s}
.ats-select:focus{border-color:var(--gold);outline:none}
.ats-table-wrap{overflow-x:auto}
.ats-table{width:100%;border-collapse:collapse;font:400 13px/1 var(--font-b)}
.ats-table thead{background:var(--bg3)}
.ats-table th{padding:10px 12px;text-align:left;font:700 11px/1 var(--font-b);color:var(--t3);text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid var(--border);white-space:nowrap}
.ats-table td{padding:10px 12px;border-bottom:1px solid var(--border);color:var(--t1);vertical-align:middle}
.ats-table tbody tr:hover{background:var(--gold-dim)}
.ats-table .ats-num{font:500 12px/1 var(--font-m);color:var(--t3)}
.ats-empty-cell{text-align:center;padding:40px 20px !important;color:var(--t3);font:500 13px/1 var(--font-b)}
.ats-empty-cell i{margin-right:6px}

/* Action buttons in table */
.ats-act{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border:1px solid var(--border);border-radius:6px;background:var(--bg3);color:var(--t2);cursor:pointer;font-size:12px;transition:all .15s;padding:0}
.ats-act:hover{border-color:var(--gold);color:var(--gold);background:var(--gold-dim)}
.ats-act.primary{background:var(--gold);color:#fff;border-color:var(--gold)}
.ats-act.primary:hover{background:var(--gold2)}
.ats-act.success{background:#15803d;color:#fff;border-color:#15803d}
.ats-act.success:hover{background:#166534}
.ats-act.danger{color:#E05C6F;border-color:rgba(224,92,111,.3)}
.ats-act.danger:hover{background:rgba(224,92,111,.1);border-color:#E05C6F}
.ats-act-group{display:flex;gap:4px;justify-content:flex-end}
.ats-act-label{font:600 10px/1 var(--font-b);margin-left:3px;display:none}
@media(min-width:1024px){.ats-act-label{display:inline}}

/* ── Buttons ────────────────────────────────────────────────────── */
.ats-btn{padding:9px 18px;border:none;border-radius:8px;font:600 13px/1 var(--font-b);cursor:pointer;transition:all .18s var(--ease);display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
.ats-btn-primary{background:var(--gold);color:#fff;box-shadow:0 2px 8px var(--gold-glow)}.ats-btn-primary:hover{background:var(--gold2);transform:translateY(-1px)}
.ats-btn-ghost{background:transparent;color:var(--t2);border:1px solid var(--border)}.ats-btn-ghost:hover{background:var(--bg3);border-color:var(--gold);color:var(--gold)}
.ats-btn-success{background:#15803d;color:#fff;box-shadow:0 2px 8px rgba(21,128,61,.25)}.ats-btn-success:hover{background:#166534;transform:translateY(-1px)}
.ats-btn-danger{background:#E05C6F;color:#fff}.ats-btn-danger:hover{background:#cc4455}
.ats-btn-sm{padding:6px 12px;font-size:12px;border-radius:6px}
.ats-btn:disabled{opacity:.5;cursor:not-allowed;transform:none !important}

/* ── Modals ─────────────────────────────────────────────────────── */
.ats-modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(2px);z-index:1050;display:none;align-items:center;justify-content:center;padding:20px}
.ats-modal-bg.show{display:flex}
.ats-modal{background:var(--bg2);border:1px solid var(--border);border-radius:12px;width:560px;max-width:100%;max-height:calc(100vh - 40px);overflow-y:auto;box-shadow:0 16px 48px rgba(0,0,0,.3);animation:atsModalIn .2s ease}
.ats-modal-sm{width:440px}
.ats-modal-lg{width:680px}
@keyframes atsModalIn{from{opacity:0;transform:translateY(12px) scale(.98)}to{opacity:1;transform:translateY(0) scale(1)}}
.ats-modal-head{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border);background:var(--bg3);border-radius:12px 12px 0 0}
.ats-modal-head h3{font:700 15px/1 var(--font-b);color:var(--t1);margin:0;display:flex;align-items:center;gap:8px}
.ats-modal-head h3 i{color:var(--gold);font-size:14px}
.ats-modal-head-danger{background:rgba(224,92,111,.06)}
.ats-modal-head-danger h3 i{color:#E05C6F}
.ats-modal-close{background:var(--bg2);border:1px solid var(--border);color:var(--t3);font-size:16px;cursor:pointer;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;transition:all .15s;line-height:1}
.ats-modal-close:hover{border-color:var(--gold);color:var(--gold);background:var(--gold-dim)}
.ats-modal-body{padding:20px}
.ats-modal-foot{display:flex;gap:8px;justify-content:flex-end;padding-top:16px;margin-top:8px;border-top:1px solid var(--border)}

/* ── Form fields ────────────────────────────────────────────────── */
.ats-fg{margin-bottom:14px}
.ats-fg label{display:block;font:600 11px/1 var(--font-b);color:var(--t2);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px}
.ats-req{color:#E05C6F}
.ats-fg input,.ats-fg textarea,.ats-fg select{width:100%;padding:9px 12px;background:var(--bg3);border:1.5px solid var(--border);border-radius:8px;color:var(--t1);font:400 13px/1.5 var(--font-b);transition:all .15s}
.ats-fg input:focus,.ats-fg textarea:focus,.ats-fg select:focus{border-color:var(--gold);outline:none;box-shadow:0 0 0 3px var(--gold-ring)}
.ats-fg textarea{min-height:80px;resize:vertical}
.ats-fg-row{display:flex;gap:14px}
.ats-fg-row .ats-fg{flex:1}
.ats-input-readonly{background:var(--bg4) !important;cursor:not-allowed}

/* ── Stars input ────────────────────────────────────────────────── */
.ats-stars{display:inline-flex;gap:3px;cursor:pointer;padding:4px 0}
.ats-stars i{font-size:20px;transition:color .1s,transform .1s}
.ats-stars i:hover{transform:scale(1.15)}
.ats-stars i.filled{color:#d97706}
.ats-stars i.empty{color:var(--t4)}

/* ── Review timeline (detail modal) ─────────────────────────────── */
.ats-timeline{position:relative;padding-left:28px;margin-top:4px}
.ats-timeline::before{content:'';position:absolute;left:9px;top:4px;bottom:4px;width:2px;background:var(--border);border-radius:1px}
.ats-tl-item{position:relative;margin-bottom:14px;padding:12px 16px;background:var(--bg3);border-radius:8px;border:1px solid var(--border);transition:border-color .15s}
.ats-tl-item:hover{border-color:var(--gold)}
.ats-tl-item::before{content:'';position:absolute;left:-23px;top:16px;width:10px;height:10px;border-radius:50%;background:var(--gold);border:2px solid var(--bg2);z-index:1}
.ats-tl-item.rejected::before{background:#E05C6F}
.ats-tl-item.hired::before{background:#15803d}
.ats-tl-stage{font:700 11px/1 var(--font-b);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;display:flex;align-items:center;gap:6px}
.ats-tl-comment{font:400 13px/1.5 var(--font-b);color:var(--t1);margin-bottom:6px}
.ats-tl-meta{font:400 11px/1 var(--font-m);color:var(--t3)}

/* ── Convert banner ─────────────────────────────────────────────── */
.ats-convert-banner{background:linear-gradient(135deg,rgba(15,118,110,.08),rgba(21,128,61,.08));border:1.5px solid rgba(15,118,110,.25);border-radius:10px;padding:18px 20px;margin:16px 0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.ats-convert-banner p{font:500 14px/1.4 var(--font-b);color:var(--t1);margin:0;display:flex;align-items:center;gap:8px}
.ats-convert-banner p i{font-size:18px}

/* ── Reject warning ─────────────────────────────────────────────── */
.ats-reject-warn{background:rgba(224,92,111,.08);border:1px solid rgba(224,92,111,.2);border-radius:8px;padding:12px 14px;font:500 13px/1.5 var(--font-b);color:#b91c38;margin-bottom:14px;display:flex;align-items:flex-start;gap:8px}
.ats-reject-warn i{margin-top:2px;flex-shrink:0}

/* ── Detail modal info sections ─────────────────────────────────── */
.ats-detail-header{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:18px;padding-bottom:16px;border-bottom:1px solid var(--border)}
.ats-detail-info{flex:1;min-width:200px}
.ats-detail-info h4{font:700 18px/1.2 var(--font-b);color:var(--t1);margin:0 0 4px}
.ats-detail-info p{font:400 13px/1.3 var(--font-b);color:var(--t3);margin:0}
.ats-detail-right{text-align:right}
.ats-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px;font:400 13px/1.5 var(--font-b);color:var(--t1)}
.ats-detail-grid strong{color:var(--t2);font-weight:600}
.ats-detail-grid .full{grid-column:1/-1}
.ats-detail-actions{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap;padding:12px 0;border-top:1px solid var(--border);border-bottom:1px solid var(--border)}

/* ── Toast ──────────────────────────────────────────────────────── */
.ats-toast{position:fixed;top:20px;right:20px;z-index:9999;padding:12px 20px;border-radius:10px;font:500 13px/1.4 var(--font-b);color:#fff;box-shadow:0 6px 20px rgba(0,0,0,.25);max-width:380px;display:flex;align-items:center;gap:8px;animation:atsToastIn .25s ease}
.ats-toast.success{background:#059669}
.ats-toast.error{background:#dc2626}
@keyframes atsToastIn{from{transform:translateX(20px);opacity:0}to{transform:translateX(0);opacity:1}}

/* ── Responsive ─────────────────────────────────────────────────── */
@media(max-width:900px){
  .ats-tabs{gap:2px}
  .ats-tab{padding:8px 12px;font-size:12px}
  .ats-tab i{display:none}
}
@media(max-width:768px){
  .ats-board{flex-direction:column;gap:10px}
  .ats-lane{flex:none;width:100%;max-height:300px}
  .ats-fg-row{flex-direction:column;gap:0}
  .ats-stats{grid-template-columns:repeat(2,1fr)}
  .ats-header{flex-direction:column}
  .ats-toolbar{flex-direction:column;width:100%}
  .ats-toolbar .ats-select{width:100%}
  .ats-detail-grid{grid-template-columns:1fr}
}
@media(max-width:480px){
  .ats-wrap{padding:12px 8px 36px}
  .ats-stats{grid-template-columns:1fr}
  .ats-lane{flex:none;width:100%;max-height:250px}
}
</style>


<!-- ══════════════════════════════════════════════════════════════════
     JAVASCRIPT
     ══════════════════════════════════════════════════════════════════ -->
<script>
document.addEventListener('DOMContentLoaded', function(){

var BASE = '<?= base_url() ?>';
var CSRF_NAME = '<?= $this->security->get_csrf_token_name() ?>';
var CSRF_HASH = '<?= $this->security->get_csrf_hash() ?>';

var STAGES      = ['Applied','Shortlisted','Interviewed','Selected','Hired'];
var STAGE_ICONS  = {Applied:'fa-inbox',Shortlisted:'fa-filter',Interviewed:'fa-comments',Selected:'fa-check-circle',Hired:'fa-user-plus'};
var STAGE_COLORS = {Applied:'#4AB5E3',Shortlisted:'#d97706',Interviewed:'#8b5cf6',Selected:'#0f766e',Hired:'#15803d'};

var _allApplicants = [];
var _jobs = {};

/* ── CSRF ── */
$(document).ajaxComplete(function(e, xhr){
    try { var r = xhr.responseJSON || JSON.parse(xhr.responseText); if(r && r.csrf_hash) CSRF_HASH = r.csrf_hash; } catch(ex){}
});
function csrfData(extra){ var d={}; d[CSRF_NAME]=CSRF_HASH; if(extra) $.extend(d,extra); return d; }

/* ── AJAX helper ── */
function ajax(url, opts){
    opts = opts || {};
    opts.url = BASE + url;
    opts.dataType = 'json';
    if(opts.type==='POST' && opts.data) opts.data = csrfData(opts.data);
    return $.ajax(opts);
}

/* ── Toast ── */
function toast(msg, ok){
    var cls = ok ? 'success' : 'error';
    var icon = ok ? 'fa-check-circle' : 'fa-exclamation-circle';
    var el = $('<div class="ats-toast '+cls+'"><i class="fa '+icon+'"></i> '+msg+'</div>');
    $('body').append(el);
    setTimeout(function(){ el.fadeOut(300, function(){ el.remove(); }); }, 3500);
}

/* ── Helpers ── */
function esc(s){ return $('<span>').text(s||'').html(); }
function fmtDate(d){
    if(!d) return '-';
    var dt = new Date(d);
    if(isNaN(dt)) return d;
    return dt.toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
}
function starsHtml(rating, size){
    size = size || 11;
    var h='';
    for(var i=1;i<=5;i++) h+='<i class="fa fa-star '+(i<=rating?'filled':'empty')+'" style="font-size:'+size+'px"></i>';
    return h;
}
function normalizeStage(s){
    var map = {Applied:'Applied',Shortlisted:'Shortlisted',Interview:'Interviewed',Interviewed:'Interviewed',Selected:'Selected',Joined:'Hired',Hired:'Hired'};
    return map[s] || 'Applied';
}
function jobTitle(jobId){
    var j = _jobs[jobId];
    return j ? j.title : (jobId || 'Unknown');
}

/* ══════════════════════════════════════════════════════════════════
   ATS NAMESPACE
   ══════════════════════════════════════════════════════════════════ */
window.ATS = {

    /* ── Tab switching ── */
    switchTab: function(tab){
        $('.ats-tab').removeClass('active').filter('[data-tab="'+tab+'"]').addClass('active');
        $('.ats-panel').removeClass('active');
        $('#ats-'+tab).addClass('active');
    },

    /* ── Load pipeline data ── */
    loadPipeline: function(){
        ajax('ats/get_pipeline', {type:'GET'}).done(function(r){
            if(r.status !== 'success') return toast(r.message||'Error loading data', false);
            _jobs = {};
            var rawJobs = r.jobs || {};
            if(Array.isArray(rawJobs)) rawJobs.forEach(function(j){ _jobs[j.id] = j; });
            else for(var k in rawJobs){ if(rawJobs.hasOwnProperty(k)) _jobs[k] = rawJobs[k]; }
            _allApplicants = r.applicants || [];

            /* Stats */
            var sc = r.stage_counts || {};
            var statsHtml = '';
            STAGES.forEach(function(s){
                var icon = STAGE_ICONS[s]||'fa-circle';
                statsHtml += '<div class="ats-stat"><div class="ats-stat-val">'+(sc[s]||0)+'</div><div class="ats-stat-label"><i class="fa '+icon+'" style="color:'+STAGE_COLORS[s]+'"></i> '+s+'</div></div>';
            });
            statsHtml += '<div class="ats-stat ats-stat-danger"><div class="ats-stat-val">'+(r.rejected||0)+'</div><div class="ats-stat-label"><i class="fa fa-ban" style="color:#E05C6F"></i> Rejected</div></div>';
            $('#atsStats').html(statsHtml);

            /* Kanban lanes */
            var laneData = {};
            STAGES.forEach(function(s){ laneData[s] = []; });
            _allApplicants.forEach(function(a){
                var stage = normalizeStage(a.stage || a.status || 'Applied');
                if((a.ats_status||'active') === 'rejected') return;
                if(laneData[stage]) laneData[stage].push(a);
            });

            var boardHtml = '';
            STAGES.forEach(function(s){
                var cards = laneData[s];
                boardHtml += '<div class="ats-lane" data-stage="'+s+'">'
                    + '<div class="ats-lane-head"><span class="ats-lane-title"><i class="fa '+STAGE_ICONS[s]+'" style="color:'+STAGE_COLORS[s]+'"></i> '+s+'</span><span class="ats-lane-count">'+cards.length+'</span></div>'
                    + '<div class="ats-lane-body">';
                if(!cards.length){
                    boardHtml += '<div class="ats-lane-empty"><i class="fa fa-user-o"></i><br>No candidates</div>';
                } else {
                    cards.forEach(function(a){
                        var cls = (a.ats_status==='hired') ? 'hired' : '';
                        boardHtml += '<div class="ats-ccard '+cls+'" onclick="ATS.viewApplicant(\''+esc(a.id)+'\')">'
                            + '<div class="ats-ccard-name">'+esc(a.name)+'</div>'
                            + '<div class="ats-ccard-pos">'+esc(jobTitle(a.job_id))+'</div>'
                            + '<div class="ats-ccard-foot">'
                            + '<span class="ats-ccard-rating">'+starsHtml(a.rating||0)+'</span>'
                            + '<span class="ats-ccard-date">'+fmtDate(a.applied_date)+'</span>'
                            + '</div></div>';
                    });
                }
                boardHtml += '</div></div>';
            });
            $('#atsPipeline').html(boardHtml);

            /* Render table view */
            ATS.renderTable();

            /* Populate job filter */
            var preserved = $('#atsFilterJob').val();
            var jOpts = '<option value="all">All Positions</option>';
            for(var jid in _jobs) jOpts += '<option value="'+esc(jid)+'">'+esc(_jobs[jid].title)+'</option>';
            $('#atsFilterJob').html(jOpts);
            if(preserved) $('#atsFilterJob').val(preserved);

            /* Populate modal job dropdown */
            var jSel = '<option value="">Select position...</option>';
            for(var jid2 in _jobs){
                if(_jobs[jid2].status==='Open' || _jobs[jid2].status==='On_Hold')
                    jSel += '<option value="'+esc(jid2)+'">'+esc(_jobs[jid2].title)+' — '+esc(_jobs[jid2].department)+'</option>';
            }
            $('#atsAppJobId').html(jSel);
        }).fail(function(){ toast('Failed to load pipeline.', false); });
    },

    /* ── Render table ── */
    renderTable: function(){
        var filterStage  = $('#atsFilterStage').val();
        var filterJob    = $('#atsFilterJob').val();
        var filterStatus = $('#atsFilterStatus').val();

        var filtered = _allApplicants.filter(function(a){
            var s  = normalizeStage(a.stage || a.status || 'Applied');
            var as = a.ats_status || 'active';
            if(filterStage !== 'all' && s !== filterStage) return false;
            if(filterJob !== 'all' && a.job_id !== filterJob) return false;
            if(filterStatus !== 'all' && as !== filterStatus) return false;
            return true;
        });

        var rows = '';
        filtered.forEach(function(a, idx){
            var s  = normalizeStage(a.stage || a.status || 'Applied');
            var as = a.ats_status || 'active';
            var statusColor = as==='hired' ? '#15803d' : as==='rejected' ? '#E05C6F' : 'var(--t3)';

            rows += '<tr>'
                + '<td class="ats-num">'+(idx+1)+'</td>'
                + '<td><strong>'+esc(a.name)+'</strong><br><span style="font:400 11px/1 var(--font-m);color:var(--t3)">'+esc(a.email||'')+'</span></td>'
                + '<td>'+esc(jobTitle(a.job_id))+'</td>'
                + '<td><span class="ats-stage '+(as==='rejected'?'rejected':s)+'"><i class="fa '+STAGE_ICONS[as==='rejected'?'Applied':s]+'" style="font-size:9px"></i> '+(as==='rejected'?'Rejected':s)+'</span></td>'
                + '<td>'+starsHtml(a.rating||0, 12)+'</td>'
                + '<td style="white-space:nowrap">'+fmtDate(a.applied_date)+'</td>'
                + '<td style="font:600 10px/1 var(--font-b);text-transform:uppercase;color:'+statusColor+'">'+esc(as)+'</td>'
                + '<td><div class="ats-act-group">'
                + '<button class="ats-act" onclick="ATS.viewApplicant(\''+esc(a.id)+'\')" title="View details"><i class="fa fa-eye"></i></button>';
            if(as === 'active'){
                var si = STAGES.indexOf(s);
                if(si >= 0 && si < STAGES.length - 2){
                    var next = STAGES[si+1];
                    rows += '<button class="ats-act primary" onclick="ATS.advanceStage(\''+esc(a.id)+'\',\''+next+'\')" title="Move to '+next+'"><i class="fa fa-arrow-right"></i></button>';
                }
                if(s === 'Selected'){
                    rows += '<button class="ats-act success" onclick="ATS.convertToStaff(\''+esc(a.id)+'\')" title="Convert to Staff"><i class="fa fa-user-plus"></i></button>';
                }
                rows += '<button class="ats-act danger" onclick="ATS.openRejectModal(\''+esc(a.id)+'\')" title="Reject"><i class="fa fa-times"></i></button>';
            }
            rows += '</div></td></tr>';
        });
        if(!rows) rows = '<tr><td colspan="8" class="ats-empty-cell"><i class="fa fa-inbox"></i> No applicants match the current filters.</td></tr>';
        $('#atsTableBody').html(rows);
    },

    /* ── View applicant detail ── */
    viewApplicant: function(id){
        $('#atsDetailBody').html('<div class="ats-empty-cell"><i class="fa fa-spinner fa-spin"></i> Loading...</div>');
        $('#atsDetailModal').addClass('show');

        ajax('ats/get_applicant?id='+encodeURIComponent(id), {type:'GET'}).done(function(r){
            if(r.status !== 'success'){ ATS.closeModal('atsDetailModal'); return toast(r.message||'Error', false); }
            var a = r.applicant;
            var s  = a.stage || 'Applied';
            var as = a.ats_status || 'active';

            /* Header */
            var h = '<div class="ats-detail-header">'
                + '<div class="ats-detail-info">'
                + '<h4>'+esc(a.name)+'</h4>'
                + '<p>'+esc(a.job_title || jobTitle(a.job_id))+(a.job_department ? ' &mdash; '+esc(a.job_department) : '')+'</p>'
                + '<div style="margin-top:8px"><span class="ats-stage '+s+'"><i class="fa '+STAGE_ICONS[s]+'" style="font-size:9px"></i> '+s+'</span></div>'
                + '</div>'
                + '<div class="ats-detail-right">'
                + '<div style="margin-bottom:6px">'+starsHtml(a.rating||0, 18)+'</div>'
                + '<div style="font:400 11px/1 var(--font-m);color:var(--t3)">Applied '+fmtDate(a.applied_date)+'</div>'
                + '</div></div>';

            /* Info grid */
            h += '<div class="ats-detail-grid">';
            h += '<div><strong>Email</strong><br>'+esc(a.email||'Not provided')+'</div>';
            h += '<div><strong>Phone</strong><br>'+esc(a.phone||'Not provided')+'</div>';
            h += '<div><strong>Qualification</strong><br>'+esc(a.qualification||'Not specified')+'</div>';
            h += '<div><strong>Experience</strong><br>'+esc(a.experience||'Not specified')+'</div>';
            if(a.resume_notes) h += '<div class="full"><strong>Resume Notes</strong><br>'+esc(a.resume_notes)+'</div>';
            if(a.staff_id) h += '<div class="full"><strong>Staff ID</strong><br><span style="font-family:var(--font-m);color:var(--gold)">'+esc(a.staff_id)+'</span></div>';
            h += '</div>';

            /* Convert banner */
            if(s === 'Selected' && as === 'active'){
                h += '<div class="ats-convert-banner">'
                    + '<p><i class="fa fa-check-circle" style="color:#15803d"></i> Candidate is <strong>Selected</strong> and ready to be hired.</p>'
                    + '<button class="ats-btn ats-btn-success" onclick="ATS.convertToStaff(\''+esc(a.id)+'\')"><i class="fa fa-user-plus"></i> Convert to Staff</button>'
                    + '</div>';
            }

            /* Action buttons */
            if(as === 'active' && s !== 'Hired'){
                h += '<div class="ats-detail-actions">';
                var si = STAGES.indexOf(s);
                if(si >= 0 && si < STAGES.length - 2){
                    var next = STAGES[si+1];
                    h += '<button class="ats-btn ats-btn-primary ats-btn-sm" onclick="ATS.advanceStage(\''+esc(a.id)+'\',\''+next+'\')"><i class="fa fa-arrow-right"></i> Move to '+next+'</button>';
                }
                h += '<button class="ats-btn ats-btn-ghost ats-btn-sm" onclick="ATS.openReviewModal(\''+esc(a.id)+'\',\''+esc(s)+'\')"><i class="fa fa-comment"></i> Add Review</button>';
                h += '<button class="ats-btn ats-btn-sm" style="background:rgba(224,92,111,.1);color:#E05C6F;border:1px solid rgba(224,92,111,.2)" onclick="ATS.openRejectModal(\''+esc(a.id)+'\')"><i class="fa fa-ban"></i> Reject</button>';
                h += '</div>';
            }

            /* Review timeline */
            var reviews = a.reviews || [];
            h += '<h5 style="font:700 13px/1 var(--font-b);color:var(--t1);margin:18px 0 12px;display:flex;align-items:center;gap:6px"><i class="fa fa-comments" style="color:var(--gold)"></i> Review History ('+reviews.length+')</h5>';
            if(reviews.length){
                h += '<div class="ats-timeline">';
                reviews.slice().reverse().forEach(function(rv){
                    var cls = rv.action==='rejected' ? 'rejected' : (rv.action==='hired' ? 'hired' : '');
                    h += '<div class="ats-tl-item '+cls+'">';
                    h += '<div class="ats-tl-stage" style="color:'+(STAGE_COLORS[rv.stage]||'var(--t2)')+'">'+esc(rv.stage||'')+(rv.rating ? ' &mdash; '+starsHtml(rv.rating, 11) : '')+'</div>';
                    h += '<div class="ats-tl-comment">'+esc(rv.comment||'')+'</div>';
                    h += '<div class="ats-tl-meta"><i class="fa fa-user-o"></i> '+esc(rv.reviewer||'')+'  &bull;  <i class="fa fa-clock-o"></i> '+fmtDate(rv.timestamp)+'</div>';
                    h += '</div>';
                });
                h += '</div>';
            } else {
                h += '<div style="text-align:center;padding:24px;color:var(--t4);font:400 13px/1.4 var(--font-b)"><i class="fa fa-comments-o" style="font-size:28px;display:block;margin-bottom:8px;opacity:.4"></i>No reviews added yet.<br>Use "Add Review" to provide stage feedback.</div>';
            }

            $('#atsDetailTitle html').html('<i class="fa fa-user"></i> '+esc(a.name));
            $('#atsDetailTitle').html('<i class="fa fa-user" style="color:var(--gold)"></i> '+esc(a.name));
            $('#atsDetailBody').html(h);
        }).fail(function(){ ATS.closeModal('atsDetailModal'); toast('Failed to load details.', false); });
    },

    /* ── Add Applicant Modal ── */
    openApplicantModal: function(){
        $('#atsAppId').val('');
        $('#atsAppName,#atsAppEmail,#atsAppPhone,#atsAppQualification,#atsAppExperience').val('');
        $('#atsAppResume').val('');
        $('#atsAppJobId').val('');
        $('#atsApplicantModalTitle').html('<i class="fa fa-user-plus" style="color:var(--gold)"></i> Add Applicant');
        $('#atsApplicantModal').addClass('show');
    },

    saveApplicant: function(){
        var data = {
            id: $('#atsAppId').val(),
            job_id: $('#atsAppJobId').val(),
            name: $('#atsAppName').val(),
            email: $('#atsAppEmail').val(),
            phone: $('#atsAppPhone').val(),
            qualification: $('#atsAppQualification').val(),
            experience: $('#atsAppExperience').val(),
            resume_notes: $('#atsAppResume').val()
        };
        if(!data.name) return toast('Candidate name is required.', false);
        if(!data.job_id) return toast('Please select a job position.', false);

        var $btn = $('#btnSaveApplicant');
        $btn.prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');

        ajax('ats/save_applicant', {type:'POST', data:data}).done(function(r){
            $btn.prop('disabled',false).html('<i class="fa fa-check"></i> Save Applicant');
            if(r.status === 'success'){
                toast(r.message||'Applicant saved.', true);
                ATS.closeModal('atsApplicantModal');
                ATS.loadPipeline();
            } else toast(r.message||'Error saving applicant.', false);
        }).fail(function(){
            $btn.prop('disabled',false).html('<i class="fa fa-check"></i> Save Applicant');
            toast('Request failed.', false);
        });
    },

    /* ── Stage advance ── */
    advanceStage: function(id, newStage){
        if(!confirm('Move this candidate to '+newStage+'?')) return;
        ajax('ats/move_stage', {type:'POST', data:{id:id, new_stage:newStage}}).done(function(r){
            if(r.status === 'success'){ toast(r.message||'Stage updated.', true); ATS.closeModal('atsDetailModal'); ATS.loadPipeline(); }
            else toast(r.message||'Stage transition failed.', false);
        });
    },

    /* ── Reject ── */
    openRejectModal: function(id){
        $('#atsRejectAppId').val(id);
        $('#atsRejectReason').val('');
        ATS.closeModal('atsDetailModal');
        $('#atsRejectModal').addClass('show');
    },
    confirmReject: function(){
        var id = $('#atsRejectAppId').val();
        var reason = $('#atsRejectReason').val();
        ajax('ats/reject_applicant', {type:'POST', data:{id:id, reason:reason}}).done(function(r){
            if(r.status === 'success'){ toast('Applicant rejected.', true); ATS.closeModal('atsRejectModal'); ATS.loadPipeline(); }
            else toast(r.message||'Error.', false);
        });
    },

    /* ── Reviews ── */
    openReviewModal: function(id, stage){
        $('#atsReviewAppId').val(id);
        $('#atsReviewStage').val(stage);
        $('#atsReviewRating').val(0);
        $('#atsReviewComment').val('');
        $('#atsReviewStars i').removeClass('filled').addClass('empty');
        ATS.closeModal('atsDetailModal');
        $('#atsReviewModal').addClass('show');
    },
    submitReview: function(){
        var data = {
            id: $('#atsReviewAppId').val(),
            rating: parseInt($('#atsReviewRating').val()) || 0,
            comment: $('#atsReviewComment').val()
        };
        if(!data.rating) return toast('Please select a rating (1-5 stars).', false);
        if(!data.comment.trim()) return toast('Review comment is required.', false);

        ajax('ats/add_review', {type:'POST', data:data}).done(function(r){
            if(r.status === 'success'){ toast(r.message||'Review added.', true); ATS.closeModal('atsReviewModal'); ATS.loadPipeline(); }
            else toast(r.message||'Error.', false);
        });
    },

    /* ── Convert to Staff ── */
    convertToStaff: function(id){
        if(!confirm('Convert this candidate to staff?\n\nYou will be redirected to the staff registration form with pre-filled data.')) return;

        ajax('ats/get_convert_data?id='+encodeURIComponent(id), {type:'GET'}).done(function(r){
            if(r.status !== 'success') return toast(r.message||'Error.', false);
            sessionStorage.setItem('ats_prefill', JSON.stringify({application_id: r.application_id, prefill: r.prefill}));
            window.location.href = BASE + 'staff/new_staff';
        }).fail(function(){ toast('Failed to get applicant data.', false); });
    },

    /* ── Modal helpers ── */
    closeModal: function(id){ $('#'+id).removeClass('show'); },

    /* ── Delete ── */
    deleteApplicant: function(id){
        if(!confirm('Delete this applicant permanently? This cannot be undone.')) return;
        ajax('ats/delete_applicant', {type:'POST', data:{id:id}}).done(function(r){
            if(r.status === 'success'){ toast('Applicant deleted.', true); ATS.closeModal('atsDetailModal'); ATS.loadPipeline(); }
            else toast(r.message||'Error.', false);
        });
    }
};

/* ── Event bindings ── */
$('.ats-tab').on('click', function(e){ e.preventDefault(); ATS.switchTab($(this).data('tab')); });
$('#atsFilterStage, #atsFilterJob, #atsFilterStatus').on('change', function(){ ATS.renderTable(); });

/* Star rating */
$('#atsReviewStars').on('click', 'i', function(){
    var val = $(this).data('val');
    $('#atsReviewRating').val(val);
    $('#atsReviewStars i').each(function(){
        $(this).toggleClass('filled', $(this).data('val') <= val).toggleClass('empty', $(this).data('val') > val);
    });
});
$('#atsReviewStars').on('mouseenter', 'i', function(){
    var val = $(this).data('val');
    $('#atsReviewStars i').each(function(){ $(this).toggleClass('filled', $(this).data('val') <= val).toggleClass('empty', $(this).data('val') > val); });
}).on('mouseleave', function(){
    var val = parseInt($('#atsReviewRating').val()) || 0;
    $('#atsReviewStars i').each(function(){ $(this).toggleClass('filled', $(this).data('val') <= val).toggleClass('empty', $(this).data('val') > val); });
});

/* Close modals on backdrop click */
$('.ats-modal-bg').on('click', function(e){ if(e.target === this) $(this).removeClass('show'); });

/* ESC key closes modals */
$(document).on('keydown', function(e){
    if(e.key === 'Escape') $('.ats-modal-bg.show').last().removeClass('show');
});

/* Initial load */
ATS.loadPipeline();

/* Deep-link: auto-open applicant detail if ?applicant=APP0001 in URL */
(function(){
    var params = new URLSearchParams(window.location.search);
    var appId  = params.get('applicant');
    if(appId){
        /* viewApplicant fetches from server independently — just wait for DOM ready */
        setTimeout(function(){ ATS.viewApplicant(appId); }, 600);
    }
})();

});
</script>
