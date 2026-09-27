<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<!-- ============================================================
     Work Schedules — PHASE 1 admin surface.
     Reads /work_schedules/get_data; writes via work_schedules/*.
     Self-contained, theme-aware. Fail-closed: every fetch checks
     r.ok AND {status:'error'} before rendering success.
     ============================================================ -->
<div class="content-wrapper">
<div class="wsx">

  <div id="wsAlert" class="wsx-alert" hidden></div>

  <!-- Header lives in its own card so the page matches every other module. -->
  <div class="wsx-card wsx-hero">
    <h1>Work Schedules</h1>
    <p>Define a schedule once, attach it to a department, and everyone in that
       department inherits it. Pick per person only where they differ.</p>
  </div>

  <!-- Blocked employees — the point of this screen -->
  <div class="wsx-card wsx-blocked" id="wsBlockedCard" hidden>
    <div class="wsx-card-hd">
      <h2 id="wsBlockedTitle">Employees who cannot be paid yet</h2>
      <span class="wsx-count" id="wsBlockedCount" hidden>0</span>
    </div>
    <p class="wsx-note" id="wsBlockedNote"></p>
    <!-- Grouped by cause, collapsed by default. One row per employee does not
         scale — 400 staff with the same cause is 400 identical rows, and the
         page becomes unreadable exactly when it matters most. -->
    <div class="wsx-groups" id="wsBlockedTable" hidden></div>
  </div>

  <!-- Catalogue -->
  <div class="wsx-card">
    <div class="wsx-card-hd">
      <h2>Schedules</h2>
      <button class="wsx-btn wsx-primary" id="wsNew">＋ New schedule</button>
    </div>
    <div class="wsx-tbl-wrap">
      <table class="wsx-tbl">
        <thead><tr><th>Name</th><th>Working days</th><th>Weekly offs</th><th>Used by</th><th></th></tr></thead>
        <tbody id="wsSchedBody"><tr><td colspan="5" class="wsx-empty">Loading…</td></tr></tbody>
      </table>
    </div>
  </div>

  <!-- Assignments -->
  <div class="wsx-card">
    <div class="wsx-card-hd">
      <h2>Assignments</h2>
      <button class="wsx-btn" id="wsAssignNew">＋ Assign a schedule</button>
    </div>
    <div class="wsx-tbl-wrap">
      <table class="wsx-tbl">
        <thead><tr><th>Attached to</th><th>Schedule</th><th>From</th><th>To</th><th></th></tr></thead>
        <tbody id="wsAsgBody"><tr><td colspan="5" class="wsx-empty">Loading…</td></tr></tbody>
      </table>
    </div>
  </div>
</div>
</div>

<!-- Schedule editor -->
<div class="wsx-modal" id="wsSchedModal" hidden>
  <div class="wsx-modal-box wsx-wide">
    <header class="wsx-mhd">
      <h3 id="wsSchedTitle">New work schedule</h3>
      <p>A schedule defines which days of the week are worked. Attach it to a
         department and everyone in that department inherits it — or attach it
         to one person where they differ.</p>
    </header>
    <input type="hidden" id="wsSchedId">

    <section class="wsx-sec">
      <label class="wsx-lab" for="wsSchedName">Schedule name</label>
      <input type="text" id="wsSchedName" class="wsx-inp" maxlength="60"
             placeholder="e.g. Teaching — Mon to Fri">
      <p class="wsx-help">Name it after who it is for, not what it does — it appears
         on assignments and on every payslip that uses it.</p>
    </section>

    <section class="wsx-sec">
      <!-- The chips select WORKING days. People think "Teaching works Mon–Fri",
           not "Teaching is off Sat–Sun", so asking for offs made them translate
           and invert the schedule. Storage is unchanged — weeklyOffs is derived
           on save as the complement of the selection. -->
      <label class="wsx-lab">Working days</label>
      <p class="wsx-help">Tick every day staff on this schedule are expected to work.
         Unticked days become weekly offs (<b>O</b>) and are never marked absent.</p>
      <div class="wsx-chips" id="wsSchedDays"></div>
    </section>

    <!-- Live restatement + the numbers that actually drive pay. Catches an
         inverted or mistyped schedule on screen, not at the next payroll run. -->
    <section class="wsx-summary" id="wsSummary">
      <div class="wsx-stats">
        <div class="wsx-stat"><span class="k">Working days</span><span class="v w" id="wsStatWork">—</span><span class="s">per week</span></div>
        <div class="wsx-stat"><span class="k">Weekly offs</span><span class="v o" id="wsStatOff">—</span><span class="s">per week</span></div>
        <div class="wsx-stat"><span class="k">Approx.</span><span class="v" id="wsStatMonth">—</span><span class="s">working days a month</span></div>
      </div>
      <p class="wsx-resolve" id="wsResolve"></p>
    </section>

    <p class="wsx-suggest" id="wsSuggest" hidden></p>

    <!-- Errors must surface INSIDE the modal. The page-level bar sits at the top
         of a scrolled page, behind the overlay — the message was being rendered
         somewhere the user could not possibly see it. -->
    <p class="wsx-merr" id="wsSchedErr" hidden></p>

    <div class="wsx-modal-foot">
      <button class="wsx-btn" data-close>Cancel</button>
      <button class="wsx-btn wsx-primary" id="wsSchedSave">Save schedule</button>
    </div>
  </div>
</div>

<!-- Confirm dialog. Replaces window.confirm(), which renders a browser chrome
     box titled "localhost says" — off-theme, and it names the host rather than
     the action, which reads as a browser warning instead of a product decision. -->
<div class="wsx-modal" id="wsConfirm" hidden>
  <div class="wsx-modal-box">
    <h3 id="wsConfirmTitle">Are you sure?</h3>
    <p class="wsx-confirm-body" id="wsConfirmBody"></p>
    <div class="wsx-modal-foot">
      <button class="wsx-btn" data-close>Cancel</button>
      <button class="wsx-btn wsx-danger" id="wsConfirmOk">Delete</button>
    </div>
  </div>
</div>

<!-- Assignment editor -->
<div class="wsx-modal" id="wsAsgModal" hidden>
  <div class="wsx-modal-box">
    <h3>Assign a schedule</h3>
    <input type="hidden" id="wsAsgId">

    <label class="wsx-lab">Attach to</label>
    <div class="wsx-seg" id="wsAsgType">
      <button type="button" class="on" data-v="department">A department</button>
      <button type="button" data-v="staff">One employee</button>
    </div>

    <label class="wsx-lab" id="wsAsgTargetLab">Department</label>
    <select id="wsAsgTarget" class="wsx-inp"></select>

    <label class="wsx-lab">Schedule</label>
    <select id="wsAsgSched" class="wsx-inp"></select>

    <div class="wsx-row2">
      <div><label class="wsx-lab">Effective from</label>
        <input type="date" id="wsAsgFrom" class="wsx-inp"></div>
      <div><label class="wsx-lab">Until <span class="wsx-hint">(blank = ongoing)</span></label>
        <input type="date" id="wsAsgTo" class="wsx-inp"></div>
    </div>

    <p class="wsx-merr" id="wsAsgErr" hidden></p>

    <div class="wsx-modal-foot">
      <button class="wsx-btn" data-close>Cancel</button>
      <button class="wsx-btn wsx-primary" id="wsAsgSave">Save assignment</button>
    </div>
  </div>
</div>

<style>
.wsx{max-width:1180px;margin:0 auto;padding:4px 0 60px;color:var(--text,#22160F)}
.wsx-hero{padding:18px 20px}
.wsx-hero h1{font-size:21px;font-weight:800;margin:0}
.wsx-hero p{margin:7px 0 0;color:var(--muted,#9C8574);font-size:13.5px;max-width:78ch;line-height:1.55}
.wsx-card{background:var(--card,#fff);border:1px solid var(--line,rgba(120,70,45,.14));
  border-radius:14px;margin-bottom:16px;overflow:hidden}
.wsx-card-hd{display:flex;align-items:center;gap:10px;padding:14px 18px;
  border-bottom:1px solid var(--line,rgba(120,70,45,.14))}
.wsx-card-hd h2{font-size:15px;font-weight:700;margin:0}
.wsx-card-hd button{margin-left:auto}
.wsx-blocked{border-color:rgba(217,119,6,.45)}
.wsx-ok{border-color:rgba(22,163,74,.4)}
.wsx-ok .wsx-card-hd h2{color:#16a34a}
.wsx-groups{padding:4px 18px 16px;display:flex;flex-direction:column;gap:9px}
/* Same trap as .wsx-modal: an author `display` rule beats the UA stylesheet's
   [hidden]{display:none}, and this container IS toggled via .hidden. */
.wsx-groups[hidden]{display:none}
.wsx-grp{border:1px solid var(--line,rgba(120,70,45,.14));border-radius:11px;overflow:hidden;
  background:var(--card2,#FAF5F0)}
.wsx-grp-hd{width:100%;display:flex;align-items:center;gap:11px;padding:12px 14px;border:none;
  background:none;font:inherit;font-size:13.5px;font-weight:700;color:inherit;cursor:pointer;text-align:left}
.wsx-grp-hd:hover{background:rgba(188,90,60,.06)}
.wsx-caret{color:var(--muted,#9C8574);font-size:11px;width:11px;flex:0 0 auto}
.wsx-grp-t{flex:1}
.wsx-grp-n{background:rgba(217,119,6,.16);color:#d97706;font-weight:800;font-size:12px;
  padding:2px 10px;border-radius:20px;flex:0 0 auto}
.wsx-grp-bd{padding:0 14px 13px 36px}
.wsx-grp-fix{margin:0;font-size:12.5px;color:var(--muted,#9C8574);line-height:1.55;max-width:78ch}
.wsx-names{display:flex;flex-wrap:wrap;gap:7px;margin-top:12px}
.wsx-name{background:var(--card,#fff);border:1px solid var(--line,rgba(120,70,45,.14));
  border-radius:9px;padding:6px 11px;font-size:12.5px;line-height:1.35}
.wsx-name b{display:block;font-weight:700}
.wsx-name i{font-style:normal;font-size:11px;color:var(--muted,#9C8574)}
.wsx-count{margin-left:auto;background:rgba(217,119,6,.15);color:#d97706;
  font-weight:800;font-size:12px;padding:2px 10px;border-radius:20px}
.wsx-note{margin:12px 18px 0;font-size:12.5px;color:var(--muted,#9C8574);line-height:1.5}
.wsx-tbl-wrap{overflow-x:auto;padding:6px 12px 12px}
.wsx-tbl{width:100%;border-collapse:collapse;font-size:13px;min-width:620px}
.wsx-tbl th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;
  color:var(--muted,#9C8574);font-weight:800;padding:9px 10px}
.wsx-tbl td{padding:10px;border-top:1px solid var(--line,rgba(120,70,45,.14));vertical-align:top}
.wsx-empty{text-align:center;color:var(--muted,#9C8574);padding:22px}
.wsx-btn{border:1px solid var(--line2,rgba(120,70,45,.22));background:var(--card,#fff);
  color:inherit;font:inherit;font-weight:600;font-size:12.5px;padding:8px 14px;
  border-radius:9px;cursor:pointer}
.wsx-btn:hover{border-color:#BC5A3C;color:#BC5A3C}
.wsx-primary{background:#BC5A3C;border-color:#BC5A3C;color:#fff}
.wsx-primary:hover{filter:brightness(1.06);color:#fff}
.wsx-del{border:none;background:rgba(220,38,38,.1);color:#dc2626;border-radius:7px;
  padding:5px 9px;cursor:pointer;font-size:12px}
.wsx-pill{display:inline-block;background:rgba(188,90,60,.1);color:#BC5A3C;font-weight:700;
  font-size:11px;padding:2px 8px;border-radius:6px;margin-right:4px}
.wsx-why{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11px;
  color:var(--muted,#9C8574);white-space:pre-line}
.wsx-alert{margin:0 0 14px;padding:11px 15px;border-radius:10px;font-size:13px;font-weight:600}
.wsx-alert.err{background:rgba(220,38,38,.1);color:#dc2626}
.wsx-alert.ok{background:rgba(22,163,74,.12);color:#16a34a}
/* In-modal error: sits directly above the button that was pressed. */
.wsx-merr{margin:18px 0 0;padding:11px 14px;border-radius:10px;font-size:12.5px;line-height:1.55;
  background:rgba(220,38,38,.09);border:1px solid rgba(220,38,38,.3);color:#dc2626;font-weight:600}
.wsx-danger{background:#dc2626;border-color:#dc2626;color:#fff}
.wsx-danger:hover{filter:brightness(1.07);color:#fff;border-color:#dc2626}
.wsx-confirm-body{margin:0;font-size:13px;line-height:1.6;color:var(--muted,#9C8574);max-width:60ch}
.wsx-confirm-body b{color:var(--text,#22160F);font-weight:700}
.wsx-btn:disabled{cursor:not-allowed}
.wsx-busy{opacity:.65;position:relative;pointer-events:none}
.wsx-busy::after{content:"";position:absolute;right:9px;top:50%;width:11px;height:11px;
  margin-top:-6px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;
  animation:wsxspin .6s linear infinite}
.wsx-primary.wsx-busy{padding-right:28px}
@keyframes wsxspin{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion:reduce){.wsx-busy::after{animation:none}}
.wsx-modal{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:900;
  display:flex;align-items:center;justify-content:center;padding:20px}
/* `hidden` hides via display:none in the UA stylesheet, which ANY author
   `display` rule overrides — so without this the overlays stay on screen at
   inset:0 and swallow every click on the page. Must stay below .wsx-modal. */
.wsx-modal[hidden]{display:none}
.wsx-modal-box{background:var(--card,#fff);border-radius:14px;padding:22px;width:100%;
  max-width:520px;max-height:88vh;overflow:auto}
.wsx-modal-box h3{margin:0 0 16px;font-size:17px;font-weight:800}
.wsx-lab{display:block;font-size:12px;font-weight:700;margin:14px 0 6px}
.wsx-hint{font-weight:500;color:var(--muted,#9C8574)}
.wsx-inp{width:100%;font:inherit;font-size:13px;padding:9px 11px;border-radius:9px;
  border:1px solid var(--line2,rgba(120,70,45,.22));background:var(--card2,#FAF5F0);color:inherit}
.wsx-chips{display:flex;flex-wrap:wrap;gap:7px}
.wsx-chip{border:1px solid var(--line2,rgba(120,70,45,.22));background:var(--card2,#FAF5F0);
  font-weight:600;font-size:12.5px;padding:6px 13px;border-radius:20px;cursor:pointer;user-select:none}
.wsx-chip.on{background:#BC5A3C;color:#fff;border-color:#BC5A3C}
.wsx-suggest{margin:10px 0 0;font-size:12px;color:#d97706;background:rgba(217,119,6,.1);
  padding:9px 12px;border-radius:9px;line-height:1.5}
.wsx-resolve{margin:12px 0 0;font-size:12.5px;line-height:1.6}
.wsx-resolve b{font-weight:800}
.wsx-resolve .w{color:#16a34a}
.wsx-resolve .o{color:#BC5A3C}
.wsx-resolve.warn{background:rgba(220,38,38,.09);color:#dc2626;padding:10px 13px;border-radius:9px}
/* Wider box: seven day chips plus a three-up stat row need the room. */
.wsx-wide{max-width:680px}
.wsx-mhd{border-bottom:1px solid var(--line,rgba(120,70,45,.14));padding-bottom:15px;margin-bottom:4px}
.wsx-mhd h3{margin:0 0 7px}
.wsx-mhd p{margin:0;font-size:12.5px;color:var(--muted,#9C8574);line-height:1.55;max-width:62ch}
.wsx-sec{margin-top:18px}
.wsx-sec .wsx-lab{margin-top:0}
.wsx-help{margin:0 0 10px;font-size:12px;color:var(--muted,#9C8574);line-height:1.5;max-width:62ch}
.wsx-help b{color:#BC5A3C;font-weight:800}
.wsx-summary{margin-top:20px;background:var(--card2,#FAF5F0);border:1px solid var(--line,rgba(120,70,45,.14));
  border-radius:12px;padding:15px 17px}
.wsx-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.wsx-stat{display:flex;flex-direction:column;gap:2px}
.wsx-stat .k{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.45px;
  color:var(--muted,#9C8574)}
.wsx-stat .v{font-size:25px;font-weight:800;line-height:1.1;font-variant-numeric:tabular-nums}
.wsx-stat .v.w{color:#16a34a}
.wsx-stat .v.o{color:#BC5A3C}
.wsx-stat .s{font-size:11px;color:var(--muted,#9C8574)}
@media(max-width:560px){.wsx-stats{grid-template-columns:1fr 1fr}}
.wsx-seg{display:inline-flex;background:var(--card2,#FAF5F0);border-radius:9px;padding:3px;gap:2px}
.wsx-seg button{border:none;background:none;font:inherit;font-size:12.5px;font-weight:600;
  padding:6px 13px;border-radius:7px;cursor:pointer;color:inherit}
.wsx-seg button.on{background:#BC5A3C;color:#fff}
.wsx-row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.wsx-modal-foot{display:flex;gap:9px;justify-content:flex-end;margin-top:22px}
@media(max-width:640px){.wsx-row2{grid-template-columns:1fr}}
</style>

<script>
(function () {
  'use strict';
  var DAYS = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
  var state = { schedules: [], assignments: [], departments: {}, weeklyOffs: [],
                blocked: [], staffTotal: 0, staffActive: 0 };

  var $ = function (id) { return document.getElementById(id); };
  var esc = function (s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  };

  function alertBox(msg, kind) {
    var a = $('wsAlert');
    a.className = 'wsx-alert ' + (kind || 'err');
    a.textContent = msg;
    a.hidden = false;
    if (kind === 'ok') setTimeout(function () { a.hidden = true; }, 3200);
  }

  /* Themed confirm. window.confirm() shows a browser box headed "localhost
     says", which is off-theme and names the host instead of the consequence. */
  var pendingConfirm = null;
  function confirmAction(title, body, okLabel, fn) {
    $('wsConfirmTitle').textContent = title;
    $('wsConfirmBody').innerHTML    = body;
    $('wsConfirmOk').textContent    = okLabel;
    pendingConfirm = fn;
    $('wsConfirm').hidden = false;
  }

  /* Error shown inside the modal that caused it, next to the button pressed. */
  function modalError(id, msg) {
    var e = $(id);
    if (!e) { alertBox(msg); return; }
    e.textContent = msg;
    e.hidden = false;
  }
  function clearModalError(id) { var e = $(id); if (e) e.hidden = true; }

  /* Every request that a button starts must show that button working. Without
     it a slow save looks like a dead click, and people press it again — which
     on an assignment means two writes racing. Also disables the button, so a
     double-press cannot happen while the first request is in flight. */
  function busy(btn, on) {
    if (!btn) return;
    if (on) {
      btn.dataset.label = btn.dataset.label || btn.textContent;
      btn.textContent = 'Working…';
      btn.disabled = true;
      btn.classList.add('wsx-busy');
    } else {
      if (btn.dataset.label) btn.textContent = btn.dataset.label;
      btn.disabled = false;
      btn.classList.remove('wsx-busy');
    }
  }

  /* Fail closed: a non-2xx response and {status:'error'} both count as failure.
     fetch() does not reject on 403/500, so checking r.ok alone is not enough. */
  function post(url, data) {
    var body = new FormData();
    Object.keys(data || {}).forEach(function (k) {
      if (Array.isArray(data[k])) data[k].forEach(function (v) { body.append(k + '[]', v); });
      else body.append(k, data[k]);
    });
    var tok = document.querySelector('meta[name="csrf-token"]');
    var nam = document.querySelector('meta[name="csrf-name"]');
    if (tok && nam) body.append(nam.content, tok.content);

    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
      .catch(function () {
        throw new Error('Could not reach the server. Check your connection and try again.');
      })
      .then(function (r) {
        /* Read the body even on a non-2xx. json_error() replies 400 WITH an
           explanation — throwing on r.ok alone discarded every server message
           and showed "Request failed (400)" instead of the actual reason. */
        return r.text().then(function (raw) {
          var j = null;
          try { j = JSON.parse(raw); } catch (e) {}

          if (j && j.status === 'error') throw new Error(j.message || humanStatus(r.status));
          if (!r.ok)                     throw new Error((j && j.message) || humanStatus(r.status, raw));
          if (!j)                        throw new Error(humanStatus(r.status, raw));
          return j;
        });
      });
  }

  /* Only reached when the server sent no usable message of its own. */
  function humanStatus(status, raw) {
    if (status === 403) return 'You do not have permission to do this. Ask an administrator for HR access.';
    if (status === 404) return 'That action is not available. The page may be out of date — reload and try again.';
    if (status === 419) return 'Your session expired. Reload the page and sign in again.';
    if (status >= 500) {
      // A PHP notice printed before the JSON is the classic cause (N-21).
      var hint = (raw && /<br|<b>|Fatal error|Warning/i.test(raw))
        ? ' The server returned an error page instead of data.' : '';
      return 'Something went wrong on the server.' + hint + ' Nothing was saved.';
    }
    return 'The request could not be completed. Nothing was saved.';
  }

  function scheduleName(id) {
    for (var i = 0; i < state.schedules.length; i++) {
      if (state.schedules[i].scheduleId === id) return state.schedules[i].name;
    }
    return id || '—';
  }

  function usageCount(id) {
    return state.assignments.filter(function (a) { return a.policyId === id; }).length;
  }

  function render() {
    // Split deliberately: renderBlockedCard() returns early in several branches,
    // and when it was inline those returns also skipped the two tables below —
    // leaving them stuck on "Loading…" forever.
    renderBlockedCard();
    renderTables();
  }

  function renderBlockedCard() {
    /* The card ALWAYS reports. Hiding it when the list is empty made three very
       different situations look identical — everyone configured, nobody found,
       and a query returning nothing all rendered as a blank page, and a blank
       page reads as success. */
    var bc = $('wsBlockedCard'), tbl = $('wsBlockedTable');
    bc.hidden = false;

    if (state.blocked.length) {
      bc.className = 'wsx-card wsx-blocked';
      $('wsBlockedTitle').textContent = '⚠ Employees who cannot be paid yet';
      $('wsBlockedCount').textContent = state.blocked.length;
      $('wsBlockedCount').hidden = false;
      $('wsBlockedNote').innerHTML = 'Payroll refuses these employees rather than ' +
        'guessing a working calendar. Assign a schedule to their department, or to ' +
        'them individually, to clear each one. <b>' + state.staffActive +
        '</b> active staff checked.';
      tbl.hidden = false;
      renderBlockedGroups();
      return;
    }

    tbl.hidden = true;
    $('wsBlockedCount').hidden = true;

    if (!state.staffActive) {
      // Not a pass. There was nobody to check, which is a data problem, and
      // saying so is the difference between a real result and silence.
      bc.className = 'wsx-card wsx-blocked';
      $('wsBlockedTitle').textContent = 'No active staff found';
      $('wsBlockedNote').innerHTML = 'Nothing could be checked — this school has <b>' +
        state.staffTotal + '</b> staff record(s), of which <b>0</b> are active. ' +
        'Add or activate staff in <b>Staff Records</b> before assigning schedules. ' +
        'This is <i>not</i> confirmation that payroll is ready.';
      return;
    }

    /* INVARIANT: with no schedules in the catalogue, nothing can resolve — so a
       green "everyone is fine" here is arithmetically impossible and means the
       check silently dropped people. Refuse to render the all-clear rather than
       show a reassurance we cannot justify. */
    if (!state.schedules.length) {
      bc.className = 'wsx-card wsx-blocked';
      $('wsBlockedTitle').textContent = '⚠ Cannot verify — no schedules exist';
      $('wsBlockedNote').innerHTML = 'There are <b>no schedules</b>, so none of the <b>' +
        state.staffActive + '</b> active staff can resolve to one — yet none was ' +
        'reported as blocked. That is a contradiction, so this screen will not show ' +
        'an all-clear. Report it before relying on payroll.';
      return;
    }

    bc.className = 'wsx-card wsx-ok';
    $('wsBlockedTitle').textContent = '✓ Every employee resolves to a schedule';
    $('wsBlockedNote').innerHTML = 'All <b>' + state.staffActive +
      '</b> active staff resolve to a work schedule for today. Payroll has a ' +
      'working calendar for each of them.';
  }

  function renderTables() {
    // Schedules
    $('wsSchedBody').innerHTML = state.schedules.length
      ? state.schedules.map(function (s) {
          var offs = s.weeklyOffs || [];
          var working = DAYS.filter(function (d) { return offs.indexOf(d) === -1; });
          var used = usageCount(s.scheduleId);
          return '<tr><td><b>' + esc(s.name) + '</b></td><td>' +
            working.map(function (d) { return '<span class="wsx-pill">' + d + '</span>'; }).join('') +
            '</td><td>' + (offs.length ? offs.map(esc).join(', ') : '—') +
            '</td><td>' + used + '</td><td style="text-align:right">' +
            '<button class="wsx-btn" data-edit="' + esc(s.scheduleId) + '">Edit</button> ' +
            '<button class="wsx-del" data-delsched="' + esc(s.scheduleId) + '">Delete</button></td></tr>';
        }).join('')
      : '<tr><td colspan="5" class="wsx-empty">No schedules yet. Create one to unblock payroll.</td></tr>';

    // Assignments
    $('wsAsgBody').innerHTML = state.assignments.length
      ? state.assignments.map(function (a) {
          var label = a.attachmentType === 'department'
            ? 'Department · ' + esc(a.attachmentId)
            : 'Employee · ' + esc(a.attachmentId);
          return '<tr><td>' + label + '</td><td>' + esc(scheduleName(a.policyId)) +
            '</td><td>' + esc(a.effectiveFrom || '—') +
            '</td><td>' + esc(a.effectiveTo || 'ongoing') +
            '</td><td style="text-align:right"><button class="wsx-del" data-delasg="' +
            esc(a.assignmentId) + '">Remove</button></td></tr>';
        }).join('')
      : '<tr><td colspan="5" class="wsx-empty">Nothing assigned yet.</td></tr>';
  }

  /* Plain-language cause + the action that clears it. The raw block code is
     kept for support, but an administrator should never have to read one. */
  var CAUSES = {
    no_policies:          ['No work schedule exists yet',
                           'Nothing has been created. Use ＋ New schedule above — one schedule can cover a whole department.'],
    no_assignment:        ['Not attached to any schedule',
                           'A schedule exists but nobody has attached it to them. Use ＋ Assign a schedule and attach it to their department.'],
    assignment_expired:   ['Their schedule assignment has ended',
                           'It had an end date that has now passed. Assign a new one starting today.'],
    ambiguous_assignment: ['Two overlapping assignments',
                           'Two schedules claim the same dates, so payroll refuses to choose. End one of them.'],
    policy_missing:       ['Attached to a schedule that no longer exists',
                           'The schedule was deleted or renamed. Re-assign them to a current one.'],
    unidentifiable:       ['Staff record has no employee ID',
                           'Payroll addresses people by staff ID. Open the record in Staff Records and check it has one.']
  };

  var OPEN = {};              // which cause groups are expanded
  var PAGE = 25;              // names shown before "show all"

  function renderBlockedGroups() {
    var groups = {};
    state.blocked.forEach(function (b) {
      (groups[b.block] = groups[b.block] || []).push(b);
    });

    $('wsBlockedTable').innerHTML = Object.keys(groups).map(function (code) {
      var people = groups[code];
      var meta   = CAUSES[code] || [code, 'Unrecognised cause — report this.'];
      var open   = !!OPEN[code];
      var shown  = open ? people : people.slice(0, PAGE);

      return '<div class="wsx-grp' + (open ? ' open' : '') + '">' +
        '<button class="wsx-grp-hd" data-grp="' + esc(code) + '">' +
          '<span class="wsx-caret">' + (open ? '▾' : '▸') + '</span>' +
          '<span class="wsx-grp-t">' + esc(meta[0]) + '</span>' +
          '<span class="wsx-grp-n">' + people.length + '</span>' +
        '</button>' +
        '<div class="wsx-grp-bd">' +
          '<p class="wsx-grp-fix">' + esc(meta[1]) + '</p>' +
          (open
            ? '<div class="wsx-names">' + shown.map(function (p) {
                return '<span class="wsx-name"><b>' + esc(p.name || '(no name on record)') +
                  '</b><i>' + esc(p.staffId) +
                  (p.department ? ' · ' + esc(p.department) : '') + '</i></span>';
              }).join('') + '</div>'
            : '') +
        '</div></div>';
    }).join('');
  }

  function load() {
    post('<?= site_url('work_schedules/get_data') ?>', {})
      .then(function (j) {
        state.schedules   = j.schedules   || [];
        state.assignments = j.assignments || [];
        state.departments = j.departments || {};
        state.weeklyOffs  = j.weeklyOffs  || [];
        state.blocked     = j.blocked     || [];
        state.staffTotal  = j.staff_total  || 0;
        state.staffActive = j.staff_active || 0;
        render();
      })
      .catch(function (e) { alertBox(e.message); });
  }

  /* ── Schedule editor ─────────────────────────────────────── */
  function openSchedule(id) {
    var s = null;
    for (var i = 0; i < state.schedules.length; i++) {
      if (state.schedules[i].scheduleId === id) { s = state.schedules[i]; break; }
    }
    $('wsSchedId').value   = s ? s.scheduleId : '';
    $('wsSchedName').value = s ? s.name : '';
    $('wsSchedTitle').textContent = s ? 'Edit schedule' : 'New schedule';

    var offs = s ? (s.weeklyOffs || []) : [];
    var sug  = $('wsSuggest');

    // D4: the tenant's declared weeklyOffs are OFFERED, never adopted. A new
    // schedule starts empty and the operator must click to accept them.
    if (!s && state.weeklyOffs.length) {
      var suggested = DAYS.filter(function (d) { return state.weeklyOffs.indexOf(d) === -1; });
      sug.hidden = false;
      sug.innerHTML = 'Your attendance settings declare <b>' +
        state.weeklyOffs.map(esc).join(', ') + '</b> as weekly offs — that would mean ticking <b>' +
        suggested.join(', ') + '</b> above. It was declared for attendance, so ' +
        'tick it yourself to use it for pay, or choose different days.';
    } else {
      sug.hidden = true;
    }

    // A chip is ON when the day is WORKED — the complement of weeklyOffs.
    // A brand-new schedule starts with nothing ticked (D4: never pre-adopted).
    $('wsSchedDays').innerHTML = DAYS.map(function (d) {
      var worked = s ? offs.indexOf(d) === -1 : false;
      return '<span class="wsx-chip' + (worked ? ' on' : '') +
        '" data-day="' + d + '">' + d + '</span>';
    }).join('');
    updateResolve();
    $('wsSchedModal').hidden = false;
  }

  /* Restate the selection in plain terms every time a chip is toggled, so an
     inverted schedule is caught on screen rather than at the next payroll run. */
  function workingDays() {
    return [].slice.call(document.querySelectorAll('#wsSchedDays .wsx-chip.on'))
      .map(function (c) { return c.dataset.day; });
  }

  /* Restate the selection in plain terms on every toggle, so a wrong schedule
     is caught on screen rather than at the next payroll run. */
  function updateResolve() {
    var working = workingDays();
    var offs    = DAYS.filter(function (d) { return working.indexOf(d) === -1; });
    var el      = $('wsResolve');

    // A month averages 52/12 = 4.345 weeks. Approximate on purpose: the real
    // figure depends on the month and on holidays, which payroll resolves per
    // employee. This is a sanity check, never an input to pay.
    var perMonth = working.length ? Math.round(working.length * 4.345) : 0;
    $('wsStatWork').textContent  = working.length;
    $('wsStatOff').textContent   = offs.length;
    $('wsStatMonth').textContent = working.length ? '≈ ' + perMonth : '—';

    if (!working.length) {
      el.className = 'wsx-resolve warn';
      el.innerHTML = '<b>No working days ticked.</b> Every day would be a weekly off, ' +
        'so nobody on this schedule could ever be paid. Tick at least one day.';
      return;
    }
    if (!offs.length) {
      el.className = 'wsx-resolve';
      el.innerHTML = 'Staff <b class="w">work all seven days</b> with no weekly off. ' +
        'Correct only for continuous-duty roles — check before saving.';
      return;
    }
    el.className = 'wsx-resolve' + (working.length < offs.length ? ' warn' : '');
    el.innerHTML = 'Staff <b class="w">work ' + working.join(', ') +
      '</b> and are <b class="o">off ' + offs.join(', ') + '</b>.' +
      (working.length < offs.length
        ? '<br><b>More days off than working days.</b> That is unusual — it is correct ' +
          'for part-time or visiting staff, but check it is what you meant before saving.'
        : '');
  }

  function saveSchedule() {
    // The UI collects WORKING days; storage keeps weeklyOffs. Derive here so
    // the stored shape and every reader stay exactly as they were.
    var working = workingDays();
    var btn = $('wsSchedSave');
    clearModalError('wsSchedErr');

    if (!working.length) { modalError('wsSchedErr', 'Tick at least one working day.'); return; }
    var offs = DAYS.filter(function (d) { return working.indexOf(d) === -1; });

    busy(btn, true);
    post('<?= site_url('work_schedules/save_schedule') ?>', {
      schedule_id: $('wsSchedId').value,
      name: $('wsSchedName').value.trim(),
      weekly_offs: offs
    }).then(function () {
      $('wsSchedModal').hidden = true;
      alertBox('Schedule saved', 'ok');
      load();
    }).catch(function (e) {
      modalError('wsSchedErr', e.message);
    }).then(function () { busy(btn, false); });
  }

  /* ── Assignment editor ───────────────────────────────────── */
  function openAssignment() {
    $('wsAsgId').value = '';
    $('wsAsgFrom').value = new Date().toISOString().slice(0, 10);
    $('wsAsgTo').value = '';
    /* No pre-selected schedule. Defaulting to the first one let a department be
       attached to the wrong schedule by simply not touching the dropdown — and
       the mistake is invisible afterwards, because a wrong schedule looks
       exactly like a right one until someone is paid on it. */
    $('wsAsgSched').innerHTML =
      '<option value="" selected disabled>— Choose a schedule —</option>' +
      state.schedules.map(function (s) {
        var offs = (s.weeklyOffs || []);
        var work = DAYS.filter(function (d) { return offs.indexOf(d) === -1; });
        return '<option value="' + esc(s.scheduleId) + '">' + esc(s.name) +
               '  (' + work.length + ' working days · off ' +
               (offs.length ? esc(offs.join(', ')) : 'none') + ')</option>';
      }).join('');
    setTargetMode('department');
    $('wsAsgModal').hidden = false;
  }

  function setTargetMode(mode) {
    [].slice.call(document.querySelectorAll('#wsAsgType button')).forEach(function (b) {
      b.classList.toggle('on', b.dataset.v === mode);
    });
    var lab = $('wsAsgTargetLab'), tgt = $('wsAsgTarget');
    if (mode === 'department') {
      lab.textContent = 'Department';
      tgt.outerHTML = '<select id="wsAsgTarget" class="wsx-inp">' +
        Object.keys(state.departments).map(function (d) {
          return '<option value="' + esc(d) + '">' + esc(d) +
            ' (' + state.departments[d] + ')</option>';
        }).join('') + '</select>';
    } else {
      lab.textContent = 'Employee ID';
      tgt.outerHTML = '<input id="wsAsgTarget" class="wsx-inp" placeholder="e.g. STA0007">';
    }
  }

  function saveAssignment() {
    var mode = document.querySelector('#wsAsgType button.on').dataset.v;
    var btn  = $('wsAsgSave');
    clearModalError('wsAsgErr');

    if (!$('wsAsgSched').value) { modalError('wsAsgErr', 'Choose a schedule first.'); return; }
    if (!$('wsAsgTarget').value.trim()) {
      modalError('wsAsgErr', mode === 'staff' ? 'Enter an employee ID.' : 'Choose a department.');
      return;
    }
    if (!$('wsAsgFrom').value) { modalError('wsAsgErr', 'Choose an effective-from date.'); return; }

    busy(btn, true);
    post('<?= site_url('work_schedules/save_assignment') ?>', {
      assignment_id: $('wsAsgId').value,
      attachment_type: mode,
      attachment_id: $('wsAsgTarget').value.trim(),
      schedule_id: $('wsAsgSched').value,
      effective_from: $('wsAsgFrom').value,
      effective_to: $('wsAsgTo').value
    }).then(function () {
      $('wsAsgModal').hidden = true;
      alertBox('Assignment saved', 'ok');
      load();
    }).catch(function (e) {
      modalError('wsAsgErr', e.message);
    }).then(function () { busy(btn, false); });
  }

  /* ── Wiring ──────────────────────────────────────────────── */
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (t.closest('#wsNew'))        { openSchedule(''); return; }
    if (t.closest('#wsAssignNew'))  {
      if (!state.schedules.length) { alertBox('Create a schedule first.'); return; }
      openAssignment(); return;
    }
    var grp = t.closest('[data-grp]');
    if (grp) { var c = grp.dataset.grp; OPEN[c] = !OPEN[c]; renderBlockedGroups(); return; }
    if (t.dataset && t.dataset.edit)  { openSchedule(t.dataset.edit); return; }
    if (t.dataset && t.dataset.day)   { t.classList.toggle('on'); updateResolve(); return; }
    if (t.closest('[data-close]'))    {
      $('wsSchedModal').hidden = true; $('wsAsgModal').hidden = true;
      $('wsConfirm').hidden = true; pendingConfirm = null;   // cancel discards the action
      return;
    }
    if (t.closest('#wsSchedSave'))    { saveSchedule(); return; }
    if (t.closest('#wsAsgSave'))      { saveAssignment(); return; }

    var seg = t.closest('#wsAsgType button');
    if (seg) { setTargetMode(seg.dataset.v); return; }

    /* Table buttons get the same busy treatment. These sit far from the page-top
       alert bar, so a refusal is also scrolled into view rather than announced
       somewhere off screen. */
    if (t.closest('#wsConfirmOk')) {
      $('wsConfirm').hidden = true;
      var fn = pendingConfirm; pendingConfirm = null;
      if (fn) fn();
      return;
    }

    if (t.dataset && t.dataset.delsched) {
      var sid = t.dataset.delsched, sbtn = t;
      var used = usageCount(sid);
      confirmAction('Delete this schedule?',
        'Deleting <b>' + esc(scheduleName(sid)) + '</b> removes it from the catalogue.' +
        (used ? ' It is currently attached in <b>' + used + '</b> place(s), so this will be refused.'
              : ' Nothing is attached to it, so nobody is affected.'),
        'Delete schedule',
        function () {
          busy(sbtn, true);
          post('<?= site_url('work_schedules/delete_schedule') ?>', { schedule_id: sid })
            .then(function () { alertBox('Schedule deleted', 'ok'); load(); })
            .catch(function (err) { busy(sbtn, false); alertBox(err.message); });
        });
      return;
    }

    if (t.dataset && t.dataset.delasg) {
      var aid = t.dataset.delasg, abtn = t;
      confirmAction('Remove this assignment?',
        'The people it covers will lose their work schedule and <b>payroll will ' +
        'refuse to pay them</b> until another one applies. They reappear in the ' +
        'blocked list immediately.',
        'Remove assignment',
        function () {
          busy(abtn, true);
          post('<?= site_url('work_schedules/delete_assignment') ?>', { assignment_id: aid })
            .then(function () { alertBox('Assignment removed', 'ok'); load(); })
            .catch(function (err) { busy(abtn, false); alertBox(err.message); });
        });
    }
  });

  load();
})();
</script>
