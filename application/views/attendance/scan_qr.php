<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<style>
    .scan-wrap { max-width: 640px; margin: 0 auto; padding: 24px 20px; }
    .scan-hdr h1 { margin: 0 0 4px; font-size: 1.2rem; color: var(--t1); font-family: var(--font-b); }
    .scan-hdr p  { margin: 0 0 18px; color: var(--t3); font-size: .9rem; }

    .scan-card { background: var(--bg2); border: 1px solid var(--border); border-radius: 10px; padding: 20px; }

    .scan-input { width: 100%; padding: 12px 14px; border: 1.5px solid var(--border); border-radius: 8px;
                  font-family: 'Courier New', monospace; font-size: .95rem; background: var(--bg);
                  color: var(--t1); box-sizing: border-box; }
    .scan-input:focus { outline: none; border-color: var(--gold); }

    .scan-actions { display: flex; gap: 10px; margin-top: 12px; }
    .scan-btn { flex: 1; padding: 12px 16px; border: none; border-radius: 8px; cursor: pointer;
                font-weight: 600; font-size: .9rem; }
    .scan-btn.primary { background: #0f766e; color: #fff; }
    .scan-btn.primary:hover { background: #115e59; }
    .scan-btn.ghost { background: var(--bg3); color: var(--t2); border: 1px solid var(--border); }

    .scan-result { margin-top: 18px; padding: 14px 16px; border-radius: 8px; display: none;
                   font-size: .92rem; line-height: 1.5; }
    .scan-result.ok    { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
    .scan-result.dup   { background: #fef9c3; color: #854d0e; border: 1px solid #fde047; }
    .scan-result.err   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

    .scan-history { margin-top: 24px; }
    .scan-history h3 { font-size: .9rem; color: var(--t2); margin: 0 0 8px; font-weight: 600; }
    .scan-row { padding: 10px 12px; border: 1px solid var(--border); border-radius: 6px;
                background: var(--bg); margin-bottom: 6px; font-size: .85rem;
                display: flex; align-items: center; gap: 10px; }
    .scan-row.ok    { border-left: 3px solid #16a34a; }
    .scan-row.dup   { border-left: 3px solid #ca8a04; }
    .scan-row.err   { border-left: 3px solid #dc2626; }
    .scan-row .who    { flex: 1; font-weight: 600; color: var(--t1); }
    .scan-row .when   { color: var(--t3); font-size: .78rem; }
    .scan-row .badge  { font-size: .7rem; padding: 2px 8px; border-radius: 12px; font-weight: 700;
                        text-transform: uppercase; letter-spacing: .04em; }
    .scan-row.ok  .badge { background: #dcfce7; color: #166534; }
    .scan-row.dup .badge { background: #fef9c3; color: #854d0e; }
    .scan-row.err .badge { background: #fee2e2; color: #991b1b; }

    .scan-hint { font-size: .82rem; color: var(--t3); margin-top: 14px; line-height: 1.5; }
</style>

<div class="content-wrapper">
<div class="scan-wrap">

    <div class="scan-hdr">
        <h1><i class="fa fa-qrcode" style="color: var(--gold); margin-right: 8px;"></i>Attendance — QR Scan</h1>
        <p>Scan a student's ID-card QR (or paste the encoded token below) to mark Present for today.</p>
    </div>

    <div class="scan-card">
        <label for="scanInput" style="font-size: .85rem; color: var(--t2); font-weight: 600; display: block; margin-bottom: 8px;">
            QR token
        </label>
        <input id="scanInput" class="scan-input" type="text" autofocus
               placeholder="Paste or scan QR token here (form auto-submits on Enter)">

        <div class="scan-actions">
            <button id="scanBtn"   class="scan-btn primary" type="button"><i class="fa fa-check"></i> Mark Present</button>
            <button id="scanClear" class="scan-btn ghost"   type="button">Clear</button>
        </div>

        <div id="scanResult" class="scan-result" role="status" aria-live="polite"></div>

        <p class="scan-hint">
            <i class="fa fa-info-circle"></i>
            A USB or Bluetooth QR scanner will paste the token directly into the box and trigger Enter.
            Manual paste also works for testing. Camera-based scanning is a planned follow-up.
        </p>
    </div>

    <div class="scan-history">
        <h3>This session</h3>
        <div id="scanHistory"></div>
    </div>

</div>
</div>

<script>
(function () {
    'use strict';

    var endpoint  = '<?= base_url('attendance/scan_qr') ?>';
    var csrfName  = '<?= $this->security->get_csrf_token_name() ?>';
    var csrfToken = '<?= $this->security->get_csrf_hash() ?>';

    var inputEl   = document.getElementById('scanInput');
    var btnEl     = document.getElementById('scanBtn');
    var clearEl   = document.getElementById('scanClear');
    var resultEl  = document.getElementById('scanResult');
    var historyEl = document.getElementById('scanHistory');

    function showResult(kind, html) {
        resultEl.className = 'scan-result ' + kind;
        resultEl.style.display = 'block';
        resultEl.innerHTML = html;
    }

    function appendHistory(kind, badge, who, msg) {
        var row = document.createElement('div');
        row.className = 'scan-row ' + kind;
        var t = new Date();
        var when = t.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        row.innerHTML =
            '<span class="badge">' + badge + '</span>' +
            '<span class="who">' + escapeHtml(who) + '</span>' +
            '<span class="when">' + when + '</span>';
        if (msg) row.title = msg;
        historyEl.insertBefore(row, historyEl.firstChild);
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function submit() {
        var token = (inputEl.value || '').trim();
        if (!token) {
            showResult('err', '<i class="fa fa-exclamation-circle"></i> Token is empty.');
            inputEl.focus();
            return;
        }

        btnEl.disabled = true;
        btnEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Marking...';

        var body = new URLSearchParams();
        body.append('qr_token', token);
        body.append(csrfName, csrfToken);

        fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
        })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, body: d }; }); })
        .then(function (env) {
            var d = env.body || {};
            if (d.csrf_token) csrfToken = d.csrf_token;

            if (d.status === 'success' && d.code === 'success') {
                showResult('ok', '<i class="fa fa-check-circle"></i> ' +
                    'Marked Present: <strong>' + escapeHtml(d.student_name) +
                    '</strong> (' + escapeHtml(d.student_id) + ') — ' +
                    escapeHtml(d.class) + ' / ' + escapeHtml(d.section));
                appendHistory('ok', 'Present', d.student_name + ' (' + d.student_id + ')', d.message);
            } else if (d.status === 'success' && d.code === 'already_marked') {
                showResult('dup', '<i class="fa fa-info-circle"></i> ' +
                    '<strong>' + escapeHtml(d.student_name) + '</strong> already marked Present today.');
                appendHistory('dup', 'Already', d.student_name + ' (' + d.student_id + ')', d.message);
            } else {
                var msg = (d && d.message) ? d.message : 'Could not mark attendance.';
                showResult('err', '<i class="fa fa-times-circle"></i> ' + escapeHtml(msg));
                appendHistory('err', 'Error', msg, '');
            }
        })
        .catch(function () {
            showResult('err', '<i class="fa fa-times-circle"></i> Network error. Please retry.');
            appendHistory('err', 'Network', 'Network error', '');
        })
        .finally(function () {
            btnEl.disabled = false;
            btnEl.innerHTML = '<i class="fa fa-check"></i> Mark Present';
            // Clear + refocus so the next scan is ready.
            inputEl.value = '';
            inputEl.focus();
        });
    }

    btnEl.addEventListener('click', submit);
    clearEl.addEventListener('click', function () {
        inputEl.value = '';
        resultEl.style.display = 'none';
        inputEl.focus();
    });
    // USB/Bluetooth scanners typically suffix Enter — auto-submit on it.
    inputEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            submit();
        }
    });
})();
</script>
