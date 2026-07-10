<?php defined('BASEPATH') OR exit('No direct script access allowed');

// Build a unique class list and per-class sections map for both tabs.
$_uniqueClasses = [];
$_classSections = [];           // ['Class 5' => ['A','B','C'], 'Class 6' => ['A']]
if (!empty($Classes) && is_array($Classes)) {
    foreach ($Classes as $c) {
        $cn = isset($c['class_name']) ? trim((string) $c['class_name']) : '';
        $sn = isset($c['section'])    ? trim((string) $c['section'])    : '';
        if ($cn === '' || $sn === '') continue;
        if (!in_array($cn, $_uniqueClasses, true)) {
            $_uniqueClasses[] = $cn;
            $_classSections[$cn] = [];
        }
        if (!in_array($sn, $_classSections[$cn], true)) {
            $_classSections[$cn][] = $sn;
        }
    }
    sort($_uniqueClasses);
    foreach ($_classSections as $k => &$v) { sort($v); }
    unset($v);
}
?>
<!-- Attendance Design System (shared, cacheable) — see assets/css/attendance_design_system.css -->
<link rel="stylesheet" href="<?= base_url('assets/css/attendance_design_system.css') ?>?v=2.1.0">

<style>
/* Page-local: ONLY the correction diff renderer (register-specific). All chrome
   (header, tabs, cards, tables, buttons, modals, toast, alerts) is consumed from
   the shared Attendance Design System above. */
.ac-diff { font-family: var(--att-font-m, monospace); font-size: 12px; }
.ac-diff .from { color: var(--att-red);   text-decoration: line-through; }
.ac-diff .to   { color: var(--att-green); }
</style>

<div class="content-wrapper">
<section class="content">
<div class="container-fluid">
<div class="att-wrap">
    <div class="att-header">
        <div class="att-header-left">
            <div class="att-header-icon"><i class="fa fa-shield"></i></div>
            <div>
                <div class="att-page-title">Attendance Control Panel</div>
                <div class="att-subtitle">Daily dashboard, lock control, correction approvals</div>
                <ul class="att-breadcrumb">
                    <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
                    <li><a href="<?= base_url('attendance') ?>">Attendance</a></li>
                    <li>Control Panel</li>
                </ul>
            </div>
        </div>
        <div class="att-subtitle" id="ac-today"></div>
    </div>

    <div class="att-tabs" role="tablist">
        <button class="att-tab active" data-tab="dashboard">Dashboard</button>
        <button class="att-tab" data-tab="locks">Locks</button>
        <button class="att-tab" data-tab="corrections">
            Corrections <span class="att-tag att-tag-red" id="ac-pending-badge" style="display:none">0</span>
        </button>
    </div>

    <!-- ── Dashboard ──────────────────────────────────────────── -->
    <section class="att-pane active" id="sec-dashboard">
        <div class="att-toolbar">
            <div class="att-fg"><label for="f-date">Date</label><input type="date" id="f-date"></div>
            <div class="att-fg"><label for="f-class">Class</label>
                <select id="f-class">
                    <option value="">— all —</option>
                    <?php foreach ($_uniqueClasses as $cn): ?>
                        <option value="<?= htmlspecialchars($cn, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cn, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="att-fg"><label for="f-section">Section</label>
                <select id="f-section"><option value="">— all —</option></select>
            </div>
            <div class="att-fg" style="align-self:flex-end"><button class="att-btn att-btn-primary" id="btn-apply">Apply</button></div>
            <div class="att-fg" style="align-self:flex-end"><button class="att-btn att-btn-ghost" id="btn-refresh">Refresh</button></div>
        </div>
        <div class="att-card att-card--panel">
            <div class="att-table-wrap">
                <table class="att-table" id="tbl-dashboard">
                    <thead>
                        <tr>
                            <th>Class / Section</th>
                            <th>Marked</th>
                            <th>P</th><th>A</th><th>L</th><th>Late</th>
                            <th>Drill-down</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody><tr><td colspan="8" class="att-empty">Loading…</td></tr></tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- ── Locks ──────────────────────────────────────────────── -->
    <section class="att-pane" id="sec-locks">
        <div class="att-toolbar">
            <div class="att-fg"><label for="lk-class">Class</label>
                <select id="lk-class">
                    <option value="">Select class…</option>
                    <?php foreach ($_uniqueClasses as $cn): ?>
                        <option value="<?= htmlspecialchars($cn, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cn, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="att-fg"><label for="lk-section">Section</label>
                <select id="lk-section"><option value="">Select section…</option></select>
            </div>
            <div class="att-fg"><label for="lk-date">Date</label><input type="date" id="lk-date"></div>
            <div class="att-fg" style="align-self:flex-end"><button class="att-btn att-btn-primary" id="lk-load">Load lock state</button></div>
        </div>
        <div class="att-card" id="lk-detail" style="display:none"></div>
    </section>

    <!-- ── Corrections ────────────────────────────────────────── -->
    <section class="att-pane" id="sec-corrections">
        <div class="att-toolbar">
            <div class="att-fg"><label for="cr-status">Status</label>
                <select id="cr-status">
                    <option value="pending" selected>Pending</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                    <option value="all">All</option>
                </select>
            </div>
            <div class="att-fg"><label for="cr-date">Date</label><input type="date" id="cr-date"></div>
            <div class="att-fg" style="align-self:flex-end"><button class="att-btn att-btn-primary" id="cr-load">Load</button></div>
        </div>
        <div class="att-card att-card--panel">
            <div class="att-table-wrap">
                <table class="att-table" id="tbl-corrections">
                    <thead>
                        <tr><th>Filed</th><th>Date</th><th>Student</th><th>Diff</th><th>Reason</th><th></th></tr>
                    </thead>
                    <tbody><tr><td colspan="6" class="att-empty">Click Load.</td></tr></tbody>
                </table>
            </div>
            <div style="padding:10px"><button class="att-btn att-btn-ghost" id="cr-more" style="display:none">Load more</button></div>
        </div>
    </section>
</div>

<!-- Lock dialog -->
<div class="att-modal-overlay" id="modal-lock"><div class="att-modal">
    <h3 id="lock-modal-title">Lock / Unlock</h3>
    <div id="lock-modal-body"></div>
    <label>Action</label>
    <div>
        <label style="display:inline-flex;gap:6px;align-items:center;font-size:13px;color:var(--att-t1);margin-right:14px">
            <input type="radio" name="lk-action" value="true"> Lock
        </label>
        <label style="display:inline-flex;gap:6px;align-items:center;font-size:13px;color:var(--att-t1)">
            <input type="radio" name="lk-action" value="false" checked> Unlock
        </label>
    </div>
    <label>Reason <span class="att-mute">(min 10 chars; required for unlock)</span></label>
    <textarea id="lk-reason" placeholder="Reason…"></textarea>
    <div id="lk-warn" class="att-alert att-alert-warn" style="display:none"></div>
    <div class="att-modal-actions">
        <button class="att-btn att-btn-ghost" data-close="modal-lock">Cancel</button>
        <button class="att-btn att-btn-primary" id="lk-apply">Apply</button>
    </div>
</div></div>

<!-- Approve dialog -->
<div class="att-modal-overlay" id="modal-approve"><div class="att-modal">
    <h3>Approve correction</h3>
    <div id="approve-body"></div>
    <label>Note <span class="att-mute">(optional)</span></label>
    <input type="text" id="ap-note" placeholder="Why approving…">
    <div id="ap-drift" class="att-alert att-alert-warn" style="display:none"></div>
    <label id="ap-force-label" style="display:none">
        <input type="checkbox" id="ap-force"> Force apply despite drift
    </label>
    <div class="att-modal-actions">
        <button class="att-btn att-btn-ghost" data-close="modal-approve">Cancel</button>
        <button class="att-btn att-btn-danger" id="ap-reject">Reject</button>
        <button class="att-btn att-btn-success" id="ap-approve">Approve</button>
    </div>
</div></div>

</div><!-- /.container-fluid -->
</section><!-- /.content -->
</div><!-- /.content-wrapper -->

<div class="att-toast" id="toast"></div>

<script>
(function(){
    'use strict';
    var BASE = '<?= site_url('attendance') ?>';
    var CSRF_NAME = '<?= $this->security->get_csrf_token_name() ?>';
    var csrf = '<?= $this->security->get_csrf_hash() ?>';
    var CLASS_SECTIONS = <?= json_encode($_classSections, JSON_UNESCAPED_UNICODE) ?>;

    // ── shared helpers ───────────────────────────────────────────
    function $(sel, root){ return (root||document).querySelector(sel); }
    function $$(sel, root){ return Array.from((root||document).querySelectorAll(sel)); }
    function todayIso(){ return new Date().toISOString().slice(0,10); }
    function toast(msg, kind){
        var t = $('#toast'); t.textContent = msg;
        t.className = 'att-toast show ' + (kind === 'error' ? 'error' : 'success');
        clearTimeout(toast._t);
        toast._t = setTimeout(function(){ t.className = 'att-toast'; }, 3500);
    }
    function api(method, path, body){
        var opts = { method: method, credentials: 'same-origin', headers: {} };
        if (body) {
            var fd = new FormData();
            Object.keys(body).forEach(function(k){
                var v = body[k];
                fd.append(k, (typeof v === 'object' && v !== null) ? JSON.stringify(v) : v);
            });
            fd.append(CSRF_NAME, csrf);
            opts.body = fd;
        }
        return fetch(BASE + '/' + path, opts).then(function(r){
            return r.json().then(function(j){
                // refresh CSRF token from response
                if (j && j.csrf_token) csrf = j.csrf_token;
                return { ok: r.ok, status: r.status, body: j };
            });
        });
    }

    // ── Tabs ─────────────────────────────────────────────────────
    $$('.att-tab').forEach(function(btn){
        btn.addEventListener('click', function(){
            $$('.att-tab').forEach(function(b){ b.classList.toggle('active', b === btn); });
            var tab = btn.getAttribute('data-tab');
            $$('.att-pane').forEach(function(s){
                s.classList.toggle('active', s.id === 'sec-' + tab);
            });
            if (tab === 'corrections') loadCorrections();
            if (tab === 'locks')       loadLockSelectors();
        });
    });

    // ── Modal close ─────────────────────────────────────────────
    document.addEventListener('click', function(e){
        var c = e.target.getAttribute('data-close');
        if (c) $('#' + c).classList.remove('open');
    });

    // ── Today header ────────────────────────────────────────────
    $('#ac-today').textContent = 'Today: ' + todayIso();
    $('#f-date').value  = todayIso();
    $('#lk-date').value = todayIso();

    // ── DASHBOARD ───────────────────────────────────────────────
    // Build the (class, section) list to query based on current filters.
    function dashboardTargets() {
        var classFilter   = $('#f-class').value;
        var sectionFilter = $('#f-section').value;
        var targets = [];
        if (classFilter && sectionFilter) {
            targets.push({ cls: classFilter, sec: sectionFilter });
        } else if (classFilter) {
            (CLASS_SECTIONS[classFilter] || []).forEach(function(s){
                targets.push({ cls: classFilter, sec: s });
            });
        } else {
            // No filter — every (class, section)
            Object.keys(CLASS_SECTIONS).forEach(function(c){
                (CLASS_SECTIONS[c] || []).forEach(function(s){
                    targets.push({ cls: c, sec: s });
                });
            });
        }
        return targets;
    }

    function fetchSummary(date, cls, sec) {
        return fetch(BASE + '/summary?class=' + encodeURIComponent(cls)
                          + '&section=' + encodeURIComponent(sec)
                          + '&date=' + encodeURIComponent(date),
                     { credentials: 'same-origin' })
            .then(function(r){ return r.ok ? r.json() : null; })
            .then(function(j){
                if (!j || j.status !== 'success') return null;
                return { cls: cls, sec: sec, p: j.present|0, a: j.absent|0, l: j.leave|0,
                         late: j.late|0, total: j.total|0 };
            })
            .catch(function(){ return null; });
    }

    function loadDashboard() {
        var date = $('#f-date').value || todayIso();
        var targets = dashboardTargets();
        var tbody = $('#tbl-dashboard tbody');

        if (targets.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="att-empty">No classes configured.</td></tr>';
            refreshPendingBadge();
            return;
        }

        tbody.innerHTML = '<tr><td colspan="8" class="att-empty">Loading ' + targets.length + ' section(s)…</td></tr>';

        Promise.all(targets.map(function(t){ return fetchSummary(date, t.cls, t.sec); }))
            .then(function(rows){
                rows = rows.filter(function(r){ return r !== null; });
                // Sort by class, then section
                rows.sort(function(a,b){
                    if (a.cls !== b.cls) return a.cls.localeCompare(b.cls);
                    return a.sec.localeCompare(b.sec);
                });

                if (rows.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="8" class="att-empty">No attendance data for ' + date + '.</td></tr>';
                    return;
                }

                var totals = { p:0, a:0, l:0, late:0, total:0 };
                var html = '';
                rows.forEach(function(r){
                    totals.p     += r.p;
                    totals.a     += r.a;
                    totals.l     += r.l;
                    totals.late  += r.late;
                    totals.total += r.total;
                    var marked = r.total > 0 ? r.total + ' marked' : '<span class="att-mute">not marked</span>';
                    html += '<tr>'
                        + '<td><b>' + r.cls + '</b> / ' + r.sec + '</td>'
                        + '<td>' + marked + '</td>'
                        + '<td>' + r.p + '</td>'
                        + '<td>' + r.a + '</td>'
                        + '<td>' + r.l + '</td>'
                        + '<td>' + r.late + '</td>'
                        + '<td><a href="#" class="att-mute sa-jump-lock" data-cls="' + r.cls + '" data-sec="' + r.sec + '">Open in Locks ›</a></td>'
                        + '<td></td>'
                        + '</tr>';
                });
                // Footer total
                html += '<tr style="background:var(--att-bg3);font-weight:600">'
                     +  '<td>Total (' + rows.length + ' sections)</td>'
                     +  '<td>' + totals.total + '</td>'
                     +  '<td>' + totals.p + '</td>'
                     +  '<td>' + totals.a + '</td>'
                     +  '<td>' + totals.l + '</td>'
                     +  '<td>' + totals.late + '</td>'
                     +  '<td></td><td></td>'
                     +  '</tr>';
                tbody.innerHTML = html;
            });

        refreshPendingBadge();
    }

    // Click-through from a Dashboard row to the Locks tab pre-filtered
    document.addEventListener('click', function(e){
        if (!e.target.classList.contains('sa-jump-lock')) return;
        e.preventDefault();
        var cls = e.target.getAttribute('data-cls');
        var sec = e.target.getAttribute('data-sec');
        $('#lk-class').value = cls;
        // Trigger change so the section dropdown re-fills, then set section
        $('#lk-class').dispatchEvent(new Event('change'));
        $('#lk-section').value = sec;
        $('#lk-date').value = $('#f-date').value || todayIso();
        // Switch to Locks tab
        var locksTab = document.querySelector('.att-tab[data-tab="locks"]');
        if (locksTab) locksTab.click();
        $('#lk-load').click();
    });
    $('#btn-apply').addEventListener('click', loadDashboard);
    $('#btn-refresh').addEventListener('click', loadDashboard);

    function refreshPendingBadge(){
        // Pull up to 100 — anything more rare in practice and we can show "99+".
        api('GET', 'correction/list?status=pending&limit=100').then(function(res){
            if (!res.ok) return;
            var rows = (res.body && res.body.requests) || [];
            var n = rows.length;
            // If page is full and nextCursor exists there are more pending.
            var more = !!(res.body && res.body.nextCursor);
            var b = $('#ac-pending-badge');
            if (n > 0) {
                b.style.display = 'inline-block';
                b.textContent = more ? (n + '+') : String(n);
            } else {
                b.style.display = 'none';
            }
        });
    }

    // ── Section-by-class dropdown wiring (Dashboard + Locks) ────
    function fillSectionsFor(classVal, $sectionEl, includeAllOption) {
        // Reset; preserve "— all —" or "Select section…" placeholder
        var current = $sectionEl.value;
        var sections = (CLASS_SECTIONS && CLASS_SECTIONS[classVal]) || [];
        var html = includeAllOption
            ? '<option value="">— all —</option>'
            : '<option value="">Select section…</option>';
        for (var i = 0; i < sections.length; i++) {
            var s = sections[i];
            html += '<option value="' + s + '">' + s + '</option>';
        }
        $sectionEl.innerHTML = html;
        // Restore previous if still valid
        if (current && sections.indexOf(current) !== -1) $sectionEl.value = current;
    }
    $('#f-class').addEventListener('change', function(){
        fillSectionsFor(this.value, $('#f-section'), true);
    });
    $('#lk-class').addEventListener('change', function(){
        fillSectionsFor(this.value, $('#lk-section'), false);
    });

    function loadLockSelectors(){ /* dropdowns are pre-populated by PHP; sections fill on class change */ }

    $('#lk-load').addEventListener('click', function(){
        var cls = $('#lk-class').value;
        var sec = $('#lk-section').value;
        var date = $('#lk-date').value;
        if (!cls || !sec || !date) return toast('Pick class, section, date.', 'error');

        api('GET', 'lock?class=' + encodeURIComponent(cls)
                  + '&section=' + encodeURIComponent(sec)
                  + '&date='    + encodeURIComponent(date)
        ).then(function(res){
            if (!res.ok) return toast(res.body.message || 'Load failed.', 'error');
            renderLockDetail(cls, sec, date, res.body);
        });
    });

    function renderLockDetail(cls, sec, date, body){
        var detail = $('#lk-detail');
        detail.style.display = 'block';

        var stage = body.stage || 'UNKNOWN';
        var pillCls = stage === 'S1_FREE' ? 'att-tag-green'
                    : stage === 'S2_RESTRICTED' ? 'att-tag-amber'
                    : stage === 'S3_LOCKED' ? 'att-tag-red' : 'att-tag-gray';

        var lk = body.lock || {};
        var lockedHtml = lk.locked
            ? ('🔴 Locked by ' + (lk.lockedBy || '?') + ' at ' + (lk.lockedAt || '?')
               + (lk.lockReason ? ' — ' + lk.lockReason : ''))
            : (lk.unlockedAt
                ? ('🟣 Unlocked by ' + (lk.unlockedBy || '?') + ' at ' + (lk.unlockedAt || '?')
                   + (lk.unlockReason ? ' — ' + lk.unlockReason : ''))
                : '⚪ No lock doc (default state)');

        detail.innerHTML = ''
            + '<div style="display:flex;justify-content:space-between;align-items:center;gap:14px">'
            + '  <div>'
            + '    <div style="font-weight:600;color:var(--att-t1)">' + cls + ' / ' + sec + '</div>'
            + '    <div class="att-mute" style="margin-top:4px">' + date + '</div>'
            + '    <div style="margin-top:8px">' + lockedHtml + '</div>'
            + '  </div>'
            + '  <div>'
            + '    <span class="att-tag ' + pillCls + '">' + stage + '</span>'
            + '    <button class="att-btn att-btn-primary" id="open-lock-modal" style="margin-left:10px">Lock / Unlock</button>'
            + '  </div>'
            + '</div>';

        $('#open-lock-modal').addEventListener('click', function(){
            $('#lock-modal-title').textContent = 'Lock / Unlock — ' + cls + ' / ' + sec + ' • ' + date;
            $('#lock-modal-body').innerHTML = '<div class="att-mute">Current: ' + lockedHtml + '</div>';
            $('#lk-reason').value = '';
            $('#lk-warn').style.display = 'none';
            // Default action is opposite of current
            var current = !!lk.locked;
            $$('input[name=lk-action]').forEach(function(r){ r.checked = (r.value === (current ? 'false' : 'true')); });

            $('#lk-apply').onclick = function(){
                var lockedNew = $('input[name=lk-action]:checked').value === 'true';
                var reason = $('#lk-reason').value.trim();
                if (!lockedNew && reason.length < 10) {
                    return toast('Unlock reason must be ≥10 chars.', 'error');
                }
                api('POST', 'lock/set', {
                    'class':   cls,
                    'section': sec,
                    'date':    date,
                    'locked':  lockedNew ? 'true' : 'false',
                    'reason':  reason
                }).then(function(res){
                    if (!res.ok) return toast(res.body.message || 'Failed.', 'error');
                    $('#modal-lock').classList.remove('open');
                    toast(lockedNew ? 'Locked.' : 'Unlocked.');
                    renderLockDetail(cls, sec, date, res.body);
                });
            };
            $('#modal-lock').classList.add('open');
        });
    }

    // ── CORRECTIONS ─────────────────────────────────────────────
    var cursorState = null;

    function fmtMark(m){
        if (!m || !m.status) return '—';
        var lateBit = m.late ? (' (Late' + (m.lateMinutes ? ' +' + m.lateMinutes + 'm' : '') + ')') : '';
        return ({P:'Present', A:'Absent', L:'Leave'}[m.status] || m.status) + lateBit;
    }
    function diffHtml(curM, reqM){
        return '<div class="ac-diff"><span class="from">' + fmtMark(curM)
             + '</span> → <span class="to">' + fmtMark(reqM) + '</span></div>';
    }

    function loadCorrections(append){
        var status = $('#cr-status').value;
        var date   = $('#cr-date').value;
        var qs = 'status=' + status + '&limit=25';
        if (date) qs += '&date=' + date;
        if (append && cursorState) qs += '&cursor=' + cursorState;

        api('GET', 'correction/list?' + qs).then(function(res){
            if (!res.ok) return toast(res.body.message || 'Load failed.', 'error');
            renderCorrections(res.body, append);
        });
    }
    $('#cr-load').addEventListener('click', function(){ cursorState = null; loadCorrections(false); });
    $('#cr-more').addEventListener('click', function(){ loadCorrections(true); });

    function renderCorrections(body, append){
        var tbody = $('#tbl-corrections tbody');
        if (!append) tbody.innerHTML = '';
        var rows = body.requests || [];
        if (!append && rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="att-empty">No requests.</td></tr>';
            $('#cr-more').style.display = 'none';
            return;
        }
        rows.forEach(function(r){
            var tr = document.createElement('tr');
            var actions = (r.status === 'pending')
                ? '<button class="att-btn att-btn-ghost" data-decide="' + (r.requestId || '') + '">Review</button>'
                : '<span class="att-mute">' + r.status + '</span>';
            tr.innerHTML = ''
                + '<td><span class="att-mute">' + (r.requestedAt || '').slice(0,16).replace('T',' ') + '</span></td>'
                + '<td>' + (r.date || '') + '</td>'
                + '<td>' + (r.studentName || r.studentId || '') + '</td>'
                + '<td>' + diffHtml(r.currentMark, r.requestedMark) + '</td>'
                + '<td>' + (r.reason || '') + '</td>'
                + '<td>' + actions + '</td>';
            // Stash the full row for the modal
            tr._req = r;
            tbody.appendChild(tr);
        });
        cursorState = body.nextCursor;
        $('#cr-more').style.display = body.nextCursor ? 'inline-block' : 'none';
    }

    // Delegated click for review button
    $('#tbl-corrections').addEventListener('click', function(e){
        if (!e.target.matches('[data-decide]')) return;
        var tr = e.target.closest('tr'); if (!tr || !tr._req) return;
        openApproveModal(tr._req);
    });

    function openApproveModal(req){
        $('#approve-body').innerHTML = ''
            + '<div><b>' + (req.studentName || req.studentId) + '</b> on ' + req.date + '</div>'
            + '<div style="margin-top:6px">' + diffHtml(req.currentMark, req.requestedMark) + '</div>'
            + '<div class="att-mute" style="margin-top:6px">Reason: ' + (req.reason || '') + '</div>';
        $('#ap-note').value = '';
        $('#ap-force').checked = false;
        $('#ap-drift').style.display = 'none';
        $('#ap-force-label').style.display = 'none';

        var rid = req.requestId || req.id || '';
        if (!rid) {
            // Fall back to building doc id from filed time — server is the source of truth though
            // so we surface a helpful error if it's missing.
            toast('Request id missing on row; refresh and retry.', 'error');
            return;
        }

        function decide(decision, force){
            // Loading feedback: disable both actions and spin the clicked one until
            // the decide round-trip resolves. Restore on every non-success path
            // (drift-409, server error, network reject) so the modal stays usable.
            var actBtn   = decision === 'approve' ? $('#ap-approve') : $('#ap-reject');
            var otherBtn = decision === 'approve' ? $('#ap-reject')  : $('#ap-approve');
            var actOrig  = actBtn ? actBtn.innerHTML : '';
            function restore(){
                if (actBtn)   { actBtn.disabled = false; actBtn.innerHTML = actOrig; }
                if (otherBtn) { otherBtn.disabled = false; }
            }
            if (otherBtn) otherBtn.disabled = true;
            if (actBtn) {
                actBtn.disabled = true;
                actBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> ' + (decision === 'approve' ? 'Approving…' : 'Rejecting…');
            }
            var body = { 'requestId': rid, 'decision': decision, 'note': $('#ap-note').value.trim() };
            if (force) body.force = 'true';
            api('POST', 'correction/decide', body).then(function(res){
                if (res.status === 409 && res.body && res.body.expected && res.body.current) {
                    // Drift — show diff and force toggle
                    $('#ap-drift').style.display = 'block';
                    $('#ap-drift').innerHTML =
                        '⚠ Live attendance has drifted since this request was filed.<br>'
                        + '<b>Expected:</b> ' + fmtMark(res.body.expected) + '<br>'
                        + '<b>Now:</b> '       + fmtMark(res.body.current);
                    $('#ap-force-label').style.display = 'block';
                    restore();
                    return;
                }
                if (!res.ok) { restore(); return toast(res.body.message || 'Failed.', 'error'); }
                $('#modal-approve').classList.remove('open');
                toast('Decision: ' + (res.body.decision || decision));
                cursorState = null;
                loadCorrections(false);
                refreshPendingBadge();
            }).catch(function(){
                restore();
                toast('Request failed. Please retry.', 'error');
            });
        }
        $('#ap-approve').onclick = function(){ decide('approve', $('#ap-force').checked); };
        $('#ap-reject').onclick  = function(){ decide('reject',  false); };
        $('#modal-approve').classList.add('open');
    }

    // ── boot ─────────────────────────────────────────────────────
    // Phase-2 IA: Corrections moved to the Approvals shell.
    //  • ?only=corrections  → Approvals embed: show ONLY the Corrections tab AND
    //    skip loadDashboard() (which fetches a summary for EVERY class-section —
    //    pure waste when only Corrections is shown; this was the slow/blank frame).
    //  • otherwise (standalone Oversight) → hide the Corrections tab so Control
    //    reads as Summary + Locks, and boot the dashboard as before.
    (function(){
        var qs   = new URLSearchParams(location.search || '');
        var only = (qs.get('only') || '').trim();
        function tabBtn(k){ return document.querySelector('.att-tab[data-tab="' + k + '"]'); }
        if (only === 'corrections') {
            ['dashboard','locks'].forEach(function(k){ var b = tabBtn(k); if (b) b.style.display = 'none'; });
            var c = tabBtn('corrections'); if (c) c.click();   // fires loadCorrections() only
            return;
        }
        loadDashboard();                                       // full Control page
        var cb = tabBtn('corrections'); if (cb) cb.style.display = 'none';   // moved to Approvals
        var h = (location.hash || '').replace('#','').trim();
        if (h && h !== 'corrections') { var t = tabBtn(h); if (t) t.click(); }
    })();
})();
</script>
