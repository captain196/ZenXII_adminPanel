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
$at = $active_tab ?? 'dashboard';
?>
<style>
.cm-wrap{padding:20px;max-width:1400px;margin:0 auto}
.cm-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.cm-header-icon{font-family:var(--font-b);font-size:1.3rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px}
.cm-header-icon i{color:var(--gold);font-size:1.1rem}
.cm-breadcrumb{list-style:none;display:flex;gap:6px;font-size:12px;color:var(--t3);padding:0;margin:6px 0 0;font-family:var(--font-b)}
.cm-breadcrumb a{color:var(--gold);text-decoration:none}
.cm-breadcrumb li+li::before{content:">";margin-right:6px;color:var(--t4)}
.cm-tabs{display:flex;gap:4px;margin-bottom:24px;border-bottom:1px solid var(--border);overflow-x:auto;-webkit-overflow-scrolling:touch}
.cm-tab{padding:10px 16px;font-size:13px;font-weight:600;color:var(--t3);text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap;transition:all var(--ease);font-family:var(--font-b)}
.cm-tab:hover{color:var(--t1)} .cm-tab.active{color:var(--gold);border-bottom-color:var(--gold)} .cm-tab i{margin-right:6px;font-size:14px}
.cm-card{background:var(--card,var(--bg2));border:1px solid var(--border);border-radius:var(--r,10px);padding:20px;margin-bottom:18px}
.cm-card-title{font-family:var(--font-b);font-size:14px;font-weight:700;color:var(--t1);margin-bottom:14px;display:flex;align-items:center;justify-content:space-between}
.cm-btn{padding:8px 16px;border-radius:var(--r-sm,6px);font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all var(--ease);font-family:var(--font-b)}
.cm-btn-primary{background:var(--gold);color:#fff} .cm-btn-primary:hover{background:var(--gold2)}
.cm-btn-danger{background:var(--rose,#ef4444);color:#fff} .cm-btn-sm{padding:6px 12px;font-size:12px}
.cm-btn-outline{background:transparent;border:1px solid var(--gold);color:var(--gold)} .cm-btn-outline:hover{background:var(--gold-dim)}
.cm-stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px}
.cm-stat{background:var(--card,var(--bg2));border:1px solid var(--border);border-radius:var(--r,10px);padding:20px;display:flex;align-items:center;gap:16px}
.cm-stat-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px}
.cm-stat-icon.teal{background:rgba(15,118,110,.12);color:var(--gold)}
.cm-stat-icon.blue{background:rgba(59,130,246,.12);color:#3b82f6}
.cm-stat-icon.amber{background:rgba(245,158,11,.12);color:#f59e0b}
.cm-stat-icon.rose{background:rgba(239,68,68,.12);color:#ef4444}
.cm-stat-icon.green{background:rgba(34,197,94,.12);color:#22c55e}
.cm-stat-icon.purple{background:rgba(139,92,246,.12);color:#8b5cf6}
.cm-stat-val{font-size:24px;font-weight:700;color:var(--t1);font-family:var(--font-b);line-height:1}
.cm-stat-lbl{font-size:12px;color:var(--t3);margin-top:2px;font-family:var(--font-b)}
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
.cm-form-group{margin-bottom:14px}
.cm-form-group label{display:block;font-size:11px;font-weight:600;color:var(--t3);margin-bottom:4px;font-family:var(--font-b);text-transform:uppercase;letter-spacing:.3px}
.cm-form-group input,.cm-form-group select,.cm-form-group textarea{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--t1);font-size:13px;font-family:var(--font-b)}
.cm-form-group input:focus,.cm-form-group select:focus,.cm-form-group textarea:focus{border-color:var(--gold);outline:none}
.cm-form-group textarea{resize:vertical;min-height:80px}
.cm-form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
/* Modal/toast/form styles inherited from header.php global definitions */
.cm-modal{width:600px}
.cm-empty{text-align:center;padding:40px 20px;color:var(--t3);font-family:var(--font-b)}
.cm-empty i{font-size:36px;display:block;margin-bottom:12px;opacity:.5}
.cm-loading{text-align:center;padding:18px 20px;color:var(--t3);font-size:13px;font-family:var(--font-b)}
.cm-toast{position:fixed;top:20px;right:20px;z-index:10000;padding:12px 20px;border-radius:8px;font-size:13px;font-weight:600;font-family:var(--font-b);color:#fff;display:none;max-width:400px;box-shadow:0 8px 24px rgba(0,0,0,.15)}
.cm-toast.success{background:#22c55e;display:block} .cm-toast.error{background:#ef4444;display:block}
</style>

<div class="content-wrapper"><section class="content"><div class="cm-wrap">

<div class="cm-header"><div>
    <div class="cm-header-icon"><i class="fa fa-comments"></i> Communication</div>
    <ol class="cm-breadcrumb"><li><a href="<?= base_url('admin') ?>">Dashboard</a></li><li>Communication</li></ol>
</div></div>

<nav class="cm-tabs">
    <?php foreach ($tabs as $slug => $t): ?>
    <a class="cm-tab<?= $at === $slug ? ' active' : '' ?>" href="<?= base_url($t['url']) ?>"><i class="fa <?= $t['icon'] ?>"></i> <?= $t['label'] ?></a>
    <?php endforeach; ?>
</nav>

<!-- Dashboard Content (server-rendered — no AJAX spinner) -->
<?php
    $esc = function($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); };
    $_conversations  = $conversations ?? 0;
    $_unread         = $unread_messages ?? 0;
    $_notices        = $notices ?? 0;
    $_circulars      = $circulars ?? 0;
    $_qSent          = $queue_sent ?? 0;
    $_qPending       = $queue_pending ?? 0;
    $_qFailed        = $queue_failed ?? 0;
    $_recentNotices  = $recent_notices ?? [];
?>
<div id="dashboardContent">
    <div class="cm-stat-grid">
        <div class="cm-stat"><div class="cm-stat-icon teal"><i class="fa fa-comments"></i></div><div><div class="cm-stat-val"><?= (int)$_conversations ?></div><div class="cm-stat-lbl">Conversations</div></div></div>
        <div class="cm-stat"><div class="cm-stat-icon blue"><i class="fa fa-envelope"></i></div><div><div class="cm-stat-val"><?= (int)$_unread ?></div><div class="cm-stat-lbl">Unread Messages</div></div></div>
        <div class="cm-stat"><div class="cm-stat-icon amber"><i class="fa fa-bullhorn"></i></div><div><div class="cm-stat-val"><?= (int)$_notices ?></div><div class="cm-stat-lbl">Notices</div></div></div>
        <div class="cm-stat"><div class="cm-stat-icon purple"><i class="fa fa-file-text-o"></i></div><div><div class="cm-stat-val"><?= (int)$_circulars ?></div><div class="cm-stat-lbl">Circulars</div></div></div>
        <div class="cm-stat"><div class="cm-stat-icon green"><i class="fa fa-check-circle"></i></div><div><div class="cm-stat-val"><?= (int)$_qSent ?></div><div class="cm-stat-lbl">Messages Sent</div></div></div>
        <div class="cm-stat"><div class="cm-stat-icon rose"><i class="fa fa-clock-o"></i></div><div><div class="cm-stat-val"><?= (int)$_qPending ?></div><div class="cm-stat-lbl">Queue Pending</div></div></div>
    </div>

    <div class="cm-card">
        <div class="cm-card-title"><span>Recent Notices</span><a href="<?= base_url('communication/notices') ?>" class="cm-btn cm-btn-sm cm-btn-outline">View All</a></div>
        <table class="cm-table"><thead><tr><th>Title</th><th>Category</th><th>Target</th><th>Date</th><th>Priority</th></tr></thead><tbody>
        <?php if (empty($_recentNotices)): ?>
            <tr><td colspan="5" class="cm-empty"><i class="fa fa-bell-slash-o"></i> No notices yet</td></tr>
        <?php else: ?>
            <?php foreach ($_recentNotices as $n):
                $pri = $n['priority'] ?? 'Normal';
                $priClass = $pri === 'High' ? 'cm-badge-rose' : ($pri === 'Low' ? 'cm-badge-blue' : 'cm-badge-amber');
            ?>
            <tr>
                <td><?= $esc($n['title'] ?? '') ?></td>
                <td><span class="cm-badge cm-badge-blue"><?= $esc($n['category'] ?? 'General') ?></span></td>
                <td><?= $esc($n['target_group'] ?? '') ?></td>
                <td><?= $esc(substr($n['created_at'] ?? '', 0, 10)) ?></td>
                <td><span class="cm-badge <?= $priClass ?>"><?= $esc($pri) ?></span></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody></table>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px">
        <div class="cm-card">
            <div class="cm-card-title"><span>Quick Actions</span></div>
            <div style="display:flex;flex-direction:column;gap:10px">
                <a href="<?= base_url('communication/messages') ?>" class="cm-btn cm-btn-primary" style="text-align:center"><i class="fa fa-plus"></i> New Message</a>
                <a href="<?= base_url('communication/notices') ?>" class="cm-btn cm-btn-outline" style="text-align:center"><i class="fa fa-bullhorn"></i> Post Notice</a>
                <a href="<?= base_url('communication/circulars') ?>" class="cm-btn cm-btn-outline" style="text-align:center"><i class="fa fa-file-text-o"></i> Upload Circular</a>
            </div>
        </div>
        <div class="cm-card">
            <div class="cm-card-title"><span>Queue Overview</span></div>
            <div style="display:flex;gap:16px;flex-wrap:wrap">
                <div style="flex:1;text-align:center;padding:12px;border-radius:8px;background:rgba(34,197,94,.08)">
                    <div style="font-size:20px;font-weight:700;color:#22c55e"><?= (int)$_qSent ?></div>
                    <div style="font-size:11px;color:var(--t3)">Sent</div>
                </div>
                <div style="flex:1;text-align:center;padding:12px;border-radius:8px;background:rgba(245,158,11,.08)">
                    <div style="font-size:20px;font-weight:700;color:#f59e0b"><?= (int)$_qPending ?></div>
                    <div style="font-size:11px;color:var(--t3)">Pending</div>
                </div>
                <div style="flex:1;text-align:center;padding:12px;border-radius:8px;background:rgba(239,68,68,.08)">
                    <div style="font-size:20px;font-weight:700;color:#ef4444"><?= (int)$_qFailed ?></div>
                    <div style="font-size:11px;color:var(--t3)">Failed</div>
                </div>
            </div>
            <button class="cm-btn cm-btn-sm cm-btn-outline" style="margin-top:12px;width:100%" onclick="CM.processQueue()"><i class="fa fa-play"></i> Process Queue Now</button>
        </div>
    </div>
</div>

</div></section></div>

<div class="cm-toast" id="cmToast"></div>

<script>
var CM = CM || {};
CM.BASE = '<?= base_url() ?>';

CM.toast = function(msg, type) {
    var t = document.getElementById('cmToast');
    t.textContent = msg; t.className = 'cm-toast ' + (type || 'success');
    setTimeout(function(){ t.className = 'cm-toast'; }, 3000);
};

CM.ajax = function(url, data, cb, method, failCb) {
    $.ajax({
        url: CM.BASE + url,
        type: method || 'GET',
        data: data,
        dataType: 'json',
        success: function(r) {
            if (r.status === 'success') { if (cb) cb(r); }
            else { CM.toast(r.message || 'Error', 'error'); if (failCb) failCb(); }
        },
        error: function(xhr) {
            var msg = 'Request failed';
            try { msg = JSON.parse(xhr.responseText).message || msg; } catch(e){}
            CM.toast(msg, 'error');
            if (failCb) failCb();
        }
    });
};

CM.processQueue = function() {
    CM.ajax('communication/process_queue', {}, function(r) {
        CM.toast(r.message || 'Queue processed');
    }, 'POST');
};
</script>
