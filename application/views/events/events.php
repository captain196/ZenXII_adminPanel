<?php $ev_can_edit = function_exists('has_permission') ? has_permission('Events','edit') : true;
      $ev_can_manage = function_exists('has_permission') ? has_permission('Events','manage') : true; ?>
<?php
$tabs = [
    'dashboard'     => ['icon' => 'fa-dashboard',   'label' => 'Dashboard',      'url' => 'events'],
    'events'        => ['icon' => 'fa-calendar-o',  'label' => 'Events',         'url' => 'events/list'],
    'calendar'      => ['icon' => 'fa-calendar',    'label' => 'Calendar',       'url' => 'events/calendar'],
    'participation' => ['icon' => 'fa-users',        'label' => 'Participation',  'url' => 'events/participation'],
];
$at = $active_tab ?? 'events';
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
/* Modal/toast/form styles inherited from header.php global definitions */
.ev-modal{width:640px}
.ev-form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.ev-filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.ev-filter{padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid var(--border);background:transparent;color:var(--t2);font-family:var(--font-b);transition:all .15s}
.ev-filter.active,.ev-filter:hover{border-color:var(--gold);background:var(--gold-dim);color:var(--gold)}
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
<div class="ev-ro-banner"><i class="fa fa-eye"></i> View-only access: you can browse events but cannot create, edit, change status, or delete them.</div>
<?php elseif (!$ev_can_manage): ?>
<div class="ev-ro-banner"><i class="fa fa-info-circle"></i> Limited access: you can create and edit events, but deleting events requires manage permission.</div>
<?php endif; ?>
<div class="ev-header"><div>
    <div class="ev-header-icon"><i class="fa fa-calendar-check-o"></i> Events &amp; Activities</div>
    <ol class="ev-breadcrumb"><li><a href="<?= base_url('admin') ?>">Dashboard</a></li><li><a href="<?= base_url('events') ?>">Events</a></li><li>Manage Events</li></ol>
</div></div>

<nav class="ev-tabs">
    <?php foreach ($tabs as $slug => $t): ?>
    <a class="ev-tab<?= $at === $slug ? ' active' : '' ?>" href="<?= base_url($t['url']) ?>"><i class="fa <?= $t['icon'] ?>"></i> <?= $t['label'] ?></a>
    <?php endforeach; ?>
</nav>

<div class="ev-card">
    <div class="ev-card-title">
        <span>Events</span>
        <button class="ev-btn ev-btn-primary" onclick="EVT.openModal()"<?= $ev_can_edit ? '' : ' disabled title="You do not have permission to create events"' ?>><i class="fa fa-plus"></i> Create Event</button>
    </div>
    <div class="ev-filters">
        <button class="ev-filter active" onclick="EVT.filter('')" data-c="">All</button>
        <button class="ev-filter" onclick="EVT.filter('event')" data-c="event">School Events</button>
        <button class="ev-filter" onclick="EVT.filter('cultural')" data-c="cultural">Cultural</button>
        <button class="ev-filter" onclick="EVT.filter('sports')" data-c="sports">Sports</button>
    </div>
    <div class="ev-tablewrap">
    <table class="ev-table"><thead><tr><th>Title</th><th>Category</th><th>Date</th><th>Location</th><th>Organizer</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody id="evtTbody"><tr><td colspan="7" class="ev-empty"><i class="fa fa-spinner fa-spin"></i><span class="ev-load-text">Loading...</span></td></tr></tbody></table>
    </div>
</div>

</div></section></div>

<!-- Event Modal -->
<div class="ev-modal-bg" id="evtModal"><div class="ev-modal">
    <div class="ev-modal-title"><span id="evtModalTitle">Create Event</span><button class="ev-modal-close" onclick="EVT.closeModal()">&times;</button></div>
    <input type="hidden" id="evtId">
    <div class="ev-form-group"><label>Title *</label><input type="text" id="evtTitle" placeholder="e.g. Annual Sports Day" maxlength="200"></div>
    <div class="ev-form-group"><label>Description</label><textarea id="evtDesc" rows="3" placeholder="Event description..." maxlength="2000"></textarea></div>
    <div class="ev-form-row">
        <div class="ev-form-group"><label>Category *</label><select id="evtCategory">
            <option value="event">School Event</option>
            <option value="cultural">Cultural Program</option>
            <option value="sports">Sports Competition</option>
        </select></div>
        <div class="ev-form-group"><label>Status</label><select id="evtStatus">
            <option value="scheduled">Scheduled</option>
            <option value="ongoing">Ongoing</option>
            <option value="completed">Completed</option>
            <option value="cancelled">Cancelled</option>
        </select></div>
    </div>
    <div class="ev-form-row">
        <div class="ev-form-group"><label>Start Date *</label><input type="date" id="evtStartDate"></div>
        <div class="ev-form-group"><label>End Date</label><input type="date" id="evtEndDate"></div>
    </div>
    <div class="ev-form-row">
        <div class="ev-form-group"><label>Location</label><input type="text" id="evtLocation" placeholder="e.g. School Auditorium"></div>
        <div class="ev-form-group"><label>Organizer</label><input type="text" id="evtOrganizer" placeholder="e.g. Sports Department"></div>
    </div>
    <div class="ev-form-group"><label>Max Participants (0 = unlimited)</label><input type="number" id="evtMaxP" min="0" value="0"></div>
    <button id="evtSaveBtn" class="ev-btn ev-btn-primary" onclick="EVT.save()" style="width:100%;margin-top:8px">Save Event</button>
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
// Guard a mutation button while its request is in-flight.
EV.btnBusy = function(el,on){ if(!el)return; if(on){el.classList.add('is-loading');el.disabled=true;} else {el.classList.remove('is-loading');el.disabled=false;} };
// Skeleton shimmer rows while a list loads.
EV.skel = function(tbodyId,cols,rows){ var h='',i,j; rows=rows||5; for(i=0;i<rows;i++){ h+='<tr>'; for(j=0;j<cols;j++){ h+='<td><div class="ev-skel ev-skel-row"></div></td>'; } h+='</tr>'; } var b=document.getElementById(tbodyId); if(b)b.innerHTML=h; };
$(document).ajaxStart(function(){$('#evLoadbar').addClass('on')}).ajaxStop(function(){$('#evLoadbar').removeClass('on')});

var EVT = {};
EVT.data = [];
EVT.currentFilter = '';

EVT.catBadge = function(c){ var m={event:'ev-badge-blue',cultural:'ev-badge-purple',sports:'ev-badge-amber'}; return m[c]||'ev-badge-blue'; };
EVT.statusBadge = function(s){ var m={scheduled:'ev-badge-blue',ongoing:'ev-badge-amber',completed:'ev-badge-green',cancelled:'ev-badge-gray'}; return m[s]||'ev-badge-blue'; };

EVT.filter = function(cat) {
    EVT.currentFilter = cat;
    var btns = document.querySelectorAll('.ev-filter');
    btns.forEach(function(b){ b.classList.remove('active'); });
    document.querySelector('.ev-filter[data-c="'+cat+'"]').classList.add('active');
    EVT.load();
};

EVT.load = function() {
    var params = {};
    if (EVT.currentFilter) params.category = EVT.currentFilter;
    EV.skel('evtTbody', 7);
    $.ajax({
        url: EV.BASE + 'events/get_events',
        type: 'GET',
        data: params,
        dataType: 'json',
        success: function(r) {
            if (!r || r.status !== 'success') {
                document.getElementById('evtTbody').innerHTML = '<tr><td colspan="7" class="ev-empty"><i class="fa fa-exclamation-circle"></i> ' + EV.esc(r && r.message ? r.message : 'Failed to load events') + '</td></tr>';
                return;
            }
            EVT.data = r.events || [];
            var html = '';
            if (!EVT.data.length) {
                html = '<tr><td colspan="7" class="ev-empty"><i class="fa fa-calendar-o"></i> No events found</td></tr>';
            } else {
                EVT.data.forEach(function(e) {
                    html += '<tr>' +
                        '<td><strong>' + EV.esc(e.title) + '</strong>' + (e.description ? '<div style="font-size:11px;color:var(--t3);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + EV.esc(e.description.substring(0,60)) + '</div>' : '') + '</td>' +
                        '<td><span class="ev-badge ' + EVT.catBadge(e.category) + '">' + EV.esc(e.category) + '</span></td>' +
                        '<td style="font-size:12px">' + EV.esc(e.start_date||'') + (e.end_date && e.end_date !== e.start_date ? '<br>to ' + EV.esc(e.end_date) : '') + '</td>' +
                        '<td style="font-size:12px">' + EV.esc(e.location||'') + '</td>' +
                        '<td style="font-size:12px">' + EV.esc(e.organizer||'') + '</td>' +
                        '<td><span class="ev-badge ' + EVT.statusBadge(e.status) + '">' + EV.esc(e.status) + '</span></td>' +
                        '<td><div class="ev-actions"><button class="ev-btn ev-btn-sm ev-btn-outline" onclick="EVT.addPhotos(\'' + EV.escJs(e.id) + '\')" title="Add Photos"><i class="fa fa-camera"></i></button>' +
                        '<button class="ev-btn ev-btn-sm ev-btn-outline" onclick="EVT.circular(\'' + EV.escJs(e.id) + '\')" title="View Circular"><i class="fa fa-file-text-o"></i></button>' +
                        '<button class="ev-btn ev-btn-sm ev-btn-primary" onclick="EVT.edit(\'' + EV.escJs(e.id) + '\')"' + (EV.canEdit ? '' : ' disabled title="No permission to edit"') + '><i class="fa fa-pencil"></i></button>' +
                        '<button class="ev-btn ev-btn-sm ev-btn-danger" onclick="EVT.del(\'' + EV.escJs(e.id) + '\',this)"' + (EV.canManage ? '' : ' disabled title="No permission to delete"') + '><i class="fa fa-trash"></i></button></div></td>' +
                        '</tr>';
                });
            }
            document.getElementById('evtTbody').innerHTML = html;
        },
        error: function(xhr) {
            var m = 'Failed to load events';
            try { m = JSON.parse(xhr.responseText).message || m; } catch(e) {}
            document.getElementById('evtTbody').innerHTML = '<tr><td colspan="7" class="ev-empty"><i class="fa fa-exclamation-circle"></i> ' + EV.esc(m) + '</td></tr>';
            EV.toast(m, 'error');
        }
    });
};

EVT.openModal = function() {
    document.getElementById('evtId').value = '';
    document.getElementById('evtTitle').value = '';
    document.getElementById('evtDesc').value = '';
    document.getElementById('evtCategory').value = 'event';
    document.getElementById('evtStatus').value = 'scheduled';
    document.getElementById('evtStartDate').value = '';
    document.getElementById('evtEndDate').value = '';
    document.getElementById('evtLocation').value = '';
    document.getElementById('evtOrganizer').value = '';
    document.getElementById('evtMaxP').value = '0';
    document.getElementById('evtModalTitle').textContent = 'Create Event';
    document.getElementById('evtModal').classList.add('show');
};

EVT.closeModal = function() { document.getElementById('evtModal').classList.remove('show'); };

EVT.edit = function(id) {
    if (!EV.canEdit) { EV.toast('You do not have permission to edit events','error'); return; }
    var e = EVT.data.find(function(x){ return x.id === id; });
    if (!e) return;
    document.getElementById('evtId').value = e.id;
    document.getElementById('evtTitle').value = e.title||'';
    document.getElementById('evtDesc').value = e.description||'';
    document.getElementById('evtCategory').value = e.category||'event';
    document.getElementById('evtStatus').value = e.status||'scheduled';
    document.getElementById('evtStartDate').value = e.start_date||'';
    document.getElementById('evtEndDate').value = e.end_date||'';
    document.getElementById('evtLocation').value = e.location||'';
    document.getElementById('evtOrganizer').value = e.organizer||'';
    document.getElementById('evtMaxP').value = e.max_participants||0;
    document.getElementById('evtModalTitle').textContent = 'Edit Event';
    document.getElementById('evtModal').classList.add('show');
};

EVT._saving = false;
EVT.save = function() {
    // Double-submit guard: a slow save_event round-trip used to let an
    // impatient user click "Save" 2-3 times, creating duplicate events.
    if (EVT._saving) return;
    if (!EV.canEdit) { EV.toast('You do not have permission to save events','error'); return; }
    var data = {
        id: document.getElementById('evtId').value,
        title: document.getElementById('evtTitle').value.trim(),
        description: document.getElementById('evtDesc').value.trim(),
        category: document.getElementById('evtCategory').value,
        status: document.getElementById('evtStatus').value,
        start_date: document.getElementById('evtStartDate').value,
        end_date: document.getElementById('evtEndDate').value,
        location: document.getElementById('evtLocation').value.trim(),
        organizer: document.getElementById('evtOrganizer').value.trim(),
        max_participants: document.getElementById('evtMaxP').value
    };
    if (!data.title) { EV.toast('Title is required','error'); return; }
    if (!data.start_date) { EV.toast('Start date is required','error'); return; }

    var btn = document.getElementById('evtSaveBtn');
    var orig = btn ? btn.innerHTML : '';
    EVT._saving = true;
    if (btn) { btn.disabled = true; btn.style.opacity = '0.7'; btn.style.cursor = 'not-allowed'; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…'; }

    $.ajax({
        url: EV.BASE + 'events/save_event', type: 'POST', data: data, dataType: 'json',
        success: function(r) {
            if (r && r.status === 'success') { EV.toast(r.message||'Saved'); EVT.closeModal(); EVT.load(); }
            else { EV.toast((r && r.message) || 'Error','error'); }
        },
        error: function(xhr) { var m='Error'; try{ m=JSON.parse(xhr.responseText).message||m; }catch(e){} EV.toast(m,'error'); },
        complete: function() {
            EVT._saving = false;
            if (btn) { btn.disabled = false; btn.style.opacity = ''; btn.style.cursor = ''; btn.innerHTML = orig; }
        }
    });
};

EVT.del = function(id, btn) {
    if (!EV.canManage) { EV.toast('You do not have permission to delete events','error'); return; }
    if (!confirm('Delete this event? This will also remove all participants.')) return;
    EV.btnBusy(btn, true);
    $.ajax({ url: EV.BASE + 'events/delete_event', type: 'POST', data: {id:id}, dataType: 'json',
        success: function(r) {
            if (!r || r.status === 'error' || r.success === false || r.status !== 'success') { EV.toast((r && r.message) || 'Request failed','error'); return; }
            EV.toast(r.message||'Deleted'); EVT.load();
        },
        error: function(xhr) { EV.toast(EV.errMsg(xhr),'error'); },
        complete: function() { EV.btnBusy(btn, false); }
    });
};

EVT.addPhotos = function(id) {
    // Jump to the School Gallery with this event preselected for upload.
    window.location.href = EV.BASE + 'schools/schoolgallery?upload_event=' + encodeURIComponent(id);
};

EVT.circular = function(id) {
    window.open(EV.BASE + 'events/circular/' + encodeURIComponent(id), '_blank');
};

document.addEventListener('DOMContentLoaded', function(){ EVT.load(); });
</script>
