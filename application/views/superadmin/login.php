<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= $this->security->get_csrf_hash() ?>">
<meta name="csrf-name"  content="<?= $this->security->get_csrf_token_name() ?>">
<title>Super Admin — GraderIQ</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════════════
   SUPER ADMIN LOGIN  ·  Dark Navy + Gold Luxury
   ═══════════════════════════════════════════════════════ */
:root {
    --navy:      #0b0f1a;
    --navy2:     #0f1525;
    --navy3:     #141c30;
    --navy4:     #1a2540;
    --navy5:     #1f2d4d;
    --gold:      #c9a84c;
    --gold2:     #e6c76a;
    --gold3:     #a8893a;
    --gold-dim:  rgba(201,168,76,.10);
    --gold-ring: rgba(201,168,76,.22);
    --gold-glow: rgba(201,168,76,.15);
    --red:       #e05555;
    --red-dim:   rgba(224,85,85,.10);
    --t1:        #eef2ff;
    --t2:        #8892aa;
    --t3:        #4e5a72;
    --t4:        #2a3350;
    --border:    rgba(201,168,76,.12);
    --brd2:      rgba(201,168,76,.24);
    --sh:        0 32px 80px rgba(0,0,0,.8), 0 0 0 1px rgba(201,168,76,.06);
    --r:         8px;
    --ease:      .18s cubic-bezier(.4,0,.2,1);
    --serif:     'Playfair Display', Georgia, serif;
    --sans:      'DM Sans', system-ui, sans-serif;
    --mono:      'DM Mono', ui-monospace, monospace;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html, body {
    height: 100%;
    width: 100%;
    font-family: var(--sans);
    background: var(--navy);
    color: var(--t1);
    overflow: hidden;
}

/* ── Two-panel layout ─────────────────────────────────── */
.sa-layout {
    display: flex;
    height: 100vh;
    width: 100vw;
    max-width: 100%;
    overflow: hidden;
}

/* ── Left decorative panel ────────────────────────────── */
.sa-panel {
    flex: 0 0 42%;
    background: var(--navy2);
    border-right: 1px solid var(--border);
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 48px 44px;
}

.sa-panel::before {
    content: '';
    position: absolute;
    top: -80px; right: -80px;
    width: 340px; height: 340px;
    border: 1px solid var(--gold-ring);
    border-radius: 50%;
    pointer-events: none;
}
.sa-panel::after {
    content: '';
    position: absolute;
    bottom: -120px; left: -60px;
    width: 420px; height: 420px;
    border: 1px solid rgba(201,168,76,.08);
    border-radius: 50%;
    pointer-events: none;
}

.sa-panel-grid {
    position: absolute;
    inset: 0;
    background-image: radial-gradient(rgba(201,168,76,.07) 1px, transparent 1px);
    background-size: 28px 28px;
    pointer-events: none;
}

.sa-panel-top { position: relative; z-index: 1; }

.sa-wordmark {
    font-family: var(--serif);
    font-size: 28px;
    font-weight: 800;
    color: var(--t1);
    letter-spacing: -.5px;
    line-height: 1;
    margin-bottom: 6px;
}
.sa-wordmark span { color: var(--gold); }

.sa-wordmark-sub {
    font-family: var(--mono);
    font-size: 9.5px;
    color: var(--t3);
    letter-spacing: 2.5px;
    text-transform: uppercase;
}

.sa-panel-mid {
    position: relative;
    z-index: 1;
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.sa-big-letter {
    font-family: var(--serif);
    font-size: clamp(100px, 14vw, 160px);
    font-weight: 800;
    color: transparent;
    -webkit-text-stroke: 1px rgba(201,168,76,.18);
    line-height: .9;
    user-select: none;
    margin-bottom: 24px;
}

.sa-panel-tagline {
    font-family: var(--serif);
    font-size: 22px;
    font-weight: 600;
    color: var(--t2);
    line-height: 1.4;
    max-width: 240px;
}
.sa-panel-tagline em {
    font-style: normal;
    color: var(--gold);
}

.sa-panel-bottom { position: relative; z-index: 1; }

.sa-security-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--gold-dim);
    border: 1px solid var(--gold-ring);
    border-radius: 40px;
    padding: 7px 14px;
    font-family: var(--mono);
    font-size: 10px;
    color: var(--gold2);
    letter-spacing: 1px;
}
.sa-badge-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: var(--gold);
    box-shadow: 0 0 8px var(--gold);
    animation: blink 2s ease-in-out infinite;
}
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.35} }

/* ── Right form panel ─────────────────────────────────── */
.sa-form-panel {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 32px;
    background: var(--navy);
    position: relative;
    overflow: hidden;
    overflow-y: auto;
}

.sa-form-panel::before {
    content: '';
    position: absolute;
    top: -80px; right: -80px;
    width: 400px; height: 400px;
    background: radial-gradient(circle, rgba(201,168,76,.06) 0%, transparent 70%);
    pointer-events: none;
}

.sa-form-inner {
    width: 100%;
    max-width: 380px;
    position: relative;
    z-index: 1;
    animation: riseIn .5s cubic-bezier(.22,1,.36,1) both;
}
@keyframes riseIn {
    from { opacity: 0; transform: translateY(20px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── Form header ──────────────────────────────────────── */
.sa-form-head { margin-bottom: 32px; }

.sa-form-eyebrow {
    font-family: var(--mono);
    font-size: 10px;
    color: var(--gold);
    letter-spacing: 2.5px;
    text-transform: uppercase;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.sa-form-eyebrow::before {
    content: '';
    display: block;
    width: 22px; height: 1px;
    background: var(--gold);
}

.sa-form-title {
    font-family: var(--serif);
    font-size: 30px;
    font-weight: 700;
    color: var(--t1);
    line-height: 1.1;
    margin-bottom: 6px;
}

.sa-form-hint {
    font-size: 12.5px;
    color: var(--t3);
    font-family: var(--mono);
}
.sa-form-hint code {
    color: var(--gold2);
    background: var(--gold-dim);
    padding: 1px 6px;
    border-radius: 4px;
    font-size: 11.5px;
}

/* ── Alert ────────────────────────────────────────────── */
.sa-alert {
    display: none;
    align-items: flex-start;
    gap: 10px;
    background: var(--red-dim);
    border: 1px solid rgba(224,85,85,.25);
    border-left: 3px solid var(--red);
    border-radius: var(--r);
    padding: 11px 14px;
    font-size: 12.5px;
    color: #ffaaaa;
    margin-bottom: 22px;
    font-family: var(--sans);
    animation: alertIn .2s ease;
}
.sa-alert.show { display: flex; }
@keyframes alertIn {
    from { opacity: 0; transform: translateY(-5px); }
    to   { opacity: 1; transform: translateY(0); }
}
.sa-alert i { font-size: 13px; margin-top: 1px; flex-shrink: 0; color: var(--red); }

/* ── Field row ────────────────────────────────────────── */
.sa-row { display: flex; gap: 12px; }
.sa-row .sa-field { flex: 1; }

/* ── Field ────────────────────────────────────────────── */
.sa-field { margin-bottom: 18px; }

.sa-label {
    display: block;
    font-size: 10.5px;
    font-weight: 600;
    color: var(--t3);
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 7px;
    font-family: var(--sans);
}

.sa-input-wrap { position: relative; }

.sa-input-icon {
    position: absolute;
    left: 13px; top: 50%;
    transform: translateY(-50%);
    color: var(--t4);
    font-size: 12px;
    pointer-events: none;
    transition: color var(--ease);
}

.sa-input {
    width: 100%;
    background: var(--navy3);
    border: 1px solid var(--border);
    border-radius: var(--r);
    color: var(--t1);
    font-family: var(--sans);
    font-size: 13.5px;
    padding: 11px 13px 11px 38px;
    outline: none;
    transition: all var(--ease);
    caret-color: var(--gold);
}
.sa-input:focus {
    border-color: var(--gold3);
    box-shadow: 0 0 0 3px var(--gold-dim);
    background: var(--navy4);
}
.sa-input:focus ~ .sa-input-icon { color: var(--gold); }
.sa-input::placeholder { color: var(--t4); }
.sa-input.has-eye { padding-right: 42px; }

.sa-eye {
    position: absolute;
    right: 11px; top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: var(--t3);
    font-size: 12px;
    cursor: pointer;
    padding: 4px;
    transition: color var(--ease);
    line-height: 1;
}
.sa-eye:hover { color: var(--gold); }

/* ── Divider ──────────────────────────────────────────── */
.sa-divider {
    height: 1px;
    background: linear-gradient(90deg, transparent, var(--border), transparent);
    margin: 4px 0 20px;
}

/* ── Submit ───────────────────────────────────────────── */
.sa-btn {
    width: 100%;
    background: var(--gold);
    border: none;
    border-radius: var(--r);
    color: var(--navy);
    font-family: var(--sans);
    font-size: 13.5px;
    font-weight: 700;
    padding: 13px;
    cursor: pointer;
    transition: all var(--ease);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    letter-spacing: .2px;
    position: relative;
    overflow: hidden;
}
.sa-btn::after {
    content: '';
    position: absolute;
    inset: 0;
    background: rgba(255,255,255,.12);
    opacity: 0;
    transition: opacity var(--ease);
}
.sa-btn:hover::after { opacity: 1; }
.sa-btn:hover { box-shadow: 0 6px 28px var(--gold-glow); }
.sa-btn:active { transform: scale(.99); }
.sa-btn:disabled { opacity: .45; cursor: not-allowed; }

.sa-btn-spinner {
    display: none;
    width: 16px; height: 16px;
    border: 2px solid rgba(11,15,26,.3);
    border-top-color: var(--navy);
    border-radius: 50%;
    animation: spin .7s linear infinite;
}
.sa-btn.loading .sa-btn-label { display: none; }
.sa-btn.loading .sa-btn-spinner { display: block; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Back link ────────────────────────────────────────── */
.sa-back { margin-top: 22px; text-align: center; }
.sa-back a {
    font-family: var(--mono);
    font-size: 11.5px;
    color: var(--t3);
    text-decoration: none;
    transition: color var(--ease);
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.sa-back a:hover { color: var(--gold); }

/* ── Responsive ───────────────────────────────────────── */
@media (max-width: 720px) {
    .sa-panel { display: none; }
    .sa-form-panel { padding: 30px 20px; }
}
</style>
</head>
<body>

<div class="sa-layout">

    <!-- ── Left panel ──────────────────────────────────────── -->
    <div class="sa-panel">
        <div class="sa-panel-grid"></div>

        <div class="sa-panel-top">
            <div class="sa-wordmark">School<span>X</span></div>
            <div class="sa-wordmark-sub">Management Platform</div>
        </div>

        <div class="sa-panel-mid">
            <div class="sa-big-letter">X</div>
            <div class="sa-panel-tagline">
                Trusted by schools.<br>
                Built for <em>administrators</em>.
            </div>
        </div>

        <div class="sa-panel-bottom">
            <div class="sa-security-badge">
                <div class="sa-badge-dot"></div>
                SECURE · RESTRICTED ACCESS
            </div>
        </div>
    </div>

    <!-- ── Right form panel ────────────────────────────────── -->
    <div class="sa-form-panel">
        <div class="sa-form-inner">

            <div class="sa-form-head">
                <div class="sa-form-eyebrow">Super Admin</div>
                <div class="sa-form-title">Control Panel</div>
                <div class="sa-form-hint">
                    Developer access only
                </div>
            </div>

            <div class="sa-alert" id="saAlert">
                <i class="fa fa-circle-exclamation"></i>
                <span id="saAlertMsg">Invalid credentials.</span>
            </div>

            <form id="saLoginForm" autocomplete="off" novalidate>

                <div class="sa-field">
                    <label class="sa-label">User ID</label>
                    <div class="sa-input-wrap">
                        <input type="text" class="sa-input" id="saAdminId"
                               name="admin_id" placeholder="Enter your user ID"
                               required autofocus maxlength="32">
                        <i class="fa fa-id-badge sa-input-icon"></i>
                    </div>
                </div>

                <div class="sa-field">
                    <label class="sa-label">Password</label>
                    <div class="sa-input-wrap">
                        <input type="password" class="sa-input has-eye" id="saPassword"
                               name="password" placeholder="••••••••••" required>
                        <i class="fa fa-lock sa-input-icon"></i>
                        <button type="button" class="sa-eye" id="saEyeBtn" aria-label="Toggle password">
                            <i class="fa fa-eye" id="saEyeIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="sa-btn" id="saSubmitBtn">
                    <span class="sa-btn-label">
                        <i class="fa fa-shield-halved"></i>
                        Access Control Panel
                    </span>
                    <span class="sa-btn-spinner"></span>
                </button>

            </form>

            <div style="text-align:center;margin-top:14px;">
                <a href="<?= base_url('superadmin/login/forgot_password') ?>" style="color:var(--gold);font-size:12.5px;text-decoration:none;opacity:.8;transition:opacity .2s;">
                    <i class="fa fa-key" style="margin-right:4px;"></i>Forgot Password?
                </a>
            </div>

            <div class="sa-back">
                <a href="<?= base_url('admin_login') ?>">
                    <i class="fa fa-arrow-left"></i>
                    Back to School Admin Login
                </a>
            </div>

        </div>
    </div>

</div>

<script>
(function () {
    'use strict';

    /* CSRF from meta tags */
    var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var csrfName  = document.querySelector('meta[name="csrf-name"]').getAttribute('content');

    /* Password toggle */
    document.getElementById('saEyeBtn').addEventListener('click', function () {
        var inp  = document.getElementById('saPassword');
        var icon = document.getElementById('saEyeIcon');
        var show = inp.type === 'password';
        inp.type       = show ? 'text' : 'password';
        icon.className = show ? 'fa fa-eye-slash' : 'fa fa-eye';
    });

    /* Alert helpers */
    function showAlert(msg) {
        document.getElementById('saAlertMsg').textContent = msg;
        document.getElementById('saAlert').classList.add('show');
    }
    function hideAlert() {
        document.getElementById('saAlert').classList.remove('show');
    }

    /* Hide alert on input */
    ['saAdminId', 'saPassword'].forEach(function (id) {
        document.getElementById(id).addEventListener('input', hideAlert);
    });

    /* Form submit */
    document.getElementById('saLoginForm').addEventListener('submit', function (e) {
        e.preventDefault();
        hideAlert();

        var btn      = document.getElementById('saSubmitBtn');
        var adminId  = document.getElementById('saAdminId').value.trim();
        var password = document.getElementById('saPassword').value;

        if (!adminId || !password) {
            showAlert('User ID and Password are required.');
            return;
        }

        btn.classList.add('loading');
        btn.disabled = true;

        /* FormData carries CSRF token in $_POST — prevents 403 */
        var fd = new FormData();
        fd.append(csrfName,    csrfToken);
        fd.append('admin_id',  adminId);
        fd.append('password',  password);

        fetch('<?= base_url('superadmin/login/authenticate') ?>', {
            method: 'POST',
            body:   fd
        })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (data) {
            if (data.status === 'success') {
                window.location.href = data.redirect;
            } else {
                showAlert(data.message || 'Login failed. Please try again.');
                btn.classList.remove('loading');
                btn.disabled = false;
            }
        })
        .catch(function (err) {
            showAlert('Server error. Please try again.');
            console.error(err);
            btn.classList.remove('loading');
            btn.disabled = false;
        });
    });

}());
</script>

</body>
</html>