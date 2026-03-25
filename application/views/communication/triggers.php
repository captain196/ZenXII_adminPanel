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
$at = $active_tab ?? 'triggers';
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
.cm-form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.trg-toggle{position:relative;display:inline-block;width:44px;height:24px;cursor:pointer}
.trg-toggle input{opacity:0;width:0;height:0}
.trg-slider{position:absolute;top:0;left:0;right:0;bottom:0;background:var(--border);border-radius:12px;transition:.2s}
.trg-slider:before{content:'';position:absolute;height:18px;width:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.2s}
.trg-toggle input:checked+.trg-slider{background:var(--gold)}
.trg-toggle input:checked+.trg-slider:before{transform:translateX(20px)}
.cm-toast{position:fixed;top:20px;right:20px;z-index:10000;padding:12px 20px;border-radius:8px;font-size:13px;font-weight:600;font-family:var(--font-b);color:#fff;display:none;max-width:400px;box-shadow:0 8px 24px rgba(0,0,0,.15)}
.cm-toast.success{background:#22c55e;display:block}.cm-toast.error{background:#ef4444;display:block}
</style>

<div class="content-wrapper"><section class="content"><div class="cm-wrap">
<div class="cm-header"><div>
    <div class="cm-header-icon"><i class="fa fa-comments"></i> Communication</div>
    <ol class="cm-breadcrumb"><li><a href="<?= base_url('admin') ?>">Dashboard</a></li><li><a href="<?= base_url('communication') ?>">Communication</a></li><li>Alert Triggers</li></ol>
</div></div>

<nav class="cm-tabs">
    <?php foreach ($tabs as $slug => $t): ?>
    <a class="cm-tab<?= $at === $slug ? ' active' : '' ?>" href="<?= base_url($t['url']) ?>"><i class="fa <?= $t['icon'] ?>"></i> <?= $t['label'] ?></a>
    <?php endforeach; ?>
</nav>

<div class="cm-card">
    <div class="cm-card-title"><span>Automated Alert Triggers</span><button class="cm-btn cm-btn-primary" onclick="TRG.openModal()"><i class="fa fa-plus"></i> Create Trigger</button></div>
    <table class="cm-table"><thead><tr><th>Name</th><th>Event</th><th>Channel</th><th>Recipient</th><th>Template</th><th>Enabled</th><th>Actions</th></tr></thead><tbody id="trgTbody"><tr><td colspan="7" class="cm-loading">Loading...</td></tr></tbody></table>
</div>

</div></section></div>

<!-- Trigger Modal -->
<div class="cm-modal-bg" id="trgModal"><div class="cm-modal">
    <div class="cm-modal-title"><span id="trgModalTitle">Create Trigger</span><button class="cm-modal-close" onclick="TRG.closeModal()">&times;</button></div>
    <input type="hidden" id="trgId">
    <div class="cm-form-group"><label>Trigger Name *</label><input type="text" id="trgName" placeholder="e.g. Absent Student Alert"></div>
    <div class="cm-form-row">
        <div class="cm-form-group"><label>Event Type *</label><select id="trgEvent">
            <option value="">-- Select --</option>
            <option value="student_absent">Student Absent</option>
            <option value="student_late">Student Late</option>
            <option value="low_attendance">Low Attendance</option>
            <option value="fee_due">Fee Due</option>
            <option value="fee_overdue">Fee Overdue</option>
            <option value="fee_received">Fee Received</option>
            <option value="exam_result">Exam Result Published</option>
            <option value="exam_schedule">Exam Scheduled</option>
            <option value="admission_approved">Admission Approved</option>
            <option value="admission_rejected">Admission Rejected</option>
            <option value="salary_processed">Salary Processed</option>
            <option value="leave_approved">Leave Approved</option>
        </select></div>
        <div class="cm-form-group"><label>Template *</label><select id="trgTemplate"><option value="">-- Select --</option></select></div>
    </div>
    <div class="cm-form-row">
        <div class="cm-form-group"><label>Channel</label><select id="trgChannel"><option value="push">Push</option><option value="sms">SMS</option><option value="email">Email</option><option value="in_app">In-App</option></select></div>
        <div class="cm-form-group"><label>Recipient Type</label><select id="trgRecipient"><option value="parent">Parent</option><option value="student">Student</option><option value="teacher">Teacher</option><option value="staff">Staff</option></select></div>
    </div>
    <div class="cm-form-group"><label>Conditions (JSON, optional)</label><input type="text" id="trgConditions" placeholder='e.g. {"min_absent_days": 1}'></div>
    <button class="cm-btn cm-btn-primary" onclick="TRG.save()" style="width:100%;margin-top:8px">Save Trigger</button>
</div></div>

<div class="cm-toast" id="cmToast"></div>

<script>
var CM = CM || {};
CM.BASE = '<?= base_url() ?>';
CM.toast = function(msg,type){var t=document.getElementById('cmToast');t.textContent=msg;t.className='cm-toast '+(type||'success');setTimeout(function(){t.className='cm-toast';},3000);};
CM.esc = function(s){var d=document.createElement('span');d.textContent=s||'';return d.innerHTML;};
CM.ajax = function(url,data,cb,method,failCb){$.ajax({url:CM.BASE+url,type:method||'GET',data:data,dataType:'json',success:function(r){if(r.status==='success'){if(cb)cb(r);}else{CM.toast(r.message||'Error','error');if(failCb)failCb();}},error:function(xhr){var m='Error';try{m=JSON.parse(xhr.responseText).message||m;}catch(e){}CM.toast(m,'error');if(failCb)failCb();}});};

var TRG = {};
TRG.data = [];
TRG.templates = [];

TRG.load = function() {
    // Load templates for dropdown
    CM.ajax('communication/get_templates', {}, function(r) {
        TRG.templates = r.templates || [];
        var sel = $('#trgTemplate');
        sel.empty().append('<option value="">-- Select --</option>');
        TRG.templates.forEach(function(t) { sel.append('<option value="' + CM.esc(t.id) + '">' + CM.esc(t.name) + ' (' + t.channel + ')</option>'); });
    });

    CM.ajax('communication/get_triggers', {}, function(r) {
        TRG.data = r.triggers || [];
        var html = '';
        if (!TRG.data.length) {
            html = '<tr><td colspan="7" class="cm-empty"><i class="fa fa-bolt"></i> No triggers configured</td></tr>';
        } else {
            TRG.data.forEach(function(t) {
                var evtLabel = t.event_type ? t.event_type.replace(/_/g, ' ').replace(/\b\w/g, function(c){return c.toUpperCase();}) : '';
                var tplName = '';
                TRG.templates.forEach(function(tp){if(tp.id===t.template_id)tplName=tp.name;});
                html += '<tr>' +
                    '<td><strong>' + CM.esc(t.name) + '</strong></td>' +
                    '<td><span class="cm-badge cm-badge-amber">' + CM.esc(evtLabel) + '</span></td>' +
                    '<td>' + CM.esc(t.channel||'') + '</td>' +
                    '<td>' + CM.esc(t.recipient_type||'') + '</td>' +
                    '<td>' + CM.esc(tplName||t.template_id||'') + '</td>' +
                    '<td><label class="trg-toggle"><input type="checkbox" ' + (t.enabled?'checked':'') + ' onchange="TRG.toggle(\'' + CM.esc(t.id) + '\',this.checked)"><span class="trg-slider"></span></label></td>' +
                    '<td><button class="cm-btn cm-btn-sm cm-btn-primary" onclick="TRG.edit(\'' + CM.esc(t.id) + '\')"><i class="fa fa-pencil"></i></button> ' +
                    '<button class="cm-btn cm-btn-sm cm-btn-danger" onclick="TRG.del(\'' + CM.esc(t.id) + '\')"><i class="fa fa-trash"></i></button></td>' +
                    '</tr>';
            });
        }
        $('#trgTbody').html(html);
    }, null, function() {
        $('#trgTbody').html('<tr><td colspan="7" class="cm-empty"><i class="fa fa-exclamation-circle"></i> Failed to load triggers</td></tr>');
    });
};

TRG.openModal = function() { $('#trgId,#trgName,#trgConditions').val(''); $('#trgEvent,#trgTemplate').val(''); $('#trgChannel').val('push'); $('#trgRecipient').val('parent'); $('#trgModalTitle').text('Create Trigger'); $('#trgModal').addClass('show'); };
TRG.closeModal = function() { $('#trgModal').removeClass('show'); };

TRG.edit = function(id) {
    var t = TRG.data.find(function(x){return x.id===id;});
    if (!t) return;
    $('#trgId').val(t.id); $('#trgName').val(t.name); $('#trgEvent').val(t.event_type); $('#trgTemplate').val(t.template_id); $('#trgChannel').val(t.channel); $('#trgRecipient').val(t.recipient_type);
    $('#trgConditions').val(JSON.stringify(t.conditions||{}));
    $('#trgModalTitle').text('Edit Trigger'); $('#trgModal').addClass('show');
};

TRG.save = function() {
    var data = { id: $('#trgId').val(), name: $('#trgName').val().trim(), event_type: $('#trgEvent').val(), template_id: $('#trgTemplate').val(), channel: $('#trgChannel').val(), recipient_type: $('#trgRecipient').val(), conditions: $('#trgConditions').val()||'{}', enabled: '1' };
    if (!data.name||!data.event_type||!data.template_id) { CM.toast('Name, event and template required','error'); return; }
    CM.ajax('communication/save_trigger', data, function(r) { CM.toast(r.message||'Saved'); TRG.closeModal(); TRG.load(); }, 'POST');
};

TRG.toggle = function(id, enabled) {
    CM.ajax('communication/toggle_trigger', {id:id, enabled:enabled?'1':'0'}, function(r) { CM.toast(r.message||'Updated'); }, 'POST');
};

TRG.del = function(id) {
    if (!confirm('Delete this trigger?')) return;
    CM.ajax('communication/delete_trigger', {id:id}, function(r) { CM.toast(r.message||'Deleted'); TRG.load(); }, 'POST');
};

document.addEventListener('DOMContentLoaded', function(){ TRG.load(); });
</script>
