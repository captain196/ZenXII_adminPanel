<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<style>
/* ── Stories Module ─────────────────────────────────────────────── */
.st-wrap{padding:20px;max-width:1440px;margin:0 auto}
.st-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.st-header-icon{font-family:var(--font-b);font-size:1.3rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px}
.st-header-icon i{color:var(--gold);font-size:1.1rem}
.st-breadcrumb{list-style:none;display:flex;gap:6px;font-size:12px;color:var(--t3);padding:0;margin:6px 0 0;font-family:var(--font-b)}
.st-breadcrumb a{color:var(--gold);text-decoration:none}
.st-breadcrumb li+li::before{content:">";margin-right:6px;color:var(--t4)}

/* Tabs */
.st-tabs{display:flex;gap:4px;margin-bottom:24px;border-bottom:1px solid var(--border);overflow-x:auto;-webkit-overflow-scrolling:touch}
.st-tab{padding:10px 16px;font-size:13px;font-weight:600;color:var(--t3);text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap;transition:all var(--ease);font-family:var(--font-b);cursor:pointer;background:none;border-top:none;border-left:none;border-right:none}
.st-tab:hover{color:var(--t1)}
.st-tab.active{color:var(--gold);border-bottom-color:var(--gold)}
.st-tab i{margin-right:6px;font-size:14px}
.st-tab .st-tab-count{font-size:10px;background:var(--gold-dim);color:var(--gold);padding:2px 7px;border-radius:10px;margin-left:6px;font-family:var(--font-m)}
.st-tab.active .st-tab-count{background:var(--gold);color:#fff}

/* Stats */
.st-stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:24px}
.st-stat{background:var(--card,var(--bg2));border:1px solid var(--border);border-radius:var(--r,10px);padding:18px;display:flex;align-items:center;gap:14px;transition:transform .15s,box-shadow .15s}
.st-stat:hover{transform:translateY(-2px);box-shadow:var(--sh)}
.st-stat-icon{width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.st-stat-icon.teal{background:rgba(188,90,60,.12);color:var(--gold)}
.st-stat-icon.blue{background:rgba(59,130,246,.12);color:#3b82f6}
.st-stat-icon.amber{background:rgba(245,158,11,.12);color:#f59e0b}
.st-stat-icon.rose{background:rgba(239,68,68,.12);color:#ef4444}
.st-stat-icon.green{background:rgba(34,197,94,.12);color:#22c55e}
.st-stat-icon.purple{background:rgba(139,92,246,.12);color:#8b5cf6}
.st-stat-icon.gray{background:rgba(156,163,175,.12);color:#9ca3af}
.st-stat-val{font-size:22px;font-weight:700;color:var(--t1);font-family:var(--font-b);line-height:1}
.st-stat-lbl{font-size:11px;color:var(--t3);margin-top:3px;font-family:var(--font-b)}

/* Card */
.st-card{background:var(--card,var(--bg2));border:1px solid var(--border);border-radius:var(--r,10px);padding:20px;margin-bottom:18px}
.st-card-title{font-family:var(--font-b);font-size:14px;font-weight:700;color:var(--t1);margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}

/* Filter bar */
.st-filter-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:20px;padding:14px 16px;background:var(--bg3,var(--bg));border:1px solid var(--border);border-radius:var(--r-sm,8px)}
.st-filter-bar select,.st-filter-bar input{padding:7px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg2);color:var(--t1);font-size:12px;font-family:var(--font-b);min-width:130px}
.st-filter-bar select:focus,.st-filter-bar input:focus{border-color:var(--gold);outline:none;box-shadow:0 0 0 2px var(--gold-ring)}
.st-filter-bar label{font-size:10px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.3px;font-family:var(--font-b);margin-right:-4px}

/* Buttons */
.st-btn{padding:7px 14px;border-radius:var(--r-sm,6px);font-size:12px;font-weight:600;cursor:pointer;border:none;transition:all var(--ease);font-family:var(--font-b);display:inline-flex;align-items:center;gap:5px}
.st-btn-primary{background:var(--gold);color:#fff}
.st-btn-primary:hover{background:var(--gold2)}
.st-btn-danger{background:#ef4444;color:#fff}
.st-btn-danger:hover{background:#dc2626}
.st-btn-success{background:#22c55e;color:#fff}
.st-btn-success:hover{background:#16a34a}
.st-btn-outline{background:transparent;border:1px solid var(--gold);color:var(--gold)}
.st-btn-outline:hover{background:var(--gold-dim)}
.st-btn-sm{padding:5px 10px;font-size:11px}
.st-btn-amber{background:#f59e0b;color:#fff}
.st-btn-amber:hover{background:#d97706}
.st-btn-gray{background:var(--bg3);color:var(--t2);border:1px solid var(--border)}
.st-btn-gray:hover{background:var(--bg4);color:var(--t1)}

/* Badge */
.st-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;font-family:var(--font-b)}
.st-badge-green{background:rgba(34,197,94,.12);color:#22c55e}
.st-badge-blue{background:rgba(59,130,246,.12);color:#3b82f6}
.st-badge-amber{background:rgba(245,158,11,.12);color:#f59e0b}
.st-badge-rose{background:rgba(239,68,68,.12);color:#ef4444}
.st-badge-purple{background:rgba(139,92,246,.12);color:#8b5cf6}
.st-badge-gray{background:rgba(156,163,175,.12);color:#9ca3af}
.st-badge-teal{background:rgba(188,90,60,.12);color:var(--gold)}

/* Story Grid */
.st-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px}
.st-story-card{background:var(--card,var(--bg2));border:1px solid var(--border);border-radius:var(--r,10px);overflow:hidden;transition:transform .15s,box-shadow .15s;cursor:pointer;position:relative}
.st-story-card:hover{transform:translateY(-3px);box-shadow:var(--sh)}
.st-story-card.selected{border-color:var(--gold);box-shadow:0 0 0 2px var(--gold-ring)}
.st-story-thumb{width:100%;height:180px;background:var(--bg3);display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative}
.st-story-thumb img{width:100%;height:100%;object-fit:cover}
.st-story-thumb video{width:100%;height:100%;object-fit:cover}
.st-story-thumb .st-media-icon{font-size:36px;color:var(--t4);opacity:.5}
.st-story-thumb .st-media-badge{position:absolute;top:8px;right:8px;background:rgba(0,0,0,.6);color:#fff;padding:3px 8px;border-radius:4px;font-size:10px;font-weight:600;font-family:var(--font-m)}
.st-story-thumb .st-play-badge{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:46px;height:46px;border-radius:50%;background:rgba(0,0,0,.45);color:#fff;display:flex;align-items:center;justify-content:center;font-size:18px;padding-left:3px;pointer-events:none}
.st-story-thumb .st-status-dot{position:absolute;top:8px;left:8px;width:10px;height:10px;border-radius:50%;border:2px solid var(--card,var(--bg2))}
.st-status-dot.active{background:#22c55e}
.st-status-dot.expired{background:#9ca3af}
.st-status-dot.flagged{background:#ef4444;animation:st-pulse 1.5s infinite}
.st-status-dot.removed{background:#6b7280}
@keyframes st-pulse{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.4)}50%{box-shadow:0 0 0 6px rgba(239,68,68,0)}}
.st-story-select{position:absolute;top:8px;left:8px;z-index:2;width:20px;height:20px;accent-color:var(--gold);cursor:pointer;display:none}
.st-bulk-mode .st-story-select{display:block}
.st-bulk-mode .st-status-dot{display:none}
.st-story-body{padding:12px 14px}
.st-story-teacher{display:flex;align-items:center;gap:8px;margin-bottom:8px}
.st-story-avatar{width:28px;height:28px;border-radius:50%;object-fit:cover;border:1.5px solid var(--border);flex-shrink:0}
.st-story-tname{font-size:12px;font-weight:600;color:var(--t1);font-family:var(--font-b);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.st-story-caption{font-size:12px;color:var(--t2);line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin-bottom:8px;min-height:34px}
.st-story-audience{display:inline-flex;align-items:center;gap:4px;font-size:10px;color:var(--t2);background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:2px 7px;margin-bottom:8px;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
/* Admin-upload indeterminate "Publishing…" bar (server-side Storage+Firestore phase after bytes reach 100%). */
#asProgressBar.as-indeterminate{width:100%!important;background:repeating-linear-gradient(90deg,#BC5A3C 0,#D4725C 12px,#BC5A3C 24px);background-size:48px 100%;animation:asStripe 1s linear infinite}
#asProgressBar.as-indeterminate ~ *,.as-indeterminate-hide{display:none}
@keyframes asStripe{from{background-position:0 0}to{background-position:48px 0}}
.st-story-meta{display:flex;align-items:center;justify-content:space-between;font-size:10px;color:var(--t3);font-family:var(--font-m)}
.st-story-meta i{margin-right:3px}
.st-story-views{display:flex;align-items:center;gap:3px}

/* Empty state */
.st-empty{text-align:center;padding:48px 20px;color:var(--t3);font-family:var(--font-b)}
.st-empty i{font-size:36px;display:block;margin-bottom:12px;opacity:.4}
.st-empty p{font-size:13px;margin:0}

/* Loading */
.st-loading{text-align:center;padding:40px 20px;color:var(--t3)}
.st-loading i{font-size:24px;margin-bottom:8px;display:block}

/* Panels */
.st-panel{display:none}
.st-panel.active{display:block;animation:stFadeIn .2s ease}
@keyframes stFadeIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}

/* Table */
.st-table{width:100%;border-collapse:collapse;font-size:12px;font-family:var(--font-b)}
.st-table th,.st-table td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--border)}
.st-table th{color:var(--t3);font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.st-table td{color:var(--t1)}
.st-table tr:hover td{background:var(--gold-dim)}

/* Leaderboard */
.st-lb-row{display:flex;align-items:center;gap:12px;padding:10px 14px;border-bottom:1px solid var(--border);transition:background .12s}
.st-lb-row:hover{background:var(--gold-dim)}
.st-lb-row:last-child{border-bottom:none}
.st-lb-rank{width:24px;height:24px;border-radius:50%;background:var(--bg3);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:var(--t2);font-family:var(--font-m);flex-shrink:0}
.st-lb-rank.gold{background:rgba(245,158,11,.15);color:#f59e0b}
.st-lb-rank.silver{background:rgba(156,163,175,.15);color:#9ca3af}
.st-lb-rank.bronze{background:rgba(180,83,9,.15);color:#b45309}
.st-lb-avatar{width:30px;height:30px;border-radius:50%;object-fit:cover;border:1.5px solid var(--border);flex-shrink:0}
.st-lb-info{flex:1;min-width:0}
.st-lb-name{font-size:12px;font-weight:600;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.st-lb-sub{font-size:10px;color:var(--t3);font-family:var(--font-m)}
.st-lb-stat{text-align:right;font-size:11px;font-family:var(--font-m)}
.st-lb-stat strong{color:var(--t1);font-weight:700}
.st-lb-stat span{color:var(--t3);font-size:10px;display:block;margin-top:1px}

/* Charts */
.st-chart-wrap{position:relative;height:260px;margin-top:8px}
.st-chart-row{display:grid;grid-template-columns:1fr 1fr;gap:18px}
@media(max-width:960px){.st-chart-row{grid-template-columns:1fr}}

/* Modal overlay */
.st-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.55);z-index:10000;align-items:center;justify-content:center;padding:20px}
.st-overlay.open{display:flex}
.st-modal{background:var(--bg2);border:1px solid var(--brd2);border-radius:16px;width:95%;max-width:680px;max-height:90vh;overflow-y:auto;box-shadow:0 12px 48px rgba(0,0,0,.3);animation:stModalIn .2s ease}
@keyframes stModalIn{from{opacity:0;transform:scale(.96) translateY(10px)}to{opacity:1;transform:none}}
.st-modal-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--bg3);border-radius:16px 16px 0 0}
.st-modal-head h4{margin:0;font-size:15px;font-weight:700;color:var(--t1);font-family:var(--font-b)}
.st-modal-close{background:var(--bg);border:none;font-size:16px;cursor:pointer;color:var(--t2);width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;transition:background .15s}
.st-modal-close:hover{background:#fee2e2;color:#dc2626}
.st-modal-body{padding:20px}

/* Detail modal specifics */
.st-detail-media{width:100%;max-height:360px;border-radius:var(--r-sm,8px);overflow:hidden;background:var(--bg3);margin-bottom:16px;display:flex;align-items:center;justify-content:center}
.st-detail-media img,.st-detail-media video{max-width:100%;max-height:360px;object-fit:contain}
.st-detail-row{display:flex;gap:20px;flex-wrap:wrap}
.st-detail-col{flex:1;min-width:200px}
.st-detail-field{margin-bottom:12px}
.st-detail-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:var(--t3);margin-bottom:3px;font-family:var(--font-b)}
.st-detail-value{font-size:13px;color:var(--t1);font-family:var(--font-b)}
.st-detail-moderation{margin-top:16px;padding-top:16px;border-top:1px solid var(--border)}
.st-detail-moderation h5{font-size:13px;font-weight:700;color:var(--t1);margin:0 0 10px;font-family:var(--font-b)}
.st-mod-reason{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--t1);font-size:12px;font-family:var(--font-b);resize:vertical;min-height:60px;margin-bottom:10px}
.st-mod-reason:focus{border-color:var(--gold);outline:none}
.st-mod-actions{display:flex;gap:8px;flex-wrap:wrap}

/* Bulk action bar */
.st-bulk-bar{display:none;padding:12px 16px;background:var(--bg3);border:1px solid var(--gold-ring);border-radius:var(--r-sm,8px);margin-bottom:16px;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.st-bulk-bar.visible{display:flex}
.st-bulk-bar .st-bulk-count{font-size:13px;font-weight:600;color:var(--t1);font-family:var(--font-b)}
.st-bulk-bar .st-bulk-actions{display:flex;gap:8px}

/* Toast */
.st-toast{position:fixed;top:20px;right:20px;z-index:10001;padding:12px 20px;border-radius:8px;font-size:13px;font-weight:600;font-family:var(--font-b);color:#fff;display:none;max-width:400px;box-shadow:0 8px 24px rgba(0,0,0,.15)}
.st-toast.success{background:#22c55e;display:block}
.st-toast.error{background:#ef4444;display:block}

/* Responsive */
@media(max-width:768px){
    .st-grid{grid-template-columns:repeat(auto-fill,minmax(200px,1fr))}
    .st-stat-grid{grid-template-columns:repeat(auto-fit,minmax(140px,1fr))}
    .st-filter-bar{flex-direction:column;align-items:stretch}
    .st-detail-row{flex-direction:column}
    .st-chart-row{grid-template-columns:1fr}
}
</style>

<div class="content-wrapper"><section class="content"><div class="st-wrap">

<!-- Header -->
<div class="st-header"><div>
    <div class="st-header-icon"><i class="fa fa-camera-retro"></i> Stories Management</div>
    <ol class="st-breadcrumb"><li><a href="<?= base_url('admin') ?>">Dashboard</a></li><li>Stories</li></ol>
</div>
<div style="display:flex;gap:8px">
    <button class="st-btn st-btn-outline" onclick="ST.toggleBulk()" id="bulkToggleBtn"><i class="fa fa-check-square-o"></i> Bulk Select</button>
    <button class="st-btn st-btn-primary" onclick="ST.openUpload()"><i class="fa fa-plus-circle"></i> Post Admin Story</button>
    <button class="st-btn st-btn-outline" onclick="ST.refresh()"><i class="fa fa-refresh"></i> Refresh</button>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     Admin Upload Modal — Phase C
     Posts stories with authorType='admin'. Priority=high pins to
     top of every parent's Stories row with a red/gold ring.
     ══════════════════════════════════════════════════════════════ -->
<div id="adminStoryModal" style="display:none;position:fixed;inset:0;background:rgba(11,31,58,.55);z-index:9000;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;border-radius:14px;max-width:560px;width:100%;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.25);">
        <div style="background:linear-gradient(135deg,#0f1f3a,#1a3358);color:#fff;padding:16px 20px;border-radius:14px 14px 0 0;display:flex;align-items:center;justify-content:space-between;">
            <h4 style="font-family:'Playfair Display',serif;font-size:17px;margin:0;">
                <i class="fa fa-bullhorn" style="color:#ffd479;"></i>&nbsp; Post Admin Story
            </h4>
            <button type="button" onclick="ST.closeUpload()" style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);color:#fff;width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:14px;">&times;</button>
        </div>
        <form id="adminStoryForm" style="padding:18px 20px;" onsubmit="return false;">
            <div style="margin-bottom:14px;">
                <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#475569;display:block;margin-bottom:5px;">
                    Media <span style="color:#dc2626;">*</span>
                </label>
                <input type="file" id="asMedia" accept="image/*,video/*" required
                       style="width:100%;padding:9px;border:1.5px dashed #cbd5e1;border-radius:8px;background:#f8fafc;cursor:pointer;">
                <div style="font-size:11px;color:#64748b;margin-top:4px;">
                    Image ≤ 10 MB · Video ≤ 50 MB
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                <div>
                    <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#475569;display:block;margin-bottom:5px;">
                        Type <span style="color:#dc2626;">*</span>
                    </label>
                    <select id="asType" required style="width:100%;padding:10px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:14px;">
                        <option value="image">Image</option>
                        <option value="video">Video</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#475569;display:block;margin-bottom:5px;">
                        Priority <span style="color:#dc2626;">*</span>
                    </label>
                    <select id="asPriority" required style="width:100%;padding:10px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:14px;">
                        <option value="normal">Normal</option>
                        <option value="high">High (pin to top)</option>
                    </select>
                </div>
            </div>

            <div style="margin-bottom:14px;">
                <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#475569;display:block;margin-bottom:5px;">
                    Caption <span style="color:#94a3b8;font-weight:500;">(optional, ≤ 500 chars)</span>
                </label>
                <textarea id="asCaption" maxlength="500" rows="3" placeholder="Brief message for parents…"
                          style="width:100%;padding:10px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:14px;font-family:inherit;resize:vertical;"></textarea>
            </div>

            <!-- Audience -->
            <div style="margin-bottom:14px;">
                <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#475569;display:block;margin-bottom:6px;">
                    Audience <span style="color:#dc2626;">*</span>
                </label>
                <div style="display:flex;gap:16px;margin-bottom:8px;">
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;color:#334155;cursor:pointer;">
                        <input type="radio" name="asAudienceMode" value="whole" checked onchange="ST.onAudienceMode()"> Whole school
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;color:#334155;cursor:pointer;">
                        <input type="radio" name="asAudienceMode" value="specific" onchange="ST.onAudienceMode()"> Specific classes
                    </label>
                </div>
                <div id="asAudienceList" style="display:none;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 10px;max-height:150px;overflow-y:auto;background:#f8fafc;">
                    <div style="font-size:12px;color:#94a3b8;">Loading classes…</div>
                </div>
                <div style="font-size:11px;color:#64748b;margin-top:4px;">
                    Whole-school reaches every parent &amp; teacher. Specific classes reach only the selected sections (and their teachers).
                </div>
            </div>

            <!-- Progress -->
            <div id="asProgressWrap" style="display:none;margin-bottom:14px;">
                <div style="font-size:12px;color:#475569;margin-bottom:5px;">
                    <span id="asProgressLabel">Uploading</span> <span id="asProgressPct">0</span><span class="as-pct-sign">%</span>
                </div>
                <div style="height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;">
                    <div id="asProgressBar" style="height:100%;background:linear-gradient(90deg,#BC5A3C,#D4725C);width:0%;transition:width .2s;"></div>
                </div>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;padding-top:8px;border-top:1px solid #e2e8f0;">
                <button type="button" onclick="ST.closeUpload()"
                        style="padding:10px 20px;border:1.5px solid #cbd5e1;background:#fff;color:#475569;border-radius:8px;font-weight:700;cursor:pointer;">
                    Cancel
                </button>
                <button type="submit" id="asSubmitBtn" onclick="ST.submitUpload()"
                        style="padding:10px 22px;background:linear-gradient(135deg,#BC5A3C,#0d5a55);color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;box-shadow:0 4px 12px rgba(188,90,60,.25);">
                    <i class="fa fa-paper-plane"></i> Publish Story
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Tabs -->
<nav class="st-tabs">
    <button class="st-tab active" data-tab="stories"><i class="fa fa-th-large"></i> All Stories <span class="st-tab-count" id="tabCountAll">--</span></button>
    <button class="st-tab" data-tab="flagged"><i class="fa fa-flag"></i> Flagged <span class="st-tab-count" id="tabCountFlagged">--</span></button>
    <button class="st-tab" data-tab="analytics"><i class="fa fa-line-chart"></i> Analytics</button>
    <button class="st-tab" data-tab="moderation"><i class="fa fa-shield"></i> Moderation Log</button>
</nav>

<!-- Stats Row -->
<div class="st-stat-grid" id="stStats">
    <div class="st-stat">
        <div class="st-stat-icon teal"><i class="fa fa-camera-retro"></i></div>
        <div><div class="st-stat-val" id="statTotal">--</div><div class="st-stat-lbl">Total Stories</div></div>
    </div>
    <div class="st-stat">
        <div class="st-stat-icon green"><i class="fa fa-check-circle"></i></div>
        <div><div class="st-stat-val" id="statActive">--</div><div class="st-stat-lbl">Active</div></div>
    </div>
    <div class="st-stat">
        <div class="st-stat-icon gray"><i class="fa fa-clock-o"></i></div>
        <div><div class="st-stat-val" id="statExpired">--</div><div class="st-stat-lbl">Expired</div></div>
    </div>
    <div class="st-stat">
        <div class="st-stat-icon blue"><i class="fa fa-eye"></i></div>
        <div><div class="st-stat-val" id="statViews">--</div><div class="st-stat-lbl">Total Views</div></div>
    </div>
    <div class="st-stat">
        <div class="st-stat-icon rose"><i class="fa fa-flag"></i></div>
        <div><div class="st-stat-val" id="statFlagged">--</div><div class="st-stat-lbl">Flagged</div></div>
    </div>
    <div class="st-stat">
        <div class="st-stat-icon purple"><i class="fa fa-users"></i></div>
        <div><div class="st-stat-val" id="statTeachers">--</div><div class="st-stat-lbl">Teachers Posting</div></div>
    </div>
</div>

<!-- ════════════ TAB PANELS ════════════ -->

<!-- Panel: All Stories -->
<div class="st-panel active" id="panel-stories">
    <!-- Filter bar -->
    <div class="st-filter-bar">
        <label>Teacher</label>
        <select id="filterTeacher"><option value="">All Teachers</option></select>
        <label>Status</label>
        <select id="filterStatus">
            <option value="">All</option>
            <option value="active">Active</option>
            <option value="expired">Expired</option>
            <option value="flagged">Flagged</option>
            <option value="removed">Removed</option>
        </select>
        <label>Media</label>
        <select id="filterMedia">
            <option value="">All</option>
            <option value="image">Image</option>
            <option value="video">Video</option>
        </select>
        <label>From</label>
        <input type="date" id="filterDateFrom">
        <label>To</label>
        <input type="date" id="filterDateTo">
        <label>Search</label>
        <input type="text" id="filterSearch" placeholder="Teacher or caption..." style="min-width:160px">
        <button class="st-btn st-btn-primary st-btn-sm" onclick="ST.loadStories()"><i class="fa fa-search"></i> Apply</button>
        <button class="st-btn st-btn-gray st-btn-sm" onclick="ST.clearFilters()"><i class="fa fa-times"></i> Clear</button>
    </div>

    <!-- Bulk action bar -->
    <div class="st-bulk-bar" id="bulkBar">
        <div class="st-bulk-count"><span id="bulkCount">0</span> story(ies) selected</div>
        <div class="st-bulk-actions">
            <button class="st-btn st-btn-amber st-btn-sm" onclick="ST.bulkAction('flagged')"><i class="fa fa-flag"></i> Flag Selected</button>
            <button class="st-btn st-btn-danger st-btn-sm" onclick="ST.bulkAction('removed')"><i class="fa fa-ban"></i> Remove Selected</button>
        </div>
    </div>

    <!-- Story grid -->
    <div class="st-grid" id="storyGrid">
        <div class="st-loading"><i class="fa fa-spinner fa-spin"></i><br>Loading stories...</div>
    </div>
</div>

<!-- Panel: Flagged -->
<div class="st-panel" id="panel-flagged">
    <div class="st-card">
        <div class="st-card-title"><span><i class="fa fa-flag" style="color:#ef4444;margin-right:6px"></i> Flagged Stories Queue</span></div>
        <div id="flaggedList">
            <div class="st-loading"><i class="fa fa-spinner fa-spin"></i><br>Loading...</div>
        </div>
    </div>
</div>

<!-- Panel: Analytics -->
<div class="st-panel" id="panel-analytics">
    <div class="st-chart-row">
        <div class="st-card">
            <div class="st-card-title"><span><i class="fa fa-bar-chart" style="color:var(--gold);margin-right:6px"></i> Stories Per Day (Last 30 Days)</span></div>
            <div class="st-chart-wrap"><canvas id="chartDaily"></canvas></div>
        </div>
        <div class="st-card">
            <div class="st-card-title"><span><i class="fa fa-pie-chart" style="color:var(--gold);margin-right:6px"></i> View Distribution</span></div>
            <div class="st-chart-wrap"><canvas id="chartViewDist"></canvas></div>
        </div>
    </div>
    <div class="st-card" style="margin-top:18px">
        <div class="st-card-title"><span><i class="fa fa-trophy" style="color:#f59e0b;margin-right:6px"></i> Teacher Leaderboard (Top 20)</span></div>
        <div id="leaderboardList">
            <div class="st-loading"><i class="fa fa-spinner fa-spin"></i><br>Loading...</div>
        </div>
    </div>
</div>

<!-- Panel: Moderation Log -->
<div class="st-panel" id="panel-moderation">
    <div class="st-card">
        <div class="st-card-title"><span><i class="fa fa-shield" style="color:var(--gold);margin-right:6px"></i> Moderation History</span></div>
        <div id="moderationLog">
            <div class="st-loading"><i class="fa fa-spinner fa-spin"></i><br>Loading...</div>
        </div>
    </div>
</div>

</div></section></div>

<!-- ════════════ STORY DETAIL MODAL ════════════ -->
<div class="st-overlay" id="storyModal">
    <div class="st-modal">
        <div class="st-modal-head">
            <h4 id="modalTitle">Story Details</h4>
            <button class="st-modal-close" onclick="ST.closeModal()">&times;</button>
        </div>
        <div class="st-modal-body" id="modalBody">
            <div class="st-loading"><i class="fa fa-spinner fa-spin"></i></div>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="st-toast" id="stToast"></div>

<!-- Chart.js — self-hosted (v4.4.4). Avoids a third-party CDN dependency and
     the SRI/supply-chain risk of loading executable JS from jsdelivr. Same
     vendored file used by attendance/analytics.php. -->
<script src="<?= base_url('assets/js/chart.umd.min.js') ?>?v=4.4.4"></script>

<!-- jQuery — footer loads it too, but the inline script below runs
     at parse time (before the footer), so we need $ available here.
     Loading twice is a no-op; jQuery is idempotent. -->
<script src="<?= base_url() ?>tools/bower_components/jquery/dist/jquery.min.js"></script>

<script>
var ST = ST || {};
ST.BASE = '<?= base_url() ?>';
ST.CSRF = {
    name: $('meta[name=csrf-name]').attr('content'),
    token: $('meta[name=csrf-token]').attr('content')
};
ST.stories = [];
ST.analytics = null;
ST.teachers = [];
ST.selected = {};
ST.bulkMode = false;
ST.charts = {};

// ══════════════════════════════════════════════════════════════════
// Phase C — Admin Upload
// ══════════════════════════════════════════════════════════════════
ST.openUpload = function() {
    document.getElementById('adminStoryForm').reset();
    document.getElementById('asProgressWrap').style.display = 'none';
    document.getElementById('asProgressBar').style.width = '0%';
    document.getElementById('asProgressPct').textContent = '0';
    document.getElementById('asSubmitBtn').disabled = false;
    // Reset audience → whole school, hide the class list.
    document.getElementById('asAudienceList').style.display = 'none';
    ST.loadAudienceOptions();
    document.getElementById('adminStoryModal').style.display = 'flex';
};

// Lazy-load the class-section checkboxes for the audience picker (once).
ST.audienceOptions = null;
ST.loadAudienceOptions = function() {
    var box = document.getElementById('asAudienceList');
    if (ST.audienceOptions) return;   // already loaded this session
    ST.ajaxGet('stories/get_audience_options', {}, function(r) {
        if (r.status !== 'success') { box.innerHTML = '<div style="font-size:12px;color:#dc2626">Could not load classes.</div>'; return; }
        ST.audienceOptions = r.options || [];
        if (!ST.audienceOptions.length) {
            box.innerHTML = '<div style="font-size:12px;color:#94a3b8">No classes found for this school.</div>';
            return;
        }
        var html = '';
        ST.audienceOptions.forEach(function(o) {
            html += '<label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#334155;padding:3px 0;cursor:pointer;">'
                + '<input type="checkbox" class="as-aud-cb" value="' + ST.escAttr(o.key) + '"> ' + ST.esc(o.label) + '</label>';
        });
        box.innerHTML = html;
    });
};

// Toggle the class list when switching between Whole school / Specific.
ST.onAudienceMode = function() {
    var mode = (document.querySelector('input[name=asAudienceMode]:checked') || {}).value;
    document.getElementById('asAudienceList').style.display = (mode === 'specific') ? 'block' : 'none';
};

ST.closeUpload = function() {
    document.getElementById('adminStoryModal').style.display = 'none';
};

ST.submitUpload = function() {
    var fileEl  = document.getElementById('asMedia');
    var typeEl  = document.getElementById('asType');
    var priEl   = document.getElementById('asPriority');
    var capEl   = document.getElementById('asCaption');
    var file    = fileEl.files && fileEl.files[0];
    if (!file) { alert('Please pick a file'); return; }

    // Client-side size guard (server enforces too)
    var type = typeEl.value;
    var maxBytes = type === 'image' ? 10 * 1024 * 1024 : 50 * 1024 * 1024;
    if (file.size > maxBytes) {
        var maxMb = maxBytes / (1024 * 1024);
        alert(type + ' must be ≤ ' + maxMb + ' MB.'); return;
    }

    // Resolve audience: whole-school = [], else the checked class keys.
    var mode = (document.querySelector('input[name=asAudienceMode]:checked') || {}).value;
    var audience = [];
    if (mode === 'specific') {
        var cbs = document.querySelectorAll('#asAudienceList .as-aud-cb:checked');
        for (var i = 0; i < cbs.length; i++) audience.push(cbs[i].value);
        if (!audience.length) { alert('Pick at least one class, or choose “Whole school”.'); return; }
    }

    var fd = new FormData();
    fd.append(ST.CSRF.name, ST.CSRF.token);
    fd.append('media',    file);
    fd.append('type',     type);
    fd.append('priority', priEl.value);
    fd.append('caption',  capEl.value.trim());
    fd.append('audience', JSON.stringify(audience));

    var btn   = document.getElementById('asSubmitBtn');
    var wrap  = document.getElementById('asProgressWrap');
    var bar   = document.getElementById('asProgressBar');
    var pct   = document.getElementById('asProgressPct');
    var label = document.getElementById('asProgressLabel');
    btn.disabled = true;
    wrap.style.display = 'block';
    bar.classList.remove('as-indeterminate');
    if (label) label.textContent = 'Uploading';

    // Use XMLHttpRequest for upload progress (fetch doesn't expose it)
    var xhr = new XMLHttpRequest();
    xhr.open('POST', ST.BASE + 'stories/upload_story', true);
    xhr.upload.onprogress = function(e) {
        if (e.lengthComputable) {
            var p = Math.round((e.loaded / e.total) * 100);
            bar.style.width = p + '%';
            pct.textContent = p;
            // Bytes are in the server's hands — it now uploads to Storage +
            // writes Firestore (several seconds). Switch to an indeterminate
            // "Publishing…" state so the user isn't staring at a frozen 100%.
            if (p >= 100 && label) {
                label.textContent = 'Publishing…';
                bar.classList.add('as-indeterminate');
            }
        }
    };
    xhr.onload = function() {
        btn.disabled = false;
        bar.classList.remove('as-indeterminate');
        var resp;
        try { resp = JSON.parse(xhr.responseText); } catch (_) {
            alert('Unexpected server response');
            return;
        }
        // Rotate CSRF (CI refreshes on every POST)
        if (resp && resp.csrf_hash) ST.CSRF.token = resp.csrf_hash;
        if (resp && resp[ST.CSRF.name]) ST.CSRF.token = resp[ST.CSRF.name];
        if (xhr.status >= 200 && xhr.status < 300 && resp.status === 'success') {
            ST.closeUpload();
            if (typeof ST.refresh === 'function') ST.refresh();
            alert(resp.message || 'Story published.');
        } else {
            alert((resp && resp.message) || 'Upload failed.');
        }
    };
    xhr.onerror = function() {
        btn.disabled = false;
        alert('Network error during upload.');
    };
    xhr.send(fd);
};

// ── Helpers ─────────────────────────────────────────────────────

ST.esc = function(s) {
    var d = document.createElement('span');
    d.textContent = s || '';
    return d.innerHTML;
};

// SEC-2 — ST.esc only escapes & < > (textContent→innerHTML). Values placed
// inside a DOUBLE-quoted HTML attribute can still break out via " or '.
// escAttr closes that hole for every quoted-attribute sink.
ST.escAttr = function(s) {
    return ST.esc(s).replace(/"/g, '&#34;').replace(/'/g, '&#39;');
};

// SEC-2 — URL allow-list for every src="…" sink (mediaUrl, avatars). Only
// https URLs render; javascript:/data:/relative or attribute-breakout
// payloads are dropped to '' (caller falls back to a safe default).
ST.safeUrl = function(u) {
    u = String(u || '');
    return /^https:\/\//i.test(u) ? u : '';
};

// SEC-2 — safe interpolation of server data into a JS single-quoted string
// that itself lives inside a double-quoted inline handler
// (onclick="ST.x('<HERE>')"). Escapes HTML, backslash, and BOTH quote styles.
ST.jsArg = function(s) {
    return ST.esc(String(s == null ? '' : s))
        .replace(/\\/g, '\\\\')
        .replace(/'/g, "\\'")
        .replace(/"/g, '&#34;');
};

ST.toast = function(msg, type) {
    var t = document.getElementById('stToast');
    t.textContent = msg;
    t.className = 'st-toast ' + (type || 'success');
    clearTimeout(ST._toastTm);
    ST._toastTm = setTimeout(function() { t.className = 'st-toast'; }, 3500);
};

ST.ajaxGet = function(url, data, cb) {
    $.ajax({
        url: ST.BASE + url, type: 'GET', data: data, dataType: 'json',
        success: function(r) {
            if (r.csrf_token) ST.CSRF.token = r.csrf_token;
            cb(r);
        },
        error: function(x) {
            if (x.responseJSON && x.responseJSON.csrf_token) ST.CSRF.token = x.responseJSON.csrf_token;
            ST.toast('Request failed. Please try again.', 'error');
        }
    });
};

ST.ajaxPost = function(url, data, cb) {
    data[ST.CSRF.name] = ST.CSRF.token;
    $.ajax({
        url: ST.BASE + url, type: 'POST', data: data, dataType: 'json',
        success: function(r) {
            if (r.csrf_token) ST.CSRF.token = r.csrf_token;
            cb(r);
        },
        error: function(x) {
            if (x.responseJSON && x.responseJSON.csrf_token) ST.CSRF.token = x.responseJSON.csrf_token;
            var m = 'Request failed.';
            try { m = JSON.parse(x.responseText).message || m; } catch(e) {}
            ST.toast(m, 'error');
        }
    });
};

ST.fmtDate = function(ts) {
    if (!ts) return '--';
    var d = new Date(typeof ts === 'number' ? ts : parseInt(ts));
    if (isNaN(d.getTime())) return '--';
    var day = d.getDate(), mon = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getMonth()];
    var hr = d.getHours(), mn = d.getMinutes();
    return day + ' ' + mon + ' ' + d.getFullYear() + ', ' + (hr < 10 ? '0' : '') + hr + ':' + (mn < 10 ? '0' : '') + mn;
};

ST.timeAgo = function(ts) {
    if (!ts) return '';
    var diff = Math.floor((Date.now() - ts) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
};

ST.statusBadge = function(s) {
    var m = {active: 'st-badge-green', expired: 'st-badge-gray', flagged: 'st-badge-rose', removed: 'st-badge-amber'};
    return '<span class="st-badge ' + (m[s] || 'st-badge-gray') + '">' + ST.esc(s || 'unknown') + '</span>';
};

// Audience chip — whole-school (empty audienceClassKeys) vs class-scoped.
// Lets moderators see at a glance which stories reach the entire school.
ST.isWholeSchool = function(s) {
    var a = s.audienceClassKeys;
    // Whole-school = empty (legacy) OR the ["*"] sentinel (new server-side
    // audience contract). Both must read as "Whole school" in the UI.
    return !a || a.length === 0 || (a.length === 1 && a[0] === '*');
};
ST.audienceChip = function(s) {
    var whole = ST.isWholeSchool(s);
    var label = s.audienceLabel || (whole ? 'Whole school' : 'Class');
    return '<div class="st-story-audience" title="Audience">'
        + '<i class="fa fa-' + (whole ? 'globe' : 'users') + '"></i> '
        + ST.esc(label) + '</div>';
};

ST.defaultAvatar = ST.BASE + 'tools/dist/img/avatar.png';

// ── Tab Navigation ──────────────────────────────────────────────

$(document).on('click', '.st-tab', function() {
    var tab = $(this).data('tab');
    $('.st-tab').removeClass('active');
    $(this).addClass('active');
    $('.st-panel').removeClass('active');
    $('#panel-' + tab).addClass('active');

    if (tab === 'analytics' && !ST.analytics) ST.loadAnalytics();
    if (tab === 'flagged') ST.loadFlagged();
    if (tab === 'moderation') ST.loadModerationLog();
});

// ── Bulk Mode ───────────────────────────────────────────────────

ST.toggleBulk = function() {
    ST.bulkMode = !ST.bulkMode;
    ST.selected = {};
    var grid = document.getElementById('storyGrid');
    var btn = document.getElementById('bulkToggleBtn');
    if (ST.bulkMode) {
        grid.classList.add('st-bulk-mode');
        btn.innerHTML = '<i class="fa fa-times"></i> Cancel';
        btn.className = 'st-btn st-btn-danger';
    } else {
        grid.classList.remove('st-bulk-mode');
        btn.innerHTML = '<i class="fa fa-check-square-o"></i> Bulk Select';
        btn.className = 'st-btn st-btn-outline';
    }
    ST.updateBulkBar();
    grid.querySelectorAll('.st-story-select').forEach(function(cb) { cb.checked = false; });
};

ST.updateBulkBar = function() {
    var count = Object.keys(ST.selected).length;
    document.getElementById('bulkCount').textContent = count;
    var bar = document.getElementById('bulkBar');
    bar.classList.toggle('visible', count > 0 && ST.bulkMode);
};

ST.onStorySelect = function(teacherId, storyId, checked) {
    var key = teacherId + '|' + storyId;
    if (checked) {
        ST.selected[key] = { teacher_id: teacherId, story_id: storyId };
    } else {
        delete ST.selected[key];
    }
    ST.updateBulkBar();
};

ST.bulkAction = function(status) {
    var items = Object.values(ST.selected);
    if (!items.length) { ST.toast('No stories selected.', 'error'); return; }
    if (!confirm('Change ' + items.length + ' story(ies) to "' + status + '"?')) return;

    ST.ajaxPost('stories/bulk_moderate', {
        status: status,
        items: JSON.stringify(items),
        reason: 'Bulk action from admin portal'
    }, function(r) {
        if (r.status === 'success') {
            ST.toast(r.message || 'Done.');
            ST.allStories = null;   // invalidate shared cache (Flagged/Moderation)
            ST.selected = {};
            ST.updateBulkBar();
            ST.loadStories();
            ST.loadAnalytics();
        } else {
            ST.toast(r.message || 'Error.', 'error');
        }
    });
};

// ── Load Teachers ───────────────────────────────────────────────

// M-1 — the teacher dropdown is now DERIVED client-side from the already
// fetched unfiltered story set (ST.allStories) instead of a separate
// `stories/get_teachers` server scan. Preserves the current selection.
ST.populateTeacherFilter = function() {
    var sel = document.getElementById('filterTeacher');
    if (!sel) return;
    var prev = sel.value;

    var byId = {};
    (ST.allStories || []).forEach(function(s) {
        var tid = s.teacherId;
        if (!tid) return;
        if (!byId[tid]) byId[tid] = { teacherId: tid, name: s.teacherName || tid, storyCount: 0 };
        byId[tid].storyCount++;
        if ((byId[tid].name === '' || byId[tid].name === tid) && s.teacherName) byId[tid].name = s.teacherName;
    });
    ST.teachers = Object.keys(byId).map(function(k) { return byId[k]; });
    ST.teachers.sort(function(a, b) { return (a.name || '').toLowerCase().localeCompare((b.name || '').toLowerCase()); });

    var html = '<option value="">All Teachers (' + ST.teachers.length + ')</option>';
    ST.teachers.forEach(function(t) {
        html += '<option value="' + ST.escAttr(t.teacherId) + '">' + ST.esc(t.name) + ' (' + t.storyCount + ')</option>';
    });
    sel.innerHTML = html;
    // Restore prior selection if it still exists.
    if (prev) sel.value = prev;
};

// M-1 — fetch the full unfiltered story set ONCE and reuse it for the teacher
// dropdown, the Flagged tab and the Moderation Log tab (they previously each
// re-scanned the whole collection). Cached in ST.allStories; invalidated
// (set to null) after any moderation so the next read is fresh.
ST.ensureAllStories = function(cb) {
    if (ST.allStories) { if (cb) cb(); return; }
    ST.ajaxGet('stories/get_stories', {}, function(r) {
        ST.allStories = (r.status === 'success') ? (r.stories || []) : [];
        ST.populateTeacherFilter();
        if (cb) cb();
    });
};

// ── Load Stories ────────────────────────────────────────────────

ST.loadStories = function() {
    var grid = document.getElementById('storyGrid');
    grid.innerHTML = '<div class="st-loading"><i class="fa fa-spinner fa-spin"></i><br>Loading stories...</div>';

    var params = {};
    var v;
    v = document.getElementById('filterTeacher').value; if (v) params.teacher = v;
    v = document.getElementById('filterStatus').value; if (v) params.status = v;
    v = document.getElementById('filterDateFrom').value; if (v) params.date_from = v;
    v = document.getElementById('filterDateTo').value; if (v) params.date_to = v;
    v = document.getElementById('filterSearch').value.trim(); if (v) params.search = v;
    var mediaFilter = document.getElementById('filterMedia').value;

    ST.ajaxGet('stories/get_stories', params, function(r) {
        if (r.status !== 'success') {
            grid.innerHTML = '<div class="st-empty"><i class="fa fa-exclamation-triangle"></i><p>Failed to load stories.</p></div>';
            return;
        }

        ST.stories = r.stories || [];

        // M-1 — when this load carried NO server-side filters it IS the full
        // school set, so cache it as ST.allStories (feeds teacher dropdown +
        // Flagged + Moderation tabs, sparing them their own collection scans).
        var noServerFilters = !params.teacher && !params.status && !params.date_from && !params.date_to && !params.search;
        if (noServerFilters) {
            ST.allStories = ST.stories;
            ST.populateTeacherFilter();
        }

        // Client-side media type filter
        var filtered = ST.stories;
        if (mediaFilter) {
            filtered = filtered.filter(function(s) { return s.mediaType === mediaFilter; });
        }

        // Update tab count
        document.getElementById('tabCountAll').textContent = filtered.length;

        if (!filtered.length) {
            grid.innerHTML = '<div class="st-empty"><i class="fa fa-camera-retro"></i><p>No stories found matching your filters.</p></div>';
            return;
        }

        var html = '';
        filtered.forEach(function(s) {
            // SEC-2 — src only ever gets an https URL (safeUrl); anything else
            // renders no media. tid/sid ride in data-* attrs (escAttr) and are
            // read back by delegated handlers, so no server value is ever
            // interpolated into an inline JS handler.
            var mediaSrc = ST.safeUrl(s.mediaUrl);
            var thumb = '';
            if (mediaSrc) {
                if (s.mediaType === 'video') {
                    // `#t=0.1` seeks to the first frame so the browser paints it
                    // as the poster — `preload="metadata"` alone shows a BLANK box.
                    // Plus a centered play overlay so it reads as a video.
                    thumb = '<video src="' + ST.escAttr(mediaSrc) + '#t=0.1" muted preload="metadata" playsinline></video>'
                          + '<span class="st-play-badge"><i class="fa fa-play"></i></span>';
                } else {
                    thumb = '<img loading="lazy" src="' + ST.escAttr(mediaSrc) + '" alt="Story" onerror="this.parentNode.innerHTML=\'<i class=\\\'fa fa-image st-media-icon\\\'></i>\'">';
                }
            } else {
                thumb = '<i class="fa fa-image st-media-icon"></i>';
            }

            var avatar = ST.safeUrl(s.teacherProfilePic) || ST.defaultAvatar;
            var statusClass = s.effectiveStatus || 'active';

            html += '<div class="st-story-card" data-tid="' + ST.escAttr(s.teacherId) + '" data-sid="' + ST.escAttr(s.storyId) + '">'
                + '<input type="checkbox" class="st-story-select">'
                + '<div class="st-story-thumb st-open-detail">'
                + thumb
                + '<span class="st-media-badge"><i class="fa fa-' + (s.mediaType === 'video' ? 'video-camera' : 'image') + '"></i> ' + ST.esc(s.mediaType || 'image') + '</span>'
                + '<span class="st-status-dot ' + ST.escAttr(statusClass) + '"></span>'
                + '</div>'
                + '<div class="st-story-body st-open-detail">'
                + '<div class="st-story-teacher">'
                + '<img class="st-story-avatar" src="' + ST.escAttr(avatar) + '" onerror="this.src=\'' + ST.defaultAvatar + '\'">'
                + '<span class="st-story-tname">' + ST.esc(s.teacherName || 'Unknown') + '</span>'
                + '</div>'
                + '<div class="st-story-caption">' + ST.esc(s.caption || 'No caption') + '</div>'
                + ST.audienceChip(s)
                + '<div class="st-story-meta">'
                + '<span><i class="fa fa-clock-o"></i> ' + ST.timeAgo(s.createdAt) + '</span>'
                + '<span class="st-story-views"><i class="fa fa-eye"></i> ' + (s.viewCount || 0) + '</span>'
                + ST.statusBadge(s.effectiveStatus)
                + '</div>'
                + '</div></div>';
        });
        grid.innerHTML = html;
    });
};

// SEC-2 — delegated story-card handlers. tid/sid are read from the card's
// data-* attrs (decoded back to their raw values by the browser) instead of
// being baked into inline onclick/onchange strings, eliminating the
// attribute-context injection surface entirely.
$(document).on('change', '#storyGrid .st-story-select', function() {
    var card = this.closest('.st-story-card');
    if (card) ST.onStorySelect(card.getAttribute('data-tid'), card.getAttribute('data-sid'), this.checked);
});
$(document).on('click', '#storyGrid .st-story-select', function(e) { e.stopPropagation(); });
$(document).on('click', '#storyGrid .st-open-detail', function() {
    var card = this.closest('.st-story-card');
    if (card) ST.openDetail(card.getAttribute('data-tid'), card.getAttribute('data-sid'));
});

ST.clearFilters = function() {
    document.getElementById('filterTeacher').value = '';
    document.getElementById('filterStatus').value = '';
    document.getElementById('filterMedia').value = '';
    document.getElementById('filterDateFrom').value = '';
    document.getElementById('filterDateTo').value = '';
    document.getElementById('filterSearch').value = '';
    ST.loadStories();
};

// ── Story Detail Modal ──────────────────────────────────────────

ST.openDetail = function(teacherId, storyId) {
    if (ST.bulkMode) return;
    document.getElementById('storyModal').classList.add('open');
    document.getElementById('modalBody').innerHTML = '<div class="st-loading"><i class="fa fa-spinner fa-spin"></i><br>Loading...</div>';

    ST.ajaxGet('stories/get_story_detail', { teacher_id: teacherId, story_id: storyId }, function(r) {
        if (r.status !== 'success') {
            document.getElementById('modalBody').innerHTML = '<div class="st-empty"><i class="fa fa-exclamation-triangle"></i><p>Story not found.</p></div>';
            return;
        }
        var s = r.story;
        var mediaSrc = ST.safeUrl(s.mediaUrl);
        var mediaHtml = '';
        if (mediaSrc) {
            if (s.mediaType === 'video') {
                // `#t=0.1` paints the first frame so the player isn't a black box
                // before play; `controls` gives the play/seek affordance.
                mediaHtml = '<video src="' + ST.escAttr(mediaSrc) + '#t=0.1" controls preload="metadata" playsinline style="max-width:100%;max-height:360px"></video>';
            } else {
                mediaHtml = '<img src="' + ST.escAttr(mediaSrc) + '" alt="Story" style="max-width:100%;max-height:360px">';
            }
        } else {
            mediaHtml = '<div style="padding:40px;color:var(--t4);font-size:24px"><i class="fa fa-image"></i> No media</div>';
        }

        var avatar = ST.safeUrl(s.teacherProfilePic) || ST.defaultAvatar;
        var expiresAt = s.expiresAt ? s.expiresAt : 0;
        var isExpired = expiresAt > 0 && expiresAt < Date.now();
        var tid = ST.jsArg(s.teacherId);
        var sid = ST.jsArg(s.storyId);

        var html = '<div class="st-detail-media">' + mediaHtml + '</div>'
            + '<div class="st-detail-row">'
            + '<div class="st-detail-col">'
            + '<div class="st-detail-field"><div class="st-detail-label">Teacher</div><div class="st-detail-value" style="display:flex;align-items:center;gap:8px"><img src="' + ST.escAttr(avatar) + '" onerror="this.src=\'' + ST.defaultAvatar + '\'" style="width:24px;height:24px;border-radius:50%"> ' + ST.esc(s.teacherName || 'Unknown') + '</div></div>'
            + '<div class="st-detail-field"><div class="st-detail-label">Caption</div><div class="st-detail-value">' + ST.esc(s.caption || 'No caption') + '</div></div>'
            + '<div class="st-detail-field"><div class="st-detail-label">Audience</div><div class="st-detail-value"><i class="fa fa-' + (ST.isWholeSchool(s) ? 'globe' : 'users') + '"></i> ' + ST.esc(s.audienceLabel || 'Whole school') + '</div></div>'
            + '<div class="st-detail-field"><div class="st-detail-label">Media Type</div><div class="st-detail-value">' + ST.esc(s.mediaType || 'image') + '</div></div>'
            + '</div>'
            + '<div class="st-detail-col">'
            + '<div class="st-detail-field"><div class="st-detail-label">Status</div><div class="st-detail-value">' + ST.statusBadge(s.effectiveStatus) + '</div></div>'
            + '<div class="st-detail-field"><div class="st-detail-label">Created</div><div class="st-detail-value">' + ST.fmtDate(s.createdAt) + '</div></div>'
            + '<div class="st-detail-field"><div class="st-detail-label">Expires</div><div class="st-detail-value">' + ST.fmtDate(expiresAt) + (isExpired ? ' <span class="st-badge st-badge-gray" style="margin-left:6px">expired</span>' : '') + '</div></div>'
            + '<div class="st-detail-field"><div class="st-detail-label">Views</div><div class="st-detail-value" style="font-size:18px;font-weight:700;color:var(--gold)">' + (s.viewCount || 0) + '</div></div>'
            + '</div></div>';

        // "Viewed by" list — who opened this story (admin oversight).
        var viewers = s.viewers || [];
        html += '<div class="st-detail-field" style="margin-top:12px"><div class="st-detail-label">Viewed by (' + viewers.length + ')</div>';
        if (!viewers.length) {
            html += '<div class="st-detail-value" style="color:var(--t4)">No views yet.</div>';
        } else {
            html += '<div style="max-height:180px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;padding:6px 10px;margin-top:4px">';
            viewers.forEach(function(v) {
                html += '<div style="display:flex;justify-content:space-between;gap:10px;padding:4px 0;font-size:13px;border-bottom:1px solid var(--border)">'
                    + '<span style="color:var(--t2)">' + ST.esc(v.userName || v.userId || 'Unknown') + '</span>'
                    + '<span style="color:var(--t4);font-size:11px">' + (v.viewedAt ? ST.timeAgo(v.viewedAt) : '') + '</span>'
                    + '</div>';
            });
            html += '</div>';
        }
        html += '</div>';

        // Moderation info if exists
        if (s.moderatedBy) {
            html += '<div style="margin-top:12px;padding:10px 14px;background:var(--bg3);border-radius:8px;font-size:12px;border:1px solid var(--border)">'
                + '<strong style="color:var(--t2)">Last moderated by:</strong> ' + ST.esc(s.moderatedByName || s.moderatedBy)
                + ' <span style="color:var(--t3);margin-left:8px">' + ST.fmtDate(s.moderatedAt) + '</span>'
                + (s.moderationReason ? '<br><strong style="color:var(--t2)">Reason:</strong> ' + ST.esc(s.moderationReason) : '')
                + '</div>';
        }

        // Moderation actions
        html += '<div class="st-detail-moderation">'
            + '<h5>Moderation Actions</h5>'
            + '<textarea class="st-mod-reason" id="modReason" placeholder="Reason for moderation (optional)..."></textarea>'
            + '<div class="st-mod-actions">'
            + '<button class="st-btn st-btn-amber st-btn-sm" onclick="ST.moderate(\'' + tid + '\',\'' + sid + '\',\'flagged\')"><i class="fa fa-flag"></i> Flag</button>'
            + '<button class="st-btn st-btn-danger st-btn-sm" onclick="ST.moderate(\'' + tid + '\',\'' + sid + '\',\'removed\')"><i class="fa fa-ban"></i> Remove</button>'
            + '<button class="st-btn st-btn-danger" onclick="ST.deleteStory(\'' + tid + '\',\'' + sid + '\')" style="margin-left:auto"><i class="fa fa-trash"></i> Delete Permanently</button>'
            + '</div></div>';

        document.getElementById('modalBody').innerHTML = html;
    });
};

ST.closeModal = function() {
    document.getElementById('storyModal').classList.remove('open');
};

// Close modal on overlay click
document.getElementById('storyModal').addEventListener('click', function(e) {
    if (e.target === this) ST.closeModal();
});

// Close modal on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') ST.closeModal();
});

// ── Moderation Actions ──────────────────────────────────────────

ST.moderate = function(teacherId, storyId, status) {
    var reason = (document.getElementById('modReason') || {}).value || '';
    ST.ajaxPost('stories/moderate_story', {
        teacher_id: teacherId,
        story_id: storyId,
        status: status,
        reason: reason
    }, function(r) {
        if (r.status === 'success') {
            ST.toast(r.message || 'Status updated.');
            ST.allStories = null;   // invalidate shared cache (Flagged/Moderation)
            ST.closeModal();
            ST.loadStories();
            ST._refreshAnalytics();
        } else {
            ST.toast(r.message || 'Error.', 'error');
        }
    });
};

ST.deleteStory = function(teacherId, storyId) {
    if (!confirm('Permanently delete this story? This cannot be undone.')) return;
    ST.ajaxPost('stories/delete_story', {
        teacher_id: teacherId,
        story_id: storyId
    }, function(r) {
        if (r.status === 'success') {
            ST.toast(r.message || 'Story deleted.');
            ST.allStories = null;   // invalidate shared cache (Flagged/Moderation)
            ST.closeModal();
            ST.loadStories();
            ST._refreshAnalytics();
        } else {
            ST.toast(r.message || 'Error.', 'error');
        }
    });
};

ST._refreshAnalytics = function() {
    ST.analytics = null;
    ST.loadAnalytics();
};

// ── Load Flagged Stories ────────────────────────────────────────

ST.loadFlagged = function() {
    var el = document.getElementById('flaggedList');
    el.innerHTML = '<div class="st-loading"><i class="fa fa-spinner fa-spin"></i><br>Loading...</div>';

    // M-1 — derived from the shared unfiltered cache; no separate fetch.
    ST.ensureAllStories(function() {
        var list = (ST.allStories || []).filter(function(s) { return s.effectiveStatus === 'flagged'; });
        document.getElementById('tabCountFlagged').textContent = list.length;

        if (!list.length) {
            el.innerHTML = '<div class="st-empty"><i class="fa fa-check-circle" style="color:#22c55e"></i><p>No flagged stories. All clear!</p></div>';
            return;
        }

        var html = '<table class="st-table"><thead><tr><th></th><th>Teacher</th><th>Caption</th><th>Type</th><th>Views</th><th>Created</th><th>Actions</th></tr></thead><tbody>';
        list.forEach(function(s) {
            var avatar = ST.safeUrl(s.teacherProfilePic) || ST.defaultAvatar;
            var tid = ST.jsArg(s.teacherId);
            var sid = ST.jsArg(s.storyId);
            html += '<tr>'
                + '<td><img loading="lazy" src="' + ST.escAttr(avatar) + '" onerror="this.src=\'' + ST.defaultAvatar + '\'" style="width:32px;height:32px;border-radius:6px;object-fit:cover;border:1px solid var(--border)"></td>'
                + '<td><strong>' + ST.esc(s.teacherName || 'Unknown') + '</strong></td>'
                + '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + ST.esc(s.caption || '--') + '</td>'
                + '<td><span class="st-badge st-badge-blue">' + ST.esc(s.mediaType) + '</span></td>'
                + '<td>' + (s.viewCount || 0) + '</td>'
                + '<td style="white-space:nowrap">' + ST.fmtDate(s.createdAt) + '</td>'
                + '<td style="white-space:nowrap">'
                + '<button class="st-btn st-btn-danger st-btn-sm" onclick="ST.moderate(\'' + tid + '\',\'' + sid + '\',\'removed\')" title="Remove"><i class="fa fa-ban"></i></button> '
                + '<button class="st-btn st-btn-outline st-btn-sm" onclick="ST.openDetail(\'' + tid + '\',\'' + sid + '\')" title="View Details"><i class="fa fa-eye"></i></button>'
                + '</td></tr>';
        });
        html += '</tbody></table>';
        el.innerHTML = html;
    });
};

// ── Load Analytics ──────────────────────────────────────────────

ST.loadAnalytics = function() {
    ST.ajaxGet('stories/get_analytics', {}, function(r) {
        if (r.status !== 'success') return;

        ST.analytics = r;

        // Update stats
        document.getElementById('statTotal').textContent = r.total || 0;
        document.getElementById('statActive').textContent = r.active || 0;
        document.getElementById('statExpired').textContent = r.expired || 0;
        document.getElementById('statViews').textContent = (r.totalViews || 0).toLocaleString('en-IN');
        document.getElementById('statFlagged').textContent = r.flagged || 0;
        document.getElementById('statTeachers').textContent = r.teacherCount || 0;
        document.getElementById('tabCountFlagged').textContent = r.flagged || 0;

        // Daily chart
        ST.renderDailyChart(r.dailyData || []);

        // View distribution chart
        ST.renderViewDistChart(r.viewDist || {});

        // Leaderboard
        ST.renderLeaderboard(r.leaderboard || []);
    });
};

ST.renderDailyChart = function(data) {
    var ctx = document.getElementById('chartDaily');
    if (!ctx) return;

    if (ST.charts.daily) ST.charts.daily.destroy();

    var labels = data.map(function(d) {
        var parts = d.date.split('-');
        return parseInt(parts[2]) + '/' + parseInt(parts[1]);
    });
    var values = data.map(function(d) { return d.count; });

    var isDark = document.body.getAttribute('data-theme') !== 'day';
    var gridColor = isDark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.08)';
    var textColor = isDark ? '#94c9c3' : '#5a9e98';

    ST.charts.daily = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Stories',
                data: values,
                backgroundColor: 'rgba(188,90,60,.35)',
                borderColor: '#BC5A3C',
                borderWidth: 1,
                borderRadius: 4,
                maxBarThickness: 24
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1, color: textColor, font: { size: 10 } }, grid: { color: gridColor } },
                x: { ticks: { color: textColor, font: { size: 9 }, maxRotation: 45 }, grid: { display: false } }
            }
        }
    });
};

ST.renderViewDistChart = function(dist) {
    var ctx = document.getElementById('chartViewDist');
    if (!ctx) return;

    if (ST.charts.viewDist) ST.charts.viewDist.destroy();

    var buckets = ['0', '1-10', '11-50', '51-100', '100+'];
    var values = buckets.map(function(b) { return dist[b] || 0; });
    var colors = ['#9ca3af', '#3b82f6', '#BC5A3C', '#f59e0b', '#ef4444'];

    ST.charts.viewDist = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: buckets.map(function(b) { return b + ' views'; }),
            datasets: [{
                data: values,
                backgroundColor: colors,
                borderWidth: 0,
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '55%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 14,
                        usePointStyle: true,
                        pointStyleWidth: 10,
                        font: { size: 11, family: "'Plus Jakarta Sans', sans-serif" },
                        color: document.body.getAttribute('data-theme') !== 'day' ? '#94c9c3' : '#5a9e98'
                    }
                }
            }
        }
    });
};

ST.renderLeaderboard = function(list) {
    var el = document.getElementById('leaderboardList');
    if (!list.length) {
        el.innerHTML = '<div class="st-empty"><i class="fa fa-trophy"></i><p>No teacher activity yet.</p></div>';
        return;
    }

    var html = '';
    list.forEach(function(t) {
        var rankClass = t.rank === 1 ? 'gold' : (t.rank === 2 ? 'silver' : (t.rank === 3 ? 'bronze' : ''));
        var avatar = ST.safeUrl(t.pic) || ST.defaultAvatar;
        html += '<div class="st-lb-row">'
            + '<div class="st-lb-rank ' + rankClass + '">' + t.rank + '</div>'
            + '<img class="st-lb-avatar" src="' + ST.escAttr(avatar) + '" onerror="this.src=\'' + ST.defaultAvatar + '\'">'
            + '<div class="st-lb-info"><div class="st-lb-name">' + ST.esc(t.name) + '</div><div class="st-lb-sub">' + t.count + ' stories</div></div>'
            + '<div class="st-lb-stat"><strong>' + (t.views || 0).toLocaleString('en-IN') + '</strong><span>' + t.avgViews + ' avg views</span></div>'
            + '</div>';
    });
    el.innerHTML = html;
};

// ── Load Moderation Log ─────────────────────────────────────────

ST.loadModerationLog = function() {
    var el = document.getElementById('moderationLog');
    el.innerHTML = '<div class="st-loading"><i class="fa fa-spinner fa-spin"></i><br>Loading...</div>';

    // M-1 — derived from the shared unfiltered cache; no separate fetch.
    ST.ensureAllStories(function() {
        var moderated = (ST.allStories || []).filter(function(s) {
            return s.status === 'flagged' || s.status === 'removed';
        });

        if (!moderated.length) {
            el.innerHTML = '<div class="st-empty"><i class="fa fa-shield"></i><p>No moderation actions recorded.</p></div>';
            return;
        }

        var html = '<table class="st-table"><thead><tr><th>Teacher</th><th>Caption</th><th>Status</th><th>Type</th><th>Views</th><th>Created</th><th>Actions</th></tr></thead><tbody>';
        moderated.forEach(function(s) {
            var tid = ST.jsArg(s.teacherId);
            var sid = ST.jsArg(s.storyId);
            html += '<tr>'
                + '<td><strong>' + ST.esc(s.teacherName || 'Unknown') + '</strong></td>'
                + '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + ST.esc(s.caption || '--') + '</td>'
                + '<td>' + ST.statusBadge(s.status) + '</td>'
                + '<td><span class="st-badge st-badge-blue">' + ST.esc(s.mediaType) + '</span></td>'
                + '<td>' + (s.viewCount || 0) + '</td>'
                + '<td style="white-space:nowrap">' + ST.fmtDate(s.createdAt) + '</td>'
                + '<td><button class="st-btn st-btn-outline st-btn-sm" onclick="ST.openDetail(\'' + tid + '\',\'' + sid + '\')"><i class="fa fa-eye"></i> View</button></td>'
                + '</tr>';
        });
        html += '</tbody></table>';
        el.innerHTML = html;
    });
};

// ── Refresh All ─────────────────────────────────────────────────

ST.refresh = function() {
    ST.analytics = null;
    ST.allStories = null;   // force a fresh shared fetch
    // loadStories() (unfiltered) repopulates ST.allStories + the teacher
    // dropdown, so no separate get_teachers scan is needed.
    ST.loadStories();
    ST.loadAnalytics();
    ST.toast('Refreshed.');
};

// ── Init ────────────────────────────────────────────────────────
// M-1 — two server scans on load (unfiltered stories + analytics) instead of
// three: loadStories() also seeds ST.allStories and the teacher dropdown, and
// the Flagged/Moderation tabs reuse that cache rather than re-scanning.

$(document).ready(function() {
    ST.loadStories();
    ST.loadAnalytics();
});
</script>
