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
$at = $active_tab ?? 'messages';
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

/* Coming Soon layout */
.cs-card{background:var(--card,var(--bg2));border:1px solid var(--border);border-radius:var(--r,12px);padding:64px 32px;text-align:center;max-width:680px;margin:48px auto;box-shadow:0 6px 24px rgba(0,0,0,.04)}
.cs-icon-wrap{width:96px;height:96px;border-radius:50%;background:var(--gold-dim);display:flex;align-items:center;justify-content:center;margin:0 auto 24px}
.cs-icon-wrap i{font-size:44px;color:var(--gold)}
.cs-badge{display:inline-block;font-family:var(--font-b);font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--gold);background:var(--gold-dim);padding:6px 14px;border-radius:999px;margin-bottom:18px}
.cs-title{font-family:var(--font-b);font-size:28px;font-weight:700;color:var(--t1);margin:0 0 14px;line-height:1.25}
.cs-body{font-family:var(--font-b);font-size:14px;color:var(--t3);line-height:1.7;max-width:520px;margin:0 auto 28px}
.cs-cta-row{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-bottom:22px}
.cs-btn{padding:11px 22px;border-radius:8px;font-family:var(--font-b);font-size:13px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:8px;transition:all var(--ease);border:1px solid transparent}
.cs-btn-primary{background:var(--gold);color:#fff}
.cs-btn-primary:hover{background:var(--gold2,var(--gold));opacity:.92}
.cs-btn-outline{background:transparent;color:var(--t1);border-color:var(--border)}
.cs-btn-outline:hover{background:var(--gold-dim);border-color:var(--gold)}
.cs-note{font-family:var(--font-b);font-size:12px;color:var(--t4,var(--t3));border-top:1px solid var(--border);padding-top:18px;margin-top:8px;line-height:1.7}
.cs-note strong{color:var(--t1);font-weight:700}
@media(max-width:640px){.cs-card{padding:40px 22px;margin:24px 12px} .cs-title{font-size:22px} .cs-icon-wrap{width:76px;height:76px} .cs-icon-wrap i{font-size:34px}}
</style>

<div class="content-wrapper"><section class="content"><div class="cm-wrap">
<div class="cm-header"><div>
    <div class="cm-header-icon"><i class="fa fa-comments"></i> Communication</div>
    <ol class="cm-breadcrumb"><li><a href="<?= base_url('admin') ?>">Dashboard</a></li><li><a href="<?= base_url('communication') ?>">Communication</a></li><li>Messages</li></ol>
</div></div>

<nav class="cm-tabs">
    <?php foreach ($tabs as $slug => $t): ?>
    <a class="cm-tab<?= $at === $slug ? ' active' : '' ?>" href="<?= base_url($t['url']) ?>"><i class="fa <?= $t['icon'] ?>"></i> <?= $t['label'] ?></a>
    <?php endforeach; ?>
</nav>

<div class="cs-card">
    <div class="cs-icon-wrap"><i class="fa fa-comments-o"></i></div>
    <div class="cs-badge">Coming Soon</div>
    <h1 class="cs-title">Direct Messaging is on the way</h1>
    <p class="cs-body">
        We're building a modern in-app messaging experience with read receipts, attachments, and group conversations. Until it launches, please use <strong>Notices</strong> or <strong>Circulars</strong> to communicate with parents and teachers across your school.
    </p>
    <div class="cs-cta-row">
        <a class="cs-btn cs-btn-primary" href="<?= base_url('communication/notices') ?>"><i class="fa fa-bullhorn"></i> Post a Notice</a>
        <a class="cs-btn cs-btn-outline" href="<?= base_url('communication/circulars') ?>"><i class="fa fa-file-text-o"></i> Send a Circular</a>
    </div>
    <p class="cs-note">
        <strong>Note for administrators:</strong> existing conversation history is preserved in the system and remains accessible to authorised personnel for audit purposes. No data has been deleted.
    </p>
</div>

</div></section></div>
