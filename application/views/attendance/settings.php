<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<style>
/* ── Attendance Settings ────────────────────────────────── */
:root {
    --att-primary: var(--gold);
    --att-primary-dim: var(--gold-dim);
    --att-primary-ring: var(--gold-ring);
    --att-primary-glow: var(--gold-glow);
    --att-bg: var(--bg);
    --att-bg2: var(--bg2);
    --att-bg3: var(--bg3);
    --att-border: var(--border);
    --att-t1: var(--t1);
    --att-t2: var(--t2);
    --att-t3: var(--t3);
    --att-shadow: var(--sh);
    --att-radius: 10px;
    --att-green: #16a34a;
    --att-red: #dc2626;
    --att-amber: #d97706;
    --att-blue: #2563eb;
    --att-danger: #c0392b;
    --att-danger-hover: #a93226;
}

.att-wrap { padding: 20px 22px 40px; }

/* ── Header ──────────────────────────────────────────────── */
.att-header {
    display: flex; align-items: center; justify-content: space-between;
    gap: 14px; padding: 18px 22px; margin-bottom: 24px;
    background: var(--att-bg2); border: 1px solid var(--att-border);
    border-radius: var(--att-radius); box-shadow: var(--att-shadow);
    flex-wrap: wrap;
}
.att-header-left { display: flex; align-items: center; gap: 14px; }
.att-header-icon {
    width: 44px; height: 44px; border-radius: 10px;
    background: var(--att-primary); display: flex; align-items: center;
    justify-content: center; flex-shrink: 0;
    box-shadow: 0 0 18px var(--att-primary-glow);
}
.att-header-icon i { color: #fff; font-size: 20px; }
.att-page-title { font-size: 18px; font-weight: 700; color: var(--att-t1); font-family: var(--font-d); }
.att-breadcrumb {
    list-style: none; display: flex; gap: 6px; margin: 4px 0 0; padding: 0;
    font-size: 12px; color: var(--att-t3);
}
.att-breadcrumb li + li::before { content: '/'; margin-right: 6px; color: var(--att-t3); }
.att-breadcrumb a { color: var(--att-primary); text-decoration: none; }
.att-breadcrumb a:hover { text-decoration: underline; }

/* ── Tabs ────────────────────────────────────────────────── */
.att-tabs { display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: 22px; }
.att-tab {
    display: flex; align-items: center; gap: 7px;
    padding: 9px 18px; border-radius: 8px; border: 1px solid var(--att-border);
    background: var(--att-bg2); color: var(--att-t2); font-size: 12.5px; font-weight: 600;
    cursor: pointer; transition: all 0.25s ease; white-space: nowrap;
    user-select: none;
}
.att-tab:hover { border-color: var(--att-primary); color: var(--att-primary); }
.att-tab.active {
    background: var(--att-primary); color: #fff; border-color: var(--att-primary);
    box-shadow: 0 0 14px var(--att-primary-glow);
}
.att-tab i { font-size: 13px; }

/* ── Panes ───────────────────────────────────────────────── */
.att-pane { display: none; }
.att-pane.active { display: block; }

/* ── Card ────────────────────────────────────────────────── */
.att-card {
    background: var(--att-bg2); border: 1px solid var(--att-border);
    border-radius: var(--att-radius); padding: 22px; margin-bottom: 18px;
    box-shadow: var(--att-shadow);
}
.att-card-title {
    font-size: 14px; font-weight: 700; color: var(--att-t1);
    margin-bottom: 16px; display: flex; align-items: center; gap: 8px;
}
.att-card-title i { color: var(--att-primary); font-size: 15px; }

/* ── Form Grid ───────────────────────────────────────────── */
.att-grid { display: grid; gap: 14px; }
.att-grid-2 { grid-template-columns: 1fr 1fr; }
.att-grid-3 { grid-template-columns: 1fr 1fr 1fr; }
@media(max-width:640px) { .att-grid-2, .att-grid-3 { grid-template-columns: 1fr; } }

.att-field label {
    display: block; font-size: 11.5px; font-weight: 600;
    color: var(--att-t2); margin-bottom: 5px; text-transform: uppercase; letter-spacing: .4px;
}
.att-field input, .att-field select, .att-field textarea {
    width: 100%; padding: 9px 12px; border-radius: 8px;
    border: 1px solid var(--att-border); background: var(--att-bg3);
    color: var(--att-t1); font-size: 13px; font-family: var(--font-b);
    transition: border-color 0.25s ease, box-shadow 0.25s ease;
    outline: none; box-sizing: border-box;
}
.att-field input:focus, .att-field select:focus, .att-field textarea:focus {
    border-color: var(--att-primary); box-shadow: 0 0 0 3px var(--att-primary-ring);
}
.att-field select option { background: var(--att-bg3); }

/* ── Buttons ─────────────────────────────────────────────── */
.att-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 18px; border-radius: 8px; border: none;
    font-size: 13px; font-weight: 600; cursor: pointer;
    transition: all 0.25s ease; font-family: var(--font-b);
}
.att-btn-primary {
    background: var(--att-primary); color: #fff;
    box-shadow: 0 2px 10px var(--att-primary-ring);
}
.att-btn-primary:hover { filter: brightness(0.9); }
.att-btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.att-btn-danger { background: var(--att-danger); color: #fff; }
.att-btn-danger:hover { background: var(--att-danger-hover); }
.att-btn-ghost {
    background: transparent; color: var(--att-t2);
    border: 1px solid var(--att-border);
}
.att-btn-ghost:hover { border-color: var(--att-primary); color: var(--att-primary); }
.att-btn-sm { padding: 5px 12px; font-size: 11.5px; }

/* ── Toggle Switch ───────────────────────────────────────── */
.att-toggle-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 0; border-bottom: 1px solid var(--att-border);
}
.att-toggle-row:last-child { border-bottom: none; }
.att-toggle-label {
    font-size: 13px; font-weight: 600; color: var(--att-t1);
    display: flex; align-items: center; gap: 8px;
}
.att-toggle-label i { color: var(--att-primary); font-size: 14px; }
.att-toggle-desc { font-size: 11.5px; color: var(--att-t3); margin-top: 2px; }
.att-switch {
    position: relative; display: inline-block;
    width: 44px; height: 24px; flex-shrink: 0;
}
.att-switch input { opacity: 0; width: 0; height: 0; position: absolute; }
.att-switch-slider {
    position: absolute; cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background: var(--att-bg3); border: 1px solid var(--att-border);
    border-radius: 24px; transition: all 0.25s ease;
}
.att-switch-slider::before {
    content: ''; position: absolute;
    width: 18px; height: 18px; left: 2px; bottom: 2px;
    background: var(--att-t3); border-radius: 50%;
    transition: all 0.25s ease;
}
.att-switch input:checked + .att-switch-slider {
    background: var(--att-primary); border-color: var(--att-primary);
}
.att-switch input:checked + .att-switch-slider::before {
    transform: translateX(20px); background: #fff;
}

/* ── Checkboxes ──────────────────────────────────────────── */
.att-check-group { display: flex; gap: 8px; flex-wrap: wrap; }
.att-check-item {
    display: flex; align-items: center; gap: 7px;
    padding: 8px 14px; border-radius: 8px; border: 1px solid var(--att-border);
    background: var(--att-bg3); cursor: pointer; transition: all 0.25s ease;
    font-size: 13px; color: var(--att-t2); font-weight: 600;
    user-select: none;
}
.att-check-item:hover { border-color: var(--att-primary); color: var(--att-primary); }
.att-check-item.checked {
    border-color: var(--att-primary); background: var(--att-primary-dim); color: var(--att-primary);
}
.att-check-item input { display: none; }

/* ── Table ───────────────────────────────────────────────── */
.att-table-wrap { overflow-x: auto; }
.att-table { width: 100%; border-collapse: collapse; }
.att-table th {
    text-align: left; padding: 8px 12px; font-size: 11px;
    font-weight: 700; color: var(--att-t3); text-transform: uppercase;
    letter-spacing: .5px; border-bottom: 1px solid var(--att-border);
}
.att-table td {
    padding: 9px 12px; font-size: 13px; color: var(--att-t1);
    border-bottom: 1px solid var(--att-border);
}
.att-table tr:last-child td { border-bottom: none; }
.att-table tr:hover td { background: var(--att-primary-dim); }

/* ── Tags ────────────────────────────────────────────────── */
.att-tag {
    display: inline-block; padding: 2px 9px; border-radius: 20px;
    font-size: 10.5px; font-weight: 700; font-family: var(--font-m);
}
.att-tag-green { background: rgba(21,128,61,.12); color: #15803d; border: 1px solid rgba(21,128,61,.25); }
.att-tag-red { background: rgba(192,57,43,.12); color: #c0392b; border: 1px solid rgba(192,57,43,.25); }
.att-tag-amber { background: rgba(217,119,6,.12); color: #d97706; border: 1px solid rgba(217,119,6,.25); }
.att-tag-blue { background: rgba(37,99,235,.12); color: #2563eb; border: 1px solid rgba(37,99,235,.25); }
.att-tag-gray { background: var(--att-bg3); color: var(--att-t3); border: 1px solid var(--att-border); }

/* ── Alert ───────────────────────────────────────────────── */
.att-alert {
    padding: 12px 16px; border-radius: 8px; font-size: 13px;
    font-weight: 600; margin-bottom: 16px; display: none;
    align-items: center; gap: 8px;
}
.att-alert.show { display: flex; }
.att-alert-success { background: rgba(21,128,61,.1); color: #15803d; border: 1px solid rgba(21,128,61,.2); }
.att-alert-error { background: rgba(192,57,43,.1); color: #c0392b; border: 1px solid rgba(192,57,43,.2); }
.att-alert-info { background: rgba(37,99,235,.1); color: #2563eb; border: 1px solid rgba(37,99,235,.2); }

/* ── Copyable Key Box ────────────────────────────────────── */
.att-key-box {
    display: none; padding: 14px 16px; border-radius: 8px;
    background: rgba(37,99,235,.08); border: 1px solid rgba(37,99,235,.2);
    margin-bottom: 16px;
}
.att-key-box.show { display: block; }
.att-key-box-title {
    font-size: 12px; font-weight: 700; color: var(--att-blue);
    text-transform: uppercase; letter-spacing: .4px; margin-bottom: 8px;
}
.att-key-box-row { display: flex; align-items: center; gap: 8px; }
.att-key-box-val {
    flex: 1; padding: 8px 12px; border-radius: 6px;
    background: var(--att-bg3); border: 1px solid var(--att-border);
    font-size: 13px; font-family: var(--font-m); color: var(--att-t1);
    word-break: break-all;
}
.att-key-copy-btn {
    padding: 8px 14px; border-radius: 6px; border: none;
    background: var(--att-blue); color: #fff; font-size: 12px;
    font-weight: 600; cursor: pointer; white-space: nowrap;
    transition: filter 0.2s ease;
}
.att-key-copy-btn:hover { filter: brightness(0.9); }

/* ── Empty State ─────────────────────────────────────────── */
.att-empty {
    text-align: center; padding: 40px 20px; color: var(--att-t3);
    font-size: 13px; font-weight: 600;
}
.att-empty i { font-size: 32px; margin-bottom: 10px; display: block; opacity: 0.4; }

/* ── Spinner ─────────────────────────────────────────────── */
.att-spinner {
    display: inline-block; width: 14px; height: 14px;
    border: 2px solid rgba(255,255,255,.3); border-top-color: #fff;
    border-radius: 50%; animation: att-spin 0.6s linear infinite;
}
@keyframes att-spin { to { transform: rotate(360deg); } }

/* ── Responsive ──────────────────────────────────────────── */
@media(max-width:767px) {
    .att-wrap { padding: 14px 10px 30px; }
    .att-header { padding: 14px 16px; }
    .att-card { padding: 16px; }
    .att-tabs { gap: 3px; }
    .att-tab { padding: 7px 12px; font-size: 11.5px; }
}
@media(max-width:479px) {
    .att-tab { padding: 6px 10px; font-size: 11px; }
    .att-toggle-row { flex-direction: column; align-items: flex-start; gap: 8px; }
}
</style>

<div class="content-wrapper">
<section class="content">
<div class="container-fluid">
<div class="att-wrap">

    <!-- ── Header ────────────────────────────────────────── -->
    <div class="att-header">
        <div class="att-header-left">
            <div class="att-header-icon"><i class="fa fa-cogs"></i></div>
            <div>
                <div class="att-page-title">Attendance Settings</div>
                <ul class="att-breadcrumb">
                    <li><a href="<?= base_url('attendance') ?>">Attendance</a></li>
                    <li>Settings</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- ── Global Alert ──────────────────────────────────── -->
    <div id="attAlert" class="att-alert"></div>

    <!-- ── Tabs ──────────────────────────────────────────── -->
    <div class="att-tabs">
        <div class="att-tab active" data-tab="general"><i class="fa fa-sliders"></i> General</div>
        <div class="att-tab" data-tab="holidays"><i class="fa fa-calendar-times-o"></i> Holiday Calendar</div>
        <div class="att-tab" data-tab="devices"><i class="fa fa-microchip"></i> Device Management</div>
    </div>

    <!-- ═══════════════════════════════════════════════════ -->
    <!-- TAB 1: General Settings                            -->
    <!-- ═══════════════════════════════════════════════════ -->
    <div id="pane-general" class="att-pane active">

        <!-- Time thresholds -->
        <div class="att-card">
            <div class="att-card-title"><i class="fa fa-clock-o"></i> Late Thresholds</div>
            <div class="att-grid att-grid-2">
                <div class="att-field">
                    <label>Late Threshold - Students</label>
                    <input type="time" id="studentLateTime" value="08:30">
                </div>
                <div class="att-field">
                    <label>Late Threshold - Staff</label>
                    <input type="time" id="staffLateTime" value="09:00">
                </div>
            </div>
        </div>

        <!-- Working days -->
        <div class="att-card">
            <div class="att-card-title"><i class="fa fa-calendar-check-o"></i> Working Days</div>
            <div class="att-check-group" id="workingDaysGroup">
                <label class="att-check-item checked"><input type="checkbox" value="Mon" checked> Mon</label>
                <label class="att-check-item checked"><input type="checkbox" value="Tue" checked> Tue</label>
                <label class="att-check-item checked"><input type="checkbox" value="Wed" checked> Wed</label>
                <label class="att-check-item checked"><input type="checkbox" value="Thu" checked> Thu</label>
                <label class="att-check-item checked"><input type="checkbox" value="Fri" checked> Fri</label>
                <label class="att-check-item checked"><input type="checkbox" value="Sat" checked> Sat</label>
                <label class="att-check-item"><input type="checkbox" value="Sun"> Sun</label>
            </div>
        </div>

        <!-- Device toggles -->
        <div class="att-card">
            <div class="att-card-title"><i class="fa fa-plug"></i> Integrations</div>

            <div class="att-toggle-row">
                <div>
                    <div class="att-toggle-label"><i class="fa fa-hand-pointer-o"></i> Biometric Enabled</div>
                    <div class="att-toggle-desc">Allow fingerprint-based attendance capture</div>
                </div>
                <label class="att-switch">
                    <input type="checkbox" id="toggleBiometric">
                    <span class="att-switch-slider"></span>
                </label>
            </div>

            <div class="att-toggle-row">
                <div>
                    <div class="att-toggle-label"><i class="fa fa-id-card-o"></i> RFID Enabled</div>
                    <div class="att-toggle-desc">Allow RFID card-based attendance capture</div>
                </div>
                <label class="att-switch">
                    <input type="checkbox" id="toggleRFID">
                    <span class="att-switch-slider"></span>
                </label>
            </div>

            <div class="att-toggle-row">
                <div>
                    <div class="att-toggle-label"><i class="fa fa-eye"></i> Face Recognition Enabled</div>
                    <div class="att-toggle-desc">Allow camera-based facial recognition attendance</div>
                </div>
                <label class="att-switch">
                    <input type="checkbox" id="toggleFaceRec">
                    <span class="att-switch-slider"></span>
                </label>
            </div>
        </div>

        <button class="att-btn att-btn-primary" id="btnSaveSettings">
            <i class="fa fa-check"></i> Save Settings
        </button>
    </div>

    <!-- ═══════════════════════════════════════════════════ -->
    <!-- TAB 2: Holiday Calendar                            -->
    <!-- ═══════════════════════════════════════════════════ -->
    <div id="pane-holidays" class="att-pane">

        <!-- Add holiday form -->
        <div class="att-card">
            <div class="att-card-title"><i class="fa fa-plus-circle"></i> Add Holiday</div>
            <div class="att-grid att-grid-3">
                <div class="att-field">
                    <label>Date</label>
                    <input type="date" id="holidayDate">
                </div>
                <div class="att-field">
                    <label>Holiday Name</label>
                    <input type="text" id="holidayName" placeholder="e.g. Republic Day">
                </div>
                <div class="att-field" style="display:flex;align-items:flex-end;">
                    <button class="att-btn att-btn-primary" id="btnAddHoliday" style="width:100%;">
                        <i class="fa fa-plus"></i> Add
                    </button>
                </div>
            </div>
        </div>

        <!-- Holiday list -->
        <div class="att-card">
            <div class="att-card-title"><i class="fa fa-calendar"></i> Holidays</div>
            <div class="att-table-wrap">
                <table class="att-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Holiday Name</th>
                            <th style="text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="holidayTableBody">
                        <tr><td colspan="4"><div class="att-empty"><i class="fa fa-calendar-o"></i>No holidays added yet</div></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════ -->
    <!-- TAB 3: Device Management                           -->
    <!-- ═══════════════════════════════════════════════════ -->
    <div id="pane-devices" class="att-pane">

        <!-- API Key alert (shown once on register) -->
        <div id="deviceKeyBox" class="att-key-box">
            <div class="att-key-box-title"><i class="fa fa-key"></i> Device API Key (copy now, shown only once)</div>
            <div class="att-key-box-row">
                <div class="att-key-box-val" id="deviceKeyVal"></div>
                <button class="att-key-copy-btn" id="btnCopyKey"><i class="fa fa-clipboard"></i> Copy</button>
            </div>
        </div>

        <!-- Register device form -->
        <div class="att-card">
            <div class="att-card-title"><i class="fa fa-plus-circle"></i> Register Device</div>
            <div class="att-grid att-grid-3">
                <div class="att-field">
                    <label>Device Name</label>
                    <input type="text" id="deviceName" placeholder="e.g. Main Gate Scanner">
                </div>
                <div class="att-field">
                    <label>Device Type</label>
                    <select id="deviceType">
                        <option value="">-- Select --</option>
                        <option value="biometric">Biometric</option>
                        <option value="rfid">RFID</option>
                        <option value="face_recognition">Face Recognition</option>
                    </select>
                </div>
                <div class="att-field">
                    <label>Location</label>
                    <input type="text" id="deviceLocation" placeholder="e.g. Main Entrance">
                </div>
            </div>
            <div style="margin-top:14px;">
                <button class="att-btn att-btn-primary" id="btnRegisterDevice">
                    <i class="fa fa-plus"></i> Register Device
                </button>
            </div>
        </div>

        <!-- Devices list -->
        <div class="att-card">
            <div class="att-card-title"><i class="fa fa-server"></i> Registered Devices</div>
            <div class="att-table-wrap">
                <table class="att-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th>Last Ping</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="deviceTableBody">
                        <tr><td colspan="6"><div class="att-empty"><i class="fa fa-microchip"></i>No devices registered</div></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div><!-- .att-wrap -->
</div><!-- .container-fluid -->
</section>
</div><!-- .content-wrapper -->

<script>
(function(){
    'use strict';

    var BASE = '<?= base_url() ?>';
    var CSRF_NAME = '<?= $this->security->get_csrf_token_name() ?>';
    var CSRF_HASH = '<?= $this->security->get_csrf_hash() ?>';

    /* ── Helpers ─────────────────────────────────────────── */
    function esc(s) {
        if (s === null || s === undefined) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(s)));
        return d.innerHTML;
    }

    function showAlert(msg, type) {
        var el = document.getElementById('attAlert');
        el.className = 'att-alert show att-alert-' + (type || 'success');
        el.innerHTML = '<i class="fa fa-' + (type === 'error' ? 'exclamation-circle' : type === 'info' ? 'info-circle' : 'check-circle') + '"></i> ' + esc(msg);
        clearTimeout(el._t);
        el._t = setTimeout(function(){ el.classList.remove('show'); }, 4000);
    }

    var settingsLoaded = false;

    function postData(url, data) {
        var fd = new FormData();
        fd.append(CSRF_NAME, CSRF_HASH);
        if (data) {
            Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
        }
        return fetch(BASE + url, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r){
                var ct = r.headers.get('content-type') || '';
                if (ct.indexOf('application/json') === -1) {
                    throw new Error('Non-JSON response (status ' + r.status + ')');
                }
                return r.json();
            })
            .then(function(j){
                if (j.csrf_hash) CSRF_HASH = j.csrf_hash;
                return j;
            });
    }

    function getData(url) {
        return fetch(BASE + url, { credentials: 'same-origin' })
            .then(function(r){
                var ct = r.headers.get('content-type') || '';
                if (ct.indexOf('application/json') === -1) {
                    throw new Error('Non-JSON response (status ' + r.status + ')');
                }
                return r.json();
            });
    }

    function formatDate(d) {
        if (!d) return '-';
        var dt = new Date(d);
        if (isNaN(dt.getTime())) return esc(d);
        return dt.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    function typeLabel(t) {
        var map = { biometric: 'Biometric', rfid: 'RFID', face_recognition: 'Face Recognition' };
        return map[t] || esc(t);
    }

    function statusTag(s) {
        var cls = s === 'online' ? 'green' : s === 'offline' ? 'red' : 'gray';
        return '<span class="att-tag att-tag-' + cls + '">' + esc(s || 'unknown') + '</span>';
    }

    /* ── Tab Switching ───────────────────────────────────── */
    var tabs = document.querySelectorAll('.att-tab');
    var panes = document.querySelectorAll('.att-pane');
    tabs.forEach(function(tab){
        tab.addEventListener('click', function(){
            var target = this.getAttribute('data-tab');
            tabs.forEach(function(t){ t.classList.remove('active'); });
            panes.forEach(function(p){ p.classList.remove('active'); });
            this.classList.add('active');
            var pane = document.getElementById('pane-' + target);
            if (pane) pane.classList.add('active');
        });
    });

    /* ── Checkbox items toggle ───────────────────────────── */
    document.querySelectorAll('.att-check-item').forEach(function(item){
        item.addEventListener('click', function(e){
            if (e.target.tagName === 'INPUT') return;
            var cb = this.querySelector('input[type="checkbox"]');
            cb.checked = !cb.checked;
            this.classList.toggle('checked', cb.checked);
        });
        var cb = item.querySelector('input[type="checkbox"]');
        cb.addEventListener('change', function(){
            item.classList.toggle('checked', this.checked);
        });
    });

    /* ═════════════════════════════════════════════════════ */
    /* TAB 1: General Settings                              */
    /* ═════════════════════════════════════════════════════ */

    function loadSettings() {
        getData('attendance/get_settings').then(function(r){
            if (!r || !r.status) return;
            var d = r.config || {};
            if (d.late_threshold_student) document.getElementById('studentLateTime').value = d.late_threshold_student;
            if (d.late_threshold_staff) document.getElementById('staffLateTime').value = d.late_threshold_staff;

            if (d.working_days && Array.isArray(d.working_days)) {
                document.querySelectorAll('#workingDaysGroup .att-check-item').forEach(function(item){
                    var cb = item.querySelector('input');
                    var checked = d.working_days.indexOf(cb.value) !== -1;
                    cb.checked = checked;
                    item.classList.toggle('checked', checked);
                });
            }

            document.getElementById('toggleBiometric').checked = (d.biometric_enabled === true || d.biometric_enabled === 'true');
            document.getElementById('toggleRFID').checked = (d.rfid_enabled === true || d.rfid_enabled === 'true');
            document.getElementById('toggleFaceRec').checked = (d.face_recognition_enabled === true || d.face_recognition_enabled === 'true');
            settingsLoaded = true;
        }).catch(function(){
            showAlert('Failed to load settings. Please refresh the page.', 'error');
        });
    }

    document.getElementById('btnSaveSettings').addEventListener('click', function(){
        if (!settingsLoaded) {
            showAlert('Settings not loaded yet. Please wait or refresh the page.', 'error');
            return;
        }
        var btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="att-spinner"></span> Saving...';

        var days = [];
        document.querySelectorAll('#workingDaysGroup input:checked').forEach(function(cb){
            days.push(cb.value);
        });

        postData('attendance/save_settings', {
            late_threshold_student: document.getElementById('studentLateTime').value,
            late_threshold_staff: document.getElementById('staffLateTime').value,
            working_days: JSON.stringify(days),
            biometric_enabled: document.getElementById('toggleBiometric').checked ? '1' : '0',
            rfid_enabled: document.getElementById('toggleRFID').checked ? '1' : '0',
            face_recognition_enabled: document.getElementById('toggleFaceRec').checked ? '1' : '0'
        }).then(function(r){
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-check"></i> Save Settings';
            if (r.status) showAlert('Settings saved successfully', 'success');
            else showAlert(r.message || 'Failed to save settings', 'error');
        }).catch(function(){
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-check"></i> Save Settings';
            showAlert('Network error while saving settings', 'error');
        });
    });

    /* ═════════════════════════════════════════════════════ */
    /* TAB 2: Holiday Calendar                              */
    /* ═════════════════════════════════════════════════════ */

    var holidays = [];

    function renderHolidays() {
        var tbody = document.getElementById('holidayTableBody');
        if (!holidays.length) {
            tbody.innerHTML = '<tr><td colspan="4"><div class="att-empty"><i class="fa fa-calendar-o"></i>No holidays added yet</div></td></tr>';
            return;
        }
        var html = '';
        holidays.sort(function(a,b){ return (a.date || '').localeCompare(b.date || ''); });
        holidays.forEach(function(h, i){
            html += '<tr>'
                + '<td>' + (i + 1) + '</td>'
                + '<td>' + formatDate(h.date) + '</td>'
                + '<td>' + esc(h.name) + '</td>'
                + '<td style="text-align:right;">'
                + '<button class="att-btn att-btn-danger att-btn-sm" data-delete-holiday="' + esc(h.id || h.date) + '"><i class="fa fa-trash"></i></button>'
                + '</td></tr>';
        });
        tbody.innerHTML = html;

        tbody.querySelectorAll('[data-delete-holiday]').forEach(function(btn){
            btn.addEventListener('click', function(){
                var id = this.getAttribute('data-delete-holiday');
                deleteHoliday(id);
            });
        });
    }

    function loadHolidays() {
        getData('attendance/get_holidays').then(function(r){
            holidays = [];
            if (r && r.status && r.holidays) {
                var h = r.holidays;
                if (typeof h === 'object' && !Array.isArray(h)) {
                    Object.keys(h).forEach(function(date){
                        holidays.push({ date: date, name: h[date] });
                    });
                } else if (Array.isArray(h)) {
                    holidays = h;
                }
            }
            renderHolidays();
        }).catch(function(){ holidays = []; renderHolidays(); });
    }

    function saveHolidays() {
        // Convert array [{date, name},...] to object {"YYYY-MM-DD": "Name"} for controller
        var obj = {};
        holidays.forEach(function(h){ if (h.date) obj[h.date] = h.name || ''; });
        return postData('attendance/save_holidays', { holidays: JSON.stringify(obj) });
    }

    document.getElementById('btnAddHoliday').addEventListener('click', function(){
        var dateEl = document.getElementById('holidayDate');
        var nameEl = document.getElementById('holidayName');
        var date = dateEl.value.trim();
        var name = nameEl.value.trim();

        if (!date) { showAlert('Please select a date', 'error'); return; }
        if (!name) { showAlert('Please enter a holiday name', 'error'); return; }

        var dup = holidays.some(function(h){ return h.date === date; });
        if (dup) { showAlert('A holiday already exists for this date', 'error'); return; }

        holidays.push({ id: date + '_' + Date.now(), date: date, name: name });
        renderHolidays();

        saveHolidays().then(function(r){
            if (r.status) {
                showAlert('Holiday added', 'success');
                dateEl.value = '';
                nameEl.value = '';
            } else {
                showAlert(r.message || 'Failed to save holiday', 'error');
            }
        }).catch(function(){
            showAlert('Network error', 'error');
        });
    });

    function deleteHoliday(id) {
        holidays = holidays.filter(function(h){ return (h.id || h.date) !== id; });
        renderHolidays();
        saveHolidays().then(function(r){
            if (r.status) showAlert('Holiday removed', 'success');
            else showAlert(r.message || 'Failed to save', 'error');
        }).catch(function(){ showAlert('Network error', 'error'); });
    }

    /* ═════════════════════════════════════════════════════ */
    /* TAB 3: Device Management                             */
    /* ═════════════════════════════════════════════════════ */

    var devices = [];

    function renderDevices() {
        var tbody = document.getElementById('deviceTableBody');
        if (!devices.length) {
            tbody.innerHTML = '<tr><td colspan="6"><div class="att-empty"><i class="fa fa-microchip"></i>No devices registered</div></td></tr>';
            return;
        }
        var html = '';
        devices.forEach(function(d){
            html += '<tr>'
                + '<td>' + esc(d.name) + '</td>'
                + '<td><span class="att-tag att-tag-blue">' + esc(typeLabel(d.type)) + '</span></td>'
                + '<td>' + esc(d.location || '-') + '</td>'
                + '<td>' + statusTag(d.status) + '</td>'
                + '<td>' + (d.last_ping ? formatDate(d.last_ping) : '<span style="color:var(--att-t3)">Never</span>') + '</td>'
                + '<td style="text-align:right; white-space:nowrap;">'
                + '<button class="att-btn att-btn-ghost att-btn-sm" data-edit-device="' + esc(d.id) + '" title="Edit"><i class="fa fa-pencil"></i></button> '
                + '<button class="att-btn att-btn-ghost att-btn-sm" data-regen-device="' + esc(d.id) + '" title="Regenerate Key"><i class="fa fa-key"></i></button> '
                + '<button class="att-btn att-btn-danger att-btn-sm" data-delete-device="' + esc(d.id) + '" title="Delete"><i class="fa fa-trash"></i></button>'
                + '</td></tr>';
        });
        tbody.innerHTML = html;

        tbody.querySelectorAll('[data-edit-device]').forEach(function(btn){
            btn.addEventListener('click', function(){ editDevice(this.getAttribute('data-edit-device')); });
        });
        tbody.querySelectorAll('[data-regen-device]').forEach(function(btn){
            btn.addEventListener('click', function(){ regenDeviceKey(this.getAttribute('data-regen-device')); });
        });
        tbody.querySelectorAll('[data-delete-device]').forEach(function(btn){
            btn.addEventListener('click', function(){ deleteDevice(this.getAttribute('data-delete-device')); });
        });
    }

    function loadDevices() {
        getData('attendance/fetch_devices').then(function(r){
            if (r && r.status && r.devices) {
                devices = Array.isArray(r.devices) ? r.devices : Object.values(r.devices);
            } else {
                devices = [];
            }
            renderDevices();
        }).catch(function(){ devices = []; renderDevices(); });
    }

    document.getElementById('btnRegisterDevice').addEventListener('click', function(){
        var nameEl = document.getElementById('deviceName');
        var typeEl = document.getElementById('deviceType');
        var locEl = document.getElementById('deviceLocation');
        var name = nameEl.value.trim();
        var type = typeEl.value;
        var location = locEl.value.trim();

        if (!name) { showAlert('Please enter a device name', 'error'); return; }
        if (!type) { showAlert('Please select a device type', 'error'); return; }
        if (!location) { showAlert('Please enter a device location', 'error'); return; }

        var btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="att-spinner"></span> Registering...';

        postData('attendance/register_device', {
            name: name,
            type: type,
            location: location
        }).then(function(r){
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-plus"></i> Register Device';
            if (r.status) {
                showAlert('Device registered successfully', 'success');
                nameEl.value = '';
                typeEl.value = '';
                locEl.value = '';

                if (r.api_key) {
                    var keyBox = document.getElementById('deviceKeyBox');
                    document.getElementById('deviceKeyVal').textContent = r.api_key;
                    keyBox.classList.add('show');
                }

                loadDevices();
            } else {
                showAlert(r.message || 'Failed to register device', 'error');
            }
        }).catch(function(){
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-plus"></i> Register Device';
            showAlert('Network error', 'error');
        });
    });

    document.getElementById('btnCopyKey').addEventListener('click', function(){
        var val = document.getElementById('deviceKeyVal').textContent;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(val).then(function(){
                showAlert('API key copied to clipboard', 'info');
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = val;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            showAlert('API key copied to clipboard', 'info');
        }
    });

    function editDevice(id) {
        var dev = devices.find(function(d){ return d.id === id; });
        if (!dev) return;

        var newName = prompt('Device Name:', dev.name);
        if (newName === null) return;
        newName = newName.trim();
        if (!newName) { showAlert('Name cannot be empty', 'error'); return; }

        var newLoc = prompt('Location:', dev.location || '');
        if (newLoc === null) return;
        newLoc = newLoc.trim();

        postData('attendance/update_device', {
            device_id: id,
            name: newName,
            location: newLoc
        }).then(function(r){
            if (r.status) {
                showAlert('Device updated', 'success');
                loadDevices();
            } else {
                showAlert(r.message || 'Update failed', 'error');
            }
        }).catch(function(){ showAlert('Network error', 'error'); });
    }

    function regenDeviceKey(id) {
        if (!confirm('Regenerate API key for this device? The old key will stop working.')) return;

        postData('attendance/regenerate_key', { device_id: id })
        .then(function(r){
            if (r.status && r.api_key) {
                document.getElementById('deviceKeyVal').textContent = r.api_key;
                document.getElementById('deviceKeyBox').classList.add('show');
                showAlert('New API key generated', 'info');
            } else {
                showAlert(r.message || 'Failed to regenerate key', 'error');
            }
        }).catch(function(){ showAlert('Network error', 'error'); });
    }

    function deleteDevice(id) {
        if (!confirm('Delete this device permanently?')) return;

        postData('attendance/delete_device', { device_id: id })
        .then(function(r){
            if (r.status) {
                showAlert('Device deleted', 'success');
                loadDevices();
            } else {
                showAlert(r.message || 'Delete failed', 'error');
            }
        }).catch(function(){ showAlert('Network error', 'error'); });
    }

    /* ── Init ────────────────────────────────────────────── */
    loadSettings();
    loadHolidays();
    loadDevices();

})();
</script>
