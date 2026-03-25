<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div class="fd-wrap">

    <!-- Header -->
    <div class="fd-header">
        <div class="fd-header-icon"><i class="fa fa-book"></i></div>
        <div>
            <h2>Student Fee Ledger</h2>
            <p>Demand-level fee tracking per student &mdash; <?= htmlspecialchars($this->session_year ?? '') ?></p>
        </div>
        <div class="fd-header-actions">
            <a href="<?= base_url('fees/dashboard') ?>" class="fd-btn fd-btn-ghost"><i class="fa fa-arrow-left"></i> Dashboard</a>
        </div>
    </div>

    <!-- Student Search -->
    <div class="fd-card" style="margin-bottom:18px;">
        <div class="fd-card-title"><i class="fa fa-search"></i> Select Student</div>
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <div style="flex:1;min-width:250px;">
                <label class="fd-label">Search by Name or ID</label>
                <input type="text" id="ledgerSearch" class="fd-input" placeholder="Type student name or ID..." autocomplete="off">
                <div id="ledgerSearchResults" class="fd-search-dropdown"></div>
            </div>
            <button class="fd-btn fd-btn-primary" id="btnLoadLedger" disabled onclick="FL.loadLedger()"><i class="fa fa-book"></i> Load Ledger</button>
            <button class="fd-btn fd-btn-ghost fd-btn-sm" id="btnGenDemands" style="display:none;" onclick="FL.generateDemands()"><i class="fa fa-magic"></i> Generate Demands</button>
            <button class="fd-btn fd-btn-ghost fd-btn-sm" id="btnComputeFines" style="display:none;" onclick="FL.computeFines()"><i class="fa fa-calculator"></i> Compute Fines</button>
        </div>
    </div>

    <!-- Student Info Banner -->
    <div id="studentBanner" style="display:none;" class="fd-card" style="margin-bottom:18px;">
        <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
            <div style="flex:1;">
                <div style="font-size:18px;font-weight:700;color:var(--t1);" id="bannerName">—</div>
                <div style="font-size:13px;color:var(--t3);" id="bannerMeta">—</div>
            </div>
            <div class="fd-stats" style="margin:0;gap:12px;" id="bannerStats"></div>
        </div>
    </div>

    <!-- Ledger Table -->
    <div class="fd-card" id="ledgerCard" style="display:none;">
        <div class="fd-card-title">
            <i class="fa fa-list-alt"></i> Fee Demands
            <span style="flex:1;"></span>
            <select id="filterStatus" class="fd-filter" onchange="FL.filterTable()">
                <option value="">All Status</option>
                <option value="unpaid">Unpaid</option>
                <option value="partial">Partial</option>
                <option value="paid">Paid</option>
            </select>
        </div>
        <div class="fd-table-wrap" style="max-height:600px;">
            <table class="fd-table" id="tblLedger">
                <thead>
                    <tr>
                        <th>Period</th>
                        <th>Fee Head</th>
                        <th>Category</th>
                        <th class="fd-num">Amount</th>
                        <th class="fd-num">Discount</th>
                        <th class="fd-num">Net</th>
                        <th class="fd-num">Fine</th>
                        <th class="fd-num">Paid</th>
                        <th class="fd-num">Balance</th>
                        <th>Status</th>
                        <th>Due Date</th>
                    </tr>
                </thead>
                <tbody id="ledgerBody">
                    <tr><td colspan="11" class="fd-empty">Select a student above</td></tr>
                </tbody>
                <tfoot id="ledgerFoot" style="display:none;">
                    <tr style="font-weight:700;border-top:2px solid var(--border);">
                        <td colspan="3">TOTALS</td>
                        <td class="fd-num" id="footOriginal">—</td>
                        <td class="fd-num" id="footDiscount">—</td>
                        <td class="fd-num" id="footNet">—</td>
                        <td class="fd-num" id="footFine">—</td>
                        <td class="fd-num" id="footPaid">—</td>
                        <td class="fd-num" id="footBalance">—</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Payment Allocation History -->
    <div class="fd-card" id="allocCard" style="display:none;margin-top:18px;">
        <div class="fd-card-title"><i class="fa fa-exchange"></i> Payment Allocation History</div>
        <div class="fd-table-wrap">
            <table class="fd-table" id="tblAlloc">
                <thead><tr><th>Receipt</th><th>Date</th><th>Total</th><th>Discount</th><th>Fine</th><th>Advance</th><th>Mode</th><th>Allocations</th><th>Status</th></tr></thead>
                <tbody id="allocBody"><tr><td colspan="9" class="fd-empty">No receipts found</td></tr></tbody>
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

    function fmt(n){ return Number(n||0).toLocaleString('en-IN',{minimumFractionDigits:0,maximumFractionDigits:0}); }
    function esc(s){ var d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }

    // ── Student Search (typeahead) ──
    var searchTimer = null;
    $('#ledgerSearch').on('input', function(){
        clearTimeout(searchTimer);
        var q = $(this).val().trim();
        if(q.length < 2){ $('#ledgerSearchResults').html('').hide(); return; }
        searchTimer = setTimeout(function(){
            $.post(BASE+'fees/search_student', {search_name:q, '<?=$this->security->get_csrf_token_name()?>':'<?=$this->security->get_csrf_hash()?>'}, function(r){
                var res = typeof r==='string'?JSON.parse(r):r;
                if(!Array.isArray(res)||!res.length){ $('#ledgerSearchResults').html('<div class="fd-dd-empty">No results</div>').show(); return; }
                var h='';
                res.forEach(function(s){
                    h+='<div class="fd-dd-item" data-uid="'+esc(s.user_id)+'" data-name="'+esc(s.name)+'" data-class="'+esc(s.class||'')+'" data-section="'+esc(s.section||'')+'" data-father="'+esc(s.father_name||'')+'">';
                    h+='<strong>'+esc(s.name)+'</strong> <span style="color:var(--t3);">'+esc(s.user_id)+' &middot; '+esc(s.class||'')+' '+esc(s.section||'')+'</span>';
                    h+='</div>';
                });
                $('#ledgerSearchResults').html(h).show();
            });
        }, 300);
    });

    $(document).on('click','.fd-dd-item', function(){
        selectedStudent = {
            id: $(this).data('uid'),
            name: $(this).data('name'),
            class: $(this).data('class'),
            section: $(this).data('section'),
            father: $(this).data('father')
        };
        $('#ledgerSearch').val(selectedStudent.name+' ('+selectedStudent.id+')');
        $('#ledgerSearchResults').hide();
        $('#btnLoadLedger').prop('disabled',false);
    });

    $(document).on('click', function(e){ if(!$(e.target).closest('#ledgerSearch,#ledgerSearchResults').length) $('#ledgerSearchResults').hide(); });

    // ── Load Ledger ──
    function loadLedger(){
        if(!selectedStudent) return;
        var sid = selectedStudent.id;
        $('#ledgerBody').html('<tr><td colspan="11" class="fd-empty"><i class="fa fa-spinner fa-spin"></i> Loading demands...</td></tr>');
        $('#ledgerCard').show();

        // Show banner
        $('#studentBanner').show();
        $('#bannerName').text(selectedStudent.name);
        $('#bannerMeta').text(selectedStudent.id+' | '+selectedStudent.class+' '+selectedStudent.section+(selectedStudent.father?' | Father: '+selectedStudent.father:''));

        $.getJSON(BASE+'fees/get_student_demands?student_id='+encodeURIComponent(sid), function(r){
            if(!r||r.status!=='success'){ $('#ledgerBody').html('<tr><td colspan="11" class="fd-empty">Failed to load</td></tr>'); return; }
            demandsCache = r.demands||[];

            // Show action buttons
            $('#btnGenDemands,#btnComputeFines').show();

            // Stats banner
            var s = r.summary||{};
            $('#bannerStats').html(
                '<div class="fd-stat fd-stat-teal" style="padding:12px 16px;"><div class="fd-stat-body"><div class="fd-stat-num" style="font-size:18px;">₹'+fmt(s.total_net)+'</div><div class="fd-stat-lbl">Total Due</div></div></div>'
                +'<div class="fd-stat fd-stat-blue" style="padding:12px 16px;"><div class="fd-stat-body"><div class="fd-stat-num" style="font-size:18px;">₹'+fmt(s.total_paid)+'</div><div class="fd-stat-lbl">Paid</div></div></div>'
                +'<div class="fd-stat fd-stat-red" style="padding:12px 16px;"><div class="fd-stat-body"><div class="fd-stat-num" style="font-size:18px;">₹'+fmt(s.total_balance)+'</div><div class="fd-stat-lbl">Balance</div></div></div>'
            );

            renderTable(demandsCache);
            loadAllocations(sid);
        });
    }

    function renderTable(demands){
        var $tb=$('#ledgerBody'), filter=$('#filterStatus').val();
        if(!demands.length){ $tb.html('<tr><td colspan="11" class="fd-empty"><i class="fa fa-inbox"></i> No demands found. Click "Generate Demands" to create.</td></tr>'); $('#ledgerFoot').hide(); return; }

        var h='', tOrig=0, tDisc=0, tNet=0, tFine=0, tPaid=0, tBal=0;
        var today = new Date().toISOString().slice(0,10);

        demands.forEach(function(d){
            if(filter && d.status!==filter) return;
            var isOverdue = (d.status!=='paid' && d.due_date && d.due_date < today);
            var statusCls = d.status==='paid'?'fd-badge-green':d.status==='partial'?'fd-badge-amber':'fd-badge-red';
            var rowCls = isOverdue ? 'style="background:rgba(220,38,38,.04);"' : '';
            var fine = parseFloat(d.fine_amount||0);

            h+='<tr '+rowCls+'>';
            h+='<td>'+esc(d.period)+'</td>';
            h+='<td><strong>'+esc(d.fee_head)+'</strong></td>';
            h+='<td><span class="fd-mode-tag">'+esc(d.category||'General')+'</span></td>';
            h+='<td class="fd-num">₹'+fmt(d.original_amount)+'</td>';
            h+='<td class="fd-num">'+(parseFloat(d.discount_amount)>0?'₹'+fmt(d.discount_amount):'—')+'</td>';
            h+='<td class="fd-num">₹'+fmt(d.net_amount)+'</td>';
            h+='<td class="fd-num">'+(fine>0?'<span style="color:#dc2626;">₹'+fmt(fine)+'</span>':'—')+'</td>';
            h+='<td class="fd-num">₹'+fmt(d.paid_amount)+'</td>';
            h+='<td class="fd-num" style="font-weight:700;'+(parseFloat(d.balance)>0?'color:#dc2626;':'color:#16a34a;')+'">₹'+fmt(d.balance)+'</td>';
            h+='<td><span class="fd-badge '+statusCls+'">'+esc(d.status)+'</span>'+(isOverdue?' <i class="fa fa-clock-o" style="color:#dc2626;font-size:11px;" title="Overdue"></i>':'')+'</td>';
            h+='<td style="font-size:12px;color:var(--t3);">'+esc(d.due_date||'—')+'</td>';
            h+='</tr>';

            tOrig+=parseFloat(d.original_amount||0); tDisc+=parseFloat(d.discount_amount||0);
            tNet+=parseFloat(d.net_amount||0); tFine+=fine;
            tPaid+=parseFloat(d.paid_amount||0); tBal+=parseFloat(d.balance||0);
        });
        if(!h) h='<tr><td colspan="11" class="fd-empty">No demands match filter</td></tr>';
        $tb.html(h);
        $('#ledgerFoot').show();
        $('#footOriginal').text('₹'+fmt(tOrig)); $('#footDiscount').text('₹'+fmt(tDisc));
        $('#footNet').text('₹'+fmt(tNet)); $('#footFine').text('₹'+fmt(tFine));
        $('#footPaid').text('₹'+fmt(tPaid)); $('#footBalance').text('₹'+fmt(tBal));
    }

    function filterTable(){ renderTable(demandsCache); }

    // ── Payment Allocation History ──
    function loadAllocations(sid){
        // Read all receipt allocations for this student
        $.getJSON(BASE+'fees/get_student_allocations?student_id='+encodeURIComponent(sid), function(r){
            $('#allocCard').show();
            var $tb=$('#allocBody');
            if(!r||r.status!=='success'||!r.receipts||!r.receipts.length){
                $tb.html('<tr><td colspan="9" class="fd-empty">No payment records</td></tr>');
                return;
            }
            var h='';
            r.receipts.forEach(function(rc){
                var allocHtml = '';
                if(rc.allocations && rc.allocations.length){
                    allocHtml = '<div class="fd-alloc-list">';
                    rc.allocations.forEach(function(a){
                        allocHtml += '<div class="fd-alloc-item">'+esc(a.period)+' — '+esc(a.fee_head)+': <strong>₹'+fmt(a.amount)+'</strong> <span class="fd-badge '+(a.new_status==='paid'?'fd-badge-green':'fd-badge-amber')+'">'+esc(a.new_status)+'</span></div>';
                    });
                    allocHtml += '</div>';
                } else { allocHtml = '<span style="color:var(--t3);">Legacy (no allocation data)</span>'; }
                var statusBadge = (rc.status||'active')==='reversed'?'fd-badge-red':'fd-badge-green';
                h+='<tr>';
                h+='<td><code>'+esc(rc.receipt_no||'—')+'</code></td>';
                h+='<td>'+esc(rc.date||'—')+'</td>';
                h+='<td class="fd-num">₹'+fmt(rc.total_amount)+'</td>';
                h+='<td class="fd-num">'+(parseFloat(rc.discount)>0?'₹'+fmt(rc.discount):'—')+'</td>';
                h+='<td class="fd-num">'+(parseFloat(rc.fine)>0?'₹'+fmt(rc.fine):'—')+'</td>';
                h+='<td class="fd-num">'+(parseFloat(rc.advance_credit)>0?'₹'+fmt(rc.advance_credit):'—')+'</td>';
                h+='<td><span class="fd-mode-tag">'+esc(rc.payment_mode||'—')+'</span></td>';
                h+='<td>'+allocHtml+'</td>';
                h+='<td><span class="fd-badge '+statusBadge+'">'+esc(rc.status||'active')+'</span></td>';
                h+='</tr>';
            });
            $tb.html(h);
        });
    }

    // ── Actions ──
    function generateDemands(){
        if(!selectedStudent) return;
        if(!confirm('Generate fee demands for '+selectedStudent.name+'?\nThis will create obligations for all academic months.')) return;
        $.post(BASE+'fees/generate_demands_for_student', {
            student_id:selectedStudent.id,
            '<?=$this->security->get_csrf_token_name()?>':'<?=$this->security->get_csrf_hash()?>'
        }, function(r){
            r = typeof r==='string'?JSON.parse(r):r;
            alert(r.message||'Done');
            loadLedger();
        });
    }

    function computeFines(){
        if(!selectedStudent) return;
        $.post(BASE+'fees/auto_compute_fines', {
            student_id:selectedStudent.id,
            '<?=$this->security->get_csrf_token_name()?>':'<?=$this->security->get_csrf_hash()?>'
        }, function(r){
            r = typeof r==='string'?JSON.parse(r):r;
            alert(r.message||'Done');
            loadLedger();
        });
    }

    window.FL = { loadLedger:loadLedger, filterTable:filterTable, generateDemands:generateDemands, computeFines:computeFines };
});
</script>

<style>
/* Reuse fd-* from dashboard + additions */
.fd-label { font-size:12px; font-weight:600; color:var(--t3,#7a9a8e); text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px; display:block; }
.fd-input { width:100%; height:40px; padding:0 14px; border:1.5px solid var(--border,#d1ddd8); border-radius:8px; font-size:14px; color:var(--t1); background:var(--bg2,#fff); outline:none; font-family:inherit; transition:border-color .14s; }
.fd-input:focus { border-color:var(--gold,#0f766e); box-shadow:0 0 0 3px rgba(15,118,110,.1); }
.fd-filter { height:34px; padding:0 28px 0 10px; border:1.5px solid var(--border); border-radius:8px; font-size:12px; color:var(--t1); background:var(--bg2); cursor:pointer; outline:none; appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10'%3E%3Cpath fill='%2364748b' d='M5 7L0 2h10z'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 9px center; }

/* Search dropdown */
.fd-search-dropdown { position:absolute; z-index:100; background:var(--bg2,#fff); border:1px solid var(--border); border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,.12); max-height:240px; overflow-y:auto; display:none; width:100%; margin-top:2px; }
.fd-dd-item { padding:10px 14px; cursor:pointer; font-size:13px; border-bottom:1px solid var(--border); }
.fd-dd-item:last-child { border:none; }
.fd-dd-item:hover { background:rgba(15,118,110,.06); }
.fd-dd-empty { padding:16px; text-align:center; color:var(--t3); font-size:13px; }

/* Badges */
.fd-badge { display:inline-block; padding:2px 10px; border-radius:12px; font-size:11px; font-weight:700; text-transform:capitalize; }
.fd-badge-green { background:rgba(22,163,74,.12); color:#16a34a; }
.fd-badge-amber { background:rgba(217,119,6,.12); color:#d97706; }
.fd-badge-red { background:rgba(220,38,38,.12); color:#dc2626; }

/* Allocation list */
.fd-alloc-list { font-size:12px; }
.fd-alloc-item { padding:2px 0; display:flex; align-items:center; gap:6px; flex-wrap:wrap; }

/* Search container needs relative positioning */
#ledgerSearch { position:relative; }
#ledgerSearch + .fd-search-dropdown { position:absolute; left:0; right:0; }
div:has(> #ledgerSearch) { position:relative; }
</style>
