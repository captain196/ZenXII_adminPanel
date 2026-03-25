<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<style>
:root {
    --ana-primary: var(--gold);
    --ana-primary-dim: var(--gold-dim);
    --ana-primary-glow: var(--gold-glow);
    --ana-bg: var(--bg);
    --ana-bg2: var(--bg2);
    --ana-bg3: var(--bg3);
    --ana-border: var(--border);
    --ana-t1: var(--t1);
    --ana-t2: var(--t2);
    --ana-t3: var(--t3);
    --ana-shadow: var(--sh);
    --ana-ease: var(--ease);
    --ana-r: 10px;
    --ana-font: var(--font-b, 'Plus Jakarta Sans', sans-serif);
    --ana-font-m: var(--font-m, 'JetBrains Mono', monospace);
    --ana-green: #16a34a;
    --ana-teal: #0f766e;
    --ana-orange: #d97706;
    --ana-red: #dc2626;
    --ana-blue: #2563eb;
    --ana-green-bg: rgba(22,163,74,.12);
    --ana-teal-bg: rgba(15,118,110,.12);
    --ana-orange-bg: rgba(217,119,6,.12);
    --ana-red-bg: rgba(220,38,38,.12);
    --ana-blue-bg: rgba(37,99,235,.12);
}

/* -- Page Header -- */
.ana-page-hdr {
    margin-bottom: 18px;
}
.ana-page-hdr h4 {
    font-family: var(--ana-font); font-weight: 700; color: var(--ana-t1);
    margin: 0 0 4px; font-size: 18px;
}
.ana-page-hdr h4 i { color: var(--ana-primary); margin-right: 6px; }
.ana-page-hdr p {
    font-family: var(--ana-font); font-size: 13px; color: var(--ana-t3); margin: 0;
}

/* -- Filter Bar -- */
.ana-filter-bar {
    display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;
    padding: 18px 20px; border-radius: var(--ana-r);
    background: var(--ana-bg2); border: 1px solid var(--ana-border);
    box-shadow: var(--ana-shadow); margin-bottom: 20px;
}
.ana-fg { display: flex; flex-direction: column; gap: 4px; }
.ana-fg label {
    font-family: var(--ana-font); font-size: 11px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .5px; color: var(--ana-t3);
}
.ana-fg select, .ana-fg input[type="text"] {
    font-family: var(--ana-font); font-size: 13px; height: 38px;
    border-radius: 6px; padding: 0 12px; border: 1px solid var(--ana-border);
    background: var(--ana-bg); color: var(--ana-t1); transition: var(--ana-ease);
    min-width: 150px;
}
.ana-fg select:focus, .ana-fg input:focus {
    outline: none; border-color: var(--ana-primary);
    box-shadow: 0 0 0 3px var(--ana-primary-dim);
}
.ana-btn {
    font-family: var(--ana-font); font-size: 13px; font-weight: 600;
    border: none; border-radius: 6px; padding: 0 20px; height: 38px;
    cursor: pointer; transition: var(--ana-ease); display: inline-flex;
    align-items: center; gap: 6px;
}
.ana-btn-primary { background: var(--ana-primary); color: #fff; }
.ana-btn-primary:hover { opacity: .88; }
.ana-btn-primary:disabled { opacity: .45; cursor: not-allowed; }

/* -- Summary Cards -- */
.ana-cards {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 16px; margin-bottom: 24px;
}
.ana-card {
    background: var(--ana-bg2); border: 1px solid var(--ana-border);
    border-radius: var(--ana-r); padding: 20px;
    box-shadow: var(--ana-shadow); position: relative; overflow: hidden;
    transition: transform .2s ease, box-shadow .2s ease;
}
.ana-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,0,0,.08);
}
.ana-card::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0;
    height: 3px; border-radius: var(--ana-r) var(--ana-r) 0 0;
}
.ana-card.avg::before { background: var(--ana-green); }
.ana-card.regular::before { background: var(--ana-blue); }
.ana-card.absent::before { background: var(--ana-red); }
.ana-card.late::before { background: var(--ana-orange); }

.ana-card-icon {
    width: 40px; height: 40px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 12px; font-size: 18px;
}
.ana-card.avg .ana-card-icon { background: var(--ana-green-bg); color: var(--ana-green); }
.ana-card.regular .ana-card-icon { background: var(--ana-blue-bg); color: var(--ana-blue); }
.ana-card.absent .ana-card-icon { background: var(--ana-red-bg); color: var(--ana-red); }
.ana-card.late .ana-card-icon { background: var(--ana-orange-bg); color: var(--ana-orange); }

.ana-card-value {
    font-family: var(--ana-font-m); font-size: 26px; font-weight: 700;
    color: var(--ana-t1); margin-bottom: 4px;
}
.ana-card-label {
    font-family: var(--ana-font); font-size: 12px; color: var(--ana-t3);
    text-transform: uppercase; letter-spacing: .4px; font-weight: 600;
}

/* -- Section Panels -- */
.ana-panel {
    background: var(--ana-bg2); border: 1px solid var(--ana-border);
    border-radius: var(--ana-r); box-shadow: var(--ana-shadow);
    margin-bottom: 24px; overflow: hidden;
}
.ana-panel-hdr {
    padding: 16px 20px; border-bottom: 1px solid var(--ana-border);
    font-family: var(--ana-font); font-size: 14px; font-weight: 700;
    color: var(--ana-t1); display: flex; align-items: center; gap: 8px;
}
.ana-panel-hdr i { color: var(--ana-primary); }
.ana-panel-body { padding: 20px; }

/* -- Bar Chart -- */
.ana-bar-row {
    display: flex; align-items: center; gap: 12px; margin-bottom: 10px;
}
.ana-bar-label {
    font-family: var(--ana-font); font-size: 12px; font-weight: 600;
    color: var(--ana-t2); min-width: 140px; text-align: right;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ana-bar-track {
    flex: 1; height: 28px; background: var(--ana-bg3);
    border-radius: 6px; overflow: hidden; position: relative;
}
.ana-bar {
    height: 100%; border-radius: 6px; display: flex; align-items: center;
    padding: 0 10px; font-family: var(--ana-font-m); font-size: 11px;
    font-weight: 700; color: #fff; transition: width .5s ease;
    min-width: 0;
}
.ana-bar.green { background: var(--ana-green); }
.ana-bar.teal { background: var(--ana-teal); }
.ana-bar.orange { background: var(--ana-orange); }
.ana-bar.red { background: var(--ana-red); }

.ana-bar-meta {
    font-family: var(--ana-font-m); font-size: 11px; color: var(--ana-t3);
    min-width: 40px;
}

/* -- Trend Table -- */
.ana-trend-table {
    width: 100%; border-collapse: collapse; font-family: var(--ana-font);
}
.ana-trend-table th {
    font-size: 11px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .3px; color: var(--ana-t3); padding: 10px 12px;
    text-align: center; border-bottom: 2px solid var(--ana-border);
    background: var(--ana-bg3);
}
.ana-trend-table td {
    padding: 10px 12px; text-align: center; border-bottom: 1px solid var(--ana-border);
    font-size: 13px; font-weight: 600;
}
.ana-trend-cell {
    display: inline-block; padding: 4px 10px; border-radius: 6px;
    font-family: var(--ana-font-m); font-size: 12px; font-weight: 700;
}
.ana-trend-cell.green { background: var(--ana-green-bg); color: var(--ana-green); }
.ana-trend-cell.teal { background: var(--ana-teal-bg); color: var(--ana-teal); }
.ana-trend-cell.orange { background: var(--ana-orange-bg); color: var(--ana-orange); }
.ana-trend-cell.red { background: var(--ana-red-bg); color: var(--ana-red); }

/* -- Individual Report -- */
.ana-individual {
    display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;
    margin-bottom: 16px;
}
.ana-individual .ana-fg select,
.ana-individual .ana-fg input[type="text"] {
    min-width: 120px;
}
.ana-report-table {
    width: 100%; border-collapse: collapse; font-family: var(--ana-font);
}
.ana-report-table th {
    font-size: 11px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .3px; color: var(--ana-t3); padding: 10px 12px;
    text-align: left; border-bottom: 2px solid var(--ana-border);
    background: var(--ana-bg3);
}
.ana-report-table td {
    padding: 10px 12px; border-bottom: 1px solid var(--ana-border);
    font-size: 13px; color: var(--ana-t1);
}
.ana-report-table td.mono {
    font-family: var(--ana-font-m); font-size: 12px;
}
.ana-report-table tfoot td {
    font-weight: 700; border-top: 2px solid var(--ana-border);
}

/* -- Empty / Loading -- */
.ana-empty {
    text-align: center; padding: 48px 20px; color: var(--ana-t3);
    font-family: var(--ana-font); font-size: 14px;
}
.ana-empty i { font-size: 36px; display: block; margin-bottom: 12px; opacity: .5; }
.ana-loading {
    text-align: center; padding: 32px 20px; color: var(--ana-t3);
    font-family: var(--ana-font); font-size: 13px; display: none;
}
.ana-loading i { margin-right: 6px; }

/* -- Toast -- */
.ana-toast {
    position: fixed; bottom: 24px; right: 24px; z-index: 9999;
    padding: 12px 22px; border-radius: 8px; font-family: var(--ana-font);
    font-size: 13px; font-weight: 600; color: #fff;
    box-shadow: 0 8px 24px rgba(0,0,0,.25);
    transform: translateY(100px); opacity: 0; transition: all .3s ease;
    pointer-events: none;
}
.ana-toast.show { transform: translateY(0); opacity: 1; }
.ana-toast.success { background: var(--ana-green); }
.ana-toast.error { background: var(--ana-red); }

/* -- Responsive -- */
@media (max-width: 1024px) {
    .ana-cards { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 767px) {
    .ana-cards { grid-template-columns: 1fr; }
    .ana-filter-bar { flex-direction: column; align-items: stretch; }
    .ana-fg select, .ana-fg input[type="text"] { min-width: 0; width: 100%; }
    .ana-bar-label { min-width: 80px; font-size: 11px; }
    .ana-individual { flex-direction: column; align-items: stretch; }
}
/* Person info banner */
.ana-person-info{display:flex;align-items:center;gap:14px;padding:14px 18px;margin-bottom:16px;
    background:var(--ana-gold-dim);border:1px solid var(--ana-gold-ring);border-radius:8px}
.ana-person-info i.fa-user-circle{font-size:32px;color:var(--ana-gold);flex-shrink:0}
.ana-person-name{font-family:var(--ana-font-b);font-size:15px;font-weight:700;color:var(--ana-t1);line-height:1.3}
.ana-person-meta{font-family:var(--ana-font-m);font-size:11.5px;color:var(--ana-t3);margin-top:2px;display:flex;gap:10px;flex-wrap:wrap}
.ana-person-meta span{display:inline-flex;align-items:center;gap:4px}
@media (max-width: 479px) {
    .ana-panel-body { padding: 12px; }
    .ana-bar-label { min-width: 60px; font-size: 10px; }
}
</style>

<div class="content-wrapper">
<section class="content">
<div class="container-fluid">

    <!-- Page Header -->
    <div class="ana-page-hdr">
        <h4>
            <i class="fa fa-bar-chart"></i>
            Attendance Analytics
        </h4>
        <p>View attendance trends, class-wise breakdowns, and individual reports</p>
    </div>

    <!-- Filter Bar -->
    <div class="ana-filter-bar">
        <div class="ana-fg">
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
        <div class="ana-fg">
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
        <div class="ana-fg">
            <label for="anaSection">Section (Optional)</label>
            <select id="anaSection" disabled>
                <option value="">All Sections</option>
            </select>
        </div>
        <div class="ana-fg" style="align-self:flex-end;">
            <button type="button" class="ana-btn ana-btn-primary" id="anaLoadBtn">
                <i class="fa fa-search"></i> Load
            </button>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="ana-cards" id="anaCards" style="display:none;">
        <div class="ana-card avg">
            <div class="ana-card-icon"><i class="fa fa-pie-chart"></i></div>
            <div class="ana-card-value" id="anaAvgPct">--</div>
            <div class="ana-card-label">Avg Attendance %</div>
        </div>
        <div class="ana-card regular">
            <div class="ana-card-icon"><i class="fa fa-trophy"></i></div>
            <div class="ana-card-value" id="anaRegularClass">--</div>
            <div class="ana-card-label">Most Regular Class</div>
        </div>
        <div class="ana-card absent">
            <div class="ana-card-icon"><i class="fa fa-exclamation-triangle"></i></div>
            <div class="ana-card-value" id="anaAbsentClass">--</div>
            <div class="ana-card-label">Highest Absenteeism</div>
        </div>
        <div class="ana-card late">
            <div class="ana-card-icon"><i class="fa fa-clock-o"></i></div>
            <div class="ana-card-value" id="anaTotalLate">--</div>
            <div class="ana-card-label">Total Late Arrivals</div>
        </div>
    </div>

    <!-- Loading -->
    <div class="ana-loading" id="anaLoading">
        <i class="fa fa-spinner fa-spin"></i> Loading analytics...
    </div>

    <!-- Empty State -->
    <div class="ana-empty" id="anaEmpty">
        <i class="fa fa-bar-chart"></i>
        <p>Select a month and click Load to view attendance analytics.</p>
    </div>

    <!-- Class-wise Bar Chart -->
    <div class="ana-panel" id="anaBarPanel" style="display:none;">
        <div class="ana-panel-hdr">
            <i class="fa fa-align-left"></i> Class-wise Attendance
        </div>
        <div class="ana-panel-body" id="anaBarBody"></div>
    </div>

    <!-- Monthly Trend -->
    <div class="ana-panel" id="anaTrendPanel" style="display:none;">
        <div class="ana-panel-hdr">
            <i class="fa fa-line-chart"></i> Monthly Trend
        </div>
        <div class="ana-panel-body">
            <div style="overflow-x:auto;">
                <table class="ana-trend-table" id="anaTrendTable">
                    <thead id="anaTrendHead"></thead>
                    <tbody id="anaTrendBody"></tbody>
                </table>
            </div>
            <div class="ana-empty" id="anaTrendEmpty" style="display:none;">
                <p>Select a specific class to view monthly trend.</p>
            </div>
        </div>
    </div>

    <!-- Individual Search -->
    <div class="ana-panel" id="anaIndPanel">
        <div class="ana-panel-hdr">
            <i class="fa fa-user"></i> Individual Attendance Report
        </div>
        <div class="ana-panel-body">
            <div class="ana-individual">
                <div class="ana-fg">
                    <label for="anaPersonId">Person ID</label>
                    <input type="text" id="anaPersonId" placeholder="Enter ID">
                </div>
                <div class="ana-fg">
                    <label for="anaPersonType">Type</label>
                    <select id="anaPersonType">
                        <option value="student">Student</option>
                        <option value="staff">Staff</option>
                    </select>
                </div>
                <div class="ana-fg">
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
                <div class="ana-fg">
                    <label for="anaIndSection">Section</label>
                    <select id="anaIndSection" disabled>
                        <option value="">Select Section</option>
                    </select>
                </div>
                <div class="ana-fg" style="align-self:flex-end;">
                    <button type="button" class="ana-btn ana-btn-primary" id="anaIndBtn">
                        <i class="fa fa-search"></i> Search
                    </button>
                </div>
            </div>
            <div class="ana-loading" id="anaIndLoading">
                <i class="fa fa-spinner fa-spin"></i> Loading report...
            </div>
            <div id="anaIndPersonInfo" class="ana-person-info" style="display:none;">
                <i class="fa fa-user-circle"></i>
                <div>
                    <div class="ana-person-name" id="anaIndPersonName"></div>
                    <div class="ana-person-meta">
                        <span><i class="fa fa-id-badge"></i> <em id="anaIndPersonId2"></em></span>
                        <span id="anaIndPersonClassWrap"><i class="fa fa-graduation-cap"></i> <em id="anaIndPersonClass"></em></span>
                    </div>
                </div>
            </div>
            <div id="anaIndResult" style="display:none;">
                <div style="overflow-x:auto;">
                    <table class="ana-report-table" id="anaIndTable">
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
            <div class="ana-empty" id="anaIndEmpty" style="display:none;">
                <p>Enter a person ID, select class/section, and click Search.</p>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div class="ana-toast" id="anaToast"></div>

</div>
</section>
</div>

<script>
(function(){
    "use strict";

    var CSRF_NAME = '<?= $this->security->get_csrf_token_name() ?>';
    var CSRF_HASH = '<?= $this->security->get_csrf_hash() ?>';
    var BASE = '<?= base_url() ?>';

    var classesData = <?= json_encode($Classes ?: []) ?>;

    /* -- Refs -- */
    var elMonth     = document.getElementById('anaMonth');
    var elClass     = document.getElementById('anaClass');
    var elSection   = document.getElementById('anaSection');
    var elLoadBtn   = document.getElementById('anaLoadBtn');
    var elCards     = document.getElementById('anaCards');
    var elAvgPct    = document.getElementById('anaAvgPct');
    var elRegular   = document.getElementById('anaRegularClass');
    var elAbsent    = document.getElementById('anaAbsentClass');
    var elTotalLate = document.getElementById('anaTotalLate');
    var elLoading   = document.getElementById('anaLoading');
    var elEmpty     = document.getElementById('anaEmpty');
    var elBarPanel  = document.getElementById('anaBarPanel');
    var elBarBody   = document.getElementById('anaBarBody');
    var elTrendPanel = document.getElementById('anaTrendPanel');
    var elTrendHead = document.getElementById('anaTrendHead');
    var elTrendBody = document.getElementById('anaTrendBody');
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

    /* -- Helpers -- */
    function esc(s) {
        if (s == null) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(s)));
        return d.innerHTML;
    }

    function showToast(msg, type) {
        elToast.textContent = msg;
        elToast.className = 'ana-toast ' + (type || 'success');
        setTimeout(function(){ elToast.classList.add('show'); }, 10);
        setTimeout(function(){ elToast.classList.remove('show'); }, 3000);
    }

    function postData(url, data) {
        var fd = new FormData();
        fd.append(CSRF_NAME, CSRF_HASH);
        if (data) {
            Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
        }
        return fetch(BASE + url, { method: 'POST', body: fd })
            .then(function(r) {
                var ct = r.headers.get('content-type') || '';
                if (ct.indexOf('application/json') !== -1) return r.json();
                return r.text().then(function(t) {
                    try { return JSON.parse(t); } catch(e) { throw new Error('Invalid response'); }
                });
            })
            .then(function(j) {
                if (j && j.csrf_hash) CSRF_HASH = j.csrf_hash;
                return j;
            });
    }

    function barColor(pct) {
        if (pct >= 90) return 'green';
        if (pct >= 75) return 'teal';
        if (pct >= 60) return 'orange';
        return 'red';
    }

    function populateSections(selectEl, className) {
        selectEl.innerHTML = '<option value="">All Sections</option>';
        if (!className) { selectEl.disabled = true; return; }
        var seen = {};
        classesData.forEach(function(c) {
            if (c.class_name === className && !seen[c.section]) {
                seen[c.section] = true;
                var o = document.createElement('option');
                o.value = c.section;
                o.textContent = c.section;
                selectEl.appendChild(o);
            }
        });
        selectEl.disabled = false;
    }

    /* -- Section dropdowns -- */
    elClass.addEventListener('change', function() {
        populateSections(elSection, elClass.value);
    });
    elIndClass.addEventListener('change', function() {
        var cls = elIndClass.value;
        elIndSection.innerHTML = '<option value="">Select Section</option>';
        if (!cls) { elIndSection.disabled = true; return; }
        var seen = {};
        classesData.forEach(function(c) {
            if (c.class_name === cls && !seen[c.section]) {
                seen[c.section] = true;
                var o = document.createElement('option');
                o.value = c.section;
                o.textContent = c.section;
                elIndSection.appendChild(o);
            }
        });
        elIndSection.disabled = false;
    });

    /* -- Load Analytics -- */
    elLoadBtn.addEventListener('click', loadAnalytics);

    function loadAnalytics() {
        var month = elMonth.value;
        if (!month) { showToast('Please select a month.', 'error'); return; }

        elEmpty.style.display = 'none';
        elCards.style.display = 'none';
        elBarPanel.style.display = 'none';
        elTrendPanel.style.display = 'none';
        elLoading.style.display = 'block';
        elLoadBtn.disabled = true;

        var payload = { month: month };
        if (elClass.value) payload['class'] = elClass.value;
        if (elSection.value) payload.section = elSection.value;

        postData('attendance/fetch_analytics', payload)
            .then(function(res) {
                elLoading.style.display = 'none';
                elLoadBtn.disabled = false;

                if (!res || res.status === 'error') {
                    showToast(res && res.message ? res.message : 'Failed to load analytics.', 'error');
                    elEmpty.style.display = 'block';
                    return;
                }

                var analytics = res.analytics || [];
                if (analytics.length === 0) {
                    elEmpty.style.display = 'block';
                    return;
                }

                renderSummary(analytics);
                renderBarChart(analytics);
                elCards.style.display = 'grid';
                elBarPanel.style.display = 'block';

                /* Load trend if a class is selected */
                if (elClass.value) {
                    loadTrend();
                } else {
                    elTrendPanel.style.display = 'block';
                    elTrendEmpty.style.display = 'block';
                    elTrendHead.innerHTML = '';
                    elTrendBody.innerHTML = '';
                }
            })
            .catch(function() {
                elLoading.style.display = 'none';
                elLoadBtn.disabled = false;
                showToast('Network error. Please try again.', 'error');
                elEmpty.style.display = 'block';
            });
    }

    function renderSummary(analytics) {
        var totalPct = 0;
        var totalLate = 0;
        var bestPct = -1, bestLabel = '--';
        var worstPct = 101, worstLabel = '--';

        analytics.forEach(function(a) {
            var pct = parseFloat(a.present_pct) || 0;
            totalPct += pct;
            totalLate += parseInt(a.late_count, 10) || 0;
            if (pct > bestPct) { bestPct = pct; bestLabel = a.label || (a['class'] + ' ' + a.section); }
            if (pct < worstPct) { worstPct = pct; worstLabel = a.label || (a['class'] + ' ' + a.section); }
        });

        var avg = analytics.length > 0 ? (totalPct / analytics.length).toFixed(1) : '0';
        elAvgPct.textContent = avg + '%';
        elRegular.textContent = bestLabel;
        elRegular.title = bestPct.toFixed(1) + '%';
        elAbsent.textContent = worstLabel;
        elAbsent.title = worstPct.toFixed(1) + '%';
        elTotalLate.textContent = totalLate;
    }

    function renderBarChart(analytics) {
        var sorted = analytics.slice().sort(function(a, b) {
            return (parseFloat(b.present_pct) || 0) - (parseFloat(a.present_pct) || 0);
        });

        var html = '';
        sorted.forEach(function(a) {
            var pct = parseFloat(a.present_pct) || 0;
            var label = esc(a.label || (a['class'] + ' ' + a.section));
            var color = barColor(pct);
            var widthPct = Math.max(pct, 2);
            html += '<div class="ana-bar-row">'
                + '<div class="ana-bar-label" title="' + label + '">' + label + '</div>'
                + '<div class="ana-bar-track">'
                + '<div class="ana-bar ' + color + '" style="width:' + widthPct + '%">'
                + (pct >= 15 ? pct.toFixed(1) + '%' : '')
                + '</div>'
                + '</div>'
                + '<div class="ana-bar-meta">' + pct.toFixed(1) + '%</div>'
                + '</div>';
        });

        elBarBody.innerHTML = html;
    }

    /* -- Monthly Trend -- */
    function loadTrend() {
        elTrendPanel.style.display = 'block';
        elTrendEmpty.style.display = 'none';
        elTrendHead.innerHTML = '';
        elTrendBody.innerHTML = '';

        var payload = {};
        if (elClass.value) payload['class'] = elClass.value;
        if (elSection.value) payload.section = elSection.value;

        postData('attendance/fetch_monthly_trend', payload)
            .then(function(res) {
                if (!res || res.status === 'error' || !res.trend || res.trend.length === 0) {
                    elTrendEmpty.style.display = 'block';
                    return;
                }

                var trend = res.trend;
                var headHtml = '<tr><th>Month</th><th>Year</th><th>Attendance %</th></tr>';
                elTrendHead.innerHTML = headHtml;

                var bodyHtml = '';
                trend.forEach(function(t) {
                    var pct = parseFloat(t.present_pct) || 0;
                    var color = barColor(pct);
                    bodyHtml += '<tr>'
                        + '<td>' + esc(t.month) + '</td>'
                        + '<td class="mono" style="font-family:var(--ana-font-m);font-size:12px;">' + esc(t.year) + '</td>'
                        + '<td><span class="ana-trend-cell ' + color + '">' + pct.toFixed(1) + '%</span></td>'
                        + '</tr>';
                });
                elTrendBody.innerHTML = bodyHtml;
            })
            .catch(function() {
                elTrendEmpty.style.display = 'block';
            });
    }

    /* -- Individual Report -- */
    elIndBtn.addEventListener('click', loadIndividual);

    function loadIndividual() {
        var pid = elPersonId.value.trim();
        var ptype = elPersonType.value;
        var cls = elIndClass.value;
        var sec = elIndSection.value;

        if (!pid) { showToast('Please enter a person ID.', 'error'); return; }
        if (!cls || !sec) { showToast('Please select class and section.', 'error'); return; }

        elIndResult.style.display = 'none';
        elIndEmpty.style.display = 'none';
        document.getElementById('anaIndPersonInfo').style.display = 'none';
        elIndLoading.style.display = 'block';
        elIndBtn.disabled = true;

        postData('attendance/fetch_individual_report', {
            person_id: pid,
            person_type: ptype,
            'class': cls,
            section: sec
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

                // Show person info banner
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
                    var present = parseInt(st.present, 10) || 0;
                    var absent = parseInt(st.absent, 10) || 0;
                    var late = parseInt(st.late, 10) || 0;
                    var total = parseInt(st.total, 10) || 0;
                    var pct = total > 0 ? ((present / total) * 100).toFixed(1) : '0.0';
                    var color = barColor(parseFloat(pct));

                    bodyHtml += '<tr>'
                        + '<td>' + esc(m.month) + (m.year ? ' ' + esc(m.year) : '') + '</td>'
                        + '<td class="mono">' + present + '</td>'
                        + '<td class="mono">' + absent + '</td>'
                        + '<td class="mono">' + late + '</td>'
                        + '<td class="mono">' + total + '</td>'
                        + '<td><span class="ana-trend-cell ' + color + '">' + pct + '%</span></td>'
                        + '</tr>';
                });
                elIndBody.innerHTML = bodyHtml;

                var tPresent = parseInt(totals.present, 10) || 0;
                var tAbsent = parseInt(totals.absent, 10) || 0;
                var tLate = parseInt(totals.late, 10) || 0;
                var tTotal = parseInt(totals.total, 10) || 0;
                var tPct = tTotal > 0 ? ((tPresent / tTotal) * 100).toFixed(1) : '0.0';
                var tColor = barColor(parseFloat(tPct));

                elIndFoot.innerHTML = '<tr>'
                    + '<td>Total</td>'
                    + '<td class="mono">' + tPresent + '</td>'
                    + '<td class="mono">' + tAbsent + '</td>'
                    + '<td class="mono">' + tLate + '</td>'
                    + '<td class="mono">' + tTotal + '</td>'
                    + '<td><span class="ana-trend-cell ' + tColor + '">' + tPct + '%</span></td>'
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

})();
</script>
