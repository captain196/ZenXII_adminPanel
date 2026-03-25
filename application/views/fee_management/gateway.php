<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
    <div class="fm-wrap">
        <!-- Top bar -->
        <div class="fm-topbar">
            <h1 class="fm-page-title">
                <i class="fa fa-plug"></i> Payment Gateway
            </h1>
            <ul class="fm-breadcrumb">
                <li><a href="<?= base_url() ?>">Dashboard</a></li>
                <li>Fees &amp; Finance</li>
                <li>Payment Gateway</li>
            </ul>
        </div>

        <!-- Gateway Provider Selection -->
        <div class="fm-card">
            <div class="fm-card-head">
                <i class="fa fa-globe"></i> Select Gateway Provider
            </div>
            <div class="fm-card-body">
                <div class="fm-gw-providers" id="fmProviders">
                    <div class="fm-gw-provider" data-provider="razorpay" onclick="selectProvider('razorpay')">
                        <div class="fm-gw-provider-icon"><i class="fa fa-credit-card"></i></div>
                        <div class="fm-gw-provider-name">Razorpay</div>
                        <div class="fm-gw-provider-desc">India's leading payment gateway for online payments</div>
                        <div class="fm-gw-check"><i class="fa fa-check"></i></div>
                    </div>
                    <div class="fm-gw-provider" data-provider="stripe" onclick="selectProvider('stripe')">
                        <div class="fm-gw-provider-icon"><i class="fa fa-cc-stripe"></i></div>
                        <div class="fm-gw-provider-name">Stripe</div>
                        <div class="fm-gw-provider-desc">Global payments platform for internet businesses</div>
                        <div class="fm-gw-check"><i class="fa fa-check"></i></div>
                    </div>
                    <div class="fm-gw-provider" data-provider="paytm" onclick="selectProvider('paytm')">
                        <div class="fm-gw-provider-icon"><i class="fa fa-mobile"></i></div>
                        <div class="fm-gw-provider-name">Paytm</div>
                        <div class="fm-gw-provider-desc">Trusted payment solution with UPI &amp; wallet support</div>
                        <div class="fm-gw-check"><i class="fa fa-check"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Configuration Form -->
        <div class="fm-card" id="fmConfigCard">
            <div class="fm-card-head">
                <i class="fa fa-cog"></i> Configuration
            </div>
            <div class="fm-card-body">
                <form id="fmGatewayForm" onsubmit="saveConfig(event)">
                    <input type="hidden" name="<?= $this->security->get_csrf_token_name() ?>" value="<?= $this->security->get_csrf_hash() ?>" id="fmCsrfToken">
                    <input type="hidden" name="provider" id="fmProvider" value="<?= !empty($config['provider']) ? htmlspecialchars($config['provider']) : '' ?>">

                    <div class="fm-form-grid">
                        <!-- Provider (readonly) -->
                        <div class="fm-form-group">
                            <label class="fm-label">Provider</label>
                            <input type="text" class="fm-input" id="fmProviderDisplay" readonly placeholder="Select a provider above" value="<?= !empty($config['provider']) ? htmlspecialchars(ucfirst($config['provider'])) : '' ?>">
                        </div>

                        <!-- Mode Toggle -->
                        <div class="fm-form-group">
                            <label class="fm-label">Mode</label>
                            <div class="fm-mode-toggle">
                                <label class="fm-mode-opt <?= (empty($config['mode']) || $config['mode'] === 'test') ? 'fm-mode-active fm-mode-test' : '' ?>">
                                    <input type="radio" name="mode" value="test" <?= (empty($config['mode']) || $config['mode'] === 'test') ? 'checked' : '' ?>> Test
                                </label>
                                <label class="fm-mode-opt <?= (!empty($config['mode']) && $config['mode'] === 'live') ? 'fm-mode-active fm-mode-live' : '' ?>">
                                    <input type="radio" name="mode" value="live" <?= (!empty($config['mode']) && $config['mode'] === 'live') ? 'checked' : '' ?>> Live
                                </label>
                            </div>
                        </div>

                        <!-- API Key -->
                        <div class="fm-form-group fm-full">
                            <label class="fm-label">API Key <span class="fm-req">*</span></label>
                            <input type="text" class="fm-input" name="api_key" id="fmApiKey" required placeholder="Enter API key" value="<?= !empty($config['api_key']) ? htmlspecialchars($config['api_key']) : '' ?>">
                        </div>

                        <!-- API Secret -->
                        <div class="fm-form-group fm-full">
                            <label class="fm-label">API Secret <span class="fm-req">*</span></label>
                            <div class="fm-secret-wrap">
                                <input type="password" class="fm-input" name="api_secret" id="fmApiSecret" required placeholder="Enter API secret" value="<?= !empty($config['api_secret']) ? htmlspecialchars($config['api_secret']) : '' ?>">
                                <button type="button" class="fm-secret-toggle" onclick="toggleSecret()" title="Show/Hide"><i class="fa fa-eye" id="fmEyeIcon"></i></button>
                            </div>
                        </div>

                        <!-- Webhook Secret -->
                        <div class="fm-form-group fm-full">
                            <label class="fm-label">Webhook Secret <span class="fm-opt">(optional)</span></label>
                            <input type="text" class="fm-input" name="webhook_secret" id="fmWebhookSecret" placeholder="Enter webhook secret" value="<?= !empty($config['webhook_secret']) ? htmlspecialchars($config['webhook_secret']) : '' ?>">
                        </div>

                        <!-- Active toggle -->
                        <div class="fm-form-group">
                            <label class="fm-label">Active</label>
                            <label class="fm-switch">
                                <input type="checkbox" name="active" id="fmActive" value="1" <?= (!empty($config['active']) && $config['active']) ? 'checked' : '' ?>>
                                <span class="fm-switch-slider"></span>
                                <span class="fm-switch-label" id="fmActiveLabel"><?= (!empty($config['active']) && $config['active']) ? 'Enabled' : 'Disabled' ?></span>
                            </label>
                        </div>
                    </div>

                    <div class="fm-form-actions">
                        <button type="submit" class="fm-btn fm-btn-primary" id="fmSaveBtn">
                            <i class="fa fa-save"></i> Save Configuration
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Status Panel + Integration Info row -->
        <div class="fm-gw-row">
            <!-- Status Panel -->
            <div class="fm-card fm-gw-half">
                <div class="fm-card-head">
                    <i class="fa fa-info-circle"></i> Current Status
                </div>
                <div class="fm-card-body">
                    <table class="fm-status-table">
                        <tr>
                            <td class="fm-status-lbl">Provider</td>
                            <td id="fmStatusProvider"><?= !empty($config['provider']) ? htmlspecialchars(ucfirst($config['provider'])) : '<span class="fm-muted">Not configured</span>' ?></td>
                        </tr>
                        <tr>
                            <td class="fm-status-lbl">Mode</td>
                            <td id="fmStatusMode">
                                <?php if (!empty($config['mode'])): ?>
                                    <span class="fm-badge <?= $config['mode'] === 'live' ? 'fm-badge-green' : 'fm-badge-gold' ?>"><?= ucfirst($config['mode']) ?></span>
                                <?php else: ?>
                                    <span class="fm-muted">--</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="fm-status-lbl">Status</td>
                            <td id="fmStatusActive">
                                <?php if (!empty($config['active'])): ?>
                                    <span class="fm-badge fm-badge-green">Active</span>
                                <?php else: ?>
                                    <span class="fm-badge fm-badge-red">Inactive</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="fm-status-lbl">Last Updated</td>
                            <td id="fmStatusUpdated"><?= !empty($config['updated_at']) ? htmlspecialchars($config['updated_at']) : '<span class="fm-muted">Never</span>' ?></td>
                        </tr>
                    </table>
                    <div class="fm-status-action">
                        <button type="button" class="fm-btn fm-btn-outline" onclick="testConnection()">
                            <i class="fa fa-refresh"></i> Test Connection
                        </button>
                    </div>
                </div>
            </div>

            <!-- Integration Info -->
            <div class="fm-card fm-gw-half">
                <div class="fm-card-head">
                    <i class="fa fa-link"></i> Integration Info
                </div>
                <div class="fm-card-body">
                    <div class="fm-info-row">
                        <label class="fm-label">Webhook URL</label>
                        <div class="fm-copy-wrap">
                            <input type="text" class="fm-input fm-input-ro" readonly value="<?= base_url('fee_management/webhook') ?>" id="fmWebhookUrl">
                            <button type="button" class="fm-copy-btn" onclick="copyToClipboard(document.getElementById('fmWebhookUrl').value)" title="Copy">
                                <i class="fa fa-clipboard"></i>
                            </button>
                        </div>
                    </div>
                    <div class="fm-info-row">
                        <label class="fm-label">Callback URL</label>
                        <div class="fm-copy-wrap">
                            <input type="text" class="fm-input fm-input-ro" readonly value="<?= base_url('fee_management/payment_callback') ?>" id="fmCallbackUrl">
                            <button type="button" class="fm-copy-btn" onclick="copyToClipboard(document.getElementById('fmCallbackUrl').value)" title="Copy">
                                <i class="fa fa-clipboard"></i>
                            </button>
                        </div>
                    </div>
                    <div class="fm-info-note">
                        <i class="fa fa-exclamation-triangle"></i>
                        Configure these URLs in your payment provider's dashboard. Ensure the provider's SDK library is included in your project for client-side integration.
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /.fm-wrap -->
</div><!-- /.content-wrapper -->

<!-- Toast container -->
<div id="fmToastBox"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var BASE = '<?= base_url() ?>';
    var csrfName = '<?= $this->security->get_csrf_token_name() ?>';
    var csrfHash = '<?= $this->security->get_csrf_hash() ?>';
    var currentProvider = '<?= !empty($config['provider']) ? $config['provider'] : '' ?>';

    // Pre-select provider if config exists
    if (currentProvider) {
        highlightProvider(currentProvider);
    }

    // Mode toggle
    document.querySelectorAll('.fm-mode-opt input[type="radio"]').forEach(function(r) {
        r.addEventListener('change', function() {
            document.querySelectorAll('.fm-mode-opt').forEach(function(el) {
                el.classList.remove('fm-mode-active', 'fm-mode-test', 'fm-mode-live');
            });
            var lbl = this.closest('.fm-mode-opt');
            lbl.classList.add('fm-mode-active');
            lbl.classList.add(this.value === 'live' ? 'fm-mode-live' : 'fm-mode-test');
        });
    });

    // Active toggle label
    var activeChk = document.getElementById('fmActive');
    if (activeChk) {
        activeChk.addEventListener('change', function() {
            document.getElementById('fmActiveLabel').textContent = this.checked ? 'Enabled' : 'Disabled';
        });
    }

    // Expose functions globally
    window.selectProvider = function(p) {
        document.getElementById('fmProvider').value = p;
        document.getElementById('fmProviderDisplay').value = p.charAt(0).toUpperCase() + p.slice(1);
        highlightProvider(p);
    };

    window.saveConfig = function(e) {
        e.preventDefault();
        var form = document.getElementById('fmGatewayForm');
        var provider = document.getElementById('fmProvider').value;
        if (!provider) {
            showToast('Please select a gateway provider', 'error');
            return;
        }

        var btn = document.getElementById('fmSaveBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

        var fd = new FormData(form);
        fd.set(csrfName, csrfHash);
        fd.set('active', document.getElementById('fmActive').checked ? '1' : '0');

        var xhr = new XMLHttpRequest();
        xhr.open('POST', BASE + 'fee_management/save_gateway_config');
        xhr.onload = function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-save"></i> Save Configuration';
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.csrf_hash) csrfHash = res.csrf_hash;
                var csrfInput = document.getElementById('fmCsrfToken');
                if (csrfInput && res.csrf_hash) csrfInput.value = res.csrf_hash;

                if (res.status === 'success' || res.success) {
                    showToast(res.message || 'Configuration saved', 'success');
                    updateStatusPanel(fd);
                } else {
                    showToast(res.message || 'Failed to save', 'error');
                }
            } catch (ex) {
                showToast('Invalid server response', 'error');
            }
        };
        xhr.onerror = function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-save"></i> Save Configuration';
            showToast('Network error', 'error');
        };
        xhr.send(fd);
    };

    window.toggleSecret = function() {
        var inp = document.getElementById('fmApiSecret');
        var ico = document.getElementById('fmEyeIcon');
        if (inp.type === 'password') {
            inp.type = 'text';
            ico.className = 'fa fa-eye-slash';
        } else {
            inp.type = 'password';
            ico.className = 'fa fa-eye';
        }
    };

    window.copyToClipboard = function(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                showToast('Copied to clipboard', 'success');
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            showToast('Copied to clipboard', 'success');
        }
    };

    window.testConnection = function() {
        showToast('Connection test not implemented yet', 'info');
    };

    window.showToast = function(msg, type) {
        type = type || 'info';
        var box = document.getElementById('fmToastBox');
        var t = document.createElement('div');
        t.className = 'fm-toast fm-toast-' + type;
        var icons = { success: 'fa-check-circle', error: 'fa-times-circle', info: 'fa-info-circle' };
        t.innerHTML = '<i class="fa ' + (icons[type] || 'fa-info-circle') + '"></i> ' + msg;
        box.appendChild(t);
        setTimeout(function() { t.classList.add('fm-toast-show'); }, 10);
        setTimeout(function() {
            t.classList.remove('fm-toast-show');
            setTimeout(function() { t.remove(); }, 300);
        }, 3000);
    };

    function highlightProvider(p) {
        document.querySelectorAll('.fm-gw-provider').forEach(function(el) {
            el.classList.toggle('fm-gw-provider-active', el.getAttribute('data-provider') === p);
        });
    }

    function updateStatusPanel(fd) {
        var provider = fd.get('provider');
        var mode = fd.get('mode');
        var active = fd.get('active') === '1';
        var now = new Date();
        var ts = now.toLocaleDateString('en-IN') + ' ' + now.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });

        document.getElementById('fmStatusProvider').textContent = provider.charAt(0).toUpperCase() + provider.slice(1);

        var modeEl = document.getElementById('fmStatusMode');
        modeEl.innerHTML = '<span class="fm-badge ' + (mode === 'live' ? 'fm-badge-green' : 'fm-badge-gold') + '">' + mode.charAt(0).toUpperCase() + mode.slice(1) + '</span>';

        var activeEl = document.getElementById('fmStatusActive');
        activeEl.innerHTML = active
            ? '<span class="fm-badge fm-badge-green">Active</span>'
            : '<span class="fm-badge fm-badge-red">Inactive</span>';

        document.getElementById('fmStatusUpdated').textContent = ts;
    }
});
</script>

<style>
/* ── Gateway: Fonts ── */
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Fraunces:wght@600;700&display=swap');

/* ── Gateway: Variables ── */
.fm-wrap {
    --fm-navy: var(--t1, #0f1f3d);
    --fm-teal: var(--gold, #0f766e);
    --fm-teal2: var(--gold2, #0d6b63);
    --fm-sky: var(--gold-dim, rgba(15,118,110,.10));
    --fm-gold: #d97706;
    --fm-red: #E05C6F;
    --fm-green: #15803d;
    --fm-text: var(--t1, #1a2940);
    --fm-muted: var(--t3, #64748b);
    --fm-border: var(--border, #d1e8e4);
    --fm-bg: var(--bg, #f0f6f5);
    --fm-card: var(--bg2, #ffffff);
    --fm-shadow: var(--sh, 0 2px 16px rgba(13,115,119,.10));
    --fm-radius: var(--r, 12px);
    font-family: 'Plus Jakarta Sans', var(--font-b, sans-serif);
    padding: 18px 22px 40px;
    background: var(--fm-bg);
    color: var(--fm-text);
    min-height: 100vh;
}

/* ── Topbar ── */
.fm-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 22px;
}
.fm-page-title {
    font-family: 'Fraunces', serif;
    font-size: 1.3rem;
    font-weight: 700;
    color: var(--fm-navy);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.fm-page-title i { color: var(--fm-teal); font-size: 1.1rem; }
.fm-breadcrumb {
    list-style: none;
    display: flex;
    gap: 6px;
    margin: 0;
    padding: 0;
    font-size: .78rem;
    color: var(--fm-muted);
}
.fm-breadcrumb li + li::before { content: '/'; margin-right: 6px; color: var(--fm-border); }
.fm-breadcrumb a { color: var(--fm-teal); text-decoration: none; }
.fm-breadcrumb a:hover { text-decoration: underline; }

/* ── Cards ── */
.fm-card {
    background: var(--fm-card);
    border-radius: var(--fm-radius);
    box-shadow: var(--fm-shadow);
    border: 1px solid var(--fm-border);
    margin-bottom: 18px;
    overflow: hidden;
}
.fm-card-head {
    padding: 13px 20px;
    font-family: inherit;
    font-size: 14px;
    font-weight: 700;
    color: var(--fm-navy);
    border-bottom: 1px solid var(--fm-border);
    background: transparent;
    display: flex;
    align-items: center;
    gap: 8px;
}
.fm-card-head i { color: var(--fm-teal); font-size: 14px; }
.fm-card-body { padding: 20px; }

/* ── Provider Cards ── */
.fm-gw-providers {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
}
.fm-gw-provider {
    position: relative;
    padding: 20px 18px;
    border: 2px solid var(--fm-border);
    border-radius: 12px;
    cursor: pointer;
    transition: all .2s ease;
    text-align: center;
    background: #fff;
}
.fm-gw-provider:hover {
    border-color: var(--fm-teal);
    box-shadow: 0 4px 20px rgba(13,115,119,.12);
    transform: translateY(-2px);
}
.fm-gw-provider-active {
    border-color: var(--fm-teal);
    background: var(--fm-sky);
    box-shadow: 0 4px 20px rgba(13,115,119,.15);
}
.fm-gw-provider-active .fm-gw-check {
    opacity: 1;
    transform: scale(1);
}
.fm-gw-provider-icon {
    width: 48px;
    height: 48px;
    margin: 0 auto 10px;
    border-radius: 50%;
    background: var(--fm-sky);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: var(--fm-teal);
    transition: background .2s;
}
.fm-gw-provider-active .fm-gw-provider-icon {
    background: var(--fm-teal);
    color: #fff;
}
.fm-gw-provider-name {
    font-weight: 700;
    font-size: 15px;
    color: var(--fm-navy);
    margin-bottom: 4px;
}
.fm-gw-provider-desc {
    font-size: .72rem;
    color: var(--fm-muted);
    line-height: 1.4;
}
.fm-gw-check {
    position: absolute;
    top: 10px;
    right: 10px;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: var(--fm-teal);
    color: #fff;
    font-size: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transform: scale(.6);
    transition: all .2s ease;
}

/* ── Form ── */
.fm-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
.fm-form-group.fm-full { grid-column: 1 / -1; }
.fm-label {
    display: block;
    font-size: .73rem;
    font-weight: 600;
    color: var(--fm-navy);
    margin-bottom: 5px;
    text-transform: uppercase;
    letter-spacing: .3px;
}
.fm-req { color: var(--fm-red); }
.fm-opt { color: var(--fm-muted); font-weight: 400; font-size: 12.5px; }
.fm-input {
    width: 100%;
    padding: 9px 12px;
    border: 1.5px solid var(--fm-border);
    border-radius: 8px;
    font-size: .82rem;
    font-family: inherit;
    color: var(--fm-text);
    background: #fff;
    transition: border-color .2s;
    box-sizing: border-box;
}
.fm-input:focus {
    outline: none;
    border-color: var(--fm-teal);
    box-shadow: 0 0 0 3px rgba(13,115,119,.1);
}
.fm-input[readonly] { background: var(--bg3, #f7faf9); color: var(--fm-muted); cursor: default; }
.fm-input-ro { background: var(--bg3, #f7faf9); color: var(--fm-muted); font-size: 12px; }

/* ── Secret toggle ── */
.fm-secret-wrap {
    position: relative;
}
.fm-secret-wrap .fm-input { padding-right: 40px; }
.fm-secret-toggle {
    position: absolute;
    right: 3px;
    top: 50%;
    transform: translateY(-50%);
    border: none;
    background: transparent;
    color: var(--fm-muted);
    cursor: pointer;
    padding: 6px 8px;
    font-size: 14px;
    transition: color .2s;
}
.fm-secret-toggle:hover { color: var(--fm-teal); }

/* ── Mode toggle ── */
.fm-mode-toggle {
    display: inline-flex;
    border: 1.5px solid var(--fm-border);
    border-radius: 8px;
    overflow: hidden;
    background: #f7faf9;
}
.fm-mode-opt {
    padding: 8px 20px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all .2s;
    color: var(--fm-muted);
    margin: 0;
    user-select: none;
}
.fm-mode-opt input { display: none; }
.fm-mode-opt:hover { background: var(--fm-sky); }
.fm-mode-active.fm-mode-test {
    background: #fef3c7;
    color: var(--fm-gold);
}
.fm-mode-active.fm-mode-live {
    background: #d1fae5;
    color: var(--fm-green);
}

/* ── Switch ── */
.fm-switch {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    user-select: none;
}
.fm-switch input { display: none; }
.fm-switch-slider {
    width: 40px;
    height: 22px;
    background: #cbd5e0;
    border-radius: 11px;
    position: relative;
    transition: background .2s;
}
.fm-switch-slider::after {
    content: '';
    position: absolute;
    width: 18px;
    height: 18px;
    background: #fff;
    border-radius: 50%;
    top: 2px;
    left: 2px;
    transition: transform .2s;
    box-shadow: 0 1px 3px rgba(0,0,0,.15);
}
.fm-switch input:checked + .fm-switch-slider {
    background: var(--fm-green);
}
.fm-switch input:checked + .fm-switch-slider::after {
    transform: translateX(18px);
}
.fm-switch-label {
    font-size: 13px;
    font-weight: 500;
    color: var(--fm-text);
}

/* ── Form actions ── */
.fm-form-actions {
    margin-top: 20px;
    display: flex;
    gap: 10px;
}
.fm-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 20px;
    border-radius: 8px;
    font-size: .8rem;
    font-weight: 600;
    font-family: inherit;
    border: none;
    cursor: pointer;
    transition: all .2s;
}
.fm-btn-primary {
    background: var(--fm-teal);
    color: #fff;
}
.fm-btn-primary:hover { background: var(--fm-teal2); }
.fm-btn-primary:disabled { opacity: .6; cursor: not-allowed; }
.fm-btn-outline {
    background: transparent;
    color: var(--fm-teal);
    border: 1.5px solid var(--fm-teal);
}
.fm-btn-outline:hover { background: var(--fm-sky); }

/* ── Status/Info row ── */
.fm-gw-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
}
.fm-gw-half { margin-bottom: 0; }
.fm-status-table {
    width: 100%;
    border-collapse: collapse;
}
.fm-status-table tr + tr td { border-top: 1px solid var(--fm-border); }
.fm-status-table td {
    padding: 10px 0;
    font-size: 13px;
    vertical-align: middle;
}
.fm-status-lbl {
    font-weight: 600;
    color: var(--fm-navy);
    width: 120px;
}
.fm-muted { color: var(--fm-muted); }
.fm-status-action {
    margin-top: 16px;
    padding-top: 14px;
    border-top: 1px solid var(--fm-border);
}

/* ── Badges ── */
.fm-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 6px;
    font-size: 11.5px;
    font-weight: 600;
    letter-spacing: .02em;
}
.fm-badge-green { background: #d1fae5; color: #166534; }
.fm-badge-gold { background: #fef3c7; color: #92400e; }
.fm-badge-red { background: #fee2e2; color: #991b1b; }

/* ── Integration info ── */
.fm-info-row { margin-bottom: 14px; }
.fm-info-row:last-of-type { margin-bottom: 0; }
.fm-copy-wrap {
    display: flex;
    gap: 0;
}
.fm-copy-wrap .fm-input {
    border-radius: 8px 0 0 8px;
    border-right: none;
}
.fm-copy-btn {
    padding: 9px 14px;
    border: 1.5px solid var(--fm-border);
    border-left: none;
    border-radius: 0 8px 8px 0;
    background: var(--fm-sky);
    color: var(--fm-teal);
    cursor: pointer;
    font-size: 14px;
    transition: all .2s;
}
.fm-copy-btn:hover { background: var(--fm-teal); color: #fff; }
.fm-info-note {
    margin-top: 18px;
    padding: 12px 14px;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 8px;
    font-size: 12px;
    color: #92400e;
    line-height: 1.5;
}
.fm-info-note i { margin-right: 4px; }

/* ── Toast ── */
#fmToastBox {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.fm-toast {
    padding: 10px 18px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    font-family: 'Plus Jakarta Sans', sans-serif;
    color: #fff;
    box-shadow: 0 4px 20px rgba(0,0,0,.15);
    opacity: 0;
    transform: translateX(30px);
    transition: all .3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}
.fm-toast-show { opacity: 1; transform: translateX(0); }
.fm-toast-success { background: var(--fm-green); }
.fm-toast-error { background: var(--fm-red); }
.fm-toast-info { background: var(--fm-teal); }

/* ── Responsive ── */
@media (max-width: 1024px) {
    .fm-gw-row { grid-template-columns: 1fr; }
}
@media (max-width: 767px) {
    .fm-wrap { padding: 16px 12px 50px; }
    .fm-page-title { font-size: 20px; }
    .fm-topbar { flex-direction: column; align-items: flex-start; gap: 6px; }
    .fm-gw-providers { grid-template-columns: 1fr; }
    .fm-form-grid { grid-template-columns: 1fr; }
    .fm-form-group.fm-full { grid-column: 1; }
    .fm-gw-row { grid-template-columns: 1fr; }
}
@media (max-width: 479px) {
    .fm-mode-opt { padding: 8px 14px; font-size: 12px; }
    .fm-form-actions { flex-direction: column; }
    .fm-form-actions .fm-btn { width: 100%; justify-content: center; }
}
</style>
