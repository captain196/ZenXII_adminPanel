<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<?php
// ── Level gating ──────────────────────────────────────────────────────
// Saving a school week is a MANAGE action. Without it the page renders
// read-only: the editor is disabled and a banner explains why. The controller
// remains the authoritative gate — this only avoids offering an action that
// would be refused.
$sc_can_manage = function_exists('has_permission') ? has_permission('Attendance', 'manage') : true;
?>

<link rel="stylesheet" href="<?= base_url('assets/css/attendance_design_system.css') ?>?v=2.0.0">

<style>
/* Page-local only. Anything reusable belongs in the Attendance Design System. */
.sc-wrap { max-width: 1180px; }
.sc-grid { display: grid; grid-template-columns: minmax(0,1fr) minmax(0,420px); gap: 20px; align-items: start; }
@media (max-width: 991px) { .sc-grid { grid-template-columns: minmax(0,1fr); } }

.sc-card { background:#fff; border:1px solid #e3e7ec; border-radius:6px; margin-bottom:20px; }
.sc-card > h3 { margin:0; padding:14px 18px; border-bottom:1px solid #eef1f5; font-size:15px; font-weight:600; color:#2c3e50; }
.sc-card > .sc-body { padding:18px; }

.sc-kv { display:flex; flex-wrap:wrap; gap:8px 28px; margin:0 0 4px; }
.sc-kv div { min-width:150px; }
.sc-kv dt { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:#8a94a0; margin:0 0 2px; }
.sc-kv dd { margin:0; font-size:14px; color:#2c3e50; font-weight:600; }

/* Weekday toggles — checkboxes styled as chips, but still real checkboxes so
   keyboard and screen-reader behaviour is unchanged. */
.sc-days { display:flex; flex-wrap:wrap; gap:8px; margin:0; padding:0; border:0; }
.sc-day { position:relative; }
.sc-day input { position:absolute; opacity:0; width:100%; height:100%; margin:0; cursor:pointer; }
.sc-day span { display:block; padding:9px 14px; border:1px solid #d6dbe1; border-radius:4px; font-size:13px; color:#5a6673; background:#fff; user-select:none; }
.sc-day input:checked + span { background:#fdecea; border-color:#e2a5a0; color:#a3352b; font-weight:600; }
.sc-day input:focus-visible + span { outline:2px solid #2f6fb2; outline-offset:2px; }
.sc-day input:disabled + span { opacity:.55; cursor:not-allowed; }

.sc-nth { border:1px dashed #d6dbe1; border-radius:4px; padding:12px; margin-top:12px; }
.sc-nth-row { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:8px; }
.sc-nth-row select, .sc-nth-row input { height:32px; }
.sc-weeks { display:flex; gap:6px; }
.sc-weeks label { font-weight:400; font-size:12px; margin:0; display:flex; gap:3px; align-items:center; }

.sc-cal { width:100%; border-collapse:collapse; table-layout:fixed; }
.sc-cal th { font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#8a94a0; padding:6px 0; font-weight:600; }
.sc-cal td { height:52px; vertical-align:top; border:1px solid #eef1f5; padding:4px 5px; font-size:12px; }
.sc-cal td.off { background:#fdf3f2; }
.sc-cal td.off .n { color:#a3352b; font-weight:700; }
.sc-cal td.pad { background:#fafbfc; border-color:#f4f6f8; }
.sc-cal .n { font-size:13px; font-weight:600; color:#2c3e50; }
.sc-cal .why { display:block; margin-top:2px; font-size:10px; line-height:1.25; color:#a3352b; overflow-wrap:anywhere; }

.sc-hist { width:100%; border-collapse:collapse; font-size:13px; }
.sc-hist th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#8a94a0; padding:8px 10px; border-bottom:1px solid #eef1f5; }
.sc-hist td { padding:9px 10px; border-bottom:1px solid #f4f6f8; vertical-align:top; }
.sc-hist tr.active td { background:#f6fbf7; }
.sc-hist .pill { display:inline-block; font-size:10px; padding:2px 7px; border-radius:10px; background:#e5f3ea; color:#2b6b43; font-weight:600; }

.sc-note { background:#f7f9fb; border-left:3px solid #c3ccd6; padding:11px 14px; font-size:13px; color:#55606d; border-radius:0 4px 4px 0; }
.sc-warn { background:#fff8e6; border-left-color:#e0a800; color:#6b5411; }
.sc-err  { background:#fdecea; border-left-color:#c0392b; color:#8d2b21; }
.sc-ok   { background:#eef8f1; border-left-color:#2e8b57; color:#256b44; }

.sc-empty { text-align:center; padding:34px 18px; color:#8a94a0; font-size:14px; }
.sc-busy { opacity:.5; pointer-events:none; }
.sc-sr { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; }
</style>

<!-- UI-FIX: wrap in .content-wrapper so the page paints the light content
     background over the dark chrome (--bg2/3). Without it this view rendered
     directly on the dark chrome and the area behind the cards showed black.
     Mirrors attendance/index.php, work_schedules/index.php, staff_access. -->
<div class="content-wrapper">
<section class="content-header">
    <h1>School Week
        <small>Which days the school teaches — students</small>
    </h1>
</section>

<section class="content sc-wrap">

    <!-- Live region: every async outcome is announced, not only painted. -->
    <div id="scAnnounce" class="sc-sr" role="status" aria-live="polite"></div>

    <?php if (!$sc_can_manage): ?>
        <div class="sc-note sc-warn" style="margin-bottom:18px;">
            You can view the school week but not change it. Changing it needs the
            <strong>manage</strong> permission on Attendance.
        </div>
    <?php endif; ?>

    <div class="sc-note" style="margin-bottom:18px;">
        Sets which weekdays are non-teaching for <strong>students</strong>. Staff working
        days are configured separately under <em>Work Schedules</em>.
    </div>

    <!-- CAL-P4 — new-session readiness. The weekly pattern survives a rollover
         automatically (school-scoped + effective-dated); dated holidays
         deliberately do not. This says so rather than letting a new session run
         silently with every festival treated as a teaching day. -->
    <div id="scReadiness" class="sc-note sc-warn" style="display:none;margin-bottom:18px;"></div>

    <div id="scLoading" class="sc-empty">Loading the school week…</div>
    <div id="scLoadError" class="sc-note sc-err" style="display:none;"></div>

    <div id="scMain" style="display:none;">

        <!-- CAL-D10 divergence — reported, never applied. -->
        <div id="scDivergence" class="sc-note sc-warn" style="display:none; margin-bottom:18px;">
            <strong>Your saved settings and your actual school week disagree.</strong>
            <p id="scDivergenceMsg" style="margin:8px 0 0;"></p>
            <p style="margin:8px 0 0;">
                Nothing has been changed. If you want the saved pattern to apply, load it
                into the editor below and choose the date it should start from. Attendance
                already recorded will not change.
            </p>
            <button type="button" class="btn btn-warning btn-sm" id="scAdoptBtn" style="margin-top:10px;"
                <?= $sc_can_manage ? '' : 'disabled' ?>>
                Load these days into the editor
            </button>
        </div>

        <div class="sc-grid">
            <div>
                <!-- ── ACTIVE POLICY ─────────────────────────────────── -->
                <div class="sc-card">
                    <h3>In force today</h3>
                    <div class="sc-body">
                        <dl class="sc-kv">
                            <div><dt>Non-teaching days</dt><dd id="scActiveOffs">—</dd></div>
                            <div><dt>In force since</dt><dd id="scActiveFrom">—</dd></div>
                            <div><dt>Source</dt><dd id="scActiveSource">—</dd></div>
                            <div><dt>Academic session</dt><dd id="scSession">—</dd></div>
                        </dl>
                        <div id="scLegacyNote" class="sc-note" style="display:none; margin-top:14px;"></div>
                    </div>
                </div>

                <!-- ── EDITOR ────────────────────────────────────────── -->
                <div class="sc-card">
                    <h3>Set a new school week</h3>
                    <div class="sc-body">
                        <p class="sc-note" style="margin:0 0 16px;">
                            A school week is never edited in place. Saving adds a
                            <strong>new version</strong> that starts on a date you choose, and
                            every earlier version stays exactly as it was — which is what keeps
                            past attendance meaning what it meant when it was recorded.
                        </p>

                        <form id="scForm" novalidate>
                            <fieldset <?= $sc_can_manage ? '' : 'disabled' ?> style="border:0; padding:0; margin:0;">
                                <legend class="sc-sr">School week configuration</legend>

                                <label style="display:block; font-size:13px; margin-bottom:7px;">
                                    Days the school is <strong>closed</strong> to students
                                </label>
                                <div class="sc-days" id="scDayToggles" role="group"
                                     aria-label="Days the school is closed to students"></div>
                                <p style="font-size:12px; color:#8a94a0; margin:8px 0 0;">
                                    Tick every day that is normally a non-teaching day. At least one
                                    is required — a week with none would treat every calendar day as
                                    a teaching day.
                                </p>

                                <div class="sc-nth">
                                    <strong style="font-size:13px;">Alternating days</strong>
                                    <p style="font-size:12px; color:#8a94a0; margin:4px 0 10px;">
                                        For patterns like <em>2nd and 4th Saturday closed</em>, where the
                                        day is only some weeks of the month.
                                    </p>
                                    <div id="scNthRows"></div>
                                    <button type="button" class="btn btn-default btn-sm" id="scAddNth">
                                        Add an alternating rule
                                    </button>
                                </div>

                                <div style="margin-top:18px;">
                                    <label for="scEffectiveFrom" style="display:block; font-size:13px;">
                                        Starts from
                                    </label>
                                    <input type="date" id="scEffectiveFrom" class="form-control"
                                           style="max-width:230px;" aria-describedby="scEffHelp">
                                    <p id="scEffHelp" style="font-size:12px; color:#8a94a0; margin:6px 0 0;">
                                        Must be after <strong id="scSettled">—</strong>, the last date whose
                                        attendance is treated as settled. Earlier dates are refused so a
                                        change today cannot alter what a past month meant.
                                    </p>
                                </div>

                                <div style="margin-top:14px;">
                                    <label for="scReason" style="display:block; font-size:13px;">
                                        Why is it changing? <span style="color:#8a94a0;">(optional)</span>
                                    </label>
                                    <input type="text" id="scReason" class="form-control" maxlength="200"
                                           placeholder="e.g. Moving to a five-day week from the new term">
                                </div>

                                <div id="scFormMsg" class="sc-note" style="display:none; margin-top:16px;"></div>

                                <div style="margin-top:18px; display:flex; gap:10px; flex-wrap:wrap;">
                                    <button type="submit" class="btn btn-primary" id="scSaveBtn">
                                        Save this school week
                                    </button>
                                    <button type="button" class="btn btn-default" id="scResetBtn">
                                        Reset to what is in force
                                    </button>
                                </div>
                            </fieldset>
                        </form>
                    </div>
                </div>

                <!-- ── HISTORY ───────────────────────────────────────── -->
                <div class="sc-card">
                    <h3>History</h3>
                    <div class="sc-body" style="padding:0;">
                        <table class="sc-hist">
                            <thead>
                                <tr>
                                    <th scope="col">Starts from</th>
                                    <th scope="col">Closed days</th>
                                    <th scope="col">Reason</th>
                                    <th scope="col">Added by</th>
                                </tr>
                            </thead>
                            <tbody id="scHistory"></tbody>
                        </table>
                        <div id="scHistEmpty" class="sc-empty" style="display:none;">
                            No school week has been set yet. Until one is, attendance runs on the
                            original rule: Sunday closed, plus any holidays you declare.
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── PREVIEW ───────────────────────────────────────────── -->
            <div>
                <div class="sc-card">
                    <h3>Preview</h3>
                    <div class="sc-body">
                        <p style="font-size:12px; color:#8a94a0; margin:0 0 12px;">
                            What the editor above would do to a month. Holidays you have declared
                            are included, so you can see the real picture before saving.
                        </p>
                        <div style="display:flex; gap:8px; margin-bottom:14px;">
                            <select id="scPrevMonth" class="form-control" style="flex:1;"
                                    aria-label="Preview month"></select>
                            <select id="scPrevYear" class="form-control" style="width:96px;"
                                    aria-label="Preview year"></select>
                        </div>
                        <div id="scPrevWrap" style="overflow-x:auto;">
                            <table class="sc-cal" id="scCal">
                                <thead><tr id="scCalHead"></tr></thead>
                                <tbody id="scCalBody"></tbody>
                            </table>
                        </div>
                        <div id="scPrevMsg" class="sc-note" style="display:none; margin-top:12px;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
</div><!-- /.content-wrapper (UI-FIX) -->

<script>
(function () {
    'use strict';

    var CSRF_NAME = '<?= $this->security->get_csrf_token_name() ?>';
    var CSRF_HASH = '<?= $this->security->get_csrf_hash() ?>';
    var BASE      = '<?= rtrim(base_url(), "/") ?>/';
    var CAN_MANAGE = <?= $sc_can_manage ? 'true' : 'false' ?>;

    var DAYS = [], DAY_NAMES = {}, STATE = null;
    var MONTHS = ['January','February','March','April','May','June','July',
                  'August','September','October','November','December'];

    function $(id) { return document.getElementById(id); }
    function announce(t) { $('scAnnounce').textContent = t; }

    function note(el, text, kind) {
        el.className = 'sc-note' + (kind ? ' sc-' + kind : '');
        el.innerHTML = text;
        el.style.display = text ? '' : 'none';
    }

    /**
     * Every response is checked TWICE: the HTTP status AND the payload's own
     * status field. fetch() does not reject on 403 or 500, so a request that was
     * refused would otherwise be rendered as a success — the panel would report
     * a saved school week that the server never accepted.
     */
    function api(path, body) {
        var opts = { method: 'POST', credentials: 'same-origin',
                     headers: { 'X-Requested-With': 'XMLHttpRequest' } };
        var fd = new FormData();
        Object.keys(body || {}).forEach(function (k) { fd.append(k, body[k]); });
        fd.append(CSRF_NAME, CSRF_HASH);
        opts.body = fd;

        return fetch(BASE + path, opts).then(function (r) {
            return r.json().catch(function () { return null; }).then(function (j) {
                if (j && j.csrf_token) { CSRF_HASH = j.csrf_token; }   // rotate, or the next POST 403s
                if (!r.ok || !j || j.status === 'error') {
                    var err = new Error((j && j.message) || ('Request failed (' + r.status + ')'));
                    err.httpStatus = r.status;
                    throw err;
                }
                return j;
            });
        });
    }

    // ── weekday toggles ────────────────────────────────────────────────
    function renderDayToggles() {
        $('scDayToggles').innerHTML = DAYS.map(function (d) {
            return '<label class="sc-day"><input type="checkbox" value="' + d + '"' +
                   (CAN_MANAGE ? '' : ' disabled') + '>' +
                   '<span>' + (DAY_NAMES[d] || d) + '</span></label>';
        }).join('');
    }
    function getCheckedDays() {
        return Array.prototype.slice
            .call($('scDayToggles').querySelectorAll('input:checked'))
            .map(function (i) { return i.value; });
    }
    function setCheckedDays(list) {
        var set = {}; (list || []).forEach(function (d) { set[d] = 1; });
        Array.prototype.slice.call($('scDayToggles').querySelectorAll('input'))
            .forEach(function (i) { i.checked = !!set[i.value]; });
    }

    // ── nth-weekday rules ──────────────────────────────────────────────
    function addNthRow(rule) {
        var wrap = document.createElement('div');
        wrap.className = 'sc-nth-row';
        var weeks = (rule && rule.weeks) || [];
        var boxes = [1,2,3,4,5].map(function (w) {
            return '<label><input type="checkbox" class="sc-week" value="' + w + '"' +
                   (weeks.indexOf(w) > -1 || weeks.indexOf(String(w)) > -1 ? ' checked' : '') +
                   (CAN_MANAGE ? '' : ' disabled') + '>' + w + '</label>';
        }).join('');
        wrap.innerHTML =
            '<select class="form-control sc-nth-day" style="width:120px;" aria-label="Day"' +
            (CAN_MANAGE ? '' : ' disabled') + '>' +
            DAYS.map(function (d) {
                return '<option value="' + d + '"' +
                       (rule && rule.day === d ? ' selected' : '') + '>' +
                       (DAY_NAMES[d] || d) + '</option>';
            }).join('') + '</select>' +
            '<span style="font-size:12px;color:#8a94a0;">closed on week</span>' +
            '<span class="sc-weeks">' + boxes + '</span>' +
            '<button type="button" class="btn btn-link btn-sm sc-nth-del" ' +
            'aria-label="Remove this rule"' + (CAN_MANAGE ? '' : ' disabled') + '>Remove</button>';
        wrap.querySelector('.sc-nth-del').addEventListener('click', function () {
            wrap.remove(); schedulePreview();
        });
        wrap.addEventListener('change', schedulePreview);
        $('scNthRows').appendChild(wrap);
    }
    function getNthRules() {
        return Array.prototype.slice.call($('scNthRows').children).map(function (row) {
            return {
                day: row.querySelector('.sc-nth-day').value,
                weeks: Array.prototype.slice.call(row.querySelectorAll('.sc-week:checked'))
                        .map(function (c) { return parseInt(c.value, 10); })
            };
        }).filter(function (r) { return r.weeks.length > 0; });
    }

    function currentDraft() {
        return { weeklyOffs: getCheckedDays(), nthWeekdayOffs: getNthRules() };
    }

    // ── preview ────────────────────────────────────────────────────────
    var previewTimer = null;
    function schedulePreview() {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(runPreview, 250);
    }

    function runPreview() {
        var y = parseInt($('scPrevYear').value, 10);
        var m = parseInt($('scPrevMonth').value, 10);
        if (!y || !m) return;

        var draft = currentDraft();
        var body  = { year: y, month: m };
        // Only send a draft once it is describable; otherwise preview what is
        // actually in force rather than rejecting an in-progress edit.
        if (draft.weeklyOffs.length || draft.nthWeekdayOffs.length) {
            body.draft = JSON.stringify(draft);
        }

        $('scPrevWrap').classList.add('sc-busy');
        api('student_calendar/preview', body).then(function (j) {
            drawCalendar(y, m, j.nonWorking || {});
            note($('scPrevMsg'), '', '');
        }).catch(function (e) {
            // Escaped: server messages quote the values that caused them — the
            // validator echoes the offending day token straight back — so an
            // unescaped message is an injection route into the admin's own page.
            note($('scPrevMsg'), escapeHtml(e.message), 'err');
        }).then(function () {
            $('scPrevWrap').classList.remove('sc-busy');
        });
    }

    function drawCalendar(year, month, nonWorking) {
        $('scCalHead').innerHTML = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']
            .map(function (d) { return '<th scope="col">' + d + '</th>'; }).join('');

        var first = new Date(year, month - 1, 1).getDay();
        var days  = new Date(year, month, 0).getDate();
        var html = '', cell = 0, row = '';

        for (var i = 0; i < first; i++) { row += '<td class="pad"></td>'; cell++; }
        for (var d = 1; d <= days; d++) {
            var why = nonWorking[d] || nonWorking[String(d)] || '';
            row += '<td class="' + (why ? 'off' : '') + '">' +
                   '<span class="n">' + d + '</span>' +
                   (why ? '<span class="why">' + escapeHtml(why) + '</span>' : '') +
                   '</td>';
            if (++cell % 7 === 0) { html += '<tr>' + row + '</tr>'; row = ''; }
        }
        while (cell % 7 !== 0) { row += '<td class="pad"></td>'; cell++; }
        if (row) html += '<tr>' + row + '</tr>';
        $('scCalBody').innerHTML = html;
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
        });
    }

    // ── history ────────────────────────────────────────────────────────
    function renderHistory(versions, activeFrom) {
        var tb = $('scHistory');
        if (!versions || !versions.length) {
            tb.innerHTML = '';
            $('scHistEmpty').style.display = '';
            return;
        }
        $('scHistEmpty').style.display = 'none';
        tb.innerHTML = versions.slice().reverse().map(function (v) {
            var offs = (v.weeklyOffs || []).map(function (d) { return DAY_NAMES[d] || d; });
            (v.nthWeekdayOffs || []).forEach(function (r) {
                offs.push((DAY_NAMES[r.day] || r.day) + ' (week ' + (r.weeks || []).join(', ') + ')');
            });
            var isActive = v.effectiveFrom === activeFrom;
            return '<tr class="' + (isActive ? 'active' : '') + '">' +
                '<td>' + escapeHtml(v.effectiveFrom || '') +
                   (isActive ? ' <span class="pill">in force</span>' : '') + '</td>' +
                '<td>' + escapeHtml(offs.join(', ') || '—') + '</td>' +
                '<td>' + escapeHtml(v.reason || v.label || '—') + '</td>' +
                '<td>' + escapeHtml(v.createdBy || '—') +
                   (v.createdAt ? '<br><span style="font-size:11px;color:#8a94a0;">' +
                                  escapeHtml(String(v.createdAt).slice(0, 10)) + '</span>' : '') +
                '</td></tr>';
        }).join('');
    }

    // ── load ───────────────────────────────────────────────────────────
    function load() {
        return api('student_calendar/get_policy', {}).then(function (j) {
            STATE = j;
            DAYS = j.dayTokens || ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
            DAY_NAMES = j.dayNames || {};

            renderDayToggles();

            var active = j.active || {};
            var offs = (active.weeklyOffs || []).map(function (d) { return DAY_NAMES[d] || d; });
            (active.nthWeekdayOffs || []).forEach(function (r) {
                offs.push((DAY_NAMES[r.day] || r.day) + ' (week ' + (r.weeks || []).join(', ') + ')');
            });
            $('scActiveOffs').textContent = offs.join(', ') || 'None';
            $('scActiveFrom').textContent = j.configured ? (active.effectiveFrom || '—') : 'Not set';
            $('scActiveSource').textContent =
                j.source === 'configured' ? 'Configured by your school'
              : j.source === 'unreadable' ? 'Settings could not be read'
              : 'Original rule (not yet configured)';
            $('scSession').textContent = j.session || '—';
            $('scSettled').textContent = j.settledThrough || '—';

            if (!j.configured) {
                note($('scLegacyNote'), escapeHtml(j.legacyNote || ''), '');
            }
            if (j.readable === false) {
                note($('scLoadError'),
                     'Your attendance settings could not be read just now, so this page is ' +
                     'showing the original rule. Nothing has been changed. Please reload.', 'err');
                $('scLoadError').style.display = '';
            }

            // Effective date: never earlier than the settled boundary + 1 day.
            //
            // The 'Z' is load-bearing. Without it the string parses as LOCAL
            // midnight while toISOString() reports UTC, so in any zone east of
            // UTC the result lands a day short: in IST, settledThrough
            // 2026-08-31 produced a minimum of 2026-08-31 — the very date the
            // server refuses — and the field DEFAULTED to it, so the first save
            // an Indian administrator attempted was rejected with a message
            // that made no sense against what the picker had offered.
            // Verified by execution under TZ=Asia/Kolkata.
            var min = j.settledThrough
                ? new Date(new Date(j.settledThrough + 'T00:00:00Z').getTime() + 86400000)
                      .toISOString().slice(0, 10)
                : j.today;
            $('scEffectiveFrom').min = min;
            if (!$('scEffectiveFrom').value) { $('scEffectiveFrom').value = min; }

            setCheckedDays(active.weeklyOffs || []);
            $('scNthRows').innerHTML = '';
            (active.nthWeekdayOffs || []).forEach(addNthRow);

            renderHistory(j.versions, active.effectiveFrom);

            var rd = j.readiness || {};
            if (rd.checked && rd.message) {
                note($('scReadiness'), escapeHtml(rd.message), 'warn');
            } else {
                $('scReadiness').style.display = 'none';
            }

            if (j.divergence) {
                $('scDivergenceMsg').textContent = j.divergence.message || '';
                $('scDivergence').style.display = '';
            } else {
                $('scDivergence').style.display = 'none';
            }

            $('scLoading').style.display = 'none';
            $('scMain').style.display = '';
        }).catch(function (e) {
            $('scLoading').style.display = 'none';
            note($('scLoadError'), escapeHtml(e.message), 'err');
            $('scLoadError').style.display = '';
        });
    }

    // ── save ───────────────────────────────────────────────────────────
    $('scForm').addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (!CAN_MANAGE) return;

        var draft = currentDraft();
        var eff   = $('scEffectiveFrom').value;

        if (!draft.weeklyOffs.length && !draft.nthWeekdayOffs.length) {
            note($('scFormMsg'), 'Choose at least one day the school is closed.', 'err');
            announce('Not saved. Choose at least one closed day.');
            return;
        }
        if (!eff) {
            note($('scFormMsg'), 'Choose the date this school week starts from.', 'err');
            return;
        }

        var btn = $('scSaveBtn');
        btn.disabled = true;
        btn.textContent = 'Saving…';
        note($('scFormMsg'), '', '');

        api('student_calendar/save_version', {
            effectiveFrom: eff,
            weeklyOffs: JSON.stringify(draft.weeklyOffs),
            nthWeekdayOffs: JSON.stringify(draft.nthWeekdayOffs),
            reason: $('scReason').value
        }).then(function (j) {
            note($('scFormMsg'), escapeHtml(j.message || 'Saved.'), 'ok');
            announce(j.message || 'School week saved.');
            $('scReason').value = '';
            return load();
        }).catch(function (e) {
            // A 409 is somebody else's save landing first, not a validation
            // problem. Offering a retry here would overwrite their policy, so
            // the only offer is a reload.
            if (e.httpStatus === 409) {
                note($('scFormMsg'), escapeHtml(e.message) +
                     ' <button type="button" class="btn btn-default btn-sm" ' +
                     'onclick="location.reload()">Reload</button>', 'warn');
            } else {
                note($('scFormMsg'), escapeHtml(e.message), 'err');
            }
            announce('Not saved. ' + e.message);
        }).then(function () {
            btn.disabled = false;
            btn.textContent = 'Save this school week';
        });
    });

    $('scResetBtn').addEventListener('click', function () {
        var active = (STATE && STATE.active) || {};
        setCheckedDays(active.weeklyOffs || []);
        $('scNthRows').innerHTML = '';
        (active.nthWeekdayOffs || []).forEach(addNthRow);
        note($('scFormMsg'), '', '');
        schedulePreview();
    });

    $('scAddNth').addEventListener('click', function () { addNthRow(null); schedulePreview(); });
    $('scDayToggles').addEventListener('change', schedulePreview);

    $('scAdoptBtn').addEventListener('click', function () {
        var d = STATE && STATE.divergence;
        if (!d) return;
        setCheckedDays(d.savedClosedDays || []);
        $('scNthRows').innerHTML = '';
        note($('scFormMsg'),
             'Loaded from your saved settings. Nothing is saved yet — check the preview, ' +
             'choose a start date, then save.', 'warn');
        announce('Saved days loaded into the editor. Not saved yet.');
        $('scEffectiveFrom').focus();
        schedulePreview();
    });

    // ── month/year pickers ─────────────────────────────────────────────
    (function initPickers() {
        var now = new Date();
        $('scPrevMonth').innerHTML = MONTHS.map(function (m, i) {
            return '<option value="' + (i + 1) + '"' +
                   (i === now.getMonth() ? ' selected' : '') + '>' + m + '</option>';
        }).join('');
        var y = now.getFullYear(), opts = '';
        for (var i = y - 1; i <= y + 2; i++) {
            opts += '<option value="' + i + '"' + (i === y ? ' selected' : '') + '>' + i + '</option>';
        }
        $('scPrevYear').innerHTML = opts;
        $('scPrevMonth').addEventListener('change', runPreview);
        $('scPrevYear').addEventListener('change', runPreview);
    })();

    load().then(runPreview);
})();
</script>
