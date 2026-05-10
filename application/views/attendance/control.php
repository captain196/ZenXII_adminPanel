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
<style>
:root {
    --ac-primary: var(--gold);
    --ac-bg:  var(--bg);
    --ac-bg2: var(--bg2);
    --ac-bg3: var(--bg3);
    --ac-border: var(--border);
    --ac-t1: var(--t1);
    --ac-t2: var(--t2);
    --ac-t3: var(--t3);
    --ac-shadow: var(--sh);
    --ac-radius: 10px;
    --ac-green: #16a34a;
    --ac-red:   #dc2626;
    --ac-amber: #d97706;
    --ac-blue:  #2563eb;
    --ac-purple: #7c3aed;
}
.ac-wrap { padding: 20px 22px 40px; }
.ac-header {
    display: flex; justify-content: space-between; align-items: center;
    gap: 14px; padding: 16px 22px; margin-bottom: 18px;
    background: var(--ac-bg2); border: 1px solid var(--ac-border);
    border-radius: var(--ac-radius); box-shadow: var(--ac-shadow);
}
.ac-title { font-size: 18px; font-weight: 600; color: var(--ac-t1); }
.ac-sub   { color: var(--ac-t3); font-size: 13px; }
.ac-tabs {
    display: flex; gap: 4px; padding: 4px; margin-bottom: 16px;
    background: var(--ac-bg2); border: 1px solid var(--ac-border);
    border-radius: var(--ac-radius); width: fit-content;
}
.ac-tab {
    padding: 8px 16px; border-radius: 6px; cursor: pointer;
    color: var(--ac-t2); font-size: 13px; font-weight: 500;
    background: transparent; border: 0;
}
.ac-tab.active { background: var(--ac-primary); color: #000; }
.ac-tab .badge {
    margin-left: 6px; padding: 1px 6px; border-radius: 8px;
    background: var(--ac-red); color: #fff; font-size: 11px;
}

.ac-section { display: none; }
.ac-section.active { display: block; }

.ac-card {
    background: var(--ac-bg2); border: 1px solid var(--ac-border);
    border-radius: var(--ac-radius); padding: 16px 18px; margin-bottom: 14px;
    box-shadow: var(--ac-shadow);
}

.ac-filters { display: flex; gap: 10px; flex-wrap: wrap; align-items: end; }
.ac-filters label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: var(--ac-t3); }
.ac-filters select, .ac-filters input[type=date] {
    padding: 7px 10px; border-radius: 6px; border: 1px solid var(--ac-border);
    background: var(--ac-bg3); color: var(--ac-t1); font-size: 13px; min-width: 130px;
}
.ac-btn {
    padding: 7px 14px; border-radius: 6px; border: 0; cursor: pointer;
    font-size: 13px; font-weight: 500; background: var(--ac-primary); color: #000;
}
.ac-btn.secondary { background: var(--ac-bg3); color: var(--ac-t1); border: 1px solid var(--ac-border); }
.ac-btn.danger  { background: var(--ac-red); color: #fff; }
.ac-btn.success { background: var(--ac-green); color: #fff; }

.ac-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.ac-table th, .ac-table td {
    padding: 9px 10px; border-bottom: 1px solid var(--ac-border); text-align: left;
}
.ac-table th { color: var(--ac-t3); font-weight: 500; font-size: 12px; text-transform: uppercase; }
.ac-table tr:hover { background: var(--ac-bg3); }

.ac-pill {
    display: inline-block; padding: 2px 9px; border-radius: 12px;
    font-size: 11px; font-weight: 600;
}
.ac-pill.s1 { background: rgba(22,163,74,0.15);  color: var(--ac-green); }
.ac-pill.s2 { background: rgba(217,119,6,0.15);  color: var(--ac-amber); }
.ac-pill.s3 { background: rgba(220,38,38,0.15);  color: var(--ac-red); }
.ac-pill.unlocked { background: rgba(124,58,237,0.15); color: var(--ac-purple); }
.ac-pill.none { background: var(--ac-bg3); color: var(--ac-t3); }

.ac-diff { font-family: monospace; font-size: 12px; }
.ac-diff .from { color: var(--ac-red);   text-decoration: line-through; }
.ac-diff .to   { color: var(--ac-green); }

/* Modal */
.ac-modal {
    position: fixed; inset: 0; background: rgba(0,0,0,0.5);
    display: none; align-items: center; justify-content: center; z-index: 999;
}
.ac-modal.show { display: flex; }
.ac-modal-card {
    background: var(--ac-bg2); border: 1px solid var(--ac-border);
    border-radius: var(--ac-radius); padding: 20px; min-width: 420px; max-width: 600px;
    box-shadow: 0 20px 50px rgba(0,0,0,0.4);
}
.ac-modal h3 { margin: 0 0 12px; font-size: 16px; color: var(--ac-t1); }
.ac-modal label { display: block; font-size: 12px; color: var(--ac-t3); margin: 12px 0 4px; }
.ac-modal textarea, .ac-modal input[type=text] {
    width: 100%; padding: 8px 10px; border: 1px solid var(--ac-border);
    border-radius: 6px; background: var(--ac-bg3); color: var(--ac-t1); font-size: 13px;
    box-sizing: border-box;
}
.ac-modal textarea { min-height: 80px; resize: vertical; }
.ac-modal .actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; }

.ac-warn {
    padding: 9px 12px; border-radius: 6px; font-size: 12px; margin: 12px 0;
    background: rgba(217,119,6,0.12); color: var(--ac-amber); border: 1px solid rgba(217,119,6,0.3);
}

.ac-toast {
    position: fixed; bottom: 24px; right: 24px; padding: 12px 16px;
    background: var(--ac-bg2); border: 1px solid var(--ac-border); border-radius: 6px;
    color: var(--ac-t1); font-size: 13px; box-shadow: var(--ac-shadow);
    transform: translateY(100px); opacity: 0; transition: all 0.2s;
}
.ac-toast.show   { transform: translateY(0); opacity: 1; }
.ac-toast.error  { border-left: 3px solid var(--ac-red); }
.ac-toast.ok     { border-left: 3px solid var(--ac-green); }

.ac-mute  { color: var(--ac-t3); font-size: 12px; }
.ac-empty { padding: 30px; text-align: center; color: var(--ac-t3); }
</style>

<div class="content-wrapper">
<section class="content">
<div class="container-fluid">
<div class="ac-wrap">
    <div class="ac-header">
        <div>
            <div class="ac-title">Attendance Control Panel</div>
            <div class="ac-sub">Daily dashboard, lock control, correction approvals</div>
        </div>
        <div class="ac-sub" id="ac-today"></div>
    </div>

    <div class="ac-tabs" role="tablist">
        <button class="ac-tab active" data-tab="dashboard">Dashboard</button>
        <button class="ac-tab" data-tab="locks">Locks</button>
        <button class="ac-tab" data-tab="corrections">
            Corrections <span class="badge" id="ac-pending-badge" style="display:none">0</span>
        </button>
    </div>

    <!-- ── Dashboard ──────────────────────────────────────────── -->
    <section class="ac-section active" id="sec-dashboard">
        <div class="ac-card">
            <div class="ac-filters">
                <label>Date <input type="date" id="f-date"></label>
                <label>Class
                    <select id="f-class">
                        <option value="">— all —</option>
                        <?php foreach ($_uniqueClasses as $cn): ?>
                            <option value="<?= htmlspecialchars($cn, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cn, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Section
                    <select id="f-section">
                        <option value="">— all —</option>
                    </select>
                </label>
                <button class="ac-btn" id="btn-apply">Apply</button>
                <button class="ac-btn secondary" id="btn-refresh">Refresh</button>
            </div>
        </div>
        <div class="ac-card">
            <table class="ac-table" id="tbl-dashboard">
                <thead>
                    <tr>
                        <th>Class / Section</th>
                        <th>Marked</th>
                        <th>P</th><th>A</th><th>L</th><th>Late</th>
                        <th>Drill-down</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody><tr><td colspan="8" class="ac-empty">Loading…</td></tr></tbody>
            </table>
        </div>
    </section>

    <!-- ── Locks ──────────────────────────────────────────────── -->
    <section class="ac-section" id="sec-locks">
        <div class="ac-card">
            <div class="ac-filters">
                <label>Class
                    <select id="lk-class">
                        <option value="">Select class…</option>
                        <?php foreach ($_uniqueClasses as $cn): ?>
                            <option value="<?= htmlspecialchars($cn, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cn, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Section
                    <select id="lk-section">
                        <option value="">Select section…</option>
                    </select>
                </label>
                <label>Date <input type="date" id="lk-date"></label>
                <button class="ac-btn" id="lk-load">Load lock state</button>
            </div>
        </div>
        <div class="ac-card" id="lk-detail" style="display:none"></div>
    </section>

    <!-- ── Corrections ────────────────────────────────────────── -->
    <section class="ac-section" id="sec-corrections">
        <div class="ac-card">
            <div class="ac-filters">
                <label>Status
                    <select id="cr-status">
                        <option value="pending" selected>Pending</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="all">All</option>
                    </select>
                </label>
                <label>Date <input type="date" id="cr-date"></label>
                <button class="ac-btn" id="cr-load">Load</button>
            </div>
        </div>
        <div class="ac-card">
            <table class="ac-table" id="tbl-corrections">
                <thead>
                    <tr><th>Filed</th><th>Date</th><th>Student</th><th>Diff</th><th>Reason</th><th></th></tr>
                </thead>
                <tbody><tr><td colspan="6" class="ac-empty">Click Load.</td></tr></tbody>
            </table>
            <div style="margin-top:10px"><button class="ac-btn secondary" id="cr-more" style="display:none">Load more</button></div>
        </div>
    </section>
</div>

<!-- Lock dialog -->
<div class="ac-modal" id="modal-lock"><div class="ac-modal-card">
    <h3 id="lock-modal-title">Lock / Unlock</h3>
    <div id="lock-modal-body"></div>
    <label>Action</label>
    <div>
        <label style="display:inline-flex;gap:6px;align-items:center;font-size:13px;color:var(--ac-t1);margin-right:14px">
            <input type="radio" name="lk-action" value="true"> Lock
        </label>
        <label style="display:inline-flex;gap:6px;align-items:center;font-size:13px;color:var(--ac-t1)">
            <input type="radio" name="lk-action" value="false" checked> Unlock
        </label>
    </div>
    <label>Reason <span class="ac-mute">(min 10 chars; required for unlock)</span></label>
    <textarea id="lk-reason" placeholder="Reason…"></textarea>
    <div id="lk-warn" class="ac-warn" style="display:none"></div>
    <div class="actions">
        <button class="ac-btn secondary" data-close="modal-lock">Cancel</button>
        <button class="ac-btn" id="lk-apply">Apply</button>
    </div>
</div></div>

<!-- Approve dialog -->
<div class="ac-modal" id="modal-approve"><div class="ac-modal-card">
    <h3>Approve correction</h3>
    <div id="approve-body"></div>
    <label>Note <span class="ac-mute">(optional)</span></label>
    <input type="text" id="ap-note" placeholder="Why approving…">
    <div id="ap-drift" class="ac-warn" style="display:none"></div>
    <label id="ap-force-label" style="display:none">
        <input type="checkbox" id="ap-force"> Force apply despite drift
    </label>
    <div class="actions">
        <button class="ac-btn secondary" data-close="modal-approve">Cancel</button>
        <button class="ac-btn danger" id="ap-reject">Reject</button>
        <button class="ac-btn success" id="ap-approve">Approve</button>
    </div>
</div></div>

</div><!-- /.container-fluid -->
</section><!-- /.content -->
</div><!-- /.content-wrapper -->

<div class="ac-toast" id="toast"></div>

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
        t.className = 'ac-toast show ' + (kind || 'ok');
        clearTimeout(toast._t);
        toast._t = setTimeout(function(){ t.className = 'ac-toast'; }, 3500);
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
    $$('.ac-tab').forEach(function(btn){
        btn.addEventListener('click', function(){
            $$('.ac-tab').forEach(function(b){ b.classList.toggle('active', b === btn); });
            var tab = btn.getAttribute('data-tab');
            $$('.ac-section').forEach(function(s){
                s.classList.toggle('active', s.id === 'sec-' + tab);
            });
            if (tab === 'corrections') loadCorrections();
            if (tab === 'locks')       loadLockSelectors();
        });
    });

    // ── Modal close ─────────────────────────────────────────────
    document.addEventListener('click', function(e){
        var c = e.target.getAttribute('data-close');
        if (c) $('#' + c).classList.remove('show');
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
            tbody.innerHTML = '<tr><td colspan="8" class="ac-empty">No classes configured.</td></tr>';
            refreshPendingBadge();
            return;
        }

        tbody.innerHTML = '<tr><td colspan="8" class="ac-empty">Loading ' + targets.length + ' section(s)…</td></tr>';

        Promise.all(targets.map(function(t){ return fetchSummary(date, t.cls, t.sec); }))
            .then(function(rows){
                rows = rows.filter(function(r){ return r !== null; });
                // Sort by class, then section
                rows.sort(function(a,b){
                    if (a.cls !== b.cls) return a.cls.localeCompare(b.cls);
                    return a.sec.localeCompare(b.sec);
                });

                if (rows.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="8" class="ac-empty">No attendance data for ' + date + '.</td></tr>';
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
                    var marked = r.total > 0 ? r.total + ' marked' : '<span class="ac-mute">not marked</span>';
                    html += '<tr>'
                        + '<td><b>' + r.cls + '</b> / ' + r.sec + '</td>'
                        + '<td>' + marked + '</td>'
                        + '<td>' + r.p + '</td>'
                        + '<td>' + r.a + '</td>'
                        + '<td>' + r.l + '</td>'
                        + '<td>' + r.late + '</td>'
                        + '<td><a href="#" class="ac-mute sa-jump-lock" data-cls="' + r.cls + '" data-sec="' + r.sec + '">Open in Locks ›</a></td>'
                        + '<td></td>'
                        + '</tr>';
                });
                // Footer total
                html += '<tr style="background:var(--ac-bg3);font-weight:600">'
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
        var locksTab = document.querySelector('.ac-tab[data-tab="locks"]');
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
        var pillCls = stage === 'S1_FREE' ? 's1'
                    : stage === 'S2_RESTRICTED' ? 's2'
                    : stage === 'S3_LOCKED' ? 's3' : 'none';

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
            + '    <div style="font-weight:600;color:var(--ac-t1)">' + cls + ' / ' + sec + '</div>'
            + '    <div class="ac-mute" style="margin-top:4px">' + date + '</div>'
            + '    <div style="margin-top:8px">' + lockedHtml + '</div>'
            + '  </div>'
            + '  <div>'
            + '    <span class="ac-pill ' + pillCls + '">' + stage + '</span>'
            + '    <button class="ac-btn" id="open-lock-modal" style="margin-left:10px">Lock / Unlock</button>'
            + '  </div>'
            + '</div>';

        $('#open-lock-modal').addEventListener('click', function(){
            $('#lock-modal-title').textContent = 'Lock / Unlock — ' + cls + ' / ' + sec + ' • ' + date;
            $('#lock-modal-body').innerHTML = '<div class="ac-mute">Current: ' + lockedHtml + '</div>';
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
                    $('#modal-lock').classList.remove('show');
                    toast(lockedNew ? 'Locked.' : 'Unlocked.');
                    renderLockDetail(cls, sec, date, res.body);
                });
            };
            $('#modal-lock').classList.add('show');
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
            tbody.innerHTML = '<tr><td colspan="6" class="ac-empty">No requests.</td></tr>';
            $('#cr-more').style.display = 'none';
            return;
        }
        rows.forEach(function(r){
            var tr = document.createElement('tr');
            var actions = (r.status === 'pending')
                ? '<button class="ac-btn secondary" data-decide="' + (r.requestId || '') + '">Review</button>'
                : '<span class="ac-mute">' + r.status + '</span>';
            tr.innerHTML = ''
                + '<td><span class="ac-mute">' + (r.requestedAt || '').slice(0,16).replace('T',' ') + '</span></td>'
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
            + '<div class="ac-mute" style="margin-top:6px">Reason: ' + (req.reason || '') + '</div>';
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
                    return;
                }
                if (!res.ok) return toast(res.body.message || 'Failed.', 'error');
                $('#modal-approve').classList.remove('show');
                toast('Decision: ' + (res.body.decision || decision));
                cursorState = null;
                loadCorrections(false);
                refreshPendingBadge();
            });
        }
        $('#ap-approve').onclick = function(){ decide('approve', $('#ap-force').checked); };
        $('#ap-reject').onclick  = function(){ decide('reject',  false); };
        $('#modal-approve').classList.add('show');
    }

    // ── boot ─────────────────────────────────────────────────────
    loadDashboard();
})();
</script>
