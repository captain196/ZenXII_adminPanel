<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<style>
html { font-size:16px !important; }

.hc-wrap { padding:24px 22px 52px; min-height:100vh; }

/* ── Header ── */
.hc-head {
    display:flex; align-items:center; gap:14px;
    padding:18px 22px; margin-bottom:22px;
    background:var(--bg2); border:1px solid var(--border);
    border-radius:var(--r); box-shadow:var(--sh);
}
.hc-head-icon {
    width:44px; height:44px; border-radius:10px;
    background:var(--gold); display:flex; align-items:center; justify-content:center;
    flex-shrink:0; box-shadow:0 0 18px var(--gold-glow);
}
.hc-head-icon i { color:#fff; font-size:18px; }
.hc-head-info { flex:1; }
.hc-head-title { font-size:18px; font-weight:700; color:var(--t1); font-family:var(--font-d); }
.hc-head-sub { font-size:12px; color:var(--t3); margin-top:2px; }

/* ── Module Chips ── */
.hc-chips {
    display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px;
    background:var(--bg2); border:1px solid var(--border);
    border-radius:10px; padding:14px 16px; box-shadow:var(--sh);
}
.hc-chip {
    padding:7px 16px; border-radius:20px; font-size:12px; font-weight:600;
    cursor:pointer; border:1px solid var(--border); background:var(--bg3);
    color:var(--t2); transition:all var(--ease); user-select:none;
    display:inline-flex; align-items:center; gap:6px; font-family:var(--font-b);
}
.hc-chip:hover { border-color:var(--gold); color:var(--gold); }
.hc-chip.active { background:var(--gold); border-color:var(--gold); color:#fff; }
.hc-chip i { font-size:13px; }

/* ── Stats ── */
.hc-stats { display:flex; gap:14px; margin-bottom:20px; flex-wrap:wrap; }
.hc-stat {
    background:var(--bg2); border:1px solid var(--border);
    border-radius:10px; padding:14px 20px; box-shadow:var(--sh);
    flex:1; min-width:120px; text-align:center;
    transition:border-color var(--ease);
}
.hc-stat:hover { border-color:var(--gold); }
.hc-stat .val { font-size:1.8rem; font-weight:800; line-height:1.1; font-family:var(--font-d); }
.hc-stat .lbl { font-size:10px; color:var(--t3); text-transform:uppercase; letter-spacing:.4px; font-weight:600; margin-top:3px; }
.hc-stat-total .val { color:var(--gold); }
.hc-stat-pass .val { color:#16a34a; }
.hc-stat-fail .val { color:#dc2626; }
.hc-stat-time .val { color:var(--t2); }

/* ── Controls ── */
.hc-controls {
    display:flex; gap:10px; align-items:center; flex-wrap:wrap;
    margin-bottom:20px;
}
.hc-btn {
    padding:10px 22px; border:none; border-radius:8px;
    font-size:13px; font-weight:700; cursor:pointer;
    font-family:var(--font-b); transition:all var(--ease);
    display:inline-flex; align-items:center; gap:8px;
}
.hc-btn-primary { background:var(--gold); color:#fff; }
.hc-btn-primary:hover { background:var(--gold2); }
.hc-btn-ghost { background:var(--bg2); color:var(--t2); border:1px solid var(--border); }
.hc-btn-ghost:hover { border-color:var(--gold); color:var(--gold); }
.hc-btn:disabled { opacity:.5; cursor:not-allowed; }
.hc-run-status { font-size:12px; color:var(--t3); margin-left:auto; display:flex; align-items:center; gap:8px; }

/* ── Progress ── */
.hc-progress { height:5px; background:var(--bg3); border-radius:3px; margin-bottom:20px; overflow:hidden; }
.hc-progress-bar { height:100%; width:0%; background:var(--gold); border-radius:3px; transition:width .3s ease; }
.hc-progress-bar.done-pass { background:#16a34a; }
.hc-progress-bar.done-fail { background:#dc2626; }

/* ── Results Table ── */
.hc-table-wrap {
    background:var(--bg2); border:1px solid var(--border);
    border-radius:10px; overflow:hidden; box-shadow:var(--sh);
}
.hc-toolbar {
    display:flex; gap:8px; padding:12px 16px;
    border-bottom:1px solid var(--border);
    align-items:center; flex-wrap:wrap;
}
.hc-toolbar span { font-size:13px; font-weight:600; color:var(--t1); margin-right:4px; }
.hc-filter-btn {
    padding:4px 12px; border-radius:14px; font-size:12px; font-weight:600;
    cursor:pointer; border:1px solid var(--border); background:transparent;
    color:var(--t2); font-family:var(--font-b); transition:all .12s;
}
.hc-filter-btn:hover { border-color:var(--gold); color:var(--gold); }
.hc-filter-btn.active { background:var(--gold-dim); border-color:var(--gold); color:var(--gold); }
.hc-search {
    margin-left:auto; background:var(--bg3); border:1px solid var(--border);
    border-radius:6px; color:var(--t1); padding:5px 10px; font-size:12px;
    font-family:var(--font-b); width:200px; outline:none;
    transition:border-color var(--ease);
}
.hc-search:focus { border-color:var(--gold); box-shadow:0 0 0 3px var(--gold-ring); }

.hc-results { max-height:600px; overflow-y:auto; }
.hc-results::-webkit-scrollbar { width:4px; }
.hc-results::-webkit-scrollbar-thumb { background:var(--border); border-radius:2px; }

.hc-table { width:100%; border-collapse:collapse; font-size:13px; }
.hc-table th {
    background:var(--bg3); color:var(--t2); font-family:var(--font-m);
    padding:10px 14px; text-align:left; border-bottom:1px solid var(--border);
    font-size:11px; text-transform:uppercase; letter-spacing:.4px;
    position:sticky; top:0; z-index:1;
}
.hc-table td { padding:10px 14px; border-bottom:1px solid var(--border); color:var(--t1); vertical-align:top; }
.hc-table tr:last-child td { border-bottom:none; }
.hc-table tr:hover td { background:var(--gold-dim); }
.hc-table tr.hc-hidden { display:none; }

.hc-badge { display:inline-block; padding:3px 12px; border-radius:20px; font-size:11px; font-weight:700; letter-spacing:.3px; }
.hc-badge-pass { background:rgba(22,163,74,.12); color:#16a34a; }
.hc-badge-fail { background:rgba(220,38,38,.12); color:#dc2626; }

.hc-mod-tag {
    display:inline-block; padding:2px 8px; border-radius:4px; font-size:10px;
    font-weight:600; background:var(--gold-dim); color:var(--gold);
    font-family:var(--font-m); text-transform:uppercase; letter-spacing:.3px;
}

.hc-detail { font-size:12px; color:var(--t3); max-width:500px; word-break:break-word; }
.hc-time { font-size:11px; color:var(--t3); font-family:var(--font-m); }
.hc-num { font-family:var(--font-m); font-size:12px; color:var(--gold); font-weight:700; }

.hc-empty { text-align:center; padding:60px 24px; color:var(--t3); }
.hc-empty i { font-size:2.5rem; display:block; margin-bottom:12px; opacity:.5; }

/* ── Module group header row ── */
.hc-group-row td {
    background:var(--bg3) !important; font-weight:700; font-size:12px;
    color:var(--gold); font-family:var(--font-d); padding:8px 14px !important;
    letter-spacing:.3px;
}

@media(max-width:767px) {
    .hc-head { flex-wrap:wrap; }
    .hc-stats { gap:10px; }
    .hc-stat { min-width:90px; padding:12px 14px; }
    .hc-controls { flex-direction:column; align-items:flex-start; }
    .hc-run-status { margin-left:0; }
}
</style>

<div class="content-wrapper">
<div class="hc-wrap">

    <div class="hc-head">
        <div class="hc-head-icon"><i class="fa fa-heartbeat"></i></div>
        <div class="hc-head-info">
            <div class="hc-head-title">Health Checker</div>
            <div class="hc-head-sub"><?= htmlspecialchars($school_name) ?> (<?= htmlspecialchars($school_id) ?>) — Session <?= htmlspecialchars($session_year) ?> — Live Firebase data integrity checks</div>
        </div>
    </div>

    <div class="hc-chips" id="chipBar">
        <div class="hc-chip active" data-mod="ALL"><i class="fa fa-check-circle"></i> All Modules</div>
        <?php foreach ($modules as $key => $m): ?>
        <div class="hc-chip" data-mod="<?= $key ?>"><i class="fa <?= $m['icon'] ?>"></i> <?= $m['label'] ?></div>
        <?php endforeach; ?>
    </div>

    <div class="hc-stats">
        <div class="hc-stat hc-stat-total"><div class="val" id="sTotal">--</div><div class="lbl">Total</div></div>
        <div class="hc-stat hc-stat-pass"><div class="val" id="sPass">--</div><div class="lbl">Passed</div></div>
        <div class="hc-stat hc-stat-fail"><div class="val" id="sFail">--</div><div class="lbl">Failed</div></div>
        <div class="hc-stat hc-stat-time"><div class="val" id="sTime">--</div><div class="lbl">Duration</div></div>
    </div>

    <div class="hc-controls">
        <button class="hc-btn hc-btn-primary" id="btnRun"><i class="fa fa-play"></i> Run Checks</button>
        <button class="hc-btn hc-btn-ghost" id="btnClear"><i class="fa fa-times"></i> Clear</button>
        <div class="hc-run-status" id="runStatus"></div>
    </div>

    <div class="hc-progress"><div class="hc-progress-bar" id="progressBar"></div></div>

    <div class="hc-table-wrap">
        <div class="hc-toolbar">
            <span>Results</span>
            <button class="hc-filter-btn active" data-f="ALL">All</button>
            <button class="hc-filter-btn" data-f="pass">Pass</button>
            <button class="hc-filter-btn" data-f="fail">Fail</button>
            <input class="hc-search" id="searchBox" type="text" placeholder="Search test name...">
        </div>
        <div class="hc-results" id="resultsArea">
            <div class="hc-empty"><i class="fa fa-heartbeat"></i> Select modules and click Run Checks</div>
        </div>
    </div>

</div>
</div>

<script>
var BASE = '<?= base_url() ?>';
var CSRF_NAME  = '<?= $this->security->get_csrf_token_name() ?>';
var CSRF_TOKEN = '<?= $this->security->get_csrf_hash() ?>';
var activeMod  = 'ALL';
var activeFilter = 'ALL';
var running = false;

/* ── Chip selection ── */
document.getElementById('chipBar').addEventListener('click', function(e) {
    var chip = e.target.closest('.hc-chip');
    if (!chip) return;
    document.querySelectorAll('.hc-chip').forEach(function(c) { c.classList.remove('active'); });
    chip.classList.add('active');
    activeMod = chip.dataset.mod;
});

/* ── Filter buttons ── */
document.querySelectorAll('.hc-filter-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.hc-filter-btn').forEach(function(b) { b.classList.remove('active'); });
        btn.classList.add('active');
        activeFilter = btn.dataset.f;
        applyFilter();
    });
});
document.getElementById('searchBox').addEventListener('input', applyFilter);

function applyFilter() {
    var q = document.getElementById('searchBox').value.toLowerCase();
    document.querySelectorAll('#resultsTable tr').forEach(function(row) {
        if (row.classList.contains('hc-group-row')) { row.style.display = ''; return; }
        var matchF = activeFilter === 'ALL' || row.dataset.status === activeFilter;
        var matchQ = !q || (row.dataset.name || '').toLowerCase().indexOf(q) !== -1;
        row.style.display = (matchF && matchQ) ? '' : 'none';
    });
}

/* ── Run ── */
document.getElementById('btnRun').addEventListener('click', function() {
    if (running) return;
    running = true;
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Running...';

    var bar = document.getElementById('progressBar');
    bar.style.width = '0%';
    bar.className = 'hc-progress-bar';

    var status = document.getElementById('runStatus');
    var t0 = Date.now();
    var timerIv = setInterval(function() {
        status.textContent = ((Date.now() - t0) / 1000).toFixed(1) + 's';
    }, 100);

    var isAll = activeMod === 'ALL';
    var url   = isAll ? BASE + 'health_check/run_all' : BASE + 'health_check/run';
    var body  = CSRF_NAME + '=' + encodeURIComponent(CSRF_TOKEN);
    if (!isAll) body += '&module=' + encodeURIComponent(activeMod);

    bar.style.width = '20%';

    fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body,
        credentials: 'include',
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        clearInterval(timerIv);
        var elapsed = ((Date.now() - t0) / 1000).toFixed(2);
        status.textContent = 'Done in ' + elapsed + 's';
        bar.style.width = '100%';

        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-play"></i> Run Checks';
        running = false;

        // Update CSRF for next request
        if (data.csrf_token) CSRF_TOKEN = data.csrf_token;

        if (data.status !== 'success') {
            document.getElementById('resultsArea').innerHTML = '<div class="hc-empty"><i class="fa fa-exclamation-triangle"></i> ' + esc(data.message || 'Unknown error') + '</div>';
            return;
        }

        document.getElementById('sTotal').textContent = data.total;
        document.getElementById('sPass').textContent  = data.passed;
        document.getElementById('sFail').textContent  = data.failed;
        document.getElementById('sTime').textContent  = elapsed + 's';

        bar.classList.add(data.failed > 0 ? 'done-fail' : 'done-pass');

        // Build table
        var moduleResults = isAll ? data.modules : [data];
        renderTable(moduleResults);
    })
    .catch(function(err) {
        clearInterval(timerIv);
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-play"></i> Run Checks';
        running = false;
        bar.style.width = '100%';
        bar.classList.add('done-fail');
        document.getElementById('resultsArea').innerHTML = '<div class="hc-empty"><i class="fa fa-exclamation-triangle"></i> ' + esc(err.message || 'Network error') + '</div>';
    });
});

function renderTable(moduleResults) {
    var idx = 0;
    var html = '<table class="hc-table" id="resultsTable"><thead><tr>';
    html += '<th style="width:36px;">#</th><th>Module</th><th>Check</th><th style="width:70px;">Status</th><th>Details</th><th style="width:60px;">Time</th>';
    html += '</tr></thead><tbody>';

    moduleResults.forEach(function(mod) {
        html += '<tr class="hc-group-row"><td colspan="6"><i class="fa fa-folder-open" style="margin-right:6px;"></i>' + esc(mod.label) + ' — ' + mod.passed + '/' + mod.total + ' passed</td></tr>';

        mod.results.forEach(function(r) {
            idx++;
            var badgeCls = r.status === 'pass' ? 'hc-badge-pass' : 'hc-badge-fail';
            var icon     = r.status === 'pass' ? 'fa-check' : 'fa-times';
            html += '<tr data-status="' + r.status + '" data-name="' + esc(r.name) + '">';
            html += '<td class="hc-num">' + idx + '</td>';
            html += '<td><span class="hc-mod-tag">' + esc(mod.module) + '</span></td>';
            html += '<td style="font-weight:600;">' + esc(r.name) + '</td>';
            html += '<td><span class="hc-badge ' + badgeCls + '"><i class="fa ' + icon + '" style="margin-right:3px;"></i>' + r.status.toUpperCase() + '</span></td>';
            html += '<td class="hc-detail">' + esc(r.detail) + '</td>';
            html += '<td class="hc-time">' + (r.ms != null ? r.ms + 'ms' : '') + '</td>';
            html += '</tr>';
        });
    });

    html += '</tbody></table>';
    document.getElementById('resultsArea').innerHTML = html;
}

/* ── Clear ── */
document.getElementById('btnClear').addEventListener('click', function() {
    if (running) return;
    document.getElementById('resultsArea').innerHTML = '<div class="hc-empty"><i class="fa fa-heartbeat"></i> Select modules and click Run Checks</div>';
    document.getElementById('progressBar').style.width = '0%';
    document.getElementById('progressBar').className = 'hc-progress-bar';
    document.getElementById('runStatus').textContent = '';
    document.getElementById('sTotal').textContent = '--';
    document.getElementById('sPass').textContent = '--';
    document.getElementById('sFail').textContent = '--';
    document.getElementById('sTime').textContent = '--';
});

function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
</script>
