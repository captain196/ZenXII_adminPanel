<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div class="fm-wrap fm-page">

    <!-- ── Top Bar ── -->
    <div class="fm-topbar">
        <div class="fm-topbar-left">
            <h1 class="fm-page-title">Student Fee Ledger</h1>
            <nav class="fm-breadcrumb">
                <a href="<?= base_url('dashboard') ?>">Dashboard</a>
                <span class="fm-bc-sep">/</span>
                <a href="<?= base_url('fees/dashboard') ?>">Fees</a>
                <span class="fm-bc-sep">/</span>
                <span>Student Ledger &mdash; <?= htmlspecialchars($this->session_year ?? '') ?></span>
            </nav>
        </div>
        <div class="fm-topbar-right">
            <a href="<?= base_url('fees/fees_counter') ?>" class="fm-btn fm-btn-ghost" id="lnkCollect" title="Collect a payment for the selected student">
                <i class="fa fa-desktop"></i> Fee Counter
            </a>
            <a href="<?= base_url('fees/defaulter_report') ?>" class="fm-btn fm-btn-ghost">
                <i class="fa fa-exclamation-circle"></i> Defaulters
            </a>
        </div>
    </div>

    <!-- ── Search Card ── -->
    <div class="fm-card">
        <div class="fm-card-hdr">
            <h2 class="fm-card-title"><i class="fa fa-search"></i> Find Student</h2>
            <span class="fm-card-hint">Type at least 2 characters of the name or ID.</span>
        </div>
        <div class="sl-search-row">
            <div class="sl-search-field">
                <input type="text" id="ledgerSearch" class="sl-input" placeholder="Type student name or admission ID…" autocomplete="off">
                <div id="ledgerSearchResults" class="sl-dropdown"></div>
            </div>
            <button class="fm-btn fm-btn-primary" id="btnLoadLedger" disabled onclick="FL.loadLedger()">
                <i class="fa fa-book"></i> Load Ledger
            </button>
        </div>
    </div>

    <!-- ── Student Header (once loaded) ── -->
    <div class="fm-card" id="studentBanner" style="display:none;">
        <div class="sl-banner">
            <div class="sl-banner-avatar"><i class="fa fa-user"></i></div>
            <div class="sl-banner-info">
                <div class="sl-banner-name" id="bannerName">—</div>
                <div class="sl-banner-meta" id="bannerMeta">—</div>
            </div>
            <div class="sl-banner-actions">
                <a href="#" class="fm-btn fm-btn-primary fm-btn-sm" id="btnCollectForStudent">
                    <i class="fa fa-inr"></i> Collect Payment
                </a>
                <button class="fm-btn fm-btn-ghost fm-btn-sm" id="btnGenDemands" onclick="FL.generateDemands()" title="Generate demands for this student only (e.g. late admission)">
                    <i class="fa fa-magic"></i> Generate Demands
                </button>
                <button class="fm-btn fm-btn-ghost fm-btn-sm" id="btnComputeFines" onclick="FL.computeFines()" title="Recompute overdue fines">
                    <i class="fa fa-calculator"></i> Compute Fines
                </button>
            </div>
        </div>

        <!-- Stat strip -->
        <div class="sl-stats" id="bannerStats">
            <div class="sl-stat sl-stat--teal">
                <span class="sl-stat-lbl">Total Due</span>
                <span class="sl-stat-val" id="statTotalDue">—</span>
            </div>
            <div class="sl-stat sl-stat--blue">
                <span class="sl-stat-lbl">Paid</span>
                <span class="sl-stat-val" id="statPaid">—</span>
            </div>
            <div class="sl-stat sl-stat--red">
                <span class="sl-stat-lbl">Outstanding</span>
                <span class="sl-stat-val" id="statBalance">—</span>
            </div>
            <div class="sl-stat sl-stat--amber">
                <span class="sl-stat-lbl">Overdue Months</span>
                <span class="sl-stat-val" id="statOverdue">—</span>
            </div>
        </div>
    </div>

    <!-- ── Ledger (demands) ── -->
    <div class="fm-card" id="ledgerCard" style="display:none;">
        <div class="fm-card-hdr fm-card-hdr--wrap">
            <h2 class="fm-card-title"><i class="fa fa-list-alt"></i> Fee Demands</h2>
            <div class="fm-toolbar">
                <select id="filterStatus" class="fm-select" onchange="FL.filterTable()">
                    <option value="">All statuses</option>
                    <option value="unpaid">Unpaid</option>
                    <option value="partial">Partial</option>
                    <option value="paid">Paid</option>
                </select>
                <select id="filterMonth" class="fm-select" onchange="FL.filterTable()">
                    <option value="">All periods</option>
                </select>
                <button class="fm-btn fm-btn-ghost fm-btn-sm" onclick="FL.exportCSV()">
                    <i class="fa fa-download"></i> Export CSV
                </button>
            </div>
        </div>
        <div class="fm-table-wrap">
            <table class="fm-table sl-table">
                <colgroup>
                    <col class="sl-c-period">
                    <col class="sl-c-head">
                    <col class="sl-c-amt">
                    <col class="sl-c-amt">
                    <col class="sl-c-amt">
                    <col class="sl-c-amt">
                    <col class="sl-c-status">
                </colgroup>
                <thead>
                    <tr>
                        <th>Period</th>
                        <th>Fee Head</th>
                        <th class="num">Net Amount</th>
                        <th class="num">Paid</th>
                        <th class="num">Balance</th>
                        <th class="num">Fine</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="ledgerBody">
                    <tr><td colspan="7" class="fm-empty">Select a student to see their demands.</td></tr>
                </tbody>
                <tfoot id="ledgerFoot" style="display:none;">
                    <tr class="sl-totals-row">
                        <td colspan="2">TOTALS</td>
                        <td class="num" id="footNet">—</td>
                        <td class="num" id="footPaid">—</td>
                        <td class="num" id="footBalance">—</td>
                        <td class="num" id="footFine">—</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- ── Payment history ── -->
    <div class="fm-card" id="allocCard" style="display:none;">
        <div class="fm-card-hdr">
            <h2 class="fm-card-title"><i class="fa fa-exchange"></i> Payment History</h2>
            <span class="fm-card-hint">Every receipt issued to this student, with the allocation breakdown.</span>
        </div>
        <div class="fm-table-wrap">
            <table class="fm-table sl-alloc-table">
                <thead>
                    <tr>
                        <th>Receipt</th>
                        <th>Date</th>
                        <th class="num">Total</th>
                        <th class="num">Discount</th>
                        <th class="num">Fine</th>
                        <th>Mode</th>
                        <th>Allocated To</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="allocBody">
                    <tr><td colspan="8" class="fm-empty">No payments yet.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    var BASE = '<?= base_url() ?>';
    var selectedStudent = null;
    var demandsCache = [];
    var csrfName = '<?= $this->security->get_csrf_token_name() ?>';
    var csrfHash = '<?= $this->security->get_csrf_hash() ?>';

    function fmt(n){ return Number(n||0).toLocaleString('en-IN',{minimumFractionDigits:0,maximumFractionDigits:0}); }
    function esc(s){ var d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }

    // ── Student search (typeahead) ──
    var searchTimer = null;
    $('#ledgerSearch').on('input', function(){
        clearTimeout(searchTimer);
        var q = $(this).val().trim();
        if(q.length < 2){ $('#ledgerSearchResults').html('').hide(); return; }
        searchTimer = setTimeout(function(){
            var body = {}; body.search_name = q; body[csrfName] = csrfHash;
            $.post(BASE+'fees/search_student', body, function(r){
                var res = typeof r==='string'?JSON.parse(r):r;
                if(!Array.isArray(res)||!res.length){
                    $('#ledgerSearchResults').html('<div class="sl-dd-empty">No matches</div>').show();
                    return;
                }
                var h='';
                res.forEach(function(s){
                    h+='<div class="sl-dd-item" data-uid="'+esc(s.user_id)+'" data-name="'+esc(s.name)+'" data-class="'+esc(s.class||'')+'" data-section="'+esc(s.section||'')+'" data-father="'+esc(s.father_name||'')+'">';
                    h+='<div class="sl-dd-name">'+esc(s.name)+'</div>';
                    h+='<div class="sl-dd-meta">'+esc(s.user_id)+' &middot; '+esc(s.class||'')+' '+esc(s.section||'')+'</div>';
                    h+='</div>';
                });
                $('#ledgerSearchResults').html(h).show();
            });
        }, 280);
    });

    $(document).on('click','.sl-dd-item', function(){
        selectedStudent = {
            id: $(this).data('uid'),
            name: $(this).data('name'),
            class: $(this).data('class'),
            section: $(this).data('section'),
            father: $(this).data('father')
        };
        $('#ledgerSearch').val(selectedStudent.name+' ('+selectedStudent.id+')');
        $('#ledgerSearchResults').hide();
        $('#btnLoadLedger').prop('disabled', false);
    });

    $(document).on('click', function(e){
        if(!$(e.target).closest('.sl-search-field').length) $('#ledgerSearchResults').hide();
    });

    // Pressing Enter loads ledger if a student is selected.
    $('#ledgerSearch').on('keydown', function(e){
        if (e.key === 'Enter' && !$('#btnLoadLedger').prop('disabled')) {
            e.preventDefault();
            loadLedger();
        }
    });

    // ── Load ledger ──
    function loadLedger(){
        if(!selectedStudent) return;
        var sid = selectedStudent.id;

        $('#ledgerBody').html('<tr><td colspan="7" class="fm-empty"><i class="fa fa-spinner fa-spin"></i> Loading demands…</td></tr>');
        $('#studentBanner, #ledgerCard').show();
        $('#allocCard').show();
        $('#allocBody').html('<tr><td colspan="8" class="fm-empty"><i class="fa fa-spinner fa-spin"></i> Loading…</td></tr>');

        $('#bannerName').text(selectedStudent.name);
        var metaBits = [selectedStudent.id, selectedStudent.class+' '+selectedStudent.section];
        if (selectedStudent.father) metaBits.push('Father: '+selectedStudent.father);
        $('#bannerMeta').text(metaBits.filter(Boolean).join(' · '));

        // Point "Collect Payment" at the fee counter for this student.
        $('#btnCollectForStudent').attr('href', BASE+'fees/fees_counter?uid='+encodeURIComponent(sid));

        $.getJSON(BASE+'fees/get_student_demands?student_id='+encodeURIComponent(sid), function(r){
            if(!r || r.status !== 'success'){
                $('#ledgerBody').html('<tr><td colspan="7" class="fm-empty fm-err">Failed to load. Please try again.</td></tr>');
                return;
            }
            demandsCache = r.demands || [];

            // Stats strip
            var s = r.summary || {};
            var today = new Date().toISOString().slice(0,10);
            var overdueMonths = new Set();
            demandsCache.forEach(function(d){
                if (d.status !== 'paid' && d.due_date && d.due_date < today && (parseFloat(d.balance||0) > 0.005)) {
                    overdueMonths.add(d.period || '');
                }
            });
            $('#statTotalDue').text('₹'+fmt(s.total_net));
            $('#statPaid').text('₹'+fmt(s.total_paid));
            $('#statBalance').text('₹'+fmt(s.total_balance));
            $('#statOverdue').text(overdueMonths.size);

            // Populate period filter
            var periods = Array.from(new Set(demandsCache.map(function(d){ return d.period || ''; }).filter(Boolean)));
            $('#filterMonth').html(
                '<option value="">All periods</option>' +
                periods.map(function(p){ return '<option value="'+esc(p)+'">'+esc(p)+'</option>'; }).join('')
            );

            renderTable();
            loadAllocations(sid);
        }).fail(function(){
            $('#ledgerBody').html('<tr><td colspan="7" class="fm-empty fm-err">Failed to load demands. Check your session and retry.</td></tr>');
        });
    }

    function renderTable(){
        var $tb = $('#ledgerBody');
        var filterStatus = $('#filterStatus').val();
        var filterMonth  = $('#filterMonth').val();

        if(!demandsCache.length){
            $tb.html(
                '<tr><td colspan="7" class="fm-empty">' +
                    '<i class="fa fa-inbox" style="font-size:24px;color:var(--t3);margin-bottom:8px;display:block;"></i>' +
                    'No demands yet. Click <strong>Generate Demands</strong> above to create them for this student.' +
                '</td></tr>'
            );
            $('#ledgerFoot').hide();
            return;
        }

        var today = new Date().toISOString().slice(0,10);
        var h = '', tNet=0, tPaid=0, tBal=0, tFine=0, visibleRows=0;

        demandsCache.forEach(function(d){
            if(filterStatus && d.status !== filterStatus) return;
            if(filterMonth && d.period !== filterMonth) return;
            visibleRows++;

            var bal  = parseFloat(d.balance||0);
            var fine = parseFloat(d.fine_amount||0);
            var isOverdue = (d.status !== 'paid' && d.due_date && d.due_date < today && bal > 0.005);
            var statusCls =
                d.status === 'paid'    ? 'fm-badge--green' :
                d.status === 'partial' ? 'fm-badge--amber' : 'fm-badge--red';
            var rowCls = isOverdue ? 'sl-row-overdue' : '';

            h+='<tr class="'+rowCls+'">';
            h+=  '<td><div class="sl-period">'+esc(d.period||'—')+'</div>'
              +    (d.due_date ? '<div class="sl-period-sub">Due '+esc(d.due_date)+'</div>' : '')
              +  '</td>';
            h+=  '<td><div class="sl-head-name">'+esc(d.fee_head||'—')+'</div>'
              +    (d.category ? '<div class="sl-head-sub">'+esc(d.category)+'</div>' : '')
              +  '</td>';
            h+=  '<td class="num">₹'+fmt(d.net_amount)
              +    (parseFloat(d.discount_amount)>0 ? '<div class="sl-sub-muted">less ₹'+fmt(d.discount_amount)+' disc</div>' : '')
              +  '</td>';
            h+=  '<td class="num">₹'+fmt(d.paid_amount)+'</td>';
            h+=  '<td class="num sl-num-primary" style="'+(bal>0.005?'color:#dc2626;':'color:#16a34a;')+'">₹'+fmt(bal)+'</td>';
            h+=  '<td class="num">'+(fine>0 ? '<span style="color:#d97706;font-weight:600;">₹'+fmt(fine)+'</span>' : '<span class="sl-muted">—</span>')+'</td>';
            h+=  '<td><span class="fm-badge '+statusCls+'">'+esc(d.status)+'</span>'
              +    (isOverdue ? ' <span class="sl-overdue-tag"><i class="fa fa-clock-o"></i> Overdue</span>' : '')
              +  '</td>';
            h+='</tr>';

            tNet  += parseFloat(d.net_amount||0);
            tPaid += parseFloat(d.paid_amount||0);
            tBal  += bal;
            tFine += fine;
        });

        if(!visibleRows){
            h = '<tr><td colspan="7" class="fm-empty">No demands match this filter.</td></tr>';
            $('#ledgerFoot').hide();
        } else {
            $('#ledgerFoot').show();
            $('#footNet').text('₹'+fmt(tNet));
            $('#footPaid').text('₹'+fmt(tPaid));
            $('#footBalance').text('₹'+fmt(tBal));
            $('#footFine').text(tFine>0 ? '₹'+fmt(tFine) : '—');
        }
        $tb.html(h);
    }

    // ── Allocations (payment history) ──
    function loadAllocations(sid){
        $.getJSON(BASE+'fees/get_student_allocations?student_id='+encodeURIComponent(sid), function(r){
            var $tb = $('#allocBody');
            if(!r || r.status !== 'success' || !r.receipts || !r.receipts.length){
                $tb.html('<tr><td colspan="8" class="fm-empty">No payments yet.</td></tr>');
                return;
            }
            var h = '';
            r.receipts.forEach(function(rc){
                var allocHtml;
                if(rc.allocations && rc.allocations.length){
                    allocHtml = '<div class="sl-alloc-list">' +
                        rc.allocations.map(function(a){
                            var pillCls = a.new_status === 'paid' ? 'fm-badge--green' : 'fm-badge--amber';
                            return '<div class="sl-alloc-item">' +
                                '<span class="sl-alloc-label">'+esc(a.period)+' · '+esc(a.fee_head)+'</span>' +
                                '<span class="sl-alloc-amt">₹'+fmt(a.amount)+'</span>' +
                                '<span class="fm-badge '+pillCls+'">'+esc(a.new_status)+'</span>' +
                            '</div>';
                        }).join('') +
                    '</div>';
                } else {
                    allocHtml = '<span class="sl-muted">Legacy receipt (no allocation breakdown)</span>';
                }
                var statusBadge = (rc.status||'active')==='reversed' ? 'fm-badge--red' : 'fm-badge--green';
                h+='<tr>';
                h+=  '<td><code class="sl-receipt-no">'+esc(rc.receipt_no||'—')+'</code></td>';
                h+=  '<td>'+esc(rc.date||'—')+'</td>';
                h+=  '<td class="num">₹'+fmt(rc.total_amount)+'</td>';
                h+=  '<td class="num">'+(parseFloat(rc.discount)>0?'₹'+fmt(rc.discount):'<span class="sl-muted">—</span>')+'</td>';
                h+=  '<td class="num">'+(parseFloat(rc.fine)>0?'₹'+fmt(rc.fine):'<span class="sl-muted">—</span>')+'</td>';
                h+=  '<td><span class="fm-badge fm-badge--neutral">'+esc(rc.payment_mode||'—')+'</span></td>';
                h+=  '<td>'+allocHtml+'</td>';
                h+=  '<td><span class="fm-badge '+statusBadge+'">'+esc(rc.status||'active')+'</span></td>';
                h+='</tr>';
            });
            $tb.html(h);
        }).fail(function(){
            $('#allocBody').html('<tr><td colspan="8" class="fm-empty fm-err">Could not load payment history.</td></tr>');
        });
    }

    // ── Actions ──
    function generateDemands(){
        if(!selectedStudent) return;
        if(!confirm('Generate fee demands for '+selectedStudent.name+'?\n\nThis creates bills for every academic month based on the fee chart. Existing demands are skipped.')) return;
        var body = {}; body.student_id = selectedStudent.id; body[csrfName] = csrfHash;
        $.post(BASE+'fees/generate_demands_for_student', body, function(r){
            r = typeof r==='string' ? JSON.parse(r) : r;
            alert(r.message || 'Done');
            loadLedger();
        });
    }

    function computeFines(){
        if(!selectedStudent) return;
        var body = {}; body.student_id = selectedStudent.id; body[csrfName] = csrfHash;
        $.post(BASE+'fees/auto_compute_fines', body, function(r){
            r = typeof r==='string' ? JSON.parse(r) : r;
            alert(r.message || 'Done');
            loadLedger();
        });
    }

    // ── CSV export (client-side) ──
    function exportCSV(){
        if(!demandsCache.length || !selectedStudent) return;
        var rows = [[
            'Period','Fee Head','Category','Net Amount','Discount','Paid','Balance','Fine','Status','Due Date'
        ]];
        demandsCache.forEach(function(d){
            rows.push([
                d.period || '', d.fee_head || '', d.category || 'General',
                d.net_amount || 0, d.discount_amount || 0,
                d.paid_amount || 0, d.balance || 0, d.fine_amount || 0,
                d.status || '', d.due_date || ''
            ]);
        });
        var csv = rows.map(function(r){
            return r.map(function(c){
                var v = String(c ?? '');
                return /[",\n]/.test(v) ? '"'+v.replace(/"/g,'""')+'"' : v;
            }).join(',');
        }).join('\n');
        var blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'ledger_'+selectedStudent.id+'_'+new Date().toISOString().slice(0,10)+'.csv';
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
    }

    window.FL = { loadLedger:loadLedger, filterTable:renderTable, generateDemands:generateDemands, computeFines:computeFines, exportCSV:exportCSV };
});
</script>

<style>
/* ═══ fm- design tokens (matches Defaulter Report / Generate Demands) ═══ */
.fm-wrap { max-width:1360px; margin:0 auto; padding:20px 24px 40px; color:var(--t1,#0f172a); font-family:'Plus Jakarta Sans',var(--font-b,sans-serif); }
.fm-topbar { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; margin-bottom:20px; flex-wrap:wrap; }
.fm-page-title { font-family:'Fraunces',serif; font-size:1.35rem; font-weight:600; color:var(--t1,#0f172a); margin:0; letter-spacing:-.01em; }
.fm-breadcrumb { font-size:.78rem; color:var(--t3,#94a3b8); margin-top:3px; }
.fm-breadcrumb a { color:var(--gold,#0f766e); text-decoration:none; }
.fm-breadcrumb a:hover { text-decoration:underline; }
.fm-bc-sep { margin:0 5px; color:var(--t3,#94a3b8); }
.fm-topbar-right { display:flex; gap:8px; flex-wrap:wrap; }

.fm-card { background:var(--bg2,#fff); border:1px solid var(--border,#e5e7eb); border-radius:10px; box-shadow:var(--sh,0 1px 3px rgba(15,31,61,.08)); margin-bottom:18px; overflow:hidden; }
.fm-card-hdr { display:flex; align-items:center; justify-content:space-between; padding:14px 20px; border-bottom:1px solid var(--border,#e5e7eb); gap:10px; flex-wrap:wrap; }
.fm-card-hdr--wrap { flex-wrap:wrap; }
.fm-card-title { font-family:'Fraunces',serif; font-size:15px; font-weight:600; margin:0; color:var(--t1,#0f172a); display:flex; align-items:center; gap:8px; }
.fm-card-title i { color:var(--gold,#0f766e); font-size:14px; }
.fm-card-hint { font-size:12px; color:var(--t3,#94a3b8); }

.fm-select { height:34px; padding:0 30px 0 12px; border:1.5px solid var(--border,#e5e7eb); border-radius:6px; font-size:13px; color:var(--t1,#0f172a); background:var(--bg2,#fff); cursor:pointer; outline:none; appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10'%3E%3Cpath fill='%2364748b' d='M5 7L0 2h10z'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 10px center; transition:border-color .2s; font-family:inherit; }
.fm-select:focus { border-color:var(--gold,#0f766e); box-shadow:0 0 0 3px rgba(15,118,110,.15); }

.fm-btn { display:inline-flex; align-items:center; gap:6px; height:34px; padding:0 14px; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; border:1px solid transparent; transition:all .15s; text-decoration:none; line-height:1; font-family:inherit; }
.fm-btn-sm { height:28px; padding:0 10px; font-size:12px; }
.fm-btn-ghost { background:var(--bg2,#fff); border-color:var(--border,#e5e7eb); color:var(--t1,#0f172a); }
.fm-btn-ghost:hover { border-color:var(--gold,#0f766e); color:var(--gold,#0f766e); }
.fm-btn-primary { background:var(--gold,#0f766e); color:#fff; border-color:var(--gold,#0f766e); }
.fm-btn-primary:hover { background:#0d6961; }
.fm-btn[disabled] { opacity:.55; cursor:not-allowed; }

.fm-toolbar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.fm-table-wrap { overflow:auto; max-height:640px; }
.fm-table { width:100%; border-collapse:collapse; font-size:13px; }
.fm-table thead th { position:sticky; top:0; background:var(--bg,#f8fafc); color:var(--t2,#475569); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; padding:12px 14px; border-bottom:1px solid var(--border,#e5e7eb); text-align:left; white-space:nowrap; z-index:1; }
.fm-table thead th.num { text-align:right; }
.fm-table tbody td { padding:12px 14px; border-bottom:1px solid var(--border,#f1f5f9); color:var(--t1,#0f172a); vertical-align:middle; }
.fm-table tbody td.num { text-align:right; font-variant-numeric:tabular-nums; }
.fm-table tbody tr:hover { background:rgba(15,118,110,.03); }
.fm-empty { text-align:center; padding:36px 16px; color:var(--t3,#94a3b8); font-size:13px; }
.fm-empty.fm-err { color:#dc2626; }

.fm-badge { display:inline-block; padding:2px 10px; border-radius:12px; font-size:11px; font-weight:700; text-transform:capitalize; line-height:1.4; }
.fm-badge--green { background:rgba(22,163,74,.12); color:#16a34a; }
.fm-badge--amber { background:rgba(217,119,6,.12); color:#d97706; }
.fm-badge--red   { background:rgba(220,38,38,.12); color:#dc2626; }
.fm-badge--neutral { background:rgba(15,31,61,.08); color:var(--t2,#475569); }

/* ═══ Student Ledger specific (sl- prefix) ═══ */

/* Search row */
.sl-search-row { display:flex; gap:12px; align-items:center; padding:18px 20px; flex-wrap:wrap; }
.sl-search-field { flex:1; min-width:280px; position:relative; }
.sl-input { width:100%; height:40px; padding:0 14px; border:1.5px solid var(--border,#e5e7eb); border-radius:8px; font-size:14px; color:var(--t1,#0f172a); background:var(--bg2,#fff); outline:none; font-family:inherit; transition:border-color .14s, box-shadow .14s; }
.sl-input:focus { border-color:var(--gold,#0f766e); box-shadow:0 0 0 3px rgba(15,118,110,.15); }
.sl-dropdown { position:absolute; top:calc(100% + 4px); left:0; right:0; background:var(--bg2,#fff); border:1px solid var(--border,#e5e7eb); border-radius:8px; box-shadow:0 8px 24px rgba(15,31,61,.12); max-height:280px; overflow-y:auto; display:none; z-index:50; }
.sl-dd-item { padding:10px 14px; cursor:pointer; border-bottom:1px solid var(--border,#f1f5f9); }
.sl-dd-item:last-child { border-bottom:none; }
.sl-dd-item:hover { background:rgba(15,118,110,.06); }
.sl-dd-name { font-weight:600; font-size:13.5px; color:var(--t1,#0f172a); }
.sl-dd-meta { font-size:11.5px; color:var(--t3,#94a3b8); margin-top:2px; }
.sl-dd-empty { padding:16px; text-align:center; color:var(--t3,#94a3b8); font-size:13px; }

/* Banner */
.sl-banner { display:flex; align-items:center; gap:16px; padding:16px 20px; border-bottom:1px solid var(--border,#e5e7eb); flex-wrap:wrap; }
.sl-banner-avatar { width:48px; height:48px; border-radius:50%; background:rgba(15,118,110,.12); color:#0f766e; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
.sl-banner-info { flex:1; min-width:200px; }
.sl-banner-name { font-size:1.1rem; font-weight:700; color:var(--t1,#0f172a); line-height:1.2; }
.sl-banner-meta { font-size:12.5px; color:var(--t3,#94a3b8); margin-top:3px; }
.sl-banner-actions { display:flex; gap:8px; flex-wrap:wrap; }

.sl-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:0; border-top:none; }
.sl-stat { display:flex; flex-direction:column; align-items:flex-start; padding:14px 20px; border-right:1px solid var(--border,#f1f5f9); }
.sl-stat:last-child { border-right:none; }
.sl-stat-lbl { font-size:11px; font-weight:700; color:var(--t2,#475569); text-transform:uppercase; letter-spacing:.5px; }
.sl-stat-val { font-size:1.3rem; font-weight:800; color:var(--t1,#0f172a); margin-top:4px; font-variant-numeric:tabular-nums; }
.sl-stat--teal .sl-stat-val { color:#0f766e; }
.sl-stat--blue .sl-stat-val { color:#2563eb; }
.sl-stat--red  .sl-stat-val { color:#dc2626; }
.sl-stat--amber .sl-stat-val { color:#d97706; }

/* Ledger table-specific */
.sl-table { table-layout:fixed; }
.sl-c-period { width:14%; }
.sl-c-head   { width:22%; }
.sl-c-amt    { width:13%; }
.sl-c-status { width:12%; }

.sl-period { font-weight:600; color:var(--t1,#0f172a); }
.sl-period-sub { font-size:11px; color:var(--t3,#94a3b8); margin-top:2px; }
.sl-head-name { font-weight:600; color:var(--t1,#0f172a); }
.sl-head-sub { font-size:11px; color:var(--t3,#94a3b8); margin-top:2px; }
.sl-sub-muted { font-size:11px; color:var(--t3,#94a3b8); margin-top:2px; font-weight:400; }
.sl-num-primary { font-weight:700; }
.sl-muted { color:var(--t3,#94a3b8); }

.sl-row-overdue td { background:rgba(220,38,38,.035); }
.sl-row-overdue:hover td { background:rgba(220,38,38,.07) !important; }
.sl-overdue-tag { display:inline-flex; align-items:center; gap:3px; font-size:10px; font-weight:700; color:#dc2626; text-transform:uppercase; margin-left:6px; letter-spacing:.4px; }

/* Totals row */
.sl-totals-row td { background:var(--bg,#f8fafc); font-weight:700; border-top:2px solid var(--border,#e5e7eb); }

/* Payment history allocation list */
.sl-alloc-table .fm-table-wrap { max-height:520px; }
.sl-alloc-list { display:flex; flex-direction:column; gap:6px; font-size:12px; }
.sl-alloc-item { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.sl-alloc-label { color:var(--t2,#475569); }
.sl-alloc-amt { font-weight:700; color:var(--t1,#0f172a); font-variant-numeric:tabular-nums; }
.sl-receipt-no { background:rgba(15,118,110,.08); color:#0f766e; padding:2px 8px; border-radius:4px; font-family:'JetBrains Mono',monospace; font-size:12px; font-weight:600; }

/* Responsive */
@media (max-width:980px) {
    .sl-stats { grid-template-columns:1fr 1fr; }
    .sl-stat { border-right:none; border-bottom:1px solid var(--border,#f1f5f9); }
    .sl-stat:nth-child(2n) { border-right:none; }
}
@media (max-width:640px) {
    .sl-stats { grid-template-columns:1fr; }
}
</style>
