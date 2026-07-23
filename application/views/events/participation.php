<?php $ev_can_edit = function_exists('has_permission') ? has_permission('Events','edit') : true;
      $ev_can_manage = function_exists('has_permission') ? has_permission('Events','manage') : true; ?>
<?php
$tabs = [
    'dashboard'     => ['icon' => 'fa-dashboard',   'label' => 'Dashboard',      'url' => 'events'],
    'events'        => ['icon' => 'fa-calendar-o',  'label' => 'Events',         'url' => 'events/list'],
    'calendar'      => ['icon' => 'fa-calendar',    'label' => 'Calendar',       'url' => 'events/calendar'],
    'participation' => ['icon' => 'fa-users',        'label' => 'Participation',  'url' => 'events/participation'],
];
$at = $active_tab ?? 'participation';
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
.ev-card-title{font-family:var(--font-b);font-size:14px;font-weight:700;color:var(--t1);margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
.ev-btn{padding:8px 16px;border-radius:var(--r-sm,6px);font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all var(--ease);font-family:var(--font-b)}
.ev-btn-primary{background:var(--gold);color:#fff} .ev-btn-primary:hover{background:var(--gold2)}
.ev-btn-danger{background:var(--rose,#ef4444);color:#fff} .ev-btn-sm{padding:6px 12px;font-size:12px}
.ev-btn-outline{background:transparent;border:1px solid var(--gold);color:var(--gold)} .ev-btn-outline:hover{background:var(--gold-dim)}
.ev-btn-success{background:#22c55e;color:#fff} .ev-btn-success:hover{background:#16a34a}
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
.ev-empty{text-align:center;padding:40px 20px;color:var(--t3);font-family:var(--font-b);overflow:hidden}
.ev-empty i{font-size:22px;display:inline-block;margin-bottom:8px;opacity:.5;vertical-align:middle}
.ev-empty .ev-load-text{display:block;font-size:12px;margin-top:4px;color:var(--t3)}
/* Modal/toast/form styles inherited from header.php global definitions */
.ev-modal{width:500px}
.ev-search-results{border:1px solid var(--border);border-radius:8px;max-height:180px;overflow-y:auto;display:none;margin-top:6px;background:var(--bg2)}
.ev-search-item{padding:10px 14px;cursor:pointer;font-size:14px;font-family:var(--font-b);border-bottom:1px solid var(--border);color:var(--t1)}
.ev-search-item:hover{background:var(--gold-dim)}
.ev-search-item:last-child{border-bottom:none}
.ev-toast.success{background:#22c55e;display:block}.ev-toast.error{background:#ef4444;display:block}
.ev-tablewrap{overflow-x:auto;-webkit-overflow-scrolling:touch;border-radius:8px}
.ev-btn:disabled,.ev-btn[disabled]{opacity:.4;cursor:not-allowed;pointer-events:none;filter:grayscale(.2)}
.ev-actions{display:flex;gap:6px;flex-wrap:wrap}
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

<div class="content-wrapper"><section class="content"><div class="ev-wrap">
<div class="ev-loadbar" id="evLoadbar"></div>
<?php if (!$ev_can_edit): ?>
<div class="ev-ro-banner"><i class="fa fa-eye"></i> View-only access: you can view participants but cannot add, remove, or mark attendance.</div>
<?php endif; ?>
<div class="ev-header"><div>
    <div class="ev-header-icon"><i class="fa fa-calendar-check-o"></i> Events &amp; Activities</div>
    <ol class="ev-breadcrumb"><li><a href="<?= base_url('admin') ?>">Dashboard</a></li><li><a href="<?= base_url('events') ?>">Events</a></li><li>Participation</li></ol>
</div></div>

<nav class="ev-tabs">
    <?php foreach ($tabs as $slug => $t): ?>
    <a class="ev-tab<?= $at === $slug ? ' active' : '' ?>" href="<?= base_url($t['url']) ?>"><i class="fa <?= $t['icon'] ?>"></i> <?= $t['label'] ?></a>
    <?php endforeach; ?>
</nav>

<!-- Event Selector -->
<div class="ev-card">
    <div class="ev-card-title"><span>Select Event</span></div>
    <select id="pEventSelect" style="width:100%;padding:10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--t1);font-size:14px;font-family:var(--font-b)">
        <option value="">-- Select an event --</option>
    </select>
</div>

<!-- Participants -->
<div class="ev-card" id="pCard" style="display:none">
    <div class="ev-card-title">
        <span id="pCardTitle">Participants</span>
        <div style="display:flex;gap:8px">
            <button class="ev-btn ev-btn-sm ev-btn-primary" onclick="PART.openModal()"<?= $ev_can_edit ? '' : ' disabled title="You do not have permission to add participants"' ?>><i class="fa fa-plus"></i> Add Participant</button>
            <button class="ev-btn ev-btn-sm ev-btn-outline" onclick="PART.load()"><i class="fa fa-refresh"></i></button>
        </div>
    </div>
    <div class="ev-tablewrap">
    <table class="ev-table"><thead><tr><th>Name</th><th>Type</th><th>Class / Section</th><th>Status</th><th>Registered</th><th>Actions</th></tr></thead>
    <tbody id="pTbody"><tr><td colspan="6" class="ev-empty"><i class="fa fa-spinner fa-spin"></i><span class="ev-load-text">Loading...</span></td></tr></tbody></table>
    </div>
</div>

</div></section></div>

<!-- Add Participant Modal -->
<div class="ev-modal-bg" id="pModal"><div class="ev-modal">
    <div class="ev-modal-title"><span>Add Participant</span><button class="ev-modal-close" onclick="PART.closeModal()">&times;</button></div>
    <div class="ev-form-group">
        <label>Search Student / Teacher</label>
        <input type="text" id="pSearch" placeholder="Type name or ID..." autocomplete="off">
        <div class="ev-search-results" id="pSearchResults"></div>
    </div>
    <input type="hidden" id="pId">
    <input type="hidden" id="pType">
    <div class="ev-form-group"><label>Selected</label><input type="text" id="pName" readonly placeholder="Search and select above"></div>
    <div id="pClassFields">
        <div class="ev-form-group"><label>Class</label><input type="text" id="pClass" readonly></div>
        <div class="ev-form-group"><label>Section</label><input type="text" id="pSection" readonly></div>
    </div>
    <button id="pSaveBtn" class="ev-btn ev-btn-primary" onclick="PART.save()" style="width:100%;margin-top:8px"<?= $ev_can_edit ? '' : ' disabled title="No permission"' ?>>Register Participant</button>
</div></div>

<div class="ev-toast" id="evToast"></div>

<script>
var EV = EV || {};
EV.BASE = '<?= base_url() ?>';
EV.canEdit = <?= $ev_can_edit ? 'true' : 'false' ?>;
EV.canManage = <?= $ev_can_manage ? 'true' : 'false' ?>;
EV.toast = function(msg,type){var t=document.getElementById('evToast');t.textContent=msg;t.className='ev-toast '+(type||'success');setTimeout(function(){t.className='ev-toast';},3000);};
EV.esc = function(s){var d=document.createElement('span');d.textContent=s||'';return d.innerHTML;};
EV.escJs = function(s){return String(s||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'\\"').replace(/</g,'\\x3c').replace(/>/g,'\\x3e');};
EV.errMsg = function(xhr){ return (xhr && xhr.responseJSON && xhr.responseJSON.message) || (function(){try{return JSON.parse(xhr.responseText).message;}catch(e){return null;}})() || 'Request failed'; };
// Fail-closed: only invoke the callback after the server confirms success.
EV.ajax = function(url,data,cb,method){$.ajax({url:EV.BASE+url,type:method||'GET',data:data,dataType:'json',
    success:function(r){ if(!r || r.status==='error' || r.success===false || r.status!=='success'){ EV.toast((r&&r.message)||'Request failed','error'); return; } if(cb)cb(r); },
    error:function(xhr){ EV.toast(EV.errMsg(xhr),'error'); }});};
EV.btnBusy = function(el,on){ if(!el)return; if(on){el.classList.add('is-loading');el.disabled=true;} else {el.classList.remove('is-loading');el.disabled=false;} };
EV.skel = function(tbodyId,cols,rows){ var h='',i,j; rows=rows||5; for(i=0;i<rows;i++){ h+='<tr>'; for(j=0;j<cols;j++){ h+='<td><div class="ev-skel ev-skel-row"></div></td>'; } h+='</tr>'; } var b=document.getElementById(tbodyId); if(b)b.innerHTML=h; };
$(document).ajaxStart(function(){$('#evLoadbar').addClass('on')}).ajaxStop(function(){$('#evLoadbar').removeClass('on')});

var PART = {};
PART.eventId = '';
PART.searchTimer = null;

PART.pStatusBadge = function(s){ var m={registered:'ev-badge-blue',attended:'ev-badge-green',absent:'ev-badge-rose'}; return m[s]||'ev-badge-blue'; };

PART.loadEvents = function() {
    $.ajax({
        url: EV.BASE + 'events/get_events',
        type: 'GET',
        dataType: 'json',
        success: function(r) {
            var sel = document.getElementById('pEventSelect');
            if (!r || r.status !== 'success') {
                sel.innerHTML = '<option value="">-- Error loading events --</option>';
                return;
            }
            var opts = '<option value="">-- Select an event --</option>';
            (r.events||[]).forEach(function(e) {
                var catLabel = {event:'Event',cultural:'Cultural',sports:'Sports'};
                opts += '<option value="' + EV.esc(e.id) + '">[' + EV.esc(catLabel[e.category]||e.category) + '] ' + EV.esc(e.title) + ' (' + EV.esc(e.start_date) + ')</option>';
            });
            sel.innerHTML = opts;
        },
        error: function(xhr) {
            document.getElementById('pEventSelect').innerHTML = '<option value="">-- Error loading events --</option>';
            var m = 'Failed to load events'; try { m = JSON.parse(xhr.responseText).message || m; } catch(e) {}
            EV.toast(m, 'error');
        }
    });
};

PART.onEventChange = function() {
    PART.eventId = document.getElementById('pEventSelect').value;
    if (PART.eventId) {
        document.getElementById('pCard').style.display = '';
        PART.load();
    } else {
        document.getElementById('pCard').style.display = 'none';
    }
};

PART.load = function() {
    if (!PART.eventId) return;
    EV.skel('pTbody', 6);
    $.ajax({
        url: EV.BASE + 'events/get_participants',
        type: 'GET',
        data: {event_id: PART.eventId},
        dataType: 'json',
        success: function(r) {
            if (!r || r.status !== 'success') {
                document.getElementById('pTbody').innerHTML = '<tr><td colspan="6" class="ev-empty"><i class="fa fa-exclamation-circle"></i> ' + EV.esc(r && r.message ? r.message : 'Failed to load participants') + '</td></tr>';
                return;
            }
            var ev = r.event || {};
            document.getElementById('pCardTitle').textContent = 'Participants — ' + (ev.title||PART.eventId) + ' (' + (r.total||0) + (ev.max_participants > 0 ? '/' + ev.max_participants : '') + ')';
            var list = r.participants || [];
            var html = '';
            if (!list.length) {
                html = '<tr><td colspan="6" class="ev-empty"><i class="fa fa-users"></i> No participants registered</td></tr>';
            } else {
                list.forEach(function(p) {
                    html += '<tr>' +
                        '<td><strong>' + EV.esc(p.name) + '</strong><div style="font-size:11px;color:var(--t3)">' + EV.esc(p.participant_id||p.id) + '</div></td>' +
                        '<td><span class="ev-badge ' + (p.participant_type==='teacher'?'ev-badge-purple':'ev-badge-blue') + '">' + EV.esc(p.participant_type) + '</span></td>' +
                        '<td style="font-size:12px">' + EV.esc(p.class||'') + (p.section ? ' / ' + EV.esc(p.section) : '') + '</td>' +
                        '<td><span class="ev-badge ' + PART.pStatusBadge(p.status) + '">' + EV.esc(p.status) + '</span></td>' +
                        '<td style="font-size:12px">' + EV.esc((p.registration_date||'').substring(0,10)) + '</td>' +
                        '<td><div class="ev-actions">' +
                            '<button class="ev-btn ev-btn-sm ev-btn-success" onclick="PART.markAttendance(\'' + EV.escJs(p.id) + '\',\'attended\',this)" title="Mark Attended"' + (EV.canEdit ? '' : ' disabled') + '><i class="fa fa-check"></i></button>' +
                            '<button class="ev-btn ev-btn-sm ev-btn-outline" onclick="PART.markAttendance(\'' + EV.escJs(p.id) + '\',\'absent\',this)" title="Mark Absent"' + (EV.canEdit ? '' : ' disabled') + '><i class="fa fa-times"></i></button>' +
                            '<button class="ev-btn ev-btn-sm ev-btn-danger" onclick="PART.remove(\'' + EV.escJs(p.id) + '\',this)" title="Remove"' + (EV.canEdit ? '' : ' disabled') + '><i class="fa fa-trash"></i></button>' +
                        '</div></td></tr>';
                });
            }
            document.getElementById('pTbody').innerHTML = html;
        },
        error: function(xhr) {
            var m = 'Failed to load participants'; try { m = JSON.parse(xhr.responseText).message || m; } catch(e) {}
            document.getElementById('pTbody').innerHTML = '<tr><td colspan="6" class="ev-empty"><i class="fa fa-exclamation-circle"></i> ' + EV.esc(m) + '</td></tr>';
            EV.toast(m, 'error');
        }
    });
};

PART.openModal = function() {
    document.getElementById('pSearch').value = '';
    document.getElementById('pId').value = '';
    document.getElementById('pType').value = '';
    document.getElementById('pName').value = '';
    document.getElementById('pClass').value = '';
    document.getElementById('pSection').value = '';
    document.getElementById('pSearchResults').style.display = 'none';
    document.getElementById('pClassFields').style.display = '';
    document.getElementById('pModal').classList.add('show');
};
PART.closeModal = function() { document.getElementById('pModal').classList.remove('show'); };

PART._searchResults = [];
PART._bindSearch = function() {
    // Event delegation for search results — avoids inline onclick with user data
    document.getElementById('pSearchResults').addEventListener('click', function(ev) {
        var item = ev.target.closest('.ev-search-item');
        if (!item) return;
        var idx = parseInt(item.getAttribute('data-idx'), 10);
        var p = PART._searchResults[idx];
        if (p) PART.selectPerson(p.id, p.type, p.name, p.class||'', p.section||'');
    });
    document.getElementById('pSearch').addEventListener('input', function() {
        var q = this.value.trim();
        clearTimeout(PART.searchTimer);
        if (q.length < 2) { document.getElementById('pSearchResults').style.display = 'none'; return; }
        PART.searchTimer = setTimeout(function() {
            EV.ajax('events/search_people', {q:q}, function(r) {
                var res = r.results || [];
                var box = document.getElementById('pSearchResults');
                if (!res.length) { box.innerHTML = '<div class="ev-search-item" style="color:var(--t3)">No results</div>'; box.style.display = 'block'; return; }
                var html = '';
                res.forEach(function(p, idx) {
                    html += '<div class="ev-search-item" data-idx="' + idx + '">' + EV.esc(p.label) + '</div>';
                });
                // Store results for delegation — avoids inline JS with user-controlled data
                PART._searchResults = res;
                box.innerHTML = html;
                box.style.display = 'block';
            });
        }, 300);
    });
};

PART.selectPerson = function(id, type, name, cls, sec) {
    document.getElementById('pId').value = id;
    document.getElementById('pType').value = type;
    document.getElementById('pName').value = name;
    document.getElementById('pClass').value = cls;
    document.getElementById('pSection').value = sec;
    document.getElementById('pSearchResults').style.display = 'none';
    document.getElementById('pSearch').value = '';
    // Hide class/section for teachers
    document.getElementById('pClassFields').style.display = (type === 'teacher') ? 'none' : '';
};

PART._saving = false;
PART.save = function() {
    if (PART._saving) return;
    if (!EV.canEdit) { EV.toast('You do not have permission to add participants','error'); return; }
    var data = {
        event_id: PART.eventId,
        participant_id: document.getElementById('pId').value,
        participant_type: document.getElementById('pType').value,
        name: document.getElementById('pName').value,
        class: document.getElementById('pClass').value,
        section: document.getElementById('pSection').value,
        status: 'registered'
    };
    if (!data.participant_id || !data.name) { EV.toast('Select a participant first','error'); return; }
    var btn = document.getElementById('pSaveBtn');
    PART._saving = true; EV.btnBusy(btn, true);
    $.ajax({ url: EV.BASE + 'events/save_participant', type: 'POST', data: data, dataType: 'json',
        success: function(r) {
            if (!r || r.status === 'error' || r.success === false || r.status !== 'success') { EV.toast((r && r.message) || 'Request failed','error'); return; }
            EV.toast(r.message||'Registered'); PART.closeModal(); PART.load();
        },
        error: function(xhr) { EV.toast(EV.errMsg(xhr),'error'); },
        complete: function() { PART._saving = false; EV.btnBusy(btn, false); }
    });
};

PART.markAttendance = function(pid, status, btn) {
    if (!EV.canEdit) { EV.toast('You do not have permission to mark attendance','error'); return; }
    EV.btnBusy(btn, true);
    $.ajax({ url: EV.BASE + 'events/mark_attendance', type: 'POST', data: {event_id: PART.eventId, participant_id: pid, status: status}, dataType: 'json',
        success: function(r) {
            if (!r || r.status === 'error' || r.success === false || r.status !== 'success') { EV.toast((r && r.message) || 'Request failed','error'); return; }
            EV.toast(r.message||'Updated'); PART.load();
        },
        error: function(xhr) { EV.toast(EV.errMsg(xhr),'error'); },
        complete: function() { EV.btnBusy(btn, false); }
    });
};

PART.remove = function(pid, btn) {
    if (!EV.canEdit) { EV.toast('You do not have permission to remove participants','error'); return; }
    if (!confirm('Remove this participant?')) return;
    EV.btnBusy(btn, true);
    $.ajax({ url: EV.BASE + 'events/remove_participant', type: 'POST', data: {event_id: PART.eventId, participant_id: pid}, dataType: 'json',
        success: function(r) {
            if (!r || r.status === 'error' || r.success === false || r.status !== 'success') { EV.toast((r && r.message) || 'Request failed','error'); return; }
            EV.toast(r.message||'Removed'); PART.load();
        },
        error: function(xhr) { EV.toast(EV.errMsg(xhr),'error'); },
        complete: function() { EV.btnBusy(btn, false); }
    });
};

document.addEventListener('DOMContentLoaded', function() {
    PART.loadEvents();
    PART._bindSearch();
    document.getElementById('pEventSelect').addEventListener('change', PART.onEventChange);
});
</script>
