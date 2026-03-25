<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>


<div class="content-wrapper">
    <div class="db-wrap">

        <!-- Top bar -->
        <div class="db-topbar">
            <div>
                <h1 class="db-page-title"><i class="fa fa-calendar-check-o"></i> Day Book</h1>
                <ul class="db-breadcrumb">
                    <li><a href="<?= base_url('admin') ?>"><i class="fa fa-home"></i> Home</a></li>
                    <li><a href="<?= base_url('account/account_book') ?>">Accounts</a></li>

                    <!-- <li>Accounts</li> -->
                    <li>Day Book</li>
                </ul>
            </div>
            <div class="db-session-badge">
                <span class="db-badge-label">Today's Date</span>
                <span class="db-badge-val" id="dbDateVal">—</span>
            </div>
        </div>

        <!-- Summary stat cards -->
        <div class="db-stats">
            <div class="db-stat-card">
                <div class="db-stat-label">Total Transactions</div>
                <div class="db-stat-val db-stat-tx" id="dbStatTx">0</div>
            </div>
            <div class="db-stat-card">
                <div class="db-stat-label">Total Credit</div>
                <div class="db-stat-val db-stat-cr" id="dbStatCr">₹0</div>
            </div>
            <div class="db-stat-card">
                <div class="db-stat-label">Total Debit</div>
                <div class="db-stat-val db-stat-dr" id="dbStatDr">₹0</div>
            </div>
        </div>

        <!-- Transactions card -->
        <div class="db-card">
            <div class="db-card-head">
                <i class="fa fa-table"></i>
                <h3>All Transactions — <span id="dbDateLabel" style="font-family:'DM Sans',sans-serif;font-size:13px;font-weight:600;color:var(--ab-teal)">Today</span></h3>
                <div class="db-card-head-right">
                    <span style="font-size:11px;color:var(--ab-muted);font-style:italic">Auto-loaded for today</span>
                </div>
            </div>

            <div class="db-tbl-outer">
                <table id="accountTable" class="db-tbl">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Particulars</th>
                            <th>Credit Amt</th>
                            <th>Debit Amt</th>
                            <th>Mode</th>
                        </tr>
                    </thead>
                    <tbody id="vouchersTableBody">
                        <!-- Populated by JS after AJAX; keep empty so DataTables doesn't error on colspan rows -->
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2"></td>
                            <td style="font-weight:700;color:rgba(255,255,255,.9)">Overall Total</td>
                            <td id="totalCrAmt">₹0</td>
                            <td id="totalDrAmt">₹0</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div><!-- /db-tbl-outer -->

            <!-- Loading / empty state shown outside the table so DataTables doesn't choke -->
            <div id="dbLoadingRow" class="db-empty">
                <i class="fa fa-spinner fa-spin"></i>
                <p>Loading today's transactions…</p>
            </div>

        </div><!-- /db-card -->

    </div>
</div>

<script>
/* Poll every 50ms until jQuery AND DataTables are both available */
(function waitForLibs(){
  if(typeof window.jQuery === 'undefined' || typeof window.jQuery.fn.DataTable === 'undefined'){
    return setTimeout(waitForLibs, 50);
  }
  var $ = window.jQuery;
  (function init(){

  /* ── Type badge ── */
  function typeBadge(t){
    if(!t) return '<span class="db-type-badge db-type-other">—</span>';
    var l = t.toLowerCase();
    var c = l==='payment'  ? 'db-type-payment'
          : (l==='received'||l==='receipt') ? 'db-type-receipt'
          : l==='journal'  ? 'db-type-journal'
          : l==='contra'   ? 'db-type-contra'
          : 'db-type-other';
    return '<span class="db-type-badge '+c+'">'+t+'</span>';
  }

  /* ── DT instance ── */
  var dtInstance = null;

  /* ── Fetch + render ── */
  function fetchAndDisplay(fromDate, toDate, accountType){
    /* Show loading state (outside the table) */
    document.getElementById('dbLoadingRow').style.display = 'block';
    if(dtInstance){ dtInstance.clear().draw(); }

    $.ajax({
      url:'<?= site_url("account/view_accounts") ?>',
      type:'POST',
      data:{ fromDate:fromDate, toDate:toDate, accountType:accountType },
      dataType:'json',
      success:function(resp){
        document.getElementById('dbLoadingRow').style.display = 'none';

        var data  = resp && resp.data ? resp.data : [];
        var totCr = 0, totDr = 0;
        var rows  = [];

        if(resp && resp.status === 'success' && data.length){
          data.forEach(function(v){
            var cr = parseFloat(v['Cr Amt'])||0;
            var dr = parseFloat(v['Dr Amt'])||0;
            totCr += cr; totDr += dr;
            rows.push([
              '<span style="font-size:12px;color:var(--ab-muted)">'+(v.Date||'—')+'</span>',
              typeBadge(v.Type),
              v.Particulars||'—',
              cr ? '<span class="db-cr">₹'+numberFormat(cr)+'</span>' : '—',
              dr ? '<span class="db-dr">₹'+numberFormat(dr)+'</span>' : '—',
              '<span class="db-mode">'+(v.Mode||'N/A')+'</span>'
            ]);
          });
        }

        document.getElementById('totalCrAmt').textContent = '₹'+numberFormat(totCr);
        document.getElementById('totalDrAmt').textContent = '₹'+numberFormat(totDr);
        document.getElementById('dbStatTx').textContent   = data.length;
        document.getElementById('dbStatCr').textContent   = '₹'+numberFormat(totCr);
        document.getElementById('dbStatDr').textContent   = '₹'+numberFormat(totDr);

        if(dtInstance){
          dtInstance.clear().rows.add(rows).draw();
        } else {
          dtInstance = $('#accountTable').DataTable({
            paging:true, searching:true, ordering:true, info:true,
            responsive:true, lengthMenu:[5,10,15,20], autoWidth:false,
            dom:'<"top"Bfl>rt<"bottom"ip>',
            buttons:[{extend:'pdfHtml5',orientation:'landscape',pageSize:'LEGAL'},'copy','csv','excel','print'],
            language:{ emptyTable:'No transactions found for today', zeroRecords:'No transactions recorded.' },
            order:[],
            data: rows,
            columns:[
              {title:'Date'}, {title:'Type'}, {title:'Particulars'},
              {title:'Credit Amt'}, {title:'Debit Amt'}, {title:'Mode'}
            ]
          });
        }
      },
      error:function(){
        document.getElementById('dbLoadingRow').style.display = 'none';
        console.error('Error fetching day book data.');
      }
    });
  }

  /* ── Fetch server date → load today ── */
  $.ajax({
    url:'<?= site_url("account/get_server_date") ?>',
    type:'GET', dataType:'json',
    success:function(r){
      if(!r || !r.date) return;
      var today = r.date; // DD-MM-YYYY
      var p = today.split('-');
      var displayDate = p[0]+'/'+p[1]+'/'+p[2];
      document.getElementById('dbDateVal').textContent   = displayDate;
      document.getElementById('dbDateLabel').textContent = 'Today — '+displayDate;
      fetchAndDisplay(today, today, 'All');
    },
    error:function(){ console.error('Could not fetch server date.'); }
  });

  })(); // end init
})(); // end waitForLibs
</script>



<style>
    @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap');

    :root {
        --ab-navy: #0b1f3a;
        --ab-teal: #0e7490;
        --ab-sky: #e0f2fe;
        --ab-green: #16a34a;
        --ab-red: #dc2626;
        --ab-amber: #d97706;
        --ab-text: #1e293b;
        --ab-muted: #64748b;
        --ab-border: #e2e8f0;
        --ab-white: #ffffff;
        --ab-bg: #f1f5f9;
        --ab-shadow: 0 1px 14px rgba(11, 31, 58, .08);
        --ab-radius: 12px;
    }

    *,
    *::before,
    *::after {
        box-sizing: border-box;
    }

    .db-wrap {
        font-family: 'DM Sans', sans-serif;
        background: var(--ab-bg);
        color: var(--ab-text);
        padding: 24px 20px 60px;
        min-height: 100%;
    }

    /* Top bar */
    .db-topbar {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 22px;
    }

    .db-page-title {
        font-family: 'Playfair Display', serif;
        font-size: 22px;
        font-weight: 700;
        color: var(--ab-navy);
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0 0 5px;
    }

    .db-page-title i {
        color: var(--ab-teal);
    }

    .db-breadcrumb {
        display: flex;
        align-items: center;
        list-style: none;
        margin: 0;
        padding: 0;
        font-size: 12.5px;
        color: var(--ab-muted);
    }

    .db-breadcrumb a {
        color: var(--ab-teal);
        text-decoration: none !important;
        font-weight: 500;
    }

    .db-breadcrumb li+li::before {
        content: '/';
        margin: 0 7px;
        color: #cbd5e1;
    }

    .db-session-badge {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 2px;
    }

    .db-badge-label {
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .6px;
        text-transform: uppercase;
        color: var(--ab-muted);
    }

    .db-badge-val {
        font-family: 'Playfair Display', serif;
        font-size: 17px;
        font-weight: 700;
        color: var(--ab-navy);
        line-height: 1;
    }

    /* Date info strip */
    .db-date-strip {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 16px;
        border-radius: 8px;
        background: var(--ab-sky);
        border: 1px solid #bae6fd;
        margin-bottom: 16px;
    }

    .db-date-strip i {
        color: var(--ab-teal);
        font-size: 15px;
    }

    .db-date-strip span {
        font-family: 'Playfair Display', serif;
        font-size: 15px;
        font-weight: 700;
        color: var(--ab-navy);
    }

    .db-date-strip small {
        font-size: 11.5px;
        color: var(--ab-muted);
        margin-left: 6px;
    }

    /* Card */
    .db-card {
        background: var(--ab-white);
        border-radius: var(--ab-radius);
        box-shadow: var(--ab-shadow);
        border: 1px solid var(--ab-border);
        overflow: hidden;
        margin-bottom: 16px;
    }

    .db-card-head {
        display: flex;
        align-items: center;
        gap: 9px;
        padding: 13px 18px;
        border-bottom: 1px solid var(--ab-border);
        background: linear-gradient(90deg, var(--ab-sky) 0%, var(--ab-white) 100%);
    }

    .db-card-head i {
        color: var(--ab-teal);
        font-size: 14px;
    }

    .db-card-head h3 {
        font-family: 'Playfair Display', serif;
        font-size: 14.5px;
        font-weight: 700;
        color: var(--ab-navy);
        margin: 0;
    }

    .db-card-head-right {
        margin-left: auto;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    /* Table */
    .db-tbl-outer {
        overflow-x: auto;
    }

    table.db-tbl {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
        font-family: 'DM Sans', sans-serif;
    }

    table.db-tbl thead th {
        background: var(--ab-navy);
        color: rgba(255, 255, 255, .88);
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .5px;
        text-transform: uppercase;
        padding: 11px 14px;
        white-space: nowrap;
    }

    table.db-tbl thead th:first-child {
        border-radius: 8px 0 0 0;
    }

    table.db-tbl thead th:last-child {
        border-radius: 0 8px 0 0;
    }

    table.db-tbl tbody tr {
        border-bottom: 1px solid var(--ab-border);
        background: var(--ab-white);
        transition: background .1s;
    }

    table.db-tbl tbody tr:last-child {
        border-bottom: none;
    }

    table.db-tbl tbody tr:hover {
        background: var(--ab-sky);
    }

    table.db-tbl tbody td {
        padding: 10px 14px;
        color: var(--ab-text);
        vertical-align: middle;
    }

    /* Footer totals row */
    table.db-tbl tfoot tr {
        background: var(--ab-navy);
    }

    table.db-tbl tfoot td {
        padding: 10px 14px;
        font-size: 13px;
        font-weight: 700;
        color: rgba(255, 255, 255, .9);
        background: var(--ab-navy) !important;
    }

    table.db-tbl tfoot td:first-child {
        border-radius: 0 0 0 0;
    }

    table.db-tbl tfoot td:last-child {
        border-radius: 0;
    }

    #totalCrAmt {
        color: #86efac !important;
        font-variant-numeric: tabular-nums;
    }

    #totalDrAmt {
        color: #fca5a5 !important;
        font-variant-numeric: tabular-nums;
    }

    /* Type badge */
    .db-type-badge {
        display: inline-flex;
        align-items: center;
        padding: 2px 9px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
    }

    .db-type-payment {
        background: #fef2f2;
        color: var(--ab-red);
        border: 1px solid #fecaca;
    }

    .db-type-receipt {
        background: #f0fdf4;
        color: var(--ab-green);
        border: 1px solid #bbf7d0;
    }

    .db-type-journal {
        background: #f0f9ff;
        color: var(--ab-teal);
        border: 1px solid #bae6fd;
    }

    .db-type-contra {
        background: #fefce8;
        color: var(--ab-amber);
        border: 1px solid #fde68a;
    }

    .db-type-other {
        background: var(--ab-bg);
        color: var(--ab-muted);
        border: 1px solid var(--ab-border);
    }

    .db-cr {
        color: var(--ab-green);
        font-variant-numeric: tabular-nums;
        font-weight: 600;
    }

    .db-dr {
        color: var(--ab-red);
        font-variant-numeric: tabular-nums;
        font-weight: 600;
    }

    .db-mode {
        font-size: 12px;
        color: var(--ab-muted);
    }

    /* Empty state */
    .db-empty {
        padding: 52px 20px;
        text-align: center;
        background: var(--ab-white);
    }

    .db-empty i {
        font-size: 28px;
        color: var(--ab-muted);
        opacity: .35;
        display: block;
        margin-bottom: 10px;
    }

    .db-empty p {
        font-size: 13px;
        color: var(--ab-muted);
        margin: 0;
    }

    /* Stat summary strip */
    .db-stats {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 16px;
    }

    .db-stat-card {
        flex: 1;
        min-width: 140px;
        background: var(--ab-white);
        border-radius: 10px;
        border: 1px solid var(--ab-border);
        box-shadow: var(--ab-shadow);
        padding: 12px 16px;
    }

    .db-stat-label {
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: var(--ab-muted);
        margin-bottom: 4px;
    }

    .db-stat-val {
        font-family: 'Playfair Display', serif;
        font-size: 18px;
        font-weight: 700;
    }

    .db-stat-cr {
        color: var(--ab-green);
    }

    .db-stat-dr {
        color: var(--ab-red);
    }

    .db-stat-tx {
        color: var(--ab-navy);
    }

    /* DataTables chrome */
    .db-wrap .dataTables_wrapper {
        padding: 13px 16px 16px;
        background: var(--ab-white);
    }

    .db-wrap .dataTables_wrapper .dataTables_filter input,
    .db-wrap .dataTables_wrapper .dataTables_length select {
        background: #fafcff !important;
        border: 1.5px solid var(--ab-border) !important;
        color: var(--ab-text) !important;
        border-radius: 7px !important;
        padding: 4px 10px !important;
    }

    .db-wrap .dataTables_wrapper .dataTables_info,
    .db-wrap .dataTables_wrapper .dataTables_length,
    .db-wrap .dataTables_wrapper .dataTables_filter {
        color: var(--ab-muted) !important;
        font-size: 12px !important;
    }

    .db-wrap .dataTables_wrapper .dataTables_paginate .paginate_button {
        border-radius: 7px !important;
        color: var(--ab-muted) !important;
        font-size: 12px !important;
        border: none !important;
        background: transparent !important;
    }

    .db-wrap .dataTables_wrapper .dataTables_paginate .paginate_button.current {
        background: var(--ab-teal) !important;
        color: #fff !important;
        border: none !important;
    }

    .db-wrap .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
        background: var(--ab-sky) !important;
        color: var(--ab-teal) !important;
    }

    .db-wrap div.dt-buttons .dt-button {
        background: var(--ab-white) !important;
        color: var(--ab-text) !important;
        border: 1.5px solid var(--ab-border) !important;
        border-radius: 7px !important;
        font-size: 11.5px !important;
        padding: 5px 12px !important;
    }

    .db-wrap div.dt-buttons .dt-button:hover {
        background: var(--ab-sky) !important;
        border-color: var(--ab-teal) !important;
        color: var(--ab-teal) !important;
    }

    @media print {

        .db-topbar,
        .db-stats,
        .db-toast-wrap {
            display: none !important;
        }

        .db-card {
            box-shadow: none;
        }
    }
</style>