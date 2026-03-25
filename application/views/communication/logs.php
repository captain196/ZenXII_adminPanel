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
$at = $active_tab ?? 'logs';
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
.cm-btn-sm{padding:6px 12px;font-size:12px}
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
.cm-empty{text-align:center;padding:40px 20px;color:var(--t3);font-family:var(--font-b)}
.cm-empty i{font-size:36px;display:block;margin-bottom:12px;opacity:.5}
.cm-loading{text-align:center;padding:18px 20px;color:var(--t3);font-size:13px;font-family:var(--font-b)}
.cm-stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:20px}
.cm-stat-mini{text-align:center;padding:16px;background:var(--bg3,#e6f4f1);border-radius:var(--r,10px);border:1px solid var(--border)}
.cm-stat-mini-val{font-size:22px;font-weight:700;font-family:var(--font-b);line-height:1}
.cm-stat-mini-lbl{font-size:11px;color:var(--t3);margin-top:4px;font-family:var(--font-b)}
/* Toast base styles inherited from header.php */
.cm-toast{position:fixed;top:20px;right:20px;z-index:10000;padding:12px 20px;border-radius:8px;font-size:13px;font-weight:600;font-family:var(--font-b);color:#fff;display:none;max-width:400px;box-shadow:0 8px 24px rgba(0,0,0,.15)}
.cm-toast.success{background:#22c55e;display:block}.cm-toast.error{background:#ef4444;display:block}
</style>

<div class="content-wrapper"><section class="content"><div class="cm-wrap">
<div class="cm-header"><div>
    <div class="cm-header-icon"><i class="fa fa-comments"></i> Communication</div>
    <ol class="cm-breadcrumb"><li><a href="<?= base_url('admin') ?>">Dashboard</a></li><li><a href="<?= base_url('communication') ?>">Communication</a></li><li>Delivery Logs</li></ol>
</div></div>

<nav class="cm-tabs">
    <?php foreach ($tabs as $slug => $t): ?>
    <a class="cm-tab<?= $at === $slug ? ' active' : '' ?>" href="<?= base_url($t['url']) ?>"><i class="fa <?= $t['icon'] ?>"></i> <?= $t['label'] ?></a>
    <?php endforeach; ?>
</nav>

<!-- Stats -->
<div class="cm-stat-grid" id="logStats">
    <div class="cm-stat-mini"><div class="cm-stat-mini-val" id="lsTotal" style="color:var(--t1)">--</div><div class="cm-stat-mini-lbl">Total</div></div>
    <div class="cm-stat-mini"><div class="cm-stat-mini-val" id="lsDelivered" style="color:#22c55e">--</div><div class="cm-stat-mini-lbl">Delivered</div></div>
    <div class="cm-stat-mini"><div class="cm-stat-mini-val" id="lsFailed" style="color:#ef4444">--</div><div class="cm-stat-mini-lbl">Failed</div></div>
    <div class="cm-stat-mini"><div class="cm-stat-mini-val" id="lsBounced" style="color:#f59e0b">--</div><div class="cm-stat-mini-lbl">Bounced</div></div>
</div>

<div class="cm-card">
    <div class="cm-card-title"><span>Delivery Logs</span><button class="cm-btn cm-btn-sm cm-btn-outline" onclick="LOG.load()"><i class="fa fa-refresh"></i> Refresh</button></div>
    <table class="cm-table"><thead><tr><th>Log ID</th><th>Queue ID</th><th>Channel</th><th>Recipient</th><th>Status</th><th>Response</th><th>Gateway</th><th>Time</th></tr></thead><tbody id="logsTbody"><tr><td colspan="8" class="cm-loading">Loading...</td></tr></tbody></table>
</div>

</div></section></div>

<div class="cm-toast" id="cmToast"></div>

<script>
var CM = CM || {};
CM.BASE = '<?= base_url() ?>';
CM.toast = function(msg,type){var t=document.getElementById('cmToast');t.textContent=msg;t.className='cm-toast '+(type||'success');setTimeout(function(){t.className='cm-toast';},3000);};
CM.esc = function(s){var d=document.createElement('span');d.textContent=s||'';return d.innerHTML;};
CM.ajax = function(url,data,cb,method,failCb){$.ajax({url:CM.BASE+url,type:method||'GET',data:data,dataType:'json',success:function(r){if(r.status==='success'){if(cb)cb(r);}else{CM.toast(r.message||'Error','error');if(failCb)failCb();}},error:function(xhr){var m='Error';try{m=JSON.parse(xhr.responseText).message||m;}catch(e){}CM.toast(m,'error');if(failCb)failCb();}});};

var LOG = {};

LOG.load = function() {
    // Stats
    CM.ajax('communication/get_log_stats', {}, function(r) {
        var s = r.stats || {};
        $('#lsTotal').text(s.total || 0);
        $('#lsDelivered').text(s.delivered || 0);
        $('#lsFailed').text(s.failed || 0);
        $('#lsBounced').text(s.bounced || 0);
    }, null, function() {
        $('#lsTotal,#lsDelivered,#lsFailed,#lsBounced').text('0');
    });

    // Logs
    CM.ajax('communication/get_logs', {}, function(r) {
        var list = r.logs || [];
        var html = '';
        if (!list.length) {
            html = '<tr><td colspan="8" class="cm-empty"><i class="fa fa-list-alt"></i> No delivery logs yet</td></tr>';
        } else {
            list.forEach(function(l) {
                var statusMap = {delivered:'cm-badge-green',failed:'cm-badge-rose',bounced:'cm-badge-amber'};
                html += '<tr>' +
                    '<td style="font-family:var(--font-m);font-size:11px">' + CM.esc(l.id) + '</td>' +
                    '<td style="font-family:var(--font-m);font-size:11px">' + CM.esc(l.queue_id||'') + '</td>' +
                    '<td>' + CM.esc(l.channel||'') + '</td>' +
                    '<td>' + CM.esc(l.recipient_id||'') + '<div style="font-size:11px;color:var(--t3)">' + CM.esc(l.recipient_contact||'') + '</div></td>' +
                    '<td><span class="cm-badge ' + (statusMap[l.status]||'cm-badge-amber') + '">' + CM.esc(l.status) + '</span></td>' +
                    '<td style="font-size:11px">' + CM.esc(l.response_code||'') + ' ' + CM.esc(l.response_message||'') + '</td>' +
                    '<td style="font-size:11px">' + CM.esc(l.gateway||'') + '</td>' +
                    '<td style="font-size:11px">' + CM.esc((l.logged_at||'').substring(0,16).replace('T',' ')) + '</td>' +
                    '</tr>';
            });
        }
        $('#logsTbody').html(html);
    }, null, function() {
        $('#logsTbody').html('<tr><td colspan="8" class="cm-empty"><i class="fa fa-exclamation-circle"></i> Failed to load logs</td></tr>');
    });
};

document.addEventListener('DOMContentLoaded', function(){ LOG.load(); });
</script>
