<?php
defined('BASEPATH') or exit('No direct script access allowed');
$esc     = function($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); };
$enabled = !empty($config['enabled']);
$amount  = (float) ($config['amount'] ?? 0);
$currency= $config['currency'] ?? 'INR';
$label   = $config['label'] ?? 'Admission Fee';
$sym_map = ['INR'=>'&#8377;','USD'=>'$','GBP'=>'&pound;','EUR'=>'&euro;'];
?>

<div class="content-wrapper">
<section class="content">
<div class="ap-wrap">

    <!-- Topbar -->
    <div class="ap-topbar">
        <h1 class="ap-page-title"><i class="fa fa-credit-card"></i> Admission Payment</h1>
        <ul class="ap-breadcrumb">
            <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
            <li>Configuration</li>
            <li>Admission Payment</li>
        </ul>
    </div>

    <!-- Stats Row -->
    <div class="ap-stats">
        <div class="ap-stat">
            <div class="ap-stat-ic <?= $enabled ? 'ic-on' : 'ic-off' ?>">
                <i class="fa <?= $enabled ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
            </div>
            <div>
                <div class="ap-stat-v" id="statStatus"><?= $enabled ? 'Active' : 'Inactive' ?></div>
                <div class="ap-stat-l">Gateway Status</div>
            </div>
        </div>
        <div class="ap-stat">
            <div class="ap-stat-ic ic-amt"><i class="fa fa-money"></i></div>
            <div>
                <div class="ap-stat-v" id="statAmount"><?= $amount > 0 ? ($currency === 'INR' ? '&#8377;' : $currency.' ').$amount : '--' ?></div>
                <div class="ap-stat-l">Fee Amount</div>
            </div>
        </div>
        <div class="ap-stat">
            <div class="ap-stat-ic ic-cur"><i class="fa fa-globe"></i></div>
            <div>
                <div class="ap-stat-v"><?= $currency ?></div>
                <div class="ap-stat-l">Currency</div>
            </div>
        </div>
    </div>

    <!-- Configuration Card -->
    <div class="ap-card">
        <div class="ap-card-head">
            <i class="fa fa-cog"></i> Payment Configuration
        </div>
        <div class="ap-card-body">
            <form id="apForm" onsubmit="return false;">
                <!-- Enable Toggle -->
                <div class="ap-toggle-row">
                    <label class="ap-switch">
                        <input type="checkbox" id="payEnabled" <?= $enabled ? 'checked' : '' ?>>
                        <span class="ap-switch-slider"></span>
                    </label>
                    <div class="ap-toggle-info">
                        <span class="ap-toggle-label" id="toggleLabel"><?= $enabled ? 'Payment Enabled' : 'Payment Disabled' ?></span>
                        <span class="ap-badge <?= $enabled ? 'ap-badge-green' : 'ap-badge-red' ?>" id="statusBadge"><?= $enabled ? 'Active' : 'Inactive' ?></span>
                    </div>
                    <div class="ap-toggle-sub">When enabled, parents see a "Pay Now" button after submitting the admission form</div>
                </div>

                <!-- Fee Fields -->
                <div id="feeFields" class="<?= $enabled ? '' : 'ap-disabled' ?>">
                    <div class="ap-form-grid">
                        <div class="ap-form-group ap-full">
                            <label class="ap-label">Fee Label</label>
                            <input type="text" class="ap-input" id="feeLabel" value="<?= $esc($label) ?>" maxlength="100" placeholder="e.g. Admission Fee, Registration Fee">
                        </div>
                        <div class="ap-form-group">
                            <label class="ap-label">Amount</label>
                            <div class="ap-input-icon">
                                <span class="ap-input-prefix" id="curSymbol"><?= $sym_map[$currency] ?? $currency ?></span>
                                <input type="number" class="ap-input ap-input-has-prefix" id="feeAmount" value="<?= $amount ?>" min="0" max="500000" step="1" placeholder="500">
                            </div>
                        </div>
                        <div class="ap-form-group">
                            <label class="ap-label">Currency</label>
                            <select class="ap-input" id="feeCurrency">
                                <option value="INR" <?= $currency==='INR'?'selected':'' ?>>INR (&#8377;) — Indian Rupee</option>
                                <option value="USD" <?= $currency==='USD'?'selected':'' ?>>USD ($) — US Dollar</option>
                                <option value="GBP" <?= $currency==='GBP'?'selected':'' ?>>GBP (&pound;) — British Pound</option>
                                <option value="EUR" <?= $currency==='EUR'?'selected':'' ?>>EUR (&euro;) — Euro</option>
                            </select>
                        </div>
                    </div>

                    <!-- Preview -->
                    <div class="ap-preview">
                        <div class="ap-preview-label"><i class="fa fa-eye"></i> Payment Preview</div>
                        <div class="ap-preview-body">
                            <div class="ap-preview-card">
                                <div class="ap-preview-head">
                                    <i class="fa fa-graduation-cap"></i> <span id="previewLabel"><?= $esc($label) ?></span>
                                </div>
                                <div class="ap-preview-amt" id="previewAmt"><?= ($sym_map[$currency] ?? $currency.' ') . number_format($amount, 0) ?></div>
                                <div class="ap-preview-note">This is what parents will see on the admission form</div>
                                <div class="ap-preview-btn"><i class="fa fa-lock"></i> Pay Now</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="ap-form-actions">
                    <button type="button" class="ap-btn ap-btn-primary" id="saveBtn" onclick="saveConfig()">
                        <i class="fa fa-save"></i> Save Configuration
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Public Link + Info Row -->
    <div class="ap-row">
        <!-- Public Form Link -->
        <div class="ap-card ap-half">
            <div class="ap-card-head">
                <i class="fa fa-link"></i> Public Admission Form
            </div>
            <div class="ap-card-body">
                <p class="ap-info-text">Share this link with parents. They can apply and pay directly from their phone or computer.</p>
                <div class="ap-copy-wrap">
                    <input type="text" class="ap-input ap-input-ro" readonly value="<?= $esc($public_form_url) ?>" id="publicLink">
                    <button type="button" class="ap-copy-btn" onclick="copyLink(this)" title="Copy to clipboard">
                        <i class="fa fa-clipboard"></i>
                    </button>
                </div>
                <div class="ap-link-actions">
                    <a href="<?= $esc($public_form_url) ?>" target="_blank" class="ap-btn ap-btn-outline ap-btn-sm">
                        <i class="fa fa-external-link"></i> Open Form
                    </a>
                </div>
            </div>
        </div>

        <!-- How it Works -->
        <div class="ap-card ap-half">
            <div class="ap-card-head">
                <i class="fa fa-info-circle"></i> How It Works
            </div>
            <div class="ap-card-body">
                <div class="ap-steps">
                    <div class="ap-step">
                        <div class="ap-step-num">1</div>
                        <div class="ap-step-text">
                            <strong>Enable & Configure</strong>
                            <span>Set the fee amount and label above</span>
                        </div>
                    </div>
                    <div class="ap-step">
                        <div class="ap-step-num">2</div>
                        <div class="ap-step-text">
                            <strong>Share the Link</strong>
                            <span>Send the public form URL to parents</span>
                        </div>
                    </div>
                    <div class="ap-step">
                        <div class="ap-step-num">3</div>
                        <div class="ap-step-text">
                            <strong>Collect Payments</strong>
                            <span>Parents submit form & pay online</span>
                        </div>
                    </div>
                    <div class="ap-step">
                        <div class="ap-step-num">4</div>
                        <div class="ap-step-text">
                            <strong>Track in SIS</strong>
                            <span>Applications appear in <a href="<?= base_url('sis/admission_leads') ?>">Admission Leads</a></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
</section>
</div>

<!-- Toast -->
<div id="apToastBox"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {

var BASE     = '<?= base_url() ?>';
var csrfName = document.querySelector('meta[name="csrf-name"]').content;
var csrfHash = document.querySelector('meta[name="csrf-token"]').content;

var toggle   = document.getElementById('payEnabled');
var fields   = document.getElementById('feeFields');
var lbl      = document.getElementById('toggleLabel');
var amtInput = document.getElementById('feeAmount');
var curSelect= document.getElementById('feeCurrency');
var lblInput = document.getElementById('feeLabel');
var symbols  = { INR:'\u20B9', USD:'$', GBP:'\u00A3', EUR:'\u20AC' };

/* Toggle */
toggle.addEventListener('change', function() {
    var on = this.checked;
    fields.classList.toggle('ap-disabled', !on);
    lbl.textContent = on ? 'Payment Enabled' : 'Payment Disabled';
    var badge = document.getElementById('statusBadge');
    badge.className = 'ap-badge ' + (on ? 'ap-badge-green' : 'ap-badge-red');
    badge.textContent = on ? 'Active' : 'Inactive';
    /* Update stats */
    var statEl = document.getElementById('statStatus');
    statEl.textContent = on ? 'Active' : 'Inactive';
    var statIc = statEl.closest('.ap-stat').querySelector('.ap-stat-ic');
    statIc.className = 'ap-stat-ic ' + (on ? 'ic-on' : 'ic-off');
    statIc.querySelector('i').className = 'fa ' + (on ? 'fa-check-circle' : 'fa-times-circle');
});

/* Preview */
function updatePreview() {
    var sym = symbols[curSelect.value] || curSelect.value + ' ';
    var amt = parseFloat(amtInput.value) || 0;
    document.getElementById('previewAmt').textContent = sym + amt.toLocaleString('en-IN');
    document.getElementById('previewLabel').textContent = lblInput.value || 'Admission Fee';
    document.getElementById('curSymbol').innerHTML = {'INR':'\u20B9','USD':'$','GBP':'&pound;','EUR':'&euro;'}[curSelect.value] || curSelect.value;
    document.getElementById('statAmount').textContent = amt > 0 ? sym + amt.toLocaleString('en-IN') : '--';
}
amtInput.addEventListener('input', updatePreview);
curSelect.addEventListener('change', updatePreview);
lblInput.addEventListener('input', updatePreview);

/* Save */
window.saveConfig = function() {
    var btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

    var fd = new FormData();
    fd.append(csrfName, csrfHash);
    fd.append('enabled',  toggle.checked ? 'true' : 'false');
    fd.append('amount',   amtInput.value);
    fd.append('currency', curSelect.value);
    fd.append('label',    lblInput.value);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', BASE + 'school_config/save_admission_payment_config');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onload = function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-save"></i> Save Configuration';
        try {
            var res = JSON.parse(xhr.responseText);
            if (res.csrf_hash) csrfHash = res.csrf_hash;
            if (res.csrf_token) csrfHash = res.csrf_token;
            if (res.status === 'success') {
                toast(res.message || 'Configuration saved successfully');
            } else {
                toast(res.message || 'Failed to save', 1);
            }
        } catch(e) {
            toast('Invalid server response', 1);
        }
    };
    xhr.onerror = function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-save"></i> Save Configuration';
        toast('Network error. Please try again.', 1);
    };
    xhr.send(fd);
};

/* Copy */
window.copyLink = function(btn) {
    var link = document.getElementById('publicLink').value;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(link).then(function() {
            btn.innerHTML = '<i class="fa fa-check"></i>';
            toast('Link copied to clipboard');
            setTimeout(function() { btn.innerHTML = '<i class="fa fa-clipboard"></i>'; }, 2000);
        });
    } else {
        var inp = document.getElementById('publicLink');
        inp.select();
        document.execCommand('copy');
        toast('Link copied to clipboard');
    }
};

/* Toast */
function toast(msg, err) {
    var el = document.createElement('div');
    el.className = 'ap-toast' + (err ? ' ap-toast-err' : '');
    el.innerHTML = '<i class="fa ' + (err ? 'fa-times-circle' : 'fa-check-circle') + '"></i> ' + msg;
    document.getElementById('apToastBox').appendChild(el);
    setTimeout(function() { el.classList.add('ap-toast-show'); }, 10);
    setTimeout(function() {
        el.classList.remove('ap-toast-show');
        setTimeout(function() { el.remove(); }, 300);
    }, 3500);
}

});
</script>

<style>
/* ── Admission Payment — Professional Design ───────────────
   Follows fm-* / fh-* module design language               */
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Fraunces:wght@600;700&display=swap');

.ap-wrap {
    --ap-teal: var(--gold, #0f766e);
    --ap-teal2: var(--gold2, #0d6b63);
    --ap-sky: var(--gold-dim, rgba(15,118,110,.10));
    --ap-ring: var(--gold-ring, rgba(15,118,110,.22));
    --ap-glow: var(--gold-glow, rgba(15,118,110,.18));
    --ap-navy: var(--t1, #0f1f3d);
    --ap-text: var(--t1, #1a2940);
    --ap-muted: var(--t3, #64748b);
    --ap-border: var(--border, #d1e8e4);
    --ap-bg: var(--bg, #f0f6f5);
    --ap-card: var(--bg2, #ffffff);
    --ap-bg3: var(--bg3, #f7faf9);
    --ap-shadow: var(--sh, 0 2px 16px rgba(13,115,119,.10));
    --ap-radius: var(--r, 12px);
    --ap-green: #15803d;
    --ap-red: #dc2626;
    font-family: 'Plus Jakarta Sans', var(--font-b, sans-serif);
    padding: 18px 22px 40px;
    background: var(--ap-bg);
    color: var(--ap-text);
    min-height: 100vh;
}

/* ── Topbar ── */
.ap-topbar {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 22px;
}
.ap-page-title {
    font-family: 'Fraunces', serif; font-size: 1.3rem; font-weight: 700;
    color: var(--ap-navy); margin: 0;
    display: flex; align-items: center; gap: 10px;
}
.ap-page-title i { color: var(--ap-teal); font-size: 1.1rem; }
.ap-breadcrumb {
    list-style: none; display: flex; gap: 6px;
    margin: 0; padding: 0; font-size: .78rem; color: var(--ap-muted);
}
.ap-breadcrumb li + li::before { content: '/'; margin-right: 6px; color: var(--ap-border); }
.ap-breadcrumb a { color: var(--ap-teal); text-decoration: none; }
.ap-breadcrumb a:hover { text-decoration: underline; }

/* ── Stats ── */
.ap-stats {
    display: flex; gap: 12px; margin-bottom: 22px; flex-wrap: wrap;
}
.ap-stat {
    flex: 1; min-width: 150px; background: var(--ap-card); border: 1px solid var(--ap-border);
    border-radius: var(--ap-radius); padding: 16px 18px;
    display: flex; align-items: center; gap: 12px; box-shadow: var(--ap-shadow);
}
.ap-stat-ic {
    width: 38px; height: 38px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; flex-shrink: 0;
}
.ap-stat-ic.ic-on  { background: rgba(21,128,61,.1); color: var(--ap-green); }
.ap-stat-ic.ic-off { background: rgba(220,38,38,.1); color: var(--ap-red); }
.ap-stat-ic.ic-amt { background: var(--ap-sky); color: var(--ap-teal); }
.ap-stat-ic.ic-cur { background: rgba(217,119,6,.1); color: #d97706; }
.ap-stat-v {
    font-size: 20px; font-weight: 800; color: var(--ap-navy);
    font-family: 'Plus Jakarta Sans', var(--font-b); line-height: 1.1;
}
.ap-stat-l {
    font-size: 10.5px; color: var(--ap-muted); text-transform: uppercase;
    letter-spacing: .4px; margin-top: 2px;
}

/* ── Cards ── */
.ap-card {
    background: var(--ap-card); border-radius: var(--ap-radius);
    box-shadow: var(--ap-shadow); border: 1px solid var(--ap-border);
    margin-bottom: 18px; overflow: hidden;
}
.ap-card-head {
    padding: 13px 20px; font-size: 14px; font-weight: 700;
    color: var(--ap-navy); border-bottom: 1px solid var(--ap-border);
    display: flex; align-items: center; gap: 8px;
}
.ap-card-head i { color: var(--ap-teal); font-size: 14px; }
.ap-card-body { padding: 20px; }

/* ── Toggle Row ── */
.ap-toggle-row {
    padding: 16px 18px; background: var(--ap-bg3); border-radius: 10px;
    border: 1px solid var(--ap-border); margin-bottom: 22px;
    display: grid; grid-template-columns: auto 1fr; gap: 6px 14px; align-items: center;
}
.ap-toggle-info {
    display: flex; align-items: center; gap: 10px;
}
.ap-toggle-label {
    font-size: 14px; font-weight: 700; color: var(--ap-navy);
}
.ap-toggle-sub {
    grid-column: 2; font-size: 12px; color: var(--ap-muted); line-height: 1.4;
}

/* Switch */
.ap-switch {
    display: inline-flex; align-items: center; cursor: pointer; user-select: none;
}
.ap-switch input { display: none; }
.ap-switch-slider {
    width: 44px; height: 24px; background: #cbd5e0;
    border-radius: 12px; position: relative; transition: background .25s;
}
.ap-switch-slider::after {
    content: ''; position: absolute; width: 20px; height: 20px;
    background: #fff; border-radius: 50%; top: 2px; left: 2px;
    transition: transform .25s; box-shadow: 0 1px 4px rgba(0,0,0,.18);
}
.ap-switch input:checked + .ap-switch-slider { background: var(--ap-green); }
.ap-switch input:checked + .ap-switch-slider::after { transform: translateX(20px); }

/* Badges */
.ap-badge {
    display: inline-block; padding: 3px 10px; border-radius: 6px;
    font-size: 11px; font-weight: 700; letter-spacing: .02em;
}
.ap-badge-green { background: rgba(21,128,61,.12); color: var(--ap-green); }
.ap-badge-red   { background: rgba(220,38,38,.12); color: var(--ap-red); }

/* ── Form Grid ── */
.ap-form-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
}
.ap-form-group.ap-full { grid-column: 1 / -1; }
.ap-label {
    display: block; font-size: .73rem; font-weight: 600;
    color: var(--ap-navy); margin-bottom: 5px;
    text-transform: uppercase; letter-spacing: .3px;
}
.ap-input {
    width: 100%; padding: 9px 12px;
    border: 1.5px solid var(--ap-border); border-radius: 8px;
    font-size: .82rem; font-family: inherit;
    color: var(--ap-text); background: var(--ap-bg3);
    transition: border-color .2s; box-sizing: border-box;
    outline: none;
}
.ap-input:focus {
    border-color: var(--ap-teal);
    box-shadow: 0 0 0 3px var(--ap-ring);
}
.ap-input[readonly], .ap-input-ro {
    background: var(--ap-bg3); color: var(--ap-muted); cursor: default; font-size: 12px;
}
.ap-input select { cursor: pointer; }

/* Input with prefix */
.ap-input-icon { position: relative; }
.ap-input-prefix {
    position: absolute; left: 1px; top: 1px; bottom: 1px;
    width: 38px; display: flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 700; color: var(--ap-teal);
    background: var(--ap-sky); border-radius: 7px 0 0 7px;
    border-right: 1px solid var(--ap-border);
}
.ap-input-has-prefix { padding-left: 48px; }

/* Disabled state */
.ap-disabled { opacity: .45; pointer-events: none; }

/* ── Preview ── */
.ap-preview {
    margin-top: 20px; border: 1px dashed var(--ap-border);
    border-radius: 10px; overflow: hidden;
}
.ap-preview-label {
    padding: 8px 16px; font-size: 11.5px; font-weight: 600;
    color: var(--ap-muted); text-transform: uppercase; letter-spacing: .3px;
    background: var(--ap-bg3); border-bottom: 1px dashed var(--ap-border);
    display: flex; align-items: center; gap: 6px;
}
.ap-preview-label i { font-size: 12px; }
.ap-preview-body {
    padding: 20px; display: flex; justify-content: center;
    background: var(--ap-bg);
}
.ap-preview-card {
    width: 260px; background: var(--ap-card);
    border: 1px solid var(--ap-border); border-radius: 12px;
    padding: 20px; text-align: center;
    box-shadow: 0 4px 20px rgba(0,0,0,.06);
}
.ap-preview-head {
    font-size: 13px; font-weight: 600; color: var(--ap-navy);
    margin-bottom: 8px; display: flex; align-items: center;
    justify-content: center; gap: 6px;
}
.ap-preview-head i { color: var(--ap-teal); }
.ap-preview-amt {
    font-size: 2rem; font-weight: 800; color: var(--ap-teal);
    margin: 8px 0 6px; font-family: 'Plus Jakarta Sans', sans-serif;
}
.ap-preview-note {
    font-size: 10.5px; color: var(--ap-muted); margin-bottom: 14px;
}
.ap-preview-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 28px; border-radius: 8px;
    background: var(--ap-teal); color: #fff;
    font-size: 13px; font-weight: 600; cursor: default;
    box-shadow: 0 2px 8px var(--ap-glow);
}

/* ── Form Actions ── */
.ap-form-actions {
    margin-top: 22px; padding-top: 18px;
    border-top: 1px solid var(--ap-border);
    display: flex; gap: 10px;
}

/* ── Buttons ── */
.ap-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 20px; border-radius: 8px; font-size: .8rem;
    font-weight: 600; font-family: inherit; border: none;
    cursor: pointer; transition: all .2s; text-decoration: none;
}
.ap-btn-primary { background: var(--ap-teal); color: #fff; }
.ap-btn-primary:hover { background: var(--ap-teal2); }
.ap-btn-primary:disabled { opacity: .6; cursor: not-allowed; }
.ap-btn-outline {
    background: transparent; color: var(--ap-teal);
    border: 1.5px solid var(--ap-teal);
}
.ap-btn-outline:hover { background: var(--ap-sky); }
.ap-btn-sm { padding: 6px 14px; font-size: 12px; }

/* ── Two Column Row ── */
.ap-row {
    display: grid; grid-template-columns: 1fr 1fr; gap: 18px;
}
.ap-half { margin-bottom: 0; }

.ap-info-text {
    font-size: 12.5px; color: var(--ap-muted); margin: 0 0 14px; line-height: 1.5;
}

/* Copy URL */
.ap-copy-wrap { display: flex; gap: 0; }
.ap-copy-wrap .ap-input {
    border-radius: 8px 0 0 8px; border-right: none;
    font-family: 'SFMono-Regular', Consolas, monospace;
}
.ap-copy-btn {
    padding: 9px 14px; border: 1.5px solid var(--ap-border);
    border-left: none; border-radius: 0 8px 8px 0;
    background: var(--ap-sky); color: var(--ap-teal);
    cursor: pointer; font-size: 14px; transition: all .2s;
}
.ap-copy-btn:hover { background: var(--ap-teal); color: #fff; }

.ap-link-actions { margin-top: 12px; }

/* ── Steps ── */
.ap-steps { display: flex; flex-direction: column; gap: 0; }
.ap-step {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 12px 0; position: relative;
}
.ap-step + .ap-step { border-top: 1px solid var(--ap-border); }
.ap-step-num {
    width: 28px; height: 28px; border-radius: 50%;
    background: var(--ap-sky); color: var(--ap-teal);
    font-size: 12px; font-weight: 800;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.ap-step-text strong {
    display: block; font-size: 13px; font-weight: 700;
    color: var(--ap-navy); margin-bottom: 2px;
}
.ap-step-text span { font-size: 12px; color: var(--ap-muted); line-height: 1.4; }
.ap-step-text a { color: var(--ap-teal); font-weight: 600; text-decoration: none; }
.ap-step-text a:hover { text-decoration: underline; }

/* ── Toast ── */
#apToastBox {
    position: fixed; top: 20px; right: 20px; z-index: 99999;
    display: flex; flex-direction: column; gap: 8px; pointer-events: none;
}
.ap-toast {
    padding: 10px 18px; border-radius: 8px; color: #fff;
    font-size: 12.5px; font-weight: 600; font-family: 'Plus Jakarta Sans', sans-serif;
    box-shadow: 0 4px 20px rgba(0,0,0,.15); pointer-events: auto;
    display: flex; align-items: center; gap: 8px;
    opacity: 0; transform: translateX(30px); transition: all .3s ease;
    background: var(--ap-green);
}
.ap-toast-err { background: var(--ap-red); }
.ap-toast-show { opacity: 1; transform: translateX(0); }

/* ── Responsive ── */
@media (max-width: 1024px) {
    .ap-row { grid-template-columns: 1fr; }
}
@media (max-width: 767px) {
    .ap-wrap { padding: 16px 12px 50px; }
    .ap-topbar { flex-direction: column; align-items: flex-start; gap: 6px; }
    .ap-page-title { font-size: 1.1rem; }
    .ap-stats { gap: 8px; }
    .ap-stat { min-width: 120px; padding: 12px 14px; }
    .ap-form-grid { grid-template-columns: 1fr; }
    .ap-form-group.ap-full { grid-column: 1; }
    .ap-row { grid-template-columns: 1fr; }
    .ap-toggle-row { grid-template-columns: 1fr; }
    .ap-toggle-sub { grid-column: 1; }
}
@media (max-width: 479px) {
    .ap-stat-v { font-size: 16px; }
    .ap-preview-amt { font-size: 1.6rem; }
    .ap-form-actions { flex-direction: column; }
    .ap-form-actions .ap-btn { width: 100%; justify-content: center; }
}
</style>
