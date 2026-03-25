<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div class="fd-wrap">

    <!-- Page Header -->
    <div class="fd-header">
        <div class="fd-header-icon"><i class="fa fa-inr"></i></div>
        <div>
            <h2>Fees Dashboard</h2>
            <p>Financial overview for <?= htmlspecialchars($this->session_year ?? '') ?></p>
        </div>
        <div class="fd-header-actions">
            <a href="<?= base_url('fees/fees_counter') ?>" class="fd-btn fd-btn-primary"><i class="fa fa-desktop"></i> Fee Counter</a>
            <a href="<?= base_url('fees/fees_records') ?>" class="fd-btn fd-btn-ghost"><i class="fa fa-list"></i> Records</a>
            <button class="fd-btn fd-btn-ghost fd-btn-sm" onclick="FD.refresh()"><i class="fa fa-refresh"></i></button>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="fd-stats">
        <div class="fd-stat fd-stat-teal">
            <div class="fd-stat-icon"><i class="fa fa-inr"></i></div>
            <div class="fd-stat-body">
                <div class="fd-stat-num" id="statCollected"><i class="fa fa-spinner fa-spin"></i></div>
                <div class="fd-stat-lbl">Total Collected</div>
            </div>
        </div>
        <div class="fd-stat fd-stat-red">
            <div class="fd-stat-icon"><i class="fa fa-exclamation-circle"></i></div>
            <div class="fd-stat-body">
                <div class="fd-stat-num" id="statDue"><i class="fa fa-spinner fa-spin"></i></div>
                <div class="fd-stat-lbl">Total Due</div>
            </div>
        </div>
        <div class="fd-stat fd-stat-blue">
            <div class="fd-stat-icon"><i class="fa fa-calendar-check-o"></i></div>
            <div class="fd-stat-body">
                <div class="fd-stat-num" id="statToday"><i class="fa fa-spinner fa-spin"></i></div>
                <div class="fd-stat-lbl">Today's Collection</div>
            </div>
        </div>
        <div class="fd-stat fd-stat-amber">
            <div class="fd-stat-icon"><i class="fa fa-pie-chart"></i></div>
            <div class="fd-stat-body">
                <div class="fd-stat-num" id="statRate"><i class="fa fa-spinner fa-spin"></i></div>
                <div class="fd-stat-lbl">Collection Rate</div>
            </div>
        </div>
    </div>

    <!-- Secondary stats row -->
    <div class="fd-stats fd-stats-sm">
        <div class="fd-stat-mini">
            <i class="fa fa-calendar"></i>
            <span id="statMonthName">—</span> Collection:
            <strong id="statMonthAmt">—</strong>
        </div>
        <div class="fd-stat-mini">
            <i class="fa fa-users"></i>
            Total Students: <strong id="statTotalStudents">—</strong>
        </div>
        <div class="fd-stat-mini">
            <i class="fa fa-check-circle" style="color:#16a34a"></i>
            Paid: <strong id="statPaid">—</strong>
        </div>
        <div class="fd-stat-mini">
            <i class="fa fa-warning" style="color:#dc2626"></i>
            Defaulters: <strong id="statDefaulters">—</strong>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="fd-grid-2">
        <!-- Monthly Collection Bar Chart -->
        <div class="fd-card">
            <div class="fd-card-title"><i class="fa fa-bar-chart"></i> Monthly Collection Trend</div>
            <div class="fd-chart-wrap">
                <canvas id="chartMonthly" height="260"></canvas>
            </div>
        </div>
        <!-- Payment Mode Pie -->
        <div class="fd-card">
            <div class="fd-card-title"><i class="fa fa-pie-chart"></i> Payment Modes</div>
            <div class="fd-chart-wrap" style="display:flex;align-items:center;justify-content:center;">
                <canvas id="chartModes" height="240" style="max-width:300px;"></canvas>
            </div>
            <div id="modesList" class="fd-modes-list"></div>
        </div>
    </div>

    <!-- Class-wise Collection + Recent Transactions -->
    <div class="fd-grid-2">
        <!-- Class-wise -->
        <div class="fd-card">
            <div class="fd-card-title"><i class="fa fa-graduation-cap"></i> Class-wise Collection</div>
            <div class="fd-table-wrap">
                <table class="fd-table" id="tblClass">
                    <thead><tr><th>Class</th><th>Students</th><th>Collected</th><th>Due</th><th>Paid %</th></tr></thead>
                    <tbody><tr><td colspan="5" class="fd-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr></tbody>
                </table>
            </div>
        </div>
        <!-- Recent Transactions -->
        <div class="fd-card">
            <div class="fd-card-title"><i class="fa fa-clock-o"></i> Recent Transactions</div>
            <div class="fd-table-wrap">
                <table class="fd-table" id="tblRecent">
                    <thead><tr><th>Receipt</th><th>Date</th><th>Student</th><th>Amount</th><th>Mode</th></tr></thead>
                    <tbody><tr><td colspan="5" class="fd-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Quick Links -->
    <div class="fd-card">
        <div class="fd-card-title"><i class="fa fa-th-large"></i> Quick Access</div>
        <div class="fd-quick-links">
            <a href="<?= base_url('fees/fees_counter') ?>" class="fd-qlink"><i class="fa fa-desktop"></i><span>Fee Counter</span></a>
            <a href="<?= base_url('fees/fees_structure') ?>" class="fd-qlink"><i class="fa fa-sitemap"></i><span>Structure</span></a>
            <a href="<?= base_url('fees/fees_chart') ?>" class="fd-qlink"><i class="fa fa-table"></i><span>Fee Chart</span></a>
            <a href="<?= base_url('fees/fees_records') ?>" class="fd-qlink"><i class="fa fa-list-alt"></i><span>Records</span></a>
            <a href="<?= base_url('fee_management/categories') ?>" class="fd-qlink"><i class="fa fa-tags"></i><span>Categories</span></a>
            <a href="<?= base_url('fee_management/discounts') ?>" class="fd-qlink"><i class="fa fa-percent"></i><span>Discounts</span></a>
            <a href="<?= base_url('fee_management/scholarships') ?>" class="fd-qlink"><i class="fa fa-trophy"></i><span>Scholarships</span></a>
            <a href="<?= base_url('fee_management/refunds') ?>" class="fd-qlink"><i class="fa fa-undo"></i><span>Refunds</span></a>
            <a href="<?= base_url('fee_management/reminders') ?>" class="fd-qlink"><i class="fa fa-bell"></i><span>Reminders</span></a>
            <a href="<?= base_url('fee_management/online_payments') ?>" class="fd-qlink"><i class="fa fa-credit-card"></i><span>Online Pay</span></a>
        </div>
    </div>

</div>
</div>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function(){
    var BASE = '<?= base_url() ?>';
    var chartMonthly = null;
    var chartModes = null;

    function fmt(n) {
        return Number(n || 0).toLocaleString('en-IN', {minimumFractionDigits: 0, maximumFractionDigits: 0});
    }
    function fmtCurrency(n) {
        return '₹' + fmt(n);
    }

    function loadDashboard() {
        $.getJSON(BASE + 'fees/get_dashboard_data', function(r) {
            if (!r || r.status !== 'success') {
                $('#statCollected').text('Error');
                return;
            }

            // Stat cards
            $('#statCollected').html(fmtCurrency(r.total_collected));
            $('#statDue').html(fmtCurrency(r.total_due));
            $('#statToday').html(fmtCurrency(r.today_collection) + '<small style="font-size:12px;font-weight:400;margin-left:6px;">(' + r.today_transactions + ' txns)</small>');
            $('#statRate').html(r.collection_rate + '%');

            // Secondary stats
            $('#statMonthName').text(r.month_name);
            $('#statMonthAmt').text(fmtCurrency(r.month_collection));
            $('#statTotalStudents').text(r.total_students);
            $('#statPaid').text(r.paid_students);
            $('#statDefaulters').text(r.defaulters);

            // Monthly bar chart
            renderMonthlyChart(r.monthly_breakdown);

            // Payment modes pie
            renderModesChart(r.payment_modes);

            // Class-wise table
            renderClassTable(r.class_collection);

            // Recent transactions
            renderRecentTable(r.recent_transactions);
        });
    }

    function renderMonthlyChart(data) {
        var months = ['April','May','June','July','August','September','October','November','December','January','February','March'];
        var labels = months.map(function(m){ return m.substring(0,3); });
        var values = months.map(function(m){ return data[m] || 0; });

        var ctx = document.getElementById('chartMonthly').getContext('2d');
        if (chartMonthly) chartMonthly.destroy();
        chartMonthly = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Collection',
                    data: values,
                    backgroundColor: 'rgba(15,118,110,.7)',
                    borderColor: '#0f766e',
                    borderWidth: 1,
                    borderRadius: 6,
                    maxBarThickness: 40,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) { return '₹' + fmt(ctx.raw); }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(v) { return '₹' + fmt(v); },
                            font: { size: 11 }
                        },
                        grid: { color: 'rgba(0,0,0,.05)' }
                    },
                    x: {
                        ticks: { font: { size: 11 } },
                        grid: { display: false }
                    }
                }
            }
        });
    }

    function renderModesChart(data) {
        var labels = Object.keys(data);
        var values = labels.map(function(k){ return data[k]; });
        var colors = ['#0f766e','#2563eb','#d97706','#dc2626','#7c3aed','#059669','#db2777'];

        var ctx = document.getElementById('chartModes').getContext('2d');
        if (chartModes) chartModes.destroy();

        if (!labels.length) {
            $('#modesList').html('<div class="fd-empty" style="padding:12px;">No payment data yet</div>');
            return;
        }

        chartModes = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors.slice(0, labels.length),
                    borderWidth: 2,
                    borderColor: '#fff',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) { return ctx.label + ': ₹' + fmt(ctx.raw); }
                        }
                    }
                }
            }
        });

        // Legend list
        var total = values.reduce(function(a,b){ return a+b; }, 0);
        var h = '';
        labels.forEach(function(l, i){
            var pct = total > 0 ? ((values[i]/total)*100).toFixed(1) : 0;
            h += '<div class="fd-mode-item"><span class="fd-mode-dot" style="background:'+colors[i%colors.length]+'"></span><span class="fd-mode-label">'+l+'</span><span class="fd-mode-val">₹'+fmt(values[i])+' ('+pct+'%)</span></div>';
        });
        $('#modesList').html(h);
    }

    function renderClassTable(data) {
        var $tb = $('#tblClass tbody');
        if (!data || !data.length) {
            $tb.html('<tr><td colspan="5" class="fd-empty"><i class="fa fa-inbox"></i> No class data</td></tr>');
            return;
        }
        var h = '';
        data.forEach(function(c){
            var barColor = c.paid_pct >= 80 ? '#16a34a' : c.paid_pct >= 50 ? '#d97706' : '#dc2626';
            h += '<tr>';
            h += '<td><strong>' + c.class.replace('Class ','') + '</strong></td>';
            h += '<td>' + c.students + '</td>';
            h += '<td class="fd-num">₹' + fmt(c.collected) + '</td>';
            h += '<td class="fd-num" style="color:#dc2626;">₹' + fmt(c.due) + '</td>';
            h += '<td><div class="fd-bar-wrap"><div class="fd-bar" style="width:'+c.paid_pct+'%;background:'+barColor+'"></div></div><span class="fd-bar-label">'+c.paid_pct+'%</span></td>';
            h += '</tr>';
        });
        $tb.html(h);
    }

    function renderRecentTable(data) {
        var $tb = $('#tblRecent tbody');
        if (!data || !data.length) {
            $tb.html('<tr><td colspan="5" class="fd-empty"><i class="fa fa-inbox"></i> No transactions yet</td></tr>');
            return;
        }
        var h = '';
        data.forEach(function(t){
            h += '<tr>';
            h += '<td><code style="font-size:12px;">' + (t.receipt || '—') + '</code></td>';
            h += '<td>' + (t.date || '—') + '</td>';
            h += '<td>' + (t.student || '—') + '</td>';
            h += '<td class="fd-num">₹' + fmt(t.amount) + '</td>';
            h += '<td><span class="fd-mode-tag">' + (t.mode || 'Cash') + '</span></td>';
            h += '</tr>';
        });
        $tb.html(h);
    }

    window.FD = {
        refresh: function() {
            $('#statCollected,#statDue,#statToday,#statRate').html('<i class="fa fa-spinner fa-spin"></i>');
            loadDashboard();
        }
    };

    loadDashboard();
});
</script>

<style>
/* ── Fees Dashboard Styles ─────────────────────────────────────── */
.fd-wrap {
    font-family: var(--font-b, 'Plus Jakarta Sans'), -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    max-width: 1400px; margin: 0 auto; padding: 24px 20px;
    color: var(--t1, #1a2e2a); line-height: 1.5; font-size: 14px;
}
.fd-wrap *, .fd-wrap *::before, .fd-wrap *::after { box-sizing: border-box; }

/* Header */
.fd-header {
    display: flex; align-items: center; gap: 16px;
    margin-bottom: 24px; padding-bottom: 18px;
    border-bottom: 1px solid var(--border, #d1ddd8);
    flex-wrap: wrap;
}
.fd-header-icon {
    width: 48px; height: 48px; border-radius: 12px;
    background: linear-gradient(135deg, var(--gold, #0f766e), #14b8a6);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 22px; flex-shrink: 0;
}
.fd-header h2 { font-size: 24px; font-weight: 800; margin: 0; color: var(--t1, #1a2e2a); }
.fd-header p { font-size: 14px; color: var(--t3, #7a9a8e); margin: 2px 0 0; }
.fd-header-actions { margin-left: auto; display: flex; gap: 8px; flex-wrap: wrap; }

/* Buttons */
.fd-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 18px; font-size: 13px; font-weight: 600;
    border-radius: 8px; border: none; cursor: pointer;
    text-decoration: none; transition: all .2s; font-family: inherit;
}
.fd-btn-primary { background: var(--gold, #0f766e); color: #fff; }
.fd-btn-primary:hover { background: var(--gold2, #0d6b63); color: #fff; text-decoration: none; }
.fd-btn-ghost { background: transparent; color: var(--t2, #4a6a60); border: 1px solid var(--border, #d1ddd8); }
.fd-btn-ghost:hover { background: var(--bg3, #e6f4f1); color: var(--t1); text-decoration: none; }
.fd-btn-sm { padding: 7px 12px; font-size: 12px; }

/* Stat Cards */
.fd-stats {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 16px; margin-bottom: 20px;
}
@media (max-width: 900px) { .fd-stats { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 500px) { .fd-stats { grid-template-columns: 1fr; } }

.fd-stat {
    background: var(--bg2, #fff); border: 1px solid var(--border, #d1ddd8);
    border-radius: 12px; padding: 20px; display: flex; align-items: center; gap: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,.04);
    transition: transform .15s, box-shadow .15s;
}
.fd-stat:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,.08); }
.fd-stat-icon {
    width: 48px; height: 48px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.fd-stat-teal .fd-stat-icon { background: rgba(15,118,110,.12); color: #0f766e; }
.fd-stat-red .fd-stat-icon { background: rgba(220,38,38,.12); color: #dc2626; }
.fd-stat-blue .fd-stat-icon { background: rgba(37,99,235,.12); color: #2563eb; }
.fd-stat-amber .fd-stat-icon { background: rgba(217,119,6,.12); color: #d97706; }
.fd-stat-num { font-size: 24px; font-weight: 800; color: var(--t1, #1a2e2a); line-height: 1.2; }
.fd-stat-lbl { font-size: 12px; color: var(--t3, #7a9a8e); font-weight: 600; text-transform: uppercase; letter-spacing: .5px; margin-top: 2px; }

/* Secondary mini stats */
.fd-stats-sm {
    display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px;
    grid-template-columns: none;
}
.fd-stat-mini {
    background: var(--bg2, #fff); border: 1px solid var(--border, #d1ddd8);
    border-radius: 8px; padding: 10px 16px; font-size: 13px;
    display: flex; align-items: center; gap: 8px; color: var(--t2, #4a6a60);
}
.fd-stat-mini strong { color: var(--t1, #1a2e2a); }
.fd-stat-mini i { font-size: 14px; }

/* Grid */
.fd-grid-2 {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 18px; margin-bottom: 18px;
}
@media (max-width: 900px) { .fd-grid-2 { grid-template-columns: 1fr; } }

/* Card */
.fd-card {
    background: var(--bg2, #fff); border: 1px solid var(--border, #d1ddd8);
    border-radius: 12px; padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,.04);
}
.fd-card-title {
    font-size: 15px; font-weight: 700; color: var(--t1, #1a2e2a);
    margin-bottom: 16px; display: flex; align-items: center; gap: 8px;
}
.fd-card-title i { color: var(--gold, #0f766e); }

/* Chart */
.fd-chart-wrap { position: relative; height: 260px; }

/* Table */
.fd-table-wrap { overflow-x: auto; max-height: 400px; overflow-y: auto; }
.fd-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.fd-table thead th {
    padding: 10px 12px; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .5px;
    color: var(--t3, #7a9a8e); border-bottom: 2px solid var(--border, #d1ddd8);
    white-space: nowrap; text-align: left;
}
.fd-table tbody td {
    padding: 10px 12px; border-bottom: 1px solid var(--border, #d1ddd8);
    vertical-align: middle;
}
.fd-table tbody tr:last-child td { border-bottom: none; }
.fd-table tbody tr:hover { background: rgba(15,118,110,.03); }
.fd-num { text-align: right; font-weight: 600; font-variant-numeric: tabular-nums; }
.fd-empty { text-align: center; color: var(--t3, #7a9a8e); padding: 24px !important; }

/* Progress bar */
.fd-bar-wrap {
    width: 80px; height: 8px; background: var(--bg3, #e6f4f1);
    border-radius: 4px; overflow: hidden; display: inline-block; vertical-align: middle;
}
.fd-bar { height: 100%; border-radius: 4px; transition: width .4s ease; }
.fd-bar-label { font-size: 11px; font-weight: 700; margin-left: 6px; color: var(--t2); }

/* Mode items */
.fd-modes-list { margin-top: 12px; }
.fd-mode-item {
    display: flex; align-items: center; gap: 8px;
    padding: 6px 0; font-size: 13px;
    border-bottom: 1px solid var(--border, #d1ddd8);
}
.fd-mode-item:last-child { border-bottom: none; }
.fd-mode-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.fd-mode-label { flex: 1; color: var(--t2); }
.fd-mode-val { font-weight: 600; color: var(--t1); font-variant-numeric: tabular-nums; }
.fd-mode-tag {
    display: inline-block; padding: 2px 8px; border-radius: 4px;
    font-size: 11px; font-weight: 600; background: var(--bg3, #e6f4f1); color: var(--t2);
}

/* Quick Links */
.fd-quick-links {
    display: grid; grid-template-columns: repeat(5, 1fr);
    gap: 12px;
}
@media (max-width: 900px) { .fd-quick-links { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 500px) { .fd-quick-links { grid-template-columns: repeat(2, 1fr); } }
.fd-qlink {
    display: flex; flex-direction: column; align-items: center; gap: 8px;
    padding: 16px 10px; border-radius: 10px;
    background: var(--bg3, #e6f4f1); color: var(--t2, #4a6a60);
    text-decoration: none; transition: all .2s; font-size: 12px; font-weight: 600;
    text-align: center;
}
.fd-qlink i { font-size: 20px; color: var(--gold, #0f766e); }
.fd-qlink:hover {
    background: var(--gold, #0f766e); color: #fff; text-decoration: none;
    transform: translateY(-2px); box-shadow: 0 4px 12px rgba(15,118,110,.25);
}
.fd-qlink:hover i { color: #fff; }

/* Night mode adjustments */
[data-theme="dark"] .fd-stat,
[data-theme="dark"] .fd-card,
[data-theme="dark"] .fd-stat-mini {
    background: var(--bg2, #0c1e38);
    border-color: var(--border, #1a3555);
}
</style>
