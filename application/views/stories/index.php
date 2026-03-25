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
.st-stat-icon.teal{background:rgba(15,118,110,.12);color:var(--gold)}
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
.st-badge-teal{background:rgba(15,118,110,.12);color:var(--gold)}

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
    <button class="st-btn st-btn-primary" onclick="ST.refresh()"><i class="fa fa-refresh"></i> Refresh</button>
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
            <button class="st-btn st-btn-success st-btn-sm" onclick="ST.bulkAction('active')"><i class="fa fa-check"></i> Approve Selected</button>
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

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

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

// ── Helpers ─────────────────────────────────────────────────────

ST.esc = function(s) {
    var d = document.createElement('span');
    d.textContent = s || '';
    return d.innerHTML;
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

ST.loadTeachers = function() {
    ST.ajaxGet('stories/get_teachers', {}, function(r) {
        if (r.status !== 'success') return;
        ST.teachers = r.teachers || [];
        var sel = document.getElementById('filterTeacher');
        sel.innerHTML = '<option value="">All Teachers (' + ST.teachers.length + ')</option>';
        ST.teachers.forEach(function(t) {
            sel.innerHTML += '<option value="' + ST.esc(t.teacherId) + '">' + ST.esc(t.name) + ' (' + t.storyCount + ')</option>';
        });
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
            var thumb = '';
            if (s.mediaUrl) {
                if (s.mediaType === 'video') {
                    thumb = '<video src="' + ST.esc(s.mediaUrl) + '" muted preload="metadata"></video>';
                } else {
                    thumb = '<img src="' + ST.esc(s.mediaUrl) + '" alt="Story" onerror="this.parentNode.innerHTML=\'<i class=\\\'fa fa-image st-media-icon\\\'></i>\'">';
                }
            } else {
                thumb = '<i class="fa fa-image st-media-icon"></i>';
            }

            var avatar = s.teacherProfilePic || ST.defaultAvatar;
            var statusClass = s.effectiveStatus || 'active';
            var tid = ST.esc(s.teacherId).replace(/'/g, "\\'");
            var sid = ST.esc(s.storyId).replace(/'/g, "\\'");

            html += '<div class="st-story-card" data-tid="' + ST.esc(s.teacherId) + '" data-sid="' + ST.esc(s.storyId) + '">'
                + '<input type="checkbox" class="st-story-select" onchange="ST.onStorySelect(\'' + tid + '\',\'' + sid + '\',this.checked)" onclick="event.stopPropagation()">'
                + '<div class="st-story-thumb" onclick="ST.openDetail(\'' + tid + '\',\'' + sid + '\')">'
                + thumb
                + '<span class="st-media-badge"><i class="fa fa-' + (s.mediaType === 'video' ? 'video-camera' : 'image') + '"></i> ' + ST.esc(s.mediaType || 'image') + '</span>'
                + '<span class="st-status-dot ' + statusClass + '"></span>'
                + '</div>'
                + '<div class="st-story-body" onclick="ST.openDetail(\'' + tid + '\',\'' + sid + '\')">'
                + '<div class="st-story-teacher">'
                + '<img class="st-story-avatar" src="' + ST.esc(avatar) + '" onerror="this.src=\'' + ST.defaultAvatar + '\'">'
                + '<span class="st-story-tname">' + ST.esc(s.teacherName || 'Unknown') + '</span>'
                + '</div>'
                + '<div class="st-story-caption">' + ST.esc(s.caption || 'No caption') + '</div>'
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
        var mediaHtml = '';
        if (s.mediaUrl) {
            if (s.mediaType === 'video') {
                mediaHtml = '<video src="' + ST.esc(s.mediaUrl) + '" controls style="max-width:100%;max-height:360px"></video>';
            } else {
                mediaHtml = '<img src="' + ST.esc(s.mediaUrl) + '" alt="Story" style="max-width:100%;max-height:360px">';
            }
        } else {
            mediaHtml = '<div style="padding:40px;color:var(--t4);font-size:24px"><i class="fa fa-image"></i> No media</div>';
        }

        var avatar = s.teacherProfilePic || ST.defaultAvatar;
        var expiresAt = s.expiresAt ? s.expiresAt : 0;
        var isExpired = expiresAt > 0 && expiresAt < Date.now();
        var tid = ST.esc(s.teacherId).replace(/'/g, "\\'");
        var sid = ST.esc(s.storyId).replace(/'/g, "\\'");

        var html = '<div class="st-detail-media">' + mediaHtml + '</div>'
            + '<div class="st-detail-row">'
            + '<div class="st-detail-col">'
            + '<div class="st-detail-field"><div class="st-detail-label">Teacher</div><div class="st-detail-value" style="display:flex;align-items:center;gap:8px"><img src="' + ST.esc(avatar) + '" onerror="this.src=\'' + ST.defaultAvatar + '\'" style="width:24px;height:24px;border-radius:50%"> ' + ST.esc(s.teacherName || 'Unknown') + '</div></div>'
            + '<div class="st-detail-field"><div class="st-detail-label">Caption</div><div class="st-detail-value">' + ST.esc(s.caption || 'No caption') + '</div></div>'
            + '<div class="st-detail-field"><div class="st-detail-label">Media Type</div><div class="st-detail-value">' + ST.esc(s.mediaType || 'image') + '</div></div>'
            + '</div>'
            + '<div class="st-detail-col">'
            + '<div class="st-detail-field"><div class="st-detail-label">Status</div><div class="st-detail-value">' + ST.statusBadge(s.effectiveStatus) + '</div></div>'
            + '<div class="st-detail-field"><div class="st-detail-label">Created</div><div class="st-detail-value">' + ST.fmtDate(s.createdAt) + '</div></div>'
            + '<div class="st-detail-field"><div class="st-detail-label">Expires</div><div class="st-detail-value">' + ST.fmtDate(expiresAt) + (isExpired ? ' <span class="st-badge st-badge-gray" style="margin-left:6px">expired</span>' : '') + '</div></div>'
            + '<div class="st-detail-field"><div class="st-detail-label">Views</div><div class="st-detail-value" style="font-size:18px;font-weight:700;color:var(--gold)">' + (s.viewCount || 0) + '</div></div>'
            + '</div></div>';

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
            + '<button class="st-btn st-btn-success st-btn-sm" onclick="ST.moderate(\'' + tid + '\',\'' + sid + '\',\'active\')"><i class="fa fa-check"></i> Approve</button>'
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

    ST.ajaxGet('stories/get_stories', { status: 'flagged' }, function(r) {
        if (r.status !== 'success') {
            el.innerHTML = '<div class="st-empty"><i class="fa fa-exclamation-triangle"></i><p>Failed to load.</p></div>';
            return;
        }
        var list = r.stories || [];
        document.getElementById('tabCountFlagged').textContent = list.length;

        if (!list.length) {
            el.innerHTML = '<div class="st-empty"><i class="fa fa-check-circle" style="color:#22c55e"></i><p>No flagged stories. All clear!</p></div>';
            return;
        }

        var html = '<table class="st-table"><thead><tr><th></th><th>Teacher</th><th>Caption</th><th>Type</th><th>Views</th><th>Created</th><th>Actions</th></tr></thead><tbody>';
        list.forEach(function(s) {
            var avatar = s.teacherProfilePic || ST.defaultAvatar;
            var tid = ST.esc(s.teacherId).replace(/'/g, "\\'");
            var sid = ST.esc(s.storyId).replace(/'/g, "\\'");
            html += '<tr>'
                + '<td><img src="' + ST.esc(avatar) + '" onerror="this.src=\'' + ST.defaultAvatar + '\'" style="width:32px;height:32px;border-radius:6px;object-fit:cover;border:1px solid var(--border)"></td>'
                + '<td><strong>' + ST.esc(s.teacherName || 'Unknown') + '</strong></td>'
                + '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + ST.esc(s.caption || '--') + '</td>'
                + '<td><span class="st-badge st-badge-blue">' + ST.esc(s.mediaType) + '</span></td>'
                + '<td>' + (s.viewCount || 0) + '</td>'
                + '<td style="white-space:nowrap">' + ST.fmtDate(s.createdAt) + '</td>'
                + '<td style="white-space:nowrap">'
                + '<button class="st-btn st-btn-success st-btn-sm" onclick="ST.moderate(\'' + tid + '\',\'' + sid + '\',\'active\')" title="Approve"><i class="fa fa-check"></i></button> '
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
                backgroundColor: 'rgba(15,118,110,.35)',
                borderColor: '#0f766e',
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
    var colors = ['#9ca3af', '#3b82f6', '#0f766e', '#f59e0b', '#ef4444'];

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
        var avatar = t.pic || ST.defaultAvatar;
        html += '<div class="st-lb-row">'
            + '<div class="st-lb-rank ' + rankClass + '">' + t.rank + '</div>'
            + '<img class="st-lb-avatar" src="' + ST.esc(avatar) + '" onerror="this.src=\'' + ST.defaultAvatar + '\'">'
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

    ST.ajaxGet('stories/get_stories', {}, function(r) {
        if (r.status !== 'success') {
            el.innerHTML = '<div class="st-empty"><i class="fa fa-exclamation-triangle"></i><p>Failed to load.</p></div>';
            return;
        }

        var moderated = (r.stories || []).filter(function(s) {
            return s.status === 'flagged' || s.status === 'removed';
        });

        if (!moderated.length) {
            el.innerHTML = '<div class="st-empty"><i class="fa fa-shield"></i><p>No moderation actions recorded.</p></div>';
            return;
        }

        var html = '<table class="st-table"><thead><tr><th>Teacher</th><th>Caption</th><th>Status</th><th>Type</th><th>Views</th><th>Created</th><th>Actions</th></tr></thead><tbody>';
        moderated.forEach(function(s) {
            var tid = ST.esc(s.teacherId).replace(/'/g, "\\'");
            var sid = ST.esc(s.storyId).replace(/'/g, "\\'");
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
    ST.loadTeachers();
    ST.loadStories();
    ST.loadAnalytics();
    ST.toast('Refreshed.');
};

// ── Init ────────────────────────────────────────────────────────

$(document).ready(function() {
    ST.loadTeachers();
    ST.loadStories();
    ST.loadAnalytics();
});
</script>
