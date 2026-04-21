<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div class="fm-wrap fm-page">

    <!-- ── Top Bar ── -->
    <div class="fm-topbar">
        <div class="fm-topbar-left">
            <h1 class="fm-page-title">Defaulter Report</h1>
            <nav class="fm-breadcrumb">
                <a href="<?= base_url('dashboard') ?>">Dashboard</a>
                <span class="fm-bc-sep">/</span>
                <a href="<?= base_url('fees/fees_dashboard') ?>">Fees</a>
                <span class="fm-bc-sep">/</span>
                <span>Defaulters &mdash; <?= htmlspecialchars($this->session_year ?? '') ?></span>
            </nav>
        </div>
        <div class="fm-topbar-right">
            <button class="fm-btn fm-btn-ghost" onclick="FDR.refresh()" title="Refresh">
                <i class="fa fa-refresh"></i>
            </button>
            <a href="<?= base_url('fees/fees_dashboard') ?>" class="fm-btn fm-btn-ghost">
                <i class="fa fa-arrow-left"></i> Dashboard
            </a>
        </div>
    </div>

    <!-- ── KPI Stats ── -->
    <div class="fm-stats-row">
        <div class="fm-stat-card">
            <div class="fm-stat-icon fm-stat-icon--red"><i class="fa fa-users"></i></div>
            <div class="fm-stat-body">
                <span class="fm-stat-val" id="statDefaulters">—</span>
                <span class="fm-stat-label">Total Defaulters</span>
            </div>
        </div>
        <div class="fm-stat-card">
            <div class="fm-stat-icon fm-stat-icon--amber"><i class="fa fa-inr"></i></div>
            <div class="fm-stat-body">
                <span class="fm-stat-val" id="statTotalDue">—</span>
                <span class="fm-stat-label">Total Outstanding</span>
            </div>
        </div>
        <div class="fm-stat-card">
            <div class="fm-stat-icon fm-stat-icon--teal"><i class="fa fa-line-chart"></i></div>
            <div class="fm-stat-body">
                <span class="fm-stat-val" id="statCollRate">—</span>
                <span class="fm-stat-label">Collection Rate</span>
            </div>
        </div>
        <div class="fm-stat-card">
            <div class="fm-stat-icon fm-stat-icon--navy"><i class="fa fa-check-circle"></i></div>
            <div class="fm-stat-body">
                <span class="fm-stat-val" id="statDemandStatus">—</span>
                <span class="fm-stat-label">Paid / Total Demands</span>
            </div>
        </div>
    </div>

    <!-- ── Aging Buckets ── -->
    <div class="fm-card">
        <div class="fm-card-hdr">
            <h2 class="fm-card-title"><i class="fa fa-hourglass-half"></i> Ageing Buckets</h2>
            <span class="fm-card-hint">Days since earliest unpaid demand</span>
        </div>
        <div class="fm-aging-grid" id="drAging">
            <div class="fm-aging-cell fm-aging-cell--green">
                <div class="fm-aging-label">0–30 days</div>
                <div class="fm-aging-val" id="ageCount1">—</div>
                <div class="fm-aging-sub" id="ageAmt1">&#8377;0</div>
            </div>
            <div class="fm-aging-cell fm-aging-cell--amber">
                <div class="fm-aging-label">31–60 days</div>
                <div class="fm-aging-val" id="ageCount2">—</div>
                <div class="fm-aging-sub" id="ageAmt2">&#8377;0</div>
            </div>
            <div class="fm-aging-cell fm-aging-cell--orange">
                <div class="fm-aging-label">61–90 days</div>
                <div class="fm-aging-val" id="ageCount3">—</div>
                <div class="fm-aging-sub" id="ageAmt3">&#8377;0</div>
            </div>
            <div class="fm-aging-cell fm-aging-cell--red">
                <div class="fm-aging-label">90+ days</div>
                <div class="fm-aging-val" id="ageCount4">—</div>
                <div class="fm-aging-sub" id="ageAmt4">&#8377;0</div>
            </div>
        </div>
    </div>

    <!-- ── Dues-Based Blocking Policy ── -->
    <div class="fm-card">
        <div class="fm-card-hdr">
            <h2 class="fm-card-title"><i class="fa fa-lock"></i> Dues-Based Blocking</h2>
            <span class="fm-card-hint">Withhold result / TC / hall-ticket / library for students with unpaid fees</span>
        </div>
        <div class="fm-policy-grid">
            <label class="fm-policy-toggle">
                <input type="checkbox" id="polResult" onchange="FDR.policyChanged()">
                <div>
                    <div class="fm-policy-title"><i class="fa fa-file-text-o"></i> Block Report Cards</div>
                    <div class="fm-policy-sub">Students with dues cannot view or print results</div>
                </div>
            </label>
            <label class="fm-policy-toggle">
                <input type="checkbox" id="polTc" onchange="FDR.policyChanged()">
                <div>
                    <div class="fm-policy-title"><i class="fa fa-id-card-o"></i> Block Transfer Certificate</div>
                    <div class="fm-policy-sub">TC cannot be issued or reprinted with outstanding dues</div>
                </div>
            </label>
            <label class="fm-policy-toggle">
                <input type="checkbox" id="polHall" onchange="FDR.policyChanged()">
                <div>
                    <div class="fm-policy-title"><i class="fa fa-ticket"></i> Block Hall Ticket</div>
                    <div class="fm-policy-sub">Exam admit cards withheld until fees cleared</div>
                </div>
            </label>
            <label class="fm-policy-toggle">
                <input type="checkbox" id="polLib" onchange="FDR.policyChanged()">
                <div>
                    <div class="fm-policy-title"><i class="fa fa-book"></i> Block Library Checkout</div>
                    <div class="fm-policy-sub">Library issues blocked for defaulters</div>
                </div>
            </label>
        </div>
        <div class="fm-policy-footer">
            <div class="fm-policy-inputs">
                <label>
                    <span>Threshold amount (₹)</span>
                    <input type="number" id="polThreshold" class="fm-input-sm" min="0" step="100" value="0"
                           placeholder="0" onchange="FDR.policyChanged()">
                </label>
                <label class="fm-inline-check">
                    <input type="checkbox" id="polOverride" onchange="FDR.policyChanged()">
                    <span>Allow admin override</span>
                </label>
            </div>
            <button type="button" class="fm-btn fm-btn-primary" id="btnSavePolicy" onclick="FDR.savePolicy()" disabled>
                <i class="fa fa-save"></i> Save Policy
            </button>
        </div>
    </div>

    <!-- ── Charts Row ── -->
    <div class="fm-grid-2">
        <div class="fm-card">
            <div class="fm-card-hdr"><h2 class="fm-card-title"><i class="fa fa-graduation-cap"></i> Class-wise Collection</h2></div>
            <div class="fm-chart-wrap"><canvas id="chartClasswise" height="260"></canvas></div>
        </div>
        <div class="fm-card">
            <div class="fm-card-hdr"><h2 class="fm-card-title"><i class="fa fa-calendar"></i> Monthly Demand vs Collection</h2></div>
            <div class="fm-chart-wrap"><canvas id="chartMonthly" height="260"></canvas></div>
        </div>
    </div>

    <!-- ── Defaulter Table ── -->
    <div class="fm-card">
        <div class="fm-card-hdr fm-card-hdr--wrap">
            <h2 class="fm-card-title"><i class="fa fa-list"></i> Defaulter List <span class="fm-muted" id="listMeta"></span></h2>
            <div class="fm-toolbar">
                <select id="filterClass" class="fm-select" onchange="FDR.applyFilters()"><option value="">All Classes</option></select>
                <select id="filterOverdue" class="fm-select" onchange="FDR.applyFilters()">
                    <option value="0">All</option>
                    <option value="7">7+ days</option>
                    <option value="30">31–60 days</option>
                    <option value="60">61–90 days</option>
                    <option value="90">90+ days</option>
                </select>
                <button type="button" class="fm-btn fm-btn-whatsapp" id="btnBulkReminder" onclick="FDR.sendBulkReminder()">
                    <i class="fa fa-whatsapp"></i> WhatsApp (<span id="selCount">0</span>)
                </button>
                <button type="button" class="fm-btn fm-btn-push" id="btnBulkPush" onclick="FDR.sendBulkPush()" title="Push notification to the parent app (instant, free)">
                    <i class="fa fa-bell"></i> Push (<span id="selCountPush">0</span>)
                </button>
                <button type="button" class="fm-btn fm-btn-ghost" onclick="FDR.exportCsv()" title="Export visible rows as CSV">
                    <i class="fa fa-download"></i> Export
                </button>
            </div>
        </div>
        <div class="fm-table-wrap">
            <table class="fm-table" id="tblDefaulters">
                <thead>
                    <tr>
                        <th style="width:28px;"><input type="checkbox" id="chkAll" class="fm-check-input" onclick="FDR.toggleAll(this.checked)"></th>
                        <th style="width:40px;">#</th>
                        <th>Student</th>
                        <th>Class</th>
                        <th class="fm-num">Total Due</th>
                        <th class="fm-num">Paid</th>
                        <th class="fm-num">Balance</th>
                        <th>Months</th>
                        <th>Oldest</th>
                        <th>Days Overdue</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="drBody">
                    <tr><td colspan="11" class="fm-empty"><i class="fa fa-spinner fa-spin"></i> Loading defaulter data…</td></tr>
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
    var CSRF_NAME = '<?= $this->security->get_csrf_token_name() ?>';
    var CSRF_HASH = '<?= $this->security->get_csrf_hash() ?>';
    var defaulterCache = [];
    var analyticsCache = null;
    var chartClass = null, chartMonth = null;

    function fmt(n){ return Number(n||0).toLocaleString('en-IN',{minimumFractionDigits:0,maximumFractionDigits:0}); }
    function esc(s){ var d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }

    function loadData(){
        $('#statDefaulters,#statTotalDue,#statCollRate,#statDemandStatus').text('…');
        $.when(
            $.getJSON(BASE+'fees/get_defaulter_data'),
            $.getJSON(BASE+'fees/get_collection_analytics')
        ).then(function(drRes, anRes){
            var dr = drRes[0], an = anRes[0];
            defaulterCache = (dr&&dr.defaulters)||[];
            analyticsCache = an||{};

            $('#statDefaulters').text(dr.total_defaulters||0);
            $('#statTotalDue').html('&#8377;'+fmt(dr.total_balance));
            var rate = an.collection_rate||0;
            $('#statCollRate').html(rate+'%');
            var st = an.by_status||{};
            $('#statDemandStatus').html((st.paid||0)+' / '+((st.paid||0)+(st.partial||0)+(st.unpaid||0)));

            // Aging buckets
            var buckets = [0,0,0,0], amounts = [0,0,0,0];
            defaulterCache.forEach(function(d){
                var days = Number(d.days_overdue || 0);
                var bal  = Number(d.balance || 0);
                var idx = days >= 90 ? 3 : days >= 61 ? 2 : days >= 31 ? 1 : 0;
                buckets[idx]++; amounts[idx] += bal;
            });
            for (var i = 0; i < 4; i++) {
                $('#ageCount'+(i+1)).text(buckets[i]);
                $('#ageAmt'+(i+1)).html('&#8377;'+fmt(amounts[i]));
            }

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
        }).fail(function(){
            $('#drBody').html('<tr><td colspan="11" class="fm-empty fm-err"><i class="fa fa-exclamation-triangle"></i> Failed to load defaulter data. <a href="#" onclick="FDR.refresh();return false;">Retry</a>.</td></tr>');
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

        $('#listMeta').text(filtered.length + ' shown');

        if(!filtered.length){
            $tb.html('<tr><td colspan="11" class="fm-empty"><i class="fa fa-check-circle" style="color:#16a34a;"></i> No defaulters'+(fc||fo>0?' matching filters':'')+'.</td></tr>');
            $('#chkAll').prop('checked', false);
            $('#selCount').text('0');
            return;
        }

        var h='';
        filtered.forEach(function(d,i){
            var urgency = d.days_overdue>=90?'fm-badge--red':d.days_overdue>=31?'fm-badge--amber':'fm-badge--green';
            h+='<tr>';
            h+='<td><input type="checkbox" class="fm-check-input fm-row-chk" data-sid="'+esc(d.student_id)+'" onclick="FDR.updateSelection()"></td>';
            h+='<td class="fm-muted">'+(i+1)+'</td>';
            h+='<td><div class="fm-cell-title">'+esc(d.student_name)+'</div><div class="fm-cell-sub">'+esc(d.student_id)+'</div></td>';
            h+='<td>'+esc((d.class||'').replace('Class ',''))+' <span class="fm-muted">'+esc((d.section||'').replace('Section ',''))+'</span></td>';
            h+='<td class="fm-num">&#8377;'+fmt(d.total_due)+'</td>';
            h+='<td class="fm-num">&#8377;'+fmt(d.total_paid)+'</td>';
            h+='<td class="fm-num fm-cell-danger">&#8377;'+fmt(d.balance)+'</td>';
            h+='<td style="text-align:center;"><span class="fm-badge fm-badge--red">'+d.unpaid_months+'</span></td>';
            h+='<td class="fm-muted" style="font-size:12px;">'+esc(d.oldest_unpaid||'—')+'</td>';
            h+='<td><span class="fm-badge '+urgency+'">'+d.days_overdue+'d</span></td>';
            h+='<td><a href="'+BASE+'fees/student_ledger?sid='+encodeURIComponent(d.student_id)+'" class="fm-btn fm-btn-ghost fm-btn-sm" title="Open ledger"><i class="fa fa-book"></i> Ledger</a></td>';
            h+='</tr>';
        });
        $tb.html(h);
        $('#chkAll').prop('checked', false);
        $('#selCount').text('0');
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
                    {label:'Demanded', data:data.map(function(d){return d.demanded;}), backgroundColor:'rgba(15,118,110,.25)', borderColor:'#0f766e', borderWidth:1, borderRadius:4},
                    {label:'Collected', data:data.map(function(d){return d.collected;}), backgroundColor:'rgba(15,118,110,.85)', borderColor:'#0f766e', borderWidth:1, borderRadius:4}
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
                    {label:'Collected', data:data.map(function(d){return d.collected;}), backgroundColor:'rgba(22,163,74,.75)', borderColor:'#16a34a', borderWidth:1, borderRadius:4}
                ]
            },
            options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top',labels:{font:{size:11}}}},scales:{y:{beginAtZero:true,ticks:{callback:function(v){return '₹'+fmt(v);},font:{size:10}},grid:{color:'rgba(0,0,0,.05)'}},x:{ticks:{font:{size:11}},grid:{display:false}}}}
        });
    }

    function sendBulkReminder(){
        var ids = $('.fm-row-chk:checked').map(function(){ return $(this).data('sid'); }).get();
        if (!ids.length) { alert('Select at least one student to send reminders.'); return; }
        var $btn = $('#btnBulkReminder');
        var original = $btn.html();
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Sending…');
        var fd = new FormData();
        fd.append(CSRF_NAME, CSRF_HASH);
        fd.append('channel', 'whatsapp');
        fd.append('template', 'fees_due_default');
        ids.forEach(function(id){ fd.append('student_ids[]', id); });
        fetch(BASE + 'fee_management/send_reminder', {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(function(r){return r.json();}).then(function(res){
                alert((res && res.message) || ('Reminder queued for ' + ids.length + ' students.'));
                $('.fm-row-chk').prop('checked', false);
                $('#chkAll').prop('checked', false);
                updateSelCount();
            }).catch(function(){ alert('Failed to send reminder.'); })
            .finally(function(){ $btn.prop('disabled', false).html(original); });
    }

    // Phase 8: instant in-app push notification (FCM) for a multi-select.
    // Hits fee_management/send_reminder with channel='push' — controller
    // dispatches through Push_service::sendToUser per student and reports
    // how many delivered vs. had no active device token.
    function sendBulkPush(){
        var ids = $('.fm-row-chk:checked').map(function(){ return $(this).data('sid'); }).get();
        if (!ids.length) { alert('Select at least one student to send push notifications.'); return; }
        var $btn = $('#btnBulkPush');
        var original = $btn.html();
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Pushing…');
        var fd = new FormData();
        fd.append(CSRF_NAME, CSRF_HASH);
        fd.append('channel', 'push');
        fd.append('template', 'Fee reminder');
        ids.forEach(function(id){ fd.append('student_ids[]', id); });
        fetch(BASE + 'fee_management/send_reminder', {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(function(r){return r.json();}).then(function(res){
                alert((res && res.message) || ('Push sent to ' + ids.length + ' parents.'));
                $('.fm-row-chk').prop('checked', false);
                $('#chkAll').prop('checked', false);
                updateSelCount();
            }).catch(function(){ alert('Failed to send push.'); })
            .finally(function(){ $btn.prop('disabled', false).html(original); });
    }

    function updateSelCount(){
        var n = $('.fm-row-chk:checked').length;
        $('#selCount').text(n);
        $('#selCountPush').text(n);
    }

    function exportCsv(){
        var fc=$('#filterClass').val(), fo=parseInt($('#filterOverdue').val())||0;
        var filtered = defaulterCache.filter(function(d){
            if(fc && d.class!==fc) return false;
            if(fo>0 && d.days_overdue<fo) return false;
            return true;
        });
        if(!filtered.length){ alert('Nothing to export.'); return; }
        var headers = ['Student ID','Student Name','Class','Section','Total Due','Total Paid','Balance','Unpaid Months','Oldest Unpaid','Days Overdue','Parent Phone'];
        var rows = [headers.join(',')];
        filtered.forEach(function(d){
            rows.push([
                (d.student_id||''),
                '"'+String(d.student_name||'').replace(/"/g,'""')+'"',
                (d.class||''), (d.section||''),
                d.total_due||0, d.total_paid||0, d.balance||0,
                d.unpaid_months||0, (d.oldest_unpaid||''), d.days_overdue||0,
                (d.parent_phone||'')
            ].join(','));
        });
        var blob = new Blob([rows.join('\n')], {type:'text/csv;charset=utf-8;'});
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a'); a.href=url; a.download='defaulters_'+(new Date()).toISOString().slice(0,10)+'.csv';
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    // ── Dues-blocking policy: load, edit, save ──
    function loadPolicy(){
        $.getJSON(BASE + 'fee_management/get_blocking_policy').done(function(res){
            if (!res || !res.policy) return;
            var p = res.policy;
            $('#polResult').prop('checked',  !!p.block_result);
            $('#polTc').prop('checked',      !!p.block_tc);
            $('#polHall').prop('checked',    !!p.block_hall_ticket);
            $('#polLib').prop('checked',     !!p.block_library);
            $('#polOverride').prop('checked',!!p.admin_override_allowed);
            $('#polThreshold').val(p.threshold_amount || 0);
            $('#btnSavePolicy').prop('disabled', true);
        });
    }
    function policyChanged(){
        $('#btnSavePolicy').prop('disabled', false);
    }
    function savePolicy(){
        var fd = new FormData();
        fd.append(CSRF_NAME, CSRF_HASH);
        fd.append('block_result',           $('#polResult').is(':checked')   ? '1' : '0');
        fd.append('block_tc',               $('#polTc').is(':checked')       ? '1' : '0');
        fd.append('block_hall_ticket',      $('#polHall').is(':checked')     ? '1' : '0');
        fd.append('block_library',          $('#polLib').is(':checked')      ? '1' : '0');
        fd.append('threshold_amount',       parseFloat($('#polThreshold').val() || 0));
        fd.append('admin_override_allowed', $('#polOverride').is(':checked') ? '1' : '0');
        var $btn = $('#btnSavePolicy');
        var original = $btn.html();
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving…');
        fetch(BASE + 'fee_management/save_blocking_policy', {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (res && res.status === 'success') {
                    $btn.html('<i class="fa fa-check"></i> Saved');
                    setTimeout(function(){ $btn.html(original).prop('disabled', true); }, 1200);
                } else {
                    alert((res && res.message) || 'Save failed');
                    $btn.html(original).prop('disabled', false);
                }
            })
            .catch(function(){ alert('Save failed'); $btn.html(original).prop('disabled', false); });
    }

    window.FDR = {
        refresh: function(){ loadData(); loadPolicy(); },
        applyFilters: function(){ renderDefaulterTable(); },
        toggleAll: function(checked){
            $('.fm-row-chk').prop('checked', checked);
            FDR.updateSelection();
        },
        updateSelection: function(){
            var n = $('.fm-row-chk:checked').length;
            $('#selCount').text(n);
            $('#selCountPush').text(n);
        },
        sendBulkReminder: sendBulkReminder,
        sendBulkPush: sendBulkPush,
        exportCsv: exportCsv,
        policyChanged: policyChanged,
        savePolicy: savePolicy
    };

    loadData();
    loadPolicy();
});
</script>

<style>
/* Design tokens — matches Discounts/Gateway pages */
.fm-wrap { max-width:1280px; margin:0 auto; padding:20px 24px 40px; color:var(--t1,#0f172a); font-family:'Plus Jakarta Sans',var(--font-b,sans-serif); }

/* Top bar */
.fm-topbar { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; margin-bottom:20px; flex-wrap:wrap; }
.fm-page-title { font-family:'Fraunces',serif; font-size:1.35rem; font-weight:600; color:var(--t1,#0f172a); margin:0; letter-spacing:-.01em; }
.fm-breadcrumb { font-size:.78rem; color:var(--t3,#94a3b8); margin-top:3px; }
.fm-breadcrumb a { color:var(--gold,#0f766e); text-decoration:none; }
.fm-breadcrumb a:hover { text-decoration:underline; }
.fm-bc-sep { margin:0 5px; color:var(--t3,#94a3b8); }
.fm-topbar-right { display:flex; gap:8px; }

/* Stats row */
.fm-stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:18px; }
.fm-stat-card { background:var(--bg2,#fff); border:1px solid var(--border,#e5e7eb); border-radius:10px; padding:16px 18px; display:flex; align-items:center; gap:14px; box-shadow:var(--sh,0 1px 3px rgba(15,31,61,.08)); transition:transform .15s ease, box-shadow .15s ease; }
.fm-stat-card:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(15,31,61,.08); }
.fm-stat-icon { width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
.fm-stat-icon--red   { background:rgba(220,38,38,.10);  color:#dc2626; }
.fm-stat-icon--amber { background:rgba(217,119,6,.10);  color:#d97706; }
.fm-stat-icon--teal  { background:rgba(15,118,110,.10); color:#0f766e; }
.fm-stat-icon--navy  { background:rgba(15,31,61,.08);   color:#0f1f3d; }
.fm-stat-body { display:flex; flex-direction:column; min-width:0; }
.fm-stat-val  { font-size:1.45rem; font-weight:700; line-height:1.15; color:var(--t1,#0f172a); font-family:'Plus Jakarta Sans',sans-serif; }
.fm-stat-label{ font-size:12.5px; color:var(--t3,#64748b); font-weight:500; margin-top:2px; }

/* Cards */
.fm-card { background:var(--bg2,#fff); border:1px solid var(--border,#e5e7eb); border-radius:10px; box-shadow:var(--sh,0 1px 3px rgba(15,31,61,.08)); margin-bottom:18px; overflow:hidden; }
.fm-card-hdr { display:flex; align-items:center; justify-content:space-between; padding:14px 20px; border-bottom:1px solid var(--border,#e5e7eb); gap:10px; flex-wrap:wrap; }
.fm-card-hdr--wrap { flex-wrap:wrap; }
.fm-card-title { font-family:'Fraunces',serif; font-size:15px; font-weight:600; margin:0; color:var(--t1,#0f172a); display:flex; align-items:center; gap:8px; }
.fm-card-title i { color:var(--gold,#0f766e); font-size:14px; }
.fm-card-hint  { font-size:12px; color:var(--t3,#94a3b8); }
.fm-muted      { color:var(--t3,#94a3b8); font-size:12px; font-weight:500; margin-left:6px; }

/* Aging buckets */
.fm-aging-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; padding:18px 20px; }
.fm-aging-cell { padding:16px; border-radius:10px; text-align:center; border:1.5px solid transparent; transition:transform .15s ease; }
.fm-aging-cell:hover { transform:translateY(-2px); }
.fm-aging-cell--green  { background:rgba(22,163,74,.08);  border-color:rgba(22,163,74,.25); }
.fm-aging-cell--amber  { background:rgba(217,119,6,.08);  border-color:rgba(217,119,6,.25); }
.fm-aging-cell--orange { background:rgba(234,88,12,.10);  border-color:rgba(234,88,12,.30); }
.fm-aging-cell--red    { background:rgba(220,38,38,.10);  border-color:rgba(220,38,38,.30); }
.fm-aging-label { font-size:11px; font-weight:700; color:var(--t2,#475569); letter-spacing:.5px; text-transform:uppercase; }
.fm-aging-val   { font-size:28px; font-weight:800; color:var(--t1,#0f172a); margin:8px 0 4px; line-height:1; }
.fm-aging-sub   { font-size:13px; font-weight:700; color:var(--t2,#475569); }

/* Charts grid */
.fm-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:18px; }
.fm-chart-wrap { padding:18px 20px; min-height:280px; }

/* Toolbar */
.fm-toolbar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.fm-select { height:34px; padding:0 30px 0 12px; border:1.5px solid var(--border,#e5e7eb); border-radius:6px; font-size:13px; color:var(--t1,#0f172a); background:var(--bg2,#fff); cursor:pointer; outline:none; appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10'%3E%3Cpath fill='%2364748b' d='M5 7L0 2h10z'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 10px center; transition:border-color .2s; }
.fm-select:focus { border-color:var(--gold,#0f766e); box-shadow:0 0 0 3px rgba(15,118,110,.15); }

/* Buttons */
.fm-btn { display:inline-flex; align-items:center; gap:6px; height:34px; padding:0 14px; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; border:1px solid transparent; transition:all .15s; text-decoration:none; line-height:1; }
.fm-btn-sm { height:28px; padding:0 10px; font-size:12px; }
.fm-btn-ghost { background:var(--bg2,#fff); border-color:var(--border,#e5e7eb); color:var(--t1,#0f172a); }
.fm-btn-ghost:hover { border-color:var(--gold,#0f766e); color:var(--gold,#0f766e); }
.fm-btn-whatsapp { background:#25D366; color:#fff; border-color:#25D366; }
.fm-btn-whatsapp:hover { background:#1ebe5d; border-color:#1ebe5d; }
.fm-btn-push { background:#3b82f6; color:#fff; border-color:#3b82f6; }
.fm-btn-push:hover { background:#2563eb; border-color:#2563eb; }
.fm-btn[disabled] { opacity:.55; cursor:not-allowed; }

/* Table */
.fm-table-wrap { overflow:auto; max-height:640px; }
.fm-table { width:100%; border-collapse:collapse; font-size:13px; }
.fm-table thead th { position:sticky; top:0; background:var(--bg,#f8fafc); color:var(--t2,#475569); font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; padding:12px 14px; border-bottom:1px solid var(--border,#e5e7eb); text-align:left; white-space:nowrap; }
.fm-table tbody td { padding:12px 14px; border-bottom:1px solid var(--border,#f1f5f9); color:var(--t1,#0f172a); }
.fm-table tbody tr:hover { background:rgba(15,118,110,.03); }
.fm-num { text-align:right; font-family:'Plus Jakarta Sans',sans-serif; font-variant-numeric:tabular-nums; }
.fm-cell-title { font-weight:600; }
.fm-cell-sub   { font-size:11px; color:var(--t3,#94a3b8); margin-top:2px; }
.fm-cell-danger{ color:#dc2626; font-weight:700; }
.fm-empty { text-align:center; padding:28px; color:var(--t3,#94a3b8); font-size:13px; }
.fm-empty.fm-err { color:#dc2626; }
.fm-empty a { color:var(--gold,#0f766e); text-decoration:underline; }

/* Badges */
.fm-badge { display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:700; text-transform:capitalize; line-height:1.4; }
.fm-badge--green { background:rgba(22,163,74,.12); color:#16a34a; }
.fm-badge--amber { background:rgba(217,119,6,.12); color:#d97706; }
.fm-badge--red   { background:rgba(220,38,38,.12); color:#dc2626; }

/* Checkboxes */
.fm-check-input { width:16px; height:16px; accent-color:var(--gold,#0f766e); cursor:pointer; vertical-align:middle; }

/* Blocking policy card */
.fm-policy-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; padding:16px 20px; }
.fm-policy-toggle { display:flex; gap:12px; padding:14px; border:1.5px solid var(--border,#e5e7eb); border-radius:10px; cursor:pointer; transition:all .15s; background:var(--bg,#fff); }
.fm-policy-toggle:hover { border-color:var(--gold,#0f766e); }
.fm-policy-toggle input[type="checkbox"] { width:18px; height:18px; accent-color:var(--gold,#0f766e); margin-top:2px; flex-shrink:0; cursor:pointer; }
.fm-policy-toggle:has(input:checked) { background:rgba(15,118,110,.05); border-color:var(--gold,#0f766e); }
.fm-policy-title { font-size:13px; font-weight:700; color:var(--t1,#0f172a); margin-bottom:2px; }
.fm-policy-title i { color:var(--gold,#0f766e); margin-right:6px; }
.fm-policy-sub { font-size:11.5px; color:var(--t3,#94a3b8); }
.fm-policy-footer { display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid var(--border,#e5e7eb); background:var(--bg,#f8fafc); gap:12px; flex-wrap:wrap; }
.fm-policy-inputs { display:flex; gap:20px; align-items:center; flex-wrap:wrap; }
.fm-policy-inputs label { display:flex; flex-direction:column; font-size:11.5px; font-weight:600; color:var(--t2,#475569); gap:4px; }
.fm-input-sm { width:140px; height:32px; padding:0 10px; border:1.5px solid var(--border,#e5e7eb); border-radius:6px; font-size:13px; outline:none; }
.fm-input-sm:focus { border-color:var(--gold,#0f766e); box-shadow:0 0 0 3px rgba(15,118,110,.15); }
.fm-inline-check { flex-direction:row !important; align-items:center; gap:8px !important; font-weight:500 !important; }
.fm-inline-check input { accent-color:var(--gold,#0f766e); }
.fm-btn-primary { background:var(--gold,#0f766e); color:#fff; border-color:var(--gold,#0f766e); }
.fm-btn-primary:hover { background:#0d6961; }

/* Responsive */
@media (max-width:980px){
  .fm-stats-row, .fm-aging-grid, .fm-grid-2, .fm-policy-grid { grid-template-columns:1fr 1fr; }
}
@media (max-width:640px){
  .fm-stats-row, .fm-aging-grid, .fm-grid-2, .fm-policy-grid { grid-template-columns:1fr; }
  .fm-toolbar { width:100%; }
  .fm-toolbar .fm-select, .fm-toolbar .fm-btn { flex:1; }
}
</style>
