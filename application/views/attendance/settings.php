<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<?php
// ── Level gating ─────────────────────────────────────────────────────
// EVERY write on this page (save settings/policy, device register/update/
// delete/regenerate-key) is a MANAGE action. Without manage the whole page
// renders read-only: inputs + buttons disabled, a banner is shown, and saves
// are blocked client-side (the controller remains the authoritative gate).
$att_can_edit   = function_exists('has_permission') ? has_permission('Attendance', 'edit')   : true;
$att_can_manage = function_exists('has_permission') ? has_permission('Attendance', 'manage') : true;
?>

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

/* Top save progress bar — a thin determinate-feel bar so saving reads as a real
   in-progress action (not just a button spinner). */
.att-savebar { position: fixed; top: 0; left: 0; height: 3px; width: 0;
    background: linear-gradient(90deg, var(--att-blue,#2563eb), #4f9dff);
    z-index: 10050; opacity: 0; transition: width .25s ease, opacity .3s ease;
    box-shadow: 0 0 8px rgba(37,99,235,.5); pointer-events: none; }
.att-savebar.run { opacity: 1; }

/* Dim + lock the geofence inputs when GPS attendance is switched OFF — makes it
   obvious the campus pin/radius only apply when GPS is enabled. */
.att-geo-fields.is-off { opacity: .45; filter: grayscale(.3); pointer-events: none; user-select: none; }

/* Inline validation hint under a field. */
.att-hint { font-size: 11px; color: var(--att-t3); margin-top: 4px; }
.att-field.has-err input { border-color: var(--att-red,#c0392b) !important; box-shadow: 0 0 0 2px rgba(192,57,43,.15); }
.att-field .att-err { display: none; font-size: 11px; color: var(--att-red,#c0392b); margin-top: 4px; font-weight: 600; }
.att-field.has-err .att-err { display: block; }

/* Save button transient success state. */
.att-btn.is-saved { background: var(--att-green,#15803d) !important; border-color: var(--att-green,#15803d) !important; }

/* ── Leaflet map fixes ──
   The admin theme applies a global `img { max-width: 100% }`, which squishes
   Leaflet's 256px tile images and leaves the map BLANK. Force tiles/markers to
   their natural size and give the panes a sane stacking context. */
#gpsMap .leaflet-tile,
#gpsMap img.leaflet-tile,
#gpsMap .leaflet-container img,
#gpsMap .leaflet-marker-icon,
#gpsMap .leaflet-marker-shadow { max-width: none !important; max-height: none !important; }
#gpsMap .leaflet-pane,
#gpsMap .leaflet-top,
#gpsMap .leaflet-bottom { z-index: 400; }
#gpsMap .leaflet-control-attribution { font-size: 10px; }

/* ── Shimmer skeleton (shown while the policy loads) ── */
.gps-sk { position: relative; overflow: hidden; background: var(--att-bg3,#eef1f4); border-radius: 8px; }
.gps-sk::after { content: ''; position: absolute; inset: 0; transform: translateX(-100%);
    background: linear-gradient(90deg, transparent, rgba(255,255,255,.55), transparent); animation: gps-shimmer 1.3s infinite; }
@keyframes gps-shimmer { 100% { transform: translateX(100%); } }
@media (prefers-reduced-motion: reduce){ .gps-sk::after { animation: none; } }
.gps-sk-map { height: 320px; margin-bottom: 14px; }
.gps-sk-row { height: 66px; margin-bottom: 10px; }
.gps-sk-btn { height: 34px; width: 150px; }

/* ── Multi-campus manager ── */
.gps-campus-list { display: flex; flex-direction: column; gap: 10px; margin: 14px 0 12px; }
.gps-campus { border: 1px solid var(--att-border); border-radius: 10px; padding: 12px 14px;
    background: var(--att-bg2,#fff); transition: border-color .15s, box-shadow .15s; }
.gps-campus.sel { border-color: var(--att-blue,#2563eb); box-shadow: 0 0 0 2px rgba(37,99,235,.15); }
.gps-campus.is-off { opacity: .62; }
.gps-campus-head { display: flex; align-items: center; gap: 10px; }
.gps-dot { width: 12px; height: 12px; border-radius: 50%; flex: 0 0 auto; box-shadow: 0 0 0 2px rgba(0,0,0,.06); }
.gps-c-name { flex: 1; min-width: 0; padding: 7px 10px; border: 1px solid var(--att-border); border-radius: 7px;
    font-size: 13px; font-weight: 600; background: var(--att-bg2,#fff); color: var(--att-t1); }
.gps-c-name:focus { border-color: var(--att-blue,#2563eb); outline: none; }
.gps-c-coord { font-size: 11px; color: var(--att-t3); font-family: var(--att-font-m,monospace); }
.gps-campus-body { display: grid; grid-template-columns: 1fr 1fr 1fr auto auto; gap: 10px; align-items: end; margin-top: 11px; }
.gps-campus-body .att-field { margin: 0; }
.gps-campus-body .att-field.has-err input { border-color: var(--att-red,#c0392b) !important; box-shadow: 0 0 0 2px rgba(192,57,43,.15); }
.gps-c-remove { border: 0; background: none; color: var(--att-t3); font-size: 20px; line-height: 1; cursor: pointer; padding: 2px 8px; border-radius: 6px; }
.gps-c-remove:hover { color: var(--att-red,#c0392b); background: rgba(192,57,43,.08); }
.gps-campus-empty { text-align: center; padding: 22px; color: var(--att-t3); font-size: 12.5px; border: 1.5px dashed var(--att-border); border-radius: 10px; }
@media (max-width: 767px){ .gps-campus-body { grid-template-columns: 1fr 1fr; } }

/* Read-only (no manage permission). Buttons/inputs are disabled via the footer
   MutationObserver; these belt-and-suspenders rules stop JS-driven toggles
   (day chips, switches, campus remove) that a disabled attr alone can't. */
.att-ro .att-check-group,
.att-ro .att-switch,
.att-ro .gps-c-remove,
.att-ro .att-key-copy-btn { pointer-events: none; }
.att-ro-banner { margin-bottom: 16px; }
</style>

<div class="content-wrapper">
<section class="content">
<div class="container-fluid">
<div class="att-wrap<?= $att_can_manage ? '' : ' att-ro' ?>">

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

    <!-- Top save progress bar (GPS tab save + others) -->
    <div id="attSaveBar" class="att-savebar"></div>

    <!-- Top progress bar (shared ADS primitive) — lit while ANY request on this
         page is in flight, driven by a busy counter inside the request helpers. -->
    <div class="att-loadbar" id="attLoadbar"></div>

    <!-- ── Global Alert ──────────────────────────────────── -->
    <div id="attAlert" class="att-alert"></div>

    <?php if (!$att_can_manage): ?>
    <!-- Read-only banner — manage permission required to change any setting -->
    <div class="att-alert att-alert-warn show att-ro-banner" role="status">
        <i class="fa fa-lock"></i> View-only — attendance settings can't be changed. Your role doesn't have manage access for Attendance.
    </div>
    <?php endif; ?>

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
            <div style="font-size:12px;color:var(--att-t3);margin:-4px 0 12px;">A check-in after these times is flagged <b>Late</b>. Applies to daily class &amp; staff attendance.</div>
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
            <div style="font-size:12px;color:var(--att-t3);margin:-4px 0 12px;">Days attendance is expected. Highlighted days are working; the rest are treated as non-working.</div>
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

        <!-- Enable + campus geofences (multi-campus) -->
        <div class="att-card">
            <div class="att-card-title"><i class="fa fa-map-marker"></i> Campus Geofences</div>

            <div class="att-toggle-row">
                <div>
                    <div class="att-toggle-label"><i class="fa fa-location-arrow"></i> Enable GPS Attendance</div>
                    <div class="att-toggle-desc">Allow staff to check in/out from the app when physically inside <b>any active campus</b> below</div>
                </div>
                <label class="att-switch">
                    <input type="checkbox" id="gpsEnabled" <?= !empty($gps_enabled) ? 'checked' : '' ?>>
                    <span class="att-switch-slider"></span>
                </label>
            </div>

            <!-- Shimmer skeleton — shown until the saved policy loads -->
            <div id="gpsShimmer" style="margin-top:14px;">
                <div class="gps-sk gps-sk-map"></div>
                <div class="gps-sk gps-sk-row"></div>
                <div class="gps-sk gps-sk-row"></div>
                <div class="gps-sk gps-sk-btn"></div>
            </div>

            <!-- Real content (revealed once loaded) -->
            <div id="gpsContent" class="att-geo-fields" style="display:none;">
                <div style="margin:14px 0 10px;font-size:11.5px;color:var(--att-t3);">
                    Add each campus where staff may clock in. Select a campus, then click the map to drop its pin (or drag the marker); each campus has its own radius. A punch is accepted inside <b>any active</b> campus.
                </div>
                <div id="gpsMap" style="height:340px;border-radius:8px;border:1px solid var(--att-border);background:#e9eef2;"></div>

                <div class="gps-campus-list" id="gpsCampusList"><!-- rendered by JS --></div>
                <button type="button" class="att-btn att-btn-ghost att-btn-sm" id="gpsAddCampus">
                    <i class="fa fa-plus"></i> Add campus
                </button>
            </div>
        </div>

        <!-- Accuracy & anti-spoof -->
        <div class="att-card">
            <div class="att-card-title"><i class="fa fa-shield"></i> Accuracy &amp; Anti-Spoof</div>
            <div class="att-grid att-grid-2">
                <div class="att-field">
                    <label for="gpsMaxAccuracy">Max GPS Accuracy (metres)</label>
                    <input type="number" min="10" max="1000" id="gpsMaxAccuracy" value="100">
                    <span class="att-err">Must be 10–1000 m.</span>
                    <div class="att-hint">Reject a punch if the phone's GPS is fuzzier than this (10–1000 m).</div>
                </div>
                <div class="att-field">
                    <label for="gpsTolerance">Boundary Tolerance (metres)</label>
                    <input type="number" min="0" max="200" id="gpsTolerance" value="0">
                    <span class="att-err">Must be 0–200 m.</span>
                    <div class="att-hint">Extra slack added outside the radius (0–200 m). Leave 0 for a strict fence.</div>
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
                <div class="att-field"><label>Check-in Opens At <span style="color:var(--att-t3)">(optional)</span></label><input type="time" id="schEarliest" value="07:00"></div>
                <div class="att-field"><label>Latest Check-in <span style="color:var(--att-t3)">(optional)</span></label><input type="time" id="schLatest" value=""></div>
            </div>
            <div style="font-size:12px;color:var(--att-t3);margin-top:10px">
                <b>On-time vs Late</b> uses Shift Start + Grace. <b>Half vs full day</b> is decided by hours worked at check-out (worked = check-out &minus; check-in &minus; break) — you can clock out anytime.
                <b>Check-in Opens At</b> blocks any check-in before that time (staff can't punch at odd hours) — leave blank for no opening restriction.
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
            <div style="display:flex;gap:9px;align-items:flex-start;margin:2px 2px 6px;padding:10px 12px;border-radius:9px;background:var(--att-amber-bg);border:1px solid var(--att-amber);color:var(--att-t2);font-size:12.5px;line-height:1.5;">
                <i class="fa fa-info-circle" style="color:var(--att-amber);margin-top:2px;flex-shrink:0;"></i>
                <span>For a day to close as <b>Holiday&nbsp;(H)</b> instead of Absent, it must be added in the <b>Academic Calendar</b> as a Holiday &mdash; that's the single source of truth this setting reads. Weekly-offs above are handled here; festival/one-off holidays are not.
                    <a href="<?= base_url('academic') ?>#calendar" style="color:var(--gold,#BC5A3C);font-weight:600;text-decoration:none;white-space:nowrap;">Open Academic Calendar &rarr;</a>
                </span>
            </div>
            <div class="att-toggle-row">
                <div>
                    <div class="att-toggle-label"><i class="fa fa-plus-circle"></i> Allow work on weekly-offs &amp; holidays</div>
                    <div class="att-toggle-desc">Accept punches on rest days &mdash; recorded as extra work (W) for extra pay</div>
                </div>
                <label class="att-switch"><input type="checkbox" id="allowWorkOnOff"><span class="att-switch-slider"></span></label>
            </div>
        </div>

        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-top:4px;">
            <button class="att-btn att-btn-primary" id="btnSaveGps">
                <i class="fa fa-check"></i> Save Attendance Policy
            </button>
            <span style="font-size:11.5px;color:var(--att-t3);">Saves every section on this tab — geofence, accuracy, work schedule and rest days.</span>
        </div>
    </div>

</div><!-- .att-wrap -->
</div><!-- .container-fluid -->
</section>
</div><!-- .content-wrapper -->

<script>
/* Shared top-progress busy counter — global so BOTH the legacy settings closure
   and the isolated GPS closure below drive the SAME #attLoadbar. The bar stays
   lit while any request is in flight and clears when the last one settles. */
window._attBusy = window._attBusy || 0;
window.busyStart = function(){ window._attBusy++; var b = document.getElementById('attLoadbar'); if (b) b.classList.add('on'); };
window.busyEnd   = function(){ window._attBusy = Math.max(0, window._attBusy - 1); if (!window._attBusy){ var b = document.getElementById('attLoadbar'); if (b) b.classList.remove('on'); } };
</script>

<script>
(function(){
    'use strict';

    var BASE = '<?= base_url() ?>';
    var CSRF_NAME = '<?= $this->security->get_csrf_token_name() ?>';
    var CSRF_HASH = '<?= $this->security->get_csrf_hash() ?>';
    var CAN_MANAGE = <?= $att_can_manage ? 'true' : 'false' ?>;

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

    // Container loading state: overlay a data table's wrap (att-rel) while it
    // (re)loads, then clear on render/error — no blank flash, no stale rows.
    function overlayOn(wrap){
        if (!wrap || wrap.querySelector('.att-loading-overlay')) return;
        wrap.classList.add('att-rel');
        var o = document.createElement('div');
        o.className = 'att-loading-overlay';
        o.innerHTML = '<span class="att-spin"></span> Loading…';
        wrap.appendChild(o);
    }
    function overlayOff(wrap){ if (wrap){ var o = wrap.querySelector('.att-loading-overlay'); if (o) o.remove(); } }

    // Fail-closed: fetch does NOT reject on 403/401/500, so a denied or failed
    // request would otherwise look "successful". Reject unless the HTTP status
    // is ok AND the payload isn't an explicit error — callers surface e.message.
    function postData(url, data) {
        var fd = new FormData();
        fd.append(CSRF_NAME, CSRF_HASH);
        if (data) {
            Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
        }
        busyStart();
        return fetch(BASE + url, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r){
                return r.json().catch(function(){ return {}; }).then(function(j){
                    if (j && j.csrf_hash)  CSRF_HASH = j.csrf_hash;
                    if (j && j.csrf_token) CSRF_HASH = j.csrf_token;
                    if (!r.ok || (j && (j.status === 'error' || j.success === false))) {
                        throw new Error((j && (j.message || j.error)) || ('Request failed (' + r.status + ')'));
                    }
                    return j;
                });
            }).finally(busyEnd);
    }

    function getData(url) {
        busyStart();
        return fetch(BASE + url, { credentials: 'same-origin' })
            .then(function(r){
                return r.json().catch(function(){ return {}; }).then(function(j){
                    if (j && j.csrf_hash) CSRF_HASH = j.csrf_hash;
                    if (!r.ok || (j && (j.status === 'error' || j.success === false))) {
                        throw new Error((j && (j.message || j.error)) || ('Request failed (' + r.status + ')'));
                    }
                    return j;
                });
            }).finally(busyEnd);
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
        if (!CAN_MANAGE) { showAlert("View-only — you don't have manage access for Attendance.", 'error'); return; }
        if (!settingsLoaded) {
            showAlert('Settings not loaded yet. Please wait or refresh the page.', 'error');
            return;
        }
        var btn = this;
        btn.classList.add('is-loading');

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
            if (r.status) showAlert('Settings saved successfully', 'success');
            else showAlert(r.message || 'Failed to save settings', 'error');
        }).catch(function(e){
            showAlert((e && e.message) || 'Could not save settings', 'error');
        }).finally(function(){ btn.classList.remove('is-loading'); });
    });

    /* ═════════════════════════════════════════════════════ */
    /* TAB 2: Holiday Management (READ-ONLY — Option D)      */
    /* Holidays are authored in the Academic Calendar only.  */
    /* ═════════════════════════════════════════════════════ */

    function loadHolidays() {
        var wrap = document.getElementById('holidayTableBody');
        wrap = wrap ? wrap.closest('.att-table-wrap') : null;
        overlayOn(wrap);
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
        }).catch(function(){ /* read-only: leave defaults */ })
        .finally(function(){ overlayOff(wrap); });
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
        var dis = CAN_MANAGE ? '' : ' disabled';
        devices.forEach(function(d){
            html += '<tr>'
                + '<td>' + esc(d.name) + '</td>'
                + '<td><span class="att-tag att-tag-blue">' + esc(typeLabel(d.type)) + '</span></td>'
                + '<td>' + esc(d.location || '-') + '</td>'
                + '<td>' + statusTag(d.status) + '</td>'
                + '<td>' + (d.last_ping ? formatDate(d.last_ping) : '<span style="color:var(--att-t3)">Never</span>') + '</td>'
                + '<td style="text-align:right; white-space:nowrap;">'
                + '<button class="att-btn att-btn-ghost att-btn-sm" data-edit-device="' + esc(d.id) + '" title="Edit"' + dis + '><i class="fa fa-pencil"></i></button> '
                + '<button class="att-btn att-btn-ghost att-btn-sm" data-regen-device="' + esc(d.id) + '" title="Regenerate Key"' + dis + '><i class="fa fa-key"></i></button> '
                + '<button class="att-btn att-btn-danger att-btn-sm" data-delete-device="' + esc(d.id) + '" title="Delete"' + dis + '><i class="fa fa-trash"></i></button>'
                + '</td></tr>';
        });
        tbody.innerHTML = html;

        tbody.querySelectorAll('[data-edit-device]').forEach(function(btn){
            btn.addEventListener('click', function(){ editDevice(this.getAttribute('data-edit-device'), this); });
        });
        tbody.querySelectorAll('[data-regen-device]').forEach(function(btn){
            btn.addEventListener('click', function(){ regenDeviceKey(this.getAttribute('data-regen-device'), this); });
        });
        tbody.querySelectorAll('[data-delete-device]').forEach(function(btn){
            btn.addEventListener('click', function(){ deleteDevice(this.getAttribute('data-delete-device'), this); });
        });
    }

    function loadDevices() {
        var wrap = document.getElementById('deviceTableBody');
        wrap = wrap ? wrap.closest('.att-table-wrap') : null;
        overlayOn(wrap);
        getData('attendance/fetch_devices').then(function(r){
            if (r && r.status && r.devices) {
                devices = Array.isArray(r.devices) ? r.devices : Object.values(r.devices);
            } else {
                devices = [];
            }
            renderDevices();
        }).catch(function(){ devices = []; renderDevices(); })
        .finally(function(){ overlayOff(wrap); });
    }

    document.getElementById('btnRegisterDevice').addEventListener('click', function(){
        if (!CAN_MANAGE) { showAlert("View-only — you don't have manage access for Attendance.", 'error'); return; }
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
        btn.classList.add('is-loading');

        postData('attendance/register_device', {
            name: name,
            type: type,
            location: location
        }).then(function(r){
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
        }).catch(function(e){
            showAlert((e && e.message) || 'Could not register device', 'error');
        }).finally(function(){ btn.classList.remove('is-loading'); });
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

    function editDevice(id, btn) {
        var dev = devices.find(function(d){ return d.id === id; });
        if (!dev) return;

        var newName = prompt('Device Name:', dev.name);
        if (newName === null) return;
        newName = newName.trim();
        if (!newName) { showAlert('Name cannot be empty', 'error'); return; }

        var newLoc = prompt('Location:', dev.location || '');
        if (newLoc === null) return;
        newLoc = newLoc.trim();

        if (btn) btn.classList.add('is-loading');
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
        }).catch(function(e){ showAlert((e && e.message) || 'Could not update device', 'error'); })
        .finally(function(){ if (btn) btn.classList.remove('is-loading'); });
    }

    function regenDeviceKey(id, btn) {
        if (!confirm('Regenerate API key for this device? The old key will stop working.')) return;

        if (btn) btn.classList.add('is-loading');
        postData('attendance/regenerate_key', { device_id: id })
        .then(function(r){
            if (r.status && r.api_key) {
                document.getElementById('deviceKeyVal').textContent = r.api_key;
                document.getElementById('deviceKeyBox').classList.add('show');
                showAlert('New API key generated', 'info');
            } else {
                showAlert(r.message || 'Failed to regenerate key', 'error');
            }
        }).catch(function(e){ showAlert((e && e.message) || 'Could not regenerate key', 'error'); })
        .finally(function(){ if (btn) btn.classList.remove('is-loading'); });
    }

    function deleteDevice(id, btn) {
        if (!confirm('Delete this device permanently?')) return;

        if (btn) btn.classList.add('is-loading');
        postData('attendance/delete_device', { device_id: id })
        .then(function(r){
            if (r.status) {
                showAlert('Device deleted', 'success');
                loadDevices();
            } else {
                showAlert(r.message || 'Delete failed', 'error');
            }
        }).catch(function(e){ showAlert((e && e.message) || 'Could not delete device', 'error'); })
        .finally(function(){ if (btn) btn.classList.remove('is-loading'); });
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
    var CAN_MANAGE = <?= $att_can_manage ? 'true' : 'false' ?>;

    // SSR bootstrap — the policy rendered by the controller. Applied synchronously
    // so the toggle + all fields show their real state on first paint (no fetch-
    // then-flip). JSON_HEX flags below prevent a campus name breaking the tag.
    var BOOTSTRAP = <?= (isset($attendance_policy) && is_array($attendance_policy) && !empty($attendance_policy)) ? json_encode($attendance_policy, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : 'null' ?>;

    var DEFAULT_CENTER = [22.9734, 78.6569]; // India centroid fallback
    var map = null, mapReady = false, gpsLoaded = false;

    function $(id){ return document.getElementById(id); }
    function esc(s){ var d=document.createElement('div'); d.appendChild(document.createTextNode(String(s==null?'':s))); return d.innerHTML; }

    // Fixed, always-visible toast. The in-flow #attAlert sits at the TOP of a
    // long page, so its confirmation fired off-screen whenever Save (at the
    // bottom) was clicked. This bottom-right toast is visible from anywhere.
    var _toastEl = null, _toastT = 0;
    function gpsAlert(msg, type){
        type = type || 'success';
        if (!_toastEl){
            _toastEl = document.createElement('div');
            _toastEl.className = 'att-toast';
            _toastEl.setAttribute('role', 'status');
            _toastEl.setAttribute('aria-live', 'polite');
            document.body.appendChild(_toastEl);
        }
        _toastEl.className = 'att-toast ' + (type === 'error' ? 'error' : type === 'info' ? 'info' : 'success');
        _toastEl.innerHTML = '<i class="fa fa-' + (type==='error'?'exclamation-circle':type==='info'?'info-circle':'check-circle') + '" style="margin-right:7px;"></i>' + esc(msg);
        void _toastEl.offsetWidth;                       // reflow so the transition runs
        _toastEl.classList.add('show');
        clearTimeout(_toastT); _toastT = setTimeout(function(){ _toastEl.classList.remove('show'); }, 4200);
    }

    // Top progress bar for the save round-trip (real in-progress feel).
    var _saveBar = document.getElementById('attSaveBar');
    function saveBar(state){
        if (!_saveBar) return;
        if (state === 'start'){ _saveBar.style.width = '0'; _saveBar.classList.add('run'); void _saveBar.offsetWidth; _saveBar.style.width = '75%'; }
        else if (state === 'done'){ _saveBar.style.width = '100%'; setTimeout(function(){ _saveBar.classList.remove('run'); _saveBar.style.width = '0'; }, 350); }
        else { _saveBar.classList.remove('run'); _saveBar.style.width = '0'; }   // error/reset
    }

    // Inline field-error helpers.
    function setErr(id, on){ var el = $(id); if (!el) return; var f = el.closest ? el.closest('.att-field') : null; if (f) f.classList.toggle('has-err', !!on); }
    function clearErrs(){ document.querySelectorAll('#pane-gps .att-field.has-err').forEach(function(f){ f.classList.remove('has-err'); }); }

    // Fail-closed: reject on denied/failed HTTP (403/401/500) or an explicit
    // error payload — fetch itself only rejects on network failure.
    function postData(url, data){
        var fd = new FormData();
        fd.append(CSRF_NAME, CSRF_HASH);
        if (data) Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
        busyStart();
        return fetch(BASE + url, { method:'POST', body:fd, credentials:'same-origin' })
            .then(function(r){
                return r.json().catch(function(){ return {}; }).then(function(j){
                    if (j && j.csrf_token) CSRF_HASH = j.csrf_token;
                    if (j && j.csrf_hash)  CSRF_HASH = j.csrf_hash;
                    if (!r.ok || (j && (j.status === 'error' || j.success === false))) {
                        throw new Error((j && (j.message || j.error)) || ('Request failed (' + r.status + ')'));
                    }
                    return j;
                });
            }).finally(busyEnd);
    }
    function getData(url){
        busyStart();
        return fetch(BASE + url, { credentials:'same-origin' })
            .then(function(r){
                return r.json().catch(function(){ return {}; }).then(function(j){
                    if (!r.ok || (j && (j.status === 'error' || j.success === false))) {
                        throw new Error((j && (j.message || j.error)) || ('Request failed (' + r.status + ')'));
                    }
                    return j;
                });
            }).finally(busyEnd);
    }

    /* ── Multi-campus manager ────────────────────────────────────────
       State = an array of campuses; the map shows every campus (circle +
       marker), and the SELECTED campus is the one that map clicks / drags /
       "use my location" edit. A punch is valid inside ANY active campus. */
    var COLORS = ['#2563eb','#15803d','#b45309','#7c3aed','#be123c','#0891b2','#c2410c','#4d7c0f'];
    var campuses = [];       // [{id,name,centerLat,centerLng,radius,active}]
    var selectedIdx = -1;
    var layers = [];         // parallel Leaflet {marker,circle} per campus

    function campusColor(i){ return COLORS[i % COLORS.length]; }
    function hasCoord(c){ return c && c.centerLat != null && c.centerLng != null && !isNaN(c.centerLat) && !isNaN(c.centerLng); }
    function clampRad(r){ r = parseInt(r, 10); if (isNaN(r)) return 200; return Math.max(25, Math.min(2000, r)); }

    // ---- Map ----
    function initMap(){
        if (mapReady) return;
        if (typeof L === 'undefined'){ return; }        // Leaflet not loaded (see ensureMap fallback)
        map = L.map('gpsMap').setView(DEFAULT_CENTER, 5);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);
        map.on('click', function(e){
            if (selectedIdx < 0 || !campuses[selectedIdx]) return;  // no campus selected → ignore
            var c = campuses[selectedIdx];
            c.centerLat = +e.latlng.lat.toFixed(6);
            c.centerLng = +e.latlng.lng.toFixed(6);
            renderCampusList(); renderMap(false);
        });
        mapReady = true;
        renderMap(true);
    }

    // Robustly show the map whenever the GPS tab becomes visible. The map is
    // created lazily, but a Leaflet map first sized inside a hidden (display:none)
    // pane paints BLANK until invalidateSize() runs once it's visible — so we
    // defer past the pane's display switch and recompute size a few times.
    function ensureMap(){
        var el = $('gpsMap');
        if (el) el.classList.remove('gps-sk');
        if (typeof L === 'undefined'){
            if (el && !el._noleaflet){ el._noleaflet = true;
                el.innerHTML = '<div style="padding:26px;text-align:center;color:var(--att-t3);font-size:12.5px;">The map library couldn’t load. You can still set each campus by latitude/longitude below, or use “Use my location”.</div>'; }
            return;
        }
        if (!mapReady) initMap();
        // A Leaflet map first sized inside a hidden (display:none) pane paints
        // BLANK until invalidateSize() runs once it's visible — recompute a few
        // times to cover the hidden→visible switch reliably.
        if (mapReady && map){
            [0, 120, 350, 700].forEach(function(ms){ setTimeout(function(){ if (map) map.invalidateSize(); }, ms); });
        }
    }

    function renderMap(fit){
        if (!mapReady) return;
        layers.forEach(function(l){ if (l.marker) map.removeLayer(l.marker); if (l.circle) map.removeLayer(l.circle); });
        layers = [];
        var pts = [];
        campuses.forEach(function(c, i){
            if (!hasCoord(c)){ layers.push({}); return; }
            var col = campusColor(i), ll = [c.centerLat, c.centerLng], on = (i === selectedIdx);
            var circle = L.circle(ll, { radius: clampRad(c.radius), color: col, fillColor: col,
                fillOpacity: on ? 0.16 : 0.07, weight: on ? 2 : 1, opacity: c.active ? 1 : 0.5 }).addTo(map);
            var marker = L.marker(ll, { draggable: on, opacity: c.active ? 1 : 0.6, title: c.name || ('Campus ' + (i + 1)) }).addTo(map);
            marker.on('dragend', function(){ var p = marker.getLatLng(); c.centerLat = +p.lat.toFixed(6); c.centerLng = +p.lng.toFixed(6); renderCampusList(); renderMap(false); });
            marker.on('click', function(){ selectCampus(i); });
            layers.push({ marker: marker, circle: circle });
            pts.push(ll);
        });
        if (fit && pts.length){ try { map.fitBounds(pts, { padding: [40, 40], maxZoom: 16 }); } catch(e){} }
    }

    // ---- Campus list ----
    function renderCampusList(){
        var wrap = $('gpsCampusList');
        if (!wrap) return;
        if (!campuses.length){
            wrap.innerHTML = '<div class="gps-campus-empty">No campuses yet. Click <b>Add campus</b>, then drop its pin on the map or use “Use my location”.</div>';
            return;
        }
        var html = '';
        campuses.forEach(function(c, i){
            var sel = (i === selectedIdx) ? ' sel' : '', off = c.active ? '' : ' is-off';
            html += ''
              + '<div class="gps-campus' + sel + off + '" data-i="' + i + '">'
              +   '<div class="gps-campus-head">'
              +     '<span class="gps-dot" style="background:' + campusColor(i) + '"></span>'
              +     '<input class="gps-c-name" data-i="' + i + '" value="' + esc(c.name) + '" placeholder="Campus ' + (i + 1) + ' name" aria-label="Campus name">'
              +     '<label class="att-switch" title="Active"><input type="checkbox" class="gps-c-active" data-i="' + i + '"' + (c.active ? ' checked' : '') + '><span class="att-switch-slider"></span></label>'
              +     '<button type="button" class="gps-c-remove" data-i="' + i + '" title="Remove campus" aria-label="Remove campus">&times;</button>'
              +   '</div>'
              +   '<div class="gps-campus-body">'
              +     '<div class="att-field"><label>Latitude</label><input class="gps-c-lat" data-i="' + i + '" type="number" step="any" value="' + (c.centerLat == null ? '' : esc(c.centerLat)) + '"></div>'
              +     '<div class="att-field"><label>Longitude</label><input class="gps-c-lng" data-i="' + i + '" type="number" step="any" value="' + (c.centerLng == null ? '' : esc(c.centerLng)) + '"></div>'
              +     '<div class="att-field"><label>Radius (m)</label><input class="gps-c-rad" data-i="' + i + '" type="number" min="25" max="2000" value="' + esc(c.radius) + '"></div>'
              +     '<button type="button" class="att-btn att-btn-ghost att-btn-sm gps-c-useloc" data-i="' + i + '"><i class="fa fa-location-arrow"></i> Use my location</button>'
              +     '<button type="button" class="att-btn att-btn-ghost att-btn-sm gps-c-edit" data-i="' + i + '"><i class="fa fa-crosshairs"></i> Edit on map</button>'
              +   '</div>'
              + '</div>';
        });
        wrap.innerHTML = html;
    }

    function selectCampus(i){
        selectedIdx = i;
        renderCampusList(); renderMap(false);
        if (mapReady && hasCoord(campuses[i])) map.setView([campuses[i].centerLat, campuses[i].centerLng], Math.max(map.getZoom(), 16));
    }
    function addCampus(){
        campuses.push({ id: '', name: 'Campus ' + (campuses.length + 1), centerLat: null, centerLng: null, radius: 200, active: true });
        selectedIdx = campuses.length - 1;
        renderCampusList(); renderMap(false);
        $('gpsEnabled').checked = true; syncGeoEnabled();
        gpsAlert('Campus added — drop its pin on the map or use “Use my location”.', 'info');
    }
    function removeCampus(i){
        campuses.splice(i, 1);
        if (selectedIdx >= campuses.length) selectedIdx = campuses.length - 1;
        renderCampusList(); renderMap(true);
    }
    function useMyLocation(i, btn){
        if (!navigator.geolocation){ gpsAlert('Geolocation is not supported by this browser.', 'error'); return; }
        var orig = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Locating…';
        navigator.geolocation.getCurrentPosition(function(pos){
            var c = campuses[i]; if (!c){ btn.disabled = false; btn.innerHTML = orig; return; }
            c.centerLat = +pos.coords.latitude.toFixed(6);
            c.centerLng = +pos.coords.longitude.toFixed(6);
            selectedIdx = i;
            ensureMap();
            renderCampusList(); renderMap(false);
            if (mapReady) map.setView([c.centerLat, c.centerLng], 17);
            gpsAlert('Location captured (±' + Math.round(pos.coords.accuracy) + ' m). Set the radius, then Save.', 'success');
            btn.disabled = false; btn.innerHTML = orig;
        }, function(err){
            var m = err.code === 1 ? 'Permission denied — allow location access for this site.' :
                    err.code === 2 ? 'Position unavailable. Check that location services are on.' :
                    err.code === 3 ? 'Timed out getting your location. Try again.' : 'Could not get your location.';
            gpsAlert(m, 'error'); btn.disabled = false; btn.innerHTML = orig;
        }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 });
    }

    // Event delegation on the campus list.
    (function(){
        var list = $('gpsCampusList');
        if (!list) return;
        list.addEventListener('input', function(e){
            var t = e.target, i = +t.getAttribute('data-i');
            if (isNaN(i) || !campuses[i]) return;
            if (t.classList.contains('gps-c-name')) campuses[i].name = t.value;
            else if (t.classList.contains('gps-c-lat')){ campuses[i].centerLat = (t.value === '' ? null : parseFloat(t.value)); renderMap(false); }
            else if (t.classList.contains('gps-c-lng')){ campuses[i].centerLng = (t.value === '' ? null : parseFloat(t.value)); renderMap(false); }
            else if (t.classList.contains('gps-c-rad')){ campuses[i].radius = t.value; renderMap(false); }
            var f = t.closest ? t.closest('.att-field') : null; if (f) f.classList.remove('has-err');
        });
        list.addEventListener('change', function(e){
            var t = e.target, i = +t.getAttribute('data-i');
            if (isNaN(i) || !campuses[i]) return;
            if (t.classList.contains('gps-c-active')){ campuses[i].active = t.checked; renderCampusList(); renderMap(false); }
        });
        list.addEventListener('click', function(e){
            var btn = e.target.closest ? e.target.closest('button') : null;
            if (btn){
                var bi = +btn.getAttribute('data-i');
                if (btn.classList.contains('gps-c-remove')){ removeCampus(bi); return; }
                if (btn.classList.contains('gps-c-useloc')){ useMyLocation(bi, btn); return; }
                if (btn.classList.contains('gps-c-edit')){ selectCampus(bi); if (mapReady) setTimeout(function(){ map.invalidateSize(); }, 40); return; }
                return;
            }
            var card = e.target.closest ? e.target.closest('.gps-campus') : null;
            if (card && !e.target.matches('input, label, .att-switch-slider')) selectCampus(+card.getAttribute('data-i'));
        });
    })();

    if ($('gpsAddCampus')) $('gpsAddCampus').addEventListener('click', addCampus);

    // Reveal the real content once the policy has loaded (hide the shimmer),
    // then show the map if the GPS tab is currently open.
    function revealGps(){
        gpsLoaded = true;
        var sh = $('gpsShimmer'), ct = $('gpsContent');
        if (sh) sh.style.display = 'none';
        if (ct) ct.style.display = '';
        syncGeoEnabled();
        var pane = $('pane-gps');
        if (pane && pane.classList.contains('active')) setTimeout(ensureMap, 30);
    }

    // Show/refresh the map whenever the GPS tab is opened. Deferred + multi-
    // invalidate inside ensureMap() so it renders even after the hidden→visible
    // switch (this fixes the blank-map-on-first-open).
    var gpsTab = document.querySelector('.att-tab[data-tab="gps"]');
    if (gpsTab) gpsTab.addEventListener('click', function(){ setTimeout(ensureMap, 30); });

    // Master GPS toggle. The server master gate is enabledMethods (derived from
    // this toggle on save); the campus list stays editable while off so admins
    // can configure campuses first, then enable. We only lightly dim it.
    function syncGeoEnabled(){
        var on = $('gpsEnabled').checked;
        var box = $('gpsContent');
        if (box) box.style.opacity = on ? '' : '.72';
        if (mapReady && on) setTimeout(function(){ map.invalidateSize(); }, 40);
    }
    $('gpsEnabled').addEventListener('change', syncGeoEnabled);

    var loadFailed = false;

    // Apply a policy object to the form (reused on first load AND after save to
    // reflect any server-side normalisation, e.g. a clamped radius).
    function applyPolicy(p){
        p = p || {};
        var gps = p.gps || {}, geo = gps.geofence || {};
        var win = (p.shifts && p.shifts.default && p.shifts.default.windows) ? p.shifts.default.windows : {};

        // Load campuses: prefer gps.geofences[]; else wrap the legacy singular
        // geofence as one campus (only if it has real coords / is active).
        var fences = [];
        if (Array.isArray(gps.geofences)) fences = gps.geofences;
        else if (geo && (geo.active || (geo.centerLat && geo.centerLng))) fences = [geo];
        campuses = fences.map(function(f, i){
            return {
                id: String(f.id || ''),
                name: String(f.name || ''),
                centerLat: (f.centerLat === 0 || f.centerLat) ? Number(f.centerLat) : null,   // 0 is valid
                centerLng: (f.centerLng === 0 || f.centerLng) ? Number(f.centerLng) : null,
                radius: f.radius ? Number(f.radius) : 200,
                active: (f.active === true)
            };
        });
        selectedIdx = campuses.length ? 0 : -1;
        // Master toggle = GPS listed in enabledMethods (fallback to geo.active).
        var methods = Array.isArray(p.enabledMethods) ? p.enabledMethods : [];
        $('gpsEnabled').checked = (methods.indexOf('gps') !== -1) || (geo.active === true);

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
        if (sch.earliestCheckIn && sch.earliestCheckIn !== '00:00') $('schEarliest').value = sch.earliestCheckIn;
        $('schLatest').value = (sch.latestCheckIn && sch.latestCheckIn !== '23:59') ? sch.latestCheckIn : '';

        var offs = p.weeklyOffs || [];
        document.querySelectorAll('#weeklyOffsGroup .att-check-item').forEach(function(item){
            var cb = item.querySelector('input');
            cb.checked = offs.indexOf(cb.value) !== -1;
            item.classList.toggle('checked', cb.checked);
        });
        $('allowWorkOnOff').checked = (p.allowWorkOnOff === true);
        $('autoAbsent').checked = (p.autoAbsent !== false);

        renderCampusList();
        if (mapReady) renderMap(true);
        revealGps();     // hide shimmer, show content, init map if the tab is open
    }

    function loadGpsPolicy(){
        getData('attendance/get_attendance_policy').then(function(r){
            if (!r || !r.status){
                loadFailed = true;
                gpsAlert('Could not load the saved policy. Refresh before editing so you don’t overwrite it.', 'error');
                revealGps();
                return;
            }
            loadFailed = false;
            applyPolicy(r.policy || {});   // empty policy = first-time; defaults stay (revealGps inside)
        }).catch(function(){
            // Genuine failure (network / non-JSON). Do NOT silently keep defaults —
            // saving now would overwrite the real stored policy with blanks.
            loadFailed = true;
            gpsAlert('Could not load the current attendance policy (network error). Refresh the page before saving.', 'error');
            revealGps();
        });
    }

    var SAVE_LABEL = '<i class="fa fa-check"></i> Save Attendance Policy';

    // Client-side validation mirroring the server's rules, so bad values are
    // caught instantly (with the offending field highlighted) instead of after
    // a round-trip. Returns a list of human-readable problems.
    function markCampusErr(i, which){
        var el = document.querySelector('.gps-c-' + which + '[data-i="' + i + '"]');
        if (el){ var f = el.closest('.att-field'); if (f) f.classList.add('has-err'); }
    }
    function validateGps(enabled){
        clearErrs();
        var errs = [];
        var activeCount = 0;
        campuses.forEach(function(c, i){
            if (!c.active) return;
            activeCount++;
            var lat = Number(c.centerLat), lng = Number(c.centerLng), rad = parseInt(c.radius, 10);
            var bad = false;
            if (c.centerLat == null || isNaN(lat) || lat < -90 || lat > 90){ markCampusErr(i, 'lat'); bad = true; }
            if (c.centerLng == null || isNaN(lng) || lng < -180 || lng > 180){ markCampusErr(i, 'lng'); bad = true; }
            if (isNaN(rad) || rad < 25 || rad > 2000){ markCampusErr(i, 'rad'); bad = true; }
            if (bad) errs.push('campus “' + (c.name || ('#' + (i + 1))) + '” needs a valid location and a 25–2000 m radius');
        });
        if (enabled && activeCount < 1) errs.push('at least one active campus with a location set');
        var acc = parseInt($('gpsMaxAccuracy').value, 10);
        if (isNaN(acc) || acc < 10 || acc > 1000){ setErr('gpsMaxAccuracy', true); errs.push('max accuracy of 10–1000 m'); }
        var tol = parseInt($('gpsTolerance').value, 10);
        if (!isNaN(tol) && (tol < 0 || tol > 200)){ setErr('gpsTolerance', true); errs.push('tolerance of 0–200 m'); }
        var full = parseFloat($('schFull').value) || 0, half = parseFloat($('schHalf').value) || 0;
        if (full > 0 && half > full){ setErr('schFull', true); setErr('schHalf', true); errs.push('half-day hours ≤ full-day hours'); }
        return errs;
    }

    $('btnSaveGps').addEventListener('click', function(){
        var btn = this;
        if (!CAN_MANAGE){ gpsAlert("View-only — you don't have manage access for Attendance.", 'error'); return; }
        if (loadFailed){
            gpsAlert('Refresh the page first — the current policy didn’t load, and saving now could overwrite it.', 'error');
            return;
        }
        var enabled = $('gpsEnabled').checked;

        var errs = validateGps(enabled);
        if (errs.length){
            gpsAlert('Please fix: ' + errs.join(', ') + '.', 'error');
            var firstBad = document.querySelector('#pane-gps .att-field.has-err input');
            if (firstBad){ firstBad.focus(); firstBad.scrollIntoView({ behavior:'smooth', block:'center' }); }
            return;
        }

        var geofences = campuses.map(function(c){
            return {
                id: c.id || '',
                name: (c.name || '').trim(),
                active: !!c.active,
                centerLat: (c.centerLat == null || isNaN(c.centerLat)) ? null : Number(c.centerLat),
                centerLng: (c.centerLng == null || isNaN(c.centerLng)) ? null : Number(c.centerLng),
                radius: clampRad(c.radius)
            };
        });
        var policy = {
            gps: {
                enabled: enabled,
                geofences: geofences,
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
                earlyOutBefore:  $('schEarlyOut').value,
                latestCheckIn:   $('schLatest').value,
                earliestCheckIn: $('schEarliest').value
            },
            weeklyOffs: (function(){ var a=[]; document.querySelectorAll('#weeklyOffsGroup input:checked').forEach(function(cb){ a.push(cb.value); }); return a; })(),
            allowWorkOnOff: $('allowWorkOnOff').checked,
            autoAbsent: $('autoAbsent').checked
        };
        btn.disabled = true; btn.innerHTML = '<span class="att-spinner"></span> Saving…';
        saveBar('start');
        postData('attendance/save_attendance_policy', { policy: JSON.stringify(policy) }).then(function(r){
            saveBar('done');
            var ok = (r.status === 'success' || r.status === true);
            if (ok){
                if (r.policy) applyPolicy(r.policy);          // reflect server-normalised values
                gpsAlert('Attendance policy saved.', 'success');
                btn.classList.add('is-saved');
                btn.innerHTML = '<i class="fa fa-check"></i> Saved';
                setTimeout(function(){ btn.classList.remove('is-saved'); btn.innerHTML = SAVE_LABEL; btn.disabled = false; }, 1600);
            } else {
                gpsAlert(r.message || 'Failed to save the policy.', 'error');
                btn.innerHTML = SAVE_LABEL; btn.disabled = false;
            }
        }).catch(function(e){
            saveBar('error');
            gpsAlert((e && e.message) || 'Could not save — your changes were NOT saved. Try again.', 'error');
            btn.innerHTML = SAVE_LABEL; btn.disabled = false;
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

    // Apply the SSR bootstrap synchronously → real state on first paint, no
    // shimmer flash and no extra round-trip. Fall back to the async fetch only
    // if the server didn't inline the policy (e.g. a cached/legacy page).
    if (BOOTSTRAP){
        loadFailed = false;
        applyPolicy(BOOTSTRAP);
    } else {
        syncGeoEnabled();   // dim the geofence card up-front (GPS defaults to off)
        loadGpsPolicy();
    }
})();
</script>

<?php if (!$att_can_manage): ?>
<script>
/* Read-only enforcement (no Attendance manage permission). Disable every
   input/select/textarea/button inside the settings wrap so nothing can be
   saved. Device rows + GPS campuses render asynchronously, so re-lock on any
   DOM mutation. The read-only data itself stays fully visible; tabs (divs)
   remain navigable. The controller is still the authoritative gate. */
(function(){
    var wrap = document.querySelector('.att-wrap');
    if (!wrap) return;
    function lock(){
        wrap.querySelectorAll('input, select, textarea, button').forEach(function(el){
            if (!el.disabled) el.disabled = true;
        });
    }
    lock();
    try { new MutationObserver(lock).observe(wrap, { childList: true, subtree: true }); } catch(e){}
})();
</script>
<?php endif; ?>
