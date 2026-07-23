<?php
$tabs = [
    'dashboard'     => ['icon' => 'fa-dashboard',   'label' => 'Dashboard',      'url' => 'events'],
    'events'        => ['icon' => 'fa-calendar-o',  'label' => 'Events',         'url' => 'events/list'],
    'calendar'      => ['icon' => 'fa-calendar',    'label' => 'Calendar',       'url' => 'events/calendar'],
    'participation' => ['icon' => 'fa-users',        'label' => 'Participation',  'url' => 'events/participation'],
];
$at = $active_tab ?? 'dashboard';
$ev_can_edit   = function_exists('has_permission') ? has_permission('Events','edit')   : true;
$ev_can_manage = function_exists('has_permission') ? has_permission('Events','manage') : true;
?>
<style>
.ev-wrap{padding:20px;max-width:1400px;margin:0 auto}
.ev-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.ev-header-icon{font-family:var(--font-b);font-size:1.3rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px}
.ev-header-icon i{color:var(--gold);font-size:1.1rem}
.ev-breadcrumb{list-style:none;display:flex;gap:6px;font-size:12px;color:var(--t3);padding:0;margin:6px 0 0;font-family:var(--font-b)}
.ev-breadcrumb a{color:var(--gold);text-decoration:none}
.ev-breadcrumb li+li::before{content:">";margin-right:6px;color:var(--t4)}
.ev-tabs{display:flex;gap:4px;margin-bottom:24px;border-bottom:1px solid var(--border);overflow-x:auto}
.ev-tab{padding:10px 16px;font-size:13px;font-weight:600;color:var(--t3);text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap;transition:all var(--ease);font-family:var(--font-b)}
.ev-tab:hover{color:var(--t1)} .ev-tab.active{color:var(--gold);border-bottom-color:var(--gold)} .ev-tab i{margin-right:6px;font-size:14px}
.ev-card{background:var(--card,var(--bg2));border:1px solid var(--border);border-radius:var(--r,10px);padding:20px;margin-bottom:18px}
.ev-card-title{font-family:var(--font-b);font-size:14px;font-weight:700;color:var(--t1);margin-bottom:14px;display:flex;align-items:center;justify-content:space-between}
.ev-stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:20px}
.ev-stat{text-align:center;padding:18px;background:var(--bg3,#F7ECE7);border-radius:var(--r,10px);border:1px solid var(--border)}
.ev-stat-val{font-size:26px;font-weight:700;font-family:var(--font-b);line-height:1}
.ev-stat-lbl{font-size:11px;color:var(--t3);margin-top:4px;font-family:var(--font-b)}
.ev-table{width:100%;border-collapse:collapse;font-size:13px;font-family:var(--font-b)}
.ev-table th,.ev-table td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--border)}
.ev-table th{color:var(--t3);font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.04em}
.ev-table td{color:var(--t1)} .ev-table tr:hover td{background:var(--gold-dim)}
.ev-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;font-family:var(--font-b)}
.ev-badge-blue{background:rgba(59,130,246,.12);color:#3b82f6}
.ev-badge-green{background:rgba(34,197,94,.12);color:#22c55e}
.ev-badge-amber{background:rgba(245,158,11,.12);color:#f59e0b}
.ev-badge-rose{background:rgba(239,68,68,.12);color:#ef4444}
.ev-badge-purple{background:rgba(139,92,246,.12);color:#8b5cf6}
.ev-badge-gray{background:rgba(156,163,175,.12);color:#9ca3af}
.ev-empty{text-align:center;padding:40px 20px;color:var(--t3);font-family:var(--font-b);overflow:hidden}
.ev-empty i{font-size:22px;display:inline-block;margin-bottom:8px;opacity:.5;vertical-align:middle}
.ev-empty .ev-load-text{display:block;font-size:12px;margin-top:4px;color:var(--t3)}
/* Toast base styles inherited from header.php */
.ev-toast.success{background:#22c55e;display:block}.ev-toast.error{background:#ef4444;display:block}
.ev-stat-val{transition:color .2s} .ev-stat-val.ev-skel{color:transparent!important}
@keyframes ev-loadbar-slide{0%{left:-40%;width:40%}50%{width:58%}100%{left:100%;width:40%}}@keyframes ev-spin{to{transform:rotate(360deg)}}@keyframes ev-shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
.ev-loadbar{position:fixed;top:0;left:0;right:0;height:3px;z-index:99999;background:var(--gold-dim,rgba(188,90,60,.15));overflow:hidden;opacity:0;transition:opacity .18s;pointer-events:none}.ev-loadbar.on{opacity:1}
.ev-loadbar:before{content:'';position:absolute;top:0;height:100%;width:40%;left:-40%;border-radius:3px;background:linear-gradient(90deg,transparent,var(--gold,#BC5A3C),transparent);animation:ev-loadbar-slide 1.1s ease-in-out infinite}
.ev-spin{display:inline-block;width:14px;height:14px;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;animation:ev-spin .7s linear infinite;vertical-align:-2px}
.is-loading{position:relative;color:transparent!important;pointer-events:none;opacity:.9}.is-loading:after{content:'';position:absolute;top:50%;left:50%;width:15px;height:15px;margin:-8px 0 0 -8px;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;color:#fff;animation:ev-spin .7s linear infinite}.ev-btn-outline.is-loading:after,.ev-btn-ghost.is-loading:after{color:var(--gold,#BC5A3C)}
.ev-rel{position:relative}.ev-loading-overlay{position:absolute;inset:0;z-index:20;display:flex;align-items:center;justify-content:center;gap:10px;background:rgba(127,127,127,.06);font-size:13px;font-weight:650;color:var(--t2)}.ev-loading-overlay .ev-spin{width:20px;height:20px;color:var(--gold,#BC5A3C)}
.ev-skel{background:linear-gradient(90deg,var(--bg2,#f1f1f1) 25%,var(--bg3,#e7e7e7) 37%,var(--bg2,#f1f1f1) 63%);background-size:200% 100%;animation:ev-shimmer 1.3s ease-in-out infinite;border-radius:8px}.ev-skel-row{height:14px;margin:8px 0}
.ev-ro-banner{display:flex;align-items:center;gap:9px;padding:10px 14px;margin:0 0 12px;border-radius:10px;font-size:13px;font-weight:600;background:rgba(37,99,235,.08);color:#1d4ed8;border:1px solid rgba(37,99,235,.2)}
@media(prefers-reduced-motion:reduce){.ev-loadbar:before,.ev-spin,.is-loading:after,.ev-skel{animation:none}}
</style>

<div class="ev-loadbar" id="evLoadbar"></div>
<div class="content-wrapper"><section class="content"><div class="ev-wrap">
<div class="ev-header"><div>
    <div class="ev-header-icon"><i class="fa fa-calendar-check-o"></i> Events &amp; Activities</div>
    <ol class="ev-breadcrumb"><li><a href="<?= base_url('admin') ?>">Dashboard</a></li><li>Events</li></ol>
</div></div>

<nav class="ev-tabs">
    <?php foreach ($tabs as $slug => $t): ?>
    <a class="ev-tab<?= $at === $slug ? ' active' : '' ?>" href="<?= base_url($t['url']) ?>"><i class="fa <?= $t['icon'] ?>"></i> <?= $t['label'] ?></a>
    <?php endforeach; ?>
</nav>

<!-- Stats -->
<div class="ev-stat-grid" id="evStats">
    <div class="ev-stat"><div class="ev-stat-val ev-skel" id="stTotal" style="color:var(--t1);width:44px;height:26px;margin:2px auto 0">--</div><div class="ev-stat-lbl">Total Events</div></div>
    <div class="ev-stat"><div class="ev-stat-val ev-skel" id="stUpcoming" style="color:#3b82f6;width:44px;height:26px;margin:2px auto 0">--</div><div class="ev-stat-lbl">Upcoming</div></div>
    <div class="ev-stat"><div class="ev-stat-val ev-skel" id="stOngoing" style="color:#f59e0b;width:44px;height:26px;margin:2px auto 0">--</div><div class="ev-stat-lbl">Ongoing</div></div>
    <div class="ev-stat"><div class="ev-stat-val ev-skel" id="stCompleted" style="color:#22c55e;width:44px;height:26px;margin:2px auto 0">--</div><div class="ev-stat-lbl">Completed</div></div>
    <div class="ev-stat"><div class="ev-stat-val ev-skel" id="stCancelled" style="color:#9ca3af;width:44px;height:26px;margin:2px auto 0">--</div><div class="ev-stat-lbl">Cancelled</div></div>
</div>

<!-- Upcoming Events -->
<div class="ev-card">
    <div class="ev-card-title"><span>Upcoming Events</span></div>
    <table class="ev-table"><thead><tr><th>Title</th><th>Category</th><th>Date</th><th>Location</th><th>Status</th></tr></thead>
    <tbody id="upcomingTbody"><tr><td colspan="5"><div class="ev-skel ev-skel-row" style="width:88%"></div><div class="ev-skel ev-skel-row" style="width:72%"></div><div class="ev-skel ev-skel-row" style="width:80%"></div></td></tr></tbody></table>
</div>

<!-- Recent Participants -->
<div class="ev-card">
    <div class="ev-card-title"><span>Recent Registrations</span></div>
    <table class="ev-table"><thead><tr><th>Name</th><th>Type</th><th>Event</th><th>Status</th><th>Registered</th></tr></thead>
    <tbody id="recentPTbody"><tr><td colspan="5"><div class="ev-skel ev-skel-row" style="width:82%"></div><div class="ev-skel ev-skel-row" style="width:68%"></div><div class="ev-skel ev-skel-row" style="width:76%"></div></td></tr></tbody></table>
</div>

</div></section></div>

<div class="ev-toast" id="evToast"></div>

<script>
var EV = EV || {};
EV.BASE = '<?= base_url() ?>';
EV.toast = function(msg,type){var t=document.getElementById('evToast');t.textContent=msg;t.className='ev-toast '+(type||'success');setTimeout(function(){t.className='ev-toast';},3000);};
EV.esc = function(s){var d=document.createElement('span');d.textContent=s||'';return d.innerHTML;};
EV.escJs = function(s){return String(s||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'\\"').replace(/</g,'\\x3c').replace(/>/g,'\\x3e');};
EV.ajax = function(url,data,cb,method){$.ajax({url:EV.BASE+url,type:method||'GET',data:data,dataType:'json',success:function(r){if(r.status==='success'){if(cb)cb(r);}else EV.toast(r.message||'Error','error');},error:function(xhr){var m='Error';try{m=JSON.parse(xhr.responseText).message||m;}catch(e){}EV.toast(m,'error');}});};

var DASH = {};
DASH.catBadge = function(c){ var m={event:'ev-badge-blue',cultural:'ev-badge-purple',sports:'ev-badge-amber'}; return m[c]||'ev-badge-blue'; };
DASH.statusBadge = function(s){ var m={scheduled:'ev-badge-blue',ongoing:'ev-badge-amber',completed:'ev-badge-green',cancelled:'ev-badge-gray'}; return m[s]||'ev-badge-blue'; };
DASH.pStatusBadge = function(s){ var m={registered:'ev-badge-blue',attended:'ev-badge-green',absent:'ev-badge-rose'}; return m[s]||'ev-badge-blue'; };

DASH.setStat = function(id, val) {
    var el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('ev-skel');
    el.style.width = ''; el.style.height = ''; el.style.margin = '';
    el.textContent = (val == null ? 0 : val);
};

DASH.clearSpinners = function(msg) {
    DASH.setStat('stTotal', 0);
    DASH.setStat('stUpcoming', 0);
    DASH.setStat('stOngoing', 0);
    DASH.setStat('stCompleted', 0);
    DASH.setStat('stCancelled', 0);
    var emptyRow = '<tr><td colspan="5" class="ev-empty"><i class="fa fa-calendar-o"></i> ' + EV.esc(msg || 'No data available') + '</td></tr>';
    document.getElementById('upcomingTbody').innerHTML = emptyRow;
    document.getElementById('recentPTbody').innerHTML = emptyRow;
};

DASH.load = function() {
    $.ajax({
        url: EV.BASE + 'events/get_dashboard',
        type: 'GET',
        dataType: 'json',
        success: function(r) {
            if (!r || r.status !== 'success') {
                DASH.clearSpinners(r && r.message ? r.message : 'Failed to load dashboard');
                EV.toast(r && r.message ? r.message : 'Error loading dashboard', 'error');
                return;
            }
            DASH.setStat('stTotal', r.total || 0);
            DASH.setStat('stUpcoming', r.upcoming || 0);
            DASH.setStat('stOngoing', r.ongoing || 0);
            DASH.setStat('stCompleted', r.completed || 0);
            DASH.setStat('stCancelled', r.cancelled || 0);

            var list = r.upcoming_events || [];
            var html = '';
            if (!list.length) {
                html = '<tr><td colspan="5" class="ev-empty"><i class="fa fa-calendar-o"></i> No upcoming events</td></tr>';
            } else {
                list.forEach(function(e) {
                    html += '<tr>' +
                        '<td><strong>' + EV.esc(e.title) + '</strong></td>' +
                        '<td><span class="ev-badge ' + DASH.catBadge(e.category) + '">' + EV.esc(e.category) + '</span></td>' +
                        '<td style="font-size:12px">' + EV.esc(e.start_date||'') + (e.end_date && e.end_date !== e.start_date ? ' - ' + EV.esc(e.end_date) : '') + '</td>' +
                        '<td style="font-size:12px">' + EV.esc(e.location||'') + '</td>' +
                        '<td><span class="ev-badge ' + DASH.statusBadge(e.status) + '">' + EV.esc(e.status) + '</span></td>' +
                        '</tr>';
                });
            }
            document.getElementById('upcomingTbody').innerHTML = html;

            var pList = r.recent_participants || [];
            var pHtml = '';
            if (!pList.length) {
                pHtml = '<tr><td colspan="5" class="ev-empty"><i class="fa fa-users"></i> No recent registrations</td></tr>';
            } else {
                pList.forEach(function(p) {
                    pHtml += '<tr>' +
                        '<td>' + EV.esc(p.name||'') + '</td>' +
                        '<td>' + EV.esc(p.participant_type||'') + '</td>' +
                        '<td style="font-size:12px">' + EV.esc(p.event_id||'') + '</td>' +
                        '<td><span class="ev-badge ' + DASH.pStatusBadge(p.status) + '">' + EV.esc(p.status||'') + '</span></td>' +
                        '<td style="font-size:12px">' + EV.esc((p.registration_date||'').substring(0,10)) + '</td>' +
                        '</tr>';
                });
            }
            document.getElementById('recentPTbody').innerHTML = pHtml;
        },
        error: function(xhr) {
            var m = 'Failed to load dashboard';
            try { m = JSON.parse(xhr.responseText).message || m; } catch(e) {}
            DASH.clearSpinners(m);
            EV.toast(m, 'error');
        }
    });
};

$(document).ajaxStart(function(){ $('#evLoadbar').addClass('on'); }).ajaxStop(function(){ $('#evLoadbar').removeClass('on'); });
document.addEventListener('DOMContentLoaded', function(){ DASH.load(); });
</script>
