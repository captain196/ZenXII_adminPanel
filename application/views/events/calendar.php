<?php
$tabs = [
    'dashboard'     => ['icon' => 'fa-dashboard',   'label' => 'Dashboard',      'url' => 'events'],
    'events'        => ['icon' => 'fa-calendar-o',  'label' => 'Events',         'url' => 'events/list'],
    'calendar'      => ['icon' => 'fa-calendar',    'label' => 'Calendar',       'url' => 'events/calendar'],
    'participation' => ['icon' => 'fa-users',        'label' => 'Participation',  'url' => 'events/participation'],
];
$at = $active_tab ?? 'calendar';
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
.ev-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;font-family:var(--font-b)}
.ev-badge-blue{background:rgba(59,130,246,.12);color:#3b82f6}
.ev-badge-green{background:rgba(34,197,94,.12);color:#22c55e}
.ev-badge-amber{background:rgba(245,158,11,.12);color:#f59e0b}
.ev-badge-purple{background:rgba(139,92,246,.12);color:#8b5cf6}
.ev-badge-gray{background:rgba(156,163,175,.12);color:#9ca3af}
/* Toast base styles inherited from header.php */
.ev-toast.success{background:#22c55e;display:block}.ev-toast.error{background:#ef4444;display:block}

/* Calendar */
.cal-nav{display:flex;align-items:center;justify-content:center;gap:16px;margin-bottom:20px;font-family:var(--font-b)}
.cal-nav-btn{background:var(--gold);color:#fff;border:none;border-radius:6px;padding:6px 14px;font-size:13px;font-weight:600;cursor:pointer;font-family:var(--font-b)}
.cal-nav-btn:hover{background:var(--gold2)}
.cal-title{font-size:18px;font-weight:700;color:var(--t1);min-width:180px;text-align:center}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:1px;background:var(--border);border:1px solid var(--border);border-radius:var(--r,10px);overflow:hidden}
.cal-head{background:var(--gold);color:#fff;padding:10px 4px;text-align:center;font-size:12px;font-weight:700;font-family:var(--font-b)}
.cal-cell{background:var(--bg2);min-height:90px;padding:6px;position:relative;cursor:default}
.cal-cell.other{background:var(--bg);opacity:.5}
.cal-cell.today{box-shadow:inset 0 0 0 2px var(--gold)}
.cal-day{font-size:12px;font-weight:600;color:var(--t2);font-family:var(--font-b);margin-bottom:4px}
.cal-evt{font-size:10px;padding:2px 6px;border-radius:4px;margin-bottom:2px;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-family:var(--font-b);font-weight:600;position:relative}
.cal-evt.cat-event{background:rgba(59,130,246,.15);color:#3b82f6}
.cal-evt.cat-cultural{background:rgba(139,92,246,.15);color:#8b5cf6}
.cal-evt.cat-sports{background:rgba(245,158,11,.15);color:#f59e0b}
.cal-tooltip{display:none;position:absolute;bottom:calc(100% + 4px);left:0;background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:10px 14px;min-width:200px;box-shadow:0 4px 16px rgba(0,0,0,.15);z-index:100;font-family:var(--font-b)}
.cal-evt:hover .cal-tooltip{display:block}
.cal-tooltip-title{font-size:13px;font-weight:700;color:var(--t1);margin-bottom:4px}
.cal-tooltip-meta{font-size:11px;color:var(--t3)}

/* Detail modal — base styles inherited from header.php global definitions */
.ev-modal{width:500px}
.ev-detail-row{display:flex;gap:8px;margin-bottom:10px;font-size:14px;font-family:var(--font-b)}
.ev-detail-label{color:var(--t3);min-width:100px;font-weight:600}
.ev-detail-val{color:var(--t1)}
</style>

<div class="content-wrapper"><section class="content"><div class="ev-wrap">
<div class="ev-header"><div>
    <div class="ev-header-icon"><i class="fa fa-calendar-check-o"></i> Events &amp; Activities</div>
    <ol class="ev-breadcrumb"><li><a href="<?= base_url('admin') ?>">Dashboard</a></li><li><a href="<?= base_url('events') ?>">Events</a></li><li>Calendar</li></ol>
</div></div>

<nav class="ev-tabs">
    <?php foreach ($tabs as $slug => $t): ?>
    <a class="ev-tab<?= $at === $slug ? ' active' : '' ?>" href="<?= base_url($t['url']) ?>"><i class="fa <?= $t['icon'] ?>"></i> <?= $t['label'] ?></a>
    <?php endforeach; ?>
</nav>

<div class="ev-card">
    <div class="cal-nav">
        <button class="cal-nav-btn" onclick="CAL.prev()"><i class="fa fa-chevron-left"></i></button>
        <div class="cal-title" id="calTitle">--</div>
        <button class="cal-nav-btn" onclick="CAL.next()"><i class="fa fa-chevron-right"></i></button>
        <button class="cal-nav-btn" onclick="CAL.today()" style="margin-left:12px">Today</button>
    </div>
    <div class="cal-grid" id="calGrid"></div>
</div>

</div></section></div>

<!-- Event Detail Modal -->
<div class="ev-modal-bg" id="evtDetailModal"><div class="ev-modal">
    <div class="ev-modal-title"><span id="detailTitle">Event Details</span><button class="ev-modal-close" onclick="CAL.closeDetail()">&times;</button></div>
    <div id="detailBody"></div>
</div></div>

<div class="ev-toast" id="evToast"></div>

<script>
var EV = EV || {};
EV.BASE = '<?= base_url() ?>';
EV.toast = function(msg,type){var t=document.getElementById('evToast');t.textContent=msg;t.className='ev-toast '+(type||'success');setTimeout(function(){t.className='ev-toast';},3000);};
EV.esc = function(s){var d=document.createElement('span');d.textContent=s||'';return d.innerHTML;};
EV.escJs = function(s){return String(s||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'\\"').replace(/</g,'\\x3c').replace(/>/g,'\\x3e');};
EV.ajax = function(url,data,cb,method){$.ajax({url:EV.BASE+url,type:method||'GET',data:data,dataType:'json',success:function(r){if(r.status==='success'){if(cb)cb(r);}else EV.toast(r.message||'Error','error');},error:function(xhr){var m='Error';try{m=JSON.parse(xhr.responseText).message||m;}catch(e){}EV.toast(m,'error');}});};

var CAL = {};
CAL.month = new Date().getMonth() + 1;
CAL.year = new Date().getFullYear();
CAL.events = [];
CAL.days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
CAL.months = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

CAL.prev = function(){ CAL.month--; if(CAL.month<1){CAL.month=12;CAL.year--;} CAL.load(); };
CAL.next = function(){ CAL.month++; if(CAL.month>12){CAL.month=1;CAL.year++;} CAL.load(); };
CAL.today = function(){ var d=new Date(); CAL.month=d.getMonth()+1; CAL.year=d.getFullYear(); CAL.load(); };

CAL.load = function() {
    document.getElementById('calTitle').textContent = CAL.months[CAL.month] + ' ' + CAL.year;
    $.ajax({
        url: EV.BASE + 'events/get_calendar',
        type: 'GET',
        data: {month:CAL.month, year:CAL.year},
        dataType: 'json',
        success: function(r) {
            if (!r || r.status !== 'success') {
                document.getElementById('calGrid').innerHTML = '<div style="grid-column:1/-1;padding:40px;text-align:center;color:var(--t3);font-family:var(--font-b)"><i class="fa fa-exclamation-circle" style="font-size:24px;display:block;margin-bottom:8px;opacity:.5"></i>' + EV.esc(r && r.message ? r.message : 'Failed to load calendar') + '</div>';
                return;
            }
            CAL.events = r.events || [];
            CAL.render();
        },
        error: function(xhr) {
            var m = 'Failed to load calendar';
            try { m = JSON.parse(xhr.responseText).message || m; } catch(e) {}
            document.getElementById('calGrid').innerHTML = '<div style="grid-column:1/-1;padding:40px;text-align:center;color:var(--t3);font-family:var(--font-b)"><i class="fa fa-exclamation-circle" style="font-size:24px;display:block;margin-bottom:8px;opacity:.5"></i>' + EV.esc(m) + '</div>';
            EV.toast(m, 'error');
        }
    });
};

CAL.render = function() {
    var grid = document.getElementById('calGrid');
    var html = '';

    // Header row
    CAL.days.forEach(function(d){ html += '<div class="cal-head">' + d + '</div>'; });

    // Calendar cells
    var firstDay = new Date(CAL.year, CAL.month - 1, 1).getDay();
    var daysInMonth = new Date(CAL.year, CAL.month, 0).getDate();
    var prevDays = new Date(CAL.year, CAL.month - 1, 0).getDate();
    var todayStr = new Date().toISOString().substring(0, 10);
    var totalCells = Math.ceil((firstDay + daysInMonth) / 7) * 7;

    for (var i = 0; i < totalCells; i++) {
        var day, cls = 'cal-cell', dateStr;
        if (i < firstDay) {
            day = prevDays - firstDay + i + 1;
            cls += ' other';
            var pm = CAL.month - 1 < 1 ? 12 : CAL.month - 1;
            var py = CAL.month - 1 < 1 ? CAL.year - 1 : CAL.year;
            dateStr = py + '-' + String(pm).padStart(2,'0') + '-' + String(day).padStart(2,'0');
        } else if (i >= firstDay + daysInMonth) {
            day = i - firstDay - daysInMonth + 1;
            cls += ' other';
            var nm = CAL.month + 1 > 12 ? 1 : CAL.month + 1;
            var ny = CAL.month + 1 > 12 ? CAL.year + 1 : CAL.year;
            dateStr = ny + '-' + String(nm).padStart(2,'0') + '-' + String(day).padStart(2,'0');
        } else {
            day = i - firstDay + 1;
            dateStr = CAL.year + '-' + String(CAL.month).padStart(2,'0') + '-' + String(day).padStart(2,'0');
            if (dateStr === todayStr) cls += ' today';
        }

        html += '<div class="' + cls + '"><div class="cal-day">' + day + '</div>';

        // Events on this date
        CAL.events.forEach(function(e) {
            if (dateStr >= e.start_date && dateStr <= (e.end_date || e.start_date)) {
                html += '<div class="cal-evt cat-' + EV.esc(e.category) + '" onclick="CAL.showDetail(\'' + EV.escJs(e.id) + '\')">' +
                    EV.esc(e.title) +
                    '<div class="cal-tooltip">' +
                        '<div class="cal-tooltip-title">' + EV.esc(e.title) + '</div>' +
                        '<div class="cal-tooltip-meta">' + EV.esc(e.start_date) + (e.end_date && e.end_date !== e.start_date ? ' - ' + EV.esc(e.end_date) : '') + '</div>' +
                        '<div class="cal-tooltip-meta">' + EV.esc(e.location||'') + '</div>' +
                    '</div>' +
                '</div>';
            }
        });
        html += '</div>';
    }
    grid.innerHTML = html;
};

CAL.showDetail = function(id) {
    EV.ajax('events/get_event', {id:id}, function(r) {
        var e = r.event;
        var catBadge = {event:'ev-badge-blue',cultural:'ev-badge-purple',sports:'ev-badge-amber'};
        var statusBadge = {scheduled:'ev-badge-blue',ongoing:'ev-badge-amber',completed:'ev-badge-green',cancelled:'ev-badge-gray'};
        document.getElementById('detailTitle').textContent = e.title || 'Event Details';
        document.getElementById('detailBody').innerHTML =
            '<div class="ev-detail-row"><div class="ev-detail-label">Category</div><div class="ev-detail-val"><span class="ev-badge ' + (catBadge[e.category]||'ev-badge-blue') + '">' + EV.esc(e.category) + '</span></div></div>' +
            '<div class="ev-detail-row"><div class="ev-detail-label">Status</div><div class="ev-detail-val"><span class="ev-badge ' + (statusBadge[e.status]||'ev-badge-blue') + '">' + EV.esc(e.status) + '</span></div></div>' +
            '<div class="ev-detail-row"><div class="ev-detail-label">Date</div><div class="ev-detail-val">' + EV.esc(e.start_date||'') + (e.end_date && e.end_date !== e.start_date ? ' to ' + EV.esc(e.end_date) : '') + '</div></div>' +
            '<div class="ev-detail-row"><div class="ev-detail-label">Location</div><div class="ev-detail-val">' + EV.esc(e.location||'N/A') + '</div></div>' +
            '<div class="ev-detail-row"><div class="ev-detail-label">Organizer</div><div class="ev-detail-val">' + EV.esc(e.organizer||'N/A') + '</div></div>' +
            '<div class="ev-detail-row"><div class="ev-detail-label">Participants</div><div class="ev-detail-val">' + (e.participant_count||0) + (e.max_participants > 0 ? ' / ' + e.max_participants : '') + '</div></div>' +
            (e.description ? '<div style="margin-top:12px;font-size:13px;color:var(--t2);font-family:var(--font-b);border-top:1px solid var(--border);padding-top:12px">' + EV.esc(e.description) + '</div>' : '');
        document.getElementById('evtDetailModal').classList.add('show');
    });
};
CAL.closeDetail = function(){ document.getElementById('evtDetailModal').classList.remove('show'); };

document.addEventListener('DOMContentLoaded', function(){ CAL.load(); });
</script>
