<?php defined('BASEPATH') or exit('No direct script access allowed');

$stages = $settings['stages'] ?? [
    'document_collection' => 'Document Collection',
    'under_review'        => 'Under Review',
    'interview'           => 'Interview / Test',
    'approved'            => 'Approved',
    'rejected'            => 'Rejected',
    'waitlisted'          => 'Waitlisted',
];
$classLimits = $settings['class_limits'] ?? [];
?>

<style>
html { font-size:16px !important; }

.ac-wrap { padding:24px 22px 52px; min-height:100vh; }

/* ── Header ── */
.ac-head {
    display:flex; align-items:center; gap:14px;
    padding:18px 22px; margin-bottom:22px;
    background:var(--bg2); border:1px solid var(--border);
    border-radius:var(--r); box-shadow:var(--sh);
}
.ac-head-icon {
    width:44px; height:44px; border-radius:10px;
    background:var(--gold); display:flex; align-items:center; justify-content:center;
    flex-shrink:0; box-shadow:0 0 18px var(--gold-glow);
}
.ac-head-icon i { color:#fff; font-size:18px; }
.ac-head-info { flex:1; }
.ac-head-title { font-size:18px; font-weight:700; color:var(--t1); font-family:var(--font-d); }
.ac-head-sub { font-size:12px; color:var(--t3); margin-top:2px; }

/* ── Buttons ── */
.ac-btn {
    display:inline-flex; align-items:center; gap:7px;
    padding:9px 18px; border-radius:8px; border:none;
    font-size:13px; font-weight:600; cursor:pointer;
    transition:all var(--ease); font-family:var(--font-b);
}
.ac-btn-primary { background:var(--gold); color:#fff; box-shadow:0 2px 10px var(--gold-ring); }
.ac-btn-primary:hover { background:var(--gold2); }
.ac-btn-ghost { background:transparent; color:var(--t2); border:1px solid var(--border); }
.ac-btn-ghost:hover { border-color:var(--gold); color:var(--gold); }
.ac-btn-sm { padding:5px 12px; font-size:11.5px; }

/* ── Card ── */
.ac-card {
    max-width:960px;
    background:var(--bg2); border:1px solid var(--border);
    border-radius:var(--r); padding:22px; margin-bottom:18px;
    box-shadow:var(--sh);
}
.ac-card-title {
    font-size:14px; font-weight:700; color:var(--t1); margin-bottom:6px;
    display:flex; align-items:center; gap:8px;
}
.ac-card-title i { color:var(--gold); font-size:15px; }
.ac-card-desc { font-size:12px; color:var(--t3); margin-bottom:16px; }

/* ── Stage List ── */
.ac-stage-list { display:flex; flex-direction:column; gap:8px; }
.ac-stage-item {
    display:flex; align-items:center; gap:10px;
    padding:10px 14px; background:var(--bg3); border:1px solid var(--border);
    border-radius:8px; transition:border-color var(--ease);
}
.ac-stage-item:hover { border-color:var(--gold); }
.ac-stage-key {
    font-size:11px; color:var(--t3); font-family:var(--font-m);
    min-width:150px; letter-spacing:.3px;
}
.ac-stage-item input {
    flex:1; padding:7px 10px; border:1px solid var(--border); border-radius:6px;
    background:var(--bg2); color:var(--t1); font-size:13px; font-family:var(--font-b);
    outline:none; transition:border-color var(--ease), box-shadow var(--ease);
}
.ac-stage-item input:focus { border-color:var(--gold); box-shadow:0 0 0 3px var(--gold-ring); }
.ac-stage-del {
    border:none; background:none; color:var(--t3); cursor:pointer;
    font-size:16px; width:28px; height:28px; border-radius:6px;
    display:flex; align-items:center; justify-content:center;
    transition:all var(--ease);
}
.ac-stage-del:hover { color:#ef4444; background:rgba(220,38,38,.1); }

/* ── Limit Grid ── */
.ac-limit-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:10px; }
.ac-limit-item {
    display:flex; align-items:center; gap:10px;
    padding:10px 14px; background:var(--bg3); border:1px solid var(--border);
    border-radius:8px;
}
.ac-limit-item label { font-size:13px; color:var(--t1); flex:1; font-weight:500; }
.ac-limit-item input {
    width:72px; padding:7px 10px; border:1px solid var(--border); border-radius:6px;
    background:var(--bg2); color:var(--t1); font-size:13px; text-align:center;
    font-family:var(--font-m); outline:none;
    transition:border-color var(--ease), box-shadow var(--ease);
}
.ac-limit-item input:focus { border-color:var(--gold); box-shadow:0 0 0 3px var(--gold-ring); }

/* ── Checkboxes ── */
.ac-check {
    display:flex; align-items:center; gap:10px; padding:8px 0;
    font-size:13px; color:var(--t1); cursor:pointer;
}
.ac-check input[type="checkbox"] {
    width:18px; height:18px; accent-color:var(--gold); cursor:pointer;
}

/* ── Misc ── */
.ac-alert { padding:12px 16px; border-radius:8px; font-size:13px; display:none; margin-bottom:16px; }
.ac-alert-success { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
.ac-alert-error { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
.ac-back { color:var(--t3); font-size:13px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; margin-bottom:14px; transition:color var(--ease); }
.ac-back:hover { color:var(--gold); }

@media(max-width:640px) {
    .ac-limit-grid { grid-template-columns:1fr; }
    .ac-stage-key { min-width:100px; font-size:10px; }
}
</style>

<div class="content-wrapper">
<div class="ac-wrap">

    <a href="<?= base_url('admission_crm') ?>" class="ac-back"><i class="fa fa-arrow-left"></i> Back to Dashboard</a>

    <div class="ac-head">
        <div class="ac-head-icon"><i class="fa fa-cog"></i></div>
        <div class="ac-head-info">
            <div class="ac-head-title">Admission Settings</div>
            <div class="ac-head-sub">Configure pipeline stages, seat limits &amp; notifications</div>
        </div>
        <button class="ac-btn ac-btn-primary" onclick="saveAllSettings()"><i class="fa fa-save"></i> Save Settings</button>
    </div>

    <div id="pageAlert" class="ac-alert"></div>

    <!-- Pipeline Stages -->
    <div class="ac-card">
        <div class="ac-card-title"><i class="fa fa-columns"></i> Pipeline Stages</div>
        <div class="ac-card-desc">Define the stages an application moves through during admission. "Approved" and "Rejected" are required.</div>
        <div class="ac-stage-list" id="stageList">
            <?php foreach ($stages as $key => $label): ?>
            <div class="ac-stage-item">
                <span class="ac-stage-key"><?= htmlspecialchars($key) ?></span>
                <input type="text" value="<?= htmlspecialchars($label) ?>" data-key="<?= htmlspecialchars($key) ?>">
                <?php if (!in_array($key, ['approved', 'rejected'])): ?>
                <button class="ac-stage-del" onclick="this.parentElement.remove()" title="Remove">&times;</button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <button class="ac-btn ac-btn-ghost ac-btn-sm" onclick="addStage()" style="margin-top:12px;"><i class="fa fa-plus"></i> Add Stage</button>
    </div>

    <!-- Class Seat Limits -->
    <div class="ac-card">
        <div class="ac-card-title"><i class="fa fa-users"></i> Class Seat Limits</div>
        <div class="ac-card-desc">Set maximum seats per class to manage capacity. Leave blank for no limit.</div>
        <div class="ac-limit-grid" id="limitGrid">
            <?php foreach ($classes as $c):
                $cls = str_replace('Class ', '', $c['class_name']);
                $limit = $classLimits[$cls] ?? '';
            ?>
            <div class="ac-limit-item">
                <label><?= htmlspecialchars($c['class_name']) ?> <?= htmlspecialchars($c['section']) ?></label>
                <input type="number" min="0" value="<?= htmlspecialchars($limit) ?>" data-class="<?= htmlspecialchars($cls . '_' . $c['section']) ?>" placeholder="--">
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Notifications -->
    <div class="ac-card">
        <div class="ac-card-title"><i class="fa fa-bell-o"></i> Notifications</div>
        <div class="ac-card-desc">Configure notification preferences for admission events.</div>
        <label class="ac-check">
            <input type="checkbox" id="notifyNewInquiry" <?= !empty($settings['notifications']['new_inquiry']) ? 'checked' : '' ?>>
            Notify on new inquiry
        </label>
        <label class="ac-check">
            <input type="checkbox" id="notifyNewApp" <?= !empty($settings['notifications']['new_application']) ? 'checked' : '' ?>>
            Notify on new application
        </label>
        <label class="ac-check">
            <input type="checkbox" id="notifyApproval" <?= !empty($settings['notifications']['approval']) ? 'checked' : '' ?>>
            Notify on approval / rejection
        </label>
    </div>

</div>
</div>

<script>
var BASE = '<?= base_url() ?>';

function addStage() {
    var key = prompt('Stage key (lowercase, underscores, e.g. "entrance_test"):');
    if (!key) return;
    key = key.toLowerCase().replace(/[^a-z0-9_]/g, '_');
    var label = prompt('Display label (e.g. "Entrance Test"):');
    if (!label) return;

    var list = document.getElementById('stageList');
    var div = document.createElement('div');
    div.className = 'ac-stage-item';
    div.innerHTML = '<span class="ac-stage-key">' + esc(key) + '</span><input type="text" value="' + esc(label) + '" data-key="' + esc(key) + '"><button class="ac-stage-del" onclick="this.parentElement.remove()" title="Remove">&times;</button>';
    list.appendChild(div);
}

function saveAllSettings() {
    var stages = {};
    document.querySelectorAll('#stageList .ac-stage-item').forEach(function(item) {
        var input = item.querySelector('input');
        var key = input.getAttribute('data-key');
        if (key && input.value.trim()) stages[key] = input.value.trim();
    });

    var limits = {};
    document.querySelectorAll('#limitGrid input').forEach(function(input) {
        var cls = input.getAttribute('data-class');
        if (cls && input.value) limits[cls] = parseInt(input.value) || 0;
    });

    var notifications = {
        new_inquiry: document.getElementById('notifyNewInquiry').checked,
        new_application: document.getElementById('notifyNewApp').checked,
        approval: document.getElementById('notifyApproval').checked,
    };

    fetch(BASE + 'admission_crm/save_settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({
            stages: JSON.stringify(stages),
            class_limits: JSON.stringify(limits),
            notifications: JSON.stringify(notifications),
        }).toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) { showAlert(data.message, data.status === 'success' ? 'success' : 'error'); })
    .catch(function() { showAlert('Save failed', 'error'); });
}

function showAlert(msg, type) {
    var el = document.getElementById('pageAlert');
    el.className = 'ac-alert ac-alert-' + type;
    el.textContent = msg; el.style.display = 'block';
    setTimeout(function() { el.style.display = 'none'; }, 4000);
}
function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
</script>
