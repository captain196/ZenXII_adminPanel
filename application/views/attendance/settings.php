<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<!-- Leaflet (OpenStreetMap) — used by the GPS Attendance campus map picker -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<!-- Attendance Design System (shared, cacheable) — see assets/css/attendance_design_system.css -->
<link rel="stylesheet" href="<?= base_url('assets/css/attendance_design_system.css') ?>?v=2.0.0">

<style>
/* Page-local: the GPS tab's Leaflet map container only. All component styling
   now lives in the shared Attendance Design System stylesheet above. */
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
        <div class="att-tab" data-tab="gps"><i class="fa fa-map-marker"></i> GPS Attendance</div>
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

        <!-- Read-only Holiday Management — authoring is the Academic Calendar -->
        <div class="att-card">
            <div class="att-card-title"><i class="fa fa-info-circle"></i> Holiday Management</div>
            <div style="font-size:12.5px;color:var(--att-t2);margin-bottom:14px;">
                Holidays are authored in the <strong>Academic Calendar</strong> — the single source of truth.
                This page is read-only.
            </div>
            <div class="att-grid att-grid-3">
                <div class="att-field"><label>Canonical Source</label>
                    <div id="holSource" style="font-size:14px;font-weight:700;color:var(--att-t1);">Academic Calendar</div></div>
                <div class="att-field"><label>Academic Session</label>
                    <div id="holSession" style="font-size:14px;font-weight:700;color:var(--att-t1);">—</div></div>
                <div class="att-field"><label>Last Updated</label>
                    <div id="holUpdated" style="font-size:14px;font-weight:700;color:var(--att-t1);">—</div></div>
            </div>
            <div class="att-grid att-grid-2" style="margin-top:12px;">
                <div class="att-field"><label>Total Holidays</label>
                    <div id="holTotal" style="font-size:18px;font-weight:700;color:var(--att-primary);">—</div></div>
                <div class="att-field"><label>Upcoming Holidays</label>
                    <div id="holUpcoming" style="font-size:18px;font-weight:700;color:var(--att-primary);">—</div></div>
            </div>
            <div style="margin-top:16px;">
                <a class="att-btn att-btn-primary" id="btnOpenCalendar" href="<?= base_url('academic') ?>#calendar">
                    <i class="fa fa-calendar"></i> Open Academic Calendar
                </a>
            </div>
        </div>

        <!-- Read-only holiday list -->
        <div class="att-card">
            <div class="att-card-title"><i class="fa fa-calendar"></i> Holidays (read-only)</div>
            <div class="att-table-wrap">
                <table class="att-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Holiday Name</th>
                        </tr>
                    </thead>
                    <tbody id="holidayTableBody">
                        <tr><td colspan="3"><div class="att-empty"><i class="fa fa-calendar-o"></i>No holidays defined. Add them in the Academic Calendar.</div></td></tr>
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

    <!-- ═══════════════════════════════════════════════════ -->
    <!-- TAB 4: GPS Attendance                              -->
    <!-- ═══════════════════════════════════════════════════ -->
    <div id="pane-gps" class="att-pane">

        <!-- Enable + campus geofence -->
        <div class="att-card">
            <div class="att-card-title"><i class="fa fa-map-marker"></i> Campus Geofence</div>

            <div class="att-toggle-row">
                <div>
                    <div class="att-toggle-label"><i class="fa fa-location-arrow"></i> Enable GPS Attendance</div>
                    <div class="att-toggle-desc">Allow staff to check in/out from the Teacher app when physically inside the campus geofence</div>
                </div>
                <label class="att-switch">
                    <input type="checkbox" id="gpsEnabled">
                    <span class="att-switch-slider"></span>
                </label>
            </div>

            <div style="margin:14px 0 10px;font-size:11.5px;color:var(--att-t3);">
                Click on the map to drop the campus pin, or drag the marker. The circle shows the allowed radius.
            </div>
            <button type="button" class="att-btn att-btn-ghost att-btn-sm" id="gpsUseMyLoc" style="margin-bottom:10px;">
                <i class="fa fa-location-arrow"></i> Use my current location
            </button>
            <div id="gpsMap" style="height:320px;border-radius:8px;border:1px solid var(--att-border);"></div>

            <div class="att-grid att-grid-3" style="margin-top:14px;">
                <div class="att-field">
                    <label>Campus Latitude</label>
                    <input type="number" step="any" id="gpsLat" placeholder="e.g. 26.8512">
                </div>
                <div class="att-field">
                    <label>Campus Longitude</label>
                    <input type="number" step="any" id="gpsLng" placeholder="e.g. 80.9462">
                </div>
                <div class="att-field">
                    <label>Radius (metres)</label>
                    <input type="number" min="10" max="5000" id="gpsRadius" value="200">
                </div>
            </div>
        </div>

        <!-- Accuracy & anti-spoof -->
        <div class="att-card">
            <div class="att-card-title"><i class="fa fa-shield"></i> Accuracy &amp; Anti-Spoof</div>
            <div class="att-grid att-grid-2">
                <div class="att-field">
                    <label>Max GPS Accuracy (metres)</label>
                    <input type="number" min="10" max="1000" id="gpsMaxAccuracy" value="100">
                </div>
                <div class="att-field">
                    <label>Boundary Tolerance (metres)</label>
                    <input type="number" min="0" max="200" id="gpsTolerance" value="0">
                </div>
            </div>
            <div class="att-toggle-row" style="margin-top:8px;">
                <div>
                    <div class="att-toggle-label"><i class="fa fa-exclamation-triangle"></i> Allow Mock Locations</div>
                    <div class="att-toggle-desc">Leave OFF in production — mock/fake-GPS punches are rejected by the server</div>
                </div>
                <label class="att-switch">
                    <input type="checkbox" id="gpsAllowMock">
                    <span class="att-switch-slider"></span>
                </label>
            </div>
        </div>

        <!-- Work schedule — the SINGLE source of shift timings + hours -->
        <div class="att-card">
            <div class="att-card-title"><i class="fa fa-hourglass-half"></i> Work Schedule (Default Shift)</div>
            <div class="att-grid att-grid-3">
                <div class="att-field"><label>Shift Start</label><input type="time" id="schStart" value="09:00"></div>
                <div class="att-field"><label>Shift End</label><input type="time" id="schEnd" value="18:00"></div>
                <div class="att-field"><label>Grace Period (min)</label><input type="number" min="0" max="120" id="schGrace" value="10"></div>
                <div class="att-field"><label>Full-day Hours (&ge;)</label><input type="number" min="0" max="24" step="0.5" id="schFull" value="8"></div>
                <div class="att-field"><label>Half-day Hours (&ge;)</label><input type="number" min="0" max="24" step="0.5" id="schHalf" value="4"></div>
                <div class="att-field"><label>Break (min)</label><input type="number" min="0" max="480" id="schBreak" value="60"></div>
                <div class="att-field"><label>Early-out Before</label><input type="time" id="schEarlyOut" value="17:30"></div>
                <div class="att-field"><label>Latest Check-in <span style="color:var(--att-t3)">(optional)</span></label><input type="time" id="schLatest" value=""></div>
            </div>
            <div style="font-size:12px;color:var(--att-t3);margin-top:10px">
                <b>On-time vs Late</b> uses Shift Start + Grace. <b>Half vs full day</b> is decided by hours worked at check-out (worked = check-out &minus; check-in &minus; break) — you can clock out anytime.
                Set <b>Full-day Hours to 0</b> to keep the classic on-time / late-only model; leave <b>Latest Check-in</b> blank for no hard cutoff.
            </div>
        </div>

        <!-- Rest days + work-on-off -->
        <div class="att-card">
            <div class="att-card-title"><i class="fa fa-calendar-o"></i> Rest Days &amp; Extra Work</div>
            <div class="att-field" style="margin-bottom:14px">
                <label>Weekly-offs</label>
                <div class="att-check-group" id="weeklyOffsGroup">
                    <label class="att-check-item"><input type="checkbox" value="Mon"> Mon</label>
                    <label class="att-check-item"><input type="checkbox" value="Tue"> Tue</label>
                    <label class="att-check-item"><input type="checkbox" value="Wed"> Wed</label>
                    <label class="att-check-item"><input type="checkbox" value="Thu"> Thu</label>
                    <label class="att-check-item"><input type="checkbox" value="Fri"> Fri</label>
                    <label class="att-check-item"><input type="checkbox" value="Sat"> Sat</label>
                    <label class="att-check-item checked"><input type="checkbox" value="Sun" checked> Sun</label>
                </div>
                <div style="font-size:12px;color:var(--att-t3);margin-top:8px">
                    Highlighted days are <b>weekly-offs</b> (non-working rest days). No attendance is expected &mdash; they show as <b>Weekly-off&nbsp;(O)</b> and are never marked Absent. If <b>Allow work on offs</b> is on, punching on one is recorded as <b>Extra&nbsp;(W)</b> for extra pay.
                </div>
            </div>
            <div class="att-toggle-row">
                <div>
                    <div class="att-toggle-label"><i class="fa fa-ban"></i> No vacant days (auto-absent)</div>
                    <div class="att-toggle-desc">Unmarked working days close as Absent; offs show as weekly-off / holiday</div>
                </div>
                <label class="att-switch"><input type="checkbox" id="autoAbsent" checked><span class="att-switch-slider"></span></label>
            </div>
            <div class="att-toggle-row">
                <div>
                    <div class="att-toggle-label"><i class="fa fa-plus-circle"></i> Allow work on weekly-offs &amp; holidays</div>
                    <div class="att-toggle-desc">Accept punches on rest days &mdash; recorded as extra work (W) for extra pay</div>
                </div>
                <label class="att-switch"><input type="checkbox" id="allowWorkOnOff"><span class="att-switch-slider"></span></label>
            </div>
        </div>

        <button class="att-btn att-btn-primary" id="btnSaveGps">
            <i class="fa fa-check"></i> Save GPS Policy
        </button>
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
    /* TAB 2: Holiday Management (READ-ONLY — Option D)      */
    /* Holidays are authored in the Academic Calendar only.  */
    /* ═════════════════════════════════════════════════════ */

    function loadHolidays() {
        getData('attendance/get_holidays').then(function(r){
            if (!r) return;
            // Summary
            var setTxt = function(id, val){ var el = document.getElementById(id); if (el) el.textContent = val; };
            setTxt('holSession',  r.session || '—');
            setTxt('holTotal',    (r.total != null ? r.total : '—'));
            setTxt('holUpcoming', (r.upcoming != null ? r.upcoming : '—'));
            setTxt('holUpdated',  r.lastUpdated ? formatDate(r.lastUpdated) : 'N/A');
            if (r.canonicalSource) setTxt('holSource', r.canonicalSource);
            if (r.editorUrl) {
                var openBtn = document.getElementById('btnOpenCalendar');
                if (openBtn) openBtn.setAttribute('href', r.editorUrl);
            }
            // Read-only list
            var list = [];
            var h = r.holidays || {};
            if (typeof h === 'object' && !Array.isArray(h)) {
                Object.keys(h).forEach(function(date){ list.push({ date: date, name: h[date] }); });
            } else if (Array.isArray(h)) {
                list = h;
            }
            list.sort(function(a,b){ return (a.date || '').localeCompare(b.date || ''); });

            var tbody = document.getElementById('holidayTableBody');
            if (!list.length) {
                tbody.innerHTML = '<tr><td colspan="3"><div class="att-empty"><i class="fa fa-calendar-o"></i>No holidays defined. Add them in the Academic Calendar.</div></td></tr>';
                return;
            }
            var html = '';
            list.forEach(function(x, i){
                html += '<tr><td>' + (i + 1) + '</td><td>' + formatDate(x.date) + '</td><td>' + esc(x.name) + '</td></tr>';
            });
            tbody.innerHTML = html;
        }).catch(function(){ /* read-only: leave defaults */ });
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

<script>
/* ═══════════════════════════════════════════════════════════ */
/* GPS Attendance tab — Leaflet campus picker + Firestore policy */
/* Isolated IIFE: does NOT touch the legacy settings closure.    */
/* ═══════════════════════════════════════════════════════════ */
(function(){
    'use strict';
    var BASE = '<?= base_url() ?>';
    var CSRF_NAME = '<?= $this->security->get_csrf_token_name() ?>';
    var CSRF_HASH = '<?= $this->security->get_csrf_hash() ?>';

    var DEFAULT_CENTER = [22.9734, 78.6569]; // India centroid fallback
    var map = null, marker = null, circle = null, mapReady = false;

    function $(id){ return document.getElementById(id); }
    function esc(s){ var d=document.createElement('div'); d.appendChild(document.createTextNode(String(s==null?'':s))); return d.innerHTML; }

    function gpsAlert(msg, type){
        var el = $('attAlert');
        el.className = 'att-alert show att-alert-' + (type || 'success');
        el.innerHTML = '<i class="fa fa-' + (type==='error'?'exclamation-circle':type==='info'?'info-circle':'check-circle') + '"></i> ' + esc(msg);
        clearTimeout(el._t); el._t = setTimeout(function(){ el.classList.remove('show'); }, 4000);
    }

    function postData(url, data){
        var fd = new FormData();
        fd.append(CSRF_NAME, CSRF_HASH);
        if (data) Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
        return fetch(BASE + url, { method:'POST', body:fd, credentials:'same-origin' })
            .then(function(r){
                var ct = r.headers.get('content-type') || '';
                if (ct.indexOf('application/json') === -1) throw new Error('Non-JSON response (' + r.status + ')');
                return r.json();
            }).then(function(j){ if (j.csrf_token) CSRF_HASH = j.csrf_token; return j; });
    }
    function getData(url){
        return fetch(BASE + url, { credentials:'same-origin' })
            .then(function(r){
                var ct = r.headers.get('content-type') || '';
                if (ct.indexOf('application/json') === -1) throw new Error('Non-JSON response (' + r.status + ')');
                return r.json();
            });
    }

    function curLatLng(){
        var lat = parseFloat($('gpsLat').value), lng = parseFloat($('gpsLng').value);
        if (isNaN(lat) || isNaN(lng)) return null;
        return [lat, lng];
    }
    function curRadius(){ var r = parseInt($('gpsRadius').value, 10); return (isNaN(r) || r <= 0) ? 200 : r; }

    function syncMarker(latlng){
        if (!mapReady) return;
        if (!marker){
            marker = L.marker(latlng, { draggable:true }).addTo(map);
            marker.on('dragend', function(){
                var p = marker.getLatLng();
                $('gpsLat').value = p.lat.toFixed(6);
                $('gpsLng').value = p.lng.toFixed(6);
                if (circle) circle.setLatLng(p);
            });
        } else { marker.setLatLng(latlng); }
        if (!circle){ circle = L.circle(latlng, { radius: curRadius(), color:'#2563eb', fillColor:'#2563eb', fillOpacity:0.12 }).addTo(map); }
        else { circle.setLatLng(latlng); circle.setRadius(curRadius()); }
    }

    function initMap(){
        if (mapReady) return;
        var c = curLatLng();
        map = L.map('gpsMap').setView(c || DEFAULT_CENTER, c ? 16 : 5);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);
        map.on('click', function(e){
            $('gpsLat').value = e.latlng.lat.toFixed(6);
            $('gpsLng').value = e.latlng.lng.toFixed(6);
            syncMarker(e.latlng);
        });
        mapReady = true;
        if (c) syncMarker(c);
        setTimeout(function(){ map.invalidateSize(); }, 60);
    }

    // Lazy-init the map the first time the GPS tab is opened (container must be visible).
    var gpsTab = document.querySelector('.att-tab[data-tab="gps"]');
    if (gpsTab) gpsTab.addEventListener('click', function(){
        if (!mapReady) initMap(); else setTimeout(function(){ map.invalidateSize(); }, 60);
    });

    // "Use my current location" — browser Geolocation (works on https:// and http://localhost).
    var useLocBtn = $('gpsUseMyLoc');
    if (useLocBtn) useLocBtn.addEventListener('click', function(){
        if (!navigator.geolocation){ gpsAlert('Geolocation is not supported by this browser.', 'error'); return; }
        var orig = useLocBtn.innerHTML;
        useLocBtn.disabled = true;
        useLocBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Locating…';
        navigator.geolocation.getCurrentPosition(function(pos){
            var lat = pos.coords.latitude, lng = pos.coords.longitude;
            $('gpsLat').value = lat.toFixed(6);
            $('gpsLng').value = lng.toFixed(6);
            if (!mapReady) initMap();
            var ll = [lat, lng];
            map.setView(ll, 17);
            syncMarker(ll);
            setTimeout(function(){ map.invalidateSize(); }, 60);
            gpsAlert('Location captured (±' + Math.round(pos.coords.accuracy) + ' m). Review the pin, set the radius, then Save GPS Policy.', 'success');
            useLocBtn.disabled = false; useLocBtn.innerHTML = orig;
        }, function(err){
            var m = err.code === 1 ? 'Permission denied — allow location access for this site in the browser.' :
                    err.code === 2 ? 'Position unavailable. Check that location services are on.' :
                    err.code === 3 ? 'Timed out while getting your location. Try again.' :
                    'Could not get your location.';
            gpsAlert(m, 'error');
            useLocBtn.disabled = false; useLocBtn.innerHTML = orig;
        }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 });
    });

    // Keep the circle in sync when lat/lng/radius are typed manually.
    ['gpsLat','gpsLng'].forEach(function(id){ $(id).addEventListener('change', function(){ var c=curLatLng(); if(c) syncMarker(c); }); });
    $('gpsRadius').addEventListener('change', function(){ if (circle) circle.setRadius(curRadius()); });

    function loadGpsPolicy(){
        getData('attendance/get_attendance_policy').then(function(r){
            if (!r || !r.status) return;
            var p = r.policy || {};
            var gps = p.gps || {}, geo = gps.geofence || {};
            var win = (p.shifts && p.shifts.default && p.shifts.default.windows) ? p.shifts.default.windows : {};

            $('gpsEnabled').checked = (geo.active === true);
            if (geo.centerLat) $('gpsLat').value = geo.centerLat;
            if (geo.centerLng) $('gpsLng').value = geo.centerLng;
            if (geo.radius) $('gpsRadius').value = geo.radius;
            if (gps.maxAccuracyMeters) $('gpsMaxAccuracy').value = gps.maxAccuracyMeters;
            $('gpsTolerance').value = gps.boundaryToleranceMeters || 0;
            $('gpsAllowMock').checked = (gps.allowMockLocation === true);

            var sch = (p.shifts && p.shifts.default && p.shifts.default.schedule) ? p.shifts.default.schedule : {};
            if (sch.shiftStart)     $('schStart').value = sch.shiftStart;
            else if (win.lateThreshold) $('schStart').value = win.lateThreshold;        // legacy fallback
            if (sch.shiftEnd)       $('schEnd').value = sch.shiftEnd;
            if (typeof sch.graceMinutes !== 'undefined') $('schGrace').value = sch.graceMinutes;
            else if (typeof win.gracePeriodMin !== 'undefined') $('schGrace').value = win.gracePeriodMin;
            if (typeof sch.breakMinutes !== 'undefined') $('schBreak').value = sch.breakMinutes;
            if (typeof sch.fullDayHours !== 'undefined') $('schFull').value = sch.fullDayHours;
            if (typeof sch.halfDayHours !== 'undefined') $('schHalf').value = sch.halfDayHours;
            if (sch.earlyOutBefore) $('schEarlyOut').value = sch.earlyOutBefore;
            $('schLatest').value = (sch.latestCheckIn && sch.latestCheckIn !== '23:59') ? sch.latestCheckIn : '';

            var offs = p.weeklyOffs || [];
            document.querySelectorAll('#weeklyOffsGroup .att-check-item').forEach(function(item){
                var cb = item.querySelector('input');
                cb.checked = offs.indexOf(cb.value) !== -1;
                item.classList.toggle('checked', cb.checked);
            });
            $('allowWorkOnOff').checked = (p.allowWorkOnOff === true);
            $('autoAbsent').checked = (p.autoAbsent !== false);
        }).catch(function(){ /* first-time / no policy — keep defaults */ });
    }

    $('btnSaveGps').addEventListener('click', function(){
        var enabled = $('gpsEnabled').checked;
        var lat = parseFloat($('gpsLat').value), lng = parseFloat($('gpsLng').value);
        if (enabled && (isNaN(lat) || isNaN(lng))){
            gpsAlert('Set the campus location on the map before enabling GPS attendance.', 'error');
            return;
        }
        var policy = {
            gps: {
                enabled: enabled,
                geofence: { active: enabled, centerLat: isNaN(lat)?null:lat, centerLng: isNaN(lng)?null:lng, radius: curRadius() },
                maxAccuracyMeters: parseInt($('gpsMaxAccuracy').value,10) || 100,
                allowMockLocation: $('gpsAllowMock').checked,
                boundaryToleranceMeters: parseInt($('gpsTolerance').value,10) || 0
            },
            schedule: {
                shiftStart:     $('schStart').value,
                shiftEnd:       $('schEnd').value,
                graceMinutes:   parseInt($('schGrace').value,10) || 0,
                fullDayHours:   parseFloat($('schFull').value) || 0,
                halfDayHours:   parseFloat($('schHalf').value) || 0,
                breakMinutes:   parseInt($('schBreak').value,10) || 0,
                earlyOutBefore: $('schEarlyOut').value,
                latestCheckIn:  $('schLatest').value
            },
            weeklyOffs: (function(){ var a=[]; document.querySelectorAll('#weeklyOffsGroup input:checked').forEach(function(cb){ a.push(cb.value); }); return a; })(),
            allowWorkOnOff: $('allowWorkOnOff').checked,
            autoAbsent: $('autoAbsent').checked
        };
        var btn = this; btn.disabled = true; btn.innerHTML = '<span class="att-spinner"></span> Saving...';
        postData('attendance/save_attendance_policy', { policy: JSON.stringify(policy) }).then(function(r){
            btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Save GPS Policy';
            if (r.status === 'success' || r.status === true) gpsAlert('GPS attendance policy saved', 'success');
            else gpsAlert(r.message || 'Failed to save GPS policy', 'error');
        }).catch(function(){
            btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Save GPS Policy';
            gpsAlert('Network error while saving GPS policy', 'error');
        });
    });

    // Day chips (weekly-offs + working days) — toggle on click. The <input> is
    // display:none, so we drive the toggle ourselves off the click and manually
    // flip both the checkbox and the .checked class. preventDefault stops the
    // wrapping <label> from ALSO toggling the checkbox (which would net to nothing).
    ['weeklyOffsGroup','workingDaysGroup'].forEach(function(id){
        var wg = document.getElementById(id);
        if (!wg) return;
        function sync(item){ item.classList.toggle('checked', item.querySelector('input').checked); }
        wg.addEventListener('click', function(ev){
            var item = ev.target.closest ? ev.target.closest('.att-check-item') : null;
            if (!item || !wg.contains(item)) return;
            ev.preventDefault();
            var cb = item.querySelector('input');
            cb.checked = !cb.checked;
            sync(item);
        });
        wg.querySelectorAll('.att-check-item').forEach(sync);
    });

    loadGpsPolicy();
})();
</script>
