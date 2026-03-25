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
$at = $active_tab ?? 'circulars';
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
.cm-badge-rose{background:rgba(239,68,68,.12);color:#ef4444}
.cm-badge-purple{background:rgba(139,92,246,.12);color:#8b5cf6}
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
    <ol class="cm-breadcrumb"><li><a href="<?= base_url('admin') ?>">Dashboard</a></li><li><a href="<?= base_url('communication') ?>">Communication</a></li><li>Circulars</li></ol>
</div></div>

<nav class="cm-tabs">
    <?php foreach ($tabs as $slug => $t): ?>
    <a class="cm-tab<?= $at === $slug ? ' active' : '' ?>" href="<?= base_url($t['url']) ?>"><i class="fa <?= $t['icon'] ?>"></i> <?= $t['label'] ?></a>
    <?php endforeach; ?>
</nav>

<div class="cm-card">
    <div class="cm-card-title"><span>Circulars</span><button class="cm-btn cm-btn-primary" onclick="CIR.openModal()"><i class="fa fa-plus"></i> Upload Circular</button></div>
    <table class="cm-table"><thead><tr><th>Title</th><th>Category</th><th>Target</th><th>Issued Date</th><th>Attachment</th><th>Actions</th></tr></thead><tbody id="circularsTbody"><tr><td colspan="6" class="cm-loading">Loading...</td></tr></tbody></table>
</div>

</div></section></div>

<!-- Circular Modal -->
<div class="cm-modal-bg" id="cirModal"><div class="cm-modal">
    <div class="cm-modal-title"><span id="cirModalTitle">Upload Circular</span><button class="cm-modal-close" onclick="CIR.closeModal()">&times;</button></div>
    <form id="cirForm" enctype="multipart/form-data">
    <input type="hidden" name="id" id="cirId">
    <input type="hidden" name="existing_attachment_url" id="cirExUrl">
    <input type="hidden" name="existing_attachment_name" id="cirExName">
    <div class="cm-form-group"><label>Title *</label><input type="text" name="title" id="cirTitle"></div>
    <div class="cm-form-group"><label>Description</label><textarea name="description" id="cirDesc" rows="3"></textarea></div>
    <div class="cm-form-row">
        <div class="cm-form-group"><label>Category</label><select name="category" id="cirCategory"><option value="General">General</option><option value="Policy">Policy</option><option value="Holiday">Holiday</option><option value="Exam">Exam</option><option value="Fee">Fee</option></select></div>
        <div class="cm-form-group"><label>Target Group</label><select name="target_group" id="cirTarget"><option value="All School">All School</option><option value="All Students">All Students</option><option value="All Teachers">All Teachers</option></select></div>
    </div>
    <div class="cm-form-row">
        <div class="cm-form-group"><label>Issued Date</label><input type="date" name="issued_date" id="cirDate"></div>
        <div class="cm-form-group"><label>Expiry Date</label><input type="date" name="expiry_date" id="cirExpiry"></div>
    </div>
    <div class="cm-form-group"><label>Attachment (PDF, DOC, JPG - max 5MB)</label><input type="file" name="attachment" id="cirFile" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"></div>
    <div id="cirExisting" style="display:none;font-size:12px;color:var(--t3);margin-bottom:10px"><i class="fa fa-paperclip"></i> <span id="cirExLabel"></span></div>
    <button type="button" class="cm-btn cm-btn-primary" onclick="CIR.save()" style="width:100%;margin-top:8px">Save Circular</button>
    </form>
</div></div>

<div class="cm-toast" id="cmToast"></div>

<script>
var CM = CM || {};
CM.BASE = '<?= base_url() ?>';
CM.toast = function(msg,type){var t=document.getElementById('cmToast');t.textContent=msg;t.className='cm-toast '+(type||'success');setTimeout(function(){t.className='cm-toast';},3000);};
CM.esc = function(s){var d=document.createElement('span');d.textContent=s||'';return d.innerHTML;};

var CIR = {};

CIR.load = function() {
    $.getJSON(CM.BASE + 'communication/get_circulars', function(r) {
        if (r.status !== 'success') { CM.toast(r.message,'error'); $('#circularsTbody').html('<tr><td colspan="6" class="cm-empty"><i class="fa fa-exclamation-circle"></i> Failed to load</td></tr>'); return; }
        var list = r.circulars || [];
        var html = '';
        if (!list.length) {
            html = '<tr><td colspan="6" class="cm-empty"><i class="fa fa-file-text-o"></i> No circulars yet</td></tr>';
        } else {
            CIR.data = list;
            list.forEach(function(c) {
                var catMap = {Policy:'cm-badge-purple',Holiday:'cm-badge-green',Exam:'cm-badge-amber',Fee:'cm-badge-rose',General:'cm-badge-blue',Recruitment:'cm-badge-purple'};
                var isRecruitment = c.source === 'hr_recruitment' || c.category === 'Recruitment';
                var att = '';
                if (c.attachment_url) {
                    att = '<a href="' + CM.esc(c.attachment_url) + '" target="_blank" class="cm-btn cm-btn-sm cm-btn-outline"><i class="fa fa-download"></i> ' + CM.esc(c.attachment_name||'Download') + '</a>';
                } else if (isRecruitment && c.is_poster) {
                    att = '<button class="cm-btn cm-btn-sm cm-btn-outline" onclick="CIR.viewPoster(\'' + CM.esc(c.id) + '\')"><i class="fa fa-eye"></i> View Poster</button>';
                } else {
                    att = '<span style="color:var(--t3)">None</span>';
                }
                var srcBadge = isRecruitment ? ' <span class="cm-badge cm-badge-amber" style="font-size:9px;">HR Job</span>' : '';
                html += '<tr>' +
                    '<td><strong>' + CM.esc(c.title) + '</strong>' + srcBadge + '</td>' +
                    '<td><span class="cm-badge ' + (catMap[c.category]||'cm-badge-blue') + '">' + CM.esc(c.category) + '</span></td>' +
                    '<td>' + CM.esc(c.target_group||'') + '</td>' +
                    '<td>' + CM.esc(c.issued_date||'') + '</td>' +
                    '<td>' + att + '</td>' +
                    '<td>' +
                    (isRecruitment && c.is_poster ? '<button class="cm-btn cm-btn-sm cm-btn-outline" onclick="CIR.viewPoster(\'' + CM.esc(c.id) + '\')" style="margin-right:4px;" title="View poster"><i class="fa fa-eye"></i></button> ' : '') +
                    '<button class="cm-btn cm-btn-sm cm-btn-primary" onclick="CIR.edit(\'' + CM.esc(c.id) + '\')"><i class="fa fa-pencil"></i></button> ' +
                    '<button class="cm-btn cm-btn-sm cm-btn-danger" onclick="CIR.del(\'' + CM.esc(c.id) + '\')"><i class="fa fa-trash"></i></button></td>' +
                    '</tr>';
            });
        }
        $('#circularsTbody').html(html);
    }).fail(function() {
        CM.toast('Failed to load circulars','error');
        $('#circularsTbody').html('<tr><td colspan="6" class="cm-empty"><i class="fa fa-exclamation-circle"></i> Failed to load circulars</td></tr>');
    });

    // Load target groups
    $.getJSON(CM.BASE + 'communication/get_target_groups', function(r) {
        if (r.status !== 'success') return;
        var sel = $('#cirTarget');
        sel.empty();
        (r.groups||[]).forEach(function(g){ sel.append('<option value="' + CM.esc(g.value) + '">' + CM.esc(g.label) + '</option>'); });
    });
};

CIR.data = [];
CIR.openModal = function() {
    $('#cirId,#cirExUrl,#cirExName').val(''); $('#cirTitle,#cirDesc').val(''); $('#cirCategory').val('General'); $('#cirTarget').val('All School');
    $('#cirDate').val(new Date().toISOString().substring(0,10)); $('#cirExpiry').val(''); $('#cirFile').val('');
    $('#cirExisting').hide(); $('#cirModalTitle').text('Upload Circular');
    $('#cirModal').addClass('show');
};
CIR.closeModal = function() { $('#cirModal').removeClass('show'); };

CIR.edit = function(id) {
    var c = CIR.data.find(function(x){return x.id===id;});
    if (!c) return;
    $('#cirId').val(c.id); $('#cirTitle').val(c.title); $('#cirDesc').val(c.description||''); $('#cirCategory').val(c.category); $('#cirTarget').val(c.target_group);
    $('#cirDate').val(c.issued_date||''); $('#cirExpiry').val(c.expiry_date||'');
    $('#cirExUrl').val(c.attachment_url||''); $('#cirExName').val(c.attachment_name||'');
    if (c.attachment_name) { $('#cirExLabel').text(c.attachment_name); $('#cirExisting').show(); }
    else $('#cirExisting').hide();
    $('#cirFile').val(''); $('#cirModalTitle').text('Edit Circular');
    $('#cirModal').addClass('show');
};

CIR.save = function() {
    var fd = new FormData($('#cirForm')[0]);
    $.ajax({
        url: CM.BASE + 'communication/save_circular',
        type: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(r) {
            if (r.status === 'success') { CM.toast(r.message||'Saved'); CIR.closeModal(); CIR.load(); }
            else CM.toast(r.message||'Error','error');
        },
        error: function(xhr) { var m='Error';try{m=JSON.parse(xhr.responseText).message||m;}catch(e){} CM.toast(m,'error'); }
    });
};

CIR.del = function(id) {
    if (!confirm('Delete this circular?')) return;
    $.post(CM.BASE + 'communication/delete_circular', {id:id}, function(r) {
        if (r.status === 'success') { CM.toast('Deleted'); CIR.load(); }
        else CM.toast(r.message||'Error','error');
    }, 'json');
};

CIR.viewPoster = function(circularId) {
    var c = CIR.data.find(function(x){ return x.id === circularId; });
    if (!c) return;

    // If description contains HTML poster content, show it directly
    if (c.is_poster && c.description) {
        $('#cirPosterContent').html(c.description);
        $('#cirPosterTitle').text(c.title || 'Job Circular');
        $('#cirPosterModal').addClass('show');
        return;
    }

    // Otherwise fetch from HR endpoint
    var srcId = c.source_id || '';
    if (!srcId) {
        // Show raw description
        $('#cirPosterContent').html('<div style="padding:20px;white-space:pre-line;">' + CM.esc(c.description || 'No content.') + '</div>');
        $('#cirPosterTitle').text(c.title || 'Circular');
        $('#cirPosterModal').addClass('show');
        return;
    }

    $('#cirPosterContent').html('<div style="text-align:center;padding:40px;color:var(--t3);"><i class="fa fa-spinner fa-spin" style="font-size:24px;"></i></div>');
    $('#cirPosterTitle').text(c.title || 'Job Circular');
    $('#cirPosterModal').addClass('show');

    $.getJSON(CM.BASE + 'hr/view_circular?circular_id=' + encodeURIComponent(circularId), function(r) {
        if (r.status === 'success' && r.poster_html) {
            $('#cirPosterContent').html(r.poster_html);
        } else {
            $('#cirPosterContent').html('<div style="padding:20px;white-space:pre-line;">' + CM.esc(c.description || 'Failed to load poster.') + '</div>');
        }
    });
};

CIR.closePoster = function() { $('#cirPosterModal').removeClass('show'); };

CIR.printPoster = function() {
    var content = document.getElementById('cirPosterContent').innerHTML;
    var w = window.open('', '_blank', 'width=700,height=900');
    w.document.write('<!DOCTYPE html><html><head><title>Circular</title>');
    w.document.write('<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">');
    w.document.write('<style>body{margin:20px;font-family:"Segoe UI",system-ui,sans-serif;}@media print{body{margin:0;}}</style>');
    w.document.write('</head><body>');
    w.document.write(content);
    w.document.write('<script>setTimeout(function(){window.print();},500);<\/script>');
    w.document.write('</body></html>');
    w.document.close();
};

document.addEventListener('DOMContentLoaded', function(){ CIR.load(); });
</script>

<!-- Poster Viewer Modal -->
<div class="cm-modal-bg" id="cirPosterModal">
  <div class="cm-modal" style="max-width:680px;">
    <div class="cm-modal-title">
      <span id="cirPosterTitle">Job Circular</span>
      <button class="cm-modal-close" onclick="CIR.closePoster()">&times;</button>
    </div>
    <div id="cirPosterContent" style="max-height:70vh;overflow-y:auto;padding:4px;"></div>
    <div style="padding:12px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px;">
      <button class="cm-btn cm-btn-outline cm-btn-sm" onclick="CIR.closePoster()">Close</button>
      <button class="cm-btn cm-btn-primary cm-btn-sm" onclick="CIR.printPoster()"><i class="fa fa-print"></i> Print</button>
    </div>
  </div>
</div>
