<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<!-- Attendance Design System (shared, cacheable) — see assets/css/attendance_design_system.css -->
<link rel="stylesheet" href="<?= base_url('assets/css/attendance_design_system.css') ?>?v=2.0.0">

<div class="content-wrapper">
<section class="content">
<div class="container-fluid">
<div class="att-wrap">

    <!-- Page Header -->
    <div class="att-header">
        <div class="att-header-left">
            <div class="att-header-icon"><i class="fa fa-bar-chart"></i></div>
            <div>
                <div class="att-page-title">Attendance Analytics</div>
                <div class="att-subtitle">Attendance trends, class-wise comparison, and composition — updated as you filter</div>
                <ul class="att-breadcrumb">
                    <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
                    <li><a href="<?= base_url('attendance') ?>">Attendance</a></li>
                    <li>Analytics</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Filter Bar (auto-refresh on change; manual refresh + export on the right) -->
    <div class="att-toolbar">
        <div class="att-fg">
            <label for="anaMonth">Month</label>
            <select id="anaMonth">
                <?php
                    $currentMonth = date('F');
                    if (!empty($months)) {
                        foreach ($months as $m) {
                            $ms = htmlspecialchars($m, ENT_QUOTES, 'UTF-8');
                            $sel = ($ms === $currentMonth) ? ' selected' : '';
                            echo '<option value="' . $ms . '"' . $sel . '>' . $ms . '</option>';
                        }
                    }
                ?>
            </select>
        </div>
        <div class="att-fg">
            <label for="anaClass">Class (Optional)</label>
            <select id="anaClass">
                <option value="">All Classes</option>
                <?php
                    if (!empty($Classes)) {
                        $seen = [];
                        foreach ($Classes as $c) {
                            $cn = htmlspecialchars($c['class_name'], ENT_QUOTES, 'UTF-8');
                            if (!isset($seen[$cn])) {
                                $seen[$cn] = true;
                                echo '<option value="' . $cn . '">' . $cn . '</option>';
                            }
                        }
                    }
                ?>
            </select>
        </div>
        <div class="att-fg">
            <label for="anaSection">Section (Optional)</label>
            <select id="anaSection" disabled>
                <option value="">All Sections</option>
            </select>
        </div>

        <div class="att-toolbar-spacer"></div>

        <div class="att-btn-group">
            <button type="button" class="att-btn att-btn-primary" id="anaLoadBtn" title="Refresh analytics">
                <i class="fa fa-refresh"></i> Refresh
            </button>
            <button type="button" class="att-btn att-btn-ghost" id="anaExportCsv" title="Export the class-wise table as CSV">
                <i class="fa fa-file-text-o"></i> CSV
            </button>
            <button type="button" class="att-btn att-btn-ghost" id="anaExportPdf" disabled title="PDF export — coming soon">
                <i class="fa fa-file-pdf-o"></i> PDF
            </button>
        </div>
    </div>

    <!-- Summary KPI Cards -->
    <div class="att-kpi-grid" id="anaCards" style="display:none;">
        <div class="att-kpi att-kpi-success">
            <div class="att-kpi-icon"><i class="fa fa-pie-chart"></i></div>
            <div class="att-kpi-value" id="anaAvgPct">--</div>
            <div class="att-kpi-label">Avg Attendance %</div>
            <div class="att-kpi-delta att-delta-flat" id="anaAvgDelta" style="display:none;"></div>
        </div>
        <div class="att-kpi att-kpi-info">
            <div class="att-kpi-icon"><i class="fa fa-trophy"></i></div>
            <div class="att-kpi-value" id="anaRegularClass">--</div>
            <div class="att-kpi-label">Most Regular Class</div>
        </div>
        <div class="att-kpi att-kpi-danger">
            <div class="att-kpi-icon"><i class="fa fa-exclamation-triangle"></i></div>
            <div class="att-kpi-value" id="anaAbsentClass">--</div>
            <div class="att-kpi-label">Highest Absenteeism</div>
        </div>
        <div class="att-kpi att-kpi-warning">
            <div class="att-kpi-icon"><i class="fa fa-clock-o"></i></div>
            <div class="att-kpi-value" id="anaTotalLate">--</div>
            <div class="att-kpi-label">Total Late Arrivals</div>
        </div>
    </div>

    <!-- Loading spinner (professional branded ring — shared DS .att-loader) -->
    <div class="att-loading" id="anaLoading">
        <div class="att-loader">
            <span class="att-loader-ring"></span>
            <span class="att-loader-text">Loading analytics&hellip;</span>
        </div>
    </div>

    <!-- Empty State -->
    <div class="att-empty" id="anaEmpty">
        <i class="fa fa-bar-chart"></i>
        <p>No attendance data for this selection yet. Try another month, class, or section.</p>
    </div>

    <!-- Class-wise + Composition (two-up) -->
    <div class="att-analytics-grid" id="anaAnalyticsGrid" style="display:none;">
        <div class="att-card att-card--panel att-u-mb0" id="anaBarPanel">
            <div class="att-card-hdr"><i class="fa fa-bar-chart"></i> Class-wise Attendance</div>
            <div class="att-card-body">
                <div class="att-chart att-chart-md att-chart--clickable"><canvas id="anaBarChart"></canvas></div>
            </div>
        </div>
        <div class="att-card att-card--panel att-u-mb0" id="anaDonutPanel">
            <div class="att-card-hdr"><i class="fa fa-pie-chart"></i> Attendance Composition</div>
            <div class="att-card-body">
                <div class="att-chart att-chart-sm att-chart--clickable"><canvas id="anaDonutChart"></canvas></div>
            </div>
        </div>
    </div>

    <!-- Monthly Trend -->
    <div class="att-card att-card--panel" id="anaTrendPanel" style="display:none;">
        <div class="att-card-hdr"><i class="fa fa-line-chart"></i> Monthly Trend</div>
        <div class="att-card-body">
            <div class="att-chart att-chart-md att-chart--clickable"><canvas id="anaTrendChart"></canvas></div>
            <div class="att-empty" id="anaTrendEmpty" style="display:none;">
                <p>No monthly trend data available for this selection.</p>
            </div>
        </div>
    </div>

    <!-- Individual Search -->
    <div class="att-card att-card--panel" id="anaIndPanel">
        <div class="att-card-hdr">
            <i class="fa fa-user"></i> Individual Attendance Report
        </div>
        <div class="att-card-body">
            <div class="att-toolbar-section" style="margin-bottom:16px;">
                <div class="att-fg">
                    <label for="anaPersonId">Person ID</label>
                    <input type="text" id="anaPersonId" placeholder="Enter ID">
                </div>
                <div class="att-fg">
                    <label for="anaPersonType">Type</label>
                    <select id="anaPersonType">
                        <option value="student">Student</option>
                        <option value="staff">Staff</option>
                    </select>
                </div>
                <div class="att-fg" id="anaIndClassFg">
                    <label for="anaIndClass">Class</label>
                    <select id="anaIndClass">
                        <option value="">Select Class</option>
                        <?php
                            if (!empty($Classes)) {
                                $seen2 = [];
                                foreach ($Classes as $c) {
                                    $cn = htmlspecialchars($c['class_name'], ENT_QUOTES, 'UTF-8');
                                    if (!isset($seen2[$cn])) {
                                        $seen2[$cn] = true;
                                        echo '<option value="' . $cn . '">' . $cn . '</option>';
                                    }
                                }
                            }
                        ?>
                    </select>
                </div>
                <div class="att-fg" id="anaIndSectionFg">
                    <label for="anaIndSection">Section</label>
                    <select id="anaIndSection" disabled>
                        <option value="">Select Section</option>
                    </select>
                </div>
                <div class="att-fg" style="align-self:flex-end;">
                    <button type="button" class="att-btn att-btn-primary" id="anaIndBtn">
                        <i class="fa fa-search"></i> Search
                    </button>
                </div>
            </div>
            <div class="att-loading" id="anaIndLoading">
                <div class="att-loader">
                    <span class="att-loader-ring"></span>
                    <span class="att-loader-text">Loading report&hellip;</span>
                </div>
            </div>
            <div id="anaIndPersonInfo" class="att-person" style="display:none;">
                <i class="fa fa-user-circle att-person-avatar"></i>
                <div>
                    <div class="att-person-name" id="anaIndPersonName"></div>
                    <div class="att-person-meta">
                        <span><i class="fa fa-id-badge"></i> <em id="anaIndPersonId2"></em></span>
                        <span id="anaIndPersonClassWrap"><i class="fa fa-graduation-cap"></i> <em id="anaIndPersonClass"></em></span>
                    </div>
                </div>
            </div>
            <div id="anaIndResult" style="display:none;">
                <div class="att-table-wrap">
                    <table class="att-table" id="anaIndTable">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Present</th>
                                <th>Absent</th>
                                <th>Late</th>
                                <th>Total Days</th>
                                <th>Attendance %</th>
                            </tr>
                        </thead>
                        <tbody id="anaIndBody"></tbody>
                        <tfoot id="anaIndFoot"></tfoot>
                    </table>
                </div>
            </div>
            <div class="att-empty" id="anaIndEmpty" style="display:none;">
                <p>Enter a person ID, select class/section, and click Search.</p>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div class="att-toast" id="anaToast"></div>

</div><!-- .att-wrap -->
</div>
</section>
</div>

<!-- Chart.js 4.x — self-hosted (served by XAMPP, no CDN dependency so it always
     loads on localhost/offline). Falls back to the CDN only if the local copy is missing. -->
<script src="<?= base_url('assets/js/chart.umd.min.js') ?>?v=4.4.4"></script>
<script>
  if (typeof window.Chart === 'undefined') {
    document.write('<scr' + 'ipt src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"><\/scr' + 'ipt>');
  }
</script>

<script>
(function(){
    "use strict";

    var CSRF_NAME = '<?= $this->security->get_csrf_token_name() ?>';
    var CSRF_HASH = '<?= $this->security->get_csrf_hash() ?>';
    var BASE = '<?= base_url() ?>';

    var classesData = <?= json_encode($Classes ?: []) ?>;

    /* ── Element refs ──────────────────────────────────────── */
    var elMonth     = document.getElementById('anaMonth');
    var elClass     = document.getElementById('anaClass');
    var elSection   = document.getElementById('anaSection');
    var elLoadBtn   = document.getElementById('anaLoadBtn');
    var elExportCsv = document.getElementById('anaExportCsv');
    var elCards     = document.getElementById('anaCards');
    var elAvgPct    = document.getElementById('anaAvgPct');
    var elAvgDelta  = document.getElementById('anaAvgDelta');
    var elRegular   = document.getElementById('anaRegularClass');
    var elAbsent    = document.getElementById('anaAbsentClass');
    var elTotalLate = document.getElementById('anaTotalLate');
    var elLoading   = document.getElementById('anaLoading');
    var elEmpty     = document.getElementById('anaEmpty');
    var elGrid      = document.getElementById('anaAnalyticsGrid');
    var elTrendPanel = document.getElementById('anaTrendPanel');
    var elTrendEmpty = document.getElementById('anaTrendEmpty');
    var elPersonId  = document.getElementById('anaPersonId');
    var elPersonType = document.getElementById('anaPersonType');
    var elIndClass  = document.getElementById('anaIndClass');
    var elIndSection = document.getElementById('anaIndSection');
    var elIndBtn    = document.getElementById('anaIndBtn');
    var elIndLoading = document.getElementById('anaIndLoading');
    var elIndResult = document.getElementById('anaIndResult');
    var elIndBody   = document.getElementById('anaIndBody');
    var elIndFoot   = document.getElementById('anaIndFoot');
    var elIndEmpty  = document.getElementById('anaIndEmpty');
    var elToast     = document.getElementById('anaToast');

    /* ── Last-loaded data (for export + drill-down payloads) ── */
    var lastAnalytics = [];
    var lastTrend     = [];
    var lastMonth     = '';

    /* ── R1 concurrency control ────────────────────────────────
       reqSeq  : monotonically-increasing token; only the response
                 whose token still equals reqSeq may touch the UI.
       curAbort: AbortController for the in-flight load; a new load
                 aborts the previous one (analytics + its trend share
                 one signal), so superseded requests are cancelled at
                 the network layer AND ignored at the UI layer. */
    var reqSeq       = 0;
    var curAbort     = null;
    var loadWatchdog = null;   // force-clears a stuck spinner if a request never settles

    /* ── Small helpers ─────────────────────────────────────── */
    function esc(s) {
        if (s == null) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(s)));
        return d.innerHTML;
    }
    function cssVar(name, fallback) {
        try {
            var v = getComputedStyle(document.documentElement).getPropertyValue(name);
            return (v && v.trim()) || fallback;
        } catch (e) { return fallback; }
    }
    function debounce(fn, ms) {
        var t;
        return function () {
            var ctx = this, args = arguments;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, ms);
        };
    }
    function showToast(msg, type) {
        elToast.textContent = msg;
        elToast.className = 'att-toast ' + (type || 'success');
        setTimeout(function(){ elToast.classList.add('show'); }, 10);
        setTimeout(function(){ elToast.classList.remove('show'); }, 3000);
    }
    function postData(url, data, signal) {
        var fd = new FormData();
        fd.append(CSRF_NAME, CSRF_HASH);
        if (data) { Object.keys(data).forEach(function(k){ fd.append(k, data[k]); }); }
        var opts = { method: 'POST', body: fd };
        if (signal) opts.signal = signal;
        return fetch(BASE + url, opts)
            .then(function(r) {
                var ct = r.headers.get('content-type') || '';
                if (ct.indexOf('application/json') !== -1) return r.json();
                return r.text().then(function(t) {
                    try { return JSON.parse(t); } catch(e) { throw new Error('Invalid response'); }
                });
            })
            .then(function(j) { if (j && j.csrf_hash) CSRF_HASH = j.csrf_hash; return j; });
    }
    function chartsReady() { return typeof window.Chart !== 'undefined'; }

    /* Resolve DS tokens once for the charts. */
    var PALETTE = {
        green:   cssVar('--att-green', '#16a34a'),
        teal:    cssVar('--att-teal', '#BC5A3C'),
        amber:   cssVar('--att-amber', '#d97706'),
        red:     cssVar('--att-red', '#dc2626'),
        blue:    cssVar('--att-blue', '#2563eb'),
        grid:    cssVar('--att-border', '#e5e7eb'),
        t2:      cssVar('--att-t2', '#555555'),
        t3:      cssVar('--att-t3', '#888888'),
        primary: cssVar('--att-primary', '#c9a24b'),
        font:    cssVar('--att-font', 'Plus Jakarta Sans, sans-serif')
    };
    if (chartsReady()) {
        Chart.defaults.font.family = PALETTE.font;
        Chart.defaults.color = PALETTE.t2;
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
    }
    function colorForPct(pct) {
        if (pct >= 90) return PALETTE.green;
        if (pct >= 75) return PALETTE.teal;
        if (pct >= 60) return PALETTE.amber;
        return PALETTE.red;
    }
    function badgeClass(pct) {
        if (pct >= 90) return 'att-badge-green';
        if (pct >= 75) return 'att-badge-teal';
        if (pct >= 60) return 'att-badge-amber';
        return 'att-badge-red';
    }

    /* ═══════════════════════════════════════════════════════ */
    /* CHART MANAGER — one registry; always destroy before draw */
    /* ═══════════════════════════════════════════════════════ */
    var ChartManager = (function(){
        var instances = {};
        return {
            destroy: function(key){
                if (instances[key]) { try { instances[key].destroy(); } catch(e){} delete instances[key]; }
            },
            destroyAll: function(){ var self = this; Object.keys(instances).forEach(function(k){ self.destroy(k); }); },
            render: function(key, canvasId, config){
                if (!chartsReady()) return null;
                this.destroy(key);
                var cv = document.getElementById(canvasId);
                if (!cv) return null;
                // Canonical fix for "Canvas is already in use": kill any chart
                // Chart.js still associates with this canvas before recreating.
                try { var ex = Chart.getChart ? Chart.getChart(cv) : null; if (ex) ex.destroy(); } catch(e){}
                // Never let a render failure throw out of the load flow (which
                // would wipe the whole page to the empty state).
                try {
                    instances[key] = new Chart(cv.getContext('2d'), config);
                } catch(e){
                    try { console.error('[attendance-analytics] chart render failed [' + key + ']:', e); } catch(_){}
                    return null;
                }
                return instances[key];
            },
            resizeAll: function(){
                Object.keys(instances).forEach(function(k){ try { instances[k].resize(); } catch(e){} });
            }
        };
    })();

    /* Force a remeasure on the next frame — belt-and-suspenders so charts get
       correct dimensions even inside AdminLTE panels/transitions. */
    function kickResize(){
        if (typeof requestAnimationFrame === 'function') {
            requestAnimationFrame(function(){ ChartManager.resizeAll(); });
        } else {
            setTimeout(function(){ ChartManager.resizeAll(); }, 30);
        }
    }

    /* ═══════════════════════════════════════════════════════ */
    /* DRILL-DOWN HOOK — single choke point; backend detail views */
    /* are a future phase, so today this only signals intent.     */
    /* ═══════════════════════════════════════════════════════ */
    function handleDrill(type, payload){
        try { console.debug('[attendance-analytics] drill-down', type, payload); } catch(e){}
        // FUTURE: route to a detailed report, e.g.
        //   window.location = BASE + 'attendance/report/' + type + '?...';
        showToast('Detailed ' + type + ' report — coming soon.', 'info');
    }
    function clickIndex(elements){
        return (elements && elements.length) ? elements[0].index : -1;
    }

    /* ═══════════════════════════════════════════════════════ */
    /* RENDER HELPERS                                            */
    /* ═══════════════════════════════════════════════════════ */
    function renderAttendanceBarChart(analytics){
        var sorted = analytics.slice().sort(function(a,b){
            return (parseFloat(b.present_pct)||0) - (parseFloat(a.present_pct)||0);
        });
        var labels = sorted.map(function(a){ return a.label || ((a['class']||'') + ' ' + (a.section||'')); });
        var data   = sorted.map(function(a){ return parseFloat(a.present_pct) || 0; });
        var colors = data.map(colorForPct);

        ChartManager.render('bar', 'anaBarChart', {
            type: 'bar',
            data: { labels: labels, datasets: [{
                label: 'Attendance %', data: data,
                backgroundColor: colors, borderRadius: 6, maxBarThickness: 46
            }]},
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function(c){ return ' ' + c.parsed.y.toFixed(1) + '%'; } } }
                },
                scales: {
                    y: { beginAtZero: true, max: 100, grid: { color: PALETTE.grid },
                         ticks: { callback: function(v){ return v + '%'; } } },
                    x: { grid: { display: false }, ticks: { autoSkip: false, maxRotation: 60, minRotation: 0 } }
                },
                onClick: function(evt, els, chart){
                    var i = clickIndex(els); if (i < 0) return;
                    handleDrill('class', { index: i, label: chart.data.labels[i], row: sorted[i] || null });
                }
            }
        });
    }

    function renderCompositionChart(analytics){
        var agg = { P:0, T:0, A:0, L:0 };
        analytics.forEach(function(a){
            var t = a.totals || {};
            agg.P += parseInt(t.P,10) || 0;
            agg.T += parseInt(t.T,10) || 0;
            agg.A += parseInt(t.A,10) || 0;
            agg.L += parseInt(t.L,10) || 0;
        });
        var labels = ['Present','Late','Absent','Leave'];
        var data   = [agg.P, agg.T, agg.A, agg.L];
        var colors = [PALETTE.green, PALETTE.amber, PALETTE.red, PALETTE.blue];

        ChartManager.render('donut', 'anaDonutChart', {
            type: 'doughnut',
            data: { labels: labels, datasets: [{ data: data, backgroundColor: colors, borderWidth: 0, hoverOffset: 6 }] },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '62%',
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: function(c){
                        var tot = c.dataset.data.reduce(function(s,v){ return s + v; }, 0);
                        var pc = tot > 0 ? ((c.parsed / tot) * 100).toFixed(1) : '0.0';
                        return ' ' + c.label + ': ' + c.parsed + ' (' + pc + '%)';
                    } } }
                },
                onClick: function(evt, els){
                    var i = clickIndex(els); if (i < 0) return;
                    handleDrill('composition', { index: i, segment: labels[i], value: data[i] });
                }
            }
        });
    }

    function renderTrendChart(trend){
        // cached===false → no data for that month → null so the line shows a gap
        var labels = trend.map(function(t){ return t.month ? t.month.substring(0,3) : ''; });
        var data   = trend.map(function(t){ return (t.cached === false) ? null : (parseFloat(t.present_pct) || 0); });
        var hasData = data.some(function(v){ return v !== null; });

        if (!hasData) {
            ChartManager.destroy('trend');
            elTrendEmpty.style.display = 'block';
            return;
        }
        elTrendEmpty.style.display = 'none';

        ChartManager.render('trend', 'anaTrendChart', {
            type: 'line',
            data: { labels: labels, datasets: [{
                label: 'Attendance %', data: data,
                borderColor: PALETTE.primary, backgroundColor: 'rgba(201,162,75,.14)',
                fill: true, tension: 0.35, spanGaps: false,
                pointRadius: 4, pointHoverRadius: 6,
                pointBackgroundColor: PALETTE.primary, borderWidth: 2
            }]},
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function(c){
                        return c.parsed.y == null ? ' No data' : ' ' + c.parsed.y.toFixed(1) + '%';
                    } } }
                },
                scales: {
                    y: { beginAtZero: true, max: 100, grid: { color: PALETTE.grid },
                         ticks: { callback: function(v){ return v + '%'; } } },
                    x: { grid: { display: false } }
                },
                onClick: function(evt, els){
                    var i = clickIndex(els); if (i < 0) return;
                    handleDrill('trend', { index: i, month: trend[i] ? trend[i].month : '', year: trend[i] ? trend[i].year : '' });
                }
            }
        });
    }

    /* ── KPI summary + previous-period delta ───────────────── */
    function renderSummary(analytics){
        var totalPct = 0, totalLate = 0;
        var bestPct = -1, bestLabel = '--';
        var worstPct = 101, worstLabel = '--';

        analytics.forEach(function(a){
            var pct = parseFloat(a.present_pct) || 0;
            totalPct += pct;
            totalLate += parseInt(a.late_count, 10) || 0;
            if (pct > bestPct)  { bestPct = pct;  bestLabel = a.label || (a['class'] + ' ' + a.section); }
            if (pct < worstPct) { worstPct = pct; worstLabel = a.label || (a['class'] + ' ' + a.section); }
        });

        var avg = analytics.length > 0 ? (totalPct / analytics.length).toFixed(1) : '0';
        elAvgPct.textContent = avg + '%';
        elRegular.textContent = bestLabel;  elRegular.title = bestPct.toFixed(1) + '%';
        elAbsent.textContent = worstLabel;  elAbsent.title = worstPct.toFixed(1) + '%';
        elTotalLate.textContent = totalLate;
    }

    function updateAvgDelta(trend){
        // Previous-period comparison from the trend series (same, consistent basis).
        elAvgDelta.style.display = 'none';
        if (!trend || !trend.length || !lastMonth) return;

        var curIdx = -1;
        for (var i = 0; i < trend.length; i++) {
            if (trend[i].month === lastMonth) { curIdx = i; break; }
        }
        if (curIdx < 0 || trend[curIdx].cached === false) return;

        var prevIdx = -1;
        for (var j = curIdx - 1; j >= 0; j--) {
            if (trend[j].cached !== false) { prevIdx = j; break; }
        }
        if (prevIdx < 0) return;

        var cur  = parseFloat(trend[curIdx].present_pct)  || 0;
        var prev = parseFloat(trend[prevIdx].present_pct) || 0;
        var diff = +(cur - prev).toFixed(1);

        var cls = 'att-delta-flat', arrow = '▬';
        if (diff > 0) { cls = 'att-delta-up';   arrow = '▲'; }
        else if (diff < 0) { cls = 'att-delta-down'; arrow = '▼'; }

        elAvgDelta.className = 'att-kpi-delta ' + cls;
        elAvgDelta.textContent = arrow + ' ' + Math.abs(diff) + '% vs ' + trend[prevIdx].month.substring(0,3);
        elAvgDelta.style.display = 'inline-flex';
    }

    /* ═══════════════════════════════════════════════════════ */
    /* LOAD ANALYTICS (auto-refresh + manual)                   */
    /* ═══════════════════════════════════════════════════════ */
    function setLoading(on){
        if (on) {
            elLoading.style.display = 'block';
            elEmpty.style.display = 'none';
            elCards.style.display = 'none';
            elGrid.style.display = 'none';
            elTrendPanel.style.display = 'none';
            elLoadBtn.disabled = true;
        } else {
            elLoading.style.display = 'none';
            elLoadBtn.disabled = false;
        }
    }

    function loadAnalytics(){
        var month = elMonth.value;
        if (!month) { showToast('Please select a month.', 'error'); return; }

        /* R1: supersede any in-flight load — cancel it and claim a new token. */
        var myReq = ++reqSeq;
        if (curAbort) { try { curAbort.abort(); } catch(e){} }
        curAbort = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        var signal = curAbort ? curAbort.signal : undefined;

        setLoading(true);

        /* Watchdog: if this request never settles (server hang / dropped
           connection), don't spin forever — clear the spinner and surface it. */
        if (loadWatchdog) clearTimeout(loadWatchdog);
        loadWatchdog = setTimeout(function(){
            if (myReq === reqSeq) {                 // still the active request → it stalled
                if (curAbort) { try { curAbort.abort(); } catch(e){} }
                setLoading(false);
                ChartManager.destroyAll();
                elEmpty.style.display = 'block';
                showToast('Loading timed out — the server did not respond. Click Refresh to retry.', 'error');
            }
        }, 15000);

        var payload = { month: month };
        if (elClass.value) payload['class'] = elClass.value;
        if (elSection.value) payload.section = elSection.value;

        postData('attendance/fetch_analytics', payload, signal)
            .then(function(res){
                if (myReq !== reqSeq) return;   // R1: a newer load superseded us — ignore stale response
                clearTimeout(loadWatchdog);
                setLoading(false);

                if (!res || res.status === 'error') {
                    showToast(res && res.message ? res.message : 'Failed to load analytics.', 'error');
                    ChartManager.destroyAll();
                    elEmpty.style.display = 'block';
                    return;
                }

                var analytics = res.analytics || [];
                lastAnalytics = analytics;
                lastMonth = month;

                if (analytics.length === 0) {
                    ChartManager.destroyAll();
                    elEmpty.style.display = 'block';
                    return;
                }

                renderSummary(analytics);

                // Containers MUST be visible before charts are created, otherwise
                // Chart.js measures a 0x0 canvas (hidden parent) and nothing draws.
                elCards.style.display = 'grid';
                elGrid.style.display = 'grid';

                renderAttendanceBarChart(analytics);
                renderCompositionChart(analytics);
                kickResize();

                loadTrend(myReq, signal);
            })
            .catch(function(err){
                if (myReq !== reqSeq) return;   // R1: stale (incl. aborted) — do not touch UI
                if (err && err.name === 'AbortError') return;
                clearTimeout(loadWatchdog);
                setLoading(false);
                try { console.error('[attendance-analytics] load failed:', err); } catch(e){}
                showToast('Could not load analytics: ' + ((err && err.message) || 'network error') + '.', 'error');
                ChartManager.destroyAll();
                elEmpty.style.display = 'block';
            });
    }

    function loadTrend(token, signal){
        elTrendPanel.style.display = 'block';
        elTrendEmpty.style.display = 'none';

        var payload = {};
        if (elClass.value) payload['class'] = elClass.value;
        if (elSection.value) payload.section = elSection.value;

        return postData('attendance/fetch_monthly_trend', payload, signal)
            .then(function(res){
                if (token !== reqSeq) return;   // R1: superseded — ignore stale trend
                var trend = (res && res.trend) ? res.trend : [];
                lastTrend = trend;
                renderTrendChart(trend);
                updateAvgDelta(trend);
                kickResize();
            })
            .catch(function(err){
                if (token !== reqSeq) return;   // R1: stale (incl. aborted)
                if (err && err.name === 'AbortError') return;
                ChartManager.destroy('trend');
                elTrendEmpty.style.display = 'block';
            });
    }

    /* ── CSV export (client-side; no backend) ──────────────── */
    function csvCell(v){
        v = String(v == null ? '' : v);
        if (/[",\r\n]/.test(v)) v = '"' + v.replace(/"/g, '""') + '"';
        return v;
    }
    function exportCsv(){
        if (!lastAnalytics || !lastAnalytics.length) { showToast('Nothing to export yet.', 'error'); return; }
        var rows = [['Class','Section','Students','Present %','Absent %','Late Count']];
        lastAnalytics.forEach(function(a){
            rows.push([a['class'] || '', a.section || '', a.students || 0,
                       a.present_pct || 0, a.absent_pct || 0, a.late_count || 0]);
        });
        var csv = rows.map(function(r){ return r.map(csvCell).join(','); }).join('\r\n');
        var blob = new Blob(["﻿" + csv], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'attendance-analytics-' + (lastMonth || 'export') + '.csv';
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        URL.revokeObjectURL(url);
        showToast('CSV exported.', 'success');
    }

    /* ═══════════════════════════════════════════════════════ */
    /* FILTERS — auto-refresh (debounced) + manual + sections   */
    /* ═══════════════════════════════════════════════════════ */
    function populateSections(selectEl, className) {
        selectEl.innerHTML = '<option value="">All Sections</option>';
        if (!className) { selectEl.disabled = true; return; }
        var seen = {};
        classesData.forEach(function(c) {
            if (c.class_name === className && !seen[c.section]) {
                seen[c.section] = true;
                var o = document.createElement('option');
                o.value = c.section; o.textContent = c.section;
                selectEl.appendChild(o);
            }
        });
        selectEl.disabled = false;
    }

    var debouncedLoad = debounce(loadAnalytics, 250);

    /* Instant feedback: show the spinner the moment a filter changes, THEN
       debounce the actual fetch. Removes the "dead time" latency where the
       page looked frozen between the change and the request firing. */
    function onFilterChange(){
        setLoading(true);
        debouncedLoad();
    }

    elMonth.addEventListener('change', onFilterChange);
    elClass.addEventListener('change', function(){
        populateSections(elSection, elClass.value);   // reset sections to match class
        onFilterChange();
    });
    elSection.addEventListener('change', onFilterChange);
    elLoadBtn.addEventListener('click', loadAnalytics);       // manual refresh (immediate)
    elExportCsv.addEventListener('click', exportCsv);

    /* ═══════════════════════════════════════════════════════ */
    /* INDIVIDUAL REPORT                                        */
    /* ═══════════════════════════════════════════════════════ */
    elIndBtn.addEventListener('click', loadIndividual);

    elIndClass.addEventListener('change', function() {
        var cls = elIndClass.value;
        elIndSection.innerHTML = '<option value="">Select Section</option>';
        if (!cls) { elIndSection.disabled = true; return; }
        var seen = {};
        classesData.forEach(function(c) {
            if (c.class_name === cls && !seen[c.section]) {
                seen[c.section] = true;
                var o = document.createElement('option');
                o.value = c.section; o.textContent = c.section;
                elIndSection.appendChild(o);
            }
        });
        elIndSection.disabled = false;
    });

    /* Class/Section apply to STUDENTS only — hide them for Staff. */
    function syncIndividualType() {
        var isStudent = elPersonType.value === 'student';
        var cfg = document.getElementById('anaIndClassFg');
        var sfg = document.getElementById('anaIndSectionFg');
        if (cfg) cfg.style.display = isStudent ? '' : 'none';
        if (sfg) sfg.style.display = isStudent ? '' : 'none';
        elPersonId.placeholder = isStudent ? 'Enter student ID' : 'Enter staff ID (e.g. STA0001)';
    }
    elPersonType.addEventListener('change', syncIndividualType);
    syncIndividualType();

    function loadIndividual() {
        var pid = elPersonId.value.trim();
        var ptype = elPersonType.value;
        var cls = elIndClass.value;
        var sec = elIndSection.value;

        if (!pid) { showToast('Please enter a person ID.', 'error'); return; }
        if (ptype === 'student' && (!cls || !sec)) { showToast('Please select class and section.', 'error'); return; }

        elIndResult.style.display = 'none';
        elIndEmpty.style.display = 'none';
        document.getElementById('anaIndPersonInfo').style.display = 'none';
        elIndLoading.style.display = 'block';
        elIndBtn.disabled = true;

        postData('attendance/fetch_individual_report', {
            person_id: pid, person_type: ptype, 'class': cls, section: sec
        })
            .then(function(res) {
                elIndLoading.style.display = 'none';
                elIndBtn.disabled = false;

                if (!res || res.status === 'error') {
                    showToast(res && res.message ? res.message : 'No data found.', 'error');
                    elIndEmpty.style.display = 'block';
                    return;
                }

                var months = res.months || [];
                var totals = res.totals || {};

                var elInfo = document.getElementById('anaIndPersonInfo');
                var pName = res.person_name || '';
                if (pName) {
                    document.getElementById('anaIndPersonName').textContent = pName;
                    document.getElementById('anaIndPersonId2').textContent = res.person_id || pid;
                    var classWrap = document.getElementById('anaIndPersonClassWrap');
                    var classEl = document.getElementById('anaIndPersonClass');
                    if (res.person_type === 'student' && (res.person_class || res.person_section)) {
                        classEl.textContent = 'Class ' + (res.person_class || '') + (res.person_section ? ' — Section ' + res.person_section : '');
                        classWrap.style.display = '';
                    } else {
                        classWrap.style.display = 'none';
                    }
                    elInfo.style.display = 'flex';
                } else {
                    elInfo.style.display = 'none';
                }

                if (months.length === 0) {
                    elIndEmpty.style.display = 'block';
                    elIndEmpty.querySelector('p').textContent = 'No attendance records found for this person.';
                    return;
                }

                var bodyHtml = '';
                months.forEach(function(m) {
                    var st = m.stats || {};
                    var present = parseInt(st.P, 10) || 0;
                    var absent  = parseInt(st.A, 10) || 0;
                    var late    = parseInt(st.T, 10) || 0;
                    var leave   = parseInt(st.L, 10) || 0;
                    var total   = present + absent + late + leave;
                    var pct = (st.present_pct != null) ? Number(st.present_pct).toFixed(1)
                              : (total > 0 ? (((present + late) / total) * 100).toFixed(1) : '0.0');
                    bodyHtml += '<tr>'
                        + '<td>' + esc(m.month) + (m.year ? ' ' + esc(m.year) : '') + '</td>'
                        + '<td class="att-mono">' + present + '</td>'
                        + '<td class="att-mono">' + absent + '</td>'
                        + '<td class="att-mono">' + late + '</td>'
                        + '<td class="att-mono">' + total + '</td>'
                        + '<td><span class="att-badge ' + badgeClass(parseFloat(pct)) + '">' + pct + '%</span></td>'
                        + '</tr>';
                });
                elIndBody.innerHTML = bodyHtml;

                var tPresent = parseInt(totals.P, 10) || 0;
                var tAbsent  = parseInt(totals.A, 10) || 0;
                var tLate    = parseInt(totals.T, 10) || 0;
                var tLeave   = parseInt(totals.L, 10) || 0;
                var tTotal   = tPresent + tAbsent + tLate + tLeave;
                var tPct = (totals.present_pct != null) ? Number(totals.present_pct).toFixed(1)
                           : (tTotal > 0 ? (((tPresent + tLate) / tTotal) * 100).toFixed(1) : '0.0');

                elIndFoot.innerHTML = '<tr>'
                    + '<td>Total</td>'
                    + '<td class="att-mono">' + tPresent + '</td>'
                    + '<td class="att-mono">' + tAbsent + '</td>'
                    + '<td class="att-mono">' + tLate + '</td>'
                    + '<td class="att-mono">' + tTotal + '</td>'
                    + '<td><span class="att-badge ' + badgeClass(parseFloat(tPct)) + '">' + tPct + '%</span></td>'
                    + '</tr>';

                elIndResult.style.display = 'block';
            })
            .catch(function() {
                elIndLoading.style.display = 'none';
                elIndBtn.disabled = false;
                showToast('Network error. Please try again.', 'error');
                elIndEmpty.style.display = 'block';
            });
    }

    /* ── Init: graceful degrade if the chart CDN is unreachable, then auto-load ── */
    if (!chartsReady()) {
        showToast('Chart library unavailable — showing data without charts.', 'error');
    }
    loadAnalytics();

})();
</script>
