<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div class="fd-wrap">

    <!-- Header -->
    <div class="fd-header">
        <div class="fd-header-icon" style="background:linear-gradient(135deg,#dc2626,#ef4444);"><i class="fa fa-exclamation-triangle"></i></div>
        <div>
            <h2>Defaulter Report</h2>
            <p>Students with unpaid fee demands &mdash; <?= htmlspecialchars($this->session_year ?? '') ?></p>
        </div>
        <div class="fd-header-actions">
            <a href="<?= base_url('fees/dashboard') ?>" class="fd-btn fd-btn-ghost"><i class="fa fa-arrow-left"></i> Dashboard</a>
            <button class="fd-btn fd-btn-ghost fd-btn-sm" onclick="FDR.refresh()"><i class="fa fa-refresh"></i></button>
        </div>
    </div>

    <!-- Summary Stats -->
    <div class="fd-stats" id="drStats">
        <div class="fd-stat fd-stat-red">
            <div class="fd-stat-icon"><i class="fa fa-users"></i></div>
            <div class="fd-stat-body">
                <div class="fd-stat-num" id="statDefaulters"><i class="fa fa-spinner fa-spin"></i></div>
                <div class="fd-stat-lbl">Total Defaulters</div>
            </div>
        </div>
        <div class="fd-stat fd-stat-amber">
            <div class="fd-stat-icon"><i class="fa fa-inr"></i></div>
            <div class="fd-stat-body">
                <div class="fd-stat-num" id="statTotalDue"><i class="fa fa-spinner fa-spin"></i></div>
                <div class="fd-stat-lbl">Total Outstanding</div>
            </div>
        </div>
        <div class="fd-stat fd-stat-teal">
            <div class="fd-stat-icon"><i class="fa fa-bar-chart"></i></div>
            <div class="fd-stat-body">
                <div class="fd-stat-num" id="statCollRate"><i class="fa fa-spinner fa-spin"></i></div>
                <div class="fd-stat-lbl">Collection Rate</div>
            </div>
        </div>
        <div class="fd-stat fd-stat-blue">
            <div class="fd-stat-icon"><i class="fa fa-check-circle"></i></div>
            <div class="fd-stat-body">
                <div class="fd-stat-num" id="statDemandStatus"><i class="fa fa-spinner fa-spin"></i></div>
                <div class="fd-stat-lbl">Paid / Total Demands</div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="fd-grid-2" style="margin-bottom:18px;">
        <div class="fd-card">
            <div class="fd-card-title"><i class="fa fa-graduation-cap"></i> Class-wise Collection</div>
            <div class="fd-chart-wrap"><canvas id="chartClasswise" height="260"></canvas></div>
        </div>
        <div class="fd-card">
            <div class="fd-card-title"><i class="fa fa-calendar"></i> Monthly Demand vs Collection</div>
            <div class="fd-chart-wrap"><canvas id="chartMonthly" height="260"></canvas></div>
        </div>
    </div>

    <!-- Defaulter Table -->
    <div class="fd-card">
        <div class="fd-card-title">
            <i class="fa fa-list"></i> Defaulter List
            <span style="flex:1"></span>
            <select id="filterClass" class="fd-filter" onchange="FDR.applyFilters()"><option value="">All Classes</option></select>
            <select id="filterOverdue" class="fd-filter" onchange="FDR.applyFilters()">
                <option value="0">All</option>
                <option value="7">7+ days overdue</option>
                <option value="30">30+ days overdue</option>
                <option value="60">60+ days overdue</option>
                <option value="90">90+ days overdue</option>
            </select>
        </div>
        <div class="fd-table-wrap" style="max-height:600px;">
            <table class="fd-table" id="tblDefaulters">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student</th>
                        <th>Class</th>
                        <th class="fd-num">Total Due</th>
                        <th class="fd-num">Paid</th>
                        <th class="fd-num">Balance</th>
                        <th>Unpaid Months</th>
                        <th>Oldest Unpaid</th>
                        <th>Days Overdue</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="drBody">
                    <tr><td colspan="10" class="fd-empty"><i class="fa fa-spinner fa-spin"></i> Loading defaulter data...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
    var BASE = '<?= base_url() ?>';
    var defaulterCache = [];
    var analyticsCache = null;
    var chartClass = null, chartMonth = null;

    function fmt(n){ return Number(n||0).toLocaleString('en-IN',{minimumFractionDigits:0,maximumFractionDigits:0}); }
    function esc(s){ var d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }

    function loadData(){
        // Load defaulters and analytics in parallel
        $.when(
            $.getJSON(BASE+'fees/get_defaulter_data'),
            $.getJSON(BASE+'fees/get_collection_analytics')
        ).then(function(drRes, anRes){
            var dr = drRes[0], an = anRes[0];
            defaulterCache = (dr&&dr.defaulters)||[];
            analyticsCache = an||{};

            // Stats
            $('#statDefaulters').text(dr.total_defaulters||0);
            $('#statTotalDue').html('₹'+fmt(dr.total_balance));

            var rate = an.collection_rate||0;
            $('#statCollRate').html(rate+'%');

            var st = an.by_status||{};
            $('#statDemandStatus').html((st.paid||0)+' / '+((st.paid||0)+(st.partial||0)+(st.unpaid||0)));

            // Populate class filter
            var classes = {};
            defaulterCache.forEach(function(d){ if(d.class) classes[d.class]=1; });
            var $cf = $('#filterClass');
            $cf.find('option:not(:first)').remove();
            Object.keys(classes).sort(function(a,b){return a.localeCompare(b,undefined,{numeric:true});}).forEach(function(c){
                $cf.append('<option value="'+esc(c)+'">'+esc(c)+'</option>');
            });

            renderDefaulterTable();
            renderClassChart(an.by_class||[]);
            renderMonthChart(an.by_month||[]);
        });
    }

    function renderDefaulterTable(){
        var $tb=$('#drBody');
        var fc=$('#filterClass').val(), fo=parseInt($('#filterOverdue').val())||0;
        var filtered = defaulterCache.filter(function(d){
            if(fc && d.class!==fc) return false;
            if(fo>0 && d.days_overdue<fo) return false;
            return true;
        });

        if(!filtered.length){ $tb.html('<tr><td colspan="10" class="fd-empty"><i class="fa fa-check-circle" style="color:#16a34a;"></i> No defaulters'+(fc||fo>0?' matching filters':'')+'</td></tr>'); return; }

        var h='';
        filtered.forEach(function(d,i){
            var urgency = d.days_overdue>=90?'fd-badge-red':d.days_overdue>=30?'fd-badge-amber':'fd-badge-green';
            h+='<tr>';
            h+='<td style="color:var(--t3);">'+(i+1)+'</td>';
            h+='<td><strong>'+esc(d.student_name)+'</strong><div style="font-size:11px;color:var(--t3);">'+esc(d.student_id)+'</div></td>';
            h+='<td>'+esc((d.class||'').replace('Class ',''))+' '+esc((d.section||'').replace('Section ',''))+'</td>';
            h+='<td class="fd-num">₹'+fmt(d.total_due)+'</td>';
            h+='<td class="fd-num">₹'+fmt(d.total_paid)+'</td>';
            h+='<td class="fd-num" style="font-weight:700;color:#dc2626;">₹'+fmt(d.balance)+'</td>';
            h+='<td style="text-align:center;"><span class="fd-badge fd-badge-red">'+d.unpaid_months+'</span></td>';
            h+='<td style="font-size:12px;">'+esc(d.oldest_unpaid||'—')+'</td>';
            h+='<td><span class="fd-badge '+urgency+'">'+d.days_overdue+' days</span></td>';
            h+='<td><a href="'+BASE+'fees/student_ledger?sid='+encodeURIComponent(d.student_id)+'" class="fd-btn fd-btn-ghost fd-btn-sm" style="padding:4px 10px;font-size:11px;"><i class="fa fa-book"></i> Ledger</a></td>';
            h+='</tr>';
        });
        $tb.html(h);
    }

    function renderClassChart(data){
        if(!data.length) return;
        var ctx = document.getElementById('chartClasswise').getContext('2d');
        if(chartClass) chartClass.destroy();
        chartClass = new Chart(ctx, {
            type:'bar',
            data:{
                labels: data.map(function(d){ return d.class.replace('Class ',''); }),
                datasets:[
                    {label:'Demanded', data:data.map(function(d){return d.demanded;}), backgroundColor:'rgba(15,118,110,.3)', borderColor:'#0f766e', borderWidth:1, borderRadius:4},
                    {label:'Collected', data:data.map(function(d){return d.collected;}), backgroundColor:'rgba(15,118,110,.8)', borderColor:'#0f766e', borderWidth:1, borderRadius:4}
                ]
            },
            options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top',labels:{font:{size:11}}}},scales:{y:{beginAtZero:true,ticks:{callback:function(v){return '₹'+fmt(v);},font:{size:10}},grid:{color:'rgba(0,0,0,.05)'}},x:{ticks:{font:{size:11}},grid:{display:false}}}}
        });
    }

    function renderMonthChart(data){
        if(!data.length) return;
        var months = {'01':'Jan','02':'Feb','03':'Mar','04':'Apr','05':'May','06':'Jun','07':'Jul','08':'Aug','09':'Sep','10':'Oct','11':'Nov','12':'Dec'};
        var ctx = document.getElementById('chartMonthly').getContext('2d');
        if(chartMonth) chartMonth.destroy();
        chartMonth = new Chart(ctx, {
            type:'bar',
            data:{
                labels: data.map(function(d){ var parts=d.period_key.split('-'); return months[parts[1]]||parts[1]; }),
                datasets:[
                    {label:'Demanded', data:data.map(function(d){return d.demanded;}), backgroundColor:'rgba(220,38,38,.2)', borderColor:'#dc2626', borderWidth:1, borderRadius:4},
                    {label:'Collected', data:data.map(function(d){return d.collected;}), backgroundColor:'rgba(22,163,74,.7)', borderColor:'#16a34a', borderWidth:1, borderRadius:4}
                ]
            },
            options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top',labels:{font:{size:11}}}},scales:{y:{beginAtZero:true,ticks:{callback:function(v){return '₹'+fmt(v);},font:{size:10}},grid:{color:'rgba(0,0,0,.05)'}},x:{ticks:{font:{size:11}},grid:{display:false}}}}
        });
    }

    window.FDR = {
        refresh: function(){ loadData(); },
        applyFilters: function(){ renderDefaulterTable(); }
    };

    loadData();
});
</script>

<style>
.fd-filter { height:34px; padding:0 28px 0 10px; border:1.5px solid var(--border,#d1ddd8); border-radius:8px; font-size:12px; color:var(--t1); background:var(--bg2,#fff); cursor:pointer; outline:none; appearance:none; margin-left:8px; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10'%3E%3Cpath fill='%2364748b' d='M5 7L0 2h10z'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 9px center; }
.fd-badge { display:inline-block; padding:2px 10px; border-radius:12px; font-size:11px; font-weight:700; text-transform:capitalize; }
.fd-badge-green { background:rgba(22,163,74,.12); color:#16a34a; }
.fd-badge-amber { background:rgba(217,119,6,.12); color:#d97706; }
.fd-badge-red { background:rgba(220,38,38,.12); color:#dc2626; }
</style>
