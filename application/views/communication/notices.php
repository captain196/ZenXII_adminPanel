<?php
$tabs = [
    'dashboard' => ['icon' => 'fa-dashboard',   'label' => 'Dashboard',      'url' => 'communication'],
    'messages'  => ['icon' => 'fa-comments',     'label' => 'Messages',       'url' => 'communication/messages'],
    'notices'   => ['icon' => 'fa-bullhorn',     'label' => 'Notice Board',   'url' => 'communication/notices'],
    'circulars' => ['icon' => 'fa-file-text-o',  'label' => 'Circulars',      'url' => 'communication/circulars'],
    'templates' => ['icon' => 'fa-copy',         'label' => 'Templates',      'url' => 'communication/templates'],
    'triggers'  => ['icon' => 'fa-bolt',         'label' => 'Alert Triggers', 'url' => 'communication/triggers'],
    'queue'     => ['icon' => 'fa-clock-o',      'label' => 'Queue',          'url' => 'communication/queue'],
    'logs'      => ['icon' => 'fa-list-alt',     'label' => 'Delivery Logs',  'url' => 'communication/logs'],
];
$at = $active_tab ?? 'notices';
?>
<style>
.cm-wrap{padding:20px;max-width:1400px;margin:0 auto}
.cm-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.cm-header-icon{font-family:var(--font-b);font-size:1.3rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px}
.cm-header-icon i{color:var(--gold);font-size:1.1rem}
.cm-breadcrumb{list-style:none;display:flex;gap:6px;font-size:12px;color:var(--t3);padding:0;margin:6px 0 0;font-family:var(--font-b)}
.cm-breadcrumb a{color:var(--gold);text-decoration:none}
.cm-breadcrumb li+li::before{content:">";margin-right:6px;color:var(--t4)}
.cm-tabs{display:flex;gap:4px;margin-bottom:24px;border-bottom:1px solid var(--border);overflow-x:auto}
.cm-tab{padding:10px 16px;font-size:13px;font-weight:600;color:var(--t3);text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap;transition:all var(--ease);font-family:var(--font-b)}
.cm-tab:hover{color:var(--t1)} .cm-tab.active{color:var(--gold);border-bottom-color:var(--gold)} .cm-tab i{margin-right:6px;font-size:14px}
.cm-card{background:var(--card,var(--bg2));border:1px solid var(--border);border-radius:var(--r,10px);padding:20px;margin-bottom:18px}
.cm-card-title{font-family:var(--font-b);font-size:14px;font-weight:700;color:var(--t1);margin-bottom:14px;display:flex;align-items:center;justify-content:space-between}
.cm-btn{padding:8px 16px;border-radius:var(--r-sm,6px);font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all var(--ease);font-family:var(--font-b)}
.cm-btn-primary{background:var(--gold);color:#fff} .cm-btn-primary:hover{background:var(--gold2)}
.cm-btn-danger{background:var(--rose,#ef4444);color:#fff} .cm-btn-sm{padding:6px 12px;font-size:12px}
.cm-table{width:100%;border-collapse:collapse;font-size:13px;font-family:var(--font-b)}
.cm-table th,.cm-table td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--border)}
.cm-table th{color:var(--t3);font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.04em}
.cm-table td{color:var(--t1)} .cm-table tr:hover td{background:var(--gold-dim)}
.cm-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;font-family:var(--font-b)}
.cm-badge-green{background:rgba(34,197,94,.12);color:#22c55e}
.cm-badge-blue{background:rgba(59,130,246,.12);color:#3b82f6}
.cm-badge-amber{background:rgba(245,158,11,.12);color:#f59e0b}
.cm-badge-rose{background:rgba(239,68,68,.12);color:#ef4444}
.cm-empty{text-align:center;padding:40px 20px;color:var(--t3);font-family:var(--font-b)}
.cm-empty i{font-size:36px;display:block;margin-bottom:12px;opacity:.5}
.cm-loading{text-align:center;padding:18px 20px;color:var(--t3);font-size:13px;font-family:var(--font-b)}
/* Modal/toast/form styles inherited from header.php global definitions */
.cm-modal{width:600px}
.cm-form-group textarea{resize:vertical;min-height:80px}
.cm-form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.cm-toast{position:fixed;top:20px;right:20px;z-index:10000;padding:12px 20px;border-radius:8px;font-size:13px;font-weight:600;font-family:var(--font-b);color:#fff;display:none;max-width:400px;box-shadow:0 8px 24px rgba(0,0,0,.15)}
.cm-toast.success{background:#22c55e;display:block}.cm-toast.error{background:#ef4444;display:block}
</style>

<div class="content-wrapper"><section class="content"><div class="cm-wrap">
<div class="cm-header"><div>
    <div class="cm-header-icon"><i class="fa fa-comments"></i> Communication</div>
    <ol class="cm-breadcrumb"><li><a href="<?= base_url('admin') ?>">Dashboard</a></li><li><a href="<?= base_url('communication') ?>">Communication</a></li><li>Notice Board</li></ol>
</div></div>

<nav class="cm-tabs">
    <?php foreach ($tabs as $slug => $t): ?>
    <a class="cm-tab<?= $at === $slug ? ' active' : '' ?>" href="<?= base_url($t['url']) ?>"><i class="fa <?= $t['icon'] ?>"></i> <?= $t['label'] ?></a>
    <?php endforeach; ?>
</nav>

<div class="cm-card">
    <div class="cm-card-title"><span>Notice Board</span><button class="cm-btn cm-btn-primary" onclick="NTC.openModal()"><i class="fa fa-plus"></i> Post Notice</button></div>
    <table class="cm-table"><thead><tr><th>Title</th><th>Target</th><th>Category</th><th>Priority</th><th>Posted By</th><th>Date</th><th>Actions</th></tr></thead><tbody id="noticesTbody"><tr><td colspan="7" class="cm-loading">Loading...</td></tr></tbody></table>
</div>

</div></section></div>

<!-- Notice Modal -->
<div class="cm-modal-bg" id="noticeModal"><div class="cm-modal">
    <div class="cm-modal-title"><span id="noticeModalTitle">Post Notice</span><button class="cm-modal-close" onclick="NTC.closeModal()">&times;</button></div>
    <input type="hidden" id="ntcId">
    <div class="cm-form-group"><label>Title *</label><input type="text" id="ntcTitle"></div>
    <div class="cm-form-group"><label>Description *</label><textarea id="ntcDesc" rows="4"></textarea></div>
    <div class="cm-form-row">
        <div class="cm-form-group"><label>Target Group</label><select id="ntcTarget"><option value="All School">All School</option><option value="All Students">All Students</option><option value="All Teachers">All Teachers</option></select></div>
        <div class="cm-form-group"><label>Priority</label><select id="ntcPriority"><option value="Normal">Normal</option><option value="High">High</option><option value="Low">Low</option></select></div>
    </div>
    <div class="cm-form-row">
        <div class="cm-form-group"><label>Category</label><select id="ntcCategory"><option value="General">General</option><option value="Academic">Academic</option><option value="Administrative">Administrative</option><option value="Holiday">Holiday</option><option value="Exam">Exam</option><option value="Event">Event</option></select></div>
        <div class="cm-form-group"><label>Expiry Date</label><input type="date" id="ntcExpiry"></div>
    </div>
    <button class="cm-btn cm-btn-primary" onclick="NTC.save()" style="width:100%;margin-top:8px">Save Notice</button>
</div></div>

<div class="cm-toast" id="cmToast"></div>

<script>
var CM = CM || {};
CM.BASE = '<?= base_url() ?>';
CM.toast = function(msg,type){var t=document.getElementById('cmToast');t.textContent=msg;t.className='cm-toast '+(type||'success');setTimeout(function(){t.className='cm-toast';},3000);};
CM.esc = function(s){var d=document.createElement('span');d.textContent=s||'';return d.innerHTML;};
CM.ajax = function(url,data,cb,method,failCb){$.ajax({url:CM.BASE+url,type:method||'GET',data:data,dataType:'json',success:function(r){if(r.status==='success'){if(cb)cb(r);}else{CM.toast(r.message||'Error','error');if(failCb)failCb();}},error:function(xhr){var m='Request failed';try{m=JSON.parse(xhr.responseText).message||m;}catch(e){}CM.toast(m,'error');if(failCb)failCb();}});};

var NTC = {};

NTC.load = function() {
    CM.ajax('communication/get_notices', {}, function(r) {
        var list = r.notices || [];
        var html = '';
        if (!list.length) {
            html = '<tr><td colspan="7" class="cm-empty"><i class="fa fa-bullhorn"></i> No notices posted yet</td></tr>';
        } else {
            list.forEach(function(n) {
                var priClass = n.priority === 'High' ? 'cm-badge-rose' : (n.priority === 'Low' ? 'cm-badge-blue' : 'cm-badge-amber');
                // HR-sourced notices are auto-generated by the Recruitment module —
                // editing/deleting here would be silently undone on the next job save.
                var isHrManaged = n.source === 'hr_recruitment';
                var srcBadge = isHrManaged
                    ? ' <span class="cm-badge cm-badge-amber" style="font-size:9px;" title="Managed by HR Recruitment — edit via HR module">HR Managed</span>'
                    : '';
                var actions = isHrManaged
                    ? '<a class="cm-btn cm-btn-sm cm-btn-outline" href="' + CM.BASE + 'hr/recruitment" title="Edit via HR Recruitment"><i class="fa fa-external-link"></i> HR</a>'
                    : ('<button class="cm-btn cm-btn-sm cm-btn-primary" onclick="NTC.edit(\'' + CM.esc(n.id) + '\')"><i class="fa fa-pencil"></i></button> ' +
                       '<button class="cm-btn cm-btn-sm cm-btn-danger" onclick="NTC.del(\'' + CM.esc(n.id) + '\')"><i class="fa fa-trash"></i></button>');
                html += '<tr>' +
                    '<td><strong>' + CM.esc(n.title) + '</strong>' + srcBadge + '<div style="font-size:11px;color:var(--t3);margin-top:2px">' + CM.esc((n.description||'').substring(0,80)) + '</div></td>' +
                    '<td>' + CM.esc(n.target_group || '') + '</td>' +
                    '<td><span class="cm-badge cm-badge-blue">' + CM.esc(n.category || '') + '</span></td>' +
                    '<td><span class="cm-badge ' + priClass + '">' + CM.esc(n.priority || 'Normal') + '</span></td>' +
                    '<td>' + CM.esc(n.created_by_name || n.created_by || '') + '</td>' +
                    '<td>' + CM.esc((n.created_at||'').substring(0,10)) + '</td>' +
                    '<td>' + actions + '</td>' +
                    '</tr>';
            });
        }
        $('#noticesTbody').html(html);
    }, null, function() {
        $('#noticesTbody').html('<tr><td colspan="7" class="cm-empty"><i class="fa fa-exclamation-circle"></i> Failed to load notices</td></tr>');
    });

    // Load target groups for dropdown
    CM.ajax('communication/get_target_groups', {}, function(r) {
        var sel = $('#ntcTarget');
        sel.empty();
        (r.groups || []).forEach(function(g) {
            sel.append('<option value="' + CM.esc(g.value) + '">' + CM.esc(g.label) + '</option>');
        });
    });
};

NTC.notices = [];
NTC.openModal = function(id) {
    $('#ntcId').val(''); $('#ntcTitle').val(''); $('#ntcDesc').val(''); $('#ntcTarget').val('All School'); $('#ntcPriority').val('Normal'); $('#ntcCategory').val('General'); $('#ntcExpiry').val('');
    $('#noticeModalTitle').text(id ? 'Edit Notice' : 'Post Notice');
    $('#noticeModal').addClass('show');
};
NTC.closeModal = function() { $('#noticeModal').removeClass('show'); };

NTC.edit = function(id) {
    CM.ajax('communication/get_notices', {}, function(r) {
        var n = (r.notices||[]).find(function(x){return x.id===id;});
        if (!n) return;
        $('#ntcId').val(n.id); $('#ntcTitle').val(n.title); $('#ntcDesc').val(n.description); $('#ntcTarget').val(n.target_group); $('#ntcPriority').val(n.priority); $('#ntcCategory').val(n.category); $('#ntcExpiry').val(n.expiry_date||'');
        $('#noticeModalTitle').text('Edit Notice');
        $('#noticeModal').addClass('show');
    });
};

NTC.save = function() {
    var data = {
        id: $('#ntcId').val(), title: $('#ntcTitle').val().trim(), description: $('#ntcDesc').val().trim(),
        target_group: $('#ntcTarget').val(), priority: $('#ntcPriority').val(), category: $('#ntcCategory').val(), expiry_date: $('#ntcExpiry').val()
    };
    if (!data.title || !data.description) { CM.toast('Title and description are required', 'error'); return; }
    if (window._ntcSaving) return;
    window._ntcSaving = true;
    var $btn = $('button.cm-btn-primary[onclick*="NTC.save"]');
    var orig = $btn.html();
    $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving…');
    $.post(CM.BASE + 'communication/save_notice', data)
        .done(function(r){
            if (r && r.status === 'success') { CM.toast(r.message || 'Notice saved'); NTC.closeModal(); }
            else CM.toast((r && r.message) || 'Failed to save', 'error');
        })
        .fail(function(){ CM.toast('Server error — refreshing list', 'error'); })
        .always(function(){
            window._ntcSaving = false;
            $btn.prop('disabled', false).html(orig);
            NTC.load();
        });
};

NTC.del = function(id) {
    if (!confirm('Delete this notice?')) return;
    CM.ajax('communication/delete_notice', {id: id}, function(r) {
        CM.toast(r.message || 'Deleted'); NTC.load();
    }, 'POST');
};

document.addEventListener('DOMContentLoaded', function(){ NTC.load(); });
</script>
