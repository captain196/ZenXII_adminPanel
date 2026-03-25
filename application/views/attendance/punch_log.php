<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<style>
:root {
    --pl-primary: var(--gold);
    --pl-primary-dim: var(--gold-dim);
    --pl-bg: var(--bg);
    --pl-bg2: var(--bg2);
    --pl-bg3: var(--bg3);
    --pl-border: var(--border);
    --pl-t1: var(--t1);
    --pl-t2: var(--t2);
    --pl-t3: var(--t3);
    --pl-shadow: var(--sh);
    --pl-ease: var(--ease);
    --pl-r: 10px;
    --pl-font: var(--font-b, 'Plus Jakarta Sans', sans-serif);
    --pl-font-m: var(--font-m, 'JetBrains Mono', monospace);
    --pl-green: #16a34a;
    --pl-red: #dc2626;
    --pl-blue: #2563eb;
    --pl-orange: #d97706;
    --pl-purple: #7c3aed;
    --pl-green-bg: rgba(22,163,74,.12);
    --pl-red-bg: rgba(220,38,38,.12);
    --pl-blue-bg: rgba(37,99,235,.12);
    --pl-orange-bg: rgba(217,119,6,.12);
    --pl-purple-bg: rgba(124,58,237,.12);
}

/* -- Page Header -- */
.pl-page-hdr { margin-bottom: 18px; }
.pl-page-hdr h4 {
    font-family: var(--pl-font); font-weight: 700; color: var(--pl-t1);
    margin: 0 0 4px; font-size: 18px;
}
.pl-page-hdr h4 i { color: var(--pl-primary); margin-right: 6px; }
.pl-page-hdr p {
    font-family: var(--pl-font); font-size: 13px; color: var(--pl-t3); margin: 0;
}

/* -- Filter Bar -- */
.pl-filter-bar {
    display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;
    padding: 18px 20px; border-radius: var(--pl-r);
    background: var(--pl-bg2); border: 1px solid var(--pl-border);
    box-shadow: var(--pl-shadow); margin-bottom: 20px;
}
.pl-fg { display: flex; flex-direction: column; gap: 4px; }
.pl-fg label {
    font-family: var(--pl-font); font-size: 11px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .5px; color: var(--pl-t3);
}
.pl-fg input[type="date"] {
    font-family: var(--pl-font); font-size: 13px; height: 38px;
    border-radius: 6px; padding: 0 12px; border: 1px solid var(--pl-border);
    background: var(--pl-bg); color: var(--pl-t1); transition: var(--pl-ease);
    min-width: 180px;
}
.pl-fg input[type="date"]:focus {
    outline: none; border-color: var(--pl-primary);
    box-shadow: 0 0 0 3px var(--pl-primary-dim);
}
.pl-btn {
    font-family: var(--pl-font); font-size: 13px; font-weight: 600;
    border: none; border-radius: 6px; padding: 0 20px; height: 38px;
    cursor: pointer; transition: var(--pl-ease); display: inline-flex;
    align-items: center; gap: 6px;
}
.pl-btn-primary { background: var(--pl-primary); color: #fff; }
.pl-btn-primary:hover { opacity: .88; }
.pl-btn-primary:disabled { opacity: .45; cursor: not-allowed; }

/* -- Summary Line -- */
.pl-summary {
    font-family: var(--pl-font); font-size: 14px; color: var(--pl-t2);
    margin-bottom: 16px; padding: 10px 16px;
    background: var(--pl-bg2); border: 1px solid var(--pl-border);
    border-radius: var(--pl-r); display: none;
}
.pl-summary strong { color: var(--pl-t1); font-weight: 700; }

/* -- Punch Table Panel -- */
.pl-panel {
    background: var(--pl-bg2); border: 1px solid var(--pl-border);
    border-radius: var(--pl-r); box-shadow: var(--pl-shadow);
    overflow: hidden; margin-bottom: 24px;
}
.pl-panel-hdr {
    padding: 16px 20px; border-bottom: 1px solid var(--pl-border);
    font-family: var(--pl-font); font-size: 14px; font-weight: 700;
    color: var(--pl-t1); display: flex; align-items: center; gap: 8px;
}
.pl-panel-hdr i { color: var(--pl-primary); }

.pl-table-wrap { overflow-x: auto; }
.pl-table {
    width: 100%; border-collapse: collapse; font-family: var(--pl-font);
}
.pl-table thead { position: sticky; top: 0; z-index: 5; }
.pl-table th {
    font-size: 11px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .3px; color: var(--pl-t3); padding: 12px 14px;
    text-align: left; border-bottom: 2px solid var(--pl-border);
    background: var(--pl-bg3); white-space: nowrap;
}
.pl-table td {
    padding: 10px 14px; border-bottom: 1px solid var(--pl-border);
    font-size: 13px; color: var(--pl-t1); vertical-align: middle;
}
.pl-table tbody tr:hover td { background: var(--pl-bg3); }
.pl-table td.mono {
    font-family: var(--pl-font-m); font-size: 12px;
}

/* -- Badges -- */
.pl-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px; border-radius: 6px;
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .3px;
}
.pl-badge-student { background: var(--pl-blue-bg); color: var(--pl-blue); }
.pl-badge-staff { background: var(--pl-purple-bg); color: var(--pl-purple); }
.pl-badge-in { background: var(--pl-green-bg); color: var(--pl-green); }
.pl-badge-out { background: var(--pl-red-bg); color: var(--pl-red); }

/* -- Confidence Bar -- */
.pl-conf-bar {
    width: 60px; height: 6px; border-radius: 3px;
    background: var(--pl-bg3); display: inline-block; vertical-align: middle;
    margin-right: 6px; overflow: hidden;
}
.pl-conf-fill {
    height: 100%; border-radius: 3px; transition: width .3s ease;
}
.pl-conf-fill.high { background: var(--pl-green); }
.pl-conf-fill.mid { background: var(--pl-orange); }
.pl-conf-fill.low { background: var(--pl-red); }

/* -- Empty / Loading -- */
.pl-empty {
    text-align: center; padding: 48px 20px; color: var(--pl-t3);
    font-family: var(--pl-font); font-size: 14px;
}
.pl-empty i { font-size: 36px; display: block; margin-bottom: 12px; opacity: .5; }
.pl-loading {
    text-align: center; padding: 32px 20px; color: var(--pl-t3);
    font-family: var(--pl-font); font-size: 13px; display: none;
}
.pl-loading i { margin-right: 6px; }

/* -- Toast -- */
.pl-toast {
    position: fixed; bottom: 24px; right: 24px; z-index: 9999;
    padding: 12px 22px; border-radius: 8px; font-family: var(--pl-font);
    font-size: 13px; font-weight: 600; color: #fff;
    box-shadow: 0 8px 24px rgba(0,0,0,.25);
    transform: translateY(100px); opacity: 0; transition: all .3s ease;
    pointer-events: none;
}
.pl-toast.show { transform: translateY(0); opacity: 1; }
.pl-toast.success { background: var(--pl-green); }
.pl-toast.error { background: var(--pl-red); }

/* -- Responsive -- */
@media (max-width: 767px) {
    .pl-filter-bar { flex-direction: column; align-items: stretch; }
    .pl-fg input[type="date"] { min-width: 0; width: 100%; }
    .pl-table th, .pl-table td { padding: 8px 10px; font-size: 12px; }
}
@media (max-width: 479px) {
    .pl-table th, .pl-table td { padding: 6px 8px; font-size: 11px; }
}
</style>

<div class="content-wrapper">
<section class="content">
<div class="container-fluid">

    <!-- Page Header -->
    <div class="pl-page-hdr">
        <h4>
            <i class="fa fa-clock-o"></i>
            Device Punch Log
        </h4>
        <p>View raw punch records from attendance devices</p>
    </div>

    <!-- Filter Bar -->
    <div class="pl-filter-bar">
        <div class="pl-fg">
            <label for="plDate">Date</label>
            <input type="date" id="plDate" value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="pl-fg" style="align-self:flex-end;">
            <button type="button" class="pl-btn pl-btn-primary" id="plLoadBtn">
                <i class="fa fa-search"></i> Load
            </button>
        </div>
    </div>

    <!-- Summary Line -->
    <div class="pl-summary" id="plSummary"></div>

    <!-- Loading -->
    <div class="pl-loading" id="plLoading">
        <i class="fa fa-spinner fa-spin"></i> Loading punch log...
    </div>

    <!-- Empty State -->
    <div class="pl-empty" id="plEmpty">
        <i class="fa fa-clock-o"></i>
        <p>Select a date and click Load to view punch records.</p>
    </div>

    <!-- Punch Table -->
    <div class="pl-panel" id="plPanel" style="display:none;">
        <div class="pl-panel-hdr">
            <i class="fa fa-list"></i> Punch Records
        </div>
        <div class="pl-table-wrap">
            <table class="pl-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Person ID</th>
                        <th>Type</th>
                        <th>Device</th>
                        <th>Device Type</th>
                        <th>Direction</th>
                        <th>Time</th>
                        <th>Confidence</th>
                    </tr>
                </thead>
                <tbody id="plBody"></tbody>
            </table>
        </div>
    </div>

    <!-- Toast -->
    <div class="pl-toast" id="plToast"></div>

</div>
</section>
</div>

<script>
(function(){
    "use strict";

    var CSRF_NAME = '<?= $this->security->get_csrf_token_name() ?>';
    var CSRF_HASH = '<?= $this->security->get_csrf_hash() ?>';
    var BASE = '<?= base_url() ?>';

    /* -- Refs -- */
    var elDate    = document.getElementById('plDate');
    var elLoadBtn = document.getElementById('plLoadBtn');
    var elSummary = document.getElementById('plSummary');
    var elLoading = document.getElementById('plLoading');
    var elEmpty   = document.getElementById('plEmpty');
    var elPanel   = document.getElementById('plPanel');
    var elBody    = document.getElementById('plBody');
    var elToast   = document.getElementById('plToast');

    /* -- Helpers -- */
    function esc(s) {
        if (s == null) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(s)));
        return d.innerHTML;
    }

    function showToast(msg, type) {
        elToast.textContent = msg;
        elToast.className = 'pl-toast ' + (type || 'success');
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

    function confClass(val) {
        var v = parseFloat(val) || 0;
        if (v >= 80) return 'high';
        if (v >= 50) return 'mid';
        return 'low';
    }

    function formatTime(t) {
        if (!t) return '--';
        return esc(String(t));
    }

    /* -- Load Punch Log -- */
    elLoadBtn.addEventListener('click', loadPunchLog);

    function loadPunchLog() {
        var dateVal = elDate.value;
        if (!dateVal) { showToast('Please select a date.', 'error'); return; }

        elEmpty.style.display = 'none';
        elPanel.style.display = 'none';
        elSummary.style.display = 'none';
        elLoading.style.display = 'block';
        elLoadBtn.disabled = true;

        postData('attendance/fetch_punch_log', { date: dateVal })
            .then(function(res) {
                elLoading.style.display = 'none';
                elLoadBtn.disabled = false;

                if (!res || res.status === 'error') {
                    showToast(res && res.message ? res.message : 'Failed to load punch log.', 'error');
                    elEmpty.style.display = 'block';
                    return;
                }

                var punches = res.punches || [];
                var displayDate = res.date || dateVal;

                if (punches.length === 0) {
                    elSummary.innerHTML = '<strong>0</strong> punches on <strong>' + esc(displayDate) + '</strong>';
                    elSummary.style.display = 'block';
                    elEmpty.style.display = 'block';
                    elEmpty.querySelector('p').textContent = 'No punch records found for this date.';
                    return;
                }

                elSummary.innerHTML = '<strong>' + punches.length + '</strong> punch'
                    + (punches.length !== 1 ? 'es' : '')
                    + ' on <strong>' + esc(displayDate) + '</strong>';
                elSummary.style.display = 'block';

                renderTable(punches);
                elPanel.style.display = 'block';
            })
            .catch(function() {
                elLoading.style.display = 'none';
                elLoadBtn.disabled = false;
                showToast('Network error. Please try again.', 'error');
                elEmpty.style.display = 'block';
            });
    }

    function renderTable(punches) {
        var html = '';
        for (var i = 0; i < punches.length; i++) {
            var p = punches[i];
            var typeClass = (p.type === 'staff') ? 'pl-badge-staff' : 'pl-badge-student';
            var typeLabel = (p.type === 'staff') ? 'Staff' : 'Student';
            var dirClass = (p.direction === 'out') ? 'pl-badge-out' : 'pl-badge-in';
            var dirLabel = (p.direction === 'out') ? 'Out' : 'In';
            var conf = parseFloat(p.confidence) || 0;
            var confCls = confClass(conf);

            html += '<tr>'
                + '<td class="mono">' + (i + 1) + '</td>'
                + '<td class="mono">' + esc(p.person_id) + '</td>'
                + '<td><span class="pl-badge ' + typeClass + '">' + typeLabel + '</span></td>'
                + '<td>' + esc(p.device || '--') + '</td>'
                + '<td>' + esc(p.device_type || '--') + '</td>'
                + '<td><span class="pl-badge ' + dirClass + '">' + dirLabel + '</span></td>'
                + '<td class="mono">' + formatTime(p.time) + '</td>'
                + '<td>'
                + '<span class="pl-conf-bar"><span class="pl-conf-fill ' + confCls + '" style="width:' + conf + '%"></span></span>'
                + '<span style="font-family:var(--pl-font-m);font-size:11px;color:var(--pl-t2);">' + conf.toFixed(0) + '%</span>'
                + '</td>'
                + '</tr>';
        }
        elBody.innerHTML = html;
    }

})();
</script>
