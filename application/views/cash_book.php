<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>


<div class="content-wrapper">
<div class="cb-wrap">

    <!-- ══ TOP BAR ══ -->
    <div class="cb-topbar">
        <div>
            <h1 class="cb-page-title"><i class="fa fa-book"></i> Cash Book</h1>
            <ul class="cb-breadcrumb">
                <li><a href="<?= base_url('admin/index') ?>"><i class="fa fa-home"></i> Dashboard</a></li>
                <li><a href="<?= base_url('account/account_book') ?>">Accounts</a></li>

                <!-- <li>Accounts</li> -->
                <li>Cash Book</li>
            </ul>
        </div>
        <div class="cb-session-badge">
            <span class="cb-badge-label">Session</span>
            <span class="cb-badge-val"><?= htmlspecialchars($session_year ?? date('Y') . '-' . (date('Y') + 1)) ?></span>
        </div>
    </div>

    <!-- ══ DRILL-DOWN TRAIL ══ -->
    <div class="cb-nav-trail" id="cbTrail">
        <span class="cb-nav-crumb active" id="trailAccounts"><i class="fa fa-list"></i> All Accounts</span>
        <span class="cb-nav-sep" id="trailSep1"   style="display:none">›</span>
        <span class="cb-nav-crumb" id="trailMonths" style="display:none"><i class="fa fa-calendar"></i> <span id="trailAccName"></span></span>
        <span class="cb-nav-sep" id="trailSep2"   style="display:none">›</span>
        <span class="cb-nav-crumb" id="trailDates"  style="display:none"><i class="fa fa-calendar-o"></i> <span id="trailMonthName"></span></span>
        <span class="cb-nav-sep" id="trailSep3"   style="display:none">›</span>
        <span class="cb-nav-crumb" id="trailDetail" style="display:none"><i class="fa fa-list-ul"></i> <span id="trailDateName"></span></span>
    </div>

    <!-- ══ LEVEL 1 — Account Balances ══ -->
    <div id="cbAccounts" class="cb-card">
        <div class="cb-card-head">
            <i class="fa fa-university"></i>
            <h3>Account Balances</h3>
            <div class="cb-card-head-right">
                <span style="font-size:11.5px;color:var(--cb-muted)">
                    Click a row · Double-click or press <strong>Show</strong> to drill in
                </span>
            </div>
        </div>
        <div class="cb-tbl-outer">
            <table id="cashBookTable" class="cb-tbl">
                <thead>
                    <tr>
                        <th>Sr.</th>
                        <th>Account Name</th>
                        <th>Opening Balance</th>
                        <th>Total Received</th>
                        <th>Total Payment</th>
                        <th>Current Balance</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                function formatIndianNumber($num) {
                    $num = str_replace(',', '', $num);
                    $num = (float)$num;
                    $isNeg = $num < 0;
                    $num = abs($num);
                    $dec = number_format($num, 2, '.', '');
                    $parts = explode('.', $dec);
                    $int = $parts[0]; $frac = isset($parts[1]) ? '.' . $parts[1] : '';
                    $len = strlen($int);
                    if ($len > 3) {
                        $last3 = substr($int, -3);
                        $rem   = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($int, 0, $len - 3));
                        $fmt   = $rem . ',' . $last3 . $frac;
                    } else {
                        $fmt = $int . $frac;
                    }
                    return $isNeg ? '−' . $fmt : $fmt;
                }
                ?>
                <?php if (!empty($accounts)): $srNo = 1; ?>
                <?php foreach ($accounts as $account): ?>
                <?php $bal = floatval(str_replace(',', '', $account['Current Balance'] ?? 0)); ?>
                <tr data-account="<?= htmlspecialchars($account['Account Name']) ?>">
                    <td style="color:var(--cb-muted);font-size:12px"><?= $srNo++ ?></td>
                    <td><?= htmlspecialchars($account['Account Name']) ?></td>
                    <td class="cb-open">₹ <?= formatIndianNumber($account['Opening Balance']) ?></td>
                    <td class="cb-recv">₹ <?= formatIndianNumber($account['Total Received']) ?></td>
                    <td class="cb-pay" >₹ <?= formatIndianNumber($account['Total Payment']) ?></td>
                    <td class="<?= $bal >= 0 ? 'cb-bal-positive' : 'cb-bal-negative' ?>">
                        ₹ <?= formatIndianNumber($account['Current Balance']) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php else: ?>
                <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--cb-muted)">No accounts found</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="cb-action-bar">
            <span style="font-size:12px;color:var(--cb-muted)">
                <i class="fa fa-info-circle"></i> Select a row, then click <strong>Show Month-Wise</strong>
            </span>
            <div class="cb-action-right">
                <button class="cb-btn cb-btn-teal" id="showMonthBtn" disabled>
                    <i class="fa fa-calendar"></i> Show Month-Wise
                </button>
            </div>
        </div>
    </div>

    <!-- ══ LEVEL 2 — Month-Wise ══ -->
    <div id="cbMonths" class="cb-card" style="display:none">
        <div class="cb-card-head">
            <i class="fa fa-calendar"></i>
            <h3>Month-Wise <span class="cb-card-sub" id="monthCardSub"></span></h3>
            <div class="cb-card-head-right">
                <span style="font-size:11.5px;color:var(--cb-muted)">Click a row to select · Double-click or <strong>View</strong> to drill in</span>
            </div>
        </div>
        <div class="cb-tbl-outer">
            <table class="cb-tbl">
                <thead>
                    <tr><th>Month</th><th>Opening</th><th>Received</th><th>Payment</th><th>Balance</th></tr>
                </thead>
                <tbody id="monthCashBookTableBody"></tbody>
                <tfoot>
                    <tr>
                        <td><strong>Total</strong></td><td></td>
                        <td class="cb-tfoot-recv" id="monthCashBookTableBodytotalReceived"></td>
                        <td class="cb-tfoot-pay"  id="monthCashBookTableBodytotalPayments"></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="cb-action-bar">
            <button class="cb-btn cb-btn-ghost cb-btn-sm" id="backBtnMonth">
                <i class="fa fa-arrow-left"></i> Back to Accounts
            </button>
            <div class="cb-action-right">
                <button class="cb-btn cb-btn-teal" id="viewbtn" disabled>
                    <i class="fa fa-eye"></i> View Date-Wise
                </button>
            </div>
        </div>
    </div>

    <!-- ══ LEVEL 3 — Date-Wise ══ -->
    <div id="cbDates" class="cb-card" style="display:none">
        <div class="cb-card-head">
            <i class="fa fa-calendar-o"></i>
            <h3>Date-Wise <span class="cb-card-sub" id="dateCardSub"></span></h3>
            <div class="cb-card-head-right">
                <span style="font-size:11.5px;color:var(--cb-muted)">Double-click a date to see transactions</span>
            </div>
        </div>
        <div class="cb-tbl-outer">
            <table class="cb-tbl">
                <thead>
                    <tr><th>Date</th><th>Opening</th><th>Received</th><th>Payment</th><th>Balance</th></tr>
                </thead>
                <tbody id="DateCashBookTableBody"></tbody>
                <tfoot>
                    <tr>
                        <td><strong>Total</strong></td><td></td>
                        <td class="cb-tfoot-recv" id="DateCashBookTableBodytotalReceived"></td>
                        <td class="cb-tfoot-pay"  id="DateCashBookTableBodytotalPayments"></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="cb-action-bar">
            <button class="cb-btn cb-btn-ghost cb-btn-sm" id="backBtndate">
                <i class="fa fa-arrow-left"></i> Back to Months
            </button>
        </div>
    </div>

    <!-- ══ LEVEL 4 — Day Detail ══ -->
    <div id="cbDetail" class="cb-card" style="display:none">
        <div class="cb-card-head">
            <i class="fa fa-list-ul"></i>
            <h3>Day Transactions <span class="cb-card-sub" id="detailCardSub"></span></h3>
        </div>
        <div class="cb-tbl-outer">
            <table id="detailCashBookTable" class="cb-tbl">
                <thead>
                    <tr><th>Date</th><th>Account</th><th>Received</th><th>Payment</th></tr>
                </thead>
                <tbody id="detailCashBookTableBody"></tbody>
                <tfoot>
                    <tr>
                        <td><strong>Total</strong></td><td></td>
                        <td class="cb-tfoot-recv" id="detailCashBookTableBodytotalReceived"></td>
                        <td class="cb-tfoot-pay"  id="detailCashBookTableBodytotalPayments"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="cb-ref-row">
            <span class="cb-ref-label">Reference :</span>
            <input type="text" id="reference" class="cb-ref-input" placeholder="Click a row to see reference" readonly>
        </div>
        <div class="cb-action-bar">
            <button class="cb-btn cb-btn-ghost cb-btn-sm" id="backBtndetail">
                <i class="fa fa-arrow-left"></i> Back to Dates
            </button>
            <div class="cb-action-right">
                <button class="cb-btn cb-btn-teal cb-btn-sm" onclick="window.print()">
                    <i class="fa fa-print"></i> Print
                </button>
            </div>
        </div>
    </div>

</div><!-- /.cb-wrap -->
</div>

<script>
/*
 * cash_book.php — JS
 * BUG FIXES vs previous version:
 *   BUG 1  FIX: Use DOMContentLoaded — guaranteed after footer jQuery/DataTables load
 *   BUG 3  FIX: Read account name via attr('data-account') not .data('account')
 *                .data() cache is lost when DataTable rewrites DOM
 *   BUG 5  FIX: Removed single-click 'tr td' handler that fired loadDetail() on every click
 *                Only dblclick (and back-compat viewbtn) triggers loadDetail
 *   BUG 13 FIX: :root CSS variables now defined above — page works standalone
 */

document.addEventListener('DOMContentLoaded', function () {
    /* BUG 1 FIX: DOMContentLoaded fires after footer scripts (jQuery + DataTables) load,
       so jQuery and DataTables are guaranteed available here. No polling needed. */
    var $ = window.jQuery;

    /* ── Shared state ── */
    var currentAccount = null;   // account name string
    var currentMonth   = null;   // month string
    var currentOpening = null;   // opening value for that month

    /* ── CI base URL for AJAX ── */
    var SITE = '<?= rtrim(site_url(), '/') ?>';

    /* ── Helpers ── */
    function fmtAmt(n) {
        n = parseFloat(String(n).replace(/[₹,]/g, '')) || 0;
        // Indian number format
        var isNeg = n < 0;
        n = Math.abs(n);
        var parts = n.toFixed(2).split('.');
        var int   = parts[0], frac = '.' + parts[1];
        if (int.length > 3) {
            var last3 = int.slice(-3);
            var rem   = int.slice(0, int.length - 3).replace(/\B(?=(\d{2})+(?!\d))/g, ',');
            int = rem + ',' + last3;
        }
        return (isNeg ? '₹ −' : '₹ ') + int + frac;
    }

    function parseAmt(str) {
        return parseFloat(String(str).replace(/[₹,−\s]/g, '').replace('−','')) || 0;
    }

    function calculateTotals(bodyId) {
        var totR = 0, totP = 0;
        document.querySelectorAll('#' + bodyId + ' tr').forEach(function (row) {
            totR += parseAmt(row.cells[2] ? row.cells[2].innerText : '0');
            totP += parseAmt(row.cells[3] ? row.cells[3].innerText : '0');
        });
        var rEl = document.getElementById(bodyId + 'totalReceived');
        var pEl = document.getElementById(bodyId + 'totalPayments');
        if (rEl) rEl.textContent = fmtAmt(totR);
        if (pEl) pEl.textContent = fmtAmt(totP);
    }

    /* ── Panel switcher ── */
    var PANELS = ['cbAccounts', 'cbMonths', 'cbDates', 'cbDetail'];
    function showPanel(id) {
        PANELS.forEach(function (p) { document.getElementById(p).style.display = 'none'; });
        document.getElementById(id).style.display = '';
    }

    /* ── Trail ── */
    function showTrail(level, accName, monthName, dateName) {
        var show = {
            trailAccounts: true,
            trailSep1:     level >= 2,
            trailMonths:   level >= 2,
            trailSep2:     level >= 3,
            trailDates:    level >= 3,
            trailSep3:     level >= 4,
            trailDetail:   level >= 4
        };
        Object.keys(show).forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.style.display = show[id] ? '' : 'none';
        });
        ['trailAccounts','trailMonths','trailDates','trailDetail'].forEach(function (id, i) {
            var el = document.getElementById(id);
            if (el) el.classList.toggle('active', i + 1 === level);
        });
        if (accName   !== undefined) document.getElementById('trailAccName').textContent   = accName;
        if (monthName !== undefined) document.getElementById('trailMonthName').textContent = monthName;
        if (dateName  !== undefined) document.getElementById('trailDateName').textContent  = dateName;
    }

    /* ══════════════════════════════════════════════════════
       LEVEL 1 — Account table (DataTables)
    ══════════════════════════════════════════════════════ */
    var accountDT = $('#cashBookTable').DataTable({
        paging:     true,
        searching:  true,
        ordering:   true,
        info:       true,
        responsive: true,
        pageLength: 15,
        autoWidth:  false,
        dom:     '<"cb-dt-toolbar"Bf>rt<"cb-dt-footer"ip>',
        buttons: [
            { extend: 'pdfHtml5', text: '<i class="fa fa-file-pdf-o"></i> PDF',   orientation: 'landscape', pageSize: 'LEGAL' },
            { extend: 'copy',     text: '<i class="fa fa-copy"></i> Copy'    },
            { extend: 'csv',      text: '<i class="fa fa-file-text-o"></i> CSV'   },
            { extend: 'excel',    text: '<i class="fa fa-file-excel-o"></i> Excel' },
            { extend: 'print',    text: '<i class="fa fa-print"></i> Print'  }
        ],
        language: { emptyTable: 'No accounts found', search: 'Search:' },
        order: []
    });

    /* BUG 3 FIX: use attr('data-account') NOT .data('account').
       DataTable rewrites DOM nodes — jQuery .data() cache is tied to the original
       element reference which is gone after DataTable clones rows.
       .attr() reads the actual HTML attribute which always survives. */
    $('#cashBookTable tbody').on('click', 'tr', function () {
        $('#cashBookTable tbody tr').removeClass('cb-selected');
        $(this).addClass('cb-selected');
        currentAccount = $(this).attr('data-account') || $(this).find('td:eq(1)').text().trim();
        $('#showMonthBtn').prop('disabled', !currentAccount);
    });

    /* Double-click on account row → directly trigger Show */
    $('#cashBookTable tbody').on('dblclick', 'tr', function () {
        if (!$('#showMonthBtn').prop('disabled')) {
            $('#showMonthBtn').trigger('click');
        }
    });

    /* ── Show Month-Wise button ── */
    $('#showMonthBtn').on('click', function () {
        if (!currentAccount) { return; }
        var $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fa fa-spinner cb-spin"></i> Loading…');

        $.ajax({
            url:  SITE + '/account/cash_book_month',
            type: 'GET',
            data: { account_name: currentAccount },
            success: function (resp) {
                $btn.prop('disabled', false).html('<i class="fa fa-calendar"></i> Show Month-Wise');
                var result = typeof resp === 'string' ? JSON.parse(resp) : resp;

                if (result.status !== 'success') {
                    alert(result.message || 'Error fetching month data');
                    return;
                }

                var body = '';
                result.data.forEach(function (row) {
                    body += '<tr data-month="' + row.month + '" data-opening="' + row.opening + '">' +
                        '<td><strong>' + row.month + '</strong></td>' +
                        '<td class="cb-open">' + fmtAmt(row.opening)   + '</td>' +
                        '<td class="cb-recv">' + fmtAmt(row.received)  + '</td>' +
                        '<td class="cb-pay">'  + fmtAmt(row.payments)  + '</td>' +
                        '<td class="cb-bal">'  + fmtAmt(row.balance)   + '</td>' +
                        '</tr>';
                });
                $('#monthCashBookTableBody').html(body);
                calculateTotals('monthCashBookTableBody');
                document.getElementById('monthCardSub').textContent = ' — ' + currentAccount;
                $('#viewbtn').prop('disabled', true);   // reset until a month row is clicked

                showPanel('cbMonths');
                showTrail(2, currentAccount);
            },
            error: function () {
                $btn.prop('disabled', false).html('<i class="fa fa-calendar"></i> Show Month-Wise');
                alert('An error occurred while fetching month data.');
            }
        });
    });

    /* ══════════════════════════════════════════════════════
       LEVEL 2 — Month table
    ══════════════════════════════════════════════════════ */

    /* Single click → select row + enable View button */
    $('#monthCashBookTableBody').on('click', 'tr', function () {
        $('#monthCashBookTableBody tr').removeClass('cb-selected');
        $(this).addClass('cb-selected');
        /* BUG 3 FIX pattern: read from data-* attr, not .data() */
        currentMonth   = $(this).attr('data-month')   || $(this).find('td:first').text().trim();
        currentOpening = $(this).attr('data-opening') || $(this).find('td:eq(1)').text().replace('₹','').trim();
        $('#viewbtn').prop('disabled', false);
    });

    /* Double-click → load dates */
    $('#monthCashBookTableBody').on('dblclick', 'tr', function () {
        loadDates($(this));
    });

    /* View Date-Wise button */
    $('#viewbtn').on('click', function () {
        var sel = $('#monthCashBookTableBody tr.cb-selected');
        if (sel.length) loadDates(sel);
    });

    function loadDates($row) {
        currentMonth   = $row.attr('data-month')   || $row.find('td:first').text().trim();
        currentOpening = $row.attr('data-opening') || $row.find('td:eq(1)').text().replace('₹','').trim();

        $.ajax({
            url:  SITE + '/account/cash_book_dates',
            type: 'POST',
            data: {
                month:        currentMonth,
                opening:      currentOpening,
                account_name: currentAccount      // currentAccount always set from attr() above
            },
            success: function (resp) {
                var result = typeof resp === 'string' ? JSON.parse(resp) : resp;
                if (result.status !== 'success' || !Array.isArray(result.data)) {
                    console.error('Unexpected cash_book_dates response:', result);
                    return;
                }

                var body = '';
                result.data.forEach(function (item) {
                    body += '<tr data-date="' + item.date + '">' +
                        '<td><strong>' + item.date + '</strong></td>' +
                        '<td class="cb-open">' + fmtAmt(item.opening)  + '</td>' +
                        '<td class="cb-recv">' + fmtAmt(item.received) + '</td>' +
                        '<td class="cb-pay">'  + fmtAmt(item.payments) + '</td>' +
                        '<td class="cb-bal">'  + fmtAmt(item.balance)  + '</td>' +
                        '</tr>';
                });
                $('#DateCashBookTableBody').html(body);
                calculateTotals('DateCashBookTableBody');
                document.getElementById('dateCardSub').textContent = ' — ' + currentMonth;

                showPanel('cbDates');
                showTrail(3, currentAccount, currentMonth);
            },
            error: function () {
                alert('An error occurred while fetching date data.');
            }
        });
    }

    /* ══════════════════════════════════════════════════════
       LEVEL 3 — Date table
       BUG 5 FIX: single-click = SELECT ONLY (no AJAX).
                  dblclick = load detail (matches original behaviour).
                  Removed the erroneous 'tr td' click handler that
                  fired loadDetail() on every single click.
    ══════════════════════════════════════════════════════ */
    $('#DateCashBookTableBody').on('click', 'tr', function () {
        $('#DateCashBookTableBody tr').removeClass('cb-selected');
        $(this).addClass('cb-selected');
        /* single click = select only, NO loadDetail here */
    });

    $('#DateCashBookTableBody').on('dblclick', 'tr', function () {
        var date = $(this).attr('data-date') || $(this).find('td:first').text().trim();
        loadDetail(date);
    });

    function loadDetail(date) {
        $.ajax({
            url:  SITE + '/account/cash_book_details',
            type: 'POST',
            data: { date: date },
            success: function (resp) {
                var data = typeof resp === 'string' ? JSON.parse(resp) : resp;
                var $tbody = $('#detailCashBookTableBody').empty();

                data.forEach(function (item) {
                    var $tr = $('<tr data-reference="' + (item.reference || '') + '"></tr>');
                    $tr.html(
                        '<td style="font-size:12px;color:var(--cb-muted)">' + item.date    + '</td>' +
                        '<td>' + item.account + '</td>' +
                        '<td class="cb-recv">' + fmtAmt(item.received) + '</td>' +
                        '<td class="cb-pay">'  + fmtAmt(item.payment)  + '</td>'
                    );
                    $tr.on('click', function () {
                        $(this).addClass('cb-selected').siblings().removeClass('cb-selected');
                        document.getElementById('reference').value =
                            $(this).attr('data-reference') || '';
                    });
                    $tbody.append($tr);
                });

                calculateTotals('detailCashBookTableBody');
                document.getElementById('detailCardSub').textContent = ' — ' + date;
                document.getElementById('reference').value = '';

                /* Re-init DataTable */
                if ($.fn.DataTable.isDataTable('#detailCashBookTable')) {
                    $('#detailCashBookTable').DataTable().destroy();
                }
                $('#detailCashBookTable').DataTable({
                    paging:     true,
                    searching:  true,
                    ordering:   true,
                    info:       true,
                    responsive: true,
                    pageLength: 10,
                    autoWidth:  false,
                    dom:     '<"cb-dt-toolbar"Bf>rt<"cb-dt-footer"ip>',
                    buttons: [
                        { extend: 'pdfHtml5', text: '<i class="fa fa-file-pdf-o"></i> PDF',    orientation: 'landscape', pageSize: 'LEGAL' },
                        { extend: 'copy',     text: '<i class="fa fa-copy"></i> Copy'     },
                        { extend: 'csv',      text: '<i class="fa fa-file-text-o"></i> CSV'    },
                        { extend: 'excel',    text: '<i class="fa fa-file-excel-o"></i> Excel' },
                        { extend: 'print',    text: '<i class="fa fa-print"></i> Print'   }
                    ],
                    language: { emptyTable: 'No transactions found', search: 'Search:' },
                    order: []
                });

                showPanel('cbDetail');
                showTrail(4, currentAccount, currentMonth, date);
            },
            error: function () {
                alert('An error occurred while fetching transaction details.');
            }
        });
    }

    /* ══════════════════════════════════════════════════════
       BACK BUTTONS
    ══════════════════════════════════════════════════════ */
    $('#backBtnMonth').on('click', function () {
        currentAccount = null;
        $('#cashBookTable tbody tr').removeClass('cb-selected');
        $('#showMonthBtn').prop('disabled', true);
        showPanel('cbAccounts');
        showTrail(1);
    });

    $('#backBtndate').on('click', function () {
        $('#viewbtn').prop('disabled', true);
        showPanel('cbMonths');
        showTrail(2, currentAccount);
    });

    $('#backBtndetail').on('click', function () {
        showPanel('cbDates');
        showTrail(3, currentAccount, currentMonth);
    });

    /* ══════════════════════════════════════════════════════
       TRAIL CLICK NAVIGATION
    ══════════════════════════════════════════════════════ */
    $('#trailAccounts').on('click', function () {
        if (!$(this).hasClass('active')) { $('#backBtnMonth').trigger('click'); }
    });
    $('#trailMonths').on('click', function () {
        if ($(this).hasClass('active')) return;
        if ($('#cbDetail').is(':visible'))      { $('#backBtndetail').trigger('click'); }
        else if ($('#cbDates').is(':visible'))  { $('#backBtndate').trigger('click'); }
    });
    $('#trailDates').on('click', function () {
        if (!$(this).hasClass('active') && $('#cbDetail').is(':visible')) {
            $('#backBtndetail').trigger('click');
        }
    });

}); /* end DOMContentLoaded */
</script>

<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap');

/* ══ CSS VARIABLES — defined here so page works standalone ══ */
/* BUG 13 FIX: was missing entirely — all var(--ab-*) resolved to empty */
:root {
    --cb-navy:   #0b1f3a;
    --cb-teal:   #0e7490;
    --cb-sky:    #e0f2fe;
    --cb-green:  #16a34a;
    --cb-red:    #dc2626;
    --cb-amber:  #d97706;
    --cb-text:   #1e293b;
    --cb-muted:  #64748b;
    --cb-border: #e2e8f0;
    --cb-white:  #ffffff;
    --cb-bg:     #f1f5f9;
    --cb-shadow: 0 1px 14px rgba(11,31,58,.08);
    --cb-radius: 12px;
}
* { box-sizing: border-box; }

/* ══ SHELL ══ */
.cb-wrap {
    font-family: 'DM Sans', sans-serif;
    background: var(--cb-bg);
    color: var(--cb-text);
    padding: 24px 20px 60px;
    min-height: 100vh;
}

/* ══ TOP BAR ══ */
.cb-topbar {
    display: flex; align-items: flex-start;
    justify-content: space-between; flex-wrap: wrap;
    gap: 12px; margin-bottom: 18px;
}
.cb-page-title {
    font-family: 'Playfair Display', serif;
    font-size: 24px; font-weight: 700; color: var(--cb-navy);
    display: flex; align-items: center; gap: 10px; margin: 0 0 5px;
}
.cb-page-title i { color: var(--cb-teal); }
.cb-breadcrumb {
    display: flex; align-items: center; list-style: none;
    margin: 0; padding: 0; font-size: 12.5px; color: var(--cb-muted);
}
.cb-breadcrumb a { color: var(--cb-teal); text-decoration: none; font-weight: 500; }
.cb-breadcrumb li + li::before { content: '/'; margin: 0 7px; color: #cbd5e1; }
.cb-session-badge { display: flex; flex-direction: column; align-items: flex-end; gap: 2px; }
.cb-badge-label {
    font-size: 10px; font-weight: 700; letter-spacing: .6px;
    text-transform: uppercase; color: var(--cb-muted);
}
.cb-badge-val {
    font-family: 'Playfair Display', serif;
    font-size: 18px; font-weight: 700; color: var(--cb-navy); line-height: 1;
}

/* ══ DRILL-DOWN TRAIL ══ */
.cb-nav-trail {
    display: flex; align-items: center; gap: 4px;
    background: var(--cb-white); border: 1px solid var(--cb-border);
    border-radius: 8px; padding: 8px 14px;
    margin-bottom: 16px; font-size: 12.5px;
    box-shadow: var(--cb-shadow);
}
.cb-nav-crumb {
    padding: 4px 10px; border-radius: 6px;
    color: var(--cb-muted); cursor: pointer; font-weight: 500;
    transition: background .12s, color .12s;
}
.cb-nav-crumb:hover  { background: var(--cb-sky); color: var(--cb-teal); }
.cb-nav-crumb.active { background: var(--cb-teal); color: #fff; cursor: default; }
.cb-nav-sep { color: #cbd5e1; font-size: 14px; user-select: none; }

/* ══ CARD ══ */
.cb-card {
    background: var(--cb-white);
    border-radius: var(--cb-radius);
    box-shadow: var(--cb-shadow);
    border: 1px solid var(--cb-border);
    overflow: hidden; margin-bottom: 18px;
}
.cb-card-head {
    display: flex; align-items: center; gap: 9px;
    padding: 13px 18px; border-bottom: 1px solid var(--cb-border);
    background: linear-gradient(90deg, var(--cb-sky) 0%, var(--cb-white) 100%);
}
.cb-card-head i { color: var(--cb-teal); font-size: 15px; }
.cb-card-head h3 {
    font-family: 'Playfair Display', serif;
    font-size: 15px; font-weight: 700; color: var(--cb-navy); margin: 0;
}
.cb-card-sub { font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 500; color: var(--cb-muted); }
.cb-card-head-right { margin-left: auto; }

/* ══ TABLE ══ */
.cb-tbl-outer { overflow-x: auto; }
.cb-tbl {
    width: 100%; border-collapse: collapse; font-size: 13px;
}
.cb-tbl thead th {
    background: var(--cb-navy); color: rgba(255,255,255,.85);
    font-size: 11px; font-weight: 700; letter-spacing: .5px;
    text-transform: uppercase; padding: 11px 14px; text-align: left;
    white-space: nowrap;
}
.cb-tbl tbody tr {
    border-bottom: 1px solid var(--cb-border);
    cursor: pointer; transition: background .1s;
}
.cb-tbl tbody tr:hover    { background: var(--cb-sky); }
.cb-tbl tbody tr.cb-selected {
    background: linear-gradient(90deg, var(--cb-sky) 0%, #bae6fd 100%);
    border-left: 3px solid var(--cb-teal);
}
.cb-tbl tbody td { padding: 10px 14px; }
.cb-tbl tfoot td {
    padding: 10px 14px; font-weight: 700;
    background: #f8fafc; border-top: 2px solid var(--cb-border);
}
.cb-open { color: var(--cb-muted); }
.cb-recv { color: var(--cb-green); font-weight: 600; }
.cb-pay  { color: var(--cb-red);   font-weight: 600; }
.cb-bal  { font-weight: 700; }
.cb-bal-positive { color: var(--cb-green); font-weight: 700; }
.cb-bal-negative { color: var(--cb-red);   font-weight: 700; }
.cb-tfoot-recv { color: var(--cb-green); font-weight: 700; }
.cb-tfoot-pay  { color: var(--cb-red);   font-weight: 700; }

/* ══ ACTION BAR ══ */
.cb-action-bar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 16px; background: #f8fafc;
    border-top: 1px solid var(--cb-border); gap: 10px; flex-wrap: wrap;
}
.cb-action-right { display: flex; gap: 8px; }

/* ══ BUTTONS ══ */
.cb-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 18px; border-radius: 8px; border: none;
    font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 600;
    cursor: pointer; transition: opacity .13s, transform .1s;
    white-space: nowrap;
}
.cb-btn:hover:not(:disabled) { opacity: .86; transform: translateY(-1px); }
.cb-btn:disabled { opacity: .4; cursor: not-allowed; transform: none; pointer-events: none; }
.cb-btn-teal  { background: var(--cb-teal);  color: #fff; }
.cb-btn-ghost {
    background: var(--cb-white); color: var(--cb-text);
    border: 1.5px solid var(--cb-border);
}
.cb-btn-sm { padding: 6px 14px; font-size: 12px; }

/* ══ REFERENCE ROW ══ */
.cb-ref-row {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 16px; border-top: 1px solid var(--cb-border);
    background: #fafcff;
}
.cb-ref-label { font-size: 12px; font-weight: 700; color: var(--cb-muted); white-space: nowrap; }
.cb-ref-input {
    flex: 1; height: 36px; padding: 0 10px;
    border: 1.5px solid var(--cb-border); border-radius: 8px;
    font-size: 13px; font-family: 'DM Sans', sans-serif;
    color: var(--cb-text); background: var(--cb-white); outline: none;
    transition: border-color .13s;
}
.cb-ref-input:focus { border-color: var(--cb-teal); }

/* ══ SPINNER ══ */
.cb-spin { animation: cb-spin .7s linear infinite; display: inline-block; }
@keyframes cb-spin { to { transform: rotate(360deg); } }

/* ══ DATATABLES OVERRIDES ══════════════════════════════════════
   Complete override of DataTables default/Bootstrap styles.
   dom string used: '<"cb-dt-toolbar"Bf>rt<"cb-dt-footer"ip>'
   We target our own class names for 100% control.
══════════════════════════════════════════════════════════════ */

/* ── Wrapper reset ── */
.dataTables_wrapper {
    font-family: 'DM Sans', sans-serif !important;
    color: var(--cb-text) !important;
    padding: 0 !important;
    position: relative;
}

/* ── Toolbar (buttons + search) — top of table inside card ── */
.cb-dt-toolbar {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    flex-wrap: wrap !important;
    gap: 10px !important;
    padding: 12px 16px !important;
    border-bottom: 1px solid var(--cb-border) !important;
    background: #f8fafc !important;
}

/* ── Export buttons group ── */
.cb-dt-toolbar .dt-buttons {
    display: flex !important;
    gap: 5px !important;
    flex-wrap: wrap !important;
}
.cb-dt-toolbar .dt-button {
    display: inline-flex !important;
    align-items: center !important;
    gap: 5px !important;
    padding: 6px 13px !important;
    border-radius: 7px !important;
    border: 1.5px solid var(--cb-border) !important;
    background: var(--cb-white) !important;
    color: var(--cb-text) !important;
    font-family: 'DM Sans', sans-serif !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    line-height: 1.5 !important;
    cursor: pointer !important;
    box-shadow: none !important;
    text-shadow: none !important;
    transition: all .12s !important;
    white-space: nowrap !important;
}
.cb-dt-toolbar .dt-button:hover {
    background: var(--cb-sky) !important;
    border-color: var(--cb-teal) !important;
    color: var(--cb-teal) !important;
    box-shadow: none !important;
}
.cb-dt-toolbar .dt-button:active,
.cb-dt-toolbar .dt-button:focus {
    outline: none !important;
    box-shadow: 0 0 0 3px rgba(14,116,144,.12) !important;
    background: var(--cb-sky) !important;
    border-color: var(--cb-teal) !important;
    color: var(--cb-teal) !important;
}
/* Per-button accent colours */
.cb-dt-toolbar .buttons-pdf   { border-color: #fca5a5 !important; color: #b91c1c !important; }
.cb-dt-toolbar .buttons-excel { border-color: #86efac !important; color: #15803d !important; }
.cb-dt-toolbar .buttons-csv   { border-color: #86efac !important; color: #15803d !important; }
.cb-dt-toolbar .buttons-print { border-color: #93c5fd !important; color: #1d4ed8 !important; }
.cb-dt-toolbar .buttons-pdf:hover   { background: #fff1f2 !important; }
.cb-dt-toolbar .buttons-excel:hover { background: #f0fdf4 !important; }
.cb-dt-toolbar .buttons-csv:hover   { background: #f0fdf4 !important; }
.cb-dt-toolbar .buttons-print:hover { background: #eff6ff !important; }

/* ── Search filter ── */
.cb-dt-toolbar .dataTables_filter {
    display: flex !important;
    align-items: center !important;
}
.cb-dt-toolbar .dataTables_filter label {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    margin: 0 !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    color: var(--cb-muted) !important;
    text-transform: uppercase !important;
    letter-spacing: .5px !important;
    white-space: nowrap !important;
}
.cb-dt-toolbar .dataTables_filter input[type="search"] {
    height: 34px !important;
    padding: 0 10px 0 34px !important;
    border: 1.5px solid var(--cb-border) !important;
    border-radius: 8px !important;
    font-size: 13px !important;
    font-family: 'DM Sans', sans-serif !important;
    color: var(--cb-text) !important;
    background:
        url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2.5'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'/%3E%3C/svg%3E")
        no-repeat 10px center,
        var(--cb-white) !important;
    outline: none !important;
    box-shadow: none !important;
    transition: border-color .13s, box-shadow .13s !important;
    min-width: 210px !important;
}
.cb-dt-toolbar .dataTables_filter input[type="search"]:focus {
    border-color: var(--cb-teal) !important;
    box-shadow: 0 0 0 3px rgba(14,116,144,.1) !important;
}

/* ── Footer bar (info + pagination) ── */
.cb-dt-footer {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    flex-wrap: wrap !important;
    gap: 10px !important;
    padding: 10px 16px !important;
    border-top: 1px solid var(--cb-border) !important;
    background: #f8fafc !important;
}

/* ── Info text ── */
.cb-dt-footer .dataTables_info {
    font-size: 12px !important;
    color: var(--cb-muted) !important;
    font-weight: 500 !important;
    padding: 0 !important;
}

/* ── Pagination ── */
.cb-dt-footer .dataTables_paginate {
    display: flex !important;
    align-items: center !important;
    gap: 3px !important;
}
.cb-dt-footer .dataTables_paginate .paginate_button {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    min-width: 32px !important;
    height: 32px !important;
    padding: 0 10px !important;
    border-radius: 7px !important;
    border: 1.5px solid var(--cb-border) !important;
    background: var(--cb-white) !important;
    color: var(--cb-text) !important;
    font-family: 'DM Sans', sans-serif !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    box-shadow: none !important;
    transition: all .12s !important;
    line-height: 1 !important;
    text-decoration: none !important;
}
.cb-dt-footer .dataTables_paginate .paginate_button:hover {
    background: var(--cb-sky) !important;
    border-color: var(--cb-teal) !important;
    color: var(--cb-teal) !important;
    box-shadow: none !important;
}
.cb-dt-footer .dataTables_paginate .paginate_button.current,
.cb-dt-footer .dataTables_paginate .paginate_button.current:hover {
    background: var(--cb-teal) !important;
    border-color: var(--cb-teal) !important;
    color: #fff !important;
}
.cb-dt-footer .dataTables_paginate .paginate_button.disabled,
.cb-dt-footer .dataTables_paginate .paginate_button.disabled:hover {
    opacity: .38 !important;
    cursor: default !important;
    pointer-events: none !important;
    background: var(--cb-white) !important;
    color: var(--cb-muted) !important;
    border-color: var(--cb-border) !important;
}
.cb-dt-footer .dataTables_paginate .ellipsis {
    padding: 0 4px !important;
    color: var(--cb-muted) !important;
    font-size: 12px !important;
    line-height: 32px !important;
}

/* ── Kill Bootstrap/DataTables default table borders that fight our theme ── */
table.dataTable.no-footer  { border-bottom: none !important; }
table.dataTable thead th   { border-bottom: none !important; }
table.dataTable tbody tr   { background-color: transparent !important; }
table.dataTable tbody tr:hover { background-color: var(--cb-sky) !important; }
table.dataTable tbody tr.cb-selected { background: linear-gradient(90deg,var(--cb-sky),#bae6fd) !important; }

/* ══ PRINT ══ */
@media print {
    .cb-topbar, .cb-nav-trail, .cb-action-bar, .cb-dt-toolbar, .cb-dt-footer { display: none !important; }
}
</style>