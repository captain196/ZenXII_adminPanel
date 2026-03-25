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
$at = $active_tab ?? 'templates';
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
.cm-btn-outline{background:transparent;border:1px solid var(--gold);color:var(--gold)} .cm-btn-outline:hover{background:var(--gold-dim)}
.cm-table{width:100%;border-collapse:collapse;font-size:13px;font-family:var(--font-b)}
.cm-table th,.cm-table td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--border)}
.cm-table th{color:var(--t3);font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.04em}
.cm-table td{color:var(--t1)} .cm-table tr:hover td{background:var(--gold-dim)}
.cm-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;font-family:var(--font-b)}
.cm-badge-green{background:rgba(34,197,94,.12);color:#22c55e}
.cm-badge-blue{background:rgba(59,130,246,.12);color:#3b82f6}
.cm-badge-amber{background:rgba(245,158,11,.12);color:#f59e0b}
.cm-badge-purple{background:rgba(139,92,246,.12);color:#8b5cf6}
.cm-empty{text-align:center;padding:40px 20px;color:var(--t3);font-family:var(--font-b)}
.cm-empty i{font-size:36px;display:block;margin-bottom:12px;opacity:.5}
.cm-loading{text-align:center;padding:18px 20px;color:var(--t3);font-size:13px;font-family:var(--font-b)}
/* Modal/toast/form styles inherited from header.php global definitions */
.cm-modal{width:650px}
.cm-form-group textarea{resize:vertical;min-height:80px}
.cm-form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.tpl-vars{margin-top:8px;font-size:11px;color:var(--t3);font-family:var(--font-m)}
.tpl-vars code{background:var(--gold-dim);padding:2px 6px;border-radius:4px;font-size:11px;cursor:pointer;margin:2px}
.tpl-vars code:hover{background:var(--gold);color:#fff}
.tpl-preview{background:var(--bg3,#e6f4f1);border:1px solid var(--border);border-radius:8px;padding:14px;margin-top:10px;font-size:13px;color:var(--t1);font-family:var(--font-b);display:none}
.cm-toast{position:fixed;top:20px;right:20px;z-index:10000;padding:12px 20px;border-radius:8px;font-size:13px;font-weight:600;font-family:var(--font-b);color:#fff;display:none;max-width:400px;box-shadow:0 8px 24px rgba(0,0,0,.15)}
.cm-toast.success{background:#22c55e;display:block}.cm-toast.error{background:#ef4444;display:block}
</style>

<div class="content-wrapper"><section class="content"><div class="cm-wrap">
<div class="cm-header"><div>
    <div class="cm-header-icon"><i class="fa fa-comments"></i> Communication</div>
    <ol class="cm-breadcrumb"><li><a href="<?= base_url('admin') ?>">Dashboard</a></li><li><a href="<?= base_url('communication') ?>">Communication</a></li><li>Templates</li></ol>
</div></div>

<nav class="cm-tabs">
    <?php foreach ($tabs as $slug => $t): ?>
    <a class="cm-tab<?= $at === $slug ? ' active' : '' ?>" href="<?= base_url($t['url']) ?>"><i class="fa <?= $t['icon'] ?>"></i> <?= $t['label'] ?></a>
    <?php endforeach; ?>
</nav>

<div class="cm-card">
    <div class="cm-card-title"><span>Message Templates</span><button class="cm-btn cm-btn-primary" onclick="TPL.openModal()"><i class="fa fa-plus"></i> Create Template</button></div>
    <table class="cm-table"><thead><tr><th>Name</th><th>Channel</th><th>Category</th><th>Variables</th><th>Status</th><th>Actions</th></tr></thead><tbody id="tplTbody"><tr><td colspan="6" class="cm-loading">Loading...</td></tr></tbody></table>
</div>

</div></section></div>

<!-- Template Modal -->
<div class="cm-modal-bg" id="tplModal"><div class="cm-modal">
    <div class="cm-modal-title"><span id="tplModalTitle">Create Template</span><button class="cm-modal-close" onclick="TPL.closeModal()">&times;</button></div>
    <input type="hidden" id="tplId">
    <div class="cm-form-group"><label>Template Name *</label><input type="text" id="tplName" placeholder="e.g. Fee Reminder"></div>
    <div class="cm-form-row">
        <div class="cm-form-group"><label>Channel</label><select id="tplChannel"><option value="push">Push Notification</option><option value="sms">SMS</option><option value="email">Email</option><option value="in_app">In-App</option></select></div>
        <div class="cm-form-group"><label>Category</label><select id="tplCategory"><option value="General">General</option><option value="Fee">Fee</option><option value="Attendance">Attendance</option><option value="Exam">Exam</option><option value="Admission">Admission</option></select></div>
    </div>
    <div class="cm-form-group"><label>Subject</label><input type="text" id="tplSubject" placeholder="e.g. Fee Reminder for {{month}}"></div>
    <div class="cm-form-group">
        <label>Message Body *</label>
        <textarea id="tplBody" rows="5" placeholder="Dear {{parent_name}}, the fee of Rs. {{amount}} for {{student_name}} ({{class}}) is due on {{due_date}}."></textarea>
    </div>
    <div class="tpl-vars">
        Available variables (click to insert):
        <code onclick="TPL.insertVar('student_name')">{{student_name}}</code>
        <code onclick="TPL.insertVar('parent_name')">{{parent_name}}</code>
        <code onclick="TPL.insertVar('class')">{{class}}</code>
        <code onclick="TPL.insertVar('section')">{{section}}</code>
        <code onclick="TPL.insertVar('amount')">{{amount}}</code>
        <code onclick="TPL.insertVar('due_date')">{{due_date}}</code>
        <code onclick="TPL.insertVar('date')">{{date}}</code>
        <code onclick="TPL.insertVar('month')">{{month}}</code>
        <code onclick="TPL.insertVar('exam_name')">{{exam_name}}</code>
        <code onclick="TPL.insertVar('percentage')">{{percentage}}</code>
        <code onclick="TPL.insertVar('grade')">{{grade}}</code>
        <code onclick="TPL.insertVar('receipt_no')">{{receipt_no}}</code>
        <code onclick="TPL.insertVar('school_name')">{{school_name}}</code>
    </div>
    <button class="cm-btn cm-btn-sm cm-btn-outline" onclick="TPL.preview()" style="margin-top:12px"><i class="fa fa-eye"></i> Preview</button>
    <div class="tpl-preview" id="tplPreview"></div>
    <button class="cm-btn cm-btn-primary" onclick="TPL.save()" style="width:100%;margin-top:12px">Save Template</button>
</div></div>

<div class="cm-toast" id="cmToast"></div>

<script>
var CM = CM || {};
CM.BASE = '<?= base_url() ?>';
CM.toast = function(msg,type){var t=document.getElementById('cmToast');t.textContent=msg;t.className='cm-toast '+(type||'success');setTimeout(function(){t.className='cm-toast';},3000);};
CM.esc = function(s){var d=document.createElement('span');d.textContent=s||'';return d.innerHTML;};
CM.ajax = function(url,data,cb,method,failCb){$.ajax({url:CM.BASE+url,type:method||'GET',data:data,dataType:'json',success:function(r){if(r.status==='success'){if(cb)cb(r);}else{CM.toast(r.message||'Error','error');if(failCb)failCb();}},error:function(xhr){var m='Error';try{m=JSON.parse(xhr.responseText).message||m;}catch(e){}CM.toast(m,'error');if(failCb)failCb();}});};

var TPL = {};
TPL.data = [];

TPL.load = function() {
    CM.ajax('communication/get_templates', {}, function(r) {
        TPL.data = r.templates || [];
        var html = '';
        if (!TPL.data.length) {
            html = '<tr><td colspan="6" class="cm-empty"><i class="fa fa-copy"></i> No templates yet</td></tr>';
        } else {
            TPL.data.forEach(function(t) {
                var chMap = {push:'cm-badge-blue',sms:'cm-badge-green',email:'cm-badge-amber',in_app:'cm-badge-purple'};
                var vars = (t.variables||[]).map(function(v){return '{{'+v+'}}';}).join(', ');
                html += '<tr>' +
                    '<td><strong>' + CM.esc(t.name) + '</strong></td>' +
                    '<td><span class="cm-badge ' + (chMap[t.channel]||'cm-badge-blue') + '">' + CM.esc(t.channel) + '</span></td>' +
                    '<td>' + CM.esc(t.category||'') + '</td>' +
                    '<td style="font-size:11px;color:var(--t3);font-family:var(--font-m)">' + CM.esc(vars||'None') + '</td>' +
                    '<td><span class="cm-badge cm-badge-green">' + CM.esc(t.status||'Active') + '</span></td>' +
                    '<td><button class="cm-btn cm-btn-sm cm-btn-primary" onclick="TPL.edit(\'' + CM.esc(t.id) + '\')"><i class="fa fa-pencil"></i></button> ' +
                    '<button class="cm-btn cm-btn-sm cm-btn-danger" onclick="TPL.del(\'' + CM.esc(t.id) + '\')"><i class="fa fa-trash"></i></button></td>' +
                    '</tr>';
            });
        }
        $('#tplTbody').html(html);
    }, null, function() {
        $('#tplTbody').html('<tr><td colspan="6" class="cm-empty"><i class="fa fa-exclamation-circle"></i> Failed to load templates</td></tr>');
    });
};

TPL.openModal = function() { $('#tplId,#tplName,#tplSubject,#tplBody').val(''); $('#tplChannel').val('push'); $('#tplCategory').val('General'); $('#tplPreview').hide(); $('#tplModalTitle').text('Create Template'); $('#tplModal').addClass('show'); };
TPL.closeModal = function() { $('#tplModal').removeClass('show'); };

TPL.edit = function(id) {
    var t = TPL.data.find(function(x){return x.id===id;});
    if (!t) return;
    $('#tplId').val(t.id); $('#tplName').val(t.name); $('#tplChannel').val(t.channel); $('#tplCategory').val(t.category); $('#tplSubject').val(t.subject||''); $('#tplBody').val(t.body||'');
    $('#tplPreview').hide(); $('#tplModalTitle').text('Edit Template');
    $('#tplModal').addClass('show');
};

TPL.insertVar = function(v) {
    var el = document.getElementById('tplBody');
    var pos = el.selectionStart || el.value.length;
    var txt = '{{' + v + '}}';
    el.value = el.value.substring(0, pos) + txt + el.value.substring(el.selectionEnd || pos);
    el.focus();
    el.setSelectionRange(pos + txt.length, pos + txt.length);
};

TPL.preview = function() {
    CM.ajax('communication/preview_template', {subject: $('#tplSubject').val(), body: $('#tplBody').val()}, function(r) {
        var html = '';
        if (r.subject) html += '<div style="font-weight:700;margin-bottom:6px">' + CM.esc(r.subject) + '</div>';
        html += '<div>' + CM.esc(r.body).replace(/\n/g, '<br>') + '</div>';
        $('#tplPreview').html(html).show();
    }, 'POST');
};

TPL.save = function() {
    var data = { id: $('#tplId').val(), name: $('#tplName').val().trim(), channel: $('#tplChannel').val(), category: $('#tplCategory').val(), subject: $('#tplSubject').val().trim(), body: $('#tplBody').val().trim() };
    if (!data.name || !data.body) { CM.toast('Name and body are required', 'error'); return; }
    CM.ajax('communication/save_template', data, function(r) { CM.toast(r.message||'Saved'); TPL.closeModal(); TPL.load(); }, 'POST');
};

TPL.del = function(id) {
    if (!confirm('Delete this template?')) return;
    CM.ajax('communication/delete_template', {id:id}, function(r) { CM.toast(r.message||'Deleted'); TPL.load(); }, 'POST');
};

document.addEventListener('DOMContentLoaded', function(){ TPL.load(); });
</script>
