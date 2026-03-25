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
$at = $active_tab ?? 'queue';
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
.cm-card-title{font-family:var(--font-b);font-size:14px;font-weight:700;color:var(--t1);margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
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
.cm-badge-gray{background:rgba(156,163,175,.12);color:#9ca3af}
.cm-empty{text-align:center;padding:40px 20px;color:var(--t3);font-family:var(--font-b)}
.cm-empty i{font-size:36px;display:block;margin-bottom:12px;opacity:.5}
.cm-loading{text-align:center;padding:18px 20px;color:var(--t3);font-size:13px;font-family:var(--font-b)}
.q-filters{display:flex;gap:8px;flex-wrap:wrap}
.q-filter{padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid var(--border);background:transparent;color:var(--t2);font-family:var(--font-b);transition:all .15s}
.q-filter.active,.q-filter:hover{border-color:var(--gold);background:var(--gold-dim);color:var(--gold)}
/* Toast base styles inherited from header.php */
.cm-toast{position:fixed;top:20px;right:20px;z-index:10000;padding:12px 20px;border-radius:8px;font-size:13px;font-weight:600;font-family:var(--font-b);color:#fff;display:none;max-width:400px;box-shadow:0 8px 24px rgba(0,0,0,.15)}
.cm-toast.success{background:#22c55e;display:block}.cm-toast.error{background:#ef4444;display:block}
</style>

<div class="content-wrapper"><section class="content"><div class="cm-wrap">
<div class="cm-header"><div>
    <div class="cm-header-icon"><i class="fa fa-comments"></i> Communication</div>
    <ol class="cm-breadcrumb"><li><a href="<?= base_url('admin') ?>">Dashboard</a></li><li><a href="<?= base_url('communication') ?>">Communication</a></li><li>Queue</li></ol>
</div></div>

<nav class="cm-tabs">
    <?php foreach ($tabs as $slug => $t): ?>
    <a class="cm-tab<?= $at === $slug ? ' active' : '' ?>" href="<?= base_url($t['url']) ?>"><i class="fa <?= $t['icon'] ?>"></i> <?= $t['label'] ?></a>
    <?php endforeach; ?>
</nav>

<div class="cm-card">
    <div class="cm-card-title">
        <span>Message Queue</span>
        <div style="display:flex;gap:8px">
            <button class="cm-btn cm-btn-sm cm-btn-primary" onclick="QUE.process()"><i class="fa fa-play"></i> Process Now</button>
        </div>
    </div>
    <div style="margin-bottom:14px">
        <div class="q-filters">
            <button class="q-filter active" onclick="QUE.filter('')" data-s="">All</button>
            <button class="q-filter" onclick="QUE.filter('pending')" data-s="pending">Pending</button>
            <button class="q-filter" onclick="QUE.filter('sent')" data-s="sent">Sent</button>
            <button class="q-filter" onclick="QUE.filter('failed')" data-s="failed">Failed</button>
            <button class="q-filter" onclick="QUE.filter('cancelled')" data-s="cancelled">Cancelled</button>
        </div>
    </div>
    <table class="cm-table"><thead><tr><th>ID</th><th>Recipient</th><th>Channel</th><th>Title</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody id="queueTbody"><tr><td colspan="7" class="cm-loading">Loading...</td></tr></tbody></table>
</div>

</div></section></div>

<div class="cm-toast" id="cmToast"></div>

<script>
var CM = CM || {};
CM.BASE = '<?= base_url() ?>';
CM.toast = function(msg,type){var t=document.getElementById('cmToast');t.textContent=msg;t.className='cm-toast '+(type||'success');setTimeout(function(){t.className='cm-toast';},3000);};
CM.esc = function(s){var d=document.createElement('span');d.textContent=s||'';return d.innerHTML;};
CM.ajax = function(url,data,cb,method,failCb){$.ajax({url:CM.BASE+url,type:method||'GET',data:data,dataType:'json',success:function(r){if(r.status==='success'){if(cb)cb(r);}else{CM.toast(r.message||'Error','error');if(failCb)failCb();}},error:function(xhr){var m='Error';try{m=JSON.parse(xhr.responseText).message||m;}catch(e){}CM.toast(m,'error');if(failCb)failCb();}});};

var QUE = {};
QUE.currentFilter = '';

QUE.filter = function(status) {
    QUE.currentFilter = status;
    $('.q-filter').removeClass('active');
    $('.q-filter[data-s="' + status + '"]').addClass('active');
    QUE.load();
};

QUE.load = function() {
    var params = {};
    if (QUE.currentFilter) params.status = QUE.currentFilter;
    CM.ajax('communication/get_queue', params, function(r) {
        var list = r.queue || [];
        var html = '';
        if (!list.length) {
            html = '<tr><td colspan="7" class="cm-empty"><i class="fa fa-inbox"></i> Queue is empty</td></tr>';
        } else {
            list.forEach(function(q) {
                var statusMap = {pending:'cm-badge-amber',sent:'cm-badge-green',failed:'cm-badge-rose',processing:'cm-badge-blue',cancelled:'cm-badge-gray'};
                var actions = '';
                if (q.status === 'pending') actions += '<button class="cm-btn cm-btn-sm cm-btn-danger" onclick="QUE.cancel(\'' + CM.esc(q.id) + '\')"><i class="fa fa-ban"></i></button>';
                if (q.status === 'failed') actions += '<button class="cm-btn cm-btn-sm cm-btn-primary" onclick="QUE.retry(\'' + CM.esc(q.id) + '\')"><i class="fa fa-refresh"></i></button>';
                html += '<tr>' +
                    '<td style="font-family:var(--font-m);font-size:11px">' + CM.esc(q.id) + '</td>' +
                    '<td>' + CM.esc(q.recipient_name||q.recipient_id||'') + '<div style="font-size:11px;color:var(--t3)">' + CM.esc(q.recipient_type||'') + '</div></td>' +
                    '<td>' + CM.esc(q.channel||'') + '</td>' +
                    '<td>' + CM.esc(q.title||'') + '<div style="font-size:11px;color:var(--t3);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + CM.esc((q.message_body||'').substring(0,50)) + '</div></td>' +
                    '<td><span class="cm-badge ' + (statusMap[q.status]||'cm-badge-amber') + '">' + CM.esc(q.status) + '</span>' + (q.error_message ? '<div style="font-size:10px;color:var(--rose)">' + CM.esc(q.error_message) + '</div>' : '') + '</td>' +
                    '<td style="font-size:11px">' + CM.esc((q.created_at||'').substring(0,16).replace('T',' ')) + '</td>' +
                    '<td>' + actions + '</td>' +
                    '</tr>';
            });
        }
        $('#queueTbody').html(html);
    }, null, function() {
        $('#queueTbody').html('<tr><td colspan="7" class="cm-empty"><i class="fa fa-exclamation-circle"></i> Failed to load queue</td></tr>');
    });
};

QUE.process = function() {
    CM.ajax('communication/process_queue', {}, function(r) { CM.toast(r.message||'Processed'); QUE.load(); }, 'POST');
};

QUE.cancel = function(id) {
    CM.ajax('communication/cancel_queued', {id:id}, function(r) { CM.toast(r.message||'Cancelled'); QUE.load(); }, 'POST');
};

QUE.retry = function(id) {
    CM.ajax('communication/retry_failed', {id:id}, function(r) { CM.toast(r.message||'Retrying'); QUE.load(); }, 'POST');
};

document.addEventListener('DOMContentLoaded', function(){ QUE.load(); });
</script>
