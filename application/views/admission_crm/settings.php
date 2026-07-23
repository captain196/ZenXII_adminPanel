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
$formFields  = $settings['form_fields'] ?? [];
$stdFields = [
    ['student_name','Student name', true, true],
    ['dob','Date of birth', true, true],
    ['parent_name','Parent / guardian', true, true],
    ['phone','Phone', true, true],
    ['email','Email', true, false],
    ['previous_school','Previous school', true, false],
    ['blood_group','Blood group', false, false],
    ['category','Category', false, false],
    ['documents','Upload documents', false, false],
];
?>

<style>
html { font-size:16px !important; }
.ac-wrap { padding:24px 22px 52px; min-height:100vh; }
.ac-head { display:flex; align-items:center; gap:14px; padding:18px 22px; margin-bottom:16px;
    background:var(--bg2); border:1px solid var(--border); border-radius:var(--r); box-shadow:var(--sh); }
.ac-head-icon { width:44px; height:44px; border-radius:10px; background:var(--gold); display:flex; align-items:center; justify-content:center;
    flex-shrink:0; box-shadow:0 0 18px var(--gold-glow); }
.ac-head-icon i { color:#fff; font-size:18px; }
.ac-head-info { flex:1; }
.ac-head-title { font-size:18px; font-weight:700; color:var(--t1); font-family:var(--font-d); }
.ac-head-sub { font-size:12px; color:var(--t3); margin-top:2px; }
.ac-back { color:var(--t3); font-size:13px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; margin-bottom:14px; transition:color var(--ease); }
.ac-back:hover { color:var(--gold); }

.ac-btn { display:inline-flex; align-items:center; gap:7px; padding:9px 16px; border-radius:9px; border:none;
    font-size:13px; font-weight:600; cursor:pointer; transition:all var(--ease); font-family:var(--font-b); text-decoration:none; }
.ac-btn-primary { background:var(--gold); color:#fff; box-shadow:0 2px 10px var(--gold-ring); }
.ac-btn-primary:hover { background:var(--gold2); }
.ac-btn-ghost { background:transparent; color:var(--t2); border:1px solid var(--border); }
.ac-btn-ghost:hover { border-color:var(--gold); color:var(--gold); }
.ac-btn-sm { padding:6px 12px; font-size:12px; }

/* Tabs */
.set-tabs { display:flex; gap:4px; border-bottom:1px solid var(--border); margin-bottom:18px; flex-wrap:wrap; }
.set-tab { border:0; background:none; color:var(--t2); font-size:13px; font-weight:600; padding:11px 14px; border-bottom:2px solid transparent; margin-bottom:-1px; cursor:pointer; font-family:var(--font-b); }
.set-tab.on { color:var(--gold); border-color:var(--gold); }
.set-tab:hover { color:var(--t1); }
.set-pane { display:none; }
.set-pane.on { display:block; animation:fade .15s ease; }
@keyframes fade { from { opacity:0; transform:translateY(3px); } to { opacity:1; transform:none; } }

/* Transparent container — the ROWS are the white cards (matches prototype). */
.ac-card { max-width:820px; background:transparent; border:0; padding:0; box-shadow:none; }
.ac-card-title { font-size:14px; font-weight:700; color:var(--t1); margin-bottom:6px; display:flex; align-items:center; gap:8px; }
.ac-card-title i { color:var(--gold); font-size:15px; }
.ac-card-desc { font-size:12px; color:var(--t3); margin-bottom:16px; }

.ac-stage-list { display:flex; flex-direction:column; gap:8px; }
.ac-stage-item { display:flex; align-items:center; gap:10px; padding:10px 14px; background:var(--bg2); box-shadow:0 1px 3px rgba(0,0,0,.05); border:1px solid var(--border); border-radius:8px; transition:border-color var(--ease); }
.ac-stage-item:hover { border-color:var(--gold); }
.ac-stage-key { font-size:11px; color:var(--t3); font-family:var(--font-m); min-width:150px; letter-spacing:.3px; }
.ac-stage-item input { flex:1; padding:7px 10px; border:1px solid var(--border); border-radius:6px; background:var(--bg2); color:var(--t1); font-size:13px; font-family:var(--font-b); outline:none; }
.ac-stage-item input:focus { border-color:var(--gold); box-shadow:0 0 0 3px var(--gold-ring); }
.ac-stage-del { border:none; background:none; color:var(--t3); cursor:pointer; font-size:16px; width:28px; height:28px; border-radius:6px; display:flex; align-items:center; justify-content:center; }
.ac-stage-del:hover { color:#C0402E; background:rgba(219,90,72,.1); }

.ac-limit-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:10px; }
.ac-limit-item { display:flex; align-items:center; gap:10px; padding:10px 14px; background:var(--bg2); box-shadow:0 1px 3px rgba(0,0,0,.05); border:1px solid var(--border); border-radius:8px; }
.ac-limit-item label { font-size:13px; color:var(--t1); flex:1; font-weight:500; }
.ac-limit-item input { width:72px; padding:7px 10px; border:1px solid var(--border); border-radius:6px; background:var(--bg2); color:var(--t1); font-size:13px; text-align:center; font-family:var(--font-m); outline:none; }
.ac-limit-item input:focus { border-color:var(--gold); box-shadow:0 0 0 3px var(--gold-ring); }

.set-row { display:flex; align-items:center; gap:12px; padding:12px 14px; background:var(--bg2); box-shadow:0 1px 3px rgba(0,0,0,.05); border:1px solid var(--border); border-radius:8px; margin-bottom:8px; }
.set-row .sn { font-weight:600; font-size:13.5px; color:var(--t1); }
.set-row .req { font-size:11px; color:var(--t3); margin-left:8px; }
.tgl { position:relative; width:40px; height:23px; flex:0 0 auto; margin-left:auto; }
.tgl input { opacity:0; width:0; height:0; position:absolute; }
.tgl span { position:absolute; inset:0; background:var(--border); border-radius:20px; cursor:pointer; transition:.15s; }
.tgl span::after { content:''; position:absolute; top:2px; left:2px; width:19px; height:19px; border-radius:50%; background:#fff; transition:.15s; }
.tgl input:checked + span { background:#2FA875; }
.tgl input:checked + span::after { transform:translateX(17px); }
.tgl input:disabled + span { opacity:.55; cursor:not-allowed; }

.field { margin-bottom:16px; max-width:520px; }
.field label { display:block; font-size:12.5px; font-weight:600; color:var(--t2); margin-bottom:6px; }
.field input, .field select { width:100%; padding:9px 12px; border:1px solid var(--border); border-radius:8px; background:var(--bg2); color:var(--t1); font-size:13.5px; font-family:var(--font-b); outline:none; }
.linkbox { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.linkbox input { flex:1; min-width:240px; padding:9px 12px; border:1px solid var(--border); border-radius:8px; background:var(--bg2); color:var(--t1); font-size:13px; font-family:var(--font-m); }
.note { display:flex; gap:10px; background:var(--gold-dim); border:1px solid var(--border); border-radius:10px; padding:12px 14px; font-size:12.5px; color:var(--t2); margin-bottom:16px; max-width:820px; }
.note b { color:var(--gold); }
.chip-ok { display:inline-flex; padding:3px 10px; border-radius:20px; font-size:11.5px; font-weight:650; background:rgba(47,168,117,.14); color:#2FA875; }

.ac-alert { padding:12px 16px; border-radius:8px; font-size:13px; display:none; margin-bottom:16px; }
.ac-alert-success { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
.ac-alert-error { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
@media(max-width:640px) { .ac-limit-grid { grid-template-columns:1fr; } .ac-head { flex-wrap:wrap; } }
</style>

<div class="content-wrapper">
<div class="ac-wrap">

    <a href="<?= base_url('admission_crm') ?>" class="ac-back"><i class="fa fa-arrow-left"></i> Back to Overview</a>

    <div class="ac-head">
        <div class="ac-head-icon"><i class="fa fa-cog"></i></div>
        <div class="ac-head-info">
            <div class="ac-head-title">Admission Settings</div>
            <div class="ac-head-sub">Pipeline stages, seat limits, the application form, notifications, fee &amp; the public form — all in one place</div>
        </div>
        <button class="ac-btn ac-btn-primary" onclick="saveAllSettings()"><i class="fa fa-save"></i> Save Settings</button>
    </div>

    <div id="pageAlert" class="ac-alert"></div>

    <div class="set-tabs">
        <button class="set-tab on" data-t="stages" onclick="setTab('stages',this)">Pipeline Stages</button>
        <button class="set-tab" data-t="limits" onclick="setTab('limits',this)">Class Limits</button>
        <button class="set-tab" data-t="form" onclick="setTab('form',this)">Application Form</button>
        <button class="set-tab" data-t="notif" onclick="setTab('notif',this)">Notifications</button>
        <button class="set-tab" data-t="fee" onclick="setTab('fee',this)">Fee &amp; Payment</button>
        <button class="set-tab" data-t="public" onclick="setTab('public',this)">Public Form</button>
    </div>

    <!-- Pipeline Stages -->
    <div class="set-pane on" data-t="stages">
        <div class="ac-card">
            <div class="ac-card-title"><i class="fa fa-columns"></i> Pipeline Stages</div>
            <div class="ac-card-desc">The stages an application moves through — these are the columns on the Applications <b>Board</b>. "Approved" and "Rejected" are required.</div>
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
    </div>

    <!-- Class Seat Limits -->
    <div class="set-pane" data-t="limits">
        <div class="ac-card">
            <div class="ac-card-title"><i class="fa fa-users"></i> Class Seat Limits</div>
            <div class="ac-card-desc">Max seats per class. When a class is full, new approvals auto-route to the Waiting List. Leave blank for no limit.</div>
            <div class="ac-limit-grid" id="limitGrid">
                <?php $limitIdx = 0; foreach ($classes as $c):
                    $cls = str_replace('Class ', '', $c['class_name']);
                    $limit = $classLimits[$cls] ?? '';
                    $limitIdx++;
                ?>
                <div class="ac-limit-item">
                    <label for="acLimit<?= $limitIdx ?>"><?= htmlspecialchars($c['class_name']) ?> <?= htmlspecialchars($c['section']) ?></label>
                    <input type="number" id="acLimit<?= $limitIdx ?>" min="0" value="<?= htmlspecialchars($limit) ?>" data-class="<?= htmlspecialchars($cls . '_' . $c['section']) ?>" placeholder="--">
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Application Form -->
    <div class="set-pane" data-t="form">
        <div class="ac-card">
            <div class="ac-card-title"><i class="fa fa-list-alt"></i> Application Form</div>
            <div class="ac-card-desc">Choose which fields appear on the public admission form. Required fields can't be turned off.</div>
            <div id="formFieldsList">
                <?php foreach ($stdFields as $f):
                    $on = $f[3] ? true : (isset($formFields[$f[0]]) ? (bool)$formFields[$f[0]] : $f[2]);
                ?>
                <div class="set-row">
                    <span class="sn"><?= htmlspecialchars($f[1]) ?></span>
                    <?php if ($f[3]): ?><span class="req">required</span><?php endif; ?>
                    <label class="tgl">
                        <input type="checkbox" data-key="<?= htmlspecialchars($f[0]) ?>" <?= $on ? 'checked' : '' ?> <?= $f[3] ? 'disabled' : '' ?>>
                        <span></span>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Notifications -->
    <div class="set-pane" data-t="notif">
        <div class="ac-card">
            <div class="ac-card-title"><i class="fa fa-bell-o"></i> Notifications</div>
            <div class="ac-card-desc">SMS notifications for admission events. Delivery requires an SMS gateway to be configured.</div>
            <div class="set-row"><span class="sn">Notify on new inquiry</span><label class="tgl"><input type="checkbox" id="notifyNewInquiry" <?= !empty($settings['notifications']['new_inquiry']) ? 'checked' : '' ?>><span></span></label></div>
            <div class="set-row"><span class="sn">Notify on new application</span><label class="tgl"><input type="checkbox" id="notifyNewApp" <?= !empty($settings['notifications']['new_application']) ? 'checked' : '' ?>><span></span></label></div>
            <div class="set-row"><span class="sn">Notify on approval / rejection</span><label class="tgl"><input type="checkbox" id="notifyApproval" <?= !empty($settings['notifications']['approval']) ? 'checked' : '' ?>><span></span></label></div>
        </div>
    </div>

    <!-- Fee & Payment -->
    <div class="set-pane" data-t="fee">
        <div class="ac-card">
            <div class="ac-card-title"><i class="fa fa-credit-card"></i> Fee &amp; Payment</div>
            <div class="ac-card-desc">Set the admission fee and the online payment gateway used by the public form — configured with the school's payment settings.</div>
            <div class="note"><span>&#9679;</span><div>Managed on the <b>Admission Payment</b> screen (fee amount, Razorpay gateway &amp; webhook).</div></div>
            <a href="<?= base_url('school_config/admission_payment') ?>" class="ac-btn ac-btn-ghost"><i class="fa fa-sliders"></i> Configure Fee &amp; Payment</a>
        </div>
    </div>

    <!-- Public Form -->
    <div class="set-pane" data-t="public">
        <div class="ac-card">
            <div class="ac-card-title"><i class="fa fa-globe"></i> Public Admission Form</div>
            <div class="ac-card-desc">Share this link with parents to accept applications online — submissions land straight in your Applications pipeline.</div>
            <?php if (!empty($school_id)): $formUrl = base_url('admission/form/' . rawurlencode($school_id)); ?>
            <div class="field"><label>Status</label><span class="chip-ok">&#9679; Live — accepting applications</span></div>
            <div class="field">
                <label for="publicFormLink">Shareable link</label>
                <div class="linkbox">
                    <input type="text" readonly value="<?= htmlspecialchars($formUrl) ?>" id="publicFormLink" onclick="this.select()">
                    <button type="button" class="ac-btn ac-btn-ghost ac-btn-sm" onclick="copyFormLink(this)"><i class="fa fa-copy"></i> Copy</button>
                    <a href="<?= htmlspecialchars($formUrl) ?>" target="_blank" rel="noopener" class="ac-btn ac-btn-ghost ac-btn-sm"><i class="fa fa-external-link"></i> Open</a>
                </div>
            </div>
            <?php else: ?>
            <a href="<?= base_url('school_config/admission_payment') ?>" class="ac-btn ac-btn-ghost"><i class="fa fa-globe"></i> Manage Public Form</a>
            <?php endif; ?>
        </div>
    </div>

</div>
</div>

<script>
var BASE = '<?= base_url() ?>';

function setTab(t, btn) {
    document.querySelectorAll('.set-tab').forEach(function(x){ x.classList.remove('on'); });
    btn.classList.add('on');
    document.querySelectorAll('.set-pane').forEach(function(p){ p.classList.toggle('on', p.getAttribute('data-t') === t); });
}

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
    var formFields = {};
    document.querySelectorAll('#formFieldsList input[type=checkbox]').forEach(function(cb) {
        formFields[cb.getAttribute('data-key')] = cb.checked;
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
            form_fields: JSON.stringify(formFields),
            notifications: JSON.stringify(notifications),
        }).toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) { showAlert(data.message, data.status === 'success' ? 'success' : 'error'); })
    .catch(function() { showAlert('Save failed', 'error'); });
}

function copyFormLink(btn) {
    var i = document.getElementById('publicFormLink'); if (!i) return;
    i.select(); i.setSelectionRange(0, 99999);
    if (navigator.clipboard) { navigator.clipboard.writeText(i.value).catch(function(){}); }
    else { try { document.execCommand('copy'); } catch (e) {} }
    var t = btn.innerHTML; btn.innerHTML = '<i class="fa fa-check"></i> Copied';
    setTimeout(function(){ btn.innerHTML = t; }, 1500);
}

function showAlert(msg, type) {
    var el = document.getElementById('pageAlert');
    el.className = 'ac-alert ac-alert-' + type; el.textContent = msg; el.style.display = 'block';
    setTimeout(function() { el.style.display = 'none'; }, 4000);
}
function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
</script>
