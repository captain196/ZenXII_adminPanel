<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<style>
:root {
    --au-primary: var(--gold);
    --au-primary-dim: var(--gold-dim);
    --au-bg: var(--bg);
    --au-bg2: var(--bg2);
    --au-bg3: var(--bg3);
    --au-border: var(--border);
    --au-t1: var(--t1);
    --au-t2: var(--t2);
    --au-t3: var(--t3);
    --au-shadow: var(--sh);
    --au-ease: var(--ease);
    --au-r: 10px;
    --au-font: var(--font-b, 'Plus Jakarta Sans', sans-serif);
    --au-font-m: var(--font-m, 'JetBrains Mono', monospace);
    --au-green: #16a34a;
    --au-red: #dc2626;
    --au-blue: #2563eb;
    --au-orange: #d97706;
    --au-purple: #7c3aed;
    --au-green-bg: rgba(22,163,74,.12);
    --au-red-bg: rgba(220,38,38,.12);
    --au-blue-bg: rgba(37,99,235,.12);
    --au-orange-bg: rgba(217,119,6,.12);
    --au-purple-bg: rgba(124,58,237,.12);
}

.au-page-hdr { margin-bottom: 18px; }
.au-page-hdr h4 {
    font-family: var(--au-font); font-weight: 700; color: var(--au-t1);
    margin: 0 0 4px; font-size: 18px;
}
.au-page-hdr h4 i { color: var(--au-primary); margin-right: 6px; }
.au-page-hdr p {
    font-family: var(--au-font); font-size: 13px; color: var(--au-t3); margin: 0;
}

.au-filter-bar {
    display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;
    padding: 18px 20px; border-radius: var(--au-r);
    background: var(--au-bg2); border: 1px solid var(--au-border);
    box-shadow: var(--au-shadow); margin-bottom: 20px;
}
.au-fg { display: flex; flex-direction: column; gap: 4px; }
.au-fg.grow { flex: 1 1 260px; }
.au-fg label {
    font-family: var(--au-font); font-size: 11px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .5px; color: var(--au-t3);
}
.au-fg input, .au-fg select {
    font-family: var(--au-font); font-size: 13px; height: 38px;
    border-radius: 6px; padding: 0 12px; border: 1px solid var(--au-border);
    background: var(--au-bg); color: var(--au-t1); transition: var(--au-ease);
}
.au-fg input[type="month"] { min-width: 160px; }
.au-fg.grow input { width: 100%; }
.au-fg input:focus, .au-fg select:focus {
    outline: none; border-color: var(--au-primary);
    box-shadow: 0 0 0 3px var(--au-primary-dim);
}
.au-btn {
    font-family: var(--au-font); font-size: 13px; font-weight: 600;
    border: none; border-radius: 6px; padding: 0 20px; height: 38px;
    cursor: pointer; transition: var(--au-ease); display: inline-flex;
    align-items: center; gap: 6px;
}
.au-btn-primary { background: var(--au-primary); color: #fff; }
.au-btn-primary:hover { opacity: .88; }
.au-btn-primary:disabled { opacity: .45; cursor: not-allowed; }
.au-btn-ghost {
    background: var(--au-bg3); color: var(--au-t2);
    border: 1px solid var(--au-border);
}
.au-btn-ghost:hover { color: var(--au-t1); }

.au-summary {
    font-family: var(--au-font); font-size: 14px; color: var(--au-t2);
    margin-bottom: 16px; padding: 10px 16px;
    background: var(--au-bg2); border: 1px solid var(--au-border);
    border-radius: var(--au-r); display: none;
}
.au-summary strong { color: var(--au-t1); font-weight: 700; }

.au-panel {
    background: var(--au-bg2); border: 1px solid var(--au-border);
    border-radius: var(--au-r); box-shadow: var(--au-shadow);
    overflow: hidden; margin-bottom: 24px;
}
.au-panel-hdr {
    padding: 16px 20px; border-bottom: 1px solid var(--au-border);
    font-family: var(--au-font); font-size: 14px; font-weight: 700;
    color: var(--au-t1); display: flex; align-items: center; gap: 8px;
}
.au-panel-hdr i { color: var(--au-primary); }

.au-table-wrap { overflow-x: auto; }
.au-table { width: 100%; border-collapse: collapse; font-family: var(--au-font); }
.au-table thead { position: sticky; top: 0; z-index: 5; }
.au-table th {
    font-size: 11px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .3px; color: var(--au-t3); padding: 12px 14px;
    text-align: left; border-bottom: 2px solid var(--au-border);
    background: var(--au-bg3); white-space: nowrap;
}
.au-table td {
    padding: 10px 14px; border-bottom: 1px solid var(--au-border);
    font-size: 13px; color: var(--au-t1); vertical-align: middle;
}
.au-table tbody tr:hover td { background: var(--au-bg3); }
.au-table td.mono { font-family: var(--au-font-m); font-size: 12px; }
.au-name { font-weight: 600; }
.au-sub { font-family: var(--au-font-m); font-size: 11px; color: var(--au-t3); }

.au-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px; border-radius: 6px;
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .3px;
}
.au-badge-mark { background: var(--au-blue-bg); color: var(--au-blue); }
.au-badge-edit { background: var(--au-orange-bg); color: var(--au-orange); }
.au-badge-other { background: var(--au-bg3); color: var(--au-t2); }
.au-badge-s1 { background: var(--au-green-bg); color: var(--au-green); }
.au-badge-s2 { background: var(--au-orange-bg); color: var(--au-orange); }
.au-badge-s3 { background: var(--au-red-bg); color: var(--au-red); }

/* status pills inside the change cell */
.au-mk { font-family: var(--au-font-m); font-weight: 700; font-size: 12px; }
.au-mk-P { color: var(--au-green); }
.au-mk-A { color: var(--au-red); }
.au-mk-L { color: var(--au-orange); }
.au-mk-T { color: var(--au-purple); }
.au-arrow { color: var(--au-t3); margin: 0 6px; }

.au-reason {
    font-family: var(--au-font); font-size: 12px; color: var(--au-t1);
    max-width: 280px; white-space: normal;
}
.au-muted { color: var(--au-t3); }

.au-empty {
    text-align: center; padding: 48px 20px; color: var(--au-t3);
    font-family: var(--au-font); font-size: 14px;
}
.au-empty i { font-size: 36px; display: block; margin-bottom: 12px; opacity: .5; }
.au-loading {
    text-align: center; padding: 32px 20px; color: var(--au-t3);
    font-family: var(--au-font); font-size: 13px; display: none;
}
.au-loading i { margin-right: 6px; }

.au-pager {
    display: flex; align-items: center; justify-content: center; gap: 14px;
    padding: 14px; font-family: var(--au-font); font-size: 13px; color: var(--au-t2);
}

.au-toast {
    position: fixed; bottom: 24px; right: 24px; z-index: 9999;
    padding: 12px 22px; border-radius: 8px; font-family: var(--au-font);
    font-size: 13px; font-weight: 600; color: #fff;
    box-shadow: 0 8px 24px rgba(0,0,0,.25);
    transform: translateY(100px); opacity: 0; transition: all .3s ease;
    pointer-events: none;
}
.au-toast.show { transform: translateY(0); opacity: 1; }
.au-toast.success { background: var(--au-green); }
.au-toast.error { background: var(--au-red); }

@media (max-width: 767px) {
    .au-filter-bar { flex-direction: column; align-items: stretch; }
    .au-fg input[type="month"], .au-fg.grow input { width: 100%; }
    .au-table th, .au-table td { padding: 8px 10px; font-size: 12px; }
}
</style>

<div class="content-wrapper">
<section class="content">
<div class="container-fluid">

    <!-- Page Header -->
    <div class="au-page-hdr">
        <h4>
            <i class="fa fa-history"></i>
            Attendance Audit Log
        </h4>
        <p>Every attendance mark and edit with the reason behind it — search by student ID, name, class or section. Includes who made the change and the governance stage.</p>
        <ol style="list-style:none;display:flex;flex-wrap:wrap;gap:6px;margin:6px 0 0;padding:0;font-size:12px;color:#8a8a8a;">
            <li><a href="<?= base_url('admin') ?>" style="color:var(--gold,#c9a24b);text-decoration:none;">Dashboard</a></li>
            <li>/&nbsp;<a href="<?= base_url('attendance') ?>" style="color:var(--gold,#c9a24b);text-decoration:none;">Attendance</a></li>
            <li>/&nbsp;Audit Log</li>
        </ol>
    </div>

    <!-- Filter Bar -->
    <div class="au-filter-bar">
        <div class="au-fg">
            <label for="auMonth">Month</label>
            <input type="month" id="auMonth" value="<?= htmlspecialchars(date('Y-m'), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="au-fg">
            <label for="auAction">Action</label>
            <select id="auAction">
                <option value="">All</option>
                <option value="MARK">Mark</option>
                <option value="EDIT">Edit</option>
                <option value="CORRECTION_APPROVE">Correction ✓</option>
                <option value="CORRECTION_REJECT">Correction ✗</option>
                <option value="LOCK">Lock</option>
                <option value="UNLOCK">Unlock</option>
            </select>
        </div>
        <div class="au-fg grow">
            <label for="auSearch">Search — ID, name, class, section, reason</label>
            <input type="text" id="auSearch" placeholder="e.g. STU0012, Yugant, Class 9th, Section A, bus…" autocomplete="off">
        </div>
        <div class="au-fg" style="align-self:flex-end;">
            <button type="button" class="au-btn au-btn-primary" id="auLoadBtn">
                <i class="fa fa-search"></i> Search
            </button>
        </div>
        <div class="au-fg" style="align-self:flex-end;">
            <button type="button" class="au-btn au-btn-ghost" id="auClearBtn">Clear</button>
        </div>
    </div>

    <!-- Summary Line -->
    <div class="au-summary" id="auSummary"></div>

    <!-- Loading -->
    <div class="au-loading" id="auLoading">
        <i class="fa fa-spinner fa-spin"></i> Loading audit log…
    </div>

    <!-- Empty State -->
    <div class="au-empty" id="auEmpty">
        <i class="fa fa-history"></i>
        <p>Pick a month and click Search to view the attendance audit trail.</p>
    </div>

    <!-- Table -->
    <div class="au-panel" id="auPanel" style="display:none;">
        <div class="au-panel-hdr">
            <i class="fa fa-list-alt"></i> Audit Records
        </div>
        <div class="au-table-wrap">
            <table class="au-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Student / Target</th>
                        <th>Class · Section</th>
                        <th>Action</th>
                        <th>Change</th>
                        <th>Stage</th>
                        <th>By</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody id="auBody"></tbody>
            </table>
        </div>
        <div class="au-pager" id="auPager" style="display:none;">
            <button type="button" class="au-btn au-btn-ghost" id="auPrev">‹ Prev</button>
            <span id="auPageInfo"></span>
            <button type="button" class="au-btn au-btn-ghost" id="auNext">Next ›</button>
        </div>
    </div>

    <!-- Toast -->
    <div class="au-toast" id="auToast"></div>

</div>
</section>
</div>

<script>
(function(){
    "use strict";

    var CSRF_NAME = '<?= $this->security->get_csrf_token_name() ?>';
    var CSRF_HASH = '<?= $this->security->get_csrf_hash() ?>';
    var BASE = '<?= base_url() ?>';

    var elMonth   = document.getElementById('auMonth');
    var elAction  = document.getElementById('auAction');
    var elSearch  = document.getElementById('auSearch');
    var elLoadBtn = document.getElementById('auLoadBtn');
    var elClear   = document.getElementById('auClearBtn');
    var elSummary = document.getElementById('auSummary');
    var elLoading = document.getElementById('auLoading');
    var elEmpty   = document.getElementById('auEmpty');
    var elPanel   = document.getElementById('auPanel');
    var elBody    = document.getElementById('auBody');
    var elPager   = document.getElementById('auPager');
    var elPrev    = document.getElementById('auPrev');
    var elNext    = document.getElementById('auNext');
    var elPageInf = document.getElementById('auPageInfo');
    var elToast   = document.getElementById('auToast');

    var curPage = 1;
    var totalPages = 1;

    function esc(s) {
        if (s == null) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(s)));
        return d.innerHTML;
    }
    function showToast(msg, type) {
        elToast.textContent = msg;
        elToast.className = 'au-toast ' + (type || 'success');
        setTimeout(function(){ elToast.classList.add('show'); }, 10);
        setTimeout(function(){ elToast.classList.remove('show'); }, 3000);
    }
    function postData(url, data) {
        var fd = new FormData();
        fd.append(CSRF_NAME, CSRF_HASH);
        if (data) { Object.keys(data).forEach(function(k){ fd.append(k, data[k]); }); }
        return fetch(BASE + url, { method: 'POST', body: fd })
            .then(function(r) {
                var ct = r.headers.get('content-type') || '';
                if (ct.indexOf('application/json') !== -1) return r.json();
                return r.text().then(function(t) {
                    try { return JSON.parse(t); } catch(e) { throw new Error('Invalid response'); }
                });
            })
            .then(function(j) { if (j && j.csrf_hash) CSRF_HASH = j.csrf_hash; return j; });
    }

    // "2026-07" -> "2026-07" already the format fetch_audit_logs wants (year_month)
    function ym() { return elMonth.value || ''; }

    function actionBadge(a) {
        if (a === 'MARK') return '<span class="au-badge au-badge-mark">Mark</span>';
        if (a === 'EDIT') return '<span class="au-badge au-badge-edit">Edit</span>';
        return '<span class="au-badge au-badge-other">' + esc((a || '').replace(/_/g,' ') || '—') + '</span>';
    }
    function stageBadge(s) {
        if (s === 'S1_FREE')       return '<span class="au-badge au-badge-s1">S1</span>';
        if (s === 'S2_RESTRICTED') return '<span class="au-badge au-badge-s2">S2</span>';
        if (s === 'S3_LOCKED')     return '<span class="au-badge au-badge-s3">S3</span>';
        return '<span class="au-muted">—</span>';
    }
    function markPill(v) {
        if (!v || typeof v !== 'object') return '<span class="au-muted">—</span>';
        var st = v.status || '';
        var late = v.late ? ' +late' : '';
        if (!st) return '<span class="au-muted">—</span>';
        return '<span class="au-mk au-mk-' + esc(st) + '">' + esc(st) + esc(late) + '</span>';
    }
    function changeCell(row) {
        var oldV = row.oldValue, newV = row.newValue;
        if (!oldV && !newV) return '<span class="au-muted">—</span>';
        if (!oldV) return markPill(newV);
        return markPill(oldV) + '<span class="au-arrow">→</span>' + markPill(newV);
    }
    function targetCell(row) {
        var name = row.targetName || '';
        var id = row.targetId || '';
        if (name) return '<div class="au-name">' + esc(name) + '</div><div class="au-sub">' + esc(id) + '</div>';
        return '<div class="mono">' + esc(id || '—') + '</div>';
    }
    function byCell(row) {
        var name = row.userName || '';
        var id = row.userId || '';
        var role = row.role ? ' · ' + row.role : '';
        if (name) return '<div class="au-name">' + esc(name) + '</div><div class="au-sub">' + esc(id) + esc(role) + '</div>';
        return '<div class="mono">' + esc(id || 'system') + '</div>';
    }
    function classCell(row) {
        var c = row.className || '';
        var s = row.section || '';
        if (!c && !s) return '<span class="au-muted">—</span>';
        return esc((c + (s ? ' · ' + s : '')).trim());
    }

    function load(page) {
        var month = ym();
        if (!month) { showToast('Please pick a month.', 'error'); return; }
        curPage = page || 1;

        elEmpty.style.display = 'none';
        elPanel.style.display = 'none';
        elSummary.style.display = 'none';
        elLoading.style.display = 'block';
        elLoadBtn.disabled = true;

        postData('attendance/fetch_audit_logs', {
            year_month: month,
            action: elAction.value || '',
            search: elSearch.value.trim(),
            page: curPage,
            limit: 50
        }).then(function(res) {
            elLoading.style.display = 'none';
            elLoadBtn.disabled = false;

            if (!res || res.status === 'error') {
                showToast(res && res.message ? res.message : 'Failed to load audit log.', 'error');
                elEmpty.style.display = 'block';
                return;
            }

            var logs = res.logs || [];
            var pg = res.pagination || { total: logs.length, page: 1, total_pages: 1 };
            totalPages = pg.total_pages || 1;

            elSummary.innerHTML = '<strong>' + (pg.total || 0) + '</strong> record'
                + ((pg.total !== 1) ? 's' : '')
                + ' in <strong>' + esc(res.year_month || month) + '</strong>'
                + (elSearch.value.trim() ? ' matching “<strong>' + esc(elSearch.value.trim()) + '</strong>”' : '');
            elSummary.style.display = 'block';

            if (logs.length === 0) {
                elEmpty.style.display = 'block';
                elEmpty.querySelector('p').textContent = 'No audit records match this filter.';
                return;
            }

            render(logs, (curPage - 1) * (pg.limit || 50));
            elPanel.style.display = 'block';

            if (totalPages > 1) {
                elPager.style.display = 'flex';
                elPageInf.textContent = 'Page ' + curPage + ' of ' + totalPages;
                elPrev.disabled = (curPage <= 1);
                elNext.disabled = (curPage >= totalPages);
            } else {
                elPager.style.display = 'none';
            }
        }).catch(function() {
            elLoading.style.display = 'none';
            elLoadBtn.disabled = false;
            showToast('Network error. Please try again.', 'error');
            elEmpty.style.display = 'block';
        });
    }

    function render(logs, startIdx) {
        var html = '';
        for (var i = 0; i < logs.length; i++) {
            var r = logs[i];
            html += '<tr>'
                + '<td class="mono">' + (startIdx + i + 1) + '</td>'
                + '<td class="mono">' + esc(r.date || '—') + '</td>'
                + '<td>' + targetCell(r) + '</td>'
                + '<td>' + classCell(r) + '</td>'
                + '<td>' + actionBadge(r.action) + '</td>'
                + '<td>' + changeCell(r) + '</td>'
                + '<td>' + stageBadge(r.stage) + '</td>'
                + '<td>' + byCell(r) + '</td>'
                + '<td>' + (r.reason ? '<span class="au-reason">' + esc(r.reason) + '</span>' : '<span class="au-muted">—</span>') + '</td>'
                + '</tr>';
        }
        elBody.innerHTML = html;
    }

    elLoadBtn.addEventListener('click', function(){ load(1); });
    elSearch.addEventListener('keydown', function(e){ if (e.key === 'Enter') load(1); });
    elPrev.addEventListener('click', function(){ if (curPage > 1) load(curPage - 1); });
    elNext.addEventListener('click', function(){ if (curPage < totalPages) load(curPage + 1); });
    elClear.addEventListener('click', function(){
        elSearch.value = ''; elAction.value = ''; load(1);
    });

    // Auto-load current month on open
    load(1);

})();
</script>
