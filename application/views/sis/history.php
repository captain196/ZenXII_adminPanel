<?php defined('BASEPATH') or exit('No direct script access allowed');

$userId = $student['User Id'] ?? '';
$name   = $student['Name'] ?? 'Student';

$actionColors = [
    'ADMISSION'       => ['bg' => '#dcfce7', 'text' => '#166534', 'icon' => 'fa-user-plus'],
    'PROFILE_UPDATE'  => ['bg' => '#dbeafe', 'text' => '#1e40af', 'icon' => 'fa-edit'],
    'PROMOTION'       => ['bg' => '#fef9c3', 'text' => '#92400e', 'icon' => 'fa-level-up'],
    'TC_ISSUED'       => ['bg' => '#fee2e2', 'text' => '#991b1b', 'icon' => 'fa-file-text-o'],
    'TC_CANCELLED'    => ['bg' => '#f3f4f6', 'text' => '#374151', 'icon' => 'fa-times'],
    'DOCUMENT_UPLOAD' => ['bg' => '#f3e8ff', 'text' => '#6b21a8', 'icon' => 'fa-upload'],
    'DOCUMENT_DELETE' => ['bg' => '#fef3c7', 'text' => '#78350f', 'icon' => 'fa-trash'],
];
?>

<style>
html { font-size: 16px !important; }
.hist-wrap { max-width:860px; margin:0 auto; padding:24px 20px; }
.page-hdr  { margin-bottom:20px; }
.page-hdr h1 { margin:0 0 4px; font-size:1.2rem; color:var(--t1); font-family:var(--font-b); }
.page-hdr p  { margin:0; color:var(--t3); font-size:.85rem; }

.timeline { position:relative; padding-left:32px; }
.timeline::before { content:''; position:absolute; left:10px; top:0; bottom:0;
    width:2px; background:var(--border); }

.tl-item { position:relative; margin-bottom:20px; }
.tl-dot  { position:absolute; left:-26px; top:6px; width:14px; height:14px;
    border-radius:50%; border:2px solid var(--bg2); box-shadow:0 0 0 2px var(--border); }

.tl-card { background:var(--bg2); border:1px solid var(--border); border-radius:8px; padding:14px 16px; }
.tl-header { display:flex; align-items:center; gap:10px; margin-bottom:6px; }
.tl-action { font-size:.82rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em;
    padding:3px 10px; border-radius:20px; font-family:var(--font-m); }
.tl-time { font-size:.82rem; color:var(--t3); margin-left:auto; }
.tl-desc { font-size:.9rem; color:var(--t1); margin-bottom:4px; }
.tl-by   { font-size:.84rem; color:var(--t3); }

.empty-state { text-align:center; padding:60px; color:var(--t3); }
.empty-state i { font-size:3rem; display:block; margin-bottom:10px; }
</style>

<div class="content-wrapper">
<div class="hist-wrap">

    <div style="margin-bottom:14px;">
        <a href="<?= base_url('sis/profile/' . urlencode($userId)) ?>" style="color:var(--t3);font-size:.85rem;text-decoration:none;">
            <i class="fa fa-arrow-left"></i> Back to Profile
        </a>
    </div>

    <div class="page-hdr">
        <h1><i class="fa fa-history" style="color:var(--gold);margin-right:8px;"></i>Student History</h1>
        <p><?= htmlspecialchars($name) ?> (<?= htmlspecialchars($userId) ?>)</p>
    </div>

    <?php if (empty($history)): ?>
    <div class="empty-state">
        <i class="fa fa-history"></i>
        No history recorded for this student.
    </div>
    <?php else: ?>
    <div class="timeline">
        <?php foreach ($history as $h):
            if (!is_array($h)) continue;
            $action = $h['action'] ?? 'ACTION';
            $colors = $actionColors[$action] ?? ['bg' => 'var(--bg3)', 'text' => 'var(--t2)', 'icon' => 'fa-circle-o'];
        ?>
        <div class="tl-item">
            <div class="tl-dot" style="background:<?= $colors['bg'] ?>;"></div>
            <div class="tl-card">
                <div class="tl-header">
                    <span class="tl-action" style="background:<?= $colors['bg'] ?>;color:<?= $colors['text'] ?>;">
                        <i class="fa <?= $colors['icon'] ?>"></i>
                        <?= htmlspecialchars($action) ?>
                    </span>
                    <span class="tl-time"><?= htmlspecialchars($h['changed_at'] ?? '') ?></span>
                </div>
                <div class="tl-desc"><?= htmlspecialchars($h['description'] ?? '') ?></div>
                <div class="tl-by">by <?= htmlspecialchars($h['changed_by'] ?? 'System') ?></div>

                <?php if (!empty($h['metadata']) && is_array($h['metadata'])): ?>
                <details style="margin-top:8px;">
                    <summary style="font-size:.84rem;color:var(--t3);cursor:pointer;">Details</summary>
                    <?php // FIXED: array_map('strval') crashes on nested arrays (e.g. Address) — encode directly ?>
                    <pre style="font-size:.84rem;color:var(--t3);margin-top:6px;overflow:auto;white-space:pre-wrap;word-break:break-all;"><?= htmlspecialchars(
                        json_encode(
                            $h['metadata'],
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                        ),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?></pre>
                </details>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>
</div>
